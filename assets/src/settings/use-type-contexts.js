import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Public post types and taxonomies, for the per-type templates.
 *
 * @return {Array} Extra contexts as [ key, label ] pairs.
 */
export default function useTypeContexts() {
	const [ contexts, setContexts ] = useState( [] );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/wp/v2/types?context=edit' } ),
			apiFetch( { path: '/wp/v2/taxonomies?context=edit' } ),
		] )
			.then( ( [ types, taxonomies ] ) => {
				const extra = [];
				Object.values( types )
					.filter( ( type ) => type.viewable )
					.forEach( ( type ) => {
						extra.push( [
							`single-${ type.slug }`,
							sprintf(
								/* translators: %s: post type name. */
								__( 'Single: %s', 'easyrankly' ),
								type.name
							),
						] );
						if ( type.has_archive ) {
							extra.push( [
								`archive-${ type.slug }`,
								sprintf(
									/* translators: %s: post type name. */
									__( 'Archive: %s', 'easyrankly' ),
									type.name
								),
							] );
						}
					} );
				Object.values( taxonomies )
					.filter( ( tax ) => tax.visibility?.publicly_queryable )
					.forEach( ( tax ) =>
						extra.push( [
							`term-${ tax.slug }`,
							sprintf(
								/* translators: %s: taxonomy name. */
								__( 'Taxonomy: %s', 'easyrankly' ),
								tax.name
							),
						] )
					);
				setContexts( extra );
			} )
			.catch( () => setContexts( [] ) );
	}, [] );

	return contexts;
}
