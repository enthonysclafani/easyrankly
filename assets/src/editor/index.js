/**
 * SEO panel in the block editor: edits the `_easyrankly_*` meta of the current post.
 *
 * Values are saved with the post through the core REST endpoint.
 */
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import {
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
	Flex,
} from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const PREFIX = '_easyrankly_';

function SeoPanel() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	if ( ! meta || ! ( `${ PREFIX }title` in meta ) ) {
		return null;
	}

	const value = ( name ) => meta[ PREFIX + name ];
	const set = ( name ) => ( next ) =>
		setMeta( { ...meta, [ PREFIX + name ]: next } );

	const text = ( name, label, placeholder, Control = TextControl ) => (
		<Control
			__next40pxDefaultSize={ Control === TextControl }
			__nextHasNoMarginBottom
			label={ label }
			placeholder={ placeholder }
			value={ value( name ) ?? '' }
			onChange={ set( name ) }
		/>
	);

	return (
		<PluginDocumentSettingPanel
			name="easyrankly-seo"
			title={ __( 'SEO', 'easyrankly' ) }
		>
			<Flex direction="column" gap={ 4 }>
				{ text(
					'title',
					__( 'SEO title', 'easyrankly' ),
					__( 'Empty uses the title template', 'easyrankly' )
				) }
				{ text(
					'description',
					__( 'Meta description', 'easyrankly' ),
					__( 'Empty uses the description template', 'easyrankly' ),
					TextareaControl
				) }
				{ text(
					'canonical',
					__( 'Canonical URL', 'easyrankly' ),
					__( 'Empty uses the permalink', 'easyrankly' )
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Do not index (noindex)', 'easyrankly' ) }
					checked={ !! value( 'noindex' ) }
					onChange={ set( 'noindex' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Do not follow links (nofollow)',
						'easyrankly'
					) }
					checked={ !! value( 'nofollow' ) }
					onChange={ set( 'nofollow' ) }
				/>
				{ text(
					'og_title',
					__( 'Social title', 'easyrankly' ),
					__( 'Empty uses the SEO title', 'easyrankly' )
				) }
				{ text(
					'og_description',
					__( 'Social description', 'easyrankly' ),
					__( 'Empty uses the meta description', 'easyrankly' ),
					TextareaControl
				) }
				<MediaUploadCheck>
					<MediaUpload
						allowedTypes={ [ 'image' ] }
						value={ value( 'og_image' ) || undefined }
						onSelect={ ( media ) => set( 'og_image' )( media.id ) }
						render={ ( { open } ) => (
							<div>
								<Button variant="secondary" onClick={ open }>
									{ value( 'og_image' )
										? __(
												'Replace social image',
												'easyrankly'
											)
										: __(
												'Choose social image',
												'easyrankly'
											) }
								</Button>{ ' ' }
								{ !! value( 'og_image' ) && (
									<Button
										variant="link"
										isDestructive
										onClick={ () => set( 'og_image' )( 0 ) }
									>
										{ __( 'Remove', 'easyrankly' ) }
									</Button>
								) }
							</div>
						) }
					/>
				</MediaUploadCheck>
			</Flex>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'easyrankly-seo', { render: SeoPanel } );
