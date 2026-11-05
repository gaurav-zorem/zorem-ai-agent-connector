<?php
/**
 * Agent token generation and verification.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and verifies agent bearer tokens.
 *
 * Storage is an HMAC-SHA256 of the token keyed with wp_salt('auth'), not a
 * password hash. That is deliberate: the token is 32 bytes of CSPRNG output, so
 * it has no guessable structure for a work factor to defend, and a keyed hash
 * is deterministic — which lets us find the agent with one indexed lookup
 * instead of scanning every row and running password_verify on each.
 *
 * The consequence, documented in Requirements and in the readme, is that
 * rotating the site's auth salts invalidates every token at once.
 */
final class TokenService {

	/**
	 * Raw entropy in bytes. 32 bytes = 256 bits.
	 */
	private const ENTROPY_BYTES = 32;

	/**
	 * Human-facing prefix, so a leaked token is recognisable in a log.
	 */
	private const TOKEN_PREFIX = 'zag_';

	/**
	 * Generate a new token.
	 *
	 * @return array{token: string, hash: string, prefix: string} The plaintext
	 *         token is returned once and never stored.
	 * @throws \RuntimeException When the platform has no CSPRNG.
	 */
	public function generate(): array {
		try {
			$raw = random_bytes( self::ENTROPY_BYTES );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Unable to generate a secure agent token: no CSPRNG available.' );
		}

		/*
		 * base64 here encodes 32 bytes of CSPRNG output into a URL- and
		 * header-safe string. It is a transport encoding for random bytes, not
		 * an attempt to hide anything, which is what the sniff guards against.
		 */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = base64_encode( $raw );

		$token = self::TOKEN_PREFIX . rtrim( strtr( $encoded, '+/', '-_' ), '=' );

		// The salt this token's hash is keyed with, so Diagnostics can tell when it changes.
		( new \AiAgentOs\Core\Requirements() )->record_salt_fingerprint();

		return array(
			'token'  => $token,
			'hash'   => $this->hash( $token ),
			'prefix' => $this->display_prefix( $token ),
		);
	}

	/**
	 * Keyed hash of a token, for storage and lookup.
	 *
	 * @param string $token Plaintext token.
	 */
	public function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time comparison of a candidate token against a stored hash.
	 *
	 * The indexed lookup already narrows to one row; this is the guard against
	 * a caller that found the row some other way.
	 *
	 * @param string $token       Plaintext token from the request.
	 * @param string $stored_hash Hash from the database.
	 */
	public function verify( string $token, string $stored_hash ): bool {
		if ( '' === $token || '' === $stored_hash ) {
			return false;
		}

		return hash_equals( $stored_hash, $this->hash( $token ) );
	}

	/**
	 * The portion of a token shown in the admin so an agent's credential is
	 * identifiable without being reusable.
	 *
	 * @param string $token Plaintext token.
	 */
	public function display_prefix( string $token ): string {
		return substr( $token, 0, 12 );
	}

	/**
	 * Whether a string is shaped like one of our tokens.
	 *
	 * A cheap gate before touching the database on every request that happens
	 * to carry some other Authorization header.
	 *
	 * @param string $token Candidate.
	 */
	public function looks_like_token( string $token ): bool {
		return 0 === strpos( $token, self::TOKEN_PREFIX ) && strlen( $token ) > 20;
	}
}
