(function () {
	'use strict';
	const config = window.eranklyMLSS;
	if (!config) return;
	async function request(path, data) {
		const response = await fetch(config.url + path, {
			method: data ? 'POST' : 'GET', credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, ...(data ? { 'Content-Type': 'application/json' } : {}) },
			...(data ? { body: JSON.stringify(data) } : {})
		});
		const result = await response.json();
		if (!response.ok) throw new Error(result.message || config.error);
		return result;
	}
	function init(box) {
		if (box.dataset.ready) return;
		box.dataset.ready = 'true';
		const { kind, id, subtype } = box.dataset;
		const own = box.querySelector('[name="erankly_mlss_language"]');
		const status = box.querySelector('[role="status"]');
		const rows = Array.from(box.querySelectorAll('[data-language]'));
		const notify = (message, error = false) => { status.textContent = message; status.classList.toggle('is-error', error); };
		function map() {
			const translations = {};
			rows.forEach(row => {
				const target = Number(row.querySelector('input[type="hidden"]').value);
				if (target && target !== Number(id)) translations[row.dataset.language] = target;
			});
			if (translations[own.value]) throw new Error(config.conflict);
			translations[own.value] = Number(id);
			return translations;
		}
		async function save() {
			return request('translations/' + kind + '/' + id, { translations: map() });
		}
		async function operate(action) {
			if (box.getAttribute('aria-busy') === 'true') return;
			const controls = Array.from(box.querySelectorAll('button, input, select'));
			box.setAttribute('aria-busy', 'true');
			controls.forEach(control => { control.dataset.wasDisabled = control.disabled ? '1' : '0'; control.disabled = true; });
			notify(config.busy);
			try { await action(); notify(config.saved); document.dispatchEvent(new CustomEvent('erankly-mlss-saved', { detail: { id: Number(id), language: own.value } })); }
			catch (error) { notify(error.message || config.error, true); }
			finally { box.removeAttribute('aria-busy'); controls.forEach(control => { control.disabled = control.dataset.wasDisabled === '1'; }); }
		}
		function select(row, object) {
			row.querySelector('input[type="hidden"]').value = object ? object.id : 0;
			row.querySelector('input[type="search"]').value = object ? object.title : '';
			row.querySelector('.erankly-mlss-results').replaceChildren();
			row.querySelector('.erankly-mlss-create').hidden = !!object;
			row.querySelector('.erankly-mlss-edit')?.remove();
			if (object && object.edit_url) {
				const link = document.createElement('a');
				link.className = 'button erankly-mlss-edit'; link.href = object.edit_url; link.textContent = config.edit;
				row.querySelector('p').prepend(link, document.createTextNode(' '));
			}
		}
		let previousLanguage = own.value;
		own.addEventListener('change', () => {
			try { map(); previousLanguage = own.value; rows.forEach(row => { row.hidden = row.dataset.language === own.value; }); }
			catch (error) { own.value = previousLanguage; notify(error.message, true); }
		});
		box.querySelector('.erankly-mlss-save')?.addEventListener('click', () => operate(save));
		rows.forEach(row => {
			const search = row.querySelector('input[type="search"]');
			const results = row.querySelector('.erankly-mlss-results');
			let timer, sequence = 0;
			search.addEventListener('input', () => {
				clearTimeout(timer); const version = ++sequence;
				row.querySelector('input[type="hidden"]').value = 0;
				row.querySelector('.erankly-mlss-edit')?.remove(); row.querySelector('.erankly-mlss-create').hidden = false;
				results.replaceChildren();
				if (search.value.trim().length < 2) return;
				timer = setTimeout(async () => {
					try {
						const objects = await request('objects?' + new URLSearchParams({ kind, subtype, language: row.dataset.language, search: search.value }));
						if (version !== sequence) return;
						results.replaceChildren();
						objects.filter(object => object.id !== Number(id)).forEach(object => {
							const button = document.createElement('button'); button.type = 'button'; button.className = 'button';
							button.textContent = object.title + ' (#' + object.id + ')';
							button.addEventListener('click', () => { ++sequence; select(row, object); }); results.append(button);
						});
					} catch (error) { if (version === sequence) notify(error.message, true); }
				}, 250);
			});
			row.querySelector('.erankly-mlss-unlink').addEventListener('click', () => { clearTimeout(timer); ++sequence; select(row, null); });
			row.querySelector('.erankly-mlss-create').addEventListener('click', () => operate(async () => {
				await save();
				const object = await request('create/' + kind + '/' + id, { language: row.dataset.language });
				select(row, object);
			}));
		});
	}
	const panel = window.wp?.editor?.PluginDocumentSettingPanel;
	if (config.postId && panel && window.wp?.plugins && window.wp?.element) {
		const { createElement: el, RawHTML, useState, useEffect } = window.wp.element;
		function TranslationsPanel() {
			const [html, setHTML] = useState('');
			const [error, setError] = useState('');
			useEffect(() => {
				let active = true;
				const refresh = async () => {
					try {
						const result = await request('editor/post/' + config.postId);
						if (active) { setHTML(result.html); setError(''); }
					} catch (failure) { if (active) setError(failure.message); }
				};
				const saved = event => {
					if (event.detail.id !== Number(config.postId)) return;
					window.wp.data?.dispatch('core/editor').editPost({ erankly_language: event.detail.language });
					refresh();
				};
				refresh(); document.addEventListener('erankly-mlss-saved', saved);
				return () => { active = false; document.removeEventListener('erankly-mlss-saved', saved); };
			}, []);
			return el(panel, { name: 'erankly-multilingual', title: config.panelTitle, className: 'erankly-panel' },
				error ? el('p', { role: 'alert' }, error) : (html ? el(RawHTML, null, html) : el('p', null, config.loading)));
		}
		window.wp.plugins.registerPlugin('erankly-multilingual', { render: TranslationsPanel });
	}
	const initialize = () => document.querySelectorAll('.erankly-mlss-editor').forEach(init);
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
	// Gutenberg may attach the native metabox after the main editor document loads.
	new MutationObserver(initialize).observe(document.body, { childList: true, subtree: true });
}());
