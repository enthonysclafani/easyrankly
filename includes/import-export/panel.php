<?php
/** Import / Export settings panel. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


function erankly_import_export_render_panel(): void {
	// On Multisite the settings option is a network option; mirror the write-access gate.
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';

	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	ERankly_Migration_Upload_Store::prune_stale();

	$export_url = wp_nonce_url( add_query_arg( 'erankly_io_action', 'export', erankly_import_export_url() ), 'erankly_io_export' );
	$action_url = erankly_import_export_url();

	// Third-party import sources, in the order they appear in the dropdown.
	$sources             = array();
	$source_availability = array();
	$default_source      = '';
	foreach ( erankly_migration_manager()->adapters() as $key => $adapter ) {
		$version         = $adapter->version();
		$sources[ $key ] = trim( $adapter->label() . ( '' !== $version ? ' ' . $version : '' ) );

		$available                   = $adapter->is_available();
		$source_availability[ $key ] = $available;
		if ( $available && '' === $default_source ) {
			$default_source = $key;
		}
	}

	$has_any_source    = '' !== $default_source;
	$active_job        = erankly_migration_job_runner()->active_job();
	$active_import_job = ERankly_Import_Job_Runner::active_job();
	$import_max        = erankly_import_export_max_bytes();
	$focused_report_id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	$focused_report    = '' !== $focused_report_id ? erankly_migration_manager()->get_report( $focused_report_id ) : null;

	erankly_import_export_render_notice();
	if ( is_array( $active_import_job ) ) {
		erankly_import_export_render_import_progress( $active_import_job );
		return;
	}
	erankly_migration_render_report();
	if ( is_array( $active_job ) ) {
		return;
	}
	if ( is_array( $focused_report ) ) {
		?>
		<p class="erankly-migration-back"><a href="<?php echo esc_url( erankly_import_export_url() ); ?>">&larr; <?php esc_html_e( 'Back to all import and export tools', 'easyrankly' ); ?></a></p>
		<?php
		return;
	}
	?>
		<?php erankly_section_open( __( 'Export', 'easyrankly' ), array( 'doc' => 'export' ) ); ?>
			<div class="erankly-card-actions"><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export data', 'easyrankly' ); ?></a></div>
		<?php erankly_section_close(); ?>

		<?php erankly_section_open( __( 'Import', 'easyrankly' ), array( 'doc' => 'import' ) ); ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: maximum safe complete-import size. */
					esc_html__( 'For memory safety, complete JSON imports are limited to %s on this request.', 'easyrankly' ),
					esc_html( size_format( $import_max ) )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" enctype="multipart/form-data" class="erankly-io-form erankly-stack">
				<?php wp_nonce_field( 'erankly_io_import' ); ?>
				<input type="hidden" name="erankly_io_action" value="import">
				<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( (string) $import_max ); ?>">
				<label class="erankly-dropzone" data-erankly-file-dropzone for="erankly-import-file">
					<input type="file" id="erankly-import-file" name="erankly_import_file" accept=".json,application/json" required class="erankly-dropzone-input" data-erankly-file-dropzone-input>
					<span class="erankly-dropzone-icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.99994 17C2.99994 17.93 2.99994 18.395 3.10216 18.7765C3.37956 19.8117 4.18821 20.6204 5.22348 20.8978C5.60498 21 6.06997 21 6.99994 21L16.9999 21C17.9299 21 18.3949 21 18.7764 20.8978C19.8117 20.6204 20.6203 19.8117 20.8977 18.7765C20.9999 18.395 20.9999 17.93 20.9999 17" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/><path d="M16.5 7.49993C16.5 7.49993 13.1858 2.99997 12 2.99996C10.8141 2.99995 7.50002 7.49996 7.50002 7.49996M12 3.99996V16" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/></svg>
					</span>
					<span class="erankly-dropzone-text" data-erankly-file-dropzone-text>
						<strong><?php esc_html_e( 'Click to choose a file', 'easyrankly' ); ?></strong>
						<?php esc_html_e( 'or drag and drop a JSON file here', 'easyrankly' ); ?>
					</span>
				</label>
				<?php submit_button( __( 'Import file', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php erankly_section_close(); ?>

		<?php if ( $has_any_source ) : ?>
		<?php erankly_section_open( __( 'Import from other plugins', 'easyrankly' ), array( 'doc' => 'import-other-plugins' ) ); ?>
			<?php if ( ! is_array( $active_job ) ) : ?>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="erankly-io-third-party">
					<?php wp_nonce_field( 'erankly_io_third_party' ); ?>
					<input type="hidden" name="erankly_io_action" value="migrate">
					<label class="screen-reader-text" for="erankly-io-source"><?php esc_html_e( 'Source plugin', 'easyrankly' ); ?></label>
					<select name="erankly_migration_source" id="erankly-io-source">
						<?php foreach ( $sources as $key => $label ) : ?>
							<?php if ( $source_availability[ $key ] ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default_source ); ?>><?php echo esc_html( $label ); ?></option>
							<?php else : ?>
								<?php /* translators: %s: source plugin name. */ ?>
								<option value="<?php echo esc_attr( $key ); ?>" disabled><?php echo esc_html( sprintf( __( '%s: no data found', 'easyrankly' ), $label ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary" name="erankly_migration_mode" value="preview"><?php esc_html_e( 'Preview migration', 'easyrankly' ); ?></button>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'A migration is already active. Its checkpoint, controls and live counters are shown above; finish or cancel it before starting another source.', 'easyrankly' ); ?></p>
			<?php endif; ?>
		<?php erankly_section_close(); ?>
		<?php endif; ?>
	<?php
}

function erankly_import_export_render_notice(): void {
	$notice = isset( $_GET['erankly_io_notice'] ) ? sanitize_key( wp_unslash( $_GET['erankly_io_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $notice ) {
		return;
	}

	$reported_notices = array( 'migration-started', 'migration-running', 'migration', 'migration-partial', 'migration-cancelled', 'migration-error', 'migration-backup-expired', 'migration-restore-error' );
	$report_id        = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report context.
	$report           = '' !== $report_id ? erankly_migration_manager()->get_report( $report_id ) : null;
	if ( is_array( $report ) && in_array( $notice, $reported_notices, true ) && ! is_array( erankly_migration_job_runner()->active_job() ) ) {
		// A terminal report is the single source of user-facing status. This
		// also prevents a stale migration-started query argument from claiming
		// that a completed job is still queued.
		return;
	}

	$static = erankly_import_export_static_notices();
	if ( isset( $static[ $notice ] ) ) {
		erankly_import_export_print_notice( ...$static[ $notice ] );
	} elseif ( in_array( $notice, array( 'migration', 'migration-partial', 'migration-cancelled', 'migration-error' ), true ) ) {
		erankly_import_export_print_notice( ...erankly_import_export_migration_result_notice( $notice, $report ) );
	} elseif ( 'imported' === $notice ) {
		erankly_import_export_print_notice( ...erankly_import_export_imported_notice() );
	}
}

function erankly_import_export_print_notice( string $class, string $message ): void {
	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Notices whose wording never depends on stored state.
 *
 * @return array<string,array{0:string,1:string}> Notice code => [ CSS class, message ].
 */
function erankly_import_export_static_notices(): array {
	return array(
		'import-error'               => array( 'notice-error', __( 'The import could not be completed. The private upload was removed; values already written were kept.', 'easyrankly' ) ),
		'import-running'             => array( 'notice-info', __( 'An import is already in progress.', 'easyrankly' ) ),
		'import-cancelled'           => array( 'notice-warning', __( 'Import cancelled. Values already written were kept.', 'easyrankly' ) ),
		'custom-code-capability'     => array( 'notice-error', __( 'This backup contains custom code. Your account needs the unfiltered HTML capability before it can be restored.', 'easyrankly' ) ),
		'transfer-starting'          => array( 'notice-info', __( 'Another import or migration is being initialized. Wait a moment, then reload this page before starting a new data transfer.', 'easyrankly' ) ),
		'unsupported-format'         => array( 'notice-error', __( 'This backup format is not supported. Import an EasyRankly 2.0, 3.0 or 4.0 export.', 'easyrankly' ) ),
		'nonce'                      => array( 'notice-error', __( 'Security check failed. Please try again.', 'easyrankly' ) ),
		'invalid'                    => array( 'notice-error', __( 'The file could not be imported. Please upload a valid EasyRankly export file.', 'easyrankly' ) ),
		'too-large'                  => array(
			'notice-error',
			sprintf(
				/* translators: %s: maximum safe complete-import size. */
				__( 'The EasyRankly export exceeds the safe import limit of %s and was rejected before being read into memory.', 'easyrankly' ),
				size_format( erankly_import_export_max_bytes() )
			),
		),
		'too-complex'                => array( 'notice-error', __( 'The EasyRankly export is too structurally complex for the available PHP memory and was rejected before JSON decoding.', 'easyrankly' ) ),
		'migration-backup-expired'   => array( 'notice-error', __( 'The pre-import backup is no longer stored. No value was changed.', 'easyrankly' ) ),
		'migration-restore-error'    => array( 'notice-error', __( 'The pre-import backup could not be restored. Download it from the report and import it manually.', 'easyrankly' ) ),
		'migration-started'          => array( 'notice-success', __( 'Migration queued. It will continue in restart-safe background batches; you can leave this page.', 'easyrankly' ) ),
		'migration-running'          => array( 'notice-info', __( 'The migration is still running from its latest saved checkpoint.', 'easyrankly' ) ),
		'migration-backup-failed'    => array( 'notice-error', __( 'Migration blocked: the automatic pre-import backup could not be written, so there would be no way to undo the import. Check that PHP can write to the system temporary directory, then try again.', 'easyrankly' ) ),
		'migration-backup-too-large' => array( 'notice-error', __( 'Migration blocked: the complete pre-import backup exceeds this server’s safe import limit, so it could not be restored reliably. Increase the import limit or available PHP memory, then try again.', 'easyrankly' ) ),
		'migration-start-error'      => array( 'notice-error', __( 'The migration could not be queued. Check database permissions and source-plugin data, then try again.', 'easyrankly' ) ),
		'migration-action-error'     => array( 'notice-error', __( 'The migration command could not be saved. No unchecked write was performed; review the database/PHP log and retry from the saved checkpoint.', 'easyrankly' ) ),
	);
}

/**
 * Progress panel for the active native import. The script drives one batch per request while the page stays open
 * and resumes automatically from the saved cursor when the page is opened again.
 *
 * @param array<string,mixed> $job Active import job.
 */
function erankly_import_export_render_import_progress( array $job ): void {
	$progress = ERankly_Import_Job_Runner::progress( $job );
	$percent  = (int) floor( 100 * $progress['processed'] / max( 1, $progress['total'] ) );
	?>
	<div class="notice notice-warning inline erankly-import-progress"
		data-erankly-import-progress
		data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'erankly_io_import_batch' ) ); ?>"
		data-job-id="<?php echo esc_attr( (string) $job['id'] ); ?>">
		<div class="erankly-import-progress-body">
		<p><strong><?php esc_html_e( 'Import in progress: do not close this page.', 'easyrankly' ); ?></strong></p>
		<p><?php esc_html_e( 'The import runs while this page is open. If it is interrupted, open this page again to continue from where it stopped.', 'easyrankly' ); ?></p>
		<progress max="100" value="<?php echo esc_attr( (string) $percent ); ?>" data-erankly-import-bar><?php echo esc_html( $percent . '%' ); ?></progress>
		<p data-erankly-import-status aria-live="polite">
			<?php
			/* translators: 1: processed records, 2: total records. */
			echo esc_html( sprintf( __( '%1$d of %2$d records processed.', 'easyrankly' ), $progress['processed'], $progress['total'] ) );
			?>
		</p>
		<noscript><p><?php esc_html_e( 'JavaScript is required to run the import.', 'easyrankly' ); ?></p></noscript>
		<?php if ( empty( $job['owned_purged'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( erankly_import_export_url() ); ?>">
				<?php wp_nonce_field( 'erankly_io_import_cancel' ); ?>
				<input type="hidden" name="erankly_io_action" value="import-cancel">
				<input type="hidden" name="erankly_import_job_id" value="<?php echo esc_attr( (string) $job['id'] ); ?>">
				<?php submit_button( __( 'Cancel import', 'easyrankly' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Outcome of a finished migration run.
 *
 * @param array<string,mixed>|null $report Report named by the request, if it still exists.
 * @return array{0:string,1:string} CSS class and message.
 */
function erankly_import_export_migration_result_notice( string $notice, ?array $report ): array {
	if ( 'migration-error' === $notice || ! is_array( $report ) ) {
		return array( 'notice-error', __( 'The migration could not be completed. Review the report for details.', 'easyrankly' ) );
	}
	if ( 'migration-cancelled' === $notice ) {
		return array( 'notice-warning', __( 'Migration cancelled at its saved checkpoint. Values already written were kept; review the final report.', 'easyrankly' ) );
	}
	if ( 'migration-partial' === $notice ) {
		return array( 'notice-warning', __( 'Migration completed with write errors. Existing EasyRankly data was preserved; review the report before switching SEO plugins.', 'easyrankly' ) );
	}
	if ( 'preview' === (string) $report['mode'] ) {
		return array( 'notice-success', __( 'Migration preview complete. No EasyRankly data was changed.', 'easyrankly' ) );
	}

	return array( 'notice-success', __( 'Migration complete. Existing EasyRankly data was preserved.', 'easyrankly' ) );
}

/**
 * Summary of a completed native import, from the counters carried in the redirect URL.
 *
 * @return array{0:string,1:string} CSS class and message.
 */
function erankly_import_export_imported_notice(): array {
	$count   = static fn( string $key ): int => isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only counters for the confirmation notice.
	$skipped = $count( 'er_redirects_skipped' );

	return array(
		$skipped > 0 ? 'notice-warning' : 'notice-success',
		sprintf(
			/* translators: 1: settings count, 2: redirects count, 3: transformed redirects, 4: skipped redirects, 5: post meta count, 6: term meta count, 7: user meta count. */
			__( 'Import complete. Settings: %1$d. Redirects: %2$d (%3$d safely transformed, %4$d skipped for review). Post metadata: %5$d. Term metadata: %6$d. User metadata: %7$d.', 'easyrankly' ),
			$count( 'er_settings' ),
			$count( 'er_redirects' ),
			$count( 'er_redirects_transformed' ),
			$skipped,
			$count( 'er_post_meta' ),
			$count( 'er_term_meta' ),
			$count( 'er_user_meta' )
		),
	);
}
