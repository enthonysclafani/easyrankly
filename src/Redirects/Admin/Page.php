<?php
/**
 * Admin screen for the redirects.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects\Admin;

use EasyRankly\Admin\RecordsPage;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Settings\Admin\Page as SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, adds and edits `erankly_redirect` posts through `/wp/v2/easyrankly-redirects`,
 * where Redirects::validate_rest() checks every rule.
 */
final class Page extends RecordsPage {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'easyrankly-redirects';

	/**
	 * Rules of the rows already printed, by ID: each row asks for one per column.
	 *
	 * @var array<int, array{id: int, source: string, target: string, code: int, regex: bool, forced: bool}|null>
	 */
	private array $rules = array();

	/**
	 * Status codes with their labels.
	 *
	 * @return array<int, string>
	 */
	private static function codes(): array {
		return array(
			301 => __( '301 Moved permanently', 'easyrankly' ),
			302 => __( '302 Found (temporary)', 'easyrankly' ),
			307 => __( '307 Temporary redirect', 'easyrankly' ),
			410 => __( '410 Gone (no target)', 'easyrankly' ),
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
			__( 'Redirects', 'easyrankly' ),
			__( 'Redirects', 'easyrankly' ),
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
		return Redirects::POST_TYPE;
	}

	/**
	 * REST route of the redirects.
	 *
	 * @return string
	 */
	protected function route(): string {
		return '/wp/v2/easyrankly-redirects';
	}

	/**
	 * Only administrators manage redirects.
	 *
	 * @return bool
	 */
	protected function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Texts of the screen.
	 *
	 * @return array<string, string>
	 */
	protected function labels(): array {
		return array(
			'title'       => __( 'Redirects', 'easyrankly' ),
			'add'         => __( 'Add redirect', 'easyrankly' ),
			'edit'        => __( 'Edit redirect', 'easyrankly' ),
			'empty'       => __( 'No redirects yet.', 'easyrankly' ),
			'search'      => __( 'Search sources', 'easyrankly' ),
			'saved'       => __( 'Redirect saved.', 'easyrankly' ),
			'activated'   => __( 'Redirects activated.', 'easyrankly' ),
			'deactivated' => __( 'Redirects deactivated.', 'easyrankly' ),
			'deleted'     => __( 'Redirects deleted.', 'easyrankly' ),
			'confirm'     => __( 'These redirects will be deleted:', 'easyrankly' ),
		);
	}

	/**
	 * Columns of the list.
	 *
	 * @return array<string, string>
	 */
	public function columns(): array {
		return array(
			'source' => __( 'Source', 'easyrankly' ),
			'target' => __( 'Target', 'easyrankly' ),
			'code'   => __( 'Type', 'easyrankly' ),
			'status' => __( 'Status', 'easyrankly' ),
		);
	}

	/**
	 * One cell of the list.
	 *
	 * @param \WP_Post $post   Redirect.
	 * @param string   $column Column.
	 * @return string
	 */
	public function cell( \WP_Post $post, string $column ): string {
		if ( ! array_key_exists( $post->ID, $this->rules ) ) {
			$this->rules[ $post->ID ] = Redirects::rule( $post->ID );
		}

		$rule = $this->rules[ $post->ID ];
		if ( null === $rule ) {
			return '';
		}

		switch ( $column ) {
			case 'source':
				return sprintf(
					'<strong><a class="row-title" href="%1$s"><code>%2$s</code></a></strong>%3$s',
					esc_url(
						$this->url(
							array(
								'action' => 'edit',
								'id'     => $post->ID,
							)
						)
					),
					esc_html( $rule['source'] ),
					$rule['regex'] ? ' ' . esc_html__( '(regex)', 'easyrankly' ) : ''
				);
			case 'target':
				return '' === $rule['target'] ? '&mdash;' : esc_html( $rule['target'] );
			case 'code':
				return esc_html( (string) $rule['code'] ) . ( $rule['forced'] ? ' ' . esc_html__( '(always)', 'easyrankly' ) : '' );
			case 'status':
				return 'publish' === $post->post_status ? esc_html__( 'Active', 'easyrankly' ) : esc_html__( 'Inactive', 'easyrankly' );
		}

		return '';
	}

	/**
	 * Form values of a redirect.
	 *
	 * @param \WP_Post|null $post Redirect, null for a new one.
	 * @return array<string, mixed>
	 */
	protected function values( ?\WP_Post $post ): array {
		$rule = null === $post ? null : Redirects::rule( $post->ID );

		return array(
			'source' => $rule['source'] ?? '',
			'target' => $rule['target'] ?? '',
			'code'   => $rule['code'] ?? 301,
			'regex'  => $rule['regex'] ?? false,
			'forced' => $rule['forced'] ?? false,
			'active' => null === $post || 'publish' === $post->post_status,
		);
	}

	/**
	 * Form values from the submitted form.
	 *
	 * Source and target stay raw: percent-encoded addresses would lose their octets with
	 * sanitize_text_field(). Redirects::check() validates and normalizes both through REST,
	 * and the target meta is sanitized by Rule::sanitize_target().
	 *
	 * @return array<string, mixed>
	 */
	protected function posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- RecordsPage::save() checks the nonce before calling this.
		$code = isset( $_POST['code'] ) ? absint( $_POST['code'] ) : 301;

		return array(
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated and normalized by Redirects::check() through REST (see above).
			'source' => isset( $_POST['source'] ) ? trim( wp_check_invalid_utf8( (string) wp_unslash( $_POST['source'] ) ) ) : '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Redirects::check() and sanitized by Rule::sanitize_target() through REST (see above).
			'target' => isset( $_POST['target'] ) ? trim( wp_check_invalid_utf8( (string) wp_unslash( $_POST['target'] ) ) ) : '',
			'code'   => array_key_exists( $code, self::codes() ) ? $code : 301,
			'regex'  => ! empty( $_POST['regex'] ),
			'forced' => ! empty( $_POST['forced'] ),
			'active' => ! empty( $_POST['active'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * REST body of a redirect; a 410 has no target.
	 *
	 * @param array<string, mixed> $values Form values.
	 * @param \WP_Post|null        $post   Redirect being edited, null for a new one.
	 * @return array<string, mixed>
	 */
	protected function to_rest( array $values, ?\WP_Post $post ): array {
		return array(
			'title'  => (string) $values['source'],
			'status' => $values['active'] ? 'publish' : 'draft',
			'meta'   => array(
				Redirects::meta_key( 'target' ) => 410 === $values['code'] ? '' : (string) $values['target'],
				Redirects::meta_key( 'code' )   => (int) $values['code'],
				Redirects::meta_key( 'regex' )  => (bool) $values['regex'],
				Redirects::meta_key( 'forced' ) => (bool) $values['forced'],
			),
		);
	}

	/**
	 * Rows of the redirect form.
	 *
	 * @param array<string, mixed> $values Form values.
	 * @param \WP_Post|null        $post   Redirect being edited, null for a new one.
	 */
	protected function fields( array $values, ?\WP_Post $post ): void {
		printf(
			'<tr><th scope="row"><label for="easyrankly-source">%1$s</label></th><td><input type="text" id="easyrankly-source" name="source" value="%2$s" class="regular-text code" required /><p class="description">%3$s</p>',
			esc_html__( 'Source', 'easyrankly' ),
			esc_attr( (string) $values['source'] ),
			esc_html__( 'Path on this site, for example /old-page. Query strings are ignored.', 'easyrankly' )
		);
		printf(
			'<p><label><input type="checkbox" name="regex" value="1"%1$s /> %2$s</label></p><p class="description">%3$s</p></td></tr>',
			checked( (bool) $values['regex'], true, false ),
			esc_html__( 'Regular expression', 'easyrankly' ),
			esc_html__( 'Matched against the lowercase path, for example ^/old/(.*)$; the target can use $1, $2…', 'easyrankly' )
		);

		printf( '<tr><th scope="row"><label for="easyrankly-code">%s</label></th><td><select id="easyrankly-code" name="code">', esc_html__( 'Type', 'easyrankly' ) );
		foreach ( self::codes() as $code => $label ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $code, selected( (int) $values['code'], $code, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row"><label for="easyrankly-target">%1$s</label></th><td><input type="text" id="easyrankly-target" name="target" value="%2$s" class="regular-text code" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Target', 'easyrankly' ),
			esc_attr( (string) $values['target'] ),
			esc_html__( 'Path on this site or full URL. Ignored for 410.', 'easyrankly' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><fieldset><legend class="screen-reader-text">%1$s</legend><label><input type="checkbox" name="active" value="1"%2$s /> %3$s</label><br /><label><input type="checkbox" name="forced" value="1"%4$s /> %5$s</label><p class="description">%6$s</p></fieldset></td></tr>',
			esc_html__( 'Options', 'easyrankly' ),
			checked( (bool) $values['active'], true, false ),
			esc_html__( 'Active', 'easyrankly' ),
			checked( (bool) $values['forced'], true, false ),
			esc_html__( 'Apply even when the page exists', 'easyrankly' ),
			/* translators: %d: maximum number of forced redirects. */
			esc_html( sprintf( __( 'Otherwise the redirect applies only to addresses that do not exist (404). At most %d redirects can be forced.', 'easyrankly' ), Redirects::MAX_FORCED ) )
		);
	}
}
