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
 * - any Throwable turns the snippet off and records the error for the administrator;
 * - a fatal error that no catch can see does the same from a shutdown function.
 */
final class PhpRunner {

	/**
	 * Snippet being run right now (0 before the first one, -1 between snippets).
	 *
	 * Per-request state, read only by shutdown(): not a cache.
	 *
	 * @var int
	 */
	private static int $running = 0;

	/**
	 * Runs a snippet's code.
	 *
	 * @param int    $id   Snippet ID.
	 * @param string $code Code without the opening tag.
	 */
	public static function run( int $id, string $code ): void {
		if ( 0 === self::$running ) {
			register_shutdown_function( array( self::class, 'shutdown' ) );
		}

		self::$running = $id;
		try {
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Custom code feature; see the rules above.
		} catch ( \Throwable $error ) {
			CustomCode::disable( $id, get_class( $error ) . ': ' . $error->getMessage() );
		}
		self::$running = -1;
	}

	/**
	 * Runs at the end of every request that ran a snippet: a fatal error (redeclared
	 * function, memory, time limit) skips the catch above but still reaches here.
	 */
	public static function shutdown(): void {
		self::handle_fatal( error_get_last(), self::$running );
	}

	/**
	 * Turns off the snippet that was running when a fatal error stopped the request.
	 *
	 * @param array<string, mixed>|null $error Last PHP error.
	 * @param int                       $id    Snippet running at that moment (-1 or 0 when none).
	 */
	public static function handle_fatal( ?array $error, int $id ): void {
		$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		if ( $id <= 0 || null === $error || 0 === ( (int) ( $error['type'] ?? 0 ) & $fatal ) ) {
			return;
		}

		CustomCode::disable( $id, 'Fatal error: ' . (string) ( $error['message'] ?? '' ) );
	}
}
