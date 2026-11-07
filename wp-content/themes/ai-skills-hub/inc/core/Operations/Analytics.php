<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Discovery\Query;
use AISkillsHub\Core\Discovery\Settings;
use AISkillsHub\Core\Discovery\Views;
use AISkillsHub\Core\Frontend\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Private, bounded read model. Facts are queried fresh; unavailable data is never a fabricated zero. */
final class Analytics {
	public const CAPABILITY = 'aish_view_analytics';
	public function __construct( private Database $db, private Migrator $migrator, private PublicCatalog $catalog, private Query $discovery ) {}
	private static function authorize(): void { if ( ! current_user_can( self::CAPABILITY ) ) { throw new \RuntimeException( 'forbidden' ); } }
	private function native( string $name ): string {
		if ( ! in_array( $name, array( 'users', 'usermeta', 'posts', 'postmeta' ), true ) ) { throw new \LogicException( 'invalid_table' ); }
		$table = $this->db->connection()->$name;
		if ( ! is_string( $table ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new \RuntimeException( 'storage_unavailable' ); }
		return '`' . $table . '`';
	}
	/** Same current community identity gates as Discovery/Query; no personal rows leave this service. */
	private function liveUser( string $id ): string {
		if ( ! in_array( $id, array( 'u.ID', 'r.user_id', 'f.user_id' ), true ) ) { throw new \LogicException( 'invalid_identity' ); }
		$users = $this->native( 'users' ); $meta = $this->native( 'usermeta' );
		return "EXISTS (SELECT 1 FROM $users lu WHERE lu.ID=$id) AND (SELECT COUNT(*) FROM $meta a WHERE a.user_id=$id AND a.meta_key='_aish_account_state')=1 AND EXISTS (SELECT 1 FROM $meta a WHERE a.user_id=$id AND a.meta_key='_aish_account_state' AND a.meta_value='active') AND (SELECT COUNT(*) FROM $meta v WHERE v.user_id=$id AND v.meta_key='_aish_verified_email_hash')=1 AND EXISTS (SELECT 1 FROM $meta v WHERE v.user_id=$id AND v.meta_key='_aish_verified_email_hash' AND v.meta_value REGEXP '^[a-f0-9]{64}$') AND (SELECT COUNT(*) FROM $meta t WHERE t.user_id=$id AND t.meta_key='_aish_email_verified_at')=1 AND EXISTS (SELECT 1 FROM $meta t WHERE t.user_id=$id AND t.meta_key='_aish_email_verified_at' AND t.meta_value<>'') AND NOT EXISTS (SELECT 1 FROM $meta x WHERE x.user_id=$id AND x.meta_key IN ('_aish_account_deletion_pending','_aish_community_erasing','_aish_contributions_erasing','_aish_operations_test_data') AND x.meta_value NOT IN ('','0'))";
	}
	private function publicSql( string $locale ): string {
		$sql = $this->catalog->matchingIdsSql( $locale, array() ); $excluded = Query::excludedSkillIds();
		if ( $excluded ) { $sql .= ' AND s.id NOT IN (' . implode( ',', $excluded ) . ')'; }
		return $sql;
	}
	private static function recordedAt( string $option ): ?string {
		$value = get_option( $option, null );
		return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ? $value : null;
	}
	private function fact( callable $query, ?string $coverage = null, bool $requireCoverage = false ): array {
		if ( $requireCoverage && null === $coverage ) { return array( 'state' => Settings::tracking() ? 'not_collected' : 'disabled', 'value' => null, 'coverage' => null ); }
		try { return array( 'state' => 'available', 'value' => $query(), 'coverage' => $coverage ); }
		catch ( \Throwable $error ) { return array( 'state' => 'unavailable', 'value' => null, 'coverage' => $coverage ); }
	}
	private function number( string $sql, array $args = array() ): int { return (int) $this->db->value( $sql, $args ); }
	/** Complete UTC days only. Current relation stocks are explicitly separated from period actions. */
	public function snapshot( int $days = 7, string $locale = 'zh_CN' ): array {
		self::authorize();
		if ( ! in_array( $days, array( 7, 30 ), true ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_range' ); }
		if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException( 'storage_unavailable' ); }
		$today = gmdate( 'Y-m-d' ); $from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$daily = $this->db->table( 'daily_metrics' ); $events = $this->db->table( 'workflow_events' ); $meta = $this->native( 'usermeta' ); $users = $this->native( 'users' );
		$viewStart = self::recordedAt( Views::COVERAGE_OPTION ); $searchStart = self::recordedAt( SearchMetrics::COVERAGE . '_search' ); $activeStart = self::recordedAt( SearchMetrics::COVERAGE . '_active' );
		$metrics = array();
		$metrics['registrations'] = $this->fact( fn() => $this->number( "SELECT COUNT(*) FROM $users u WHERE u.user_registered>=%s AND u.user_registered<%s AND NOT EXISTS (SELECT 1 FROM $meta x WHERE x.user_id=u.ID AND x.meta_key='_aish_operations_test_data' AND x.meta_value NOT IN ('','0'))", array( $from, $today ) ) );
		$metrics['verified_current'] = $this->fact( fn() => $this->number( "SELECT COUNT(*) FROM $users u WHERE " . $this->liveUser( 'u.ID' ) ) );
		$metrics['verified_period'] = $this->fact( fn() => $this->number( "SELECT COUNT(*) FROM $users u WHERE " . $this->liveUser( 'u.ID' ) . " AND EXISTS (SELECT 1 FROM $meta t WHERE t.user_id=u.ID AND t.meta_key='_aish_email_verified_at' AND t.meta_value>=%s AND t.meta_value<%s)", array( $from, $today ) ) );
		// Counts current public state, so suspension/withdrawal/Skill removal is reflected immediately.
		$metrics['favorites'] = $this->fact( function () use ( $locale ): int {
			$public = $this->publicSql( $locale ); $favorites = $this->db->table( 'favorites' );
			return $this->number( "SELECT COUNT(*) FROM $favorites f WHERE f.is_active=1 AND f.skill_id IN ($public) AND " . $this->liveUser( 'f.user_id' ) );
		} );
		$metrics['reviews'] = $this->fact( function () use ( $locale ): int {
			$public = $this->publicSql( $locale ); $reviews = $this->db->table( 'reviews' ); $revisions = $this->db->table( 'review_revisions' );
			return $this->number( "SELECT COUNT(*) FROM $reviews r INNER JOIN $revisions v ON v.id=r.approved_revision_id AND v.review_id=r.id WHERE r.state='approved' AND v.moderation_state='approved' AND v.moderator_id>0 AND v.moderated_at>='1970-01-01 00:00:00' AND v.rating BETWEEN 1 AND 5 AND r.skill_id IN ($public) AND " . $this->liveUser( 'r.user_id' ) );
		} );
		$metrics['views'] = $this->fact( function () use ( $locale, $from, $today, $daily ): int {
			$public = str_replace( '%', '%%', $this->publicSql( $locale ) );
			return $this->number( "SELECT COALESCE(SUM(metric_count),0) FROM $daily WHERE metric_name='skill_view' AND dimension_type='skill' AND locale='' AND policy_version=%s AND stat_day>=%s AND stat_day<%s AND dimension_key REGEXP '^[1-9][0-9]*$' AND CAST(dimension_key AS UNSIGNED) IN ($public)", array( Views::POLICY, $from, $today ) );
		}, $viewStart, true );
		foreach ( array( 'search', 'search_no_result' ) as $name ) {
			$metrics[ $name ] = $this->fact( fn() => $this->number( "SELECT COALESCE(SUM(metric_count),0) FROM $daily WHERE metric_name=%s AND dimension_type='site' AND dimension_key='all' AND locale=%s AND policy_version=%s AND stat_day>=%s AND stat_day<%s", array( $name, $locale, SearchMetrics::POLICY, $from, $today ) ), $searchStart, true );
		}
		$metrics['active_days'] = $this->fact( fn() => $this->db->rows( "SELECT stat_day,metric_count FROM $daily WHERE metric_name='active_user' AND dimension_type='site' AND dimension_key='all' AND locale='' AND policy_version=%s AND stat_day>=%s AND stat_day<%s ORDER BY stat_day", array( SearchMetrics::POLICY, $from, $today ) ), $activeStart, true );
		$submissionWhere = $this->submissionWhere( 'e.subject_id' );
		$metrics['submissions'] = $this->fact( fn() => $this->number( "SELECT COUNT(DISTINCT e.subject_id) FROM $events e WHERE e.subject_type='submission' AND e.action='contribution_submitted' AND e.created_at>=%s AND e.created_at<%s AND $submissionWhere", array( $from, $today ) ) );
		$metrics['outcomes'] = $this->fact( fn() => $this->db->rows( "SELECT e.action,COUNT(*) AS actions,COUNT(DISTINCT e.subject_id) AS submissions FROM $events e WHERE e.subject_type='submission' AND e.action IN ('contribution_in_review','contribution_needs_changes','contribution_accepted','contribution_rejected') AND e.created_at>=%s AND e.created_at<%s AND $submissionWhere GROUP BY e.action ORDER BY e.action", array( $from, $today ) ) );
		$metrics['processing'] = $this->fact( fn() => $this->db->row( "SELECT COUNT(*) AS samples,AVG(TIMESTAMPDIFF(SECOND,a.started,b.finished)) AS seconds FROM (SELECT subject_id,MIN(created_at) AS started FROM $events WHERE subject_type='submission' AND action='contribution_submitted' GROUP BY subject_id) a INNER JOIN (SELECT subject_id,MIN(created_at) AS finished FROM $events WHERE subject_type='submission' AND action IN ('contribution_accepted','contribution_rejected') GROUP BY subject_id) b ON b.subject_id=a.subject_id WHERE b.finished>=%s AND b.finished<%s AND b.finished>=a.started AND " . $this->submissionWhere( 'a.subject_id' ), array( $from, $today ) ) );
		$metrics['terms'] = $this->fact( fn() => $this->terms( $locale, $from, $today ), $searchStart, true );
		$metrics['resources'] = $this->fact( fn() => $this->discovery->list( 'most-viewed', $locale, array( 'window' => $days, 'page' => 1, 'per_page' => 10 ) )['items'], $viewStart, true );
		$metrics['categories'] = $this->fact( fn() => $this->topics( 'category', $locale, $from, $today ), $viewStart, true );
		$metrics['use_cases'] = $this->fact( fn() => $this->topics( 'use_case', $locale, $from, $today ), $viewStart, true );
		$health = get_option( SearchMetrics::HEALTH, array() );
		foreach ( $metrics as &$metric ) {
			if ( null !== $metric['coverage'] ) {
				$metric['partial'] = $metric['coverage'] > $from . ' 00:00:00';
				if ( $metric['coverage'] >= $today . ' 00:00:00' ) { $metric['state'] = 'outside_coverage'; $metric['value'] = null; }
			}
		}
		unset( $metric );
		return array( 'from' => $from, 'until_exclusive' => $today, 'days' => $days, 'locale' => $locale, 'timezone' => 'UTC', 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'tracking' => Settings::tracking(), 'metrics' => $metrics, 'health' => is_array( $health ) ? array_filter( $health, static fn( string $day ): bool => $day >= $from && $day <= $today, ARRAY_FILTER_USE_KEY ) : array() );
	}
	private function terms( string $locale, string $from, string $until ): array {
		$daily = $this->db->table( 'daily_metrics' );
		$rows = $this->db->rows( "SELECT d.dimension_key AS term_key,SUM(d.metric_count) AS observations,COALESCE(SUM(z.metric_count),0) AS no_results FROM $daily d LEFT JOIN $daily z ON z.stat_day=d.stat_day AND z.metric_name='no_result_term' AND z.dimension_type='search_term' AND z.dimension_key=d.dimension_key AND z.locale=d.locale AND z.policy_version=d.policy_version WHERE d.metric_name='search_term' AND d.dimension_type='search_term' AND d.policy_version=%s AND d.locale=%s AND d.metric_count>=%d AND d.stat_day>=%s AND d.stat_day<%s GROUP BY d.dimension_key ORDER BY no_results DESC,observations DESC,d.dimension_key ASC LIMIT 30", array( SearchMetrics::POLICY, $locale, SearchPrivacy::THRESHOLD, $from, $until ) );
		foreach ( $rows as &$row ) {
			$row['term'] = SearchPrivacy::displayDimension( $row['term_key'] );
			if ( null === $row['term'] ) { throw new \RuntimeException( 'term_data_unavailable' ); }
			unset( $row['term_key'] );
		}
		unset( $row );
		return $rows;
	}
	/** Only Phase 10-owned private submission parents; Contact and legacy anonymous inbox stay separate. */
	private function submissionWhere( string $id ): string {
		if ( ! in_array( $id, array( 'e.subject_id', 'a.subject_id' ), true ) ) { throw new \LogicException( 'invalid_subject' ); }
		$p = $this->native( 'posts' ); $m = $this->native( 'postmeta' ); $u = $this->native( 'usermeta' );
		return "EXISTS (SELECT 1 FROM $p p WHERE p.ID=$id AND p.post_type='aish_message' AND p.post_status='private' AND p.post_parent=0 AND p.post_author>0 AND (SELECT COUNT(*) FROM $m v WHERE v.post_id=p.ID AND v.meta_key='_aish_submission_version')=1 AND EXISTS (SELECT 1 FROM $m v WHERE v.post_id=p.ID AND v.meta_key='_aish_submission_version' AND v.meta_value='1') AND (SELECT COUNT(*) FROM $m k WHERE k.post_id=p.ID AND k.meta_key='_aish_message_kind')=1 AND EXISTS (SELECT 1 FROM $m k WHERE k.post_id=p.ID AND k.meta_key='_aish_message_kind' AND k.meta_value='submit') AND NOT EXISTS (SELECT 1 FROM $u x WHERE x.user_id=p.post_author AND x.meta_key='_aish_operations_test_data' AND x.meta_value NOT IN ('','0')))";
	}
	private function topics( string $kind, string $locale, string $from, string $until ): array {
		$daily = $this->db->table( 'daily_metrics' ); $public = str_replace( '%', '%%', $this->publicSql( $locale ) );
		if ( 'category' === $kind ) {
			$t = $this->db->table( 'categories' ); $link = $this->db->table( 'skill_categories' ); $tr = $this->db->table( 'term_translations' );
			$join = "$t t INNER JOIN $link l ON l.category_id=t.id LEFT JOIN $tr tr ON tr.term_type='category' AND tr.term_id=t.id AND tr.locale=%s";
			$name = "COALESCE(NULLIF(TRIM(tr.name),''),t.name)"; $gate = 't.is_archived=0'; $group = 't.id,t.name,tr.name';
		} else {
			$t = $this->db->table( 'use_cases' ); $link = $this->db->table( 'use_case_skills' ); $tr = $this->db->table( 'use_case_translations' );
			$join = "$t t INNER JOIN $link l ON l.use_case_id=t.id INNER JOIN $tr tr ON tr.use_case_id=t.id AND tr.locale=%s";
			$eligibleTopics = str_replace( '%', '%%', $this->discovery->publicTopicIdsSql( 'use_case', $locale ) );
			$name = 'tr.name'; $gate = "t.id IN ($eligibleTopics)"; $group = 't.id,tr.name';
		}
		// Every public/translation/expiry predicate applies before ordering and LIMIT.
		return $this->db->rows( "SELECT t.id,$name AS name,SUM(d.metric_count) AS views FROM $join INNER JOIN $daily d ON d.dimension_type='skill' AND d.dimension_key REGEXP '^[1-9][0-9]*$' AND CAST(d.dimension_key AS UNSIGNED)=l.skill_id AND d.metric_name='skill_view' AND d.locale='' AND d.policy_version=%s AND d.stat_day>=%s AND d.stat_day<%s WHERE $gate AND l.skill_id IN ($public) GROUP BY $group HAVING views>0 ORDER BY views DESC,t.id ASC LIMIT 10", array( $locale, Views::POLICY, $from, $until ) );
	}
}
