import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	CardHeader,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Public post types that have at least one public taxonomy, with those taxonomies.
 *
 * @return {Array} Items { slug, name, taxonomies: [ { slug, name } ] }.
 */
function usePostTypesWithTaxonomies() {
	const [ items, setItems ] = useState( [] );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/wp/v2/types?context=edit' } ),
			apiFetch( { path: '/wp/v2/taxonomies?context=edit' } ),
		] )
			.then( ( [ types, taxonomies ] ) => {
				const publicTaxonomies = Object.values( taxonomies ).filter(
					( tax ) => tax.visibility?.publicly_queryable
				);
				setItems(
					Object.values( types )
						.filter( ( type ) => type.viewable )
						.map( ( type ) => ( {
							slug: type.slug,
							name: type.name,
							taxonomies: publicTaxonomies.filter( ( tax ) =>
								tax.types.includes( type.slug )
							),
						} ) )
						.filter( ( type ) => type.taxonomies.length > 0 )
				);
			} )
			.catch( () => setItems( [] ) );
	}, [] );

	return items;
}

export default function Breadcrumbs( { settings, update } ) {
	const postTypes = usePostTypesWithTaxonomies();
	const map = { ...( settings.breadcrumb_taxonomies ?? {} ) };

	const setTaxonomy = ( postType ) => ( taxonomy ) => {
		const next = { ...map };
		if ( taxonomy ) {
			next[ postType ] = taxonomy;
		} else {
			delete next[ postType ];
		}
		update( 'breadcrumb_taxonomies', next );
	};

	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>{ __( 'Breadcrumbs', 'easyrankly' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'These options change the WordPress Breadcrumbs block. The structured data follows the trail the block shows.',
						'easyrankly'
					) }
				</p>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Label of the first item', 'easyrankly' ) }
					help={ __(
						'Empty keeps the WordPress label (Home).',
						'easyrankly'
					) }
					value={ settings.breadcrumb_home_label ?? '' }
					onChange={ ( value ) =>
						update( 'breadcrumb_home_label', value )
					}
				/>
				{ postTypes.map( ( type ) => (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						key={ type.slug }
						label={ type.name }
						value={ map[ type.slug ] ?? '' }
						options={ [
							{
								value: '',
								label: __(
									'First taxonomy with terms',
									'easyrankly'
								),
							},
							...type.taxonomies.map( ( tax ) => ( {
								value: tax.slug,
								label: tax.name,
							} ) ),
						] }
						onChange={ setTaxonomy( type.slug ) }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}
