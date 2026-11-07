# Phase 13 real-environment QA

This directory contains new Phase 13 evidence. Historical `tests/operations/results` files remain unchanged. The fixed QA databases are `qa_discovery` and `qa_operations84`; PHP entry points reject HTTP execution and require `AISH_PHASE13_QA=1`. Never point these fixtures at a production database.

## Preserved environments

Executed in Codex Cloud on 2026-11-07:

| Container | WordPress | PHP | MySQL | HTTP |
|---|---|---|---|---|
| `p11qa-wp83` | 7.1.4 | 8.3.36 | 8.0.46 | Apache, localhost:18183 |
| `p12qa-wp84` | 7.1.4 | 8.4.27 | 8.0.46 | PHP built-in server, localhost:18184 |

Both retain **schema 1.9.0 and 33 Core tables**. Baseline commit is `46d63dbfd256ae38c579f65608ee46876cf545f0`, theme 0.12.0. Original containers, native WordPress files, MySQL volumes, private credentials and earlier-phase fixtures were retained. Phase 13 needs no DDL or schema migration.

The PHP 8.4 runtime and Chromium 155 were already available from Phase 12. Its reproducible lightweight Dockerfile and proxy/CA handling are in [the Phase 12 environment guide](../operations/README.md). The binary inside that container is `php84`. No package installation, blanket cleanup, volume reset or Docker prune was needed for Phase 13.

All browser sessions and WordPress nonces are real. The browser runs actual HTTP against these WordPress/MySQL instances at 1440, 768 and 375 pixels in Chinese and English. This is Chromium coverage, not a claim about every browser or production TLS deployment.

The QA MU plugin blocks external HTTP and intercepts outgoing mail. All records, contacts, users, evidence, impressions and clicks created here are explicitly synthetic local fixtures. Native email transport outcomes in inherited notification regressions are **Mock**, never real SMTP or delivered email. Cookie/nonce values pass through private process pipes and are not written to committed result files.

## Reproduction sequence

1. On the retained 0.12.0 runtimes, run `baseline.php` once and preserve `baseline-php83.json` and `baseline-php84.json` unchanged. It records row counts and sorted hashes, not raw private records. Core structure fingerprints omit the naturally advancing AUTO_INCREMENT value.
2. Capture dashboard BEFORE measurements using `performance-run.py` before copying new runtime code. Do not create fixtures between these measurements and their matching AFTER samples.
3. Copy the integrated theme to the existing mounts using `python3 tests/operations/sync-theme.py`. This shared helper copies only theme files and sets deployment permissions to directories 0755/files 0644. It does not touch credentials, volumes or historical evidence.
4. Run `preservation.php` before mutable Phase 13 fixtures. Each runtime checks all 33 Core table row/DDL fingerprints, native users/usermeta/private Inbox/revisions, repeated current-version migration, theme switching and reactivation. These initial **79 checks per runtime** are not rerun against a now-mutated fixture database and presented as the same baseline.
5. Run the Phase 13 service suites sequentially on each runtime:

```sh
env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
  docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE13_QA=1 \
  p11qa-wp83 php /repo/tests/commercial/sponsors.php
```

Use the same command for `enterprise.php`, `entitlements-bundles.php`, `metrics.php` and `privacy-transactions.php`. Replace the container/binary with `p12qa-wp84 php84` for PHP 8.4. These scripts create bounded synthetic fixtures; they restore public display/tracking configuration when finished. They do not delete old Core records. The privacy suite erases only its newly created inquiry after constructing a matching confirmed native privacy request.

6. Run `python3 tests/commercial/concurrency.py 83` and `84`. Four fresh PHP processes race per operation. The reused native Inbox deliberately returns `busy` under nonblocking lock contention; the harness explicitly retries those requests after all parallel workers finish. A busy response is never counted as a successful write. Sponsor/config revisions, a single inquiry identity, and exactly four committed audit entries are checked.
7. Run `node tests/commercial/browser.cjs 83` and `84`, then each runtime's `--xss` and `--withdraw` subsets, separately from any CLI suite modifying sponsor, enterprise or homepage tracking settings. Use the managed Node dependency path for Playwright. The private `browser-control.php` helper creates native auth cookies and signed nonces, and restores only its isolated configuration. Never store that helper's raw cookie output in reports. Each full run submits one inquiry and retries the same key, consuming two requests from the real five-per-hour/address limiter; repeated full runs must respect that limit. No limiter reset is performed. The subsets send no enterprise form.
8. Run `python3 tests/commercial/regressions.py 83` and `84`. It executes the existing Phase 12 real domain suites while saving results into **this** directory. The old notification/outbox suite uses Mock mail responses. Do not run the original operations browser runner because it writes its historical output paths.
9. Run the existing `tests/integration/core.php` against the PHP 8.4 WordPress installation once all browser/theme-changing work is idle. Only randomly named `aish_t_*` integration tables are created and cleaned by that existing harness.
10. For native ZIP upgrade, copy the QA WordPress code into a separate private directory without a theme bind mount. Uploaded media is not needed or copied for this installer test; original uploads remain untouched. Run `package-upgrade.php` in a temporary PHP 8.4 container: `AISH_PACKAGE_STAGE=baseline` installs 0.12.0; a **new PHP process** without that stage loads the actual 0.12.0 theme before upgrading to 0.13.0. A third process with `AISH_PACKAGE_STAGE=verify` boots the installed 0.13.0 Core and Commercial services. Verify all installed ZIP files and retained Core/native account/Inbox hashes. Run `package-hashes.py` to compare the final ZIP with repository files and both live theme mounts. The root repository LICENSE is intentionally added separately by the packager.

## Evidence and limits

- `sponsors`: native capability/configuration/review/CAS gates, allowed URLs, no private fields in projections, language-specific publication, pause/future/actual UTC expiry and immediate Skill withdrawal. Expiry is tested by crossing a real short period, without Cron.
- `enterprise`: the existing private `aish_message` Inbox, separate subtype, exact retry behavior, bounded input, dedicated capabilities, native editing guards, revisions and private audit metadata. It creates no Skill, order or mail.
- `entitlements-bundles`: read-only free eligibility and existing verified-account/object checks; real favorites remain free. Bundles reference existing collections and Skill IDs and recheck current language/member publication.
- `metrics`: actual MySQL commercial observations, distinct daily dedupe, language dimensions, purpose-separated signed cookies, exact counts, rollback, quotas and no changes to natural aggregate rows. Direct persistence is invoked through reflection; actual nonce/origin/proof HTTP controls are covered by the browser suite separately.
- `privacy-transactions`: native confirmed export/erasure and actual transaction rollback after a controlled audit SQL failure. No schema alteration is used for fault injection.
- `performance-*`: before/after SQL counts and actual elapsed time on this shared host. `performance-semantics` compares the immutable 0.12 dashboard with the new implementation across 33 queue states, role restrictions and publication changes. Paired scale samples temporarily add 0/100/1000 private fixture rows inside a transaction and prove rollback; measurement samples are not counted as functional assertions or production benchmarks.

The QA harness initially made incorrect assumptions about rejecting versus sanitizing plain-text markup and the existing notification table name. Browser harness corrections preserved WordPress's private admin canonical, checked durable click writes rather than an abandoned navigation response, and accepted the existing widgets' distinct withdrawal-error wording. The inherited analytics fixture's random hex suffix could contain five consecutive digits and correctly trigger privacy suppression; its generator now maps hex symbols to safe letters. No privacy rule was weakened. Those checks were corrected and rerun; Phase 12 historical results were not changed.

Native Inbox lock contention is handled explicitly as a retryable response. A real intermittent bundle lock-upgrade deadlock was diagnosed through sanitized MySQL InnoDB metadata; the old code rolled back safely without data loss. The narrow exclusive no-op initialization upsert was then verified through **five four-writer rounds per runtime**, each producing one committed revision/audit and three clean revision conflicts. The separate browser withdrawal regression intentionally interrupts one transport request per widget, then verifies a visible retry, actual successful WordPress response and revoked cookie. Three injected transport errors per runtime are expected; there were no unexpected console/page errors.

## Final counted evidence

`results/qa-summary.json` derives these counts from the final reports:

- **164 functional scenario runs / 2,766 assertions**, all passed. This includes 42 browser scenarios / 798 assertions and the real-MySQL notification suites whose external mail transport is Mock.
- **158 preservation checks**, **40 concurrent-write checks**, **162 Core integration assertions**, **18 native package install/upgrade/fresh-boot checks**, and **4 package/source/runtime hash checks**, all passed.
- The combined real-environment assertions/checks total **3,148**; this does not turn Mock mail or injected network failures into real external-service validation.
- **36 fresh-process performance measurements** and **18 paired scale probes** are reported separately from functional assertions.
- Final development ZIP: **190 files**, SHA256 `6fb760a7236108aabc66b32705f4efaacba1d53c1f510c6db4c656c3696c5367`.

The two analytics runs have different assertion totals because their existing QA datasets contain different numbers of historical metric rows, each checked twice for private-data suppression. They exercise the same seven functional scenarios. Static/isolated suites and build counts are maintained separately by the root Phase 13 report.

Not executed here: real SMTP acceptance/delivery; live GitHub or AI services; commercial WPML; real Rank Math/Yoast integration; Redis/Memcached; multisite; other browsers; production TLS, high sustained load, or a large production dataset. Payment, subscriptions and commercial transactions are outside Phase 13 and were not implemented or represented as tested. This development package is not Phase 14 or final production acceptance.
