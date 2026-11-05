<?php
/**
 * Adding and changing navigation menu items.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Media\UploadImageFromUrl;
use AiAgentOs\Abilities\Menus\GetMenu;

/**
 * The one write path behind add-menu-item and update-menu-item.
 *
 * An item links to a published post, page or supported CPT item, to a term
 * of a supported taxonomy, or to an http(s) address. A link to a draft
 * would show the draft's title in the menu before it is public; a
 * `javascript:` or `data:` address would run in every visitor's browser.
 *
 * wp_update_nav_menu_item() replaces every field it is not given with its
 * default, so an update starts from the item's current values and changes
 * only what the input names.
 */
final class MenuItemWriter {

	/** Item types. */
	public const TYPES = array( 'post_type', 'taxonomy', 'custom' );

	/** Longest item title. */
	public const TITLE_MAX = 200;

	/**
	 * Input properties of an item.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array {
		return array(
			'type'      => array(
				'type'        => 'string',
				'enum'        => self::TYPES,
				'description' => __( 'What the item links to: "post_type" (a post, page or item; set object_id), "taxonomy" (a category, tag or term; set object_id) or "custom" (set url).', 'zorem-ai-agent-connector' ),
			),
			'object_id' => Schema::id( __( 'The post, page, item or term ID the item links to.', 'zorem-ai-agent-connector' ) ),
			'url'       => array(
				'type'        => 'string',
				'maxLength'   => UploadImageFromUrl::URL_MAX,
				'description' => __( 'For a custom item: the http or https address to link to.', 'zorem-ai-agent-connector' ),
			),
			'title'     => array(
				'type'        => 'string',
				'maxLength'   => self::TITLE_MAX,
				'description' => __( 'The text shown in the menu. Leave out to use the linked item\'s own title.', 'zorem-ai-agent-connector' ),
			),
			'parent_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'ID of the menu item to nest this under, or 0 for top level.', 'zorem-ai-agent-connector' ),
			),
			'position'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 10000,
				'description' => __( 'Position in the menu, starting at 1. Leave out to add at the end.', 'zorem-ai-agent-connector' ),
			),
		);
	}

	/**
	 * Save an item.
	 *
	 * @param int                  $menu_id Menu ID.
	 * @param int                  $item_id Item ID, or 0 for a new item.
	 * @param array<string, mixed> $input   Ability input.
	 * @return array<string, mixed>|\WP_Error The saved item, as get-menu returns it.
	 */
	public static function save( int $menu_id, int $item_id, array $input ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu instanceof \WP_Term ) {
			return self::not_found( __( 'No menu with that ID was found.', 'zorem-ai-agent-connector' ) );
		}

		$args = self::current( (int) $menu->term_id, $item_id );

		if ( $args instanceof \WP_Error ) {
			return $args;
		}

		$type = isset( $input['type'] ) ? (string) $input['type'] : (string) $args['menu-item-type'];

		if ( isset( $input['type'] ) || isset( $input['object_id'] ) || isset( $input['url'] ) ) {
			$target = self::target( $type, $input, $args );

			if ( $target instanceof \WP_Error ) {
				return $target;
			}

			$args = array_merge( $args, $target );
		}

		if ( isset( $input['title'] ) ) {
			$args['menu-item-title'] = mb_substr( sanitize_text_field( (string) $input['title'] ), 0, self::TITLE_MAX );
		}

		if ( isset( $input['parent_id'] ) ) {
			$parent = absint( $input['parent_id'] );

			if ( $parent > 0 && ( $parent === $item_id || ! self::is_item_of( $parent, (int) $menu->term_id ) ) ) {
				return new \WP_Error( 'ai_agent_os_invalid_parent', __( 'The parent must be another item of the same menu.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			$args['menu-item-parent-id'] = $parent;
		}

		if ( isset( $input['position'] ) ) {
			$args['menu-item-position'] = absint( $input['position'] );
		}

		$saved = wp_update_nav_menu_item( (int) $menu->term_id, $item_id, wp_slash( $args ) );

		if ( $saved instanceof \WP_Error ) {
			return $saved;
		}

		$item = wp_setup_nav_menu_item( get_post( (int) $saved ) );

		return is_object( $item ) && isset( $item->ID ) ? GetMenu::project( $item ) : self::not_found( __( 'WordPress did not save the menu item.', 'zorem-ai-agent-connector' ) );
	}

	/**
	 * The item's current fields, or the defaults for a new one.
	 *
	 * @param int $menu_id Menu ID.
	 * @param int $item_id Item ID, or 0.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function current( int $menu_id, int $item_id ) {
		$args = array(
			'menu-item-status'    => 'publish',
			'menu-item-type'      => 'custom',
			'menu-item-object'    => 'custom',
			'menu-item-object-id' => 0,
			'menu-item-url'       => '',
			'menu-item-title'     => '',
			'menu-item-parent-id' => 0,
			'menu-item-position'  => 0,
		);

		if ( 0 === $item_id ) {
			return $args;
		}

		if ( ! self::is_item_of( $item_id, $menu_id ) ) {
			return self::not_found( __( 'That menu has no item with that ID.', 'zorem-ai-agent-connector' ) );
		}

		$item = wp_setup_nav_menu_item( get_post( $item_id ) );

		return array_merge(
			$args,
			array(
				'menu-item-type'        => (string) $item->type,
				'menu-item-object'      => (string) $item->object,
				'menu-item-object-id'   => (int) $item->object_id,
				'menu-item-url'         => (string) $item->url,
				'menu-item-title'       => (string) $item->post_title,
				'menu-item-parent-id'   => (int) $item->menu_item_parent,
				'menu-item-position'    => (int) $item->menu_order,
				'menu-item-description' => (string) $item->description,
				'menu-item-attr-title'  => (string) $item->attr_title,
				'menu-item-target'      => (string) $item->target,
				'menu-item-classes'     => implode( ' ', (array) $item->classes ),
				'menu-item-xfn'         => (string) $item->xfn,
			)
		);
	}

	/**
	 * The link fields for a type and target.
	 *
	 * @param string               $type  Item type.
	 * @param array<string, mixed> $input Ability input.
	 * @param array<string, mixed> $args  The item's current fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function target( string $type, array $input, array $args ) {
		$object_id = isset( $input['object_id'] ) ? absint( $input['object_id'] ) : (int) $args['menu-item-object-id'];

		if ( 'post_type' === $type ) {
			$post = PostReader::find( $object_id );

			if ( $post instanceof \WP_Error || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				return new \WP_Error( 'ai_agent_os_invalid_target', __( 'A menu item can link only to a published, unprotected post, page or item.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			return array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => (string) $post->post_type,
				'menu-item-object-id' => (int) $post->ID,
				'menu-item-url'       => '',
			);
		}

		if ( 'taxonomy' === $type ) {
			$term = get_term( $object_id );

			if ( ! $term instanceof \WP_Term || ! ContentTypes::is_supported_taxonomy( (string) $term->taxonomy ) ) {
				return new \WP_Error( 'ai_agent_os_invalid_target', __( 'A menu item can link only to a term of a supported taxonomy.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			return array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => (string) $term->taxonomy,
				'menu-item-object-id' => (int) $term->term_id,
				'menu-item-url'       => '',
			);
		}

		if ( 'custom' === $type ) {
			$url    = trim( (string) ( $input['url'] ?? $args['menu-item-url'] ) );
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === esc_url_raw( $url, array( 'http', 'https' ) ) ) {
				return new \WP_Error( 'ai_agent_os_invalid_url', __( 'A custom menu item needs an http or https address.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			return array(
				'menu-item-type'      => 'custom',
				'menu-item-object'    => 'custom',
				'menu-item-object-id' => 0,
				'menu-item-url'       => esc_url_raw( $url, array( 'http', 'https' ) ),
			);
		}

		return new \WP_Error( 'ai_agent_os_invalid_type', __( 'That item type is not supported.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
	}

	/**
	 * Whether a post is an item of a menu.
	 *
	 * @param int $item_id Item ID.
	 * @param int $menu_id Menu ID.
	 */
	private static function is_item_of( int $item_id, int $menu_id ): bool {
		return is_nav_menu_item( $item_id ) && has_term( $menu_id, 'nav_menu', $item_id );
	}

	/**
	 * A not-found error.
	 *
	 * @param string $message The message.
	 */
	private static function not_found( string $message ): \WP_Error {
		return new \WP_Error( 'ai_agent_os_not_found', $message, array( 'status' => 404 ) );
	}
}
