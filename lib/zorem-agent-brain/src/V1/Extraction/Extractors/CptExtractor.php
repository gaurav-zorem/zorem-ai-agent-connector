<?php
/**
 * Post type knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the post types a plugin registers.
 *
 * Almost entirely static-derived: register_post_type() takes no callback, so
 * the runtime registry knows a post type exists but not who created it. This is
 * the clearest case for the token scan existing at all — without it these
 * artefacts would be attributable to nobody.
 *
 * An internal section. Post types describe how the site stores its data, which
 * is shape an external agent has no business enumerating on the strength of one
 * ability grant.
 */
final class CptExtractor extends ArtefactExtractor {

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'cpts';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_CPTS;
	}

	/**
	 * Fact group read.
	 */
	protected function group(): string {
		return 'cpts';
	}

	/**
	 * Artefact noun.
	 *
	 * @param int $count Artefact count.
	 */
	protected function noun( int $count ): string {
		return _n( 'post type', 'post types', $count, 'zorem-ai-agent-connector' );
	}

	/**
	 * Section preamble.
	 */
	protected function preamble(): string {
		return __( 'Custom post types this plugin registers. These are found by reading the plugin source: post type registration leaves no callback to trace at runtime.', 'zorem-ai-agent-connector' );
	}
}
