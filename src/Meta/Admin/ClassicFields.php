<?php
/**
 * SEO meta box on the classic post edit screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Meta\Admin;

use EasyRankly\Admin\MediaField;
use EasyRankly\Meta\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the SEO fields of the block editor panel to the classic edit screen and saves them.
 *
 * Only where the block editor is not used: WooCommerce products, sites with the Classic Editor
 * plugin, post types without `editor` support or REST. It is the one classic meta box of the
 * plugin (decision of 14 October 2026): without it those posts have no SEO fields at all.
 * Values go through the sanitize callbacks registered in Meta, as from the block editor.
 */
final class ClassicFields {

	/**
	 * Nonce action and field name.
	 */
	private const NONCE = 'easyrankly_post_seo';

	/**
	 * Hooks the meta box and its saving.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Whether the classic screen of a post shows the box: a viewable post type, not media
	 * (decided: no SEO fields on attachments), edited without the block editor.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function applies( \WP_Post $post ): bool {
		return 'attachment' !== $post->post_type
			&& is_post_type_viewable( $post->post_type )
			&& ! use_block_editor_for_post( $post )
			&& current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Adds the box and the image picker it uses.
	 *
	 * @param mixed $post_type Post type of the screen.
	 * @param mixed $post      Post being edited.
	 */
	public function add( $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post || ! self::applies( $post ) ) {
			return;
		}

		add_meta_box( 'easyrankly-seo', __( 'SEO', 'easyrankly' ), array( $this, 'render' ), (string) $post_type, 'normal' );
		add_action( 'admin_enqueue_scripts', array( MediaField::class, 'enqueue' ) );
	}

	/**
	 * Prints the fields.
	 *
	 * @param mixed $post Post being edited.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$value = static fn( string $name ): mixed => Meta::post( $post->ID, $name );

		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row( 'title', __( 'SEO title', 'easyrankly' ), (string) $value( 'title' ), __( 'Empty uses the title template', 'easyrankly' ) );
		$this->text_row( 'description', __( 'Meta description', 'easyrankly' ), (string) $value( 'description' ), __( 'Empty uses the description template', 'easyrankly' ), true );
		$this->text_row( 'canonical', __( 'Canonical URL', 'easyrankly' ), (string) $value( 'canonical' ), __( 'Empty uses the permalink', 'easyrankly' ) );

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="easyrankly[noindex]" value="1" %2$s> %3$s</label><br><label><input type="checkbox" name="easyrankly[nofollow]" value="1" %4$s> %5$s</label></td></tr>',
			esc_html__( 'Search engines', 'easyrankly' ),
			checked( (bool) $value( 'noindex' ), true, false ),
			esc_html__( 'Do not index (noindex)', 'easyrankly' ),
			checked( (bool) $value( 'nofollow' ), true, false ),
			esc_html__( 'Do not follow links (nofollow)', 'easyrankly' )
		);

		$this->text_row( 'og_title', __( 'Social title', 'easyrankly' ), (string) $value( 'og_title' ), __( 'Empty uses the SEO title', 'easyrankly' ) );
		$this->text_row( 'og_description', __( 'Social description', 'easyrankly' ), (string) $value( 'og_description' ), __( 'Empty uses the meta description', 'easyrankly' ), true );

		printf( '<tr><th scope="row"><label for="easyrankly-og_image">%s</label></th><td>', esc_html__( 'Social image', 'easyrankly' ) );
		MediaField::render( 'easyrankly-og_image', 'easyrankly[og_image]', absint( $value( 'og_image' ) ) );
		printf( '<p class="description">%s</p></td></tr>', esc_html__( 'Empty uses the featured image, then the default image.', 'easyrankly' ) );

		echo '</tbody></table>';
	}

	/**
	 * Saves the fields; an empty value deletes the meta so the default applies.
	 *
	 * Only for the post the form was sent for: posts created while it saves (a translation)
	 * run save_post with the same form data.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    Post.
	 */
	public function save( $post_id, $post ): void {
		$post_id = (int) $post_id;

		if ( ! isset( $_POST[ self::NONCE ], $_POST['post_ID'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( absint( $_POST['post_ID'] ) !== $post_id || ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		// Each value is sanitized below by the callback registered for its key.
		$input = isset( $_POST['easyrankly'] ) && is_array( $_POST['easyrankly'] ) ? wp_unslash( $_POST['easyrankly'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per key through sanitize_meta().

		foreach ( Meta::fields() as $name => $field ) {
			$key = Meta::PREFIX . $name;
			// The keys are registered per post type, so their sanitize callbacks need it.
			$value = sanitize_meta( $key, $input[ $name ] ?? $field['default'], 'post', $post->post_type );

			if ( $value === $field['default'] || '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/**
	 * Prints one text or textarea row.
	 *
	 * @param string $name        Field name without prefix.
	 * @param string $label       Visible label.
	 * @param string $value       Current value.
	 * @param string $placeholder Shown while the field is empty: what is used instead.
	 * @param bool   $textarea    Whether to print a textarea.
	 */
	private function text_row( string $name, string $label, string $value, string $placeholder, bool $textarea = false ): void {
		$id    = 'easyrankly-' . $name;
		$field = $textarea
			? sprintf( '<textarea id="%1$s" name="easyrankly[%2$s]" rows="3" class="large-text" placeholder="%4$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ), esc_attr( $placeholder ) )
			: sprintf( '<input type="text" id="%1$s" name="easyrankly[%2$s]" value="%3$s" class="large-text" placeholder="%4$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $placeholder ) );

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			$field // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
		);
	}
}
