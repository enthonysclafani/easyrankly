<?php
/**
 * Classic admin screen for records kept as a non-public post type.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * List, "Add" / "Edit" form and delete confirmation of one post type, like the core screens
 * for posts, users and plugins, without JavaScript.
 *
 * Every write goes through the REST route of the post type with rest_do_request(), as the
 * current user: the same permission checks and validation as the API, in one place. The
 * forms post to the screen itself, so an invalid value comes back with its error and the
 * values typed; a successful change redirects to the list with a message.
 *
 * Views (`action` in the URL): none for the list, `new`, `edit` with `id`, `delete` with `ids[]`.
 */
abstract class RecordsPage {

	/**
	 * Error to show on the current view.
	 *
	 * @var string
	 */
	protected string $error = '';

	/**
	 * Values typed in the form, shown again after an error.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $submitted = null;

	/**
	 * Submenu slug.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Post type of the records.
	 *
	 * @return string
	 */
	abstract public function post_type(): string;

	/**
	 * REST route of the post type, e.g. `/wp/v2/easyrankly-redirects`.
	 *
	 * @return string
	 */
	abstract protected function route(): string;

	/**
	 * Whether the current user may use the screen.
	 *
	 * @return bool
	 */
	abstract protected function can_manage(): bool;

	/**
	 * Texts of the screen: title, add, edit, empty, search, saved, deleted, activated, deactivated, confirm.
	 *
	 * @return array<string, string>
	 */
	abstract protected function labels(): array;

	/**
	 * Columns after the checkbox, the first one with the row actions.
	 *
	 * @return array<string, string> Column => label.
	 */
	abstract public function columns(): array;

	/**
	 * Escaped HTML of one cell.
	 *
	 * @param \WP_Post $post   Record.
	 * @param string   $column Column.
	 * @return string
	 */
	abstract public function cell( \WP_Post $post, string $column ): string;

	/**
	 * Form values of a record, or of a new one.
	 *
	 * @param \WP_Post|null $post Record, null for a new one.
	 * @return array<string, mixed>
	 */
	abstract protected function values( ?\WP_Post $post ): array;

	/**
	 * Form values from the submitted form.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function posted(): array;

	/**
	 * REST body of the form values.
	 *
	 * @param array<string, mixed> $values Form values.
	 * @param \WP_Post|null        $post   Record being edited, null for a new one.
	 * @return array<string, mixed>
	 */
	abstract protected function to_rest( array $values, ?\WP_Post $post ): array;

	/**
	 * Prints the rows of the form table.
	 *
	 * @param array<string, mixed> $values Form values.
	 * @param \WP_Post|null        $post   Record being edited, null for a new one.
	 */
	abstract protected function fields( array $values, ?\WP_Post $post ): void;

	/**
	 * Notices shown above the list and the form (e.g. safe mode).
	 */
	protected function notices(): void {}

	/**
	 * Assets of the form (e.g. the code editor).
	 *
	 * @param \WP_Post|null $post Record being edited, null for a new one.
	 */
	protected function enqueue_form( ?\WP_Post $post ): void {}

	/**
	 * One text of the screen.
	 *
	 * @param string $key Text key.
	 * @return string
	 */
	public function label( string $key ): string {
		return $this->labels()[ $key ] ?? '';
	}

	/**
	 * URL of the screen with query arguments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => $this->slug() ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Handles the submitted forms and links before anything is printed.
	 */
	public function load(): void {
		if ( ! $this->can_manage() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- Each change below checks its nonce first; reading the view does not change anything.
		if ( isset( $_POST['easyrankly_save'] ) ) {
			$this->save();
		} elseif ( isset( $_POST['easyrankly_delete'] ) ) {
			$this->delete();
		} elseif ( in_array( $this->action(), array( 'activate', 'deactivate' ), true ) && isset( $_GET['id'] ) ) {
			$this->toggle_one( $this->action(), absint( $_GET['id'] ) );
		} elseif ( in_array( $this->action(), array( 'activate', 'deactivate' ), true ) && isset( $_GET['ids'] ) ) {
			$this->toggle_bulk( $this->action() );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( in_array( $this->action(), array( 'new', 'edit' ), true ) ) {
			add_action( 'admin_enqueue_scripts', fn() => $this->enqueue_form( $this->record() ) );
		}
	}

	/**
	 * Prints the view asked for.
	 */
	public function render(): void {
		if ( ! $this->can_manage() ) {
			return;
		}

		echo '<div class="wrap">';
		switch ( $this->action() ) {
			case 'new':
			case 'edit':
				$this->render_form();
				break;
			case 'delete':
				$this->render_delete();
				break;
			default:
				$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * The list with search, row and bulk actions.
	 */
	private function render_list(): void {
		printf(
			'<h1 class="wp-heading-inline">%1$s</h1> <a href="%2$s" class="page-title-action">%3$s</a><hr class="wp-header-end" />',
			esc_html( $this->label( 'title' ) ),
			esc_url( $this->url( array( 'action' => 'new' ) ) ),
			esc_html( $this->label( 'add' ) )
		);
		$this->notices();
		$this->messages();

		$table = new RecordsTable( $this );
		$table->prepare_items();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( $this->slug() ) );
		$table->search_box( $this->label( 'search' ), $this->slug() );
		$table->display();
		echo '</form>';
	}

	/**
	 * The "Add" or "Edit" form.
	 */
	private function render_form(): void {
		$post = $this->record();
		if ( 'edit' === $this->action() && null === $post ) {
			printf( '<h1>%s</h1>', esc_html( $this->label( 'edit' ) ) );
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'This item does not exist.', 'easyrankly' ) );
			return;
		}

		printf( '<h1>%s</h1>', esc_html( null === $post ? $this->label( 'add' ) : $this->label( 'edit' ) ) );
		$this->notices();
		$this->messages();

		// The form shows the record as stored: only those who may edit it get to read it.
		if ( null !== $post && ! current_user_can( 'edit_post', $post->ID ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Sorry, you are not allowed to edit this item.', 'easyrankly' ) );
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $this->url() ), esc_html__( 'Back to the list', 'easyrankly' ) );
			return;
		}

		$id = null === $post ? 0 : $post->ID;
		printf(
			'<form method="post" action="%s">',
			esc_url(
				$this->url(
					null === $post ? array( 'action' => 'new' ) : array(
						'action' => 'edit',
						'id'     => $id,
					)
				)
			)
		);
		wp_nonce_field( 'easyrankly-save_' . $this->slug() . '_' . $id );
		printf( '<input type="hidden" name="easyrankly_save" value="%d" />', (int) $id );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->fields( $this->submitted ?? $this->values( $post ), $post );
		echo '</tbody></table>';
		submit_button( null === $post ? $this->label( 'add' ) : __( 'Save changes', 'easyrankly' ) );
		echo '</form>';
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $this->url() ), esc_html__( 'Back to the list', 'easyrankly' ) );
	}

	/**
	 * Asks to confirm the deletion of the records chosen.
	 */
	private function render_delete(): void {
		printf( '<h1>%s</h1>', esc_html__( 'Delete', 'easyrankly' ) );
		$this->messages();

		$posts = $this->records( $this->ids() );
		if ( array() === $posts ) {
			printf( '<p>%s</p>', esc_html__( 'Nothing to delete.', 'easyrankly' ) );
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $this->url() ), esc_html__( 'Back to the list', 'easyrankly' ) );
			return;
		}

		printf( '<p>%s</p><ul class="ul-disc">', esc_html( $this->label( 'confirm' ) ) );
		foreach ( $posts as $post ) {
			printf( '<li>%s</li>', esc_html( $post->post_title ) );
		}
		echo '</ul>';

		printf( '<form method="post" action="%s">', esc_url( $this->url( array( 'action' => 'delete' ) ) ) );
		wp_nonce_field( 'easyrankly-delete_' . $this->slug() );
		foreach ( $posts as $post ) {
			printf( '<input type="hidden" name="ids[]" value="%d" />', (int) $post->ID );
		}
		echo '<input type="hidden" name="easyrankly_delete" value="1" />';
		submit_button( __( 'Yes, delete', 'easyrankly' ), 'primary', 'submit', false );
		printf( ' <a href="%1$s" class="button">%2$s</a></form>', esc_url( $this->url() ), esc_html__( 'Cancel', 'easyrankly' ) );
	}

	/**
	 * Saves the form through REST: back to the list on success, the form with the error otherwise.
	 */
	private function save(): void {
		$id = isset( $_POST['easyrankly_save'] ) ? absint( $_POST['easyrankly_save'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line, with the ID in the action.
		check_admin_referer( 'easyrankly-save_' . $this->slug() . '_' . $id );

		$post = $id > 0 ? $this->record( $id ) : null;
		if ( $id > 0 && null === $post ) {
			$this->error = __( 'This item does not exist.', 'easyrankly' );
			return;
		}

		$values = $this->posted();
		$result = $this->dispatch( 'POST', $id > 0 ? $id : null, $this->to_rest( $values, $post ) );

		if ( is_wp_error( $result ) ) {
			$this->error     = $result->get_error_message();
			$this->submitted = $values;
			return;
		}

		$this->redirect( array( 'message' => 'saved' ) );
	}

	/**
	 * Deletes the records confirmed on the delete view.
	 */
	private function delete(): void {
		check_admin_referer( 'easyrankly-delete_' . $this->slug() );

		$deleted = 0;
		$errors  = array();
		foreach ( $this->ids() as $id ) {
			$error = $this->failure( $id, $this->dispatch( 'DELETE', $id, array( 'force' => true ) ) );
			if ( null === $error ) {
				++$deleted;
			} else {
				$errors[] = $error;
			}
		}

		$this->finish(
			$deleted,
			$errors,
			array(
				'message' => 'deleted',
				'count'   => $deleted,
			)
		);
	}

	/**
	 * Activates or deactivates one record from its row action.
	 *
	 * @param string $action "activate" or "deactivate".
	 * @param int    $id     Record ID.
	 */
	private function toggle_one( string $action, int $id ): void {
		check_admin_referer( 'easyrankly-' . $action . '_' . $id );
		$this->toggle( $action, array( $id ) );
	}

	/**
	 * Activates or deactivates the records checked in the list.
	 *
	 * @param string $action "activate" or "deactivate".
	 */
	private function toggle_bulk( string $action ): void {
		check_admin_referer( 'bulk-' . $this->slug() );
		$this->toggle( $action, $this->ids() );
	}

	/**
	 * Changes the status of records through REST, going on after an error.
	 *
	 * @param string $action "activate" or "deactivate".
	 * @param int[]  $ids    Record IDs.
	 */
	private function toggle( string $action, array $ids ): void {
		$done   = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			$error = $this->failure( $id, $this->dispatch( 'POST', $id, array( 'status' => 'activate' === $action ? 'publish' : 'draft' ) ) );
			if ( null === $error ) {
				++$done;
			} else {
				$errors[] = $error;
			}
		}

		$this->finish( $done, $errors, array( 'message' => 'activate' === $action ? 'activated' : 'deactivated' ) );
	}

	/**
	 * Message of a failed change on one record, or null when it succeeded.
	 *
	 * @param int            $id     Record ID.
	 * @param true|\WP_Error $result Result of the change.
	 * @return string|null
	 */
	private function failure( int $id, $result ): ?string {
		if ( ! is_wp_error( $result ) ) {
			return null;
		}

		$post = $this->record( $id );

		return null === $post ? $result->get_error_message() : $post->post_title . ': ' . $result->get_error_message();
	}

	/**
	 * Back to the list with a message when every change succeeded; otherwise stays on the
	 * view and says how many changed and which did not, one per line.
	 *
	 * @param int                  $done   Records changed.
	 * @param string[]             $errors One message per record not changed.
	 * @param array<string, mixed> $args   Query arguments of the success message.
	 */
	private function finish( int $done, array $errors, array $args ): void {
		if ( array() === $errors ) {
			$this->redirect( $args );
		}

		/* translators: %d: number of items changed. */
		$summary     = sprintf( _n( '%d item changed. Not changed:', '%d items changed. Not changed:', $done, 'easyrankly' ), $done );
		$this->error = implode( "\n", array_merge( array( $summary ), $errors ) );
	}

	/**
	 * Runs a REST request on the route of the post type as the current user.
	 *
	 * @param string               $method HTTP method.
	 * @param int|null             $id     Record ID, null to create.
	 * @param array<string, mixed> $body   Body parameters.
	 * @return true|\WP_Error
	 */
	protected function dispatch( string $method, ?int $id, array $body ) {
		$request = new \WP_REST_Request( $method, $this->route() . ( null === $id ? '' : '/' . $id ) );
		$request->set_body_params( $body );
		$error = rest_do_request( $request )->as_error();

		return $error instanceof \WP_Error ? $error : true;
	}

	/**
	 * Goes back to the list with a message, and stops.
	 *
	 * @param array<string, mixed> $args Query arguments of the message.
	 */
	protected function redirect( array $args ): void {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * The error of this request, or the message of the change just made.
	 */
	private function messages(): void {
		if ( '' !== $this->error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', nl2br( esc_html( $this->error ), false ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which fixed message to show.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$text    = $this->label( $message );
		if ( 'deleted' === $message ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only a number in a fixed message.
			$count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 1;
			/* translators: %d: number of items deleted. */
			$text = sprintf( _n( '%d item deleted.', '%d items deleted.', $count, 'easyrankly' ), $count );
		}

		if ( '' !== $text && in_array( $message, array( 'saved', 'deleted', 'activated', 'deactivated' ), true ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
		}
	}

	/**
	 * View asked for in the URL; the list table names bulk actions `action` or `action2`.
	 *
	 * @return string
	 */
	protected function action(): string {
		// phpcs:disable WordPress.Security.NonceVerification -- Choosing a view changes nothing; changes check their nonce.
		foreach ( array( 'action', 'action2' ) as $key ) {
			$action = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $action && '-1' !== $action ) {
				return $action;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification

		return '';
	}

	/**
	 * Record IDs sent by the list or the delete view.
	 *
	 * @return list<int>
	 */
	private function ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The callers check the nonce before changing anything.
		$ids = isset( $_REQUEST['ids'] ) ? (array) wp_unslash( $_REQUEST['ids'] ) : array();

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * The record of the edit view, or another one, if it is of this post type.
	 *
	 * @param int|null $id Record ID; null reads `id` from the URL.
	 * @return \WP_Post|null
	 */
	protected function record( ?int $id = null ): ?\WP_Post {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which record to show.
		$id   = $id ?? ( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && $this->post_type() === $post->post_type ? $post : null;
	}

	/**
	 * Records of this post type among the IDs.
	 *
	 * @param int[] $ids Record IDs.
	 * @return list<\WP_Post>
	 */
	private function records( array $ids ): array {
		return array_values( array_filter( array_map( fn( int $id ): ?\WP_Post => $this->record( $id ), $ids ) ) );
	}
}
