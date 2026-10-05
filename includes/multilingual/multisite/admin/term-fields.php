<?php
/** Term translation fields on the taxonomy edit screen, with two-sided relationship sync on save. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the edit-form fields and save hooks for every linkable taxonomy that exists on this site. */
function erankly_mlms_admin_register_term_hooks(): void {
	if ( ! erankly_mlms_is_active_for_linking() ) {
		return;
	}

	foreach ( erankly_mlms_get_settings()['taxonomies'] as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		add_action( "{$taxonomy}_edit_form_fields", 'erankly_mlms_render_term_edit_fields', 10, 2 );
		add_action( "edited_{$taxonomy}", 'erankly_mlms_handle_term_save', 10, 2 );
	}
}

/** Renders one translation select per participating site on the term edit form. */
function erankly_mlms_render_term_edit_fields( WP_Term $term, string $taxonomy ): void {
	$own_blog    = get_current_blog_id();
	$map         = erankly_mlms_get_term_translations( $term->term_id, $own_blog );
	$other_sites = array_filter(
		erankly_mlms_get_enabled_sites(),
		static fn( array $site ): bool => $site['blog_id'] !== $own_blog
	);
	?>
	<tr class="form-field term-erankly-mlms-translations-wrap">
		<th scope="row"><span class="erankly-mlms-term-translations-label"><?php esc_html_e( 'Linked translations', 'easyrankly' ); ?></span></th>
		<td>
			<?php wp_nonce_field( 'erankly_mlms_save_translations', 'erankly_mlms_term_translations_nonce' ); ?>
			<p class="description">
				<?php esc_html_e( 'Connect the equivalent term on the other sites of the network. Links are kept in sync on both sides.', 'easyrankly' ); ?>
			</p>
			<?php if ( empty( $other_sites ) ) : ?>
				<p class="description"><?php esc_html_e( 'No other participating site found.', 'easyrankly' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $other_sites as $site ) : ?>
				<?php
				$blog_id  = $site['blog_id'];
				$linked   = (int) ( $map[ $blog_id ] ?? 0 );
				$taxonomy_object = get_taxonomy( $taxonomy );
				$capability = $taxonomy_object instanceof WP_Taxonomy ? $taxonomy_object->cap->manage_terms : 'manage_categories';
				$editable  = erankly_mlms_user_can_edit_site( $blog_id, $capability );
				$field_id  = 'erankly-mlms-term-translation-' . $blog_id;
				?>
				<p class="erankly-mlms-term-field">
					<strong><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $site['name'] ); ?></label></strong>
					<code><?php echo esc_html( $site['hreflang'] ); ?></code>
				</p>
				<?php if ( $editable ) : ?>
					<select name="erankly_mlms_term_translations[<?php echo esc_attr( (string) $blog_id ); ?>]" id="<?php echo esc_attr( $field_id ); ?>" class="widefat erankly-mlms-term-select">
						<option value="0"><?php esc_html_e( '— None —', 'easyrankly' ); ?></option>
						<?php foreach ( erankly_mlms_get_term_choices( $blog_id, $taxonomy, $linked ) as $choice_id => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $choice_id ); ?>" <?php selected( $linked, $choice_id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<p class="description">
						<?php
						$linked_label = $linked > 0 ? erankly_mlms_get_term_title( $blog_id, $taxonomy, $linked ) : '';
						echo '' !== $linked_label
							? esc_html( sprintf( /* translators: %s: linked term label. */ _x( 'Linked to %s (read-only: your account cannot edit that site).', 'linked term', 'easyrankly' ), $linked_label ) )
							: esc_html__( 'Your account cannot edit that site, so no link can be created from here.', 'easyrankly' );
						?>
					</p>
				<?php endif; ?>
			<?php endforeach; ?>
		</td>
	</tr>
	<?php
}

/**
 * Persists the translation links submitted with the term. Only processes requests carrying the term-field
 * payload and nonce, so REST term updates or programmatic wp_update_term() calls can never wipe relationships.
 *
 * @param int $term_id Term ID.
 * @param int $tt_id   Term taxonomy ID.
 */
function erankly_mlms_handle_term_save( int $term_id, int $tt_id ): void {
	unset( $tt_id );

	$nonce = isset( $_POST['erankly_mlms_term_translations_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['erankly_mlms_term_translations_nonce'] ) ) : '';

	if ( '' === $nonce || ! isset( $_POST['erankly_mlms_term_translations'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $nonce, 'erankly_mlms_save_translations' ) ) {
		return;
	}

	$screen   = get_current_screen();
	$taxonomy = $screen instanceof WP_Screen ? (string) $screen->taxonomy : '';
	$settings = erankly_mlms_get_settings();

	if ( '' === $taxonomy || ! in_array( $taxonomy, $settings['taxonomies'], true ) || ! taxonomy_exists( $taxonomy ) ) {
		return;
	}

	$taxonomy_object = get_taxonomy( $taxonomy );

	if ( ! $taxonomy_object instanceof WP_Taxonomy || ! current_user_can( $taxonomy_object->cap->manage_terms ) ) {
		return;
	}

	$own_blog = get_current_blog_id();
	$targets  = erankly_mlms_get_term_translations( $term_id, $own_blog );

	// Values for read-only sites are never submitted (disabled controls): they stay preserved via $targets.
	foreach ( wp_unslash( (array) $_POST['erankly_mlms_term_translations'] ) as $blog_key => $raw ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above. InputNotSanitized -- every value is resolved through erankly_mlms_resolve_term_value().
		$target_blog = absint( $blog_key );

		if ( 0 === $target_blog || $target_blog === $own_blog ) {
			continue;
		}

		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object instanceof WP_Taxonomy || ! erankly_mlms_user_can_edit_site( $target_blog, $taxonomy_object->cap->manage_terms ) ) {
			continue;
		}

		$value = erankly_mlms_resolve_term_value( $target_blog, (string) $raw, $taxonomy );

		if ( 0 === $value ) {
			unset( $targets[ $target_blog ] );
		} else {
			$targets[ $target_blog ] = $value;
		}
	}

	erankly_mlms_set_term_translations( $term_id, $targets );
}
