# 开发进度

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
| V2 产品扩展准备 | 规划文档待用户审核；没有开始 Phase 8 | ../PRODUCT_SPEC_V2.md；PRODUCT_DATABASE_DESIGN.md / PRODUCT_MODULE_AUDIT.md / PRODUCT_ROADMAP.md |
| Phase 7.5 全站 UI/UX 设计 | 已按 SkillsMP 视觉参考完成资源目录修订，待用户审核 | DESIGN_SYSTEM.md；design-prototype/README.md；正式代码/数据库未变 |
| Phase 8–14 | 尚未实施 | 需逐阶段明确授权；规划不等于开发完成 |

## Phase 7.5 视觉方向修订（当前交付，2026-11-06 Asia/Shanghai）

以设计提交 `04a8190fd7938749efbb142430c6e5ff23cb38c5` 为修改前基线，实际查看 SkillsMP 首页、搜索页和详情页，只学习视觉结构与信息密度。将五份规范和五页静态原型统一调整为专业 Skills 资源目录：白色/浅灰、克制紫色、64px桌面导航、搜索靠前、紧凑单列资源列表、技术文档式详情与明确来源/License/版本/风险。用户中心采用记录列表，后台保留原生WordPress控件。静态风格ID改为 `skills-directory`，原稳定模块ID与双语/状态/审核流程保持。

本轮重新执行Chromium155的79项前台/状态/交互与24项后台内存预览检查，103通过、0失败；对比页8项检查通过，50张前后原图/并排图及其尺寸、SHA已核对；10组新基础配色对比计算通过，设计独立Tailwind构建、JS语法和设计边界检查通过。前后截图来自同尺寸、同语言、默认状态的真实静态页面；见[五页对比](DESIGN_COMPARISON.md)及[原型证据](design-prototype/README.md)。仅设计文档、隔离HTML/CSS/JS、截图和设计预览包变化；正式主题、数据库、安装包、历史Final QA结论均未改。修订完成后等待用户审核，不开始Phase8。

## Phase 7.5 初稿（历史，视觉方向已由上述修订替代）

初稿基于当时 main `6f7790d58d1884d400d7fd6ad5156449ec134081` 审查现有主题、Core、前后台、语言/SEO、采集/AI与V2规划；形成五份设计合同：DESIGN_SYSTEM、FRONTEND_DESIGN_SPEC、USER_CENTER_DESIGN、ADMIN_DESIGN_SPEC、RESPONSIVE_DESIGN。初稿前台采用Modern SaaS浅色卡片风格（当前已替换为专业资源目录），后台保留WordPress原生运营结构；后续Phase8–14须依据这些规范验收。

新增隔离HTML首页、列表、详情、用户Dashboard、WP后台Dashboard，含中英、设备/状态预览、演示筛选、收藏/评价反馈、原生后台待办与首页配置内存流程。首页独立模块、样式与运营数据分层、双语配置、草稿/发布/CAS、未来排期合同均有设计；生产配置能力归后续授权范围，未实现生产首页重构或页面构建器。初稿当时没有可读取的参考图；当前修订已实际查看用户指定的SkillsMP。

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
