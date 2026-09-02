<?php
/**
 * Shared EasyRankly smoke-test helpers.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$reset_request_caches = static function () {
	$method = new ReflectionMethod( ERankly_Plugin::class, 'reset_runtime_caches' );
	$method->setAccessible( true );
	$method->invoke( null );
};

$decode_graph = static function ( $markup ) {
	$matches = array();

	if ( 1 !== preg_match( '~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~is', $markup, $matches ) ) {
		return null;
	}

	$data = json_decode( trim( $matches[1] ), true );

	return is_array( $data ) ? $data : null;
};

$find_nodes = static function ( $graph, $type ) {
	$found = array();

	if ( ! is_array( $graph ) || empty( $graph['@graph'] ) || ! is_array( $graph['@graph'] ) ) {
		return $found;
	}

	foreach ( $graph['@graph'] as $node ) {
		if ( is_array( $node ) && isset( $node['@type'] ) && $type === $node['@type'] ) {
			$found[] = $node;
		}
	}

	return $found;
};

$find_node = static function ( $graph, $type ) use ( $find_nodes ) {
	$nodes = $find_nodes( $graph, $type );

	return ! empty( $nodes ) ? $nodes[0] : null;
};

$assert_unique_schema_ids = static function ( $graph ) use ( $assert ) {
	$ids = array();

	foreach ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : array() as $node ) {
		if ( is_array( $node ) && ! empty( $node['@id'] ) && is_string( $node['@id'] ) ) {
			$ids[] = $node['@id'];
		}
	}

	$assert( count( $ids ) === count( array_unique( $ids ) ), 'Automatic schema node IDs must be unique.' );
};
