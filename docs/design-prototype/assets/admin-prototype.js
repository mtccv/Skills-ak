/* Design-only interactions. No WordPress endpoint, persistence, or network calls. */
(() => {
  'use strict';
  const $ = (selector) => document.querySelector(selector);
  const $$ = (selector) => Array.from(document.querySelectorAll(selector));
  const copy = (value) => JSON.parse(JSON.stringify(value));
  const uiLang = () => document.documentElement.lang.toLowerCase().startsWith('en') ? 'en' : 'zh';
  const bilingual = (node, zh, en) => {
    node.dataset.i18nZh = zh;
    node.dataset.i18nEn = en;
    node.textContent = uiLang() === 'en' ? en : zh;
    return node;
  };
  const element = (tag, zh, en, className) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    return bilingual(node, zh, en);
  };
  const moduleInfo = {
    skills: ['Skills 与内容审核', 'Skills and content approval', '现有原生列表与双语编辑；审核必须针对当前待审修订。', 'Existing native lists and bilingual editing; approval must target the current pending revision.', ['Skill #128 · revision 4 · pending', '完整语言：中文 / English；来源与人工审核证据分列。', '批量仅提交待审、转草稿、确认归档；不批量直接发布。'], ['Skill #128 · revision 4 · pending', 'Complete languages: 中文 / English. Source evidence and human review are separate.', 'Bulk actions submit for review, return to draft, or confirm archival; they never publish directly.']],
    users: ['用户管理', 'User management', 'Phase 8 / 12 规划：在 WordPress 原生 Users 增加业务状态。', 'Planned for Phase 8 / 12: extend native WordPress Users with business status.', ['列：注册时间、验证证据、业务限制、互动汇总。', '限制操作显示原因、期限、操作者与恢复路径。', '社区运营能力不授予角色管理权限。'], ['Columns: registration date, verification evidence, business restrictions, activity summaries.', 'Restrictions show the reason, expiry, actor, and restoration path.', 'Community moderation does not grant role-management permissions.']],
    submissions: ['投稿 Inbox', 'Submission inbox', '当前私有 Inbox 已存在；注册用户投稿和版本流程属于 Phase 10 规划。', 'A private Inbox exists today; registered-user submission revisions are planned for Phase 10.', ['Inbox #42 · revision 1 · 仓库路径待确认。', '旧匿名投稿保留原归属，不按邮箱自动绑定。', '采纳只代表建立或关联记录，不代表 Skill 已发布。'], ['Inbox #42 · revision 1 · Repository path unconfirmed.', 'Legacy anonymous submissions keep their ownership; email does not establish an account link.', 'Acceptance means a record is created or linked; it does not mean the Skill is published.']],
    reviews: ['社区评价审核', 'Community review moderation', 'Phase 9 规划：与 Skill 内容审核、AI 审稿分别管理。', 'Planned for Phase 9: separate from Skill content approval and AI review.', ['Review revision 3 待审；revision 2 仍公开。', '一用户一 Skill；仅有效批准修订贡献评分。', '拒绝新稿保留旧公开版本；撤回即时移除计数。'], ['Review revision 3 is pending; revision 2 remains public.', 'One review per user per Skill; only the active approved revision contributes to scores.', 'Rejecting an edit retains the previous public version; withdrawal removes its contribution.']],
    reports: ['举报与纠错', 'Reports and corrections', 'Phase 9 / 12 规划：保留对象、语言和确切修订的证据。', 'Planned for Phase 9 / 12: retain the target object, language, and exact revision.', ['Skill #96 · revision 2 · 安全线索待核实。', '举报不自动下架、不触发抓取、不公开举报人。', '处理结果、理由、操作者和时间形成审计记录。'], ['Skill #96 · revision 2 · Security concern to verify.', 'Reports do not automatically unpublish content, fetch URLs, or identify reporters publicly.', 'Resolution, reason, actor, and time form an audit record.']],
    sources: ['GitHub 采集与来源', 'GitHub imports and sources', '现有采集队列；任务、来源存在性、变更状态与公开审核各自独立。', 'Existing import queue; job status, source presence, change status, and publication review are independent.', ['任务 #721：incomplete；完整扫描未结束，不能推断来源删除。', '任务 #719：retry；下一次 2026-11-05 09:45 UTC。', '新来源进入 pending；handled 仅表示人工处理来源提示。'], ['Job #721: incomplete. An unfinished scan cannot establish source deletion.', 'Job #719: retry. Next attempt: 2026-11-05 09:45 UTC.', 'New sources enter pending; handled only acknowledges a source notice.']],
    ai: ['AI 生成与草稿审阅', 'AI generation and draft review', '现有 AI 队列；生成不会自动覆盖人工内容或发布。', 'Existing AI queue; generation never automatically replaces human content or publishes it.', ['任务 #304：failed / 结果待核实；已发送未确认，不盲目重发。', '待审草稿：needs_review；审稿状态 pending / edited / accepted / rejected。', '费用按配置单价估算，不是账单；未知用量不能显示为 0。', '人工采用只填明确选择的空字段，并回到 pending。'], ['Job #304: failed / result uncertain. Sent but unconfirmed requests are not blindly resent.', 'Draft job: needs_review. Review states: pending / edited / accepted / rejected.', 'Costs use configured prices and are estimates, not bills; unknown usage is never zero.', 'Human adoption only fills explicitly selected empty fields and returns the Skill to pending.']],
    analytics: ['运营统计', 'Operational analytics', 'Phase 9 / 12 规划：只展示可复算的业务指标。', 'Planned for Phase 9 / 12: show reproducible business metrics.', ['每项注明数据源、窗口、分母、统计起点与更新时间。', '每日去重浏览量之和不是整个窗口的独立访客。', '未采集显示无数据，未知显示未知；不以示例值填充。'], ['Each metric states its source, window, denominator, collection start, and refresh time.', 'The sum of daily deduplicated views is not the number of unique visitors across the whole window.', 'Uncollected metrics show no data; unknown values stay unknown. Demo values are never substituted.']],
    settings: ['设置', 'Settings', '复用原生 options、领域设置页与权限边界。', 'Reuse native options, domain settings pages, and permission boundaries.', ['现有：每页数量 / 默认语言、SEO、AI 档案与来源配置。', 'API Key 只显示服务器状态与固定掩码；无查看或复制按钮。', '通知运营与首页外观在后续阶段实现；当前只做设计。'], ['Existing: page size / default language, SEO, AI profiles, and source configuration.', 'API keys show only server configuration status and a fixed mask; there is no reveal or copy button.', 'Notification operations and homepage settings are future implementation work; this is design only.']]
  };
  let lastDialogTrigger = null;
  const openDialog = (dialog, trigger) => {
    lastDialogTrigger = trigger || document.activeElement;
    dialog.showModal();
  };
  $$('dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => {
      if (lastDialogTrigger && lastDialogTrigger.isConnected) lastDialogTrigger.focus();
    });
    dialog.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => dialog.close()));
  });
  let currentModule = null;
  const renderModuleDialog = () => {
    const item = moduleInfo[currentModule];
    if (!item) return;
    bilingual($('#admin-module-title'), item[0], item[1]);
    const content = $('#admin-module-content');
    content.replaceChildren();
    content.append(element('span', '示例记录 / 设计说明', 'Demo records / design notes', 'wp-status blue'));
    content.append(element('p', item[2], item[3]));
    const list = document.createElement('ul');
    item[4].forEach((line, index) => list.append(element('li', line, item[5][index])));
    content.append(list);
    content.append(element('p', '此入口展示界面合同，不查询 WordPress、不执行审核或任务。', 'This entry explains the interface contract. It does not query WordPress or execute reviews or jobs.', 'wp-description wp-module-demo'));
  };
  $$('[data-admin-module]').forEach((button) => button.addEventListener('click', () => {
    currentModule = button.dataset.adminModule;
    renderModuleDialog();
    openDialog($('#admin-module-dialog'), button);
  }));
  const activatePage = (name, focus = true) => {
    $$('[data-admin-screen]').forEach((screen) => { screen.hidden = screen.dataset.adminScreen !== name; });
    $$('.wp-sidebar [data-admin-page]').forEach((button) => {
      const active = button.dataset.adminPage === name;
      button.classList.toggle('active', active);
      if (active) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
    });
    $('.wp-shell').classList.remove('wp-menu-open');
    $('#wp-menu-toggle').setAttribute('aria-expanded', 'false');
    if (focus) $('#main').focus({ preventScroll: true });
  };
  $$('[data-admin-page]').forEach((button) => button.addEventListener('click', () => activatePage(button.dataset.adminPage)));
  $('#wp-menu-toggle').addEventListener('click', () => {
    const expanded = $('.wp-shell').classList.toggle('wp-menu-open');
    $('#wp-menu-toggle').setAttribute('aria-expanded', String(expanded));
  });
  const originalRows = Array.from($('#task-rows').children);
  $('#task-sort').addEventListener('change', (event) => {
    const rows = event.target.value === 'oldest' ? originalRows.slice().reverse() : originalRows;
    $('#task-rows').replaceChildren(...rows);
  });
  const demoState = new URLSearchParams(location.search).get('state');
  const stateLabels = {
    loading: ['正在载入待办示例…', 'Loading the inbox demo…'],
    empty: ['没有待处理记录。其他摘要仍是固定演示快照。', 'No pending items. Other summaries remain fixed demo snapshots.'],
    error: ['待办暂时无法载入，数量未知。未报告任何保存或审核成功。', 'The inbox could not load; its count is unknown. No save or review is reported as successful.']
  };
  if (stateLabels[demoState]) {
    const row = document.createElement('tr');
    const cell = element('td', ...stateLabels[demoState]);
    cell.colSpan = 4;
    cell.setAttribute('role', demoState === 'error' ? 'alert' : 'status');
    row.append(cell);
    $('#task-rows').replaceChildren(row);
    $('#task-sort').disabled = true;
    bilingual($('.wp-todo .wp-count'), demoState === 'empty' ? '0' : '—', demoState === 'empty' ? '0' : '—');
    bilingual($('.wp-status-tabs strong'), demoState === 'empty' ? '待处理 (0)' : '待处理数量未知', demoState === 'empty' ? 'Pending (0)' : 'Pending count unknown');
  }
  if (demoState === 'missing') {
    const notice = document.createElement('div');
    notice.className = 'wp-notice warning';
    notice.setAttribute('role', 'status');
    const content = document.createElement('div');
    content.append(element('strong', '缺译状态示例：English 内容尚未完整', 'Missing-translation demo: English content is incomplete'));
    content.append(element('p', '缺失语言不会显示为可公开译文。请进入双语编辑补充；切换语言保留当前输入。', 'The missing language is not presented as a publishable translation. Complete it in the bilingual editor; changing content tabs preserves input.'));
    notice.append(content);
    $('#admin-dashboard').insertBefore(notice, $('.wp-stats'));
  }
  const moduleLabels = {
    hero: ['Hero 介绍', 'Hero introduction', '核心 · 标题与介绍', 'Core · Title and description'],
    search: ['搜索入口', 'Search', '核心 · 搜索已公开内容', 'Core · Search public content'],
    categories: ['分类导航', 'Categories', '按分类发现', 'Browse by category'],
    trending: ['热门 Skills', 'Trending Skills', '真实信号排行 · 后续实现', 'Ranked by real signals · Planned'],
    editors: ['编辑精选', 'Editor’s picks', '人工选择与编辑说明', 'Editorial choices and context'],
    recent: ['最近内容更新', 'Recently updated', '公开内容批准事件 · 后续实现', 'Public content approval events · Planned'],
    rated: ['高分 Skills', 'Top-rated Skills', '有效批准评价 · 后续实现', 'Valid approved reviews · Planned'],
    tutorials: ['入门教程', 'Getting started', '已发布原生教程', 'Published native tutorials'],
    contribute: ['投稿与共建', 'Contribute', '投稿入口与说明', 'Submission entry and guidance']
  };
  const initialModules = Object.keys(moduleLabels).map((id) => ({ id, enabled: id !== 'rated' }));
  const initialConfig = {
    style_id: 'modern-saas',
    locales: {
      zh: { title: '找到下一个让你事半功倍的 Skill', description: '探索经过整理的 AI Skills，把重复工作交给工具，把时间留给创造。', modules: copy(initialModules) },
      en: { title: 'Find your next unfair advantage.', description: 'Discover thoughtfully curated AI Skills. Spend less time on repetitive work, and more on what you create.', modules: copy(initialModules) }
    }
  };
  let config = copy(initialConfig);
  let liveConfig = copy(initialConfig);
  let savedConfig = copy(initialConfig);
  let contentLocale = 'zh';
  let liveRevision = 6;
  let draftRevision = 7;
  let revisionCounter = 7;
  let statusMessage = ['草稿已载入。可修改后保存或预览。', 'Draft loaded. Edit, save, or preview it.'];
  const revisions = new Map([[6, copy(initialConfig)], [7, copy(initialConfig)]]);
  let publicationCandidate = null;
  const dirty = () => JSON.stringify(config) !== JSON.stringify(savedConfig);
  const updateStatus = (zh, en) => {
    statusMessage = [zh, en];
    bilingual($('#home-state-message'), zh, en);
    bilingual($('#home-dirty'), dirty() ? '有未保存修改' : '草稿已保存', dirty() ? 'Unsaved changes' : 'Draft saved');
    $('#home-live-revision').textContent = `#${liveRevision}`;
    $('#home-draft-revision').textContent = `#${draftRevision}`;
  };
  const sendPreview = () => {
    const frame = $('#home-preview-frame');
    if (!frame.contentWindow) return;
    const locale = config.locales[contentLocale];
    const message = { type: 'aish-design-home', locale: contentLocale, modules: copy(locale.modules), title: locale.title, description: locale.description };
    // HTTP static previews use a same-origin target; file previews have an opaque origin.
    const targetOrigin = location.origin === 'null' ? '*' : location.origin;
    frame.contentWindow.postMessage(message, targetOrigin);
  };
  const renderModules = () => {
    const list = $('#home-modules');
    list.replaceChildren();
    config.locales[contentLocale].modules.forEach((module, index, all) => {
      const labels = moduleLabels[module.id];
      const core = module.id === 'hero' || module.id === 'search';
      const row = document.createElement('li');
      row.dataset.enabled = String(module.enabled);
      const number = element('span', String(index + 1).padStart(2, '0'), String(index + 1).padStart(2, '0'), 'wp-module-number');
      row.append(number);
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = module.enabled;
      input.disabled = core;
      const labelText = document.createElement('span');
      labelText.append(element('span', labels[0], labels[1], 'wp-module-name'));
      labelText.append(element('span', labels[2], labels[3], 'wp-module-detail'));
      label.append(input, labelText);
      input.addEventListener('change', () => {
        module.enabled = input.checked;
        row.dataset.enabled = String(module.enabled);
        updateStatus('修改尚未保存。线上示例保持原修订。', 'Changes are not saved. The live demo keeps its current revision.');
      });
      row.append(label);
      const controls = document.createElement('div');
      controls.className = 'wp-module-move';
      [-1, 1].forEach((direction) => {
        const button = element('button', direction === -1 ? '↑' : '↓', direction === -1 ? '↑' : '↓', 'wp-button');
        button.type = 'button';
        button.setAttribute('aria-label', `${direction === -1 ? (uiLang() === 'en' ? 'Move up ' : '上移 ') : (uiLang() === 'en' ? 'Move down ' : '下移 ')}${labels[uiLang() === 'en' ? 1 : 0]}`);
        button.dataset.move = `${module.id}:${direction}`;
        button.disabled = core || (direction === -1 ? index <= 2 : index === all.length - 1);
        button.addEventListener('click', () => {
          const target = index + direction;
          [all[index], all[target]] = [all[target], all[index]];
          renderModules();
          updateStatus('模块顺序已调整，尚未保存。', 'Module order changed; not yet saved.');
          const replacement = $$('[data-move]').find((node) => node.dataset.move === `${module.id}:${direction}`);
          if (replacement && !replacement.disabled) replacement.focus();
          else $('#home-modules input:not(:disabled)')?.focus();
        });
        controls.append(button);
      });
      row.append(controls);
      list.append(row);
    });
  };
  const renderContent = () => {
    const locale = config.locales[contentLocale];
    $('#home-title').value = locale.title;
    $('#home-description').value = locale.description;
    $$('[data-content-lang]').forEach((button) => {
      const active = button.dataset.contentLang === contentLocale;
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
    });
    $('#home-content-panel').setAttribute('aria-labelledby', `home-tab-${contentLocale}`);
    renderModules();
    updateStatus(...statusMessage);
  };
  $$('[data-content-lang]').forEach((button, index, tabs) => {
    button.addEventListener('click', () => { contentLocale = button.dataset.contentLang; renderContent(); });
    button.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs[tabs.length - 1] : tabs[(index + 1) % tabs.length];
      next.click();
      next.focus();
    });
  });
  [['#home-title', 'title'], ['#home-description', 'description']].forEach(([selector, field]) => {
    $(selector).addEventListener('input', (event) => {
      config.locales[contentLocale][field] = event.target.value;
      updateStatus('修改尚未保存。线上示例保持原修订。', 'Changes are not saved. The live demo keeps its current revision.');
    });
  });
  const validConfig = () => {
    if (config.locales.zh.title.trim() && config.locales.en.title.trim()) return true;
    updateStatus('请为中文和 English 都填写 Hero 标题；缺译不能作为可发布内容。', 'Provide a Hero title for both Chinese and English; missing translations cannot be published.');
    return false;
  };
  const addRevisionOption = (number) => {
    if (Array.from($('#home-revision').options).some((option) => Number(option.value) === number)) return;
    const option = element('option', `#${number} · 当前会话保存`, `#${number} · Saved this session`);
    option.value = String(number);
    $('#home-revision').prepend(option);
  };
  const saveDraft = () => {
    draftRevision = ++revisionCounter;
    savedConfig = copy(config);
    revisions.set(draftRevision, copy(config));
    addRevisionOption(draftRevision);
  };
  $('#home-save').addEventListener('click', () => {
    if (!validConfig()) return;
    saveDraft();
    updateStatus(`已保存内存草稿 #${draftRevision}，线上仍为 #${liveRevision}。`, `Memory draft #${draftRevision} saved; live remains #${liveRevision}.`);
  });
  $('#home-restore').addEventListener('click', () => {
    const selected = Number($('#home-revision').value);
    if (!revisions.has(selected)) return;
    config = copy(revisions.get(selected));
    saveDraft();
    statusMessage = [`已从 #${selected} 恢复为新草稿 #${draftRevision}，尚未发布。`, `Restored #${selected} as new draft #${draftRevision}; not published.`];
    renderContent();
  });
  $('#home-publish').addEventListener('click', (event) => {
    if (!validConfig()) return;
    publicationCandidate = copy(config);
    bilingual($('#home-publish-summary'), `将用这份中英文配置替换内存中的线上修订 #${liveRevision}。风格：Modern SaaS。`, `Replace memory live revision #${liveRevision} with this Chinese and English configuration. Style: Modern SaaS.`);
    const changes = $('#home-changes');
    changes.replaceChildren();
    ['zh', 'en'].forEach((locale) => {
      const current = config.locales[locale];
      const old = liveConfig.locales[locale];
      const count = current.modules.filter((module) => module.enabled).length;
      const changed = JSON.stringify(current) !== JSON.stringify(old);
      const title = locale === 'zh' ? '中文' : 'English';
      changes.append(element('li', `${title}：显示 ${count} 个模块；${changed ? '内容或顺序有改动' : '与线上示例相同'}。`, `${title}: ${count} visible modules; ${changed ? 'content or order changed' : 'same as the live demo'}.`));
    });
    changes.append(element('li', '同一风格配置覆盖两语言；内容与顺序仍独立。', 'One style covers both languages; their content and order stay independent.'));
    openDialog($('#home-publish-dialog'), event.currentTarget);
  });
  $('#home-confirm-publish').addEventListener('click', () => {
    if (!publicationCandidate) return;
    config = copy(publicationCandidate);
    saveDraft();
    liveConfig = copy(publicationCandidate);
    liveRevision = draftRevision;
    publicationCandidate = null;
    updateStatus(`已模拟发布修订 #${liveRevision}。只改变当前页面内存。`, `Simulated publication of revision #${liveRevision}. Only page memory changed.`);
    $('#home-publish-dialog').close();
  });
  $('#home-preview').addEventListener('click', (event) => {
    const frame = $('#home-preview-frame');
    const previewUrl = new URL('index.html', location.href);
    previewUrl.searchParams.set('embed', '1');
    previewUrl.searchParams.set('lang', contentLocale);
    frame.src = previewUrl.href;
    openDialog($('#home-preview-dialog'), event.currentTarget);
  });
  $('#home-preview-frame').addEventListener('load', sendPreview);
  $$('[data-preview-width]').forEach((button) => button.addEventListener('click', () => {
    $('#home-preview-frame').classList.toggle('is-mobile', button.dataset.previewWidth === 'mobile');
    $$('[data-preview-width]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
  }));
  const localizeDynamic = () => {
    renderModules();
    updateStatus(...statusMessage);
    if (currentModule) renderModuleDialog();
    $$('[data-i18n-aria-zh]').forEach((node) => node.setAttribute('aria-label', uiLang() === 'en' ? node.dataset.i18nAriaEn : node.dataset.i18nAriaZh));
    $$('[data-i18n-title-zh]').forEach((node) => node.title = uiLang() === 'en' ? node.dataset.i18nTitleEn : node.dataset.i18nTitleZh);
  };
  new MutationObserver(localizeDynamic).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
  renderContent();
  localizeDynamic();
  if (new URLSearchParams(location.search).get('view') === 'appearance') activatePage('appearance', false);
})();
