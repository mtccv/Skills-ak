import Alpine from 'alpinejs';
import './accounts.js';
import './community.js';
import './contributions.js';

window.Alpine = Alpine;
Alpine.start();

// A page restored from the browser's back/forward cache must accept another search.
window.addEventListener('pageshow', () => {
  document.querySelectorAll('form[x-data]').forEach((form) => {
    const state = Alpine.$data(form);
    if (state && 'submitting' in state) state.submitting = false;
  });
});
