<?php
/**
 * Human labels for reason slugs.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * One place where every Agent OS component names its reason slugs.
 *
 * A log that shows `grant_too_low` to a store owner speaks a language they do
 * not have; one that shows only "Read-only grant" cannot be searched. Screens
 * show both (PATTERNS 20), and this is where the label comes from — including
 * for slugs written by the *other* plugin, so either plugin's log labels every
 * record it holds.
 *
 * A service, not a contract: labels are registered in code on every request,
 * so there is nothing to persist and no one needs to own it.
 */
final class ReasonVocabulary {

	/**
	 * Labels by slug.
	 *
	 * @var array<string, string>
	 */
	private array $labels = array();

	/**
	 * Which plugin registered each slug.
	 *
	 * @var array<string, string>
	 */
	private array $owners = array();

	/**
	 * Register a label. The first registration of a slug wins.
	 *
	 * @param string $slug  Reason slug.
	 * @param string $label Human label.
	 * @param string $owner Slug of the registering plugin.
	 * @return bool Whether it was registered.
	 */
	public function register( string $slug, string $label, string $owner ): bool {
		if ( '' === $slug || isset( $this->labels[ $slug ] ) ) {
			return false;
		}

		$this->labels[ $slug ] = $label;
		$this->owners[ $slug ] = $owner;

		return true;
	}

	/**
	 * The label for a slug, or an empty string when none is registered.
	 *
	 * Empty rather than the slug itself, so a screen can tell an unnamed reason
	 * from a named one and show the gap instead of hiding it.
	 *
	 * @param string $slug Reason slug.
	 */
	public function label( string $slug ): string {
		return $this->labels[ $slug ] ?? '';
	}

	/**
	 * Every registered slug with its label.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		return $this->labels;
	}
}
