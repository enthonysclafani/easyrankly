# EasyRankly line-audit — independent completion verdict (re-audit)

**Verdict: COMPLETE**

Report: `easyrankly-line-audit-report.md` (pass **3**). Plugin source was not modified by this auditor. Pass-3 “gaps closed” was treated as unproven and re-checked against disk + source.

---

## Inventory (live tree vs §1)

Method: walk excluding `.git`; `wc -l`; same exclusions as the report.

| | Disk | Report §1 |
| --- | ---: | ---: |
| First-party files | **183** | **183** |
| First-party lines | **73673** | **73673** (no `≈`) |
| Named table rows | 183 | 183 (no globs) |
| On disk, missing from table | **0** | |
| In table, missing from disk | **0** | |
| Line mismatches | **0** | |
| `tests/test-*.php` | **50 / 20940** | **50 / 20940**, one row each |
| `tests/js/*.cjs` | **3 / 1173** | 3 named rows |
| `includes/helpers/sanitization.php` | **889** | **889** |
| `easyrankly.php` | **1130** | **1130**, note says 1–1130 re-read |

Exclusions still OK: `.git/`; `vendor/` (1192); `.dist/easyrankly.zip`; `.phpunit.result.cache`.

Git dirty paths (`admin-settings.js`, `admin.js`, `test-js-contracts.php`, untracked autosave probe) look like parallel product work, not auditor artifacts.

---

## Prior gaps: closed vs still open

| # | Prior gap | Status |
| --- | --- | --- |
| 1 | 54 files / 21 128 lines lacked per-file notes | **Closed.** All 54 have §1 notes with purpose + `1–EOF` (or equivalent) + issues or «reviewed, no issues». Backed by `audit-notes-inf-a.md` / `audit-notes-inf-b.md`. Independent check: **0/54** fail the substance bar. |
| 2 | `tests/test-*.php` glob 49 / ~21 900 | **Closed.** 50 named rows, line sum **20940**. |
| 3 | Collapsed §3 (M/L/N, SEO-038–081, TST-011–039, ADM-005–029) | **Closed.** Each former range ID has a `####` with file + perché + azione (N-05 is a comment-policy nit without a path; N-08 is a pointer to FE-020). Last SEO is **SEO-075** (not padded to 081). New INF-062–064, INF-080–084, TST-041–047 exist as headings. |
| 4 | Dual ADM-01 vs ADM-001 headings | **Closed.** No `#### ADM-01`…`ADM-04` headings. Canonical IDs ADM-001–030; aliases only in parentheses. §0 documents the mapping. |
| 5 | Exact severity census (no `≈`) | **Closed on totals; split caveat below.** `grep '^#### '` = **285**. Claimed 2 superseded / 2 blocker / 42 high / 22 nit **match heading keywords**. Claimed 106 medium / 109 low **do not** (see census). |
| 6 | sanitization.php 889; easyrankly.php 794–1130 re-read | **Closed.** §1 = 889. INF-A documents `easyrankly.php` reviewed **1–1130**, including the REST block 794–1130 (user-search starts ~800). Spot-check INF-063 at `:762-776` is real. |
| 7 | §4 standalone prompt-ready | **Closed.** WP1–13 each have files, IDs, **Accettazione**. WP1–11 have **Test:**; WP12 encodes tests in acceptance (`PHPUnit verde`); WP13 has **Test da lanciare:**. WP2 includes `content-defaults.php`. |
| 8 | §5 empty only if leftover ranges truly none | **Closed** for PHP/JS/CSS/`tests/test-*.php`. Honest caveats remain (lock hashes, no browser CSS, probes not re-read in *expansion*). |

**Live-audit / probes caveat:** `tests/live-schema-audit.php` and `tests/js/*.cjs` were **not** byte-re-read in the expansion pass. They **do** have per-file notes from pass 2 (`audit-notes-tests.md` table: audited + line counts matching disk) and §1 rows citing TST-001/002/006/010/013/032. That is a real prior read, not a checkmark-only listing. **Does not fail.**

---

## Census (independent vs claimed)

Source: every `^#### ` heading in the report (285 unique IDs, 0 duplicate heading IDs).

| Bucket | Claimed | Counted from heading severity word |
| --- | ---: | ---: |
| Total `####` | 285 | **285** |
| superseded | 2 (B-01, INF-029) | **2** |
| blocker | 2 | **2** |
| high | 42 | **42** (H-01–11, INF-003–009, SEO-001–010, TST-001–010, ADM-001–003, INF-083) |
| medium | 106 | **122** |
| low | 109 | **93** |
| nit | 22 | **22** |
| high-adjacent | 1 (SEO-042) | **1** |
| medium-adjacent | 1 (FE-011) | **1** |

**Why 106/109 ≠ 122/93:** sixteen headings labeled `— medium —` sit under the Low section: SEO-053, SEO-068, SEO-069, SEO-073, SEO-075, INF-062, INF-063, INF-080, INF-081, INF-082, TST-041–045, TST-047. Counting those as low reproduces the claimed 106/109. The findings themselves are fully specified; only the §2 table split is section-hacked.

ID holes (not collapses): SEO-012/014/021/050/052; INF-011/016/030/038/041–047/056/058–060/065–079. INF-016 is **see also** under H-07 (and WP8), not its own `####`. Pointers INF-009=H-02 and SEO-010=H-03 are headings as claimed.

---

## Spot-checks (new + prior high-risk)

| Claim | Source | Result |
| --- | --- | --- |
| INF-062 flag after write ignore result `settings.php:267-316,319-381` | `:313-315` and `:376-381` set migrated flags after `erankly_update_plugin_settings` with no `true === $result` | **Match** |
| INF-063 network deactivate swallow `easyrankly.php:762-776` | catch `Throwable`, log only if `WP_DEBUG`, continue | **Match** |
| INF-064 `switch_to_blog` no finally `sanitization-schema.php:290-304` | switch, `get_post`, restore without try/finally | **Match** |
| INF-080 queue always new token `network-reset.php:225-258` | no already-running guard; always `update_site_option` | **Match** |
| INF-081 user 0 may import custom code `:260-264` | `return 0 === $user_id \|\| current_user_can('unfiltered_html')` | **Match** |
| INF-083 restore merge; purge skips `ERANKLY_OPTION` `:568-584,708-714` | `apply_settings` → `update_plugin_option`; purge deletes special meta/redirects/meta, not settings option; `update_plugin_settings` default `$replace=false` | **Match** |
| SEO-075 `url_to_postid` `:77-79` | site provider `:77-79` | **Match** |
| TST-041 REST save only title/noindex `:41-67` | `test-lifecycle-rest.php:41-67` | **Match** |
| ADM namespace | no `#### ADM-01` | **Match** |

These could not have been written without reading those lines.

---

## Prompt-ready? **Yes**

§0 is an execution prompt (WP order, ADM aliases, no vendor). §4 WP1–13 are ordered, with files, IDs, acceptance, and tests. A follow-up can start at WP1 without reconstructing collapsed IDs from notes. Notes remain useful detail, not a required second prompt.

Thin leftovers that do **not** block: WP12 has no `**Test:**` line; most findings omit `**type:**` (severity lives in the heading); N-05/N-08 are thin.

---

## Caveats that do not block completion

1. §2 medium/low split is 106/109 only if 16 medium headings in the Low section are counted as low. Independent heading-keyword split is **122 / 93**. Totals 285 still hold.
2. `composer.lock` package **hashes** not semantically audited (`packages: []` + instantiator 1.5.0 verified).
3. CSS read statically; viewport not exercised in a browser.
4. `live-schema-audit.php` and the three `.cjs` probes: pass-2 line-by-line notes exist; expansion did not re-read them.
5. Nineteen §1 rows still have **empty notes** (not in the former 54): e.g. `includes/breadcrumbs.php`, `includes/admin.php`, `includes/special-meta.php`, some CSS/JS. Those files already had pass-2 workstream notes (SEO/admin/frontend).
6. `audit-notes-inf-a.md` still says sanitization.php **890**; disk/report are **889**. `audit-notes-expansion.md` header still says last SEO **SEO-070**; the report goes to **SEO-075**.
7. INF-016 has no standalone heading (see H-07).
8. Uncommitted autosave-related plugin files exist; not treated as auditor output.

---

*Re-audit of pass 3. Uncertain evidence was not counted as coverage. Remaining caveats are documentation/census-split and stated tooling limits, not unread first-party ranges.*
