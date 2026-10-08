/**
 * Language panel in the block editor: sets the language of the post and links its
 * translations through the `easyrankly/v1/translations` routes. Changes apply at once,
 * without saving the post.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ComboboxControl,
	Flex,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/easyrankly/v1/translations/';
const languages = window.easyrankly?.languages ?? [];

/**
 * Post IDs of a translations response, keyed by language.
 *
 * @param {Object} translations Translations from the API.
 * @return {Object} Language => post ID.
 */
function ids( translations ) {
	return Object.fromEntries(
		Object.entries( translations ).map( ( [ language, post ] ) => [
			language,
			post.id,
		] )
	);
}

/**
 * Search field to pick an existing post of a type and language.
 *
 * @param {Object}               props          Props.
 * @param {string}               props.postType Post type.
 * @param {string}               props.language Language slug.
 * @param {(id: number) => void} props.onPick   Receives the chosen post ID.
 */
function ExistingPost( { postType, language, onPick } ) {
	const [ search, setSearch ] = useState( '' );
	const [ options, setOptions ] = useState( [] );

	useEffect( () => {
		const timer = setTimeout( () => {
			apiFetch( {
				path: addQueryArgs( PATH + 'candidates', {
					post_type: postType,
					language,
					search,
				} ),
			} )
				.then( ( items ) =>
					setOptions(
						items.map( ( item ) => ( {
							value: String( item.id ),
							label: item.title || `#${ item.id }`,
						} ) )
					)
				)
				.catch( () => setOptions( [] ) );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ postType, language, search ] );

	return (
		<ComboboxControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Link existing content', 'easyrankly' ) }
			value={ null }
			options={ options }
			onFilterValueChange={ setSearch }
			onChange={ ( value ) => value && onPick( Number( value ) ) }
		/>
	);
}

function LanguagePanel() {
	const { postId, postType } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			postType: select( editorStore ).getCurrentPostType(),
		} ),
		[]
	);
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( postId ) {
			apiFetch( { path: PATH + postId } )
				.then( setData )
				.catch( ( response ) => setError( response.message ) );
		}
	}, [ postId ] );

	const run = ( request ) => {
		setBusy( true );
		setError( null );
		request
			.then( setData )
			.catch( ( response ) => setError( response.message ) )
			.finally( () => setBusy( false ) );
	};

	const save = ( body ) =>
		run( apiFetch( { path: PATH + postId, method: 'POST', data: body } ) );

	const copy = ( language ) =>
		run(
			apiFetch( {
				path: PATH + postId + '/copy',
				method: 'POST',
				data: { language },
			} )
		);

	return (
		<PluginDocumentSettingPanel
			name="easyrankly-language"
			title={ __( 'Language', 'easyrankly' ) }
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ data === null ? (
				! error && <Spinner />
			) : (
				<Flex direction="column" gap={ 4 }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Language of this content', 'easyrankly' ) }
						value={ data.language }
						disabled={ busy }
						options={ languages.map( ( language ) => ( {
							value: language.slug,
							label: language.name,
						} ) ) }
						onChange={ ( language ) => save( { language } ) }
					/>
					{ languages
						.filter(
							( language ) => language.slug !== data.language
						)
						.map( ( language ) => {
							const translation =
								data.translations[ language.slug ];
							const others = ids( data.translations );

							if ( translation ) {
								delete others[ language.slug ];
								return (
									<div key={ language.slug }>
										<strong>{ language.name }</strong>
										{ ': ' }
										{ translation.edit_link ? (
											<a href={ translation.edit_link }>
												{ translation.title ||
													`#${ translation.id }` }
											</a>
										) : (
											translation.title ||
											`#${ translation.id }`
										) }
										{ translation.status !== 'publish' &&
											` (${ translation.status })` }{ ' ' }
										<Button
											variant="link"
											isDestructive
											disabled={ busy }
											onClick={ () =>
												save( {
													language: data.language,
													translations: others,
												} )
											}
										>
											{ __( 'Unlink', 'easyrankly' ) }
										</Button>
									</div>
								);
							}

							return (
								<div key={ language.slug }>
									<p>
										<strong>{ language.name }</strong>
										{ ': ' }
										{ __( 'no translation', 'easyrankly' ) }
									</p>
									<Button
										variant="secondary"
										disabled={ busy }
										onClick={ () => copy( language.slug ) }
									>
										{ sprintf(
											/* translators: %s: language name. */
											__(
												'Create %s draft',
												'easyrankly'
											),
											language.name
										) }
									</Button>
									<ExistingPost
										postType={ postType }
										language={ language.slug }
										onPick={ ( id ) =>
											save( {
												language: data.language,
												translations: {
													...others,
													[ language.slug ]: id,
												},
											} )
										}
									/>
								</div>
							);
						} ) }
				</Flex>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'easyrankly-language', { render: LanguagePanel } );
