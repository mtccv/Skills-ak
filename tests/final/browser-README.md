# Real browser QA

These scripts exercise an actual disposable WordPress/MySQL installation. They do not mock WordPress, SQL or browser HTTP. Never point them at production: fixture creation is restricted to the `qa_browser` database and a loopback site. All example Skills, taxonomy labels and editorial posts are explicitly QA content.

- `browser-fixtures.php`: creates persistent bilingual fixtures, four private Skill states, native tutorials/static pages and three restricted test users. Re-running reuses the fixture manifest, rotates test passwords and resets only the isolated form rate counters.
- `browser-e2e.cjs`: Chromium desktop/tablet/mobile public routes, form submissions, native admin UI edits, bilingual Skill review/archive, private preview and restricted account navigation.
- `browser-persistence.php`: checks actual stored rows after the browser actions, including private Inbox records and stable bilingual Skill IDs.
- `browser-compat.cjs`: separate Firefox/WebKit desktop/mobile public route, navigation and search matrix. Engine launch failure is reported as skipped, never passed. Full admin mutations are covered by Chromium.

Run PHP files with `wp eval 'require "...";'`, not `wp eval-file`: these scripts retain `declare(strict_types=1)` and `eval-file` adds a prefix before it. The disposable container mounts this repository read-only at `/repo` and the private output directory at `/qa`.

```sh
python3 /tmp/aish-final-qa/ctl.py wp browser eval 'require "/repo/tests/final/browser-fixtures.php";' > /tmp/aish-final-qa/browser-fixtures.json
# Copy private generated role credentials through stdout into a private file, never the terminal.
umask 077
python3 /tmp/aish-final-qa/ctl.py exec browser cat /qa/browser-test-credentials.json > /tmp/aish-final-qa/browser-host-credentials.json
QA_BROWSER_CREDENTIALS=/tmp/aish-final-qa/browser-host-credentials.json QA_BROWSER_FIXTURES=/tmp/aish-final-qa/browser-fixtures.json node tests/final/browser-e2e.cjs
python3 /tmp/aish-final-qa/ctl.py wp browser eval 'require "/repo/tests/final/browser-persistence.php";' > /tmp/aish-final-qa/browser-persistence-results.json
PLAYWRIGHT_BROWSERS_PATH=/tmp/aish-final-qa/browsers QA_BROWSER_ENGINE=firefox QA_BROWSER_FIXTURES=/tmp/aish-final-qa/browser-fixtures.json node tests/final/browser-compat.cjs
PLAYWRIGHT_SKIP_VALIDATE_HOST_REQUIREMENTS=1 PLAYWRIGHT_BROWSERS_PATH=/tmp/aish-final-qa/browsers QA_BROWSER_ENGINE=webkit QA_BROWSER_FIXTURES=/tmp/aish-final-qa/browser-fixtures.json node tests/final/browser-compat.cjs
```

Playwright and downloaded browsers are test-environment dependencies, outside the project package files. Chromium defaults to `/usr/bin/chromium`; `QA_CHROMIUM` can select another installed binary. A sandbox that prevents browser processes or Docker socket access needs the environment's approved execution path.

This Cloud host had no WebKit system libraries. For the real run, Debian 13 packages `libgtk-4-1`, `libgraphene-1.0-0`, `libharfbuzz-icu0`, `libmanette-0.2-0`, `libhyphen0`, `libgles2`, `libcairo-script-interpreter2`, `libhidapi-hidraw0`, `libglvnd0` and `liblzo2-2` were downloaded using an isolated apt state under `/tmp/aish-final-qa/webkit-deps`, extracted with `dpkg-deb -x`, and their libraries linked into the downloaded browser's `minibrowser-{gtk,wpe}/sys/lib` directories. No host packages or production dependencies changed. The WebKit wrapper overwrites `LD_LIBRARY_PATH`; links in its private library directory preserve its existing launch contract. `PLAYWRIGHT_SKIP_VALIDATE_HOST_REQUIREMENTS=1` bypasses only the installer's system-path library check, which does not recognize this private GLES library; actual browser launch, rendering and test failures still determine results. A normally provisioned host can use Playwright's documented system dependencies and omit that variable. Linux WebKit is engine compatibility evidence, not a test on an actual macOS/iOS Safari device.

JSON counts and screenshots are written under `/tmp/aish-final-qa`, not Git. Credentials, databases, browser downloads and WordPress/plugin packages must remain outside Git. Chromium reports preserve a timestamped copy for each full run; failed initial runs are evidence, not later successful results.

Checks are deliberately scoped: these scripts do not call GitHub/AI services, install WPML, test production traffic or establish accessibility certification. Programmatic responsive checks include viewport overflow, a semantic H1, language/navigation/keyboard actions, visible form feedback, console/runtime errors and asset failures; screenshot review supplements these checks.
