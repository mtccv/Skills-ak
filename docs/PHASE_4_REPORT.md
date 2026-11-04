# Phase 4 — 搜索与 SEO 开发报告

以 GitHub main 最新 `729b0a92a7659d7bda5bbafe10a76a5f49a512b0` 为基线。开始时工作区干净，已读取总规格、Core、Frontend、进度和 Phase 1–3 报告。用户已验收 Phase 3，本轮仅开发搜索与 SEO；没有采集、AI 自动生成或外部部署。

## 完成内容

- Skills / 教程当前语言的标题、摘要、正文关键词搜索；分类、标签、平台组合筛选，确定性排序、分页和空状态，保留合理搜索参数。
- 公开查询继续强制已发布、当前修订审核及真实译文；教程按原生发布状态、密码、明确语言与实际内容限制。教程标签筛选复用关联的公开 Skill，不另建分类存储。
- 请求内查询/计数复用、批量关联卡片及原生元数据/作者预取。关闭无法感知 Core 归档的 WordPress 持久查询结果缓存。
- 原生双语 SEO 配置页面及文章 metabox；Skill 沿用既有翻译表 SEO 字段，其他配置使用 options/postmeta。Title、Description、self Canonical、Open Graph、Twitter Card。
- 当前语言独立 Canonical，真实且可索引译文的 hreflang / x-default；缺译不伪造。WPML 官方对象映射 hook、语言代码适配、站点根路径保护及原有 URL/request 接口。
- 独立双语分页 XML Sitemap；公开 Skills、有效分类/标签、教程和必要静态页面，真实 lastmod，排除 noindex、私有、缺译、搜索和筛选组合。接入 WordPress、Yoast、Rank Math 索引，排除托管原生 page 重复地址。
- 搜索/筛选/排序、投稿结果、私有预览、后台和错误页索引策略；动态 robots.txt 保留原生规则及插件 Sitemap，关闭站点可见性时禁止抓取。没有创建覆盖 WordPress 的物理 robots.txt。
- Hub SSR 页面统一 Meta 输出所有权，避免与 Yoast/Rank Math/WPML 重复输出；保留其他 WordPress 页面的插件处理。

## 数据与升级

主题/Core **0.5.0**，schema **1.3.0**。已在实施前说明索引调整理由：只追加 `skills(status,review_status,updated_at,id)` 和 `skill_translations(locale,title,skill_id)` 两个非唯一索引；八表、字段、永久 ID、来源唯一键及数据不变。没有删除数据或修改 functions.php 业务边界。

索引通过既有版本化迁移、锁和实际结构验证安装，前台不运行 DDL。更新后需由授权管理员后台/可信 WP-CLI 完成升级；失败不虚报版本。新增索引未运行真实 MySQL 迁移或 EXPLAIN，不能宣称性能基准已通过；中文子串仍使用候选扫描。

## 文件清单

新增：

- `inc/core/Frontend/Seo.php`、`SeoSettings.php`、`Sitemap.php`。
- `tests/basic/public-search.php`、`editorial-search.php`、`seo.php`、`sitemap.php`。
- `docs/SEO.md`、本报告。

修改：

- `inc/core/Frontend/PublicCatalog.php`、`Editorial.php`、`Router.php`、`Forms.php`、`Strings.php`。
- `inc/core/Database/Schema.php`、`Migrator.php`。
- `inc/core/Modules/FrontendModule.php`、`inc/core/bootstrap.php`、`style.css`。
- `templates/frontend/listing.php`、`tutorials.php`。
- `tests/basic/frontend-routing.php`、`.github/workflows/checks.yml`。
- `AGENTS.md`、`CODEX_MASTER_SPEC.md`、`README.md`、`docs/CORE.md`、`FRONTEND.md`、`PROGRESS.md`。

主题相对路径均基于 `wp-content/themes/ai-skills-hub/`。构建产物遵循既有忽略策略，由源码和 lock 在 Cloud/CI 生成；未提交凭据、运行数据库或依赖目录。

## 已实际执行的检查

| 检查 | 结果 |
| --- | --- |
| Cloud PHP 8.3.36 语法 | 51 个 PHP 文件全部通过（45 个主题、6 个检查脚本） |
| 隔离 PHP 基础检查 | 342 项通过：路由 97、公开搜索 95、教程搜索 49、SEO 64、Sitemap 37 |
| Tailwind + esbuild | 构建通过 |
| 资源检查 | 通过；CSS 46069 bytes，JS 56108 bytes |
| JavaScript / Bash 语法 | 通过 |
| Git diff 空白与基本代码复核 | 通过；修正持久查询缓存、空正文计数、Sitemap 路由/XML 样式保留、禁用 Sitemap 广告及语言前缀重复等问题 |

检查使用 Cloud 缓存官方 WordPress/PHP 镜像，只读挂载源码；没有启动 MySQL。Node 24.22.1、npm 11.9.0。Browserslist 仍提示既有 caniuse-lite 数据较旧，构建成功，未为消除提示变更依赖。

342 项检查使用记录 SQL 或 WordPress 函数替身，验证输入、查询合同、URL/输出及策略边界，**不是 MySQL、WordPress 或插件集成验收**。另核对了原生 Sitemap Provider 的继承接口，不能据此声称整个站点已运行通过。

## 未执行与验收限制

完整 MySQL/WordPress、1.2→1.3 实际升级和重复升级、查询执行计划/大数据性能、持久缓存和并发撤稿、真实 WPML/Yoast/Rank Math、浏览器及端到端测试均未执行，按用户策略留 Final QA。完整待测清单见 SEO.md。

WPML 多域名/自定义路由仍需使用已有 request/URL 适配接口，未宣称安装插件后所有模式自动通过；分类名称共享、不生成译文。About/Contact 无实际语言正文时保持可访问但 noindex。只有真实内容才进入 Sitemap。物理 robots.txt 由服务器返回时不会经过主题 filter，需要 Final QA 核对真实 HTTP 响应。

代码及报告将正常提交并同步 GitHub main；具体实现 SHA、CI 链接和最终交付 SHA 在提交完成后补记。完成 Phase 4 后暂停，等待下一阶段指令。
