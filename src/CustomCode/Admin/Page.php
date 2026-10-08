<?php
/**
 * Admin screen for custom code.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode\Admin;

use EasyRankly\Admin\RecordsPage;
use EasyRankly\CustomCode\CustomCode;
use EasyRankly\Settings\Admin\Page as SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, adds and edits `erankly_snippet` posts through `/wp/v2/easyrankly-snippets`,
 * where CustomCode::validate_rest() checks permissions, the type and the PHP syntax.
 *
 * The code is written in the code editor of the core, the one of the theme and plugin editors.
 */
final class Page extends RecordsPage {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'easyrankly-snippets';

	/**
	 * Positions with their labels.
	 *
	 * @return array<string, string>
	 */
	private static function positions(): array {
		return array(
			'head'      => __( 'Head', 'easyrankly' ),
			'body_open' => __( 'After the opening body tag', 'easyrankly' ),
			'footer'    => __( 'Footer', 'easyrankly' ),
		);
	}

	/**
	 * Hooks the menu after the parent menu exists.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
	}

	/**
	 * Adds the submenu; changes are handled when its screen loads, before any output.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'Custom code', 'easyrankly' ),
			__( 'Custom code', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Submenu slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Post type of the records.
	 *
	 * @return string
	 */
	public function post_type(): string {
		return CustomCode::POST_TYPE;
	}

	/**
	 * REST route of the snippets.
	 *
	 * @return string
	 */
	protected function route(): string {
		return '/wp/v2/easyrankly-snippets';
	}

	/**
	 * Snippets need manage_options and unfiltered_html.
	 *
	 * @return bool
	 */
	protected function can_manage(): bool {
		return CustomCode::can_manage();
	}

	/**
	 * Texts of the screen.
	 *
	 * @return array<string, string>
	 */
	protected function labels(): array {
		return array(
			'title'       => __( 'Custom code', 'easyrankly' ),
			'add'         => __( 'Add snippet', 'easyrankly' ),
			'edit'        => __( 'Edit snippet', 'easyrankly' ),
			'empty'       => __( 'No snippets yet.', 'easyrankly' ),
			'saved'       => __( 'Snippet saved.', 'easyrankly' ),
			'activated'   => __( 'Snippets activated.', 'easyrankly' ),
			'deactivated' => __( 'Snippets deactivated.', 'easyrankly' ),
			'deleted'     => __( 'Snippets deleted.', 'easyrankly' ),
			'confirm'     => __( 'These snippets will be deleted, with their history:', 'easyrankly' ),
		);
	}

	/**
	 * Safe mode and PHP availability, above the list and the form.
	 */
	protected function notices(): void {
		if ( CustomCode::safe_mode() ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Safe mode is on (EASYRANKLY_SAFE_MODE): no snippet runs.', 'easyrankly' ) );
		}
		if ( ! CustomCode::php_allowed() ) {
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html__( 'File editing is disabled on this site: PHP snippets do not run and cannot be edited.', 'easyrankly' ) );
		}
	}

	/**
	 * Columns of the list.
	 *
	 * @return array<string, string>
	 */
	public function columns(): array {
		return array(
			'name'     => __( 'Name', 'easyrankly' ),
			'type'     => __( 'Type', 'easyrankly' ),
			'position' => __( 'Position', 'easyrankly' ),
			'status'   => __( 'Status', 'easyrankly' ),
		);
	}

	/**
	 * One cell of the list.
	 *
	 * @param \WP_Post $post   Snippet.
	 * @param string   $column Column.
	 * @return string
	 */
	public function cell( \WP_Post $post, string $column ): string {
		switch ( $column ) {
			case 'name':
				return sprintf(
					'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
					esc_url(
						$this->url(
							array(
								'action' => 'edit',
								'id'     => $post->ID,
							)
						)
					),
					esc_html( $post->post_title )
				);
			case 'type':
				return esc_html( strtoupper( CustomCode::type( $post->ID ) ) );
			case 'position':
				$position = (string) get_post_meta( $post->ID, CustomCode::meta_key( 'position' ), true );
				return esc_html( ( self::positions()[ $position ] ?? $position ) . ' (' . (int) get_post_meta( $post->ID, CustomCode::meta_key( 'priority' ), true ) . ')' );
			case 'status':
				$status = 'publish' === $post->post_status ? __( 'Active', 'easyrankly' ) : __( 'Inactive', 'easyrankly' );
				if ( '' !== (string) get_post_meta( $post->ID, CustomCode::meta_key( 'error' ), true ) ) {
					$status .= ' ' . __( '(turned off by an error)', 'easyrankly' );
				}
				return esc_html( $status );
		}

		return '';
	}

	/**
	 * Form values of a snippet.
	 *
	 * @param \WP_Post|null $post Snippet, null for a new one.
	 * @return array<string, mixed>
	 */
	protected function values( ?\WP_Post $post ): array {
		if ( null === $post ) {
			return array(
				'name'     => '',
				'type'     => 'html',
				'code'     => '',
				'position' => 'head',
				'priority' => 10,
				'active'   => false,
			);
		}

		return array(
			'name'     => $post->post_title,
			'type'     => CustomCode::type( $post->ID ),
			'code'     => $post->post_content,
			'position' => (string) get_post_meta( $post->ID, CustomCode::meta_key( 'position' ), true ),
			'priority' => (int) get_post_meta( $post->ID, CustomCode::meta_key( 'priority' ), true ),
			'active'   => 'publish' === $post->post_status,
		);
	}

	/**
	 * Form values from the submitted form.
	 *
	 * The code stays raw: it is the administrator's own HTML or PHP, written with
	 * unfiltered_html (and edit_plugins for PHP), which CustomCode::validate_rest() checks.
	 *
	 * @return array<string, mixed>
	 */
	protected function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- RecordsPage::save() checks the nonce before calling this.
		$position = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'head';

		return array(
			'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'type'     => isset( $_POST['type'] ) && 'php' === $_POST['type'] ? 'php' : 'html',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The snippet code itself (see above), checked by CustomCode::validate_rest().
			'code'     => isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '',
			'position' => array_key_exists( $position, self::positions() ) ? $position : 'head',
			'priority' => isset( $_POST['priority'] ) ? min( 1000, absint( $_POST['priority'] ) ) : 10,
			'active'   => ! empty( $_POST['active'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * REST body of a snippet. The type is sent only when the snippet is created: it never changes.
	 *
	 * @param array<string, mixed> $values Form values.
	 * @param \WP_Post|null        $post   Snippet being edited, null for a new one.
	 * @return array<string, mixed>
	 */
	protected function to_rest( array $values, ?\WP_Post $post ): array {
		$meta = array(
			CustomCode::meta_key( 'position' ) => (string) $values['position'],
			CustomCode::meta_key( 'priority' ) => (int) $values['priority'],
		);
		if ( null === $post ) {
			$meta[ CustomCode::meta_key( 'type' ) ] = (string) $values['type'];
		}

		return array(
			'title'   => (string) $values['name'],
			'content' => (string) $values['code'],
			'status'  => $values['active'] ? 'publish' : 'draft',
			'meta'    => $meta,
		);
	}

	/**
	 * The code editor of the core, in the mode of the snippet type, unless the user turned it off.
	 *
	 * @param \WP_Post|null $post Snippet being edited, null for a new one.
	 */
	protected function enqueue_form( ?\WP_Post $post ): void {
		$php      = null !== $post && 'php' === CustomCode::type( $post->ID );
		$settings = wp_enqueue_code_editor( array( 'type' => $php ? 'application/x-httpd-php-open' : 'text/html' ) );

		if ( false !== $settings ) {
			// The core prints the editor script in the head, before the textarea exists.
			wp_add_inline_script(
				'code-editor',
				sprintf( 'document.addEventListener( "DOMContentLoaded", function () { window.wp.codeEditor.initialize( "easyrankly-code", %s ); } );', wp_json_encode( $settings ) )
			);
		}
	}

	/**
	 * Rows of the snippet form.
	 *
	 * @param array<string, mixed> $values Form values.
	 * @param \WP_Post|null        $post   Snippet being edited, null for a new one.
	 */
	protected function fields( array $values, ?\WP_Post $post ): void {
		// The type of an existing snippet is the stored one, also when the form comes back with an error.
		$values['type'] = null === $post ? $values['type'] : CustomCode::type( $post->ID );
		$error          = null === $post ? '' : (string) get_post_meta( $post->ID, CustomCode::meta_key( 'error' ), true );
		if ( '' !== $error ) {
			printf(
				'<tr><td colspan="2"><div class="notice notice-warning inline"><p>%1$s <code>%2$s</code></p></div></td></tr>',
				esc_html__( 'This snippet was turned off after an error:', 'easyrankly' ),
				esc_html( $error )
			);
		}

		printf(
			'<tr><th scope="row"><label for="easyrankly-name">%1$s</label></th><td><input type="text" id="easyrankly-name" name="name" value="%2$s" class="regular-text" required /></td></tr>',
			esc_html__( 'Name', 'easyrankly' ),
			esc_attr( (string) $values['name'] )
		);

		printf( '<tr><th scope="row"><label for="easyrankly-type">%s</label></th><td>', esc_html__( 'Type', 'easyrankly' ) );
		if ( null === $post ) {
			echo '<select id="easyrankly-type" name="type">';
			printf( '<option value="html"%1$s>%2$s</option>', selected( $values['type'], 'html', false ), esc_html__( 'HTML', 'easyrankly' ) );
			if ( CustomCode::can_write_php() ) {
				printf( '<option value="php"%1$s>%2$s</option>', selected( $values['type'], 'php', false ), esc_html__( 'PHP', 'easyrankly' ) );
			}
			echo '</select>';
		} else {
			printf(
				'<strong id="easyrankly-type">%1$s</strong><p class="description">%2$s</p>',
				esc_html( strtoupper( (string) $values['type'] ) ),
				esc_html__( 'The type cannot change after the snippet is created.', 'easyrankly' )
			);
		}
		echo '</td></tr>';

		printf(
			'<tr><th scope="row"><label for="easyrankly-code">%1$s</label></th><td><textarea id="easyrankly-code" name="code" rows="14" class="large-text code">%2$s</textarea><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Code', 'easyrankly' ),
			esc_textarea( (string) $values['code'] ),
			'php' === $values['type']
				? esc_html__( 'PHP without the opening tag. It is checked for syntax errors before it can be active, and turned off at the first runtime error.', 'easyrankly' )
				: esc_html__( 'HTML is printed as it is on every page of the site. PHP goes without the opening tag: it is checked for syntax errors before it can be active, and turned off at the first runtime error.', 'easyrankly' )
		);

		printf( '<tr><th scope="row"><label for="easyrankly-position">%s</label></th><td><select id="easyrankly-position" name="position">', esc_html__( 'Position', 'easyrankly' ) );
		foreach ( self::positions() as $position => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $position ), selected( $values['position'], $position, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row"><label for="easyrankly-priority">%1$s</label></th><td><input type="number" id="easyrankly-priority" name="priority" value="%2$d" min="0" max="1000" class="small-text" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Priority', 'easyrankly' ),
			(int) $values['priority'],
			esc_html__( 'Lower numbers run first.', 'easyrankly' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="active" value="1"%2$s /> %3$s</label></td></tr>',
			esc_html__( 'Status', 'easyrankly' ),
			checked( (bool) $values['active'], true, false ),
			esc_html__( 'Active', 'easyrankly' )
		);
	}
}
