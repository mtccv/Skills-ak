# Phase 1 Core 使用与维护

本阶段仅建立数据基础，页面及原生业务后台留待用户验收后的阶段。完整字段与索引见总规格第 5 节，以及 `inc/core/Database/Schema.php`。WordPress 表前缀不是固定 `wp_`。

## 数据所有权

- `skills.id` 是永久内部 ID；中英文翻译和版本都引用这个 ID，不依赖 WordPress 文章或 WPML 对象 ID。
- `repositories` 规范化 GitHub owner/name（小写），一个仓库可对应多个 Skills。
- `UNIQUE(repository_id,source_path)` 对来源去重，路径按完整 UTF-8 字节比较，大小写不同的真实文件不会合并。根文件用 `SKILL.md`，子目录用 `directory/SKILL.md`，不要传 GitHub URL。
- 翻译以 `(skill_id,locale)` 唯一，别名 zh/zh-cn/en/en-us 归一化到 zh_CN/en_US。`(locale,slug)` 也唯一；slug 冲突直接报错，绝不更新另一个 Skill 的译文。
- 版本以 `(skill_id,commit_sha)` 唯一，hash 由 Core 从来源原文计算；重复且相同的内容返回原 ID，内容冲突报错。原文保留用于未来审核，但不能当 HTML 直接输出。
- 分类有 nullable parent_id，标签非层级；两张关联表使用联合主键保证重复分配不会产生重复关系。
- 不提供删除 API，不在主题升级、停用或切换时清理数据。关联完整性由 Catalog 的事务/行锁和引用校验维护；没有数据库外键，禁止绕过应用层任意写入。

## 调用接口

以下 PHP 仅在可信 WordPress 服务器代码中调用；写入必须存在拥有 `manage_options` 的当前用户。CLI 写业务数据也须指定合法管理员用户，不能因为运行在 CLI 就跳过 Catalog 权限。

```php
$core = AISkillsHub\Core\Core::boot();
$catalog = $core->catalog();
$repository_id = $catalog->createRepository('openai', 'skills');
$skill_id = $catalog->createSkill($repository_id, 'review/SKILL.md');
$catalog->saveTranslation($skill_id, 'zh_CN', '代码审查', 'code-review', '中文摘要', '<p>说明</p>');
$catalog->saveTranslation($skill_id, 'en_US', 'Code Review', 'code-review', 'Summary', '<p>Description</p>');
$translation = $catalog->getTranslation($skill_id, 'zh_CN');
echo AISkillsHub\Core\Security\Output::text($translation['title']);
echo AISkillsHub\Core\Security\Output::rich($translation['content']);
```

其他接口：`getRepository`、`getSkill`、`findSkillBySource`、`listSkills`、`addVersion`、`versionsForSkill`、`createCategory`、`createTag`、`assignTerm`、`termsForSkill`。Skills 列表和版本历史默认每页 20 项，上限 100，offset 上限 1000000；列表排序只接受 id/created_at/updated_at 和 ASC/DESC，并以 ID 处理同值排序。查询结果为数据库字段数组（ID 由 wpdb 返回数字字符串，关联/写接口接受正 int）；`create*` 和 `saveTranslation` 返回 int ID。API 不通过请求自动执行，不进行网络访问。

错误类别：无效输入抛 InvalidArgumentException；权限/引用缺失/版本冲突抛 DomainException；数据库操作失败抛 RuntimeException，错误不含 SQL/凭据。未来 HTTP 控制器需自己转成合适状态码、校验 nonce 和 capability，再调用 Catalog；内部 Database DAL 不承担 HTTP 授权，不允许直接接收用户 SQL、表名或列名。

输出在最后一层按上下文处理：`Output::text/attribute/url/rich/json`。数据入库不预先 HTML 转义，中文不会双重编码；富文本入库和输出都使用 WordPress KSES 白名单。

## 生命周期与升级

Core bootstrap 注册限定命名空间自动加载器，显式模块为 database、language、catalog；ModuleLoader 先验证完整依赖图，拒绝重复、缺失或循环，再按顺序注册服务。`functions.php` 仅保留 Phase 0 主题启动代码，没有新增领域逻辑。

迁移仅在管理员 `admin_init`、授权的主题切换后请求或可信 WP-CLI 上下文运行。前台仅加载服务，不创建业务表。WP-CLI 的首次主题激活可能在下次 WordPress 加载时才执行迁移；README 的版本检查命令会加载主题并显示 `1.1.0`。后台失败提示为通用信息；管理员重新加载后台可重试。

1. 升级前备份数据库和代码；暂停可能写库的外部任务（本阶段没有任务）。
2. 使用 GET_LOCK 对当前数据库+站点表前缀加连接级锁，非阻塞争用返回 `aish_schema_busy`，不覆盖版本。
3. 按顺序执行 1.0.0（八张基础表）→1.1.0（版本标签字段），每步由 dbDelta 比较现有结构。
4. 检查所有列、永久 ID 类型、完整唯一索引、路径二进制类型、InnoDB 和 utf8mb4；成功后才存非 autoload 的 `aish_schema_version`。
5. 失败保留最后成功版本，可能存在部分 DDL；修复数据库后重试。MySQL DDL 不承诺事务回滚。拒绝未知版本、降级，不自动删除列或历史内容。

当前版本会直接跳过 DDL；意外手工删表不通过降低版本来隐式“修复”，应先备份、调查并由管理员明确恢复。恢复完整备份是回退方式，旧代码不能读取更新 schema 时会拒绝业务操作。多站点使用当前站点前缀/选项，网络启用不自动遍历所有站点。

## 后续 WPML 接口

`aish_core_language_provider` filter 可注入实现 `LanguageProvider` 的对象，提供 `supportedLocales()`、`normalize()`、`externalObjectId(skill_id,locale)`。内置提供者仅支持 zh_CN/en_US，externalObjectId 为 null。外部 ID 用于未来适配层，不能改变 Core 的主 ID 或唯一翻译行。

目前只以替身验证接口，不检测/调用 WPML 插件，不修改 URL/rewrite，不提供翻译生成。真实 WPML 适配、插件许可和迁移联调均未执行。

## 实际测试

Cloud PHP 语法与前端构建，及 CI 中的 `npm run test:core`/`npm run smoke:wp` 分开记录。集成测试创建随机 `aish_t_<12 hex>_` 前缀表、专用版本选项和临时 subscriber，finally 只删除这些测试对象；会切换主题后恢复。因此只应在可丢弃的开发或 CI 站点运行，禁止在生产运行测试脚本。

测试覆盖真实结构、幂等重复安装、历史结构升级、锁争用、失败与重试、ID/译文保留、仓库多 Skills、来源大小写/去重、版本快照、分类标签、权限、SQL/LIKE 输入、HTML/URL/JSON 输出、事务回滚和主题切换保留数据。测试脚本在 Web 根目录外，仅在 PHP CLI 执行。多进程写入压力、真实 WPML、真实采集/AI 服务属于未执行项。
