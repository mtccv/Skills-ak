# Phase 14 — 用户体验、学习体系与增长功能完善

用户于2026-11-08（Asia/Shanghai）授权；基准 `1282c4b756b3a5b020aa18e62a9295cee213d549`，schema1.9.0、33张Core表、开发包0.13.0。以最新main继续，不重建或破坏Phase0–13。本文件整理本轮用户要求，实际交付见 PHASE_14_REPORT.md。

## 必须交付

- Phase7.5白灰/深灰/陶土棕#AD5E48目录风格，中英独立内容，375/768/1440响应式；原生WordPress后台，原型不变。
- 非强制可跳过的首次访问、注册欢迎、邮箱验证、搜索、收藏、评价、投稿、用户中心引导；必要完成标志、设置中重看，不增加行为画像。
- 帮助中心复用原生教程：Agent Skills、与Prompt区别、发现、安装、Claude Code、Codex、创建、来源风险、投稿、评价收藏、License、FAQ十二主题；分类/搜索/分页、真实译文、SEO、阅读导航、公开关联资源。
- 详情URL/GitHub复制、固定社交分享与正确OG；安装步骤、明确来源的命令复制与反馈、平台/依赖/版本/来源及帮助。未知命令待核实，不编造、执行或下载脚本，不绕过License。
- 明确同意的邮件订阅、确认、中英偏好、每周精选/新资源、取消与一键退订、失败/退信/重复保护。复用现有outbox和公开编辑内容；不自动纳入注册用户、Contact或企业咨询。可关闭暂停，区分入队/传输受理/送达，Mock不代表真实邮件。
- 中英Community/Submission/Review/Author Claim/License/Safety/Privacy/Terms/Contact & Help九规范主题，导航及实际行为一致，不编造法律或绝对安全承诺。
- 检查已有首页/搜索/筛选/详情/收藏评价/投稿/账户/开发者/通知/帮助的移动交互、键盘、焦点、空/错/加载状态、长内容；不改变业务权限或SEO。
- 必要性能改进，复用既有系统，默认schema1.9.0/33表不变；仅真实结构缺口才迁移。保存历史数据与Skill ID。

## 实施约束

依据数据库规格§4.5，首版订阅面向**已验证原生注册用户主动双重确认**；访客先登录注册，不新建匿名联系人存储。本人受保护usermeta保存同意与偏好，复用email_verifications及notification_outbox。早期规划的1.10.0/第34表是候选，本轮不实施。公开帮助内容由管理员在原教程后台审核发布，完整独立中英原稿可显式导入草稿，不能升级时自动发布或覆盖人工内容。

## 验证与交付

PHP8.3/8.4语法、隔离/Mock、构建、真实WordPress/MySQL、浏览器、中英375/768/1440、订阅/退订/权限/命令安全/旧功能回归；真实邮件、WPML、第三方API、SEO插件、CDN等按实际执行情况报告。重点验证无强制订阅、退订后阻止后续营销、失败幂等、私有页不索引、关联资源公开门禁、复制不执行、移动无明显溢出。

更新PROGRESS、CHANGELOG、HELP_CENTER、COMMUNITY_GUIDELINES、EMAIL_SUBSCRIPTION、INSTALLATION_GUIDE、PHASE_14_REPORT、KNOWN_LIMITATIONS；提供数据库变化、包版本、文件清单、测试分类和完整产品Final QA2.0计划。完成检查后正常提交并同步GitHub main，给出SHA与开发包。**Phase14完成后暂停，不执行Final QA2.0、不宣布上线。**
