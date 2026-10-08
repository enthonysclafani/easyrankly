/**
 * Sections of the settings screen, in display order.
 *
 * Each section receives `settings` (the whole option) and `update( key, value )`.
 */
import General from './general';
import Robots from './robots';
import Templates from './templates';

export default [ General, Templates, Robots ];
