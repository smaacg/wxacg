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
// 5d. 只覆核不發現的來源：Prime 與 Apple TV
//     案例全部來自 2026-09-16 對站上既有網址的實測
// ─────────────────────────────────────────────────────────
$pv = Anime_Sync_Streaming_Source_Base::make( 'amazon' );
$at = Anime_Sync_Streaming_Source_Base::make( 'appletv' );

t_is( t_call( $pv, 'verify_only' ), true, 'Prime：只覆核不建索引' );
t_is( t_call( $at, 'verify_only' ), true, 'Apple TV：只覆核不建索引' );

// Prime：AniList 給的全球網址要改寫成台灣區才判斷得出可看性（站上 26 筆裡有 12 筆是全球版）
t_is(
	t_call( $pv, 'recheck_url', 'https://www.primevideo.com/detail/0SJK7L4CHPWNY58A73PBANLSHB' ),
	'https://www.primevideo.com/-/zh_TW/detail/0SJK7L4CHPWNY58A73PBANLSHB',
	'Prime recheck_url：全球網址改寫成台灣區'
);
t_is(
	t_call( $pv, 'recheck_url', 'https://www.primevideo.com/-/zh_TW/detail/0JCBAQM21T35H8URDVGEMXG1XX' ),
	'https://www.primevideo.com/-/zh_TW/detail/0JCBAQM21T35H8URDVGEMXG1XX',
	'Prime recheck_url：已經是台灣區就不動'
);

t_is( t_call( $pv, 'parse_alive', '<div>主要按鈕：您所在地區的 Prime Video 無法繼續觀看此內容</div>', '' ), false, 'Prime parse_alive：台灣看不到' );
t_is( t_call( $pv, 'parse_alive', '<div data-testid="entitlement-message">以 Prime 會員資格觀看</div>', '' ), true, 'Prime parse_alive：可看' );
// ★ 可看的頁面也會出現「您所在」（觀看派對的介面字串），所以必須比對整句而不是關鍵詞
t_is( t_call( $pv, 'parse_alive', '<div>您所在地點無法使用此觀看派對。</div><div data-testid="entitlement-message">以 Prime 會員資格觀看</div>', '' ), true, 'Prime parse_alive：「您所在」出現在觀看派對字串裡不算下架' );
t_is( t_call( $pv, 'parse_alive', '<html>只有租買、沒有任何訊息</html>', '' ), null, 'Prime parse_alive：判不出來就不動它' );

// Apple TV：有 iTunes 商店頻道才算真的買得到
t_is( t_call( $at, 'parse_alive', '{"channelId":"tvs.sbd.9001","isEntitledToPlay":true}', 'https://tv.apple.com/tw/movie/x/umc.cmc.aaa' ), true, 'Apple TV parse_alive：有 iTunes 商店＝買得到' );
t_is( t_call( $at, 'parse_alive', '{"canonicalId":"umc.cmc.bbb"}', 'https://tv.apple.com/tw/show/x/umc.cmc.bbb' ), false, 'Apple TV parse_alive：只有目錄頁＝台灣買不到' );
// 站上有 2 筆是美國／日本區網址（AniList 給錯地區），不必抓頁面就能判定無效
t_is( t_call( $at, 'parse_alive', '{"channelId":"tvs.sbd.9001"}', 'https://tv.apple.com/jp/movie/x/umc.cmc.ccc' ), false, 'Apple TV parse_alive：日本區網址對台灣無效' );
t_is( t_call( $at, 'parse_alive', '<html>認不得的頁面</html>', 'https://tv.apple.com/tw/movie/x/umc.cmc.ddd' ), null, 'Apple TV parse_alive：認不得就不動它' );

// ─────────────────────────────────────────────────────────
// 5e. 作品頁「近期下架」的挑選邏輯
//     （與 public/templates/single-anime.php 裡那段同一套判斷；
//      正式站目前沒有任何 _ended_ 標記，線上跑不到，只能靠這裡守住）
// ─────────────────────────────────────────────────────────
$pick_ended = static function ( array $meta, array $labels, array $current_urls ): array {
	$out    = [];
	$cutoff = gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS );
	foreach ( $meta as $key => $vals ) {
		if ( strpos( (string) $key, '_anime_tw_streaming_ended_' ) !== 0 ) {
			continue;
		}
		$k       = substr( (string) $key, strlen( '_anime_tw_streaming_ended_' ) );
		$parts   = explode( '@', (string) ( $vals[0] ?? '' ), 2 );
		$removed = trim( $parts[1] ?? '' );
		if ( $removed === '' || $removed < $cutoff ) {
			continue;
		}
		if ( ! isset( $labels[ $k ] ) ) {
			continue;
		}
		if ( trim( (string) ( $current_urls[ $k ] ?? '' ) ) !== '' ) {
			continue;   // 已經重新上架
		}
		$out[] = $k;
	}
	sort( $out );
	return $out;
};

$L     = [ 'hami' => 'Hami', 'friday' => 'friDay', 'ofiii' => 'Ofiii' ];
$today = gmdate( 'Y-m-d' );
$old90 = gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS );

t_is( $pick_ended( [ '_anime_tw_streaming_ended_hami' => [ "2026-09-05@$today" ] ], $L, [] ), [ 'hami' ], '近期下架：授權到期而移除' );
t_is( $pick_ended( [ '_anime_tw_streaming_ended_friday' => [ "@$today" ] ], $L, [] ), [ 'friday' ], '近期下架：頁面不存在而移除（到期日為空）' );
t_is( $pick_ended( [ '_anime_tw_streaming_ended_hami' => [ "@$old90" ] ], $L, [] ), [], '近期下架：超過 60 天就不再顯示' );
// ★ 已重新上架必須看「網址欄位當下的值」，不能看 $tw_streaming_keys（那個陣列在該處還沒填）
t_is( $pick_ended( [ '_anime_tw_streaming_ended_hami' => [ "@$today" ] ], $L, [ 'hami' => 'https://hamivideo.hinet.net/product/1.do' ] ), [], '近期下架：已重新上架就不顯示' );
t_is( $pick_ended( [ '_anime_tw_streaming_ended_unknown' => [ "@$today" ] ], $L, [] ), [], '近期下架：未登錄的平台 key 不顯示' );
t_is( $pick_ended( [ '_anime_tw_streaming_ended_hami' => [ '2026-09-05' ] ], $L, [] ), [], '近期下架：格式壞掉（缺 @移除日）不顯示' );
t_is( $pick_ended( [ '_anime_tw_streaming_ended_hami' => [ "@$today" ], '_anime_tw_streaming_ended_ofiii' => [ "@$today" ] ], $L, [] ), [ 'hami', 'ofiii' ], '近期下架：兩個平台同時列出' );

// ─────────────────────────────────────────────────────────
// 5f. 重新上架要把「曾經下架」的紀錄清乾淨
//     （write() 會 delete _ended_ 與 _gone_；不清的話作品頁會同時出現
//      「Hami 按鈕」和「近期下架：Hami」）
// ─────────────────────────────────────────────────────────
$write_src = file_get_contents( ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-base.php' );
$write_pos = strpos( $write_src, 'protected function write( int $post_id' );
$write_body = $write_pos !== false ? substr( $write_src, $write_pos, 1800 ) : '';
t_is( strpos( $write_body, "delete_post_meta( \$post_id, '_anime_tw_streaming_ended_' . \$this->key() )" ) !== false, true, 'write()：重新寫入時清除 _ended_ 標記' );
t_is( strpos( $write_body, 'delete_post_meta( $post_id, $this->gone_meta_key() )' ) !== false, true, 'write()：重新寫入時清除疑似下架的 strike' );

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
