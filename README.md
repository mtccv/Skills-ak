# AI Skills Hub

WordPress 原创主题项目，内置 AI Skills Hub Core。Phase 0 已验收；当前 Phase 1 实现数据库、迁移、模块加载和双语数据访问，不包含业务页面、采集或 AI 生成。

完整需求及阶段门禁见 [CODEX_MASTER_SPEC.md](CODEX_MASTER_SPEC.md)，Core 使用说明见 [docs/CORE.md](docs/CORE.md)，本轮证据见 [docs/PHASE_1_REPORT.md](docs/PHASE_1_REPORT.md)。[Phase 0 报告](docs/PHASE_0_REPORT.md) 为历史记录。未经用户验收，不进入 Phase 2。

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

安装脚本将随机管理员密码保存到被 Git 忽略的 `.dev-admin-password`（权限 0600），并对 WP-CLI 输出脱敏，避免密码进入终端日志。开发用户名为 `cloud_admin`；需要登录时在 Cloud 内私密查看密码文件，不要粘贴到聊天或提交。只在 Cloud 内访问 `http://localhost:8080`；数据库没有宿主端口。WP-CLI 与 Web 使用同一数据库和 WordPress volume。初始站点默认英文；Phase 1 提供双语数据存储，前台语言切换与路由留待 Phase 2。

```sh
npm run check:assets
npm run lint:php
npm run test:core
npm run smoke:wp
docker compose stop
```

`lint:php` 优先使用环境内 PHP，否则使用已运行的 WordPress 容器。CI 自带 PHP。`test:core` 在已安装、已激活主题的开发 WordPress 上执行真实 MySQL 集成测试：使用随机测试表前缀并在 finally 清理，只应运行于开发/CI 站点。Cloud 未能拉取 MySQL 镜像时使用仓库的 `wordpress-smoke` CI 任务，报告须区分执行环境。

`npm run dev` 监听 CSS，`npm run dev:js` 监听 JS，分别在终端运行；改变依赖后提交 `package-lock.json`。容器镜像使用官方版本系列标签，可变标签不保证长期完全重现；已取得的实际版本和 digest 见阶段报告，更新镜像后应重新验收。

`docker compose down` 保留数据卷。`down -v` 会删除开发数据库和站点文件，仅在明确要清空开发站时手动执行。运行数据不随 Git 同步，Cloud 重建后按以上命令重新安装。生成的 `assets/dist` 不入库；下载/启用主题前必须先构建，发布打包流程留待后续阶段。

## 目录与当前范围

- `wp-content/themes/ai-skills-hub`：最小可激活主题、模块化 Core、资源源码。
- `compose.yaml`、`.env.example`：Cloud 开发环境。
- `scripts/`、`.github/workflows/`：构建与语法验证。
- `tests/integration/core.php`：数据库结构、重复安装、实际增量升级、双语关联与安全测试。
- `docs/`：环境检查和阶段结果。

Skills 采用自定义表作为主存储，内部 ID 与语言无关；不注册影子 CPT。只预留 WPML 接口，不采集 GitHub、不调用 AI、不实现多语言内容路由。服务器密钥不得进入源码、前端或日志。每阶段完成代码提交与推送，并报告测试限制。
