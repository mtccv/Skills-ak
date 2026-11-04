# 开发进度

| 阶段 | 状态 | 记录 |
| --- | --- | --- |
| Phase 0 项目初始化 | 用户已验收 | PHASE_0_REPORT.md |
| Phase 1 数据库与 Core | 用户已验收 | PHASE_1_REPORT.md；历史 CI 证据保留 |
| Phase 2 WordPress 原生后台 | 用户已验收 | PHASE_2_REPORT.md |
| Phase 3 中英文双语前台 | 代码和必要检查完成，准备同步 GitHub，等待验收 | PHASE_3_REPORT.md / FRONTEND.md |
| Phase 4 高级 SEO 等后续工作 | 未启动，等待用户安排 | 不自动进入 |
| Final QA | 待执行 | 全面 MySQL、WordPress、WPML、浏览器、并发和端到端验收 |

Phase 2 范围为菜单/统计、Skills 人工及来源录入、双语编辑、状态/归档、分类/标签、手动仓库、人工审核/风险/备注、私有阅读预览、列表/批量和 capability/nonce 安全控制。

数据沿用原八表及稳定 Skill ID，通过 1.2.0 增量迁移添加本轮必要字段。Phase 3 实现公开前台与内置语言路由，使用既有标签和 WordPress 原生存储补充教程与私有投稿；不修改业务 schema。GitHub 自动采集、AI 自动生成、真实 WPML 适配尚未实施。

当前策略：每阶段只执行必要 PHP 语法、前端构建、基本代码检查。完整测试在 Final QA 统一执行；历史通过不自动覆盖新代码。仓库 CI 默认只运行 PHP/资源检查，完整数据库/WordPress 基线须手动选择 `final_qa=true`。

Phase 2 代码提交 `e25da3241ab7830f0d34f8157063863b456d578a`；[CI 基本检查](https://github.com/mtccv/Skills-ak/actions/runs/41904484033) PHP 8.3/8.4 与 assets 通过，数据库/WordPress 任务按策略跳过。

Phase 3 当前范围：原创响应式 SaaS 主题，12 类页面，中英文词典与路由、实际翻译跳转及缺译回退，真实公开 Skills 搜索/筛选/排序/分页，教程与私有来信，基础 Meta/Canonical/hreflang/面包屑。高阶 SEO 与 Sitemap 留 Phase 4，完整运行验收留 Final QA。用户已明确同意表单保存为后台私有待处理记录。
