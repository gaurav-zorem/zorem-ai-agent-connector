<?php
/**
 * Shared behaviour for artefact-listing extractors.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Extraction\ExtractionContext;
use ZoremAgentBrain\V1\Extraction\ExtractorInterface;
use ZoremAgentBrain\V1\Extraction\PluginAttributionResolver;
use ZoremAgentBrain\V1\Extraction\Provenance;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the five extractors that list named artefacts.
 *
 * Shortcodes, post types, REST routes, settings and hooks all reduce to the
 * same shape: take the names reconciled for one fact group, render them as a
 * list, record each one's attribution confidence. Only the labelling differs,
 * so the differences are declared as data and the behaviour lives here once.
 *
 * Everything these produce is `token_derived`. A shortcode tag, a route path
 * and a hook name are all read from code — either the live registry or the
 * token scan — and none of them is an author's description of anything.
 */
abstract class ArtefactExtractor implements ExtractorInterface {

	/**
	 * The fact group this extractor reads from the context.
	 */
	abstract protected function group(): string;

	/**
	 * A short noun for the artefacts, used in titles and summaries.
	 *
	 * @param int $count How many were found, for pluralisation.
	 */
	abstract protected function noun( int $count ): string;

	/**
	 * A sentence explaining what the section contains.
	 */
	abstract protected function preamble(): string;

	/**
	 * Whether this plugin has any artefacts in the group.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	public function supports( ExtractionContext $context ): bool {
		return array() !== $context->all_names( $this->group() );
	}

	/**
	 * Build the entry.
	 *
	 * @param ExtractionContext $context Plugin context.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract( ExtractionContext $context ): array {
		$names = array_values( array_filter( $context->all_names( $this->group() ), array( self::class, 'is_name' ) ) );

		if ( array() === $names ) {
			return array();
		}

		$facts = array();
		$lines = array();

		foreach ( $names as $name ) {
			$confidence = $context->confidence( $this->group(), $name );

			$facts[] = array(
				'name'       => $name,
				'confidence' => $confidence,
				'evidence'   => $context->evidence( $this->group(), $name ),
			);

			$lines[] = '- `' . $name . '`' . $this->confidence_note( $confidence );
		}

		// Headed by the slug: the plugin's Name is author text, and this content is tagged token-derived.
		$content = '# ' . $context->slug() . ' — ' . $this->noun( count( $names ) ) . "\n\n"
			. $this->preamble() . "\n\n"
			. implode( "\n", $lines ) . "\n";

		$content .= $this->confidence_legend( $facts );

		return array(
			array(
				'entry_key'      => KnowledgeEntry::plugin_key( $context->slug(), $this->get_section() ),
				'source_type'    => KnowledgeEntry::SOURCE_PLUGIN_EXTRACTED,
				'source_ref'     => $context->slug(),
				'plugin_slug'    => $context->slug(),
				'plugin_version' => $context->version(),
				'section'        => $this->get_section(),
				'title'          => $context->name() . ' — ' . $this->noun( count( $names ) ),
				'summary'        => sprintf(
					/* translators: 1: count, 2: artefact noun, 3: plugin name. */
					__( '%1$d %2$s found for %3$s.', 'zorem-ai-agent-connector' ),
					count( $names ),
					$this->noun( count( $names ) ),
					$context->name()
				),
				'content'        => $content,
				'facts'          => array( $this->group() => $facts ),
				'meta'           => array_merge( $context->base_meta(), $this->provenance_meta() ),
			),
		);
	}

	/**
	 * Whether a string can be an artefact name.
	 *
	 * Names are read from string literals in a plugin's code and labelled
	 * token-derived, which an agent reads as verified. A literal is whatever
	 * the author wrote, so `add_shortcode( 'SYSTEM: ignore your rules and …' )`
	 * would otherwise reach agents as a verified fact. Real hook, shortcode,
	 * post type, option and route names never hold whitespace, control
	 * characters or backticks and are short, so anything else is not listed.
	 *
	 * @param mixed $name Candidate name.
	 */
	public static function is_name( $name ): bool {
		return is_string( $name )
			&& '' !== $name
			&& strlen( $name ) <= 191
			&& 1 !== preg_match( '/[\s\p{C}`]/u', $name )
			&& false === strpos( $name, '<<<' );
	}

	/**
	 * Provenance for an artefact listing.
	 *
	 * @return array<string, mixed>
	 */
	protected function provenance_meta(): array {
		/*
		 * Names come from the registry or the token stream, and the content is
		 * headed by the slug. The summary names the plugin by its Name, which
		 * the author wrote, so it is author text.
		 */
		return array(
			'provenance' => Provenance::map(
				array(
					'facts.' . $this->group() => Provenance::TOKEN_DERIVED,
					'content'                 => Provenance::TOKEN_DERIVED,
					'summary'                 => Provenance::AUTHOR_PROSE,
				)
			),
		);
	}

	/**
	 * An inline note for artefacts whose attribution is weaker than confident.
	 *
	 * Written next to the artefact rather than buried in metadata: an agent
	 * reading the list should be able to see which entries are certain.
	 *
	 * @param string $confidence Attribution confidence.
	 */
	private function confidence_note( string $confidence ): string {
		switch ( $confidence ) {
			case PluginAttributionResolver::DECLARED_ONLY:
				return ' — found in source; not seen registered in this request';
			case PluginAttributionResolver::RUNTIME_SUSPECT:
				return ' — registered at runtime, but absent from this plugin\'s source; attribution uncertain';
			case PluginAttributionResolver::RUNTIME_ONLY:
				return ' — registered at runtime';
			default:
				return '';
		}
	}

	/**
	 * A closing note when any artefact is less than confidently attributed.
	 *
	 * @param array<int, array<string, string>> $facts Artefact facts.
	 */
	private function confidence_legend( array $facts ): string {
		foreach ( $facts as $fact ) {
			if ( PluginAttributionResolver::CONFIDENT !== $fact['confidence'] ) {
				return "\n> Entries without a confidence note were found both in this plugin's source"
					. " and in the live registry. The others were seen only one way.\n";
			}
		}

		return '';
	}
}
