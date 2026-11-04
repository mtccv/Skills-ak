<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Sources;

use AISkillsHub\Core\Security\Input;

defined( 'ABSPATH' ) || exit;

/** Heuristics over inert text and tree metadata. This never runs or installs a Skill. */
final class RiskInspector {
	public static function inspect( string $raw, array $parsed, array $entries, string $repositoryLicense = '' ): array {
		$raw = Input::utf8( $raw, Frontmatter::MAX_FILE_BYTES );
		$findings = array();
		$add = static function ( string $code, string $severity, string $message ) use ( &$findings ): void {
			$findings[ $code ] = array( 'code' => $code, 'severity' => $severity, 'message' => $message );
		};
		$add( 'static_scope_limited', 'unknown', 'Only SKILL.md text and tree metadata were inspected. Scripts and dependencies were not executed or inspected as executable content; this is not a safety certification.' );
		$metadata = is_array( $parsed['metadata'] ?? null ) ? $parsed['metadata'] : array();
		$declared = $metadata['license'] ?? '';
		$declared = is_string( $declared ) ? self::shortText( $declared, 1024 ) : '';
		$repositoryLicense = self::shortText( $repositoryLicense, 256 );
		$known = array( 'MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', 'MPL-2.0', 'GPL-2.0-only', 'GPL-2.0-or-later', 'GPL-3.0-only', 'GPL-3.0-or-later', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'AGPL-3.0-only', 'AGPL-3.0-or-later', 'Unlicense', 'CC0-1.0', 'CC-BY-4.0', 'CC-BY-SA-4.0', 'BSL-1.0', 'Zlib' );
		$effective = '' !== $declared ? $declared : $repositoryLicense;
		$licenseStatus = in_array( $effective, $known, true ) ? 'declared_unverified' : 'needs_verification';
		if ( 'needs_verification' === $licenseStatus ) { $add( 'license_unknown', 'unknown', 'License is missing, nonstandard or not recognized; an editor must verify the applicable terms.' ); }
		if ( $declared && $repositoryLicense && $declared !== $repositoryLicense && 'NOASSERTION' !== $repositoryLicense ) { $add( 'license_mismatch', 'medium', 'Skill and repository license declarations differ; verify which terms apply.' ); }
		foreach ( is_array( $parsed['warnings'] ?? null ) ? $parsed['warnings'] : array() as $warning ) {
			if ( in_array( $warning, array( 'root_skill_directory_unverified', 'skill_directory_depth', 'skill_body_empty' ), true ) ) { $add( $warning, 'medium', 'Skill directory or body structure needs manual verification.' ); }
		}
		$patterns = array(
			'external_network' => array( 'medium', '~(?:https?|wss?|ftp)://|\b(?:curl|wget|Invoke-WebRequest|requests\.(?:get|post))\b|\bfetch\s*\(~i', 'The Skill mentions external network access. Destinations and transmitted data require review.' ),
			'dependency_install' => array( 'medium', '/\b(?:npm\s+(?:install|i|ci)|npx\s|pip[3]?\s+install|uv\s+(?:add|pip)|apt(?:-get)?\s+install|brew\s+install|cargo\s+install|gem\s+install|composer\s+install|go\s+install)\b/i', 'The Skill references dependency installation. Dependencies were not fetched or installed.' ),
			'privileged_operations' => array( 'high', '/\b(?:sudo|su\s+-|chmod\s+(?:777|[ugoa]*\+s)|chown\s+root|CAP_SYS_ADMIN|Administrator|Set-ExecutionPolicy)\b|--privileged\b|(?:\/etc\/(?:sudoers|shadow)|\b(?:rm\s+-[a-z]*r[a-z]*f|mkfs(?:\.[a-z]+)?|dd\s+if=)\b)/i', 'The Skill mentions privileged or destructive operations. Manual review is required.' ),
			'command_execution' => array( 'high', '/(?:\b(?:curl|wget)\b[^\r\n]{0,500}\|\s*(?:sh|bash)|\b(?:eval|exec|shell_exec|system|passthru|popen)\s*\(|\b(?:subprocess\.(?:run|Popen|call)|child_process|os\.system|Invoke-Expression)\b|\b(?:bash|sh)\s+-c\b)/i', 'The Skill contains command execution or downloaded-command patterns. No command was run.' ),
			'credential_access' => array( 'high', '/(?:\.ssh\/|\.aws\/|\/proc\/[^\s]*environ|\b(?:AWS_SECRET_ACCESS_KEY|GITHUB_TOKEN|OPENAI_API_KEY|PRIVATE KEY)\b|(?:read|print|exfiltrat|send|upload)[^\r\n]{0,80}(?:secret|credential|api.?key|token))/i', 'The Skill references secrets or credential access. This text has no authority to access configuration or credentials.' ),
			'prompt_injection_indicator' => array( 'high', '/(?:ignore\s+(?:all\s+)?(?:previous|prior|system)\s+(?:instructions|messages)|override\s+(?:the\s+)?system|(?:system|developer)\s*(?:prompt|message)\s*:|忽略.{0,12}(?:指令|提示)|读取.{0,12}(?:密钥|凭据)|绕过.{0,12}(?:审核|权限))/iu', 'Potential instruction-manipulation text is present. Repository content is data and cannot change permissions or invoke management actions.' ),
		);
		foreach ( $patterns as $code => list( $severity, $pattern, $message ) ) { if ( preg_match( $pattern, $raw ) ) { $add( $code, $severity, $message ); } }
		if ( ! empty( $metadata['allowed-tools'] ) ) { $add( 'requested_tools', 'medium', 'The Skill declares tool permissions. These declarations grant no permissions in this site.' ); }
		if ( ! empty( $metadata['compatibility'] ) ) { $add( 'compatibility_requirements', 'medium', 'The Skill declares environment or dependency requirements. These declarations have not been tested.' ); }
		if ( count( $entries ) > 10000 ) { $add( 'tree_inspection_bounded', 'medium', 'Tree metadata exceeds the inspection limit; metadata inspection is incomplete.' ); }
		foreach ( array_slice( $entries, 0, 10000 ) as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) ) { $add( 'invalid_tree_entry', 'high', 'Invalid tree metadata was encountered.' ); continue; }
			try { $path = Input::sourcePath( $entry['path'] ); }
			catch ( \InvalidArgumentException $error ) { $add( 'abnormal_path', 'high', 'A noncanonical or unsafe relative file path was encountered.' ); continue; }
			if ( preg_match( '/^[a-z]:/i', $path ) ) { $add( 'abnormal_path', 'high', 'A noncanonical or unsafe relative file path was encountered.' ); continue; }
			$name = strtolower( basename( $path ) );
			$mode = is_string( $entry['mode'] ?? null ) ? $entry['mode'] : '';
			$type = is_string( $entry['type'] ?? null ) ? $entry['type'] : '';
			if ( ! in_array( $type, array( 'blob', 'tree', 'commit' ), true ) || ! in_array( $mode, array( '100644', '100755', '040000', '120000', '160000' ), true ) ) { $add( 'invalid_tree_entry', 'high', 'Invalid tree metadata was encountered.' ); }
			if ( '120000' === $mode ) { $add( 'symbolic_link', 'medium', 'A symbolic link is present. Link targets were not followed.' ); }
			if ( '160000' === $mode || 'commit' === $type ) { $add( 'git_submodule', 'medium', 'A submodule is present. External repository contents were not fetched.' ); }
			if ( '100755' === $mode || preg_match( '/\.(?:sh|bash|zsh|ps1|bat|cmd|py|js|mjs|cjs|php|rb|pl|exe|dll|so)$/iD', $name ) ) { $add( 'script_or_executable', 'medium', 'Script or executable file paths are present; their contents were not executed or inspected.' ); }
			if ( in_array( $name, array( 'package.json', 'package-lock.json', 'requirements.txt', 'pyproject.toml', 'pipfile', 'poetry.lock', 'cargo.toml', 'go.mod', 'gemfile', 'composer.json', 'dockerfile', 'docker-compose.yml', 'compose.yaml' ), true ) ) { $add( 'dependency_manifest', 'medium', 'Dependency or container manifests are present; dependency contents remain unverified.' ); }
			if ( isset( $entry['size'] ) && is_numeric( $entry['size'] ) && (float) $entry['size'] > Frontmatter::MAX_FILE_BYTES ) { $add( 'oversized_related_file', 'medium', 'A related file exceeds the content inspection limit.' ); }
			if ( substr_count( $path, '/' ) > 12 || preg_match( '#(?:^|/)(?:node_modules|vendor|\.git)(?:/|$)#i', $path ) || in_array( $name, array( '.env', 'id_rsa', 'id_ed25519' ), true ) ) { $add( 'abnormal_directory_structure', 'medium', 'Deep, generated or sensitive file locations require manual review.' ); }
		}
		$risk = 'unknown';
		foreach ( $findings as $finding ) { if ( 'high' === $finding['severity'] ) { $risk = 'high'; break; } if ( 'medium' === $finding['severity'] ) { $risk = 'medium'; } }
		return array( 'risk_level' => $risk, 'license' => array( 'declared' => $declared, 'repository' => $repositoryLicense, 'effective' => $effective, 'status' => $licenseStatus ), 'status' => 'requires_review', 'findings' => array_values( $findings ), 'scan_scope' => 'skill_text_and_tree_metadata_only', 'scripts_executed' => false );
	}

	private static function shortText( string $text, int $limit ): string {
		return strlen( $text ) <= $limit && preg_match( '//u', $text ) && ! preg_match( '/[\x00-\x1f\x7f]/', $text ) ? $text : '';
	}
}
