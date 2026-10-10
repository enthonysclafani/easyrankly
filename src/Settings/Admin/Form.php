<?php
/**
 * Turns a submitted settings page into settings.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings\Admin;

use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Menus;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the fields of one settings page into the shape of the settings schema.
 *
 * The core options.php passes the posted `easyrankly_settings` array to update_option(). Each page
 * posts only its own fields and names itself in `_page`, so this returns only the settings
 * that page shows; Settings::sanitize() then validates them and keeps every other setting.
 * Unchecked boxes and removed rows are not posted: they are worked out here from what the
 * page shows. Values from REST never carry `_page` (the schema rejects unknown keys), so
 * they pass through untouched.
 */
final class Form {

	/**
	 * Converts a submitted page; any other value passes through.
	 *
	 * @param mixed $value Value given to update_option(), already unslashed by options.php.
	 * @return mixed
	 */
	public static function normalize( $value ) {
		if ( ! is_array( $value ) || ! array_key_exists( '_page', $value ) ) {
			return $value;
		}

		$page    = is_string( $value['_page'] ) ? $value['_page'] : '';
		$current = Settings::get();

		switch ( $page ) {
			case Page::SLUG:
				$settings = self::general( $value, $current );
				break;
			case Page::TITLES:
				$settings = self::titles( $value, $current );
				break;
			case Page::INDEXING:
				$settings = self::indexing( $value, $current );
				break;
			case Page::SCHEMA:
				$settings = self::schema( $value );
				break;
			case Page::LANGUAGES:
				$settings = self::languages( $value );
				break;
			default:
				$settings = array();
		}

		foreach ( $settings as $key => $setting ) {
			if ( ! Settings::is_valid( $key, $setting ) ) {
				self::error( $key, self::invalid_message( $key ) );
			}
		}

		return $settings;
	}

	/**
	 * General page: title separator and breadcrumb.
	 *
	 * @param array<mixed>         $value   Posted values.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	private static function general( array $value, array $current ): array {
		$settings = self::strings( $value, array( 'title_separator', 'breadcrumb_home_label' ) );

		// The post types shown on the page get the posted taxonomy, or none; the others keep theirs.
		$map    = (array) $current['breadcrumb_taxonomies'];
		$posted = is_array( $value['breadcrumb_taxonomies'] ?? null ) ? $value['breadcrumb_taxonomies'] : array();
		foreach ( array_keys( Fields::breadcrumb_types() ) as $type ) {
			$taxonomy = is_string( $posted[ $type ] ?? null ) ? $posted[ $type ] : '';
			if ( '' === $taxonomy ) {
				unset( $map[ $type ] );
			} else {
				$map[ $type ] = $taxonomy;
			}
		}
		$settings['breadcrumb_taxonomies'] = $map;

		return $settings;
	}

	/**
	 * Titles page: the templates of all languages, or of the language being edited.
	 *
	 * Generic contexts of all languages keep empty fields, which mean "no template" rather than
	 * the default. Elsewhere an empty context is dropped: it falls back to a more general template.
	 *
	 * @param array<mixed>         $value   Posted values.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	private static function titles( array $value, array $current ): array {
		$language = is_string( $value['_language'] ?? null ) ? $value['_language'] : '';
		if ( '' !== $language && ! Languages::exists( $language ) ) {
			return array();
		}

		$posted   = is_array( $value['templates'] ?? null ) ? $value['templates'] : array();
		$generic  = Fields::generic_contexts();
		$by_lang  = (array) $current['language_templates'];
		$edited   = '' === $language ? (array) $current['templates'] : (array) ( $by_lang[ $language ] ?? array() );
		$contexts = $generic + Fields::type_contexts();

		foreach ( array_keys( $contexts ) as $context ) {
			$fields   = is_array( $posted[ $context ] ?? null ) ? $posted[ $context ] : array();
			$template = array(
				'title'       => is_string( $fields['title'] ?? null ) ? $fields['title'] : '',
				'description' => is_string( $fields['description'] ?? null ) ? $fields['description'] : '',
			);

			if ( '' === $language && isset( $generic[ $context ] ) ) {
				$edited[ $context ] = $template;
			} elseif ( '' === $template['title'] && '' === $template['description'] ) {
				unset( $edited[ $context ] );
			} else {
				$edited[ $context ] = $template;
			}
		}

		if ( '' === $language ) {
			return array( 'templates' => $edited );
		}

		if ( array() === $edited ) {
			unset( $by_lang[ $language ] );
		} else {
			$by_lang[ $language ] = $edited;
		}

		return array( 'language_templates' => $by_lang );
	}

	/**
	 * Indexing page: noindex rules and extra robots.txt rules.
	 *
	 * @param array<mixed>         $value   Posted values.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>
	 */
	private static function indexing( array $value, array $current ): array {
		$settings = self::strings( $value, array( 'robots_txt' ) );

		// Rules for contexts the page does not show (a post type that is gone) stay as they are.
		$shown   = array_map( 'strval', array_keys( Fields::noindex_contexts() ) );
		$posted  = is_array( $value['noindex'] ?? null ) ? array_keys( array_filter( $value['noindex'] ) ) : array();
		$checked = array_intersect( $shown, array_map( 'strval', $posted ) );
		$kept    = array_diff( array_map( 'strval', (array) $current['noindex'] ), $shown );

		$settings['noindex'] = array_values( array_unique( array_merge( $kept, $checked ) ) );

		return $settings;
	}

	/**
	 * Schema and social page: identity, profiles and sharing defaults.
	 *
	 * @param array<mixed> $value Posted values.
	 * @return array<string, mixed>
	 */
	private static function schema( array $value ): array {
		$settings = self::strings( $value, array( 'identity_type', 'identity_name', 'x_username' ) );

		if ( isset( $settings['x_username'] ) ) {
			$settings['x_username'] = ltrim( trim( $settings['x_username'] ), '@' );
		}

		foreach ( Settings::IMAGES as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				$settings[ $key ] = absint( $value[ $key ] );
			}
		}

		if ( isset( $value['same_as'] ) && is_string( $value['same_as'] ) ) {
			$lines               = preg_split( '/\R/', $value['same_as'] );
			$lines               = array_map( 'trim', false === $lines ? array() : $lines );
			$settings['same_as'] = array_values( array_unique( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) ) );
		}

		return $settings;
	}

	/**
	 * Languages page: the rows in order, without removed and empty rows.
	 *
	 * A row that cannot be saved leaves the languages as they are, with an error that says why.
	 *
	 * @param array<mixed> $value Posted values.
	 * @return array<string, mixed>
	 */
	private static function languages( array $value ): array {
		$rows = array();

		foreach ( is_array( $value['languages'] ?? null ) ? $value['languages'] : array() as $index => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$slug   = strtolower( trim( is_string( $row['slug'] ?? null ) ? $row['slug'] : '' ) );
			$locale = trim( is_string( $row['locale'] ?? null ) ? $row['locale'] : '' );
			$name   = trim( is_string( $row['name'] ?? null ) ? $row['name'] : '' );
			$texts  = array();
			foreach ( array( 'site_title', 'tagline' ) as $key ) {
				$text = trim( is_string( $row[ $key ] ?? null ) ? $row[ $key ] : '' );
				if ( '' !== $text ) {
					$texts[ $key ] = $text;
				}
			}

			if ( '' === $slug && '' === $locale && '' === $name && array() === $texts ) {
				continue;
			}

			$rows[] = array(
				'order'  => is_numeric( $row['order'] ?? null ) ? (int) $row['order'] : PHP_INT_MAX,
				'index'  => (int) $index,
				'slug'   => $slug,
				'locale' => $locale,
				'name'   => $name,
				'texts'  => $texts,
			);
		}

		foreach ( $rows as $row ) {
			if ( 1 !== preg_match( '/' . Languages::SLUG_PATTERN . '/', $row['slug'] ) ) {
				return self::error( 'languages', __( 'Each URL prefix needs 2 or 3 lowercase letters, optionally followed by a hyphen and a region (for example en or pt-br).', 'easyrankly' ) );
			}
			if ( 1 !== preg_match( '/' . Languages::LOCALE_PATTERN . '/', $row['locale'] ) ) {
				return self::error( 'languages', __( 'Each language needs a locale such as en_US or it_IT.', 'easyrankly' ) );
			}
			if ( '' === $row['name'] ) {
				return self::error( 'languages', __( 'Each language needs a name.', 'easyrankly' ) );
			}
		}

		$slugs = array_column( $rows, 'slug' );
		if ( count( array_unique( $slugs ) ) !== count( $slugs ) ) {
			return self::error( 'languages', __( 'Each language needs a different URL prefix.', 'easyrankly' ) );
		}

		// Lowest order first; rows with the same order keep their position on the page.
		usort( $rows, static fn( array $a, array $b ): int => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] ) );

		$languages = array();
		foreach ( $rows as $row ) {
			// Site title and tagline only when set: empty means those of Settings > General.
			$languages[ $row['slug'] ] = array(
				'locale' => $row['locale'],
				'name'   => $row['name'],
			) + $row['texts'];
		}

		$settings = array( 'languages' => $languages );

		// Navigation menus are posted only when the page lists some: otherwise they stay as they are.
		if ( is_array( $value[ Menus::SETTING ] ?? null ) ) {
			$settings[ Menus::SETTING ] = self::navigation_menus( $value[ Menus::SETTING ], $languages );
		}

		return $settings;
	}

	/**
	 * Navigation menus chosen for each language other than the default; "Same menu" is not stored.
	 *
	 * @param array<mixed>         $posted    Posted language => navigation menu ID => chosen ID.
	 * @param array<string, mixed> $languages Languages being saved, default first.
	 * @return array<string, array<int, int>>
	 */
	private static function navigation_menus( array $posted, array $languages ): array {
		$default = array_key_first( $languages );
		$menus   = array();

		foreach ( $posted as $language => $chosen ) {
			if ( ! is_string( $language ) || $default === $language || ! isset( $languages[ $language ] ) || ! is_array( $chosen ) ) {
				continue;
			}
			foreach ( $chosen as $navigation => $target ) {
				$navigation = (int) $navigation;
				$target     = is_numeric( $target ) ? (int) $target : 0;
				if ( $navigation > 0 && $target > 0 && $navigation !== $target ) {
					$menus[ $language ][ $navigation ] = $target;
				}
			}
		}

		return $menus;
	}

	/**
	 * Posted string fields, among those named.
	 *
	 * @param array<mixed> $value Posted values.
	 * @param string[]     $keys  Setting keys.
	 * @return array<string, string>
	 */
	private static function strings( array $value, array $keys ): array {
		$strings = array();
		foreach ( $keys as $key ) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
				$strings[ $key ] = $value[ $key ];
			}
		}

		return $strings;
	}

	/**
	 * Message for a value the schema rejects.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function invalid_message( string $key ): string {
		/* translators: %s: name of a setting. */
		return sprintf( __( '%s: the value is not valid and was not saved.', 'easyrankly' ), Fields::label( $key ) );
	}

	/**
	 * Adds an error to the messages shown after saving.
	 *
	 * @param string $key     Setting key.
	 * @param string $message Message.
	 * @return array<string, mixed> Nothing to save.
	 */
	private static function error( string $key, string $message ): array {
		add_settings_error( Settings::OPTION, 'easyrankly_' . $key, $message );

		return array();
	}
}
