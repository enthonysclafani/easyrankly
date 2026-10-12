<?php
/**
 * Prints and runs active snippets.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the snippets of each position from the autoloaded cache: no query. Each cached
 * snippet carries an HMAC made with the salts of wp-config.php, checked before it runs.
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
		foreach ( CustomCode::POSITIONS as $hook ) {
			add_action( $hook, array( self::class, 'run_hook' ) );
		}
	}

	/**
	 * Runs the position of the hook being fired.
	 *
	 * A static method, not a closure, so a theme, a plugin or a snippet can take a position off:
	 * `remove_action( 'wp_footer', array( \EasyRankly\CustomCode\Runner::class, 'run_hook' ) )`.
	 */
	public static function run_hook(): void {
		$position = array_search( current_filter(), CustomCode::POSITIONS, true );
		if ( is_string( $position ) ) {
			( new self() )->run( $position );
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

		foreach ( self::snippets( $position ) as $snippet ) {
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
	 * Signed snippets of a position from the cache.
	 *
	 * A snippet whose signature does not match is never run. When there is one, the cache is
	 * rebuilt from the snippets themselves: it was written before signatures existed, the salts
	 * in wp-config.php changed, or something other than this plugin wrote the option.
	 *
	 * @param string $position Position key.
	 * @return array<int, array<mixed>>
	 */
	private static function snippets( string $position ): array {
		$signed = self::signed( self::cached( $position ), $position, $all );
		if ( $all ) {
			return $signed;
		}

		CustomCode::rebuild_cache();

		return self::signed( self::cached( $position ), $position, $all );
	}

	/**
	 * Cached entries of a position, unchecked.
	 *
	 * @param string $position Position key.
	 * @return array<mixed>
	 */
	private static function cached( string $position ): array {
		// Read only from the autoloaded options: before the first snippet the option does not exist.
		$cache = isset( wp_load_alloptions()[ CustomCode::OPTION ] ) ? get_option( CustomCode::OPTION ) : null;
		if ( ! is_array( $cache ) || empty( $cache['positions'][ $position ] ) || ! is_array( $cache['positions'][ $position ] ) ) {
			return array();
		}

		return $cache['positions'][ $position ];
	}

	/**
	 * Cached entries that are snippets this plugin wrote for this position.
	 *
	 * @param array<mixed> $cached   Cached entries.
	 * @param string       $position Position key.
	 * @param bool|null    $all      Set to whether every entry was signed.
	 * @param-out bool     $all
	 * @return array<int, array<mixed>>
	 */
	private static function signed( array $cached, string $position, ?bool &$all ): array {
		$all    = true;
		$signed = array();
		foreach ( $cached as $snippet ) {
			$type = is_array( $snippet ) ? ( $snippet['type'] ?? '' ) : null;
			if (
				is_array( $snippet ) && is_string( $type ) && isset( $snippet['id'], $snippet['code'], $snippet['sig'] )
				&& is_numeric( $snippet['id'] ) && is_string( $snippet['code'] ) && is_string( $snippet['sig'] )
				&& hash_equals( CustomCode::signature( (int) $snippet['id'], $type, $position, $snippet['code'] ), $snippet['sig'] )
			) {
				$signed[] = $snippet;
			} else {
				$all = false;
			}
		}

		return $signed;
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
