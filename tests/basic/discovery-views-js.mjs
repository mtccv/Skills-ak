import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

// DOM/time/fetch model, not a browser or HTTP integration test.
const source = await readFile(new URL('../../wp-content/themes/ai-skills-hub/assets/src/discovery-views.js', import.meta.url), 'utf8');
let assertions = 0;
const check = (condition, message) => { assertions += 1; assert.ok(condition, message); };
const settle = () => new Promise((resolve) => setImmediate(resolve));
async function fixture({ existing = false, recordFailure = false, withdrawFailure = false, endpoint = 'https://directory.example/wp-admin/admin-ajax.php' } = {}) {
  const calls = [];
  const intervals = new Map();
  const nodes = Object.fromEntries(['consent', 'withdraw', 'status'].map((name) => [name, {
    hidden: name === 'withdraw', disabled: false, textContent: '', events: {},
    addEventListener(type, handler) { this.events[type] = handler; },
  }]));
  const element = {
    dataset: { endpoint, nonce: 'nonce', skill: '5', locale: 'en_US', ticket: 'page-proof' },
    querySelector(selector) { return nodes[selector.match(/data-discovery-(.*?)\]/)[1]]; },
  };
  let now = 0;
  let focus = true;
  let serial = 0;
  const events = {};
  const document = { visibilityState: 'visible', hasFocus: () => focus, querySelectorAll: () => [element] };
  const window = { location: { href: 'https://directory.example/en/skills/resource/', origin: 'https://directory.example' }, addEventListener: (name, fn) => { events[name] = fn; }, dispatchEvent: (event) => { events[event.type]?.(); } };
  vm.runInNewContext(source, { document, window, Event: class { constructor(type) { this.type = type; } }, performance: { now: () => now }, URL, URLSearchParams,
    setInterval: (fn) => { const id = ++serial; intervals.set(id, fn); return id; }, clearInterval: (id) => intervals.delete(id),
    fetch: async (url, options) => {
      const body = Object.fromEntries(options.body.entries());
      calls.push({ body, options });
      const state = body.step === 'start' ? (existing ? 'ready' : 'consent_required') : body.step === 'consent' ? 'ready' : body.step === 'withdraw' ? 'stopped' : 'recorded';
      return { ok: !(recordFailure && body.step === 'record') && !(withdrawFailure && body.step === 'withdraw'), json: async () => ({ success: true, data: { state, ticket: 'dwell-proof', minimum_seconds: 8 } }) };
    },
  });
  await settle();
  return { calls, nodes, document, events, setWithdrawFailure: (value) => { withdrawFailure = value; }, focus: (value) => { focus = value; },
    tick: async (milliseconds = 1000) => { now += milliseconds; for (const fn of [...intervals.values()]) await fn(); await settle(); },
    click: async (name) => { await nodes[name].events.click(); await settle(); },
  };
}

let f = await fixture();
check(f.calls.length === 1 && f.calls[0].body.step === 'start', 'initial request checks existing consent only');
for (let i = 0; i < 20; i += 1) await f.tick();
check(f.calls.length === 1 && !f.nodes.consent.hidden, 'no consent means no observation even after long dwell');
await f.click('consent');
check(f.calls.at(-1).body.step === 'consent' && f.nodes.consent.hidden && !f.nodes.withdraw.hidden, 'consent is an explicit button action with visible withdrawal');
for (let i = 0; i < 7; i += 1) await f.tick();
check(!f.calls.some((call) => call.body.step === 'record'), 'seven visible seconds is insufficient');
await f.tick();
check(f.calls.filter((call) => call.body.step === 'record').length === 1, 'eight visible focused seconds submits once');
for (let i = 0; i < 20; i += 1) await f.tick();
check(f.calls.filter((call) => call.body.step === 'record').length === 1, 'interval is stopped after its single observation');
check(f.calls.every((call) => call.options.credentials === 'same-origin' && call.options.cache === 'no-store' && call.options.redirect === 'error'), 'transport avoids caching, redirects and foreign credentials');
check(f.calls.every((call) => !('count' in call.body) && !('duration' in call.body) && !('user_id' in call.body)), 'client never chooses counts, duration claims or account identity');

f = await fixture({ existing: true });
f.document.visibilityState = 'hidden';
for (let i = 0; i < 10; i += 1) await f.tick();
f.document.visibilityState = 'visible'; f.focus(false);
for (let i = 0; i < 10; i += 1) await f.tick();
check(!f.calls.some((call) => call.body.step === 'record'), 'hidden and unfocused time never contributes');
f.focus(true);
await f.tick(60000);
check(!f.calls.some((call) => call.body.step === 'record'), 'a suspended timer gap cannot masquerade as visible dwell');
for (let i = 0; i < 7; i += 1) await f.tick();
check(f.calls.filter((call) => call.body.step === 'record').length === 1, 'visible time resumes with bounded intervals');

f = await fixture({ existing: true });
for (let i = 0; i < 3; i += 1) await f.tick();
await f.click('withdraw');
for (let i = 0; i < 10; i += 1) await f.tick();
check(!f.calls.some((call) => call.body.step === 'record') && f.calls.at(-1).body.step === 'withdraw', 'withdrawal stops the timer immediately and requests cookie removal');
check(!f.nodes.consent.hidden && f.nodes.withdraw.hidden, 'withdrawal returns to the explicit opt-in state');

f = await fixture({ existing: true, withdrawFailure: true }); await f.click('withdraw');
for (let i = 0; i < 10; i += 1) await f.tick();
check(!f.nodes.withdraw.hidden && !f.nodes.withdraw.disabled && f.nodes.consent.hidden && f.nodes.status.textContent.includes('could not be confirmed'), 'failed withdrawal keeps a visible enabled retry with an explicit unconfirmed warning');
await f.click('withdraw'); await f.tick();
check(f.calls.filter((call) => call.body.step === 'withdraw').length === 2 && !f.calls.some((call) => call.body.step === 'record'), 'retrying failed withdrawal never restarts natural-view timing');
f.setWithdrawFailure(false); await f.click('withdraw'); await f.tick();
check(f.nodes.withdraw.hidden && !f.nodes.consent.hidden && !f.calls.some((call) => call.body.step === 'record'), 'successful retry restores opt-in controls with measurement still stopped');

f = await fixture({ existing: true, recordFailure: true });
for (let i = 0; i < 30; i += 1) await f.tick();
check(f.calls.filter((call) => call.body.step === 'record').length === 1 && f.nodes.status.textContent.includes('could not be confirmed'), 'ambiguous failed write is reported and never retried automatically');

f = await fixture({ existing: true });
f.events.pagehide();
for (let i = 0; i < 10; i += 1) await f.tick();
check(!f.calls.some((call) => call.body.step === 'record'), 'leaving page cancels pending observation');

f = await fixture({ endpoint: 'https://foreign.example/admin-ajax.php' });
await f.click('consent');
check(f.calls.length === 0 && f.nodes.status.textContent.includes('unavailable'), 'cross-origin configured endpoint fails closed without any request');
f = await fixture({ existing: true }); f.events['aish-measurement-withdrawn']();
for (let i = 0; i < 10; i += 1) await f.tick();
check(!f.calls.some((call) => call.body.step === 'record') && !f.nodes.consent.hidden && f.nodes.withdraw.hidden, 'another placement withdrawal stops natural-view timing and updates controls');
console.log(`Discovery view browser logic: ${assertions} isolated DOM/time/fetch assertions passed; no real browser or HTTP integration.`);
