<?php
declare(strict_types=1);

/** Runs the real exit-based controller in isolated PHP children, never WordPress or MySQL. */
namespace AISkillsHub\Core\Admin {
	final class Management {}
}

namespace {
	define( 'ABSPATH', '/unused/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	final class SettingsDatabaseStub {
		public string $options = 'wp_options';
		public string $last_error = '';
		public bool $suppressed = false;
		public int $reads = 0;
		public function suppress_errors( bool $value ): bool { $previous = $this->suppressed; $this->suppressed = $value; return $previous; }
		public function prepare( string $sql, string $key ): string {
			if ( 'aish_admin_settings' !== $key || ! str_contains( $sql, 'WHERE option_name=%s' ) ) { throw new \RuntimeException( 'Unexpected settings query.' ); }
			return str_replace( '%s', "'aish_admin_settings'", $sql );
		}
		public function get_var( string $sql ): ?string {
			++$this->reads;
			$this->last_error = 'read_failure' === $GLOBALS['scenario'] ? 'private SQL diagnostic' : '';
			return 'read_failure' === $GLOBALS['scenario'] ? null : $GLOBALS['stored'];
		}
	}
	function current_user_can( string $cap ): bool { return in_array( $cap, $GLOBALS['caps'], true ); }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'fixture' === $nonce && 'aish_settings' === $action; }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function __( string $value, string $domain = '' ): string { return $value; }
	function esc_html__( string $value, string $domain = '' ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function get_current_user_id(): int { return 7; }
	function delete_transient( string $key ): bool { unset( $GLOBALS['transients'][ $key ] ); return true; }
	function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['transients'][ $key ] = $value; return true; }
	function maybe_serialize( mixed $value ): string { return serialize( $value ); }
	function update_option( string $key, mixed $value, bool $autoload ): bool {
		if ( 'aish_admin_settings' !== $key || $autoload ) { throw new \RuntimeException( 'Unexpected settings option.' ); }
		++$GLOBALS['writes'];
		if ( 'write_failure' === $GLOBALS['scenario'] ) {
			$GLOBALS['wpdb']->last_error = 'private SQL diagnostic';
			if ( ! $GLOBALS['wpdb']->suppressed ) { echo 'private SQL diagnostic'; }
			return false;
		}
		if ( 'cache_shadow' === $GLOBALS['scenario'] ) { $GLOBALS['cached'] = $value; return false; }
		if ( 'malformed_storage' === $GLOBALS['scenario'] ) { $GLOBALS['stored'] = 'corrupt option bytes'; return true; }
		$serialized = maybe_serialize( $value );
		if ( $serialized === $GLOBALS['stored'] ) { return false; }
		$GLOBALS['stored'] = $serialized;
		return true;
	}
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['cached']; }
	function wp_cache_delete( string $key, string $group ): bool { $GLOBALS['invalidated'][] = $group . ':' . $key; return true; }
	function admin_url( string $path ): string { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function wp_safe_redirect( string $url, int $status ): bool { $GLOBALS['redirect'] = $url; $GLOBALS['http_status'] = $status; return true; }
	function wp_die( string $message, string $title = '', array $args = array() ): never { $GLOBALS['http_status'] = $args['response'] ?? 500; $GLOBALS['denied'] = $message; exit; }

	if ( '--case' === ( $argv[1] ?? '' ) ) {
		$GLOBALS['scenario'] = $argv[2] ?? '';
		$GLOBALS['wpdb'] = new SettingsDatabaseStub();
		$GLOBALS['stored'] = maybe_serialize( 'unchanged' === $GLOBALS['scenario'] ? array( 'per_page' => 50, 'default_locale' => 'en_US' ) : array( 'per_page' => 20, 'default_locale' => 'zh_CN' ) );
		$GLOBALS['cached'] = array( 'per_page' => 20, 'default_locale' => 'zh_CN' );
		$GLOBALS['writes'] = 0; $GLOBALS['invalidated'] = array(); $GLOBALS['transients'] = array();
		$GLOBALS['caps'] = 'no_capability' === $GLOBALS['scenario'] ? array( 'read' ) : array( 'aish_manage_settings' );
		if ( 'administrator' === $GLOBALS['scenario'] ) { $GLOBALS['caps'] = array( 'manage_options' ); }
		$_SERVER['REQUEST_METHOD'] = 'wrong_method' === $GLOBALS['scenario'] ? 'GET' : 'POST';
		$_POST = array( '_wpnonce' => 'bad_nonce' === $GLOBALS['scenario'] ? 'invalid' : 'fixture', 'per_page' => 'invalid_limit' === $GLOBALS['scenario'] ? '101' : '50', 'default_locale' => 'invalid_locale' === $GLOBALS['scenario'] ? 'fr_FR' : 'en_US' );
		register_shutdown_function( static function (): void {
			echo json_encode( array( 'stored' => $GLOBALS['stored'], 'redirect' => $GLOBALS['redirect'] ?? '', 'status' => $GLOBALS['http_status'] ?? 0, 'writes' => $GLOBALS['writes'], 'reads' => $GLOBALS['wpdb']->reads, 'suppressed' => $GLOBALS['wpdb']->suppressed, 'invalidated' => $GLOBALS['invalidated'], 'messages' => $GLOBALS['transients'] ), JSON_THROW_ON_ERROR ), "\n";
		} );
		require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Admin/Actions.php';
		$actions = new \AISkillsHub\Core\Admin\Actions( new \AISkillsHub\Core\Admin\Management() );
		( new \ReflectionMethod( $actions, 'dispatch' ) )->invoke( $actions, 'aish_settings' );
		exit;
	}

	$count = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$count ): void { if ( ! $condition ) { throw new \RuntimeException( $message ); } ++$count; };
	$run = static function ( string $scenario ) use ( $assert ): array {
		$process = proc_open( array( PHP_BINARY, __FILE__, '--case', $scenario ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new \RuntimeException( 'Cannot run isolated controller child.' ); }
		fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		$assert( 0 === proc_close( $process ) && '' === $errors, $scenario . ' child must complete without PHP diagnostics.' );
		$assert( ! str_contains( $output, 'private SQL diagnostic' ), $scenario . ' must not disclose storage diagnostics.' );
		return json_decode( $output, true, 32, JSON_THROW_ON_ERROR );
	};
	foreach ( array( 'success', 'unchanged', 'administrator' ) as $scenario ) {
		$result = $run( $scenario );
		$assert( str_contains( $result['redirect'], 'aish_notice=saved' ) && 303 === $result['status'], $scenario . ' must show saved.' );
		$assert( $result['stored'] === maybe_serialize( array( 'per_page' => 50, 'default_locale' => 'en_US' ) ) && 1 === $result['reads'], $scenario . ' must verify durable values, including unchanged false.' );
		$assert( ! $result['suppressed'], 'Restore error display state.' );
	}
	foreach ( array( 'write_failure', 'cache_shadow', 'read_failure', 'malformed_storage' ) as $scenario ) {
		$result = $run( $scenario );
		$assert( str_contains( $result['redirect'], 'aish_notice=failed' ) && 303 === $result['status'], $scenario . ' must not show saved.' );
		$assert( ! empty( $result['messages']['aish_failure_7'] ) && ! $result['suppressed'], $scenario . ' must use generic feedback and restore error state.' );
		$assert( $result['invalidated'] === array( 'options:aish_admin_settings', 'options:alloptions', 'options:notoptions' ), $scenario . ' must invalidate only option caches.' );
		if ( in_array( $scenario, array( 'write_failure', 'cache_shadow' ), true ) ) { $assert( $result['stored'] === maybe_serialize( array( 'per_page' => 20, 'default_locale' => 'zh_CN' ) ), 'Failed writes must preserve previous settings.' ); }
	}
	foreach ( array( 'wrong_method' => 405, 'bad_nonce' => 403, 'no_capability' => 403 ) as $scenario => $status ) {
		$result = $run( $scenario );
		$assert( $status === $result['status'] && 0 === $result['writes'] && 0 === $result['reads'], $scenario . ' must stop before storage.' );
	}
	foreach ( array( 'invalid_limit', 'invalid_locale' ) as $scenario ) {
		$result = $run( $scenario );
		$assert( str_contains( $result['redirect'], 'aish_notice=failed' ) && 0 === $result['writes'], $scenario . ' must not write invalid settings.' );
	}
	echo "Admin settings: $count isolated assertions passed (WordPress/SQL stubs; no real integration).\n";
}
