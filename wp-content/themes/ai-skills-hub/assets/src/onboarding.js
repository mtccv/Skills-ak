// Completion preferences only. No visits, search terms, resource IDs or analytics events.
const STEPS = ['welcome', 'registration', 'verified', 'search', 'favorite', 'review', 'submission', 'account'];
const STORAGE = 'aish_help_introductions_v1';
const state = value => ({version: 1, completed: STEPS.filter(step => Array.isArray(value?.completed) && value.completed.includes(step))});
const readDevice = () => { try { const value = localStorage.getItem(STORAGE); const parsed = value && value.length <= 256 ? JSON.parse(value) : null; return state(parsed?.version === 1 ? parsed : null); } catch { return state(null); } };
const dismissDevice = step => {
  const next = state({completed: [...readDevice().completed, step]});
  try { localStorage.setItem(STORAGE, JSON.stringify(next)); } catch { /* Preference storage is optional; reading/navigation still work. */ }
};
const start = () => {
  document.querySelectorAll('[data-onboarding-step],[data-onboarding-settings]').forEach(panel => {
    if (panel.dataset.onboardingReady) return;
    panel.dataset.onboardingReady = '1';
    const step = panel.dataset.onboardingStep;
    const guest = panel.dataset.onboardingScope === 'device';
    const status = panel.querySelector('[data-onboarding-status]');
    if (guest) {
      if (!STEPS.includes(step)) return;
      panel.hidden = readDevice().completed.includes(step);
      panel.querySelectorAll('[data-onboarding-dismiss]').forEach(button => button.addEventListener('click', () => { dismissDevice(step); panel.hidden = true; }));
      return;
    }
    const form = panel.querySelector('[data-onboarding-form]');
    if (!form) return;
    let pending = false;
    form.addEventListener('submit', async event => {
      if (!window.fetch) return;
      event.preventDefault(); if (pending) return;
      let endpoint;
      try { endpoint = new URL(panel.dataset.onboardingEndpoint || '', location.href); }
      catch { if (status) status.textContent = panel.dataset.onboardingFailed; return; }
      if (endpoint.origin !== location.origin) { if (status) status.textContent = panel.dataset.onboardingFailed; return; }
      pending = true; panel.setAttribute('aria-busy', 'true');
      const buttons = form.querySelectorAll('button'); buttons.forEach(button => { button.disabled = true; });
      const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 10000);
      try {
        const body = new URLSearchParams(); for (const [key, value] of new FormData(form)) body.append(key, String(value));
        const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', body, signal: controller.signal});
        const result = await response.json();
        if (!response.ok || result?.success !== true || result?.data?.version !== 1 || !Array.isArray(result?.data?.completed)) throw new Error('preference_unconfirmed');
        const reset = body.get('operation') === 'reset';
        if (reset ? result.data.completed.length !== 0 : !result.data.completed.includes(step)) throw new Error('preference_unconfirmed');
        if (reset) { if (status) status.textContent = panel.dataset.onboardingReset; }
        else panel.hidden = true;
      } catch { if (status) status.textContent = panel.dataset.onboardingFailed; }
      finally { clearTimeout(timer); pending = false; panel.removeAttribute('aria-busy'); buttons.forEach(button => { button.disabled = false; }); }
    });
  });
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
