<?php
/**
 * Plugin Name:       EasyRankly
 * Plugin URI:        https://easyrankly.com
 * Description:       Lightweight SEO built only on native WordPress APIs: metadata, redirects, sitemaps, custom code and multilingual.
 * Version:           3.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            EasyRankly
 * Author URI:        https://easyrankly.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyrankly
 *
 * @package EasyRankly
 */

namespace EasyRankly;

defined( 'ABSPATH' ) || exit;

const VERSION     = '3.0.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/src/autoload.php';

( new Plugin() )->register();
