# AI Provider 与 API 配置

Phase 6 的 AI 配置位于 **AI Skills Hub → AI Settings**。主题/Core 0.7.0、schema 1.5.0；先由授权后台完成既有版本化迁移。当前实现 OpenAI 和 OpenAI-compatible 两种选择，统一使用 HTTPS **Chat Completions** 协议。

## 密钥与主机

API Key 只从服务器环境变量或同名 WordPress 常量读取，不存入 WordPress options、自定义表、任务、浏览器输入框或 Git。配置 ID `default` 对应 `AISH_AI_KEY_DEFAULT`；`compatible` 对应 `AISH_AI_KEY_COMPATIBLE`；其他 ID 对应 `AISH_AI_KEY_<大写ID>`。常量优先于环境变量。后台只展示固定掩码和是否配置，不能取回原值。

Cloud 的 `.env.example` 只有空 Key。真实值放入被 Git 忽略的 Cloud 秘密配置；现有 Compose 向 WordPress 和 CLI 传递 `DEFAULT`、`COMPATIBLE` 两个示例变量。新增其他配置 ID 时，还需在受控服务器环境中注入对应变量；不需要用户本机开发或外部部署。改变容器环境后需按现有 Cloud 流程更新容器，不能只把变量写进未传入进程的文件。

`AISH_AI_ALLOWED_HOSTS` 为逗号分隔的精确主机白名单，默认只有 `api.openai.com`，最多 20 个。兼容服务需要由服务器配置显式加入其真实 API 主机，然后在后台配置 Base URL。该值也可用同名常量定义。禁止通配符、IP 地址、本机/内网主机、HTTP、凭据、端口、查询参数和 fragment；运行时再次检查 DNS 地址必须为公网范围。不得为使用第三方服务关闭 TLS 校验或绕过网络策略。

后台配置能力不能修改服务器主机白名单。只有授权管理员可改 Provider 配置；原文和模型输出不能指定 API 主机、密钥名称或模型。配置页不接收 API Key 字段，日志只记录受控错误代码。

## 配置字段

最多保存 20 个模型配置，信息存入不自动加载的 `aish_ai_profiles` option。同一供应商可保存多个模型配置，生成时选择需要的配置。

| 字段 | 行为 |
| --- | --- |
| 配置 ID | 小写字母开头，仅字母、数字、下划线，最长 32；更换 ID 表示另一个配置及密钥引用 |
| Provider | `openai` 或 `openai_compatible`；可信 PHP 扩展可通过 Provider 接口替换 |
| API Base URL | OpenAI 固定 `https://api.openai.com/v1`；兼容服务填其 API 根路径，程序追加 `/chat/completions` |
| Model | 必须填写服务实际支持的模型 ID，不自动猜测或抓取模型列表 |
| Temperature | 0–2，可留空省略；不支持该参数的模型应留空 |
| 超时 | 5–45 秒，默认 30 |
| 最大输出 Token | 256–8192，默认 4096；太小可能产生截断 JSON 并被拒绝 |
| 输出参数 | 选择服务支持的 `max_tokens` 或 `max_completion_tokens`，默认后者 |
| 输入/输出价格 | 管理员填写 USD/百万 Token；空白表示未知，不当作免费 |
| 单任务金额上限 | 按配置价格做保守预检查；0 表示不设金额上限，输出 Token 限制仍有效 |
| 启用 | 新配置默认关闭；停用或修改后，旧排队任务不会悄悄切换到新配置 |

AI 队列全局默认暂停。保存配置、启用配置和启用队列是独立操作。排队任务冻结不含秘密的配置和 Prompt 版本，执行前重新比较当前配置指纹；配置变更、移除或停用时任务失败并提示重新配置/建任务，防止已排队内容被发送到意外服务。

## 协议与扩展接口

请求使用 `Authorization: Bearer`、`messages`（固定 system + user）、`response_format: {"type":"json_object"}`、非 streaming、单一 completion，不传 tools/functions，不执行 tool calls。兼容服务必须实际支持这些参数和 `choices[0].message.content` / `finish_reason=stop` 响应；不支持时明确失败，不自动改成不受校验的文本请求。

本轮不包含 Responses API、Azure 专用 `api-key` / `api-version` 路由、任意厂商工具协议或自动模型发现。可以在可信 PHP 代码中实现 `AISkillsHub\Core\AI\Provider`，通过 `aish_ai_provider` filter 返回适配器；必须返回受限内容、Token 用量（未知为 null）及耗时，并遵守同样的凭据、网络和人工审核边界。HTTP 用户输入不能指定类名或 PHP 文件。

`Provider::generate($profile, $messages, $requestId)` 返回 `content`、`input_tokens`、`output_tokens`、`elapsed_ms` 和可选 `returned_model`。任务记录冻结的请求模型；供应商可能返回别名或修订名，不能据此声称实际硬件/模型实现已验证。`ProviderException` 仅携带受控代码、是否可重试、退避时间、结果是否不确定及安全用量元数据，不包含原始响应或密钥。

HTTP 响应上限 1 MiB，内容文本上限 256 KiB，最终结构化草稿上限 128 KiB。禁止重定向、压缩和任意响应下载 URL，始终验证 TLS。供应商响应回显 API Key 时拒绝保留该响应；仍须选择可信服务，精确回显检查不是防范恶意服务所有编码泄露的保证。

## 连接测试与错误

连接测试需要配置能力和生成能力；默认 WordPress 管理员拥有两者。该测试排入同一队列，发送最小 JSON 生成请求，**可能计费**，提交前有明确确认。成功只说明该配置完成一次 JSON 请求，不代表双语草稿质量、完整站点或所有模型参数已经验收。

| 情形 | 处理 |
| --- | --- |
| 缺少 Key、配置无效、401/403 | 记录安全错误，修正服务器/模型配置后重试或新建任务 |
| 429 / 明确 5xx | 有界延迟重试，遵守 Retry-After，最多三次请求尝试 |
| 网络异常、超时、408、过期 in-flight 尝试 | 结果及计费可能不确定，不自动重发；管理员确认风险后手动重试 |
| 截断、无效 JSON、额外字段、工具调用、无依据命令 | 拒绝草稿；能可靠读取的 Token 用量仍保留，不能当作没有成本 |
| 未知用量且启用金额上限 | 拒绝继续预算不明的任务；核对供应商账单后决定下一步 |
| 来源/人工修订/配置变化 | 保留历史并阻止旧任务自动采用，重新核对后建任务 |

重试请求 ID 和本地唯一键可防止本地重复提交，不保证所有供应商提供恰好一次处理或计费。详细队列与审稿流程见 [AI 使用说明](AI_USAGE.md)，安全及 Final QA 边界见 [AI 安全说明](AI_SECURITY.md)。
