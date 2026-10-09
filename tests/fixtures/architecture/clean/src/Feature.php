<?php
namespace Fixture;

defined( 'ABSPATH' ) || exit;

// wp_schedule_event() and 'CREATE TABLE' in comments are ignored.
final class Feature {
	private string $name = 'feature';

	public function register(): void {
		add_action( 'init', array( $this, 'on_init' ) );
		add_action(
			'admin_menu',
			function (): void {
				add_action( 'load-settings_page_fixture', array( $this, 'on_load' ) );
			}
		);
	}

	public function on_init(): void {
		$label = "Name: {$this->name}";
		add_filter( 'the_title', static fn( $title ) => $title . $label );
	}

	public function on_load(): void {
		$this->copy();
	}

	private function copy(): void {
		// Names that only look like the agent's, and a method named like an AI function.
		update_option( 'easyrankly_settings', array( 'progress' => 'easyrankly-promo' ) );
		$this->wp_get_ability();
	}

	private function wp_get_ability(): void {
	}
}
