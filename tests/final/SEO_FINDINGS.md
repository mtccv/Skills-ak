# Final QA：双语与 SEO 发现登记

独立站点：Codex Cloud `aishqa-seo` / `qa_seo` / localhost:18084；WordPress 7.1.4、PHP 8.3.36、MySQL 8.0.46。实际 HTTP 与数据库，不以旧隔离测试代替集成。证据 JSON 保存在运行目录 `/tmp/aish-final-qa/seo-*.json`，不提交数据库、插件代码或凭据。

## 先记录的实际问题

| 编号 | 等级 | 实际触发与证据 | 最小修复范围 |
| --- | --- | --- | --- |
| SEO-01 | P1 | WordPress `add_query_arg()` 不对新增值自动 URL 编码；`q=QA Browser`、引号/空格组合在 Router::url 的适配器 URL 验证处抛异常，正常搜索得到 503。独立安全/浏览器 QA 已复现；之前替身用 http_build_query 隐藏了真实契约差异 | 查询值在调用 WordPress helper 前编码；保留原筛选语义，真实空格/中文/百分号/&/#/+ round trip 回归 |
| SEO-02 | P2 | Yoast SEO 28.8 删除 WordPress `_wp_render_title_tag`，主题清空 Yoast presenters 后无人输出 `<title>`。真实中英 Skill 与分页标题为空，其他 Meta 正常；`seo-yoast.json` 初轮 1042 项中相关 3 项失败 | 主题拥有 Hub SEO 时恢复原生 title-tag renderer；非 Hub 页面和关闭主题 SEO 所有权时保留插件行为 |
| SEO-03 | P2 | 主题 robots_txt priority 99 早于 Yoast 99999；最终输出失去 WordPress admin/公共 ajax 规则；blog_public=0 后又追加空 Disallow 与 Sitemap。`seo-yoast-private-before.json` 实际 17 项中 1 项失败 | 最终优先级执行站点可见性，私站不广告 Sitemap；公开结果缺少基本后台规则时补充，保留插件其他指令 |
| SEO-04 | P2 | 实际无插件首页、Skill 页面完全没有 schema.org/JSON-LD。旧规格/Phase 3–4 只实现语义 HTML/可见面包屑；本次 Final QA 明确要求结构化数据，属于已确认功能缺口 | 仅对主题拥有且公开可索引的 200 页面输出当前语言 WebSite/WebPage/BreadcrumbList；不生成评分、作者或安全认证 |

| SEO-05 | P2 | 真正启动 Rank Math 前台与 Sitemap 模块后，`page-sitemap.xml` 包含 3 个由 Hub About/Contact 双语 URL 代表的原生重复地址。其公开 `rank_math/sitemap/entry` 使用数据库 stdClass，现有排除回调只接受 WP_Post；`seo-rankmath-fixed.json` 1323 项中此 1 项失败 | 按公开 post entry 对象契约验证类型/ID/post_type，不新增数据查询，保留正常 WordPress 页面 |

| SEO-06 | P2 | Rank Math 的持久 XML 缓存命中跳过动态 `rank_math/sitemap/index`。真实发布后归档唯一公开中文 Skill，主题 index 已移除 `skills-zh-1`，插件 index 仍广告已 404 的 child；旧 page sitemap 也绕过 SEO-05 新排除回调。`seo-rankmath-cache-diagnosis.json` 保留两个实际 index 对照 | 通过插件公开 XML cache enable filter 保持动态 Hub 索引新鲜；不扩改业务写入、事务或数据模型 |

| SEO-07 | P2 | 关闭旧 XML 缓存后，Yoast 与 Rank Math 同时启用时真实 `/sitemap_index.xml` 由 Rank Math 渲染；既有 owner() 仅凭 Yoast 常量禁止 Rank Math 回调，导致全部 11 个 Hub 子图漏收录。此前旧 Rank Math cache 掩盖此问题；`seo-matrix-final/seo-both-final.json` 中 advertised=0 | 两个公开 index hook 均提供同一批子图，按精确 loc 幂等追加，遵循实际渲染方，不抢占插件路由 |

## 环境和测试契约纠正（不计产品缺陷）

- 初始只读挂载保留工作区 700/600 权限，Apache 无法读取主题，返回空白 200；CLI root 能执行。调整主题目录 755/文件 644 后正常。保留 `seo-none-first.json`，其失败不能算成功。
- 初轮脚本误把合法英文 `lang="en"` 判为必须 `en-US`。接受这两个合法值后，无插件矩阵 1042 项全部通过，273 请求、11 子 Sitemap、225 个收录 URL 全部实际抓取。原记录保留为 `seo-none-overstrict-language.json`。
- Rank Math 1.0.281 激活后未选择免费注册向导的“Skip”，其 registration.invalid 阻止所有实际前台/模块启动。使用插件公开 Skip 对应的 `rank_math_registration_skip` 选项再测试；不能把只存在版本常量当作插件联调通过。初轮 `seo-rankmath-before.json` 为配置诊断证据。
- 官方免费插件来自 downloads.wordpress.org：Yoast ZIP SHA256 `27cc01cb149db7186ef750552a5cc2ae5028933473886e419918af273b075105`；Rank Math `d3d464bb2b3b619e46cd93c16a8d21073c473bd411867c1c8d73110debd0ba29`。只安装到隔离站点。
- 没有提供合法 WPML 安装包，真实 WPML 联调未执行；内置双语和公开适配器测试不能代替 WPML。

最终修复与复测数字将在完成后追加，不把上述初始失败或计划标为通过。

## 最终修复和真实复测

SEO-01 至 SEO-07 已按最小范围修复。最终四模式矩阵 **5990 / 5990** 断言通过，**1254** 次真实 HTTP 请求；每模式 11 个子 XML、225 个索引 URL 全部实际抓取。`tests/final/results/seo-matrix.json` 是最终场景总表，`seo-status.json` 区分实际集成、隔离断言与未执行 WPML。

- 无插件与 Yoast 主矩阵各 1324 项；Rank Math 与双插件主矩阵各 1323 项，差异来自各插件原生 sitemap 数量。其余断言覆盖私站、编辑撤回、归档、两种重新审核恢复和默认语言切换。
- Rank Math 真实旧 XML 缓存保留；开启 QA 配置 fixture 显式请求插件缓存，主题公开过滤器阻止旧索引绕过动态投影。撤回/归档时空中文子图从插件 index 即时移除；恢复发布后即时加入，不依赖手动清缓存。
- 双插件 actual renderer 取得同一批子图；独立测试覆盖两个 hook 顺序和重复调用，精确 loc 使用线性去重，没有 XML 外部实体解析器。
- 隔离检查：Router 104、SEO 76、异常边界 83、Sitemap 49。旧 stub 的 URL 编码假设、一次 hook registry 测试变量碰撞均已纠正，不据此宣称真实集成。
- 自动审批额度曾临时阻止执行；恢复后完成以上实际复测，不把受阻命令记为通过。真实 WPML 仍未执行。
