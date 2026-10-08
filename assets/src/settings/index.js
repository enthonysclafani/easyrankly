/**
 * Settings screen: reads and saves `easyrankly_settings` through `/wp/v2/settings`.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import sections from './sections';

const OPTION = 'easyrankly_settings';

function App() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( response ) => setSettings( response[ OPTION ] ?? {} ) )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			);
	}, [] );

	const update = ( key, value ) =>
		setSettings( ( current ) => ( { ...current, [ key ]: value } ) );

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: { [ OPTION ]: settings },
		} )
			.then( ( response ) => {
				setSettings( response[ OPTION ] );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'easyrankly' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: error.message } )
			)
			.finally( () => setSaving( false ) );
	};

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
			{ settings === null ? (
				! notice && <Spinner />
			) : (
				<>
					{ sections.map( ( Section, index ) => (
						<Section
							key={ index }
							settings={ settings }
							update={ update }
						/>
					) ) }
					<p>
						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving }
							onClick={ save }
						>
							{ __( 'Save settings', 'easyrankly' ) }
						</Button>
					</p>
				</>
			) }
		</>
	);
}

const container = document.getElementById( 'easyrankly-settings' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
