/**
 * Redirects screen: lists and edits `erankly_redirect` posts through `/wp/v2/easyrankly-redirects`.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Flex,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const PATH = '/wp/v2/easyrankly-redirects';
const PER_PAGE = 20;
const META = {
	target: '_easyrankly_redirect_target',
	code: '_easyrankly_redirect_code',
	regex: '_easyrankly_redirect_regex',
	forced: '_easyrankly_redirect_forced',
};
const CODES = [
	{ value: '301', label: __( '301 Moved permanently', 'easyrankly' ) },
	{ value: '302', label: __( '302 Found (temporary)', 'easyrankly' ) },
	{ value: '307', label: __( '307 Temporary redirect', 'easyrankly' ) },
	{ value: '410', label: __( '410 Gone (no target)', 'easyrankly' ) },
];
const EMPTY = {
	id: 0,
	source: '',
	target: '',
	code: 301,
	regex: false,
	forced: false,
	active: true,
};

/**
 * Flattens a REST redirect into the form fields.
 *
 * @param {Object} post REST item (context=edit).
 * @return {Object} Rule.
 */
function toRule( post ) {
	return {
		id: post.id,
		source: post.title?.raw ?? '',
		target: post.meta?.[ META.target ] ?? '',
		code: post.meta?.[ META.code ] ?? 301,
		regex: !! post.meta?.[ META.regex ],
		forced: !! post.meta?.[ META.forced ],
		active: post.status === 'publish',
	};
}

/**
 * Saves a rule; the server validates it and answers 400 with a message when it is invalid.
 *
 * @param {Object} rule Rule from the form.
 * @return {Promise} Saved REST item.
 */
function saveRule( rule ) {
	return apiFetch( {
		path: rule.id ? `${ PATH }/${ rule.id }` : PATH,
		method: 'POST',
		data: {
			title: rule.source,
			status: rule.active ? 'publish' : 'draft',
			meta: {
				[ META.target ]: Number( rule.code ) === 410 ? '' : rule.target,
				[ META.code ]: Number( rule.code ),
				[ META.regex ]: rule.regex,
				[ META.forced ]: rule.forced,
			},
		},
	} );
}

function RuleForm( { rule, onClose, onSaved } ) {
	const [ values, setValues ] = useState( rule );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( key ) => ( value ) =>
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
	const gone = Number( values.code ) === 410;

	const submit = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( null );
		saveRule( values )
			.then( onSaved )
			.catch( ( response ) => setError( response.message ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<Modal
			title={
				rule.id
					? __( 'Edit redirect', 'easyrankly' )
					: __( 'Add redirect', 'easyrankly' )
			}
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
						label={ __( 'Source', 'easyrankly' ) }
						help={
							values.regex
								? __(
										'Regular expression matched against the lowercase path, for example ^/old/(.*)$',
										'easyrankly'
									)
								: __(
										'Path on this site, for example /old-page. Query strings are ignored.',
										'easyrankly'
									)
						}
						value={ values.source }
						onChange={ set( 'source' ) }
						required
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Regular expression', 'easyrankly' ) }
						checked={ values.regex }
						onChange={ set( 'regex' ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Type', 'easyrankly' ) }
						value={ String( values.code ) }
						options={ CODES }
						onChange={ set( 'code' ) }
					/>
					{ ! gone && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Target', 'easyrankly' ) }
							help={ __(
								'Path on this site or full URL. With a regular expression, $1 inserts the first captured group.',
								'easyrankly'
							) }
							value={ values.target }
							onChange={ set( 'target' ) }
							required
						/>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Apply even when the page exists',
							'easyrankly'
						) }
						help={ __(
							'Off: the redirect runs only when the address returns 404.',
							'easyrankly'
						) }
						checked={ values.forced }
						onChange={ set( 'forced' ) }
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
	const [ rules, setRules ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ pages, setPages ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ editing, setEditing ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ reload, setReload ] = useState( 0 );

	useEffect( () => {
		apiFetch( {
			path: addQueryArgs( PATH, {
				context: 'edit',
				status: 'publish,draft',
				per_page: PER_PAGE,
				page,
				search: search || undefined,
				orderby: 'id',
				order: 'desc',
			} ),
			parse: false,
		} )
			.then( ( response ) => {
				setPages(
					Number( response.headers.get( 'X-WP-TotalPages' ) ) || 1
				);
				return response.json();
			} )
			.then( ( items ) => setRules( items.map( toRule ) ) )
			.catch( ( error ) => {
				setRules( [] );
				setNotice( { status: 'error', message: error.message } );
			} );
	}, [ page, search, reload ] );

	const refresh = ( message ) => {
		setEditing( null );
		setNotice( message ? { status: 'success', message } : null );
		setReload( ( count ) => count + 1 );
	};

	const fail = ( error ) =>
		setNotice( { status: 'error', message: error.message } );

	const remove = ( rule ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this redirect?', 'easyrankly' ) ) ) {
			return;
		}
		apiFetch( {
			path: `${ PATH }/${ rule.id }?force=true`,
			method: 'DELETE',
		} )
			.then( () => refresh( __( 'Redirect deleted.', 'easyrankly' ) ) )
			.catch( fail );
	};

	const toggle = ( rule ) =>
		saveRule( { ...rule, active: ! rule.active } )
			.then( () => refresh() )
			.catch( fail );

	return (
		<>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<Flex justify="space-between" className="tablenav">
				<Button
					variant="primary"
					onClick={ () => setEditing( { ...EMPTY } ) }
				>
					{ __( 'Add redirect', 'easyrankly' ) }
				</Button>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search redirects', 'easyrankly' ) }
					value={ search }
					onChange={ ( value ) => {
						setSearch( value );
						setPage( 1 );
					} }
				/>
			</Flex>
			{ rules === null && <Spinner /> }
			{ rules !== null && (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>{ __( 'Source', 'easyrankly' ) }</th>
							<th>{ __( 'Target', 'easyrankly' ) }</th>
							<th>{ __( 'Type', 'easyrankly' ) }</th>
							<th>{ __( 'Status', 'easyrankly' ) }</th>
							<th>{ __( 'Actions', 'easyrankly' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rules.length === 0 && (
							<tr>
								<td colSpan="5">
									{ __( 'No redirects yet.', 'easyrankly' ) }
								</td>
							</tr>
						) }
						{ rules.map( ( rule ) => (
							<tr key={ rule.id }>
								<td>
									<code>{ rule.source }</code>
									{ rule.regex &&
										` ${ __( '(regex)', 'easyrankly' ) }` }
								</td>
								<td>{ rule.target || '—' }</td>
								<td>
									{ rule.code }
									{ rule.forced &&
										` ${ __( '(always)', 'easyrankly' ) }` }
								</td>
								<td>
									{ rule.active
										? __( 'Active', 'easyrankly' )
										: __( 'Inactive', 'easyrankly' ) }
								</td>
								<td>
									<Button
										variant="link"
										onClick={ () => setEditing( rule ) }
									>
										{ __( 'Edit', 'easyrankly' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										onClick={ () => toggle( rule ) }
									>
										{ rule.active
											? __( 'Deactivate', 'easyrankly' )
											: __( 'Activate', 'easyrankly' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () => remove( rule ) }
									>
										{ __( 'Delete', 'easyrankly' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ pages > 1 && (
				<Flex justify="flex-end" className="tablenav">
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
			{ editing && (
				<RuleForm
					rule={ editing }
					onClose={ () => setEditing( null ) }
					onSaved={ () =>
						refresh( __( 'Redirect saved.', 'easyrankly' ) )
					}
				/>
			) }
		</>
	);
}

const root = document.getElementById( 'easyrankly-redirects' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
