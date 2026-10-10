<?php
/**
 * Sections of the Languages settings page.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings\Admin;

use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Menus;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the languages table, the menus of each language and the help on theme texts.
 *
 * Fields::add() registers these sections; Form::languages() reads what they post.
 */
final class LanguageFields {

	/**
	 * The languages, one row each, plus an empty row to add one.
	 */
	public static function languages_table(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Add a second language to publish translations of your content. The first language is the default one: its addresses do not change. The others get their URL prefix, for example /en/.', 'easyrankly' )
		);

		$rows   = Languages::all();
		$rows[] = array(
			'locale'     => '',
			'name'       => '',
			'site_title' => '',
			'tagline'    => '',
		);
		// Empty fields use the site title and tagline of Settings > General: shown as placeholders.
		$general = array(
			'site_title' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			'tagline'    => wp_specialchars_decode( (string) get_option( 'blogdescription' ), ENT_QUOTES ),
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Order', 'easyrankly' ),
			__( 'Name', 'easyrankly' ),
			__( 'URL prefix', 'easyrankly' ),
			__( 'Locale', 'easyrankly' ),
			__( 'Site Title', 'easyrankly' ),
			__( 'Tagline', 'easyrankly' ),
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
			$fields = array(
				'name'       => array(
					'value'       => $language['name'],
					'maxlength'   => 50,
					'placeholder' => '',
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'Name of %s', 'easyrankly' ), $label ),
				),
				'slug'       => array(
					'value'       => $is_new ? '' : (string) $slug,
					'maxlength'   => 12,
					'placeholder' => '',
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'URL prefix of %s', 'easyrankly' ), $label ),
				),
				'locale'     => array(
					'value'       => $language['locale'],
					'maxlength'   => 20,
					'placeholder' => 'en_US',
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'Locale of %s', 'easyrankly' ), $label ),
				),
				'site_title' => array(
					'value'       => $language['site_title'],
					'maxlength'   => 200,
					'placeholder' => $general['site_title'],
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'Site title of %s', 'easyrankly' ), $label ),
				),
				'tagline'    => array(
					'value'       => $language['tagline'],
					'maxlength'   => 400,
					'placeholder' => $general['tagline'],
					/* translators: %s: language name. */
					'aria'        => sprintf( __( 'Tagline of %s', 'easyrankly' ), $label ),
				),
			);
			foreach ( $fields as $field => $input ) {
				printf(
					'<td><input type="text" name="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" aria-label="%5$s" /></td>',
					esc_attr( Fields::name( 'languages', (string) $index, $field ) ),
					esc_attr( $input['value'] ),
					(int) $input['maxlength'],
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
			esc_html__( 'The locale (for example en_US or it_IT) sets the language of the pages and their hreflang code. Content without a language belongs to the default language. The language with the lowest order is the default one: another language can take its place only once every content has its language.', 'easyrankly' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Changing a URL prefix moves the content, title templates and menus of the language to the new prefix; rename its template parts and synced patterns too (for example header-en). Removing a language deletes its title templates and menu choices, and its content is shown in the default language.', 'easyrankly' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Site title and tagline replace those of Settings > General on the pages of the language. Leave them empty to use the same ones in every language.', 'easyrankly' )
		);
	}

	/**
	 * Menus of each language: the language locations of classic themes live in Appearance > Menus;
	 * the navigation menus of block themes are chosen here, one per language.
	 */
	public static function menus_section(): void {
		$locations = array_filter(
			array_keys( get_registered_nav_menus() ),
			static fn( $location ): bool => ! str_contains( (string) $location, Menus::SEPARATOR )
		);
		if ( array() !== $locations ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Every menu location of the theme has one location per language. A language location without a menu shows the menu of the default language.', 'easyrankly' ),
				esc_url( admin_url( 'nav-menus.php?action=locations' ) ),
				esc_html__( 'Manage menu locations', 'easyrankly' )
			);
		}

		$navigations = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		if ( array() === $navigations ) {
			if ( array() === $locations ) {
				printf( '<p>%s</p>', esc_html__( 'The theme has no menu locations and the site has no navigation menus yet.', 'easyrankly' ) );
			}
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'Navigation blocks show, on the pages of a language, the navigation menu chosen for it. "Same menu" keeps the menu of the block.', 'easyrankly' )
		);

		$languages = array_diff_key( Languages::all(), array( Languages::default() => true ) );
		$current   = (array) Settings::value( Menus::SETTING );
		$titles    = array();
		foreach ( $navigations as $navigation ) {
			$titles[ $navigation->ID ] = '' !== $navigation->post_title ? $navigation->post_title : __( '(no title)', 'easyrankly' );
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Navigation menu', 'easyrankly' ) );
		foreach ( $languages as $language ) {
			printf( '<th scope="col">%s</th>', esc_html( $language['name'] ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $titles as $id => $title ) {
			printf( '<tr><th scope="row">%s</th>', esc_html( $title ) );
			foreach ( $languages as $slug => $language ) {
				$chosen = (int) ( ( (array) ( $current[ $slug ] ?? array() ) )[ $id ] ?? 0 );
				printf(
					'<td><select name="%1$s" aria-label="%2$s"><option value="">%3$s</option>',
					esc_attr( Fields::name( Menus::SETTING, $slug, (string) $id ) ),
					/* translators: 1: navigation menu title, 2: language name. */
					esc_attr( sprintf( __( '%1$s in %2$s', 'easyrankly' ), $title, $language['name'] ) ),
					esc_html__( 'Same menu', 'easyrankly' )
				);
				foreach ( $titles as $option => $option_title ) {
					if ( $option !== $id ) {
						printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $option, selected( $option, $chosen, false ), esc_html( $option_title ) );
					}
				}
				echo '</select></td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * How the texts of the theme get a version per language: a naming rule in block themes, nothing to save
	 * here; classic themes keep widgets and Customizer texts in every language.
	 */
	public static function theme_texts_section(): void {
		if ( ! wp_is_block_theme() ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Classic themes are not supported for theme texts: widgets and texts set in the Customizer show the same text in every language. Contents, menus, site title and tagline, and the texts the theme translates itself change with the language.', 'easyrankly' )
			);
			return;
		}

		$example = (string) array_key_first( array_diff_key( Languages::all(), array( Languages::default() => true ) ) );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: language URL prefix, e.g. "en". */
					__( 'To change a template part on the pages of a language, create a template part with the same name followed by a hyphen and the URL prefix of the language, for example header-%1$s for header. Without it, the pages of the language show the template part of the default language.', 'easyrankly' ),
					$example
				)
			)
		);
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: 1: language URL prefix in capitals, e.g. "EN". */
					__( 'Synced patterns work the same way: a synced pattern named "Banner - %1$s" replaces "Banner" on the pages of that language. Unsynced patterns become a copy where they are inserted: translate them in the page of each language, or move their text into a template part or a synced pattern.', 'easyrankly' ),
					strtoupper( $example )
				)
			),
			// Template parts and patterns share this screen of the Site Editor.
			esc_url( admin_url( 'site-editor.php?p=/pattern' ) ),
			esc_html__( 'Edit template parts and patterns', 'easyrankly' )
		);
	}
}
