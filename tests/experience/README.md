# Phase 14 targeted development QA

This directory records **Phase 14 development checks**, not the broader product-level Final QA 2.0. The latter remains a plan and requires separate authorization. Historical evidence under `tests/commercial/results` and earlier phase directories is not overwritten.

## Retained real environments

Phase 14 baseline commit: `1282c4b756b3a5b020aa18e62a9295cee213d549`, theme/Core 0.13.0, schema 1.9.0 / 33 Core tables. Reporting date: 2026-11-08 (Asia/Shanghai).

| Runtime | WordPress | PHP | MySQL | Local HTTP |
|---|---|---|---|---|
| p11qa-wp83 | 7.1.4 | 8.3.36 | 8.0.46 | localhost:18183 |
| p12qa-wp84 | 7.1.4 | 8.4.27 | 8.0.46 | localhost:18184 |

Both existing containers and volumes are retained. The PHP 8.4 binary is `php84`. Nothing resets or truncates a database, deletes old records, or exposes credential files. The managed Docker socket is always selected explicitly after clearing `DOCKER_HOST`, `DOCKER_CONTEXT`, `DOCKER_TLS`, `DOCKER_TLS_VERIFY`, and `DOCKER_CERT_PATH`.

`baseline.php` is read-only, requires CLI plus `AISH_PHASE14_QA=1`, and rejects databases other than the two known isolated QA databases. Its results contain counts and one-way fingerprints, not row contents. Baselines contain **1,844 / 1,332 Core rows**, respectively, all 33 DDL fingerprints, and native users/usermeta, private Inbox/revisions, and existing tutorial/page/revision groups. The tutorial groups were added before any source synchronization; earlier baseline fingerprints remained identical.

The retained QA MU plugin blocks external HTTP and intercepts outgoing `wp_mail`. Subscriber double confirmation and outbox persistence can therefore be tested against real WordPress/MySQL, but mail transport is **Mock**; it does not prove SMTP acceptance, bounce webhook delivery, or arrival in a recipient inbox. Browser-injected transport failures are explicitly labeled as fault injection.

## Executed results

`results/final-case-matrix.json` is the authoritative **deduplicated final case matrix**: **243 passed, 0 unresolved failed business cases** (PHP 8.3: 121; PHP 8.4: 122). A case is identified by runtime, domain and case ID. Later executed regressions supersede earlier outcomes; overlapping runs are not added twice. Raw assertion totals are not added to this case count. Historical initial failures remain available and are mapped to their actual regression evidence.

The real database portion covers onboarding ownership and concurrent merge, native help draft import/rollback/repeated import, independent languages, installation quotation and SHA binding, public sharing, explicit double consent, email-change A→B→A revocation, metadata guards, native privacy erasure/deletion, curated issues, bounded batch rollback/retry, pause/resume, unsubscribe, bounce, existing account/security mail compatibility and 30/180/90-day retention boundaries. All newsletter transport results are intercepted **Mock** results. No SMTP or recipient delivery was tested.

The independent PHP/MySQL processes exercise duplicate opt-ins, duplicate claimed messages, policy-lock contention, recovery of known-unsent messages and a deterministic cancellation/send barrier. Cancellation waits for an already-running send; once cancellation completes no later message is dispatched. The test selects only explicit owned outbox rows. This is a bounded concurrency check, not a load benchmark.

Chromium evidence includes Chinese/English at 375, 768 and 1440 pixels, actual navigation/language switching, help search/pagination, favorites/review/submission regression, clipboard success/failure, account introductions, consent/confirmation/unsubscribe, private SEO and native administrator screens. The final sources are `browser-php83.json`, `browser-public-php84.json` and `browser-newsletter-php84.json`. Their overlapping health cases are deduplicated in the final matrix.

Separate infrastructure evidence, **not included in the 243 business-case count**:

| Check | Executed outcome |
|---|---|
| Initial retained-data activation, repeated migration, switch/reactivation | 83 checks passed on each PHP runtime |
| Existing real Core schema/migration/lifecycle integration | 162 assertions passed on each runtime; random test tables cleaned |
| Native 0.13.0 ZIP installation, 0.14.0 upgrade, fresh PHP boot | 6 + 6 + 6 checks passed in an independent writable WordPress copy |
| Final package bytes versus repository/both runtime mounts/LICENSE | 4 checks passed; 214 ZIP files (213 theme files + LICENSE) |
| Final ZIP SHA-256 | `d7e7c5a32e88432395d7a204d3c9683d58c7dcb3ac219dac09686bb6a18aaae5` |
| SQL/latency observations | See `performance-notes.md`; limited real read-only samples, no SLO claims |

Two source issues were fixed and then verified: native Help/Subscriptions screens emitted headers after WordPress output (P2); the complete native privacy eraser hit `users_forbidden` after an earlier module removed its completion marker (P1). The latter now uses an identity-bound erasure context and passed the real native workflow. Email-consent round-trip revocation and native installation-option bypass guards were also covered after review fixes.

Several initial failures were test-contract errors, explicitly preserved: direct privacy extension dispatch without the native lifecycle, a reused request-local Catalog after archival, an obsolete outbox selection SQL pattern, assuming an HTTPS-only RFC8058 header on HTTP localhost, expecting `retry` instead of the documented paused `queued` state, and selecting the logout form as the submission editor. Corrected cases were reexecuted; no accepted message was resent just to obtain an assertion.

## Original-data comparison after all tests

The initial **unchanged** activation comparison is not substituted for the final comparison. `retained-data.php` separately compares each original dataset's count and full-set hash, using primary-key ordered original-count candidates to allow appended QA fixtures. A match proves the original IDs and row bytes remain present. An unmatched aggregate is **not** marked passed or repaired from guesses.

Final results preserve all 33 original table structures, schema 1.9.0 and original business Skill/translations/versions/favorites/reviews/submission/Contact/native user records. **40/43 groups match on PHP 8.3, 41/43 on PHP 8.4.** Remaining dataset differences are:

| Runtime | Unmatched original groups |
|---|---|
| PHP 8.3 | `notification_outbox`, `daily_metrics`, native `usermeta` |
| PHP 8.4 | `notification_outbox`, `daily_metrics` |

The initial campaign test's obsolete SQL selector could select retained QA queue rows; workers also run normal expiry recovery. Existing analytics regressions can recalculate shared aggregates. The first PHP 8.3 browser setup created a session for the retained QA administrator; later browser helpers use newly created owned administrators. These are observed test mutation paths, **not a proof of every individual changed row**. The pre-test baseline contains aggregate hashes, not plaintext row backups, so exact historical row reconstruction is unavailable. No original data has been fabricated, restored without a backup, or removed to hide these differences. The final reports state this limitation explicitly. Native ZIP upgrade separately compares the entire current dataset immediately before/after and preserves it exactly.

## Reproduction order

The import fault test expects a fresh Phase 14 help library. Do not reset the retained databases to rerun it. Use `help-regression.php` for the already-imported 42 QA articles, and use the explicit stored fixture regression scripts for already-created campaigns. Never resend a terminal accepted message.

1. Preserve the baseline JSON before copying Phase 14 production code.
2. After root integration, synchronize only deployable theme files into the existing theme mounts; run `preservation.php` before introducing Phase 14 fixtures. Repeated current migration and theme deactivation/reactivation must preserve all baseline data and 33 tables.
3. Exercise actual help/tutorial data management, independent languages, safe search/category/pagination, publication gates, related resources, onboarding state ownership, and installation/share contracts.
4. Exercise subscription explicit consent, account verification, token expiry and generations, duplicate requests, email changes, pause/bounce/cancel, issue queue dedupe, privacy handling and final eligibility checks. Use the existing outbox with intercepted transport and record accepted versus delivered honestly.
5. Check critical interleavings using independent PHP/MySQL processes where relevant; no production-scale benchmark claims.
6. Run real Chromium at 375, 768, and 1440 pixels in Chinese and English against the actual WordPress servers. Check navigation, keyboard/focus, form/errors, overflow, code scrolling, copy-only behavior, no private SEO, onboarding and subscription/unsubscribe flows.
7. Rerun the necessary existing account/community/submission/discovery/operations/commercial regressions with new output paths. Do not overwrite their original result files.
8. Validate the final root-built development ZIP through native WordPress installation/upgrade in an isolated writable copy and a fresh process boot. Compare every packaged file with repository and real runtime copies, preserving original data.

`service-tests.py`, `concurrency.py`, `mail-concurrency.py`, `regressions.py` and `final-matrix.py` provide the recorded sequence. `source-syncs.json` fingerprints every controlled runtime copy. The root agent separately records PHP syntax, isolated/Mock contracts, JavaScript checks, front-end build, documentation and package checks.

## External limits

No real SMTP/delivery, live GitHub or AI API, licensed WPML, current SEO-plugin combination, persistent cache/CDN, production TLS/load, multisite or physical devices are assumed available. Chromium viewport testing is not all-browser or hardware compatibility. Final QA 2.0 remains unstarted.
