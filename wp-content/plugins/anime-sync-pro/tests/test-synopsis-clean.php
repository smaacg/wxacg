<?php
/**
 * 檔案名稱: tests/test-synopsis-clean.php
 * 簡介清理規則的回歸測試（純函式，不連網、不碰資料庫）
 *
 * 執行：
 *   "C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" ^
 *     -d extension_dir="C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext" ^
 *     -d extension=php_mbstring.dll ^
 *     "F:\fuck\app\public\wp-content\plugins\anime-sync-pro\tests\test-synopsis-clean.php"
 *
 * 案例取自正式站 anime_synopsis_chinese 的實際資料（2026-09-17 全站 3,244 筆掃描）。
 * 改 clean_synopsis() 之前先跑一次，改完再跑一次。
 *
 * @package Anime_Sync_Pro
 */

require __DIR__ . '/bootstrap.php';

$api  = new Anime_Sync_API_Handler();
$IDEO = json_decode( '"\u3000"' );   // U+3000 全形空格，明確產生不靠字面跳脫

// ─────────────────────────────────────────────────────────
// 1. 行首的全形空格縮排要清掉
//    Bangumi 中文簡介慣用「　　」開頭；前台是用 wpautop() 產生段落的
//    （single-anime.php:5211），這些縮排純屬多餘。
//    真實案例：post 55570《成為女主角！》14 個全形空格、7 行。
// ─────────────────────────────────────────────────────────
t_is(
	$api->clean_synopsis_public( "{$IDEO}{$IDEO}在鄉下長大的女高中生涼海日和，喜歡跑步。" ),
	'在鄉下長大的女高中生涼海日和，喜歡跑步。',
	'行首：開頭兩個全形空格要清掉'
);
t_is(
	$api->clean_synopsis_public( "{$IDEO}{$IDEO}第一行\n{$IDEO}{$IDEO}第二行\n{$IDEO}{$IDEO}第三行" ),
	"第一行\n第二行\n第三行",
	'行首：每一行的縮排都要清掉'
);
t_is(
	$api->clean_synopsis_public( "{$IDEO}只有一個" ),
	'只有一個',
	'行首：單一個全形空格也要清'
);
t_is(
	$api->clean_synopsis_public( "{$IDEO}{$IDEO}{$IDEO}{$IDEO}連續四個" ),
	'連續四個',
	'行首：連續多個一次清乾淨'
);

// ─────────────────────────────────────────────────────────
// 2. ★ 句中的全形空格一定要保留 ★
//    這是整組測試最重要的一條。句中的全形空格幾乎都是日文標題與副標的
//    分隔，是正當內容，清掉就是破壞資料。
//    2026-09-17 全站實測：625 個全形空格裡 154 個在句中，全是這種用法。
// ─────────────────────────────────────────────────────────
t_is(
	$api->clean_synopsis_public( "機動戦士ガンダム{$IDEO}閃光のハサウェイ" ),
	"機動戦士ガンダム{$IDEO}閃光のハサウェイ",
	'句中：日文標題分隔要保留（閃光のハサウェイ）'
);
t_is(
	$api->clean_synopsis_public( "劇場版{$IDEO}アーヤと魔女" ),
	"劇場版{$IDEO}アーヤと魔女",
	'句中：劇場版標題分隔要保留'
);
t_is(
	$api->clean_synopsis_public( "{$IDEO}{$IDEO}劇場版{$IDEO}アーヤと魔女" ),
	"劇場版{$IDEO}アーヤと魔女",
	'混合：只清行首，同一行句中的要留'
);
t_is(
	$api->clean_synopsis_public( "{$IDEO}第一行句中{$IDEO}保留\n{$IDEO}第二行句中{$IDEO}保留" ),
	"第一行句中{$IDEO}保留\n第二行句中{$IDEO}保留",
	'混合：多行各自只清行首'
);

// ─────────────────────────────────────────────────────────
// 3. 英文簡介走同一支函式，不得受影響（U+3000 是 CJK 字元）
// ─────────────────────────────────────────────────────────
t_is(
	$api->clean_synopsis_public( 'Hiyori left her hometown to pursue her passion.' ),
	'Hiyori left her hometown to pursue her passion.',
	'英文：半形空格完全不受影響'
);

// ─────────────────────────────────────────────────────────
// 4. 既有行為的回歸，不得被新規則弄壞
// ─────────────────────────────────────────────────────────
t_is( $api->clean_synopsis_public( '第一行<br>第二行' ),      "第一行\n第二行", '回歸：<br> 轉換行' );
t_is( $api->clean_synopsis_public( '第一行<br />第二行' ),    "第一行\n第二行", '回歸：<br /> 轉換行' );
t_is( $api->clean_synopsis_public( '<p>去標籤</p>' ),          '去標籤',        '回歸：HTML 標籤剝除' );
t_is( $api->clean_synopsis_public( '引號&quot;解碼&quot;' ),   '引號"解碼"',    '回歸：HTML 實體解碼' );
t_is( $api->clean_synopsis_public( '正文 (Source: MAL)' ),     '正文',          '回歸：移除 (Source: …)' );
t_is( $api->clean_synopsis_public( '正文 [Written by MAL Rewrite]' ), '正文',   '回歸：移除 [Written by …]' );
t_is( $api->clean_synopsis_public( '  前後空白  ' ),           '前後空白',      '回歸：頭尾 trim' );

// 空字串不得爆炸
t_is( $api->clean_synopsis_public( '' ), '', '邊界：空字串' );
t_is( $api->clean_synopsis_public( $IDEO . $IDEO ), '', '邊界：只有全形空格 → 清空後 trim 成空字串' );

exit( t_report() );
