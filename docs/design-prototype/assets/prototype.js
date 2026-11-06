/* Phase 7.5 design demo only: no API, cookies, accounts, analytics or persistence. */
(() => {
  'use strict';
  const params = new URLSearchParams(location.search);
  const locale = params.get('lang') === 'en' ? 'en' : 'zh';
  const page = document.body.dataset.page || 'home';
  const pages = {home:'index.html',skills:'skills.html',skill:'skill.html',dashboard:'dashboard.html',admin:'admin.html'};
  const t = (zh,en) => locale === 'en' ? en : zh;
  const switchURL = new URL(location.href); switchURL.searchParams.set('lang',locale==='zh'?'en':'zh');
  const paths = {
    search:'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
    arrow:'<path d="M4 12h16m-6-6 6 6-6 6"/>',
    code:'<path d="m8 7-5 5 5 5m8-10 5 5-5 5m-3-13-2 16"/>',
    spark:'<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/>',
    bookmark:'<path d="M6 4h12v17l-6-4-6 4V4Z"/>',
    check:'<path d="m5 12 4 4L19 6"/>',
    book:'<path d="M12 5v15M3 4c4-1 7 0 9 2 2-2 5-3 9-2v15c-4-1-7 0-9 2-2-2-5-3-9-2V4Z"/>',
    grid:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    bolt:'<path d="m13 2-8 12h6l-1 8 9-13h-7l1-7Z"/>',
    shield:'<path d="m12 3 8 3v5c0 5-4 8-8 10-4-2-8-5-8-10V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
    star:'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
    globe:'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
    file:'<path d="M6 3h8l4 4v14H6V3Zm8 0v5h4M9 12h6m-6 4h6"/>',
    external:'<path d="M14 3h7v7m0-7L10 14m0-10H4v16h16v-6"/>',
    chart:'<path d="M4 20V4m0 16h17M8 16v-4m5 4V7m5 9v-7"/>',
    clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    bell:'<path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5l-2 3Zm5 3h4"/>',
    user:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    filter:'<path d="M3 6h18M6 12h12M9 18h6"/>',
    close:'<path d="m6 6 12 12M6 18 18 6"/>',
    copy:'<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M15 8V3H3v13h5"/>',
    layers:'<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/>',
    menu:'<path d="M4 6h16M4 12h16M4 18h16"/>',
    heart:'<path d="M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8 6l8 8 8-8a5 5 0 0 0 0-7Z"/>'
  };
  const icon = name => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name]||paths.grid}</svg>`;
  const brand = `<a class="site-brand" href="index.html?lang=${locale}"><span class="brand-mark">${icon('layers')}</span><span>AI Skills <span class="font-normal text-muted">Hub</span></span></a>`;
  document.documentElement.lang = locale === 'en' ? 'en' : 'zh-CN';
  document.title = `${t('设计原型','Design prototype')} · ${t(({home:'首页',skills:'资源目录',skill:'Skill 详情',dashboard:'用户中心',admin:'WordPress 后台'})[page]||'预览',({home:'Home',skills:'Skills',skill:'Skill details',dashboard:'My workspace',admin:'WordPress admin'})[page]||'Preview')} · AI Skills Hub`;
  const toolbar = document.getElementById('prototype-toolbar');
  if (toolbar) {
    toolbar.className = 'prototype-bar';
    const labels = {home:t('首页','Home'),skills:t('列表','Directory'),skill:t('详情','Detail'),dashboard:t('用户中心','Account'),admin:t('后台','WP admin')};
    toolbar.innerHTML = `<div class="container-shell prototype-bar-inner"><span class="demo-pill">${t('设计原型 · 所有内容与数字均为演示','DESIGN DEMO · All content and numbers are examples')}</span><nav aria-label="${t('原型页面','Prototype pages')}">${Object.entries(pages).map(([id,url])=>`<a href="${url}?lang=${locale}" ${page===id?'aria-current="page"':''}>${labels[id]}</a>`).join('')}</nav><a href="preview.html?page=${page}&lang=${locale}">${t('设备预览','Device preview')} ↗</a><label class="inline-flex items-center gap-2">${t('状态','State')}<select id="prototype-state"><option value="default">${t('默认','Default')}</option><option value="loading">${t('加载中','Loading')}</option><option value="empty">${t('空状态','Empty')}</option><option value="error">${t('错误','Error')}</option><option value="missing">${t('缺失翻译','Missing translation')}</option></select></label><a class="ml-auto" href="${switchURL.href}" lang="${locale==='zh'?'en':'zh-CN'}">${locale==='zh'?'English':'简体中文'}</a></div>`;
    if (params.get('embed') === '1') toolbar.hidden = true;
    const state = toolbar.querySelector('select');
    state.value = ['loading','empty','error','missing'].includes(params.get('state'))?params.get('state'):'default';
    state.addEventListener('change',()=>{const u=new URL(location.href);u.searchParams.set('state',state.value);location.href=u.href;});
  }
  const navigation = `<a href="skills.html" ${['skills','skill'].includes(page)?'aria-current="page"':''}>${t('Skills 目录','Skills')}</a><a href="index.html#categories">${t('分类','Categories')}</a><a href="index.html#tutorials">${t('教程','Tutorials')}</a>`;
  const header = document.getElementById('site-header');
  if (header) header.innerHTML = `<a class="skip-link" href="#main">${t('跳到主要内容','Skip to content')}</a><header class="site-header"><div class="container-shell header-row">${brand}<nav class="site-nav" aria-label="${t('主导航','Main navigation')}">${navigation}</nav><div class="flex items-center gap-2 md:gap-3"><a class="language-link" href="${switchURL.href}" lang="${locale==='zh'?'en':'zh-CN'}" data-language-switch>${locale==='zh'?'EN':'中文'}</a><a class="btn btn-secondary hidden md:inline-flex" href="dashboard.html">${t('投稿 Skill','Submit a skill')}</a><a class="icon-btn" href="dashboard.html" aria-label="${t('个人中心（设计演示）','My account (design demo)')}">${icon('user')}</a></div></div><details class="mobile-menu"><summary class="container-shell">${t('浏览导航','Browse navigation')}${icon('menu')}</summary><nav class="container-shell" aria-label="${t('移动导航','Mobile navigation')}">${navigation}<a href="dashboard.html">${t('投稿 Skill','Submit a skill')}</a></nav></details></header>`;
  const footer = document.getElementById('site-footer');
  if (footer) footer.innerHTML = `<footer class="site-footer"><div class="container-shell footer-grid"><div>${brand}<p class="mt-4 max-w-sm text-sm leading-7 text-muted">${t('发现值得了解的 Agent Skills。读懂来源，学习用法，分享真实体验。','Discover agent skills worth understanding. Explore their source, learn how they work, and share your experience.')}</p><span class="badge badge-muted mt-5">${t('中英双语 · 免费探索','Bilingual · Free to explore')}</span></div><div><h2 class="text-sm font-semibold">${t('发现','Discover')}</h2><nav><a href="skills.html">${t('全部 Skills','All skills')}</a><a href="index.html#trending">${t('趋势资源','Trending')}</a><a href="index.html#editors">${t('编辑精选','Editor’s picks')}</a></nav></div><div><h2 class="text-sm font-semibold">${t('学习与参与','Learn & contribute')}</h2><nav><a href="index.html#tutorials">${t('安装与使用教程','Installation guides')}</a><a href="dashboard.html">${t('投稿与贡献','Contribute')}</a><a href="dashboard.html#notifications">${t('我的通知','My notifications')}</a></nav></div><div><h2 class="text-sm font-semibold">${t('关于平台','About the hub')}</h2><nav><a href="#" data-demo-action>${t('关于我们','About')}</a><a href="#" data-demo-action>${t('联系与帮助','Contact & help')}</a><a href="#" data-demo-action>${t('隐私与社区规范','Privacy & guidelines')}</a></nav></div></div><div class="container-shell flex flex-wrap justify-between gap-3 border-t border-line py-5 text-xs text-muted"><span>© 2026 AI Skills Hub</span><span>${t('Phase 7.5 设计演示，不代表已上线功能。','Phase 7.5 design demonstration, not a live product.')}</span></div></footer>`;
  function applyLanguage() {
    document.querySelectorAll('[data-i18n-zh][data-i18n-en]').forEach(el=>{el.textContent=el.getAttribute(`data-i18n-${locale}`);});
    document.querySelectorAll('[data-placeholder-zh]').forEach(el=>{el.placeholder=el.getAttribute(`data-placeholder-${locale}`)||'';});
    document.querySelectorAll('[data-label-zh]').forEach(el=>{el.setAttribute('aria-label',el.getAttribute(`data-label-${locale}`)||'');});
    document.querySelectorAll('[data-icon]').forEach(el=>{el.innerHTML=icon(el.dataset.icon);});
    document.querySelectorAll('a[href]').forEach(a=>{
      if(a.hasAttribute('data-language-switch') || a.closest('#prototype-toolbar'))return;
      const raw=a.getAttribute('href');
      if(!raw||raw.startsWith('#')||!/^([a-z-]+\.html)([?#]|$)/.test(raw))return;
      const u=new URL(raw,location.href);u.searchParams.set('lang',locale);a.href=u.href;
    });
  }
  applyLanguage();
  let toastTimer;
  function toast(message) {
    let el=document.getElementById('design-toast');
    if(!el){el=document.createElement('div');el.id='design-toast';el.className='toast';el.setAttribute('role','status');document.body.append(el);}
    el.textContent=message;el.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>{el.hidden=true;},6000);
  }
  window.AishPrototype={locale,t,toast,icon,applyLanguage};
  document.dispatchEvent(new CustomEvent('aish:language',{detail:{locale}}));
  document.addEventListener('click',event=>{
    const el=event.target.closest('[data-demo-action],[data-favorite],[data-open-dialog],[data-close-dialog],[data-copy-target]');
    if(!el)return;
    event.preventDefault();
    if(el.hasAttribute('data-favorite')) {
      const selected=el.getAttribute('aria-pressed')==='true';el.setAttribute('aria-pressed',String(!selected));
      el.classList.toggle('bg-violet-50',!selected);el.classList.toggle('text-violet-700',!selected);
      toast(t(selected?'已取消示例收藏；刷新即重置，未写入账户。':'已收藏此示例；仅当前页面内存演示。',selected?'Demo favorite removed. No account was changed.':'Demo favorite saved in this page only.'));return;
    }
    if(el.dataset.openDialog){const dialog=document.getElementById(el.dataset.openDialog);if(dialog?.showModal)dialog.showModal();return;}
    if(el.hasAttribute('data-close-dialog')){el.closest('dialog')?.close();return;}
    if(el.dataset.copyTarget){const text=document.getElementById(el.dataset.copyTarget)?.textContent||'';if(navigator.clipboard&&window.isSecureContext)navigator.clipboard.writeText(text).then(()=>toast(t('已复制示例文本；没有执行命令。','Demo text copied. Nothing was executed.'))).catch(()=>toast(t('无法自动复制，请选中文本手动复制。','Copy unavailable. Select and copy the text manually.')));else toast(t('请选中文本手动复制；没有执行命令。','Select and copy the text. Nothing is executed.'));return;}
    toast(t('设计演示：此入口的完整流程已在规范中规划，未提交或发送任何数据。','Design demo: this flow is specified in the design documents. No data was submitted or sent.'));
  });
  document.querySelectorAll('form[data-demo-form]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();if(!form.reportValidity())return;form.closest('dialog')?.close();toast(t('示例已进入待审核状态演示。未创建真实投稿或评价。','Demo moved to a pending-review illustration. No real review or submission was created.'));}));
  document.querySelectorAll('form[data-search-form]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();const q=new FormData(form).get('q')||'';location.href=`skills.html?lang=${locale}&q=${encodeURIComponent(q)}`;}));
  document.querySelectorAll('.mobile-menu, .account-mobile-nav').forEach(details=>details.addEventListener('keydown',e=>{if(e.key==='Escape'){details.open=false;details.querySelector('summary').focus();}}));
  const filter=document.getElementById('filter-disclosure');
  if(filter&&innerWidth<1024)filter.open=false;
  const query=document.getElementById('catalog-query');
  if(query){
    query.value=params.get('q')||'';
    const form=document.getElementById('catalog-filter');
    const sort=document.getElementById('catalog-sort');
    const cards=[...document.querySelectorAll('[data-catalog-card]')];
    for(const name of ['category','platform'])form.querySelectorAll(`[name=${name}]`).forEach(el=>{el.checked=params.getAll(name).includes(el.value);});
    const tagSelect=form.querySelector('[name=tag]');if([...tagSelect.options].some(o=>o.value===params.get('tag')))tagSelect.value=params.get('tag');
    if(params.get('sort')==='title')sort.value='title';
    let currentPage=Math.max(1,Math.min(10,Number.parseInt(params.get('pg'),10)||1));
    const readFilters=()=>({q:query.value.trim(),categories:[...form.querySelectorAll('[name=category]:checked')].map(el=>el.value),platforms:[...form.querySelectorAll('[name=platform]:checked')].map(el=>el.value),tag:form.querySelector('[name=tag]').value});
    let applied=readFilters();
    const feedback=document.getElementById('filter-feedback');
    const pending=()=>{feedback.hidden=false;feedback.textContent=t('筛选尚未应用。','Filters have not been applied.');};
    function render(){
      const q=applied.q.toLocaleLowerCase();
      const {categories,platforms,tag}=applied;
      let matches=cards.filter(card=>(!q||card.dataset.search.toLocaleLowerCase().includes(q))&&(!categories.length||categories.includes(card.dataset.category))&&(!platforms.length||platforms.includes(card.dataset.platform))&&(!tag||card.dataset.tags.split(' ').includes(tag)));
      matches.sort((a,b)=>sort.value==='title'?a.dataset.name.localeCompare(b.dataset.name):Number(a.dataset.rank)-Number(b.dataset.rank));
      cards.forEach(card=>card.hidden=true);
      const grid=document.getElementById('catalog-grid');
      matches.forEach(card=>grid.append(card));
      const perPage=6;const last=Math.max(1,Math.ceil(matches.length/perPage));currentPage=Math.min(currentPage,last);
      matches.slice((currentPage-1)*perPage,currentPage*perPage).forEach(card=>card.hidden=false);
      const u=new URL(location.href);for(const key of ['q','category','platform','tag','sort','pg'])u.searchParams.delete(key);
      if(q)u.searchParams.set('q',applied.q);categories.forEach(v=>u.searchParams.append('category',v));platforms.forEach(v=>u.searchParams.append('platform',v));if(tag)u.searchParams.set('tag',tag);if(sort.value==='title')u.searchParams.set('sort','title');if(currentPage>1)u.searchParams.set('pg',String(currentPage));history.replaceState(null,'',u.href);
      const switched=new URL(u.href);switched.searchParams.set('lang',locale==='zh'?'en':'zh');document.querySelectorAll('[data-language-switch],#prototype-toolbar a[lang]').forEach(a=>a.href=switched.href);
      document.getElementById('result-count').textContent=t(`${matches.length} 个示例资源`,`${matches.length} demo skills`);
      document.getElementById('catalog-empty').hidden=matches.length>0;
      const pager=document.getElementById('catalog-pagination');pager.replaceChildren();
      for(let i=1;i<=last;i++){const b=document.createElement('button');b.type='button';b.textContent=String(i);b.setAttribute('aria-label',t(`第 ${i} 页`,`Page ${i}`));if(i===currentPage)b.setAttribute('aria-current','page');b.addEventListener('click',()=>{currentPage=i;render();document.getElementById('results-heading').focus();});pager.append(b);}
    }
    query.addEventListener('input',pending);form.addEventListener('change',pending);form.addEventListener('submit',event=>{event.preventDefault();applied=readFilters();feedback.hidden=true;currentPage=1;render();document.getElementById('results-heading').focus();});sort.addEventListener('change',()=>{currentPage=1;render();});
    document.querySelectorAll('[data-reset-filters]').forEach(el=>el.addEventListener('click',()=>{form.reset();query.value='';applied=readFilters();feedback.hidden=true;currentPage=1;render();}));render();
  }
  const state=params.get('state');
  const region=document.querySelector('[data-state-region]');
  if(region&&['loading','empty','error'].includes(state)){
    const prior=document.createElement('div');prior.hidden=true;while(region.firstChild)prior.append(region.firstChild);region.append(prior);
    const view=document.createElement('div');
    if(state==='loading'){view.setAttribute('role','status');view.innerHTML=`<p class="mb-4 text-sm text-muted">${t('正在载入示例内容…','Loading demo content…')}</p><div class="grid gap-5 md:grid-cols-3" aria-hidden="true">${[1,2,3].map(()=>'<div class="panel p-6"><div class="skeleton h-11 w-11"></div><div class="skeleton mt-5 h-5 w-3/4"></div><div class="skeleton mt-4 h-3 w-full"></div><div class="skeleton mt-2 h-3 w-5/6"></div></div>').join('')}</div>`;}
    if(state==='empty')view.innerHTML=`<div class="empty-state"><span class="icon-box">${icon('search')}</span><h2>${t('还没有符合条件的内容','Nothing matches yet')}</h2><p>${t('可以清除筛选，或浏览其他分类。这里不会填入虚构资源。','Clear your filters or explore another category. We won’t invent content to fill this space.')}</p><a class="btn btn-secondary" href="${pages[page]}?lang=${locale}">${t('返回默认演示','Back to default demo')}</a></div>`;
    if(state==='error')view.innerHTML=`<div class="notice notice-error" role="alert"><span>${icon('shield')}</span><div><h2 class="font-semibold">${t('暂时无法加载','We couldn’t load this section')}</h2><p>${t('请稍后重试。你的操作没有被报告为保存成功。','Please try again. We have not reported your action as saved.')}</p><a class="text-link" href="${pages[page]}?lang=${locale}">${t('重试演示','Retry demo')} →</a></div></div>`;
    region.append(view);
  }
  if(state==='missing'&&page!=='admin'){
    if(region)region.hidden=true;
    const notice=document.createElement('div');notice.className='container-shell notice notice-warning my-5';notice.setAttribute('role','status');notice.textContent=t('缺译状态示例：所选内容尚无中文译文。可以阅读已有英文原文，或返回中文目录；不会伪造翻译。','Missing-translation example: this item has no English version. Read the available Chinese original or return to the English directory. No translation is invented.');const links=document.createElement('div');links.className='mt-3 flex flex-wrap gap-3';const original=document.createElement('a');original.className='btn btn-secondary';original.href=`${pages[page]}?lang=${locale==='zh'?'en':'zh'}`;original.textContent=t('阅读英文示例','Read Chinese example');const back=document.createElement('a');back.className='btn btn-secondary';back.href=`skills.html?lang=${locale}`;back.textContent=t('返回中文目录','Back to English directory');links.append(original,back);const wrap=document.createElement('div');wrap.textContent=notice.textContent;notice.replaceChildren(wrap);wrap.append(links);document.getElementById('main')?.prepend(notice);
  }
  // Same-origin embedded home-preview contract; never accepts arbitrary URLs/HTML.
  window.addEventListener('message',event=>{
    if(window.parent===window||event.source!==window.parent||event.origin!==location.origin||event.data?.type!=='aish-design-home'||page!=='home')return;
    const ids=['hero','search','categories','trending','editors','recent','rated','tutorials','contribute'];
    const modules=event.data.modules;
    if(!Array.isArray(modules)||modules.length>ids.length)return;
    const seen=new Set();const main=document.getElementById('main');
    for(const item of modules){if(!item||!ids.includes(item.id)||seen.has(item.id)||typeof item.enabled!=='boolean')continue;seen.add(item.id);const el=document.querySelector(`[data-home-module="${item.id}"]`);if(el){el.hidden=!item.enabled;main.append(el);}}
    if(typeof event.data.title==='string'&&event.data.title.length<=120)document.querySelector('[data-home-title]').textContent=event.data.title;
    if(typeof event.data.description==='string'&&event.data.description.length<=320)document.querySelector('[data-home-description]').textContent=event.data.description;
  });
})();
