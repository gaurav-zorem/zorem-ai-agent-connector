<?php
/**
 * Maps files to plugins, and enumerates a plugin's own source.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "which plugin owns this file" and "which files are this plugin's own".
 *
 * Both questions are less obvious than they look. WordPress does not record
 * which plugin registered anything, so attribution starts from a file path and
 * works backwards. And a plugin directory is not the same as a plugin's own
 * code: bundled dependencies, build output and test suites all live inside it
 * and describe someone else's software.
 */
final class PluginLocator {

	/**
	 * Directories never treated as a plugin's own source.
	 *
	 * Found the hard way while sizing the corpus: this plugin measured 11.3 MB
	 * because node_modules sits inside it. Scanning bundled dependencies would
	 * fill the Brain with minified JavaScript and third-party library source,
	 * attributed to whichever plugin happens to vendor it.
	 *
	 * @var array<int, string>
	 */
	private const EXCLUDED_DIRS = array(
		'node_modules',
		'vendor',
		'vendor-bundled',
		'vendor-prefixed',
		'build',
		'dist',
		'tests',
		'test',
		'__tests__',
		'languages',
		'.git',
		'.github',
		'coverage',
	);

	/**
	 * Files larger than this are skipped: minified or generated, not readable source.
	 */
	private const MAX_FILE_BYTES = 900000;

	/**
	 * Cached plugin directory => slug, longest path first.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $plugin_dirs = null;

	/**
	 * Cached file path => slug resolutions.
	 *
	 * @var array<string, string|null>
	 */
	private array $file_cache = array();

	/**
	 * The directory plugins are read from.
	 *
	 * Everything here resolves paths through this rather than referring to
	 * WP_PLUGIN_DIR directly, so the whole extraction stack can be pointed at a
	 * fixture tree in tests — which is the only way to assert resume behaviour
	 * against a corpus of a known, fixed size. A site that relocates its
	 * plugins directory already has it in WP_PLUGIN_DIR.
	 *
	 * The filter is honoured only under the WordPress test suite. This
	 * directory decides which plugin owns a file — a proposal's owner, an
	 * audit event's source, an ability's plugin — so on a live site any plugin
	 * hooking it could point it into its own folder and have its files read
	 * as another plugin's.
	 */
	public static function plugins_dir(): string {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
			return rtrim( WP_PLUGIN_DIR, '/\\' );
		}

		/**
		 * Filters the directory extraction reads plugins from. Tests only.
		 *
		 * @param string $dir Absolute path, no trailing slash.
		 */
		return rtrim( (string) apply_filters( 'zorem_agent_brain_plugins_dir', WP_PLUGIN_DIR ), '/\\' );
	}

	/**
	 * The absolute path to one plugin's directory.
	 *
	 * @param string $slug Plugin slug.
	 */
	public static function path_for( string $slug ): string {
		return self::plugins_dir() . '/' . $slug;
	}

	/**
	 * All installed plugins as slug => plugin data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();

		foreach ( get_plugins() as $file => $data ) {
			$slug = $this->slug_from_plugin_file( (string) $file );

			$plugins[ $slug ] = array_merge(
				(array) $data,
				array(
					'slug' => $slug,
					'file' => (string) $file,
					'dir'  => self::path_for( $slug ),
				)
			);
		}

		return $plugins;
	}

	/**
	 * Active plugins only, as slug => plugin data.
	 *
	 * Extraction covers active plugins: a deactivated plugin's knowledge would
	 * describe software that is not running, which is worse than absent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		$all    = $this->all_plugins();
		$result = array();

		foreach ( $active as $file ) {
			$slug = $this->slug_from_plugin_file( (string) $file );

			if ( isset( $all[ $slug ] ) ) {
				$result[ $slug ] = $all[ $slug ];
			}
		}

		return $result;
	}

	/**
	 * Group plugins that are the same product installed twice.
	 *
	 * This install carries `ast-pro` and `advanced-shipment-tracking-pro` —
	 * one product, two directories. Keyed on text domain and name rather than
	 * slug, so duplicates collapse to the active copy and the other is recorded
	 * as a duplicate rather than extracted twice.
	 *
	 * @param array<string, array<string, mixed>> $plugins Plugins to group.
	 * @return array<string, array<int, string>> Identity => slugs.
	 */
	public function group_duplicates( array $plugins ): array {
		$groups = array();

		foreach ( $plugins as $slug => $data ) {
			$identity = strtolower( trim( (string) ( $data['TextDomain'] ?? '' ) ) );

			if ( '' === $identity ) {
				$identity = strtolower( trim( (string) ( $data['Name'] ?? $slug ) ) );
			}

			$groups[ $identity ][] = $slug;
		}

		return array_filter(
			$groups,
			static fn ( array $slugs ): bool => count( $slugs ) > 1
		);
	}

	/**
	 * The plugin slug owning a file, or null when it belongs to no plugin.
	 *
	 * Null is a real answer, not a failure: core files, mu-plugins and shared
	 * libraries all legitimately resolve to nothing, and attributing them to a
	 * plugin would be a confident lie.
	 *
	 * @param string $file Absolute file path.
	 */
	public function plugin_for_file( string $file ): ?string {
		if ( '' === $file ) {
			return null;
		}

		if ( array_key_exists( $file, $this->file_cache ) ) {
			return $this->file_cache[ $file ];
		}

		$normalized = $this->normalize( $file );
		$slug       = null;

		foreach ( $this->plugin_directories() as $dir => $candidate ) {
			if ( 0 === strpos( $normalized, $dir ) ) {
				$slug = $candidate;
				break;
			}
		}

		$this->file_cache[ $file ] = $slug;

		return $slug;
	}

	/**
	 * A plugin's own PHP files, excluding vendored and generated directories.
	 *
	 * @param string $slug        Plugin slug.
	 * @param int    $max_files   File-count budget.
	 * @param int    $max_bytes   Byte budget.
	 * @return array{files: array<int, string>, bytes: int, truncated: bool, skipped_large: int}
	 */
	public function source_files( string $slug, int $max_files = 1500, int $max_bytes = 12582912 ): array {
		$dir = self::path_for( $slug );

		$result = array(
			'files'         => array(),
			'bytes'         => 0,
			'truncated'     => false,
			'skipped_large' => 0,
		);

		if ( ! is_dir( $dir ) ) {
			return $result;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				function ( $current ): bool {
					if ( $current->isDir() ) {
						return ! in_array( $current->getFilename(), self::EXCLUDED_DIRS, true );
					}

					return 'php' === strtolower( $current->getExtension() );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$size = (int) $file->getSize();

			if ( $size > self::MAX_FILE_BYTES ) {
				++$result['skipped_large'];
				continue;
			}

			/*
			 * Budgets stop before exceeding, and the overflow is recorded.
			 * Silent truncation would let a partial Brain look complete, which
			 * is the failure mode an admin cannot detect from the outside.
			 */
			if ( count( $result['files'] ) >= $max_files || ( $result['bytes'] + $size ) > $max_bytes ) {
				$result['truncated'] = true;
				break;
			}

			$result['files'][] = $file->getPathname();
			$result['bytes']  += $size;
		}

		return $result;
	}

	/**
	 * How big a plugin's scannable source is, without reading any of it.
	 *
	 * The queue orders small plugins first, so it needs a size before it decides
	 * what to extract — but calling `source_files()` for that would build and
	 * hold a full path list for every queued plugin at once, which is the
	 * allocation pattern the memory findings warn against.
	 *
	 * This walks directory metadata only. `getSize()` is a stat, not a read, so
	 * the cost is filesystem calls rather than memory, and the result is capped:
	 * past `$cap_bytes` the exact total stops mattering, since every use of this
	 * number is a comparison against other plugins that are all smaller.
	 *
	 * @param string $slug      Plugin slug.
	 * @param int    $cap_bytes Stop counting past this; 0 to count everything.
	 * @return array{files: int, bytes: int, capped: bool}
	 */
	public function measure( string $slug, int $cap_bytes = 33554432 ): array {
		$dir = self::path_for( $slug );

		$result = array(
			'files'  => 0,
			'bytes'  => 0,
			'capped' => false,
		);

		if ( ! is_dir( $dir ) ) {
			return $result;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				function ( $current ): bool {
					if ( $current->isDir() ) {
						return ! in_array( $current->getFilename(), self::EXCLUDED_DIRS, true );
					}

					return 'php' === strtolower( $current->getExtension() );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			++$result['files'];
			$result['bytes'] += (int) $file->getSize();

			if ( $cap_bytes > 0 && $result['bytes'] >= $cap_bytes ) {
				$result['capped'] = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * Plugin directories, normalised, longest first so nested paths match precisely.
	 *
	 * @return array<string, string> Directory => slug.
	 */
	private function plugin_directories(): array {
		if ( null !== $this->plugin_dirs ) {
			return $this->plugin_dirs;
		}

		$dirs = array();

		foreach ( array_keys( $this->all_plugins() ) as $slug ) {
			$path = self::path_for( (string) $slug );

			$dirs[ $this->normalize( $path ) . '/' ] = (string) $slug;

			/*
			 * A symlinked plugin folder too. PHP reports a file's resolved path
			 * (`__FILE__`, stack frames), so a plugin linked into the plugins
			 * directory registers its abilities from a path outside it, and would
			 * otherwise belong to no plugin. The resolved path comes from the
			 * filesystem, so it is as trustworthy as the folder itself.
			 */
			$real = realpath( $path );

			if ( false !== $real && $this->normalize( $real ) !== $this->normalize( $path ) ) {
				$dirs[ $this->normalize( $real ) . '/' ] = (string) $slug;
			}
		}

		uksort( $dirs, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$this->plugin_dirs = $dirs;

		return $this->plugin_dirs;
	}

	/**
	 * Normalise a path for comparison across platforms.
	 *
	 * @param string $path Raw path.
	 */
	private function normalize( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	/**
	 * The slug for a plugin file such as "woocommerce/woocommerce.php".
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 */
	public function slug_from_plugin_file( string $plugin_file ): string {
		$dir = dirname( $plugin_file );

		return '.' === $dir ? basename( $plugin_file, '.php' ) : $dir;
	}

	/**
	 * The directories excluded from source scanning.
	 *
	 * @return array<int, string>
	 */
	public static function excluded_dirs(): array {
		return self::EXCLUDED_DIRS;
	}

	/**
	 * Clear caches. Used by tests.
	 */
	public function flush(): void {
		$this->plugin_dirs = null;
		$this->file_cache  = array();
	}
}
