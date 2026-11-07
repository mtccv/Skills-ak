# 性能与并发测试报告

## Phase 12 运营增量实测（独立小样本）

环境：WordPress 7.1.4 / PHP 8.4.27 / MySQL 8.0.46，Cloud共享主机；同一PHP进程每项顺序调用5次。样本仅10个Skills、20个译文、2个收藏、4个评价，不是生产规模压测。

| 读模型 | 实测耗时范围（ms） | SQL查询数 |
|---|---:|---:|
| analytics | 9.363–13.657 | 18–24 |
| dashboard | 27.281–54.944 | 193–195 |
| users_page | 9.356–26.752 | 25–25 |
| notifications_page | 6.350–28.453 | 21–21 |

Dashboard复用多种域查询和逐项授权，固定查询开销较高（此样本193–195次）；目前不是随列表行增长的N+1证据，但仍是后续可优化的计数投影。不能从本样本推断生产容量或宣称大规模性能验收。分析/通知/用户关系使用有界批次，私有读模型不跨用户缓存。四进程并发的用户幂等、首页CAS、邮件有限重试、搜索唯一事件/聚合结果见 [本轮QA证据](tests/operations/results/)。真实持久缓存/CDN、多站点、持续负载未测。

详细定义与范围见 [指标说明](docs/ANALYTICS_DEFINITIONS.md)、[阶段报告](docs/PHASE_12_REPORT.md)。

本报告仅使用实际 MySQL 查询、EXPLAIN、HTTP 计时和独立 PHP 进程结果，不使用 Mock 延迟或估算吞吐。环境为 Codex Cloud，多个 QA 站共用资源。

## 环境和样本

- WordPress 7.1.4、PHP 8.3.36、MySQL 8.0.46；CPU cgroup 配额 4 核、内存上限 16 GiB、MySQL buffer pool 128 MiB、REPEATABLE-READ。
- 合成数据：1000 个明确标注 QA 的 Skills、2000 条双语译文、80 篇教程；24 个 Skill 的每种语言正文约 256 KiB，正文约 16.87 MB。数据来自独立测试库，不是生产资源或用户统计。
- 最终 SQL 快照合计 1057 Skills、2114 译文、92 教程（含安全与其他夹具）；HTTP 使用同一基准内容、较早时间点，不能假称各快照行数完全相同。
- 未启用持久对象缓存；没有清空 buffer pool/操作系统缓存，不能称为冷缓存测试。

## 真实 SQL

每类三轮；时间含数据库和 PHP 投影。首轮原生 WordPress options/meta 预热查询与 Core SQL 分开记录。

| 查询 | 三轮耗时范围（ms） | Core SELECT 数 | 返回项 |
| --- | ---: | --- | --- |
| list_12 | 217.1–222.7 | 4 | 12 |
| list_24 | 286.7–296.2 | 4 | 24 |
| search_en | 317.9–327.7 | 4 | 12 |
| search_zh | 101.4–107.5 | 4 | 12 |
| combined_filters | 42.6–44.0 | 4 | 12 |
| pagination_20 | 276.0–282.2 | 4 | 12 |
| detail | 15.5–16.3 | 5 | 1 |
| sitemap_skills | 9.3–9.7 | 1 | 200 |
| tutorial_cards | 70.1–74.7 | 0 | 12 |
| tutorial_sitemap | 132.6–141.3 | 0 | 43 |

12 与 24 卡片均为 **4 条 Core SQL**；同实例重复读取新增 SQL **0**。38 份真实 EXPLAIN 显示公开排序、语言唯一关系和关联表索引使用情况；不能据此宣称所有查询都无全表扫描。前导 `%关键词%`、公开完整性 REGEXP、COUNT 与分类统计仍有候选扫描成本。未为通过测试而弱化公开门禁或改表。

## 真实 HTTP

8 类路由，每组 12 次请求，分别并发 1 和 4；每路由一次不计时预热。**192/192 成功**，无响应 PHP 诊断。时间包含 localhost HTTP 及完整 HTML 接收，不包含浏览器绘制。

| 路由 | 并发 1 中位数（ms） | 并发 4 中位数（ms） | 并发 4 样本最大值（ms） |
| --- | ---: | ---: | ---: |
| home_en | 645.2 | 690.0 | 732.7 |
| home_zh | 269.5 | 295.7 | 337.0 |
| list | 1210.8 | 1244.1 | 1311.4 |
| detail | 127.1 | 145.9 | 165.2 |
| search | 1353.3 | 1386.4 | 1429.2 |
| combined | 1057.2 | 1095.9 | 1152.4 |
| pagination | 1280.5 | 1310.9 | 1367.5 |
| tutorials | 1061.5 | 1090.8 | 1109.1 |

12 次样本的 nearest-rank p95 等于该组最大值，样本不足以表示生产尾延迟。JSON 中 count/wall time 只表示短测试窗口的完成率，不是最大容量。列表/搜索约 1–1.4 秒的完整 HTTP 样本表明大规模扫描仍需容量验证；本阶段不承诺生产 SLO，也未为缩短数字引入新搜索系统。

## 并发和恢复

- 三套 WordPress/PHP 数据矩阵实际验证多进程来源去重、修订竞争、迁移锁、lock timeout、连接终止后回滚与重新迁移。
- 采集验证独立 PHP/MySQL 会话 enqueue/claim 竞争、租约 fencing、来源写入到检查点之间的进程中断、归档与旧 worker 写回交错。
- AI 在 PHP 8.3/8.4 下验证请求键/活动键竞争、全局 worker 锁、双重采用和人工编辑竞争；真实 KILL 自有连接后保留不确定 attempt，不自动再次计费。Provider HTTP 为 Mock。
- 实际 wp-cron.php HTTP 验证回调和下一次事件唯一性；不保证 WP-Cron 准点或任意停机期间的调度精度。

## 限制

未执行持续数小时压力、十万级内容、真实外部 API 吞吐、生产 TLS/CDN/页面缓存、Redis/Memcached、多节点数据库或精确 COMMIT 已提交但确认包丢失的故障窗口。主题公开页与 Hub XML 保持不可共享缓存的安全策略；为动态 Hub 索引正确性，启用本主题时禁用 Yoast/Rank Math XML Sitemap 缓存，相关 XML 请求需实时查询，不能把本次页面性能样本当作大型 Sitemap 容量证明。

原始证据：[SQL/EXPLAIN](tests/final/results/performance-sql.json)、[HTTP 样本](tests/final/results/performance-http.json)；复现：[SECURITY_PERFORMANCE_README.md](tests/final/SECURITY_PERFORMANCE_README.md)。
