/**
 * Language switcher block. It is rendered on the server (no frontend script): the editor
 * shows a preview built from the configured languages.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

function Edit() {
	const blockProps = useBlockProps();
	// Read at render: the languages may be printed with another editor script.
	const languages = window.easyrankly?.languages ?? [];

	return (
		<nav { ...blockProps } aria-label={ __( 'Languages', 'easyrankly' ) }>
			{ languages.length < 2 ? (
				<p>
					{ __(
						'Add a second language in EasyRankly settings to show the language switcher.',
						'easyrankly'
					) }
				</p>
			) : (
				<ul>
					{ languages.map( ( language ) => (
						<li key={ language.slug }>
							{ /* eslint-disable-next-line jsx-a11y/anchor-is-valid */ }
							<a
								href="#"
								onClick={ ( event ) => event.preventDefault() }
							>
								{ language.name }
							</a>
						</li>
					) ) }
				</ul>
			) }
		</nav>
	);
}

registerBlockType( 'easyrankly/language-switcher', {
	edit: Edit,
	save: () => null,
} );
