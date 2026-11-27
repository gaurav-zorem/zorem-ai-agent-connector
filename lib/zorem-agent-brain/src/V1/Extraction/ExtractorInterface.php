<?php
/**
 * Tier 1 extractor contract.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Produces knowledge entries for one plugin, deterministically.
 *
 * Tier 1 rules, binding on every implementation:
 *
 *   - **No AI, no network, no configuration.** An extractor must work on a
 *     fresh install with no API key and no settings touched. The Brain has to
 *     be useful before anyone configures anything.
 *   - **Facts, not judgement.** An extractor states what the code declares. It
 *     does not infer intent, recommend call order, or describe what a plugin is
 *     "for". That is Tier 2 or curated knowledge.
 *   - **Provenance on everything.** Each entry records which of its parts were
 *     verified from structure and which are text the author wrote. See
 *     Provenance for why that distinction is a security boundary.
 *   - **Never executes plugin code.** Extractors read registries and tokens.
 */
interface ExtractorInterface {

	/**
	 * A stable identifier, used in logs and diagnostics.
	 */
	public function get_id(): string;

	/**
	 * The knowledge section this extractor writes into.
	 */
	public function get_section(): string;

	/**
	 * Whether this extractor can produce anything for a plugin.
	 *
	 * Returning false is normal and cheap: most plugins register no abilities,
	 * ship no readme, or declare no shortcodes.
	 *
	 * @param ExtractionContext $context Everything known about the plugin.
	 */
	public function supports( ExtractionContext $context ): bool;

	/**
	 * Build entries for a plugin.
	 *
	 * @param ExtractionContext $context Everything known about the plugin.
	 * @return array<int, array<string, mixed>> Entry payloads for KnowledgeRepository::upsert().
	 */
	public function extract( ExtractionContext $context ): array;
}
