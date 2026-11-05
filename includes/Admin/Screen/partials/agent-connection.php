<?php
/**
 * Connection: endpoint, token, expiry, masking, destructive, status.
 *
 * The three destructive actions reachable from this screen — issue a new token,
 * revoke the agent, revoke all permissions — all go through a confirmation
 * modal. Two of them were single-click in the React version.
 *
 * @package AiAgentOs
 *
 * @var int                        $ai_agent_os_agent_id      Agent id.
 * @var \AiAgentOs\Agents\Agent $ai_agent_os_agent         The agent.
 * @var array<string, mixed>       $ai_agent_os_exposure_data Effective customer-data exposure.
 */

defined( 'ABSPATH' ) || exit;

$ai_agent_os_expires = (string) ( $ai_agent_os_agent->get_expires_at() ?? '' );

if ( '' !== $ai_agent_os_expires ) {
	$ai_agent_os_expires = substr( $ai_agent_os_expires, 0, 10 );
}

/*
 * The agent's own MCP address. A client keeps one connection per address, and
 * this address is the agent: a client that has it connects as the agent and
 * sees the tools the agent is allowed, nothing else on the site.
 */
$ai_agent_os_endpoint = ai_agent_os_service( \AiAgentOs\Mcp\AgentAddress::class )->url( $ai_agent_os_agent );


/*
 * The client-side key for this agent. Clients show it in their own UI, so it
 * follows the agent's name rather than its id — `support-assistant`, not `7`.
 */
$ai_agent_os_key = sanitize_title( $ai_agent_os_agent->get_name() );

if ( '' === $ai_agent_os_key ) {
	$ai_agent_os_key = 'WordPress';
}

/*
 * The token is never rendered by PHP: it is stored only as a hash. When one is
 * issued on this page, agents.js shows the plaintext in the Token card, once;
 * leaving or reloading the page drops it. The agent is set up without one:
 * most clients connect by signing in and never need it.
 */
$ai_agent_os_has_token = '' !== (string) $ai_agent_os_agent->get_token_prefix();
?>
<?php
/*
 * The form starts above the header, so the Save button is inside it.
 *
 * It used to be the other way round: header first with a hand-written save
 * button, then the form. `bindSettingsForm()` finds its button with
 * `$form.find()`, a descendant search, so a button outside the form is a button
 * the binding cannot see. Combined with the binding never running on this form
 * at all, the result was a Save that was permanently disabled and three fields
 * that could not be written. This is the shape `settings.php` already used
 * correctly, and the header helper emits the same button.
 *
 * `data-aaos-endpoint` because this panel writes one agent, not site settings.
 */
?>
<form class="aaos-settings-form" data-aaos-agent-form
	data-aaos-endpoint="/agents/<?php echo esc_attr( (string) $ai_agent_os_agent_id ); ?>"
	data-aaos-method="put">

<?php
ai_agent_os_section_header(
	__( 'Connection', 'zorem-ai-agent-connector' ),
	__( 'How this agent reaches the site, and what it is allowed to be.', 'zorem-ai-agent-connector' ),
	true
);
?>

<?php
/*
 * Connect instructions live here rather than in a tab of their own.
 *
 * They existed only in the one-time modal shown at creation, which means an
 * admin who dismissed it had the endpoint and no way back to the instructions.
 * A second sidebar item called "Connect" beside "Connection" would also make
 * somebody guess which one holds the token.
 */
?>
<div class="zui-card aaos-connect">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Connect this agent', 'zorem-ai-agent-connector' ); ?></h3>
		<p class="zui-card__sub">
			<?php esc_html_e( 'Add this agent’s address to your AI app and approve the sign-in on this site. For a client that cannot open a browser, issue a token in the Token section below.', 'zorem-ai-agent-connector' ); ?>
		</p>
	</div>

	<div class="zui-card__body aaos-connect__body">


		<?php
		$ai_agent_os_conn_agent   = ai_agent_os_service( \AiAgentOs\Agents\AgentRepository::class )->find( (int) $ai_agent_os_agent_id );
		$ai_agent_os_oauth        = null !== $ai_agent_os_conn_agent ? (array) $ai_agent_os_conn_agent->meta( 'oauth', array() ) : array();
		$ai_agent_os_oauth_down   = \AiAgentOs\OAuth\OAuthEndpoints::unavailable_reason();
		$ai_agent_os_oauth_return = (string) ( $ai_agent_os_oauth['redirect_host'] ?? '' );
		?>

		<?php if ( '' !== $ai_agent_os_oauth_return ) : ?>
			<p class="aaos-note">
				<?php
				printf(
					/* translators: %s: host name the client's sign-in returned to. */
					esc_html__( 'A client signing in from %s was last approved to connect as this agent. It holds its own credential, so there is nothing to copy here. Revoking this agent disconnects every client connected as it.', 'zorem-ai-agent-connector' ),
					'<strong>' . esc_html( $ai_agent_os_oauth_return ) . '</strong>'
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		/*
		 * The connect guide: the agent's own address, then the steps for each
		 * app. Replacing the address below stops every client that holds the
		 * old one, which is how a connection is withdrawn without revoking the
		 * agent itself.
		 */
		$ai_agent_os_guide_name = $ai_agent_os_agent->get_name();
		$ai_agent_os_codex_key  = '' !== $ai_agent_os_key ? $ai_agent_os_key : 'agent-os';
		$ai_agent_os_codex_add  = 'codex mcp add ' . $ai_agent_os_codex_key . ' --url ' . $ai_agent_os_endpoint;
		$ai_agent_os_codex_env  = 'ZOREM_' . strtoupper( str_replace( '-', '_', $ai_agent_os_codex_key ) ) . '_TOKEN';
		$ai_agent_os_codex_tok  = $ai_agent_os_codex_add . ' --bearer-token-env-var ' . $ai_agent_os_codex_env;
		$ai_agent_os_claude     = array(
			/* translators: Steps in the Claude app. Bold words are its menu labels. */
			__( 'Click your name (bottom left) → <strong>Settings</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Open <strong>Connectors</strong>, then click <strong>Add custom connector</strong>.', 'zorem-ai-agent-connector' ),
			/* translators: %s: the agent's name, suggested as the connector's name. */
			sprintf( __( 'Give it a name (for example <em>%s</em>) and paste this agent’s <strong>address</strong> into the remote MCP server URL box. Leave the OAuth client ID and secret empty.', 'zorem-ai-agent-connector' ), esc_html( $ai_agent_os_guide_name ) ),
			__( 'Click <strong>Add</strong>, then <strong>Connect</strong> on the connector that appears.', 'zorem-ai-agent-connector' ),
			__( 'Sign in to this site as an administrator if asked, check that the screen names this agent, then click <strong>Approve and connect</strong>.', 'zorem-ai-agent-connector' ),
		);
		$ai_agent_os_chatgpt    = array(
			/* translators: Steps in the ChatGPT app. Bold words are its menu labels. */
			__( 'Click your name (bottom left) → <strong>Settings</strong> → <strong>Plugins</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Open <strong>Developer mode</strong> at the bottom of that list and switch <strong>Developer mode</strong> on.', 'zorem-ai-agent-connector' ),
			__( 'Go back and click <strong>Browse plugins</strong>, then the <strong>+</strong> button beside the search box.', 'zorem-ai-agent-connector' ),
			__( 'In <strong>New Plugin</strong>: type a <strong>Name</strong>, leave <strong>Connection</strong> on <strong>Server URL</strong>, and paste this agent’s <strong>address</strong> underneath.', 'zorem-ai-agent-connector' ),
			__( 'Leave <strong>Authentication</strong> on <strong>OAuth</strong>, tick <strong>I understand and want to continue</strong>, then click <strong>Create</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Sign in to this site as an administrator, check that the screen names this agent, then click <strong>Approve and connect</strong>.', 'zorem-ai-agent-connector' ),
		);
		$ai_agent_os_allowed    = array(
			'strong' => array(),
			'em'     => array(),
		);
		?>

		<h4 class="aaos-connect__title"><?php esc_html_e( 'Add this agent to your AI app', 'zorem-ai-agent-connector' ); ?></h4>
		<?php
		/*
		 * The guide always shows, so it can be read and copied anywhere. Where
		 * sign-in cannot run yet (no https, or plain permalinks) it says so
		 * first, and points at the token, which works everywhere.
		 */
		?>
		<?php if ( null !== $ai_agent_os_oauth_down ) : ?>
			<div class="zui-notice zui-notice--warning aaos-guide__down">
				<div class="zui-notice__body">
					<p class="zui-notice__text"><strong><?php esc_html_e( 'Sign-in from Claude or ChatGPT does not work on this site yet.', 'zorem-ai-agent-connector' ); ?></strong> <?php echo esc_html( $ai_agent_os_oauth_down ); ?></p>
					<p class="zui-notice__text"><?php esc_html_e( 'The steps below apply once it does. Until then, connect with this agent’s token: issue one in the Token section below, or use the Codex command with a token.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>
		<?php endif; ?>
			<p class="aaos-connect__lede">
				<?php esc_html_e( 'Copy this agent’s address, then follow the steps for the app you use. You approve the connection on this site as an administrator — there is no token to copy and no file to edit.', 'zorem-ai-agent-connector' ); ?>
			</p>

			<div class="aaos-guide__url aaos-addressbar">
				<span class="aaos-guide__urllabel"><?php esc_html_e( 'This agent’s address', 'zorem-ai-agent-connector' ); ?></span>
				<code class="aaos-connect__code aaos-addressbar__url" data-aaos-copy-source="endpoint"><?php echo esc_html( $ai_agent_os_endpoint ); ?></code>
				<span class="aaos-addressbar__actions">
					<button type="button" class="zui-btn zui-btn-secondary aaos-btn--sm" data-aaos-copy-target="[data-aaos-copy-source='endpoint']">
						<span><?php esc_html_e( 'Copy', 'zorem-ai-agent-connector' ); ?></span>
					</button>
					<button type="button" class="zui-btn zui-btn-secondary aaos-btn--sm aaos-btn--danger" data-aaos-mcp-address-new="<?php echo esc_attr( (string) $ai_agent_os_agent->get_id() ); ?>"
						title="<?php esc_attr_e( 'If this address leaks, give the agent a new one. The old address stops working at once, and connected apps need the new one.', 'zorem-ai-agent-connector' ); ?>"
						data-confirm="<?php esc_attr_e( 'Give this agent a new MCP address? Clients using the current one will stop working until you give them the new one.', 'zorem-ai-agent-connector' ); ?>"><span><?php esc_html_e( 'New address', 'zorem-ai-agent-connector' ); ?></span></button>
				</span>
			</div>

			<div class="aaos-guide">
				<div class="aaos-guide__app">
					<div class="aaos-guide__head">
						<span class="aaos-guide__badge aaos-guide__badge--claude"><?php esc_html_e( 'Claude', 'zorem-ai-agent-connector' ); ?></span>
						<span class="aaos-guide__sub"><?php esc_html_e( 'claude.ai and the desktop app', 'zorem-ai-agent-connector' ); ?></span>
					</div>
					<ol class="aaos-guide__steps">
						<?php foreach ( $ai_agent_os_claude as $ai_agent_os_step ) : ?>
							<li><?php echo wp_kses( $ai_agent_os_step, $ai_agent_os_allowed ); ?></li>
						<?php endforeach; ?>
					</ol>
					<p class="aaos-guide__done"><?php esc_html_e( 'Done — the connector turns on and this agent’s tools appear in your chats.', 'zorem-ai-agent-connector' ); ?></p>
				</div>

				<div class="aaos-guide__app">
					<div class="aaos-guide__head">
						<span class="aaos-guide__badge aaos-guide__badge--chatgpt"><?php esc_html_e( 'ChatGPT', 'zorem-ai-agent-connector' ); ?></span>
						<span class="aaos-guide__sub"><?php esc_html_e( 'developer mode has to be on first', 'zorem-ai-agent-connector' ); ?></span>
					</div>
					<ol class="aaos-guide__steps">
						<?php foreach ( $ai_agent_os_chatgpt as $ai_agent_os_step ) : ?>
							<li><?php echo wp_kses( $ai_agent_os_step, $ai_agent_os_allowed ); ?></li>
						<?php endforeach; ?>
					</ol>
					<p class="aaos-guide__done"><?php esc_html_e( 'Done — pick it from the + menu inside a chat to use it.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>

			<div class="aaos-guide__note aaos-guide__note--info">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<div>
					<p><strong><?php esc_html_e( 'Codex', 'zorem-ai-agent-connector' ); ?></strong> —
						<?php
						printf(
							/* translators: %s: name of the environment variable that holds the token. */
							esc_html__( 'connects with this agent’s token: issue one in the Token section below, put it in an environment variable named %s, then add the agent in a terminal with:', 'zorem-ai-agent-connector' ),
							'<code>' . esc_html( $ai_agent_os_codex_env ) . '</code>'
						);
						?>
					</p>
					<code class="aaos-guide__cmd" data-aaos-copy-source="codex-token"><?php echo esc_html( $ai_agent_os_codex_tok ); ?></code>
					<button type="button" class="zui-btn zui-btn-secondary aaos-btn--sm" data-aaos-copy-target="[data-aaos-copy-source='codex-token']"><span><?php esc_html_e( 'Copy', 'zorem-ai-agent-connector' ); ?></span></button>
					<p class="aaos-guide__fine"><?php esc_html_e( 'Other apps (Cursor, VS Code, Claude Code): add a remote MCP server and paste the same address. An app that identifies itself with a client metadata document signs in the same way; one that cannot sign in connects with the agent’s token instead.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>

			<div class="aaos-guide__note">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<div>
					<p><?php esc_html_e( 'The connection acts as this agent only: it sees and does exactly what this agent’s permissions allow. To disconnect, remove the connector in the app, or revoke this agent here.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>

	</div>
</div>

<div class="zui-card">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Token', 'zorem-ai-agent-connector' ); ?></h3>
	</div>
	<div class="zui-row">
		<div class="zui-row__head">
			<span class="zui-row__label"><?php esc_html_e( 'Current token', 'zorem-ai-agent-connector' ); ?></span>
			<p class="zui-row__desc">
				<?php esc_html_e( 'Only the first few characters are stored in readable form — enough to tell two tokens apart in a log, not enough to use.', 'zorem-ai-agent-connector' ); ?>
			</p>
		</div>
		<div class="zui-row__control">
			<?php if ( $ai_agent_os_has_token ) : ?>
				<code data-aaos-token-prefix><?php echo esc_html( $ai_agent_os_agent->get_token_prefix() . '…' ); ?></code>
			<?php else : ?>
				<span class="aaos-muted" data-aaos-token-prefix><?php esc_html_e( 'No token issued. Not needed when you connect by signing in.', 'zorem-ai-agent-connector' ); ?></span>
			<?php endif; ?>
			<p>
				<?php if ( $ai_agent_os_has_token ) : ?>
					<button type="button" class="zui-btn zui-btn-secondary" data-zui-modal-toggle="aaos-agent-rotate">
						<?php esc_html_e( 'Issue new token', 'zorem-ai-agent-connector' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="zui-btn zui-btn-secondary" data-aaos-issue-token>
						<?php esc_html_e( 'Issue a token', 'zorem-ai-agent-connector' ); ?>
					</button>
				<?php endif; ?>
			</p>
		</div>
	</div>
	<div class="zui-card__body aaos-tokenbox" data-aaos-token-inline hidden>
		<div class="aaos-tokenbox__token">
			<input type="text" class="zui-input aaos-tokenbox__value" readonly spellcheck="false" autocomplete="off"
				aria-label="<?php esc_attr_e( 'Token', 'zorem-ai-agent-connector' ); ?>" data-aaos-copy-source="token" data-aaos-token-value>
			<button type="button" class="zui-btn zui-btn-secondary aaos-btn--sm" data-aaos-copy-target="[data-aaos-copy-source='token']">
				<span><?php esc_html_e( 'Copy token', 'zorem-ai-agent-connector' ); ?></span>
			</button>
		</div>
		<div class="zui-notice zui-notice--success aaos-connect__notice">
			<div class="zui-notice__body">
				<p class="zui-notice__text"><strong><?php esc_html_e( 'Copy the token now.', 'zorem-ai-agent-connector' ); ?></strong>
					<?php esc_html_e( 'This is the only time it is shown: it is stored only as a hash, and leaving or reloading this page removes it. Send it as “Authorization: Bearer <token>” to this agent’s address. Any token this agent had before has stopped working.', 'zorem-ai-agent-connector' ); ?></p>
			</div>
		</div>
	</div>
</div>

<?php // The form opened above the section header; this is the card it carries. ?>
	<div class="zui-card">
		<div class="zui-card__head">
			<h3 class="zui-card__title"><?php esc_html_e( 'Access', 'zorem-ai-agent-connector' ); ?></h3>
		</div>
		<?php
		ai_agent_os_render_fields(
			array(
				'expires_at'          => array(
					'type'  => 'date',
					'title' => __( 'Expires', 'zorem-ai-agent-connector' ),
					'desc'  => __( 'Leave empty for no expiry. An expired agent stops working but keeps its permissions and history.', 'zorem-ai-agent-connector' ),
					'value' => $ai_agent_os_expires,
				),
				'acts_for'            => array(
					'type'    => 'select',
					'title'   => __( 'Acts for', 'zorem-ai-agent-connector' ),
					'desc'    => __( 'Who this agent works on behalf of. An agent acting for anyone but the store administrator masks personal data unless that is turned off below.', 'zorem-ai-agent-connector' ),
					'options' => array(
						\AiAgentOs\Agents\Agent::ACTS_FOR_ADMIN   => __( 'The store administrator', 'zorem-ai-agent-connector' ),
						\AiAgentOs\Agents\Agent::ACTS_FOR_PARTNER => __( 'A partner (supplier, agency)', 'zorem-ai-agent-connector' ),
						\AiAgentOs\Agents\Agent::ACTS_FOR_PUBLIC  => __( 'The public (shoppers, visitors)', 'zorem-ai-agent-connector' ),
					),
					'value'   => $ai_agent_os_agent->get_acts_for(),
				),
				'pii_masked'          => array(
					'type'  => 'toggle',
					'title' => __( 'Mask customer personal data', 'zorem-ai-agent-connector' ),
					'desc'  => __( 'Replaces names, emails, phone numbers and addresses with [masked] in the answers that hold them, such as the user list. Turning it off gives this agent real personal data.', 'zorem-ai-agent-connector' ),
					'help'  => (string) $ai_agent_os_exposure_data['detail'],
					'value' => $ai_agent_os_agent->masks_pii(),
				),
				'destructive_enabled' => array(
					'type'  => 'toggle',
					'title' => __( 'Allow destructive abilities', 'zorem-ai-agent-connector' ),
					'desc'  => __( 'Destructive abilities delete, cancel or refund things and cannot easily be undone. With this off, this agent cannot run any of them, even ones it is granted.', 'zorem-ai-agent-connector' ),
					'value' => $ai_agent_os_agent->allows_destructive(),
				),
			)
		);
		?>
	</div>
</form>
<div class="zui-card">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Status', 'zorem-ai-agent-connector' ); ?></h3>
	</div>
	<div class="zui-row zui-row--inline">
		<div class="zui-row__head">
			<span class="zui-row__label">
				<?php
				/*
				 * Expiry is part of this answer, not a separate fact.
				 *
				 * This reported `revoked` or `active` and nothing else, so an
				 * expired agent was described as active here while the header
				 * badge two inches above said Expired. Two sources of truth on
				 * one screen — and the one a merchant is about to act on was
				 * the wrong one.
				 */
				if ( 'revoked' === $ai_agent_os_agent->get_status() ) {
					echo esc_html__( 'This agent is revoked', 'zorem-ai-agent-connector' );
				} elseif ( $ai_agent_os_agent->is_expired() ) {
					echo esc_html__( 'This agent has expired', 'zorem-ai-agent-connector' );
				} else {
					echo esc_html__( 'This agent is active', 'zorem-ai-agent-connector' );
				}
				?>
			</span>
			<p class="zui-row__desc">
				<?php esc_html_e( 'Revoking stops the token working. Nothing is deleted — its permissions stay, and you can reactivate it.', 'zorem-ai-agent-connector' ); ?>
			</p>
		</div>
		<div class="zui-row__control">
			<?php if ( 'revoked' === $ai_agent_os_agent->get_status() ) : ?>
				<button type="button" class="zui-btn zui-btn-secondary" data-aaos-reactivate>
					<?php esc_html_e( 'Reactivate this agent', 'zorem-ai-agent-connector' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="zui-btn zui-btn-danger"
					data-zui-modal-toggle="aaos-agent-revoke"
					data-aaos-agent="<?php echo esc_attr( (string) $ai_agent_os_agent_id ); ?>"
					data-aaos-agent-name="<?php echo esc_attr( $ai_agent_os_agent->get_name() ); ?>">
					<?php esc_html_e( 'Revoke this agent', 'zorem-ai-agent-connector' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

</div>
