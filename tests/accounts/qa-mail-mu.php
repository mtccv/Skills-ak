<?php
declare(strict_types=1);

/** Isolated QA fixture. Never install on a business or production site. */
if ( ! defined( 'DB_NAME' ) || ! in_array( DB_NAME, array( 'qa_browser', 'qa_php84' ), true ) || ! is_file( '/var/www/aish-phase8-key.json' ) ) { return; }
$aish_qa_key = json_decode( (string) file_get_contents( '/var/www/aish-phase8-key.json' ), true );
define( 'AISH_ACCOUNT_MAIL_KEY_VERSION', 'qa1' );
define( 'AISH_ACCOUNT_MAIL_KEYS', array( 'qa1' => $aish_qa_key['key'] ) );
define( 'AISH_ACCOUNT_MAIL_ENABLED', true );
unset( $aish_qa_key );
// No real transport calls. Bodies remain request-local memory only for assertions.
add_filter( 'pre_wp_mail', static function ( mixed $pre, array $atts ): bool {
	$GLOBALS['aish_phase8_qa_mails'][] = $atts;
	if ( ( $GLOBALS['aish_phase8_qa_transport'] ?? 'accept' ) === 'throw' ) { throw new RuntimeException( 'Synthetic transport failure.' ); }
	return ! in_array( $GLOBALS['aish_phase8_qa_transport'] ?? 'accept', array( 'reject', 'ambiguous_false' ), true );
}, PHP_INT_MAX, 2 );
