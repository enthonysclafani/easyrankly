/* global wp, eranklyMlmsEditorPanel */
/**
 * "Linked translations" document settings panel for the block editor.
 *
 * Rendered with the shared EasyRankly panel chrome (.erankly-panel classes) so it looks exactly like the
 * "Search appearance" / "Search visibility" panels, and positioned last by the shared panel-ordering logic.
 * Edits write the REST-registered translation map through the post meta API, so the links are saved with the
 * normal Update button and the meta-write hooks synchronize the counterpart sites.
 */
( function () {
	'use strict';

	const config = typeof window.eranklyMlmsEditorPanel === 'object' ? window.eranklyMlmsEditorPanel : null;

	if ( ! config || ! Array.isArray( config.sites ) ) {
		return;
	}

	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	const { createElement: el } = wp.element;
	const { useDispatch, useSelect } = wp.data;
	const { SelectControl } = wp.components;
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const META_KEY = '_erankly_mlms_translations';

	function TranslationsPanel() {
		const meta = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
			[]
		);
		const { editPost } = useDispatch( 'core/editor' );

		const readMap = () => {
			const raw = meta[ META_KEY ];

			return raw && typeof raw === 'object' && ! Array.isArray( raw ) ? { ...raw } : {};
		};

		const setLinked = ( blogId, rawValue ) => {
			const next = readMap();
			const value = parseInt( rawValue, 10 );

			if ( value > 0 ) {
				next[ String( blogId ) ] = value;
			} else {
				delete next[ String( blogId ) ];
			}

			editPost( { meta: { [ META_KEY ]: next } } );
		};

		const rows = config.sites.map( ( site ) => {
			const current = parseInt( meta[ META_KEY ]?.[ String( site.blogId ) ], 10 ) || 0;
			const label = site.name + ' (' + site.hreflang + ')';

			if ( ! site.canEdit ) {
				return el(
					'div',
					{ key: 'erankly-mlms-site-' + site.blogId, className: 'erankly-field' },
					el( 'p', { className: 'components-base-control__label erankly-mlms-site-label' }, label ),
					current > 0
						? el( 'p', { className: 'components-base-control__help' },
							__( 'Linked (read-only: your account cannot edit that site).', 'easyrankly' ) )
						: el( 'p', { className: 'components-base-control__help' },
							__( 'Your account cannot edit that site, so no link can be created from here.', 'easyrankly' ) )
				);
			}

			return el( SelectControl, {
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
				disabled: ! site.choices.length,
				key: 'erankly-mlms-site-' + site.blogId,
				label,
				onChange: ( value ) => setLinked( site.blogId, value ),
				options: [
					{ label: __( '— None —', 'easyrankly' ), value: '0' },
				].concat(
					site.choices.map( ( choice ) => ( {
						label: choice.label,
						value: String( choice.id ),
					} ) )
				),
				value: String( current ),
			} );
		} );

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--translations',
				name: 'erankly-mlms-translations',
				title: __( 'Linked translations', 'easyrankly' ),
			},
			el(
				'p',
				{ className: 'erankly-mlms-panel-note' },
				__(
					'Connect the equivalent content on the other sites of the network. Links are kept in sync on both sides.',
					'easyrankly'
				)
			),
			config.isFrontPage
				? el(
					'p',
					{ className: 'erankly-mlms-panel-note' },
					__(
						'This is the static front page: the front pages of the participating sites are always cross-linked automatically.',
						'easyrankly'
					)
				)
				: null,
			...rows
		);
	}

	registerPlugin( 'erankly-mlms-translations', {
		render: TranslationsPanel,
	} );
}() );
