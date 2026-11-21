<?php
/**
 * The agent: permissions and connection.
 *
 * The React version used a `TabPanel` inside a tab. Here they are `.zui-section`
 * panels behind a sidebar, which is the library's own idiom for "sections within
 * a screen" and avoids a second row of tabs under the first.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/locked.php';

/**
 * Agent repository.
 *
 * @var \AiAgentOs\Agents\AgentRepository $ai_agent_os_agents
 */
$ai_agent_os_agents = ai_agent_os_service( \AiAgentOs\Agents\AgentRepository::class );

$ai_agent_os_agent = $ai_agent_os_agents->get();

if ( null === $ai_agent_os_agent ) {
	return;
}

/**
 * Customer data exposure.
 *
 * @var \AiAgentOs\Privacy\CustomerDataExposure $ai_agent_os_exposure
 */
$ai_agent_os_exposure = ai_agent_os_service( \AiAgentOs\Privacy\CustomerDataExposure::class );
/**
 * Permission repository.
 *
 * @var \AiAgentOs\Governance\PermissionRepository $ai_agent_os_perms
 */
$ai_agent_os_perms = ai_agent_os_service( \AiAgentOs\Governance\PermissionRepository::class );
/**
 * Ability inventory.
 *
 * @var \AiAgentOs\Governance\AbilityInventory $ai_agent_os_inventory
 */
$ai_agent_os_inventory = ai_agent_os_service( \AiAgentOs\Governance\AbilityInventory::class );

$ai_agent_os_exposure_data = $ai_agent_os_exposure->describe(
	$ai_agent_os_agent->get_id(),
	$ai_agent_os_agent->masks_pii()
);

$ai_agent_os_sections = array(
	'permissions' => array(
		'label' => __( 'Permissions', 'zorem-ai-agent-connector' ),
		'icon'  => 'shield-check',
	),
	'connect'     => array(
		'label' => __( 'Connect', 'zorem-ai-agent-connector' ),
		'icon'  => 'globe',
	),
	'access'      => array(
		'label' => __( 'Access & safety', 'zorem-ai-agent-connector' ),
		'icon'  => 'sliders',
	),
	'manage'      => array(
		'label' => __( 'Manage agent', 'zorem-ai-agent-connector' ),
		'icon'  => 'alert-circle',
	),
);
?>
<?php
/*
 * The header card, rebuilt to the design bundle at Z8.
 *
 * It was a thin crumb line: a back link, the name, and one badge. The design
 * gives the screen a proper head — the name at page-title weight with its
 * status beside it, and a meta line naming the three things somebody arriving
 * here wants confirmed before they touch anything: what kind of agent it is,
 * how much it is holding, and which token is live.
 *
 * The token prefix earns its place. It is the only way to tell, from the admin
 * side, whether the credential a client is using is the current one — and it is
 * safe to show because only the first characters are stored in readable form.
 */
$ai_agent_os_state_label = $ai_agent_os_agent->get_status();

if ( $ai_agent_os_agent->is_expired() && 'active' === $ai_agent_os_state_label ) {
	$ai_agent_os_state_label = 'expired';
}

$ai_agent_os_states = array(
	'active'    => __( 'Active', 'zorem-ai-agent-connector' ),
	'revoked'   => __( 'Revoked', 'zorem-ai-agent-connector' ),
	'suspended' => __( 'Suspended', 'zorem-ai-agent-connector' ),
	'expired'   => __( 'Expired', 'zorem-ai-agent-connector' ),
);

$ai_agent_os_grant_count = (int) ( $ai_agent_os_perms->count_grants( $ai_agent_os_agent->get_id() )['total'] ?? 0 );
?>
<div class="zui-card aaos-agenthead">

	<a class="aaos-agenthead__back" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ai-agent-os', 'tab' => 'agent' ), admin_url( 'admin.php' ) ) ); ?>">
		&larr; <?php esc_html_e( 'All agents', 'zorem-ai-agent-connector' ); ?>
	</a>

	<div class="aaos-agenthead__title">
		<h2 class="aaos-agenthead__name"><?php echo esc_html( $ai_agent_os_agent->get_name() ); ?></h2>

		<span class="aaos-pill aaos-pill--<?php echo esc_attr( $ai_agent_os_state_label ); ?>">
			<span class="aaos-pill__dot" aria-hidden="true"></span>
			<?php echo esc_html( $ai_agent_os_states[ $ai_agent_os_state_label ] ?? $ai_agent_os_state_label ); ?>
		</span>

		<?php
		/*
		 * The customer-data verdict stays in the header.
		 *
		 * It is not in the design, and it is kept anyway: it is H15's restored
		 * column, and the detail screen is exactly where somebody is about to
		 * change the grants that decide it. Losing it here to match a mock
		 * would be the port dropping an affordance for tidiness, which §4
		 * exists to prevent.
		 */
		?>
		<span class="aaos-tag aaos-tag--<?php echo esc_attr( $ai_agent_os_exposure_data['status'] ); ?>"
			title="<?php echo esc_attr( $ai_agent_os_exposure_data['detail'] ); ?>">
			<?php
			printf(
				/* translators: %s: No access, Masked or Visible. */
				esc_html__( 'Customer data: %s', 'zorem-ai-agent-connector' ),
				esc_html( $ai_agent_os_exposure_data['label'] )
			);
			?>
		</span>
	</div>

	<p class="aaos-agenthead__meta">
		<?php
		printf(
			/* translators: %s: who the agent acts for, e.g. "Store administrator". */
			esc_html__( 'Acts for: %s', 'zorem-ai-agent-connector' ),
			esc_html( \AiAgentOs\Agents\Agent::acts_for_label( $ai_agent_os_agent->get_acts_for() ) )
		);
		?>
		<span class="aaos-agenthead__sep">·</span>
		<?php
		/*
		 * Marked so a save can update it. It used to be plain text, so granting
		 * an ability and saving left the header saying "0 tools granted" until
		 * a full reload — which reads as a save that did not take.
		 */
		?>
		<span data-aaos-grant-count>
			<?php
			printf(
				esc_html(
					/* translators: %s: number of granted abilities. */
					_n( '%s tool granted', '%s tools granted', $ai_agent_os_grant_count, 'zorem-ai-agent-connector' )
				),
				esc_html( number_format_i18n( $ai_agent_os_grant_count ) )
			);
			?>
		</span>

		<?php
		// Every app signed in as the agent now. ("no token issued" read like a problem for an agent connected by signing in.)
		require_once __DIR__ . '/app-label.php';

		$ai_agent_os_head_apps = array();

		foreach ( (array) ( ai_agent_os_service( \AiAgentOs\OAuth\OAuthStore::class )->signed_in_apps()[ $ai_agent_os_agent->get_id() ] ?? array() ) as $ai_agent_os_head_app ) {
			$ai_agent_os_head_apps[] = ai_agent_os_app_label( (string) $ai_agent_os_head_app['client_id'] );
		}
		?>
		<?php if ( array() !== $ai_agent_os_head_apps ) : ?>
			<span class="aaos-agenthead__sep">·</span>
			<?php
			/* translators: %s: app names, e.g. "Claude, ChatGPT". */
			printf( esc_html__( 'Connected: %s', 'zorem-ai-agent-connector' ), esc_html( implode( ', ', array_unique( $ai_agent_os_head_apps ) ) ) );
			?>
		<?php endif; ?>
		<?php if ( $ai_agent_os_agent->get_token_prefix() ) : ?>
			<span class="aaos-agenthead__sep">·</span>
			<?php esc_html_e( 'token', 'zorem-ai-agent-connector' ); ?>
			<code><?php echo esc_html( $ai_agent_os_agent->get_token_prefix() . '…' ); ?></code>
		<?php endif; ?>
	</p>

	<?php // "Add agent" and "Export all agents" (AI Agent OS PRO) are on the agents list, as in PRO. ?>
</div>

<div class="zui-layout" data-aaos-agent>

	<?php ai_agent_os_render_sidebar( $ai_agent_os_sections, $ai_agent_os_agent->get_name() ); ?>

	<div class="zui-content">

		<?php
		// -------------------------------------------------------- Permissions.
		ai_agent_os_section_open( 'permissions', true );
		require __DIR__ . '/permission-matrix.php';
		ai_agent_os_section_close();

		// ------------------------------------------------------------ Connect.
		ai_agent_os_section_open( 'connect' );
		require __DIR__ . '/agent-connect.php';
		ai_agent_os_section_close();

		// ---------------------------------------------------- Access & safety.
		ai_agent_os_section_open( 'access' );
		require __DIR__ . '/agent-access.php';
		ai_agent_os_section_close();

		// ------------------------------------------------------- Manage agent.
		ai_agent_os_section_open( 'manage' );
		require __DIR__ . '/agent-manage.php';
		ai_agent_os_section_close();

		?>

	</div>
</div>

<?php
// --------------------------------------------------------------- Modals.
ai_agent_os_confirm_modal(
	array(
		'id'      => 'aaos-agent-revoke',
		'title'   => __( 'Revoke this agent?', 'zorem-ai-agent-connector' ),
		'body'    => __( 'Its token stops working immediately and any client connected as this agent will start failing. Its permissions are kept, so you can reactivate it later.', 'zorem-ai-agent-connector' ),
		'confirm' => __( 'Revoke agent', 'zorem-ai-agent-connector' ),
		'action'  => 'revoke-agent',
	)
);

ai_agent_os_confirm_modal(
	array(
		'id'      => 'aaos-agent-revoke-all',
		'title'   => __( 'Revoke every permission?', 'zorem-ai-agent-connector' ),
		'body'    => __( 'This agent will be left holding nothing. Every tool disappears from its client at the next connection, and you would have to grant them back yourself.', 'zorem-ai-agent-connector' ),
		'confirm' => __( 'Revoke all permissions', 'zorem-ai-agent-connector' ),
		'action'  => 'revoke-all-permissions',
	)
);

ai_agent_os_confirm_modal(
	array(
		'id'      => 'aaos-agent-rotate',
		'title'   => __( 'Issue a new token?', 'zorem-ai-agent-connector' ),
		'body'    => __( 'The current token stops working the moment the new one is issued. Any client still using it will start failing, and nothing on this screen will explain why — so have the client’s configuration open before you continue.', 'zorem-ai-agent-connector' ),
		'note'    => __( 'The new token is shown in the Token section, once. It cannot be recovered afterwards.', 'zorem-ai-agent-connector' ),
		'confirm' => __( 'Issue new token', 'zorem-ai-agent-connector' ),
		'tone'    => 'primary',
		'action'  => 'rotate-token',
	)
);

