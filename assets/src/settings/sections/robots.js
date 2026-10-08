import {
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	TextareaControl,
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
						'Ask search engines not to index these pages (noindex) and leave them out of the sitemaps. Search results are never indexed. A single post or term can also be excluded from its own SEO settings.',
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
				<TextareaControl
					__nextHasNoMarginBottom
					className="code"
					label={ __( 'Extra robots.txt rules', 'easyrankly' ) }
					help={ __(
						'Appended to the robots.txt WordPress generates (which already lists the sitemap). Ignored while the site discourages search engines.',
						'easyrankly'
					) }
					rows={ 6 }
					value={ settings.robots_txt ?? '' }
					onChange={ ( value ) => update( 'robots_txt', value ) }
				/>
			</CardBody>
		</Card>
	);
}
