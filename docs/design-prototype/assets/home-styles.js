/* Static design registry only. No WordPress settings, storage, or remote templates. */
(() => {
  'use strict';
  const registry = Object.freeze({
    'skills-directory': Object.freeze({ zh: '专业资源目录', en: 'Skills Directory' }),
    'modern-saas': Object.freeze({ zh: 'SaaS 展示首页', en: 'Modern SaaS' })
  });
  const ids = Object.freeze(Object.keys(registry));
  const has = (id) => typeof id === 'string' && Object.hasOwn(registry, id);
  const normalize = (id) => has(id) ? id : 'skills-directory';
  const label = (id, language) => registry[normalize(id)][language === 'en' ? 'en' : 'zh'];
  const isHome = () => document.body?.dataset.page === 'home';
  const language = () => document.documentElement.lang.startsWith('en') ? 'en' : 'zh';
  let current = normalize(new URLSearchParams(location.search).get('style'));

  function syncLinks() {
    if (!isHome()) return;
    document.querySelectorAll('a[href]').forEach((link) => {
      const raw = link.getAttribute('href');
      if (!raw || raw.startsWith('#')) return;
      const url = new URL(raw, location.href);
      if (url.origin !== location.origin || !/\/(index|preview)\.html$/.test(url.pathname)) return;
      url.searchParams.set('style', current);
      link.href = url.href;
    });
  }

  function syncFeaturedContent() {
    if (!isHome()) return;
    // A visual projection must obey the source module's state and visibility.
    // Hidden sample data is retained for the state demo, not for another surface.
    const state = document.getElementById('prototype-state')?.value || new URLSearchParams(location.search).get('state') || 'default';
    const module = document.querySelector('[data-home-module="trending"]');
    const region = module?.querySelector('[data-state-region]');
    const featured = region?.querySelector('.skill-card');
    const visible = (node) => Boolean(node && !node.closest('[hidden]') && node.getClientRects().length && getComputedStyle(node).visibility === 'visible');
    const available = state === 'default' && visible(module) && visible(region) && visible(featured);
    for (const [slot, source] of [['title', 'h3'], ['description', '.description']]) {
      const text = available ? featured.querySelector(source)?.textContent || '' : '';
      document.querySelectorAll(`[data-home-featured-${slot}]`).forEach((node) => {
        node.textContent = text;
        const resource = node.closest('.home-style-console-resource');
        if (resource) resource.hidden = !available;
      });
    }
  }

  function apply(id, { updateURL = false } = {}) {
    current = normalize(id);
    if (!isHome()) return current;
    document.body.dataset.homeStyle = current;
    const picker = document.getElementById('prototype-home-style');
    if (picker) picker.value = current;
    const status = document.getElementById('prototype-home-style-status');
    if (status) status.textContent = label(current, language());
    if (updateURL) {
      const url = new URL(location.href);
      url.searchParams.set('style', current);
      history.replaceState(null, '', url);
    }
    syncLinks();
    syncFeaturedContent();
    return current;
  }
  window.AishHomeStyles = Object.freeze({ ids, has, normalize, label, apply, syncFeaturedContent });
  if (!isHome()) return;
  apply(current);

  document.addEventListener('DOMContentLoaded', () => {
    // Both skins use the same live module DOM. Changing a skin never rebuilds
    // content, resets favorites, duplicates IDs, or replaces bilingual copy.
    const toolbar = document.querySelector('#prototype-toolbar .prototype-bar-inner');
    if (toolbar) {
      const control = document.createElement('label');
      control.className = 'inline-flex items-center gap-2';
      control.append(document.createTextNode(language() === 'en' ? 'Home style ' : '首页风格 '));
      const select = document.createElement('select');
      select.id = 'prototype-home-style';
      ids.forEach((id) => {
        const option = document.createElement('option');
        option.value = id;
        option.textContent = label(id, language());
        select.append(option);
      });
      select.addEventListener('change', () => apply(select.value, { updateURL: true }));
      control.append(select);
      const stateControl = toolbar.querySelector('#prototype-state')?.closest('label');
      toolbar.insertBefore(control, stateControl || null);
      const status = document.createElement('span');
      status.id = 'prototype-home-style-status';
      status.className = 'sr-only';
      status.setAttribute('role', 'status');
      toolbar.append(status);
    }
    // The optional visual example refers to an existing resource; it has no
    // separate resource record or author-maintained content copy.
    apply(current);
  });
})();
