/* Static design check only. Use existing Playwright via NODE_PATH; no install or WP setup. */
const path = require('node:path');
const base = process.env.AISH_DESIGN_URL || 'http://127.0.0.1:8765';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({executablePath:process.env.AISH_CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
  const page = await browser.newPage({ viewport: {width:1440,height:1000} });
  const errors=[]; page.on('pageerror', e => errors.push(String(e)));
  const checks=[];
  const check = async (name, fn) => { await fn(); checks.push(name); };
  await page.goto(base+'/admin.html', {waitUntil:'networkidle'});
  await check('Native WP shell colors and sidebar',async()=>{assert.equal(await page.locator('.wp-adminbar').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(29, 35, 39)');assert.equal(await page.locator('.wp-sidebar').evaluate(el=>el.getBoundingClientRect().width),160)});
  await check('Desktop dashboard has no page overflow',async()=>assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)));
  await page.screenshot({path:path.join(__dirname,'screenshots/admin-dashboard-1440.png'),fullPage:true});
  await check('Operational module detail and close focus',async()=>{await page.locator('.wp-stat[data-admin-module="ai"]').click();assert(await page.locator('#admin-module-dialog').isVisible());assert((await page.locator('#admin-module-content').textContent()).includes('结果待核实'));await page.keyboard.press('Escape');assert(await page.locator('.wp-stat[data-admin-module="ai"]').evaluate(el=>el===document.activeElement))});
  await page.locator('.wp-sidebar [data-admin-page="appearance"]').click();
  await check('Both homepage styles are selectable with directory default',async()=>{assert.deepEqual(await page.locator('#home-style option').evaluateAll(options=>options.map(option=>option.value)),['skills-directory','modern-saas']);assert.equal(await page.locator('#home-style').inputValue(),'skills-directory')});
  await page.locator('#home-title').fill('后台预览测试标题');
  await page.locator('[data-content-lang="en"]').click();
  await check('Independent bilingual copy retains unsaved values',async()=>{assert.equal(await page.locator('#home-title').inputValue(),'Find agent skills for your work');await page.locator('[data-content-lang="zh"]').click();assert.equal(await page.locator('#home-title').inputValue(),'后台预览测试标题')});
  await check('Module visibility and keyboard order',async()=>{await page.locator('#home-modules li').filter({hasText:'热门 Skills'}).locator('input').uncheck();await page.locator('[data-move="editors:-1"]').click();assert.equal(await page.locator('#home-modules li').nth(3).locator('.wp-module-name').textContent(),'编辑精选')});
  await page.locator('#home-save').click();
  await check('Saving draft keeps live revision unchanged',async()=>{assert.equal(await page.locator('#home-live-revision').textContent(),'#6');assert.equal(await page.locator('#home-draft-revision').textContent(),'#8')});
  await page.locator('#home-preview').click();
  const frame = await page.locator('#home-preview-frame').contentFrame();
  await check('Same-origin iframe reflects copy and disabled module',async()=>{await frame.locator('[data-home-title]').filter({hasText:'后台预览测试标题'}).waitFor();assert(await frame.locator('[data-home-module="trending"]').isHidden());assert(await frame.locator('#prototype-toolbar').isHidden())});
  await page.locator('[data-preview-width="mobile"]').click();
  await check('Mobile preview width',async()=>assert((await page.locator('#home-preview-frame').boundingBox()).width<=390));
  await page.locator('#home-preview-dialog [data-close-dialog]').click();
  await page.locator('#home-publish').click();
  await check('Publication requires explicit review dialog',async()=>{assert(await page.locator('#home-publish-dialog').isVisible());assert.equal(await page.locator('#home-live-revision').textContent(),'#6')});
  await page.locator('#home-confirm-publish').click();
  await check('Publication changes memory revision',async()=>assert.equal(await page.locator('#home-live-revision').textContent(),'#9'));
  await page.locator('#home-revision').selectOption('6');await page.locator('#home-restore').click();
  await check('Restore makes a draft without changing live',async()=>{assert.equal(await page.locator('#home-live-revision').textContent(),'#9');assert.equal(await page.locator('#home-draft-revision').textContent(),'#10');assert.equal(await page.locator('#home-title').inputValue(),'发现适合你任务的 Agent Skills')});
  await page.screenshot({path:path.join(__dirname,'screenshots/admin-appearance-1440.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  await check('Mobile appearance has no page overflow',async()=>assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)));
  await page.screenshot({path:path.join(__dirname,'screenshots/admin-appearance-390.png'),fullPage:true});
  await page.locator('#wp-menu-toggle').click();
  await check('Mobile menu opens and routes',async()=>{assert(await page.locator('.wp-sidebar').isVisible());await page.locator('.wp-sidebar [data-admin-page="dashboard"]').click();assert(await page.locator('#admin-dashboard').isVisible());assert(await page.locator('.wp-sidebar').isHidden())});
  await check('Mobile dashboard has no page overflow',async()=>assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)));
  await page.screenshot({path:path.join(__dirname,'screenshots/admin-dashboard-390.png'),fullPage:true});
  await page.goto(base+'/admin.html?lang=en&view=appearance',{waitUntil:'networkidle'});
  await check('English UI and page reload resets memory',async()=>{assert.equal(await page.locator('#admin-appearance h1').textContent(),'Homepage appearance');assert.equal(await page.locator('#home-live-revision').textContent(),'#6');assert.equal(await page.locator('#home-modules .wp-module-name').first().textContent(),'Directory introduction')});
  for(const state of ['empty','error','loading']){await page.goto(`${base}/admin.html?state=${state}`,{waitUntil:'networkidle'});await check(`Native admin ${state} state`,async()=>{assert.equal(await page.locator('#task-rows tr').count(),1);assert(await page.locator('#task-sort').isDisabled())})}
  await check('No browser JavaScript errors',async()=>assert.deepEqual(errors,[]));
  await page.setViewportSize({width:360,height:800});
  for(const view of ['dashboard','appearance']){
    await page.goto(`${base}/admin.html?view=${view}`,{waitUntil:'networkidle'});
    await check(`360px ${view}: no page overflow`,async()=>assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)));
  }
  await check('Unfocused skip link is clipped',async()=>assert.equal(await page.locator('.wp-skip').evaluate(el=>getComputedStyle(el).clipPath),'inset(50%)'));
  await page.goto(base+'/admin.html?state=missing',{waitUntil:'networkidle'});
  await check('Native missing translation notice',async()=>assert(await page.locator('.wp-notice').filter({hasText:'缺译状态示例'}).isVisible()));
  // Start a fresh in-memory session so style checks do not change the original revision assertions.
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(base+'/admin.html?view=appearance',{waitUntil:'networkidle'});
  const readLocale = async (locale) => {
    await page.locator(`[data-content-lang="${locale}"]`).click();
    return {title:await page.locator('#home-title').inputValue(),description:await page.locator('#home-description').inputValue(),modules:await page.locator('#home-modules li').evaluateAll(rows=>rows.map(row=>({id:row.querySelector('[data-move]').dataset.move.split(':')[0],enabled:row.querySelector('input').checked})))};
  };
  const initialZh = await readLocale('zh');
  const initialEn = await readLocale('en');
  await page.locator('#home-title').fill('Keep the English editorial title');
  await page.locator('#home-description').fill('English copy survives style changes and restores.');
  await page.locator('#home-modules li').filter({hasText:'入门教程'}).locator('input').uncheck();
  const editedEn = await readLocale('en');
  await readLocale('zh');
  await page.locator('#home-title').fill('保留中文编辑标题');
  await page.locator('#home-description').fill('切换风格与恢复草稿均保留独立文案。');
  await page.locator('#home-modules li').filter({hasText:'热门 Skills'}).locator('input').uncheck();
  await page.locator('[data-move="editors:-1"]').click();
  const editedZh = await readLocale('zh');
  const closePreview = async () => page.locator('#home-preview-dialog [data-close-dialog]').click();
  const openPreview = async (button, style, title) => {
    await page.locator(button).click();
    const source = new URL(await page.locator('#home-preview-frame').getAttribute('src'));
    assert.equal(source.searchParams.get('style'),style);
    const preview = page.frameLocator('#home-preview-frame');
    await preview.locator(`body[data-home-style="${style}"]`).waitFor();
    await preview.locator('[data-home-title]').filter({hasText:title}).waitFor();
    return preview;
  };
  await check('Switching either style preserves both languages and independent modules',async()=>{
    for(const style of ['modern-saas','skills-directory','modern-saas']) {
      await page.locator('#home-style').selectOption(style);
      assert.deepEqual(await readLocale('en'),editedEn);
      assert.deepEqual(await readLocale('zh'),editedZh);
      assert.equal(await page.locator('#home-style').inputValue(),style);
    }
    assert((await page.locator('#home-dirty').textContent()).includes('未保存'));
    assert((await page.locator('#home-live-style').textContent()).includes('专业资源目录'));
    assert.equal(await page.locator('#home-live-style-summary').textContent(),'专业资源目录');
  });
  await check('Saving selected style records its revision while keeping live directory',async()=>{
    await page.locator('#home-save').click();
    assert.equal(await page.locator('#home-draft-revision').textContent(),'#8');
    assert.equal(await page.locator('#home-live-revision').textContent(),'#6');
    assert((await page.locator('#home-revision option[value="8"]').textContent()).includes('SaaS'));
    assert((await page.locator('#home-live-style').textContent()).includes('专业资源目录'));
    assert.equal(await page.locator('#home-live-style-summary').textContent(),'专业资源目录');
  });
  await check('Draft preview renders SaaS style and edited module visibility and order',async()=>{
    const preview = await openPreview('#home-preview','modern-saas',editedZh.title);
    assert(await preview.locator('[data-home-module="trending"]').isHidden());
    const order = await preview.locator('[data-home-module]').evaluateAll(nodes=>nodes.map(node=>node.dataset.homeModule));
    assert(order.indexOf('editors') < order.indexOf('trending'));
    await closePreview();
  });
  await check('Current live preview is independent from the saved bilingual draft',async()=>{
    const preview = await openPreview('#home-live-preview','skills-directory',initialZh.title);
    assert(await preview.locator('[data-home-module="trending"]').isVisible());
    assert((await page.locator('#home-preview-title').textContent()).includes('线上示例'));
    await closePreview();
    assert.deepEqual(await readLocale('en'),editedEn);
    assert.deepEqual(await readLocale('zh'),editedZh);
    assert.equal(await page.locator('#home-style').inputValue(),'modern-saas');
  });
  await check('Style publication review names the style and cancellation preserves live',async()=>{
    await page.locator('#home-publish').click();
    assert((await page.locator('#home-publish-summary').textContent()).includes('SaaS'));
    assert((await page.locator('#home-changes').textContent()).includes('→'));
    await page.locator('#home-publish-dialog .wp-dialog-actions [data-close-dialog]').click();
    assert.equal(await page.locator('#home-live-revision').textContent(),'#6');
    assert((await page.locator('#home-live-style').textContent()).includes('专业资源目录'));
    assert.equal(await page.locator('#home-live-style-summary').textContent(),'专业资源目录');
  });
  await check('Confirmed style publication switches only the in-memory live configuration',async()=>{
    await page.locator('#home-publish').click();
    await page.locator('#home-confirm-publish').click();
    assert.equal(await page.locator('#home-live-revision').textContent(),'#9');
    assert((await page.locator('#home-live-style').textContent()).includes('SaaS'));
    assert.equal(await page.locator('#home-live-style-summary').textContent(),'SaaS 展示首页');
    await openPreview('#home-live-preview','modern-saas',editedZh.title);
    await closePreview();
    await readLocale('en');
    const englishPreview = await openPreview('#home-live-preview','modern-saas',editedEn.title);
    assert(await englishPreview.locator('[data-home-module="tutorials"]').isHidden());
    await closePreview();
  });
  await check('Restore includes style and both locale configurations without replacing live',async()=>{
    await page.locator('#home-revision').selectOption('6');
    await page.locator('#home-restore').click();
    assert.equal(await page.locator('#home-style').inputValue(),'skills-directory');
    assert.equal(await page.locator('#home-draft-revision').textContent(),'#10');
    assert.equal(await page.locator('#home-live-revision').textContent(),'#9');
    assert.equal(await page.locator('#home-live-style-summary').textContent(),'SaaS 展示首页');
    assert.deepEqual(await readLocale('en'),initialEn);
    assert.deepEqual(await readLocale('zh'),initialZh);
    await openPreview('#home-preview','skills-directory',initialZh.title);
    await closePreview();
    await openPreview('#home-live-preview','modern-saas',editedZh.title);
    await closePreview();
    await page.locator('#home-revision').selectOption('8');
    await page.locator('#home-restore').click();
    assert.equal(await page.locator('#home-style').inputValue(),'modern-saas');
    assert.deepEqual(await readLocale('en'),editedEn);
    assert.deepEqual(await readLocale('zh'),editedZh);
  });
  await check('Switching published SaaS back to directory keeps custom bilingual content',async()=>{
    await page.locator('#home-style').selectOption('skills-directory');
    await page.locator('#home-publish').click();
    assert((await page.locator('#home-publish-summary').textContent()).includes('专业资源目录'));
    await page.locator('#home-confirm-publish').click();
    assert.equal(await page.locator('#home-live-revision').textContent(),'#12');
    assert.equal(await page.locator('#home-live-style-summary').textContent(),'专业资源目录');
    assert.deepEqual(await readLocale('en'),editedEn);
    assert.deepEqual(await readLocale('zh'),editedZh);
    await openPreview('#home-live-preview','skills-directory',editedZh.title);
    await closePreview();
  });
  await check('Unknown style cannot save, preview, or publish a draft',async()=>{
    await page.locator('#home-style').evaluate(select=>{const option=document.createElement('option');option.value='unknown-style';option.textContent='Unknown';select.append(option)});
    await page.locator('#home-style').selectOption('unknown-style');
    const revision = await page.locator('#home-draft-revision').textContent();
    for(const button of ['#home-save','#home-preview','#home-publish']) {
      await page.locator(button).click();
      assert.equal(await page.locator('#home-draft-revision').textContent(),revision);
      assert((await page.locator('#home-state-message').textContent()).includes('未知风格'));
    }
    assert(await page.locator('#home-publish-dialog').isHidden());
    assert(await page.locator('#home-preview-dialog').isHidden());
    await openPreview('#home-live-preview','skills-directory',editedZh.title);
    await closePreview();
  });
  await check('Style session reload resets all memory and has no JavaScript errors',async()=>{
    await page.reload({waitUntil:'networkidle'});
    assert.equal(await page.locator('#home-style').inputValue(),'skills-directory');
    assert.equal(await page.locator('#home-live-revision').textContent(),'#6');
    assert.deepEqual(await readLocale('en'),initialEn);
    assert.deepEqual(await readLocale('zh'),initialZh);
    assert.deepEqual(errors,[]);
  });
  fs.writeFileSync(path.join(__dirname,'admin-checks.json'),JSON.stringify({scope:'Static native-style admin prototype; no WordPress, persistence, permissions, APIs, or Cron exercised',browser:browser.version(),checks,passed:checks.length,errors,screenshots:['screenshots/admin-dashboard-1440.png','screenshots/admin-appearance-1440.png','screenshots/admin-dashboard-390.png','screenshots/admin-appearance-390.png']},null,2));
  console.log(JSON.stringify({passed:checks.length,checks,errors},null,2));
  await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
