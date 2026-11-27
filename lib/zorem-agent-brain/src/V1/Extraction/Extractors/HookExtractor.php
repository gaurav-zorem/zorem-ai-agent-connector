<?php
/**
 * Hook knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Extraction\ExtractionContext;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the extension points a plugin provides.
 *
 * The actions and filters a plugin *fires*, not the ones it listens to. There
 * is no registry for these at all — do_action() leaves no trace — so they exist
 * in the Brain only because the token scanner reads them out of the source.
 *
 * An internal section: a list of every extension point is a map of where the
 * plugin can be modified.
 */
final class HookExtractor extends ArtefactExtractor {

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'hooks';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_HOOKS;
	}

	/**
	 * Fact group read.
	 */
	protected function group(): string {
		return 'hooks_fired';
	}

	/**
	 * Artefact noun.
	 *
	 * @param int $count Artefact count.
	 */
	protected function noun( int $count ): string {
		return _n( 'extension point', 'extension points', $count, 'zorem-ai-agent-connector' );
	}

	/**
	 * Section preamble.
	 */
	protected function preamble(): string {
		return __( 'Actions this plugin fires, which other code can hook. Read from the plugin source; firing an action leaves no runtime registry.', 'zorem-ai-agent-connector' );
	}

	/**
	 * Include filters alongside actions.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	public function supports( ExtractionContext $context ): bool {
		return array() !== $context->all_names( 'hooks_fired' )
			|| array() !== $context->all_names( 'hooks_filtered' );
	}
}
