<?php
/**
 * Multilingual content on a single site.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the hidden taxonomies that store the language of each post and its translations.
 *
 * Languages themselves live in the `languages` setting (see Languages). Nothing here runs
 * on the frontend beyond the registrations.
 */
final class Multilingual {

	/**
	 * Hooks the registrations, the cleanup of groups and the routing by language.
	 */
	public function register(): void {
		// Late priority: every viewable post type must exist.
		add_action( 'init', array( $this, 'register_taxonomies' ), 99 );
		add_action( 'before_delete_post', array( Translations::class, 'on_delete_post' ) );

		( new Routing() )->register();
		( new Rest() )->register();
		( new Hreflang() )->register();
		( new Switcher() )->register();
		( new SiteIdentity() )->register();
		( new Menus() )->register();
		( new TemplateParts() )->register();
		add_action( 'wp_sitemaps_init', array( $this, 'register_sitemap' ) );
	}

	/**
	 * Registers both taxonomies on every post type with languages.
	 *
	 * Hidden everywhere: no UI, no URLs, no REST. Only administrators manage the terms;
	 * anyone who can edit posts can assign them.
	 */
	public function register_taxonomies(): void {
		$args = array(
			'public'            => false,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'show_in_nav_menus' => false,
			'show_in_rest'      => false,
			'show_tagcloud'     => false,
			'show_admin_column' => false,
			'hierarchical'      => false,
			'rewrite'           => false,
			'query_var'         => false,
			'capabilities'      => array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'edit_posts',
			),
		);

		register_taxonomy(
			Translations::LANGUAGE,
			Languages::post_types(),
			$args + array(
				'labels' => array(
					'name'          => __( 'Languages', 'easyrankly' ),
					'singular_name' => __( 'Language', 'easyrankly' ),
				),
			)
		);

		register_taxonomy(
			Translations::GROUP,
			Languages::post_types(),
			$args + array(
				'labels' => array(
					'name'          => __( 'Translation groups', 'easyrankly' ),
					'singular_name' => __( 'Translation group', 'easyrankly' ),
				),
			)
		);
	}

	/**
	 * Adds the sitemap of the language homes to the core sitemaps.
	 *
	 * @param mixed $sitemaps Core sitemaps server.
	 */
	public function register_sitemap( $sitemaps ): void {
		if ( $sitemaps instanceof \WP_Sitemaps ) {
			$sitemaps->registry->add_provider( HomesSitemap::NAME, new HomesSitemap() );
		}
	}
}
