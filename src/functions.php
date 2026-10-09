<?php
/**
 * Public functions other plugins may call. Each one is a compatibility promise.
 *
 * @package EasyRankly
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates a pending proposal for a person to accept, edit or reject in the AI agent dashboard.
 *
 * The proposal passes the same checks as the agent's own: the action must be allowed (one of the
 * agent's, or registered with the `easyrankly_agent_actions` filter, never destructive), the input
 * must be valid on the ability's schema, texts carry no markup and no links to other sites, and
 * the daily limit of proposals applies. Older pending proposals of the same action on the same
 * post are superseded. Nothing changes on the site until a person accepts the proposal; the
 * ability then runs with that person's permissions, so creating one needs no capability.
 *
 * Call it after `init`.
 *
 * @since 3.0.0
 *
 * @param array<string, mixed> $args {
 *     The proposal.
 *
 *     @type string $ability    Name of the ability that applies the proposal. Required.
 *     @type array  $input      Input of the ability. Required.
 *     @type string $title      Short summary shown in the dashboard. Required.
 *     @type string $motivation Optional. Why the change helps, in plain text.
 *     @type string $evidence   Optional. Data that supports it, in plain text.
 *     @type float  $confidence Optional. From 0 to 1. Default 0.
 *     @type int    $object     Optional. ID of the post the proposal changes, when the action's
 *                              definition cannot tell it from the input.
 * }
 * @return int|WP_Error ID of the proposal, or why it was not created.
 */
function easyrankly_create_proposal( array $args ) {
	if ( ! did_action( 'init' ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Proposals can be created only after the init action.', 'easyrankly' ), '3.0.0' );
		return new WP_Error( 'easyrankly_too_early', __( 'Proposals can be created only after the init action.', 'easyrankly' ) );
	}

	if ( ! is_string( $args['ability'] ?? null ) || ! is_array( $args['input'] ?? null ) || ! is_string( $args['title'] ?? null ) ) {
		return new WP_Error( 'easyrankly_proposal_args', __( 'A proposal needs an ability, its input and a summary.', 'easyrankly' ) );
	}

	$proposal = array(
		'ability' => $args['ability'],
		'input'   => $args['input'],
		'title'   => $args['title'],
	);
	foreach ( array( 'motivation', 'evidence' ) as $key ) {
		if ( is_string( $args[ $key ] ?? null ) ) {
			$proposal[ $key ] = $args[ $key ];
		}
	}
	if ( is_numeric( $args['confidence'] ?? null ) ) {
		$proposal['confidence'] = (float) $args['confidence'];
	}
	if ( is_numeric( $args['object'] ?? null ) ) {
		$proposal['object'] = max( 0, (int) $args['object'] );
	}

	return EasyRankly\Agent\Proposals::create( $proposal );
}
