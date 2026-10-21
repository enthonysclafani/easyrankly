<?php
/**
 * List table of a records screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the records of one post type with the core list table: search, pagination,
 * row actions (Edit, Activate or Deactivate, Delete) and bulk actions.
 *
 * Columns and cells come from the screen; the table only queries and lays them out.
 */
final class RecordsTable extends \WP_List_Table {

	/**
	 * Records per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Screen the table belongs to.
	 *
	 * @var RecordsPage
	 */
	private RecordsPage $page;

	/**
	 * Sets up the table for a screen.
	 *
	 * @param RecordsPage $page Screen.
	 */
	public function __construct( RecordsPage $page ) {
		$this->page = $page;

		parent::__construct(
			array(
				'plural'   => $page->slug(),
				'singular' => $page->slug() . '-item',
				'ajax'     => false,
				'screen'   => $page->slug(),
			)
		);
	}

	/**
	 * Queries the records of the current page of the table, active and inactive.
	 */
	public function prepare_items(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search of the list.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$query = new \WP_Query(
			array(
				'post_type'              => $this->page->post_type(),
				'post_status'            => array( 'publish', 'draft' ),
				's'                      => $search,
				// Names only: the content is the code, which not everyone listed here may read.
				'search_columns'         => array( 'post_title' ),
				'posts_per_page'         => self::PER_PAGE,
				'paged'                  => $this->get_pagenum(),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_term_cache' => false,
			)
		);

		$this->items = is_array( $query->posts ) ? $query->posts : array();
		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Columns: a checkbox, then those of the screen.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array( 'cb' => '<input type="checkbox" />' ) + $this->page->columns();
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'activate'   => __( 'Activate', 'easyrankly' ),
			'deactivate' => __( 'Deactivate', 'easyrankly' ),
			'delete'     => __( 'Delete', 'easyrankly' ),
		);
	}

	/**
	 * Checkbox of a row.
	 *
	 * @param mixed $item Post.
	 * @return string
	 */
	public function column_cb( $item ) {
		if ( ! $item instanceof \WP_Post || ! current_user_can( 'edit_post', $item->ID ) ) {
			return '';
		}

		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input type="checkbox" id="cb-select-%1$d" name="ids[]" value="%1$d" />',
			$item->ID,
			/* translators: %s: record name. */
			esc_html( sprintf( __( 'Select %s', 'easyrankly' ), $item->post_title ) )
		);
	}

	/**
	 * Cell of a screen column.
	 *
	 * @param mixed  $item        Post.
	 * @param string $column_name Column.
	 * @return string Escaped HTML.
	 */
	public function column_default( $item, $column_name ) {
		return $item instanceof \WP_Post ? $this->page->cell( $item, $column_name ) : '';
	}

	/**
	 * Edit, Activate or Deactivate, and Delete under the first column.
	 *
	 * @param mixed  $item        Post.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( ! $item instanceof \WP_Post || $column_name !== $primary || ! current_user_can( 'edit_post', $item->ID ) ) {
			return '';
		}

		$toggle  = 'publish' === $item->post_status ? 'deactivate' : 'activate';
		$actions = array(
			'edit'   => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					$this->page->url(
						array(
							'action' => 'edit',
							'id'     => $item->ID,
						)
					)
				),
				esc_html__( 'Edit', 'easyrankly' )
			),
			$toggle  => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					wp_nonce_url(
						$this->page->url(
							array(
								'action' => $toggle,
								'id'     => $item->ID,
							)
						),
						'easyrankly-' . $toggle . '_' . $item->ID
					)
				),
				'activate' === $toggle ? esc_html__( 'Activate', 'easyrankly' ) : esc_html__( 'Deactivate', 'easyrankly' )
			),
			'delete' => sprintf(
				'<a href="%1$s" class="submitdelete">%2$s</a>',
				esc_url(
					$this->page->url(
						array(
							'action' => 'delete',
							'ids'    => array( $item->ID ),
						)
					)
				),
				esc_html__( 'Delete', 'easyrankly' )
			),
		);

		return $this->row_actions( $actions );
	}

	/**
	 * Message of an empty list.
	 */
	public function no_items(): void {
		echo esc_html( $this->page->label( 'empty' ) );
	}
}
