// Optional shared-cookie measurement. No local storage, query logging or automatic retries.
document.querySelectorAll('[data-search-metric]').forEach((element) => {
  const allow = element.querySelector('[data-search-consent]');
  const withdraw = element.querySelector('[data-search-withdraw]');
  const status = element.querySelector('[data-search-status]');
  const zh = element.dataset.locale === 'zh_CN';
  let busy = false;
  let cancelled = false;
  let timer;
  const text = (cn, en) => { status.textContent = zh ? cn : en; };
  const request = async (step) => {
    const endpoint = new URL(element.dataset.endpoint, window.location.href);
    if (endpoint.origin !== window.location.origin) throw new Error('origin');
    const response = await fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'aish_search_metric', step, nonce: element.dataset.nonce, proof: element.dataset.proof }),
    });
    if (!response.ok) throw new Error('unavailable');
    const result = await response.json();
    if (!result.success || typeof result.data?.state !== 'string') throw new Error('response');
    return result.data;
  };
  const measure = (result) => {
    if (result.state !== 'ready' || cancelled) return;
    allow.hidden = true; withdraw.hidden = false;
    text('当天统计已启用，可随时停止。', 'Daily measurement is enabled. You can stop at any time.');
    clearInterval(timer);
    timer = setInterval(async () => {
      if (cancelled || document.visibilityState !== 'visible' || !document.hasFocus()) return;
      clearInterval(timer);
      try { await request('record'); }
      catch { if (!cancelled) text('本次统计未能确认，不会自动重试。', 'Measurement could not be confirmed and will not be retried.'); }
    }, 1500);
  };
  allow.addEventListener('click', async () => {
    if (busy) return;
    busy = true; allow.disabled = true; cancelled = false;
    try { measure(await request('consent')); }
    catch { text('暂时无法启用，请刷新后重试。', 'Measurement is unavailable. Reload to try again.'); }
    finally { busy = false; allow.disabled = false; }
  });
  withdraw.addEventListener('click', async () => {
    if (busy) return;
    cancelled = true; clearInterval(timer); busy = true; withdraw.disabled = true;
    try { await request('withdraw'); allow.hidden = false; withdraw.hidden = true; text('已停止当天浏览、搜索和账户活跃统计。', 'Daily view, search and account-activity measurement stopped.'); }
    catch { text('此页面已停止统计，Cookie 清除未确认，请重试。', 'This page stopped measuring; cookie removal is unconfirmed. Please retry.'); }
    finally { busy = false; withdraw.disabled = false; }
  });
  request('start').then(measure).catch(() => {});
  window.addEventListener('pagehide', () => { cancelled = true; clearInterval(timer); }, { once: true });
});
