# INF-B notes

Working tree authoritative. Every assigned file read via Read+offsets. No plugin/test/git mutations.

## Per-file

### includes/class-erankly-import-job-runner.php
- lines: 893
- reviewed: 1–893
- purpose: Crash-safe batched native JSON restore worker (stage spool, lock, apply settings/redirects/meta, purge-on-restore).
- Issues: INF-001, INF-002 (purge usermeta), INF-006, INF-081
- bloat/comments: Lock CAS copy-paste (settings/migration/import/start). Verbose phpcs ignores. `apply_payload_batch` is the deprecated sync path.

### includes/class-erankly-multilingual-provider-registry.php
- lines: 470
- reviewed: 1–470
- purpose: Fail-closed multilingual provider registry + context/hreflang helpers.
- Issues: INF-007 (also `erankly_get_hreflang_output_owner` memoizes early)
- bloat/comments: phpcs disable for mixed OO/functions. Boot catch swallows Throwable after `register_hooks()` may have already added hooks.

### includes/import-export.php
- lines: 15
- reviewed: 1–15
- purpose: Loader for actions/export/panel/report.
- Issues: reviewed, no issues
- bloat/comments: none

### includes/import-export/actions.php
- lines: 599
- reviewed: 1–599
- purpose: Import size/JSON guards, admin action dispatch, report/backup downloads.
- Issues: reviewed, no new issues (MS caps/URLs correct here; contrast INF-008 on redirects)
- bloat/comments: JSON scanner is long but bounded. `ERANKLY_IMPORT_JSON_MAX_DEPTH` defined in `easyrankly.php`.

### includes/import-export/export.php
- lines: 222
- reviewed: 1–222
- purpose: Streamed backup document + download + deprecated import wrappers.
- Issues: INF-039
- bloat/comments: Deprecated `erankly_import_apply` / `erankly_import_third_party`.

### includes/import-export/panel.php
- lines: 327
- reviewed: 1–327
- purpose: Import/export settings panel + notice renderer.
- Issues: reviewed, no issues
- bloat/comments: Notice switch is large but one-screen UX. Unused `erankly_third_party_data_exists` lives in report.php.

### includes/import-export/report.php
- lines: 498
- reviewed: 1–498
- purpose: Migration assistant UI (steps, attention, live job, history).
- Issues: INF-040 (presenter reads `settings_invalid`)
- bloat/comments: `erankly_third_party_data_exists` looks unused in-set.

### includes/localized-value-writer.php
- lines: 365
- reviewed: 1–365
- purpose: Allowlisted CAS writer for localized SEO source strings.
- Issues: INF-013
- bloat/comments: MS hard-refuse is intentional. Sanitizer loaded via `admin/settings-page.php` (different path than import runner).

### includes/migrations.php
- lines: 145
- reviewed: 1–145
- purpose: Migration subsystem loader + per-blog data-transfer start lock + manager/runner accessors.
- Issues: INF-003
- bloat/comments: Lock CAS duplicated with import/migration job locks.

### includes/migrations/class-erankly-migration-adapter.php
- lines: 1061
- reviewed: 1–1061
- purpose: Shared adapter contract, keyset meta/table scans, robots/schema helpers, coarse fingerprint.
- Issues: INF-020, INF-082
- bloat/comments: `global_robots` / `has_robot_configuration` suffix matching is dense. `iterable_batch` offset fallback documented as not for production. `fingerprint_columns` on table defs are unused (COUNT+MAX only).

### includes/migrations/class-erankly-migration-adapter-aioseo.php
- lines: 924
- reviewed: 1–924
- purpose: AIOSEO v3/v4/v5 settings, posts/terms, PRO redirects.
- Issues: INF-004 (via convert on titles + `website_name`/`organization_*`), INF-050, INF-020 (`old_schema_entity`)
- bloat/comments: Large nested-option walk. `table_rows` + `content_records` exist beside `content_batch`.

### includes/migrations/class-erankly-migration-adapter-rankmath.php
- lines: 738
- reviewed: 1–738
- purpose: Rank Math titles/sitemap/redirections + meta.
- Issues: INF-014, INF-023
- bloat/comments: Redirect iterable + batch duplicate mapping. `fingerprint_columns` unused.

### includes/migrations/class-erankly-migration-adapter-seopress.php
- lines: 704
- reviewed: 1–704
- purpose: SEOPress titles/social/sitemap + post/term/CPT redirects.
- Issues: reviewed, no new IDs (logged-in redirects correctly flagged unsupported via `visibility`)
- bloat/comments: `redirect_records` and `map_redirect_record` duplicate. `_seopress_robots_index` checked → noindex is the SEOPress checkbox polarity.

### includes/migrations/class-erankly-migration-adapter-yoast.php
- lines: 899
- reviewed: 1–899
- purpose: Yoast titles/social/schema + premium/legacy redirects + taxonomy option.
- Issues: INF-049
- bloat/comments: `taxonomy_option_batch` / `redirect_option_batch` load the whole option then page in PHP. `has_redirect_data` treats any array option as “has redirects”.

### includes/migrations/class-erankly-migration-admin-presenter.php
- lines: 145
- reviewed: 1–145
- purpose: Compact UI state from a persisted report.
- Issues: INF-040
- bloat/comments: none

### includes/migrations/class-erankly-migration-job-runner.php
- lines: 1056
- reviewed: 1–1056
- purpose: Resumable third-party preview/import worker.
- Issues: INF-005, INF-040, INF-082, INF-084
- bloat/comments: Lock CAS copy-paste. `empty_counts()` has no `settings_invalid`. Backup is created before the start lock.

### includes/migrations/class-erankly-migration-manager.php
- lines: 307
- reviewed: 1–307
- purpose: Adapter factory, report history, verification checklist.
- Issues: INF-040 (`settings_invalid` never in counts; verification ignores it)
- bloat/comments: Deprecated `update_report`. `finish_report` `update_option` without CAS (low; report history only).

### includes/migrations/class-erankly-migration-source-changed-exception.php
- lines: 13
- reviewed: 1–13
- purpose: Exception type for mid-run source change.
- Issues: reviewed, no issues
- bloat/comments: none

### includes/migrations/class-erankly-migration-upload-store.php
- lines: 415
- reviewed: 1–415
- purpose: Private OS-temp staging for uploads and pre-import backups.
- Issues: reviewed, no issues
- bloat/comments: Public-path rejection + chmod 0700/0600 is thorough. `prune_stale` does not protect an active migration backup except by TTL.

### includes/migrations/legacy-cleanup.php
- lines: 55
- reviewed: 1–55
- purpose: Purge retired migration options/tables/cron.
- Issues: reviewed, no issues
- bloat/comments: none

### includes/migrations/runtime-backup.php
- lines: 230
- reviewed: 1–230
- purpose: Pre-import backup write/validate/restore.
- Issues: INF-001 (restore uses `start_from_file` + purge)
- bloat/comments: `erankly_migration_referenced_backups` possibly unused in-set.

### includes/migrations/runtime-database.php
- lines: 19
- reviewed: 1–19
- purpose: `SHOW TABLES LIKE` with `esc_like`.
- Issues: reviewed, no issues
- bloat/comments: none

### includes/migrations/runtime-redirects.php
- lines: 125
- reviewed: 1–125
- purpose: Normalize imported/migrated redirects; convert contains/starts_with/ends_with → regex.
- Issues: INF-023
- bloat/comments: none

### includes/migrations/runtime-variables.php
- lines: 190
- reviewed: 1–190
- purpose: Third-party template token → EasyRankly `{{…}}`.
- Issues: INF-004
- bloat/comments: Large token map is the contract.

### includes/network-reset.php
- lines: 466
- reviewed: 1–466
- purpose: Resumable network-wide reset job (sitemeta CAS for progress; not for queue).
- Issues: INF-024, INF-080
- bloat/comments: Progress CAS is careful; queue is not.

### includes/redirects.php
- lines: 75
- reviewed: 1–75
- purpose: Redirect module boot + dbDelta gate + panel shim.
- Issues: reviewed, no issues
- bloat/comments: REST registered unconditionally (needed for `/wp-json/`).

### includes/redirects/class-erankly-redirects-activator.php
- lines: 146
- reviewed: 1–146
- purpose: Create/upgrade redirects table; one-time v3 backfill.
- Issues: INF-025
- bloat/comments: none

### includes/redirects/class-erankly-redirects-admin.php
- lines: 832
- reviewed: 1–832
- purpose: Redirects tab CRUD UI.
- Issues: INF-008
- bloat/comments: Form/table markup is large but single panel. Sort/pagination links hardcode `admin_url('options-general.php')`.

### includes/redirects/class-erankly-redirects-normalizer.php
- lines: 472
- reviewed: 1–472
- purpose: Path/query normalize, regex/wildcard compile, evaluate, validate.
- Issues: reviewed, no new issues (supports INF-016/017 at call sites)
- bloat/comments: `normalize_target_url` `wp_unslash` on import path that is already unslashed (comment on `normalize_source` warns against a second unslash).

### includes/redirects/class-erankly-redirects-repository.php
- lines: 653
- reviewed: 1–653
- purpose: CRUD + runtime rule buckets + hit sampling.
- Issues: INF-017
- bloat/comments: none

### includes/redirects/class-erankly-redirects-rest.php
- lines: 162
- reviewed: 1–162
- purpose: Admin AJAX toggle/delete/test routes.
- Issues: INF-008
- bloat/comments: none

### includes/redirects/class-erankly-redirects-runner.php
- lines: 235
- reviewed: 1–235
- purpose: Frontend `parse_request` matcher + loop guard.
- Issues: INF-016/H-07, H-08
- bloat/comments: `allowed_redirect_hosts` filter is not removed (request exits). `find_advanced_match` first hit after `compare_rules` sort.

### includes/reset.php
- lines: 387
- reviewed: 1–387
- purpose: Site reset + network-reset queue + Reset card UI.
- Issues: INF-002, INF-018
- bloat/comments: Network-admin “this site” copy says other sites are untouched; usermeta wipe is network-global (INF-002).

---

## Findings

### INF-001 — high — restore integrity
`includes/class-erankly-import-job-runner.php:232,486–488,688–727,739–746`
`start_from_file` sets `purge_owned=true`. First settings batch calls `purge_owned_data()` (SQL wipe of plugin meta + special meta + all redirect rows), then `apply_settings`. `process()` catch calls `finish($job,'failed')` which **deletes the spool**. Interrupted restore after purge cannot resume. Used by `erankly_migration_restore_backup`.
**Action:** Keep the spool until a verified complete replay, or refuse to purge until the first checkpoint after settings+a durable “purged” flag is saved; never `finish(failed)`-unlink after purge.

### INF-002 — high — multisite data loss
`includes/reset.php:213–218` (also `class-erankly-import-job-runner.php:693–698`)
`DELETE FROM $wpdb->usermeta WHERE meta_key LIKE '_erankly_%'` (reset) / `meta_key IN (plugin keys)` (restore purge). Usermeta is network-global. A “this site” reset or one-site restore wipes author SEO for every site.
**Action:** Scope user-meta deletes to users that belong to the current blog, or store author SEO in blog-scoped storage.

### INF-003 — high — lock topology
`includes/migrations.php:35–67`
`erankly_acquire_data_transfer_start_lock` CAS-updates `$wpdb->options` (blog). EasyRankly settings on Multisite are `get_site_option`. Two sites can start import/migration against the same network settings option.
**Action:** Use sitemeta (or a network option) for the start lock on MS.

### INF-004 — medium — mapping correctness
`includes/migrations/runtime-variables.php:151–180`
AIOSEO pattern `/#([a-z0-9_]+)/i` matches CSS colors, URL fragments, schema `@id` hashes. Unmapped tokens kept; mapped ones rewritten (`#title` → `{{post_title}}`). AIOSEO also runs `$convert` on `website_name` / organization strings (`class-erankly-migration-adapter-aioseo.php:117,253`).
**Action:** Only replace known `#token` forms with word boundaries / AIOSEO’s actual delimiter set; do not convert identity fields.

### INF-005 — high — migration integrity
`includes/migrations/class-erankly-migration-job-runner.php:394–400`
Source fingerprint is verified only after content+redirects are written. Mid-run source change → exception → pause with data already mutated; resume re-hits the same check.
**Action:** Re-fingerprint before each write batch (or at least before the first write of each stream).

### INF-006 — medium — concurrency
`includes/class-erankly-import-job-runner.php:582–583`
Native import still goes through `erankly_update_plugin_option(ERANKLY_OPTION, $clean)`. Working tree now routes that into `erankly_update_plugin_settings` (mutex), but **without `$replace=true`**, so it merges into live settings. `special_meta` is a separate option write. Distinct from INF-083 (merge vs snapshot).
**Action:** Restore with `$replace=true`; keep mutex.

### INF-007 — medium — multilingual correctness
`includes/class-erankly-multilingual-provider-registry.php:318–329,384–396`
`erankly_get_multilingual_context()` sets `$resolved=true` before `close_and_boot`; empty context is memoized for the request. `erankly_get_hreflang_output_owner()` likewise memoizes `none` if called pre-boot.
**Action:** Do not memoize until the registry is closed and booted.

### INF-008 — medium — capability / MS routing
`includes/redirects/class-erankly-redirects-rest.php:65`
`includes/redirects/class-erankly-redirects-admin.php:73,111,617,667,820–829`
Redirect REST/admin gate is `manage_options` only. Form/sort/pagination/`admin_url()` always use `admin_url('options-general.php')`, even when the rest of settings live under Network Admin.
**Action:** Mirror import-export (`manage_network_options` + `network_admin_url` when `is_network_admin()` / MS).

### INF-013 — medium — mapping
`includes/localized-value-writer.php:27–28`
`archive_seo_title` / `archive_seo_description` map to `global_special_meta.author`, not date archives.
**Action:** Map archive keys to the date (or a dedicated archive) special-meta slot.

### INF-014 — medium — mapping
`includes/migrations/class-erankly-migration-adapter-rankmath.php:275–277`
`if ( $sitemap ) { $settings['enable_sitemap'] = 1; }` treats any non-empty `rank-math-options-sitemap` array as “sitemap on”.
**Action:** Read the actual sitemap-module / enable flag.

### INF-016 / H-07 — medium — redirect safety
`includes/redirects/class-erankly-redirects-runner.php:171–207`
Loop detector only follows `get_exact_rule_cached` hops. Pattern/regex chains are invisible; a regex→exact→regex loop is not stopped.
**Action:** Evaluate pattern rules on each hop, or refuse to follow non-exact targets in the detector.

### H-08 — medium — redirect skip list
`includes/redirects/class-erankly-redirects-runner.php:93–121`
`should_skip_request` skips admin/ajax/cron/REST/login. It does not skip `xmlrpc.php` (or feeds/sitemaps).
**Action:** Skip `xmlrpc.php` (and decide explicitly for feeds/sitemaps).

### INF-017 — medium — frontend cost
`includes/redirects/class-erankly-redirects-repository.php:171–202`
Regex rules never get a path prefix (only exact, and wildcards with a complete first segment). All regex rules land in `global.any` and run on every frontend request.
**Action:** Bucket regexes that have a literal prefix; keep only unanchored/global patterns in `global.any`.

### INF-018 — medium — cache
`includes/reset.php:191–222`
Reset SQL-deletes post/term/user meta and does not `wp_cache_flush()`. Import purge does flush (`class-erankly-import-job-runner.php:707`).
**Action:** Flush object cache after bulk SQL meta deletes.

### INF-020 — low — sanitizer mismatch
`includes/migrations/class-erankly-migration-adapter.php:855,869`
`includes/migrations/class-erankly-migration-adapter-aioseo.php:897`
Schema `@type` / AIOSEO old schema type use `preg_replace('/[^A-Za-z0-9_-]/','')` instead of `erankly_sanitize_schema_type_name`.
**Action:** Use the same sanitizer as settings.

### INF-023 — medium — mapping
`includes/migrations/class-erankly-migration-adapter-rankmath.php:422–429,521–528`
`includes/migrations/runtime-redirects.php:32–42`
Rank Math `contains` is emitted then converted to an unanchored `preg_quote(normalize_path())` regex. `normalize_path` strips host/query, so Rank Math “contains” on a full URL/query becomes a tighter path substring.
**Action:** Preserve Rank Math contains semantics (or document and skip).

### INF-024 — medium — race
`includes/network-reset.php:240`
`erankly_queue_network_reset` uses `update_site_option` with no CAS. Two queue calls overwrite tokens; leftover cron args no-op.
**Action:** CAS-insert the job; see also INF-080.

### INF-025 — medium — memory
`includes/redirects/class-erankly-redirects-activator.php:71`
One-time v3 backfill `SELECT * FROM redirects ORDER BY id ASC` with no LIMIT.
**Action:** Keyset-page the backfill.

### INF-039 — medium — MS export
`includes/import-export/export.php:159`
`special_meta` is `get_option` (current blog only). Settings on MS are the network option. Network-admin export captures only the current blog’s special pages.
**Action:** Export/import special meta for the intended blog scope (or all sites).

### INF-040 — low — counters
`includes/migrations/class-erankly-migration-manager.php:111–149`
`includes/migrations/class-erankly-migration-job-runner.php:447–493`
`includes/migrations/class-erankly-migration-admin-presenter.php:122`
`settings_invalid` is read by the presenter and never exists in `empty_counts()` / never incremented. Invalid settings are `continue`d (`! array_key_exists($key, $sanitized)`).
**Action:** Increment `settings_invalid` or drop the counter.

### INF-049 — medium — availability
`includes/migrations/class-erankly-migration-adapter-yoast.php:339–349,332–336`
`is_array(get_option('wpseo_taxonomy_meta'))` and the premium redirect options treat `[]` as available / “has redirect data”. Empty Yoast options still enable the source dropdown and `enable_redirects`.
**Action:** Require a non-empty map (`has_option_map`).

### INF-050 — medium — mapping
`includes/migrations/class-erankly-migration-adapter-aioseo.php:528–529,561–562`
`source_url_match` is only `regex` or `exact`. AIOSEO contains/start/end (and similar) silently become exact.
**Action:** Map the other match types or mark unsupported.

### INF-080 — medium — race / job integrity
`includes/network-reset.php:225–258`
`erankly_queue_network_reset()` always writes a new token and `last_processed_id=0`. There is no “already running” guard. A second Network Admin click replaces an in-flight job; the old worker’s later CAS fails, but it may already be inside `switch_to_blog` + `erankly_reset_site_data` while the new job resets the same sites.
**Action:** Refuse to queue when status is pending/running/retrying; CAS-insert only when absent.

### INF-081 — medium — authz
`includes/class-erankly-import-job-runner.php:260–264`
`current_user_may_import_custom_code()` returns true when `get_current_user_id() === 0`. The comment talks about the cron worker, but this function is only called from `start()` / `start_from_file()`, never `process()`. The start path is the stated security boundary. WP-CLI, a future REST/cron wrapper, or any user-0 caller can stage raw custom HTML.
**Action:** Require `unfiltered_html` for a real user; reject user 0 at start (the worker already trusts a staged spool).

### INF-082 — medium — fingerprint completeness
`includes/migrations/class-erankly-migration-adapter.php:892–927`
Meta/table fingerprints are `COUNT(*)` + `MAX(id)` only (comment admits this is for insert/delete). In-place UPDATEs of existing Yoast/AIOSEO/Rank Math rows do not change the fingerprint, so INF-005’s end-of-run check cannot see content edits. Option surfaces are hashed whole (OK). Declared `fingerprint_columns` are unused.
**Action:** Checksum consumed columns, or include `MAX(umeta_id/meta_id)` plus a value hash where the engine allows.

### INF-083 — high — restore integrity
`includes/class-erankly-import-job-runner.php:568–584,708–714`
`purge_owned_data()` clears meta, special meta, and redirects. It does **not** delete `ERANKLY_OPTION`. `apply_settings` then writes via `erankly_update_plugin_option` → `erankly_update_plugin_settings($clean)` with **`$replace=false`**, i.e. `array_replace($current, $clean)`. Keys present in live settings but absent from the backup snapshot survive a “restore to snapshot”. Contradicts the spool comment that a restore must land on the snapshot.
**Action:** Purge or `$replace=true` for settings on `purge_owned` restores.

### INF-084 — low — race / I/O
`includes/migrations/class-erankly-migration-job-runner.php:94–124`
The full pre-import backup is written **before** `erankly_acquire_data_transfer_start_lock()`. Two concurrent migrate clicks both stream a complete export; the loser discards its file. During the unlocked backup, a native import can already be running (import releases the start lock after launch).
**Action:** Acquire the start lock before `erankly_migration_create_backup()`, or fail if an import job is already active before starting the export.

---

## Remaining ranges
None. All assigned path:line-ranges read.
