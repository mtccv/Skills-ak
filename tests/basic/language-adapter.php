<?php
declare(strict_types=1);

/** Optional language integrations must fail without stopping all Core registration. */
define( 'ABSPATH', '/fixture/' );
$GLOBALS['adapter_filter'] = null;
$GLOBALS['adapter_hooks'] = array();
$GLOBALS['adapter_admin'] = false;
function apply_filters( string $hook, mixed $value ): mixed {
	if ( 'aish_core_language_provider' !== $hook ) { throw new RuntimeException( 'Unexpected filter.' ); }
	return $GLOBALS['adapter_filter'] ? ( $GLOBALS['adapter_filter'] )( $value ) : $value;
}
function add_action( string $hook, callable $callback ): void { $GLOBALS['adapter_hooks'][ $hook ][] = $callback; }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability && $GLOBALS['adapter_admin']; }
function esc_html__( string $value, string $domain ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }

$root = dirname( __DIR__, 2 ) . '/wp-content/themes/ai-skills-hub/inc/core/';
foreach ( array( 'Module.php', 'Core.php', 'Language/LanguageProvider.php', 'Language/BuiltinLanguageProvider.php', 'Modules/LanguageModule.php' ) as $file ) { require $root . $file; }
use AISkillsHub\Core\Core;
use AISkillsHub\Core\Language\BuiltinLanguageProvider;
use AISkillsHub\Core\Language\LanguageProvider;
use AISkillsHub\Core\Modules\LanguageModule;

$assertions = 0;
function check( bool $condition, string $message ): void {
	global $assertions;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$assertions;
}
function registerLanguage( ?callable $filter = null ): Core {
	$GLOBALS['adapter_filter'] = $filter; $GLOBALS['adapter_hooks'] = array();
	$core = new Core(); ( new LanguageModule() )->register( $core );
	// Later modules must remain able to register after the optional integration fails.
	$core->set( 'later_module', new stdClass() );
	return $core;
}
function notices(): string {
	ob_start();
	foreach ( $GLOBALS['adapter_hooks']['admin_notices'] ?? array() as $notice ) { $notice(); }
	return (string) ob_get_clean();
}

$core = registerLanguage();
check( $core->languages() instanceof BuiltinLanguageProvider, 'Default provider remains built in.' );
check( array() === $GLOBALS['adapter_hooks'], 'Healthy startup does not add an error notice.' );
$custom = new class implements LanguageProvider {
	public function supportedLocales(): array { return array( 'zh_CN', 'en_US' ); }
	public function normalize( string $locale ): string { return ( new BuiltinLanguageProvider() )->normalize( $locale ); }
	public function externalObjectId( int $skill_id, string $locale ): ?int { return 9000 + $skill_id; }
};
$core = registerLanguage( static fn() => $custom );
check( $custom === $core->languages(), 'Valid adapter identity/contract is preserved.' );
check( 9007 === $core->languages()->externalObjectId( 7, 'en_US' ), 'Valid external mapping is retained.' );
check( array() === $GLOBALS['adapter_hooks'], 'A valid adapter has no failure warning.' );

foreach ( array(
	static function (): never { throw new RuntimeException( 'fixture-key-do-not-echo <script>secret</script>' ); },
	static function (): never { throw new TypeError( 'fixture-key-do-not-echo' ); },
	static fn() => null,
	static fn() => array( 'zh_CN', 'en_US' ),
	static fn() => new stdClass(),
) as $invalid ) {
	$core = registerLanguage( $invalid );
	check( $core->get( 'later_module' ) instanceof stdClass, 'An invalid optional adapter does not abort module loading.' );
	check( $core->languages() instanceof BuiltinLanguageProvider, 'Broken integration uses existing locale semantics.' );
	check( array( 'zh_CN', 'en_US' ) === $core->languages()->supportedLocales(), 'No invented locales are introduced.' );
	check( 'zh_CN' === $core->languages()->normalize( 'zh-cn' ) && 'en_US' === $core->languages()->normalize( 'en' ), 'Canonical language aliases survive.' );
	check( null === $core->languages()->externalObjectId( 7, 'zh_CN' ), 'Fallback does not invent an external translation relationship.' );
	try { $core->languages()->normalize( 'fr' ); throw new RuntimeException( 'Unsupported language was accepted.' ); }
	catch ( InvalidArgumentException $error ) { ++$assertions; }
	$GLOBALS['adapter_admin'] = false;
	check( '' === notices(), 'Unprivileged users cannot see the operational warning.' );
	$GLOBALS['adapter_admin'] = true; $notice = notices();
	check( str_contains( $notice, 'could not load the language adapter' ), 'Administrators get a useful fixed warning.' );
	check( ! str_contains( $notice, 'fixture-key' ) && ! str_contains( $notice, '<script>' ), 'No exception details or secret-shaped data are rendered.' );
}

echo 'Language adapter contracts: ' . $assertions . " assertions passed (isolated PHP; no WordPress/WPML).\n";
