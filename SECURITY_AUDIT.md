# Final QA 安全审查

基线 `d6401c2d6fb810605f5ba87524301ecc50418971`。本轮结合 Phase 7 历史审查、实际 WordPress/MySQL、多进程故障注入、浏览器及隔离回归；不是第三方渗透认证，也不宣称绝对安全。所有攻击样本仅针对新建 localhost QA 站，不访问生产、云 metadata 或其他私网服务。

## 实际覆盖与结果

真实安全矩阵执行 **178 次 HTTP / 207 项断言**，以及生产补丁后的 **28 项 HTTP 回归**，均通过。管理员、Subscriber、Contributor、Author、Editor 的请求使用真实 WordPress 会话；检查权限时使用该会话有效 nonce，同时另测错误/缺失 nonce，避免把 nonce 拒绝误认成 capability 保护。

| 安全边界 | 实际证据 |
| --- | --- |
| SQL 注入与输入验证 | 引号、注释、通配符、非法筛选/排序/分页作为字面数据或被拒绝；真实 SQL 查询及数据指纹核对，无未授权修改 |
| XSS 与内容输出 | 存储/反射 HTML、结构化数据安全编码、浏览器执行和页面输出检查；来源内容只作数据，未执行未知脚本 |
| CSRF / 权限 | 十类管理写入口的匿名、低权限有效 nonce、管理员错误 nonce、GET 写入拒绝；原生后台、编辑、审核和归档确认 |
| REST / AJAX | 未注册的 Hub 管理 REST/AJAX 不提供旁路；Inbox、教程和草稿的原生入口检查 |
| 私有内容 | draft/pending/rejected/archived、旧审核、缺审核人/时间、缺译、私有/密码教程及私有投稿不能通过公开 URL/搜索/XML 取得；编辑撤回和归档后立即重新核对 |
| 预览 | 授权能力和用户绑定 nonce、跨用户拒绝、noindex/缓存/CSP；预览不作为公开页面或 Sitemap 条目 |
| URL / 路径 / SSRF | 固定 GitHub API 域、AI HTTPS允许主机、非法来源路径/私网地址拒绝；真实 WordPress 安全请求拒绝受控的非本站 loopback 地址，其余 DNS/重定向/协议边界使用 Mock |
| 数据库错误脱敏 | 真实 MySQL duplicate-key 携带合成 canary，异常不携带原驱动信息/previous，debug.log 新增段无 canary；不是用真实 Key 作攻击样本 |
| 数据完整性 | 真实事务/行锁/唯一索引、独立进程来源去重、修订与人工采用竞争、租约 fencing、连接中断恢复、十四表迁移和主题生命周期 |
| AI/采集保护 | 原文、生成草稿、人工编辑和审核分开；不覆盖人工字段、不自动发布，不执行 Skill 或安装命令；真实数据库/队列结合明确 Mock HTTP |

详细入口、角色和断言见 [安全运行说明](tests/final/SECURITY_PERFORMANCE_README.md)、[安全结果](tests/final/results/security-http.json)、[浏览器结果](tests/final/results/browser-summary.json)、[数据结果](tests/final/results/data-summary.json)。AI 两 PHP 版本合计 282 项实际后端断言；Sources 156 项（包含真实 wp-cron.php 触发），外部网络仍为 Mock/阻断。

## 已发现并修复的问题

| 编号 | 级别 | 问题与修复 |
| --- | --- | --- |
| QA-01 | P1 | 正常含空格搜索 URL 503；按真实 WordPress helper 契约预编码参数，保持中文/保留字符语义。不是 SQL 注入 |
| QA-02 | P2 | Yoast 移除原生 title renderer 后 Hub 没有标题；仅 Hub SEO 所有权下恢复原生 renderer |
| QA-03 | P2 | 插件后置 robots 修改破坏私站/后台规则；最后优先级落实站点可见性，保留合理公开规则 |
| QA-04 | P2 | 缺少 Schema.org；仅已验证公开可索引页面输出真实 WebSite/WebPage/BreadcrumbList，安全 JSON 编码，不推测评分/安全结论 |
| QA-05 | P2 | Rank Math stdClass entry 未排除原生重复页面；验证公开 hook 对象契约兼容 WP_Post/stdClass |
| QA-06 | P2 | 插件 XML 缓存跳过动态 Hub 索引；用公开 filters 禁用 XML 缓存，实测子图撤回/新增/恢复 |
| QA-07 | P2 | 双插件真实 renderer 与常量优先级不同导致 Hub 索引遗漏；两个公开 hook 按精确 loc 幂等追加 |

本轮没有确认未修复 P0/P1、未授权发布、人工字段覆盖或业务数据丢失。SEO 缓存问题属于索引兼容错误，不应被夸大为私有正文泄露：公开 URL 本身仍执行门禁。发现、修复前证据、修复后矩阵见 [SEO_FINDINGS.md](tests/final/SEO_FINDINGS.md)。

## 测试环境凭据事件与处理

一次过宽的临时目录文件搜索把 **qa_data、qa_seo 的临时管理员密码**带入诊断工具输出。仅涉及本次新建 localhost QA 账户，不涉及真实 API Key、生产账户或外部服务。已立即轮换这两个密码并撤销全部登录会话；没有把密码写入 Git、证据 JSON 或安装包。后续读取限定精确文件；提交前对15个当前已知临时密码/数据库秘密进行静默匹配，候选仓库与ZIP匹配0、十个测试站debug.log匹配0，见 [delivery-secret-scan.json](tests/final/results/delivery-secret-scan.json)。这不是对未知秘密的穷尽证明。该事件是测试过程的操作问题，不是主题运行时漏洞；保留说明而不重现敏感值。

WordPress 原生调试日志中早期的 strict_types eval-file 加载错误、测试参数假设以及故障注入另作诊断，不计入正常页面通过。最终浏览器/HTTP 矩阵检查 PHP Warning/Fatal 和 Console Error；受控数据库失败验证的是错误处理，不能把它们称作生产故障。

## 未验证与安全边界

没有真实 WPML、GitHub/AI 专用凭据，因此真实模型提示注入抗性、供应商错误体/密钥轮换/账单、完整 DNS rebinding/IPv6/代理拓扑没有验收。未执行未知脚本、不进行外网攻击、不购买 API。有限输入测试不证明所有可能载荷安全；详见 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)。第三方插件、WordPress 和 npm 依赖需继续维护；本次 npm 审计实际为 0 个已知漏洞，不是未来安全保证。
