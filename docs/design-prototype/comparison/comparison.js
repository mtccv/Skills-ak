/* Read-only screenshot comparison. No network service, storage or account calls. */
(() => {
 'use strict';
 const pages = {home:['首页','index'],skills:['Skills 列表','skills'],skill:['Skill 详情','skill'],dashboard:['用户 Dashboard','dashboard'],admin:['WordPress 后台 Dashboard','admin']};
 const params = new URLSearchParams(location.search);
 const form = document.getElementById('comparison-controls');
 const pageChoice = document.getElementById('page-choice');
 const deviceChoice = document.getElementById('device-choice');
 const extentChoice = document.getElementById('extent-choice');
 pageChoice.value = Object.hasOwn(pages,params.get('page')) ? params.get('page') : 'home';
 deviceChoice.value = params.get('device') === 'mobile' ? 'mobile' : 'desktop';
 extentChoice.value = params.get('extent') === 'full' ? 'full' : 'viewport';
 document.body.dataset.capture = String(params.get('capture') === '1');
 function render() {
  const page = pageChoice.value, device = deviceChoice.value, extent = extentChoice.value;
  const [title,filename] = pages[page];
  const deviceLabel = device === 'desktop' ? '桌面 1440 × 1000 · 中文' : '手机 390 × 844 · English';
  document.body.dataset.device = device;
  document.getElementById('comparison-title').textContent = title;
  document.getElementById('comparison-meta').textContent = `${deviceLabel} · ${extent === 'full' ? '完整页面' : '首屏对比'}`;
  for (const stage of ['before','after']) {
   const src = `${page}-${device}-${stage}-${extent}.png`;
   const img = document.getElementById(`${stage}-image`);
   img.src = src;
   img.alt = `${stage === 'before' ? '修改前' : '修改后'}${title} · ${deviceLabel} · ${extent === 'full' ? '完整页面' : '首屏'}`;
   document.getElementById(`${stage}-link`).href = src;
  }
  document.getElementById('live-preview').href = `../${filename}.html?lang=${device === 'desktop' ? 'zh' : 'en'}&state=default`;
  const url = new URL(location.href);
  url.searchParams.set('page',page);url.searchParams.set('device',device);url.searchParams.set('extent',extent);
  history.replaceState(null,'',url);
 }
 form.addEventListener('change',render);
 form.addEventListener('submit',event=>event.preventDefault());
 render();
})();
