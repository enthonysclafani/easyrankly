<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Social {

	/**
	 * Sanitizes a URL used in social metadata and schema.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_social_url( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url   = sanitize_url( trim( wp_check_invalid_utf8( $value, true ) ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );

		if (
			! is_array( $parts )
			|| empty( $parts['host'] )
			|| empty( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
		) {
			return '';
		}

		return $url;
	}

	/**
	 * Registers settings on the native screens that own each concern.
	 *
	 * @return void
	 */
	public static function register_admin_settings(): void {
		self::register_social_settings();
		self::register_site_identity_settings();
	}

	/**
	 * Registers the site-wide social-preview settings.
	 *
	 * @return void
	 */
	public static function register_social_settings(): void {
		register_setting(
			'erankly_social_settings',
			self::SOCIAL_SETTINGS_OPTION,
			array(
				'default'           => array(
					'default_image_id' => 0,
					'profiles'         => array(),
				),
				'sanitize_callback' => array( self::class, 'sanitize_social_settings' ),
				'type'              => 'array',
			)
		);

		add_settings_section(
			'erankly_social_profiles',
			__( 'Site accounts', 'easyrankly' ),
			null,
			'erankly-social'
		);

		add_settings_field(
			'erankly_social_profiles',
			__( 'Profile URLs', 'easyrankly' ),
			array( self::class, 'render_social_profiles_field' ),
			'erankly-social',
			'erankly_social_profiles',
			array( 'label_for' => 'erankly_social_profiles' )
		);

		add_settings_section(
			'erankly_social_previews',
			__( 'Sharing image', 'easyrankly' ),
			null,
			'erankly-social'
		);

		add_settings_field(
			'erankly_social_default_image',
			__( 'Default social image', 'easyrankly' ),
			array( self::class, 'render_social_settings_field' ),
			'erankly-social',
			'erankly_social_previews'
		);
	}

	private static function register_site_identity_settings(): void {
		register_setting(
			'general',
			self::SITE_IDENTITY_OPTION,
			array(
				'default'           => array(
					'person_user_id' => 0,
					'type'           => 'organization',
				),
				'sanitize_callback' => array( self::class, 'sanitize_site_identity' ),
				'type'              => 'array',
			)
		);

		add_settings_field(
			'erankly_site_identity_type',
			__( 'Site identity', 'easyrankly' ),
			array( self::class, 'render_site_identity_type_field' ),
			'general',
			'default',
			array( 'label_for' => 'erankly_site_identity_type' )
		);

		add_settings_field(
			'erankly_site_identity_person',
			__( 'Primary person', 'easyrankly' ),
			array( self::class, 'render_site_identity_person_field' ),
			'general',
			'default',
			array( 'label_for' => 'erankly_site_identity_person_user_id' )
		);
	}

	/**
	 * Renders the site identity type selector.
	 *
	 * @return void
	 */
	public static function render_site_identity_type_field(): void {
		$settings = self::get_site_identity_settings();
		?>
		<select name="<?php echo esc_attr( self::SITE_IDENTITY_OPTION ); ?>[type]" id="erankly_site_identity_type">
			<option value="organization" <?php selected( $settings['type'], 'organization' ); ?>><?php esc_html_e( 'An organization', 'easyrankly' ); ?></option>
			<option value="person" <?php selected( $settings['type'], 'person' ); ?>><?php esc_html_e( 'A person', 'easyrankly' ); ?></option>
		</select>
		<?php if ( self::is_business_profile_ready( self::get_business_profile() ) ) : ?>
			<p class="description"><?php esc_html_e( 'The enabled Local business profile currently owns the primary site identity.', 'easyrankly' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the user associated with a personal site identity.
	 *
	 * @return void
	 */
	public static function render_site_identity_person_field(): void {
		$settings = self::get_site_identity_settings();
		wp_dropdown_users(
			array(
				'name'              => self::SITE_IDENTITY_OPTION . '[person_user_id]',
				'id'                => 'erankly_site_identity_person_user_id',
				'selected'          => $settings['person_user_id'],
				'show_option_none'  => __( 'Select a user', 'easyrankly' ),
				'option_none_value' => 0,
				'show'              => 'display_name_with_login',
			)
		);
	}

	/**
	 * Renders site-level identity profile URLs.
	 *
	 * @return void
	 */
	public static function render_social_profiles_field(): void {
		$settings = self::get_social_settings();
		?>
		<textarea
			class="large-text code"
			id="erankly_social_profiles"
			name="<?php echo esc_attr( self::SOCIAL_SETTINGS_OPTION ); ?>[profiles]"
			rows="6"
		><?php echo esc_textarea( implode( "\n", $settings['profiles'] ) ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Enter one public profile URL per line. These identify the site in structured data; an X profile also supplies twitter:site.', 'easyrankly' ); ?></p>
		<?php
	}

	/**
	 * Adds the plugin settings screen below Settings.
	 *
	 * @return void
	 */
	public static function register_social_settings_page(): void {
		add_options_page(
			__( 'Social', 'easyrankly' ),
			__( 'Social', 'easyrankly' ),
			'manage_options',
			'erankly',
			array( self::class, 'render_social_settings_page' )
		);
	}

	/**
	 * Loads the Media Library only on the Social settings screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_social_settings_assets( $hook_suffix ): void {
		if ( 'settings_page_erankly' !== $hook_suffix || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$script_path = plugin_dir_path( self::FILE ) . 'assets/social-settings.js';

		wp_enqueue_media();
		wp_enqueue_script(
			'erankly-social-settings',
			plugins_url( 'assets/social-settings.js', self::FILE ),
			array( 'jquery', 'media-editor' ),
			self::asset_version( $script_path ),
			true
		);
	}

	/**
	 * Renders the default social image control.
	 *
	 * @return void
	 */
	public static function render_social_settings_field(): void {
		$settings   = self::get_social_settings();
		$image_id   = $settings['default_image_id'];
		$image_html = $image_id
			? wp_get_attachment_image(
				$image_id,
				'medium',
				false,
				array(
					'id' => 'erankly-social-image-preview',
				)
			)
			: '';
		$has_image = '' !== $image_html;
		?>
		<div id="erankly-social-image-control">
			<div id="erankly-social-image-preview-wrap"<?php echo $has_image ? '' : ' class="hidden"'; ?>>
				<?php if ( $has_image ) : ?>
					<?php echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated by WordPress. ?>
				<?php endif; ?>
			</div>
			<input
				type="hidden"
				id="erankly_social_default_image_id"
				name="<?php echo esc_attr( self::SOCIAL_SETTINGS_OPTION ); ?>[default_image_id]"
				value="<?php echo esc_attr( $image_id ); ?>"
			>
			<?php if ( current_user_can( 'upload_files' ) ) : ?>
				<p class="hide-if-no-js">
					<button
						type="button"
						id="erankly-social-image-choose"
						class="button"
						data-choose="<?php esc_attr_e( 'Choose a social image', 'easyrankly' ); ?>"
						data-change="<?php esc_attr_e( 'Change social image', 'easyrankly' ); ?>"
						data-select="<?php esc_attr_e( 'Use as social image', 'easyrankly' ); ?>"
						aria-describedby="erankly-social-image-description"
					>
						<?php echo $has_image ? esc_html__( 'Change social image', 'easyrankly' ) : esc_html__( 'Choose a social image', 'easyrankly' ); ?>
					</button>
					<button
						type="button"
						id="erankly-social-image-remove"
						class="button<?php echo $has_image ? '' : ' hidden'; ?>"
					>
						<?php esc_html_e( 'Remove social image', 'easyrankly' ); ?>
					</button>
				</p>
			<?php endif; ?>
			<p id="erankly-social-image-description" class="description">
				<?php esc_html_e( 'Used when content has no featured image. Alternative text is inherited automatically from the Media Library.', 'easyrankly' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the Social settings page.
	 *
	 * @return void
	 */
	public static function render_social_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Social', 'easyrankly' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'erankly_social_settings' );
				do_settings_sections( 'erankly-social' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitizes the complete social settings option.
	 *
	 * @param mixed $settings Submitted settings.
	 * @return array{default_image_id: int, profiles: string[]}
	 */
	public static function sanitize_social_settings( $settings ): array {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$image_id = isset( $settings['default_image_id'] ) ? absint( $settings['default_image_id'] ) : 0;

		// Migrate a legacy local image URL when the setting is next read or saved.
		if ( ! $image_id && isset( $settings['default_image_url'] ) ) {
			$legacy_url = self::sanitize_social_url( $settings['default_image_url'] );
			$image_id   = $legacy_url ? absint( attachment_url_to_postid( $legacy_url ) ) : 0;
		}

		$profiles = isset( $settings['profiles'] ) ? $settings['profiles'] : array();

		if ( is_string( $profiles ) ) {
			$profiles = preg_split( '/\R/u', $profiles );
		}

		if ( ! is_array( $profiles ) ) {
			$profiles = array();
		}

		$profiles = array_map( array( self::class, 'sanitize_social_url' ), $profiles );
		$profiles = array_values( array_unique( array_filter( $profiles ) ) );

		return array(
			'default_image_id' => wp_attachment_is_image( $image_id ) ? $image_id : 0,
			'profiles'         => $profiles,
		);
	}

	/**
	 * Migrates the retired URL-based default image outside public requests.
	 *
	 * @return void
	 */
	public static function migrate_legacy_social_settings(): void {
		$settings = get_option( self::SOCIAL_SETTINGS_OPTION, array() );

		if ( is_array( $settings ) && array_key_exists( 'default_image_url', $settings ) ) {
			update_option( self::SOCIAL_SETTINGS_OPTION, self::sanitize_social_settings( $settings ) );
			self::reset_runtime_caches();
		}
	}

	/**
	 * Sanitizes the site identity selection.
	 *
	 * @param mixed $settings Submitted settings.
	 * @return array{person_user_id: int, type: string}
	 */
	public static function sanitize_site_identity( $settings ): array {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$user_id = isset( $settings['person_user_id'] ) ? absint( $settings['person_user_id'] ) : 0;

		if ( $user_id && ! get_userdata( $user_id ) ) {
			$user_id = 0;
		}

		return array(
			'person_user_id' => $user_id,
			'type'           => isset( $settings['type'] ) && 'person' === $settings['type'] ? 'person' : 'organization',
		);
	}

	/**
	 * Adds an author's X (Twitter) handle to WordPress' Contact Info fields.
	 *
	 * @param array<string, string> $methods Contact methods.
	 * @return array<string, string>
	 */
	public static function add_twitter_contact_method( $methods ): array {
		if ( ! is_array( $methods ) ) {
			$methods = array();
		}

		$methods[ self::TWITTER_USER_META_KEY ] = __( 'X username (author)', 'easyrankly' );

		return $methods;
	}

	private static function get_social_settings(): array {
		$key = self::get_cache_context_key();

		if ( ! isset( self::$social_settings_cache[ $key ] ) ) {
			self::$social_settings_cache[ $key ] = self::sanitize_social_settings( get_option( self::SOCIAL_SETTINGS_OPTION, array() ) );
		}

		return self::$social_settings_cache[ $key ];
	}

	private static function get_site_identity_settings(): array {
		return self::sanitize_site_identity( get_option( self::SITE_IDENTITY_OPTION, array() ) );
	}

	private static function normalize_twitter_handle( $handle ): string {
		if ( ! is_string( $handle ) ) {
			return '';
		}

		$handle = ltrim( trim( sanitize_text_field( $handle ) ), '@' );

		if ( '' === $handle || ! preg_match( '/^[A-Za-z0-9_]+$/', $handle ) ) {
			return '';
		}

		return '@' . $handle;
	}

	private static function get_site_twitter_handle(): string {
		$profiles = self::get_social_settings()['profiles'];

		foreach ( $profiles as $profile ) {
			$parts = is_string( $profile ) ? wp_parse_url( $profile ) : false;

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
				continue;
			}

			$host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );

			if ( ! in_array( $host, array( 'x.com', 'twitter.com' ), true ) ) {
				continue;
			}

			$segments = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );

			if ( 1 !== count( $segments ) ) {
				continue;
			}

			$handle = self::normalize_twitter_handle( $segments[0] );

			if ( '' !== $handle ) {
				return $handle;
			}
		}

		return '';
	}

	/**
	 * Prints the resolved description for search engines.
	 *
	 * The explicit EasyRankly description takes precedence. A manually entered
	 * WordPress excerpt is the automatic fallback; content is never synthesized.
	 *
	 * @return void
	 */
	public static function print_meta_description(): void {
		$post_id  = self::get_singular_post_id();
		$analysis = self::get_head_analysis( $post_id );

		if ( array_key_exists( 'description', $analysis['meta'] ) ) {
			return;
		}

		$description = self::get_request_description();
		$description = self::normalize_social_text( $description );

		if ( '' === $description ) {
			return;
		}

		echo "\n";
		self::print_meta_tag( 'name', 'description', $description );
	}

	/**
	 * Prints Open Graph and X (Twitter) preview metadata for public page contexts.
	 *
	 * Values come from the data WordPress already owns. A matching meta tag in the
	 * trusted effective Head code takes precedence, so advanced overrides do not create
	 * duplicates.
	 *
	 * @return void
	 */
	public static function print_social_preview(): void {
		$context = self::get_social_context();

		if ( empty( $context ) ) {
			return;
		}

		$post_id     = isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;
		$title       = isset( $context['title'] ) && is_string( $context['title'] )
			? $context['title']
			: '';
		$description = isset( $context['description'] ) && is_string( $context['description'] )
			? $context['description']
			: '';
		$url         = isset( $context['url'] ) ? self::sanitize_social_url( $context['url'] ) : '';
		$type        = isset( $context['type'] ) && 'article' === $context['type'] ? 'article' : 'website';
		$site_name   = self::normalize_social_text( get_bloginfo( 'name' ) );
		$locale      = self::normalize_social_text( get_locale() );
		$image       = self::get_social_image_data( $post_id );

		if ( '' === $title || '' === $url ) {
			return;
		}

		$tags = array();
		self::add_social_meta_tag( $tags, 'property', 'og:title', $title );
		self::add_social_meta_tag( $tags, 'property', 'og:description', $description );
		self::add_social_meta_tag( $tags, 'property', 'og:type', $type );
		self::add_social_meta_tag( $tags, 'property', 'og:url', $url, true );
		self::add_social_meta_tag( $tags, 'property', 'og:locale', $locale );
		self::add_social_meta_tag( $tags, 'property', 'og:site_name', $site_name );
		self::add_social_image_meta_tags( $tags, 'og', $image, 'property' );

		if ( 'article' === $type && $post_id ) {
			self::add_article_meta_tags( $tags, $post_id );
		}

		$twitter_card = self::get_twitter_card( $image );
		self::add_social_meta_tag( $tags, 'name', 'twitter:card', $twitter_card );
		self::add_social_meta_tag( $tags, 'name', 'twitter:site', self::get_site_twitter_handle() );
		self::add_social_meta_tag( $tags, 'name', 'twitter:title', $title );
		self::add_social_meta_tag( $tags, 'name', 'twitter:description', $description );
		self::add_social_image_meta_tags( $tags, 'twitter', $image, 'name' );

		if ( 'article' === $type && $post_id ) {
			$author_handle = self::normalize_twitter_handle(
				get_the_author_meta( self::TWITTER_USER_META_KEY, (int) get_post_field( 'post_author', $post_id ) )
			);
			self::add_social_meta_tag( $tags, 'name', 'twitter:creator', $author_handle );
		}

		$manual_keys = self::get_manual_social_meta_keys( is_singular() ? $post_id : 0 );
		$printed     = false;

		foreach ( $tags as $tag ) {
			$attribute = $tag['attribute'];
			$key       = strtolower( $tag['key'] );
			$value     = $tag['value'];
			$is_url    = $tag['is_url'];

			if ( self::is_manual_social_override( $key, $manual_keys ) ) {
				continue;
			}

			if ( ! $printed ) {
				echo "\n";
				$printed = true;
			}

			self::print_meta_tag( $attribute, $key, $value, $is_url );
		}
	}

	private static function get_social_context(): array {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || is_embed() || is_404() || is_preview() ) {
			return array();
		}

		$context   = array();
		$paged_url = ! is_singular() && (int) get_query_var( 'paged' ) > 1 ? get_pagenum_link() : '';

		if ( is_singular() ) {
			$post_id = self::get_singular_post_id();
			$url     = $post_id ? wp_get_canonical_url( $post_id ) : false;

			if ( ! $post_id || ! is_string( $url ) ) {
				return array();
			}

			$context = self::get_social_post_context( $post_id, $url );
		} elseif ( is_home() ) {
			$post_id = self::get_posts_page_id();
			$url     = $post_id ? get_permalink( $post_id ) : home_url( '/' );
			$context = self::get_social_post_context( $post_id, is_string( $url ) ? $url : home_url( '/' ) );
		} elseif ( is_front_page() ) {
			$context = self::get_social_post_context( 0, home_url( '/' ) );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			$url  = is_object( $term ) ? get_term_link( $term ) : false;

			if ( ! is_object( $term ) || ! is_string( $url ) ) {
				return array();
			}

			$context = array(
				'description' => isset( $term->description ) ? self::normalize_social_text( $term->description ) : '',
				'post_id'     => 0,
				'title'       => isset( $term->name ) ? self::normalize_social_text( $term->name ) : '',
				'type'        => 'website',
				'url'         => $url,
			);
		} elseif ( is_author() ) {
			$author_id = absint( get_queried_object_id() );
			$author    = get_userdata( $author_id );

			if ( ! $author ) {
				return array();
			}

			$context = array(
				'description' => self::normalize_social_text( $author->description ),
				'post_id'     => 0,
				'title'       => self::normalize_social_text(
					sprintf(
						/* translators: %s: author display name. */
						__( 'Posts by %s', 'easyrankly' ),
						$author->display_name
					)
				),
				'type'        => 'website',
				'url'         => get_author_posts_url( $author_id ),
			);
		} elseif ( is_search() ) {
			$query = self::normalize_social_text( get_search_query( false ) );
			$context = array(
				'description' => self::normalize_social_text( get_bloginfo( 'description' ) ),
				'post_id'     => 0,
				'title'       => self::normalize_social_text(
					'' !== $query
						? sprintf(
							/* translators: %s: search query. */
							__( 'Search results for %s', 'easyrankly' ),
							$query
						)
						: __( 'Search results', 'easyrankly' )
				),
				'type'        => 'website',
				'url'         => get_search_link( $query ),
			);
		} elseif ( is_post_type_archive() || is_date() || is_archive() ) {
			$context = array(
				'description' => self::normalize_social_text( get_the_archive_description() ),
				'post_id'     => 0,
				'title'       => self::normalize_social_text( get_the_archive_title() ),
				'type'        => 'website',
				'url'         => '' !== $paged_url ? $paged_url : get_pagenum_link(),
			);
		}

		if ( empty( $context ) ) {
			return array();
		}

		if ( '' !== $paged_url ) {
			$context['url'] = $paged_url;
		}

		if ( empty( $context['title'] ) ) {
			$context['title'] = self::normalize_social_text( get_bloginfo( 'name' ) );
		}

		if ( empty( $context['description'] ) && ! empty( $context['post_id'] ) ) {
			$context['description'] = self::get_content_description( $context['post_id'] );
		}

		if ( ! is_singular() && ! empty( $context['url'] ) ) {
			$context['url'] = self::resolve_non_singular_url( $context['url'] );
		}

		return $context;
	}

	private static function resolve_non_singular_url( $url ): string {
		$analysis = self::get_head_analysis( 0 );

		if ( ! empty( $analysis['canonical_url'] ) ) {
			$url = $analysis['canonical_url'];
		}

		return self::sanitize_social_url( $url );
	}

	private static function get_social_post_context( $post_id, $url ): array {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array(
				'description' => self::normalize_social_text( get_bloginfo( 'description' ) ),
				'post_id'     => 0,
				'title'       => self::normalize_social_text( get_bloginfo( 'name' ) ),
				'type'        => 'website',
				'url'         => $url,
			);
		}

		$post = get_post( $post_id );

		if ( ! $post || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) {
			return array();
		}

		return array(
			'description' => self::get_content_description( $post_id ),
			'post_id'     => $post_id,
			'title'       => self::normalize_social_text( get_the_title( $post_id ) ),
			'type'        => 'post' === $post->post_type ? 'article' : 'website',
			'url'         => $url,
		);
	}

	private static function add_social_meta_tag( &$tags, $attr, $key, $value, $is_url = false ): void {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return;
		}

		$tags[] = array(
			'attribute' => $attr,
			'is_url'    => $is_url,
			'key'       => $key,
			'value'     => (string) $value,
		);
	}

	private static function add_social_image_meta_tags( &$tags, $namespace, $image, $attribute ): void {
		if ( ! is_array( $image ) || empty( $image['url'] ) || ! is_string( $image['url'] ) ) {
			return;
		}

		self::add_social_meta_tag( $tags, $attribute, $namespace . ':image', $image['url'], true );

		if ( ! empty( $image['alt'] ) && is_string( $image['alt'] ) ) {
			self::add_social_meta_tag( $tags, $attribute, $namespace . ':image:alt', $image['alt'] );
		}

		if ( 'og' !== $namespace ) {
			return;
		}

		if ( ! empty( $image['width'] ) ) {
			self::add_social_meta_tag( $tags, $attribute, 'og:image:width', absint( $image['width'] ) );
		}

		if ( ! empty( $image['height'] ) ) {
			self::add_social_meta_tag( $tags, $attribute, 'og:image:height', absint( $image['height'] ) );
		}

		if ( ! empty( $image['type'] ) && is_string( $image['type'] ) ) {
			self::add_social_meta_tag( $tags, $attribute, 'og:image:type', $image['type'] );
		}
	}

	private static function add_article_meta_tags( &$tags, $post_id ): void {
		$published = get_post_time( DATE_W3C, false, $post_id );
		$modified  = get_post_modified_time( DATE_W3C, false, $post_id );
		$author_id = (int) get_post_field( 'post_author', $post_id );

		self::add_social_meta_tag( $tags, 'property', 'article:published_time', $published );
		self::add_social_meta_tag( $tags, 'property', 'article:modified_time', $modified );

		if ( $author_id ) {
			self::add_social_meta_tag( $tags, 'property', 'article:author', get_author_posts_url( $author_id ), true );
		}

		self::add_social_meta_tag( $tags, 'property', 'article:section', self::get_primary_category_name( $post_id ) );

		$tags_for_post = get_the_tags( $post_id );

		if ( ! is_array( $tags_for_post ) ) {
			return;
		}

		foreach ( $tags_for_post as $tag ) {
			if ( is_object( $tag ) && ! empty( $tag->name ) ) {
				self::add_social_meta_tag( $tags, 'property', 'article:tag', self::normalize_social_text( $tag->name ) );
			}
		}
	}

	private static function get_twitter_card( $image ): string {
		return ! empty( $image['url'] ) ? 'summary_large_image' : 'summary';
	}

	private static function get_request_description(): string {
		if ( is_singular() ) {
			$description = self::get_content_description( self::get_singular_post_id() );

			if ( '' !== $description || ! is_front_page() ) {
				return $description;
			}

			return self::normalize_social_text( get_bloginfo( 'description' ) );
		}

		if ( is_home() ) {
			$posts_page_id = self::get_posts_page_id();
			$description   = $posts_page_id ? self::get_content_description( $posts_page_id ) : '';

			return '' !== $description
				? $description
				: self::normalize_social_text( get_bloginfo( 'description' ) );
		}

		if ( is_front_page() ) {
			return self::normalize_social_text( get_bloginfo( 'description' ) );
		}

		if ( is_category() || is_tag() || is_tax() || is_author() || is_post_type_archive() ) {
			return self::normalize_social_text( get_the_archive_description() );
		}

		return '';
	}

	private static function get_content_description( $post_id ): string {
		$description = get_post_meta( $post_id, self::DESCRIPTION_META_KEY, true );
		$description = self::normalize_social_text( $description );

		if ( '' !== $description ) {
			return $description;
		}

		$excerpt = get_post_field( 'post_excerpt', $post_id, 'raw' );

		if ( ! is_string( $excerpt ) || '' === trim( $excerpt ) ) {
			return '';
		}

		return self::normalize_social_text( strip_shortcodes( $excerpt ) );
	}

	private static function get_social_image_data( $post_id ): array {
		$post_id = absint( $post_id );
		$image   = $post_id ? self::get_featured_image_data( $post_id ) : self::get_empty_social_image_data();

		if ( '' === $image['url'] ) {
			$image = self::get_default_social_image_data();
		}

		return $image;
	}

	private static function get_featured_image_data( $post_id ): array {
		$image_id = absint( get_post_thumbnail_id( $post_id ) );

		return $image_id ? self::get_attachment_social_image_data( $image_id ) : self::get_empty_social_image_data();
	}

	private static function get_attachment_social_image_data( $image_id ): array {
		$image_id = absint( $image_id );
		$url      = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : false;

		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return self::get_empty_social_image_data();
		}

		$metadata = wp_get_attachment_metadata( $image_id );
		$width    = is_array( $metadata ) && isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
		$height   = is_array( $metadata ) && isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
		$type     = get_post_mime_type( $image_id );

		return array(
			'alt'    => self::normalize_social_text( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ),
			'height' => $height,
			'type'   => is_string( $type ) ? $type : '',
			'url'    => $url,
			'width'  => $width,
		);
	}

	private static function get_default_social_image_data(): array {
		$settings = self::get_social_settings();

		return self::get_attachment_social_image_data( $settings['default_image_id'] );
	}

	private static function get_empty_social_image_data(): array {
		return array(
			'alt'    => '',
			'height' => 0,
			'type'   => '',
			'url'    => '',
			'width'  => 0,
		);
	}

	private static function normalize_social_text( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = wp_check_invalid_utf8( $value, true );
		$value = wp_strip_all_tags( $value, true );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return is_string( $value ) ? trim( $value ) : '';
	}

	private static function print_meta_tag( $attribute, $key, $value, $is_url = false ): void {
		$escaped_value = $is_url ? esc_url( $value ) : esc_attr( $value );

		printf(
			'<meta %1$s="%2$s" content="%3$s">' . "\n",
			esc_attr( $attribute ),
			esc_attr( $key ),
			$escaped_value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped according to semantic type above.
		);
	}
}
