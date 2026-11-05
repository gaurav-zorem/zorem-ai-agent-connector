<?php
/**
 * Removing secrets and personal data from text a stranger will read.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The last pass over any answer that leaves an Agent OS component.
 *
 * Ported from the legacy SecretRedactor and PiiRedactor::redact_text(),
 * unchanged in what they match. Stateless: a caller that knows specific secret
 * values (a stored API key) passes them in.
 *
 * Order matters and is fixed in redact(): secrets, then personal data, then
 * length — the length cap is last so a truncation can never cut a redaction in
 * half and leave the front of a key showing. The legacy no-key storefront path
 * skipped this pass entirely; one shared entry point makes that harder to
 * repeat.
 */
final class TextRedactor {

	/** What a secret becomes. */
	public const SECRET_MASK = '[redacted]';

	/** What an email address becomes. Valid-looking, so output schemas still pass. */
	public const EMAIL_MASK = 'masked@masked.invalid';

	/** Shortest known secret value replaced literally; shorter ones risk matching ordinary words. */
	private const MIN_KNOWN_SECRET = 12;

	/** Labels whose value is a secret, whatever it looks like. */
	private const LABELS = '(?:api[_\- ]?key|secret|client[_\- ]?secret|consumer[_\- ]?secret|access[_\- ]?token|refresh[_\- ]?token|token|password|passwd|pass|pwd|authorization)';

	/**
	 * Redact secrets, then emails, then bound the length.
	 *
	 * @param string             $text         Text.
	 * @param array<int, string> $public_email Addresses meant to be shown, e.g. the store's own.
	 * @param int                $max_chars    Longest result; 0 for no limit.
	 * @param array<int, string> $known        Secret values to replace literally.
	 */
	public static function redact( string $text, array $public_email = array(), int $max_chars = 0, array $known = array() ): string {
		$text = self::secrets( $text, $known );
		$text = self::emails( $text, $public_email );

		if ( $max_chars > 0 && mb_strlen( $text ) > $max_chars ) {
			$text = mb_substr( $text, 0, $max_chars - 1 ) . '…';
		}

		return $text;
	}

	/**
	 * Replace known secret values and secret-shaped strings.
	 *
	 * @param string             $text  Text.
	 * @param array<int, string> $known Secret values to replace literally.
	 */
	public static function secrets( string $text, array $known = array() ): string {
		if ( '' === $text ) {
			return $text;
		}

		foreach ( $known as $secret ) {
			if ( is_string( $secret ) && strlen( $secret ) >= self::MIN_KNOWN_SECRET ) {
				$text = str_replace( $secret, self::SECRET_MASK, $text );
			}
		}

		$patterns = array(
			// Well-known prefixes, unambiguous enough to match alone.
			'/\bsk-[A-Za-z0-9_\-]{16,}/',
			'/\bsk-ant-[A-Za-z0-9_\-]{16,}/',
			'/\bghp_[A-Za-z0-9]{20,}/',
			'/\bgithub_pat_[A-Za-z0-9_]{20,}/',
			'/\bAKIA[0-9A-Z]{16}\b/',
			'/\bAIza[0-9A-Za-z_\-]{30,}/',
			'/\bxox[baprs]-[A-Za-z0-9\-]{10,}/',
			// Stripe secret and restricted keys.
			'/\b[sr]k_(?:live|test)_[A-Za-z0-9]{8,}/',
			// WooCommerce REST API consumer keys and secrets.
			'/\b(?:ck|cs)_[a-f0-9]{30,}/',
			// JSON Web Tokens: three base64url parts, the first a JSON header.
			'/\beyJ[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]{5,}/',
			// Agent tokens and connect-flow credentials, which Agent OS prefixes by design.
			'/\bzag_[A-Za-z0-9_\-]{20,}/',
			'/\bzo[acr]_[A-Za-z0-9_\-]{20,}/',
		);

		foreach ( $patterns as $pattern ) {
			$result = preg_replace( $pattern, self::SECRET_MASK, $text );
			$text   = is_string( $result ) ? $result : $text;
		}

		$labelled = array(
			// "Authorization: Bearer …" and a bare "Bearer …" / "Basic …".
			'/\b((?:Bearer|Basic)\s+)[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1' . self::SECRET_MASK,
			// "password": "…" and 'secret' => '…': a quoted value after a quoted label, any length.
			'/(["\']' . self::LABELS . '["\']\s*(?:=>|[:=])\s*)(["\'])(?:(?!\2).){1,}\2/i' => '$1$2' . self::SECRET_MASK . '$2',
			// api_key: …, password = …: keep the label, mask the value. Short passwords count.
			'/(\b' . self::LABELS . '\s*[:=]\s*)["\']?[^\s"\',;&]{4,}["\']?/i' => '$1' . self::SECRET_MASK,
		);

		foreach ( $labelled as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			$text   = is_string( $result ) ? $result : $text;
		}

		return $text;
	}

	/**
	 * Mask email addresses, except those meant to be public.
	 *
	 * @param string             $text           Text.
	 * @param array<int, string> $keep_addresses Addresses to keep, matched case-insensitively.
	 */
	public static function emails( string $text, array $keep_addresses = array() ): string {
		$keep = array_values( array_filter( array_map( 'strtolower', array_map( 'strval', $keep_addresses ) ) ) );

		$result = preg_replace_callback(
			'/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/i',
			static function ( array $found ) use ( $keep ): string {
				return in_array( strtolower( $found[0] ), $keep, true ) ? $found[0] : self::EMAIL_MASK;
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}
}
