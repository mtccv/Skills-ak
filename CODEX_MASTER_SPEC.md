# AI Skills Hub — 项目总规格

版本：1.4；建立及修订日期：2026-11-05（Asia/Shanghai）。仓库：https://github.com/mtccv/Skills-ak。

## 1. 目标与执行约束

建立面向中文及英文读者的 AI Skills 发现、检索、阅读与来源导航网站。使用 WordPress 原生内容管理和原创主题；主题内置 AI Skills Hub Core，负责领域逻辑。GitHub 是来源，AI 是辅助编辑工具，编辑人员控制发布。

唯一开发环境为 Codex Cloud。不得引入宝塔、要求用户本机开发或部署外部服务器。Cloud 内的容器、数据库与 HTTP 预览只用于开发与验收，不构成生产部署。代码、规格和构建锁文件同步 GitHub；运行数据、密钥和依赖目录不入库。

用户已验收 Phase 0–3，当前授权 **Phase 4：AI Skills Hub 搜索系统与 SEO 系统**。本轮以 GitHub main 的既有代码为基础，完善中英文 Skills/教程搜索、组合筛选、排序分页、查询优化、独立 SEO 配置、多语言 Meta、XML Sitemap 和 robots 索引策略。复用现有八表；已说明的必要数据库调整仅为 1.3.0 两个非唯一索引，不新增业务字段或改变稳定 ID、审核、来源去重设计。SEO 复用已有翻译字段及 WordPress options/postmeta。Contact 与 Submit 继续按用户已确认方案保存私有待处理记录，不直接创建或发布 Skill。完成必要检查、文档、提交及 GitHub main 同步后暂停；不自动开始后续阶段、GitHub 采集或 AI 生成。

## 2. 技术基线

| 层 | 决策 |
| --- | --- |
| CMS | WordPress 官方当前稳定版本；主题声明最低 6.8，后续验收记录实际版本 |
| 运行时 | PHP 8.3 起（满足 PHP 8.x），验收目标 8.3/8.4；上线前复核支持周期 |
| 数据库 | MySQL 8.4 LTS，utf8mb4，统一以 UTC 存储任务时间 |
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

后续 Core 按 Content、Language、Sources、Jobs、AI、Admin、Security 分模块，PHP 命名空间 `AISkillsHub\Core`。主题函数使用 `aish_` 前缀，选项/元字段/表名使用 `aish_`，WordPress 数据表前缀从 `$wpdb->prefix` 获取。模块通过 WordPress hooks 启动，外部供应商、语言关系和任务执行均提供接口，避免模板直接请求外部网络。

Core 随主题加载，切换主题后停止执行，但保留内容和数据；不得自动删除表、选项或文章。文档说明此耦合，未来独立插件拆分须经用户确认。升级执行版本化、可重复的迁移，不在每个前台请求执行 DDL。开发阶段不自动启用真实外部任务。

## 5. 数据库与内容模型（Phase 1 基础、Phase 2 字段扩展、Phase 4 索引优化）

依据用户本轮要求，Core 自定义表为唯一主存储。WordPress 提供用户、权限、选项、主题生命周期与原生后台界面；不注册影子 Skills CPT，不以 post ID 或语言文章 ID 代替内部 Skill ID。Phase 0 未创建业务表，无旧 CPT 数据需要转换。

### 5.1 八张核心表

表名均加 `$wpdb->prefix`，支持 1–40 个 ASCII 字母/数字/下划线的站点前缀。InnoDB、utf8mb4，UTC datetime。实体主键为 bigint unsigned AUTO_INCREMENT，PHP API 接受正整数（受 PHP_INT_MAX 限制）；内部 ID 不因标题、语言、slug 或版本变更而更换。

| 表（加 WP 前缀） | 字段与约束 | 关联 |
| --- | --- | --- |
| aish_repositories | id, owner varchar(39), name varchar(100), is_archived, created_at；UNIQUE(owner,name)，GitHub 身份归一化小写 | 一个仓库有多个 Skills |
| aish_skills | id, repository_id nullable, source_path VARBINARY(768) nullable, status, revision, review_status, risk_level, reviewer_id, reviewed_at, reviewed_revision, review_notes, created_at, updated_at；UNIQUE(repository_id,source_path)，INDEX(status,created_at)，INDEX(status,review_status,updated_at,id) | 稳定的语言无关 Skill 实体 |
| aish_skill_translations | id, skill_id, locale varchar(10), title, slug nullable, summary, content, seo_title, seo_description, created_at, updated_at；UNIQUE(skill_id,locale)，UNIQUE(locale,slug)，INDEX(locale,title,skill_id) | zh_CN/en_US 共用同一 skill_id；缺译返回 null |
| aish_skill_versions | id, skill_id, commit_sha varchar(64), content_hash char(64), source_content mediumtext, version_label, created_at；UNIQUE(skill_id,commit_sha) | 每个 Skill 的不可变来源快照，同提交可包含多个 Skills |
| aish_categories | id, name, slug UNIQUE, parent_id nullable, is_archived, created_at；INDEX(parent_id) | 层级分类，共用分类名称；名称翻译留后续 |
| aish_tags | id, name, slug UNIQUE, is_archived, created_at | 非层级标签 |
| aish_skill_categories | PRIMARY KEY(skill_id,category_id)，INDEX(category_id) | Skills 与分类多对多 |
| aish_skill_tags | PRIMARY KEY(skill_id,tag_id)，INDEX(tag_id) | Skills 与标签多对多 |

`source_path` 是仓库内规范的相对文件路径（例如 `tools/review/SKILL.md`），最大 768 UTF-8 字节；拒绝绝对路径、空/点/父路径段、反斜杠和控制字符，不自动解码 URL、不改大小写。VARBINARY 和完整联合唯一索引避免 MySQL 字符排序及索引截断造成错误合并。单仓库不同路径对应不同 Skill；同一路径在不同仓库独立存在。

正文最多 512 KiB，经 `wp_kses_post` 存储；来源快照为受限 UTF-8 原文，仅以纯文本方式输出，不执行 Markdown/脚本/指令。版本哈希由 Core 自行计算 SHA-256，同一 skill_id+commit_sha 的不同内容拒绝写入，不能覆盖旧版本。slug 采用最长 191 字节的规范 ASCII 小写形式，中英文允许同 slug（按 locale 隔离）。

不建立数据库外键或级联删除；Catalog 事务与行锁校验关联对象，数据库唯一键兜底并发去重。DAL 属于可信内部代码，不是授权边界；外部写入必须经过 Catalog 或 Admin Management 服务。没有硬删除 API 或卸载清理钩子，主题升级、停用、切换均保留数据。绕过 Catalog 的人工 SQL 必须自行维护关联完整性，禁止将内部 DAL 直接暴露到 HTTP。

### 5.2 初始化与增量升级

`aish_schema_version` 为每站点 schema 版本，autoload 关闭。迁移 1.0.0 建立基础八表，1.1.0 为版本快照添加 `version_label`；Phase 2 加入 1.2.0 扩展现有八表；Phase 4 的 1.3.0 只添加 `skills.public_updated(status,review_status,updated_at,id)` 和 `skill_translations.locale_title(locale,title,skill_id)` 两个非唯一完整索引，并校验实际索引列顺序。没有新表、新业务字段或数据重写。1.0.0 同时用于旧结构升级测试，并不表示已有线上历史数据。

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

### 5.4 后续扩展（不在本阶段实现）

许可证、工具标签、GitHub ID/统计、采集配置和 AI 审核元信息由后续增量迁移添加，不能另建重复仓库身份系统。未来自动化阶段经单独授权再按需加入 aish_imports（repository_id、skill_id、来源 SHA、状态）、aish_jobs（幂等键、重试和锁）、aish_audit（脱敏审计）；不在 Phase 1 建表或调度。

少量配置复用 WordPress options；大型设置和日志禁用 autoload。任务不存凭据，外部服务密钥来自环境引用。后续来源更新/AI 结果只能成为待审核变更，不能隐式覆盖人工编辑或直接发布。

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

使用场景、安装说明、依赖要求可从现有译文正文的 H2 标题提取；未提供时明确显示未知，完整正文仍可阅读。版本仅显示审核时间之前记录的历史 SHA 元数据；现有 schema 未把 SHA 与审核修订/来源路径绑定，因此不能声称该 SHA 已被审核或是当前来源，GitHub 文件链接使用 HEAD。不读取外部仓库，不自动分析来源或生成内容。

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

Hub SSR 路由由主题统一拥有 Meta 输出：通过 Yoast 的公开 presenter filter、Rank Math 的 frontend head action 和 WPML hreflang filter 避免重复；非 Hub 路由保留插件行为。`aish_frontend_seo_enabled` 可将标签输出交给适配器；安全 noindex HTTP 标记仍独立生效。WPML 教程关系使用公开 `wpml_object_id` API（缺译不返回原文），并继续验证站点本身的语言、发布和双向关系；URL/请求适配保留既有 filters。真实 WPML、Yoast 和 Rank Math 联调尚待 Final QA，不能把 hooks 存在等同插件运行兼容已验收。

### 6.6 Phase 4 Sitemap 与索引策略

`/aish-sitemap.xml` 提供 XML 索引，按类型 skills/category/tag/tutorials/pages、语言 zh/en、每文件 200 URL 分页。Skill/教程仅收录当前语言真实公开且允许索引的条目；分类/标签必须未归档并有当前语言公开 Skills。静态页包括首页、Skills/教程列表及有真实语言正文的 About/Contact。不收录投稿页、搜索、筛选组合、排序、分页 URL、草稿、待审、拒绝、归档、缺译、noindex 内容。

Sitemap 与公开查询共用门禁；noindex 排除在计数与 LIMIT 之前执行，避免错误页数。实体 lastmod 使用已有 UTC 更新时间；分类/标签取关联公开内容的真实最大时间；没有真实更新日期的生成集合页不伪造 lastmod。无结果/越界 XML 子文件返回 404；数据库失败返回通用 503，不能输出部分 XML 并报成功。

WordPress 原生 Sitemap provider、Yoast 和 Rank Math 的官方索引 hooks 引用同一组 Hub XML 文件，避免再创建另一套 URL 集；已有 Hub 用途的 WordPress 页面从原生/插件文章 Sitemap 排除其重复原生地址。识别到 Yoast/Rank Math 时选择一个索引接入方；其他 Sitemap 插件可通过 `aish_sitemaps_enabled` 禁用主题源并接管。第三方索引缓存、启停组合与撤稿即时生效需真实插件 QA。

搜索、含筛选/排序/未知参数的 URL、表单结果、投稿页、缺失说明页、空分类/标签、404/503 均 noindex；正常集合的合法纯 page 分页可自引用 Canonical，但不进入 Sitemap。私有后台/登录/预览使用 noindex；公开权限校验不能用 robots 代替。站点关闭“允许搜索引擎索引”时，主题 Sitemap 停用并保持 noindex。

使用 WordPress 虚拟 robots.txt，保留其后台规则及已有插件 Sitemap 指令；公开站点且缺少 Sitemap 指令时补充 Hub 索引。不得 Disallow 搜索/筛选 URL 而妨碍爬虫读取页面 noindex；私有站点输出 Disallow: /。不在仓库写入会覆盖 WordPress 动态行为的静态 robots.txt；实际 Web 服务的物理文件覆盖情况留 Final QA 核查。

## 7. WordPress 原生后台

使用 WordPress 原生菜单、WP_List_Table、wp_editor 和 admin-post 控制器管理 Core 记录；不创建影子文章，不引入页面构建器或独立 SPA。

基础菜单为 AI Skills Hub → Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings；Phase 3 增加 Tutorials 与 Inbox，Phase 4 增加 SEO。

- Dashboard 显示总数、待审核、各状态以及分类、标签、仓库统计。
- Skills 支持创建、读取、修改和归档；保留 draft/pending/published/rejected/archived 五种状态；支持人工来源和同仓库多个 Skills。归档代替永久删除，必须在服务器返回的确认页二次提交。
- 中文/英文在同一编辑表单内切换，分别维护标题、slug、简介、正文、SEO 标题和描述。显示缺失/未完整/完整状态；不得因缺译自动填充另一种语言。
- 列表支持搜索、状态/风险/仓库/分类/标签筛选、白名单排序和分页；批量操作设数量上限，逐项能力及修订检查，不能批量绕过审核直接发布。
- 分类支持层级及循环校验；标签为平面结构；仓库仅手动维护 owner/name，已关联 Skills 的仓库不允许直接改身份。归档分类、标签和仓库保留原关联。
- 人工审核支持 pending、approved、rejected，记录人员、UTC 时间、修订、Unknown/Low/Medium/High 风险和备注。只有审批当前修订才可发布；编辑内容需要重新审核。拒绝保留原记录。
- Skill 阅读预览为登录且拥有 Skills 管理或审核能力、有效 nonce 的私有页面，标记 no-store/noindex；未审核内容不提供公开地址。公开站点页面由 Phase 3 实现。
- Settings 配置默认编辑语言和列表每页数量；SEO 子菜单及原生文章 SEO 面板承载 Phase 4 配置，不提供尚未实现的采集/AI 设置。
- 能力为 aish_manage_skills、aish_review_skills、aish_manage_taxonomies、aish_manage_repositories、aish_manage_settings；默认只赋予管理员，manage_options 兼容既有管理员。编辑及其他角色不自动获得能力。页面、控制器和服务各自校验相关 capability，nonce 不能代替能力。

归档及批量操作使用 POST + nonce + 权限 + 二次确认；输入验证、prepared SQL、输出上下文转义和 KSES 沿用 Core。服务抛出可控错误，HTTP 层不显示数据库详情。设置保存在 WordPress options，未引入额外业务表。

## 8. 内置双语与 WPML

- 界面字符串统一 gettext，text domain `ai-skills-hub`，提供中文与英文目录；内容翻译与界面翻译分开处理。
- 内置内容以同一个 skill_id 下的 zh_CN/en_US 翻译行关联；Phase 1 只实现存取与语言归一化，不产生翻译或路由。Phase 2 提供后台默认编辑语言及编辑切换；Phase 3 实现公开 `/zh/`、`/en/` 及完整前台双语界面词典。
- `LanguageProvider` 接口提供支持语言、语言归一化和可选外部对象 ID；通过 `aish_core_language_provider` 注入，适配器必须实现该接口。WPML 使用官方 hooks/API 和配置，Phase 4 教程已接入公开 wpml_object_id 关系查找；外部对象 ID 不得取代稳定 Skill ID，也不得重复拥有 Core 的翻译关系。WPML 接管 URL 时内置 rewrite 退出；Phase 1 不启用真实 WPML 集成。
- 两种模式必须分别测试语言切换、列表/搜索过滤、分页、canonical/hreflang、分类、缓存、后台编辑。模式迁移要有 dry-run、冲突清单、备份和回退，不能自动覆盖翻译。
- WPML 为可选商业依赖，不随仓库分发；未提供合法插件时以适配合同测试覆盖，必须把真实联调标为待完成。

## 9. GitHub Skills 采集

仅采集管理员配置的 GitHub 仓库及路径，默认关闭定时采集。使用 GitHub API、认证引用、ETag/条件请求、分页、速率预算和退避。发现 `SKILL.md` 时解析有限 front matter 与 Markdown；路径、文件大小、递归深度和请求时长均设上限。初始建议：单文件 512 KiB、每任务最多 100 项、请求超时 20 秒；未来采集阶段用样本校准。

仓库身份先归一化 owner/name，Skill 来源使用 repository_id + source_path 去重，版本使用 skill_id + commit_sha 去重并校验内容哈希。来源更新只生成待审核更新，不覆盖人工编辑或直接发布。记录固定提交 SHA、规范来源 URL、许可证和采集时间；许可证不明时仅保留必要元信息及来源链接，全文使用策略交由编辑确认。删除/移动内容标记待核对，不自动删除已发布文章。

不得执行来源中的命令、脚本或指令；采集文本视为不可信数据。下载地址仅允许 GitHub 已定义 HTTPS API/内容域，校验重定向，阻止内网和云元数据地址；不提供任意 URL 抓取代理。

## 10. AI 双语内容生成

供应商适配层接受脱敏且长度受限的来源数据，输出结构化 zh_CN/en_US 标题、摘要、说明、适用场景及限制；校验 JSON schema、语言、必填项及长度。系统提示明确来源中的指令只是引用，不得改变任务、访问工具或读取密钥。

所有结果保存为草稿/待审核，保留模型、提示版本、来源 SHA、时间与失败原因。不得捏造测试结果、兼容性、安全保证或许可证；缺失信息必须标明未知。重试使用幂等键，失败不覆盖现有译文；显示单任务 token/费用预算与取消能力。无真实凭据时使用测试替身，不伪造真实 API 成功。发布前必须人工校对两种语言。

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
| **Phase 4 搜索与 SEO（当前授权）** | Skills/教程双语关键词、组合筛选、查询与索引优化、独立 SEO、多语言 Meta、XML Sitemap、robots 索引策略 | 必要 PHP/构建/基本检查，文档及 GitHub main 同步；完成后等待下一指令 |
| 后续自动化（未授权） | GitHub 采集、AI 内容、队列与审计 | 用户另行确认后实施 |
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

沿用用户 Phase 2 起调整后的要求，项目采用分阶段开发、最后统一全面测试：本阶段只执行必要 PHP 语法、前端构建和基本代码检查，修复发现的明显问题。CI 的 push/pull_request 自动运行 PHP 8.3/8.4 及资源构建，数据库/WordPress 基线保留为 workflow_dispatch 的 final_qa 手动任务，不随每次提交执行。

完整 MySQL、WordPress、WPML、浏览器兼容、并发和端到端验收统一放到 Final QA。至少包括：八表结构及 1.1→1.2 数据保留迁移、重复安装、并发修订/审核竞争、人工来源/去重、双语完整性、各角色与 nonce、编辑后审核失效、归档确认及恢复、分类循环、关联归档保留、私有预览、XSS/SQL 注入、主题切换数据保留、真实 WPML/多语言路由及浏览器操作流程。现有 tests/integration/core.php 为可复用基线，不等同完整 Final QA。

Phase 1 已完成测试作为历史证据保存在其报告，不能用于宣称新代码通过集成。接口替身不能证明真实 WPML 兼容；语法检查不能证明数据库迁移或后台运行成功。每个未执行项目须明确标记，禁止以“预计通过”代替证据。

每阶段提交应关联规格和验收证据；报告注明未运行测试及原因。变更规格时同步 README/阶段报告，不能把计划当现状。分支推送用普通 fast-forward；远端已有并发变更时先检查并整合，禁止覆盖历史。用户关于代码同步仓库的要求持续有效。

Phase 3 Final QA 补充清单：真实 MySQL 公开谓词/筛选/计数和编辑撤回竞争；WordPress 重写/子目录/默认固定链接、12 类页面双语跳转；教程双向关系及私有收件箱、真实表单持久化/限流/Nonce；404 与 503、无译文回退、Canonical/hreflang、移动键盘及无 JS、浏览器兼容、WPML 和并发。静态代码审查和语法检查不能替代这些运行验收。

Phase 4 Final QA 补充清单：MySQL 1.2→1.3 仅索引增量及可重复执行、真实 EXPLAIN/大数据量与组合筛选分页；教程 get_meta_sql 回调、语言/密码/完整性门禁及真实 WordPress 缓存；中英文自 Canonical/hreflang/x-default、独立 Meta 配置与 noindex；Sitemap 全量/跨页/缺译/归档/异常/lastmod 与重复 URL；物理 robots.txt 覆盖、站点关闭索引、原生及 Yoast/Rank Math/WPML 真实启停组合和索引缓存。必要基础断言只能验证隔离合同，不能代替上述真实系统验收。
