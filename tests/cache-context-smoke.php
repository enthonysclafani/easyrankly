<?php
/**
 * Runtime cache context regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/cache-context-smoke.php
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

$locale = 'en_US';
$locale_filter = static function () use ( &$locale ) {
	return $locale;
};
$head_filter = static function () use ( &$locale ) {
	return '<meta name="cache-context" content="' . esc_attr( $locale . '-' . get_current_blog_id() ) . '">';
};
$business_filter = static function () use ( &$locale ) {
	return array(
		'business_type' => 'LocalBusiness',
		'enabled'       => false,
		'name'          => 'Business ' . $locale,
	);
};
$social_filter = static function () use ( &$locale ) {
	return array(
		'default_image_id' => 0,
		'profiles'         => array( 'https://example.com/' . strtolower( $locale ) ),
	);
};
$identity_filter = static function () {
	return array(
		'person_user_id' => 0,
		'type'           => 'organization',
	);
};
$blogname_filter = static function () use ( &$locale ) {
	return 'Site ' . $locale;
};
$render_head = static function () {
	ob_start();
	ERankly_Plugin::print_head_code();

	return ob_get_clean();
};
$social_method = new ReflectionMethod( ERankly_Plugin::class, 'get_social_settings' );
$identity_method = new ReflectionMethod( ERankly_Plugin::class, 'get_site_identity_schema' );
$social_method->setAccessible( true );
$identity_method->setAccessible( true );
$had_blog_id      = array_key_exists( 'blog_id', $GLOBALS );
$original_blog_id = $had_blog_id ? $GLOBALS['blog_id'] : null;

add_filter( 'locale', $locale_filter );
add_filter( 'pre_option_erankly_global_code', $head_filter );
add_filter( 'pre_option_erankly_business_settings', $business_filter );
add_filter( 'pre_option_erankly_social_settings', $social_filter );
add_filter( 'pre_option_erankly_site_identity', $identity_filter );
add_filter( 'pre_option_blogname', $blogname_filter );

try {
	$reset_request_caches();
	$first_head     = $render_head();
	$first_business = ERankly_Plugin::get_business_profile();
	$first_social   = $social_method->invoke( null );
	$first_identity = $identity_method->invoke( null );

	$locale = 'it_IT';
	$second_head     = $render_head();
	$second_business = ERankly_Plugin::get_business_profile();
	$second_social   = $social_method->invoke( null );
	$second_identity = $identity_method->invoke( null );

	$assert( false !== strpos( $first_head, 'en_US-' ), 'The first locale must populate its own head-code cache.' );
	$assert( false !== strpos( $second_head, 'it_IT-' ), 'Head-code cache entries must be isolated by locale.' );
	$assert( 'Business en_US' === $first_business['name'] && 'Business it_IT' === $second_business['name'], 'Business-profile cache entries must be isolated by locale.' );
	$assert( 'https://example.com/en_us' === $first_social['profiles'][0] && 'https://example.com/it_it' === $second_social['profiles'][0], 'Social-settings cache entries must be isolated by locale.' );
	$assert( 'Site en_US' === $first_identity['name'] && 'Site it_IT' === $second_identity['name'], 'Identity-schema cache entries must be isolated by locale.' );

	$simulated_blog_id   = get_current_blog_id() + 1000;
	$GLOBALS['blog_id']  = $simulated_blog_id;
	$site_isolated_head  = $render_head();
	$GLOBALS['blog_id']  = $original_blog_id;
	$assert( false !== strpos( $site_isolated_head, 'it_IT-' . $simulated_blog_id ), 'Head-code cache entries must be isolated by site ID.' );

	if ( is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 2,
			)
		);

		if ( count( $site_ids ) > 1 ) {
			$origin_id = get_current_blog_id();
			$target_id = (int) current( array_diff( array_map( 'intval', $site_ids ), array( $origin_id ) ) );
			switch_to_blog( $target_id );
			$network_head = $render_head();
			restore_current_blog();
			$assert( false !== strpos( $network_head, 'it_IT-' . $target_id ), 'Head-code cache entries must be isolated by site.' );
		}
	}
} finally {
	remove_filter( 'locale', $locale_filter );
	remove_filter( 'pre_option_erankly_global_code', $head_filter );
	remove_filter( 'pre_option_erankly_business_settings', $business_filter );
	remove_filter( 'pre_option_erankly_social_settings', $social_filter );
	remove_filter( 'pre_option_erankly_site_identity', $identity_filter );
	remove_filter( 'pre_option_blogname', $blogname_filter );

	if ( $had_blog_id ) {
		$GLOBALS['blog_id'] = $original_blog_id;
	} else {
		unset( $GLOBALS['blog_id'] );
	}

	$reset_request_caches();
}

echo "EasyRankly cache context smoke test passed.\n";
