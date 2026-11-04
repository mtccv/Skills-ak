# Phase 2 — WordPress 原生后台开发报告

本轮以用户已验收的 Phase 0/1 为基础，仅开发原生后台；完成后暂停，等待用户安排 Phase 3。历史基线为 `f4cee8d4a1717842452a7bb6bccb55619a24ab7d`，开发前已确认 origin/main 一致。没有重建项目、替换 DAL 或改写 functions.php。

## 完成功能

- AI Skills Hub 顶级菜单与 Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings 子菜单。
- 原生 WP_List_Table 搜索、筛选、排序、分页；统计、状态计数与翻译完整性。
- Skill 新增、读取、编辑、归档；人工无来源录入、已有仓库多 Skills；同一稳定 ID 下中文/英文标题、Slug、简介、正文、SEO；wp_editor 与语言切换，不伪造缺失翻译。
- draft、pending、published、rejected、archived；草稿可不完整，提交后由有审核能力的用户批准或拒绝。批准需至少一种完整译文；编辑后旧批准失效，修订号阻止过期覆盖。
- Unknown/Low/Medium/High 风险、最近一次审核人员/UTC 时间/修订/备注。拒绝需备注；没有批量直接发布。
- 分类层级及循环校验、标签、手动仓库维护；已关联仓库不得直接更换身份。归档保留实体、来源、历史快照和关系。
- 归档确认页、用户绑定的短期确认 token、POST/nonce/capability、批量数量限制和逐项结果计数。
- 私有 Skill 阅读预览：有效 capability + nonce、指定语言、不缓存、不索引；未实现公开前台页面。
- 设置默认编辑语言和分页数量；默认仅管理员获得五项专用管理能力，其他角色需明确授予。

## 数据扩展说明

Phase 1 的现有表没有人工来源、审核、修订、SEO 和归档字段，无法完整承载本轮明确要求；实施前已向用户说明必要调整。

schema 1.2.0 仅扩展现有八表：Skills 来源双 NULL 支持人工录入，增加修订/审核/风险/人员/时间/备注；翻译增加 SEO 字段、允许部分草稿 NULL slug；分类/标签/仓库增加归档标记。内部 ID 与完整来源唯一索引不变。历史无有效审核证据的 published 记录转 pending，保留原始内容；不会删除原始记录。

审核字段保存最近一次决定，不是完整事件日志。WPML 继续保留 LanguageProvider 接口；没有宣称真实插件联调。GitHub 和 AI 自动化未开发。

## 文件清单

新增：

- `wp-content/themes/ai-skills-hub/inc/core/Admin/Management.php`：管理事务、修订、审核、归档及查询。
- `wp-content/themes/ai-skills-hub/inc/core/Admin/Actions.php`：HTTP 验证、保存、批量、确认、预览。
- `wp-content/themes/ai-skills-hub/inc/core/Admin/Pages.php`：原生菜单、统计、编辑、审核、设置。
- `wp-content/themes/ai-skills-hub/inc/core/Admin/ListTable.php`：原生列表呈现。
- `wp-content/themes/ai-skills-hub/inc/core/Modules/AdminModule.php`：模块加载、能力及后台资源。
- `wp-content/themes/ai-skills-hub/assets/admin.css`、`assets/admin.js`：后台布局及渐进增强语言切换。
- `docs/PROGRESS.md`、本报告。

修改：

- Core.php、bootstrap.php、style.css：注册 Admin 服务/模块，主题/Core 版本 0.3.0。
- Database/Schema.php、Migrator.php：schema 1.2.0 顺序迁移及验证。
- Content/Catalog.php、Security/Access.php：复用原接口，业务能力、人工来源读取和发布约束。
- tests/integration/core.php：更新模块、迁移、历史已发布记录及禁止绕过审核的基线预期，留 Final QA 执行。
- .github/workflows/checks.yml：PHP/构建自动，数据库/WordPress 基线改为手动 Final QA。
- CODEX_MASTER_SPEC.md、AGENTS.md、README.md、docs/CORE.md：同步本轮范围、架构及新测试策略。

## 必要检查与限制

Cloud 为本轮唯一开发环境；Node.js 24.22.1、npm 11.9.0。宿主无独立 PHP，使用已有官方 `wordpress:php8.3-apache` 缓存镜像中的 PHP 8.3.36，只读挂载源码执行语法检查，未启动数据库。

| 检查 | 实际结果 |
| --- | --- |
| PHP 8.3.36 `php -l` | 25 个主题文件 + 1 个集成脚本，共 26 个全部通过；只检查语法，未执行该集成脚本 |
| 原生 WP_List_Table 类加载 | 镜像内 WordPress 基类与新列表子类加载成功；不是后台运行验收 |
| `npm run build` | Tailwind CSS 与 esbuild 构建成功 |
| `npm run check:assets` | app.css 6005 bytes；app.js 55953 bytes；资源检查通过 |
| `node --check assets/admin.js` | 后台 JavaScript 语法检查通过 |
| `git diff --check`、基本代码复核 | 通过；修正表单/控制器字段、原生批量 nonce 冲突、分页、过期编辑重试、NULL 来源查询及审核完整性等明显问题 |
| GitHub Actions 基本 CI | 代码提交 e25da32 的 PHP 8.3、PHP 8.4 和 assets 均 success；wordpress-smoke 为 skipped，未启动完整数据库测试 |

构建期间 Browserslist 提示 caniuse-lite 数据较旧，构建未失败；本轮没有为消除此提示变更依赖。

以下本阶段**未执行**，按用户策略留到 Final QA：MySQL 1.1→1.2 实际迁移/重复安装/数据保留、WordPress 后台激活及浏览器操作、WPML 真插件、并发编辑与审核、权限/CSRF/XSS 的完整运行矩阵、端到端和浏览器兼容。源码审查、PHP lint 及历史 Phase 1 CI 均不能作为这些项目通过的证据。

后台界面文案目前以中文为主，UI 已接入 gettext；完整英文界面消息目录尚未提供。中英文内容分别编辑及保存已实现，不把消息目录预留等同完整界面翻译。名称选择显示前 100 条可用记录并保留所有已有选择，超出范围支持输入 ID；每种分类/标签最多关联 100 项。

代码提交：`e25da3241ab7830f0d34f8157063863b456d578a`，已正常推送 origin/main 并核对远端 SHA 一致。GitHub Actions 证据：[运行 41904484033](https://github.com/mtccv/Skills-ak/actions/runs/41904484033)。本报告补记提交只更新文档，不改变已检查代码；最终提交 SHA 以本轮交付消息及 `git log -1` 为准。
