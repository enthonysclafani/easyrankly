<?php
namespace Fixture\Admin;

defined( 'ABSPATH' ) || exit;

final class Screen {
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets(): void {
		wp_enqueue_script( 'fixture-admin' );
	}
}
