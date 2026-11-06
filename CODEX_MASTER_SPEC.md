# AI Skills Hub — 项目总规格

## 当前实施增量：Phase 9（2026-11-06）

用户已验收 Phase 8 并授权用户互动系统。以 main `02d37d3`（包含指定基准 `40777e5` 及账户版安装包）继续；主题/Core **0.9.0**，schema **1.7.0**。在原 17 表上只追加 favorites、reviews、review_revisions、review_reports、skill_reports、workflow_events、metric_events、daily_metrics，共 **25 表**。原表定义、Skill ID、双语关联、来源去重、人工内容和匿名 Contact/Submit 保留。

CommunityModule 复用原生账户验证、Database、PublicCatalog、账户通知与隐私请求。收藏私有且用户/Skill 唯一；同一个 Skill 的两种语言共用一条评价主体，修改保存新修订，待审或拒绝不覆盖旧批准版，批准原子切换，撤回/隐藏立即不再公开计分。举报与纠错独立人工处理，不自动下架。独立社区权限、POST/Nonce、对象归属、事务/锁/CAS、幂等请求和失败回滚为必需合同；审核人不能冒充作者或批准自己的评价。

本阶段仅积累真实收藏变化、首次批准评价及公开内容批准事件，不建设榜单、运营分析、新投稿、开发者或支付。浏览/搜索统计未启用；后续不能把本阶段计数当历史浏览量。遵循既有白灰/深灰/陶土棕设计，原型不变。完整操作与边界见 [COMMUNITY_REVIEWS.md](COMMUNITY_REVIEWS.md)，结果见 [Phase 9 报告](docs/PHASE_9_REPORT.md)。通过必要检查、提交同步 main 后暂停，等待 Phase 10。下方旧阶段限制与 QA 均保留为历史记录。

## Phase 8 历史增量（2026-11-06）

用户已确认 Phase 7.5 初审并授权账户与个人中心开发；下文 Final QA 为 V1 历史规格和证据。Phase 8 的完整增量合同由 [PRODUCT_SPEC_V2.md](PRODUCT_SPEC_V2.md)、[数据库规划](docs/PRODUCT_DATABASE_DESIGN.md)及五份 Phase 7.5 设计规范共同定义，实际使用见 [ACCOUNT_SYSTEM.md](ACCOUNT_SYSTEM.md)。新主题/Core **0.8.0**、schema **1.6.0**；只增加邮箱验证、账户通知、邮件 outbox 三表，共 17 张 Core 表。原十四表、Skill ID、来源去重、双语关系、Contact/Submit 记录及人工审核门禁保持。

账户复用原生 WordPress Users/Subscriber、认证/会话/密码重置，增加独立 Accounts 模块、双语认证与个人中心、头像、安全设置和原生隐私申请。采用白/浅灰、深灰字及陶土棕 `#AD5E48`，不沿用下文历史 Phase 3 紫色/薄荷绿配色。原型不改。邮件必须具备专用服务器加密密钥和显式传输配置；Token 哈希存储、一次性确认、重发限流、失败恢复，传输受理不等于送达。没有授权开发 Phase 9–14。

本阶段结果独立记录于 `docs/PHASE_8_REPORT.md`，保留真实 WordPress/MySQL、Mock 传输、隔离检查和未执行外部服务的区别；历史 Final QA 不能替代新代码验证。完成代码、构建、报告及正常 main 同步后暂停等待 Phase 9。

版本：1.9（Final QA）；建立及修订日期：2026-11-05（Asia/Shanghai）。仓库：https://github.com/mtccv/Skills-ak。

## 1. 目标与执行约束

建立面向中文及英文读者的 AI Skills 发现、检索、阅读与来源导航网站。使用 WordPress 原生内容管理和原创主题；主题内置 AI Skills Hub Core，负责领域逻辑。GitHub 是来源，AI 是辅助编辑工具，编辑人员控制发布。

唯一开发环境为 Codex Cloud。不得引入宝塔、要求用户本机开发或部署外部服务器。Cloud 内的容器、数据库与 HTTP 预览只用于开发与验收，不构成生产部署。代码、规格和构建锁文件同步 GitHub；运行数据、密钥和依赖目录不入库。

用户已验收 Phase 0–7，当前正式授权 **Final QA：全站统一真实测试、问题修复及可安装交付**。基线为 GitHub main `d6401c2d6fb810605f5ba87524301ecc50418971`。完整范围包括 WordPress/PHP8.3/8.4/MySQL8.0、主题生命周期和升级、迁移/事务/并发、双语/WPML、浏览器/原生后台、SEO/第三方插件、采集/AI/Cron、安全与性能。先记录实际问题并分 P0–P3，再做必要修复与回归；不增加无关功能。

交付主题/Core 0.7.1，schema 仍为 1.5.0，不修改十四表设计、迁移序列或 Skill ID。保留双语关系、来源唯一键和 Phase 0–7 功能；AI 草稿采用不能覆盖人工字段或自动发布，Contact 与 Submit 保持私有待处理。Phase 7 两轮报告保留历史证据，不把历史 Mock 改写为真实集成。

本阶段尽可能在 Cloud 独立容器中执行真实测试，不连接或清空生产数据。只有已有合法凭据/授权包才能进行真实 GitHub/AI/WPML 测试；没有时明确未执行，不购买服务、不提取仓库 Git 认证冒充应用 Token。全部结果区分真实环境、真实后端+Mock外部服务、纯 Mock、静态及未执行。关键依赖未验收不得宣布完全通过。完成后更新根目录 FINAL_QA_REPORT / SECURITY_AUDIT / COMPATIBILITY_REPORT / PERFORMANCE_REPORT / KNOWN_LIMITATIONS、开发进度与变更记录，提供主题 ZIP 和安装说明，提交并同步 main；不自动部署外部服务器。

## 2. 技术基线

| 层 | 决策 |
| --- | --- |
| CMS | WordPress 官方当前稳定版本；主题声明最低 6.8，后续验收记录实际版本 |
| 运行时 | PHP 8.3 起（满足 PHP 8.x），验收目标 8.3/8.4；上线前复核支持周期 |
| 数据库 | MySQL / InnoDB / utf8mb4，统一 UTC；现有开发 Compose 默认 8.4 LTS，本次按用户要求用独立 MySQL 8.0 真测，不降级或复用原 8.4 数据卷 |
| 前台 | 原创 PHP WordPress 主题，语义 HTML、Tailwind CSS 3、Alpine.js 3 |
| 构建 | Node.js 22 或 24 LTS、npm lock、Tailwind CLI + esbuild；不依赖 CDN |
| 后台 | WordPress 原生菜单、列表表格、编辑器、Options API、角色与权限 |
| 开发 | Codex Cloud 内 Docker Compose：WordPress/PHP、MySQL、按需 WP-CLI |
| 多语言 | 内置简体中文 zh_CN 与英语 en_US；WPML 适配，不能重复拥有语言关系 |
| 集成 | WordPress HTTP API 请求 GitHub 与 AI 服务；供应商通过适配层替换 |

不修改 WordPress 核心，不依赖付费建站器，不建立独立管理 SPA。生产密钥、AI 模型、来源清单、品牌视觉及域名在相关阶段配置，不能凭空声称已有。

## 3. 范围与角色

- 访客：浏览、搜索、按分类/标签/语言筛选、切换语言、查看技能说明和 GitHub 来源。
- 编辑：维护内容、双语关系与元数据，审核采集和 AI 草稿，发布、下架、回滚修订。
- 管理员：编辑能力之外，管理来源、任务、供应商配置及迁移；使用专用 capability 校验。
- 系统：异步采集、生成草稿、重试、去重、缓存与审计；不能绕过人工发布。

首版不包括付费订阅、支付、社交社区、用户上传执行包、运行仓库代码或自动安装第三方 Skills。Phase 3 访客可提交私有建议，须由编辑独立录入及审核，不能绕过 Skills 发布门禁。

## 4. 代码架构

```text
CODEX_MASTER_SPEC.md
compose.yaml / .env.example
scripts/                     # 环境检查与验证
docs/                        # 阶段报告及开发说明
wp-content/themes/ai-skills-hub/
  style.css / functions.php  # 主题元信息与启动
  header.php / footer.php / index.php
  inc/core/bootstrap.php     # 内置 Core 唯一入口
  assets/src/                # Tailwind / Alpine 源码
  assets/dist/               # 构建结果，由 npm build 生成
  languages/                 # gettext 翻译目录
```

当前 Core 显式模块为 database、language、catalog、admin、frontend、sources、ai，PHP 命名空间 `AISkillsHub\Core`。Sources 保留 GitHub 发现、解析、静态检查及采集任务；AI 独立包含 Provider/Settings、Prompt/Output、来源上下文、GenerationStore/Service、Worker/Scheduler 及原生 Admin。ModuleLoader 校验依赖图后注册，各模块复用同一个 DAL/Migrator，不把 AI 混入 GitHub worker 或公开模板。主题函数使用 `aish_` 前缀，选项/元字段/表名使用 `aish_`，WordPress 数据表前缀从 `$wpdb->prefix` 获取；模板不直接请求外部网络。

Core 随主题加载，切换主题时清除采集与 AI Cron 事件，但保留内容、任务和日志；不得自动删除表、选项或文章。文档说明此耦合，未来独立插件拆分须经用户确认。升级执行版本化、可重复的迁移，不在每个前台请求执行 DDL。仓库定期同步默认关闭，AI 队列默认暂停，均需授权后台明确配置和排队；Final QA 未提供真实服务凭据时使用 Mock Provider，并单列真实 API 未执行。

## 5. 数据库与内容模型（保留八张核心表，采集与 AI 运营数据独立）

依据用户本轮要求，Core 自定义表为唯一主存储。WordPress 提供用户、权限、选项、主题生命周期与原生后台界面；不注册影子 Skills CPT，不以 post ID 或语言文章 ID 代替内部 Skill ID。Phase 0 未创建业务表，无旧 CPT 数据需要转换。

### 5.1 八张核心表

表名均加 `$wpdb->prefix`，支持 1–40 个 ASCII 字母/数字/下划线的站点前缀。InnoDB、utf8mb4，UTC datetime。实体主键为 bigint unsigned AUTO_INCREMENT，PHP API 接受正整数（受 PHP_INT_MAX 限制）；内部 ID 不因标题、语言、slug 或版本变更而更换。

| 表（加 WP 前缀） | 字段与约束 | 关联 |
| --- | --- | --- |
| aish_repositories | id, owner varchar(39), name varchar(100), is_archived, created_at；1.4.0 增加 github_id、default_branch、sync_enabled、sync_interval、last_sync_at、last_sync_commit、last_scan_at、sync_status、license_spdx；UNIQUE(owner,name)，UNIQUE(github_id)，owner/name 归一化小写 | 一个仓库有多个 Skills；GitHub 数字身份不能取代内部 id |
| aish_skills | id, repository_id nullable, source_path VARBINARY(768) nullable, status, revision, review_status, risk_level, reviewer_id, reviewed_at, reviewed_revision, review_notes, created_at, updated_at；UNIQUE(repository_id,source_path)，INDEX(status,created_at)，INDEX(status,review_status,updated_at,id) | 稳定的语言无关 Skill 实体 |
| aish_skill_translations | id, skill_id, locale varchar(10), title, slug nullable, summary, content, seo_title, seo_description, created_at, updated_at；UNIQUE(skill_id,locale)，UNIQUE(locale,slug)，INDEX(locale,title,skill_id) | zh_CN/en_US 共用同一 skill_id；缺译返回 null |
| aish_skill_versions | id, skill_id, commit_sha varchar(64), content_hash char(64), source_content mediumtext, version_label, created_at；1.4.0 增加 file_sha varchar(64)、repository_id nullable、source_path VARBINARY(768) nullable；UNIQUE(skill_id,commit_sha) | 每个 Skill 的不可变来源快照，同提交可包含多个 Skills；历史快照不虚构来源绑定 |
| aish_categories | id, name, slug UNIQUE, parent_id nullable, is_archived, created_at；INDEX(parent_id) | 层级分类，共用分类名称；名称翻译留后续 |
| aish_tags | id, name, slug UNIQUE, is_archived, created_at | 非层级标签 |
| aish_skill_categories | PRIMARY KEY(skill_id,category_id)，INDEX(category_id) | Skills 与分类多对多 |
| aish_skill_tags | PRIMARY KEY(skill_id,tag_id)，INDEX(tag_id) | Skills 与标签多对多 |

`source_path` 是仓库内规范的相对文件路径（例如 `tools/review/SKILL.md`），最大 768 UTF-8 字节；拒绝绝对路径、空/点/父路径段、反斜杠和控制字符，不自动解码 URL、不改大小写。VARBINARY 和完整联合唯一索引避免 MySQL 字符排序及索引截断造成错误合并。单仓库不同路径对应不同 Skill；同一路径在不同仓库独立存在。

正文最多 512 KiB，经 `wp_kses_post` 存储；来源快照为受限 UTF-8 原文，仅以纯文本方式输出，不执行 Markdown/脚本/指令。版本哈希由 Core 自行计算 SHA-256，同一 skill_id+commit_sha 的不同内容拒绝写入，不能覆盖旧版本。slug 采用最长 191 字节的规范 ASCII 小写形式，中英文允许同 slug（按 locale 隔离）。

不建立数据库外键或级联删除；Catalog、Management、ImportStore 和 GenerationStore 通过事务与行锁校验关联对象，数据库唯一键兜底并发去重。DAL 属于可信内部代码，不是授权边界；人工写入经过受权限保护的服务，采集/生成后台写入还要求可信后台上下文及活动任务租约。GenerationStore 不提供自动发布入口，人工采用必须同时校验来源基线、Skill 修订与现存译文。没有硬删除 API 或卸载清理钩子，主题升级、停用、切换均保留数据。人工 SQL 必须自行维护关联完整性，禁止将内部 DAL 直接暴露到 HTTP。

### 5.2 初始化与增量升级

`aish_schema_version` 为每站点 schema 版本，autoload 关闭。迁移 1.0.0 建立基础八表，1.1.0 为版本快照添加 `version_label`；Phase 2 加入 1.2.0 扩展现有八表；Phase 4 的 1.3.0 只添加 `skills.public_updated(status,review_status,updated_at,id)` 和 `skill_translations.locale_title(locale,title,skill_id)` 两个非唯一完整索引。Phase 5 的 1.4.0 追加第 5.4 节的字段、唯一键及三张采集运营表；Phase 6 的 1.5.0 只新增第 5.5 节的三张 AI 运营表，不改原业务字段、ID、译文、索引或来源设计。1.0.0 同时用于旧结构升级测试，并不表示已有线上历史数据。

迁移使用 `dbDelta`、MySQL GET_LOCK/RELEASE_LOCK、实际列/完整唯一索引/InnoDB/utf8mb4 验证；只有验证通过才能更新版本。拒绝未知版本与降级，失败不虚报版本，并支持排除故障后重复执行。MySQL DDL 不是原子事务，失败可能留下部分表；各迁移保持可重复、只增不删，升级前仍须备份，不能宣称 DDL 自动回滚。

仅在授权管理员后台请求、主题切换后或可信 WP-CLI 请求进行自动升级；普通前台请求不运行 DDL。当前版本时仅比较选项，不重复建表；数据库未就绪或版本不受支持时 Catalog 拒绝操作。后台只显示通用错误，不打印 SQL 或凭据。多站点按当前站点前缀/选项分别迁移，不做全网络自动遍历。

### 5.3 Phase 2 必要扩展与数据保留

Phase 1 缺少人工来源、审核与 SEO 字段，不能完成本轮明确要求；实现前已向用户说明增量调整理由。1.2.0 在原表内添加字段，保留 ID、来源唯一键、快照和关联：

- 人工录入时 repository_id/source_path 同时为 NULL；有来源时两者必须完整且校验真实仓库。保留 UNIQUE(repository_id,source_path)，允许多个独立人工记录。
- revision 为编辑修订号；review_status 为 unreviewed/pending/approved/rejected；risk_level 为 unknown/low/medium/high。reviewer_id、reviewed_at、reviewed_revision、review_notes 保存最近一次人工审核。此结构不是完整审核事件历史；事件日志留后续阶段。
- 翻译增加 seo_title/seo_description；未填写的草稿 slug 存 NULL，保留 UNIQUE(locale,slug)，不生成虚构译文或占位 slug。
- repositories/categories/tags 增加 is_archived，归档不删除记录或已有关系。
- 历史 published 且无当前修订审核证据的记录转 pending；原文、ID、关联均保留。内容、来源或 Skill 的分类/标签关联发生修改时使旧审核失效，防止旧批准覆盖新内容。
- 编辑请求必须提交 revision，服务在事务中锁定行并比较修订号，过期编辑拒绝覆盖。审核批准必须针对当前待审修订，且至少一种语言的标题、slug、简介、正文完整；另一种语言可以缺失，后台明确标记。

### 5.4 Phase 5 必要增量与运营表（schema 1.4.0）

原八表没有 GitHub 不变数字身份、文件 SHA、快照来源绑定、可恢复队列或采集结果状态，无法可靠判断同名仓库替换、增量内容变化和过期任务写入。因此使用已有 Migrator 追加以下结构，不改变 Skill 主键、来源唯一键、翻译关系或人工审核模型，不把任务/风险 JSON 混入公开译文。

- repositories：`github_id` 为 nullable unsigned bigint，新增 UNIQUE `github_identity(github_id)`；`default_branch` varchar(255)、`sync_enabled` 默认 0、`sync_interval` unsigned int 默认 86400 秒、`last_sync_at` nullable datetime、`last_sync_commit` varchar(64)、`last_scan_at` nullable datetime、`sync_status` varchar(20) 默认 idle、`license_spdx` varchar(100)。首次可靠 API 响应后绑定 GitHub ID，后续身份变化拒绝同步；不是新建第二套仓库。license_spdx 保存最后完整成功的许可证基线，与 last_sync_commit/last_sync_at 在成功收尾时原子推进，不在获取仓库信息时提前覆盖。
- skill_versions：`file_sha` varchar(64) 默认空，`repository_id` 与 `source_path` 允许 NULL，保存新采集快照的来源绑定。旧记录保留未知值，不根据当前来源回填历史；仍 UNIQUE(skill_id,commit_sha)，不覆盖已有原文。

| 新表（加 WP 前缀） | 实际字段与约束 | 用途 |
| --- | --- | --- |
| aish_imports | id、skill_id、repository_id、source_path VARBINARY(768)、file_sha、commit_sha、source_status、change_status、metadata_json、findings_json、last_seen_job nullable、created_at、updated_at；UNIQUE(skill_id)、UNIQUE(repository_id,source_path)、INDEX(repository_id,source_status) | 当前收到的来源元数据、风险证据及待处理变更；引用原 Skill，不存另一套译文 |
| aish_jobs | id、repository_id、kind、status、active_key nullable、state_json、attempts、available_at、lease_token、locked_until nullable、deadline_at、created_at、updated_at、completed_at nullable、last_error；UNIQUE(active_key)、INDEX(status,available_at)、INDEX(status,locked_until) | 持久化阶段、游标、退避和租约；active_key=repository_id 保证同仓库最多一个活动任务，终态置 NULL |
| aish_import_logs | id、job_id、repository_id、skill_id nullable、source_path VARBINARY(768) nullable、level、code、message、created_at；INDEX(job_id,id) | 固定错误码/简短脱敏事件与来源定位；每任务最多 1000 条，重复事件不重复写入 |

imports 的 `source_status` 为 present/missing，`change_status` 区分 new/updated/inspection_updated/missing/restored/handled；jobs 为 queued/running/retry/complete/incomplete/failed/cancelled。source_status 不等于公开审核状态；handled 只表示人工处理来源提示，不发布 Skill。业务写入统一走现有 DAL 的白名单和 prepared SQL。

少量配置复用 WordPress options；任务状态和日志使用运营表，任务 JSON 上限 4 MiB，不存凭据。1.4.0 沿用锁、dbDelta 和物理列/索引验证，历史版本验证排除尚未引入的字段和表；只在成功后更新版本。升级和恢复须备份，真实 MySQL 迁移、重复执行与并发验证留 Final QA。AI 配置、生成记录和完整审核事件历史不在 Phase 5 实现。

### 5.5 Phase 6 必要增量（schema 1.5.0）

原业务表和采集任务只保存来源及当前人工内容，无法独立保留 AI 原始结果、人工修改、逐次网络用量和不确定请求状态。新增三张运营表，使来源、AI 草稿和已审核内容分离，避免把生成任务塞进 Phase 5 仓库 worker 或覆盖人工译文。

| 新表（加 WP 前缀） | 数据所有权与约束 |
| --- | --- |
| aish_ai_jobs | 关联原 skill_id（连接测试可空），冻结来源、Skill/译文基线、Provider/模型档案及 Prompt Version；保存任务状态、来源 SHA、租约/重试、不可变 AI 输出、独立编辑草稿、审稿修订与状态；请求键防同表单重复入队，活动键防同 Skill 并行生成 |
| aish_ai_attempts | 每次外部请求之前写入的尝试记录，关联 job、请求标识、状态、输入/输出 Token、耗时、估算费用与固定错误码；未确认请求不能伪装成未发送或成功 |
| aish_ai_reviews | 人工保存、采用或拒绝的追加记录，关联 job、操作者、时间、草稿快照及所选字段，保留 AI 输出与人工修改的来源区别 |

当前共 14 张 Core 管理表。Provider 档案和全局暂停设置使用非 autoload 的 WordPress options；API Key 只使用服务器常量/环境变量引用，不进入上述表、配置 JSON 或日志。AI 表引用既有 Skill ID，不建立译文副本作为公开主存储。1.5.0 继续按既有顺序迁移和物理验证，历史版本验证不提前要求新表，主题停用不删除记录。具体字段以 Schema.php 和 CORE.md 为准；真实 MySQL 升级、重复升级及并发留 Final QA。

## 6. Phase 3 双语前台

采用原创 SaaS 风格设计系统：浅色背景、深色正文、紫色主色与薄荷色辅助色，几何 Logo；统一导航、按钮、卡片、表单、标签、面包屑和阅读排版。PHP SSR + WordPress 原生模板 + Tailwind CSS + Alpine.js，无页面构建器、远程字体或 CDN 运行依赖。360px 至桌面响应式，移动导航用原生 details 保留无 JS 可用性，搜索及表单使用原生 HTTP，Alpine 仅增强加载反馈。

| 页面 | 路径及数据 |
| --- | --- |
| 首页 | /zh/、/en/；Hero/搜索、真实精选/最新 Skills、按公开数量排列分类、真实平台标签、已发布教程、投稿入口 |
| Skills 列表 | /{lang}/skills/；关键词、分类/标签/平台、最新/最早/名称排序、每页 12 项和空状态 |
| Skill 详情 | /{lang}/skills/{稳定ID}/{语言slug}/；公开投影、真实译文、来源及审核信息 |
| 分类、标签 | /{lang}/category/{slug}/、/{lang}/tag/{slug}/；既有分类/标签及公开资源计数 |
| 教程列表、详情 | /{lang}/tutorials/、/{lang}/tutorials/{WP文章ID}/；aish_tutorial 原生文章及 postmeta |
| 搜索 | /{lang}/search/?q=...；公开 Skills 分页与匹配教程入口 |
| About、Contact、Submit | /{lang}/about/、contact/、submit/；原生页面内容、真实私有表单流程 |
| 404 | 不存在/未公开内容返回 404，不泄露记录存在性；服务故障返回 503 通用提示 |

所有资源列表与统计来自真实数据库；没有种子示例 Skills、虚构统计、评价或兼容性保证。为空时显示真实空状态。About/Contact/Submit 未配置编辑正文时，仅显示站点功能说明与表单引导，不伪造企业资料。

### 6.1 公开边界与不改表的数据映射

PublicCatalog 在 DAL 上做只读投影，所有入口（详情、搜索、分类计数、相关资源及语言备选）同时要求 published、approved、reviewed_revision=revision、有效审核人员/时间与该语言完整内容。不能通过公共 URL、分类数、语言切换或搜索泄露草稿/待审/拒绝/归档记录。原始快照、内部审核备注及审核人员身份不出现在公开 payload。公共 HTML 暂时禁用共享缓存，避免归档后继续命中旧页面；性能缓存策略留后续。

Skill 名称、简介、正文与 SEO 使用现有翻译表；仓库和路径使用既有来源；作者无独立字段时显示未提供，不冒充仓库维护者。平台使用现有 tag.slug 的 platform-*，许可证使用 license-*，精选使用 featured。这些是人工管理的标签，不代表平台实测或法律保证。分类/标签显示实际存储名称，允许编辑维护双语名称，不自动虚构译文。

使用场景、安装说明、依赖要求可从现有译文正文的 H2 标题提取；未提供时明确显示未知，完整正文仍可阅读。版本仅显示审核时间之前记录的历史 SHA 元数据；1.4.0 新快照保存来源绑定，但仍没有 SHA 对人工审核修订的绑定，历史快照的来源也可能未知，不能声称该 SHA 已被审核或是当前来源，GitHub 文件链接继续使用 HEAD。普通前台请求不采集外部仓库；后台新增的来源原文、风险和 License 提示不自动成为公开内容。

### 6.2 教程、说明页面和私有表单

教程使用 WordPress 原生 wp_posts/postmeta 中的 aish_tutorial，编辑语言 zh_CN/en_US、双向对应教程 ID、相关 Skill ID；不是 Skills 影子 CPT。仅发布、无密码且语言明确的文章公开。CPT 关闭默认公开查询、REST 和搜索出口，由统一路由呈现。

About/Contact/Submit 使用原生 page + 页面用途/语言元数据。Contact/投稿写入原生 aish_message 私有 CPT，只有授权管理员可查看/处理；记录来源链接和联系信息，不发邮件、不抓取 URL、不创建 Skill。POST + nonce + 字段长度/邮件/HTTPS GitHub URL 校验、蜜罐、数据库锁保护的限流；只保存地址哈希，每地址每小时 5 次，全站每小时 120 次。保存成功和必要元数据持久化后才返回成功状态。

### 6.3 前台路由与基础结构

Phase 3 已建立每页标题、Meta Description、Canonical、真实可用翻译的 hreflang、语义 H1/H2 与面包屑。Phase 4 在同一路由上统一 SEO 输出和索引策略，见以下各节。已编辑正文 H1 在展示时降为 H2，保持页面单一 H1；语言切换保留筛选并回到第一页，不虚构跨语言分页对应关系。

首次管理员后台访问初始化前台重写规则；若当前是普通固定链接，仅此时设置 /%postname%/，已有自定义固定链接设置保留。前台请求不刷新规则、不做 DDL。Apache/Nginx 仍需把页面请求交给 WordPress front controller；Cloud 使用既有 WordPress Apache 容器。WPML 可通过关闭内置路由及请求/URL/alternate filters 接管，真实插件联调留 Final QA。详情见 docs/FRONTEND.md。

### 6.4 Phase 4 搜索与查询优化

Skills 搜索当前语言的标题、简介和正文；教程搜索当前语言的标题、摘要和正文。关键词为转义后的字面子串，支持中文、英文及混合输入；`%`、`_` 不作为用户通配符，不使用自动翻译、停用词或负号语法。使用现有 utf8mb4 排序规则，搜索最多 100 字符/400 UTF-8 字节。

Skills 和教程支持关键词、分类、标签、平台组合筛选、newest/oldest/title 排序、分页与真实空状态。教程的分类/标签/平台来自 `_aish_related_skill`：至少同一个当前语言可公开 Skill 满足整组筛选，不能由多个不相干 Skills 分别凑齐条件。关键词匹配教程自身，不要求关联 Skill 命中该关键词。没有关联 Skill 的公开教程仍可在无分类/标签/平台条件的查询中出现。

结果只允许当前语言的公开、完整内容；Skills 必须审核对应当前修订。教程为明确语言、publish、无密码且标题/正文存在实际文本。排序使用 ID 作为同值稳定次序。URL 保留合法 q/category/tag/platform/sort/page，分页保持筛选，语言切换回到第一页。

PublicCatalog 使用请求内结果/计数/语言/分类缓存，计数不关联无用仓库数据；教程关联 Skill 卡片批量查询，卡片分类与标签以集合加载。Editorial 批量预取文章元信息与作者，关闭原生 WP_Query 的持久查询结果缓存，避免 Core 自定义表归档未更新 WordPress posts 缓存代际而复用旧匹配结果。缓存不跨请求保存公开快照。

1.3.0 索引用于公开时间排序和语言标题排序的访问路径；它们不能消除 `%关键词%` 或完整性 REGEXP 的候选行扫描。不宣称已经获得真实 MySQL EXPLAIN、并发或负载性能证据。大规模查询基准与进一步索引选择在 Final QA 验证，不未经测量引入全文索引或另一套搜索存储。

### 6.5 Phase 4 SEO 配置与多语言

首页、Skills 列表、Skill 详情、分类、标签、教程列表/详情、About、Contact 分别支持 SEO Title、Meta Description、自引用 Canonical、Open Graph、Twitter Card；中文和英文独立管理。Skill 标题/描述使用现有已审核翻译行的 seo_title/seo_description，缺省回退到该行真实标题/简介；不能通过另一套设置绕过内容审核。

原生后台增加 SEO 子菜单，按语言/页面类型/实体 ID 保存到非 autoload 的 `aish_seo_settings`；最多 1000 条非默认覆盖设置。教程和说明页使用原生编辑器 SEO 面板及 `_aish_seo_*` 元字段。支持可选 HTTPS 分享图片与 noindex；没有图片时输出 summary Twitter Card，不捏造图片。修改使用 capability、nonce、输入验证及转义；options 保存使用锁与版本指纹防过期覆盖。Canonical 根据真实路由计算，不提供随意跨语言填写入口。

仅为实际公开且可索引的译文输出 hreflang；Skill 仍以同一内部 ID 找译文，教程要求双向对应关系。About/Contact 需要真实的当前语言说明页，分类/标签需要当前语言有公开资源，才进入语言替代索引关系。x-default 优先使用实际可用的默认语言，没有默认语言译文时指向现有可用语言，绝不指向不存在的译文。分页没有未经验证的 hreflang。

Hub SSR 路由由主题统一拥有 Meta 输出：通过 Yoast 的公开 presenter filter、Rank Math 的 frontend head action 和 WPML hreflang filter 避免重复；非 Hub 路由保留插件行为。`aish_frontend_seo_enabled` 可将标签输出交给适配器；安全 noindex HTTP 标记仍独立生效。WPML 教程关系使用公开 `wpml_object_id` API（缺译不返回原文），并继续验证站点本身的语言、发布和双向关系；URL/请求适配保留既有 filters。Final QA 已用实际 Yoast 28.8 / Rank Math 1.0.281 验证四种插件模式；WPML 缺合法安装包仍未验证，不能把 hooks 存在等同 WPML 运行验收。

### 6.6 Phase 4 Sitemap 与索引策略

`/aish-sitemap.xml` 提供 XML 索引，按类型 skills/category/tag/tutorials/pages、语言 zh/en、每文件 200 URL 分页。Skill/教程仅收录当前语言真实公开且允许索引的条目；分类/标签必须未归档并有当前语言公开 Skills。静态页包括首页、Skills/教程列表及有真实语言正文的 About/Contact。不收录投稿页、搜索、筛选组合、排序、分页 URL、草稿、待审、拒绝、归档、缺译、noindex 内容。

Sitemap 与公开查询共用门禁；noindex 排除在计数与 LIMIT 之前执行，避免错误页数。实体 lastmod 使用已有 UTC 更新时间；分类/标签取关联公开内容的真实最大时间；没有真实更新日期的生成集合页不伪造 lastmod。无结果/越界 XML 子文件返回 404；数据库失败返回通用 503，不能输出部分 XML 并报成功。

WordPress 原生 Sitemap provider、Yoast 和 Rank Math 的官方索引 hooks 引用同一组 Hub XML 文件，避免再创建另一套 URL 集；已有 Hub 用途的 WordPress 页面从原生/插件文章 Sitemap 排除其重复原生地址。识别到 Yoast/Rank Math 时选择一个索引接入方；其他 Sitemap 插件可通过 `aish_sitemaps_enabled` 禁用主题源并接管。第三方索引缓存、启停组合与撤稿即时生效需真实插件 QA。

搜索、含筛选/排序/未知参数的 URL、表单结果、投稿页、缺失说明页、空分类/标签、404/503 均 noindex；正常集合的合法纯 page 分页可自引用 Canonical，但不进入 Sitemap。私有后台/登录/预览使用 noindex；公开权限校验不能用 robots 代替。站点关闭“允许搜索引擎索引”时，主题 Sitemap 停用并保持 noindex。

使用 WordPress 虚拟 robots.txt，保留其后台规则及已有插件 Sitemap 指令；公开站点且缺少 Sitemap 指令时补充 Hub 索引。不得 Disallow 搜索/筛选 URL 而妨碍爬虫读取页面 noindex；私有站点输出 Disallow: /。不在仓库写入会覆盖 WordPress 动态行为的静态 robots.txt；实际 Web 服务的物理文件覆盖情况留 Final QA 核查。

## 7. WordPress 原生后台

使用 WordPress 原生菜单、WP_List_Table、wp_editor 和 admin-post 控制器管理 Core 记录；不创建影子文章，不引入页面构建器或独立 SPA。

基础菜单为 AI Skills Hub → Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings；Phase 3 增加 Tutorials 与 Inbox，Phase 4 增加 SEO，Phase 5 增加 GitHub Imports，Phase 6 增加 AI Settings 与 AI Generation。

- Dashboard 显示总数、待审核、各状态以及分类、标签、仓库统计。
- Skills 支持创建、读取、修改和归档；保留 draft/pending/published/rejected/archived 五种状态；支持人工来源和同仓库多个 Skills。归档代替永久删除，必须在服务器返回的确认页二次提交。
- 中文/英文在同一编辑表单内切换，分别维护标题、slug、简介、正文、SEO 标题和描述。显示缺失/未完整/完整状态；不得因缺译自动填充另一种语言。
- 列表支持搜索、状态/风险/仓库/分类/标签筛选、白名单排序和分页；批量操作设数量上限，逐项能力及修订检查，不能批量绕过审核直接发布。
- 分类支持层级及循环校验；标签为平面结构；仓库沿用手动 owner/name 管理，GitHub Imports 追加规范 URL、同步开关/间隔及任务操作。已关联 Skills、GitHub ID 或任务的仓库不允许直接改身份。归档分类、标签和仓库保留原关联；归档仓库停用采集并取消其活动任务。
- 人工审核支持 pending、approved、rejected，记录人员、UTC 时间、修订、Unknown/Low/Medium/High 风险和备注。只有审批当前修订才可发布；编辑内容需要重新审核。拒绝保留原记录。
- Skill 阅读预览为登录且拥有 Skills 管理或审核能力、有效 nonce 的私有页面，标记 no-store/noindex；未审核内容不提供公开地址。公开站点页面由 Phase 3 实现。
- Settings 配置默认编辑语言和列表每页数量；SEO 子菜单及原生文章 SEO 面板承载 Phase 4 配置。GitHub Imports 展示来源与采集结果，手动批量最多 10 个仓库。AI Settings 管理 Provider/模型档案、参数、价格、连接测试及全局暂停；AI Generation 管理单个/批量/指定语言或字段生成、任务、用量及审稿。两种自动化的页面只配置/排队，不直接做长网络请求；凭据仅显示配置状态和固定掩码。
- 原能力 aish_manage_skills、aish_review_skills、aish_manage_taxonomies、aish_manage_repositories、aish_manage_settings 保留；AI 增加 aish_manage_ai_settings 与 aish_generate_content。默认只赋予管理员，manage_options 兼容既有管理员。AI 草稿采用另须 Skills 管理和审核能力；编辑及其他角色不自动获得能力。页面、控制器和服务各自校验相关 capability，nonce 不能代替能力。

归档及批量操作使用 POST + nonce + 权限 + 二次确认；输入验证、prepared SQL、输出上下文转义和 KSES 沿用 Core。服务抛出可控错误，HTTP 层不显示数据库详情。设置保存在 WordPress options，未引入额外业务表。

## 8. 内置双语与 WPML

- 界面字符串统一 gettext，text domain `ai-skills-hub`，提供中文与英文目录；内容翻译与界面翻译分开处理。
- 内置内容以同一个 skill_id 下的 zh_CN/en_US 翻译行关联；Phase 1 只实现存取与语言归一化，不产生翻译或路由。Phase 2 提供后台默认编辑语言及编辑切换；Phase 3 实现公开 `/zh/`、`/en/` 及完整前台双语界面词典。
- `LanguageProvider` 接口提供支持语言、语言归一化和可选外部对象 ID；通过 `aish_core_language_provider` 注入，适配器必须实现该接口。WPML 使用官方 hooks/API 和配置，Phase 4 教程已接入公开 wpml_object_id 关系查找；外部对象 ID 不得取代稳定 Skill ID，也不得重复拥有 Core 的翻译关系。WPML 接管 URL 时内置 rewrite 退出；Phase 1 不启用真实 WPML 集成。
- 两种模式必须分别测试语言切换、列表/搜索过滤、分页、canonical/hreflang、分类、缓存、后台编辑。模式迁移要有 dry-run、冲突清单、备份和回退，不能自动覆盖翻译。
- WPML 为可选商业依赖，不随仓库分发；未提供合法插件时以适配合同测试覆盖，必须把真实联调标为待完成。

## 9. Phase 5 GitHub Skills 采集

### 9.1 API、身份与资源上限

仅接受管理员配置的 `https://github.com/owner/repository` 根地址（可带 `.git`），拒绝凭据、端口、查询参数、片段及任意下载地址。统一 GitHubClient 用 `wp_safe_remote_get` 请求固定 `https://api.github.com` REST 路径；TLS 验证、拒绝不安全目标、禁止重定向和压缩响应、每请求 15 秒超时。仓库/提交响应上限 128 KiB，Tree 响应 4 MiB，Blob JSON 响应 768 KiB、解码文件 512 KiB。只读公开且非 disabled 仓库，Token 不能使私有仓库进入采集范围。

可选 Token 来自服务器 `AISH_GITHUB_TOKEN` 常量或同名环境变量，不写入任务/日志/前台，不入 Git。认证失败、权限拒绝、身份变化等终止本次扫描；429、主/次限流、网络及 5xx 由有界任务重试。共享限流状态保存 remaining/reset/cooldown，遵守 Retry-After/重置时间和指数退避，不在请求内 sleep。GitHub 数字 ID 初次绑定后不可替换；跨 Cron 步骤取 Commit/Tree/Blob 前重新验证公开身份，避免同名仓库重建混用来源。固定 Commit → Tree SHA → Blob SHA，校验响应 SHA、长度及 Git blob 哈希，不跟随响应中的 URL。增量依据 SHA，不宣称实现 ETag 缓存。

### 9.2 完整发现与安全解析

精确查找大小写为 `SKILL.md` 的普通 blob（100644/100755），支持根目录和同仓库多个目录。树路径使用既有相对路径校验，保留大小写；树/符号链接/子模块类型与 mode 对应校验，符号链接和子模块不递归。Discovery 保存纯 JSON 状态：树最多 10000 条、Skill 候选最多 500 个、路径最多 32 段、树请求最多 1000 次、Discovery 状态最多 2 MiB；超过上限标记不完整，不能悄悄截断成完整结果。

递归 Tree 返回 truncated 或响应超过大小限制时，丢弃不完整的清单，记录诊断并以固定 root SHA 逐层读取非递归 Tree。子树队列和祖先 SHA 持久化，相同 SHA 在不同目录允许分别遍历，祖先环路拒绝。非递归 Tree 仍截断、超限、异常路径或目录层级不一致时失败并保留不完整状态。仅在完整树、所有文件处理完成且零文件错误后，允许对比缺失来源；解析失败、读取失败或超大 Skill 不得触发删除推断。

Frontmatter 支持受限 YAML 字符串、单双引号、文字/折叠块及扁平 metadata 字符串映射；允许 name、description、license、compatibility、metadata、allowed-tools，不实现通用 YAML。name/description 必填并校验类型/长度；嵌套 Skill 的 name 须匹配目录名，根 SKILL.md 留目录未知提示。头部上限 16 KiB/256 行、metadata 最多 32 项；拒绝重复键、非字符串隐式值、未知字段、标签/锚点/别名/合并键和不支持的复杂结构。失败记录固定诊断后继续其他候选，不执行 YAML 或 Markdown 中的任何指令。

### 9.3 去重、增量与人工审核

来源唯一性始终是内部 `repository_id + source_path`，绝不按 Skill name 合并。ImportStore 使用事务、行锁、数据库唯一键及任务租约防并发冲突。新发现的合法来源只创建 `pending/review_status=pending` Skill 和私有来源快照；不自动写入中文或英文翻译，不设置公开 slug，不自动发布。管理员从来源原文补充真实双语内容，并走原有 Reviews 的当前修订审核。

文件变化追加不可变版本，记录 File SHA、Commit SHA、快照绑定、SHA-256 和风险结果。已有 Skill 的 ID、slug、人工译文、审核及公开内容不由采集覆盖；新原文只是后台待处理变更。增量 sync 仅在同仓库最近前序任务为 complete、当前 Commit 等于 last_sync_commit、且许可证相对最后成功基线未变化时，才通过 baselineReusable 跳过重复扫描。若最近前序任务失败、不完整或取消，即使上游回退到原成功 SHA，也必须重新检查，避免未完成任务的部分写入被误当成完整基线。Commit 变化但某文件 SHA 未变时复用已核验快照，仍针对新树与许可证重新静态检查，风险/依赖变化标为 inspection_updated，不重复存原文版本。完整 scan 重新读取候选内容。

仅完整成功的 finish 才原子保存 last_sync_at/last_sync_commit/license_spdx；仓库元数据获取或失败/取消任务均不能推进该基线，last_scan_at 是排队时间，不表示完成。完整清单确认旧路径不存在时标记 missing，恢复标记 restored，不删除或自动归档旧 Skill/已发布页面。新路径与缺失旧路径具有相同文件 SHA 时记录 possible_move，由人工核对；不猜测移动、迁移 ID 或修改已发布 slug。“来源变更已处理”校验来源版本指纹和审核权限，只清理提示，不等于通过内容审核。

### 9.4 调度与恢复

WordPress Cron 的 `aish_import_tick` 使用 60 秒周期；管理员初始化注册，普通前台只允许 WordPress 常规触发 Cron，不在页面处理栈执行扫描。任务阶段为 repository → commit → tree → files → finalize，一次 Cron 最多执行一个可恢复步骤，一个文件一步；持久化检查点后下一步至少延迟 60 秒。执行网络操作的步骤最多两个 HTTP 请求（仓库身份校验加固定对象读取），每个独立 15 秒超时。

Scheduler 使用数据库/站点级 GET_LOCK 保证单 worker，同时每轮最多检查 20 个到期仓库；ImportStore 的活动任务唯一键、claim 锁、120 秒租约与 fencing token 防止重复任务和过期写入。单仓库新任务间隔至少 60 秒，定期间隔范围 3600–604800 秒，默认 86400 秒且默认关闭。后台手动批量最多 10 个仓库，不并行扫描。成功检查点重置连续失败计数；同一步达到 5 次失败或任务超过 48 小时截止即终止，不永久占用队列。

运行状态明确区分 queued/running/retry/complete/incomplete/failed/cancelled；断点保存失败不能报成功，租约到期后可恢复。租约回收、幂等来源写入和完整清单核对需真实 MySQL 并发测试；WordPress Cron 依赖站点请求，60 秒是计划间隔，不承诺准点。无需假设 Action Scheduler 已安装。切换主题仅清理调度事件，不删除任何任务、来源或版本。

### 9.5 静态风险与安全边界

只分析 SKILL.md 文本和对应目录树元数据，记录许可证声明/仓库 SPDX、依赖声明/清单文件、脚本、网络地址、特权/破坏性命令、可疑命令执行、密钥读取/提示注入、路径/符号链接、超大文件及异常目录提示。未知许可证标记待核实；原文仅作为后台私有证据保存，公开使用须编辑确认。脚本本身和依赖内容未执行、未安装，也不声称已做全面源码审计或绝对安全认证。

静态结果保存在 imports，不自动替换人工 risk_level 或绕过审核。来源文本不能读取系统密钥、改变系统指令、触发管理接口、扩展网络域名或授权执行。后台所有写入要求 POST、nonce、capability；归档二次确认，原文按文本转义。日志使用固定码和安全提示，不保存 HTTP Authorization、响应原文或 SQL。操作说明见 `docs/GITHUB_IMPORT.md`，安全说明见 `docs/IMPORT_SECURITY.md`。

## 10. Phase 6 AI 智能内容生成

### 10.1 Provider 与配置

独立 Provider 接口接受冻结模型档案、结构化消息及请求标识，返回内容、Token 使用量和耗时；ProviderRegistry 允许可信服务器代码注入实现，默认支持 OpenAI 与 OpenAI 格式兼容服务，不绑定具体模型。内置适配器使用 `/chat/completions`、Bearer 和 `response_format=json_object`，不发送工具或使用流式执行；其他服务须实际支持该协议，不能据“兼容”名称推定任意厂商 API 可用。后台维护最多 20 份档案，包括 Provider、HTTPS API Base URL、Model、可选 Temperature、超时 5–45 秒、输出 Token 上限 256–8192、max_tokens/max_completion_tokens 参数选择、输入/输出每百万 Token 单价、可选费用上限及启用状态。

API Key 由服务器 `AISH_AI_KEY_<大写档案ID>` 常量或环境变量读取，后台只显示固定掩码，不提供读取明文接口，不写 options、任务、浏览器、普通日志或 Git。默认 API 主机为 api.openai.com；自定义主机必须加入服务器 `AISH_AI_ALLOWED_HOSTS`，且仍拒绝 IP、私网/本地主机、不安全协议、凭据、查询片段和重定向。使用统一 WordPress 安全 HTTP、TLS、有限超时和响应体限制，错误转为固定诊断，不回显远端错误体或 Authorization。

连接测试通过同一受控队列发起最小模型请求，记录状态和用量；同时要求 AI 设置和生成能力，仅有配置权限的角色不能单独发起付费测试。测试也可能收费，成功仅证明该档案当时的最小请求可用，不代表整套生成、数据库或浏览器已验收。Provider/Model 切换不会把旧任务悄悄改发到新目标，执行前校验冻结配置与当前设置。

### 10.2 输入与结构化输出

输入只来自 Phase 5 已采集来源及真实数据库信息：名称、description、SKILL.md、仓库/路径、File/Commit SHA、现有平台标签、依赖/安装/License 证据、静态风险和已有分类标签目录。提示采用独立 system 消息和 JSON user 数据；来源只作为不可信引用，不能替换系统指令、调用工具、读取密钥、执行脚本、安装依赖或触发管理操作。白名单来源 JSON 最多 64 KiB，超限明确失败，不静默截断后冒充完整分析；常见密钥模式在传给模型前遮蔽，但不声称检测所有敏感信息。

支持双语、仅中文、仅英文、摘要及使用场景的独立生成模式。两种语言均关联原 Skill ID，但以各自读者习惯生成，不以逐句直译或关键词堆砌冒充质量。完整语言结果包含标题、简介、详细介绍、核心功能、适用任务/场景、安装初稿、环境依赖、限制和安全注意。部分生成仅返回指定字段，不能用片段无条件替换完整正文。

输出必须为有效 JSON，严格结果上限 128 KiB、HTTP 响应上限 1 MiB，并逐层验证允许字段、类型、重复键、必填内容、数量、长度、请求语言及引用证据。语言字符检查只筛除明显错配，不能证明文风、事实或翻译质量。无效 JSON、截断、错误语言、超限或不受支持结构进入失败记录，不自动兜底为虚构内容。

### 10.3 建议与风险边界

分类建议区分主/次分类，标签建议优先引用真实已有 ID/名称；未知名称只保留建议，管理员在既有分类/标签页面核实后才创建或关联，不自动生成同义项。平台兼容性和依赖须有实际依据，不把模型推断当已测试支持。安装步骤明确“来源有文档”或“待核实”，结构化 command 字段逐字匹配 SKILL.md 及所引用证据，不编造通用安装命令、不执行命令。自然语言解释的事实正确性仍需人工核实，字段校验不是语义证明。

AI 风险结果使用 unknown/low/medium/high，保留来源证据与解释，覆盖命令、脚本、文件系统、敏感数据、网络、依赖、权限、下载及提示注入线索。该结果不改人工 risk_level、不覆盖 Phase 5 静态检查，不宣称通过模型检查即安全。原始内容可能隐瞒风险，必须人工核对许可证、兼容性和安装行为。

### 10.4 草稿、人工保护与采用

原始 GitHub 快照、不可变 AI 输出、人工编辑草稿、已采用内容分别记录。AI 完成只进入 needs_review，不能自动更新正式译文或公开状态。管理员可查看原文与生成结果、修改中文/英文草稿、拒绝建议，或明确选择字段采用；记录操作者、时间及版本，不覆盖原始 AI 输出。

采用时在事务内核对来源指纹、Skill 修订号与正式译文基线，任一变化即拒绝旧草稿写回。只允许填入选中且仍为空的标题、简介、正文，不覆盖已有人工字段、slug、SEO、分类标签或审核。已有内容需要合并时由编辑在原 Skills 编辑器主动操作；仅使用场景的片段保留为审稿建议，不能当完整正文自动采用。成功采用使 Skill 进入 pending、修订递增，之后仍需补齐真实 slug/内容并按既有 Reviews 审核当前修订，才可能公开。

### 10.5 任务、用量与费用

AI 使用独立 WordPress Cron 队列，计划间隔 60 秒，每步最多一个模型请求；全局默认暂停，只有授权管理员配置/入队并启用后执行。批量最多 10 个 Skill，活动任务键和请求键防同源并行/重复表单，支持暂停、恢复、取消及显式失败重试。租约 120 秒，任务截止 48 小时，尝试最多 3 次；所有写回检查当前租约，普通前台请求不执行耗时任务。WP-Cron 依赖真实触发，不承诺准点执行。

每次请求前先持久化 attempt。明确可重试的限流/服务错误按退避调度；超时、网络中断或已发送请求未保存结果的情况标为不确定，不自动假设供应商没执行而重发。人工重试可能产生额外费用，不能承诺跨供应商“恰好一次”计费。暂停/取消也不能撤回已经发出的外部请求，界面应如实显示已知结果和限制。

任务记录 Provider、Model、Prompt Version、来源 SHA、状态、固定错误、耗时、输入/输出 Token 和估算费用。用量缺失保持未知，不写虚构零值；费用按管理员配置单价估算并明确标注，不冒充实际账单。输出 Token 和单请求时长有上限；配置费用上限时执行前做保守预算校验，价格未知不能假报预算已满足。价格变更不重写历史估算。

说明见 `docs/AI_USAGE.md`、`docs/API_CONFIGURATION.md`、`docs/AI_SECURITY.md`；历史基本检查结果见 Phase 6 报告，当前真实集成见 FINAL_QA_REPORT。没有真实 API Key 时只运行 Mock Provider，不能声称真实 AI API 成功。

## 11. 安全与运维规范

- 每个 PHP 入口防直接访问；输入按字段校验/净化，输出按 HTML/属性/URL/JSON 上下文转义；富文本只允许 `wp_kses` 白名单。
- 写操作同时检查 capability 与 nonce；REST 明确 permission_callback，nonce 不能代替权限；匿名写入仅允许本轮明确授权的私有联系/投稿入口，不能调用 Skills 管理服务。
- SQL 使用 `$wpdb->prepare`；排序字段和表名仅来自常量/白名单。上传遵守 WP 类型限制，禁止任意文件落盘和远程代码执行。
- HTTP 使用 WP 安全请求 API、HTTPS 验证、域名限制、超时/大小限制；每次跳转重新验证目的地。防 SSRF、存储型 XSS、CSRF、越权与提示注入。
- 凭据不入 Git、不回显、不写日志；`.env`、数据库、uploads、构建依赖和备份忽略。前端 bundle 不含服务器密钥。开发密码随机生成，示例文件留空。
- 依赖锁定，CI 构建和 PHP 语法检查；后续引入 WordPress Coding Standards、权限/恶意输入/任务幂等集成测试。发现风险记录实际影响，不把语法通过当作安全验证。
- 开发站仅绑定 Cloud 的 localhost，不绑定公网；MySQL 不开放宿主机端口。生产部署不在本项目当前授权范围。
- 备份/回滚涵盖数据库、上传和版本化代码；迁移与数据删除需可审查的操作步骤，切换主题不删数据。

## 12. 开发阶段与验收

| 阶段 | 实施范围 | 退出条件 |
| --- | --- | --- |
| Phase 0 初始化（已验收） | 根规格、README/贡献约束、Git 忽略、原创最小主题/Core 空入口、前端构建、Cloud Compose、PHP/构建 CI、环境与测试记录、提交同步 | 用户已明确验收；Cloud 运行限制保留历史记录 |
| Phase 1 数据库与 Core（已验收） | 八表、版本化迁移、稳定 ID/双语数据关联、模块加载、统一 DAL/Catalog、验证/转义、语言接口 | 历史报告记录 PHP、MySQL/WordPress CI 证据；用户已验收 |
| Phase 2 原生后台（已验收） | 菜单、统计、Skills 双语 CRUD/归档、分类/标签/仓库、人工审核、风险/备注、受限预览、列表/批量和能力控制 | PHP、前端构建、基本检查；提交同步并暂停；全面集成留 Final QA |
| Phase 3 中英文双语前台（已验收） | 原创主题、12 类页面、真实公开查询、语言路由与回退、基础 SEO、教程与私有投稿 | 用户已验收；历史检查及未测项目见 Phase 3 报告 |
| Phase 4 搜索与 SEO（已验收） | Skills/教程双语关键词、组合筛选、查询与索引优化、独立 SEO、多语言 Meta、XML Sitemap、robots 索引策略 | 用户已验收；历史检查及未测项目见 Phase 4 报告 |
| Phase 5 GitHub Skills 自动采集（已验收） | 公开 GitHub API、完整树发现、安全 Frontmatter、去重/增量/不可变快照、原生采集后台、Cron 小任务/恢复、静态风险与人工处理 | 用户已验收；历史检查及未测项目见 Phase 5 报告 |
| Phase 6 AI 智能内容生成（已验收） | 可替换 Provider、双语分析/安装/分类/风险草稿、原生 AI 后台、任务用量/预算和人工内容保护 | 用户已验收；历史检查及未测项目见 Phase 6 报告 |
| Phase 7 安全、性能、稳定性与完善（已验收） | 完成两轮跨模块审查与必要修复 | 历史证据见 Phase 7 两份报告；用户已授权进入 Final QA |
| Final QA 集成与交付 | 全站交互、可访问性、SEO、性能、安全回归、备份恢复说明 | 端到端流程及回滚通过、无阻断缺陷、测试限制清楚；不自动外部部署 |

### Phase 0 明确边界

以下为已验收 Phase 0 的历史边界，不限制用户现已授权的后续阶段。

允许：初始化可激活的原创最小主题（仅通用占位页面）、`load_theme_textdomain`、构建资源加载、Core 无业务逻辑入口，以及环境配置/文档/CI。

禁止：实现 CPT、业务表、Skills 页面设计、双语内容路由、WPML 业务适配、采集/队列、AI API、业务后台、真实内容生产或外部部署。这些在本轮仅有设计。

### Phase 0 验收清单

1. 仓库与 origin 检查，无已有代码被覆盖；总规格覆盖需求、架构、数据库、前后台、多语言、集成、安全、阶段和验收。
2. `npm ci`、`npm run build` 成功；本地 dist 中 CSS/JS 均非空，仓库提交 lock 和源码，dist 由构建生成。
3. 主题 PHP 全文件 lint；Cloud 内 WordPress+MySQL 启动、主题激活和 HTTP 冒烟测试，记录 PHP/WP/MySQL 实际版本。受环境限制的项目标记阻塞，不能宣称通过。
4. 无密钥/数据库/第三方 WordPress 核心入库；保留云端重建说明及可运行检查命令。
5. 记录 Git SHA、分支、推送结果；若远端受限，完成本地提交并说明实际阻塞，不强推。
6. 完成报告中逐项区分通过、阻塞和后续事项。只有用户确认才能进入 Phase 1。

## 13. 验证策略与变更控制

Phase 2–7 使用分阶段基本检查；当前用户已正式启动 Final QA，执行真实集成并保留必要 PHP、构建和隔离回归。CI 的 push/pull_request 自动运行 PHP 8.3/8.4 及资源构建，数据库/WordPress 基线保留为 workflow_dispatch 的 final_qa 手动任务，不随每次提交执行。

当前 Final QA 的真实集成至少包括：八表结构及 1.1→1.2 数据保留迁移、重复安装、并发修订/审核竞争、人工来源/去重、双语完整性、各角色与 nonce、编辑后审核失效、归档确认及恢复、分类循环、关联归档保留、私有预览、XSS/SQL 注入、主题切换数据保留、真实 WPML/多语言路由及浏览器操作流程。现有 tests/integration/core.php 为可复用基线，不等同完整 Final QA。

Phase 1 已完成测试作为历史证据保存在其报告，不能用于宣称新代码通过集成。接口替身不能证明真实 WPML 兼容；语法检查不能证明数据库迁移或后台运行成功。每个未执行项目须明确标记，禁止以“预计通过”代替证据。

每阶段提交应关联规格和验收证据；报告注明未运行测试及原因。变更规格时同步 README/阶段报告，不能把计划当现状。分支推送用普通 fast-forward；远端已有并发变更时先检查并整合，禁止覆盖历史。用户关于代码同步仓库的要求持续有效。

Phase 3 Final QA 补充清单：真实 MySQL 公开谓词/筛选/计数和编辑撤回竞争；WordPress 重写/子目录/默认固定链接、12 类页面双语跳转；教程双向关系及私有收件箱、真实表单持久化/限流/Nonce；404 与 503、无译文回退、Canonical/hreflang、移动键盘及无 JS、浏览器兼容、WPML 和并发。静态代码审查和语法检查不能替代这些运行验收。

Phase 4 Final QA 补充清单：MySQL 1.2→1.3 仅索引增量及可重复执行、真实 EXPLAIN/大数据量与组合筛选分页；教程 get_meta_sql 回调、语言/密码/完整性门禁及真实 WordPress 缓存；中英文自 Canonical/hreflang/x-default、独立 Meta 配置与 noindex；Sitemap 全量/跨页/缺译/归档/异常/lastmod 与重复 URL；物理 robots.txt 覆盖、站点关闭索引、原生及 Yoast/Rank Math/WPML 真实启停组合和索引缓存。必要基础断言只能验证隔离合同，不能代替上述真实系统验收。

Phase 5 阶段检查以模拟 GitHub API、纯状态机和数据库替身覆盖仓库地址/公开身份、Git Tree/截断回退、多 SKILL.md、Frontmatter、SHA/重复扫描/增量、限流重试及不可信内容；不得描述为真实 GitHub 或 MySQL 验收。实际结果记录于 Phase 5 报告。

Phase 5 Final QA 补充清单：真实 GitHub 认证/匿名限流/次限流、公开转私有/同名重建、分支/Tree/Blob 响应与超限；MySQL 1.3→1.4 与历史版本逐级迁移/重复安装/故障恢复、11 表物理结构及原八表 ID/双语/唯一键/内容保留；真实 WordPress Cron 触发、单 worker 锁、租约回收与过期 worker 拒写、断点崩溃恢复、并发排队/去重、5 次失败/48 小时截止；完整/截断/失败清单的 missing 规则、疑似移动、相同文件 SHA 的新树风险变化、人工编辑与采集并发、来源绑定修改、原生后台权限/nonce/归档确认、浏览器以及 Phase 0–4 搜索/SEO/WPML 回归。上述项目在 Phase 5 当时未执行；本次 Final QA 逐项记录实际覆盖和剩余边界。

Phase 6 阶段检查使用 Mock Provider/HTTP 与存储替身，覆盖 Provider 切换、API 错误/超时/退避、Key 掩码与错误脱敏、结构化输出/无效 JSON、中文/英文模式、来源证据、人工字段保护、重复任务、暂停恢复和 Token 记录。断言通过不代表真实 AI 供应商或数据库已经验收，准确结果写入 Phase 6 报告。

Phase 6 Final QA 补充清单：真实 OpenAI/兼容服务协议、模型/Temperature/Token 参数差异、Host/DNS/SSRF/TLS/重定向/大小边界和密钥轮换；MySQL 1.4→1.5 与历史逐级升级、重复迁移、原 11 表数据不变、任务唯一键/租约/事务、请求前后崩溃和不确定计费恢复；WordPress Cron、角色/nonce/批量部分失败、全局/单任务暂停取消；来源更新/管理员同时编辑/旧 AI 草稿采用竞争、空字段保护、采用后审核失效；Token 缺失、价格未知、预算边界、历史估算与供应商账单对照；真实双语质量、提示注入、安装证据、风险和术语建议的人工核验；浏览器、WPML/SEO 以及 Phase 0–5 全部端到端回归。无真实凭据时不得把 API 测试标为通过。


### Final QA 已验证的 SEO 兼容规则

当前主题对公开可索引的200页面输出安全编码的WebSite/WebPage/BreadcrumbList，不推测评分、作者或安全认证。Yoast移除的原生title renderer仅在Hub拥有SEO输出时恢复；robots最后优先级落实私站策略。两个插件公开Sitemap index hook均可按精确loc幂等追加Hub子图，不用插件常量猜测谁实际渲染端点；使用公开filters禁用其XML缓存以防缓存跳过自定义表动态索引，承担实时XML查询成本。RankMath stdClass和Yoast WP_Post均按有效post entry契约处理。详细真实状态变更与缓存证据见FINAL_QA_REPORT和docs/SEO。
