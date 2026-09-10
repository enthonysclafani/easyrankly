<?php
/** Section presentation helpers: the section/card wrapper pair and the per-section external documentation links. Missing sections intentionally render no link. */
defined( 'ABSPATH' ) || exit;
function erankly_section_doc_links(): array {
	return apply_filters(
		'erankly_section_doc_links',
		array(
			'search-engines'           => 'https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data',
			'custom-schema'            => 'https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data',
			'xml-sitemap'              => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview',
			'news-sitemap'             => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap',
			'image-sitemap'            => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps',
			'video-sitemap'            => 'https://developers.google.com/search/docs/crawling-indexing/sitemaps/video-sitemaps',
			'editor-schema'            => 'https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data',
		)
	);
}
function erankly_render_section_doc_link( string $section ): void {
	$urls = erankly_section_doc_links();
	$url  = (string) ( $urls[ $section ] ?? '' );
	if ( '' === $url ) {
		return;
	}
	printf(
		'<a class="erankly-section-doc-link" href="%1$s" data-erankly-doc-section="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
		esc_url( $url ),
		esc_attr( $section ),
		esc_html__( 'Learn more', 'easyrankly' )
	);
}
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
 * one place is what stops the card and the section from drifting apart, and it
 * makes the documentation link impossible to forget.
 *
 * @param string $title Already-translated section heading.
 * @param array{doc?:string, hidden?:bool, class?:string, card?:bool, card_class?:string} $args
 *   - doc:        Key in erankly_section_doc_links(); no link is rendered when absent.
 *   - hidden:     Renders the section with the HTML hidden attribute.
 *   - class:      Extra class(es) for .erankly-settings-section.
 *   - card:       Whether to open a .erankly-card. Defaults to true.
 *   - card_class: Extra class(es) for the card.
 */
function erankly_section_open( string $title, array $args = array() ): void {
	$doc        = isset( $args['doc'] ) ? (string) $args['doc'] : '';
	$class      = isset( $args['class'] ) ? trim( (string) $args['class'] ) : '';
	$card       = ! isset( $args['card'] ) || (bool) $args['card'];
	$card_class = isset( $args['card_class'] ) ? trim( (string) $args['card_class'] ) : '';
	$hidden     = ! empty( $args['hidden'] );
	erankly_section_stack( $card );
	?>
	<div class="erankly-settings-section<?php echo '' !== $class ? ' ' . esc_attr( $class ) : ''; ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
		<div class="erankly-section-title-row">
			<h2 class="erankly-section-title"><?php echo esc_html( $title ); ?></h2>
			<?php erankly_render_section_doc_link( $doc ); ?>
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
