# AI Skills Hub Core 使用与维护

Phase 0–4 已验收；Phase 5 在既有数据、后台、双语前台、搜索及 SEO 基础上追加 GitHub Skills 自动采集。当前主题/Core 为 0.6.0，schema 为 1.4.0：保留原八张核心表及其 ID/关联，扩展仓库和来源版本元数据，并增加 imports/jobs/import_logs 三张运营表。完整字段与索引见总规格第 5 节，以及 `inc/core/Database/Schema.php`。WordPress 表前缀不是固定 `wp_`。AI 自动翻译/生成留到尚未授权的 Phase 6。

## 数据所有权

- `skills.id` 是永久内部 ID；中英文翻译和版本都引用这个 ID，不依赖 WordPress 文章或 WPML 对象 ID。
- `repositories` 规范化 GitHub owner/name（小写），一个仓库可对应多个 Skills；1.4.0 绑定 nullable 唯一 `github_id` 以识别同名仓库重建，但所有 Skill 仍引用原内部 repository_id。
- `UNIQUE(repository_id,source_path)` 对来源去重，路径按完整 UTF-8 字节比较，大小写不同的真实文件不会合并。根文件用 `SKILL.md`，子目录用 `directory/SKILL.md`，不要传 GitHub URL。
- 翻译以 `(skill_id,locale)` 唯一，别名 zh/zh-cn/en/en-us 归一化到 zh_CN/en_US。`(locale,slug)` 也唯一；Phase 2 允许不完整草稿的 slug 为 NULL；slug 冲突直接报错，绝不更新另一个 Skill 的译文。
- 版本以 `(skill_id,commit_sha)` 唯一，hash 由 Core 从来源原文计算；重复且相同的内容返回原 ID，内容冲突报错。1.4.0 采集快照另存 file_sha/repository_id/source_path，旧快照保留未知绑定；原文只能作为私有纯文本证据，不能当 HTML 直接输出。
- 分类有 nullable parent_id，标签非层级；两张关联表使用联合主键保证重复分配不会产生重复关系。
- 仅提供归档，不提供硬删除 API，不在主题升级、停用或切换时清理数据。关联完整性由 Catalog、Management、ImportStore 的事务/行锁和引用校验维护；没有数据库外键，禁止绕过应用层任意写入。来源 missing 不等于删除 Skill 或自动撤销已发布页面。

## 调用接口

以下 Catalog PHP 仅在可信 WordPress 服务器代码中调用；写入必须存在拥有相关业务 capability（或兼容的 `manage_options`）的当前用户。CLI 调用 Catalog 也须指定合法管理员用户，不能因为运行在 CLI 就跳过权限。Phase 5 的 ImportStore 后台执行接口另限定 DOING_CRON/WP_CLI、活动任务租约和来源校验，不将该入口暴露给 HTTP 或伪装管理员；它只能保存来源证据及未发布的新 Skill，不能调用人工发布流程。

```php
$core = AISkillsHub\Core\Core::boot();
$catalog = $core->catalog();
$repository_id = $catalog->createRepository('openai', 'skills');
$skill_id = $catalog->createSkill($repository_id, 'review/SKILL.md');
$catalog->saveTranslation($skill_id, 'zh_CN', '代码审查', 'code-review', '中文摘要', '<p>说明</p>');
$catalog->saveTranslation($skill_id, 'en_US', 'Code Review', 'code-review', 'Summary', '<p>Description</p>');
$translation = $catalog->getTranslation($skill_id, 'zh_CN');
echo AISkillsHub\Core\Security\Output::text($translation['title']);
echo AISkillsHub\Core\Security\Output::rich($translation['content']);
```

其他接口：`getRepository`、`getSkill`、`findSkillBySource`、`listSkills`、`addVersion`、`versionsForSkill`、`createCategory`、`createTag`、`assignTerm`、`termsForSkill`。Skills 列表和版本历史默认每页 20 项，上限 100，offset 上限 1000000；列表排序只接受 id/created_at/updated_at 和 ASC/DESC，并以 ID 处理同值排序。查询结果为数据库字段数组（ID 由 wpdb 返回数字字符串，关联/写接口接受正 int）；`create*` 和 `saveTranslation` 返回 int ID。API 不通过请求自动执行，不进行网络访问。

错误类别：无效输入抛 InvalidArgumentException；权限/引用缺失/版本冲突抛 DomainException；数据库操作失败抛 RuntimeException，错误不含 SQL/凭据。Phase 2 admin-post 控制器校验方法、nonce 和 capability，再调用 Management 服务；内部 Database DAL 不承担 HTTP 授权，不允许直接接收用户 SQL、表名或列名。

输出在最后一层按上下文处理：`Output::text/attribute/url/rich/json`。数据入库不预先 HTML 转义，中文不会双重编码；富文本入库和输出都使用 WordPress KSES 白名单。

## 生命周期与升级

Core bootstrap 注册限定命名空间自动加载器，显式模块为 database、language、catalog、admin、frontend、sources；ModuleLoader 先验证完整依赖图，拒绝重复、缺失或循环，再按顺序注册服务。SourcesModule 依赖 database/catalog/admin，使用同一个 DAL/Migrator，不重建领域系统。`functions.php` 仅保留主题启动代码，没有新增领域逻辑。

迁移仅在管理员 `admin_init`、授权的主题切换后请求或可信 WP-CLI 上下文运行。前台仅加载服务，不创建业务表。WP-CLI 的首次主题激活可能在下次 WordPress 加载时才执行迁移；README 的版本检查命令会加载主题并显示当前 `1.4.0`。后台失败提示为通用信息；管理员重新加载后台可重试。采集仅在 schema 就绪后运行。

1. 升级前备份数据库和代码；暂停可能写库的任务与 Cron 执行，等待活动步骤结束；不要仅靠关闭定期同步来假设既有队列已取消。
2. 使用 GET_LOCK 对当前数据库+站点表前缀加连接级锁，非阻塞争用返回 `aish_schema_busy`，不覆盖版本。
3. 按顺序执行 1.0.0（八张基础表）→1.1.0（版本标签字段）→1.2.0（人工来源、审核/修订、双语 SEO、归档）→1.3.0（两个非唯一搜索索引）→1.4.0（仓库/快照扩展及三张采集运营表），每步由 dbDelta 比较现有结构。
4. 检查所有列、永久 ID 类型、完整唯一索引、路径二进制类型、InnoDB 和 utf8mb4；1.3.0 另校验搜索索引完整列顺序及非唯一属性，1.4.0 校验 GitHub 身份、运营唯一键及可空字段。历史迁移验证不提前要求未来字段/表；成功后才存非 autoload 的 `aish_schema_version`。
5. 失败保留最后成功版本，可能存在部分 DDL；修复数据库后重试。MySQL DDL 不承诺事务回滚。拒绝未知版本、降级，不自动删除列或历史内容。

当前版本会直接跳过 DDL；意外手工删表不通过降低版本来隐式“修复”，应先备份、调查并由管理员明确恢复。恢复完整备份是回退方式，旧代码不能读取更新 schema 时会拒绝业务操作。多站点使用当前站点前缀/选项，网络启用不自动遍历所有站点。

## WPML 接口与实施边界

`aish_core_language_provider` filter 可注入实现 `LanguageProvider` 的对象，提供 `supportedLocales()`、`normalize()`、`externalObjectId(skill_id,locale)`。内置提供者仅支持 zh_CN/en_US，externalObjectId 为 null。外部 ID 用于未来适配层，不能改变 Core 的主 ID 或唯一翻译行。

Phase 1 的 LanguageProvider 只以替身验证接口。Phase 4 的 Editorial 会在公开 `wpml_object_id` hook 可用时查找教程对照文章，要求不返回原文来冒充缺译，并继续校验真实语言、公开状态及双向关系。`aish_wpml_language_code` 可调整 zh-hans/en 代码映射；Core Skills 仍由同一内部 ID 的译文行决定关系。既有请求、URL 和语言地址 filters 允许适配 WPML 路由模式，详见 FRONTEND.md。未提供翻译生成，真实插件联调与迁移验收仍待 Final QA。

## Phase 2 管理服务与数据规则

`$core->management()` 提供后台服务，依赖已有 Database、Migrator、Catalog，不直接通过 HTTP 暴露 DAL。`AdminModule` 在 Core 注册 Management、Actions 和 Pages，业务代码不进入 functions.php。列表使用原生 WP_List_Table，正文使用 wp_editor，设置通过受授权的 admin-post 保存到 WordPress options。

主要服务：

- `dashboard()`、`skills(filters)`、`skill(id)`：统计、分页列表、实体与双语内容及分类/标签 ID。
- `saveSkill(?id, data)`：单事务保存实体、两种译文与关联；`revision` 防过期覆盖；`save_mode` 为 draft 或 pending。人工来源为 repository_id/source_path 同时 NULL，有来源时仍严格去重。
- `review(id, decision, risk, notes, revision)`：仅待审核当前修订可 approved/rejected；批准必须有至少一种完整译文，批准后 published；记录当前用户和 UTC 时间。完整译文要求标题、slug、简介、正文，SEO 字段可选。
- `archiveSkill(id, revision)`、`changeStatus(id, draft|pending, revision)`：保留数据的状态变更；HTTP 归档需二次确认。批量操作不提供直接发布或批量审核。
- `listTerms/saveTerm/archiveTerm`、`listRepositories/saveRepository/archiveRepository`：分类、标签、仓库手动管理，支持分页、搜索、排序及归档。已关联 Skills 的仓库禁止改 owner/name；分类拒绝循环层级。

权限按 `aish_manage_skills`、`aish_review_skills`、`aish_manage_taxonomies`、`aish_manage_repositories`、`aish_manage_settings` 分开，兼容 manage_options；默认只授予管理员。CLI 业务调用也需要合法用户。Catalog 旧入口不允许直接 createSkill(..., published) 绕过审核；翻译或 Skill 的分类/标签关联修改会使审核失效。

1.2.0 仅扩展原八表。Skills 的 `revision`、`review_status`、`risk_level`、`reviewer_id`、`reviewed_at`、`reviewed_revision`、`review_notes` 记录当前修订与最近一次审核；不是无限历史审计日志。旧 published 且缺少当前审核证据的记录转待审核；数据本身保留。内容修改后旧审核人员、时间、备注仍可显示，但旧 reviewed_revision 不再授权发布。

Translations 新增 `seo_title`（191 字符）、`seo_description`（320 字符），slug 允许 NULL 以保存部分草稿；缺失语言返回 null，不复制或编造另一种语言。Repositories/categories/tags 增加 is_archived，不删除原关联。归档旧关联可以保留，不能随意新增到已归档对象。

预览通过受保护的 admin-post 动作返回阅读视图，要求 Skills 管理或审核 capability、nonce，发送私有/no-store/noindex 标记。使用指定语言，缺译显示真实缺失，不做语言内容回退。不注册公开路由或详情模板。

## 历史 Phase 2 验证与 Final QA

Phase 2 执行 PHP 语法、前端构建与基本代码审查，历史结果见 PHASE_2_REPORT.md。完整 MySQL、WordPress、WPML、浏览器、并发和端到端测试按用户要求统一留到 Final QA。Phase 1 报告中的 110 项断言和 CI 成功是历史证据，不代表新增后台已集成通过。

现有 `tests/integration/core.php` 创建随机 `aish_t_<12 hex>_` 前缀表、专用版本选项和临时 subscriber，finally 仅删除这些测试对象；会切换主题后恢复，只能在可丢弃的开发/CI 站点运行，禁止生产运行。Phase 2 更新了模块数量、当前迁移和发布约束等基线预期，尚未重新执行。

Final QA 须覆盖 1.1→1.2 实际迁移、重复升级、人工多记录 NULL 来源、草稿 NULL slug、两种语言唯一性、过期保存、审核与编辑竞争、拒绝与归档恢复、角色矩阵、nonce/HTTP 方法、批量部分失败、分类循环及归档关系、预览缓存/越权、SQL/XSS、WordPress 编辑器、浏览器无 JS 操作及 WPML 真实联调。仅手动执行 CI `final_qa=true` 的旧基线不足以宣称所有项目完成。

## Phase 3 Frontend 模块

`FrontendModule` 注册 PublicCatalog、Editorial、Forms、Router；通过 `$core->get('public_catalog')`、`$core->get('editorial')`、`$core->get('frontend')` 取得对象。`functions.php` 仍只启动主题与资源。

PublicCatalog 是受限只读投影，所有对外 Skills/计数/语言备选只读取已发布且当前修订审核通过的记录；不把管理用 Catalog 直接暴露给 HTTP。Phase 3 没有修改八表、Schema/Migrator、Catalog、Management 或既有 LanguageProvider，交付时 schema 为 1.2.0。Phase 4 的新增索引见下节。

教程、说明页及私有投稿只复用 WordPress 原生 posts/postmeta/options；没有 Skills CPT 或另一套 Skill ID。公开版本 SHA 仅为审核时间之前记录的历史参考。1.4.0 虽增加新快照的来源绑定，仍未绑定人工审核修订，旧快照也可能缺少来源，不能宣称已审核源码。平台、公开许可证、精选继续使用人工标签，采集证据不直接投影到前台。

完整路由、数据映射、私有表单及多语言 hooks 见 FRONTEND.md。Phase 3/4 的历史检查分别见 PHASE_3_REPORT.md、PHASE_4_REPORT.md；本轮记录见 PHASE_5_REPORT.md。真实数据库公开查询、重写、表单持久化、浏览器和 WPML 尚待 Final QA，不能沿用 Phase 1 集成结果宣称通过。

## Phase 4 搜索、SEO 与索引

业务仍在 `FrontendModule` 组装，没有新增平行 Core 或把逻辑放入 functions.php。既有 public_catalog/editorial/frontend 服务保留；模块增加 SeoSettings、Seo、Sitemap 的 hooks。所有 SEO 能力都建立在已通过公开门禁的 Router context 或受限 Sitemap 查询上。

新增及增强的内部 API：

| API | 行为 |
| --- | --- |
| `PublicCatalog::skills(locale, filters)` | 当前语言标题/简介/正文的字面子串搜索；分类/标签/平台 AND 条件、稳定排序和分页；count 无无用仓库 join |
| `cardsByIds(ids, locale)` | 最多 24 个正整数 ID，去重后保持调用顺序；仅公开卡片；一组资源查询加两组分类/标签关联查询，不逐条加载详情/版本 |
| `matchingIdsSql(locale, filters)` | 为 Editorial 内部组合筛选生成已验证且 prepare 的 SELECT 子查询；不是 HTTP/外部 SQL 接口，不能接受原始 SQL |
| `PublicCatalog::sitemapCount(kind, locale)` / `sitemapItems(kind, locale, page, perPage)` | kind 为 skill/category/tag（兼容 skills 别名）；noindex 在 COUNT/LIMIT 前排除；仅返回 id/slug/updated_at，默认每页 200 |
| `Editorial::tutorials(locale, filters)` | 原生 WP_Query，通过 query-token 范围内的 get_meta_sql 增加真实内容/关键词和关联公开 Skill 条件；保持 locale/publish/无密码限制 |
| `Editorial::sitemapCount(locale)` / `sitemapItems(locale, page, perPage)` | 原生已发布教程的语言/完整性/noindex 分页，使用真实 UTC modified 时间 |
| `SeoSettings::values(route, locale, id)` / `postValues(postId)` | 双语 options 配置和原生教程/页面 postmeta；Skill title/description 始终由审核翻译字段拥有 |
| `Seo::prepare(context, query)` | 当前语言 Meta、索引策略、真实 alternate/x-default；插件去重只针对主题接管的 Hub 路由 |

公开查询只使用请求内缓存，不持久保存结果；PublicCatalog 最多保留 128 个请求内条目。Editorial 关闭 WP_Query 持久结果缓存，因为自定义 Skill 归档不更新 WordPress posts 的缓存代际；文章元字段和作者仍批量预取，避免 N+1。没有给字段补假译文或为了搜索创建 Skill 副本。

schema **1.3.0** 仅追加：

- `skills.public_updated(status, review_status, updated_at, id)`：公开时间排序候选访问路径。
- `skill_translations.locale_title(locale, title, skill_id)`：语言及标题排序候选访问路径。

既有唯一键、字段和内容均不变；索引的 utf8mb4 字节长度低于 MySQL/InnoDB 限制。迁移继续采用管理员/WP-CLI 生命周期、锁、dbDelta 与实际 schema 验证；普通前台不执行 DDL。必要性来自现有查询条件与排序结构，不等于已经实测性能；`%关键词%` 和完整性正则仍可能扫描候选行，真实 EXPLAIN/迁移/并发/规模测试留 Final QA。

SEO 配置使用 `aish_seo_settings` 非 autoload option（按 locale/route/id，最多 1000 条非默认覆盖）和 `_aish_seo_title`、`_aish_seo_description`、`_aish_seo_image`、`_aish_seo_noindex` 原生文章元数据。未增加业务表。options 保存校验 capability/nonce、真实实体、锁与当前设置指纹；Skill SEO 文本仍走原有编辑与审核流程，另行分享图片/noindex 不赋予发布权限。具体配置、robots.txt 和插件接管说明见 SEO.md。

Phase 4 历史检查以 PHASE_4_REPORT.md 为准；用户已验收，Phase 5 保留本节全部功能。隔离 PHP 合同断言不会执行 MySQL 或加载 WordPress，不能作为真实 WP_Query、迁移、WPML/Yoast/Rank Math 或 XML HTTP 行为通过的证据。

## Phase 5 Sources 模块与数据边界

`SourcesModule` 注册 `imports`（ImportStore）、`import_worker`（Worker）、`import_scheduler`（Scheduler）三个 Core 服务和 GitHub Imports 原生后台。GitHubClient 负责唯一外部 HTTP 通道，Discovery 为可序列化树遍历状态机，Frontmatter 为安全 YAML 子集，RiskInspector 为文本/树元数据静态提示；它们不执行来源代码，不生成译文，也不替代原 Management 人工审核。

1.4.0 的新增字段及三表是可恢复采集的运行记录，不是另一套内容库：

| 结构 | 新增内容与规则 |
| --- | --- |
| repositories | github_id nullable 唯一、default_branch、sync_enabled（默认关闭）、sync_interval（默认 86400 秒）、last_sync_at、last_sync_commit、last_scan_at、sync_status、license_spdx（最后完整成功的许可证基线）；仍使用原 id/owner/name |
| skill_versions | file_sha、repository_id/source_path 可空来源绑定；保留 UNIQUE(skill_id,commit_sha) 和不可变原文，历史绑定不回填 |
| imports | 引用原 skill_id/repository_id/source_path，唯一 Skill 和来源；保存 file_sha/commit_sha、source_status/change_status、metadata_json/findings_json、last_seen_job 与时间 |
| jobs | repository_id、scan/sync、status、nullable unique active_key、state_json、attempts/available_at、lease_token/locked_until、deadline_at、时间及 last_error；同仓库只有一个活动任务 |
| import_logs | job/repository/可选 Skill 和路径、level/code/message/时间；固定脱敏事件，每任务最多 1000 条，同事件幂等 |

`ImportStore::saveRepository()`、`configureRepository()`、`archiveRepository()`、`enqueue()` 供受授权后台使用；仓库管理需要 `aish_manage_repositories` 或兼容管理员能力。定期调度使用限定后台上下文的 enqueue，不能由访客选择调度参数。读取仓库/任务/来源/日志的管理 API 也检查管理能力；“来源已处理”另需 `aish_review_skills`，校验 Commit 和来源版本指纹，不能绕过发布审核。HTTP 入口统一 POST、nonce、capability 与长度/枚举验证；归档要求二次确认。

Worker 写入经过 `claim/checkpoint/retry/finish` 和随机租约 token，过期或撤销的 worker 不能继续写。`record()` 在事务中按 repository_id+source_path 查询并锁定原 Skill/来源；若人工修改导致原绑定不一致则停止。新记录固定 pending/review pending，不插入翻译行；同源更新只追加快照和 incoming 元数据，不修改人工标题、译文、slug、审核或已发布状态。相同文件 SHA 不重复创建原文版本，但新 Commit 的目录/依赖/许可证变化会重新静态检查并形成 inspection_updated。

增量同步的提前结束还要求 `baselineReusable()` 确认同仓库最近前序任务为 complete，当前 Commit 与 last_sync_commit 相同，且许可证相对最后完整成功基线未变化。前序任务失败、不完整或取消后，即使上游回退到原成功 SHA，也必须重新检查，不能让部分写入被误判为完整结果。

仅完整清单与所有文件零错误后，`completeInventory()` 标记缺失来源并保存幂等核对标记；`finish(complete)` 同时验证完成条件。来源 missing/restored/possible_move 不删除记录、不改内部 Skill ID、不自动下架页面，须人工处理。只有 complete 的收尾事务才原子更新仓库 last_sync_at/last_sync_commit/license_spdx；`updateRepository()` 获取元数据时只推进身份与分支，不提前改写许可证成功基线。last_scan_at 表示任务排队时间。归档仓库取消活动任务并禁用采集，保留所有数据。

## Phase 5 调度、发现与安全限制

Cron `aish_import_tick` 计划每 60 秒触发，使用站点级 GET_LOCK 保证同一时间只有一个 worker；每次最多为 20 个到期仓库排队，只执行一个步骤。状态依次 repository → commit → tree → files → finalize，tree/file 可多次停留并持久化队列/游标；步骤成功后至少延迟 60 秒。执行网络的步骤最多两个 15 秒 HTTP 请求，普通前台请求不执行扫描。120 秒租约、同仓库活动唯一键、claim 锁和 token 校验处理重复触发；同一步连续失败最多 5 次，任务截止 48 小时。成功检查点重置失败计数，故这不是整个仓库最多 5 个步骤。

手动批量最多 10 个仓库，新任务间隔至少 60 秒；定期间隔可设 3600–604800 秒，默认关闭。WordPress Cron 依赖请求，实际启动可能延迟；不假设 Action Scheduler 已安装。切换主题清理 Cron 事件而不删除队列数据。升级/排障时应停止执行器，关闭某仓库定期同步只阻止未来周期，不等于取消既有手动任务。

GitHubClient 只访问固定 api.github.com HTTPS REST 路径，安全 HTTP API、禁止重定向/压缩、TLS 验证、响应大小限制；可选 Token 仅服务器常量/环境读取。API 必须确认仓库公开、GitHub 数字身份匹配；跨步骤重新验证身份，之后按固定 Commit/Tree/Blob SHA 读取并校验 Git blob 哈希。限流状态、Retry-After 和指数退避入队，不在请求中阻塞等待。详细大小限制及错误说明见 [GITHUB_IMPORT.md](GITHUB_IMPORT.md)。

Discovery 单树最多 10000 条元数据、500 个 SKILL.md、32 段路径、1000 次树请求和 2 MiB 状态；完整任务 JSON 最多 4 MiB。递归 truncated/响应超限丢弃部分清单，回退固定 SHA 的非递归逐层遍历；非递归仍截断或任何上限/路径异常均不报告完整。符号链接/子模块不遍历，候选只接受精确名称及普通 blob。每文件最多 512 KiB，超大或解析失败记录问题并继续其他候选，整个任务仍不完整且不得推断删除。

Frontmatter 只支持白名单字符串、引号/多行块及扁平 metadata，拒绝执行型标签、锚点/别名/合并、重复键和不支持的类型；name/description 必填，嵌套目录名须匹配 name。RiskInspector 只读原文及目录树元数据，检查 License、依赖/脚本/外连/特权命令/提示注入/异常路径和大小；未知许可证待核实。它不读取所有脚本内容、不运行命令、不安装依赖，结果不是绝对安全认证。原文按纯文本转义，只在受限后台展示，不供 SEO/前台直接消费。

本轮模拟 API、纯状态机和数据库替身检查的实际结果见 PHASE_5_REPORT.md；真实 GitHub API、MySQL 1.3→1.4/重复迁移、WordPress Cron/权限/浏览器/并发租约/端到端及 WPML 回归均留 Final QA。操作和安全边界分别见 [GITHUB_IMPORT.md](GITHUB_IMPORT.md)、[IMPORT_SECURITY.md](IMPORT_SECURITY.md)。完成 Phase 5 提交同步后暂停，等待 Phase 6。
