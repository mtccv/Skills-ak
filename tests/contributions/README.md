# Phase 10 isolated WordPress/MySQL and browser checks

These fixtures run only with `AISH_PHASE10_QA=1` from PHP CLI inside the existing disposable `qa_browser` or `qa_php84` databases. They never reset a database. Synthetic identities begin `p10_`; generated resources are clearly marked as QA fixtures. The browser bridge exchanges generated test credentials over private process stdin/stdout; reports do not contain credentials.

`baseline.php` captured schema 1.7.0 before the Phase 10 migration. `migrate.php` then applied 1.8.0 and repeated it, compared the physical structure and every row fingerprint of all original 25 tables, and compared private Inbox posts, postmeta and revisions. The saved baseline and migration reports are historical one-time evidence and must not be regenerated after mutable fixtures.

The following scripts exercise actual WordPress and MySQL, with external HTTP blocked and `wp_mail` intercepted by a test-only mock:

- `integration.php`: private native Inbox ownership, verified accounts, validation, exact source deduplication, drafts, revisions, CAS, manual review, immutable update proposals, rollback and idempotence.
- `developers.php`: opt-in reviewed snapshots, bilingual boundaries, claim evidence and roles, private data projections, native editor bypass protection and notifications.
- `concurrency.php` / `race-worker.php`: independent PHP processes and MySQL sessions, concurrent source deduplication, state races and claim/profile identity conflicts. These are bounded concurrency tests, not load benchmarks.
- `privacy.php`: exporter, eraser and native account deletion integration (maintained by the schema/privacy workstream).
- `browser.cjs` / `browser-control.php`: actual Chromium forms, native administrator moderation, public/private access, bilingual routes, no-JavaScript fallback and 320/768/1440 responsive overflow checks.

Run these only after the target QA database has migrated to schema 1.8.0 and the Core contribution module is fully wired. A representative command is:

```sh
docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE10_QA=1 aishqa-browser php /repo/tests/contributions/integration.php
```

Use `aishqa-php84` for the second PHP runtime. The managed Cloud Docker runtime requires the documented session proxy and CA trust; do not override its networking. The browser runner needs the installed Playwright module and `/usr/bin/chromium`; it writes screenshots and a sanitized `browser.json` to `AISH_PHASE10_RESULTS` or `/tmp/aish-final-qa/phase10-browser`.

Mail transport success here means the mock accepted a call. These scripts do not prove delivery through a real SMTP service, real GitHub API validity, remote evidence authenticity, AI API operation, or WPML compatibility. None of the user-supplied source URLs is fetched or executed.

## Executed evidence — 2026-11-06

Both real sites run WordPress 7.1.4 and MySQL 8.0.46; PHP runtimes are 8.3.36 and 8.4.27. `results/summary.json` is the final machine-readable index. Counts below distinguish test cases from assertions.

| Real check | PHP 8.3 | PHP 8.4 |
| --- | ---: | ---: |
| First 1.7→1.8 migration, repeat migration, original table/Inbox preservation | 59 checks | 59 checks |
| Submissions, source identity, revisions, state machine, late result linking, query batches and rollback | 13 cases / 78 assertions | 13 cases / 78 assertions |
| Reviewed profiles, claims, native bypass, privacy consent, cache and hook isolation | 8 cases / 59 assertions | 8 cases / 59 assertions |
| Privacy export/erase/native deletion and bounded recovery | 5 cases / 47 assertions | 5 cases / 47 assertions |
| Independent-process bounded concurrency | 7 cases / 22 assertions | 7 cases / 22 assertions |
| Existing Core historical migration/theme lifecycle regression | Not repeated | 148 assertions |
| Existing Phase 9 community regression | Not repeated | 23 cases / 114 assertions |

The final Chromium workflow passed **102 checks**, with **0 failures or recorded page/console/asset/PHP errors**. It covers Chinese/English, 320/768/1440 CSS pixel widths, no-JavaScript list access, protected form submissions, native review screens, source deduplication, reviewed developer pages and public Skill attribution. There are 26 local screenshots; four synthetic-data examples are retained under `results/screenshots/`.

The final ZIP was installed through actual `Theme_Upgrader::upgrade` from 0.9.0 to 0.10.0 on the separate retained `qa_package` site. A fresh bootstrap migrated schema 1.7→1.8, preserved every original table fingerprint (25 tables, 18 rows), and verified all 144 installed files exactly against the archive. The installed package then passed **17 real HTTP checks**. See `release/PHASE_10_PACKAGE.json` and `results/package-*.json` for its precise archive hash and evidence. This package test uses PHP 8.4; PHP 8.3 service behavior is covered above.

Actual issues found during integration were corrected and regressed: missing PublicCatalog filter argument for update suggestions; default native nicenames throwing during pending-profile attribution; WordPress remapping native Inbox capabilities before the object guard; native revision metadata mapping to its parent; multi-record result projections exceeding the catalog's bounded batch limit; and caller-suspended cache invalidation. Public profile button spacing was corrected after screenshot inspection. Historical fault-injection tests initially could not create MySQL triggers after the existing container restart reset a dynamic QA setting. The isolated trigger prerequisite was enabled only for those unchanged tests and restored afterward; this was an environment prerequisite, not an application failure.

The developer transaction test intentionally invokes one HTTP request and one `wp_mail` call from a native profile hook. Both are blocked inside the transaction. The recorded counters reflect attempts seen by the test interceptors, not real network traffic or email delivery. All other new domain groups report zero external HTTP attempts.

Real SMTP delivery, remote GitHub evidence/API validity, paid AI providers, WPML, other browser engines and sustained production load remain unexecuted. Neither the mock mail interceptor nor a locally accepted review proves those external systems work.
