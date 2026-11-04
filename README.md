# AI Skills Hub

WordPress 原创主题项目，内置 AI Skills Hub Core。当前仅完成 Phase 0 初始化，业务功能尚未开发。

完整需求及阶段门禁见 [CODEX_MASTER_SPEC.md](CODEX_MASTER_SPEC.md)，本次证据见 [docs/PHASE_0_REPORT.md](docs/PHASE_0_REPORT.md)。未经用户确认，不进入 Phase 1。

## Codex Cloud 开发

仅在 Codex Cloud 运行，不需要本机环境、宝塔或外部服务器。要求 Docker Compose、Node.js 22/24、npm；PHP 和 MySQL 运行在容器内。

```sh
npm ci
npm run build
cp .env.example .env
```

编辑 `.env`，为两个 MySQL 密码填入不同的随机值（例如 `openssl rand -hex 24`），不要提交该文件。然后启动 Cloud 开发服务：

```sh
docker compose up -d --wait mysql wordpress
docker compose run --rm cli core install \
  --url=http://localhost:8080 --title='AI Skills Hub Dev' \
  --admin_user=cloud_admin --admin_email=dev@example.invalid --prompt=admin_password
docker compose run --rm cli theme activate ai-skills-hub
docker compose run --rm cli core version
docker compose exec -T wordpress php -v
docker compose exec -T mysql mysql --version
curl --fail http://localhost:8080/
```

`--prompt` 在终端输入随机开发管理员密码，避免写入命令历史。只在 Cloud 内访问 `http://localhost:8080`；数据库没有宿主端口。WP-CLI 与 Web 使用同一数据库和 WordPress volume。初始站点默认英文；内置内容双语在 Phase 2 实施。

```sh
npm run check:assets
npm run lint:php
npm run smoke:wp
docker compose stop
```

`lint:php` 优先使用环境内 PHP，否则使用已运行的 WordPress 容器。CI 自带 PHP。`npm run dev` 监听 CSS，`npm run dev:js` 监听 JS，分别在终端运行；改变依赖后提交 `package-lock.json`。容器镜像使用官方版本系列标签，首次验收记录实际镜像 digest；严格重现时使用报告中的 digest。更新镜像后应重新验收。

`docker compose down` 保留数据卷。`down -v` 会删除开发数据库和站点文件，仅在明确要清空开发站时手动执行。运行数据不随 Git 同步，Cloud 重建后按以上命令重新安装。生成的 `assets/dist` 不入库；下载/启用主题前必须先构建，发布打包流程留待后续阶段。

## 目录与当前范围

- `wp-content/themes/ai-skills-hub`：最小可激活主题、Core 入口、资源源码。
- `compose.yaml`、`.env.example`：Cloud 开发环境。
- `scripts/`、`.github/workflows/`：构建与语法验证。
- `docs/`：环境检查和阶段结果。

Phase 0 不注册 Skills 内容类型、不建业务表、不采集 GitHub、不调用 AI、不实现多语言内容路由。服务器密钥不得进入源码、前端或日志。每阶段完成代码提交与推送，并报告测试限制。
