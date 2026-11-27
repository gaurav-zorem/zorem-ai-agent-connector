<?php
/**
 * Ability knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Extraction\AbilityRisk;
use ZoremAgentBrain\V1\Extraction\ExtractionContext;
use ZoremAgentBrain\V1\Extraction\ExtractorInterface;
use ZoremAgentBrain\V1\Extraction\Provenance;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Documents the abilities a plugin registers.
 *
 * The highest-value extractor by a wide margin, and nearly free: the abilities
 * registry already holds every ability's name, schema and annotations, and
 * AbilityRisk classifies it (AI Agent OS's governance classifier, when
 * installed, through `zorem_agent_brain_ability_risk`). That is precisely "what
 * this plugin does and how an agent should call it", already structured.
 *
 * Facts here are verified — a name, a schema and a risk class all come from the
 * registry. Labels and descriptions are the author's words and are tagged as
 * such, because an ability described as "safely reads orders" is a claim, not a
 * guarantee, and the risk class is the part that governs anything.
 */
final class AbilityExtractor implements ExtractorInterface {

	/**
	 * Risk classification, for documentation.
	 *
	 * @var AbilityRisk
	 */
	private AbilityRisk $risk;

	/**
	 * Constructor.
	 *
	 * @param AbilityRisk $risk Risk classification.
	 */
	public function __construct( AbilityRisk $risk ) {
		$this->risk = $risk;
	}

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'abilities';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_ABILITIES;
	}

	/**
	 * Whether this plugin registers any abilities.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	public function supports( ExtractionContext $context ): bool {
		return array() !== $this->abilities_for( $context );
	}

	/**
	 * Build the abilities entry.
	 *
	 * @param ExtractionContext $context Plugin context.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract( ExtractionContext $context ): array {
		$abilities = $this->abilities_for( $context );

		if ( array() === $abilities ) {
			return array();
		}

		$facts    = array();
		$sections = array();

		foreach ( $abilities as $ability ) {
			$name        = $ability->get_name();
			$risk        = $this->risk->classify( $ability );
			$inferred    = $this->risk->is_inferred( $ability );
			$schema      = $ability->get_input_schema();
			$required    = $this->required_params( $schema );
			$optional    = $this->optional_params( $schema, $required );
			$annotations = (array) $ability->get_meta_item( 'annotations', array() );

			$facts[] = array(
				'name'          => $name,
				'label'         => $ability->get_label(),
				'description'   => $ability->get_description(),
				'risk'          => $risk,
				'risk_inferred' => $inferred,
				'grant_needed'  => AbilityRisk::required_level( $risk ),
				'required'      => $required,
				'optional'      => $optional,
				'readonly'      => ! empty( $annotations['readonly'] ),
				'idempotent'    => ! empty( $annotations['idempotent'] ),
				'destructive'   => ! empty( $annotations['destructive'] ),
				'confidence'    => $context->confidence( 'abilities', $name ),
				'evidence'      => $context->evidence( 'abilities', $name ),
			);

			$sections[] = $this->render_ability( end( $facts ) );
		}

		$content = '# ' . $context->name() . " abilities\n\n"
			. "Abilities this plugin registers, with the grant level each one needs.\n\n"
			. implode( "\n", $sections );

		return array(
			array(
				'entry_key'      => KnowledgeEntry::plugin_key( $context->slug(), $this->get_section() ),
				'source_type'    => KnowledgeEntry::SOURCE_PLUGIN_EXTRACTED,
				'source_ref'     => $context->slug(),
				'plugin_slug'    => $context->slug(),
				'plugin_version' => $context->version(),
				'section'        => $this->get_section(),
				'title'          => sprintf(
					/* translators: %s: plugin name. */
					__( '%s abilities', 'zorem-ai-agent-connector' ),
					$context->name()
				),
				'summary'        => sprintf(
					/* translators: 1: ability count, 2: plugin name. */
					_n( '%1$d ability registered by %2$s.', '%1$d abilities registered by %2$s.', count( $facts ), 'zorem-ai-agent-connector' ),
					count( $facts ),
					$context->name()
				),
				'content'        => $content,
				'facts'          => array( 'abilities' => $facts ),
				'meta'           => array_merge(
					$context->base_meta(),
					array(

						/*
						 * The schema, risk class and grant level come from the
						 * registry, but each ability's facts also carry its
						 * author-written label and description, and the summary
						 * carries the plugin's own name. A record is only as
						 * trustworthy as its least verified part, so all three
						 * are tagged as author text. The facts used to be tagged
						 * token-derived, which put ability descriptions into the
						 * enrichment prompt's verified section, unfenced.
						 */
						'provenance' => Provenance::map(
							array(
								'facts.abilities' => Provenance::AUTHOR_PROSE,
								'content'         => Provenance::AUTHOR_PROSE,
								'summary'         => Provenance::AUTHOR_PROSE,
							)
						),
					)
				),
			),
		);
	}

	/**
	 * Render one ability as Markdown, including its mechanical usage note.
	 *
	 * The usage line is derived entirely from declared values: required and
	 * optional parameters from the input schema, the grant level from the risk
	 * class, the behavioural flags from the ability's own annotations. It says
	 * what calling this costs, never when to call it — that judgement belongs to
	 * Tier 2 or curated knowledge.
	 *
	 * @param array<string, mixed> $fact One ability's facts.
	 */
	private function render_ability( array $fact ): string {
		$lines = array( '## `' . $fact['name'] . '`' );

		if ( '' !== (string) $fact['description'] ) {
			$lines[] = '';
			$lines[] = (string) $fact['description'];
		}

		$usage = array();

		$usage[] = $fact['required']
			? 'Requires ' . $this->format_params( $fact['required'] ) . '.'
			: 'Takes no required parameters.';

		if ( $fact['optional'] ) {
			$usage[] = 'Optional: ' . $this->format_params( $fact['optional'] ) . '.';
		}

		$usage[] = sprintf(
			'Risk **%s** — an agent needs a `%s` grant.',
			(string) $fact['risk'],
			(string) $fact['grant_needed']
		);

		if ( AbilityRisk::RISK_DESTRUCTIVE === $fact['risk'] ) {
			$usage[] = 'Destructive: also requires the site-wide setting and the agent\'s own opt-in.';
		}

		if ( $fact['risk_inferred'] ) {
			$usage[] = 'This risk level was inferred from the ability name, not declared by the plugin.';
		}

		$lines[] = '';
		$lines[] = '**Usage:** ' . implode( ' ', $usage );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Format a parameter list for the usage line.
	 *
	 * @param array<int, array<string, string>> $params Parameters.
	 */
	private function format_params( array $params ): string {
		$parts = array();

		foreach ( $params as $param ) {
			$type    = (string) ( $param['type'] ?? '' );
			$parts[] = '`' . (string) $param['name'] . '`' . ( '' === $type ? '' : ' (' . $type . ')' );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Required parameters, from the input schema.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 * @return array<int, array<string, string>>
	 */
	private function required_params( array $schema ): array {
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		$params = array();

		foreach ( $required as $name ) {
			$params[] = array(
				'name' => (string) $name,
				'type' => $this->type_of( $properties[ (string) $name ] ?? array() ),
			);
		}

		return $params;
	}

	/**
	 * Optional parameters: everything declared that is not required.
	 *
	 * @param array<string, mixed>              $schema   Input schema.
	 * @param array<int, array<string, string>> $required Required parameters.
	 * @return array<int, array<string, string>>
	 */
	private function optional_params( array $schema, array $required ): array {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		$required_names = array_column( $required, 'name' );
		$params         = array();

		foreach ( $properties as $name => $definition ) {
			if ( in_array( (string) $name, $required_names, true ) ) {
				continue;
			}

			$params[] = array(
				'name' => (string) $name,
				'type' => $this->type_of( is_array( $definition ) ? $definition : array() ),
			);
		}

		return $params;
	}

	/**
	 * A property's declared type, flattened for display.
	 *
	 * @param array<string, mixed> $definition Property definition.
	 */
	private function type_of( array $definition ): string {
		$type = $definition['type'] ?? '';

		if ( is_array( $type ) ) {
			return implode( '|', array_map( 'strval', $type ) );
		}

		return (string) $type;
	}

	/**
	 * The abilities attributed to this plugin.
	 *
	 * Attribution comes from the runtime pass, which reflected each ability's
	 * callback to a file. An ability's namespace is not used: anything can
	 * register `woocommerce/…`, so believing the prefix would let one plugin
	 * write another's knowledge.
	 *
	 * @param ExtractionContext $context Plugin context.
	 * @return array<int, \WP_Ability>
	 */
	private function abilities_for( ExtractionContext $context ): array {
		$abilities = array();

		foreach ( $context->live( 'abilities' ) as $name ) {
			/*
			 * Checked first: wp_get_ability() on an unregistered name raises
			 * _doing_it_wrong(), and a recorded ability may have been
			 * unregistered since.
			 */
			$ability = function_exists( 'wp_has_ability' ) && wp_has_ability( (string) $name ) ? wp_get_ability( (string) $name ) : null;

			if ( $ability instanceof \WP_Ability ) {
				$abilities[] = $ability;
			}
		}

		return $abilities;
	}

	/**
	 * Grant levels, exposed for the renderer's benefit.
	 *
	 * @return array<int, string>
	 */
	public static function grant_levels(): array {
		return AbilityRisk::levels();
	}
}
