<?php
/**
 * Knowledge entry entity.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One piece of knowledge about the site, from any source.
 *
 * Immutable, like Agent and Skill. Carries both prose and structured facts:
 * `content` is Markdown for a model to read, `facts` is the same knowledge as
 * JSON so retrieval can answer structured questions without a model having to
 * parse prose back into data.
 */
final class KnowledgeEntry {

	/** Where an entry came from. */
	public const SOURCE_PLUGIN_EXTRACTED = 'plugin_extracted';
	public const SOURCE_AI_GENERATED     = 'ai_generated';
	public const SOURCE_REMOTE_CURATED   = 'remote_curated';
	public const SOURCE_MANUAL           = 'manual';

	/** Store content the merchant selected: policies, FAQs, pages. */
	public const SOURCE_WP_CONTENT = 'wp_content';

	/**
	 * Facts read from the store's own configuration.
	 *
	 * Not prose and not extracted from code: the shipping zones, the store
	 * address, the currency, whether guests may check out. A merchant set these
	 * deliberately in WooCommerce, which makes them more reliable than a policy
	 * page written two years ago and never revisited — so they rank above
	 * `wp_content` and below anything a human wrote here on purpose.
	 */
	public const SOURCE_STORE_SETTINGS = 'store_settings';

	public const TRUST_APPROVED = 'approved';
	public const TRUST_DRAFT    = 'draft';

	/** Sections whose content an agent may read on the strength of an ability grant alone. */
	public const SECTION_OVERVIEW   = 'overview';
	public const SECTION_ABILITIES  = 'abilities';
	public const SECTION_FAQ        = 'faq';
	public const SECTION_SHORTCODES = 'shortcodes';
	public const SECTION_MANUAL     = 'manual';

	/**
	 * Uploaded Markdown and synced GitHub documents, until 1.3.0 removed both.
	 *
	 * Kept as a known section so a site's existing rules on it stay valid.
	 */
	public const SECTION_DOCUMENT = 'document';

	/** Sections that describe the site's internal shape. */
	public const SECTION_CONFIGURATION = 'configuration';
	public const SECTION_HOOKS         = 'hooks';
	public const SECTION_REST          = 'rest';
	public const SECTION_CPTS          = 'cpts';

	/**
	 * Row id.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Stable identity, unique across sources.
	 *
	 * @var string
	 */
	private string $entry_key;

	/**
	 * One of the SOURCE_* constants.
	 *
	 * @var string
	 */
	private string $source_type;

	/**
	 * What the entry describes: a plugin slug, a post id, a remote doc id.
	 *
	 * @var string|null
	 */
	private ?string $source_ref;

	/**
	 * Owning plugin, when there is one.
	 *
	 * @var string|null
	 */
	private ?string $plugin_slug;

	/**
	 * Plugin version the entry was derived from.
	 *
	 * @var string|null
	 */
	private ?string $plugin_version;

	/**
	 * One of the SECTION_* constants.
	 *
	 * @var string
	 */
	private string $section;

	/**
	 * Human-readable title.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Short summary, used in listings and search results.
	 *
	 * @var string
	 */
	private string $summary;

	/**
	 * Markdown body.
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Machine-readable form of the same knowledge.
	 *
	 * @var array<string, mixed>
	 */
	private array $facts;

	/**
	 * Trust status: approved or draft.
	 *
	 * @var string
	 */
	private string $trust_status;

	/**
	 * Precedence within a plugin and section. Lower wins.
	 *
	 * @var int
	 */
	private int $priority;

	/**
	 * Checksum of the inputs this entry was built from.
	 *
	 * @var string|null
	 */
	private ?string $checksum;

	/**
	 * Rough token count, so an agent can budget before reading.
	 *
	 * @var int
	 */
	private int $token_estimate;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Last update timestamp.
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Free-form metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta;

	/**
	 * Construct from a database row or an extractor's array.
	 *
	 * @param array<string, mixed> $row Entry data.
	 */
	public function __construct( array $row ) {
		$this->id             = (int) ( $row['id'] ?? 0 );
		$this->entry_key      = (string) ( $row['entry_key'] ?? '' );
		$this->source_type    = (string) ( $row['source_type'] ?? self::SOURCE_PLUGIN_EXTRACTED );
		$this->source_ref     = isset( $row['source_ref'] ) ? (string) $row['source_ref'] : null;
		$this->plugin_slug    = isset( $row['plugin_slug'] ) ? (string) $row['plugin_slug'] : null;
		$this->plugin_version = isset( $row['plugin_version'] ) ? (string) $row['plugin_version'] : null;
		$this->section        = (string) ( $row['section'] ?? self::SECTION_OVERVIEW );
		$this->title          = (string) ( $row['title'] ?? '' );
		$this->summary        = (string) ( $row['summary'] ?? '' );
		$this->content        = (string) ( $row['content'] ?? '' );
		$this->trust_status   = (string) ( $row['trust_status'] ?? self::TRUST_APPROVED );
		$this->priority       = (int) ( $row['priority'] ?? self::default_priority( $this->source_type ) );
		$this->checksum       = isset( $row['checksum'] ) ? (string) $row['checksum'] : null;
		$this->created_at     = (string) ( $row['created_at'] ?? '' );
		$this->updated_at     = (string) ( $row['updated_at'] ?? '' );

		$this->facts = self::decode_array( $row['facts'] ?? array() );
		$this->meta  = self::decode_array( $row['meta'] ?? array() );

		$this->token_estimate = isset( $row['token_estimate'] )
			? (int) $row['token_estimate']
			: self::estimate_tokens( $this->content . ' ' . $this->summary );
	}

	/**
	 * Row id.
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Stable identity.
	 */
	public function get_entry_key(): string {
		return $this->entry_key;
	}

	/**
	 * Originating source type.
	 */
	public function get_source_type(): string {
		return $this->source_type;
	}

	/**
	 * What the entry describes.
	 */
	public function get_source_ref(): ?string {
		return $this->source_ref;
	}

	/**
	 * Owning plugin slug.
	 */
	public function get_plugin_slug(): ?string {
		return $this->plugin_slug;
	}

	/**
	 * Plugin version the entry was derived from.
	 */
	public function get_plugin_version(): ?string {
		return $this->plugin_version;
	}

	/**
	 * Section name.
	 */
	public function get_section(): string {
		return $this->section;
	}

	/**
	 * Title.
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * Summary.
	 */
	public function get_summary(): string {
		return $this->summary;
	}

	/**
	 * Markdown body.
	 */
	public function get_content(): string {
		return $this->content;
	}

	/**
	 * Structured facts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_facts(): array {
		return $this->facts;
	}

	/**
	 * Trust status.
	 */
	public function get_trust_status(): string {
		return $this->trust_status;
	}

	/**
	 * Whether this entry may be shown to agents at all.
	 *
	 * A draft is invisible to every agent through every path, whatever scope
	 * grants exist. That is the entire meaning of the approval gate.
	 */
	public function is_approved(): bool {
		return self::TRUST_APPROVED === $this->trust_status;
	}

	/**
	 * Precedence. Lower wins.
	 */
	public function get_priority(): int {
		return $this->priority;
	}

	/**
	 * Input checksum.
	 */
	public function get_checksum(): ?string {
		return $this->checksum;
	}

	/**
	 * Rough token count.
	 */
	public function get_token_estimate(): int {
		return $this->token_estimate;
	}

	/**
	 * Creation timestamp.
	 */
	public function get_created_at(): string {
		return $this->created_at;
	}

	/**
	 * Last update timestamp.
	 */
	public function get_updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * All metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * One metadata value.
	 *
	 * @param string $key           Meta key.
	 * @param mixed  $default_value Returned when absent.
	 * @return mixed
	 */
	public function meta( string $key, $default_value = null ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : $default_value;
	}

	/**
	 * Whether the owning plugin is currently inactive.
	 *
	 * Stale knowledge is retained rather than deleted — rebuilding is expensive
	 * and reactivation is common — but it must not be served.
	 */
	public function is_stale(): bool {
		return (bool) $this->meta( 'stale', false );
	}

	/**
	 * The MCP resource URI for this entry.
	 */
	public function get_resource_uri(): string {
		return 'WordPress://zorem-agent-brain/knowledge/' . rawurlencode( $this->entry_key );
	}

	/**
	 * Default precedence for a source type. Lower wins.
	 *
	 * Curated knowledge beats merchant-written knowledge beats generated
	 * knowledge beats extracted knowledge, for the same plugin and section.
	 *
	 * @param string $source_type One of the SOURCE_* constants.
	 */
	public static function default_priority( string $source_type ): int {
		$map = array(
			self::SOURCE_REMOTE_CURATED   => 10,
			self::SOURCE_MANUAL           => 20,
			self::SOURCE_STORE_SETTINGS   => 30,
			self::SOURCE_WP_CONTENT       => 40,
			self::SOURCE_AI_GENERATED     => 50,
			self::SOURCE_PLUGIN_EXTRACTED => 90,
		);

		return $map[ $source_type ] ?? 90;
	}

	/**
	 * Every valid source type.
	 *
	 * @return array<int, string>
	 */
	public static function source_types(): array {
		return array(
			self::SOURCE_PLUGIN_EXTRACTED,
			self::SOURCE_AI_GENERATED,
			self::SOURCE_REMOTE_CURATED,
			self::SOURCE_MANUAL,
			self::SOURCE_WP_CONTENT,
			self::SOURCE_STORE_SETTINGS,
		);
	}

	/**
	 * Build the canonical entry key for a plugin section.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $section     Section name.
	 */
	public static function plugin_key( string $plugin_slug, string $section ): string {
		return 'plugin:' . $plugin_slug . ':' . $section;
	}

	/**
	 * A rough token count.
	 *
	 * A budgeting aid, not a billing figure — nothing charges against it. It
	 * exists so an agent can decide whether an entry fits in the context it has
	 * left, and so the enrichment preview can show a cost before calling a
	 * provider.
	 *
	 * Words and punctuation are counted at different rates, because they
	 * tokenize at different rates. A flat "characters / 4" is the usual rule of
	 * thumb and it holds well for prose, but it under-counts punctuation-dense
	 * text badly — and punctuation-dense text is exactly what this plugin
	 * produces most of. A REST-route table measured 35% below a subword count
	 * under the flat rule, which would have an agent budget for two thirds of
	 * what it was about to fetch. Punctuation runs are therefore charged at
	 * roughly two characters per token.
	 *
	 * Still an approximation, and honest about it: `estimate_basis` travels with
	 * the number wherever it is reported.
	 *
	 * @param string $text Text to measure.
	 */
	public static function estimate_tokens( string $text ): int {
		$text = trim( $text );

		if ( '' === $text ) {
			return 0;
		}

		$tokens = 0;

		if ( preg_match_all( '/[\p{L}\p{N}]+/u', $text, $words ) ) {
			foreach ( $words[0] as $word ) {
				$tokens += (int) ceil( mb_strlen( $word ) / 4 );
			}
		}

		if ( preg_match_all( '/[^\p{L}\p{N}\s]+/u', $text, $marks ) ) {
			foreach ( $marks[0] as $run ) {
				$tokens += (int) ceil( mb_strlen( $run ) / 2 );
			}
		}

		return max( 1, $tokens );
	}

	/**
	 * Decode a value that may be JSON, an array, or nothing.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	private static function decode_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * Array form for REST responses and MCP payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'entry_key'      => $this->entry_key,
			'source_type'    => $this->source_type,
			'source_ref'     => $this->source_ref,
			'plugin_slug'    => $this->plugin_slug,
			'plugin_version' => $this->plugin_version,
			'section'        => $this->section,
			'title'          => $this->title,
			'summary'        => $this->summary,
			'content'        => $this->content,
			'facts'          => $this->facts,
			'trust_status'   => $this->trust_status,
			'priority'       => $this->priority,
			'token_estimate' => $this->token_estimate,
			'is_stale'       => $this->is_stale(),
			'created_at'     => $this->created_at,
			'updated_at'     => $this->updated_at,
			'meta'           => $this->meta,
		);
	}
}
