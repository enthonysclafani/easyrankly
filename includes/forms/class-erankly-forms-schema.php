<?php
/** Published form blocks are the only authority for accepted submission fields. */
defined( 'ABSPATH' ) || exit;

final class ERankly_Forms_Schema {
	public const TYPES = array( 'text', 'email', 'tel', 'url', 'textarea', 'select', 'radio', 'checkboxes', 'consent', 'newsletter_consent' );
	public const AUTOCOMPLETE = array( '', 'off', 'name', 'given-name', 'family-name', 'email', 'tel', 'organization', 'url', 'street-address', 'postal-code', 'address-level2', 'country-name' );

	public static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value );
	}
	public static function cut( string $value, int $limit ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit, 'UTF-8' );
		}
		preg_match_all( '/./us', $value, $chars );
		return implode( '', array_slice( $chars[0], 0, $limit ) );
	}
	public static function limit( string $type ): int {
		return array( 'email' => 254, 'tel' => 40, 'url' => 2048, 'textarea' => 5000 )[ $type ] ?? 200;
	}
	public static function fields( string $content ) {
		$fields = array();
		$error = null;
		$walk = static function ( array $blocks, int $depth = 0 ) use ( &$walk, &$fields, &$error ): void {
			if ( $depth > 32 ) {
				$error = true;
				return;
			}
			foreach ( $blocks as $block ) {
				if ( 'easyrankly/form-field' === $block['blockName'] ) {
					$a = (array) $block['attrs'];
					$type = $a['type'] ?? 'text';
					if ( ! in_array( $type, self::TYPES, true ) || count( $fields ) >= 30 ) {
						$error = true;
						continue;
					}
					$label = self::cut( sanitize_text_field( $a['label'] ?? '' ), 200 );
					$name = sanitize_key( $a['name'] ?? sanitize_title( $label ) );
					$name = substr( $name, 0, 80 );
					if ( '' === $name ) {
						$name = 'field_' . ( count( $fields ) + 1 );
					}
					if ( isset( $fields[ $name ] ) || 0 === strpos( $name, 'erankly_hp_' ) ) {
						$error = true;
						continue;
					}
					$options = array();
					foreach ( array_slice( (array) ( $a['options'] ?? array() ), 0, 50 ) as $option ) {
						if ( is_string( $option ) && '' !== trim( $option ) ) {
							$options[] = self::cut( sanitize_text_field( $option ), 200 );
						}
					}
					$autocomplete = $a['autocomplete'] ?? '';
					$fields[ $name ] = array(
						'name' => $name, 'type' => $type, 'label' => $label ?: $name,
						'required' => ! empty( $a['required'] ),
						'placeholder' => self::cut( sanitize_text_field( $a['placeholder'] ?? '' ), 200 ),
						'help' => self::cut( sanitize_text_field( $a['help'] ?? '' ), 500 ),
						'autocomplete' => in_array( $autocomplete, self::AUTOCOMPLETE, true ) ? $autocomplete : '',
						'options' => array_values( array_unique( $options ) ),
					);
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ), $depth + 1 );
			}
		};
		$walk( parse_blocks( $content ) );
		return $error || ! $fields ? new WP_Error( 'invalid_form', __( 'This form needs to be checked by the site administrator.', 'easyrankly' ), array( 'status' => 503 ) ) : $fields;
	}

	public static function validate( array $fields, array $input ): array {
		$values = $errors = array();
		foreach ( $fields as $name => $field ) {
			$value = $input[ $name ] ?? ( 'checkboxes' === $field['type'] ? array() : '' );
			$valid = true;
			if ( 'checkboxes' === $field['type'] ) {
				$valid = is_array( $value ) && count( $value ) <= 50;
				$value = $valid ? array_values( $value ) : array();
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || ! in_array( $item, $field['options'], true ) ) { $valid = false; }
				}
				$value = $valid ? array_values( array_unique( $value ) ) : array();
			} elseif ( in_array( $field['type'], array( 'consent', 'newsletter_consent' ), true ) ) {
				$valid = in_array( $value, array( '', '1', true, false, 1, 0 ), true );
				$value = in_array( $value, array( '1', true, 1 ), true ) ? '1' : '';
			} else {
				$valid = is_string( $value );
				$value = $valid ? trim( $value ) : '';
				$valid = $valid && self::length( $value ) <= self::limit( $field['type'] );
				if ( '' !== $value ) {
					switch ( $field['type'] ) {
						case 'email': $valid = $valid && (bool) is_email( $value ); break;
						case 'url': $valid = $valid && (bool) filter_var( $value, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ); break;
						case 'tel': $valid = $valid && (bool) preg_match( '/^[0-9 +().\/-]+$/D', $value ); break;
						case 'select': case 'radio': $valid = $valid && in_array( $value, $field['options'], true ); break;
					}
				}
				$value = 'textarea' === $field['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			if ( $field['required'] && ( '' === $value || array() === $value ) ) { $valid = false; }
			if ( ! $valid ) { $errors[ $name ] = __( 'Please check this field.', 'easyrankly' ); }
			$values[ $name ] = $value;
		}
		return array( 'values' => $values, 'errors' => $errors );
	}
}
