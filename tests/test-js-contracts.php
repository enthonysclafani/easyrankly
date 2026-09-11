<?php
/**
 * Lightweight contracts for admin/editor JavaScript. CI has no JS test runner;
 * these assertions lock the identifiers PHP and the scripts must keep in sync.
 */

final class ERankly_Js_Contracts_Test extends WP_UnitTestCase {

	private function source( string $relative ): string {
		$path = ERANKLY_PATH . ltrim( $relative, '/' );
		$this->assertFileExists( $path );

		$contents = file_get_contents( $path );
		$this->assertIsString( $contents );
		$this->assertNotSame( '', $contents );

		return $contents;
	}

	public function test_breadcrumbs_block_registers_a_dynamic_block(): void {
		$source = $this->source( 'blocks/breadcrumbs/index.js' );

		$this->assertStringContainsString( "registerBlockType( 'easyrankly/breadcrumbs'", $source );
		$this->assertStringContainsString( 'return null;', $source );
	}

	public function test_admin_settings_posts_panel_changes_to_the_rest_url(): void {
		$source = $this->source( 'assets/js/admin-settings.js' );

		$this->assertStringContainsString( 'function changedTopLevelKeys', $source );
		$this->assertStringContainsString( 'fetch(config.restUrl', $source );
	}

	public function test_schema_jsonld_validates_type_names_with_the_plugin_textdomain(): void {
		$source = $this->source( 'assets/js/schema-jsonld.js' );

		$this->assertStringContainsString( 'function isValidTypeName', $source );
		$this->assertStringContainsString( "'easyrankly'", $source );
	}

	public function test_editor_script_maps_meta_keys_and_registers_the_plugin(): void {
		$source = $this->source( 'assets/js/editor.js' );

		$this->assertStringContainsString( 'const META_MAP', $source );
		$this->assertStringContainsString( "title: '_erankly_title'", $source );
		$this->assertStringContainsString( 'registerPlugin', $source );
	}

	public function test_site_editor_script_binds_the_special_meta_setting(): void {
		$source = $this->source( 'assets/js/site-editor.js' );

		$this->assertStringContainsString( 'PluginDocumentSettingPanel', $source );
		$this->assertStringContainsString( 'specialMetaSetting', $source );
	}

	public function test_admin_reset_script_targets_the_reset_modal(): void {
		$source = $this->source( 'assets/js/admin-reset.js' );

		$this->assertStringContainsString( '[data-erankly-reset-modal]', $source );
	}
}
