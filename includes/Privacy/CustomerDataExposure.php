<?php
/**
 * Whether an agent can actually see customer personal data.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Privacy;

use AiAgentOs\Governance\PermissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The effective answer, not the switch position.
 *
 * ## Why this class exists
 *
 * The agents list used to render this column as `agent.pii_masked ? 'Masked' :
 * 'Visible'` — the raw state of one toggle. A storefront plugin, which holds
 * no ability that can return a customer record at all, therefore read
 * **"Visible"**. An administrator scanning that column for the row to worry
 * about would have found the one agent that cannot see a customer if it tried.
 *
 * The audit called that its worst misreading, and it is worth being precise
 * about why: the old column was not *wrong* about the toggle. It was answering
 * a question nobody asked. "Is masking off?" and "can this agent see customer
 * data?" have different answers whenever the agent holds no covered ability,
 * which is the common case.
 *
 * So the answer is computed from two facts together — what is granted, and
 * whether masking applies — and the "no access" case wins over the toggle
 * because a mask on nothing is still nothing.
 *
 * ## What it deliberately does not do
 *
 * It does not consider the agent's destructive switch, its status, or its
 * expiry. A revoked agent's grants are still its grants; showing "No access"
 * for a revoked agent would hide what it gets back on reactivation. Status has
 * its own column.
 */
final class CustomerDataExposure {

	public const NO_ACCESS = 'none';
	public const MASKED    = 'masked';
	public const VISIBLE   = 'visible';

	/**
	 * Which abilities can return customer data.
	 *
	 * @var CoveredAbilities
	 */
	private CoveredAbilities $covered;

	/**
	 * Grants, by agent.
	 *
	 * @var PermissionRepository
	 */
	private PermissionRepository $permissions;

	/**
	 * Constructor.
	 *
	 * @param CoveredAbilities     $covered     Which abilities can return customer data.
	 * @param PermissionRepository $permissions Grants, by agent.
	 */
	public function __construct( CoveredAbilities $covered, PermissionRepository $permissions ) {
		$this->covered     = $covered;
		$this->permissions = $permissions;
	}

	/**
	 * The covered abilities this agent actually holds a grant on.
	 *
	 * A grant level of `none` is not a grant. The repository stores those rows
	 * — an explicit "none" is how a revocation is recorded — so filtering them
	 * out here is not defensive, it is the difference between right and wrong.
	 *
	 * @param int $agent_id Agent id.
	 * @return array<int, string> Ability names, sorted.
	 */
	public function granted_covered_abilities( int $agent_id ): array {
		$names = array();

		foreach ( $this->permissions->get_grants( $agent_id ) as $ability_name => $level ) {
			if ( PermissionRepository::LEVEL_NONE === $level ) {
				continue;
			}

			if ( $this->covered->is_covered( $ability_name ) ) {
				$names[] = $ability_name;
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * One of NO_ACCESS, MASKED or VISIBLE.
	 *
	 * @param int  $agent_id   Agent id.
	 * @param bool $pii_masked Whether the agent masks personal data.
	 */
	public function status( int $agent_id, bool $pii_masked ): string {
		if ( array() === $this->granted_covered_abilities( $agent_id ) ) {
			return self::NO_ACCESS;
		}

		return $pii_masked ? self::MASKED : self::VISIBLE;
	}

	/**
	 * The status plus everything a screen needs to explain it.
	 *
	 * Returned as one array rather than four calls so that a template cannot
	 * render a label from one call and a tooltip from another that disagreed
	 * with it — which is a real hazard when the underlying grants can change
	 * between two reads on a busy site.
	 *
	 * @param int  $agent_id   Agent id.
	 * @param bool $pii_masked Whether the agent masks personal data.
	 * @return array{status: string, label: string, tone: string, detail: string, abilities: array<int, string>}
	 */
	public function describe( int $agent_id, bool $pii_masked ): array {
		$abilities = $this->granted_covered_abilities( $agent_id );
		$status    = array() === $abilities ? self::NO_ACCESS : ( $pii_masked ? self::MASKED : self::VISIBLE );

		switch ( $status ) {
			case self::NO_ACCESS:
				$label  = __( 'No access', 'zorem-ai-agent-connector' );
				$tone   = 'success';
				$detail = __( 'No ability granted to this agent can return a customer record, so there is nothing for masking to apply to.', 'zorem-ai-agent-connector' );
				break;

			case self::MASKED:
				$label = __( 'Masked', 'zorem-ai-agent-connector' );
				$tone  = 'neutral';
				/* translators: %d: number of granted abilities that can return customer data. */
				$detail = sprintf(
					/* translators: %d: number of granted abilities. */
					_n(
						'%d granted ability can return customer data, and names, emails, phone numbers and addresses are replaced with [masked] in its responses.',
						'%d granted abilities can return customer data, and names, emails, phone numbers and addresses are replaced with [masked] in their responses.',
						count( $abilities ),
						'zorem-ai-agent-connector'
					),
					count( $abilities )
				);
				break;

			default:
				$label  = __( 'Visible', 'zorem-ai-agent-connector' );
				$tone   = 'danger';
				$detail = sprintf(
					/* translators: %d: number of granted abilities. */
					_n(
						'%d granted ability returns real customer names, emails, phone numbers and addresses to this agent.',
						'%d granted abilities return real customer names, emails, phone numbers and addresses to this agent.',
						count( $abilities ),
						'zorem-ai-agent-connector'
					),
					count( $abilities )
				);
				break;
		}

		return array(
			'status'    => $status,
			'label'     => $label,
			'tone'      => $tone,
			'detail'    => $detail,
			'abilities' => $abilities,
		);
	}
}
