<?php
declare(strict_types=1);
/** Isolated policies only: no real WordPress, SQL or mail transport. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['enterprise_exists'] = true; $GLOBALS['options'] = array( 'aish_schema_version' => '1.9.0' ); $GLOBALS['caps'] = array(); $GLOBALS['checks'] = 0; $GLOBALS['hooks'] = array();
function get_option( string $k, mixed $default = false ): mixed { return $GLOBALS['options'][ $k ] ?? $default; }
function current_user_can( string $cap ): bool { return in_array( $cap, $GLOBALS['caps'], true ); }
function wp_strip_all_tags( string $v ): string { return strip_tags( $v ); }
function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_email( string $v ): string { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function is_email( string $v ): bool { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( string $v ): array|false { return parse_url( $v ); }
function esc_url_raw( string $v, array $protocols ): string { return $v; }
function wp_json_encode( mixed $v ): string { return json_encode( $v, JSON_THROW_ON_ERROR ); }
function add_action( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void { $GLOBALS['hooks'][ $hook ][] = $fn; }
function add_filter( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void { add_action( $hook, $fn, $priority, $args ); }
function wp_die( string $message, string $title = '', array $args = array() ): never { throw new RuntimeException( (string) ( $args['response'] ?? 500 ) ); }
final class WP_Error { public function __construct( public string $code, public string $message ) {} }
final class WP_Post { public function __construct( public int $ID ) {} }
final class WP_Query {}
final class wpdb {
	public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta'; public string $last_error = ''; public int $queries = 0; public bool $unavailable = false;
	public function suppress_errors( bool $value ): bool { return false; }
	public function prepare( string $sql, array $values ): string { return json_encode( array( $sql, $values ), JSON_THROW_ON_ERROR ); }
	public function get_var( string $value ): ?int { ++$this->queries; if ( $this->unavailable ) { throw new RuntimeException( 'not stored' ); } if ( str_starts_with( $value, 'SELECT 1 FROM wp_postmeta' ) ) { return $GLOBALS['enterprise_exists'] ? 1 : null; } [ $sql, $v ] = json_decode( $value, true, 16, JSON_THROW_ON_ERROR ); if ( str_contains( $sql, '_aish_enterprise_version' ) ) { return 7 === $v[0] ? 7 : null; } throw new RuntimeException( 'Unexpected scalar query.' ); }
	public function get_results( string $value, string $format ): array { [ $sql, $v ] = json_decode( $value, true, 16, JSON_THROW_ON_ERROR ); return 17 === $v[0] ? array( array( 'post_id' => 7, 'meta_key' => '_aish_sender_email' ) ) : array(); }
}
$base = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Database/Schema.php', 'Database/Database.php', 'Database/Migrator.php', 'Security/Input.php', 'Discovery/Settings.php', 'Frontend/Forms.php', 'Commercial/Enterprise.php', 'Commercial/EnterpriseGuards.php', 'Commercial/EnterpriseFrontend.php' ) as $file ) { require $base . $file; }
use AISkillsHub\Core\Commercial\{Enterprise,EnterpriseGuards,EnterpriseFrontend}; use AISkillsHub\Core\Database\{Database,Migrator}; use AISkillsHub\Core\Frontend\Forms;
function check( bool $ok, string $message ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function reject( callable $fn, string $code = '' ): void { try { $fn(); } catch ( Throwable $e ) { check( '' === $code || $e->getMessage() === $code, 'Expected rejection ' . $code ); return; } throw new RuntimeException( 'Unexpected acceptance.' ); }
$native = new wpdb(); $db = new Database( $native ); $service = new Enterprise( $db, new Migrator( $db ) );
check( false === Enterprise::snapshot()['enabled'] && 0 === Enterprise::snapshot()['revision'], 'Defaults must be disabled and not insert options.' );
check( null === Enterprise::publicCopy( 'en_US' ) && null === Enterprise::publicCopy( 'zh_CN' ), 'No invented service copy.' );
check( 1 === count( $GLOBALS['options'] ), 'Defaults do not create records.' );
$valid = array( 'enabled' => true, 'translations' => array( 'en_US' => array( 'title' => 'Verified service', 'description' => 'Actual description.' ) ) );
$value = Enterprise::validate( $valid ); check( '' === $value['translations']['zh_CN']['title'], 'Missing translation stays missing.' );
$GLOBALS['options'][ Enterprise::OPTION ] = wp_json_encode( array( 'revision' => 1 ) + $value );
check( 'Verified service' === Enterprise::publicCopy( 'en_US' )['title'], 'Configured enabled language is visible.' ); check( null === Enterprise::publicCopy( 'zh_CN' ), 'Missing language has no fake fallback.' );
check( null === Enterprise::publicCopy( '../../' ), 'Invalid language rejected.' );
foreach ( array( array(), array( 'enabled' => '1', 'translations' => array() ), array( 'enabled' => true, 'translations' => array() ), $valid + array( 'price' => 1 ), array( 'enabled' => true, 'translations' => array( 'en_US' => array( 'title' => 'x', 'description' => str_repeat( 'x', 2001 ) ) ) ), array( 'enabled' => false, 'translations' => array( 'fr_FR' => array() ) ) ) as $bad ) { reject( static fn() => Enterprise::validate( $bad ) ); }
$strip = $valid; $strip['translations']['en_US']['title'] = '<b>safe</b>'; check( 'safe' === Enterprise::validate( $strip )['translations']['en_US']['title'], 'Service copy stored as plain text.' );
$GLOBALS['options'][ Enterprise::OPTION ] = '{bad JSON'; check( null === Enterprise::publicCopy( 'en_US' ), 'Corrupt config fails closed without interrupting Contact.' );
foreach ( array( fn() => $service->listing(), fn() => $service->detail( 7 ), fn() => $service->history( 7 ), fn() => $service->moderate( 7, 1, 'closed', '' ), fn() => $service->saveConfig( $valid, 0 ), fn() => $service->countReceived( '2026-01-01 00:00:00', '2026-01-02 00:00:00' ) ) as $operation ) { reject( $operation, 'forbidden' ); }
check( 0 === $native->queries, 'Unauthorized service access cannot read private data.' );
$GLOBALS['caps'] = array( 'aish_manage_settings', 'aish_manage_submissions', 'manage_options' ); reject( fn() => $service->listing(), 'forbidden' );
$data = array( 'name' => 'Person', 'email' => 'person@example.test', 'message' => '<script>bad</script>Actual request', 'website' => '' ); $result = Forms::validateContact( $data ); check( ! str_contains( $result['message'], '<script>' ) && $result['name'] === 'Person', 'Shared Contact validation sanitizes request.' );
foreach ( array( 'email' => 'invalid', 'website' => 'spam', 'name' => array(), 'message' => '', 'github_url' => 'http://127.0.0.1/' ) as $key => $bad ) { $case = $data; $case[ $key ] = $bad; reject( fn() => Forms::validateContact( $case ) ); }
$guard = new EnterpriseGuards( $db );
check( array( 'do_not_allow' ) === $guard->caps( array( 'aish_manage_settings' ), 'edit_post', 1, array( 7 ) ), 'Generic native edit denied.' );
check( array( 'do_not_allow' ) === $guard->caps( array( 'aish_manage_settings' ), 'aish_manage_settings', 1, array( 7 ) ), 'CPT remapped cap cannot bypass object guard.' );
check( array( 'aish_manage_settings' ) === $guard->caps( array( 'aish_manage_settings' ), 'read_post', 1, array( 8 ) ), 'Old Contact remains unchanged.' );
check( array( 'aish_manage_settings' ) === $guard->caps( array( 'aish_manage_settings' ), 'aish_manage_settings', 1, array() ), 'Global capability still works.' );
check( $guard->rejectWrite( false, array( 'ID' => 7 ) ), 'Native rewrite blocked.' );
check( $guard->rejectWrite( false, array( 'post_type' => 'revision', 'post_parent' => 7 ) ), 'Native copied revision blocked.' );
check( $guard->rejectWrite( false, array( 'meta_input' => array( Enterprise::MARKER => '1' ) ) ), 'Marker cannot be forged with native insert.' );
check( false === $guard->meta( null, 8, Enterprise::MARKER, '1' ), 'Marker cannot be added by generic metadata API.' );
check( false === $guard->meta( null, 7, '_aish_sender_email', 'other@example.test' ), 'Email cannot be rewritten outside service.' );
check( null === $guard->meta( null, 8, '_aish_sender_email', 'other@example.test' ), 'Old Contact metadata contract unaffected.' );
check( false === $guard->metaById( null, 17, 'unsafe' ), 'Metadata by ID cannot bypass guard.' );
check( false === $guard->deletePost( null, new WP_Post( 7 ) ), 'Enterprise record not hard deleted from native UI.' );
check( str_contains( $guard->lists( '', new WP_Query() ), 'enterprise_private' ), 'Native lists exclude private subtype.' );
$native->unavailable = true; check( array( 'do_not_allow' ) === $guard->caps( array(), 'read_post', 1, array( 7 ) ), 'Read fails closed on SQL error.' ); $native->unavailable = false;
$guard->register(); reject( fn() => $GLOBALS['hooks']['export_wp'][0]( array( 'content' => 'all' ) ), '403' ); $GLOBALS['hooks']['export_wp'][0]( array( 'content' => 'post' ) ); check( true, 'Separate public post WXR remains available.' ); $GLOBALS['enterprise_exists'] = false; $GLOBALS['hooks']['export_wp'][0]( array( 'content' => 'all' ) ); check( true, 'Default empty enterprise storage does not restrict native export.' ); $GLOBALS['enterprise_exists'] = true;
check( $service->exportPrivacy( 'person@example.test' ) instanceof WP_Error, 'Private export requires native privacy capability.' ); check( $service->erasePrivacy( 'person@example.test' ) instanceof WP_Error, 'Private erase requires native privacy capability.' );
$front = new EnterpriseFrontend( $service, new Forms( $db ) ); $front->register(); $_GET['enterprise'] = 'sent'; $context = array( 'route' => 'contact', 'canonical' => 'https://example.test/en/contact/', 'alternates' => array( 'en_US' => 'x' ), 'seo_alternates' => array( 'en_US' => 'x' ), 'x_default' => 'x' ); $context = $GLOBALS['hooks']['aish_frontend_context'][0]( $context ); check( $context['noindex'] && '' === $context['canonical'] && ! $context['seo_alternates'] && '' === $context['x_default'], 'Result feedback has no index or language/canonical link.' );
printf( "Enterprise isolated policies: %d assertions passed. No real WordPress/MySQL/mail transport.\n", $GLOBALS['checks'] );
