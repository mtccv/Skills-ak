# Phase 4 搜索、SEO 与 Sitemap

本阶段扩展现有 Frontend 模块，不创建 Skills CPT 或重复业务表。主题/Core 为 0.5.0；schema 1.3.0 仅新增两个非唯一索引。首次升级前备份数据库，然后使用授权管理员后台请求或既有可信 WP-CLI 流程完成迁移；普通前台不执行 DDL。管理员访问后台会将前台重写标记更新为 2，注册 Sitemap 规则，保留已有固定链接设置。

## 搜索行为

Skills 和教程均按当前 `zh_CN` / `en_US` 搜索实际标题、简介/摘要和正文。关键词作为完整输入的字面子串匹配；百分号、下划线及连字符不会成为 SQL 通配或 WordPress 排除表达式。最多 100 个 Unicode 字符、400 UTF-8 字节，值通过 `esc_like` 和 prepared SQL 绑定。

`q`、`category`、`tag`、`platform` 可组合，`sort` 为 newest / oldest / title，`page` 为 1–10000；每页 12 条，内部公开列表上限 24。时间排序采用更新时间及稳定 ID，标题排序以 ID 处理同名。提交筛选回到第一页；分页和语言切换保留有效参数，语言切换回到第一页。表单保留超出前 200 个选项的已选 slug，避免静默丢失筛选。

教程通过现有 `_aish_related_skill` 关联筛选：必须有同一个当前语言可公开 Skill 同时满足所选分类、标签和平台。未关联 Skill 的教程仍可正常关键词搜索。综合搜索展示 Skills 分页和匹配教程入口，教程入口保留组合筛选；教程列表提供独立分页和空状态。

公开 Skill 要求 published + approved + reviewed_revision=revision + 有效审核人员/时间 + 当前语言标题、slug、简介、正文完整。教程要求 publish、无密码、明确语言、实际非空标题和正文。noindex 只影响索引，不等同私有，仍可在站内搜索出现；未审核或私有内容始终不公开。

PublicCatalog 请求内复用相同查询和计数，卡片分类/标签批量加载；教程关联 Skills 使用 `cardsByIds()`，不逐条读取详情/版本。Editorial 批量预取文章元数据和作者，查询结果只在当前请求缓存。关闭 WP_Query 持久结果缓存，因为 Core 自定义表的审核/归档不会推进 WordPress posts 缓存版本。HTTP 页面/XML 继续 no-store，未引入跨请求公开结果缓存。

1.3.0 新增 `skills(status,review_status,updated_at,id)` 和 `skill_translations(locale,title,skill_id)`，保留全部字段、主键和唯一约束。索引用于公开候选查询和排序；前导 `%` 的中文子串查询仍可能扫描候选正文，没有声称实现全文索引或通过性能基准。MySQL EXPLAIN、数据规模、并发撤稿及迁移测试留 Final QA。

## 编辑 SEO

AI Skills Hub → SEO 按页面类型、语言及可选实体 ID 打开记录。首页、Skills 列表、教程列表、About、Contact 可分别维护中英文 SEO Title、Meta Description、可选 HTTPS 分享图片和 noindex。分类/标签按原 ID 与语言独立配置；名称仍是已有共享名称，未生成分类译文。

Skill 的 SEO Title/Description 继续由 Skills 双语编辑器维护，使用原翻译表字段及现有审核流程；SEO 设置页仅配置其分享图片与 noindex。教程和说明页面编辑器新增 SEO 面板，使用 `_aish_seo_title`、`_aish_seo_description`、`_aish_seo_image`、`_aish_seo_noindex` 原生 postmeta。教程配对的两篇文章分别配置，不互相复制。说明页的文章 SEO 非空值优先于页面类型设置，noindex 为两处任意一处开启即生效。

留空使用当前语言真实标题/摘要或内置页面说明，不生成译文或图片。分享标签沿用该语言 SEO 标题和描述，配置图片时输出 summary_large_image，否则为 summary。配置通过 nonce、capability 和字段校验；Options API 使用非 autoload 单项，最多 1000 条非默认覆盖，目标指纹和数据库锁防止覆盖并行编辑。

Canonical 自动由真实当前语言路由生成，不能输入跨语言 Canonical。分页使用带 page 的自身地址，筛选保留规范化有效参数；未知参数不进入 Canonical。SEO 设置不改变发布权限。Submit 始终 noindex，配置不能绕过此规则。

## 多语言与插件所有权

每个可索引页面输出自身 Canonical。hreflang 只来自通过公开校验的实际语言备选，并排除相应语言的 noindex 配置；详情缺译不产生虚假链接。About/Contact 缺少实际已发布说明正文时，表单/通用说明仍可访问，但该语言页 noindex 且不进入语言备选或 Sitemap。分类/标签要求当前语言有公开资源。

纯分页页面保留 self Canonical，不宣称不同语言的第 N 页一一对应。搜索、筛选等 noindex 页面不输出 hreflang。x-default 指向可用的站点默认语言版本；默认语言缺失时指向真实存在的备选版本，不指向不存在译文。

内置 `/zh/`、`/en/` 以 WordPress 配置的站点根地址构建，避免语言插件对 `home_url` 添加目录后再次叠加语言。原有 `aish_frontend_builtin_routing`、`aish_frontend_request`、`aish_frontend_url` 继续支持自定义路由/语言域名适配。WPML 教程关系使用公开 `wpml_object_id`，`return_original_if_missing=false`，并再次验证语言元数据、发布状态和反向关系；`aish_wpml_language_code` 可映射默认 zh-hans/en 代码。Core Skills 始终使用稳定内部 ID，不交给 WPML 生成影子文章。

Hub 虚拟 SSR 页面由主题统一拥有 Meta 输出：Yoast 使用公开 `wpseo_frontend_presenters` filter 返回空 presenters；Rank Math 使用公开 `rank_math/head` action 清除该请求的插件输出；WPML 的 `wpml_hreflangs` 在这些页面关闭重复输出。主题输出一次 Description、Canonical、OG、Twitter 和经过验证的 hreflang；标题使用最终优先级，robots 使用 WordPress `wp_robots`。其他 WordPress 页面保留插件行为。

`aish_frontend_seo_enabled=false` 可把 Hub 页面输出交给自定义适配器；此时适配器必须完整接管 Meta/hreflang。HTTP noindex 安全边界仍生效。`aish_frontend_alternates` 只能调整已经验证的语言 URL，不能追加缺失语言。WPML 多域名/自定义目录需要上述请求与 URL 适配器，不能仅安装插件便宣称所有模式自动联调完成。

以上为代码接入与合同检查，**真实 WPML、Yoast、Rank Math 版本兼容和单标签数量尚未完成运行验收**。

## XML Sitemap

统一入口 `/aish-sitemap.xml`，子图为 `/aish-sitemap-{skills|category|tag|tutorials|pages}-{zh|en}-{page}.xml`，每份最多 200 个 URL。公开投影在 COUNT 和分页前排除显式 noindex，避免每条记录重复查询。分类/标签只收录未归档且当前语言有公开 Skills 的记录；教程同样排除私有、密码保护及缺少实际正文的记录。

静态项包括两种语言的首页、Skills/教程列表，以及确有该语言说明正文的 About/Contact。排除 Submit、搜索、排序/筛选 URL、分页 URL、预览、私有收件箱、404 和缺译。详情使用稳定 ID 和实际语言 slug。实体 lastmod 使用实际数据库 UTC 时间；分类/标签聚合公开关联更新时间；生成型列表不伪造 lastmod。修改 SEO 配置不会伪造正文更新时间。

WordPress 原生 Sitemap provider、Yoast 的 `wpseo_sitemap_index`、Rank Math 的 `rank_math/sitemap/index` 引用同一批子图，优先 Yoast、其次 Rank Math、否则 WordPress；不再生成平行 URL 集合。托管的 About/Contact/Submit 原生 page 从这些 Sitemap 的默认文章入口排除，避免原生永久链接重复。站点应只选择一个通用 SEO 插件；此代码只协调 Hub 内容所有权，不替其他插件处理互相之间的全部冲突。

`blog_public=0` 或 `aish_sitemaps_enabled=false` 时关闭 Hub Sitemap。无效/越界子图返回 404；数据库失败返回 503 + Retry-After，不把半份 XML 当成功结果。XML 转义，返回 no-store 和 noindex/follow；Sitemap 本身不作为普通内容收录。

## robots.txt 与索引策略

| URL 类型 | 策略 |
| --- | --- |
| 干净的公开实体、首页/列表、真实说明页 | 可索引，self Canonical，按实际译文输出 hreflang |
| 只有合法 page 参数的列表分页 | 可索引、self Canonical；不加入 Sitemap/hreflang |
| 搜索、分类/标签/平台组合参数、排序参数、未知参数 | noindex/follow/noarchive；保留规范化 Canonical |
| Contact/Submit 表单结果、翻译回退提示参数 | noindex/follow/noarchive；Canonical 去掉提示参数 |
| Submit、无正文 About/Contact、空分类/标签 | noindex，不进入 Sitemap |
| 404、503 | noindex，无 Canonical/hreflang，保留真实 HTTP 状态 |
| WordPress 后台、登录、私有审核预览 | noindex/nofollow/noarchive；权限与 nonce 仍是访问控制 |

WordPress 虚拟 robots.txt 保留现有后台 Disallow 和 admin-ajax Allow，不添加会遮住 noindex 的搜索/筛选参数 Disallow。没有现成 Sitemap 指令且 Hub Sitemap 启用时补充其入口；存在原生/SEO 插件声明时保留。站点关闭搜索引擎可见性时返回 `User-agent: *` / `Disallow: /`，不广告 Sitemap。

robots.txt 不是访问控制，Disallow 也不能代替 noindex。物理 robots.txt 可能在到达 WordPress 前由 Web 服务直接返回，主题无法修改它；部署/Final QA 须核对实际响应。仓库不新增会覆盖 WordPress 动态规则的物理 robots.txt，也没有部署外部服务器。

## Final QA 待执行

真实 MySQL 1.2→1.3 重复升级及数据保留；组合查询/空内容边界、执行计划和大数据分页；WordPress 重写/子目录/HTTPS/物理 robots.txt；编辑、归档、noindex 即时撤回和持久对象缓存；完整角色/nonce、保存并发；WPML 实际语言代码/目录/域名；Yoast/Rank Math Meta 数量与 Sitemap 缓存；抓取全部 XML URL 验证状态/Canonical/hreflang；浏览器、并发和端到端。当前隔离 PHP 检查不能替代这些验收。
