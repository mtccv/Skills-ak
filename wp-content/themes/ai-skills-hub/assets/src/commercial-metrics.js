// Optional first-party observations. Normal links remain usable without this code or consent.
document.querySelectorAll('[data-commercial-metric]').forEach((element) => {
  const allow = element.querySelector('[data-commercial-consent]');
  const withdraw = element.querySelector('[data-commercial-withdraw]');
  const status = element.querySelector('[data-commercial-status]');
  const link = element.querySelector('[data-commercial-link]');
  if (!allow || !withdraw || !status || !link) return;
  const zh = element.dataset.commercialLocale === 'zh_CN';
  let ready = false;
  let busy = false;
  let cancelled = false;
  let impression = false;
  let clicked = false;
  let visible = false;
  let elapsed = 0;
  let last = performance.now();
  let timer;
  const text = (cn, en) => { status.textContent = zh ? cn : en; };
  const request = async (step, event = '') => {
    const endpoint = new URL(element.dataset.commercialEndpoint, window.location.href);
    if (endpoint.origin !== window.location.origin) throw new Error('origin');
    const perform = async () => {
      const response = await fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', keepalive: event === 'click',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'aish_commercial_metric', step, event, nonce: element.dataset.commercialNonce, proof: element.dataset.commercialProof }),
    });
    if (!response.ok) throw new Error('unavailable');
    const result = await response.json();
    if (!result.success || typeof result.data?.state !== 'string') throw new Error('response');
      if (step === 'withdraw' && result.data.state !== 'stopped') throw new Error('unconfirmed');
      return result.data;
    };
    if (step !== 'consent' && step !== 'withdraw') return perform();
    // All measurement widgets share one cookie. Preserve explicit mutation order.
    const pending = (window.aishMeasurementConsentTail || Promise.resolve()).catch(() => {}).then(perform);
    window.aishMeasurementConsentTail = pending.catch(() => {});
    return pending;
  };
  const stop = () => { cancelled = true; ready = false; clearInterval(timer); };
  const measure = (result) => {
    if (result.state !== 'ready' || cancelled) return;
    ready = true; allow.hidden = true; withdraw.hidden = false;
    text('当天赞助统计已启用；这不是邮件订阅。', 'Daily sponsorship measurement is enabled; this is not an email subscription.');
    if (!('IntersectionObserver' in window) || impression) return;
    clearInterval(timer); last = performance.now();
    timer = setInterval(() => {
      const now = performance.now();
      if (ready && !cancelled && visible && document.visibilityState === 'visible' && document.hasFocus()) elapsed += Math.min(1000, Math.max(0, now - last));
      last = now;
      if (elapsed < 1000 || impression) return;
      impression = true; clearInterval(timer);
      request('record', 'impression').catch(() => { if (!cancelled) text('展示统计未确认，不会自动重试。', 'Impression measurement is unconfirmed and will not be retried.'); });
    }, 250);
  };
  let observer;
  if ('IntersectionObserver' in window) {
    observer = new IntersectionObserver((entries) => { visible = entries.some((entry) => entry.target === element && entry.isIntersecting && entry.intersectionRatio >= 0.5); }, { threshold: [0, 0.5] });
    observer.observe(element);
  }
  link.addEventListener('click', (event) => {
    if (!event.isTrusted || !ready || cancelled || clicked) return;
    clicked = true;
    // Never preventDefault, rewrite href, wait for a reply or call an arbitrary destination endpoint.
    request('record', 'click').catch(() => {});
  });
  allow.addEventListener('click', async () => {
    if (busy) return; busy = true; allow.disabled = true; cancelled = false;
    try { measure(await request('consent')); }
    catch { text('暂时无法启用统计，链接仍可正常使用。', 'Measurement is unavailable; the link remains usable.'); }
    finally { busy = false; allow.disabled = false; }
  });
  withdraw.addEventListener('click', async () => {
    if (busy) return; stop(); busy = true; withdraw.disabled = true;
    window.dispatchEvent(new Event('aish-measurement-withdrawn'));
    try { await request('withdraw'); allow.hidden = false; withdraw.hidden = true; text('已停止所有当天统计。', 'All daily measurement stopped.'); }
    catch { allow.hidden = true; withdraw.hidden = false; text('此页面已停止统计，Cookie 清除未确认，请重试。', 'This page stopped measuring; cookie removal is unconfirmed. Please retry.'); }
    finally { busy = false; withdraw.disabled = false; }
  });
  window.addEventListener('aish-measurement-withdrawn', () => { stop(); allow.hidden = false; withdraw.hidden = true; text('此页面的所有当天统计已停止。', 'All daily measurement on this page stopped.'); });
  window.addEventListener('pagehide', () => { stop(); observer?.disconnect(); }, { once: true });
  request('start').then(measure).catch(() => {});
});
