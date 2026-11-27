<?php
/**
 * Connect: the agent's address, a short guide per app, the apps signed in now,
 * and the token for apps that cannot sign in.
 *
 * Rebuilt to be read top to bottom by someone connecting an app for the first
 * time: copy the address, pick your app, follow three steps. Each app's full
 * walkthrough lives in the docs ("Full setup guide"), not on this screen. The
 * settings that used to share this page moved to Access & safety and Danger
 * zone (agent-access.php, agent-danger.php).
 *
 * @package AiAgentOs
 *
 * @var \AiAgentOs\Agents\Agent $ai_agent_os_agent The agent.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/app-label.php';

/*
 * This agent's own MCP address. A client keeps one connection per address, so
 * each agent having its own is what lets one AI account connect several agents
 * of this site at once, each with its own tools.
 */
$ai_agent_os_endpoint = ai_agent_os_service( \AiAgentOs\Mcp\AgentAddress::class )->url();

// The client-side key for this agent, shown in the app's own UI: `support-assistant`, not `7`.
$ai_agent_os_key = sanitize_title( $ai_agent_os_agent->get_name() );
$ai_agent_os_key = '' !== $ai_agent_os_key ? $ai_agent_os_key : 'agent-os';

$ai_agent_os_codex_env = 'ZOREM_' . strtoupper( str_replace( '-', '_', $ai_agent_os_key ) ) . '_TOKEN';
$ai_agent_os_codex_cmd = 'codex mcp add ' . $ai_agent_os_key . ' --url ' . $ai_agent_os_endpoint . ' --bearer-token-env-var ' . $ai_agent_os_codex_env;

// The token is never rendered by PHP: it is stored only as a hash (agents.js shows a new one once).
$ai_agent_os_has_token = '' !== (string) $ai_agent_os_agent->get_token_prefix();

$ai_agent_os_grok_on = ( new \AiAgentOs\OAuth\RegistrationPolicy( ai_agent_os_service( \AiAgentOs\Core\Settings::class ) ) )->is_enabled();

/**
 * Filters the docs page that holds each app's setup guide.
 *
 * Each app's "Full setup guide" link opens its own page below this one
 * (`connect-claude/`, `connect-chatgpt/`, …). The page itself keeps an
 * `#<app>` part for each, so links from older versions still land there.
 *
 * @param string $url The parent page, with a trailing slash.
 */
$ai_agent_os_docs = trailingslashit( (string) apply_filters( 'ai_agent_os_connect_docs_url', 'https://docs.zorem.com/docs/zorem-ai-agent-connector/connecting-claude-chatgpt-and-codex/' ) );

// The guide page of each app tab, under the docs page above.
$ai_agent_os_guides = array(
	'claude'  => 'connect-claude/',
	'chatgpt' => 'connect-chatgpt/',
	'grok'    => 'connect-grok/',
	'codex'   => 'connect-codex/',
	'other'   => 'connect-other-apps/',
);

$ai_agent_os_strong = array(
	'strong' => array(),
	'code'   => array(),
);

/*
 * One short guide per app: three steps, one tip, a link to the full guide.
 * Kept to what someone needs with the app open beside this screen; the rest
 * (plans, pop-up blockers, troubleshooting) is in the docs.
 */
$ai_agent_os_apps = array(
	'claude'  => array(
		'name'  => __( 'Claude', 'zorem-ai-agent-connector' ),
		'sub'   => __( 'claude.ai and the desktop app', 'zorem-ai-agent-connector' ),
		'steps' => array(
			__( 'In Claude, open <strong>Settings → Connectors → Add custom connector</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Name it and paste the agent address. Leave the OAuth fields empty.', 'zorem-ai-agent-connector' ),
			__( 'Click <strong>Connect</strong>, then approve on this site.', 'zorem-ai-agent-connector' ),
		),
		'note'  => __( 'Custom connectors need a paid Claude plan.', 'zorem-ai-agent-connector' ),
	),
	'chatgpt' => array(
		'name'  => __( 'ChatGPT', 'zorem-ai-agent-connector' ),
		'sub'   => __( 'developer mode has to be on first', 'zorem-ai-agent-connector' ),
		'steps' => array(
			__( 'In ChatGPT, open <strong>Settings → Plugins</strong> and turn on <strong>Developer mode</strong>.', 'zorem-ai-agent-connector' ),
			__( '<strong>Browse plugins → +</strong>, paste the agent address and keep Authentication on <strong>OAuth</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Click <strong>Create</strong>, then approve on this site.', 'zorem-ai-agent-connector' ),
		),
		'note'  => __( 'Needs developer mode in ChatGPT settings.', 'zorem-ai-agent-connector' ),
	),
	'grok'    => array(
		'name'  => __( 'Grok', 'zorem-ai-agent-connector' ),
		'sub'   => __( 'grok.com', 'zorem-ai-agent-connector' ),
		'steps' => array(
			__( 'On grok.com, open <strong>Settings → Connect Apps → Add a custom connector</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Paste the agent address and click <strong>Add MCP</strong>.', 'zorem-ai-agent-connector' ),
			__( 'Approve the sign-in on this site.', 'zorem-ai-agent-connector' ),
		),
		'note'  => __( 'Allow pop-ups for grok.com if the sign-in window does not open.', 'zorem-ai-agent-connector' ),
	),
	'codex'   => array(
		'name'  => __( 'Codex', 'zorem-ai-agent-connector' ),
		'sub'   => __( 'connects with a token', 'zorem-ai-agent-connector' ),
		'steps' => array(
			__( 'Click <strong>Issue a token</strong> below and copy it.', 'zorem-ai-agent-connector' ),
			/* translators: %s: environment variable name. */
			sprintf( __( 'Save it in an environment variable named %s.', 'zorem-ai-agent-connector' ), '<code>' . esc_html( $ai_agent_os_codex_env ) . '</code>' ),
			__( 'Run this command in a terminal:', 'zorem-ai-agent-connector' ),
		),
		'note'  => __( 'The token is shown once — store it somewhere safe.', 'zorem-ai-agent-connector' ),
		'cmd'   => $ai_agent_os_codex_cmd,
	),
	'other'   => array(
		'name'  => __( 'Other', 'zorem-ai-agent-connector' ),
		'title' => __( 'Other apps', 'zorem-ai-agent-connector' ),
		'sub'   => __( 'Cursor, VS Code, Claude Code and others', 'zorem-ai-agent-connector' ),
		'steps' => array(
			__( 'Add a remote MCP server in the app.', 'zorem-ai-agent-connector' ),
			__( 'Paste the agent address.', 'zorem-ai-agent-connector' ),
			__( 'Sign in if the app offers it; otherwise issue a token below and send it as <code>Authorization: Bearer &lt;token&gt;</code>.', 'zorem-ai-agent-connector' ),
		),
		'note'  => __( 'Any app that supports remote MCP servers can connect.', 'zorem-ai-agent-connector' ),
	),
);

// Grok signs in by registering, which "Let Grok sign in" in Settings allows; without it, no Grok guide.
if ( ! $ai_agent_os_grok_on ) {
	unset( $ai_agent_os_apps['grok'] );
}

// The apps signed in as this agent now, and who approved each (meta.grants, stored at approval).

$ai_agent_os_signed  = (array) ( ai_agent_os_service( \AiAgentOs\OAuth\OAuthStore::class )->signed_in_apps()[ $ai_agent_os_agent->get_id() ] ?? array() );
$ai_agent_os_grants  = (array) $ai_agent_os_agent->meta( 'grants', array() );
$ai_agent_os_dt_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>

<?php ai_agent_os_section_header( __( 'Connect', 'zorem-ai-agent-connector' ), __( 'Add this agent to an AI app. You approve every connection on this site.', 'zorem-ai-agent-connector' ) ); ?>

<?php
/*
 * A server that drops the Authorization header lets the whole sign-in succeed
 * and then refuses every call, so the warning sits where the admin connects.
 */
$ai_agent_os_auth_check = ai_agent_os_service( \AiAgentOs\Mcp\AuthorizationHeaderCheck::class )->result();
?>
<?php if ( \AiAgentOs\Mcp\AuthorizationHeaderCheck::STATUS_STRIPPED === $ai_agent_os_auth_check['status'] ) : ?>
	<div class="zui-notice zui-notice--danger aaos-guide__down">
		<div class="zui-notice__body">
			<p class="zui-notice__text"><strong><?php esc_html_e( 'This web server removes the Authorization header, so no app can use this address yet.', 'zorem-ai-agent-connector' ); ?></strong> <?php esc_html_e( 'The sign-in will appear to succeed, then every call is refused. Fix it on the server:', 'zorem-ai-agent-connector' ); ?></p>
			<ul class="zui-notice__text">
				<?php foreach ( \AiAgentOs\Mcp\AuthorizationHeaderCheck::fixes() as $ai_agent_os_fix ) : ?>
					<li><?php echo esc_html( $ai_agent_os_fix['text'] ); ?> <code><?php echo esc_html( $ai_agent_os_fix['code'] ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
<?php endif; ?>

<?php // ------------------------------------------------------------ Address. ?>
<div class="zui-card aaos-cx">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Agent address', 'zorem-ai-agent-connector' ); ?></h3>
		<p class="zui-card__sub"><?php esc_html_e( 'Paste this into your AI app. It is private to this agent — share it only with apps you trust.', 'zorem-ai-agent-connector' ); ?></p>
	</div>
	<div class="zui-card__body">
		<div class="aaos-cx-addr">
			<label class="screen-reader-text" for="aaos-cx-address"><?php esc_html_e( 'Agent address', 'zorem-ai-agent-connector' ); ?></label>
			<input type="text" id="aaos-cx-address" class="zui-input aaos-cx-addr__field" readonly spellcheck="false"
				value="<?php echo esc_attr( $ai_agent_os_endpoint ); ?>" data-aaos-copy-source="endpoint">
			<button type="button" class="zui-btn zui-btn-primary aaos-cx-addr__copy" data-aaos-copy-target="[data-aaos-copy-source='endpoint']">
				<span><?php esc_html_e( 'Copy', 'zorem-ai-agent-connector' ); ?></span>
			</button>
			<div class="aaos-cx-more">
				<button type="button" class="zui-btn zui-btn-secondary aaos-cx-more__toggle" data-aaos-cx-more aria-expanded="false" aria-controls="aaos-cx-more-menu"
					aria-label="<?php esc_attr_e( 'More address options', 'zorem-ai-agent-connector' ); ?>">
					<?php \Zorem\UI\icon( 'more-vertical' ); ?>
				</button>
				<div class="aaos-cx-more__menu" id="aaos-cx-more-menu" hidden>
					<button type="button" class="aaos-cx-more__item" data-aaos-mcp-address-new
						data-confirm="<?php esc_attr_e( 'Give this agent a new MCP address? Apps using the current one stop working until you give them the new one.', 'zorem-ai-agent-connector' ); ?>">
						<?php esc_html_e( 'Get a new address…', 'zorem-ai-agent-connector' ); ?>
					</button>
					<p class="aaos-cx-more__note"><?php esc_html_e( 'Only if the address leaked. Every connected app stops until it gets the new one.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>
		</div>
	</div>
</div>

<?php // ------------------------------------------------------- Connect an app. ?>
<div class="zui-card aaos-cx">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Connect an app', 'zorem-ai-agent-connector' ); ?></h3>
		<p class="zui-card__sub"><?php esc_html_e( 'Pick the app you use. You approve the connection on this site — no password or token to copy.', 'zorem-ai-agent-connector' ); ?></p>
	</div>
	<div class="zui-card__body" data-aaos-cx-apps>
		<div class="aaos-cx-tabs" role="tablist" aria-label="<?php esc_attr_e( 'AI app', 'zorem-ai-agent-connector' ); ?>">
			<?php $ai_agent_os_first = true; ?>
			<?php foreach ( $ai_agent_os_apps as $ai_agent_os_slug => $ai_agent_os_app ) : ?>
				<button type="button" role="tab" class="aaos-cx-tab aaos-cx-tab--<?php echo esc_attr( $ai_agent_os_slug ); ?>"
					id="aaos-cx-tab-<?php echo esc_attr( $ai_agent_os_slug ); ?>"
					aria-controls="aaos-cx-panel-<?php echo esc_attr( $ai_agent_os_slug ); ?>"
					aria-selected="<?php echo $ai_agent_os_first ? 'true' : 'false'; ?>"
					tabindex="<?php echo $ai_agent_os_first ? '0' : '-1'; ?>"
					data-aaos-cx-tab="<?php echo esc_attr( $ai_agent_os_slug ); ?>">
					<span class="aaos-cx-tab__dot" aria-hidden="true"></span><?php echo esc_html( $ai_agent_os_app['name'] ); ?>
				</button>
				<?php $ai_agent_os_first = false; ?>
			<?php endforeach; ?>
		</div>

		<?php $ai_agent_os_first = true; ?>
		<?php foreach ( $ai_agent_os_apps as $ai_agent_os_slug => $ai_agent_os_app ) : ?>
			<?php $ai_agent_os_title = (string) ( $ai_agent_os_app['title'] ?? $ai_agent_os_app['name'] ); ?>
			<div class="aaos-cx-panel" role="tabpanel" id="aaos-cx-panel-<?php echo esc_attr( $ai_agent_os_slug ); ?>"
				aria-labelledby="aaos-cx-tab-<?php echo esc_attr( $ai_agent_os_slug ); ?>"
				data-aaos-cx-panel="<?php echo esc_attr( $ai_agent_os_slug ); ?>" <?php echo $ai_agent_os_first ? '' : 'hidden'; ?>>
				<div class="aaos-cx-panel__head">
					<h4 class="aaos-cx-panel__title"><?php echo esc_html( $ai_agent_os_title ); ?></h4>
					<span class="aaos-cx-panel__sub"><?php echo esc_html( $ai_agent_os_app['sub'] ); ?></span>
				</div>
				<ol class="aaos-cx-steps">
					<?php foreach ( $ai_agent_os_app['steps'] as $ai_agent_os_step ) : ?>
						<li><span class="aaos-cx-steps__text"><?php echo wp_kses( $ai_agent_os_step, $ai_agent_os_strong ); ?></span></li>
					<?php endforeach; ?>
				</ol>
				<?php if ( ! empty( $ai_agent_os_app['cmd'] ) ) : ?>
					<div class="aaos-cx-cmd">
						<code data-aaos-copy-source="cmd-<?php echo esc_attr( $ai_agent_os_slug ); ?>"><?php echo esc_html( $ai_agent_os_app['cmd'] ); ?></code>
						<button type="button" class="zui-btn zui-btn-secondary aaos-btn--sm" data-aaos-copy-target="[data-aaos-copy-source='cmd-<?php echo esc_attr( $ai_agent_os_slug ); ?>']"><span><?php esc_html_e( 'Copy', 'zorem-ai-agent-connector' ); ?></span></button>
					</div>
				<?php endif; ?>
				<div class="aaos-cx-panel__foot">
					<span class="aaos-cx-panel__note"><?php echo esc_html( $ai_agent_os_app['note'] ); ?></span>
					<a class="aaos-cx-panel__docs" href="<?php echo esc_url( isset( $ai_agent_os_guides[ $ai_agent_os_slug ] ) ? $ai_agent_os_docs . $ai_agent_os_guides[ $ai_agent_os_slug ] : $ai_agent_os_docs . '#' . $ai_agent_os_slug ); ?>" target="_blank" rel="noopener noreferrer">
						<?php
						/* translators: %s: app name, e.g. Grok. */
						printf( esc_html__( 'Full %s setup guide', 'zorem-ai-agent-connector' ), esc_html( $ai_agent_os_title ) );
						?>
						<?php \Zorem\UI\icon( 'external-link' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'zorem-ai-agent-connector' ); ?></span>
					</a>
				</div>
			</div>
			<?php $ai_agent_os_first = false; ?>
		<?php endforeach; ?>

		<?php
		/*
		 * The token: only for apps that cannot sign in, so it shows only on
		 * the Codex and Other tabs (agents.js toggles it with the tab).
		 */
		?>
		<div class="aaos-cx-token" data-aaos-cx-token-for="codex other" hidden>
			<div class="zui-row">
				<div class="zui-row__head">
					<span class="zui-row__label"><?php esc_html_e( 'Token', 'zorem-ai-agent-connector' ); ?></span>
					<p class="zui-row__desc"><?php esc_html_e( 'Shown once when issued, then stored only as a hash. The app sends it as “Authorization: Bearer <token>”. Issuing a new one stops the old one.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
				<div class="zui-row__control">
					<?php if ( $ai_agent_os_has_token ) : ?>
						<code data-aaos-token-prefix><?php echo esc_html( $ai_agent_os_agent->get_token_prefix() . '…' ); ?></code>
						<p><button type="button" class="zui-btn zui-btn-secondary" data-zui-modal-toggle="aaos-agent-rotate"><?php esc_html_e( 'Issue new token', 'zorem-ai-agent-connector' ); ?></button></p>
					<?php else : ?>
						<span class="aaos-muted" data-aaos-token-prefix><?php esc_html_e( 'No token issued.', 'zorem-ai-agent-connector' ); ?></span>
						<p><button type="button" class="zui-btn zui-btn-secondary" data-aaos-issue-token><?php esc_html_e( 'Issue a token', 'zorem-ai-agent-connector' ); ?></button></p>
					<?php endif; ?>
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
							<?php esc_html_e( 'This is the only time it is shown: leaving or reloading this page removes it. Any token this agent had before has stopped working.', 'zorem-ai-agent-connector' ); ?></p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<?php // ------------------------------------------------------ Connected apps. ?>
<div class="zui-card aaos-cx">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Connected apps', 'zorem-ai-agent-connector' ); ?></h3>
		<p class="zui-card__sub"><?php esc_html_e( 'Apps signed in as this agent right now. Disconnecting one leaves the others connected.', 'zorem-ai-agent-connector' ); ?></p>
	</div>
	<div class="zui-card__body">
		<?php if ( array() === $ai_agent_os_signed ) : ?>
			<p class="aaos-cx-empty"><?php esc_html_e( 'No app is signed in as this agent yet. Apps connected with a token are not listed here.', 'zorem-ai-agent-connector' ); ?></p>
		<?php else : ?>
			<ul class="aaos-cx-apps">
				<?php foreach ( $ai_agent_os_signed as $ai_agent_os_grant => $ai_agent_os_sign ) : ?>
					<?php
					$ai_agent_os_label = ai_agent_os_app_label( (string) $ai_agent_os_sign['client_id'] );
					$ai_agent_os_who   = (int) ( $ai_agent_os_grants[ $ai_agent_os_grant ]['user'] ?? 0 );
					$ai_agent_os_user  = $ai_agent_os_who > 0 ? get_userdata( $ai_agent_os_who ) : false;
					$ai_agent_os_since = (int) ( $ai_agent_os_grants[ $ai_agent_os_grant ]['at'] ?? 0 );
					$ai_agent_os_last  = strtotime( (string) $ai_agent_os_sign['last_at'] . ' UTC' );
					?>
					<li class="aaos-cx-app" data-aaos-cx-app="<?php echo esc_attr( (string) $ai_agent_os_grant ); ?>">
						<span class="aaos-cx-app__badge aaos-cx-app__badge--<?php echo esc_attr( sanitize_html_class( strtolower( $ai_agent_os_label ) ) ); ?>" aria-hidden="true"><?php echo esc_html( mb_substr( $ai_agent_os_label, 0, 1 ) ); ?></span>
						<span class="aaos-cx-app__text">
							<span class="aaos-cx-app__name"><?php echo esc_html( $ai_agent_os_label ); ?></span>
							<span class="aaos-cx-app__meta">
								<?php
								$ai_agent_os_bits = array();

								if ( $ai_agent_os_user instanceof \WP_User ) {
									/* translators: %s: administrator's display name. */
									$ai_agent_os_bits[] = sprintf( __( 'Approved by %s', 'zorem-ai-agent-connector' ), $ai_agent_os_user->display_name );
								}

								if ( $ai_agent_os_since > 0 ) {
									/* translators: %s: date and time. */
									$ai_agent_os_bits[] = sprintf( __( 'connected %s', 'zorem-ai-agent-connector' ), wp_date( $ai_agent_os_dt_fmt, $ai_agent_os_since ) );
								}

								if ( false !== $ai_agent_os_last ) {
									/* translators: %s: date and time. */
									$ai_agent_os_bits[] = sprintf( __( 'last active %s', 'zorem-ai-agent-connector' ), wp_date( $ai_agent_os_dt_fmt, $ai_agent_os_last ) );
								}

								echo esc_html( implode( ' · ', $ai_agent_os_bits ) );
								?>
							</span>
						</span>
						<?php
						/* translators: %s: app name. */
						$ai_agent_os_confirm = sprintf( __( 'Disconnect %s? It stops working at once and has to sign in again to reconnect. Other apps stay connected.', 'zorem-ai-agent-connector' ), $ai_agent_os_label );
						?>
						<button type="button" class="zui-btn zui-btn-secondary aaos-btn--sm aaos-cx-app__off"
							data-aaos-cx-disconnect="<?php echo esc_attr( (string) $ai_agent_os_grant ); ?>"
							data-confirm="<?php echo esc_attr( $ai_agent_os_confirm ); ?>">
							<span><?php esc_html_e( 'Disconnect', 'zorem-ai-agent-connector' ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php
		$ai_agent_os_last_refusal = ai_agent_os_service( \AiAgentOs\Mcp\LastRefusal::class )->get();
		?>
		<?php if ( null !== $ai_agent_os_last_refusal ) : ?>
			<p class="aaos-note aaos-cx-refusal">
				<?php
				printf(
					/* translators: 1: date and time, 2: why the request was refused, 3: the reason's code. */
					esc_html__( 'The MCP address last refused a request on %1$s: %2$s (%3$s)', 'zorem-ai-agent-connector' ),
					esc_html( ai_agent_os_local_datetime( $ai_agent_os_last_refusal['at'] ) ),
					esc_html( \AiAgentOs\Governance\Decision::labels()[ $ai_agent_os_last_refusal['reason'] ] ?? '' ),
					'<code>' . esc_html( $ai_agent_os_last_refusal['reason'] ) . '</code>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</div>

<p class="aaos-cx-foot">
	<?php \Zorem\UI\icon( 'lock' ); ?>
	<?php esc_html_e( 'Every app acts as this agent only: it can do exactly what this agent’s permissions allow, and nothing more.', 'zorem-ai-agent-connector' ); ?>
</p>
