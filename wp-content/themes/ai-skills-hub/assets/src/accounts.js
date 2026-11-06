// Progressive enhancement only: account navigation and every mutation work with SSR forms.
const initializeAccounts = () => {
  if (!document.body.classList.contains('aish-account-site')) return;
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(show));
      button.textContent = show ? button.dataset.hide : button.dataset.show;
    });
  });
  document.querySelectorAll('.account-mobile-nav').forEach((details) => {
    details.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !details.open) return;
      details.open = false;
      details.querySelector('summary').focus();
    });
  });
  let dirty = false;
  document.querySelectorAll('[data-account-form]').forEach((form) => {
    const action = form.querySelector('[name="account_action"]')?.value;
    if (['profile', 'preferences'].includes(action)) form.addEventListener('input', () => { dirty = true; });
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
  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });
  const feedback = document.querySelector('[data-account-feedback]');
  if (feedback) {
    const invalid = document.querySelectorAll('[aria-invalid="true"]');
    if (invalid.length) {
      const links = document.createElement('ul');
      invalid.forEach((input) => {
        const label = document.querySelector(`label[for="${input.id}"]`);
        if (!label) return;
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = `#${input.id}`;
        link.className = 'account-text-action';
        link.textContent = label.textContent;
        link.addEventListener('click', () => input.focus());
        item.append(link); links.append(item);
      });
      feedback.append(links);
    }
    feedback.focus({ preventScroll: false });
  }
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeAccounts);
else initializeAccounts();
window.addEventListener('pageshow', () => {
  document.querySelectorAll('[data-account-form]').forEach((form) => {
    form.dataset.submitting = 'false';
    form.removeAttribute('aria-busy');
    form.querySelectorAll('[data-original-text]').forEach((button) => {
      button.disabled = false;
      button.textContent = button.dataset.originalText;
      delete button.dataset.originalText;
    });
  });
});
