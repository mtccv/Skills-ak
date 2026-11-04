# Phase 0 初始化验收记录

日期：2026-11-05（Asia/Shanghai）。范围：仅 CODEX_MASTER_SPEC.md 中的 Phase 0。

结论：初始化代码及 CI 验收通过；**Cloud 本机数据库/HTTP 运行验收未完成，仍属于 Phase 0 待补验项**。没有进入 Phase 1。

## 仓库检查

- 工作目录 `/workspace/Skills-ak`，origin 为 `https://github.com/mtccv/Skills-ak.git`。
- 开始时目录为空，Git 无提交，当前分支为 `work`；GitHub 默认分支为 `main`，仓库 size=0，`git ls-remote origin` 无 refs。
- GitHub 连接确认当前账户有 push 权限。没有已有代码、历史或 AGENTS.md 被覆盖。
- 初始化完成后使用 `main`，普通推送；最终提交 SHA 与远端核对结果见本轮交付回复及 `git log -1`。

## 创建文件

| 类别 | 文件 |
| --- | --- |
| 规格与说明 | `CODEX_MASTER_SPEC.md`、`AGENTS.md`、`README.md`、`LICENSE`、本报告 |
| 仓库配置 | `.gitignore`、`.editorconfig`、`.env.example` |
| Cloud 开发环境 | `compose.yaml` |
| 前端构建 | `package.json`、`package-lock.json`、`tailwind.config.cjs` |
| 验证与 CI | `scripts/check-assets.mjs`、`scripts/lint-php.sh`、`scripts/smoke-wordpress.sh`、`scripts/install-wordpress.py`、`.github/workflows/checks.yml` |
| 原创主题 | `wp-content/themes/ai-skills-hub/` 下的 `style.css`、`functions.php`、`header.php`、`footer.php`、`index.php` |
| 内置 Core | 主题内 `inc/core/bootstrap.php`，只声明版本，不注册业务模块 |
| 源资源与占位 | 主题内 `assets/src/app.css`、`assets/src/app.js`、`assets/dist/.gitkeep`、`languages/.gitkeep` |

共 27 个跟踪文件。`.env` 使用随机开发密码，仅留 Cloud，未跟踪；`node_modules` 与构建得到的 `app.css`/`app.js` 忽略。开发管理员密码文件 `.dev-admin-password` 也已忽略。WordPress 核心和 MySQL 数据设计为保存在 Docker volume，不入库。GPL-2.0-or-later 的主题声明及 GPL v2 许可证文本已加入仓库。

## 环境检查

- Cloud：Linux x86_64，Debian 13；Node.js v24.22.1，npm 11.9.0。
- Docker daemon 29.9.2，Compose v2.40.3。
- 原生 PHP、Composer、MySQL、WP-CLI 未安装；采用官方容器提供 PHP/WordPress/MySQL/WP-CLI，无本机或外部服务器依赖。
- 网络策略为 enforced/unrestricted，VPN 未配置，未绑定 GitHub/AI 业务密钥。GitHub 操作使用平台已有仓库授权。
- 默认沙箱访问代理、Docker socket 及 esbuild 子进程受限；通过平台授权执行通道完成网络/容器/构建操作，保留配置代理和 TLS 验证。
- `docker compose config --quiet` 通过。Web 绑定 `127.0.0.1:8080`，MySQL 不映射宿主端口。
- Cloud 已下载 WordPress 镜像并执行 PHP lint；MySQL/WP-CLI 两轮拉取均遇到严重慢速/停滞，未完成镜像下载，`docker compose ps --all` 无已启动服务。官方 WP-CLI PHAR 直连代理下载也在 60 秒后超时，未将不完整文件当作工具使用。没有绕过代理或关闭 TLS。
- 完整 Compose 验证转在临时 GitHub Actions runner 执行，已验证配置可启动并完成主题运行测试；这是 CI 测试，不是部署外部站点，也不能代替 Cloud 本机的就绪结论。
- Cloud 网络恢复后，依照 README 重新完成 `docker compose up -d --wait mysql wordpress`、安装、激活、`npm run lint:php`、`npm run smoke:wp`，才能关闭本机运行待补验项。

## 验证记录

| 检查 | 结果 |
| --- | --- |
| `npm ci`（最终 lock） | 通过，77 个包 |
| `npm run build` | 通过：Tailwind 3.4.19、Alpine 3.17.5、esbuild 0.25.12 |
| `npm run check:assets` | 通过：CSS 5622 bytes、JS 55953 bytes，检查 Tailwind 模板扫描 |
| `npm audit --audit-level=high` | 0 个已知漏洞（仅该次 npm 依赖审计结论） |
| shell / Node 检查脚本语法 | 通过 |
| Git 空白检查和 `.env` 忽略检查 | 通过 |
| PHP 8.3.36 lint | 官方 WordPress 镜像内全部 5 个主题 PHP 文件通过 |
| GitHub Actions（最终实现 `4acd8a1`） | PHP 8.3、8.4、前端及 WordPress 集成四项通过，[运行记录](https://github.com/mtccv/Skills-ak/actions/runs/41896065325) |
| WordPress / MySQL / HTTP（CI） | WordPress 7.1.4、PHP 8.3.36、MySQL 8.4.13；安装与主题激活通过，首页和 CSS/JS HTTP 通过，Core 已加载且未注册 Phase 1 内容类型 |
| 安装工具日志脱敏（CI） | 通过；实际密码输出均替换为 `[redacted]` |
| WordPress / MySQL / HTTP（Cloud 本机） | 未完成：镜像传输限制；不能标为通过 |

Cloud 实测 WordPress 镜像：`wordpress@sha256:1f59452d2ceed177ad383d17b3a6a7a5c8225d89e34adb36880af8603ac244c2`。MySQL 与 CLI 的 Cloud 镜像未取得完整 digest；Compose 仍使用可变的版本系列标签，尚未实现所有容器镜像的不可变锁定。

集成验收发现并修正了 WP-CLI 命令需显式带 `wp` 的问题，以及密码提示模式的回显问题。安装脚本捕获并脱敏输出，CI 同时对随机管理员密码加 mask。此前暴露的值仅用于已经销毁的临时 CI 站点，不是持久站点凭据。

Tailwind 的 Browserslist 数据日期提示仍存在；更新数据后仍提示，构建成功。这是工具数据新鲜度提示，不是运行验收失败。

## 阶段边界

未注册 Skills CPT、未创建业务表、未实现采集/AI/双语路由/WPML 或业务后台。gettext 入口仅用于主题初始化，并不代表完成双语功能。最小首页为通用占位页，未开展 Phase 1 页面设计。

CI 定义 PHP 8.3/8.4 语法检查、Node 24 构建及 Compose 冒烟测试，最终实现提交的四个任务已实际通过。WPML 真插件、GitHub 业务来源和 AI 真服务测试属于后续阶段。

Phase 0 结束后停止，等待用户确认 Phase 1。
