# 用户投稿与版本更新建议

Phase 10 复用原生 WordPress `aish_message` 私有 Inbox，不新增投稿主表。新投稿绑定当前已验证的 WordPress 用户；Contact 和历史匿名投稿保留原类型、原记录及原归属。本文描述当前实现，完整检查结果见 [Phase 10 报告](PHASE_10_REPORT.md)。

## 用户入口

以下 `{lang}` 为 `zh` 或 `en`。全部页面要求登录、邮箱验证和当前账户可用，返回 `private, no-store` 与 `noindex, nofollow, noarchive`。未登录先进入原账户登录流程；登录、验证后可返回原投稿或指定目标，不重新创建账户。

| 地址 | 功能 |
| --- | --- |
| `/{lang}/submit/` | 新 Skill 投稿，保存草稿或直接提交审核 |
| `/{lang}/account/submissions/` | 本人投稿与更新建议；状态、类型筛选与分页 |
| `/{lang}/account/submission/?id={post_id}` | 本人材料、状态、修订、用户可见反馈及公开结果 |
| `/{lang}/account/update-suggestion/?skill={skill_id}` | 从当前语言的已发布资源提出版本更新建议 |
| `/{lang}/account/notifications/` | 原账户通知中心内查看实际审核结果 |

同一个 post ID 在中文和英文界面中仍是同一份私有材料。界面语言切换不会翻译用户输入；表单另记录材料语言。无 JavaScript 时仍能提交、编辑、筛选、分页和撤回。JavaScript 只增强提交中状态、防重复点击、未保存提示和返回页面后的按钮恢复；浏览器不保存草稿到 localStorage。

## 填写与保存

可以提交 GitHub 来源，也可以选择“人工录入，无 GitHub 来源”。GitHub 填仓库根地址，例如 `https://github.com/owner/repository`，将 `tools/example/SKILL.md` 放在独立路径字段；不填 GitHub 文件页面 URL。仓库身份使用已有 `GitHubClient::repositoryIdentity()` 规范化，路径使用既有安全相对路径校验，保留大小写。已知路径必须以 `SKILL.md` 结尾；未知路径留空，显示待确认，不默认为根目录 `SKILL.md`。

字段包括名称、简介、分类建议、平台及依据、已知 License 和投稿说明。分类与平台是建议文本，不自动创建分类/标签或宣称平台已验证。名称最多 191 字符、简介 1,200、分类和平台各 500、License 100、说明 6,000；相对路径最多 768 UTF-8 字节。服务端还有独立传输体积和格式限制。

“保存草稿”允许材料不完整；已经填写的来源仍须合法。“提交人工审核”要求名称、简介、说明和相应来源完整。结果仅在持久化成功后提示。表单出错保留有界文本和原请求键/修订号，不将新数据库修订偷偷代入旧表单继续覆盖。

重复来源按规范仓库身份和独立 `source_path` 判断，不按 Skill 名称或单一仓库 URL 合并。一个仓库不同路径可分别投稿；未知路径只作为仓库线索，由人工确定。已有资源来源不重新创建；从公开资源详情发起更新建议。重复来源提示不会返回其他人的投稿正文、编号或审核备注。

## 状态与人工处理

| 内部状态 | 界面状态 | 用户可编辑 / 撤回 |
| --- | --- | --- |
| `draft` | 草稿 / Draft | 是 |
| `submitted` | 待审核 / Pending | 是，需当前修订仍未被审核操作改变 |
| `in_review` | 审核中 / Reviewing | 否，材料冻结 |
| `needs_changes` | 需要补充资料 / Needs Information | 是，可再次提交 |
| `accepted` | 已接受 / Accepted | 否 |
| `rejected` | 已拒绝 / Rejected | 否，保留结果和历史 |
| `withdrawn` | 已撤回 / Withdrawn | 否，保留处理记录 |

“接受投稿”仅表示进入后续编辑、来源采集或关联流程，**不是 Skill 发布**。该操作不会自动创建 Skill、运行 GitHub 扫描、调用 AI、修改稳定 slug、覆盖人工中英译文或不可变来源快照。编辑人员仍在原 Skills/Imports/Reviews 界面完成后续工作；PublicCatalog 发布门禁保持不变。

接受时可以人工关联最多 20 个现有 Skill ID，允许同仓库线索最终关联多个资源。“我的投稿”的最终资源链接每次重新通过当前语言 PublicCatalog 查询：下架、审核失效、缺译或尚未发布时不显示旧标题/slug，只提示当前无可公开结果。

审核意见分为“用户可见反馈”和“管理员内部备注”。前台、通知与个人导出仅展示允许给本人看的字段；内部备注不混入用户材料。审核不能直接改写投稿人的原始正文、伪装用户发布，所有材料修订与处理事件保留独立记录。撤回须勾选确认，既有正式 Skill 不受影响。

## WordPress 原生后台

AI Skills Hub 新增独立的“用户投稿”和“版本更新建议”队列：

- `admin.php?page=aish-submissions` 使用 `aish_manage_submissions`。
- `admin.php?page=aish-update-suggestions` 使用 `aish_manage_update_suggestions`。

默认管理员获得能力，Subscriber 不获得编辑/采集/AI/发布权限。列表支持搜索、状态/语言筛选、稳定排序和分页。每项详情显示来源、路径、License、建议材料、关联资源、内容快照及工作流历史。

处理操作包括开始审核、要求补充、接受和拒绝，要求 POST、Nonce、专用能力和预期修订；审核者不能处理自己的投稿。批量最多 20 条，逐项检查并报告部分失败；接受和结果关联必须逐项查看，不能批量直接批准或发布。

旧 Inbox 继续管理 Contact 和历史匿名投稿。新受控投稿从旧编辑入口隔离；不能利用原生编辑器、REST、XML-RPC 或修改 postmeta 绕过归属和状态机。没有按历史邮箱自动关联新账户的迁移。

## 更新建议

从已发布 Skill 的“建议更新版本”入口进入，服务器读取当前稳定 Skill ID 和修订；建议必须保留所见修订、真实来源及相同文件路径。GitHub 来源要求完整 Commit SHA 和变更证据；人工来源不伪造 GitHub 仓库。管理员同时修改了 Skill 或来源后，旧建议提交会产生冲突，需要重新核实。

更新建议与普通投稿共用原生私有主存储、修订和幂等机制，但使用独立 `update_suggestion` 类型、后台队列及审核能力。批准建议不会写入 `skill_versions`，不会替换 Skill ID、正文、译文、许可标签或人工批准状态。

## 事务、通知和隐私

新材料使用原生 posts/postmeta 和受控不可变原生修订，保存、审核、审计与站内通知在统一事务内完成；账户锁、投稿写锁、行锁和预期修订拒绝并发过期操作。相同请求键和相同载荷重试返回既有结果，不能重复创建材料或通知；相同键携带不同材料返回冲突。数据库提交结果不明确时仅显示可恢复错误，不假报成功。

Phase 10 审核结果使用 Phase 8 已有 `user_notifications`，事件键唯一并按账户绑定；通知只有固定中英文标题，不包含私有材料或内部备注。**本阶段为站内通知，没有新增投稿邮件发送或真实邮件送达保证。** 账户验证/重置邮件继续沿用原邮件配置，配置与送达边界见 [EMAIL_SETUP](../EMAIL_SETUP.md)。

账户导出/删除请求继续通过原生隐私工具，扩展到本人受控投稿、内容修订、反馈与更新建议。删号先隔离公开关联和归属，分批清理；“将内容重新分配给另一用户”不能把私人投稿转交另一账户。历史匿名 Inbox 不被此新归属流程认领或误删；原 Skill、译文、采集/AI 历史和其他人的数据不受影响。详见 [PRIVACY_AND_SECURITY](../PRIVACY_AND_SECURITY.md)。

## 数据与验证

schema `1.7.0 → 1.8.0` 只新增 `author_claims`，Core 表数 25 → 26；投稿不增加第二张主表，不重建原 25 表。数据设计见 [PRODUCT_DATABASE_DESIGN](PRODUCT_DATABASE_DESIGN.md)。主题切换/停用不删除数据；迁移由原 Migrator 版本化执行，普通前台不运行 DDL。

隔离 HTTP/模板检查在 [`tests/basic/contribution-frontend.php`](../tests/basic/contribution-frontend.php)，真实测试与截图入口在 [`tests/contributions/`](../tests/contributions/)，执行结果以 [Phase 10 报告](PHASE_10_REPORT.md) 为准。隔离替身不能证明真实 WP/MySQL/浏览器运行；没有真实邮件、WPML 或 GitHub API 证据时仍列未执行。本阶段不进入榜单、运营统计、商业化或 Phase 11。
