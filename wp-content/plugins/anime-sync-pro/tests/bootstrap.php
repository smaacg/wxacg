<?php
/**
 * 檔案名稱: tests/bootstrap.php
 * 串流來源純函式測試用的 WordPress 替身
 *
 * 為什麼不用 PHPUnit／wp-env
 * --------------------------
 * 要測的是「字串進、字串出」的解析函式（normalize、work_name、parse_end_date…），
 * 它們不碰資料庫也不發請求。為了它們架一套 WP 測試環境，維護成本遠高於收益，
 * 而且這台開發機沒有 composer 環境。這裡只補這些函式實際會碰到的幾個 WP 函式，
 * 用 `php tests/test-streaming-sources.php` 就能跑。
 *
 * ★ 這些測試存在的理由（2026-09-16）：同一天內我在正式站踩了三次可以被測試擋下的錯——
 *   CLI 閉包裡用 self:: 取常數（fatal）、PHP 字串插值把變數吃掉、解析規則沒有已知答案可對照。
 *
 * @package Anime_Sync_Pro
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "只能在命令列執行\n" );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'ANIME_SYNC_PRO_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

// YT 系來源靠這個常數判斷能不能做存活覆核（provides_alive_check）。
// 測試不對外連線（wp_remote_get 一律回 WP_Error），值是什麼都無所謂。
define( 'SMACG_YT_API_KEY', 'test-key' );

class WP_Error {
	private $c;
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

$GLOBALS['__opt'] = [];
$GLOBALS['__tr']  = [];
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['__tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__tr'][ $k ] ); return true; }
function add_action() {}
function has_action() { return false; }
function wp_next_scheduled() { return false; }
function wp_schedule_event() {}
function wp_unschedule_event() {}
function wp_clear_scheduled_hook() {}
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
/*
 * 照 WP 核心的實作寫，不要隨手 strip_tags() 了事——
 * clean_synopsis() 的行為（含最後的 trim）依賴它，樁不忠實的話
 * 測試會因為錯誤的理由而通過。
 *
 * 注意 trim() 預設只去 ASCII 空白，不會去掉 U+3000 全形空格；
 * 那正是全形空格能一路存進資料庫的原因，也是 clean_synopsis()
 * 要另外處理行首縮排的理由。
 */
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	if ( ! is_scalar( $text ) ) { return ''; }
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) { $text = preg_replace( '/[\r\n\t ]+/', ' ', $text ); }
	return trim( $text );
}
function wp_upload_dir() { return [ 'basedir' => sys_get_temp_dir() . '/asp-tests' ]; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_remote_get( $url, $args = [] ) { return new WP_Error( 'blocked', '測試不對外連線：' . $url ); }
function wp_remote_post( $url, $args = [] ) { return new WP_Error( 'blocked', '測試不對外連線：' . $url ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function current_time( $f ) { return date( $f ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function get_post_meta( $id, $k, $single = false ) { return ''; }
function get_the_title( $id ) { return ''; }
function do_action() {}
function apply_filters( $tag, $value ) { return $value; }

require ANIME_SYNC_PRO_DIR . 'includes/class-youranimes-season-index.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-base.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-bundle-base.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-bangumi-data.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-bahamut.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-myvideo.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-ofiii.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-litv.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-friday.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-linetv.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-hami.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-catchplay.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-garageplay.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-verify-only.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-youtube.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-yt-channels.php';
// 格式登錄表：泡麵番判定規則的單一出處，見 tests/test-format-registry.php
require ANIME_SYNC_PRO_DIR . 'includes/class-format-registry.php';
/*
 * 簡介清理（clean_synopsis）也是純字串函式，見 tests/test-synopsis-clean.php。
 * API handler 的建構子兩個相依都是可選的，會自己退回
 * Rate_Limiter::get_instance() 與 new ID_Mapper()——後者只用到
 * wp_upload_dir() / trailingslashit()，上面都已經樁好，不會連外。
 */
require ANIME_SYNC_PRO_DIR . 'includes/class-rate-limiter.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-id-mapper.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-api-handler.php';
/*
 * 播放清單同步的標題解析（集數抽取、PV 黑名單）同樣是純字串函式，
 * 用同一套替身就能測。見 tests/test-youtube-playlist-sync.php。
 */
require ANIME_SYNC_PRO_DIR . 'includes/class-youtube-playlist-sync.php';

// ── 極簡斷言 ──
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = [];

function t_is( $actual, $expected, string $what ): void {
	if ( $actual === $expected ) {
		$GLOBALS['__pass']++;
		return;
	}
	$GLOBALS['__fail'][] = sprintf( "%s\n      預期：%s\n      實際：%s", $what, var_export( $expected, true ), var_export( $actual, true ) );
}

function t_report(): int {
	printf( "\n通過 %d 項", $GLOBALS['__pass'] );
	if ( empty( $GLOBALS['__fail'] ) ) {
		echo "，全部通過 ✓\n";
		return 0;
	}
	printf( "，失敗 %d 項：\n", count( $GLOBALS['__fail'] ) );
	foreach ( $GLOBALS['__fail'] as $f ) {
		echo "  ✗ ", $f, "\n";
	}
	return 1;
}

/**
 * 把 protected 的解析函式open 出來測。
 * 每個來源一個匿名子類別太囉嗦，用 Closure::bind 直接呼叫。
 */
function t_call( object $obj, string $method, ...$args ) {
	$fn = Closure::bind( function () use ( $method, $args ) {
		return $this->$method( ...$args );
	}, $obj, get_class( $obj ) );
	return $fn();
}
