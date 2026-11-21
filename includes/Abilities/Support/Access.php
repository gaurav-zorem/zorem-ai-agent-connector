<?php
/**
 * Object-level authorisation for the abilities.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * What the caller may do to the thing they named.
 *
 * Every ability's `permission_callback` asks one of these, and every one of
 * them resolves the object out of the input and asks WordPress about *that*
 * object rather than about the caller's role in general.
 *
 * ## Why the blanket capability was not enough
 *
 * `current_user_can( 'edit_posts' )` answers "could this person edit some post
 * somewhere", which is true for every Author on the site. It says nothing about
 * the post in the request. An ability that checks only the blanket capability
 * and then reads or writes whatever id it was handed is authorising the role,
 * not the action — so an Author could read another author's post meta, or edit
 * a post they cannot open in the editor.
 *
 * WordPress already answers the real question through its meta capabilities:
 * `read_post`, `edit_post`, `delete_post`, `edit_comment` and `edit_term` each
 * take an object id and run the same mapping the admin screens use, including
 * the `*_others_*` and `*_private_*` rules and anything a plugin filters in.
 * So these helpers pass the id through to core instead of re-deriving the rule.
 *
 * ## Why the post type's own capabilities
 *
 * A post type registered with its own `capability_type` is not governed by
 * `edit_posts` at all. Hard-coding `edit_posts` for the custom-post-type
 * abilities was wrong in both directions: it let through a caller who holds
 * `edit_posts` but none of the type's capabilities, and it refused a role that
 * holds the type's capabilities but not `edit_posts`. `create_in()` and
 * `can_list()` read the capabilities off the type, which is where WordPress
 * keeps the answer. Taxonomies are treated the same way.
 *
 * ## The agent is not exempt
 *
 * The agent runs as its own user with the capabilities `CapabilityBridge` lends
 * for the duration of one call, so these checks apply to it as well — which is
 * the point. A grant on the Permissions screen says the agent may use the
 * tool; this says what the tool may touch.
 */
final class Access {

	/**
	 * The object id in an ability's input, or 0 when none was given.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function id( $input ): int {
		return is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;
	}

	/**
	 * Whether the caller may read the post the input names.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function can_read_post( $input ): bool {
		$id = self::id( $input );

		return $id > 0 && current_user_can( 'read_post', $id );
	}

	/**
	 * Whether the caller may edit the post the input names.
	 *
	 * Used for writes, and for reading a post's meta: custom fields are not
	 * published content, so reading them is held to the same bar as changing
	 * the post.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function can_edit_post( $input ): bool {
		$id = self::id( $input );

		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	/**
	 * Whether the caller may edit the post named under another key.
	 *
	 * For inputs that point at a post without calling it `id` — an upload's
	 * `attach_to`, for instance. An absent or zero value is not a refusal: it
	 * means no post was named, and the caller decides what that means.
	 *
	 * @param mixed  $input Ability input.
	 * @param string $key   Property holding the post id.
	 */
	public static function can_edit_post_named( $input, string $key ): bool {
		$id = is_array( $input ) ? absint( $input[ $key ] ?? 0 ) : 0;

		return 0 === $id || current_user_can( 'edit_post', $id );
	}

	/**
	 * Whether the caller may trash the post the input names.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function can_delete_post( $input ): bool {
		$id = self::id( $input );

		return $id > 0 && current_user_can( 'delete_post', $id );
	}

	/**
	 * Whether the caller may create an item of a post type.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function can_create_in( string $post_type ): bool {
		$type = get_post_type_object( $post_type );

		if ( ! $type instanceof \WP_Post_Type ) {
			return false;
		}

		return current_user_can( (string) $type->cap->create_posts );
	}

	/**
	 * Whether the caller may create content of any post type this plugin supports.
	 *
	 * The coarse gate for the abilities that take the post type as input.
	 * `PostWriter::create()` then checks `create_posts` for the type actually
	 * asked for, and `ContentTypes` refuses a type this plugin does not
	 * support with a message that names it — more use to the caller than a
	 * bare "no permission", and it discloses nothing, since which post types a
	 * site has is not a secret.
	 */
	public static function can_create_any(): bool {
		foreach ( array_keys( ContentTypes::supported() ) as $post_type ) {
			if ( self::can_create_in( (string) $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the caller may list items of a post type at all.
	 *
	 * A coarse gate: `PostReader::list_items()` still checks `read_post` on
	 * every row before it is returned, so this only decides whether the listing
	 * runs, not what comes back from it.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function can_list( string $post_type ): bool {
		$type = get_post_type_object( $post_type );

		if ( ! $type instanceof \WP_Post_Type ) {
			return false;
		}

		return current_user_can( (string) $type->cap->edit_posts );
	}

	/**
	 * Whether the caller may list items of any post type this plugin supports.
	 *
	 * For the abilities that read across types. True when at least one
	 * supported type is listable, because the listing is filtered per item.
	 */
	public static function can_list_any(): bool {
		foreach ( array_keys( ContentTypes::supported() ) as $post_type ) {
			if ( self::can_list( (string) $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the caller may moderate or edit the comment the input names.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function can_edit_comment( $input ): bool {
		$id = self::id( $input );

		return $id > 0 && current_user_can( 'edit_comment', $id );
	}

	/**
	 * Whether the caller may read the comments on the post the input names.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function can_read_comments( $input ): bool {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}

		$post_id = is_array( $input ) ? absint( $input['post_id'] ?? 0 ) : 0;

		return 0 === $post_id || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Whether the caller may edit the term the input names.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function can_edit_term( $input ): bool {
		$id = self::id( $input );

		return $id > 0 && current_user_can( 'edit_term', $id );
	}

	/**
	 * Whether the caller may add terms to a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function can_manage_taxonomy( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy ) {
			return false;
		}

		return current_user_can( (string) $object->cap->manage_terms );
	}

	/**
	 * Whether the caller may read a taxonomy's terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function can_read_taxonomy( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy ) {
			return false;
		}

		return current_user_can( (string) $object->cap->assign_terms );
	}

	/**
	 * Whether the caller may edit the post the input names and assign terms
	 * in the taxonomy it names.
	 *
	 * @param mixed $input Ability input with `id` and `taxonomy`.
	 */
	public static function can_assign_terms( $input ): bool {
		if ( ! self::can_edit_post( $input ) ) {
			return false;
		}

		$taxonomy = is_array( $input ) ? sanitize_key( (string) ( $input['taxonomy'] ?? '' ) ) : '';
		$object   = '' === $taxonomy ? null : get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy ) {
			return false;
		}

		return current_user_can( (string) $object->cap->assign_terms );
	}
}
