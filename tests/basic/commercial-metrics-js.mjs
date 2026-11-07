import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
// Isolated DOM/clock/network model; real browser checks are reported separately.
const source = await readFile(new URL('../../wp-content/themes/ai-skills-hub/assets/src/commercial-metrics.js', import.meta.url), 'utf8');
let assertions = 0;
const check = (value, message) => { assertions += 1; assert.ok(value, message); };
const settle = () => new Promise((resolve) => setImmediate(resolve));
async function fixture({ consent = false, failure = '', endpoint = 'https://directory.example/wp-admin/admin-ajax.php', delayedConsent = false, sharedWindow = null } = {}) {
  const calls = []; const events = {}; const intervals = new Map(); let timerId = 0; let now = 0; let focus = true; let observe; let releaseConsent;
  const nodes = Object.fromEntries(['consent', 'withdraw', 'status', 'link'].map((name) => [name, {
    hidden: name === 'withdraw', disabled: false, textContent: '', href: 'https://sponsor.example/resource', events: {},
    addEventListener(type, callback) { this.events[type] = callback; },
  }]));
  const element = { dataset: { commercialEndpoint: endpoint, commercialNonce: 'nonce', commercialLocale: 'en_US', commercialProof: 'signed-display' }, querySelector: (selector) => nodes[selector.match(/data-commercial-(.*?)\]/)[1]] };
  class Observer { constructor(callback) { observe = callback; } observe() {} disconnect() {} }
  class Event { constructor(type) { this.type = type; } }
  const window = sharedWindow || { location: { href: 'https://directory.example/en/', origin: 'https://directory.example' }, IntersectionObserver: Observer, addEventListener: (name, callback) => { events[name] = callback; }, dispatchEvent: (event) => { events[event.type]?.(); } };
  const document = { visibilityState: 'visible', hasFocus: () => focus, querySelectorAll: () => [element] };
  vm.runInNewContext(source, { document, window, Event, IntersectionObserver: Observer, performance: { now: () => now }, URL, URLSearchParams,
    setInterval: (callback) => { const id = ++timerId; intervals.set(id, callback); return id; }, clearInterval: (id) => intervals.delete(id),
    fetch: async (url, options) => {
      const body = Object.fromEntries(options.body.entries()); calls.push({ body, options });
      if (delayedConsent && body.step === 'consent') await new Promise((resolve) => { releaseConsent = resolve; });
      const state = body.step === 'start' ? (consent ? 'ready' : 'consent_required') : body.step === 'consent' ? 'ready' : body.step === 'withdraw' ? 'stopped' : 'recorded';
      return { ok: body.step !== failure, json: async () => ({ success: true, data: { state } }) };
    },
  });
  await settle();
  return { calls, nodes, events, document, window, setFailure: (value) => { failure = value; }, release: () => releaseConsent?.(), focus: (value) => { focus = value; }, visible: (ratio) => observe([{ target: element, isIntersecting: ratio > 0, intersectionRatio: ratio }]),
    tick: async (ms = 250) => { now += ms; for (const callback of [...intervals.values()]) callback(); await settle(); },
    click: async (name, trusted = true) => { await nodes[name].events.click({ isTrusted: trusted, preventDefault: () => { throw new Error('Link navigation blocked'); } }); await settle(); },
  };
}
let f = await fixture(); f.visible(1); for (let i = 0; i < 8; i += 1) await f.tick(); await f.click('link');
check(f.calls.length === 1 && f.calls[0].body.step === 'start', 'no sponsorship consent means no impressions or clicks');
check(f.nodes.link.href === 'https://sponsor.example/resource', 'normal link target unchanged');
await f.click('consent'); check(f.nodes.consent.hidden && !f.nodes.withdraw.hidden, 'explicit sponsorship opt-in exposes withdrawal');
f.visible(0.49); for (let i = 0; i < 8; i += 1) await f.tick(); check(!f.calls.some((c) => c.body.event === 'impression'), 'less than half visibility does not count');
f.visible(1); f.document.visibilityState = 'hidden'; for (let i = 0; i < 8; i += 1) await f.tick(); f.document.visibilityState = 'visible'; f.focus(false); for (let i = 0; i < 8; i += 1) await f.tick(); check(!f.calls.some((c) => c.body.event === 'impression'), 'hidden and unfocused time excluded');
f.focus(true); for (let i = 0; i < 3; i += 1) await f.tick(); check(!f.calls.some((c) => c.body.event === 'impression'), 'less than one second not counted'); await f.tick(); check(f.calls.filter((c) => c.body.event === 'impression').length === 1, 'one visible second records one response observation');
for (let i = 0; i < 12; i += 1) await f.tick(); check(f.calls.filter((c) => c.body.event === 'impression').length === 1, 'no repeated display observation per response');
await f.click('link', false); check(!f.calls.some((c) => c.body.event === 'click'), 'synthetic programmatic link events ignored'); await f.click('link'); await f.click('link'); check(f.calls.filter((c) => c.body.event === 'click').length === 1, 'trusted click normal navigation plus once-only observation');
check(f.calls.find((c) => c.body.event === 'click').options.keepalive === true, 'click request can finish without delaying navigation');
check(f.calls.every((c) => c.options.cache === 'no-store' && c.options.redirect === 'error' && c.options.credentials === 'same-origin'), 'no cross-origin redirects credentials or caching');
check(f.calls.every((c) => Object.keys(c.body).sort().join(',') === 'action,event,nonce,proof,step'), 'no destination URL count or identity in event payload');
f = await fixture({ consent: true }); f.visible(1); await f.click('withdraw'); for (let i = 0; i < 8; i += 1) await f.tick(); await f.click('link'); check(!f.calls.some((c) => c.body.step === 'record') && !f.nodes.consent.hidden && f.nodes.withdraw.hidden, 'withdrawal stops display and click handlers');
f = await fixture({ consent: true }); f.visible(1); f.events['aish-measurement-withdrawn'](); for (let i = 0; i < 8; i += 1) await f.tick(); check(!f.calls.some((c) => c.body.step === 'record') && !f.nodes.consent.hidden, 'withdrawal elsewhere stops this placement and updates UI');
f = await fixture({ consent: true, failure: 'record' }); f.visible(1); for (let i = 0; i < 16; i += 1) await f.tick(); check(f.calls.filter((c) => c.body.step === 'record').length === 1 && f.nodes.status.textContent.includes('unconfirmed'), 'uncertain failures never retried automatically');
f = await fixture({ endpoint: 'https://foreign.example/wp-admin/admin-ajax.php' }); await f.click('consent'); await f.click('link'); check(f.calls.length === 0, 'foreign measurement endpoint never requested; link not blocked');
f = await fixture({ consent: true, failure: 'withdraw' }); f.visible(1); await f.click('withdraw'); for (let i = 0; i < 8; i += 1) await f.tick(); await f.click('link');
check(!f.nodes.withdraw.hidden && !f.nodes.withdraw.disabled && f.nodes.consent.hidden && f.nodes.status.textContent.includes('unconfirmed'), 'unconfirmed removal exposes an enabled retry instead of silently hiding withdrawal');
await f.click('withdraw'); await f.tick();
check(f.calls.filter((c) => c.body.step === 'withdraw').length === 2 && !f.calls.some((c) => c.body.step === 'record'), 'retrying failed removal does not restart impressions or click tracking');
f.setFailure(''); await f.click('withdraw'); await f.tick();
check(f.nodes.withdraw.hidden && !f.nodes.consent.hidden && !f.calls.some((c) => c.body.step === 'record'), 'confirmed cookie-removal retry restores opt-in controls with tracking stopped');
const first = await fixture({ delayedConsent: true });
const grant = first.click('consent'); await settle();
const second = await fixture({ consent: true, sharedWindow: first.window });
const withdrawal = second.click('withdraw'); await settle();
check(first.calls.some((c) => c.body.step === 'consent') && !second.calls.some((c) => c.body.step === 'withdraw'), 'a second widget cannot send cookie removal before an earlier consent response settles');
check(!second.nodes.consent.hidden, 'waiting for cookie serialization still stops this widget immediately');
first.release(); await grant; await withdrawal;
check(second.calls.at(-1).body.step === 'withdraw', 'shared-cookie withdrawal is sent after the earlier consent finishes, without automatic retries');
console.log(`Commercial measurement browser logic: ${assertions} isolated DOM/time/fetch assertions passed; no real browser assertions.`);
