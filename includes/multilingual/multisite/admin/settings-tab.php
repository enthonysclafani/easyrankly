<?php
/** "Multilingual" tab in the EasyRankly settings screen (Feature modules group, Network Admin only). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the settings tab, its save handler and the editor integration hooks. */
function erankly_mlms_admin_bootstrap(): void {
	add_filter( 'erankly_settings_tabs', 'erankly_mlms_register_settings_tab', 10, 2 );
	add_action( 'erankly_render_settings_tab_multilingual', 'erankly_mlms_render_settings_tab' );
	add_action( 'network_admin_edit_erml_save_settings', 'erankly_mlms_handle_network_settings_save' );
	add_filter( 'erankly_settings_autosave_client_panels', 'erankly_mlms_register_autosave_panel' );
	add_action( 'add_meta_boxes', 'erankly_mlms_register_meta_boxes', 10, 2 );
	add_action( 'save_post', 'erankly_mlms_handle_post_save', 10, 2 );
	add_action( 'enqueue_block_editor_assets', 'erankly_mlms_enqueue_editor_panel' );
	add_action( 'admin_enqueue_scripts', 'erankly_mlms_enqueue_admin_assets' );
	add_action( 'admin_init', 'erankly_mlms_admin_register_term_hooks' );
}

/**
 * Adds the "Multilingual" tab through the public EasyRankly extension API. The feature_modules group places the tab in the
 * settings sidebar's "Feature modules" section; network scope keeps the site/language map in the Network
 * Admin, where it belongs.
 *
 * @param array<string,mixed> $tabs           Registered extension tabs.
 * @param array<string,mixed> $screen_context Current settings screen context.
 * @return array<string,mixed>
 */
function erankly_mlms_register_settings_tab( array $tabs, array $screen_context ): array {
	unset( $screen_context );

	$tabs['multilingual'] = array(
		'label'      => __( 'Multilingual', 'easyrankly' ),
		'capability' => 'manage_network_options',
		'scope'      => 'network',
		'position'   => 50,
		'group'      => 'feature_modules',
	);

	return $tabs;
}

/**
 * Points the EasyRankly settings JS at this tab's REST route so the panel autosaves like the core ones. The
 * settings screen already renders extension tabs as standalone panels; a tab without an entry here is simply
 * left unbound. 'fieldRoot' scopes the client-side serializer to erml_ms_settings[...] instead of the core
 * erankly_settings[...].
 *
 * @param array<string,array<string,mixed>> $panels Client-side autosave configs, keyed by tab slug.
 * @return array<string,array<string,mixed>>
 */
function erankly_mlms_register_autosave_panel( array $panels ): array {
	$panels['multilingual'] = array(
		'restUrl'      => esc_url_raw( rest_url( 'erankly/v1/multilingual/multisite/settings' ) ),
		'fieldRoot'    => ERANKLY_MLMS_OPTION,
		// Languages and participation flags drive PHP-rendered parts of this tab (the hreflang column, the
		// duplicate warning, the x-default choices), so the settings wrapper is re-fetched after such a save.
		'reloadOnSave' => true,
		'refreshKeys'  => array( 'sites' ),
	);

	return $panels;
}

/** Saves the Multilingual tab form. Only reached without JavaScript: the tab otherwise autosaves over REST. */
function erankly_mlms_handle_network_settings_save(): void {
	check_admin_referer( 'erankly_mlms_save_settings' );

	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}

	$raw = isset( $_POST['erml_ms_settings'] ) ? wp_unslash( (array) $_POST['erml_ms_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized inside erankly_mlms_sanitize_settings().

	erankly_mlms_save_settings( $raw );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'        => 'erankly',
				'erankly_tab' => 'multilingual',
				'erankly_mlms_saved'  => '1',
			),
			network_admin_url( 'settings.php' )
		)
	);
	exit;
}

/**
 * Renders the Multilingual tab body. The panel is standalone: it owns its form, which autosaves over REST (see
 * erankly_mlms_register_autosave_panel()) and falls back to a plain POST submit only when JavaScript is unavailable.
 */
function erankly_mlms_render_settings_tab(): void {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	$settings            = erankly_mlms_get_settings();
	$network_sites       = erankly_mlms_get_network_sites();
	$enabled_sites       = erankly_mlms_get_enabled_sites();
	$language_choices    = erankly_mlms_get_language_choices();
	$linkable_post_types = erankly_mlms_get_linkable_post_types();
	$linkable_taxonomies = erankly_mlms_get_linkable_taxonomies();
	$x_default           = (int) $settings['x_default_blog'];
	$resolved_hreflangs  = array();
	$duplicate_hreflangs = array();

	foreach ( $network_sites as $site ) {
		$config   = $settings['sites'][ $site['blog_id'] ] ?? array();
		$language = erankly_mlms_resolve_site_language( $site['blog_id'], (string) ( $config['language'] ?? '' ) );
		$hreflang = erankly_mlms_locale_to_hreflang( $language );

		$resolved_hreflangs[ $site['blog_id'] ] = $hreflang;

		if ( '' === $hreflang ) {
			continue;
		}

		$duplicate_hreflangs[ $hreflang ][] = $site['blog_id'];
	}

	$duplicate_hreflangs = array_filter(
		$duplicate_hreflangs,
		static fn( array $blog_ids ): bool => count( $blog_ids ) > 1
	);
	?>
	<?php if ( isset( $_GET['erankly_mlms_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag. ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Multilingual settings saved.', 'easyrankly' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=erankly_mlms_save_settings' ) ); ?>">
		<?php wp_nonce_field( 'erankly_mlms_save_settings' ); ?>

		<div class="erankly-settings-section">
			<div class="erankly-section-title-row">
				<h3 class="erankly-section-title"><?php esc_html_e( 'Sites and languages', 'easyrankly' ); ?></h3>
			</div>
			<div class="erankly-card">
				<p class="description">
					<?php esc_html_e( 'Every participating site is one language: its locale becomes the site hreflang (it_IT becomes it-it). Home pages are always cross-linked, all other content only where translations are linked.', 'easyrankly' ); ?>
				</p>
				<table class="widefat striped erankly-panel-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Site', 'easyrankly' ); ?></th>
							<th class="erankly-mlms-col-participates"><?php esc_html_e( 'Participates', 'easyrankly' ); ?></th>
							<th class="erankly-mlms-col-language"><?php esc_html_e( 'Language', 'easyrankly' ); ?></th>
							<th class="erankly-mlms-col-hreflang"><?php esc_html_e( 'hreflang', 'easyrankly' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $network_sites ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No public sites found on this network.', 'easyrankly' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $network_sites as $site ) : ?>
							<?php
							$blog_id  = $site['blog_id'];
							$config   = $settings['sites'][ $blog_id ] ?? array();
							$language = (string) ( $config['language'] ?? '' );
							$hreflang = $resolved_hreflangs[ $blog_id ];
							?>
						<tr>
							<td>
								<strong><?php echo esc_html( $site['name'] ); ?></strong><br>
								<span class="description"><?php echo esc_html( $site['url'] ); ?></span>
							</td>
							<td>
								<label>
									<input type="checkbox" class="erankly-toggle" name="erml_ms_settings[sites][<?php echo esc_attr( (string) $blog_id ); ?>][enabled]" value="1" <?php checked( ! isset( $config['enabled'] ) || ! empty( $config['enabled'] ) ); ?>>
									<span class="screen-reader-text"><?php esc_html_e( 'Site participates in the cluster', 'easyrankly' ); ?></span>
								</label>
							</td>
							<td>
								<select name="erml_ms_settings[sites][<?php echo esc_attr( (string) $blog_id ); ?>][language]" class="widefat">
									<option value="" <?php selected( '' === $language ); ?>>
										<?php
										printf(
											/* translators: %s: site locale. */
											esc_html__( 'Site language (%s)', 'easyrankly' ),
											esc_html( erankly_mlms_resolve_site_language( $blog_id, '' ) )
										);
										?>
									</option>
									<?php foreach ( $language_choices as $locale => $label ) : ?>
										<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $language, $locale ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><code><?php echo esc_html( '' !== $hreflang ? $hreflang : '—' ); ?></code></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $duplicate_hreflangs ) ) : ?>
				<div class="notice notice-warning erankly-mlms-panel-notice">
					<p>
						<?php
						$lines = array();
						foreach ( $duplicate_hreflangs as $hreflang => $blog_ids ) {
							/* translators: 1: hreflang tag, 2: comma-separated list of blog IDs. */
							$lines[] = sprintf( __( '"%1$s" on sites %2$s', 'easyrankly' ), $hreflang, implode( ', ', $blog_ids ) );
						}
						printf(
							/* translators: %s: list of duplicate hreflang assignments. */
							esc_html__( 'Duplicate hreflang: %s. Give each participating site a distinct language, or search engines will use only the first.', 'easyrankly' ),
							esc_html( implode( '; ', $lines ) )
						);
						?>
					</p>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="erankly-settings-section">
			<div class="erankly-section-title-row">
				<h3 class="erankly-section-title"><?php esc_html_e( 'Default language (x-default)', 'easyrankly' ); ?></h3>
			</div>
			<div class="erankly-card">
				<div class="erankly-field">
					<label for="erankly-mlms-x-default-blog"><?php esc_html_e( 'Site', 'easyrankly' ); ?></label>
					<select id="erankly-mlms-x-default-blog" name="erml_ms_settings[x_default_blog]" class="widefat erankly-field-full-width">
						<option value="0" <?php selected( 0 === $x_default ); ?>><?php esc_html_e( 'No x-default tag', 'easyrankly' ); ?></option>
						<?php foreach ( $enabled_sites as $site ) : ?>
							<option value="<?php echo esc_attr( (string) $site['blog_id'] ); ?>" <?php selected( $x_default, $site['blog_id'] ); ?>>
								<?php
								printf(
									/* translators: 1: site name, 2: hreflang tag. */
									esc_html__( '%1$s (%2$s)', 'easyrankly' ),
									esc_html( $site['name'] ),
									esc_html( $site['hreflang'] )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Served to visitors whose language matches none of the alternates. Usually the site in your main language.', 'easyrankly' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div class="erankly-settings-section">
			<div class="erankly-section-title-row">
				<h3 class="erankly-section-title"><?php esc_html_e( 'Linkable content', 'easyrankly' ); ?></h3>
			</div>
			<div class="erankly-card">
				<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
					<legend><?php esc_html_e( 'Post types', 'easyrankly' ); ?></legend>
					<div class="erankly-checkbox-options">
						<?php foreach ( $linkable_post_types as $post_type => $label ) : ?>
							<label>
								<input type="checkbox" class="erankly-toggle" name="erml_ms_settings[post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $settings['post_types'], true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
					<legend><?php esc_html_e( 'Taxonomies', 'easyrankly' ); ?></legend>
					<div class="erankly-checkbox-options">
						<?php foreach ( $linkable_taxonomies as $taxonomy => $label ) : ?>
							<label>
								<input type="checkbox" class="erankly-toggle" name="erml_ms_settings[taxonomies][]" value="<?php echo esc_attr( $taxonomy ); ?>" <?php checked( in_array( $taxonomy, $settings['taxonomies'], true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<p class="description">
					<?php esc_html_e( 'Translations are linked from the "Linked translations" box in the editor and on term edit screens. Links stay in sync on both sides; no content is copied or translated.', 'easyrankly' ); ?>
				</p>
			</div>
		</div>

		<noscript>
			<div class="erankly-settings-submit">
				<?php submit_button( __( 'Save multilingual settings', 'easyrankly' ), 'primary', 'submit', false ); ?>
			</div>
		</noscript>
	</form>
	<?php
}
