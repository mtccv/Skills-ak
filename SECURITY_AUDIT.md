# Final QA 安全审查

## Phase 13 定向审查与回归

范围为新Commercial模块、共享观察凭证、Contact/私有Inbox入口及运营概览优化，保留旧阶段审查事实。本轮修复记录和实际结果见 [阶段报告](docs/PHASE_13_REPORT.md)，不构成第三方安全认证或替代外部环境测试。

- 赞助/指标/企业咨询三个独立Capability，默认仅管理员取得；入口检查方法、Nonce和原生能力。配置CAS、事务内审计、并发锁及错误回滚，不授予账户权限或发布权。
- 内部赞助只能使用稳定Skill ID并实时通过当前语言PublicCatalog；拒绝以本站原始URL绕过门禁，外链仅正常导航，不提供开放重定向、不自动抓取。
- 图片限定公开本地受控栅格文件，核对真实类型、扩展名、大小、像素、附件/父对象状态和路径；阻止私有头像、密码内容、脚本及伪装图片。仅文本时不要求素材。
- 企业咨询复用私有Inbox，独立类型及能力；原生编辑/元信息/查询/导出入口保持保护。确认身份的WP隐私导出/擦除保留必要匿名事实，处理备注不进公开页面或统计。
- 商业同意不隐含自然浏览同意；同页多组件共享撤回，变更请求串行避免Cookie竞态；撤回失败时保持计量停止并显示可重试按钮，只有服务端确认stopped才认为移除已确认。签名证明绑定位置、修订、语言和不可变Skill ID，写入前重新检查展示/公开状态，事务中先取锁再读取，防旧快照绕过撤回。
- 指标不保存原始IP、UA、URL、用户ID或个人轨迹；短期随机凭证、有限预算、固定错误码、有界保留，不改变自然榜单权重或原指标。
- 免费权益仅当前会话只读预检，不能替代对象授权、Nonce、邮箱验证、暂停和发布审核；无角色/套餐请求参数授予接口。
- 真实并发测试发现套装选项初始化的共享锁升级死锁，失败事务安全回滚；采用一致写锁修复并回归，保持原表、数据与修订设计。

未使用真实邮件/付费API或未知Skill脚本，不把Mock响应称为外部服务验证。服务端日志仍须由部署者禁录Cookie、表单正文和认证参数。

## Phase 12 变更范围复核

本轮仅针对运营增量及相关跨模块边界，不能代替未执行的外部环境完整审计。新增入口均使用Capability及Nonce，读取按当前账户/对象归属，写入经原域服务、修订CAS与事务。复查并修复原生修改邮箱解除业务暂停、通知偏好重写开发者公开授权、私有首页预览SEO所有权和管理员邮件恢复门禁问题。邮件不输出凭据、收件人或传输异常原文，不重试不确定结果；来源运营不执行脚本或任意URL请求。

搜索分析采用明确用途同意、同源短时签名、有限预算、敏感词抑制、低频无明文和有界保留；运营报表只聚合，不建立个人搜索记录。暂停/恢复不授予角色、批准内容或解除隐私清理。实际PHP、Mock、WordPress/MySQL/浏览器及未执行范围见 [Phase12报告](docs/PHASE_12_REPORT.md)，不将Mock传输或接口存在称为真实外部服务通过。

## Phase 10 定向开发审查与回归（2026-11-06）

本次是新增投稿/开发者模块及账户、原生 Inbox 接口的定向代码审查和运行验证，不替代下方历史 Final QA，也不宣称完成第三方插件或全网安全认证。

- 新投稿正向所有权标记、当前 user_id、邮箱验证、独立能力、Nonce/Origin 和 CAS；旧匿名 Inbox/Contact 不按邮箱或原 post_author 归属。
- 原生编辑、修订恢复、metadata/by-mid 与对象权限保护，阻止绕过不可变快照；真实 WP 测试发现 CPT 先映射 primitive capability 后再过滤，已覆盖该路径。
- 投稿/关联结果、开发者身份和认领只读 PublicCatalog 当前已发布内容；接受、认领和版本建议均不能修改已审内容、授予编辑能力或自动发布。
- 白名单 GitHub URL/路径校验，用户输入不发起网络、安装或执行；无前端 Token/API Key。公开资料只用已审核快照，无邮箱、登录名、私有证据和管理员备注。
- 账户/来源/handle 锁、真实唯一索引、事务内审计/通知；原生写入事务中禁用网络与邮件副作用，提交/回滚清理对象缓存。
- 原生账户删除/转交前隔离受控 Inbox 和修订，持久队列分批擦除；注销的审核人员私人备注也清除，保留必要非公开审核事实。

测试逐项证据及修复列表见 [Phase 10 报告](docs/PHASE_10_REPORT.md) 与 [tests/contributions/results](tests/contributions/results/)。实际保留期和外部依赖限制见 [隐私说明](PRIVACY_AND_SECURITY.md)。

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
