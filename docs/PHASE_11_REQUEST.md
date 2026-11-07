# Phase 11 用户授权需求

接收日期：2026-11-07（Asia/Shanghai）。本文件保存本轮用户明确授权的范围，不代表功能已经实现或测试通过。当前实际 schema 为 1.8.0，共26表；本轮须保留全部26表，按规划只追加7表至33表，复用已有统计与审计表。

Phase 10 已完成，现在正式开始 Phase 11：AI Skills Hub 资源发现与推荐系统开发。

GitHub 仓库：
https://github.com/mtccv/Skills-ak

当前基准 Commit：
c7fa503432ceb4fc971c3b7d44b44591e3c0a7af

请完整阅读：

- CODEX_MASTER_SPEC.md
- PRODUCT_SPEC_V2.md
- PRODUCT_DATABASE_DESIGN.md
- PRODUCT_MODULE_AUDIT.md
- PRODUCT_ROADMAP.md
- DESIGN_SYSTEM.md
- FRONTEND_DESIGN_SPEC.md
- Phase 8 账户系统文档
- Phase 9 社区评价文档
- Phase 10 投稿与开发者中心文档
- 当前 Schema、Migrator、PublicCatalog 和搜索系统代码

严格遵循已经确认的视觉规范：

- SkillsMP-inspired 专业 Skills 资源目录风格。
- 白色、浅灰色背景。
- 深灰色标题和正文。
- 陶土棕 #AD5E48 少量强调。
- 紧凑、清晰、专业。
- 减少重复大圆角卡片和装饰性元素。
- 支持中英文。
- 适配 Desktop、Tablet、Mobile。

# 一、Trending Skills 趋势榜

实现真实数据驱动的 Trending Skills。

要求：

1. 统计有效浏览。
2. 统计有效收藏。
3. 使用已审核的有效评分作为质量信号。
4. 使用最近时间窗口计算趋势。
5. 排除机器人和明显异常流量。
6. 防止刷新页面刷浏览量。
7. 防止重复收藏影响趋势。
8. 提供明确统计时间范围。
9. 新资源获得合理曝光机会。
10. 数据不足时显示合理的空状态。

不得直接使用 GitHub Stars 冒充用户热度。

不得使用虚构数据。

不得把后台审核、采集任务或普通更新时间作为趋势事件。

# 二、Most Viewed

实现有效 Skill 浏览量统计。

要求：

- 定义有效浏览事件。
- 区分真实访问和明显机器人流量。
- 设置合理的去重窗口。
- 不保存不必要的用户个人浏览轨迹。
- 支持按时间窗口统计。
- 支持日聚合。
- 排除管理员和测试数据。
- 资源不再公开时立即从公开榜单移除。

浏览量统计必须有明确口径。

不能把 HTTP 请求总数直接当作有效浏览量。

# 三、Most Favorited

使用 Phase 9 已完成的收藏数据。

要求：

- 按真实有效收藏数量排序。
- 同一用户同一 Skill 只计算一次。
- 取消收藏后及时更新计数。
- 用户删除账户时正确处理关联收藏。
- 中英文共享同一个 Skill ID。
- 只展示当前允许公开的 Skills。

不要重复开发收藏功能。

# 四、Top Rated

使用 Phase 9 已完成的评价数据。

要求：

- 只统计 Approved 状态的有效评价。
- 未审核、拒绝、隐藏和已撤回评价不参与评分。
- 使用真实平均评分。
- 显示真实评价数量。
- 支持评分分布。
- 设置合理的最低评价数量规则。
- 样本不足时显示实际评分及样本数量，不制造虚假排名。
- 评价被隐藏或撤回时及时重新计算。
- 不允许管理员虚构评价。

推荐排序算法必须有明确文档，避免仅按平均分导致一条五星评价的资源长期排在大量真实评价的资源前面。

# 五、Recently Added

实现最近收录资源列表。

要求：

- 使用真实 Skill 创建记录。
- 与首次公开发布时间区分。
- 明确显示收录日期的业务含义。
- 只展示当前允许公开的 Skills。
- 中英文页面保持同一 Skill 身份。
- 历史时间未知时不得伪造日期。

# 六、Recently Updated

实现最近内容更新资源列表。

特别注意：

不能直接把 skills.updated_at 当作真正的内容更新时间。

需要区分：

- 人工编辑并批准的内容更新。
- 原始来源版本变化。
- 后台审核操作。
- 采集同步操作。
- AI 草稿生成。
- 用户收藏和评价。

只有符合定义并经过批准的公开内容变化，才能进入 Recently Updated。

不得把仅有 GitHub 文件变化但尚未审核的内容作为正式更新展示。

不得覆盖人工编辑的中英文内容。

# 七、Editor's Picks 编辑精选

实现完整的编辑精选系统。

功能：

1. 创建精选集合。
2. 编辑集合。
3. 删除或归档集合。
4. 中英文标题。
5. 中英文描述。
6. 添加 Skills。
7. 调整展示顺序。
8. 设置发布时间。
9. 设置结束时间。
10. 启用和停用。
11. 首页展示。
12. 集合详情页。

后台继续使用 WordPress 原生管理界面。

集合中的 Skill 必须通过现有 PublicCatalog 公开状态检查。

不得通过精选设置绕过审核。

# 八、Use Case 场景化发现

这是本阶段的重要功能。

建立结构化的使用场景系统。

首批场景建议：

- Frontend Development
- Backend Development
- Full Stack Development
- UI/UX Design
- Browser Automation
- Data Analysis
- Research
- Documentation
- Content Writing
- Productivity
- Spreadsheet Automation
- WordPress Development
- AI Workflow Automation
- DevOps

支持：

- 场景新增。
- 场景编辑。
- 场景归档。
- 中英文标题。
- 中英文介绍。
- 独立 URL。
- SEO Title。
- Meta Description。
- Skill 关联。
- 相关教程。
- 场景排序。
- 首页推荐。

场景与 Skill 必须通过稳定 Skill ID 关联。

AI 可以提出场景建议，但不能自动建立已审核的正式场景关系。

# 九、分类与标签双语完善

现有 categories 和 tags 必须保留原始 ID。

检查 PRODUCT_DATABASE_DESIGN.md 中规划的 term_translations。

实现：

- 分类中文名称。
- 分类英文名称。
- 分类双语描述。
- 标签双语名称。
- 标签双语描述。
- 缺失翻译处理。
- 分类层级。
- 相关资源数量。

不能通过复制分类和标签来制造两套互不关联的资源分类体系。

缺少翻译时不得假装已有真实翻译。

# 十、Platform Collections

完善平台资源发现：

- Claude Code
- Codex
- Cursor
- 其他经过来源核实的平台

支持平台介绍、资源数量、相关 Skills 和平台专题页面。

平台兼容性必须有证据支持。

不能仅因为 Skill 被某个用户提交，就自动认定它支持该平台。

# 十一、Related Skills 和 Similar Skills

实现相关资源推荐。

第一版优先使用：

1. 相同分类。
2. 相同标签。
3. 相同已审核 Use Case。
4. 相同平台。
5. 编辑人工关联。

推荐结果必须：

- 只显示已公开 Skills。
- 优先显示当前语言内容。
- 不重复显示当前 Skill。
- 不显示已归档和未审核资源。
- 排序结果稳定。
- 避免重复数据库查询。

不必首期引入复杂机器学习推荐系统。

# 十二、首页模块化运营

在现有 Phase 7.5 首页设计基础上实现：

- Trending Skills。
- Explore by Category。
- Explore by Use Case。
- Editor's Picks。
- Recently Added。
- Recently Updated。
- Most Favorited。
- Top Rated。
- Popular Platforms。
- Learning Guides。

要求：

- 首页模块独立。
- 支持显示和隐藏。
- 支持排序。
- 支持内容配置。
- 中文和英文分别配置内容。
- 没有真实数据时合理隐藏或显示空状态。
- 不使用演示数据冒充真实数据。
- 不重复开发 Skills 查询和 SEO 逻辑。

# 十三、数据库迁移

按照 PRODUCT_DATABASE_DESIGN.md 和 PRODUCT_ROADMAP.md 执行 Phase 11 的版本迁移。

目标 schema：

1.8.0 → 1.9.0。

规划重点：

- use_cases
- use_case_translations
- use_case_skills
- editorial_collections
- collection_translations
- collection_items
- term_translations
- metric_events
- daily_metrics

以当前实际 Schema 和设计文档为准。

如果 Phase 9 已经创建 workflow_events 或 metric_events，不得重复创建。

不得删除、重建或改变原有 25 张表。

保留所有 Skill ID、收藏、评价和投稿历史。

# 十四、安全和数据要求

- 所有统计数据必须真实。
- 不收集不必要的个人浏览历史。
- 防止刷浏览和刷榜。
- 不公开私有用户数据。
- 不让榜单绕过 PublicCatalog。
- 不让未审核评价进入排名。
- 不让未审核 GitHub 更新进入 Recently Updated。
- 所有后台管理操作使用 Capability 和 Nonce。
- 支持安全缓存和即时失效。
- 用户删除或匿名化后正确处理统计数据。
- 不将演示数据写入正式数据库。

# 十五、测试要求

执行必要的 PHP 语法、构建、隔离及 Mock 测试。

重点覆盖：

- 浏览量去重。
- 机器人排除。
- Trending 时间窗口。
- 收藏计数。
- 评分计算。
- 最低评价样本规则。
- Recently Added 时间定义。
- Recently Updated 事件定义。
- 精选集合发布。
- 集合排序。
- 场景双语。
- 场景与 Skill 关联。
- 分类翻译。
- 缺失翻译。
- 平台过滤。
- Related Skills。
- 缓存失效。
- 下架资源退出榜单。
- 中英文页面关联。
- 并发统计和重复事件。

真实 WordPress、MySQL、浏览器、WPML 和外部服务测试按实际执行情况报告。

不得将 Mock 测试冒充真实环境测试。

# 十六、提交要求

完成后：

1. 更新 PROGRESS.md。
2. 更新 CHANGELOG.md。
3. 更新 DISCOVERY_SYSTEM.md。
4. 更新 USE_CASES.md。
5. 更新 DATABASE_DESIGN 文档。
6. 更新后台使用说明。
7. 执行必要检查。
8. 提交 Git。
9. 同步 GitHub main。
10. 汇报 Commit SHA。
11. 暂停等待 Phase 12 指令。

不要提前开发 Phase 12、13、14。

现在正式开始 Phase 11。