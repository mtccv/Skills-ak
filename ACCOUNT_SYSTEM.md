# Phase 8 — 用户账户与个人中心

> 当前Phase14：账户仍使用原生Users，schema1.9.0/33表。可跳过引导与帮助入口已扩展；邮件精选为单独主动双重确认，注册和验证不会自动订阅。历史Phase8/9的版本与未开放描述不代表当前能力。见[帮助中心](HELP_CENTER.md)、[订阅说明](EMAIL_SUBSCRIPTION.md)。

> Phase 9 已扩展账户中心：“我的收藏”“我的评价”、详情互动及社区处理通知已接入原生账户；当前总 schema 为 1.7.0 / 25 表，账户三表不变。下文 1.6.0 / 17 表及未开放社区说明描述 Phase 8 交付当时。无需重新注册或重建账户，社区操作须完成当前邮箱验证。详见 [社区说明](COMMUNITY_REVIEWS.md)。

本阶段使用 WordPress 原生 Users、Subscriber、密码散列、认证 Cookie、会话和密码重置凭证。没有独立密码库，也没有复制 Skills。设计采用已确认的白/浅灰背景、深灰文字和陶土棕 `#AD5E48`；Phase 7.5 的静态原型保留为设计证据。

## 页面和范围

中文 `/zh/`、英文 `/en/` 下提供 `register/`、`login/`、`verify-email/`、`forgot-password/`、`reset-password/`、`account/` 及账户下的 `profile/`、`settings/`、`security/`、`privacy/`、`notifications/`。账户跨语言共用同一个原生用户 ID。

个人中心提供真实账户状态、资料、头像、通知和邮件状态；未开放收藏、评分、新投稿、排行榜或营销订阅，不填演示数量。旧 Contact/Submit 私有收件箱保持原有流程，不按注册邮箱认领历史记录。

注册默认 Subscriber，不接受请求指定角色。新用户验证前只有受限验证、恢复及退出流程；验证绑定当前邮箱，不能仅凭一个 `active` 字段取得社区权限。已有管理员的原生登录和后台能力保留，缺验证证据不冒称邮箱已验证。账号安全操作要求当前密码；新邮箱确认前原邮箱保持不变。

## 模块和数据库

`AccountsModule` 复用 Core 的 Database/Migrator、WordPress 原生身份和现有前台基础。业务逻辑位于 `inc/core/Accounts/`，模板位于 `templates/account/`，不放入 `functions.php`。

schema **1.6.0** 在现有 14 张业务表之外只增加：

| 表（动态站点前缀 + aish_） | 用途 |
| --- | --- |
| email_verifications | 绑定用户/用途/目标邮箱的一次性验证，哈希、有效期、消费状态和活动唯一键 |
| user_notifications | 本人账户通知、语言、读取状态、过期时间和事件去重 |
| notification_outbox | 加密邮件载荷、幂等键、租约、有限重试和真实传输状态 |

共 17 张 Core 表；原 14 表、Skill ID、双语翻译、来源键及历史数据保持。资料和偏好复用受限 usermeta，头像复用受控私有附件，隐私申请复用 `user_request`。迁移只在授权管理/CLI 环境执行，不在普通前台请求建表；旧版本失败时保留最后成功版本。升级前备份数据库和文件；不能通过降低版本号或清空表“回滚”。

## 安装与开放

1. 备份现有数据库和上传文件，安装本仓库构建的主题；PHP 8.3/8.4、MySQL/InnoDB、sodium 和 WordPress 图片处理扩展为相应功能基础。
2. 授权管理员访问后台，完成 1.5.0 → 1.6.0 增量迁移；检查通用迁移提示，失败时先修正原因再重试。已有管理员登录不依赖邮件配置。
3. 按 [EMAIL_SETUP.md](EMAIL_SETUP.md) 配置独立加密密钥和邮件传输，再明确启用账户邮件。缺少密钥或启用声明时不接受无法恢复的新注册。
4. 配置可靠 WP-Cron 触发，运行固定账户邮件任务；在测试邮箱完成真实收信与链接验证后再对外开放。
5. 在中英文分别验证注册、登录、恢复、设置和退出；测试结果以本阶段报告为准，不继承历史 Final QA 的通过结论。

主题切换清除账户 Cron 调度而保留身份、通知和队列；重新启用后由管理员初始化调度，过期租约按实际阶段恢复。已开始但结果不确定的发送不自动重复；用户可以受限重发，旧验证凭证立即失效。

## 隐私和扩展边界

见 [PRIVACY_AND_SECURITY.md](PRIVACY_AND_SECURITY.md)。账户页面 noindex/nofollow/noarchive、private/no-store，不加入 Sitemap；验证/重置链接进入后清除 URL 中的凭证，GET 不消费验证。资料默认私有，公开作者档案留到后续授权阶段。

第三方插件直接 SQL 写用户绕过原生 hooks、主题停用期间的账户策略、多站点全网用户清理及真实 WPML 兼容须分别验收。生产传输、真实邮件送达不由 Mock 测试证明。
