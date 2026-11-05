<?php
/**
 * Which post types and taxonomies the core abilities may touch.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The content the core abilities are allowed to read and change.
 *
 * The abilities lend an agent the core `post` and `page` capabilities and
 * nothing else, so they can only be right about post types whose
 * capabilities are those: the default for register_post_type(). A type with
 * its own capabilities (WooCommerce products, most LMS courses) would be
 * checked against capabilities nobody lent, and a type that is internal
 * plumbing (revisions, templates, menu items) is not content. Both are
 * excluded, and list-post-types says which and why, so "not supported" is
 * never mistaken for "not there" (PATTERNS 5).
 */
final class ContentTypes {

	/** Excluded: WordPress internals and media, which other abilities handle. */
	public const REASON_INTERNAL = 'internal';

	/** Excluded: another plugin's own abilities cover it. */
	public const REASON_OWN_ABILITIES = 'own_abilities';

	/** Excluded: the type checks capabilities of its own. */
	public const REASON_CUSTOM_CAPS = 'custom_capabilities';

	/** Excluded: no admin screen, so not something a person edits. */
	public const REASON_NO_ADMIN_SCREEN = 'no_admin_screen';

	/**
	 * Post types never touched, with the reason.
	 *
	 * Not filterable: which content an agent's lent capabilities reach is an
	 * authorisation input (PATTERNS 9).
	 *
	 * @var array<string, string>
	 */
	private const EXCLUDED = array(
		'attachment'           => self::REASON_INTERNAL,
		'revision'             => self::REASON_INTERNAL,
		'nav_menu_item'        => self::REASON_INTERNAL,
		'custom_css'           => self::REASON_INTERNAL,
		'customize_changeset'  => self::REASON_INTERNAL,
		'oembed_cache'         => self::REASON_INTERNAL,
		'user_request'         => self::REASON_INTERNAL,
		'wp_block'             => self::REASON_INTERNAL,
		'wp_template'          => self::REASON_INTERNAL,
		'wp_template_part'     => self::REASON_INTERNAL,
		'wp_global_styles'     => self::REASON_INTERNAL,
		'wp_navigation'        => self::REASON_INTERNAL,
		'wp_font_family'       => self::REASON_INTERNAL,
		'wp_font_face'         => self::REASON_INTERNAL,
		'product'              => self::REASON_OWN_ABILITIES,
		'product_variation'    => self::REASON_OWN_ABILITIES,
		'shop_order'           => self::REASON_OWN_ABILITIES,
		'shop_order_refund'    => self::REASON_OWN_ABILITIES,
		'shop_order_placehold' => self::REASON_OWN_ABILITIES,
		'shop_coupon'          => self::REASON_OWN_ABILITIES,
	);

	/**
	 * Taxonomies never touched.
	 *
	 * @var array<int, string>
	 */
	private const EXCLUDED_TAXONOMIES = array(
		'nav_menu',
		'link_category',
		'post_format',
		'wp_theme',
		'wp_template_part_area',
		'wp_pattern_category',
	);

	/**
	 * Whether a post type may be read and changed.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function is_supported( string $post_type ): bool {
		return '' === self::exclusion_reason( $post_type );
	}

	/**
	 * Why a registered post type is excluded, or '' when it is supported.
	 *
	 * An unregistered type is excluded as internal: there is nothing to touch.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function exclusion_reason( string $post_type ): string {
		if ( isset( self::EXCLUDED[ $post_type ] ) ) {
			return self::EXCLUDED[ $post_type ];
		}

		$object = get_post_type_object( $post_type );

		if ( ! $object instanceof \WP_Post_Type ) {
			return self::REASON_INTERNAL;
		}

		if ( ! $object->show_ui ) {
			return self::REASON_NO_ADMIN_SCREEN;
		}

		if ( null === self::base_of( $object ) ) {
			return self::REASON_CUSTOM_CAPS;
		}

		return '';
	}

	/**
	 * Supported post types, name => object.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function supported(): array {
		$supported = array();

		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			if ( $object instanceof \WP_Post_Type && self::is_supported( (string) $name ) ) {
				$supported[ (string) $name ] = $object;
			}
		}

		return $supported;
	}

	/**
	 * Registered post types that are excluded, name => reason.
	 *
	 * @return array<string, string>
	 */
	public static function excluded(): array {
		$excluded = array();

		foreach ( get_post_types( array(), 'names' ) as $name ) {
			$reason = self::exclusion_reason( (string) $name );

			if ( '' !== $reason ) {
				$excluded[ (string) $name ] = $reason;
			}
		}

		return $excluded;
	}

	/**
	 * `post` or `page`: the core type whose capabilities this type uses, or null.
	 *
	 * Compared capability by capability, so a type that renames even one is
	 * treated as having capabilities of its own.
	 *
	 * @param \WP_Post_Type $type The post type.
	 */
	public static function base_of( \WP_Post_Type $type ): ?string {
		if ( ! $type->map_meta_cap ) {
			return null;
		}

		$caps = self::primitive_caps( $type );

		foreach ( array( 'post', 'page' ) as $base ) {
			$core = get_post_type_object( $base );

			if ( $core instanceof \WP_Post_Type && self::primitive_caps( $core ) === $caps ) {
				return $base;
			}
		}

		return null;
	}

	/**
	 * Whether a taxonomy may be read and assigned for a supported post type.
	 *
	 * Supported when it has an admin screen, belongs to at least one
	 * supported post type, and uses the default term capabilities
	 * (`manage_categories` to manage, `edit_posts` to assign), whether it
	 * names them directly or through the category and tag meta capabilities.
	 *
	 * @param string $taxonomy  Taxonomy name.
	 * @param string $post_type Only for this post type, or '' for any supported one.
	 */
	public static function is_supported_taxonomy( string $taxonomy, string $post_type = '' ): bool {
		if ( in_array( $taxonomy, self::EXCLUDED_TAXONOMIES, true ) ) {
			return false;
		}

		$object = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy || ! $object->show_ui ) {
			return false;
		}

		$cap = $object->cap;

		$manages = array( $cap->manage_terms, $cap->edit_terms, $cap->delete_terms );

		foreach ( $manages as $term_cap ) {
			if ( array( 'manage_categories' ) !== self::primitive_of( (string) $term_cap ) ) {
				return false;
			}
		}

		if ( array( 'edit_posts' ) !== self::primitive_of( (string) $cap->assign_terms ) ) {
			return false;
		}

		$types = '' === $post_type ? array_keys( self::supported() ) : ( self::is_supported( $post_type ) ? array( $post_type ) : array() );

		return array() !== array_intersect( (array) $object->object_type, $types );
	}

	/**
	 * Supported taxonomies of one post type, name => object.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function taxonomies_of( string $post_type ): array {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $object ) {
			if ( $object instanceof \WP_Taxonomy && self::is_supported_taxonomy( (string) $name, $post_type ) ) {
				$taxonomies[ (string) $name ] = $object;
			}
		}

		return $taxonomies;
	}

	/**
	 * The primitive capabilities a term capability stands for.
	 *
	 * Categories and tags name meta capabilities (`edit_categories`,
	 * `assign_post_tags`) that map_meta_cap() turns into `manage_categories`
	 * and `edit_posts`; a custom taxonomy names those primitives directly.
	 * Both are the defaults, and both must be recognised as such.
	 *
	 * @param string $cap A taxonomy's capability.
	 * @return array<int, string>
	 */
	private static function primitive_of( string $cap ): array {
		return array_values( array_unique( map_meta_cap( $cap, 0 ) ) );
	}

	/**
	 * A post type's primitive capabilities, with the meta ones left out.
	 *
	 * @param \WP_Post_Type $type The post type.
	 * @return array<string, string>
	 */
	private static function primitive_caps( \WP_Post_Type $type ): array {
		$caps = (array) $type->cap;

		unset( $caps['edit_post'], $caps['read_post'], $caps['delete_post'] );
		ksort( $caps );

		return $caps;
	}
}
