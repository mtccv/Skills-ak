# 真实 WordPress 双语 / SEO / Sitemap 验证

本套件只用于可丢弃的 Codex Cloud QA 站点，不用于生产站点。最终实际环境：WordPress 7.1.4、PHP 8.3.36、MySQL 8.0.46，独立数据库 `qa_seo`，localhost:18084。官方免费 Yoast SEO 28.8 和 Rank Math 1.0.281 分别启用、同时启用、全部停用；没有伪造插件类或 HTTP 响应。

最终结果：**5990 / 5990 项断言通过，1254 次真实 HTTP 请求**。断言数量与请求次数分别统计；同一响应可以验证多个性质。每种插件组合都完整读取 11 个 Hub 子 Sitemap，并抓取全部 225 个被收录 URL，验证可公开访问与自身 Canonical。所有 28 份分场景 JSON 及汇总 `seo-matrix.json` 位于 [results](results/)，其中包含每项断言、状态码、公开响应头和响应正文 SHA256，不包含密码、Cookie、API Key 或数据库转储。

## 复现

先由测试环境管理员建立独立站点并安装、启用主题与固定链接；不要对真实数据运行 seed。`seo-seed.php` 与 `seo-control.php` 均要求 CLI、`AISH_FINAL_QA=1`、DB_NAME=`qa_seo` 和 localhost 站点地址。官方插件需预先安装，Rank Math 使用官方免费 Skip registration 配置，使其实际 frontend/manager/sitemap/schema 模块加载；仅有版本常量不能算插件运行。

当前 QA 控制器示例（控制器路径属于本次一次性测试环境）：

```bash
python3 /tmp/aish-final-qa/ctl.py exec seo env AISH_FINAL_QA=1 php /repo/tests/final/seo-seed.php > /tmp/aish-final-qa/seo-fixture.json
python3 tests/final/seo-matrix.py --controller /tmp/aish-final-qa/ctl.py --fixture /tmp/aish-final-qa/seo-fixture.json --output-dir /tmp/aish-final-qa/seo-matrix-verified
```

`seo-cache-fixture.php` 是唯一额外配置 fixture，可放到测试站 MU 插件目录；它仅在 `qa_seo` 数据库中用优先级 99 请求两个真实插件启用 XML 缓存，不改 HTTP 内容。最终矩阵安装了此 fixture，验证主题最终公开过滤器仍维持动态索引。修复前生成的真实 Rank Math XML 缓存保留，没有手工删除；发布 → 编辑保存草稿 → 人工重新审核 → 归档 → 再审核过程中不调用缓存清空或 rewrite flush。只有切换插件时刷新其重写规则。

每种模式均验证：双语导航和标题、现存翻译 hreflang/x-default、缺译回退、正确 slug 301、特殊字符搜索参数 round trip、Meta/OG/Twitter 无重复、Schema.org 图、私有状态无公开内容、noindex/robots、原生托管页面重复项排除、插件索引与当前 Hub 子图完全一致、撤回时移除空语言子图及恢复时重新加入。双插件同时启用是冲突边界测试；日常仍应选择一个通用 SEO 插件。

## 证据范围与限制

[SEO_FINDINGS.md](SEO_FINDINGS.md) 记录 7 个先发现后修复的问题；`seo-*-before*.json`、`seo-baseline.json` 和缓存对照保留失败/旧基线证据，不计入最终 5990 项通过。无插件旧基线 1042 项不包括后来新增的结构化数据断言。初始文件权限和过严 HTML lang 断言导致的诊断失败记录保存在一次性 `/tmp/aish-final-qa` 环境，不能计为产品通过。

PHP 替身测试为路由 104、SEO 76、异常边界 83、Sitemap 49 项；它们与真实 HTTP 结果明确分开。Final QA 期间自动审批曾短暂因额度无法执行一条命令；恢复后已完成剩余套件，无绕过审批。

**真实 WPML 未执行**：未提供合法安装包。内置双语、公开适配器隔离验证、真实 Yoast/Rank Math 检查均不能代替真实 WPML 联调。外部 AI/GitHub API、其他浏览器和数据库/并发测试由对应 Final QA 套件单列，不计入本套件结果。

插件 XML 缓存关闭的范围和性能代价见 [SEO 使用说明](../../docs/SEO.md)：主题启用时 XML 重新生成，普通 HTML 缓存不受影响；不更改数据模型、Skill ID 或人工内容。
