<?php
/** Post and term translation relationships: per-site meta maps kept consistent on both sides. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a callback in the context of another site, with the global rewrite state temporarily rebuilt for that
 * site. switch_to_blog() re-reads options but does not refresh $wp_rewrite, so URL generators (get_term_link,
 * get_permalink, url_to_postid) would keep the startup site's front, bases and permastructs and produce URLs
 * that 404 on the target site whenever the two sites use different structures. Mirrors the standard Multisite
 * URL-generation dance: switch, rebuild, work, restore, rebuild again.
 *
 * @template T
 * @param callable():T $callback Callback executed in the target site context.
 * @return T The callback return value.
 */
function erankly_mlms_with_site( int $blog_id, callable $callback ): mixed {
	$switched = get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	try {
		return $callback();
	} finally {
		if ( $switched ) {
			restore_current_blog();
			erankly_mlms_rebuild_rewrite_for_current_site();
		}
	}
}

/**
 * Rebuilds the global rewrite state for the current (possibly switched) site. $wp_rewrite->init() re-reads the
 * permalink structure, but the taxonomy permastructs were registered at startup by WP_Taxonomy::
 * add_rewrite_rules() with the startup site's front and bases, so get_term_link() would keep producing URLs of
 * the wrong site. The builtin rewrite slugs are refreshed from the current site's options with the exact
 * defaults of create_initial_taxonomies(), then every taxonomy re-registers its permastruct against the
 * rebuilt front.
 */
function erankly_mlms_rebuild_rewrite_for_current_site(): void {
	global $wp_rewrite;

	$wp_rewrite->init();

	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		return; // Plain permalinks: permastructs are not registered and term links fall back to query strings.
	}

	$category_base = (string) get_option( 'category_base' );
	$tag_base      = (string) get_option( 'tag_base' );

	$category = get_taxonomy( 'category' );

	if ( $category instanceof WP_Taxonomy && is_array( $category->rewrite ) ) {
		$category->rewrite['slug']        = '' !== $category_base ? $category_base : 'category';
		$category->rewrite['with_front']  = '' === $category_base || $wp_rewrite->using_index_permalinks();
		$category->rewrite['hierarchical'] = true;
		$category->add_rewrite_rules();
	}

	$post_tag = get_taxonomy( 'post_tag' );

	if ( $post_tag instanceof WP_Taxonomy && is_array( $post_tag->rewrite ) ) {
		$post_tag->rewrite['slug']       = '' !== $tag_base ? $tag_base : 'tag';
		$post_tag->rewrite['with_front'] = '' === $tag_base || $wp_rewrite->using_index_permalinks();
		$post_tag->add_rewrite_rules();
	}

	// Custom taxonomies registered their permastruct at startup too: re-register them against the rebuilt
	// front. Their slug is a registration constant and does not vary per site.
	foreach ( get_taxonomies( array(), 'objects' ) as $tax_object ) {
		if (
			$tax_object instanceof WP_Taxonomy
			&& is_array( $tax_object->rewrite )
			&& ! in_array( $tax_object->name, array( 'category', 'post_tag' ), true )
		) {
			$tax_object->add_rewrite_rules();
		}
	}
}

/**
 * Returns the linked translations of a post: map of counterpart blog_id => post_id, excluding the post's own site.
 */
function erankly_mlms_get_post_translations( int $post_id, ?int $blog_id = null ): array {
	$blog_id = $blog_id ?? get_current_blog_id();

	return erankly_mlms_cached(
		'post_translations_' . $blog_id . '_' . $post_id,
		static fn(): array => erankly_mlms_normalize_translation_map(
			erankly_mlms_read_meta_map( 'post', $blog_id, $post_id ),
			$blog_id
		)
	);
}

/**
 * Returns the linked translations of a term: map of counterpart blog_id => term_id, excluding the term's own site.
 */
function erankly_mlms_get_term_translations( int $term_id, ?int $blog_id = null ): array {
	$blog_id = $blog_id ?? get_current_blog_id();

	return erankly_mlms_cached(
		'term_translations_' . $blog_id . '_' . $term_id,
		static fn(): array => erankly_mlms_normalize_translation_map(
			erankly_mlms_read_meta_map( 'term', $blog_id, $term_id ),
			$blog_id
		)
	);
}

/**
 * Replaces the translation map of a post and synchronizes every counterpart side. Safe to call from a
 * save_post hook: only meta writes happen on the counterpart sites, so no save_post recursion can occur.
 *
 * @param array<int,int> $targets Counterpart blog_id => post_id.
 */
function erankly_mlms_set_post_translations( int $post_id, array $targets ): void {
	erankly_mlms_set_translations( 'post', $post_id, get_current_blog_id(), $targets );
}

/**
 * Replaces the translation map of a term and synchronizes every counterpart side.
 *
 * @param array<int,int> $targets Counterpart blog_id => term_id.
 */
function erankly_mlms_set_term_translations( int $term_id, array $targets ): void {
	erankly_mlms_set_translations( 'term', $term_id, get_current_blog_id(), $targets );
}

/**
 * Core two-sided synchronization. For every counterpart site whose link changed, the backlink is added or
 * removed on the counterpart object; when a counterpart is reassigned to a different object of the same site,
 * the displaced object's own map is cleaned up so the relationship graph never contains one-way links.
 *
 * Two different "previous" states matter here and must not be conflated. $previous is the state the caller
 * wants the counterparts diffed against; $stored is what the database actually holds right now. They differ on
 * the external-write path: the meta hooks run *after* the foreign write has already been committed, so the row
 * holds the incoming value while $previous still describes the last state this add-on sanctioned. Deciding the
 * write from $previous alone would leave a rejected value sitting in the database whenever the sanitized result
 * happens to equal it -- which is exactly what the capability filter produces when it strips a target.
 *
 * @param array<int,int>      $targets  Counterpart blog_id => object_id.
 * @param array<int,int>|null $previous Previously stored map, when the caller already knows it (external
 *                                      meta writes arrive here after the map has been updated). When omitted,
 *                                      the previous state is read from the stored meta.
 */
function erankly_mlms_set_translations( string $type, int $object_id, int $blog_id, array $targets, ?array $previous = null ): void {
	// Guard against pathological call chains (e.g. another plugin syncing on our meta updates) and against the
	// meta-write hooks re-entering while this function writes its own meta.
	if ( ! empty( $GLOBALS['erankly_mlms_sync_running'] ) || $object_id <= 0 ) {
		return;
	}

	$GLOBALS['erankly_mlms_sync_running'] = true;

	try {
		$stored = erankly_mlms_normalize_translation_map(
			erankly_mlms_read_meta_map( $type, $blog_id, $object_id ),
			$blog_id
		);
		$old    = null !== $previous
			? erankly_mlms_normalize_translation_map( $previous, $blog_id )
			: $stored;
		$new    = erankly_mlms_normalize_translation_map( $targets, $blog_id );

		if ( $old === $new && $stored === $new ) {
			return;
		}

		// Skipped only when the row already holds exactly this map: on the external-write path $stored is the
		// incoming (possibly rejected) value, and rewriting it is what enforces the decision.
		if ( $stored !== $new ) {
			erankly_mlms_write_meta_map( $type, $blog_id, $object_id, $new );
		}

		foreach ( $old as $counterpart_blog => $counterpart_id ) {
			if ( ( $new[ $counterpart_blog ] ?? 0 ) === $counterpart_id ) {
				continue;
			}

			erankly_mlms_update_counterpart_backlink( $type, $counterpart_blog, $counterpart_id, $blog_id, $object_id, false );
		}

		foreach ( $new as $counterpart_blog => $counterpart_id ) {
			if ( ( $old[ $counterpart_blog ] ?? 0 ) === $counterpart_id ) {
				continue;
			}

			erankly_mlms_update_counterpart_backlink( $type, $counterpart_blog, $counterpart_id, $blog_id, $object_id, true );
		}
	} finally {
		$GLOBALS['erankly_mlms_sync_running'] = false;
		erankly_mlms_flush_runtime_caches();
	}
}

/**
 * Adds or removes the backlink pointing at one object from a counterpart object. When linking, a stale
 * backlink to a different object of the same site is displaced: the counterpart now points at the new object
 * and the stale object's own map drops the counterpart.
 *
 * @param bool $link True to add the backlink, false to remove it.
 */
function erankly_mlms_update_counterpart_backlink( string $type, int $counterpart_blog, int $counterpart_id, int $own_blog_id, int $own_id, bool $link ): void {
	if ( $counterpart_blog === $own_blog_id || $counterpart_id <= 0 || $own_id <= 0 ) {
		return;
	}

	$previous = 0;
	$switched = get_current_blog_id() !== $counterpart_blog;

	if ( $switched ) {
		switch_to_blog( $counterpart_blog );
	}

	try {
		$exists = 'post' === $type
			? null !== get_post( $counterpart_id )
			: null !== get_term( $counterpart_id );

		if ( ! $exists ) {
			return;
		}

		$map = erankly_mlms_normalize_translation_map( erankly_mlms_read_current_meta_map( $type, $counterpart_id ), $counterpart_blog );
		$changed = false;

		if ( $link ) {
			$previous = (int) ( $map[ $own_blog_id ] ?? 0 );

			if ( $previous === $own_id ) {
				return;
			}

			$map[ $own_blog_id ] = $own_id;
			$changed = true;
		} else {
			if ( ! isset( $map[ $own_blog_id ] ) ) {
				return;
			}

			unset( $map[ $own_blog_id ] );
			$changed = true;
		}

		if ( $changed ) {
			erankly_mlms_write_current_meta_map( $type, $counterpart_id, $map );
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	// A displaced stale link must not linger on the object that lost the counterpart. The stale object lives on
	// the same site as the object being linked, so the cleanup writes there.
	if ( $link && $previous > 0 && $previous !== $own_id ) {
		$stale_map = 'post' === $type
			? erankly_mlms_get_post_translations( $previous, $own_blog_id )
			: erankly_mlms_get_term_translations( $previous, $own_blog_id );

		if ( ( $stale_map[ $counterpart_blog ] ?? 0 ) === $counterpart_id ) {
			unset( $stale_map[ $counterpart_blog ] );
			erankly_mlms_write_meta_map( $type, $own_blog_id, $previous, $stale_map );
		}
	}
}

/**
 * Removes every link pointing at a deleted post from its counterpart objects. Runs on before_delete_post,
 * which fires while the post's own meta is still readable.
 */
function erankly_mlms_handle_post_deletion( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	$GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( 'post', $blog_id, $post_id ) ] = true;

	$was_syncing = ! empty( $GLOBALS['erankly_mlms_sync_running'] );
	$GLOBALS['erankly_mlms_sync_running'] = true;
	try {
		foreach ( erankly_mlms_get_post_translations( $post_id, $blog_id ) as $counterpart_blog => $counterpart_id ) {
			erankly_mlms_update_counterpart_backlink( 'post', $counterpart_blog, $counterpart_id, $blog_id, $post_id, false );
		}
	} finally {
		$GLOBALS['erankly_mlms_sync_running'] = $was_syncing;
	}

}

/**
 * Removes every link pointing at a deleted term from its counterpart objects. Runs on pre_delete_term, which
 * fires at the start of wp_delete_term() while the term's own meta is still readable (the later delete_term
 * action fires after the meta has been deleted).
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 */
function erankly_mlms_handle_term_deletion( int $term_id, string $taxonomy ): void {
	unset( $taxonomy );

	$blog_id = get_current_blog_id();
	$GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( 'term', $blog_id, $term_id ) ] = true;

	$was_syncing = ! empty( $GLOBALS['erankly_mlms_sync_running'] );
	$GLOBALS['erankly_mlms_sync_running'] = true;
	try {
		foreach ( erankly_mlms_get_term_translations( $term_id, $blog_id ) as $counterpart_blog => $counterpart_id ) {
			erankly_mlms_update_counterpart_backlink( 'term', $counterpart_blog, $counterpart_id, $blog_id, $term_id, false );
		}
	} finally {
		$GLOBALS['erankly_mlms_sync_running'] = $was_syncing;
	}

}

/**
 * Snapshots the previous post translation map before an external write reaches the database.
 *
 * @param int|int[]  $meta_id    Meta ID (array for deletions).
 * @param int        $object_id  Post ID.
 * @param string     $meta_key   Meta key.
 * @param mixed|null $meta_value New value (writes only).
 */
function erankly_mlms_snapshot_post_meta_before_write( $meta_id, int $object_id, string $meta_key, $meta_value = null ): void {
	unset( $meta_id, $meta_value );

	erankly_mlms_snapshot_meta_before_write( 'post', $object_id, $meta_key );
}

/**
 * Snapshots the previous term translation map before an external write reaches the database.
 *
 * @param int|int[]  $meta_id    Meta ID (array for deletions).
 * @param int        $object_id  Term ID.
 * @param string     $meta_key   Meta key.
 * @param mixed|null $meta_value New value (writes only).
 */
function erankly_mlms_snapshot_term_meta_before_write( $meta_id, int $object_id, string $meta_key, $meta_value = null ): void {
	unset( $meta_id, $meta_value );

	erankly_mlms_snapshot_meta_before_write( 'term', $object_id, $meta_key );
}

/**
 * Records the map an object carried before an external write lands. The update_*_meta/delete_*_meta actions fire
 * before the write, while the old value is still readable; the matching added/updated/deleted handlers then
 * synchronize the counterparts against this snapshot.
 *
 * Skipped while the add-on is writing its own meta: those writes fire the same actions, and the resulting
 * snapshots would never be consumed (the write handlers bail on the same guard). Left unguarded they accumulate
 * for the rest of the request and can be picked up by a later genuine write to the same object.
 */
function erankly_mlms_snapshot_meta_before_write( string $type, int $object_id, string $meta_key ): void {
	if ( erankly_mlms_meta_key_for( $type ) !== $meta_key || $object_id <= 0 || ! empty( $GLOBALS['erankly_mlms_sync_running'] )
		|| ! empty( $GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( $type, get_current_blog_id(), $object_id ) ] ) ) {
		return;
	}

	if ( ! isset( $GLOBALS['erankly_mlms_meta_snapshots'] ) || ! is_array( $GLOBALS['erankly_mlms_meta_snapshots'] ) ) {
		$GLOBALS['erankly_mlms_meta_snapshots'] = array();
	}

	$blog_id = get_current_blog_id();

	$GLOBALS['erankly_mlms_meta_snapshots'][ erankly_mlms_snapshot_key( $type, $blog_id, $object_id ) ] = 'post' === $type
		? erankly_mlms_get_post_translations( $object_id, $blog_id )
		: erankly_mlms_get_term_translations( $object_id, $blog_id );
}

/**
 * @param int    $meta_id    Meta ID.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value New value.
 */
function erankly_mlms_handle_post_meta_write( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
	unset( $meta_id );

	erankly_mlms_handle_meta_write( 'post', $object_id, $meta_key, $meta_value );
}

/**
 * @param int    $meta_id    Meta ID.
 * @param int    $object_id  Term ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value New value.
 */
function erankly_mlms_handle_term_meta_write( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
	unset( $meta_id );

	erankly_mlms_handle_meta_write( 'term', $object_id, $meta_key, $meta_value );
}

/**
 * Synchronizes the counterpart sites after an external write of a translation map (the block editor panel saves
 * through the post meta API, and code or WP-CLI can reach either map directly), bypassing the editor save
 * handlers. Targets the current user cannot edit keep their previous value instead of being applied, mirroring
 * the read-only behavior of the editor UIs.
 *
 * The rejected value is already committed to the row by the time this runs, so the sanitized map is handed to
 * erankly_mlms_set_translations() as the authoritative state: it compares against the stored row and rewrites it.
 */
function erankly_mlms_handle_meta_write( string $type, int $object_id, string $meta_key, $meta_value ): void {
	if ( erankly_mlms_meta_key_for( $type ) !== $meta_key || $object_id <= 0 || ! empty( $GLOBALS['erankly_mlms_sync_running'] )
		|| ! empty( $GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( $type, get_current_blog_id(), $object_id ) ] ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	$old     = erankly_mlms_take_meta_snapshot( $type, $blog_id, $object_id );
	$new     = erankly_mlms_normalize_translation_map( is_array( $meta_value ) ? $meta_value : array(), $blog_id );

	// Targets the user cannot edit keep their previous value; the rest are applied as written.
	$filtered = erankly_mlms_user_editable_targets( $type, $new )
		+ array_diff_key( $old, erankly_mlms_user_editable_targets( $type, $old ) );
	ksort( $filtered );

	erankly_mlms_set_translations( $type, $object_id, $blog_id, $filtered, $old );
}

/**
 * @param int|int[] $meta_ids  Meta IDs.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 */
function erankly_mlms_handle_post_meta_delete( $meta_ids, int $object_id, string $meta_key ): void {
	unset( $meta_ids );

	erankly_mlms_handle_meta_delete( 'post', $object_id, $meta_key );
}

/**
 * @param int|int[] $meta_ids  Meta IDs.
 * @param int       $object_id Term ID.
 * @param string    $meta_key  Meta key.
 */
function erankly_mlms_handle_term_meta_delete( $meta_ids, int $object_id, string $meta_key ): void {
	unset( $meta_ids );

	erankly_mlms_handle_meta_delete( 'term', $object_id, $meta_key );
}

/**
 * Synchronizes the counterpart sites after an external deletion of a translation map: an emptied map removes
 * every backlink, exactly like unlinking in the editor. Targets the current user cannot edit survive the
 * deletion, which also restores the row that the deletion already removed.
 */
function erankly_mlms_handle_meta_delete( string $type, int $object_id, string $meta_key ): void {
	if ( erankly_mlms_meta_key_for( $type ) !== $meta_key || $object_id <= 0 || ! empty( $GLOBALS['erankly_mlms_sync_running'] )
		|| ! empty( $GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( $type, get_current_blog_id(), $object_id ) ] ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	$old     = erankly_mlms_take_meta_snapshot( $type, $blog_id, $object_id );

	erankly_mlms_set_translations(
		$type,
		$object_id,
		$blog_id,
		array_diff_key( $old, erankly_mlms_user_editable_targets( $type, $old ) ),
		$old
	);
}

/** Returns the meta key holding the translation map of one object type. */
function erankly_mlms_meta_key_for( string $type ): string {
	return 'post' === $type ? ERANKLY_MLMS_POST_META_KEY : ERANKLY_MLMS_TERM_META_KEY;
}

/** Builds the request-scoped snapshot key for one object. Post and term IDs share a namespace, so type leads. */
function erankly_mlms_snapshot_key( string $type, int $blog_id, int $object_id ): string {
	return $type . ':' . $blog_id . ':' . $object_id;
}

/** Returns and clears the pre-write snapshot for one object, defaulting to an empty map. */
function erankly_mlms_take_meta_snapshot( string $type, int $blog_id, int $object_id ): array {
	$key = erankly_mlms_snapshot_key( $type, $blog_id, $object_id );

	if ( isset( $GLOBALS['erankly_mlms_meta_snapshots'] ) && is_array( $GLOBALS['erankly_mlms_meta_snapshots'] ) && array_key_exists( $key, $GLOBALS['erankly_mlms_meta_snapshots'] ) ) {
		$snapshot = $GLOBALS['erankly_mlms_meta_snapshots'][ $key ];
		unset( $GLOBALS['erankly_mlms_meta_snapshots'][ $key ] );

		return is_array( $snapshot ) ? $snapshot : array();
	}

	return array();
}

/**
 * Keeps only the targets the current user may edit. Used to enforce the per-site editing capabilities on writes
 * that reach the meta rows without passing through an editor screen.
 *
 * @param array<int,int> $map Counterpart blog_id => object_id.
 * @return array<int,int> The editable subset.
 */
function erankly_mlms_user_editable_targets( string $type, array $map ): array {
	$editable = array();

	foreach ( $map as $counterpart_blog => $counterpart_id ) {
		if ( erankly_mlms_user_can_edit_object( $type, $counterpart_blog, $counterpart_id ) ) {
			$editable[ $counterpart_blog ] = $counterpart_id;
		}
	}

	return $editable;
}

/**
 * Returns whether the current user can edit one specific object on a site (switched capability check). Terms are
 * gated on the taxonomy's own manage_terms capability, which is what the term edit screen enforces.
 */
function erankly_mlms_user_can_edit_object( string $type, int $blog_id, int $object_id ): bool {
	if ( $object_id <= 0 ) {
		return false;
	}

	return (bool) erankly_mlms_cached(
		'can_edit_object_' . $type . '_' . $blog_id . '_' . $object_id,
		static function () use ( $type, $blog_id, $object_id ): bool {
			$switched = get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			try {
				if ( 'post' === $type ) {
					return null !== get_post( $object_id ) && current_user_can( 'edit_post', $object_id );
				}

				$term = get_term( $object_id );

				if ( ! $term instanceof WP_Term ) {
					return false;
				}

				$taxonomy = get_taxonomy( $term->taxonomy );

				return $taxonomy instanceof WP_Taxonomy && current_user_can( $taxonomy->cap->manage_terms );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	);
}

/**
 * Resolves a raw editor value (numeric ID or full URL) into a post ID on the target site. The post must exist,
 * match the expected post type and carry a workable status.
 */
function erankly_mlms_resolve_post_value( int $blog_id, string $raw, string $post_type ): int {
	$raw = trim( $raw );

	if ( '' === $raw || $blog_id === get_current_blog_id() ) {
		return 0;
	}

	return (int) erankly_mlms_with_site(
		$blog_id,
		static function () use ( $raw, $post_type ): int {
			$post_id = ctype_digit( $raw ) ? absint( $raw ) : erankly_mlms_post_id_from_url( $raw );

			if ( $post_id <= 0 ) {
				return 0;
			}

			$post = get_post( $post_id );

			if (
				! $post instanceof WP_Post
				|| $post->post_type !== $post_type
				|| in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true )
			) {
				return 0;
			}

			return (int) $post->ID;
		}
	);
}

/**
 * Resolves a raw editor value (numeric ID) into a term ID on the target site. Terms are picked from a select,
 * so only numeric identifiers are accepted.
 */
function erankly_mlms_resolve_term_value( int $blog_id, string $raw, string $taxonomy ): int {
	$raw = trim( $raw );

	if ( '' === $raw || ! ctype_digit( $raw ) || $blog_id === get_current_blog_id() ) {
		return 0;
	}

	return (int) erankly_mlms_with_site(
		$blog_id,
		static function () use ( $raw, $taxonomy ): int {
			$term = get_term( absint( $raw ) );

			if ( ! $term instanceof WP_Term || $term->taxonomy !== $taxonomy ) {
				return 0;
			}

			return (int) $term->term_id;
		}
	);
}

/**
 * Resolves the canonical URL of a linked post on its own site. Only published posts of linkable post types are
 * eligible; for the hreflang set, noindexed posts are skipped (the navigable set keeps them).
 *
 * @return string Absolute URL, or '' when the post is unavailable for the requested set.
 */
function erankly_mlms_resolve_post_url( int $blog_id, int $post_id, bool $navigable ): string {
	return erankly_mlms_cached(
		'post_url_' . $blog_id . '_' . $post_id . '_' . ( $navigable ? 'navigable' : 'hreflang' ),
		static function () use ( $blog_id, $post_id, $navigable ): string {
			if ( $post_id <= 0 ) {
				return '';
			}

			return (string) erankly_mlms_with_site(
				$blog_id,
				static function () use ( $post_id, $navigable ): string {
					$url = '';
					$post = get_post( $post_id );

					if (
						$post instanceof WP_Post
						&& 'publish' === $post->post_status
						&& in_array( $post->post_type, erankly_mlms_get_settings()['post_types'], true )
						&& ( $navigable || ! erankly_mlms_post_is_noindexed( $post ) )
					) {
						$permalink = get_permalink( $post );
						$url       = is_string( $permalink ) ? esc_url_raw( $permalink ) : '';
					}

					return $url;
				}
			);
		}
	);
}

/**
 * Resolves the URL of a linked term on its own site, mirroring erankly_mlms_resolve_post_url().
 *
 * @return string Absolute URL, or '' when the term is unavailable for the requested set.
 */
function erankly_mlms_resolve_term_url( int $blog_id, int $term_id, bool $navigable ): string {
	return erankly_mlms_cached(
		'term_url_' . $blog_id . '_' . $term_id . '_' . ( $navigable ? 'navigable' : 'hreflang' ),
		static function () use ( $blog_id, $term_id, $navigable ): string {
			if ( $term_id <= 0 ) {
				return '';
			}

			return (string) erankly_mlms_with_site(
				$blog_id,
				static function () use ( $term_id, $navigable ): string {
					$url  = '';
					$term = get_term( $term_id );

					if (
						$term instanceof WP_Term
						&& in_array( $term->taxonomy, erankly_mlms_get_settings()['taxonomies'], true )
						&& ( $navigable || ! erankly_mlms_term_is_noindexed( $term ) )
					) {
						$link = get_term_link( $term );
						$url  = $link instanceof WP_Error ? '' : esc_url_raw( (string) $link );
					}

					return $url;
				}
			);
		}
	);
}

/**
 * Mirrors EasyRankly's per-object noindex resolution (post meta plus the global post type directive). Degrades
 * to "not noindexed" when the host plugin's helpers are unavailable, so the add-on never suppresses output it
 * cannot verify.
 */
function erankly_mlms_post_is_noindexed( WP_Post $post ): bool {
	return erankly_object_seo_state_is_noindex( 'post', $post->ID, $post->post_type );
}

/** Uses the same tri-state robots policy as core. */
function erankly_mlms_term_is_noindexed( WP_Term $term ): bool {
	return erankly_object_seo_state_is_noindex( 'term', $term->term_id, $term->taxonomy );
}

/**
 * Lists the posts of one post type on a target site for the editor select. The currently linked post is merged
 * on top even when it falls outside the most-recent window.
 *
 * @return array<int,string> Map of post ID to option label.
 */
function erankly_mlms_get_post_choices( int $blog_id, string $post_type, int $force_id = 0 ): array {
	$choices = erankly_mlms_cached(
		'post_choices_' . $blog_id . '_' . $post_type,
		static fn(): array => erankly_mlms_query_post_choices( $blog_id, $post_type )
	);

	if ( $force_id > 0 ) {
		$forced = erankly_mlms_get_post_title( $blog_id, $force_id );

		if ( '' !== $forced ) {
			$choices[ $force_id ] = $forced;
		}
	}

	return $choices;
}

/**
 * Lists the terms of one taxonomy on a target site for the editor select, with the same merged-inclusion rule.
 *
 * @return array<int,string> Map of term ID to option label.
 */
function erankly_mlms_get_term_choices( int $blog_id, string $taxonomy, int $force_id = 0 ): array {
	$choices = erankly_mlms_cached(
		'term_choices_' . $blog_id . '_' . $taxonomy,
		static fn(): array => erankly_mlms_query_term_choices( $blog_id, $taxonomy )
	);

	if ( $force_id > 0 ) {
		$forced = erankly_mlms_get_term_title( $blog_id, $taxonomy, $force_id );

		if ( '' !== $forced ) {
			$choices[ $force_id ] = $forced;
		}
	}

	return $choices;
}

/** Returns whether the current user can edit content on a site (site membership applies on Multisite). */
function erankly_mlms_user_can_edit_site( int $blog_id, string $capability = 'edit_posts' ): bool {
	return (bool) erankly_mlms_cached(
		'can_edit_' . $blog_id . '_' . $capability,
		static function () use ( $blog_id, $capability ): bool {
			$switched = get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			try {
				return current_user_can( $capability );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	);
}

/** @return array<int,int> Normalized counterpart map: blog_id => object_id, own site excluded. */
function erankly_mlms_normalize_translation_map( array $raw, int $own_blog_id ): array {
	$map = array();

	foreach ( $raw as $blog_id => $object_id ) {
		$blog_id   = absint( $blog_id );
		$object_id = absint( $object_id );

		if ( 0 === $blog_id || 0 === $object_id || $blog_id === $own_blog_id ) {
			continue;
		}

		$map[ $blog_id ] = $object_id;
	}

	ksort( $map );

	return $map;
}

/** Reads a raw meta map, switching to the target site when necessary. */
function erankly_mlms_read_meta_map( string $type, int $blog_id, int $object_id ): array {
	if ( $object_id <= 0 ) {
		return array();
	}

	$switched = get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	$raw = erankly_mlms_read_current_meta_map( $type, $object_id );

	if ( $switched ) {
		restore_current_blog();
	}

	return is_array( $raw ) ? $raw : array();
}

/**
 * Reads the raw meta map in the current site context. A missing meta row reads as an empty string through
 * get_post_meta()/get_term_meta(), so the result is always coerced to an array here.
 *
 * @return array<mixed>
 */
function erankly_mlms_read_current_meta_map( string $type, int $object_id ): array {
	if ( $object_id <= 0 ) {
		return array();
	}

	$raw = 'post' === $type
		? get_post_meta( $object_id, ERANKLY_MLMS_POST_META_KEY, true )
		: get_term_meta( $object_id, ERANKLY_MLMS_TERM_META_KEY, true );

	return is_array( $raw ) ? $raw : array();
}

/** Writes a normalized meta map on any site, deleting the meta entirely when the map is empty. */
function erankly_mlms_write_meta_map( string $type, int $blog_id, int $object_id, array $map ): void {
	$switched = get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	erankly_mlms_write_current_meta_map( $type, $object_id, erankly_mlms_normalize_translation_map( $map, $blog_id ) );

	if ( $switched ) {
		restore_current_blog();
	}
}

/** Writes the normalized meta map in the current site context. */
function erankly_mlms_write_current_meta_map( string $type, int $object_id, array $map ): void {
	if ( $object_id <= 0 ) {
		return;
	}

	if ( 'post' === $type ) {
		empty( $map )
			? delete_post_meta( $object_id, ERANKLY_MLMS_POST_META_KEY )
			: update_post_meta( $object_id, ERANKLY_MLMS_POST_META_KEY, $map );

		return;
	}

	empty( $map )
		? delete_term_meta( $object_id, ERANKLY_MLMS_TERM_META_KEY )
		: update_term_meta( $object_id, ERANKLY_MLMS_TERM_META_KEY, $map );
}

/** Builds the "#ID — Title" option label for a post on any site. */
function erankly_mlms_get_post_title( int $blog_id, int $post_id ): string {
	return (string) erankly_mlms_cached(
		'post_title_' . $blog_id . '_' . $post_id,
		static function () use ( $blog_id, $post_id ): string {
			$switched = get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			try {
				$post = get_post( $post_id );

				if ( ! $post instanceof WP_Post ) {
					return '';
				}

				$title = '' !== trim( (string) $post->post_title )
					? (string) $post->post_title
					: __( '(no title)', 'easyrankly' );

				return sprintf( '#%d — %s', (int) $post->ID, $title );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	);
}

/** Builds the "#ID — Name" option label for a term on any site. */
function erankly_mlms_get_term_title( int $blog_id, string $taxonomy, int $term_id ): string {
	return (string) erankly_mlms_cached(
		'term_title_' . $blog_id . '_' . $taxonomy . '_' . $term_id,
		static function () use ( $blog_id, $taxonomy, $term_id ): string {
			$switched = get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			try {
				$term = get_term( $term_id );

				if ( ! $term instanceof WP_Term || $term->taxonomy !== $taxonomy ) {
					return '';
				}

				return sprintf( '#%d — %s', (int) $term->term_id, (string) $term->name );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	);
}

/** Queries the recent published posts of one post type on a target site for the editor select. */
function erankly_mlms_query_post_choices( int $blog_id, string $post_type ): array {
	$choices = array();
	$switched = get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		$limit = (int) apply_filters( 'erankly_mlms_meta_box_post_choices', 200, $blog_id, $post_type );

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'numberposts'            => max( 1, $limit ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			$title = '' !== trim( (string) $post->post_title )
				? (string) $post->post_title
				: __( '(no title)', 'easyrankly' );

			$choices[ (int) $post->ID ] = sprintf( '#%d — %s', (int) $post->ID, $title );
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $choices;
}

/** Queries the terms of one taxonomy on a target site for the editor select. */
function erankly_mlms_query_term_choices( int $blog_id, string $taxonomy ): array {
	$choices = array();
	$switched = get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		$limit = (int) apply_filters( 'erankly_mlms_meta_box_term_choices', 200, $blog_id, $taxonomy );

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => max( 1, $limit ),
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$choices[ (int) $term->term_id ] = sprintf( '#%d — %s', (int) $term->term_id, (string) $term->name );
			}
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $choices;
}

/**
 * Maps a pasted URL to a post ID on the current (already switched) site. Falls back to the static front page
 * when the URL is the site home, which url_to_postid() intentionally reports as 0.
 */
function erankly_mlms_post_id_from_url( string $url ): int {
	$post_id = (int) url_to_postid( $url );

	if ( $post_id > 0 ) {
		return $post_id;
	}

	$home   = untrailingslashit( (string) home_url() );
	$target = untrailingslashit( esc_url_raw( $url ) );

	if ( '' !== $home && $home === $target ) {
		return (int) get_option( 'page_on_front' );
	}

	return 0;
}

/** Clears deletion guards and cached maps after WordPress removes the object. */
function erankly_mlms_finish_post_deletion( int $id ): void {
	unset( $GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( 'post', get_current_blog_id(), $id ) ] );
	erankly_mlms_flush_runtime_caches();
}

function erankly_mlms_finish_term_deletion( int $id ): void {
	unset( $GLOBALS['erankly_mlms_deleting'][ erankly_mlms_snapshot_key( 'term', get_current_blog_id(), $id ) ] );
	erankly_mlms_flush_runtime_caches();
}
