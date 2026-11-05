<?php
/**
 * One field definition in, one canonical ZUI row out.
 *
 * Every later milestone consumes this, so the shape of a field definition is
 * the most load-bearing decision in the migration. It is deliberately small:
 * eleven keys, none of them optional-but-really-required, and no key whose
 * meaning depends on another key's value.
 *
 * ```php
 * array(
 *     'type'     => 'toggle',        // required — see render_field()
 *     'title'    => 'Enable X',      // required — the row label
 *     'desc'     => 'What it does.', // optional — the line under the label
 *     'help'     => 'Longer text.',  // optional — hint under the control
 *     'tooltip'  => 'Why it exists.',// optional — the (i) bubble on the label
 *     'value'    => true,            // required — current value, already resolved
 *     'options'  => array( … ),      // select / radio / multi only
 *     'min'      => 7,               // number only
 *     'max'      => 365,             // number only
 *     'disabled' => false,           // optional
 *     'class'    => '',              // optional — extra class on the row
 * )
 * ```
 *
 * ## Why `value` is passed in rather than read here
 *
 * A renderer that reaches for `get_option()` decides *where* a value lives,
 * which makes it impossible to render a field whose value comes from somewhere
 * else — a service, a computed default, a REST payload. A renderer that reads
 * settings itself cannot; this one is handed the answer. The caller already knows.
 *
 * ## Why no `show` key
 *
 * A renderer that skips a field unless `show` is truthy means every definition
 * carries `'show' => true`, and a forgotten one silently disappears.
 * Here, a field in the array is a field on the screen. Conditional fields are
 * the caller's `if`.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_render_fields' ) ) {
	/**
	 * Render an ordered map of field id => definition.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions.
	 */
	function ai_agent_os_render_fields( array $fields ): void {
		foreach ( $fields as $id => $field ) {
			ai_agent_os_render_field( (string) $id, $field );
		}
	}
}

if ( ! function_exists( 'ai_agent_os_render_field' ) ) {
	/**
	 * Render one field as a canonical `.zui-row`.
	 *
	 * Supported types:
	 *   toggle    — on/off switch, inline row
	 *   checkbox  — square box, inline row
	 *   text      — single-line input, stacked row
	 *   number    — numeric input with min/max, stacked row
	 *   password  — masked input, stacked row
	 *   select    — dropdown, stacked row
	 *   radio     — radio cards, stacked row
	 *   readonly  — a value with no control, stacked row
	 *
	 * Anything else renders nothing rather than guessing. A field type nobody
	 * implemented should be a blank space somebody notices, not a text input
	 * silently accepting the wrong kind of value.
	 *
	 * @param string               $id    Field id; becomes the input name.
	 * @param array<string, mixed> $field Field definition.
	 */
	function ai_agent_os_render_field( string $id, array $field ): void {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		$inline = in_array( $type, array( 'toggle', 'checkbox' ), true );

		$row_class = 'zui-row';

		if ( $inline ) {
			$row_class .= ' zui-row--inline';
		}

		if ( ! empty( $field['class'] ) ) {
			$row_class .= ' ' . $field['class'];
		}
		?>
		<?php
		/*
		 * The visible label is tied to the control programmatically.
		 *
		 * It is a `<span>` rather than a `<label for>` because the row head
		 * also carries a description and a tooltip, and wrapping those in a
		 * label makes a screen reader read the whole paragraph as the field's
		 * name. `aria-labelledby` names the control with the heading alone, and
		 * works for every control type here — a toggle wrapped in its own
		 * label, a bare number input, a select.
		 *
		 * Without this the toggles announced as "checkbox, unlabeled": the
		 * wrapping `<label class="zui-toggle">` contains only the track and
		 * thumb spans, so there is no text inside it to name anything.
		 */
		$label_id             = $id . '-label';
		$field['aria_labels'] = $label_id;
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>">
			<div class="zui-row__head">
				<span class="zui-row__label" id="<?php echo esc_attr( $label_id ); ?>">
					<?php echo esc_html( (string) ( $field['title'] ?? '' ) ); ?>
					<?php ai_agent_os_render_tooltip( $field ); ?>
				</span>
				<?php if ( ! empty( $field['desc'] ) ) : ?>
					<p class="zui-row__desc"><?php echo esc_html( (string) $field['desc'] ); ?></p>
				<?php endif; ?>
			</div>
			<div class="zui-row__control">
				<?php
				ai_agent_os_render_control( $id, $type, $field );

				if ( ! empty( $field['help'] ) ) :
					?>
					<p class="zui-row__hint"><?php echo esc_html( (string) $field['help'] ); ?></p>
					<?php
				endif;
				?>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'ai_agent_os_render_control' ) ) {
	/**
	 * The control itself, without the row chrome.
	 *
	 * Split out so a caller that needs a bare control — inside a table cell, a
	 * modal field, a filter bar — can have one without faking a row around it.
	 *
	 * @param string               $id    Field id.
	 * @param string               $type  Field type.
	 * @param array<string, mixed> $field Field definition.
	 */
	function ai_agent_os_render_control( string $id, string $type, array $field ): void {
		$value    = $field['value'] ?? '';
		$disabled = ! empty( $field['disabled'] );

		/*
		 * Set by `ai_agent_os_render_field()`. Absent when a caller renders a
		 * bare control with no row around it — in that case the caller owns the
		 * labelling, and emitting a reference to an element that does not exist
		 * would be worse than emitting nothing.
		 */
		$labelled_by = empty( $field['aria_labels'] ) ? '' : (string) $field['aria_labels'];

		switch ( $type ) {

			case 'toggle':
			case 'checkbox':
				$is_toggle = ( 'toggle' === $type );
				?>
				<label class="<?php echo esc_attr( $is_toggle ? 'zui-toggle' : 'zui-checkbox' ); ?>">
					<?php
					/*
					 * The paired hidden input is what makes "off" a value rather
					 * than an absence. Without it an unchecked box sends nothing
					 * and a partial save cannot tell "the user turned this off"
					 * from "this field was not on the form".
					 */
					?>
					<input type="hidden" name="<?php echo esc_attr( $id ); ?>" value="0">
					<input type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $id ); ?>"
						value="1"
						class="<?php echo esc_attr( $is_toggle ? 'zui-toggle__input' : 'zui-checkbox__input' ); ?>"
						<?php
						if ( '' !== $labelled_by ) {
							printf( ' aria-labelledby="%s"', esc_attr( $labelled_by ) );
						}
						?>
						<?php checked( (bool) $value, true ); ?>
						<?php disabled( $disabled, true ); ?>>
					<?php if ( $is_toggle ) : ?>
						<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
					<?php else : ?>
						<span class="zui-checkbox__box"></span>
					<?php endif; ?>
				</label>
				<?php
				break;

			case 'text':
			case 'password':
			case 'date':
				/*
				 * `date` is the one field type Z3 needed that the guide's
				 * component vocabulary does not name. It is a native input with
				 * ZUI's `.zui-input` class and nothing else — the library styles
				 * the box, the browser supplies the picker. Recorded in
				 * ZUI-USAGE.md; no new component was invented for it.
				 */
				?>
				<input type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					class="zui-input"
					value="<?php echo esc_attr( (string) $value ); ?>"
					<?php echo isset( $field['placeholder'] ) ? 'placeholder="' . esc_attr( (string) $field['placeholder'] ) . '"' : ''; ?>
					<?php
					if ( '' !== $labelled_by ) {
						printf( ' aria-labelledby="%s"', esc_attr( $labelled_by ) );
					}
					?>
					<?php disabled( $disabled, true ); ?>>
				<?php
				break;

			case 'number':
				?>
				<input type="number"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					class="zui-input"
					value="<?php echo esc_attr( (string) $value ); ?>"
					<?php echo isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : ''; ?>
					<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : ''; ?>
					step="1"
					<?php
					if ( '' !== $labelled_by ) {
						printf( ' aria-labelledby="%s"', esc_attr( $labelled_by ) );
					}
					?>
					<?php disabled( $disabled, true ); ?>>
				<?php
				break;

			case 'select':
				?>
				<div class="zui-select-wrap">
					<select id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $id ); ?>"
						class="zui-select"
						<?php
						if ( '' !== $labelled_by ) {
							printf( ' aria-labelledby="%s"', esc_attr( $labelled_by ) );
						}
						?>
						<?php disabled( $disabled, true ); ?>>
						<?php foreach ( (array) ( $field['options'] ?? array() ) as $option_value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>>
								<?php echo esc_html( (string) $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="zui-select-chevron" aria-hidden="true"><?php \Zorem\UI\icon( 'chevron-down' ); ?></span>
				</div>
				<?php
				break;

			case 'radio':
				?>
				<div class="zui-radio-cards">
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $option_value => $option ) : ?>
						<?php
						$option_label = is_array( $option ) ? ( $option['label'] ?? '' ) : $option;
						$option_desc  = is_array( $option ) ? ( $option['desc'] ?? '' ) : '';
						?>
						<label class="zui-radio-card<?php echo ( (string) $value === (string) $option_value ) ? ' is-active' : ''; ?>">
							<input type="radio"
								name="<?php echo esc_attr( $id ); ?>"
								value="<?php echo esc_attr( (string) $option_value ); ?>"
								class="zui-radio-card__input"
								<?php checked( (string) $value, (string) $option_value ); ?>
								<?php disabled( $disabled, true ); ?>>
							<span class="zui-radio-card__body">
								<span class="zui-radio-card__label"><?php echo esc_html( (string) $option_label ); ?></span>
								<?php if ( '' !== $option_desc ) : ?>
									<span class="zui-radio-card__desc"><?php echo esc_html( (string) $option_desc ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<?php
				break;

			case 'readonly':
				?>
				<span class="aaos-readonly-value"><?php echo esc_html( (string) $value ); ?></span>
				<?php
				break;
		}
	}
}

if ( ! function_exists( 'ai_agent_os_render_tooltip' ) ) {
	/**
	 * The (i) bubble beside a label.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	function ai_agent_os_render_tooltip( array $field ): void {
		if ( empty( $field['tooltip'] ) ) {
			return;
		}
		?>
		<span class="zui-tooltip" tabindex="0" role="img" aria-label="<?php echo esc_attr( (string) $field['tooltip'] ); ?>">
			<?php \Zorem\UI\icon( 'help-circle' ); ?>
			<span class="zui-tooltip__bubble">
				<?php echo esc_html( (string) $field['tooltip'] ); ?>
				<span class="zui-tooltip__arrow"></span>
			</span>
		</span>
		<?php
	}
}

if ( ! function_exists( 'ai_agent_os_section_header' ) ) {
	/**
	 * A section's title bar, with the save button when the section can save.
	 *
	 * @param string $title Section title.
	 * @param string $sub   Subtitle.
	 * @param bool   $save  Whether to render a save button.
	 * @param string $icon  ZUI icon name for the header tile, or empty for none.
	 */
	function ai_agent_os_section_header( string $title, string $sub = '', bool $save = false, string $icon = '' ): void {
		?>
		<div class="zui-section-header">
			<?php
			/*
			 * `__main` wraps the icon tile and the text, because that is what
			 * `section-header.css` styles as the left cluster. Without an icon
			 * the wrapper is still correct and costs nothing — with one, leaving
			 * it out would put a 64px tile and the title in two separate flex
			 * children of a `space-between` row, i.e. at opposite ends.
			 */
			?>
			<div class="zui-section-header__main">
				<?php if ( '' !== $icon ) : ?>
					<span class="zui-section-header__icon" aria-hidden="true"><?php \Zorem\UI\icon( $icon ); ?></span>
				<?php endif; ?>

				<div class="zui-section-header__text">
					<h2 class="zui-section-header__title"><?php echo esc_html( $title ); ?></h2>
					<?php if ( '' !== $sub ) : ?>
						<p class="zui-section-header__sub"><?php echo esc_html( $sub ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $save ) : ?>
				<div class="zui-section-header__actions">
					<span class="zui-savebtn-wrap">
						<?php
						/*
						 * Disabled until something changes. The one-save model
						 * means this button is the only way a setting is
						 * written, so its disabled state is the honest signal
						 * that there is nothing to write — not decoration.
						 */
						?>
						<button type="button" class="zui-savebtn" data-aaos-save disabled>
							<span class="zui-savebtn__label"><?php esc_html_e( 'Save changes', 'zorem-ai-agent-connector' ); ?></span>
							<span class="zui-savebtn__spinner"></span>
						</button>
					</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
