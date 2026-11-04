# Phase 1 验收报告：数据库与 AI Skills Hub Core

日期：2026-11-05（Asia/Shanghai）。用户已验收 Phase 0，本轮仅执行用户授权的 Phase 1。结果：代码与要求的集成测试通过，停止等待验收，不进入 Phase 2。

## 需求落地

| 用户任务 | 实现 |
| --- | --- |
| Skills 核心表 | `aish_skills`，稳定 bigint unsigned ID，仓库关联、规范路径、状态及 UTC 时间 |
| 中英文翻译表 | `aish_skill_translations`，两种语言共用 skill_id，唯一 `(skill_id,locale)`；slug 以 locale 隔离 |
| GitHub Repositories | `aish_repositories`，规范化 owner/name 唯一；一个仓库可包含多个 Skills |
| Skills Versions | `aish_skill_versions`，不可变来源快照、commit SHA、计算的 SHA-256、版本标签 |
| Categories / Tags | 层级 categories、非层级 tags，及 skill_categories/skill_tags 两张多对多表 |
| 初始化与升级 | 1.0.0→1.1.0 迁移、MySQL 连接锁、dbDelta、实际结构验证、非 autoload 版本选项、失败重试及拒绝降级 |
| 模块加载 | Core、Module/ModuleLoader，显式 database/language/catalog 模块，依赖顺序及重复/缺失/循环检查 |
| 统一数据访问 | Database DAL 与 Catalog 应用接口，事务、行锁、参数绑定、表/字段/排序白名单及分页上限 |
| 输入与输出安全 | 正 ID、UTF-8/长度/路径/仓库/locale 验证，写能力校验，KSES、HTML/属性/URL/JSON 上下文处理 |
| WPML 接口 | LanguageProvider 及可注入 filter；外部对象 ID 不替代内部 Skill ID，仅预留接口 |

所有业务表均加当前 WordPress 表前缀，InnoDB、utf8mb4。Skill 来源由数据库完整唯一索引 `(repository_id,source_path)` 去重；路径 VARBINARY(768)，保留大小写，无索引截断。双语是同一个 Skill 的两行翻译，不建立影子 CPT。

不提供删除 API 或主题停用清理，迁移只增不删。数据引用由 Catalog 事务与行锁校验，不使用数据库外键或级联删除；内部 DAL 不可直接暴露为用户 SQL 接口。`functions.php` 没有新增业务逻辑。

## 文件清单

相对 Phase 0 提交 `d03f911`，本轮新增 19 个文件，修改 9 个文件，共 28 个。

新增文件：

```text
docs/CORE.md
docs/PHASE_1_REPORT.md
scripts/test-core.sh
tests/integration/core.php
wp-content/themes/ai-skills-hub/inc/core/Core.php
wp-content/themes/ai-skills-hub/inc/core/Module.php
wp-content/themes/ai-skills-hub/inc/core/ModuleLoader.php
wp-content/themes/ai-skills-hub/inc/core/Database/Database.php
wp-content/themes/ai-skills-hub/inc/core/Database/Schema.php
wp-content/themes/ai-skills-hub/inc/core/Database/Migrator.php
wp-content/themes/ai-skills-hub/inc/core/Content/Catalog.php
wp-content/themes/ai-skills-hub/inc/core/Language/LanguageProvider.php
wp-content/themes/ai-skills-hub/inc/core/Language/BuiltinLanguageProvider.php
wp-content/themes/ai-skills-hub/inc/core/Modules/DatabaseModule.php
wp-content/themes/ai-skills-hub/inc/core/Modules/LanguageModule.php
wp-content/themes/ai-skills-hub/inc/core/Modules/CatalogModule.php
wp-content/themes/ai-skills-hub/inc/core/Security/Access.php
wp-content/themes/ai-skills-hub/inc/core/Security/Input.php
wp-content/themes/ai-skills-hub/inc/core/Security/Output.php
```

修改文件：

```text
CODEX_MASTER_SPEC.md
AGENTS.md
README.md
.github/workflows/checks.yml
package.json
scripts/lint-php.sh
scripts/smoke-wordpress.sh
wp-content/themes/ai-skills-hub/inc/core/bootstrap.php
wp-content/themes/ai-skills-hub/style.css
```

总规格按用户本轮要求修订，取代原 CPT 主存储计划，重新界定 Phase 1/2；Phase 0 记录保留为历史证据。主题版本/Core 版本为 0.2.0，数据库版本为 1.1.0，两者独立。

## 环境与执行证据

Cloud 工作目录 `/workspace/Skills-ak`，起点 `main` / `d03f911`，无用户未提交改动。已执行 Git fetch 核对远端。首轮推送遇到 GitHub 503，普通推送重试成功，未强推。

Cloud 为 Debian 13、Node 24.22.1、npm 11.9.0、Docker 29.9.2；仍无原生 PHP/MySQL，只有已缓存的 WordPress/PHP 镜像，无运行中的 Compose 数据库服务。本轮不重复 Phase 0 的长时间镜像下载，按用户授权使用 CI 真实 MySQL 服务完成集成测试。没有部署外部站点。

最终实现提交：[`5618b85`](https://github.com/mtccv/Skills-ak/commit/5618b85)。最终完整 CI：[Core checks #41900248453](https://github.com/mtccv/Skills-ak/actions/runs/41900248453)，四个任务全部 success：PHP 8.3、PHP 8.4、assets、wordpress-smoke（包含 Core 数据库测试）。本报告属于随后独立的文档提交，不改变经测试的 PHP/构建代码。

实际集成环境：WordPress 7.1.4、PHP 8.3.36、MySQL Community Server 8.4.13。

## 测试结果

| 检查 | 实际执行环境 | 结果 |
| --- | --- | --- |
| PHP 全文件语法（20 个主题 PHP + 1 个测试 PHP） | Cloud PHP 8.3.36 容器；CI PHP 8.3/8.4 | 全部通过 |
| 干净依赖安装与前端构建 | Cloud、CI Node 24 | 通过；77 包；CSS 5763 bytes、JS 55953 bytes |
| 数据库结构 | CI 真实 MySQL 8.4.13 | 八表、列、ID 类型、完整唯一索引、VARBINARY 路径、InnoDB、utf8mb4 验证通过 |
| 重复安装 | CI | 两次重复执行均通过，当前版本不发 DDL，八表行数及双语关联保持不变 |
| 数据库升级 | CI | 实际创建缺少 version_label 的 1.0.0 表，写入内容后迁移到 1.1.0；ID、中文/emoji、版本记录保留，新字段默认空 |
| 升级保护 | CI | 第二 MySQL 连接持锁时拒绝并发迁移；未来版本/降级拒绝；模拟 DDL 失败不更新版本，排除阻塞后重试通过 |
| 中英文关联 | CI | 同 skill_id，语言别名归一化；更新中文保留英文，译文行 ID 稳定；跨 Skill slug 冲突不覆盖原译文 |
| 仓库、来源和版本 | CI | 同仓库多 Skill，按仓库+路径去重，路径大小写保留；重复版本幂等、冲突快照拒绝、源哈希正确 |
| Categories / Tags | CI | 分类父级、多个分类、标签重复分配和多对多关系通过 |
| 数据安全 | CI | SQL/排序/表列白名单、LIKE 字面量、路径/UTF-8/长度/分页、不可修改 ID、孤儿引用拒绝、事务回滚、匿名及 subscriber 越权拒绝、HTML/属性/URL/JSON 处理通过 |
| 模块与语言扩展 | CI | 模块依赖/重复/缺失/循环检测、语言 adapter 替身与 filter 接入、外部 ID 不改变 Skill ID 通过 |
| 主题数据保留 | CI | 切换主题再恢复后测试数据、双语/版本及稳定 ID 保留，活动站点 Core 表结构完整 |
| HTTP 冒烟 | CI | 主题激活、Core 加载、schema 当前、无影子 CPT；首页/CSS/JS 响应成功 |
| Git/脚本检查 | Cloud | 空白检查、shell 语法通过；未提交凭据、依赖目录、数据库或构建产物 |

最终集成测试输出：`Integration complete: 110 assertions passed.`。最终集成日志没有 PHP Warning、Deprecated 或 Fatal error。Tailwind 仍有 Phase 0 已知的 Browserslist 数据日期提示，构建成功。

测试表使用随机 `aish_t_<12 hex>_` 前缀；finally 只清理这些表、专用 schema 选项及临时 subscriber，并恢复主题。生产 Core 不含 DROP/TRUNCATE/业务删除或外部请求逻辑。

## 未实际执行及范围限制

- **Cloud 本机 MySQL 集成与 HTTP 测试未执行**；表中数据库结果来自 GitHub CI 真实服务，没有用 mock 冒充 MySQL。
- **PHP 8.4 的数据库运行测试未执行**；8.4 在 CI 检查全部 PHP 语法，数据库集成使用 PHP 8.3.36。
- **真实 WPML 插件联调未执行**；只验证预留接口及替身，不宣称真实兼容已完成。
- **多进程业务写入压力测试未执行**；迁移锁验证使用两个真实 MySQL 连接，唯一键和幂等验证使用顺序操作，不宣称负载测试。
- 未开发 GitHub 自动采集、AI 自动生成、业务后台页面、前台 Skills 页面或多语言路由。这些不属于本轮授权范围。

## Git 与阶段门禁

实现和报告均提交并同步 `origin/main`；最终交付 SHA 与远端一致性见本轮交付回复，或 `git log -1`。最终报告提交仅包含本文件，因此沿用上述实现提交的通过证据，避免为纯文档重复启动数据库任务。

Phase 1 完成后暂停，等待用户验收，不自动进入 Phase 2。
