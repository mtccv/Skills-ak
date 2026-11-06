/* Design-only color revision checks. Requires the existing Playwright/Chromium.
 * AISH_PALETTE_BEFORE_URL serves frozen commit 2d6df357; AISH_DESIGN_URL serves
 * the current prototype. No WordPress, database, API, or production requests.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { chromium } = require('playwright');
const beforeBase = process.env.AISH_PALETTE_BEFORE_URL || 'http://127.0.0.1:8768';
const currentBase = process.env.AISH_DESIGN_URL || 'http://127.0.0.1:8767';
const pages = ['index', 'skills', 'skill', 'dashboard', 'admin'];
const results = {
  scope: 'Static color-only design regression; no WordPress, database, API, or production tests',
  baselineCommit: '2d6df357f871bb31183182c02f7cd28dc80b1b18',
  geometryToleranceCssPx: 0.1,
  matrix: [], colors: [], interactions: [], failures: [],
  consoleErrors: [], failedResources: [], externalRequests: []
};
const digest = value => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');

// This runs in the browser. Ignore class/style attributes because palette tokens
// and color utility names change. Compare all other DOM attributes and direct
// text, then geometry and typography for every visible element in document order.
// Normalize only same-origin URL attributes because the two servers use different
// ports; paths, parameters, fragments, and external destinations remain compared.
function snapshot() {
  const all = Array.from(document.querySelectorAll('*'));
  const tree = all.map(el => ({
    tag: el.tagName,
    attributes: Array.from(el.attributes).filter(a => !['class', 'style'].includes(a.name))
      .map(a => {
        let value = a.value;
        if (['href', 'src', 'action'].includes(a.name)) {
          const url = new URL(value, location.href);
          if (url.origin === location.origin) value = url.pathname + url.search + url.hash;
        }
        return [a.name, value];
      }).sort((a, b) => a[0].localeCompare(b[0])),
    text: Array.from(el.childNodes).filter(n => n.nodeType === Node.TEXT_NODE)
      .map(n => n.textContent).join('').replace(/\s+/g, ' ').trim()
  }));
  const visible = [];
  all.forEach((el, index) => {
    const css = getComputedStyle(el);
    const box = el.getBoundingClientRect();
    if (!el.getClientRects().length || css.display === 'none' || css.visibility === 'hidden' || !box.width || !box.height) return;
    visible.push({
      index, tag: el.tagName,
      rect: ['x', 'y', 'width', 'height'].map(key => Math.round(box[key] * 100) / 100),
      font: [css.fontFamily, css.fontSize, css.fontWeight, css.lineHeight, css.letterSpacing, css.textAlign]
    });
  });
  return { tree, visible, extent: [document.documentElement.scrollWidth, document.documentElement.scrollHeight] };
}

// Scan computed styles, including gradients/shadows and generated decorations.
// Task identifiers such as #719 are not colors and never enter this scan.
function scanColors() {
  const properties = ['color', 'backgroundColor', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor', 'outlineColor', 'textDecorationColor', 'fill', 'stroke', 'backgroundImage', 'boxShadow', 'textShadow'];
  const removedAccents = [];
  const unique = new Set();
  let sampled = 0;
  const hue = (r, g, b) => {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b), delta = max - min;
    if (!delta) return { h: 0, s: 0 };
    const light = (max + min) / 2;
    const saturation = delta / (1 - Math.abs(2 * light - 1));
    let h = max === r ? ((g - b) / delta) % 6 : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
    h = (h * 60 + 360) % 360;
    return { h, s: saturation };
  };
  Array.from(document.querySelectorAll('*')).forEach((el, index) => {
    for (const pseudo of [null, '::before', '::after']) {
      const css = getComputedStyle(el, pseudo);
      if (pseudo && (css.content === 'none' || css.content === 'normal')) continue;
      sampled++;
      for (const property of properties) {
        const value = css[property];
        for (const match of value.matchAll(/rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)/g)) {
          const alpha = match[4] === undefined ? 1 : Number(match[4]);
          if (alpha <= 0) continue;
          const rgb = match.slice(1, 4).map(Number);
          unique.add(rgb.join(','));
          const color = hue(...rgb);
          if (((color.h >= 245 && color.h <= 300) || (color.h >= 80 && color.h <= 175)) && color.s >= 0.08 && removedAccents.length < 20)
            removedAccents.push({ index, tag: el.tagName, pseudo, property, value });
        }
      }
    }
  });
  return { sampledStyleTargets: sampled, uniqueRgbColors: unique.size, removedAccents };
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.AISH_CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  results.browser = browser.version();
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
  const allowed = new Set([new URL(beforeBase).origin, new URL(currentBase).origin]);
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (!allowed.has(url.origin)) { results.externalRequests.push(url.origin + url.pathname); return route.abort(); }
    return route.continue();
  });
  const before = await context.newPage();
  const current = await context.newPage();
  for (const page of [before, current]) {
    page.on('pageerror', e => results.consoleErrors.push(e.message));
    page.on('console', m => { if (m.type() === 'error') results.consoleErrors.push(m.text()); });
    page.on('response', r => { if (r.status() >= 400) results.failedResources.push({ url: r.url(), status: r.status() }); });
  }
  const check = async (group, name, fn) => {
    try { const detail = await fn(); results[group].push({ name, pass: true, ...detail }); }
    catch (error) { results[group].push({ name, pass: false, error: error.message }); results.failures.push(name + ': ' + error.message); }
  };
  const go = async (page, base, file, query = '') => {
    await page.goto(`${base}/${file}.html${query}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
  };
  for (const width of [1440, 390]) for (const lang of ['zh', 'en']) for (const file of pages) {
    await check('matrix', `${file}/${lang}/${width}: original layout and text`, async () => {
      const viewport = { width, height: width < 768 ? 844 : 1000 };
      await Promise.all([before.setViewportSize(viewport), current.setViewportSize(viewport)]);
      await Promise.all([go(before, beforeBase, file, `?lang=${lang}`), go(current, currentBase, file, `?lang=${lang}`)]);
      const [a, b] = await Promise.all([before.evaluate(snapshot), current.evaluate(snapshot)]);
      assert.equal(digest(b.tree), digest(a.tree), 'DOM tags, non-color attributes, or text changed');
      assert.equal(b.visible.length, a.visible.length, 'visible element count changed');
      let maxDelta = 0;
      for (let i = 0; i < a.visible.length; i++) {
        const old = a.visible[i], now = b.visible[i];
        assert.equal(now.index, old.index, `element order changed at ${i}`);
        assert.equal(JSON.stringify(now.font), JSON.stringify(old.font), `typography changed at element ${now.index} (${now.tag})`);
        old.rect.forEach((value, axis) => { maxDelta = Math.max(maxDelta, Math.abs(value - now.rect[axis])); });
      }
      assert(maxDelta <= results.geometryToleranceCssPx, `geometry changed by ${maxDelta.toFixed(2)} CSS px`);
      assert.deepEqual(b.extent, a.extent, 'document dimensions changed');
      return { domNodesCompared: a.tree.length, visibleElementsCompared: a.visible.length, maxGeometryDeltaCssPx: Number(maxDelta.toFixed(2)), domSignature: digest(b.tree) };
    });
  }
  await current.setViewportSize({ width: 1440, height: 1000 });
  for (const file of pages) {
    await check('colors', `${file}: no purple or green in computed colors or effects`, async () => {
      await go(current, currentBase, file, '?lang=en');
      const detail = await current.evaluate(scanColors);
      assert.deepEqual(detail.removedAccents, [], 'purple or green computed color remains');
      return detail;
    });
  }
  await check('interactions', 'Homepage uses the approved warm accent and neutral surfaces', async () => {
    await go(current, currentBase, 'index', '?lang=en');
    const actual = await current.evaluate(() => ({
      brand: getComputedStyle(document.documentElement).getPropertyValue('--brand').trim().toLowerCase(),
      button: getComputedStyle(document.querySelector('.btn-primary')).backgroundColor,
      logo: getComputedStyle(document.querySelector('.brand-mark')).backgroundColor,
      text: getComputedStyle(document.body).color,
      body: getComputedStyle(document.body).backgroundColor
    }));
    assert.equal(actual.brand, '#ad5e48');
    assert.equal(actual.button, 'rgb(173, 94, 72)');
    assert.equal(actual.logo, 'rgb(173, 94, 72)');
    assert.equal(actual.text, 'rgb(17, 24, 39)');
    assert.equal(actual.body, 'rgb(255, 255, 255)');
    return { actual };
  });
  await check('interactions', 'Hover and keyboard focus use the new palette without layout changes', async () => {
    const button = current.locator('.btn-primary').first();
    const rect = await button.boundingBox();
    await button.hover();
    await current.waitForTimeout(250);
    assert.equal(await button.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(159, 81, 60)');
    assert.deepEqual(await button.boundingBox(), rect);
    await current.mouse.move(0, 0);
    await button.focus();
    await current.keyboard.press('Tab');
    await current.keyboard.press('Shift+Tab');
    assert(await button.evaluate(el => el.matches(':focus-visible')));
    assert.equal(await button.evaluate(el => getComputedStyle(el).outlineColor), 'rgb(173, 94, 72)');
    assert.deepEqual((await current.evaluate(scanColors)).removedAccents, []);
    assert.deepEqual(results.consoleErrors, []);
    assert.deepEqual(results.failedResources, []);
    assert.deepEqual(results.externalRequests, []);
    assert.equal((await context.cookies()).length, 0);
    return { hover: '#9F513C', focus: '#AD5E48' };
  });
  results.passed = [...results.matrix, ...results.colors, ...results.interactions].filter(item => item.pass).length;
  results.failed = results.failures.length;
  fs.writeFileSync(path.join(__dirname, 'palette-checks.json'), JSON.stringify(results, null, 2) + '\n');
  console.log(JSON.stringify({ browser: results.browser, passed: results.passed, failed: results.failed, failures: results.failures }, null, 2));
  await browser.close();
  if (results.failed) process.exitCode = 1;
})().catch(error => { console.error(error); process.exit(1); });
