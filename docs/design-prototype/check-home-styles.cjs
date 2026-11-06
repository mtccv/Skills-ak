/* Design-only browser regression. Existing Playwright/Chromium required.
 * Never writes WordPress, storage, remote services, or historical comparison files.
 * AISH_DESIGN_URL=http://127.0.0.1:8767 node docs/design-prototype/check-home-styles.cjs
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const base = process.env.AISH_DESIGN_URL || 'http://127.0.0.1:8765';
const address = new URL(base);
if (address.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(address.hostname)) throw new Error('Use an isolated localhost HTTP design service');
const ids = ['hero','search','categories','trending','editors','recent','rated','tutorials','contribute'];
const styles = ['skills-directory','modern-saas'];
const screenshots = path.join(__dirname, 'screenshots', 'styles');
const results = {scope:'Static homepage style registry and shared modules only; no WordPress, database, API, or production publication tested',matrix:[],states:[],interactions:[],failures:[],consoleErrors:[],externalRequests:[],failedResources:[],screenshots:[]};
(async () => {
  const browser = await chromium.launch({executablePath:process.env.AISH_CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
  results.browser = browser.version();
  const context = await browser.newContext({viewport:{width:1440,height:1000},deviceScaleFactor:1,reducedMotion:'reduce'});
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin !== address.origin) { results.externalRequests.push(url.origin + url.pathname); return route.abort(); }
    return route.continue();
  });
  const page = await context.newPage();
  page.on('pageerror', error => results.consoleErrors.push(error.message));
  page.on('console', message => { if(message.type()==='error')results.consoleErrors.push(message.text()); });
  page.on('response', response => { if(response.status()>=400)results.failedResources.push({url:response.url(),status:response.status()}); });
  async function check(group,name,fn) { try { await fn(); results[group].push({name,pass:true}); } catch(error) { results[group].push({name,pass:false,error:error.message});results.failures.push(name+': '+error.message); } }
  const go = (file,params) => page.goto(`${base}/${file}?${new URLSearchParams(params)}`,{waitUntil:'networkidle'});
  const test = (name,fn) => check('interactions',name,fn);
  fs.mkdirSync(screenshots,{recursive:true});
  for(const style of styles)for(const lang of ['zh','en'])for(const width of [1440,768,390,360]) {
    await check('matrix',`${style}/${lang}/${width}`,async()=>{
      await page.setViewportSize({width,height:width<768?844:1000});
      await go('index.html',{style,lang});
      assert.equal(await page.locator('body').getAttribute('data-home-style'),style);
      assert.equal(await page.locator('html').getAttribute('lang'),lang==='zh'?'zh-CN':'en');
      assert.equal(await page.locator('h1:visible').count(),1);
      assert.deepEqual(await page.locator('[data-home-module]').evaluateAll(nodes=>nodes.map(node=>node.dataset.homeModule)),ids);
      assert.equal(await page.evaluate(()=>{const ids=[...document.querySelectorAll('[id]')].map(node=>node.id);return ids.length-new Set(ids).size;}),0);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No page horizontal overflow');
      assert.equal(await page.locator('.home-style-console').isVisible(),style==='modern-saas');
      const featured = await page.locator('[data-home-module=trending] .skill-card h3').first().textContent();
      assert.equal(await page.locator('[data-home-featured-title]').textContent(),featured);
      assert.equal(await page.locator('[data-home-featured-title]').evaluate(node=>node.closest('.home-style-console-resource').hidden),false);
      if(width===1440 || width===390) {
        const filename=`${style}-${width}-${lang}.png`;
        await page.screenshot({path:path.join(screenshots,filename),fullPage:true});
        results.screenshots.push('screenshots/styles/'+filename);
      }
    });
  }
  await page.setViewportSize({width:390,height:844});
  for(const style of styles)for(const state of ['loading','empty','error','missing']) {
    await check('states',`${style}/${state}`,async()=>{
      await go('index.html',{lang:'en',style,state});
      assert.equal(await page.locator('body').getAttribute('data-home-style'),style);
      assert.equal(await page.locator('#prototype-state').inputValue(),state);
      const text=await page.locator('body').innerText();
      const feedback={loading:/Loading demo content/,empty:/Nothing matches yet/,error:/We couldn.t load this section/,missing:/Missing-translation example/};
      assert(feedback[state].test(text));
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
      assert.equal(await page.locator('[data-home-featured-title]').textContent(),'');
      assert.equal(await page.locator('[data-home-featured-description]').textContent(),'');
      assert.equal(await page.locator('.home-style-console-resource').first().isVisible(),false);
      assert(await page.locator('[data-home-title]').isVisible(),'Generic Hero title remains visible');
      assert(await page.locator('[data-home-description]').isVisible(),'Generic Hero description remains visible');
      await page.locator('#prototype-home-style').selectOption(style==='modern-saas'?'skills-directory':'modern-saas');
      assert.equal(await page.locator('[data-home-featured-description]').textContent(),'');
      assert.equal(await page.locator('.home-style-console-resource').first().isVisible(),false);
    });
  }
  await test('Repeated toolbar style changes retain module DOM, text and favorite state',async()=>{
    await page.setViewportSize({width:1440,height:1000});await go('index.html',{lang:'en',style:'skills-directory'});
    const favorite=page.locator('[data-home-module=trending] [data-favorite]').first();await favorite.click();
    const moduleHandle=await page.locator('[data-home-module=trending]').elementHandle();
    const titleHandle=await page.locator('[data-home-title]').elementHandle();
    const initial=await page.locator('[data-home-module]').evaluateAll(nodes=>nodes.map(node=>({id:node.dataset.homeModule,hidden:node.hidden,text:node.textContent})));
    for(const style of ['modern-saas','skills-directory','modern-saas']) {
      await page.locator('#prototype-home-style').selectOption(style);
      assert.equal(await page.locator('body').getAttribute('data-home-style'),style);
      assert.equal(await favorite.getAttribute('aria-pressed'),'true');
      assert(await moduleHandle.evaluate(node=>node===document.querySelector('[data-home-module=trending]')));
      assert(await titleHandle.evaluate(node=>node===document.querySelector('[data-home-title]')));
      assert.deepEqual(await page.locator('[data-home-module]').evaluateAll(nodes=>nodes.map(node=>({id:node.dataset.homeModule,hidden:node.hidden,text:node.textContent}))),initial);
    }
    assert.equal(new URL(page.url()).searchParams.get('style'),'modern-saas');
    assert.equal(await page.evaluate(()=>localStorage.length+sessionStorage.length),0);
  });
  await test('Language switching retains the selected style and shares translated featured content',async()=>{
    await page.locator('[data-language-switch]').click();await page.waitForURL('**/index.html?**');
    assert.equal(await page.locator('html').getAttribute('lang'),'zh-CN');
    assert.equal(await page.locator('body').getAttribute('data-home-style'),'modern-saas');
    assert.equal(new URL(page.url()).searchParams.get('style'),'modern-saas');
    assert.equal(await page.locator('[data-home-featured-description]').textContent(),await page.locator('[data-home-module=trending] .description').first().textContent());
  });
  await test('Unknown style falls back to directory without fetching arbitrary URLs',async()=>{
    await go('index.html',{style:'https://example.invalid/skin.css',lang:'en'});
    assert.equal(await page.locator('body').getAttribute('data-home-style'),'skills-directory');
    assert.equal(await page.locator('#prototype-home-style').inputValue(),'skills-directory');
    assert.equal(await page.locator('#prototype-home-style option').count(),2);
    assert.equal(await page.locator('link[href*="example.invalid"],script[src*="example.invalid"]').count(),0);
  });
  await test('Device preview switches home skins and keeps inner pages on the shared design',async()=>{
    await go('preview.html',{page:'home',style:'modern-saas',lang:'en',width:'390'});
    const style=page.locator('[name=style]');const frame=page.frameLocator('#design-frame');
    await frame.locator('body[data-home-style=modern-saas]').waitFor();
    assert.equal(await frame.locator('body').evaluate(()=>innerWidth),390);
    await style.selectOption('skills-directory');await frame.locator('body[data-home-style=skills-directory]').waitFor();
    await style.selectOption('modern-saas');await frame.locator('body[data-home-style=modern-saas]').waitFor();
    await page.locator('[name=page]').selectOption('skills');await frame.locator('body[data-page=skills]').waitFor();
    assert(await style.isDisabled());assert.equal(await style.inputValue(),'modern-saas');
    assert.equal(await frame.locator('body').getAttribute('data-home-style'),null);
    assert.equal(new URL(await page.locator('#design-frame').getAttribute('src'),base).searchParams.get('style'),null);
    await page.locator('[name=page]').selectOption('home');await frame.locator('body[data-home-style=modern-saas]').waitFor();assert(!(await style.isDisabled()));
  });
  await test('Shared home module contract retains configured text, visibility and order through a skin switch',async()=>{
    // A same-origin parent sends the same finite contract used by the admin preview.
    // This tests the supported message interface; it does not create live settings.
    await go('preview.html',{page:'home',style:'skills-directory',lang:'en',width:'1440'});
    const frame=page.frameLocator('#design-frame');await frame.locator('body[data-home-style=skills-directory]').waitFor();
    const modules=[...ids];[modules[3],modules[4]]=[modules[4],modules[3]];
    const config={type:'aish-design-home',styleId:'skills-directory',locale:'en',title:'Configured design title',description:'Configured demonstration description.',modules:modules.map(id=>({id,enabled:id!=='rated'}))};
    await page.evaluate(message=>document.getElementById('design-frame').contentWindow.postMessage(message,location.origin),config);
    await frame.locator('[data-home-title]').filter({hasText:'Configured design title'}).waitFor();
    const snapshot=await frame.locator('[data-home-module]').evaluateAll(nodes=>nodes.map(node=>({id:node.dataset.homeModule,hidden:node.hidden})));
    await page.evaluate(message=>document.getElementById('design-frame').contentWindow.postMessage(message,location.origin),{...config,styleId:'modern-saas'});
    await frame.locator('body[data-home-style=modern-saas]').waitFor();
    assert.equal(await frame.locator('[data-home-title]').textContent(),'Configured design title');
    assert.equal(await frame.locator('[data-home-description]').textContent(),'Configured demonstration description.');
    assert.deepEqual(await frame.locator('[data-home-module]').evaluateAll(nodes=>nodes.map(node=>({id:node.dataset.homeModule,hidden:node.hidden}))),snapshot);
    const disabled={...config,styleId:'modern-saas',modules:config.modules.map(module=>({...module,enabled:module.id==='trending'?false:module.enabled}))};
    await page.evaluate(message=>document.getElementById('design-frame').contentWindow.postMessage(message,location.origin),disabled);
    await frame.locator('[data-home-module=trending]').waitFor({state:'hidden'});
    assert.equal(await frame.locator('[data-home-featured-title]').textContent(),'');
    assert.equal(await frame.locator('[data-home-featured-description]').textContent(),'');
    assert.equal(await frame.locator('.home-style-console-resource').first().isVisible(),false);
    assert(await frame.locator('[data-home-title]').isVisible());
    assert.equal(await frame.locator('[data-home-description]').textContent(),'Configured demonstration description.');
    for(const styleId of ['skills-directory','modern-saas']) {
      await page.evaluate(message=>document.getElementById('design-frame').contentWindow.postMessage(message,location.origin),{...disabled,styleId});
      await frame.locator(`body[data-home-style="${styleId}"]`).waitFor();
      assert.equal(await frame.locator('[data-home-featured-description]').textContent(),'');
      assert.equal(await frame.locator('.home-style-console-resource').first().isVisible(),false);
    }
    await page.evaluate(message=>document.getElementById('design-frame').contentWindow.postMessage(message,location.origin),{...config,styleId:'modern-saas'});
    await frame.locator('[data-home-module=trending]').waitFor({state:'visible'});
    assert.equal(await frame.locator('[data-home-featured-title]').textContent(),await frame.locator('[data-home-module=trending] .skill-card h3').first().textContent());
    assert.equal(await frame.locator('[data-home-featured-description]').textContent(),await frame.locator('[data-home-module=trending] .description').first().textContent());
    assert(await frame.locator('.home-style-console-resource').first().isVisible());
    await page.evaluate(message=>document.getElementById('design-frame').contentWindow.postMessage(message,location.origin),{...config,styleId:'https://example.invalid/template',title:'Must not apply'});
    await page.waitForTimeout(50);
    assert.equal(await frame.locator('[data-home-title]').textContent(),'Configured design title');
    assert.equal(await frame.locator('body').getAttribute('data-home-style'),'modern-saas');
  });
  await test('No browser errors, failed resources, external requests, cookies or storage',async()=>{
    assert.deepEqual(results.consoleErrors,[]);assert.deepEqual(results.externalRequests,[]);assert.deepEqual(results.failedResources,[]);
    assert.equal((await context.cookies()).length,0);assert.equal(await page.evaluate(()=>localStorage.length+sessionStorage.length),0);
  });
  results.passed=[...results.matrix,...results.states,...results.interactions].filter(check=>check.pass).length;
  results.failed=results.failures.length;
  fs.writeFileSync(path.join(__dirname,'home-style-checks.json'),JSON.stringify(results,null,2)+'\n');
  console.log(JSON.stringify({browser:results.browser,passed:results.passed,failed:results.failed,failures:results.failures,screenshots:results.screenshots.length},null,2));
  await browser.close();if(results.failed)process.exitCode=1;
})().catch(error=>{console.error(error);process.exit(1);});
