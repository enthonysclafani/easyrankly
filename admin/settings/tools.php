<?php
/** Database tools panel: separate from the settings/autosave form. */
defined( 'ABSPATH' ) || exit;

function erankly_tools_render_panel(): void {
	if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
		return;
	}
	?>
	<section class="erankly-tools erankly-settings-section" data-erankly-database-tools data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'erankly_database_tools' ) ); ?>">
		<div class="erankly-section-title-row">
			<h2 class="erankly-section-title"><?php esc_html_e( 'Database cleanup', 'easyrankly' ); ?></h2>
		</div>
		<div class="erankly-card">
			<div class="erankly-tools-intro">
				<h3 class="erankly-field-label"><?php esc_html_e( 'Keep your database tidy', 'easyrankly' ); ?></h3>
				<p><?php esc_html_e( 'Analyze unused data, choose what to remove, and review the exact list before confirming.', 'easyrankly' ); ?></p>
				<p class="erankly-tools-scope"><?php esc_html_e( 'Published content, normal drafts, media, users, SEO settings and active cache entries are preserved.', 'easyrankly' ); ?></p>
				<?php if ( is_multisite() ) : ?>
				<p class="erankly-tools-scope"><?php esc_html_e( 'This cleanup applies only to the current site. Shared network data is excluded.', 'easyrankly' ); ?></p>
				<?php endif; ?>
			</div>
			<fieldset class="erankly-tools-options erankly-stack" data-tools-options>
				<legend class="screen-reader-text"><?php esc_html_e( 'Choose database cleanup categories', 'easyrankly' ); ?></legend>
				<?php foreach ( ERankly_Database_Tools::categories() as $key => $category ) : ?>
				<div class="erankly-tools-row" data-tools-row="<?php echo esc_attr( $key ); ?>">
					<input type="checkbox" class="erankly-toggle" id="erankly-tools-<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $key ); ?>" data-tools-category data-recommended="<?php echo $category['recommended'] ? '1' : '0'; ?>" disabled aria-describedby="erankly-tools-help-<?php echo esc_attr( $key ); ?>">
					<div class="erankly-tools-row-content">
						<div class="erankly-tools-row-title">
						<label class="erankly-field-label" for="erankly-tools-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $category['label'] ); ?></label>
						<span class="erankly-tools-badge<?php echo $category['recommended'] ? ' is-recommended' : ''; ?>"><?php echo esc_html( $category['recommended'] ? __( 'Recommended', 'easyrankly' ) : __( 'Optional', 'easyrankly' ) ); ?></span>
						</div>
						<p class="description" id="erankly-tools-help-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $category['available'] ? $category['description'] : $category['reason'] ); ?></p>
					</div>
					<span class="erankly-tools-count" data-tools-count><?php echo $category['available'] ? '…' : '—'; ?></span>
				</div>
				<?php endforeach; ?>
			</fieldset>
			<div class="erankly-tools-actions erankly-card-actions">
				<button type="button" class="button button-primary" data-tools-preview disabled><?php esc_html_e( 'Clean database', 'easyrankly' ); ?></button>
				<button type="button" class="button" data-tools-scan><?php esc_html_e( 'Analyze again', 'easyrankly' ); ?></button>
				<span class="erankly-tools-selection" data-tools-selection></span>
			</div>
			<p class="erankly-tools-footnote description"><?php esc_html_e( 'Table optimization, active transients, pending comments, pingbacks, trackbacks and custom content types are excluded.', 'easyrankly' ); ?></p>
			<p class="erankly-tools-status" role="status" aria-live="polite" data-tools-status></p>
		</div>
		<div class="erankly-tools-results erankly-card" data-tools-results hidden tabindex="-1">
			<h3><?php esc_html_e( 'Cleanup results', 'easyrankly' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Category', 'easyrankly' ); ?></th><th scope="col"><?php esc_html_e( 'Removed', 'easyrankly' ); ?></th><th scope="col"><?php esc_html_e( 'Skipped', 'easyrankly' ); ?></th><th scope="col"><?php esc_html_e( 'Failed', 'easyrankly' ); ?></th></tr></thead>
				<tbody data-tools-result-rows></tbody>
			</table>
		</div>
		<div class="erankly-tools-progress" data-tools-progress hidden>
			<progress value="0" max="1" data-tools-progress-bar aria-label="<?php esc_attr_e( 'Cleanup progress', 'easyrankly' ); ?>"></progress>
			<button type="button" class="button" data-tools-stop><?php esc_html_e( 'Stop cleanup', 'easyrankly' ); ?></button>
		</div>
		<dialog class="erankly-tools-dialog" data-tools-dialog aria-labelledby="erankly-tools-dialog-title" aria-describedby="erankly-tools-dialog-desc">
			<h2 id="erankly-tools-dialog-title"><?php esc_html_e( 'Review database cleanup', 'easyrankly' ); ?></h2>
			<p id="erankly-tools-dialog-desc"><?php esc_html_e( 'Only the items listed below will be removed. Changed or restored items will be skipped. This deletion is permanent.', 'easyrankly' ); ?></p>
			<div class="erankly-tools-summary" data-tools-summary></div>
			<p data-tools-limited hidden><?php esc_html_e( 'This preview includes up to 500 items. Analyze again after cleanup to review the next group.', 'easyrankly' ); ?></p>
			<p class="erankly-tools-footnote"><?php esc_html_e( 'Keep a recent database backup before deleting recoverable content.', 'easyrankly' ); ?></p>
			<label class="erankly-tools-acknowledge"><input type="checkbox" data-tools-acknowledge> <?php esc_html_e( 'I reviewed this list and understand that these items will be permanently deleted.', 'easyrankly' ); ?></label>
			<div class="erankly-tools-dialog-actions">
				<button type="button" class="button" data-tools-cancel autofocus><?php esc_html_e( 'Cancel', 'easyrankly' ); ?></button>
				<button type="button" class="button button-primary" data-tools-confirm disabled><?php esc_html_e( 'Confirm cleanup', 'easyrankly' ); ?></button>
			</div>
		</dialog>
		<div hidden data-tools-i18n>
			<span data-text="analyzing"><?php esc_html_e( 'Analyzing the database…', 'easyrankly' ); ?></span>
			<span data-text="analyzed"><?php esc_html_e( 'Analysis complete. Review your selection before cleaning.', 'easyrankly' ); ?></span>
			<span data-text="empty"><?php esc_html_e( 'No eligible data found for the selected categories. Nothing was removed.', 'easyrankly' ); ?></span>
			<span data-text="previewing"><?php esc_html_e( 'Preparing the exact cleanup list…', 'easyrankly' ); ?></span>
			<span data-text="cleaning"><?php esc_html_e( 'Cleaning the confirmed items…', 'easyrankly' ); ?></span>
			<span data-text="complete"><?php esc_html_e( 'Cleanup complete. Changed or restored items were skipped; failed items were kept where possible.', 'easyrankly' ); ?></span>
			<span data-text="stopping"><?php esc_html_e( 'Stopping after the current group…', 'easyrankly' ); ?></span>
			<span data-text="stopped"><?php esc_html_e( 'Cleanup stopped. The results show the items already processed.', 'easyrankly' ); ?></span>
			<span data-text="error"><?php esc_html_e( 'The request failed. If cleanup had started, some items may have been removed. Analyze again to check the remaining data.', 'easyrankly' ); ?></span>
			<span data-text="selected"><?php esc_html_e( 'Selected categories:', 'easyrankly' ); ?></span>
			<span data-text="related"><?php esc_html_e( 'Associated metadata / revisions:', 'easyrankly' ); ?></span>
			<span data-text="unavailable"><?php esc_html_e( 'Unavailable', 'easyrankly' ); ?></span>
		</div>
	</section>
	<?php
}
