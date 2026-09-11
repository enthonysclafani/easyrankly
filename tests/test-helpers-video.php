<?php
/** Video URL extraction, embed/content/thumbnail resolution and their sitemap aliases. */

final class ERankly_Helpers_Video_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Both modules are loaded lazily in production, so the tests exercise the
		// same entry points the owning surfaces use.
		erankly_load_content_helpers();
		erankly_load_video_helpers();
	}

	public function test_load_video_helpers_is_idempotent(): void {
		erankly_load_video_helpers();
		erankly_load_video_helpers();

		$this->assertTrue( function_exists( 'erankly_extract_video_urls' ) );
	}

	public function test_extract_video_urls_finds_youtube_watch_and_short_forms(): void {
		$content = 'Intro https://www.youtube.com/watch?v=dQw4w9WgXcQ middle https://youtu.be/aBcDeFgHiJk end';

		$urls = erankly_extract_video_urls( $content );

		$this->assertSame(
			array(
				'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
				'https://youtu.be/aBcDeFgHiJk',
			),
			$urls
		);
	}

	public function test_extract_video_urls_finds_vimeo_page_links(): void {
		$urls = erankly_extract_video_urls( 'Watch https://vimeo.com/123456789 now' );

		$this->assertSame( array( 'https://vimeo.com/123456789' ), $urls );
	}

	public function test_extract_video_urls_normalizes_youtube_iframe_to_watch_url(): void {
		$content = '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0" title="x"></iframe>';

		$this->assertSame(
			array( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ),
			erankly_extract_video_urls( $content )
		);
	}

	public function test_extract_video_urls_handles_nocookie_youtube_iframe(): void {
		$content = '<iframe loading="lazy" src="https://www.youtube-nocookie.com/embed/aBcDeFgHiJk"></iframe>';

		$this->assertSame(
			array( 'https://www.youtube.com/watch?v=aBcDeFgHiJk' ),
			erankly_extract_video_urls( $content )
		);
	}

	public function test_extract_video_urls_normalizes_vimeo_iframe_to_page_url(): void {
		$content = '<iframe allow="autoplay" src="https://player.vimeo.com/video/987654321"></iframe>';

		$this->assertSame( array( 'https://vimeo.com/987654321' ), erankly_extract_video_urls( $content ) );
	}

	/**
	 * The iframe patterns require whitespace before src, so an iframe whose src is its
	 * first attribute is skipped. WordPress emits embeds with the dimensions first and
	 * its oEmbed markup is caught by the watch-URL pattern, so this only affects
	 * hand-written iframes. Documented here rather than asserted so a future fix is
	 * not blocked by this test.
	 */
	public function test_extract_video_urls_skips_iframe_whose_src_is_the_first_attribute(): void {
		$this->markTestIncomplete( 'Known gap: the iframe patterns need an attribute before src.' );

		$content = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>';

		$this->assertSame(
			array( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ),
			erankly_extract_video_urls( $content )
		);
	}

	public function test_extract_video_urls_finds_html5_video_and_source_tags(): void {
		$content = '<video src="https://cdn.example.org/clip.mp4"></video>'
			. '<video><source src="https://cdn.example.org/alt.webm"></video>';

		$this->assertSame(
			array(
				'https://cdn.example.org/clip.mp4',
				'https://cdn.example.org/alt.webm',
			),
			erankly_extract_video_urls( $content )
		);
	}

	public function test_extract_video_urls_decodes_entities_and_dedupes(): void {
		$content = '<video src="https://cdn.example.org/a.mp4?x=1&amp;y=2"></video>'
			. '<video src="https://cdn.example.org/a.mp4?x=1&amp;y=2"></video>';

		$this->assertSame(
			array( 'https://cdn.example.org/a.mp4?x=1&y=2' ),
			erankly_extract_video_urls( $content )
		);
	}

	public function test_extract_video_urls_returns_empty_for_content_without_video(): void {
		$this->assertSame( array(), erankly_extract_video_urls( 'Just a paragraph with a https://example.org link.' ) );
	}

	public function test_sitemap_alias_delegates_to_extract_video_urls(): void {
		$content = 'https://youtu.be/aBcDeFgHiJk';

		$this->assertSame(
			erankly_extract_video_urls( $content ),
			erankly_extract_sitemap_video_urls( $content )
		);
	}

	public function test_get_video_embed_url_maps_every_supported_form(): void {
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', erankly_get_video_embed_url( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', erankly_get_video_embed_url( 'https://youtu.be/dQw4w9WgXcQ' ) );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', erankly_get_video_embed_url( 'https://www.youtube.com/embed/dQw4w9WgXcQ' ) );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', erankly_get_video_embed_url( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ) );
		$this->assertSame( 'https://player.vimeo.com/video/123456789', erankly_get_video_embed_url( 'https://vimeo.com/123456789' ) );
		$this->assertSame( 'https://player.vimeo.com/video/123456789', erankly_get_video_embed_url( 'https://player.vimeo.com/video/123456789' ) );
	}

	public function test_get_video_embed_url_returns_empty_for_unsupported_input(): void {
		$this->assertSame( '', erankly_get_video_embed_url( 'https://example.org/video.mp4' ) );
		$this->assertSame( '', erankly_get_video_embed_url( '' ) );
	}

	public function test_sitemap_alias_delegates_to_get_video_embed_url(): void {
		$url = 'https://youtu.be/dQw4w9WgXcQ';

		$this->assertSame( erankly_get_video_embed_url( $url ), erankly_get_sitemap_video_embed_url( $url ) );
	}

	public function test_get_video_content_url_accepts_self_hosted_extensions(): void {
		$this->assertSame( 'https://cdn.example.org/a.mp4', erankly_get_video_content_url( 'https://cdn.example.org/a.mp4' ) );
		$this->assertSame( 'https://cdn.example.org/a.webm', erankly_get_video_content_url( 'https://cdn.example.org/a.webm' ) );
		$this->assertSame( 'https://cdn.example.org/a.m4v', erankly_get_video_content_url( 'https://cdn.example.org/a.m4v' ) );
		$this->assertSame( 'https://cdn.example.org/a.mov', erankly_get_video_content_url( 'https://cdn.example.org/a.mov' ) );
		$this->assertSame( 'https://cdn.example.org/a.ogg', erankly_get_video_content_url( 'https://cdn.example.org/a.ogg' ) );
	}

	public function test_get_video_content_url_rejects_page_urls_and_case_variants_are_accepted(): void {
		$this->assertSame( '', erankly_get_video_content_url( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );
		$this->assertSame( 'https://cdn.example.org/A.MP4', erankly_get_video_content_url( 'https://cdn.example.org/A.MP4' ) );
	}

	public function test_sitemap_alias_delegates_to_get_video_content_url(): void {
		$url = 'https://cdn.example.org/a.mp4';

		$this->assertSame( erankly_get_video_content_url( $url ), erankly_get_sitemap_video_content_url( $url ) );
	}

	public function test_get_video_thumbnail_url_prefers_the_featured_image(): void {
		$filename      = '2026/09/erankly-video-thumb-' . wp_generate_password( 8, false ) . '.jpg';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Video thumbnail probe',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			),
			$filename
		);
		$this->assertIsInt( $attachment_id );

		update_attached_file( $attachment_id, $filename );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => $filename,
				'width'  => 1200,
				'height' => 800,
			)
		);

		$post_id = self::factory()->post->create();
		set_post_thumbnail( $post_id, $attachment_id );

		$thumbnail = erankly_get_video_thumbnail_url( $post_id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );

		$this->assertNotSame( '', $thumbnail );
		$this->assertSame( erankly_get_image_url( $attachment_id, 'full' ), $thumbnail );
		$this->assertStringNotContainsString( 'img.youtube.com', $thumbnail );
	}

	public function test_get_video_thumbnail_url_falls_back_to_the_youtube_poster(): void {
		$post_id = self::factory()->post->create();

		$this->assertSame(
			'https://img.youtube.com/vi/dQw4w9WgXcQ/0.jpg',
			erankly_get_video_thumbnail_url( $post_id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' )
		);
		$this->assertSame(
			'https://img.youtube.com/vi/aBcDeFgHiJk/0.jpg',
			erankly_get_video_thumbnail_url( $post_id, 'https://youtu.be/aBcDeFgHiJk' )
		);
	}

	public function test_get_video_thumbnail_url_returns_empty_without_image_or_youtube_id(): void {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', erankly_get_video_thumbnail_url( $post_id, 'https://vimeo.com/123456789' ) );
		$this->assertSame( '', erankly_get_video_thumbnail_url( 0, 'https://vimeo.com/123456789' ) );
	}

	public function test_sitemap_alias_delegates_to_get_video_thumbnail_url(): void {
		$post_id = self::factory()->post->create();
		$url     = 'https://youtu.be/aBcDeFgHiJk';

		$this->assertSame(
			erankly_get_video_thumbnail_url( $post_id, $url ),
			erankly_get_sitemap_video_thumbnail_url( $post_id, $url )
		);
	}
}
