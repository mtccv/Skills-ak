/* Real Chromium + real WordPress/MySQL; all wp_mail transport is intercepted by the QA MU fixture. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const base = process.env.AISH_PHASE8_URL || 'http://127.0.0.1:18083';
if (base !== 'http://127.0.0.1:18083') throw new Error('Only the isolated Phase 8 QA site is allowed.');
const output = process.env.AISH_PHASE8_RESULTS || '/tmp/aish-final-qa/phase8-browser';
fs.mkdirSync(output, {recursive:true, mode:0o700});
const checks = [], errors = [], screenshotFiles = [];
function check(id, pass) { checks.push({id,status:pass?'passed':'failed'}); if(!pass) throw new Error(id); }
function control(email,mode='state') {
  return JSON.parse(execFileSync('docker',['exec','-i','-e','AISH_PHASE8_QA=1','aishqa-browser','php','/repo/tests/accounts/browser-control.php'],{input:JSON.stringify({email,mode}),encoding:'utf8'}));
}
function form(page,action) { return page.locator(`form:visible:has(input[name="account_action"][value="${action}"])`); }
async function submit(page,action) { const [response]=await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}),form(page,action).locator('button[type="submit"]').click()]); if(response && response.status()>=400) throw new Error(`submit ${action}: HTTP ${response.status()} ${await page.locator('#account-feedback').innerText().catch(()=>'')}`); }
function safeError(e) { return String(e.message || e).split('\n')[0].replace(/\?\S+/g,'?[redacted]').slice(0,200); }

(async()=>{
  const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    for (const lang of ['en','zh']) {
      const context=await browser.newContext({viewport:{width:1440,height:1100}});
      const page=await context.newPage(); page.on('pageerror',e=>errors.push({kind:'pageerror',message:safeError(e)}));
      page.on('console',m=>{if(m.type()==='error') errors.push({kind:'console',message:m.text().replace(/\?\S+/g,'?[redacted]').slice(0,150)});});
      const email=`p8-browser-${crypto.randomBytes(8).toString('hex')}-${lang}@example.invalid`;
      const password='Browser-QA-\\'+crypto.randomBytes(16).toString('hex');
      try {
        let response=await page.goto(`${base}/${lang}/register/`,{waitUntil:'networkidle'});
        check(`${lang}:register-http-200`,response.status()===200);
        check(`${lang}:private-cache-and-robots`,response.headers()['cache-control'].includes('no-store') && response.headers()['x-robots-tag'].includes('noindex'));
        check(`${lang}:one-title-one-h1`,await page.locator('title').count()===1 && await page.locator('h1').count()===1);
        check(`${lang}:no-public-seo-identities`,await page.locator('link[rel="canonical"],link[rel="alternate"][hreflang],script[type="application/ld+json"]').count()===0);
        for(const width of [320,1440]) { await page.setViewportSize({width,height:1000}); check(`${lang}:registration-no-overflow-${width}`,await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)); const file=`register-${lang}-${width}.png`; await page.screenshot({path:path.join(output,file),fullPage:true}); screenshotFiles.push(file); }
        await form(page,'register').locator('[name=email]').fill(email);
        await form(page,'register').locator('[name=password]').fill(password);
        await form(page,'register').locator('[name=display_name]').fill(lang==='zh'?'账户浏览器验证':'Account browser QA');
        await form(page,'register').locator('[name=terms]').check(); await submit(page,'register');
        const pending=control(email,'verification'); check(`${lang}:registration-native-pending`,pending.exists && pending.state==='pending_email' && pending.roles.join(',')==='subscriber');
        const token=pending.payload.token;
        await page.goto(`${base}/${lang}/verify-email/?token=${token}`,{waitUntil:'networkidle'});
        check(`${lang}:verification-url-cleans-token`,page.url()===`${base}/${lang}/verify-email/`);
        check(`${lang}:get-does-not-consume-token`,!control(email).verified);
        check(`${lang}:token-not-rendered`,!(await page.content()).includes(token));
        const cookies=await context.cookies(); check(`${lang}:credential-cookie-http-only`,cookies.some(c=>c.name.includes('verify') && c.httpOnly && c.sameSite==='Lax'));
        await submit(page,'verify'); check(`${lang}:explicit-verification-activates`,control(email).verified);
        await page.goto(`${base}/${lang}/login/`,{waitUntil:'networkidle'});
        await form(page,'login').locator('[name=email]').fill(email); await form(page,'login').locator('[name=password]').fill(password); await submit(page,'login');
        check(`${lang}:native-session-dashboard`,page.url()===`${base}/${lang}/account/`);
        check(`${lang}:no-demo-or-future-controls`,!(await page.locator('body').innerText()).match(/Alex|Phase\s?[89]|Example account|示例账户/));
        for (const width of [320,768,1440]) {
          await page.setViewportSize({width,height:1000});
          check(`${lang}:dashboard-no-overflow-${width}`,await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
          const file=`dashboard-${lang}-${width}.png`; await page.screenshot({path:path.join(output,file),fullPage:true}); screenshotFiles.push(file);
        }
        await page.setViewportSize({width:1440,height:1100});
        await page.goto(`${base}/${lang}/account/profile/`,{waitUntil:'networkidle'});
        const display=lang==='zh'?'真实私有测试资料':'Private profile QA';
        await form(page,'profile').locator('[name=display_name]').fill(display);
        await form(page,'profile').locator('[name=description]').fill('Private account biography for isolated QA.');
        await form(page,'profile').locator('[name=url]').fill('https://example.com/'); await submit(page,'profile');
        check(`${lang}:profile-persists-own-native-user`,control(email).display_name===display);
        const png=Buffer.from(await page.evaluate(()=>{const c=document.createElement('canvas');c.width=80;c.height=80;const g=c.getContext('2d');g.fillStyle='#AD5E48';g.fillRect(0,0,80,80);return c.toDataURL('image/png').split(',')[1];}),'base64');
        await form(page,'avatar_upload').locator('[name=avatar]').setInputFiles({name:'avatar.png',mimeType:'image/png',buffer:png}); await submit(page,'avatar_upload');
        const avatar=control(email); check(`${lang}:avatar-private-attachment-created`,avatar.avatar>0);
        const imageResponse=await context.request.get(`${base}/wp-admin/admin-post.php?action=aish_account_avatar`);
        check(`${lang}:avatar-owner-image`,imageResponse.status()===200 && imageResponse.headers()['content-type'].includes('image/png') && imageResponse.headers()['cache-control'].includes('no-store'));
        const anonymous=await browser.newContext(); const anon=anonymous.request;
        const forbiddenImage=await anon.get(`${base}/wp-admin/admin-post.php?action=aish_account_avatar&user_id=${avatar.id}`);
        check(`${lang}:avatar-anonymous-denied`,forbiddenImage.status()===404);
        for(const suffix of [`/?author=${avatar.id}`,`/author/${avatar.user_nicename}/`,`/wp-json/wp/v2/users/${avatar.id}`,`/wp-json/wp/v2/users?include=${avatar.id}`,`/wp-json/wp/v2/media/${avatar.avatar}`]) {
          const res=await anon.get(base+suffix); const body=await res.text();
          check(`${lang}:no-private-profile-${suffix.split('?')[0].replace(/\d+/g,'ID')}`,!body.includes(display) && !body.includes(email) && !body.includes('Private account biography for isolated QA.'));
        }
        await anonymous.close();
        await page.goto(`${base}/${lang}/account/privacy/`,{waitUntil:'networkidle'});
        await form(page,'export').locator('[name=current_password]').fill(password); await submit(page,'export');
        check(`${lang}:privacy-native-request-visible`,(await page.locator('body').innerText()).includes(lang==='zh'?'已确认':'Confirmed'));
        await page.goto(`${base}/${lang}/account/security/`,{waitUntil:'networkidle'});
        check(`${lang}:password-email-session-forms`,await form(page,'password').count()===1 && await form(page,'email_change').count()===1 && await form(page,'sessions').count()===1);
        await submit(page,'logout');
        await page.goto(`${base}/${lang}/account/`,{waitUntil:'networkidle'}); check(`${lang}:logout-revokes-private-access`,page.url().includes('/login/'));
        await page.goto(`${base}/${lang}/forgot-password/`,{waitUntil:'networkidle'});
        await form(page,'forgot').locator('[name=email]').fill(email); await submit(page,'forgot');
        const reset=control(email,'reset').payload;
        await page.goto(`${base}/${lang}/reset-password/?key=${reset.key}&login=${reset.login}`,{waitUntil:'networkidle'});
        check(`${lang}:reset-url-cleans-native-key`,page.url()===`${base}/${lang}/reset-password/` && !(await page.content()).includes(reset.key));
        const replacement=password+'-new'; await form(page,'reset').locator('[name=new_password]').fill(replacement); await form(page,'reset').locator('[name=confirm_password]').fill(replacement); await submit(page,'reset');
        check(`${lang}:native-password-reset-completes`,page.url()===`${base}/${lang}/login/`);
        await page.goto(`${base}/${lang}/login/`,{waitUntil:'networkidle'}); await form(page,'login').locator('[name=email]').fill(email); await form(page,'login').locator('[name=password]').fill(replacement); await submit(page,'login');
        check(`${lang}:native-password-reset-login`,page.url()===`${base}/${lang}/account/`);
      } catch(e) { errors.push({kind:'workflow',lang,message:safeError(e)}); }
      await context.close();
    }
    // A stolen generic WordPress guest nonce is insufficient without the matching browser-bound token.
    const first=await browser.newContext(),second=await browser.newContext(); const page=await first.newPage();
    await page.goto(`${base}/en/login/`,{waitUntil:'networkidle'});
    const payload=await form(page,'login').evaluate(el=>Object.fromEntries(new FormData(el)));
    payload.email='nobody@example.invalid';payload.password='Not-an-actual-password';
    let result=await second.request.post(`${base}/en/login/`,{form:payload,headers:{Origin:base}});
    check('cross-browser-guest-csrf-rejected',result.status()===403);
    result=await first.request.post(`${base}/en/login/`,{form:payload,headers:{Origin:'https://untrusted.example'}});
    check('cross-origin-post-rejected',result.status()===403);
    payload._aish_nonce='invalid'; result=await first.request.post(`${base}/en/login/`,{form:payload,headers:{Origin:base}});
    check('invalid-wordpress-nonce-rejected',result.status()===403);
    await first.close();await second.close();
    const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:900}});const nopage=await nojs.newPage();
    await nopage.goto(`${base}/zh/login/`);check('no-javascript-login-form-available',await form(nopage,'login').count()===1);
    check('no-javascript-no-overflow',await nopage.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));await nojs.close();
  } catch(e) {errors.push({kind:'suite',message:safeError(e)});}
  finally{await browser.close();}
  const report={browser:'Chromium 155, Linux headless',environment:'Real WordPress 7.1.4 / PHP 8.3.36 / MySQL 8.0.46; Mock mail transport',checks,passed:checks.filter(x=>x.status==='passed').length,failed:checks.filter(x=>x.status==='failed').length,errors,screenshotFiles};
  fs.writeFileSync(path.join(output,'browser.json'),JSON.stringify(report,null,2));
  console.log(JSON.stringify({passed:report.passed,failed:report.failed,errors,screenshotFiles},null,2));process.exit(report.failed || errors.length?1:0);
})();
