<?php
/** One renderer per settings tab panel; markup contract (data-erankly-*) shared with admin.js. */
defined( 'ABSPATH' ) || exit;
function erankly_render_settings_panel_features( array $settings, bool $redirects_enabled, bool $sitemap_enabled, bool $custom_code_enabled ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-features" role="region" aria-labelledby="erankly-settings-tab-features" data-erankly-settings-panel="settings-features">
					<?php erankly_section_open( __( 'Feature modules', 'easyrankly' ), array( 'doc' => 'feature-modules' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_redirects]" value="1" <?php checked( $redirects_enabled ); ?>> <?php esc_html_e( 'Enable the redirect manager', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_sitemap]" value="1" <?php checked( $sitemap_enabled ); ?>> <?php esc_html_e( 'Enable the sitemap module', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_custom_code]" value="1" <?php checked( $custom_code_enabled ); ?>> <?php esc_html_e( 'Enable custom code', 'easyrankly' ); ?></label>
						</div>
						<?php
						do_action( 'erankly_settings_features_modules', $settings );
						?>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
function erankly_render_settings_panel_custom_code( array $head_blocks, string $head_name, array $body_open_blocks, string $body_open_name, array $body_close_blocks, string $body_close_name ): void {
	$can_unfiltered  = current_user_can( 'unfiltered_html' );
	$stored_settings = erankly_get_stored_settings();
	$legacy_pending  = array_filter(
		array(
			(string) ( $stored_settings['head_code'] ?? '' ),
			(string) ( $stored_settings['body_open_code'] ?? '' ),
			(string) ( $stored_settings['body_close_code'] ?? '' ),
		),
		static fn( string $value ): bool => '' !== trim( $value )
	);
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-custom-code" role="region" aria-labelledby="erankly-settings-tab-custom-code" data-erankly-settings-panel="settings-custom-code">
					<?php if ( ! $can_unfiltered || ! empty( $legacy_pending ) ) : ?>
						<?php erankly_section_open( __( 'Custom code', 'easyrankly' ) ); ?>
							<?php if ( ! $can_unfiltered ) : ?>
								<p class="description"><?php esc_html_e( 'Your user role cannot save custom code (unfiltered HTML is required). The fields below are read-only.', 'easyrankly' ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $legacy_pending ) ) : ?>
								<p class="notice notice-warning inline"><?php esc_html_e( 'A legacy snippet could not be persisted as a block. It still runs as a fail-safe for this request; reload this panel and check the database if the warning remains.', 'easyrankly' ); ?></p>
							<?php endif; ?>
						<?php erankly_section_close(); ?>
					<?php endif; ?>
					<?php
						erankly_render_custom_code_builder(
							__( 'HEAD code', 'easyrankly' ),
							$head_blocks,
							$head_name,
							$can_unfiltered
							);
						erankly_render_custom_code_builder(
							__( 'Start of BODY code', 'easyrankly' ),
							$body_open_blocks,
							$body_open_name,
							$can_unfiltered
							);
						erankly_render_custom_code_builder(
							__( 'End of BODY code', 'easyrankly' ),
							$body_close_blocks,
							$body_close_name,
							$can_unfiltered
							);
						?>
				</div>
	<?php
}
function erankly_render_custom_code_builder( string $title, array $blocks, string $name, bool $can_unfiltered ): void {
	?>
					<?php erankly_section_open( $title, array( 'card' => false ) ); ?>
						<div class="erankly-code-builder erankly-card" data-erankly-code-builder data-erankly-next-index="<?php echo esc_attr( (string) count( $blocks ) ); ?>" data-erankly-max-blocks="<?php echo esc_attr( (string) erankly_custom_code_max_blocks() ); ?>">
							<div class="erankly-code-blocks <?php echo empty( $blocks ) ? 'is-empty' : ''; ?>" data-erankly-code-blocks data-erankly-collection="<?php echo esc_attr( $name ); ?>">
								<?php foreach ( $blocks as $index => $block ) : ?>
									<?php erankly_render_custom_code_block( is_array( $block ) ? $block : array(), (string) $index, $name, $can_unfiltered ); ?>
								<?php endforeach; ?>
							</div>
							<template data-erankly-code-template>
								<?php erankly_render_custom_code_block( array(), '__INDEX__', $name, $can_unfiltered ); ?>
							</template>
							<div class="erankly-card-actions"><button type="button" class="button button-secondary" data-erankly-add-code><?php esc_html_e( 'Add code', 'easyrankly' ); ?></button></div>
							<p class="description" data-erankly-block-limit-notice role="status" hidden><?php echo esc_html( sprintf( /* translators: %d: Maximum number of snippets allowed in one output location (head, start of body, or footer). */ __( 'Maximum reached: %d snippets per location.', 'easyrankly' ), erankly_custom_code_max_blocks() ) ); ?></p>
						</div>
					<?php erankly_section_close(); ?>
	<?php
}
function erankly_render_settings_panel_general( array $settings, int $schema_person_user_id, $schema_person_user, bool $show_organization_fields ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-general" role="region" aria-labelledby="erankly-settings-tab-general" data-erankly-settings-panel="settings-general">
					<?php erankly_section_open( __( 'Site identity', 'easyrankly' ), array( 'doc' => 'site-identity' ) ); ?>
						<div class="erankly-field">
							<label for="erankly-organization-name"><?php esc_html_e( 'Organization or person name', 'easyrankly' ); ?></label>
							<div class="erankly-variable-field" data-erankly-variable-field>
								<input id="erankly-organization-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_name]" value="<?php echo esc_attr( (string) $settings['organization_name'] ); ?>">
								<?php erankly_render_variable_picker( array(), array( 'site' ) ); ?>
							</div>
						</div>
						<div class="erankly-field">
							<label for="erankly-website-name"><?php esc_html_e( 'Website name', 'easyrankly' ); ?></label>
							<div class="erankly-variable-field" data-erankly-variable-field>
								<input id="erankly-website-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[website_name]" value="<?php echo esc_attr( (string) $settings['website_name'] ); ?>">
								<?php erankly_render_variable_picker(); ?>
							</div>
						</div>
						<div class="erankly-field">
							<label for="erankly-website-description"><?php esc_html_e( 'Website description', 'easyrankly' ); ?></label>
							<div class="erankly-variable-field" data-erankly-variable-field>
								<textarea id="erankly-website-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[website_description]"><?php echo esc_textarea( (string) $settings['website_description'] ); ?></textarea>
								<?php erankly_render_variable_picker(); ?>
							</div>
						</div>
						<div class="erankly-field">
							<label for="erankly-schema-identity"><?php esc_html_e( 'Identity type', 'easyrankly' ); ?></label>
							<select id="erankly-schema-identity" class="widefat erankly-field-full-width" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_identity]" data-erankly-schema-identity>
								<option value="organization" <?php selected( $settings['schema_identity'], 'organization' ); ?>><?php esc_html_e( 'Organization', 'easyrankly' ); ?></option>
								<option value="person" <?php selected( $settings['schema_identity'], 'person' ); ?>><?php esc_html_e( 'Person', 'easyrankly' ); ?></option>
							</select>
						</div>
						<div class="erankly-field" data-erankly-person-reference-field <?php echo 'person' === $settings['schema_identity'] ? '' : 'hidden'; ?>>
							<span class="erankly-field-label" id="erankly-person-reference-label"><?php esc_html_e( 'Person reference user', 'easyrankly' ); ?></span>
							<div data-erankly-user-search-wrap>
								<input type="hidden"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_person_user_id]"
									value="<?php echo esc_attr( (string) $schema_person_user_id ); ?>"
									data-erankly-user-id>
								<div class="erankly-autocomplete-control">
									<div class="erankly-autocomplete-value" data-erankly-user-selected<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>>
										<input type="text"
											class="widefat erankly-user-selected-input"
											readonly
											aria-labelledby="erankly-person-reference-label"
											value="<?php echo ( $schema_person_user instanceof WP_User ) ? esc_attr( sprintf( /* translators: 1: User display name, 2: User ID. */ __( '%1$s (ID: %2$d)', 'easyrankly' ), $schema_person_user->display_name, $schema_person_user->ID ) ) : ''; ?>"
											data-erankly-user-selected-name>
									</div>
									<div class="erankly-autocomplete-search" data-erankly-user-search-input-wrap<?php echo ( $schema_person_user instanceof WP_User ) ? ' hidden' : ''; ?>>
										<input type="text"
											id="erankly-person-reference-search"
											class="widefat erankly-user-search-input"
											placeholder="<?php esc_attr_e( 'Search users…', 'easyrankly' ); ?>"
											autocomplete="off"
											aria-autocomplete="list"
											aria-controls="erankly-person-reference-results"
											aria-expanded="false"
											role="combobox"
											aria-labelledby="erankly-person-reference-label"
											data-erankly-user-search-input>
										<ul class="erankly-autocomplete-results" id="erankly-person-reference-results" role="listbox" hidden data-erankly-user-results></ul>
									</div>
									<button type="button" class="button" data-erankly-user-remove<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'easyrankly' ); ?></button>
								</div>
							</div>
						</div>
						<div data-erankly-organization-only <?php echo $show_organization_fields ? '' : 'hidden'; ?>>
							<div class="erankly-field">
								<label for="erankly-organization-description"><?php esc_html_e( 'Organization description', 'easyrankly' ); ?></label>
								<textarea id="erankly-organization-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_description]"><?php echo esc_textarea( (string) $settings['organization_description'] ); ?></textarea>
							</div>
							<div class="erankly-inline-fields erankly-inline-fields-two-columns">
								<div class="erankly-field">
									<label for="erankly-organization-email"><?php esc_html_e( 'Business email', 'easyrankly' ); ?></label>
									<input id="erankly-organization-email" class="widefat" type="email" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_email]" value="<?php echo esc_attr( (string) $settings['organization_email'] ); ?>">
								</div>
								<div class="erankly-field">
									<label for="erankly-organization-phone"><?php esc_html_e( 'Business telephone', 'easyrankly' ); ?></label>
									<input id="erankly-organization-phone" class="widefat" type="tel" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_phone]" value="<?php echo esc_attr( (string) $settings['organization_phone'] ); ?>" placeholder="+1 555 123 4567">
								</div>
							</div>
							<?php erankly_render_organization_details( $settings ); ?>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Post type defaults', 'easyrankly' ), array( 'doc' => 'post-type-defaults', 'card' => false ) ); ?>
						<?php erankly_render_global_meta_defaults( 'global_post_type_meta', erankly_get_public_post_types(), $settings ); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Taxonomy defaults', 'easyrankly' ), array( 'doc' => 'taxonomy-defaults', 'card' => false ) ); ?>
						<?php erankly_render_global_meta_defaults( 'global_taxonomy_meta', erankly_get_public_taxonomies(), $settings ); ?>
					<?php erankly_section_close(); ?>
					<?php if ( is_multisite() ) : ?>
						<?php /* Per-site special pages are edited on each site; nothing to render here. */ ?>
					<?php elseif ( erankly_use_site_editor_special_page_panels() ) : ?>
						<input type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[preserve_global_special_meta]" value="1">
					<?php else : ?>
						<?php erankly_section_open( __( 'Special pages and archives', 'easyrankly' ), array( 'doc' => 'special-pages', 'card' => false ) ); ?>
							<?php erankly_render_special_page_defaults( erankly_special_page_keys(), $settings ); ?>
						<?php erankly_section_close(); ?>
					<?php endif; ?>
				</div>
	<?php
}
function erankly_render_settings_panel_social( array $settings ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-social" role="region" aria-labelledby="erankly-settings-tab-social" data-erankly-settings-panel="settings-social">
					<?php erankly_section_open( __( 'Default images', 'easyrankly' ), array( 'doc' => 'default-images' ) ); ?>
						<div class="erankly-field">
							<label for="erankly-organization-logo-url"><?php esc_html_e( 'Organization logo', 'easyrankly' ); ?></label>
							<?php
							$organization_logo_id  = absint( $settings['organization_logo'] );
							$organization_logo_url = isset( $settings['organization_logo_url'] ) ? (string) $settings['organization_logo_url'] : '';
							if ( '' === $organization_logo_url && $organization_logo_id > 0 ) {
								$organization_logo_url = erankly_get_image_url( $organization_logo_id, 'full' );
							}
							if ( '' === $organization_logo_url ) {
								$organization_logo_url = erankly_default_organization_logo_url_template();
							}
							erankly_render_media_url_field(
								'erankly-organization-logo-url',
								ERANKLY_OPTION . '[organization_logo_url]',
								$organization_logo_url,
								erankly_default_organization_logo_placeholder(),
								ERANKLY_OPTION . '[organization_logo]',
								$organization_logo_id,
								false
							);
							?>
						</div>
						<div class="erankly-field">
							<label for="erankly-default-social-image-url"><?php esc_html_e( 'Default social image URL', 'easyrankly' ); ?></label>
							<?php
							erankly_render_media_url_field(
								'erankly-default-social-image-url',
								ERANKLY_OPTION . '[default_social_image_url]',
								(string) $settings['default_social_image_url'],
								erankly_default_social_image_placeholder(),
								ERANKLY_OPTION . '[default_og_image]',
								absint( $settings['default_og_image'] ),
								false
							);
							?>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Social defaults', 'easyrankly' ), array( 'doc' => 'social-defaults', 'card' => false ) ); ?>
						<?php erankly_render_social_meta_defaults( $settings ); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Social profiles', 'easyrankly' ), array( 'doc' => 'social-profiles' ) ); ?>
						<div class="erankly-field">
							<label for="erankly-twitter-site"><?php esc_html_e( 'X (Twitter) site', 'easyrankly' ); ?></label>
							<input id="erankly-twitter-site" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[twitter_site]" value="<?php echo esc_attr( (string) $settings['twitter_site'] ); ?>" placeholder="@example">
						</div>
						<div class="erankly-field">
							<label for="erankly-social-profiles"><?php esc_html_e( 'Social profiles', 'easyrankly' ); ?></label>
							<textarea id="erankly-social-profiles" class="widefat" rows="5" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[social_profiles]"><?php echo esc_textarea( (string) $settings['social_profiles'] ); ?></textarea>
						</div>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
function erankly_render_settings_panel_schema( array $settings, array $global_schema_blocks, string $global_schema_name ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-schema" role="region" aria-labelledby="erankly-settings-tab-schema" data-erankly-settings-panel="settings-schema">
					<?php erankly_section_open( __( 'Information for Google and other search engines', 'easyrankly' ), array( 'doc' => 'search-engines' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_breadcrumbs]" value="1" <?php checked( $settings['enable_breadcrumbs'], 1 ); ?>> <?php esc_html_e( 'Enable breadcrumbs', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field">
							<label for="erankly-breadcrumb-jsonld-mode"><?php esc_html_e( 'Breadcrumb JSON-LD', 'easyrankly' ); ?></label>
							<select id="erankly-breadcrumb-jsonld-mode" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[breadcrumb_jsonld_mode]">
								<option value="when_visible" <?php selected( (string) ( $settings['breadcrumb_jsonld_mode'] ?? 'when_visible' ), 'when_visible' ); ?>><?php esc_html_e( 'Only when a visible trail is present (recommended)', 'easyrankly' ); ?></option>
								<option value="always" <?php selected( (string) ( $settings['breadcrumb_jsonld_mode'] ?? '' ), 'always' ); ?>><?php esc_html_e( 'Always emit BreadcrumbList JSON-LD', 'easyrankly' ); ?></option>
								<option value="off" <?php selected( (string) ( $settings['breadcrumb_jsonld_mode'] ?? '' ), 'off' ); ?>><?php esc_html_e( 'Do not emit breadcrumb JSON-LD', 'easyrankly' ); ?></option>
							</select>
						</div>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_website_search_action]" value="1" <?php checked( ! empty( $settings['enable_website_search_action'] ) ); ?>> <?php esc_html_e( 'Add WebSite SearchAction', 'easyrankly' ); ?></label>
						</div>
						<?php erankly_render_local_business_settings( $settings ); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Schema types by content type', 'easyrankly' ), array( 'doc' => 'post-type-defaults', 'hidden' => ! empty( $settings['simplified_mode'] ) ) ); ?>
						<?php erankly_render_post_type_schema_types(); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Custom JSON-LD Schema', 'easyrankly' ), array( 'doc' => 'custom-schema', 'hidden' => ! empty( $settings['simplified_mode'] ), 'card' => false ) ); ?>
						<div class="erankly-schema-builder erankly-card" data-erankly-schema-builder data-erankly-next-index="<?php echo esc_attr( (string) count( $global_schema_blocks ) ); ?>">
							<div class="erankly-schema-blocks <?php echo empty( $global_schema_blocks ) ? 'is-empty' : ''; ?>" data-erankly-schema-blocks data-erankly-collection="<?php echo esc_attr( $global_schema_name ); ?>">
								<?php foreach ( $global_schema_blocks as $index => $block ) : ?>
									<?php erankly_render_schema_block( is_array( $block ) ? $block : array(), (string) $index, $global_schema_name, true ); ?>
								<?php endforeach; ?>
							</div>
							<template data-erankly-schema-template>
								<?php erankly_render_schema_block( array(), '__INDEX__', $global_schema_name, true ); ?>
							</template>
							<div class="erankly-card-actions"><button type="button" class="button button-secondary" data-erankly-add-schema><?php esc_html_e( 'Add Schema', 'easyrankly' ); ?></button></div>
						</div>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
function erankly_render_settings_panel_sitemap( array $settings, string $sitemap_url ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-sitemap" role="region" aria-labelledby="erankly-settings-tab-sitemap" data-erankly-settings-panel="settings-sitemap">
					<?php erankly_section_open( __( 'XML sitemap', 'easyrankly' ), array( 'doc' => 'xml-sitemap' ) ); ?>
						<p class="description">
							<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open wp-sitemap.xml', 'easyrankly' ); ?></a>
						</p>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Google News sitemap', 'easyrankly' ), array( 'doc' => 'news-sitemap' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_news_sitemap]" value="1" <?php checked( $settings['enable_news_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate Google News sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-news-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-news-1.xml', 'easyrankly' ); ?></a>
							</p>
						</div>
						<div class="erankly-field erankly-checkboxes erankly-visibility-defaults" role="group" aria-labelledby="erankly-news-post-types-label">
							<span class="erankly-field-label" id="erankly-news-post-types-label"><?php esc_html_e( 'Included post types', 'easyrankly' ); ?></span>
							<div class="erankly-checkbox-options">
								<?php
								$news_post_types = (array) erankly_get_setting( 'news_sitemap_post_types', array( 'post' ) );
								foreach ( erankly_get_public_post_types() as $post_type => $object ) :
									?>
									<label>
										<input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_sitemap_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $news_post_types, true ) ); ?>>
										<?php echo esc_html( $object->labels->singular_name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="erankly-field">
							<label for="erankly-news-publication-name"><?php esc_html_e( 'News publication name', 'easyrankly' ); ?></label>
							<input
								id="erankly-news-publication-name"
								class="widefat"
								type="text"
								name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_publication_name]"
								value="<?php echo esc_attr( (string) $settings['news_publication_name'] ); ?>"
								maxlength="200"
							>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Image sitemap', 'easyrankly' ), array( 'doc' => 'image-sitemap' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_image_sitemap]" value="1" <?php checked( $settings['enable_image_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate image sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-image-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-image-1.xml', 'easyrankly' ); ?></a>
							</p>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Video sitemap', 'easyrankly' ), array( 'doc' => 'video-sitemap' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_video_sitemap]" value="1" <?php checked( $settings['enable_video_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate video sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-video-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-video-1.xml', 'easyrankly' ); ?></a>
							</p>
						</div>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
function erankly_render_settings_panel_settings( array $settings ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-settings" role="region" aria-labelledby="erankly-settings-tab-settings" data-erankly-settings-panel="settings-settings">
					<?php if ( function_exists( 'erankly_reset_render_notice' ) ) : ?>
						<?php erankly_reset_render_notice(); ?>
					<?php endif; ?>
					<?php erankly_section_open( __( 'Preferences', 'easyrankly' ), array( 'doc' => 'preferences' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[simplified_mode]" value="1" <?php checked( $settings['simplified_mode'], 1 ); ?>> <?php esc_html_e( 'Simplified mode', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[resolve_placeholders]" value="1" <?php checked( ! empty( $settings['resolve_placeholders'] ) ); ?>> <?php esc_html_e( 'Show resolved values for variables', 'easyrankly' ); ?></label>
						</div>
					<?php erankly_section_close(); ?>
					<?php if ( function_exists( 'erankly_reset_render_panel' ) ) : ?>
						<?php erankly_reset_render_panel(); ?>
					<?php endif; ?>
				</div>
	<?php
}
function erankly_render_settings_panel_advanced( array $settings ): void {
	$max_image_preview = isset( $settings['robots_max_image_preview'] ) ? sanitize_key( (string) $settings['robots_max_image_preview'] ) : '';
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-advanced" role="region" aria-labelledby="erankly-settings-tab-advanced" data-erankly-settings-panel="settings-advanced">
					<?php erankly_section_open( __( 'Indexing & robots directives', 'easyrankly' ), array( 'doc' => 'indexing-robots' ) ); ?>
						<div class="erankly-field">
							<label for="erankly-robots-max-image-preview"><?php esc_html_e( 'max-image-preview', 'easyrankly' ); ?></label>
							<select id="erankly-robots-max-image-preview" class="widefat erankly-field-full-width" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_image_preview]">
								<option value="" <?php selected( $max_image_preview, '' ); ?>><?php esc_html_e( 'No explicit directive', 'easyrankly' ); ?></option>
								<option value="none" <?php selected( $max_image_preview, 'none' ); ?>>none</option>
								<option value="standard" <?php selected( $max_image_preview, 'standard' ); ?>>standard</option>
								<option value="large" <?php selected( $max_image_preview, 'large' ); ?>>large</option>
							</select>
						</div>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<div class="erankly-field">
								<label for="erankly-robots-max-snippet"><?php esc_html_e( 'max-snippet', 'easyrankly' ); ?></label>
								<input id="erankly-robots-max-snippet" class="widefat" type="number" step="1" min="-1" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_snippet]" value="<?php echo esc_attr( (string) $settings['robots_max_snippet'] ); ?>">
							</div>
							<div class="erankly-field">
								<label for="erankly-robots-max-video-preview"><?php esc_html_e( 'max-video-preview', 'easyrankly' ); ?></label>
								<input id="erankly-robots-max-video-preview" class="widefat" type="number" step="1" min="-1" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_video_preview]" value="<?php echo esc_attr( (string) $settings['robots_max_video_preview'] ); ?>">
							</div>
						</div>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_nosnippet]" value="1" <?php checked( $settings['robots_nosnippet'], 1 ); ?>> <?php esc_html_e( 'Add nosnippet', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_noimageindex]" value="1" <?php checked( $settings['robots_noimageindex'], 1 ); ?>> <?php esc_html_e( 'Add noimageindex', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_notranslate]" value="1" <?php checked( $settings['robots_notranslate'], 1 ); ?>> <?php esc_html_e( 'Add notranslate', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_indexifembedded]" value="1" <?php checked( $settings['robots_indexifembedded'], 1 ); ?>> <?php esc_html_e( 'Allow indexing of embedded content when noindex is active', 'easyrankly' ); ?></label>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'robots.txt', 'easyrankly' ), array( 'doc' => 'robots-txt' ) ); ?>
						<div class="erankly-field">
							<label for="erankly-robots-txt-extra"><?php esc_html_e( 'robots.txt: custom rules', 'easyrankly' ); ?></label>
							<textarea id="erankly-robots-txt-extra" class="widefat code" rows="12" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_txt_extra]"><?php echo esc_textarea( (string) $settings['robots_txt_extra'] ); ?></textarea>
						</div>
						<div class="erankly-field">
							<label for="erankly-robots-txt-preview"><?php esc_html_e( 'robots.txt preview', 'easyrankly' ); ?></label>
							<textarea id="erankly-robots-txt-preview" class="widefat code" rows="12" readonly><?php echo esc_textarea( erankly_get_robots_txt_preview() ); ?></textarea>
							<p class="description">
								<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open robots.txt', 'easyrankly' ); ?></a>
							</p>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Pagination', 'easyrankly' ), array( 'doc' => 'pagination' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[noindex_paginated]" value="1" <?php checked( $settings['noindex_paginated'], 1 ); ?>> <?php esc_html_e( 'Noindex page 2, 3, … of archives', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[noindex_paginated_content]" value="1" <?php checked( $settings['noindex_paginated_content'], 1 ); ?>> <?php esc_html_e( 'Noindex paginated posts, pages, and comments', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[nofollow_paginated]" value="1" <?php checked( $settings['nofollow_paginated'], 1 ); ?>> <?php esc_html_e( 'Nofollow all paginated content', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[noindex_feeds]" value="1" <?php checked( $settings['noindex_feeds'], 1 ); ?>> <?php esc_html_e( 'Send noindex for RSS feeds', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field">
							<label for="erankly-paginated-title-format"><?php esc_html_e( 'Paginated title suffix', 'easyrankly' ); ?></label>
							<div class="erankly-variable-field" data-erankly-variable-field>
								<input id="erankly-paginated-title-format" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[paginated_title_format]" value="<?php echo esc_attr( (string) $settings['paginated_title_format'] ); ?>" placeholder="<?php esc_attr_e( 'Page {{page_number}} of {{max_pages}}', 'easyrankly' ); ?>">
								<?php erankly_render_variable_picker( array(), array( 'pagination' ) ); ?>
							</div>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Attachment pages', 'easyrankly' ), array( 'doc' => 'attachment-pages' ) ); ?>
						<div class="erankly-field">
							<label for="erankly-attachment-redirect"><?php esc_html_e( 'Redirect attachment pages', 'easyrankly' ); ?></label>
							<select name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[attachment_redirect]" id="erankly-attachment-redirect" class="widefat erankly-field-full-width">
								<option value="parent" <?php selected( $settings['attachment_redirect'], 'parent' ); ?>><?php esc_html_e( 'Redirect to parent post (fallback: media file)', 'easyrankly' ); ?></option>
								<option value="file" <?php selected( $settings['attachment_redirect'], 'file' ); ?>><?php esc_html_e( 'Redirect to media file', 'easyrankly' ); ?></option>
								<option value="none" <?php selected( $settings['attachment_redirect'], 'none' ); ?>><?php esc_html_e( 'Leave attachment pages unchanged', 'easyrankly' ); ?></option>
							</select>
						</div>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
