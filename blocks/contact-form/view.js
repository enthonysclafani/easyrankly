import { store, getContext, getElement } from '@wordpress/interactivity';

const widgets = new WeakMap();
const { state } = store('easyrankly/forms', {
	callbacks: {
		init() {
			const { ref: form } = getElement();
			const context = getContext();
			if (!context.turnstile) return;
			let attempts = 0;
			const init = () => {
				if (!form.isConnected || widgets.has(form)) return;
				if (window.turnstile && context.siteKey) {
					try { widgets.set(form, window.turnstile.render(form.querySelector('.erankly-form-turnstile'), {sitekey: context.siteKey, action: context.action})); }
					catch (_) { context.status = state.verification; }
				} else if (++attempts < 100) setTimeout(init, 100);
				else context.status = state.verification;
			};
			init();
		},
	},
	actions: {
		*submit(event) {
			event.preventDefault();
			const { ref: form } = getElement();
			const context = getContext();
			if (context.sending || !form.reportValidity()) return;
			context.sending = true;
			context.status = state.sending;
			for (const element of form.querySelectorAll('[aria-invalid]')) element.removeAttribute('aria-invalid');
			for (const element of form.querySelectorAll('.erankly-form-error')) element.textContent = '';
			const data = new FormData(form);
			const payload = { fields: {}, token: '', source: location.origin + location.pathname };
			for (const wrapper of form.querySelectorAll('[data-erankly-field]')) {
				const name = wrapper.dataset.eranklyField;
				payload.fields[name] = wrapper.querySelector('input[type=checkbox]') && wrapper.tagName === 'FIELDSET' ? data.getAll(name) : (data.get(name) || '');
			}
			for (const [name, value] of data) if (name.startsWith('erankly_hp_')) payload[name] = value;
			const widget = widgets.get(form);
			try {
				if (context.turnstile) {
					payload.token = widget !== undefined && window.turnstile ? window.turnstile.getResponse(widget) : '';
					if (!payload.token) { context.status = state.verification; return; }
				}
				const response = yield fetch(context.endpoint, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'omit', body: JSON.stringify(payload)});
				const result = yield response.json();
				context.status = result.message || state.error;
				if (response.ok && result.success) form.reset();
				else {
					let first;
					for (const wrapper of form.querySelectorAll('[data-erankly-field]')) {
						const message = result.errors && result.errors[wrapper.dataset.eranklyField];
						if (!message) continue;
						wrapper.querySelector('.erankly-form-error').textContent = message;
						for (const input of wrapper.querySelectorAll('input,select,textarea')) { input.setAttribute('aria-invalid', 'true'); first = first || input; }
					}
					if (first) first.focus();
				}
			} catch (_) { context.status = state.error; }
			finally {
				context.sending = false;
				if (context.turnstile && widget !== undefined && window.turnstile) window.turnstile.reset(widget);
			}
		},
	},
});
