# 兼容性报告

基线 `d6401c2d6fb810605f5ba87524301ecc50418971`，交付主题/Core 0.7.1、schema 1.5.0。实际版本来自正在运行的容器和浏览器，来源见 [environment.json](tests/final/results/environment.json)。

| 对象 | 实际环境与结果 | 边界 |
| --- | --- | --- |
| WordPress 7.1.4 | 主要真实集成环境；原生后台、公开页面、表单、SEO、任务和数据库流程执行 | 单站 localhost；不是所有 WordPress 配置 |
| WordPress 6.8 | 官方核心校验；全新安装启用、11 组/102 数据断言、124 Core 断言、五条公开路径通过 | 最低声明版本；没有重复全量插件/三引擎矩阵 |
| PHP 8.3.36 | 完整语法/隔离与主要 WP/MySQL/浏览器运行环境 | 不包含所有 PHP 扩展组合 |
| PHP 8.4.27 | 语法/隔离、真实数据库 102+124、真实 AI 后端 141 断言通过；ZIP 安装升级单列于最终报告 | AI 外部 HTTP Mock；全量浏览器主要运行 PHP 8.3 |
| MySQL 8.0.46 | 14 表、schema 1.5.0、历史逐级升级、重复安装/迁移、稳定 ID/双语、唯一键、事务及实际并发恢复通过 | InnoDB/utf8mb4、REPEATABLE-READ、大小写模式 0；不是 MariaDB/其他模式证明 |
| MySQL 8.4.13（CI） | 实现提交 c483a54 的独立 GitHub CI：WP7.1.4/PHP8.3.36，安装/启用、124条Core断言及HTTP/资源冒烟通过 | 仅基线，不把8.0完整并发/故障矩阵复制为8.4通过 |
| Chromium 155.0.8059.79 | 112/112 UI 用例；原生后台与私有表单实际持久化 13/13 | 桌面/平板/手机视口及无 JS 路径，不是实体移动硬件 |
| Firefox 153.0 | 52/52 实际前台浏览器用例通过 | Linux Playwright 配套引擎；完整后台操作由 Chromium 验证 |
| WebKit 26.5 | 52/52 实际前台浏览器用例通过 | 临时运行库准备后执行；不等同 Safari/iOS 实机，完整后台操作由 Chromium 验证 |
| 内置 zh/en | 真实语言路由、同 Skill ID、教程双向链接、缺译回退、语言公开门禁通过 | 类别/标签共享名称，不伪造独立译文 |
| Yoast SEO 28.8 | 官方免费包；真实 Meta/XML/robots/状态变更矩阵，发现问题已进入修复回归记录 | 详细最终计数见 SEO 证据，不以仅安装 active 判断兼容 |
| Rank Math 1.0.281 | 官方免费包，完成公开 Skip 配置并验证前台/Sitemap 模块；真实单启/双启测试 | 常规使用仍建议一个 SEO 插件；索引缓存策略见 docs/SEO.md |
| WPML | **未执行真实测试** | 缺少合法包；公开 API Adapter/替身检查不能代替 |
| GitHub REST API | 后端 WordPress/MySQL/队列真实，HTTP 使用 Mock | **真实 API 未执行**，无专用认证配置 |
| OpenAI / 兼容 Provider | 两 PHP 版本真实后端与 Mock Provider 通过 | **真实模型服务未执行**，无 API Key |

目录权限问题、VFS 磁盘准备、测试账户轮换、WP-CLI strict_types 加载器错误和测试夹具假设已单列，不混淆为产品兼容性缺陷。Linux WebKit 所需库仅在 `/tmp` 准备，不加入生产 npm/PHP 依赖。

交付 ZIP 是单一 `ai-skills-hub/` 主题目录，带实际构建 CSS/JS；安装现成包不需要 Node.js。许可证、构建和 SHA 校验见 [INSTALLATION.md](INSTALLATION.md)。旧版数据不随 ZIP 替换或主题切换删除；安装包测试使用全部 14 表内容指纹验证，精确结果及 SHA 见 FINAL_QA_REPORT.md。

第三方插件实际发行包来源与 checksum、最终 XML/Meta 结果保存在 `tests/final/SEO_FINDINGS.md` 及 `tests/final/results/seo-*.json`。不把测试中的合成内容、临时账号或第三方插件代码放入安装包。

远端自动与手动集成CI均成功，见 [ci-final.json](tests/final/results/ci-final.json)。构建有非阻断Browserslist数据更新提示，GitHub提示Actions v4声明Node20并由runner转用Node24；本轮不为消除提示而更换稳定依赖，实际构建/浏览器结果另行验证。
