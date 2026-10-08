import {
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import useTypeContexts from '../use-type-contexts';

const CONTEXTS = [
	[ 'archive', __( 'Post type archives', 'easyrankly' ) ],
	[ 'term', __( 'Category, tag and term archives', 'easyrankly' ) ],
	[ 'author', __( 'Author archives', 'easyrankly' ) ],
	[ 'date', __( 'Date archives', 'easyrankly' ) ],
];

export default function Robots( { settings, update } ) {
	const typeContexts = useTypeContexts();
	const rules = settings.noindex ?? [];

	const toggle = ( key ) => ( checked ) =>
		update(
			'noindex',
			checked
				? [ ...rules, key ]
				: rules.filter( ( rule ) => rule !== key )
		);

	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>{ __( 'Search engines', 'easyrankly' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Ask search engines not to index these pages (noindex). Search results are never indexed. A single post or term can also be excluded from its own SEO settings.',
						'easyrankly'
					) }
				</p>
				{ [ ...CONTEXTS, ...typeContexts ].map( ( [ key, label ] ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ key }
						label={ label }
						checked={ rules.includes( key ) }
						onChange={ toggle( key ) }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}
