/* global jQuery, wp */

( function ( $ ) {
	'use strict';

	var $input = $( '#erankly_social_default_image_id' ),
		$preview = $( '#erankly-social-image-preview' ),
		$previewWrap = $( '#erankly-social-image-preview-wrap' ),
		$choose = $( '#erankly-social-image-choose' ),
		$remove = $( '#erankly-social-image-remove' ),
		frame;

	$choose.on( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( {
				title: $choose.data( 'choose' ),
				library: { type: 'image' },
				button: { text: $choose.data( 'select' ) },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first(),
					attributes = attachment.toJSON(),
					preview = attributes.sizes && attributes.sizes.medium ? attributes.sizes.medium : attributes;

				$input[ 0 ].value = attachment.get( 'id' );
				$preview.attr( {
					src: preview.url,
					alt: attributes.alt || '',
				} ).removeClass( 'hidden' );
				$previewWrap.removeClass( 'hidden' );
				$choose.text( $choose.data( 'change' ) );
				$remove.removeClass( 'hidden' );
			} );
		}

		frame.open();
	} );

	$remove.on( 'click', function () {
		$input[ 0 ].value = 0;
		$preview.attr( { src: '', alt: '' } ).addClass( 'hidden' );
		$previewWrap.addClass( 'hidden' );
		$remove.addClass( 'hidden' );
		$choose.text( $choose.data( 'choose' ) ).trigger( 'focus' );
	} );
}( jQuery ) );
