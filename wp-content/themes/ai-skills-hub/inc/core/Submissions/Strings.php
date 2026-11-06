<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;
defined( 'ABSPATH' ) || exit;

final class Strings {
	private const COPY = array(
		'submit' => array( '投稿 Skill', 'Submit a Skill' ), 'submissions' => array( '我的投稿', 'My submissions' ), 'submission' => array( '投稿详情', 'Submission details' ), 'update-suggestion' => array( '建议更新版本', 'Suggest a version update' ),
		'private_help' => array( '投稿与审核意见仅对你和获授权的审核人员可见。', 'Your proposals and feedback are private to you and authorized reviewers.' ),
		'accepted_help' => array( '接受投稿仅表示进入后续编辑、采集或关联流程，不代表 Skill 已发布。资源仍须通过原有人工内容审核。', 'Acceptance starts editorial, source review or linking work. It does not publish a Skill; normal human content review still applies.' ),
		'source_help' => array( '填写 GitHub 仓库根地址和独立的相对文件路径。未知路径请留空，标为待确认；同一仓库可包含多个 Skills。此表单不会启动采集或 AI 生成。', 'Enter the GitHub repository root and a separate relative file path. Leave an unknown path empty for confirmation. A repository can contain multiple Skills. This form starts no import or AI job.' ),
		'update_help' => array( '提交来源、目标 Commit 和变更证据供人工核实。建议不会覆盖版本快照、译文或已审核内容。', 'Provide the source, proposed commit and evidence for human verification. A suggestion never replaces version snapshots, translations or reviewed content.' ),
		'name' => array( 'Skill 名称', 'Skill name' ), 'summary' => array( '资源简介', 'Summary' ), 'notes' => array( '投稿说明 / 变更摘要', 'Submission notes / change summary' ), 'locale' => array( '材料语言', 'Content language' ),
		'source_kind' => array( '来源类型', 'Source type' ), 'source.github' => array( 'GitHub 仓库', 'GitHub repository' ), 'source.manual' => array( '人工录入，无 GitHub 来源', 'Manual proposal without GitHub' ), 'github_url' => array( 'GitHub Repository URL', 'GitHub repository URL' ), 'source_path' => array( 'Skill 相对文件路径（可留空）', 'Relative Skill file path (optional)' ),
		'categories' => array( '分类建议', 'Suggested categories' ), 'platforms' => array( '支持平台及依据', 'Supported platforms and evidence' ), 'license' => array( 'License（已知时）', 'License (if known)' ), 'evidence' => array( '可核实证据', 'Verifiable evidence' ), 'commit_sha' => array( '新 Commit SHA', 'Proposed commit SHA' ),
		'save_draft' => array( '保存草稿', 'Save draft' ), 'submit_review' => array( '提交人工审核', 'Submit for review' ), 'withdraw' => array( '撤回投稿', 'Withdraw proposal' ), 'confirm_withdraw' => array( '我确认撤回这份未处理投稿。已有正式 Skill 不受影响。', 'I confirm withdrawal of this unprocessed proposal. Existing Skills are unaffected.' ),
		'view_all' => array( '查看全部投稿', 'View all submissions' ), 'view_detail' => array( '查看详情', 'View details' ), 'view_skill' => array( '查看已公开资源', 'View published resource' ), 'back' => array( '返回我的投稿', 'Back to my submissions' ),
		'status' => array( '审核状态', 'Review status' ), 'kind' => array( '类型', 'Type' ), 'kind.submit' => array( 'Skill 投稿', 'Skill submission' ), 'kind.update_suggestion' => array( '版本更新建议', 'Version update suggestion' ), 'all' => array( '全部', 'All' ),
		'state.draft' => array( '草稿', 'Draft' ), 'state.submitted' => array( '待审核', 'Pending' ), 'state.in_review' => array( '审核中', 'Reviewing' ), 'state.needs_changes' => array( '需要补充资料', 'Needs information' ), 'state.accepted' => array( '已接受', 'Accepted' ), 'state.rejected' => array( '已拒绝', 'Rejected' ), 'state.withdrawn' => array( '已撤回', 'Withdrawn' ),
		'feedback' => array( '给你的审核意见', 'Reviewer feedback for you' ), 'no_feedback' => array( '暂未提供审核意见。', 'No reviewer feedback yet.' ), 'results' => array( '最终发布资源', 'Published outcomes' ), 'no_results' => array( '当前语言下暂无可公开的关联资源。', 'No linked resource is currently public in this language.' ),
		'number' => array( '投稿编号', 'Submission number' ), 'created' => array( '创建时间', 'Created' ), 'submitted' => array( '提交时间', 'Submitted' ), 'revision' => array( '材料修订', 'Proposal revision' ), 'unknown_path' => array( '路径待确认', 'Path awaiting confirmation' ), 'untitled' => array( '未命名草稿', 'Untitled draft' ),
		'empty' => array( '暂无符合条件的投稿。可先保存草稿，再补充材料提交审核。', 'No matching proposals. Save a draft and complete it before submitting for review.' ), 'readonly' => array( '此状态下材料已锁定，不能编辑或撤回。', 'This proposal is locked in its current state and cannot be edited or withdrawn.' ),
		'apply' => array( '筛选', 'Apply filters' ), 'clear_filters' => array( '清除筛选', 'Clear filters' ), 'previous' => array( '上一页', 'Previous' ), 'next' => array( '下一页', 'Next' ), 'page' => array( '第 %1$d 页 · 共 %2$d 项', 'Page %1$d · %2$d records' ),
		'working' => array( '正在提交…', 'Submitting…' ), 'error_title' => array( '操作未完成', 'Action not completed' ), 'reload' => array( '重新加载最新内容', 'Reload current content' ),
		'pagination' => array( '分页导航', 'Pagination' ),
		'error.invalid_input' => array( '请检查字段格式与长度。保存草稿允许未填写的材料；提交审核需补全必填信息。', 'Check field formats and lengths. Drafts may be incomplete; review submissions require complete information.' ),
		'error.invalid_source' => array( '请填写有效的 GitHub 仓库根地址与安全的相对路径，不要填写文件页面地址。', 'Use a valid GitHub repository root URL and safe relative path, not a file page URL.' ),
		'error.csrf' => array( '页面授权已过期或请求来源无效。请重新加载后重试。', 'The form authorization expired or the request origin is invalid. Reload and try again.' ),
		'error.forbidden' => array( '当前账户无权执行此操作。', 'This account cannot perform this action.' ), 'error.unavailable' => array( '记录或目标资源不可用，或你无权访问。', 'The record or target resource is unavailable or inaccessible.' ),
		'error.conflict' => array( '材料已被其他操作更新。你的输入保留在此页，请先重新加载并核对最新修订。', 'Another action changed this record. Your input remains on this page; reload and compare the latest revision before retrying.' ),
		'error.expired_request' => array( '该请求已过期。请重新加载并核对当前记录。', 'This request expired. Reload and check the current record.' ),
		'error.duplicate_source' => array( '这份来源已有待处理的投稿。请检查自己的投稿；其他用户的私有信息不会展示。', 'A proposal for this source is already being handled. Check your submissions; other users’ private information is not shown.' ),
		'error.source_exists' => array( '该来源已有资源记录。请从公开资源页面提交更新建议，或等待管理员核实。', 'A resource already exists for this source. Suggest an update from its public page or wait for editorial verification.' ),
		'error.rate_limited' => array( '操作过于频繁，请稍后重试。', 'Too many requests. Please try again later.' ), 'error.busy' => array( '记录正在处理，请稍后重试原请求。', 'This record is being processed. Retry the same request shortly.' ), 'error.storage_unavailable' => array( '暂时无法确认操作结果。请稍后重试原请求，避免重复创建。', 'The result could not be confirmed. Retry the same request later to avoid creating a duplicate.' ),
		'message.saved' => array( '草稿已保存，尚未提交审核。', 'Draft saved; it has not been submitted for review.' ), 'message.submitted' => array( '材料已提交人工审核，未创建或发布公开 Skill。', 'Proposal submitted for human review. No public Skill was created or published.' ), 'message.withdrawn' => array( '投稿已撤回，保留处理记录。', 'Proposal withdrawn; its history is retained.' ),
	);
	public static function has( string $key ): bool { return isset( self::COPY[ $key ] ); }
	public static function get( string $key, string $locale ): string { return self::COPY[ $key ][ 'en_US' === $locale ? 1 : 0 ] ?? $key; }
}
