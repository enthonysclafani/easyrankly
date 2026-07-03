(function () {
	'use strict';

	// Delete confirmation.
	document.addEventListener('click', function (event) {
		var link = event.target.closest('.easyrankly-redirects-delete');

		if (!link) {
			return;
		}

		var message = window.easyranklyRedirects && window.easyranklyRedirects.deleteConfirm ? window.easyranklyRedirects.deleteConfirm : 'Delete this redirect?';

		if (!window.confirm(message)) {
			event.preventDefault();
		}
	});

	// Show/hide the "Required role" field based on the "Apply to" select.
	function syncRoleField() {
		var visibilitySelect = document.getElementById('easyrankly-redirects-visibility');
		var roleField = document.getElementById('easyrankly-redirects-role-field');

		if (!visibilitySelect || !roleField) {
			return;
		}

		roleField.style.display = visibilitySelect.value === 'logged_in' ? '' : 'none';
	}

	syncRoleField();

	var sel = document.getElementById('easyrankly-redirects-visibility');
	if (sel) {
		sel.addEventListener('change', syncRoleField);
	}
}());
