<?php
/**
 * Settings knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Extraction\ExtractionContext;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the options and settings pages a plugin declares.
 *
 * Records that an option **exists** and what configures it. It never records an
 * option's value: settings routinely hold licence keys, API credentials and
 * customer data, and a knowledge base is exactly the wrong place for those. The
 * name is the useful part anyway — an agent asking "how is this configured"
 * needs to know the setting exists, not what it currently says.
 *
 * An internal section, for the same reason.
 */
final class SettingsExtractor extends ArtefactExtractor {

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'settings';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_CONFIGURATION;
	}

	/**
	 * Fact group read.
	 */
	protected function group(): string {
		return 'settings';
	}

	/**
	 * Artefact noun.
	 *
	 * @param int $count Artefact count.
	 */
	protected function noun( int $count ): string {
		return _n( 'setting', 'settings', $count, 'zorem-ai-agent-connector' );
	}

	/**
	 * Section preamble.
	 */
	protected function preamble(): string {
		return __( 'Options this plugin registers. Names only — values are never recorded, because settings routinely hold credentials.', 'zorem-ai-agent-connector' );
	}

	/**
	 * Include admin pages alongside registered options.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	public function supports( ExtractionContext $context ): bool {
		return array() !== $context->all_names( 'settings' )
			|| array() !== $context->all_names( 'settings_pages' );
	}
}
