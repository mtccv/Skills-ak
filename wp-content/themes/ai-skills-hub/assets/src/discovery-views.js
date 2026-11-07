// An optional daily cookie, never localStorage, account IDs or a browsing history.
document.querySelectorAll('[data-discovery-view]').forEach((element) => {
  const allow = element.querySelector('[data-discovery-consent]');
  const withdraw = element.querySelector('[data-discovery-withdraw]');
  const status = element.querySelector('[data-discovery-status]');
  const zh = element.dataset.locale === 'zh_CN';
  let timer;
  let ticket;
  let seconds = 0;
  let busy = false;
  let generation = 0;
  const stop = () => { clearInterval(timer); timer = undefined; ticket = undefined; seconds = 0; generation += 1; };
  const text = (chinese, english) => { status.textContent = zh ? chinese : english; };
  const request = async (step, proof = element.dataset.ticket) => {
    const endpoint = new URL(element.dataset.endpoint, window.location.href);
    if (endpoint.origin !== window.location.origin) throw new Error('origin');
    const response = await fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'aish_discovery_view', step, nonce: element.dataset.nonce,
        skill: element.dataset.skill, locale: element.dataset.locale, ticket: proof || '' }),
    });
    if (!response.ok) throw new Error('unavailable');
    const data = await response.json();
    if (!data.success || !data.data || typeof data.data.state !== 'string') throw new Error('response');
    return data.data;
  };
  const measure = (result) => {
    stop();
    if (result.state !== 'ready' || typeof result.ticket !== 'string') return;
    ticket = result.ticket;
    allow.hidden = true;
    withdraw.hidden = false;
    text('已允许当天匿名统计，可随时停止。', 'Anonymous measurement is allowed today. You can stop at any time.');
    let previous = performance.now();
    const revision = generation;
    timer = setInterval(async () => {
      const now = performance.now();
      // A suspended tab cannot add a long elapsed gap as visible dwell time.
      if (document.visibilityState === 'visible' && document.hasFocus()) seconds += Math.min((now - previous) / 1000, 1);
      previous = now;
      if (seconds < 8 || !ticket || revision !== generation) return;
      clearInterval(timer);
      timer = undefined;
      const proof = ticket;
      ticket = undefined;
      try { await request('record', proof); }
      catch { if (revision === generation) text('本次浏览未能确认计数，不会自动重试。', 'This observation could not be confirmed; it will not be retried automatically.'); }
    }, 1000);
  };
  allow.addEventListener('click', async () => {
    if (busy) return;
    busy = true; allow.disabled = true;
    try { measure(await request('consent')); }
    catch { text('暂时无法启用，请刷新页面后重试。', 'Measurement is unavailable. Reload the page to try again.'); }
    finally { busy = false; allow.disabled = false; }
  });
  withdraw.addEventListener('click', async () => {
    if (busy) return;
    stop(); busy = true; withdraw.disabled = true;
    try {
      await request('withdraw');
      withdraw.hidden = true; allow.hidden = false;
      text('已停止，匿名汇总不会关联到您的账户。', 'Stopped. Anonymous totals are not linked to your account.');
    } catch { text('当前页面已停止统计，未能确认清除当天 Cookie；请重试。', 'This page has stopped measuring. Cookie removal could not be confirmed; please retry.'); }
    finally { busy = false; withdraw.disabled = false; }
  });
  // Without an existing, signed consent cookie this request writes nothing.
  busy = true; allow.disabled = true;
  request('start').then(measure).catch(() => {}).finally(() => { busy = false; allow.disabled = false; });
  window.addEventListener('pagehide', stop, { once: true });
});
