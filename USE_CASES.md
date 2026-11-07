# 使用场景与编辑集合

Phase 11，主题/Core 0.11.0、schema 1.9.0。场景是人工维护的稳定实体，中英文共用同一 ID，并通过 use_case_skills 关联原 Skill ID；不是复制分类或生成第二份 Skill。

## 维护场景

管理员进入 **AI Skills Hub → 使用场景 → 新增**：

1. 选择草稿，填写中文或英文的名称、简介、slug 和可选 SEO Title/Description。
2. 填入实际 Skill ID、相关教程文章 ID，设置顺序；没有翻译的语言保持空白。
3. 检查内容后选择 published 并确认保存；需要停用则归档，原记录保留。
4. 页面使用 `/zh/use-cases/{id}/{slug}/` 或 `/en/use-cases/{id}/{slug}/`。已选 slug 保持稳定，归档再启用不改地址。

发布场景不批准 Skill。成员中的草稿、未审核、拒绝、归档或当前语言不完整者，在计数、排序和分页之前过滤。相关教程也须真实发布且有对应语言，不从另一语言制造内容。缺少整页翻译时该语言地址不可用，不输出对应 hreflang 或 Sitemap 项；空场景显示真实空状态且不索引。

编辑时必须使用页面当前修订；并发冲突需刷新再确认，不能覆盖另一位管理员已保存的内容。删除采用归档，用户数据、原 Skill、版本和双语关联不删除。筛选只接受有界关键词/分类/标签/平台，不能向查询传入 SQL。

## 场景建议与真实内容

初始可由编辑核实后建立 Frontend Development、Backend Development、Full Stack Development、UI/UX Design、Browser Automation、Data Analysis、Research、Documentation、Content Writing、Productivity、Spreadsheet Automation、WordPress Development、AI Workflow Automation、DevOps。

这些是待编辑确认的建议，**迁移不自动创建场景或填入 Skills**。系统不调用 AI 建立已审核的正式关联，不根据名称推测支持平台。

## 精选、平台、分类和首页

精选集合在独立“编辑精选”入口维护，支持成员显示顺序、发布时间和结束时间。场景按 sort_order 排序，成员使用稳定 ID 顺序；关联教程从已审核语言投影读取。集合/场景均有独立双语 SEO 和公开门禁。

平台专题来自真实 platform-* 标签及管理员核实的证据；只能组织已有关系，不能推测兼容性。分类和标签保留原 ID、slug、层级；“分类与标签翻译”补充真实名称和介绍，缺译显示共享原名并明确回退状态。

首页“发现与首页配置”可按语言设置场景模块显隐、位置、数量、标题及说明。保存草稿不会上线，发布配置后才生效。视觉沿用白/浅灰、深灰、陶土棕 #AD5E48，无原型改写。

数据规则、隐私和排行见 [DISCOVERY_SYSTEM](DISCOVERY_SYSTEM.md)；真实/隔离测试及限制见 [Phase 11 报告](docs/PHASE_11_REPORT.md)。Phase 12 尚未开始。
