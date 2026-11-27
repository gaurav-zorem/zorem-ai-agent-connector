<?php
/**
 * Shortcode knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the shortcodes a plugin registers.
 *
 * A public section: knowing a shortcode tag exists tells an agent what content
 * it may encounter in a post, and reveals nothing about the site's internals.
 */
final class ShortcodeExtractor extends ArtefactExtractor {

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'shortcodes';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_SHORTCODES;
	}

	/**
	 * Fact group read.
	 */
	protected function group(): string {
		return 'shortcodes';
	}

	/**
	 * Artefact noun.
	 *
	 * @param int $count Artefact count.
	 */
	protected function noun( int $count ): string {
		return _n( 'shortcode', 'shortcodes', $count, 'zorem-ai-agent-connector' );
	}

	/**
	 * Section preamble.
	 */
	protected function preamble(): string {
		return __( 'Shortcode tags this plugin handles. Content containing these tags is rendered by this plugin.', 'zorem-ai-agent-connector' );
	}
}
