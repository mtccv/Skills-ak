# AI Skills Hub

WordPress 原创主题项目，内置 AI Skills Hub Core。Phase 0、Phase 1 已验收；Phase 2 基于既有 Core 加入 WordPress 原生后台、双语编辑、人工审核与归档。公开前台、自动采集和 AI 生成尚未开发。

完整需求及阶段门禁见 [CODEX_MASTER_SPEC.md](CODEX_MASTER_SPEC.md)，Core 使用说明见 [docs/CORE.md](docs/CORE.md)，本轮记录见 [docs/PHASE_2_REPORT.md](docs/PHASE_2_REPORT.md)，阶段进度见 [docs/PROGRESS.md](docs/PROGRESS.md)。[Phase 1 报告](docs/PHASE_1_REPORT.md) 与 [Phase 0 报告](docs/PHASE_0_REPORT.md) 保留为历史证据。Phase 2 完成后暂停，等待用户安排 Phase 3。

## Codex Cloud 开发

仅在 Codex Cloud 运行，不需要本机环境、宝塔或外部服务器。要求 Docker Compose、Node.js 22/24、npm、Python 3；PHP 和 MySQL 运行在容器内。

```sh
npm ci
npm run build
cp .env.example .env
```

编辑 `.env`，为两个 MySQL 密码填入不同的随机值（例如 `openssl rand -hex 24`），不要提交该文件。然后启动 Cloud 开发服务：

```sh
docker compose up -d --wait mysql wordpress
python3 scripts/install-wordpress.py
docker compose run --rm cli wp theme activate ai-skills-hub
docker compose run --rm cli wp eval 'echo AISkillsHub\Core\Core::boot()->migrator()->version(), PHP_EOL;'
docker compose run --rm cli wp core version
docker compose exec -T wordpress php -v
docker compose exec -T mysql mysql --version
curl --fail http://localhost:8080/
```

安装脚本将随机管理员密码保存到被 Git 忽略的 `.dev-admin-password`（权限 0600），并对 WP-CLI 输出脱敏，避免密码进入终端日志。开发用户名为 `cloud_admin`；需要登录时在 Cloud 内私密查看密码文件，不要粘贴到聊天或提交。只在 Cloud 内访问 `http://localhost:8080`；数据库没有宿主端口。WP-CLI 与 Web 使用同一数据库和 WordPress volume。初始站点默认英文；Phase 1 提供双语数据存储，Phase 2 提供后台双语编辑，公开前台语言切换与路由留待后续阶段。

```sh
npm run check:assets
npm run lint:php
git diff --check
docker compose stop
```

`lint:php` 优先使用环境内 PHP，否则使用已运行的 WordPress 容器。CI 自带 PHP。Cloud 只有缓存的 PHP 镜像时，也可用隔离的一次性容器只读挂载主题及 tests 执行 `php -l`，不启动数据库。

按当前策略，每阶段运行 PHP、构建和基本代码检查；完整集成留到 Final QA。届时可在可丢弃的开发 WordPress 上执行 `npm run test:core` 与 `npm run smoke:wp`，或手动触发 GitHub Actions 的 `Core checks` 并设置 `final_qa=true`。现有集成脚本使用随机表前缀并在 finally 清理，是 Final QA 的基线而非全部验收。默认 push/PR 不运行 MySQL/WordPress 任务。

`npm run dev` 监听 CSS，`npm run dev:js` 监听 JS，分别在终端运行；改变依赖后提交 `package-lock.json`。容器镜像使用官方版本系列标签，可变标签不保证长期完全重现；已取得的实际版本和 digest 见阶段报告，更新镜像后应重新验收。

`docker compose down` 保留数据卷。`down -v` 会删除开发数据库和站点文件，仅在明确要清空开发站时手动执行。运行数据不随 Git 同步，Cloud 重建后按以上命令重新安装。生成的 `assets/dist` 不入库；下载/启用主题前必须先构建，发布打包流程留待后续阶段。

## 目录与当前范围

- `wp-content/themes/ai-skills-hub`：原创主题、模块化 Core、原生 Admin 服务/界面及资源源码。
- `compose.yaml`、`.env.example`：Cloud 开发环境。
- `scripts/`、`.github/workflows/`：构建与语法验证。
- `tests/integration/core.php`：数据库结构、重复安装、实际增量升级、双语关联与安全测试。
- `docs/`：环境检查和阶段结果。

Skills 采用自定义表作为主存储，内部 ID 与语言无关；不注册影子 CPT。只预留 WPML 接口，不采集 GitHub、不调用 AI、不实现多语言内容路由。服务器密钥不得进入源码、前端或日志。每阶段完成代码提交与推送，并报告测试限制。

## Phase 2 后台使用

管理员进入 WordPress 后台的 **AI Skills Hub**，可访问 Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings。

创建 Skill 时可以不选择仓库；手工分别维护中文/英文标题、简介、正文和 SEO。保存草稿允许内容不完整，空白译文不会自动补全。提交审核后，有审核能力的用户选择风险等级并记录备注；批准当前修订且至少一种语言内容完整才会发布。已发布内容修改后必须重新审核。预览只对授权用户开放，不是公开页面。

删除采用归档，服务器确认页要求二次提交，保留原记录与关联。默认只有管理员获得管理能力；其他角色必须由站点管理员明确分配相应 `aish_*` capability。升级到 schema `1.2.0` 前备份数据库；详情见 Core 文档。
