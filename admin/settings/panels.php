<?php
/** One renderer per settings tab panel; markup contract (data-erankly-*) shared with admin.js. */
defined( 'ABSPATH' ) || exit;
require_once ERANKLY_PATH . 'admin/settings/fields.php';
function erankly_render_settings_panel_features( array $settings, bool $redirects_enabled, bool $sitemap_enabled, bool $custom_code_enabled ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-features" role="region" aria-labelledby="erankly-settings-tab-features" data-erankly-settings-panel="settings-features">
					<?php erankly_section_open( __( 'Feature modules', 'easyrankly' ), array( 'doc' => 'feature-modules' ) ); ?>
						<?php
						$labels = erankly_feature_module_labels();
						$values = array(
							'enable_seo'          => erankly_seo_enabled(),
							'enable_tools'        => erankly_tools_enabled(),
							'enable_redirects'    => $redirects_enabled,
							'enable_sitemap'      => $sitemap_enabled,
							'enable_custom_code'  => $custom_code_enabled,
							'enable_forms'        => erankly_forms_enabled(),
							'enable_multilingual' => erankly_multilingual_enabled(),
						);
						$read_only = is_multisite() && ! is_network_admin();
						foreach ( erankly_feature_modules() as $slug => $module ) {
							erankly_render_settings_checkboxes( array( $module['setting'] => $labels[ $slug ] ), $values, array( 'disabled' => $read_only ) );
						}
						if ( ! $read_only ) {
							do_action( 'erankly_settings_features_modules', $settings );
						}
						?>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
function erankly_render_settings_panel_custom_code( array $head_blocks, string $head_name, array $body_open_blocks, string $body_open_name, array $body_close_blocks, string $body_close_name ): void {
	$can_unfiltered = current_user_can( 'unfiltered_html' );
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-custom-code" role="region" aria-labelledby="erankly-settings-tab-custom-code" data-erankly-settings-panel="settings-custom-code">
					<?php if ( ! $can_unfiltered ) : ?>
						<?php erankly_section_open( __( 'Custom code', 'easyrankly' ) ); ?>
							<p class="description"><?php esc_html_e( 'Your user role cannot save custom code (unfiltered HTML is required). The fields below are read-only.', 'easyrankly' ); ?></p>
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
					<div class="erankly-settings-section erankly-code-builder" data-erankly-code-builder data-erankly-next-index="<?php echo esc_attr( (string) count( $blocks ) ); ?>" data-erankly-max-blocks="<?php echo esc_attr( (string) erankly_custom_code_max_blocks() ); ?>">
						<div class="erankly-section-title-row">
							<h2 class="erankly-section-title"><?php echo esc_html( $title ); ?></h2>
							<div class="erankly-code-heading-actions">
								<button type="button" class="erankly-code-add" data-erankly-add-code><?php esc_html_e( 'Add', 'easyrankly' ); ?></button>
								<span aria-hidden="true">|</span>
								<a href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-custom-code', 'https://docs.easyrankly.com/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn', 'easyrankly' ); ?></a>
							</div>
						</div>
						<div class="erankly-card">
							<div class="erankly-code-blocks <?php echo empty( $blocks ) ? 'is-empty' : ''; ?>" data-erankly-code-blocks data-erankly-collection="<?php echo esc_attr( $name ); ?>">
								<?php foreach ( $blocks as $index => $block ) : ?>
									<?php erankly_render_custom_code_block( is_array( $block ) ? $block : array(), (string) $index, $name, $can_unfiltered ); ?>
								<?php endforeach; ?>
							</div>
							<p class="description erankly-code-empty"><?php esc_html_e( 'Your code snippets will appear here. Add your first snippet to get started.', 'easyrankly' ); ?></p>
							<template data-erankly-code-template>
								<?php erankly_render_custom_code_block( array(), '__INDEX__', $name, $can_unfiltered ); ?>
							</template>
							<p class="description" data-erankly-block-limit-notice role="status" hidden><?php echo esc_html( sprintf( /* translators: %d: Maximum number of snippets allowed in one output location (head, start of body, or footer). */ __( 'Maximum reached: %d snippets per location.', 'easyrankly' ), erankly_custom_code_max_blocks() ) ); ?></p>
						</div>
					</div>
	<?php
}
/** Renders all SEO settings in one panel, with one autosave scope. */
function erankly_render_settings_panel_seo( array $settings ): void {
	$person_id = absint( $settings['schema_person_user_id'] ?? 0 );
	?>
	<div class="erankly-settings-panel is-active" id="erankly-settings-panel-seo" role="region" aria-labelledby="erankly-settings-tab-seo" data-erankly-settings-panel="settings-seo">
		<?php
		erankly_render_settings_panel_general( $settings, $person_id, $person_id > 0 ? get_userdata( $person_id ) : false, erankly_settings_show_organization_fields( $settings ), false );
		erankly_render_settings_panel_social( $settings, false );
		erankly_render_settings_panel_schema( $settings, is_array( $settings['global_schema_blocks'] ?? null ) ? $settings['global_schema_blocks'] : array(), ERANKLY_OPTION . '[global_schema_blocks]', false );
		erankly_render_settings_panel_advanced( $settings, false );
		?>
	</div>
	<?php
}
function erankly_render_settings_panel_general( array $settings, int $schema_person_user_id, $schema_person_user, bool $show_organization_fields, bool $standalone = true ): void {
	$is_person            = 'person' === (string) ( $settings['schema_identity'] ?? 'organization' );
	$show_location_fields = erankly_settings_show_location_fields( $settings );
	$email_org_label      = __( 'Business email', 'easyrankly' );
	$email_person_label   = __( 'Email', 'easyrankly' );
	$phone_org_label      = __( 'Business telephone', 'easyrankly' );
	$phone_person_label   = __( 'Telephone', 'easyrankly' );
	?>
				<?php if ( $standalone ) : ?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-general" role="region" aria-labelledby="erankly-settings-tab-general" data-erankly-settings-panel="settings-general">
				<?php endif; ?>
					<?php erankly_section_open( __( 'Site identity', 'easyrankly' ), array( 'doc' => 'site-identity' ) ); ?>
						<div class="erankly-tabs-bar">
							<div class="erankly-tabs" role="group" aria-label="<?php esc_attr_e( 'Identity type', 'easyrankly' ); ?>" data-erankly-identity-selector>
								<button type="button" class="erankly-tab <?php echo $is_person ? '' : 'is-active'; ?>" aria-pressed="<?php echo $is_person ? 'false' : 'true'; ?>" data-erankly-identity-option="organization"><?php esc_html_e( 'Organization', 'easyrankly' ); ?></button>
								<button type="button" class="erankly-tab <?php echo $is_person ? 'is-active' : ''; ?>" aria-pressed="<?php echo $is_person ? 'true' : 'false'; ?>" data-erankly-identity-option="person"><?php esc_html_e( 'Person', 'easyrankly' ); ?></button>
							</div>
							<input type="hidden" id="erankly-schema-identity" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_identity]" value="<?php echo esc_attr( $is_person ? 'person' : 'organization' ); ?>" data-erankly-schema-identity>
						</div>
						<?php erankly_render_settings_field( 'website_name', __( 'Website name', 'easyrankly' ), (string) $settings[ 'website_name' ], array( 'variables' => array() ) ); ?>
						<?php erankly_render_settings_field( 'website_description', __( 'Website description', 'easyrankly' ), (string) $settings[ 'website_description' ], array( 'type' => 'textarea', 'variables' => array() ) ); ?>
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
						<div class="erankly-stack" data-erankly-organization-only <?php echo $show_organization_fields ? '' : 'hidden'; ?>>
							<?php erankly_render_settings_field( 'organization_name', __( 'Organization name', 'easyrankly' ), (string) $settings[ 'organization_name' ], array( 'variables' => array( 'site' ) ) ); ?>
							<?php erankly_render_settings_field( 'organization_description', __( 'Organization description', 'easyrankly' ), (string) $settings[ 'organization_description' ], array( 'type' => 'textarea' ) ); ?>
						</div>
						<div class="erankly-stack" data-erankly-location-fields <?php echo $show_location_fields ? '' : 'hidden'; ?>>
							<div class="erankly-inline-fields erankly-inline-fields-two-columns">
								<div class="erankly-field">
									<label for="erankly-organization-email" data-erankly-identity-label data-erankly-label-organization="<?php echo esc_attr( $email_org_label ); ?>" data-erankly-label-person="<?php echo esc_attr( $email_person_label ); ?>"><?php echo esc_html( $is_person ? $email_person_label : $email_org_label ); ?></label>
									<input id="erankly-organization-email" class="widefat" type="email" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_email]" value="<?php echo esc_attr( (string) $settings['organization_email'] ); ?>">
								</div>
								<div class="erankly-field">
									<label for="erankly-organization-phone" data-erankly-identity-label data-erankly-label-organization="<?php echo esc_attr( $phone_org_label ); ?>" data-erankly-label-person="<?php echo esc_attr( $phone_person_label ); ?>"><?php echo esc_html( $is_person ? $phone_person_label : $phone_org_label ); ?></label>
									<input id="erankly-organization-phone" class="widefat" type="tel" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_phone]" value="<?php echo esc_attr( (string) $settings['organization_phone'] ); ?>">
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
				<?php if ( $standalone ) : ?>
				</div>
				<?php endif; ?>
	<?php
}
function erankly_render_settings_panel_social( array $settings, bool $standalone = true ): void {
	?>
				<?php if ( $standalone ) : ?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-social" role="region" aria-labelledby="erankly-settings-tab-social" data-erankly-settings-panel="settings-social">
				<?php endif; ?>
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
								ERANKLY_OPTION . '[default_og_image]',
								absint( $settings['default_og_image'] ),
								false
							);
							?>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Social profiles', 'easyrankly' ), array( 'doc' => 'social-profiles' ) ); ?>
						<?php erankly_render_settings_field( 'twitter_site', __( 'X (Twitter) site', 'easyrankly' ), (string) $settings[ 'twitter_site' ] ); ?>
						<?php erankly_render_settings_field( 'social_profiles', __( 'Social profiles', 'easyrankly' ), (string) $settings[ 'social_profiles' ], array( 'type' => 'textarea', 'attributes' => array( 'rows' => '5' ) ) ); ?>
					<?php erankly_section_close(); ?>
				<?php if ( $standalone ) : ?>
				</div>
				<?php endif; ?>
	<?php
}
function erankly_render_settings_panel_schema( array $settings, array $global_schema_blocks, string $global_schema_name, bool $standalone = true ): void {
	?>
				<?php if ( $standalone ) : ?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-schema" role="region" aria-labelledby="erankly-settings-tab-schema" data-erankly-settings-panel="settings-schema">
				<?php endif; ?>
					<?php erankly_section_open( __( 'Information for Google and other search engines', 'easyrankly' ), array( 'doc' => 'search-engines' ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<?php erankly_render_settings_checkboxes( array( 'enable_breadcrumbs' => __( 'Enable breadcrumbs', 'easyrankly' ) ), array( 'enable_breadcrumbs' => ( 1 == $settings['enable_breadcrumbs'] ) ), array( 'wrap' => false ) ); ?>
							<p class="description"><?php echo esc_html( erankly_breadcrumb_settings_help_text() ); ?></p>
						</div>
						<?php erankly_render_settings_field( 'breadcrumb_jsonld_mode', __( 'Breadcrumb JSON-LD', 'easyrankly' ), (string) ( $settings['breadcrumb_jsonld_mode'] ?? 'when_visible' ), array( 'type' => 'select', 'options' => array( 'when_visible' => __( 'Only when a visible trail is present (recommended)', 'easyrankly' ), 'always' => __( 'Always emit BreadcrumbList JSON-LD', 'easyrankly' ), 'off' => __( 'Do not emit breadcrumb JSON-LD', 'easyrankly' ) ) ) ); ?>
						<?php erankly_render_local_business_settings( $settings ); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Schema types by content type', 'easyrankly' ), array( 'doc' => 'post-type-defaults' ) ); ?>
						<?php erankly_render_post_type_schema_types(); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Custom JSON-LD Schema', 'easyrankly' ), array( 'doc' => 'custom-schema', 'card' => false ) ); ?>
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
				<?php if ( $standalone ) : ?>
				</div>
				<?php endif; ?>
	<?php
}
function erankly_render_settings_panel_sitemap( array $settings ): void {
	?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-sitemap" role="region" aria-labelledby="erankly-settings-tab-sitemap" data-erankly-settings-panel="settings-sitemap">
					<?php erankly_section_open( __( 'Google News sitemap', 'easyrankly' ), array( 'doc' => 'news-sitemap', 'view_url' => erankly_get_sitemap_url( '/sitemap-news-1.xml' ) ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<?php erankly_render_settings_checkboxes( array( 'enable_news_sitemap' => __( 'Google News sitemap', 'easyrankly' ) ), array( 'enable_news_sitemap' => ( 1 == $settings['enable_news_sitemap'] ) ), array( 'wrap' => false ) ); ?>
						</div>
						<div class="erankly-stack" data-erankly-news-sitemap-fields<?php echo 1 == $settings['enable_news_sitemap'] ? '' : ' hidden'; ?>>
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
							<?php erankly_render_settings_field( 'news_publication_name', __( 'News publication name', 'easyrankly' ), (string) $settings[ 'news_publication_name' ], array( 'attributes' => array( 'maxlength' => '200' ) ) ); ?>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Image sitemap', 'easyrankly' ), array( 'doc' => 'image-sitemap', 'view_url' => erankly_get_sitemap_url( '/sitemap-image-1.xml' ) ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<?php erankly_render_settings_checkboxes( array( 'enable_image_sitemap' => __( 'Image sitemap', 'easyrankly' ) ), array( 'enable_image_sitemap' => ( 1 == $settings['enable_image_sitemap'] ) ), array( 'wrap' => false ) ); ?>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Video sitemap', 'easyrankly' ), array( 'doc' => 'video-sitemap', 'view_url' => erankly_get_sitemap_url( '/sitemap-video-1.xml' ) ) ); ?>
						<div class="erankly-field erankly-checkboxes">
							<?php erankly_render_settings_checkboxes( array( 'enable_video_sitemap' => __( 'Video sitemap', 'easyrankly' ) ), array( 'enable_video_sitemap' => ( 1 == $settings['enable_video_sitemap'] ) ), array( 'wrap' => false ) ); ?>
						</div>
					<?php erankly_section_close(); ?>
				</div>
	<?php
}
function erankly_render_settings_panel_advanced( array $settings, bool $standalone = true ): void {
	$max_image_preview = isset( $settings['robots_max_image_preview'] ) ? sanitize_key( (string) $settings['robots_max_image_preview'] ) : '';
	?>
				<?php if ( $standalone ) : ?>
				<div class="erankly-settings-panel is-active" id="erankly-settings-panel-advanced" role="region" aria-labelledby="erankly-settings-tab-advanced" data-erankly-settings-panel="settings-advanced">
				<?php endif; ?>
					<?php erankly_section_open( __( 'Indexing & robots directives', 'easyrankly' ), array( 'doc' => 'indexing-robots' ) ); ?>
						<?php erankly_render_settings_field( 'robots_max_image_preview', __( 'max-image-preview', 'easyrankly' ), $max_image_preview, array( 'type' => 'select', 'options' => array( '' => __( 'No explicit directive', 'easyrankly' ), 'none' => 'none', 'standard' => 'standard', 'large' => 'large' ) ) ); ?>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<?php erankly_render_settings_field( 'robots_max_snippet', __( 'max-snippet', 'easyrankly' ), (string) $settings[ 'robots_max_snippet' ], array( 'type' => 'number', 'attributes' => array( 'step' => '1', 'min' => '-1' ) ) ); ?>
							<?php erankly_render_settings_field( 'robots_max_video_preview', __( 'max-video-preview', 'easyrankly' ), (string) $settings[ 'robots_max_video_preview' ], array( 'type' => 'number', 'attributes' => array( 'step' => '1', 'min' => '-1' ) ) ); ?>
						</div>
						<?php erankly_render_settings_checkboxes( array( 'robots_nosnippet' => __( 'Add nosnippet', 'easyrankly' ), 'robots_noimageindex' => __( 'Add noimageindex', 'easyrankly' ), 'robots_notranslate' => __( 'Add notranslate', 'easyrankly' ), 'robots_indexifembedded' => __( 'Allow indexing of embedded content when noindex is active', 'easyrankly' ) ), $settings ); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'robots.txt', 'easyrankly' ), array( 'doc' => 'robots-txt' ) ); ?>
						<?php erankly_render_settings_field( 'robots_txt_extra', __( 'Custom rules', 'easyrankly' ), (string) $settings[ 'robots_txt_extra' ], array( 'type' => 'textarea', 'attributes' => array( 'class' => 'widefat code', 'rows' => '12' ) ) ); ?>
						<div class="erankly-field">
							<label for="erankly-robots-txt-preview"><?php esc_html_e( 'Preview', 'easyrankly' ); ?></label>
							<textarea id="erankly-robots-txt-preview" class="widefat code" rows="12" readonly><?php echo esc_textarea( erankly_get_robots_txt_preview() ); ?></textarea>
							<p class="description">
								<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open robots.txt', 'easyrankly' ); ?></a>
							</p>
						</div>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Pagination', 'easyrankly' ), array( 'doc' => 'pagination' ) ); ?>
						<?php erankly_render_settings_checkboxes( array( 'noindex_paginated' => __( 'Noindex page 2, 3, … of archives', 'easyrankly' ), 'noindex_paginated_content' => __( 'Noindex paginated posts, pages, and comments', 'easyrankly' ), 'nofollow_paginated' => __( 'Nofollow all paginated content', 'easyrankly' ), 'noindex_feeds' => __( 'Send noindex for RSS feeds', 'easyrankly' ) ), $settings ); ?>
						<?php erankly_render_settings_field( 'paginated_title_format', __( 'Paginated title suffix', 'easyrankly' ), (string) $settings[ 'paginated_title_format' ], array( 'variables' => array( 'pagination' ) ) ); ?>
					<?php erankly_section_close(); ?>
					<?php erankly_section_open( __( 'Attachment pages', 'easyrankly' ), array( 'doc' => 'attachment-pages' ) ); ?>
						<?php erankly_render_settings_field( 'attachment_redirect', __( 'Redirect attachment pages', 'easyrankly' ), (string) $settings['attachment_redirect'], array( 'type' => 'select', 'options' => array( 'parent' => __( 'Redirect to parent post (fallback: media file)', 'easyrankly' ), 'file' => __( 'Redirect to media file', 'easyrankly' ), 'none' => __( 'Leave attachment pages unchanged', 'easyrankly' ) ) ) ); ?>
					<?php erankly_section_close(); ?>
				<?php if ( $standalone ) : ?>
				</div>
				<?php endif; ?>
	<?php
}
