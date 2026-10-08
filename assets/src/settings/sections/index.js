/**
 * Sections of the settings screen, in display order.
 *
 * Each section receives `settings` (the whole option) and `update( key, value )`.
 */
import Breadcrumbs from './breadcrumbs';
import General from './general';
import Identity from './identity';
import Languages from './languages';
import Robots from './robots';
import Social from './social';
import Templates from './templates';

export default [
	General,
	Templates,
	Robots,
	Social,
	Identity,
	Breadcrumbs,
	Languages,
];
