(function () {
	'use strict';
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { SelectControl, PanelBody } = wp.components;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	wp.blocks.registerBlockType('easyrankly/contact-form', {
		icon: el('svg', {width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', style: {fill: 'none'}, stroke: 'currentColor', strokeWidth: 1.5, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false},
			el('rect', {x: 4, y: 4, width: 16, height: 4, rx: 1}),
			el('rect', {x: 4, y: 11, width: 16, height: 4, rx: 1}),
			el('rect', {x: 4, y: 18, width: 6, height: 3, rx: 1, fill: 'currentColor'})
		),
		edit({attributes, setAttributes}) {
			const forms = wp.data.useSelect(select => select('core').getEntityRecords('postType', 'erankly_form', {per_page: 100, status: 'publish', context: 'edit'}), []);
			const options = [{value: 0, label: __('Choose a form', 'easyrankly')}].concat((forms || []).map(form => ({value: form.id, label: form.title.raw || __('Untitled form', 'easyrankly')})));
			const selectProps = {label: __('Form', 'easyrankly'), value: attributes.ref, options, onChange: value => setAttributes({ref: Number(value)})};
			const editUrl = window.eranklyFormsEditor && window.eranklyFormsEditor.editUrl;
			return el(Fragment, {}, el(InspectorControls, {}, el(PanelBody, {title: __('Form', 'easyrankly')}, el(SelectControl, selectProps))),
				el('div', useBlockProps(),
					attributes.ref ? el(Fragment, {}, el('div', {inert: '', style: {pointerEvents: 'none'}}, el(wp.serverSideRender, {block: 'easyrankly/contact-form', attributes})), el('a', {href: (editUrl || 'post.php') + '?post=' + attributes.ref + '&action=edit'}, __('Edit form', 'easyrankly'))) : el(SelectControl, {...selectProps, className: 'erankly-form-picker'})));
		},
		save() { return null; },
	});
}());
