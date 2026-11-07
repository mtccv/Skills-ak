# Phase 12 real-environment evidence

These scripts are CLI-only and refuse databases other than `qa_discovery` and `qa_operations84`. Run only in disposable, private QA environments. They create synthetic users, Skills, reviews, private Inbox submissions and metrics; they must never be pointed at a production site. External HTTP is blocked by the QA MU plugin, and all mail responses are Mock. No credential, mail token, cookie, raw email body or SMTP response is stored in these results.

## Actual runtimes

Executed on 2026-11-07 in Codex Cloud:

| Runtime | WordPress | PHP | Database | Web server |
|---|---|---|---|---|
| Preserved `p11qa-wp83` | 7.1.4 | 8.3.36 | MySQL 8.0.46 | Apache, localhost:18183 |
| Independent `p12qa-wp84` | 7.1.4 | 8.4.27 | MySQL 8.0.46 | PHP built-in server, localhost:18184 |

The original MySQL/WordPress containers, volumes, credentials and Phase 11 data were retained. PHP 8.4 uses a new database `qa_operations84`, with its own native WordPress installation and Phase 11 baseline fixtures. Both use schema **1.9.0 / 33 Core tables**. Chromium 155 uses real HTTP, cookies, WordPress nonce checks and MySQL-backed sessions; the browser results are not DOM mocks.

### PHP 8.4 recovery

The retained Docker daemon uses the **VFS** storage driver. The previous full PHP 8.4 WordPress image attempt ran out of space: approximately 24 GB of a 32 GB root filesystem was already used despite only about 2 GB of logical image sizes. No database volume, credentials, package, source or build output was deleted. No Docker prune or blanket cleanup was performed.

Instead, `Dockerfile.php84` builds the official Alpine 3.22 PHP 8.4 packages, including mysqli, mbstring, sodium, XML/DOM, curl, gd, intl, zip and pcntl. The successful runtime reports PHP **8.4.27**. Package installation reported 65 MiB; after the independent WordPress copies and tests, root still had approximately 5.7 GB free. This is a real additional PHP/WordPress runtime, not a syntax-version emulation.

The managed proxy CA is passed as a BuildKit secret, never copied into the image or repository:

```sh
env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
  docker --host=unix:///var/run/docker.sock build \
  --secret id=proxy_ca,src=/etc/ssl/certs/ca-certificates.crt \
  -f tests/operations/Dockerfile.php84 -t aish-p12-php84 tests/operations
```

Provision a separate QA database and native WordPress filesystem before starting this image. Supply database/admin secrets from a private file outside the repository. The retained Phase 11 `/tmp/p11qa/credentials.json` was mounted read-only in this run; its contents were never committed. WordPress core was copied from the retained, legally downloaded QA installation. A private PHP router serves existing static files and sends other paths to WordPress `index.php`. Bind the web port to localhost, set `DISABLE_WP_CRON`, and install the existing external-HTTP/mail isolation MU plugin. The PHP binary is **`php84`**, not `php`.

## Reproduction order

1. Before copying Phase 12 code, run `baseline.php` with `AISH_PHASE12_QA=1` on each Phase 11 runtime. Keep the resulting `baseline-php83.json` and `baseline-php84.json` immutable.
2. Run `python3 tests/operations/sync-theme.py`. This copies only theme source into the existing QA theme mounts and sets deployable directories/files to 0755/0644 so Apache `www-data` can read them. The repository itself is owner-only. If a retained bind mount points to a replaced directory inode, restart only that WordPress container; do not delete its volume.
3. Run `preservation.php` once before any mutable fixtures. It compares all 33 tables' row and structure hashes plus native users, usermeta, private Inbox and revisions. It also repeats the current migration and switches away from/back to the theme. Phase 12 requires no schema migration or Core DDL.
4. Run real suites sequentially per database:

```sh
python3 tests/operations/run-real.py 83
python3 tests/operations/run-real.py 84
python3 tests/operations/concurrency.py 83
python3 tests/operations/concurrency.py 84
```

The wrapper always selects the local QA Docker daemon and fixed container/database names. `transactions.php` and `performance.php` are additional CLI suites invoked with the same environment flag and runtime binary. Transaction fault injection uses a temporary WordPress query filter, not a table/schema alteration.

5. Run browser suites separately from any suite that changes theme/home settings:

```sh
node tests/operations/browser.cjs 83
node tests/operations/browser.cjs 84
```

`browser-control.php` uses private stdin/stdout to create synthetic authenticated sessions and real WordPress nonces. Its generated cookie values never enter committed JSON. It also tests the first upgraded native admin request by removing only the four newly introduced capabilities/version marker in QA; the actual `admin_menu` hook must restore them before menu access checks.

6. Run `tests/integration/core.php` on PHP 8.4 with the real QA WordPress loaded. It creates and cleans only its randomly named `aish_t_*` test tables. Do not overlap this theme-switch suite with browser tests. `core-integration-php84.txt` records **162 assertions passed** in this Phase 12 run.
7. Run `package-upgrade.php` in a separate copy of the PHP 8.4 WordPress filesystem without a theme bind mount. Native `Theme_Upgrader` installs the retained Phase 11 ZIP, then upgrades to the final Phase 12 ZIP. It compares all Core/private-Inbox row hashes and every installed ZIP file. `package-hashes.py` additionally checks the repository and both live QA mounts against the final ZIP; the packager adds the root `LICENSE` separately.

## Evidence interpretation

- `preservation-php*.json`: real read-only baseline comparison, repeated installation and theme lifecycle, **79 checks per runtime**.
- `users-php*.json`: real native-user capability/state/audit/CAS checks and immediate bilingual rating/favorite eligibility; native administrator email changes cannot remove a suspension.
- `notifications-mail-php*.json`: real MySQL notification ownership/read transitions and encrypted outbox processing. Transport accept/reject/uncertain responses are **Mock**, not SMTP or delivered mail. Covers preserving developer-owned public-profile consent and pending-email administrator recovery.
- `workbench-home-php*.json`: real per-type capabilities, partial batch failures, idempotent moderation, source-evidence acknowledgements without Skill mutations, private draft/CAS/current-public-state homepage checks.
- `analytics-php*.json`: real MySQL five-observer threshold, proof replay, current public eligibility, exact known-sample deltas, real accent-insensitive collation (`resume` / `résumé`) separation, and consent/current-identity/daily-active-user deduplication. Direct persistence tests use reflection; separate browser cases exercise real nonce/origin/consent HTTP controls.
- `concurrency-php*.json`: four independent PHP processes per race, actual MySQL locks/CAS, **8 checks per runtime**. A safe lock-busy response is distinct from a successful write. One durable user audit, one homepage revision, one retry schedule, one search event and aggregate are verified.
- `transactions-php*.json`: actual MySQL audit-write failure injection and rollback, **2 cases per runtime**, no DDL.
- `performance-php*.json`: five measured calls per read model with actual SQL counts and elapsed milliseconds. Small synthetic data on a shared host; these are observations, not production throughput guarantees. Dashboard's bounded projection/authentication work has relatively high fixed SQL overhead; large-data/load testing remains necessary.
- `browser-php*.json` and screenshots: actual bilingual account/admin pages, 1440/768/375 viewports, private cache controls, preview nonce/revision/public-gate behavior, owner-only reads, correct native form nonces, search consent and parallel replay.
- `package-upgrade-php84.json`: real native install/upgrade, 174 installed files and retained-data hashes. `runtime-package-hashes.json`: all 173 runtime theme files plus the separately packaged root license match.

Known historical QA-only observations were diagnosed rather than relabeled as product passes: a stale retained bind-mount inode, owner-only deployment file permissions, and pre-fix synthetic unqualified search-term fixtures. The latter **15 synthetic rows** created during this session were preserved and relabeled `operations-qa-legacy`; no record was deleted or migrated as production data. Final results use the qualified hashed dimension format. Initial failing harness outputs remain private under `/tmp/p12qa`; committed results are the final explicit reruns.

## Not verified here

No real outbound mail acceptance or inbox delivery, real GitHub/AI API, commercial WPML, Rank Math/Yoast plugin, persistent Redis/Memcached, multisite deployment, large production dataset, sustained load, or non-Chromium browser was tested in this Phase 12 run. Previous-phase external limitations remain in force. The development ZIP is not a production release or blanket Final QA approval.
