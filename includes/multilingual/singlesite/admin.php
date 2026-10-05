<?php
/** Single-site configuration and editorial integration; editor assets load only on editor screens. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_admin_boot(): void {
	require_once __DIR__ . '/editor.php';
	erankly_mlss_editor_boot();
	add_filter( 'erankly_settings_tabs', static function ( array $tabs ): array {
		$tabs['multilingual'] = array( 'label' => __( 'Multilingual', 'easyrankly' ), 'capability' => 'manage_options', 'scope' => 'site', 'position' => 50, 'group' => 'feature_modules' );
		return $tabs;
	} );
	add_filter( 'erankly_settings_autosave_client_panels', static function ( array $panels ): array {
		$panels['multilingual'] = array( 'restUrl' => rest_url( 'erankly/v1/multilingual/singlesite/settings' ), 'fieldRoot' => ERANKLY_MLSS_OPTION, 'reloadOnSave' => true, 'refreshKeys' => array( 'languages', 'url_slugs', 'default_language', 'prefix_default', 'menus' ) );
		return $panels;
	} );
	add_action( 'erankly_render_settings_tab_multilingual', 'erankly_mlss_render_settings' );
	add_action( 'admin_post_erankly_mlss_save_settings', 'erankly_mlss_handle_settings_save' );
}

function erankly_mlss_handle_settings_save(): void {
	check_admin_referer( 'erankly_mlss_save_settings' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'easyrankly' ) );
	}
	$result = erankly_mlss_save_settings( isset( $_POST[ ERANKLY_MLSS_OPTION ] ) ? (array) wp_unslash( $_POST[ ERANKLY_MLSS_OPTION ] ) : array() );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ) );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'erankly', 'erankly_tab' => 'multilingual' ), admin_url( 'options-general.php' ) ) );
	exit;
}

function erankly_mlss_render_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = erankly_mlss_get_settings();
	$display_languages = array_map( static function ( string $language ): string {
		return preg_replace_callback(
			'/-([a-z]{2}|[a-z]{4})(?=-|$)/',
			static fn( array $part ): string => '-' . ( 4 === strlen( $part[1] ) ? ucfirst( $part[1] ) : strtoupper( $part[1] ) ),
			$language
		);
	}, $settings['languages'] );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="erankly_mlss_save_settings">
		<?php wp_nonce_field( 'erankly_mlss_save_settings' ); ?>
		<?php erankly_section_open( __( 'Single-site languages', 'easyrankly' ) ); ?>
		<p class="description"><?php esc_html_e( 'Single site detected. Each translation is a native WordPress content item. Existing content belongs to the default language; additional languages use separate URLs. Link or create translations in the content editor.', 'easyrankly' ); ?></p>
		<div class="erankly-field">
			<label for="erankly-mlss-languages"><?php esc_html_e( 'Languages', 'easyrankly' ); ?></label>
			<textarea id="erankly-mlss-languages" class="widefat" rows="4" name="<?php echo esc_attr( ERANKLY_MLSS_OPTION ); ?>[languages]"><?php echo esc_textarea( implode( "\n", $display_languages ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One language tag per line, for example it-IT or en-US. Maximum 32 languages.', 'easyrankly' ); ?></p>
		</div>
		<?php foreach ( $settings['languages'] as $language ) : ?>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( 'erankly-mlss-url-' . $language ); ?>"><?php echo esc_html( sprintf( __( 'URL prefix · %s', 'easyrankly' ), $language ) ); ?></label>
			<input type="text" class="regular-text" id="<?php echo esc_attr( 'erankly-mlss-url-' . $language ); ?>" name="<?php echo esc_attr( ERANKLY_MLSS_OPTION . '[url_slugs][' . $language . ']' ); ?>" value="<?php echo esc_attr( '/' . $settings['url_slugs'][ $language ] . '/' ); ?>" placeholder="/it/" maxlength="66" spellcheck="false" autocomplete="off">
		</div>
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'Set a unique URL prefix for each language, for example it-IT → /it/ and en-US → /en/. Leave a field empty to use its language tag. Language tags and WordPress locales stay unchanged. Changing a prefix changes that language’s public URLs.', 'easyrankly' ); ?></p>
		<?php foreach ( array( 'default_language' => __( 'Default language', 'easyrankly' ), 'x_default_language' => __( 'x-default language', 'easyrankly' ) ) as $key => $label ) : ?>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( 'erankly-mlss-' . $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( 'erankly-mlss-' . $key ); ?>" name="<?php echo esc_attr( ERANKLY_MLSS_OPTION . '[' . $key . ']' ); ?>">
				<?php if ( 'x_default_language' === $key ) : ?><option value=""><?php esc_html_e( 'None', 'easyrankly' ); ?></option><?php endif; ?>
				<?php foreach ( $settings['languages'] as $language ) : ?><option value="<?php echo esc_attr( $language ); ?>" <?php selected( $settings[ $key ], $language ); ?>><?php echo esc_html( $language ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<?php endforeach; ?>
		<div class="erankly-field">
			<label><input type="checkbox" name="<?php echo esc_attr( ERANKLY_MLSS_OPTION ); ?>[prefix_default]" value="1" <?php checked( $settings['prefix_default'] ); ?>> <?php esc_html_e( 'Include the language prefix in default-language URLs', 'easyrankly' ); ?></label>
			<p class="description"><?php esc_html_e( 'Leave this off to preserve existing default-language URLs. Plain permalinks use a language query parameter.', 'easyrankly' ); ?></p>
		</div>
		<p class="description"><?php esc_html_e( 'Add the Language switcher block, or use [erankly_language_switcher]. Theme and plugin interface translations use installed WordPress language packs.', 'easyrankly' ); ?></p>
		<?php erankly_section_close(); ?>
		<?php erankly_mlss_render_shared_strings( $settings ); ?>
		<?php erankly_mlss_render_menus( $settings ); ?>
		<noscript><?php submit_button(); ?></noscript>
	</form>
	<?php
}

function erankly_mlss_render_shared_strings( array $settings ): void {
	$registered = array_merge( array(
		'site_title' => array( 'source' => get_option( 'blogname' ), 'group' => __( 'Site title', 'easyrankly' ) ),
		'site_tagline' => array( 'source' => get_option( 'blogdescription' ), 'group' => __( 'Tagline', 'easyrankly' ) ),
	), $GLOBALS['erankly_mlss_strings_registry'] ?? array() );
	erankly_section_open( __( 'Shared strings', 'easyrankly' ) );
	foreach ( $settings['languages'] as $language ) {
		if ( $language === $settings['default_language'] ) { continue; }
		$dictionary = (array) get_option( 'erankly_multilingual_strings_' . $language, array() );
		foreach ( $registered as $key => $string ) {
			$field = 'erankly-mlss-string-' . $language . '-' . $key;
			printf( '<div class="erankly-field"><label for="%s">%s · %s</label><input class="widefat" id="%s" name="%s[strings][%s][%s]" value="%s"><p class="description">%s</p></div>', esc_attr( $field ), esc_html( $string['group'] ?: $key ), esc_html( $language ), esc_attr( $field ), esc_attr( ERANKLY_MLSS_OPTION ), esc_attr( $language ), esc_attr( $key ), esc_attr( $dictionary[ $key ] ?? '' ), esc_html( $string['source'] ) );
		}
	}
	erankly_section_close();
}

function erankly_mlss_render_menus( array $settings ): void {
	$menus = get_terms( array( 'taxonomy' => 'nav_menu', 'hide_empty' => false, 'number' => 100, 'erankly_lang' => 'all' ) );
	if ( is_wp_error( $menus ) || ! $menus ) { return; }
	$locations = get_nav_menu_locations();
	$source = (int) ( reset( $locations ) ?: $menus[0]->term_id );
	$map = erankly_mlss_get_translations( 'term', $source );
	erankly_section_open( __( 'Navigation menus', 'easyrankly' ) );
	echo '<p class="description">' . esc_html__( 'Connect equivalent classic menus. Native navigation block links also resolve to their translated content.', 'easyrankly' ) . '</p>';
	foreach ( $settings['languages'] as $language ) {
		printf( '<div class="erankly-field"><label for="erankly-mlss-menu-%s">%s</label><select id="erankly-mlss-menu-%s" name="%s[menus][%s]"><option value="0">%s</option>', esc_attr( $language ), esc_html( $language ), esc_attr( $language ), esc_attr( ERANKLY_MLSS_OPTION ), esc_attr( $language ), esc_html__( 'None', 'easyrankly' ) );
		foreach ( $menus as $menu ) { printf( '<option value="%d" %s>%s</option>', $menu->term_id, selected( $map[ $language ] ?? 0, $menu->term_id, false ), esc_html( $menu->name ) ); }
		echo '</select></div>';
	}
	erankly_section_close();
}
