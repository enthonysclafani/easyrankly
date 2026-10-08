/**
 * Agent dashboard: lists the proposals through `/easyrankly/v1/proposals` and records decisions;
 * the Memory tab edits the project memory. Markup and classes of the core list tables, no
 * components: the tabs, the status links and the pages are plain links.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	createRoot,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import Analysis from './analysis';
import Memory from './memory';

const PATH = '/easyrankly/v1/proposals';
const STATUSES = [
	{ value: 'pending', label: __( 'Pending', 'easyrankly' ) },
	{ value: 'accepted', label: __( 'Accepted', 'easyrankly' ) },
	{ value: 'rejected', label: __( 'Rejected', 'easyrankly' ) },
	{ value: 'superseded', label: __( 'Superseded', 'easyrankly' ) },
	{ value: 'failed', label: __( 'Failed', 'easyrankly' ) },
	{ value: 'reverted', label: __( 'Undone', 'easyrankly' ) },
	{ value: 'all', label: __( 'All', 'easyrankly' ) },
];
// Fields longer than this are edited in a textarea.
const LONG_FIELD = 120;

const PER_PAGE = 20;

/**
 * Address of this screen with other query arguments.
 *
 * @param {Object} args Query arguments.
 * @return {string} URL.
 */
function screenUrl( args ) {
	return addQueryArgs( 'admin.php', { page: 'easyrankly-agent', ...args } );
}

/**
 * Sends a decision; the server answers with the updated proposal or an error message.
 *
 * @param {number} id     Proposal ID.
 * @param {string} action accept, reject or undo.
 * @param {Object} data   Request body.
 * @return {Promise} Updated proposal.
 */
function decide( id, action, data = {} ) {
	return apiFetch( {
		path: `${ PATH }/${ id }/${ action }`,
		method: 'POST',
		data,
	} );
}

/**
 * Shows a value of the preview; empty means "the site templates apply".
 *
 * @param {Object}  props       Props.
 * @param {?string} props.value Value.
 * @return {Element} Value or placeholder.
 */
function Value( { value } ) {
	if ( value === '' || value === null || value === undefined ) {
		return <em>{ __( '(template)', 'easyrankly' ) }</em>;
	}
	return String( value );
}

/**
 * Preview of the differences: one row per changed field.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.diff Rows from the REST API.
 * @return {Element} Table.
 */
function Diff( { diff } ) {
	return (
		<table className="widefat striped">
			<thead>
				<tr>
					<th scope="col">{ __( 'Field', 'easyrankly' ) }</th>
					<th scope="col">{ __( 'Now', 'easyrankly' ) }</th>
					<th scope="col">{ __( 'Proposed', 'easyrankly' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ diff.map( ( row ) => (
					<tr key={ row.field }>
						<th scope="row">{ row.label }</th>
						<td>
							<del>
								<Value value={ row.before } />
							</del>
						</td>
						<td>
							<ins>
								<Value value={ row.after } />
							</ins>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Details of a proposal and the form of one decision, opened under its row.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.item    Proposal.
 * @param {string}     props.mode    view, accept, edit, reject or undo.
 * @param {() => void} props.onClose Closes the row.
 * @param {() => void} props.onDone  Called after a decision.
 * @return {Element} Form.
 */
function Decision( { item, mode, onClose, onDone } ) {
	const [ values, setValues ] = useState( () =>
		Object.fromEntries(
			item.diff.map( ( row ) => [ row.field, row.after ] )
		)
	);
	const [ reason, setReason ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const submit = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );

		let request;
		if ( mode === 'reject' ) {
			request = decide( item.id, 'reject', { reason } );
		} else if ( mode === 'undo' ) {
			request = decide( item.id, 'undo' );
		} else if ( mode === 'edit' ) {
			request = decide( item.id, 'accept', { changes: values } );
		} else {
			request = decide( item.id, 'accept' );
		}

		request
			.then( () => {
				onDone();
				onClose();
			} )
			.catch( ( response ) => {
				setError( response.message );
				// A failed or superseded proposal changed status: refresh the list.
				onDone();
			} )
			.finally( () => setBusy( false ) );
	};

	const titles = {
		view: __( 'Proposal', 'easyrankly' ),
		accept: __( 'Accept the proposal', 'easyrankly' ),
		edit: __( 'Edit and accept', 'easyrankly' ),
		reject: __( 'Reject the proposal', 'easyrankly' ),
		undo: __( 'Undo the proposal', 'easyrankly' ),
	};
	const labels = {
		accept: __( 'Accept', 'easyrankly' ),
		edit: __( 'Save and accept', 'easyrankly' ),
		reject: __( 'Reject', 'easyrankly' ),
		undo: __( 'Undo', 'easyrankly' ),
	};

	return (
		<form onSubmit={ submit }>
			<fieldset>
				<legend>
					<strong>{ titles[ mode ] }</strong>
				</legend>
				{ error && (
					<div className="notice notice-error inline">
						<p>{ error }</p>
					</div>
				) }
				{ item.object && (
					<p>
						{ sprintf(
							/* translators: %s: title of the content the proposal changes. */
							__( 'Content: %s', 'easyrankly' ),
							item.object.title
						) }
					</p>
				) }
				{ item.motivation && <p>{ item.motivation }</p> }
				{ item.evidence && (
					<p>
						<strong>{ __( 'Evidence:', 'easyrankly' ) }</strong>{ ' ' }
						{ item.evidence }
					</p>
				) }
				{ item.note && (
					<div className="notice notice-info inline">
						<p>{ item.note }</p>
					</div>
				) }
				{ mode === 'edit' ? (
					item.diff.map( ( row ) => {
						const id = `easyrankly-${ item.id }-${ row.field }`;
						const long =
							String( row.after ).length > LONG_FIELD ||
							row.field.includes( 'description' );
						const change = ( event ) =>
							setValues( ( current ) => ( {
								...current,
								[ row.field ]: event.target.value,
							} ) );
						return (
							<p key={ row.field }>
								<label htmlFor={ id }>{ row.label }</label>
								<br />
								{ long ? (
									<textarea
										id={ id }
										className="large-text"
										rows={ 3 }
										value={ values[ row.field ] ?? '' }
										onChange={ change }
									/>
								) : (
									<input
										id={ id }
										type="text"
										className="large-text"
										value={ values[ row.field ] ?? '' }
										onChange={ change }
									/>
								) }
							</p>
						);
					} )
				) : (
					<Diff diff={ item.diff } />
				) }
				{ mode === 'reject' && (
					<p>
						<label htmlFor={ `easyrankly-reason-${ item.id }` }>
							{ __( 'Reason (optional)', 'easyrankly' ) }
						</label>
						<br />
						<textarea
							id={ `easyrankly-reason-${ item.id }` }
							className="large-text"
							rows={ 3 }
							value={ reason }
							onChange={ ( event ) =>
								setReason( event.target.value )
							}
						/>
						<span className="description">
							{ __(
								'Tell the agent why, so it can learn your preferences.',
								'easyrankly'
							) }
						</span>
					</p>
				) }
				<p className="submit">
					{ mode !== 'view' && (
						<>
							<button
								type="submit"
								className="button button-primary"
								disabled={ busy }
							>
								{ labels[ mode ] }
							</button>{ ' ' }
						</>
					) }
					<button
						type="button"
						className="button"
						onClick={ onClose }
					>
						{ mode === 'view'
							? __( 'Close', 'easyrankly' )
							: __( 'Cancel', 'easyrankly' ) }
					</button>
					{ busy && <span className="spinner is-active" /> }
				</p>
			</fieldset>
		</form>
	);
}

/**
 * Links to filter the proposals by status, like the post status links.
 *
 * @param {Object} props         Props.
 * @param {string} props.current Status shown.
 * @return {Element} Links.
 */
function StatusLinks( { current } ) {
	return (
		<ul className="subsubsub">
			{ STATUSES.map( ( option, index ) => (
				<li key={ option.value }>
					<a
						href={ screenUrl( { status: option.value } ) }
						className={
							option.value === current ? 'current' : undefined
						}
						aria-current={
							option.value === current ? 'page' : undefined
						}
					>
						{ option.label }
					</a>
					{ index < STATUSES.length - 1 && ' |' }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Previous and next page links, like the pagination of the core list tables.
 *
 * @param {Object} props        Props.
 * @param {string} props.status Status shown.
 * @param {number} props.page   Current page.
 * @param {number} props.pages  Number of pages.
 * @return {Element|null} Links.
 */
function Pagination( { status, page, pages } ) {
	if ( pages < 2 ) {
		return null;
	}

	return (
		<div className="tablenav bottom">
			<div className="tablenav-pages">
				<span className="pagination-links">
					{ page > 1 ? (
						<a
							className="prev-page button"
							href={ screenUrl( { status, paged: page - 1 } ) }
						>
							<span className="screen-reader-text">
								{ __( 'Previous page', 'easyrankly' ) }
							</span>
							<span aria-hidden="true">‹</span>
						</a>
					) : (
						<span
							className="tablenav-pages-navspan button disabled"
							aria-hidden="true"
						>
							‹
						</span>
					) }{ ' ' }
					<span className="paging-input">
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'easyrankly' ),
							page,
							pages
						) }
					</span>{ ' ' }
					{ page < pages ? (
						<a
							className="next-page button"
							href={ screenUrl( { status, paged: page + 1 } ) }
						>
							<span className="screen-reader-text">
								{ __( 'Next page', 'easyrankly' ) }
							</span>
							<span aria-hidden="true">›</span>
						</a>
					) : (
						<span
							className="tablenav-pages-navspan button disabled"
							aria-hidden="true"
						>
							›
						</span>
					) }
				</span>
			</div>
		</div>
	);
}

/**
 * Status and page from the address, so the links above work like the core screens.
 *
 * @return {{status: string, page: number}} Filter.
 */
function filterFromUrl() {
	const query = new window.URLSearchParams( window.location.search );
	const status = query.get( 'status' );
	return {
		status: STATUSES.some( ( option ) => option.value === status )
			? status
			: 'pending',
		page: Math.max( 1, Number( query.get( 'paged' ) ) || 1 ),
	};
}

function Proposals() {
	const [ { status, page } ] = useState( filterFromUrl );
	const [ items, setItems ] = useState( [] );
	const [ pages, setPages ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( null );

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( {
			path: addQueryArgs( PATH, { status, page, per_page: PER_PAGE } ),
			parse: false,
		} )
			.then( ( response ) => {
				setPages( Number( response.headers.get( 'X-WP-TotalPages' ) ) );
				return response.json();
			} )
			.then( setItems )
			.catch( ( response ) =>
				setError(
					response.message ??
						__( 'The proposals could not be loaded.', 'easyrankly' )
				)
			)
			.finally( () => setLoading( false ) );
	}, [ status, page ] );

	useEffect( load, [ load ] );

	const statusLabel = ( value ) =>
		STATUSES.find( ( option ) => option.value === value )?.label ?? value;
	const toggle = ( item, mode ) => () =>
		setOpen( ( current ) =>
			current?.item.id === item.id && current.mode === mode
				? null
				: { item, mode }
		);
	const action = ( item, mode, label ) => (
		<button
			type="button"
			className="button-link"
			onClick={ toggle( item, mode ) }
			aria-expanded={ open?.item.id === item.id && open.mode === mode }
		>
			{ label }
		</button>
	);
	const actions = ( item ) => {
		const links = [];
		if ( item.status === 'pending' ) {
			links.push(
				[
					'accept',
					action( item, 'accept', __( 'Accept', 'easyrankly' ) ),
				],
				[
					'edit',
					action(
						item,
						'edit',
						__( 'Edit and accept', 'easyrankly' )
					),
				],
				[
					'trash',
					action( item, 'reject', __( 'Reject', 'easyrankly' ) ),
				]
			);
		}
		if ( item.status === 'accepted' ) {
			links.push( [
				'undo',
				action( item, 'undo', __( 'Undo', 'easyrankly' ) ),
			] );
		}
		return links;
	};

	return (
		<>
			<Analysis onProposal={ load } />
			{ error && (
				<div className="notice notice-error inline">
					<p>{ error }</p>
				</div>
			) }
			<StatusLinks current={ status } />
			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" className="column-primary">
							{ __( 'Proposal', 'easyrankly' ) }
						</th>
						<th scope="col">{ __( 'Content', 'easyrankly' ) }</th>
						<th scope="col">
							{ __( 'Confidence', 'easyrankly' ) }
						</th>
						<th scope="col">{ __( 'Status', 'easyrankly' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ loading && (
						<tr>
							<td colSpan="4">
								<span className="spinner is-active" />
							</td>
						</tr>
					) }
					{ ! loading && items.length === 0 && (
						<tr className="no-items">
							<td colSpan="4">
								{ __( 'No proposals here.', 'easyrankly' ) }
							</td>
						</tr>
					) }
					{ ! loading &&
						items.map( ( item ) => [
							<tr key={ item.id }>
								<td className="column-primary">
									<strong>
										<button
											type="button"
											className="button-link row-title"
											onClick={ toggle( item, 'view' ) }
										>
											{ item.title }
										</button>
									</strong>
									<div className="row-actions visible">
										{ actions( item ).map(
											( [ key, link ], index ) => (
												<span
													key={ key }
													className={ key }
												>
													{ index > 0 && ' | ' }
													{ link }
												</span>
											)
										) }
									</div>
								</td>
								<td>
									{ item.object ? (
										<a
											href={
												item.object.edit_url ||
												item.object.url
											}
										>
											{ item.object.title ||
												__(
													'(no title)',
													'easyrankly'
												) }
										</a>
									) : (
										'—'
									) }
								</td>
								<td>{ `${ Math.round( item.confidence * 100 ) }%` }</td>
								<td>{ statusLabel( item.status ) }</td>
							</tr>,
							open?.item.id === item.id && (
								<tr key={ `${ item.id }-decision` }>
									<td colSpan="4">
										<Decision
											// A new form for each mode, so no error or reason of another stays.
											key={ open.mode }
											item={ open.item }
											mode={ open.mode }
											onClose={ () => setOpen( null ) }
											onDone={ load }
										/>
									</td>
								</tr>
							),
						] ) }
				</tbody>
			</table>
			<Pagination status={ status } page={ page } pages={ pages } />
		</>
	);
}

const root = document.getElementById( 'easyrankly-agent' );
if ( root ) {
	createRoot( root ).render(
		root.dataset.tab === 'memory' ? <Memory /> : <Proposals />
	);
}
