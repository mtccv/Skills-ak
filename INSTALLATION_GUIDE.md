# Phase 14 安装与使用指南

本指南适用于 **AI Skills Hub 0.14.0 开发验证包**，分为 WordPress 主题安装与第三方 Skill 安装体验。数据库保持 **schema 1.9.0 / 33 张 Core 自定义表**。安装包可安装不等于已经通过产品级 Final QA 2.0 或正式上线。

## 一、WordPress 主题安装与升级

开发包：[ai-skills-hub-0.14.0.zip](release/ai-skills-hub-0.14.0.zip)。以最终提交中的包、`release/SHA256SUMS` 和 [Phase 14 报告](PHASE_14_REPORT.md) 为同一交付集合；不要将不同构建的包与校验值混用。

1. 在 Codex Cloud 的独立 WordPress 实例准备受支持的 PHP、MySQL/InnoDB 和必要扩展。主题声明最低 WordPress 6.8、PHP 8.3；本阶段具体版本与实际测试覆盖以报告为准。PHP 需 mysqli、JSON、mbstring、DOM/XML、sodium；头像处理需 GD/Imagick，隐私 ZIP 导出需 ZipArchive。
2. 备份完整数据库、媒体、原主题和受保护配置；数据库包括原生 WordPress 表与全部 33 张 Core 表。不要删除数据卷、降级 schema 选项或清表重装。
3. 核对包 SHA-256。管理员在 **外观 → 主题 → 安装主题 → 上传主题** 安装，或在现有同名主题上执行 WordPress 正常替换。源码只读挂载实例用源码更新与构建维护，不从后台强写挂载目录。
4. 启用后进入后台确认 Core schema 仍为 1.9.0、33 表及现有 Skill ID、译文、收藏、评价、投稿、任务历史均保留。从 0.13.0 升级不新增 Core 表；更旧版本只走原有版本化迁移。
5. 检查固定链接、`/zh/`、`/en/`、CSS/JS 请求、原后台菜单和本人账户中心。若重写异常，在原生固定链接设置保存并检查 Web 层；不要通过弱化权限解决路由问题。
6. 帮助原稿由管理员主动导入为草稿，并逐篇审核发布；不会安装即公开 42 篇文章。订阅默认不自动加入任何用户，先明确配置邮件系统与暂停状态，再人工验证所需流程。
7. 根据 [EMAIL_SETUP.md](EMAIL_SETUP.md) 配置专用加密密钥、账户邮件开关、现有 outbox 和可靠 Cron。没有真实传输时保留配置提示与 Mock 边界，不把 `wp_mail()` 成功写成已收到邮件。

完整 Cloud 启动、构建、API 配置、备份和回退步骤见 [INSTALLATION.md](INSTALLATION.md)。主题 ZIP 不包含 WordPress 核心、数据库、业务媒体、API Key、邮件密钥或测试账号。

首次核对建议：使用独立测试账户检查中英登录与验证、收藏、待审评价、投稿、通知、帮助、订阅确认与退订；不把 QA 数据导入正式内容。不自行启动真实 AI 或 GitHub 任务，不自行购买邮件/API 服务。

## 二、安装第三方 Skill 前先核对

详情页的安装体验只呈现当前可公开资源。阅读目标平台、来源仓库、Skill 文件路径、License、版本 SHA、依赖、安装步骤、限制和风险。正文是已审核的介绍，不能由“已发布”推导出“在你的环境绝对安全”。

不同 Skill 的目录结构和安装方式可能不同；网站不生成通用安装命令，也不会替你运行命令、安装依赖或下载执行脚本。没有可信安装证据时显示待核实说明与当前语言帮助入口；可按源码与平台文档人工核查。

复制前应确认：

- URL 与当前语言对应，资源仍公开且版本符合你的需要。
- 命令针对你的平台和环境；平台未知时保持未核实，不根据名称猜测。
- 来源、命令与原始 License 的使用/复制条件已核对。复制按钮不是法律授权。
- 命令没有索取秘密、破坏数据、提升不必要权限或执行未知下载脚本。不能确认时先停止并向维护者核实。

## 三、管理员如何启用有证据的命令复制

原生后台入口 **AI Skills Hub → 安装命令核实 / Installation evidence**，需要 `aish_review_skills`。这一入口保存复制辅助证据，不改变 Skill 发布审核。

1. 输入稳定 Skill ID 和具体语言 `zh_CN` / `en_US`。资源须当前已发布、当前修订审核通过，并有完整对应译文。
2. 命令必须是当前已审核译文正文中**完整、可见的 `<pre>` 代码块（可包含 `<code>`，也支持原生预格式文本块）**。不从不完整行、隐藏内容、任意 HTML、猜测的 Markdown 或统一模板拼接命令。
3. GitHub 来源需选择当前导入关联的不可变 `skill_versions` 快照。仓库、相对路径、文件 SHA、Commit SHA 及内容指纹必须匹配；命令原文必须出现在来源快照。不能拿一个无关版本为现有命令背书。
4. 人工来源只可注明来自当前已审核编辑正文，不标为官方。平台只能选 Skill 实际已有平台标签，未确认时维持未核实提示。
5. License 需已明确；填写具体权利核查依据及内部备注，明确确认许可/用途与本次操作。内部备注不会进入前台、复制文本或分享地址。不要填写密码、Token、API Key 或敏感个人资料；这是管理记录，不是秘密保管库。
6. 保存后在实际中英详情页检查来源、版本和平台说明。后台采用修订比较；冲突时重新读取并人工核查，不能盲重试覆盖别人证据。

正文、审批、License、平台、来源身份或导入快照变化后，旧复制证据失效，需重新核实。证据按 Skill/语言保存在有界非 autoload options，使用现有工作流审计；不新增 Core 表、不修改 Skill ID 或版本快照。原生 options 增删改不能代替核实工作流；拥有 `manage_options` 的站点管理员仍可通过 WordPress 管理能力读取记录，不应在其中存放凭据。

历史来源快照若缺少当前绑定信息，不能据此启用复制；需要有当前来源快照并通过原流程人工审核。只有可核查的复制辅助信息不代表已经运行测试，也不将命令升级为“官方安全命令”。

## 四、复制、分享与反馈

复制按钮仅在用户主动操作后调用浏览器 Clipboard 写入文字。成功、失败均有可见反馈；剪贴板 API 不可用或被拒绝时，可选择可见代码并手动复制。不会把复制行为交给 Shell，也不会自动打开终端或执行未知 Skill。

分享只用当前语言的公开详情 URL，不带预览 Token、内部备注或账户参数。支持复制详情 URL、复制可用 GitHub 来源、浏览器原生分享（可用时），以及固定 X、LinkedIn、Reddit 链接；不加载第三方分享 SDK。GitHub 链接若指向当前分支应理解为可能随上游变化，核查内容仍应参考所示不可变版本。

Open Graph 继续由原 SEO 系统生成；分享不另输出第二套 Canonical/OG，也不为缺译资源捏造语言 URL。社交平台的抓取缓存、预览裁剪与刷新有自己的策略，不能把本地标签检查当作真实平台预览已验证。

安装失败时使用详情页帮助入口、已发布相关教程或 Skill 纠错；说明平台、版本、来源及脱敏错误，不发送 Token、Cookie、密码、完整私人项目或让管理员执行未知脚本。

## 五、限制与验证

代码块复制不验证命令执行结果、网络目标可用性或所有许可证条件；人工来源核查不替代自身环境判断。浏览器 Clipboard/native Share 支持与安全上下文有关，手动复制和普通链接保持可用。未配置的真实 SMTP、WPML、AI/GitHub API、SEO 插件、CDN 和实体设备检查在报告中单独列出，不能由隔离测试或视口模拟替代。

English: install the verified 0.14.0 ZIP through native WordPress, preserve the complete database and existing IDs, and verify schema 1.9.0 / 33 Core tables. Skill installation is a separate operation: copy controls require current reviewed content and source/license evidence. They copy visible text only, never execute commands. Missing evidence remains explicitly unverified; review platform guidance and original sources before acting.
