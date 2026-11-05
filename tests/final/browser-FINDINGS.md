# Browser Final QA findings and evidence

Scope: disposable `aishqa-browser` / `qa_browser`, WordPress 7.1.4, PHP 8.3.36, MySQL 8.0.46; real browser HTTP, admin forms and persistent database records. Explicit QA fixtures never represent production resources, statistics or platform compatibility claims.

## Product finding recorded before repair

- **SEO-01 / P1** (shared with the SEO/security workstream): a normal Skills query containing spaces (`QA Browser`) combined with category, tag, platform and title sorting returned 503. The first valid Chromium run therefore had **106 passed, 2 failed, 0 skipped**: the combination-filter case and its resulting console-error case. These are two observations of one defect. Real WordPress `add_query_arg()` does not URL-encode newly supplied values; Router rejected its own URL. The Router owner recorded and fixed the cause in `SEO_FINDINGS.md`, without changing the data model. Subsequent real browser regression verified the combination query, page 2, preserved keyword/filter parameters and no-results state.

No additional confirmed production defect was found in this browser workstream. See sibling Final QA reports for database, queue, API, security and plugin findings; this document does not claim to supersede them.

## Setup and harness corrections, not product defects

- The first web attempt returned an empty 200 because the read-only mounted workspace retained 700/600 permissions. WordPress CLI ran as root while Apache could not read the theme. Theme directories/files were set to 755/644; the original failed result remains in the temporary evidence directory.
- WP-CLI `eval-file` prefixes code and rejected the fixture's `declare(strict_types=1)`. The supported command now uses `wp eval 'require "...";'`. The one initial QA PHP fatal in `debug.log` is that test-loader error; subsequent application requests are checked separately.
- WebKit's Cloud libraries were missing. They were downloaded and extracted under `/tmp`, then linked into the browser's private library directory. The installed browser itself was launched and tested; a downloaded executable alone is never reported as a passed browser run.
- A transient automatic-approval review failed because account usage was exhausted. No rejected command ran. After the user's instruction to continue, the same authorized browser checks were approved and resumed.

## Evidence interpretation

Public routes cover both languages at desktop, tablet and mobile sizes in Chromium; Firefox/WebKit cover desktop and mobile. Frontend data are actual database fixtures. Chromium performs native taxonomy/repository editing and archive confirmation, bilingual Skill creation, review/publication, human edit invalidation, private preview access control, native tutorial metadata updates, private Inbox handling, subscriber denial and single-AI-capability navigation. A separate database check confirms persisted state rather than trusting success notices.

Screenshots supplement DOM/HTTP checks; they do not establish a formal accessibility audit or physical-device testing. Exact final counts, engine versions and per-case evidence are kept in `results/browser-*.json`. Temporary earlier failures remain separate from those final results.

## Final actual results

| Executed check | Passed | Failed | Skipped |
| --- | ---: | ---: | ---: |
| Chromium 155.0.8059.79, frontend and native admin | 112 | 0 | 0 |
| Firefox 153.0, public desktop/mobile | 52 | 0 | 0 |
| WebKit 26.5, public desktop/mobile | 52 | 0 | 0 |
| Real database persistence after the final Chromium run | 13 | 0 | 0 |

Final Chromium includes a last assertion after all admin actions for asset and runtime failures. Intentional private-resource/404 requests produce expected document-level 404 console messages; there were zero unexpected runtime errors and zero failed script/style/image/font assets. The QA PHP log still contains only the initial loader failure described above, with no new application diagnostic after setup. Both PHP harness files passed PHP 8.3 syntax checks; both Node scripts passed `node --check`; `git diff --check` passed.

The final JSON files were checked against the private test-account passwords before copying them into `results/`. Three selected screenshots contain only explicit QA content and no credentials: desktop Chinese homepage, mobile English Skill detail and mobile native Skill editor. All remaining screenshots stay in the temporary QA directory.
