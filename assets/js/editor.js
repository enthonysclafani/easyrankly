/* global eranklyEditor, wp */
( function () {
	'use strict';

	const shared = window.eranklyShared;
	const {
		Button,
		FormTokenField,
		Modal,
		Notice,
		SelectControl,
		TextareaControl,
	} = wp.components;
	const { useDispatch, useSelect } = wp.data;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6.
	// Prefer wp.editor (6.6+, non-deprecated) and fall back to wp.editPost
	// (WP 5.3–6.5) so the post-editor panel still loads on older WordPress.
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const { createElement: el, createPortal, Fragment, useEffect, useState } = wp.element;
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const config = eranklyEditor;

	// Maps the shared builders' short field names to this editor's post meta keys.
	const META_MAP = {
		title: '_erankly_title',
		description: '_erankly_description',
		canonical: '_erankly_canonical',
		breadcrumb_name: '_erankly_breadcrumb_name',
		og_title: '_erankly_og_title',
		og_description: '_erankly_og_description',
		twitter_title: '_erankly_twitter_title',
		twitter_description: '_erankly_twitter_description',
		twitter_card_type: '_erankly_twitter_card_type',
		og_image_url: '_erankly_og_image_url',
		og_image_id: '_erankly_og_image_id',
		og_image_alt: '_erankly_og_image_alt',
		twitter_image_url: '_erankly_twitter_image_url',
		twitter_image_id: '_erankly_twitter_image_id',
		twitter_image_alt: '_erankly_twitter_image_alt',
		index_directive: '_erankly_index_directive',
		follow_directive: '_erankly_follow_directive',
		archive_directive: '_erankly_archive_directive',
		snippet_directive: '_erankly_snippet_directive',
		image_directive: '_erankly_image_directive',
		max_snippet: '_erankly_max_snippet',
		max_video_preview: '_erankly_max_video_preview',
		max_image_preview: '_erankly_max_image_preview',
		indexifembedded: '_erankly_indexifembedded',
		schema_mode: '_erankly_schema_mode',
		schema_blocks: '_erankly_schema_blocks',
		schema_disabled_types: '_erankly_schema_disabled_types',
		disable_sitemap: '_erankly_disable_sitemap',
		exclude_search: '_erankly_exclude_search',
		exclude_archive: '_erankly_exclude_archive',
		exclude_from_news: '_erankly_exclude_from_news',
	};

	// Optional controls available in the post editor.
	const FEATURES = {
		breadcrumbName: config.breadcrumbsEnabled,
		canonical: true,
		cardType: true,
		disableSitemap: true,
		excludeQueries: true,
		newsSitemap: config.newsSitemapEnabled,
		splitSocialImages: true,
		triStateRobots: true,
	};

	function uniqueSchemaTypes( tokens ) {
		const seen = new Set();
		const unique = [];

		( Array.isArray( tokens ) ? tokens : [] ).forEach( ( token ) => {
			const value = String( token || '' ).trim();

			if ( ! value ) {
				return;
			}

			const key = value.toLowerCase();

			if ( seen.has( key ) ) {
				return;
			}

			seen.add( key );
			unique.push( value );
		} );

		return unique;
	}

	function validateJsonLd( value ) {
		if ( window.eranklyJsonLd && typeof window.eranklyJsonLd.validate === 'function' ) {
			return window.eranklyJsonLd.validate( value );
		}

		const text = String( value || '' ).replace( /{{\s*[a-z0-9_]+\s*}}/gi, 'x' );

		if ( text.trim() === '' ) {
			return { valid: true, code: '', message: '' };
		}

		try {
			JSON.parse( text );
			return { valid: true, code: '', message: '' };
		} catch ( error ) {
			return {
				valid: false,
				code: 'syntax',
				message: __( 'This is not valid JSON, so it cannot be used as JSON-LD.', 'easyrankly' ),
			};
		}
	}

	function schemaBlocks( value ) {
		return Array.isArray( value ) ? value.filter( ( block ) => block && typeof block === 'object' ) : [];
	}

	function blockHasJson( block ) {
		return !!( block && block.fields && String( block.fields.custom_json || '' ).trim() );
	}

	// Post-meta data adapter shared builders read and write through.
	function usePostData() {
		const meta = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
			[]
		);
		const { editPost } = useDispatch( 'core/editor' );

		return {
			get: ( field ) => {
				const value = meta[ META_MAP[ field ] ];

				return undefined === value || null === value ? '' : value;
			},
			set: ( field, value ) => editPost( { meta: { [ META_MAP[ field ] ]: value } } ),
		};
	}

	function useConfigWithPostContext() {
		const { canonicalPlaceholder, postTitle } = useSelect(
			( select ) => {
				const editor = select( 'core/editor' );

				return {
					canonicalPlaceholder: editor.getPermalink() || '',
					postTitle: editor.getEditedPostAttribute( 'title' ) || '',
				};
			},
			[]
		);

		return { ...config, canonicalPlaceholder, postTitle };
	}

	function GeneralPanel() {
		const data = usePostData();
		const panelConfig = useConfigWithPostContext();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--appearance',
				name: 'erankly-general',
				title: __( 'Search appearance', 'easyrankly' ),
			},
			...shared.searchAppearanceFields( { config: panelConfig, data, features: FEATURES } ),
			...( wp.hooks && wp.hooks.applyFilters ? wp.hooks.applyFilters( 'erankly.editor.searchAppearanceExtras', [], { data, config } ) : [] )
		);
	}

	function SocialPanel() {
		const data = usePostData();
		const panelConfig = useConfigWithPostContext();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--social',
				name: 'erankly-social',
				title: __( 'Social sharing', 'easyrankly' ),
			},
			...shared.socialFields( { config: panelConfig, data, features: FEATURES } ),
			...( wp.hooks && wp.hooks.applyFilters ? wp.hooks.applyFilters( 'erankly.editor.socialExtras', [], { data, config } ) : [] )
		);
	}

	function VisibilityPanel() {
		const data = usePostData();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--visibility',
				name: 'erankly-visibility',
				title: __( 'Search visibility', 'easyrankly' ),
			},
			...shared.visibilityFields( { data, features: FEATURES } )
		);
	}

	function bindSchemaSaveFocus() {
		if ( bindSchemaSaveFocus.bound || ! wp.data || typeof wp.data.subscribe !== 'function' ) {
			return;
		}

		bindSchemaSaveFocus.bound = true;

		let wasSaving = false;
		let invalidSchemaDraft = null;

		wp.data.subscribe( function () {
			const editor = wp.data.select( 'core/editor' );

			if ( ! editor || typeof editor.isSavingPost !== 'function' ) {
				return;
			}

			const saving = editor.isSavingPost();

			if ( saving && ! wasSaving ) {
				const invalid = document.querySelector(
					'.erankly-panel--schema textarea[aria-invalid="true"], .erankly-panel--schema .erankly-is-invalid textarea'
				);

				if ( invalid ) {
					const meta = editor.getEditedPostAttribute( 'meta' ) || {};
					const currentBlocks = Array.isArray( meta[ META_MAP.schema_blocks ] )
						? meta[ META_MAP.schema_blocks ]
						: [];

					// The server keeps the previous valid value. Keep a separate
					// editor draft so the REST response cannot make invalid input
					// disappear before the author has a chance to correct it.
					invalidSchemaDraft = JSON.parse( JSON.stringify( currentBlocks ) );

					const panel = invalid.closest( '.components-panel__body' );
					const toggle = panel && ! panel.classList.contains( 'is-opened' )
						? panel.querySelector( '.components-panel__body-toggle' )
						: null;

					if ( toggle ) {
						toggle.click();
					}

					window.setTimeout( function () {
						invalid.focus();
					}, 0 );
				}
			}

			if ( ! saving && wasSaving && invalidSchemaDraft ) {
				const draft = invalidSchemaDraft;
				const meta = editor.getEditedPostAttribute( 'meta' ) || {};
				const savedBlocks = Array.isArray( meta[ META_MAP.schema_blocks ] )
					? meta[ META_MAP.schema_blocks ]
					: [];

				invalidSchemaDraft = null;

				if ( JSON.stringify( savedBlocks ) !== JSON.stringify( draft ) ) {
					wp.data.dispatch( 'core/editor' ).editPost( {
						meta: { [ META_MAP.schema_blocks ]: draft },
					} );
				}

				window.setTimeout( function () {
					const restored = document.querySelector(
						'.erankly-panel--schema textarea[aria-invalid="true"], .erankly-panel--schema .erankly-is-invalid textarea'
					);

					if ( restored ) {
						restored.focus();
					}
				}, 0 );
			}

			wasSaving = saving;
		} );
	}

	function SchemaPanel() {
		const data = usePostData();
		bindSchemaSaveFocus();
		const blocks = schemaBlocks( data.get( 'schema_blocks' ) );
		const mode = data.get( 'schema_mode' ) || 'default';
		const disabledTypes = uniqueSchemaTypes(
			Array.isArray( data.get( 'schema_disabled_types' ) ) ? data.get( 'schema_disabled_types' ) : []
		);
		const hasCustom = blocks.some( blockHasJson );
		const suggestions = Array.isArray( config.schemaTypeSuggestions ) ? config.schemaTypeSuggestions : [];
		const isDisabled = 'disabled' === mode;

		function setMode( value ) {
			data.set( 'schema_mode', value );
		}

		function setBlocks( nextBlocks ) {
			data.set( 'schema_blocks', nextBlocks );
		}

		function addBlock() {
			if ( 'default' === mode ) {
				setMode( 'merge' );
			}

			setBlocks( [ ...blocks, { type: 'custom', fields: { custom_json: '' } } ] );
		}

		const notices = [];

		if ( 'default' === mode && hasCustom ) {
			notices.push(
				el(
					Notice,
					{
						isDismissible: false,
						key: 'default-custom',
						status: 'warning',
					},
					__( 'This content already has custom JSON-LD. Automatic schema ignores those blocks until you switch to Automatic + custom schema.', 'easyrankly' ),
					el(
						'p',
						{ key: 'default-custom-action' },
						el(
							Button,
							{
								onClick: () => setMode( 'merge' ),
								variant: 'secondary',
							},
							__( 'Use Automatic + custom schema', 'easyrankly' )
						)
					)
				)
			);
		}

		if ( 'replace' === mode && ! hasCustom ) {
			notices.push(
				el(
					Notice,
					{
						isDismissible: false,
						key: 'replace-empty',
						status: 'warning',
					},
					__( 'Custom schema only emits the JSON-LD added below. No automatic or site-wide schema will be output. Add a JSON-LD block, or this page will have no EasyRankly structured data.', 'easyrankly' )
				)
			);
		}

		if ( isDisabled ) {
			notices.push(
				el(
					Notice,
					{
						isDismissible: false,
						key: 'disabled',
						status: 'info',
					},
					__( 'No EasyRankly JSON-LD will be emitted for this content, including automatic, site-wide, and custom blocks.', 'easyrankly' )
				)
			);
		}

		const fields = [
			el( SelectControl, {
				__next40pxDefaultSize: true,
				key: 'schema-mode',
				label: __( 'Schema mode', 'easyrankly' ),
				onChange: setMode,
				options: [
					{ label: __( 'Automatic schema', 'easyrankly' ), value: 'default' },
					{ label: __( 'Automatic + custom schema', 'easyrankly' ), value: 'merge' },
					{ label: __( 'Custom schema only', 'easyrankly' ), value: 'replace' },
					{ label: __( 'Disable schema', 'easyrankly' ), value: 'disabled' },
				],
				value: mode,
			} ),
		];

		if ( isDisabled ) {
			fields.push( ...notices );
		} else {
			fields.push( ...notices );
			fields.push(
				el( FormTokenField, {
					__next40pxDefaultSize: true,
					autoCapitalize: 'none',
					autoComplete: 'off',
					key: 'disabled-types',
					label: __( 'Suppress generated schema types', 'easyrankly' ),
					onChange: ( values ) => data.set( 'schema_disabled_types', uniqueSchemaTypes( values ) ),
					suggestions,
					tokenizeOnSpace: false,
					value: disabledTypes,
				} )
			);

			blocks.forEach( ( block, index ) => {
				const json = block && block.fields ? ( block.fields.custom_json || '' ) : '';
				const result = validateJsonLd( json );
				const errorId = 'erankly-schema-block-error-' + index;

				fields.push(
					el(
						Fragment,
						{ key: 'schema-block-' + index },
						el( TextareaControl, {
							className: result.valid ? undefined : 'erankly-is-invalid',
							help: result.valid ? ( result.notice || undefined ) : result.message,
							label: `${ __( 'Custom JSON-LD', 'easyrankly' ) } ${ index + 1 }`,
							onChange: ( value ) => {
								const nextBlocks = [ ...blocks ];
								nextBlocks[ index ] = { type: 'custom', fields: { custom_json: value } };
								setBlocks( nextBlocks );
							},
							rows: 10,
							value: json,
							'aria-describedby': result.valid ? undefined : errorId,
							'aria-invalid': result.valid ? 'false' : 'true',
						} ),
						! result.valid && el(
							'p',
							{
								className: 'erankly-schema-json-error',
								id: errorId,
								role: 'alert',
							},
							result.message
						),
						el( Button, {
							isDestructive: true,
							onClick: () => setBlocks( blocks.filter( ( unused, blockIndex ) => blockIndex !== index ) ),
							variant: 'link',
						}, __( 'Remove schema block', 'easyrankly' ) )
					)
				);
			} );

			fields.push(
				el( Button, {
					key: 'add-schema',
					onClick: addBlock,
					variant: 'secondary',
				}, __( 'Add JSON-LD schema', 'easyrankly' ) )
			);
		}

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--schema',
				name: 'erankly-schema',
				title: __( 'Schema', 'easyrankly' ),
			},
			...fields
		);
	}

	// JS ports of erankly_normalize_seo_text() / erankly_trim_text(), so the SERP
	// preview cleans up resolved templates the same way the front end does.
	const SEPARATOR = '(?:-|\\||–|—)';

	function htmlToText( html ) {
		const withoutCode = String( html || '' )
			.replace( /<!--[\s\S]*?-->/g, ' ' )
			.replace( /<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ' )
			.replace( /<\/?(p|div|h[1-6]|li|br|figure|blockquote)\b[^>]*>/gi, ' ' );
		const doc = new window.DOMParser().parseFromString( withoutCode, 'text/html' );

		return ( doc.body ? doc.body.textContent : '' ).replace( /\[\/?[a-z][^\]]*\]/gi, '' );
	}

	function normalizeSeoText( value ) {
		return htmlToText( value )
			.replace( /\s+/g, ' ' )
			.replace( new RegExp( '\\s*(' + SEPARATOR + ')(?:\\s*' + SEPARATOR + ')+\\s*', 'gu' ), ' $1 ' )
			.replace( /\s*(?:\(\s*\)|\[\s*\])/gu, '' )
			.replace( new RegExp( '^(?:\\s*' + SEPARATOR + '\\s*)+', 'u' ), '' )
			.replace( new RegExp( '(?:\\s*' + SEPARATOR + '\\s*)+$', 'u' ), '' )
			.trim();
	}

	function trimText( text, limit ) {
		const chars = Array.from( text );

		if ( chars.length <= limit ) {
			return text;
		}

		return chars.slice( 0, limit - 1 ).join( '' ).replace( /[\s.,;:-]+$/, '' );
	}

	function resolveTemplate( template, values, exclude ) {
		return String( template || '' ).replace( /{{\s*([a-z0-9_]+)\s*}}/gi, ( match, key ) => {
			const normalizedKey = key.toLowerCase();

			return normalizedKey === exclude ? '' : String( values[ normalizedKey ] || '' );
		} );
	}

	// Resolves the title, description and URL the front end would print for
	// the post being edited, from its unsaved state.
	function useSerpData() {
		const serp = config.serp || {};
		const post = useSelect( ( select ) => {
			const editor = select( 'core/editor' );

			return {
				content: editor.getEditedPostContent(),
				date: editor.getEditedPostAttribute( 'date' ),
				excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
				permalink: editor.getPermalink() || '',
				title: editor.getEditedPostAttribute( 'title' ) || '',
			};
		}, [] );
		const isSingular = 'singular' === serp.context;
		const values = {
			...( config.variableExamples || {} ),
			site_name: config.siteName,
			site_description: config.siteDescription,
			post_title: post.title,
			post_url: post.permalink,
		};

		if ( post.excerpt ) {
			values.post_excerpt = post.excerpt;
		}

		const titleTemplate = ( isSingular && String( post.meta[ META_MAP.title ] || '' ).trim() ) || serp.titleTemplate;
		let title = normalizeSeoText( resolveTemplate( titleTemplate, values, 'seo_title' ) );

		if ( '' === title ) {
			title = isSingular ? normalizeSeoText( post.title ) : config.siteName;
		}

		const descriptionTemplate = ( isSingular && String( post.meta[ META_MAP.description ] || '' ).trim() ) || serp.descriptionTemplate;
		let description = normalizeSeoText( resolveTemplate( descriptionTemplate, values, 'meta_description' ) );
		const generated = isSingular ? htmlToText( post.excerpt || post.content ).replace( /\s+/g, ' ' ).trim() : '';

		if ( '' === description ) {
			description = isSingular ? trimText( generated, 160 ) : String( config.siteDescription || '' );
		}

		// A description cut from the content ({{post_excerpt}} or the generated
		// fallback) stops mid-sentence; Google marks that with " ...".
		const isDescriptionTrimmed = description.length < generated.length && generated.startsWith( description );

		return {
			date: serp.showDate && post.date ? wp.date.dateI18n( 'j M Y', post.date ) : '',
			description,
			isDescriptionTrimmed,
			permalink: post.permalink,
			query: normalizeSeoText( post.title ) || title,
			siteIcon: serp.siteIcon || '',
			siteName: config.siteName || '',
			title,
		};
	}

	// "https://example.com › blog › my-post", the way Google prints result URLs.
	function serpBreadcrumb( permalink ) {
		try {
			const url = new window.URL( permalink );
			const segments = url.pathname.split( '/' ).filter( Boolean ).map( ( segment ) => {
				try {
					return window.decodeURIComponent( segment );
				} catch ( error ) {
					return segment;
				}
			} );

			return [ url.origin, ...segments ].join( ' › ' );
		} catch ( error ) {
			return permalink;
		}
	}

	// Google bolds the query words it finds in the snippet.
	function highlightQuery( text, query ) {
		const words = Array.from( new Set(
			String( query || '' ).toLowerCase().split( /[^\p{L}\p{N}]+/u ).filter( ( word ) => Array.from( word ).length > 2 )
		) );

		if ( ! words.length ) {
			return text;
		}

		const pattern = new RegExp(
			'(?<![\\p{L}\\p{N}])(' + words.map( ( word ) => word.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) ).join( '|' ) + ')(?![\\p{L}\\p{N}])',
			'giu'
		);

		return text.split( pattern ).map( ( part, index ) => ( index % 2 ? el( 'b', { key: index }, part ) : part ) );
	}

	// Material Design icon paths (Apache 2.0) for the mock results page.
	const SERP_ICONS = {
		apps: 'M6 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6 12c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm-6 0c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0-6c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6 0c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm4-8c0 1.1.9 2 2 2s2-.9 2-2-.9-2-2-2-2 .9-2 2zm-4 2c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6 6c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 6c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z',
		caret: 'M7 10l5 5 5-5z',
		clear: 'M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z',
		globe: 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zm6.93 6h-2.95a15.65 15.65 0 0 0-1.38-3.56A8.03 8.03 0 0 1 18.92 8zM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96zM4.26 14C4.1 13.36 4 12.69 4 12s.1-1.36.26-2h3.38c-.08.66-.14 1.32-.14 2s.06 1.34.14 2H4.26zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56A7.987 7.987 0 0 1 5.08 16zm2.95-8H5.08a7.987 7.987 0 0 1 4.33-3.56A15.65 15.65 0 0 0 8.03 8zM12 19.96c-.83-1.2-1.48-2.53-1.91-3.96h3.82c-.43 1.43-1.08 2.76-1.91 3.96zM14.34 14H9.66c-.09-.66-.16-1.32-.16-2s.07-1.35.16-2h4.68c.09.65.16 1.32.16 2s-.07 1.34-.16 2zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95a8.03 8.03 0 0 1-4.33 3.56zM16.36 14c.08-.66.14-1.32.14-2s-.06-1.34-.14-2h3.38c.16.64.26 1.31.26 2s-.1 1.36-.26 2h-3.38z',
		lens: 'M5 15H3v4c0 1.1.9 2 2 2h4v-2H5v-4zM5 5h4V3H5c-1.1 0-2 .9-2 2v4h2V5zm14-2h-4v2h4v4h2V5c0-1.1-.9-2-2-2zm0 16h-4v2h4c1.1 0 2-.9 2-2v-4h-2v4zM12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 6c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z',
		lock: 'M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6zm3 11c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z',
		mic: 'M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z',
		more: 'M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z',
		search: 'M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z',
	};

	function SerpIcon( { name, size = 24 } ) {
		return el(
			'svg',
			{
				'aria-hidden': true,
				className: 'erankly-serp__icon erankly-serp__icon--' + name,
				focusable: 'false',
				height: size,
				viewBox: '0 0 24 24',
				width: size,
			},
			el( 'path', { d: SERP_ICONS[ name ], fill: 'currentColor' } )
		);
	}

	function SerpSource( { children, favicon } ) {
		return el(
			'div',
			{ className: 'erankly-serp__source' },
			el( 'span', { className: 'erankly-serp__favicon', 'aria-hidden': true }, favicon ),
			el( 'span', { className: 'erankly-serp__source-text' }, children )
		);
	}

	function SerpResult( { data } ) {
		const description = data.description + ( data.isDescriptionTrimmed ? ' ...' : '' );

		return el(
			'div',
			{ className: 'erankly-serp__result' },
			el(
				SerpSource,
				{
					favicon: data.siteIcon
						? el( 'img', { alt: '', src: data.siteIcon } )
						: el( SerpIcon, { name: 'globe', size: 18 } ),
				},
				el( 'span', { className: 'erankly-serp__site-name' }, data.siteName ),
				el(
					'span',
					{ className: 'erankly-serp__url-row' },
					el( 'span', { className: 'erankly-serp__url' }, serpBreadcrumb( data.permalink ) ),
					el( SerpIcon, { name: 'more', size: 18 } )
				)
			),
			el( 'h3', { className: 'erankly-serp__title' }, data.title ),
			description.trim()
				? el(
					'div',
					{ className: 'erankly-serp__description' },
					data.date ? el( 'span', { className: 'erankly-serp__date' }, data.date + ' — ' ) : null,
					highlightQuery( description, data.query )
				)
				: null
		);
	}

	// Grey placeholder results around the real one, so it reads as a results page.
	function SerpSkeleton( { titleWidth } ) {
		const bone = ( modifier, width ) => el( 'span', {
			className: 'erankly-serp__bone' + ( modifier ? ' erankly-serp__bone--' + modifier : '' ),
			style: { width },
		} );

		return el(
			'div',
			{ className: 'erankly-serp__result erankly-serp__result--skeleton', 'aria-hidden': true },
			el( SerpSource, null, bone( '', '96px' ), bone( '', '188px' ) ),
			bone( 'title', titleWidth ),
			bone( 'text', '100%' ),
			bone( 'text', '64%' )
		);
	}

	function SerpPreviewModal( { onClose } ) {
		const data = useSerpData();
		const tabs = [
			[ __( 'AI Mode', 'easyrankly' ) ],
			[ __( 'All', 'easyrankly' ), 'is-active' ],
			[ __( 'Images', 'easyrankly' ) ],
			[ __( 'Videos', 'easyrankly' ) ],
			[ __( 'News', 'easyrankly' ) ],
			[ __( 'Short videos', 'easyrankly' ) ],
			[ __( 'Web', 'easyrankly' ) ],
			[ __( 'More', 'easyrankly' ), 'has-caret' ],
			[ __( 'Tools', 'easyrankly' ), 'has-caret is-tools' ],
		];

		return el(
			Modal,
			{
				className: 'erankly-serp-modal',
				onRequestClose: onClose,
				size: 'large',
				title: __( 'SERP preview', 'easyrankly' ),
			},
			el(
				'div',
				{ className: 'erankly-serp-window' },
				el(
					'div',
					{ className: 'erankly-serp-window__bar', 'aria-hidden': true },
					el( 'span', { className: 'erankly-serp-window__dots' }, el( 'span' ), el( 'span' ), el( 'span' ) ),
					el(
						'span',
						{ className: 'erankly-serp-window__address' },
						el( SerpIcon, { name: 'lock', size: 12 } ),
						el( 'span', null, 'google.com/search?q=' + data.query.replace( /\s+/g, '+' ) )
					)
				),
				el(
					'div',
					{ className: 'erankly-serp' },
					el(
						'div',
						{ className: 'erankly-serp__header' },
						el( 'span', { className: 'erankly-serp__logo', 'aria-hidden': true }, 'Google' ),
						el(
							'div',
							{ className: 'erankly-serp__searchbar' },
							el( 'span', { className: 'erankly-serp__query' }, data.query ),
							el(
								'span',
								{ className: 'erankly-serp__searchbar-actions', 'aria-hidden': true },
								el( SerpIcon, { name: 'clear' } ),
								el( 'span', { className: 'erankly-serp__divider' } ),
								el( SerpIcon, { name: 'mic' } ),
								el( SerpIcon, { name: 'lens' } ),
								el( SerpIcon, { name: 'search' } )
							)
						),
						el(
							'div',
							{ className: 'erankly-serp__account', 'aria-hidden': true },
							el( SerpIcon, { name: 'apps' } ),
							el( 'span', { className: 'erankly-serp__sign-in' }, __( 'Sign in', 'easyrankly' ) )
						)
					),
					el(
						'div',
						{ className: 'erankly-serp__tabs', 'aria-hidden': true },
						tabs.map( ( [ label, modifiers = '' ] ) => el(
							'span',
							{ className: ( 'erankly-serp__tab ' + modifiers ).trim(), key: label },
							label,
							modifiers.includes( 'has-caret' ) ? el( SerpIcon, { name: 'caret', size: 18 } ) : null
						) )
					),
					el(
						'div',
						{ className: 'erankly-serp__results' },
						el( SerpResult, { data } ),
						el( SerpSkeleton, { titleWidth: '58%' } ),
						el( SerpSkeleton, { titleWidth: '44%' } )
					)
				)
			)
		);
	}

	// Gutenberg has no slot next to the "View" link, so the button is portaled
	// into the header settings bar and re-attached whenever the header remounts
	// (for example after leaving distraction-free mode).
	function useHeaderSlot() {
		const [ host ] = useState( () => {
			const node = window.document.createElement( 'div' );

			node.className = 'erankly-serp-preview-slot';
			return node;
		} );

		useEffect( () => {
			let frameId = 0;
			const attach = () => {
				frameId = 0;

				const settings = window.document.querySelector( '.editor-header__settings, .edit-post-header__settings' );

				if ( ! settings ) {
					return;
				}

				// Core's "View" link has no class of its own: it is the link
				// right before the device preview dropdown.
				let anchor = settings.querySelector( ':scope > .editor-preview-dropdown, :scope > .edit-post-post-preview-dropdown' );
				let previous = anchor ? anchor.previousElementSibling : null;

				if ( previous === host ) {
					previous = host.previousElementSibling;
				}

				if ( previous && 'A' === previous.tagName ) {
					anchor = previous;
				}

				if ( anchor ) {
					if ( host.nextElementSibling !== anchor ) {
						settings.insertBefore( host, anchor );
					}
				} else if ( host.parentElement !== settings ) {
					settings.insertBefore( host, settings.firstChild );
				}
			};
			const schedule = () => {
				if ( ! frameId ) {
					frameId = window.requestAnimationFrame( attach );
				}
			};
			const observer = new window.MutationObserver( schedule );

			observer.observe( window.document.body, { childList: true, subtree: true } );
			attach();

			return () => {
				observer.disconnect();

				if ( frameId ) {
					window.cancelAnimationFrame( frameId );
				}

				host.remove();
			};
		}, [ host ] );

		return host;
	}

	function SerpPreviewButton() {
		const host = useHeaderSlot();
		const [ isOpen, setIsOpen ] = useState( false );

		return el(
			Fragment,
			null,
			createPortal(
				el( Button, {
					__next40pxDefaultSize: true,
					className: 'erankly-serp-preview-button',
					onClick: () => setIsOpen( true ),
					size: 'compact',
					variant: 'tertiary',
				}, __( 'SERP preview', 'easyrankly' ) ),
				host
			),
			isOpen ? el( SerpPreviewModal, { onClose: () => setIsOpen( false ) } ) : null
		);
	}

	function ERanklyDocumentSettings() {
		shared.usePanelsAfterDefaults();

		return el(
			Fragment,
			null,
			el( SerpPreviewButton ),
			el( GeneralPanel ),
			el( SocialPanel ),
			el( SchemaPanel ),
			el( VisibilityPanel )
		);
	}

	registerPlugin( 'erankly-document-settings', {
		render: ERanklyDocumentSettings,
	} );
}() );
