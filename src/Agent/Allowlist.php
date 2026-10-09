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
 *   publishing, users or roles) or another plugin registered it with the
 *   `easyrankly_agent_actions` filter;
 * - the ability is not annotated as destructive;
 * - its input is valid on the ability's schema;
 * - its texts carry no markup and no links to other sites;
 * - a redirect goes from a path of this site to another path of this site;
 * - fewer than DAILY_LIMIT proposals were created today.
 */
final class Allowlist {

	/**
	 * Abilities the agent itself may propose. Adding one is a product decision: see CLAUDE.md, "Agente AI".
	 * Other plugins add theirs with the `easyrankly_agent_actions` filter (see actions()).
	 */
	public const ACTIONS = array(
		Actions::POST_SEO,
		Actions::IMAGE_ALT,
		Actions::REDIRECT,
	);

	/**
	 * How the preview of the differences shows a field: one line, or a text compared line by line.
	 */
	public const FIELD_TYPES = array( 'text', 'long_text' );

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
		if ( null === self::definition( $name ) ) {
			return new \WP_Error( 'easyrankly_not_allowed', __( 'The agent cannot propose this action.', 'easyrankly' ) );
		}

		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			return new \WP_Error( 'easyrankly_unknown_action', __( 'This action is not supported.', 'easyrankly' ) );
		}

		// Only an explicit `false` passes: an ability that does not say may delete or lose data.
		$annotations = $ability->get_meta_item( 'annotations' );
		if ( ! is_array( $annotations ) || false !== ( $annotations['destructive'] ?? null ) ) {
			return new \WP_Error( 'easyrankly_destructive', __( 'The agent cannot propose destructive actions.', 'easyrankly' ) );
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
	 * Every action a proposal may run, with its definition: the agent's own, then those other plugins register.
	 *
	 * @return array<string, array{snapshot: callable, object: callable, fields: array<string, array{label: string, type: string}>}>
	 */
	public static function actions(): array {
		$actions = array();
		foreach ( self::ACTIONS as $name ) {
			$actions[ $name ] = array(
				'snapshot' => static fn( array $input ) => Actions::snapshot( $name, $input ),
				'object'   => array( Actions::class, 'object_id' ),
				'fields'   => array_map(
					static fn( string $label ): array => array(
						'label' => $label,
						'type'  => 'text',
					),
					Actions::labels()
				),
			);
		}

		/**
		 * Filters the actions other plugins let proposals run.
		 *
		 * Each key is the name of an ability registered with `wp_register_ability()` and annotated
		 * with `'destructive' => false`. A proposal of such an action passes the same checks as the
		 * agent's own: the ability's input schema, texts without markup or links to other sites,
		 * the daily limit, and the fingerprint of the values it replaces. When a person accepts it,
		 * the ability runs with that person's permissions; "Undo" runs it again with the values
		 * returned by `snapshot`, so the ability must accept them as input.
		 *
		 * The agent's own actions cannot be replaced or removed.
		 *
		 * @since 3.0.0
		 *
		 * @param array<string, array{snapshot: callable, object?: callable, fields?: array<string, array{label?: string, type?: string}>}> $actions {
		 *     Definitions by ability name. Default empty array.
		 *
		 *     @type callable $snapshot Receives the validated input and returns the current values of the
		 *                              fields it would change, in the same shape as the input, or a WP_Error
		 *                              when the object does not exist.
		 *     @type callable $object   Optional. Receives the validated input and returns the ID of the post
		 *                              the proposal changes, or 0. Default: the input's `id`.
		 *     @type array    $fields   Optional. For each input field, its `label` and its `type` in the
		 *                              preview of the differences: `text` (default) or `long_text`, which
		 *                              compares the values line by line.
		 * }
		 */
		$registered = apply_filters( 'easyrankly_agent_actions', array() );

		foreach ( is_array( $registered ) ? $registered : array() as $name => $definition ) {
			if ( ! is_string( $name ) || isset( $actions[ $name ] ) ) {
				continue;
			}

			$definition = is_array( $definition ) ? self::definition_from( $definition ) : null;
			if ( null === $definition ) {
				_doing_it_wrong( 'easyrankly_agent_actions', esc_html( sprintf( 'The action %s needs a callable "snapshot", and "object" must be callable when given.', $name ) ), '3.0.0' );
				continue;
			}

			$actions[ $name ] = $definition;
		}

		return $actions;
	}

	/**
	 * Definition of an action a proposal may run.
	 *
	 * @param string $name Ability name.
	 * @return array{snapshot: callable, object: callable, fields: array<string, array{label: string, type: string}>}|null Null when the action is not allowed.
	 */
	public static function definition( string $name ): ?array {
		return self::actions()[ $name ] ?? null;
	}

	/**
	 * Labels of the fields of an action, for the preview and the memory.
	 *
	 * @param string $name Ability name.
	 * @return array<string, string> Field => label.
	 */
	public static function labels( string $name ): array {
		return array_map( static fn( array $field ): string => $field['label'], self::definition( $name )['fields'] ?? array() );
	}

	/**
	 * A definition registered by another plugin, normalized; null when it cannot work.
	 *
	 * @param array<mixed> $definition Raw definition.
	 * @return array{snapshot: callable, object: callable, fields: array<string, array{label: string, type: string}>}|null
	 */
	private static function definition_from( array $definition ): ?array {
		$snapshot = $definition['snapshot'] ?? null;
		$object   = $definition['object'] ?? array( Actions::class, 'object_id' );
		if ( ! is_callable( $snapshot ) || ! is_callable( $object ) ) {
			return null;
		}

		$fields = array();
		foreach ( is_array( $definition['fields'] ?? null ) ? $definition['fields'] : array() as $field => $args ) {
			if ( ! is_string( $field ) || ! is_array( $args ) ) {
				continue;
			}
			$label            = is_string( $args['label'] ?? null ) ? sanitize_text_field( $args['label'] ) : '';
			$type             = $args['type'] ?? 'text';
			$fields[ $field ] = array(
				'label' => '' !== $label ? $label : $field,
				'type'  => in_array( $type, self::FIELD_TYPES, true ) ? $type : 'text',
			);
		}

		return array(
			'snapshot' => $snapshot,
			'object'   => $object,
			'fields'   => $fields,
		);
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
