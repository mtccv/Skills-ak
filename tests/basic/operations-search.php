<?php
declare(strict_types=1);
// Isolated protocol/privacy checks. No WordPress, MySQL, browser or external APIs.
namespace AISkillsHub\Core\Database {
	final class Database { public function prefix(): string { return 'fixture_'; } }
	final class Migrator { public function isCurrent(): bool { return true; } }
}
namespace AISkillsHub\Core\Discovery {
	final class Views { public function analyticsEligible(): bool { return true; } public function analyticsObserver(): ?string { return null; } }
	final class Settings { public static function tracking(): bool { return true; } }
}
namespace AISkillsHub\Core\Frontend {
	final class Router { public static function siteUrl(): string { return 'https://directory.example'; } }
}
namespace {
	if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 );
	function wp_salt( string $name ): string { return 'isolated-test-salt-not-a-production-credential'; }
	function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
	function get_option( string $key, mixed $default = false ): mixed { return $default; }
	function update_option( string $key, mixed $value, bool $autoload = false ): bool { return true; }
	$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Operations/';
	require $root . 'SearchPrivacy.php'; require $root . 'SearchMetrics.php';
	use AISkillsHub\Core\Operations\SearchPrivacy as Privacy;
	use AISkillsHub\Core\Operations\SearchMetrics;
	$count = 0;
	function check( bool $pass, string $message ): void { global $count; ++$count; if ( ! $pass ) { throw new \RuntimeException( $message ); } }
	function reject( callable $callback, string $message ): void { try { $callback(); } catch ( \Throwable $error ) { check( true, $message ); return; } throw new \RuntimeException( $message ); }
	foreach ( array( 'Python', '中文 技能', 'C++', 'C#', 'agent skills', 'PHP 8.4', 'HTTP client' ) as $term ) { check( null !== Privacy::term( $term ), 'safe developer query accepted: ' . $term ); }
	foreach ( array( 'alice@example.test', 'a@b', 'https://example.test', 'www.example.test', '192.168.1.1:8080', 'my api_key foo', 'password 123', 'Bearer secret', 'sk-examplecredential0123456789', 'ghp_examplecredential0123456789', 'github_pat_example', 'AKIAEXAMPLE', '12345678901', '+1 202 555 0123', 'mytoken123', '<script>alert(1)</script>', 'x%40example.com', 'abc/../../etc/passwd', str_repeat( 'z', 30 ), str_repeat( '字', 65 ), array( 'bad' ), null, "bad\nquery", "bad\0query", "\xff" ) as $term ) { check( null === Privacy::term( $term ), 'sensitive/unclassified input suppressed' ); }
	check( 'python skills' === Privacy::term( '  Python   Skills ' ), 'ASCII case/space normalization dedupes same term' );
	foreach ( array( 'resume', 'résumé', '中文技能', str_repeat( '语', 60 ) ) as $term ) {
		$key = Privacy::dimension( $term );
		check( $term === Privacy::displayDimension( $key ), 'qualified dimension round-trips a validated term' );
		check( preg_match_all( '/./us', $key ) <= 191, 'qualified identity + display text fits the unchanged varchar column' );
	}
	check( substr( Privacy::dimension( 'resume' ), 0, 64 ) !== substr( Privacy::dimension( 'résumé' ), 0, 64 ), 'accent-equivalent SQL words have distinct ASCII identities' );
	check( null === Privacy::displayDimension( hash( 'sha256', 'resume' ) . ':résumé' ), 'mismatched stored identity cannot masquerade as another term' );
	check( null === Privacy::displayDimension( 'unqualified legacy text' ), 'plain pre-threshold or malformed dimensions are not presented as qualified terms' );
	$context = array( 'valid' => true, 'failed' => false, 'page' => 1, 'route' => 'search', 'query' => 'Python', 'locale' => 'en_US', 'total' => 0 );
	$data = Privacy::context( $context ); check( true === $data['zero'] && 'python' === $data['term'], 'genuine successful zero result classified' );
	foreach ( array( array( 'valid' => false ), array( 'failed' => true ), array( 'page' => 2 ), array( 'page' => '1' ), array( 'total' => -1 ), array( 'total' => '0' ), array( 'total' => null ), array( 'locale' => 'xx' ), array( 'route' => 'account' ), array( 'query' => '' ), array( 'query' => array() ) ) as $change ) { check( null === Privacy::context( array_replace( $context, $change ) ), 'invalid/failed/nonfirst/nonsearch input cannot get proof' ); }
	check( false === Privacy::context( array_replace( $context, array( 'total' => 3 ) ) )['zero'], 'positive result never counted as zero' );
	$suppressed = Privacy::context( array_replace( $context, array( 'query' => 'user@example.test' ) ) ); check( null === $suppressed['term'] && true === $suppressed['zero'], 'sensitive query contributes only site count' );
	$metrics = new SearchMetrics( new \AISkillsHub\Core\Database\Database(), new \AISkillsHub\Core\Database\Migrator(), new \AISkillsHub\Core\Discovery\Views() );
	$call = static fn( string $method, mixed ...$args ): mixed => ( new \ReflectionMethod( $metrics, $method ) )->invoke( $metrics, ...$args );
	$proof = $call( 'proof', $data ); $decoded = $call( 'decode', $proof ); check( 'python' === $decoded['term'] && true === $decoded['zero'] && 32 === strlen( $decoded['request'] ), 'server-issued proof binds validated term, zero status and unique response' );
	check( $proof !== $call( 'proof', $data ), 'separate successful response gets a distinct idempotency identity' );
	$cleanProof = $call( 'proof', $suppressed ); check( ! str_contains( base64_decode( explode( '.', $cleanProof )[0] ), 'example' ), 'suppressed raw query does not enter proof' );
	$parts = explode( '.', $proof ); reject( fn() => $call( 'decode', $parts[0] . '.' . str_repeat( '0', 64 ) ), 'tampered MAC rejected' ); reject( fn() => $call( 'decode', str_repeat( 'a', 1801 ) ), 'oversized proof rejected' );
	$sign = static function ( array $v ) use ( $call ): string { $body = rtrim( strtr( base64_encode( json_encode( $v ) ), '+/', '-_' ), '=' ); return $body . '.' . $call( 'mac', 'proof:' . $body ); };
	foreach ( array( array( 'issued' => time() - 1900 ), array( 'issued' => time() + 10 ), array( 'locale' => 'xx' ), array( 'zero' => 1 ), array( 'term' => 'secret token' ) ) as $change ) { reject( fn() => $call( 'decode', $sign( array_replace( $decoded, $change ) ) ), 'invalid/expired signed payload rejected' ); }
	foreach ( array( 'https://directory.example', 'https://directory.example:443' ) as $origin ) { $_SERVER['HTTP_ORIGIN'] = $origin; check( true === $call( 'sameOrigin' ), 'same origin allowed' ); }
	foreach ( array( 'http://directory.example', 'https://evil.example', 'https://directory.example:444', 'https://directory.example/path', 'https://alice@directory.example', 'https://directory.example?x=1', '', 'null' ) as $origin ) { $_SERVER['HTTP_ORIGIN'] = $origin; check( false === $call( 'sameOrigin' ), 'foreign/credential/path origin rejected' ); }
	ob_start(); $metrics->render( array_replace( $context, array( 'failed' => true ) ) ); $output = ob_get_clean(); check( '' === $output, 'failed query does not render a countable observation' );
	echo "Operations search privacy/protocol isolated: $count passed; no live service assertions.\n";
}
