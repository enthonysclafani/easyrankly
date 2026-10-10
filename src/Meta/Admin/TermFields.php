<?php
/**
 * SEO fields on the term edit screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Meta\Admin;

use EasyRankly\Admin\MediaField;
use EasyRankly\Meta\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the SEO fields to the classic edit form of every public taxonomy and saves them.
 */
final class TermFields {

	/**
	 * Nonce action and field name.
	 */
	private const NONCE = 'easyrankly_term_seo';

	/**
	 * Hooks the form of each public taxonomy once taxonomies exist.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'hook_taxonomies' ) );
		add_action( 'load-term.php', array( $this, 'load' ) );
	}

	/**
	 * Loads the image picker on the edit screen of the taxonomies that show the fields.
	 */
	public function load(): void {
		$taxonomy = isset( $_REQUEST['taxonomy'] ) ? sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the assets of the screen.

		if ( taxonomy_exists( $taxonomy ) && is_taxonomy_viewable( $taxonomy ) ) {
			add_action( 'admin_enqueue_scripts', array( MediaField::class, 'enqueue' ) );
		}
	}

	/**
	 * Adds the form and save hooks for every public taxonomy with an admin UI.
	 */
	public function hook_taxonomies(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			if ( ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}

			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
	}

	/**
	 * Prints the fields as rows of the term edit table.
	 *
	 * @param mixed $term Term being edited.
	 */
	public function render( $term ): void {
		if ( ! $term instanceof \WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}

		$value = static fn( string $name ): mixed => Meta::term( $term->term_id, $name );

		echo '<tr class="form-field"><th colspan="2"><h2>' . esc_html__( 'SEO', 'easyrankly' ) . '</h2>';
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '</th></tr>';

		$this->text_row( 'title', __( 'SEO title', 'easyrankly' ), (string) $value( 'title' ), __( 'Empty uses the title template', 'easyrankly' ) );
		$this->text_row( 'description', __( 'Meta description', 'easyrankly' ), (string) $value( 'description' ), __( 'Empty uses the description template', 'easyrankly' ), true );
		$this->text_row( 'canonical', __( 'Canonical URL', 'easyrankly' ), (string) $value( 'canonical' ), __( 'Empty uses the term archive URL', 'easyrankly' ) );

		printf(
			'<tr class="form-field"><th scope="row">%1$s</th><td><label><input type="checkbox" name="easyrankly[noindex]" value="1" %2$s> %3$s</label><br><label><input type="checkbox" name="easyrankly[nofollow]" value="1" %4$s> %5$s</label></td></tr>',
			esc_html__( 'Search engines', 'easyrankly' ),
			checked( (bool) $value( 'noindex' ), true, false ),
			esc_html__( 'Do not index this archive (noindex)', 'easyrankly' ),
			checked( (bool) $value( 'nofollow' ), true, false ),
			esc_html__( 'Do not follow its links (nofollow)', 'easyrankly' )
		);

		$this->text_row( 'og_title', __( 'Social title', 'easyrankly' ), (string) $value( 'og_title' ), __( 'Empty uses the SEO title', 'easyrankly' ) );
		$this->text_row( 'og_description', __( 'Social description', 'easyrankly' ), (string) $value( 'og_description' ), __( 'Empty uses the meta description', 'easyrankly' ), true );

		printf( '<tr class="form-field"><th scope="row"><label for="easyrankly-og_image">%s</label></th><td>', esc_html__( 'Social image', 'easyrankly' ) );
		MediaField::render( 'easyrankly-og_image', 'easyrankly[og_image]', absint( $value( 'og_image' ) ) );
		printf( '<p class="description">%s</p></td></tr>', esc_html__( 'Empty uses the default image.', 'easyrankly' ) );
	}

	/**
	 * Saves the fields; an empty value deletes the meta so the default applies.
	 *
	 * @param mixed $term_id Term ID.
	 */
	public function save( $term_id ): void {
		$term_id = (int) $term_id;

		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// Each value is sanitized below by the callback registered for its key.
		$input = isset( $_POST['easyrankly'] ) && is_array( $_POST['easyrankly'] ) ? wp_unslash( $_POST['easyrankly'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per key through sanitize_meta().

		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		foreach ( Meta::fields() as $name => $field ) {
			$key = Meta::PREFIX . $name;
			// The keys are registered per taxonomy, so their sanitize callbacks need it.
			$value = sanitize_meta( $key, $input[ $name ] ?? $field['default'], 'term', $term->taxonomy );

			if ( $value === $field['default'] || '' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
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
			? sprintf( '<textarea id="%1$s" name="easyrankly[%2$s]" rows="3" placeholder="%4$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ), esc_attr( $placeholder ) )
			: sprintf( '<input type="text" id="%1$s" name="easyrankly[%2$s]" value="%3$s" placeholder="%4$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), esc_attr( $placeholder ) );

		printf(
			'<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			$field // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped parts.
		);
	}
}
