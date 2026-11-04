# Phase 5 — GitHub Skills 自动采集开发报告

基线为 GitHub main 的 `3766dc1b410e50dd9873b1ef52a1a818d4a3f8fb`。开始时工作区干净，本地与远端一致；已阅读总规格、Core、Frontend、SEO、进度、Phase 1–4 报告和现有代码。用户已验收 Phase 4，本轮仅实施 Phase 5。

## 实现结果

- 统一 GitHub REST Client：公开仓库 URL/身份验证、GitHub ID、默认分支、Commit/Tree/Blob、文件 SHA 校验，可选服务器 Token；固定主机、禁止重定向、TLS、15 秒超时、响应大小限制、主/次限流和退避。
- 多 SKILL.md 发现：固定 Commit 快照，递归 Tree 截断或超限时重新分层遍历；严格路径、类型、大小和数量限制。任何不完整目录或文件失败都不会报告完整成功或推断来源删除。
- 受限 YAML Frontmatter：name/description/标准元数据、字符串类型与长度验证，拒绝执行型语法和不支持的格式。单文件失败记日志继续，不执行外部内容。
- 增量存储：使用原 `repository_id + source_path`，保留 Skill ID、已发布 slug 与双语关系；File SHA 去重、不可变版本、固定来源快照、完整扫描才标记 missing、同 Blob 疑似移动提示。文件未变仍检查新目录的依赖和风险变化。
- 可靠基线：最近前序任务必须完整成功，才允许同 Commit 快速复用；部分扫描失败后即使源仓库回退原 Commit 也重新核对。License 基线随完整成功原子推进，避免中断后漏检。
- WordPress Cron 小任务：持久阶段、游标、每仓库活动任务唯一键、全局 worker 锁、租约/超时、失败重试。普通前台和管理表单只登记/排队，不执行长扫描；无需 Action Scheduler。
- 原生 GitHub Imports 后台：仓库添加/编辑/定时设置、手动/批量扫描同步、归档取消、任务状态、结果/日志/限流、来源原文/SHA/License/风险、关联 Skills/Reviews。Capability、POST、nonce、确认与输出转义；过期来源确认拒绝覆盖。
- 新资源固定 pending，不创建译文，不自动发布。已有资源只保存待处理来源变更，不覆盖人工翻译或审核。静态检查是风险线索，未知 License 待核实，不作为安全认证。

## 数据与既有功能

主题/Core **0.6.0**，schema **1.4.0**。实施前已说明新增字段与持久运营数据的必要性：原八表无法表达 GitHub 不变数字 ID、File SHA、历史来源绑定、可恢复任务和私有采集结果。

迁移通过既有 Migrator/DAL 追加仓库采集字段、版本来源字段及 `imports`、`jobs`、`import_logs` 三张运营表，保留原八表、唯一来源键、ID、译文、审核及 Phase 4 索引。旧版本定义仍可验证；不回填虚构历史来源，不删除业务数据，不把业务塞入 functions.php。没有新增影子 Skills 系统。

原前台、SEO、搜索、Sitemap 的发布/审核门禁保持不变；公开内容仍需人工编辑和审核。没有实现 Phase 6 AI 翻译或内容生成，没有外部部署。完整使用步骤见 [GITHUB_IMPORT.md](GITHUB_IMPORT.md)，安全边界见 [IMPORT_SECURITY.md](IMPORT_SECURITY.md)。

## 文件清单

共 35 个文件：19 个新增、16 个修改。主题路径以下均相对 `wp-content/themes/ai-skills-hub/`。

新增：

- `inc/core/Sources/GitHubClient.php`、`GitHubException.php`：受限 HTTP/API 与安全错误。
- `inc/core/Sources/Discovery.php`、`Frontmatter.php`、`RiskInspector.php`：发现、解析和静态风险。
- `inc/core/Sources/ImportStore.php`、`Worker.php`、`Scheduler.php`、`Admin.php`：持久化、恢复调度与原生后台。
- `inc/core/Modules/SourcesModule.php`：既有 Core 服务与模块注册。
- `tests/basic/github-client.php`、`github-discovery.php`、`skill-parser.php`、`import-store.php`、`import-admin.php`、`import-worker.php`：隔离模拟检查。
- `docs/GITHUB_IMPORT.md`、`docs/IMPORT_SECURITY.md`、本报告。

修改：

- `inc/core/Database/Schema.php`、`Migrator.php`：1.4.0 增量迁移。
- `inc/core/Core.php`、`inc/core/bootstrap.php`、`style.css`：模块及版本。
- `inc/core/Admin/Management.php`：已有仓库一旦绑定 GitHub 身份或采集历史，不允许人工换绑另一来源。
- `.env.example`、`compose.yaml`：可选 Token 的空配置与 Cloud 环境传递。
- `.github/workflows/checks.yml`、`tests/integration/core.php`：新增模拟检查及 Final QA 基线的模块/表数量适配。
- `AGENTS.md`、`CODEX_MASTER_SPEC.md`、`README.md`、`docs/CORE.md`、`FRONTEND.md`、`PROGRESS.md`：范围、架构、维护与进度。

构建产物按原规则由源码及 lock 生成，不提交 `.env`、凭据、依赖目录、WordPress 核心或运行数据库。原 Phase 1–4 报告保留历史证据，不改写为本轮验收。

## 检查与证据

执行环境为 Codex Cloud，PHP 使用缓存的官方 WordPress/PHP 8.3.36 镜像，只读挂载源码；主机无 PHP/MySQL 服务，本轮未启动数据库。Node 24.22.1 / npm 11.9.0。

| 检查 | 实际结果 |
| --- | --- |
| PHP 8.3 语法 | 67 个 PHP 文件通过 |
| PHP 隔离基础/模拟检查 | 827 项通过，覆盖 11 个脚本；本轮新增 485 项，既有回归 342 项 |
| Tailwind / esbuild | 通过 |
| 资源检查 | 通过；CSS 46658 bytes，JS 56108 bytes |
| JavaScript / Bash 语法 | 通过 |
| Compose 配置 | `docker compose config --quiet` 通过；未打印展开配置或启动服务 |
| Git diff / 基本代码复核 | 通过；修正来源身份、同 SHA 风险重检、归档配置与确认指纹、License 基线及失败任务回退问题 |

模拟覆盖仓库验证、认证/限流/失败退避、Git Tree/truncated、多文件发现、YAML/不可信内容、SHA 去重、重放/增量、任务恢复、来源缺失规则和后台权限/转义。存储测试使用内存 DAL 替身，不执行真实 SQL；API 测试使用固定响应，不发送真实采集请求。旧路由/搜索/SEO/Sitemap 的隔离检查一并通过。Browserslist 提示既有 caniuse-lite 较旧，构建成功，未为消除提示变更依赖。

本轮六组模拟断言分别为 GitHub Client 160、Discovery 63、Frontmatter/风险 95、ImportStore 78、Admin 32、Worker 57。既有回归为路由 97、公开搜索 95、教程搜索 49、SEO 64、Sitemap 37。

## 未执行及实际限制

真实 GitHub API、MySQL 迁移/重复升级/事务锁/并发、WordPress Cron 与原生后台、WPML/SEO 插件、浏览器和完整端到端均未执行，按用户策略留 Final QA。`tests/integration/core.php` 只调整当前结构预期，不能据此声称 1.4.0 的真实升级已通过。CI 默认跳过 WordPress/MySQL 集成，保留显式 Final QA 手动触发。

安全 YAML 为受限子集，不是任意 YAML 实现；目录/文件/任务有明确上限，超限会显示不完整。静态检查只覆盖 Skill 文本及树元数据，没有执行或全面审计仓库脚本。疑似移动由管理员核实，不自动改绑。没有自动补充翻译，采集原文需在现有编辑器由人工采用。WP-Cron 在低流量或禁用时可能延迟；任务仍按真实状态显示，不伪造完成。

提交、远端一致性及 CI 结果将在完成同步后补记。Phase 5 完成后暂停，等待 Phase 6 指令。
