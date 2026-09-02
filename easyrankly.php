<?php
/**
 * Plugin Name:       EasyRankly
 * Plugin URI:        https://easyrankly.com
 * Description:       Adds trusted custom code, search visibility, SEO/social metadata, and structured site and local-business identity.
 * Version:           2.1.0
 * Requires at least: 7.1
 * Requires PHP:      7.4
 * Author:            EasyRankly
 * Author URI:        https://easyrankly.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyrankly
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/erankly-ownership.php';
require_once __DIR__ . '/includes/erankly-variables.php';
require_once __DIR__ . '/includes/erankly-editor.php';
require_once __DIR__ . '/includes/erankly-business.php';
require_once __DIR__ . '/includes/erankly-social.php';
require_once __DIR__ . '/includes/erankly-schema.php';
require_once __DIR__ . '/includes/erankly-output.php';

final class ERankly_Plugin {
	private const FILE = __FILE__;

	private const VERSION = '2.1.0';

	/** Plugins known to emit an overlapping SEO head. */
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

	// Stored names keep the historical prefix as a persistence compatibility boundary.
	private const HEAD_META_KEY = 'erankly_code';

	private const GLOBAL_HEAD_OPTION = 'erankly_global_code';

	private const BODY_START_META_KEY = 'erankly_body_start_code';

	private const GLOBAL_BODY_START_OPTION = 'erankly_global_body_start_code';

	private const BODY_END_META_KEY = 'erankly_body_end_code';

	private const GLOBAL_BODY_END_OPTION = 'erankly_global_body_end_code';

	private const VISIBILITY_META_KEY = 'erankly_visibility';

	private const DESCRIPTION_META_KEY = 'erankly_meta_description';

	private const SOCIAL_SETTINGS_OPTION = 'erankly_social_settings';

	private const SITE_IDENTITY_OPTION = 'erankly_site_identity';

	private const BUSINESS_SETTINGS_OPTION = 'erankly_business_settings';

	private const TWITTER_USER_META_KEY = 'erankly_twitter_handle';

	private static $registered_post_types = array();

	private static $head_analysis_cache = array();
	private static $effective_head_code_cache = array();
	private static $effective_body_code_cache = array();
	private static $code_variables_cache = array();
	private static $is_resolving_code = false;
	private static $omit_sentinel = '';
	private static $business_profile_cache = array();
	private static $social_settings_cache = array();
	private static $site_identity_schema_cache = array();

	use ERankly_Ownership;
	use ERankly_Variables;
	use ERankly_Editor;
	use ERankly_Business;
	use ERankly_Social;
	use ERankly_Schema;
	use ERankly_Output;

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Revision objects use their own subtype, so inherit these checks explicitly.
		add_filter( 'sanitize_post_meta_' . self::HEAD_META_KEY . '_for_revision', array( self::class, 'sanitize_raw_code' ), 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::BODY_START_META_KEY . '_for_revision', array( self::class, 'sanitize_raw_code' ), 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::BODY_END_META_KEY . '_for_revision', array( self::class, 'sanitize_raw_code' ), 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::DESCRIPTION_META_KEY . '_for_revision', 'sanitize_textarea_field', 10, 4 );
		add_filter( 'sanitize_post_meta_' . self::VISIBILITY_META_KEY . '_for_revision', array( self::class, 'sanitize_visibility' ), 10, 4 );
		add_filter( 'auth_post_meta_' . self::HEAD_META_KEY . '_for_revision', array( self::class, 'authorize_head_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::BODY_START_META_KEY . '_for_revision', array( self::class, 'authorize_head_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::BODY_END_META_KEY . '_for_revision', array( self::class, 'authorize_head_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::DESCRIPTION_META_KEY . '_for_revision', array( self::class, 'authorize_editor_meta' ), 10, 6 );
		add_filter( 'auth_post_meta_' . self::VISIBILITY_META_KEY . '_for_revision', array( self::class, 'authorize_editor_meta' ), 10, 6 );

		add_action( 'init', array( self::class, 'register_global_code_settings' ), 20 );
		add_action( 'init', array( self::class, 'register_meta' ), 20 );
		add_action( 'registered_post_type', array( self::class, 'register_post_type_meta' ), 20, 2 );
		add_action( 'init', array( self::class, 'register_business_profile_block' ), 20 );
		add_action( 'admin_init', array( self::class, 'migrate_legacy_social_settings' ), 5 );
		add_action( 'admin_init', array( self::class, 'register_business_settings' ) );
		add_action( 'admin_menu', array( self::class, 'register_business_settings_page' ) );
		add_action( 'admin_notices', array( self::class, 'render_head_owner_notice' ) );
		add_action( 'network_admin_notices', array( self::class, 'render_head_owner_notice' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		add_action( 'wp_head', array( self::class, 'claim_title_ownership' ), 0 );
		add_action( 'wp_head', array( self::class, 'print_head_code' ), 100 );
		add_action( 'wp_body_open', array( self::class, 'print_body_start_code' ), 0 );
		add_action( 'wp_footer', array( self::class, 'print_body_end_code' ), 100 );
		add_action( 'wp', array( self::class, 'prepare_request_ownership' ), 20 );
		add_filter( 'get_canonical_url', array( self::class, 'filter_core_canonical_url' ), 20, 2 );
		add_shortcode( 'easyrankly_business_profile', array( self::class, 'render_business_profile_shortcode' ) );

		add_action( 'admin_init', array( self::class, 'register_admin_settings' ) );
		add_action( 'admin_menu', array( self::class, 'register_social_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_social_settings_assets' ) );
		add_action( 'wp_head', array( self::class, 'print_meta_description' ), 5 );
		add_action( 'wp_head', array( self::class, 'print_social_preview' ), 5 );
		add_action( 'wp_head', array( self::class, 'print_schema_graph' ), 10 );
		add_filter( 'wp_robots', array( self::class, 'filter_robots' ), 20 );
		add_filter( 'wp_headers', array( self::class, 'filter_robots_headers' ), 20 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'filter_sitemap_post_query_args' ), 20, 2 );
		add_filter( 'posts_where', array( self::class, 'filter_sitemap_posts_where' ), 20, 2 );
		add_filter( 'user_contactmethods', array( self::class, 'add_twitter_contact_method' ) );
	}

	/** Warns administrators without changing runtime ownership behavior. */
	public static function render_head_owner_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$site_plugins    = (array) get_option( 'active_plugins', array() );
		$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$conflicts       = array_intersect( self::HEAD_OWNER_PLUGINS, array_merge( $site_plugins, $network_plugins ) );

		if ( empty( $conflicts ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'EasyRankly is the exclusive SEO owner. Deactivate the other active SEO plugin to prevent duplicate metadata and structured data.', 'easyrankly' ); ?></p>
		</div>
		<?php
	}

	private static function get_cache_context_key(): string {
		return get_current_blog_id() . '|' . get_locale();
	}

	private static function reset_runtime_caches(): void {
		self::$business_profile_cache     = array();
		self::$code_variables_cache       = array();
		self::$effective_body_code_cache  = array();
		self::$effective_head_code_cache  = array();
		self::$head_analysis_cache        = array();
		self::$site_identity_schema_cache = array();
		self::$social_settings_cache      = array();
	}

	private static function asset_version( $path ): string {
		$modified = file_exists( $path ) ? filemtime( $path ) : false;

		return $modified ? (string) $modified : self::VERSION;
	}
}

ERankly_Plugin::init();

if ( ! function_exists( 'erankly_get_business_profile' ) ) {
	/**
	 * Returns EasyRankly's normalized local-business profile.
	 *
	 * @deprecated 2.0.1 Use ERankly_Plugin::get_business_profile().
	 * @return array<string, mixed>
	 */
	function erankly_get_business_profile(): array {
		_deprecated_function( __FUNCTION__, '2.0.1', 'ERankly_Plugin::get_business_profile()' );

		return ERankly_Plugin::get_business_profile();
	}
}
