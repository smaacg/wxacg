<?php
/**
 * 檔案名稱: tests/test-youranimes-fill-only.php
 * YourAnimes 寫入的「回填模式只補空白」保護
 *
 * 執行方式（與其他套件相同，見 tests/test-streaming-sources.php 檔頭）：
 *   "C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" ^
 *     -d extension_dir="...\ext" -d extension=php_mbstring.dll ^
 *     "F:\fuck\app\public\wp-content\plugins\anime-sync-pro\tests\test-youranimes-fill-only.php"
 *
 * 為什麼需要這支（2026-09-20）
 * ----------------------------
 * write_to_acf() 原本是無條件 update_post_meta()。每日同步只碰當季新番，那些欄位
 * 本來就是 YA 寫的，覆寫等於更新，沒問題。但過期重整要掃全站 1,804 部，裡面有
 * 人工修正過的網址、掃描器寫的網址、以及查證後刻意保留的值（Bilibili 那批搜尋頁
 * 連結）——無條件覆寫會把它們靜默改寫成 YA 的版本。
 *
 * 回填的目的是「補缺」，不是「以 YA 為準重寫全站」。這支測試盯著那個差別。
 *
 * @package Anime_Sync_Pro
 */

require __DIR__ . '/bootstrap.php';

$fetcher = new Anime_Sync_YourAnimes_Fetcher();

/** write_to_acf() 是 private，用 t_call 叫進去。 */
$write = static function ( int $pid, array $streams, bool $fill_only ) use ( $fetcher ) {
	return t_call( $fetcher, 'write_to_acf', $pid, $streams, $fill_only );
};

// ─────────────────────────────────────────────────────────
// 1. 預設行為（每日同步）：以 YA 為準覆寫
// ─────────────────────────────────────────────────────────
t_meta_reset();
update_post_meta( 1, 'anime_tw_streaming_url_bahamut', 'https://ani.gamer.com.tw/animeVideo.php?sn=OLD' );

$write( 1, [ 'bahamut' => 'https://ani.gamer.com.tw/animeVideo.php?sn=NEW' ], false );

t_is(
	get_post_meta( 1, 'anime_tw_streaming_url_bahamut', true ),
	'https://ani.gamer.com.tw/animeVideo.php?sn=NEW',
	'預設模式：既有網址會被 YA 的版本覆寫（每日同步的原行為，不可改變）'
);

// ─────────────────────────────────────────────────────────
// 2. 回填模式：既有值一律不動
// ─────────────────────────────────────────────────────────
t_meta_reset();
update_post_meta( 2, 'anime_tw_streaming_url_bahamut', 'https://ani.gamer.com.tw/animeVideo.php?sn=MANUAL' );

$write( 2, [ 'bahamut' => 'https://ani.gamer.com.tw/animeVideo.php?sn=YA' ], true );

t_is(
	get_post_meta( 2, 'anime_tw_streaming_url_bahamut', true ),
	'https://ani.gamer.com.tw/animeVideo.php?sn=MANUAL',
	'回填模式：人工修正過的網址不可被覆寫'
);

// ★ 站上查證後刻意保留的 Bilibili 搜尋頁連結，回填時同樣不能被改掉
t_meta_reset();
update_post_meta( 3, 'anime_tw_streaming_url_bilibili', 'https://www.bilibili.com/search?keyword=x' );

$write( 3, [ 'bilibili' => 'https://www.bilibili.com/bangumi/media/md123' ], true );

t_is(
	get_post_meta( 3, 'anime_tw_streaming_url_bilibili', true ),
	'https://www.bilibili.com/search?keyword=x',
	'回填模式：查證後刻意保留的 Bilibili 連結不可被改寫'
);

// ─────────────────────────────────────────────────────────
// 3. 回填模式對「空白欄位」仍要補進去——這才是它存在的理由
// ─────────────────────────────────────────────────────────
t_meta_reset();
$write( 4, [ 'netflix' => 'https://www.netflix.com/tw/title/81662402' ], true );

t_is(
	get_post_meta( 4, 'anime_tw_streaming_url_netflix', true ),
	'https://www.netflix.com/tw/title/81662402',
	'回填模式：空白欄位要補上（這是整個功能的目的）'
);

$checked = get_post_meta( 4, 'anime_tw_streaming', true );
t_is(
	is_array( $checked ) && in_array( 'netflix', $checked, true ),
	true,
	'回填模式：補網址的同時要勾選該平台，否則前台不顯示'
);

// ─────────────────────────────────────────────────────────
// 4. 已有網址卻沒勾選：補勾選，但網址仍然不動
// ─────────────────────────────────────────────────────────
t_meta_reset();
update_post_meta( 5, 'anime_tw_streaming_url_hami', 'https://hamivideo.hinet.net/product/KEEP.do' );

$write( 5, [ 'hami' => 'https://hamivideo.hinet.net/product/OTHER.do' ], true );

t_is(
	get_post_meta( 5, 'anime_tw_streaming_url_hami', true ),
	'https://hamivideo.hinet.net/product/KEEP.do',
	'回填模式：補勾選時網址仍不可被覆寫'
);
$checked5 = get_post_meta( 5, 'anime_tw_streaming', true );
t_is(
	is_array( $checked5 ) && in_array( 'hami', $checked5, true ),
	true,
	'回填模式：「有網址卻沒勾選」的不一致要補起來（只讓既有資料被看見）'
);

// ─────────────────────────────────────────────────────────
// 5. 配音欄位是人工維護的，回填模式一律不覆蓋
// ─────────────────────────────────────────────────────────
t_meta_reset();
update_post_meta( 6, 'anime_dub_url_mandarin', '巴哈姆特動畫瘋|https://ani.gamer.com.tw/MANUAL' );

$write( 6, [ '__dub_mandarin_multi' => [ 'hami' => 'https://hamivideo.hinet.net/product/YA.do' ] ], true );

t_is(
	get_post_meta( 6, 'anime_dub_url_mandarin', true ),
	'巴哈姆特動畫瘋|https://ani.gamer.com.tw/MANUAL',
	'回填模式：人工維護的國語配音欄位不可被覆寫'
);

// 但空白的配音欄位仍要補
t_meta_reset();
$write( 7, [ '__dub_mandarin_multi' => [ 'hami' => 'https://hamivideo.hinet.net/product/YA.do' ] ], true );

t_is(
	strpos( (string) get_post_meta( 7, 'anime_dub_url_mandarin', true ), 'hamivideo.hinet.net/product/YA.do' ) !== false,
	true,
	'回填模式：空白的配音欄位要補上'
);

// ─────────────────────────────────────────────────────────
// 6. YouTube 播放清單：回填模式不覆寫，也不觸發集數同步
//    （原本只有「國際頻道」才保護既有值，台灣頻道的清單會覆寫人工填的；
//      而下面緊接著的立即同步，乘上 1,804 部會把 YouTube API 配額吃光）
// ─────────────────────────────────────────────────────────
t_meta_reset();
update_post_meta( 8, 'anime_yt_playlist_url', 'https://www.youtube.com/playlist?list=MANUAL' );

$write( 8, [ '__dub_mandarin_multi' => [ 'muse' => 'https://www.youtube.com/playlist?list=YA' ] ], true );

t_is(
	get_post_meta( 8, 'anime_yt_playlist_url', true ),
	'https://www.youtube.com/playlist?list=MANUAL',
	'回填模式：既有的 YouTube 播放清單不可被覆寫'
);

// 空白時仍要補上（補完就 return，不會往下觸發集數同步）
t_meta_reset();
$r9 = $write( 9, [ '__dub_mandarin_multi' => [ 'muse' => 'https://www.youtube.com/playlist?list=YA' ] ], true );

t_is(
	get_post_meta( 9, 'anime_yt_playlist_url', true ),
	'https://www.youtube.com/playlist?list=YA',
	'回填模式：空白的 YouTube 播放清單要補上'
);
t_is(
	is_array( $r9 ) && ! in_array( 'YouTube 自動同步 ()', $r9, true ),
	true,
	'回填模式：不觸發 YouTube 集數同步（避免 1,804 部打爆 API 配額）'
);

exit( t_report() );
