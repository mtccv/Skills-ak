# Phase 8 account QA

These scripts use real WordPress Users, sessions, privacy requests and MySQL.
The mail fixture intercepts `wp_mail()` before SMTP. Its accepted result is **Mock
transport acceptance**, never evidence of real mail delivery.

Use only an isolated Codex Cloud site with database name `qa_browser` or
`qa_php84`, `AISH_PHASE8_QA=1`, the repository mounted read-only at `/repo`, and
the official WordPress runtime at `/var/www/html`. Do not broaden the scripts'
database guards for a business site. Test users have generated credentials and
`example.invalid` addresses. They are synthetic QA accounts, not product data.

The existing Final QA runtime was restarted with its test volumes intact:
WordPress 7.1.4, PHP 8.3.36 / 8.4.27 and MySQL 8.0.46. The host has no PHP CLI;
checks execute in the corresponding WordPress container. No production database
or remote server is involved.

## Preparation

1. Build theme assets normally. Apache must read source files (0644) and traverse
   directories (0755). Never apply those permissions to credentials.
2. Capture `baseline.php` before migrating, saving the JSON to the private QA
   directory as `/qa/accounts-baseline-browser.json` or
   `/qa/accounts-baseline-php84.json`. It outputs counts and cryptographic
   fingerprints only, never underlying content. This run captured schema 1.5.0
   before the trusted upgrade; all original 14 table fingerprints are compared.
3. Generate a unique random 32-byte key for each QA site. Save its base64 value
   in `/var/www/aish-phase8-key.json` outside the document root, readable only by
   the QA PHP service account, with shape `{"key":"<generated value>"}`. Do
   not commit this file or print it. Copy `qa-mail-mu.php` into that disposable
   site's MU plugin directory. The fixture refuses other database names and
   intercepts every mail call; its message bodies remain request-local memory.
4. Disable automatic Cron in QA. Workers are invoked explicitly. Dedicated
   encryption readiness is not proof of a configured real SMTP service.

## Commands

```sh
docker exec -e AISH_PHASE8_QA=1 aishqa-browser php /repo/tests/accounts/migration.php
docker exec -e AISH_PHASE8_QA=1 aishqa-browser php /repo/tests/accounts/integration.php
docker exec -e AISH_PHASE8_QA=1 aishqa-browser php /repo/tests/accounts/concurrency.php
docker exec -e AISH_PHASE8_QA=1 aishqa-php84 php /repo/tests/accounts/migration.php
docker exec -e AISH_PHASE8_QA=1 aishqa-php84 php /repo/tests/accounts/integration.php
docker exec -e AISH_PHASE8_QA=1 aishqa-php84 php /repo/tests/accounts/concurrency.php
docker exec -e AISH_PHASE8_QA=1 aishqa-browser php /repo/tests/accounts/privacy-guards.php
docker exec -e AISH_PHASE8_QA=1 aishqa-php84 php /repo/tests/accounts/privacy-guards.php
node tests/accounts/browser.cjs
node tests/accounts/legacy-smoke.cjs
```

Run the private export test as the same service user as Apache. Repeat for
`aishqa-php84`; its private export directory deliberately cannot be read by
other Unix users. The temporary script contains no credentials.

```sh
docker cp tests/accounts/privacy-export.php aishqa-browser:/tmp/aish-phase8-privacy-export.php
docker exec aishqa-browser chmod 0644 /tmp/aish-phase8-privacy-export.php
docker exec --user www-data -e AISH_PHASE8_QA=1 aishqa-browser php /tmp/aish-phase8-privacy-export.php
```

The directory guard runs each configuration in a separate PHP process; repeat
for `aishqa-php84`. Each case must reject the path without changing permissions.

```sh
for case in filesystem_root system_temporary_root web_root web_root_ancestor; do
  docker exec -e AISH_PHASE8_QA=1 -e AISH_EXPORT_DIRECTORY_CASE="$case" aishqa-browser php /repo/tests/accounts/privacy-directory.php
done
```

`migration.php` creates a fresh random table prefix, installs the real 1.5.0
schema, intentionally obstructs one new table with a view, verifies the failed
migration retains its old version, then recovers and checks all 17 tables. Its
cleanup drops only its own random test tables, never current business tables.

`integration.php` uses new native QA accounts and the current schema. It covers
registration, fixed Subscriber role, duplicate addresses, verification hashes,
authenticated encryption, expiry/replay/resend, native login and reset, email
change, native admin change invalidation, private profile ownership, account
capabilities, REST/application-password restrictions, brute-force limits,
privacy request/export/erasure and native deletion recovery. It resets only the
disposable site's Phase 8 test rate counters before a repeated suite; all
within-test security checks exercise the actual limiter.

The legacy-admin case also seeds an empty historical email on one generated
native identity, then verifies native profile editing, login, capabilities and
deletion. It never changes the existing administrator or permits new public
registration without an email address.

`privacy-guards.php` verifies pinned native request identity, recycled email
protection, transactional native tables, private avatar filtering and deletion
staging. `privacy-export.php` generates a real WordPress ZIP outside the webroot
and requests the download over real HTTP as its owner, another account and an
anonymous client. It checks expiry, filesystem permissions, native export-email
links and bilingual templates; email transmission still uses the Mock fixture.

Mail cases distinguish an explicitly proven Mock rejection (bounded retry),
bare `false` and exceptions (uncertain, no blind retry), accepted transport
(clear ciphertext), abandoned claimed work (recoverable), and a crash after
the durable sending marker (uncertain). No SMTP connection is made.

`concurrency.php` starts separate PHP/WordPress/MySQL processes behind a
barrier. It tests three theme registrations, four native WordPress registrations
and a mixed native/theme race for one email. A short stagger lets the email lock
be exercised rather than making the zero-wait rate-counter lock decide every
race. This is bounded concurrency evidence, not sustained load measurement.

`browser.cjs` uses Chromium against the exact localhost QA URL on port 18083.
It submits real Chinese/English forms, verifies by explicit POST, logs in,
changes private profiles, uploads real multipart PNGs, requests export, logs
out and resets native passwords. It checks private headers, token URL cleanup,
nonce/browser-bound CSRF/origin checks, anonymous author/REST/avatar exposure,
no-JS form availability and 320/768/1440px reflow. The private CLI bridge reads
tokens into test-process memory only; do not expose `browser-control.php` as an
HTTP endpoint or publish raw access logs containing temporary URL credentials.

`legacy-smoke.cjs` checks existing public routes, Sitemap, robots, a real native
administrator login and the AI Skills Hub backend. It also confirms that pending
Subscribers cannot use native admin index/profile pages or private profile routes.
The administrator credential is read from the pre-existing private QA fixture
in process memory only; no administrator password is embedded in the test.

Only sanitized result JSON and screenshots of synthetic accounts are retained
in `results/`. Raw credentials, queue payloads, cookies, source logs and databases
are excluded. Real SMTP/inbox delivery, production HTTPS/proxy/caches, WPML,
multisite, external authentication plugins and physical mobile devices remain
separate unexecuted integrations.

## Frozen ZIP upgrade smoke

`package-upgrade.php` is a separate, one-time check for the existing writable
`aishqa-package` / `qa_package` fixture at theme 0.7.1 and schema 1.5.0. Do not
run it on business data or reset a database to make the preconditions pass.
Copy the frozen 0.8.0 ZIP and script into `/tmp/aish-phase8-package/` in that
container, owned by its `www-data` service user. Set `AISH_PHASE8_QA=1`,
`AISH_QA_ZIP_SHA256` to the published archive digest and run the copied script
as `www-data`, first with `AISH_PACKAGE_MODE=upgrade`, then in a new process
with `AISH_PACKAGE_MODE=verify`. The first step snapshots the original fourteen
table fingerprints and uses native `Theme_Upgrader`; the second loads the new
theme with `WP_CLI=true`, triggering the registered trusted-CLI migration hook,
checks schema 1.6.0 / 17 tables, original data and all installed file bytes.
The script refuses to overwrite its original snapshot.

The actual result is `release/phase8-install-check.json`, with separate real
localhost HTTP checks for `/en/`, `/zh/` and their login pages. This is a ZIP
upgrade check, not a new empty-site installation or real mail-delivery test.
