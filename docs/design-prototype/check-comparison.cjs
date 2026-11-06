/* Phase 7.5 design-only screenshot evidence. No WordPress, database or API calls.
 * First serve the original git-archive at 127.0.0.1:8766 and current prototype at
 * 127.0.0.1:8765. Run `node check-comparison.cjs before`, then `after`, then `pairs`.
 * Captures original PNGs without cropping, compositing or image post-processing.
 */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const stage = process.argv[2];
assert(['before','after','pairs'].includes(stage), 'Choose before, after, or pairs');
const baselineCommit = '04a8190fd7938749efbb142430c6e5ff23cb38c5';
const output = path.join(__dirname, 'comparison');
const pages = [ ['home','index'], ['skills','skills'], ['skill','skill'], ['dashboard','dashboard'], ['admin','admin'] ];
const viewports = [ {name:'desktop', width:1440, height:1000, lang:'zh'}, {name:'mobile', width:390, height:844, lang:'en'} ];
const base = stage === 'before' ? process.env.AISH_DESIGN_BEFORE_URL || 'http://127.0.0.1:8766' : process.env.AISH_DESIGN_URL || 'http://127.0.0.1:8765';
const origin = new URL(base).origin;
assert(new URL(base).protocol === 'http:' && ['localhost','127.0.0.1'].includes(new URL(base).hostname), 'Only local static servers are permitted');
const sourceRoot = stage === 'before' ? process.env.AISH_DESIGN_BEFORE_DIR || '/tmp/aish-design-before/docs/design-prototype' : __dirname;
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sourceFiles = ['index.html','skills.html','skill.html','dashboard.html','admin.html','assets/prototype.css','assets/prototype.js','assets/admin-prototype.css','assets/admin-prototype.js'];
(async()=>{
 fs.mkdirSync(output, {recursive:true});
 const browser=await chromium.launch({executablePath:process.env.AISH_CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const result={scope:'Static design screenshots only. All content and numbers are demonstration data.', stage, baselineCommit, browser:browser.version(), capturedAt:new Date().toISOString(), deviceScaleFactor:1, query:'lang=zh|en&state=default&embed=1', toolbarNote:'embed=1 is the existing prototype preview option. Only the design toolbar is hidden; no CSS or DOM is injected into product pages.', captures:[], errors:[], externalRequests:[], failedResources:[]};
 if(stage!=='pairs')result.sourceHashes=Object.fromEntries(sourceFiles.map(f=>[f,hash(fs.readFileSync(path.join(sourceRoot,f)))]));
 const context=await browser.newContext({deviceScaleFactor:1,reducedMotion:'reduce'});
 await context.route('**/*',route=>{const u=new URL(route.request().url());if(u.origin!==origin){result.externalRequests.push(u.origin+u.pathname);return route.abort();}return route.continue();});
 const page=await context.newPage();
 page.on('pageerror',e=>result.errors.push(e.message));
 page.on('console',m=>{if(m.type()==='error')result.errors.push(m.text());});
 page.on('response',r=>{if(r.status()>=400)result.failedResources.push({url:r.url(),status:r.status()});});
 for(const [name,filename] of pages)for(const viewport of viewports){
  if(stage==='pairs'){
   await page.setViewportSize({width:viewport.name==='desktop'?1520:870,height:viewport.name==='desktop'?650:1050});
   await page.goto(`${base}/comparison/index.html?page=${name}&device=${viewport.name}&capture=1`,{waitUntil:'networkidle'});
   await page.locator('#comparison-board img').evaluateAll(imgs=>Promise.all(imgs.map(img=>img.decode())));
   const target=path.join(output,`${name}-${viewport.name}-comparison.png`);
   await page.locator('#comparison-board').screenshot({path:target});
   result.captures.push({page:name,device:viewport.name,file:path.basename(target),sha256:hash(fs.readFileSync(target))});
   continue;
  }
  await page.setViewportSize({width:viewport.width,height:viewport.height});
  const url=`${base}/${filename}.html?lang=${viewport.lang}&state=default&embed=1`;
  await page.goto(url,{waitUntil:'networkidle'});
  await page.evaluate(()=>document.fonts.ready);
  assert.equal(await page.locator('h1:visible').count(),1,`${name}: one visible H1`);
  assert.equal(await page.locator('html').getAttribute('lang'),viewport.lang==='zh'?'zh-CN':'en');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name}/${viewport.name}: no page overflow`);
  const measures=await page.evaluate(()=>({documentHeight:document.documentElement.scrollHeight, viewportHeight:innerHeight, headerHeight:document.querySelector('.site-header')?.getBoundingClientRect().height??null, firstSkillTop:document.querySelector('.skill-card')?.getBoundingClientRect().top??null, searchTop:document.querySelector('#home-q, #catalog-query')?.getBoundingClientRect().top??null, heroHeight:document.querySelector('[data-home-module=hero]')?.getBoundingClientRect().height??null}));
  const files={};
  for(const [label,fullPage] of [['viewport',false],['full',true]]){
   const file=`${name}-${viewport.name}-${stage}-${label}.png`;
   await page.screenshot({path:path.join(output,file),fullPage});
   files[label]={file,sha256:hash(fs.readFileSync(path.join(output,file)))};
  }
  result.captures.push({page:name,device:viewport.name,language:viewport.lang,viewport:{width:viewport.width,height:viewport.height},...measures,files});
 }
 if(stage==='pairs'){
  result.viewerChecks=[];
  async function check(name,fn){try{await fn();result.viewerChecks.push({name,passed:true});}catch(e){result.viewerChecks.push({name,passed:false,error:e.message});result.errors.push(name+': '+e.message);}}
  await page.goto(`${base}/comparison/index.html`,{waitUntil:'networkidle'});
  await check('Page selector changes both screenshot sources',async()=>{await page.locator('#page-choice').selectOption('skills');assert((await page.locator('#before-image').getAttribute('src')).startsWith('skills-'));assert((await page.locator('#after-image').getAttribute('src')).startsWith('skills-'));});
  await check('Device selector uses matching image pair and URL',async()=>{await page.locator('#device-choice').selectOption('mobile');assert.equal(await page.locator('body').getAttribute('data-device'),'mobile');assert.equal(new URL(page.url()).searchParams.get('device'),'mobile');assert((await page.locator('#before-image').getAttribute('src')).includes('-mobile-'));});
  await check('Full-page images retain their natural proportions',async()=>{await page.locator('#extent-choice').selectOption('full');await page.locator('#comparison-board img').evaluateAll(imgs=>Promise.all(imgs.map(img=>img.decode())));for(const img of await page.locator('#comparison-board img').all()){const m=await img.evaluate(el=>({w:el.getBoundingClientRect().width,h:el.getBoundingClientRect().height,nw:el.naturalWidth,nh:el.naturalHeight}));assert.equal(m.nw,390);assert(m.nh>844);assert(Math.abs(m.h-m.w*m.nh/m.nw)<1);}});
  await check('Reload preserves page, device and full-page selection',async()=>{await page.reload({waitUntil:'networkidle'});assert.equal(await page.locator('#page-choice').inputValue(),'skills');assert.equal(await page.locator('#device-choice').inputValue(),'mobile');assert.equal(await page.locator('#extent-choice').inputValue(),'full');});
  await check('Keyboard can move between native selection controls',async()=>{await page.locator('#page-choice').focus();await page.keyboard.press('Tab');assert(await page.locator('#device-choice').evaluate(el=>el===document.activeElement));});
  for(const width of [390,768,1440])await check(`Comparison viewer has no horizontal overflow at ${width}px`,async()=>{await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));});
 }
 result.captureCount=result.captures.length;
 result.passed=result.errors.length===0&&result.externalRequests.length===0&&result.failedResources.length===0;
 fs.writeFileSync(path.join(output,`${stage}-capture.json`),JSON.stringify(result,null,2)+'\n');
 console.log(JSON.stringify({stage,browser:result.browser,captures:result.captureCount,passed:result.passed,errors:result.errors,externalRequests:result.externalRequests,failedResources:result.failedResources},null,2));
 await browser.close();if(!result.passed)process.exitCode=1;
})().catch(error=>{console.error(error);process.exit(1);});
