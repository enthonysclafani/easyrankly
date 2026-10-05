<?php
/**
 * Classic-editor meta box and taxonomy form fields (Gutenberg renders the React panels instead). The
 * erankly_render_post_*_fields() functions are shared with the block-editor sidebar (assets/js/editor.js);
 * erankly_render_advanced_robots_fields() and erankly_render_social_meta_fields() are shared with term forms.
 * Saves live in meta-box/saver.php.
 */
defined( 'ABSPATH' ) || exit;
require_once ERANKLY_PATH . 'admin/field-renderers.php';
require_once ERANKLY_PATH . 'admin/settings/section-links.php';
require_once ERANKLY_PATH . 'admin/meta-box/saver.php';
function erankly_register_meta_box(): void {
	$screen = get_current_screen();
	if ( $screen instanceof WP_Screen && $screen->is_block_editor() ) {
		return;
	}
	foreach ( erankly_get_public_post_types() as $post_type => $object ) {
		if ( ! $object->show_ui ) {
			continue;
		}
		add_meta_box(
			'erankly',
			__( 'EasyRankly', 'easyrankly' ),
			'erankly_render_meta_box',
			$post_type,
			'normal',
			'default'
		);
	}
}
function erankly_register_taxonomy_fields(): void {
	foreach ( erankly_get_public_taxonomies() as $taxonomy => $object ) {
		if ( ! $object->show_ui ) {
			continue;
		}
		add_action( $taxonomy . '_add_form_fields', 'erankly_render_add_term_fields' );
		add_action( $taxonomy . '_edit_form_fields', 'erankly_render_edit_term_fields' );
		add_action( 'created_' . $taxonomy, 'erankly_save_term_fields' );
		add_action( 'edited_' . $taxonomy, 'erankly_save_term_fields' );
	}
}
/**
 * Renders a text input, or a textarea when $rows is set, with the variable picker and a live character counter.
 *
 * @param array<string,mixed> $examples Variable picker examples.
 */
function erankly_render_counted_variable_field( string $id, string $name, string $label, string $value, int $limit, array $examples, int $rows = 0, string $counter_id = '' ): void {
	$counter_id = '' !== $counter_id ? $counter_id : $id . '-counter';
	?>
	<div class="erankly-field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<?php if ( $rows > 0 ) : ?>
			<textarea id="<?php echo esc_attr( $id ); ?>" class="widefat erankly-counted-field" rows="<?php echo esc_attr( (string) $rows ); ?>" name="<?php echo esc_attr( $name ); ?>" data-erankly-limit="<?php echo esc_attr( (string) $limit ); ?>" data-erankly-counter="<?php echo esc_attr( $counter_id ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
			<input id="<?php echo esc_attr( $id ); ?>" class="widefat erankly-counted-field" type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" data-erankly-limit="<?php echo esc_attr( (string) $limit ); ?>" data-erankly-counter="<?php echo esc_attr( $counter_id ); ?>" data-erankly-warning="<?php esc_attr_e( 'recommended max', 'easyrankly' ); ?>">
			<?php endif; ?>
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
		<span id="<?php echo esc_attr( $counter_id ); ?>" class="erankly-character-counter" aria-live="polite"></span>
	</div>
	<?php
}
/**
 * Renders the Open Graph and X fields shared by the classic post meta box and the taxonomy forms. Element ids are
 * $id_prefix . field . $id_suffix, so post and term screens keep their own stable ids.
 *
 * @param array<string,string> $values   Stored values keyed by meta key without the `_erankly_` prefix.
 * @param array<string,mixed>  $examples Variable picker examples.
 */
function erankly_render_social_meta_fields( string $id_prefix, string $id_suffix, array $values, array $examples ): void {
	$text_fields = array(
		'og_title'            => array( 'og-title', __( 'Open Graph title', 'easyrankly' ), 60, 0 ),
		'og_description'      => array( 'og-description', __( 'Open Graph description', 'easyrankly' ), 200, 3 ),
		'twitter_title'       => array( 'twitter-title', __( 'X (Twitter) title', 'easyrankly' ), 70, 0 ),
		'twitter_description' => array( 'twitter-description', __( 'X (Twitter) description', 'easyrankly' ), 200, 3 ),
	);
	foreach ( $text_fields as $key => list( $slug, $label, $limit, $rows ) ) {
		erankly_render_counted_variable_field(
			$id_prefix . $slug . $id_suffix,
			'erankly_' . $key,
			$label,
			$values[ $key ] ?? '',
			$limit,
			$examples,
			$rows,
			$id_prefix . $slug . '-counter' . $id_suffix
		);
	}
	$card_id = $id_prefix . 'twitter-card-type' . $id_suffix;
	$card    = $values['twitter_card_type'] ?? '';
	$images  = array(
		'og'      => array( 'og-image-url', 'social-image-alt', __( 'Open Graph image URL', 'easyrankly' ), __( 'Social image alt text', 'easyrankly' ) ),
		'twitter' => array( 'twitter-image-url', 'twitter-image-alt', __( 'X (Twitter) image URL', 'easyrankly' ), __( 'X image alt text override', 'easyrankly' ) ),
	);
	?>
	<div class="erankly-field">
		<label for="<?php echo esc_attr( $card_id ); ?>"><?php esc_html_e( 'X (Twitter) card type', 'easyrankly' ); ?></label>
		<select id="<?php echo esc_attr( $card_id ); ?>" class="widefat" name="erankly_twitter_card_type">
			<option value="" <?php selected( $card, '' ); ?>><?php esc_html_e( 'Automatic (large image when available)', 'easyrankly' ); ?></option>
			<option value="summary" <?php selected( $card, 'summary' ); ?>><?php esc_html_e( 'Summary', 'easyrankly' ); ?></option>
			<option value="summary_large_image" <?php selected( $card, 'summary_large_image' ); ?>><?php esc_html_e( 'Summary with large image', 'easyrankly' ); ?></option>
		</select>
	</div>
	<?php foreach ( $images as $network => list( $url_slug, $alt_slug, $url_label, $alt_label ) ) : ?>
	<div class="erankly-field">
		<label for="<?php echo esc_attr( $id_prefix . $url_slug . $id_suffix ); ?>"><?php echo esc_html( $url_label ); ?></label>
		<?php erankly_render_media_url_field( $id_prefix . $url_slug . $id_suffix, 'erankly_' . $network . '_image_url', $values[ $network . '_image_url' ] ?? '' ); ?>
		<label for="<?php echo esc_attr( $id_prefix . $alt_slug . $id_suffix ); ?>"><?php echo esc_html( $alt_label ); ?></label>
		<input id="<?php echo esc_attr( $id_prefix . $alt_slug . $id_suffix ); ?>" class="widefat" type="text" name="erankly_<?php echo esc_attr( $network ); ?>_image_alt" value="<?php echo esc_attr( $values[ $network . '_image_alt' ] ?? '' ); ?>">
	</div>
	<?php endforeach; ?>
	<?php
}
/**
 * Reads the stored social values for one post or term, keyed as erankly_render_social_meta_fields() expects.
 *
 * @return array<string,string>
 */
function erankly_get_social_meta_values( string $object_type, int $object_id ): array {
	$values = array();
	foreach ( array( 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'twitter_card_type', 'og_image_url', 'og_image_alt', 'twitter_image_url', 'twitter_image_alt' ) as $key ) {
		$value          = $object_id > 0 ? get_metadata( $object_type, $object_id, '_erankly_' . $key, true ) : '';
		$values[ $key ] = is_string( $value ) ? trim( $value ) : '';
	}

	return $values;
}
function erankly_render_post_general_fields( WP_Post $post ): void {
	$examples = erankly_get_admin_variable_examples( $post );
	erankly_render_counted_variable_field( 'erankly-title', 'erankly_title', __( 'Meta title', 'easyrankly' ), erankly_get_post_meta_string( $post->ID, 'title' ), 65, $examples );
	erankly_render_counted_variable_field( 'erankly-description', 'erankly_description', __( 'Meta description', 'easyrankly' ), erankly_get_post_meta_string( $post->ID, 'description' ), 160, $examples, 3 );
	?>
	<div class="erankly-field">
		<label for="erankly-canonical"><?php esc_html_e( 'Canonical URL', 'easyrankly' ); ?></label>
		<div class="erankly-variable-field" data-erankly-variable-field>
			<input id="erankly-canonical" class="widefat" type="text" name="erankly_canonical" value="<?php echo esc_attr( erankly_get_post_meta_string( $post->ID, 'canonical' ) ); ?>">
			<?php erankly_render_variable_picker( $examples ); ?>
		</div>
	</div>
	<?php if ( (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) : ?>
	<div class="erankly-field">
		<label for="erankly-breadcrumb-name"><?php esc_html_e( 'Breadcrumb name', 'easyrankly' ); ?></label>
		<input id="erankly-breadcrumb-name" class="widefat" type="text" name="erankly_breadcrumb_name" value="<?php echo esc_attr( erankly_get_post_meta_string( $post->ID, 'breadcrumb_name' ) ); ?>" maxlength="120">
	</div>
	<?php endif; ?>
	<?php
	$primary_terms = get_post_meta( $post->ID, '_erankly_primary_terms', true );
	$primary_terms = is_array( $primary_terms ) ? $primary_terms : array();
	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $tax_object ) :
		if ( ! $tax_object->public || ! has_term( '', $taxonomy, $post ) ) {
			continue;
		}
		$primary_select_id = 'erankly-primary-' . sanitize_html_class( $taxonomy );
		?>
		<div class="erankly-field">
			<?php /* translators: %s: singular taxonomy label, for example Category. */ ?>
			<label for="<?php echo esc_attr( $primary_select_id ); ?>"><?php echo esc_html( sprintf( __( 'Primary %s', 'easyrankly' ), $tax_object->labels->singular_name ) ); ?></label>
		<?php
		wp_dropdown_categories(
			array(
				'taxonomy'          => $taxonomy,
				'name'              => 'erankly_primary_terms[' . $taxonomy . ']',
				'id'                => $primary_select_id,
				'class'             => 'widefat',
				'selected'          => isset( $primary_terms[ $taxonomy ] ) ? absint( $primary_terms[ $taxonomy ] ) : 0,
				'show_option_none'  => __( 'Automatic', 'easyrankly' ),
				'option_none_value' => 0,
				'hide_empty'        => false,
			)
		);
		?>
		</div>
	<?php endforeach; ?>
	<?php do_action( 'erankly_post_general_fields_after', $post ); ?>
	<?php
}
function erankly_render_post_social_fields( WP_Post $post ): void {
	erankly_render_social_meta_fields( 'erankly-', '', erankly_get_social_meta_values( 'post', $post->ID ), erankly_get_admin_variable_examples( $post ) );
	do_action( 'erankly_post_social_fields_after', $post );
}
function erankly_render_post_visibility_fields( WP_Post $post ): void {
	erankly_render_advanced_robots_fields( erankly_get_object_robots_field_values( 'post', $post->ID ) );
	?>
	<div class="erankly-field erankly-checkboxes" role="group" aria-labelledby="erankly-post-archives-label">
		<span class="erankly-field-label" id="erankly-post-archives-label"><?php esc_html_e( 'Archives', 'easyrankly' ); ?></span>
		<label><input type="checkbox" class="erankly-toggle" name="erankly_exclude_search" value="1" <?php checked( erankly_get_post_meta_bool( $post->ID, 'exclude_search' ) ); ?>> <?php esc_html_e( 'Exclude from site search', 'easyrankly' ); ?></label>
		<label><input type="checkbox" class="erankly-toggle" name="erankly_exclude_archive" value="1" <?php checked( erankly_get_post_meta_bool( $post->ID, 'exclude_archive' ) ); ?>> <?php esc_html_e( 'Exclude from archives', 'easyrankly' ); ?></label>
	</div>
	<?php if ( (bool) erankly_get_setting( 'enable_news_sitemap', 0 ) ) : ?>
	<div class="erankly-field erankly-checkboxes">
		<label><input type="checkbox" class="erankly-toggle" name="erankly_exclude_from_news" value="1" <?php checked( erankly_get_post_meta_bool( $post->ID, 'exclude_from_news' ) ); ?>> <?php esc_html_e( 'Exclude this page from Google News sitemap', 'easyrankly' ); ?></label>
	</div>
	<?php endif; ?>
	<?php
}
/**
 * Reads the per-object robots values edited by erankly_render_advanced_robots_fields().
 *
 * @param string $object_type `post` or `term`.
 * @return array<string,mixed>
 */
function erankly_get_object_robots_field_values( string $object_type, int $object_id ): array {
	$values = array();
	foreach ( array( 'index', 'follow', 'archive', 'snippet', 'image' ) as $axis ) {
		$values[ $axis . '_directive' ] = $object_id > 0 ? erankly_get_object_robots_directive( $object_type, $object_id, $axis ) : 'inherit';
	}
	foreach ( array( 'max_snippet', 'max_video_preview', 'max_image_preview', 'indexifembedded' ) as $key ) {
		$values[ $key ] = $object_id > 0 ? get_metadata( $object_type, $object_id, '_erankly_' . $key, true ) : '';
	}
	$values['disable_sitemap'] = $object_id > 0 && '1' === (string) get_metadata( $object_type, $object_id, '_erankly_disable_sitemap', true );

	return $values;
}
function erankly_render_robots_directive_select( string $name, string $value, string $label, string $allow_label, string $deny_label ): void {
	$axis  = str_replace( array( 'erankly_', '_directive' ), '', $name );
	$id    = 'erankly-' . str_replace( '_', '-', $axis ) . '-directive';
	$allow = array(
		'index'   => 'index',
		'follow'  => 'follow',
		'archive' => 'archive',
		'snippet' => 'snippet',
		'image'   => 'imageindex',
	);
	$deny  = array(
		'index'   => 'noindex',
		'follow'  => 'nofollow',
		'archive' => 'noarchive',
		'snippet' => 'nosnippet',
		'image'   => 'noimageindex',
	);
	?>
	<div class="erankly-field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<select id="<?php echo esc_attr( $id ); ?>" class="widefat" name="<?php echo esc_attr( $name ); ?>">
			<option value="inherit" <?php selected( $value, 'inherit' ); ?>><?php esc_html_e( 'Inherit', 'easyrankly' ); ?></option>
			<option value="<?php echo esc_attr( $allow[ $axis ] ); ?>" <?php selected( $value, $allow[ $axis ] ); ?>><?php echo esc_html( $allow_label ); ?></option>
			<option value="<?php echo esc_attr( $deny[ $axis ] ); ?>" <?php selected( $value, $deny[ $axis ] ); ?>><?php echo esc_html( $deny_label ); ?></option>
		</select>
	</div>
	<?php
}
function erankly_render_advanced_robots_fields( array $values ): void {
	$max_snippet_value       = isset( $values['max_snippet'] ) && is_scalar( $values['max_snippet'] ) ? (string) $values['max_snippet'] : '';
	$max_video_preview_value = isset( $values['max_video_preview'] ) && is_scalar( $values['max_video_preview'] ) ? (string) $values['max_video_preview'] : '';
	$max_image_preview_value = isset( $values['max_image_preview'] ) && is_scalar( $values['max_image_preview'] ) ? (string) $values['max_image_preview'] : '';
	?>
	<div class="erankly-inline-fields erankly-inline-fields-two-columns">
		<?php
		erankly_render_robots_directive_select( 'erankly_index_directive', (string) $values['index_directive'], __( 'Indexing', 'easyrankly' ), __( 'Index', 'easyrankly' ), __( 'Noindex', 'easyrankly' ) );
		erankly_render_robots_directive_select( 'erankly_follow_directive', (string) $values['follow_directive'], __( 'Link following', 'easyrankly' ), __( 'Follow', 'easyrankly' ), __( 'Nofollow', 'easyrankly' ) );
		erankly_render_robots_directive_select( 'erankly_archive_directive', (string) $values['archive_directive'], __( 'Cached copy', 'easyrankly' ), __( 'Allow archive', 'easyrankly' ), __( 'Noarchive', 'easyrankly' ) );
		erankly_render_robots_directive_select( 'erankly_snippet_directive', (string) $values['snippet_directive'], __( 'Text snippet', 'easyrankly' ), __( 'Allow snippet', 'easyrankly' ), __( 'Nosnippet', 'easyrankly' ) );
		erankly_render_robots_directive_select( 'erankly_image_directive', (string) $values['image_directive'], __( 'Image indexing', 'easyrankly' ), __( 'Allow image indexing', 'easyrankly' ), __( 'Noimageindex', 'easyrankly' ) );
		?>
		<div class="erankly-field">
			<label for="erankly-max-snippet"><?php esc_html_e( 'Max snippet', 'easyrankly' ); ?></label>
			<input id="erankly-max-snippet" class="widefat" type="number" min="-1" name="erankly_max_snippet" value="<?php echo esc_attr( $max_snippet_value ); ?>">
		</div>
		<div class="erankly-field">
			<label for="erankly-max-video-preview"><?php esc_html_e( 'Max video preview', 'easyrankly' ); ?></label>
			<input id="erankly-max-video-preview" class="widefat" type="number" min="-1" name="erankly_max_video_preview" value="<?php echo esc_attr( $max_video_preview_value ); ?>">
		</div>
		<div class="erankly-field">
			<label for="erankly-max-image-preview"><?php esc_html_e( 'Max image preview', 'easyrankly' ); ?></label>
			<select id="erankly-max-image-preview" class="widefat" name="erankly_max_image_preview">
				<option value="inherit" <?php selected( $max_image_preview_value, 'inherit' ); ?>><?php esc_html_e( 'Inherit', 'easyrankly' ); ?></option>
				<?php foreach ( array( 'none', 'standard', 'large' ) as $preview ) : ?>
					<option value="<?php echo esc_attr( $preview ); ?>" <?php selected( $max_image_preview_value, $preview ); ?>><?php echo esc_html( $preview ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="erankly-field erankly-checkboxes">
		<label><input type="checkbox" class="erankly-toggle" name="erankly_indexifembedded" value="1" <?php checked( ! empty( $values['indexifembedded'] ) ); ?>> <?php esc_html_e( 'Index if embedded when noindex applies', 'easyrankly' ); ?></label>
		<label><input type="checkbox" class="erankly-toggle" name="erankly_disable_sitemap" value="1" <?php checked( ! empty( $values['disable_sitemap'] ) ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
	</div>
	<?php
}
function erankly_render_post_schema_fields( WP_Post $post ): void {
	$mode           = erankly_get_post_meta_string( $post->ID, 'schema_mode' );
	$mode           = in_array( $mode, array( 'default', 'merge', 'replace', 'disabled' ), true ) ? $mode : 'default';
	$blocks         = get_post_meta( $post->ID, '_erankly_schema_blocks', true );
	$disabled_types = get_post_meta( $post->ID, '_erankly_schema_disabled_types', true );
	$blocks         = is_array( $blocks ) ? $blocks : array();
	$disabled_types = is_array( $disabled_types ) ? $disabled_types : array();
	$suggestions    = erankly_get_schema_type_suggestions_for_post( (int) $post->ID );
	$has_custom     = false;
	foreach ( $blocks as $block ) {
		if ( is_array( $block ) && erankly_schema_block_has_content( $block ) ) {
			$has_custom = true;
			break;
		}
	}
	?>
	<div class="erankly-post-schema" data-erankly-post-schema data-erankly-schema-mode="<?php echo esc_attr( $mode ); ?>">
	<div class="erankly-field">
		<label for="erankly-schema-mode"><?php esc_html_e( 'Schema mode', 'easyrankly' ); ?></label>
		<select id="erankly-schema-mode" class="widefat" name="erankly_schema_mode" data-erankly-schema-mode-select>
			<option value="default" <?php selected( $mode, 'default' ); ?>><?php esc_html_e( 'Automatic schema', 'easyrankly' ); ?></option>
			<option value="merge" <?php selected( $mode, 'merge' ); ?>><?php esc_html_e( 'Automatic + custom schema', 'easyrankly' ); ?></option>
			<option value="replace" <?php selected( $mode, 'replace' ); ?>><?php esc_html_e( 'Custom schema only', 'easyrankly' ); ?></option>
			<option value="disabled" <?php selected( $mode, 'disabled' ); ?>><?php esc_html_e( 'Disable schema', 'easyrankly' ); ?></option>
		</select>
	</div>
	<div class="notice notice-info inline" data-erankly-schema-notice="default-custom" role="status" <?php echo ( 'default' === $mode && $has_custom ) ? '' : 'hidden'; ?>>
		<p><?php esc_html_e( 'This content already has custom JSON-LD. Automatic schema ignores those blocks. Switch to Automatic + custom schema to emit them, or remove the unused blocks.', 'easyrankly' ); ?></p>
		<p><button type="button" class="button button-secondary" data-erankly-schema-switch-merge><?php esc_html_e( 'Use Automatic + custom schema', 'easyrankly' ); ?></button></p>
	</div>
	<div class="notice notice-warning inline" data-erankly-schema-notice="replace-empty" role="status" <?php echo ( 'replace' === $mode && ! $has_custom ) ? '' : 'hidden'; ?>>
		<p><?php esc_html_e( 'Custom schema only emits the JSON-LD added below. No automatic or site-wide schema will be output. Add a JSON-LD block, or this page will have no EasyRankly structured data.', 'easyrankly' ); ?></p>
	</div>
	<div class="notice notice-info inline" data-erankly-schema-notice="disabled" role="status" <?php echo 'disabled' === $mode ? '' : 'hidden'; ?>>
		<p><?php esc_html_e( 'No EasyRankly JSON-LD will be emitted for this content, including automatic, site-wide, and custom blocks.', 'easyrankly' ); ?></p>
	</div>
	<div class="erankly-field" data-erankly-schema-generated-controls>
		<span class="erankly-field-label" id="erankly-schema-disabled-types-label"><?php esc_html_e( 'Suppress generated schema types', 'easyrankly' ); ?></span>
		<div class="erankly-schema-type-tokens" role="group" aria-labelledby="erankly-schema-disabled-types-label">
			<?php
			$selected_lower = array_map( 'strtolower', $disabled_types );
			foreach ( $suggestions as $type ) :
				$type = (string) $type;
				$checkbox_id = 'erankly-schema-disabled-' . sanitize_html_class( strtolower( $type ) );
				?>
				<label>
					<input type="checkbox" class="erankly-toggle" name="erankly_schema_disabled_types[]" value="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $checkbox_id ); ?>" <?php checked( in_array( strtolower( $type ), $selected_lower, true ) ); ?>>
					<?php echo esc_html( $type ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
		$extra_types = array();
		foreach ( $disabled_types as $type ) {
			$type = (string) $type;
			if ( '' === $type ) {
				continue;
			}
			$known = false;
			foreach ( $suggestions as $suggestion ) {
				if ( 0 === strcasecmp( $type, (string) $suggestion ) ) {
					$known = true;
					break;
				}
			}
			if ( ! $known ) {
				$extra_types[] = $type;
			}
		}
		?>
		<label for="erankly-schema-disabled-types-extra" class="screen-reader-text"><?php esc_html_e( 'Additional schema types to suppress', 'easyrankly' ); ?></label>
		<input id="erankly-schema-disabled-types-extra" class="widefat" type="text" name="erankly_schema_disabled_types_extra" value="<?php echo esc_attr( implode( ', ', $extra_types ) ); ?>">
	</div>
	<?php // data-erankly-next-index seeds the Add button: without it the first added block reuses index 0 and overwrites the block already stored. ?>
	<div class="erankly-schema-builder" data-erankly-schema-builder data-erankly-next-index="<?php echo esc_attr( (string) count( $blocks ) ); ?>" data-erankly-schema-custom-controls>
		<div class="erankly-schema-blocks <?php echo empty( $blocks ) ? 'is-empty' : ''; ?>" data-erankly-schema-blocks>
			<?php foreach ( $blocks as $index => $block ) : ?>
				<?php erankly_render_schema_block( is_array( $block ) ? $block : array(), (string) $index, 'erankly_schema_blocks' ); ?>
			<?php endforeach; ?>
		</div>
		<template data-erankly-schema-template><?php erankly_render_schema_block( array(), '__INDEX__', 'erankly_schema_blocks' ); ?></template>
		<p class="erankly-schema-actions"><button type="button" class="button button-secondary" data-erankly-add-schema><?php esc_html_e( 'Add JSON-LD schema', 'easyrankly' ); ?></button></p>
	</div>
	</div>
	<?php
}
function erankly_render_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'erankly_save_meta_box', 'erankly_meta_box_nonce' );
	?>
	<div class="erankly-meta-box">
		<h3 class="erankly-meta-box-section-title"><?php esc_html_e( 'Search appearance', 'easyrankly' ); ?></h3>
		<?php erankly_render_post_general_fields( $post ); ?>
		<h3 class="erankly-meta-box-section-title"><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></h3>
		<?php erankly_render_post_social_fields( $post ); ?>
		<h3 class="erankly-meta-box-section-title"><?php esc_html_e( 'Schema', 'easyrankly' ); ?></h3>
		<?php erankly_render_post_schema_fields( $post ); ?>
		<h3 class="erankly-meta-box-section-title"><?php esc_html_e( 'Search visibility', 'easyrankly' ); ?></h3>
		<?php erankly_render_post_visibility_fields( $post ); ?>
		<?php do_action( 'erankly_meta_box_panels', $post ); ?>
	</div>
	<?php
}
function erankly_render_add_term_fields( string $taxonomy ): void {
	?>
	<div class="form-field term-erankly-wrap">
		<h2><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h2>
		<?php erankly_render_term_meta_fields( 0, $taxonomy ); ?>
	</div>
	<?php
}
function erankly_render_edit_term_fields( WP_Term $term ): void {
	?>
	<tr class="form-field term-erankly-wrap">
		<th scope="row"><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></th>
		<td>
			<?php erankly_render_term_meta_fields( $term->term_id, $term->taxonomy ); ?>
		</td>
	</tr>
	<?php
}
function erankly_render_term_meta_fields( int $term_id, string $taxonomy ): void {
	wp_nonce_field( 'erankly_save_term_fields', 'erankly_term_fields_nonce' );
	$id_suffix             = '-' . ( $term_id > 0 ? (string) $term_id : sanitize_key( $taxonomy ) );
	$term_object           = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
	$examples              = erankly_get_admin_variable_examples( null, $term_object instanceof WP_Term ? $term_object : null );
	$canonical             = $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'canonical' ) : '';
	?>
	<div class="erankly-meta-box erankly-term-meta-box">
		<h3 class="erankly-meta-box-section-title"><?php esc_html_e( 'Search appearance', 'easyrankly' ); ?></h3>
		<?php
		erankly_render_counted_variable_field( 'erankly-term-title' . $id_suffix, 'erankly_title', __( 'Meta title', 'easyrankly' ), $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'title' ) : '', 65, $examples, 0, 'erankly-term-title-counter' . $id_suffix );
		erankly_render_counted_variable_field( 'erankly-term-description' . $id_suffix, 'erankly_description', __( 'Meta description', 'easyrankly' ), $term_id > 0 ? erankly_get_term_meta_string( $term_id, 'description' ) : '', 160, $examples, 3, 'erankly-term-description-counter' . $id_suffix );
		?>
		<div class="erankly-field">
			<label for="erankly-term-canonical<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e( 'Canonical URL', 'easyrankly' ); ?></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="erankly-term-canonical<?php echo esc_attr( $id_suffix ); ?>" class="widefat" type="text" name="erankly_canonical" value="<?php echo esc_attr( $canonical ); ?>">
				<?php erankly_render_variable_picker( $examples ); ?>
			</div>
		</div>
		<?php do_action( 'erankly_term_general_fields_after', $term_id, ltrim( $id_suffix, '-' ) ); ?>
		<h3 class="erankly-meta-box-section-title"><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></h3>
		<?php erankly_render_social_meta_fields( 'erankly-term-', $id_suffix, erankly_get_social_meta_values( 'term', $term_id ), $examples ); ?>
		<?php do_action( 'erankly_term_social_fields_after', $term_id, ltrim( $id_suffix, '-' ) ); ?>
		<?php erankly_render_advanced_robots_fields( erankly_get_object_robots_field_values( 'term', $term_id ) ); ?>
	</div>
	<?php
}
