/**
 * Admin entry points, listed once for both `npm run build` and `npm run start`.
 * Each one is assets/src/<name>/index.js, built to build/<name>.js with its .asset.php.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const entries = [ 'editor', 'languages', 'language-switcher', 'media-field' ];

module.exports = {
	...defaultConfig,
	entry: Object.fromEntries(
		entries.map( ( name ) => [ name, `./assets/src/${ name }/index.js` ] )
	),
};
