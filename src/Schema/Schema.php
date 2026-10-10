<?php
/**
 * Schema.org structured data.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Schema;

use EasyRankly\Canonical\Canonical;
use EasyRankly\Context\Context;
use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use EasyRankly\Titles\Template;
use EasyRankly\Titles\Titles;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one JSON-LD graph per page: the site's Organization (or local business) or Person, WebSite,
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
				'name'        => Template::plain( (string) get_bloginfo( 'name' ) ),
				'description' => Template::plain( (string) get_bloginfo( 'description' ) ),
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
		$title      = Titles::title();
		$page       = array(
			'@type'       => $this->page_type( $keys ),
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => '' !== $title ? $title : Template::plain( wp_get_document_title() ),
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
	 * The Organization, local business or Person the site represents.
	 *
	 * A local business is an Organization of a more specific type (Restaurant, Dentist…) with
	 * address, phone, coordinates and opening hours; it keeps the #organization @id.
	 *
	 * @param string $home Home URL.
	 * @return array<string, mixed>
	 */
	private function identity( string $home ): array {
		$identity  = (string) Settings::value( 'identity_type' );
		$is_person = 'person' === $identity;
		$name      = (string) Settings::value( 'identity_name' );
		$same_as   = Settings::value( 'same_as' );
		$logo      = Settings::image( 'identity_logo' );
		$business  = 'local_business' === $identity ? (array) Settings::value( 'local_business' ) : null;

		$node = array(
			'@type' => $is_person ? 'Person' : 'Organization',
			'@id'   => $home . ( $is_person ? '#person' : '#organization' ),
			'name'  => '' !== $name ? $name : Template::plain( (string) get_bloginfo( 'name' ) ),
			'url'   => $home,
		);

		if ( null !== $business ) {
			$type          = is_string( $business['type'] ?? null ) ? $business['type'] : '';
			$node['@type'] = 1 === preg_match( '/' . Settings::BUSINESS_TYPE_PATTERN . '/', $type ) ? $type : 'LocalBusiness';
		}
		if ( null !== $logo ) {
			// Copied in the settings: no attachment to load on every page.
			$node[ $is_person ? 'image' : 'logo' ] = self::image_object( $logo, $home . ( $is_person ? '#personimage' : '#logo' ) );
			if ( null !== $business ) {
				$node['image'] = array( '@id' => $home . '#logo' );
			}
		}
		if ( is_array( $same_as ) && array() !== $same_as ) {
			$node['sameAs'] = array_values( $same_as );
		}

		return null === $business ? $node : $node + self::business_details( $business );
	}

	/**
	 * Address, phone, coordinates and opening hours of a local business, without empty fields.
	 *
	 * @param array<mixed> $business The local_business setting.
	 * @return array<string, mixed>
	 */
	private static function business_details( array $business ): array {
		$text = static fn( string $key ): string => is_string( $business[ $key ] ?? null ) ? trim( $business[ $key ] ) : '';

		$address = array_filter(
			array(
				'streetAddress'   => $text( 'street_address' ),
				'addressLocality' => $text( 'locality' ),
				'addressRegion'   => $text( 'region' ),
				'postalCode'      => $text( 'postal_code' ),
				'addressCountry'  => $text( 'country' ),
			)
		);

		$details = array(
			'address'   => array() === $address ? null : array( '@type' => 'PostalAddress' ) + $address,
			'telephone' => '' !== $text( 'telephone' ) ? $text( 'telephone' ) : null,
		);

		if ( is_numeric( $business['latitude'] ?? null ) && is_numeric( $business['longitude'] ?? null ) ) {
			$details['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $business['latitude'],
				'longitude' => (float) $business['longitude'],
			);
		}

		// One specification per time range, listing every day that has it.
		$ranges = array();
		$hours  = is_array( $business['hours'] ?? null ) ? $business['hours'] : array();
		foreach ( Settings::WEEKDAYS as $day ) {
			foreach ( is_array( $hours[ $day ] ?? null ) ? $hours[ $day ] : array() as $range ) {
				if ( is_string( $range ) && 1 === preg_match( '/^(\d\d:\d\d)-(\d\d:\d\d)$/', $range ) ) {
					$ranges[ $range ][] = $day;
				}
			}
		}
		foreach ( $ranges as $range => $days ) {
			list( $opens, $closes )                 = explode( '-', (string) $range );
			$details['openingHoursSpecification'][] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $days,
				'opens'     => $opens,
				'closes'    => $closes,
			);
		}

		return array_filter( $details, static fn( $value ): bool => null !== $value );
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
				'headline'         => Template::truncate( Template::plain( Template::post_title( $post ) ), 110 ),
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
			'name'  => Template::plain( $user->display_name ),
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
				'name'     => Template::plain( $item['label'] ),
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
		$image = Meta::image( $attachment_id );

		return null === $image ? null : self::image_object( $image, $id );
	}

	/**
	 * ImageObject node of an image.
	 *
	 * @param array{url: string, width: int, height: int, alt: string} $image Image.
	 * @param string                                                   $id    @id of the node.
	 * @return array<string, mixed>
	 */
	private static function image_object( array $image, string $id ): array {
		return array(
			'@type'  => 'ImageObject',
			'@id'    => $id,
			'url'    => $image['url'],
			'width'  => $image['width'],
			'height' => $image['height'],
		);
	}
}
