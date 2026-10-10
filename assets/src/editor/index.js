/**
 * SEO panels in the block editor, "Search appearance" and "Social sharing": they edit the
 * `_easyrankly_*` meta of the current post.
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
import { useEntityProp, store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const PREFIX = '_easyrankly_';

function SeoPanels() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const imageId = meta?.[ `${ PREFIX }og_image` ] || 0;
	const image = useSelect(
		( select ) =>
			imageId ? select( coreStore ).getMedia( imageId ) : undefined,
		[ imageId ]
	);

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
		<>
			<PluginDocumentSettingPanel
				name="easyrankly-search"
				title={ __( 'Search appearance', 'easyrankly' ) }
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
						__(
							'Empty uses the description template',
							'easyrankly'
						),
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
				</Flex>
			</PluginDocumentSettingPanel>
			<PluginDocumentSettingPanel
				name="easyrankly-social"
				title={ __( 'Social sharing', 'easyrankly' ) }
			>
				<Flex direction="column" gap={ 4 }>
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
							value={ imageId || undefined }
							onSelect={ ( media ) =>
								set( 'og_image' )( media.id )
							}
							render={ ( { open } ) => (
								<Flex direction="column" gap={ 3 }>
									{ !! image && (
										<img
											src={
												image.media_details?.sizes
													?.large?.source_url ??
												image.source_url
											}
											alt={ image.alt_text ?? '' }
											// 1.91:1 and centered crop, like the large cards of Facebook, LinkedIn and X.
											style={ {
												display: 'block',
												width: '100%',
												aspectRatio: '1.91 / 1',
												objectFit: 'cover',
												border: '1px solid #ddd',
												borderRadius: '2px',
												boxSizing: 'border-box',
											} }
										/>
									) }
									<Flex justify="flex-start" gap={ 3 }>
										<Button
											__next40pxDefaultSize
											variant="secondary"
											onClick={ open }
										>
											{ imageId
												? __(
														'Replace social image',
														'easyrankly'
													)
												: __(
														'Choose social image',
														'easyrankly'
													) }
										</Button>
										{ !! imageId && (
											<Button
												variant="link"
												isDestructive
												onClick={ () =>
													set( 'og_image' )( 0 )
												}
											>
												{ __( 'Remove', 'easyrankly' ) }
											</Button>
										) }
									</Flex>
								</Flex>
							) }
						/>
					</MediaUploadCheck>
				</Flex>
			</PluginDocumentSettingPanel>
		</>
	);
}

registerPlugin( 'easyrankly-seo', { render: SeoPanels } );
