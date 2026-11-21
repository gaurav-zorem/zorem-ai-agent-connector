<?php
/**
 * Registers this plugin's own WordPress content abilities.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The core WordPress abilities: posts, pages, terms, comments, media,
 * users, menus, custom post types and site tools.
 *
 * Registered like any other plugin's abilities, so AbilityGate wraps each one
 * as it registers and no agent can call one it was not granted. Nothing here
 * decides who may call what.
 *
 * This class also remembers the exact ability objects it registered. The
 * capabilities these abilities need include ones CapabilityBridge never lends
 * on an author's say-so (`list_users`, `upload_files`, `edit_others_posts`),
 * so CapMapLoader trusts our list only for an ability that is the object we
 * registered. An ability name is not proof of who registered it (CS-SEC-5):
 * another plugin that registered `ai-agent-os/get-users` first, or replaced
 * ours, gets nothing from our list.
 */
final class CoreAbilities {

	/**
	 * Every ability class. Each has `NAME`, `CAPS` and `definition()`.
	 *
	 * @var array<int, class-string>
	 */
	public const CLASSES = array(
		Content\GetPosts::class,
		Content\GetPost::class,
		Content\GetPages::class,
		Content\GetPage::class,
		Content\GetCategories::class,
		Content\GetTags::class,
		Comments\GetComments::class,
		Media\GetMedia::class,
		Users\GetUsers::class,
		Cpt\ListPostTypes::class,
		Cpt\GetCptItems::class,
		Cpt\GetCptItem::class,
		Cpt\GetCptTaxonomies::class,
		Cpt\GetTermMeta::class,
		Site\SiteStatistics::class,
		Site\GetPostMeta::class,
		Site\GetActivePlugins::class,
		Menus\ListMenus::class,
		Menus\GetMenu::class,
		Content\CreatePost::class,
		Content\UpdatePost::class,
		Content\DeletePost::class,
		Content\CreateCategory::class,
		Content\CreateTag::class,
		Content\CreatePage::class,
		Content\DuplicatePost::class,
		Content\AssignPostTerms::class,
		Comments\ModerateComment::class,
		Comments\ReplyToComment::class,
		Comments\UpdateComment::class,
		Media\UploadImageFromUrl::class,
		Cpt\CreateCptItem::class,
		Cpt\UpdateCptItem::class,
		Cpt\DeleteCptItem::class,
		Cpt\AssignCptTerms::class,
		Cpt\UpdateTermMeta::class,
		Cpt\UpdateTerm::class,
		Menus\CreateMenu::class,
		Menus\AddMenuItem::class,
		Menus\UpdateMenuItem::class,
		Site\SearchReplace::class,
		Site\UpdatePostMeta::class,
		Site\RefreshCache::class,
	);

	/**
	 * The abilities this class registered, name => the registered object.
	 *
	 * @var array<string, \WP_Ability>
	 */
	private array $registered = array();

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the categories.
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::categories() as $slug => $definition ) {
			if ( ! wp_has_ability_category( $slug ) ) {
				wp_register_ability_category( $slug, $definition );
			}
		}
	}

	/**
	 * Register every ability whose name is still free.
	 *
	 * Authorisation is not decided here. Every class in `CLASSES` supplies its
	 * own `permission_callback` in `definition()`, and each one resolves the
	 * object named in the input and asks WordPress about that object —
	 * `read_post`, `edit_post`, `delete_post`, `edit_comment`, `edit_term`, or
	 * the target post type's or taxonomy's own capabilities. See
	 * `Abilities\Support\Access`, which all of them call, for why a blanket
	 * capability check is not enough. The write and delete paths check again
	 * before they act.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::CLASSES as $class ) {
			$name = (string) $class::NAME;

			if ( wp_has_ability( $name ) ) {
				continue;
			}

			$ability = wp_register_ability( $name, $class::definition() );

			if ( $ability instanceof \WP_Ability ) {
				$this->registered[ $name ] = $ability;
			}
		}
	}

	/**
	 * The capabilities to lend an ability, if it is one this class registered.
	 *
	 * @param \WP_Ability $ability The ability about to run.
	 * @return array<int, string>|null Null when the ability is not ours.
	 */
	public function caps_for( \WP_Ability $ability ): ?array {
		$name = $ability->get_name();

		if ( ! isset( $this->registered[ $name ] ) || $this->registered[ $name ] !== $ability ) {
			return null;
		}

		$class = self::class_for( $name );

		return null === $class ? null : array_values( (array) $class::CAPS );
	}

	/**
	 * Names of the abilities this class registered.
	 *
	 * @return array<int, string>
	 */
	public function registered_names(): array {
		return array_keys( $this->registered );
	}

	/**
	 * The class of an ability name, or null.
	 *
	 * @param string $name Ability name.
	 * @return class-string|null
	 */
	public static function class_for( string $name ): ?string {
		foreach ( self::CLASSES as $class ) {
			if ( $class::NAME === $name ) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * Category labels and descriptions, slug => definition.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function categories(): array {
		return array(
			// Who-am-I: the agent asking about itself.
			'ai-agent-os'          => array(
				'label'       => __( 'Zorem AI Agent Connector', 'zorem-ai-agent-connector' ),
				'description' => __( 'The agent asking about itself.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-content'  => array(
				'label'       => __( 'Posts', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read and change blog posts and their categories and tags.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-pages'    => array(
				'label'       => __( 'Pages', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read and create pages.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-comments' => array(
				'label'       => __( 'Comments', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read and moderate comments.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-media'    => array(
				'label'       => __( 'Media', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read the media library and add images to it.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-users'    => array(
				'label'       => __( 'Users', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read the site\'s user list.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-menus'    => array(
				'label'       => __( 'Menus', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read and change navigation menus.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-cpt'      => array(
				'label'       => __( 'Other content', 'zorem-ai-agent-connector' ),
				'description' => __( 'Read and change custom post type items and their terms.', 'zorem-ai-agent-connector' ),
			),
			'ai-agent-os-site'     => array(
				'label'       => __( 'Maintenance', 'zorem-ai-agent-connector' ),
				'description' => __( 'Site statistics, post meta, active plugins and cache.', 'zorem-ai-agent-connector' ),
			),
		);
	}
}
