import {
	Button,
	Card,
	CardBody,
	CardHeader,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

export default function Social( { settings, update } ) {
	const imageId = settings.social_image ?? 0;

	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>{ __( 'Social sharing', 'easyrankly' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Default image shared when a page has neither its own social image nor a featured image.',
						'easyrankly'
					) }
				</p>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ imageId || undefined }
					onSelect={ ( media ) => update( 'social_image', media.id ) }
					render={ ( { open } ) => (
						<p>
							<Button variant="secondary" onClick={ open }>
								{ imageId
									? __(
											'Replace default image',
											'easyrankly'
										)
									: __(
											'Choose default image',
											'easyrankly'
										) }
							</Button>{ ' ' }
							{ !! imageId && (
								<Button
									variant="link"
									isDestructive
									onClick={ () =>
										update( 'social_image', 0 )
									}
								>
									{ __( 'Remove', 'easyrankly' ) }
								</Button>
							) }
						</p>
					) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'X username', 'easyrankly' ) }
					help={ __(
						'Without @. Printed as twitter:site.',
						'easyrankly'
					) }
					maxLength={ 15 }
					value={ settings.x_username ?? '' }
					onChange={ ( value ) =>
						update( 'x_username', value.replace( /^@/, '' ) )
					}
				/>
			</CardBody>
		</Card>
	);
}
