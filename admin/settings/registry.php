<?php
/** Core settings panels and their assets/client behavior, available before rendering. */
defined( 'ABSPATH' ) || exit;

/** @return array<string,array<string,mixed>> */
function erankly_admin_settings_registry(): array {
	return array(
		'seo'           => array( 'modules' => array( 'tabs', 'media', 'variables', 'identity', 'user-search', 'schema-builder', 'local-business', 'settings' ), 'autosave' => array( 'reloadOnSave' => true, 'refreshKeys' => array( 'robots_txt_extra', 'enable_local_business', 'local_business_type' ) ) ),
		'features'      => array( 'modules' => array( 'tabs', 'settings' ), 'autosave' => array( 'reloadOnSave' => true, 'refreshKeys' => array_column( erankly_feature_modules(), 'setting' ) ) ),
		'sitemap'       => array( 'modules' => array( 'tabs', 'settings' ), 'autosave' => array() ),
		'custom-code'   => array( 'modules' => array( 'tabs', 'code-builder', 'settings' ), 'autosave' => array() ),
		'import-export' => array( 'modules' => array( 'tabs', 'fields' ) ),
		'tools'         => array( 'modules' => array( 'tabs', 'tools' ) ),
		'redirects'     => array( 'modules' => array( 'tabs', 'panels' ) ),
		'special-pages' => array( 'modules' => array( 'tabs', 'media', 'variables', 'settings' ), 'autosave' => array() ),
	);
}

/** @return array<string,array<string,mixed>> */
function erankly_admin_settings_client_panels(): array {
	$panels = array();
	foreach ( erankly_admin_settings_registry() as $slug => $panel ) {
		if ( isset( $panel['autosave'] ) ) {
			$panels[ $slug ] = array_merge( array( 'restUrl' => esc_url_raw( rest_url( 'erankly/v1/settings/' . $slug ) ) ), $panel['autosave'] );
		}
	}
	return $panels;
}
