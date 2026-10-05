<?php
/** Global form appearance, shared by the settings preview, frontend and block editor. */
defined( 'ABSPATH' ) || exit;

final class ERankly_Forms_Style {
	/** A small set of controls for the existing CSS variables. */
	public static function fields(): array {
		return array(
			'style_border_width' => array( 'label' => __( 'Border width', 'easyrankly' ), 'default' => 1, 'variable' => '--erankly-form-border-width', 'unit' => 'px', 'max' => 12, 'step' => .1 ),
			'style_border_color' => array( 'label' => __( 'Border color', 'easyrankly' ), 'default' => '#cbd5e1', 'variable' => '--erankly-form-border' ),
			'style_radius' => array( 'label' => __( 'Field corner radius', 'easyrankly' ), 'default' => 8, 'variable' => '--erankly-form-radius', 'unit' => 'px', 'max' => 48, 'step' => .1 ),
			'style_button_radius' => array( 'label' => __( 'Button corner radius', 'easyrankly' ), 'default' => 8, 'variable' => '--erankly-form-button-radius', 'unit' => 'px', 'max' => 48, 'step' => .1 ),
			'style_background' => array( 'label' => __( 'Field background', 'easyrankly' ), 'default' => '#ffffff', 'variable' => '--erankly-form-background' ),
			'style_color' => array( 'label' => __( 'Field text color', 'easyrankly' ), 'default' => '#1f2937', 'variable' => '--erankly-form-color' ),
			'style_gap' => array( 'label' => __( 'Space between fields', 'easyrankly' ), 'default' => 20, 'variable' => '--erankly-form-gap', 'unit' => 'px', 'max' => 80, 'step' => .1 ),
			'style_padding_vertical' => array( 'label' => __( 'Vertical padding', 'easyrankly' ), 'default' => 12, 'variable' => '--erankly-form-padding-vertical', 'unit' => 'px', 'max' => 64, 'step' => .1 ),
			'style_padding_horizontal' => array( 'label' => __( 'Horizontal padding', 'easyrankly' ), 'default' => 14, 'variable' => '--erankly-form-padding-horizontal', 'unit' => 'px', 'max' => 64, 'step' => .1 ),
			'style_font_size' => array( 'label' => __( 'Text size', 'easyrankly' ), 'default' => 16, 'variable' => '--erankly-form-font-size', 'unit' => 'px', 'min' => 12, 'max' => 32, 'step' => 1 ),
			'style_accent' => array( 'label' => __( 'Button and focus color', 'easyrankly' ), 'default' => '#1f2937', 'variable' => '--erankly-form-accent' ),
			'style_button_color' => array( 'label' => __( 'Button text color', 'easyrankly' ), 'default' => '#ffffff', 'variable' => '--erankly-form-button-color' ),
		);
	}
	public static function defaults(): array {
		return array_map( static function ( $field ) { return $field['default']; }, self::fields() );
	}
	/** Convert the previous relative measurements once, keeping existing choices. */
	public static function migrate( array $settings ): array {
		if ( 2 !== (int) ( $settings['style_units_version'] ?? 0 ) ) {
			foreach ( array( 'style_radius', 'style_gap', 'style_padding_vertical', 'style_padding_horizontal' ) as $key ) {
				if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) && is_numeric( $settings[ $key ] ) ) { $settings[ $key ] = round( (float) $settings[ $key ] * 16, 1 ); }
			}
		}
		// Keep the existing button appearance until its new control is changed.
		if ( ! array_key_exists( 'style_button_radius', $settings ) && isset( $settings['style_radius'] ) ) { $settings['style_button_radius'] = $settings['style_radius']; }
		$settings['style_units_version'] = 2;
		return $settings;
	}
	/** Reject invalid CSS values before any settings (including delivery settings) are saved. */
	public static function sanitize( array $input, array $settings ) {
		foreach ( self::fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) { continue; }
			$value = self::value( $input[ $key ], $field );
			if ( null === $value ) {
				return new WP_Error( 'invalid_form_style', sprintf( __( 'Please check %s.', 'easyrankly' ), $field['label'] ), array( 'status' => 400 ) );
			}
			$settings[ $key ] = $value;
		}
		return $settings;
	}
	/** Validate stored values too: imports or other plugins must never inject CSS. */
	private static function value( $value, array $field ) {
		if ( ! isset( $field['unit'] ) ) {
			return is_string( $value ) && preg_match( '/^#[0-9a-f]{6}$/iD', $value ) ? strtolower( $value ) : null;
		}
		if ( ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[0-9]{1,2}(?:\.[0-9]{1,3})?$/D', (string) $value ) ) { return null; }
		$number = (float) $value;
		if ( $number < ( $field['min'] ?? 0 ) || $number > $field['max'] || abs( $number / $field['step'] - round( $number / $field['step'] ) ) > .00001 ) { return null; }
		return 1 === $field['step'] ? (int) $number : $number;
	}
	public static function values( array $settings ): array {
		$values = array();
		foreach ( self::fields() as $key => $field ) { $values[ $key ] = self::value( $settings[ $key ] ?? $field['default'], $field ) ?? $field['default']; }
		return $values;
	}
	/** This is the only source of form appearance values, including the defaults. */
	public static function css( array $settings ): string {
		$values = self::values( $settings );
		$css = '';
		foreach ( self::fields() as $key => $field ) {
			$css .= $field['variable'] . ':' . $values[ $key ] . ( $field['unit'] ?? '' ) . ';';
		}
		return ':is(.wp-block-easyrankly-contact-form,.erankly-form-field-editor){' . $css . '}';
	}
}
