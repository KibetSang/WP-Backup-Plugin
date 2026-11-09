<?php
/**
 * A small stand-in for the parts of WordPress the plugin uses, so the plugin's jobs, destinations and admin endpoints
 * run end to end in PHPUnit without a WordPress install. Options live in a real wp_options table in the test database
 * (so a restore that swaps tables behaves as it does in WordPress); HTTP goes to fake providers (tests set
 * $GLOBALS['bbk_http']); cron, mail and JSON replies are recorded for the tests to inspect.
 *
 * Integration on a real WordPress is still to be done before release (see docs/PERFORMANCE.md).
 */

declare(strict_types=1);

// phpcs:disable

$GLOBALS['bbk_wp_root'] = sys_get_temp_dir() . '/bbk-wp-' . bin2hex( random_bytes( 4 ) );
mkdir( $GLOBALS['bbk_wp_root'] . '/wp-content/plugins', 0777, true );
register_shutdown_function( static function (): void {
	$d = $GLOBALS['bbk_wp_root'];
	if ( is_dir( $d ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $d );
	}
} );

define( 'ABSPATH', $GLOBALS['bbk_wp_root'] . '/' );
define( 'WP_CONTENT_DIR', $GLOBALS['bbk_wp_root'] . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'DB_HOST', (string) ( getenv( 'BBK_TEST_DB_HOST' ) ?: '127.0.0.1:3407' ) );
define( 'DB_USER', (string) ( getenv( 'BBK_TEST_DB_USER' ) ?: 'wp' ) );
define( 'DB_PASSWORD', (string) ( getenv( 'BBK_TEST_DB_PASS' ) ?: 'wp-dev-pass' ) );
define( 'DB_NAME', 'wp' );
define( 'AUTH_KEY', str_repeat( 'a', 64 ) );
define( 'SECURE_AUTH_KEY', str_repeat( 'b', 64 ) );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
$GLOBALS['table_prefix'] = 'wp_';
$GLOBALS['wp_version']   = '7.1';

final class BbkTestJson extends RuntimeException {
	public function __construct( public bool $ok, public mixed $data, public int $status ) {
		parent::__construct( 'json' );
	}
}
final class BbkTestDie extends RuntimeException {
}

class WP_Error {
	public function __construct( private string $code = '', private string $msg = '' ) {
	}
	public function get_error_message(): string {
		return $this->msg;
	}
}
class WP_User {
	public int $ID = 0;
	public string $user_login = '';
	public string $user_pass = '';
	public string $display_name = '';
}
class WP_REST_Request {
	public function __construct( private array $params = array() ) {
	}
	public function get_param( string $k ): mixed {
		return $this->params[ $k ] ?? null;
	}
}
class WP_REST_Response {
	public array $headers = array();
	public function __construct( public mixed $data = null, public int $status = 200 ) {
	}
	public function header( string $k, string $v ): void {
		$this->headers[ $k ] = $v;
	}
}

/** The options table, read and written for real; a cache like WordPress's, emptied by wp_cache_flush(). */
final class bbk_wpdb {
	public string $options = 'wp_options';
	public ?mysqli $db = null;
	public function link(): mysqli {
		if ( ! $this->db ) {
			$this->db = \BastionBackup\Core\Db::connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		}
		return $this->db;
	}
	public function db_version(): string {
		return $this->link()->server_info;
	}
	public function prepare( string $sql, mixed ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . $this->link()->real_escape_string( (string) $v ) . "'";
		}, $sql );
	}
	public function get_var( string $sql ): ?string {
		$r = $this->link()->query( $sql );
		if ( ! $r instanceof mysqli_result ) {
			return null;
		}
		$row = $r->fetch_row();
		return null === $row ? null : ( null === $row[0] ? null : (string) $row[0] );
	}
	public function query( string $sql ): int|bool {
		$r = $this->link()->query( $sql );
		return false === $r ? false : $this->link()->affected_rows;
	}
}
$GLOBALS['wpdb']          = new bbk_wpdb();
$GLOBALS['bbk_opt_cache'] = array();

function bbk_test_reset_options_table(): void {
	$db = $GLOBALS['wpdb']->link();
	$db->query( "CREATE TABLE IF NOT EXISTS wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) DEFAULT CHARSET=utf8mb4" );
	$GLOBALS['bbk_opt_cache'] = array();
}

function get_option( string $name, mixed $default = false ): mixed {
	if ( array_key_exists( $name, $GLOBALS['bbk_opt_cache'] ) ) {
		return $GLOBALS['bbk_opt_cache'][ $name ];
	}
	$db = $GLOBALS['wpdb']->link();
	$r  = $db->query( "SELECT option_value FROM wp_options WHERE option_name = '" . $db->real_escape_string( $name ) . "'" );
	$row = $r instanceof mysqli_result ? $r->fetch_row() : null;
	if ( ! $row ) {
		return $default;
	}
	$v = @unserialize( (string) $row[0], array( 'allowed_classes' => false ) );
	$v = false === $v && 'b:0;' !== $row[0] ? (string) $row[0] : $v;
	$GLOBALS['bbk_opt_cache'][ $name ] = $v;
	return $v;
}
function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
	$db  = $GLOBALS['wpdb']->link();
	$raw = is_array( $value ) || is_object( $value ) || is_bool( $value ) ? serialize( $value ) : (string) $value;
	$db->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" . $db->real_escape_string( $name ) . "', '" . $db->real_escape_string( $raw ) . "', 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );
	$GLOBALS['bbk_opt_cache'][ $name ] = $value;
	do_action( 'update_option_' . $name );
	return true;
}
function add_option( string $name, mixed $value = '', string $dep = '', mixed $autoload = 'yes' ): bool {
	return false === get_option( $name ) ? update_option( $name, $value ) : false;
}
function delete_option( string $name ): bool {
	$db = $GLOBALS['wpdb']->link();
	$db->query( "DELETE FROM wp_options WHERE option_name = '" . $db->real_escape_string( $name ) . "'" );
	unset( $GLOBALS['bbk_opt_cache'][ $name ] );
	return true;
}
function delete_site_option( string $name ): bool {
	return delete_option( $name );
}
function wp_cache_flush(): bool {
	$GLOBALS['bbk_opt_cache'] = array();
	return true;
}
function set_transient( string $k, mixed $v, int $exp = 0 ): bool {
	return update_option( '_transient_' . $k, array( 'v' => $v, 'exp' => $exp ? time() + $exp : 0 ) );
}
function get_transient( string $k ): mixed {
	$o = get_option( '_transient_' . $k, null );
	if ( ! is_array( $o ) || ( $o['exp'] && $o['exp'] < time() ) ) {
		return false;
	}
	return $o['v'];
}
function delete_transient( string $k ): bool {
	return delete_option( '_transient_' . $k );
}

// Hooks.
$GLOBALS['bbk_hooks'] = array();
function add_action( string $h, callable|array|string $cb, int $prio = 10, int $args = 1 ): bool {
	$GLOBALS['bbk_hooks'][ $h ][] = $cb;
	return true;
}
function add_filter( string $h, callable|array|string $cb, int $prio = 10, int $args = 1 ): bool {
	return add_action( $h, $cb, $prio, $args );
}
function do_action( string $h, mixed ...$args ): void {
	foreach ( $GLOBALS['bbk_hooks'][ $h ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}
function apply_filters( string $h, mixed $v, mixed ...$args ): mixed {
	foreach ( $GLOBALS['bbk_hooks'][ $h ] ?? array() as $cb ) {
		$v = $cb( $v, ...$args );
	}
	return $v;
}
function current_action(): string {
	return (string) ( $GLOBALS['bbk_current_action'] ?? '' );
}
function register_activation_hook( string $f, callable|array $cb ): void {
}
function register_deactivation_hook( string $f, callable|array $cb ): void {
}
function register_rest_route( string $ns, string $route, array $args ): bool {
	$GLOBALS['bbk_routes'][ $ns . $route ] = $args;
	return true;
}

// Users.
$GLOBALS['bbk_user'] = new WP_User();
function bbk_test_login( string $login = 'admin', string $password = 'correct-password', int $id = 1 ): void {
	$u               = new WP_User();
	$u->ID           = $id;
	$u->user_login   = $login;
	$u->display_name = ucfirst( $login );
	$u->user_pass    = password_hash( $password, PASSWORD_DEFAULT );
	$GLOBALS['bbk_user']   = $u;
	$GLOBALS['bbk_can']    = true;
	$GLOBALS['bbk_cookie'] = $id;
}
function wp_get_current_user(): WP_User {
	return $GLOBALS['bbk_user'];
}
function get_current_user_id(): int {
	return $GLOBALS['bbk_user']->ID;
}
function wp_set_current_user( int $id ): void {
}
function get_user_by( string $f, mixed $v ): WP_User|false {
	return $GLOBALS['bbk_user']->user_login === $v ? $GLOBALS['bbk_user'] : false;
}
function current_user_can( string $cap ): bool {
	return ! empty( $GLOBALS['bbk_can'] );
}
function user_can( int $id, string $cap ): bool {
	return ! empty( $GLOBALS['bbk_can'] ) && $id === $GLOBALS['bbk_user']->ID;
}
function wp_check_password( string $pw, string $hash, int $id = 0 ): bool {
	return password_verify( $pw, $hash );
}
function wp_validate_auth_cookie( string $c = '', string $scheme = '' ): int|false {
	return $GLOBALS['bbk_cookie'] ?? false;
}
function is_multisite(): bool {
	return false;
}
function is_admin(): bool {
	return false;
}

// Nonces, requests, replies.
function wp_create_nonce( string $a = '' ): string {
	return 'nonce-' . $a;
}
function check_ajax_referer( string $a, string $k = '_ajax_nonce', bool $die = true ): bool {
	return ( $_POST[ $k ] ?? '' ) === 'nonce-' . $a;
}
function check_admin_referer( string $a ): bool {
	return ( $_GET['_wpnonce'] ?? '' ) === 'nonce-' . $a;
}
function wp_nonce_url( string $url, string $a ): string {
	return add_query_arg( '_wpnonce', 'nonce-' . $a, $url );
}
function wp_send_json_success( mixed $d = null, int $s = 200 ): never {
	throw new BbkTestJson( true, $d, $s );
}
function wp_send_json_error( mixed $d = null, int $s = 200 ): never {
	throw new BbkTestJson( false, $d, $s );
}
function wp_die( string $m = '', mixed $t = '', mixed $a = array() ): never {
	throw new BbkTestDie( $m );
}
function wp_unslash( mixed $v ): mixed {
	return $v;
}
function status_header( int $c ): void {
}
function nocache_headers(): void {
}

// Escaping and sanitising.
function __( string $s, string $d = '' ): string {
	return $s;
}
function esc_html( string $s ): string {
	return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( string $s ): string {
	return esc_html( $s );
}
function esc_html__( string $s, string $d = '' ): string {
	return esc_html( $s );
}
function esc_attr__( string $s, string $d = '' ): string {
	return esc_html( $s );
}
function esc_url( string $s ): string {
	return esc_html( $s );
}
function esc_textarea( string $s ): string {
	return esc_html( $s );
}
function wp_kses( string $s, array $a ): string {
	return $s;
}
function wp_kses_post( string $s ): string {
	return $s;
}
function wp_strip_all_tags( string $s ): string {
	return trim( strip_tags( $s ) );
}
function sanitize_text_field( string $s ): string {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $s ) ) );
}
function sanitize_key( string $s ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) );
}
function sanitize_email( string $s ): string {
	return false !== filter_var( trim( $s ), FILTER_VALIDATE_EMAIL ) ? trim( $s ) : '';
}
function is_email( string $s ): string|false {
	return false !== filter_var( $s, FILTER_VALIDATE_EMAIL ) ? $s : false;
}
function sanitize_file_name( string $s ): string {
	return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $s );
}
function checked( mixed $a, mixed $b = true, bool $echo = true ): string {
	return $a == $b ? ' checked' : '';
}
function selected( mixed $a, mixed $b = true, bool $echo = true ): string {
	return $a == $b ? ' selected' : '';
}
function disabled( mixed $a, mixed $b = true, bool $echo = true ): string {
	return $a == $b ? ' disabled' : '';
}

// Paths, URLs, formatting.
function wp_json_encode( mixed $d, int $f = 0 ): string|false {
	return json_encode( $d, $f );
}
function wp_mkdir_p( string $d ): bool {
	return is_dir( $d ) || mkdir( $d, 0777, true );
}
function wp_normalize_path( string $p ): string {
	return (string) preg_replace( '#/+#', '/', str_replace( '\\', '/', $p ) );
}
function trailingslashit( string $s ): string {
	return rtrim( $s, '/\\' ) . '/';
}
function untrailingslashit( string $s ): string {
	return rtrim( $s, '/\\' );
}
function home_url( string $p = '' ): string {
	return rtrim( (string) get_option( 'home', 'https://old.example' ), '/' ) . $p;
}
function site_url( string $p = '' ): string {
	return rtrim( (string) get_option( 'siteurl', 'https://old.example' ), '/' ) . $p;
}
function admin_url( string $p = '' ): string {
	return site_url( '/wp-admin/' . $p );
}
function network_admin_url( string $p = '' ): string {
	return admin_url( $p );
}
function rest_url( string $p = '' ): string {
	return site_url( '/wp-json/' . ltrim( $p, '/' ) );
}
function plugins_url( string $p = '', string $f = '' ): string {
	return site_url( '/wp-content/plugins/bastion-backup/' . $p );
}
function plugin_basename( string $f ): string {
	return 'bastion-backup/bastion-backup.php';
}
function add_query_arg( string|array $k, mixed $v = null, ?string $url = null ): string {
	if ( is_array( $k ) ) {
		$url = (string) $v;
		$q   = $k;
	} else {
		$q = array( $k => $v );
	}
	return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $q );
}
function wp_parse_url( string $u, int $c = -1 ): mixed {
	return parse_url( $u, $c );
}
function size_format( int|float $b, int $d = 0 ): string {
	return $b >= 1048576 ? round( $b / 1048576, 1 ) . ' MB' : round( $b / 1024 ) . ' KB';
}
function number_format_i18n( float|int $n, int $d = 0 ): string {
	return number_format( (float) $n, $d );
}
function human_time_diff( int $from, int $to = 0 ): string {
	return (string) abs( ( $to ?: time() ) - $from ) . ' seconds';
}
function wp_date( string $f, ?int $t = null ): string {
	return gmdate( $f, $t ?? time() );
}
function wp_timezone(): DateTimeZone {
	return new DateTimeZone( 'UTC' );
}
function wp_timezone_string(): string {
	return 'UTC';
}
function wp_raise_memory_limit( string $c = 'admin' ): string|false {
	return false;
}

// Cron and mail: recorded.
$GLOBALS['bbk_cron'] = array();
$GLOBALS['bbk_mail'] = array();
function wp_schedule_single_event( int $t, string $hook, array $args = array() ): bool {
	$GLOBALS['bbk_cron'][] = array( $t, $hook, $args );
	return true;
}
function wp_schedule_event( int $t, string $rec, string $hook, array $args = array() ): bool {
	$GLOBALS['bbk_cron'][] = array( $t, $hook, $args, $rec );
	return true;
}
function wp_next_scheduled( string $hook, array $args = array() ): int|false {
	foreach ( $GLOBALS['bbk_cron'] as $c ) {
		if ( $c[1] === $hook ) {
			return $c[0];
		}
	}
	return false;
}
function wp_clear_scheduled_hook( string $hook, array $args = array() ): int {
	$GLOBALS['bbk_cron'] = array_values( array_filter( $GLOBALS['bbk_cron'], static fn( $c ) => ! ( $c[1] === $hook && ( ! $args || $c[2] === $args ) ) ) );
	return 0;
}
function wp_unschedule_hook( string $hook ): int {
	return wp_clear_scheduled_hook( $hook );
}
function spawn_cron(): void {
}
function wp_mail( string $to, string $subj, string $body ): bool {
	$GLOBALS['bbk_mail'][] = array( $to, $subj, $body );
	return true;
}

// HTTP: to the fake provider the test installs.
function is_wp_error( mixed $v ): bool {
	return $v instanceof WP_Error;
}
function wp_remote_request( string $url, array $args = array() ): array|WP_Error {
	$GLOBALS['bbk_http_log'][] = array( $args['method'] ?? 'GET', $url, $args['headers'] ?? array(), strlen( (string) ( $args['body'] ?? '' ) ) );
	$h = $GLOBALS['bbk_http'] ?? null;
	if ( ! is_callable( $h ) ) {
		return new WP_Error( 'http', 'No network in tests.' );
	}
	return $h( (string) ( $args['method'] ?? 'GET' ), $url, (array) ( $args['headers'] ?? array() ), (string) ( $args['body'] ?? '' ) );
}
function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
	return wp_remote_request( $url, $args + array( 'method' => 'GET' ) );
}
function wp_remote_retrieve_response_code( array $r ): int {
	return (int) $r['response']['code'];
}
function wp_remote_retrieve_headers( array $r ): array {
	return array_change_key_case( (array) ( $r['headers'] ?? array() ) );
}
function wp_remote_retrieve_header( array $r, string $k ): string {
	return (string) ( array_change_key_case( (array) ( $r['headers'] ?? array() ) )[ strtolower( $k ) ] ?? '' );
}
function wp_remote_retrieve_body( array $r ): string {
	return (string) ( $r['body'] ?? '' );
}
/** A reply as wp_remote_request gives it. */
function bbk_reply( int $code, string $body = '', array $headers = array() ): array {
	return array( 'response' => array( 'code' => $code ), 'headers' => $headers, 'body' => $body );
}

// Asset and admin stubs (not exercised).
function add_menu_page( mixed ...$a ): string {
	return '';
}
function wp_enqueue_style( mixed ...$a ): void {
}
function wp_enqueue_script( mixed ...$a ): void {
}
function wp_localize_script( mixed ...$a ): bool {
	return true;
}
function submit_button( mixed ...$a ): void {
}

// The plugin itself.
require dirname( __DIR__, 2 ) . '/bastion-backup.php';
