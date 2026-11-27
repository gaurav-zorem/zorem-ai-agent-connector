<?php
/**
 * Encryption for secrets the Brain stores.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Seals a secret (a GitHub token now, the AI key at S2e) for storage in the database.
 *
 * Authenticated encryption — libsodium's secretbox, which WordPress always
 * provides through its bundled sodium_compat — so a stored value cannot be
 * altered without detection. The legacy plugin used AES-CBC without a MAC,
 * which is malleable; this replaces it rather than porting it.
 *
 * The key is derived from the site's `AUTH_KEY` salt. That protects a
 * database dump, a backup or a staging copy, which is the realistic threat.
 * It does not protect against someone who can read wp-config.php, and the
 * screens that take a secret say so.
 *
 * A changed salt makes old values unreadable. `open()` then returns null —
 * never garbage — and `fingerprint()` lets a screen say why.
 */
final class SecretBox {

	/** Prefix marking the format, so a future change can be told apart. */
	private const VERSION = 'v1:';

	/**
	 * Seal a secret. Null when sealing is not possible.
	 *
	 * @param string $secret     The secret.
	 * @param string $context    What it is for, e.g. "github-token"; binds the key to one use.
	 */
	public static function seal( string $secret, string $context ): ?string {
		if ( '' === $secret ) {
			return null;
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $secret, $nonce, self::key( $context ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding for binary ciphertext, not obfuscation.
		return self::VERSION . base64_encode( $nonce . $cipher );
	}

	/**
	 * Open a sealed secret. Null when it cannot be opened: wrong context,
	 * altered value, unknown format, or a changed salt.
	 *
	 * @param string $sealed  Value from seal().
	 * @param string $context The context it was sealed with.
	 */
	public static function open( string $sealed, string $context ): ?string {
		if ( 0 !== strpos( $sealed, self::VERSION ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See seal().
		$raw = base64_decode( substr( $sealed, strlen( self::VERSION ) ), true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key( $context )
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * A short, non-reversible fingerprint of the current key, stored beside a
	 * sealed value so a screen can tell "salt changed" from "never set".
	 *
	 * @param string $context The context.
	 */
	public static function fingerprint( string $context ): string {
		return substr( hash_hmac( 'sha256', 'fingerprint', self::key( $context ) ), 0, 12 );
	}

	/**
	 * The 32-byte key for a context.
	 *
	 * @param string $context The context.
	 */
	private static function key( string $context ): string {
		return hash_hmac( 'sha256', 'zorem-agent-brain|secret|' . $context, wp_salt( 'auth' ), true );
	}
}
