( function ( wp, settings ) {
	'use strict';

	const {
		Button,
		Dropdown,
		Fill,
		Modal,
		RadioControl,
		TabPanel,
		TextareaControl,
		__experimentalHStack: HStack,
		__experimentalVStack: VStack,
	} = wp.components;
	const {
		__experimentalInspectorPopoverHeader: InspectorPopoverHeader,
	} = wp.blockEditor;
	const apiFetch = wp.apiFetch;
	const { useEntityProp } = wp.coreData;
	const { useDispatch, useSelect } = wp.data;
	const {
		PluginDocumentSettingPanel,
		PluginMoreMenuItem,
	} = wp.editor;
	const { createElement: el, Fragment, useMemo, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { store: noticesStore } = wp.notices;
	const { registerPlugin } = wp.plugins;

	const canEditCode = Boolean( settings.canEditCode );
	const canEditGlobalCode = Boolean( settings.canEditGlobalCode );
	const descriptionMetaKey = settings.descriptionMetaKey;
	const globalOptionKeys = {
		head: settings.globalHeadOptionKey,
		bodyStart: settings.globalBodyStartOptionKey,
		bodyEnd: settings.globalBodyEndOptionKey,
	};
	const initialGlobalCode = {
		head:
			typeof settings.globalHeadCode === 'string'
				? settings.globalHeadCode
				: '',
		bodyStart:
			typeof settings.globalBodyStartCode === 'string'
				? settings.globalBodyStartCode
				: '',
		bodyEnd:
			typeof settings.globalBodyEndCode === 'string'
				? settings.globalBodyEndCode
				: '',
	};
	const metaKeys = {
		head: settings.headMetaKey,
		bodyStart: settings.bodyStartMetaKey,
		bodyEnd: settings.bodyEndMetaKey,
	};
	const visibilityMetaKey = settings.visibilityMetaKey;
	const codeLocations = [
		{
			key: 'head',
			title: __( 'Head', 'easyrankly' ),
		},
		{
			key: 'bodyStart',
			title: __( 'Body start', 'easyrankly' ),
		},
		{
			key: 'bodyEnd',
			title: __( 'Body end', 'easyrankly' ),
		},
	];
	const visibilityChoices = [
		{
			description: __(
				'Leave the final decision to WordPress and installed SEO plugins.',
				'easyrankly'
			),
			label: __( 'Index', 'easyrankly' ),
			value: 'index',
		},
		{
			description: __(
				'Ask search engines not to show this content.',
				'easyrankly'
			),
			label: __( 'Noindex', 'easyrankly' ),
			value: 'noindex',
		},
	];

	/**
	 * Groups modal controls like a section in WordPress Preferences.
	 *
	 * @param {Object}  props             Component properties.
	 * @param {Element} props.children    Section controls.
	 * @param {string}  props.description Section description.
	 * @param {string}  props.title       Section heading.
	 * @return {Element} Preferences-style section.
	 */
	function ModalSection( { children, description, title } ) {
		return el(
			'fieldset',
			{ className: 'preferences-modal__section' },
			( title || description ) &&
				el(
					'legend',
					{ className: 'preferences-modal__section-legend' },
					title &&
						el(
							'h2',
							{ className: 'preferences-modal__section-title' },
							title
						),
					description &&
						el(
							'p',
							{ className: 'preferences-modal__section-description' },
							description
						)
				),
			el(
				'div',
				{ className: 'preferences-modal__section-content' },
				children
			)
		);
	}

	/**
	 * Renders current-content and global code controls.
	 *
	 * @param {Object}   props                     Component properties.
	 * @param {Object}   props.currentCode         Current-content code by location.
	 * @param {Object}   props.globalCode          Site-wide code by location.
	 * @param {boolean}  props.isGlobalDirty       Whether global code changed.
	 * @param {boolean}  props.isSavingGlobal      Whether global code is saving.
	 * @param {Function} props.onChangeCurrentCode Updates current-content code.
	 * @param {Function} props.onChangeGlobalCode  Updates global code.
	 * @param {Function} props.onClose             Closes the modal.
	 * @param {Function} props.onSaveGlobal        Saves global code.
	 * @return {Element} Advanced-code modal.
	 */
	function CodeModal( {
		currentCode,
		globalCode,
		isGlobalDirty,
		isSavingGlobal,
		onChangeCurrentCode,
		onChangeGlobalCode,
		onClose,
		onSaveGlobal,
	} ) {
		const tabs = [];

		tabs.push( {
			name: 'current-post',
			title: __( 'Current post', 'easyrankly' ),
		} );

		if ( canEditGlobalCode ) {
			tabs.push( {
				name: 'global',
				title: __( 'Global', 'easyrankly' ),
			} );
		}

		return el(
			Modal,
			{
				className: 'preferences-modal',
				closeButtonLabel: __( 'Close Custom code', 'easyrankly' ),
				onRequestClose: onClose,
				title: __( 'Custom code', 'easyrankly' ),
			},
			el(
				TabPanel,
				{
					className: 'erankly-tabs',
					initialTabName: 'current-post',
					orientation: 'vertical',
					tabs,
				},
				( tab ) => {
					const isGlobal = 'global' === tab.name;
					const code = isGlobal ? globalCode : currentCode;
					const onChange = isGlobal
						? onChangeGlobalCode
						: onChangeCurrentCode;

					return el(
						Fragment,
						null,
						codeLocations.map( ( location ) =>
							el(
								ModalSection,
								{
									key: location.key,
								},
								el( TextareaControl, {
									label: location.title,
									name: `erankly-${
										isGlobal ? 'global-' : ''
									}${ location.key }`,
										onChange: ( value ) =>
											onChange( location.key, value ),
										rows: 8,
									spellCheck: false,
									value: code[ location.key ],
								} )
							)
						),
						isGlobal &&
							el(
								HStack,
								{ justify: 'flex-end' },
								el(
									Button,
									{
										accessibleWhenDisabled: true,
										disabled:
											isSavingGlobal || ! isGlobalDirty,
										isBusy: isSavingGlobal,
										onClick: onSaveGlobal,
										variant: 'primary',
									},
									__( 'Save', 'easyrankly' )
								)
							)
					);
				}
			)
		);
	}

	/**
	 * Renders search indexing like WordPress' native Status summary row.
	 *
	 * @param {Object}   props          Component properties.
	 * @param {Function} props.onChange Updates the indexing value.
	 * @param {string}   props.value    Current indexing value.
	 * @return {Element} Indexing summary control.
	 */
	function IndexingControl( { onChange, value } ) {
		const [ popoverAnchor, setPopoverAnchor ] = useState( null );
		const popoverProps = useMemo(
			() => ( {
				anchor: popoverAnchor,
				'aria-label': __( 'Indexing', 'easyrankly' ),
				headerTitle: __( 'Indexing', 'easyrankly' ),
				offset: 36,
				placement: 'left-start',
				shift: true,
			} ),
			[ popoverAnchor ]
		);
		const currentChoice =
			visibilityChoices.find( ( choice ) => choice.value === value ) ||
			visibilityChoices[ 0 ];

		return el(
			Fill,
			{ name: 'PluginPostStatusInfo' },
			el(
				HStack,
				{
					className: 'editor-post-panel__row',
					ref: setPopoverAnchor,
				},
				el(
					'div',
					{ className: 'editor-post-panel__row-label' },
					__( 'Indexing', 'easyrankly' )
				),
				el(
					'div',
					{ className: 'editor-post-panel__row-control' },
					el( Dropdown, {
						className: 'editor-post-status',
						contentClassName: 'editor-change-status__content',
						focusOnMount: true,
						popoverProps,
						renderToggle: ( { isOpen, onToggle } ) =>
							el(
								Button,
								{
									'aria-expanded': isOpen,
									'aria-label': sprintf(
										/* translators: %s: Current indexing setting. */
										__( 'Change indexing: %s', 'easyrankly' ),
										currentChoice.label
									),
									className: 'editor-post-status__toggle',
									onClick: onToggle,
									size: 'compact',
									variant: 'tertiary',
								},
								currentChoice.label
							),
						renderContent: ( { onClose } ) =>
							el(
								Fragment,
								null,
								el( InspectorPopoverHeader, {
									onClose,
									title: __( 'Indexing', 'easyrankly' ),
								} ),
								el(
									'form',
									{
										onSubmit: ( event ) => {
											event.preventDefault();
											onClose();
										},
									},
									el(
										VStack,
										{ spacing: 4 },
										el( RadioControl, {
											className: 'editor-change-status__options',
											hideLabelFromVision: true,
											label: __( 'Indexing', 'easyrankly' ),
											onChange,
											options: visibilityChoices,
											selected: value,
										} )
									)
								)
							),
					} )
				)
			)
		);
	}

	/**
	 * Renders the editor controls for a resolved entity.
	 *
	 * @param {Object} props          Component properties.
	 * @param {string} props.postType Current post type.
	 * @param {number} props.postId   Current post ID.
	 * @return {Element} Editor controls.
	 */
	function ERanklyEditor( { postType, postId } ) {
		const [ isOpen, setIsOpen ] = useState( false );
		const [ globalCode, setGlobalCode ] = useState( initialGlobalCode );
		const [ savedGlobalCode, setSavedGlobalCode ] = useState(
			initialGlobalCode
		);
		const [ isSavingGlobal, setIsSavingGlobal ] = useState( false );
		const [ meta, setMeta ] = useEntityProp(
			'postType',
			postType,
			'meta',
			postId
		);
		const { createErrorNotice, createSuccessNotice } =
			useDispatch( noticesStore );

		if ( ! meta ) {
			return null;
		}

		const currentCode = Object.fromEntries(
			Object.entries( metaKeys ).map( ( [ location, key ] ) => [
				location,
				typeof meta[ key ] === 'string' ? meta[ key ] : '',
			] )
		);
		const metaDescription =
			typeof meta[ descriptionMetaKey ] === 'string'
				? meta[ descriptionMetaKey ]
				: '';
		const visibility =
			meta[ visibilityMetaKey ] === 'noindex' ? 'noindex' : 'index';
		const updateMeta = ( key, value ) => {
			setMeta( {
				...meta,
				[ key ]: value,
			} );
		};
		const saveGlobalCode = async () => {
			if ( ! canEditGlobalCode || isSavingGlobal ) {
				return;
			}

			setIsSavingGlobal( true );

			try {
				const requestData = Object.fromEntries(
					Object.entries( globalOptionKeys ).map(
						( [ location, optionKey ] ) => [
							optionKey,
							globalCode[ location ],
						]
					)
				);
				const response = await apiFetch( {
					data: requestData,
					method: 'POST',
					path: '/wp/v2/settings',
				} );
				const savedCode = Object.fromEntries(
					Object.entries( globalOptionKeys ).map(
						( [ location, optionKey ] ) => [
							location,
							response &&
								typeof response[ optionKey ] === 'string'
									? response[ optionKey ]
									: '',
						]
					)
				);

				setGlobalCode( savedCode );
				setSavedGlobalCode( savedCode );
				createSuccessNotice(
					__( 'Global code saved.', 'easyrankly' ),
					{ type: 'snackbar' }
				);
			} catch ( error ) {
				createErrorNotice(
					error && typeof error.message === 'string'
						? error.message
						: __( 'Global code could not be saved.', 'easyrankly' ),
					{ type: 'snackbar' }
				);
			} finally {
				setIsSavingGlobal( false );
			}
		};

		return el(
			Fragment,
			null,
			el(
				PluginDocumentSettingPanel,
				{
					name: 'search-engines',
					title: __( 'Search engines', 'easyrankly' ),
				},
				el( TextareaControl, {
					help: __(
						'Shown in search results and social shares.',
						'easyrankly'
					),
					label: __( 'Description', 'easyrankly' ),
					name: 'erankly-meta-description',
					onChange: ( value ) => updateMeta( descriptionMetaKey, value ),
					rows: 5,
					value: metaDescription,
				} )
			),
			el( IndexingControl, {
				onChange: ( value ) =>
					updateMeta( visibilityMetaKey, value ),
				value: visibility,
			} ),
			canEditCode &&
				el(
					PluginMoreMenuItem,
					{ onClick: () => setIsOpen( true ) },
					__( 'Custom code', 'easyrankly' )
				),
			canEditCode &&
				isOpen &&
				el( CodeModal, {
					currentCode,
					globalCode,
					isGlobalDirty: Object.keys( globalOptionKeys ).some(
						( location ) =>
							globalCode[ location ] !==
							savedGlobalCode[ location ]
					),
					isSavingGlobal,
					onChangeCurrentCode: ( location, value ) =>
						updateMeta( metaKeys[ location ], value ),
					onChangeGlobalCode: ( location, value ) =>
						setGlobalCode( {
							...globalCode,
							[ location ]: value,
						} ),
					onClose: () => setIsOpen( false ),
					onSaveGlobal: saveGlobalCode,
				} )
		);
	}

	/**
	 * Resolves the entity currently edited by the post editor.
	 *
	 * @return {Element|null} Plugin UI once the entity is available.
	 */
	function ERanklyPlugin() {
		const { postId, postType } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );

			return {
				postId: editor.getCurrentPostId(),
				postType: editor.getCurrentPostType(),
			};
		}, [] );

		if (
			! postId ||
			! postType ||
			Object.values( metaKeys ).some( ( key ) => ! key )
		) {
			return null;
		}

		return el( ERanklyEditor, { postId, postType } );
	}

	registerPlugin( 'easyrankly', {
		render: ERanklyPlugin,
	} );
} )( window.wp, window.eranklyEditorSettings || {} );
