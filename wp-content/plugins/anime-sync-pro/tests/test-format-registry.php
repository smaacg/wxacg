<?php
/**
 * 檔案名稱: tests/test-format-registry.php
 * 格式登錄表與泡麵番判定的回歸測試（純函式，不連網、不碰資料庫）
 *
 * 執行：
 *   "C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" ^
 *     -d extension_dir="C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext" ^
 *     -d extension=php_mbstring.dll ^
 *     "F:\fuck\app\public\wp-content\plugins\anime-sync-pro\tests\test-format-registry.php"
 *
 * 案例取自正式站實際資料（2026-09-17 全站 2,105 篇已發布 anime 的量測）。
 * 改判定規則之前先跑一次，改完再跑一次。
 *
 * @package Anime_Sync_Pro
 */

require __DIR__ . '/bootstrap.php';

// ─────────────────────────────────────────────────────────
// 1. 哪些格式「有資格」被判成泡麵番
//    只收 ONA：AniList 的 TV_SHORT 只給電視播出的短篇，
//    網路播出的短篇一律歸 ONA，這一層才是站上要補的。
// ─────────────────────────────────────────────────────────
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'ONA' ),      true,  '資格：ONA' );
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'ona' ),      true,  '資格：大小寫不影響' );
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'TV' ),       false, '資格：TV 不收' );
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'TV_SHORT' ), false, '資格：TV_SHORT 本來就是泡麵番，不需要再判' );
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'OVA' ),      false, '資格：OVA 不收' );
// 特別篇本身就是有意義的標籤，短的特別篇仍然是特別篇
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'SPECIAL' ),  false, '資格：SPECIAL 刻意不收' );
t_is( Anime_Sync_Format_Registry::is_short_eligible( 'MOVIE' ),    false, '資格：MOVIE 不收' );

// ─────────────────────────────────────────────────────────
// 2. 時長門檻
// ─────────────────────────────────────────────────────────
t_is( Anime_Sync_Format_Registry::SHORT_MAX_MINUTES, 15, '門檻：15 分（日文維基「短編アニメ」定義）' );

t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 1 ),  true,  '時長：1 分' );
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 5 ),  true,  '時長：5 分' );
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 15 ), true,  '時長：15 分（邊界內）' );
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 16 ), false, '時長：16 分（邊界外）' );
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 24 ), false, '時長：24 分是一般長度' );

/*
 * ★ 這條是整組測試裡最重要的一條 ★
 *
 * anime_duration = 0 是「未知」的占位值，不是「很短」。
 * 站上有 271 部正常的 TV 動畫（無職轉生第三季、幼女戰記第二季、
 * BLEACH 千年血戰篇…）時長都記 0。不擋掉就會被全部標成泡麵番。
 */
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 0 ),  false, '時長 0 是「未知」不是「很短」，必須排除' );
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', -1 ), false, '時長：負數不合理，排除' );

// 沒資格的格式，時長再短也不是泡麵番
t_is( Anime_Sync_Format_Registry::is_short_anime( 'TV', 5 ),      false, '格式不符：TV 5 分也不算' );
t_is( Anime_Sync_Format_Registry::is_short_anime( 'SPECIAL', 5 ), false, '格式不符：SPECIAL 5 分也不算' );

// ─────────────────────────────────────────────────────────
// 3. 正式站實際案例
// ─────────────────────────────────────────────────────────
// 暦物語：ONA、每話約 10～15 分（外部查證：アニプレックス官網、dアニメストア）
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 14 ), true, '實例：暦物語（14 分）' );
// 衛宮家今天的餐桌風景：ONA、每話約 12～13 分（外部查證：日文維基）
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 13 ), true, '實例：衛宮家今天的餐桌風景（13 分）' );
// 香格里拉・開拓異境 迷你動畫：AniList 上就是 ONA，不是 TV_SHORT
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 3 ),  true, '實例：香格里拉 迷你動畫' );

/*
 * 已知誤判，刻意留著並記錄：post 4444「輝夜姬…先行PV」是把 PV 當成
 * 作品建檔（ONA / 10 分 / 1 集），會拿到泡麵番徽章。
 * 這是資料建檔的問題，該清掉那筆資料，不該用分類規則去繞——
 * 實測加上「集數 ≥6」的條件會為了擋這 1 筆而誤殺 11 部真正的泡麵番。
 */
t_is( Anime_Sync_Format_Registry::is_short_anime( 'ONA', 10 ), true, '已知誤判：先行PV 也會中（屬資料問題，不在此處繞）' );

// ─────────────────────────────────────────────────────────
// 4. 顯示名稱
// ─────────────────────────────────────────────────────────
t_is( Anime_Sync_Format_Registry::get_display_label( 'ONA', true ),  '泡麵番', '顯示：短的 ONA → 泡麵番' );
t_is( Anime_Sync_Format_Registry::get_display_label( 'ONA', false ), 'ONA',   '顯示：一般 ONA 維持 ONA' );

// 旗標就算被設錯，沒資格的格式也不會變泡麵番（雙重保險）
t_is( Anime_Sync_Format_Registry::get_display_label( 'TV', true ), 'TV', '顯示：旗標設錯也不會讓 TV 變泡麵番' );

// 不傳旗標時，行為必須與 get_label() 完全相同（既有呼叫點不受影響）
foreach ( [ 'TV', 'TV_SHORT', 'MOVIE', 'OVA', 'ONA', 'SPECIAL', 'MUSIC' ] as $code ) {
	t_is(
		Anime_Sync_Format_Registry::get_display_label( $code ),
		Anime_Sync_Format_Registry::get_label( $code ),
		"回歸：{$code} 不傳旗標時與 get_label() 一致"
	);
}

// 風格參數要能一路傳下去
t_is( Anime_Sync_Format_Registry::get_display_label( 'ONA', false, 'long' ), 'ONA（網路動畫）', '顯示：long 風格' );
t_is( Anime_Sync_Format_Registry::get_display_label( 'ONA', true, 'choice' ), '泡麵番 (TV_SHORT)', '顯示：choice 風格也借 TV_SHORT 的名稱' );

// 「泡麵番」這三個字只有一個出處：TV_SHORT 那一筆
t_is(
	Anime_Sync_Format_Registry::get_display_label( 'ONA', true ),
	Anime_Sync_Format_Registry::get_label( 'TV_SHORT' ),
	'單一出處：泡麵番的字串來自 TV_SHORT，沒有第二份'
);

exit( t_report() );
