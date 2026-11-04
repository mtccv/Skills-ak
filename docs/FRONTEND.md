# Phase 3 双语前台使用与维护

前台复用 Phase 1/2 的八张 Core 表，数据库 schema 仍为 `1.2.0`。没有注册 Skills 文章副本，没有新增业务表，也没有自动导入或生成内容。教程、说明页面和私有来信使用 WordPress 原生 posts/postmeta 存储。

## 启用与真实内容准备

1. 在 Codex Cloud 按 README 构建主题资源并启用主题，然后以管理员身份访问一次 WordPress 后台。`Router::refreshRewrites()` 在首次 `admin_init` 检查 `aish_frontend_routes_version`：原来使用普通链接时改为 `/%postname%/`，已有固定链接设置则保留；注册双语规则并刷新重写。也可在“设置 → 固定链接”保存一次重试。Web 服务需要将路径请求交给 WordPress；不要求用户本机开发或外部部署。
2. 在 AI Skills Hub → Skills 新建或编辑真实 Skill。人工资源不填写仓库和路径；GitHub 来源继续使用既有仓库 ID 与规范相对路径。分别填写实际拥有的中文/英文内容；缺少译文时保持空白。
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

列表使用 GET 参数 `q`、`category`、`tag`、`platform`、`sort`、`page`。`sort` 允许 `newest`、`oldest`、`title`；公开页面每页 12 项，API 上限 24 项；page 上限 10000，搜索最多 100 个字符/400 UTF-8 字节。筛选值为现有分类/标签 Slug；平台值使用完整 `platform-*` Slug。不存在的详情、无效参数和越界空分页返回 404；数据读取失败返回 503 与通用错误提示，不输出 SQL。请求不会访问 GitHub 或 AI 服务。

分类、标签和平台选项按当前语言可见的已发布 Skill 统计；选项最多返回 200 项，每个 Skill 展示的分类/标签关联各最多 100 项。分类与标签名称仍使用原表中的共享名称，不自动生成中英文副本。

## 精选、平台、许可证与详情内容

在已有 Tags 管理页创建和维护以下约定，不需要新表或新字段：

| 标签 Slug 约定 | 前台用途 | 编辑规则 |
| --- | --- | --- |
| `featured` | 首页精选 Skills | 仅主动关联该标签的已审核发布资源进入精选；没有记录时显示空状态 |
| `platform-*` | 支持平台、平台筛选 | 标签名称是显示名称；根据实际兼容信息建立，例如有依据时使用 `platform-codex`，不会预置支持声明 |
| `license-*` | 许可证信息 | 标签名称是实际许可证名称；确认来源许可证后再关联，缺失时明确未提供 |

这些仍然是普通 Core 标签；可在后台编辑、归档和关联。标签归档不删除数据，前台不再使用已归档标记。

Skill 作者没有独立字段，因此前台不把仓库 Owner 或审核人员冒充作者；仓库 Owner 单独标为维护者，作者缺失时如实显示。版本仅返回审核时间之前已存在的历史 SHA/标签/时间，不返回原始快照。现有版本表没有仓库路径或审核修订绑定，故 `version_source_verified=false`；记录的 SHA 不代表已验证的当前来源版本。GitHub 文件链接使用 `/blob/HEAD/{path}`，不会用旧 SHA 拼接可能已经变更的仓库路径。

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
- `_aish_translation_id`：可选另一语言教程 ID。保存时验证类型、语言和编辑权限，并维护双向关联；已有其他配对时拒绝覆盖。公开切换还要求两个教程都已发布、无密码保护、语言正确且相互指向。
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

公开 HTML 发送 `private, no-store`，防止资源撤下后主题自身仍复用缓存页面。接入第三方页面缓存/CDN 时需保留此规则，并在 Final QA 验证撤稿生效；本阶段没有部署缓存或外部站点。

## API 与扩展接口

`FrontendModule` 在 Core 注册 `public_catalog`、`editorial`、`frontend` 服务，依赖既有 database/language/catalog 模块。模板通过 `Router::context()`、`View` 和 WordPress `get_template_part()` 渲染；业务查询不放入 `functions.php`。

主要接口：`PublicCatalog::skills(locale, filters)` 返回 items/total/page/per_page；`detail(id, locale)` 返回公开详情或 null；`alternatives(id)` 返回 locale→slug；`terms(kind, locale)`、`term(kind, slug, locale)`、`platforms(locale)`、`featured(locale, limit)`、`latest(locale, limit)`、`related(id, locale, limit)` 提供列表数据。`Editorial` 提供 `tutorials`、`tutorial`、`tutorialAlternatives`、`relatedTutorials`、`page`。`View::sections()` 读取已存正文区块，`Strings` 提供两种语言的界面文案。

| Filter | 参数与用途 |
| --- | --- |
| `aish_core_language_provider` | 既有 LanguageProvider 注入接口；外部对象 ID 不能替代内部 Skill ID |
| `aish_frontend_builtin_routing` | `(bool enabled)`；默认 true，可由适配层关闭内置路径匹配/规则 |
| `aish_frontend_request` | `(null\|array request, string path)`；适配层可返回 route/locale 及可选 id/slug，路由仍验证允许值 |
| `aish_frontend_url` | `(string url, string route, array args, string locale)`；统一调整站内页面、语言切换及表单返回地址 |
| `aish_frontend_alternates` | `(array alternates, array context)`；调整语言地址，主题只输出支持语言的字符串 URL |
| `aish_frontend_seo_enabled` | `(bool enabled, array context)`；关闭主题基础 description/canonical/hreflang 输出，方便 SEO 插件统一管理 |
| `aish_editorial_translation_id` | `(mixed translation_id, int source_post_id, string target_locale)`；教程映射须通过公开状态、语言及反向配对检查 |

WPML 接入时应协调请求映射、URL 与语言关系，可关闭内置路由并自行刷新相应 rewrite；不能重复拥有 Core 的 Skill 翻译关系。以上为可接入接口，不表示真实 WPML 插件已验证兼容。

主题仅提供语义标题、基础描述、canonical/hreflang 和面包屑。详情 hreflang 只列出实际可公开的译文；高级 Sitemap、搜索索引策略和完整 SEO 优化不在 Phase 3 范围。

## 验证范围

本文件描述当前实现和编辑方式，不作为集成通过证明。本阶段按用户策略仅做必要 PHP、构建及基础代码检查；实际执行结果由 Phase 3 报告记录。完整 MySQL、WordPress、WPML、浏览器、并发、表单滥用/缓存及端到端验收统一留到 Final QA。没有写入演示数据，也没有执行自动采集、AI 内容生成或外部部署。
