# Final QA：安全与性能实测

本目录记录 2026-11-05 在 Codex Cloud 独立 `qa_security` 数据库、`aishqa-security` WordPress 容器及 `http://127.0.0.1:18087` 上执行的检查。没有连接生产数据库，没有执行未知 Skill、发送真实 GitHub/AI 请求或访问云 metadata/远程私网地址。所有内容、账号、来信及负载数据均为明确的 QA fixture。

## 结果与证据

| 检查 | 实际结果 | 原始 JSON |
| --- | --- | --- |
| 完整安全矩阵 | 178 个真实 HTTP 请求，207/207 断言通过 | [security-http.json](results/security-http.json) |
| 最后 Router/SEO/Sitemap 修改后的只读公共回归 | 28 个请求，28/28 通过；复用现有记录 | [security-final-http.json](results/security-final-http.json) |
| SQL/投影测量 | 10 类操作 × 3 轮；38 份真实 `EXPLAIN FORMAT=JSON`；查询数量与 memo 检查通过 | [performance-sql.json](results/performance-sql.json) |
| 有界 HTTP 负载 | 8 类路由 × 并发 1/4 × 每组 12 次，192/192 成功；另有每路由一次预热 | [performance-http.json](results/performance-http.json) |

207 项断言包含 30 项加载真实 WordPress 后的输入/URL 校验、真实 MySQL 错误及日志检查，并非 207 个独立 HTTP 端点。源码 PHP 语法、Python 编译和 `git diff --check` 亦通过。完整浏览器执行、插件组合、迁移和任务并发分别由其他 Final QA 套件负责。

## 发现及修复复核

**QA-01 / P1：含空格关键词导致核心搜索不可用。** `/en/search/?q=QA%20Browser` 及带该关键词的组合筛选曾返回 503。真实 WordPress `add_query_arg()` 不自动编码新加入的参数值；`Router::url()` 得到带原始空格的 URL 后，被自身 URL 校验拒绝。`&`、`#`、`+`、`%` 等保留字符也会改变原搜索参数语义。直接调用 PublicCatalog/Editorial 的真实 SQL 查询没有相同错误，因此未将其描述为 SQL 注入。

Router owner 已在加入查询参数前编码参数值，并补充保留字符 roundtrip 回归。完整安全矩阵及最后 28 URL 回归均覆盖含空格、中文和保留字符的搜索，现返回 200；未审核记录仍不可见。修复未修改核心表、Skill ID 或发布逻辑。

准备期间另纠正了测试环境的主题挂载权限、测试 fixture 的非法下划线 slug，以及“WordPress 安全请求允许其配置的本站 host”的测试预期。这些没有计为应用漏洞。归档确认接口对伪造用户绑定 token 返回明确失败的 303，其他权限拒绝返回 403；断言同时核对全部业务记录指纹未变化，不把任意重定向视为安全通过。

## 安全覆盖

- 真实 WordPress 登录：管理员、Subscriber、Contributor、Author、Editor。CLI 通过 `wp_validate_auth_cookie()` 验证 HTTP 会话，再为同一会话生成真实 nonce；不会把无效 nonce 的拒绝误当成 capability 检查通过。
- 十类管理写入口：管理员错误 nonce、匿名提交、四种低权限角色持有效 nonce 提交、原生后台页面、正向授权设置保存和 GET 写操作拒绝。前后比对核心记录及设置指纹。
- 草稿、待审、拒绝、归档、旧审核修订、未批准、缺少审核人/时间、缺译、私有/密码教程及 Inbox 的公共 URL；REST/AJAX 未注册管理入口；授权预览和跨用户 nonce、缓存/robots/CSP；归档后下一请求立即撤回且记录保留。
- SQL 片段与通配符作为字面搜索、非法筛选/排序/分页、存储及反射 HTML、来源路径穿越、GitHub URL 和 AI Base URL 白名单。
- Contact/Submit 经真实公开表单保存为后台私有记录；缺少 nonce 不写入，投稿不创建 Skill 或 AI 任务。
- `wp_safe_remote_get()` 对与本站 host 不同的受控 loopback `127.0.0.2` 的拒绝；没有向 metadata 或远程私网发请求。这不等于完整 DNS rebinding、IPv6、代理或供应商协议验证。
- 真实 MySQL duplicate-key 错误使用无意义的合成 secret canary，验证 DAL 异常没有原始驱动信息/previous、debug.log 新增段没有该值。没有使用或输出真实 API Key。

未确认 SQL 注入、可执行 XSS、权限绕过或私有内容泄露；这是本矩阵的观察范围，不是绝对安全认证。HTTP 字符串检查本身不执行浏览器 JavaScript，浏览器证据单独记录。

## 数据集、环境与量纲

实际运行 WordPress **7.1.4**、PHP **8.3.36**、MySQL **8.0.46**，InnoDB buffer pool **128 MiB**，事务隔离 `REPEATABLE-READ`，PHP CLI memory limit **256 MiB**、CLI OPcache 关闭。没有持久对象缓存插件。本套件没有把 MySQL 8.0 或 PHP 8.3 的结果写成 MySQL 8.4、PHP 8.4 或 WordPress 6.8 的实测通过。

主负载包含 1000 个合成已审核 Skill、2000 条中英文译文、8 分类、4 公共标签以及 80 教程。其中 24 个 Skill 的两种正文各约 256 KiB。另有安全用例产生的记录，均予保留：首轮 HTTP 期间同期 SQL 读数为 1045 Skills/2090 译文/88 教程；最后安全复测后 SQL 快照为 **1057 Skills、2114 译文、92 教程、1000 分类关联、3100 标签关联**，正文共 **16,880,963 bytes**。两个批次不是完全相同的行数快照。

runner 可见 5 CPU，cgroup CPU 配额相当于 4 核、内存限额 16 GiB。多个独立 QA 站点共用宿主与同一 MySQL 实例，各自数据库分离；运行期间其他代理可能执行检查。这不是独享生产服务器或无干扰基准。

SQL JSON 的 `application_ms` 包含数据库查询和 PHP 投影；`select_queries` 是实际捕获的查询数。Core 与原生 option/meta 查询单列：12/24 张卡片均固定 **4 条 Core SQL**，初次原生 option 读取另记 1 条，不误报 N+1。同请求相同调用第二次新增查询 **0**。`peak_growth_bytes` 是 PHP allocator 在该次调用相对起点的峰值增量；0 可能表示复用已分配内存，不表示完全不使用内存。

最终 3 轮中位数：列表 12/24 项 **222/291 ms**，英文搜索 **323 ms**，中文搜索 **103 ms**，组合筛选 **43 ms**，第 20 页 **276 ms**，详情 **16 ms**，200 条 Skill Sitemap **9 ms**，教程卡片 **73 ms**、教程 Sitemap **139 ms**。完整每轮结果及 SQL 计划保留在 JSON。

HTTP 单位为从本机发送请求到读完 HTML 的毫秒；不包含浏览器渲染。每组只有 12 次，nearest-rank p95 恰等于该组最大值，不能当作稳定的生产尾延迟。

| 路由 | 并发 1 中位 / p95 ms | 并发 4 中位 / p95 ms |
| --- | --- | --- |
| 英文首页 | 645 / 656 | 690 / 733 |
| 中文首页 | 269 / 310 | 296 / 337 |
| Skills 列表 | 1211 / 1241 | 1244 / 1311 |
| Skill 详情 | 127 / 142 | 146 / 165 |
| 搜索 | 1353 / 1550 | 1386 / 1429 |
| 组合筛选 | 1057 / 1119 | 1096 / 1152 |
| 第 20 页 | 1280 / 1385 | 1311 / 1368 |
| 教程列表 | 1062 / 1079 | 1091 / 1109 |

JSON 中每组完成数/实际墙钟时间计算出的请求率只是这次短测的观察值，不是系统最大吞吐。没有 flush 数据库/操作系统缓存，不宣称冷缓存测试。真实计划可见 `public_updated`、`skill_locale`、来源/关联主键及原生索引的使用；关键词/可见性计数仍有扫描及正文 `REGEXP` 成本，分类、标签和平台计数也增加列表请求成本。没有明确产品 SLO，不能据此宣布性能指标验收通过，也未为增加修改数量添加索引或重构既有数据库设计。

## 复现

先在 Cloud 准备独立 WordPress/MySQL：数据库名必须为 `qa_security`、home 必须是 loopback，主题已启用并迁移到 schema 1.5.0。关闭自动 Cron；源码只读挂载到 `/repo`，测试结果挂载到 `/qa`。不要把 CLI helper 部署为网页接口。以下宿主命令只用于该隔离环境；HTTP runner 使用 Python 标准库，无新增依赖。

```bash
python3 tests/final/security-http.py --base http://127.0.0.1:18087 \
  --container aishqa-security --output /tmp/aish-final-qa/security-http.json
```

runner 将随机一次性 QA 密码通过 stdin 传给 CLI、执行真实登录，密码不写入结果。完整运行会追加明确标识的测试记录；不会清空数据库。PHP helper 自行拒绝其他数据库名或非 loopback home。

通过本地 Docker daemon 在容器运行性能脚本，stdin 依次传 `{"mode":"seed","skills":1000}` 和 `{"mode":"measure"}`；保存 stdout 为 JSON。seed 保存一次性的语料清单，后续不会重复创建主负载：

```bash
env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
  docker --host=unix:///var/run/docker.sock exec -i aishqa-security \
  php /repo/tests/final/performance-sql.php

python3 tests/final/performance-http.py --base http://127.0.0.1:18087 \
  --fixture /tmp/aish-final-qa/performance-fixture.json \
  --output /tmp/aish-final-qa/performance-http.json --requests 12
```

最后生产文件修改后可复用已有完整安全 JSON，只读检查公共路由，不重建 fixture、不重新施加负载：

```bash
python3 tests/final/security-http.py --base http://127.0.0.1:18087 \
  --smoke-fixture /tmp/aish-final-qa/security-http.json \
  --output /tmp/aish-final-qa/security-final-http.json
```

结果不包含密码、nonce、Cookie、真实 API Token 或数据库凭据。原始测试环境日志与凭据文件留在 `/tmp/aish-final-qa`，不提交 Git。

## 保留边界

未执行持续压力/数小时 soak、冷启动/冷缓存、分布式缓存、跨机流量、CDN、生产规模或真实外部 GitHub/AI 协议测试。此处并发 1/4 是读取 HTTP 并发，不冒充事务并发写入、Cron 租约或任务故障恢复验证。其他 QA 套件有独立证据；未执行部分仍必须在总报告标明。
