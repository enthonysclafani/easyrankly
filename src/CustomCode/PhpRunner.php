<?php
/**
 * The only place where EasyRankly runs code with eval.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one PHP snippet. Rules (CLAUDE.md, "Custom code"):
 * - the code was written by a user with edit_plugins and parsed with token_get_all() on save;
 * - Runner skips it in safe mode and when file editing is disabled;
 * - any Throwable turns the snippet off and records the error for the administrator.
 */
final class PhpRunner {

	/**
	 * Runs a snippet's code.
	 *
	 * @param int    $id   Snippet ID.
	 * @param string $code Code without the opening tag.
	 */
	public static function run( int $id, string $code ): void {
		try {
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Custom code feature; see the rules above.
		} catch ( \Throwable $error ) {
			CustomCode::disable( $id, get_class( $error ) . ': ' . $error->getMessage() );
		}
	}
}
