import { Card, CardBody, CardHeader, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function General( { settings, update } ) {
	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>{ __( 'General', 'easyrankly' ) }</h2>
			</CardHeader>
			<CardBody>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Title separator', 'easyrankly' ) }
					help={ __(
						'Inserted by the {{sep}} variable in title templates.',
						'easyrankly'
					) }
					maxLength={ 10 }
					value={ settings.title_separator ?? '' }
					onChange={ ( value ) => update( 'title_separator', value ) }
				/>
			</CardBody>
		</Card>
	);
}
