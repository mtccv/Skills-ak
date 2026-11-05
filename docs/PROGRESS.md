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
| Phase 7 安全、性能与稳定性 | 两轮代码审查与修复完成；等待用户安排 Final QA | PHASE_7_REPORT.md / PHASE_7_ROUND_2.md / ../CHANGELOG.md |
| Final QA | 未开始，等待用户指令 | 全面 MySQL、WordPress、WPML、浏览器、并发和端到端验收 |

Phase 2 范围为菜单/统计、Skills 人工及来源录入、双语编辑、状态/归档、分类/标签、手动仓库、人工审核/风险/备注、私有阅读预览、列表/批量和 capability/nonce 安全控制。

数据沿用原八表及稳定 Skill ID，Phase 2 通过 1.2.0 增量迁移添加后台必要字段。Phase 3 实现公开前台与内置语言路由，使用既有标签和 WordPress 原生存储补充教程与私有投稿；不修改业务 schema。Phase 4 实现搜索、SEO 及 WPML 公开 API 接口，真实插件联调仍待 Final QA。Phase 5 增加 GitHub 自动采集；Phase 6 增加独立 AI 草稿、任务和人工处理，不自动发布。

当前策略：每阶段只执行必要 PHP 语法、前端构建、基本代码检查。完整测试在 Final QA 统一执行；历史通过不自动覆盖新代码。仓库 CI 默认只运行 PHP/资源检查，完整数据库/WordPress 基线须手动选择 `final_qa=true`。

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

Cloud PHP 8.3.36 的 100 个 PHP 文件语法、29 组 2679 项隔离/Mock 断言、前端构建/资源、JS/Bash、Compose 及差异检查通过；npm audit 本次为 0 个已知漏洞。九个新增测试入口共 818 项，全部断言比首轮增加 940 项。Git/CI 证据在本轮提交后补记；所有真实 WordPress、MySQL、WPML、GitHub/AI API、浏览器、并发与端到端仍未执行。Phase 7 代码工作完成后暂停，等待用户安排 Final QA，不自动运行或部署。
