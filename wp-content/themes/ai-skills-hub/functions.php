<?php
/** Theme initialization only; feature modules are loaded by Core. */

defined( 'ABSPATH' ) || exit;

require_once get_template_directory() . '/inc/core/bootstrap.php';

function aish_setup() {
	load_theme_textdomain( 'ai-skills-hub', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
}
add_action( 'after_setup_theme', 'aish_setup' );

function aish_enqueue_assets() {
	$directory = get_template_directory() . '/assets/dist/';
	$uri       = get_template_directory_uri() . '/assets/dist/';
	if ( is_file( $directory . 'app.css' ) ) {
		wp_enqueue_style( 'aish-app', $uri . 'app.css', array(), (string) filemtime( $directory . 'app.css' ) );
	}
	if ( is_file( $directory . 'app.js' ) ) {
		wp_enqueue_script( 'aish-app', $uri . 'app.js', array(), (string) filemtime( $directory . 'app.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}
}
add_action( 'wp_enqueue_scripts', 'aish_enqueue_assets' );
