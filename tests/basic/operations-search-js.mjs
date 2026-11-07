import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

// Isolated DOM/time/fetch model; not a real browser or HTTP integration test.
const source = await readFile(new URL('../../wp-content/themes/ai-skills-hub/assets/src/operations-analytics.js', import.meta.url), 'utf8');
const settle = () => new Promise((resolve) => setImmediate(resolve));
let count = 0;
const check = (value, label) => { count += 1; assert.ok(value, label); };
async function fixture({ consent = false, failure = '', endpoint = 'https://directory.example/wp-admin/admin-ajax.php' } = {}) {
  const intervals = new Map(); let timerId = 0; const calls = []; const events = {};
  const nodes = Object.fromEntries(['consent', 'withdraw', 'status'].map((name) => [name, {
    hidden: name === 'withdraw', disabled: false, textContent: '', events: {},
    addEventListener(event, callback) { this.events[event] = callback; },
  }]));
  const element = {
    dataset: { endpoint, nonce: 'test-nonce', locale: 'en_US', proof: 'signed-result-proof' },
    querySelector(selector) { return nodes[selector.match(/data-search-(.*?)\]/)[1]]; },
  };
  let focus = true;
  const document = { visibilityState: 'visible', hasFocus: () => focus, querySelectorAll: () => [element] };
  const window = { location: { href: 'https://directory.example/en/search/?q=example', origin: 'https://directory.example' }, addEventListener: (name, callback) => { events[name] = callback; }, dispatchEvent: (event) => { events[event.type]?.(); } };
  vm.runInNewContext(source, { document, window, Event: class { constructor(type) { this.type = type; } }, URL, URLSearchParams,
    setInterval: (callback) => { const id = ++timerId; intervals.set(id, callback); return id; }, clearInterval: (id) => intervals.delete(id),
    fetch: async (url, options) => {
      const body = Object.fromEntries(options.body.entries()); calls.push({ body, options });
      const state = body.step === 'start' ? (consent ? 'ready' : 'consent_required') : body.step === 'consent' ? 'ready' : body.step === 'withdraw' ? 'stopped' : 'recorded';
      return { ok: body.step !== failure, json: async () => ({ success: true, data: { state } }) };
    },
  });
  await settle();
  return { calls, nodes, document, events, setFailure: (value) => { failure = value; }, focus: (value) => { focus = value; },
    tick: async () => { for (const callback of [...intervals.values()]) await callback(); await settle(); },
    click: async (name) => { await nodes[name].events.click(); await settle(); },
  };
}
let f = await fixture();
check(f.calls.length === 1 && f.calls[0].body.step === 'start', 'initial request reads existing purpose-specific consent');
for (let i = 0; i < 20; i += 1) await f.tick();
check(f.calls.length === 1 && !f.nodes.consent.hidden, 'no consent means no measurement');
await f.click('consent');
check(f.nodes.consent.hidden && !f.nodes.withdraw.hidden, 'explicit opt-in exposes withdrawal');
f.document.visibilityState = 'hidden'; await f.tick();
f.document.visibilityState = 'visible'; f.focus(false); await f.tick();
check(!f.calls.some((call) => call.body.step === 'record'), 'hidden and unfocused results do not count');
f.focus(true); await f.tick();
for (let i = 0; i < 10; i += 1) await f.tick();
check(f.calls.filter((call) => call.body.step === 'record').length === 1, 'visible first-page proof recorded once');
check(f.calls.every((call) => call.options.cache === 'no-store' && call.options.credentials === 'same-origin' && call.options.redirect === 'error'), 'transport is same-origin and no-store without redirects');
check(f.calls.every((call) => Object.keys(call.body).sort().join(',') === 'action,nonce,proof,step'), 'client sends no plaintext query, count, URL or user ID');

f = await fixture({ consent: true });
await f.click('withdraw'); await f.tick();
check(!f.calls.some((call) => call.body.step === 'record') && f.calls.at(-1).body.step === 'withdraw', 'withdrawal cancels pending observations before cookie removal');
check(!f.nodes.consent.hidden && f.nodes.withdraw.hidden, 'successful withdrawal returns to opt-in');

f = await fixture({ consent: true, failure: 'record' });
for (let i = 0; i < 10; i += 1) await f.tick();
check(f.calls.filter((call) => call.body.step === 'record').length === 1 && f.nodes.status.textContent.includes('could not be confirmed'), 'ambiguous write is not claimed successful or automatically retried');

f = await fixture({ consent: true, failure: 'withdraw' });
await f.click('withdraw'); await f.tick();
check(!f.calls.some((call) => call.body.step === 'record') && f.nodes.status.textContent.includes('unconfirmed'), 'failed cookie removal still stops this page and reports uncertainty');
check(!f.nodes.withdraw.hidden && !f.nodes.withdraw.disabled && f.nodes.consent.hidden, 'unconfirmed removal keeps the withdrawal retry visible and enabled');
await f.click('withdraw'); await f.tick();
check(f.calls.filter((call) => call.body.step === 'withdraw').length === 2 && !f.calls.some((call) => call.body.step === 'record'), 'explicit failed-removal retry remains available without restarting measurement');
f.setFailure(''); await f.click('withdraw'); await f.tick();
check(f.nodes.withdraw.hidden && !f.nodes.consent.hidden && !f.calls.some((call) => call.body.step === 'record'), 'confirmed retry returns to opt-in without collecting new events');


f = await fixture({ consent: true }); f.events.pagehide(); await f.tick();
check(!f.calls.some((call) => call.body.step === 'record'), 'leaving the page cancels pending collection');
f = await fixture({ endpoint: 'https://foreign.example/admin-ajax.php' }); await f.click('consent');
check(f.calls.length === 0 && f.nodes.status.textContent.includes('unavailable'), 'foreign endpoint fails before network request');
f = await fixture({ consent: true }); f.events['aish-measurement-withdrawn'](); await f.tick();
check(!f.calls.some((call) => call.body.step === 'record') && !f.nodes.consent.hidden && f.nodes.withdraw.hidden, 'another placement withdrawal stops search observation and updates controls');
console.log(`Operations search browser logic: ${count} isolated DOM/time/fetch assertions passed; no real browser or HTTP integration.`);
