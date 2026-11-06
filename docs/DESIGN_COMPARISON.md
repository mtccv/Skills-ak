# Phase 7.5 — Skills Directory 视觉修订对比

状态：**待设计审核；只涉及设计文档与独立静态原型，不是 WordPress 正式主题更新，不开始 Phase 8。**

修改前基线：[`04a8190fd7938749efbb142430c6e5ff23cb38c5`](https://github.com/mtccv/Skills-ak/commit/04a8190fd7938749efbb142430c6e5ff23cb38c5)。修改前页面从该提交的 `git archive` 封存副本运行，不是重新制作的“旧版效果图”。

## 1. 本次视觉方向

核心参考：[SkillsMP 首页](https://skillsmp.com/)、[搜索页](https://skillsmp.com/search)、[技术资源详情页](https://skillsmp.com/creators/anthropics/skills/skills-frontend-design)。本轮由主代理实际读取并以 Cloud Chromium 查看页面；只学习视觉层级、布局、导航、排版、搜索、资源条目与来源信息的组织方式，不复制商标、代码、具体页面资产或功能架构。

AI Skills Hub 保留原创品牌、紫色强调、中英双语、来源与审核语义。白底、浅灰辅助区和细分隔线承担信息分组；搜索、资源名称、来源路径、安装、License、版本与风险信息成为主要内容。前台采用专业资源目录布局，用户中心以个人资源与待办列表为主，后台继续采用 WordPress 原生信息结构和蓝色操作控件。

| 页面 | 修改前的主要问题 | 本轮修订方向 |
| --- | --- | --- |
| 首页 | 大面积浅紫渐变 Hero、装饰控制台与大标题占据首屏，搜索和资源位置较低 | 紧凑介绍、搜索靠前、分类与资源直接进入阅读视线，移除装饰控制台 |
| Skills 列表 | 多列大圆角卡片偏重图标与空白，来源信息不突出 | 筛选与结果层级清晰，紧凑资源条目优先显示名称、来源和必要元信息 |
| Skill 详情 | 宣传式页头与重复面板弱化文档阅读和技术信息 | 技术文档式布局、紧凑标题、来源事实与安装/版本/风险信息明确分区 |
| 用户 Dashboard | 大指标和卡片组成通用 SaaS 仪表盘观感 | 紧凑账户导航与摘要，以收藏、投稿、评价和通知行式信息为主 |
| WordPress 后台 Dashboard | 局部装饰、摘要卡片与前台旧风格命名不一致 | 保持原生后台，压缩摘要与待办信息，统一品牌及 Skills Directory 风格名称 |

详细约束以[设计系统](DESIGN_SYSTEM.md)、[前台规范](FRONTEND_DESIGN_SPEC.md)、[用户中心](USER_CENTER_DESIGN.md)、[后台规范](ADMIN_DESIGN_SPEC.md)、[响应式规范](RESPONSIVE_DESIGN.md)为准。

## 2. 查看前后截图

打开[交互对比页](design-prototype/comparison/index.html)，可切换五页、桌面/手机，以及首屏/完整页面。每张图可点击查看原始 PNG。页面内按容器等比显示，不拉伸、不重绘截图、不裁掉产品内容。

| 页面 | 桌面同尺寸对比 | 手机同尺寸对比 | 完整修改前 / 修改后 |
| --- | --- | --- | --- |
| 首页 | [桌面并排](design-prototype/comparison/home-desktop-comparison.png) | [手机并排](design-prototype/comparison/home-mobile-comparison.png) | [前](design-prototype/comparison/home-desktop-before-full.png) / [后](design-prototype/comparison/home-desktop-after-full.png) |
| Skills 列表 | [桌面并排](design-prototype/comparison/skills-desktop-comparison.png) | [手机并排](design-prototype/comparison/skills-mobile-comparison.png) | [前](design-prototype/comparison/skills-desktop-before-full.png) / [后](design-prototype/comparison/skills-desktop-after-full.png) |
| Skill 详情 | [桌面并排](design-prototype/comparison/skill-desktop-comparison.png) | [手机并排](design-prototype/comparison/skill-mobile-comparison.png) | [前](design-prototype/comparison/skill-desktop-before-full.png) / [后](design-prototype/comparison/skill-desktop-after-full.png) |
| 用户 Dashboard | [桌面并排](design-prototype/comparison/dashboard-desktop-comparison.png) | [手机并排](design-prototype/comparison/dashboard-mobile-comparison.png) | [前](design-prototype/comparison/dashboard-desktop-before-full.png) / [后](design-prototype/comparison/dashboard-desktop-after-full.png) |
| WordPress 后台 Dashboard | [桌面并排](design-prototype/comparison/admin-desktop-comparison.png) | [手机并排](design-prototype/comparison/admin-mobile-comparison.png) | [前](design-prototype/comparison/admin-desktop-before-full.png) / [后](design-prototype/comparison/admin-desktop-after-full.png) |

所有截图均为设计演示数据。`embed=1` 是原型已有的预览选项，用于隐藏设计工具栏；后台保留原生风格顶栏。截图过程中没有注入 CSS 或修改产品 DOM。示例收藏、评分、来源、账号与统计不代表真实业务数据，也不代表生产功能已经完成。

## 3. 截图条件与证据

- 浏览器：Cloud Chromium `155.0.8059.79`，设备像素比 `1`，减少动态效果设置。
- 桌面：视口 `1440 × 1000`，简体中文；手机：视口 `390 × 844`，英文。前后保持相同尺寸、语言及默认状态。
- 每页每尺寸保留一张首屏 PNG 与一张完整页面 PNG；并排图由对比 HTML 在浏览器中直接截图生成，原始文件不经图像处理。
- 修改前只读取本机封存静态服务，修改后只读取本机当前静态服务；不访问 WordPress、数据库、GitHub API 或 AI Provider。
- [修改前记录](design-prototype/comparison/before-capture.json)与[修改后记录](design-prototype/comparison/after-capture.json)保存浏览器版本、实际采集时间、基线提交、源文件 SHA-256、各截图 SHA-256、页面尺寸与错误记录。
- [并排图记录](design-prototype/comparison/pairs-capture.json)记录合成展示页的浏览器截图，不冒充产品原始分辨率。

截图只是视觉评审证据，不等于完整产品验收。真实移动设备、其他浏览器、WordPress/WPML 与外部 API 测试不在本轮范围内；历史 Final QA 的未验证事项保持原状。

## 4. 本轮实际截图检查

| 检查 | 实际结果 |
| --- | --- |
| 修改前：五页 × 两种视口/语言 | 10 组采集通过，20 张原始 PNG |
| 修改后：五页 × 两种视口/语言 | 10 组采集通过，20 张原始 PNG |
| 前后并排展示图 | 10 张浏览器截图生成成功 |
| 对比器交互与响应式 | 8/8 通过：页面、设备、完整页、刷新保留、键盘及三个宽度 |
| 原型截图页面 | 无 JavaScript 错误、失败资源、外部请求或整页横溢 |
| 截图完整性 | 修改前 9 个源文件与基线 Git 对象一致；修改后 9 个源文件与当前原型一致；50 张 PNG 的 SHA-256 已核对，40 张原始 PNG 尺寸已核对 |

上述采集数量不计入产品功能验收用例，也不把历史的浏览器测试数量视为本次回归。完整原型交互回归结果以[原型 README](design-prototype/README.md)中的本轮记录为准。

首页布局实测如下，均来自原始视口的 CSS 像素坐标（保留一位小数），没有裁图改变首屏内容；这些是布局测量，不是性能指标：

| 条件 | 测量项 | 修改前 | 修改后 |
| --- | --- | ---: | ---: |
| 桌面中文 | 导航区域高度 | 81.0px | 65.0px |
| 桌面中文 | 首页介绍区域高度 | 579.5px | 127.2px |
| 桌面中文 | 搜索输入框距页面顶部 | 705.5px | 207.2px |
| 桌面中文 | 首个资源条目距页面顶部 | 1334.5px | 535.0px |
| 手机英文 | 导航区域高度 | 126.0px | 110.0px |
| 手机英文 | 首页介绍区域高度 | 1063.9px | 163.0px |
| 手机英文 | 搜索输入框距页面顶部 | 1234.9px | 288.0px |
| 手机英文 | 首个资源条目距页面顶部 | 2365.9px | 781.8px |

桌面首页的搜索与首组资源均进入原始首屏；手机版搜索前移至首屏，资源自然接在分类之后。各页完整高度可能随资源行式布局和中文/英文文本长度变化，不能仅以页面变短判断可用性。

## 5. 可复现方式

先从基线提交提取静态原型到独立临时目录，以只读副本提供本机服务；当前原型使用另一端口。默认端口分别为 `8766` 与 `8765`。然后在仓库根目录依次运行：

```sh
node docs/design-prototype/check-comparison.cjs before
node docs/design-prototype/check-comparison.cjs after
node docs/design-prototype/check-comparison.cjs pairs
```

脚本使用环境中已有的 Playwright 与 Chromium，不新增依赖；可用 `AISH_CHROMIUM_PATH`、`AISH_DESIGN_BEFORE_URL`、`AISH_DESIGN_BEFORE_DIR`、`AISH_DESIGN_URL` 指定本机浏览器/静态服务。脚本仅允许 `localhost` 或 `127.0.0.1` HTTP 地址，阻止外部请求。重跑会更新对应截图及证据 JSON，不修改业务代码。

完成设计审核前不进入 Phase 8。
