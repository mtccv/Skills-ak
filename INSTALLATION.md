# AI Skills Hub 安装与维护

Phase 14 使用 **0.14.0 开发验证版**，schema **1.9.0 / 33 表保持**，没有新 Core 表迁移。使用 [开发安装包](release/ai-skills-hub-0.14.0.zip)，升级前保留数据库、媒体、配置及原主题备份。帮助原稿仅由管理员明确导入为草稿；引导可跳过，邮件精选须独立同意与确认，不自动导入任何注册或 Contact 邮箱。先阅读 [Phase 14 安装与使用](INSTALLATION_GUIDE.md)、[本轮测试与包验证](PHASE_14_REPORT.md)。不要导入 QA 数据，不因为安装包可用就视为正式上线验收。产品级 [Final QA 2.0](FINAL_QA_2_PLAN.md) 仍是待授权计划。

历史 Phase 13 已在真实 WordPress7.1.4/PHP8.4/MySQL8.0 环境完成 0.12.0→0.13.0 原生 ZIP 升级、新进程启动和数据/文件一致性检查；见 [Phase 13 报告](docs/PHASE_13_REPORT.md)。该历史事实不替代本轮验收，也未部署外部服务器。已有商业预留默认关闭、免费功能保持，规则见 [商业预留说明](docs/COMMERCIAL_READINESS.md)。

Phase11安装或升级只追加7张发现表，原26表保留。启用后管理员进入AI Skills Hub确认schema1.9.0、共33表，再配置精选/场景/平台及首页；未配置时显示真实空状态。有效浏览默认关闭，管理员开启后访客仍须明确同意。不得向正式站点导入QAfixtures或制造榜单计数。详见 [资源发现](DISCOVERY_SYSTEM.md)、[场景](USE_CASES.md)。

适用于主题 / 内置 Core **0.14.0**，数据库 schema **1.9.0**。安装包、版本和 SHA-256 应来自同一次已验证构建。本指南沿用 Codex Cloud 开发与验收环境，不要求本机开发、宝塔或外部服务器部署。

主题提供前台和全部 Core 模块；WordPress、数据库、配置密钥、媒体及业务数据不包含在 ZIP 中。切换主题会停用 Hub 路由和后台功能，并清理其 Cron 事件，但不删除 Skills、译文、任务或历史。GitHub 采集与 AI 草稿都必须经过人工审核，安装不会自动生成或发布示例资源。

## 1. 环境与安装包

| 项目 | 要求 |
| --- | --- |
| WordPress | 主题声明最低 6.8；使用受维护版本，具体已验版本见本次交付测试记录 |
| PHP | 8.3+；启用 mysqli、JSON、mbstring、DOM/XML、sodium；头像需要 GD 或 Imagick，隐私 ZIP 导出需要 ZipArchive，支持 WordPress HTTPS 请求；核对所用 PHP 版本的实际验收记录 |
| 数据库 | MySQL、InnoDB、utf8mb4；Compose 默认 MySQL 8.4。Cloud完成8.0真实矩阵，独立CI另通过8.4.13的Core基线；两者覆盖范围分别记录 |
| Web | 正常运行的 WordPress；Apache 允许 `.htaccess` 重写，或实例现有 Web 层具有等效 WordPress 路由规则 |
| 权限 | 管理员安装主题；数据库用户能执行正常读写、版本化 CREATE / ALTER / 索引和连接锁操作 |
| 源码构建 | 仅从源码制作安装包时需要 Node.js 22/24、npm、Python 3.10+；安装现成 ZIP 不需要 Node.js |
| 网络 | GitHub 使用固定 `api.github.com` HTTPS API；AI 使用显式允许的公网 HTTPS API。保留 Cloud 代理与 CA，不能关闭 TLS 验证 |

使用源码构建时，在仓库根目录执行：

```sh
npm ci
npm run build
npm run check:assets
python3 scripts/package-theme.py --check
python3 scripts/package-theme.py
```

输出为 `release/ai-skills-hub-0.14.0.zip` 和 `release/SHA256SUMS`；实际文件名自动读取 `style.css` 的 Version，并要求 Core 版本一致。脚本不会代替前端构建；缺少非空 `assets/dist/app.css` / `app.js` 或必要模板时直接失败。`--check` 只校验输入，不创建安装包。

ZIP 只有一个 `ai-skills-hub/` 顶级目录，包含主题 PHP、模板、资源源码、已构建资源，以及从仓库根复制的 `LICENSE`。不打入整个 GitHub 仓库、WordPress 核心、数据库、测试、依赖目录、隐藏文件、缓存、备份及已知敏感配置文件；拒绝主题中的符号链接和特殊文件。打包前仍须人工检查源码没有硬编码凭据，文件筛选不能替代密钥审查。

文件顺序和元信息固定，目录权限为 0755、文件为 0644；ZIP 使用无需压缩器的存储格式。默认时间统一为 1980-01-01 UTC，可显式设置合法的 `SOURCE_DATE_EPOCH`（1980–2107）。相同内容与时间参数产生相同 ZIP；打包脚本生成的 `SHA256SUMS` 只列本次版本；仓库交付清单另保留历史 0.7.1、0.8.0、0.9.0 、0.10.0 、0.11.0 、0.12.0、0.13.0 和当前 0.14.0 校验。`--output-dir` 可指定其他交付目录。

在 Cloud 中核对下载或复制后的包：

```sh
cd release
sha256sum -c SHA256SUMS
```

包的 SHA 不匹配时先重新取得正确文件，不安装不一致的包。仅更新版本号或 PHP 文件之后也必须重新生成 SHA。

## 2. Codex Cloud 从源码启动

以下复用已有 [README](README.md) 和 `scripts/install-wordpress.py`，不建立另一套开发环境。

1. 准备当前仓库，执行前端构建。
2. 将 `.env.example` 复制为被 Git 忽略的 `.env`。为 `MYSQL_PASSWORD` 和 `MYSQL_ROOT_PASSWORD` 分别设置不同随机值；不要将其输出到聊天、日志或 Git。
3. 启动服务。仅首次空站运行安装脚本；已有站点不要重新初始化数据库。

```sh
docker compose up -d --wait mysql wordpress
python3 scripts/install-wordpress.py
docker compose run --rm cli wp theme activate ai-skills-hub
docker compose run --rm cli wp --user=cloud_admin eval 'do_action("admin_init"); echo AISkillsHub\Core\Core::boot()->migrator()->version(), PHP_EOL;'
```

首次安装的管理员名为 `cloud_admin`；随机密码保存在 Cloud 中被忽略的 `.dev-admin-password`，权限 0600。私密查看它以登录，不复制到交付记录。已有站点使用自己的管理员身份，替换上述 `--user` 值。

浏览 Cloud 的 `http://localhost:8080/wp-admin/`、`/zh/`、`/en/`。Compose 只绑定宿主 localhost，MySQL 不开放宿主端口。`docker compose stop` 或不带 `-v` 的 `down` 保留数据卷；**不要使用 `down -v` 作为升级步骤**，它会删除卷。

当前 Compose 将仓库主题只读挂载到 WordPress。该模式下通过源码更新和重新构建维护主题；后台 ZIP 覆盖安装不能写入此挂载。不要为更新包而清空数据库或删除站点卷。

## 3. 在 Cloud WordPress 中安装 ZIP

此方式用于已有的、主题目录可写的 Cloud WordPress 实例；不要同时让同一主题路径被仓库只读挂载。

1. 确认 WordPress 已安装、上述依赖可用，检查 ZIP 的 SHA-256；已有数据先按第 8 节备份。
2. 管理员打开“外观 → 主题 → 安装主题 → 上传主题”，选择 `ai-skills-hub-0.14.0.zip`，安装并启用。已有同名主题时按 WordPress 界面确认替换当前版本。
3. 再次打开后台，确认 AI Skills Hub 顶级菜单存在，且没有数据库升级错误通知。首装及旧版升级通过现有迁移器执行；成功版本应为 `1.9.0`，共 33 张 Core 表。
4. 打开“设置 → 固定链接”保存一次，检查 `/zh/`、`/en/`。主题首次管理员初始化会为普通链接设置 `/%postname%/`，保留已有非空固定链接规则。
5. 确认前台加载 `assets/dist/app.css` 和 `app.js`；没有内容时应显示真实空状态。
6. 按 [邮件配置](EMAIL_SETUP.md) 配置 sodium 专用加密密钥、`AISH_ACCOUNT_MAIL_ENABLED`、可信邮件传输与 Cron，再测试中英文注册、验证及恢复。没有配置时新注册保持关闭，已有管理员仍可原生登录。`wp_mail()` 成功仅表示传输受理，不等于收到邮件。
7. 核对 schema 1.9.0 共 33 表、原有数据全部保留，后台出现独立社区评价/举报/纠错菜单；以验证后的测试账户检查收藏、待审评价、修订审核和撤回，详见 [社区说明](COMMUNITY_REVIEWS.md)。
8. 核对 `/zh/login/`、`/en/register/`、本人账户中心、私有头像和隐私申请；详见 [账户说明](ACCOUNT_SYSTEM.md) 与 [隐私安全](PRIVACY_AND_SECURITY.md)。账户策略随主题加载，主题切换保留数据，但不继续执行账户保护和邮件调度。

也可在同一 Cloud 实例使用 WP-CLI，路径应指向该实例实际可读的 ZIP：

```sh
wp theme install /path/to/ai-skills-hub-0.14.0.zip --activate
wp --user=YOUR_ADMIN_USER eval 'do_action("admin_init"); echo AISkillsHub\Core\Core::boot()->migrator()->version(), PHP_EOL;'
```

上面的路径和管理员名是待替换参数。不要将项目仓库 ZIP 当主题 ZIP 上传，否则目录层级和构建资源可能不符合 WordPress 安装要求。

## 4. 首次内容与权限配置

- **Settings**：设置默认中文或英文、后台列表数量。`/` 跳转默认语言，正文语言使用 `/zh/` 和 `/en/`。
- **Skills / Add New Skill**：可人工录入无 GitHub 来源的资源，也可编辑采集到的待审核记录。同一个 Skill ID 维护中英文标题、Slug、简介、正文和 SEO；缺译留空。
- **Categories / Tags**：维护真实分类、标签。`featured`、`platform-*`、`license-*` 是现有标签约定；只有确认来源依据后才填写平台或许可证。
- **Reviews**：至少一种语言的标题、Slug、简介和正文完整，提交待审核后由有权限的人批准当前修订。草稿保存、AI 生成或“来源已处理”都不等同发布。编辑已发布内容后必须重新审核。
- **Tutorials / WordPress 页面**：在双语元信息面板明确设置语言与翻译关系。About、Contact、Submit 页面还要选择对应页面用途。Contact / 投稿保存在私有 Inbox，不发送邮件，也不直接创建 Skill。
- **SEO**：分别设置中英文页面信息。Canonical 自动计算；未完成译文不输出假 hreflang。搜索引擎可见性由 WordPress“设置 → 阅读”控制；验收或私有站点保持不公开索引。

默认只有管理员获得 Hub 能力；不要将“已登录”当作管理权限。按实际职责分配 `aish_manage_skills`、`aish_review_skills`、`aish_manage_taxonomies`、`aish_manage_repositories`、`aish_manage_settings`、`aish_manage_ai_settings`、`aish_generate_content`。AI 草稿采用同时需要生成、Skills 管理和审核能力；连接测试需要 AI 设置和生成能力。

WPML 是可选商业插件，不随 ZIP 提供。使用已有合法授权插件，并按当前交付报告确认真实兼容覆盖；接口测试不代表全部插件版本通过。Yoast / Rank Math 与 Hub 的 Meta/Sitemap 所有权说明见 [SEO 文档](docs/SEO.md)，避免同时由多个插件接管同一类页面。保留 WordPress 动态 `robots.txt`，不要放入遮蔽它的物理文件，也不要用 robots 代替私有内容权限检查。

## 5. GitHub 采集

在 **AI Skills Hub → GitHub Imports** 登记 `https://github.com/owner/repository` 形式的公开仓库，手动选择完整扫描或增量同步。一个仓库可含多个 `SKILL.md`；来源按内部仓库 ID + 文件路径去重。定期同步默认关闭，间隔范围为 1 小时至 7 天，默认 24 小时。

公开 API 可不带 Token。需要配置时，在受保护的 Cloud 秘密配置中设置 `AISH_GITHUB_TOKEN`，并确保传入 WordPress **及执行 Cron/WP-CLI 的 PHP 进程**。现有 Compose 已映射此变量；值只放入被忽略的 `.env` 或托管秘密配置。也支持受保护的 WordPress 配置常量，不能将真实值写入主题、页面或仓库。改变容器环境后重建相关容器并核对后台“已配置”状态，不显示 Token 明文。

新采集资源只进入 pending，不自动创建译文或发布。查看任务、File / Commit SHA、来源变更、License 和静态风险，再人工编辑/审核。`incomplete` / `failed` 不能当作完整清单；truncated Tree 会按目录继续，未完成时不推断删除。来源缺失或疑似移动保留记录及历史，交人工处理。不会执行源文件、脚本或依赖安装。

关闭定期同步只阻止未来定时排队；已有手动任务仍可能运行。需要停止某仓库全部采集时，使用确认后的“归档并停用”，保留数据但取消其任务。风险扫描只是线索，未知 License 仍需核实。完整流程见 [GitHub 采集说明](docs/GITHUB_IMPORT.md) 和 [采集安全说明](docs/IMPORT_SECURITY.md)。

## 6. AI Provider 安全配置

在 **AI Settings** 建立模型档案，选择 OpenAI 或 OpenAI-compatible；填写真实 Model、HTTPS Base URL、Temperature、超时 5–45 秒、输出 Token 上限、供应商支持的 Token 参数。兼容层使用 Chat Completions + JSON object，不自动适配任意厂商协议或 Responses API。

| 配置 | 放置位置 |
| --- | --- |
| `AISH_AI_KEY_DEFAULT` | ID 为 `default` 的档案密钥，服务器秘密配置 |
| `AISH_AI_KEY_COMPATIBLE` | ID 为 `compatible` 的档案密钥，服务器秘密配置 |
| `AISH_AI_KEY_<大写档案ID>` | 其他档案对应密钥；需另外映射到容器/进程 |
| `AISH_AI_ALLOWED_HOSTS` | 服务器允许的精确主机名列表，默认 `api.openai.com`；不接受 URL、通配符、IP、内网或 localhost |
| Provider / Model / 参数 / 单价 / 金额上限 | WordPress AI Settings；不在此输入 API Key |

现有 Compose 传递 default / compatible 的 Key 和主机白名单。新增档案 ID 不会自动创建相应容器环境映射。PHP 配置常量优先于同名环境变量；排查时只检查是否配置，不打印 Key、请求头或完整环境。后台只显示固定掩码，不允许取回明文。没有 Key 时保持 AI 暂停，不能把 Mock 测试标为真实 API 验证。

配置启用与全局队列启用是两项操作。先确认费用与来源授权，再由管理员排队连接测试并启用队列；连接测试也可能计费，成功仅说明这一次 JSON 协议请求有效。可配置输入/输出 USD/百万 Token 单价和单任务金额上限；费用是估算，缺失用量显示未知，不保证供应商账单与估算一致。

AI Generation 可对已采集来源生成双语、单语言、摘要或使用场景草稿，每批最多 10 项。模型输入是有界、不可信的来源数据；不授权它执行指令、安装依赖、读取秘密或发布内容。生成完成后在后台比较原文、原始 AI 输出、人工修改稿及正式译文。只能明确采用仍为空的字段，已有人工文本、Slug 和 SEO 不被覆盖；采用后回到 pending，继续正常人工审核。

超时、连接中断或 worker 消失可能已有费用，结果记为不确定，不自动盲重发；先核对供应商请求/账单，再决定人工重试。修改模型配置不会静默改变已排队任务的冻结配置。详细字段与故障说明见 [API 配置](docs/API_CONFIGURATION.md)、[AI 使用](docs/AI_USAGE.md)、[AI 安全](docs/AI_SECURITY.md)。

## 7. Cron 调度与观察

首次以管理员访问后台会注册每分钟的 `aish_import_tick`、`aish_ai_tick` 和 `aish_account_mail_tick`。来源任务每次推进一个可恢复步骤，AI 每次最多一个请求，账户邮件每次处理有界工作，均受数据库锁和任务租约控制。普通前台不在页面处理栈执行长扫描或生成；WordPress 默认 Cron 依赖访问触发，低流量时会延迟。

在现有 Compose 中查看事件：

```sh
docker compose run --rm cli wp cron event list --fields=hook,next_run_gmt,recurrence
```

若需要独立按分钟触发，使用 Cloud 现有受控调度执行下列命令；它会运行 WordPress **全部到期事件**，可能发出 GitHub/AI 请求，并非只读检查：

```sh
docker compose run --rm -T cli wp cron event run --due-now
```

确认该调度实际工作后，才在受保护的 WordPress 配置中设置 `DISABLE_WP_CRON=true`，避免依赖访问触发。没有替代调度时不要关闭 WP-Cron。无需安装 Action Scheduler。调度应使用同一数据库、同一版本主题、同一组秘密映射；不要同时在多个不受控进程中强制循环执行。

观察后台状态、下次可执行时间、限流 reset / Retry-After 和受控错误码。AI 全局/单任务暂停不会撤销已发送的 HTTP；正在执行的任务需如实记录结果。租约过期后按已有恢复流程处理，不手动清空任务表、重置 Skill ID 或修改已知用量。

## 8. 备份、升级与回退

**升级前**：暂停 AI 队列，暂停 Cloud 外部调度，并安排维护窗口阻止新的站点写入与 WP-Cron 触发；等待已发出的请求结束。仅关闭来源定期同步不足以停止现有任务。不要为升级而归档业务仓库或取消所有任务，暂停调度即可。

备份范围包括完整 WordPress 数据库（含原生 posts/postmeta/options/users 和全部三十三张 `{$wpdb->prefix}aish_*` 表）、`wp-content` 媒体/插件/主题、受保护的 WordPress/秘密配置、上一版本安装包及校验值。没有“只备份主题就保留数据”的替代方案。

先将 `AISH_BACKUP_DIR` 设置为**仓库外、非 Web 目录的受保护持久备份路径**。确认数据库表为 InnoDB，停止 DDL/写入后可用现有 Compose 导出：

```sh
: "${AISH_BACKUP_DIR:?请先设置仓库外的受保护备份目录}"
umask 077
mkdir -p "$AISH_BACKUP_DIR"
docker compose run --rm -T cli wp --quiet db export - --single-transaction --quick > "$AISH_BACKUP_DIR/database.sql"
docker compose exec -T wordpress tar -C /var/www/html -czf - wp-content > "$AISH_BACKUP_DIR/wp-content.tar.gz"
```

另行私密备份 `wp-config.php` 和真实秘密配置，不打印或提交它们。核对导出命令成功、文件非空，并在独立 Cloud 验证实例实际恢复后再认为备份可用。命令失败生成的空文件不是有效备份；Cloud 临时目录也不能替代持久备份。

**升级步骤**：记录当前主题/Core/schema 版本 → 备份 → 校验新包 → 在可写主题模式通过 WordPress 替换同名包，或在源码挂载模式更新代码并重新构建 → 管理员进入后台执行版本化迁移 → 确认 schema `1.9.0` 与 33 张 Core 表、前台资源/双语路由/私有门禁正常 → 核对原有 Skill ID、译文、来源、版本及 AI 历史仍在 → 恢复调度与需要的队列。

迁移只在授权后台或可信 WP-CLI 中执行，前台不执行 DDL；版本成功验证后才推进。升级错误时保留现有数据和最后成功版本，修复原因后重试，不清表重建、不改写 schema option 冒充成功。MySQL DDL 不能承诺整体回滚；断线后的 COMMIT 或已发 HTTP 也不能假称撤销。

**回退**：保持维护和暂停状态。确认旧代码可读取当前 schema；不支持时，必须在独立实例先验证配套数据库/文件备份的恢复，而不是只覆盖旧 PHP 或强制降低 schema 版本。恢复会丢失备份之后的新写入，应先保留当前数据并核对影响。主题停用/切换本身不删数据；重新启用后以管理员打开后台，重新确认迁移和 Cron，最后再恢复处理。

## 9. 安装后核对与排障

| 现象 | 检查 |
| --- | --- |
| `/zh/`、`/en/` 404 | 管理员保存固定链接；确认请求交给 WordPress、主题启用及重写有效 |
| 页面无样式或菜单不工作 | ZIP 是否包含非空 `assets/dist/app.css` / `app.js`，资源请求是否 200；源码模式重新构建 |
| 数据库升级提示 | 数据库权限/可用性、当前 schema；使用现有迁移器重试，保留数据，不删除表 |
| Skills 列表为空 | 当前语言内容完整、状态 published、当前修订已批准；草稿/缺译应保持不可见 |
| 新用户注册提示服务未就绪 | sodium、专用加密密钥、明确启用标志、邮件传输及账户 Cron；不要用放宽验证绕过配置 |
| 队列一直 queued / paused | Cron 是否注册和实际触发、全局/单任务暂停、下次执行时间、仓库开关、密钥是否传入实际 worker |
| GitHub 限流 / incomplete | 查看受控错误和限流时间；等待退避或修复源格式，不能强制改成 complete |
| AI failed / uncertain | 查配置/模型参数/输出错误及供应商账单；未知结果不盲重试，不手工清零用量 |
| Canonical / hreflang / XML 不正确 | 对应语言是否真实公开、noindex 设置、SEO 插件所有权与缓存；缺译不应伪造链接 |
| 私有内容或旧发布页仍被缓存 | 保留主题 no-store/noindex 及权限检查，核对额外缓存层；清理缓存不能代替修复公开门禁 |

历史 0.7.1 包的全新安装、升级、重装和切换恢复记录见 [Final QA](FINAL_QA_REPORT.md)，不能直接作为新版本验收。历史 0.8.0 包包含 96 个主题文件，源码验证、邮件 Mock 和未执行范围见 [Phase 8 报告](docs/PHASE_8_REPORT.md)，包的实际校验记录见 [发布证据](release/PHASE_8_PACKAGE.json)：该次已在隔离 WordPress/PHP 8.4/MySQL 8.0 实际完成原生 ZIP 升级，原十四表数据保留、schema 1.6.0 十七表和全部 96 文件核对通过；未重新执行 0.8.0 空库 ZIP 安装。启用本主题会通过公开filters关闭Yoast/RankMath XML Sitemap缓存，保证Hub索引动态更新；正常只选择一个SEO插件。交付验收范围和未执行项以最新 [进度记录](docs/PROGRESS.md) 及 Final QA 结果为准。真实 AI Key、WPML 商业插件或目标数据库版本缺失时须保留对应限制；本指南和 ZIP 构建成功不构成真实 API、插件全版本或生产部署验收。


历史 0.9.0 包包含 113 个主题文件，Phase 9 真实 WordPress/MySQL、社区并发、隐私和浏览器检查以 [Phase 9 报告](docs/PHASE_9_REPORT.md) 为准；ZIP 升级及精确文件校验记录见 [Phase 9 发布证据](release/PHASE_9_PACKAGE.json)。旧阶段通过结果不自动继承为新版本验收。

## Phase 10 投稿与开发者中心（历史迁移说明）

登录原生 WordPress 管理员后台触发版本化迁移及四项独立审核能力安装。1.7.0→1.8.0 仅增加 author_claims，共 26 表，原私有 Inbox 与 25 表保留。新投稿须登录且验证邮箱；旧匿名投稿仍留在历史 Inbox，不会因邮箱一致转给新账户。投稿接受不等于公开发布，结果资源须经过既有 Skill 审核。

用户中心增加“我的投稿”和“开发者中心”；资料公开须本人选择并经人工审核。站内通知保存不代表电子邮件已发送。作者认领、更新建议、隐私清理与实际限制见 [SUBMISSIONS.md](SUBMISSIONS.md)、[DEVELOPER_CENTER.md](DEVELOPER_CENTER.md)、[PRIVACY_AND_SECURITY.md](PRIVACY_AND_SECURITY.md)。升级前备份；不通过回滚旧主题代码降级数据库。

历史交付 [0.10.0主题ZIP](release/ai-skills-hub-0.10.0.zip) 含144个文件与已编译资源；本次检查以 [Phase10报告](docs/PHASE_10_REPORT.md) 为准，包的原生升级与内容比对见 [发布证据](release/PHASE_10_PACKAGE.json)。历史0.9.0包及其证据保持不变。
