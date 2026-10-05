(function (wp) {
	'use strict';
	if (!wp?.blocks || wp.blocks.getBlockType('easyrankly/language-switcher')) return;
	const el = wp.element.createElement;
	wp.blocks.registerBlockType('easyrankly/language-switcher', {
		apiVersion: 3, title: wp.i18n.__('Language switcher', 'easyrankly'), icon: 'translation', category: 'widgets',
		description: wp.i18n.__('Links to available translations of the current page.', 'easyrankly'),
		supports: { html: false },
		edit: function () {
			return el('div', wp.blockEditor.useBlockProps(), el(wp.components.Placeholder, {
				label: wp.i18n.__('Language switcher', 'easyrankly'), icon: 'translation'
			}, wp.i18n.__('Available language links appear on the published page.', 'easyrankly')));
		},
		save: function () { return null; }
	});
}(window.wp));
