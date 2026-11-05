#!/usr/bin/env node
'use strict';

// Real Chromium + disposable WordPress/MySQL site. No mock HTTP or production credentials.
// QA_BROWSER_CREDENTIALS and QA_BROWSER_FIXTURES point to private JSON files outside Git.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const supplied = JSON.parse(fs.readFileSync(process.env.QA_BROWSER_CREDENTIALS, 'utf8'));
const credentials = supplied.admin ? supplied : { admin: supplied };
const base = process.env.QA_BROWSER_URL || supplied.url || credentials.admin.url || 'http://localhost:18083';
if (!['localhost', '127.0.0.1'].includes(new URL(base).hostname)) throw Error('Only disposable loopback sites are allowed.');
const fixtures = JSON.parse(fs.readFileSync(process.env.QA_BROWSER_FIXTURES, 'utf8'));
const output = process.env.QA_BROWSER_OUTPUT || '/tmp/aish-final-qa';
fs.mkdirSync(path.join(output, 'screenshots'), { recursive: true });
const cases = [];
const resourceErrors = [];
const consoleErrors = [];
const runId = Date.now().toString(36);
const redact = (text) => {
  let value = String(text);
  for (const account of Object.values(credentials)) if (account && account.password) value = value.split(account.password).join('[redacted]');
  return value.replace(/(_wpnonce=)[^&\s]+/g, '$1[redacted]').slice(0, 1500);
};
const screenshot = async (page, name) => page.screenshot({ path: path.join(output, 'screenshots', `${name}.png`), fullPage: true });
async function test(name, page, operation) {
  const start = Date.now();
  try { const evidence = await operation(); cases.push({ name, status: 'passed', ms: Date.now() - start, evidence }); }
  catch (error) { cases.push({ name, status: 'failed', priority: 'P2', ms: Date.now() - start, error: redact(error.message) }); if (page && !page.isClosed()) await screenshot(page, `failure-${name.replace(/[^a-z0-9-]/gi, '-')}`).catch(() => {}); }
  fs.writeFileSync(path.join(output, 'browser-results.json'), JSON.stringify({ browser: 'Chromium', base, runId, cases, resourceErrors, consoleErrors }, null, 2));
  console.log(`${cases.at(-1).status}: ${name}`);
}
async function goto(page, relative, status = 200) {
  const response = await page.goto(new URL(relative, base).href, { waitUntil: 'networkidle' });
  assert.equal(response.status(), status, `Unexpected HTTP status for ${relative}`);
  const html = await page.locator('body').innerText();
  assert(!/Fatal error|Warning:|WordPress database error|Uncaught (?:Error|Exception)|QA_INTERNAL_REVIEW_NOTE_NOT_PUBLIC/.test(html), 'PHP diagnostic or private review note appeared');
  return response;
}
async function submit(page, locator) { await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), locator.click()]); }
async function login(browser, account) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  context.setDefaultTimeout(10000);
  const page = await context.newPage();
  await page.goto(`${base}/wp-login.php`);
  await page.locator('#user_login').fill(account.username);
  await page.locator('#user_pass').fill(account.password);
  await submit(page, page.locator('#wp-submit'));
  assert(new URL(page.url()).pathname.startsWith('/wp-admin'), 'Login failed');
  return { context, page };
}
async function fillSkill(page, title, slug) {
  for (const locale of ['zh_CN', 'en_US']) {
    await page.locator(`#aish-tab-${locale}`).click();
    await page.locator(`[name="translations[${locale}][title]"]`).fill(`${title} ${locale}`);
    await page.locator(`[name="translations[${locale}][slug]"]`).fill(slug);
    await page.locator(`[name="translations[${locale}][summary]"]`).fill(locale === 'zh_CN' ? '浏览器真实编辑摘要。' : 'Real browser editing summary.');
    await page.locator(`#aish_${locale}_content-html`).click();
    await page.locator(`#aish_${locale}_content`).fill(locale === 'zh_CN' ? '<p>浏览器人工编辑正文。</p>' : '<p>Manually edited browser content.</p>');
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  try {
    const context = await browser.newContext();
    context.setDefaultTimeout(10000);
    const page = await context.newPage();
    page.on('pageerror', error => consoleErrors.push({ page: page.url(), error: redact(error.message) }));
    page.on('console', message => { if (message.type() === 'error') consoleErrors.push({ page: page.url(), error: redact(message.text()) }); });
    page.on('response', response => { if (response.status() >= 400 && ['script', 'stylesheet', 'image', 'font'].includes(response.request().resourceType())) resourceErrors.push({ url: response.url(), status: response.status() }); });
    const skill = fixtures.published[0];
    const viewports = { desktop: { width: 1440, height: 1000 }, tablet: { width: 768, height: 1024 }, mobile: { width: 360, height: 800 } };
    for (const [device, viewport] of Object.entries(viewports)) {
      await page.setViewportSize(viewport);
      for (const language of ['zh', 'en']) {
        const routes = ['', 'skills/', `skills/${skill.id}/${skill.slug}/`, 'category/qa-browser-category/', 'tag/qa-browser-tag/', 'tutorials/', `tutorials/${fixtures.tutorials[language === 'zh' ? 'zh_CN' : 'en_US']}/`, 'search/?q=QA', 'about/', 'contact/', 'submit/', 'not-found/'];
        for (const [index, route] of routes.entries()) await test(`${device}-${language}-route-${index}`, page, async () => {
          const response = await goto(page, `/${language}/${route}`, route === 'not-found/' ? 404 : 200);
          assert.equal(await page.locator('h1').count(), 1, 'Expected exactly one H1');
          const dimensions = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth }));
          assert(dimensions.scroll <= dimensions.viewport + 2, `Horizontal overflow: ${JSON.stringify(dimensions)}`);
          if (route !== 'not-found/') assert.equal(await page.locator('link[rel=canonical]').count(), 1, 'Missing/duplicate Canonical');
          if (['search/?q=QA', 'submit/', 'not-found/'].includes(route)) assert.match(response.headers()['x-robots-tag'] || '', /noindex/);
          await screenshot(page, `${device}-${language}-${index}`);
          return { status: response.status(), url: new URL(page.url()).pathname, dimensions };
        });
      }
      await test(`${device}-navigation`, page, async () => {
        await goto(page, '/en/');
        if (device !== 'desktop') { await page.locator('.mobile-navigation summary').click(); assert(await page.locator('.mobile-navigation').getAttribute('open') !== null); await submit(page, page.locator('.mobile-navigation a[href$="/en/skills/"]')); }
        else await submit(page, page.locator('.desktop-nav a[href$="/en/skills/"]'));
        assert(new URL(page.url()).pathname === '/en/skills/');
        await submit(page, page.locator('.language-switch a[lang=zh-CN]')); assert(new URL(page.url()).pathname === '/zh/skills/');
      });
    }
    await page.setViewportSize(viewports.desktop);
    await test('combined-filter-sort-pagination', page, async () => {
      await goto(page, '/en/skills/'); await page.locator('#skills-query').fill('QA Browser');
      await page.locator('#filter-category').selectOption('qa-browser-category'); await page.locator('#filter-tag').selectOption('qa-browser-tag'); await page.locator('#filter-platform').selectOption('platform-qa-browser'); await page.locator('#filter-sort').selectOption('title');
      await submit(page, page.locator('.filter-panel button[type=submit]'));
      assert.equal(await page.locator('.skill-card').count(), 12); assert.match(new URL(page.url()).search, /category=qa-browser-category/);
      await submit(page, page.locator('.aish-pagination a[rel=next]')); assert.equal(new URL(page.url()).searchParams.get('page'), '2'); assert.equal(await page.locator('.skill-card').count(), 2); assert.equal(new URL(page.url()).searchParams.get('q'), 'QA Browser');
      await page.locator('#skills-query').fill('definitely-no-qa-result'); await submit(page, page.locator('.filter-panel button[type=submit]')); assert.equal(await page.locator('.skill-card').count(), 0); assert(await page.locator('.empty-state').isVisible());
    });
    await test('missing-translation-fallback', page, async () => {
      const row = fixtures.zh_only; await goto(page, `/en/skills/${row.id}/${row.slug}/`);
      assert(new URL(page.url()).pathname.startsWith('/zh/')); assert.equal(new URL(page.url()).searchParams.get('translation'), 'missing');
      assert.equal(await page.locator('link[hreflang=en]').count(), 0); assert(await page.locator('[role=status]').isVisible());
    });
    for (const [state, row] of Object.entries(fixtures.hidden)) await test(`hidden-${state}-public`, page, async () => { const response = await goto(page, `/en/skills/${row.id}/${row.slug}/`, 404); assert.match(response.headers()['x-robots-tag'] || '', /noindex/); assert(!(await page.locator('main').innerText()).includes(`QA Browser Skill ${state}`)); });
    await test('forms-native-validation-and-success', page, async () => {
      for (const kind of ['contact', 'submit']) {
        await goto(page, `/en/${kind}/`); await page.locator('main button[type=submit]').click(); assert(!new URL(page.url()).searchParams.has('form'));
        await page.locator('#aish-name').fill(`QA Browser ${runId}`); await page.locator('#aish-email').fill('browser-qa@example.invalid'); await page.locator('#aish-message').fill(`Isolated QA private ${kind} message ${runId}`);
        if (kind === 'submit') await page.locator('#aish-github').fill('https://github.com/mtccv/Skills-ak');
        await submit(page, page.locator('main button[type=submit]')); assert.equal(new URL(page.url()).searchParams.get('form'), 'sent'); assert(await page.locator('main [role=status]').isVisible());
      }
    });
    await test('form-invalid-nonce-feedback', page, async () => {
      await goto(page, '/en/contact/'); await page.locator('#aish-name').fill('QA'); await page.locator('#aish-email').fill('qa@example.invalid'); await page.locator('#aish-message').fill('Private fixture');
      await page.locator('main input[name=_wpnonce]').evaluate(input => { input.value = 'invalid-qa-nonce'; }); await submit(page, page.locator('main button[type=submit]')); assert.equal(new URL(page.url()).searchParams.get('form'), 'expired');
    });
    await test('frontend-console-and-assets', page, async () => { assert.equal(resourceErrors.length, 0, 'Failed frontend assets'); const errors = consoleErrors.filter(item => !item.error.includes('404 (Not Found)')); assert.equal(errors.length, 0, 'Frontend console errors'); return { failedAssets: resourceErrors.length, javascriptErrors: errors.length }; });
    const admin = await login(browser, credentials.admin);
    const adminPage = admin.page;
    adminPage.on('pageerror', error => consoleErrors.push({ page: adminPage.url(), error: redact(error.message) }));
    adminPage.on('response', response => { if (response.status() >= 400 && ['script', 'stylesheet', 'image', 'font'].includes(response.request().resourceType())) resourceErrors.push({ url: response.url(), status: response.status() }); });
    for (const screen of ['aish', 'aish-skills', 'aish-skill-edit', 'aish-categories', 'aish-tags', 'aish-repositories', 'aish-reviews', 'aish-settings', 'aish-seo', 'aish-imports', 'aish-ai-settings', 'aish-ai-generation']) await test(`admin-screen-${screen}`, adminPage, async () => { await goto(adminPage, `/wp-admin/admin.php?page=${screen}`); assert(!(await adminPage.locator('#wpbody-content').innerText()).includes('无法显示内容')); assert(await adminPage.locator('h1').count() > 0); });
    for (const kind of ['category', 'tag', 'repository']) await test(`admin-${kind}-create-edit-archive`, adminPage, async () => {
      const screen = { category: 'aish-categories', tag: 'aish-tags', repository: 'aish-repositories' }[kind];
      const name = `QA UI ${kind} ${runId}`;
      await goto(adminPage, `/wp-admin/admin.php?page=${screen}`);
      const form = adminPage.locator('form').filter({ has: adminPage.locator(`[name=action][value="${kind === 'repository' ? 'aish_save_repository' : 'aish_save_term'}"]`) });
      if (kind === 'repository') { await form.locator('[name=owner]').fill('qa-browser'); await form.locator('[name=name]').fill(`fixture-${runId}`); }
      else { await form.locator('[name=name]').fill(name); await form.locator('[name=slug]').fill(`qa-ui-${kind}-${runId}`); }
      await submit(adminPage, form.locator('input[type=submit]')); assert.equal(new URL(adminPage.url()).searchParams.get('aish_notice'), 'saved');
      const rowText = kind === 'repository' ? `qa-browser/fixture-${runId}` : name;
      const row = adminPage.locator('#the-list tr').filter({ hasText: rowText }); assert.equal(await row.count(), 1);
      await submit(adminPage, row.locator('strong a'));
      const id = new URL(adminPage.url()).searchParams.get('id'); assert(Number(id) > 0);
      const editedName = kind === 'repository' ? `fixture-${runId}-edited` : `${name} edited`;
      await adminPage.locator('input[name=name]').fill(editedName); await submit(adminPage, adminPage.locator('input[type=submit][name=submit]')); assert.equal(new URL(adminPage.url()).searchParams.get('aish_notice'), 'saved');
      await goto(adminPage, `/wp-admin/admin.php?page=${screen}`);
      const currentRow = adminPage.locator('#the-list tr').filter({ hasText: kind === 'repository' ? `qa-browser/${editedName}` : editedName });
      await currentRow.hover(); await submit(adminPage, currentRow.locator('.archive a')); await submit(adminPage, adminPage.locator('input[name=confirm]')); assert.equal(new URL(adminPage.url()).searchParams.get('aish_notice'), 'archived');
      await goto(adminPage, `/wp-admin/admin.php?page=${screen}&id=${id}`); assert(await adminPage.locator('input[name=name]').inputValue()); return { id: Number(id) };
    });
    await test('native-tutorial-metabox-bilingual-save', adminPage, async () => {
      const id = fixtures.tutorials.en_US;
      await goto(adminPage, `/wp-admin/post.php?post=${id}&action=edit`);
      assert.equal(await adminPage.locator('#aish-editorial-locale').inputValue(), 'en_US');
      assert.equal(await adminPage.locator('#aish-editorial-translation').inputValue(), String(fixtures.tutorials.zh_CN));
      await adminPage.locator('#title').fill('QA Browser Tutorial UI verified');
      await adminPage.locator('#aish-editorial-skills').fill(`${fixtures.published[0].id},${fixtures.published[1].id}`);
      await submit(adminPage, adminPage.locator('#publish'));
      assert.equal(await adminPage.locator('#aish-editorial-skills').inputValue(), `${fixtures.published[0].id},${fixtures.published[1].id}`);
      await goto(page, `/en/tutorials/${id}/`); assert.equal(await page.locator('h1').innerText(), 'QA Browser Tutorial UI verified');
      await submit(page, page.locator('.language-switch a[lang=zh-CN]')); assert.equal(new URL(page.url()).pathname, `/zh/tutorials/${fixtures.tutorials.zh_CN}/`);
    });
    await test('native-private-inbox-review', adminPage, async () => {
      await goto(adminPage, `/wp-admin/edit.php?post_type=aish_message&s=${runId}`);
      const rows = adminPage.locator('#the-list tr'); assert.equal(await rows.count(), 2);
      await submit(adminPage, rows.first().locator('.row-title'));
      const id = Number(new URL(adminPage.url()).searchParams.get('post'));
      await adminPage.locator('#aish-message-state').selectOption('reviewed'); await adminPage.locator('#aish-message-notes').fill('QA private handling note'); await submit(adminPage, adminPage.locator('#publish'));
      assert.equal(await adminPage.locator('#aish-message-state').inputValue(), 'reviewed');
      assert.equal(await adminPage.locator('#aish-message-notes').inputValue(), 'QA private handling note');
      await goto(page, `/?p=${id}`); assert(!(await page.locator('body').innerText()).includes('QA private handling note'));
      return { id };
    });
    let createdId;
    await test('admin-bilingual-create-pending', adminPage, async () => {
      await goto(adminPage, '/wp-admin/admin.php?page=aish-skill-edit'); await fillSkill(adminPage, `QA UI ${runId}`, `qa-ui-${runId}`);
      await submit(adminPage, adminPage.locator('button[name=save_mode][value=pending]')); assert.equal(new URL(adminPage.url()).searchParams.get('aish_notice'), 'saved');
      createdId = Number(new URL(adminPage.url()).searchParams.get('id')); assert(createdId > 0);
      await adminPage.locator('#aish-tab-zh_CN').click(); assert.equal(await adminPage.locator('[name="translations[zh_CN][title]"]').inputValue(), `QA UI ${runId} zh_CN`);
      await adminPage.locator('#aish-tab-en_US').click(); assert.equal(await adminPage.locator('[name="translations[en_US][title]"]').inputValue(), `QA UI ${runId} en_US`);
      await adminPage.locator('#aish-tab-en_US').press('ArrowLeft'); assert.equal(await adminPage.locator('#aish-tab-zh_CN').getAttribute('aria-selected'), 'true');
      await goto(page, `/en/skills/${createdId}/qa-ui-${runId}/`, 404); return { skillId: createdId };
    });
    await test('admin-private-preview', adminPage, async () => {
      assert(createdId, 'Create prerequisite failed'); await goto(adminPage, `/wp-admin/admin.php?page=aish-skill-edit&id=${createdId}`);
      const preview = await adminPage.locator('a[href*="action=aish_preview"]').first().getAttribute('href');
      const popup = await admin.context.newPage(); const response = await popup.goto(preview); assert.equal(response.status(), 200); assert.match(response.headers()['x-robots-tag'] || '', /noindex/); assert.match(response.headers()['cache-control'] || '', /no-store/); assert((await popup.locator('body').innerText()).includes(`QA UI ${runId}`)); await popup.close();
      const denied = await page.request.get(preview); assert.notEqual(denied.status(), 200, 'Anonymous preview must not expose a successful body');
    });
    await test('admin-review-publish-edit-invalidation', adminPage, async () => {
      assert(createdId, 'Create prerequisite failed'); await goto(adminPage, `/wp-admin/admin.php?page=aish-reviews&id=${createdId}`); await adminPage.locator('[name=risk]').selectOption('low'); await adminPage.locator('[name=notes]').fill('QA private review note'); await submit(adminPage, adminPage.locator('button[name=decision][value=approved]'));
      await goto(page, `/en/skills/${createdId}/qa-ui-${runId}/`); assert((await page.locator('h1').innerText()).includes(`QA UI ${runId}`));
      await goto(adminPage, `/wp-admin/admin.php?page=aish-skill-edit&id=${createdId}`); await adminPage.locator('#aish-tab-en_US').click(); await adminPage.locator('[name="translations[en_US][summary]"]').fill('Changed human content needs another review.'); await submit(adminPage, adminPage.locator('button[name=save_mode][value=draft]')); await goto(page, `/en/skills/${createdId}/qa-ui-${runId}/`, 404);
    });
    await test('admin-archive-confirmation', adminPage, async () => {
      assert(createdId, 'Create prerequisite failed'); await goto(adminPage, `/wp-admin/admin.php?page=aish-skill-edit&id=${createdId}`); await submit(adminPage, adminPage.locator('a[href*="page=aish-confirm"]'));
      assert(await adminPage.locator('[name=confirmed][value="1"]').count() === 1); await submit(adminPage, adminPage.locator('input[name=confirm]')); assert.equal(new URL(adminPage.url()).searchParams.get('aish_notice'), 'archived');
      await goto(adminPage, `/wp-admin/admin.php?page=aish-skill-edit&id=${createdId}`); assert((await adminPage.locator('#wpbody-content').innerText()).includes('归档')); await goto(page, `/en/skills/${createdId}/qa-ui-${runId}/`, 404);
    });
    await test('admin-invalid-nonce', adminPage, async () => {
      const response = await adminPage.request.post(`${base}/wp-admin/admin-post.php`, { form: { action: 'aish_settings', _wpnonce: 'invalid-qa-nonce', per_page: '50', default_locale: 'en_US' } }); assert.equal(response.status(), 403);
    });
    await test('admin-mobile-language-editor', adminPage, async () => {
      await adminPage.setViewportSize(viewports.mobile); await goto(adminPage, `/wp-admin/admin.php?page=aish-skill-edit&id=${createdId}`); await adminPage.locator('#aish-tab-en_US').click(); assert(await adminPage.locator('#aish-panel-en_US').isVisible()); assert(!(await adminPage.locator('#aish-panel-zh_CN').isVisible())); await screenshot(adminPage, 'admin-mobile-editor'); await adminPage.setViewportSize(viewports.desktop);
    });
    if (credentials.subscriber) {
      const subscriber = await login(browser, credentials.subscriber);
      await test('subscriber-management-denied', subscriber.page, async () => { for (const screen of ['aish-skills', 'aish-ai-generation', 'aish-settings']) { const response = await subscriber.page.goto(`${base}/wp-admin/admin.php?page=${screen}`); assert.equal(response.status(), 403); } });
      await subscriber.context.close();
    } else cases.push({ name: 'subscriber-management-denied', status: 'skipped', reason: 'No disposable subscriber credentials supplied.' });
    for (const [role, screen] of [['ai_generate', 'aish-ai-generation'], ['ai_settings', 'aish-ai-settings']]) {
      if (!credentials[role]) { cases.push({ name: `${role}-single-capability-menu`, status: 'skipped', reason: 'No isolated single-capability account supplied.' }); continue; }
      const account = await login(browser, credentials[role]);
      await test(`${role}-single-capability-menu`, account.page, async () => {
        await goto(account.page, `/wp-admin/admin.php?page=${screen}`);
        assert(await account.page.locator(`#adminmenu a[href="admin.php?page=${screen}"]`).first().isVisible(), 'Authorized AI-only navigation missing');
        const denied = await account.page.goto(`${base}/wp-admin/admin.php?page=aish`); assert.equal(denied.status(), 403, 'AI-only user must not gain legacy Dashboard');
      }); await account.context.close();
    }
    const noJsContext = await browser.newContext({ javaScriptEnabled: false, viewport: viewports.mobile }); const noJs = await noJsContext.newPage();
    await test('mobile-no-javascript-navigation-search', noJs, async () => { await goto(noJs, '/en/'); await noJs.locator('.mobile-navigation summary').click(); await submit(noJs, noJs.locator('.mobile-navigation a[href$="/en/skills/"]')); await noJs.locator('#skills-query').fill('QA'); await submit(noJs, noJs.locator('.filter-panel button[type=submit]')); assert(await noJs.locator('.skill-card').count() > 0); await screenshot(noJs, 'mobile-no-js'); });
    await test('runtime-errors-and-assets-after-admin', adminPage, async () => { assert.equal(resourceErrors.length, 0, 'Failed frontend/admin assets'); assert.equal(consoleErrors.filter(item => !item.error.includes('404 (Not Found)')).length, 0, 'Unexpected runtime errors'); });
    await noJsContext.close(); await admin.context.close(); await context.close();
  } catch (error) {
    cases.push({ name: 'suite-execution', status: 'failed', priority: 'P1', error: redact(error.message) });
  } finally {
    const counts = { passed: 0, failed: 0, skipped: 0 }; for (const row of cases) ++counts[row.status];
    const report = JSON.stringify({ browser: await browser.version(), base, runId, counts, cases, resourceErrors, consoleErrors, limitations: ['This file reports Chromium; separate browser-compat reports cover any additional engines.', 'Only isolated QA database content is used.', 'This script does not call GitHub or AI providers.'] }, null, 2);
    fs.writeFileSync(path.join(output, 'browser-results.json'), report);
    fs.writeFileSync(path.join(output, `browser-results-${runId}.json`), report);
    await browser.close(); console.log(JSON.stringify(counts)); if (counts.failed) process.exitCode = 1;
  }
})().catch(error => { console.error(redact(error.message)); process.exitCode = 1; });
