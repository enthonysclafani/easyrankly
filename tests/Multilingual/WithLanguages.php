<?php
/**
 * Languages for the tests.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Settings\Settings;

/**
 * Stores the languages of a test by slug, with their usual locale and name.
 */
trait WithLanguages {

	/**
	 * Saves the languages, the first one as default, as the only settings.
	 *
	 * @param string ...$slugs Language slugs among it, en, fr and de.
	 */
	private function set_languages( string ...$slugs ): void {
		$known     = array(
			'it' => array(
				'locale' => 'it_IT',
				'name'   => 'Italiano',
			),
			'en' => array(
				'locale' => 'en_US',
				'name'   => 'English',
			),
			'fr' => array(
				'locale' => 'fr_FR',
				'name'   => 'Français',
			),
			'de' => array(
				'locale' => 'de_DE',
				'name'   => 'Deutsch',
			),
		);
		$languages = array();
		foreach ( $slugs as $slug ) {
			$languages[ $slug ] = $known[ $slug ];
		}

		update_option( Settings::OPTION, array( 'languages' => $languages ) );
	}
}
