<?php
/**
 * Decides which knowledge a reader may read.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Scope;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question: may this reader read this entry?
 *
 * Ported from the legacy KnowledgeScopeResolver, with readers in place of
 * agents. The precedence, most specific first:
 *
 *   1. Inactive readers read nothing.
 *   2. Drafts are invisible to every reader, through every path.
 *   3. Stale entries (a deactivated plugin's) are not served.
 *   4. An explicit **entry** rule.
 *   5. An explicit **section** rule, then an explicit **plugin** rule.
 *   6. Derived: the entry's plugin is among the reader's plugin grants, and
 *      the section's tier permits the reader. Or the entry is one of the
 *      **store's own pages** — a page the merchant ticked as knowledge
 *      (source `wp_content`, no plugin) in a public section — which every
 *      reader may read: ticking a page is the merchant saying "agents may
 *      quote this".
 *
 * query_constraints() mirrors decide() exactly, for the SQL filter: any
 * divergence would show as rows a search counts but cannot open. Both ask one
 * question about internal access — Reader::reads_internal().
 *
 * No `catch` here: a scoping failure must reach KnowledgeGate, which turns it
 * into a logged denial.
 */
final class ReaderScopeResolver {

	public const ALLOWED_SCOPE_GRANT     = 'scope_grant';
	public const ALLOWED_DERIVED_PUBLIC  = 'derived_public';
	public const ALLOWED_INTERNAL_READER = 'derived_internal';
	public const ALLOWED_STORE_PAGE      = 'store_page';

	/**
	 * Sources whose public entries every reader may read: pages the merchant chose as knowledge.
	 */
	public const STORE_PAGE_SOURCES = array( KnowledgeEntry::SOURCE_WP_CONTENT );

	public const DENIED_DRAFT           = 'knowledge_draft';
	public const DENIED_STALE           = 'knowledge_stale';
	public const DENIED_SUPERSEDED      = 'knowledge_superseded';
	public const DENIED_SCOPE_DENY      = 'knowledge_scope_denied';
	public const DENIED_NO_GRANT        = 'knowledge_no_plugin_grant';
	public const DENIED_INTERNAL_TIER   = 'knowledge_internal_section';
	public const DENIED_READER_INACTIVE = 'knowledge_reader_inactive';

	/**
	 * Explicit rules.
	 *
	 * @var ScopeRepository
	 */
	private ScopeRepository $scopes;

	/**
	 * Constructor.
	 *
	 * @param ScopeRepository $scopes Explicit rules.
	 */
	public function __construct( ScopeRepository $scopes ) {
		$this->scopes = $scopes;
	}

	/**
	 * Every reason slug with its human label, for the activity log (PATTERNS 20).
	 *
	 * @return array<string, string>
	 */
	public static function reason_labels(): array {
		return array(
			self::DENIED_DRAFT           => __( 'Unreviewed draft', 'zorem-ai-agent-connector' ),
			self::DENIED_STALE           => __( 'Plugin not active', 'zorem-ai-agent-connector' ),
			self::DENIED_SUPERSEDED      => __( 'Replaced by a more authoritative entry', 'zorem-ai-agent-connector' ),
			self::DENIED_SCOPE_DENY      => __( 'Knowledge rule denies it', 'zorem-ai-agent-connector' ),
			self::DENIED_NO_GRANT        => __( 'No grant on that plugin', 'zorem-ai-agent-connector' ),
			self::DENIED_INTERNAL_TIER   => __( 'Internal section, reader does not act for an administrator', 'zorem-ai-agent-connector' ),
			self::DENIED_READER_INACTIVE => __( 'Reader not active', 'zorem-ai-agent-connector' ),
		);
	}

	/**
	 * Whether a reader may read an entry, and why.
	 *
	 * @param Reader         $reader The reader.
	 * @param KnowledgeEntry $entry  The entry.
	 * @return array{allowed: bool, reason: string}
	 */
	public function decide( Reader $reader, KnowledgeEntry $entry ): array {
		if ( ! $reader->is_active() ) {
			return self::denied( self::DENIED_READER_INACTIVE );
		}

		if ( KnowledgeEntry::TRUST_APPROVED !== $entry->get_trust_status() ) {
			return self::denied( self::DENIED_DRAFT );
		}

		if ( $entry->is_stale() ) {
			return self::denied( self::DENIED_STALE );
		}

		// Hidden from search already; asked for by its key it is refused the same way.
		if ( '' !== (string) $entry->meta( 'superseded_by', '' ) ) {
			return self::denied( self::DENIED_SUPERSEDED );
		}

		$rules = $this->rules_by_type( $reader );

		// 4. Entry rule.
		$entry_rule = $rules[ ScopeRepository::SCOPE_ENTRY ][ $entry->get_entry_key() ] ?? null;

		if ( null !== $entry_rule ) {
			return $entry_rule ? self::allowed( self::ALLOWED_SCOPE_GRANT ) : self::denied( self::DENIED_SCOPE_DENY );
		}

		// 5. Section, then plugin rules.
		$section_rule = $rules[ ScopeRepository::SCOPE_SECTION ][ $entry->get_section() ] ?? null;

		if ( null !== $section_rule ) {
			return $section_rule ? self::allowed( self::ALLOWED_SCOPE_GRANT ) : self::denied( self::DENIED_SCOPE_DENY );
		}

		$plugin      = (string) $entry->get_plugin_slug();
		$plugin_rule = '' === $plugin ? null : ( $rules[ ScopeRepository::SCOPE_PLUGIN ][ $plugin ] ?? null );

		if ( null !== $plugin_rule ) {
			return $plugin_rule ? self::allowed( self::ALLOWED_SCOPE_GRANT ) : self::denied( self::DENIED_SCOPE_DENY );
		}

		// 6. Derived. The store's own pages first: ticked by the merchant as knowledge, in a public section.
		if ( '' === $plugin && in_array( $entry->get_source_type(), self::STORE_PAGE_SOURCES, true ) && SectionTiers::is_public( $entry->get_section() ) ) {
			return self::allowed( self::ALLOWED_STORE_PAGE );
		}

		if ( '' === $plugin || ! in_array( $plugin, $reader->plugin_grants(), true ) ) {
			return self::denied( self::DENIED_NO_GRANT );
		}

		if ( SectionTiers::is_public( $entry->get_section() ) ) {
			return self::allowed( self::ALLOWED_DERIVED_PUBLIC );
		}

		if ( $reader->reads_internal() ) {
			return self::allowed( self::ALLOWED_INTERNAL_READER );
		}

		return self::denied( self::DENIED_INTERNAL_TIER );
	}

	/**
	 * Constraints for filtering a query in SQL, mirroring decide().
	 *
	 * @param Reader $reader The reader.
	 * @return array<string, mixed>
	 */
	public function query_constraints( Reader $reader ): array {
		if ( ! $reader->is_active() ) {
			return array( 'deny_all' => true );
		}

		$allow = array_fill_keys( ScopeRepository::scope_types(), array() );
		$deny  = $allow;

		foreach ( $this->scopes->for_reader( $reader->ref() ) as $rule ) {
			if ( $rule['granted'] ) {
				$allow[ $rule['scope_type'] ][] = $rule['scope_value'];
			} else {
				$deny[ $rule['scope_type'] ][] = $rule['scope_value'];
			}
		}

		return array(
			'deny_all'         => false,
			'reads_internal'   => $reader->reads_internal(),
			'derived_plugins'  => $reader->plugin_grants(),
			// Null for an administrator's reader: every section, as in decide().
			'derived_sections' => $reader->reads_internal() ? null : SectionTiers::public_sections(),
			'store_sources'    => self::STORE_PAGE_SOURCES,
			'public_sections'  => SectionTiers::public_sections(),
			'allow_entries'    => $allow[ ScopeRepository::SCOPE_ENTRY ],
			'deny_entries'     => $deny[ ScopeRepository::SCOPE_ENTRY ],
			'allow_sections'   => $allow[ ScopeRepository::SCOPE_SECTION ],
			'deny_sections'    => $deny[ ScopeRepository::SCOPE_SECTION ],
			'allow_plugins'    => $allow[ ScopeRepository::SCOPE_PLUGIN ],
			'deny_plugins'     => $deny[ ScopeRepository::SCOPE_PLUGIN ],
		);
	}

	/**
	 * A reader's rules indexed by type and value: true allow, false deny.
	 *
	 * @param Reader $reader The reader.
	 * @return array<string, array<string, bool>>
	 */
	private function rules_by_type( Reader $reader ): array {
		$rules = array_fill_keys( ScopeRepository::scope_types(), array() );

		foreach ( $this->scopes->for_reader( $reader->ref() ) as $rule ) {
			$rules[ $rule['scope_type'] ][ $rule['scope_value'] ] = $rule['granted'];
		}

		return $rules;
	}

	/**
	 * An allow result.
	 *
	 * @param string $reason Why.
	 * @return array{allowed: bool, reason: string}
	 */
	private static function allowed( string $reason ): array {
		return array(
			'allowed' => true,
			'reason'  => $reason,
		);
	}

	/**
	 * A deny result.
	 *
	 * @param string $reason Why.
	 * @return array{allowed: bool, reason: string}
	 */
	private static function denied( string $reason ): array {
		return array(
			'allowed' => false,
			'reason'  => $reason,
		);
	}
}
