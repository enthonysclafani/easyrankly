import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const SLUG = /^(?!wp-)[a-z]{2,3}(-[a-z0-9]{2,8})?$/;
const MAX = 20;

/**
 * Rows edited on screen, from the setting (an object keyed by slug, in order).
 *
 * @param {Object} languages Setting value.
 * @return {Array} Rows { slug, locale, name }.
 */
function toRows( languages ) {
	return Object.entries( languages ?? {} ).map( ( [ slug, language ] ) => ( {
		slug,
		locale: language.locale ?? '',
		name: language.name ?? '',
	} ) );
}

/**
 * Problem that prevents saving the rows, or null.
 *
 * @param {Array} rows Rows.
 * @return {string|null} Message.
 */
function problem( rows ) {
	const slugs = rows.map( ( row ) => row.slug );
	if ( slugs.some( ( slug ) => ! SLUG.test( slug ) ) ) {
		return __(
			'Each URL prefix needs 2 or 3 lowercase letters, optionally followed by a hyphen and a region (for example en or pt-br).',
			'easyrankly'
		);
	}
	if ( new Set( slugs ).size !== slugs.length ) {
		return __(
			'Each language needs a different URL prefix.',
			'easyrankly'
		);
	}
	return null;
}

export default function Languages( { settings, update } ) {
	const [ rows, setRows ] = useState( () => toRows( settings.languages ) );
	const error = problem( rows );

	const change = ( next ) => {
		setRows( next );
		// Invalid rows stay on screen only: the setting keeps its last valid value.
		if ( ! problem( next ) ) {
			update(
				'languages',
				Object.fromEntries(
					next.map( ( { slug, locale, name } ) => [
						slug,
						{ locale, name },
					] )
				)
			);
		}
	};

	const set = ( index, key ) => ( value ) =>
		change(
			rows.map( ( row, i ) =>
				i === index ? { ...row, [ key ]: value } : row
			)
		);

	const move = ( index, offset ) => {
		const next = [ ...rows ];
		const [ row ] = next.splice( index, 1 );
		next.splice( index + offset, 0, row );
		change( next );
	};

	return (
		<Card className="easyrankly-settings__section">
			<CardHeader>
				<h2>{ __( 'Languages', 'easyrankly' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Add a second language to publish translations of your content. The first language is the default one: its addresses do not change. The others get their URL prefix, for example /en/.',
						'easyrankly'
					) }
				</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ rows.map( ( row, index ) => (
					<Flex key={ index } align="flex-end" gap={ 2 }>
						<FlexBlock>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={
									index === 0
										? __(
												'Name (default language)',
												'easyrankly'
											)
										: __( 'Name', 'easyrankly' )
								}
								maxLength={ 50 }
								value={ row.name }
								onChange={ set( index, 'name' ) }
							/>
						</FlexBlock>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'URL prefix', 'easyrankly' ) }
								value={ row.slug }
								onChange={ ( value ) =>
									set( index, 'slug' )( value.toLowerCase() )
								}
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Locale', 'easyrankly' ) }
								placeholder="en_US"
								value={ row.locale }
								onChange={ set( index, 'locale' ) }
							/>
						</FlexItem>
						<FlexItem>
							<Button
								size="compact"
								icon="arrow-up-alt2"
								label={ __( 'Move up', 'easyrankly' ) }
								disabled={ index === 0 }
								onClick={ () => move( index, -1 ) }
							/>
							<Button
								size="compact"
								icon="arrow-down-alt2"
								label={ __( 'Move down', 'easyrankly' ) }
								disabled={ index === rows.length - 1 }
								onClick={ () => move( index, 1 ) }
							/>
							<Button
								size="compact"
								isDestructive
								icon="trash"
								label={ __( 'Remove', 'easyrankly' ) }
								onClick={ () =>
									change(
										rows.filter( ( _, i ) => i !== index )
									)
								}
							/>
						</FlexItem>
					</Flex>
				) ) }
				<p>
					<Button
						variant="secondary"
						disabled={ rows.length >= MAX }
						onClick={ () =>
							change( [
								...rows,
								{ slug: '', locale: '', name: '' },
							] )
						}
					>
						{ __( 'Add language', 'easyrankly' ) }
					</Button>
				</p>
				<p className="description">
					{ __(
						'The locale (for example en_US or it_IT) sets the language of the pages and their hreflang code. Content without a language belongs to the default language.',
						'easyrankly'
					) }
				</p>
			</CardBody>
		</Card>
	);
}
