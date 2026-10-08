<?php
/**
 * Prints active snippets on the frontend.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the snippets of each position from the autoloaded cache: no query.
 *
 * Snippets never run in the admin, so a broken one can always be fixed there.
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
		if ( CustomCode::safe_mode() || is_admin() ) {
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
					PhpRunner::run( (int) $snippet['id'], (string) $snippet['code'] );
				}
				continue;
			}

			// Raw HTML written by a user with unfiltered_html: printing it as is is the feature.
			echo $snippet['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Snippet saved by a user with unfiltered_html.
		}
	}
}
