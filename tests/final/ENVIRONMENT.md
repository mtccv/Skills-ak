# Final QA 真实环境与复现边界

基线 `d6401c2d6fb810605f5ba87524301ecc50418971`。本次所有数据库、卷、用户与内容均为 Codex Cloud 中新建的测试资源；不连接生产数据库。测试内容明确标为 QA，不能作为站点资源或统计发布。

## 实际运行配置

| 容器 / 数据库 | localhost 端口 | 用途 |
| --- | --- | --- |
| aishqa-data / qa_data | 18081 | PHP 8.3 数据库、迁移及并发 |
| aishqa-browser / qa_browser | 18083 | 浏览器及原生后台 |
| aishqa-seo / qa_seo | 18084 | SEO / 免费插件启停矩阵 |
| aishqa-sources / qa_sources | 18085 | 采集、恢复及 Cron |
| aishqa-ai / qa_ai | 18086 | AI 工作流及并发，先 PHP8.3 后切 PHP8.4 保留数据；外部 Provider Mock |
| aishqa-security / qa_security | 18087 | HTTP 安全及性能样本 |
| aishqa-php84 / qa_php84 | 18088 | PHP 8.4 数据库矩阵 |
| aishqa-wp68 / qa_wp68 | 18089 | WordPress 6.8 最低声明版本 |
| aishqa-package / qa_package | 18090 | PHP 8.4 下真实 ZIP 安装及升级 |
| aishqa-fresh / qa_fresh | 18091 | 最终 0.7.1 ZIP 的全新 WordPress 空库直接安装 |

数据库为同一独立 MySQL Community 8.0.46 服务，十个库相互隔离，没有宿主端口。WordPress 7.1.4 / PHP 8.3.36、8.4.27；最低版本站使用官方 WordPress 6.8 下载包并核对官方核心 checksum。额外的 `wp-config-docker.php` 是镜像模板，校验提示它不属于核心文件，不是核心文件 hash 错误。扩展及版本证据见 [results/environment.json](results/environment.json)。MySQL 为 InnoDB、utf8mb4、REPEATABLE-READ、lower_case_table_names=0、128 MiB buffer pool。

Web 进程 PHP memory_limit 256M；WP-CLI 自身会设置 CLI memory_limit=-1。Docker 官方镜像带 mysqli、curl、mbstring、openssl、intl、zip 等扩展，实际列表以 JSON 为准。Cloud cgroup CPU 配额 4 核、内存 16 GiB；多个站共用 CPU、磁盘和 MySQL，性能报告不是独占服务器峰值测试。

Cloud 使用 VFS 存储，官方多层镜像拉取时曾耗尽临时磁盘，发生在服务运行和业务数据创建之前。仅将本次 QA 镜像容器导出、重新导入为扁平文件系统，并恢复原 ENTRYPOINT/CMD/ENV/WORKDIR/USER/VOLUME/EXPOSE 后启动；没有改 Docker 存储驱动或 WordPress/MySQL 文件。来源 digest 与方法保存在 environment.json，扁平镜像本身不作为产品交付物。普通 Docker 环境可直接使用对应官方 digest。

## 复现准备

1. 在新的 Cloud 测试目录、独立 Compose project 和空卷建立上述数据库。不得把这些故障注入脚本指向业务数据库；脚本使用专用 DB 名、CLI opt-in 等守卫。
2. Web 容器挂载仓库为 `/repo:ro`；除 package 站外，主题挂载到 `/var/www/html/wp-content/themes/ai-skills-hub:ro`。package 站必须使用实际 ZIP 安装，不能源码挂载。先运行 `npm ci && npm run build && npm run check:assets`。
3. WordPress/MySQL 密码与随机测试管理员密码仅生成到权限 0700 的临时目录中，单个 JSON 权限 0600；通过 `_FILE` 或 stdin 传入，不把值放进命令行或 Git。各站 credentials JSON 的形状为 `{"url":"http://127.0.0.1:端口","username":"qa_admin","password":"临时随机值","email":"qa@example.invalid"}`。浏览器测试会创建额外权限测试用户，其密码也只存私有临时文件。
4. 容器设置 `AISH_FINAL_QA=1`，启用 WP_DEBUG_LOG、关闭 WP_DEBUG_DISPLAY。常规测试设置 DISABLE_WP_CRON=true，使任务只由受控测试触发；真实 Cron HTTP 用例直接请求 wp-cron.php 并核对实际 Apache 执行及调度记录。
5. 全新安装 WordPress，启用主题，设置 `/%postname%/` 并 flush rewrite，打开 blog_public。Apache 必须能读取主题（目录 0755、文件 0644）并允许 .htaccess 重写。首次空白 HTTP 是挂载权限准备错误，纠正后相关测试全部重跑。
6. 通过 Cloud 允许的代理和 CA 建立下载信任；不关闭 TLS 验证。临时 MU plugin 仅设置 QA 的证书路径，不属于主题源码。没有在环境中注入真实 GitHub/AI API Key。所有外部响应模拟均在测试报告明确标识。
7. 本次故障注入使用 MySQL SIGNAL trigger，隔离服务器设置 `log_bin_trust_function_creators=1`；这是专用 QA 权限前提，不是产品部署要求。不提升生产数据库权限。KILL CONNECTION 仅针对测试进程自己的连接。
8. 各套件具体运行命令见同目录 `DATA_README.md`、`AI_README.md`、`SOURCES_README.md`、`browser-README.md` 及测试脚本。PHP 顶部 strict_types 文件通过 `wp eval 'require "/repo/…php";'` 或自身 bootstrap 执行；不要用会预置 eval 内容的 `wp eval-file` 冒充相同运行方式。

本次 Docker socket 通过当前 Cloud 授权访问；不要把 Cloud 特定代理、证书和 socket 设置复制为生产配置。停止测试后保留测试卷即可；禁止使用未核对 project/卷名的 `down -v`。历史阶段未运行的真实测试，只有本次明确记录的结果才能更新为已验证。

## 外部依赖

Yoast SEO 28.8 与 Rank Math 1.0.281 从官方免费发行包安装到 seo 站。Rank Math 使用其免费向导的公开 Skip 选项完成初始化，不能只检测 active 状态就声称兼容。没有合法 WPML 包，真实 WPML 未执行。没有专用 GitHub Token/真实 AI Key，不提取 Git 仓库认证用于应用 API，也不购买服务；真实供应商协议、质量与账单仍未验证。

## 验收结束状态

Cloud真实测试和远端CI完成后，仅停止本次Compose project的十个WordPress容器及MySQL服务；数据卷、源码、安装包和证据均保留，没有执行down -v或删除业务数据。需要复查时先按原私有配置恢复对应测试服务；报告中的localhost地址不是仍在运行的公网部署。
