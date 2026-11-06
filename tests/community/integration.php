<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$author = c9_user( 'author' ); $other = c9_user( 'other' ); $pendingUser = c9_user( 'pending', false );
$skill = c9_skill( 'main' ); $second = c9_skill( 'second' ); $englishOnly = c9_skill( 'english', false ); $draft = c9_skill( 'private-draft', true, false );
$review = array();

c9_case( 'NATIVE-IDENTITY-VERIFICATION-AND-CAPABILITIES', function () use ( $author, $pendingUser, $accounts, $service, $query, $skill ): void {
	c9_assert( $accounts->verified( $author ) && ! $accounts->verified( $pendingUser ), 'Native verification facts are real.' );
	c9_assert( get_userdata( $author )->roles === array( 'subscriber' ) && ! user_can( $author, 'aish_moderate_community' ) && ! user_can( $author, 'manage_options' ), 'Subscriber cannot acquire moderator/admin capabilities.' );
	wp_set_current_user( 0 ); c9_reject( fn() => $service->setFavorite( $skill, true, 'en_US', c9_key() ), 'Guest cannot write a favorite.' );
	c9_reject( fn() => $query->mineFavorites( 'en_US' ), 'Guest cannot read private favorites.' );
	wp_set_current_user( $pendingUser ); c9_reject( fn() => $service->submitReview( $skill, c9_payload(), 0, c9_key() ), 'Pending email cannot post a review.' );
	c9_reject( fn() => $service->setFavorite( $skill, true, 'en_US', c9_key() ), 'Pending email cannot use community writes.' );
} );

c9_case( 'FAVORITE-IDEMPOTENT-CROSS-LANGUAGE', function () use ( $author, $skill, $service, $query, $db ): void {
	wp_set_current_user( $author ); $key = c9_key();
	$service->setFavorite( $skill, true, 'en_US', $key ); $service->setFavorite( $skill, true, 'en_US', $key );
	$service->setFavorite( $skill, true, 'zh_CN', c9_key() );
	c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d AND is_active=1', array( $author, $skill ) ), 'Both languages and retries use one active relationship.' );
	c9_assert( $query->mineFavorites( 'en_US' )['total'] === 1 && $query->mineFavorites( 'zh_CN' )['total'] === 1, 'Both languages query the same private collection.' );
	c9_reject( fn() => $service->setFavorite( $skill, false, 'en_US', $key ), 'A reused key with a different target cannot mutate the relationship.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['favorites'] === 1, 'Public count represents actual active relationship count.' );
} );

c9_case( 'FAVORITE-CANCEL-RESTORE-FIRST-TIME-METRIC', function () use ( $author, $skill, $service, $query, $db ): void {
	wp_set_current_user( $author ); $table = $db->table( 'favorites' );
	$initial = $db->row( "SELECT * FROM $table WHERE user_id=%d AND skill_id=%d", array( $author, $skill ) ); $key = c9_key();
	$service->setFavorite( $skill, false, 'en_US', $key ); $service->setFavorite( $skill, false, 'en_US', $key );
	c9_assert( 0 === (int) $query->mineFavorites( 'en_US' )['total'], 'Explicit cancel and retry remain cancelled.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['favorites'] === 0, 'Cancellation updates current public count immediately.' );
	$service->setFavorite( $skill, true, 'en_US', c9_key() );
	$restored = $db->row( "SELECT * FROM $table WHERE user_id=%d AND skill_id=%d", array( $author, $skill ) );
	c9_assert( $restored['created_at'] === $initial['created_at'] && null === $restored['cancelled_at'], 'Restore retains first favorite timestamp and clears cancellation.' );
	c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'metric_events' ) . ' WHERE metric_name=%s AND dimension_key=%s', array( 'favorite_add', (string) $skill ) ), 'Same user/Skill/day repeated adds cannot inflate action ledger.' );
} );

c9_case( 'FAVORITE-PRIVATE-OWNERSHIP-PAGINATION', function () use ( $author, $other, $skill, $second, $service, $query ): void {
	wp_set_current_user( $other ); c9_assert( 0 === (int) $query->mineFavorites( 'en_US', array( 'user_id' => $author ) )['total'], 'A forged user filter cannot read another collection.' );
	$service->setFavorite( $second, true, 'en_US', c9_key() );
	wp_set_current_user( $author ); $one = $query->mineFavorites( 'en_US', array( 'per_page' => 1, 'page' => 1 ) );
	c9_assert( count( $one['items'] ) === 1 && (int) $one['total'] === 1, 'Private pagination includes only owner data.' );
	c9_assert( ! str_contains( json_encode( $one ), 'Community QA second' ), 'Other user resource is absent from private projection.' );
} );

c9_case( 'SKILL-PUBLIC-AND-TRANSLATION-WRITE-GATES', function () use ( $author, $draft, $englishOnly, $service, $query ): void {
	wp_set_current_user( $author );
	c9_reject( fn() => $service->setFavorite( $draft, true, 'en_US', c9_key() ), 'Draft cannot be favorited by ID.' );
	c9_reject( fn() => $service->submitReview( $draft, c9_payload(), 0, c9_key() ), 'Draft cannot receive public community writes.' );
	c9_reject( fn() => $service->setFavorite( $englishOnly, true, 'zh_CN', c9_key() ), 'Missing translation cannot bypass language public gate.' );
	$service->setFavorite( $englishOnly, true, 'en_US', c9_key() );
	$zh = $query->mineFavorites( 'zh_CN' );
	$missing = array_values( array_filter( $zh['items'], static fn( array $item ): bool => (int) $item['skill_id'] === $englishOnly ) )[0] ?? array();
	c9_assert( isset( $missing['available'] ) && ! $missing['available'] && null === $missing['skill'] && 'en_US' === ( $missing['alternative']['locale'] ?? '' ), 'Missing-language favorite is unavailable with an explicit real alternative language.' );
} );

c9_case( 'REVIEW-RATING-VALIDATION', function () use ( $author, $skill, $service, $db ): void {
	wp_set_current_user( $author );
	foreach ( array( 0, 6, -1, 2.5, '4.0', '04', '5 OR 1=1', true, array( 5 ) ) as $bad ) {
		$payload = c9_payload(); $payload['rating'] = $bad;
		c9_reject( fn() => $service->submitReview( $skill, $payload, 0, c9_key() ), 'Out-of-range or noninteger rating is rejected.' );
	}
	c9_assert( array() === c9_head( $author, $skill ), 'Invalid rating attempts create no review head.' );
} );

c9_case( 'REVIEW-FIRST-PENDING-AND-RETRY', function () use ( $author, $skill, $service, $query, $db, &$review ): void {
	wp_set_current_user( $author ); $key = c9_key(); $payload = c9_payload( 4 );
	$service->submitReview( $skill, $payload, 0, $key ); $service->submitReview( $skill, $payload, 0, $key );
	$review = c9_head( $author, $skill );
	c9_assert( $review['state'] === 'pending' && null === $review['approved_revision_id'] && (int) $review['pending_revision_id'] > 0, 'First revision remains pending with no public pointer.' );
	c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'review_revisions' ) . ' WHERE review_id=%d', array( $review['id'] ) ), 'Request replay does not create another revision.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['count'] === 0 && $query->publicSummary( $skill, 'en_US' )['average'] === null, 'No approved reviews means no fabricated rating.' );
	c9_assert( (int) $query->publicReviews( $skill, 'en_US' )['total'] === 0, 'Pending text is absent from public reviews.' );
	$changed = $payload; $changed['rating'] = 5; c9_reject( fn() => $service->submitReview( $skill, $changed, 0, $key ), 'Same idempotency key with different review conflicts.' );
} );

c9_case( 'COMMUNITY-MODERATOR-CANNOT-SELFAPPROVE-OR-EDIT-SKILLS', function () use ( $author, $service, $management, &$review ): void {
	$user = get_userdata( $author ); $user->add_cap( 'aish_moderate_community' ); wp_set_current_user( 0 ); wp_set_current_user( $author );
	try {
		c9_assert( current_user_can( 'aish_moderate_community' ) && ! current_user_can( 'aish_manage_skills' ), 'Scoped community role does not grant editorial capabilities.' );
		c9_reject( fn() => $service->moderateReview( (int) $review['id'], (int) $review['pending_revision_id'], (int) $review['lock_version'], 'approve', 'approved', '', c9_key() ), 'Moderator cannot approve a review authored by the same account.' );
		c9_reject( fn() => $management->saveSkill( null, array( 'save_mode' => 'draft' ) ), 'Community moderator cannot create or edit Skill content.' );
	} finally { $user->remove_cap( 'aish_moderate_community' ); wp_set_current_user( 0 ); wp_set_current_user( $author ); }
} );

c9_case( 'APPROVE-EXACT-REVISION-AND-PRIVATE-NOTES', function () use ( $author, $other, $skill, $query, $service, &$review ): void {
	wp_set_current_user( $other );
	c9_reject( fn() => $service->moderateReview( (int) $review['id'], (int) $review['pending_revision_id'], (int) $review['lock_version'], 'approve', 'approved', '', c9_key() ), 'Subscriber cannot moderate.' );
	$review = c9_approve( $review, 'approve', 'INTERNAL_QA_NOTE_DO_NOT_PUBLISH' );
	c9_assert( $review['state'] === 'approved' && null === $review['pending_revision_id'], 'Approval atomically installs public pointer.' );
	$summary = $query->publicSummary( $skill, 'en_US' );
	c9_assert( (int) $summary['count'] === 1 && (float) $summary['average'] === 4.0 && (int) $summary['distribution'][4] === 1, 'Aggregate reads exactly one approved rating.' );
	$public = $query->publicReviews( $skill, 'en_US' );
	c9_assert( str_contains( json_encode( $public ), 'Original tested comment' ) && ! str_contains( json_encode( $public ), 'INTERNAL_QA_NOTE'), 'Published UGC excludes internal moderation notes.' );
	c9_assert( ! str_contains( json_encode( $public ), get_userdata( $author )->user_email ) && ! str_contains( json_encode( $public ), get_userdata( $author )->user_login ), 'Public review never exposes native email or login name.' );
	wp_set_current_user( $author ); c9_assert( ! str_contains( json_encode( $query->mineReview( $skill ) ), 'INTERNAL_QA_NOTE' ), 'Own review projection also excludes internal notes.' );
} );

c9_case( 'EDIT-PENDING-PRESERVES-PUBLIC-REJECT-KEEPS-OLD', function () use ( $author, $skill, $service, $query, &$review ): void {
	$approvedId = (int) $review['approved_revision_id']; $old = c9_revision( $approvedId );
	wp_set_current_user( $author ); $service->submitReview( $skill, c9_payload( 2, 'zh_CN', 'PRIVATE_NEW_EDIT' ), (int) $review['lock_version'], c9_key() );
	$review = c9_head( $author, $skill );
	c9_assert( (int) $review['approved_revision_id'] === $approvedId && (int) $review['pending_revision_id'] !== $approvedId, 'Editing creates independent pending snapshot.' );
	c9_assert( c9_revision( $approvedId )['comment'] === $old['comment'], 'Approved source content is immutable.' );
	c9_assert( (float) $query->publicSummary( $skill, 'zh_CN' )['average'] === 4.0 && (int) $query->publicSummary( $skill, 'zh_CN' )['count'] === 1, 'Other UI language still uses old shared rating.' );
	c9_assert( ! str_contains( json_encode( $query->publicReviews( $skill, 'en_US' ) ), 'PRIVATE_NEW_EDIT' ), 'Pending edit text cannot leak publicly.' );
	$review = c9_approve( $review, 'reject', 'PRIVATE_REJECTION_NOTE' );
	c9_assert( (int) $review['approved_revision_id'] === $approvedId && null === $review['pending_revision_id'], 'Rejected edit retains previously approved pointer.' );
	c9_assert( (float) $query->publicSummary( $skill, 'en_US' )['average'] === 4.0, 'Rejection leaves published rating intact.' );
} );

c9_case( 'SUPERSEDED-PENDING-STALE-MODERATION-ATOMIC-SWITCH', function () use ( $author, $skill, $service, $query, $admin, &$review ): void {
	wp_set_current_user( $author ); $service->submitReview( $skill, c9_payload( 3, 'en_US', 'SUPERSEDED' ), (int) $review['lock_version'], c9_key() );
	$stale = c9_head( $author, $skill );
	$service->submitReview( $skill, c9_payload( 5, 'zh_CN', 'LATEST_APPROVED' ), (int) $stale['lock_version'], c9_key() );
	$review = c9_head( $author, $skill );
	c9_assert( c9_revision( (int) $stale['pending_revision_id'] )['moderation_state'] === 'superseded', 'Replacement leaves earlier pending revision as historical superseded snapshot.' );
	wp_set_current_user( $admin );
	c9_reject( fn() => $service->moderateReview( (int) $stale['id'], (int) $stale['pending_revision_id'], (int) $stale['lock_version'], 'approve', 'approved', '', c9_key() ), 'Stale admin view cannot approve superseded draft.' );
	$review = c9_approve( $review );
	foreach ( array( 'zh_CN', 'en_US' ) as $locale ) { $summary = $query->publicSummary( $skill, $locale ); c9_assert( (int) $summary['count'] === 1 && (float) $summary['average'] === 5.0, 'Approval replaces shared aggregate exactly once.' ); }
	c9_assert( str_contains( json_encode( $query->publicReviews( $skill, 'en_US' ) ), 'LATEST_APPROVED' ), 'New approved text is now the sole public snapshot.' );
} );

c9_case( 'CROSS-USER-REVIEW-OWNERSHIP', function () use ( $author, $other, $skill, $service, $query, &$review ): void {
	wp_set_current_user( $other );
	c9_assert( null === $query->mineReview( $skill ), 'Own review query does not return another author review.' );
	c9_reject( fn() => $service->withdrawReview( (int) $review['id'], (int) $review['lock_version'], c9_key() ), 'Forged review ID cannot withdraw another author.' );
	$payload = c9_payload( 2, 'en_US', 'SECOND_AUTHOR' ); $payload['user_id'] = $author;
	c9_reject( fn() => $service->submitReview( $skill, $payload, 0, c9_key() ), 'Forged owner field is rejected instead of being treated as authority.' );
	unset( $payload['user_id'] ); $service->submitReview( $skill, $payload, 0, c9_key() );
	c9_assert( (int) c9_head( $author, $skill )['id'] === (int) $review['id'] && (int) c9_head( $other, $skill )['id'] !== (int) $review['id'], 'Client owner field cannot replace actual native author.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['count'] === 1, 'Second pending review has no public contribution.' );
} );

c9_case( 'REPORT-REVIEW-PRIVATE-IDEMPOTENT-NO-AUTOHIDE', function () use ( $other, $skill, $service, $query, $db, $admin, &$review ): void {
	wp_set_current_user( $other ); $key = c9_key();
	$payload = array( 'locale' => 'en_US', 'reason_code' => 'false_review', 'details' => 'PRIVATE_REPORT_BODY_QA only for moderator.' );
	$service->reportReview( (int) $review['id'], (int) $review['approved_revision_id'], $payload, $key );
	$service->reportReview( (int) $review['id'], (int) $review['approved_revision_id'], $payload, $key );
	$service->reportReview( (int) $review['id'], (int) $review['approved_revision_id'], $payload, c9_key() );
	$reports = $db->rows( 'SELECT * FROM ' . $db->table( 'review_reports' ) . ' WHERE reporter_user_id=%d AND review_id=%d', array( $other, $review['id'] ) );
	c9_assert( count( $reports ) === 1 && (int) $reports[0]['revision_id'] === (int) $review['approved_revision_id'], 'Active duplicates refer to the exact published revision only once.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['count'] === 1, 'Report cannot automatically hide a review.' );
	c9_assert( ! str_contains( json_encode( $query->publicReviews( $skill, 'en_US' ) ), 'PRIVATE_REPORT_BODY_QA' ), 'Private report details never enter public UGC.' );
	c9_reject( fn() => $query->adminReports( 'review' ), 'Subscriber cannot list private moderator reports.' );
	wp_set_current_user( $admin ); $service->resolveReport( 'review', (int) $reports[0]['id'], 'reviewing', 'other', '', c9_key() );
	wp_set_current_user( $other ); $duplicate = $service->reportReview( (int) $review['id'], (int) $review['approved_revision_id'], $payload, c9_key() );
	c9_assert( $duplicate['state'] === 'reviewing' && $db->value( 'SELECT state FROM ' . $db->table( 'review_reports' ) . ' WHERE id=%d', array( $reports[0]['id'] ) ) === 'reviewing', 'Active duplicate preserves actual reviewing state.' );
	$changed = $payload; $changed['details'] = 'Changed duplicate must not replace original evidence.';
	c9_reject( fn() => $service->reportReview( (int) $review['id'], (int) $review['approved_revision_id'], $changed, c9_key() ), 'Different duplicate details cannot overwrite the existing active report.' );
	wp_set_current_user( $admin ); $service->resolveReport( 'review', (int) $reports[0]['id'], 'closed', 'no_action', 'PRIVATE_HANDLER_NOTE', c9_key(), 'reviewing' );
	$closed = $db->row( 'SELECT * FROM ' . $db->table( 'review_reports' ) . ' WHERE id=%d', array( $reports[0]['id'] ) );
	c9_assert( $closed['state'] === 'closed' && null === $closed['active_key'] && (int) $closed['handler_id'] === $admin && ! empty( $closed['resolved_at'] ), 'Manual resolution records handler/time and releases active key.' );
} );

c9_case( 'SKILL-CORRECTION-LOCALE-REVISION-PRIVATE', function () use ( $author, $skill, $service, $query, $db, $admin ): void {
	wp_set_current_user( $author ); $revision = (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) );
	$payload = array( 'locale' => 'zh_CN', 'kind' => 'installation', 'details' => 'PRIVATE_SKILL_CORRECTION_QA invalid instructions.', 'skill_revision' => $revision ); $key = c9_key();
	$service->reportSkill( $skill, $payload, $key ); $service->reportSkill( $skill, $payload, $key );
	$row = $db->row( 'SELECT * FROM ' . $db->table( 'skill_reports' ) . ' WHERE reporter_user_id=%d AND skill_id=%d', array( $author, $skill ) );
	c9_assert( $row['locale'] === 'zh_CN' && (int) $row['skill_revision'] === $revision, 'Correction freezes explicit target language and actual Skill revision.' );
	c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'skill_reports' ) . ' WHERE reporter_user_id=%d AND skill_id=%d', array( $author, $skill ) ), 'Correction request replay does not duplicate.' );
	c9_assert( $db->value( 'SELECT status FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) ) === 'published', 'Report cannot automatically archive Skill.' );
	$bad = $payload; $bad['skill_revision'] = $revision - 1; c9_reject( fn() => $service->reportSkill( $skill, $bad, c9_key() ), 'Outdated correction target fails instead of claiming a current revision.' );
	c9_reject( fn() => $query->adminReports( 'skill' ), 'Private correction requires moderator capability.' );
	wp_set_current_user( $admin ); $service->resolveReport( 'skill', (int) $row['id'], 'handled', 'resolved', 'PRIVATE_HANDLER_NOTE', c9_key() );
	c9_assert( $db->value( 'SELECT state FROM ' . $db->table( 'skill_reports' ) . ' WHERE id=%d', array( $row['id'] ) ) === 'handled', 'Administrator can record a handled correction without changing Skill content.' );
} );

c9_case( 'WITHDRAW-IMMEDIATE-PUBLIC-AND-SCORE-REMOVAL', function () use ( $author, $skill, $service, $query, &$review ): void {
	wp_set_current_user( $author ); $key = c9_key();
	$service->withdrawReview( (int) $review['id'], (int) $review['lock_version'], $key ); $service->withdrawReview( (int) $review['id'], (int) $review['lock_version'], $key );
	$review = c9_head( $author, $skill );
	c9_assert( $review['state'] === 'withdrawn' && null === $review['approved_revision_id'], 'Withdrawal clears public pointer.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['count'] === 0 && (int) $query->publicReviews( $skill, 'en_US' )['total'] === 0, 'The same query object sees immediate removal, with no cached old score.' );
	c9_assert( $query->publicSummary( $skill, 'en_US' )['average'] === null, 'No remaining valid contribution does not become a fake zero-star rating.' );
} );

c9_case( 'RESUBMISSION-SAME-HEAD-AND-FIRST-PUBLISHED-TIME', function () use ( $author, $skill, $service, $db, &$review ): void {
	$oldId = (int) $review['id']; $first = $review['first_published_at'];
	wp_set_current_user( $author ); $service->submitReview( $skill, c9_payload( 3, 'en_US', 'RESTORED' ), (int) $review['lock_version'], c9_key() );
	$review = c9_approve( c9_head( $author, $skill ) );
	c9_assert( (int) $review['id'] === $oldId && $review['first_published_at'] === $first, 'Withdrawal and resubmission preserve identity and first contribution date.' );
	c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'metric_events' ) . ' WHERE metric_name=%s AND dimension_key=%s', array( 'first_review_approved', (string) $skill ) ), 'Edits and restoration cannot inflate first-approved metrics.' );
} );

c9_case( 'HIDE-CANNOT-BE-BYPASSED-BY-EDIT', function () use ( $author, $skill, $service, $query, &$review ): void {
	$review = c9_approve( $review, 'hide', 'PRIVATE_HIDE_NOTE' );
	c9_assert( $review['state'] === 'hidden' && null === $review['approved_revision_id'], 'Moderation hide removes public pointer immediately.' );
	c9_assert( (int) $query->publicSummary( $skill, 'en_US' )['count'] === 0, 'Hidden review no longer contributes to ratings.' );
	wp_set_current_user( $author ); c9_reject( fn() => $service->submitReview( $skill, c9_payload( 5 ), (int) $review['lock_version'], c9_key() ), 'Author edit cannot restore administrator-hidden content.' );
	try { $service->withdrawReview( (int) $review['id'], (int) $review['lock_version'], c9_key() ); }
	catch ( AISkillsHub\Core\Community\Exception $error ) { /* Keeping hidden immutable is also valid. */ }
	$review = c9_head( $author, $skill );
	c9_reject( fn() => $service->submitReview( $skill, c9_payload( 5 ), (int) $review['lock_version'], c9_key() ), 'Withdraw-then-submit cannot bypass a moderator hide.' );
} );

c9_case( 'SKILL-ARCHIVE-INVALIDATES-READS-AND-PRIVATE-PLACEHOLDER', function () use ( $admin, $author, $skill, $service, $query, $management, $db ): void {
	wp_set_current_user( $admin ); $revision = (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) );
	$management->archiveSkill( $skill, $revision );
	wp_set_current_user( $author );
	$favorites = $query->mineFavorites( 'en_US' );
	c9_assert( ! str_contains( json_encode( $favorites ), 'Community QA main' ), 'Unavailable favorite contains no cached old title.' );
	c9_reject( fn() => $service->setFavorite( $skill, true, 'en_US', c9_key() ), 'Re-adding an archived resource is refused.' );
	$service->setFavorite( $skill, false, 'en_US', c9_key() );
	c9_assert( 0 === (int) $db->value( 'SELECT is_active FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d', array( $author, $skill ) ), 'Owner can still remove an unavailable resource.' );
	try { $summary = $query->publicSummary( $skill, 'en_US' ); c9_assert( (int) $summary['count'] === 0 && (int) $summary['favorites'] === 0, 'Unavailable Skill returns no public totals.' ); }
	catch ( AISkillsHub\Core\Community\Exception $error ) { c9_assert( true, 'Unavailable resource query fails closed.' ); }
} );

c9_case( 'MALICIOUS-UGC-VALIDATION-AND-SQL-BOUNDARIES', function () use ( $other, $second, $service, $query, $db, $admin ): void {
	wp_set_current_user( $other ); $payload = c9_payload( '5', 'en_US', 'Sanitize' );
	$payload['title'] = '<script>window.__p9xss=1</script>Safe title'; $payload['comment'] = '<img src=x onerror="window.__p9xss=1">Safe review text \\ quotes \' OR 1=1 --';
	$service->submitReview( $second, $payload, 0, c9_key() ); $head = c9_head( $other, $second );
	$stored = c9_revision( (int) $head['pending_revision_id'] );
	c9_assert( ! str_contains( $stored['title'], '<script' ) && ! str_contains( $stored['comment'], '<img' ), 'Stored user text is stripped to the allowed plain-text contract.' );
	$head = c9_approve( $head ); $public = $query->publicReviews( $second, 'en_US' );
	c9_assert( (int) $public['total'] === 1, 'Bound suspicious text does not alter SQL row selection.' );
	wp_set_current_user( $other ); c9_reject( fn() => $query->mineFavorites( 'en_US', array( 'sort' => 'id; DROP TABLE users' ) ), 'Query sort cannot accept injected SQL.' );
} );

c9_case( 'TRANSACTION-ROLLBACK-ON-REQUIRED-EVENT-FAILURE', function () use ( $author, $second, $service, $db ): void {
	wp_set_current_user( $author );
	// A transient database trigger failure simulates unavailable durable workflow storage.
	$name = 'p9_fail_' . bin2hex( random_bytes( 6 ) ); $table = $db->table( 'workflow_events' );
	$db->execute( "CREATE TRIGGER `$name` BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic QA event failure'" );
	try { c9_reject( fn() => $service->setFavorite( $second, true, 'en_US', c9_key() ), 'Required durable event failure aborts the relationship write.' ); }
	finally { $db->execute( "DROP TRIGGER `$name`" ); }
	c9_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d AND is_active=1', array( $author, $second ) ), 'No partially committed favorite remains after failure.' );
	$service->setFavorite( $second, true, 'en_US', c9_key() );
	c9_assert( 1 === (int) $db->value( 'SELECT is_active FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d AND skill_id=%d', array( $author, $second ) ), 'New request recovers once the database fault is removed.' );
} );

c9_case( 'SKILL-PUBLICATION-EVENT-ATOMICITY-AND-NO-FAKE-UPDATE', function () use ( $admin, $management, $db ): void {
	$skill = c9_skill( 'publication-event', true, false ); wp_set_current_user( $admin );
	$management->changeStatus( $skill, 'pending', 1 );
	$revision = (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) );
	$name = 'p9_publication_' . bin2hex( random_bytes( 5 ) );
	$db->execute( "CREATE TRIGGER `$name` BEFORE INSERT ON " . $db->table( 'workflow_events' ) . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic publication event failure'" );
	try { c9_reject( fn() => $management->review( $skill, 'approved', 'unknown', '', $revision ), 'Publication cannot commit without its required content event.' ); }
	finally { $db->execute( "DROP TRIGGER `$name`" ); }
	$row = $db->row( 'SELECT * FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) );
	c9_assert( $row['status'] === 'pending' && (int) $row['revision'] === $revision, 'Publication event failure leaves old pending state and revision intact.' );
	$management->review( $skill, 'approved', 'unknown', '', $revision );
	$events = $db->rows( 'SELECT * FROM ' . $db->table( 'workflow_events' ) . ' WHERE subject_type=%s AND subject_id=%d AND action=%s', array( 'skill', $skill, 'public_content_approved' ) );
	c9_assert( count( $events ) === 1, 'Successful publication creates one actual content event.' );
	$revision = (int) $db->value( 'SELECT revision FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $skill ) );
	$management->changeStatus( $skill, 'pending', $revision );
	$management->review( $skill, 'approved', 'unknown', '', $revision + 1 );
	c9_assert( 1 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'workflow_events' ) . ' WHERE subject_type=%s AND subject_id=%d AND action=%s', array( 'skill', $skill, 'public_content_approved' ) ), 'Reapproving unchanged content does not fabricate a new content-update event.' );
} );

c9_case( 'NATIVE-USER-DELETE-IMMEDIATE-GATE-AND-DURABLE-CLEANUP', function () use ( $other, $author, $second, $admin, $core, $query, $db ): void {
	$head = c9_head( $other, $second );
	c9_assert( (int) $query->publicSummary( $second, 'en_US' )['count'] === 1, 'Deletion fixture starts with a real approved contribution.' );
	wp_set_current_user( $admin ); c9_assert( wp_delete_user( $other ), 'Native WordPress user deletion succeeds.' );
	c9_assert( false === get_userdata( $other ) && (int) $query->publicSummary( $second, 'en_US' )['count'] === 0, 'Deleted identity stops public rating contribution immediately, before batch erasure.' );
	$native = $db->connection();
	$request = (int) $db->value( "SELECT post_id FROM {$native->postmeta} WHERE meta_key='_aish_cleanup_target' AND meta_value=%s ORDER BY post_id DESC LIMIT 1", array( (string) $other ) );
	c9_assert( $request > 0 && get_post_meta( $request, '_aish_cleanup_state', true ) === 'pending', 'Native deletion leaves the actual durable Phase 8 cleanup request.' );
	// Prioritize only this generated request in the disposable fixture; never alter another identity.
	$db->execute( "UPDATE {$native->posts} SET post_modified_gmt='1980-01-01 00:00:00',post_modified='1980-01-01 00:00:00' WHERE ID=%d", array( $request ) );
	$core->get( 'account_privacy' )->tick();
	$erased = $db->row( 'SELECT * FROM ' . $db->table( 'reviews' ) . ' WHERE id=%d', array( $head['id'] ) );
	c9_assert( null === $erased['user_id'] && null === $erased['approved_revision_id'] && $erased['state'] === 'withdrawn', 'Durable batch anonymizes the old head and clears pointers.' );
	c9_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'review_revisions' ) . " WHERE review_id=%d AND (title<>'' OR comment<>'' OR experience<>'' OR pros<>'' OR cons<>'')", array( $head['id'] ) ), 'Personal review text is removed by module cleanup.' );
	c9_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'favorites' ) . ' WHERE user_id=%d', array( $other ) ), 'Deleted user favorites are physically removed.' );
	c9_assert( 0 === (int) $db->value( 'SELECT COUNT(*) FROM ' . $db->table( 'review_reports' ) . ' WHERE reporter_user_id=%d', array( $other ) ), 'Deleted reporter identity is removed from reports.' );
	c9_assert( get_post_meta( $request, '_aish_cleanup_state', true ) === 'complete', 'Shared durable request completes only after community cleanup.' );
	c9_assert( get_userdata( $author ) instanceof WP_User && $db->value( 'SELECT status FROM ' . $db->table( 'skills' ) . ' WHERE id=%d', array( $second ) ) === 'published', 'Other account and the public Skill survive deletion.' );
} );

c9_case( 'REAL-PAGINATION-SEARCH-AND-BATCHED-PRIVATE-PROJECTIONS', function () use ( $service, $query, $db, $admin ): void {
	$owner = c9_user( 'pagination' ); $ids = array();
	foreach ( array( 'alpha', 'beta', 'gamma' ) as $suffix ) {
		$id = c9_skill( 'pagination-' . $suffix ); $ids[] = $id; wp_set_current_user( $owner );
		$service->setFavorite( $id, true, 'en_US', c9_key() ); $service->submitReview( $id, c9_payload( 4, 'en_US', $suffix ), 0, c9_key() );
	}
	wp_set_current_user( $owner );
	$first = $query->mineFavorites( 'en_US', array( 'page' => 1, 'per_page' => 2 ) );
	$secondPage = $query->mineFavorites( 'en_US', array( 'page' => 2, 'per_page' => 2 ) );
	c9_assert( $first['total'] === 3 && count( $first['items'] ) === 2 && count( $secondPage['items'] ) === 1, 'Real favorite rows cross the page boundary without changing the total.' );
	c9_assert( 3 === count( array_unique( array_merge( array_column( $first['items'], 'skill_id' ), array_column( $secondPage['items'], 'skill_id' ) ) ) ), 'Stable secondary ordering prevents duplicate resources between pages.' );
	c9_assert( 1 === $query->mineFavorites( 'en_US', array( 'q' => 'pagination-alpha' ) )['total'], 'Owner search matches actual current-language resource content.' );
	c9_assert( 0 === $query->mineFavorites( 'en_US', array( 'q' => '% OR 1=1' ) )['total'], 'Wildcards and SQL-shaped search text remain literal bound input.' );
	$native = $db->connection();
	$before = $native->num_queries; $query->mineFavorites( 'en_US', array( 'per_page' => 1 ) ); $single = $native->num_queries - $before;
	$before = $native->num_queries; $query->mineFavorites( 'en_US', array( 'per_page' => 3 ) ); $three = $native->num_queries - $before;
	c9_assert( $three <= $single + 1, 'Favorite card projection uses bounded batch queries as page size increases.' );
	$before = $native->num_queries; $query->mineReviews( 'en_US', array( 'per_page' => 1 ) ); $single = $native->num_queries - $before;
	$before = $native->num_queries; $reviews = $query->mineReviews( 'en_US', array( 'per_page' => 3 ) ); $three = $native->num_queries - $before;
	c9_assert( $reviews['total'] === 3 && count( $reviews['items'] ) === 3 && $three <= $single + 1, 'Own review revisions and Skill cards batch instead of one query per review.' );
	$publicSkill = $ids[0]; c9_approve( c9_head( $owner, $publicSkill ) );
	foreach ( array( 'one', 'two' ) as $suffix ) { $user = c9_user( 'page-reader-' . $suffix ); wp_set_current_user( $user ); $service->submitReview( $publicSkill, c9_payload( 3 ), 0, c9_key() ); c9_approve( c9_head( $user, $publicSkill ) ); }
	wp_set_current_user( 0 ); $pageOne = $query->publicReviews( $publicSkill, 'en_US', array( 'page' => 1, 'per_page' => 2 ) ); $pageTwo = $query->publicReviews( $publicSkill, 'en_US', array( 'page' => 2, 'per_page' => 2 ) );
	c9_assert( $pageOne['total'] === 3 && count( $pageOne['items'] ) === 2 && count( $pageTwo['items'] ) === 1 && 3 === count( array_unique( array_merge( array_column( $pageOne['items'], 'id' ), array_column( $pageTwo['items'], 'id' ) ) ) ), 'Approved review pagination is stable across actual multi-row pages.' );
} );

c9_report();
