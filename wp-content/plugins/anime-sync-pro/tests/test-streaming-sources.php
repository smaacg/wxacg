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
            // YT 系：播放清單用 Data API 問存活，所以不限網址是誰寫的都能覆核（2026-09-17 加）
            'muse' => true, 'ani_one' => true, 'tropicsanime' => true,
            'mighty' => true, 'ani_mi' => true, 'its_anime' => true,
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
// 5b-2. YT 系覆核：網址轉換與 API 回應解析
//       #478 尼古喵喵的 Ani-One 清單 2026-08 就被刪了卻沒人發現——它沒有來源標記，
//       索引比對那條路掃不到它。這組測試守的是接替它的那條路。
// ─────────────────────────────────────────────────────────
$muse_src = Anime_Sync_Streaming_Source_Base::make( 'muse' );
$yt_pl    = 'https://www.youtube.com/playlist?list=PLap2KuvugB9M';

t_is(
	t_call( $muse_src, 'recheck_url', $yt_pl ),
	'https://www.googleapis.com/youtube/v3/playlists?part=id&id=PLap2KuvugB9M&key=test-key',
	'YT 覆核：播放清單網址轉成 playlists.list 查詢'
);
t_is(
	t_call( $muse_src, 'recheck_url', 'https://www.youtube.com/@MuseTW' ),
	'https://www.youtube.com/@MuseTW',
	'YT 覆核：認不出清單 id 的網址原樣回傳'
);

// 已刪除或轉私人的清單，API 回的是 200 ＋ 空 items（不是 404），所以只能靠內容判斷
t_is( t_call( $muse_src, 'parse_alive', '{"items":[]}', $yt_pl ), false, 'YT 覆核：items 空＝清單已刪除' );
t_is( t_call( $muse_src, 'parse_alive', '{"items":[{"id":"PLap2KuvugB9M"}]}', $yt_pl ), true, 'YT 覆核：items 有內容＝清單還在' );

// 以下都不是「作品下架」的證據，一律回 null 不動它，否則會錯殺
t_is( t_call( $muse_src, 'parse_alive', '{"error":{"message":"quotaExceeded"}}', $yt_pl ), null, 'YT 覆核：配額用盡不判下架' );
t_is( t_call( $muse_src, 'parse_alive', '<html>503</html>', $yt_pl ), null, 'YT 覆核：回應不是 JSON 不判下架' );
t_is( t_call( $muse_src, 'parse_alive', '{"pageInfo":{"totalResults":0}}', $yt_pl ), null, 'YT 覆核：缺 items 欄位不判下架' );
t_is( t_call( $muse_src, 'parse_alive', '{"items":[]}', 'https://www.youtube.com/@MuseTW' ), null, 'YT 覆核：非播放清單網址不判下架' );

// Accept 要換成 JSON；而且刻意不帶 allow_404，免得 endpoint 打錯被當成作品下架
t_is( t_call( $muse_src, 'end_date_fetch_opts' ), [ 'accept' => 'application/json' ], 'YT 覆核：Accept 用 JSON 且不帶 allow_404' );

// ─────────────────────────────────────────────────────────
// 5c. 本機不排程（2026-09-17）
//     串流每輪都要抓 sitemap 建索引（MyVideo 5.8 萬條目、LiTV 14 萬），
//     在開發機跑只會拖慢站台，資料還寫進本機獨立 DB、對正式站無用。
// ─────────────────────────────────────────────────────────
$sched_src = Anime_Sync_Streaming_Source_Base::make( 'muse' );

$asa_sched_case = static function ( string $env, string $home ) use ( $sched_src ): array {
    $GLOBALS['__env']     = $env;
    $GLOBALS['__home']    = $home;
    $GLOBALS['__sched']   = [];
    $GLOBALS['__unsched'] = [];
    $sched_src->schedule();
    return [ $GLOBALS['__sched'], $GLOBALS['__unsched'] ];
};

$H = 'anime_sync_streaming_source_muse';

[ $s, $u ] = $asa_sched_case( 'production', 'https://weixiaoacg.com' );
t_is( $s, [ $H ], '排程守門：正式站照常註冊排程' );

[ $s, $u ] = $asa_sched_case( 'local', 'https://weixiaoacg.com' );
t_is( $s, [], '排程守門：環境標為 local 就不註冊（即使網址是正式站）' );
// 只擋新註冊不夠：加守門之前排過的會一直留著，所以要順手清掉
t_is( $u, [ $H ], '排程守門：非正式站順手清掉既有排程' );

/*
 * ★ 最關鍵的一項：別人 clone 這個 repo 時 wp-config.php 不在版控裡，
 *   他的環境不會有 WP_ENVIRONMENT_TYPE='local'，wp_get_environment_type()
 *   會回 'production'。只認 'local' 的守門在那邊完全失效——這一項就是在守這件事。
 */
[ $s, $u ] = $asa_sched_case( 'production', 'https://wxacg.local' );
t_is( $s, [], '排程守門：別人的環境（未設 WP_ENVIRONMENT_TYPE）也不註冊' );
t_is( $u, [ $H ], '排程守門：別人的環境同樣會清掉既有排程' );

[ $s, ] = $asa_sched_case( 'production', 'https://www.weixiaoacg.com' );
t_is( $s, [ $H ], '排程守門：正式站帶 www. 前綴仍視為正式站' );

$GLOBALS['__env']  = 'production';            // 還原，免得影響後面的測試
$GLOBALS['__home'] = 'https://weixiaoacg.com';

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
// 5g. 下架時「不」通知會員（2026-09-16 使用者決定）
//     下架是每天都在發生的日常異動，逐筆推播會變成洗版。
//     只留 _ended_ 標記與作品頁的「近期下架」標示；
//     「到期前 14 天」的預告（notify_ending）保留，那是還來得及看完的資訊。
// ─────────────────────────────────────────────────────────
$base_src = file_get_contents( ANIME_SYNC_PRO_DIR . 'includes/class-streaming-source-base.php' );

t_is( strpos( $base_src, 'function notify_removed(' ) !== false, false, '下架不通知：notify_removed() 不存在' );
t_is( strpos( $base_src, '$this->notify_removed(' ) !== false, false, '下架不通知：沒有任何地方呼叫它' );
// 到期前的預告通知要保留
t_is( strpos( $base_src, 'protected function notify_ending(' ) !== false, true, '到期前預告：notify_ending() 保留' );

// ─────────────────────────────────────────────────────────
// 5h. 平台改名不等於下架（2026-09-22）
//     巴哈把「轉生成自動販賣機的我今天也在迷宮徘徊 第二季」列成不帶「第二季」的
//     名稱，站上 sn=49485 從台灣 IP 實抓 200 正常播放頁，卻被 check_gone() 標疑似
//     下架——它只拿標題去索引查，從不看站上那個網址還在不在索引裡。三輪後會刪掉
//     正確的網址。量到同類誤判：litv/bahamut 各 1、catchplay 2。
//
//     索引就是平台現況快照：網址還列著就代表作品還在，這比標題可靠。
// ─────────────────────────────────────────────────────────
$gone_src = Anime_Sync_Streaming_Source_Base::make( 'catchplay' );
$gone_url = 'https://www.catchplay.com/tw/video/abc123';
$gone_idx = [
	// 鍵是正規化後的標題；平台這輪把它列成「某作品」，站上標題是「某作品 第二季」
	t_call( $gone_src, 'normalize', '某作品' ) => [ [ 'u' => $gone_url ] ],
	t_call( $gone_src, 'normalize', '別部作品' ) => [ [ 'u' => 'https://www.catchplay.com/tw/video/zzz999' ] ],
];

t_set_index( $gone_src, $gone_idx );
t_is( count( t_call( $gone_src, 'index_urls' ) ), 2, 'index_urls()：蒐集索引裡的每一個網址' );
t_is( isset( t_call( $gone_src, 'index_urls' )[ $gone_url ] ), true, 'index_urls()：以網址為鍵，可直接 isset 查' );

// ── 網址仍在索引 → 救回，不得標記疑似下架 ──
t_meta_reset();
$GLOBALS['__meta'][ 501 ] = [
	'_anime_tw_streaming_src_catchplay' => 'auto',
	'anime_tw_streaming_url_catchplay'  => $gone_url,
];
t_set_index( $gone_src, $gone_idx );
$gone_r = t_call( $gone_src, 'check_gone', true );
t_is( $gone_r['rescued'], 1, '改名救回：標題配不到但網址仍在索引 → 算救回' );
t_is( $gone_r['marked'], 0, '改名救回：不標記疑似下架' );
t_is( isset( $GLOBALS['__meta'][501]['_anime_tw_streaming_gone_catchplay'] ), false, '改名救回：不寫 gone 標記' );
t_is( $GLOBALS['__meta'][501]['anime_tw_streaming_url_catchplay'], $gone_url, '改名救回：網址原封不動' );

// ── 已經被標過 gone，這輪救回要把標記清掉（前台才會拿掉「可能已下架」）──
t_meta_reset();
$GLOBALS['__meta'][ 502 ] = [
	'_anime_tw_streaming_src_catchplay'  => 'auto',
	'anime_tw_streaming_url_catchplay'   => $gone_url,
	'_anime_tw_streaming_gone_catchplay' => '2026-09-20|2',
];
t_set_index( $gone_src, $gone_idx );
$gone_r = t_call( $gone_src, 'check_gone', true );
t_is( isset( $GLOBALS['__meta'][502]['_anime_tw_streaming_gone_catchplay'] ), false, '改名救回：清掉既有的 gone 標記' );
t_is( $gone_r['cleared'], 1, '改名救回：算進「恢復」數' );

// ★ 最關鍵的一條：已累計到第 3 輪（滿 GONE_STRIKES）也不准刪，因為網址還在索引裡
t_meta_reset();
$GLOBALS['__meta'][ 503 ] = [
	'_anime_tw_streaming_src_catchplay'  => 'auto',
	'anime_tw_streaming_url_catchplay'   => $gone_url,
	'_anime_tw_streaming_gone_catchplay' => '2026-09-20|2',   // +1 = 3，原本這輪就會刪
];
t_set_index( $gone_src, $gone_idx );
$gone_r = t_call( $gone_src, 'check_gone', true );
t_is( $gone_r['removed'], 0, '改名救回：滿三輪也不刪（救援在累計 strike 之前）' );
t_is( $GLOBALS['__meta'][503]['anime_tw_streaming_url_catchplay'], $gone_url, '改名救回：滿三輪網址仍在' );

// ── 真下架（網址也不在索引裡）必須照舊標記，救援不能把下架偵測整條廢掉 ──
t_meta_reset();
$GLOBALS['__meta'][ 504 ] = [
	'_anime_tw_streaming_src_catchplay' => 'auto',
	'anime_tw_streaming_url_catchplay'  => 'https://www.catchplay.com/tw/video/gone404',
];
t_set_index( $gone_src, $gone_idx );
$gone_r = t_call( $gone_src, 'check_gone', true );
t_is( $gone_r['rescued'], 0, '真下架：網址不在索引 → 不救' );
t_is( $gone_r['marked'], 1, '真下架：照常標記疑似下架' );
t_is( $GLOBALS['__meta'][504]['_anime_tw_streaming_gone_catchplay'], gmdate( 'Y-m-d' ) . '|1', '真下架：記第 1 輪' );

// ── 沒有網址可比（欄位空）也不能救 ──
t_meta_reset();
$GLOBALS['__meta'][ 505 ] = [ '_anime_tw_streaming_src_catchplay' => 'auto' ];
t_set_index( $gone_src, $gone_idx );
$gone_r = t_call( $gone_src, 'check_gone', true );
t_is( $gone_r['rescued'], 0, '空網址：不得當成救回' );
t_is( $gone_r['marked'], 1, '空網址：照常標記' );

// 重建索引後網址集合必須跟著失效，否則救援拿上一輪的舊快照判斷
t_is( strpos( $base_src, '$this->index_urls = null;' ) !== false, true, 'save_index()：換索引時讓網址集合快取失效' );

t_meta_reset();

// ─────────────────────────────────────────────────────────
// 5i. 索引有缺口的來源不准跑下架偵測（2026-09-23）
//     救援只擋得住「平台改名」，擋不住「索引根本沒收這部作品」。
//     CatchPlay：寫過 167 筆有 102 筆（61%）網址不在索引裡，「星期一的豐滿」
//     兩筆連標題都沒有——但台灣 IP 實抓 200、og:title 正常，作品活著，
//     只是 CatchPlay 自己的 sitemap 沒列它。
//     bilibili：索引是 bangumi-data 的 ID 對照表，從來不是平台目錄。
// ─────────────────────────────────────────────────────────
$cover = static function ( string $key ): bool {
	$s = Anime_Sync_Streaming_Source_Base::make( $key );
	return (bool) t_call( $s, 'index_covers_platform' );
};

t_is( $cover( 'catchplay' ), false, '索引缺口：CatchPlay 不當下架偵測的依據' );
t_is( $cover( 'bilibili' ), false, '索引缺口：bangumi-data 系（bilibili）不當下架偵測的依據' );
t_is( $cover( 'bahamut' ), true, '索引缺口：巴哈的 sitemap 夠完整，維持偵測' );
t_is( $cover( 'garageplay' ), true, '索引缺口：車庫維持偵測' );

// ★ 兩個概念不能共用同一個方法：index_is_complete() 回 false 會讓 run_scheduled()
//   強制重建索引（佇列式爬蟲靠它推進），拿它來擋下架偵測會害這兩家每輪都重建。
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'catchplay' ), 'index_is_complete' ), true,
	'索引缺口：CatchPlay 的 index_is_complete() 維持 true（否則每輪強制重建）' );
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'bilibili' ), 'index_is_complete' ), true,
	'索引缺口：bilibili 的 index_is_complete() 維持 true' );
// 佇列式爬蟲的語意不得被波及
t_is( t_call( Anime_Sync_Streaming_Source_Base::make( 'ptsplus' ), 'index_covers_platform' ), true,
	'索引缺口：公視+ 爬完整站，涵蓋率不受影響' );

// check_gone 的守門條件必須三個都看
t_is( strpos( $base_src, '$this->provides_alive_check() && $this->index_is_complete() && $this->index_covers_platform()' ) !== false,
	true, '守門：check_gone() 同時看覆核能力、佇列完成、索引涵蓋率' );

// ─────────────────────────────────────────────────────────
// 6. 索引包基底：三家共用同一套讀檔與過期判斷
// ─────────────────────────────────────────────────────────
foreach ( [ 'bahamut', 'garageplay', 'catchplay' ] as $key ) {
	$src = Anime_Sync_Streaming_Source_Base::make( $key );
	t_is( $src instanceof Anime_Sync_Streaming_Source_Bundle_Base, true, "索引包基底：{$key} 繼承 Bundle_Base" );
	$info = $src->bundle_info();
	t_is( is_array( $info ) && array_key_exists( 'stale', $info ), true, "索引包基底：{$key} 的 bundle_info() 有 stale 旗標" );
}

// ─────────────────────────────────────────────────────────
// 6a. 佇列式爬蟲每輪都要推進（2026-09-21）
//
// run_locked() 原本只用「距上次建索引滿 6 天」決定要不要重建，
// 結果佇列式來源首輪抓完一批後就凍住：LINE TV 索引建於 9/16，
// 9/21 當天跑 5 次全是「索引 361 部」原地踏步（已抓 4,271／待抓 2,871）。
// 修法是「索引未完成就強制重建」，而這條的安全性完全依賴下面這個前提——
// 非佇列式來源的 index_is_complete() 必須是 true，否則它們會每輪重抓整包。
// ─────────────────────────────────────────────────────────
foreach ( [ 'linetv' => true, 'ptsplus' => true,
            'myvideo' => false, 'hami' => false, 'ofiii' => false,
            'litv' => false, 'friday' => false, 'bahamut' => false ] as $key => $is_queue ) {
	$src = Anime_Sync_Streaming_Source_Base::make( $key );
	t_is( t_call( $src, 'incremental' ), $is_queue, "{$key}：" . ( $is_queue ? '佇列式增量' : '整包型' ) );
	if ( ! $is_queue ) {
		t_is(
			t_call( $src, 'index_is_complete' ),
			true,
			"{$key}：整包型來源必須回報索引已完成（否則會每輪重抓整包）"
		);
	}
}

// ─────────────────────────────────────────────────────────
// 6b. Apple TV：改版後「沒有商店頻道」不能再當成買不到
//     （2026-09-20：三個仍可觀看的作品頁被判死、卡在第 2/3 輪，差一輪就被刪）
// ─────────────────────────────────────────────────────────
$atv = Anime_Sync_Streaming_Source_Base::make( 'appletv' );

// 舊結構仍然照舊判斷
t_is(
	t_call( $atv, 'parse_alive', '{"channelId":"tvs.sbd.9001"}', 'https://tv.apple.com/tw/movie/x/umc.cmc.a' ),
	true,
	'Apple TV：舊結構有 iTunes 商店頻道 → 還在'
);
t_is(
	t_call( $atv, 'parse_alive', '{"channelId":"tvs.sbd.1234","canonicalId":"x"}', 'https://tv.apple.com/tw/movie/x/umc.cmc.a' ),
	false,
	'Apple TV：舊結構但沒有商店頻道 → 目錄頁，買不到'
);

/*
 * ★ 這一項是這次修正的核心。
 *   改版後的頁面沒有 "channelId"，卻有 umc.cmc.（還有每頁都在的 Apple TV+ 品牌區塊
 *   "target":{"id":"tvs.sbd.4000","type":"Brand"}）。舊寫法會掉進 umc.cmc. 那條回 false，
 *   把 133 筆全部誤殺。缺少判斷依據時要回 null，不是回 false。
 */
t_is(
	t_call(
		$atv,
		'parse_alive',
		'{"id":"umc.cmc.tsj0o2owpb1ci5dahxm4wja5","target":{"id":"tvs.sbd.4000","type":"Brand"},"title":"Apple TV"}',
		'https://tv.apple.com/tw/show/x/umc.cmc.tsj0o2owpb1ci5dahxm4wja5'
	),
	null,
	'Apple TV：新結構沒有 channelId → 不下結論（不能判死）'
);

// 品牌區塊裡的 tvs.sbd.4000 不是上架證據，不能拿來判活
t_is(
	t_call( $atv, 'parse_alive', '{"target":{"id":"tvs.sbd.4000","type":"Brand"}}', 'https://tv.apple.com/tw/movie/x/umc.cmc.a' ),
	null,
	'Apple TV：品牌區塊的 tvs.sbd.4000 不算上架證據'
);

// 非台灣商店不必抓頁面就知道無效
t_is(
	t_call( $atv, 'parse_alive', '{"channelId":"tvs.sbd.9001"}', 'https://tv.apple.com/us/movie/x/umc.cmc.a' ),
	false,
	'Apple TV：/us/ 連結對台灣讀者無效'
);

// 真正下架靠 404，與 parse_alive 無關——這條路徑斷了上面的寬鬆判斷就會變成永不下架
t_is( t_call( $atv, 'alive_missing_codes' ), [ 404 ], 'Apple TV：不存在的頁面回 404，仍偵測得到真下架' );

// ─────────────────────────────────────────────────────────
// 7. 公視+：語言版本後綴、多節目取捨、軟性 404
//    （2026-09-19 從主機實測的行為，改動任何一項都要先重測再改這裡）
// ─────────────────────────────────────────────────────────
$pts = Anime_Sync_Streaming_Source_Base::make( 'ptsplus' );

$pts_page = static function ( string $title ): string {
	return '<html><head><meta property="og:title" content="公視+ | ' . $title . '"/></head></html>';
};

// work_name：去語言版本後綴，站上「搖曳露營△」才對得上
t_is( t_call( $pts, 'work_name', '搖曳露營△（雙語版）' ), '搖曳露營△', '公視 work_name：去全形（雙語版）' );
t_is( t_call( $pts, 'work_name', '熊星人和地球人(台語版)' ), '熊星人和地球人', '公視 work_name：去半形(台語版)' );
t_is( t_call( $pts, 'work_name', '葬送的芙莉蓮' ), '葬送的芙莉蓮', '公視 work_name：沒有後綴就原樣' );

/*
 * 破折號不能當切點：公視有不少節目名本身就含破折號，
 * 切下去會把真名截斷成「歐吉桑騎士」。
 */
t_is(
	t_call( $pts, 'work_name', '歐吉桑騎士 – 阿順阿忠的中年危機' ),
	'歐吉桑騎士 – 阿順阿忠的中年危機',
	'公視 work_name：名稱裡的破折號不能當切點'
);

// pick_entry：原版（無後綴）優先，其次雙語版
$pts_entries = [
	[ 'title' => '葬送的芙莉蓮（台語版）', 'url' => 'https://www.ptsplus.tv/zh/programs/aaa', 'date' => '' ],
	[ 'title' => '葬送的芙莉蓮（雙語版）', 'url' => 'https://www.ptsplus.tv/zh/programs/bbb', 'date' => '' ],
	[ 'title' => '葬送的芙莉蓮',           'url' => 'https://www.ptsplus.tv/zh/programs/ccc', 'date' => '' ],
];
t_is( t_call( $pts, 'pick_entry', $pts_entries )['url'], 'https://www.ptsplus.tv/zh/programs/ccc', '公視 pick_entry：原版優先' );
t_is(
	t_call( $pts, 'pick_entry', [ $pts_entries[0], $pts_entries[1] ] )['url'],
	'https://www.ptsplus.tv/zh/programs/bbb',
	'公視 pick_entry：沒有原版時取雙語版'
);

/*
 * ★ 這三項是這個來源最容易出事的地方：公視對不存在的節目回 HTTP 200，
 *   只有 og:title 變成「404」。存活判斷若看狀態碼，死連結會全部被判成活的。
 */
t_is( t_call( $pts, 'parse_alive', $pts_page( '404' ), '' ), false, '公視 parse_alive：og:title 是 404 → 已下架' );
t_is( t_call( $pts, 'parse_alive', $pts_page( '葬送的芙莉蓮' ), '' ), true, '公視 parse_alive：正常節目 → 還在' );
t_is( t_call( $pts, 'parse_alive', '<html><head></head></html>', '' ), null, '公視 parse_alive：沒有 og:title → 不下結論' );

t_is( t_call( $pts, 'parse_page', $pts_page( '404' ) ), null, '公視 parse_page：404 頁不進索引' );
t_is( t_call( $pts, 'parse_page', $pts_page( '國王排名' ) )['title'], '國王排名', '公視 parse_page：取得節目名' );
t_is( t_call( $pts, 'provides_alive_check' ), true, '公視：可以從主機判斷存活' );

// 平台標籤不該再寫死「台語版」——站上放的是節目主頁，所有語言版本的共同入口
t_is( Anime_Sync_Streaming_Registry::get_acf_choices()['ptsplus'] ?? '', '公視+', '公視：平台標籤是「公視+」不是「公視(台語版)」' );

exit( t_report() );
