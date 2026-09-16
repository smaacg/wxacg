<?php
/**
 * 檔案名稱: tests/test-youtube-playlist-sync.php
 * YouTube 播放清單同步：標題解析規則的回歸測試（純函式，不連網、不碰資料庫）
 *
 * 執行：
 *   "C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" ^
 *     -d extension_dir="C:\Users\Pju\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext" ^
 *     -d extension=php_mbstring.dll ^
 *     "F:\fuck\app\public\wp-content\plugins\anime-sync-pro\tests\test-youtube-playlist-sync.php"
 *
 * 案例全部取自正式站 anime_online_watch 的實際資料
 * （2026-09-17 全量匯出 878 篇、15,614 行分析所得），不是憑空想的。
 * 改任何解析規則之前先跑一次，改完再跑一次。
 *
 * @package Anime_Sync_Pro
 */

require __DIR__ . '/bootstrap.php';

$yt = new Anime_Sync_YouTube_Playlist_Sync();

// ─────────────────────────────────────────────────────────
// 1. 中文數字集數
//    post 57390《我立於百萬生命之上》12 支影片全部卡在這裡：
//    解析不出集數 → 退回 YouTube 原標題 → 前端十二個晶片都截成「《我立…」。
// ─────────────────────────────────────────────────────────
$ani_one = static function ( string $ep ): string {
	return "《我立於百萬生命之上》- {$ep} |【Ani-One】(日語原聲 | 繁體中文字幕)";
};

t_is( t_call( $yt, 'extract_episode_range', $ani_one( '第一話' ) ),   [ 1, 1 ],   '中文數字：第一話' );
t_is( t_call( $yt, 'extract_episode_range', $ani_one( '第九話' ) ),   [ 9, 9 ],   '中文數字：第九話' );
t_is( t_call( $yt, 'extract_episode_range', $ani_one( '第十話' ) ),   [ 10, 10 ], '中文數字：第十話' );
// 十一以上是重點：既有的 convert_cn_season_numerals() 正好在這裡失效，不能複用
t_is( t_call( $yt, 'extract_episode_range', $ani_one( '第十一話' ) ), [ 11, 11 ], '中文數字：第十一話（組合寫法）' );
t_is( t_call( $yt, 'extract_episode_range', $ani_one( '第十二話' ) ), [ 12, 12 ], '中文數字：第十二話（組合寫法）' );

// cn_numeral_to_int 直接測，邊界蓋到 1～99
t_is( t_call( $yt, 'cn_numeral_to_int', '八' ),     8,  'cn→int：個位數' );
t_is( t_call( $yt, 'cn_numeral_to_int', '十' ),     10, 'cn→int：十' );
t_is( t_call( $yt, 'cn_numeral_to_int', '十二' ),   12, 'cn→int：十二' );
t_is( t_call( $yt, 'cn_numeral_to_int', '二十' ),   20, 'cn→int：二十' );
t_is( t_call( $yt, 'cn_numeral_to_int', '二十四' ), 24, 'cn→int：二十四' );
t_is( t_call( $yt, 'cn_numeral_to_int', '九十九' ), 99, 'cn→int：九十九' );
t_is( t_call( $yt, 'cn_numeral_to_int', '零' ),     0,  'cn→int：認不出回 0，不亂猜' );

// 只在「第…話／集／回／幕」結構內替換，不得動到作品名裡的中文數字
t_is(
	t_call( $yt, 'convert_cn_episode_numerals', '第三王子的逆襲' ),
	'第三王子的逆襲',
	'不轉換：後面沒有話／集／回／幕單位'
);
t_is(
	t_call( $yt, 'convert_cn_episode_numerals', '十二國記 第一話' ),
	'十二國記 第1話',
	'只轉集數：作品名裡的「十二」不動'
);

// ─────────────────────────────────────────────────────────
// 2. 日文標題走同一條路（post 4080 香格里拉迷你動畫）
// ─────────────────────────────────────────────────────────
t_is(
	t_call( $yt, 'extract_episode_range', 'TVアニメ『シャングリラ・フロンティア』ミニアニメ_第八話「『シャンフロ』の主人公 パート2 」' ),
	[ 8, 8 ],
	'日文標題：_第八話'
);

// ─────────────────────────────────────────────────────────
// 3. 作品自己的單位：《夜櫻家大作戰》用「作戰01」（post 1716，39 行）
// ─────────────────────────────────────────────────────────
t_is( t_call( $yt, 'extract_episode_number', '夜櫻家大作戰 作戰01【櫻之戒】｜Muse木棉花 動畫 線上看' ), 1, '作戰01' );
t_is( t_call( $yt, 'extract_episode_number', '夜櫻家大作戰 作戰04【辛三/嫌五】｜Muse木棉花 動畫 線上看' ), 4, '作戰04' );

// ─────────────────────────────────────────────────────────
// 4. 集數包在方括號、省掉「第」：「【1話】…」
// ─────────────────────────────────────────────────────────
t_is( t_call( $yt, 'extract_episode_number', '【1話】ピョン吉が張り付いたのに 全く驚かないひろし【スキマノアニメ】' ), 1, '【1話】' );

// ─────────────────────────────────────────────────────────
// 5. 阿拉伯數字本來就會過，不得被上面的改動弄壞（回歸）
// ─────────────────────────────────────────────────────────
t_is( t_call( $yt, 'extract_episode_range', '《藥師少女的獨語》第12話 (繁中字幕 | 日語原聲)【Ani-One】' ), [ 12, 12 ], '回歸：第12話' );
t_is( t_call( $yt, 'extract_episode_number', '《某作品》#7 (繁中字幕)' ), 7, '回歸：#7' );
t_is( t_call( $yt, 'extract_episode_number', 'Some Anime EP11 - Title' ), 11, '回歸：EP11' );

/*
 * 「第1~24合集」目前抓不到：範圍規則要求數字後面緊接 話／集／回／幕，
 * 而這裡接的是「合集」。這是已知缺口，刻意不修——它其實是整季合輯，
 * 標成「第1-24話」並不精確。把現況釘在測試裡，將來要改是有意識地改。
 */
t_is(
	t_call( $yt, 'extract_episode_range', '《斬！赤紅之瞳》第1~24合集 (繁中字幕 | 日語原聲)【Ani-One】' ),
	null,
	'已知缺口：第1~24合集抓不到（刻意）'
);

// ─────────────────────────────────────────────────────────
// 6. 沒有集數的特殊影片 → 簡化成短標籤
// ─────────────────────────────────────────────────────────
t_is( t_call( $yt, 'guess_special_label', '【クロミアニメ】最終話「さよならロミナ」' ), '最終話', '最終話（要排在 sp 之前）' );
t_is( t_call( $yt, 'guess_special_label', '《某作品》OVA (繁中字幕)' ), 'OVA', 'OVA' );

/*
 * 真的認不出就保留原標題，維持既有行為、不亂猜。
 * post 4240《ちびゴジラの逆襲》116 支影片全靠副標分辨，標題裡根本沒有集數；
 * 這種情況由前端的「第 N 部」退路處理（見 single-anime.php 的 label 長度上限）。
 */
$chibi = '【公式】TVアニメ『ちびゴジラの逆襲』「怪獣島のちびゴジラ」';
t_is( t_call( $yt, 'extract_episode_range', $chibi ), null,   '無集數：range 回 null' );
t_is( t_call( $yt, 'guess_special_label', $chibi ),   $chibi, '無集數：保留原標題（前端改顯示「第 N 部」）' );

// ─────────────────────────────────────────────────────────
// 7. PV 黑名單：主題曲／插入曲／短剪輯要被擋下（2026-09-17 新增）
//    加之前實測過：全站 15,614 行裡這些關鍵字命中 38 行，
//    其中 0 行同時帶正常集數，所以不會誤殺正片。
// ─────────────────────────────────────────────────────────
t_is( t_call( $yt, 'is_pv_title', 'YOASOBI X《機動戰士鋼彈 水星的魔女》主題曲「祝福」【Ani-One】' ), true, '黑名單：主題曲' );
t_is( t_call( $yt, 'is_pv_title', 'F/ACE-《RAIN》——《現在的是哪一個多聞!?》插入曲【Ani-One 】' ), true, '黑名單：插入曲' );
t_is( t_call( $yt, 'is_pv_title', '＼僕は、強くなる……！／｜#LV999の村人 #切り抜き  #shorts  #アニメ' ), true, '黑名單：#shorts／切り抜き' );

// 不得誤殺正片
t_is( t_call( $yt, 'is_pv_title', $ani_one( '第一話' ) ), false, '黑名單：不誤殺正片（中文數字）' );
t_is( t_call( $yt, 'is_pv_title', '《藥師少女的獨語》第12話 (繁中字幕 | 日語原聲)【Ani-One】' ), false, '黑名單：不誤殺正片（阿拉伯數字）' );

exit( t_report() );
