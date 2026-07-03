(function () {
	'use strict';

	function bindMediaUrlField(field) {
		var selectButton = field.querySelector('[data-easyrankly-select-media-url]');
		var clearButton = field.querySelector('[data-easyrankly-clear-media-url]');
		var input = field.querySelector('[data-easyrankly-media-url-input]');
		var idInput = field.querySelector('[data-easyrankly-media-url-id]');
		var preview = field.querySelector('[data-easyrankly-media-url-preview]');
		var frame;
		var isMediaSelection = false;

		if (!selectButton || !clearButton || !input || !window.wp || !window.wp.media) {
			return;
		}

		function updatePreview(url) {
			if (!preview) {
				return;
			}

			preview.innerHTML = '';

			if (!url || url.indexOf('{{') !== -1) {
				return;
			}

			var image = document.createElement('img');

			image.src = url;
			image.alt = '';
			preview.appendChild(image);
		}

		selectButton.addEventListener('click', function () {
			if (frame) {
				frame.open();
				return;
			}

			frame = window.wp.media({
				title: selectButton.textContent,
				button: {
					text: selectButton.textContent
				},
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var url = attachment.url || '';

				isMediaSelection = true;
				input.value = url;

				if (idInput) {
					idInput.value = attachment.id || '';
				}

				updatePreview(url);
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				isMediaSelection = false;
			});

			frame.open();
		});

		clearButton.addEventListener('click', function () {
			input.value = '';

			if (idInput) {
				idInput.value = '';
			}

			updatePreview('');
			input.dispatchEvent(new Event('input', { bubbles: true }));
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});

		input.addEventListener('input', function () {
			if (idInput && !isMediaSelection) {
				idInput.value = '';
			}

			updatePreview(input.value);
		});
	}

	function bindTabs(container) {
		var tabLists = Array.prototype.slice.call(container.querySelectorAll('.easyrankly-tabs'));

		tabLists.forEach(function (tabList) {
			var tabContainer = tabList.closest('[data-easyrankly-tabs-root]') || tabList.parentElement;
			var tabs = Array.prototype.slice.call(tabList.querySelectorAll('[data-easyrankly-tab]'));
			var panels = tabContainer ? Array.prototype.filter.call(tabContainer.children, function (child) {
				return child.hasAttribute('data-easyrankly-panel');
			}) : [];

			// setFocus=true moves keyboard focus to the newly activated tab (used for
			// keyboard navigation). setFocus=false leaves focus where it is (used for clicks).
			function activateTab(tab, setFocus) {
				if (tab.disabled || tab.getAttribute('aria-disabled') === 'true') {
					return;
				}

				var target = tab.getAttribute('data-easyrankly-tab');

				tabs.forEach(function (item) {
					var isActive = item === tab;

					item.classList.toggle('is-active', isActive);
					item.classList.toggle('nav-tab-active', isActive);
					item.setAttribute('aria-selected', isActive ? 'true' : 'false');
					// Roving tabindex: only the active tab participates in the Tab-key sequence.
					item.setAttribute('tabindex', isActive ? '0' : '-1');
				});

				panels.forEach(function (panel) {
					var isActive = panel.getAttribute('data-easyrankly-panel') === target;

					panel.classList.toggle('is-active', isActive);
					panel.hidden = !isActive;
				});

				if (setFocus) {
					tab.focus();
				}
			}

			// Initialise roving tabindex from the server-rendered active state.
			tabs.forEach(function (tab) {
				tab.setAttribute('tabindex', tab.classList.contains('is-active') ? '0' : '-1');
			});

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					activateTab(tab, false);
				});
			});

			// Keyboard navigation per the ARIA Tabs pattern:
			// ArrowRight / ArrowLeft move focus cyclically; Home / End jump to endpoints.
			tabList.addEventListener('keydown', function (e) {
				var key = e.key;

				if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'Home' && key !== 'End') {
					return;
				}

				var focusable = tabs.filter(function (t) {
					return !t.hidden && !t.disabled && t.getAttribute('aria-disabled') !== 'true';
				});

				if (focusable.length === 0) {
					return;
				}

				var current = focusable.indexOf(document.activeElement);
				var next;

				if (key === 'ArrowRight') {
					next = (current + 1) % focusable.length;
				} else if (key === 'ArrowLeft') {
					next = (current - 1 + focusable.length) % focusable.length;
				} else if (key === 'Home') {
					next = 0;
				} else {
					next = focusable.length - 1;
				}

				e.preventDefault();
				activateTab(focusable[next], true);
			});
		});
	}

	function bindSettingsTabs(root) {
		var tablist = root.querySelector('[data-easyrankly-settings-tablist]');

		if (!tablist) {
			return;
		}

		var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[data-easyrankly-tab]'));
		var panels = Array.prototype.slice.call(root.querySelectorAll('[data-easyrankly-settings-panel]'));
		var referer = root.querySelector('input[name="_wp_http_referer"]');
		var settingsSubmit = root.querySelector('[data-easyrankly-settings-submit]');

		// Keep the Settings API redirect on the active tab after "Save Changes".
		function syncReferer(target) {
			if (!referer) {
				return;
			}

			var base = referer.value
				.split('#')[0]
				.replace(/([?&])easyrankly_tab=[^&]*&?/, '$1')
				.replace(/[?&]$/, '');

			if (target === 'settings-features' || target === 'settings-health' || target === 'settings-redirects' || target === 'settings-import-export') {
				base += (base.indexOf('?') === -1 ? '?' : '&') + 'easyrankly_tab=' + target.replace('settings-', '');
			}

			referer.value = base;
		}

		// Keep the active-tab URL in sync so that F5 / reload restores the correct tab.
		// Uses replaceState (not pushState) to avoid polluting the browser history.
		function syncUrl(target) {
			if (!window.history || typeof window.history.replaceState !== 'function') {
				return;
			}

			var shortName = target.replace('settings-', '');
			var url = new URL(window.location.href);

			url.searchParams.set('easyrankly_tab', shortName);
			url.hash = '';
			history.replaceState(history.state, '', url.toString());
		}

		// setFocus=true moves keyboard focus to the tab button (for keyboard navigation).
		function activate(target, setFocus) {
			tabs.forEach(function (tab) {
				var isActive = tab.getAttribute('data-easyrankly-tab') === target;

				tab.classList.toggle('is-active', isActive);
				tab.classList.toggle('nav-tab-active', isActive);
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
				// Roving tabindex: only the active tab is reachable via Tab key.
				tab.setAttribute('tabindex', isActive ? '0' : '-1');
			});

			panels.forEach(function (panel) {
				var isActive = panel.getAttribute('data-easyrankly-settings-panel') === target;

				panel.classList.toggle('is-active', isActive);
				panel.hidden = !isActive;
			});

			if (settingsSubmit) {
				// Panels that carry their own form (the core's external panels and any
				// extension tab) opt out of the shared "Save Changes" button.
				var activePanel = panels.filter(function (panel) {
					return panel.getAttribute('data-easyrankly-settings-panel') === target;
				})[0];
				var standalone = activePanel ? activePanel.hasAttribute('data-easyrankly-standalone-panel') : false;

				settingsSubmit.hidden = standalone || target === 'settings-health' || target === 'settings-import-export' || target === 'settings-redirects' || target === 'settings-multilingual';
			}

			syncReferer(target);

			if (setFocus) {
				var activeTab = tablist.querySelector('[data-easyrankly-tab="' + target + '"]');

				if (activeTab) {
					activeTab.focus();
				}
			}
		}

		// Initialise roving tabindex from the server-rendered aria-selected state.
		tabs.forEach(function (tab) {
			tab.setAttribute('tabindex', tab.getAttribute('aria-selected') === 'true' ? '0' : '-1');
		});

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var target = tab.getAttribute('data-easyrankly-tab');

				activate(target, false);
				syncUrl(target);
			});
		});

		// Keyboard navigation per the ARIA Tabs pattern (§3.23):
		// ArrowRight / ArrowLeft cycle focus; Home / End jump to endpoints.
		tablist.addEventListener('keydown', function (e) {
			var key = e.key;

			if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'Home' && key !== 'End') {
				return;
			}

			var focusable = tabs.filter(function (t) {
				return !t.hidden && !t.disabled && t.getAttribute('aria-disabled') !== 'true';
			});

			if (focusable.length === 0) {
				return;
			}

			var current = focusable.indexOf(document.activeElement);
			var next;

			if (key === 'ArrowRight') {
				next = (current + 1) % focusable.length;
			} else if (key === 'ArrowLeft') {
				next = (current - 1 + focusable.length) % focusable.length;
			} else if (key === 'Home') {
				next = 0;
			} else {
				next = focusable.length - 1;
			}

			e.preventDefault();
			var nextTarget = focusable[next].getAttribute('data-easyrankly-tab');

			activate(nextTarget, true);
			syncUrl(nextTarget);
		});

		// Activate the server-requested panel on init. For panels that PHP already
		// renders as active (general, features, health, redirects, import-export) this
		// is a no-op in terms of visibility. For JS-only panels (social, schema,
		// sitemap, settings, advanced, bloat) it removes the hardcoded `hidden`
		// attribute so that a direct URL reload restores the correct tab.
		var initialPanel = tablist.getAttribute('data-easyrankly-active-panel');

		if (initialPanel) {
			activate(initialPanel, false);
		}

		root.easyranklyActivateSettingsTab = activate;
	}

	function bindSimplifiedMode(root) {
		var simplifiedMode = root.querySelector('input[name$="[simplified_mode]"]');
		var advancedTab = root.querySelector('[data-easyrankly-advanced-tab]');
		var advancedPanel = root.querySelector('[data-easyrankly-advanced-panel]');
		var customSchemaSection = root.querySelector('[data-easyrankly-custom-schema-section]');

		if (!simplifiedMode || !advancedTab || !advancedPanel) {
			return;
		}

		function syncAdvancedVisibility() {
			var isSimplified = advancedTab.hidden;

			if (customSchemaSection) {
				customSchemaSection.hidden = isSimplified;
			}

			if (isSimplified && advancedPanel.classList.contains('is-active') && typeof root.easyranklyActivateSettingsTab === 'function') {
				root.easyranklyActivateSettingsTab('settings-settings');
			}
		}

		syncAdvancedVisibility();
	}

	// Linked fields are matched by an explicit data-easyrankly-linked-field
	// attribute (flat setting names, e.g. social defaults) or by the [title] /
	// [description] suffix of nested names (post type and taxonomy defaults).
	function getLinkedFieldName(field) {
		var explicit = field.getAttribute('data-easyrankly-linked-field');

		if (explicit) {
			return explicit;
		}

		if (field.name.indexOf('[description]') !== -1) {
			return 'description';
		}

		if (field.name.indexOf('[title]') !== -1) {
			return 'title';
		}

		return '';
	}

	function getLinkedDefaultFields(container, fieldName) {
		return Array.prototype.slice.call(container.querySelectorAll('.easyrankly-default-tab-panel input, .easyrankly-default-tab-panel textarea')).filter(function (field) {
			return getLinkedFieldName(field) === fieldName;
		});
	}

	function getLinkedDefaultSource(container) {
		var activePanel = container.querySelector('.easyrankly-default-tab-panel.is-active');

		if (activePanel) {
			return activePanel;
		}

		return container.querySelector('.easyrankly-default-tab-panel');
	}

	function syncLinkedDefaultFields(container, sourceField) {
		var fieldName = (sourceField && getLinkedFieldName(sourceField)) || 'title';
		var fields = getLinkedDefaultFields(container, fieldName);
		var value = sourceField ? sourceField.value : '';

		fields.forEach(function (field) {
			if (field === sourceField || field.value === value) {
				return;
			}

			field.value = value;
			field.dispatchEvent(new Event('input', { bubbles: true }));
			field.dispatchEvent(new Event('change', { bubbles: true }));
		});
	}

	function setLinkedDefaultsState(container, isLinked, shouldSync) {
		var input = container.querySelector('[data-easyrankly-linked-input]');
		var toggle = container.querySelector('[data-easyrankly-linked-toggle]');
		var status = container.querySelector('[data-easyrankly-linked-status]');
		var action = container.querySelector('[data-easyrankly-linked-action]');
		var tabs = Array.prototype.slice.call(container.querySelectorAll('.easyrankly-tabs [data-easyrankly-tab]'));
		var panels = Array.prototype.slice.call(container.querySelectorAll('.easyrankly-default-tab-panel'));
		var source = getLinkedDefaultSource(container);
		var title = source ? source.querySelector('[data-easyrankly-linked-field="title"], [name*="[title]"]') : null;
		var description = source ? source.querySelector('[data-easyrankly-linked-field="description"], [name*="[description]"]') : null;
		var target = source ? source.getAttribute('data-easyrankly-panel') : '';
		var actionLabel = '';

		if (!target && panels.length > 0) {
			target = panels[0].getAttribute('data-easyrankly-panel');
		}

		container.classList.toggle('is-linked', isLinked);

		tabs.forEach(function (tab) {
			var isActive = !isLinked && tab.getAttribute('data-easyrankly-tab') === target;

			tab.disabled = isLinked;
			tab.setAttribute('aria-disabled', isLinked ? 'true' : 'false');
			tab.classList.toggle('is-active', isActive);
			tab.classList.toggle('nav-tab-active', isActive);
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');

			if (isLinked) {
				tab.setAttribute('tabindex', '-1');
			} else {
				tab.removeAttribute('tabindex');
			}
		});

		if (input) {
			input.value = isLinked ? '1' : '0';
		}

		if (toggle) {
			actionLabel = toggle.getAttribute(isLinked ? 'data-easyrankly-linked-action-on-label' : 'data-easyrankly-linked-action-off-label') || '';
			toggle.setAttribute('aria-label', actionLabel);
			toggle.setAttribute('title', actionLabel);
		}

		if (action) {
			action.textContent = actionLabel;
		}

		if (status && toggle) {
			status.textContent = isLinked ? toggle.getAttribute('data-easyrankly-linked-on-label') : toggle.getAttribute('data-easyrankly-linked-off-label');
		}

		if (isLinked && shouldSync) {
			if (title) {
				syncLinkedDefaultFields(container, title);
			}

			if (description) {
				syncLinkedDefaultFields(container, description);
			}
		}
	}

	function bindLinkedDefaults(container) {
		var toggle = container.querySelector('[data-easyrankly-linked-toggle]');
		var input = container.querySelector('[data-easyrankly-linked-input]');

		if (!toggle || !input) {
			return;
		}

		toggle.addEventListener('click', function () {
			setLinkedDefaultsState(container, !container.classList.contains('is-linked'), true);
		});

		container.querySelectorAll('.easyrankly-default-tab-panel input, .easyrankly-default-tab-panel textarea').forEach(function (field) {
			if (!getLinkedFieldName(field)) {
				return;
			}

			field.addEventListener('input', function () {
				if (container.classList.contains('is-linked')) {
					syncLinkedDefaultFields(container, field);
				}
			});
		});

		setLinkedDefaultsState(container, input.value !== '0', true);
	}

	function bindCharacterCounter(field) {
		var limit = parseInt(field.getAttribute('data-easyrankly-limit'), 10);
		var counterId = field.getAttribute('data-easyrankly-counter');
		var warning = field.getAttribute('data-easyrankly-warning') || 'too long';
		var counter = counterId ? document.getElementById(counterId) : null;

		if (!counter || !limit) {
			return;
		}

		function updateCounter() {
			var length = field.value.length;
			var isTooLong = length > limit;

			counter.textContent = isTooLong ? length + '/' + limit + ' - ' + warning : length + '/' + limit;
			counter.classList.toggle('is-warning', isTooLong);
		}

		field.addEventListener('input', updateCounter);
		updateCounter();
	}

	function closeVariablePicker(field) {
		var trigger = field.querySelector('[data-easyrankly-variable-trigger]');
		var menu = field.querySelector('[data-easyrankly-variable-menu]');

		if (!trigger || !menu) {
			return;
		}

		menu.hidden = true;
		trigger.setAttribute('aria-expanded', 'false');
	}

	function filterVariablePicker(field) {
		var search = field.querySelector('[data-easyrankly-variable-search]');
		var query = search ? search.value.trim().toLowerCase() : '';

		field.querySelectorAll('[data-easyrankly-variable-group]').forEach(function (group) {
			var hasVisibleOption = false;

			group.querySelectorAll('[data-easyrankly-variable]').forEach(function (option) {
				var haystack = option.getAttribute('data-easyrankly-variable-search-text') || '';
				var isVisible = !query || haystack.indexOf(query) !== -1;

				option.hidden = !isVisible;
				hasVisibleOption = hasVisibleOption || isVisible;
			});

			group.hidden = !hasVisibleOption;
		});
	}

	function insertVariable(control, variable) {
		var start = typeof control.selectionStart === 'number' ? control.selectionStart : control.value.length;
		var end = typeof control.selectionEnd === 'number' ? control.selectionEnd : start;

		control.value = control.value.slice(0, start) + variable + control.value.slice(end);
		control.focus();

		if (typeof control.setSelectionRange === 'function') {
			control.setSelectionRange(start + variable.length, start + variable.length);
		}

		control.dispatchEvent(new Event('input', { bubbles: true }));
		control.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function bindVariablePicker(field) {
		var control = field.querySelector('input:not([type="search"]), textarea');
		var trigger = field.querySelector('[data-easyrankly-variable-trigger]');
		var menu = field.querySelector('[data-easyrankly-variable-menu]');
		var search = field.querySelector('[data-easyrankly-variable-search]');

		if (!control || !trigger || !menu || field.getAttribute('data-easyrankly-variable-bound') === 'true') {
			return;
		}

		field.setAttribute('data-easyrankly-variable-bound', 'true');

		trigger.addEventListener('click', function (event) {
			var shouldOpen = menu.hidden;

			event.preventDefault();
			event.stopPropagation();
			document.querySelectorAll('[data-easyrankly-variable-field]').forEach(function (otherField) {
				if (otherField !== field) {
					closeVariablePicker(otherField);
				}
			});

			menu.hidden = !shouldOpen;
			trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

			if (shouldOpen && search) {
				search.value = '';
				filterVariablePicker(field);
				search.focus();
			}
		});

		menu.addEventListener('click', function (event) {
			var option = event.target ? event.target.closest('[data-easyrankly-variable]') : null;

			event.stopPropagation();

			if (!option) {
				return;
			}

			insertVariable(control, option.getAttribute('data-easyrankly-variable') || '');
			closeVariablePicker(field);
		});

		if (search) {
			search.addEventListener('input', function () {
				filterVariablePicker(field);
			});

			search.addEventListener('keydown', function (event) {
				var firstVisibleOption;

				if (event.key === 'Escape') {
					closeVariablePicker(field);
					control.focus();
					return;
				}

				if (event.key !== 'Enter') {
					return;
				}

				Array.prototype.some.call(field.querySelectorAll('[data-easyrankly-variable]'), function (option) {
					if (option.hidden) {
						return false;
					}

					firstVisibleOption = option;
					return true;
				});

				if (firstVisibleOption) {
					event.preventDefault();
					insertVariable(control, firstVisibleOption.getAttribute('data-easyrankly-variable') || '');
					closeVariablePicker(field);
				}
			});
		}
	}

	function bindVariablePickers(container) {
		container.querySelectorAll('[data-easyrankly-variable-field]').forEach(bindVariablePicker);
	}


	function setSchemaBlockExpanded(block, isExpanded) {
		block.open = isExpanded;
	}

	function updateSchemaBuilderState(builder) {
		var list = builder ? builder.querySelector('[data-easyrankly-schema-blocks]') : null;
		var hasBlocks = list && list.querySelector('[data-easyrankly-schema-block]');

		if (list) {
			list.classList.toggle('is-empty', !hasBlocks);
		}
	}

	function bindSchemaBlock(block) {
		var removeButton = block.querySelector('[data-easyrankly-remove-schema]');

		bindVariablePickers(block);

		if (removeButton) {
			removeButton.addEventListener('click', function (event) {
				event.stopPropagation();
				var builder = block.closest('[data-easyrankly-schema-builder]');

				block.remove();
				updateSchemaBuilderState(builder);
			});
		}
	}

	function bindSchemaBuilder(builder) {
		var list = builder.querySelector('[data-easyrankly-schema-blocks]');
		var template = builder.querySelector('[data-easyrankly-schema-template]');
		var addButton = builder.querySelector('[data-easyrankly-add-schema]');

		builder.querySelectorAll('[data-easyrankly-schema-block]').forEach(bindSchemaBlock);
		updateSchemaBuilderState(builder);

		if (!list || !template || !addButton) {
			return;
		}

		addButton.addEventListener('click', function () {
			var nextIndex = parseInt(builder.getAttribute('data-easyrankly-next-index'), 10) || 0;
			var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));

			list.insertAdjacentHTML('beforeend', html);
			builder.setAttribute('data-easyrankly-next-index', String(nextIndex + 1));
			bindSchemaBlock(list.lastElementChild);
			setSchemaBlockExpanded(list.lastElementChild, true);
			updateSchemaBuilderState(builder);
		});
	}

	function bindSchemaIdentityField(field) {
		var container = field.closest('.easyrankly-settings');
		var personField = container ? container.querySelector('[data-easyrankly-person-reference-field]') : null;
		var personDescription = container ? container.querySelector('[data-easyrankly-person-reference-description]') : null;
		var identityFields = container ? container.querySelector('[data-easyrankly-schema-identity-fields]') : null;

		if (!personField) {
			return;
		}

		function updatePersonField() {
			var isPerson = field.value === 'person';

			personField.hidden = !isPerson;

			if (personDescription) {
				personDescription.hidden = !isPerson;
			}

			if (identityFields) {
				identityFields.classList.toggle('is-person', isPerson);
			}

			syncOrganizationFieldsVisibility(container);
		}

		field.addEventListener('change', updatePersonField);
		updatePersonField();
	}

	function syncOrganizationFieldsVisibility(container) {
		var identity = container ? container.querySelector('[data-easyrankly-schema-identity]') : null;
		var localBusiness = container ? container.querySelector('[data-easyrankly-local-business-toggle]') : null;
		var showOrganizationFields = identity && identity.value !== 'person';

		if (!identity) {
			return;
		}

		container.querySelectorAll('[data-easyrankly-organization-only]').forEach(function (fields) {
			fields.hidden = !showOrganizationFields;
		});
	}

	function bindUserSearch(wrap) {
		var config = window.easyranklyUserSearch;

		if (!config || !config.restUrl || !config.nonce) {
			return;
		}

		var idInput    = wrap.querySelector('[data-easyrankly-user-id]');
		var selected   = wrap.querySelector('[data-easyrankly-user-selected]');
		var selectedName = wrap.querySelector('[data-easyrankly-user-selected-name]');
		var removeBtn  = wrap.querySelector('[data-easyrankly-user-remove]');
		var inputWrap  = wrap.querySelector('[data-easyrankly-user-search-input-wrap]');
		var searchInput = wrap.querySelector('[data-easyrankly-user-search-input]');
		var resultsList = wrap.querySelector('[data-easyrankly-user-results]');

		if (!idInput || !selected || !removeBtn || !inputWrap || !searchInput || !resultsList) {
			return;
		}

		var debounceTimer = null;
		var i18n = config.i18n || {};

		function closeResults() {
			resultsList.hidden = true;
			resultsList.innerHTML = '';
		}

		function selectUser(id, text) {
			idInput.value = id;
			if (selectedName) {
				selectedName.value = text;
			}
			selected.hidden = false;
			inputWrap.hidden = true;
			removeBtn.hidden = false;
			searchInput.value = '';
			closeResults();
		}

		function clearUser() {
			idInput.value = '0';
			if (selectedName) {
				selectedName.value = '';
			}
			selected.hidden = true;
			inputWrap.hidden = false;
			removeBtn.hidden = true;
			searchInput.value = '';
			closeResults();
			searchInput.focus();
		}

		function fetchResults(query) {
			var url = config.restUrl + '?q=' + encodeURIComponent(query);

			resultsList.hidden = false;
			resultsList.innerHTML = '<li class="easyrankly-autocomplete-status easyrankly-user-result-status">' + (i18n.searching || 'Searching…') + '</li>';

			fetch(url, {
				headers: { 'X-WP-Nonce': config.nonce },
				credentials: 'same-origin'
			})
				.then(function (res) { return res.ok ? res.json() : []; })
				.then(function (items) {
					resultsList.innerHTML = '';

					if (!items || items.length === 0) {
						resultsList.innerHTML = '<li class="easyrankly-autocomplete-status easyrankly-user-result-status">' + (i18n.noResults || 'No matches found.') + '</li>';
						return;
					}

					items.forEach(function (item) {
						var li = document.createElement('li');

						li.className = 'easyrankly-autocomplete-item easyrankly-user-result-item';
						li.setAttribute('role', 'option');
						li.setAttribute('tabindex', '-1');
						li.textContent = item.text;
						li.addEventListener('mousedown', function (e) {
							e.preventDefault();
							selectUser(item.id, item.text);
						});
						li.addEventListener('keydown', function (e) {
							if (e.key === 'Enter' || e.key === ' ') {
								e.preventDefault();
								selectUser(item.id, item.text);
							}
						});
						resultsList.appendChild(li);
					});
				})
				.catch(function () {
					closeResults();
				});
		}

		removeBtn.addEventListener('click', clearUser);

		searchInput.addEventListener('input', function () {
			clearTimeout(debounceTimer);
			var query = searchInput.value.trim();

			debounceTimer = setTimeout(function () {
				fetchResults(query);
			}, 300);
		});

		searchInput.addEventListener('focus', function () {
			if (resultsList.hidden) {
				fetchResults(searchInput.value.trim());
			}
		});

		searchInput.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeResults();
				return;
			}

			if (e.key !== 'ArrowDown') {
				return;
			}

			var first = resultsList.querySelector('[role="option"]');

			if (first) {
				e.preventDefault();
				first.focus();
			}
		});

		resultsList.addEventListener('keydown', function (e) {
			var items = Array.prototype.slice.call(resultsList.querySelectorAll('[role="option"]'));
			var current = items.indexOf(document.activeElement);

			if (e.key === 'ArrowDown') {
				e.preventDefault();
				if (current < items.length - 1) {
					items[current + 1].focus();
				}
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				if (current > 0) {
					items[current - 1].focus();
				} else {
					searchInput.focus();
				}
			} else if (e.key === 'Escape') {
				closeResults();
				searchInput.focus();
			}
		});

		document.addEventListener('click', function (e) {
			if (!wrap.contains(e.target)) {
				closeResults();
			}
		});
	}

	function bindBloatToggle(panel) {
		var advancedView = panel.querySelector('[data-easyrankly-bloat-view="advanced"]');
		var master = panel.querySelector('[data-easyrankly-bloat-master]');

		if (!advancedView || !master) {
			return;
		}

		// The master toggle only drives the cleanups marked as safe; the riskier
		// ones keep their saved state and stay advanced-mode only.
		function getSafeItems() {
			return Array.prototype.slice.call(advancedView.querySelectorAll('[data-easyrankly-bloat-safe]'));
		}

		function syncMasterFromItems() {
			var items = getSafeItems();
			master.checked = items.length > 0 && items.every(function (cb) { return cb.checked; });
		}

		master.addEventListener('change', function () {
			getSafeItems().forEach(function (cb) {
				cb.checked = master.checked;
			});
		});

		getSafeItems().forEach(function (cb) {
			cb.addEventListener('change', syncMasterFromItems);
		});

		syncMasterFromItems();
	}

	function bindLocalBusiness(container) {
		var toggle = container.querySelector('[data-easyrankly-local-business-toggle]');
		var fields = container.querySelector('[data-easyrankly-local-business-fields]');
		var type = container.querySelector('[data-easyrankly-local-business-type]');
		var foodFields = container.querySelector('[data-easyrankly-food-business-fields]');
		var foodTypes = ['Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'FoodEstablishment'];

		if (!toggle || !fields) {
			return;
		}

		function syncVisibility() {
			fields.hidden = !toggle.checked;
			syncOrganizationFieldsVisibility(container.closest('.easyrankly-settings'));

			if (type && foodFields) {
				foodFields.hidden = foodTypes.indexOf(type.value) === -1;
			}
		}

		toggle.addEventListener('change', syncVisibility);

		if (type) {
			type.addEventListener('change', syncVisibility);
		}

		container.querySelectorAll('[data-easyrankly-opening-day]').forEach(function (day) {
			var closed = day.querySelector('[data-easyrankly-day-closed]');
			var intervals = day.querySelector('[data-easyrankly-opening-intervals]');

			if (!closed || !intervals) {
				return;
			}

			function syncDay() {
				intervals.hidden = closed.checked;
			}

			closed.addEventListener('change', syncDay);
			syncDay();
		});

		syncVisibility();
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.easyrankly-meta-box').forEach(bindTabs);
		document.querySelectorAll('.easyrankly-settings').forEach(bindTabs);
		document.querySelectorAll('.easyrankly-settings').forEach(bindSettingsTabs);
		document.querySelectorAll('.easyrankly-settings').forEach(bindSimplifiedMode);
		document.querySelectorAll('[data-easyrankly-media-url-field]').forEach(bindMediaUrlField);
		document.querySelectorAll('.easyrankly-counted-field').forEach(bindCharacterCounter);
		bindVariablePickers(document);
		document.querySelectorAll('[data-easyrankly-linked-defaults]').forEach(bindLinkedDefaults);
		document.querySelectorAll('[data-easyrankly-schema-builder]').forEach(bindSchemaBuilder);
		document.querySelectorAll('[data-easyrankly-schema-identity]').forEach(bindSchemaIdentityField);
		document.querySelectorAll('[data-easyrankly-user-search-wrap]').forEach(bindUserSearch);
		document.querySelectorAll('[data-easyrankly-local-business]').forEach(bindLocalBusiness);
		var bloatPanel = document.getElementById('easyrankly-settings-panel-bloat');
		if (bloatPanel) {
			bindBloatToggle(bloatPanel);
		}

		document.addEventListener('click', function () {
			document.querySelectorAll('[data-easyrankly-variable-field]').forEach(closeVariablePicker);
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				document.querySelectorAll('[data-easyrankly-variable-field]').forEach(closeVariablePicker);
			}
		});
	});
}());
