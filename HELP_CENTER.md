# Phase 14 帮助中心与新用户引导

适用：主题/Core **0.14.0**，schema **1.9.0**，**33 张 Core 表保持不变**。这是功能使用说明，不是 Final QA 2.0 通过报告。本阶段执行证据见 [PHASE_14_REPORT.md](PHASE_14_REPORT.md)，未取得的外部环境见 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)。

## 入口与内容来源

中文入口 `/zh/help/`，英文入口 `/en/help/`。帮助中心复用现有 WordPress `aish_tutorial` 教程内容、语言关系、SEO 字段和公开查询，不创建第二套文章系统。帮助文章的详情仍是原有教程 URL；帮助首页提供关键词、分类和分页，阅读页提供当前正文的 H2/H3 目录及相关教程。

| 分类值 | 中文 | English |
| --- | --- | --- |
| `getting-started` | 入门 | Getting started |
| `platforms` | 平台指南 | Platform guides |
| `creating` | 创建 Skills | Creating Skills |
| `community` | 参与社区 | Community |
| `safety` | 来源与安全 | Sources and safety |
| `policies` | 社区规范 | Guidelines |

搜索参数为 `q`、`help_category`、`page`；关键词最多 100 字符。分页保留必要筛选。只显示当前语言实际已发布的教程；缺译、草稿、私有或不可公开内容不会补入结果。没有内容时显示真实空状态。筛选、搜索和分页按既有索引策略处理，不为无价值参数组合另建 Sitemap 项。

## 建立并维护帮助内容

授权管理员进入 **AI Skills Hub → 帮助中心 / Help center**，需要 `aish_manage_skills`。先在页面下方按主题及语言阅读内容库，再明确勾选并点击“导入帮助草稿 / Import help drafts”。首次完整导入最多创建 **21 个主题 × 2 种语言 = 42 篇原生教程草稿**；这不是公开的演示文章，也不是自动发布。

内容库包括以下 12 个学习主题：

1. 什么是 Agent Skills？ / What are Agent Skills?
2. Skills 与 Prompt 的区别 / Skills and prompts.
3. 如何发现合适的 Skill？ / Find a suitable Skill.
4. 如何安装 Skills？ / Install Skills with evidence.
5. Claude Code Skills 使用指南 / Use Skills with Claude Code.
6. Codex Skills 使用指南 / Use Skills with Codex.
7. 如何创建自己的 Skill？ / Create your own Skill.
8. 如何检查来源和风险？ / Check sources and risks.
9. 如何提交 Skill？ / Submit a Skill.
10. 如何收藏和评价？ / Favorite and review Skills.
11. License 与版权说明 / Understand licenses and copyright.
12. 常见问题 / Frequently asked questions.

另外 9 个社区说明主题见 [COMMUNITY_GUIDELINES.md](COMMUNITY_GUIDELINES.md)。原稿位于主题 `inc/core/Learning/help-content.json`，中英文分别维护，不在请求时自动翻译。

导入会保留已有文章、人工修改、语言关系和回收站记录。重复导入只创建缺失的主题/语言草稿，不重新覆盖正文；只有本次同时新建的中英两篇才自动关联。已有文章的一边缺失或译文关联异常时，管理员在原教程编辑器核实并维护，导入不会擅自“修复”。失败时检查数据库与权限，再正常重试，不删除已有文章重来。

在原生教程编辑器中完成以下检查再发布：

1. 核对标题、简介、正文、语言与帮助分类；平台说明与真实上游文档一致。
2. 核对独立 SEO Title、Description，以及实际存在的对应语言文章。缺少翻译时保持缺译，不复制另一种语言冒充。
3. 按原教程系统关联已有 Skill ID。关联资源仍经过当前语言和公开审核门禁；文章中不得暴露私有版本、内部审核备注或预览链接。
4. 社区、隐私和使用说明必须符合站点实际配置与适用要求；不得把内容库当作已经完成法律审核。
5. 正常人工发布后检查帮助分类、搜索、详情阅读导航、相关内容及语言切换。下架后的文章不得继续通过帮助列表公开。

阅读目录只取净化后正文的 H2/H3，最多 24 项；不执行正文中的脚本或短代码。相关教程最多展示 4 项。缺少 PHP DOM 扩展时保留安全正文阅读，目录降级为空；正式安装仍应满足所列扩展要求。

## 可跳过的新用户引导

引导是页面中的普通说明，不强制弹窗、不抢焦点、不要求订阅。可点击“知道了 / Got it”或“跳过 / Skip”；两者只保存已关闭该说明的偏好，不表示业务已完成。

| 说明 | 展示条件 |
| --- | --- |
| 首次访问欢迎 | 首页，当前浏览器/账户未关闭 |
| 注册后的下一步 | 原账户流程签名的注册反馈；保持防邮箱枚举文案，不声称该邮箱一定已新建账户 |
| 邮箱验证完成 | 原账户流程实际验证成功后的签名反馈 |
| 搜索帮助 | 资源列表搜索与筛选区域，未关闭时 |
| 收藏提示 | 原社区服务真正保存收藏并返回签名成功反馈后 |
| 首次评价说明 | 原评价页尚无本人评价主体时 |
| 投稿流程说明 | 现有会员投稿表单 |
| 个人中心介绍 | 账户 Dashboard |

登录用户只在原生 usermeta `_aish_onboarding_steps` 保存版本和最多 8 个固定主题标记；没有时间戳、搜索词、Skill ID、访问序列或新的统计事件。写入用原账户锁与事务合并，重试不会新增记录；接口不接受其他用户 ID。未验证但未暂停的本人账户可以关闭普通帮助，社区写权限仍由原验证规则控制。暂停或无权账户不能通过此接口修改状态。

游客只使用同源浏览器 `localStorage` 的 `aish_help_introductions_v1`，中英共用最少标记，不发送服务器偏好请求。浏览器禁用/清理存储或使用另一设备后可能重新显示；禁用 JavaScript 时提供静态帮助链接，无法记住游客关闭偏好。登录后的偏好由账户独立保存，不导入游客浏览记录。

**账户设置 → 帮助与新用户引导** 可展开重看全部说明，或明确重置本人标记。重看时展示的是流程说明，不是当前账户的验证/收藏成功证明；重置不修改收藏、评价、投稿、通知或邮件订阅。原生隐私导出与删除流程已纳入这些标记。

## 支持与边界

资源安装操作见 [INSTALLATION_GUIDE.md](INSTALLATION_GUIDE.md)；订阅是独立主动选择，见 [EMAIL_SUBSCRIPTION.md](EMAIL_SUBSCRIPTION.md)。帮助中心不会触发 GitHub 扫描、AI 调用或未知脚本执行。外部文档链接只用于人工核对，不是安全认证。真实 WPML、邮件、SEO 插件及外部 API 的实际验证范围必须以本轮报告为准。

English: `/en/help/` serves published English tutorials only. An administrator may import the bilingual starter library as drafts, review it in the existing tutorial editor, and publish each language independently. Optional inline introductions can be skipped or revisited in Account Settings. Their bounded completion preferences never establish verification, community eligibility or newsletter consent.
