# 真实 ZIP 安装与升级验收

2026-11-05 实际完成：PHP 8.4.27 / WordPress 7.1.4 / MySQL 8.0.46，最终 0.7.1 ZIP（767776 bytes、78 files）通过真实安装升级、覆盖重装、停用和重新启用。33/33 项断言通过，基线/升级/重装/重新启用四轮 HTTP 各 10/10。最终包 SHA256 为 `470e2ef14fc8c1692baf9fe8b4487483101c61f54bfbd5ab4f549e7b52c8f268`，证据见 [package-results.json](results/package-results.json)。较早的候选包不作为最终验收依据。

仅在独立、可丢弃的 `aishqa-package` 容器和 `qa_package` 数据库执行。该站点使用 PHP 8.4、WordPress 原生文件系统及 MySQL 8.0，没有把开发主题目录挂载成运行主题。`/repo` 为只读项目目录、`/qa` 为 QA 结果目录，WP-CLI 在 `/opt/wp-cli.phar`。所有命令只操作这个 QA 站点，不得替换成生产站点或放宽 PHP 脚本的精确数据库检查。

## 基线来源

`package-baseline.py` 从 Git `d6401c2d6fb810605f5ba87524301ecc50418971` 读取主题源码及 LICENSE，在临时目录组装 0.7.0。由于构建产物不进 Git，补入当前已构建 `assets/dist/app.css` 与 `app.js`，并确认两个 `assets/src` 输入与基线一致。当前 Tailwind 配置包含 Final QA 修正，因此这只是明确记录来源的升级测试基线，不能称为历史原版发布包。JSON 记录每个资源与整个 ZIP 的 SHA256。

```sh
npm ci
npm run build
python3 tests/final/package-baseline.py
docker exec aishqa-package install -d -o www-data -g www-data -m 0750 /tmp/aish-package
docker exec aishqa-package install -o www-data -g www-data -m 0644 /repo/tests/final/package-state.php /repo/tests/final/package-upgrade.php /qa/package-baseline/ai-skills-hub-0.7.0.zip /tmp/aish-package/
docker exec --user www-data aishqa-package php /opt/wp-cli.phar theme install /tmp/aish-package/ai-skills-hub-0.7.0.zip --activate
docker exec --user www-data -e AISH_FINAL_QA=1 aishqa-package php /tmp/aish-package/package-state.php seed > /tmp/aish-final-qa/package-state-baseline.json
docker exec --user www-data -e AISH_FINAL_QA=1 aishqa-package php /tmp/aish-package/package-state.php hooks > /tmp/aish-final-qa/package-hooks-baseline.json
python3 tests/final/package-http.py baseline
```

站点必须已有 WordPress 管理员、默认主题和漂亮链接，设置 `DISABLE_WP_CRON=true`。安装目录应由网站用户 `www-data` 所有。Cloud 的 `/repo`、`/qa` 权限不向网站用户开放，因此仅复制这些不含秘密的脚本和 ZIP 到容器私有临时目录，不放宽仓库或凭据目录权限。凭据由专用站点配置提供，不写入这些命令或证据。`seed` 只允许第一次创建样本；已有样本时使用 `snapshot`，不会清空或重复创建业务数据。

十四表样本通过现有 Core 服务建立，包括一个人工审核发布的中英文 Skill、独立人工草稿、仓库、原文/版本、分类标签关系、采集记录及日志、AI 待审草稿与人工编辑历史。AI 输出明确是数据库夹具，没有请求 Provider 或执行未知 Skill。逐表指纹覆盖所有列与全部记录，输出只含数量和 SHA256，不保存原始数据库行。

## 最终 ZIP 升级与保留

等待发布者完成版本更新及所有生产代码修复，再生成最终 `release/ai-skills-hub-0.7.1.zip`。没有最终 ZIP 时升级脚本直接拒绝，不能预先报告通过。

```sh
python3 scripts/package-theme.py
sha256sum release/ai-skills-hub-0.7.1.zip
docker exec aishqa-package install -o www-data -g www-data -m 0644 /repo/release/ai-skills-hub-0.7.1.zip /tmp/aish-package/
docker exec --user www-data -e AISH_FINAL_QA=1 -e AISH_QA_ZIP_SHA256='<verified SHA256>' aishqa-package php /tmp/aish-package/package-upgrade.php > /tmp/aish-final-qa/package-upgrade.json
docker exec --user www-data -e AISH_FINAL_QA=1 aishqa-package php /tmp/aish-package/package-state.php hooks > /tmp/aish-final-qa/package-state-upgraded.json
python3 tests/final/package-http.py upgraded
docker exec --user www-data aishqa-package php /opt/wp-cli.phar theme install /tmp/aish-package/ai-skills-hub-0.7.1.zip --force
docker exec --user www-data -e AISH_FINAL_QA=1 aishqa-package php /tmp/aish-package/package-state.php snapshot > /tmp/aish-final-qa/package-state-reinstalled.json
python3 tests/final/package-http.py reinstalled
docker exec --user www-data aishqa-package php /opt/wp-cli.phar theme activate twentytwentyfive
docker exec --user www-data -e AISH_FINAL_QA=1 aishqa-package php /tmp/aish-package/package-state.php snapshot > /tmp/aish-final-qa/package-state-inactive.json
docker exec --user www-data aishqa-package php /opt/wp-cli.phar theme activate ai-skills-hub
docker exec --user www-data -e AISH_FINAL_QA=1 aishqa-package php /tmp/aish-package/package-state.php hooks > /tmp/aish-final-qa/package-state-reactivated.json
python3 tests/final/package-http.py reactivated
python3 tests/final/package-report.py --expected-sha256 '<verified SHA256>'
```

`package-upgrade.php` 设置测试站点的更新响应，调用真实 `Theme_Upgrader::upgrade()`，替换已安装目录后退出。随后的新 PHP 请求才加载升级后类；不以当前进程中旧版本类作为升级验证。重复安装走 WP-CLI 的真实 `Theme_Upgrader` 覆盖安装；主题切换后 Core 不加载、Cron 被清理，重新启用后恢复注册/调度。

升级前额外运行 `snapshot` 保存为 `/tmp/aish-final-qa/package-state-before-final.json`，用于校验实际安装基线文件逐个匹配基线 ZIP。`package-report.py` 比较每一步与基线的 `tables`、`fixture` 完全相等，确保十四表、稳定 ID、人工翻译/SEO、slug 和历史内容没有变化，并验证已安装文件逐个匹配最终 ZIP。所有表应非空。最终主题和新请求中 Core 版本必须为 0.7.1，schema 保持 1.5.0。Cron 验证运行已注册 action，但样本队列已完成或仅待审核，仓库自动采集已禁用；任何外部 HTTP 尝试使测试失败。

HTTP 检查在固定 `127.0.0.1:18090` 上验证十个路径：首页、列表、双语公开详情、私有草稿 404、Sitemap、robots 和已安装 CSS/JS。它检查人工内容标记、私有内容不泄露和 PHP 诊断；不冒充真实 API、完整浏览器或 SEO 验收。

测试脚本初版曾把 Alpine 静态 JS 中的正常 `Warning:` 字符串误判为 PHP 诊断，已按响应类型修正并完整重跑 HTTP 检查。首次原生升级调用因 root 安装目录所有权与网站用户不一致，原生文件系统检测退出，主题实际仍为 0.7.0；修正专用 QA 目录所有权后以网站用户重跑。升级脚本同时纠正成功返回值为 `true`（不是 bulk/run 的数组），不将先前失败算作通过。这些是测试夹具/环境问题，不是主题生产缺陷。正式执行记录保存到 `results/package-results.json`，原始日志及凭据不复制仓库。

## 最终包全新空库安装

另建 `aishqa-fresh` / `qa_fresh` / localhost:18091、PHP8.4.27的空WordPress实例，无主题源码挂载。直接 `wp theme install /repo/release/ai-skills-hub-0.7.1.zip --activate` 并保存固定链接后，运行 `python3 tests/final/package-fresh.py`。实测12/12检查通过（版本/14张空表及10条HTTP），证据 `results/package-fresh.json`；使用同一最终SHA，不复用已迁移的package数据库。初次只读检查脚本误用不存在的Core getter，纠正为服务注册表后重跑，未改产品逻辑。
