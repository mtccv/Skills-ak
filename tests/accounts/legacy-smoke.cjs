const {chromium}=require('playwright');
const fs=require('node:fs');
const crypto=require('node:crypto');
const base='http://127.0.0.1:18083';
const checks=[];
function check(id,ok){checks.push({id,status:ok?'passed':'failed'});if(!ok)throw new Error(id);}
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try {
  const context=await browser.newContext();
  for(const [route,status] of [['/zh/',200],['/en/',200],['/en/skills/',200],['/zh/search/?q=QA',200],['/en/tutorials/',200],['/zh/contact/',200],['/aish-sitemap.xml',200],['/robots.txt',200],['/en/not-a-real-route/',404]]){
   const r=await context.request.get(base+route);const b=await r.text();check('public:'+route,r.status()===status&&!/Fatal error|PHP Warning|Uncaught Error/.test(b));
   if(route==='/aish-sitemap.xml')check('accounts-excluded-from-sitemap',!b.includes('/account/')&&!b.includes('/register/')&&!b.includes('/verify-email/'));
  }
  const credentials=JSON.parse(fs.readFileSync('/tmp/aish-final-qa/browser-credentials.json','utf8'));
  const admin=await context.newPage();await admin.goto(base+'/wp-login.php');
  await admin.locator('#user_login').fill(credentials.username);await admin.locator('#user_pass').fill(credentials.password);
  await Promise.all([admin.waitForNavigation({waitUntil:'networkidle'}),admin.locator('#wp-submit').click()]);
  check('legacy-admin-native-login',admin.url().includes('/wp-admin/')&&await admin.locator('#adminmenu').count()===1);
  const backend=await admin.goto(base+'/wp-admin/admin.php?page=aish',{waitUntil:'networkidle'});
  check('legacy-admin-hub-dashboard',backend.status()===200&&await admin.locator('#adminmenu').count()===1&&!/Fatal error|PHP Warning/.test(await admin.locator('body').innerText()));
  await context.close();
  const pending=await browser.newContext();const page=await pending.newPage();
  const email='p8-browser-'+crypto.randomBytes(8).toString('hex')+'-en@example.invalid',password='Pending-QA-'+crypto.randomBytes(16).toString('hex');
  await page.goto(base+'/en/register/');await page.locator('[name=email]').fill(email);await page.locator('[name=password]').fill(password);await page.locator('[name=display_name]').fill('Pending account QA');await page.locator('[name=terms]').check();
  await Promise.all([page.waitForNavigation(),page.locator('button[type=submit]').click()]);
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(email);await page.locator('#user_pass').fill(password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
  await page.goto(base+'/wp-admin/index.php');check('pending-native-admin-verification-only',page.url().includes('/verify-email/')&&await page.locator('#adminmenu').count()===0);
  await page.goto(base+'/wp-admin/profile.php');check('pending-native-profile-verification-only',page.url().includes('/verify-email/')&&await page.locator('#your-profile').count()===0);
  await page.goto(base+'/en/account/profile/');check('pending-private-center-verification-only',page.url().includes('/verify-email/'));
  await pending.close();
 }catch(e){checks.push({id:'unexpected-interruption',status:'failed',reason:String(e.message).split('\n')[0].replace(/\?\S+/g,'?[redacted]')});}
 finally{await browser.close();}
 const failed=checks.filter(c=>c.status==='failed').length;const report={environment:'Real WordPress 7.1.4/PHP8.3/MySQL8, Chromium; Mock mail transport',checks,passed:checks.length-failed,failed};
 fs.writeFileSync('tests/accounts/results/legacy-smoke.json',JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report,null,2));process.exit(failed?1:0);
})();
