<?php
/** Native classic/block editor metabox and taxonomy controls; no editor framework bundle. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_editor_boot(): void {
	add_action( 'add_meta_boxes', 'erankly_mlss_add_meta_box', 10, 2 );
	add_action( 'save_post', 'erankly_mlss_editor_save_post', 20, 2 );
	add_action( 'created_term', 'erankly_mlss_editor_save_term', 20, 3 );
	add_action( 'edited_term', 'erankly_mlss_editor_save_term', 20, 3 );
	add_action( 'admin_init', 'erankly_mlss_editor_taxonomies' );
	add_action( 'admin_enqueue_scripts', 'erankly_mlss_editor_assets' );
	add_action( 'restrict_manage_posts', 'erankly_mlss_list_filter' );
	add_action( 'pre_get_posts', 'erankly_mlss_admin_query' );
	add_action( 'admin_notices', static function (): void {
		$key = 'erankly_multilingual_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	} );
}

function erankly_mlss_add_meta_box( string $type, WP_Post $post ): void {
	$screen = get_current_screen();
	if ( $screen && $screen->is_block_editor() ) { return; }
	if ( '' === erankly_mlss_object_subtype( 'post', $post->ID ) ) { return; }
	add_meta_box( 'erankly-mlss-translations', __( 'Multilingual', 'easyrankly' ), static fn( WP_Post $post ) => erankly_mlss_render_translation_fields( 'post', $post->ID, $post->post_type ), $type, 'side', 'default', array( '__block_editor_compatible_meta_box' => true ) );
}

function erankly_mlss_editor_taxonomies(): void {
	foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
		if ( 'post_format' === $taxonomy ) { continue; }
		add_action( "{$taxonomy}_edit_form_fields", static function ( WP_Term $term ) use ( $taxonomy ): void {
			echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Multilingual', 'easyrankly' ) . '</th><td>';
			erankly_mlss_render_translation_fields( 'term', $term->term_id, $taxonomy );
			echo '</td></tr>';
		} );
		add_action( "{$taxonomy}_add_form_fields", static function () use ( $taxonomy ): void { erankly_mlss_render_translation_fields( 'term', 0, $taxonomy ); } );
	}
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		if ( 'attachment' === $type ) { continue; }
		add_filter( "manage_{$type}_posts_columns", static function ( array $columns ): array { $columns['erankly_language'] = __( 'Language', 'easyrankly' ); return $columns; } );
		add_action( "manage_{$type}_posts_custom_column", static function ( string $column, int $id ): void { if ( 'erankly_language' === $column ) { echo esc_html( erankly_mlss_get_language( 'post', $id ) ); } }, 10, 2 );
	}
}

function erankly_mlss_render_translation_fields( string $kind, int $id, string $subtype ): void {
	$settings = erankly_mlss_get_settings();
	$language = $id ? erankly_mlss_get_language( $kind, $id ) : $settings['default_language'];
	$map = $id ? erankly_mlss_get_translations( $kind, $id ) : array();
	wp_nonce_field( 'erankly_mlss_editor', 'erankly_mlss_editor_nonce' );
	printf( '<div class="erankly-mlss-editor" data-kind="%s" data-id="%d" data-subtype="%s">', esc_attr( $kind ), $id, esc_attr( $subtype ) );
	printf( '<p><label for="erankly-mlss-own-language">%s</label><select class="widefat" id="erankly-mlss-own-language" name="erankly_mlss_language">', esc_html__( 'Content language', 'easyrankly' ) );
	foreach ( $settings['languages'] as $tag ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $tag ), selected( $language, $tag, false ), esc_html( $tag ) ); }
	echo '</select></p>';
	if ( $id ) {
		echo '<p class="description">' . esc_html__( 'Find an existing translation or create a copy to translate. Article copies remain drafts.', 'easyrankly' ) . '</p>';
		foreach ( $settings['languages'] as $tag ) {
			$target = (int) ( $map[ $tag ] ?? 0 );
			$can_read = $target && ( 'post' === $kind ? current_user_can( 'read_post', $target ) : current_user_can( 'edit_term', $target ) );
			$term = 'term' === $kind && $target ? get_term( $target ) : null;
			$title = $can_read && $target !== $id ? ( 'post' === $kind ? get_the_title( $target ) : ( $term instanceof WP_Term ? $term->name : '' ) ) : '';
			printf( '<div class="erankly-mlss-row" data-language="%s" %s><label for="erankly-mlss-search-%s">%s</label><input type="hidden" name="erankly_mlss_links[%s]" value="%d"><input type="search" class="widefat erankly-mlss-search" id="erankly-mlss-search-%s" value="%s" autocomplete="off"><div class="erankly-mlss-results" role="group" aria-label="%s"></div><p>', esc_attr( $tag ), $tag === $language ? 'hidden' : '', esc_attr( $tag ), esc_html( $tag ), esc_attr( $tag ), $target, esc_attr( $tag ), esc_attr( $title ), esc_attr__( 'Translation suggestions', 'easyrankly' ) );
			if ( $target && $target !== $id && current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $target ) ) {
				$url = 'post' === $kind ? get_edit_post_link( $target, 'raw' ) : get_edit_term_link( $target, $subtype );
				printf( '<a class="button erankly-mlss-edit" href="%s">%s</a> ', esc_url( $url ), esc_html__( 'Edit translation', 'easyrankly' ) );
			}
			printf( '<button type="button" class="button erankly-mlss-create" %s>%s</button> <button type="button" class="button-link erankly-mlss-unlink">%s</button></p></div>', $target && $target !== $id ? 'hidden' : '', esc_html__( 'Create translation', 'easyrankly' ), esc_html__( 'Disconnect', 'easyrankly' ) );
		}
		printf( '<p><button type="button" class="button button-primary erankly-mlss-save">%s</button></p>', esc_html__( 'Save translation links', 'easyrankly' ) );
	}
	echo '<p class="erankly-mlss-status" role="status" aria-live="polite"></p></div>';
}

function erankly_mlss_editor_save( string $kind, int $id ): void {
	$nonce = isset( $_POST['erankly_mlss_editor_nonce'] ) && is_string( $_POST['erankly_mlss_editor_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['erankly_mlss_editor_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'erankly_mlss_editor' ) || ! current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $id ) || ! isset( $_POST['erankly_mlss_language'] ) || ! is_string( $_POST['erankly_mlss_language'] ) ) { return; }
	$language = erankly_mlss_language_tag( wp_unslash( $_POST['erankly_mlss_language'] ) );
	$map = array();
	foreach ( (array) wp_unslash( $_POST['erankly_mlss_links'] ?? array() ) as $tag => $target ) {
		if ( is_scalar( $target ) && absint( $target ) && absint( $target ) !== $id ) { $map[ $tag ] = absint( $target ); }
	}
	if ( isset( $map[ $language ] ) ) { return; }
	$map[ $language ] = $id;
	$result = erankly_mlss_set_translations( $kind, $id, $map );
	if ( is_wp_error( $result ) ) { set_transient( 'erankly_multilingual_notice_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS ); }
}
function erankly_mlss_editor_save_post( int $id, WP_Post $post ): void {
	if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || '' === erankly_mlss_object_subtype( 'post', $id ) ) { return; }
	erankly_mlss_editor_save( 'post', $id );
}
function erankly_mlss_editor_save_term( int $id, int $tt_id, string $taxonomy ): void { erankly_mlss_editor_save( 'term', $id ); }

function erankly_mlss_editor_assets(): void {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->base, array( 'post', 'term', 'edit-tags' ), true ) ) { return; }
	if ( $screen->post_type && ( ! get_post_type_object( $screen->post_type )->public || 'attachment' === $screen->post_type ) ) { return; }
	$block_editor = $screen->is_block_editor();
	$dependencies = $block_editor ? array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data' ) : array();
	wp_enqueue_script( 'erankly-mlss-editor', ERANKLY_URL . 'assets/multilingual/singlesite/editor.js', $dependencies, ERANKLY_VERSION, true );
	wp_localize_script( 'erankly-mlss-editor', 'eranklyMLSS', array( 'postId' => $block_editor && get_post() ? get_post()->ID : 0, 'panelTitle' => __( 'Multilingual', 'easyrankly' ), 'loading' => __( 'Loading translations…', 'easyrankly' ), 'url' => rest_url( 'erankly/v1/multilingual/singlesite/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'saved' => __( 'Translation links saved.', 'easyrankly' ), 'error' => __( 'The operation could not be completed.', 'easyrankly' ), 'edit' => __( 'Edit translation', 'easyrankly' ), 'busy' => __( 'Saving…', 'easyrankly' ), 'conflict' => __( 'Disconnect the existing translation before assigning its language to this content.', 'easyrankly' ) ) );
	wp_enqueue_style( 'erankly-mlss-editor', ERANKLY_URL . 'assets/multilingual/singlesite/editor.css', array(), ERANKLY_VERSION );
}

function erankly_mlss_list_filter(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'attachment' === $screen->post_type || ! get_post_type_object( $screen->post_type )->public ) { return; }
	echo '<label class="screen-reader-text" for="erankly-list-language">' . esc_html__( 'Language', 'easyrankly' ) . '</label><select id="erankly-list-language" name="erankly_lang"><option value="all">' . esc_html__( 'All languages', 'easyrankly' ) . '</option>';
	foreach ( erankly_mlss_get_settings()['languages'] as $language ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $language ), selected( $_GET['erankly_lang'] ?? 'all', $language, false ), esc_html( $language ) ); }
	echo '</select>';
}
function erankly_mlss_admin_query( WP_Query $query ): void {
	if ( is_admin() && $query->is_main_query() && isset( $_GET['erankly_lang'] ) && is_string( $_GET['erankly_lang'] ) ) { $query->set( 'erankly_lang', sanitize_text_field( wp_unslash( $_GET['erankly_lang'] ) ) ); }
}
