# Phase 3 — 中英文双语前台开发报告

日期：2026-11-05（Asia/Shanghai）。基于已验收 Phase 2 的 `e154d9aec1eee4cb7b5f25e62a8e0277f94fdd16`，开发前已核对 origin/main 一致且工作区干净。本轮只实现用户授权的 Phase 3，完成后暂停等待 Phase 4。

## 已实现

- 原创 SaaS 风格主题：统一浅色/深色正文/紫色与薄荷色设计、几何品牌 Logo、响应式导航、Hero、搜索、卡片、按钮、表单、面包屑及内容阅读排版；Tailwind CSS + Alpine.js + PHP SSR + WordPress 原生模板。
- 12 类页面：首页、Skills 列表、Skill 详情、分类、标签、教程列表、教程详情、搜索、About、Contact、Submit a Skill、404。共用模板组件而非复制 12 套数据逻辑。
- 首页从真实数据库展示精选、最新 Skills、分类计数、实际平台标签、已发布教程与投稿入口；无内容时显示真实空状态，没有虚构统计、评价、资源或示例译文。
- Skills 列表包含关键词、分类、标签、平台筛选、排序、分页与无结果状态。教程支持搜索、排序及分页；综合搜索提供匹配教程入口。
- 详情包含实际译文、仓库及路径、许可证/平台标签、历史版本元数据、更新时间、分类/标签、使用说明区块、风险和公开审核时间、相关 Skills/教程。
- `/zh/`、`/en/`、205 项中英文前台文案、真实对应译文链接、缺译提示与回退；不在英文地址伪装显示中文内容。WPML 保留语言提供者、请求/URL/语言对应 hooks，未宣称真插件联调成功。
- 所有公开 Skills 查询限制已发布、批准有效且审核对应当前修订；列表、计数、详情和语言替代入口共用条件。内部审核备注、审核人员身份和来源原始快照不返回前台。
- 教程使用原生 `aish_tutorial` 与 postmeta，说明页使用原生 page；关闭教程原生公开查询/REST 出口，由受限双语路由展示。
- 用户确认的 Contact/投稿流程：POST + nonce + 输入校验/蜜罐/限流，实际保存到 `aish_message` 私有收件箱，管理员人工处理，不发送邮件、不创建或发布 Skill。
- 基础 SEO：语义标题、独立页面标题/Meta Description、Canonical/hreflang 接口、面包屑；高级 SEO、完整 Sitemap 与索引策略未开发。

## 数据与兼容边界

核心 schema 仍为 **1.2.0**；没有修改 Phase 1 Database/Schema/Migrator/Catalog、Phase 2 Admin、既有 LanguageProvider，没有新建业务表或 Skills CPT。主题/Core 版本为 **0.4.0**；`functions.php` 只更新过时注释，业务仍由模块加载。

平台使用既有 `platform-*` 标签，许可证使用 `license-*`，精选使用 `featured`；分类/标签名称使用原表实际名称，不自动翻译。使用场景/安装/依赖从已存译文的 H2 区块提取，未提供则明确显示缺失。Skill 没有独立作者字段，作者显示未提供，仓库维护者另列。

现有版本表没有来源路径/审核修订绑定，因此 SHA 只能作为审核时间之前的历史记录，不能证明当前来源已经通过源码验证。来源链接使用 HEAD，原始快照不公开。没有网络采集或 AI 请求。

首次管理员后台访问初始化双语重写规则；普通固定链接改为 `/%postname%/`，已有自定义结构保留。前台不执行 DDL 或刷新规则。公开 HTML 暂时发送 no-store，缓存性能及撤稿并发需要 Final QA 验证。WordPress/服务器实际重写效果本阶段未运行验证。

## 文件清单

新增：

- `inc/core/Frontend/`：PublicCatalog.php、Editorial.php、Forms.php、Router.php、View.php、Strings.php。
- `inc/core/Modules/FrontendModule.php`。
- `templates/frontend/`：home.php、listing.php、skill.php、tutorials.php、tutorial.php、page.php、not-found.php。
- `template-parts/frontend/`：skill-card.php、tutorial-card.php。
- 主题 `404.php`。
- `tests/basic/frontend-routing.php`：隔离路由/URL 代码检查，使用 WordPress 函数替身，不加载 WordPress 或数据库。
- `docs/FRONTEND.md`、本报告。

上述主题路径均相对于 `wp-content/themes/ai-skills-hub/`。

修改：

- 主题 header.php、footer.php、index.php、assets/src/app.css、assets/src/app.js、style.css、functions.php；tailwind.config.cjs。
- Core.php、bootstrap.php：注册 frontend 模块与版本。
- .github/workflows/checks.yml：在 PHP 基本检查中执行隔离路由断言；完整数据库任务仍只允许手动 Final QA。
- scripts/check-assets.mjs：移除旧占位页样式断言，检查当前模板的 Tailwind 工具类和设计系统样式。
- scripts/smoke-wordpress.sh、tests/integration/core.php：仅更新未来 Final QA 的路径/模块预期，未执行这些集成脚本。
- CODEX_MASTER_SPEC.md、AGENTS.md、README.md、docs/CORE.md、docs/PROGRESS.md：同步新阶段划分、架构、内容配置与测试策略。

## 实际检查

Cloud 使用已有官方 WordPress/PHP 镜像中的 PHP 8.3.36，不启动 MySQL。Node.js 24.22.1、npm 11.9.0；未部署外部服务器。

| 检查 | 结果 |
| --- | --- |
| 全部 PHP 语法 | 42 个主题 PHP + 原有集成脚本通过；新增路由检查脚本另行 lint；最终共 44 个 PHP 文件 |
| 隔离路由/URL 基础检查 | 76 项断言通过：语言路径、未知地址、原生查询隔离、子目录/index.php、畸形适配器输入、URL 编码等；PHP 替身检查，**不是 WordPress/MySQL 集成测试** |
| Tailwind + esbuild | 构建通过；CSS 46020 bytes，JS 56108 bytes |
| 资源检查 | 通过；首次检查发现旧占位 CSS 类断言失效，更新为当前真实样式后通过 |
| JavaScript、Bash、Git diff 检查 | 通过 |
| 基本代码复核 | 修正固定链接解析、缺译跳转、教程排序、搜索边界、结构化正文键名、表单错误屏蔽等明显问题；未发现阻断性代码问题 |

Browserslist 仍提示 caniuse-lite 数据较旧，构建成功；本轮未为消除此提示修改依赖。

**未执行**完整 MySQL、真实 WordPress、WPML、浏览器、并发及端到端测试。尤其数据库 REGEXP/window 查询、重写/.htaccess、CPT 权限、表单实际持久化与限流、缓存撤稿、真实语言插件和浏览器操作均待 Final QA。没有将历史 Phase 1 CI 或本轮替身断言当作这些测试通过的证据。

阶段代码与文档提交并同步 GitHub main，完整 SHA 及 CI 基本检查结果在最终交付消息/后续本报告补记中列出。完成后暂停，未进入 Phase 4。
