(function () {
	'use strict';
	const { registerBlockType, registerBlockVariation } = wp.blocks;
	const { createElement: el, Fragment, useEffect } = wp.element;
	const { useBlockProps, InspectorControls, InspectorAdvancedControls } = wp.blockEditor;
	const { TextControl, TextareaControl, SelectControl, ToggleControl, PanelBody, Notice } = wp.components;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;
	const types = [['text', __('Text', 'easyrankly')], ['email', __('Email', 'easyrankly')], ['tel', __('Phone', 'easyrankly')], ['url', __('Website', 'easyrankly')], ['textarea', __('Message', 'easyrankly')], ['select', __('Select', 'easyrankly')], ['radio', __('Single choice', 'easyrankly')], ['checkboxes', __('Multiple choices', 'easyrankly')], ['consent', __('Consent', 'easyrankly')], ['newsletter_consent', __('Newsletter consent', 'easyrankly')]];
	const autocomplete = ['', 'off', 'name', 'given-name', 'family-name', 'email', 'tel', 'organization', 'url', 'street-address', 'postal-code', 'address-level2', 'country-name'];
	function flatten(blocks) { return blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]); }
	registerBlockType('easyrankly/form-field', {
		edit({attributes: a, setAttributes, clientId}) {
			const blocks = useSelect(select => flatten(select('core/block-editor').getBlocks()), []);
			const names = blocks.filter(b => b.name === 'easyrankly/form-field' && b.clientId !== clientId).map(b => b.attributes.name);
			useEffect(() => {
				if (a.name) return;
				const base = (a.label || 'field').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 65) || 'field';
				let name = base, n = 2;
				while (names.includes(name)) name = base + '_' + n++;
				setAttributes({name});
			}, [a.name, a.label, names.join('|')]);
			const duplicate = a.name && names.includes(a.name);
			const group = ['radio', 'checkboxes'].includes(a.type);
			const choices = a.type === 'select' || group;
			const consent = ['consent', 'newsletter_consent'].includes(a.type);
			const label = el('span', {}, a.label || __('Field', 'easyrankly'), a.required ? ' *' : '');
			let preview;
			if (a.type === 'textarea') preview = el('textarea', {disabled: true, placeholder: a.placeholder, rows: 5});
			else if (a.type === 'select') preview = el('select', {disabled: true}, el('option', {}, a.placeholder || __('Choose an option', 'easyrankly')), a.options.map((option, i) => el('option', {key: i}, option)));
			else if (group) preview = a.options.map((option, i) => el('label', {key: i}, el('input', {disabled: true, type: a.type === 'radio' ? 'radio' : 'checkbox'}), ' ' + option));
			else preview = el('input', {disabled: true, type: consent ? 'checkbox' : a.type, placeholder: a.placeholder});
			return el(Fragment, {},
				el(InspectorControls, {}, el(PanelBody, {title: __('Field settings', 'easyrankly')},
					el(SelectControl, {label: __('Type', 'easyrankly'), value: a.type, options: types.map(([value, label]) => ({value, label})), onChange: type => setAttributes({type})}),
					el(TextControl, {label: __('Label', 'easyrankly'), value: a.label, onChange: label => setAttributes({label})}),
					el(TextControl, {label: __('Placeholder', 'easyrankly'), value: a.placeholder, onChange: placeholder => setAttributes({placeholder})}),
					el(TextControl, {label: __('Help text', 'easyrankly'), value: a.help, onChange: help => setAttributes({help})}),
					el(ToggleControl, {label: __('Required', 'easyrankly'), checked: a.required, onChange: required => setAttributes({required})}),
					el(SelectControl, {label: __('Browser autocomplete', 'easyrankly'), value: a.autocomplete, options: autocomplete.map(value => ({value, label: value || __('None', 'easyrankly')})), onChange: value => setAttributes({autocomplete: value})}),
					choices && el(TextareaControl, {label: __('Options (one per line)', 'easyrankly'), value: a.options.join('\n'), onChange: value => setAttributes({options: value.split('\n')})})
				)),
				el(InspectorAdvancedControls, {}, el(TextControl, {label: __('Field name', 'easyrankly'), help: __('Unique within this form. Use letters, numbers, underscores or hyphens.', 'easyrankly'), value: a.name, onChange: value => setAttributes({name: value.toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 80)})})),
				el(group ? 'fieldset' : 'div', useBlockProps({className: 'erankly-form-field-editor' + (consent ? ' erankly-form-field--consent' : '')}),
					duplicate && el(Notice, {status: 'error', isDismissible: false}, __('Field names must be unique.', 'easyrankly')),
					consent ? el('label', {}, preview, label) : el(Fragment, {}, el(group ? 'legend' : 'label', {}, label), preview),
					el('small', {}, a.help))
			);
		},
		save() { return null; },
	});
	const variations = [
		['name', __('Name', 'easyrankly'), 'text', 'name'], ['email', __('Email', 'easyrankly'), 'email', 'email'],
		['phone', __('Phone', 'easyrankly'), 'tel', 'tel'], ['company', __('Company', 'easyrankly'), 'text', 'organization'],
		['website', __('Website', 'easyrankly'), 'url', 'url'], ['text', __('Text', 'easyrankly'), 'text', ''],
		['message', __('Message', 'easyrankly'), 'textarea', ''], ['select', __('Select', 'easyrankly'), 'select', ''],
		['radio', __('Single choice', 'easyrankly'), 'radio', ''], ['checkboxes', __('Multiple choices', 'easyrankly'), 'checkboxes', ''],
		['consent', __('Consent', 'easyrankly'), 'consent', ''], ['newsletter', __('Newsletter consent', 'easyrankly'), 'newsletter_consent', ''],
	];
	variations.forEach(([name, title, type, token]) => registerBlockVariation('easyrankly/form-field', {name, title, attributes: {type, label: type === 'newsletter_consent' ? __('I would like to receive the newsletter.', 'easyrankly') : title, autocomplete: token}, scope: ['inserter'], isActive: attributes => attributes.type === type && attributes.autocomplete === token}));
}());
