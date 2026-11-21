<?php
/**
 * Confirmation modals.
 *
 * The prototype specified a confirmation step on all four destructive actions
 * — revoke the agent, revoke all
 * permissions, and issue a new token. The React screens had none: every one of
 * them fired on the first click.
 *
 * ## The consequence sentence is the whole point
 *
 * Each modal states what will happen in the merchant's terms, verbatim from the
 * design, and the confirm button repeats the verb rather than saying "OK". A
 * dialog that says "Are you sure?" above a button that says "Yes" has told the
 * reader nothing they did not already know, and trains them to click through.
 *
 * "Issue new token" is in this list and it is worth saying why, because it is
 * not obviously destructive: it silently stops the previous token working. An
 * agent that was running fine ten seconds ago starts failing, and nothing on
 * the screen connects the two events unless the dialog said so first.
 *
 * ## Markup
 *
 * Canonical `.zui-modal` per the guide. Visibility is the `hidden` attribute,
 * and `zui.js` handles opening via `[data-zui-modal-toggle]`, closing via
 * `[data-zui-modal-close]`, the backdrop, and Escape — so a confirmation modal
 * needs no plugin JavaScript to open or close, only to act on confirm.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_confirm_modal' ) ) {
	/**
	 * Render a confirmation modal.
	 *
	 * Shape:
	 *   id      : DOM id; the trigger's `data-zui-modal-toggle` must match.
	 *   title   : dialog title.
	 *   body    : the consequence sentence. Rendered as one paragraph.
	 *   note    : optional second paragraph, quieter.
	 *   confirm : confirm button label. Repeat the verb.
	 *   tone    : 'danger' (default) or 'primary'.
	 *   action  : value placed on the confirm button as `data-aaos-confirm`,
	 *             which the JavaScript dispatches on.
	 *
	 * @param array<string, mixed> $args Modal definition.
	 */
	function ai_agent_os_confirm_modal( array $args ): void {
		$id      = (string) ( $args['id'] ?? '' );
		$tone    = 'primary' === ( $args['tone'] ?? 'danger' ) ? 'zui-btn-primary' : 'zui-btn-danger';
		$confirm = (string) ( $args['confirm'] ?? __( 'Confirm', 'zorem-ai-agent-connector' ) );

		if ( '' === $id ) {
			return;
		}
		?>
		<div class="zui-modal" id="<?php echo esc_attr( $id ); ?>" hidden>

			<div class="zui-modal__backdrop" data-zui-modal-close></div>

			<div class="zui-modal__dialog" role="dialog" aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $id ); ?>-title">

				<div class="zui-modal__head">
					<div>
						<h3 class="zui-modal__title" id="<?php echo esc_attr( $id ); ?>-title">
							<?php echo esc_html( (string) ( $args['title'] ?? '' ) ); ?>
						</h3>
					</div>
					<button type="button" class="zui-modal__close" data-zui-modal-close
						aria-label="<?php esc_attr_e( 'Close', 'zorem-ai-agent-connector' ); ?>">
						<?php \Zorem\UI\icon( 'x' ); ?>
					</button>
				</div>

				<div class="zui-modal__body">
					<p><?php echo esc_html( (string) ( $args['body'] ?? '' ) ); ?></p>
					<?php if ( ! empty( $args['note'] ) ) : ?>
						<p class="aaos-muted"><?php echo esc_html( (string) $args['note'] ); ?></p>
					<?php endif; ?>

					<?php
					/*
					 * A slot for the subject of the action — the agent's name, how
					 * many permissions. Filled by the JavaScript from the
					 * trigger's data attributes, because one modal serves every
					 * trigger on the screen rather than one modal each: the
					 * page would otherwise carry a dialog per action, all but
					 * one describing something nobody is about to do.
					 */
					?>
					<p class="aaos-confirm__subject" data-aaos-confirm-subject hidden></p>
				</div>

				<div class="zui-modal__foot">
					<div class="zui-modal__actions">
						<button type="button" class="zui-btn zui-btn-ghost" data-zui-modal-close>
							<?php esc_html_e( 'Cancel', 'zorem-ai-agent-connector' ); ?>
						</button>
						<button type="button" class="zui-btn <?php echo esc_attr( $tone ); ?>"
							data-aaos-confirm="<?php echo esc_attr( (string) ( $args['action'] ?? '' ) ); ?>">
							<?php echo esc_html( $confirm ); ?>
						</button>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
