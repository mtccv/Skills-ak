# AI Skills Hub V2 数据库设计规划

日期：2026-11-06（Asia/Shanghai）。核查基线：`main` / `1c27f8770fc3bd20b2ffd92d8873444ba92d54c8`。本文是 Phase 8–14 的设计，不是已执行迁移；当前主题/Core 为 **0.7.1**、schema 为 **1.5.0**。本轮只读源码和历史报告，没有连接运行数据库、执行 PHP/WordPress、启动服务或改变数据库。

对应产品范围见 [PRODUCT_SPEC_V2.md](../PRODUCT_SPEC_V2.md)，模块差距见 [PRODUCT_MODULE_AUDIT.md](PRODUCT_MODULE_AUDIT.md)，实施次序见 [PRODUCT_ROADMAP.md](PRODUCT_ROADMAP.md)。以下“现有”依据源码定义与既有 QA 证据；“规划”均须在对应阶段另行实施和验证。

## 1. 已核实的现状与不变约束

直接核对 [Schema.php](../wp-content/themes/ai-skills-hub/inc/core/Database/Schema.php) 的 `COLUMNS`、`UNIQUE_KEYS`、`SEARCH_KEYS` 和 `definitions()`，目前是 **14 张 Core 自定义表、151 个列定义**，不是把历史“八表”或某份规划中的候选表数当作现状。14 表由基础 8 表、采集 3 表、AI 3 表组成；WordPress 自带 users/usermeta/posts/postmeta/options 不计入这 14 表。

实际物理表名是 **`$wpdb->prefix . 'aish_' . $entity`**；默认前缀时才是 `wp_aish_*`。用户候选名称中的 `ash_*` 在本设计中统一映射为既有 `aish_*`，不建立第二套命名体系。原生 WordPress 表通过 `$wpdb->users`、`$wpdb->usermeta`、`$wpdb->posts` 等取得，不假定与站点表前缀完全相同；多站点的全局用户与站点业务归属须单独验证。

所有现有自定义表为 InnoDB、`utf8mb4_unicode_ci`，日期为 UTC。`source_path` 使用完整 `VARBINARY(768)`，保留路径字节和大小写，不使用截断唯一索引。所有外键均为**逻辑关系**，目前无 SQL `FOREIGN KEY` 或数据库级联删除。既有 DAL 提供字段/表白名单、参数绑定及事务；服务负责权限、引用校验、行锁和状态机。迁移持有数据库+站点前缀命名锁，逐级增量、结构验证成功后更新非 autoload 的 `aish_schema_version`；未知版本或降级被拒绝。

`skills.id` 是永久、语言无关的 Skill ID。收藏、评价、认领、举报、集合、场景和统计只引用它；不得引用某语言翻译 ID 来制造两套用户关系。来源唯一性仍是 `repository_id + source_path`，一个仓库可以有多个 Skills。WordPress 用户 ID、GitHub 数字 ID、WPML 对象 ID 均不能替代 Skill ID。

现有业务只归档，不提供硬删除 Skill/仓库的 API；升级和主题停用不清数据。V2 账户隐私删除是新增且有范围的用户数据处理，不授权删除既有 Skills、版本、人工译文、采集或 AI 历史。

证据优先级：上述源码定义与 [Migrator.php](../wp-content/themes/ai-skills-hub/inc/core/Database/Migrator.php)、[Database.php](../wp-content/themes/ai-skills-hub/inc/core/Database/Database.php)、[Catalog.php](../wp-content/themes/ai-skills-hub/inc/core/Content/Catalog.php)，其次为 [CODEX_MASTER_SPEC.md](../CODEX_MASTER_SPEC.md)、[CORE.md](CORE.md)、Phase 1–7 报告及 [FINAL_QA_REPORT.md](../FINAL_QA_REPORT.md)。`CORE.md` 中旧主题版本和阶段未执行描述是历史记录，当前交付结论以 Final QA/PROGRESS 为准：20 工作包通过、0 失败、5 外部验收未执行；本文不改变该结论。

## 2. 当前 schema 1.5.0 完整字典

为保持字典可读，以下使用类型缩写：`B=bigint(20) unsigned`，`I=int(10) unsigned`，`T=tinyint(1) unsigned`，`V(n)=varchar(n)`，`C(n)=char(n)`，`D=datetime`。`?` 表示允许 NULL、默认 NULL；没有 `?` 的列均 NOT NULL；`=值` 表示声明的默认值；未写默认值表示 DDL 未声明默认值。所有单列 `id B AUTO_INCREMENT` 均为主键。PK/UK/KEY 分别为主键/唯一/普通完整索引，列次序与源码一致。所有表名以下省略动态前缀及 `aish_`。

### 2.1 基础八表

| 表 / 列数 | 完整字段与类型 | 全部索引 | 逻辑关联与用途 |
| --- | --- | --- | --- |
| `repositories` / 14 | `id B AUTO_INCREMENT`; `owner V(39)`; `name V(100)`; `is_archived T=0`; `github_id B?`; `default_branch V(255)=''`; `sync_enabled T=0`; `sync_interval I=86400`; `last_sync_at D?`; `last_sync_commit V(64)=''`; `last_scan_at D?`; `sync_status V(20)='idle'`; `license_spdx V(100)=''`; `created_at D` | PK(`id`); UK `repository_identity(owner,name)`; UK `github_identity(github_id)` | owner/name 规范化小写；GitHub ID 可空。仓库身份与内部 id 分离；仓库归档停止采集但保留数据。 |
| `skills` / 13 | `id B AUTO_INCREMENT`; `repository_id B?`; `source_path VARBINARY(768)?`; `status V(20)='draft'`; `revision B=1`; `review_status V(20)='unreviewed'`; `risk_level V(10)='unknown'`; `reviewer_id B?`; `reviewed_at D?`; `reviewed_revision B?`; `review_notes TEXT?`; `created_at D`; `updated_at D` | PK(`id`); UK `skill_source(repository_id,source_path)`; KEY `status_created(status,created_at)`; KEY `public_updated(status,review_status,updated_at,id)` | `repository_id → repositories.id`；人工无来源时两列同时 NULL；`reviewer_id → WP users.ID` 是审核证据。`review_status` 是 Skill 内容审核，不是社区星级评价。 |
| `skill_translations` / 11 | `id B AUTO_INCREMENT`; `skill_id B`; `locale V(10)`; `title V(191)`; `slug V(191)?`; `summary TEXT`; `content LONGTEXT`; `seo_title V(191)=''`; `seo_description V(320)=''`; `created_at D`; `updated_at D` | PK(`id`); UK `skill_locale(skill_id,locale)`; UK `locale_slug(locale,slug)`; KEY `locale_title(locale,title,skill_id)` | `skill_id → skills.id`；zh_CN/en_US 共用 Skill；缺译不存在，不复制另一语言；NULL slug 只适用于草稿。 |
| `skill_versions` / 10 | `id B AUTO_INCREMENT`; `skill_id B`; `commit_sha V(64)`; `content_hash C(64)`; `source_content MEDIUMTEXT`; `version_label V(191)=''`; `file_sha V(64)=''`; `repository_id B?`; `source_path VARBINARY(768)?`; `created_at D` | PK(`id`); UK `skill_commit(skill_id,commit_sha)` | `skill_id → skills.id`；可空 `repository_id → repositories.id`。不可变原文快照；旧来源绑定未知仍未知，不能据 SHA 声称当前公开修订的源码已审。 |
| `categories` / 6 | `id B AUTO_INCREMENT`; `name V(191)`; `slug V(191)`; `parent_id B?`; `is_archived T=0`; `created_at D` | PK(`id`); UK `slug(slug)`; KEY `parent_id(parent_id)` | `parent_id → categories.id`；服务拒绝层级循环。分类名目前共享，无独立翻译表。 |
| `tags` / 5 | `id B AUTO_INCREMENT`; `name V(191)`; `slug V(191)`; `is_archived T=0`; `created_at D` | PK(`id`); UK `slug(slug)` | 非层级标签。`platform-*`、`license-*`、`featured` 由人工维护；目前无独立平台表。 |
| `skill_categories` / 2 | `skill_id B`; `category_id B` | PK(`skill_id,category_id`); KEY `category_id(category_id)` | `skill_id → skills.id`；`category_id → categories.id`；多对多。 |
| `skill_tags` / 2 | `skill_id B`; `tag_id B` | PK(`skill_id,tag_id`); KEY `tag_id(tag_id)` | `skill_id → skills.id`；`tag_id → tags.id`；多对多。 |

### 2.2 采集运营三表

| 表 / 列数 | 完整字段与类型 | 全部索引 | 逻辑关联与用途 |
| --- | --- | --- | --- |
| `imports` / 13 | `id B AUTO_INCREMENT`; `skill_id B`; `repository_id B`; `source_path VARBINARY(768)`; `file_sha V(64)=''`; `commit_sha V(64)=''`; `source_status V(20)='present'`; `change_status V(20)='new'`; `metadata_json MEDIUMTEXT?`; `findings_json MEDIUMTEXT?`; `last_seen_job B?`; `created_at D`; `updated_at D` | PK(`id`); UK `import_skill(skill_id)`; UK `import_source(repository_id,source_path)`; KEY `repository_status(repository_id,source_status)` | 引用 `skills/repositories`；`last_seen_job → jobs.id`。当前来源投影与静态风险证据，不是另一份 Skill 正文。 |
| `jobs` / 15 | `id B AUTO_INCREMENT`; `repository_id B`; `kind V(10)`; `status V(20)='queued'`; `active_key B?`; `state_json MEDIUMTEXT?`; `attempts I=0`; `available_at D`; `lease_token V(64)=''`; `locked_until D?`; `deadline_at D`; `created_at D`; `updated_at D`; `completed_at D?`; `last_error V(64)=''` | PK(`id`); UK `active_repository(active_key)`; KEY `due(status,available_at)`; KEY `lease(status,locked_until)` | `repository_id → repositories.id`。活动时 active_key=repository_id，终态 NULL；同仓库最多一个活动采集任务。 |
| `import_logs` / 9 | `id B AUTO_INCREMENT`; `job_id B`; `repository_id B`; `skill_id B?`; `source_path VARBINARY(768)?`; `level V(10)`; `code V(64)`; `message V(500)`; `created_at D` | PK(`id`); KEY `job_log(job_id,id)` | 引用 `jobs/repositories/skills`；固定错误码、短脱敏信息；现有服务限制每任务最多 1000 条，不存凭据。 |

### 2.3 AI 运营三表

| 表 / 列数 | 完整字段与类型 | 全部索引 | 逻辑关联与用途 |
| --- | --- | --- | --- |
| `ai_jobs` / 32 | `id B AUTO_INCREMENT`; `skill_id B?`; `mode V(20)`; `request_key C(64)`; `active_key C(64)?`; `profile_json MEDIUMTEXT`; `profile_hash C(64)`; `provider V(64)`; `model V(191)`; `prompt_version V(64)`; `source_json MEDIUMTEXT`; `source_commit V(64)=''`; `file_sha V(64)=''`; `status V(20)='queued'`; `review_status V(20)='pending'`; `revision B=1`; `output_json MEDIUMTEXT?`; `edited_json MEDIUMTEXT?`; `attempts I=0`; `input_tokens B?`; `output_tokens B?`; `cost_estimate DECIMAL(20,8)?`; `elapsed_ms B=0`; `requester_id B`; `lease_token C(64)=''`; `locked_until D?`; `available_at D`; `deadline_at D`; `created_at D`; `updated_at D`; `completed_at D?`; `last_error V(64)=''` | PK(`id`); UK `request_identity(request_key)`; UK `active_generation(active_key)`; KEY `due(status,available_at)`; KEY `skill_history(skill_id,id)`; KEY `lease(status,locked_until)` | `skill_id → skills.id` 可空用于连接测试；`requester_id → WP users.ID`。来源、配置、原输出、人工稿分离；AI review_status 不等于社区评价或正式 Skill 审核。 |
| `ai_attempts` / 12 | `id B AUTO_INCREMENT`; `job_id B`; `attempt_number I`; `request_key C(64)`; `status V(20)='inflight'`; `input_tokens B?`; `output_tokens B?`; `cost_estimate DECIMAL(20,8)?`; `elapsed_ms B=0`; `error_code V(64)=''`; `started_at D`; `completed_at D?` | PK(`id`); UK `job_attempt(job_id,attempt_number)`; UK `attempt_request(request_key)` | `job_id → ai_jobs.id`。外部请求前持久化；结果不确定不当作从未发送；费用缺失保持 NULL。 |
| `ai_reviews` / 7 | `id B AUTO_INCREMENT`; `job_id B`; `revision B`; `action V(20)`; `actor_id B`; `snapshot_json MEDIUMTEXT`; `created_at D` | PK(`id`); UK `job_revision(job_id,revision)` | `job_id → ai_jobs.id`；`actor_id → WP users.ID`；AI 人工编辑/采用/拒绝事件，不能复用为用户星级评论表。 |

### 2.4 已使用的 WordPress 原生存储

| 存储 | 当前已核实用法 | V2 复用原则 |
| --- | --- | --- |
| `users` / `usermeta` | WordPress 管理员身份、角色/capabilities，以及 `reviewer_id/requester_id/actor_id` 引用；主题尚无完整前台账户流程 | 唯一身份源；密码哈希、登录 Cookie、重置密码、会话均由 WP 持有；新用户 Subscriber，不复制邮箱密码账户表。 |
| `posts` / `postmeta`：`aish_message` | [Forms.php](../wp-content/themes/ai-skills-hub/inc/core/Frontend/Forms.php) 注册私有 CPT；`post_author=0`；`post_content` 原始消息；`_aish_message_kind=contact/submit`、`_aish_message_locale`、`_aish_sender_name`、`_aish_sender_email`、`_aish_github_url`、`_aish_message_state=pending/reviewed/archived`，后台另有 `_aish_message_notes` | P10 沿用同一帖子 ID 和收件箱，增加账户归属、版本和处理结果；不建 `submissions` 影子表。旧匿名投稿不得按邮箱自动绑定新账户。 |
| `posts` / `postmeta`：`aish_tutorial` | [Editorial.php](../wp-content/themes/ai-skills-hub/inc/core/Frontend/Editorial.php) 使用原生正文、作者、revisions；`_aish_locale`、`_aish_translation_id`、多值 `_aish_related_skill`；原生公开/REST 出口关闭，由受限双语路由显示 | 帮助/教程继续使用原生文章及受控关联；不将 Skill 迁到 CPT。 |
| 原生 `page` / postmeta | About/Contact/Submit 用途、语言、SEO 元数据；教程/页面 `_aish_seo_*` | 增加帮助、赞助、企业说明等内容和语言配置；不为静态入口建业务表。 |
| `options` / transient | `aish_schema_version`、SEO/AI 配置、锁保护限流、重写及后台提示；业务配置多为 non-autoload | 配置、开关和短缓存继续原生存储；持续通知、事件、榜单关系不用无限增长的序列化 option。 |

当前 Forms 的 GitHub 校验允许根地址以外的路径且允许空值，不是正式来源身份校验；P10 应复用 `GitHubClient::repositoryIdentity()` 的严格根 URL 规范化，再用 `Input::sourcePath()` 处理管理员确认的仓库内路径。当前 `NativeMetadata` 只保证受控元数据事务，并不保证 `wp_insert_post` 正文、所有 hooks 与元数据在一个完整投稿事务内；新工作流必须补齐该边界，不能将现状说成已经具备用户编辑/幂等提交。

## 3. 用户候选表逐项决定

下表是规划决定；新建的时间以第 8 节为准。

| 用户候选 | 决定 / 实际存储 | 理由 |
| --- | --- | --- |
| `ash_user_profiles` | **不建；复用 WP users/usermeta** | 昵称/简介、头像附件 ID、语言、公开开关、验证/账户状态是原生用户扩展；避免独立账户和重复邮箱。 |
| `ash_email_verifications` | **新建 `aish_email_verifications`** | 一次性验证凭证需要哈希唯一、到期、消费及重发失效的可并发约束；不保存密码，不替代 WP 密码重置。 |
| `ash_favorites` | **新建 `aish_favorites`** | user+skill 唯一关系、分页及真实计数；跨语言一份收藏。 |
| `ash_reviews` | **新建 `aish_reviews`，另加修订表** | 一人一 Skill 一条有效评价；需要独立待审版本与已批准版本，不能覆盖评分快照。 |
| `ash_review_reports` | **新建 `aish_review_reports`** | 举报指向确切公开评价修订，保留处理状态，避免评价修改后证据漂移。 |
| `ash_user_notifications` | **新建 `aish_user_notifications`** | 站内收件箱、未读和用户分页；与邮件投递尝试分开。 |
| `ash_notification_preferences` | **不建；复用 usermeta** | 当前仅单用户的有限偏好对象，无需跨用户关系表；必要事务通知不等同营销订阅。 |
| `ash_user_activities` | **不建泛用户轨迹表** | 不收集持续浏览历史；操作审计用有限 `workflow_events`，计数去重用短期 `metric_events`，职责和留存不同。 |
| `ash_skill_reports` | **新建 `aish_skill_reports`** | 失效来源、内容错误、安全/违规线索需结构化队列和结果；不直接修改 Skill 或触发采集执行。 |
| `ash_collection_views` | **不建独立表** | 收藏页是关系查询；Skill 榜单与集合浏览通过共用 metric_events/daily_metrics 的明确维度计数，不建个人集合浏览历史。 |
| `ash_editorial_collections` | **新建 `aish_editorial_collections`，另加翻译表** | 编辑集合有独立稳定 ID、发布/排序和双语介绍；与单一 featured 标签不同。 |
| `ash_collection_items` | **新建 `aish_collection_items`** | 集合—Skill 多对多，唯一关系及人工顺序；动态保留 Skill 公开门禁。 |
| `ash_use_cases` | **新建 `aish_use_cases`** | 任务/场景需要稳定分类身份，不把已有人工标签暗中改为另一语义。 |
| `ash_use_case_translations` | **新建 `aish_use_case_translations`** | 场景中英名称、slug 和说明可独立审核；不把标签现有共享名称声称为已经翻译。 |
| `ash_use_case_skills` | **新建 `aish_use_case_skills`** | 场景—Skill 多对多；仅显式人工确认关联，AI 只能建议。 |

候选之外的必要结构：`review_revisions` 承载不可变评论稿件；`notification_outbox` 承载事务邮件可靠投递；`workflow_events` 承载审核/状态审计；`author_claims` 区分投稿者、仓库维护者和经核实作者；`collection_translations` 避免集合双语重复实体；`term_translations` 为现有分类/标签补充真实翻译而不复制实体；`metric_events/daily_metrics` 为真实 Views/Trending 和有界运营统计提供去重和聚合依据；`newsletter_subscriptions` 保存可撤销的自愿周精选同意。不会仅为了达到候选表数量而建表。

## 4. 新结构的规划字典

本节沿用第 2 节类型简写与 NULL 约定。新表同样为 InnoDB/utf8mb4，标识哈希用 `C(64)` 的 ASCII 二进制比较，状态用服务白名单，不依赖 MySQL ENUM 难以扩展的迁移。主键是 B，计数为非负 I/B；涉及增减计算时先检查下界，不以无符号溢出来处理负数。所有 JSON 字段均限定键、类型、大小及版本，使用文本列便于既有 DAL 和 dbDelta 管理；不是任意插件写入通道。

### 4.1 Phase 8：原生账户、验证和最小通知基础

| 新表 | 字段 | 主键、唯一、查询索引与逻辑引用 |
| --- | --- | --- |
| `email_verifications` | `id B AUTO_INCREMENT`; `user_id B`; `purpose V(32)`; `target_email V(254)`; `email_hash C(64)`; `token_hash C(64)`; `active_key C(64)?`; `expires_at D`; `attempt_count I=0`; `consumed_at D?`; `created_at D`; `updated_at D` | PK(`id`); UK(`token_hash`); UK(`active_key`); KEY(`user_id,purpose,created_at`); KEY(`expires_at,id`); `user_id → WP users.ID`。active_key 为 user+purpose 哈希，仅一个当前有效凭证。target_email 只保存需要验证的地址，不是登录账户副本。 |
| `user_notifications` | `id B AUTO_INCREMENT`; `user_id B`; `event_key C(64)`; `type V(32)`; `subject_type V(32)`; `subject_id B?`; `template_key V(64)`; `params_json TEXT`; `locale V(10)`; `read_at D?`; `created_at D`; `expires_at D` | PK(`id`); UK(`user_id,event_key`); KEY(`user_id,read_at,id`); KEY(`expires_at,id`); `user_id → WP users.ID`；subject 是允许类型集合的逻辑引用。通知仅投影用户可看的状态，不含内部审核备注/别人的举报人信息。 |
| `notification_outbox` | `id B AUTO_INCREMENT`; `recipient_user_id B?`; `notification_id B?`; `event_key C(64)`; `channel V(16)='email'`; `template_key V(64)`; `locale V(10)`; `destination_hash C(64)`; `payload_ciphertext MEDIUMTEXT?`; `key_version V(32)`; `status V(20)='queued'`; `attempts I=0`; `available_at D`; `lease_token C(64)=''`; `locked_until D?`; `last_error V(64)=''`; `provider_message_id V(191)?`; `created_at D`; `updated_at D`; `accepted_at D?`; `expires_at D` | PK(`id`); UK(`event_key,channel`); KEY(`status,available_at,id`); KEY(`status,locked_until`); KEY(`recipient_user_id,created_at`); KEY(`expires_at,id`)；引用 WP user / notification。这是新通知队列，不复用 GitHub jobs 或 AI attempts。 |

用户扩展复用 usermeta，规划白名单包括 `_aish_account_state`（pending_email/active/suspended）、`_aish_email_verified_at`、`_aish_verified_email_hash`、`_aish_profile_locale`、`_aish_avatar_attachment_id`、`_aish_public_profile`、`_aish_notification_preferences`、`_aish_onboarding_version`。昵称/简介优先用原生 `display_name`/`description`；无第二份密码、登录 token 或用户主键。头像引用归属明确的受控 WP attachment，Subscriber 不自动获得通用上传/媒体库权限。收藏明细始终私有，本版不增加公开收藏开关。

`_aish_notification_preferences` 是有固定版本的有限对象：站内可选事件、可选事务邮件和语言；账户验证/重置等必要安全邮件独立定义。周精选默认关闭，不能从“接收投稿处理结果”推导营销同意。usermeta 没有 `(user_id,meta_key)` 唯一约束，关键状态必须在用户级锁内用 WP API 保存并读回；不能把 `update_user_meta()` 的非唯一存储误当 SQL 唯一键。

注册使用 WP 用户 API、WP 密码策略和 Subscriber；待验证账户禁止参与收藏/评价/投稿，登录会话仅能进入验证/帮助流程。注册、改邮箱最终验证消费、产品资料修改和管理员改邮箱入口共用规范邮箱命名锁，并在锁内 `email_exists()` 复查；涉及两个邮箱时按规范键排序取锁，再取账户锁，统一采用“邮箱锁 → 账户锁”次序。不在 `users` 上擅加全局唯一邮箱索引；注册后不因邮箱竞态误更新他人的账户。原生 WP 用户写入 hooks 纳入验证与失效协议，第三方插件路径须兼容验收；直接 SQL 绕过 hooks 不在所承诺的并发协议内。邮箱规范化不做 Gmail 点号/加号等供应商特例合并。

验证 URL 包含高熵随机凭证，表内只存不可逆 token 哈希，绑定 user/purpose/目标邮箱并用事务单次消费；重发使旧 active_key 失效。邮箱更改成功前保留原 WP 邮箱，成功时原子更新账户与验证状态、撤销旧凭证/必要会话；安全通知按策略发往旧地址。密码重置继续使用 WP 原生密钥和流程，不建立自定义 password_reset 表。

outbox 中确需持久化的一次性链接/目标地址规划使用专用服务器密钥认证加密，密钥材料不入数据库/Git，表内只存非秘密 key_version；成功提交发送或到期后清除 ciphertext。注册前检查加密/邮件配置就绪，专用 key 缺失时阻止创建新的待验证注册及重发投递并提示“注册邮件服务未就绪”，不能把明文 token 临时塞进日志/JSON，也不能无邮件恢复路径地接受注册。该检查不一刀切停用原生管理员登录/恢复路径。

密钥轮换后新消息使用新版本，旧版本仅在未过期队列/验证所需窗口内留服务器以便解密，迁移密文需受控地重新加密；旧 key 丢失或泄露则取消关联敏感投递、撤销对应验证凭证并要求受限重发，不以明文降级，也不谎称历史邮件能恢复。冻结目标地址避免排队期间用户改邮箱导致验证链接发错对象。WP mail 返回成功只表示交给传输层，不能显示“已送达收件箱”。以上均是 P8 待实现设计；P8 仅实现账户所需通知类型，投稿/评论等类型在所属阶段增加。

### 4.2 Phase 9：收藏、社区评价、举报、审计与真实计数

| 新表 | 字段 | 主键、唯一、查询索引与逻辑引用 |
| --- | --- | --- |
| `favorites` | `user_id B`; `skill_id B`; `is_active T=1`; `created_at D`; `updated_at D`; `cancelled_at D?` | PK(`user_id,skill_id`); KEY(`user_id,is_active,created_at,skill_id`); KEY(`skill_id,is_active,created_at,user_id`)；引用 WP user/Skill。取消仅改 is_active=0，重加不改首次 created_at；账户删除按隐私流程物理删除。不存在另一个跨语言副本。 |
| `reviews` | `id B AUTO_INCREMENT`; `user_id B?`; `skill_id B`; `state V(20)='pending'`; `approved_revision_id B?`; `pending_revision_id B?`; `lock_version B=1`; `first_published_at D?`; `created_at D`; `updated_at D`; `withdrawn_at D?` | PK(`id`); UK(`user_id,skill_id`); KEY(`skill_id,state,id`); KEY(`user_id,updated_at,id`)；user 可空仅用于删除后匿名化；两个指针必须指向本 review 的 revision。账户存续期间一 user+skill 始终一条头记录，撤回后重提也复用。 |
| `review_revisions` | `id B AUTO_INCREMENT`; `review_id B`; `revision I`; `locale V(10)`; `rating T`; `comment TEXT`; `experience TEXT`; `pros TEXT`; `cons TEXT`; `moderation_state V(20)='pending'`; `moderator_id B?`; `moderated_at D?`; `moderation_code V(64)?`; `private_note TEXT?`; `request_key C(64)`; `created_at D` | PK(`id`); UK(`review_id,revision`); UK(`request_key`); KEY(`moderation_state,created_at,id`); KEY(`review_id,id`)；引用 review/WP moderator。rating 服务与数据库兼容约束验证为整数 1–5；评论/体验/优缺点分别保存，跨语言计分同一头。 |
| `review_reports` | `id B AUTO_INCREMENT`; `review_id B`; `revision_id B`; `reporter_user_id B?`; `locale V(10)`; `reason_code V(32)`; `details TEXT`; `state V(20)='pending'`; `active_key C(64)?`; `request_key C(64)`; `handler_id B?`; `resolution_code V(64)?`; `private_note TEXT?`; `created_at D`; `resolved_at D?` | PK(`id`); UK(`request_key`); UK(`active_key`); KEY(`state,created_at,id`); KEY(`review_id,revision_id,id`); KEY(`reporter_user_id,id`)；引用 review、确切 revision、WP reporter/handler。locale 是举报/反馈语言，原评论语言从 revision 确认；active_key=user+revision+举报类型，在未结案时防重复；正文不公开。 |
| `skill_reports` | `id B AUTO_INCREMENT`; `skill_id B`; `skill_revision B`; `reporter_user_id B?`; `locale V(10)`; `kind V(32)`; `details TEXT`; `state V(20)='pending'`; `active_key C(64)?`; `request_key C(64)`; `handler_id B?`; `resolution_code V(64)?`; `private_note TEXT?`; `created_at D`; `resolved_at D?` | PK(`id`); UK(`request_key`); UK(`active_key`); KEY(`state,kind,created_at,id`); KEY(`skill_id,id`); KEY(`reporter_user_id,id`)；引用 Skill/WP user；保留举报时 Skill 修订和明确语言，纠错必须选择目标内容 locale，不能猜 UI 语言。active_key 含 locale；kind 覆盖 broken_link/correction/security/abuse 等白名单。 |
| `workflow_events` | `id B AUTO_INCREMENT`; `event_key C(64)`; `subject_type V(32)`; `subject_id B`; `subject_revision B?`; `action V(32)`; `actor_user_id B?`; `from_state V(20)?`; `to_state V(20)?`; `metadata_json TEXT`; `created_at D`; `retain_until D?` | PK(`id`); UK(`event_key`); KEY(`subject_type,subject_id,id`); KEY(`subject_type,action,created_at,subject_id`); KEY(`actor_user_id,created_at,id`); KEY(`retain_until,id`)；subject 仅允许 skill/review/report/submission/claim 等内部枚举，P10 扩展。仅操作结果和固定原因，不复制全量私信、密码或浏览轨迹。公开 Skill 内容事件去除个人信息后可按内容历史保留。 |
| `metric_events` | `id B AUTO_INCREMENT`; `metric_name V(32)`; `dimension_type V(16)`; `dimension_key V(191)`; `locale V(10)=''`; `event_day DATE`; `dedupe_key C(64)`; `policy_version V(16)`; `occurred_at D`; `expires_at D` | PK(`id`); UK(`event_day,metric_name,dedupe_key`); KEY(`dimension_type,dimension_key,event_day`); KEY(`expires_at,id`)；维度类型/键严格解析，Skill/collection 维度逻辑引用相应 ID；无长期 user_id、原始 IP、邮箱或 UA。dedupe_key 输入含维度、locale、事件范围和日桶。 |
| `daily_metrics` | `stat_day DATE`; `metric_name V(32)`; `dimension_type V(16)`; `dimension_key V(191)`; `locale V(10)=''`; `metric_count B=0`; `policy_version V(16)`; `updated_at D` | PK(`stat_day,metric_name,dimension_type,dimension_key,locale`); KEY(`metric_name,dimension_type,stat_day,dimension_key`)；维度/指标配对仅接受第 5.3 节白名单，不接受任意 JSON。政策版本变更从新 UTC 日启用；是统计投影，不是评分/收藏事实源。 |

本规划**不向现有 skills 表新增发布时间字段**。从 P9 开始，在人工批准公开内容的同一事务追加 `workflow_events(subject_type=skill, action=public_content_approved, subject_revision=当前修订)`，记录真实事件时间与批准内容指纹；没有内容变化的再次审核不冒充内容更新。Recently Added 使用原实体 `created_at` 并明确称“收录日期”；Recently Updated 查询这些可证公开内容事件，再检查当前公开门禁。采集新来源、AI 草稿、后台 updated_at 都不替代公开内容事件；历史无可证事件的记录不回填虚构日期，显示更新时间未知或不进入该事件榜。

### 4.3 Phase 10：原生投稿与作者认领

**投稿继续以 `aish_message` 的 WP post ID 为主键。** 新注册用户提交时 `post_author=当前 user_id`，保持 post_status=private；`post_content` 是当前稿，原生 revisions 或受控 workflow snapshot 保存编辑前版本，不能仅依赖当前不支持 revisions 的 CPT 配置。P10 必须显式增加并验证 revisions 支持或由投稿服务保存不可变版本，不假定现状已有。

| 规划新增/扩展的受保护 postmeta | 类型与含义 |
| --- | --- |
| `_aish_message_kind` | 现有 submit/contact 继续保留；新增 update_suggestion。所有者和角色仍从 WP 当前会话/记录读取。 |
| `_aish_source_kind` | github/manual 枚举；GitHub 类型要求严格根 URL，manual 类型保留来源说明并按现有人工审核，不凭空补仓库身份。 |
| `_aish_submission_state` | draft/submitted/needs_changes/in_review/accepted/rejected/withdrawn；与历史 `_aish_message_state` 分离，旧处理状态不冒充正式接收/发布结果。 |
| `_aish_submission_revision`、`_aish_submission_request_key` | 正整数修订；随机请求键哈希，防重复提交；meta 无物理唯一索引，须走第 5 节锁协议。 |
| `_aish_canonical_repository` | GitHub 类型为规范化 `owner/name`，严格根 HTTPS GitHub URL 解析；保留原 `_aish_github_url` 作原提交证据。人工无 GitHub 投稿可缺省，不强造来源。 |
| `_aish_repository_id`、`_aish_target_skill_id`、`_aish_target_skill_revision` | 可空的既有实体逻辑引用；更新建议必须指向当前公开 Skill 和所见修订，不让用户替换来源身份。 |
| `_aish_suggested_source_path` | 可空、规范仓库内路径建议；未知保持 NULL/缺省并标待发现，不默认 SKILL.md。不是用户决定的最终 Skill 主键；管理员确认后仍由既有来源唯一键校验。 |
| `_aish_result_skill_ids` | 有界整数 ID 列表；一个仓库投稿经审核可产出多个 Skill，不能强行根 URL=一个 Skill。 |
| `_aish_submitted_at`、`_aish_resolved_at`、`_aish_handler_id`、`_aish_user_visible_feedback` | UTC 时间、处理 WP 用户 ID、单独的用户可见反馈。现有内部 `_aish_message_notes` 始终私有。 |

仓库根 URL 是来源线索，可以经管理员明确授权既有 Sources 流程后发现多个路径；投稿网页不直接发起扫描。相同仓库的线索可显式关联已有处理记录，但不能因仓库相同拒绝不同路径的 Skill。已知路径才使用严格 repository_id+source_path 去重；人工无 GitHub 投稿继续允许 repository/path 同时为空，最终人工审核规则保持。来源不完整的提案不是已确认 Skill，更不是自动发布依据。

新建唯一必要的关系表：

| 新表 | 字段 | 主键、唯一、查询索引与逻辑引用 |
| --- | --- | --- |
| `author_claims` | `id B AUTO_INCREMENT`; `skill_id B`; `claimant_user_id B?`; `claim_role V(16)`; `repository_id B?`; `source_revision B`; `state V(20)='pending'`; `proof_kind V(32)`; `proof_json TEXT`; `request_key C(64)`; `active_request_key C(64)?`; `approved_skill_key B?`; `reviewer_id B?`; `reviewed_at D?`; `expires_at D?`; `created_at D`; `updated_at D` | PK(`id`); UK(`request_key`); UK(`active_request_key`); UK(`approved_skill_key`); KEY(`skill_id,state,id`); KEY(`claimant_user_id,state,id`); KEY(`state,created_at,id`)；引用既有 Skill/repository、WP claimant/reviewer。claim_role 仅 author/maintainer；active_request_key 防同用户同 Skill 重复待审；approved_skill_key=skill_id 仅对有效主要核实归属行设置。 |

本规划建议首版每 Skill 最多一个“主要核实归属”账户，并按 claim_role 清楚区分作者和维护者；这不意味着一个 Skill 只有一位原创作者。其他公开署名也须有真实证据，不赋予未经核实权属；多人维护团队若进入范围，应另设计角色和有效期关系，不能悄悄放宽唯一键。仓库控制证据只能支持 maintainer，author 需要来源署名、版权/许可或其他可信创作证据核对；不能把邮箱、同名 GitHub 账号、自填 URL 或投稿身份直接当证明。proof_json 只存公开证据定位、核验时间和必要摘要，不存 GitHub Token、身份证或私钥。转移/撤销在事务内释放旧 approved_skill_key、核对来源身份并写审计；认领不授予管理员权限或直接发布权。

开发者主页从 opt-in 用户资料及有效 author_claims、当前公开 Skills 投影，不建 developer_accounts 表。P10 在 usermeta 追加有界 `_aish_profile_translations`，仅允许 zh_CN/en_US 两个键，每项为真实 `display_name/bio` 和可选 `seo_title/seo_description`，各字段有长度/KSES 白名单。原生 description 可作为个人私有默认介绍，但未经明确语言确认和审核不能复制为两份公开译文；缺译显示缺失，不生成对应 hreflang 或伪装另一语言介绍。

公开路由 `/{lang}/authors/{public_handle}/` 的 handle 规划复用 WP `user_nicename`，但现有自动从 user_login 生成的值不能直接开放。用户显式 opt-in 后提交独立、无邮箱/内部登录名的公开候选，审核时分配 3–50 字符安全 slug；持规范 handle 命名锁后核对站点原生用户记录中的唯一性，并由 WP API 保存/读回。原生 nicename 没有物理唯一索引，必须和邮箱规则一样明确受控写路径及第三方兼容边界；不擅自改 WP 核心表。已有 author URL 的改名影响应在审批时确认并按公开身份规则处理，未 opt-in 账户没有作者主页。

资料审核使用 `_aish_profile_revision`、`_aish_profile_review_status`、`_aish_profile_reviewed_revision`、`_aish_profile_reviewer_id`、`_aish_profile_reviewed_at` 及受保护 `_aish_profile_approved_snapshot`：用户编辑的是待审双语资料，公开读取不可由用户改写的批准快照，快照含真实语言内容、handle 和批准修订。首次待审不公开，既有资料编辑可保留旧批准版；停用、撤回 opt-in 或封禁立即关闭公开投影。服务在账户锁内防过期批准，P10 起写 workflow 审计；资料批准不自动批准作者认领，认领批准也不自动公开未审资料。

更新建议复用同一个私有收件箱 kind，最终采用仍经现有 Management/来源审核；不直接覆盖人工译文或重绑 Skill。

### 4.4 Phase 11：精选集合和使用场景

| 新表 | 字段 | 主键、唯一、查询索引与逻辑引用 |
| --- | --- | --- |
| `editorial_collections` | `id B AUTO_INCREMENT`; `status V(20)='draft'`; `revision B=1`; `owner_user_id B?`; `sort_order I=0`; `created_at D`; `updated_at D`; `published_at D?` | PK(`id`); KEY(`status,sort_order,id`); owner 逻辑引用 WP user。独立集合 ID，不复制 Skills。 |
| `collection_translations` | `id B AUTO_INCREMENT`; `collection_id B`; `locale V(10)`; `title V(191)`; `slug V(191)?`; `description TEXT`; `seo_title V(191)=''`; `seo_description V(320)=''`; `created_at D`; `updated_at D` | PK(`id`); UK(`collection_id,locale`); UK(`locale,slug`)；引用 collection，缺译不伪造 hreflang。 |
| `collection_items` | `collection_id B`; `skill_id B`; `sort_order I=0`; `added_by B?`; `created_at D` | PK(`collection_id,skill_id`); KEY(`collection_id,sort_order,skill_id`); KEY(`skill_id,collection_id`)；引用 collection/Skill/WP user。人工排序冲突以 Skill ID 稳定排序。 |
| `use_cases` | `id B AUTO_INCREMENT`; `status V(20)='draft'`; `revision B=1`; `sort_order I=0`; `created_at D`; `updated_at D` | PK(`id`); KEY(`status,sort_order,id`)；稳定、语言无关场景身份；V2 不引入无需求的层级树。 |
| `use_case_translations` | `id B AUTO_INCREMENT`; `use_case_id B`; `locale V(10)`; `name V(191)`; `slug V(191)?`; `description TEXT`; `seo_title V(191)=''`; `seo_description V(320)=''`; `created_at D`; `updated_at D` | PK(`id`); UK(`use_case_id,locale`); UK(`locale,slug`)；引用 use_case。 |
| `use_case_skills` | `use_case_id B`; `skill_id B`; `assigned_by B?`; `created_at D` | PK(`use_case_id,skill_id`); KEY(`skill_id,use_case_id`)；引用 use_case/Skill/WP user；来源只能是人工确认，不自动将 AI 用途建议写成正式关系。 |
| `term_translations` | `id B AUTO_INCREMENT`; `term_type V(16)`; `term_id B`; `locale V(10)`; `name V(191)`; `description TEXT`; `created_at D`; `updated_at D` | PK(`id`); UK(`term_type,term_id,locale`); KEY(`term_type,locale,name,term_id`)；term_type 仅 category/tag，分别逻辑引用既有 categories/tags。原 ID 和 slug 不变，不创建翻译版实体或第二套平台标签。 |

既有 featured 标签保持；其 Skill 可由编辑明确加入新集合，但迁移不凭空发布“精选榜”。平台、公开 License 仍用现有 tags，不另建 platform 表。既有类别/标签的共享名称保留，P11 通过 term_translations 追加人工翻译；缺译显示既有名称并标记缺译，不复制为假译文。原语言路径仍使用既有 slug，SEO 配置继续由现有按语言/实体的设置拥有。新场景/集合有自身翻译表。相关推荐按真实共同平台/场景/标签或人工集合关系排序，公开门禁后去重、排除当前 Skill，无数据时真实空状态；不存伪造“相似度”事实。

### 4.5 Phase 12–14：运营、预留商业入口与周精选

Phase 12 后台复用各工作流表、users/usermeta、原生收件箱和既有采集/AI 运营表；推荐位用可审核集合/现有 featured 标签及有界 options 配置。必要索引先根据真实查询/EXPLAIN 决定，不能为了阶段编号创建无用表。失效链接检查仍通过受限 GitHub 客户端和独立受控任务，不能让举报 URL 变成任意网络请求。邮件失败统计读 outbox 的真实状态，外部送达未知保持未知。

Phase 13 赞助说明、会员接口声明、套装/企业入口使用原生 page、语言元数据及 non-autoload options；会员接口只定义扩展能力合同/安全开关，**不创建账单、订单、支付 token、会员有效期或付费墙表**，不预写不存在的付费权益。

| Phase 14 新表 | 字段 | 主键、唯一、查询索引与逻辑引用 |
| --- | --- | --- |
| `newsletter_subscriptions` | `id B AUTO_INCREMENT`; `user_id B`; `state V(20)='pending'`; `locale V(10)`; `consent_version V(32)`; `consent_generation B=1`; `consent_nonce C(64)`; `token_key_version V(32)`; `consented_at D`; `confirmed_at D?`; `unsubscribed_at D?`; `email_hash C(64)`; `preferences_json TEXT`; `unsubscribe_token_hash C(64)`; `updated_at D` | PK(`id`); UK(`user_id`); UK(`unsubscribe_token_hash`); KEY(`state,locale,id`)；引用已验证 WP user。preferences 为有限的场景/平台 ID 与频率白名单，不存另一份密码或邮箱账户。nonce 是每次同意生成的随机值，不是可单独使用的退订凭证。 |

V2 周精选面向已有注册用户；偏好页可浏览，发送只对主动双重确认且仍 active 的订阅。确认凭证复用 email_verifications 的 newsletter purpose；邮箱变更使旧确认失效，需针对新邮箱重确认。取消订阅立即阻止新发送并取消尚未发出的 outbox 项；一键退订 token 只授权取消，不能读取账户。若以后开放访客邮箱订阅，应先设计独立联系人及验证/去重/隐私迁移，本方案不偷偷生成 WP 用户。历史账户、注册复选框缺省、收藏或通知设置都不回填为订阅同意。

退订 token 规划为服务端专用密钥 HMAC(`用途=unsubscribe + subscription_id + consent_generation + consent_nonce + token_key_version`) 的固定长度结果，表内只保存派生材料、key_version 和 token_hash；邮件生成时可重建同一同意期间的固定用途链接，发送后删除 outbox 密文不影响下一封生成。每封邮件不能轮换唯一 token_hash，导致旧邮件无法退订。退订幂等；重新订阅需要新同意代次和确认，此前链接不再控制新同意。密钥轮换保留仍有效同意代次所需旧版本的派生/验证能力，或显式使其安全失效并提供登录偏好页退订；密钥丢失/泄露先暂停受影响订阅发送、撤销旧凭证并重确认，不静默生成所有旧链接均失效的新 hash。

Phase 14 分享/安装复制不需要新计费或下载表；复制动作不能当作成功安装。只有可证来源的命令才展示，正文/来源发生变化后重新核验。若运营需要复制点击指标，只能经评审扩展 metric_events/daily_metrics 的白名单与口径版本；本版不预设该指标已经存在。

## 5. 跨表约束、并发和统计口径

### 5.1 一人一条有效评价与待审快照

本规划建议采用“已批准版本继续公开，编辑稿独立待审”的政策，作为产品评审项；该政策尚未实现。

1. 首次提交：事务中插入/锁定 `reviews(user_id,skill_id)`，新增 revision，设置 pending 指针；approved 指针为 NULL，公开评价数和评分均不增加。并发提交由唯一 user+skill、请求键及头记录锁协调，不能只先 SELECT 再无约束 INSERT。
2. 修改已批准评价：正文/星级写新的不可变 revision；头的 approved 指针仍指向旧版，新 pending 指针指向新稿。列表、均分、人数只读取旧批准版。用户再次改待审稿时生成更高版本并将旧待审稿标为 superseded，不原地改正文证据。
3. 审核：锁住头记录，核对 pending_revision_id、lock_version、提交内容和管理员能力。批准时原子设置新 approved 指针、清 pending，更新状态及首次发布时间（仅第一次），同事务写审核事件/通知/outbox。审核员打开的旧版本已被用户替换时，拒绝过期批准；不能批准另一条 review 的 revision。
4. 拒绝编辑稿：只拒绝当前 pending；旧批准版本仍是唯一公开评价。首次稿被拒绝则仍无公开指针。管理员的内部备注和举报者信息不返回作者的公开对象。
5. 撤回、隐藏、撤销批准或删除账户：在同一事务清公开指针，撤回/隐藏状态禁止公众读取；所有评分查询立即失效。被举报不等于自动下架，实际处理必须有明确决定。恢复须重验审批和权限，不能让用户编辑绕过隐藏状态。
6. `AVG/SUM/COUNT` 以 reviews 头 JOIN 其唯一 approved revision 且状态有效为准；验证 rating 是 1–5 整数、revision.review_id 匹配、确已批准。**不能对全部 revision 求平均，也不能把 pending 星级缓存到公开 Skill 行。**跨语言评价可以展示原语言标记及筛选，但全站评分始终只算一人一次。

rating/comment/experience/pros/cons 有长度白名单；评论正文必填，真实使用体验与优缺点可按产品规则留空，不能强迫用户编造体验或优缺点。星级有效不代表安全认证。此处自定义 reviews 是社区数据，既有 Management 的 Skill 审核和 ai_reviews 保持原职责。

### 5.2 写入协议与邮件事务

收藏的添加/取消使用明确目标状态，避免“toggle”在重试时反转两次；数据库联合主键与头记录锁决定重复添加幂等。取消设置 is_active=0、cancelled_at；再次添加设为 active 并清取消时间，但不改变首次 created_at。重复取消返回当前真实状态，不能通过删行再插入刷新近期热度。收藏页按当前 user_id+is_active 查询且始终私有；隐藏/归档 Skill 在本人收藏中显示不可用占位，在公共页面不能泄露其标题/链接。公共榜单只显示汇总数量。

所有新写请求需要登录状态、邮箱状态、对象所有权或具体 capability、nonce 和服务层重复校验。nonce 不提供幂等性，也不能证明对象归属。请求幂等键必须绑定用户、动作和载荷指纹；同 key 异 payload 返回冲突。并发唯一键冲突只在重新读到相同业务意图时返回“已处理”，不能吞掉其他数据库错误。

投稿因 postmeta 没有唯一索引，P10 的创建/编辑/撤回必须通过**一个受控投稿服务**：获取站点级命名锁（后续有实测才拆分按来源锁）→确认 WP posts/postmeta 为 InnoDB → 开单一 DAL 事务 → 锁定已有帖子/读取权属与修订 → 在锁内按规范来源/用户/请求键查重 → 保存帖子及所有必需元数据、状态事件与通知 → 验证持久值 → 提交后清理 WP 对象缓存 → 释放锁。受保护字段不能由通用后台 meta 编辑器或客户端直接写入。并发跨用户同来源投稿应返回已有公开 Skill/已有待处理提案的安全提示，不暴露他人的私稿；不同 source_path 不能错误合并。

现有 DAL 拒绝嵌套事务。跨原生帖子、Core 关系和 outbox 的工作流必须有一个外层事务所有者，并拆分可在已持锁事务中调用的内部方法；不得在事务里直接套调用又开事务的 `Management`/`NativeMetadata` 公共接口。事务中的 WP hooks 禁止发送邮件/网络请求或向共享缓存发布未提交内容，提交/回滚后均使相关缓存失效；兼容性验收须验证实际 hooks 行为。

状态改变与站内通知/outbox 插入在同一事务完成，避免“业务成功但永远没登记通知”。worker 按 due 索引领取一个小批次、设置租约和 token，发送后带同 token 条件更新；过期 worker 不得覆盖新 worker 状态。终态和可重试失败有明确重试上限/期限/退避。网络请求在事务外执行，不长期占行锁；不能复用采集/AI worker 来发送邮件。

outbox 状态白名单规划为 queued/running/retry/accepted/failed/uncertain/cancelled/expired；accepted 仅表示传输受理。投递 event_key 由业务事件、收件人、冻结目标地址的 HMAC、模板类型/版本和适用的订阅同意代次组成，再与 channel 唯一；同一事件发给不同用户或新旧地址不互相吞掉，重试同一投递不能生成新业务事件。站内通知同样按收件人事件去重。模板版本冻结在经认证加密的有界载荷中，不能因模板后来改动而悄悄改变已经排队的安全邮件。

提交响应丢失时事务可能已提交，先凭幂等键查真实状态，不盲目重做。邮件提供方受理结果未知时标 `uncertain`，除非提供方具有可核实的幂等机制，不自动无限重发同一验证链接或声称“恰好一次送达”。账号事件成功、邮件失败分别呈现；用户可在限频和令牌失效规则下显式重发。

### 5.3 有界指标与真实榜单

P9 引入最小统计写入基础，P11 使用 Skill 榜单和集合指标，P12 增加运营可视化；尚未上线的事件不回填假历史。客户端不能自由指定 metric_name、维度或计数增量；服务端根据已完成的真实动作生成事件。以下是允许的指标—维度配对，新增项需先更新口径与数据合同：

| 指标 | 允许维度 / 去重 | 用途与边界 |
| --- | --- | --- |
| `skill_view` | `skill` + 真正公开 Skill ID；同观察主体/Skill/UTC 日最多一次，跨 zh/en 合并到 locale='' | 公开详情成功渲染后计数；预览、错误、管理员预览、可识别爬虫、无资格计量的访问不计。称“去重浏览”，不是精确独立人数。 |
| `favorite_add` / `favorite_remove` | `skill` + ID；真实关系状态转换，同账户/Skill/动作/日去重 | 运营历史动作数；不作为当前收藏人数的事实源。反复点按钮不无限加计数。 |
| `first_review_approved` | `skill` + ID；review 首次批准事件唯一，后续编辑/重审不重复 | 运营首次批准数量；已撤回评价不能继续影响当前评分或热榜。 |
| `account_registered` / `email_verified` | `site` + 固定值 `all`；WP user 对应事件单次，事件中不保存明文 user ID | P9 启用以后真实事件；早期注册数可由 WP users 的时间查询，不能把未知历史验证时间填入新表。 |
| `active_user` | `site` + `all`；每个已验证 WP user/UTC 日一次，以当日盐 HMAC 去重 | 定义为真实登录或完成一次有效账户交互；匿名访问、邮件打开、仅注册不算活跃用户。只给日活口径，不把每日人数求和称月活独立人数。 |
| `search` / `search_no_result` | `site` + `all`，locale=zh_CN/en_US；同合法搜索请求幂等 | 全量合法搜索与无结果分母；只统计当前公开搜索结果，不记录完整查询 URL。统计失败不阻断搜索。 |
| `search_term` / `no_result_term` | `search_term` + 隐私清理后的规范短词，最多 64 Unicode 字符、总长度受列界限限制；同观察主体/词/语言/UTC 日一次 | 拒绝邮箱、手机号、URL、疑似 token/凭据及可识别长数字等敏感输入；无法安全分类时仅增加 site 总量，不落词。去掉原始用户标识，不建立某人搜索轨迹。 |
| `collection_view` | `collection` + 已发布编辑集合 ID；同主体/集合/UTC 日一次 | P11 集合上线，P12 获准启用对应运营指标后才收集；替代候选 collection_views 明细表，不跟踪私人收藏页。 |
| `submission_submitted` / `submission_accepted` | `site` + `all`；真实投稿状态事件 | P10 起记录；转化报表明确提交/接受的时间窗口，不能把不同期两个总数相除冒充同一队列转化率。 |
| `commercial_entry_impression` / `commercial_entry_click` | `entry` + 服务端配置的有限 `natural或sponsored:slot_code:item_code`；三部分均按配置白名单解析，同主体/位置/内容/指标/UTC 日去重 | P13 获准后按已审位置分别记录自然/赞助展示和点击。impression 只证明公开位置成功渲染并记录，不宣称用户实际观看；click 只证明有效入口动作，不是付费/成交。无任意 URL、用户文本或客户端自定义维度。 |

搜索词不因经过简单脱敏就宣称绝无个人信息；默认只向授权运营展示**单个 UTC 日**至少 5 个不同观察主体支持的词，低频/无法核实阈值的词不展示。搜索词日桶按主体去重，单人重复搜索或跨日累计不能顶过单日阈值；多日词报表只汇总达到阈值的日桶并标为日去重累计，不称周期独立用户数。关键词观察次数与完整搜索请求数分别命名。词维度按日设置硬上限，超限仍计 site 总量但显示词报表覆盖受限，不扩张无界高基数表。低频词日桶不超过 7 日即清理，原始搜索文本不进日志。

metric_events 的插入与对应 daily_metrics 增量在同一事务内；重复唯一键意味着同观察事件已经记过，不再加总。统计失败不回滚用户已经成功的独立浏览/搜索响应；收藏/评价等核心写入若带内部事件，应在核心事务保证事实记录，异步修复统计必须有可确认的事件键，不能从假设次数补加。短期事件清理前完成日桶核对；故障日标为缺失/部分覆盖，不能用 0 伪装完整成功。配置/统计覆盖状态采用有界 options（起始时间、政策版本、最近健康时间及有限缺口窗口），不建立永久逐用户日志。

匿名计量仅在隐私设置允许且有合法短期随机观察凭证时进行；退出计量时不尝试 IP 指纹补计。去重键使用每日轮换的服务端盐和限定作用域，既不保存 IP/UA，也不把现有登录 Cookie 当作可导出分析 ID。它能减少重复和简单刷量，不承诺识别真实独立人类；展示受限覆盖范围。搜索/访问类指标不得在账户活动流中展示个人轨迹。

榜单使用不同事实源，避免日聚合过期造成撤回后仍抬分：

- **Most Viewed：**按已知时间窗汇总 `skill_view` 日桶，标明采集起始日和更新时刻；同主体同 Skill 同 UTC 日中英只计一次，7/30 日结果是日去重浏览之和，**不等于整个窗口独立访客数**。没有数据时为空，不捏造全站历史访问量。
- **Most Favorited：**直接按当前 is_active=1 的 favorites 关系计数；取消收藏立即减少，站点私人收藏的明细不公开，公开数量采用产品告知后的汇总口径。
- **Top Rated：**仅当前公开 approved 指针；真实均分+人数，默认至少 5 条有效评价参加主榜；均分、人数、稳定 Skill ID 依次排序，无评价不设默认五星。
- **Trending：**最近 7 个完整 UTC 日，令 `V_d` 为去重浏览日聚合，`A_d` 为**当前 is_active=1** 且首次 created_at 落在该日的收藏，`R_d` 为**当前仍公开批准**且 first_published_at 落在该日的评价。默认建议 `Σ 2^(-d/3) × [ln(1+V_d)+3ln(1+A_d)+2ln(1+R_d)]`，最近完整日 d=0。A/R 直接从关系与审核指针批量分组，不读取历史动作聚合来冒充仍有效信号。取消/撤回立即排除；收藏重加、评价恢复/编辑重审均保留原首次贡献时间，不重新获得近期热度。零有效信号不入榜，少于 7 日显示实际窗口。公式和参数是待产品评审建议，持久化配置须有算法版本。
- **Recently Added / Recently Updated：**前者是真实收录日期；后者只用可证的公共内容批准事件。待审稿、来源扫描时间、评论/收藏活动都不伪装成正文更新。

所有排名、计数、集合、场景、开发者页和相关推荐均在最终查询再次套用既有公开门禁：published + approved + 当前审核修订、有效审核人员/时间、对应语言完整翻译；语言过滤发生在结果分页与数量计算前。Skill 归档、翻译撤回、审核失效时下一次公开请求即隐藏；缓存只保存可失效的派生结果，提交后清理相关 Skill/榜单/语言缓存，失败宁可显示更新中。赞助、AI 使用量和管理员推荐不得污染自然排行。

### 5.4 逻辑外键和删除边界

继续**不用数据库物理 FK**，以兼容既有 WordPress 生命周期、站点前缀、原生表和 dbDelta；这不等于允许任意悬空引用。写入服务锁定父记录、校验归属/状态/类型；关系表使用联合唯一键；删除/匿名化由受控服务按依赖顺序操作，同步写必要审计。事务锁顺序固定为规范邮箱锁（需要时）→账户 → Skill/原生帖子 → 关系头 → 修订/事件/通知，避免业务间反序锁争用；锁失败/死锁有界重试只允许已确认回滚的事务。

逻辑完整性巡检检查不存在父对象、跨 review 指针、错误 locale、负数/超界评分、已删除用户仍有待发信、重复 postmeta 权威值等；发现异常隔离该对象，不静默“修复”来源 ID、公开稿或评分。下段明确保留的历史审核 actor ID 是有边界的审计例外，不作为仍有效账户所有权。数据库级唯一约束与服务状态机必须同时验证；`NULL` 唯一键允许多行的语义仅用于明确终态释放，不表示可以绕过有效身份约束。

旧 `skills.reviewer_id`、AI requester/actor 是批准与审计证据。用户删除不能统一清空仍授权公开的 Skill reviewer_id，否则破坏原公开门禁；按内容审计保留政策保留最少、不可公开的历史数值 ID，不再通过该 ID 反查/输出已经删除的个人资料，也不重新分配这个历史身份。该历史引用不代表用户仍存在或仍有权限；具体必要性和期限须在隐私/内容审计政策明确。管理员/编辑注销先转交实际管理职责，不把已审公共内容一刀切撤稿。已有 WordPress 管理账户和集成服务不得因缺少 V2 `_aish_email_verified_at` 被默认封禁；迁移显式标为 legacy 管理身份，保留既有能力，新前台参与策略单独定义。

## 6. 隐私、保留期与用户注销

以下为实施前应在隐私说明中明确的**建议默认期限**，不是对既有数据库已经执行的清理。每次清理按固定批次/游标运行、有审计和暂停开关；主题停用/更新绝不自动删表。合理业务或投诉保全需明确范围和到期日，不能无限期保留所有个人信息。

| 数据 | 建议保留与删除/匿名化策略 |
| --- | --- |
| 未验证账户 | 验证 token 默认 24 小时到期；重发旧 token 立即失效。新注册且 7 日未验证、无业务贡献的 pending 用户可按告知策略删除；旧管理账户/存量用户不套此规则。 |
| 验证记录 | 过期/消费后 7 日内移除目标邮箱/token 哈希等短期凭证记录；账户只保留必要验证时间和当前邮箱绑定哈希。限频摘要最多 24 小时。 |
| 资料/头像/偏好/收藏 | 用户可导出/修改；公开资料默认关闭，收藏始终私有。取消收藏保留最少 user+skill/首次时间/软状态以免重复加分，不作为公开活动记录；注销后 30 日内物理删除本人扩展资料、私有头像附件和全部收藏状态行。共享/他人使用附件须先核对归属，不能按 ID 盲删。 |
| 评价 | 注销或用户撤回立即停止公开计分；默认注销清空公开指针、匿名化 user_id，30 日内清除评论/体验/优缺点等个人文本。若受理投诉需留证，仅留最少必要稿件，最多 180 日并限制访问；不得将已删除内容保留在公共缓存/搜索摘要中。 |
| 举报/认领/投稿 | 私有联系方式与证明按结案后最多 180 日保留；随后匿名化作者/处理人不必要个人信息并删除私人正文/证据。已被正式采用的 Skill 内容按内容授权与来源保留规则处理，不因投稿人注销删除公共 Skill 或原始仓库快照。身份认领注销即撤销公开归属，不把认领转给同邮箱新用户。 |
| 站内通知 | 默认最多 180 日，用户可清空；注销后立即停止读写并进入删除流程。主题 UI 只显示对该用户仍可见的目标摘要。 |
| 邮件 outbox | 验证/敏感 payload 受理发送或到期即销毁密文；完成/失败脱敏投递记录最多 30 日，尚需处理的失败上限也不超过明确任务期限。注销/退订取消适用的未发送邮件，安全邮件与营销区分。 |
| workflow 审计 | 普通审核/状态事件建议 365 日；到期清除非必要 actor/私密元数据。可证明公共内容修订的去个人信息事件可随内容历史保留；不复制评论正文来绕过删除。 |
| metric_events | 默认最多 7 日，仅用于幂等、聚合核对和低频阈值；到期按日桶删除。不得通过延长 HMAC 生命周期重建用户跨日轨迹。 |
| daily_metrics | Skill/site/collection 等无个人维度的汇总最多 400 日；清理后的搜索词维度最多 30 日，低频词到期删除；保留 site 总量不会保留原词。显示窗口不超过已存有效数据。 |
| 周精选 | 退订立即停止营销发送；30 日内清除兴趣偏好和可用于发送的凭证。最少同意/撤回版本及时间证明建议 180 日后匿名化/清除；不删除 WP 账户，也不影响必要安全通知。 |
| 备份 | 按站点声明的备份轮换周期，建议上限 30 日；恢复备份后先重放删除/退订清单再开放邮件发送和账户服务，防止被删除数据重新对外使用。 |

账户导出/删除接入 WordPress 隐私工具；先撤销会话、停用公开身份、取消待发营销/私人通知，再处理各关系和附件。P8 以原生私有 `user_request` 记录和受保护元数据保存目标旧 user ID、清理范围/游标/结果，支持暂停恢复；不将长清理事务塞进删除 hook。完成后的请求记录清除不必要的邮箱/身份信息并按明确期限保留最少结果。

清理协议同时接入产品注销、WP 管理员用户删除、插件调用 `wp_delete_user()` 等原生 hooks，不限于自有隐私表单；删除前先登记持久清理任务，删除后重查残留/待发队列并继续批处理。未经 hooks 的直接 SQL 删除不承诺即时完成，须由孤儿巡检发现后隔离处理。P10 将私有 `aish_message` 及其 revisions 加入清理：不能随着 WordPress“把该用户内容转交给另一用户”的全局 reassignment 把私人投稿、联系方式和编辑历史转给他人。受保护类型由清理服务先隔离/匿名化再执行原生删除或转交；一般文章的正常转交不自动授权私人投稿转交。需要保留的正式公共 Skill 与私有提案分别处理。

订阅停止、评分失效和公开资料隐藏应即时生效，实际异步擦除的进度可在仍有权限的请求状态页/管理员隐私工具查看；账户已彻底删除后不承诺还可登录 Dashboard。原生删除和对象缓存、第三方删除 hooks、并发发信之间的恢复必须在后续真实 WordPress 验收，当前只是规划协议。

文档中的 24 小时/7/30/180/365/400 日期限是明确可评审的默认值，实施前按经营地区及实际运营需求确认；不是将“合规”当作收集更多信息的理由。日志不得记录密码、验证码明文、重置/验证链接、数据库 SQL 绑定值、邮件正文或 API 密钥。

## 7. 查询与服务归属

新的账户/社区/投稿/发现/通知/统计服务经现有 ModuleLoader 声明依赖，复用同一个 Database/Migrator；模板只取公开投影。WordPress users/posts/options 通过原生 API 与有界事务封装访问；自定义表写入进入服务，不能把 DAL 暴露为“任意表/字段”HTTP 接口。Functions.php 不承载业务状态机。

主要读取：个人收藏由 `(user_id,is_active,created_at,skill_id)` 游标分页；评论由 `(skill_id,state,id)` 加批准指针读取；审核队列按状态/日期/ID；通知由 user+read_at+ID；每日榜单按窗口批量聚合后一次获取当前语言卡片，避免逐条 Skill 查询。投稿初期查询 meta 用有界分页和明确 kind/author，已有索引不足时基于真实 EXPLAIN 提出后续索引/投影迁移，不能未测先宣称大规模容量。

后台计数要注明来源和状态：WP 原生注册用户 ≠ 已验证活跃用户；已受理投稿 ≠ 已发布 Skill；邮件 transport accepted ≠ delivered；AI 请求 token/估算费用 ≠ 社区活跃度。统计不完整、读失败和真实零值分开显示，不以缓存旧数字掩盖故障。

## 8. Phase 8–14 版本化增量迁移计划

下表是**建议版本分配，尚未修改 `Schema::VERSION` 或迁移代码**。schema 版本与主题发布版本独立；没有 DDL 的阶段不为编号一致而假升级。

| 阶段 / 计划 schema | 新增自定义表 | 阶段后总表数 | 原生存储与数据保留 |
| --- | --- | --- | --- |
| 当前 / **1.5.0** | 已有 14 表 | **14** | 当前真实源码状态；原 Skill ID、全部字段/索引/历史保留。 |
| Phase 8 / **1.6.0** | email_verifications、user_notifications、notification_outbox（3） | **17** | users/usermeta 新增有限扩展元键；不改 WP users 核心定义，不默认锁死已有管理员，不批量虚构邮箱验证。 |
| Phase 9 / **1.7.0** | favorites、reviews、review_revisions、review_reports、skill_reports、workflow_events、metric_events、daily_metrics（8） | **25** | 原 14 表无字段更改；新增关系/统计从真实启用时开始，历史空数据真实为未知或空，不伪造评价、浏览或公开修订事件。 |
| Phase 10 / **1.8.0** | author_claims（1） | **26** | aish_message 原生元数据增量；旧匿名投稿保持原作者/正文/邮箱和状态，只标 legacy，不按邮箱认领，不自动创建 Skill。 |
| Phase 11 / **1.9.0** | editorial_collections、collection_translations、collection_items、use_cases、use_case_translations、use_case_skills、term_translations（7） | **33** | 原平台/标签/分类/slug 不变；新集合、场景和术语译文为空草稿起步，不伪造精选或翻译。 |
| Phase 12 / **仍 1.9.0** | 默认无新表 | **33** | 复用运营结构；若真实查询证明需要索引/字段，必须另开后续版本并调整后续编号，不能悄悄改已发布迁移。 |
| Phase 13 / **仍 1.9.0** | 无 | **33** | 原生内容/options 有界配置；无订单、支付、账单和付费权益迁移。 |
| Phase 14 / **1.10.0** | newsletter_subscriptions（1） | **34** | 独立主动同意，从未订阅开始；不把存量用户自动加入周精选。 |

合计规划新增 **20 表**，如全部获准实施则从当前 14 表到 34 表；当前仍是 14 表 / schema 1.5.0。每个阶段只注册并验证该阶段实际引入的表/列/索引，旧版本验证不能提前要求后续结构。字段长度、完整唯一键、索引顺序、InnoDB、字符集和可空身份都必须加入结构核验，而不只相信 dbDelta 的返回消息。

每次迁移的实施流程：

1. 核对真实 schema 版本、WordPress 原生表引擎、备份恢复能力和代码/数据基线；暂停会冲突的写 worker，等待租约内正在执行的事务完成。备份和测试只在对应开发阶段授权后进行，本轮未执行。
2. 沿用 GET_LOCK 与禁止断线自动重放的迁移作用域；只用可信定义创建缺失表/追加索引，执行 dbDelta 后验证实际结构。MySQL DDL 可能部分生效，不声称可整体事务回滚。
3. 数据回填单独有界、幂等、可恢复；使用 non-autoload 的阶段 checkpoint 记录游标，未完成不得开放依赖新状态的功能。原数据只补确定映射，未知验证/发布时间/归属保持未知。
4. 每级成功后才写 aish_schema_version；失败保留最后验证成功版本，功能开关保持关闭并显示通用诊断。重新请求只补未完成步骤，不能 DROP/TRUNCATE 重建当前表以“修复”。
5. 比较迁移前后原十四表的 ID/记录数/关键字段指纹，核对来源唯一键、双语关系、审核修订、人工正文/SEO、原始版本和 AI/采集历史未被覆盖。非事务 DDL 回退依赖已验证完整备份与匹配代码；不得降低版本号强迫旧代码运行。
6. 通过对应真实 MySQL/WordPress/并发/故障测试后再开放该模块；主题停用保留全部数据、取消相关 Cron，重启恢复可恢复任务并重新核验租约。多站点不能默认全网迁移，应逐站点有界执行与验收。

## 9. 后续实施验收要求与本轮状态

对应阶段应验证：从 1.5.0 逐级/跨级到目标版本、重复安装、DDL 中断/断连恢复、未知新版本拒绝降级；原 14 表保留；非默认前缀；原生用户/元数据缓存；注册重复邮箱和验证单次消费；收藏并发幂等；评价编辑/批准竞态、待审零计分和撤回立即失效；原生投稿锁/所有权/版本及 source 去重；认领转移唯一性；邮件租约、未知结果与重试；日聚合重复/缺口、搜索隐私阈值；删除/退订恢复备份后的抑制。

公开查询额外验证：中英同 Skill 不重复收藏/评分；缺译不泄露；归档/撤销审核立即离开排行榜/集合/作者主页/SEO；无真实行为时没有示例热度或五星；没有凭据时真实邮件/API/WPML 用“未执行”标记，不用 Mock 结果替代。

本轮只完成设计与源码/历史证据核对，**没有实施以上迁移，没有运行数据库或产品测试，没有进入 Phase 8**。精确结构是否符合真实运行环境、各迁移和并发协议是否通过，必须在后续获准开发时验证；当前规划的完整字典不能替代运行验收。
