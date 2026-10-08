/**
 * Custom code screen: lists and edits `erankly_snippet` posts through `/wp/v2/easyrankly-snippets`.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Flex,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/wp/v2/easyrankly-snippets';
const STATE = window.easyrankly ?? {};
const META = {
	type: '_easyrankly_snippet_type',
	position: '_easyrankly_snippet_position',
	priority: '_easyrankly_snippet_priority',
	error: '_easyrankly_snippet_error',
};
const POSITIONS = [
	{ value: 'head', label: __( 'Head', 'easyrankly' ) },
	{
		value: 'body_open',
		label: __( 'After the opening body tag', 'easyrankly' ),
	},
	{ value: 'footer', label: __( 'Footer', 'easyrankly' ) },
];
const EMPTY = {
	id: 0,
	name: '',
	code: '',
	type: 'html',
	position: 'head',
	priority: 10,
	active: false,
	error: '',
};

/**
 * Flattens a REST snippet into the form fields.
 *
 * @param {Object} post REST item (context=edit).
 * @return {Object} Snippet.
 */
function toSnippet( post ) {
	return {
		id: post.id,
		name: post.title?.raw ?? '',
		code: post.content?.raw ?? '',
		type: post.meta?.[ META.type ] ?? 'html',
		position: post.meta?.[ META.position ] ?? 'head',
		priority: post.meta?.[ META.priority ] ?? 10,
		active: post.status === 'publish',
		error: post.meta?.[ META.error ] ?? '',
	};
}

/**
 * Saves a snippet; the server checks permissions and PHP syntax and answers with a message.
 *
 * @param {Object} snippet Snippet from the form.
 * @return {Promise} Saved REST item.
 */
function saveSnippet( snippet ) {
	const meta = {
		[ META.position ]: snippet.position,
		[ META.priority ]: Number( snippet.priority ) || 0,
	};
	if ( ! snippet.id ) {
		meta[ META.type ] = snippet.type;
	}
	return apiFetch( {
		path: snippet.id ? `${ PATH }/${ snippet.id }` : PATH,
		method: 'POST',
		data: {
			title: snippet.name,
			content: snippet.code,
			status: snippet.active ? 'publish' : 'draft',
			meta,
		},
	} );
}

function SnippetForm( { snippet, onClose, onSaved } ) {
	const [ values, setValues ] = useState( snippet );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( key ) => ( value ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
	const types = [ { value: 'html', label: __( 'HTML', 'easyrankly' ) } ];
	if ( STATE.canPhp || values.type === 'php' ) {
		types.push( { value: 'php', label: __( 'PHP', 'easyrankly' ) } );
	}

	const submit = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( null );
		saveSnippet( values )
			.then( onSaved )
			.catch( ( response ) => setError( response.message ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<Modal
			title={
				snippet.id
					? __( 'Edit snippet', 'easyrankly' )
					: __( 'Add snippet', 'easyrankly' )
			}
			onRequestClose={ onClose }
			size="large"
		>
			<form onSubmit={ submit }>
				<Flex direction="column" gap={ 4 }>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ snippet.error && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This snippet was turned off after an error:',
								'easyrankly'
							) }{ ' ' }
							<code>{ snippet.error }</code>
						</Notice>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'easyrankly' ) }
						value={ values.name }
						onChange={ set( 'name' ) }
						required
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Type', 'easyrankly' ) }
						value={ values.type }
						options={ types }
						onChange={ set( 'type' ) }
						disabled={ !! snippet.id }
						help={
							snippet.id
								? __(
										'The type cannot change after the snippet is created.',
										'easyrankly'
									)
								: null
						}
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Code', 'easyrankly' ) }
						help={
							values.type === 'php'
								? __(
										'PHP without the opening tag. It is checked for syntax errors before it can be active, and turned off at the first runtime error.',
										'easyrankly'
									)
								: __(
										'Printed as it is on every page of the site.',
										'easyrankly'
									)
						}
						rows={ 14 }
						value={ values.code }
						onChange={ set( 'code' ) }
						style={ { fontFamily: 'monospace' } }
						spellCheck={ false }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Position', 'easyrankly' ) }
						value={ values.position }
						options={ POSITIONS }
						onChange={ set( 'position' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						max={ 1000 }
						label={ __( 'Priority', 'easyrankly' ) }
						help={ __( 'Lower numbers run first.', 'easyrankly' ) }
						value={ String( values.priority ) }
						onChange={ set( 'priority' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Active', 'easyrankly' ) }
						checked={ values.active }
						onChange={ set( 'active' ) }
					/>
					<Flex justify="flex-end">
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'easyrankly' ) }
						</Button>
						<Button
							variant="primary"
							type="submit"
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save', 'easyrankly' ) }
						</Button>
					</Flex>
				</Flex>
			</form>
		</Modal>
	);
}

function App() {
	const [ snippets, setSnippets ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ reload, setReload ] = useState( 0 );

	useEffect( () => {
		apiFetch( {
			path: addQueryArgs( PATH, {
				context: 'edit',
				status: 'publish,draft',
				per_page: 100,
				orderby: 'title',
				order: 'asc',
			} ),
		} )
			.then( ( items ) => setSnippets( items.map( toSnippet ) ) )
			.catch( ( error ) => {
				setSnippets( [] );
				setNotice( { status: 'error', message: error.message } );
			} );
	}, [ reload ] );

	const refresh = ( message ) => {
		setEditing( null );
		setNotice( message ? { status: 'success', message } : null );
		setReload( ( count ) => count + 1 );
	};

	const fail = ( error ) =>
		setNotice( { status: 'error', message: error.message } );

	const remove = ( snippet ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this snippet?', 'easyrankly' ) ) ) {
			return;
		}
		apiFetch( {
			path: `${ PATH }/${ snippet.id }?force=true`,
			method: 'DELETE',
		} )
			.then( () => refresh( __( 'Snippet deleted.', 'easyrankly' ) ) )
			.catch( fail );
	};

	const toggle = ( snippet ) =>
		saveSnippet( { ...snippet, active: ! snippet.active } )
			.then( () => refresh() )
			.catch( fail );

	const position = ( value ) =>
		POSITIONS.find( ( item ) => item.value === value )?.label ?? value;

	return (
		<>
			{ STATE.safeMode && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Safe mode is on (EASYRANKLY_SAFE_MODE): no snippet runs.',
						'easyrankly'
					) }
				</Notice>
			) }
			{ ! STATE.phpAllowed && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'File editing is disabled on this site: PHP snippets do not run and cannot be edited.',
						'easyrankly'
					) }
				</Notice>
			) }
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<p>
				<Button
					variant="primary"
					onClick={ () => setEditing( { ...EMPTY } ) }
				>
					{ __( 'Add snippet', 'easyrankly' ) }
				</Button>
			</p>
			{ snippets === null && <Spinner /> }
			{ snippets !== null && (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>{ __( 'Name', 'easyrankly' ) }</th>
							<th>{ __( 'Type', 'easyrankly' ) }</th>
							<th>{ __( 'Position', 'easyrankly' ) }</th>
							<th>{ __( 'Status', 'easyrankly' ) }</th>
							<th>{ __( 'Actions', 'easyrankly' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ snippets.length === 0 && (
							<tr>
								<td colSpan="5">
									{ __( 'No snippets yet.', 'easyrankly' ) }
								</td>
							</tr>
						) }
						{ snippets.map( ( snippet ) => (
							<tr key={ snippet.id }>
								<td>{ snippet.name }</td>
								<td>{ snippet.type.toUpperCase() }</td>
								<td>
									{ position( snippet.position ) } (
									{ snippet.priority })
								</td>
								<td>
									{ snippet.active
										? __( 'Active', 'easyrankly' )
										: __( 'Inactive', 'easyrankly' ) }
									{ snippet.error &&
										` ${ __(
											'(turned off by an error)',
											'easyrankly'
										) }` }
								</td>
								<td>
									<Button
										variant="link"
										onClick={ () => setEditing( snippet ) }
									>
										{ __( 'Edit', 'easyrankly' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										onClick={ () => toggle( snippet ) }
									>
										{ snippet.active
											? __( 'Deactivate', 'easyrankly' )
											: __( 'Activate', 'easyrankly' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () => remove( snippet ) }
									>
										{ __( 'Delete', 'easyrankly' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ editing && (
				<SnippetForm
					snippet={ editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () =>
						refresh( __( 'Snippet saved.', 'easyrankly' ) )
					}
				/>
			) }
		</>
	);
}

const root = document.getElementById( 'easyrankly-snippets' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
