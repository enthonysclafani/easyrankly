<?php
/** Shared markup for simple settings fields; keeps the existing names and data-* hooks. */
defined( 'ABSPATH' ) || exit;

/**
 * @param array<string,mixed> $args Type, select options, control attributes, field class and variable picker groups/examples.
 */
function erankly_render_settings_field( string $key, string $label, string $value, array $args = array() ): void {
	$type        = $args['type'] ?? 'text';
	$is_textarea = 'textarea' === $type;
	$is_select   = 'select' === $type;
	$attributes  = array_merge(
		array(
			'id'    => 'erankly-' . str_replace( '_', '-', $key ),
			'class' => 'widefat' . ( $is_select ? ' erankly-field-full-width' : '' ),
			'name'  => ERANKLY_OPTION . '[' . $key . ']',
		),
		$is_textarea ? array( 'rows' => 3 ) : ( $is_select ? array() : array( 'type' => $type, 'value' => $value ) ),
		$args['attributes'] ?? array()
	);
	$has_variables = array_key_exists( 'variables', $args );
	?>
	<div class="<?php echo esc_attr( $args['field_class'] ?? 'erankly-field' ); ?>">
		<label for="<?php echo esc_attr( $attributes['id'] ); ?>"><?php echo esc_html( $label ); ?></label>
		<?php if ( $has_variables ) : ?><div class="erankly-variable-field" data-erankly-variable-field><?php endif; ?>
		<?php
		echo $is_textarea ? '<textarea' : ( $is_select ? '<select' : '<input' );
		erankly_render_settings_attributes( $attributes );
		echo '>';
		if ( $is_textarea ) {
			echo esc_textarea( $value ) . '</textarea>';
		} elseif ( $is_select ) {
			foreach ( $args['options'] as $option_value => $option_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $option_value ), selected( $value, (string) $option_value, false ), esc_html( $option_label ) );
			}
			echo '</select>';
		}
		?>
		<?php if ( $has_variables ) : ?>
			<?php erankly_render_variable_picker( $args['examples'] ?? array(), $args['variables'] ); ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

/** Prints escaped control attributes for the shared field renderers. */
function erankly_render_settings_attributes( array $attributes ): void {
	foreach ( $attributes as $attribute => $value ) {
		if ( false !== $value ) {
			echo ' ' . esc_attr( $attribute ) . ( true === $value ? '' : '="' . esc_attr( (string) $value ) . '"' );
		}
	}
}

/** Renders one or more toggles, optionally within an existing checkbox group. */
function erankly_render_settings_checkboxes( array $labels, array $values, array $args = array() ): void {
	$wrap = $args['wrap'] ?? true;
	if ( $wrap ) {
		echo '<div class="erankly-field erankly-checkboxes">';
	}
	foreach ( $labels as $key => $label ) {
		$attributes = array_merge(
			array( 'type' => 'checkbox', 'class' => 'erankly-toggle', 'name' => ( $args['name_prefix'] ?? ERANKLY_OPTION ) . '[' . $key . ']', 'value' => '1', 'checked' => '1' === (string) ( $values[ $key ] ?? false ), 'disabled' => ! empty( $args['disabled'] ) ),
			! empty( $args['linked'] ) ? array( 'data-erankly-linked-field' => $key ) : array()
		);
		echo '<label><input';
		erankly_render_settings_attributes( $attributes );
		echo '> ' . esc_html( $label ) . '</label>';
	}
	if ( $wrap ) {
		echo '</div>';
	}
}
