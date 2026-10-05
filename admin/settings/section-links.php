<?php
/** Section presentation helpers: the section/card wrapper pair. */
defined( 'ABSPATH' ) || exit;
/**
 * Tracks whether each open section owns a card, so erankly_section_close()
 * never has to repeat the flag. Sections are not nested today; the bookkeeping
 * costs nothing and a mismatched open/close would silently corrupt the markup.
 *
 * @param bool|null $push Card flag to push, or null to pop the last one.
 * @return bool The flag popped, or false when nothing is open.
 */
function erankly_section_stack( ?bool $push = null ): bool {
	static $stack = array();
	if ( null !== $push ) {
		$stack[] = $push;
		return $push;
	}
	if ( array() === $stack ) {
		return false;
	}
	return (bool) array_pop( $stack );
}

/**
 * Opens a settings section: the section wrapper, its heading row and its card.
 *
 * Must be paired with erankly_section_close(). Keeping the three wrappers in
 * one place is what stops the card and the section from drifting apart.
 *
 * @param string $title Already-translated section heading.
 * @param array{class?:string, card?:bool, card_class?:string, view_url?:string, doc?:string} $args
 *   - class:      Extra class(es) for .erankly-settings-section.
 *   - card:       Whether to open a .erankly-card. Defaults to true.
 *   - card_class: Extra class(es) for the card.
 *   - view_url:   Optional View link; also shows Learn when doc is provided.
 *   - doc:        Documentation topic used for the Learn link's source tag.
 */
function erankly_section_open( string $title, array $args = array() ): void {
	$class      = isset( $args['class'] ) ? trim( (string) $args['class'] ) : '';
	$card       = ! isset( $args['card'] ) || (bool) $args['card'];
	$card_class = isset( $args['card_class'] ) ? trim( (string) $args['card_class'] ) : '';
	erankly_section_stack( $card );
	?>
	<div class="erankly-settings-section<?php echo '' !== $class ? ' ' . esc_attr( $class ) : ''; ?>">
		<div class="erankly-section-title-row">
			<h2 class="erankly-section-title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( ! empty( $args['view_url'] ) ) : ?>
				<div class="erankly-section-heading-actions">
					<a href="<?php echo esc_url( $args['view_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'easyrankly' ); ?></a>
					<?php if ( ! empty( $args['doc'] ) ) : ?>
						<span aria-hidden="true">|</span>
						<a href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-' . sanitize_key( $args['doc'] ), 'https://docs.easyrankly.com/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn', 'easyrankly' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $card ) : ?>
		<div class="erankly-card<?php echo '' !== $card_class ? ' ' . esc_attr( $card_class ) : ''; ?>">
			<?php
		endif;
}

/**
 * Closes the card and the section opened by erankly_section_open().
 */
function erankly_section_close(): void {
	$card = erankly_section_stack();
	?>
		<?php if ( $card ) : ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
}
