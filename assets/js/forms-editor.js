(function () {
	'use strict';
	const { createElement: el, useEffect, useState, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { TextControl, ToggleControl, SelectControl, Notice, Button } = wp.components;
	function flatten(blocks) { return blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]); }
	function Settings() {
		const {meta, blocks} = wp.data.useSelect(select => ({meta: select('core/editor').getEditedPostAttribute('meta') || {}, blocks: flatten(select('core/block-editor').getBlocks())}), []);
		const settings = meta._erankly_form_settings || {};
		const [groups, setGroups] = useState([]);
		const [groupError, setGroupError] = useState('');
		const [loadingGroups, setLoadingGroups] = useState(false);
		const fields = blocks.filter(b => b.name === 'easyrankly/form-field');
		const names = fields.map(b => b.attributes.name);
		const invalid = fields.length > 30 || names.some(name => !name || name.startsWith('erankly_hp_')) || new Set(names).size !== names.length;
		const recipients = settings.recipients || [];
		const invalidRecipients = recipients.length > 10 || recipients.some(email => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
		const emailFields = fields.filter(b => b.attributes.type === 'email' && b.attributes.required);
		const nameFields = fields.filter(b => (b.attributes.type || 'text') === 'text');
		const consentFields = fields.filter(b => b.attributes.type === 'newsletter_consent');
		const emailField = settings.mailerlite_email_field === undefined ? 'email' : settings.mailerlite_email_field;
		const consentField = settings.mailerlite_consent_field === undefined ? 'newsletter' : settings.mailerlite_consent_field;
		const invalidMailerlite = settings.mailerlite && (!/^[1-9][0-9]{0,31}$/.test(settings.mailerlite_group_id || '') || !emailFields.some(b => b.attributes.name === emailField) || !consentFields.some(b => b.attributes.name === consentField) || (settings.mailerlite_name_field && !nameFields.some(b => b.attributes.name === settings.mailerlite_name_field)));
		const {editPost, lockPostSaving, unlockPostSaving} = wp.data.useDispatch('core/editor');
		useEffect(() => {
			if (invalid || invalidRecipients || invalidMailerlite) lockPostSaving('erankly-form-schema'); else unlockPostSaving('erankly-form-schema');
			return () => unlockPostSaving('erankly-form-schema');
		}, [invalid, invalidRecipients, invalidMailerlite]);
		useEffect(() => {
			if (!settings.mailerlite) return;
			let active = true;
			setLoadingGroups(true);
			wp.apiFetch({path: '/erankly/v1/forms/mailerlite-groups'}).then(result => {
				if (active) { setGroups(result.groups); setGroupError(''); }
			}).catch(error => { if (active) setGroupError(error.message); }).finally(() => { if (active) setLoadingGroups(false); });
			return () => { active = false; };
		}, [!!settings.mailerlite]);
		function change(key, value) { editPost({meta: {...meta, _erankly_form_settings: {...settings, [key]: value}}}); }
		function addNewsletterConsent() {
			let name = 'newsletter', number = 2;
			while (names.includes(name)) name = 'newsletter_' + number++;
			wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('easyrankly/form-field', {type: 'newsletter_consent', name, label: __('I would like to receive the newsletter.', 'easyrankly'), required: false}), wp.data.select('core/block-editor').getBlocks().length);
			change('mailerlite_consent_field', name);
		}
		const mappedOptions = (items, empty) => [{value: '', label: empty}, ...items.map(b => ({value: b.attributes.name, label: b.attributes.label || b.attributes.name}))];
		const groupOptions = [{value: '', label: loadingGroups ? __('Loading groups…', 'easyrankly') : __('Choose a group', 'easyrankly')}, ...groups.map(group => ({value: group.id, label: group.name}))];
		if (settings.mailerlite_group_id && !groups.some(group => group.id === settings.mailerlite_group_id)) groupOptions.push({value: settings.mailerlite_group_id, label: __('Saved group', 'easyrankly') + ' · ' + settings.mailerlite_group_id});
		const Panel = wp.editor.PluginDocumentSettingPanel || wp.editPost.PluginDocumentSettingPanel;
		return el(Panel, {className: 'erankly-panel erankly-panel--forms', name: 'erankly-form-settings', title: __('Form settings', 'easyrankly')},
			invalid && el(Notice, {status: 'error', isDismissible: false}, __('Use up to 30 fields with unique names.', 'easyrankly')),
			invalidRecipients && el(Notice, {status: 'error', isDismissible: false}, __('Enter up to 10 valid email recipients.', 'easyrankly')),
			el(TextControl, {label: __('Email recipients', 'easyrankly'), help: __('Separate with commas. Leave empty to use the site administrator email.', 'easyrankly'), value: recipients.join(', '), onChange: value => change('recipients', value.split(',').map(v => v.trim()).filter(Boolean))}),
			el(TextControl, {label: __('Email subject', 'easyrankly'), help: __('Use {field_name} to include a submitted value.', 'easyrankly'), value: settings.subject || '', onChange: value => change('subject', value)}),
			el(ToggleControl, {label: __('Send to Slack', 'easyrankly'), checked: !!settings.slack, onChange: value => change('slack', value)}),
			el(ToggleControl, {label: __('Subscribe to MailerLite', 'easyrankly'), checked: !!settings.mailerlite, onChange: value => change('mailerlite', value)}),
			settings.mailerlite && el(Fragment, {},
				groupError && el(Notice, {status: 'warning', isDismissible: false}, groupError, window.eranklyFormsSettings && el('a', {href: window.eranklyFormsSettings.settingsUrl}, __('Open Forms settings', 'easyrankly'))),
				el(SelectControl, {label: __('MailerLite group', 'easyrankly'), value: settings.mailerlite_group_id || '', options: groupOptions, onChange: value => change('mailerlite_group_id', value)}),
				el(SelectControl, {label: __('Subscriber email', 'easyrankly'), help: __('Choose a required email field.', 'easyrankly'), value: emailField, options: mappedOptions(emailFields, __('Choose an email field', 'easyrankly')), onChange: value => change('mailerlite_email_field', value)}),
				el(SelectControl, {label: __('Subscriber name (optional)', 'easyrankly'), value: settings.mailerlite_name_field || '', options: mappedOptions(nameFields, __('Do not send a name', 'easyrankly')), onChange: value => change('mailerlite_name_field', value)}),
				el(SelectControl, {label: __('Newsletter consent', 'easyrankly'), help: __('Subscribe only when this checkbox is selected.', 'easyrankly'), value: consentField, options: mappedOptions(consentFields, __('Choose a newsletter consent field', 'easyrankly')), onChange: value => change('mailerlite_consent_field', value)}),
				!consentFields.length && el(Button, {variant: 'secondary', onClick: addNewsletterConsent}, __('Add newsletter consent', 'easyrankly')),
				invalidMailerlite && el(Notice, {status: 'warning', isDismissible: false}, __('Complete the MailerLite group and field selections before saving this Form.', 'easyrankly'))
			),
			el(TextControl, {label: __('Submit button label', 'easyrankly'), value: settings.submit_label || '', onChange: value => change('submit_label', value)}),
			el(TextControl, {label: __('Success message', 'easyrankly'), value: settings.success_message || '', onChange: value => change('success_message', value)})
		);
	}
	wp.plugins.registerPlugin('erankly-form-settings', {render: Settings});
}());
