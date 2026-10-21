/**
 * EasyRankly > Import: each button runs the import of one plugin, one batch per REST request,
 * until the server says it is done. The server keeps the progress, so a closed tab resumes
 * where it stopped.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Runs the batches of one source.
 *
 * @param {HTMLButtonElement} button Button with the `data-easyrankly-import` slug.
 */
async function run( button ) {
	const source = button.dataset.easyranklyImport;
	const status = document.querySelector(
		`[data-easyrankly-import-status="${ source }"]`
	);
	let restart = button.dataset.restart === '1';

	button.disabled = true;
	status.textContent = button.dataset.running;

	try {
		let progress;
		do {
			progress = await apiFetch( {
				path: `/easyrankly/v1/import/${ source }`,
				method: 'POST',
				data: { restart },
			} );
			restart = false;
			status.textContent = progress.message;
		} while ( progress.stage !== 'done' );
		button.dataset.restart = '1';
	} catch {
		status.textContent = button.dataset.failed;
		button.dataset.restart = '0';
	}

	button.disabled = false;
}

document
	.querySelectorAll( '[data-easyrankly-import]' )
	.forEach( ( button ) =>
		button.addEventListener( 'click', () => run( button ) )
	);
