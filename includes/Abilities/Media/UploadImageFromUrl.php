<?php
/**
 * The `ai-agent-os/upload-image-from-url` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Media;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Downloads an image from a public URL into the media library.
 *
 * The URL is attacker-steerable text (an agent reads the web), so the
 * download is treated as a request someone else chose:
 *
 *   - http or https only, and wp_http_validate_url() must accept it, which
 *     refuses localhost, private and reserved addresses, and odd ports;
 *   - the download goes through wp_safe_remote_get() (via download_url()),
 *     which applies the same check to every redirect;
 *   - the body is capped at MAX_BYTES and refused if it reached the cap;
 *   - the file's real type is read from its bytes, and only the image types
 *     in TYPES are kept, whatever the URL's extension claims;
 *   - the temporary file is removed on every path.
 */
final class UploadImageFromUrl {

	/** Ability name. */
	public const NAME = 'ai-agent-os/upload-image-from-url';

	/** Capabilities lent for the call: uploading, and attaching to an item. */
	public const CAPS = array( 'upload_files', ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/** Largest download kept, in bytes. */
	public const MAX_BYTES = 10485760;

	/** Longest URL. */
	public const URL_MAX = 2048;

	/** Seconds to wait for the download. */
	private const TIMEOUT = 30;

	/** Image types kept, extension => MIME type. */
	public const TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	);

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Upload image from URL', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Downloads a JPEG, PNG, GIF, WebP or AVIF image (up to 10 MB) from a public web address into the media library, optionally attached to a post or page.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-media',
			'input_schema'        => Schema::input(
				array(
					'url'       => array(
						'type'        => 'string',
						'format'      => 'uri',
						'maxLength'   => self::URL_MAX,
						'description' => __( 'The image\'s public http or https address. Only use an address the user gave or that the image is plainly published at.', 'zorem-ai-agent-connector' ),
					),
					'title'     => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => __( 'Title for the media library. Leave out to use the file name.', 'zorem-ai-agent-connector' ),
					),
					'alt'       => array(
						'type'        => 'string',
						'maxLength'   => 500,
						'description' => __( 'Alternative text describing the image for people who cannot see it.', 'zorem-ai-agent-connector' ),
					),
					'attach_to' => Schema::id( __( 'ID of the post or page to attach the image to.', 'zorem-ai-agent-connector' ) ),
				),
				array( 'url' )
			),
			'output_schema'       => GetMedia::item_schema(),
			'permission_callback' => static fn( $input = null ): bool => current_user_can( 'upload_files' ) && Access::can_edit_post_named( $input, 'attach_to' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_WRITE, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$url   = mb_substr( trim( (string) ( $input['url'] ?? '' ) ), 0, self::URL_MAX );

		if ( ! self::is_public_url( $url ) ) {
			return new \WP_Error( 'ai_agent_os_unsafe_url', __( 'Only public http or https addresses can be downloaded.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$attach_to = 0;

		if ( isset( $input['attach_to'] ) ) {
			$post = PostReader::find( absint( $input['attach_to'] ) );

			if ( $post instanceof \WP_Error ) {
				return $post;
			}

			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new \WP_Error( 'ai_agent_os_forbidden', __( 'The image cannot be attached to that item.', 'zorem-ai-agent-connector' ), array( 'status' => 403 ) );
			}

			$attach_to = (int) $post->ID;
		}

		self::load_media_functions();

		$cap = static function ( array $args ): array {
			$args['limit_response_size'] = self::MAX_BYTES;

			return $args;
		};

		add_filter( 'http_request_args', $cap );

		try {
			$tmp = download_url( $url, self::TIMEOUT );
		} finally {
			remove_filter( 'http_request_args', $cap );
		}

		if ( $tmp instanceof \WP_Error ) {
			return new \WP_Error( 'ai_agent_os_download_failed', __( 'The image could not be downloaded.', 'zorem-ai-agent-connector' ), array( 'status' => 502 ) );
		}

		try {
			return self::import( (string) $tmp, $url, $attach_to, $input );
		} finally {
			if ( is_string( $tmp ) && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Whether a URL is http(s) and passes WordPress's public-address check.
	 *
	 * @param string $url The URL.
	 */
	public static function is_public_url( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		return false !== wp_http_validate_url( $url );
	}

	/**
	 * Check a downloaded file and add it to the media library.
	 *
	 * @param string               $tmp    The temporary file.
	 * @param string               $url    Where it came from.
	 * @param int                  $attach_to Item to attach to, or 0.
	 * @param array<string, mixed> $input  Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function import( string $tmp, string $url, int $attach_to, array $input ) {
		$size = (int) filesize( $tmp );

		if ( $size < 1 || $size >= self::MAX_BYTES ) {
			return new \WP_Error( 'ai_agent_os_too_large', __( 'The image is empty or larger than 10 MB.', 'zorem-ai-agent-connector' ), array( 'status' => 413 ) );
		}

		$mime = (string) wp_get_image_mime( $tmp );
		$ext  = (string) array_search( $mime, self::TYPES, true );

		if ( '' === $ext ) {
			return new \WP_Error( 'ai_agent_os_not_an_image', __( 'The file is not a JPEG, PNG, GIF, WebP or AVIF image.', 'zorem-ai-agent-connector' ), array( 'status' => 415 ) );
		}

		$base = sanitize_file_name( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$name = ( '' === $base ? 'image' : $base ) . '.' . $ext;
		$post = array();

		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$post['post_title'] = sanitize_text_field( (string) $input['title'] );
		}

		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$attach_to,
			null,
			$post
		);

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		if ( isset( $input['alt'] ) ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt'] ) ) );
		}

		$attachment = get_post( (int) $id );

		return $attachment instanceof \WP_Post
			? GetMedia::project( $attachment )
			: new \WP_Error( 'ai_agent_os_save_failed', __( 'WordPress did not save the image.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
	}

	/**
	 * Load the admin media functions, which the front end does not.
	 */
	private static function load_media_functions(): void {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
