# 开发进度

| 阶段 | 状态 | 记录 |
| --- | --- | --- |
| Phase 0 项目初始化 | 用户已验收 | PHASE_0_REPORT.md |
| Phase 1 数据库与 Core | 用户已验收 | PHASE_1_REPORT.md；历史 CI 证据保留 |
| Phase 2 WordPress 原生后台 | 代码与必要检查完成，等待用户验收 | PHASE_2_REPORT.md |
| Phase 3 及后续 | 未启动，等待用户安排 | 不自动进入 |
| Final QA | 待执行 | 全面 MySQL、WordPress、WPML、浏览器、并发和端到端验收 |

Phase 2 范围为菜单/统计、Skills 人工及来源录入、双语编辑、状态/归档、分类/标签、手动仓库、人工审核/风险/备注、私有阅读预览、列表/批量和 capability/nonce 安全控制。

数据沿用原八表及稳定 Skill ID，通过 1.2.0 增量迁移添加本轮必要字段。公开前台、语言路由、GitHub 自动采集、AI 自动生成、真实 WPML 适配尚未实施。

当前策略：每阶段只执行必要 PHP 语法、前端构建、基本代码检查。完整测试在 Final QA 统一执行；历史通过不自动覆盖新代码。仓库 CI 默认只运行 PHP/资源检查，完整数据库/WordPress 基线须手动选择 `final_qa=true`。
