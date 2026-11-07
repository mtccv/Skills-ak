import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const source=await readFile(new URL('../../wp-content/themes/ai-skills-hub/assets/src/skill-experience.js',import.meta.url),'utf8');
let count=0;const check=(ok,why)=>{count++;assert.ok(ok,why);};
function fixture({input=false,secure=true,clipboard='success',share='success',url='https://hub.test/en/skills/41/example/',foreignTarget=false,ready='complete',delay=false}={}){
 const copies=[],shares=[],events={};let resolve;const details={open:false},pre={focused:false,focus(){this.focused=true;}},status={textContent:''};
 class HTMLInputElement {constructor(value){this.value=value;this.focused=false;this.selected=false;}focus(){this.focused=true;}select(){this.selected=true;}closest(q){return q==='details'?details:null;}}
 const text='echo "<literal>"\ncommand --full-value $(not-executed)';const target=input?new HTMLInputElement(url):{textContent:text,closest:q=>q==='pre'?pre:null};
 const button={hidden:true,disabled:false,dataset:{copyTarget:'target'},events:{},addEventListener(name,fn){this.events[name]=fn;}};
 const shareButton={hidden:true,disabled:false,events:{},addEventListener(name,fn){this.events[name]=fn;}};
 const root={dataset:{copySuccess:'Copied complete text.',copyFailure:'Select complete text manually.',shareFailure:'Sharing did not finish.',shareTitle:'Current language title',shareUrl:url},contains:t=>t===target&&!foreignTarget,querySelector:q=>q.includes('status')?status:shareButton,querySelectorAll:()=>[button]};
 const selection={cleared:false,range:null,removeAllRanges(){this.cleared=true;},addRange(range){this.range=range;}};
 const navigator={};if(clipboard!=='missing')navigator.clipboard={writeText:async value=>{copies.push(value);if(delay)await new Promise(done=>{resolve=done;});if(clipboard==='failure')throw Error('private clipboard error');}};
 if(share!=='missing')navigator.share=async payload=>{shares.push(payload);if(share==='cancel')throw Object.assign(Error('cancel'),{name:'AbortError'});if(share==='failure')throw Error('private share error');};
 const document={readyState:ready,getElementById:()=>target,querySelectorAll:()=>[root],addEventListener(name,fn){events[name]=fn;},createRange:()=>({selected:null,selectNodeContents(t){this.selected=t;}})};
 const context={document,navigator,window:{isSecureContext:secure,getSelection:()=>selection},HTMLInputElement,URL};vm.runInNewContext(source,context);
 return{root,target,button,shareButton,status,copies,shares,selection,details,pre,events,text,resolve:()=>resolve?.(),remount:()=>vm.runInNewContext('mountSkillExperiences()',context)};
}
let f=fixture();check(!f.button.hidden&&!f.shareButton.hidden,'enhanced controls appear after safe mounting');await f.button.events.click();check(f.copies[0]===f.text,'full visible multiline text copied without executing or rewriting');check(f.status.textContent==='Copied complete text.'&&!f.button.disabled,'success announced only after fulfilled clipboard promise');check(f.shares.length===0,'copy does not trigger sharing');
f=fixture({input:true});await f.button.events.click();check(f.copies[0]===f.target.value,'URL input copies exactly canonical value');await f.shareButton.events.click();check(f.shares.length===1&&f.shares[0].url===f.target.value&&Object.keys(f.shares[0]).join(',')==='title,url','native share only title and canonical URL');
for(const options of [{clipboard:'missing'},{clipboard:'failure'},{secure:false}]){f=fixture(options);await f.button.events.click();check(f.selection.range?.selected===f.target&&f.pre.focused,'code fallback selects entire visible block and keyboard focus');check(f.status.textContent==='Select complete text manually.'&&!f.button.disabled,'failure feedback honest and retryable');check(!f.status.textContent.includes('private'),'internal browser errors never rendered');}
f=fixture({input:true,clipboard:'failure'});await f.button.events.click();check(f.details.open&&f.target.focused&&f.target.selected,'URL fallback opens collapsed links and selects whole URL');
f=fixture({foreignTarget:true});check(f.button.hidden&&!f.button.events.click,'copy target outside its public projection is rejected');
f=fixture({delay:true});const pending=f.button.events.click();await f.button.events.click();check(f.copies.length===1&&f.button.disabled&&f.status.textContent==='','pending clipboard not duplicated or falsely successful');f.resolve();await pending;check(!f.button.disabled&&f.status.textContent==='Copied complete text.','clipboard completion recovers controls');
f=fixture({share:'missing'});check(f.shareButton.hidden,'unsupported native share remains hidden while link/copy fallbacks work');
f=fixture({share:'failure'});await f.shareButton.events.click();check(f.status.textContent==='Sharing did not finish.'&&!f.shareButton.disabled,'share failure reported without provider details');
f=fixture({share:'cancel'});await f.shareButton.events.click();check(f.status.textContent===''&&!f.shareButton.disabled,'user cancellation leaves controls usable without false failure');
for(const url of ['javascript:alert(1)','https://hub.test/en/?preview=private','https://hub.test/en/#private','https://secret@hub.test/','not a URL']){f=fixture({url});check(f.shareButton.hidden&&!f.shareButton.events.click,'unsafe/private native share payload blocked');}
f=fixture({ready:'loading'});check(f.button.hidden&&typeof f.events.DOMContentLoaded==='function','DOM loading defers binding');f.events.DOMContentLoaded();check(!f.button.hidden,'ready event mounts controls');const click=f.button.events.click;f.remount();check(f.button.events.click===click,'repeat mount is idempotent');
check(!/\b(?:fetch|XMLHttpRequest|eval|execCommand)\s*\(/.test(source),'no request, script execution or legacy paste execution sink');
console.log(`Skill experience browser logic: ${count} isolated DOM/clipboard assertions passed; no real browser assertions.`);
