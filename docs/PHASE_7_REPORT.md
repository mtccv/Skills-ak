# Phase 7 首批审查与修复记录（阶段进行中）

基线：GitHub main `b3295f643c167ab7a6b5bba60e01f58359253cc3`。开始时工作区干净，fetch 后本地与远端一致；已阅读总规格、Core、Frontend、SEO、采集/AI/API/安全说明、进度及 Phase 0–6 报告。

用户已验收 Phase 6。Phase 7 请求在“GitHub 采集稳定性 → 任务重复”结束；用户明确答复“还有后续，我会补充”。因此本记录只描述已授权范围的首批工作，**不表示 Phase 7 已完成或可验收**，也没有进入 Final QA 或部署。

## 审查方式与边界

使用人工源码审查、独立复核及针对性的隔离故障测试。尝试使用 Codex Security 标准扫描流程，但技能引用的预检/执行文件及对应工具在当前环境不可用，未完成插件预检或扫描；本报告不是该插件的扫描结果。

| 边界 | 本批实际检查 |
| --- | --- |
| 后台入口 | 原生 Actions、SEO、Imports、AI 的 admin-post 控制器；公开 Contact/Submit、原生教程/页面/Inbox 保存回调；capability、方法、nonce、归档确认、输入及输出 |
| SQL 与存储 | DAL 表/列白名单、prepared 值、排序枚举、事务；14 表 schema/迁移/唯一来源与双语关系；并发绑定和任务恢复 |
| 公开内容 | PublicCatalog、Router、Editorial、SEO、Sitemap、预览；发布及当前修订批准、真实译文、私有来信/草稿、缓存和输出边界 |
| 外部请求 | GitHub 固定主机与固定 SHA、公开身份；AI 服务器主机白名单、HTTPS/DNS、零重定向、超时/大小限制、固定错误与密钥引用 |
| 不可信内容 | 路径、树、Frontmatter、静态风险、AI Prompt/JSON/证据；原文不进入执行器、安装器、动态模板路径或管理工具 |
| 生命周期与资源 | 主题切换只清 Cron；公开查询批量读取、任务列表投影、AI 历史正文载荷；构建/依赖及开发配置 |

当前代码未注册额外的后台 AJAX 或 REST 管理接口。没有确认 SQL 注入、可执行 XSS、CSRF、SSRF、未授权公开草稿或密钥泄漏的可利用路径；这只描述本次源码审查结果，不能证明这些风险在真实 WordPress、数据库、网络及插件组合中全部不存在。

## 已定位并修复的问题

| 问题与触发 | 修复与证据 |
| --- | --- |
| 旧 Repositories 归档仅改 is_archived，快速恢复可能继续旧任务及定时同步 | 统一委托 ImportStore 的归档事务，关闭同步、取消活动任务、撤销租约；记录、译文、版本均保留。隔离测试覆盖 queued/running/retry、恢复及取消写入失败回滚 |
| 仓库改名在行锁前建立一致性快照，可能遗漏等待期间提交的 Skill/任务绑定 | existing 仓库先锁行再做一致性查询；交错模型验证已绑定身份不能更换。旧代码在新增反例中失败；不是实测 MySQL 并发 |
| 采集完整对账已提交，finish 写失败后的 retry 用旧内存状态抹掉对账标记/计数 | checkpoint/retry/finish 保留数据库已有对账证据及 Commit/计数，拒绝伪造或换 Commit。修复前隔离复现 `first_missing=1 durable_marker_after_retry=lost retry_missing=0`，新增故障回归 |
| AI 重复 JSON 键正则在长字符串下触发 PCRE JIT 栈限制，失败被忽略 | 使用合法 JSON 上的线性扫描，检查对象内解码后的键；长合法描述、嵌套与 Unicode 转义重复键回归。属于结构验证缺陷，不是代码执行或自动发布漏洞 |
| AI 详情一次载入最多 100 份完整审稿快照，长正文多次保存造成大内存载荷 | 每页 25 条操作元信息，独立按任务/记录双 ID 读取一份快照；权限、分页、空状态及转义保持。测试包含超过旧 100 条范围的长快照，不删除历史 |
| 原生语言/互链/相关 Skill、来信状态/备注可能在数据库失败时部分保存 | 共用受控的原生元数据事务，逐写检查并读回验证，锁内核对配对，失败保留旧元数据并显示通用提示；提交/回滚后清理相关对象缓存。WordPress 正文此前已经保存，不假称回滚正文 |

主题/Core 仍为 0.7.0，schema 仍为 1.5.0。Schema.php 与 Migrator.php 没有修改，不新增/删除/重建表；原八表及六张运营表、稳定 ID、来源唯一键、双语关系和所有历史保留。采集与 AI 不改已发布 slug；人工明确改 slug 时原 Router 通过稳定 ID 和公开门禁对旧地址作 301，不另造 slug 历史表。

## 文件与验证记录

本批 Cloud 实际检查：

| 检查 | 结果 |
| --- | --- |
| PHP 8.3 语法 | 91 个 PHP 文件通过；缓存官方镜像、无网络、只读源码挂载 |
| 隔离/Mock 回归 | 20 组共 1739 项通过；较 Phase 6 增加 273 项 |
| 新增定向检查 | Management 完整性 50、Editorial 元数据 122、Inbox 元数据 56；旧 AI 内容/存储/后台及采集存储测试共增加 45 项 |
| Tailwind/esbuild 与资源 | 通过；CSS 47227 bytes、JS 56108 bytes |
| npm audit | 本次报告 0 个已知漏洞；没有据此升级依赖或宣称应用无漏洞 |
| JavaScript/Bash、Compose、Git diff | 语法、配置及空白检查通过 |
| Schema/迁移差异 | Schema.php、Migrator.php 相对基线没有变更 |

Node 24.22.1、npm 11.9.0。既有 Browserslist 数据日期提示仍出现，构建成功。PHP 检查没有启动 WordPress/MySQL，20 组测试均为独立模型；本批不把历史 CI 或模拟通过当作真实系统验收。

新增文件：`Frontend/NativeMetadata.php`、`tests/basic/management-integrity.php`、`editorial-management.php`、`forms-management.php` 及本报告。

修改运行文件（主题路径相对 `wp-content/themes/ai-skills-hub/inc/core/`）：`Admin/Management.php`、`Sources/ImportStore.php`、`AI/Output.php`、`AI/GenerationStore.php`、`AI/Admin.php`、`Frontend/Editorial.php`、`Frontend/Forms.php`、`Modules/FrontendModule.php`。

修改测试：`tests/basic/ai-content.php`、`ai-store.php`、`ai-admin.php`、`import-store.php`；CI 注册三个新检查。总规格、AGENTS、README、PROGRESS、CORE、FRONTEND、GITHUB_IMPORT、IMPORT_SECURITY、AI_USAGE、AI_SECURITY 同步更新。历史报告不改写，源码不包含实际密钥、依赖目录或构建产物。

## 未执行与后续

- 本批使用 Mock HTTP/Provider、WordPress 函数及 wpdb 事务/交错替身；没有真实 GitHub/AI API、WordPress、MySQL、WPML、SEO 插件、浏览器或端到端验收。
- 原生 postmeta 的回滚依赖事务型表；真实 InnoDB、持久对象缓存、插件保存 hooks、数据库断连/死锁、并发配对/采集/采用和主题切换需 Final QA。没有用模拟模型冒充数据库锁证明。
- AI 自定义主机的 DNS 与实际连接/代理行为仍需部署环境验证；服务器白名单不能替代可信供应商选择。没有密钥时不声称真实模型调用通过。
- 公开搜索的前导通配与 REGEXP、教程 Sitemap 装载完整文章对象的载荷、规模/并发查询性能尚未测量；不以源码检查冒充 EXPLAIN 或性能基准。
- 原有人工内容保护、未知计费处理、完整扫描门禁的 Mock 回归不能替代实际故障恢复验收。
- 等待用户补充 Phase 7 剩余要求后继续本阶段；不会自动进入下一阶段。
