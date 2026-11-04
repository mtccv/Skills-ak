# AI Skills Hub

WordPress 原创主题项目，内置 AI Skills Hub Core。Phase 0–5 已验收；Phase 6 在既有双语前台、原生后台、搜索、SEO 和 GitHub 采集之上增加可替换 AI Provider、结构化双语草稿、分类/安装/风险建议及人工采用流程。主题/Core 为 0.7.0；schema 1.5.0 保留八张核心表和三张采集运营表，仅追加 AI 任务、尝试用量及审稿记录。Skill ID、来源唯一键和双语关系不变；AI 不能自动发布或覆盖人工内容。

完整需求及阶段门禁见 [CODEX_MASTER_SPEC.md](CODEX_MASTER_SPEC.md)，Core 说明见 [docs/CORE.md](docs/CORE.md)，本轮记录见 [docs/PHASE_6_REPORT.md](docs/PHASE_6_REPORT.md)。AI 操作、API 配置及安全边界分别见 [AI_USAGE.md](docs/AI_USAGE.md)、[API_CONFIGURATION.md](docs/API_CONFIGURATION.md)、[AI_SECURITY.md](docs/AI_SECURITY.md)。采集维护见 [GITHUB_IMPORT.md](docs/GITHUB_IMPORT.md)、[IMPORT_SECURITY.md](docs/IMPORT_SECURITY.md)，前台见 [FRONTEND.md](docs/FRONTEND.md)，搜索与 SEO 见 [SEO.md](docs/SEO.md)，进度见 [PROGRESS.md](docs/PROGRESS.md)。Phase 0–5 报告保留历史证据，不改写为本轮验收。Phase 6 完成提交同步后暂停，等待 Phase 7 指令。

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

按当前策略，每阶段运行 PHP、构建和基本代码检查；`tests/basic/` 包含路由、搜索查询合同、教程、SEO、Sitemap、GitHub 采集和 AI Provider/结构化内容/任务/人工保护的独立 PHP 检查，可逐个 `php tests/basic/文件名.php` 执行。它们使用模拟响应、函数/数据库替身，不调用真实 AI/GitHub API 或加载真实 WordPress/MySQL；CI 同步执行，不能把断言通过视为集成通过。完整集成留到 Final QA。届时可在可丢弃的开发 WordPress 上执行 `npm run test:core` 与 `npm run smoke:wp`，或手动触发 GitHub Actions 的 `Core checks` 并设置 `final_qa=true`。现有集成脚本使用随机表前缀并在 finally 清理，是 Final QA 的基线而非全部验收。默认 push/PR 不运行 MySQL/WordPress 任务。

`npm run dev` 监听 CSS，`npm run dev:js` 监听 JS，分别在终端运行；改变依赖后提交 `package-lock.json`。容器镜像使用官方版本系列标签，可变标签不保证长期完全重现；已取得的实际版本和 digest 见阶段报告，更新镜像后应重新验收。

`docker compose down` 保留数据卷。`down -v` 会删除开发数据库和站点文件，仅在明确要清空开发站时手动执行。运行数据不随 Git 同步，Cloud 重建后按以上命令重新安装。生成的 `assets/dist` 不入库；下载/启用主题前必须先构建，发布打包流程留待后续阶段。

## 目录与当前范围

- `wp-content/themes/ai-skills-hub`：原创主题、模块化 Core、原生 Admin 服务/界面及资源源码；`inc/core/Sources` 为采集，`inc/core/AI` 为 AI Provider、提示/输出、任务和审稿实现。
- `compose.yaml`、`.env.example`：Cloud 开发环境。
- `scripts/`、`.github/workflows/`：构建与语法验证。
- `tests/basic/`：隔离路由、搜索、SEO、Sitemap 与模拟 GitHub / AI 检查。
- `tests/integration/core.php`：数据库结构、重复安装、实际增量升级、双语关联与安全测试基线，留 Final QA 执行。
- `docs/`：环境检查和阶段结果。

Skills 采用自定义表作为主存储，内部 ID 与语言无关；不注册影子 CPT。内置双语路由与 WPML 公开 API/适配接口并存。GitHub 采集和 AI 生成仅处理管理员授权的来源与任务，在后台分步运行；普通前台不执行扫描或 AI 请求。真实 AI/GitHub、WordPress/MySQL、WPML、Yoast、Rank Math 联调留 Final QA。服务器密钥不得进入源码、前端或日志。每阶段完成代码提交与推送，并报告测试限制。

## Phase 2 后台使用

管理员进入 WordPress 后台的 **AI Skills Hub**，可访问 Dashboard、Skills、Add New Skill、Categories、Tags、Repositories、Reviews、Settings。

创建 Skill 时可以不选择仓库；手工分别维护中文/英文标题、简介、正文和 SEO。保存草稿允许内容不完整，空白译文不会自动补全。提交审核后，有审核能力的用户选择风险等级并记录备注；批准当前修订且至少一种语言内容完整才会发布。已发布内容修改后必须重新审核。预览只对授权用户开放，不是公开页面。

删除采用归档，服务器确认页要求二次提交，保留原记录与关联。默认只有管理员获得管理能力；其他角色必须由站点管理员明确分配相应 `aish_*` capability。升级前备份数据库并暂停任务执行；历史 1.2.0 提供管理字段、1.3.0 增搜索索引、1.4.0 增采集元数据和运营表，当前 1.5.0 增 AI 运营表，详情见 Core 文档。

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

## Phase 5 GitHub 采集

在 **AI Skills Hub → GitHub Imports** 添加公开 GitHub 仓库根 URL、设置同步间隔，或为已有仓库排队“完整扫描/增量同步”。定期同步默认关闭；间隔 1 小时至 7 天，默认 24 小时。手动批量最多 10 个仓库，同仓库仅一个活动任务且新任务至少间隔 60 秒。查看任务详情、来源变更、File/Commit SHA、License、静态风险及采集日志后，进入既有 Skills/Reviews 人工编辑和审核。归档仓库停用并取消任务，保留 Skills、版本和日志。

公开仓库可匿名访问；需要提高 API 配额时，在服务器 PHP 配置定义 `AISH_GITHUB_TOKEN` 或注入同名环境变量。不要把真实 Token 写入仓库、示例文件、前端或日志；后台仅显示是否配置及限流状态。Cloud 容器需由受信任部署配置把变量传入 PHP，不能只在宿主终端设置后假设容器可读取。详细设置与排障见 [采集说明](docs/GITHUB_IMPORT.md)。

Cron 每 60 秒计划执行一个可恢复步骤：仓库 → 提交 → 树 → 逐文件 → 核对。单 worker 数据库锁、120 秒租约、同一步最多 5 次连续失败和 48 小时截止限制任务；WordPress Cron 依赖请求，计划间隔不保证准点。固定提交树最多 10000 条、500 个 Skills；递归 Tree 截断/响应超限回退逐层扫描，仍不完整则报告 incomplete，不推断删除。文件上限 512 KiB，使用安全 YAML 子集，不执行脚本或安装依赖。

每个来源按 `repository_id + source_path` 去重，保留内部 Skill ID。新采集 Skill 固定 pending、不创建译文；来源更新只保存 incoming 快照和风险结果，不覆盖人工内容、slug 或审核。相同文件 SHA 的新提交仍重新检查目录/依赖/许可证变化；缺失/疑似移动保留记录交人工处理。静态提示不是安全认证，未知许可证待核实，详见 [安全说明](docs/IMPORT_SECURITY.md)。

Phase 5 的历史检查及真实 API/MySQL/WordPress 待测范围见其报告；本轮继续保留采集功能，不将既有模拟证据视为真实运行验收。

## Phase 6 AI 内容生成

在 **AI Skills Hub → AI Settings** 配置 OpenAI 或 OpenAI 兼容服务的模型档案、HTTPS Base URL、生成参数、超时、Token 上限及可选价格/费用上限。API Key 由服务器 `AISH_AI_KEY_<大写档案ID>` 常量或环境变量提供，后台仅显示固定掩码；不存入 WordPress options。非默认 API 主机还必须由服务器 `AISH_AI_ALLOWED_HOSTS` 明确允许，不能通过仓库文本选择目标。具体配置和 Cloud 容器传递方式见 API_CONFIGURATION.md。

AI 队列默认暂停。管理员配置后可排队连接测试或对已采集 Skill 单个/批量生成，启用队列后由 WordPress Cron 每次执行一个请求。支持双语、仅中文、仅英文、仅摘要和仅使用场景；批量最多 10 个 Skill。连接测试也可能产生费用，需要同时拥有 AI 设置与生成权限，仅配置角色不能单独发起请求。超时或网络结果不确定的尝试不盲目自动重发，人工重试需要理解可能重复计费；普通页面不调用模型。

AI Generation 显示来源原文、SHA、结构化输出、任务与失败历史、Token 和估算费用，并允许单独编辑双语审稿草稿、接受或拒绝。AI 输出和人工编辑草稿分开留档；采用前核对来源和内容版本，只能填入所选仍为空的标题、摘要或正文，已存在人工字段不会覆盖。使用场景等部分结果保留为建议，由编辑在正常 Skills 编辑器有意识地合并；采用后仍为 pending，补充真实 Slug/内容并按 Reviews 审核后才可公开。

分类/标签/平台和安装说明都要求来源依据，新增术语只提供建议，不自动创建或关联；未知安装步骤、依赖和 License 标为待核实。模型没有执行 Skill 命令或读取密钥的工具权限。AI 风险结果是人工线索，不能替代静态检查或安全认证。准确操作与限制见 AI_USAGE.md / AI_SECURITY.md；模拟检查和真实 AI API 未执行范围见 Phase 6 报告。完成后暂停，不自动进入 Phase 7。
