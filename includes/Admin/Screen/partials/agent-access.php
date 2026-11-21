<?php
/**
 * Access & safety: expiry, who the agent acts for, customer-data masking and
 * destructive abilities. One form, one Save.
 *
 * @package AiAgentOs
 *
 * @var \AiAgentOs\Agents\Agent $ai_agent_os_agent         The agent.
 * @var array<string, mixed>   $ai_agent_os_exposure_data Effective customer-data exposure.
 */

defined( 'ABSPATH' ) || exit;

$ai_agent_os_expires = (string) ( $ai_agent_os_agent->get_expires_at() ?? '' );

if ( '' !== $ai_agent_os_expires ) {
	$ai_agent_os_expires = substr( $ai_agent_os_expires, 0, 10 );
}
?>
<?php // The form starts above the header, so the header's Save button is inside it. ?>
<form class="aaos-settings-form" data-aaos-agent-form
	data-aaos-endpoint="/agent"
	data-aaos-method="put">


<?php
ai_agent_os_section_header(
	__( 'Access & safety', 'zorem-ai-agent-connector' ),
	__( 'When it stops, who uses it, and what it may never do.', 'zorem-ai-agent-connector' ),
	true
);
?>

	<div class="zui-card aaos-access">
		<?php
		ai_agent_os_render_fields(
			array(
				'expires_at'          => array(
					'type'    => 'date',
					'title'   => __( 'Expires', 'zorem-ai-agent-connector' ),
					'desc'    => __( 'Optional. The agent stops working after this date.', 'zorem-ai-agent-connector' ),
					'tooltip' => __( 'An expired agent keeps its permissions and history. Clear the date to switch it back on.', 'zorem-ai-agent-connector' ),
					'value'   => $ai_agent_os_expires,
				),
				'acts_for'            => array(
					'type'    => 'select',
					'title'   => __( 'Acts for', 'zorem-ai-agent-connector' ),
					'desc'    => __( 'Who will use this agent.', 'zorem-ai-agent-connector' ),
					'tooltip' => __( 'Partner and public agents get masked customer data by default. Choose the store administrator only for you and your team.', 'zorem-ai-agent-connector' ),
					'options' => array(
						\AiAgentOs\Agents\Agent::ACTS_FOR_ADMIN   => __( 'You and your team (store administrator)', 'zorem-ai-agent-connector' ),
						\AiAgentOs\Agents\Agent::ACTS_FOR_PARTNER => __( 'A partner — supplier or agency', 'zorem-ai-agent-connector' ),
						\AiAgentOs\Agents\Agent::ACTS_FOR_PUBLIC  => __( 'The public — shoppers and visitors', 'zorem-ai-agent-connector' ),
					),
					'value'   => $ai_agent_os_agent->get_acts_for(),
				),
				'pii_masked'          => array(
					'type'    => 'toggle',
					'title'   => __( 'Mask customer personal data', 'zorem-ai-agent-connector' ),
					'desc'    => __( 'Hide customers’ names, emails, phone numbers and addresses.', 'zorem-ai-agent-connector' ),
					'tooltip' => __( 'Customer names, emails, phone numbers and addresses come back as [masked]. On by default for partner and public agents.', 'zorem-ai-agent-connector' ),
					'help'    => (string) $ai_agent_os_exposure_data['detail'],
					'value'   => $ai_agent_os_agent->masks_pii(),
				),
				'destructive_enabled' => array(
					'type'    => 'toggle',
					'title'   => __( 'Allow destructive abilities', 'zorem-ai-agent-connector' ),
					'desc'    => __( 'Let it delete, cancel or refund things.', 'zorem-ai-agent-connector' ),
					'tooltip' => __( 'These actions cannot easily be undone. With this off, the agent cannot run them even if they are granted on Permissions.', 'zorem-ai-agent-connector' ),
					'value'   => $ai_agent_os_agent->allows_destructive(),
				),
			)
		);
		?>
	</div>
</form>
