<?php
/**
 * The connect flow's consent screen.
 *
 * Written to be read by somebody who does not know what OAuth is (plan §3.3):
 * no scope strings, no client ids in the main reading path.
 *
 * The client's name is the one string on this page chosen by whoever wants
 * access, so it is never a heading, never the largest text, and always sits
 * inside a container that says it is the client's own claim (§3.3.2). Our
 * own chrome — the plugin name, the headings, the warnings — is fixed text.
 *
 * @package AiAgentOs
 *
 * @var string|null                                     $ai_agent_os_refusal  Why nothing can be approved.
 * @var \AiAgentOs\OAuth\AuthorizationRequest|null   $ai_agent_os_request  The validated request.
 * @var array<string, mixed>|null                       $ai_agent_os_agent    The agent and its tools, or null.
 * @var bool                                            $ai_agent_os_form_error Whether Approve came back with nothing to connect as.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap aaos-consent">
	<p class="aaos-consent__brand"><?php echo esc_html( AI_AGENT_OS_NAME ); ?> · <?php esc_html_e( 'Connection request', 'zorem-ai-agent-connector' ); ?></p>
	<h1><?php esc_html_e( 'A client is asking to connect to this site', 'zorem-ai-agent-connector' ); ?></h1>

	<?php if ( null !== $ai_agent_os_refusal || ! $ai_agent_os_request instanceof \AiAgentOs\OAuth\AuthorizationRequest ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( (string) $ai_agent_os_refusal ); ?></p></div>
		<?php return; ?>
	<?php endif; ?>

	<?php
	$ai_agent_os_client = $ai_agent_os_request->client();
	$ai_agent_os_host   = $ai_agent_os_request->redirect_host();
	$ai_agent_os_name   = (string) $ai_agent_os_client['name'];
	$ai_agent_os_doc    = 'cimd' === $ai_agent_os_client['kind'] ? (string) wp_parse_url( (string) $ai_agent_os_client['client_id'], PHP_URL_HOST ) : '';
	?>

	<div class="aaos-consent__card">
		<h2><?php esc_html_e( 'Who is asking', 'zorem-ai-agent-connector' ); ?></h2>
		<p class="aaos-consent__muted"><?php esc_html_e( 'If you approve, the connection is handed to:', 'zorem-ai-agent-connector' ); ?></p>
		<p class="aaos-consent__host"><?php echo esc_html( $ai_agent_os_host ); ?></p>

		<?php if ( '' !== $ai_agent_os_name ) : ?>
			<p class="aaos-consent__claim">
				<span class="aaos-consent__claim-label"><?php esc_html_e( 'The client calls itself — its own claim, not checked by this site', 'zorem-ai-agent-connector' ); ?></span>
				<bdi><?php echo esc_html( $ai_agent_os_name ); ?></bdi>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $ai_agent_os_doc ) : ?>
			<p class="aaos-consent__muted">
				<?php
				printf(
					/* translators: %s: domain name. */
					esc_html__( 'Its description is published by %s.', 'zorem-ai-agent-connector' ),
					'<strong>' . esc_html( $ai_agent_os_doc ) . '</strong>'
				);
				?>
			</p>
		<?php else : ?>
			<p class="aaos-consent__muted"><?php esc_html_e( 'It registered itself with this site. Anyone can register, so its name proves nothing — judge it by the address above.', 'zorem-ai-agent-connector' ); ?></p>
		<?php endif; ?>

		<?php if ( $ai_agent_os_request->is_loopback() ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This client is a program running on your own computer, not a hosted service. Approve only if you just started the connection yourself, from a program you trust.', 'zorem-ai-agent-connector' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="aaos-consent__muted"><?php esc_html_e( 'If you did not just start a connection from this client, deny this request.', 'zorem-ai-agent-connector' ); ?></p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( \AiAgentOs\OAuth\ConsentScreen::ACTION ); ?>">
		<?php wp_nonce_field( \AiAgentOs\OAuth\ConsentScreen::ACTION ); ?>
		<?php foreach ( $ai_agent_os_request->params() as $ai_agent_os_key => $ai_agent_os_value ) : ?>
			<?php $ai_agent_os_field = 'oauth[' . $ai_agent_os_key . ']'; ?>
			<input type="hidden" name="<?php echo esc_attr( $ai_agent_os_field ); ?>" value="<?php echo esc_attr( $ai_agent_os_value ); ?>">
		<?php endforeach; ?>

		<div class="aaos-consent__card">
			<h2><?php esc_html_e( 'Which agent it connects as', 'zorem-ai-agent-connector' ); ?></h2>
			<p class="aaos-consent__muted"><?php esc_html_e( 'The client is signing in at this agent’s own MCP address, so it connects as this agent and no other. It gets exactly this agent’s tools. To change what it can do later, edit the agent’s Permissions.', 'zorem-ai-agent-connector' ); ?></p>

			<?php
			/*
			 * Approve can come back empty when the agent was revoked between
			 * this page loading and the button being pressed.
			 */
			?>
			<div class="notice notice-error inline"<?php echo $ai_agent_os_form_error ? '' : ' hidden'; ?>>
				<p><?php esc_html_e( 'Nothing on this site is active to connect as any more. Reload this page and start the connection again.', 'zorem-ai-agent-connector' ); ?></p>
			</div>

			<?php if ( null === $ai_agent_os_agent ) : ?>
				<p><?php esc_html_e( 'This site has nothing active to connect as. Open the Agent screen, then start the connection again.', 'zorem-ai-agent-connector' ); ?></p>
			<?php else : ?>
				<?php
				/*
				 * Carried, not chosen. The MCP address already decides what the
				 * client connects as, so a control here would offer a choice
				 * that does not exist; what is being approved is spelled out
				 * underneath instead.
				 */
				?>
				<input type="hidden" name="agent" value="<?php echo esc_attr( (string) (int) $ai_agent_os_agent['id'] ); ?>">

				<div class="aaos-consent__agent">
					<p class="aaos-consent__agentname">
						<bdi><?php echo esc_html( (string) $ai_agent_os_agent['name'] ); ?></bdi>
						<span class="aaos-consent__muted">· <?php echo esc_html( (string) $ai_agent_os_agent['acts_for'] ); ?></span>
					</p>

					<?php if ( '' !== $ai_agent_os_agent['description'] ) : ?>
						<p class="aaos-consent__muted"><?php echo esc_html( (string) $ai_agent_os_agent['description'] ); ?></p>
					<?php endif; ?>

					<details>
						<summary>
							<?php
							printf(
								/* translators: %d: number of tools. */
								esc_html( _n( 'It has %d tool', 'It has %d tools', count( $ai_agent_os_agent['tools'] ), 'zorem-ai-agent-connector' ) ),
								(int) count( $ai_agent_os_agent['tools'] )
							);
							?>
						</summary>
						<?php if ( array() === $ai_agent_os_agent['tools'] ) : ?>
							<p class="aaos-consent__muted"><?php esc_html_e( 'None — the client would connect but could do nothing.', 'zorem-ai-agent-connector' ); ?></p>
						<?php else : ?>
							<ul>
								<?php foreach ( $ai_agent_os_agent['tools'] as $ai_agent_os_tool ) : ?>
									<li>
										<?php echo esc_html( (string) $ai_agent_os_tool['label'] ); ?>
										<code><?php echo esc_html( (string) $ai_agent_os_tool['level'] ); ?></code>
										<?php if ( 'destructive' === $ai_agent_os_tool['risk'] ) : ?>
											<strong><?php esc_html_e( 'destructive', 'zorem-ai-agent-connector' ); ?></strong>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</details>

					<ul class="aaos-consent__wont">
						<?php foreach ( $ai_agent_os_agent['wont'] as $ai_agent_os_line ) : ?>
							<li><?php echo esc_html( (string) $ai_agent_os_line ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<div class="aaos-consent__card">
			<h2><?php esc_html_e( 'What approving does', 'zorem-ai-agent-connector' ); ?></h2>
			<p><?php esc_html_e( 'The client acts as the agent named above, and gets exactly the tools listed. Nothing new is created and no permission changes.', 'zorem-ai-agent-connector' ); ?></p>
			<p class="aaos-consent__muted"><?php esc_html_e( 'No password or token is shown to anyone; the client receives its own credential directly. Revoking the agent disconnects this client, and anything else connected the same way.', 'zorem-ai-agent-connector' ); ?></p>
		</div>

		<p class="aaos-consent__actions">
			<button type="submit" name="decision" value="approve" class="button button-primary"><?php esc_html_e( 'Approve and connect', 'zorem-ai-agent-connector' ); ?></button>
			<button type="submit" name="decision" value="deny" class="button" formnovalidate><?php esc_html_e( 'Deny', 'zorem-ai-agent-connector' ); ?></button>
		</p>
	</form>
</div>

