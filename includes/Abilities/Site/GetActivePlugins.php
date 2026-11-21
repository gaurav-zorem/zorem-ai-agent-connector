<?php
/**
 * The `ai-agent-os/get-active-plugins` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Site;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * The active plugins' names, slugs and versions.
 *
 * Needs `activate_plugins`, as the Plugins screen does: a plugin list with
 * versions is what someone looking for a vulnerable plugin wants first, so
 * it is not something every content agent should see. The capability is
 * lent for this read only; nothing here activates or changes a plugin. No
 * file paths, authors or URLs are returned.
 */
final class GetActivePlugins {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-active-plugins';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'activate_plugins' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'See installed plugins', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists the plugins active on the site, with name, slug and version. Use it to find out which features (SEO, shop, forms, languages) the site has.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-site',
			'input_schema'        => Schema::input( array() ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'           => array( 'type' => 'string' ),
								'slug'           => array( 'type' => 'string' ),
								'version'        => array( 'type' => 'string' ),
								'network_active' => array( 'type' => 'boolean' ),
							),
						),
					),
				),
			),
			'permission_callback' => static fn(): bool => current_user_can( 'activate_plugins' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function execute(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$network   = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$active    = array_unique( array_merge( (array) get_option( 'active_plugins', array() ), $network ) );
		$plugins   = array();

		foreach ( $active as $file ) {
			$file = (string) $file;

			if ( ! isset( $installed[ $file ] ) ) {
				continue;
			}

			$plugins[] = array(
				'name'           => (string) ( $installed[ $file ]['Name'] ?? '' ),
				'slug'           => false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ),
				'version'        => (string) ( $installed[ $file ]['Version'] ?? '' ),
				'network_active' => in_array( $file, $network, true ),
			);
		}

		usort( $plugins, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

		return array( 'plugins' => $plugins );
	}
}
