/* Real Chromium + WordPress/MySQL. Mail transport remains the QA-only mock. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:18083';
const output = process.env.AISH_PHASE9_RESULTS || '/tmp/aish-final-qa/phase9-browser';
fs.mkdirSync(output, {recursive:true,mode:0o700});
const checks=[], errors=[], screenshots=[];
function check(id,pass) { checks.push({id,status:pass?'passed':'failed'}); if(!pass) throw new Error(id); }
function bridge(input) { return JSON.parse(execFileSync('docker',['exec','-i','-e','AISH_PHASE9_QA=1','aishqa-browser','php','/repo/tests/community/browser-control.php'],{input:JSON.stringify(input),encoding:'utf8'})); }
function form(page,action) { return page.locator(`form:visible:has(input[name=community_action][value="${action}"])`); }
function accountForm(page,action) { return page.locator(`form:visible:has(input[name=account_action][value="${action}"])`); }
async function submit(page,target) { const [res]=await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}),target.locator('button[type=submit]').click()]); if(res && res.status()>=400) throw new Error(`Form response HTTP ${res.status()}`); }
async function login(page,user,lang) { await page.goto(`${base}/${lang}/login/`,{waitUntil:'networkidle'}); await accountForm(page,'login').locator('[name=email]').fill(user.email); await accountForm(page,'login').locator('[name=password]').fill(user.password); await submit(page,accountForm(page,'login')); }
async function fillReview(page,lang,marker,rating) { const f=form(page,'review'); await f.locator(`[name=rating][value="${rating}"]`).check(); await f.locator('[name=title]').fill(marker); await f.locator('[name=comment]').fill(`${marker}: Browser-tested original content; <script>window.__p9xss=1</script>`); await f.locator('[name=experience]').fill(lang==='zh'?'仅用于隔离测试的使用体验。':'Experience in this isolated browser fixture.'); await f.locator('[name=pros]').fill('Explicit moderation.'); await f.locator('[name=cons]').fill('Fixture only.'); await f.locator('[name=platform]').fill('Claude Code'); await f.locator('[name=version_label]').fill('Browser v1'); await f.locator('[name=locale]').selectOption(lang==='zh'?'zh_CN':'en_US'); await submit(page,f); }
async function moderate(page,id,decision) { await page.goto(`${base}/wp-admin/admin.php?page=aish-community-reviews&id=${id}`,{waitUntil:'networkidle'}); const f=decision==='hide'?page.locator('form:has(input[name=decision][value=hide])'):page.locator('form:has(select[name=decision])'); if(decision!=='hide') await f.locator('[name=decision]').selectOption(decision); await f.locator('[name=reason]').selectOption(decision==='approve'?'approved':'other'); await f.locator('[name=private_note]').fill('PRIVATE_BROWSER_MODERATION_NOTE'); await f.locator('[name=confirmed]').check(); await submit(page,f); }
async function responsive(page,name,lang) { for(const width of [320,768,1440]) { await page.setViewportSize({width,height:1000}); check(`${lang}:${name}-no-overflow-${width}`,await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)); if(width!==768) { const file=`${name}-${lang}-${width}.png`; await page.screenshot({path:path.join(output,file),fullPage:true}); screenshots.push(file); } } }
function scrub(error) { return String(error.message||error).split('\n')[0].replace(/\?\S+/g,'?[redacted]').slice(0,160); }
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try {
  for(const lang of ['en','zh']) {
   const seed=bridge({mode:'seed',lang}), contexts=[];
   const context=await browser.newContext({viewport:{width:1440,height:1000}}); contexts.push(context);
   const page=await context.newPage(); page.on('pageerror',e=>errors.push({lang,kind:'pageerror',message:scrub(e)}));
   page.on('console',m=>{if(m.type()==='error') errors.push({lang,kind:'console',message:scrub(m.text())});});
   const state=(role='author',mode='state')=>bridge({mode,user:seed.users[role].id,skill:seed.skill});
   const publicUrl=`${base}/${lang}/skills/${seed.skill}/${seed.slug}/`, privateReview=`${base}/${lang}/account/review/?skill=${seed.skill}`;
   try {
    let response=await page.goto(publicUrl,{waitUntil:'networkidle'}); check(`${lang}:public-detail-and-empty-ratings`,response.status()===200 && await page.locator('#community-reviews').count()===1 && await page.locator('#community-reviews article.community-review').count()===0);
    await page.locator('.community-actions a[href*="/account/favorite/"]').click(); await page.waitForURL(/\/login\//); check(`${lang}:anonymous-login-retains-private-return`,(await context.cookies()).some(c=>c.name.endsWith('aish_account_return') && c.httpOnly && c.sameSite==='Lax'));
    await accountForm(page,'login').locator('[name=email]').fill(seed.users.author.email); await accountForm(page,'login').locator('[name=password]').fill(seed.users.author.password); await submit(page,accountForm(page,'login'));
    check(`${lang}:login-returns-favorite-intent`,page.url().includes('/account/favorite/') && page.url().includes(`skill=${seed.skill}`));
    const savedForm=await form(page,'favorite').evaluate(el=>Object.fromEntries(new FormData(el)));
    const invalid=await context.request.post(page.url(),{form:{...savedForm,_aish_nonce:'invalid'},headers:{Origin:base}}); check(`${lang}:invalid-nonce-rejected`,invalid.status()===403);
    const cross=await context.request.post(page.url(),{form:savedForm,headers:{Origin:'https://untrusted.example'}}); check(`${lang}:cross-origin-rejected`,cross.status()===403);
    await submit(page,form(page,'favorite')); check(`${lang}:favorite-persists-and-private-page`,state().favorites===1 && page.url().includes('/account/favorites/'));
    response=await page.reload({waitUntil:'networkidle'}); check(`${lang}:private-cache-robots-no-seo`,response.headers()['cache-control'].includes('no-store') && response.headers()['x-robots-tag'].includes('noindex') && await page.locator('link[rel=canonical],link[hreflang],script[type="application/ld+json"]').count()===0);
    const deniedAdmin=await context.request.get(`${base}/wp-admin/admin.php?page=aish-community-reviews`);check(`${lang}:subscriber-native-admin-denied`,deniedAdmin.status()===403);
    const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:900},storageState:await context.storageState()});const plain=await nojs.newPage();await plain.goto(`${base}/${lang}/account/favorites/`);check(`${lang}:no-javascript-private-favorites-available`,await form(plain,'favorite').count()===1 && await plain.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));await nojs.close();
    await responsive(page,'favorites',lang);
    await page.locator(`.account-language a[lang="${lang==='en'?'zh-CN':'en'}"]`).click(); check(`${lang}:favorite-language-shares-id`,await page.locator('.community-record').count()===1);
    await page.goto(`${base}/${lang}/account/favorites/`,{waitUntil:'networkidle'}); await submit(page,form(page,'favorite')); check(`${lang}:cancel-favorite-empty`,state().favorites===0 && await page.locator('.community-record').count()===0);
    await page.goto(`${base}/${lang}/account/favorite/?skill=${seed.skill}`,{waitUntil:'networkidle'}); await submit(page,form(page,'favorite'));
    await page.goto(privateReview,{waitUntil:'networkidle'}); await responsive(page,'review-form',lang); await fillReview(page,lang,`FIRST_${lang}`,4);
    let head=state().head; check(`${lang}:first-review-pending`,head && head.state==='pending' && !head.approved_revision_id);
    await page.goto(publicUrl,{waitUntil:'networkidle'}); check(`${lang}:pending-review-not-public`,await page.locator('#community-reviews article.community-review').count()===0 && !(await page.content()).includes(`FIRST_${lang}`));
    const modContext=await browser.newContext();contexts.push(modContext);const mod=await modContext.newPage();await login(mod,seed.users.moderator,lang);await moderate(mod,head.id,'approve');
    check(`${lang}:native-admin-approval`,state().head.state==='approved');
    check(`${lang}:community-moderator-no-editorial-menu`,await mod.locator('#adminmenu a[href*="page=aish-skills"]').count()===0);
    await page.goto(publicUrl,{waitUntil:'networkidle'}); check(`${lang}:approved-rating-and-sanitized-public-content`,(await page.locator('.community-average').innerText()).includes('4.0') && await page.locator('#community-reviews article.community-review').count()===1 && await page.evaluate(()=>window.__p9xss===undefined) && !(await page.content()).includes('PRIVATE_BROWSER_MODERATION_NOTE'));
    check(`${lang}:editorial-review-panel-neutral-color`,await page.locator('aside > section').last().evaluate(el=>{const s=getComputedStyle(el);return s.backgroundColor==='rgb(250, 250, 249)' && s.borderTopColor==='rgb(231, 229, 228)';}));
    await responsive(page,'skill-reviews',lang);
    response=await page.goto(`${publicUrl}?reviews_page=2`,{waitUntil:'networkidle'});check(`${lang}:review-pagination-seo-no-duplicate`,response.status()===200 && response.headers()['x-robots-tag'].includes('noindex') && await page.locator('link[rel=canonical]').count()===1 && await page.locator('link[rel=canonical]').getAttribute('href')===publicUrl && await page.locator('link[hreflang]').count()===0);
    await page.goto(privateReview,{waitUntil:'networkidle'});await fillReview(page,lang,`EDIT_${lang}`,2);await page.goto(publicUrl,{waitUntil:'networkidle'});check(`${lang}:pending-edit-keeps-old-public-score`,(await page.locator('.community-average').innerText()).includes('4.0') && (await page.content()).includes(`FIRST_${lang}`) && !(await page.content()).includes(`EDIT_${lang}`));
    await moderate(mod,head.id,'reject');await page.reload({waitUntil:'networkidle'});check(`${lang}:rejected-edit-keeps-old-public`,(await page.locator('.community-average').innerText()).includes('4.0') && (await page.content()).includes(`FIRST_${lang}`));
    const reporterContext=await browser.newContext();contexts.push(reporterContext);const reporter=await reporterContext.newPage();await login(reporter,seed.users.reporter,lang);await reporter.goto(publicUrl,{waitUntil:'networkidle'});await reporter.locator('article.community-review a[data-community-dialog]').click();await reporter.locator('#community-report-dialog[open] #community-report-form').waitFor();check(`${lang}:report-dialog-real-private-form`,await reporter.locator('#community-report-dialog[open] #community-report-form').count()===1);await reporter.locator('#community-report-form [name=reason_code]').selectOption('other');await reporter.locator('#community-report-form [name=details]').fill('PRIVATE_BROWSER_REPORT: verify this exact revision.');await submit(reporter,reporter.locator('#community-report-form'));check(`${lang}:report-submitted-without-autohide`,state('reporter').reports===1 && state().head.state==='approved');
    await reporter.goto(`${base}/${lang}/account/report-skill/?skill=${seed.skill}`,{waitUntil:'networkidle'});await form(reporter,'report_skill').locator('[name=kind]').selectOption('installation');await form(reporter,'report_skill').locator('[name=details]').fill('PRIVATE_BROWSER_CORRECTION: installation requires review.');await submit(reporter,form(reporter,'report_skill'));check(`${lang}:skill-correction-independent`,state('reporter').corrections===1);
    await reporter.goto(`${base}/${lang}/account/favorites/?user_id=${seed.users.author.id}`,{waitUntil:'networkidle'});check(`${lang}:cross-user-favorites-not-exposed`,await reporter.locator('.community-record').count()===0);
    await page.goto(publicUrl,{waitUntil:'networkidle'});check(`${lang}:reporter-and-private-notes-never-public`,!(await page.content()).includes('PRIVATE_BROWSER_') && !(await page.content()).includes(seed.users.reporter.email));
    await page.goto(privateReview,{waitUntil:'networkidle'});await form(page,'withdraw').locator('[name=confirm_withdraw]').check();await submit(page,form(page,'withdraw'));await page.goto(publicUrl,{waitUntil:'networkidle'});check(`${lang}:withdraw-removes-public-review`,state().head.state==='withdrawn' && await page.locator('#community-reviews article.community-review').count()===0);
    await page.goto(privateReview,{waitUntil:'networkidle'});await fillReview(page,lang,`RESTORED_${lang}`,5);await moderate(mod,head.id,'approve');await moderate(mod,head.id,'hide');await page.goto(privateReview,{waitUntil:'networkidle'});check(`${lang}:hidden-review-cannot-edit`,state().head.state==='hidden' && await form(page,'review').count()===0);
    state('author','archive');await page.goto(`${base}/${lang}/account/favorites/`,{waitUntil:'networkidle'});check(`${lang}:archived-favorite-placeholder`,await page.locator('.community-record').count()===1 && !(await page.locator('.community-record').innerText()).includes('Community QA browser'));
    await submit(page,form(page,'favorite'));check(`${lang}:unavailable-favorite-removable`,state().favorites===0);
    const anon=await browser.newContext();contexts.push(anon);const denied=await anon.request.get(privateReview);check(`${lang}:anonymous-private-route-redirects-login`,denied.url().includes('/login/') && !(await denied.text()).includes(`RESTORED_${lang}`));
   } catch(e) { errors.push({lang,kind:'workflow',message:scrub(e)}); }
   for(const c of contexts) await c.close();
  }
 } finally { await browser.close(); }
 const result={browser:'Chromium 155 Linux headless',environment:'Real WordPress 7.1.4 / PHP 8.3.36 / MySQL 8.0.46; QA Mock mail transport',checks,passed:checks.filter(c=>c.status==='passed').length,failed:checks.filter(c=>c.status==='failed').length,errors,screenshots};
 fs.writeFileSync(path.join(output,'browser.json'),JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({passed:result.passed,failed:result.failed,errors,screenshots},null,2));process.exit(result.failed||errors.length?1:0);
})().catch(e=>{console.error(scrub(e));process.exit(1);});
