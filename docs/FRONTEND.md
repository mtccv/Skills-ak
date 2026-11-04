# 双语前台、搜索与 SEO 使用维护（Phase 3–5）

前台复用 Phase 1/2 的八张 Core 表；Phase 3 交付为 schema `1.2.0`，Phase 4 的 `1.3.0` 追加两个非唯一搜索索引。Phase 0–4 已验收；当前 Phase 5 主题/Core 为 `0.6.0`、schema 为 `1.4.0`，保留全部前台/搜索/SEO 功能，仅为后台采集扩展仓库/版本元数据和 imports/jobs/import_logs 运营表。没有 Skills 文章副本，不自动生成译文；教程、说明页面和私有来信仍使用 WordPress 原生 posts/postmeta 存储。

## 启用与真实内容准备

1. 在 Codex Cloud 按 README 构建主题资源并启用主题，然后以管理员身份访问一次 WordPress 后台。`Router::refreshRewrites()` 在管理员 `admin_init` 检查 `aish_frontend_routes_version`（Phase 4 为 2，含 Sitemap 路由）：原来使用普通链接时改为 `/%postname%/`，已有固定链接设置则保留；注册双语规则并刷新重写。也可在“设置 → 固定链接”保存一次重试。Web 服务需要将路径请求交给 WordPress；不要求用户本机开发或外部部署。
2. 在 AI Skills Hub → Skills 新建或编辑真实 Skill。人工资源不填写仓库和路径；GitHub 来源继续使用既有仓库 ID 与规范相对路径。Phase 5 可从 GitHub Imports 中打开采集到的 pending Skill；原文仅为后台证据，需要人工分别填写实际拥有的中文/英文内容，缺少译文时保持空白。
3. 至少一种语言的标题、Slug、简介、正文完整后提交审核，由具有审核能力的用户批准。仅保存草稿不会出现在前台；修改已发布内容后需要重新审核。后台私有预览与公开详情是不同入口。
4. 按下面的标签约定维护精选、平台和许可证，再将标签关联到 Skill；新关联会使原审核失效。没有相关记录时首页展示真实空状态，不填充演示卡片、虚构数量或用户评价。
5. 在“教程 / Tutorials”添加真实教程；在 WordPress “页面”添加 About、Contact、Submit a Skill 的实际说明。Contact 和投稿表单本身可以接收来信，说明正文为空时不会自动编造机构介绍、联系方式或投稿政策。

默认语言沿用 AI Skills Hub → Settings 的 `default_locale`，只接受 `zh_CN` 或 `en_US`。首页根路径 `/` 以 302 跳转到默认语言；原生 `/?s=关键词` 转到对应语言的搜索页面。

## 页面与 URL

下表中的 `{lang}` 为 `zh` 或 `en`。Skill URL 使用稳定内部 ID，Slug 只用于可读地址；标题或译文变化不会改变 ID。

| 页面 | 路径 |
| --- | --- |
| 首页 | `/{lang}/` |
| Skills 列表 | `/{lang}/skills/` |
| Skill 详情 | `/{lang}/skills/{skill_id}/{translation_slug}/` |
| 分类 | `/{lang}/category/{slug}/` |
| 标签 | `/{lang}/tag/{slug}/` |
| 教程列表 | `/{lang}/tutorials/` |
| 教程详情 | `/{lang}/tutorials/{post_id}/` |
| 搜索 | `/{lang}/search/?q=关键词` |
| About | `/{lang}/about/` |
| Contact | `/{lang}/contact/` |
| Submit a Skill | `/{lang}/submit/` |
| 404 | 无匹配的公开路径；`/{lang}/not-found/` 可访问该状态 |

列表使用 GET 参数 `q`、`category`、`tag`、`platform`、`sort`、`page`。`sort` 允许 `newest`、`oldest`、`title`；公开页面每页 12 项，API 上限 24 项；page 上限 10000，搜索最多 100 个字符/400 UTF-8 字节。筛选值为现有分类/标签 Slug；平台值使用完整 `platform-*` Slug。不存在的详情、无效参数和越界空分页返回 404；数据读取失败返回 503 与通用错误提示，不输出 SQL。前台页面处理不访问 GitHub 或 AI 服务；采集只在独立 Cron 小任务中执行。

分类、标签和平台选项按当前语言可见的已发布 Skill 统计；选项最多返回 200 项，每个 Skill 展示的分类/标签关联各最多 100 项。分类与标签名称仍使用原表中的共享名称，不自动生成中英文副本。

## Phase 4 搜索行为

Skills 关键词匹配当前语言标题、简介、正文；教程匹配当前语言标题、摘要、正文。关键词是字面子串：支持中英文混合，用户输入的 `%`、`_`、反斜杠会按字面量转义，负号不作排除运算，不自动翻译。匹配大小写遵循现有数据库 utf8mb4 排序规则。

Skills 与教程列表都支持 q/category/tag/platform/sort/page。分类、标签、平台共同限制同一个匹配 Skill。教程通过已维护的“相关 Skill ID”关联筛选：至少一个当前语言公开 Skill 同时满足所选条件；教程关键词只匹配教程自身。无关联的教程仍能在普通教程搜索/列表出现，只是不满足资源分类/标签/平台筛选。

综合搜索页分页展示 Skills，并给出最多 3 条匹配教程与完整教程结果入口；教程列表独立分页并保留全部筛选。newest/oldest 按更新时间，title 按标题，同值使用 ID 稳定排序。筛选无结果显示真实空状态，不返回另一语言或未审核资源填充结果。

公开查询复用本次请求中的结果/计数，关联 Skills 卡片、分类/标签、教程作者与元数据批量加载；没有跨请求的公开结果缓存。原生 WP_Query 结果缓存关闭，防止 Core 自定义表的归档未触发原生缓存失效。两个新索引支撑公开时间/语言标题访问路径；包含匹配和完整性 REGEXP 的真实规模性能仍待 MySQL EXPLAIN 与 Final QA 验证。

## 精选、平台、许可证与详情内容

在已有 Tags 管理页创建和维护以下约定，不需要新表或新字段：

| 标签 Slug 约定 | 前台用途 | 编辑规则 |
| --- | --- | --- |
| `featured` | 首页精选 Skills | 仅主动关联该标签的已审核发布资源进入精选；没有记录时显示空状态 |
| `platform-*` | 支持平台、平台筛选 | 标签名称是显示名称；根据实际兼容信息建立，例如有依据时使用 `platform-codex`，不会预置支持声明 |
| `license-*` | 许可证信息 | 标签名称是实际许可证名称；确认来源许可证后再关联，缺失时明确未提供 |

这些仍然是普通 Core 标签；可在后台编辑、归档和关联。标签归档不删除数据，前台不再使用已归档标记。

Skill 作者没有独立字段，因此前台不把仓库 Owner 或审核人员冒充作者；仓库 Owner 单独标为维护者，作者缺失时如实显示。版本仅返回审核时间之前已存在的历史 SHA/标签/时间，不返回原始快照。1.4.0 的新采集快照保存仓库/路径绑定，但仍未绑定人工审核修订，旧快照也可能没有来源证据，故前台继续保持 `version_source_verified=false`；记录的 SHA 不代表已审核的当前来源版本。GitHub 文件链接使用 `/blob/HEAD/{path}`，不会用旧 SHA 拼接可能已经变更的仓库路径。

使用场景、安装说明和依赖要求从当前已审核译文正文中提取，不新增存储。编辑时使用真正的二级标题（`<h2>`），后面写实际内容；支持以下标题，英文大小写不敏感：

| 详情区块 | 可识别的 H2 标题 |
| --- | --- |
| 使用场景 | 使用场景、适用场景、Use cases |
| 安装说明 | 安装说明、安装、Installation、Installation instructions |
| 依赖要求 | 依赖要求、依赖、Dependencies、Requirements |

每个区块读取首个匹配 H2 后直到下一个 H1/H2 的内容。没有匹配区块或 PHP DOM 扩展不可用时保留缺失状态，完整正文仍可阅读；不猜测安装命令、依赖或适用场景。富文本输出继续经过 KSES，正文内的 H1 调整为 H2，页面标题保留一个主 H1。

## 教程与说明页面编辑

`Editorial` 注册 `aish_tutorial`，仅通过受控双语路由公开读取；CPT 本身关闭公开查询、REST、原生 archive 和 rewrite。后台支持标题、正文、摘要、作者及修订，编辑能力为 `aish_manage_skills`。

教程的“AI Skills Hub 双语内容”面板维护：

- `_aish_locale`：明确选择中文或英文；未选择的教程不出现在双语前台。
- `_aish_translation_id`：可选另一语言教程 ID。保存时验证类型、语言和编辑权限，并维护双向关联；已有其他配对时拒绝覆盖。公开切换还要求两个教程都已发布、无密码保护、标题/正文非空、语言正确且相互指向。WPML 公开关系 API 可提供对应 ID，仍接受这些站点公开边界检查。
- `_aish_related_skill`：重复 postmeta 行，每行一个既有 Skill ID，最多 100 个。前台只链接通过 PublicCatalog 公开校验的资源；不会创建 Skills 副本。

建议先创建两篇实际译文并分别设置语言，再在其中一篇填写对应教程 ID。仅发布其中一种语言也可以；另一种语言不会自动生成。教程摘要缺失时从已有正文截取，不编造介绍；作者使用 WordPress 实际显示名称。

About、Contact 和 Submit a Skill 使用原生 `page`。在同一元信息面板设置 `_aish_locale` 和 `_aish_page_kind`（`about`、`contact`、`submit`）。每种语言/用途只发布一个页面；若重复，前台选择 ID 最小的已发布且无密码保护页面。缺少某语言页面时仅显示该语言的通用功能说明，不编造编辑正文或复制另一种语言内容。

## Contact 与投稿收件箱

用户已确认表单保存为后台私有记录。两个表单都提交到 `admin-post.php?action=aish_public_message`，仅接受 POST，并校验 nonce、语言、表单类型、字段长度、邮箱和隐藏蜜罐字段；GitHub 地址仅接受 HTTPS 的 github.com 仓库路径，不抓取该 URL。

通过后创建 `aish_message`，状态固定为 `private`，保存姓名、邮箱、语言、留言、可选 GitHub 地址及类型；不发送邮件，不调用外部服务，不直接创建或发布 Skill。存储失败返回错误，不假报成功。

AI Skills Hub → “来信与投稿 / Inbox”仅对拥有 `aish_manage_settings` 的用户开放。管理员查看原文后选择待处理、已处理或已归档，并记录内部备注。投稿需要另行人工录入 Skills，然后走现有审核流程。

该 CPT 关闭公开查询、搜索、REST、archive 和 rewrite，并在写入时强制 private（原生 trash 状态除外）。公开输出不包含留言、姓名、邮箱或内部备注。每个地址每小时窗口最多 5 次、全站每小时窗口最多 120 次；MySQL 连接锁协调计数，只保存通过站点盐生成的地址 HMAC、时间和数量，不保存原始 IP。地址取 `REMOTE_ADDR`，不信任客户端转发头；共享出口会共享地址额度。旧地址限流记录仅作为短期运行数据清理，不删除来信记录。

## 公开访问边界与语言行为

`PublicCatalog` 是公开 Skills 的唯一查询入口。列表、计数、详情、相关资源、分类/标签关联及语言替代查询统一要求：`status=published`、`review_status=approved`、`reviewed_revision=revision`、正修订号、正审核用户 ID，以及不早于 1970-01-01 的审核时间。请求语言的译文也必须完整；PHP 继续使用已有 `Management::translationComplete()` 防御性检查。

结果只返回公开字段。审核备注、审核人员身份、原始来源快照和管理状态详情不传给模板。草稿、待审核、拒绝、归档或审核已失效的资源通过公开 ID/Slug 请求均不可读取，即使访问者已登录管理员账号也不会绕过该规则。

直接访问缺失译文的已发布 Skill 时，以 302 跳转到真实存在的完整语言，并提示缺译；两种语言均不可公开时返回 404。语言切换优先使用同一个 Skill ID 的真实译文，缺译时跳到目标语言列表并提示；教程遵循已验证的双向配对。不会在 `/en/` 中伪装展示中文译文。Slug 不匹配的可见 Skill 地址以 301 跳到其规范地址。

公开 HTML 发送 `private, no-store`，Sitemap XML 发送 `no-store`，防止资源撤下后主题自身仍复用跨请求快照。接入第三方页面缓存/CDN 时需保留此规则，并在 Final QA 验证撤稿生效；本阶段没有部署缓存或外部站点。

## Phase 5 来源更新如何影响前台

采集新资源固定为 pending，并且不创建中文/英文翻译，因此不会进入列表、详情、搜索、语言备选或 Sitemap。编辑人员须在既有 Skills 编辑器补充真实内容，经 Reviews 批准当前修订后才能公开；后台“来源变更已处理”不是发布按钮。

已存在的来源更新仅保存 incoming 元数据、不可变原文版本和静态风险提示，不覆盖人工译文、SEO 字段、slug 或审核。原有已发布页面继续展示人工批准的内容；如需采用新来源，管理员编辑后按原规则重新审核。来源 missing/疑似移动也不会自动删除或归档公开资源，后台人员应核实并决定如何处理已有页面。扫描不完整时不推断来源删除。

采集的 License/依赖/风险均为后台证据，不自动变成公开 `license-*`、`platform-*` 标签或正文声明。静态检查不表示脚本已执行、平台已测试或绝对安全。既有 WPML 接口、独立双语 SEO、Canonical/hreflang、robots 和 Sitemap 门禁保持不变；具体采集操作见 [GITHUB_IMPORT.md](GITHUB_IMPORT.md)，信任边界见 [IMPORT_SECURITY.md](IMPORT_SECURITY.md)。

## API 与扩展接口

`FrontendModule` 在 Core 注册 `public_catalog`、`editorial`、`frontend` 服务，依赖既有 database/language/catalog 模块。模板通过 `Router::context()`、`View` 和 WordPress `get_template_part()` 渲染；业务查询不放入 `functions.php`。

主要接口：`PublicCatalog::skills(locale, filters)` 返回 items/total/page/per_page；`detail(id, locale)` 返回公开详情或 null；`alternatives(id)` 返回 locale→slug；`terms(kind, locale)`、`term(kind, slug, locale)`、`platforms(locale)`、`featured(locale, limit)`、`latest(locale, limit)`、`related(id, locale, limit)` 提供列表数据。Phase 4 增加 `cardsByIds(ids, locale)` 批量关联卡片和 `sitemapCount/sitemapItems` 紧凑分页投影。`matchingIdsSql` 仅供可信内部的教程关联筛选，不暴露给 HTTP。`Editorial` 提供 `tutorials`、`tutorial`、`tutorialAlternatives`、`relatedTutorials`、`page` 及教程 Sitemap count/items。`View::sections()` 读取已存正文区块，`Strings` 提供两种语言的界面文案。

| Filter | 参数与用途 |
| --- | --- |
| `aish_core_language_provider` | 既有 LanguageProvider 注入接口；外部对象 ID 不能替代内部 Skill ID |
| `aish_frontend_builtin_routing` | `(bool enabled)`；默认 true，可由适配层关闭内置路径匹配/规则 |
| `aish_frontend_request` | `(null\|array request, string path)`；适配层可返回 route/locale 及可选 id/slug，路由仍验证允许值 |
| `aish_frontend_url` | `(string url, string route, array args, string locale)`；统一调整站内页面、语言切换及表单返回地址 |
| `aish_frontend_alternates` | `(array alternates, array context)`；调整已经通过公开检查的语言地址，不能借此创造缺失译文 |
| `aish_frontend_seo_enabled` | `(bool enabled, array context)`；默认主题统一输出 Meta，关闭后由适配器拥有标签；独立的安全 noindex HTTP 标记仍生效 |
| `aish_editorial_translation_id` | `(mixed translation_id, int source_post_id, string target_locale)`；教程映射须通过公开状态、语言及反向配对检查 |
| `aish_wpml_language_code` | `(string code, string locale)`；默认 zh_CN→zh-hans、en_US→en，供 WPML 公开 wpml_object_id API 使用 |
| `aish_sitemaps_enabled` | `(bool enabled)`；关闭主题 XML 源及索引接入，交由其他已配置 Sitemap 适配器负责 |

WPML 接入时应协调请求映射、URL 与语言关系，可关闭内置路由并自行刷新相应 rewrite；不能重复拥有 Core 的 Skill 翻译关系。Phase 4 在 wpml_object_id hook 可用时读取教程翻译，不回退原文来伪造对应翻译；同时继续验证本地语言元数据、公开状态和双向关系。Hub 的 hreflang 由主题统一输出，以 wpml_hreflangs filter 避免重复；这些接口不表示真实插件运行兼容已验收。

## Phase 4 SEO 配置、索引与 Sitemap

AI Skills Hub → SEO 为中文/英文分别配置首页、Skills/教程列表、About/Contact、分类/标签的标题、描述、分享图片和 noindex；实体设置按真实 ID 选择。Skill 标题与描述仍在 Skills 双语编辑器的现有 SEO 字段维护并审核，SEO 子菜单只管理其分享图片/索引。教程与原生说明页编辑器另有 AI Skills Hub SEO 面板，配置作用于该文章语言；没有 SEO 文本时使用当前语言真实内容。

Canonical 自动使用当前语言规范地址，不能在后台任意填成另一语言；Open Graph/Twitter 标题、描述共用该语言 SEO 配置，图片为可选 HTTPS URL。未配置图片不输出虚构图片，Twitter 使用 summary。真实可索引译文输出 hreflang，x-default 优先选实际可用的默认语言；缺译、noindex 或未经验证的分页不会产生伪语言链接。

Hub SSR 路由由主题统一拥有标签，使用 Yoast public presenters、Rank Math frontend head action 和 WPML hreflang hooks 避免重复；其他原生页面继续由插件处理。若改用适配器输出，使用 aish_frontend_seo_enabled，但需同步处理标题、canonical、语言关系与 robots，不能只关闭主题后假设插件理解自定义 Skills 表。

默认 noindex 包括搜索、任何筛选/排序参数（包含显式默认排序）、表单结果、投稿页、未知参数、无真实说明页的 About/Contact、空分类/标签及错误页。正常列表的合法纯 page 分页可 self-canonical，但不加入 Sitemap；筛选状态仍保留 URL，不通过删除参数损坏搜索体验。私有预览和后台继续要求权限，noindex 不是访问控制。

XML 索引为 `/aish-sitemap.xml`，子文件形如 `/aish-sitemap-skills-zh-1.xml`，类型覆盖 skills/category/tag/tutorials/pages，两种语言独立、每文件 200 URL。仅收录已发布/当前审核有效/真实译文/允许索引的资源；分类标签需存在本语言公开 Skills，About/Contact 需有真实已发布说明页。页面无可靠日期时不伪造 lastmod；实体使用现有 UTC 更新日期。noindex 在 count/paging 前排除。

原生 WordPress、Yoast、Rank Math 索引通过公开扩展点引用同一组 XML 子文件；原生 About/Contact/Submit 文章地址从其 Sitemap 排除，使用真实 Hub 路由避免重复。其他 Sitemap 提供者可由 aish_sitemaps_enabled 关闭本主题源后接管。第三方索引缓存、插件启停及撤稿生效需 Final QA 验证。

不创建静态 robots.txt；使用 WordPress 虚拟输出，保留后台和已有 Sitemap 规则，公开站点缺少 Sitemap 行时补充 Hub 索引。搜索/参数 URL 保持可抓取，让爬虫读取 noindex；关闭站点搜索引擎可见性时 noindex、停用主题 Sitemap、robots.txt 输出 Disallow: /。服务器若已有物理 robots.txt 会绕过 WordPress filter，最终环境需要实际核查。详细维护说明见 [SEO.md](SEO.md)。

## 验证范围

本文件描述当前实现和编辑方式，不作为集成通过证明。本阶段按用户策略仅做必要 PHP、构建及基础代码检查；前台/搜索/SEO 历史结果见 Phase 3/4 报告，采集模拟检查见 Phase 5 报告。完整 MySQL、WordPress、WPML、浏览器、并发、表单滥用/缓存及端到端验收统一留到 Final QA。本轮没有写入演示数据或调用真实 GitHub API，模拟采集不代表真实环境通过；未开发 AI 内容生成或部署外部站点。
