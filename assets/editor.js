( function ( wp, settings ) {
	'use strict';

	const {
		Button,
		Dropdown,
		Flex,
		Modal,
		RadioControl,
		TabPanel,
		TextareaControl,
	} = wp.components;
	const apiFetch = wp.apiFetch;
	const { useEntityProp } = wp.coreData;
	const { useDispatch, useSelect } = wp.data;
	const {
		PluginDocumentSettingPanel,
		PluginMoreMenuItem,
		PluginPostStatusInfo,
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
	const variables = Array.isArray( settings.variables ) ? settings.variables : [];
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
			description: __( 'Allow search engines to index this content.', 'easyrankly' ),
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
	 * Renders the indexing popover header with public components.
	 *
	 * @param {Object}   props         Component properties.
	 * @param {Function} props.onClose Closes the popover.
	 * @param {string}   props.title   Popover title.
	 * @return {Element} Popover header.
	 */
	function IndexingPopoverHeader( { onClose, title } ) {
		return el(
			Flex,
			{
				justify: 'space-between',
			},
			el( 'strong', null, title ),
			el(
				Button,
				{
					onClick: onClose,
					size: 'compact',
					variant: 'tertiary',
				},
				__( 'Close', 'easyrankly' )
			)
		);
	}

	/**
	 * Renders one code location field per tab of the Custom code modal.
	 *
	 * @param {Object}   props          Component properties.
	 * @param {Object}   props.code     Code by location.
	 * @param {boolean}  props.isGlobal Whether the fields target the global option.
	 * @param {Function} props.onChange Updates code for a location.
	 * @return {Element} Code location fields.
	 */
	function CodeFields( { code, isGlobal = false, onChange } ) {
		return el(
			Fragment,
			null,
			codeLocations.map( ( location ) =>
				el( TextareaControl, {
					key: location.key,
					label: location.title,
					name: `erankly-${
						isGlobal ? 'global-' : ''
					}${ location.key }`,
					onChange: ( value ) => onChange( location.key, value ),
					rows: 8,
					spellCheck: false,
					value: code[ location.key ],
				} )
			)
		);
	}

	/**
	 * Renders the reference for the variables Custom code can use.
	 *
	 * @return {Element} Variables reference.
	 */
	function VariablesReference() {
		return el(
			Fragment,
			null,
			el(
				'p',
				null,
				__(
					'Write a variable as {{name}} in any Custom code field. Global code resolves it on every page, so one template covers the whole site.',
					'easyrankly'
				)
			),
			el(
				'p',
				null,
				__(
					'A variable can list fallbacks: {{excerpt|siteDescription|"Fixed text"}} uses the first value that is not empty. When the whole chain stays empty, a tag written on a line of its own is dropped and the automatic EasyRankly metadata takes over.',
					'easyrankly'
				)
			),
			el(
				'table',
				{ className: 'erankly-variables' },
				el(
					'thead',
					null,
					el(
						'tr',
						null,
						el( 'th', null, __( 'Variable', 'easyrankly' ) ),
						el( 'th', null, __( 'Source', 'easyrankly' ) ),
						el( 'th', null, __( 'Current value', 'easyrankly' ) )
					)
				),
				el(
					'tbody',
					null,
					variables.map( ( variable ) =>
						el(
							'tr',
							{ key: variable.token },
							el( 'td', null, el( 'code', null, variable.token ) ),
							el( 'td', null, variable.label ),
							el(
								'td',
								null,
								variable.value ||
									el( 'em', null, __( 'Empty', 'easyrankly' ) )
							)
						)
					)
				)
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
		const sections = [
			{
				name: 'current-post',
				tabLabel: __( 'Current post', 'easyrankly' ),
				content: el( CodeFields, {
					code: currentCode,
					onChange: onChangeCurrentCode,
				} ),
			},
		];

		if ( canEditGlobalCode ) {
			sections.push( {
				name: 'global',
				tabLabel: __( 'Global', 'easyrankly' ),
				content: el(
					Fragment,
					null,
					el( CodeFields, {
						code: globalCode,
						isGlobal: true,
						onChange: onChangeGlobalCode,
					} ),
					el(
						Flex,
						{ justify: 'flex-end' },
						el(
							Button,
							{
								accessibleWhenDisabled: true,
								disabled: isSavingGlobal || ! isGlobalDirty,
								isBusy: isSavingGlobal,
								onClick: onSaveGlobal,
								variant: 'primary',
							},
							__( 'Save', 'easyrankly' )
						)
					)
				),
			} );
		}

		if ( variables.length ) {
			sections.push( {
				name: 'variables',
				tabLabel: __( 'Variables', 'easyrankly' ),
				content: el( VariablesReference ),
			} );
		}

		return el(
			Modal,
			{
				closeButtonLabel: __( 'Close Custom code', 'easyrankly' ),
				onRequestClose: onClose,
				size: 'large',
				title: __( 'Custom code', 'easyrankly' ),
			},
			el(
				TabPanel,
				{
					initialTabName: 'current-post',
					tabs: sections.map( ( { name, tabLabel } ) => ( {
						name,
						title: tabLabel,
					} ) ),
				},
				( tab ) =>
					sections.find(
						( section ) => section.name === tab.name
					)?.content
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
		const [ popoverControl, setPopoverControl ] = useState( null );
		// PluginPostStatusInfo does not forward refs; the control's parent is the
		// complete row used by WordPress for native status popover anchoring.
		const popoverProps = useMemo(
			() => ( {
				anchor: popoverControl ? popoverControl.parentElement : null,
				'aria-label': __( 'Indexing', 'easyrankly' ),
				headerTitle: __( 'Indexing', 'easyrankly' ),
				offset: 36,
				placement: 'left-start',
				shift: true,
			} ),
			[ popoverControl ]
		);
		const currentChoice =
			visibilityChoices.find( ( choice ) => choice.value === value ) ||
			visibilityChoices[ 0 ];

		return el(
			PluginPostStatusInfo,
			{ className: 'editor-post-panel__row erankly-indexing-row' },
			el(
				'div',
				{ className: 'editor-post-panel__row-label' },
				__( 'Indexing', 'easyrankly' )
			),
			el(
				'div',
				{
					className: 'editor-post-panel__row-control',
					ref: setPopoverControl,
				},
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
								el( IndexingPopoverHeader, {
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
									el( RadioControl, {
										className: 'editor-change-status__options',
										hideLabelFromVision: true,
										label: __( 'Indexing', 'easyrankly' ),
										onChange,
										options: visibilityChoices,
										selected: value,
									} )
								)
							),
				} )
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
			! descriptionMetaKey ||
			! visibilityMetaKey ||
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
