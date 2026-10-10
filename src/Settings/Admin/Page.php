<?php
/**
 * Admin screens for the plugin settings.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings\Admin;

use EasyRankly\Admin\MediaField;
use EasyRankly\Multilingual\Languages;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the EasyRankly menu with one short settings page per topic, like the WordPress settings.
 *
 * Every page is a Settings API form posted to options.php: the core checks the nonce and
 * manage_options, then saves `easyrankly_settings`. Form::normalize() turns the fields of the
 * page into settings, and Settings::sanitize() keeps the settings of the other pages as they are.
 */
final class Page {

	/**
	 * Menu slug of the top-level menu and of the first settings page.
	 */
	public const SLUG = 'easyrankly';

	/**
	 * Slug of the Titles page.
	 */
	public const TITLES = 'easyrankly-titles';

	/**
	 * Slug of the Indexing page.
	 */
	public const INDEXING = 'easyrankly-indexing';

	/**
	 * Slug of the Schema and social page.
	 */
	public const SCHEMA = 'easyrankly-schema';

	/**
	 * Slug of the Languages page.
	 */
	public const LANGUAGES = 'easyrankly-languages';

	/**
	 * Hooks the menu and the conversion of the submitted forms.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		// Before Settings::sanitize() (priority 10), which validates what the form became.
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Form::class, 'normalize' ), 5 );
	}

	/**
	 * Settings pages, in menu order.
	 *
	 * @return array<string, array{menu: string, title: string}> Menu slug => titles.
	 */
	public static function pages(): array {
		return array(
			self::SLUG      => array(
				'menu'  => __( 'General', 'easyrankly' ),
				'title' => __( 'General settings', 'easyrankly' ),
			),
			self::TITLES    => array(
				'menu'  => __( 'Titles', 'easyrankly' ),
				'title' => __( 'Titles and descriptions', 'easyrankly' ),
			),
			self::INDEXING  => array(
				'menu'  => __( 'Indexing', 'easyrankly' ),
				'title' => __( 'Indexing', 'easyrankly' ),
			),
			self::SCHEMA    => array(
				'menu'  => __( 'Schema and social', 'easyrankly' ),
				'title' => __( 'Schema and social sharing', 'easyrankly' ),
			),
			self::LANGUAGES => array(
				'menu'  => __( 'Languages', 'easyrankly' ),
				'title' => __( 'Languages', 'easyrankly' ),
			),
		);
	}

	/**
	 * Adds the top-level menu and one submenu per settings page; each page sets up its fields
	 * and assets only when it loads.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'EasyRankly', 'easyrankly' ),
			__( 'EasyRankly', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_current' ),
			'dashicons-chart-line'
		);

		foreach ( self::pages() as $slug => $page ) {
			$hook = add_submenu_page( self::SLUG, $page['title'], $page['menu'], 'manage_options', $slug, array( $this, 'render_current' ) );

			if ( false !== $hook ) {
				add_action(
					'load-' . $hook,
					function () use ( $slug ): void {
						$this->load( $slug );
					}
				);
			}
		}
	}

	/**
	 * Registers the sections and fields of one page and its assets.
	 *
	 * @param string $slug Page slug.
	 */
	public function load( string $slug ): void {
		Fields::add( $slug );

		if ( self::SCHEMA === $slug ) {
			add_action( 'admin_enqueue_scripts', array( MediaField::class, 'enqueue' ) );
		}
	}

	/**
	 * Prints the page the admin is on (the core calls page callbacks without arguments).
	 */
	public function render_current(): void {
		$slug = (string) ( $GLOBALS['plugin_page'] ?? '' );
		$this->render( isset( self::pages()[ $slug ] ) ? $slug : self::SLUG );
	}

	/**
	 * Prints one settings page.
	 *
	 * @param string $slug Page slug.
	 */
	public function render( string $slug ): void {
		if ( ! current_user_can( 'manage_options' ) || ! isset( self::pages()[ $slug ] ) ) {
			return;
		}

		$language = self::TITLES === $slug ? Fields::language() : '';

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( self::pages()[ $slug ]['title'] ) );
		settings_errors();

		if ( self::TITLES === $slug ) {
			self::language_links( $language );
		}

		echo '<form action="options.php" method="post">';
		settings_fields( 'easyrankly' );
		printf( '<input type="hidden" name="%s[_page]" value="%s" />', esc_attr( Settings::OPTION ), esc_attr( $slug ) );
		if ( '' !== $language ) {
			printf( '<input type="hidden" name="%s[_language]" value="%s" />', esc_attr( Settings::OPTION ), esc_attr( $language ) );
		}
		do_settings_sections( $slug );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Links to the templates of all languages and of each language, like the post status links.
	 *
	 * @param string $current Language being edited, empty for all languages.
	 */
	private static function language_links( string $current ): void {
		if ( ! Languages::enabled() ) {
			return;
		}

		$base  = menu_page_url( self::TITLES, false );
		$links = array( '' => __( 'All languages', 'easyrankly' ) );
		foreach ( Languages::all() as $slug => $language ) {
			$links[ $slug ] = $language['name'];
		}

		echo '<ul class="subsubsub">';
		$last = array_key_last( $links );
		foreach ( $links as $slug => $name ) {
			printf(
				'<li><a href="%1$s"%2$s>%3$s</a>%4$s</li>',
				esc_url( '' === $slug ? $base : add_query_arg( 'language', $slug, $base ) ),
				$slug === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $name ),
				$slug === $last ? '' : ' |'
			);
		}
		echo '</ul><div class="clear"></div>';
	}
}
