<?php
/**
 * The administrator's acknowledgement for a stored AI key.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Records, by version, what an administrator agreed to when storing a key in the database.
 *
 * One ledger for the site, kept by the kit, so both plugins honour the same
 * acknowledgement — there is no path where one plugin uses a key the merchant
 * agreed to only in the other (plan §14.5).
 *
 * - **Version 1** covered AI enrichment: plugin documentation sent to the
 *   provider to draft descriptions, which a human then reviews.
 * - **Version 2** added the assistants: questions typed by shoppers and staff
 *   sent to the same provider with the same key — a materially different
 *   thing to agree to. A version 1 acknowledgement does not cover it, and is
 *   not silently upgraded: the administrator re-confirms.
 *
 * The version is stamped by `record()` and cannot be set to anything else —
 * a caller cannot claim a newer agreement than the text that was shown.
 *
 * Keys provided outside the database (a wp-config constant, an environment
 * variable, WordPress's own Connectors screen) need no acknowledgement here:
 * the acknowledgement is about what storing a key in this plugin's database
 * does and does not protect against.
 */
final class ConsentLedger {

	public const OPTION = 'zorem_agent_brain_ai_consent';

	/** The version of the acknowledgement text currently shown. */
	public const CURRENT_VERSION = 2;

	/** Purposes and the version that first covered them. */
	private const COVERS = array(
		'enrichment' => 1,
	);

	/** Version required for any purpose not listed above (the assistants). */
	private const DEFAULT_VERSION = 2;

	/**
	 * Record the administrator's acknowledgement of the current text.
	 *
	 * @param int $user_id Who acknowledged.
	 */
	public function record( int $user_id ): bool {
		return update_option(
			self::OPTION,
			array(
				'version' => self::CURRENT_VERSION,
				'by'      => $user_id,
				'at'      => current_time( 'mysql', true ),
			),
			false
		);
	}

	/**
	 * Withdraw the acknowledgement. A stored key then serves nothing.
	 */
	public function revoke(): bool {
		return delete_option( self::OPTION );
	}

	/**
	 * The version on file, 0 for none.
	 */
	public function version(): int {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? max( 0, (int) ( $stored['version'] ?? 0 ) ) : 0;
	}

	/**
	 * Whether any acknowledgement is on file.
	 */
	public function recorded(): bool {
		return $this->version() > 0;
	}

	/**
	 * Whether the acknowledgement on file covers a purpose.
	 *
	 * @param string $purpose e.g. "enrichment", "storefront", "admin-chat", "support".
	 */
	public function covers( string $purpose ): bool {
		return $this->version() >= ( self::COVERS[ $purpose ] ?? self::DEFAULT_VERSION );
	}

	/**
	 * Who acknowledged and when, for the settings screen.
	 *
	 * @return array{version: int, by: int, at: string}
	 */
	public function describe(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'version' => max( 0, (int) ( $stored['version'] ?? 0 ) ),
			'by'      => (int) ( $stored['by'] ?? 0 ),
			'at'      => (string) ( $stored['at'] ?? '' ),
		);
	}
}
