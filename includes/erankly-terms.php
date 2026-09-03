<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Terms {

	/**
	 * Registers term visibility for every available public taxonomy.
	 *
	 * @return void
	 */
	public static function register_term_visibility(): void {
		foreach ( get_taxonomies( array(), 'names' ) as $taxonomy ) {
			self::register_taxonomy_term_visibility( $taxonomy );
		}
	}

	/**
	 * Registers visibility metadata when a supported taxonomy becomes available.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public static function register_taxonomy_term_visibility( $taxonomy ): void {
		$taxonomy_object = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;

		if (
			! $taxonomy_object instanceof WP_Taxonomy
			|| in_array( $taxonomy_object->name, self::$registered_taxonomies, true )
			|| ! $taxonomy_object->public
			|| ! $taxonomy_object->publicly_queryable
		) {
			return;
		}

		$registered = register_term_meta(
			$taxonomy_object->name,
			self::VISIBILITY_META_KEY,
			array(
				'auth_callback'     => array( self::class, 'authorize_term_meta' ),
				'default'           => 'index',
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
			)
		);

		if ( ! $registered ) {
			return;
		}

		add_action( "{$taxonomy_object->name}_add_form_fields", array( self::class, 'render_term_visibility_add_field' ) );
		add_action( "{$taxonomy_object->name}_edit_form_fields", array( self::class, 'render_term_visibility_edit_field' ), 10, 2 );

		self::$registered_taxonomies[] = $taxonomy_object->name;
	}

	/**
	 * Allows editors to update visibility for terms they can edit.
	 *
	 * @param bool     $allowed  Whether access is currently allowed.
	 * @param string   $meta_key Meta key being checked.
	 * @param int      $term_id  Term ID being checked.
	 * @param int      $user_id  User ID being checked.
	 * @param string   $cap      Requested capability.
	 * @param string[] $caps     Primitive capabilities required by WordPress.
	 * @return bool
	 */
	public static function authorize_term_meta( $allowed, $meta_key, $term_id, $user_id, $cap, $caps ): bool {
		return current_user_can( 'edit_term', absint( $term_id ) );
	}

	/**
	 * Renders the Indexing control on the Add term screen.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public static function render_term_visibility_add_field( $taxonomy ): void {
		?>
		<div class="form-field">
			<label for="erankly-term-visibility"><?php esc_html_e( 'Indexing', 'easyrankly' ); ?></label>
			<?php self::render_term_visibility_control( 'index' ); ?>
		</div>
		<?php
	}

	/**
	 * Renders the Indexing control on the Edit term screen.
	 *
	 * @param WP_Term $term     Term being edited.
	 * @param string  $taxonomy Taxonomy name.
	 * @return void
	 */
	public static function render_term_visibility_edit_field( $term, $taxonomy ): void {
		$visibility = $term instanceof WP_Term
			? self::sanitize_visibility( get_term_meta( $term->term_id, self::VISIBILITY_META_KEY, true ) )
			: 'index';
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="erankly-term-visibility"><?php esc_html_e( 'Indexing', 'easyrankly' ); ?></label>
			</th>
			<td>
				<?php self::render_term_visibility_control( $visibility ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persists term visibility from classic term screens.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	public static function save_term_visibility( $term_id, $tt_id, $taxonomy ): void {
		if (
			! in_array( $taxonomy, self::$registered_taxonomies, true )
			|| ! isset( $_POST['erankly_term_visibility'], $_POST['erankly_term_visibility_nonce'] )
		) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['erankly_term_visibility_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'erankly_term_visibility' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$visibility = self::sanitize_visibility( sanitize_text_field( wp_unslash( $_POST['erankly_term_visibility'] ) ) );

		if ( 'noindex' === $visibility ) {
			update_term_meta( $term_id, self::VISIBILITY_META_KEY, $visibility );

			return;
		}

		delete_term_meta( $term_id, self::VISIBILITY_META_KEY );
	}

	/**
	 * Excludes terms marked noindex from WordPress' native taxonomy sitemaps.
	 *
	 * The core provider uses this query for both URL lists and pagination, which
	 * keeps page counts correct without creating or rendering another sitemap.
	 *
	 * @param array  $args     Sitemap WP_Term_Query arguments.
	 * @param string $taxonomy Taxonomy being mapped.
	 * @return array Sitemap WP_Term_Query arguments.
	 */
	public static function filter_sitemap_taxonomy_query_args( $args, $taxonomy ) {
		if ( ! is_array( $args ) || '' === $taxonomy ) {
			return $args;
		}

		$args['_erankly_exclude_noindex'] = true;

		return $args;
	}

	/**
	 * Excludes noindex terms without adding termmeta joins to sitemap queries.
	 *
	 * @param string[] $clauses    Term query SQL clauses.
	 * @param string[] $taxonomies Taxonomies being queried.
	 * @param array    $args       Term query arguments.
	 * @return string[]
	 */
	public static function filter_sitemap_terms_clauses( $clauses, $taxonomies, $args ) {
		if ( ! is_array( $clauses ) || empty( $args['_erankly_exclude_noindex'] ) ) {
			return $clauses;
		}

		global $wpdb;

		$predicate = $wpdb->prepare(
			'NOT EXISTS (
				SELECT 1 FROM %i AS erankly_visibility_termmeta
				WHERE erankly_visibility_termmeta.term_id = t.term_id
				AND erankly_visibility_termmeta.meta_key = %s
				AND erankly_visibility_termmeta.meta_value = %s
			)',
			$wpdb->termmeta,
			self::VISIBILITY_META_KEY,
			'noindex'
		);
		$where     = isset( $clauses['where'] ) && is_string( $clauses['where'] ) ? $clauses['where'] : '';

		$clauses['where'] = '' === trim( $where ) ? $predicate : $where . ' AND ' . $predicate;

		return $clauses;
	}

	private static function render_term_visibility_control( $visibility ) {
		wp_nonce_field( 'erankly_term_visibility', 'erankly_term_visibility_nonce' );
		?>
		<select name="erankly_term_visibility" id="erankly-term-visibility">
			<option value="index" <?php selected( $visibility, 'index' ); ?>><?php esc_html_e( 'Index', 'easyrankly' ); ?></option>
			<option value="noindex" <?php selected( $visibility, 'noindex' ); ?>><?php esc_html_e( 'Noindex', 'easyrankly' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Noindex asks search engines not to show this term archive and removes it from the WordPress sitemap.', 'easyrankly' ); ?></p>
		<?php
	}

	private static function is_term_noindex( $term_id ): bool {
		return 'noindex' === get_term_meta( absint( $term_id ), self::VISIBILITY_META_KEY, true );
	}

	private static function get_visibility_term_id(): int {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return 0;
		}

		$term = get_queried_object();

		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}
}
