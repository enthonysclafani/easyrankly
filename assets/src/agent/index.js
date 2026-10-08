/**
 * Agent dashboard: lists the proposals through `/easyrankly/v1/proposals` and records decisions;
 * the Memory tab edits the project memory.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Flex,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
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
];
// Fields longer than this are edited in a textarea.
const LONG_FIELD = 120;

const PER_PAGE = 20;

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
					<th>{ __( 'Field', 'easyrankly' ) }</th>
					<th>{ __( 'Now', 'easyrankly' ) }</th>
					<th>{ __( 'Proposed', 'easyrankly' ) }</th>
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
 * Details of a proposal and the form of one decision.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.item    Proposal.
 * @param {string}     props.mode    view, accept, edit, reject or undo.
 * @param {() => void} props.onClose Closes the modal.
 * @param {() => void} props.onDone  Called after a decision.
 * @return {Element} Modal body.
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
				// A failed or superseded proposal changed status: refresh the list behind the modal.
				onDone();
			} )
			.finally( () => setBusy( false ) );
	};

	const labels = {
		accept: __( 'Accept', 'easyrankly' ),
		edit: __( 'Save and accept', 'easyrankly' ),
		reject: __( 'Reject', 'easyrankly' ),
		undo: __( 'Undo', 'easyrankly' ),
	};

	return (
		<form onSubmit={ submit }>
			<Flex direction="column" gap={ 4 }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
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
					<Notice status="info" isDismissible={ false }>
						{ item.note }
					</Notice>
				) }
				{ mode === 'edit' ? (
					item.diff.map( ( row ) => {
						const Control =
							String( row.after ).length > LONG_FIELD ||
							row.field.includes( 'description' )
								? TextareaControl
								: TextControl;
						return (
							<Control
								key={ row.field }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ row.label }
								value={ values[ row.field ] ?? '' }
								onChange={ ( value ) =>
									setValues( ( current ) => ( {
										...current,
										[ row.field ]: value,
									} ) )
								}
							/>
						);
					} )
				) : (
					<Diff diff={ item.diff } />
				) }
				{ mode === 'reject' && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Reason (optional)', 'easyrankly' ) }
						help={ __(
							'Tell the agent why, so it can learn your preferences.',
							'easyrankly'
						) }
						value={ reason }
						onChange={ setReason }
					/>
				) }
				<Flex justify="flex-end">
					<Button variant="tertiary" onClick={ onClose }>
						{ mode === 'view'
							? __( 'Close', 'easyrankly' )
							: __( 'Cancel', 'easyrankly' ) }
					</Button>
					{ mode !== 'view' && (
						<Button
							variant="primary"
							type="submit"
							isDestructive={ mode === 'reject' }
							isBusy={ busy }
							disabled={ busy }
						>
							{ labels[ mode ] }
						</Button>
					) }
				</Flex>
			</Flex>
		</form>
	);
}

function Proposals() {
	const [ status, setStatus ] = useState( 'pending' );
	const [ page, setPage ] = useState( 1 );
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

	const titles = {
		view: __( 'Proposal', 'easyrankly' ),
		accept: __( 'Accept the proposal', 'easyrankly' ),
		edit: __( 'Edit and accept', 'easyrankly' ),
		reject: __( 'Reject the proposal', 'easyrankly' ),
		undo: __( 'Undo the proposal', 'easyrankly' ),
	};
	const statusLabel = ( value ) =>
		STATUSES.find( ( option ) => option.value === value )?.label ?? value;
	const openModal = ( item, mode ) => () => setOpen( { item, mode } );

	return (
		<Flex direction="column" gap={ 4 }>
			<Analysis onProposal={ load } />
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<Flex justify="flex-start">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show', 'easyrankly' ) }
					value={ status }
					options={ [
						...STATUSES,
						{ value: 'all', label: __( 'All', 'easyrankly' ) },
					] }
					onChange={ ( value ) => {
						setStatus( value );
						setPage( 1 );
					} }
				/>
			</Flex>
			{ loading && <Spinner /> }
			{ ! loading && items.length === 0 && (
				<p>{ __( 'No proposals here.', 'easyrankly' ) }</p>
			) }
			{ ! loading && items.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Proposal', 'easyrankly' ) }</th>
							<th>{ __( 'Content', 'easyrankly' ) }</th>
							<th>{ __( 'Confidence', 'easyrankly' ) }</th>
							<th>{ __( 'Status', 'easyrankly' ) }</th>
							<th>{ __( 'Actions', 'easyrankly' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<tr key={ item.id }>
								<td>
									<Button
										variant="link"
										onClick={ openModal( item, 'view' ) }
									>
										{ item.title }
									</Button>
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
								<td>
									<Flex justify="flex-start" wrap>
										{ item.status === 'pending' && (
											<>
												<Button
													variant="primary"
													size="compact"
													onClick={ openModal(
														item,
														'accept'
													) }
												>
													{ __(
														'Accept',
														'easyrankly'
													) }
												</Button>
												<Button
													variant="secondary"
													size="compact"
													onClick={ openModal(
														item,
														'edit'
													) }
												>
													{ __(
														'Edit and accept',
														'easyrankly'
													) }
												</Button>
												<Button
													variant="tertiary"
													size="compact"
													isDestructive
													onClick={ openModal(
														item,
														'reject'
													) }
												>
													{ __(
														'Reject',
														'easyrankly'
													) }
												</Button>
											</>
										) }
										{ item.status === 'accepted' && (
											<Button
												variant="secondary"
												size="compact"
												onClick={ openModal(
													item,
													'undo'
												) }
											>
												{ __( 'Undo', 'easyrankly' ) }
											</Button>
										) }
									</Flex>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ pages > 1 && (
				<Flex justify="flex-end">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'easyrankly' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'easyrankly' ),
							page,
							pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'easyrankly' ) }
					</Button>
				</Flex>
			) }
			{ open && (
				<Modal
					title={ titles[ open.mode ] }
					size="large"
					onRequestClose={ () => setOpen( null ) }
				>
					<Decision
						item={ open.item }
						mode={ open.mode }
						onClose={ () => setOpen( null ) }
						onDone={ load }
					/>
				</Modal>
			) }
		</Flex>
	);
}

function App() {
	return (
		<TabPanel
			tabs={ [
				{ name: 'proposals', title: __( 'Proposals', 'easyrankly' ) },
				{ name: 'memory', title: __( 'Memory', 'easyrankly' ) },
			] }
		>
			{ ( tab ) =>
				tab.name === 'memory' ? <Memory /> : <Proposals />
			}
		</TabPanel>
	);
}

const root = document.getElementById( 'easyrankly-agent' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
