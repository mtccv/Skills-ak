<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Contributions;
defined( 'ABSPATH' ) || exit;

/** Fixed notification copy; no submitted text, internal remarks or private target snapshots. */
final class Strings {
	private const NOTIFICATIONS = array(
		'contribution_submitted' => array( '投稿已提交审核', 'Proposal submitted for review' ),
		'contribution_reviewing' => array( '投稿正在审核', 'Proposal under review' ),
		'contribution_needs_information' => array( '投稿需要补充资料', 'More information requested for your proposal' ),
		'contribution_accepted' => array( '投稿已接受，等待后续编辑流程', 'Proposal accepted for editorial processing' ),
		'contribution_rejected' => array( '投稿未获接受', 'Proposal rejected' ),
		'contribution_withdrawn' => array( '投稿已撤回', 'Proposal withdrawn' ),
		'profile_submitted' => array( '开发者资料已提交审核', 'Developer profile submitted for review' ),
		'profile_approved' => array( '开发者资料已审核通过', 'Developer profile approved' ),
		'profile_rejected' => array( '开发者资料未获批准', 'Developer profile rejected' ),
		'profile_hidden' => array( '开发者公开主页已隐藏', 'Developer public profile hidden' ),
		'profile_withdrawn' => array( '开发者公开主页已关闭', 'Developer public profile withdrawn' ),
		'claim_submitted' => array( '作者或维护者认领已提交审核', 'Attribution application submitted for review' ),
		'claim_approved' => array( '作者或维护者认领已审核通过', 'Attribution application approved' ),
		'claim_rejected' => array( '作者或维护者认领未获批准', 'Attribution application rejected' ),
		'claim_revoked' => array( '作者或维护者认领已撤销', 'Attribution revoked' ),
		'claim_disputed' => array( '作者或维护者认领进入争议处理', 'Attribution marked as disputed' ),
		'claim_withdrawn' => array( '作者或维护者认领已撤回', 'Attribution withdrawn' ),
	);
	public static function notification( string $key, string $locale ): ?string { return self::NOTIFICATIONS[ $key ][ 'en_US' === $locale ? 1 : 0 ] ?? null; }
}
