#!/usr/bin/env node
'use strict';
// Additional actual browser engines against the same explicit disposable fixtures.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const playwright = require('playwright');
const engine = process.env.QA_BROWSER_ENGINE || 'firefox';
if (!['firefox', 'webkit'].includes(engine)) throw Error('Unsupported compatibility engine.');
const base = process.env.QA_BROWSER_URL || 'http://127.0.0.1:18083';
if (!['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw Error('Loopback QA only.');
const fixtures = JSON.parse(fs.readFileSync(process.env.QA_BROWSER_FIXTURES, 'utf8'));
const output = process.env.QA_BROWSER_OUTPUT || '/tmp/aish-final-qa';
const cases = [];
const errors = [];
const assets = [];
let browser;
const save = () => {
  const counts = { passed: 0, failed: 0, skipped: 0 }; for (const item of cases) ++counts[item.status];
  fs.writeFileSync(path.join(output, `browser-${engine}-results.json`), JSON.stringify({ engine, version: browser ? browser.version() : null, base, counts, cases, errors, assets, limitation: 'Cross-engine frontend only; full administrator mutations are tested separately with Chromium.' }, null, 2));
  return counts;
};
async function test(name, operation) {
  try { await operation(); cases.push({ name, status: 'passed' }); }
  catch (error) { cases.push({ name, status: 'failed', priority: 'P2', error: error.message.slice(0, 1500) }); }
  save(); console.log(`${cases.at(-1).status}: ${name}`);
}
(async () => {
  try { browser = await playwright[engine].launch({ headless: true }); }
  catch (error) { cases.push({ name: 'engine-launch', status: 'skipped', reason: error.message.slice(0, 1000) }); console.log(JSON.stringify(save())); return; }
  try {
    const context = await browser.newContext(); context.setDefaultTimeout(10000);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 400 && ['script', 'stylesheet', 'font', 'image'].includes(response.request().resourceType())) assets.push({ url: response.url(), status: response.status() }); });
    const skill = fixtures.published[0];
    for (const [device, viewport] of Object.entries({ desktop: { width: 1440, height: 1000 }, mobile: { width: 360, height: 800 } })) {
      await page.setViewportSize(viewport);
      for (const language of ['en', 'zh']) {
        const routes = ['', 'skills/', `skills/${skill.id}/${skill.slug}/`, 'category/qa-browser-category/', 'tag/qa-browser-tag/', 'tutorials/', `tutorials/${fixtures.tutorials[language === 'zh' ? 'zh_CN' : 'en_US']}/`, 'search/?q=QA', 'about/', 'contact/', 'submit/', 'not-found/'];
        for (const [index, route] of routes.entries()) await test(`${device}-${language}-${index}`, async () => {
          const response = await page.goto(`${base}/${language}/${route}`, { waitUntil: 'networkidle' });
          assert.equal(response.status(), route === 'not-found/' ? 404 : 200);
          assert.equal(await page.locator('h1').count(), 1);
          const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth); assert(overflow <= 2, `Horizontal overflow ${overflow}px`);
          assert(!/Fatal error|Warning:|WordPress database error|QA_INTERNAL_REVIEW_NOTE_NOT_PUBLIC/.test(await page.locator('body').innerText()));
          if (index === 0 || index === 2) await page.screenshot({ path: path.join(output, 'screenshots', `${engine}-${device}-${language}-${index}.png`), fullPage: true });
        });
      }
      await test(`${device}-language-and-keyboard-navigation`, async () => {
        await page.goto(`${base}/en/skills/`, { waitUntil: 'networkidle' });
        if (device === 'mobile') { await page.locator('.mobile-navigation summary').focus(); await page.keyboard.press('Enter'); assert(await page.locator('.mobile-navigation').getAttribute('open') !== null); }
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.language-switch a[lang=zh-CN]').click()]); assert.equal(new URL(page.url()).pathname, '/zh/skills/');
      });
    }
    await test('combined-search-and-pagination', async () => {
      await page.goto(`${base}/en/skills/`, { waitUntil: 'networkidle' }); await page.locator('#skills-query').fill('QA Browser');
      for (const [id, value] of Object.entries({ category: 'qa-browser-category', tag: 'qa-browser-tag', platform: 'platform-qa-browser', sort: 'title' })) await page.locator(`#filter-${id}`).selectOption(value);
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.filter-panel button[type=submit]').click()]); assert.equal(await page.locator('.skill-card').count(), 12);
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('.aish-pagination a[rel=next]').click()]); assert.equal(await page.locator('.skill-card').count(), 2); assert.equal(new URL(page.url()).searchParams.get('q'), 'QA Browser');
    });
    await test('runtime-errors-and-assets', async () => { assert.equal(errors.length, 0); assert.equal(assets.length, 0); });
    await context.close();
  } catch (error) { cases.push({ name: 'suite-execution', status: 'failed', error: error.message.slice(0, 1500) }); }
  finally { const counts = save(); await browser.close(); console.log(JSON.stringify(counts)); if (counts.failed) process.exitCode = 1; }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
