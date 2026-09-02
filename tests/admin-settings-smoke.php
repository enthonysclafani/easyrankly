<?php
/**
 * Admin settings registration regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/admin-settings-smoke.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( 'ERankly_Plugin' ) ) {
	throw new RuntimeException( 'EasyRankly must be active before running this test.' );
}

require __DIR__ . '/bootstrap.php';

ERankly_Plugin::register_admin_settings();
ERankly_Plugin::register_business_settings();

$registered_settings = get_registered_settings();
$settings_fields     = isset( $GLOBALS['wp_settings_fields'] ) && is_array( $GLOBALS['wp_settings_fields'] )
	? $GLOBALS['wp_settings_fields']
	: array();

$assert(
	isset( $registered_settings['erankly_social_settings']['type'] )
	&& 'array' === $registered_settings['erankly_social_settings']['type'],
	'The social settings option must be registered as an array.'
);
$assert(
	isset( $registered_settings['erankly_business_settings']['type'] )
	&& 'array' === $registered_settings['erankly_business_settings']['type'],
	'The business settings option must be registered as an array.'
);

foreach ( array( 'erankly_social_settings', 'erankly_site_identity', 'erankly_business_settings' ) as $setting_name ) {
	$setting = isset( $registered_settings[ $setting_name ] ) ? $registered_settings[ $setting_name ] : array();
	$assert( ! empty( $setting['type'] ), 'Every setting must declare its type.' );
	$assert( array_key_exists( 'default', $setting ), 'Every setting must declare its default.' );
	$assert( ! empty( $setting['sanitize_callback'] ) && is_callable( $setting['sanitize_callback'] ), 'Every setting must have a callable sanitizer.' );
}
$assert(
	'erankly_social_profiles' === $settings_fields['erankly-social']['erankly_social_profiles']['erankly_social_profiles']['args']['label_for'],
	'The social profiles label must target its textarea.'
);
$assert(
	empty( $settings_fields['erankly-social']['erankly_social_previews']['erankly_social_default_image']['args']['label_for'] ),
	'The composite social image control must not label its hidden input.'
);
$assert(
	'erankly_site_identity_type' === $settings_fields['general']['default']['erankly_site_identity_type']['args']['label_for'],
	'The site identity label must target its select.'
);
$assert(
	'erankly_site_identity_person_user_id' === $settings_fields['general']['default']['erankly_site_identity_person']['args']['label_for'],
	'The primary person label must target its select.'
);

$missing_social_settings  = new stdClass();
$original_social_settings = get_option( 'erankly_social_settings', $missing_social_settings );
$legacy_lookup_count      = 0;
$legacy_lookup_filter     = static function () use ( &$legacy_lookup_count ) {
	++$legacy_lookup_count;

	return 0;
};
$deprecated_filter        = '__return_false';
$active_plugins_filter    = static function () {
	return array( 'wordpress-seo/wp-seo.php' );
};
$network_plugins_filter   = static function () {
	return array();
};
$capability_filter        = static function ( $allcaps ) {
	$allcaps['manage_options'] = true;

	return $allcaps;
};

add_filter( 'pre_attachment_url_to_postid', $legacy_lookup_filter );

try {
	$sanitize_social_settings = array( ERankly_Plugin::class, 'sanitize_social_settings' );
	remove_filter( 'sanitize_option_erankly_social_settings', $sanitize_social_settings );
	update_option(
		'erankly_social_settings',
		array(
			'default_image_url' => home_url( '/wp-content/uploads/easyrankly-legacy.jpg' ),
			'profiles'         => array(),
		)
	);
	add_filter( 'sanitize_option_erankly_social_settings', $sanitize_social_settings );

	ERankly_Plugin::migrate_legacy_social_settings();
	ERankly_Plugin::migrate_legacy_social_settings();
	$migrated_settings = get_option( 'erankly_social_settings', array() );

	$assert( 1 === $legacy_lookup_count, 'A legacy social image URL must be resolved only once.' );
	$assert( ! array_key_exists( 'default_image_url', $migrated_settings ), 'The legacy social image URL must be removed after migration.' );
	$assert( 0 === $migrated_settings['default_image_id'], 'An unresolved legacy social image must fall back to no image.' );

	ob_start();
	ERankly_Plugin::render_social_settings_field();
	$image_field = ob_get_clean();
	$assert( false === strpos( $image_field, 'id="erankly-social-image-preview"' ), 'An empty social image must not render an img placeholder.' );

	$late_post_type = 'erankly_late_meta';
	register_post_type(
		$late_post_type,
		array(
			'public'       => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'custom-fields', 'revisions' ),
		)
	);
	$late_meta = get_registered_meta_keys( 'post', $late_post_type );
	$assert( isset( $late_meta['erankly_code'], $late_meta['erankly_visibility'] ), 'Post types registered after EasyRankly must receive its metadata.' );

	foreach ( array( 'erankly_code', 'erankly_body_start_code', 'erankly_body_end_code', 'erankly_meta_description', 'erankly_visibility' ) as $meta_key ) {
		$meta = isset( $late_meta[ $meta_key ] ) ? $late_meta[ $meta_key ] : array();
		$assert( ! empty( $meta['sanitize_callback'] ) && is_callable( $meta['sanitize_callback'] ), 'Every post meta field must have a callable sanitizer.' );
		$assert( ! empty( $meta['auth_callback'] ) && is_callable( $meta['auth_callback'] ), 'Every post meta field must have a callable authorization callback.' );
	}

	unregister_post_type( $late_post_type );

	$plugin_file        = dirname( __DIR__ ) . '/easyrankly.php';
	$readme_file        = dirname( __DIR__ ) . '/readme.txt';
	$block_file         = dirname( __DIR__ ) . '/blocks/business-profile/block.json';
	$schema_file        = dirname( __DIR__ ) . '/includes/erankly-schema.php';
	$uninstall_file     = dirname( __DIR__ ) . '/uninstall.php';
	$distignore_file    = dirname( __DIR__ ) . '/.distignore';
	$plugin_data        = get_file_data(
		$plugin_file,
		array(
			'requires_php' => 'Requires PHP',
			'requires_wp'  => 'Requires at least',
			'version'      => 'Version',
		),
		'plugin'
	);
	$readme             = file_get_contents( $readme_file );
	$block              = json_decode( file_get_contents( $block_file ), true );
	$schema_source      = file_get_contents( $schema_file );
	$uninstall_source   = file_get_contents( $uninstall_file );
	$distignore         = file_get_contents( $distignore_file );
	$stable_tag_match   = array();
	$requires_wp_match  = array();
	$requires_php_match = array();
	$tested_up_to_match = array();

	$assert( 1 === preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $stable_tag_match ), 'The readme must declare a stable tag.' );
	$assert( 1 === preg_match( '/^Requires at least:\s*(\S+)/mi', $readme, $requires_wp_match ), 'The readme must declare its minimum WordPress version.' );
	$assert( 1 === preg_match( '/^Requires PHP:\s*(\S+)/mi', $readme, $requires_php_match ), 'The readme must declare its minimum PHP version.' );
	$assert( 1 === preg_match( '/^Tested up to:\s*(\S+)/mi', $readme, $tested_up_to_match ), 'The readme must declare the highest tested WordPress version.' );
	$assert( ! empty( $plugin_data['version'] ), 'The plugin header must declare a version.' );
	$assert( $plugin_data['version'] === $stable_tag_match[1], 'The plugin version and readme stable tag must match.' );
	$assert( $plugin_data['requires_wp'] === $requires_wp_match[1], 'The plugin header and readme must declare the same minimum WordPress version.' );
	$assert( $plugin_data['requires_php'] === $requires_php_match[1], 'The plugin header and readme must declare the same minimum PHP version.' );
	$assert( version_compare( $tested_up_to_match[1], $requires_wp_match[1], '>=' ), 'Tested up to must not be lower than the minimum WordPress version.' );
	$assert( is_array( $block ) && $plugin_data['version'] === $block['version'], 'The plugin and block versions must match.' );
	$assert( false === strpos( $readme, '== Testing ==' ), 'Release documentation must not reference tests excluded from the package.' );
	$assert( 1 === preg_match( '/^tests\/$/m', $distignore ), 'Development tests must remain excluded from release packages.' );
	$assert( 1 === preg_match( '/^rules\.md$/m', $distignore ), 'Development rules must remain excluded from release packages.' );
	$assert( ! defined( 'EASYRANKLY_FILE' ), 'The plugin file path must not leak through a global constant.' );
	$assert( false === strpos( file_get_contents( $plugin_file ), 'Domain Path:' ), 'The plugin header must not advertise a missing language directory.' );
	$assert( function_exists( 'erankly_get_business_profile' ), 'The documented business-profile helper must remain callable.' );
	$assert( method_exists( ERankly_Plugin::class, 'sanitize_head_code' ), 'The pre-2.0 sanitizer callback must retain a compatibility alias.' );
	$assert( ( new ReflectionMethod( ERankly_Plugin::class, 'get_business_profile' ) )->isPublic(), 'The business profile API must remain public.' );
	$assert( ! method_exists( ERankly_Plugin::class, 'get_request_context' ), 'Dead request-context state must not return.' );
	$assert( ! file_exists( dirname( __DIR__ ) . '/includes/erankly-request.php' ), 'The ownership trait must not retain its misleading old filename.' );
	$assert( file_exists( dirname( __DIR__ ) . '/includes/erankly-ownership.php' ), 'The ownership trait must use its responsibility-based filename.' );
	$assert( false !== strpos( $schema_source, 'wp_json_encode(' ), 'Automatic schema must use WordPress JSON serialization.' );
	$assert( false !== strpos( $schema_source, 'wp_print_inline_script_tag(' ), 'Automatic schema must use WordPress safe inline-script output.' );
	$assert( false === strpos( $schema_source, '<script type="application/ld+json">' ), 'Automatic schema must not build its script element manually.' );

	$plugin_reflection = new ReflectionClass( ERankly_Plugin::class );

	foreach ( $plugin_reflection->getReflectionConstants() as $constant ) {
		if ( ! preg_match( '/_(?:OPTION|META_KEY)$/', $constant->getName() ) || ! is_string( $constant->getValue() ) ) {
			continue;
		}

		$assert(
			false !== strpos( $uninstall_source, "'" . $constant->getValue() . "'" ),
			'Every persistence constant must be represented in uninstall.php.'
		);
	}

	foreach ( array( 'Retired in 1.9.0', 'Retired in 1.16.1', 'Retired in 1.17.0' ) as $legacy_marker ) {
		$assert( false !== strpos( $uninstall_source, $legacy_marker ), 'Every legacy uninstall group must document its retirement version.' );
	}

	add_filter( 'deprecated_function_trigger_error', $deprecated_filter );
	$assert( '<meta name="description" content="compatibility">' === ERankly_Plugin::sanitize_head_code( '<meta name="description" content="compatibility">' ), 'The sanitizer compatibility alias must delegate unchanged.' );
	$assert( is_array( erankly_get_business_profile() ), 'The business-profile compatibility helper must delegate to the normalized profile.' );
	remove_filter( 'deprecated_function_trigger_error', $deprecated_filter );

	add_filter( 'pre_option_active_plugins', $active_plugins_filter );
	add_filter( 'pre_site_option_active_sitewide_plugins', $network_plugins_filter );
	add_filter( 'user_has_cap', $capability_filter );
	ob_start();
	ERankly_Plugin::render_head_owner_notice();
	$notice = ob_get_clean();
	$assert( false !== strpos( $notice, 'exclusive SEO owner' ), 'A known overlapping SEO plugin must produce an administrator warning.' );
	remove_filter( 'pre_option_active_plugins', $active_plugins_filter );
	remove_filter( 'pre_site_option_active_sitewide_plugins', $network_plugins_filter );
	remove_filter( 'user_has_cap', $capability_filter );

	$production_files = array_merge(
		array( $plugin_file ),
		glob( dirname( __DIR__ ) . '/includes/*.php' )
	);
	$production_source = '';

	foreach ( $production_files as $production_file ) {
		$source = file_get_contents( $production_file );
		$production_source .= "\n" . $source;
		$assert( false === strpos( $source, "apply_filters( 'erankly_" ), 'EasyRankly must not expose a public filter surface.' );
	}

	$assert(
		0 === preg_match( '/\b(?:wp_remote_[a-z_]+|wp_safe_remote_[a-z_]+|download_url|curl_exec|fsockopen)\s*\(/i', $production_source ),
		'EasyRankly runtime must not perform outbound network requests.'
	);
	$assert(
		0 === preg_match( '/\b(?:wp_enqueue_scripts|enqueue_block_assets)\b/', $production_source ),
		'EasyRankly must not enqueue frontend assets.'
	);
	$assert(
		0 === preg_match( '/\b(?:dbDelta|wp_schedule_event|wp_schedule_single_event|set_transient|set_site_transient|WP_Filesystem|file_put_contents|fwrite)\s*\(/i', $production_source ),
		'EasyRankly must not add tables, persistent caches, cron, or filesystem writes.'
	);
	$assert( 0 === preg_match( '/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $production_source ), 'EasyRankly must retain WordPress-compatible coercion at callback boundaries.' );

	foreach ( array( 'script', 'style', 'viewScript', 'viewScriptModule', 'viewStyle' ) as $frontend_asset_key ) {
		$assert( ! array_key_exists( $frontend_asset_key, $block ), 'The Business Profile block must not load frontend assets.' );
	}
} finally {
	remove_filter( 'pre_attachment_url_to_postid', $legacy_lookup_filter );
	remove_filter( 'deprecated_function_trigger_error', $deprecated_filter );
	remove_filter( 'pre_option_active_plugins', $active_plugins_filter );
	remove_filter( 'pre_site_option_active_sitewide_plugins', $network_plugins_filter );
	remove_filter( 'user_has_cap', $capability_filter );
	add_filter( 'sanitize_option_erankly_social_settings', array( ERankly_Plugin::class, 'sanitize_social_settings' ) );

	if ( $missing_social_settings === $original_social_settings ) {
		delete_option( 'erankly_social_settings' );
	} else {
		update_option( 'erankly_social_settings', $original_social_settings );
	}
}

echo "EasyRankly admin settings smoke test passed.\n";
