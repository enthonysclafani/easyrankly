( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;

	blocks.registerBlockType( 'easyrankly/business-profile', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var controls = [
				[ 'showName', __( 'Show business name', 'easyrankly' ) ],
				[ 'showAddress', __( 'Show address', 'easyrankly' ) ],
				[ 'showPhone', __( 'Show telephone', 'easyrankly' ) ],
				[ 'showHours', __( 'Show opening hours', 'easyrankly' ) ],
				[ 'showGbp', __( 'Show Google Maps link', 'easyrankly' ) ]
			];

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Business details', 'easyrankly' ), initialOpen: true },
						controls.map( function ( control ) {
							var key = control[ 0 ];

							return el( ToggleControl, {
								key: key,
								label: control[ 1 ],
								checked: attributes[ key ],
								onChange: function ( value ) {
									var update = {};
									update[ key ] = value;
									props.setAttributes( update );
								}
							} );
						} )
					)
				),
				el( ServerSideRender, {
					block: 'easyrankly/business-profile',
					attributes: attributes,
					emptyResponsePlaceholder: el(
						'p',
						null,
						__( 'Complete and enable the profile under Settings → Local business.', 'easyrankly' )
					)
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.serverSideRender
);
