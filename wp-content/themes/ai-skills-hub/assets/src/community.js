// All mutations remain ordinary, authenticated POST forms. No local persistence of drafts.
const initializeCommunity = () => {
  let dirty = false;
  const bindForms = (root) => {
    root.querySelectorAll('[data-community-form]').forEach((form) => {
      if (form.dataset.communityBound === 'true') return;
      form.dataset.communityBound = 'true';
      if (form.querySelector('[name="community_action"]')?.value === 'review') {
        form.addEventListener('input', () => { dirty = true; });
      }
      form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
        dirty = false;
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
          button.dataset.originalText = button.textContent;
          button.disabled = true;
          if (button.dataset.pendingLabel) button.textContent = button.dataset.pendingLabel;
        });
      });
    });
  };
  bindForms(document);
  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault(); event.returnValue = '';
  });
  document.querySelector('[data-community-feedback]')?.focus();

  const dialog = document.getElementById('community-report-dialog');
  if (!dialog || typeof dialog.showModal !== 'function' || typeof fetch !== 'function') return;
  const content = dialog.querySelector('[data-community-dialog-content]');
  const title = dialog.querySelector('#community-dialog-title');
  const loading = dialog.querySelector('.community-dialog-loading');
  let source = null;
  let pending = null;
  dialog.querySelector('[data-community-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => {
    pending?.abort(); pending = null;
    content.replaceChildren();
    source?.focus();
  });
  document.querySelectorAll('a[data-community-dialog]').forEach((link) => {
    link.addEventListener('click', async (event) => {
      const url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || !/\/(?:zh|en)\/account\/report-(?:review|skill)\/$/.test(url.pathname)) return;
      event.preventDefault();
      pending?.abort(); pending = new AbortController();
      const request = pending;
      source = link; content.replaceChildren(); loading.hidden = false;
      title.textContent = link.textContent;
      dialog.showModal();
      dialog.querySelector('[data-community-close]').focus();
      try {
        const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', signal: request.signal });
        if (request !== pending || !dialog.open) return;
        if (!response.ok || response.redirected || new URL(response.url).origin !== window.location.origin) {
          if (dialog.open) window.location.assign(url.href);
          return;
        }
        const html = await response.text();
        const page = new DOMParser().parseFromString(html, 'text/html');
        const panel = page.getElementById('community-report-panel');
        if (!panel || !panel.querySelector('#community-report-form')) { window.location.assign(url.href); return; }
        if (request !== pending || !dialog.open) return;
        // Only our same-origin escaped SSR form is imported, never arbitrary remote markup.
        title.textContent = panel.dataset.communityReportTitle || link.textContent;
        content.append(document.importNode(panel, true));
        loading.hidden = true; bindForms(content);
        content.querySelector('select,input:not([type="hidden"]):not([tabindex="-1"]),textarea')?.focus();
      } catch (error) {
        if (error.name !== 'AbortError' && request === pending && dialog.open) window.location.assign(url.href);
      }
    });
  });
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeCommunity);
else initializeCommunity();
window.addEventListener('pageshow', () => {
  document.querySelectorAll('[data-community-form]').forEach((form) => {
    form.dataset.submitting = 'false'; form.removeAttribute('aria-busy');
    form.querySelectorAll('[data-original-text]').forEach((button) => {
      button.disabled = false; button.textContent = button.dataset.originalText; delete button.dataset.originalText;
    });
  });
});
