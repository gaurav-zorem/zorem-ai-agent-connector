<?php
/**
 * An approved skill manual.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * A skill's manual as handed to an experience: how to use a set of abilities.
 *
 * Only approved skills are ever turned into a SkillManual; drafts never leave
 * the provider. The tier travels with the text so a consumer can label it.
 */
final class SkillManual {

	/** Trust tiers. */
	public const TIER_OFFICIAL  = 'official';
	public const TIER_LIBRARY   = 'library';
	public const TIER_GENERATED = 'generated';
	public const TIER_CUSTOM    = 'custom';

	/**
	 * Skill slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Title.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * One of the TIER_* constants.
	 *
	 * @var string
	 */
	private string $tier;

	/**
	 * The manual, as Markdown.
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Ability names the manual documents.
	 *
	 * @var array<int, string>
	 */
	private array $abilities;

	/**
	 * Constructor.
	 *
	 * @param string             $slug      Skill slug.
	 * @param string             $title     Title.
	 * @param string             $tier      One of the TIER_* constants.
	 * @param string             $content   The manual.
	 * @param array<int, string> $abilities Ability names.
	 * @throws \InvalidArgumentException When the tier is unknown.
	 */
	public function __construct( string $slug, string $title, string $tier, string $content, array $abilities ) {
		if ( ! in_array( $tier, array( self::TIER_OFFICIAL, self::TIER_LIBRARY, self::TIER_GENERATED, self::TIER_CUSTOM ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown skill tier.' );
		}

		$this->slug      = $slug;
		$this->title     = $title;
		$this->tier      = $tier;
		$this->content   = $content;
		$this->abilities = array_values( array_map( 'strval', $abilities ) );
	}

	/**
	 * Skill slug.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Trust tier.
	 */
	public function tier(): string {
		return $this->tier;
	}

	/**
	 * The manual.
	 */
	public function content(): string {
		return $this->content;
	}

	/**
	 * Ability names the manual documents.
	 *
	 * @return array<int, string>
	 */
	public function abilities(): array {
		return $this->abilities;
	}
}
