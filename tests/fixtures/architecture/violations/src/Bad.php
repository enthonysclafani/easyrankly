<?php
namespace Fixture;

add_action( 'init', 'fixture_boot' );

final class Bad {
	public function __construct() {
		add_filter( 'the_title', 'strtoupper' );
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		wp_schedule_event( time(), 'daily', 'fixture_cron' );
		\dbDelta( 'CREATE TABLE fixture (id INT)' );
		global $wpdb;
		eval( '$x = 1;' );
		file_put_contents( '/tmp/fixture', 'y' );
		wp_enqueue_script( 'fixture' );
		$this->copy( 'a method, not the global function' );
		// wp_schedule_event() inside a comment is ignored.
	}
}
