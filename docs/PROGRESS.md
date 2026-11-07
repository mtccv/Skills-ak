# 开发进度

## Phase 14 — 用户体验、学习体系与自愿订阅（开发完成，验收有保留项）

基于main `1282c4b756b3a5b020aa18e62a9295cee213d549`，主题/Core **0.14.0**；schema **1.9.0 / 33张Core表保持**。LearningModule复用已有账户、教程、社区、投稿、发现、通知及加密outbox：八类可跳过引导、独立中英帮助/规范草稿、阅读导航、来源与许可绑定的复制分享、自愿双重确认订阅及人工批准精选批次。不自动订阅/发布，不执行未知命令，不改Phase7.5原型。

开发检查与修复证据收敛到[Phase14报告](../PHASE_14_REPORT.md)和 `tests/experience/results/`；真实WordPress/MySQL/Chromium、真实存储搭配Mock传输、隔离与静态检查分开统计，旧阶段报告不继承。33表DDL及原Skills/译文/版本/收藏/评价/投稿/Contact/Users等原集合一致；旧QA outbox、日聚合与83管理员会话有5项集合字节差异，无法用原集合哈希逐行恢复，单独作为未通过的保留项。邮箱变更撤销旧订阅、原生配置权限、隐私擦除生命周期及后台Header问题均已修复回归。

双PHP各75隔离脚本/5935断言、374文件语法、JS117断言、构建和打包器8测试通过。真实环境最终去重243功能用例通过、0未解决功能失败（含Mock邮件传输的场景另标）；Core各162断言、原生包升级18检查及214文件字节核对通过。7类外部/扩展依赖未执行，不宣称完整验收或正式上线。

[帮助中心](../HELP_CENTER.md) · [社区规范](../COMMUNITY_GUIDELINES.md) · [邮件订阅](../EMAIL_SUBSCRIPTION.md) · [安装与复制](../INSTALLATION_GUIDE.md) · [安装包0.14.0](../release/ai-skills-hub-0.14.0.zip)。[Final QA2.0完整计划](../FINAL_QA_2_PLAN.md)仅交付计划；本阶段完成正常提交同步main后暂停，不自动执行下一轮验收或正式上线。



## Phase 13 — 商业化能力预留（实现及本环境验证完成）

基于main `46d63dbfd256ae38c579f65608ee46876cf545f0`，主题/Core **0.13.0**，schema **1.9.0 / 33表保持**。完成默认关闭的人工赞助/企业说明、复用集合的套装入口、当前用户只读免费权益、私有企业咨询及独立最小指标。免费账户/社区/投稿/公开审核规则不变，不包含支付、订单、付费墙、营销群发或主题授权服务，Phase7.5原型保持。

先完成Phase12概览性能遗留：真实WordPress/MySQL快照服务管理员201→87条SQL，受限权限和非零统计语义保持；两PHP各三次的36次权限/前后测量，加18组有限规模配对测试，详见[性能报告](../PERFORMANCE_REPORT.md)。本轮发现并修复同意用途、赞助公开URL/素材门禁、Cookie撤回及套装锁升级死锁等问题。

PHP8.3.36/8.4.27各67个隔离/Mock脚本、5106断言通过，328文件语法通过；前端构建、JS60断言、打包器8测试通过。两套真实WP7.1.4/MySQL8.0.46与Chromium的领域/性能语义/原运营/浏览器共164场景、2766断言通过（其中100为真实数据库＋Mock邮件传输），另158保留、40并发、162Core和4包一致性检查通过。原生0.12.0→0.13.0 ZIP安装/升级及新进程启动18检查通过；精确证据及限制以[阶段报告](PHASE_13_REPORT.md)为准，不将Mock、历史结果或未执行外部集成列为真实通过。

[商业预留说明](COMMERCIAL_READINESS.md) · [阶段报告](PHASE_13_REPORT.md) · [运营指南](OPERATIONS_GUIDE.md) · [0.13.0开发包](../release/ai-skills-hub-0.13.0.zip) · [安装配置](../INSTALLATION.md)。最终证据位于tests/commercial/results，历史阶段证据不覆盖；真实邮件/WPML/外部API/插件缓存及生产负载仍按本轮未执行报告。正常提交同步main后暂停等待Phase14，不自动进入下一阶段或正式发布。

## Phase 12 — 运营管理、通知与数据分析（开发及本环境检查完成）

基于 main `997bdd05a0332cd7e9603323d36a9de16810a1c7`，主题/Core **0.12.0**，schema **1.9.0 / 33 表保持**。新增按权限的运营概览、统一待处理工作台、用户暂停恢复、站内通知完善、邮件诊断和有限恢复、真实指标与隐私搜索分析、来源异常处理以及首页私有预览/有效期/选材审计。复用所有已有账户/审核/通知/统计/发现服务，不改变ID、角色或人工发布门禁；Phase7.5原型不变。

PHP8.3.36和8.4.27各63个隔离/Mock脚本、4806次断言通过，前端构建、JS29断言、打包工具8测试通过。PHP8.4已通过轻量Cloud容器恢复，没有删除旧数据或凭据。真实WordPress7.1.4/MySQL8.0.46两环境94场景/1224断言（含真实数据库＋Mock邮件100断言）、另184保留/并发/包检查及162旧Core断言通过，0最终失败；开发ZIP已原生升级验证。精确结果统一以 [本阶段最终报告](PHASE_12_REPORT.md) 及 `tests/operations/results/` 为准；未执行外部依赖不计为通过。

[运营使用](OPERATIONS_GUIDE.md) · [指标口径](ANALYTICS_DEFINITIONS.md) · [通知邮件](NOTIFICATIONS_AND_EMAIL.md) · [0.12.0开发验证包](../release/ai-skills-hub-0.12.0.zip) · [安装配置](../INSTALLATION.md)。本轮正常提交同步main后暂停等待Phase13，不进入Phase13/14或正式发布。

## Phase 11 — 资源发现与推荐（开发及必要检查完成）

基于main `c7fa503432ceb4fc971c3b7d44b44591e3c0a7af`，主题/Core **0.11.0**、schema **1.9.0**。重新核对26表，增量增加规划7表至33表；原表结构/数据、SkillID、收藏、评价、双语与历史私有Inbox保持。实现六排行、明确同意的有效浏览、编辑集合、场景、术语翻译、平台专题、相关/相似推荐与双语首页配置；不使用演示排行，不自动发布，不进入Phase12。

PHP8.3语法263文件、56隔离/Mock脚本4477断言执行、JS16断言、Tailwind/esbuild及打包检查通过。真实WP7.1.4/PHP8.3.36/MySQL8.0.46迁移保留、全新/重复安装、领域流程、并发、清理和Core生命周期通过；Chromium中英及三宽度、后台权限/Nonce、SEO和真实浏览同意/去重通过；真实检查合计833次执行、0最终失败，原生ZIP升级及160文件/33表数据保留验证通过。精确最终结果、文件清单及限制见 [Phase11报告](PHASE_11_REPORT.md)，不把Mock写为真实外部服务。

PHP8.4镜像拉取存储不足，本轮未执行；真实WPML、GitHub/AI、邮件、SEO插件组合、多站点、其他浏览器及生产负载未验。提供 [0.11.0安装包](../release/ai-skills-hub-0.11.0.zip)、[发现系统](../DISCOVERY_SYSTEM.md)、[使用场景](../USE_CASES.md)。正常提交同步main后停止，等待Phase12指令；Phase7.5原型未改。

## Phase 10 — 用户投稿与开发者中心（实现及已执行检查完成，等待用户验收）

基于用户指定 `93738c139b5c8e04243d5b5757e30356147c7ddb`，主题/Core **0.10.0**、schema **1.8.0**。只新增 author_claims 至26表，原25表、Skill ID、版本和双语关系保留。投稿/更新建议复用私有 Inbox 及不可变修订；新增受控归属、状态审核、最终资源关联、站内结果通知、公开资料审核快照、作者/维护者认领和隐私清理。Accepted不等于Skill发布，认领不授予编辑权；不触发用户指定来源的外部请求。

PHP8.3/8.4各48个隔离/Mock脚本、3883断言及232个PHP文件语法通过；真实WP7.1.4/MySQL8.0.46迁移、投稿、开发者、认领、隐私和并发均通过。真实Chromium中英/320/768/1440页面102项通过；PHP8.4旧Core生命周期148检查和P9社区23用例/114断言回归通过。分类明细、实际问题修复和未执行范围见 [PHASE_10_REPORT.md](PHASE_10_REPORT.md)，不把Mock邮件描述为真实送达。

[0.10.0安装包](../release/ai-skills-hub-0.10.0.zip) · [投稿说明](SUBMISSIONS.md) · [开发者中心](DEVELOPER_CENTER.md) · [安装配置](../INSTALLATION.md)。原型不修改，视觉保持白灰/深灰/陶土棕。本轮真实SMTP、WPML、GitHub/AI外部API及第三方插件/生产负载未验；原有数据和匿名Contact/投稿保持。正常提交同步main后暂停，等待Phase11指令，不自动进入下一阶段。

## Phase 9 — 用户互动系统（历史交付，用户已验收）

基于 `02d37d3`（含指定 `40777e5` 和账户版安装包），主题/Core **0.9.0**、schema **1.7.0**。新增私有收藏、真实星级/体验、不可变评价修订、批准版切换、原生社区审核、独立举报/纠错、社区通知和隐私清理。原 17 表与数据保留，只追加八表至 25 表；稳定 Skill ID、双语关系、历史 Contact/Submit 和 Phase 7.5 原型不变。不开发 Phase 10。

PHP 8.3/8.4 各 40 个隔离/Mock 脚本、3291 断言通过。真实 WordPress 7.1.4/MySQL 8.0.46 的迁移保留、领域流程、并发、通知/擦除竞争、隐私、旧 Core 生命周期和账户导出通过；真实 Chromium 中英与响应式 78 项通过。另验证 0.8.0→0.9.0 原生 ZIP 升级、25 表与113安装文件一致。详见 [PHASE_9_REPORT.md](PHASE_9_REPORT.md) 和 `tests/community/results/`，不把 Mock 邮件当真实送达。

交付 [0.9.0 主题 ZIP](../release/ai-skills-hub-0.9.0.zip)、[社区使用说明](../COMMUNITY_REVIEWS.md)、[安装说明](../INSTALLATION.md)和更新后的隐私文档。真实 SMTP、WPML、第三方账户/缓存插件、多站点及生产负载未验证；本轮无真实 GitHub/AI 调用。当前最小事件只包含真实互动及公开内容批准，未启用浏览/搜索统计或榜单。正常提交同步 GitHub main 后暂停，等待用户安排 Phase 10。

## Phase 8 — 账户与个人中心（历史交付，用户已验收）

补充交付：以实现提交 `40777e537f84184e6438005aec39f3bf8ba1b05c` 构建可上传的 [0.8.0 主题 ZIP](../release/ai-skills-hub-0.8.0.zip)，含已编译资源；更新安装说明及双版本 SHA-256 清单，保留历史 0.7.1 包。包检查见 [发布记录](../release/PHASE_8_PACKAGE.json)。不进入 Phase 9。

基于 `556ea8d`（包含指定的 `56d790a` 及绿色补修），主题/Core 0.8.0、schema 1.6.0。新增原生 Users/Subscriber 的中英文注册、验证、登录/恢复、资料/头像、Dashboard、安全与隐私申请、账户通知和加密邮件队列。仅新增三张账户表至 17 表，原十四表/Skill ID/译文/来源键/历史私有来信保留；Phase 7.5 原型不改，不开发 Phase 9 功能。

实际检查：PHP 8.3/8.4 各 151 文件语法、33 个隔离/Mock 脚本各 2876 断言通过；真实 WordPress 7.1.4/MySQL 8.0.46 的账户流程、迁移/数据保留、并发注册、隐私与受保护导出通过；真实 Chromium 中英 69 项与既有 HTTP 冒烟 15 项通过。完整分组、断言和证据见 [PHASE_8_REPORT.md](PHASE_8_REPORT.md)。修复真实表单 Origin:null、原生认证限流、旧角色兼容、原生密码更改后的邮箱凭证、隐私请求邮箱复用及导出访问等问题后回归。最终构建、资源/打包器和差异检查通过。

邮件传输使用 Mock，未测试真实 SMTP/收件箱；本阶段真实 WPML、第三方认证/邮件/缓存插件、多站点和其他浏览器未执行，不承接历史 Final QA 为本阶段背书。使用说明：[ACCOUNT_SYSTEM](../ACCOUNT_SYSTEM.md)、[EMAIL_SETUP](../EMAIL_SETUP.md)、[PRIVACY_AND_SECURITY](../PRIVACY_AND_SECURITY.md)。旧 release/0.7.1 ZIP 保留为历史交付，本阶段代码通过构建打包脚本生成 0.8.0；不把旧 ZIP 描述为账户版安装包。

代码正常提交并同步 GitHub main 后暂停，等待用户安排 Phase 9；本记录不代表用户已完成最终验收。

| 阶段 | 状态 | 记录 |
| --- | --- | --- |
| Phase 0 项目初始化 | 用户已验收 | PHASE_0_REPORT.md |
| Phase 1 数据库与 Core | 用户已验收 | PHASE_1_REPORT.md；历史 CI 证据保留 |
| Phase 2 WordPress 原生后台 | 用户已验收 | PHASE_2_REPORT.md |
| Phase 3 中英文双语前台 | 用户已验收 | PHASE_3_REPORT.md / FRONTEND.md |
| Phase 4 搜索与 SEO | 用户已验收 | PHASE_4_REPORT.md / SEO.md |
| Phase 5 GitHub Skills 自动采集 | 用户已验收 | PHASE_5_REPORT.md / GITHUB_IMPORT.md / IMPORT_SECURITY.md |
| Phase 6 AI 双语内容 | 用户已验收 | PHASE_6_REPORT.md / AI_USAGE.md / API_CONFIGURATION.md / AI_SECURITY.md |
| Phase 7 安全、性能与稳定性 | 用户已验收 | PHASE_7_REPORT.md / PHASE_7_ROUND_2.md / ../CHANGELOG.md |
| Final QA | 已执行范围通过，外部验收有保留；20 通过 / 0 失败 / 5 未执行 | ../FINAL_QA_REPORT.md；真实 WPML / GitHub / AI 仍未验证 |
| V2 产品扩展准备 | 规划完成，Phase 8 已获实施授权 | ../PRODUCT_SPEC_V2.md；PRODUCT_DATABASE_DESIGN.md / PRODUCT_MODULE_AUDIT.md / PRODUCT_ROADMAP.md |
| Phase 7.5 全站 UI/UX 设计 | 用户已初步审核通过；作为后续设计标准 | DESIGN_SYSTEM.md；design-prototype/README.md；原型保留 |
| Phase 8 账户与个人中心 | 用户已验收 | PHASE_8_REPORT.md；真实邮件等外部依赖未验收 |
| Phase 9 用户互动系统 | 用户已验收 | PHASE_9_REPORT.md；外部依赖如实列出 |
| Phase 10 投稿与开发者中心 | 本轮实施与检查 | PHASE_10_REPORT.md；等待用户验收 |
| Phase 11–14 | 尚未实施 | 需逐阶段明确授权；规划不等于开发完成 |

## Phase 7.5 原布局配色调整（历史交付，设计继续采用）

最新补修：按用户截图清除薄荷绿/绿色残留，包含首页书本图标、用户中心在线点/收藏图标/隐私面板及成功徽章；统一灰白/深灰，状态文字与原布局不变。已扩展现有配色检查以核对紫色和绿色均无残留，并更新截图与预览包。

以 `2d6df357f871bb31183182c02f7cd28dc80b1b18` 为本轮基线，保留原五页布局、文案、模块及交互。按用户要求实际查看SkillsMP首页与搜索页，仅借鉴白/浅灰底、深灰字与少量陶土棕的配色；参考主色#AD5E48、悬停#9F513C，原紫色及紫色渐变/阴影已移除。控件边框采用本项目对比度校验后的#858C97；后台操作蓝及语义状态色保留。

仅设计规范、隔离原型颜色、截图/检查证据及预览包变化。Chromium155的101项原流程与27项颜色/布局检查全部通过（128/128）；五页中英桌面/手机几何差0 CSS px，14项当前配色对比度计算通过；设计构建、JS语法与边界检查通过。完整证据见[当前原型说明](design-prototype/README.md)。正式主题、数据库、生产安装包和历史Final QA结论均不变，不开始Phase8，等待用户审核。

## Phase 7.5 恢复整套修改前设计（历史，布局继续采用）

用户明确要求全部采用修改前设计，不保留后来改版。当前从 `04a8190fd7938749efbb142430c6e5ff23cb38c5` 恢复首页、Skills列表、Skill详情、用户Dashboard、WordPress后台Dashboard、共享组件和五份设计规范；取消目录改版、双风格选择器及其活动资源。后续修改保留在Git历史中，不覆盖原提交；当前原型与预览包仅包含原方案。

本轮15个运行/检查源文件逐字节匹配原版；重新构建设计资源，Chromium155浏览器77+24共101项通过、0失败；6个JS/CJS语法、12份Markdown的166个本地链接、6个HTML资源/锚点及生产边界检查通过。已重打仅含原设计与截图的预览包，结果记录于[原型说明](design-prototype/README.md)。正式主题/Core0.7.1、schema1.5.0、数据库、安装包和历史Final QA结论不变；没有进入Phase8。完成后等待用户审核。

## Phase 7.5 双首页风格模块化（历史，已撤回）

以 `1fbd2909bbcf52f0d13d22d46d48215000588131` 为基线，按用户要求将专业资源目录（默认 `skills-directory`）与 SaaS 展示首页（`modern-saas`）做成可切换静态风格。共用九个稳定模块与同一示例内容，切换不重置中英文文案、模块显隐/顺序或收藏；仅影响首页，其余内页及原生后台维持原设计。

提供首页工具栏、设备预览器与后台“首页外观”三个入口。后台可保存内存草稿、独立查看草稿/线上示例、确认发布、按历史修订恢复风格和双语配置为新草稿。未知前台演示ID回退默认；未知后台ID拒绝保存/预览/发布。所有状态仅当前页内存，刷新重置；没有实现真实WordPress配置保存。

本轮Cloud Chromium155实际通过79项原有五页回归、34项后台流程、30项双风格检查，合计143通过、0最终失败；8张双风格中英桌面/手机截图归档，原五页前后对比不覆盖。首次新增后台测试定位歧义已修正。最终审查修复发布侧栏风格未同步、Hero资源投影未遵守缺译/异常/模块隐藏状态两处原型问题，并补充回归。独立Tailwind构建、JS语法、文档/资源链接及生产文件边界检查通过，更新设计预览ZIP；历史证据见[当时原型说明](https://github.com/mtccv/Skills-ak/blob/6c616fa4a4bb0a37cb82287390a49861a1978e46/docs/design-prototype/README.md)。仅设计文档、隔离原型与证据变化，未修改正式主题/数据库/安装包，未新增依赖；不把静态内存演示当作真实后台集成。等待设计评审，不开始Phase8。

## Phase 7.5 视觉方向修订（历史，已撤回）

以设计提交 `04a8190fd7938749efbb142430c6e5ff23cb38c5` 为修改前基线，实际查看 SkillsMP 首页、搜索页和详情页，只学习视觉结构与信息密度。将五份规范和五页静态原型统一调整为专业 Skills 资源目录：白色/浅灰、克制紫色、64px桌面导航、搜索靠前、紧凑单列资源列表、技术文档式详情与明确来源/License/版本/风险。用户中心采用记录列表，后台保留原生WordPress控件。静态风格ID改为 `skills-directory`，原稳定模块ID与双语/状态/审核流程保持。

本轮重新执行Chromium155的79项前台/状态/交互与24项后台内存预览检查，103通过、0失败；对比页8项检查通过，50张前后原图/并排图及其尺寸、SHA已核对；10组新基础配色对比计算通过，设计独立Tailwind构建、JS语法和设计边界检查通过。前后截图来自同尺寸、同语言、默认状态的真实静态页面；见[历史五页对比](https://github.com/mtccv/Skills-ak/blob/1fbd2909bbcf52f0d13d22d46d48215000588131/docs/DESIGN_COMPARISON.md)及[当时原型证据](https://github.com/mtccv/Skills-ak/blob/1fbd2909bbcf52f0d13d22d46d48215000588131/docs/design-prototype/README.md)。仅设计文档、隔离HTML/CSS/JS、截图和设计预览包变化；正式主题、数据库、安装包、历史Final QA结论均未改。修订完成后等待用户审核，不开始Phase8。

## Phase 7.5 初稿（历史实现，当前恢复采用）

初稿基于当时 main `6f7790d58d1884d400d7fd6ad5156449ec134081` 审查现有主题、Core、前后台、语言/SEO、采集/AI与V2规划；形成五份设计合同：DESIGN_SYSTEM、FRONTEND_DESIGN_SPEC、USER_CENTER_DESIGN、ADMIN_DESIGN_SPEC、RESPONSIVE_DESIGN。初稿前台采用Modern SaaS浅色卡片风格（本轮全部恢复采用），后台保留WordPress原生运营结构；后续Phase8–14须依据这些规范验收。

新增隔离HTML首页、列表、详情、用户Dashboard、WP后台Dashboard，含中英、设备/状态预览、演示筛选、收藏/评价反馈、原生后台待办与首页配置内存流程。首页独立模块、样式与运营数据分层、双语配置、草稿/发布/CAS、未来排期合同均有设计；生产配置能力归后续授权范围，未实现生产首页重构或页面构建器。初稿当时没有可读取的参考图；后来的目录修订曾实际查看用户指定的SkillsMP，该修订现已撤回。

实际Chromium155静态浏览器77项页面/状态/交互+24项后台配置检查通过，共101通过/0最终失败；另10组基础配色对比度计算通过。独立Tailwind构建、JS语法、文档与HTML链接/边界检查通过，保存真实截图与可复现脚本。初轮误判测试与2px预览宽度问题已修正，投稿状态/设计token/显式应用筛选已对齐。见[原型说明和全部证据](design-prototype/README.md)。直接file://导航被托管浏览器策略阻止，未完成离线交互检查；本机HTTP预览为已测替代。Firefox/WebKit、真实设备/读屏和完整缩放未测试。

轻量[静态设计包](ai-skills-hub-design-preview.zip)不是WordPress安装包。仅Markdown和docs内设计专用HTML/CSS/JS/图片/检查证据/ZIP变更，正式主题代码、CODEX_MASTER_SPEC.md、schema1.5.0十四表、数据和release主题ZIP均不变。没有PHP业务修改、迁移、真实WordPress/MySQL/API或账户功能开发。历史Final QA限制保留，不将设计检查当成产品集成验收。设计同步main后暂停，等待用户评审，不开始Phase8。

## V2 规划（此前规划，2026-11-06 Asia/Shanghai）

以 main `1c27f8770fc3bd20b2ffd92d8873444ba92d54c8` 为基线，只读审查总规格、技术文档、Phase 0–7 及 Final QA 报告和相关模块；新增四份产品/数据库/复用/路线图 Markdown。当前代码定义 **14 张自定义表、151 个列定义**，schema **1.5.0**；WordPress 原生用户、文章、元数据与其他插件表不计入。没有启动服务、连接运行数据库或迁移，未将静态盘点称为本轮数据库运行测试。

建议保留原十四表，资料/偏好复用 usermeta，投稿继续私有 `aish_message`，新数据按 1.6.0→1.7.0→1.8.0→1.9.0→1.10.0 分阶段增加；P12/P13 无 DDL 不虚增 schema。最终建议新增20表、累计34表，均未实施。明确三个 Reviews 语义、匿名投稿不能按邮箱认领、待审评价不计分、收藏重加不刷热度、真实统计/邮件前置、原生删号隐私边界及分类标签旁挂翻译。

本轮仅检查文档一致性、源码表清单、相对链接和 Markdown 差异：8 个 Markdown 变更、71 个本地链接、新增设计20表/现有14表151列核对通过，`git diff --check` 通过。PHP/构建/数据库/浏览器/API 测试不在纯规划执行范围，未重新运行。原主题/Core 0.7.1、安装包、CODEX_MASTER_SPEC.md、运行代码和历史验收不变。规划提交同步后暂停，等待用户审核，不自动进入 Phase 8。

## 已完成阶段的历史记录

Phase 2 范围为菜单/统计、Skills 人工及来源录入、双语编辑、状态/归档、分类/标签、手动仓库、人工审核/风险/备注、私有阅读预览、列表/批量和 capability/nonce 安全控制。

数据沿用原八表及稳定 Skill ID，Phase 2 通过 1.2.0 增量迁移添加后台必要字段。Phase 3 实现公开前台与内置语言路由，使用既有标签和 WordPress 原生存储补充教程与私有投稿；不修改业务 schema。Phase 4 实现搜索、SEO 及 WPML 公开 API 接口；Final QA 已验证免费 SEO 插件，真实 WPML 仍缺合法包。Phase 5 增加 GitHub 自动采集；Phase 6 增加独立 AI 草稿、任务和人工处理，不自动发布。

历史阶段只执行必要 PHP、构建及基本检查；当前已按用户授权执行 Final QA 真实集成，明确保留外部未验收项。历史通过不自动覆盖新代码。仓库 CI 默认只运行 PHP/资源检查，完整数据库/WordPress 基线须手动选择 `final_qa=true`。

Phase 2 代码提交 `e25da3241ab7830f0d34f8157063863b456d578a`；[CI 基本检查](https://github.com/mtccv/Skills-ak/actions/runs/41904484033) PHP 8.3/8.4 与 assets 通过，数据库/WordPress 任务按策略跳过。

Phase 3 当前范围：原创响应式 SaaS 主题，12 类页面，中英文词典与路由、实际翻译跳转及缺译回退，真实公开 Skills 搜索/筛选/排序/分页，教程与私有来信，基础 Meta/Canonical/hreflang/面包屑。高阶 SEO 与 Sitemap 留 Phase 4，完整运行验收留 Final QA。用户已明确同意表单保存为后台私有待处理记录。

Phase 3 代码提交 `6cdc701c5553129307656c458ec89a5d27a0c19f`；[CI 基本检查](https://github.com/mtccv/Skills-ak/actions/runs/41914668942) 的 PHP 8.3/8.4、隔离路由断言及 assets 通过。完整 WordPress/MySQL 任务按策略跳过，没有进入 Phase 4。

Phase 4 在已验收 Phase 3 的 `729b0a92a7659d7bda5bbafe10a76a5f49a512b0` 上实现中英文 Skills/教程搜索、组合筛选、批量查询、双语 SEO 配置和 Meta、hreflang/x-default、分页 XML Sitemap、插件输出协调与 robots 策略。主题/Core 0.5.0；schema 1.3.0 仅增加两个非唯一索引，保留原八表和全部数据。没有提前实现自动采集或 AI。

Cloud PHP 8.3.36 全部 51 个文件语法通过，342 项隔离基础断言通过，Tailwind/esbuild、资源及脚本检查通过。真实 MySQL、WordPress、WPML、SEO 插件、浏览器、并发和端到端均仍待 Final QA，隔离检查不作为集成验收。实现提交 `3df1e0573f84a8d7c563094ebf16af383cc2a636`，已同步 GitHub main；[CI 41917878687](https://github.com/mtccv/Skills-ak/actions/runs/41917878687) 的 PHP 8.3/8.4 与 assets 均通过，WordPress/MySQL 任务按策略 skipped。报告及本进度随后进行纯文档补记，不改变已检查代码。Phase 4 完成后暂停。

Phase 5 以已验收 Phase 4 的 `3766dc1b410e50dd9873b1ef52a1a818d4a3f8fb` 为基线，实现受限 GitHub API、固定 SHA 树发现、truncated 分层回退、安全 Frontmatter、静态风险检查、来源去重与增量同步、原生采集后台和可恢复 Cron 小任务。主题/Core 0.6.0、schema 1.4.0；原八表保留，仅追加仓库/版本必要字段及 imports/jobs/import_logs 三张运营表。新资源固定 pending，不生成译文；旧来源只追加快照和待处理通知，不覆盖人工内容或自动发布。

新增模拟检查覆盖 API/发现/解析/存储/后台/worker，并保留原有路由、搜索与 SEO 基础回归。67 个 PHP 文件语法、827 项隔离断言、Tailwind/esbuild、资源/脚本及 Compose 配置检查通过。实现提交 `6cccdf526e2be05c55e8739908379438455de5a7` 已同步 GitHub main；[CI 41922541600](https://github.com/mtccv/Skills-ak/actions/runs/41922541600) 的 PHP 8.3/8.4 和 assets 均 success，WordPress/MySQL 按策略 skipped。证据详见 [Phase 5 报告](PHASE_5_REPORT.md)，Git/CI 补记为随后纯文档提交。

真实 GitHub API、MySQL、WordPress Cron/后台、WPML、浏览器、并发与端到端均未执行，留 Final QA；没有部署外部服务。Phase 5 完成后暂停，等待 Phase 6 指令。

Phase 6 以 GitHub main `4a7d70c3eeda9c4d4f1f668508ea7d036b4bb4fe` 为基线，新增可替换 Provider、OpenAI/兼容协议、多模型配置、结构化双语/局部草稿、证据与风险建议、原生 AI Settings/Generation、可恢复 Cron 与用量/估算成本。主题/Core 0.7.0、schema 1.5.0；保留原十一表定义，追加 ai_jobs/ai_attempts/ai_reviews。API Key 只存服务器配置，队列默认暂停。人工采用检查来源与编辑修订，只填选中的空字段，保留人工内容并转正常待审核。

本轮 Cloud PHP 8.3 语法检查 87 文件、1466 项隔离/模拟断言、前端构建与资源、脚本及 Compose 配置检查通过。实现提交 `b5c159bf8befb6a4fdc941f5d2384bffccb3b8ac` 已同步 GitHub main；[CI 41927463668](https://github.com/mtccv/Skills-ak/actions/runs/41927463668) 的 PHP 8.3/8.4 和 assets 均 success，WordPress/MySQL 按策略 skipped。Git/CI 补记为随后纯文档提交。

没有执行真实 AI API、MySQL、WordPress、WPML、浏览器或端到端测试；完整验收仍留 Final QA。文件、限制及证据见 [Phase 6 报告](PHASE_6_REPORT.md)。Phase 6 完成后暂停，等待 Phase 7 指令。

Phase 7 已获授权，基线为 main `b3295f643c167ab7a6b5bba60e01f58359253cc3`。用户明确表示还会补充“任务重复”之后的条目，本批仅先处理已知安全、数据完整性与采集稳定性范围，不代表阶段完成。

首批修复统一仓库归档、仓库身份并发读取、采集对账恢复、AI 长 JSON 重复键检查、审稿历史按需读取，以及原生语言/配对/来信元数据的原子保存。schema 1.5.0 和十四表未变，数据保留。Cloud 91 个 PHP 文件语法、1739 项隔离断言、前端构建/资源/脚本/Compose 检查通过；npm audit 本次报告零个已知漏洞。真实 API、WordPress/MySQL、插件、浏览器和并发仍未执行。审查覆盖、证据、限制与文件见 [Phase 7 进行中记录](PHASE_7_REPORT.md)，等待补充要求后继续。

首批实现提交 `79ec692dfaab00bb28452486ff6a8b234919eb26` 已同步 GitHub main；[CI 41975787195](https://github.com/mtccv/Skills-ak/actions/runs/41975787195) 的 PHP 8.3/8.4 和 assets 均 success，WordPress/MySQL 按策略 skipped。此 Git/CI 补记随后单独提交，不改变已验证代码；Phase 7 保持进行中。


## Phase 7 第二轮

用户已补齐十二项审查及退出要求；以首轮最终 main `205d202d63b23008834ed8533806ee62e34d9edd` 为基线。跨模块、事务/并发模型、GitHub/AI 恢复、后台权限、私有公开门禁、多语言/SEO、查询/缓存、Cron、日志及主题生命周期均完成代码复核。

修复旧采集版本绑定和同 HEAD 基线漏核验、检查点仓库状态、连续 lease 失效上限、AI 新请求误报受理、连接 JSON 检查、Settings 虚报保存、语言/URL adapter 失败、SEO 索引读取错误、教程载荷，以及 wpdb 自动重连丢失事务/连接锁的问题。交叉复核还修正了严格模式与 dbDelta 缺表探测的兼容回归；菜单模型误报被撤回，未为增加修改数量改动稳定入口。确认的重要代码问题均已处理，发现、修复、测试和边界详见 [第二轮报告](PHASE_7_ROUND_2.md)。

主题/Core 仍为 0.7.0，schema 仍为 1.5.0；没有改表定义、删除数据或重建数据库，Skill ID、双语关联、人工内容及历史保留。未增加依赖、自动发布或未知脚本执行。九个新隔离检查加入默认 PHP CI，完整集成开关仍未开启。

Cloud PHP 8.3.36 的 100 个 PHP 文件语法、29 组 2679 项隔离/Mock 断言、前端构建/资源、JS/Bash、Compose 及差异检查通过；npm audit 本次为 0 个已知漏洞。九个新增测试入口共 818 项，全部断言比首轮增加 940 项。所有真实 WordPress、MySQL、WPML、GitHub/AI API、浏览器、并发与端到端仍未执行。Phase 7 代码工作已完成并暂停，等待用户安排 Final QA，不自动运行或部署。

实现提交 `49ee0a757e88f622cc4d37f0f07160e221bfb169` 已同步 GitHub main；[CI 41982408246](https://github.com/mtccv/Skills-ak/actions/runs/41982408246) 的 PHP 8.3、PHP 8.4、assets 全部通过，wordpress-smoke 按策略 skipped。最终证据补记为随后纯文档提交，不修改已验证代码。


## Final QA（当前交付）

以 `d6401c2d6fb810605f5ba87524301ecc50418971` 为基线，在 Codex Cloud 新建隔离 WordPress/MySQL/浏览器环境，未使用生产数据或部署外部服务器。主题/Core 0.7.1，schema 仍1.5.0。

- 真实 WP 7.1.4 / PHP 8.3.36、8.4.27 / MySQL 8.0.46，及最低 WP 6.8：数据矩阵合计678断言通过；来源/AI多进程、事务、连接中断和真实HTTP Cron验证通过。外部Provider仍Mock。
- Chromium112、Firefox52、WebKit52，实际后台/表单持久化13；安全207+28；SEO四模式5990，均通过。性能真实样本192/192 HTTP，记录实际SQL/EXPLAIN，不承诺生产容量。
- 修复7个真实问题：搜索参数编码P1；Yoast标题、robots、Schema.org、RankMath entry对象、XML缓存和双插件索引六个P2。没有未修复P0/P1，不改核心表/ID/人工字段，不自动发布。
- PHP两版本各120文件语法、29组2710隔离/Mock断言、前端构建、打包8测试和npm audit通过。构建包含非阻断Browserslist数据更新提示，未擅自升级依赖。
- 最终ZIP直接全新安装12检查、原生升级/重装/切换33断言通过，十四表全记录指纹及78文件匹配；安装包 `release/ai-skills-hub-0.7.1.zip`，SHA256 `470e2ef14fc8c1692baf9fe8b4487483101c61f54bfbd5ab4f549e7b52c8f268`。

按25个验收工作包：20通过、0失败、5未执行。缺少合法WPML包、GitHub/AI应用凭据及真实生成结果/账单，不能宣布全部验收通过。详见根目录 FINAL_QA_REPORT、SECURITY_AUDIT、COMPATIBILITY_REPORT、PERFORMANCE_REPORT、KNOWN_LIMITATIONS 与 INSTALLATION。完整无密证据在 tests/final/results；历史阶段报告中的未测记录不改写。完成代码与文档提交同步后暂停，等待用户安排剩余外部验收。

实现与ZIP提交 `c483a54ae2c492fdb8adb2fe3fcf0ac12d14ce81` 已同步main；自动CI [42005401099](https://github.com/mtccv/Skills-ak/actions/runs/42005401099) PHP8.3/8.4及assets通过，另手动Final QA CI [42005492777](https://github.com/mtccv/Skills-ak/actions/runs/42005492777)四任务全部通过。后者真实WP7.1.4/PHP8.3.36/MySQL8.4.13的124条Core基线与HTTP资源检查通过；完整并发/浏览器/插件仍以Cloud MySQL8.0证据为准。此后补记仅文档/证据，不修改已测主题或ZIP。
