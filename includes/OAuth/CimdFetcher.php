<?php
/**
 * Client ID Metadata Document retrieval.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the JSON document a URL-shaped `client_id` points at.
 *
 * This is the one place the plugin makes an outbound request because an
 * unauthenticated stranger asked it to (plan §1.4, S16), so the defence does
 * not lean on `wp_safe_remote_get()` alone. Its private-range check runs
 * through the `http_request_host_is_external` filter, which any other plugin
 * on the site can loosen. Here:
 *
 *   - https only, default port only, no credentials, no fragment;
 *   - IP literals are refused outright, IPv6 included — a client's identity is
 *     a domain name, and the name is what the consent screen shows;
 *   - every address the name resolves to must be public, and the connection
 *     is pinned to the address that was checked (CURLOPT_RESOLVE), so a
 *     second, different DNS answer at connect time cannot be used (rebinding);
 *   - no redirects, a size cap enforced while streaming, a wall-clock timeout;
 *   - the response must be JSON and must name itself.
 *
 * If the transport is not cURL the pin cannot be applied, and the fetch is
 * refused rather than made without it.
 */
final class CimdFetcher {

	/**
	 * Largest document accepted, in bytes.
	 */
	public const MAX_BYTES = 16384;

	/**
	 * Total time allowed, in seconds.
	 */
	public const TIMEOUT = 5;

	/**
	 * IPv4 blocks that are never a public web server (RFC 6890 and friends).
	 *
	 * @var array<int, string>
	 */
	private const NON_PUBLIC_V4 = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	);

	/**
	 * How long a good document is reused, in seconds.
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * The host and address the in-flight request is pinned to.
	 *
	 * @var array{host: string, ip: string}|null
	 */
	private ?array $pin = null;

	/**
	 * Whether the pin was applied to the in-flight request.
	 *
	 * @var bool
	 */
	private bool $pinned = false;

	/**
	 * Whether a client_id is shaped like a metadata document URL.
	 *
	 * @param string $client_id Client id.
	 */
	public function is_document_url( string $client_id ): bool {
		return 0 === strpos( $client_id, 'https://' );
	}

	/**
	 * Fetch and validate a document.
	 *
	 * @param string $url The client_id.
	 * @return array<string, mixed>|\WP_Error The decoded document.
	 */
	public function fetch( string $url ) {
		$cache_key = 'ai_agent_os_cimd_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$target = $this->check_url( $url );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$document = $this->request( $url, $target['host'], $target['ip'] );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		set_transient( $cache_key, $document, self::CACHE_TTL );

		return $document;
	}

	/**
	 * Validate a document URL and resolve it to one checked public address.
	 *
	 * Public so the refusal cases can be tested without a network.
	 *
	 * @param string $url The client_id.
	 * @return array{host: string, ip: string}|\WP_Error
	 */
	public function check_url( string $url ) {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return $this->refuse( __( 'The client metadata address must use https.', 'zorem-ai-agent-connector' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return $this->refuse( __( 'The client metadata address may not carry credentials or a fragment.', 'zorem-ai-agent-connector' ) );
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return $this->refuse( __( 'The client metadata address must use the default https port.', 'zorem-ai-agent-connector' ) );
		}

		if ( '' === trim( (string) ( $parts['path'] ?? '' ), '/' ) ) {
			return $this->refuse( __( 'The client metadata address must have a path.', 'zorem-ai-agent-connector' ) );
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( '' === $host || false !== strpos( $host, '[' ) || false !== strpos( $host, ':' ) || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $this->refuse( __( 'The client metadata address must be a domain name, not an IP address.', 'zorem-ai-agent-connector' ) );
		}

		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || false === strpos( $host, '.' ) ) {
			return $this->refuse( __( 'The client metadata address must be a public domain name.', 'zorem-ai-agent-connector' ) );
		}

		$addresses = $this->resolve( $host );

		if ( array() === $addresses ) {
			return $this->refuse( __( 'The client metadata address does not resolve.', 'zorem-ai-agent-connector' ) );
		}

		foreach ( $addresses as $ip ) {
			if ( ! $this->is_public_ip( $ip ) ) {
				return $this->refuse( __( 'The client metadata address resolves to a private or reserved network.', 'zorem-ai-agent-connector' ) );
			}
		}

		return array(
			'host' => $host,
			'ip'   => $addresses[0],
		);
	}

	/**
	 * Whether an address is publicly routable.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return false;
		}

		if ( 16 === strlen( $packed ) ) {
			// An IPv4 address inside IPv6 (mapped, or NAT64's well-known prefix) is judged as the IPv4 address.
			$prefix = substr( $packed, 0, 12 );

			if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix || "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) === $prefix ) {
				return $this->is_public_ip( (string) inet_ntop( substr( $packed, 12 ) ) );
			}

			return true;
		}

		/*
		 * PHP's reserved-range flag has changed between versions and misses
		 * some blocks either way, so the IPv4 ones that are never a public
		 * web server are listed here too.
		 */
		$long = (int) ip2long( $ip );

		foreach ( self::NON_PUBLIC_V4 as $block ) {
			list( $network, $bits ) = explode( '/', $block );

			$mask = ( -1 << ( 32 - (int) $bits ) ) & 0xFFFFFFFF;

			if ( ( $long & $mask ) === ( (int) ip2long( $network ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a decoded document against the URL it came from (S15).
	 *
	 * @param mixed  $document Decoded JSON.
	 * @param string $url      The client_id it was fetched from.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate_document( $document, string $url ) {
		if ( ! is_array( $document ) ) {
			return $this->refuse( __( 'The client metadata document is not a JSON object.', 'zorem-ai-agent-connector' ) );
		}

		if ( ! isset( $document['client_id'] ) || $url !== $document['client_id'] ) {
			return $this->refuse( __( 'The client metadata document does not name the address it was fetched from.', 'zorem-ai-agent-connector' ) );
		}

		if ( empty( $document['redirect_uris'] ) || ! is_array( $document['redirect_uris'] ) ) {
			return $this->refuse( __( 'The client metadata document lists no redirect addresses.', 'zorem-ai-agent-connector' ) );
		}

		/*
		 * A public client: one that can sign in without a secret, protected by
		 * PKCE. Said either as its method, or among the methods it supports —
		 * ChatGPT's document prefers `private_key_jwt` but lists `none` too, and
		 * this server offers no `private_key_jwt`, so `none` is what it uses
		 * here. A document that cannot do `none` is still refused.
		 */
		$method    = (string) ( $document['token_endpoint_auth_method'] ?? 'none' );
		$supported = isset( $document['token_endpoint_auth_methods_supported'] ) && is_array( $document['token_endpoint_auth_methods_supported'] )
			? array_map( 'strval', $document['token_endpoint_auth_methods_supported'] )
			: array();

		if ( 'none' !== $method && ! in_array( 'none', $supported, true ) ) {
			return $this->refuse( __( 'A client identified by a metadata document must be a public client.', 'zorem-ai-agent-connector' ) );
		}

		return $document;
	}

	/**
	 * Pin the in-flight request to the checked address.
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 */
	public function pin_connection( $handle ): void {
		if ( null === $this->pin ) {
			return;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pinning the resolved address needs the raw handle.
		curl_setopt( $handle, CURLOPT_RESOLVE, array( $this->pin['host'] . ':443:' . $this->pin['ip'] ) );
		curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt

		$this->pinned = true;
	}

	/**
	 * Make the pinned request and decode it.
	 *
	 * @param string $url  The client_id.
	 * @param string $host Checked host.
	 * @param string $ip   Checked address.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $url, string $host, string $ip ) {
		/*
		 * Through an outbound proxy the connection goes to the proxy, which
		 * resolves the host again itself: the checked address would not be
		 * the one reached. Refused rather than trusted.
		 */
		if ( ( new \WP_HTTP_Proxy() )->is_enabled() && ( new \WP_HTTP_Proxy() )->send_through_proxy( $url ) ) {
			return $this->refuse( __( 'Client metadata documents cannot be fetched through this server’s outbound proxy. Register the client instead, or ask your host to exempt the client’s address from the proxy.', 'zorem-ai-agent-connector' ) );
		}

		$this->pin    = array(
			'host' => $host,
			'ip'   => $ip,
		);
		$this->pinned = false;

		add_action( 'http_api_curl', array( $this, 'pin_connection' ), 999 );

		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
					'reject_unsafe_urls'  => true,
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
		} finally {
			remove_action( 'http_api_curl', array( $this, 'pin_connection' ), 999 );
			$this->pin = null;
		}

		if ( ! $this->pinned ) {
			return $this->refuse( __( 'The client metadata document could not be fetched safely on this server.', 'zorem-ai-agent-connector' ) );
		}

		if ( is_wp_error( $response ) ) {
			return $this->refuse( __( 'The client metadata document could not be fetched.', 'zorem-ai-agent-connector' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->refuse( __( 'The client metadata address did not answer with a document.', 'zorem-ai-agent-connector' ) );
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( 0 !== strpos( trim( $type ), 'application/json' ) ) {
			return $this->refuse( __( 'The client metadata document is not JSON.', 'zorem-ai-agent-connector' ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) >= self::MAX_BYTES ) {
			return $this->refuse( __( 'The client metadata document is too large.', 'zorem-ai-agent-connector' ) );
		}

		return $this->validate_document( json_decode( $body, true ), $url );
	}

	/**
	 * IPv4 addresses a host resolves to.
	 *
	 * IPv4 only: the pin is an IPv4 address, and an AAAA answer is not one that
	 * can be checked and pinned here.
	 *
	 * @param string $host Host name.
	 * @return array<int, string>
	 */
	private function resolve( string $host ): array {
		$addresses = gethostbynamel( $host );

		return is_array( $addresses ) ? array_values( $addresses ) : array();
	}

	/**
	 * The refusal every failure here maps to.
	 *
	 * @param string $why Detail for the consent screen.
	 */
	private function refuse( string $why ): \WP_Error {
		return new \WP_Error( 'ai_agent_os_oauth_invalid_client', $why );
	}
}
