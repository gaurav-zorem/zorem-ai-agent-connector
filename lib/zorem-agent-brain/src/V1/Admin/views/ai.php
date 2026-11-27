<?php
/**
 * Website Brain: AI provider and acknowledgement.
 *
 * Laid out as a status, then the three ways to connect a provider (the safest
 * first), with what the key pays for beside them. Storing a key here is two
 * numbered steps, because the acknowledgement must come before the key.
 *
 * @package ZoremAgentBrain
 *
 * @var array<string, mixed> $zorem_agent_brain View data: availability, model, assistants, consent, stored_key, providers.
 */

defined( 'ABSPATH' ) || exit;

use ZoremAgentBrain\V1\Ai\ConsentLedger;
use ZoremAgentBrain\V1\Ai\ProviderResolver;
use ZoremAgentBridge\V1\Dto\ModelLease;

$zorem_agent_brain_available = $zorem_agent_brain['availability'];
$zorem_agent_brain_consent   = $zorem_agent_brain['consent'];
$zorem_agent_brain_key       = $zorem_agent_brain['stored_key'];
$zorem_agent_brain_current   = $zorem_agent_brain_consent['version'] >= ConsentLedger::CURRENT_VERSION;
$zorem_agent_brain_ready     = ! empty( $zorem_agent_brain_available['available'] );

$zorem_agent_brain_constants = array();

foreach ( array_keys( $zorem_agent_brain['providers'] ) as $zorem_agent_brain_pid ) {
	foreach ( ProviderResolver::constants_for( (string) $zorem_agent_brain_pid ) as $zorem_agent_brain_const ) {
		$zorem_agent_brain_constants[ $zorem_agent_brain_const ] = ProviderResolver::label( (string) $zorem_agent_brain_pid );
	}
}

$zorem_agent_brain_uses = array(
	// Support chat was merged into the shopping assistant, which now answers order questions too.
	array( 'cart', __( 'Shopping assistant', 'zorem-ai-agent-connector' ), __( 'Writes answers to shoppers on your store, about products, delivery, returns and their own orders, instead of only quoting your pages.', 'zorem-ai-agent-connector' ) ),
	array( 'admin-users', __( 'Store assistant', 'zorem-ai-agent-connector' ), __( 'Answers your staff in wp-admin, from what the Brain knows.', 'zorem-ai-agent-connector' ) ),
);
?>
<section class="zui-section zorem-brain__ai">

	<?php /* ---- Status. ---- */ ?>
	<div class="zui-card zorem-brain__status <?php echo $zorem_agent_brain_ready ? 'is-ready' : 'is-missing'; ?>">
		<span class="zorem-brain__statusicon dashicons dashicons-<?php echo esc_attr( $zorem_agent_brain_ready ? 'yes-alt' : 'warning' ); ?>" aria-hidden="true"></span>
		<div class="zorem-brain__statustext">
			<h2 class="zorem-brain__statustitle">
				<?php echo $zorem_agent_brain_ready ? esc_html__( 'AI is ready', 'zorem-ai-agent-connector' ) : esc_html__( 'No AI provider yet', 'zorem-ai-agent-connector' ); ?>
			</h2>
			<p class="zorem-brain__meta">
				<?php if ( $zorem_agent_brain_ready ) : ?>
					<?php
					if ( ProviderResolver::SOURCE_BRAIN === ( $zorem_agent_brain_available['source'] ?? '' ) ) {
						/* translators: %s: provider name. */
						echo esc_html( sprintf( __( '%s, with the key saved on this page.', 'zorem-ai-agent-connector' ), ProviderResolver::label( (string) $zorem_agent_brain_available['provider'] ) ) );
					} else {
						/* translators: 1: provider name, 2: where the key comes from, such as "WordPress Connectors". */
						echo esc_html( sprintf( __( '%1$s, with the key from %2$s.', 'zorem-ai-agent-connector' ), ProviderResolver::label( (string) $zorem_agent_brain_available['provider'] ), (string) $zorem_agent_brain_available['credential'] ) );
					}
					?>
				<?php else : ?>
					<?php
					// The diagnosis, not a generic line: "your security keys changed" needs saying (Rule 8).
					echo esc_html( (string) $zorem_agent_brain_available['admin_message'] );
					?>
				<?php endif; ?>
			</p>
			<?php if ( ! $zorem_agent_brain_ready ) : ?>
				<p class="zorem-brain__meta"><?php esc_html_e( 'The assistants still work without one — they answer by quoting your own pages. Connect a provider below to let them write their replies.', 'zorem-ai-agent-connector' ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $zorem_agent_brain_ready ) : ?>
			<span class="zui-badge zui-badge--success"><?php esc_html_e( 'Connected', 'zorem-ai-agent-connector' ); ?></span>
		<?php else : ?>
			<span class="zui-badge zui-badge--warning"><?php esc_html_e( 'Optional', 'zorem-ai-agent-connector' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( $zorem_agent_brain_ready && ModelLease::CONSENT_STALE === $zorem_agent_brain['assistants']->state() ) : ?>
		<div class="zui-note zui-note--warning">
			<span class="dashicons dashicons-warning"></span>
			<p><?php esc_html_e( 'You agreed to this key being used for AI drafts. It would now also answer questions from shoppers and staff, which is a different thing to agree to — so the assistants stay off until you confirm the acknowledgement below.', 'zorem-ai-agent-connector' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="zorem-brain__aicols">

		<?php /* ---- Connect a provider: three ways, the safest first. ---- */ ?>
		<div class="zui-card zorem-brain__card">
			<div class="zui-card__head zorem-brain__cardhead">
				<div>
					<h2 class="zui-card__title"><?php esc_html_e( 'Connect a provider', 'zorem-ai-agent-connector' ); ?></h2>
					<p class="zorem-brain__cardsub"><?php esc_html_e( 'Choose one of three ways. WordPress also needs the provider’s plugin installed — for example the AI provider plugin for Anthropic.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>
			<div class="zui-card__body">
				<ol class="zorem-brain__ways">
					<li class="zorem-brain__way">
						<div class="zorem-brain__wayhead">
							<span class="zorem-brain__waynum">1</span>
							<strong><?php esc_html_e( 'Add it to wp-config.php', 'zorem-ai-agent-connector' ); ?></strong>
							<span class="zui-badge zui-badge--success"><?php esc_html_e( 'Recommended', 'zorem-ai-agent-connector' ); ?></span>
						</div>
						<p class="zorem-brain__meta"><?php esc_html_e( 'The safest place: the key never touches the database. Add one line above “That’s all, stop editing!” and it is used automatically.', 'zorem-ai-agent-connector' ); ?></p>
						<pre class="zorem-brain__snippet">define( 'ANTHROPIC_API_KEY', 'your-key-here' );</pre>
						<?php if ( array() !== $zorem_agent_brain_constants ) : ?>
							<p class="zorem-brain__meta zorem-brain__consts">
								<?php esc_html_e( 'Names read:', 'zorem-ai-agent-connector' ); ?>
								<?php foreach ( $zorem_agent_brain_constants as $zorem_agent_brain_const => $zorem_agent_brain_plabel ) : ?>
									<code title="<?php echo esc_attr( $zorem_agent_brain_plabel ); ?>"><?php echo esc_html( $zorem_agent_brain_const ); ?></code>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
					</li>

					<li class="zorem-brain__way">
						<div class="zorem-brain__wayhead">
							<span class="zorem-brain__waynum">2</span>
							<strong><?php esc_html_e( 'Use WordPress’s Connectors screen', 'zorem-ai-agent-connector' ); ?></strong>
						</div>
						<p class="zorem-brain__meta"><?php esc_html_e( 'Settings → Connectors, where WordPress keeps AI keys for every plugin on the site. A key there is used automatically.', 'zorem-ai-agent-connector' ); ?></p>
					</li>

					<li class="zorem-brain__way">
						<div class="zorem-brain__wayhead">
							<span class="zorem-brain__waynum">3</span>
							<strong><?php esc_html_e( 'Store a key here', 'zorem-ai-agent-connector' ); ?></strong>
							<?php if ( $zorem_agent_brain_key['stored'] ) : ?>
								<span class="zui-badge zui-badge--success">
									<?php
									/* translators: %s: last four characters of the key. */
									echo esc_html( sprintf( __( 'Stored — ends in %s', 'zorem-ai-agent-connector' ), $zorem_agent_brain_key['last4'] ) );
									?>
								</span>
							<?php endif; ?>
						</div>

						<details class="zorem-brain__fineprint">
							<summary><?php esc_html_e( 'Read this first: how a stored key is protected', 'zorem-ai-agent-connector' ); ?></summary>
							<p><?php esc_html_e( "A key stored here is encrypted with a key derived from this site's security keys. That protects it in a stray backup or a database copy. It is not a vault: anything running as WordPress on this site can decrypt it, and so can anyone who has both the database and wp-config.php. If your security keys are changed, the stored key stops working and you will need to enter it again.", 'zorem-ai-agent-connector' ); ?></p>
							<p><?php esc_html_e( 'When you turn the assistants on, this key answers questions from shoppers and staff. Their messages are sent to your provider, and you pay for that usage.', 'zorem-ai-agent-connector' ); ?></p>
						</details>

						<div class="zorem-brain__steps">
							<div class="zorem-brain__step <?php echo $zorem_agent_brain_current ? 'is-done' : ''; ?>">
								<span class="zorem-brain__stepmark" aria-hidden="true"><?php echo $zorem_agent_brain_current ? '✓' : 'a'; ?></span>
								<div class="zorem-brain__stepbody">
									<strong><?php esc_html_e( 'Acknowledge', 'zorem-ai-agent-connector' ); ?></strong>
									<?php if ( $zorem_agent_brain_current ) : ?>
										<p class="zorem-brain__meta">
											<?php
											/* translators: %s: date. */
											echo esc_html( sprintf( __( 'Acknowledged %s.', 'zorem-ai-agent-connector' ), \ZoremAgentBrain\V1\Admin\Screen::local_time( $zorem_agent_brain_consent['at'] ) ) );
											?>
											<button type="button" class="zui-btn-ghost zorem-brain__inline" data-zorem-brain-action data-method="DELETE" data-route="provider/consent" data-confirm="<?php esc_attr_e( 'Withdraw the acknowledgement? A stored key then stops being used.', 'zorem-ai-agent-connector' ); ?>" data-reload="1"><?php esc_html_e( 'Withdraw', 'zorem-ai-agent-connector' ); ?></button>
										</p>
									<?php else : ?>
										<form data-zorem-brain-form data-route="provider/consent" data-reload="1" class="zorem-brain__ackform">
											<label class="zorem-brain__check">
												<input type="checkbox" name="acknowledge" value="1" required>
												<span><?php esc_html_e( 'I understand that a key stored in the database is encrypted but not a vault, and that, when the assistants are on, it pays for questions from shoppers and staff.', 'zorem-ai-agent-connector' ); ?></span>
											</label>
											<button type="submit" class="zui-btn-secondary"><?php esc_html_e( 'Record acknowledgement', 'zorem-ai-agent-connector' ); ?></button>
										</form>
									<?php endif; ?>
								</div>
							</div>

							<div class="zorem-brain__step <?php echo $zorem_agent_brain_key['stored'] ? 'is-done' : ''; ?>">
								<span class="zorem-brain__stepmark" aria-hidden="true"><?php echo $zorem_agent_brain_key['stored'] ? '✓' : 'b'; ?></span>
								<div class="zorem-brain__stepbody">
									<strong><?php esc_html_e( 'Save the key', 'zorem-ai-agent-connector' ); ?></strong>
									<form data-zorem-brain-form data-route="provider/key" data-reload="1" class="zorem-brain__keyform" <?php echo ! $zorem_agent_brain_current && 0 === $zorem_agent_brain_consent['version'] ? 'data-needs-ack="1"' : ''; ?>>
										<div class="zui-select-wrap">
											<select class="zui-select" name="provider" aria-label="<?php esc_attr_e( 'Provider', 'zorem-ai-agent-connector' ); ?>">
												<?php foreach ( $zorem_agent_brain['providers'] as $zorem_agent_brain_id => $zorem_agent_brain_label ) : ?>
													<option value="<?php echo esc_attr( $zorem_agent_brain_id ); ?>" <?php selected( $zorem_agent_brain_key['provider'], $zorem_agent_brain_id ); ?>><?php echo esc_html( $zorem_agent_brain_label ); ?></option>
												<?php endforeach; ?>
											</select>
											<span class="zui-select-chevron"><?php function_exists( 'Zorem\UI\icon' ) && \Zorem\UI\icon( 'chevron-down' ); ?></span>
										</div>
										<input type="password" class="zui-input" name="key" autocomplete="off" required aria-label="<?php esc_attr_e( 'API key', 'zorem-ai-agent-connector' ); ?>" placeholder="<?php echo esc_attr( $zorem_agent_brain_key['stored'] ? sprintf( /* translators: %s: last four characters. */ __( 'Stored — ends in %s', 'zorem-ai-agent-connector' ), $zorem_agent_brain_key['last4'] ) : __( 'Paste your API key', 'zorem-ai-agent-connector' ) ); ?>">
										<button type="submit" class="zui-btn-primary"><span><?php esc_html_e( 'Save key', 'zorem-ai-agent-connector' ); ?></span></button>
										<?php if ( $zorem_agent_brain_key['stored'] ) : ?>
											<button type="button" class="zui-btn-danger" data-zorem-brain-action data-method="DELETE" data-route="provider/key" data-confirm="<?php esc_attr_e( 'Delete the stored key?', 'zorem-ai-agent-connector' ); ?>" data-reload="1"><?php esc_html_e( 'Delete key', 'zorem-ai-agent-connector' ); ?></button>
										<?php endif; ?>
									</form>
									<?php if ( ! $zorem_agent_brain_current && 0 === $zorem_agent_brain_consent['version'] ) : ?>
										<p class="zorem-brain__meta"><?php esc_html_e( 'Tick the acknowledgement above; Save key records it and then saves the key.', 'zorem-ai-agent-connector' ); ?></p>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</li>
				</ol>
			</div>
		</div>

		<div class="zorem-brain__aiside">

			<?php /* ---- Which model the provider is asked for. ---- */ ?>
			<?php $zorem_agent_brain_model = $zorem_agent_brain['model']; ?>
			<div class="zui-card zorem-brain__card">
				<div class="zui-card__head zorem-brain__cardhead">
					<div>
						<h2 class="zui-card__title"><?php esc_html_e( 'Model', 'zorem-ai-agent-connector' ); ?></h2>
						<p class="zorem-brain__cardsub"><?php esc_html_e( 'Which of your provider\'s models writes the replies. If answers fail with "quota exceeded", pick a model your key has quota for, such as a "Flash" or "Lite" one on a free Google key.', 'zorem-ai-agent-connector' ); ?></p>
					</div>
				</div>
				<div class="zui-card__body">
					<?php if ( ! $zorem_agent_brain_model['reachable'] ) : ?>
						<p class="zorem-brain__meta"><?php esc_html_e( 'Connect a provider first; its models are listed here once WordPress can reach it.', 'zorem-ai-agent-connector' ); ?></p>
					<?php else : ?>
						<form data-zorem-brain-form data-route="provider/model" data-reload="1" class="zorem-brain__modelform">
							<div class="zui-select-wrap">
								<select class="zui-select" name="model" aria-label="<?php esc_attr_e( 'Model', 'zorem-ai-agent-connector' ); ?>">
									<option value=""><?php esc_html_e( 'Provider default', 'zorem-ai-agent-connector' ); ?></option>
									<?php if ( '' !== $zorem_agent_brain_model['chosen'] && ! isset( $zorem_agent_brain_model['models'][ $zorem_agent_brain_model['chosen'] ] ) ) : ?>
										<option value="<?php echo esc_attr( $zorem_agent_brain_model['chosen'] ); ?>" selected><?php echo esc_html( $zorem_agent_brain_model['chosen'] ); ?></option>
									<?php endif; ?>
									<?php foreach ( $zorem_agent_brain_model['models'] as $zorem_agent_brain_mid => $zorem_agent_brain_mname ) : ?>
										<option value="<?php echo esc_attr( $zorem_agent_brain_mid ); ?>" <?php selected( $zorem_agent_brain_model['chosen'], $zorem_agent_brain_mid ); ?>><?php echo esc_html( $zorem_agent_brain_mname === $zorem_agent_brain_mid ? $zorem_agent_brain_mid : $zorem_agent_brain_mname . ' (' . $zorem_agent_brain_mid . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="zui-select-chevron"><?php function_exists( 'Zorem\UI\icon' ) && \Zorem\UI\icon( 'chevron-down' ); ?></span>
							</div>
							<button type="submit" class="zui-btn-primary"><span><?php esc_html_e( 'Save model', 'zorem-ai-agent-connector' ); ?></span></button>
						</form>
						<p class="zorem-brain__meta">
							<?php
							echo esc_html(
								array() === $zorem_agent_brain_model['models']
									? __( 'The provider did not return a list just now; "Provider default" still works.', 'zorem-ai-agent-connector' )
									/* translators: %s: number of models. */
									: sprintf( _n( '%s model can write text and use tools.', '%s models can write text and use tools.', count( $zorem_agent_brain_model['models'] ), 'zorem-ai-agent-connector' ), number_format_i18n( count( $zorem_agent_brain_model['models'] ) ) )
							);
							?>
							<button type="button" class="zui-btn-ghost zorem-brain__inline" data-zorem-brain-action data-method="POST" data-route="provider/models" data-reload="1"><?php esc_html_e( 'Refresh list', 'zorem-ai-agent-connector' ); ?></button>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php /* ---- What the key pays for. ---- */ ?>
			<div class="zui-card zorem-brain__card">
				<div class="zui-card__head zorem-brain__cardhead">
					<div>
						<h2 class="zui-card__title"><?php esc_html_e( 'What the key is used for', 'zorem-ai-agent-connector' ); ?></h2>
						<p class="zorem-brain__cardsub"><?php esc_html_e( 'Messages go to your provider, and you pay them for that usage. Agents that connect over MCP — Claude, ChatGPT — bring their own AI and never use this key.', 'zorem-ai-agent-connector' ); ?></p>
					</div>
				</div>
				<div class="zui-card__body">
					<ul class="zorem-brain__uses">
						<?php foreach ( $zorem_agent_brain_uses as $zorem_agent_brain_use ) : ?>
							<li class="zorem-brain__use">
								<span class="zorem-brain__useicon dashicons dashicons-<?php echo esc_attr( $zorem_agent_brain_use[0] ); ?>" aria-hidden="true"></span>
								<span>
									<strong><?php echo esc_html( $zorem_agent_brain_use[1] ); ?></strong>
									<span class="zorem-brain__meta"><?php echo esc_html( $zorem_agent_brain_use[2] ); ?></span>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</section>
