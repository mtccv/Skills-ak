# Phase 6 — 智能内容生成开发报告

以 GitHub main `4a7d70c3eeda9c4d4f1f668508ea7d036b4bb4fe` 为基线。开始时工作区干净、远端一致；已阅读总规格、Core、Frontend、SEO、采集使用与安全说明、进度及 Phase 1–5 报告。用户已验收 Phase 5，本轮只实施 Phase 6，没有进入 Phase 7 或部署外部服务。

## 完成内容

- 可替换 Provider 接口与 Registry；OpenAI / OpenAI-compatible Chat Completions，安全 Base URL、服务器 Key、模型/Temperature/输出参数/超时配置，连接测试和多个模型档案。
- 来源快照使用 Phase 5 同一 Skill ID、仓库路径、Commit/File SHA、许可证线索及静态结果；固定 system + JSON 来源，排除人工译文/编辑基线/控制字段和密钥。模型没有命令执行或管理工具。
- 独立中文/英文完整草稿、单语言重新生成、双语摘要及使用场景局部建议。严格 JSON、语言/字段/长度/证据校验；安装命令必须逐字来源于原文，不确定内容待核实。
- 分类、标签、平台与场景建议，优先匹配既有目录，新增词仅提示；风险等级、引用及解释单独保存，不覆盖静态扫描或人工风险级别。
- 原生 AI Settings / AI Generation，配置、连接测试、最多十项批量排队、状态筛选/分页、原文/静态检查/AI 草稿/人工内容比较、草稿修改/采用/拒绝、用量和操作历史。
- 草稿与正式内容隔离。采用事务检查 Skill 修订、来源指纹、译文基线与 AI 草稿修订，只填明确选择的空字段，保护所有非空人工字段和 slug。采用后进入 pending，仍走正常人工发布审核。
- WordPress Cron 每次一个请求，持久化尝试先于 HTTP、幂等键、活动任务唯一键、租约、暂停恢复、重试与超时。网络超时或中断导致处理不明时不自动重发；未知 Token/成本不当作零。
- 冻结 Provider/Model/Prompt/价格，记录 Token、耗时和估算费用；配置变化不悄悄切换旧任务。API Key 不入数据库、前端或日志，后台只显示固定掩码。

## 数据与保留范围

主题/Core **0.7.0**，schema **1.5.0**。为保存独立草稿、逐次请求及人工处理记录，已在实施前说明新增运营表的理由。新增 `ai_jobs`、`ai_attempts`、`ai_reviews`；原八张核心表和 Phase 5 三张运营表定义保持不变，合计十四表。沿用既有版本化、带锁迁移及 DAL，不建立另一套 Skill ID 或翻译系统。

原前台、搜索、SEO、Sitemap、GitHub 采集、原生编辑/审核功能保留，公开门禁仍要求发布及当前修订批准。AI 不创建公开页面、slug 或分类标签，不自动执行安装命令；没有把业务堆到 functions.php。主题停用/升级保留全部任务和历史。

## 文件清单

共 **41 个文件**：24 个新增、17 个修改。主题路径相对 `wp-content/themes/ai-skills-hub/`。

新增：

- `inc/core/AI/Provider.php`、`ProviderRegistry.php`、`ProviderException.php`、`OpenAIProvider.php`、`Settings.php`：适配层、安全 HTTP、模型配置与凭据引用。
- `inc/core/AI/Prompt.php`、`Output.php`：不可信来源隔离、结构化内容、证据及安全渲染。
- `inc/core/AI/SourceContext.php`、`GenerationService.php`、`GenerationStore.php`：真实来源快照、排队、用量与审稿持久化。
- `inc/core/AI/Worker.php`、`Scheduler.php`、`Admin.php`、`inc/core/Modules/AIModule.php`：后台执行、模块注册和原生管理。
- `tests/basic/ai-provider.php`、`ai-content.php`、`ai-source.php`、`ai-store.php`、`ai-admin.php`、`ai-worker.php`：六组隔离检查。
- `docs/AI_USAGE.md`、`API_CONFIGURATION.md`、`AI_SECURITY.md`、本报告。

修改：

- `inc/core/Database/Schema.php`、`Migrator.php`；`inc/core/Core.php`、`bootstrap.php`、`style.css`。
- `.env.example`、`compose.yaml`：空密钥示例、服务器主机白名单和 Cloud 环境传递。
- `.github/workflows/checks.yml`、`tests/basic/import-store.php`、`tests/integration/core.php`：新增检查、旧测试版本引用及 Final QA 模块/表数预期。
- `AGENTS.md`、`CODEX_MASTER_SPEC.md`、`README.md`、`docs/CORE.md`、`FRONTEND.md`、`IMPORT_SECURITY.md`、`PROGRESS.md`。

没有提交真实凭据、运行数据库、依赖目录、WordPress 核心或构建产物。构建按原有源码及 lock 生成，历史阶段报告未改写。

## 已实际执行的检查

| 检查 | 结果 |
| --- | --- |
| Cloud PHP 8.3.36 语法 | 87 个 PHP 文件全部通过 |
| 隔离 / Mock 检查 | 1466 项通过：本轮 639，既有回归 827 |
| AI Provider / 配置 | 260 项 Mock HTTP、DNS、WordPress 函数检查 |
| AI Prompt / Output | 77 项结构、语言、证据与长度检查 |
| AI Source / Service | 39 项来源、排队、权限与预算边界检查 |
| AI Store | 72 项内存 DAL、租约、去重、用量及人工保护检查 |
| AI Admin | 56 项原生控制器替身、权限/nonce/批量/转义检查 |
| AI Worker / Source | 135 项 Mock Provider、恢复、未知结果、Token 与目录快照检查 |
| Tailwind / esbuild / 资源 | 通过；CSS 47227 bytes、JS 56108 bytes |
| JavaScript / Bash / Compose | 语法及 `docker compose config --quiet` 通过 |
| Git diff / 基本代码复核 | 通过；复核人工内容保护、来源身份、租约与未知计费；修正标题长度、失联用量汇总、连接权限一致性及后台记录显示 |

PHP 使用 Cloud 缓存官方镜像，只读挂载源码；没有启动 MySQL 或真实 WordPress。Node 24.22.1、npm 11.9.0。既有 caniuse-lite 日期提示仍出现，构建成功，未为消除提示变更依赖。

以上 API 使用 Mock HTTP/Provider，数据库使用内存/记录查询替身，后台使用 WordPress 函数替身。**没有真实 AI API 调用成功证据**，不能将这些断言称为真实 OpenAI、MySQL、WordPress、浏览器或 E2E 验收。CI 的完整 WordPress/MySQL 任务仍只供 Final QA 手动触发。

## 未执行与实现边界

真实 OpenAI/兼容服务、模型质量、计费、MySQL 1.4→1.5 迁移/重复执行/事务并发、WordPress Cron/后台、WPML/SEO 插件、浏览器及完整端到端均未执行，留 Final QA。历史 schema 字符串相同的断言不能证明真实 DDL 已执行。

本轮兼容层使用 Chat Completions + JSON object，不代表支持 Responses API、Azure 特定路由或任意厂商参数。来源有 64 KiB Prompt / 1 MiB 任务快照上限，目录最多携带 200 项，超限或缺失如实标注；不能把有限元数据说成完整源码审计。

重新生成保留独立建议，已有人工字段只能在原编辑器由人合并；使用场景片段不自动替换整个正文。AI 证据匹配和语言字符检查不能保证事实、语义或翻译质量。风险与成本均有明确局限，未知费用不会显示为零，人工重试可能再计费。

完整操作见 [AI_USAGE.md](AI_USAGE.md)，配置见 [API_CONFIGURATION.md](API_CONFIGURATION.md)，安全与待测项见 [AI_SECURITY.md](AI_SECURITY.md)。提交与 CI 证据将在同步后补记。完成后暂停，等待 Phase 7 指令。
