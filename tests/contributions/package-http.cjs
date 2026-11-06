/* Read-only HTTP smoke against the actually installed final package. */
const {request}=require('playwright');const fs=require('node:fs');
(async()=>{const client=await request.newContext({baseURL:'http://127.0.0.1:18090',maxRedirects:0});const checks=[];
function check(id,pass){checks.push({id,status:pass?'passed':'failed'});}
try{for(const lang of ['en','zh']){
 let r=await client.get(`/${lang}/`);check(`${lang}:installed-home`,r.status()===200&&!/(?:Fatal error|Parse error|PHP Warning)/.test(await r.text()));
 r=await client.get(`/${lang}/login/`);check(`${lang}:installed-native-account-login`,r.status()===200&&(await r.text()).includes('account_action'));
 for(const path of ['submit','account/submissions','account/developer']){r=await client.get(`/${lang}/${path}/`);check(`${lang}:${path}-private-login-redirect`,r.status()===302&&(r.headers()['location']||'').includes('/login/')&&(r.headers()['cache-control']||'').includes('no-store'));}
 for(const handle of ['qa-no-such-approved-profile','admin','invalid--handle']){r=await client.get(`/${lang}/developers/${handle}/`);check(`${lang}:hidden-or-invalid-profile-${handle}`,r.status()===404&&(r.headers()['x-robots-tag']||'').includes('noindex'));}
 }let r=await client.get('/wp-json/wp/v2/aish_message');check('native-inbox-has-no-public-rest-route',r.status()===404);
}finally{await client.dispose();}
const result={environment:'Actually installed ai-skills-hub-0.10.0 ZIP; local HTTP WordPress7.1.4/PHP8.4.27/MySQL8.0.46',checks,passed:checks.filter(x=>x.status==='passed').length,failed:checks.filter(x=>x.status!=='passed').length};fs.writeFileSync('tests/contributions/results/package-http.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({passed:result.passed,failed:result.failed}));process.exit(result.failed?1:0);
})().catch(()=>{console.error('Package HTTP check failed before completion.');process.exit(1);});
