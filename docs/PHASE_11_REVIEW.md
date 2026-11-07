# Phase 11 集成复查记录

日期：2026-11-07。本记录只覆盖第二名开发代理对发现页、查询、Sitemap、模块接入及旧功能兼容的独立功能复查；不是 Final QA 或真实 WPML/第三方插件验收。

| 编号 | 已确认问题 | 修复位置与处理 | 验证与状态 |
| --- | --- | --- | --- |
| R11-01 / P2 | Skill 只关联子分类时，分类查询未返回没有直接资源的父分类，筛选器无法显示分类层级。 | `Frontend/PublicCatalog.php` 批量补齐受限、循环安全的祖先分类；保留真实直接资源数量，`Frontend/View.php` 生成层级名称。 | `tests/basic/public-search.php` 验证子分类独占关联、父分类零直接资源、一次祖先查询；隔离测试通过。 |
| R11-02 / P2 | `/editors-picks/` 与 `/collections/` 展示同一目录，却各自输出 Canonical。 | `Discovery/Frontend.php` 统一生成 `/collections/`，旧入口 301 跳转并保留安全参数。 | 隔离 URL 回归通过；真实隔离 WordPress HTTP 请求 `/en/editors-picks/?q=tools&page=2` 返回 301，Location 为 `/en/collections/?page=2&q=tools`。 |
| R11-03 / P2 | 固定平台详情仍显示其他平台选项，查询却静默覆盖所选平台，筛选与结果不一致。 | `templates/discovery/page.php` 隐藏固定平台详情的重复平台选择器；`Discovery/Frontend.php` 拒绝冲突的平台参数，保留分类、标签和关键词筛选。 | `tests/basic/discovery-frontend.php` 验证表单不再输出平台选择器、仍有分类和标签；隔离测试通过。 |
| R11-04 / P2 | 首页模块接管后，旧 Router 仍提前读取未使用的平台与教程数据，教程模块再次查询。 | `Frontend/Router.php` 检测发现首页钩子后，只为 Hero 读取单条精选/最新卡片并复用分类；模块负责其余数据，旧分支保留。 | 代码检查确认不再执行旧平台/教程读取；既有 `tests/basic/frontend-routing.php` 隔离回归通过。未据此声称性能基准提升。 |
| R11-05 / P2 | 新发现路由忽略既有 `aish_frontend_builtin_routing`、`aish_frontend_url` 适配接口，语言域名及关闭内置路由配置未生效。 | `Discovery/Frontend.php` 尊重内置路由开关；`Frontend/Router.php::adaptedUrl()` 统一 URL 适配与验证。适配异常关闭当前响应的内容输出，展示链接使用经过验证的内置回退。 | `tests/basic/discovery-frontend.php` 覆盖关闭路由、异常适配器、语言域名、无效协议/认证/片段及回退。仅为隔离适配测试，不代表真实 WPML 通过。 |
| R11-06 / P2 | 未分页的专题目录接受 `?page=999` 并返回同一目录、200 状态及 Page 999 标题，形成无效分页 URL。 | `Discovery/Frontend.php` 对没有详情 slug 的集合、场景及平台目录拒绝大于 1 的页码，返回 404 并设置 noindex。 | 真实隔离 WordPress HTTP 已复现原问题；同步最终主题副本后，最终浏览器/HTTP 回归确认专题目录 page=999 返回404，结果记录在tests/discovery/results/browser.json。 |

## 接入与回归检查

- 在独立 `p11qa-wp83` WordPress 环境启动 Core：实际 schema 为 `1.9.0`，`discovery` 模块已注册，Sitemap 注入的对象就是 Core 注册的 `discovery_query`，没有第二套查询服务。
- 只在当前 PHP 进程通过 `pre_option_aish_schema_version` 模拟 `1.8.0`：Discovery 与既有 PublicCatalog 都在执行 SQL 前拒绝读取；账户存储就绪检查及注册就绪检查关闭。移除过滤器后恢复正常。没有更改实际版本或数据库数据。
- 在真实 WordPress URL 工具上测试中文及 `& C++ 100%` 查询参数，分页 URL 经解析后与原输入一致，没有双重编码。
- 已运行新旧 Sitemap 隔离测试，覆盖分页超过 200 条、缺失语言、独立平台 URL、真实时间字段、私有站点及旧 XML 接口；未使用这些隔离结果声称真实 SEO 插件兼容。
- 已运行发现查询、输入、Schema、排序、前端，以及既有分类搜索、路由、迁移恢复和账户原生保护隔离测试。分类、公开资源与关联查询维持批量投影；未发现逐卡片查询。

真实 WordPress HTTP/启动检查使用项目独立 QA 服务与合成数据；没有访问生产数据、真实 API、真实邮件或合法授权 WPML 环境。全阶段测试数量和外部依赖限制由统一交付报告汇总，本记录不另行累加。
