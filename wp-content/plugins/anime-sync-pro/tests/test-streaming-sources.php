<?php
/**
 * 檔案名稱: tests/test-streaming-sources.php
 * 串流來源解析規則的回歸測試（純函式，不連網、不碰資料庫）
 *
 * 執行：
 *   "C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" ^
 *     -d extension_dir="...\ext" -d extension=php_mbstring.dll ^
 *     "F:\fuck\app\public\wp-content\plugins\anime-sync-pro\tests\test-streaming-sources.php"
 *
 * 每個案例都來自實際抓過的資料（見各來源檔頭的實測記錄），不是憑空想的。
 * 改任何解析規則之前先跑一次，改完再跑一次。
 *
 * @package Anime_Sync_Pro
 */

require __DIR__ . '/bootstrap.php';

// ─────────────────────────────────────────────────────────
// 1. 基底 normalize()：季別寫法要能拉平，否則「第二季」配不到「第2季」
// ─────────────────────────────────────────────────────────
$base = Anime_Sync_Streaming_Source_Base::make( 'myvideo' );

t_is( t_call( $base, 'normalize', '進擊的巨人 第二季' ), t_call( $base, 'normalize', '進擊的巨人 第2季' ), 'normalize：中文數字季別＝阿拉伯數字季別' );
t_is( t_call( $base, 'normalize', '進擊的巨人 第2季' ), t_call( $base, 'normalize', '進擊的巨人  第 2 季' ), 'normalize：空白不影響' );
t_is( t_call( $base, 'normalize', 'Re:Zero Season 2' ), t_call( $base, 'normalize', 'Re:Zero 第2季' ), 'normalize：Season 2 ＝ 第2季' );
// 季數 ≥7 走的是另一條分支（normalize_public 只認 2~6），大小寫同樣不得分歧
t_is( t_call( $base, 'normalize', '某作品 第七季' ), t_call( $base, 'normalize', '某作品 第7季' ), 'normalize：第七季 ＝ 第7季（季數 ≥7 也要一致）' );
t_is( t_call( $base, 'normalize', '某作品 第二季' ), t_call( $base, 'normalize', '某作品 第2期' ), 'normalize：季與期是同一件事' );
t_is( t_call( $base, 'normalize', 'bgm:22108' ), 'bgm22108', 'normalize：bangumi-data 的 ID 鍵形狀穩定' );
t_is( t_call( $base, 'normalize', 'al:9876' ), 'al9876', 'normalize：AniList ID 鍵形狀穩定' );

// index_keys()：沒有開播日的來源不得產生「去劇場版前綴」的別名
// （2026-09-15 事故：TV 版被配到劇場版，因為別名讓劇場版變成唯一候選）
t_is( t_call( $base, 'index_keys', '劇場版 少女與戰車' ), [ t_call( $base, 'normalize', '劇場版 少女與戰車' ) ], 'index_keys：無開播日來源只產生一個鍵，不做劇場版別名' );

// ─────────────────────────────────────────────────────────
// 2. 各來源 work_name()：從平台標題取出作品名
// ─────────────────────────────────────────────────────────

// 巴哈：尾端 [12]、[電影]、[中文配音] 之類的標記要去掉
$baha = Anime_Sync_Streaming_Source_Base::make( 'bahamut' );
t_is( t_call( $baha, 'work_name', '葬送的芙莉蓮 [1]' ), '葬送的芙莉蓮', '巴哈 work_name：去尾端集數標記' );
t_is( t_call( $baha, 'work_name', '間諜家家酒 [12] [中文配音]' ), '間諜家家酒', '巴哈 work_name：去多個尾端標記' );

// Hami：季別 S2、分割 P1/P2 是它的慣例
$hami = Anime_Sync_Streaming_Source_Base::make( 'hami' );
t_is( t_call( $hami, 'work_name', '骸骨騎士大人異世界冒險中S2' ), '骸骨騎士大人異世界冒險中 第2季', 'Hami work_name：S2 → 第2季' );
t_is( t_call( $hami, 'work_name', '犬夜叉P1' ), '犬夜叉', 'Hami work_name：P1 是第一部分，去掉' );
t_is( t_call( $hami, 'work_name', '關於我轉生變成史萊姆這檔事S2P2' ), '關於我轉生變成史萊姆這檔事 第2季 第2部分', 'Hami work_name：S2P2 兩段都要轉' );
t_is( t_call( $hami, 'work_name', '夏目友人帳伍' ), '夏目友人帳伍', 'Hami work_name：沒有標記就原樣' );

// CatchPlay：全形間隔點是季別分隔
$cp = Anime_Sync_Streaming_Source_Base::make( 'catchplay' );
t_is( t_call( $cp, 'work_name', 'SPY x FAMILY 間諜家家酒．第2季' ), 'SPY x FAMILY 間諜家家酒 第2季', 'CatchPlay work_name：．第2季 → 空格分隔' );
t_is( t_call( $cp, 'work_name', '魔法少女小圓 後編 永遠的物語' ), '魔法少女小圓 後編 永遠的物語', 'CatchPlay work_name：一般標題不動' );

// 車庫 AniPASS：站名尾綴
$gp = Anime_Sync_Streaming_Source_Base::make( 'garageplay' );
t_is( t_call( $gp, 'work_name', '灰色：幻影扳機 動畫版 Stargazer | Anipass 動畫' ), '灰色：幻影扳機 動畫版 Stargazer', '車庫 work_name：去站名尾綴' );

// MyVideo／Ofiii／LiTV：集數在標題裡
$mv = Anime_Sync_Streaming_Source_Base::make( 'myvideo' );
t_is( t_call( $mv, 'work_name', '我的英雄學院 第25集' ), '我的英雄學院', 'MyVideo work_name：去尾端集數' );
$of = Anime_Sync_Streaming_Source_Base::make( 'ofiii' );
t_is( t_call( $of, 'work_name', '鬼滅之刃 第1季 第3集' ), '鬼滅之刃', 'Ofiii work_name：從集數截斷並去第1季' );

// LINE TV：尾綴從「相關影音｜」砍掉
$ltv = Anime_Sync_Streaming_Source_Base::make( 'linetv' );
t_is( t_call( $ltv, 'work_name', '孤獨搖滾相關影音｜免費線上看｜LINE TV-精彩隨看' ), '孤獨搖滾', 'LINE TV work_name：砍站名尾綴' );

// ─────────────────────────────────────────────────────────
// 3. Hami parse_end_date()：平台公告的下架日期
//    （到期日機制的核心，解析錯會誤刪作品）
// ─────────────────────────────────────────────────────────
t_is(
	t_call( $hami, 'parse_end_date', '<li>下架時間&nbsp;2026年10月31日</span></div></li>' ),
	'2026-10-31',
	'Hami parse_end_date：一般作品頁的明文日期'
);
t_is(
	t_call( $hami, 'parse_end_date', '下架時間&nbsp; <span class="endDate0" hidden data="2026年09月24日"></span>' ),
	'2026-09-24',
	'Hami parse_end_date：到期作品頁把日期放在 hidden span 的 data 屬性'
);
t_is(
	t_call( $hami, 'parse_end_date', '下架時間&nbsp; <span class="endDate_show"></span>' ),
	'',
	'Hami parse_end_date：有欄位但沒日期 → 空字串（不是 null）'
);
t_is(
	// ★ 這段假 HTML 不能出現「下架時間」四個字：第一版寫成「沒有下架時間欄位」，
	//   程式因此判定它是作品頁，是測試資料寫錯而不是程式錯。
	t_call( $hami, 'parse_end_date', '<html><body>這是首頁，只有輪播與推薦區塊</body></html>' ),
	null,
	'Hami parse_end_date：不是作品頁 → null（不可當成「沒有到期日」）'
);

// ─────────────────────────────────────────────────────────
// 4. CatchPlay title_from_page()：og:title 同時是標題與「是不是動畫」的判斷
// ─────────────────────────────────────────────────────────
t_is(
	$cp->title_from_page( '<meta property="og:title" content="《SPY x FAMILY 間諜家家酒．第2季》線上看｜CATCHPLAY+ 正版日本動畫動漫專區"/>' ),
	'SPY x FAMILY 間諜家家酒 第2季',
	'CatchPlay title_from_page：動畫專區 → 取出作品名'
);
t_is(
	$cp->title_from_page( '<meta property="og:title" content="《九條好漢在一班》線上看｜共1季26集｜CATCHPLAY+ 正版影集專區"/>' ),
	null,
	'CatchPlay title_from_page：真人影集專區 → null（同名真人版靠這道擋掉）'
);
t_is(
	$cp->title_from_page( '<meta property="og:title" content="《航海王(國語版)》線上看｜CATCHPLAY+ 正版日本動畫動漫專區"/>' ),
	null,
	'CatchPlay title_from_page：中文配音版不進索引'
);
t_is(
	$cp->title_from_page( '<html><head><title>CATCHPLAY+</title></head></html>' ),
	null,
	'CatchPlay title_from_page：沒有 og:title → null'
);

// ─────────────────────────────────────────────────────────
// 5. YouTube 頻道：播放清單標題 → 作品名
// ─────────────────────────────────────────────────────────
$muse = Anime_Sync_Streaming_Source_Base::make( 'muse' );
t_is( t_call( $muse, 'work_name', '【中文字幕】《關於我轉生變成史萊姆這檔事》第一季' ), t_call( $muse, 'work_name', '【中文字幕】《關於我轉生變成史萊姆這檔事》第一季' ), 'Muse work_name：可重入（同輸入同輸出）' );

// ─────────────────────────────────────────────────────────
// 5b. 網址覆核的宣告：哪些來源能從主機判斷「作品頁還在不在」
//     （2026-09-16 從主機實測的結果，改動任何一項都要先重測再改這裡）
// ─────────────────────────────────────────────────────────
foreach ( [ 'myvideo' => true, 'ofiii' => true, 'litv' => true, 'friday' => true, 'linetv' => true, 'hami' => true,
            'catchplay' => false, 'bahamut' => false, 'garageplay' => false ] as $key => $expected ) {
	$src = Anime_Sync_Streaming_Source_Base::make( $key );
	t_is( t_call( $src, 'provides_alive_check' ), $expected, "覆核宣告：{$key} " . ( $expected ? '可以' : '不能' ) . '從主機判斷存活' );
}

// friDay 對不存在的 id 回 400 而不是 404——漏掉這個設定，它的下架永遠偵測不到
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'friday' ), 'alive_missing_codes' ), [ 400, 404 ], 'friDay：不存在的狀態碼是 400（含 404）' );
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'myvideo' ), 'alive_missing_codes' ), [ 404 ], 'MyVideo：不存在的狀態碼是 404' );

// LINE TV 每小時已經要爬 300 頁建索引，覆核批次必須比別人小
t_is(
	t_call( Anime_Sync_Streaming_Source_Base::make( 'linetv' ), 'recheck_batch' ) < t_call( Anime_Sync_Streaming_Source_Base::make( 'hami' ), 'recheck_batch' ),
	true,
	'LINE TV 的覆核批次要小於一般來源'
);

// 能覆核的來源不該再跑索引比對版的下架偵測（兩者重複會把 strike 加兩次）
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'hami' ), 'provides_end_date' ), true, 'Hami：有到期日可解析' );
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'myvideo' ), 'provides_end_date' ), false, 'MyVideo：沒有到期日，只做存活覆核' );

// ─────────────────────────────────────────────────────────
// 5c. bangumi-data：台灣站點要收齊（漏一個就少一批可比對的作品）
// ─────────────────────────────────────────────────────────
foreach ( [ 'gamer', 'muse_tw', 'ani_one', 'ani_one_asia', 'tropics', 'mighty', 'bilibili_tw', 'bilibili_hk_mo_tw' ] as $site ) {
	t_is( in_array( $site, Anime_Sync_Bangumi_Data_Feed::SITES, true ), true, "bangumi-data 站點：{$site} 有收" );
}
// netflix 是全球站點（siteMeta 沒有 regions），收了會寫入台灣看不到的連結——2026-09-15 踩過
t_is( in_array( 'netflix', Anime_Sync_Bangumi_Data_Feed::SITES, true ), false, 'bangumi-data 站點：netflix 不可收（全球站點、非台灣）' );
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'bilibili' ), 'bangumi_sites' ), [ 'bilibili_tw', 'bilibili_hk_mo_tw' ], 'Bilibili：台灣專屬站點優先、港澳台次之' );

// ─────────────────────────────────────────────────────────
// 6. 索引包基底：三家共用同一套讀檔與過期判斷
// ─────────────────────────────────────────────────────────
foreach ( [ 'bahamut', 'garageplay', 'catchplay' ] as $key ) {
	$src = Anime_Sync_Streaming_Source_Base::make( $key );
	t_is( $src instanceof Anime_Sync_Streaming_Source_Bundle_Base, true, "索引包基底：{$key} 繼承 Bundle_Base" );
	$info = $src->bundle_info();
	t_is( is_array( $info ) && array_key_exists( 'stale', $info ), true, "索引包基底：{$key} 的 bundle_info() 有 stale 旗標" );
}

exit( t_report() );
