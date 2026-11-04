# Phase 0 初始化验收记录

日期：2026-11-05（Asia/Shanghai）。范围：仅 CODEX_MASTER_SPEC.md 中的 Phase 0。

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
| 验证与 CI | `scripts/check-assets.mjs`、`scripts/lint-php.sh`、`scripts/smoke-wordpress.sh`、`.github/workflows/checks.yml` |
| 原创主题 | `wp-content/themes/ai-skills-hub/` 下的 `style.css`、`functions.php`、`header.php`、`footer.php`、`index.php` |
| 内置 Core | 主题内 `inc/core/bootstrap.php`，只声明版本，不注册业务模块 |
| 源资源与占位 | 主题内 `assets/src/app.css`、`assets/src/app.js`、`assets/dist/.gitkeep`、`languages/.gitkeep` |

`.env` 使用随机开发密码，仅留 Cloud，未跟踪；`node_modules` 与构建得到的 `app.css`/`app.js` 忽略。WordPress 核心和 MySQL 数据位于 Docker volume，不入库。GPL-2.0-or-later 的主题声明及 GPL v2 许可证文本已加入仓库。

## 环境检查

- Cloud：Linux x86_64，Debian 13；Node.js v24.22.1，npm 11.9.0。
- Docker daemon 29.9.2，Compose v2.40.3。
- 原生 PHP、Composer、MySQL、WP-CLI 未安装；采用官方容器提供 PHP/WordPress/MySQL/WP-CLI，无本机或外部服务器依赖。
- 网络策略为 enforced/unrestricted，VPN 未配置，未绑定 GitHub/AI 业务密钥。GitHub 操作使用平台已有仓库授权。
- 默认沙箱访问代理、Docker socket 及 esbuild 子进程受限；通过平台授权执行通道完成网络/容器/构建操作，保留配置代理和 TLS 验证。
- `docker compose config --quiet` 通过。Web 绑定 `127.0.0.1:8080`，MySQL 不映射宿主端口。

## 验证记录

| 检查 | 结果 |
| --- | --- |
| `npm ci`（最终 lock） | 通过，77 个包 |
| `npm run build` | 通过：Tailwind 3.4.19、Alpine 3.17.5、esbuild 0.25.12 |
| `npm run check:assets` | 通过：CSS 5622 bytes、JS 55953 bytes，检查 Tailwind 模板扫描 |
| `npm audit --audit-level=high` | 0 个已知漏洞（仅该次 npm 依赖审计结论） |
| shell / Node 检查脚本语法 | 通过 |
| Git 空白检查和 `.env` 忽略检查 | 通过 |
| PHP lint / WordPress / MySQL / HTTP | 等待容器首次下载后实测补全 |

Tailwind 的 Browserslist 数据日期提示仍存在；更新数据后仍提示，构建成功。这是工具数据新鲜度提示，不是运行验收失败。

## 阶段边界

未注册 Skills CPT、未创建业务表、未实现采集/AI/双语路由/WPML 或业务后台。gettext 入口仅用于主题初始化，并不代表完成双语功能。最小首页为通用占位页，未开展 Phase 1 页面设计。

CI 定义 PHP 8.3/8.4 语法检查和 Node 24 构建；本地实测版本与远端 CI 结果分别记录，不混同。WPML 真插件、GitHub 业务来源和 AI 真服务测试属于后续阶段。

Phase 0 结束后停止，等待用户确认 Phase 1。
