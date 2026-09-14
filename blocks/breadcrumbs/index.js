( function () {
	'use strict';

	const { registerBlockType, createBlock, getBlockType } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const registeredCore = typeof getBlockType === 'function' ? getBlockType( 'core/breadcrumbs' ) : null;
	const coreAvailable = registeredCore
		? true
		: !!( window.eranklyBreadcrumbsBlock && window.eranklyBreadcrumbsBlock.coreAvailable );

	function attributesForCoreBreadcrumbs( attributes ) {
		const next = {};

		if ( attributes && ( 'wide' === attributes.align || 'full' === attributes.align ) ) {
			next.align = attributes.align;
		}

		if ( attributes && 'string' === typeof attributes.className && '' !== attributes.className ) {
			next.className = attributes.className;
		}

		return next;
	}

	registerBlockType( 'easyrankly/breadcrumbs', {
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
			inserter: ! coreAvailable,
		},
		transforms: {
			to: [
				{
					type: 'block',
					blocks: [ 'core/breadcrumbs' ],
					isMatch: function () {
						return !! getBlockType( 'core/breadcrumbs' );
					},
					transform: function ( attributes ) {
						return createBlock(
							'core/breadcrumbs',
							attributesForCoreBreadcrumbs( attributes )
						);
					},
				},
			],
		},
		edit: function EditBreadcrumbs() {
			const blockProps = useBlockProps( {
				className: 'erankly-breadcrumbs-editor',
			} );

			return el(
				'nav',
				blockProps,
				el(
					'p',
					{ className: 'erankly-breadcrumbs-editor__hint' },
					__( 'Legacy breadcrumb trail. Prefer the WordPress Breadcrumbs block when it is available. The visible path is rendered on the front end.', 'easyrankly' )
				)
			);
		},
		save: function SaveBreadcrumbs() {
			return null;
		},
	} );
}() );
