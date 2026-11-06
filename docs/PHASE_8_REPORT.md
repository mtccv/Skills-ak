# Phase 8 — 账户与个人中心交付记录

基线：`556ea8dfba9b03e84f1cb36d063f53418f1d31ea`，包含用户指定的设计提交 `56d790a` 及随后去绿色补修。主题/Core **0.8.0**，schema **1.6.0**。仅实施 Phase 8，Phase 9–14 未开始；没有修改 Phase 7.5 的静态原型。

## 交付范围

- 原生 WordPress Users/Subscriber 邮箱注册、登录/退出、一次性邮箱验证、重发、原生密码恢复/重置、密码/邮箱修改和其他会话撤销。
- 中英文认证页、Dashboard、Profile、Account/Privacy/Security Settings、账户通知。真实账户数据，未加入收藏、评分、新投稿或示例统计。
- 默认私有资料、本地受控头像、WordPress 原生隐私请求与导出/擦除接口。
- 哈希凭证、版本化专用密钥加密邮件队列、有限 Cron 工作、传输受理/不确定状态、重试和清理。没有真实 SMTP 或收件箱送达证明。
- 只新增 `email_verifications`、`user_notifications`、`notification_outbox`，原 14 表保持。原生 usermeta、attachment、user_request 承载相应扩展，没有第二套账户库。

## 实际测试环境

Codex Cloud 既有独立 QA 容器：WordPress **7.1.4**、PHP **8.3.36 / 8.4.27**、MySQL **8.0.46**；Chromium **155**。主机没有 PHP CLI，因此 PHP 检查使用容器。真实 WordPress/MySQL 测试与 Mock 邮件传输明确分开；没有接触生产数据库。原业务表迁移前保存指纹，临时存储测试使用随机独立前缀。

## 最终检查结果

所有计数均注明单位，不将断言数、用例组和视口检查相加冒充独立验收项目。以下为本次实际执行结果，最终失败为 0；开发中发现的问题已修复后回归。

| 类别 | 实际结果 | 证据 |
| --- | --- | --- |
| PHP 语法，静态 | PHP 8.3 / 8.4 分别 151 个 PHP 文件通过 | `tests/accounts/results/static-checks.json` |
| 隔离与 Mock 回归 | 两个 PHP 版本各 33 个脚本、2876 项断言通过；其中账户 4 脚本共 155 项 | `tests/accounts/results/basic-php83.txt`、`basic-php84.txt` |
| 真实 WP/MySQL 账户 + Mock 邮件 | 两个版本各 20 组、136 项断言通过：角色、一次性验证、恢复/改邮箱、旧管理员、邮件状态、原生旁路等 | `tests/accounts/results/integration-php83.json`、`integration-php84.json` |
| 真实 MySQL 存储 | 两个版本各 55 项通过；全新历史结构升级、原十四表保留、重复安装/迁移、事务、唯一键和队列租约 | `tests/phase8/results/storage-php83.json`、`storage-php84.json` |
| 真实迁移/中断恢复 | 两个版本各 9 项通过 | `tests/accounts/results/migration-php83.json`、`migration-php84.json` |
| 真实并发注册 | 两个版本各 3 组竞争通过：主题、原生 API、混合路径，同邮箱只产生一个用户 | `tests/accounts/results/concurrency-php83.json`、`concurrency-php84.json` |
| 真实隐私/头像安全 | 两个版本各 9 组、41 项通过；原请求绑定、邮箱复用、私有媒体、清理及表引擎保护 | `tests/accounts/results/privacy-guards-php83.json`、`privacy-guards-php84.json` |
| 真实原生 ZIP 生成 + HTTP 下载 | 两个版本各 22 项通过；私有目录/权限、本人下载、其他/匿名/过期拒绝、变更邮箱失效 | `tests/accounts/results/privacy-export-php83.json`、`privacy-export-php84.json` |
| 私有导出目录配置回归 | 两个版本各 4 组、8 项通过；拒绝系统根/临时根/网站根及祖先，原目录权限不变 | `tests/accounts/results/privacy-directory-php83.json`、`privacy-directory-php84.json` |
| 真实 Chromium + WP/MySQL + Mock 邮件 | 69 项通过，0 Console/Page Error；中英完整账户流程，320/768/1440px，实际 PNG 上传、CSRF、无 JS 等 | `tests/accounts/results/browser.json` 与同目录 10 张截图 |
| 既有功能 HTTP 冒烟 | 15 项通过，公开双语页面、私有入口、管理员等有限回归；不是重新执行全部 V1 Final QA | `tests/accounts/results/legacy-smoke.json` |
| 前端/打包/差异 | Tailwind/esbuild、资源检查、JS 语法、8 项打包器测试、0.8.0 包输入检查、`git diff --check` 通过 | `tests/accounts/results/static-checks.json` |

原浏览器站点 14 张业务表共 155 行及 6 条私有来信、PHP 8.4 测试站点业务表共 16 行，迁移前后分别核对；业务表指纹保持，私有来信数量保持。不是对生产库的证明。新的账户测试记录只存在 Cloud 独立测试数据库。

截图：[中文桌面 Dashboard](../tests/accounts/results/dashboard-zh-1440.png) · [英文手机 Dashboard](../tests/accounts/results/dashboard-en-320.png) · [中文手机注册](../tests/accounts/results/register-zh-320.png)。所有截图中的账户是实际 QA 测试账户，不是生产种子数据。

## 审查发现与修复

| 等级 | 实际问题 | 修复方式 |
| --- | --- | --- |
| P1 | 注册表单在 `no-referrer` 下收到 `Origin: null`，误拒绝合法提交 | 保留浏览器绑定 HMAC + WordPress nonce 两项验证；允许不透明 Origin 的合法请求，明确跨站 Origin 仍拒绝 |
| P1 | 原生登录的早期限流错误可被 WordPress 后续认证覆盖 | 在最终认证阶段再次执行限流拒绝，测试正确密码也不能绕过限额 |
| P1 | 旧编辑/自定义业务角色缺验证字段时可能丢失已有后台能力 | 保留既有管理能力，社区写入仍要求真实邮箱验证；新 Subscriber 不获得管理权限 |
| P1 | 原生改密码/重置路径可能留下待确认的新邮箱凭证 | 绑定当前原生密码版本与原邮箱、锁定用户行并接入原生密码变更失效钩子 |
| P1 | 旧隐私申请按邮箱解析时可能指向后来使用同邮箱的账户 | 固定实际请求和原用户 ID，邮箱变更失效旧申请，历史未绑定请求安全拒绝 |
| P1 | 原生 REST 集合的错误返回合同、私有头像在删除/转交过程中的所有权边界需要保护 | 受控附件元数据、会话所有权、私有 REST/列表保护、删除前隔离和有界清理 |
| P1 | WordPress 原生隐私导出默认下载位置不符合本人授权设计 | 账户申请使用受保护文件存储和授权下载；具体运行证据随隐私回归记录 |
| P1 | 历史空邮箱管理员的原生删除被账户清理误拦截 | 无邮箱时以稳定用户 ID 锁定和持久登记清理；真实原生更新、认证、权限和删除回归通过 |
| P2 | 私有导出目录错误配置为临时根目录或网站祖先可能改动共享目录权限 | 在创建和 chmod 前拒绝这些配置，保留专用私有目录约束 |
| P2 | `wp_mail(false)` 不能证明服务未受理，自动重试可能重复发送 | 默认记为 uncertain；只有可信传输适配器明确确认未受理才有限重试 |
| P2 | 通知重放、分页边界、设置数据形状及既有短密码表单约束不一致 | 幂等已读、显式分页边界、统一字段合同；只对新密码要求新长度策略 |

以上等级是本阶段开发审查记录，不代表已有生产用户受影响。修复结果以最终回归证据为准。

## 文件清单

- Core：`inc/core/Modules/AccountsModule.php`；`inc/core/Accounts/` 中账户、锁、限流、验证/通知 Store、加密、邮件/worker/scheduler、私有前台、双语文案、头像和隐私服务。
- 数据与接入：`Database/Schema.php`、`Migrator.php`、`Core.php`、`bootstrap.php`、`Frontend/Router.php`；主题版本、导航及既有颜色 token 接入确认品牌配色，保持页面业务逻辑。
- UI：`templates/account/page.php`、`assets/src/accounts.css`、`accounts.js`、共享构建入口；原型文件不变。
- 检查：`tests/basic/account-*.php`、`tests/accounts/`、`tests/phase8/`；原有表数/模块顺序/迁移恢复检查及 CI。
- 文档：根 `ACCOUNT_SYSTEM.md`、`EMAIL_SETUP.md`、`PRIVACY_AND_SECURITY.md`、总规格、README、V2 状态、Core/数据库说明、进度、变更记录及本报告。

## 未执行与部署边界

1. **真实邮件传输、回执、退信、收件箱送达：未执行**，没有真实服务配置；Mock true 只验证 transport accepted 的状态处理。
2. **真实 WPML、第三方 SSO/认证/缓存/邮件插件：未执行本阶段联调**，接口和安全失败不等于插件运行验收。
3. **多站点全网迁移/账户删除、生产负载、Firefox/WebKit/真实移动设备及完整读屏：未执行本阶段验收**。新页面以真实 Chromium 桌面/平板/手机视口检查。
4. 本轮未重新执行真实 GitHub/AI API；没有新增这些服务功能，不改变历史缺凭据限制。
5. 验证链接首跳仍经过服务器，必须按 EMAIL_SETUP 配置外围日志，主题不能擦除代理或邮件插件已写入的秘密。
6. 原生 Users 删除中断会保持隔离和待清理状态，需管理员排错重试；主题停用保留数据，但账户策略和调度随主题停止。隐私擦除不自动等于删除原生身份，也不删除 Skills/来源/AI/匿名来信。

本阶段已执行范围内没有已知未修复 P0/P1；不能据此宣布真实邮件或上述外部依赖已完全验收。完成正常 Git 提交和 GitHub main 同步后暂停，等待用户安排 Phase 9。
