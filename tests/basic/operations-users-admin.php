<?php
declare(strict_types=1);

/** Isolated UI authorization/CSRF boundaries; service and WordPress are substitutes. */
namespace AISkillsHub\Core\Operations {
	final class Users {
		public const CAPABILITY = 'aish_manage_accounts';
		public array $writes = array(); public int $reads = 0;
		public function setState( int $id, string $action, int $revision, string $reason, string $recovery, string $key ): void { $this->writes[] = func_get_args(); }
		public function summaries( array $ids ): array { ++$this->reads; return array( 7 => array( 'id' => 7, 'verified' => false, 'registered_at' => '<script>unsafe</script>', 'state' => 'pending_email' ) ); }
		public function erase( bool $done, int $id ): bool { return $done; }
	}
}
namespace {
	define( 'ABSPATH', '/isolated-wordpress/' );
	$GLOBALS['authorized'] = false; $GLOBALS['locale'] = 'en_US'; $GLOBALS['hooks'] = array();
	function get_current_user_id(): int { return 19; }
	function current_user_can( string $cap ): bool { return $GLOBALS['authorized'] && 'aish_manage_accounts' === $cap; }
	function get_user_locale(): string { return $GLOBALS['locale']; }
	function wp_die( string $message, string $title, array $data ): never { throw new \RuntimeException( 'http_' . $data['response'] ); }
	function wp_unslash( string $text ): string { return stripslashes( $text ); }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'safe-7' === $nonce && 'aish_operate_user_7' === $action; }
	function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_url( string $value ): string { return esc_html( $value ); }
	function esc_attr( string $value ): string { return esc_html( $value ); }
	function admin_url( string $path ): string { return 'https://example.test/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function set_transient( string $key, mixed $value, int $seconds ): bool { $GLOBALS['notice'] = $value; return true; }
	function add_action( string $name, callable $callback ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
	function add_filter( string $name, callable $callback, int $priority = 10, int $args = 1 ): void { $GLOBALS['hooks'][ $name ][] = $callback; }
	final class Redirect extends \RuntimeException { public function __construct( public string $url, public int $status ) { parent::__construct( 'redirect' ); } }
	function wp_safe_redirect( string $url, int $status ): never { throw new Redirect( $url, $status ); }
	require dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/Operations/UsersAdmin.php';
	use AISkillsHub\Core\Operations\{Users,UsersAdmin};
	$service = new Users(); $admin = new UsersAdmin( $service ); $checks = 0;
	function check( bool $value, string $message ): void { global $checks; if ( ! $value ) { throw new \RuntimeException( $message ); } ++$checks; }
	function handle( UsersAdmin $admin ): Redirect { try { $admin->handle(); } catch ( Redirect $redirect ) { return $redirect; } throw new \RuntimeException( 'Expected redirect.' ); }
	check( array( 'name' => 'Name' ) === $admin->columns( array( 'name' => 'Name' ) ), 'Unprivileged native users view gets no private operational columns.' );
	check( '' === $admin->column( '', 'aish_state', 7 ) && 0 === $service->reads, 'Unprivileged column requests never query another user.' );
	try { $admin->screen(); throw new \RuntimeException( 'Expected forbidden.' ); } catch ( \RuntimeException $error ) { check( 'http_403' === $error->getMessage(), 'Direct user operations screen requires explicit capability.' ); }
	$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( 'user_id' => '7', 'operation' => 'suspend', 'revision' => '2', 'reason' => 'Reviewed abuse', 'recovery_condition' => 'Appeal reviewed', 'request_key' => str_repeat( 'a', 32 ), '_wpnonce' => 'safe-7', 'confirmed' => '1' );
	try { $admin->handle(); throw new \RuntimeException( 'Expected forbidden.' ); } catch ( \RuntimeException $error ) { check( 'http_403' === $error->getMessage() && ! $service->writes, 'Direct POST requires explicit capability before writing.' ); }
	$GLOBALS['authorized'] = true; $redirect = handle( $admin );
	check( array( 7, 'suspend', 2, 'Reviewed abuse', 'Appeal reviewed', str_repeat( 'a', 32 ) ) === $service->writes[0], 'Validated POST delegates exact target, optimistic revision and idempotency key to business service.' );
	check( 303 === $redirect->status && 'success' === $GLOBALS['notice'] && str_contains( $redirect->url, 'user_id=7' ), 'Successful processing uses private actor notice and safe local redirect.' );
	$base = $_POST;
	foreach ( array( array( '_wpnonce' => 'invalid' ), array( 'user_id' => '8' ), array( 'confirmed' => '0' ), array( 'user_id' => array( '7' ) ), array( 'revision' => '2e2' ), array( 'reason' => array( 'untrusted' ) ) ) as $input ) {
		$_POST = array_replace( $base, $input ); handle( $admin ); check( 1 === count( $service->writes ) && 'failed' === $GLOBALS['notice'], 'Invalid nonce, target binding, confirmation and scalar fields cannot execute a transition.' );
	}
	$_POST = $base; $_SERVER['REQUEST_METHOD'] = 'GET'; handle( $admin ); check( 1 === count( $service->writes ), 'GET cannot perform account mutation.' );
	check( 4 === count( $admin->columns( array( 'name' => 'Name' ) ) ), 'Authorized native list receives all three operational columns.' );
	$GLOBALS['wp_list_table'] = (object) array( 'items' => array( (object) array( 'ID' => 7 ) ) );
	$html = $admin->column( '', 'aish_registered', 7 ); $admin->column( '', 'aish_state', 7 );
	check( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ), 'Native column values are escaped.' );
	check( 1 === $service->reads, 'Native list primes one bounded summary batch across columns.' );
	$admin->register(); check( isset( $GLOBALS['hooks']['admin_post_aish_operate_user'], $GLOBALS['hooks']['aish_account_privacy_erase_batch'] ), 'Private POST and existing privacy erasure are registered without new REST/AJAX/download endpoints.' );
	echo json_encode( array( 'suite' => 'operations-users-admin', 'kind' => 'isolated UI / substituted domain and WordPress', 'checks' => $checks, 'status' => 'passed' ), JSON_PRETTY_PRINT ) . PHP_EOL;
}
