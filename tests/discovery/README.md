# Phase 11 真实环境回归

本目录使用独立 Docker WordPress/MySQL 测试数据；不连接生产服务，不从业务库导入数据。所有统计、评价、用户和专题都是明确标记的隔离 QA fixtures，不能作为网站真实运营数据。测试经过实际运行才会写入 `results/`，隔离 PHP/JS 模型测试位于 `tests/basic/`，两者不混报。

## 实际环境

- WordPress 7.1.4；PHP 8.3.36；MySQL 8.0.46；Chromium 155；Playwright。
- 容器 `p11qa-mysql`、`p11qa-wp83`，专用网络 `p11qa-net`；WordPress 仅绑定 `127.0.0.1:18183`。
- 专用数据库 `qa_discovery`；测试脚本要求 CLI 和 `AISH_PHASE11_QA=1`，并校验库名及 schema。
- 初始主题来自不可变 `c7fa503432ceb4fc971c3b7d44b44591e3c0a7af`，schema 1.8.0，实际 26 张 Core 表。先记录原表 DDL/行哈希及私有 Inbox，再复制当前主题进行增量迁移。
- 初始保留证据包含 18 行 Core 测试数据：稳定 Skill ID、双语译文、仓库来源、已验证 WordPress 用户、有效收藏、已批准评价与原审核/通知/统计记录；另有匿名 Contact/Submission 私有 Inbox 2 条及 4 条元数据。
- `baseline.php` 输出哈希和数量，不输出资料原文、邮箱、Token 或密码。
- MySQL、WordPress、浏览器为真实运行；邮件通过 `pre_wp_mail` Mock，外部 HTTP 通过 MU plugin 禁用。没有真实邮件送达、GitHub/AI API 或商业 WPML/SEO 插件联调。

## 启动与执行约束

使用官方 `mysql:8.0` 和 `wordpress:php8.3-apache`，建立新的 Docker 网络和卷。数据库及 WordPress 管理员凭据放在 `/tmp/p11qa/` 下权限为 0600 的私有文件，不提交仓库。主题先从上述基线 `git archive` 提取至 `/tmp/p11qa/theme`，挂载到 `/var/www/html/wp-content/themes/ai-skills-hub`；仓库挂载 `/repo:ro`，辅助脚本挂载 `/qa:ro`。PHP CLI 通过原生 `wp_install` 安装新的 WordPress，再切换主题和调用现有管理服务创建测试 fixtures。不得在已有生产库执行。

本次用于阻断真实外部操作的 MU 插件只安装在 QA 卷中：`pre_http_request` 返回 `WP_Error`、`pre_wp_mail` 返回 `true`，关闭原生自动更新检查。该模拟邮件受理不证明邮件已真实发出或送达。

清除可能指向其他 daemon 的 Docker 环境变量，并显式使用本地 daemon：

```bash
unset DOCKER_HOST DOCKER_CONTEXT DOCKER_TLS DOCKER_TLS_VERIFY DOCKER_CERT_PATH
docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE11_QA=1 p11qa-wp83 php /repo/tests/discovery/baseline.php
# 保存基线输出后，仅替换 QA 主题文件，不清库、不降版本。
docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE11_QA=1 p11qa-wp83 php /repo/tests/discovery/migrate.php
docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE11_QA=1 p11qa-wp83 php /repo/tests/discovery/fresh-install.php
docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE11_QA=1 p11qa-wp83 php /repo/tests/discovery/integration.php
docker --host=unix:///var/run/docker.sock exec -e AISH_PHASE11_QA=1 p11qa-wp83 php /repo/tests/discovery/retention.php
docker --host=unix:///var/run/docker.sock exec p11qa-wp83 php /repo/tests/integration/core.php
```

`migrate.php` 必须在修改业务 fixtures 前执行：它验证原 26 表的完整哈希，之后的测试会增加隔离数据。`fresh-install.php` 使用新的随机表前缀，验证完整 33 表首次安装、重复安装和拒绝降级；不重置当前 Core 表。

真实浏览器测试需要 Playwright 和 `/usr/bin/chromium`，不新增项目依赖。`browser-control.php` 从当前服务测试保存的 QA option 读取公开 fixture IDs；它可以仅在这个独立数据库中开启同意式统计。浏览器管理登录从私有 `/tmp/p11qa/credentials.json` 的 `admin` 字段读取密码，测试输出不保存凭据。先将 `browser-control.php` 默认输出保存到 `/tmp/p11qa/browser-fixture.json`，再执行：

```bash
python3 tests/discovery/concurrency.py
node tests/discovery/browser.cjs
```

`concurrency.py` 启动 8 个独立 PHP/MySQL 连接并发写入同一个观察者/Skill（中英交替），预期一次记录、7 次去重；另有 4 个相同 revision 的专题写入，预期一次提交、3 次冲突。这里通过 CLI-only harness 反射调用私有持久化方法验证事务；浏览器还独立测试真正 AJAX HTTP 入口，并实际等待 8 秒。

`browser.cjs` 覆盖中英文、1440/768/375 三种宽度，每组合 15 个页面（90 次页面验证）、原生后台四个发现界面、新建专题、权限/Nonce、私有草稿、Canonical/hreflang/OG/Twitter、Sitemap、301/400/404。浏览器使用明确的测试正常 UA 验证同意流，也单独发送 bot/DNT/GPC 验证排除；这些不是自然访问量。截图展示的测试内容不是正式站点内容。

`package-upgrade.php` 使用 WordPress `Theme_Upgrader` 安装基线 ZIP、覆盖升级当前 ZIP，并逐文件核对包内哈希、全部 33 表及私有 Inbox 哈希。它运行在共享 QA WordPress 卷的临时 PHP 容器中，不挂载主题目录本身，避免 Docker bind mount 阻止原生目录替换。其他凭据、网络和数据库仍与隔离 QA 相同。`/qa/baseline.zip` 来自基线主题 `git archive --format=zip --prefix=ai-skills-hub/`；当前包读取 `/repo/release/ai-skills-hub-0.11.0.zip`。

## 本次证据与边界

2026-11-07 最终执行结果（全部使用上述真实 WordPress/MySQL）：

| 验证 | 实际通过 | 最终失败 |
| --- | ---: | ---: |
| 1.8→1.9 迁移、重复迁移、26 表/Inbox 保留 | 61 项检查 | 0 |
| 新前缀完整安装、重复安装、拒绝降级 | 2 用例 / 6 断言 | 0 |
| 发现系统与既有账户/社区服务交互 | 23 用例 / 76 断言 | 0 |
| 独立进程并发去重 / 编辑冲突 | 4 项检查 | 0 |
| 201 条积压分批清理 / 续调度 / 模块隔离 | 2 用例 / 5 断言 | 0 |
| Chromium 中英响应式、原生后台、SEO、安全入口 | 16 工作包 / 413 断言 | 0 |
| 原有 Core 集成（含真实主题切换/重新启用） | 162 断言 | 0 |
| 原生 ZIP 安装升级、160 文件哈希、33 表/Inbox 保留 | 6 项检查 | 0 |

最终安装包 SHA-256：`e9945b16e76260bbf9a7a4ac893774212bd811d5c212b68eb551b3310c880fd1`。原生升级实测成功，保留 schema 1.9.0，包内 160 个文件与安装后字节哈希一致。以上测试合计 833 个断言/检查，工作包与断言不混为用例数量；早期 harness 调试记录不纳入最终失败数。

最终结果以 `results/migration-php83.json`、`fresh-install-php83.json`、`integration-php83.json`、`concurrency-php83.json`、`retention-php83.json`、`core-runtime-php83.txt`、`browser.json`、`package-upgrade-php83.json` 为准。截图为真实 Chromium 捕获，不是静态原型截图。

初次迁移已真实完成，但 QA 脚本机械替换表数时误将 `sha256` 拼写改为 `sha266`，导致迁移后的指纹检查中断；`migration-initial-harness-error.json` 保留这次事实。修正测试后没有重置数据库或版本，重复迁移及 26 表/Inbox 保留检查全部重新执行。初次浏览器脚本误用 `/account/login/` 和 `en-US` hreflang，已按已有 `/login/` 与 `en` 合约修正并全部重跑。清理测试初次没有声明可信 CLI 上下文，生产代码正确拒绝清理；补齐 `WP_CLI` 后实测分批清理及续调度。这些均为测试 harness 修正，不是通过降低业务门禁取得通过。

PHP 8.4 WordPress 镜像下载到解包阶段因 Docker 存储空间不足（`no space left on device`）失败，故本目录没有真实 PHP 8.4 WordPress/MySQL 运行结论；未删除已有数据卷来腾空间。PHP 8.4 静态/隔离检查是否执行，以主任务的单独记录为准。真实 WPML、第三方 SEO 插件、外部邮件和 GitHub/AI API 本次未执行。此阶段回归不替代所有未来规模压测与真实访客反作弊评估。
