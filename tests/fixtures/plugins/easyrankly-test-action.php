<?php
/**
 * Plugin Name: EasyRankly test action
 * Description: Test fixture. Registers proposable actions the way another plugin would, using only the
 *              documented extension points of EasyRankly.
 *
 * @package EasyRankly
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'easyrankly-test',
			array(
				'label'       => 'EasyRankly test',
				'description' => 'Abilities of the test fixture.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		$args = array(
			'label'               => 'Set the excerpt of a post',
			'description'         => 'Sets the excerpt of a post.',
			'category'            => 'easyrankly-test',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'excerpt' => array(
						'type'      => 'string',
						'maxLength' => 5000,
					),
				),
				'required'             => array( 'id', 'excerpt' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( $input ) {
				$updated = wp_update_post(
					array(
						'ID'           => (int) $input['id'],
						'post_excerpt' => (string) $input['excerpt'],
					),
					true
				);
				return is_wp_error( $updated ) ? $updated : array( 'id' => $updated );
			},
			'permission_callback' => static fn( $input ): bool => current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);

		wp_register_ability( 'easyrankly-test/set-excerpt', $args );

		// The same action, annotated as destructive: EasyRankly must refuse it.
		$args['meta']['annotations']['destructive'] = true;
		wp_register_ability( 'easyrankly-test/replace-excerpt', $args );

		// Without annotations: EasyRankly must refuse it too.
		unset( $args['meta'] );
		wp_register_ability( 'easyrankly-test/clear-excerpt', $args );
	}
);

add_filter(
	'easyrankly_agent_actions',
	static function ( $actions ) {
		$definition = array(
			'snapshot' => static function ( array $input ) {
				$post = get_post( (int) ( $input['id'] ?? 0 ) );
				if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
					return new WP_Error( 'easyrankly_test_not_found', 'No post with this ID.' );
				}
				return array(
					'id'      => $post->ID,
					'excerpt' => $post->post_excerpt,
				);
			},
			'fields'   => array(
				'excerpt' => array(
					'label' => 'Excerpt',
					'type'  => 'long_text',
				),
			),
		);

		$actions['easyrankly-test/set-excerpt']     = $definition;
		$actions['easyrankly-test/replace-excerpt'] = $definition;
		$actions['easyrankly-test/clear-excerpt']   = $definition;

		// The agent's own actions cannot be replaced.
		$actions['easyrankly/update-post-seo'] = array(
			'snapshot' => static fn() => array(),
		);

		return $actions;
	}
);
