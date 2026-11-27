<?php
/**
 * Turning an acting agent into a reader.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Scope;

use ZoremAgentBridge\V1\Dto\AgentRef;

defined( 'ABSPATH' ) || exit;

/**
 * Which host resolves which reader namespace.
 *
 * AI Agent OS resolves `agent:…`; AI Shopping Assistant resolves `exp:…`.
 * Each namespace is claimed once, by the same rule as the bridge's providers:
 * a second owner voids both claims, so the namespace resolves nothing and
 * every read from it is denied — never handed to whichever plugin loaded last.
 */
final class ReaderRegistry {

	/**
	 * Resolvers by namespace: [callable, owner].
	 *
	 * @var array<string, array{resolver: callable, owner: string}>
	 */
	private array $resolvers = array();

	/**
	 * Conflicted namespaces: namespace => owners.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $conflicts = array();

	/**
	 * Who lists each namespace's readers for the screens: namespace => callable.
	 *
	 * @var array<string, callable>
	 */
	private array $listers = array();

	/**
	 * Claim a namespace.
	 *
	 * @param string   $space    Namespace, e.g. "exp".
	 * @param callable $resolver Receives an AgentRef, returns a Reader or null.
	 * @param string   $owner    Slug of the claiming plugin.
	 * @return bool Whether the claim was accepted.
	 */
	public function register( string $space, callable $resolver, string $owner ): bool {
		if ( 1 !== preg_match( '/^[a-z]+$/', $space ) || '' === $owner ) {
			return false;
		}

		if ( isset( $this->conflicts[ $space ] ) ) {
			if ( ! in_array( $owner, $this->conflicts[ $space ], true ) ) {
				$this->conflicts[ $space ][] = $owner;
			}

			return false;
		}

		if ( isset( $this->resolvers[ $space ] ) && $owner !== $this->resolvers[ $space ]['owner'] ) {
			$this->conflicts[ $space ] = array( $this->resolvers[ $space ]['owner'], $owner );
			unset( $this->resolvers[ $space ] );

			return false;
		}

		/*
		 * The same owner name from different code is a conflict, and voids the
		 * namespace. Re-registering under the holder's name used to replace its
		 * resolver, so any plugin could install its own `agent` resolver and
		 * mint administrator readers. The same code registering again (a
		 * double boot) is accepted, as before.
		 */
		if ( isset( $this->resolvers[ $space ] ) && self::defined_in( $this->resolvers[ $space ]['resolver'] ) !== self::defined_in( $resolver ) ) {
			$this->conflicts[ $space ] = array( $owner, $owner . ' (another resolver)' );
			unset( $this->resolvers[ $space ] );

			return false;
		}

		$this->resolvers[ $space ] = array(
			'resolver' => $resolver,
			'owner'    => $owner,
		);

		return true;
	}

	/**
	 * The file a callable is defined in, to tell one registrant's code from another's.
	 *
	 * @param callable $resolver The resolver.
	 */
	private static function defined_in( callable $resolver ): string {
		try {
			if ( is_array( $resolver ) ) {
				return (string) ( new \ReflectionMethod( $resolver[0], (string) $resolver[1] ) )->getFileName();
			}

			if ( is_object( $resolver ) && ! $resolver instanceof \Closure ) {
				return (string) ( new \ReflectionMethod( $resolver, '__invoke' ) )->getFileName();
			}

			return (string) ( new \ReflectionFunction( \Closure::fromCallable( $resolver ) ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			unset( $e );

			return '';
		}
	}

	/**
	 * The reader for an acting agent, or null when no host resolves it.
	 *
	 * A resolver that returns something other than a Reader for a ref in
	 * another namespace is ignored: a host may only speak for its own readers.
	 *
	 * @param AgentRef $agent The acting agent.
	 */
	public function resolve( AgentRef $agent ): ?Reader {
		$space = $agent->namespace();

		if ( ! isset( $this->resolvers[ $space ] ) ) {
			return null;
		}

		$reader = ( $this->resolvers[ $space ]['resolver'] )( $agent );

		if ( ! $reader instanceof Reader || $reader->ref() !== $agent->ref() ) {
			return null;
		}

		return $reader;
	}

	/**
	 * Let a namespace's owner list its readers, so a screen can offer them by name.
	 *
	 * Only the plugin that holds the namespace, from the same code as its
	 * resolver, may list it: a list is what an administrator ticks to grant
	 * access, so a stranger's list could slip its own readers in.
	 *
	 * @param string   $space  Namespace already claimed with register().
	 * @param callable $lister Returns reader ref => label.
	 * @param string   $owner  Slug of the claiming plugin.
	 * @return bool Whether the list was accepted.
	 */
	public function lists( string $space, callable $lister, string $owner ): bool {
		if ( ! isset( $this->resolvers[ $space ] ) || $owner !== $this->resolvers[ $space ]['owner'] ) {
			return false;
		}

		if ( self::defined_in( $this->resolvers[ $space ]['resolver'] ) !== self::defined_in( $lister ) ) {
			return false;
		}

		$this->listers[ $space ] = $lister;

		return true;
	}

	/**
	 * Every listed reader, by label: ref => label.
	 *
	 * A ref outside the lister's own namespace, or a namespace that has since
	 * fallen into conflict, is left out.
	 *
	 * @return array<string, string>
	 */
	public function known(): array {
		$known = array();

		foreach ( $this->listers as $space => $lister ) {
			if ( ! isset( $this->resolvers[ $space ] ) ) {
				continue;
			}

			foreach ( (array) $lister() as $ref => $label ) {
				if ( 0 === strpos( (string) $ref, $space . ':' ) && is_string( $label ) ) {
					$known[ (string) $ref ] = $label;
				}
			}
		}

		asort( $known, SORT_NATURAL | SORT_FLAG_CASE );

		return $known;
	}

	/**
	 * Conflicted namespaces.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Namespace owners.
	 *
	 * @return array<string, string>
	 */
	public function owners(): array {
		return array_map( static fn ( array $claim ): string => $claim['owner'], $this->resolvers );
	}
}
