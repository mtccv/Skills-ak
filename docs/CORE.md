# AI Skills Hub Core 使用与维护

Phase 1 建立数据基础；Phase 2 在同一数据与模块体系上实现 WordPress 原生后台。完整字段与索引见总规格第 5 节，以及 `inc/core/Database/Schema.php`。WordPress 表前缀不是固定 `wp_`。

## 数据所有权

- `skills.id` 是永久内部 ID；中英文翻译和版本都引用这个 ID，不依赖 WordPress 文章或 WPML 对象 ID。
- `repositories` 规范化 GitHub owner/name（小写），一个仓库可对应多个 Skills。
- `UNIQUE(repository_id,source_path)` 对来源去重，路径按完整 UTF-8 字节比较，大小写不同的真实文件不会合并。根文件用 `SKILL.md`，子目录用 `directory/SKILL.md`，不要传 GitHub URL。
- 翻译以 `(skill_id,locale)` 唯一，别名 zh/zh-cn/en/en-us 归一化到 zh_CN/en_US。`(locale,slug)` 也唯一；Phase 2 允许不完整草稿的 slug 为 NULL；slug 冲突直接报错，绝不更新另一个 Skill 的译文。
- 版本以 `(skill_id,commit_sha)` 唯一，hash 由 Core 从来源原文计算；重复且相同的内容返回原 ID，内容冲突报错。原文保留用于未来审核，但不能当 HTML 直接输出。
- 分类有 nullable parent_id，标签非层级；两张关联表使用联合主键保证重复分配不会产生重复关系。
- 仅提供归档，不提供硬删除 API，不在主题升级、停用或切换时清理数据。关联完整性由 Catalog 的事务/行锁和引用校验维护；没有数据库外键，禁止绕过应用层任意写入。

## 调用接口

以下 PHP 仅在可信 WordPress 服务器代码中调用；写入必须存在拥有相关业务 capability（或兼容的 `manage_options`）的当前用户。CLI 写业务数据也须指定合法管理员用户，不能因为运行在 CLI 就跳过 Catalog 权限。

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

Core bootstrap 注册限定命名空间自动加载器，显式模块为 database、language、catalog、admin；ModuleLoader 先验证完整依赖图，拒绝重复、缺失或循环，再按顺序注册服务。`functions.php` 仅保留 Phase 0 主题启动代码，没有新增领域逻辑。

迁移仅在管理员 `admin_init`、授权的主题切换后请求或可信 WP-CLI 上下文运行。前台仅加载服务，不创建业务表。WP-CLI 的首次主题激活可能在下次 WordPress 加载时才执行迁移；README 的版本检查命令会加载主题并显示 `1.2.0`。后台失败提示为通用信息；管理员重新加载后台可重试。

1. 升级前备份数据库和代码；暂停可能写库的外部任务（本阶段没有任务）。
2. 使用 GET_LOCK 对当前数据库+站点表前缀加连接级锁，非阻塞争用返回 `aish_schema_busy`，不覆盖版本。
3. 按顺序执行 1.0.0（八张基础表）→1.1.0（版本标签字段）→1.2.0（人工来源、审核/修订、双语 SEO、归档），每步由 dbDelta 比较现有结构。
4. 检查所有列、永久 ID 类型、完整唯一索引、路径二进制类型、InnoDB 和 utf8mb4；成功后才存非 autoload 的 `aish_schema_version`。
5. 失败保留最后成功版本，可能存在部分 DDL；修复数据库后重试。MySQL DDL 不承诺事务回滚。拒绝未知版本、降级，不自动删除列或历史内容。

当前版本会直接跳过 DDL；意外手工删表不通过降低版本来隐式“修复”，应先备份、调查并由管理员明确恢复。恢复完整备份是回退方式，旧代码不能读取更新 schema 时会拒绝业务操作。多站点使用当前站点前缀/选项，网络启用不自动遍历所有站点。

## 后续 WPML 接口

`aish_core_language_provider` filter 可注入实现 `LanguageProvider` 的对象，提供 `supportedLocales()`、`normalize()`、`externalObjectId(skill_id,locale)`。内置提供者仅支持 zh_CN/en_US，externalObjectId 为 null。外部 ID 用于未来适配层，不能改变 Core 的主 ID 或唯一翻译行。

目前只以替身验证接口，不检测/调用 WPML 插件，不修改 URL/rewrite，不提供翻译生成。真实 WPML 适配、插件许可和迁移联调均未执行。

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

## 验证与 Final QA

本阶段执行 PHP 语法、前端构建与基本代码审查，结果见 PHASE_2_REPORT.md。完整 MySQL、WordPress、WPML、浏览器、并发和端到端测试按用户要求统一留到 Final QA。Phase 1 报告中的 110 项断言和 CI 成功是历史证据，不代表新增后台已集成通过。

现有 `tests/integration/core.php` 创建随机 `aish_t_<12 hex>_` 前缀表、专用版本选项和临时 subscriber，finally 仅删除这些测试对象；会切换主题后恢复，只能在可丢弃的开发/CI 站点运行，禁止生产运行。Phase 2 更新了模块数量、当前迁移和发布约束等基线预期，尚未重新执行。

Final QA 须覆盖 1.1→1.2 实际迁移、重复升级、人工多记录 NULL 来源、草稿 NULL slug、两种语言唯一性、过期保存、审核与编辑竞争、拒绝与归档恢复、角色矩阵、nonce/HTTP 方法、批量部分失败、分类循环及归档关系、预览缓存/越权、SQL/XSS、WordPress 编辑器、浏览器无 JS 操作及 WPML 真实联调。仅手动执行 CI `final_qa=true` 的旧基线不足以宣称所有项目完成。
