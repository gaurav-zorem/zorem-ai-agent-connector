<?php
/**
 * Size limits shared by the core abilities.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Every bound an input schema states, in one place (CS-AI-2).
 *
 * The schemas read these constants and the callbacks clamp to them again,
 * so a schema that is loosened by mistake still cannot ask for more.
 */
final class Limits {

	/** Most items one list call returns. */
	public const PER_PAGE_MAX = 50;

	/** Items one list call returns when it does not say. */
	public const PER_PAGE_DEFAULT = 20;

	/** Highest page number a list call may ask for. */
	public const PAGE_MAX = 1000;

	/** Longest search phrase. */
	public const SEARCH_MAX = 200;

	/** Longest slug, post type or taxonomy name, or meta key. */
	public const KEY_MAX = 191;

	/** Longest meta value returned in full; longer ones are cut and flagged. */
	public const META_VALUE_MAX = 10000;

	/** Most meta keys one meta read returns. */
	public const META_KEYS_MAX = 200;
}
