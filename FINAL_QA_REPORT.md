# Final QA — AI Skills Hub

## 总体验收结论

**已验证范围通过，可以交付安装包；整体外部验收仍有保留，不能宣布完全通过。** 没有已知未修复 P0/P1 或数据丢失问题；真实 WPML、GitHub/AI 服务和生成质量/账单尚未验证。未部署外部服务器。

基线：`d6401c2d6fb810605f5ba87524301ecc50418971`。主题/Core **0.7.1**，schema **1.5.0**；保留原十四表、稳定 Skill ID、双语关系、人工内容与历史。所有外部文件只作数据，不执行未知 Skill、不自动发布。

## 计数口径与矩阵

按下面 **25 个验收工作包**统计：**20 通过、0 失败、5 未执行**。这是工作包数，不把 HTTP 请求、PHP 文件、断言和重复版本矩阵混为一类。修复前失败另记为 7 个已修复问题，不计为最终仍失败；额外环境边界见 KNOWN_LIMITATIONS.md。机器可读汇总：[qa-summary.json](tests/final/results/qa-summary.json)。

| 编号 | 验收工作包 | 最终状态 / 证据性质 |
| --- | --- | --- |
| T01 | 真实 WordPress 全新安装与最终 ZIP 启用 | 真实环境通过 |
| T02 | 原生主题升级、重装及文件一致性 | 真实环境通过 |
| T03 | 停用、重新启用与数据保留 | 真实环境通过 |
| T04 | 十四表、旧版逐级迁移及重复安装 | 真实环境通过 |
| T05 | 唯一键、事务、并发、锁及失败恢复 | 真实环境通过 |
| T06 | 原生 Skills 双语编辑、审核与归档 | 真实环境通过 |
| T07 | 分类、标签、仓库与权限操作 | 真实环境通过 |
| T08 | 内置双语关联、切换及缺译门禁 | 真实环境通过 |
| T09 | 教程、Contact/Submit 及私有 Inbox | 真实环境通过 |
| T10 | 三引擎与桌面/平板/手机视口 | 真实环境通过 |
| T11 | 中英搜索、组合筛选、排序和分页 | 真实环境通过 |
| T12 | Meta、Canonical、hreflang、Schema 和面包屑 | 真实环境通过 |
| T13 | Sitemap、robots、SEO 插件与缓存状态变化 | 真实环境通过 |
| T14 | 权限/Nonce/SQL/XSS/私有门禁及安全 URL | 真实入口通过；外部网络边界另有 Mock |
| T15 | GitHub 来源发现/增量/恢复的真实后端 | 真实 WP/MySQL 后端通过，GitHub HTTP Mock |
| T16 | AI 任务/并发/历史/人工审核的真实后端 | 真实 WP/MySQL 后端通过，Provider Mock |
| T17 | 真实 wp-cron.php 回调及重复任务保护 | 真实 HTTP Cron 通过，外部 HTTP Mock/阻断 |
| T18 | SQL/EXPLAIN、N+1、分页及有界并发计时 | 真实环境通过 |
| T19 | 两 PHP 版本语法与隔离/Mock 回归 | 静态与隔离/Mock 通过 |
| T20 | 前端构建、依赖审计及可复现打包 | 构建/审计/打包检查通过 |
| EXT-01 | 真实 WPML 集成 | 未执行；缺授权包/真实凭据或输出 |
| EXT-02 | 真实 GitHub API | 未执行；缺授权包/真实凭据或输出 |
| EXT-03 | 真实 OpenAI API | 未执行；缺授权包/真实凭据或输出 |
| EXT-04 | 真实 OpenAI 兼容服务 | 未执行；缺授权包/真实凭据或输出 |
| EXT-05 | 真实生成质量/注入抗性/供应商账单 | 未执行；缺授权包/真实凭据或输出 |

## 实际环境与详细数字

Docker 已实际启动并运行测试。Cloud VFS 镜像存储问题在业务数据建立前解决，官方镜像文件系统扁平化后运行；来源 digest、PHP 扩展及设置见 [环境证据](tests/final/results/environment.json) 与 [复现说明](tests/final/ENVIRONMENT.md)。所有库/卷都是本次新建，Web 只绑定 localhost，MySQL 无宿主端口。

| 测试 | 实际结果 |
| --- | --- |
| WordPress/PHP/MySQL | 主环境 WP 7.1.4，PHP 8.3.36 / 8.4.27，MySQL 8.0.46；最低声明 WP 6.8 独立实测 |
| 数据库三环境 | 每套 11 组 / 102 断言 + 124 Core 断言，合计 678 真实断言通过；WP 6.8 另 5 路径通过 |
| 最终 ZIP | 全新安装 12 检查、升级/重装/切换 33 断言通过；共 50 次 HTTP，14 表全记录指纹不变，78 个已安装文件逐个匹配 ZIP |
| 浏览器 | Chromium 112、Firefox 52、WebKit 52，全部通过；实际持久化 13/13 |
| 安全 | 178 HTTP / 207 断言通过；另 28 HTTP 回归通过 |
| SEO | 四种插件模式共 5990 断言 / 1254 HTTP 通过，覆盖已存在缓存、撤回/归档/恢复、子图增减及私站 |
| GitHub 后端 | 21 组 / 145 断言通过；真实 DB/进程，外部 HTTP Mock |
| AI 后端 | PHP 8.3/8.4 各 29 用例 / 141 断言，合计 282；真实 DB/并发，Provider Mock |
| 实际 HTTP Cron | 11/11，通过 wp-cron.php 的 Apache 请求执行；不是只调用 do_action 冒充调度 |
| 性能 | 10 类 SQL × 3 轮，38 份 EXPLAIN；192/192 HTTP 成功，卡片 12/24 均 4 Core SQL，同请求 memo 新增 0 |
| PHP / 隔离 | 两版本各 120 文件语法通过、29 组 / 2710 隔离/Mock 断言通过 |
| 前端/打包 | Tailwind + esbuild、assets、Python/JS/Bash 检查通过；打包 8 单元测试通过，npm audit 0 个已知漏洞 |

完整证据位于 [tests/final/results](tests/final/results)。初始过严测试假设、权限/文件系统准备、strict_types 加载器错误及修正都保留说明，没有当作成功或产品漏洞。首次全新安装只读校验使用了不存在的 Core getter，改为真实服务注册表后重新执行，未修改产品逻辑。

## 已修复问题

| 编号 | 等级 | 实际问题与最终处理 |
| --- | --- | --- |
| QA-01 | P1 | 含空格/保留字符搜索和组合筛选 503；正确编码 WordPress 新查询参数，三引擎回归 |
| QA-02 | P2 | Yoast 导致 Hub 缺失 title；恢复本路由原生 title renderer |
| QA-03 | P2 | 插件覆盖 robots 私站/后台规则；最终优先级落实可见性策略 |
| QA-04 | P2 | 缺少 Schema.org；增加真实且安全编码的 WebSite/WebPage/BreadcrumbList |
| QA-05 | P2 | Rank Math stdClass entry 契约导致原生页面重复收录；兼容真实对象类型 |
| QA-06 | P2 | XML 缓存绕过动态 Hub 索引；用公开 filters 禁用插件 Sitemap 缓存，测试子图增减 |
| QA-07 | P2 | 双插件实际 renderer 与常量优先级不一致而漏收录；两个公开 hook 按精确 loc 幂等追加 |

先记录的触发证据与修复前/后结果见 [SEO_FINDINGS.md](tests/final/SEO_FINDINGS.md)。生产修复集中在 Router、Seo、Sitemap；未改变业务表设计、迁移版本或人工发布规则。构建依赖未新增。

## 安全、性能和未解决项

[SECURITY_AUDIT.md](SECURITY_AUDIT.md) 记录权限/数据边界和测试过程的临时凭据事件；两套 QA 密码已轮换、会话已撤销，没有生产/API 凭据进入 Git。[COMPATIBILITY_REPORT.md](COMPATIBILITY_REPORT.md) 列实际版本和真实插件/浏览器范围。[PERFORMANCE_REPORT.md](PERFORMANCE_REPORT.md) 给出实测数值和共享宿主限制，不承诺生产容量。

五个外部工作包仍需管理员提供已有合法测试配置才能完成；没有把 Mock 当作真实 API，也没有购买服务。持久缓存、多站点、真实 Safari/iOS、生产 HTTPS、长期大规模压力及精确 COMMIT 确认丢失窗口不在已验证覆盖内。分类/标签沿用共享名称设计，不宣称已有独立字段翻译。详见 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)。

## 安装包、提交与复现

- 安装包：[ai-skills-hub-0.7.1.zip](release/ai-skills-hub-0.7.1.zip)，767776 bytes，78 文件。
- SHA256：`470e2ef14fc8c1692baf9fe8b4487483101c61f54bfbd5ab4f549e7b52c8f268`，对应 [SHA256SUMS](release/SHA256SUMS)。
- 已通过真实 WordPress 原生全新安装、0.7.0→0.7.1 升级、重复覆盖、停用和恢复，最终包并非开发源码目录的静态 ZIP。
- 完整安装、配置、密钥、Cron、备份和升级说明：[INSTALLATION.md](INSTALLATION.md)。
- 数据、浏览器、采集、AI、SEO、安全/性能及安装包复现说明位于 tests/final；测试凭据、数据库、第三方插件和 WordPress 核心不随 Git 或 ZIP 交付。

GitHub main 的最终 SHA 在交付回复和提交记录提供；本文件包含在对应提交中，不以自引用占位 SHA 冒充实际提交。远端 CI 结果如有补充，独立记录实际 SHA/环境，不能替代这里的 Cloud 实测。
