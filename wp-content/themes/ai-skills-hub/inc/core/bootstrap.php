<?php
declare(strict_types=1);
/** Built-in Core entry point. Business logic is loaded from explicit modules. */

namespace AISkillsHub\Core;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.14.0';

spl_autoload_register( static function ( string $class ): void {
	$prefix = __NAMESPACE__ . '\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
		return;
	}
	$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

Core::boot();
