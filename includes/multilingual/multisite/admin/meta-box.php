<?php
/** "Linked translations" meta box for the post editor, with two-sided relationship sync on save. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the meta box for every linkable post type of the current site (classic editor only: the block editor renders the React "Linked translations" panel instead). */
function erankly_mlms_register_meta_boxes( string $post_type, WP_Post $post ): void {
	unset( $post );

	$screen = get_current_screen();

	if ( $screen instanceof WP_Screen && $screen->is_block_editor() ) {
		return;
	}

	$settings = erankly_mlms_get_settings();

	if ( ! in_array( $post_type, $settings['post_types'], true ) ) {
		return;
	}

	if ( ! erankly_mlms_is_active_for_linking() ) {
		return;
	}

	add_meta_box(
		'erankly-mlms-translations',
		__( 'Linked translations', 'easyrankly' ),
		'erankly_mlms_render_post_meta_box',
		$post_type,
		'side',
		'default',
		array( '__back_compat_meta_box' => false )
	);
}

/** Renders the per-site translation link selects (classic editor, styled with the shared EasyRankly field anatomy). */
function erankly_mlms_render_post_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'erankly_mlms_save_translations', 'erankly_mlms_translations_nonce' );

	$own_blog    = get_current_blog_id();
	$map         = erankly_mlms_get_post_translations( $post->ID, $own_blog );
	$is_front    = (int) get_option( 'page_on_front' ) === (int) $post->ID && 'page' === $post->post_type;
	$other_sites = array_filter(
		erankly_mlms_get_enabled_sites(),
		static fn( array $site ): bool => $site['blog_id'] !== $own_blog
	);
	?>
	<div class="erankly-meta-box erankly-mlms-translations-meta-box">
		<p class="description">
			<?php esc_html_e( 'Connect the equivalent content on the other sites of the network. Links are kept in sync on both sides.', 'easyrankly' ); ?>
		</p>

		<?php if ( $is_front ) : ?>
		<p class="description erankly-mlms-notice">
			<?php esc_html_e( 'This is the static front page: the front pages of the participating sites are always cross-linked automatically.', 'easyrankly' ); ?>
		</p>
		<?php endif; ?>

		<?php if ( empty( $other_sites ) ) : ?>
		<p class="description"><?php esc_html_e( 'No other participating site found.', 'easyrankly' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $other_sites as $site ) : ?>
			<?php
			$blog_id   = $site['blog_id'];
			$linked    = (int) ( $map[ $blog_id ] ?? 0 );
			$editable  = erankly_mlms_user_can_edit_site( $blog_id );
			$field_id  = 'erankly-mlms-translation-' . $blog_id;
			?>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $site['name'] ); ?>
				<code><?php echo esc_html( $site['hreflang'] ); ?></code>
			</label>
			<?php if ( $editable ) : ?>
				<select name="erankly_mlms_translations[<?php echo esc_attr( (string) $blog_id ); ?>]" id="<?php echo esc_attr( $field_id ); ?>" class="widefat">
					<option value="0"><?php esc_html_e( '— None —', 'easyrankly' ); ?></option>
					<?php foreach ( erankly_mlms_get_post_choices( $blog_id, $post->post_type, $linked ) as $choice_id => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $choice_id ); ?>" <?php selected( $linked, $choice_id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<p class="description">
					<?php
					if ( $linked > 0 ) {
						$linked_label = erankly_mlms_get_post_title( $blog_id, $linked );
						$linked_url   = erankly_mlms_resolve_post_url( $blog_id, $linked, true );
						echo '' !== $linked_label
							? esc_html( sprintf( /* translators: %s: linked post label. */ _x( 'Linked to %s (read-only: your account cannot edit that site).', 'linked post', 'easyrankly' ), $linked_label ) )
							: esc_html__( 'A translation exists on that site (read-only: your account cannot edit it).', 'easyrankly' );
						if ( '' !== $linked_url ) {
							printf( ' <a href="%s">%s</a>', esc_url( $linked_url ), esc_html__( 'View', 'easyrankly' ) );
						}
					} else {
						esc_html_e( 'Your account cannot edit that site, so no link can be created from here.', 'easyrankly' );
					}
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Persists the translation links submitted with the post. Only processes requests that actually carry the meta
 * box payload (the block editor's meta-box loader and classic editor saves do; quick-edit, bulk actions and
 * REST saves do not), so unrelated saves can never wipe existing relationships.
 *
 * @param int    $post_id Post ID.
 * @param WP_Post $post   Post object.
 */
function erankly_mlms_handle_post_save( int $post_id, WP_Post $post ): void {
	$nonce = isset( $_POST['erankly_mlms_translations_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['erankly_mlms_translations_nonce'] ) ) : '';

	if ( '' === $nonce || ! isset( $_POST['erankly_mlms_translations'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $nonce, 'erankly_mlms_save_translations' ) ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}

	$settings = erankly_mlms_get_settings();

	if ( ! in_array( $post->post_type, $settings['post_types'], true ) || ! erankly_mlms_is_active_for_linking() ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$own_blog = get_current_blog_id();
	$targets  = erankly_mlms_get_post_translations( $post_id, $own_blog );

	// Values for read-only sites are never submitted (disabled selects): they stay preserved via $targets.
	foreach ( wp_unslash( (array) $_POST['erankly_mlms_translations'] ) as $blog_key => $raw ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is resolved through erankly_mlms_resolve_post_value().
		$target_blog = absint( $blog_key );

		if ( 0 === $target_blog || $target_blog === $own_blog ) {
			continue;
		}

		// Server-side permission enforcement: a crafted submission cannot touch sites the user cannot edit.
		if ( ! erankly_mlms_user_can_edit_site( $target_blog ) ) {
			continue;
		}

		$value = erankly_mlms_resolve_post_value( $target_blog, (string) $raw, $post->post_type );

		if ( 0 === $value ) {
			unset( $targets[ $target_blog ] );
		} else {
			$targets[ $target_blog ] = $value;
		}
	}

	erankly_mlms_set_post_translations( $post_id, $targets );
}
