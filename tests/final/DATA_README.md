# 数据与生命周期真实集成测试

仅在可丢弃的 Codex Cloud WordPress/MySQL 站点运行。测试不会请求 GitHub 或 AI 服务；生命周期样本中的 AI 输出是明确标记的数据库测试数据，不能当作真实生成测试。

`data-suite.php` 和 `data-worker.php` 必须同时满足 PHP CLI、`AISH_FINAL_QA=1`，且 `DB_NAME` 精确属于 `qa_data`、`qa_php84`、`qa_wp68`。不要为生产库放宽白名单。需要已安装 WordPress、管理员用户、已激活 AI Skills Hub 主题、另一默认主题、当前 schema，以及 `proc_open`、原生 mysqli 和同账户终止自身 MySQL 会话的权限。停用自动 WP-Cron，避免测试数据发出自动化请求。

## 执行

在项目代码挂载到 `/repo`、结果目录挂载到 `/qa` 的专用测试容器运行：

```sh
docker exec -e AISH_FINAL_QA=1 -e AISH_QA_RESULT_DIR=/qa aishqa-data php /repo/tests/final/data-suite.php
docker exec aishqa-data php /repo/tests/integration/core.php

docker exec -e AISH_FINAL_QA=1 -e AISH_QA_RESULT_DIR=/qa/php84 aishqa-php84 php /repo/tests/final/data-suite.php
docker exec aishqa-php84 php /repo/tests/integration/core.php

docker exec -e AISH_FINAL_QA=1 -e AISH_QA_RESULT_DIR=/qa/wp68 aishqa-wp68 php /repo/tests/final/data-suite.php
docker exec aishqa-wp68 php /repo/tests/integration/core.php
```

容器命名不是测试逻辑的一部分；复用其他专用容器时需保持数据库白名单。默认 WordPress 入口为 `/var/www/html/wp-load.php`，可用 `AISH_QA_WP_LOAD` 指定已核实的测试站点入口。数据库凭据仅由该站点配置提供，不写进命令、结果或仓库。

脚本为每次运行创建随机 `aish_qd_<12 hex>_` 表前缀和专用 schema option，在 `finally` 中仅清理这些测试对象。主题切换验证需要实际站点的十四张表都有记录，因此在专用 QA 站点创建带随机标识的样本，并保留到整站销毁或人工核查；不会清空原业务表。旧 `tests/integration/core.php` 使用独立 `aish_t_` 随机前缀清理自己的样本。

两个并发用例启动独立 PHP/WordPress/MySQL 进程，在所有进程完成 WordPress 加载后通过屏障同时继续。没有用内存数据库替身代替这些运行。

## 覆盖与证据

- D01：十四表首装、Schema 物理验证、InnoDB、utf8mb4。
- D02：1.0→1.5 逐级升级、重复升级、稳定 ID/双语/历史保留、未知版本和降级拒绝。
- D03：视图占用新增表名造成真实 DDL 失败，版本保留，排除阻塞后恢复。
- D04：来源/语言/slug/版本唯一键、路径大小写、人工 NULL 来源与草稿 NULL slug。
- D05：两个真实 MySQL 会话竞争迁移 advisory lock。
- D06：八进程同时创建同来源，收敛到同一个 Skill ID。
- D07：两个编辑进程提交同一旧 revision，仅一个成功。
- D08：真实行锁等待超时，整个业务事务回滚，连接恢复使用。
- D09：KILL CONNECTION 中断事务，拒绝重放与后续连接复用，不留下部分写入。
- D10：KILL CONNECTION 中断首装 DDL，保留部分表和旧版本，新连接重试完成。
- D11：填充十四表后，通过新的 WordPress 请求切换主题与重新激活，表计数、双语内容及稳定 ID 保留。

每个运行输出机器可读 `data-results.json`，包含实际 PHP/WordPress/MySQL 版本、隔离级别、表名大小写模式、各用例结果及断言数。仓库证据位于 `results/data-php83.json`、`results/data-php84.json`、`results/data-wp68.json` 和 `results/data-summary.json`。旧 Core 基线的统计加入汇总；原始运行日志不复制进仓库。

2026-11-05 实际执行结果（MySQL 均为 8.0.46、InnoDB、REPEATABLE-READ、`lower_case_table_names=0`）：

| WordPress | PHP | 数据/生命周期用例 | 本套件断言 | 旧 Core 断言 |
| --- | --- | --- | --- | --- |
| 7.1.4 | 8.3.36 | 11/11 | 102 | 124 |
| 7.1.4 | 8.4.27 | 11/11 | 102 | 124 |
| 6.8 | 8.3.36 | 11/11 | 102 | 124 |

WordPress 6.8 另以真实 HTTP 请求检查 `/zh/`、`/en/`、`/en/skills/`、`/aish-sitemap.xml`、`/robots.txt`，均返回 200，响应未包含 PHP fatal/warning/notice/deprecated。`results/data-wp68-http.json` 保存状态与类型证据；这是可用性冒烟检查，不替代 SEO 正确性或浏览器验收。

首次 PHP 8.3 执行中发现两个测试夹具问题：新 wpdb 在替换全局连接前未设置 WordPress 表前缀；AI 档案固定数据把非空数值 `max_cost` 写成 null。已修正测试并完整重跑，不将它们描述为生产代码缺陷。

## 验证边界

这组证据只证明结果文件记录的环境和用例。没有调用真实 GitHub/AI API，不验证模型语义或计费。没有精确诱发 COMMIT 已持久化但确认包丢失的窗口，不能承诺该情况已回滚或恰好一次。MySQL 8.4、`lower_case_table_names=1/2`、任意第三方 `db.php`、持续压力及所有线程交错未在此套件实测；八进程/两进程的有界并发不是吞吐量或长期负载指标。历史 CI 和 Mock 检查不混入本次真实结果。
