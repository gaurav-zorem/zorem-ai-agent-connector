<?php
/**
 * The connect approval, as a small page of its own.
 *
 * Apps (Claude, ChatGPT, Grok) open this in a small popup window when someone
 * clicks Connect. It used to be a full WordPress admin screen, sidebar and
 * all, three cards long. Now it is one card that fits the popup: which app,
 * which agent, three plain lines on what it can do, the rest behind "Show
 * details", and Approve. It is a complete HTML page, printed before the admin
 * header; ConsentScreen::load() ends the request after it, so none of the
 * admin chrome loads.
 *
 * Who is asking: the app's name is its own claim, so the name shown comes from
 * the redirect address it cannot fake when that address is a known app's
 * (claude.ai is Claude), and the address is always shown beside it.
 *
 * @package AiAgentOs
 *
 * @var string|null                                $ai_agent_os_refusal    Why nothing can be approved.
 * @var \AiAgentOs\OAuth\AuthorizationRequest|null $ai_agent_os_request    The validated request.
 * @var array<int, array<string, mixed>>           $ai_agent_os_cards      Agents the app may connect as (one when the address decides).
 * @var bool                                       $ai_agent_os_form_error Whether Approve came back without a usable agent.
 * @var string                                     $ai_agent_os_form_text  What that error says.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_popup_app' ) ) {
	/**
	 * The app a request comes from: a name, a colour and a letter, from its redirect address.
	 *
	 * @param string $host  Redirect host (checked: the code is only ever sent there).
	 * @param string $claim The name the app gave (its own claim).
	 * @return array{name: string, color: string, letter: string, known: bool}
	 */
	function ai_agent_os_popup_app( string $host, string $claim ): array {
		$apps = array(
			'claude.ai'     => array( 'Claude', '#d97757' ),
			'anthropic.com' => array( 'Claude', '#d97757' ),
			'chatgpt.com'   => array( 'ChatGPT', '#10a37f' ),
			'openai.com'    => array( 'ChatGPT', '#10a37f' ),
			'grok.com'      => array( 'Grok', '#111827' ),
			'x.ai'          => array( 'Grok', '#111827' ),
		);

		foreach ( $apps as $domain => $app ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return array(
					'name'   => $app[0],
					'color'  => $app[1],
					'letter' => mb_substr( $app[0], 0, 1 ),
					'known'  => true,
				);
			}
		}

		$name = '' !== trim( $claim ) ? trim( $claim ) : $host;

		return array(
			'name'   => $name,
			'color'  => '#64748b',
			'letter' => mb_strtoupper( mb_substr( $name, 0, 1 ) ),
			'known'  => false,
		);
	}
}

$ai_agent_os_site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
$ai_agent_os_site = '' !== $ai_agent_os_site ? $ai_agent_os_site : (string) wp_parse_url( home_url(), PHP_URL_HOST );
$ai_agent_os_user = wp_get_current_user();
$ai_agent_os_app  = null;
$ai_agent_os_host = '';

if ( $ai_agent_os_request instanceof \AiAgentOs\OAuth\AuthorizationRequest ) {
	$ai_agent_os_client = $ai_agent_os_request->client();
	$ai_agent_os_host   = $ai_agent_os_request->redirect_host();
	$ai_agent_os_app    = ai_agent_os_popup_app( $ai_agent_os_host, (string) $ai_agent_os_client['name'] );
}

$ai_agent_os_title = null !== $ai_agent_os_app
	/* translators: 1: the app, e.g. Claude; 2: the site name. */
	? sprintf( __( 'Connect %1$s to %2$s?', 'zorem-ai-agent-connector' ), $ai_agent_os_app['name'], $ai_agent_os_site )
	: __( 'Connect an app', 'zorem-ai-agent-connector' );

nocache_headers();

// Like nocache_headers(): once output has started a header cannot be sent, and trying raises a warning.
if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( (string) get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $ai_agent_os_title ); ?></title>
<style>
	:root { --blue:#3b64d3; --blue-d:#2d4db5; --blue-bg:#eef2fd; --ink:#0f172a; --text:#1e293b; --soft:#475569; --faint:#94a3b8; --line:#e2e8f0; --g:#15803d; --g-bg:#e8f6ee; --a:#b45309; --a-bg:#fff4e0; --r:#b42318; --r-bg:#fdecea; }
	* { box-sizing: border-box; }
	html, body { margin: 0; }
	body { min-height: 100vh; background: #f1f4f8; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--text); font-size: 14px; line-height: 1.5; display: flex; justify-content: center; align-items: flex-start; padding: 24px 16px; }
	.card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #dde3ea; border-radius: 16px; box-shadow: 0 10px 30px rgba(15,23,42,.08); padding: 22px 24px 20px; }
	.site { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--soft); margin: 0 0 16px; }
	.site .mark { width: 22px; height: 22px; border-radius: 6px; background: var(--blue-bg); color: var(--blue); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
	.pair { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 4px 0 14px; }
	.logo { width: 54px; height: 54px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 22px; color: #fff; }
	.dots { display: inline-flex; gap: 4px; } .dots i { width: 5px; height: 5px; border-radius: 50%; background: var(--faint); }
	h1 { margin: 0; text-align: center; font-size: 18px; line-height: 1.35; color: var(--ink); overflow-wrap: anywhere; }
	.sub { margin: 6px 0 18px; text-align: center; font-size: 13px; color: var(--soft); overflow-wrap: anywhere; }
	.agent { display: flex; gap: 12px; align-items: center; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; margin: 0 0 4px; }
	label.agent { cursor: pointer; } label.agent:has(input:checked) { border-color: var(--blue); box-shadow: 0 0 0 1px var(--blue); }
	.agent input[type=radio] { margin: 0; accent-color: var(--blue); }
	.av { flex: 0 0 36px; height: 36px; border-radius: 10px; background: var(--blue-bg); color: var(--blue); font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
	.agent .t { flex: 1; min-width: 0; } .agent b { display: block; color: var(--ink); overflow-wrap: anywhere; } .agent span.m { font-size: 12px; color: var(--soft); }
	.facts { list-style: none; margin: 12px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
	.facts li { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; }
	.ic { flex: 0 0 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
	.ic.g { background: var(--g-bg); color: var(--g); } .ic.a { background: var(--a-bg); color: var(--a); } .ic.r { background: var(--r-bg); color: var(--r); }
	.choice + .choice { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--line); }
	details { margin-top: 12px; font-size: 12px; color: var(--soft); }
	details summary { cursor: pointer; color: var(--blue); font-weight: 500; width: max-content; }
	details ul { margin: 8px 0 0; padding-left: 18px; }
	details li { margin: 2px 0; }
	.warn { margin-top: 12px; font-size: 12px; color: #7a3e06; background: var(--a-bg); border-radius: 8px; padding: 8px 10px; }
	.error { margin: 0 0 12px; font-size: 13px; color: var(--r); background: var(--r-bg); border-radius: 8px; padding: 8px 10px; }
	.trust { display: flex; gap: 8px; align-items: flex-start; margin: 16px 0 0; font-size: 13px; cursor: pointer; }
	.trust input { margin-top: 2px; width: 16px; height: 16px; accent-color: var(--blue); }
	.trust small { display: block; color: var(--faint); font-size: 12px; }
	.actions { display: flex; gap: 10px; margin-top: 18px; }
	button { font: inherit; cursor: pointer; }
	.btn { flex: 1; height: 42px; border-radius: 10px; border: 0; background: var(--blue); color: #fff; font-weight: 600; font-size: 14px; }
	.btn:hover { background: var(--blue-d); }
	.btn2 { flex: 0 0 110px; height: 42px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; color: var(--ink); font-weight: 500; font-size: 14px; }
	.btn2:hover { border-color: var(--soft); }
	button:focus-visible, summary:focus-visible, input:focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
	.foot { margin: 14px 0 0; text-align: center; font-size: 11px; color: var(--faint); }
	.refusal { text-align: center; } .refusal .big { width: 48px; height: 48px; border-radius: 50%; background: var(--r-bg); color: var(--r); font-size: 22px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; margin: 6px 0 12px; }
	.refusal p { color: var(--soft); font-size: 13px; }
</style>
</head>
<body>
<main class="card">
	<p class="site">
		<span class="mark" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $ai_agent_os_site, 0, 1 ) ) ); ?></span>
		<span>
			<?php
			echo esc_html( $ai_agent_os_site );

			if ( $ai_agent_os_user->exists() ) {
				/* translators: %s: the logged-in user's display name. */
				echo ' · ' . esc_html( sprintf( __( 'signed in as %s', 'zorem-ai-agent-connector' ), $ai_agent_os_user->display_name ) );
			}
			?>
		</span>
	</p>

	<?php if ( null !== $ai_agent_os_refusal || null === $ai_agent_os_app ) : ?>
		<div class="refusal">
			<span class="big" aria-hidden="true">!</span>
			<h1><?php esc_html_e( 'This connection can’t be approved', 'zorem-ai-agent-connector' ); ?></h1>
			<p><?php echo esc_html( (string) $ai_agent_os_refusal ); ?></p>
			<p class="foot"><?php esc_html_e( 'You can close this window. Nothing has been changed.', 'zorem-ai-agent-connector' ); ?></p>
		</div>
	<?php else : ?>

		<div class="pair" aria-hidden="true">
			<span class="logo" style="background:<?php echo esc_attr( $ai_agent_os_app['color'] ); ?>"><?php echo esc_html( $ai_agent_os_app['letter'] ); ?></span>
			<span class="dots"><i></i><i></i><i></i></span>
			<span class="logo" style="background:var(--blue)"><?php echo esc_html( mb_strtoupper( mb_substr( $ai_agent_os_site, 0, 1 ) ) ); ?></span>
		</div>

		<h1><?php echo esc_html( $ai_agent_os_title ); ?></h1>
		<p class="sub">
			<?php
			echo esc_html(
				1 === count( $ai_agent_os_cards )
					/* translators: 1: the app, 2: its web address, e.g. claude.ai. */
					? sprintf( __( '%1$s (%2$s) will act as this agent.', 'zorem-ai-agent-connector' ), $ai_agent_os_app['name'], $ai_agent_os_host )
					/* translators: 1: the app, 2: its web address, e.g. claude.ai. */
					: sprintf( __( 'Choose which agent %1$s (%2$s) acts as.', 'zorem-ai-agent-connector' ), $ai_agent_os_app['name'], $ai_agent_os_host )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( \AiAgentOs\OAuth\ConsentScreen::ACTION ); ?>">
			<?php wp_nonce_field( \AiAgentOs\OAuth\ConsentScreen::ACTION ); ?>
			<?php foreach ( $ai_agent_os_request->params() as $ai_agent_os_key => $ai_agent_os_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( 'oauth[' . $ai_agent_os_key . ']' ); ?>" value="<?php echo esc_attr( $ai_agent_os_value ); ?>">
			<?php endforeach; ?>

			<?php if ( $ai_agent_os_form_error ) : ?>
				<p class="error" role="alert"><?php echo esc_html( $ai_agent_os_form_text ); ?></p>
			<?php endif; ?>

			<?php if ( array() === $ai_agent_os_cards ) : ?>
				<p class="error"><?php esc_html_e( 'There is no active agent to connect as. Reactivate it on the Agent screen, then connect again.', 'zorem-ai-agent-connector' ); ?></p>
			<?php endif; ?>

			<?php
			$ai_agent_os_single = 1 === count( $ai_agent_os_cards );
			$ai_agent_os_trust  = false;

			foreach ( $ai_agent_os_cards as $ai_agent_os_card ) :
				$ai_agent_os_tools  = (array) $ai_agent_os_card['tools'];
				$ai_agent_os_writes = (int) ( $ai_agent_os_card['writes'] ?? 0 );
				$ai_agent_os_dest   = ! empty( $ai_agent_os_card['destructive'] );
				$ai_agent_os_masks  = ! empty( $ai_agent_os_card['masks'] );
				$ai_agent_os_view   = 0 === $ai_agent_os_writes && ! $ai_agent_os_dest;
				$ai_agent_os_trust  = $ai_agent_os_trust || ( $ai_agent_os_single && $ai_agent_os_view && $ai_agent_os_masks );
				$ai_agent_os_count  = count( $ai_agent_os_tools );
				$ai_agent_os_tag    = $ai_agent_os_single ? 'div' : 'label';
				?>
				<div class="choice">
					<<?php echo esc_html( $ai_agent_os_tag ); ?> class="agent">
						<?php
						/*
						 * One agent: nothing to pick and nothing posted. The address the
						 * popup was opened at decides the agent, and handle() approves
						 * the site's agent whatever a form sends.
						 */
						if ( ! $ai_agent_os_single ) :
							?>
							<input type="radio" name="agent" value="<?php echo esc_attr( (string) (int) $ai_agent_os_card['id'] ); ?>" required>
						<?php endif; ?>
						<span class="av" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( (string) $ai_agent_os_card['name'], 0, 1 ) ) ); ?></span>
						<span class="t">
							<b><bdi><?php echo esc_html( (string) $ai_agent_os_card['name'] ); ?></bdi></b>
							<span class="m">
								<?php
								/* translators: 1: who the agent acts for, e.g. "The public"; 2: number of tools. */
								echo esc_html( sprintf( _n( 'Acts for %1$s · %2$s tool', 'Acts for %1$s · %2$s tools', $ai_agent_os_count, 'zorem-ai-agent-connector' ), mb_strtolower( (string) $ai_agent_os_card['acts_for'] ), number_format_i18n( $ai_agent_os_count ) ) );
								?>
							</span>
						</span>
					</<?php echo esc_html( $ai_agent_os_tag ); ?>>

					<ul class="facts">
						<?php if ( 0 === $ai_agent_os_count ) : ?>
							<li><span class="ic a">!</span><?php esc_html_e( 'It has no tools yet, so it would connect but could do nothing', 'zorem-ai-agent-connector' ); ?></li>
						<?php elseif ( 0 === $ai_agent_os_writes ) : ?>
							<li><span class="ic g">✓</span><?php esc_html_e( 'Can only look, it can’t change anything', 'zorem-ai-agent-connector' ); ?></li>
						<?php else : ?>
							<li><span class="ic a">!</span>
								<?php
								/* translators: 1: tools that can change something, 2: all its tools. */
								echo esc_html( sprintf( __( 'Can change things: %1$s of its %2$s tools make changes', 'zorem-ai-agent-connector' ), number_format_i18n( $ai_agent_os_writes ), number_format_i18n( $ai_agent_os_count ) ) );
								?>
							</li>
						<?php endif; ?>

						<?php if ( $ai_agent_os_dest ) : ?>
							<li><span class="ic r">!</span><?php esc_html_e( 'Can delete, cancel or refund things', 'zorem-ai-agent-connector' ); ?></li>
						<?php else : ?>
							<li><span class="ic g">✓</span><?php esc_html_e( 'Can’t delete, cancel or refund anything', 'zorem-ai-agent-connector' ); ?></li>
						<?php endif; ?>

						<?php if ( $ai_agent_os_masks ) : ?>
							<li><span class="ic g">✓</span><?php esc_html_e( 'Customer names, emails and addresses are hidden', 'zorem-ai-agent-connector' ); ?></li>
						<?php else : ?>
							<li><span class="ic r">!</span><?php esc_html_e( 'Can see customer names, emails and addresses', 'zorem-ai-agent-connector' ); ?></li>
						<?php endif; ?>
					</ul>

					<?php if ( $ai_agent_os_writes > 0 || $ai_agent_os_dest ) : ?>
						<p class="warn">
							<?php
							/* translators: %s: the app, e.g. Grok. */
							echo esc_html( sprintf( __( 'This agent can change your store. Only approve if you started this from %s yourself.', 'zorem-ai-agent-connector' ), $ai_agent_os_app['name'] ) );
							?>
						</p>
					<?php endif; ?>

					<details>
						<summary><?php esc_html_e( 'Show details', 'zorem-ai-agent-connector' ); ?></summary>
						<ul>
							<?php foreach ( array_slice( $ai_agent_os_tools, 0, 25 ) as $ai_agent_os_tool ) : ?>
								<li>
									<?php echo esc_html( (string) $ai_agent_os_tool['label'] ); ?>
									<?php if ( 'write' === ( $ai_agent_os_tool['level'] ?? '' ) ) : ?>
										· <?php echo esc_html( 'destructive' === ( $ai_agent_os_tool['risk'] ?? '' ) ? __( 'can delete', 'zorem-ai-agent-connector' ) : __( 'can change', 'zorem-ai-agent-connector' ) ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
							<?php if ( $ai_agent_os_count > 25 ) : ?>
								<li>
									<?php
									/* translators: %s: number of further tools. */
									echo esc_html( sprintf( __( 'and %s more', 'zorem-ai-agent-connector' ), number_format_i18n( $ai_agent_os_count - 25 ) ) );
									?>
								</li>
							<?php endif; ?>
							<?php if ( (int) ( $ai_agent_os_card['hides'] ?? 0 ) > 0 ) : ?>
								<li>
									<?php
									/* translators: %s: number of abilities. */
									echo esc_html( sprintf( _n( 'It cannot see the %s other ability on this site.', 'It cannot see the %s other abilities on this site.', (int) $ai_agent_os_card['hides'], 'zorem-ai-agent-connector' ), number_format_i18n( (int) $ai_agent_os_card['hides'] ) ) );
									?>
								</li>
							<?php endif; ?>
						</ul>
					</details>
				</div>
			<?php endforeach; ?>

			<details>
				<summary><?php esc_html_e( 'About this connection', 'zorem-ai-agent-connector' ); ?></summary>
				<ul>
					<li>
						<?php
						/* translators: %s: web address, e.g. claude.ai. */
						echo esc_html( sprintf( __( 'Approving sends the connection to %s, and nowhere else.', 'zorem-ai-agent-connector' ), $ai_agent_os_host ) );
						?>
					</li>
					<?php if ( ! $ai_agent_os_app['known'] ) : ?>
						<li><?php esc_html_e( 'The app’s name is its own claim, not checked by this site. Judge it by the address above.', 'zorem-ai-agent-connector' ); ?></li>
					<?php endif; ?>
					<?php if ( $ai_agent_os_request->is_loopback() ) : ?>
						<li><strong><?php esc_html_e( 'This app is a program on your own computer, not a hosted service. Approve only if you just started it yourself.', 'zorem-ai-agent-connector' ); ?></strong></li>
					<?php endif; ?>
					<li><?php esc_html_e( 'No password or token is shown; the app receives its own sign-in directly.', 'zorem-ai-agent-connector' ); ?></li>
					<li><?php esc_html_e( 'Nothing new is created and no permission changes. Disconnect it any time from the agent’s Connect page.', 'zorem-ai-agent-connector' ); ?></li>
				</ul>
			</details>

			<?php if ( $ai_agent_os_trust ) : ?>
				<label class="trust">
					<input type="checkbox" name="trust" value="1">
					<span>
						<?php
						/* translators: %s: the app, e.g. Claude. */
						echo esc_html( sprintf( __( 'Don’t ask again for %s on this agent', 'zorem-ai-agent-connector' ), $ai_agent_os_app['name'] ) );
						?>
						<small><?php esc_html_e( 'Next time it connects to this agent while you’re signed in, it connects straight away. Only offered for agents that can only look.', 'zorem-ai-agent-connector' ); ?></small>
					</span>
				</label>
			<?php endif; ?>

			<div class="actions">
				<button type="submit" name="decision" value="deny" class="btn2" formnovalidate><?php esc_html_e( 'Deny', 'zorem-ai-agent-connector' ); ?></button>
				<button type="submit" name="decision" value="approve" class="btn"<?php disabled( array() === $ai_agent_os_cards ); ?>><?php esc_html_e( 'Approve', 'zorem-ai-agent-connector' ); ?></button>
			</div>
			<p class="foot">
				<?php
				/* translators: %s: the app, e.g. Claude. */
				echo esc_html( sprintf( __( 'Didn’t start this from %s? Click Deny.', 'zorem-ai-agent-connector' ), $ai_agent_os_app['name'] ) );
				?>
			</p>
		</form>
	<?php endif; ?>
</main>
</body>
</html>
