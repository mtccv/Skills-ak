(() => {
  'use strict';
  const pages = {home:'index.html',skills:'skills.html',skill:'skill.html',dashboard:'dashboard.html',admin:'admin.html'};
  const form = document.querySelector('#preview-controls');
  const frame = document.querySelector('#design-frame');
  const query = new URLSearchParams(location.search);
  for (const name of ['page','lang','width','state']) {
    const select = form.elements.namedItem(name);
    if ([...select.options].some(o=>o.value===query.get(name))) select.value = query.get(name);
  }
  function render() {
    const values = Object.fromEntries(new FormData(form));
    const width = ['1440','768','390','360'].includes(values.width)? values.width:'1440';
    frame.width = width;
    frame.style.width = width + 'px';
    frame.src = (pages[values.page] || pages.home) + '?' + new URLSearchParams({lang:values.lang,state:values.state,embed:'1'});
    document.querySelector('#preview-description').textContent = `${width} CSS px · ${values.lang} · ${values.state} · Design demo only`;
    history.replaceState(null,'','?'+new URLSearchParams(values));
  }
  form.addEventListener('change',render);
  form.addEventListener('submit',e=>e.preventDefault());
  render();
})();
