<?php
/**
 * The permission matrix.
 *
 * Rebuilt at Z8 to the design bundle's `AgentDetailTab.tsx`: one card holding a
 * toolbar, one table, and tinted section rows instead of a card per ability
 * category. Every permission on one surface, with the risk of each ability
 * beside the control that grants it.
 *
 * ## Three honesty affordances live in this table
 *
 * 1. **Inferred risk is labelled inferred.** The classifier guesses from an
 *    ability's name when a plugin declares nothing, and a guess shown as a fact
 *    is how an administrator ends up trusting the wrong thing. The badge carries
 *    an asterisk and says so on hover.
 * 2. **"Capabilities lent automatically" is shown, not hidden.** When no map
 *    names the WordPress capabilities an ability needs, they are lent on
 *    demand for each call — never the ones that would let a call change users,
 *    plugins or site code. The badge says which abilities run that way.
 * 3. **The locked-destructive state explains itself where it happens** — the
 *    note sits under that row's own control, not in a banner at the top. The
 *    design put it there and it is the better placement: a disabled control with
 *    its reason three feet away is a disabled control nobody understands.
 *
 * Search is client-side over rows already on the page. There are a few hundred
 * abilities at most and they are all here; a round trip to filter a list the
 * browser is holding would be slower and would need a route.
 *
 * @package AiAgentOs
 *
 * @var int                                           $ai_agent_os_agent_id  Agent id.
 * @var \AiAgentOs\Agents\Agent                    $ai_agent_os_agent     The agent.
 * @var \AiAgentOs\Governance\PermissionRepository  $ai_agent_os_perms     Grants.
 * @var \AiAgentOs\Governance\AbilityInventory      $ai_agent_os_inventory Abilities.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Both inputs may be supplied by the caller, so this table can render for an
 * agent that does not exist yet.
 *
 * The create screen needs the same matrix before there is anything to read
 * grants from — and a second, nearly-identical table would drift from this one
 * the first time either changed. `isset()` rather than a flag: a caller either
 * has these answers or does not.
 */
$ai_agent_os_grants = isset( $ai_agent_os_grants )
	? (array) $ai_agent_os_grants
	: $ai_agent_os_perms->get_grants( $ai_agent_os_agent_id );

$ai_agent_os_categories = $ai_agent_os_inventory->grouped_descriptors();

$ai_agent_os_locked = isset( $ai_agent_os_locked )
	? (bool) $ai_agent_os_locked
	: ! $ai_agent_os_agent->allows_destructive();

$ai_agent_os_levels = array(
	'none'  => __( 'None', 'zorem-ai-agent-connector' ),
	'read'  => __( 'Read', 'zorem-ai-agent-connector' ),
	'write' => __( 'Write', 'zorem-ai-agent-connector' ),
);

$ai_agent_os_risk_labels = array(
	'read'        => __( 'READ', 'zorem-ai-agent-connector' ),
	'write'       => __( 'WRITE', 'zorem-ai-agent-connector' ),
	'destructive' => __( 'DESTRUCTIVE', 'zorem-ai-agent-connector' ),
);
?>
<div class="aaos-tablecard aaos-matrix" data-aaos-matrix>

	<div class="aaos-tablecard__toolbar">
		<div class="aaos-search">
			<span class="aaos-search__icon" aria-hidden="true"><?php \Zorem\UI\icon( 'search' ); ?></span>
			<label class="screen-reader-text" for="aaos-matrix-search"><?php esc_html_e( 'Search abilities', 'zorem-ai-agent-connector' ); ?></label>
			<input type="search" id="aaos-matrix-search" class="aaos-search__input"
				data-aaos-matrix-search
				placeholder="<?php esc_attr_e( 'Search abilities…', 'zorem-ai-agent-connector' ); ?>">
		</div>

		<?php
		/*
		 * Every section at once, with the same three choices each section row
		 * has. Only the on-screen matrix changes: nothing is saved until
		 * "Save permissions", and a level a row cannot take (destructive with
		 * destructive mode off) is skipped, as it is per section. "None"
		 * rather than "Revoke all": on an existing agent that name is the
		 * button that revokes at once.
		 */
		?>
		<div class="aaos-matrix-all" role="group" aria-label="<?php esc_attr_e( 'Set every ability', 'zorem-ai-agent-connector' ); ?>">
			<span class="aaos-matrix-all__label"><?php esc_html_e( 'All abilities', 'zorem-ai-agent-connector' ); ?></span>
			<span class="aaos-mtable__bulk">
				<button type="button" class="aaos-bulk" data-aaos-bulk="write"><?php esc_html_e( 'Grant all', 'zorem-ai-agent-connector' ); ?></button>
				<button type="button" class="aaos-bulk" data-aaos-bulk="read"><?php esc_html_e( 'All read', 'zorem-ai-agent-connector' ); ?></button>
				<button type="button" class="aaos-bulk" data-aaos-bulk="none"><?php esc_html_e( 'None', 'zorem-ai-agent-connector' ); ?></button>
			</span>
		</div>

		<?php
		/*
		 * Only for an agent that exists. Rendered for an agent that does not
		 * yet have an id, these buttons would save to agent 0, which answers
		 * 404.
		 */
		?>
		<?php if ( (int) $ai_agent_os_agent_id > 0 ) : ?>
			<div class="aaos-tablecard__actions">
				<button type="button" class="aaos-btn aaos-btn--ghost" data-zui-modal-toggle="aaos-agent-revoke-all">
					<?php esc_html_e( 'Revoke all', 'zorem-ai-agent-connector' ); ?>
				</button>
				<button type="button" class="aaos-btn aaos-btn--primary" data-aaos-save-permissions disabled>
					<?php esc_html_e( 'Save permissions', 'zorem-ai-agent-connector' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>

	<div class="aaos-tablecard__scroll">
		<table class="aaos-atable aaos-mtable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ability', 'zorem-ai-agent-connector' ); ?></th>
					<th><?php esc_html_e( 'Risk', 'zorem-ai-agent-connector' ); ?></th>
					<th class="aaos-atable__actions"><?php esc_html_e( 'Grant', 'zorem-ai-agent-connector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ai_agent_os_categories as $ai_agent_os_slug => $ai_agent_os_group ) : ?>

					<?php
					/*
					 * How much of this section is granted, computed before the
					 * heading is drawn because the heading reports it and the
					 * default open state depends on it.
					 */
					$ai_agent_os_total   = 0;
					$ai_agent_os_granted = 0;

					foreach ( (array) $ai_agent_os_group['abilities'] as $ai_agent_os_counted ) {
						++$ai_agent_os_total;

						$ai_agent_os_has = (string) ( $ai_agent_os_grants[ (string) $ai_agent_os_counted['name'] ] ?? 'none' );

						if ( 'none' !== $ai_agent_os_has ) {
							++$ai_agent_os_granted;
						}
					}

					/*
					 * Sections holding nothing start closed.
					 *
					 * On a site with a few large plugins this screen runs to
					 * several thousand pixels, nearly all of it abilities set
					 * to None — so the few grants that decide what the agent
					 * can actually do are the hardest things on the page to
					 * find. Opening only the sections with a grant puts the
					 * answer to "what can this agent do" on one screen, and
					 * leaves the rest one click away.
					 *
					 * Everything stays in the DOM either way: the search still
					 * reaches a closed section, and no request is needed to
					 * open one.
					 */
					$ai_agent_os_open = $ai_agent_os_granted > 0;
					?>
					<tr class="aaos-mtable__section" data-aaos-matrix-group data-aaos-group="<?php echo esc_attr( (string) $ai_agent_os_slug ); ?>">
						<td colspan="3">
							<?php
							/*
							 * A wrapper, because the flex row cannot be the cell
							 * itself. `display: flex` on a `td` takes it out of
							 * the table's box model, so `colspan` stops applying
							 * and the band ended at the ability column's edge —
							 * a rule running down the middle of a full-width row
							 * with the bulk buttons stopping short of it.
							 */
							?>
							<div class="aaos-mtable__band">
							<button type="button" class="aaos-mtable__toggle"
								data-aaos-matrix-toggle="<?php echo esc_attr( (string) $ai_agent_os_slug ); ?>"
								aria-expanded="<?php echo $ai_agent_os_open ? 'true' : 'false'; ?>">
								<span class="aaos-mtable__chev" aria-hidden="true">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
								</span>
								<?php
								/*
								 * The label alone. It used to be run through a
								 * "%s section" template, which produced "SITE
								 * SECTION" and "USER SECTION" — the word added
								 * to labels that already read as one, in a
								 * heading row that is visibly a section. The
								 * template only ever made sense for a plugin
								 * name like WooCommerce, and not much even then.
								 */
								?>
								<span class="aaos-mtable__sectionname">
									<?php echo esc_html( (string) $ai_agent_os_group['label'] ); ?>
								</span>

								<?php if ( ! empty( $ai_agent_os_group['subtitle'] ) ) : ?>
									<span class="aaos-mtable__sectionsub">
										&middot; <?php echo esc_html( (string) $ai_agent_os_group['subtitle'] ); ?>
									</span>
								<?php endif; ?>
							</button>

							<?php
							/*
							 * The tally sits beside the controls that change it,
							 * not tucked behind the section name. It was inside
							 * the toggle, which put "0 of 7 granted" at the end
							 * of a label and the buttons that grant those seven
							 * somewhere else entirely.
							 */
							?>
							<span class="aaos-mtable__tally<?php echo $ai_agent_os_granted > 0 ? ' is-granted' : ''; ?>">
								<?php
								printf(
									/* translators: 1: number of abilities granted, 2: number of abilities in the section. */
									esc_html__( '%1$d of %2$d granted', 'zorem-ai-agent-connector' ),
									(int) $ai_agent_os_granted,
									(int) $ai_agent_os_total
								);
								?>
							</span>

							<?php
							/*
							 * Bulk controls per section.
							 *
							 * Granting a role by hand meant clicking through
							 * every ability in a group one at a time, which is
							 * how somebody ends up granting a whole section
							 * because the alternative was tedious. Three
							 * explicit choices are safer than a tired hand:
							 * "All read" exists so the common case — let it look
							 * at everything, change nothing — does not require
							 * picking Write anywhere.
							 *
							 * Outside the toggle button, not inside it: a button
							 * within a button is invalid markup, and clicking
							 * "Grant all" would also collapse the section it had
							 * just filled in.
							 */
							?>
							<span class="aaos-mtable__bulk">
								<button type="button" class="aaos-bulk" data-aaos-bulk="write" data-aaos-bulk-group="<?php echo esc_attr( (string) $ai_agent_os_slug ); ?>">
									<?php esc_html_e( 'Grant all', 'zorem-ai-agent-connector' ); ?>
								</button>
								<button type="button" class="aaos-bulk" data-aaos-bulk="read" data-aaos-bulk-group="<?php echo esc_attr( (string) $ai_agent_os_slug ); ?>">
									<?php esc_html_e( 'All read', 'zorem-ai-agent-connector' ); ?>
								</button>
								<button type="button" class="aaos-bulk" data-aaos-bulk="none" data-aaos-bulk-group="<?php echo esc_attr( (string) $ai_agent_os_slug ); ?>">
									<?php esc_html_e( 'Revoke', 'zorem-ai-agent-connector' ); ?>
								</button>
							</span>
							</div>
						</td>
					</tr>

					<?php
					foreach ( (array) $ai_agent_os_group['abilities'] as $ai_agent_os_ability ) :
						$ai_agent_os_name  = (string) $ai_agent_os_ability['name'];
						$ai_agent_os_risk  = (string) ( $ai_agent_os_ability['risk'] ?? 'write' );
						$ai_agent_os_level = (string) ( $ai_agent_os_grants[ $ai_agent_os_name ] ?? 'none' );
						$ai_agent_os_infer = ! empty( $ai_agent_os_ability['risk_inferred'] );
						$ai_agent_os_label = (string) ( $ai_agent_os_ability['label'] ?? $ai_agent_os_name );
						$ai_agent_os_rlock = ( 'destructive' === $ai_agent_os_risk && $ai_agent_os_locked );
						?>
						<?php
						/*
						 * Hidden server-side rather than by script on load, so
						 * a closed section is never briefly drawn open. On this
						 * screen that flash would be the height of the whole
						 * matrix collapsing under the pointer.
						 */
						?>
						<tr data-aaos-ability="<?php echo esc_attr( $ai_agent_os_name ); ?>"
							data-aaos-group="<?php echo esc_attr( (string) $ai_agent_os_slug ); ?>"
							data-aaos-search="<?php echo esc_attr( strtolower( $ai_agent_os_name . ' ' . $ai_agent_os_label ) ); ?>"
							<?php echo $ai_agent_os_open ? '' : 'hidden'; ?>>

							<td class="aaos-mtable__ability">
								<div class="aaos-mtable__head">
									<span class="aaos-mtable__name"><?php echo esc_html( $ai_agent_os_label ); ?></span>

									<?php if ( ! empty( $ai_agent_os_ability['caps_unknown'] ) ) : ?>
										<span class="aaos-flag"
											title="<?php esc_attr_e( 'This plugin does not declare which WordPress capabilities its ability needs, so this plugin lends the ones it asks for, for each call only. Capabilities that would let it change users, plugins or site code are never lent, and a call that needs one is refused.', 'zorem-ai-agent-connector' ); ?>">
											<?php esc_html_e( 'Capabilities lent automatically', 'zorem-ai-agent-connector' ); ?>
										</span>
									<?php endif; ?>
								</div>

								<code class="aaos-mtable__slug"><?php echo esc_html( $ai_agent_os_name ); ?></code>

								<?php if ( ! empty( $ai_agent_os_ability['description'] ) ) : ?>
									<p class="aaos-mtable__desc"><?php echo esc_html( (string) $ai_agent_os_ability['description'] ); ?></p>
								<?php endif; ?>
							</td>

							<td class="aaos-mtable__risk">
								<?php
								/*
								 * The asterisk's explanation reaches a keyboard
								 * and a screen reader, not only a mouse.
								 *
								 * It was a native `title`, which appears on
								 * hover and nowhere else — invisible on touch,
								 * unreachable by keyboard, and unannounced. The
								 * text explains why an ability was classified
								 * Write without the plugin saying so, which is
								 * exactly the sort of thing somebody granting
								 * permissions needs to be able to read.
								 *
								 * `tabindex` and `role="note"` make it focusable
								 * and announced; `title` stays for the hover
								 * everyone already expects.
								 */
								?>
								<span class="aaos-risk aaos-risk--<?php echo esc_attr( $ai_agent_os_risk ); ?>"
									<?php if ( $ai_agent_os_infer ) : ?>
									tabindex="0"
									role="note"
									aria-label="<?php esc_attr_e( 'Risk classified from the ability name, because the plugin does not declare it. Unclassifiable abilities default to Write, never Read.', 'zorem-ai-agent-connector' ); ?>"
									<?php endif; ?>
									<?php if ( $ai_agent_os_infer ) : ?>
										title="<?php esc_attr_e( 'This plugin does not declare how risky this ability is, so it was classified from its name. Unclassifiable abilities default to Write, never Read.', 'zorem-ai-agent-connector' ); ?>"
									<?php endif; ?>>
									<?php echo esc_html( $ai_agent_os_risk_labels[ $ai_agent_os_risk ] ?? strtoupper( $ai_agent_os_risk ) ); ?><?php echo $ai_agent_os_infer ? '*' : ''; ?>
								</span>
							</td>

							<td class="aaos-atable__actions">
								<?php
								/*
								 * A read-only ability is not offered Write.
								 *
								 * `RiskClassifier::required_level()` treats read
								 * as sufficient for a READ ability, so granting
								 * Write was never a hole — it stored a level
								 * that means nothing and then displayed it back
								 * as though it did. "This agent may write
								 * environment info" describes a capability that
								 * does not exist.
								 *
								 * Not offering it is better than accepting and
								 * silently rewriting it: a control that changes
								 * your answer after you give it is its own kind
								 * of dishonest.
								 */
								$ai_agent_os_offer = 'read' === $ai_agent_os_risk
									? array_intersect_key(
										$ai_agent_os_levels,
										array(
											'none' => 1,
											'read' => 1,
										)
									)
									: $ai_agent_os_levels;
								?>
								<div class="aaos-grant">
									<div class="aaos-seg<?php echo $ai_agent_os_rlock ? ' is-locked' : ''; ?>">
										<?php foreach ( $ai_agent_os_offer as $ai_agent_os_value => $ai_agent_os_text ) : ?>
											<?php $ai_agent_os_dis = ( $ai_agent_os_rlock && 'none' !== $ai_agent_os_value ); ?>
											<button type="button"
												class="aaos-seg__item<?php echo ( $ai_agent_os_value === $ai_agent_os_level ) ? ' is-active' : ''; ?>"
												data-aaos-level="<?php echo esc_attr( $ai_agent_os_value ); ?>"
												<?php disabled( $ai_agent_os_dis, true ); ?>>
												<?php if ( $ai_agent_os_dis ) : ?>
													<?php \Zorem\UI\icon( 'lock' ); ?>
												<?php endif; ?>
												<?php echo esc_html( $ai_agent_os_text ); ?>
											</button>
										<?php endforeach; ?>
									</div>

									<?php if ( $ai_agent_os_rlock ) : ?>
										<span class="aaos-grant__note">
											<?php esc_html_e( 'Destructive abilities are off for this agent. Turn them on in the Connection tab, under Access.', 'zorem-ai-agent-connector' ); ?>
										</span>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>

				<?php endforeach; ?>

				<tr class="aaos-atable__noresults" data-aaos-matrix-empty hidden>
					<td colspan="3"><?php esc_html_e( 'No ability matches that search.', 'zorem-ai-agent-connector' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
