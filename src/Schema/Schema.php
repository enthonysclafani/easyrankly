<?php
/**
 * Schema.org structured data.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Schema;

use EasyRankly\Canonical\Canonical;
use EasyRankly\Context\Context;
use EasyRankly\Settings\Settings;
use EasyRankly\Titles\Template;
use EasyRankly\Titles\Titles;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one JSON-LD graph per page: the site's Organization or Person, WebSite,
 * WebPage, Article on posts, and BreadcrumbList when the page shows a breadcrumb.
 *
 * Nodes reference each other by @id, so search engines read one connected graph
 * instead of separate fragments.
 *
 * BreadcrumbList is built from the items the core/breadcrumbs block actually rendered.
 * Block themes render the template before wp_head, so by then the items are known;
 * pages without the block (or classic themes, which render after <head>) get no
 * BreadcrumbList, because structured data must describe what visitors see.
 */
final class Schema {

	/**
	 * Breadcrumb items of the first core/breadcrumbs block rendered in this request.
	 *
	 * @var list<array{label: string, url?: string}>|null
	 */
	private ?array $breadcrumbs = null;

	/**
	 * Hooks the breadcrumb capture and the JSON-LD output.
	 */
	public function register(): void {
		add_filter( 'block_core_breadcrumbs_items', array( $this, 'capture_breadcrumbs' ), PHP_INT_MAX );
		add_action( 'wp_head', array( $this, 'print_graph' ), 3 );
	}

	/**
	 * Remembers the items of the first breadcrumb block rendered, without changing them.
	 *
	 * @param mixed $items Items about to be rendered.
	 * @return mixed
	 */
	public function capture_breadcrumbs( $items ) {
		if ( null === $this->breadcrumbs && is_array( $items ) && ! doing_action( 'wp_head' ) ) {
			$this->breadcrumbs = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item['label'] ) && is_string( $item['label'] ) ) {
					$this->breadcrumbs[] = isset( $item['url'] ) && is_string( $item['url'] ) && '' !== $item['url']
						? array(
							'label' => $item['label'],
							'url'   => $item['url'],
						)
						: array( 'label' => $item['label'] );
				}
			}
		}

		return $items;
	}

	/**
	 * Prints the graph as a single JSON-LD script (data, not code).
	 */
	public function print_graph(): void {
		$graph = $this->graph();

		if ( array() === $graph ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
			)
		);
	}

	/**
	 * Nodes of the current page.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function graph(): array {
		$keys = Context::keys();
		if ( array() === $keys || is_search() || is_404() ) {
			return array();
		}

		$home     = home_url( '/' );
		$identity = $this->identity( $home );
		$graph    = array( $identity );

		$graph[] = array_filter(
			array(
				'@type'       => 'WebSite',
				'@id'         => $home . '#website',
				'url'         => $home,
				'name'        => self::text( (string) get_bloginfo( 'name' ) ),
				'description' => self::text( (string) get_bloginfo( 'description' ) ),
				'inLanguage'  => get_bloginfo( 'language' ),
				'publisher'   => array( '@id' => $identity['@id'] ),
			)
		);

		$object = get_queried_object();
		$post   = $object instanceof \WP_Post && is_singular() ? $object : null;
		$url    = null !== $post ? (string) wp_get_canonical_url( $post ) : Canonical::archive_url();

		if ( '' === $url ) {
			// Noindex pages have no canonical: describe the page at its own address.
			$url = null !== $post ? (string) get_permalink( $post ) : $home;
		}

		$breadcrumb = $this->breadcrumb_list( $url );
		$page       = array(
			'@type'       => $this->page_type( $keys ),
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => '' !== Titles::title() ? Titles::title() : self::text( wp_get_document_title() ),
			'description' => Titles::description(),
			'isPartOf'    => array( '@id' => $home . '#website' ),
			'inLanguage'  => get_bloginfo( 'language' ),
		);

		if ( null !== $post ) {
			$page['datePublished'] = (string) get_post_time( 'c', true, $post );
			$page['dateModified']  = (string) get_post_modified_time( 'c', true, $post );
		}
		if ( null !== $breadcrumb ) {
			$page['breadcrumb'] = array( '@id' => $breadcrumb['@id'] );
		}
		if ( is_front_page() ) {
			$page['about'] = array( '@id' => $identity['@id'] );
		}
		if ( 'ProfilePage' === $page['@type'] && $object instanceof \WP_User ) {
			// Google requires mainEntity on a ProfilePage: the person the archive is about.
			$page['mainEntity'] = $this->profile( $object );
		}

		$graph[] = array_filter( $page );

		if ( null !== $post && 'post' === $post->post_type && ! is_front_page() ) {
			$graph[] = $this->article( $post, $url, $identity['@id'] );
		}

		if ( null !== $breadcrumb ) {
			$graph[] = $breadcrumb;
		}

		return $graph;
	}

	/**
	 * The Organization or Person the site represents.
	 *
	 * @param string $home Home URL.
	 * @return array<string, mixed>
	 */
	private function identity( string $home ): array {
		$is_person = 'person' === Settings::value( 'identity_type' );
		$name      = (string) Settings::value( 'identity_name' );
		$same_as   = Settings::value( 'same_as' );
		$image     = $this->image( (int) Settings::value( 'identity_logo' ), $home . ( $is_person ? '#personimage' : '#logo' ) );

		$node = array(
			'@type' => $is_person ? 'Person' : 'Organization',
			'@id'   => $home . ( $is_person ? '#person' : '#organization' ),
			'name'  => '' !== $name ? $name : self::text( (string) get_bloginfo( 'name' ) ),
			'url'   => $home,
		);

		if ( null !== $image ) {
			$node[ $is_person ? 'image' : 'logo' ] = $image;
		}
		if ( is_array( $same_as ) && array() !== $same_as ) {
			$node['sameAs'] = array_values( $same_as );
		}

		return $node;
	}

	/**
	 * Article node of a post.
	 *
	 * The author is the user core loads for the post anyway (setup_postdata), so this adds no query.
	 *
	 * @param \WP_Post $post        Post.
	 * @param string   $url         URL of the page.
	 * @param string   $publisher   @id of the Organization or Person.
	 * @return array<string, mixed>
	 */
	private function article( \WP_Post $post, string $url, string $publisher ): array {
		$author = get_userdata( (int) $post->post_author );
		$image  = $this->image( (int) get_post_thumbnail_id( $post ), $url . '#primaryimage' );

		return array_filter(
			array(
				'@type'            => 'Article',
				'@id'              => $url . '#article',
				'headline'         => Template::truncate( self::text( get_the_title( $post ) ), 110 ),
				'datePublished'    => (string) get_post_time( 'c', true, $post ),
				'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'isPartOf'         => array( '@id' => $url . '#webpage' ),
				'publisher'        => array( '@id' => $publisher ),
				'author'           => false === $author ? null : array(
					'@type' => 'Person',
					'name'  => $author->display_name,
					'url'   => get_author_posts_url( $author->ID, $author->user_nicename ),
				),
				'image'            => $image,
				'inLanguage'       => get_bloginfo( 'language' ),
			),
			static fn( $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * Person an author archive is about.
	 *
	 * Only fields of the user row, which core already loaded as the queried object: no user meta, so no query.
	 *
	 * @param \WP_User $user Author of the archive.
	 * @return array<string, mixed>
	 */
	private function profile( \WP_User $user ): array {
		$url = get_author_posts_url( $user->ID, $user->user_nicename );

		return array(
			'@type' => 'Person',
			'@id'   => $url . '#author',
			'name'  => self::text( $user->display_name ),
			'url'   => $url,
		);
	}

	/**
	 * BreadcrumbList from the items the breadcrumb block rendered, or null without a block.
	 *
	 * The last item has no URL in the block (it is the current page): it gets the page URL.
	 *
	 * @param string $url URL of the page.
	 * @return array<string, mixed>|null
	 */
	private function breadcrumb_list( string $url ): ?array {
		if ( null === $this->breadcrumbs || count( $this->breadcrumbs ) < 2 ) {
			return null;
		}

		$elements = array();
		$last     = count( $this->breadcrumbs ) - 1;

		foreach ( $this->breadcrumbs as $index => $item ) {
			$element = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => self::text( $item['label'] ),
			);

			$item_url = $item['url'] ?? ( $index === $last ? $url : '' );
			if ( '' !== $item_url ) {
				$element['item'] = $item_url;
			}

			$elements[] = $element;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $elements,
		);
	}

	/**
	 * Schema.org type of the page.
	 *
	 * @param string[] $keys Context keys, most specific first.
	 * @return string
	 */
	private function page_type( array $keys ): string {
		return match ( end( $keys ) ) {
			'archive', 'term', 'date' => 'CollectionPage',
			'author'                  => 'ProfilePage',
			'home'                    => is_front_page() && is_home() ? 'CollectionPage' : 'WebPage',
			default                   => 'WebPage',
		};
	}

	/**
	 * ImageObject of an attachment, or null when it is not an image.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $id            @id of the node.
	 * @return array<string, mixed>|null
	 */
	private function image( int $attachment_id, string $id ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$source = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( false === $source ) {
			return null;
		}

		return array(
			'@type'  => 'ImageObject',
			'@id'    => $id,
			'url'    => $source[0],
			'width'  => (int) $source[1],
			'height' => (int) $source[2],
		);
	}

	/**
	 * Plain text without tags and entities.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function text( string $value ): string {
		return Template::plain( $value );
	}
}
