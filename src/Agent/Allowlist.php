<?php
/**
 * What the agent may propose, checked without the model.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic gate every proposal passes, at creation and again at acceptance.
 *
 * Content, Search Console queries and pages the agent reads are untrusted data and may
 * carry instructions. Whatever the model returns, a proposal exists only if:
 * - its ability is in ACTIONS (never custom code, settings, robots.txt, deletions,
 *   publishing, users or roles);
 * - its input is valid on the ability's schema;
 * - its texts carry no markup and no links to other sites;
 * - a redirect goes from a path of this site to another path of this site;
 * - fewer than DAILY_LIMIT proposals were created today.
 */
final class Allowlist {

	/**
	 * Abilities a proposal may run. Adding one is a product decision: see CLAUDE.md, "Agente AI".
	 */
	public const ACTIONS = array(
		Actions::POST_SEO,
		Actions::IMAGE_ALT,
		Actions::REDIRECT,
	);

	/**
	 * Most proposals created per day, whatever triggers them.
	 */
	public const DAILY_LIMIT = 50;

	/**
	 * Validates an action and its input.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Raw input.
	 * @return array<string, mixed>|\WP_Error Normalized input.
	 */
	public static function validate( string $name, $input ) {
		if ( ! in_array( $name, self::ACTIONS, true ) ) {
			return new \WP_Error( 'easyrankly_not_allowed', __( 'The agent cannot propose this action.', 'easyrankly' ) );
		}

		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			return new \WP_Error( 'easyrankly_unknown_action', __( 'This action is not supported.', 'easyrankly' ) );
		}

		$input = $ability->normalize_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$valid = $ability->validate_input( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$input = is_array( $input ) ? $input : array();
		foreach ( $input as $field => $value ) {
			if ( is_string( $value ) ) {
				$text = self::check_text( (string) $field, $value );
				if ( is_wp_error( $text ) ) {
					return $text;
				}
			}
		}

		if ( Actions::REDIRECT === $name ) {
			$redirect = self::check_redirect( $input );
			if ( is_wp_error( $redirect ) ) {
				return $redirect;
			}
		}

		return $input;
	}

	/**
	 * Whether today's proposals already reached the daily limit (site time zone).
	 *
	 * @return bool
	 */
	public static function daily_limit_reached(): bool {
		$midnight = new \DateTimeImmutable( 'today', wp_timezone() );
		$query    = new \WP_Query(
			array(
				'post_type'              => Proposals::POST_TYPE,
				'post_status'            => array_values( Proposals::STATUSES ),
				'date_query'             => array(
					array(
						'after'     => $midnight->format( 'Y-m-d H:i:s' ),
						'inclusive' => true,
					),
				),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $query->found_posts >= self::DAILY_LIMIT;
	}

	/**
	 * A proposed redirect: both ends are paths on this site, and it redirects somewhere.
	 *
	 * An empty target (turning a redirect off) is how "Undo" works, never something to propose.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|\WP_Error
	 */
	private static function check_redirect( array $input ) {
		foreach ( array( 'source', 'target' ) as $field ) {
			$value = is_string( $input[ $field ] ?? null ) ? $input[ $field ] : '';
			if ( ! str_starts_with( $value, '/' ) || str_starts_with( $value, '//' ) ) {
				/* translators: %s: field name. */
				return new \WP_Error( 'easyrankly_redirect_external', sprintf( __( 'The field %s must be a path on this site.', 'easyrankly' ), $field ) );
			}
		}

		return true;
	}

	/**
	 * A text value: no markup, no control characters, no links to other sites.
	 *
	 * @param string $field Field name, for the error message.
	 * @param string $value Value.
	 * @return true|\WP_Error
	 */
	private static function check_text( string $field, string $value ) {
		if ( wp_strip_all_tags( $value ) !== $value || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			/* translators: %s: field name. */
			return new \WP_Error( 'easyrankly_markup', sprintf( __( 'The field %s contains markup.', 'easyrankly' ), $field ) );
		}

		$site = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		preg_match_all( '#\b(?:https?://|www\.)[^\s"\'<>]+#i', $value, $matches );

		foreach ( $matches[0] as $url ) {
			$url  = 0 === stripos( $url, 'www.' ) ? 'http://' . $url : $url;
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( $host !== $site ) {
				/* translators: %s: field name. */
				return new \WP_Error( 'easyrankly_external_domain', sprintf( __( 'The field %s links to another site.', 'easyrankly' ), $field ) );
			}
		}

		return true;
	}
}
