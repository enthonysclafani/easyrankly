import {
	Button,
	Card,
	CardBody,
	CardHeader,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

export default function Identity( { settings, update } ) {
	const isPerson = settings.identity_type === 'person';
	const logo = settings.identity_logo ?? 0;
	// Edited as text, one URL per line; saved as a list without empty lines.
	const [ profiles, setProfiles ] = useState(
		( settings.same_as ?? [] ).join( '\n' )
	);

	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>
					{ __( 'Site identity (structured data)', 'easyrankly' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'This site represents', 'easyrankly' ) }
					value={ settings.identity_type ?? 'organization' }
					options={ [
						{
							value: 'organization',
							label: __( 'An organization', 'easyrankly' ),
						},
						{
							value: 'person',
							label: __( 'A person', 'easyrankly' ),
						},
					] }
					onChange={ ( value ) => update( 'identity_type', value ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'easyrankly' ) }
					help={ __( 'Empty uses the site title.', 'easyrankly' ) }
					value={ settings.identity_name ?? '' }
					onChange={ ( value ) => update( 'identity_name', value ) }
				/>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ logo || undefined }
					onSelect={ ( media ) =>
						update( 'identity_logo', media.id )
					}
					render={ ( { open } ) => (
						<p>
							<Button variant="secondary" onClick={ open }>
								{ isPerson
									? __( 'Choose photo', 'easyrankly' )
									: __( 'Choose logo', 'easyrankly' ) }
							</Button>{ ' ' }
							{ !! logo && (
								<Button
									variant="link"
									isDestructive
									onClick={ () =>
										update( 'identity_logo', 0 )
									}
								>
									{ __( 'Remove', 'easyrankly' ) }
								</Button>
							) }
						</p>
					) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Profiles on other sites', 'easyrankly' ) }
					help={ __(
						'One URL per line (social profiles, Wikipedia…).',
						'easyrankly'
					) }
					value={ profiles }
					onChange={ ( value ) => {
						setProfiles( value );
						update(
							'same_as',
							value
								.split( '\n' )
								.map( ( line ) => line.trim() )
								.filter( Boolean )
						);
					} }
				/>
			</CardBody>
		</Card>
	);
}
