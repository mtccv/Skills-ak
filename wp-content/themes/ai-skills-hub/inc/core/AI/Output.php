<?php
declare(strict_types=1);

namespace AISkillsHub\Core\AI;

defined( 'ABSPATH' ) || exit;

/** Strict JSON draft contract. Validation is not semantic fact checking or a safety certificate. */
final class Output {
	public const MAX_BYTES = 131072;
	private const FULL = array( 'title', 'summary', 'description', 'key_features', 'tasks', 'use_cases', 'installation', 'requirements', 'limitations', 'safety_notes' );
	private const RISK_CATEGORIES = array( 'command', 'shell', 'filesystem', 'sensitive_data', 'network', 'dependency', 'privilege', 'download', 'prompt_injection', 'other' );

	public static function parse( string $json, array $source, string $mode ): array {
		if ( strlen( $json ) > self::MAX_BYTES || ! preg_match( '//u', $json ) ) { throw new \InvalidArgumentException( 'ai_output_size_or_encoding' ); }
		try { $data = json_decode( $json, false, 32, JSON_THROW_ON_ERROR ); }
		catch ( \JsonException $error ) { throw new \InvalidArgumentException( 'ai_output_invalid_json' ); }
		self::duplicates( $json );
		$source = Prompt::source( $source ); $locales = Prompt::locales( $mode );
		$data = self::object( $data, array( 'locales', 'suggestions', 'risk' ) );
		$data['locales'] = self::object( $data['locales'], $locales );
		$fields = match ( $mode ) { 'summary' => array( 'summary' ), 'use_cases' => array( 'use_cases' ), default => self::FULL };
		foreach ( $data['locales'] as $locale => &$content ) {
			$content = self::object( $content, $fields );
			foreach ( array( 'title' => 191, 'summary' => 1200, 'description' => 12000 ) as $field => $max ) {
				if ( isset( $content[ $field ] ) ) { $content[ $field ] = self::text( $content[ $field ], $max ); self::language( $content[ $field ], $locale ); }
			}
			foreach ( array( 'key_features', 'tasks', 'use_cases', 'limitations', 'safety_notes' ) as $field ) {
				if ( isset( $content[ $field ] ) ) { $content[ $field ] = self::texts( $content[ $field ], 12, 2000 ); }
			}
			if ( 'use_cases' === $mode && $content['use_cases'] ) { self::language( implode( ' ', $content['use_cases'] ), $locale ); }
			if ( isset( $content['installation'] ) ) { $content['installation'] = self::installation( $content['installation'], $source ); }
			if ( isset( $content['requirements'] ) ) {
				$content['requirements'] = self::items( $content['requirements'], 20 );
				foreach ( $content['requirements'] as &$requirement ) { $requirement = self::object( $requirement, array( 'text', 'evidence' ) ); $requirement['text'] = self::text( $requirement['text'], 1000 ); $requirement['evidence'] = self::evidence( $requirement['evidence'], $source ); } unset( $requirement );
			}
		}
		unset( $content );
		$data['suggestions'] = self::suggestions( $data['suggestions'], $source );
		$risk = self::object( $data['risk'], array( 'level', 'findings', 'limitations' ) );
		if ( ! in_array( $risk['level'], array( 'unknown', 'low', 'medium', 'high' ), true ) ) { throw new \InvalidArgumentException( 'ai_output_risk_level' ); }
		$risk['findings'] = self::items( $risk['findings'], 30 );
		foreach ( $risk['findings'] as &$finding ) {
			$finding = self::object( $finding, array( 'category', 'evidence', 'explanation' ) );
			if ( ! in_array( $finding['category'], self::RISK_CATEGORIES, true ) ) { throw new \InvalidArgumentException( 'ai_output_risk_category' ); }
			$finding['evidence'] = self::evidence( $finding['evidence'], $source ); $finding['explanation'] = self::text( $finding['explanation'], 2000 );
		} unset( $finding );
		$risk['limitations'] = self::texts( $risk['limitations'], 12, 2000 );
		if ( ! $risk['limitations'] || ( in_array( $risk['level'], array( 'medium', 'high' ), true ) && ! $risk['findings'] ) ) { throw new \InvalidArgumentException( 'ai_output_risk_evidence_required' ); }
		$data['risk'] = $risk;
		return $data;
	}

	private static function object( mixed $value, array $keys ): array {
		if ( ! $value instanceof \stdClass ) { throw new \InvalidArgumentException( 'ai_output_object_required' ); }
		$data = get_object_vars( $value );
		if ( array_diff( array_keys( $data ), $keys ) || array_diff( $keys, array_keys( $data ) ) ) { throw new \InvalidArgumentException( 'ai_output_fields' ); }
		return $data;
	}
	private static function text( mixed $value, int $limit, bool $empty = false ): string {
		if ( ! is_string( $value ) || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) || ( ! $empty && '' === trim( $value ) ) || preg_match_all( '/./us', $value ) > $limit ) { throw new \InvalidArgumentException( 'ai_output_invalid_text' ); }
		return $value;
	}
	private static function language( string $value, string $locale ): void {
		if ( ! preg_match( 'zh_CN' === $locale ? '/\p{Han}/u' : '/[A-Za-z]/', $value ) ) { throw new \InvalidArgumentException( 'ai_output_language' ); }
	}
	private static function items( mixed $items, int $limit ): array {
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > $limit ) { throw new \InvalidArgumentException( 'ai_output_list' ); }
		return $items;
	}
	private static function texts( mixed $items, int $limit, int $length ): array {
		return array_map( static fn( mixed $item ): string => self::text( $item, $length ), self::items( $items, $limit ) );
	}
	private static function strings( mixed $source ): array {
		if ( is_string( $source ) ) { return array( $source ); }
		$result = array();
		if ( is_array( $source ) ) { foreach ( $source as $value ) { $result = array_merge( $result, self::strings( $value ) ); } }
		return $result;
	}
	private static function evidence( mixed $quote, array $source ): string {
		$quote = self::text( $quote, 2000 );
		if ( str_contains( $quote, '[REDACTED' ) ) { throw new \InvalidArgumentException( 'ai_output_redacted_evidence' ); }
		// Taxonomy catalogs are options to match, not evidence of Skill functionality/platform support.
		unset( $source['categories'], $source['tags'] );
		foreach ( self::strings( $source ) as $value ) { if ( str_contains( $value, $quote ) ) { return $quote; } }
		throw new \InvalidArgumentException( 'ai_output_unsupported_evidence' );
	}
	private static function installation( mixed $value, array $source ): array {
		$data = self::object( $value, array( 'status', 'steps', 'note' ) );
		if ( ! in_array( $data['status'], array( 'documented', 'needs_verification' ), true ) ) { throw new \InvalidArgumentException( 'ai_output_installation_status' ); }
		$data['note'] = self::text( $data['note'], 2000 ); $data['steps'] = self::items( $data['steps'], 20 );
		if ( 'documented' === $data['status'] && ! $data['steps'] ) { throw new \InvalidArgumentException( 'ai_output_installation_evidence' ); }
		foreach ( $data['steps'] as &$step ) {
			$step = self::object( $step, array( 'text', 'command', 'evidence' ) );
			$step['text'] = self::text( $step['text'], 2000 ); $step['command'] = self::text( $step['command'], 2000, true ); $step['evidence'] = self::evidence( $step['evidence'], $source );
			if ( ! str_contains( $source['raw'], $step['evidence'] ) || ( '' !== $step['command'] && ( ! str_contains( $source['raw'], $step['command'] ) || ! str_contains( $step['evidence'], $step['command'] ) ) ) ) { throw new \InvalidArgumentException( 'ai_output_invented_command' ); }
		} unset( $step );
		return $data;
	}
	private static function suggestions( mixed $value, array $source ): array {
		$data = self::object( $value, array( 'categories', 'tags', 'platforms', 'scenarios' ) );
		foreach ( array( 'categories' => 6, 'tags' => 20 ) as $kind => $max ) {
			$data[ $kind ] = self::items( $data[ $kind ], $max ); $seen = array(); $primary = 0;
			foreach ( $data[ $kind ] as &$item ) {
				$item = self::object( $item, 'categories' === $kind ? array( 'existing_id', 'name', 'role', 'evidence' ) : array( 'existing_id', 'name', 'evidence' ) );
				$item['name'] = self::text( $item['name'], 200 ); $item['evidence'] = self::evidence( $item['evidence'], $source );
				if ( 'categories' === $kind && ( ! in_array( $item['role'], array( 'primary', 'secondary' ), true ) || ( 'primary' === $item['role'] && ++$primary > 1 ) ) ) { throw new \InvalidArgumentException( 'ai_output_category_role' ); }
				if ( null !== $item['existing_id'] ) {
					if ( ! is_int( $item['existing_id'] ) || $item['existing_id'] < 1 ) { throw new \InvalidArgumentException( 'ai_output_term_identity' ); }
					$match = false;
					foreach ( $source[ $kind ] ?? array() as $term ) { if ( is_array( $term ) && (int) ( $term['id'] ?? 0 ) === $item['existing_id'] && ( $term['name'] ?? '' ) === $item['name'] ) { $match = true; break; } }
					if ( ! $match ) { throw new \InvalidArgumentException( 'ai_output_unknown_term' ); }
				} else {
					foreach ( $source[ $kind ] ?? array() as $term ) { if ( is_array( $term ) && strtolower( (string) ( $term['name'] ?? '' ) ) === strtolower( $item['name'] ) ) { throw new \InvalidArgumentException( 'ai_output_duplicate_term' ); } }
				}
				$key = strtolower( $item['name'] );
				if ( isset( $seen[ $key ] ) ) { throw new \InvalidArgumentException( 'ai_output_duplicate_term' ); } $seen[ $key ] = true;
			} unset( $item );
		}
		$data['platforms'] = self::items( $data['platforms'], 12 );
		foreach ( $data['platforms'] as &$item ) {
			$item = self::object( $item, array( 'name', 'evidence' ) ); $item['name'] = self::text( $item['name'], 100 ); $item['evidence'] = self::evidence( $item['evidence'], $source );
			if ( false === stripos( $item['evidence'], $item['name'] ) ) { throw new \InvalidArgumentException( 'ai_output_platform_not_documented' ); }
		} unset( $item );
		$data['scenarios'] = self::texts( $data['scenarios'], 12, 1000 );
		return $data;
	}

	/** json_decode discards duplicate keys; reject them before they can mask a field. */
	private static function duplicates( string $json ): void {
		preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null/s', $json, $matches );
		$stack = array();
		foreach ( $matches[0] as $token ) {
			if ( '{' === $token || '[' === $token ) { $stack[] = array( 'object' => '{' === $token, 'key' => '{' === $token, 'keys' => array() ); continue; }
			if ( '}' === $token || ']' === $token ) { array_pop( $stack ); continue; }
			$index = count( $stack ) - 1; if ( $index < 0 ) { continue; }
			if ( ',' === $token ) { $stack[ $index ]['key'] = $stack[ $index ]['object']; continue; }
			if ( ':' === $token ) { $stack[ $index ]['key'] = false; continue; }
			if ( $stack[ $index ]['key'] && '"' === $token[0] ) {
				$key = json_decode( $token, true, 4, JSON_THROW_ON_ERROR );
				if ( isset( $stack[ $index ]['keys'][ $key ] ) ) { throw new \InvalidArgumentException( 'ai_output_duplicate_key' ); }
				$stack[ $index ]['keys'][ $key ] = true; $stack[ $index ]['key'] = false;
			}
		}
	}

	/** Converts a validated locale to inert HTML. Partial use_cases returns only its section. */
	public static function content( array $locale, string $language ): string {
		if ( ! in_array( $language, array( 'zh_CN', 'en_US' ), true ) ) { throw new \InvalidArgumentException( 'ai_invalid_locale' ); }
		$zh = 'zh_CN' === $language; $html = '';
		$escape = static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		if ( isset( $locale['description'] ) ) { $html .= '<p>' . nl2br( $escape( $locale['description'] ) ) . '</p>'; }
		$labels = $zh ? array( 'key_features' => '核心功能', 'tasks' => '适用任务', 'use_cases' => '使用场景', 'limitations' => '使用限制', 'safety_notes' => '风险提示' ) : array( 'key_features' => 'Key features', 'tasks' => 'Supported tasks', 'use_cases' => 'Use cases', 'limitations' => 'Limitations', 'safety_notes' => 'Safety notes' );
		foreach ( $labels as $field => $label ) {
			if ( ! array_key_exists( $field, $locale ) ) { continue; }
			$html .= '<h2>' . $label . '</h2><ul>'; foreach ( $locale[ $field ] as $item ) { $html .= '<li>' . $escape( $item ) . '</li>'; } $html .= '</ul>';
		}
		if ( isset( $locale['installation'] ) ) {
			$item = $locale['installation']; $html .= '<h2>' . ( $zh ? '安装说明' : 'Installation guide' ) . '</h2><p>' . ( $zh ? 'AI 说明草稿，须人工核实；来源中的步骤未经执行或安全验证。' : 'AI draft for human verification. Source steps have not been executed or certified safe.' ) . '</p><p>' . ( 'documented' === $item['status'] ? ( $zh ? '来源记载了步骤（尚未核实）。' : 'Steps are documented in the source (unverified).' ) : ( $zh ? '安装方式待核实。' : 'Installation requires verification.' ) ) . '</p><p>' . $escape( $item['note'] ) . '</p>';
			foreach ( $item['steps'] as $step ) { $html .= '<p>' . $escape( $step['text'] ) . '</p>'; if ( '' !== $step['command'] ) { $html .= '<pre><code>' . $escape( $step['command'] ) . '</code></pre>'; } $html .= '<blockquote>' . $escape( $step['evidence'] ) . '</blockquote>'; }
		}
		if ( isset( $locale['requirements'] ) ) { $html .= '<h2>' . ( $zh ? '环境和依赖要求' : 'Requirements' ) . '</h2><ul>'; foreach ( $locale['requirements'] as $item ) { $html .= '<li>' . $escape( $item['text'] ) . '<blockquote>' . $escape( $item['evidence'] ) . '</blockquote></li>'; } $html .= '</ul>'; }
		return $html;
	}
}
