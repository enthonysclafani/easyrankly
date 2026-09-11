<?php
/**
 * Minimal WooCommerce stand-ins for the Product-schema mapper.
 *
 * Deliberately named .inc so PHPUnit's `suffix=".php"` directory scan does NOT
 * auto-load it. It is required from the test's set_up(), which runs inside a
 * separate process, because the plugin detects WooCommerce with
 * `function_exists( 'wc_get_product' )`: defining that function in the shared
 * process would make every other test see WooCommerce as active.
 */

if ( ! class_exists( 'WC_DateTime' ) ) {
	class WC_DateTime extends DateTime {
		public function date( string $format ): string {
			return $this->format( $format );
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		/** @var array<string,mixed> */
		public array $stub = array();

		public function __construct( array $stub = array() ) {
			$this->stub = $stub;
		}

		public function get_name(): string {
			return (string) ( $this->stub['name'] ?? '' );
		}

		public function get_short_description(): string {
			return (string) ( $this->stub['short_description'] ?? '' );
		}

		public function get_description(): string {
			return (string) ( $this->stub['description'] ?? '' );
		}

		public function get_sku(): string {
			return (string) ( $this->stub['sku'] ?? '' );
		}

		public function get_global_unique_id(): string {
			return (string) ( $this->stub['global_unique_id'] ?? '' );
		}

		public function get_price(): string {
			return (string) ( $this->stub['price'] ?? '' );
		}

		public function is_in_stock(): bool {
			return (bool) ( $this->stub['in_stock'] ?? true );
		}

		public function is_on_sale(): bool {
			return (bool) ( $this->stub['on_sale'] ?? false );
		}

		public function get_date_on_sale_to() {
			return $this->stub['sale_to'] ?? null;
		}

		public function get_average_rating(): string {
			return (string) ( $this->stub['average_rating'] ?? '0' );
		}

		public function get_rating_count(): int {
			return (int) ( $this->stub['rating_count'] ?? 0 );
		}
	}
}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable extends WC_Product {
		/** @return array<string,array<int|string,string>> */
		public function get_variation_prices( bool $for_display = true ): array {
			unset( $for_display );
			return (array) ( $this->stub['variation_prices'] ?? array() );
		}

		/** @return array<int,int> */
		public function get_visible_children(): array {
			return (array) ( $this->stub['visible_children'] ?? array() );
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/** @return WC_Product|false */
	function wc_get_product( int $post_id ) {
		$stub = get_post_meta( $post_id, '_erankly_test_wc_product', true );

		if ( ! is_array( $stub ) ) {
			return false;
		}

		return ! empty( $stub['variable'] )
			? new WC_Product_Variable( $stub )
			: new WC_Product( $stub );
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return 'EUR';
	}
}
