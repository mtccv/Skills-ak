# Phase 9 community QA

These tests use the existing isolated Codex Cloud WordPress/MySQL fixtures only:
`aishqa-browser` / `qa_browser` and `aishqa-php84` / `qa_php84`. They refuse other
database names and require `AISH_PHASE9_QA=1`. Never relax those guards to run on
business data. No external APIs or real SMTP are exercised; the existing Phase 8
MU fixture intercepts mail, which cannot prove delivery.

## Migration baseline

`baseline.php` loads WordPress without `WP_CLI`, does not call `admin_init`, and
only emits counts and hashes for the seventeen existing tables plus the private
inbox count. Capture it before new schema migration or test fixture writes. It
never outputs underlying rows, account details or encrypted payloads.

`migrate.php` explicitly upgrades that same fixture from 1.6.0 to 1.7.0, verifies
the physical schema and exactly twenty-five tables, repeats the migration, and
compares every original table fingerprint and private inbox count. It refuses a
fixture already migrated. The pre-migration snapshots in `results/` are evidence
for this run, not reusable expected data for unrelated databases.

```sh
docker exec -e AISH_PHASE9_QA=1 aishqa-browser php /repo/tests/community/baseline.php
docker exec -e AISH_PHASE9_QA=1 aishqa-php84 php /repo/tests/community/baseline.php
# Save each sanitized JSON to its matching baseline result before proceeding.
docker exec -e AISH_PHASE9_QA=1 aishqa-browser php /repo/tests/community/migrate.php
docker exec -e AISH_PHASE9_QA=1 aishqa-php84 php /repo/tests/community/migrate.php
```

## Domain and concurrency

`bootstrap.php` requires schema 1.7.0. Fixtures use generated native Subscriber
identities, real one-time verification credentials, and actual Management Skill
draft/approval workflows. Credentials remain process-local and are never printed.
Only generated `example.invalid` identities and clearly marked synthetic Skills
are added to these disposable sites; existing business records are not reset.

`integration.php` exercises target-state favorite replay/cancellation/restore,
cross-language ownership, missing translations and unpublished resources, strict
rating input, pending/approved/rejected/superseded review revisions, publication
pointer compare-and-swap, withdrawal/hide, separate reports and corrections,
private notes, SQL-bound hostile text and transactional failure recovery.

The fault-injection case briefly installs a random named trigger rejecting
workflow-event insertion, then removes that exact trigger in `finally`. It checks
the failed favorite is rolled back and a subsequent normal request succeeds. A
second injected failure checks Skill publication and its required workflow event
commit together, and unchanged content reapproval does not create a fake update.
Do not run competing mutating suites against that same prefix while either
bounded fault case is active. On this dedicated MySQL instance, binary logging
initially prevented trigger creation (`log_bin_trust_function_creators=0`). The
QA administrator restored the existing test prerequisite to `1`; both suites
then passed. This is a test-environment prerequisite, not a product deployment
requirement. Do not change production MySQL privileges for these tests.

`concurrency.php` starts independent PHP/MySQL processes behind private temporary
barriers. It tests same-key and different-key favorite additions, review creation
replay and compare-and-swap, and concurrent withdrawal versus administrator
approval. This is a small concurrency test, not a production load benchmark.

```sh
docker exec -e AISH_PHASE9_QA=1 aishqa-browser php /repo/tests/community/integration.php
docker exec -e AISH_PHASE9_QA=1 aishqa-php84 php /repo/tests/community/integration.php
docker exec -e AISH_PHASE9_QA=1 aishqa-browser php /repo/tests/community/concurrency.php
docker exec -e AISH_PHASE9_QA=1 aishqa-php84 php /repo/tests/community/concurrency.php
```

`notification-race.php` and its independent worker cover both lock orderings of
moderation notification creation versus account privacy erasure. A read-only
query observer pauses the real notification INSERT while its transaction holds
the recipient lock; the competing eraser must wait and subsequently remove that
notice. With privacy first, moderation must not create a post-erasure notice.
Both PHP versions passed two cases and eighteen assertions, using real MySQL
connections rather than an in-memory locking stub.

```sh
docker exec -e AISH_PHASE9_QA=1 aishqa-browser php /repo/tests/community/notification-race.php
docker exec -e AISH_PHASE9_QA=1 aishqa-php84 php /repo/tests/community/notification-race.php
```

## Actual browser workflow

`browser.cjs` launches the installed Chromium through Playwright against only
`http://127.0.0.1:18083`. `browser-control.php` is a guarded CLI-only bridge:
generated Subscriber passwords stay in the browser driver process and are never
included in saved reports. The moderator fixture has only the two community
capabilities, and retains the Subscriber role. Moderation runs through the real
WordPress admin UI and its normal Nonce handling, not an alternate test endpoint.

The bilingual workflow covers anonymous login return cookies, private saves and
removal, pending first reviews, approval, pending edits, rejection, reporting via
the real progressive dialog, independent Skill corrections, withdrawal, hiding,
unavailable saved resources, private response headers, public review pagination
SEO, cross-user access, Nonce/Origin denial, and no-JavaScript private controls.
It checks 320/768/1440px widths and captures synthetic fixture screenshots.

```sh
npm run build
node tests/community/browser.cjs
```

Browser output defaults to `/tmp/aish-final-qa/phase9-browser/`. Real user flows
run in Chromium only; Firefox, WebKit, external SMTP delivery and third-party
integrations are not established by this suite. Screenshots show marked QA
fixtures, not production data or actual user recommendations.

## Separate installable ZIP upgrade fixture

`package-upgrade.php` refuses any database except the existing dedicated
`qa_package` fixture. It uses native `Theme_Upgrader` to upgrade the frozen Phase 8
ZIP to Phase 9, compares the seventeen original table fingerprints, verifies the
1.7.0 physical schema and checks installed PHP/CSS/JS bytes against the actual
ZIP. Package evidence is recorded separately in `release/PHASE_9_PACKAGE.json`.

For reproduction, copy the guarded script and frozen ZIP into
`/tmp/aish-phase9-package` inside the isolated package container, run as
`www-data` with `AISH_PHASE9_QA=1`, `AISH_PACKAGE_MODE=upgrade` then `verify`,
and `AISH_QA_ZIP_SHA256` set to the exact frozen archive hash. Do not overwrite
the original pre-upgrade snapshot. The explicit `reinstall` mode is only for a
deliberately revised final archive after its earlier upgrade comparison passed.

Only final sanitized result JSON and synthetic-account screenshots belong in the
repository. Raw logs, database dumps, passwords, cookies, nonces and private mail
payloads do not. Executed results are reported separately from static/Mock tests
and unexecuted external integrations.
