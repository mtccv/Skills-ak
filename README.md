# AI Skills Hub

WordPress 原创主题项目，内置 AI Skills Hub Core。Phase 0–3 已验收；Phase 4 在现有中英文前台与后台之上完善 Skills/教程搜索、独立 SEO 配置、多语言 Meta、XML Sitemap 与 robots 索引策略。主题/Core 为 0.5.0；schema 1.3.0 仅增加两个搜索索引，不改变业务字段或 Skill ID，没有自动采集或 AI 生成。

完整需求及阶段门禁见 [CODEX_MASTER_SPEC.md](CODEX_MASTER_SPEC.md)，Core 使用说明见 [docs/CORE.md](docs/CORE.md)，本轮记录见 [docs/PHASE_4_REPORT.md](docs/PHASE_4_REPORT.md)，前台及编辑使用说明见 [docs/FRONTEND.md](docs/FRONTEND.md)，搜索与 SEO 维护见 [docs/SEO.md](docs/SEO.md)，阶段进度见 [docs/PROGRESS.md](docs/PROGRESS.md)。[Phase 1 报告](docs/PHASE_1_REPORT.md) 与 [Phase 0 报告](docs/PHASE_0_REPORT.md) 保留为历史证据。[Phase 3 报告](docs/PHASE_3_REPORT.md) 保留前台历史证据。Phase 4 完成后暂停，等待用户下一阶段指令。

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
curl --fail --location http://localhost:8080/en/
```

安装脚本将随机管理员密码保存到被 Git 忽略的 `.dev-admin-password`（权限 0600），并对 WP-CLI 输出脱敏，避免密码进入终端日志。开发用户名为 `cloud_admin`；需要登录时在 Cloud 内私密查看密码文件，不要粘贴到聊天或提交。只在 Cloud 内访问 `http://localhost:8080`；数据库没有宿主端口。WP-CLI 与 Web 使用同一数据库和 WordPress volume。初始站点默认英文；Phase 1 提供双语数据存储，Phase 2 提供后台双语编辑，Phase 3 使用 `/zh/` 与 `/en/` 访问前台；Phase 4 继续使用这些真实语言地址。管理员首次进入后台时会初始化重写规则；空的固定链接设置改为 `/%postname%/`，已有设置保留。Apache 需允许 WordPress 的 .htaccess 重写；前台请求不会自行修改重写或 schema。

```sh
npm run check:assets
npm run lint:php
git diff --check
docker compose stop
```

`lint:php` 优先使用环境内 PHP，否则使用已运行的 WordPress 容器。CI 自带 PHP。Cloud 只有缓存的 PHP 镜像时，也可用隔离的一次性容器只读挂载主题及 tests 执行 `php -l`，不启动数据库。

按当前策略，每阶段运行 PHP、构建和基本代码检查；`tests/basic/` 包含路由、搜索查询合同、教程、SEO、Sitemap 的独立 PHP 检查，可逐个 `php tests/basic/文件名.php` 执行。它们使用函数/数据库替身，不加载真实 WordPress/MySQL；CI 同步执行，不能把断言通过视为集成通过。完整集成留到 Final QA。届时可在可丢弃的开发 WordPress 上执行 `npm run test:core` 与 `npm run smoke:wp`，或手动触发 GitHub Actions 的 `Core checks` 并设置 `final_qa=true`。现有集成脚本使用随机表前缀并在 finally 清理，是 Final QA 的基线而非全部验收。默认 push/PR 不运行 MySQL/WordPress 任务。

`npm run dev` 监听 CSS，`npm run dev:js` 监听 JS，分别在终端运行；改变依赖后提交 `package-lock.json`。容器镜像使用官方版本系列标签，可变标签不保证长期完全重现；已取得的实际版本和 digest 见阶段报告，更新镜像后应重新验收。

`docker compose down` 保留数据卷。`down -v` 会删除开发数据库和站点文件，仅在明确要清空开发站时手动执行。运行数据不随 Git 同步，Cloud 重建后按以上命令重新安装。生成的 `assets/dist` 不入库；下载/启用主题前必须先构建，发布打包流程留待后续阶段。

## 目录与当前范围

- `wp-content/themes/ai-skills-hub`：原创主题、模块化 Core、原生 Admin 服务/界面及资源源码。
- `compose.yaml`、`.env.example`：Cloud 开发环境。
- `scripts/`、`.github/workflows/`：构建与语法验证。
- `tests/basic/`：隔离路由、搜索、SEO 与 Sitemap 合同检查。
- `tests/integration/core.php`：数据库结构、重复安装、实际增量升级、双语关联与安全测试基线，留 Final QA 执行。
- `docs/`：环境检查和阶段结果。

Skills 采用自定义表作为主存储，内部 ID 与语言无关；不注册影子 CPT。内置双语路由与 WPML 公开 API/适配接口并存，不采集 GitHub、不调用 AI；真实 WPML、Yoast、Rank Math 联调留 Final QA。服务器密钥不得进入源码、前端或日志。每阶段完成代码提交与推送，并报告测试限制。

## Phase 2 后台使用

管理员进入 WordPress 后台的 **AI Skills Hub**，可访问 Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings。

创建 Skill 时可以不选择仓库；手工分别维护中文/英文标题、简介、正文和 SEO。保存草稿允许内容不完整，空白译文不会自动补全。提交审核后，有审核能力的用户选择风险等级并记录备注；批准当前修订且至少一种语言内容完整才会发布。已发布内容修改后必须重新审核。预览只对授权用户开放，不是公开页面。

删除采用归档，服务器确认页要求二次提交，保留原记录与关联。默认只有管理员获得管理能力；其他角色必须由站点管理员明确分配相应 `aish_*` capability。升级前备份数据库；历史 1.2.0 提供管理字段，当前 1.3.0 只增索引，详情见 Core 文档。

## Phase 3 前台与真实内容

访问 `/zh/` 或 `/en/`，使用首页、Skills 列表/详情、分类/标签、教程列表/详情、搜索、About、Contact、Submit a Skill 与 404。所有资源列表和统计读取真实数据库；没有内容时显示空状态，不自动生成示例 Skills 或译文。

Skills 必须通过 Phase 2 人工审核且当前修订保持批准才能公开。编辑后旧批准失效，缺少完整翻译的语言不会显示虚构内容。平台/许可证/精选复用已有标签（`platform-*`、`license-*`、`featured`）；使用说明复用正文 H2，配置说明见 FRONTEND.md。无作者字段时显示“未提供”，仓库维护者另列。

后台新增 **教程 / Tutorials** 与 **来信与投稿 / Inbox**。教程使用原生 WordPress 文章及双语元数据；About/Contact/Submit 正文使用“页面”的语言/用途设置。联系与投稿表单真实保存为私有待处理记录，不发送邮件、不直接写入 Skills。管理员人工处理后仍须在 Skills 后台录入和审核。

Phase 3 的完整前台已验收，Phase 4 继续保留其页面、Core 八表和原生后台。完整浏览器/WordPress/MySQL/WPML/端到端测试留 Final QA；当前只执行必要语法、构建与基本代码检查，准确结果见本轮报告。

## Phase 4 搜索与 SEO

Skills 和教程支持当前语言标题、摘要、正文的中英文关键词搜索，以及分类/标签/平台组合筛选、排序和分页。教程的分类筛选通过其实际关联且可公开的 Skill 实现；不会自动创建教程分类副本或关联草稿。关键词为字面子串，不自动翻译；分页与语言切换保留合理筛选参数。查询复用请求内结果，关联资源、分类/标签、作者和元字段批量读取。

后台 **AI Skills Hub → SEO** 分别维护中文/英文页面的标题、描述、分享图片和 noindex。Skill 标题/描述沿用既有双语编辑器与审核；教程及说明页使用原生编辑器 SEO 面板。Canonical 自动指向当前语言规范地址，hreflang 仅列出真实可索引译文，x-default 使用实际可用语言。Hub 路由由主题统一输出 Meta，通过公开 hooks 避免与 Yoast/Rank Math/WPML 重复；真实插件联调待 Final QA。

`/aish-sitemap.xml` 按语言及类型分页收录公开 Skills、非空分类/标签、教程和必要静态页面，每个子文件 200 URL。排除缺译、未审核/归档、noindex、搜索/筛选/排序和投稿页面；原生 WordPress/Yoast/Rank Math 索引引用同一组 Hub XML。lastmod 使用真实已有日期，不伪造更新时间。

robots.txt 使用 WordPress 动态输出，保留后台及其他插件规则；不添加会遮蔽页面 noindex 的搜索/筛选 Disallow。关闭站点搜索引擎可见性后，主题 Sitemap 停用并保持 noindex。私有记录仍由 capability/nonce 保护，robots 不能替代权限。配置与排查细节见 [SEO.md](docs/SEO.md)。

schema 1.3.0 只增加 `public_updated(status,review_status,updated_at,id)` 与 `locale_title(locale,title,skill_id)` 两个非唯一索引；前导 `%` 子串和完整性正则仍可能扫描候选数据，不宣称已经通过大规模性能验收。升级在管理员后台或可信 WP-CLI 执行，前台不做 DDL。
