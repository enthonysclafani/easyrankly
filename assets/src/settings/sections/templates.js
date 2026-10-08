import {
	Card,
	CardBody,
	CardHeader,
	PanelBody,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import useTypeContexts from '../use-type-contexts';

const CONTEXTS = [
	[ 'home', __( 'Home page', 'easyrankly' ) ],
	[ 'single', __( 'Single content (all types)', 'easyrankly' ) ],
	[ 'archive', __( 'Post type archives', 'easyrankly' ) ],
	[ 'term', __( 'Category, tag and term archives', 'easyrankly' ) ],
	[ 'author', __( 'Author archives', 'easyrankly' ) ],
	[ 'date', __( 'Date archives', 'easyrankly' ) ],
	[ 'search', __( 'Search results', 'easyrankly' ) ],
	[ '404', __( 'Page not found', 'easyrankly' ) ],
];

const VARIABLES =
	'{{title}} {{sep}} {{site_name}} {{tagline}} {{page}} {{excerpt}} {{term_description}} {{author}} {{post_type}} {{category}} {{date}} {{search_query}}';

function ContextFields( { contextKey, label, templates, update } ) {
	const current = templates[ contextKey ] ?? {};
	const set = ( field ) => ( value ) =>
		update( 'templates', {
			...templates,
			[ contextKey ]: {
				title: '',
				description: '',
				...current,
				[ field ]: value,
			},
		} );

	return (
		<fieldset className="easyrankly-settings__context">
			<legend>
				<strong>{ label }</strong>
			</legend>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Title', 'easyrankly' ) }
				value={ current.title ?? '' }
				onChange={ set( 'title' ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Meta description', 'easyrankly' ) }
				value={ current.description ?? '' }
				onChange={ set( 'description' ) }
			/>
		</fieldset>
	);
}

export default function Templates( { settings, update } ) {
	const typeContexts = useTypeContexts();
	const templates = settings.templates ?? {};

	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>{ __( 'Titles and descriptions', 'easyrankly' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Empty title: WordPress builds it. Empty description: none is printed. A value set on a single post or term always wins.',
						'easyrankly'
					) }
				</p>
				<p>
					{ __( 'Variables:', 'easyrankly' ) }{ ' ' }
					<code>{ VARIABLES }</code>
				</p>
				{ CONTEXTS.map( ( [ key, label ] ) => (
					<ContextFields
						key={ key }
						contextKey={ key }
						label={ label }
						templates={ templates }
						update={ update }
					/>
				) ) }
				{ typeContexts.length > 0 && (
					<PanelBody
						title={ __(
							'Per content type and taxonomy',
							'easyrankly'
						) }
						initialOpen={ false }
					>
						<p>
							{ __(
								'Leave empty to use the general template above.',
								'easyrankly'
							) }
						</p>
						{ typeContexts.map( ( [ key, label ] ) => (
							<ContextFields
								key={ key }
								contextKey={ key }
								label={ label }
								templates={ templates }
								update={ update }
							/>
						) ) }
					</PanelBody>
				) }
			</CardBody>
		</Card>
	);
}
