// Copy visible text only. No command execution, download, tracking request or third-party SDK.
function mountSkillExperience(root) {
  if (root.dataset.copyMounted === '1') return;
  root.dataset.copyMounted = '1';
  const status = root.querySelector('.aish-copy-status');
  const say = (message) => { if (status) status.textContent = message || ''; };
  const copyButtons = root.querySelectorAll('[data-copy-target]');
  copyButtons.forEach((button) => {
    const target = document.getElementById(button.dataset.copyTarget);
    if (!target || !root.contains(target)) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
      const text = target instanceof HTMLInputElement ? target.value : target.textContent;
      if (!text || button.disabled) return;
      button.disabled = true;
      say('');
      try {
        if (!window.isSecureContext || !navigator.clipboard?.writeText) throw new Error('clipboard unavailable');
        await navigator.clipboard.writeText(text);
        say(root.dataset.copySuccess);
      } catch (_) {
        const details = target.closest('details');
        if (details) details.open = true;
        if (target instanceof HTMLInputElement) {
          target.focus(); target.select();
        } else {
          const pre = target.closest('pre');
          if (pre) pre.focus();
          try { const selection = window.getSelection(); const range = document.createRange(); range.selectNodeContents(target); selection.removeAllRanges(); selection.addRange(range); } catch (_) {}
        }
        say(root.dataset.copyFailure);
      } finally { button.disabled = false; }
    });
  });
  const share = root.querySelector('[data-native-share]');
  if (share && navigator.share) {
    let url;
    try { url = new URL(root.dataset.shareUrl); } catch (_) { return; }
    if (!['http:', 'https:'].includes(url.protocol) || url.search || url.hash || url.username || url.password) return;
    share.hidden = false;
    share.addEventListener('click', async () => {
      if (share.disabled) return;
      share.disabled = true;
      try { await navigator.share({ title: root.dataset.shareTitle, url: url.href }); }
      catch (error) { if (error?.name !== 'AbortError') say(root.dataset.shareFailure); }
      finally { share.disabled = false; }
    });
  }
}
function mountSkillExperiences() {
  document.querySelectorAll('[data-skill-share], [data-installation-experience]').forEach(mountSkillExperience);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountSkillExperiences, { once: true });
else mountSkillExperiences();
