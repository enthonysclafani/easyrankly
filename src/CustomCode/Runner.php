<?php
/**
 * Prints and runs active snippets.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the snippets of each position from the autoloaded cache: no query.
 *
 * The page positions never run in the admin. The `everywhere` position runs PHP on every
 * request, admin, REST, AJAX, cron and WP-CLI included, like functions.php, but never on the
 * Custom code screen, so a broken snippet can always be fixed there.
 */
final class Runner {

	/**
	 * Hooks every position.
	 */
	public function register(): void {
		foreach ( CustomCode::POSITIONS as $position => $hook ) {
			add_action( $hook, fn() => $this->run( $position ) );
		}
	}

	/**
	 * Prints or runs the active snippets of a position, by priority.
	 *
	 * @param string $position Position key.
	 */
	public function run( string $position ): void {
		$everywhere = CustomCode::EVERYWHERE === $position;
		if ( CustomCode::safe_mode() || ( $everywhere ? self::on_custom_code_screen() : is_admin() ) ) {
			return;
		}

		// Read only from the autoloaded options: before the first snippet the option does not exist.
		$cache = isset( wp_load_alloptions()[ CustomCode::OPTION ] ) ? get_option( CustomCode::OPTION ) : null;
		if ( ! is_array( $cache ) || empty( $cache['positions'][ $position ] ) || ! is_array( $cache['positions'][ $position ] ) ) {
			return;
		}

		foreach ( $cache['positions'][ $position ] as $snippet ) {
			if ( ! is_array( $snippet ) || ! isset( $snippet['id'], $snippet['code'] ) ) {
				continue;
			}

			if ( 'php' === ( $snippet['type'] ?? '' ) ) {
				if ( CustomCode::php_allowed() ) {
					// Output at load time would come before the headers (or inside a JSON response): it is
					// dropped, unless the snippet left its own buffer open on top (full-page buffering).
					$level = ob_get_level();
					if ( $everywhere ) {
						ob_start();
					}
					PhpRunner::run( (int) $snippet['id'], (string) $snippet['code'] );
					if ( $everywhere && ob_get_level() === $level + 1 ) {
						ob_end_clean();
					}
				}
				continue;
			}

			if ( $everywhere ) {
				continue;
			}

			// Raw HTML written by a user with unfiltered_html: printing it as is is the feature.
			echo $snippet['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Snippet saved by a user with unfiltered_html.
		}
	}

	/**
	 * Whether this request is the Custom code screen (its form posts there too, and saves through
	 * an internal REST request), where `everywhere` snippets stay off.
	 *
	 * Read from the request because plugins_loaded comes before the screen is known. Anyone can ask
	 * for this URL, but without the capability the request ends in the login redirect or an error.
	 *
	 * @return bool
	 */
	private static function on_custom_code_screen(): bool {
		if ( ! is_admin() || 'admin.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared with the screen slug, nothing is saved.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return Admin\Page::SLUG === $page;
	}
}
