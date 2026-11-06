/* Optional design-only browser checks. Uses an already installed Playwright and
 * Chromium; does not install dependencies, start WordPress, or call APIs.
 * Usage: NODE_PATH=/path/to/node_modules AISH_DESIGN_URL=http://127.0.0.1:8765 node docs/design-prototype/check-preview.cjs
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const base = process.env.AISH_DESIGN_URL || 'http://127.0.0.1:8765';
const output = __dirname;
const results = {scope:'Static Phase 7.5 design prototype only; no WordPress/database/API tests',matrix:[],states:[],interactions:[],failures:[],consoleErrors:[],externalRequests:[],failedResources:[]};
(async()=>{
 const browser = await chromium.launch({executablePath:process.env.AISH_CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 results.browser = browser.version();
 const context = await browser.newContext({viewport:{width:1440,height:1000},deviceScaleFactor:1});
 await context.route('**/*',route=>{const u=new URL(route.request().url());if(u.origin!==new URL(base).origin){results.externalRequests.push(u.origin+u.pathname);return route.abort();}return route.continue();});
 const p = await context.newPage();
 p.on('pageerror',e=>results.consoleErrors.push(e.message));
 p.on('console',m=>{if(m.type()==='error')results.consoleErrors.push(m.text());});
 p.on('response',r=>{if(r.status()>=400)results.failedResources.push({url:r.url(),status:r.status()});});
 async function check(group,name,fn){try{await fn();results[group].push({name,pass:true});}catch(e){results[group].push({name,pass:false,error:e.message});results.failures.push(name+': '+e.message);}}
 async function go(page,query=''){await p.goto(`${base}/${page}.html${query}`,{waitUntil:'networkidle'});}
 fs.mkdirSync(path.join(output,'screenshots'),{recursive:true});
 for(const width of [1440,768,390,360])for(const lang of ['zh','en'])for(const page of ['index','skills','skill','dashboard','admin']){
  await check('matrix',`${page}/${lang}/${width}`,async()=>{
   await p.setViewportSize({width,height:width<768?844:1000});await go(page,`?lang=${lang}`);
   assert.equal(await p.locator('html').getAttribute('lang'),lang==='zh'?'zh-CN':'en');
   assert.equal(await p.locator('h1:visible').count(),1,'one visible H1');
   const robots=(await p.locator('meta[name=robots]').getAttribute('content')).split(',').map(s=>s.trim());assert(robots.includes('noindex')&&robots.includes('nofollow'));
   assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'no page horizontal overflow');
   const dup=await p.evaluate(()=>{const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);return ids.length-new Set(ids).size;});assert.equal(dup,0,'unique ids');
   if(width===1440&&lang==='zh'||width===390&&lang==='en'||width===1440&&lang==='en'&&page==='index')await p.screenshot({path:path.join(output,'screenshots',`${page==='index'?'home':page}-${width===1440?'desktop':'mobile'}-${lang}.png`),fullPage:true});
  });
 }
 await p.setViewportSize({width:1440,height:1000});
 for(const page of ['index','skills','skill','dashboard','admin'])for(const state of ['loading','empty','error','missing']){
  await check('states',`${page}/${state}`,async()=>{await go(page,`?lang=en&state=${state}`);assert.equal(await p.locator('#prototype-state').inputValue(),state);const body=await p.locator('body').innerText();const words={loading:/loading/i,empty:/nothing|no pending|no matching|no results|no items/i,error:/couldn.t|failed|unavailable|unable/i,missing:/translation|language/i};assert(words[state].test(body),'state feedback visible');assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 }
 const test=(name,fn)=>check('interactions',name,fn);
 await test('Home search passes keyword and locale',async()=>{await go('index','?lang=en');await p.locator('#home-q').fill('research');await p.locator('form[data-search-form] button').click();await p.waitForURL('**/skills.html?**');assert.equal(new URL(p.url()).searchParams.get('q'),'research');assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),1);});
 await test('Category links populate controls',async()=>{await go('skills','?lang=en&category=design');assert(await p.locator('[name=category][value=design]').isChecked());assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),2);});
 await test('Category/platform/tag combined filter',async()=>{await go('skills','?lang=en');await p.locator('[name=category][value=development]').check();await p.locator('[name=platform][value=claude]').check();await p.locator('[name=tag]').selectOption('docs');assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),6,'unapplied values retain results');await p.locator('#apply-filters').click();assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),1);assert.equal(new URL(p.url()).searchParams.get('platform'),'claude');});
 await test('No results and reset',async()=>{await p.locator('#catalog-query').fill('nonexistent987');await p.locator('#apply-filters').click();assert(await p.locator('#catalog-empty').isVisible());await p.locator('#catalog-empty [data-reset-filters]').click();assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),6);});
 await test('Pagination persists URL',async()=>{await p.locator('#catalog-pagination button').nth(1).click();assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),3);assert.equal(new URL(p.url()).searchParams.get('pg'),'2');await p.reload({waitUntil:'networkidle'});assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),3);});
 await test('Alphabetical sort',async()=>{await p.locator('#catalog-sort').selectOption('title');assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').first().getAttribute('data-name'),'API Blueprint');});
 await test('Favorite toggles in memory only',async()=>{const f=p.locator('#catalog-grid [data-catalog-card]:visible [data-favorite]').first();await f.click();assert.equal(await f.getAttribute('aria-pressed'),'true');assert(await p.locator('#design-toast').isVisible());await p.reload({waitUntil:'networkidle'});assert.equal(await p.locator('[data-favorite]').first().getAttribute('aria-pressed'),'false');assert.equal(await p.evaluate(()=>localStorage.length+sessionStorage.length),0);});
 await test('Detail review modal and Escape focus',async()=>{await go('skill','?lang=en');await p.locator('[data-open-dialog]').click();assert(await p.locator('#review-dialog').isVisible());await p.keyboard.press('Escape');assert(!(await p.locator('#review-dialog').isVisible()));assert(await p.locator('[data-open-dialog]').evaluate(el=>el===document.activeElement));});
 await test('Required review fields and pending demo feedback',async()=>{await p.locator('[data-open-dialog]').click();await p.locator('#review-dialog button[type=submit]').click();assert(await p.locator('#review-dialog').isVisible());await p.locator('.star-input label').nth(3).click();await p.locator('#review-body').fill('Example experience for interface review only.');await p.locator('#review-dialog button[type=submit]').click();assert(!(await p.locator('#review-dialog').isVisible()));assert((await p.locator('#design-toast').innerText()).includes('No real'));});
 await test('Missing translation hides sample content and offers real prototype fallback',async()=>{await go('skill','?lang=en&state=missing');assert(!(await p.locator('[data-state-region]').isVisible()));await p.getByRole('link',{name:'Read Chinese example',exact:true}).click();assert.equal(await p.locator('html').getAttribute('lang'),'zh-CN');});
 await test('Mobile navigation toggles and Escape restores focus',async()=>{await p.setViewportSize({width:390,height:844});await go('index','?lang=en');await p.locator('.mobile-menu summary').click();assert(await p.locator('.mobile-menu').evaluate(el=>el.open));await p.keyboard.press('Escape');assert(!(await p.locator('.mobile-menu').evaluate(el=>el.open)));assert(await p.locator('.mobile-menu summary').evaluate(el=>el===document.activeElement));});
 await test('Mobile filter is inline collapsed disclosure',async()=>{await go('skills','?lang=en');assert.equal(await p.locator('#catalog-query').evaluate(el=>getComputedStyle(el).fontSize),'16px');assert(!(await p.locator('#filter-disclosure').evaluate(el=>el.open)));await p.locator('#filter-disclosure summary').click();await p.locator('[name=category][value=research]').check();await p.locator('#apply-filters').click();assert.equal(await p.locator('#catalog-grid [data-catalog-card]:visible').count(),2);});
 await test('Dashboard account notice is demo only',async()=>{await go('dashboard','?lang=en');await p.locator('[data-demo-action]:visible').first().click();assert(await p.locator('#design-toast').isVisible());assert.equal((await context.cookies()).length,0);});
 await test('Preview switches real iframe width and language',async()=>{await p.setViewportSize({width:1440,height:1000});await go('preview');await p.locator('[name=page]').selectOption('skill');await p.locator('[name=width]').selectOption('390');await p.locator('[name=lang]').selectOption('en');const f=p.frameLocator('#design-frame');await f.locator('html[lang=en]').waitFor();assert.equal(await f.locator('body').evaluate(()=>innerWidth),390);assert(!(await f.locator('#prototype-toolbar').isVisible()));});
 await test('Reduced motion disables skeleton animation',async()=>{await p.emulateMedia({reducedMotion:'reduce'});await go('index','?state=loading');assert.equal(await p.locator('.skeleton').first().evaluate(el=>getComputedStyle(el).animationName),'none');await p.emulateMedia({reducedMotion:'no-preference'});});
 await test('320px reflow sample',async()=>{await p.setViewportSize({width:320,height:900});await go('skill','?lang=en');assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 await test('No JavaScript/console errors or failed/external requests',async()=>{assert.deepEqual(results.consoleErrors,[]);assert.deepEqual(results.failedResources,[]);assert.deepEqual(results.externalRequests,[]);});
 results.passed=[...results.matrix,...results.states,...results.interactions].filter(r=>r.pass).length;
 results.failed=results.failures.length;
 fs.writeFileSync(path.join(output,'preview-checks.json'),JSON.stringify(results,null,2)+'\n');
 console.log(JSON.stringify({browser:results.browser,passed:results.passed,failed:results.failed,failures:results.failures},null,2));
 await browser.close();if(results.failed)process.exitCode=1;
})().catch(e=>{console.error(e);process.exit(1)});
