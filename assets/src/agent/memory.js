/**
 * Memory tab: edits `erankly_memory` entries through `/wp/v2/easyrankly-memory`,
 * imports and exports them as one Markdown file through `/easyrankly/v1/memory`.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Flex,
	FormFileUpload,
	Modal,
	Notice,
	Spinner,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/wp/v2/easyrankly-memory';
const PER_PAGE = 100;
const PREVIEW = 140;
const EMPTY = { id: 0, title: '', content: '', active: true };

/**
 * Flattens a REST entry into the form fields.
 *
 * @param {Object} post REST item (context=edit).
 * @return {Object} Entry.
 */
function toEntry( post ) {
	return {
		id: post.id,
		title: post.title?.raw ?? '',
		content: post.content?.raw ?? '',
		active: post.status === 'publish',
	};
}

/**
 * Downloads a text as a file, without writing anything on the server.
 *
 * @param {string} filename File name.
 * @param {string} text     Content.
 */
function download( filename, text ) {
	const url = window.URL.createObjectURL(
		new window.Blob( [ text ], { type: 'text/markdown' } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	window.URL.revokeObjectURL( url );
}

function EntryForm( { entry, onClose, onSaved } ) {
	const [ values, setValues ] = useState( entry );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( key ) => ( value ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );

	const run = ( request ) => {
		setBusy( true );
		setError( null );
		request
			.then( onSaved )
			.catch( ( response ) => setError( response.message ) )
			.finally( () => setBusy( false ) );
	};

	const submit = ( event ) => {
		event.preventDefault();
		run(
			apiFetch( {
				path: values.id ? `${ PATH }/${ values.id }` : PATH,
				method: 'POST',
				data: {
					title: values.title,
					content: values.content,
					status: values.active ? 'publish' : 'draft',
				},
			} )
		);
	};

	const remove = () =>
		run(
			apiFetch( {
				path: `${ PATH }/${ values.id }?force=true`,
				method: 'DELETE',
			} )
		);

	return (
		<Modal
			title={
				entry.id
					? __( 'Edit memory entry', 'easyrankly' )
					: __( 'Add memory entry', 'easyrankly' )
			}
			size="large"
			onRequestClose={ onClose }
		>
			<form onSubmit={ submit }>
				<Flex direction="column" gap={ 4 }>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'easyrankly' ) }
						value={ values.title }
						onChange={ set( 'title' ) }
						required
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Fact', 'easyrankly' ) }
						help={ __(
							'One fact per entry, in Markdown: tone, audience, brand rules, strategic pages, decisions.',
							'easyrankly'
						) }
						rows={ 10 }
						value={ values.content }
						onChange={ set( 'content' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'The agent uses this entry',
							'easyrankly'
						) }
						checked={ values.active }
						onChange={ set( 'active' ) }
					/>
					<Flex justify="space-between">
						<div>
							{ entry.id > 0 && (
								<Button
									variant="tertiary"
									isDestructive
									disabled={ busy }
									onClick={ remove }
								>
									{ __( 'Delete', 'easyrankly' ) }
								</Button>
							) }
						</div>
						<Flex justify="flex-end" expanded={ false }>
							<Button variant="tertiary" onClick={ onClose }>
								{ __( 'Cancel', 'easyrankly' ) }
							</Button>
							<Button
								variant="primary"
								type="submit"
								isBusy={ busy }
								disabled={ busy }
							>
								{ __( 'Save', 'easyrankly' ) }
							</Button>
						</Flex>
					</Flex>
				</Flex>
			</form>
		</Modal>
	);
}

export default function Memory() {
	const [ entries, setEntries ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ importing, setImporting ] = useState( false );

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( {
			path: addQueryArgs( PATH, {
				context: 'edit',
				status: 'publish,draft',
				per_page: PER_PAGE,
				orderby: 'modified',
				order: 'desc',
			} ),
		} )
			.then( ( posts ) => setEntries( posts.map( toEntry ) ) )
			.catch( ( response ) =>
				setNotice( { status: 'error', text: response.message } )
			)
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( load, [ load ] );

	const exportFile = () =>
		apiFetch( { path: '/easyrankly/v1/memory/export' } )
			.then( ( data ) => download( data.filename, data.markdown ) )
			.catch( ( response ) =>
				setNotice( { status: 'error', text: response.message } )
			);

	// One request per batch of entries, until the whole file is read.
	const importFile = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file ) {
			return;
		}
		setImporting( true );
		setNotice( null );
		const markdown = await file.text();
		const totals = { created: 0, skipped: 0, errors: [] };
		try {
			let offset = 0;
			let result;
			do {
				result = await apiFetch( {
					path: '/easyrankly/v1/memory/import',
					method: 'POST',
					data: { markdown, offset },
				} );
				totals.created += result.created;
				totals.skipped += result.skipped;
				totals.errors.push( ...result.errors );
				offset = result.next;
			} while ( offset < result.total );
			setNotice( {
				status: totals.errors.length ? 'warning' : 'success',
				text: [
					sprintf(
						/* translators: 1: entries created, 2: entries already present. */
						__(
							'Imported %1$d entries, %2$d already present.',
							'easyrankly'
						),
						totals.created,
						totals.skipped
					),
					...totals.errors,
				].join( ' ' ),
			} );
		} catch ( response ) {
			setNotice( { status: 'error', text: response.message } );
		}
		setImporting( false );
		load();
	};

	return (
		<Flex direction="column" gap={ 4 }>
			<p>
				{ __(
					'Facts the agent keeps in mind. Reasons for rejected proposals and your edits to accepted ones are added here too: change or delete them freely.',
					'easyrankly'
				) }
			</p>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }
			<Flex justify="flex-start">
				<Button variant="primary" onClick={ () => setEditing( EMPTY ) }>
					{ __( 'Add entry', 'easyrankly' ) }
				</Button>
				<Button variant="secondary" onClick={ exportFile }>
					{ __( 'Export .md', 'easyrankly' ) }
				</Button>
				<FormFileUpload
					__next40pxDefaultSize
					accept=".md,text/markdown,text/plain"
					onChange={ importFile }
					render={ ( { openFileDialog } ) => (
						<Button
							variant="secondary"
							isBusy={ importing }
							disabled={ importing }
							onClick={ openFileDialog }
						>
							{ __( 'Import .md', 'easyrankly' ) }
						</Button>
					) }
				/>
			</Flex>
			{ loading && <Spinner /> }
			{ ! loading && entries.length === 0 && (
				<p>{ __( 'The memory is empty.', 'easyrankly' ) }</p>
			) }
			{ ! loading && entries.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Name', 'easyrankly' ) }</th>
							<th>{ __( 'Fact', 'easyrankly' ) }</th>
							<th>{ __( 'Used', 'easyrankly' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ entries.map( ( entry ) => (
							<tr key={ entry.id }>
								<td>
									<Button
										variant="link"
										onClick={ () => setEditing( entry ) }
									>
										{ entry.title }
									</Button>
								</td>
								<td>
									{ entry.content.length > PREVIEW
										? `${ entry.content.slice(
												0,
												PREVIEW
											) }…`
										: entry.content }
								</td>
								<td>
									{ entry.active
										? __( 'Yes', 'easyrankly' )
										: __( 'No', 'easyrankly' ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ editing && (
				<EntryForm
					entry={ editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						load();
					} }
				/>
			) }
		</Flex>
	);
}
