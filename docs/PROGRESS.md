# 开发进度

| 阶段 | 状态 | 记录 |
| --- | --- | --- |
| Phase 0 项目初始化 | 用户已验收 | PHASE_0_REPORT.md |
| Phase 1 数据库与 Core | 用户已验收 | PHASE_1_REPORT.md；历史 CI 证据保留 |
| Phase 2 WordPress 原生后台 | 用户已验收 | PHASE_2_REPORT.md |
| Phase 3 中英文双语前台 | 用户已验收 | PHASE_3_REPORT.md / FRONTEND.md |
| Phase 4 搜索与 SEO | 用户已验收 | PHASE_4_REPORT.md / SEO.md |
| Phase 5 GitHub Skills 自动采集 | 实现与必要检查完成，正在提交同步 | PHASE_5_REPORT.md / GITHUB_IMPORT.md / IMPORT_SECURITY.md |
| Phase 6 AI 双语内容 | 未启动，等待用户安排 | 不自动进入 AI 翻译或生成 |
| Final QA | 待执行 | 全面 MySQL、WordPress、WPML、浏览器、并发和端到端验收 |

Phase 2 范围为菜单/统计、Skills 人工及来源录入、双语编辑、状态/归档、分类/标签、手动仓库、人工审核/风险/备注、私有阅读预览、列表/批量和 capability/nonce 安全控制。

数据沿用原八表及稳定 Skill ID，Phase 2 通过 1.2.0 增量迁移添加后台必要字段。Phase 3 实现公开前台与内置语言路由，使用既有标签和 WordPress 原生存储补充教程与私有投稿；不修改业务 schema。Phase 4 实现搜索、SEO 及 WPML 公开 API 接口，真实插件联调仍待 Final QA。Phase 5 增加 GitHub 自动采集；AI 自动生成尚未实施。

当前策略：每阶段只执行必要 PHP 语法、前端构建、基本代码检查。完整测试在 Final QA 统一执行；历史通过不自动覆盖新代码。仓库 CI 默认只运行 PHP/资源检查，完整数据库/WordPress 基线须手动选择 `final_qa=true`。

Phase 2 代码提交 `e25da3241ab7830f0d34f8157063863b456d578a`；[CI 基本检查](https://github.com/mtccv/Skills-ak/actions/runs/41904484033) PHP 8.3/8.4 与 assets 通过，数据库/WordPress 任务按策略跳过。

Phase 3 当前范围：原创响应式 SaaS 主题，12 类页面，中英文词典与路由、实际翻译跳转及缺译回退，真实公开 Skills 搜索/筛选/排序/分页，教程与私有来信，基础 Meta/Canonical/hreflang/面包屑。高阶 SEO 与 Sitemap 留 Phase 4，完整运行验收留 Final QA。用户已明确同意表单保存为后台私有待处理记录。

Phase 3 代码提交 `6cdc701c5553129307656c458ec89a5d27a0c19f`；[CI 基本检查](https://github.com/mtccv/Skills-ak/actions/runs/41914648942) 的 PHP 8.3/8.4、隔离路由断言及 assets 通过。完整 WordPress/MySQL 任务按策略跳过，没有进入 Phase 4。

Phase 4 在已验收 Phase 3 的 `729b0a92a7659d7bda5bbafe10a76a5f49a512b0` 上实现中英文 Skills/教程搜索、组合筛选、批量查询、双语 SEO 配置和 Meta、hreflang/x-default、分页 XML Sitemap、插件输出协调与 robots 策略。主题/Core 0.5.0；schema 1.3.0 仅增加两个非唯一索引，保留原八表和全部数据。没有提前实现自动采集或 AI。

Cloud PHP 8.3.36 全部 51 个文件语法通过，342 项隔离基础断言通过，Tailwind/esbuild、资源及脚本检查通过。真实 MySQL、WordPress、WPML、SEO 插件、浏览器、并发和端到端均仍待 Final QA，隔离检查不作为集成验收。实现提交 `3df1e0573f84a8d7c563094ebf16af383cc2a636`，已同步 GitHub main；[CI 41917878687](https://github.com/mtccv/Skills-ak/actions/runs/41917878687) 的 PHP 8.3/8.4 与 assets 均通过，WordPress/MySQL 任务按策略 skipped。报告及本进度随后进行纯文档补记，不改变已检查代码。Phase 4 完成后暂停。

Phase 5 以已验收 Phase 4 的 `3766dc1b410e50dd9873b1ef52a1a818d4a3f8fb` 为基线，实现受限 GitHub API、固定 SHA 树发现、truncated 分层回退、安全 Frontmatter、静态风险检查、来源去重与增量同步、原生采集后台和可恢复 Cron 小任务。主题/Core 0.6.0、schema 1.4.0；原八表保留，仅追加仓库/版本必要字段及 imports/jobs/import_logs 三张运营表。新资源固定 pending，不生成译文；旧来源只追加快照和待处理通知，不覆盖人工内容或自动发布。

新增模拟检查覆盖 API/发现/解析/存储/后台/worker，并保留原有路由、搜索与 SEO 基础回归。已执行的准确数量与 Git/CI 证据见 [Phase 5 报告](PHASE_5_REPORT.md)。真实 GitHub API、MySQL、WordPress Cron/后台、WPML、浏览器、并发与端到端均未执行，留 Final QA；没有部署外部服务。完成同步后暂停，等待 Phase 6 指令。
