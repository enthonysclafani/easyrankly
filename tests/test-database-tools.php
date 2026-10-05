<?php
/** Integration tests for the destructive database-maintenance boundary; never run against the live site. */
final class ERankly_Database_Tools_Test extends WP_UnitTestCase {
	private int $admin_id;
	private bool $external_cache;

	public function set_up(): void {
		parent::set_up();
		require_once ERANKLY_PATH . 'includes/class-erankly-database-tools.php';
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$this->external_cache = (bool) wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
	}

	public function tear_down(): void {
		delete_transient( 'erankly_tools_preview_' . $this->admin_id );
		wp_using_ext_object_cache( $this->external_cache );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	private function call( string $mode, array $args = array() ): array|WP_Error {
		return ERankly_Database_Tools::dispatch( array_merge( array( 'mode' => $mode, 'nonce' => wp_create_nonce( 'erankly_database_tools' ) ), $args ) );
	}

	private function preview( array $categories ): array {
		$result = $this->call( 'preview', array( 'categories' => $categories ) );
		$this->assertNotWPError( $result );
		return $result;
	}

	private function drain( string $token ): array {
		$cursor = 0;
		do {
			$result = $this->call( 'clean', array( 'token' => $token, 'confirmed' => '1', 'offset' => $cursor ) );
			$this->assertNotWPError( $result );
			$this->assertGreaterThanOrEqual( $cursor, $result['cursor'] );
			$cursor = $result['cursor'];
		} while ( ! $result['done'] );
		return $result;
	}

	private function post( array $args = array(), int $days = 45 ): int {
		$date = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return self::factory()->post->create( array_merge( array( 'post_title' => 'Old test post', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => $date, 'post_date_gmt' => $date, 'post_modified' => $date, 'post_modified_gmt' => $date ), $args ) );
	}

	private function orphan( string $type, string $key = 'tools_test_orphan', int $object_id = 987650000 ): int {
		global $wpdb;
		$table  = _get_meta_table( $type );
		$this->assertNotFalse( $wpdb->insert( $table, array( $type . '_id' => $object_id, 'meta_key' => $key, 'meta_value' => 'private fixture value' ) ) );
		return (int) $wpdb->insert_id;
	}

	private function expired( string $name ): void {
		add_option( '_transient_' . $name, 'private cached fixture', '', false );
		add_option( '_transient_timeout_' . $name, time() - HOUR_IN_SECONDS, '', false );
	}

	private function old_comment( int $post, string $status = 'spam', array $args = array() ): int {
		$id = self::factory()->comment->create( array_merge( array( 'comment_post_ID' => $post, 'comment_approved' => $status, 'comment_type' => 'comment', 'comment_content' => 'Old test comment' ), $args ) );
		add_comment_meta( $id, '_wp_trash_meta_time', time() - 45 * DAY_IN_SECONDS );
		return $id;
	}

	public function test_analysis_is_read_only_even_for_expired_transients(): void {
		$this->expired( 'tools_test_read_only' );
		$mid = $this->orphan( 'post' );
		$result = $this->call( 'scan' );
		$this->assertNotWPError( $result );
		$this->assertGreaterThan( 0, $result['categories']['expired_transients']['count'] );
		$this->assertSame( 'private cached fixture', get_option( '_transient_tools_test_read_only' ) );
		$this->assertNotFalse( get_metadata_by_mid( 'post', $mid ) );
		$this->assertFalse( get_option( '_transient_erankly_tools_preview_' . $this->admin_id ) );
	}

	public function test_expired_cache_is_removed_but_active_and_non_expiring_cache_are_preserved(): void {
		$this->expired( 'tools_test_expired' );
		set_transient( 'tools_test_active', 'active fixture', DAY_IN_SECONDS );
		set_transient( 'tools_test_permanent', 'permanent fixture' );
		$preview = $this->preview( array( 'expired_transients' ) );
		$result = $this->drain( $preview['token'] );
		$this->assertSame( 1, $result['results'][0]['removed'] );
		$this->assertFalse( get_option( '_transient_tools_test_expired' ) );
		$this->assertFalse( get_option( '_transient_timeout_tools_test_expired' ) );
		$this->assertSame( 'active fixture', get_transient( 'tools_test_active' ) );
		$this->assertSame( 'permanent fixture', get_transient( 'tools_test_permanent' ) );
	}

	public function test_cache_renewed_after_preview_is_skipped(): void {
		$this->expired( 'tools_test_renewed' );
		$preview = $this->preview( array( 'expired_transients' ) );
		set_transient( 'tools_test_renewed', 'fresh cache', DAY_IN_SECONDS );
		$result = $this->drain( $preview['token'] );
		$this->assertSame( 1, $result['results'][0]['skipped'] );
		$this->assertSame( 'fresh cache', get_transient( 'tools_test_renewed' ) );
	}

	public function test_database_cache_is_disabled_with_external_object_cache(): void {
		$this->expired( 'tools_test_external' );
		wp_using_ext_object_cache( true );
		$scan = $this->call( 'scan' );
		$this->assertFalse( $scan['categories']['expired_transients']['available'] );
		$this->assertWPError( $this->call( 'preview', array( 'categories' => array( 'expired_transients' ) ) ) );
		$this->assertSame( 'private cached fixture', get_option( '_transient_tools_test_external' ) );
	}

	public function test_only_genuinely_orphaned_metadata_is_removed(): void {
		$post = $this->post();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );
		add_post_meta( $post, '_erankly_title', 'preserved SEO title' );
		add_comment_meta( $comment, 'akismet_history', 'preserved history' );
		add_user_meta( $this->admin_id, 'tools_test_user', 'preserved user setting' );
		$ids = array( 'post' => $this->orphan( 'post' ), 'comment' => $this->orphan( 'comment' ), 'user' => $this->orphan( 'user' ) );
		$categories = array( 'orphan_postmeta', 'orphan_commentmeta' );
		if ( ! is_multisite() ) $categories[] = 'orphan_usermeta';
		$this->drain( $this->preview( $categories )['token'] );
		$this->assertFalse( get_metadata_by_mid( 'post', $ids['post'] ) );
		$this->assertFalse( get_metadata_by_mid( 'comment', $ids['comment'] ) );
		$this->assertSame( is_multisite(), (bool) get_metadata_by_mid( 'user', $ids['user'] ) );
		$this->assertSame( 'preserved SEO title', get_post_meta( $post, '_erankly_title', true ) );
		$this->assertSame( 'preserved history', get_comment_meta( $comment, 'akismet_history', true ) );
		$this->assertSame( 'preserved user setting', get_user_meta( $this->admin_id, 'tools_test_user', true ) );
	}

	public function test_metadata_with_a_restored_parent_is_skipped(): void {
		global $wpdb;
		$mid = $this->orphan( 'post' );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$id = $this->post();
		$post = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $id ), ARRAY_A );
		$post['ID'] = 987650000;
		$wpdb->insert( $wpdb->posts, $post );
		$result = $this->drain( $preview['token'] );
		$this->assertSame( 1, $result['results'][0]['skipped'] );
		$this->assertNotFalse( get_metadata_by_mid( 'post', $mid ) );
	}

	public function test_changed_metadata_is_skipped_and_values_are_never_exposed(): void {
		$mid = $this->orphan( 'post' );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$this->assertStringNotContainsString( 'private fixture value', wp_json_encode( $preview ) );
		$this->assertStringNotContainsString( 'fingerprint', wp_json_encode( $preview ) );
		update_metadata_by_mid( 'post', $mid, 'changed after review' );
		$result = $this->drain( $preview['token'] );
		$this->assertSame( 1, $result['results'][0]['skipped'] );
		$this->assertSame( 'changed after review', get_metadata_by_mid( 'post', $mid )->meta_value );
	}

	public function test_parent_restored_in_a_metadata_hook_is_preserved_by_the_atomic_delete(): void {
		global $wpdb;
		$mid = $this->orphan( 'post' );
		$id = $this->post();
		$template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $id ), ARRAY_A );
		$template['ID'] = 987650000;
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$restore = static function () use ( $wpdb, $template ) { $wpdb->insert( $wpdb->posts, $template ); };
		add_action( 'delete_post_meta', $restore );
		try { $this->drain( $preview['token'] ); }
		finally { remove_action( 'delete_post_meta', $restore ); }
		$this->assertNotFalse( get_metadata_by_mid( 'post', $mid ) );
	}

	public function test_revision_cleanup_keeps_five_latest_revisions_and_all_autosaves(): void {
		$post = $this->post();
		$revisions = array();
		for ( $i = 0; $i < 8; ++$i ) {
			$revisions[] = $this->post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post . '-revision-' . $i ) );
		}
		$autosave = $this->post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post . '-autosave-v1' ) );
		add_metadata( 'post', $revisions[0], 'tools_test_revision_meta', 'old value' );
		$preview = $this->preview( array( 'revisions' ) );
		$this->assertSame( 3, $preview['count'] );
		$this->assertSame( 1, $preview['groups'][0]['metadata'] );
		$this->drain( $preview['token'] );
		foreach ( array_slice( $revisions, 0, 3 ) as $id ) $this->assertNull( get_post( $id ) );
		foreach ( array_slice( $revisions, 3 ) as $id ) $this->assertNotNull( get_post( $id ) );
		$this->assertNotNull( get_post( $autosave ) );
		$this->assertNotNull( get_post( $post ) );
		$this->assertSame( '', get_post_meta( $revisions[0], 'tools_test_revision_meta', true ) );
	}

	public function test_revision_newly_in_the_five_to_keep_is_skipped(): void {
		$post = $this->post();
		$ids = array();
		for ( $i = 0; $i < 6; ++$i ) $ids[] = $this->post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_name' => $post . '-revision-' . $i ) );
		$preview = $this->preview( array( 'revisions' ) );
		wp_delete_post_revision( $ids[5] );
		$result = $this->drain( $preview['token'] );
		$this->assertSame( 1, $result['results'][0]['skipped'] );
		$this->assertNotNull( get_post( $ids[0] ) );
	}

	public function test_only_empty_old_auto_drafts_are_eligible(): void {
		$old = $this->post( array( 'post_status' => 'auto-draft', 'post_content' => '' ) );
		$keep = array(
			$this->post( array( 'post_status' => 'draft', 'post_content' => '' ) ),
			$this->post( array( 'post_status' => 'auto-draft', 'post_content' => 'Unsaved content' ) ),
			$this->post( array( 'post_status' => 'auto-draft', 'post_content' => '' ), 1 ),
		);
		$preview = $this->preview( array( 'auto_drafts' ) );
		$this->assertSame( 1, $preview['count'] );
		$this->drain( $preview['token'] );
		$this->assertNull( get_post( $old ) );
		foreach ( $keep as $id ) $this->assertNotNull( get_post( $id ) );
	}

	public function test_active_editor_lock_protects_old_content(): void {
		$post = $this->post( array( 'post_status' => 'auto-draft', 'post_content' => '' ) );
		update_post_meta( $post, '_edit_lock', time() . ':' . $this->admin_id );
		$this->assertSame( 0, $this->preview( array( 'auto_drafts' ) )['count'] );
		$this->assertNotNull( get_post( $post ) );
	}

	public function test_trash_cleanup_preserves_recent_trash_media_custom_types_and_related_content(): void {
		$old = $this->post( array( 'post_status' => 'trash' ) );
		add_post_meta( $old, '_wp_trash_meta_time', time() - 45 * DAY_IN_SECONDS );
		$revision = $this->post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $old ) );
		$keep = array();
		foreach ( array( array( 'post_status' => 'trash' ), array( 'post_type' => 'attachment', 'post_status' => 'trash' ), array( 'post_type' => 'product', 'post_status' => 'trash' ), array( 'post_status' => 'trash' ), array( 'post_status' => 'trash' ), array( 'post_status' => 'trash' ) ) as $args ) {
			$id = $this->post( $args );
			add_post_meta( $id, '_wp_trash_meta_time', time() - 45 * DAY_IN_SECONDS );
			$keep[] = $id;
		}
		update_post_meta( $keep[0], '_wp_trash_meta_time', time() - DAY_IN_SECONDS );
		self::factory()->comment->create( array( 'comment_post_ID' => $keep[3] ) );
		$attachment = $this->post( array( 'post_type' => 'attachment', 'post_parent' => $keep[4], 'post_status' => 'inherit' ) );
		$child = $this->post( array( 'post_type' => 'page', 'post_parent' => $keep[5] ) );
		$preview = $this->preview( array( 'trashed_posts' ) );
		$this->assertSame( 1, $preview['count'] );
		$this->assertSame( 1, $preview['groups'][0]['revisions'] );
		$this->drain( $preview['token'] );
		$this->assertNull( get_post( $old ) );
		$this->assertNull( get_post( $revision ) );
		foreach ( $keep as $id ) $this->assertNotNull( get_post( $id ) );
		$this->assertSame( $keep[4], (int) get_post( $attachment )->post_parent );
		$this->assertSame( $keep[5], (int) get_post( $child )->post_parent );
	}

	public function test_changed_related_metadata_or_new_comments_skip_the_parent(): void {
		$post = $this->post( array( 'post_status' => 'trash' ) );
		add_post_meta( $post, '_wp_trash_meta_time', time() - 45 * DAY_IN_SECONDS );
		$preview = $this->preview( array( 'trashed_posts' ) );
		add_post_meta( $post, '_erankly_title', 'Updated SEO title' );
		$this->assertSame( 1, $this->drain( $preview['token'] )['results'][0]['skipped'] );
		$preview = $this->preview( array( 'trashed_posts' ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post ) );
		$this->assertSame( 1, $this->drain( $preview['token'] )['results'][0]['skipped'] );
		$this->assertNotNull( get_post( $post ) );
	}

	public function test_comment_cleanup_requires_verified_old_status_and_protects_replies_and_special_types(): void {
		$post = $this->post();
		$spam = $this->old_comment( $post );
		$trash = $this->old_comment( $post, 'trash' );
		$keep = array(
			$this->old_comment( $post, '0' ),
			$this->old_comment( $post, '1' ),
			$this->old_comment( $post, 'spam', array( 'comment_type' => 'order_note' ) ),
			$this->old_comment( $post, 'spam', array( 'comment_type' => 'pingback' ) ),
			$this->old_comment( $post ),
			$this->old_comment( $post ),
			$this->old_comment( $post ),
			$this->old_comment( $this->post( array( 'post_type' => 'product' ) ) ),
		);
		delete_comment_meta( $keep[4], '_wp_trash_meta_time' );
		update_comment_meta( $keep[5], '_wp_trash_meta_time', time() - DAY_IN_SECONDS );
		$reply = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_parent' => $keep[6], 'comment_approved' => '1' ) );
		$preview = $this->preview( array( 'spam_comments', 'trashed_comments' ) );
		$this->assertSame( 2, $preview['count'] );
		$this->drain( $preview['token'] );
		$this->assertNull( get_comment( $spam ) );
		$this->assertNull( get_comment( $trash ) );
		foreach ( $keep as $id ) $this->assertNotNull( get_comment( $id ) );
		$this->assertSame( $keep[6], (int) get_comment( $reply )->comment_parent );
	}

	public function test_restored_comment_is_skipped(): void {
		$post = $this->post();
		$comment = $this->old_comment( $post );
		$preview = $this->preview( array( 'spam_comments' ) );
		wp_unspam_comment( $comment );
		$this->assertSame( 1, $this->drain( $preview['token'] )['results'][0]['skipped'] );
		$this->assertNotNull( get_comment( $comment ) );
	}

	public function test_permission_nonce_method_confirmation_and_category_allowlist(): void {
		$mid = $this->orphan( 'post' );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $preview['token'], 'offset' => 0 ) ) );
		$this->assertWPError( $this->call( 'scan', array( 'nonce' => 'invalid' ) ) );
		$this->assertWPError( ERankly_Database_Tools::dispatch( array( 'mode' => 'scan', 'nonce' => wp_create_nonce( 'erankly_database_tools' ) ), 'GET' ) );
		$this->assertWPError( $this->call( 'preview', array( 'categories' => array( 'optimize_tables' ) ) ) );
		$this->assertWPError( $this->call( 'preview', array( 'categories' => array( array( 'orphan_postmeta' ) ) ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertWPError( $this->call( 'scan' ) );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $preview['token'], 'confirmed' => '1', 'offset' => 0 ) ) );
		$this->assertNotFalse( get_metadata_by_mid( 'post', $mid ) );
	}

	public function test_snapshot_is_bound_to_user_and_latest_preview(): void {
		$this->orphan( 'post' );
		$first = $this->preview( array( 'orphan_postmeta' ) );
		$second = $this->preview( array( 'orphan_postmeta' ) );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $first['token'], 'confirmed' => '1', 'offset' => 0 ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $second['token'], 'confirmed' => '1', 'offset' => 0 ) ) );
	}

	public function test_expired_preview_and_cancelled_preview_cannot_delete(): void {
		$mid = $this->orphan( 'post' );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$key = 'erankly_tools_preview_' . $this->admin_id;
		$snapshot = get_transient( $key );
		$snapshot['expires'] = time() - 1;
		set_transient( $key, $snapshot, MINUTE_IN_SECONDS );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $preview['token'], 'confirmed' => '1', 'offset' => 0 ) ) );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$this->assertNotWPError( $this->call( 'cancel', array( 'token' => $preview['token'] ) ) );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $preview['token'], 'confirmed' => '1', 'offset' => 0 ) ) );
		$this->assertNotFalse( get_metadata_by_mid( 'post', $mid ) );
	}

	public function test_batches_are_bounded_retries_idempotent_and_new_items_excluded(): void {
		$ids = array();
		for ( $i = 0; $i < 25; ++$i ) $ids[] = $this->orphan( 'post', 'tools_test_' . $i );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$new_id = $this->orphan( 'post', 'tools_test_added_after_preview' );
		$args = array( 'token' => $preview['token'], 'confirmed' => '1', 'offset' => 0, 'ids' => array( $new_id ) );
		$first = $this->call( 'clean', $args );
		$this->assertSame( 20, $first['cursor'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( $first, $this->call( 'clean', $args ) );
		$this->assertWPError( $this->call( 'clean', array_merge( $args, array( 'offset' => 100 ) ) ) );
		$last = $this->call( 'clean', array_merge( $args, array( 'offset' => 20 ) ) );
		$this->assertTrue( $last['done'] );
		$this->assertSame( 25, $last['results'][0]['removed'] );
		$this->assertNotFalse( get_metadata_by_mid( 'post', $new_id ) );
	}

	public function test_preview_has_a_global_limit_and_discloses_remaining_items(): void {
		global $wpdb;
		for ( $i = 0; $i < 501; ++$i ) $wpdb->insert( $wpdb->postmeta, array( 'post_id' => 987650000, 'meta_key' => 'tools_test_limit', 'meta_value' => 'fixture' ) );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$this->assertSame( 500, $preview['count'] );
		$this->assertTrue( $preview['limited'] );
		$this->assertCount( 500, $preview['groups'][0]['items'] );
	}

	public function test_core_deletion_filters_are_respected_and_failure_reported(): void {
		$post = $this->post( array( 'post_status' => 'auto-draft', 'post_content' => '' ) );
		$preview = $this->preview( array( 'auto_drafts' ) );
		$block = static fn() => false;
		add_filter( 'pre_delete_post', $block );
		try { $result = $this->drain( $preview['token'] ); }
		finally { remove_filter( 'pre_delete_post', $block ); }
		$this->assertSame( 1, $result['results'][0]['failed'] );
		$this->assertNotNull( get_post( $post ) );
	}

	public function test_content_restored_by_a_pre_deletion_hook_is_skipped(): void {
		$post = $this->post( array( 'post_status' => 'auto-draft', 'post_content' => '' ) );
		$preview = $this->preview( array( 'auto_drafts' ) );
		$restore = static function ( $id ) use ( $post ) {
			if ( $id === $post ) wp_update_post( array( 'ID' => $post, 'post_status' => 'draft' ) );
		};
		add_action( 'before_delete_post', $restore );
		try { $result = $this->drain( $preview['token'] ); }
		finally { remove_action( 'before_delete_post', $restore ); }
		$this->assertSame( 1, $result['results'][0]['skipped'] );
		$this->assertSame( 'draft', get_post_status( $post ) );
	}

	public function test_comment_restored_by_a_pre_deletion_hook_is_skipped(): void {
		global $wpdb;
		$comment = $this->old_comment( $this->post() );
		$preview = $this->preview( array( 'spam_comments' ) );
		$restore = static function ( $id ) use ( $wpdb, $comment ) {
			if ( (int) $id === $comment ) $wpdb->update( $wpdb->comments, array( 'comment_approved' => '1' ), array( 'comment_ID' => $comment ) );
		};
		add_action( 'delete_comment', $restore );
		try { $result = $this->drain( $preview['token'] ); }
		finally { remove_action( 'delete_comment', $restore ); }
		$this->assertSame( 1, $result['results'][0]['skipped'] );
		$this->assertNotNull( get_comment( $comment ) );
	}

	public function test_metadata_deletion_filters_are_respected(): void {
		$mid = $this->orphan( 'post' );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		$block = static fn() => false;
		add_filter( 'delete_post_metadata_by_mid', $block );
		try { $result = $this->drain( $preview['token'] ); }
		finally { remove_filter( 'delete_post_metadata_by_mid', $block ); }
		$this->assertSame( 1, $result['results'][0]['failed'] );
		$this->assertNotFalse( get_metadata_by_mid( 'post', $mid ) );
	}

	public function test_multisite_excludes_shared_data_and_rejects_foreign_site_snapshots(): void {
		if ( ! is_multisite() ) $this->markTestSkipped( 'Multisite only.' );
		$this->assertFalse( ERankly_Database_Tools::categories()['orphan_usermeta']['available'] );
		$this->assertWPError( $this->call( 'preview', array( 'categories' => array( 'orphan_usermeta' ) ) ) );
		set_site_transient( 'tools_test_shared_cache', 'network cache', DAY_IN_SECONDS );
		$this->orphan( 'post' );
		$preview = $this->preview( array( 'orphan_postmeta' ) );
		// A snapshot copied into another site's options must also fail its explicit blog binding.
		$key = 'erankly_tools_preview_' . $this->admin_id;
		$snapshot = get_transient( $key );
		$snapshot['blog'] = get_current_blog_id() + 1;
		set_transient( $key, $snapshot, MINUTE_IN_SECONDS );
		$this->assertWPError( $this->call( 'clean', array( 'token' => $preview['token'], 'confirmed' => '1', 'offset' => 0 ) ) );
		$this->assertSame( 'network cache', get_site_transient( 'tools_test_shared_cache' ) );
	}

	public function test_tools_routing_sidebar_and_assets_are_site_scoped(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ERANKLY_PATH . 'includes/admin.php';
		erankly_admin_load_settings_modules();
		erankly_admin_load_tools_module();
		set_current_screen( 'settings_page_erankly' );
		$_GET['erankly_tab'] = 'tools';
		try {
			$page = erankly_resolve_settings_page_state( erankly_get_settings() );
			$this->assertSame( 'settings-tools', $page['active_panel'] );
			$this->assertFalse( $page['show_settings_submit'] );
			$this->assertSame( array( 'tabs', 'tools' ), erankly_admin_asset_modules( 'settings:tools' ) );
			ob_start();
			erankly_admin_render_settings_page();
			$html = ob_get_clean();
			$this->assertStringContainsString( 'data-erankly-database-tools', $html );
			$this->assertStringContainsString( 'data-tools-acknowledge', $html );
			$this->assertStringNotContainsString( 'data-erankly-settings-autosave', $html );
		} finally { unset( $_GET['erankly_tab'] ); }
	}
}
