<?php
/**
 * LocalBusiness schema and canonical NAP regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/local-business-schema-smoke.php
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

$business_settings = array(
	'enabled'          => true,
	'business_type'    => 'ProfessionalService',
	'name'             => 'EasyRankly Studio',
	'street_address'   => 'Via Roma 20',
	'address_locality' => 'Milano',
	'address_region'   => 'MI',
	'postal_code'      => '20100',
	'address_country'  => 'IT',
	'telephone'        => '+39 02 1234567',
	'page_id'          => 0,
	'opening_hours'    => array(
		'Monday' => array(
			'enabled' => true,
			'opens'   => '09:00',
			'closes'  => '18:00',
		),
		'Tuesday' => array(
			'enabled' => true,
			'opens'   => '09:00',
			'closes'  => '18:00',
		),
	),
	'latitude'         => '45.46420',
	'longitude'        => '9.19000',
	'gbp_url'          => 'https://maps.google.com/?cid=123456',
);
$global_head_code       = '';
$business_lookup_count = 0;
$business_filter       = static function () use ( &$business_settings, &$business_lookup_count ) {
	++$business_lookup_count;

	return $business_settings;
};
$head_filter      = static function () use ( &$global_head_code ) {
	return $global_head_code;
};
$identity_filter  = static function () {
	return array(
		'person_user_id' => 0,
		'type'           => 'organization',
	);
};
$show_on_front_filter = static function () {
	return 'posts';
};
$blog_public_filter = static function () {
	return '1';
};
$social_filter = static function () {
	return array(
		'default_image_id' => 0,
		'profiles'         => array( 'https://www.linkedin.com/company/easyrankly' ),
	);
};
$render_graph = static function () use ( $reset_request_caches ) {
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_schema_graph();

	return ob_get_clean();
};

$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

add_filter( 'pre_option_erankly_business_settings', $business_filter );
add_filter( 'pre_option_erankly_global_code', $head_filter );
add_filter( 'pre_option_erankly_site_identity', $identity_filter );
add_filter( 'pre_option_erankly_social_settings', $social_filter );
add_filter( 'pre_option_show_on_front', $show_on_front_filter );
add_filter( 'pre_option_blog_public', $blog_public_filter );

try {
	$query              = new WP_Query();
	$query->is_404      = false;
	$query->is_home     = true;
	$query->is_page     = false;
	$query->is_singular = false;
	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = null;

	$business_lookup_count = 0;
	$graph    = $decode_graph( $render_graph() );
	$business = $find_node( $graph, 'ProfessionalService' );
	$website  = $find_node( $graph, 'WebSite' );

	$assert( 1 === $business_lookup_count, 'Business settings must be normalized only once per request.' );
	$assert( is_array( $business ), 'The graph must contain the configured LocalBusiness subtype.' );
	$assert( home_url( '/#identity' ) === $business['@id'], 'LocalBusiness must reuse the stable identity ID.' );
	$assert( 'EasyRankly Studio' === $business['name'], 'Schema must use the canonical business name.' );
	$assert( 'Via Roma 20' === $business['address']['streetAddress'], 'Schema must use the canonical street address.' );
	$assert( '+39 02 1234567' === $business['telephone'], 'Schema must use the canonical telephone.' );
	$assert( 45.4642 === $business['geo']['latitude'], 'Schema must emit latitude as a number.' );
	$assert( 9.19 === $business['geo']['longitude'], 'Schema must emit longitude as a number.' );
	$assert( array( 'Monday', 'Tuesday' ) === $business['openingHoursSpecification'][0]['dayOfWeek'], 'Equal hours must be grouped by day.' );
	$assert( in_array( $business_settings['gbp_url'], $business['sameAs'], true ), 'The Google profile URL must be emitted in sameAs.' );
	$assert( array( '@id' => home_url( '/#identity' ) ) === $website['publisher'], 'WebSite must reference the LocalBusiness identity.' );

	$visible = ERankly_Plugin::render_business_profile_shortcode();
	$assert( false !== strpos( $visible, 'EasyRankly Studio' ), 'The visible profile must reuse the canonical business name.' );
	$assert( false !== strpos( $visible, 'Via Roma 20' ), 'The visible profile must reuse the canonical address.' );
	$assert( false !== strpos( $visible, '+39 02 1234567' ), 'The visible profile must reuse the canonical telephone.' );
	$assert( false !== strpos( $visible, '09:00–18:00' ), 'The visible profile must reuse canonical opening hours.' );
	$block = do_blocks( '<!-- wp:easyrankly/business-profile {"showHours":false} /-->' );
	$assert( false !== strpos( $block, 'EasyRankly Studio' ), 'The registered dynamic block must render canonical business data.' );
	$assert( false === strpos( $block, '09:00–18:00' ), 'Block display attributes must control the rendered fields.' );
	$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'easyrankly/business-profile' );
	$assert( in_array( 'erankly-business-profile-editor', $block_type->editor_script_handles, true ), 'Block metadata must own the editor script handle.' );
	$assert( in_array( 'wp-i18n', wp_scripts()->registered['erankly-business-profile-editor']->deps, true ), 'The block editor script must load WordPress translations.' );

	$global_head_code = '<script type="application/ld+json">{"@type":"LocalBusiness","@id":"https://example.com/#manual-business"}</script>';
	$manual_graph     = $decode_graph( $render_graph() );
	$manual_website   = $find_node( $manual_graph, 'WebSite' );
	$assert( null === $find_node( $manual_graph, 'ProfessionalService' ), 'Manual LocalBusiness must suppress the automatic identity node.' );
	$assert( array( '@id' => 'https://example.com/#manual-business' ) === $manual_website['publisher'], 'Automatic nodes must reference the manual business ID.' );

	$global_head_code                   = '';
	$business_settings['street_address'] = '';
	$incomplete_graph                   = $decode_graph( $render_graph() );
	$assert( null === $find_node( $incomplete_graph, 'ProfessionalService' ), 'Incomplete NAP must not emit LocalBusiness.' );
	$assert( is_array( $find_node( $incomplete_graph, 'Organization' ) ), 'Incomplete NAP must retain the legacy Organization identity.' );

	$sanitized = ERankly_Plugin::sanitize_business_settings(
		array_merge(
			$business_settings,
			array(
				'latitude'  => '91.00000',
				'longitude' => '9.19000',
			)
		)
	);
	$assert( '' === $sanitized['latitude'] && '' === $sanitized['longitude'], 'Invalid coordinate pairs must be omitted together.' );

	$relaxed = ERankly_Plugin::sanitize_business_settings(
		array_merge(
			$business_settings,
			array(
				'latitude'  => '45.4642',
				'longitude' => '9.19',
			)
		)
	);
	$assert( '45.4642' === $relaxed['latitude'] && '9.19' === $relaxed['longitude'], 'Coordinates with fewer than five decimals must be accepted.' );

	$invalid_country = ERankly_Plugin::sanitize_business_settings( array_merge( $business_settings, array( 'address_country' => 'ZZ' ) ) );
	$assert( '' === $invalid_country['address_country'], 'A country code outside ISO 3166-1 alpha-2 must be discarded.' );

	$normalized_country = ERankly_Plugin::sanitize_business_settings( array_merge( $business_settings, array( 'address_country' => ' it ' ) ) );
	$assert( 'IT' === $normalized_country['address_country'], 'Country codes must be normalized and validated against ISO 3166-1 alpha-2.' );

	$GLOBALS['wp_settings_errors'] = array();
	$invalid_hours = ERankly_Plugin::sanitize_business_settings(
		array_merge(
			$business_settings,
			array(
				'opening_hours' => array(
					'Monday' => array(
						'enabled' => true,
						'opens'   => '9',
						'closes'  => '',
					),
				),
			)
		)
	);
	$business_errors = get_settings_errors( 'erankly_business_settings' );
	$assert( empty( $invalid_hours['opening_hours']['Monday']['enabled'] ), 'Invalid opening hours must be disabled.' );
	$assert( 'erankly-business-hours-monday' === $business_errors[0]['code'], 'Invalid enabled opening hours must produce a settings notice.' );

	$types_method = new ReflectionMethod( ERankly_Plugin::class, 'get_local_business_types' );
	$types_method->setAccessible( true );
	$supported_types = $types_method->invoke( null );

	$groups_method = new ReflectionMethod( ERankly_Plugin::class, 'get_local_business_type_groups' );
	$groups_method->setAccessible( true );
	$grouped_types = array();

	foreach ( $groups_method->invoke( null ) as $group_types ) {
		foreach ( $group_types as $type => $label ) {
			$assert( isset( $supported_types[ $type ] ) && $supported_types[ $type ] === $label, 'Grouped business types must match the flat supported list.' );
			$assert( ! isset( $grouped_types[ $type ] ), 'A business type must belong to exactly one group.' );
			$grouped_types[ $type ] = true;
		}
	}

	$assert( count( $grouped_types ) === count( $supported_types ), 'Every supported business type must be rendered in a group.' );
	$assert( isset( $supported_types['ExerciseGym'] ) && isset( $supported_types['NightClub'] ), 'Common local business categories must be selectable.' );
	$assert( ! isset( $supported_types['VeterinaryCare'] ) && ! isset( $supported_types['Hospital'] ), 'Only LocalBusiness subtypes may be offered as business types.' );
} finally {
	remove_filter( 'pre_option_erankly_business_settings', $business_filter );
	remove_filter( 'pre_option_erankly_global_code', $head_filter );
	remove_filter( 'pre_option_erankly_site_identity', $identity_filter );
	remove_filter( 'pre_option_erankly_social_settings', $social_filter );
	remove_filter( 'pre_option_show_on_front', $show_on_front_filter );
	remove_filter( 'pre_option_blog_public', $blog_public_filter );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;

	if ( $original_wp_query instanceof WP_Query ) {
		$original_wp_query->reset_postdata();
	}
}

echo "EasyRankly LocalBusiness schema smoke test passed.\n";
