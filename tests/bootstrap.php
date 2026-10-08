<?php
/**
 * PHPUnit bootstrap for the WordPress core test suite.
 *
 * Development-only file: it runs in CLI before WordPress loads, so the
 * direct-access guard does not apply.
 *
 * @package EasyRankly
 */

$easyrankly_tests_dir = getenv( 'WP_TESTS_DIR' );
$easyrankly_tests_dir = is_string( $easyrankly_tests_dir ) && '' !== $easyrankly_tests_dir ? $easyrankly_tests_dir : '/tmp/wordpress-tests-lib';

$easyrankly_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', is_string( $easyrankly_polyfills ) && '' !== $easyrankly_polyfills ? $easyrankly_polyfills : dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( $easyrankly_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Run tools/install-wp-tests.sh or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $easyrankly_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/easyrankly.php';
	}
);

require $easyrankly_tests_dir . '/includes/bootstrap.php';
require_once dirname( __DIR__ ) . '/tools/check-architecture.php';
