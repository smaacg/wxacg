<?php
/**
 * 檔案名稱: tools/build-bahamut-bundle.php
 * 在台灣 IP 的電腦上抓巴哈 sitemap，產出隨 repo 部署的索引包。
 *
 * 為什麼要有這支
 * --------------
 * 正式站主機在吉隆坡，巴哈對它回 403 Cloudflare 人機驗證；台灣 IP 正常。
 * 所以「抓」這一步搬到本機做，產出 data/source_bahamut_bundle.json（約 250KB），
 * git push 部署上去，主機端的 Anime_Sync_Streaming_Source_Bahamut 只讀檔。
 *
 * 不依賴 WordPress：這支自己補幾個替身函式，然後載入**真正會部署的那兩個類別**
 * （基底＋巴哈子類別）去抓與歸納——不另寫一份等效邏輯，本機建出來的就是
 * 主機會用的同一套規則。
 *
 * 用法（Windows，Local WP 自帶的 PHP）：
 *   "C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" ^
 *     -d extension_dir="C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext" ^
 *     -d extension=php_mbstring.dll -d extension=php_openssl.dll -d extension=php_curl.dll ^
 *     "F:\fuck\app\public\wp-content\plugins\anime-sync-pro\tools\build-bahamut-bundle.php"
 *
 *   成功後 git add data/source_bahamut_bundle.json → commit → push，部署即生效。
 *   建議每週跑一次（巴哈每月新增 20~70 部作品）。
 *
 * @package Anime_Sync_Pro
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "只能在命令列執行\n" );
}

define( 'ASP_BUNDLE_BUILD', true );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ANIME_SYNC_PRO_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

// ── WordPress 函式替身：只補基底在「收集」路徑會碰到的那幾個 ──
class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['__asp_tr'] = [];
function get_transient( $k ) { return $GLOBALS['__asp_tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__asp_tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__asp_tr'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function add_action() {}
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }

/**
 * 找 CA 憑證包。Local WP 自帶的 PHP 沒有 php.ini，curl 不知道信任誰，
 * 會回「unable to get local issuer certificate」。不關驗證，改給它憑證檔：
 * 依序看環境變數 ASP_CA_BUNDLE、php.ini 的 curl.cainfo、Git for Windows 自帶的那份。
 */
function asp_ca_bundle(): string {
	$candidates = [
		(string) getenv( 'ASP_CA_BUNDLE' ),
		(string) ini_get( 'curl.cainfo' ),
		'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
		'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
		dirname( PHP_BINARY ) . '/extras/ssl/cacert.pem',
	];
	foreach ( $candidates as $c ) {
		if ( $c !== '' && is_readable( $c ) ) {
			return $c;
		}
	}
	return '';
}

/** 用 curl 取代 wp_remote_get；標頭與基底一致。 */
function wp_remote_get( $url, $args = [] ) {
	$ch = curl_init( $url );
	$ca = asp_ca_bundle();
	if ( $ca !== '' ) {
		curl_setopt( $ch, CURLOPT_CAINFO, $ca );
	}
	curl_setopt_array( $ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 3,
		CURLOPT_TIMEOUT        => (int) ( $args['timeout'] ?? 60 ),
		CURLOPT_USERAGENT      => (string) ( $args['user-agent'] ?? 'Mozilla/5.0' ),
		CURLOPT_HTTPHEADER     => [ 'Accept: application/xml,text/xml;q=0.9,*/*;q=0.8', 'Accept-Language: zh-TW,zh;q=0.9,en;q=0.8' ],
	] );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );
	if ( $body === false ) {
		return new WP_Error( 'http_request_failed', $err );
	}
	return [ 'body' => (string) $body, 'response' => [ 'code' => $code ] ];
}

// bangumi-data 對照表快取（Anime_Sync_Bangumi_Data_Feed 用 wp_upload_dir 找位置）放系統暫存目錄
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_upload_dir() { return [ 'basedir' => sys_get_temp_dir() . '/asp-bundle-build' ]; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }

require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-base.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-bangumi-data.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-bahamut.php';

$out = Anime_Sync_Streaming_Source_Bahamut::bundle_path();
$src = new Anime_Sync_Streaming_Source_Bahamut();

echo "抓取 https://ani.gamer.com.tw/sitemap/sitemap.xml（約 21MB）…\n";
echo "接著把 bangumi-data 的巴哈 ACG 編號解析成動畫瘋 sn（只抓上一包沒有的；第一次約 1,700 頁、每頁間隔 0.4 秒）…\n";
$t0 = microtime( true );
$r  = $src->export_bundle( $out, static function ( int $done, int $total, string $id, ?int $sn ): void {
	if ( $done === 1 || $done % 50 === 0 || $done === $total ) {
		printf( "  ACG 對照 %d/%d（s=%s → %s）\n", $done, $total, $id, $sn === null ? '抓取失敗' : ( $sn > 0 ? 'sn=' . $sn : '無動畫瘋' ) );
	}
} );

if ( is_wp_error( $r ) ) {
	fwrite( STDERR, '失敗：' . $r->get_error_code() . '：' . $r->get_error_message() . "\n" );
	exit( 1 );
}

printf( "完成：%d 條目 → %d 部作品；ACG 對照 %d 筆（上一包 %d）；%d KB，%.1f 秒\n寫入 %s\n", $r['entries'], $r['works'], $r['acg'], $r['acg_prev'], $r['bytes'] / 1024, microtime( true ) - $t0, $out );

// ── 車庫娛樂 AniPASS：同樣主機被擋、台灣 IP 正常，順便建。153 個作品頁約 2 分鐘 ──
require ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-garageplay.php';
echo "\n抓取 https://garageplay.tw/anipass/AnipassVideo（清單＋每部作品頁 og:title）…\n";
$t1  = microtime( true );
$gp  = new Anime_Sync_Streaming_Source_Garageplay();
$out2 = Anime_Sync_Streaming_Source_Garageplay::bundle_path();
$r2  = $gp->export_bundle( $out2, static function ( int $done, int $total, string $title ): void {
	if ( $done === 1 || $done % 25 === 0 || $done === $total ) {
		printf( "  AniPASS %d/%d（%s）\n", $done, $total, $title );
	}
} );
if ( is_wp_error( $r2 ) ) {
	// 車庫失敗不讓整支排程失敗：巴哈那包已經寫好；bat 會 git add 兩個檔，沒變的不會進 commit
	fwrite( STDERR, '車庫建包失敗（巴哈那包不受影響）：' . $r2->get_error_code() . '：' . $r2->get_error_message() . "\n" );
} else {
	printf( "完成：%d 條目 → %d 部作品，%d KB，%.1f 秒\n寫入 %s\n", $r2['entries'], $r2['works'], $r2['bytes'] / 1024, microtime( true ) - $t1, $out2 );
}

echo "下一步：git add 這兩個檔 → commit → push，部署後主機端排程會讀它們。\n";
