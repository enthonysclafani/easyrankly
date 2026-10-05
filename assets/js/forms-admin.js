(function () {
	'use strict';
	const { __ } = wp.i18n;
	const form = document.getElementById('erankly-forms-settings');
	if (!form) return;
	const status = document.getElementById('erankly-forms-admin-status');
	const smtpFields = document.getElementById('erankly-forms-smtp-fields');
	const turnstileFields = document.getElementById('erankly-forms-turnstile-fields');
	const slackFields = document.getElementById('erankly-forms-slack-fields');
	const mailerliteFields = document.getElementById('erankly-forms-mailerlite-fields');
	const stylePreview = document.getElementById('erankly-forms-style-preview');
	const styleInputs = form.querySelectorAll('[data-style-variable]');
	function updateStylePreview() {
		if (!stylePreview) return;
		for (const input of styleInputs) {
			if (input.validity.valid) stylePreview.style.setProperty(input.dataset.styleVariable, input.value + input.dataset.styleUnit);
		}
		for (const output of form.querySelectorAll('[data-style-value]')) output.textContent = form.elements[output.dataset.styleValue].value;
	}
	function updateSmtpFields() { smtpFields.hidden = !form.elements.smtp_enabled.checked; }
	function updateTurnstileFields() { turnstileFields.hidden = !form.elements.turnstile_enabled.checked; }
	function updateSlackFields() { slackFields.hidden = !form.elements.slack_enabled.checked; }
	function updateMailerliteFields() { mailerliteFields.hidden = !form.elements.mailerlite_enabled.checked; }
	updateSmtpFields();
	updateTurnstileFields();
	updateSlackFields();
	updateMailerliteFields();
	updateStylePreview();
	let timer, pending = null, queued = false;
	function save() {
		if (pending) { queued = true; return pending; }
		pending = saveOnce().then(ok => {
			pending = null;
			if (queued) { queued = false; return save(); }
			return ok;
		});
		return pending;
	}
	async function saveOnce() {
		if (!form.reportValidity()) return false;
		const data = {};
		// Only named settings fields belong in the payload; skip the preview inputs.
		for (const input of form.querySelectorAll('input, select')) {
			if (!input.name) continue;
			if (!form.elements.turnstile_enabled.checked && ['turnstile_site_key', 'turnstile_secret_key'].includes(input.name)) continue;
			if (!form.elements.slack_enabled.checked && input.name === 'slack_webhook_url') continue;
			data[input.name] = input.type === 'checkbox' ? input.checked : input.value;
		}
		status.textContent = __('Saving…', 'easyrankly');
		let ok = false;
		try {
			const result = await wp.apiFetch({path: '/erankly/v1/forms/settings', method: 'POST', data});
			for (const [name, configured, toggle] of [['turnstile_secret_key', 'turnstile_secret_configured', 'turnstile_enabled'], ['slack_webhook_url', 'slack_configured', 'slack_enabled'], ['smtp_password', 'smtp_password_configured', null], ['mailerlite_api_key', 'mailerlite_api_key_configured', null]]) {
				const input = form.elements[name];
				const disabled = toggle && !data[toggle] && !form.elements[toggle].checked;
				if (input.value === data[name] || disabled) input.value = '';
				input.placeholder = result[configured] ? __('Configured — enter a replacement', 'easyrankly') : '';
			}
			for (const name of ['clear_smtp_password', 'clear_mailerlite_api_key']) if (form.elements[name].checked === data[name]) form.elements[name].checked = false;
			if (data.clear_mailerlite_api_key && form.elements.mailerlite_enabled.checked === data.mailerlite_enabled) { form.elements.mailerlite_enabled.checked = result.mailerlite_enabled; updateMailerliteFields(); }
			status.textContent = __('Settings saved.', 'easyrankly');
			ok = true;
		} catch (error) { status.textContent = error.message || __('Settings could not be saved.', 'easyrankly'); }
		return ok;
	}
	form.addEventListener('input', () => { updateSmtpFields(); updateTurnstileFields(); updateSlackFields(); updateMailerliteFields(); updateStylePreview(); clearTimeout(timer); timer = setTimeout(save, 900); });
	form.addEventListener('submit', event => { event.preventDefault(); clearTimeout(timer); save(); });
	const resetStyle = document.getElementById('erankly-forms-style-reset');
	if (resetStyle) resetStyle.addEventListener('click', () => {
		for (const input of styleInputs) input.value = input.dataset.styleDefault;
		updateStylePreview();
		clearTimeout(timer);
		save();
	});
	for (const channel of ['slack', 'smtp', 'mailerlite']) document.getElementById('erankly-forms-' + channel + '-test').addEventListener('click', async event => {
		clearTimeout(timer);
		event.target.disabled = true;
		try {
			if (!await save()) return;
			if (channel === 'slack' && !form.elements.slack_enabled.checked) return;
			if (channel === 'mailerlite' && !form.elements.mailerlite_enabled.checked) return;
			const result = await wp.apiFetch({path: '/erankly/v1/forms/' + channel + '-test', method: 'POST'});
			status.textContent = result.message;
		} catch (error) { status.textContent = error.message; }
		finally { event.target.disabled = false; }
	});
}());
