<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Operations;

use AISkillsHub\Core\Admin\ContributionUi as Ui;
use AISkillsHub\Core\AI\Settings;
use AISkillsHub\Core\Database\Database;
use AISkillsHub\Core\Database\Migrator;
use AISkillsHub\Core\Frontend\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/** Current-state batch counts. Reuse is local to one snapshot; every source is freshly authorized. */
final class Dashboard {
	private array $providers = array();
	public function __construct( private Database $db, private Migrator $migrator, private Workbench $workbench, private Health $health ) {}
	public function addSummary( string $key, string $capability, callable $provider ): void {
		if ( ! preg_match( '/^[a-z_]{1,40}$/D', $key ) || ! preg_match( '/^aish_[a-z_]{1,60}$/D', $capability ) ) { throw new \InvalidArgumentException( 'invalid_summary' ); }
		$this->providers[ $key ] = array( $capability, $provider );
	}
	private function card( string $key, string $label, string $unit, string $scope, string $url, callable $read ): array {
		$row = compact( 'key', 'label', 'unit', 'scope', 'url' );
		try { if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException(); } $value = $read(); if ( ! is_int( $value ) || $value < 0 ) { throw new \RuntimeException(); } return $row + array( 'value' => $value, 'status' => 'available' ); }
		catch ( \Throwable $error ) { return $row + array( 'value' => null, 'status' => 'unavailable' ); }
	}
	/** A failed aggregate is unavailable, never a fabricated zero; no cross-request cache. */
	private function aggregate( callable $read ): ?array {
		try { if ( ! $this->migrator->isCurrent() ) { throw new \RuntimeException(); } return $read(); }
		catch ( \Throwable $error ) { return null; }
	}
	private static function count( ?array $counts, string $key ): int {
		if ( null === $counts || ! array_key_exists( $key, $counts ) || ! is_numeric( $counts[ $key ] ) ) { throw new \RuntimeException(); }
		return (int) $counts[ $key ];
	}
	public function snapshot(): array {
		if ( ! current_user_can( Workbench::CAP ) ) { throw new \DomainException( 'forbidden' ); }
		$rows = array(); $current = Ui::t( '当前状态快照；包括所有创建日期，UTC', 'Current state across all creation dates; UTC' );
		foreach ( Workbench::available() as $type => $label ) {
			$states = in_array( $type, array( 'submissions', 'updates' ), true ) ? array( 'submitted', 'in_review' ) : ( in_array( $type, array( 'review_reports', 'skill_reports' ), true ) ? array( 'pending', 'reviewing' ) : array( 'pending' ) );
			$requested = 'submissions' === $type ? array_merge( $states, array( 'needs_changes' ) ) : $states;
			$counts = $this->aggregate( fn(): array => $this->workbench->counts( $this->db, $this->migrator, $type, $requested ) );
			$unit = 'reviews' === $type ? Ui::t( '待审修订', 'pending revisions' ) : Ui::t( '记录', 'records' );
			foreach ( $states as $state ) {
				$scope = $current . ' · ' . Workbench::stateLabel( $state );
				$rows[] = $this->card( $type . '_' . $state, $label . ( count( $states ) > 1 ? ' · ' . Workbench::stateLabel( $state ) : '' ), $unit, $scope, Ui::url( 'aish-workbench', array( 'queue' => $type, 'state' => $state ) ), fn(): int => self::count( $counts, $state ) );
			}
			if ( 'submissions' === $type ) { $rows[] = $this->card( 'submissions_needs_information', Ui::t( '需要补充资料的投稿', 'Submissions needing information' ), Ui::t( '记录', 'records' ), $current, Ui::url( 'aish-workbench', array( 'queue' => $type, 'state' => 'needs_changes' ) ), fn(): int => self::count( $counts, 'needs_changes' ) ); }
		}
		if ( current_user_can( Health::CAP ) ) {
			$jobs = $this->aggregate( function (): array { $result = array_fill_keys( array( 'incomplete', 'failed', 'retry' ), 0 ); foreach ( $this->db->rows( 'SELECT status,COUNT(*) AS total FROM ' . $this->db->table( 'jobs' ) . " WHERE status IN ('incomplete','failed','retry') GROUP BY status" ) as $row ) { $result[ $row['status'] ] = (int) $row['total']; } return $result; } );
			$sources = $this->aggregate( fn(): ?array => $this->db->row( 'SELECT COALESCE(SUM(source_status=\'missing\'),0) AS missing,COALESCE(SUM(change_status<>\'handled\'),0) AS changes FROM ' . $this->db->table( 'imports' ) ) );
			foreach ( array( 'incomplete' => Ui::t( 'GitHub 扫描未完成', 'Incomplete GitHub scans' ), 'failed' => Ui::t( 'GitHub 扫描失败', 'Failed GitHub scans' ), 'retry' => Ui::t( 'GitHub 等待重试', 'GitHub retries waiting' ) ) as $state => $label ) { $rows[] = $this->card( 'github_' . $state, $label, Ui::t( '任务', 'jobs' ), $current . Ui::t( '；任务记录，不等于失效资源数', '; job records, not unavailable resources' ), Ui::url( 'aish-source-health', array( 'view' => 'jobs', 'state' => $state ) ), fn(): int => self::count( $jobs, $state ) ); }
			$rows[] = $this->card( 'source_missing', Ui::t( '来源疑似缺失', 'Sources suspected missing' ), Ui::t( '来源记录', 'source records' ), $current . Ui::t( '；包括已记录运营处理的快照，不推断永久失效', '; includes operationally acknowledged snapshots; not permanent deletion' ), Ui::url( 'aish-source-health', array( 'view' => 'sources', 'state' => 'missing' ) ), fn(): int => self::count( $sources, 'missing' ) );
			$rows[] = $this->card( 'source_changes', Ui::t( '待采集审稿确认的来源变化', 'Source changes awaiting import review' ), Ui::t( '来源记录', 'source records' ), $current . Ui::t( '；运营处理注记不代替采集审稿确认', '; operational acknowledgements do not replace import review' ), Ui::url( 'aish-source-health', array( 'view' => 'sources', 'state' => 'needs_check' ) ), fn(): int => self::count( $sources, 'changes' ) );
		}
		if ( current_user_can( 'aish_generate_content' ) ) {
			$ai = $this->db->table( 'ai_jobs' ); $attempts = $this->db->table( 'ai_attempts' ); $link = Ui::url( 'aish-ai-generation' );
			$counts = $this->aggregate( fn(): ?array => $this->db->row( "SELECT COALESCE(SUM(j.status='needs_review' AND j.review_status IN ('pending','edited')),0) AS drafts,COALESCE(SUM(j.status='failed'),0) AS failed,COALESCE(SUM(j.status='failed' AND EXISTS (SELECT 1 FROM $attempts a WHERE a.job_id=j.id AND a.status='uncertain')),0) AS uncertain FROM $ai j WHERE j.status IN ('needs_review','failed')" ) );
			$rows[] = $this->card( 'ai_drafts', Ui::t( 'AI 草稿待审', 'AI drafts awaiting review' ), Ui::t( '任务草稿', 'job drafts' ), $current, Ui::url( 'aish-ai-generation', array( 'status' => 'needs_review' ) ), fn(): int => self::count( $counts, 'drafts' ) );
			$rows[] = $this->card( 'ai_failed', Ui::t( 'AI 失败任务（含结果不确定）', 'Failed AI jobs (including uncertain outcomes)' ), Ui::t( '任务', 'jobs' ), $current, Ui::url( 'aish-ai-generation', array( 'status' => 'failed' ) ), fn(): int => self::count( $counts, 'failed' ) );
			$rows[] = $this->card( 'ai_uncertain', Ui::t( 'AI 结果不确定', 'Uncertain AI outcomes' ), Ui::t( '失败任务中的子集', 'subset of failed jobs' ), $current . Ui::t( '；进入失败任务列表，查看具体尝试证据', '; open failed jobs to inspect attempt evidence' ), Ui::url( 'aish-ai-generation', array( 'status' => 'failed' ) ), fn(): int => self::count( $counts, 'uncertain' ) );
			try { $settings = new Settings( $this->db ); $profiles = $settings->profiles(); $configured = count( array_filter( $profiles, static fn( array $p ): bool => ! empty( $p['enabled'] ) && ! empty( $p['key_configured'] ) ) ); $rows[] = array( 'key' => 'ai_configuration', 'label' => Ui::t( 'AI 队列配置', 'AI queue configuration' ), 'unit' => Ui::t( '已启用且配置密钥的模型配置', 'enabled profiles with configured keys' ), 'scope' => Ui::t( '仅检查配置，不调用外部 API；保持当前暂停设置', 'Configuration only; no API call; current pause setting is preserved' ), 'value' => $configured ?: null, 'status' => ! $configured ? 'not_configured' : ( $settings->paused() ? 'paused' : 'available' ), 'url' => $link ); }
			catch ( \Throwable $error ) { $rows[] = array( 'key' => 'ai_configuration', 'label' => Ui::t( 'AI 配置状态', 'AI configuration state' ), 'unit' => '', 'scope' => $current, 'value' => null, 'status' => 'unavailable', 'url' => $link ); }
		}
		if ( current_user_can( 'aish_manage_skills' ) ) {
			foreach ( array( 'zh_CN' => '简体中文', 'en_US' => 'English' ) as $locale => $language ) {
				$rows[] = $this->card( 'public_' . $locale, Ui::t( '可公开 Skills · ', 'Public Skills · ' ) . $language, Ui::t( '当前语言资源', 'resources in this language' ), $current . Ui::t( '；当前修订获批且译文完整；两个语言不可相加当作独立 Skill 数', '; approved current revision and complete translation; language totals are not distinct Skill totals' ), Ui::url( 'aish-skills' ), function () use ( $locale ): int { $ids = ( new PublicCatalog( $this->db, $this->migrator ) )->matchingIdsSql( $locale, array() ); return (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'skills' ) . " WHERE id IN ($ids)" ); } );
			}
		}
		foreach ( $this->providers as $key => [ $capability, $provider ] ) {
			if ( ! current_user_can( $capability ) ) { continue; }
			try { $extra = $provider(); if ( ! is_array( $extra ) || count( $extra ) > 20 ) { throw new \RuntimeException(); } foreach ( $extra as $row ) { if ( ! is_array( $row ) || ! isset( $row['key'], $row['label'], $row['scope'], $row['unit'], $row['url'], $row['status'] ) || ! in_array( $row['status'], array( 'available', 'unavailable', 'not_configured', 'paused' ), true ) || ( null !== ( $row['value'] ?? null ) && ( ! is_int( $row['value'] ) || $row['value'] < 0 ) ) ) { throw new \RuntimeException(); } } $rows = array_merge( $rows, $extra ); }
			catch ( \Throwable $error ) { $rows[] = array( 'key' => $key, 'label' => $key, 'unit' => '', 'scope' => $current, 'url' => '', 'value' => null, 'status' => 'unavailable' ); }
		}
		return array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'timezone' => 'UTC', 'metrics' => $rows );
	}
}
