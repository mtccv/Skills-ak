<?php
declare(strict_types=1);

namespace AISkillsHub\Core;

defined( 'ABSPATH' ) || exit;

/** Explicit module instances only; no paths/classes supplied by requests or remote data. */
final class ModuleLoader {
	public function load( array $modules, Core $core ): array {
		$registry = array();
		foreach ( $modules as $module ) {
			if ( ! $module instanceof Module || isset( $registry[ $module->name() ] ) ) {
				throw new \LogicException( 'Invalid or duplicate Core module.' );
			}
			$registry[ $module->name() ] = $module;
		}
		$visiting = array();
		$ordered  = array();
		$visit    = function ( string $name ) use ( &$visit, &$visiting, &$ordered, $registry ) {
			if ( isset( $ordered[ $name ] ) ) {
				return;
			}
			if ( ! isset( $registry[ $name ] ) || isset( $visiting[ $name ] ) ) {
				throw new \LogicException( 'Missing or cyclic Core module dependency.' );
			}
			$visiting[ $name ] = true;
			foreach ( $registry[ $name ]->dependencies() as $dependency ) {
				$visit( $dependency );
			}
			unset( $visiting[ $name ] );
			$ordered[ $name ] = $registry[ $name ];
		};
		foreach ( array_keys( $registry ) as $name ) {
			$visit( $name );
		}
		// Validate the whole dependency graph before performing any registration.
		foreach ( $ordered as $module ) {
			$module->register( $core );
		}
		return array_keys( $ordered );
	}
}
