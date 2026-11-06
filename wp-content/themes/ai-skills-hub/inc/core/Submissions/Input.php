<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Submissions;

use AISkillsHub\Core\Community\Input as Text;
use AISkillsHub\Core\Security\Input as CoreInput;
use AISkillsHub\Core\Sources\GitHubClient;

defined( 'ABSPATH' ) || exit;

final class Input {
	public const STATES = array( 'draft', 'submitted', 'in_review', 'needs_changes', 'accepted', 'rejected', 'withdrawn' );
	public const KINDS = array( 'submit', 'update_suggestion' );
	public static function id( mixed $value, bool $zero = false ): int { try { return Text::id( $value, $zero ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_input' ); } }
	public static function choice( mixed $value, array $choices ): string { try { return Text::choice( $value, $choices ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_input' ); } }
	public static function text( mixed $value, int $max, int $min = 0, bool $multiline = true ): string { try { return Text::text( $value, $max, $min, $multiline ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_input' ); } }
	public static function locale( mixed $locale ): string { return self::choice( $locale, array( 'zh_CN', 'en_US' ) ); }
	public static function request( string $request ): void { try { Text::request( $request ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_input' ); } }
	public static function payload( array $input, bool $submit ): array {
		$allowed = array( 'kind', 'locale', 'source_kind', 'github_url', 'source_path', 'name', 'summary', 'categories', 'platforms', 'license', 'notes', 'target_skill_id', 'target_skill_revision', 'commit_sha', 'evidence' );
		if ( array_diff( array_keys( $input ), $allowed ) ) { throw new Exception( 'invalid_input' ); }
		$data = array( 'kind' => self::choice( $input['kind'] ?? null, self::KINDS ), 'locale' => self::locale( $input['locale'] ?? null ), 'source_kind' => self::choice( $input['source_kind'] ?? null, array( 'github', 'manual' ) ) );
		foreach ( array( 'name' => 191, 'summary' => 1200, 'categories' => 500, 'platforms' => 500, 'license' => 100, 'notes' => 6000, 'evidence' => 3000 ) as $key => $max ) {
			$min = $submit && in_array( $key, array( 'name', 'summary', 'notes' ), true ) ? ( 'name' === $key ? 1 : 5 ) : 0;
			$data[ $key ] = self::text( $input[ $key ] ?? '', $max, $min, ! in_array( $key, array( 'name', 'license' ), true ) );
		}
		$rawUrl = $input['github_url'] ?? ''; $path = $input['source_path'] ?? null;
		if ( ! is_string( $rawUrl ) || ( null !== $path && ! is_string( $path ) ) ) { throw new Exception( 'invalid_input' ); }
		$rawUrl = trim( $rawUrl ); $data['github_url'] = ''; $data['source_path'] = null;
		if ( 'github' === $data['source_kind'] ) {
			if ( $rawUrl ) { try { $identity = GitHubClient::repositoryIdentity( $rawUrl ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_source' ); } $data['github_url'] = 'https://github.com/' . $identity['owner'] . '/' . $identity['name']; }
			elseif ( $submit ) { throw new Exception( 'invalid_source' ); }
			if ( null !== $path && '' !== $path ) {
				try { if ( preg_match( '/^[a-z]:/i', $path ) ) { throw new \InvalidArgumentException(); } $data['source_path'] = CoreInput::sourcePath( $path ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_source' ); }
				if ( 'SKILL.md' !== basename( $data['source_path'] ) || ! $data['github_url'] ) { throw new Exception( 'invalid_source' ); }
			}
		} elseif ( '' !== $rawUrl || ( null !== $path && '' !== $path ) ) { throw new Exception( 'invalid_source' ); }
		$data['target_skill_id'] = self::id( $input['target_skill_id'] ?? 0, true );
		$data['target_skill_revision'] = self::id( $input['target_skill_revision'] ?? 0, true );
		$sha = $input['commit_sha'] ?? '';
		if ( ! is_string( $sha ) ) { throw new Exception( 'invalid_input' ); }
		$data['commit_sha'] = '';
		if ( '' !== $sha ) { try { $data['commit_sha'] = GitHubClient::sha( $sha ); } catch ( \Throwable $error ) { throw new Exception( 'invalid_input' ); } }
		if ( 'update_suggestion' === $data['kind'] ) {
			if ( $data['target_skill_id'] < 1 || $data['target_skill_revision'] < 1 ) { throw new Exception( 'invalid_input' ); }
			if ( $submit && ( strlen( $data['evidence'] ) < 5 || ( 'github' === $data['source_kind'] && '' === $data['commit_sha'] ) ) ) { throw new Exception( 'invalid_input' ); }
		} elseif ( $data['target_skill_id'] || $data['target_skill_revision'] || '' !== $data['commit_sha'] ) { throw new Exception( 'invalid_input' ); }
		return $data;
	}
	public static function cap( string $kind ): string { self::choice( $kind, self::KINDS ); return 'submit' === $kind ? 'aish_manage_submissions' : 'aish_manage_update_suggestions'; }
}
