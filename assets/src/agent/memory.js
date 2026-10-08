/**
 * Memory tab: edits `erankly_memory` entries through `/wp/v2/easyrankly-memory`,
 * imports and exports them as one Markdown file through `/easyrankly/v1/memory`.
 * Markup and classes of the core admin screens, no components.
 */
import apiFetch from '@wordpress/api-fetch';
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
		<form onSubmit={ submit }>
			<h2>
				{ entry.id
					? __( 'Edit memory entry', 'easyrankly' )
					: __( 'Add memory entry', 'easyrankly' ) }
			</h2>
			{ error && (
				<div className="notice notice-error inline">
					<p>{ error }</p>
				</div>
			) }
			<table className="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label htmlFor="easyrankly-memory-title">
								{ __( 'Name', 'easyrankly' ) }
							</label>
						</th>
						<td>
							<input
								id="easyrankly-memory-title"
								type="text"
								className="regular-text"
								value={ values.title }
								onChange={ ( event ) =>
									set( 'title' )( event.target.value )
								}
								required
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label htmlFor="easyrankly-memory-content">
								{ __( 'Fact', 'easyrankly' ) }
							</label>
						</th>
						<td>
							<textarea
								id="easyrankly-memory-content"
								className="large-text"
								rows={ 10 }
								value={ values.content }
								onChange={ ( event ) =>
									set( 'content' )( event.target.value )
								}
							/>
							<p className="description">
								{ __(
									'One fact per entry, in Markdown: tone, audience, brand rules, strategic pages, decisions.',
									'easyrankly'
								) }
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">{ __( 'Used', 'easyrankly' ) }</th>
						<td>
							<label htmlFor="easyrankly-memory-active">
								<input
									id="easyrankly-memory-active"
									type="checkbox"
									checked={ values.active }
									onChange={ ( event ) =>
										set( 'active' )( event.target.checked )
									}
								/>{ ' ' }
								{ __(
									'The agent uses this entry',
									'easyrankly'
								) }
							</label>
						</td>
					</tr>
				</tbody>
			</table>
			<p className="submit">
				<button
					type="submit"
					className="button button-primary"
					disabled={ busy }
				>
					{ __( 'Save', 'easyrankly' ) }
				</button>{ ' ' }
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'easyrankly' ) }
				</button>
				{ entry.id > 0 && (
					<>
						{ ' ' }
						<button
							type="button"
							className="button-link button-link-delete"
							disabled={ busy }
							onClick={ remove }
						>
							{ __( 'Delete', 'easyrankly' ) }
						</button>
					</>
				) }
				{ busy && <span className="spinner is-active" /> }
			</p>
		</form>
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
		event.target.value = '';
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
		<>
			<p>
				{ __(
					'Facts the agent keeps in mind. Reasons for rejected proposals and your edits to accepted ones are added here too: change or delete them freely.',
					'easyrankly'
				) }
			</p>
			{ notice && (
				<div className={ `notice notice-${ notice.status } inline` }>
					<p>{ notice.text }</p>
				</div>
			) }
			<p>
				<button
					type="button"
					className="button button-primary"
					onClick={ () => setEditing( EMPTY ) }
				>
					{ __( 'Add entry', 'easyrankly' ) }
				</button>{ ' ' }
				<button type="button" className="button" onClick={ exportFile }>
					{ __( 'Export .md', 'easyrankly' ) }
				</button>{ ' ' }
				<label htmlFor="easyrankly-memory-import" className="button">
					{ __( 'Import .md', 'easyrankly' ) }
					<input
						id="easyrankly-memory-import"
						type="file"
						className="screen-reader-text"
						accept=".md,text/markdown,text/plain"
						disabled={ importing }
						onChange={ importFile }
					/>
				</label>
				{ importing && <span className="spinner is-active" /> }
			</p>
			{ editing && (
				<EntryForm
					// A new form for each entry, so no value of the previous one stays.
					key={ editing.id }
					entry={ editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						load();
					} }
				/>
			) }
			{ loading && <span className="spinner is-active" /> }
			{ ! loading && entries.length === 0 && (
				<p>{ __( 'The memory is empty.', 'easyrankly' ) }</p>
			) }
			{ ! loading && entries.length > 0 && (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">{ __( 'Name', 'easyrankly' ) }</th>
							<th scope="col">{ __( 'Fact', 'easyrankly' ) }</th>
							<th scope="col">{ __( 'Used', 'easyrankly' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ entries.map( ( entry ) => (
							<tr key={ entry.id }>
								<td>
									<strong>
										<button
											type="button"
											className="button-link row-title"
											onClick={ () =>
												setEditing( entry )
											}
										>
											{ entry.title }
										</button>
									</strong>
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
		</>
	);
}
