<?php
/** Network settings, the per-site language map and hreflang derivation. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the default module settings. The core Feature modules toggle controls activation; this separate network option
 * only configures participating sites, languages and linkable content.
 *
 * @return array{sites:array<int,array{language:string,enabled:int}>,x_default_blog:int,post_types:array<int,string>,taxonomies:array<int,string>}
 */
function erankly_mlms_default_settings(): array {
	return array(
		'sites'          => array(),
		'x_default_blog' => get_main_site_id(),
		'post_types'     => array( 'post', 'page' ),
		'taxonomies'     => array( 'category', 'post_tag' ),
	);
}

/**
 * Returns the network module settings (request-level cached).
 *
 * @return array{sites:array<int,array{language:string,enabled:int}>,x_default_blog:int,post_types:array<int,string>,taxonomies:array<int,string>}
 */
function erankly_mlms_get_settings(): array {
	return erankly_mlms_cached(
		'settings',
		static function (): array {
			$stored   = get_site_option( ERANKLY_MLMS_OPTION, array() );
			$settings = is_array( $stored ) ? array_merge( erankly_mlms_default_settings(), $stored ) : erankly_mlms_default_settings();

			return erankly_mlms_normalize_settings( $settings );
		}
	);
}

/**
 * Coerces a stored settings array into its canonical shape. Deliberately free of WordPress lookups: the runtime
 * cache can be built before custom post types are registered, so eligibility checks against
 * get_post_types()/get_taxonomies() happen at save time (admin context) and at use time instead.
 *
 * @return array{sites:array<int,array{language:string,enabled:int}>,x_default_blog:int,post_types:array<int,string>,taxonomies:array<int,string>}
 */
function erankly_mlms_normalize_settings( array $settings ): array {
	$sites = array();

	foreach ( (array) ( $settings['sites'] ?? array() ) as $blog_id => $config ) {
		$blog_id = absint( $blog_id );

		if ( 0 === $blog_id || ! is_array( $config ) ) {
			continue;
		}

		$sites[ $blog_id ] = array(
			'language' => erankly_mlms_sanitize_locale( (string) ( $config['language'] ?? '' ) ),
			'enabled'  => empty( $config['enabled'] ) ? 0 : 1,
		);
	}

	ksort( $sites );

	return array(
		'sites'          => $sites,
		'x_default_blog' => max( 0, (int) ( $settings['x_default_blog'] ?? 0 ) ),
		'post_types'     => array_values( array_unique( array_map( 'sanitize_key', (array) ( $settings['post_types'] ?? array() ) ) ) ),
		'taxonomies'     => array_values( array_unique( array_map( 'sanitize_key', (array) ( $settings['taxonomies'] ?? array() ) ) ) ),
	);
}

/**
 * Validates and normalizes a raw settings payload coming from the Multilingual tab form. Site eligibility is
 * checked here (admin-only context) rather than at read time, so the runtime cache never depends on blog
 * existence or on post type registration order. Unknown top-level keys (including the legacy `enabled` flag of
 * releases before 1.2.0) are dropped by the normalization.
 *
 * @return array{sites:array<int,array{language:string,enabled:int}>,x_default_blog:int,post_types:array<int,string>,taxonomies:array<int,string>}
 */
function erankly_mlms_sanitize_settings( mixed $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$settings = erankly_mlms_normalize_settings( $input );

	$sites = array();

	foreach ( (array) ( $input['sites'] ?? array() ) as $blog_id => $config ) {
		$blog_id = absint( $blog_id );

		if ( 0 === $blog_id || null === get_site( $blog_id ) ) {
			continue; // Drop entries for sites that no longer exist.
		}

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$sites[ $blog_id ] = array(
			'language' => erankly_mlms_sanitize_locale( (string) ( $config['language'] ?? '' ) ),
			'enabled'  => empty( $config['enabled'] ) ? 0 : 1,
		);
	}

	$settings['sites']      = $sites;
	$settings['post_types'] = array_values( array_intersect( $settings['post_types'], array_keys( erankly_mlms_get_linkable_post_types() ) ) );
	$settings['taxonomies'] = array_values( array_intersect( $settings['taxonomies'], array_keys( erankly_mlms_get_linkable_taxonomies() ) ) );

	$x_default = max( 0, (int) ( $input['x_default_blog'] ?? 0 ) );
	$settings['x_default_blog'] = $x_default > 0 && null !== get_site( $x_default ) ? $x_default : 0;

	return $settings;
}

/** Sanitizes and stores the network settings, then resets the request caches. */
function erankly_mlms_save_settings( mixed $input ): void {
	update_site_option( ERANKLY_MLMS_OPTION, erankly_mlms_sanitize_settings( $input ) );
	erankly_mlms_flush_runtime_caches();
}

/**
 * Registers the REST route the Multilingual tab autosaves to. The tab renders inside the EasyRankly settings
 * screen, whose admin JS posts a panel's fields on every change instead of waiting for a submit button (see
 * bindSettingsAutosave() in easyrankly/assets/js/admin-settings.js); the panel is pointed at this route by
 * erankly_mlms_register_autosave_panel() in includes/admin/settings-tab.php. Registered outside the is_admin() branch of
 * erankly_mlms_bootstrap(): a REST request is not an admin request.
 */
function erankly_mlms_register_settings_rest_route(): void {
	register_rest_route(
		'erankly/v1/multilingual/multisite',
		'/settings',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'erankly_mlms_rest_save_settings',
			// The language map is a network option and the tab lives in the Network Admin, so a per-site
			// admin must never reach this route.
			'permission_callback' => static fn(): bool => current_user_can( 'manage_network_options' ),
			'args'                => array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Persists an autosave payload from the Multilingual tab. The panel always posts every field it renders, so the
 * payload is a complete settings array and needs no per-panel merge step the way the core routes do:
 * erankly_mlms_save_settings() sanitizes it (dropping unknown keys, stale blog IDs and ineligible post types) and
 * flushes the runtime caches.
 */
function erankly_mlms_rest_save_settings( WP_REST_Request $request ): WP_REST_Response {
	erankly_mlms_save_settings( (array) $request->get_param( 'settings' ) );

	return new WP_REST_Response(
		array(
			'saved'    => true,
			'warnings' => array(),
		),
		200
	);
}

/** Validates a stored-or-submitted locale code. Empty means "follow the site language". */
function erankly_mlms_sanitize_locale( string $locale ): string {
	$locale = trim( $locale );

	if ( '' === $locale ) {
		return '';
	}

	return 1 === preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,10})*$/', $locale ) ? $locale : '';
}

/**
 * Public post types that can carry translation links. Attachment pages are excluded: media items never form
 * hreflang clusters.
 *
 * @return array<string,string> Map of post type name to singular label.
 */
function erankly_mlms_get_linkable_post_types(): array {
	return erankly_mlms_cached(
		'linkable_post_types',
		static function (): array {
			$linkable = array();

			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
				if ( 'attachment' === $post_type->name ) {
					continue;
				}

				$linkable[ $post_type->name ] = (string) $post_type->labels->singular_name;
			}

			ksort( $linkable );

			return $linkable;
		}
	);
}

/**
 * Public, UI-visible taxonomies that can carry translation links. post_format is excluded: format archives are
 * technical pages, not language alternates.
 *
 * @return array<string,string> Map of taxonomy name to singular label.
 */
function erankly_mlms_get_linkable_taxonomies(): array {
	return erankly_mlms_cached(
		'linkable_taxonomies',
		static function (): array {
			$linkable = array();

			foreach ( get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $taxonomy ) {
				if ( 'post_format' === $taxonomy->name ) {
					continue;
				}

				$linkable[ $taxonomy->name ] = (string) $taxonomy->labels->singular_name;
			}

			ksort( $linkable );

			return $linkable;
		}
	);
}

/**
 * All public, non-archived sites of the network, in stable blog_id order. Used by the settings tab; the runtime
 * cluster builder (erankly_mlms_get_enabled_sites()) applies the per-site participation flags on top of this list.
 *
 * @return array<int,array{blog_id:int,name:string,url:string}>
 */
function erankly_mlms_get_network_sites(): array {
	return erankly_mlms_cached(
		'network_sites',
		static function (): array {
			$sites = array();

			if ( ! is_multisite() ) {
				return $sites;
			}

			foreach ( get_sites( array( 'public' => 1, 'spam' => 0, 'deleted' => 0, 'archived' => 0 ) ) as $site ) {
				$blog_id = (int) $site->blog_id;

				$sites[ $blog_id ] = array(
					'blog_id' => $blog_id,
					'name'    => (string) get_blog_option( $blog_id, 'blogname' ),
					'url'     => (string) get_home_url( $blog_id, '/' ),
				);
			}

			return $sites;
		}
	);
}

/**
 * Resolves the language of one site. An explicit per-site override wins; otherwise the site's own WordPress
 * locale applies (WPLANG of the site, falling back to the network default).
 */
function erankly_mlms_resolve_site_language( int $blog_id, string $override ): string {
	if ( '' !== $override ) {
		return $override;
	}

	return erankly_mlms_cached(
		'site_language_' . $blog_id,
		static function () use ( $blog_id ): string {
			return erankly_mlms_site_locale( $blog_id );
		}
	);
}

/**
 * Reads the locale of one site without the global memo. get_locale() caches the first answer in the $locale
 * global and in WP_Locale, so it is not switch_to_blog-safe: on a request started on blog A it would report
 * blog A's locale for every switched-to blog. This mirrors get_locale()'s option chain per blog instead:
 * the WPLANG constant (wp-config), the site's WPLANG option, the network WPLANG option, then en_US.
 */
function erankly_mlms_site_locale( int $blog_id ): string {
	$switched = get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		$locale = '';

		if ( defined( 'WPLANG' ) ) {
			$locale = (string) WPLANG;
		}

		if ( '' === $locale ) {
			$db_locale = get_option( 'WPLANG' );

			if ( false !== $db_locale && '' !== (string) $db_locale ) {
				$locale = (string) $db_locale;
			} elseif ( false === $db_locale ) {
				$ms_locale = get_site_option( 'WPLANG' );

				if ( false !== $ms_locale && '' !== (string) $ms_locale ) {
					$locale = (string) $ms_locale;
				}
			}
		}

		if ( '' === $locale ) {
			$locale = 'en_US';
		}

		/**
		 * Filters the resolved site locale, mirroring the core 'locale' filter so locale-override plugins stay
		 * effective. Core applies it after its own resolution; the per-blog value is passed here.
		 *
		 * @param string $locale  Resolved locale of the site.
		 * @param int    $blog_id Site whose locale is being resolved.
		 */
		return (string) apply_filters( 'erankly_mlms_site_locale', $locale, $blog_id );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/** Returns the effective language (locale) of one site, honoring the per-site override. */
function erankly_mlms_get_site_language( int $blog_id ): string {
	$settings = erankly_mlms_get_settings();

	return erankly_mlms_resolve_site_language( $blog_id, (string) ( $settings['sites'][ $blog_id ]['language'] ?? '' ) );
}

/**
 * Derives a hreflang tag from a WordPress locale: it_IT becomes it-it, de_CH_informal becomes de-ch-informal.
 * Output case is normalized downstream by EasyRankly (hreflang matching is case-insensitive per BCP 47).
 *
 * The result is validated against the host plugin's own tag grammar rather than trusted. The locale validator
 * upstream of this is deliberately permissive (it has to accept locales this install has never seen), so it
 * admits shapes that erankly_clean_hreflang_alternates() later drops -- and a tag dropped there disappears
 * silently from the head. Rejecting it here instead keeps the settings table honest: a site whose language
 * cannot produce a usable tag shows no tag and stays out of the cluster, which is the same outcome, made
 * visible. Falls through unvalidated only when the host plugin is not loaded (WP-CLI edge cases, uninstall).
 */
function erankly_mlms_locale_to_hreflang( string $locale ): string {
	$locale = strtolower( trim( $locale ) );

	if ( '' === $locale ) {
		return '';
	}

	$hreflang = str_replace( '_', '-', $locale );

	if ( function_exists( 'erankly_is_valid_hreflang_tag' ) && ! erankly_is_valid_hreflang_tag( $hreflang ) ) {
		return '';
	}

	return $hreflang;
}

/** Returns the hreflang tag of one site, or '' when the site has no resolvable language. */
function erankly_mlms_get_site_hreflang( int $blog_id ): string {
	return erankly_mlms_locale_to_hreflang( erankly_mlms_get_site_language( $blog_id ) );
}

/**
 * Returns whether the linking UI can work right now: the network must have at least two participating sites
 * including the current one. The module itself is active whenever the add-on runs on Multisite.
 */
function erankly_mlms_is_active_for_linking(): bool {
	$sites = erankly_mlms_get_enabled_sites();

	return count( $sites ) >= 2 && isset( $sites[ get_current_blog_id() ] );
}

/** Returns whether a site participates in the cluster (participation defaults to on). */
function erankly_mlms_site_participates( int $blog_id ): bool {
	$config = erankly_mlms_get_settings()['sites'][ $blog_id ] ?? null;

	return ! ( is_array( $config ) && empty( $config['enabled'] ) );
}

/**
 * Returns the participating public sites that form the hreflang cluster, keyed by blog_id in ascending order so
 * that duplicate hreflangs resolve deterministically (lowest blog_id wins).
 *
 * @return array<int,array{blog_id:int,name:string,url:string,language:string,hreflang:string}>
 */
function erankly_mlms_get_enabled_sites(): array {
	return erankly_mlms_cached(
		'enabled_sites',
		static function (): array {
			$sites = array();

			if ( ! is_multisite() ) {
				return $sites;
			}

			$limit      = (int) apply_filters( 'erankly_mlms_max_enabled_sites', 0 ); // 0 = unlimited.
			$query_args = array(
				'public'   => 1,
				'spam'     => 0,
				'deleted'  => 0,
				'archived' => 0,
			);

			if ( $limit > 0 ) {
				$query_args['number'] = $limit;
			}

			foreach ( get_sites( $query_args ) as $site ) {
				$blog_id = (int) $site->blog_id;

				if ( ! erankly_mlms_site_participates( $blog_id ) ) {
					continue;
				}

				$language = erankly_mlms_get_site_language( $blog_id );
				$hreflang = erankly_mlms_locale_to_hreflang( $language );

				if ( '' === $hreflang ) {
					continue;
				}

				$sites[ $blog_id ] = array(
					'blog_id'  => $blog_id,
					'name'     => (string) get_blog_option( $blog_id, 'blogname' ),
					'url'      => (string) get_home_url( $blog_id, '/' ),
					'language' => $language,
					'hreflang' => $hreflang,
				);
			}

			erankly_mlms_report_duplicate_hreflangs( $sites );

			return $sites;
		}
	);
}

/**
 * Surfaces sites that resolve to the same hreflang as a registry diagnostic, so the notice appears next to the
 * EasyRankly provider diagnostics in the admin. The cluster builder keeps the first (lowest blog_id) site.
 *
 * @param array<int,array{blog_id:int,name:string,url:string,language:string,hreflang:string}> $sites
 */
function erankly_mlms_report_duplicate_hreflangs( array $sites ): void {
	$by_hreflang = array();

	foreach ( $sites as $site ) {
		$by_hreflang[ $site['hreflang'] ][] = $site['blog_id'];
	}

	$duplicates = array_filter(
		$by_hreflang,
		static fn( array $blog_ids ): bool => count( $blog_ids ) > 1
	);

	if ( empty( $duplicates ) || ! class_exists( 'ERankly_Multilingual_Provider_Registry' ) ) {
		return;
	}

	$lines = array();

	foreach ( $duplicates as $hreflang => $blog_ids ) {
		/* translators: 1: hreflang tag, 2: comma-separated list of blog IDs. */
		$lines[] = sprintf( __( '"%1$s" on sites %2$s', 'easyrankly' ), $hreflang, implode( ', ', $blog_ids ) );
	}

	ERankly_Multilingual_Provider_Registry::instance()->add_diagnostic(
		'erankly_mlms_duplicate_hreflang',
		sprintf(
			/* translators: %s: list of duplicate hreflang assignments. */
			__( 'EasyRankly Multilingual: duplicate hreflang (%s). Give each participating site a distinct language, or search engines will use only the first.', 'easyrankly' ),
			implode( '; ', $lines )
		),
		array( 'provider_id' => ERANKLY_MLMS_PROVIDER_ID )
	);
}

/** Returns the language choices for the settings selects: installed packs, en_US and every network site locale. */
function erankly_mlms_get_language_choices(): array {
	return erankly_mlms_cached(
		'language_choices',
		static function (): array {
			$choices = array(
				'en_US' => __( 'English (United States)', 'easyrankly' ),
			);

			$translations = function_exists( 'wp_get_available_translations' ) ? (array) wp_get_available_translations() : array();

			foreach ( get_available_languages() as $locale ) {
				$locale = (string) $locale;

				if ( 'en_US' === $locale || '' === $locale ) {
					continue;
				}

				$choices[ $locale ] = (string) ( $translations[ $locale ]['native_name'] ?? $locale );
			}

			// Every network site locale stays selectable even when its pack is not installed here.
			foreach ( erankly_mlms_get_network_sites() as $site ) {
				$locale = erankly_mlms_resolve_site_language( $site['blog_id'], '' );

				if ( '' !== $locale && ! isset( $choices[ $locale ] ) ) {
					$choices[ $locale ] = $locale;
				}
			}

			ksort( $choices );

			return $choices;
		}
	);
}

/** Request-level cache readers. Centralized so tests and settings saves can reset every memo at once. */
function erankly_mlms_cached( string $key, callable $factory ): mixed {
	if ( ! isset( $GLOBALS['erankly_mlms_runtime_cache'] ) || ! is_array( $GLOBALS['erankly_mlms_runtime_cache'] ) ) {
		$GLOBALS['erankly_mlms_runtime_cache'] = array();
	}

	if ( ! array_key_exists( $key, $GLOBALS['erankly_mlms_runtime_cache'] ) ) {
		$GLOBALS['erankly_mlms_runtime_cache'][ $key ] = $factory();
	}

	return $GLOBALS['erankly_mlms_runtime_cache'][ $key ];
}

/** Resets every request-level memo (settings, site lists, languages, translations, resolved URLs). */
function erankly_mlms_flush_runtime_caches(): void {
	$GLOBALS['erankly_mlms_runtime_cache'] = array();
}
