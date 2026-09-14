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
		$this->assertStringContainsString( 'html: false', $source );
		$this->assertStringContainsString( "align: [ 'wide', 'full' ]", $source );
		$this->assertStringContainsString( 'inserter: ! coreAvailable', $source );
		$this->assertStringContainsString( 'attributesForCoreBreadcrumbs', $source );
		$this->assertStringContainsString( "'core/breadcrumbs'", $source );
		$this->assertStringContainsString( 'return null;', $source );

		$manifest = $this->source( 'blocks/breadcrumbs/block.json' );
		$this->assertStringContainsString( '"inserter": false', $manifest );
		$this->assertStringContainsString( '"align": [ "wide", "full" ]', $manifest );
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

	public function test_admin_widgets_load_more_local_business_sites_from_rest(): void {
		$source = $this->source( 'assets/js/admin-widgets.js' );

		$this->assertStringContainsString( 'eranklyLocalBusiness', $source );
		$this->assertStringContainsString( 'data-erankly-local-business-load-more', $source );
		$this->assertStringContainsString( 'config.pagesUrl', $source );
		$this->assertStringContainsString( 'ensureSitesLoaded', $source );
		$this->assertStringContainsString( 'data-erankly-local-business-page-search', $source );
		$this->assertStringContainsString( 'X-WP-Nonce', $source );
		$this->assertStringContainsString( 'indexOf("?")', $source );
		$this->assertStringContainsString( 'data-erankly-local-business-enabled', $source );
		$this->assertStringContainsString( 'classList.toggle("is-closed", closed.checked)', $source );
	}

	public function test_schema_identity_keeps_location_fields_when_local_business_is_enabled(): void {
		$source = $this->source( 'assets/js/admin-schema.js' );

		$this->assertStringContainsString( 'function localBusinessIsEnabled', $source );
		$this->assertStringContainsString( 'data-erankly-local-business-enabled', $source );
		$this->assertStringContainsString( 'data-erankly-organization-only', $source );
		$this->assertStringContainsString( 'data-erankly-location-fields', $source );
		$this->assertStringContainsString( 'data-erankly-identity-label', $source );
		$this->assertStringContainsString( 'data-erankly-local-business-toggle', $source );
	}

	public function test_local_business_widget_probe_covers_enable_and_page_search(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node is required for the LocalBusiness widget probe.' );
		}

		$script = ERANKLY_PATH . 'tests/js/local-business-widget-probe.cjs';
		$this->assertFileExists( $script );

		$output     = array();
		$exit_code  = 1;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $script ), $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
	}

	public function test_breadcrumbs_block_probe_preserves_compatible_transform_attributes(): void {
		$node = trim( (string) shell_exec( 'command -v node' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node is required for the breadcrumbs block probe.' );
		}

		$script = ERANKLY_PATH . 'tests/js/breadcrumbs-block-probe.cjs';
		$this->assertFileExists( $script );

		$output    = array();
		$exit_code = 1;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $script ), $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
	}

}
