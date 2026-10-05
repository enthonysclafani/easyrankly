<?php
/** Compatibility guards. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_should_output_head(): bool {
	$should_output = erankly_is_frontend_html_request();

	/** @param bool $should_output True to render metadata. */
	return (bool) apply_filters( 'erankly_enable_head_output', $should_output );
}

function erankly_localize_url( string $url ): string {
	$provider    = erankly_get_multilingual_provider();
	$context     = erankly_get_multilingual_context();
	$provider_id = $provider instanceof ERankly_Multilingual_Provider_Interface ? $provider->get_id() : '';

	if ( $provider instanceof ERankly_Multilingual_Provider_Interface && $provider->is_enabled() ) {
		try {
			$url = $provider->localize_url( $url, $context );
		} catch ( Throwable ) {
			$url = '';
		}
	}

	return (string) apply_filters( 'erankly_localized_url', $url, $context, $provider_id );
}

function erankly_finalize_canonical_url( string $canonical ): string {
	return (string) apply_filters( 'erankly_canonical', erankly_localize_url( esc_url_raw( $canonical ) ) );
}

function erankly_is_woocommerce_active(): bool {
	return function_exists( 'wc_get_product' );
}

function erankly_woocommerce_structured_data_enabled(): bool {
	$enabled = erankly_is_woocommerce_active() && class_exists( 'WC_Structured_Data' );

	/** @param bool $enabled Whether WooCommerce Product JSON-LD is active. */
	return (bool) apply_filters( 'erankly_woocommerce_structured_data_enabled', $enabled );
}

function erankly_should_render_woocommerce_product_schema( int $post_id ): bool {
	$should_render = ! erankly_woocommerce_structured_data_enabled();

	/** @param bool $should_render Whether EasyRankly should render Product schema. */
	return (bool) apply_filters( 'erankly_render_woocommerce_product_schema', $should_render, $post_id );
}

/**
 * Preserves the public product-data API while loading its implementation only when WooCommerce is active.
 *
 * @return array<string,mixed>
 */
function erankly_get_woocommerce_product_data( int $post_id ): array {
	if ( ! erankly_is_woocommerce_active() ) {
		return array();
	}

	require_once ERANKLY_PATH . 'includes/compatibility-woocommerce.php';

	return erankly_build_woocommerce_product_data( $post_id );
}
