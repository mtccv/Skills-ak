<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Admin\ContributionUi;

defined( 'ABSPATH' ) || exit;

/** Read-only WordPress native reporting; the service repeats authorization before querying. */
final class AnalyticsAdmin {
	public function __construct( private Analytics $analytics ) {}
	private static function t( string $zh, string $en ): string { return ContributionUi::t( $zh, $en ); }
	public function register(): void {
		add_action( 'admin_menu', function (): void { $label = self::t( '运营分析', 'Operations Analytics' ); add_submenu_page( 'aish', $label, $label, Analytics::CAPABILITY, 'aish-analytics', array( $this, 'screen' ) ); } );
	}
	private static function state( array $metric ): string {
		return match ( $metric['state'] ?? 'unavailable' ) {
			'available' => self::t( '实际数据', 'Recorded facts' ),
			'disabled' => self::t( '采集未启用', 'Collection disabled' ),
			'not_collected' => self::t( '尚未开始采集', 'Collection has not started' ),
			'outside_coverage' => self::t( '所选期间尚无采集覆盖', 'Selected period predates coverage' ),
			default => self::t( '数据暂不可用（不是零）', 'Temporarily unavailable (not zero)' ),
		};
	}
	public function screen(): void {
		if ( ! current_user_can( Analytics::CAPABILITY ) ) { wp_die( esc_html( self::t( '没有运营分析权限。', 'Analytics permission is required.' ) ), '', array( 'response' => 403 ) ); }
		nocache_headers();
		echo '<div class="wrap"><h1>' . esc_html( self::t( 'AI Skills Hub · 运营分析', 'AI Skills Hub · Operations Analytics' ) ) . '</h1>';
		try {
			$days = filter_var( $_GET['window'] ?? 7, FILTER_VALIDATE_INT ); $locale = $_GET['locale'] ?? 'zh_CN';
			if ( ! in_array( $days, array( 7, 30 ), true ) || ! is_string( $locale ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_range' ); }
			$report = $this->analytics->snapshot( $days, $locale );
			echo '<form method="get"><input type="hidden" name="page" value="aish-analytics"><label>' . esc_html( self::t( '完整 UTC 日期', 'Complete UTC days' ) ) . ' <select name="window">';
			foreach ( array( 7, 30 ) as $n ) { echo '<option value="' . $n . '" ' . selected( $days, $n, false ) . '>' . $n . '</option>'; }
			echo '</select></label> <label>' . esc_html( self::t( '资源/搜索语言', 'Resource / search language' ) ) . ' <select name="locale">';
			foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $locale, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
			echo '</select></label> <button class="button">' . esc_html( self::t( '查看', 'View' ) ) . '</button></form>';
			echo '<p>' . esc_html( self::t( '期间：', 'Period: ' ) . $report['from'] . ' 00:00 ≤ UTC < ' . $report['until_exclusive'] . ' 00:00 · ' . self::t( '查询时间：', 'Queried at: ' ) . $report['updated_at'] . ' UTC' ) . '</p>';
			echo '<p>' . esc_html( self::t( '当前收藏、评价及验证状态为查询时快照；其余期间指标不包含今天。新采集不回填过去，空缺不等于零。注册和投稿来自保留的业务记录；删除/匿名化可能减少历史样本。用户数为本 WordPress 用户库范围，资源与搜索按所选语言。', 'Favorites, approved reviews and verification states are current snapshots; period metrics exclude today. No historical backfill; missing coverage is not zero. Registration and submission facts use retained business records; deletion/anonymization can reduce historical samples. User figures cover this WordPress user database; resources and searches use the selected language.' ) ) . '</p>';
			if ( ! $report['tracking'] ) { echo '<div class="notice notice-info inline"><p>' . esc_html( self::t( '浏览/搜索/活跃采集当前关闭。既有真实记录仍可查看；未配置不显示为已采集零值。', 'View/search/activity collection is currently off. Existing facts remain visible; unconfigured metrics are not reported as observed zero.' ) ) . '</p></div>'; }
			if ( $report['health'] ) { echo '<div class="notice notice-warning inline"><p>' . esc_html( self::t( '所选期间存在统计失败或词数量上限记录，观察指标仅为已确认的部分覆盖：', 'Measurement failures or term-cap records exist; observations represent confirmed partial coverage: ' ) . implode( ', ', array_keys( $report['health'] ) ) ) . '</p></div>'; }
			$this->cards( $report['metrics'] );
			$this->activity( $report['metrics']['active_days'] );
			$this->submissions( $report['metrics'] );
			$this->terms( $report['metrics']['terms'] );
			$this->popular( $report['metrics'] );
			echo '<h2>' . esc_html( self::t( '口径、覆盖及限制', 'Definitions, coverage and limits' ) ) . '</h2><p>' . esc_html( self::t( '观察指标只包含自愿同意、未声明 GPC/DNT 且通过基础机器人排除的请求。每天短期随机凭证不能证明是真实独立人类。搜索成功响应仅在首结果页可见后计量；无结果是该次真实筛选结果为零，查询失败/参数无效不计。无结果搜索分母是同期间、同语言的成功搜索响应数；不计算跨期投稿转化率。', 'Observation metrics include consenting requests without GPC/DNT that pass basic bot exclusions. Daily random credentials do not prove unique humans. Successful search responses are measured on the visible first result page; zero means that actual filtered result was empty. Failed/invalid queries are excluded. The no-result denominator is successful searches in the same language and period. No cross-period submission conversion rate is computed.' ) ) . '</p><p>' . esc_html( self::t( '采集与聚合在同一事务提交，无异步估算；报告每次查询刷新，不共用用户缓存。事件最多保留 7 日、合格词日桶 30 日、其他运营日桶 400 日；Cron 分批清理，故障时可能延后。指标不足或失败不会阻断正常搜索/账户操作。详见 ANALYTICS_DEFINITIONS.md。', 'Events and aggregates commit atomically; there is no estimated backfill. Reports query fresh facts without shared user caches. Events retain 7 days, qualified term buckets 30 days, other operations buckets 400 days; bounded Cron cleanup may be delayed on failure. Analytics failures never block searches or account actions. See ANALYTICS_DEFINITIONS.md.' ) ) . '</p>';
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '统计暂不可用，或日期/语言参数无效。未将错误计为零。', 'Reporting is unavailable, or the period/language is invalid. Errors are not counted as zero.' ) ) . '</p></div>'; }
		echo '</div>';
	}
	private function cards( array $metrics ): void {
		$definitions = array(
			'registrations' => array( self::t( '新注册账户', 'New registrations' ), self::t( 'WP users.user_registered；期间内仍保留的账户，按 ID 去重；不等于活跃。', 'WP users.user_registered; retained accounts created in period, once per ID; not activity.' ) ),
			'verified_current' => array( self::t( '当前有效验证账户', 'Currently verified accounts' ), self::t( '当前 active、唯一验证时间/hash，排除删除/清理/测试；账户快照。', 'Current active account with unique verification evidence; exclude deletion/erasure/test flags; snapshot.' ) ),
			'verified_period' => array( self::t( '期间完成验证且当前仍有效', 'Verified in period and still eligible' ), self::t( '当前验证时间落在期间；换邮箱会更新，不能当历史首次验证事件总数。', 'Current verification timestamp in period; email changes replace it. Not a historical first-verification total.' ) ),
			'views' => array( self::t( '有效 Skill 浏览', 'Qualified Skill views' ), self::t( 'daily_metrics skill_view；同观察主体/Skill/UTC日一次，跨语言去重；仅当前语言公开资源。', 'daily_metrics skill_view; once per observer/Skill/UTC day, combined languages; currently public resources in chosen language.' ) ),
			'favorites' => array( self::t( '当前有效收藏', 'Current eligible favorites' ), self::t( 'favorites 活跃关系＋有效验证用户＋当前语言公开 Skill；不是历史点击数。', 'Active favorite relationships + eligible verified users + currently public Skills; not historical clicks.' ) ),
			'reviews' => array( self::t( '当前已审核评价', 'Current approved reviews' ), self::t( 'reviews 批准指针＋对应修订批准证据＋有效用户和公开 Skill；按评价 ID。', 'Approved review pointer + approved matching revision + eligible user and public Skill; once per review.' ) ),
			'submissions' => array( self::t( '期间提交投稿', 'Submissions submitted in period' ), self::t( 'workflow_events contribution_submitted；仅受控私有投稿，每个投稿 ID 一次；不含 Contact/更新建议。', 'workflow_events contribution_submitted; controlled private submissions once per ID; excludes Contact/update suggestions.' ) ),
			'search' => array( self::t( '成功搜索观察次数', 'Observed successful searches' ), self::t( '已签名成功结果响应；同日主体＋响应幂等，每主体每日最多100；不是所有 HTTP 请求。', 'Signed successful result response; observer + response idempotency, max 100 per daily observer; not all HTTP requests.' ) ),
			'search_no_result' => array( self::t( '无结果搜索观察次数', 'Observed zero-result searches' ), self::t( '成功搜索中真实结果数=0；与成功搜索相同期间、语言和去重，不含错误。', 'Successful searches with actual total=0; same period, language and dedupe; excludes query errors.' ) ),
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::t( '指标', 'Metric' ) ) . '</th><th>' . esc_html( self::t( '数量 / 状态', 'Count / state' ) ) . '</th><th>' . esc_html( self::t( '来源与去重', 'Source and deduplication' ) ) . '</th></tr></thead><tbody>';
		foreach ( $definitions as $key => [ $title, $definition ] ) {
			$m = $metrics[ $key ]; echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td>' . esc_html( 'available' === $m['state'] ? number_format_i18n( (int) $m['value'] ) : self::state( $m ) ) . '</td><td>' . esc_html( $definition );
			if ( $m['coverage'] ) { echo '<br>' . esc_html( self::t( '实际采集起点：', 'Actual collection start: ' ) . $m['coverage'] . ' UTC' ); }
			if ( ! empty( $m['partial'] ) ) { echo '<br><strong>' . esc_html( self::t( '期间覆盖不完整', 'Partial period coverage' ) ) . '</strong>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	private function activity( array $metric ): void {
		echo '<h2>' . esc_html( self::t( '已验证账户每日活跃', 'Daily active verified accounts' ) ) . '</h2><p>' . esc_html( self::t( '只计明确同意后成功登录或完成有效账户交互，每个 WP 用户/UTC日一次，日盐 HMAC，不保存用户 ID。不计算月度独立用户，也不把日活之和叫月活。空缺日期没有已确认观测，不能据此断言零用户。', 'Consenting successful login or valid account interaction, once per WP user/UTC day using daily HMAC; no stored user ID. No monthly unique-user figure or DAU sum mislabeled as MAU. Missing dates have no confirmed observations and do not establish zero users.' ) ) . '</p>';
		if ( 'available' !== $metric['state'] ) { echo '<p>' . esc_html( self::state( $metric ) ) . '</p>'; return; }
		echo '<p>' . esc_html( self::t( '采集起点：', 'Collection start: ' ) . ( $metric['coverage'] ?? '—' ) . ' UTC' ) . '</p>';
		$this->table( array( 'stat_day' => self::t( '日期 UTC', 'UTC day' ), 'metric_count' => self::t( '当天去重账户', 'Distinct accounts that day' ) ), $metric['value'] );
	}
	private function submissions( array $metrics ): void {
		echo '<h2>' . esc_html( self::t( '投稿处理结果与时长', 'Submission outcomes and processing time' ) ) . '</h2><p>' . esc_html( self::t( '处理数为期间实际状态事件，投稿数为该结果涉及的去重投稿；同一投稿可先补充资料再被接受，不能把各行当互斥用户数。仅保留的 Phase10 受控投稿，不含历史匿名来信；没有首次提交事件的历史记录不推算处理时长。', 'Actions are actual period workflow events; submissions are distinct IDs with that outcome. A submission can need information then be accepted, so rows are not mutually exclusive users. Only retained Phase 10 owned submissions; no inferred duration for older records without a first-submit event.' ) ) . '</p>';
		$m = $metrics['outcomes']; if ( 'available' === $m['state'] ) { $this->table( array( 'action' => self::t( '实际操作', 'Recorded action' ), 'actions' => self::t( '处理次数', 'Action count' ), 'submissions' => self::t( '涉及投稿', 'Distinct submissions' ) ), $m['value'] ); } else { echo '<p>' . esc_html( self::state( $m ) ) . '</p>'; }
		$p = $metrics['processing']; $v = $p['value'];
		if ( 'available' === $p['state'] && (int) ( $v['samples'] ?? 0 ) > 0 ) { echo '<p>' . esc_html( self::t( '最早保留的提交→最早保留的接受/拒绝平均自然时长：', 'Mean elapsed earliest retained submission → earliest retained acceptance/rejection: ' ) . number_format_i18n( (float) $v['seconds'] / 3600, 2 ) . ' h · n=' . (int) $v['samples'] ) . '</p>'; }
		else { echo '<p>' . esc_html( 'available' === $p['state'] ? self::t( '无符合条件的时长样本。', 'No eligible duration samples.' ) : self::state( $p ) ) . '</p>'; }
		echo '<p>' . esc_html( self::t( '保留的终态事件时间位于所选期间，始终以实际事件配对；旧事件会按保留策略清理，不能保证是原始首次提交/终态。包含等待补充资料的自然时间，不代表管理员工时。', 'Earliest retained terminal outcome must fall in the selected period. Older workflow events expire, so these pairs may not be the original first submission/outcome. Includes waiting for information and does not represent staff work hours.' ) ) . '</p>';
	}
	private function terms( array $metric ): void {
		echo '<h2>' . esc_html( self::t( '内容缺口：合格搜索词', 'Content gaps: eligible search terms' ) ) . '</h2><p>' . esc_html( self::t( '只汇总同一 UTC 日至少5个不同观察主体的词日桶；低频只保存不可逆日哈希，不存明文。每词/语言/主体/日去重，多日为日去重累计，不是期间独立人数。邮箱、密钥、URL、长数字及无法安全分类的词只计搜索总量。最多显示30项，每日最多2000种词，保存30日。无结果可来自组合筛选。', 'Only day buckets with at least 5 different observers on the SAME UTC day. Low-frequency terms retain only daily keyed hashes, never plaintext. Once per term/language/observer/day; multi-day sums are daily-observer totals, not period unique people. Sensitive/unclassified terms count only in site totals. At most 30 rows, 2,000 terms admitted daily, 30-day term retention. Zero results can reflect combined filters.' ) ) . '</p>';
		if ( 'available' !== $metric['state'] ) { echo '<p>' . esc_html( self::state( $metric ) ) . '</p>'; return; }
		$this->table( array( 'term' => self::t( '规范短词', 'Normalized short term' ), 'observations' => self::t( '日去重累计', 'Sum of daily observers' ), 'no_results' => self::t( '其中观察到零结果', 'Observers with zero results' ) ), $metric['value'] );
	}
	private function popular( array $metrics ): void {
		foreach ( array( 'resources' => self::t( '热门资源', 'Popular resources' ), 'categories' => self::t( '热门分类', 'Popular categories' ), 'use_cases' => self::t( '热门场景', 'Popular use cases' ) ) as $key => $title ) {
			echo '<h2>' . esc_html( $title ) . '</h2>'; $m = $metrics[ $key ];
			if ( 'available' !== $m['state'] ) { echo '<p>' . esc_html( self::state( $m ) ) . '</p>'; continue; }
			$rows = $m['value'];
			if ( 'resources' === $key ) { $rows = array_map( static fn( array $row ): array => array( 'id' => $row['id'], 'name' => $row['title'], 'views' => $row['metric']['views'] ?? 0 ), $rows ); }
			$this->table( array( 'id' => 'ID', 'name' => self::t( '名称', 'Name' ), 'views' => self::t( '有效浏览', 'Qualified views' ) ), $rows );
		}
		echo '<p>' . esc_html( self::t( '按同期间有效 Skill 浏览累计，仅当前语言允许公开的资源与专题。一个 Skill 可属于多个分类/场景，各行不是互斥总量；不代表专题页面浏览量。缺分类翻译时使用已有名称，不生成译文。', 'Ranks use qualified Skill views in the same period for currently public resources/topics in the selected language. One Skill can belong to several categories/use cases; rows overlap and do not measure topic-page visits. Existing category names are used when a translation is missing.' ) ) . '</p>';
	}
	private function table( array $columns, array $rows ): void {
		echo '<table class="widefat striped"><thead><tr>'; foreach ( $columns as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr>'; foreach ( $columns as $key => $label ) { echo '<td>' . esc_html( (string) ( $row[ $key ] ?? '—' ) ) . '</td>'; } echo '</tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="' . count( $columns ) . '">' . esc_html( self::t( '没有符合当前范围的已确认记录。', 'No confirmed records in this scope.' ) ) . '</td></tr>'; } echo '</tbody></table>';
	}
}
