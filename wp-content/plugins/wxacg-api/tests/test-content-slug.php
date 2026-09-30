<?php
/**
 * 檔案名稱: tests/test-content-slug.php
 * 新聞稿日期 slug 規則的回歸測試（純函式，不連網、不碰資料庫）
 *
 * 執行（Windows + Git Bash）：
 *   PHPDIR=$(ls -d "$APPDATA"/Local/lightning-services/php-*\/bin/win64 | sort -V | tail -1)
 *   "$PHPDIR/php.exe" -d extension_dir="$PHPDIR/ext" -d extension=mbstring \
 *     wp-content/plugins/wxacg-api/tests/test-content-slug.php
 *
 * 為什麼不用 PHPUnit
 * ------------------
 * 與 anime-sync-pro/tests 同樣的理由：要測的是「輸入什麼 → post_name 變成什麼」
 * 的判斷邏輯，不碰資料庫也不發請求。這裡只補 filter_insert_post_data() 實際
 * 會呼叫到的幾個 WordPress 函式。
 *
 * 這些案例存在的理由
 * ------------------
 * filter_insert_post_data() 掛在 wp_insert_post_data，那個 filter 在「更新」
 * 文章時同樣會跑。無條件覆寫 post_name 會造成每存一次檔就換一次網址；
 * 反過來「只要已是日期格式就完全不碰 $data」則會在 pending 情境把 slug
 * 寫成空字串（見案例 3 的說明）。兩個方向都要被測住。
 *
 * @package WxacgApi
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( "只能在命令列執行\n" );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

define( 'WEIXIAOACG_ID_CATS',  [ 'announcement', 'news' ] );
define( 'WEIXIAOACG_LLM_CATS', [ 'review' ] );

/* ──────────────────────────────────────────────
 * WordPress 替身
 * ────────────────────────────────────────────── */

/** 每個 post_id 對應的分類 slug 與資料庫裡現有的 post_name */
$GLOBALS['stub_posts'] = [];

function wp_get_post_categories( $post_id ) {
    // 直接把分類 slug 當 term_id 用，get_term() 再原樣還原，省掉一層對照表
    return $GLOBALS['stub_posts'][ $post_id ]['cats'] ?? [];
}

function get_term( $tid, $taxonomy = '' ) {
    return (object) [ 'slug' => $tid ];
}

function is_wp_error( $thing ) {
    return false;
}

function get_post_field( $field, $post_id, $context = 'display' ) {
    return $GLOBALS['stub_posts'][ $post_id ][ $field ] ?? '';
}

/** 固定回傳值，測試才能斷言「有沒有換新的」 */
function current_time( $format ) {
    return '19990101-000000';
}

function wp_rand( $min = 0, $max = 0 ) {
    return 111;
}

/*
 * 非 ID 系列分類會走 Gemini 分支。這裡不設 WEIXIAOACG_GEMINI_API_KEY，
 * gemini_slug() 會在取不到金鑰時 error_log 後回 false，slug 保持原樣——
 * 正是「不該被日期 slug 覆蓋」要驗的結果。快取函式補成永遠 miss。
 */
function get_transient( $key ) {
    return false;
}

function set_transient( $key, $value, $ttl = 0 ) {
    return true;
}

// 把 gemini_slug() 的 error_log 導到暫存檔，測試輸出才乾淨
ini_set( 'error_log', sys_get_temp_dir() . '/wxacg-api-test-content-slug.log' );

const GENERATED = '19990101-000000-111';   // 上面兩個替身組出來的「新產生的 slug」

require_once dirname( __DIR__ ) . '/includes/class-content-slug.php';

/* ──────────────────────────────────────────────
 * 測試案例
 * ────────────────────────────────────────────── */

$obj    = new Wxacg_Api_Content_Slug();
$passed = 0;
$failed = 0;

/**
 * @param string $desc     案例說明
 * @param array  $stub     這篇文章在資料庫裡的樣子：['cats' => [...], 'post_name' => '...']
 * @param array  $data     進到 filter 的 $data
 * @param array  $postarr  進到 filter 的 $postarr
 * @param string $expect   預期的 $data['post_name']
 */
function t( string $desc, array $stub, array $data, array $postarr, string $expect ): void {
    global $obj, $passed, $failed;

    $post_id = $postarr['ID'] ?? 0;
    $GLOBALS['stub_posts'] = $post_id ? [ $post_id => $stub ] : [];

    $result = $obj->filter_insert_post_data( $data, $postarr );
    $actual = $result['post_name'] ?? '(沒有 post_name)';

    if ( $actual === $expect ) {
        $passed++;
        printf( "  ✅ %s\n", $desc );
    } else {
        $failed++;
        printf( "  ❌ %s\n       預期「%s」實際「%s」\n", $desc, $expect, $actual );
    }
}

$date_slug = '20260930-032948-700';
$news      = [ 'cats' => [ 'news' ] ];

echo "新聞稿日期 slug 規則\n";

// 新建：還沒有 post_id，一定要產生
t( '新建文章 → 產生日期 slug',
    [], [ 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => '' ],
    [ 'post_category' => [ 'news' ] ], GENERATED );

// 一般更新：這是「每存一次檔就換一次網址」那個 bug 的直接測項
t( '更新文章、現有已是日期 slug → 維持原值',
    $news + [ 'post_name' => $date_slug ],
    [ 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => $date_slug ],
    [ 'ID' => 101 ], $date_slug );

/*
 * pending 迴歸：wp_insert_post() 在狀態為 pending 且當下使用者對該篇沒有
 * publish_post 權限時（沒有登入使用者的 cron／CLI 也算），會先把 $post_name
 * 清成空字串；寫入後的補救段落又刻意排除 pending，不會補回來。
 * 所以這裡必須「明確寫回原值」，只是不動 $data 會讓空字串進資料庫。
 */
t( 'pending 把 post_name 清空 → 仍寫回原本的日期 slug',
    $news + [ 'post_name' => $date_slug ],
    [ 'post_type' => 'post', 'post_status' => 'pending', 'post_name' => '' ],
    [ 'ID' => 102 ], $date_slug );

// 現有 slug 不是日期格式（例如分類後來才改成新聞）→ 要補上
t( '現有 slug 不是日期格式 → 產生日期 slug',
    $news + [ 'post_name' => 'some-manual-slug' ],
    [ 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'some-manual-slug' ],
    [ 'ID' => 103 ], GENERATED );

/*
 * 丟垃圾桶：核心會在本 filter 之前就把 __trashed 後綴寫進資料庫並清快取，
 * 所以這裡讀到的是帶後綴的值，不符合日期格式 → 產生新的。
 * 與修正前行為相同，列在這裡是為了把這個已知行為釘住。
 */
t( '垃圾桶（現有 slug 帶 __trashed）→ 產生日期 slug',
    $news + [ 'post_name' => $date_slug . '__trashed' ],
    [ 'post_type' => 'post', 'post_status' => 'trash', 'post_name' => $date_slug . '__trashed' ],
    [ 'ID' => 104 ], GENERATED );

// 不是 ID 系列分類 → 不該被日期 slug 覆蓋（這篇分類是 review，走 LLM 分支）
t( '非 ID 系列分類 → 不套用日期 slug',
    [ 'cats' => [ 'review' ], 'post_name' => 'english-slug' ],
    [ 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'english-slug', 'post_title' => '測試標題' ],
    [ 'ID' => 105 ], 'english-slug' );

// 開頭兩道 guard
t( '非 post 文章類型 → 原樣返回',
    $news + [ 'post_name' => $date_slug ],
    [ 'post_type' => 'anime', 'post_status' => 'publish', 'post_name' => 'liar-game' ],
    [ 'ID' => 106 ], 'liar-game' );

t( 'auto-draft → 原樣返回',
    $news + [ 'post_name' => '' ],
    [ 'post_type' => 'post', 'post_status' => 'auto-draft', 'post_name' => '' ],
    [ 'ID' => 107 ], '' );

t( 'revision（post_status 為 inherit）→ 原樣返回',
    $news + [ 'post_name' => '101-revision-v1' ],
    [ 'post_type' => 'post', 'post_status' => 'inherit', 'post_name' => '101-revision-v1' ],
    [ 'ID' => 108 ], '101-revision-v1' );

echo "\n";
if ( $failed === 0 ) {
    echo "通過 {$passed} 項，全部通過 ✓\n";
    exit( 0 );
}
echo "通過 {$passed} 項，失敗 {$failed} 項 ✗\n";
exit( 1 );
