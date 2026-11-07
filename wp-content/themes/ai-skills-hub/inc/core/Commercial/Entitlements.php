<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Accounts\AccountService;
use AISkillsHub\Core\Database\Database;

defined( 'ABSPATH' ) || exit;

/** Read-only free eligibility. Domain services still own object, nonce and transaction gates. */
final class Entitlements {
	public const VERSION = 'free-1';
	private const MANAGEMENT = array(
		'skills' => 'aish_manage_skills', 'skill_review' => 'aish_review_skills',
		'community' => 'aish_moderate_community', 'submissions' => 'aish_manage_submissions',
		'discovery' => 'aish_manage_discovery', 'sponsors' => 'aish_manage_sponsors',
		'enterprise' => 'aish_manage_enterprise', 'commercial_metrics' => 'aish_view_commercial_metrics',
	);
	public function __construct( private AccountService $accounts, private Database $db ) {}

	/** Current session only: no user ID, plan, role or request-supplied grant is accepted. */
	public function eligibility( string $feature ): array {
		$result = array( 'eligible' => false, 'source' => self::VERSION, 'reason' => 'unsupported', 'object_authorization_required' => true );
		if ( in_array( $feature, array( 'browse', 'search' ), true ) ) { return array_replace( $result, array( 'eligible' => true, 'reason' => 'free_public_content' ) ); }
		if ( ! in_array( $feature, array( 'favorite', 'review', 'submit', 'profile_read', 'profile_edit', 'account_security' ), true ) ) { return $result; }
		try {
			// Unverified owners retain the existing recovery/profile-read boundaries.
			$user = $this->accounts->requireOwner( ! in_array( $feature, array( 'profile_read', 'account_security' ), true ) );
			$meta = $this->db->connection()->usermeta;
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $meta ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
			if ( null !== $this->db->value( "SELECT umeta_id FROM `$meta` WHERE user_id=%d AND meta_key IN ('_aish_account_deletion_pending','_aish_community_erasing','_aish_contributions_erasing') AND meta_value<>'' AND meta_value<>'0' LIMIT 1", array( (int) $user->ID ) ) ) { return array_replace( $result, array( 'reason' => 'account_restricted' ) ); }
			return array_replace( $result, array( 'eligible' => true, 'reason' => 'free_account_feature' ) );
		} catch ( \Throwable $error ) {
			// No account existence, verification metadata, exception body or other-user data.
			return array_replace( $result, array( 'reason' => 'account_unavailable' ) );
		}
	}

	/** Fixed management areas query native capabilities; this API cannot grant capabilities. */
	public function manages( string $area ): bool {
		try { return isset( self::MANAGEMENT[ $area ] ) && current_user_can( self::MANAGEMENT[ $area ] ); }
		catch ( \Throwable $error ) { return false; }
	}
}
