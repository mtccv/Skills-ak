# Phase 7.5 — 可运行静态设计原型

这里是设计交付物，不是 WordPress 主题安装包或上线功能。本次视觉修订基线 `main / 04a8190fd7938749efbb142430c6e5ff23cb38c5`；主题/Core `0.7.1`、schema `1.5.0` 保持不变。本轮没有修改生产 PHP/CSS/JS，没有启动 WordPress/MySQL 或写入任何业务数据库。

所有 Skill、来源名称、用户、收藏、评分、数量、通知、任务和费用都是**设计演示数据**。原型顶部与相关模块明确标记；收藏、表单、首页草稿/发布仅影响当前页内存，刷新重置，无 Cookie、localStorage、账号服务、API、邮件或分析请求。脚本只操作固定演示 DOM；不执行 Skill 命令、不安装依赖、不调用 GitHub/AI。

本轮按用户指定，以 [SkillsMP](https://skillsmp.com/) 的视觉层级、导航、搜索、资源条目和技术信息布局为参考。已在 Cloud Chromium 实际查看其首页、搜索页和详情页；采用原创的白灰资源目录设计，紫色用于品牌和主要操作。不复制商标、代码、页面资产或功能架构，不使用通用 AI SaaS 模板。

优先查看 [五页修改前后截图对比](../DESIGN_COMPARISON.md) 与 [交互对比页](comparison/index.html)。修改前来自上述提交的封存原型，修改后为当前真实静态浏览器截图；桌面和手机均保持前后相同尺寸及语言。

## 1. 打开设计

| 页面 | HTML | Desktop 中文截图 | Mobile 英文截图 |
| --- | --- | --- | --- |
| 首页 | [index.html](index.html) | [完整首页](screenshots/home-desktop-zh.png) | [手机首页](screenshots/home-mobile-en.png) |
| Skills列表 | [skills.html](skills.html) | [资源目录](screenshots/skills-desktop-zh.png) | [手机列表](screenshots/skills-mobile-en.png) |
| Skill详情 | [skill.html](skill.html) | [详情](screenshots/skill-desktop-zh.png) | [手机详情](screenshots/skill-mobile-en.png) |
| 用户Dashboard | [dashboard.html](dashboard.html) | [用户中心](screenshots/dashboard-desktop-zh.png) | [手机账户](screenshots/dashboard-mobile-en.png) |
| WP后台Dashboard | [admin.html](admin.html) | [原生后台风格](screenshots/admin-desktop-zh.png) | [手机后台](screenshots/admin-mobile-en.png) |

补充：[英文完整首页](screenshots/home-desktop-en.png)、[首页外观配置 Desktop](screenshots/admin-appearance-1440.png)、[首页外观配置 Mobile](screenshots/admin-appearance-390.png)。这些是 Chromium 真实静态页面截图，不是文字线框图，不是 WordPress 实际后台截图。

[设备预览器 preview.html](preview.html) 可选择页面、中文/English、1440/768/390/360px画布和 Default/Loading/Empty/Error/Missing 状态。iframe保持选中的真实CSS宽度，外层空间不足时允许评审画布局部横滚；产品页面本身不横溢。

GitHub文件页显示源码，不自动执行HTML。可下载[设计评审包（含前后截图）](../ai-skills-hub-design-preview.zip)保留完整目录查看。Cloud中已验证的打开方式是静态HTTP服务，无需数据库或WordPress：

```sh
# 在仓库根目录，仅供 Cloud 本机设计评审
python3 -m http.server 8765 --bind 127.0.0.1 --directory docs/design-prototype
# Cloud 浏览器访问 http://127.0.0.1:8765/preview.html
```

开发始终在Cloud内完成，不要求用户本机搭建开发环境，也未部署外部服务器。HTML/CSS/JS不依赖CDN、网络字体或远程图片；普通浏览器可尝试直接打开解压的HTML，但**本轮直接 file:// 验证被托管浏览器策略拦截**（`ERR_BLOCKED_BY_ADMINISTRATOR`），不能报告离线打开已通过。证据见 [offline-checks.json](offline-checks.json)。页面访问不是公开网络发布。

## 2. 可评审的交互

- 所有五页中文/英文切换；保留允许的演示筛选URL参数。上方原型工具条不是未来产品UI。
- 首页保留九个模块：紧凑目录介绍（沿用 `hero` 模块 ID）、搜索、分类、Trending、Editor’s Picks、Recently Updated、Top Rated、教程、投稿；模块有独立稳定标识。平台在搜索区和资源条目展示，不能据此宣称已核验兼容性。
- 列表采用单列紧凑资源行和分组筛选，显示示例来源路径、平台、分类、License及更新状态；9条静态样例、每页6条：关键词+单分类+单平台+标签组合，修改后“应用筛选”，按名称排序、分页、重置和无结果。使用`q/category/platform/tag/sort/pg`作为**演示参数**，不宣称它们就是生产Router的参数；正式沿既有参数合同映射。推荐顺序是样例顺序，不是真实推荐算法。所有卡片共用一个详情展示模板，不提供9份独立资源正文。
- 收藏按钮演示选中/取消，刷新即清空。详情评价原生弹窗、1–5星、必填验证、Esc/焦点返回、待审提示；不会创建评价。复制只复制示例文件路径，不提供或执行安装命令。
- Dashboard展示未来账户信息结构、不可用收藏占位、投稿与评价分开状态、通知和隐私入口。尚未制作的流程使用明确的演示反馈，不伪装真实认证或保存。
- 后台保持WP灰色画布、160px侧栏、原生蓝色操作、Metabox/表格/Notice；运营入口打开流程说明弹窗，并非完整编辑器。
- “首页外观”只可选专业资源目录 / Skills Directory（`skills-directory`）；支持按内容语言编辑文案、模块显隐/键盘排序、内存草稿、确认发布、历史恢复为草稿、同源iframe预览。目录介绍/搜索在这个基础演示中不可关闭；正式规则见设计合同。当前只实现目录介绍文案和模块设置交互，推荐来源选择/模块标题/CTA等详细配置属于规范，未在原型做完整表单。
- 定时发布显示为未开放，只定义未来不可变修订/时区/幂等任务合同。真实风格registry、配置持久化、CAS、权限、缓存失效和发布服务留后续明确授权，主要归P12；不是本轮业务代码。

## 3. 源文件与重建

- `source.css` + `tailwind.config.cjs`：设计独立构建入口，使用项目已安装Tailwind，不改根配置/package/lockfile。
- `assets/prototype.css`：已编译静态样式，可直接查看，不需要观看者运行npm。
- `assets/prototype.js`：共享导航、语言、演示状态、筛选、收藏、弹窗、受限iframe合同。
- `assets/admin-prototype.css/js`：原生后台样式及内存配置流程；没有WordPress请求。
- `assets/preview.js`：设备评审器。
- 原型使用少量原生DOM JS，便于独立预览；**不是新增生产前端框架**。正式实现仍是PHP SSR +现有Tailwind/Alpine，不把示例脚本拷入主题充当业务服务。

仅重建设计CSS：

```sh
node_modules/.bin/tailwindcss -c docs/design-prototype/tailwind.config.cjs \
  -i docs/design-prototype/source.css -o docs/design-prototype/assets/prototype.css --minify
```

不要为本次设计执行生产 `npm run build` 或生成新主题ZIP。现有构建显示的Browserslist数据过期提示为非阻断提示，本轮未擅自升级依赖。

## 4. 实际执行的检查

本次视觉修订已重新执行以下检查。检查环境：Codex Cloud、Chromium **155.0.8059.79**、本机静态HTTP服务；没有真实账户、WordPress、MySQL、WPML、外部API或生产数据。

| 类别 | 实际结果 | 证据与口径 |
| --- | --- | --- |
| 五页×中英×1440/768/390/360 | 40/40通过 | 一个可见H1、正确lang、noindex/nofollow、唯一ID、无整页横溢 |
| 五页×Loading/Empty/Error/Missing | 20/20通过 | 真实浏览器反馈可见；演示区块状态，不代表服务器恢复逻辑 |
| 搜索/筛选/分页/收藏/弹窗/移动导航/预览等 | 19/19通过 | 含详情入口44px触点、账户菜单Esc、必填、焦点、减动画、320px详情reflow、无外部请求/JS错误/失败资源；[preview-checks.json](preview-checks.json) |
| 后台内存配置与预览流程 | 24/24通过 | 双语独立、保存不改线上示例、发布确认、恢复草稿、窄屏与原生状态；[admin-checks.json](admin-checks.json) |
| 固定颜色配对WCAG对比度计算 | 10/10满足设定阈值 | [contrast-checks.json](contrast-checks.json)，不是页面全量无障碍审计 |
| 设计Tailwind构建、JS语法 | 通过 | 独立CSS构建；浏览器JS、检查脚本与Tailwind配置语法 |
| 文档/边界 | 通过 | 设计Markdown的本地链接、HTML的本地资源/锚点与字段label、差异检查；正式主题和数据库文件不变；[static-checks.json](static-checks.json) |
| 直接file://导航 | 历史检查被环境策略阻止，本轮未重试，未完成页面检查 | 不绕过托管浏览器策略；HTTP为已验证替代 |

浏览器检查合计 **103项通过、0项最终失败**；另有10项颜色计算通过，对比页8项浏览器检查通过；50张前后原图/并排截图及源文件哈希核对见[对比报告](../DESIGN_COMPARISON.md)。计数是设计检查用例，既不是完整产品验收数，也不是PHP/WordPress集成测试数。

可复现脚本：[check-preview.cjs](check-preview.cjs)、[check-admin.cjs](check-admin.cjs)。环境需已有Playwright和Chromium；本轮未新增项目依赖。可通过`NODE_PATH`指向环境已有模块、`AISH_CHROMIUM_PATH`指定浏览器、`AISH_DESIGN_URL`指定**独立静态服务**：

```sh
node docs/design-prototype/check-preview.cjs
node docs/design-prototype/check-admin.cjs
```

检查会在本设计目录重写结果JSON与截图，不访问业务服务。本次修订的103项重新运行全部通过，含新增的详情入口触点/跳转和账户菜单Esc回归；风格ID及默认文案的测试预期已同步。最初设计提交中的检查历史：8项将合法的额外`noarchive`误判为失败，1项选中了折叠菜单的隐藏按钮，已修正测试断言/定位；另1项发现预览iframe边框占用2px，已移除边框并回归。当时规范审查还修正了投稿enum、重复token、应用筛选交互和缺译演示区块。最终记录为修正后的完整结果，不掩盖初轮发现。

## 5. 仍需后续验收的范围

- 其余页面已经有布局、组件、状态、移动、多语言和验收规范，尚未制作每页完整HTML原型。
- 真实WordPress保存、Nonce/Capability/对象权限、用户认证、邮件、数据库一致性、统计和SEO/WPML插件不属于本轮设计测试。
- Firefox/WebKit、真实手机触屏、完整读屏、200%/400%缩放、全部高对比/颜色配对和线下用户可用性访谈尚未执行；浏览器里的390px不等于真实手机设备测试。
- 历史Final QA的五项外部未执行仍保持未执行。本次设计不提高历史产品验收结论。
- 原型不是完整页面构建器，不支持任意HTML/CSS/SQL、新风格上传、生产排期或真实发布。

五规范经审核后作为Phase8–14视觉与交互约束：[设计系统](../DESIGN_SYSTEM.md)、[全站前台](../FRONTEND_DESIGN_SPEC.md)、[用户中心](../USER_CENTER_DESIGN.md)、[后台](../ADMIN_DESIGN_SPEC.md)、[响应式](../RESPONSIVE_DESIGN.md)。本轮完成后暂停，等待设计评审，不开始Phase8。
