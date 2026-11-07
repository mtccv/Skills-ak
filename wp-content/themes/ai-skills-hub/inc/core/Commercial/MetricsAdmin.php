<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Commercial;

use AISkillsHub\Core\Admin\ContributionUi;

defined( 'ABSPATH' ) || exit;

/** Permission-isolated aggregate reports. No partner export, identities or private inquiry payload. */
final class MetricsAdmin {
	public function __construct( private Metrics $metrics, private Enterprise $enterprise ) {}
	private static function t( string $zh, string $en ): string { return ContributionUi::t( $zh, $en ); }
	public function register(): void {
		add_action( 'admin_menu', function (): void { $label = self::t( '赞助展示统计', 'Sponsorship Metrics' ); add_submenu_page( 'aish', $label, $label, Metrics::CAPABILITY, 'aish-commercial-metrics', array( $this, 'screen' ) ); } );
	}
	private static function names(): array {
		return array( 'sponsor_impression' => self::t( '观察到的展示', 'Observed impressions' ), 'sponsor_click' => self::t( '观察到的链接点击', 'Observed link clicks' ), 'sponsor_impression_unique' => self::t( '按日去重展示累计', 'Sum of daily-deduplicated impressions' ), 'sponsor_click_unique' => self::t( '按日去重点击累计', 'Sum of daily-deduplicated clicks' ) );
	}
	public function screen(): void {
		if ( ! current_user_can( Metrics::CAPABILITY ) ) { wp_die( esc_html( self::t( '无权查看此汇总。', 'You cannot view these aggregates.' ) ), '', array( 'response' => 403 ) ); }
		nocache_headers();
		echo '<div class="wrap"><h1>' . esc_html( self::t( '赞助展示统计', 'Sponsorship Metrics' ) ) . '</h1><p>' . esc_html( self::t( '免费运营；没有购买、收入、付费会员或成交率指标。以下只来自明确同意的有效展示、点击及实际收到的咨询记录。', 'Free operation: no purchase, revenue, paid-member or sales-conversion metrics. These figures use consenting observations and actual received inquiry records only.' ) ) . '</p>';
		try {
			$days = $_GET['days'] ?? '7'; $locale = $_GET['locale'] ?? 'zh_CN';
			if ( ! is_string( $days ) || ! in_array( $days, array( '7', '30' ), true ) || ! is_string( $locale ) || ! in_array( $locale, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'invalid_window' ); }
			$data = $this->metrics->summary( (int) $days, $locale );
			echo '<form method="get"><input type="hidden" name="page" value="aish-commercial-metrics"><label>' . esc_html( self::t( '期间', 'Period' ) ) . ' <select name="days">'; foreach ( array( '7', '30' ) as $n ) { echo '<option value="' . esc_attr( $n ) . '"' . selected( $days, $n, false ) . '>' . esc_html( $n . self::t( ' 个 UTC 日（含今天）', ' UTC days, including today' ) ) . '</option>'; }
			echo '</select></label> <label>' . esc_html( self::t( '语言', 'Language' ) ) . ' <select name="locale">'; foreach ( array( 'zh_CN' => '中文', 'en_US' => 'English' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $locale, $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label> <button class="button">' . esc_html( self::t( '查看', 'View' ) ) . '</button></form>';
			echo '<p>' . esc_html( $data['start'] . ' → ' . $data['end'] . ' UTC · ' . self::t( '今天尚未结束；查询时读取实际聚合，没有跨请求报表缓存。', 'Today is incomplete; live aggregates with no cross-request report cache.' ) ) . '</p><p>' . esc_html( self::t( '实际采集起点：', 'Actual collection start: ' ) . ( $data['coverage'] ?? self::t( '尚未采集', 'Not collected' ) ) ) . '</p>';
			if ( ! $data['tracking_enabled'] ) { echo '<p><strong>' . esc_html( self::t( '当前采集关闭；已保存历史如有则仍显示。', 'Collection is currently off; retained historical facts remain visible.' ) ) . '</strong></p>'; }
			if ( $data['partial'] ) { echo '<p><strong>' . esc_html( self::t( '期间覆盖不完整；无观测不能证明没有访问。', 'Partial period coverage; no observations do not prove no traffic.' ) ) . '</strong></p>'; }
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::t( '指标', 'Metric' ) ) . '</th><th>' . esc_html( self::t( '数量', 'Count' ) ) . '</th></tr></thead><tbody>';
			foreach ( self::names() as $metric => $label ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( 'available' === $data['state'] ? number_format_i18n( $data['totals'][ $metric ] ) : self::t( '尚无已确认观测', 'No confirmed observations' ) ) . '</td></tr>'; }
			try { $inquiries = $this->enterprise->countReceived( $data['start'], gmdate( 'Y-m-d H:i:s', time() + 1 ) ); echo '<tr><th scope="row">' . esc_html( self::t( '实际收到的企业咨询（所有语言）', 'Received enterprise inquiries, all languages' ) ) . '</th><td>' . esc_html( number_format_i18n( $inquiries ) ) . '</td></tr>'; }
			catch ( \Throwable $error ) { echo '<tr><th scope="row">' . esc_html( self::t( '实际企业咨询', 'Received enterprise inquiries' ) ) . '</th><td>' . esc_html( self::t( '暂不可用，不按零处理', 'Unavailable, not counted as zero' ) ) . '</td></tr>'; }
			echo '</tbody></table><p>' . esc_html( self::t( '展示需可见、聚焦且至少 50% 进入视口累计 1 秒；点击使用正常链接，不重定向、不阻塞。每个页面响应、观察主体和事件类型最多记一次；另按赞助 ID、位置、语言、观察主体与 UTC 日去重。重复点击与刷新受去重/限额影响，数据不是全部点击量或独立真人数；日去重累计不是期间独立访客。', 'An impression requires focus and at least 50% visibility for 1 second. Clicks use normal links without redirects or blocking. At most one observation per response, daily observer and event type; separate daily dedupe uses sponsor ID, placement, language and observer. Retries, reloads and quotas affect coverage. Counts are neither all clicks nor unique people; summed daily observers are not period unique visitors.' ) ) . '</p>';
			echo '<p>' . esc_html( self::t( '同一观察主体每天最多 100 次展示/点击观测，全站每天最多 10,000 次。匿名去重事件保存 7 日，日聚合保存 400 日；不保存 IP、账户 ID、完整 URL、User-Agent 或跨日身份。不计算点击→咨询归因或成交率。咨询数来自仍保留的私有 Inbox 实际记录，删除后可能减少，所有语言汇总。', 'At most 100 impression/click observations per daily observer and 10,000 site-wide per UTC day. Dedupe events are retained for 7 days; daily aggregates for 400. No IP, account ID, complete URL, User-Agent or cross-day identity is stored. No click-to-inquiry attribution or sales conversion is calculated. Inquiry counts use retained private Inbox records, all languages, and may decrease after deletion.' ) ) . '</p><h2>' . esc_html( self::t( '位置明细（最多 200 行）', 'Placement breakdown, at most 200 rows' ) ) . '</h2><table class="widefat striped"><thead><tr><th>ID / ' . esc_html( self::t( '位置', 'Placement' ) ) . '</th><th>' . esc_html( self::t( '指标', 'Metric' ) ) . '</th><th>' . esc_html( self::t( '数量', 'Count' ) ) . '</th></tr></thead><tbody>';
			foreach ( $data['rows'] as $row ) { echo '<tr><td>' . esc_html( $row['dimension_key'] ) . '</td><td>' . esc_html( self::names()[ $row['metric_name'] ] ) . '</td><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td></tr>'; }
			if ( ! $data['rows'] ) { echo '<tr><td colspan="3">' . esc_html( self::t( '没有当前范围内的已确认观测。', 'No confirmed observations in this range.' ) ) . '</td></tr>'; } echo '</tbody></table>';
		} catch ( \Throwable $error ) { echo '<div class="notice notice-error"><p>' . esc_html( self::t( '统计暂不可用或参数无效。未将查询失败计为零。', 'Reporting is unavailable or parameters are invalid. Query failures are not counted as zero.' ) ) . '</p></div>'; }
		echo '</div>';
	}
}
