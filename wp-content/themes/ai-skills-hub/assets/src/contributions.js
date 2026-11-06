// Progressive enhancement only: every action works through native POST and server redirects.
const initializeContributions = () => {
  let dirty = false;
  document.querySelectorAll('[data-contribution-form]').forEach((form) => {
    if (form.hasAttribute('data-contribution-draft')) {
      form.addEventListener('input', () => { dirty = true; });
      form.addEventListener('change', () => { dirty = true; });
    }
    form.addEventListener('submit', (event) => {
      if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
      // Disabled buttons are not successful controls. Preserve the chosen draft/review mode.
      const submitter = event.submitter;
      if (submitter?.name) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.name = submitter.name; hidden.value = submitter.value;
        hidden.dataset.contributionSubmitter = 'true'; form.append(hidden);
      }
      dirty = false; form.dataset.submitting = 'true'; form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.dataset.originalText = button.textContent; button.disabled = true;
        if (button.dataset.pendingLabel) button.textContent = button.dataset.pendingLabel;
      });
    });
  });
  window.addEventListener('beforeunload', (event) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  document.querySelector('[data-contribution-feedback]')?.focus();
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeContributions);
else initializeContributions();
window.addEventListener('pageshow', () => {
  document.querySelectorAll('[data-contribution-form]').forEach((form) => {
    form.dataset.submitting = 'false'; form.removeAttribute('aria-busy');
    form.querySelectorAll('[data-contribution-submitter]').forEach((input) => input.remove());
    form.querySelectorAll('[data-original-text]').forEach((button) => {
      button.disabled = false; button.textContent = button.dataset.originalText; delete button.dataset.originalText;
    });
  });
});
