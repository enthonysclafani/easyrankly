<?php
/**
 * Site title and tagline of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * On the pages of a language, the site title and tagline are those of the language.
 *
 * Filtering the options covers every reader at once: get_bloginfo(), the site title and
 * tagline blocks, feeds, title templates, schema and Open Graph. A language without its own
 * text keeps the one of Settings > General. Admin, REST and the other non-frontend requests
 * read the stored options, so Settings > General always edits the real values.
 */
final class SiteIdentity {

	/**
	 * Hooks the two options.
	 */
	public function register(): void {
		add_filter( 'option_blogname', array( $this, 'filter_title' ) );
		add_filter( 'option_blogdescription', array( $this, 'filter_tagline' ) );
	}

	/**
	 * Site title in the language being viewed.
	 *
	 * @param mixed $value Stored site title.
	 * @return mixed
	 */
	public function filter_title( $value ) {
		return $this->translate( $value, 'site_title' );
	}

	/**
	 * Tagline in the language being viewed.
	 *
	 * @param mixed $value Stored tagline.
	 * @return mixed
	 */
	public function filter_tagline( $value ) {
		return $this->translate( $value, 'tagline' );
	}

	/**
	 * Text of the language being viewed, escaped like the core stores these options.
	 *
	 * @param mixed  $value Stored value.
	 * @param string $key   `site_title` or `tagline`.
	 * @return mixed
	 */
	private function translate( $value, string $key ) {
		if ( ! Languages::enabled() || ! Routing::is_frontend() ) {
			return $value;
		}

		$text = Languages::all()[ Routing::current() ][ $key ] ?? '';

		// sanitize_option() stores blogname and blogdescription with esc_html(): readers expect that.
		return '' === $text ? $value : esc_html( $text );
	}
}
