<?php
/**
 * 微笑動漫 Child Theme — functions.php
 * @version 2.24.1 (2026-06-28)
 * Changelog：
 *   2.24.1 (2026-06-28) 底部導航「會員」未登入時觸發 login modal，不跳 wp-login
 *   2.23.0 (2026-06-11) Email 驗證獎勵搬遷：
 *      - 移除 smacg_email_verified action 內的發獎邏輯
 *        （Lv.10 5000 EXP 補滿、歡迎通知、bonus 記錄）
 *        改由 wxacg-gamification 外掛的 Exp_Events::on_email_verified 統一處理
 *      - 移除 nsl_register_new_user / nsl_login / wp_login 三層 NSL 兜底
 *        改由 Exp_Events 統一處理
 *      - 保留 smacg_user_needs_email_verify() 判斷函式（共用 helper）
 *      - 保留 smacg_push_email_verify_notification() 通知推送
 *      - 保留 resend_verify footer 腳本
 *   2.22.0 (2026-06-01) Bug 修正
 *   2.20.0 (2026-05-27) Footer v1.4.0
 *   2.19.0 (2026-05-26) 動漫關聯影評／前導文章
 *   2.18.0 (2026-05-26) 全站文字白色化
 *   2.17.0 (2026-05-23) 註冊防護：Honeypot + IP 限制 + 密碼強度 + Email 驗證
 *   2.16.0 (2026-05-18) Bangumi 模組
 *   2.15.0 (2026-05-16) ajax-news-filter
 *   2.14.0 (2026-05-15) Phase 3-A：會員核心搬遷至 wxacg-members
 *   2.13.0 (2026-05-15) 整合 wxacg-api
 *   2.12.0 (2026-05-15) Phase 2：gamification 模組搬遷
 *   2.7.3  (2026-05-14) season-event 模組
 */
defined( 'ABSPATH' ) || exit;

define( 'weixiaoacg_VERSION',   '2.25.0' );
define( 'weixiaoacg_THEME_URL', get_stylesheet_directory_uri() );
define( 'weixiaoacg_THEME_DIR', get_stylesheet_directory() );

/* === 點數常數 === */
define( 'SMACG_POINT_FAVORITE',  5  );
define( 'SMACG_POINT_WANT',      1  );
define( 'SMACG_POINT_WATCHING',  3  );
define( 'SMACG_POINT_COMPLETED', 8  );
define( 'SMACG_POINT_FULLCLEAR', 10 );
define( 'SMACG_POINT_EPISODE',   1  );
define( 'SMACG_POINT_READ',      2  );
define( 'SMACG_POINT_COMMENT',   3  );
define( 'SMACG_POINT_LOGIN',     1  );

define( 'WXACG_FOLLOW_DAILY_LIMIT', 200 );
define( 'WXACG_FOLLOW_COOLDOWN',    1   );

/* === 註冊防護常數（v2.17.0）=== */
if ( ! defined( 'SMACG_REGISTER_IP_DAILY_LIMIT' ) ) define( 'SMACG_REGISTER_IP_DAILY_LIMIT', 3   );
if ( ! defined( 'SMACG_REGISTER_COOLDOWN' ) )       define( 'SMACG_REGISTER_COOLDOWN',       60  );
if ( ! defined( 'SMACG_REGISTER_PW_MIN_LEN' ) )     define( 'SMACG_REGISTER_PW_MIN_LEN',     8   );
if ( ! defined( 'SMACG_REGISTER_USERNAME_MIN' ) )   define( 'SMACG_REGISTER_USERNAME_MIN',   3   );
if ( ! defined( 'SMACG_REGISTER_USERNAME_MAX' ) )   define( 'SMACG_REGISTER_USERNAME_MAX',   20  );
if ( ! defined( 'SMACG_REGISTER_VERIFY_EXPIRE' ) )  define( 'SMACG_REGISTER_VERIFY_EXPIRE',  DAY_IN_SECONDS );
if ( ! defined( 'SMACG_REGISTER_UNVERIFIED_TTL' ) ) define( 'SMACG_REGISTER_UNVERIFIED_TTL', 7 * DAY_IN_SECONDS );

/* === 內容分類常數 === */
const WEIXIAOACG_ID_CATS  = [ 'announcement', 'news' ];
const WEIXIAOACG_LLM_CATS = [ 'review', 'feature' ];

/* === 保險常數 === */
if ( ! defined( 'WXACG_BADGE_SLUG' ) ) define( 'WXACG_BADGE_SLUG', 'badge' );
if ( ! defined( 'WXACG_EVENT_CPT' ) )  define( 'WXACG_EVENT_CPT',  'wxacg_season_event' );

$inc = weixiaoacg_THEME_DIR . '/inc/';

/* === 主載入：主題層級核心 === */
foreach ( [
    'setup-theme',
    'class-nav-walker',
    'setup-enqueue',
    'bangumi-loader',
] as $f ) {
    require_once $inc . $f . '.php';
}


/* === 選擇性載入 === */
$optional = [
    'image-optimizer',
    'ajax-news-filter',
    'search-hot',        // ★v2.25.0 熱門/最近搜尋 REST（有 file_exists 保護）
];


foreach ( $optional as $f ) {
    $path = $inc . $f . '.php';
    if ( file_exists( $path ) ) require_once $path;
}

unset( $inc, $optional, $f, $path );

/* === 外掛狀態檢查 === */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'activate_plugins' ) ) return;
    $missing = [];
    if ( ! defined( 'WXACG_GAMIFY_VERSION' ) )  $missing[] = 'SMACG Gamification';
    if ( ! defined( 'WXACG_API_VERSION' ) )     $missing[] = 'SMACG API';
    if ( ! defined( 'WXACG_MEMBERS_VERSION' ) ) $missing[] = 'SMACG Members';
    if ( empty( $missing ) ) return;
    echo '<div class="notice notice-error"><p><strong>weixiaoacg 主題：</strong>'
       . '以下外掛未啟用，相關功能將完全停用：<code>'
       . esc_html( implode( '、', $missing ) )
       . '</code>。請至 <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">外掛頁</a> 啟用。</p></div>';
} );


/**
 * 搜尋結果分類篩選（全部 / 資料庫 / 文章）
 * ?stype=db   → 只搜 anime + manga（資料庫）
 * ?stype=post → 只搜 post（文章）
 * 無參數      → 全部
 * @since 2.26.0
 */
add_action( 'pre_get_posts', function ( $q ) {
    if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) {
        return;
    }
    $stype = isset( $_GET['stype'] ) ? sanitize_key( $_GET['stype'] ) : '';

    if ( $stype === 'db' ) {
        $q->set( 'post_type', [ 'anime', 'manga' ] );
    } elseif ( $stype === 'post' ) {
        $q->set( 'post_type', [ 'post' ] );
    }
    // 其他情況（全部）：不動，維持預設搜尋範圍
} );


/* ============================================================
 * 抽一部動漫：改用 admin-ajax.php 端點（v2.24.2 2026-07-18）
 * ------------------------------------------------------------
 * 原本用 ?wxacg_random_anime=1 + template_redirect 的做法，
 * 「沒登入的訪客」會被快取外掛／CDN 抓到快取版首頁，
 * PHP 根本沒執行到判斷式，點了沒反應；已登入的人因為快取
 * 外掛通常會略過快取，所以才會看起來「登入才有用」。
 * admin-ajax.php 幾乎所有快取外掛都預設不快取，
 * 改走這裡可以保證每次點擊都是即時執行、不受登入狀態或快取影響。
 * ============================================================ */
function wxacg_random_anime_redirect() {
    $ids = get_posts( [
        'post_type'        => 'anime',
        'post_status'      => 'publish',
        'posts_per_page'   => 1,
        'orderby'          => 'rand',
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ] );

    if ( ! empty( $ids[0] ) ) {
        wp_safe_redirect( get_permalink( (int) $ids[0] ), 302 );
        exit;
    }

    wp_safe_redirect( home_url( '/anime/' ), 302 );
    exit;
}
add_action( 'wp_ajax_wxacg_random_anime',        'wxacg_random_anime_redirect' );
add_action( 'wp_ajax_nopriv_wxacg_random_anime', 'wxacg_random_anime_redirect' );

/* 舊網址 /?wxacg_random_anime=1 保留 fallback，避免舊連結／書籤失效 */
add_action( 'template_redirect', function () {
    if ( empty( $_GET['wxacg_random_anime'] ) || is_admin() ) {
        return;
    }
    wxacg_random_anime_redirect();
} );

/* ============================================================
 * 熱門標籤管理
 * ============================================================ */
add_action( 'admin_menu', function () {
    add_theme_page(
        '熱門標籤',
        '熱門標籤',
        'manage_options',
        'smacg-popular-tags',
        'smacg_popular_tags_page'
    );
} );

function smacg_popular_tags_page() {
    if ( isset( $_POST['smacg_popular_tags_nonce'] )
      && wp_verify_nonce( $_POST['smacg_popular_tags_nonce'], 'smacg_save_popular_tags' ) ) {
        $raw   = wp_unslash( $_POST['smacg_popular_tags'] ?? '' );
        $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
        update_option( 'smacg_popular_tags', $lines );
        echo '<div class="notice notice-success"><p>已儲存。</p></div>';
    }

    $current = get_option( 'smacg_popular_tags', [] );
    $value   = is_array( $current ) ? implode( "\n", $current ) : '';
    ?>
    <div class="wrap">
      <h1>熱門標籤管理</h1>
      <p>每行輸入一個標籤名稱或 slug，將顯示於新聞頁與文章側欄。</p>
      <p>建議 10–15 個，依時事更新。例如：<code>鬼滅之刃</code>、<code>新番</code>、<code>COSPLAY</code>。</p>
      <form method="post">
        <?php wp_nonce_field( 'smacg_save_popular_tags', 'smacg_popular_tags_nonce' ); ?>
        <textarea name="smacg_popular_tags" rows="20" cols="50"
          style="width:400px;font-family:monospace;"
          placeholder="鬼滅之刃&#10;新番情報&#10;COSPLAY&#10;聲優&#10;..."><?php echo esc_textarea( $value ); ?></textarea>
        <p><?php submit_button( '儲存熱門標籤' ); ?></p>
      </form>
      <hr>
      <h2>目前站內標籤使用量前 30 名（參考用）</h2>
      <?php
      $stats = get_tags( [ 'orderby' => 'count', 'order' => 'DESC', 'number' => 30, 'hide_empty' => true ] );
      if ( $stats ) {
          echo '<ul style="columns:3;">';
          foreach ( $stats as $t ) {
              printf( '<li><code>%s</code>（%d 篇）</li>',
                  esc_html( $t->name ), (int) $t->count );
          }
          echo '</ul>';
      }
      ?>
    </div>
    <?php
}

function smacg_get_popular_tags( $limit = 15 ) {
    $configured = get_option( 'smacg_popular_tags', [] );
    $tags       = [];

    if ( ! empty( $configured ) && is_array( $configured ) ) {
        foreach ( $configured as $name ) {
            $term = get_term_by( 'name', $name, 'post_tag' )
                 ?: get_term_by( 'slug', $name, 'post_tag' );
            if ( $term && ! is_wp_error( $term ) ) {
                $tags[] = $term;
            }
            if ( count( $tags ) >= $limit ) break;
        }
    }

    if ( empty( $tags ) ) {
        $tags = get_tags( [
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => $limit,
            'hide_empty' => true,
        ] );
    }
    return $tags;
}

/* ============================================================
 * v2.21.0 / v2.23.0：Email 驗證 — 判斷與通知（獎勵已搬至 Exp_Events）
 * ============================================================ */

/**
 * 判斷使用者是否需要 email 驗證
 */
function smacg_user_needs_email_verify( int $uid ): bool {
    if ( $uid <= 0 ) return false;
    if ( (int) get_user_meta( $uid, 'smacg_email_verified', true ) === 1 ) return false;

    $token    = (string) get_user_meta( $uid, 'smacg_email_verify_token', true );
    $reg_time = (int)    get_user_meta( $uid, 'smacg_register_time', true );

    // 舊帳號（無 token、無 register_time）→ 自動標記為已驗證
    if ( ! $token && ! $reg_time ) {
        update_user_meta( $uid, 'smacg_email_verified', 1 );
        return false;
    }
    return true;
}

/**
 * 推送「請驗證 email」通知中心（24h 冷卻）
 */
function smacg_push_email_verify_notification( int $uid ): void {
    if ( ! function_exists( 'wxacg_create_notification' ) ) return;
    if ( ! smacg_user_needs_email_verify( $uid ) ) return;

    $transient_key = 'smacg_verify_notif_' . $uid;
    if ( get_transient( $transient_key ) ) return;

    $user = get_userdata( $uid );
    if ( ! $user ) return;

    wxacg_create_notification( [
        'user_id'     => $uid,
        'type'        => 'system',
        'object_type' => 'user',
        'object_id'   => $uid,
        'data'        => [
            'kind'    => 'email_verify',
            'title'   => '請驗證您的電子郵件',
            'excerpt' => '完成驗證即可獲得 Lv.10 + 完整會員功能！驗證信已寄至 ' . $user->user_email,
            'url'     => home_url( '/mc/?action=resend_verify' ),
            'icon'    => '📧',
        ],
        'force'       => true,
    ] );

    set_transient( $transient_key, 1, DAY_IN_SECONDS );
}

/* 註冊完成時（延遲 2 秒推送） */
add_action( 'user_register', function ( $uid ) {
    wp_schedule_single_event( time() + 2, 'smacg_delayed_verify_notif', [ (int) $uid ] );
}, 20, 1 );

/* 登入時補推 */
add_action( 'wp_login', function ( $login, $user ) {
    if ( $user instanceof WP_User ) {
        smacg_push_email_verify_notification( (int) $user->ID );
    }
}, 20, 2 );

/* 延遲事件 handler */
add_action( 'smacg_delayed_verify_notif', 'smacg_push_email_verify_notification', 10, 1 );

/* ─────────────────────────────────────────────────────────
 * v2.23.0：smacg_email_verified action 內的「發獎勵」邏輯
 *           已移至 wxacg-gamification 的 Exp_Events::on_email_verified
 *           本檔不再 add_action('smacg_email_verified', ...)
 *
 *           NSL 三層兜底（nsl_register_new_user / nsl_login / wp_login）
 *           也已搬到 Exp_Events，本檔不再處理
 * ───────────────────────────────────────────────────────── */

/**
 * /mc/?action=resend_verify → 自動觸發重寄驗證信
 */
add_action( 'wp_footer', function () {
    if ( ! is_user_logged_in() ) return;
    if ( empty( $_GET['action'] ) || $_GET['action'] !== 'resend_verify' ) return;
    if ( ! smacg_user_needs_email_verify( get_current_user_id() ) ) return;

    $nonce    = wp_create_nonce( 'smacg_resend_verify' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    $mc_url   = home_url( '/mc/' );
    $uid      = get_current_user_id();
    ?>
    <script>
    (function(){
      var flagKey = 'smacg_resend_done_<?php echo (int) $uid; ?>';
      try {
        if ( sessionStorage.getItem(flagKey) === '1' ) {
          history.replaceState({}, '', '<?php echo esc_js( $mc_url ); ?>');
          return;
        }
        sessionStorage.setItem(flagKey, '1');
      } catch (e) {}

      history.replaceState({}, '', '<?php echo esc_js( $mc_url ); ?>');

      var fd = new FormData();
      fd.append('action', 'smacg_resend_verify_email');
      fd.append('nonce',  '<?php echo esc_js( $nonce ); ?>');
      fetch('<?php echo esc_js( $ajax_url ); ?>', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      })
      .then(function(r){ return r.json(); })
      .then(function(res){
        var msg = (res && res.data && res.data.msg) ? res.data.msg : '操作完成';
        alert((res && res.success ? '✅ ' : '❌ ') + msg + '\n（也請檢查垃圾郵件夾）');
      })
      .catch(function(){
        alert('❌ 網路錯誤，請稍後再試');
      });
    })();
    </script>
    <?php
}, 99 );

/* ============================================================
 * v2.19.0：動漫關聯影評 / 前導文章（ACF post_object）
 * ============================================================ */

function smacg_get_anime_articles_count( int $anime_post_id, string $category ): int {
    if ( $anime_post_id <= 0 ) return 0;
    if ( ! in_array( $category, [ 'review', 'feature' ], true ) ) return 0;

    $cache_key = 'smacg_anime_art_cnt_' . $anime_post_id . '_' . $category;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return (int) $cached;
    }

    global $wpdb;

    $needle_int = '%' . $wpdb->esc_like( 'i:' . $anime_post_id . ';' ) . '%';
    $needle_str = '%' . $wpdb->esc_like( '"' . $anime_post_id . '"' ) . '%';

    $sql = $wpdb->prepare(
        "SELECT p.ID, pm.meta_value
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm
                 ON pm.post_id = p.ID AND pm.meta_key = 'related_anime'
         INNER JOIN {$wpdb->term_relationships} tr
                 ON tr.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt
                 ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = 'category'
         INNER JOIN {$wpdb->terms} t
                 ON t.term_id = tt.term_id
                AND t.slug = %s
         WHERE p.post_type = 'post'
           AND p.post_status = 'publish'
           AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )",
        $category,
        $needle_int,
        $needle_str
    );

    $rows  = $wpdb->get_results( $sql );
    $count = 0;

    if ( $rows ) {
        $seen = [];
        foreach ( $rows as $row ) {
            $pid = (int) $row->ID;
            if ( isset( $seen[ $pid ] ) ) continue;

            $ids = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $ids ) ) {
                if ( (int) $ids === $anime_post_id ) {
                    $seen[ $pid ] = true;
                    $count++;
                }
                continue;
            }

            $value_ids = array_map( 'intval', array_values( $ids ) );
            if ( in_array( $anime_post_id, $value_ids, true ) ) {
                $seen[ $pid ] = true;
                $count++;
            }
        }
    }

    set_transient( $cache_key, $count, HOUR_IN_SECONDS );
    return $count;
}

add_action( 'pre_get_posts', function ( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) return;

    $related = isset( $_GET['related_anime'] ) ? (int) $_GET['related_anime'] : 0;
    if ( $related <= 0 ) return;

    $cat = (string) $query->get( 'category_name' );
    if ( ! in_array( $cat, [ 'feature', 'review' ], true ) ) return;

    $meta_query   = $query->get( 'meta_query' ) ?: [];
    $meta_query[] = [
        'relation' => 'OR',
        [
            'key'     => 'related_anime',
            'value'   => 'i:' . $related . ';',
            'compare' => 'LIKE',
        ],
        [
            'key'     => 'related_anime',
            'value'   => '"' . $related . '"',
            'compare' => 'LIKE',
        ],
    ];
    $query->set( 'meta_query', $meta_query );
} );

add_action( 'save_post_post', function ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

    $new_ids = get_post_meta( $post_id, 'related_anime', true );
    $new_ids = is_array( $new_ids ) ? array_map( 'intval', $new_ids ) : [];

    $old_ids = get_post_meta( $post_id, '_related_anime_prev', true );
    $old_ids = is_array( $old_ids ) ? array_map( 'intval', $old_ids ) : [];

    $all_ids = array_unique( array_merge( $new_ids, $old_ids ) );
    foreach ( $all_ids as $aid ) {
        foreach ( [ 'review', 'feature' ] as $c ) {
            delete_transient( 'smacg_anime_art_cnt_' . $aid . '_' . $c );
        }
    }

    update_post_meta( $post_id, '_related_anime_prev', $new_ids );
}, 20, 1 );

add_action( 'before_delete_post', function ( $post_id ) {
    $ids = get_post_meta( $post_id, 'related_anime', true );
    $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    foreach ( $ids as $aid ) {
        foreach ( [ 'review', 'feature' ] as $c ) {
            delete_transient( 'smacg_anime_art_cnt_' . $aid . '_' . $c );
        }
    }
}, 10, 1 );

/* ============================================================
 * v2.20.0：Footer 樣式
 * ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $rel = '/assets/css/footer.css';
    $abs = weixiaoacg_THEME_DIR . $rel;
    if ( ! file_exists( $abs ) ) return;

    wp_enqueue_style(
        'smacg-footer',
        weixiaoacg_THEME_URL . $rel,
        [],
        filemtime( $abs )
    );
}, 20 );

/* ============================================================
 * 點一下馬上登出：略過 WP「確定登出嗎」確認頁
 * ============================================================ */
add_action( 'wp_ajax_smacg_get_logout_url', function () {
    $url = html_entity_decode( wp_logout_url( home_url( '/' ) ), ENT_QUOTES );
    wp_send_json_success( [ 'url' => $url ] );
} );

add_action( 'wp_footer', function () {
    if ( ! is_user_logged_in() ) return;
    ?>
    <script>
    (function(){
      var link = document.getElementById('smacg-logout-link');
      if (!link) return;
      link.addEventListener('click', function(e){
        e.preventDefault();
        fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>?action=smacg_get_logout_url', {
          credentials: 'same-origin'
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if (res && res.success && res.data && res.data.url) {
            window.location.href = res.data.url;
          } else {
            window.location.href = link.href;
          }
        })
        .catch(function(){ window.location.href = link.href; });
      });
    })();
    </script>
    <?php
}, 100 );

/* ============================================================
 * PWA：已登入使用者不載入 Service Worker
 * ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_user_logged_in() ) return;
    wp_dequeue_script( 'pwa-for-wp' );
    wp_deregister_script( 'pwa-for-wp' );
}, 99 );

add_action( 'wp_footer', function () {
    if ( ! is_user_logged_in() ) return;
    ?>
    <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations().then(function(regs){
        regs.forEach(function(r){ r.unregister(); });
      });
    }
    </script>
    <?php
}, 100 );

/* ============================================================
 * 擋掉 wp-login.php 的「註冊」入口
 * ============================================================ */
add_action( 'login_init', function () {
    if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';

    if ( $action === 'register' ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }
} );

/**
 * 前端攔截：把留言區指向 /user/xxx/ 的連結改寫成 /u/xxx/
 * （wpDiscuz PHP filter 在此環境不生效，改用 DOM 改寫保證生效）
 */
add_action( 'wp_footer', function () {
    // 只在有留言區的單篇文章載入
    if ( ! is_singular() ) return;
    ?>
    <script>
    (function () {
        function rewriteUserLinks(root) {
            var links = (root || document).querySelectorAll('a[href*="/user/"]');
            links.forEach(function (a) {
                // 僅改寫 wpDiscuz 留言區內的連結，避免動到其他地方
                if (!a.closest('#wpdcom, .wpd-comment, .wpdiscuz-comment')) return;
                var m = a.getAttribute('href').match(/\/user\/([^\/?#]+)\/?/);
                if (m && m[1]) {
                    a.setAttribute('href', '/u/' + m[1] + '/');
                }
            });
        }
        // 初次載入
        document.addEventListener('DOMContentLoaded', function () { rewriteUserLinks(document); });
        // wpDiscuz 是 AJAX 載入留言，用 MutationObserver 持續改寫後續載入的留言
        var obs = new MutationObserver(function (mutations) {
            mutations.forEach(function (mu) {
                mu.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) rewriteUserLinks(node);
                });
            });
        });
        var target = document.getElementById('wpdcom') || document.body;
        obs.observe(target, { childList: true, subtree: true });
    })();
    </script>
    <?php
}, 100 );


/**
 * 前端注入 wpDiscuz「暴雷（Spoiler）」按鈕。
 * wpdiscuz_editor_buttons PHP filter 在此環境不生效（與 /user/ 改寫同樣情形），
 * 改用 DOM 注入 + 自實作點擊邏輯，產生核心可辨識的 [spoiler title="..."] 短碼。
 * 用法：留言框先反白選取要遮的文字 → 點暴雷按鈕 → 輸入提示標題 → 送出。
 * @since 2.25.0
 */
add_action( 'wp_footer', function () {
    if ( ! is_singular( [ 'anime', 'manga', 'asa_char_comments' ] ) ) return;
    ?>
    <script>
    (function () {
        var SPOILER_TITLE_PROMPT = '請輸入暴雷提示標題（例如：劇情雷、結局雷）';

        // 眼睛-劃掉 圖示
        var ICON = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

        function insertSpoiler(toolbar) {
            // wpDiscuz 工具列 id：wpd-editor-toolbar-<uid>；編輯器容器 id：wpd-editor-<uid>
            var tid = toolbar.id || '';
            var uid = tid.replace('wpd-editor-toolbar-', '');
            var editorEl = document.getElementById('wpd-editor-' + uid);
            if (!editorEl) return;

            var qlEditor = editorEl.querySelector('.ql-editor');
            if (!qlEditor) return;

            var title = window.prompt(SPOILER_TITLE_PROMPT);
            if (title === null) return;           // 使用者按取消
            title = title.trim();
            if (title === '') title = '暴雷';

            var selText = '';
            var sel = window.getSelection();
            if (sel && sel.rangeCount > 0 && qlEditor.contains(sel.anchorNode)) {
                selText = sel.toString();
            }

            var left  = ' [spoiler title="' + title + '"] ';
            var right = ' [/spoiler] ';
            var shortcode = selText ? (left + selText + right) : (left + right);

            qlEditor.focus();
            if (sel && sel.rangeCount > 0 && qlEditor.contains(sel.anchorNode)) {
                var range = sel.getRangeAt(0);
                range.deleteContents();
                range.insertNode(document.createTextNode(shortcode));
            } else {
                qlEditor.appendChild(document.createTextNode(shortcode));
            }
            // 觸發 input 事件讓 Quill 同步內容
            qlEditor.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function addSpoilerButton(toolbar) {
            if (toolbar.querySelector('.wpd-smacg-spoiler-btn')) return; // 已加過就跳過
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ql-smacg-spoiler wpd-smacg-spoiler-btn';
            btn.title = '暴雷隱藏（先選取要遮住的文字再按）';
            btn.innerHTML = ICON;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                insertSpoiler(toolbar);
            });
            toolbar.appendChild(btn);
        }

        function scanToolbars(root) {
            var toolbars = (root || document).querySelectorAll('[id^="wpd-editor-toolbar-"]');
            toolbars.forEach(addSpoilerButton);
        }

        document.addEventListener('DOMContentLoaded', function () { scanToolbars(document); });

        // 回覆表單為 AJAX 動態產生，用 observer 補上按鈕
        var obs = new MutationObserver(function (muts) {
            muts.forEach(function (mu) {
                mu.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.id && node.id.indexOf('wpd-editor-toolbar-') === 0) {
                        addSpoilerButton(node);
                    } else {
                        scanToolbars(node);
                    }
                });
            });
        });
        obs.observe(document.getElementById('wpdcom') || document.body, { childList: true, subtree: true });
    })();
    </script>
    <style>
    .wpd-smacg-spoiler-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 3px 5px;
        color: #6b7280;
        vertical-align: middle;
        line-height: 1;
    }
    .wpd-smacg-spoiler-btn:hover { color: #e11d48; }
    </style>
    <?php
}, 100 );


add_filter('wpdiscuz_comment_author', function($author_name, $comment){
    $uid = (int)($comment->user_id ?? 0);
    if ($uid > 0) {
        $u = get_userdata($uid);
        if ($u && $u->display_name) {
            return $u->display_name;
        }
    }
    return $author_name;
}, 20, 2);

add_filter('nsl_registration_user_data', function ($userData, $provider) {
    // 取 email 前綴作為基底
    $email = isset($userData['email']) ? $userData['email'] : '';
    if ($email && strpos($email, '@') !== false) {
        $base = sanitize_user(current(explode('@', $email)), true);
    } else {
        // email 拿不到時,退而用顯示名稱
        $base = sanitize_user($provider->getAuthUserData('name'), true);
    }
    $base = strtolower(trim($base));
    if ($base === '') {
        return $userData; // 真的無法產生就維持 NSL 原邏輯
    }

    // 確保帳號唯一,被佔用就加數字
    $username = $base;
    $i = 1;
    while (username_exists($username)) {
        $username = $base . $i;
        $i++;
    }

    $userData['username'] = $username;
    return $userData;
}, 10, 2);

/* ============================================================
 * v2.24.0：anime 單頁 meta description 自動 fallback
 *           無手填 Rank Math 描述時 → 用劇情簡介（截斷約 155 字）
 *           來源 key 與 single-anime.php 一致：
 *           anime_synopsis_chinese → anime_synopsis
 *           僅作用於 anime 單頁；同步影響 OG / Twitter description
 * ============================================================ */
add_filter( 'rank_math/frontend/description', function ( $description ) {

    // 只處理 anime 單頁
    if ( ! is_singular( 'anime' ) ) {
        return $description;
    }

    // 已有手填／既有描述就不覆蓋
    if ( is_string( $description ) && trim( $description ) !== '' ) {
        return $description;
    }

    $post_id = get_queried_object_id();
    if ( ! $post_id ) {
        return $description;
    }

    // 取簡介：與模板相同的 fallback 順序
    $synopsis = (string) get_post_meta( $post_id, 'anime_synopsis_chinese', true );
    if ( trim( $synopsis ) === '' ) {
        $synopsis = (string) get_post_meta( $post_id, 'anime_synopsis', true );
    }

    $synopsis = trim( wp_strip_all_tags( $synopsis ) );
    if ( $synopsis === '' ) {
        return $description; // 真的沒簡介就維持原樣（交給 Rank Math 全域設定）
    }

    // 壓掉多餘空白／換行，截斷到約 155 字（中文以字元計）
    $synopsis = preg_replace( '/\s+/u', ' ', $synopsis );

    $limit = 155;
    if ( function_exists( 'mb_strlen' ) && mb_strlen( $synopsis ) > $limit ) {
        $synopsis = mb_substr( $synopsis, 0, $limit ) . '…';
    } elseif ( ! function_exists( 'mb_strlen' ) && strlen( $synopsis ) > $limit ) {
        $synopsis = substr( $synopsis, 0, $limit ) . '…';
    }

    return $synopsis;
}, 20 );

/**
 * STAFF role 顯示正規化：固定對照表優先，其餘交給 OpenCC 兜底
 * 資料庫存簡體也沒關係,前端一律顯示正確繁體(台灣 ACG 用語)
 */
function wxacg_staff_role( $role ) {
    static $map = [
        '脚本'=>'腳本','指令碼'=>'腳本','主题歌演出'=>'主題歌演出',
        '音乐'=>'音樂','动画制作'=>'動畫製作','人物设定'=>'人物設定',
        '人物设计'=>'人物設定','导演'=>'監督','導演'=>'監督','监督'=>'監督',
        '主题歌作曲'=>'主題歌作曲','音响监督'=>'音響監督',
        '主题歌作词'=>'主題歌作詞','系列构成'=>'系列構成','制作'=>'製作',
    ];
    $role = trim( (string) $role );
    if ( $role === '' ) return '';
    if ( isset( $map[ $role ] ) ) return $map[ $role ];
    // 兜底：交給 OpenCC,但事後修正它的 ACG 誤轉
    if ( class_exists( 'Anime_Sync_CN_Converter' ) ) {
        $role = Anime_Sync_CN_Converter::static_convert( $role );
        $role = str_replace( '指令碼', '腳本', $role ); // 反 s2twp 誤轉
    }
    return $role;
}

/* ============================================================
 * v2.26.0：多語言標題搜尋（anime + manga）
 * ------------------------------------------------------------
 * 把中文/簡體/日文原名/羅馬拼音/英文標題彙整進隱藏 meta
 * _smacg_search_blob，並讓前台搜尋比對此欄位，達成
 * 「任一語言輸入都能搜到同一部作品」。
 *
 * 顯示用的 post_title 完全不動；blob 只供搜尋比對。
 * ============================================================ */

/**
 * 要納入搜尋 blob 的標題 meta key（anime 與 manga 共用同組 key）。
 */
function smacg_search_title_keys(): array {
    return [
        'anime_title_chinese',
        'anime_title_simplified',
        'anime_title_native',
        'anime_title_romaji',
        'anime_title_english',
    ];
}

/**
 * 依 post_id 組出搜尋 blob 字串並寫入 _smacg_search_blob。
 * 空的標題自動略過；全空則刪除 blob。
 *
 * @return string 寫入的 blob（回填指令會用到）
 */
function smacg_build_search_blob( int $post_id ): string {
    $parts = [];
    foreach ( smacg_search_title_keys() as $key ) {
        $val = get_post_meta( $post_id, $key, true );
        if ( is_string( $val ) ) {
            $val = trim( $val );
            if ( $val !== '' ) {
                $parts[] = $val;
            }
        }
    }
    // 也把 post_title 本身收進去（有些作品標題是人工改的，未必等於任一 meta）
    $title = trim( (string) get_post_field( 'post_title', $post_id ) );
    if ( $title !== '' ) {
        $parts[] = $title;
    }

    $parts = array_values( array_unique( $parts ) );

    if ( empty( $parts ) ) {
        delete_post_meta( $post_id, '_smacg_search_blob' );
        return '';
    }

    $blob = implode( ' | ', $parts );
    update_post_meta( $post_id, '_smacg_search_blob', $blob );
    return $blob;
}

/**
 * anime / manga 存檔後重建 blob。
 * 掛在 acf/save_post priority 30：確保在 ACF 寫入欄位（10）與
 * 上面「中文標題同步 post_title」（20）都完成之後才組 blob。
 */
add_action( 'acf/save_post', function ( $post_id ) {
    if ( ! is_numeric( $post_id ) ) {
        return;
    }
    $post_id = (int) $post_id;
    $ptype   = get_post_type( $post_id );
    if ( ! in_array( $ptype, [ 'anime', 'manga' ], true ) ) {
        return;
    }
    smacg_build_search_blob( $post_id );
}, 30 );

/**
 * 匯入 / 同步（非 ACF UI 路徑）也重建 blob。
 * import manager 在寫完 meta 後會更新 anime_sync_time，
 * 但最保險是直接掛 save_post_{cpt}，涵蓋所有寫入來源。
 */
add_action( 'save_post_anime', function ( $post_id, $post, $update ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
    smacg_build_search_blob( (int) $post_id );
}, 30, 3 );

add_action( 'save_post_manga', function ( $post_id, $post, $update ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
    smacg_build_search_blob( (int) $post_id );
}, 30, 3 );

/**
 * 前台主搜尋：把 _smacg_search_blob 納入比對。
 * 精準鎖定原生搜尋群組 (((post_title...)))，只對這組 OR 上 blob，
 * 不碰 post_password 等其他條件，避免 AND/OR 優先級錯亂。
 */
add_filter( 'posts_search', function ( $search, $wp_query ) {
    global $wpdb;

    if ( is_admin() || ! $wp_query->is_main_query() || ! $wp_query->is_search() ) {
        return $search;
    }
    if ( trim( $search ) === '' ) {
        return $search;
    }

    $term = trim( (string) $wp_query->get( 's' ) );
    if ( $term === '' ) {
        return $search;
    }

    $like = '%' . $wpdb->esc_like( $term ) . '%';
    $blob = $wpdb->prepare(
        "{$wpdb->posts}.ID IN (
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_smacg_search_blob' AND meta_value LIKE %s
         )",
        $like
    );

    // WordPress 原生搜尋群組長這樣：  (((wp_posts.post_title LIKE '...') OR (...excerpt...) OR (...content...)))
    // 我們用正則抓「以 ((( 開頭、對應到該組結尾 )))」的那一段，只對它 OR 上 blob。
    $pattern = '/\(\(\(' . preg_quote( $wpdb->posts, '/' ) . '\.post_title\s+LIKE.*?\)\)\)/s';

    $replaced = preg_replace_callback(
        $pattern,
        function ( $m ) use ( $blob ) {
            // 把原本那組整包起來，再 OR 上 blob
            return '( ' . $m[0] . ' OR ' . $blob . ' )';
        },
        $search,
        1,
        $count
    );

    if ( $count > 0 ) {
        $search = $replaced;
    }

    return $search;
}, 10, 2 );

/**
 * 讓 header 即時搜尋下拉（AJAX action=weixiaoacg_search）也支援
 * _smacg_search_blob 多語言搜尋。
 *
 * 因為該 handler 在 plugin 內用標準 WP_Query(['s'=>...])，
 * 既非 main_query 也非 is_search()，原本的 posts_search filter 不會作用。
 * 這裡針對此特定 AJAX request 額外掛一個 posts_search filter。
 */
add_action( 'wp_ajax_weixiaoacg_search',        'smacg_enable_blob_for_ajax_search', 1 );
add_action( 'wp_ajax_nopriv_weixiaoacg_search', 'smacg_enable_blob_for_ajax_search', 1 );
function smacg_enable_blob_for_ajax_search() {
    add_filter( 'posts_search', 'smacg_ajax_search_blob_clause', 10, 2 );
}
function smacg_ajax_search_blob_clause( $search, $wp_query ) {
    global $wpdb;

    // 只處理帶搜尋字串的查詢
    $term = trim( (string) $wp_query->get( 's' ) );
    if ( $term === '' || trim( $search ) === '' ) {
        return $search;
    }

    $like = '%' . $wpdb->esc_like( $term ) . '%';
    $blob = $wpdb->prepare(
        "{$wpdb->posts}.ID IN ("
        . "SELECT post_id FROM {$wpdb->postmeta} "
        . "WHERE meta_key = '_smacg_search_blob' AND meta_value LIKE %s"
        . ")",
        $like
    );

    // 用與結果頁相同的做法：抓 (((post_title LIKE ...))) 群組，OR 進 blob
    $pattern = '/\(\(\(' . preg_quote( $wpdb->posts, '/' ) . '\.post_title\s+LIKE.*?\)\)\)/s';
    $search  = preg_replace_callback(
        $pattern,
        function ( $m ) use ( $blob ) {
            return '( ' . $m[0] . ' OR ' . $blob . ' )';
        },
        $search,
        1
    );

    return $search;
}

/* ------------------------------------------------------------
 * 後台一次性回填：外觀 → 「回填搜尋索引」
 * 把現有 anime + manga 全部重建 blob。跑一次即可。
 * ------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    add_theme_page(
        '回填搜尋索引',
        '回填搜尋索引',
        'manage_options',
        'smacg-rebuild-search-blob',
        'smacg_rebuild_search_blob_page'
    );
} );

function smacg_rebuild_search_blob_page() {
    if ( isset( $_POST['smacg_rebuild_blob_nonce'] )
      && wp_verify_nonce( $_POST['smacg_rebuild_blob_nonce'], 'smacg_rebuild_blob' ) ) {

        $ids = get_posts( [
            'post_type'        => [ 'anime', 'manga' ],
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ] );

        $done = 0;
        foreach ( $ids as $pid ) {
            smacg_build_search_blob( (int) $pid );
            $done++;
        }
        echo '<div class="notice notice-success"><p>已回填 <strong>' . (int) $done . '</strong> 筆搜尋索引。</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>回填搜尋索引</h1>
      <p>把現有全部 anime + manga 的多語言標題重建為 <code>_smacg_search_blob</code>，讓中文／簡體／日文／羅馬拼音／英文都能搜到。</p>
      <p>之後每次在後台編輯或重新同步作品時會自動更新，不需再手動回填。</p>
      <form method="post">
        <?php wp_nonce_field( 'smacg_rebuild_blob', 'smacg_rebuild_blob_nonce' ); ?>
        <?php submit_button( '開始回填' ); ?>
      </form>
    </div>
    <?php
}

/* ============================================================
 * anime 中文標題(anime_title_chinese)存檔時，
 * 自動同步為 WordPress 文章標題(post_title)
 * 讓「顯示名」與「內部名」永遠一致，搜尋也搜得到
 * ============================================================ */
add_action( 'acf/save_post', function ( $post_id ) {

    // 只處理數字型 post_id（略過 options、term 等）
    if ( ! is_numeric( $post_id ) ) {
        return;
    }
    $post_id = (int) $post_id;

    // 只處理 anime 這個文章類型
    if ( get_post_type( $post_id ) !== 'anime' ) {
        return;
    }

    // 取這次存檔後的中文標題
    $cn = get_post_meta( $post_id, 'anime_title_chinese', true );
    $cn = is_string( $cn ) ? trim( $cn ) : '';

    // 中文標題為空就不動（避免把標題洗掉）
    if ( $cn === '' ) {
        return;
    }

    // 已經一致就不必更新，省一次寫入
    if ( trim( get_post_field( 'post_title', $post_id ) ) === $cn ) {
        return;
    }

    // 解除鉤子避免無限迴圈，更新 post_title 後再掛回
    remove_action( 'acf/save_post', __FUNCTION__ );

    wp_update_post( [
        'ID'         => $post_id,
        'post_title' => $cn,
    ] );

    add_action( 'acf/save_post', __FUNCTION__, 20 );

}, 20 ); // priority 20：在 ACF 把欄位寫入資料庫（預設 10）之後才執行


/* ============================================================
 * 角色頁 (/character/xxx/) 讓 wpDiscuz 認得 post type，
 * 使留言框套用 wpDiscuz 樣式（與作品頁一致）
 * ============================================================ */
add_action( 'wp', function () {
    $char_id = (int) get_query_var( 'asa_character_id' );
    if ( $char_id <= 0 ) {
        return;
    }

    // 找出這個角色對應的留言承載 post（asa_char_comments）
    $posts = get_posts( [
        'post_type'      => 'asa_char_comments',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => 'asa_character_bgm_id',
                'value'   => $char_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ],
        ],
    ] );

    if ( empty( $posts ) ) {
        return;
    }

    global $wp_query;
    $cpost = $posts[0];

    // 把主查詢偽裝成「正在看這篇 asa_char_comments」，
    // 讓 wpDiscuz 的 is_singular / postTypes 白名單判斷通過
    $wp_query->is_singular       = true;
    $wp_query->is_single         = true;
    $wp_query->is_page           = false;
    $wp_query->is_404            = false;
    $wp_query->queried_object    = $cpost;
    $wp_query->queried_object_id = $cpost->ID;
}, 1 );

// AdBlock 偵測
add_action('wp_footer', function() {
    $adcheck_url = content_url('uploads/adcheck/adsbygoogle.js');
?>
<div id="adblock-notice" style="display:none;position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#0f0c29,#302b63,#24243e);color:#fff;padding:20px 32px;z-index:99999;box-shadow:0 -4px 20px rgba(0,0,0,0.6);">
  <div style="max-width:800px;margin:0 auto;display:flex;align-items:center;gap:20px;flex-wrap:wrap;justify-content:center;text-align:center;">
    <div style="font-size:36px;">🌸</div>
    <div style="flex:1;min-width:260px;">
      <div style="font-size:17px;font-weight:bold;margin-bottom:6px;">嗨，我們偵測到您使用了廣告封鎖器</div>
      <div style="font-size:13px;line-height:1.8;color:#ccc;">
        微笑動漫是由一群熱愛動漫的人用心經營，我們每天更新番劇資訊、整理播出時間、撰寫介紹，只為讓您第一時間掌握最新動態。<br>
        我們<strong style="color:#ff6b9d;">承諾永遠不使用彈窗廣告、插頁廣告或全螢幕廣告</strong>，只保留不干擾瀏覽的靜態廣告。<br>
        如果您認同我們的用心，請將 <strong style="color:#7ec8e3;">weixiaoacg.com</strong> 加入白名單，這是支持我們繼續免費分享的最簡單方式 ❤️
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <button onclick="document.getElementById('adblock-notice').style.display='none'" style="padding:10px 22px;background:linear-gradient(135deg,#e94560,#c0392b);border:none;border-radius:6px;color:#fff;cursor:pointer;font-size:14px;font-weight:bold;white-space:nowrap;">❤️ 我支持，關閉提示</button>
      <button onclick="document.getElementById('adblock-notice').style.display='none'" style="padding:10px 22px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.3);border-radius:6px;color:#ccc;cursor:pointer;font-size:13px;white-space:nowrap;">暫時關閉</button>
    </div>
  </div>
</div>
<script>
(function() {
  var s = document.createElement('script');
  s.src = '<?php echo esc_url($adcheck_url); ?>?t=' + Date.now();
  s.onload = function() {
    // 腳本載入成功，沒有 AdBlock
  };
  s.onerror = function() {
    // 腳本被攔截，有 AdBlock
    document.getElementById('adblock-notice').style.display = 'block';
  };
  document.head.appendChild(s);
})();
</script>
<?php
});

// 預載入 Font Awesome 字型（本機），減少阻塞
add_action('wp_head', function() {
    $base = weixiaoacg_THEME_URL . '/assets/fontawesome/webfonts/';
    echo '<link rel="preload" href="' . esc_url( $base . 'fa-solid-900.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
    echo '<link rel="preload" href="' . esc_url( $base . 'fa-regular-400.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
    echo '<link rel="preload" href="' . esc_url( $base . 'fa-brands-400.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
}, 1);


// jQuery 改為 defer 載入，移出關鍵路徑
add_filter('script_loader_tag', function($tag, $handle) {
    if ($handle === 'jquery-core' && !is_admin()) {
        return str_replace('<script ', '<script defer ', $tag);
    }
    return $tag;
}, 10, 2);

// 預載入本地 jQuery，讓瀏覽器更早發現
add_action('wp_head', function() {
    if (!is_admin()) {
    }
}, 2);

// 強制 jQuery 不被 defer（修復 jQuery is not defined）
add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
    $no_defer = [ 'jquery-core', 'jquery', 'jquery-migrate' ];
    if ( in_array( $handle, $no_defer, true ) ) {
        $tag = str_replace( ' defer', '', $tag );
        $tag = str_replace( " defer='defer'", '', $tag );
        $tag = str_replace( ' defer="defer"', '', $tag );
    }
    return $tag;
}, 10, 3 );


add_action('wp_footer', function() { ?>
<nav class="wx-bottom-nav" role="navigation" aria-label="底部導航">
  <a href="<?php echo home_url('/news/'); ?>" class="wx-bottom-nav__item <?php echo is_singular('post') ? 'is-active' : ''; ?>">
    <span class="wx-bottom-nav__icon">📰</span>
    <span class="wx-bottom-nav__label">新聞</span>
  </a>
  <a href="<?php echo home_url('/bangumi/'); ?>" class="wx-bottom-nav__item <?php echo is_page('bangumi') ? 'is-active' : ''; ?>">
    <span class="wx-bottom-nav__icon">🗓️</span>
    <span class="wx-bottom-nav__label">新番</span>
  </a>
  <a href="<?php echo admin_url('admin-ajax.php') . '?action=wxacg_random_anime'; ?>" rel="nofollow" class="wx-bottom-nav__item wx-bottom-nav__item--center">
    <span class="wx-bottom-nav__icon-wrap"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="26" height="26"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm10 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm-5 4a1 1 0 1 0 0 2 1 1 0 0 0 0-2zM7 15a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm10 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg></span>
    <span class="wx-bottom-nav__label">抽動漫</span>
  </a>
  <a href="<?php echo home_url('/anime/'); ?>" class="wx-bottom-nav__item <?php echo is_post_type_archive('anime') || is_singular('anime') ? 'is-active' : ''; ?>">
    <span class="wx-bottom-nav__icon">🎬</span>
    <span class="wx-bottom-nav__label">動漫</span>
  </a>
  <a href="<?php echo home_url('/forum/'); ?>" class="wx-bottom-nav__item <?php echo ( is_page('forum') || strpos( $_SERVER['REQUEST_URI'] ?? '', '/community/' ) !== false || strpos( $_SERVER['REQUEST_URI'] ?? '', '/forum' ) !== false ) ? 'is-active' : ''; ?>">
    <span class="wx-bottom-nav__icon">💬</span>
    <span class="wx-bottom-nav__label">討論</span>
  </a>
</nav>
<style>
.wx-bottom-nav { display: none; }
@media (max-width: 768px) {
    .wx-bottom-nav {
        display: flex;
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        z-index: 999999 !important;
        height: 72px;
        background: rgba(10, 14, 24, 0.88);
        backdrop-filter: blur(24px) saturate(180%);
        -webkit-backdrop-filter: blur(24px) saturate(180%);
        border-top: 1px solid rgba(255,255,255,0.08);
        justify-content: space-around;
        align-items: stretch;
        padding-bottom: env(safe-area-inset-bottom, 0px);
        box-shadow: 0 -4px 30px rgba(0,0,0,0.45);
    }
    .wx-bottom-nav__item {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 5px;
        color: rgba(180, 195, 220, 0.45);
        font-size: 11px;
        font-weight: 600;
        text-decoration: none;
        transition: color 0.2s ease, transform 0.15s ease;
        padding: 8px 4px;
        position: relative;
    }
    .wx-bottom-nav__item:active { transform: scale(0.90); }
    .wx-bottom-nav__item:hover,
    .wx-bottom-nav__item.is-active { color: #ffffff; }
    .wx-bottom-nav__item.is-active::before {
        content: "";
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 28px;
        height: 3px;
        background: linear-gradient(90deg, #63a8ff, #a663ff);
        border-radius: 0 0 4px 4px;
    }
    .wx-bottom-nav__icon {
        font-size: 24px;
        line-height: 1;
        transition: transform 0.2s ease;
    }
    .wx-bottom-nav__item:hover .wx-bottom-nav__icon,
    .wx-bottom-nav__item.is-active .wx-bottom-nav__icon { transform: translateY(-2px); }
    .wx-bottom-nav__label { font-size: 11px; font-weight: 600; }
    .wx-bottom-nav__item--center { color: #ffffff; }
    .wx-bottom-nav__item--center .wx-bottom-nav__icon-wrap {
        width: 54px;
        height: 54px;
        background: linear-gradient(135deg, #e11d48, #f97316);
        border-radius: 50%;
        margin-top: -24px;
        box-shadow: 0 4px 22px rgba(225,29,72,0.6), 0 0 0 4px rgba(10,14,24,0.88);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .wx-bottom-nav__item--center:hover .wx-bottom-nav__icon-wrap,
    .wx-bottom-nav__item--center:active .wx-bottom-nav__icon-wrap {
        transform: scale(1.1) translateY(-3px);
        box-shadow: 0 8px 30px rgba(225,29,72,0.75), 0 0 0 4px rgba(10,14,24,0.88);
    }
    .wx-bottom-nav__item--center .wx-bottom-nav__label {
        margin-top: 2px;
        color: rgba(180,195,220,0.65);
    }
    body { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)) !important; }
}
</style>
<?php }, 99);

// AdSense 廣告碼（貼入 <head>）
add_action('wp_head', function () {
    echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3709514691049766" crossorigin="anonymous"></script>' . "\n";
}, 5);

// 允許 Python API 自動寫入 Rank Math SEO 欄位 (加入權限宣告)
add_action( 'rest_api_init', function() {
    register_meta( 'post', 'rank_math_focus_keyword', array(
        'type'          => 'string',
        'single'        => true,
        'show_in_rest'  => true,
        'auth_callback' => function() {
            // 只要是有權限發文的帳號，就允許修改這個欄位
            return current_user_can( 'edit_posts' ); 
        }
    ) );
    
    register_meta( 'post', 'rank_math_description', array(
        'type'          => 'string',
        'single'        => true,
        'show_in_rest'  => true,
        'auth_callback' => function() {
            return current_user_can( 'edit_posts' );
        }
    ) );
} );

// 允許 WordPress 透過 API 儲存 <style> 和 <iframe> 標籤
add_filter( 'wp_kses_allowed_html', function ( $tags, $context ) {
    if ( 'post' === $context ) {
        // 解鎖 CSS 樣式標籤
        $tags['style'] = array(
            'type'  => true,
            'media' => true,
        );
        // 解鎖 YouTube 影片標籤
        $tags['iframe'] = array(
            'src'             => true,
            'height'          => true,
            'width'           => true,
            'frameborder'     => true,
            'allowfullscreen' => true,
            'allow'           => true,
            'title'           => true,
            'class'           => true,
            'style'           => true,
        );
    }
    return $tags;
}, 10, 2 );

// ==========================================
// 建立 AI 翻譯專用 REST API (輸出動漫多國語言字典)
// ==========================================
add_action( 'rest_api_init', function () {
    register_rest_route( 'wxacg/v1', '/glossary', array(
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function( $request ) {
            global $wpdb;
            $cached_glossary = get_transient( 'wxacg_ai_glossary' );
            if ( false !== $cached_glossary ) {
                return rest_ensure_response( $cached_glossary );
            }

            $glossary = array();
            $query = "
                SELECT pm.post_id, pm.meta_key, pm.meta_value 
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'anime' 
                AND p.post_status = 'publish'
                AND pm.meta_key IN ('anime_title_chinese', 'anime_title_native', 'anime_title_english', 'anime_title_simplified')
                AND pm.meta_value != ''
            ";
            
            $results = $wpdb->get_results( $query );
            if ( ! is_array( $results ) ) {
                $results = array();
            }

            $grouped_data = array();
            foreach ( $results as $row ) {
                $grouped_data[ $row->post_id ][ $row->meta_key ] = trim($row->meta_value);
            }

            foreach ( $grouped_data as $post_id => $meta ) {
                if ( empty( $meta['anime_title_chinese'] ) ) continue;
                $tw_title = $meta['anime_title_chinese'];
                if ( ! empty( $meta['anime_title_native'] ) ) {
                    $glossary[ $meta['anime_title_native'] ] = $tw_title;
                }
                if ( ! empty( $meta['anime_title_english'] ) ) {
                    $glossary[ $meta['anime_title_english'] ] = $tw_title;
                }
                if ( ! empty( $meta['anime_title_simplified'] ) ) {
                    $glossary[ $meta['anime_title_simplified'] ] = $tw_title;
                }
            }

            set_transient( 'wxacg_ai_glossary', $glossary, 12 * HOUR_IN_SECONDS );
            return rest_ensure_response( $glossary );
        }
    ) );
} );
