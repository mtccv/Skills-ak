# Phase 7 第二轮审查记录

基线：GitHub main `205d202d63b23008834ed8533806ee62e34d9edd`；开始时工作区干净，fetch 后与远端一致。用户补充十二项审查范围并明确不进入 Final QA。本轮继续人工源码复核和隔离/Mock 检查，不将首轮结论直接当作本轮证据，也不声称运行了缺少工具支持的 Codex Security 插件扫描。

## 发现登记（先记录，后修复）

| 编号 | 触发与影响 | 初始证据/处理状态 |
| --- | --- | --- |
| R2-01 | Phase 1 同 Commit 旧来源快照缺少 Phase 5 File SHA/来源绑定；完整扫描复用该行后，Phase 6 严格 JOIN 仍无法取源 | 真实 ImportStore + wpdb 替身复现 `legacy_scan_ai_binding=missing`；待按本次实际核实来源安全补全空元数据，不改原文/ID/历史时间，不全库推断回填 |
| R2-02 | 采集 checkpoint 将任务改为 queued，但仓库 sync_status 留在 running，后台状态长期不一致 | 隔离复现 `checkpoint_job=queued repo=running`；待同一事务同步状态 |
| R2-03 | 教程 Sitemap 加载每页完整文章正文/元数据后只取 ID/modified；卡片还生成从未使用的完整富文本 content | 实际 payload 隔离调用对 1 MiB 正文和已有短摘要仍返回 1048576 bytes content；Sitemap 的 fields 缺省为 all。待紧凑查询和卡片投影，保留公开及语言/noindex门禁，不宣称已测性能基准 |
| R2-04 | WordPress wpdb 在断线后可能自动重连并重放事务内语句，原事务/行锁已丢失 | 已只读核对最低 6.8 及缓存版本 wpdb 源码；真实 DAL + 断线模型复现 success/first=0/second=1/replays=1。事务作用域内 mysqli 严格模式的模型结果 failed/first=0/second=0/replays=0，模式恢复；待落地和补故障分支，未运行真实数据库 |
| R2-05 | 原生 SEO options/postmeta 读取失败被当作缺省值，可能遗漏人工 noindex；后续查询会覆盖 last_error | 故障替身复现 `route_noindex=false excluded=[] post_noindex=false`；应失败关闭，不能把数据库异常当未配置 |
| R2-06 | Router 的 URL/SEO adapter 阶段在异常边界之外；插件回调异常可越过既有 503 处理 | 隔离回调抛错复现 `Uncaught adapter exception: RuntimeException status=null`；避免异常消息进入页面，不虚构对应译文 |
| R2-07 | 不同请求键遇到活动 AI 任务时返回成功但不保存新键；该任务结束后重交第二个表单可建立另一付费任务 | 实际 Store 隔离模型复现 `first=1 second_form_returned=1 same_second_form_after_completion=2 jobs=2`；无改表方案为明确报告活动冲突，不假称该新表单已被幂等受理 |
| R2-08 | 普通 Settings 保存未检查数据库错误，update_option 失败后仍提示 saved | 实际 Actions 隔离反例显示配置未变但跳转 saved；待检查写入和持久值，保留无变化保存的合法语义 |
| R2-09 | AI 连接测试没有使用草稿的重复键检查，可能将冲突 JSON 当作成功 | Mock 验证 `{\"ok\":false,\"ok\":true}` 结果为 complete；拟统一严格 JSON 校验，不将连接测试等同完整生成验收 |
| R2-10 | 可选 LanguageProvider 过滤器异常或返回错误类型会中止全部 Core 注册 | 实际 LanguageModule + 抛错 filter 替身复现注册中止；待保留内置语言服务并显示授权管理员的固定诊断，不造译文或修改语言关系 |
| R2-11（排除） | 单项 AI 能力的导航入口曾被怀疑丢失 | 初始菜单数组模型错误地把重挂载放在 admin_menu 注册之后；官方顺序在注册之前，不能据该模型确认问题。撤回该发现，保留现有菜单，不增加修改数量；真实角色导航仍留 Final QA |
| R2-12 | 第五次连续采集 lease 失效时，claim 在递增失败数之前检查上限，仍允许额外一次执行 | 实际 claim + 内存过期谓词复现 `fifth_expiry_claim=running stored_attempts=5 stored_status=running`；待先计算本次失联次数，再判定并持久化终态 |
| R2-13 | 迁移持有连接级 GET_LOCK，但非事务 DDL 期间 wpdb 自动重连会失去锁并继续重放语句 | 与 R2-04 同一已核对的 wpdb 自动重放路径；待在整个迁移锁作用域启用严格 mysqli，断线立即停止，保留部分 DDL 和最后成功版本供重试，不改迁移设计/表结构 |
| R2-14 | Cron 全局锁及配置/表单限频锁中的事务外原生查询可自动重连，丢失连接级锁后仍继续执行 | 实际 Sources Scheduler + 断线模型复现 reconnects=1/runsWithoutLock=1；实际 AI Scheduler/Worker 模型复现重连后仍调用 Provider，外层 strict 均阻止继续。配置整值读改写和限频采用同类锁作用域；待补严格驱动保护。不据此声称已确认同一任务重复计费，亦不承诺撤销已发出的 HTTP |
| R2-15 | 交叉复核发现 R2-13 初稿使 dbDelta 预期的缺表 DESCRIBE 也抛出异常，可能阻断首装和新增表迁移 | 已核对缓存 WordPress upgrade.php 的 DESCRIBE→空结果→CREATE 流程及 wpdb mysqli_query；该回归来自本轮未提交补丁，不能直接交付。待在严格保护下用受信任 schema 定义先创建确实缺失的表，再运行原 dbDelta/验证；不改变表定义或版本 |

R2-04 追加确认：ROLLBACK 本身失败时，直接允许同一 DAL 再次 START 可能隐式提交旧事务中的部分写入。隔离模型得到 `next_transaction=success committed={first:1},implicit_commits=1`。修复需同时关闭不确定会话并拒绝该请求内后续使用；不能把未知 COMMIT 描述为必然回滚。

R2-01 交叉复核：旧任务曾 complete 且 HEAD 未变时，增量 fastpath 会跳过此次来源绑定核验。待给可复用基线补一次仅投影缺失绑定的 LIMIT 1 查询，发现 present 来源无法 JOIN 完整版本时执行正常扫描，避免默认同步永久跳过可恢复的旧数据；不读取全量正文、不猜测历史。手动完整扫描仍可显式核验。

上表保留发现时的记录；初始“待修复”不作为当前状态。最终处理与验证见下文。


## 最终处理

| 编号 | 修复与保留边界 |
| --- | --- |
| R2-01 | sourceContent 严格 JOIN 完整来源身份；新鲜、已校验 Blob 只补齐同 Commit/同原文版本的空元数据，冲突拒绝。增量基线检查 present 来源绑定，缺失时重新扫描；不全库回填、不改历史正文/时间/ID |
| R2-02 | checkpoint 在同一事务将任务与仓库同时置为 queued；失败整体回滚 |
| R2-03 | 卡片只投影所需摘要；教程 Sitemap 按页取 ID，再取 ID/modified 并复核公开/密码/语言/正文/noindex，不装载全量正文和 postmeta；无实际性能基准声明 |
| R2-04 | 原生 mysqli 严格模式阻止事务内 wpdb 断线重放；失败回滚，回滚无法确认则关闭并隔离连接。未知 COMMIT 不假称撤销，所有出口恢复调用方驱动模式 |
| R2-05 | SEO option/postmeta/blog_public 读取错误清除失败缓存并受控失败；检测到持续读取错误的页面/XML/robots 为 503/no-store，不把异常当可索引缺省值 |
| R2-06 | Router 请求、URL 与 SEO adapter 异常进入固定 503/noindex 错误上下文，不含实体/错误文本/Canonical/hreflang；模板与已保存表单仅回退地址，不重复写入 |
| R2-07 | 原 request key 保持幂等；不同 request key 遇活动任务明确报告忙，不假称新表单已受理。批量部分成功保留原任务与人工内容 |
| R2-08 | 普通后台 Settings 保存检查写入错误并读取持久值；合法无变化仍成功，错误/不一致清理缓存并给通用失败提示 |
| R2-09 | AI 连接测试复用有界严格 JSON 解码、重复键检查，仅允许精确 {"ok":true}；保留真实用量，不把失败响应当成功 |
| R2-10 | LanguageProvider 注册失败回退内置规范化并给授权管理员固定诊断，不改变内容、翻译关联或有效 adapter |
| R2-11 | 排除：菜单模型错误，未修改稳定代码；真实角色导航留 Final QA |
| R2-12 | 先计算本次 lease 过期次数，第五次立即终止并持久化次数/释放活动键；不多执行一次，成功 checkpoint 仍重置连续失败 |
| R2-13/R2-15 | 迁移全作用域禁止断线重放；严格模式下先精确探测并按既有受信任 schema 创建缺失表，再运行 dbDelta 与完整验证，避免预期缺表探测异常。部分 DDL 保留供重试，最后成功版本不虚增；无表定义/版本变化 |
| R2-14 | Sources/AI Scheduler、AI/SEO 配置及 Forms 限频的完整连接锁作用域启用严格模式；断线后不自动重连继续，释放异常也恢复驱动模式。已发送 HTTP 不能撤销，仍依赖租约、不确定状态和人工处理 |

R2-01 至 R2-14 中确认并处理 13 项基线问题，R2-11 排除；R2-15 是交叉复核本轮未提交补丁发现并修正的兼容回归，不冒充新增基线漏洞。均为证据驱动修复，不等同 14 个可利用安全漏洞。

## 十二项范围复核

| 用户范围 | 复核结论与相关证据 |
| --- | --- |
| 0–6 模块兼容 | 原生后台/前台/Core 服务保留；补齐旧采集→AI 来源绑定与语言扩展失败边界。完整旧隔离套件随新增检查一起执行 |
| 事务/并发/去重 | 原 source 唯一键、双语唯一关系、行锁与活动键不变；新增断连/回滚隔离和配置锁保护。交错替身不是实测并发 |
| GitHub 恢复/重试/状态 | truncated/大小/限流/退避、完整对账门禁保留；修复检查点状态、连续 lease 上限与旧来源快路径 |
| AI 批量/历史/JSON/人工保护 | 请求幂等明确，严格连接 JSON；历史分页与完整快照保留。原人工修订/来源基线/只填空字段/pending 回归保持 |
| 后台 capability/nonce/API | 检查原生 admin-post、保存 hooks、公开表单、预览。源码未注册额外 REST/AJAX 管理端点；普通 Settings 不再虚报保存 |
| 私有草稿/预览 | PublicCatalog、搜索、详情、语言链接、XML 保持发布且当前修订批准及完整语言门禁；预览仍需能力和 nonce。错误响应清空实体 payload |
| 双语/WPML | 稳定 Skill ID、locale 唯一关系未变；可选 adapter 注册/URL/异常不会造内容或泄漏异常。真实 WPML 留待验 |
| SEO/Canonical/hreflang/Sitemap | 保留同语言自引用、实际译文和单一输出所有者；noindex 读失败不放行，异常 XML/robots 不报告空成功 |
| 查询/缓存/N+1 | 教程卡片/Sitemap 载荷减小；批量元信息预取和请求缓存保留；失败 option/postmeta 缓存清理。未增加长期公共共享缓存，没有新增索引/EXPLAIN 声明 |
| Cron/锁/异常恢复 | 同时保留全局锁、活动任务唯一键、lease/fencing、期限/退避；补事务外重连丢锁保护。已发送 HTTP 与未知 COMMIT 仍不能宣称恰好一次 |
| 日志/密钥 | 固定错误码/诊断，SQL、原始错误体、Key/Token 不新增到输出/日志。模式扫描命中唯一私钥标题为既有脱敏测试假数据；未发现实际凭据字面量 |
| 主题生命周期/迁移 | Schema.php、十四表定义、schema 1.5.0 未变；迁移不删除/重建数据，主题切换仍只清 Cron。加固首装、重复/失败迁移模型，不伪报真实数据库验证 |

此次人工源码复核没有确认 SQL 注入、可执行 XSS、CSRF、SSRF、后台权限绕过、公开未审核内容或未知 Skill 执行的可利用路径；这不等同真实环境的全面安全证明。没有新增外部依赖、业务表、后台接口或自动发布行为。

## 实际检查

| 检查 | 本轮实际结果 |
| --- | --- |
| PHP 8.3.36 语法 | 100 个 PHP 文件全部通过；缓存官方 PHP 容器、禁网、只读源码挂载，不启动 WordPress/MySQL |
| 全部隔离/Mock | 29 组、2679 项断言通过，比首轮增加 940 项；WordPress 函数、wpdb/连接/事务/DDL、HTTP/Provider 均为替身或模型 |
| 九个新增入口 | database-transactions 92、migration-recovery 151、language-adapter 50、editorial-projections 36、seo-failures 83、admin-settings 52、import-scheduler 59、ai-locks 99、frontend-locks 196，共 818 项；其余 20 组 1861 项 |
| 前端构建/资源 | npm run build 与 npm run check:assets 通过，CSS 47227 bytes、JS 56108 bytes |
| 静态/配置 | JS/Bash 语法、Compose config、git diff --check 通过；29 个测试入口均已注册 CI，Final QA gate 未改 |
| 数据结构 | Schema.php 对基线无差异；Migrator 只修复故障与缺表创建边界，schema 版本、定义和迁移顺序未变 |
| 安全模式检查 | 未找到实际凭据字面量；私钥标题命中经检查为既有脱敏测试假内容；生产 PHP 未新增 REST/AJAX 管理注册或 shell/eval 执行入口 |
| npm audit | 本次 0 个已知依赖漏洞，未新增或升级依赖；不证明应用不存在漏洞 |
| GitHub Actions | [Core checks 41982408246](https://github.com/mtccv/Skills-ak/actions/runs/41982408246)：实现提交的 PHP 8.3、PHP 8.4、assets 均 success；wordpress-smoke 为 skipped |

Cloud Node 24.22.1、npm 11.9.0。Browserslist 仍提示数据日期较旧，构建成功。部分新增连接测试另用 php -n 的驱动替身通过，不重复计入 2679，也不解释为另一套真实数据库测试。两个 SEO 子进程另外核对实际 exit 响应分支，主汇总仍只记其 83 项断言。没有运行全栈服务或 Final QA。

## 文件清单

运行修改（相对 `wp-content/themes/ai-skills-hub/inc/core/`）：

- `Database/{Database,Migrator}.php`、`Modules/LanguageModule.php`、`Admin/Actions.php`。
- `Sources/{ImportStore,Worker,Scheduler}.php`。
- `AI/{Admin,GenerationStore,Output,Worker,Scheduler,Settings}.php`。
- `Frontend/{Editorial,Forms,Router,Seo,SeoSettings,Sitemap,View}.php`。

新增独立检查：`tests/basic/{admin-settings,ai-locks,database-transactions,editorial-projections,frontend-locks,import-scheduler,language-adapter,migration-recovery,seo-failures}.php`；更新原 AI/采集/SEO/Sitemap 八个测试文件，CI 注册九个新入口。

文档：新增本报告和根 `CHANGELOG.md`；更新总规格、AGENTS、README、PROGRESS、CORE、FRONTEND、SEO、GITHUB_IMPORT、IMPORT_SECURITY、AI_USAGE、API_CONFIGURATION、AI_SECURITY；首轮报告仅添加后续链接，保留历史证据。无构建产物、依赖目录、实际密钥或数据文件入库。

## 遗留与未执行

本轮确认的重要代码问题均按上表处理；未发现需继续留在 Phase 7 修复的已确认阻断问题。以下是真实运行待验项和已明确的运行限制，不标为通过：

- **真实 WordPress/MySQL/WPML/API/浏览器/并发/端到端均未执行**。CI 的 wordpress-smoke 必须保持 skipped，只有用户安排 Final QA 后才运行。
- 真实首装、1.0–1.5 逐级/重复/失败升级、现有数据和唯一索引、原生 postmeta InnoDB、死锁、连接断开、未知 COMMIT、缓存/保存 hooks、同时采集/编辑/采用与主题切换仍需验收；模型不能证明数据库锁行为。
- 严格模式保护针对原生 WordPress mysqli。物理连接在无 SQL 检测的 HTTP 途中丢失时，已发出的请求无法撤销；不承诺分布式恰好一次或绝对串行网络调用。AI 原有 uncertain/人工确认重试及未知费用机制必须在真实环境验证。
- GitHub 固定身份、限流/截断/超限、供应商格式/Token/计费、超时与失败写回需真实 API 验证。无真实 Key 不报告调用成功；来源和模型仍不得执行命令或自动发布。
- WPML 语言目录/域名和失败回调、Yoast/Rank Math Meta/Sitemap 所有权与缓存、物理 robots.txt、角色/nonce/公开预览需真实插件和浏览器验收。
- 前导通配搜索、REGEXP 正文判定、关联投影、规模/并发 EXPLAIN 和历史容量尚未测量；减少载荷不等于达到吞吐/延迟指标，不通过删除历史规避容量问题。
- 安全模式检查和 npm audit 不能证明不存在未知漏洞；长期备份恢复、日志/版本/AI 历史运维容量留 Final QA。

Phase 7 代码工作完成后暂停，等待用户安排 Final QA，不自动执行集成或部署。


## Git / CI 补记

实现提交 `49ee0a757e88f622cc4d37f0f07160e221bfb169` 已正常推送 GitHub main，未强推。[Core checks 41982408246](https://github.com/mtccv/Skills-ak/actions/runs/41982408246) 的 headSha 与该提交完全一致；PHP 8.3、PHP 8.4 和 assets 全部 success，wordpress-smoke 按 Final QA gate 为 skipped。本补记与当前状态说明随后仅作纯文档提交，不改变已通过检查的运行代码、测试或 CI 配置；最终远端同步 SHA 见交付消息及 `git log -1`。

Phase 7 两轮代码层审查和修复已完成，未进入 Final QA，等待用户安排。真实集成待验清单保持上述未执行状态。
