<?php
/**
 * AI agent: abilities the agent reads with, and the proposals it makes.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `easyrankly` ability category, the agent's abilities, its proposals and their REST routes.
 *
 * The Abilities API builds its registry lazily, the first time something asks for an
 * ability (REST, AI Client, admin), so nothing here runs on a normal frontend request.
 */
final class Agent {

	/**
	 * Ability category slug, also the namespace of every ability name.
	 */
	public const CATEGORY = 'easyrankly';

	/**
	 * Hooks the category and ability registration.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities::class, 'register_abilities' ) );
		add_action( 'wp_abilities_api_init', array( Actions::class, 'register_abilities' ) );

		( new Proposals() )->register();
		( new Rest() )->register();
	}

	/**
	 * Registers the ability category.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'EasyRankly SEO', 'easyrankly' ),
				'description' => __( 'Read the SEO data of the site and its content, and propose changes for a person to approve.', 'easyrankly' ),
			)
		);
	}
}
