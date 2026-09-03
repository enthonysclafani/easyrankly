<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Editor {

	/**
	 * Registers the plugin metadata for public, REST-enabled post types.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			self::register_post_type_meta( $post_type->name, $post_type );
		}
	}

	/**
	 * Registers the Search engines description on block templates.
	 *
	 * @return void
	 */
	public static function register_template_description_field(): void {
		register_rest_field(
			'wp_template',
			self::DESCRIPTION_META_KEY,
			array(
				'get_callback'    => array( self::class, 'get_template_description_field' ),
				'update_callback' => array( self::class, 'update_template_description_field' ),
				'schema'          => array(
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'context'     => array( 'edit', 'view' ),
					'description' => __( 'Description shown in search results and social shares for pages rendered by this template.', 'easyrankly' ),
					'type'        => 'string',
				),
			)
		);
	}

	/**
	 * Returns the stored description for a block template REST response.
	 *
	 * @param array $template Prepared template data.
	 * @return string
	 */
	public static function get_template_description_field( $template ): string {
		$post_id = is_array( $template ) && isset( $template['wp_id'] ) ? absint( $template['wp_id'] ) : 0;

		if ( ! $post_id ) {
			return '';
		}

		$description = get_post_meta( $post_id, self::DESCRIPTION_META_KEY, true );

		return is_string( $description ) ? $description : '';
	}

	/**
	 * Stores the Search engines description for a block template.
	 *
	 * @param mixed             $value    Submitted description.
	 * @param WP_Block_Template $template Template being updated.
	 * @return void
	 */
	public static function update_template_description_field( $value, $template ): void {
		$post_id = $template instanceof WP_Block_Template ? absint( $template->wp_id ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$description = sanitize_textarea_field( is_string( $value ) ? $value : '' );

		if ( '' === $description ) {
			delete_post_meta( $post_id, self::DESCRIPTION_META_KEY );

			return;
		}

		update_post_meta( $post_id, self::DESCRIPTION_META_KEY, $description );
	}

	/**
	 * Registers metadata when a supported post type becomes available.
	 *
	 * @param string       $post_type        Post type name.
	 * @param WP_Post_Type $post_type_object Post type object.
	 * @return void
	 */
	public static function register_post_type_meta( $post_type, $post_type_object ): void {
		if (
			in_array( $post_type, self::$registered_post_types, true )
			|| ! $post_type_object instanceof WP_Post_Type
			|| ! $post_type_object->public
			|| ! $post_type_object->show_in_rest
			|| ! post_type_supports( $post_type, 'custom-fields' )
		) {
			return;
		}

		$revisions_enabled = post_type_supports( $post_type, 'revisions' );
		$head_args          = array(
			'auth_callback'     => array( self::class, 'authorize_head_meta' ),
			'default'           => '',
			'revisions_enabled' => $revisions_enabled,
			'sanitize_callback' => array( self::class, 'sanitize_raw_code' ),
			'show_in_rest'      => true,
			'single'            => true,
			'type'              => 'string',
		);
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
		$registered = register_post_meta( $post_type, self::HEAD_META_KEY, $head_args )
			&& register_post_meta( $post_type, self::BODY_START_META_KEY, $head_args )
			&& register_post_meta( $post_type, self::BODY_END_META_KEY, $head_args )
			&& register_post_meta( $post_type, self::DESCRIPTION_META_KEY, $description_args )
			&& register_post_meta( $post_type, self::VISIBILITY_META_KEY, $visibility_args );

		if ( $registered ) {
			self::$registered_post_types[] = $post_type;
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
	public static function register_global_code_settings(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}

		$settings = array(
			self::GLOBAL_HEAD_OPTION       => array(
				'description' => __( 'Code printed in the document head on every front-end page.', 'easyrankly' ),
				'label'       => __( 'Global head code', 'easyrankly' ),
				'sanitize'    => array( self::class, 'sanitize_raw_code' ),
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
	public static function authorize_head_meta( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ): bool {
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
	public static function authorize_editor_meta( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ): bool {
		return self::can_edit_meta( $post_id );
	}

	private static function can_edit_meta( $post_id ): bool {
		$post_id   = absint( $post_id );
		$parent_id = wp_is_post_revision( $post_id );

		if ( $parent_id ) {
			$post_id = $parent_id;
		}

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Validates trusted raw code without altering intentional markup or scripts.
	 *
	 * Authorization is enforced separately by authorize_head_meta().
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_raw_code( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = str_replace( "\0", '', $value );
		$value = wp_check_invalid_utf8( $value, true );

		return trim( $value );
	}

	/**
	 * Preserves the pre-2.0 public sanitizer callback.
	 *
	 * @deprecated 2.0.1 Use sanitize_raw_code().
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_head_code( $value ): string {
		_deprecated_function( __METHOD__, '2.0.1', __CLASS__ . '::sanitize_raw_code()' );

		return self::sanitize_raw_code( $value );
	}

	/**
	 * Restricts search visibility to the supported values.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_visibility( $value ): string {
		return 'noindex' === $value ? 'noindex' : 'index';
	}

	/**
	 * Loads the editor integration on supported block editor screens.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! $screen->is_block_editor() ) {
			return;
		}

		$is_site_editor = 'site-editor' === $screen->id;

		if ( ! $is_site_editor && ( ! $screen->post_type || ! in_array( $screen->post_type, self::$registered_post_types, true ) ) ) {
			return;
		}

		$style_path  = plugin_dir_path( self::FILE ) . 'assets/editor.css';
		$script_path = plugin_dir_path( self::FILE ) . 'assets/editor.js';

		wp_enqueue_style(
			'erankly-editor-style',
			plugins_url( 'assets/editor.css', self::FILE ),
			array( $is_site_editor ? 'wp-edit-site' : 'wp-edit-post' ),
			self::asset_version( $style_path )
		);

		wp_enqueue_script(
			'erankly-editor',
			plugins_url( 'assets/editor.js', self::FILE ),
			array(
				'wp-api-fetch',
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
			'headMetaKey'                 => self::HEAD_META_KEY,
			'registeredPostTypes'         => array_values( self::$registered_post_types ),
			'templateDescriptionField'    => self::DESCRIPTION_META_KEY,
			'templatePostType'            => 'wp_template',
			'variables'                   => self::get_code_variable_examples( self::get_edited_post_id() ),
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
			'easyrankly'
		);
	}

	/**
	 * Returns the content the block editor screen has open.
	 *
	 * @return int
	 */
	private static function get_edited_post_id(): int {
		$post = get_post();

		return $post instanceof WP_Post ? absint( $post->ID ) : 0;
	}
}
