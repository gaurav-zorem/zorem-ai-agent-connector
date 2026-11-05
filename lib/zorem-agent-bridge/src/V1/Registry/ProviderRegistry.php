<?php
/**
 * Which component provides each contract.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * One provider per contract, claimed once, then sealed.
 *
 * Three rules, each there because the alternative lets code outside the
 * authority decide who the authority is:
 *
 * 1. **A second claim voids both.** If two components claim the same
 *    contract, neither gets it: the contract falls back to its null provider,
 *    which for the permission authority denies everything, and the conflict is
 *    recorded with both owners' names so an administrator can see who. "Last
 *    one wins" would let any plugin replace the authority by loading later.
 * 2. **Claims close when the registry seals**, right after the
 *    `zorem_agent_bridge_register` action. A late claim is refused and
 *    recorded rather than silently accepted mid-request.
 * 3. **A provider must implement the contract it claims.** Checked at claim
 *    time, so a wrong object fails loudly where it is registered, not later
 *    where it is used.
 *
 * A component re-claiming a contract it already holds, with the same owner
 * name, is accepted and replaces its own provider — so a plugin that boots
 * twice in one request cannot conflict with itself.
 */
final class ProviderRegistry {

	/**
	 * Accepted claims: contract => [provider, owner].
	 *
	 * @var array<string, array{provider: object, owner: string}>
	 */
	private array $claims = array();

	/**
	 * Conflicted contracts: contract => owners that claimed it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $conflicts = array();

	/**
	 * Claims refused because they arrived after sealing: "contract by owner".
	 *
	 * @var array<int, string>
	 */
	private array $late = array();

	/**
	 * Whether claims are closed.
	 *
	 * @var bool
	 */
	private bool $sealed = false;

	/**
	 * Claim a contract.
	 *
	 * @param string $contract Interface name.
	 * @param object $provider Implementation.
	 * @param string $owner    Slug of the claiming plugin or library.
	 * @return bool Whether the claim was accepted.
	 * @throws \InvalidArgumentException When the provider does not implement the contract, or the owner is empty.
	 */
	public function claim( string $contract, object $provider, string $owner ): bool {
		if ( '' === $owner ) {
			throw new \InvalidArgumentException( 'A claim needs an owner.' );
		}

		if ( ! interface_exists( $contract ) || ! $provider instanceof $contract ) {
			throw new \InvalidArgumentException( sprintf( 'The provider does not implement %s.', esc_html( $contract ) ) );
		}

		if ( $this->sealed ) {
			$this->late[] = $contract . ' by ' . $owner;

			return false;
		}

		if ( isset( $this->conflicts[ $contract ] ) ) {
			if ( ! in_array( $owner, $this->conflicts[ $contract ], true ) ) {
				$this->conflicts[ $contract ][] = $owner;
			}

			return false;
		}

		if ( isset( $this->claims[ $contract ] ) && $owner !== $this->claims[ $contract ]['owner'] ) {
			$this->conflicts[ $contract ] = array( $this->claims[ $contract ]['owner'], $owner );
			unset( $this->claims[ $contract ] );

			return false;
		}

		/*
		 * The same owner name with a different implementation is a conflict
		 * too. An owner is a name the caller supplies, so re-claiming under the
		 * holder's name used to replace its provider silently: last one wins,
		 * exactly what claim-once exists to prevent. Re-claiming with the same
		 * class (a double boot) is accepted, as before.
		 */
		if ( isset( $this->claims[ $contract ] ) && get_class( $this->claims[ $contract ]['provider'] ) !== get_class( $provider ) ) {
			$this->conflicts[ $contract ] = array( $owner, $owner . ' (another implementation)' );
			unset( $this->claims[ $contract ] );

			return false;
		}

		$this->claims[ $contract ] = array(
			'provider' => $provider,
			'owner'    => $owner,
		);

		return true;
	}

	/**
	 * Close claims.
	 */
	public function seal(): void {
		$this->sealed = true;
	}

	/**
	 * Whether claims are closed.
	 */
	public function is_sealed(): bool {
		return $this->sealed;
	}

	/**
	 * The provider for a contract, or null when unclaimed or conflicted.
	 *
	 * @param string $contract Interface name.
	 */
	public function get( string $contract ): ?object {
		return $this->claims[ $contract ]['provider'] ?? null;
	}

	/**
	 * The owner of a contract's provider, or null.
	 *
	 * @param string $contract Interface name.
	 */
	public function owner( string $contract ): ?string {
		return $this->claims[ $contract ]['owner'] ?? null;
	}

	/**
	 * Every accepted claim, contract => owner.
	 *
	 * @return array<string, string>
	 */
	public function owners(): array {
		return array_map( static fn ( array $claim ): string => $claim['owner'], $this->claims );
	}

	/**
	 * Conflicted contracts, contract => owners.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Claims refused for arriving after sealing.
	 *
	 * @return array<int, string>
	 */
	public function late_claims(): array {
		return $this->late;
	}
}
