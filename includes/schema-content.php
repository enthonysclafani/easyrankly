<?php
/** Schema.org content detection from post content and supported plugins. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event node for The Events Calendar events. Other post types get no automatic Event: guessing dates and venues
 * from arbitrary meta keys risks publishing misleading structured data. Use a custom schema block, or the
 * erankly_schema filter, for other event sources.
 *
 * @return array<string,mixed>
 */
function erankly_schema_event( int $post_id ): array {
	if ( 'tribe_events' !== get_post_type( $post_id ) ) {
		return array();
	}

	$schema = erankly_schema_event_from_tec( $post_id );

	if ( empty( $schema ) ) {
		return array();
	}

	$schema = erankly_schema_event_finalize( $schema );

	if ( empty( $schema ) ) {
		return array();
	}

	return apply_filters( 'erankly_schema_event', array_filter( $schema ), $post_id );
}

/**
 * Drops events that cannot carry a valid ISO-8601 startDate and a usable location.
 * Incomplete events are omitted rather than published as if they were rich-result ready.
 *
 * @param array<string,mixed> $schema Event node.
 * @return array<string,mixed>
 */
function erankly_schema_event_finalize( array $schema ): array {
	$start = isset( $schema['startDate'] ) ? (string) $schema['startDate'] : '';

	if ( '' === $start ) {
		return array();
	}

	$end = isset( $schema['endDate'] ) ? (string) $schema['endDate'] : '';

	if ( '' !== $end ) {
		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );

		if ( false === $end_ts || ( false !== $start_ts && $end_ts < $start_ts ) ) {
			unset( $schema['endDate'] );
		}
	}

	if ( ! erankly_schema_event_has_valid_location( $schema ) ) {
		return array();
	}

	return $schema;
}

/**
 * Google Event structured data requires location: a Place (name and/or address) or a VirtualLocation with a URL.
 *
 * @param array<string,mixed> $schema Event node.
 */
function erankly_schema_event_has_valid_location( array $schema ): bool {
	$location = $schema['location'] ?? null;

	if ( is_array( $location ) && isset( $location[0] ) && erankly_array_is_list( $location ) ) {
		foreach ( $location as $item ) {
			if ( is_array( $item ) && erankly_schema_event_location_item_is_valid( $item ) ) {
				return true;
			}
		}

		return false;
	}

	return is_array( $location ) && erankly_schema_event_location_item_is_valid( $location );
}

/**
 * @param array<string,mixed> $location Location node.
 */
function erankly_schema_event_location_item_is_valid( array $location ): bool {
	$type = isset( $location['@type'] ) ? trim( (string) $location['@type'] ) : '';
	$url  = isset( $location['url'] ) ? trim( (string) $location['url'] ) : '';
	$name = isset( $location['name'] ) ? trim( (string) $location['name'] ) : '';
	$addr = $location['address'] ?? null;

	if ( 'VirtualLocation' === $type ) {
		return '' !== $url;
	}

	if ( 'Place' === $type || 'PostalAddress' === $type || '' === $type ) {
		if ( '' !== $name || '' !== $url ) {
			return true;
		}

		if ( ! is_array( $addr ) ) {
			return false;
		}

		foreach ( $addr as $key => $value ) {
			if ( '@type' === $key ) {
				continue;
			}

			if ( is_array( $value ) || ( is_scalar( $value ) && '' !== trim( (string) $value ) ) ) {
				return true;
			}
		}
	}

	return false;
}

/** @return array<string,mixed> */
function erankly_schema_event_from_tec( int $post_id ): array {
	$start = (string) get_post_meta( $post_id, '_EventStartDate', true );
	$end   = (string) get_post_meta( $post_id, '_EventEndDate', true );

	$start_iso = erankly_schema_event_datetime( $start );

	if ( '' === $start_iso ) {
		return array();
	}

	$permalink = (string) get_permalink( $post_id );
	$schema    = array(
		'@type'            => 'Event',
		'@id'              => $permalink . '#event',
		'name'             => get_the_title( $post_id ),
		'startDate'        => $start_iso,
		'url'              => $permalink,
		'mainEntityOfPage' => array(
			'@id' => erankly_get_canonical() . '#webpage',
		),
		'organizer'        => array(
			'@id' => erankly_schema_identity_id(),
		),
		'eventStatus'      => 'https://schema.org/EventScheduled',
	);

	$end_iso = '' !== $end ? erankly_schema_event_datetime( $end ) : '';

	if ( '' !== $end_iso ) {
		$schema['endDate'] = $end_iso;
	}

	$description = erankly_get_description();

	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	$image = erankly_get_og_image();

	if ( '' !== $image ) {
		$schema['image'] = $image;
	}

	$event_url = (string) get_post_meta( $post_id, '_EventURL', true );

	if ( '' !== $event_url ) {
		$schema['sameAs'] = esc_url_raw( $event_url );
	}

	$location = erankly_schema_event_location_from_tec( $post_id );

	if ( ! empty( $location ) ) {
		$schema['location']             = $location;
		$schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
	} elseif ( erankly_schema_event_is_virtual( $post_id ) ) {
		$virtual_url = $event_url !== '' ? esc_url_raw( $event_url ) : $permalink;
		$schema['location'] = array(
			'@type' => 'VirtualLocation',
			'url'   => $virtual_url,
		);
		$schema['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
	}

	return $schema;
}

/** Normalises an event datetime string to ISO 8601. Returns an empty string when the value is not a date. */
function erankly_schema_event_datetime( string $value ): string {
	$value = trim( $value );

	if ( '' === $value ) {
		return '';
	}

	$timestamp = strtotime( $value );

	return false !== $timestamp ? gmdate( DATE_W3C, $timestamp ) : '';
}

function erankly_schema_event_is_virtual( int $post_id ): bool {
	$flags = array( '_EventVirtual', '_ecp_virtual' );

	foreach ( $flags as $key ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( ! empty( $value ) && 'no' !== strtolower( (string) $value ) && '0' !== (string) $value ) {
			return true;
		}
	}

	return false;
}

/** @return array<string,mixed> */
function erankly_schema_event_location_from_tec( int $post_id ): array {
	$venue_id = absint( get_post_meta( $post_id, '_EventVenueID', true ) );

	if ( $venue_id <= 0 ) {
		return array();
	}

	$name = get_the_title( $venue_id );
	$addr = array_filter(
		array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => trim( (string) get_post_meta( $venue_id, '_VenueAddress', true ) ),
			'addressLocality' => trim( (string) get_post_meta( $venue_id, '_VenueCity', true ) ),
			'addressRegion'   => trim( (string) get_post_meta( $venue_id, '_VenueState', true ) ),
			'postalCode'      => trim( (string) get_post_meta( $venue_id, '_VenueZip', true ) ),
			'addressCountry'  => trim( (string) get_post_meta( $venue_id, '_VenueCountry', true ) ),
		),
		static fn( $value ): bool => is_string( $value ) && '' !== $value
	);

	$location = array(
		'@type' => 'Place',
		'name'  => is_string( $name ) ? $name : '',
	);

	if ( count( $addr ) > 1 ) {
		$location['address'] = $addr;
	}

	return array_filter( $location );
}

/**
 * Returns VideoObject schema nodes for embedded videos in a post. Uses the same extraction logic as the video
 * sitemap.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_schema_video_objects( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$video_markers  = array( 'youtube.com/', 'youtu.be/', 'vimeo.com/', '<video', 'wp:video' );
	$video_haystack = strtolower( $post->post_content );
	$has_video      = array_reduce(
		$video_markers,
		static fn( bool $found, string $marker ): bool => $found || str_contains( $video_haystack, $marker ),
		false
	);

	if ( ! $has_video ) {
		return array();
	}

	erankly_load_video_helpers();
	$video_urls = erankly_extract_video_urls( $post->post_content );

	if ( empty( $video_urls ) ) {
		return array();
	}

	$canonical   = erankly_get_canonical();
	$title       = get_the_title( $post_id );
	$description = erankly_trim_text( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 500 );
	$upload_date = get_the_date( DATE_W3C, $post_id );
	$objects     = array();

	foreach ( $video_urls as $index => $video_url ) {
		$embed_url     = erankly_get_video_embed_url( $video_url );
		$content_url   = erankly_get_video_content_url( $video_url );
		$thumbnail_url = erankly_get_video_thumbnail_url( $post_id, $video_url );

		if ( ( '' === $embed_url && '' === $content_url ) || '' === $thumbnail_url ) {
			continue;
		}

		$object = array(
			'@type'            => 'VideoObject',
			'@id'              => $canonical . '#video-' . substr( md5( $video_url ), 0, 8 ),
			'name'             => erankly_schema_video_name( is_string( $title ) ? $title : '', (int) $index, count( $video_urls ) ),
			'thumbnailUrl'     => $thumbnail_url,
			'uploadDate'       => is_string( $upload_date ) ? $upload_date : '',
			'mainEntityOfPage' => array(
				'@id' => $canonical . '#webpage',
			),
		);

		if ( '' === $object['name'] || '' === $object['thumbnailUrl'] || '' === $object['uploadDate'] ) {
			continue;
		}

		if ( '' !== $description ) {
			$object['description'] = $description;
		}

		if ( '' !== $embed_url ) {
			$object['embedUrl'] = $embed_url;
		}

		if ( '' !== $content_url ) {
			$object['contentUrl'] = esc_url_raw( $content_url );
		}

		/**
 * Filters an individual VideoObject schema node. Return an empty array to exclude the video.
 *
 * @param int                 $index     Zero-based index on the page.
 */
		$object = apply_filters( 'erankly_schema_video_object', $object, $post_id, $video_url, (int) $index );

		if ( ! empty( $object ) ) {
			$objects[] = array_filter( $object );
		}
	}

	$objects = apply_filters( 'erankly_schema_video_objects', $objects, $post_id );

	return is_array( $objects ) ? array_values( array_filter( $objects ) ) : array();
}

/**
 * Distinguishes multiple videos on one page. A single video keeps the post title;
 * later videos append a 1-based index so each VideoObject has a unique name.
 */
function erankly_schema_video_name( string $title, int $index, int $total ): string {
	$title = trim( $title );

	if ( $total <= 1 || $index < 1 ) {
		return $title;
	}

	return sprintf(
		/* translators: 1: post title, 2: 1-based video index. */
		__( '%1$s (video %2$d)', 'easyrankly' ),
		$title,
		$index + 1
	);
}

/** @return array<string,mixed> */
function erankly_schema_service_for_page( int $post_id ): array {
	/** Filters Service schema arguments for the current page. Return a non-empty array to emit Service schema via erankly_schema_service(). */
	$args = apply_filters( 'erankly_schema_service_args', array(), $post_id );

	if ( empty( $args ) || ! is_array( $args ) ) {
		return array();
	}

	return erankly_schema_service( $args );
}

/**
 * @param array<int,string>              $names  Block names to match.
 * @return array<int,array<string,mixed>>
 */
function erankly_find_blocks_by_names( array $blocks, array $names ): array {
	$found = array();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( '' !== $block_name && in_array( $block_name, $names, true ) ) {
			$found[] = $block;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, erankly_find_blocks_by_names( $block['innerBlocks'], $names ) );
		}
	}

	return $found;
}

function erankly_schema_plain_text_from_html( string $html ): string {
	return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}
