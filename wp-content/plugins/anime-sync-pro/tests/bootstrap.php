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
function add_filter() {}
function has_action() { return false; }
function wp_next_scheduled() { return false; }

/*
 * 排程替身記錄呼叫內容，而不是空函式。
 * 「本機不排程」這種行為，空函式只能證明它沒爆炸，證明不了它到底有沒有被呼叫——
 * 那樣的測試會因為錯誤的理由而通過。
 */
$GLOBALS['__sched']   = [];
$GLOBALS['__unsched'] = [];
function wp_schedule_event( $ts = 0, $recurrence = '', $hook = '' ) { $GLOBALS['__sched'][] = $hook; return true; }
function wp_unschedule_event() {}
function wp_clear_scheduled_hook( $hook = '' ) { $GLOBALS['__unsched'][] = $hook; }

/* 環境類型：預設 production，測試需要時改 $GLOBALS['__env'] 切成 'local' */
$GLOBALS['__env'] = 'production';
function wp_get_environment_type() { return $GLOBALS['__env']; }

/*
 * 站台網址：預設正式站，測試改 $GLOBALS['__home'] 模擬別人 clone 下去的環境。
 * is_production_site() 靠它判斷，沒有替身的話測試會直接 fatal。
 */
$GLOBALS['__home'] = 'https://weixiaoacg.com';
function home_url( $path = '' ) { return $GLOBALS['__home'] . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
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
/*
 * postmeta 記憶體替身。
 *
 * 原本 get_post_meta() 一律回空字串——那對純字串解析的測試夠用，但要測
 * 「回填模式不覆寫既有值」就不行了：樁永遠說「欄位是空的」，於是不論程式
 * 有沒有那道保護，測試都會通過。測試必須看得見寫進去的東西。
 */
$GLOBALS['__meta'] = [];
function get_post_meta( $id, $k, $single = false ) {
	$v = $GLOBALS['__meta'][ $id ][ $k ] ?? '';
	return $single ? $v : ( $v === '' ? [] : [ $v ] );
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['__meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['__meta'][ $id ][ $k ] ); return true; }
function wp_schedule_single_event( $ts = 0, $hook = '', $args = [] ) { return true; }
function t_meta_reset(): void { $GLOBALS['__meta'] = []; }
function get_the_title( $id ) { return ''; }
function do_action() {}
function apply_filters( $tag, $value ) { return $value; }

/*
 * 極簡 $wpdb 替身：只夠 check_gone() 撈「帶來源標記的作品 ID」。
 * 直接查上面那個 postmeta 替身，不連資料庫——下架偵測是會刪正式站資料的路徑，
 * 只驗程式碼字串擋不住行為回歸，測試必須真的跑得動它。
 */
class T_Fake_WPDB {
	public $postmeta = 'wp_postmeta';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $v ) {
			$sql = preg_replace( '/%s/', "'" . $v . "'", $sql, 1 );
		}
		return $sql;
	}
	/** @return string[] */
	public function get_col( $sql ) {
		if ( ! preg_match( "/meta_key = '([^']+)'/", (string) $sql, $m ) ) {
			return [];
		}
		$out = [];
		foreach ( $GLOBALS['__meta'] as $id => $kv ) {
			if ( isset( $kv[ $m[1] ] ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}
}
$GLOBALS['wpdb'] = new T_Fake_WPDB();

/** 把索引直接塞進來源物件（private $index），免得測試去動硬碟上的索引檔。 */
function t_set_index( object $src, array $index ): void {
	$p = new ReflectionProperty( Anime_Sync_Streaming_Source_Base::class, 'index' );
	$p->setAccessible( true );
	$p->setValue( $src, $index );
	$u = new ReflectionProperty( Anime_Sync_Streaming_Source_Base::class, 'index_urls' );
	$u->setAccessible( true );
	$u->setValue( $src, null );
}

require ANIME_SYNC_PRO_DIR . 'includes/class-youranimes-season-index.php';
// 平台 key → 標籤／計費的單一出處；公視+ 的標籤有測試盯著，見 test-streaming-sources.php
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-registry.php';
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
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-ptsplus.php';
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
/*
 * YourAnimes 抓取器：測「回填模式只補空白、不覆寫」那道保護，
 * 見 tests/test-youranimes-fill-only.php。相依都可控——Error_Logger 有
 * class_exists 守門會退回 error_log，Registry 與 YouTube 同步上面都載了。
 */
require ANIME_SYNC_PRO_DIR . 'includes/class-youranimes-fetcher.php';

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
