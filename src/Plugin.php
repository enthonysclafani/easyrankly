<?php
/**
 * Plugin bootstrap.
 *
 * @package EasyRankly
 */

namespace EasyRankly;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every feature of the plugin.
 *
 * Each feature lives in its own directory under src/ and exposes register().
 * Add one line here per feature; nothing else belongs in this class.
 */
final class Plugin {

	/**
	 * Registers the hooks of every feature.
	 */
	public function register(): void {
		( new Settings\Settings() )->register();
		( new Meta\Meta() )->register();
		( new Titles\Titles() )->register();
		( new Robots\Robots() )->register();
		( new Canonical\Canonical() )->register();
		( new Social\Social() )->register();
		( new Schema\Schema() )->register();
		( new Breadcrumbs\Breadcrumbs() )->register();
		( new RobotsTxt\RobotsTxt() )->register();
		( new Sitemap\Sitemap() )->register();

		if ( is_admin() ) {
			( new Settings\Admin\Page() )->register();
			( new Meta\Admin\EditorPanel() )->register();
			( new Meta\Admin\TermFields() )->register();
		}
	}
}
