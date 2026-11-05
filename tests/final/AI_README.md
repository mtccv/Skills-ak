# AI Final QA

The four `ai-*.php` scripts execute the real WordPress, Core, MySQL, permissions,
configuration, queue, transaction and review code. GitHub source records and AI
responses are explicitly synthetic. All WordPress HTTP requests in these scripts
are blocked; they do not call OpenAI or another model API, even if credentials
are present. No Skill scripts or installation instructions are executed.

Use a dedicated, installed WordPress instance with the theme active, schema
1.5.0, an administrator, database name **`qa_ai`**, and `DISABLE_WP_CRON=true`.
Mount this repository at `/repo`. Run sequentially:

```sh
docker exec -e AISH_FINAL_QA=1 aishqa-ai php /repo/tests/final/ai-workflows.php
docker exec -e AISH_FINAL_QA=1 aishqa-ai php /repo/tests/final/ai-concurrency.php
```

`AISH_WP_LOAD` optionally selects a different `wp-load.php` location. The scripts
reject non-CLI calls, absent opt-in and other database names. Each prints JSON
case results and returns a nonzero exit code if any case fails. They create and
retain their own fixture records; they do not clear tables or remove existing
data. Unfinished jobs created by a case are cancelled after the case, and the AI
queue is paused at suite completion. Do not run alongside unrelated work in this
dedicated database.

The workflow suite covers bilingual and partial output, original/editable
draft separation, manual adoption/rejection, existing human content and slugs,
source/editor baselines, current-review publication rules, request deduplication,
batch recovery, pause/resume, JSON errors, retry limits, uncertain usage, cost
estimates/budgets, provider configuration changes, connection checks, key masking,
history pagination and subscriber access denial. The one publication fixture is
explicitly approved through the existing human review service; AI generation
never publishes it, and adopting its missing language returns it to pending.

The concurrency suite launches two PHP processes with separate real WordPress
and MySQL sessions. It tests request-key/active-key contention, the global worker
lock, double adoption, and adoption racing a human edit. Its fault test uses a
second session to kill **only its own child process's database connection** during
a Mock Provider attempt. It verifies persisted `inflight` evidence and later
`uncertain` recovery, with no automatic request replay. The MySQL QA user needs
permission to terminate another connection belonging to that same user.

To keep tests bounded, due/expired timestamps are advanced on the test jobs in
MySQL instead of waiting through backoff or the full lease duration. Timeout and
rate-limit responses are Mock Provider exceptions, not measured network failures.
The injected connection termination is real; no real HTTP request is sent.

Recorded results: WordPress 7.1.4, MySQL 8.0.46, with **both PHP 8.3.36 and
8.4.27** actually executed. Per runtime: workflow **23 cases / 121 assertions**
and concurrency/recovery **6 cases / 20 assertions**, all passed (282 assertions
across the two runtimes). The AI container was switched to the PHP 8.4 image
while preserving its dedicated database and volume; each suite created new
fixtures and ran sequentially. JSON evidence uses `ai-workflows-` and
`ai-concurrency-` with `php83-mysql80.json` / `php84-mysql80.json` suffixes. Initial runs exposed two incorrect
test assumptions about editor saves returning content to `draft`; the fixtures
were corrected, both suites rerun, and no production change was required.

These results do not validate real model authentication, content quality,
vendor-specific formats, actual rate limits, network timeouts, invoices
or MySQL 8.4. No real AI key was configured for this run. HTTP admin forms, nonce
handling and browser interactions require their separate Final QA suites.
