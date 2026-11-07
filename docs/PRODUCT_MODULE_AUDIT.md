# 产品 V2 模块审计与复用边界

## Phase 11 增量实现核对

新增 DiscoveryModule，依赖现有 community/contributions/frontend/admin，复用 PublicCatalog、Editorial、Seo/Sitemap 和稳定 Skill ID。Query负责公开排行/推荐与主题投影，Management/Input负责集合/场景/翻译事务，Settings/Admin负责原生运营，Views负责自愿有效观察；没有向functions.php集中业务逻辑。

现有26表保留，七张规划表落地至33；metric_events/daily_metrics/workflow_events复用。原账户、投稿、收藏、评价接口不重建；缺译和非公开资源先过滤再计数。前后台共享既有视觉，原型不修改。实际修复与证据见 [Phase11报告](PHASE_11_REPORT.md)。下方审计差距为实施前历史，不能代表当前未实现。

> Phase 10 当前增量：新增 ContributionsModule 及 Submissions/Developers 服务、独立原生后台、双语页面、受控 Inbox 原生接口保护、公开资料快照和账户隐私接入。schema 1.8.0 仅增加 author_claims，原 25 表保持。Forms 的旧 Submit 接口转至验证账户投稿流程，Contact 与历史匿名记录保留。下方历史差距表不代表当前仍缺已交付功能。见 [Phase 10 报告](PHASE_10_REPORT.md)。

> 实施增量：Phase 8 已验收，Phase 9 新增 `CommunityModule`（Service/Query、不可变修订、Admin/Frontend、Events/Metrics、Privacy/Scheduler），复用既有 Accounts/Database/PublicCatalog。schema 1.7.0 共 25 表；历史审计表保留当时差距，不代表当前仍缺账户/社区能力。详见 [社区说明](../COMMUNITY_REVIEWS.md) 和 [Phase 9 报告](PHASE_9_REPORT.md)。未实施 Phase 10–14。

日期：2026-11-06（Asia/Shanghai）。只读审计基线：`main` / `1c27f8770fc3bd20b2ffd92d8873444ba92d54c8`。当前已交付主题/Core 为 **0.7.1**，数据库 schema 为 **1.5.0**。

本文件只记录源码现状和 Phase 8–14 的规划差距，不表示这些阶段已经开发或启动。本轮仅阅读源码、既有报告和编写 Markdown；没有修改 PHP、数据库定义、测试、构建配置或安装包，没有启动 WordPress、服务、迁移或测试，没有调用真实 GitHub/AI/邮件服务。

规划关系：根目录 [PRODUCT_SPEC_V2.md](../PRODUCT_SPEC_V2.md) 定义产品范围；[PRODUCT_DATABASE_DESIGN.md](PRODUCT_DATABASE_DESIGN.md) 定义增量数据契约；[PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md) 定义阶段交付。本审计以当前源码判断复用程度，后续阶段中新建的服务仍明确标为规划依赖。

## 1. 审计口径与证据

已完整阅读 `AGENTS.md`、`CODEX_MASTER_SPEC.md`、`docs/CORE.md`、`docs/FRONTEND.md`、`docs/SEO.md`、`docs/PROGRESS.md`、`CHANGELOG.md`、Phase 0–7 全部阶段报告（含 Phase 7 第二轮），以及根目录 Final QA、安全、兼容、性能和已知限制报告。旧阶段文档中“留待 Final QA”的历史记录不改写；当前验证状态以根目录最终报告为准。

每个功能只按下列四类标记，复合需求拆开判断：

| 标记 | 分类 | 含义 |
| --- | --- | --- |
| A | 已有可直接复用 | 源码已有对应能力；仍需接入新调用方及后续阶段回归，不代表新流程已验收 |
| B | 已有但需扩展 | 已有内容、服务或入口可作为基础，缺少新需求的字段、状态、授权或交互 |
| C | 尚无，需要开发 | 没有对应主题业务实现；WordPress 平台有 API 不等于主题已有完整前台功能 |
| D | 与现有设计冲突，需先调整 | 直接照搬名称、数据模型或流程会破坏既有边界，需先明确兼容方案 |

下文 `T/` 指 `wp-content/themes/ai-skills-hub/`，`C/` 指 `wp-content/themes/ai-skills-hub/inc/core/`，冒号后为此基线源码行号。表内 E 编号引用下列证据；“尚无”由模块注册、数据定义、控制器、模板及全主题静态检索交叉判断，不由单次关键词未命中独立得出。

| 证据 | 源码位置 | 实际结论 |
| --- | --- | --- |
| E01 | `C/Core.php:25`、`C/Core.php:28` | 仅注册 database/language/catalog/admin/frontend/sources/ai 七个模块，没有 Accounts、Community、Analytics、Notifications 等业务模块 |
| E02 | `C/Database/Schema.php:9`、`:11`、`:28`；`C/Database/Database.php:19`、`:27` | schema 1.5.0、十四张自定义表；稳定 Skill ID、来源/双语唯一键；实际表名为 `$wpdb->prefix . 'aish_' . entity` |
| E03 | `C/Modules/AdminModule.php:23`；`C/Admin/Pages.php:9`；`C/Security/Access.php:9`；`C/Modules/AIModule.php:28` | 五项原管理能力及两项 AI 能力默认只给 administrator；兼容 `manage_options`；不是 Subscriber 前台写权限 |
| E04 | `C/Admin/Pages.php:46`、`:63`、`:234`；`C/Admin/Management.php:62`、`:245` | Dashboard 是后台资源统计；Reviews 是当前 Skill 修订的人工发布审核，没有用户星级/体验评价 |
| E05 | `C/Admin/Management.php:188`、`:206`、`:245`、`:267` | 人工编辑只保存 draft/pending；事务内检查 revision；批准当前修订才 published；归档保留数据 |
| E06 | `C/Frontend/Forms.php:11`、`:19`、`:50`、`:63` | Contact 与 Submit 共用 `aish_message` 原生私有 CPT；登录/匿名入口同时存在；状态为 pending/reviewed/archived |
| E07 | `C/Frontend/Forms.php:112`、`:171`、`:187`、`:205` | 保存到 WP posts/postmeta，`post_author=0`；姓名/邮箱/语言/GitHub URL 为元数据；不抓取、不发信、不创建 Skill；没有用户归属和来源去重 |
| E08 | `C/Frontend/Forms.php:80`、`:122`；`C/Frontend/NativeMetadata.php:61` | Inbox 状态/备注有能力、nonce 与元数据事务；公开表单按地址 HMAC/全站限流，不保存原始 IP；不是所有新接口的现成通用限流器 |
| E09 | `C/Frontend/PublicCatalog.php:42`、`:46`、`:60`、`:223`、`:267` | 所有公开 Skill 投影要求 published、approved、当前审核修订、有效审核人/时间及当前语言完整内容；语言关系引用同一 Skill ID |
| E10 | `C/Frontend/PublicCatalog.php:127`、`:131`、`:214`、`:218`、`:303`、`:320` | 排序只有 newest/oldest/title；newest 按 `s.updated_at`；featured 为人工标签；平台为 `platform-*`；相关资源按共同分类/标签选取 |
| E11 | `C/Frontend/PublicCatalog.php:238`、`:243`、`:252`、`:258` | 作者为 null；历史 SHA 不等于审核绑定版本，`version_source_verified=false`；来源链接用 HEAD，不能推断作者或安全安装版本 |
| E12 | `C/Language/BuiltinLanguageProvider.php:9`、`:13`、`:21`；`C/Modules/LanguageModule.php:22`；`C/Frontend/Editorial.php:222` | 内置中英文规范化、可注入 Provider；WPML 公开 hook 用于教程映射，缺译不回原文，并继续校验双向关系；不是完整真实 WPML 验收 |
| E13 | `C/Frontend/Router.php:10`、`:46`、`:92`、`:103`、`:138`、`:285`、`:306` | 现有十二类路由及过滤/URL 白名单；有内置路由退出、请求和 URL filters；新路由不能只加模板 |
| E14 | `C/Frontend/Seo.php:10`、`:18`、`:77`、`:89`、`:133`；`C/Frontend/SeoSettings.php:16` | 统一 SEO 所有权、现有 noindex 和插件去重；当前结构化数据仅 WebSite/WebPage/BreadcrumbList，没有作者认证或评分 |
| E15 | `C/Frontend/Sitemap.php:18`、`:32`、`:56`、`:69`、`:105`；`C/Frontend/PublicCatalog.php:176`、`:193` | XML 类型仅 skills/category/tag/tutorials/pages；公开门禁和 noindex 在计数/分页前应用；关闭 SEO 插件 XML 缓存避免失效条目残留 |
| E16 | `C/Frontend/PublicCatalog.php:15`；`C/Frontend/Editorial.php:73`；`C/Frontend/Router.php:276` | 请求内 memo、禁用 WP_Query 持久结果缓存、公开 HTML private/no-store；不能让新榜单缓存绕过撤回/归档 |
| E17 | `C/Frontend/Editorial.php:44`、`:124`、`:145`、`:253`、`:330` | 教程是原生 `aish_tutorial`，有正文、作者、语言和相关 Skill；原生公开查询/REST 关闭，经统一公开路由输出 |
| E18 | `C/Frontend/View.php:110`；`T/templates/frontend/skill.php:40`；`T/assets/src/app.js:1`；`T/header.php:42` | 使用场景/安装/依赖由已审正文 H2 提取；现有 JS 仅 Alpine 和表单状态恢复；有响应式导航，没有账户中心、分享或命令复制流程 |
| E19 | `C/Sources/GitHubClient.php:40`、`:67`、`:91`、`:129`、`:161`；`C/Security/Input.php:40` | 规范仓库根 URL、公开身份、固定 SHA 与安全 API Client；来源路径单独校验；禁止任意网络目的地 |
| E20 | `C/Sources/ImportStore.php:298`、`:308`、`:312`、`:323`、`:369`、`:459` | 来源按 repository_id+source_path 去重；新 Skill 固定 pending；旧来源仅追加快照/变更；完整清单才标 missing；处理提示不发布 |
| E21 | `C/Sources/Scheduler.php:16`、`:31`；`C/AI/Scheduler.php:14`、`:23` | 两条独立 WP-Cron 队列，连接锁、小步骤、主题切换只清事件；不存在可直接发送业务邮件的通用队列 |
| E22 | `C/AI/Settings.php:24`、`:37`、`:151`；`C/AI/Worker.php:39`、`:53`；`C/AI/GenerationStore.php:112`、`:185`、`:195` | AI Key 仅服务器配置、允许主机、默认暂停；发请求前保存 attempt；结果不确定不盲目重发；成功输出仍待审 |
| E23 | `C/AI/GenerationStore.php:264`、`:269`、`:285`、`:298`、`:306`；`C/AI/Output.php:106` | AI 审稿历史独立；采用只填明确选择的空字段并回 pending；安装命令须逐字匹配来源证据；不是社区评分或命令安全认证 |
| E24 | `C/Database/Database.php:34`、`:95`；`C/Database/Migrator.php:24`；`C/Modules/DatabaseModule.php:26` | prepared SQL、事务/断线保护、受控迁移；普通前台不做 DDL；未知提交结果不假定已回滚 |
| E25 | `C/Sources/ImportStore.php:390`；`C/Sources/Scheduler.php:48`；`C/AI/Scheduler.php:33` | 固定码、限定数量的采集日志及私有 AI 尝试/审稿记录；不回显密钥/SQL/远端正文；没有全站业务审计台账 |

额外静态检索覆盖 `wp_mail`、用户注册/登录/密码重置、`user_register`、`wp_privacy_*`、头像、收藏/评分/Trending、通知、订阅、分享/clipboard 等入口。未发现主题的完整账户、邮件投递、社区互动、行为统计或营销订阅实现。WordPress 核心不在本仓库中，本审计没有把 WordPress 内建账户/邮件 API 写成主题已完成的前台流程。

## 2. Phase 8 — 账户与个人中心

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 原生用户身份、密码和会话 | A（平台能力） | 复用 WP Users/usermeta、认证 Cookie、密码哈希和原生密码重置 API；不建第二用户表或密码系统。主题当前仅在后台使用 WP 当前用户/能力 | E03、E04 |
| 邮箱注册、验证及重发 | C | 需前台注册、默认 Subscriber、邮箱唯一性/变更策略、令牌摘要/到期/单次消费、重发限流和验证状态。Subscriber 角色本身不证明邮箱已验证 | E01、E02、E03、E13 |
| 前台登录/退出/忘记与重置密码 | C | 包装原生认证/重置能力，新增双语表单、通用防枚举反馈、安全站内返回地址、登录后去向；不能用 nonce 代替登录或验证状态 | E03、E13、E18 |
| 资料、显示名、头像 | C | WP usermeta/头像接口仅是基础；需本人白名单字段更新、可见性和头像来源策略。不能为上传头像给 Subscriber 全局 `upload_files` 或使用可任意抓取的远端头像 URL | E03、E11、E18 |
| 用户 Dashboard | C | 当前 Dashboard 是后台目录统计。需用户中心的资料、收藏、评价、投稿、通知入口与真实空状态，逐条检查所有权，禁止把后台管理能力授给普通用户 | E03、E04、E13 |
| 隐私、导出与删除 | C | 需接入 WP 隐私导出/擦除机制，定义用户资料、互动、投稿联系信息、通知和行为记录的处理规则；删除账户不删除 Skill 主体/历史来源，不更换稳定 Skill ID | E02、E07、E09、E25 |
| 新账户页面双语/索引 | B | 复用 Router/Strings/SEO 的框架，明确注册、验证、重置、私有中心 noindex/no-store；令牌/邮箱不能进入 canonical、日志、埋点和公开页面 | E12–E16 |
| 验证/重置事务邮件 | C | 需 Phase 8 最小邮件适配、持久发送意图、去重、失败/不确定状态与限频；WP `wp_mail()` 返回成功仅代表提交给邮件系统，不证明收件。不能等到 Phase 12 才提供验证邮件基础 | E07、E21、E22 |

规划中“已验证用户”须是服务端校验的当前账户状态，不接受浏览器传入 `verified`。新社区/投稿写接口应同时检查登录、验证状态、对象所有权、POST/nonce 和业务限频；管理员审核权限独立处理。原生 WordPress 角色和七项现有管理能力保留，不自动授予 Editor、Author 或 Subscriber。账户注册、改邮箱和账户删除的并发与恢复语义需在 Phase 8 实施前固定。

## 3. Phase 9 — 收藏、评分、评价、举报与纠错

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 收藏/取消收藏/我的收藏 | C | 需以原 WP user_id + 稳定 skill_id 建立唯一关系，重复请求幂等；列表再经 PublicCatalog 过滤，不因收藏泄露已下架内容 | E02、E09、E13 |
| 1–5 星、体验、优点/缺点 | C | 需独立社区评价模型、输入限制、前台提交/编辑/撤回；同一 user+skill 跨语言只允许一条有效逻辑评价，不能用 locale 形成多份票数 | E02、E04、E09 |
| 社区评价审核与公开 | D | 当前 `aish-reviews` / `aish_review_skills` 控制 Skill 发布；`aish_ai_reviews` 保存 AI 草稿采用记录。社区评价需要独立命名、状态、能力和页面，不能复用其中任何一项语义 | E04、E05、E23 |
| 评价修订、评分人数与均分 | C | 需不可变修订及明确公开指针；首次待审不公开/不计分。编辑已审评价产生待审新稿，旧已审版本继续唯一公开/计分；批准原子替换，拒绝新稿保留旧版；撤回/管理员隐藏/账户删除立即移除有效分数 | E02、E05（仅可借鉴修订控制） |
| 举报与内容纠错 | C | 需本人提交及后台处理状态、原因、证据、处理历史和私有回复。现有匿名 Contact 可保留一般联络，不能据邮件/自由文本认定是验证账户举报或规范 Skill 纠错单 | E06–E08、E25 |
| 评分展示/结构化数据 | B | 详情/卡片和 SEO 可扩展，只读取批准且有效的评价统计；零评价保持“暂无评分”。不把待审/拒绝/撤回稿计分，不生成虚假 aggregateRating 或安全认证 | E09、E14、E18 |

评价状态和 Skill 的编辑审核状态互不替代：好评不会批准 Skill，举报也不会自动覆盖人工风险字段。对同一逻辑评价的评分更新、撤回、恢复须串行核对修订并使聚合失效；聚合任务可以延迟重建，但任何公开读取都须即时排除被隐藏的对象，不能用旧缓存重新公开。

## 4. Phase 10 — 注册投稿、开发者与认领

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 原生私有投稿收件箱 | A | 可继续使用 `aish_message`、原生列表/元数据、私有状态与人工处理。它存于 WP posts/postmeta，当前不存在自定义 submissions 表 | E06–E08 |
| 注册用户投稿/我的投稿 | B | 在原生存储上增加可信用户归属、表单修订、处理状态、规范来源字段和关联结果。保留历史 `post_author=0`；禁止仅凭旧 sender_email 批量认领到新账户 | E07、E08 |
| GitHub URL 与 source_path | B | 复用 `GitHubClient::repositoryIdentity()` 和 `Input::sourcePath()`，区分仓库根 URL 与规范相对文件路径。现有 Forms URL 字段较宽、允许额外路径，不等同规范来源键 | E07、E19 |
| 投稿并发去重/重复来源响应 | B | Skill 层已有唯一 repository_id+source_path；投稿受理层仍需事务/幂等与待处理重复处理。仓库里不同路径必须可独立投稿，不按标题、name、仓库 URL 单独合并；只公开返回本来可见的匹配资源 | E02、E05、E20 |
| 用户编辑/撤回投稿 | B | 需本人所有权和状态转换，拒绝过期编辑。撤回申请只影响投稿提案；已形成正式 Skill 的人工内容不会随提案撤回被删除或直接下架 | E05–E08 |
| 投稿审核/转入采集或编辑 | B | 管理员受理后复用既有来源/采集/人工编辑服务；来源进入 pending，完整语言和当前修订批准后才公开。用户提交 URL 不授权前台执行采集、AI 或付费连接测试 | E03、E05、E19–E23 |
| 投稿结果与进度通知 | C | 需业务事件、站内通知/邮件、收件人权限和幂等投递。现有 Inbox 状态保存与重定向提示不能视为已通知用户 | E06、E07、E21 |
| 开发者页面与作者认领 | C | 当前 Skill author 为 null，仓库 owner 仅为维护者信息。需可核验的认领证据、后台批准/撤销、用户与 Skill 的独立归属关系、公开资料选择及双语 SEO；提交者、编辑者、审核者和作者不能互相冒充 | E04、E11–E15 |
| 版本更新建议 | B | 来源快照与 incoming 变更可复用；需用户提案、来源绑定和处理结果。建议不得覆盖不可变快照/人工译文/slug/SEO，也不能把历史 SHA 宣称为已审核安装版本 | E11、E20、E23 |

历史 Contact 必须继续是私有一般联系记录；新的账户投稿不能把所有 `aish_message` 自动改成用户投稿或公开资源。现有 `pending/reviewed/archived` 的“已处理”没有包含“已创建 Skill、已批准、已发信”等含义，新状态要通过明确的类型和元数据表达。

## 5. Phase 11 — 真实数据驱动的发现

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 公开搜索/筛选/分页 | A | 当前语言真实查询、组合分类/标签/平台、稳定 ID 排序和空状态可直接复用 | E09、E10、E13 |
| Trending | C | 需有效事件、明确窗口/公式、反刷和不足样本规则；不能改名包装 featured、更新时间或 GitHub Stars。公开展示解释统计口径，不填演示热度 | E02、E10 |
| Views | C | 需定义有效详情浏览、机器人/刷新去重、匿名数据最小化、保留期、日聚合和计数一致性。不得把 HTTP 请求数、分类资源数或合成 QA 数据当真实浏览量 | E02、E10、E16 |
| Favorites / Ratings 排序 | C | 依赖 Phase 9 的真实收藏和有效已审评分；定义最小评价人数、并列和更新规则；未审核评价不能影响分数、榜单和结构化数据 | E02、E09、E10、E14 |
| 既有 latest/newest 排序 | A | 现有算法按 Skill updated_at 排序，包含后台编辑/审核等操作；可保留旧查询和 URL 兼容，不将其直接命名为新增产品的 Recently Updated | E05、E10 |
| Recently Updated / 最近内容更新 | B | 从 P9 开始在真实人工批准事务中记录 `workflow_events` 的公共内容变更事件，P11 按有证据的事件排序；没有正文变化的重审、采集 incoming 和互动不算公共内容更新。不向 skills 新增时间列，未知历史不造事件/日期 | E02、E05、E10、E20 |
| Recently Added / 最近收录 | B | 使用原实体 created_at 并明确标“收录日期”，扩展专门公开查询/入口；它不是首次上架时间。未来如需首次公开时间只取可证批准事件，历史未知不回填为创建日 | E02、E05、E10 |
| 个人最近浏览 | C（不纳入当前范围） | 当前没有该功能，用户此次未要求建立个人浏览历史；不新增此入口、持久轨迹表或跨身份历史拼接。这里只区分它与 latest/newest、匿名浏览计量的不同含义 | E01、E10、E18 |
| 编辑精选 / Editors' Picks | B | `featured` 人工标签基础可用；多推荐位、顺序、时效、双语说明和专题需扩展。编辑精选不得与赞助推荐或热榜混同 | E10、E15 |
| 平台发现 | B | 已有 `platform-*` 筛选/计数；独立平台落地页、编辑说明/SEO 可以扩展；标签仅表示有来源依据的编辑标注，不证明平台实测 | E10、E13–E15 |
| 分类/标签真实双语名称与说明 | B | 当前 categories/tags 只有共享 name/slug，双语 URL 不代表名称已有独立翻译。P11 规划旁挂 `term_translations(term_type,term_id,locale)`，保留原 taxonomy ID、slug 和关系；缺译可显示原名并标明，不能把回退当真实翻译或复制新实体 | E02、E10、E12–E15 |
| 使用场景发现 | B | 当前只有正文 H2 区块，需结构化、可审核的场景目录/关联与双语落地页；不能把正文片段/AI建议直接当已维护分类 | E18、E23 |
| Related Skills | A（现有规则） | 当前按共同分类/标签选取并按更新时间排序；可直接复用此规则。新增权重、场景或行为推荐属于 B，仍须当前语言公开门禁 | E09、E10 |

所有榜单的统计对象仍是同一稳定 Skill ID，不为中英翻译各建一份收藏/评分主体。展示时再按请求语言过滤公开译文；缺译不制造 hreflang。新 Views/Trending 可以使用异步聚合，但榜单、卡片和推荐位取数必须再核对公开状态；Skill 下架、评价隐藏、隐私变更不能等下一轮榜单计算才生效。

## 6. Phase 12 — 运营、通知与后台

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 原生菜单/列表/编辑审核 | A | 保留 WP 原生后台、现有 Skills、Inbox、Imports、AI 操作；扩展模块注册而非新建管理 SPA | E01、E03–E08 |
| 社区/投稿/认领运营审核 | B | 复用后台组件和权限校验方式，新增独立业务能力、队列、筛选与操作事件。原 Reviews 页面继续表示 Skill 发布审核 | E03–E05、E25 |
| 站内通知与已读状态 | C | 需用户可见通知、所有权、已读/隐藏、去重及失效对象处理；后台短时 admin_notice/transient 不是消息中心 | E04、E07、E25 |
| 事务邮件运营 | C | 扩展 Phase 8 邮件基础，增加投稿/审核结果模板、语言、发送记录、退避/重试和失败处理。现有采集/AI队列不能直接消费邮件任务；不能称已有投递队列或送达率 | E07、E21、E22 |
| 内容统计 | A（既有统计） | 已有 Skill 状态、总数、仓库/分类/标签计数，可直接展示为其原含义 | E04 |
| 用户/社区/投稿/行为统计 | B | 在既有后台增加真实聚合、分母/时间窗、零值/未知区分；不把当前内容数包装为注册/活跃/转化数据 | E02、E04、E10 |
| 搜索无结果分析 | C | 现有页面可显示空状态，但没有查询事件分析。需敏感词/联系方式去除、限长、最小保留与聚合；失败请求与真正零结果须分开 | E09、E10、E25 |
| 失效来源/链接处理 | B | 已有完整采集 missing、失败码和后台处理提示，可作为 GitHub 来源状态依据；仍缺运营工单/告警/重试规则，不能把不完整扫描或短暂网络失败等同链接永久失效 | E19、E20、E25 |
| 推荐位管理 | B | 从 featured 标签扩展为有位置、排序、期限、语言和发布审核的运营配置；新推荐不能绕过 Skill 门禁，不用直接改标签来模拟全部商业/运营状态 | E05、E10、E15 |
| 全站操作审计与恢复 | B | 采集日志和 AI 审稿记录可参考；需独立记录社区审核、投稿归属/状态、认领、通知和隐私动作。只保存必要操作者/事件/对象/时间，不复制密钥、令牌、完整请求或不必要 PII | E22–E25 |

邮件“已入队”“已尝试”“邮件传输接口已受理”“真实送达”应有区别。没有供应商回执时不宣称送达；未知结果不能无界重试或承诺恰好一次。验证和安全邮件与自愿订阅营销邮件分开，退订营销不会阻止必要账户安全邮件。

## 7. Phase 13 — 商业化预留

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 赞助/推广位及人工运营预留 | C | 尚无商业业务模型。可规划位置、展示标识、时段、审核及人工配置扩展点，默认关闭；仅复用现有原生后台与公开卡片 | E01、E02、E04、E10 |
| 推广与编辑精选关系 | D | `featured` 是编辑人工标签，不能改解释为付费认证；赞助展示须单独标识，不购买评分、审核批准或自然榜单名次 | E05、E10、E14 |
| 会员/权限扩展接口 | C | 只预留可扩展契约及配置，不默认制造付费角色，不改变已授权公开阅读和 Subscriber 的能力边界 | E03、E09 |
| 原创 Skills 展示 | B | 已有人工来源 Skill、双语编辑/审核及详情可复用；需原创权利依据、专门集合/入口与真实说明。仍使用原稳定 Skill ID，不建立商品版副本，不凭标记宣称原创或认证 | E02、E05、E09、E11 |
| 原创 Skills 套装 | B | 既有标签/卡片可作展示基础，套装组织、顺序及说明当前尚无；规划复用 P11 新集合与原生双语内容，逐项核对内容依据和公开门禁，不新增订单/下载执行系统 | E02、E10、E13、E17 |
| 企业定制说明与联系入口 | B | 原生说明页和私有 Contact/Inbox 可复用；需新增真实双语企业服务说明、需求类型和受限处理配置。提交仅产生私有人工联系记录，不假报订单、合同成立、付款或服务交付 | E06–E08、E13、E17 |
| 商业展示/点击统计 | C | 当前没有赞助位、原创套装或企业入口的行为统计。规划扩展 P9/P12 的 Analytics 白名单和有界聚合，明确位置、窗口、去重、统计起点及隐私；只提供授权汇总，不编造收入或暴露个人清单 | E01、E02、E04、E25 |
| 支付、订单、付费墙 | D（超出本轮范围） | 用户仅要求预留。不得新增支付 SDK、订单执行/收费、真实订阅扣费或锁住现有公开内容；未来单独取得需求与实施授权 | E01、E02；总规格首版边界 |

本阶段不能把“预留”写成“已接入商业服务”。不为尚无实际使用场景的支付数据建立运行表或调用外部收费接口；可执行设计以 V2 数据与阶段方案中的延后条件为准。

## 8. Phase 14 — 引导、教程、分享与订阅偏好

| 功能 | 分类 | 当前能力、缺口及后续边界 | 证据 |
| --- | --- | --- | --- |
| 双语教程与相关资源 | A | 原生教程、真实作者、双语对应、相关 Skill、公开边界均可复用 | E12、E17 |
| 新手引导/上手路径 | B | 增加按平台/场景组织的教程路径、进度或引导文案；不自动生产教程或假称所有平台已测试 | E10、E17、E18 |
| 分享链接/原生分享 | C | 需当前语言 canonical 的用户触发分享/复制；不带私有参数、验证令牌或用户标识；缺少分享 API 时保留可访问的普通链接 | E13、E14、E18 |
| 安装命令安全复制 | B | 已审正文可能有安装区块，AI 草稿也有来源匹配检查；前台仍无复制。需明确显示完整纯文本、来源/风险提示、用户主动复制及失败反馈，拒绝隐藏控制字符；不执行命令、不下载运行脚本、不为缺失说明捏造命令 | E11、E18、E23 |
| 自愿订阅/周精选 | C | 需单独的订阅同意/时间/来源/语言、确认与退订、频次和发送规则。注册或投稿不等于订阅，不导入历史 Inbox 邮箱，不使用合成活动填充周精选 | E07、E21、E25 |
| 内容与通知偏好 | C | 需本人可控语言/平台/场景/邮件偏好，更新不改变已公开 Skill 翻译；隐私设置及退订即时作用于后续投递 | E01、E12、E18 |
| 移动用户中心 | B | 现有响应式导航/表单可沿用，需为账户、收藏、评价、投稿和通知新增移动布局、键盘/无 JS/焦点/状态反馈验收 | E13、E18 |

“安全复制”只描述交互不会自动执行和不会隐藏实际文本，不是命令无害或来源版本已审核的保证。现有 `version_source_verified=false` 必须继续如实表达，除非后续单独建立并验收审核修订与固定来源版本的绑定。

## 9. 必须先消解的设计冲突

| 冲突 | 不可直接采用的做法 | V2 规划约束 |
| --- | --- | --- |
| `ash_` 与 `aish_` 命名 | 新表/option/capability 使用 `ash_`，或重命名现有十四表 | 全部沿用 `aish_`，实际 WP 站点前缀动态取得；候选名只作规划，不本轮迁移（E02） |
| 投稿被说成自定义业务表 | 把历史 `aish_message` 当已有 submissions 表，另写第二份主记录 | 扩展原生 posts/postmeta；如后续有索引辅助结构必须标明非主存储、必要性和恢复规则（E06–E08） |
| 匿名来信与账户归属 | 按邮箱相等将历史记录自动挂给新账户，或一律修改 post_author | 保留匿名历史；归属建立需新认证流程/人工核验，Contact 与账户投稿分别授权（E07） |
| Reviews 三种含义 | 把 Skill 发布审核或 AI 草稿审稿当用户星级评价 | 明确 Editorial Review、AI Review、Community Review 的服务、状态、权限、菜单与表名边界（E04、E23） |
| Subscriber 与管理权限 | 给普通用户 `aish_manage_skills`、`manage_options` 或审核能力以实现投稿 | 独立前台本人操作服务；登录+验证+对象权限，管理员能力保留（E03） |
| GitHub URL 与来源身份 | 仅按 URL/名称去重；改写路径大小写；跨语言生成新 Skill | 根 URL 规范化+独立 source_path，最终复用原 repository_id+source_path 唯一键；同 Skill ID 聚合互动（E02、E19、E20） |
| 近期数据含义 | 把 updated_at 当首次上架、首次创建或用户最近浏览 | 三种时间/事件明确区分；未知历史不伪造（E02、E05、E10） |
| 收藏/评价私有数据进入公开缓存 | 直接缓存管理 Catalog 或不重新校验状态的榜单 JSON | 公开投影仍由 PublicCatalog 门禁控制；私有中心按用户隔离；状态改变即时隐藏（E09、E16） |
| WPML 自动兼容推定 | 安装插件后认为所有域名/目录/新页面自动适配 | 通过现有 filters 明确路由所有者，不复制 Skill 身份；真实 WPML 仍待验（E12–E15） |
| 来源 missing 与永久下架 | 单次链接错误/不完整扫描自动删 Skill 或改作者/路径 | 来源状态、运营告警与人工发布分离；完整扫描才推断 missing，不删除历史（E20） |
| 已有 Cron 等于已有通知队列 | 让来源/AI job 装任意邮件载荷，声称 `wp_mail` 等于送达 | 新任务有自己的类型、生命周期、去重与权限；复用锁/小步骤模式，不混用既有 worker（E21、E22） |
| 商业/AI/社区影响人工内容 | 付费或好评直接发布；AI/认领覆盖人工字段；用户撤回删除正式资源 | 人工发布审核、revision、空字段采用及不可变来源规则继续独立生效（E05、E20、E23） |

## 10. 扩展位置与必须保留的合同

新增业务继续由 Core 显式模块装配，逻辑进入各自服务/控制器/原生后台，模板只接受经授权的投影。不能把新增流程集中到 `functions.php`，不能另建影子 Skills CPT、第二套仓库、用户密码或翻译主存储。

| 层次 | 可复用位置 | 扩展前必须明确的合同 |
| --- | --- | --- |
| 身份与权限 | WP Users + Access/Actions 模式 | 原生用户为唯一身份；区分已登录、已验证、本人拥有、运营审核和管理员能力；nonce 不是身份授权 |
| 数据与事务 | Database/Schema/Migrator、NativeMetadata | 新表白名单和增量版本、唯一键/并发修订、部分 DDL 恢复、WP 原生表事务条件；不能在前台迁移或删除历史 |
| 内容发布 | Management + PublicCatalog | 已发布+当前修订批准+完整当前语言始终生效；管理 DAL 不可直接暴露给前台 |
| 新页面 | Router/Strings/View/Seo/SeoSettings/Sitemap | 同时更新路由解析、URL、上下文、模板、标题/语言、权限/noindex、XML；私有中心/结果/验证页禁止收录 |
| 投稿与来源 | Forms + GitHubClient + ImportStore | 账户提案与正式 Skill 分离；规范来源和并发重复；新采集 pending，完整清单、人工确认和来源不可信边界 |
| 邮件与通知 | 新模块，借鉴 Scheduler/attempt 模式 | 最小 Phase 8 事务邮件在注册验证之前具备；事务提交后发送，去重与不确定结果明示；Phase 12/14扩展模板/运营/订阅 |
| 行为与发现 | 新事件/聚合服务 + PublicCatalog | 先定义有效事件和保留期，再做 Views/Trending；统计可延迟，权限/撤回不可延迟；禁止数据造假 |
| 审计与隐私 | 固定错误码与现有私有事件模式 | 明确谁能看、保留多久、如何导出/擦除/匿名化；不记录密钥、邮箱验证 token、密码、SQL 或完整外部错误体 |

AI 与 GitHub 是后台编辑辅助链路。注册用户提交、评价、认领或复制安装说明，不因此获得网络凭据、模型调用权、队列管理权、远端执行权或公开发布权。验证邮箱也不构成 GitHub 仓库所有权证明。

## 11. 验证状态与后续验收条件

本轮结论来自源码和历史证据，没有新的运行测试。既有 Final QA 仍为 **25 个工作包：20 通过、0 失败、5 未执行**，见 [FINAL_QA_REPORT.md](../FINAL_QA_REPORT.md) 与 [KNOWN_LIMITATIONS.md](../KNOWN_LIMITATIONS.md)。T15/T16 是真实 WP/MySQL 后端加 Mock 外部 HTTP/Provider，T17 为真实 HTTP Cron 加 Mock/阻断外部请求；不得写成真实外部服务全链路通过。

| 外部项目 | 当前状态 | V2 规划处理 |
| --- | --- | --- |
| EXT-01 真实 WPML | 未执行 | 保留合法包/实际环境条件，新页面加入后需真实联调 |
| EXT-02 真实 GitHub API | 未执行 | 不将 Git 仓库 fetch/push 或 ZIP 下载当采集验收；取得合法应用配置后单独验证 |
| EXT-03 真实 OpenAI | 未执行 | 不用 Mock 证明模型服务可用 |
| EXT-04 真实兼容 Provider | 未执行 | 不据 OpenAI 格式接口推定供应商协议/计量已通过 |
| EXT-05 生成质量、注入抗性、账单 | 未执行 | 真实输出和人工质量/证据/费用核对仍需单列 |
| 新账户邮件送达、邮箱验证和重置 | 尚未实现/未验收 | WP API、队列单测或传输受理不能代替真实收件验证；与原五个 EXT 统计分开 |

已有真实 QA 也不覆盖全部多站点、Redis/Memcached、CDN/页面缓存、生产 HTTPS/邮件、真实 Safari/iOS、长期十万级负载或精确 COMMIT 确认丢失窗口。新功能需要各阶段的真实权限/所有权矩阵、并发唯一性/修订竞争、双语与缺译、撤回/聚合失效、隐私、真实邮件及原 Phase 0–7 回归；这些是后续验收要求，本轮没有执行或预先标为通过。

现有历史报告、十四表、稳定 ID、双语关系、来源唯一键、人工内容及既有安装包继续作为保留基线。进入 Phase 8 的实施动作需以用户后续明确启动为准，本审计本身不启动开发。
