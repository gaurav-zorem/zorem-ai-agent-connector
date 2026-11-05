<?php
/**
 * Universal ability interception.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

use AiAgentOs\Audit\ActivityRecorder;
use AiAgentOs\Audit\CallAnnotations;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Auth\AgentContext;
use AiAgentOs\Privacy\PiiRedactor;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps every ability registered on the site.
 *
 * This is the enforcement point that does not depend on which door a request
 * came through. The MCP server we build is scoped per agent, so an ungranted
 * tool is invisible there — but the adapter's default server exposes
 * mcp-adapter/execute-ability, which runs any ability by name, and WooCommerce
 * stands up a server of its own. A per-server allow-list cannot cover doors it
 * does not own. Wrapping the abilities themselves does.
 *
 * Two rules govern the wrapper:
 *
 *   1. It wraps, never replaces. The original permission callback is still
 *      called, and called last. An agent can therefore only ever end up with
 *      less access than the ability's author intended, never more.
 *
 *   2. Without an agent in context it is transparent. Ordinary human traffic —
 *      an admin in wp-admin, a logged-in customer, WP-CLI — reaches the
 *      original callbacks unchanged. Installing this plugin does not alter
 *      behaviour for anyone who is not an agent.
 */
final class AbilityGate {

	/**
	 * The permission authority.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $resolver;

	/**
	 * Runtime capability elevation.
	 *
	 * @var CapabilityBridge
	 */
	private CapabilityBridge $capabilities;

	/**
	 * Audit trail.
	 *
	 * @var ActivityRecorder
	 */
	private ActivityRecorder $activity;

	/**
	 * Response redaction.
	 *
	 * @var PiiRedactor
	 */
	private PiiRedactor $redactor;

	/**
	 * Per-call context contributed by the ability itself.
	 *
	 * @var CallAnnotations
	 */
	private CallAnnotations $annotations;

	/**
	 * Capabilities lent to each ability's permission check, held until its
	 * execution records them on the same activity row.
	 *
	 * A call is checked and then run as two separate bridge windows; what the
	 * check borrowed would otherwise be missing from the call's row.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $permission_lent = array();

	/**
	 * Risk of each ability, for the log's look-up / change split.
	 *
	 * @var RiskClassifier|null
	 */
	private ?RiskClassifier $risk;

	/**
	 * Which abilities this gate wrapped.
	 *
	 * @var GovernedAbilities
	 */
	private GovernedAbilities $governed;

	/**
	 * Constructor.
	 *
	 * @param PermissionResolver $resolver     The permission authority.
	 * @param CapabilityBridge   $capabilities Runtime capability elevation.
	 * @param ActivityRecorder   $activity     Audit trail.
	 * @param PiiRedactor        $redactor     Response redaction.
	 * @param CallAnnotations    $annotations  Per-call context from the ability.
	 * @param RiskClassifier     $risk         Ability risk, for the log's look-up / change split.
	 * @param GovernedAbilities  $governed     Which abilities this gate wrapped.
	 */
	public function __construct(
		PermissionResolver $resolver,
		CapabilityBridge $capabilities,
		ActivityRecorder $activity,
		PiiRedactor $redactor,
		CallAnnotations $annotations,
		?RiskClassifier $risk = null,
		?GovernedAbilities $governed = null
	) {
		$this->resolver     = $resolver;
		$this->capabilities = $capabilities;
		$this->activity     = $activity;
		$this->redactor     = $redactor;
		$this->annotations  = $annotations;
		$this->risk         = $risk;
		$this->governed     = $governed ?? new GovernedAbilities();
	}

	/**
	 * Whether a call looks things up or changes something, for the activity log's filters.
	 *
	 * From the ability's risk: read is a look-up, write and destructive change
	 * something. '' when there is no classifier to ask.
	 *
	 * @param \WP_Ability|null $ability The ability.
	 */
	private function kind( ?\WP_Ability $ability ): string {
		if ( ! $ability instanceof \WP_Ability || ! $this->risk instanceof RiskClassifier ) {
			return '';
		}

		return RiskClassifier::RISK_READ === $this->risk->classify( $ability ) ? ActivityRecorder::KIND_READ : ActivityRecorder::KIND_CHANGE;
	}

	/**
	 * Hook the wrapper.
	 *
	 * Late priority so that any other plugin filtering registration arguments
	 * has already had its say, and our wrapper ends up outermost.
	 */
	public function register(): void {
		/*
		 * Something read the abilities registry before this point, so the
		 * abilities registered then were never wrapped. They are missing from
		 * the governed set and refused to agents; Diagnostics says why.
		 */
		if ( did_action( 'wp_abilities_api_init' ) ) {
			$this->governed->mark_late();
		}

		add_filter( 'wp_register_ability_args', array( $this, 'wrap' ), 999, 2 );
	}

	/**
	 * Wrap an ability's callbacks at registration time.
	 *
	 * @param array<string, mixed> $args Ability registration arguments.
	 * @param string               $name Ability name.
	 * @return array<string, mixed>
	 */
	public function wrap( array $args, string $name ): array {
		if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
			$args['permission_callback'] = $this->wrap_permission( $args['permission_callback'], $name );
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$args['execute_callback'] = $this->wrap_execute( $args['execute_callback'], $name );
		}

		$this->governed->record( $name, $args );

		return $args;
	}

	/**
	 * Build the permission wrapper.
	 *
	 * @param callable $original The ability's own permission callback.
	 * @param string   $name     Ability name.
	 */
	private function wrap_permission( callable $original, string $name ): callable {
		/*
		 * The agent is resolved inside the closure, not captured here.
		 * Registration happens once per request, long before we know who is
		 * calling; capturing state at registration time would pin the wrapper
		 * to whoever happened to be current when abilities were registered.
		 */
		return function ( $input = null ) use ( $original, $name ) {
			$agent = AgentContext::get();

			if ( ! $agent instanceof Agent ) {
				return $original( $input );
			}

			$ability = $this->ability( $name );

			// An ability that cannot be looked up cannot be judged: refused, never run ungated.
			if ( ! $ability instanceof \WP_Ability ) {
				$this->activity->blocked( $agent, $name, Decision::UNGOVERNED, $input, array( 'stage' => 'permission' ) );

				return false;
			}

			$decision = $this->resolver->resolve( $agent, $ability, $input );

			if ( ! $decision->is_allowed() ) {
				$this->activity->blocked(
					$agent,
					$name,
					(string) $decision->get_reason(),
					$input,
					array(
						'stage' => 'permission',
						'kind'  => $this->kind( $ability ),
					)
				);

				return false;
			}

			/*
			 * Governance said yes. Now let the ability's own check run, with
			 * the capabilities it expects present for exactly this call.
			 */
			$allowed = $this->capabilities->with_caps(
				$ability,
				static fn() => $original( $input )
			);

			if ( true !== $allowed ) {
				$report = $this->capabilities->last_report();

				/*
				 * Refused because the ability asked for a capability that is
				 * never lent automatically. Named as such, here and to the
				 * client, rather than as a WordPress refusal nobody can act on.
				 */
				if ( array() !== $report['withheld'] ) {
					$this->activity->blocked(
						$agent,
						$name,
						Decision::CAP_WITHHELD,
						$input,
						array(
							'stage'         => 'permission',
							'caps_withheld' => $report['withheld'],
							'kind'          => $this->kind( $ability ),
						)
					);

					return new \WP_Error(
						'ai_agent_os_cap_withheld',
						sprintf(
							/* translators: %s: comma-separated WordPress capability names. */
							__( 'This tool needs a WordPress capability that is never lent to the agent, because it would allow changing users, plugins, site settings, other people’s content or site code: %s. An administrator can do this in WordPress directly.', 'zorem-ai-agent-connector' ),
							implode( ', ', $report['withheld'] )
						),
						array( 'status' => 403 )
					);
				}

				$this->activity->blocked(
					$agent,
					$name,
					Decision::WP_DENIED,
					$input,
					array(
						'stage' => 'permission',
						'kind'  => $this->kind( $ability ),
					)
				);
			}

			if ( true === $allowed ) {
				$this->permission_lent[ $name ] = $this->capabilities->last_report()['lent'];
			}

			return $allowed;
		};
	}

	/**
	 * Build the execution wrapper.
	 *
	 * @param callable $original The ability's own execute callback.
	 * @param string   $name     Ability name.
	 */
	private function wrap_execute( callable $original, string $name ): callable {
		return function ( $input = null ) use ( $original, $name ) {
			$agent = AgentContext::get();

			if ( ! $agent instanceof Agent ) {
				return $original( $input );
			}

			$ability = $this->ability( $name );
			$started = microtime( true );

			if ( ! $ability instanceof \WP_Ability ) {
				$this->activity->blocked( $agent, $name, Decision::UNGOVERNED, $input, array( 'stage' => 'execute' ) );

				return new \WP_Error( 'ai_agent_os_ungoverned', Decision::labels()[ Decision::UNGOVERNED ], array( 'status' => 403 ) );
			}

			/*
			 * The permission check ran with this input; check it again here so
			 * a caller that skipped check_permissions() is held to the same
			 * input-sensitive rules (a status change to cancelled).
			 */
			$decision = $this->resolver->resolve( $agent, $ability, $input );

			if ( ! $decision->is_allowed() ) {
				$this->activity->blocked(
					$agent,
					$name,
					(string) $decision->get_reason(),
					$input,
					array(
						'stage' => 'execute',
						'kind'  => $this->kind( $ability ),
					)
				);

				return new \WP_Error( 'ai_agent_os_denied', $decision->get_message(), array( 'status' => 403 ) );
			}

			/*
			 * A masked agent may not find out who is a customer by searching for
			 * them. A tool that declares it uses contact details only as proof
			 * (an order looked up by its number and the customer's own email,
			 * both of which must match) keeps them; its reply is still masked.
			 */
			if ( $agent->masks_pii() && ! self::contact_is_proof( $ability ) ) {
				$input = $this->redactor->strip_customer_search( $input );
			}

			// Anything left by a call that threw belongs to that call, not this one.
			$this->annotations->drain();

			try {
				$result = $this->capabilities->with_caps(
					$ability,
					static fn() => $original( $input )
				);
			} catch ( \Throwable $thrown ) {
				/*
				 * The call still gets its row. The client gets a generic error:
				 * an exception message can carry paths, SQL or customer data.
				 */
				$result = new \WP_Error(
					'ai_agent_os_ability_failed',
					__( 'The tool failed while running. The details are in the site’s activity log.', 'zorem-ai-agent-connector' ),
					array(
						'status'    => 500,
						'exception' => get_class( $thrown ),
					)
				);
			}

			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
			$report   = $this->capabilities->last_report();

			$report['lent'] = array_values( array_unique( array_merge( $this->permission_lent[ $name ] ?? array(), $report['lent'] ) ) );
			unset( $this->permission_lent[ $name ] );

			if ( $agent->masks_pii() ) {
				$result = $this->redactor->redact( $name, $result );
			}

			$this->activity->executed(
				$agent,
				$name,
				is_wp_error( $result ) ? ActivityRecorder::STATUS_ERROR : ActivityRecorder::STATUS_SUCCESS,
				$input,
				$duration,
				array_merge(
					$this->result_context( $input, $result ),
					$this->capability_context( $report ),
					array(
						// The reply as the agent got it, masked if it masks; the recorder cleans and bounds it.
						'output' => $result,
						'kind'   => $this->kind( $ability ),
					)
				)
			);

			return $result;
		};
	}

	/**
	 * Whether a tool declares that contact details in its input are proof, not a search.
	 *
	 * Declared by the tool's plugin as `meta.zorem.contact_is_proof = true`.
	 *
	 * @param \WP_Ability $ability The tool.
	 */
	private static function contact_is_proof( \WP_Ability $ability ): bool {
		$meta = $ability->get_meta();

		return true === ( $meta['zorem']['contact_is_proof'] ?? null );
	}

	/**
	 * What a call borrowed, for its activity row.
	 *
	 * Only automatic calls carry it: a mapped call borrows exactly what its
	 * map says, which is the same every time and readable in the map.
	 *
	 * @param array{automatic: bool, lent: array<int, string>, withheld: array<int, string>} $report Bridge report.
	 * @return array<string, mixed>
	 */
	private function capability_context( array $report ): array {
		if ( ! $report['automatic'] ) {
			return array();
		}

		$context = array( 'caps_lent' => $report['lent'] );

		if ( array() !== $report['withheld'] ) {
			$context['caps_withheld'] = $report['withheld'];
		}

		return $context;
	}

	/**
	 * Look an ability up by name at call time.
	 *
	 * @param string $name Ability name.
	 */
	private function ability( string $name ): ?\WP_Ability {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = wp_get_ability( $name );

		return $ability instanceof \WP_Ability ? $ability : null;
	}

	/**
	 * Work out what object a call touched, for the activity log.
	 *
	 * Best-effort: it makes the log answer "which order?" rather than only
	 * "an order ability ran". Never fails the call.
	 *
	 * @param mixed $input  Call input.
	 * @param mixed $result Call result.
	 * @return array<string, mixed>
	 */
	private function result_context( $input, $result ): array {
		$annotations = $this->annotations->drain();

		$context = array();

		if ( is_wp_error( $result ) ) {
			$context['error_code'] = $result->get_error_code();
		}

		if ( ! is_array( $input ) ) {
			// Merged last: what the ability stated beats what we guessed.
			return array_merge( $context, $annotations );
		}

		$id_keys = array(
			'order_id'   => 'order',
			'product_id' => 'product',
			'post_id'    => 'post',
			'id'         => 'object',
		);

		foreach ( $id_keys as $key => $type ) {
			if ( ! empty( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$context['object_type'] = $type;
				$context['object_id']   = (int) $input[ $key ];
				break;
			}
		}

		// Merged last: what the ability stated beats what we guessed.
		return array_merge( $context, $annotations );
	}
}
