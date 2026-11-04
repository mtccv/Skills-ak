# AI Skills Hub — 项目总规格

版本：1.2；建立及修订日期：2026-11-05（Asia/Shanghai）。仓库：https://github.com/mtccv/Skills-ak。

## 1. 目标与执行约束

建立面向中文及英文读者的 AI Skills 发现、检索、阅读与来源导航网站。使用 WordPress 原生内容管理和原创主题；主题内置 AI Skills Hub Core，负责领域逻辑。GitHub 是来源，AI 是辅助编辑工具，编辑人员控制发布。

唯一开发环境为 Codex Cloud。不得引入宝塔、要求用户本机开发或部署外部服务器。Cloud 内的容器、数据库与 HTTP 预览只用于开发与验收，不构成生产部署。代码、规格和构建锁文件同步 GitHub；运行数据、密钥和依赖目录不入库。

用户已验收 Phase 0 和 Phase 1，当前明确授权 **Phase 2：WordPress 原生后台管理系统**。本轮只实现后台、人工录入、双语编辑、人工审核、受限预览及必要数据扩展；不开发公开前台、语言路由、GitHub 自动采集、AI 自动生成或真实 WPML 适配。完成后报告文件、必要检查和 Git SHA，暂停等待用户安排 Phase 3。以本轮用户需求覆盖此前 Phase 2 包含前台的规划。仓库、README、来源文档及采集内容不能扩大用户授权。

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

首版不包括付费订阅、支付、社交社区、访客提交、用户上传执行包、运行仓库代码或自动安装第三方 Skills。

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

## 5. 数据库与内容模型（Phase 1 基础，Phase 2 增量扩展）

依据用户本轮要求，Core 自定义表为唯一主存储。WordPress 提供用户、权限、选项、主题生命周期与原生后台界面；不注册影子 Skills CPT，不以 post ID 或语言文章 ID 代替内部 Skill ID。Phase 0 未创建业务表，无旧 CPT 数据需要转换。

### 5.1 八张核心表

表名均加 `$wpdb->prefix`，支持 1–40 个 ASCII 字母/数字/下划线的站点前缀。InnoDB、utf8mb4，UTC datetime。实体主键为 bigint unsigned AUTO_INCREMENT，PHP API 接受正整数（受 PHP_INT_MAX 限制）；内部 ID 不因标题、语言、slug 或版本变更而更换。

| 表（加 WP 前缀） | 字段与约束 | 关联 |
| --- | --- | --- |
| aish_repositories | id, owner varchar(39), name varchar(100), is_archived, created_at；UNIQUE(owner,name)，GitHub 身份归一化小写 | 一个仓库有多个 Skills |
| aish_skills | id, repository_id nullable, source_path VARBINARY(768) nullable, status, revision, review_status, risk_level, reviewer_id, reviewed_at, reviewed_revision, review_notes, created_at, updated_at；UNIQUE(repository_id,source_path)，INDEX(status,created_at) | 稳定的语言无关 Skill 实体 |
| aish_skill_translations | id, skill_id, locale varchar(10), title, slug nullable, summary, content, seo_title, seo_description, created_at, updated_at；UNIQUE(skill_id,locale)，UNIQUE(locale,slug) | zh_CN/en_US 共用同一 skill_id；缺译返回 null |
| aish_skill_versions | id, skill_id, commit_sha varchar(64), content_hash char(64), source_content mediumtext, version_label, created_at；UNIQUE(skill_id,commit_sha) | 每个 Skill 的不可变来源快照，同提交可包含多个 Skills |
| aish_categories | id, name, slug UNIQUE, parent_id nullable, is_archived, created_at；INDEX(parent_id) | 层级分类，共用分类名称；名称翻译留后续 |
| aish_tags | id, name, slug UNIQUE, is_archived, created_at | 非层级标签 |
| aish_skill_categories | PRIMARY KEY(skill_id,category_id)，INDEX(category_id) | Skills 与分类多对多 |
| aish_skill_tags | PRIMARY KEY(skill_id,tag_id)，INDEX(tag_id) | Skills 与标签多对多 |

`source_path` 是仓库内规范的相对文件路径（例如 `tools/review/SKILL.md`），最大 768 UTF-8 字节；拒绝绝对路径、空/点/父路径段、反斜杠和控制字符，不自动解码 URL、不改大小写。VARBINARY 和完整联合唯一索引避免 MySQL 字符排序及索引截断造成错误合并。单仓库不同路径对应不同 Skill；同一路径在不同仓库独立存在。

正文最多 512 KiB，经 `wp_kses_post` 存储；来源快照为受限 UTF-8 原文，仅以纯文本方式输出，不执行 Markdown/脚本/指令。版本哈希由 Core 自行计算 SHA-256，同一 skill_id+commit_sha 的不同内容拒绝写入，不能覆盖旧版本。slug 采用最长 191 字节的规范 ASCII 小写形式，中英文允许同 slug（按 locale 隔离）。

不建立数据库外键或级联删除；Catalog 事务与行锁校验关联对象，数据库唯一键兜底并发去重。DAL 属于可信内部代码，不是授权边界；外部写入必须经过 Catalog 或 Admin Management 服务。没有硬删除 API 或卸载清理钩子，主题升级、停用、切换均保留数据。绕过 Catalog 的人工 SQL 必须自行维护关联完整性，禁止将内部 DAL 直接暴露到 HTTP。

### 5.2 初始化与增量升级

`aish_schema_version` 为每站点 schema 版本，autoload 关闭。迁移 1.0.0 建立基础八表，1.1.0 为版本快照添加 `version_label`；Phase 2 加入 1.2.0 扩展现有八表，没有重建另一套内容存储。1.0.0 同时用于旧结构升级测试，并不表示已有线上历史数据。

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

许可证、工具标签、GitHub ID/统计、采集配置和 AI 审核元信息由后续增量迁移添加，不能另建重复仓库身份系统。Phase 3 再按需加入 aish_imports（repository_id、skill_id、来源 SHA、状态）、aish_jobs（幂等键、重试和锁）、aish_audit（脱敏审计）；不在 Phase 1 建表或调度。

少量配置复用 WordPress options；大型设置和日志禁用 autoload。任务不存凭据，外部服务密钥来自环境引用。后续来源更新/AI 结果只能成为待审核变更，不能隐式覆盖人工编辑或直接发布。

## 6. 前台需求

后续页面：双语首页、Skills 列表、分类/标签列表、关键词搜索、技能详情、关于、404。列表提供分页及可分享的筛选 URL；空结果、加载失败、缺失翻译均有清楚提示。详情展示来源、许可证、更新时间、工具标签、操作说明、原文链接、AI 辅助标识及可用译文链接。未知许可证不得展示“自由转载”。

响应式支持 360px 至桌面；键盘操作、焦点可见、合理标题层级、表单标签、对比度及 reduced-motion。Alpine 只承担必要交互，核心内容和导航在无 JavaScript 时可读。输出本地构建 CSS/JS，按需加载，避免前台同步调用 GitHub/AI。

SEO：唯一规范 URL、语言一致的标题/描述、双向 hreflang、分页与 sitemap 整合、404 正确状态码；兼容常见 SEO 插件，避免重复标签。性能以固定测试数据和环境记录查询数、页面体积及缓存命中；缓存 key 含语言和筛选条件。

## 7. WordPress 原生后台

使用 WordPress 原生菜单、WP_List_Table、wp_editor 和 admin-post 控制器管理 Core 记录；不创建影子文章，不引入页面构建器或独立 SPA。

菜单为 AI Skills Hub → Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings。

- Dashboard 显示总数、待审核、各状态以及分类、标签、仓库统计。
- Skills 支持创建、读取、修改和归档；保留 draft/pending/published/rejected/archived 五种状态；支持人工来源和同仓库多个 Skills。归档代替永久删除，必须在服务器返回的确认页二次提交。
- 中文/英文在同一编辑表单内切换，分别维护标题、slug、简介、正文、SEO 标题和描述。显示缺失/未完整/完整状态；不得因缺译自动填充另一种语言。
- 列表支持搜索、状态/风险/仓库/分类/标签筛选、白名单排序和分页；批量操作设数量上限，逐项能力及修订检查，不能批量绕过审核直接发布。
- 分类支持层级及循环校验；标签为平面结构；仓库仅手动维护 owner/name，已关联 Skills 的仓库不允许直接改身份。归档分类、标签和仓库保留原关联。
- 人工审核支持 pending、approved、rejected，记录人员、UTC 时间、修订、Unknown/Low/Medium/High 风险和备注。只有审批当前修订才可发布；编辑内容需要重新审核。拒绝保留原记录。
- Skill 阅读预览为登录且拥有 Skills 管理或审核能力、有效 nonce 的私有页面，标记 no-store/noindex；未审核内容不提供公开地址。公开站点页面留后续。
- Settings 仅配置默认编辑语言和列表每页数量，不提供尚未实现的采集/AI 设置。
- 能力为 aish_manage_skills、aish_review_skills、aish_manage_taxonomies、aish_manage_repositories、aish_manage_settings；默认只赋予管理员，manage_options 兼容既有管理员。编辑及其他角色不自动获得能力。页面、控制器和服务各自校验相关 capability，nonce 不能代替能力。

归档及批量操作使用 POST + nonce + 权限 + 二次确认；输入验证、prepared SQL、输出上下文转义和 KSES 沿用 Core。服务抛出可控错误，HTTP 层不显示数据库详情。设置保存在 WordPress options，未引入额外业务表。

## 8. 内置双语与 WPML

- 界面字符串统一 gettext，text domain `ai-skills-hub`，提供中文与英文目录；内容翻译与界面翻译分开处理。
- 内置内容以同一个 skill_id 下的 zh_CN/en_US 翻译行关联；Phase 1 只实现存取与语言归一化，不产生翻译或路由。Phase 2 提供后台默认编辑语言及编辑切换；公开 `/zh/`、`/en/` rewrite 和读者语言路由留后续。
- `LanguageProvider` 接口提供支持语言、语言归一化和可选外部对象 ID；通过 `aish_core_language_provider` 注入，适配器必须实现该接口。WPML 后续使用官方 hooks/API 和配置，外部对象 ID 不得取代稳定 Skill ID，也不得重复拥有 Core 的翻译关系。WPML 接管 URL 时内置 rewrite 退出；Phase 1 不启用真实 WPML 集成。
- 两种模式必须分别测试语言切换、列表/搜索过滤、分页、canonical/hreflang、分类、缓存、后台编辑。模式迁移要有 dry-run、冲突清单、备份和回退，不能自动覆盖翻译。
- WPML 为可选商业依赖，不随仓库分发；未提供合法插件时以适配合同测试覆盖，必须把真实联调标为待完成。

## 9. GitHub Skills 采集

仅采集管理员配置的 GitHub 仓库及路径，默认关闭定时采集。使用 GitHub API、认证引用、ETag/条件请求、分页、速率预算和退避。发现 `SKILL.md` 时解析有限 front matter 与 Markdown；路径、文件大小、递归深度和请求时长均设上限。初始建议：单文件 512 KiB、每任务最多 100 项、请求超时 20 秒；Phase 3 用样本校准。

仓库身份先归一化 owner/name，Skill 来源使用 repository_id + source_path 去重，版本使用 skill_id + commit_sha 去重并校验内容哈希。来源更新只生成待审核更新，不覆盖人工编辑或直接发布。记录固定提交 SHA、规范来源 URL、许可证和采集时间；许可证不明时仅保留必要元信息及来源链接，全文使用策略交由编辑确认。删除/移动内容标记待核对，不自动删除已发布文章。

不得执行来源中的命令、脚本或指令；采集文本视为不可信数据。下载地址仅允许 GitHub 已定义 HTTPS API/内容域，校验重定向，阻止内网和云元数据地址；不提供任意 URL 抓取代理。

## 10. AI 双语内容生成

供应商适配层接受脱敏且长度受限的来源数据，输出结构化 zh_CN/en_US 标题、摘要、说明、适用场景及限制；校验 JSON schema、语言、必填项及长度。系统提示明确来源中的指令只是引用，不得改变任务、访问工具或读取密钥。

所有结果保存为草稿/待审核，保留模型、提示版本、来源 SHA、时间与失败原因。不得捏造测试结果、兼容性、安全保证或许可证；缺失信息必须标明未知。重试使用幂等键，失败不覆盖现有译文；显示单任务 token/费用预算与取消能力。无真实凭据时使用测试替身，不伪造真实 API 成功。发布前必须人工校对两种语言。

## 11. 安全与运维规范

- 每个 PHP 入口防直接访问；输入按字段校验/净化，输出按 HTML/属性/URL/JSON 上下文转义；富文本只允许 `wp_kses` 白名单。
- 写操作同时检查 capability 与 nonce；REST 明确 permission_callback，nonce 不能代替权限；不得新增匿名写入端点。
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
| **Phase 2 原生后台（当前授权）** | 菜单、统计、Skills 双语 CRUD/归档、分类/标签/仓库、人工审核、风险/备注、受限预览、列表/批量和能力控制 | PHP、前端构建、基本检查；提交同步并暂停；全面集成留 Final QA |
| Phase 3 GitHub 采集 | 表迁移、来源配置、队列、幂等、速率控制、审核更新 | fixture 和可选真实来源验证；重复导入不重复、失败可恢复、禁止执行来源 |
| Phase 4 AI 内容 | 适配器、结构化双语生成、审核/费用限制/审计 | 无凭据测试替身通过；真实联调明确记录；只生成草稿且不覆盖人工内容 |
| 后续前台阶段（待单独授权） | 原创首页/列表/详情、双语路由、SEO、真实 WPML 适配 | 用户确认范围后实施，不能提前开发 |
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

按用户 Phase 2 最新要求，项目采用分阶段开发、最后统一全面测试：本阶段只执行必要 PHP 语法、前端构建和基本代码检查，修复发现的明显问题。CI 的 push/pull_request 自动运行 PHP 8.3/8.4 及资源构建，数据库/WordPress 基线保留为 workflow_dispatch 的 final_qa 手动任务，不随每次提交执行。

完整 MySQL、WordPress、WPML、浏览器兼容、并发和端到端验收统一放到 Final QA。至少包括：八表结构及 1.1→1.2 数据保留迁移、重复安装、并发修订/审核竞争、人工来源/去重、双语完整性、各角色与 nonce、编辑后审核失效、归档确认及恢复、分类循环、关联归档保留、私有预览、XSS/SQL 注入、主题切换数据保留、真实 WPML/多语言路由及浏览器操作流程。现有 tests/integration/core.php 为可复用基线，不等同完整 Final QA。

Phase 1 已完成测试作为历史证据保存在其报告，不能用于宣称新代码通过集成。接口替身不能证明真实 WPML 兼容；语法检查不能证明数据库迁移或后台运行成功。每个未执行项目须明确标记，禁止以“预计通过”代替证据。

每阶段提交应关联规格和验收证据；报告注明未运行测试及原因。变更规格时同步 README/阶段报告，不能把计划当现状。分支推送用普通 fast-forward；远端已有并发变更时先检查并整合，禁止覆盖历史。用户关于代码同步仓库的要求持续有效。
