# INF audit notes (helpers / migrations / import / redirects / reset)

Working tree authoritative. Every listed file read via Read+offsets. No plugin/test/git mutations.

## Coverage

All assigned files: coperto. See parent response for table.

## Highest-severity (write these first)

### Restore data loss
`ERankly_Import_Job_Runner::start_from_file` sets `purge_owned=true`. First settings batch calls `purge_owned_data()` (SQL wipe of all `_erankly_*` meta + special meta + ALL redirect rows), then `apply_settings`. `process()` catch calls `finish($job,'failed')` which **deletes the spool**. Interrupted restore after purge cannot resume. Catastrophic.

### Multisite user-meta reset
`erankly_reset_site_data()` DELETEs `$wpdb->usermeta WHERE meta_key LIKE '_erankly_%'`. Usermeta is network-global. A “this site” reset wipes author SEO meta for every site.

### Per-site start lock vs network settings
`erankly_acquire_data_transfer_start_lock` uses `$wpdb->options` (blog). EasyRankly settings on MS are `get_site_option`. Two sites can start import/migration against the same network option.

### AIOSEO `#token` converter
`erankly_import_convert_variables` aioseo pattern `/#([a-z0-9_]+)/i` matches CSS colors, URL fragments (`#product`, `#website`), schema `@id` hashes. Unmapped tokens kept; mapped ones rewritten (`#title` → `{{post_title}}`).

### Migration fingerprint after writes
Job runner verifies source fingerprint only after content+redirects written. Change mid-run → exception → pause with data already mutated; resume re-hits same check.

## Other notes
- `erankly_get_multilingual_context()` memoizes empty context if called before `close_and_boot`.
- Native import settings write uses `erankly_update_plugin_option`, not the settings mutex.
- Redirect admin/REST: `manage_options` + `admin_url('options-general.php')` even if UI is under Network Admin.
- `erankly_is_frontend_html_request()` does not exclude REST/feeds/sitemaps; custom-code layer adds extra gates, head output does not.
- `{{page_number}}` floors to 1 on unpaginated requests.
- `archive_seo_title` localized writer maps to author special meta, not date archives.
- Duplicate lock CAS copy-pasted across settings-lock, migration job, import job, data-transfer start, network reset.
- Video `erankly_get_sitemap_video_*` aliases; `erankly_custom_code_context_allowlist` alias.
- Deprecated: `erankly_import_apply`, `erankly_import_third_party`, `ERankly_Migration_Manager::update_report`.
- Possible unused in-set: `erankly_default_social_image_placeholder`, `erankly_default_organization_logo_placeholder`, `erankly_migration_referenced_backups`, `erankly_third_party_data_exists`.
- File header lies: global-meta.php and template-variables.php claim “always loaded early”; they are lazy via `erankly_load_content_helpers()`.
- Reset SQL meta delete does not `wp_cache_flush()` (restore path does).
- Large autoloaded settings option (custom code up to 100KB × 3 + legacy).
- Rank Math `if ( $sitemap ) { enable_sitemap = 1 }` treats any non-empty sitemap options as “sitemap on”.
- Adapters always emit mapped meta arrays with empty strings; job runner skips empty via `is_meaningful`.
- `erankly_send_response` ETag hashed from original body then `DOMDocument->save()` may emit different bytes.
- Redirect loop detector only follows exact cached rules, not pattern/regex chains.
- All regex redirects live in the global runtime bucket (every frontend request).
- Woo `erankly_get_og_image()` in product schema uses current query, not `$post_id`.
