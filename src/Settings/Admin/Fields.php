<?php
/**
 * Sections and fields of the settings pages.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings\Admin;

use EasyRankly\Multilingual\Languages;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers each page's sections and fields with the Settings API and prints them with the
 * markup of the WordPress settings screens (`form-table`, `regular-text`, `description`).
 *
 * Field names follow the shape Form::normalize() reads: `easyrankly_settings[key]`.
 */
final class Fields {

	/**
	 * Variables the title and description templates understand.
	 */
	private const VARIABLES = '{{title}} {{sep}} {{site_name}} {{tagline}} {{page}} {{excerpt}} {{term_description}} {{author}} {{post_type}} {{category}} {{date}} {{search_query}}';

	/**
	 * Registers the sections and fields of one page.
	 *
	 * @param string $slug Page slug.
	 */
	public static function add( string $slug ): void {
		switch ( $slug ) {
			case Page::SLUG:
				self::add_general();
				break;
			case 'easyrankly-titles':
				self::add_titles();
				break;
			case 'easyrankly-indexing':
				self::add_indexing();
				break;
			case 'easyrankly-schema':
				self::add_schema();
				break;
			case 'easyrankly-languages':
				add_settings_section( 'languages', '', array( self::class, 'languages_table' ), $slug );
				break;
		}
	}

	/**
	 * Language whose templates the Titles page edits: empty for the templates of all languages.
	 *
	 * @return string
	 */
	public static function language(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of what to show; saving goes through options.php.
		$language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';

		return Languages::enabled() && Languages::exists( $language ) ? $language : '';
	}

	/**
	 * Generic contexts of the templates, with their labels.
	 *
	 * PHP turns the key "404" into an integer: callers cast keys to strings where it matters.
	 *
	 * @return array<int|string, string>
	 */
	public static function generic_contexts(): array {
		return array(
			'home'    => __( 'Home page', 'easyrankly' ),
			'single'  => __( 'Single content (all types)', 'easyrankly' ),
			'archive' => __( 'Post type archives', 'easyrankly' ),
			'term'    => __( 'Category, tag and term archives', 'easyrankly' ),
			'author'  => __( 'Author archives', 'easyrankly' ),
			'date'    => __( 'Date archives', 'easyrankly' ),
			'search'  => __( 'Search results', 'easyrankly' ),
			'404'     => __( 'Page not found', 'easyrankly' ),
		);
	}

	/**
	 * Contexts of each viewable post type and public taxonomy, with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function type_contexts(): array {
		$contexts = array();

		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ! is_post_type_viewable( $type ) ) {
				continue;
			}
			/* translators: %s: post type name. */
			$contexts[ 'single-' . $type->name ] = sprintf( __( 'Single: %s', 'easyrankly' ), $type->labels->name );
			if ( $type->has_archive ) {
				/* translators: %s: post type name. */
				$contexts[ 'archive-' . $type->name ] = sprintf( __( 'Archive: %s', 'easyrankly' ), $type->labels->name );
			}
		}

		foreach ( get_taxonomies( array( 'publicly_queryable' => true ), 'objects' ) as $taxonomy ) {
			/* translators: %s: taxonomy name. */
			$contexts[ 'term-' . $taxonomy->name ] = sprintf( __( 'Taxonomy: %s', 'easyrankly' ), $taxonomy->labels->name );
		}

		// Keys the settings schema cannot store (very long names) are left out.
		$pattern = '/' . \EasyRankly\Context\Context::KEY_PATTERN . '/';

		return array_filter( $contexts, static fn( string $key ): bool => 1 === preg_match( $pattern, $key ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Contexts that can be excluded from indexing, with their labels.
	 *
	 * The home page and search results are left out: the first is never excluded,
	 * the second is never indexed.
	 *
	 * @return array<int|string, string>
	 */
	public static function noindex_contexts(): array {
		$generic = array_intersect_key( self::generic_contexts(), array_flip( array( 'archive', 'term', 'author', 'date' ) ) );

		return $generic + self::type_contexts();
	}

	/**
	 * Viewable post types with at least one public taxonomy, for the breadcrumb trail.
	 *
	 * @return array<string, array{name: string, taxonomies: array<string, string>}> Post type => name and taxonomies.
	 */
	public static function breadcrumb_types(): array {
		$types = array();

		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ! is_post_type_viewable( $type ) ) {
				continue;
			}

			$taxonomies = array();
			foreach ( get_object_taxonomies( $type->name, 'objects' ) as $taxonomy ) {
				if ( $taxonomy->publicly_queryable ) {
					$taxonomies[ $taxonomy->name ] = $taxonomy->labels->name;
				}
			}

			if ( array() !== $taxonomies ) {
				$types[ $type->name ] = array(
					'name'       => $type->labels->name,
					'taxonomies' => $taxonomies,
				);
			}
		}

		return $types;
	}

	/**
	 * General: title separator and breadcrumb.
	 */
	private static function add_general(): void {
		$page = Page::SLUG;

		add_settings_section( 'general', '', '__return_null', $page );
		add_settings_field(
			'title_separator',
			__( 'Title separator', 'easyrankly' ),
			array( self::class, 'text' ),
			$page,
			'general',
			array(
				'label_for'   => self::id( 'title_separator' ),
				'key'         => 'title_separator',
				'input_class' => 'small-text',
				'maxlength'   => 10,
				'description' => __( 'Inserted by the {{sep}} variable in title templates.', 'easyrankly' ),
			)
		);

		add_settings_section(
			'breadcrumbs',
			__( 'Breadcrumbs', 'easyrankly' ),
			array( self::class, 'paragraph' ),
			$page,
			array( 'paragraph' => __( 'These options change the WordPress Breadcrumbs block. The structured data follows the trail the block shows.', 'easyrankly' ) )
		);
		add_settings_field(
			'breadcrumb_home_label',
			__( 'Label of the first item', 'easyrankly' ),
			array( self::class, 'text' ),
			$page,
			'breadcrumbs',
			array(
				'label_for'   => self::id( 'breadcrumb_home_label' ),
				'key'         => 'breadcrumb_home_label',
				'maxlength'   => 60,
				'description' => __( 'Empty keeps the WordPress label (Home).', 'easyrankly' ),
			)
		);

		$current = (array) Settings::value( 'breadcrumb_taxonomies' );
		foreach ( self::breadcrumb_types() as $type => $item ) {
			$id = self::id( 'breadcrumb_taxonomies', $type );
			add_settings_field(
				$id,
				esc_html( $item['name'] ),
				array( self::class, 'select' ),
				$page,
				'breadcrumbs',
				array(
					'label_for' => $id,
					'name'      => self::name( 'breadcrumb_taxonomies', $type ),
					'value'     => (string) ( $current[ $type ] ?? '' ),
					'options'   => array( '' => __( 'First taxonomy with terms', 'easyrankly' ) ) + $item['taxonomies'],
				)
			);
		}
	}

	/**
	 * Titles: the templates of each context, for all languages or for the language chosen.
	 */
	private static function add_titles(): void {
		$page     = 'easyrankly-titles';
		$language = self::language();
		$settings = Settings::get();
		$base     = (array) $settings['templates'];
		$by_lang  = (array) $settings['language_templates'];

		// A language edits its own templates; those of all languages show as placeholders.
		$templates    = '' === $language ? $base : (array) ( $by_lang[ $language ] ?? array() );
		$placeholders = '' === $language ? array() : $base;

		$intro = __( 'Empty title: WordPress builds it. Empty description: none is printed. A value set on a single post or term always wins.', 'easyrankly' );
		if ( '' !== $language ) {
			$intro = __( 'Templates of one language are used on its pages. Empty fields use the templates of all languages.', 'easyrankly' );
		}

		add_settings_section( 'templates', '', array( self::class, 'templates_intro' ), $page, array( 'paragraph' => $intro ) );
		self::add_template_fields( self::generic_contexts(), 'templates', $templates, $placeholders );

		add_settings_section(
			'type_templates',
			__( 'Per content type and taxonomy', 'easyrankly' ),
			array( self::class, 'paragraph' ),
			$page,
			array( 'paragraph' => __( 'Leave empty to use the general template above.', 'easyrankly' ) )
		);
		self::add_template_fields( self::type_contexts(), 'type_templates', $templates, $placeholders );
	}

	/**
	 * One field per context, with its title and description templates.
	 *
	 * @param array<int|string, string> $contexts     Context key => label.
	 * @param string                    $section      Section ID.
	 * @param array<mixed>              $templates    Templates being edited.
	 * @param array<mixed>              $placeholders Templates shown when a field is empty.
	 */
	private static function add_template_fields( array $contexts, string $section, array $templates, array $placeholders ): void {
		foreach ( $contexts as $key => $label ) {
			add_settings_field(
				'template-' . $key,
				esc_html( $label ),
				array( self::class, 'template' ),
				'easyrankly-titles',
				$section,
				array(
					'context'     => $key,
					'label'       => $label,
					'value'       => is_array( $templates[ $key ] ?? null ) ? $templates[ $key ] : array(),
					'placeholder' => is_array( $placeholders[ $key ] ?? null ) ? $placeholders[ $key ] : array(),
				)
			);
		}
	}

	/**
	 * Indexing: noindex per context and extra robots.txt rules.
	 */
	private static function add_indexing(): void {
		$page = 'easyrankly-indexing';

		add_settings_section(
			'noindex',
			'',
			array( self::class, 'paragraph' ),
			$page,
			array( 'paragraph' => __( 'Ask search engines not to index these pages (noindex) and leave them out of the sitemaps. Search results are never indexed. A single post or term can also be excluded from its own SEO settings.', 'easyrankly' ) )
		);
		add_settings_field( 'noindex', __( 'Do not index', 'easyrankly' ), array( self::class, 'noindex' ), $page, 'noindex' );

		add_settings_section( 'robots', __( 'robots.txt', 'easyrankly' ), '__return_null', $page );
		add_settings_field(
			'robots_txt',
			__( 'Extra rules', 'easyrankly' ),
			array( self::class, 'textarea' ),
			$page,
			'robots',
			array(
				'label_for'   => self::id( 'robots_txt' ),
				'key'         => 'robots_txt',
				'input_class' => 'large-text code',
				'rows'        => 6,
				'description' => __( 'Appended to the robots.txt WordPress generates (which already lists the sitemap). Ignored while the site discourages search engines.', 'easyrankly' ),
				'link'        => home_url( '/robots.txt' ),
			)
		);
	}

	/**
	 * Schema and social: who the site represents and the defaults for sharing.
	 */
	private static function add_schema(): void {
		$page = 'easyrankly-schema';

		add_settings_section( 'identity', __( 'Site identity (structured data)', 'easyrankly' ), '__return_null', $page );
		add_settings_field( 'identity_type', __( 'This site represents', 'easyrankly' ), array( self::class, 'identity_type' ), $page, 'identity' );
		add_settings_field(
			'identity_name',
			__( 'Name', 'easyrankly' ),
			array( self::class, 'text' ),
			$page,
			'identity',
			array(
				'label_for'   => self::id( 'identity_name' ),
				'key'         => 'identity_name',
				'maxlength'   => 200,
				'placeholder' => get_bloginfo( 'name' ),
				'description' => __( 'Empty uses the site title.', 'easyrankly' ),
			)
		);
		add_settings_field(
			'identity_logo',
			__( 'Logo or photo', 'easyrankly' ),
			array( self::class, 'media' ),
			$page,
			'identity',
			array(
				'label_for'   => self::id( 'identity_logo' ),
				'key'         => 'identity_logo',
				'description' => __( 'The logo of the organization, or the photo of the person.', 'easyrankly' ),
			)
		);
		add_settings_field(
			'same_as',
			__( 'Profiles on other sites', 'easyrankly' ),
			array( self::class, 'same_as' ),
			$page,
			'identity',
			array( 'label_for' => self::id( 'same_as' ) )
		);

		add_settings_section( 'social', __( 'Social sharing', 'easyrankly' ), '__return_null', $page );
		add_settings_field(
			'social_image',
			__( 'Default image', 'easyrankly' ),
			array( self::class, 'media' ),
			$page,
			'social',
			array(
				'label_for'   => self::id( 'social_image' ),
				'key'         => 'social_image',
				'description' => __( 'Shared when a page has neither its own social image nor a featured image.', 'easyrankly' ),
			)
		);
		add_settings_field(
			'x_username',
			__( 'X username', 'easyrankly' ),
			array( self::class, 'text' ),
			$page,
			'social',
			array(
				'label_for'   => self::id( 'x_username' ),
				'key'         => 'x_username',
				'maxlength'   => 16,
				'description' => __( 'Without @. Printed as twitter:site.', 'easyrankly' ),
			)
		);
	}

	/**
	 * A paragraph under a section title.
	 *
	 * @param array<string, mixed> $section Section arguments, with `paragraph`.
	 */
	public static function paragraph( array $section ): void {
		if ( isset( $section['paragraph'] ) && is_string( $section['paragraph'] ) ) {
			printf( '<p>%s</p>', esc_html( $section['paragraph'] ) );
		}
	}

	/**
	 * Introduction of the templates, with the variables they understand.
	 *
	 * @param array<string, mixed> $section Section arguments, with `paragraph`.
	 */
	public static function templates_intro( array $section ): void {
		self::paragraph( $section );
		printf( '<p>%1$s <code>%2$s</code></p>', esc_html__( 'Variables:', 'easyrankly' ), esc_html( self::VARIABLES ) );
	}

	/**
	 * A text input.
	 *
	 * @param array<string, mixed> $args Field arguments: key, input_class, maxlength, placeholder, description.
	 */
	public static function text( array $args ): void {
		$key = (string) $args['key'];

		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="%4$s" maxlength="%5$d" placeholder="%6$s" />',
			esc_attr( self::id( $key ) ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) Settings::value( $key ) ),
			esc_attr( (string) ( $args['input_class'] ?? 'regular-text' ) ),
			(int) ( $args['maxlength'] ?? 200 ),
			esc_attr( (string) ( $args['placeholder'] ?? '' ) )
		);
		self::description( $args );
	}

	/**
	 * A textarea.
	 *
	 * @param array<string, mixed> $args Field arguments: key, input_class, rows, description, link.
	 */
	public static function textarea( array $args ): void {
		$key = (string) $args['key'];

		printf(
			'<textarea id="%1$s" name="%2$s" class="%3$s" rows="%4$d">%5$s</textarea>',
			esc_attr( self::id( $key ) ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) ( $args['input_class'] ?? 'large-text' ) ),
			(int) ( $args['rows'] ?? 4 ),
			esc_textarea( (string) Settings::value( $key ) )
		);
		self::description( $args );

		if ( isset( $args['link'] ) && is_string( $args['link'] ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $args['link'] ), esc_html__( 'View robots.txt', 'easyrankly' ) );
		}
	}

	/**
	 * A select.
	 *
	 * @param array<string, mixed> $args Field arguments: label_for, name, value, options (value => label).
	 */
	public static function select( array $args ): void {
		printf( '<select id="%1$s" name="%2$s">', esc_attr( (string) $args['label_for'] ), esc_attr( (string) $args['name'] ) );
		foreach ( (array) $args['options'] as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, (string) $args['value'], false ),
				esc_html( (string) $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Title and description templates of one context.
	 *
	 * @param array<string, mixed> $args Field arguments: context, label, value, placeholder.
	 */
	public static function template( array $args ): void {
		$context  = (string) $args['context'];
		$value    = (array) $args['value'];
		$fallback = (array) $args['placeholder'];

		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html( (string) $args['label'] ) );
		foreach ( array(
			'title'       => __( 'Title', 'easyrankly' ),
			'description' => __( 'Meta description', 'easyrankly' ),
		) as $field => $label ) {
			$id = self::id( 'templates', $context, $field );
			printf(
				'<p><label for="%1$s">%2$s</label><br /><input type="text" id="%1$s" name="%3$s" value="%4$s" placeholder="%5$s" class="large-text" maxlength="%6$d" /></p>',
				esc_attr( $id ),
				esc_html( $label ),
				esc_attr( self::name( 'templates', $context, $field ) ),
				esc_attr( is_string( $value[ $field ] ?? null ) ? $value[ $field ] : '' ),
				esc_attr( is_string( $fallback[ $field ] ?? null ) ? $fallback[ $field ] : '' ),
				'title' === $field ? 200 : 400
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Checkboxes of the contexts not to index.
	 */
	public static function noindex(): void {
		$rules = (array) Settings::value( 'noindex' );

		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__( 'Do not index', 'easyrankly' ) );
		foreach ( self::noindex_contexts() as $key => $label ) {
			$key = (string) $key;
			printf(
				'<label><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label><br />',
				esc_attr( self::name( 'noindex', $key ) ),
				checked( in_array( $key, $rules, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Whether the site represents an organization or a person.
	 */
	public static function identity_type(): void {
		$current = (string) Settings::value( 'identity_type' );

		printf( '<fieldset><legend class="screen-reader-text">%s</legend>', esc_html__( 'This site represents', 'easyrankly' ) );
		foreach ( array(
			'organization' => __( 'An organization', 'easyrankly' ),
			'person'       => __( 'A person', 'easyrankly' ),
		) as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( self::name( 'identity_type' ) ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Profiles on other sites, one URL per line.
	 *
	 * @param array<string, mixed> $args Field arguments: label_for.
	 */
	public static function same_as( array $args ): void {
		printf(
			'<textarea id="%1$s" name="%2$s" class="large-text code" rows="4">%3$s</textarea>',
			esc_attr( (string) $args['label_for'] ),
			esc_attr( self::name( 'same_as' ) ),
			esc_textarea( implode( "\n", (array) Settings::value( 'same_as' ) ) )
		);
		printf( '<p class="description">%s</p>', esc_html__( 'One URL per line (social profiles, Wikipedia…).', 'easyrankly' ) );
	}

	/**
	 * An image from the media library, saved as its attachment ID.
	 *
	 * Without JavaScript it is a number field; the media-field script turns it into
	 * "Choose image" and "Remove" buttons with the media library modal.
	 *
	 * @param array<string, mixed> $args Field arguments: key, description.
	 */
	public static function media( array $args ): void {
		$key = (string) $args['key'];
		$id  = (int) Settings::value( $key );

		printf(
			'<div class="easyrankly-media-field" data-choose="%1$s" data-replace="%2$s" data-remove="%3$s">',
			esc_attr__( 'Choose image', 'easyrankly' ),
			esc_attr__( 'Replace image', 'easyrankly' ),
			esc_attr__( 'Remove', 'easyrankly' )
		);
		printf( '<p class="easyrankly-media-field__preview">%s</p>', $id > 0 ? wp_get_attachment_image( $id, 'thumbnail' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped by the core.
		printf(
			'<input type="number" min="0" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" />',
			esc_attr( self::id( $key ) ),
			esc_attr( self::name( $key ) ),
			esc_attr( $id > 0 ? (string) $id : '' )
		);
		echo '</div>';
		self::description( $args );
	}

	/**
	 * The languages, one row each, plus an empty row to add one.
	 */
	public static function languages_table(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Add a second language to publish translations of your content. The first language is the default one: its addresses do not change. The others get their URL prefix, for example /en/.', 'easyrankly' )
		);

		$rows   = Languages::all();
		$rows[] = array(
			'locale' => '',
			'name'   => '',
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Order', 'easyrankly' ),
			__( 'Name', 'easyrankly' ),
			__( 'URL prefix', 'easyrankly' ),
			__( 'Locale', 'easyrankly' ),
			__( 'Remove', 'easyrankly' ),
		) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		$index = 0;
		foreach ( $rows as $slug => $language ) {
			$is_new = ! is_string( $slug );
			$label  = $is_new ? __( 'New language', 'easyrankly' ) : $language['name'];

			echo '<tr>';
			printf(
				'<td><input type="number" min="1" max="20" name="%1$s" value="%2$s" class="small-text" aria-label="%3$s" /></td>',
				esc_attr( self::name( 'languages', (string) $index, 'order' ) ),
				esc_attr( (string) ( $index + 1 ) ),
				/* translators: %s: language name. */
				esc_attr( sprintf( __( 'Order of %s', 'easyrankly' ), $label ) )
			);
			$fields = array(
				'name'   => array(
					'value'     => $language['name'],
					'maxlength' => 50,
					/* translators: %s: language name. */
					'aria'      => sprintf( __( 'Name of %s', 'easyrankly' ), $label ),
				),
				'slug'   => array(
					'value'     => $is_new ? '' : (string) $slug,
					'maxlength' => 12,
					/* translators: %s: language name. */
					'aria'      => sprintf( __( 'URL prefix of %s', 'easyrankly' ), $label ),
				),
				'locale' => array(
					'value'     => $language['locale'],
					'maxlength' => 20,
					/* translators: %s: language name. */
					'aria'      => sprintf( __( 'Locale of %s', 'easyrankly' ), $label ),
				),
			);
			foreach ( $fields as $field => $input ) {
				printf(
					'<td><input type="text" name="%1$s" value="%2$s" maxlength="%3$d" placeholder="%4$s" aria-label="%5$s" /></td>',
					esc_attr( self::name( 'languages', (string) $index, $field ) ),
					esc_attr( $input['value'] ),
					(int) $input['maxlength'],
					esc_attr( 'locale' === $field ? 'en_US' : '' ),
					esc_attr( $input['aria'] )
				);
			}
			echo '<td>';
			if ( ! $is_new ) {
				printf(
					'<input type="checkbox" name="%1$s" value="1" aria-label="%2$s" />',
					esc_attr( self::name( 'languages', (string) $index, 'remove' ) ),
					/* translators: %s: language name. */
					esc_attr( sprintf( __( 'Remove %s', 'easyrankly' ), $label ) )
				);
			}
			echo '</td></tr>';
			++$index;
		}

		echo '</tbody></table>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The locale (for example en_US or it_IT) sets the language of the pages and their hreflang code. Content without a language belongs to the default language. The language with the lowest order is the default one.', 'easyrankly' )
		);
	}

	/**
	 * Description under a field.
	 *
	 * @param array<string, mixed> $args Field arguments, with an optional `description`.
	 */
	private static function description( array $args ): void {
		if ( isset( $args['description'] ) && is_string( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Name attribute of a field: `easyrankly_settings[key][part]…`.
	 *
	 * @param string $key   Setting key.
	 * @param string ...$path Nested keys.
	 * @return string
	 */
	public static function name( string $key, string ...$path ): string {
		return Settings::OPTION . '[' . $key . ']' . implode( '', array_map( static fn( string $part ): string => '[' . $part . ']', $path ) );
	}

	/**
	 * ID attribute of a field.
	 *
	 * @param string $key   Setting key.
	 * @param string ...$path Nested keys.
	 * @return string
	 */
	private static function id( string $key, string ...$path ): string {
		return 'easyrankly-' . implode( '-', array_merge( array( $key ), $path ) );
	}
}
