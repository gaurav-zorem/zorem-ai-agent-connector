<?php
/**
 * REST route knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the REST routes a plugin registers.
 *
 * Aggregated into a single entry rather than one per route, and deliberately
 * so: WooCommerce alone declares 345, and an entry per route would bury every
 * other plugin's knowledge under one plugin's API surface while consuming the
 * context window retrieval is meant to conserve.
 *
 * An internal section. The full route surface is reconnaissance — it is the
 * shape of everything the site can be asked to do, which is a different thing
 * from the abilities an agent has been granted.
 *
 * Attribution here degrades rather than fails when the REST server is
 * unavailable. Under WP-CLI there is no request, `rest_api_init` never fires,
 * and forcing it fatals — measured twice in the legacy plugin. The resolver
 * guards on `did_action()`, so a CLI run simply produces the static half of the
 * picture. A registry export from the command line runs exactly this path.
 */
final class RestRouteExtractor extends ArtefactExtractor {

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'rest';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_REST;
	}

	/**
	 * Fact group read.
	 */
	protected function group(): string {
		return 'rest';
	}

	/**
	 * Artefact noun.
	 *
	 * @param int $count Artefact count.
	 */
	protected function noun( int $count ): string {
		return _n( 'REST route', 'REST routes', $count, 'zorem-ai-agent-connector' );
	}

	/**
	 * Section preamble.
	 */
	protected function preamble(): string {
		if ( ! did_action( 'rest_api_init' ) ) {
			return __( 'REST routes found in this plugin\'s source. The live REST registry was not available when this was extracted, so routes registered conditionally may be missing.', 'zorem-ai-agent-connector' );
		}

		return __( 'REST routes this plugin registers. These are the plugin\'s own HTTP surface, not the abilities an agent has been granted.', 'zorem-ai-agent-connector' );
	}
}
