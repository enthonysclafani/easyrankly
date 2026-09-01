<?php
/**
 * Plugin Name:       EasyRankly
 * Plugin URI:        https://easyrankly.com
 * Description:       Adds per-content and global head/body code, search visibility, automatic SEO/social metadata, and article/breadcrumb schema.
 * Version:           1.14.0
 * Requires at least: 7.1
 * Requires PHP:      7.4
 * Author:            EasyRankly
 * Author URI:        https://easyrankly.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyrankly
 * Domain Path:       /languages
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EasyRankly plugin bootstrap.
 */
final class ERankly_Plugin {
	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private const VERSION = '1.14.0';

	/**
	 * Plugins that take ownership of SEO metadata in the document head.
	 *
	 * EasyRankly must not register any of its features while one of these
	 * plugins is active, otherwise pages can receive competing metadata.
	 *
	 * @var string[]
	 */
	private const HEAD_OWNER_PLUGINS = array(
		'all-in-one-seo-pack/all_in_one_seo_pack.php',
		'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
		'autodescription/autodescription.php',
		'platinum-seo-pack/platinum_seo_pack.php',
		'smartcrawl-seo/wpmu-dev-seo.php',
		'slim-seo/slim-seo.php',
		'squirrly-seo/squirrly.php',
		'surerank/surerank.php',
		'seo-by-rank-math/rank-math.php',
		'seo-by-rank-math-pro/rank-math-pro.php',
		'seo-ultimate/seo-ultimate.php',
		'wordpress-seo/wp-seo.php',
		'wordpress-seo-premium/wp-seo-premium.php',
		'wp-meta-seo/wp-meta-seo.php',
		'wp-seopress/seopress.php',
		'wp-seopress-pro/seopress-pro.php',
	);

	/**
	 * Post meta key used to store the code.
	 *
	 * @var string
	 */
	private const META_KEY = 'erankly_code';

	/**
	 * Site option used to store code printed on every front-end page.
	 *
	 * @var string
	 */
	private const GLOBAL_HEAD_OPTION = 'erankly_global_code';

	/**
	 * Post meta key used to store code printed after the opening body tag.
	 *
	 * @var string
	 */
	private const BODY_START_META_KEY = 'erankly_body_start_code';

	/**
	 * Site option used to store global code printed after the opening body tag.
	 *
	 * @var string
	 */
	private const GLOBAL_BODY_START_OPTION = 'erankly_global_body_start_code';

	/**
	 * Post meta key used to store code printed near the closing body tag.
	 *
	 * @var string
	 */
	private const BODY_END_META_KEY = 'erankly_body_end_code';

	/**
	 * Site option used to store global code printed near the closing body tag.
	 *
	 * @var string
	 */
	private const GLOBAL_BODY_END_OPTION = 'erankly_global_body_end_code';

	/**
	 * Post meta key used to store search visibility.
	 *
	 * @var string
	 */
	private const VISIBILITY_META_KEY = 'erankly_visibility';

	/**
	 * Post meta key used to store the SEO description.
	 *
	 * @var string
	 */
	private const DESCRIPTION_META_KEY = 'erankly_meta_description';

	/**
	 * Site option that owns the default social image.
	 *
	 * @var string
	 */
	private const SOCIAL_SETTINGS_OPTION = 'erankly_social_settings';

	/**
	 * User meta key for an author's X (Twitter) handle.
	 *
	 * @var string
	 */
	private const TWITTER_USER_META_KEY = 'erankly_twitter_handle';

	/**
	 * Post types for which the meta field was registered in this request.
	 *
	 * @var string[]
	 */
	private static $registered_post_types = array();

	/**
	 * Known active plugins that caused EasyRankly to stand down.
	 *
	 * @var string[]
	 */
	private static $detected_head_owner_plugins = array();

	/**
	 * Whether the visible dates were already added to the queried post.
	 *
	 * @var bool
	 */
	private static $printed_article_dates = false;

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::has_active_head_owner() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( self::class, 'render_head_owner_notice' ) );
			}

			return;
		}

		// Revision objects use their own subtype, so inherit these checks explicitly.
		add_filter( 'sanitize_post_meta_' . self::META_KEY . '_for_revision', array( self::class, 'sanitize_head_code' ), 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::BODY_START_META_KEY . '_for_revision', array( self::class, 'sanitize_raw_code' ), 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::BODY_END_META_KEY . '_for_revision', array( self::class, 'sanitize_raw_code' ), 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::DESCRIPTION_META_KEY . '_for_revision', 'sanitize_textarea_field', 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::VISIBILITY_META_KEY . '_for_revision', array( self::class, 'sanitize_visibility' ), 10, 4 );
		add_filter( 'auth_post_meta_' . self::META_KEY . '_for_revision', array( self::class, 'authorize_head_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::BODY_START_META_KEY . '_for_revision', array( self::class, 'authorize_head_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::BODY_END_META_KEY . '_for_revision', array( self::class, 'authorize_head_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::DESCRIPTION_META_KEY . '_for_revision', array( self::class, 'authorize_editor_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::VISIBILITY_META_KEY . '_for_revision', array( self::class, 'authorize_editor_meta' ), 10, 6 );

		add_action( 'init', array( self::class, 'register_global_code_settings' ), 20 );
		add_action( 'init', array( self::class, 'register_meta' ), 20 );
		add_action( 'admin_init', array( self::class, 'register_social_settings' ) );
		add_action( 'admin_menu', array( self::class, 'register_social_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_social_settings_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		add_action( 'wp_head', array( self::class, 'print_meta_description' ), 5 );
		add_action( 'wp_head', array( self::class, 'print_social_preview' ), 5 );
		add_action( 'wp_head', array( self::class, 'print_article_schema' ), 10 );
		add_action( 'wp_head', array( self::class, 'print_breadcrumb_schema' ), 10 );
		add_action( 'wp_head', array( self::class, 'print_head_code' ), 100 );
		add_action( 'wp_body_open', array( self::class, 'print_body_start_code' ), 0 );
		add_action( 'wp_footer', array( self::class, 'print_body_end_code' ), 100 );
		add_filter( 'render_block_core/post-content', array( self::class, 'prepend_article_dates' ), 10, 3 );
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ), 20 );
		add_filter( 'wp_headers', array( self::class, 'filter_robots_headers' ), 20 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'filter_sitemap_post_query_args' ), 20, 2 );
		add_filter( 'user_contactmethods', array( self::class, 'add_twitter_contact_method' ) );
	}

	/**
	 * Checks site and network plugins for another owner of document-head SEO.
	 *
	 * The active-plugin options make detection independent of plugin load order.
	 * Runtime signals also cover renamed plugin directories and must-use plugins.
	 *
	 * @return bool Whether EasyRankly must remain completely inactive.
	 */
	private static function has_active_head_owner() {
		$site_plugins = get_option( 'active_plugins', array() );

		if ( ! is_array( $site_plugins ) ) {
			$site_plugins = array();
		}

		$network_plugins = array();

		if ( is_multisite() ) {
			$sitewide_plugins = get_site_option( 'active_sitewide_plugins', array() );

			if ( is_array( $sitewide_plugins ) ) {
				$network_plugins = array_keys( $sitewide_plugins );
			}
		}

		$active_plugins = array_values(
			array_filter(
				array_merge( $site_plugins, $network_plugins ),
				'is_string'
			)
		);
		$active_plugins = array_unique(
			array_map(
				'wp_normalize_path',
				$active_plugins
			)
		);

		/**
		 * Filters plugin basenames that make EasyRankly remain inactive.
		 *
		 * This lets custom and third-party head owners participate without a
		 * change to EasyRankly itself.
		 *
		 * @param string[] $owner_plugins Plugin basenames relative to the plugins directory.
		 */
		$owner_plugins = apply_filters( 'erankly_owner_plugins', self::HEAD_OWNER_PLUGINS );

		if ( ! is_array( $owner_plugins ) ) {
			$owner_plugins = array();
		}

		$owner_plugins = array_values( array_filter( $owner_plugins, 'is_string' ) );
		$owner_plugins = array_map( 'wp_normalize_path', $owner_plugins );
		$matches       = array_values( array_intersect( $active_plugins, $owner_plugins ) );
		$detected      = ! empty( $matches );
		self::$detected_head_owner_plugins = $matches;

		if ( ! $detected ) {
			$detected = defined( 'WPSEO_VERSION' )
				|| defined( 'RANK_MATH_VERSION' )
				|| defined( 'AIOSEO_VERSION' )
				|| defined( 'SEOPRESS_VERSION' )
				|| defined( 'THE_SEO_FRAMEWORK_VERSION' )
				|| function_exists( 'YoastSEO' )
				|| function_exists( 'rank_math' )
				|| function_exists( 'aioseo' )
				|| function_exists( 'seopress_init' )
				|| function_exists( 'the_seo_framework' );
		}

		/**
		 * Filters the final head-owner detection result.
		 *
		 * Must-use and custom plugins can return true here when they manage the
		 * same metadata even if they have no public runtime signal.
		 *
		 * @param bool     $detected      Whether another head owner was detected.
		 * @param string[] $active_plugins Active site and network plugin basenames.
		 * @param string[] $owner_plugins  Known head-owner plugin basenames.
		 */
		return (bool) apply_filters(
			'erankly_has_active_head_owner',
			$detected,
			$active_plugins,
			$owner_plugins
		);
	}

	/**
	 * Shows administrators why EasyRankly is inactive on this request.
	 *
	 * @return void
	 */
	public static function render_head_owner_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$owners = self::get_detected_head_owner_names();
		$message = empty( $owners )
			? __( 'EasyRankly is inactive because another plugin manages SEO metadata in the document head.', 'easyrankly' )
			: sprintf(
				/* translators: %s: SEO plugin names. */
				__( 'EasyRankly is inactive because %s manages SEO metadata in the document head.', 'easyrankly' ),
				implode( ', ', $owners )
			);

		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Resolves display names for detected head-owner plugins.
	 *
	 * @return string[]
	 */
	private static function get_detected_head_owner_names() {
		if ( empty( self::$detected_head_owner_plugins ) ) {
			return array();
		}

		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$names   = array();

		foreach ( self::$detected_head_owner_plugins as $plugin_file ) {
			if ( isset( $plugins[ $plugin_file ]['Name'] ) && is_string( $plugins[ $plugin_file ]['Name'] ) ) {
				$names[] = $plugins[ $plugin_file ]['Name'];
			} else {
				$names[] = $plugin_file;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Registers the plugin metadata for public, REST-enabled post types.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'names'
		);

		foreach ( $post_types as $post_type ) {
			if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
				continue;
			}

			$revisions_enabled = post_type_supports( $post_type, 'revisions' );
			$head_args          = array(
				'auth_callback'     => array( self::class, 'authorize_head_meta' ),
				'default'           => '',
				'revisions_enabled' => $revisions_enabled,
				'sanitize_callback' => array( self::class, 'sanitize_head_code' ),
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			);
			$body_args = $head_args;
			$body_args['sanitize_callback'] = array( self::class, 'sanitize_raw_code' );

			$description_args = array(
				'auth_callback'     => array( self::class, 'authorize_editor_meta' ),
				'default'           => '',
				'revisions_enabled' => $revisions_enabled,
				'sanitize_callback' => 'sanitize_textarea_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			);

			$visibility_args = array(
				'auth_callback'     => array( self::class, 'authorize_editor_meta' ),
				'default'           => 'index',
				'revisions_enabled' => $revisions_enabled,
				'sanitize_callback' => array( self::class, 'sanitize_visibility' ),
				'show_in_rest'      => array(
					'schema' => array(
						'default' => 'index',
						'enum'    => array( 'index', 'noindex' ),
						'type'    => 'string',
					),
				),
				'single'            => true,
				'type'              => 'string',
			);
			$head_registered        = register_post_meta( $post_type, self::META_KEY, $head_args );
			$body_start_registered  = register_post_meta( $post_type, self::BODY_START_META_KEY, $body_args );
			$body_end_registered    = register_post_meta( $post_type, self::BODY_END_META_KEY, $body_args );
			$description_registered = register_post_meta(
				$post_type,
				self::DESCRIPTION_META_KEY,
				$description_args
			);
			$visibility_registered  = register_post_meta(
				$post_type,
				self::VISIBILITY_META_KEY,
				$visibility_args
			);
			if (
				$head_registered
				&& $body_start_registered
				&& $body_end_registered
				&& $description_registered
				&& $visibility_registered
			) {
				self::$registered_post_types[] = $post_type;
			}
		}
	}

	/**
	 * Registers global raw-code options for trusted administrators.
	 *
	 * Conditional registration keeps the raw option out of the REST settings
	 * schema for users who cannot publish unfiltered HTML.
	 *
	 * @return void
	 */
	public static function register_global_code_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}

		$settings = array(
			self::GLOBAL_HEAD_OPTION       => array(
				'description' => __( 'Code printed in the document head on every front-end page.', 'easyrankly' ),
				'label'       => __( 'Global head code', 'easyrankly' ),
				'sanitize'    => array( self::class, 'sanitize_head_code' ),
			),
			self::GLOBAL_BODY_START_OPTION => array(
				'description' => __( 'Code printed after the opening body tag on every front-end page.', 'easyrankly' ),
				'label'       => __( 'Global body start code', 'easyrankly' ),
				'sanitize'    => array( self::class, 'sanitize_raw_code' ),
			),
			self::GLOBAL_BODY_END_OPTION   => array(
				'description' => __( 'Code printed near the closing body tag on every front-end page.', 'easyrankly' ),
				'label'       => __( 'Global body end code', 'easyrankly' ),
				'sanitize'    => array( self::class, 'sanitize_raw_code' ),
			),
		);

		foreach ( $settings as $option_name => $setting ) {
			register_setting(
				'erankly',
				$option_name,
				array(
					'default'           => '',
					'description'       => $setting['description'],
					'label'             => $setting['label'],
					'sanitize_callback' => $setting['sanitize'],
					'show_in_rest'      => true,
					'type'              => 'string',
				)
			);
		}
	}

	/**
	 * Allows trusted editors to update the raw code for content they can edit.
	 *
	 * @param bool     $allowed  Whether access is currently allowed.
	 * @param string   $meta_key Meta key being checked.
	 * @param int      $post_id  Post ID being checked.
	 * @param int      $user_id  User ID being checked.
	 * @param string   $cap      Requested capability.
	 * @param string[] $caps     Primitive capabilities required by WordPress.
	 * @return bool
	 */
	public static function authorize_head_meta( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
		return self::can_edit_meta( $post_id ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * Allows editors to update metadata for content they can edit.
	 *
	 * @param bool     $allowed  Whether access is currently allowed.
	 * @param string   $meta_key Meta key being checked.
	 * @param int      $post_id  Post ID being checked.
	 * @param int      $user_id  User ID being checked.
	 * @param string   $cap      Requested capability.
	 * @param string[] $caps     Primitive capabilities required by WordPress.
	 * @return bool
	 */
	public static function authorize_editor_meta( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) {
		return self::can_edit_meta( $post_id );
	}

	/**
	 * Checks whether the current user can edit the post associated with meta.
	 *
	 * @param int $post_id Post or revision ID being checked.
	 * @return bool
	 */
	private static function can_edit_meta( $post_id ) {
		$post_id   = absint( $post_id );
		$parent_id = wp_is_post_revision( $post_id );

		if ( $parent_id ) {
			$post_id = $parent_id;
		}

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Validates raw head code while preserving intentional HTML, CSS, and JS.
	 * The managed description tag is removed to prevent duplicate metadata.
	 *
	 * Authorization is enforced separately by authorize_head_meta().
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_head_code( $value ) {
		$value = self::sanitize_raw_code( $value );
		$value = preg_replace(
			'~<meta\b(?=[^>]*\sname\s*=\s*(?:"description"|\'description\'|description)(?=\s|/?>))[^>]*>~i',
			'',
			$value
		);

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Validates trusted raw code without altering intentional markup or scripts.
	 *
	 * Authorization is enforced separately by authorize_head_meta().
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_raw_code( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = str_replace( "\0", '', $value );
		$value = wp_check_invalid_utf8( $value, true );

		return trim( $value );
	}

	/**
	 * Restricts search visibility to the supported values.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_visibility( $value ) {
		return 'noindex' === $value ? 'noindex' : 'index';
	}

	/**
	 * Sanitizes a URL used in social metadata.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_social_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url   = esc_url_raw( trim( wp_check_invalid_utf8( $value, true ) ), array( 'http', 'https' ) );
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
	 * Registers the site-wide social-preview settings.
	 *
	 * @return void
	 */
	public static function register_social_settings() {
		register_setting(
			'erankly_social_settings',
			self::SOCIAL_SETTINGS_OPTION,
			array(
				'default'           => array( 'default_image_id' => 0 ),
				'sanitize_callback' => array( self::class, 'sanitize_social_settings' ),
			)
		);

		add_settings_section(
			'erankly_social_previews',
			'',
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

	/**
	 * Adds the plugin settings screen below Settings.
	 *
	 * @return void
	 */
	public static function register_social_settings_page() {
		add_options_page(
			__( 'EasyRankly', 'easyrankly' ),
			__( 'EasyRankly', 'easyrankly' ),
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
	public static function enqueue_social_settings_assets( $hook_suffix ) {
		if ( 'settings_page_erankly' !== $hook_suffix || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$script_path = plugin_dir_path( __FILE__ ) . 'assets/social-settings.js';

		wp_enqueue_media();
		wp_enqueue_script(
			'erankly-social-settings',
			plugins_url( 'assets/social-settings.js', __FILE__ ),
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
	public static function render_social_settings_field() {
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
				<?php else : ?>
					<img id="erankly-social-image-preview" class="hidden" src="" alt="">
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
	public static function render_social_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h1>
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
	 * @return array{default_image_id: int}
	 */
	public static function sanitize_social_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$image_id = isset( $settings['default_image_id'] ) ? absint( $settings['default_image_id'] ) : 0;

		// Migrate a legacy local image URL when the setting is next read or saved.
		if ( ! $image_id && isset( $settings['default_image_url'] ) ) {
			$legacy_url = self::sanitize_social_url( $settings['default_image_url'] );
			$image_id   = $legacy_url ? absint( attachment_url_to_postid( $legacy_url ) ) : 0;
		}

		return array( 'default_image_id' => wp_attachment_is_image( $image_id ) ? $image_id : 0 );
	}

	/**
	 * Adds an author's X (Twitter) handle to WordPress' Contact Info fields.
	 *
	 * @param array<string, string> $methods Contact methods.
	 * @return array<string, string>
	 */
	public static function add_twitter_contact_method( $methods ) {
		if ( ! is_array( $methods ) ) {
			$methods = array();
		}

		$methods[ self::TWITTER_USER_META_KEY ] = __( 'X (Twitter) handle', 'easyrankly' );

		return $methods;
	}

	/**
	 * Returns the sanitized social settings with defaults.
	 *
	 * @return array{default_image_id: int}
	 */
	private static function get_social_settings() {
		$settings = get_option( self::SOCIAL_SETTINGS_OPTION, array() );

		return self::sanitize_social_settings( $settings );
	}

	/**
	 * Normalizes a bare or @-prefixed X (Twitter) handle.
	 *
	 * @param mixed $handle Submitted handle.
	 * @return string
	 */
	private static function normalize_twitter_handle( $handle ) {
		if ( ! is_string( $handle ) ) {
			return '';
		}

		$handle = ltrim( trim( sanitize_text_field( $handle ) ), '@' );

		if ( '' === $handle || ! preg_match( '/^[A-Za-z0-9_]+$/', $handle ) ) {
			return '';
		}

		return '@' . $handle;
	}

	/**
	 * Loads the editor integration on supported block editor screens.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		$screen = get_current_screen();

		if (
			! $screen
			|| ! $screen->is_block_editor()
			|| ! $screen->post_type
			|| ! in_array( $screen->post_type, self::$registered_post_types, true )
		) {
			return;
		}

		$script_path = plugin_dir_path( __FILE__ ) . 'assets/editor.js';
		$style_path  = plugin_dir_path( __FILE__ ) . 'assets/editor.css';

		wp_enqueue_style(
			'erankly-editor',
			plugins_url( 'assets/editor.css', __FILE__ ),
			array( 'wp-components', 'wp-preferences' ),
			self::asset_version( $style_path )
		);

		wp_enqueue_script(
			'erankly-editor',
			plugins_url( 'assets/editor.js', __FILE__ ),
			array(
				'wp-api-fetch',
				'wp-block-editor',
				'wp-components',
				'wp-core-data',
				'wp-data',
				'wp-editor',
				'wp-element',
				'wp-i18n',
				'wp-notices',
				'wp-plugins',
			),
			self::asset_version( $script_path ),
			true
		);

		$can_edit_global_code = current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
		$editor_settings      = array(
			'bodyEndMetaKey'              => self::BODY_END_META_KEY,
			'bodyStartMetaKey'            => self::BODY_START_META_KEY,
			'canEditCode'                 => current_user_can( 'unfiltered_html' ),
			'canEditGlobalCode'           => $can_edit_global_code,
			'descriptionMetaKey'          => self::DESCRIPTION_META_KEY,
			'globalBodyEndCode'           => $can_edit_global_code ? self::get_global_body_code( self::GLOBAL_BODY_END_OPTION ) : '',
			'globalBodyEndOptionKey'      => self::GLOBAL_BODY_END_OPTION,
			'globalBodyStartCode'         => $can_edit_global_code ? self::get_global_body_code( self::GLOBAL_BODY_START_OPTION ) : '',
			'globalBodyStartOptionKey'    => self::GLOBAL_BODY_START_OPTION,
			'globalHeadCode'              => $can_edit_global_code ? self::get_global_head_code() : '',
			'globalHeadOptionKey'         => self::GLOBAL_HEAD_OPTION,
			'headMetaKey'                 => self::META_KEY,
			'visibilityMetaKey'           => self::VISIBILITY_META_KEY,
		);
		$editor_settings = wp_json_encode(
			$editor_settings,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( is_string( $editor_settings ) ) {
			wp_add_inline_script(
				'erankly-editor',
				'window.eranklyEditorSettings = ' . $editor_settings . ';',
				'before'
			);
		}

		wp_set_script_translations(
			'erankly-editor',
			'easyrankly',
			plugin_dir_path( __FILE__ ) . 'languages'
		);
	}

	/**
	 * Prints the resolved description for search engines.
	 *
	 * The explicit EasyRankly description takes precedence. A manually entered
	 * WordPress excerpt is the automatic fallback; content is never synthesized.
	 *
	 * @return void
	 */
	public static function print_meta_description() {
		if ( ! is_singular() ) {
			return;
		}

		$description = self::get_content_description( get_queried_object_id() );

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
	public static function print_social_preview() {
		$context = self::get_social_context();

		if ( empty( $context ) ) {
			return;
		}

		$post_id     = isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;
		$title       = isset( $context['title'] ) ? self::normalize_social_text( $context['title'] ) : '';
		$description = isset( $context['description'] ) ? self::normalize_social_text( $context['description'] ) : '';
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
		self::add_social_meta_tag( $tags, 'name', 'twitter:title', $title );
		self::add_social_meta_tag( $tags, 'name', 'twitter:description', $description );
		self::add_social_image_meta_tags( $tags, 'twitter', $image, 'name' );

		if ( 'article' === $type && $post_id ) {
			$author_handle = self::normalize_twitter_handle(
				get_the_author_meta( self::TWITTER_USER_META_KEY, (int) get_post_field( 'post_author', $post_id ) )
			);
			self::add_social_meta_tag( $tags, 'name', 'twitter:creator', $author_handle );
		}

		/**
		 * Filters the resolved social metadata before it is printed.
		 *
		 * Each item has `attribute`, `key`, `value`, and `is_url` entries. Repeated
		 * keys are supported for Open Graph values such as article tags.
		 *
		 * @param array<int, array<string, mixed>> $tags    Resolved meta tags.
		 * @param array<string, mixed>             $context Current social page context.
		 */
		$tags = apply_filters( 'erankly_social_meta_tags', $tags, $context );

		if ( ! is_array( $tags ) ) {
			return;
		}

		$manual_keys = self::get_manual_social_meta_keys( is_singular() ? $post_id : 0 );
		$printed     = false;

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['attribute'] ) || empty( $tag['key'] ) || ! array_key_exists( 'value', $tag ) ) {
				continue;
			}

			$attribute = (string) $tag['attribute'];
			$key       = strtolower( trim( (string) $tag['key'] ) );
			$value     = is_scalar( $tag['value'] ) ? (string) $tag['value'] : '';
			$is_url    = ! empty( $tag['is_url'] );

			if ( ( 'name' !== $attribute && 'property' !== $attribute ) || '' === $key || '' === $value || self::is_manual_social_override( $key, $manual_keys ) ) {
				continue;
			}

			if ( ! $printed ) {
				echo "\n";
				$printed = true;
			}

			self::print_meta_tag( $attribute, $key, $value, $is_url );
		}
	}

	/**
	 * Resolves the active request into a social-preview context.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_social_context() {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || is_embed() || is_404() || is_preview() ) {
			return array();
		}

		$context = array();

		if ( is_singular() ) {
			$post_id = absint( get_queried_object_id() );
			$url     = $post_id ? wp_get_canonical_url( $post_id ) : false;

			if ( ! $post_id || ! is_string( $url ) ) {
				return array();
			}

			$context = self::get_social_post_context( $post_id, $url );
		} elseif ( is_front_page() ) {
			$post_id = absint( get_option( 'page_on_front' ) );
			$context = self::get_social_post_context( $post_id, home_url( '/' ) );
		} elseif ( is_home() ) {
			$post_id = absint( get_option( 'page_for_posts' ) );
			$url     = $post_id ? get_permalink( $post_id ) : home_url( '/' );
			$context = self::get_social_post_context( $post_id, is_string( $url ) ? $url : home_url( '/' ) );
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
				'title'       => sprintf( __( 'Posts by %s', 'easyrankly' ), self::normalize_social_text( $author->display_name ) ),
				'type'        => 'website',
				'url'         => get_author_posts_url( $author_id ),
			);
		} elseif ( is_search() ) {
			$query = self::normalize_social_text( get_search_query( false ) );
			$context = array(
				'description' => self::normalize_social_text( get_bloginfo( 'description' ) ),
				'post_id'     => 0,
				'title'       => '' !== $query ? sprintf( __( 'Search results for %s', 'easyrankly' ), $query ) : __( 'Search results', 'easyrankly' ),
				'type'        => 'website',
				'url'         => get_search_link( $query ),
			);
		} elseif ( is_post_type_archive() || is_date() || is_archive() ) {
			$context = array(
				'description' => self::normalize_social_text( get_the_archive_description() ),
				'post_id'     => 0,
				'title'       => self::normalize_social_text( get_the_archive_title() ),
				'type'        => 'website',
				'url'         => get_pagenum_link(),
			);
		}

		if ( empty( $context ) ) {
			return array();
		}

		if ( ! is_singular() && (int) get_query_var( 'paged' ) > 1 ) {
			$context['url'] = get_pagenum_link();
		}

		if ( empty( $context['title'] ) ) {
			$context['title'] = self::normalize_social_text( get_bloginfo( 'name' ) );
		}

		if ( empty( $context['description'] ) && ! empty( $context['post_id'] ) ) {
			$context['description'] = self::get_content_description( $context['post_id'] );
		}

		return $context;
	}

	/**
	 * Creates a social context from a WordPress post, when one is available.
	 *
	 * @param int    $post_id Content ID, or 0 for a site-level context.
	 * @param string $url     Canonical page URL.
	 * @return array<string, mixed>
	 */
	private static function get_social_post_context( $post_id, $url ) {
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

	/**
	 * Adds one optional meta tag to the resolved list.
	 *
	 * @param array<int, array<string, mixed>> $tags   Meta tags.
	 * @param string                            $attr   Attribute name.
	 * @param string                            $key    Metadata key.
	 * @param mixed                             $value  Metadata value.
	 * @param bool                              $is_url Whether the value is a URL.
	 * @return void
	 */
	private static function add_social_meta_tag( &$tags, $attr, $key, $value, $is_url = false ) {
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

	/**
	 * Adds the appropriate image metadata for an Open Graph or X image.
	 *
	 * @param array<int, array<string, mixed>> $tags      Meta tags.
	 * @param string                            $namespace og or twitter.
	 * @param array<string, mixed>              $image    Resolved image data.
	 * @param string                            $attribute Meta attribute name.
	 * @return void
	 */
	private static function add_social_image_meta_tags( &$tags, $namespace, $image, $attribute ) {
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

	/**
	 * Adds article-specific Open Graph metadata for a WordPress post.
	 *
	 * @param array<int, array<string, mixed>> $tags    Meta tags.
	 * @param int                               $post_id Post ID.
	 * @return void
	 */
	private static function add_article_meta_tags( &$tags, $post_id ) {
		$published = get_post_time( DATE_W3C, true, $post_id );
		$modified  = get_post_modified_time( DATE_W3C, true, $post_id );
		$author_id = (int) get_post_field( 'post_author', $post_id );

		self::add_social_meta_tag( $tags, 'property', 'article:published_time', $published );
		self::add_social_meta_tag( $tags, 'property', 'article:modified_time', $modified );

		if ( $author_id ) {
			self::add_social_meta_tag( $tags, 'property', 'article:author', get_author_posts_url( $author_id ), true );
		}

		$categories = get_the_category( $post_id );

		if ( is_array( $categories ) && ! empty( $categories[0]->name ) ) {
			self::add_social_meta_tag( $tags, 'property', 'article:section', self::normalize_social_text( $categories[0]->name ) );
		}

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

	/**
	 * Selects the X card type from the resolved WordPress image.
	 *
	 * @param array<string, mixed> $image Resolved X image data.
	 * @return string
	 */
	private static function get_twitter_card( $image ) {
		return ! empty( $image['url'] ) ? 'summary_large_image' : 'summary';
	}

	/**
	 * Prints automatic BlogPosting JSON-LD for the queried post.
	 *
	 * Publication and modification values share the same date resolver used by
	 * the visible article dates. Complete manual Article JSON-LD takes ownership.
	 *
	 * @return void
	 */
	public static function print_article_schema() {
		$post_id = self::get_article_post_id();

		if ( ! $post_id || self::has_manual_article_schema( $post_id ) ) {
			return;
		}

		/**
		 * Filters whether EasyRankly should print automatic Article schema.
		 *
		 * @param bool $enabled Whether automatic Article schema is enabled.
		 * @param int  $post_id ID of the queried post.
		 */
		$enabled = (bool) apply_filters( 'erankly_article_schema_enabled', true, $post_id );

		if ( ! $enabled ) {
			return;
		}

		$post  = get_post( $post_id );
		$dates = self::get_article_dates( $post );
		$url   = wp_get_canonical_url( $post_id );

		if ( ! $post || empty( $dates['published'] ) || ! is_string( $url ) || '' === $url ) {
			return;
		}

		$headline = self::normalize_social_text( get_the_title( $post_id ) );

		if ( '' === $headline ) {
			return;
		}

		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'BlogPosting',
			'@id'              => $url . '#article',
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $url,
			),
			'headline'         => $headline,
			'url'              => $url,
			'datePublished'    => $dates['published'],
		);

		if ( ! empty( $dates['modified'] ) ) {
			$schema['dateModified'] = $dates['modified'];
		}

		$author_id = absint( $post->post_author );
		$author    = $author_id ? get_userdata( $author_id ) : false;

		if ( $author ) {
			$author_name = self::normalize_social_text( $author->display_name );

			if ( '' !== $author_name ) {
				$schema['author'] = array(
					'@type' => 'Person',
					'name'  => $author_name,
					'url'   => get_author_posts_url( $author_id ),
				);
			}
		}

		$image = self::get_featured_image_data( $post_id );

		if ( ! empty( $image['url'] ) ) {
			$schema['image'] = $image['url'];
		}

		/**
		 * Filters the automatic BlogPosting data before it is encoded.
		 *
		 * @param array<string, mixed> $schema  Resolved schema data.
		 * @param int                  $post_id ID of the queried post.
		 * @param array<string, mixed> $dates   Resolved publication and modification dates.
		 */
		$schema = apply_filters( 'erankly_article_schema', $schema, $post_id, $dates );

		if ( ! is_array( $schema ) || empty( $schema['@type'] ) || empty( $schema['datePublished'] ) ) {
			return;
		}

		$json = wp_json_encode(
			$schema,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * Adds the publication and update dates immediately before post content.
	 *
	 * The time elements carry the exact same ISO 8601 values used in JSON-LD;
	 * their labels use the site's normal human-readable date format.
	 *
	 * @param string   $block_content Rendered post-content block.
	 * @param array    $parsed_block  Parsed block data.
	 * @param WP_Block $block         Block instance.
	 * @return string Filtered block content.
	 */
	public static function prepend_article_dates( $block_content, $parsed_block, $block ) {
		if (
			self::$printed_article_dates
			|| ! is_singular( 'post' )
			|| is_preview()
			|| is_feed()
			|| ! $block instanceof WP_Block
		) {
			return $block_content;
		}

		$post_id = absint( get_queried_object_id() );

		if ( isset( $block->context['postId'] ) && $post_id !== absint( $block->context['postId'] ) ) {
			return $block_content;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return $block_content;
		}

		$dates = self::get_article_dates( $post );

		if ( empty( $dates['published'] ) || empty( $dates['published_timestamp'] ) ) {
			return $block_content;
		}

		$date_format = (string) get_option( 'date_format' );

		if ( '' === $date_format ) {
			$date_format = 'F j, Y';
		}

		$items = array(
			sprintf(
				'<span class="erankly-article-dates__published">%1$s <time datetime="%2$s">%3$s</time></span>',
				esc_html__( 'Published:', 'easyrankly' ),
				esc_attr( $dates['published'] ),
				esc_html( wp_date( $date_format, $dates['published_timestamp'], wp_timezone() ) )
			),
		);

		if ( ! empty( $dates['modified'] ) && ! empty( $dates['modified_timestamp'] ) ) {
			$items[] = sprintf(
				'<span class="erankly-article-dates__modified">%1$s <time datetime="%2$s">%3$s</time></span>',
				esc_html__( 'Updated:', 'easyrankly' ),
				esc_attr( $dates['modified'] ),
				esc_html( wp_date( $date_format, $dates['modified_timestamp'], wp_timezone() ) )
			);
		}

		$separator = '<span class="erankly-article-dates__separator" aria-hidden="true"> &middot; </span>';
		$markup    = sprintf(
			'<p class="erankly-article-dates has-small-font-size" aria-label="%1$s">%2$s</p>',
			esc_attr__( 'Article dates', 'easyrankly' ),
			implode( $separator, $items )
		);

		self::$printed_article_dates = true;

		return $markup . "\n" . $block_content;
	}

	/**
	 * Returns a public, indexable post eligible for Article schema.
	 *
	 * @return int Post ID, or 0 when no Article schema should be printed.
	 */
	private static function get_article_post_id() {
		if (
			! is_singular( 'post' )
			|| is_preview()
			|| is_feed()
			|| ! (int) get_option( 'blog_public' )
		) {
			return 0;
		}

		$post_id = absint( get_queried_object_id() );
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return 0;
		}

		if ( 'noindex' === get_post_meta( $post_id, self::VISIBILITY_META_KEY, true ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Resolves article dates in the site's timezone.
	 *
	 * `dateModified` is omitted until the post has actually changed after its
	 * publication timestamp, avoiding a misleading duplicate update date.
	 *
	 * @param WP_Post|null $post Post object.
	 * @return array{modified: string, modified_timestamp: int, published: string, published_timestamp: int}
	 */
	private static function get_article_dates( $post ) {
		$empty = array(
			'modified'            => '',
			'modified_timestamp'  => 0,
			'published'           => '',
			'published_timestamp' => 0,
		);

		if ( ! $post instanceof WP_Post ) {
			return $empty;
		}

		$published = get_post_datetime( $post, 'date', 'local' );
		$modified  = get_post_datetime( $post, 'modified', 'local' );

		if ( ! $published ) {
			return $empty;
		}

		$published_timestamp = $published->getTimestamp();
		$modified_timestamp  = $modified ? $modified->getTimestamp() : 0;

		$dates = array(
			'modified'            => '',
			'modified_timestamp'  => 0,
			'published'           => $published->format( DATE_W3C ),
			'published_timestamp' => $published_timestamp,
		);

		if ( $modified && $modified_timestamp > $published_timestamp ) {
			$dates['modified']           = $modified->format( DATE_W3C );
			$dates['modified_timestamp'] = $modified_timestamp;
		}

		return $dates;
	}

	/**
	 * Prints one automatic breadcrumb trail for eligible singular content.
	 *
	 * Effective global or per-content code takes ownership whenever it contains
	 * BreadcrumbList markup, avoiding a second schema for that content.
	 *
	 * @return void
	 */
	public static function print_breadcrumb_schema() {
		$post_id = self::get_breadcrumb_post_id();

		if ( ! $post_id || false !== stripos( self::get_effective_head_code( $post_id ), 'BreadcrumbList' ) ) {
			return;
		}

		/**
		 * Filters whether EasyRankly should print its automatic breadcrumb schema.
		 *
		 * Return false when a theme or custom integration owns BreadcrumbList output
		 * without taking ownership of all document-head SEO metadata.
		 *
		 * @param bool $enabled Whether automatic breadcrumb schema is enabled.
		 * @param int  $post_id ID of the queried content.
		 */
		$enabled = (bool) apply_filters( 'erankly_breadcrumb_schema_enabled', true, $post_id );

		if ( ! $enabled ) {
			return;
		}

		$items = self::get_breadcrumb_items( $post_id );

		if ( ! is_array( $items ) || count( $items ) < 2 ) {
			return;
		}

		$list_items = array();
		$last_index = count( $items ) - 1;

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['label'] ) || ! is_scalar( $item['label'] ) ) {
				return;
			}

			$name = trim(
				wp_specialchars_decode(
					wp_strip_all_tags( (string) $item['label'], true ),
					ENT_QUOTES
				)
			);

			if ( '' === $name ) {
				return;
			}

			$list_item = array(
				'@type'    => 'ListItem',
				'name'     => $name,
				'position' => $index + 1,
			);

			// Google treats the current page URL as optional for the final item.
			if ( $index < $last_index ) {
				if ( empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
					return;
				}

				$url = esc_url_raw( $item['url'] );

				if ( '' === $url ) {
					return;
				}

				$list_item['item'] = $url;
			}

			$list_items[] = $list_item;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list_items,
		);
		$json   = wp_json_encode(
			$schema,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}

		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/**
	 * Returns the queried content ID when it can receive breadcrumb schema.
	 *
	 * Search engines cannot use rich-result markup on private, password-protected,
	 * preview, non-public, or noindex content, so omit it for those requests.
	 *
	 * @return int Content ID, or 0 when no breadcrumb schema should be printed.
	 */
	private static function get_breadcrumb_post_id() {
		if (
			! is_singular()
			|| is_front_page()
			|| is_preview()
			|| is_feed()
			|| ! (int) get_option( 'blog_public' )
		) {
			return 0;
		}

		$post_id = absint( get_queried_object_id() );
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return 0;
		}

		$post_type = get_post_type_object( $post->post_type );

		if ( ! $post_type || ! $post_type->public ) {
			return 0;
		}

		if ( 'noindex' === get_post_meta( $post_id, self::VISIBILITY_META_KEY, true ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Builds breadcrumb items with WordPress core's resolver and filter.
	 *
	 * @param int $post_id Content ID.
	 * @return array<int, array<string, mixed>> Breadcrumb items.
	 */
	private static function get_breadcrumb_items( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$items = array(
			array(
				'label' => __( 'Home', 'easyrankly' ),
				'url'   => home_url( '/' ),
			),
		);
		$path_post      = $post;
		$path_post_type = $post->post_type;

		if ( ! is_post_type_hierarchical( $path_post_type ) && $post->post_parent ) {
			$parent_post = get_post( $post->post_parent );

			if ( $parent_post ) {
				$path_post      = $parent_post;
				$path_post_type = $parent_post->post_type;
			}
		}

		$post_type_object = get_post_type_object( $path_post_type );
		$archive_link     = get_post_type_archive_link( $path_post_type );
		$page_for_posts   = get_option( 'page_for_posts' );

		if (
			$post_type_object
			&& $archive_link
			&& untrailingslashit( home_url() ) !== untrailingslashit( $archive_link )
		) {
			$label = $post_type_object->labels->archives;

			if ( 'post' === $path_post_type && $page_for_posts ) {
				$label = block_core_breadcrumbs_get_post_title( $page_for_posts );
			}

			$items[] = array(
				'label' => $label,
				'url'   => $archive_link,
			);
		}

		// This matches the core block's default `prefersTaxonomy` setting.
		if ( is_post_type_hierarchical( $path_post_type ) || $path_post->post_parent ) {
			$items = array_merge(
				$items,
				block_core_breadcrumbs_get_hierarchical_post_type_breadcrumbs( $path_post->ID )
			);
		} else {
			$items = array_merge(
				$items,
				block_core_breadcrumbs_get_terms_breadcrumbs( $path_post->ID, $path_post_type )
			);
		}

		$is_paged = (int) get_query_var( 'page' ) > 1 || (int) get_query_var( 'cpage' ) > 1;
		$title    = block_core_breadcrumbs_get_post_title( $post );

		if ( $is_paged ) {
			$items[] = array(
				'label' => $title,
				'url'   => get_permalink( $post ),
			);
			$items[] = block_core_breadcrumbs_create_page_number_item(
				(int) get_query_var( 'cpage' ) > 1 ? 'cpage' : 'page'
			);
		} else {
			$items[] = array( 'label' => $title );
		}

		return apply_filters( 'block_core_breadcrumbs_items', $items );
	}

	/**
	 * Resolves a description from existing WordPress content data.
	 *
	 * @param int $post_id Post ID.
	 * @return string Plain-text description, if available.
	 */
	private static function get_content_description( $post_id ) {
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

	/**
	 * Resolves the featured image and its site-wide fallback data.
	 *
	 * @param int $post_id Content ID, if the context owns a post.
	 * @return array{alt: string, height: int, type: string, url: string, width: int}
	 */
	private static function get_social_image_data( $post_id ) {
		$post_id = absint( $post_id );
		$image   = $post_id ? self::get_featured_image_data( $post_id ) : self::get_empty_social_image_data();

		if ( '' === $image['url'] ) {
			$image = self::get_default_social_image_data();
		}

		return $image;
	}

	/**
	 * Resolves the featured image and its Media Library metadata.
	 *
	 * @param int $post_id Post ID.
	 * @return array{alt: string, height: int, type: string, url: string, width: int}
	 */
	private static function get_featured_image_data( $post_id ) {
		$image_id = absint( get_post_thumbnail_id( $post_id ) );

		return $image_id ? self::get_attachment_social_image_data( $image_id ) : self::get_empty_social_image_data();
	}

	/**
	 * Resolves complete social image metadata from a Media Library attachment.
	 *
	 * @param int $image_id Attachment ID.
	 * @return array{alt: string, height: int, type: string, url: string, width: int}
	 */
	private static function get_attachment_social_image_data( $image_id ) {
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

	/**
	 * Gets the site-wide fallback social image.
	 *
	 * @return array{alt: string, height: int, type: string, url: string, width: int}
	 */
	private static function get_default_social_image_data() {
		$settings = self::get_social_settings();

		return self::get_attachment_social_image_data( $settings['default_image_id'] );
	}

	/**
	 * Returns a consistently shaped empty image data array.
	 *
	 * @return array{alt: string, height: int, type: string, url: string, width: int}
	 */
	private static function get_empty_social_image_data() {
		return array(
			'alt'    => '',
			'height' => 0,
			'type'   => '',
			'url'    => '',
			'width'  => 0,
		);
	}

	/**
	 * Converts existing WordPress text into one safe social metadata value.
	 *
	 * @param mixed $value Text to normalize.
	 * @return string Normalized plain text.
	 */
	private static function normalize_social_text( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = wp_check_invalid_utf8( $value, true );
		$value = wp_strip_all_tags( $value, true );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Prints one escaped metadata tag.
	 *
	 * @param string $attribute Attribute name: property or name.
	 * @param string $key       Metadata key.
	 * @param string $value     Metadata value.
	 * @param bool   $is_url    Whether the value is a URL.
	 * @return void
	 */
	private static function print_meta_tag( $attribute, $key, $value, $is_url = false ) {
		$escaped_value = $is_url ? esc_url( $value ) : esc_attr( $value );

		printf(
			'<meta %1$s="%2$s" content="%3$s">' . "\n",
			esc_attr( $attribute ),
			esc_attr( $key ),
			$escaped_value
		);
	}

	/**
	 * Gets sanitized global code intended for the document head.
	 *
	 * @return string Stored global head code, if any.
	 */
	private static function get_global_head_code() {
		$code = self::sanitize_head_code( get_option( self::GLOBAL_HEAD_OPTION, '' ) );

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Gets sanitized global code intended for the document body.
	 *
	 * @param string $option_name Body code option name.
	 * @return string Stored global body code, if any.
	 */
	private static function get_global_body_code( $option_name ) {
		$code = self::sanitize_raw_code( get_option( $option_name, '' ) );

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Gets sanitized per-content code intended for the document head.
	 *
	 * @param int $post_id Content ID.
	 * @return string Stored head code, if any.
	 */
	private static function get_head_code( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$code = self::sanitize_head_code( get_post_meta( $post_id, self::META_KEY, true ) );

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Gets sanitized per-content code intended for the document body.
	 *
	 * @param int    $post_id Content ID.
	 * @param string $meta_key Body code meta key.
	 * @return string Stored body code, if any.
	 */
	private static function get_body_code( $post_id, $meta_key ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$code = self::sanitize_raw_code( get_post_meta( $post_id, $meta_key, true ) );

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Joins global and per-content code in output order.
	 *
	 * @param string $global_code Global code.
	 * @param string $post_code   Per-content code.
	 * @return string Combined code.
	 */
	private static function combine_code( $global_code, $post_code ) {
		if ( '' === $global_code ) {
			return $post_code;
		}

		if ( '' === $post_code ) {
			return $global_code;
		}

		return $global_code . "\n" . $post_code;
	}

	/**
	 * Gets the global and per-content code that applies to one content item.
	 *
	 * @param int $post_id Content ID, or zero for global code only.
	 * @return string Effective head code, if any.
	 */
	private static function get_effective_head_code( $post_id ) {
		return self::combine_code(
			self::get_global_head_code(),
			self::get_head_code( $post_id )
		);
	}

	/**
	 * Gets the global and per-content body code for one output location.
	 *
	 * @param int    $post_id      Content ID, or zero for global code only.
	 * @param string $global_option Body code option name.
	 * @param string $meta_key      Body code meta key.
	 * @return string Effective body code, if any.
	 */
	private static function get_effective_body_code( $post_id, $global_option, $meta_key ) {
		return self::combine_code(
			self::get_global_body_code( $global_option ),
			self::get_body_code( $post_id, $meta_key )
		);
	}

	/**
	 * Checks valid manual JSON-LD for an Article-compatible schema type.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether manual Article schema owns this post.
	 */
	private static function has_manual_article_schema( $post_id ) {
		$code = self::get_effective_head_code( $post_id );

		if ( '' === $code ) {
			return false;
		}

		$pattern = '~<script\b(?=[^>]*\btype\s*=\s*(?:"application/ld\+json"|\'application/ld\+json\'|application/ld\+json(?=\s|>)))[^>]*>(.*?)</script\s*>~is';
		$matches = array();

		if ( ! preg_match_all( $pattern, $code, $matches ) || empty( $matches[1] ) ) {
			return false;
		}

		foreach ( $matches[1] as $json ) {
			$data = json_decode( trim( $json ), true );

			if (
				JSON_ERROR_NONE === json_last_error()
				&& is_array( $data )
				&& self::schema_data_has_type( $data, array( 'article', 'blogposting', 'newsarticle' ) )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively finds a schema.org type in decoded JSON-LD.
	 *
	 * @param array                  $data  Decoded JSON-LD data.
	 * @param array<int, string>     $types Lowercase schema type names.
	 * @return bool Whether a matching type exists.
	 */
	private static function schema_data_has_type( $data, $types ) {
		if ( isset( $data['@type'] ) ) {
			$candidates = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );

			foreach ( $candidates as $candidate ) {
				if ( ! is_string( $candidate ) ) {
					continue;
				}

				$type = preg_replace( '~^https?://schema\.org/~i', '', trim( $candidate ) );
				$type = is_string( $type ) ? preg_replace( '~^schema:~i', '', $type ) : '';

				if ( is_string( $type ) && in_array( strtolower( $type ), $types, true ) ) {
					return true;
				}
			}
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) && self::schema_data_has_type( $value, $types ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds social meta keys explicitly supplied by trusted effective Head code.
	 *
	 * @param int $post_id Content ID, or zero for global code only.
	 * @return array<string, bool> Lowercase meta keys supplied manually.
	 */
	private static function get_manual_social_meta_keys( $post_id ) {
		$code = self::get_effective_head_code( $post_id );

		if ( '' === $code || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return array();
		}

		$processor = new WP_HTML_Tag_Processor( $code );
		$keys      = array();

		while ( $processor->next_tag( 'meta' ) ) {
			$key = $processor->get_attribute( 'property' );

			if ( ! is_string( $key ) || '' === trim( $key ) ) {
				$key = $processor->get_attribute( 'name' );
			}

			if ( ! is_string( $key ) ) {
				continue;
			}

			$key = strtolower( trim( $key ) );

			if ( preg_match( '/^(?:article|fb|og|twitter):/', $key ) ) {
				$keys[ $key ] = true;
			}
		}

		return $keys;
	}

	/**
	 * Checks whether a manual meta tag suppresses an automatic social tag.
	 *
	 * An explicit image tag owns all of its structured properties as well, so an
	 * automatic alt, type, or dimension cannot become attached to the wrong image.
	 *
	 * @param string              $key         Automatic metadata key.
	 * @param array<string, bool> $manual_keys  Keys from trusted Head code.
	 * @return bool
	 */
	private static function is_manual_social_override( $key, $manual_keys ) {
		if ( isset( $manual_keys[ $key ] ) ) {
			return true;
		}

		if ( 0 === strpos( $key, 'og:image' ) ) {
			return isset( $manual_keys['og:image'] ) || isset( $manual_keys['og:image:url'] );
		}

		if ( 0 === strpos( $key, 'twitter:image' ) ) {
			return isset( $manual_keys['twitter:image'] );
		}

		return false;
	}

	/**
	 * Prints global code and, on singular views, the current content's code.
	 *
	 * @return void
	 */
	public static function print_head_code() {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		$code    = self::get_effective_head_code( $post_id );

		self::print_raw_code( $code );
	}

	/**
	 * Prints global and current-content code after the opening body tag.
	 *
	 * @return void
	 */
	public static function print_body_start_code() {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		$code    = self::get_effective_body_code(
			$post_id,
			self::GLOBAL_BODY_START_OPTION,
			self::BODY_START_META_KEY
		);

		self::print_raw_code( $code );
	}

	/**
	 * Prints global and current-content code near the closing body tag.
	 *
	 * @return void
	 */
	public static function print_body_end_code() {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		$code    = self::get_effective_body_code(
			$post_id,
			self::GLOBAL_BODY_END_OPTION,
			self::BODY_END_META_KEY
		);

		self::print_raw_code( $code );
	}

	/**
	 * Prints trusted raw code with consistent surrounding whitespace.
	 *
	 * @param string $code Code to print.
	 * @return void
	 */
	private static function print_raw_code( $code ) {
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			return;
		}

		// Deliberately unescaped: only users with unfiltered_html can save this code.
		echo "\n" . $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Adds per-content directives to WordPress' canonical robots tag.
	 *
	 * Selecting index leaves WordPress and other SEO plugins in control, so a
	 * site-wide privacy setting can still prevent indexing.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public static function filter_robots( $robots ) {
		$post_id = self::get_visibility_post_id();

		if ( ! $post_id ) {
			return $robots;
		}

		if ( 'noindex' === get_post_meta( $post_id, self::VISIBILITY_META_KEY, true ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/**
	 * Adds the per-content noindex directive to the HTTP response.
	 *
	 * This mirrors the canonical robots meta tag for WordPress-rendered pages.
	 * Static files bypass WordPress and must be controlled at the web server or
	 * CDN level.
	 *
	 * @param string[] $headers HTTP headers to be sent.
	 * @return string[] Filtered HTTP headers.
	 */
	public static function filter_robots_headers( $headers ) {
		$post_id = self::get_visibility_post_id();

		if ( ! $post_id || 'noindex' !== get_post_meta( $post_id, self::VISIBILITY_META_KEY, true ) ) {
			return $headers;
		}

		$robots_values = array();

		foreach ( $headers as $name => $value ) {
			if ( 'x-robots-tag' !== strtolower( (string) $name ) ) {
				continue;
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$robots_values[] = trim( $value );
			}

			unset( $headers[ $name ] );
		}

		$robots_value = implode( ', ', $robots_values );

		if ( ! preg_match( '/(?:^|,)\s*noindex\s*(?=,|$)/i', $robots_value ) ) {
			$robots_value = '' === $robots_value ? 'noindex' : $robots_value . ', noindex';
		}

		$headers['X-Robots-Tag'] = $robots_value;

		return $headers;
	}

	/**
	 * Excludes content marked noindex from WordPress' native post sitemaps.
	 *
	 * The core provider uses this query for both URL lists and pagination, which
	 * keeps page counts correct without creating or rendering another sitemap.
	 *
	 * @param array  $args      Sitemap WP_Query arguments.
	 * @param string $post_type Post type being mapped.
	 * @return array Sitemap WP_Query arguments.
	 */
	public static function filter_sitemap_post_query_args( $args, $post_type ) {
		if ( ! is_array( $args ) || '' === $post_type ) {
			return $args;
		}

		$visibility_meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => self::VISIBILITY_META_KEY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::VISIBILITY_META_KEY,
				'value'   => 'noindex',
				'compare' => '!=',
			),
		);

		if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = $visibility_meta_query;

			return $args;
		}

		// Keep existing sitemap constraints and ensure they cannot weaken noindex.
		$args['meta_query'] = array(
			'relation' => 'AND',
			$args['meta_query'],
			$visibility_meta_query,
		);

		return $args;
	}

	/**
	 * Gets the content ID that owns the visibility setting for this request.
	 *
	 * WordPress treats the configured posts page as an archive, so it is
	 * `is_home()` rather than `is_singular()` even though it has a page ID.
	 *
	 * @return int Content ID, or 0 when no content visibility setting applies.
	 */
	private static function get_visibility_post_id() {
		if ( is_singular() ) {
			return absint( get_queried_object_id() );
		}

		if ( ! is_home() || 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		return absint( get_option( 'page_for_posts' ) );
	}

	/**
	 * Returns a cache-busting asset version.
	 *
	 * @param string $path Absolute asset path.
	 * @return string
	 */
	private static function asset_version( $path ) {
		$modified = file_exists( $path ) ? filemtime( $path ) : false;

		return $modified ? (string) $modified : self::VERSION;
	}
}

// Let the active theme declare head ownership before registering any output.
add_action( 'after_setup_theme', array( ERankly_Plugin::class, 'init' ), PHP_INT_MAX );
