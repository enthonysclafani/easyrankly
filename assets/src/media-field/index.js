/**
 * Image fields of the settings pages and of the term screens: "Choose image" and "Remove" buttons with the media
 * library modal. Without this script the field is a number input with the attachment ID.
 */

/**
 * Enhances one field.
 *
 * @param {HTMLElement} field Element with the `easyrankly-media-field` class.
 */
function enhance( field ) {
	const input = field.querySelector( 'input' );
	const preview = field.querySelector( '.easyrankly-media-field__preview' );
	if ( ! input || ! preview || ! window.wp?.media ) {
		return;
	}

	input.type = 'hidden';

	const choose = document.createElement( 'button' );
	choose.type = 'button';
	choose.className = 'button';

	const remove = document.createElement( 'button' );
	remove.type = 'button';
	remove.className = 'button-link button-link-delete';
	remove.textContent = field.dataset.remove;

	const refresh = () => {
		const hasImage = Number( input.value ) > 0;
		choose.textContent = hasImage
			? field.dataset.replace
			: field.dataset.choose;
		remove.hidden = ! hasImage;
	};

	let frame;
	choose.addEventListener( 'click', () => {
		frame =
			frame ||
			window.wp.media( {
				title: field.dataset.choose,
				library: { type: 'image' },
				multiple: false,
			} );
		frame.off( 'select' ).on( 'select', () => {
			const image = frame.state().get( 'selection' ).first().toJSON();
			const img = document.createElement( 'img' );
			img.src = image.sizes?.thumbnail?.url ?? image.url;
			img.alt = image.alt ?? '';
			preview.replaceChildren( img );
			input.value = String( image.id );
			refresh();
		} );
		frame.open();
	} );

	remove.addEventListener( 'click', () => {
		preview.replaceChildren();
		input.value = '0';
		refresh();
	} );

	const actions = document.createElement( 'p' );
	actions.append( choose, ' ', remove );
	field.append( actions );
	refresh();
}

document
	.querySelectorAll( '.easyrankly-media-field' )
	.forEach( ( field ) => enhance( field ) );
