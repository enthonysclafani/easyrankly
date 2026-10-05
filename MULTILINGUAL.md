# Native Multilingual module

Enable **EasyRankly → Feature modules → Multilingual**. The flag defaults to off, is site-wide on a single site and network-wide on Multisite. WordPress `is_multisite()` selects exactly one implementation; there is no manual mode setting that can select an incompatible provider. Saving the Features panel refreshes the available settings tabs.

When disabled, neither `includes/multilingual.php` nor any implementation, settings, relationship hooks, REST routes or module assets load. The existing public provider API stays available for third-party integrations. When enabled, provider-specific hooks start only after the registry has selected a provider. Conflicting third-party providers keep the registry's existing explicit-choice policy.

## Multisite

The implementation is integrated from [the former extension](https://github.com/enthonysclafani/easyrankly-multilingual-multisite), version 1.3.0, commit `adb32e7a1570158d99f8c4c22999c62bb5feab51`. Network settings, the editor panel, classic meta box, taxonomy fields and reciprocal post/term links are included. Configuration appears in Network Admin. Assets load only on the relevant settings/editor screens.

Storage remains compatible: `erml_ms_settings`, `_erankly_mlms_translations` and `_erankly_mlms_term_translations`. Native functions/assets use the `erankly_mlms_` prefix and the core `easyrankly` text domain. Settings autosave uses `/erankly/v1/multilingual/multisite/settings`.

Deactivate the old extension when upgrading. Core suppresses its bootstrap callback if it is still active, so it cannot start a duplicate provider or bypass the core toggle. **Do not run the old extension's uninstall routine:** version 1.3.0 deletes the shared options and relationship metadata. Keep it deactivated, or remove its files without running that routine. Core uninstall now owns cleanup of those settings and links.

## Single site

Translations are separate native WordPress posts, pages, public CPT entries or taxonomy terms. Each has independent content, publication status, URL and SEO metadata. Shared interface text uses a small explicit dictionary. There is no rendered-HTML translation pass, output buffer, global `gettext` interception or frontend bundle.

### Configuration and URLs

The Multilingual tab accepts up to 32 language tags, a default language and an optional x-default language. Tags are normalized to lowercase, for example `it-it` and `en-us`. Configuration and dictionaries are not autoloaded. Unassigned existing content belongs to the default language without bulk database writes. **Changing the default language also reinterprets unassigned content**; explicitly assign objects whose language must stay fixed. Languages in use cannot be removed until their content is reassigned.

Each language has an independent **URL prefix** field. For example, keep `it-IT` as the language tag and set `/it/` as its URL prefix; `en-US` can use `/en/`. Prefixes are unique, lowercase path segments containing letters, numbers and hyphens; WordPress service paths are reserved. Empty or previously unconfigured fields use the language tag, preserving existing URLs. The settings API accepts a `url_slugs` map keyed by normalized language tag, for example `{"it-it":"it","en-us":"en"}`. Changing prefixes refreshes rewrite rules and the sitemap cache and changes public URLs; previous prefixes do not become redirect aliases.

Pretty permalinks use `/en-us/example/` by default, or `/en/example/` with a custom prefix; default-language URLs remain unchanged unless the prefix-default setting is enabled. Plain permalinks continue to use the language tag in `?erankly_lang=en-us`. Subdirectory installations are supported. One language-pattern copy of native rewrite rules serves all configured languages. Unknown languages and objects requested under the wrong language return 404. URL prefixes do not change content assignments, locale resolution or hreflang tags.

Frontend content queries, taxonomy archives, search and pagination select the current language. REST, CLI, administration and sitemaps avoid implicit language filters. Linked static front pages and posts pages resolve in the current language; a missing static front-page translation returns 404. Dynamic homepages and public CPT archives have language URLs. Native home links, breadcrumbs and navigation block object references resolve to translated targets. Classic menu equivalents can be linked in settings or through the term API. Custom URL links and authored classic-menu labels are preserved.

### Editorial workflow

The block editor has a native **Multilingual** document panel; the classic editor has a side metabox, and taxonomy forms have language controls. Choose a language, find an existing translation or create a copy, then **Save translation links**. Classic post and taxonomy form submissions also persist those fields. Post lists have a language column and filter.

Post copies remain **drafts** for manual translation. They copy content, excerpt, translated parent when available, menu order, page template and the existing featured-image ID. Media files are shared. Only existing translated taxonomy terms are assigned. Canonicals and other SEO metadata are not cloned by default. Term copies have a temporary language-suffixed name/slug and translated parent; native terms have no draft status. Create navigation menus in WordPress's menu editor.

Changes require edit capabilities for every affected member. One atomic database statement and short, expiring editorial leases protect cluster writes and concurrent creation. Failed link writes preserve the previous group; a failed copy/link operation removes only its newly created object.

### Storage, caching and migration

The selected single-site provider creates `${wpdb->prefix}erankly_languages` once. It stores **one indexed row per explicitly assigned object**: kind, ID, language and group UUID. Object/language indexes serve lookups and filtered queries; a unique group/language index prevents duplicates. Group maps are cached, and list queries prime membership in bounded batches rather than per object.

Earlier `_erankly_mlss_translations` metadata migrates in resumable batches of 100 objects per kind during administration/CLI requests, with temporary scheduled continuation for larger sites. Reciprocal, same-subtype maps retain their groups. Invalid relationships become separate language assignments. Metadata is removed after successful conversion; database failures retain it for retry. New writes use the indexed table exclusively.

Disabling preserves content and associations while stopping module routing, integrations and assets on subsequent requests. Re-enabling resumes them. Uninstall removes the table, options and relationships; native posts, terms and attachments remain. Editorial copy code loads only on creation requests; REST/editor code loads only in its corresponding context.

### SEO and switcher

Multilingual works independently of the SEO module. With SEO enabled, its robots and canonical rules refine the alternate-language set. With SEO disabled, Multilingual uses native WordPress URLs, publication status and site visibility; saved EasyRankly SEO overrides remain stored but do not affect hreflang or navigation.

Canonicals use native language URLs; explicit canonical overrides are preserved. Hreflang requires existing reciprocal, public/published and unprotected equivalents. Noindex and non-self canonicals are excluded from SEO alternates; public noindex equivalents remain available for visitor navigation. Special-page robots policies apply to translated front/blog pages. Sitemaps cover all languages and include dynamic language homepages.

Add the **Language switcher** block or `[erankly_language_switcher]`. Both render on the server, with no frontend CSS/JavaScript, and show only existing public equivalents. Fewer than two equivalents produce no switcher. Preview, 404, feed, pagination/multipage, search and date/author archive contexts currently emit no alternate-language sets; dynamic homepages and CPT archives do.

### Integration API

Write complete groups through the authenticated API rather than writing metadata/table rows:

```php
$result = erankly_mlss_set_translations( 'post', $italian_id, array(
    'it-it' => $italian_id,
    'en-us' => $english_id,
) ); // Check is_wp_error($result); every affected member must be editable.

$result = erankly_mlss_set_translations( 'term', $italian_term_id, array(
    'it-it' => $italian_term_id,
    'en-us' => $english_term_id,
) ); // Same taxonomy required, including nav_menu groups.

$map = erankly_mlss_get_translations( 'post', $italian_id );
$language = erankly_mlss_get_language( 'post', $italian_id );
$result = erankly_mlss_set_language( 'post', $italian_id, 'it-it' );
$new_draft_id = erankly_mlss_create_translation( 'post', $italian_id, 'fr-fr' );
```

Singletons assign a language without hreflang. Replacing a group disconnects displaced members while preserving their languages. Deletion removes membership. `erankly_multilingual_translations_updated` passes kind, source ID, new map and all affected IDs for integrations/cache purges. `erankly_multilingual_copy_meta_keys` opts specific custom fields into copying; canonical/edit-lock/legacy-map keys remain excluded. Page-builder and commerce metadata need explicit adapters.

REST routes under `/erankly/v1/multilingual/singlesite/`:

- `POST settings`, with `{"settings":{...}}`, requires `manage_options`.
- `POST translations/post/{id}` or `translations/term/{id}`, with `{"translations":{"it-it":123,"en-us":456}}`, checks every affected member.
- `POST create/post/{id}` or `create/term/{id}`, with `{"language":"fr-fr"}`, creates and links a native copy.
- `GET objects?kind=post&subtype=page&language=en-us&search=example`, returns at most 20 editable matches.
- `GET editor/post/{id}` or `editor/term/{id}`, returns escaped editor controls to authorized editors.

Native `/wp/v2` resources expose writable `erankly_language`; collections accept `erankly_lang`, with normal REST behavior when omitted. Internal `WP_Query` and `get_terms` support a configured `erankly_lang` or `all` to bypass implicit filtering.

### Shared strings and locale

Site title/tagline have settings fields. Themes/integrations can register explicit strings on `init` and translate when rendering:

```php
if ( function_exists( 'erankly_mlss_register_string' ) ) {
    erankly_mlss_register_string( 'footer_cta', 'Contattaci', 'Footer' );
}
echo esc_html( function_exists( 'erankly_mlss_translate_string' )
    ? erankly_mlss_translate_string( 'footer_cta', 'Contattaci' )
    : 'Contattaci' );
```

Only the current language dictionary is read; missing entries fall back to source. Native theme/plugin interface strings use installed WordPress language packs. `erankly_multilingual_locale` overrides tag-to-locale mapping when needed. Arbitrary rendered strings, JavaScript text, machine translation, language-specific domains and repeated identical native slugs are outside this implementation. WordPress's usual slug uniqueness rules apply.

## Verification

There are 44 single-site tests covering relationships, permissions, concurrent creation, migration, indexed queries/cache priming, routing, custom URL prefixes, homepages, navigation, REST, strings, switcher, SEO and disabled behavior. The multisite provider retains 26 tests. The full regression suite runs in both topologies; the native block-editor workflow and public translated-page metadata are also checked in WordPress Studio.
