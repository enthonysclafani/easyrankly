/**
 * Analysis bar: runs the agent one step per request through `/easyrankly/v1/agent/step`,
 * only while this page is open. Hidden when no AI provider is available.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, Notice, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const MESSAGES = {
	finished: __( 'Analysis complete.', 'easyrankly' ),
	up_to_date: __( 'Nothing new to analyze.', 'easyrankly' ),
	daily_limit: __(
		'The agent reached its daily limit of proposals. It will continue tomorrow.',
		'easyrankly'
	),
	ai_unavailable: __( 'No AI provider is available.', 'easyrankly' ),
};

/**
 * @param {Object}     props            Props.
 * @param {() => void} props.onProposal Called when a proposal is created.
 * @return {Element|null} Bar.
 */
export default function Analysis( { onProposal } ) {
	const [ status, setStatus ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ current, setCurrent ] = useState( null );
	const [ created, setCreated ] = useState( 0 );
	const [ message, setMessage ] = useState( null );
	const stop = useRef( false );

	const run = async ( force ) => {
		setRunning( true );
		setMessage( null );
		stop.current = false;
		try {
			let step;
			do {
				step = await apiFetch( {
					path: '/easyrankly/v1/agent/step',
					method: 'POST',
					data: { force },
				} );
				if ( step.item ) {
					setCurrent( step.item.title );
				}
				if ( step.proposal > 0 ) {
					setCreated( ( count ) => count + 1 );
					onProposal();
				}
			} while ( ! step.done && ! stop.current );
			setMessage(
				stop.current
					? __( 'Analysis paused.', 'easyrankly' )
					: ( MESSAGES[ step.reason ] ?? '' )
			);
		} catch ( error ) {
			setMessage( error.message );
		}
		setCurrent( null );
		setRunning( false );
	};

	useEffect( () => {
		apiFetch( { path: '/easyrankly/v1/agent' } )
			.then( ( data ) => {
				setStatus( data );
				if ( data.ai && data.auto && ( data.recent > 0 || data.due ) ) {
					run( false );
				}
			} )
			.catch( () => setStatus( { ai: false } ) );
		return () => {
			stop.current = true;
		};
		// Runs once, when the dashboard opens.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( status === null ) {
		return null;
	}

	if ( ! status.ai ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Connect an AI provider in Settings → Connectors to get proposals for titles, descriptions and image texts. Redirect proposals for trashed content work without AI.',
					'easyrankly'
				) }
			</Notice>
		);
	}

	const toggle = ( value ) => {
		setStatus( ( previous ) => ( { ...previous, auto: value } ) );
		apiFetch( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: { easyrankly_settings: { agent_auto: value } },
		} ).catch( ( error ) => setMessage( error.message ) );
	};

	return (
		<Flex direction="column" gap={ 2 }>
			<Flex justify="flex-start" align="center" wrap>
				{ running ? (
					<Button
						variant="secondary"
						onClick={ () => ( stop.current = true ) }
					>
						{ __( 'Pause', 'easyrankly' ) }
					</Button>
				) : (
					<Button variant="secondary" onClick={ () => run( true ) }>
						{ __( 'Analyze now', 'easyrankly' ) }
					</Button>
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Analyze automatically when this page opens',
						'easyrankly'
					) }
					help={ __(
						'The agent sends content to your AI provider only while this page is open.',
						'easyrankly'
					) }
					checked={ !! status.auto }
					onChange={ toggle }
				/>
			</Flex>
			{ running && current && (
				<p>
					{ sprintf(
						/* translators: 1: content or image title, 2: proposals created so far. */
						__(
							'Analyzing “%1$s”… %2$d proposals so far.',
							'easyrankly'
						),
						current,
						created
					) }
				</p>
			) }
			{ message && <p>{ message }</p> }
		</Flex>
	);
}
