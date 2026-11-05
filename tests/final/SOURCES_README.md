# Sources Final QA

这组测试执行真实 WordPress、Core 服务、MySQL 事务和连接锁。全部外部 HTTP 通过 WordPress `pre_http_request` 模拟；未匹配的请求被测试过滤器拒绝，不会回退到真实网络。未配置专用 `AISH_GITHUB_TOKEN`，因此没有验证真实 GitHub API、认证、实际限流或网络超时，也没有提取或复用 `gh` 凭据。

## 已执行证据

| 入口 | 实际结果 | 主要覆盖 |
| --- | --- | --- |
| `sources-workflow.php` | 15 组、120 项断言通过 | 多 Skill、增量与版本、人工双语/发布保护、截断树回退、输入及文件限制、Mock API 错误/限流、旧来源绑定、真实 SQL 失败回滚、最终清单恢复、租约/归档、Cron hook、主题数据保留、移动/风险变化、上游回退 |
| `sources-concurrency.php` | 6 组、25 项断言通过 | 独立 PHP/WordPress/MySQL 会话同时入队/领取/重放来源、领取后进程退出、来源提交后检查点前退出、实际 `KILL CONNECTION`、管理员归档与在途 Mock 响应的交错 |
| `sources-http-cron.py` | 11 项断言通过 | 真实 localhost GET `wp-cron.php`、Apache/匿名 Cron 上下文、两个生产回调链、Importer 单步检查点、AI 暂停、单条未来循环事件、重复 GET 不重复执行、配置恢复 |

环境：WordPress **7.1.4**、PHP **8.3.36**、MySQL **8.0.46**、Core schema **1.5.0**；Codex Cloud 内独立 `qa_sources` 数据库和 `aishqa-sources` 容器。本证据不覆盖 PHP 8.4 或 MySQL 8.4。

最终原始 JSON：

- [工作流结果](results/sources-workflow-php83-mysql80.json)
- [并发与恢复结果](results/sources-concurrency-php83-mysql80.json)
- [真实 HTTP Cron 结果](results/sources-http-cron-php83-mysql80.json)

JSON 仅包含环境版本、测试名称、断言数量、耗时、安全诊断及必要的调度观测；不包含凭据、任务租约、来源原文或 SQL。原两套共 **21 组、145 项断言**，追加真实 HTTP Cron **11 项断言**，合计 **156 项断言通过**；没有据此确认需要修改生产代码的缺陷。

## 复现条件

仅在可丢弃的独立开发/QA 站点运行，不能运行于生产或已有业务数据库：

1. 运行 WordPress/PHP 8.3 与 MySQL 8.0，数据库名必须以 `qa_` 开头，设置 `AISH_FINAL_QA=1`。
2. 将仓库挂载到容器 `/repo`，安装并激活当前主题，构建主题资源，初始化 schema 1.5.0。站点须有管理员和另一个可切换的已安装主题。
3. 默认 WordPress 根目录为 `/var/www/html`；其他目录设置 `AISH_QA_WP_ROOT`。测试会选取当前站点的第一个管理员，实际调用 WordPress capability 检查。
4. 数据库用户需要该 QA 数据库的读写及创建/删除测试触发器权限。若启用 binary logging，专用 QA MySQL 实例需允许该用户创建触发器，例如由环境管理员设置 `log_bin_trust_function_creators=1`。不要为了这些测试修改生产服务器设置。
5. PHP CLI 允许 `proc_open`；并发套件只运行本仓库的测试脚本。不同进程使用独立数据库连接；同一数据库用户能够终止自己的另一个测试连接。
6. 暂停该 QA 站点的其他队列消费者，禁用自动 WP-Cron 触发。测试直接调用已注册的 `aish_import_tick` 回调；同一 Sources QA 数据库中的两个入口应顺序执行。

在本次 Cloud 容器中，执行命令如下。结果输出写到宿主机当前目录，需自行选择测试证据保存位置：

```sh
env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
  docker --host=unix:///var/run/docker.sock exec -i -e AISH_FINAL_QA=1 aishqa-sources \
  php /repo/tests/final/sources-workflow.php > sources-workflow-php83-mysql80.json

env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
  docker --host=unix:///var/run/docker.sock exec -i -e AISH_FINAL_QA=1 aishqa-sources \
  php /repo/tests/final/sources-concurrency.php > sources-concurrency-php83-mysql80.json
```

每个入口在失败时返回非零退出码，并输出包含失败组的 JSON。语法检查不能替代实际执行。并发子进程的 stdout 由父测试读取；不要单独公开子进程输出或数据库配置。

## 真实 HTTP Cron 复现

上述两套 PHP 测试直接调用注册回调；追加入口通过真实 HTTP 请求检验 WordPress 的分发链：

```sh
python tests/final/sources-http-cron.py > sources-http-cron-php83-mysql80.json
```

该入口针对本次 Cloud 的 `aishqa-sources`、`qa_sources`、`127.0.0.1:18085` 和 `/tmp/aish-final-qa/mu-plugins`。它复制 `sources-http-cron-mu.php` 作为临时 MU 夹具；夹具要求数据库名、`AISH_FINAL_QA=1`、临时 active 选项三重匹配，即使目录共享也不会改变其他 QA 站点。结束后移除该文件，并读回核验原 Cron 数组、AI 暂停及原仓库同步开关已经恢复。不要直接将此夹具安装到正常站点。

测试将 Sources 与 AI 的循环事件设为到期，通过两次真实 GET `/wp-cron.php?doing_wp_cron` 观察执行。首次响应后，两个回调后置计数均为 1，生产回调仍在优先级 10 注册；上下文为 `apache2handler`、`DOING_CRON=true`、用户 ID 0。Importer 确实读取一个 Mock 仓库元数据并将任务保存为 queued/commit；AI 保持暂停，没有新增 attempt。两条循环事件各保留一条未来事件；第二次 GET 的计数、游标、检查点及计划保持不变。清理只归档该次新测试仓库，保留队列记录和历史。

WordPress 在临时空 Cron 上注册了内置更新检查，观测到对 `api.wordpress.org` 的 6 次请求尝试，全部由夹具拒绝，没有出站。初稿误要求“被拒绝的其他 WordPress 请求数为零”，随后改为核验只允许这类核心检查被阻止、业务仅发生一个 Mock GitHub 请求。这是测试断言过严的修正，不是产品缺陷。真实 HTTP Cron 的 11 项最终断言全部通过。

## 数据和故障注入

测试使用随机仓库 owner 区分每次运行。结束时归档本次测试创建的仓库，保留 Skills、人工测试译文、版本和任务历史；不清空任何表，也不删除已有业务记录。测试会改变本次任务的到期时间和租约截止时间，以验证真实数据库恢复路径，不等待完整的 60 秒步骤间隔或 120 秒租约。

故障注入包括指定测试记录的 MySQL `SIGNAL` 触发器（在 `finally` 删除）、事务中进程退出、独立会话终止 worker MySQL 连接，以及文件屏障控制的并发交错。遇到被强制终止的整个测试进程，应检查该次运行的 `qa_sources_*` 测试触发器和活动任务，再恢复测试；不要清理其他对象。

源码中的恶意 PHP/shell 字符串仅作为不可执行来源数据。测试检查其未执行，不安装依赖，不运行未知 Skill。

## 环境问题与验收边界

初次执行有两组触发器注入未能开始：MySQL 错误 **1419**，原因是 binary logging 已启用、QA 用户没有 SUPER 且 `log_bin_trust_function_creators=0`。环境管理员仅在专用 QA 实例设为 1 后，两组真实 SQL 故障恢复测试通过。这属于测试环境准备问题，不是产品缺陷。较早的共享磁盘耗尽也发生在执行器启动/写测试文件之前，恢复容量后继续，没有据此修改生产代码。

需保留的边界：

- MySQL、WordPress 服务、事务、会话、并发进程及上述连接中断是真实执行；GitHub 响应、超时、429/403/5xx、私有/替换仓库、截断 Tree 和响应大小情况均为 HTTP fixture。
- 前两套 Cron 验证实际事件注册、直接回调、连接锁和恢复；追加套件已验证真实 `wp-cron.php` HTTP 分发。仍未测量普通页面自动 spawn、低流量延迟、计划精度或负载吞吐。
- 已发出的真实 HTTP 无法因数据库断连或归档而撤销；测试不宣称跨系统恰好一次执行，也不验证 GitHub 服务端行为。
- 本证据中的 SQL 错误和 KILL 是受控故障，不能替代全部操作系统崩溃、网络分区、未知 COMMIT 及长期运行容量测试。
- 这些套件没有验收真实 GitHub 凭据、浏览器后台表单/Nonce、WPML、SEO 插件或真实 AI API；对应项目由其他 Final QA 证据或未验证清单说明。
