<?php
/**
 * Sections of the Languages settings page.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings\Admin;

use EasyRankly\Multilingual\Languages;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the languages table, the strings of each language and the help on theme texts.
 *
 * Fields::add() registers these sections; Form::languages() reads what they post.
 */
final class LanguageFields {

	/**
	 * The languages, one row each, plus an empty row to add one.
	 */
	public static function languages_table(): void {
		if ( ! wp_is_block_theme() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Multilingual needs a block theme: with a classic theme, menus, widgets and Customizer texts do not change with the language.', 'easyrankly' )
			);
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'The first language is the default one: its addresses do not change. The others get their URL prefix, e.g. /en/.', 'easyrankly' )
		);

		$rows   = Languages::all();
		$rows[] = array(
			'locale' => '',
			'name'   => '',
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Order', 'easyrankly' ),
			__( 'Name', 'easyrankly' ),
			__( 'URL prefix', 'easyrankly' ),
			__( 'Locale', 'easyrankly' ),
			__( 'Remove', 'easyrankly' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		$index = 0;
		foreach ( $rows as $slug => $language ) {
			$is_new = ! is_string( $slug );
			$label  = $is_new ? __( 'New language', 'easyrankly' ) : $language['name'];

			echo '<tr>';
			printf(
				'<td><input type="number" min="1" max="20" name="%1$s" value="%2$s" class="small-text" aria-label="%3$s" />',
				esc_attr( Fields::name( 'languages', (string) $index, 'order' ) ),
				esc_attr( (string) ( $index + 1 ) ),
				/* translators: %s: language name. */
				esc_attr( sprintf( __( 'Order of %s', 'easyrankly' ), $label ) )
			);
			// The prefix the row had: a new one renames the language instead of replacing it.
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" /></td>',
				esc_attr( Fields::name( 'languages', (string) $index, 'previous' ) ),
				esc_attr( $is_new ? '' : (string) $slug )
			);
			// Short values: the size attribute keeps the table narrow without CSS of ours.
			$fields = array(
				'name'   => array(
					'value'       => $language['name'],
					'maxlength'   => 50,
					'size'        => 15,
					'placeholder' => '',
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'Name of %s', 'easyrankly' ), $label ),
				),
				'slug'   => array(
					'value'       => $is_new ? '' : (string) $slug,
					'maxlength'   => 12,
					'size'        => 8,
					'placeholder' => '',
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'URL prefix of %s', 'easyrankly' ), $label ),
				),
				'locale' => array(
					'value'       => $language['locale'],
					'maxlength'   => 20,
					'size'        => 8,
					'placeholder' => 'en_US',
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'Locale of %s', 'easyrankly' ), $label ),
				),
			);
			foreach ( $fields as $field => $input ) {
				printf(
					'<td><input type="text" name="%1$s" value="%2$s" maxlength="%3$d" size="%4$d" placeholder="%5$s" aria-label="%6$s" /></td>',
					esc_attr( Fields::name( 'languages', (string) $index, $field ) ),
					esc_attr( $input['value'] ),
					(int) $input['maxlength'],
					(int) $input['size'],
					esc_attr( $input['placeholder'] ),
					esc_attr( $input['aria'] )
				);
			}
			echo '<td>';
			if ( ! $is_new ) {
				printf(
					'<input type="checkbox" name="%1$s" value="1" aria-label="%2$s" />',
					esc_attr( Fields::name( 'languages', (string) $index, 'remove' ) ),
					/* translators: %s: language name. */
					esc_attr( sprintf( __( 'Remove %s', 'easyrankly' ), $label ) )
				);
			}
			echo '</td></tr>';
			++$index;
		}

		echo '</tbody></table>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The locale sets the language of the pages and their hreflang. Content without a language belongs to the default one, so another language can come first only once all content has a language.', 'easyrankly' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Changing a URL prefix keeps content and templates; rename its template parts and synced patterns too (e.g. header-en). Removing a language deletes its templates and strings; its content is shown in the default language.', 'easyrankly' )
		);
	}

	/**
	 * The strings of each saved language, one row each, posted with the row of the languages table.
	 *
	 * A new language gets its strings once saved.
	 */
	public static function strings_table(): void {
		// Empty fields use the site title and tagline of Settings > General and the breadcrumb
		// label of the General page (or the WordPress one): shown as placeholders.
		$breadcrumb = (string) Settings::value( 'breadcrumb_home_label' );
		$general    = array(
			'site_title'            => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			'tagline'               => wp_specialchars_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES ),
			'breadcrumb_home_label' => '' !== $breadcrumb ? $breadcrumb : __( 'Home', 'easyrankly' ),
		);

		printf(
			'<p>%s</p>',
			esc_html__( 'Empty fields use the value shown.', 'easyrankly' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Language', 'easyrankly' ),
			__( 'Site Title', 'easyrankly' ),
			__( 'Tagline', 'easyrankly' ),
			__( 'Breadcrumb home label', 'easyrankly' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		// Same index as the row of the language in the languages table, which comes first there.
		$index = 0;
		foreach ( Languages::all() as $language ) {
			printf( '<tr><th scope="row">%s</th>', esc_html( $language['name'] ) );
			$fields = array(
				'site_title'            => array(
					'maxlength' => 200,
					'class'     => 'large-text',
					/* translators: %s: language name. */
					'aria'      => sprintf( __( 'Site title of %s', 'easyrankly' ), $language['name'] ),
				),
				'tagline'               => array(
					'maxlength' => 400,
					'class'     => 'large-text',
					/* translators: %s: language name. */
					'aria'      => sprintf( __( 'Tagline of %s', 'easyrankly' ), $language['name'] ),
				),
				'breadcrumb_home_label' => array(
					'maxlength' => 60,
					'class'     => '',
					/* translators: %s: language name. */
					'aria'      => sprintf( __( 'Breadcrumb home label of %s', 'easyrankly' ), $language['name'] ),
				),
			);
			foreach ( $fields as $field => $input ) {
				printf(
					'<td><input type="text" name="%1$s" value="%2$s" maxlength="%3$d" size="15" class="%4$s" placeholder="%5$s" aria-label="%6$s" /></td>',
					esc_attr( Fields::name( 'languages', (string) $index, $field ) ),
					esc_attr( $language[ $field ] ),
					(int) $input['maxlength'],
					esc_attr( $input['class'] ),
					esc_attr( $general[ $field ] ),
					esc_attr( $input['aria'] )
				);
			}
			echo '</tr>';
			++$index;
		}

		echo '</tbody></table>';
	}

	/**
	 * How the texts of the theme get a version per language: a naming rule, nothing to save here.
	 */
	public static function theme_texts_section(): void {
		$example = (string) array_key_first( array_diff_key( Languages::all(), array( Languages::default() => true ) ) );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: language URL prefix, e.g. "en". */
					__( 'A template part named with the URL prefix of a language, e.g. header-%1$s, replaces header on the pages of that language. Its navigation block gives the language its own menu.', 'easyrankly' ),
					$example
				)
			)
		);
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: 1: language URL prefix in capitals, e.g. "EN". */
					__( 'Same for synced patterns: "Banner - %1$s" replaces "Banner". Unsynced patterns are copies: translate them where they are inserted.', 'easyrankly' ),
					strtoupper( $example )
				)
			),
			// Template parts and patterns share this screen of the Site Editor.
			esc_url( admin_url( 'site-editor.php?p=/pattern' ) ),
			esc_html__( 'Edit template parts and patterns', 'easyrankly' )
		);
	}
}
