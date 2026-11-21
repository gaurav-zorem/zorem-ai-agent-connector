<?php
/**
 * Environment requirements.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

use ZoremAgentBridge\V1\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the plugin may boot, and records why not.
 *
 * Every failure has a slug for logs and tests, and a sentence for the admin
 * that names the cause and the fix. A failure makes the plugin inert; it
 * never fatals.
 *
 * Detection and wording are separate on purpose. The check runs on
 * `plugins_loaded`, before `init`, and calling `__()` that early makes
 * WordPress (6.7+) log "translation loading was triggered too early" on every
 * request. So `are_met()` and `failed_slugs()` translate nothing, and the
 * sentences are built only when `failures()` is asked — by the admin notice,
 * which renders long after `init`.
 */
final class Requirements {

	/** The MCP Adapter's main class. */
	public const ADAPTER_CLASS = 'WP\\MCP\\Core\\McpAdapter';

	/** Option holding the auth salt fingerprint seen when tokens were last issued. */
	public const SALT_FINGERPRINT_OPTION = 'ai_agent_os_salt_fingerprint';

	/**
	 * The detected adapter, once looked up.
	 *
	 * @var array{available: bool, provenance: string, version: string, path: string}|null
	 */
	private ?array $adapter = null;


	/** Failure slugs. */
	public const PHP_TOO_OLD       = 'php_too_old';
	public const WP_TOO_OLD        = 'wp_too_old';
	public const BRIDGE_MISSING    = 'bridge_missing';
	public const BRIDGE_TOO_OLD    = 'bridge_incompatible';
	public const LEGACY_ACTIVE     = 'legacy_plugin_active';
	public const ABILITIES_MISSING = 'abilities_api_missing';

	/**
	 * Failed checks from the last run, in check order.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $failed = null;

	/**
	 * Whether every requirement is met. Translates nothing.
	 */
	public function are_met(): bool {
		return array() === $this->failed_slugs();
	}

	/**
	 * The slugs of the failed checks. Translates nothing.
	 *
	 * @return array<int, string>
	 */
	public function failed_slugs(): array {
		if ( null === $this->failed ) {
			$this->failed = $this->check();
		}

		return $this->failed;
	}

	/**
	 * The failures as slug => human-readable message.
	 *
	 * Translates, so call it from `init` or later.
	 *
	 * @return array<string, string>
	 */
	public function failures(): array {
		$messages = array();

		foreach ( $this->failed_slugs() as $slug ) {
			$messages[ $slug ] = $this->message( $slug );
		}

		return $messages;
	}

	/**
	 * Run every check.
	 *
	 * @return array<int, string>
	 */
	private function check(): array {
		$failed = array();

		if ( version_compare( PHP_VERSION, AI_AGENT_OS_MIN_PHP, '<' ) ) {
			$failed[] = self::PHP_TOO_OLD;
		}

		if ( version_compare( (string) get_bloginfo( 'version' ), AI_AGENT_OS_MIN_WP, '<' ) ) {
			$failed[] = self::WP_TOO_OLD;
		} elseif ( ! function_exists( 'wp_register_ability' ) ) {
			$failed[] = self::ABILITIES_MISSING;
		}

		if ( ! class_exists( Bridge::class ) ) {
			$failed[] = self::BRIDGE_MISSING;
		} elseif ( ! Bridge::satisfies( AI_AGENT_OS_MIN_BRIDGE ) ) {
			$failed[] = self::BRIDGE_TOO_OLD;
		}

		if ( Coexistence::legacy_is_active() ) {
			$failed[] = self::LEGACY_ACTIVE;
		}

		return $failed;
	}

	/**
	 * The sentence for one failure: the cause and the fix.
	 *
	 * @param string $slug A failure slug.
	 */
	private function message( string $slug ): string {
		if ( self::PHP_TOO_OLD === $slug ) {
			return sprintf(
				/* translators: 1: plugin name, 2: required PHP version, 3: current PHP version. */
				__( '%1$s needs PHP %2$s or newer. This site runs PHP %3$s — ask your host to upgrade it.', 'zorem-ai-agent-connector' ),
				AI_AGENT_OS_NAME,
				AI_AGENT_OS_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( self::WP_TOO_OLD === $slug ) {
			return sprintf(
				/* translators: 1: plugin name, 2: required WordPress version. */
				__( '%1$s needs WordPress %2$s or newer. Update WordPress to use it.', 'zorem-ai-agent-connector' ),
				AI_AGENT_OS_NAME,
				AI_AGENT_OS_MIN_WP
			);
		}

		if ( self::ABILITIES_MISSING === $slug ) {
			return sprintf(
				/* translators: %s: plugin name. */
				__( '%s needs the WordPress Abilities API, which this WordPress install does not provide.', 'zorem-ai-agent-connector' ),
				AI_AGENT_OS_NAME
			);
		}

		if ( self::BRIDGE_MISSING === $slug ) {
			return sprintf(
				/* translators: %s: plugin name. */
				__( '%s could not load its bundled Agent Runtime Bridge. Reinstall the plugin to restore the missing files.', 'zorem-ai-agent-connector' ),
				AI_AGENT_OS_NAME
			);
		}

		if ( self::BRIDGE_TOO_OLD === $slug ) {
			return sprintf(
				/* translators: 1: plugin name, 2: loaded bridge version, 3: required bridge version. */
				__( '%1$s needs Agent Runtime Bridge %3$s, but another plugin on this site loaded version %2$s. Update that plugin, or deactivate it.', 'zorem-ai-agent-connector' ),
				AI_AGENT_OS_NAME,
				Bridge::version(),
				AI_AGENT_OS_MIN_BRIDGE
			);
		}

		if ( self::LEGACY_ACTIVE === $slug ) {
			return sprintf(
				/* translators: 1: plugin name, 2: the conflicting plugin's name. */
				__( '%1$s is paused because %2$s is also active. Both would govern this site and could contradict each other. Deactivate %2$s to use this plugin.', 'zorem-ai-agent-connector' ),
				AI_AGENT_OS_NAME,
				Coexistence::legacy_plugin_name()
			);
		}

		return $slug;
	}

	/**
	 * Detect the MCP Adapter, loading the bundled copy if nothing else provides it.
	 *
	 * Provenance matters for support: WooCommerce vendors its own copy, and when
	 * both are installed whichever autoloader wins is the one actually running.
	 *
	 * @return array{available: bool, provenance: string, version: string, path: string}
	 */
	public function detect_adapter(): array {
		if ( null !== $this->adapter ) {
			return $this->adapter;
		}

		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			// Only reached when nothing has provided the classes, so two copies are never both loaded.
			BundledAdapterLoader::register();
		}

		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			$this->adapter = array(
				'available'  => false,
				'provenance' => 'none',
				'version'    => '',
				'path'       => '',
			);

			return $this->adapter;
		}

		try {
			$path = (string) ( new \ReflectionClass( self::ADAPTER_CLASS ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			$path = '';
		}

		$this->adapter = array(
			'available'  => true,
			'provenance' => $this->classify_adapter_path( $path ),
			'version'    => defined( self::ADAPTER_CLASS . '::VERSION' ) ? (string) constant( self::ADAPTER_CLASS . '::VERSION' ) : '',
			'path'       => $path,
		);

		return $this->adapter;
	}

	/**
	 * Whether a usable MCP Adapter is present.
	 */
	public function has_mcp_adapter(): bool {
		return (bool) $this->detect_adapter()['available'];
	}

	/**
	 * Fingerprint of the current auth salt: a keyed MAC of a fixed string, not a hash of the salt.
	 */
	public function current_salt_fingerprint(): string {
		return hash_hmac( 'sha256', 'ai-agent-os-salt-fingerprint', wp_salt( 'auth' ) );
	}

	/**
	 * Remember the current salt's fingerprint. Called whenever a token is issued.
	 */
	public function record_salt_fingerprint(): void {
		update_option( self::SALT_FINGERPRINT_OPTION, $this->current_salt_fingerprint(), false );
	}

	/**
	 * Whether the auth salt changed since a token was last issued.
	 *
	 * Tokens are stored as an HMAC keyed with that salt, so a rotation stops
	 * every token at once, and the only symptom the agent sees is "invalid
	 * token". This is the diagnosis that symptom needs.
	 */
	public function salt_has_rotated(): bool {
		$stored = get_option( self::SALT_FINGERPRINT_OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		return ! hash_equals( $stored, $this->current_salt_fingerprint() );
	}

	/**
	 * Everything the Diagnostics screen reports about the platform.
	 *
	 * @return array<string, mixed>
	 */
	public function get_diagnostics(): array {
		$adapter = $this->detect_adapter();
		$foreign = $this->detect_foreign_mcp_routes();

		return array(
			'php_version'        => PHP_VERSION,
			'wp_version'         => get_bloginfo( 'version' ),
			'plugin_version'     => AI_AGENT_OS_VERSION,
			'bridge_version'     => Bridge::version(),
			'woocommerce'        => class_exists( 'WooCommerce' ) ? ( defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' ) : false,
			'adapter'            => array(
				'available'       => $adapter['available'],
				'provenance'      => $adapter['provenance'],
				'version'         => $adapter['version'],
				'path'            => $this->relative_path( $adapter['path'] ),
				'bundled_version' => AI_AGENT_OS_BUNDLED_ADAPTER_VERSION,
				'version_matches' => '' === $adapter['version'] || AI_AGENT_OS_BUNDLED_ADAPTER_VERSION === $adapter['version'],
			),
			'salt_rotated'       => $this->salt_has_rotated(),
			'schema'             => array(
				'db_version'      => get_option( Installer::DB_VERSION_OPTION, '' ),
				'expected'        => Installer::DB_VERSION,
				'missing_tables'  => Installer::missing_tables(),
				'install_failure' => Installer::failure(),
			),
			'foreign_mcp_routes' => $foreign['routes'],

			/*
			 * An empty route list means two different things: "no other servers
			 * exist" or "we could not look". This says which.
			 */
			'foreign_mcp_error'  => $foreign['error'],
			'is_multisite'       => is_multisite(),
		);
	}

	/**
	 * Other MCP servers registered on this site.
	 *
	 * Every additional server is another door into the same abilities, governed
	 * by that server's own rules. The gate still denies agent calls through them,
	 * but the administrator should know they exist.
	 *
	 * @return array{routes: array<int, array{id: string, route: string}>, error: string}
	 */
	public function detect_foreign_mcp_routes(): array {
		$adapter_class = self::ADAPTER_CLASS;

		if ( ! $this->has_mcp_adapter() || ! method_exists( $adapter_class, 'instance' ) ) {
			return array(
				'routes' => array(),
				'error'  => '',
			);
		}

		try {
			$servers = $adapter_class::instance()->get_servers();
		} catch ( \Throwable $e ) {
			// Broad on purpose (a third-party adapter), and not silent: the reason is reported.
			return array(
				'routes' => array(),
				'error'  => $e->getMessage(),
			);
		}

		$found = array();

		foreach ( (array) $servers as $server_id => $server ) {
			if ( \AiAgentOs\Mcp\Routes::SERVER_ID === $server_id ) {
				continue;
			}

			$found[] = array(
				'id'    => (string) $server_id,
				'route' => is_object( $server ) && method_exists( $server, 'get_server_route' ) && method_exists( $server, 'get_server_route_namespace' )
					? (string) $server->get_server_route_namespace() . '/' . (string) $server->get_server_route()
					: '',
			);
		}

		return array(
			'routes' => $found,
			'error'  => '',
		);
	}

	/**
	 * Shorten an absolute path for display, so the server root is never printed.
	 *
	 * @param string $path Absolute path.
	 */
	private function relative_path( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$normalized = str_replace( '\\', '/', $path );
		$content    = str_replace( '\\', '/', WP_CONTENT_DIR );

		return 0 === strpos( $normalized, $content ) ? 'wp-content' . substr( $normalized, strlen( $content ) ) : basename( $normalized );
	}

	/**
	 * Where the live adapter came from.
	 *
	 * @param string $path Absolute path to the adapter class file.
	 */
	private function classify_adapter_path( string $path ): string {
		if ( '' === $path ) {
			return 'unknown';
		}

		$normalized = str_replace( '\\', '/', $path );

		if ( 0 === strpos( $normalized, str_replace( '\\', '/', AI_AGENT_OS_PATH ) ) ) {
			return 'bundled';
		}

		return false !== strpos( $normalized, '/plugins/woocommerce/' ) ? 'woocommerce' : 'external';
	}
}
