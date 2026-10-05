<?php
/** Custom code module: location-targeted HEAD / BODY output. Loaded only when the feature is enabled. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers frontend output hooks for custom code snippets. */
function erankly_custom_code_boot(): void {
	add_action( 'wp_head', 'erankly_render_custom_head_code', 20 );
	add_action( 'wp_body_open', 'erankly_render_custom_body_open_code', 5 );
	add_action( 'wp_footer', 'erankly_render_custom_body_close_code', 20 );
}

/**
 * Whether custom code may print on the current request.
 *
 * Fail-closed list: only plain frontend HTML for visitors. Excludes admin,
 * AJAX/CRON/REST/XML-RPC, feeds, embeds, robots, trackbacks and previews
 * (Customizer / post preview) so tracking pixels never pollute stats and
 * code never runs in privileged or machine-readable contexts.
 */
function erankly_custom_code_should_output(): bool {
	if ( ! erankly_custom_code_enabled() ) {
		return false;
	}

	if ( ! erankly_is_frontend_html_request() ) {
		return false;
	}

	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}

	if ( is_feed() || is_robots() || is_trackback() || is_embed() ) {
		return false;
	}

	if ( is_preview() || is_customize_preview() ) {
		return false;
	}

	return true;
}

/**
 * Returns the snippets for one location that match the current request. Targeting is shared with global
 * schema blocks via erankly_targeted_block_matches_request(), which lives in the always-loaded helpers so
 * custom code still works when the frontend schema renderer is off.
 *
 * @param string $blocks_key One of head_code_blocks, body_open_code_blocks, body_close_code_blocks.
 * @param string $filter     Filter applied to each snippet before output.
 * @return string[]
 */
function erankly_get_matching_custom_code( string $blocks_key, string $filter ): array {
	$stored = erankly_get_stored_settings();
	$blocks = isset( $stored[ $blocks_key ] ) && is_array( $stored[ $blocks_key ] ) ? $stored[ $blocks_key ] : array();
	$out    = array();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) || empty( $block['enabled'] ) ) {
			continue;
		}

		$code = isset( $block['code'] ) ? trim( (string) $block['code'] ) : '';

		if ( '' === $code || ! erankly_targeted_block_matches_request( $block ) ) {
			continue;
		}

		$code = (string) apply_filters( $filter, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass only erankly_custom_* filters (head/body_open/body_close).

		if ( '' !== trim( $code ) ) {
			$out[] = $code;
		}
	}

	return $out;
}

/**
 * Prints one location's snippets verbatim, at most once per request.
 *
 * @param string $location One of head, body_open, body_close.
 */
function erankly_render_custom_code( string $location ): void {
	static $rendered = array();

	if ( isset( $rendered[ $location ] ) || ! erankly_custom_code_should_output() ) {
		return;
	}

	$snippets = erankly_get_matching_custom_code( $location . '_code_blocks', 'erankly_custom_' . $location . '_code' );

	if ( array() === $snippets ) {
		return;
	}

	$rendered[ $location ] = true;

	echo "\n<!-- EasyRankly " . esc_html( str_replace( '_', ' ', $location ) ) . " code -->\n" . implode( "\n", $snippets ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional verbatim custom code; capability-gated at save time.
}

function erankly_render_custom_head_code(): void {
	erankly_render_custom_code( 'head' );
}

function erankly_render_custom_body_open_code(): void {
	erankly_render_custom_code( 'body_open' );
}

function erankly_render_custom_body_close_code(): void {
	erankly_render_custom_code( 'body_close' );
}
