<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Contributions;

use AISkillsHub\Core\Frontend\Router;

defined( 'ABSPATH' ) || exit;

/** Shared presentation-boundary helpers; domain services always recheck authorization. */
final class Ui {
	public static function integer( mixed $value, int $min = 1, int $max = PHP_INT_MAX ): int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$result = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $min, 'max_range' => $max ) ) );
		if ( false === $result ) { throw new \InvalidArgumentException( 'invalid_input' ); } return $result;
	}
	public static function text( mixed $value, int $max ): string {
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		$value = wp_unslash( $value );
		if ( strlen( $value ) > $max || str_contains( $value, "\0" ) || ! preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'invalid_input' ); }
		return $value;
	}
	public static function input( string $key, int $max = 20000 ): string { return self::text( $_POST[ $key ] ?? '', $max ); }
	public static function path(): ?string {
		$uri = $_SERVER['REQUEST_URI'] ?? ''; $path = is_string( $uri ) && strlen( $uri ) <= 4096 ? wp_parse_url( $uri, PHP_URL_PATH ) : null;
		$base = rtrim( (string) wp_parse_url( Router::siteUrl(), PHP_URL_PATH ), '/' );
		if ( ! is_string( $path ) || ( '' !== $base && ! str_starts_with( $path, $base . '/' ) ) ) { return null; }
		return preg_replace( '#^index\.php(?:/|$)#', '', trim( substr( $path, strlen( $base ) ), '/' ) );
	}
	public static function privateHeaders(): void {
		nocache_headers(); header( 'Cache-Control: private, no-store, max-age=0' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Frame-Options: SAMEORIGIN' );
		foreach ( array( 'rel_canonical', 'wp_shortlink_wp_head', 'rest_output_link_wp_head', 'wp_oembed_add_discovery_links' ) as $callback ) { remove_action( 'wp_head', $callback ); }
		remove_all_actions( 'rank_math/head' ); add_action( 'wp_head', '_wp_render_title_tag', 1 );
	}
	public static function fields( string $scope, string $action, bool $retry = false ): string {
		$key = bin2hex( random_bytes( 16 ) );
		if ( $retry && ( $_POST['contribution_action'] ?? '' ) === $action && is_string( $_POST['request_key'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $_POST['request_key'] ) ) { $key = $_POST['request_key']; }
		$nonce = str_replace( ' id="_aish_nonce"', ' id="aish-' . esc_attr( $scope . '-' . $action . '-' . $key ) . '"', wp_nonce_field( 'aish_' . $scope . '_' . $action, '_aish_nonce', false, false ) );
		return '<input type="hidden" name="contribution_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="request_key" value="' . esc_attr( $key ) . '">' . $nonce;
	}
	public static function csrf( string $scope, string $action ): void {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ( ! is_string( $origin ) || ( ! in_array( $origin, array( '', 'null' ), true ) && ! self::sameOrigin( $origin, Router::siteUrl() ) ) || ! wp_verify_nonce( self::input( '_aish_nonce', 64 ), 'aish_' . $scope . '_' . $action ) ) { throw new \RuntimeException( 'csrf' ); }
	}
	private static function sameOrigin( string $origin, string $site ): bool {
		$a = wp_parse_url( $origin ); $b = wp_parse_url( $site );
		return is_array( $a ) && is_array( $b ) && ! isset( $a['user'] ) && ! isset( $a['pass'] ) && strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && (int) ( $a['port'] ?? ( 'https' === ( $a['scheme'] ?? '' ) ? 443 : 80 ) ) === (int) ( $b['port'] ?? ( 'https' === ( $b['scheme'] ?? '' ) ? 443 : 80 ) );
	}
	private static function cookieName( string $scope ): string { return ( is_ssl() ? '__Host-' : '' ) . 'aish_' . $scope . '_flash'; }
	public static function flash( string $scope, string $code ): void {
		$data = base64_encode( (string) wp_json_encode( array( 'code' => $code, 'user' => get_current_user_id(), 'expires' => time() + 120 ) ) );
		setcookie( self::cookieName( $scope ), $data . '.' . hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) ), array( 'expires' => time() + 120, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
	}
	public static function readFlash( string $scope, array $codes ): string {
		$cookie = $_COOKIE[ self::cookieName( $scope ) ] ?? '';
		setcookie( self::cookieName( $scope ), '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 512 || 1 !== substr_count( $cookie, '.' ) ) { return ''; }
		[ $data, $signature ] = explode( '.', $cookie, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) ), $signature ) ) { return ''; }
		$value = json_decode( (string) base64_decode( $data, true ), true );
		return is_array( $value ) && ( $value['user'] ?? null ) === get_current_user_id() && is_int( $value['expires'] ?? null ) && $value['expires'] >= time() && in_array( $value['code'] ?? '', $codes, true ) ? $value['code'] : '';
	}
	public static function errorStatus( string $reason ): int {
		return match ( $reason ) { 'csrf', 'forbidden', 'unverified' => 403, 'unavailable', 'not_found' => 404, 'conflict', 'duplicate', 'duplicate_source', 'source_exists', 'handle_conflict', 'handle_taken', 'claim_exists', 'claim_conflict', 'expired_request', 'invalid_state', 'source_changed' => 409, 'rate_limited' => 429, 'storage_unavailable', 'busy' => 503, default => 422 };
	}
	public static function field( string $name, string $label, string $value, int $max, int $rows = 0, string $help = '', bool $required = false ): void {
		$id = 'contribution-' . $name; $attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . esc_attr( (string) $max ) . '"' . ( $required ? ' required' : '' ) . ( $help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '' );
		echo '<div class="account-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( $rows ) { echo '<textarea' . $attrs . ' rows="' . esc_attr( (string) $rows ) . '">' . esc_textarea( $value ) . '</textarea>'; }
		else { echo '<input type="text"' . $attrs . ' value="' . esc_attr( $value ) . '">'; }
		if ( $help ) { echo '<p id="' . esc_attr( $id . '-help' ) . '" class="account-help">' . esc_html( $help ) . '</p>'; } echo '</div>';
	}
	public static function pagination( array $listing, callable $url, string $route, array $params, callable $t ): void {
		if ( $listing['total'] <= $listing['per_page'] && $listing['page'] <= 1 ) { return; }
		echo '<nav class="account-pagination" aria-label="' . esc_attr( $t( 'pagination' ) ) . '">';
		if ( $listing['page'] > 1 ) { echo '<a class="account-button account-button-secondary" href="' . esc_url( $url( $route, array_merge( $params, array( 'page' => $listing['page'] - 1 ) ) ) ) . '">' . esc_html( $t( 'previous' ) ) . '</a>'; }
		echo '<span>' . esc_html( sprintf( $t( 'page' ), $listing['page'], $listing['total'] ) ) . '</span>';
		if ( $listing['page'] * $listing['per_page'] < $listing['total'] ) { echo '<a class="account-button account-button-secondary" href="' . esc_url( $url( $route, array_merge( $params, array( 'page' => $listing['page'] + 1 ) ) ) ) . '">' . esc_html( $t( 'next' ) ) . '</a>'; } echo '</nav>';
	}
}
