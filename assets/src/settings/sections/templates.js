import {
	Card,
	CardBody,
	CardHeader,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
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

/**
 * Title and description fields of one context.
 *
 * @param {Object}                 props              Props.
 * @param {string}                 props.contextKey   Context key.
 * @param {string}                 props.label        Context label.
 * @param {Object}                 props.templates    Templates being edited.
 * @param {Object}                 props.placeholders Templates used when a field is empty.
 * @param {(next: Object) => void} props.onChange     Receives the edited templates.
 */
function ContextFields( {
	contextKey,
	label,
	templates,
	placeholders,
	onChange,
} ) {
	const current = templates[ contextKey ] ?? {};
	const fallback = placeholders?.[ contextKey ] ?? {};
	const set = ( field ) => ( value ) =>
		onChange( {
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
				placeholder={ fallback.title ?? '' }
				value={ current.title ?? '' }
				onChange={ set( 'title' ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Meta description', 'easyrankly' ) }
				placeholder={ fallback.description ?? '' }
				value={ current.description ?? '' }
				onChange={ set( 'description' ) }
			/>
		</fieldset>
	);
}

export default function Templates( { settings, update } ) {
	const typeContexts = useTypeContexts();
	const [ language, setLanguage ] = useState( '' );
	const languages = Object.entries( settings.languages ?? {} );
	const base = settings.templates ?? {};
	const byLanguage = settings.language_templates ?? {};

	// With a language chosen, its own templates are edited and the general ones show as placeholders.
	const templates = language ? ( byLanguage[ language ] ?? {} ) : base;
	const placeholders = language ? base : null;
	const onChange = ( next ) =>
		language
			? update( 'language_templates', {
					...byLanguage,
					[ language ]: next,
				} )
			: update( 'templates', next );

	const fields = ( [ key, label ] ) => (
		<ContextFields
			key={ key }
			contextKey={ key }
			label={ label }
			templates={ templates }
			placeholders={ placeholders }
			onChange={ onChange }
		/>
	);

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
				{ languages.length > 1 && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Language', 'easyrankly' ) }
						help={ __(
							'Templates of one language are used on its pages. Empty fields use the templates of all languages.',
							'easyrankly'
						) }
						value={ language }
						options={ [
							{
								value: '',
								label: __( 'All languages', 'easyrankly' ),
							},
							...languages.map( ( [ slug, item ] ) => ( {
								value: slug,
								label: item.name,
							} ) ),
						] }
						onChange={ setLanguage }
					/>
				) }
				{ CONTEXTS.map( fields ) }
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
						{ typeContexts.map( fields ) }
					</PanelBody>
				) }
			</CardBody>
		</Card>
	);
}
