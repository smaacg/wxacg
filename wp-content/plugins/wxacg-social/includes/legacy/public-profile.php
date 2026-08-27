<?php
/**
 * Public Profile System - /u/{username}/
 *
 * @package weixiaoacg
 * @subpackage PublicProfile
 * @version 1.3.0 (2026-05-17)
 *
 * v1.3.0 變更（2026-05-17）：
 *   - 【Bug 修復・跑版根因】smacg_pp_dispatch() 在 include 模板前
 *     重置 $wp_query 狀態為「虛擬 page」：
 *       - is_home / is_front_page / is_archive / is_category / is_tag /
 *         is_tax / is_search / is_404 / is_posts_page 全部設為 false
 *       - is_singular / is_page 設為 true
 *       - queried_object 設為當前 WP_User
 *       - status_header(200)
 *     原因：先前 $wp_query 仍處於原始 home/blog 狀態，
 *     導致 page-public-profile.php 內呼叫的 get_header() → body_class()
 *     輸出 "home blog" 等不應出現的 class，使 Blocksy 父主題套用
 *     首頁 / 列表頁版面與 .pp-wrap 疊加，造成公開頁跑版。
 *     此修復為跑版根本解，配合主題端 body_class filter 雙保險。
 *
 * v1.2.1 變更（2026-05-16，hotfix）：
 *   - 【Bug 修復・回滾 v1.2.0 的 Section 6 設計】
 *     v1.2.0 一度將新用戶預設隱私改寫成 4 個扁平裸 key
 *     （public_profile / public_watchlist / show_email / show_continue_watching），
 *     原意是要對齊「假設中的 privacy.php 抽離層」。
 *     但 privacy.php 實際上因與 member-stats.php::smacg_get_user_privacy()
 *     重複宣告造成 Fatal，已永久刪除。
 *     真正的隱私資料層為：
 *       - 讀：wxacg-members/includes/legacy/member-stats.php::smacg_get_user_privacy()
 *             → 讀 user_meta 'smacg_privacy'（陣列）
 *       - 寫：wxacg-members/includes/legacy/member-ajax.php::wp_ajax_smacg_update_privacy
 *             → 寫 user_meta 'smacg_privacy'（陣列）
 *     v1.2.0 殘留的扁平裸 key 寫法導致新註冊用戶的預設值被寫到沒人讀的位置，
 *     雖然不會 fatal、預設值與 fallback 相同所以表面無感，
 *     但會殘留 4 列無用 user_meta，並讓「使用者是否已初始化偏好」的判斷失準。
 *   - Section 6 改回寫入單一 'smacg_privacy' 陣列 meta（int 0/1 值）。
 *   - 移除對 smacg_get_user_privacy_defaults() 的死引用（該函式從未在 repo 中存在）。
 *   - 其餘區段（rewrite / dispatch / SEO / helper / admin bar / 等級徽章注入）byte-perfect 不變。
 *
 * v1.2.0 變更（2026-05-16）：
 *   - 新增 /u/{username}/(followers|following)/ rewrite 規則（含分頁）
 *   - 新增 query var smacg_pp_section（值：'followers' | 'following'）
 *   - smacg_pp_dispatch() 偵測 followers/following 子頁時，
 *     不載入 page-public-profile.php，改由 followers-page.php 接管渲染。
 *   - smacg_is_public_profile_page() 自動對 followers/following 頁回傳 true
 *     （因為仍會設定 $GLOBALS['smacg_pp_user_obj']），
 *     使主題 setup-enqueue.php 的 public-profile.css/js 自動載入。
 *
 * v1.1.0 變更：
 *   - Bug #10 修正：移除模糊比對 fallback，避免 /u/taro/ 撈到 taro2
 *   - Bug #11 修正：rewrite flush flag 改用 WXACG_SOCIAL_VERSION，升版自動 flush
 *   - Bug #12 修正：移除自前 canonical，改 hook 進 Yoast / Rank Math
 *
 * 提供公開個人頁功能：
 * - URL: /u/{username}/
 * - URL: /u/{username}/(followers|following)/         (v1.2.0)
 * - URL: /u/{username}/(followers|following)/page/{n}/ (v1.2.0)
 * - 訪客可看（依隱私設定過濾內容）
 * - 完整 SEO meta（OpenGraph / Twitter Card）
 * - 條件式 noindex（私人帳號 / 不存在使用者）
 *
 * Hook 流程：
 *   init → 註冊 rewrite rule + query var
 *   parse_request → 解析 username → user_id
 *   template_redirect → 主頁載入 page-public-profile.php；
 *                       followers/following 由 followers-page.php 接管。
 *
 * 提供給其他檔案的 helper：
 *   wxacg_get_public_profile_url( $user_id_or_login )
 *   wxacg_get_public_profile_user()
 *   smacg_is_public_profile_page()
 *   wxacg_can_view_profile_section( $user_id, $section )
 *   wxacg_pp_is_owner()
 *   wxacg_pp_has_seo_plugin()
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
   設定常數
   ============================================================ */
if ( ! defined( 'SMACG_PUBLIC_PROFILE_SLUG' ) ) {
    define( 'SMACG_PUBLIC_PROFILE_SLUG', 'u' );  // URL 前綴：/u/{username}/
}

/* ============================================================
   1. 註冊 rewrite rule + query var
   ============================================================ */
add_action( 'init', 'smacg_pp_register_rewrite' );
function smacg_pp_register_rewrite() {
    $slug = SMACG_PUBLIC_PROFILE_SLUG;

    // 主頁：/u/{username}/
    add_rewrite_rule(
        '^' . $slug . '/([^/]+)/?$',
        'index.php?smacg_pp_user=$matches[1]',
        'top'
    );

    // 主頁分頁：/u/{username}/page/{n}/
    add_rewrite_rule(
        '^' . $slug . '/([^/]+)/page/([0-9]+)/?$',
        'index.php?smacg_pp_user=$matches[1]&smacg_pp_paged=$matches[2]',
        'top'
    );

    // Tabs：/u/{username}/(watchlist|ratings|badges|activity|toplist)/
    add_rewrite_rule(
        '^' . $slug . '/([^/]+)/(watchlist|ratings|badges|activity|toplist)/?$',
        'index.php?smacg_pp_user=$matches[1]&smacg_pp_tab=$matches[2]',
        'top'
    );

    /*
     * 單一排行清單：/u/{username}/toplist/{id}/
     *
     * 這條要排在上面那條之前才會生效嗎？不用——WordPress 依序比對，上面
     * 那條的 pattern 以 /?$ 結尾，不會吃掉後面還有 /{id}/ 的網址。
     *
     * 為什麼要獨立網址而不是 /u/{name}/toplist/#id：
     * 井號片段不會送到伺服器，社群平台抓不到，分享卡片就只能顯示整個
     * 個人頁的資訊。排行要能單獨產生 OG 卡片，就必須有自己的網址。
     */
    add_rewrite_rule(
        '^' . $slug . '/([^/]+)/toplist/([0-9]+)/?$',
        'index.php?smacg_pp_user=$matches[1]&smacg_pp_tab=toplist&smacg_pp_toplist=$matches[2]',
        'top'
    );

    // v1.2.0：Followers / Following 列表頁
    add_rewrite_rule(
        '^' . $slug . '/([^/]+)/(followers|following)/?$',
        'index.php?smacg_pp_user=$matches[1]&smacg_pp_section=$matches[2]',
        'top'
    );

    // v1.2.0：Followers / Following 列表分頁
    add_rewrite_rule(
        '^' . $slug . '/([^/]+)/(followers|following)/page/([0-9]+)/?$',
        'index.php?smacg_pp_user=$matches[1]&smacg_pp_section=$matches[2]&smacg_pp_paged=$matches[3]',
        'top'
    );
}

add_filter( 'query_vars', 'smacg_pp_query_vars' );
function smacg_pp_query_vars( $vars ) {
    $vars[] = 'smacg_pp_user';
    $vars[] = 'smacg_pp_paged';
    $vars[] = 'smacg_pp_tab';
    $vars[] = 'smacg_pp_section';   // v1.2.0：'followers' | 'following'
    $vars[] = 'smacg_pp_toplist';   // 單一排行清單的 ID
    return $vars;
}

/* ============================================================
   2. 首次啟用 / 版本變更時自動 flush rewrite
   ------------------------------------------------------------
   v1.1.0：用 WXACG_SOCIAL_VERSION 比對，升版會自動 flush
   ============================================================ */
add_action( 'init', 'smacg_pp_maybe_flush_rewrite', 99 );
function smacg_pp_maybe_flush_rewrite() {
    $current = defined( 'WXACG_SOCIAL_VERSION' ) ? WXACG_SOCIAL_VERSION : '0.0.0';
    if ( get_option( 'smacg_pp_rewrite_flushed' ) !== $current ) {
        flush_rewrite_rules( false );
        update_option( 'smacg_pp_rewrite_flushed', $current );
    }
}

// 切換主題時清掉 flag，下次再 flush
add_action( 'switch_theme', function () {
    delete_option( 'smacg_pp_rewrite_flushed' );
} );

/* ============================================================
   3. 解析 URL → 找 user → 載入模板
   ------------------------------------------------------------
   v1.1.0：移除模糊比對 fallback（避免 /u/taro/ 跑去顯示 taro2）
   v1.2.0：偵測 smacg_pp_section（followers/following）時，
           只解析 user 並設定全域變數，模板載入交給 followers-page.php
   v1.3.0：include 模板前重置 $wp_query 狀態為虛擬 page，
           避免 body_class 殘留 home/blog 造成版面疊加跑版
   ============================================================ */
add_action( 'template_redirect', 'smacg_pp_dispatch' );
function smacg_pp_dispatch() {
    $username = get_query_var( 'smacg_pp_user' );
    if ( empty( $username ) ) return;

    $username = urldecode( $username );
    $user = get_user_by( 'login', $username );
    if ( ! $user ) $user = get_user_by( 'slug', $username );

    if ( ! $user ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
        return;
    }

    $GLOBALS['smacg_pp_user_obj'] = $user;

    /* ============================================================
       v1.3.1 修復：用「掛在 page-id-1 的虛擬 page 上」的方式重置 query
       ------------------------------------------------------------
       - 找一個現有 page（優先取 ID=1 之外的、設為「全寬無 sidebar」的 page）
       - 把它塞進 $wp_query->posts，讓主題拿得到 queried_object
       - 但實際模板用 template_include filter 切到 page-public-profile.php
       ============================================================ */
    global $wp_query;

    // 建立一個假的 WP_Post 物件（不查 DB，避免污染快取）
    $fake_post = new stdClass();
    $fake_post->ID             = 0;
    $fake_post->post_author    = $user->ID;
    $fake_post->post_date      = current_time( 'mysql' );
    $fake_post->post_date_gmt  = current_time( 'mysql', 1 );
    $fake_post->post_title     = ( $user->display_name ?: $user->user_login ) . ' 的個人頁';
    $fake_post->post_content   = '';
    $fake_post->post_status    = 'publish';
    $fake_post->comment_status = 'closed';
    $fake_post->ping_status    = 'closed';
    $fake_post->post_name      = 'u-' . $user->user_nicename;
    $fake_post->post_type      = 'page';
    $fake_post->filter         = 'raw';
    $fake_post = new WP_Post( $fake_post );

    $wp_query->posts                = [ $fake_post ];
    $wp_query->post                 = $fake_post;
    $wp_query->queried_object       = $fake_post;
    $wp_query->queried_object_id    = 0;
    $wp_query->post_count           = 1;
    $wp_query->current_post         = -1;
    $wp_query->found_posts          = 1;
    $wp_query->max_num_pages        = 1;
    $wp_query->is_home              = false;
    $wp_query->is_front_page        = false;
    $wp_query->is_archive           = false;
    $wp_query->is_category          = false;
    $wp_query->is_tag               = false;
    $wp_query->is_tax               = false;
    $wp_query->is_author            = false;
    $wp_query->is_search            = false;
    $wp_query->is_feed              = false;
    $wp_query->is_404               = false;
    $wp_query->is_posts_page        = false;
    $wp_query->is_post_type_archive = false;
    $wp_query->is_singular          = true;
    $wp_query->is_page              = true;
    $wp_query->is_single            = false;

    // 讓 $post 全域同步
    $GLOBALS['post'] = $fake_post;
    setup_postdata( $fake_post );

    status_header( 200 );
    nocache_headers();

    // followers/following 子頁交給 followers-page.php
    $section = get_query_var( 'smacg_pp_section' );
    if ( $section === 'followers' || $section === 'following' ) {
        add_action( 'wp_head', 'smacg_pp_render_meta_tags', 1 );
        add_filter( 'wpseo_canonical',                       'smacg_pp_filter_seo_canonical', 10, 1 );
        add_filter( 'rank_math/frontend/canonical',          'smacg_pp_filter_seo_canonical', 10, 1 );
        add_filter( 'aioseo_canonical_url',                  'smacg_pp_filter_seo_canonical', 10, 1 );
        add_filter( 'wpseo_opengraph_title',                 'smacg_pp_filter_seo_title', 10, 1 );
        add_filter( 'rank_math/opengraph/facebook/og_title', 'smacg_pp_filter_seo_title', 10, 1 );
        return;
    }

    // SEO hooks
    add_action( 'wp_head', 'smacg_pp_render_meta_tags', 1 );
    add_filter( 'wpseo_canonical',                       'smacg_pp_filter_seo_canonical', 10, 1 );
    add_filter( 'rank_math/frontend/canonical',          'smacg_pp_filter_seo_canonical', 10, 1 );
    add_filter( 'aioseo_canonical_url',                  'smacg_pp_filter_seo_canonical', 10, 1 );
    add_filter( 'wpseo_opengraph_title',                 'smacg_pp_filter_seo_title', 10, 1 );
    add_filter( 'rank_math/opengraph/facebook/og_title', 'smacg_pp_filter_seo_title', 10, 1 );

    /*
     * 排行頁的描述與圖片也要蓋掉 SEO 外掛的預設值，否則卡片會是
     * 「某某在微笑動漫的追番清單與評分」配上頭像——看不出是哪份榜單。
     */
    add_filter( 'rank_math/opengraph/facebook/og_description', 'wxacg_toplist_filter_og_desc', 10, 1 );
    add_filter( 'rank_math/opengraph/facebook/og_image',       'wxacg_toplist_filter_og_image', 10, 1 );
    add_filter( 'rank_math/opengraph/twitter/twitter_title',   'smacg_pp_filter_seo_title', 10, 1 );
    add_filter( 'rank_math/opengraph/twitter/twitter_description', 'wxacg_toplist_filter_og_desc', 10, 1 );
    add_filter( 'rank_math/opengraph/twitter/twitter_image',   'wxacg_toplist_filter_og_image', 10, 1 );
    add_filter( 'wpseo_opengraph_desc',                        'wxacg_toplist_filter_og_desc', 10, 1 );
    add_filter( 'wpseo_opengraph_image',                       'wxacg_toplist_filter_og_image', 10, 1 );

    /*
     * ★ <title> 與 robots
     *
     * 上面那幾條只處理 canonical 與「社群分享用的 og:title」，瀏覽器分頁
     * 與搜尋結果顯示的 <title> 從來沒被設定過——公開檔案頁因此一律顯示
     * 首頁的標題「微笑動漫 | 動漫新聞、新番情報與動漫評論」，每個人的
     * 頁面看起來都一樣。
     *
     * robots 同樣：/u/ 是用 rewrite rule + fake post 生出來的虛擬頁面，
     * Rank Math 認不得這種頁面就套用預設值，實測輸出 "follow, noindex"
     * ——注意那是 Rank Math 的格式（逗號後有空格），與本檔隱私判斷輸出的
     * "noindex,nofollow" 不同，可見並非隱私設定造成，而是所有公開檔案
     * 都被擋在搜尋結果之外。
     *
     * 這裡明確接管兩者：標題用「{暱稱} 的個人頁」，robots 則依使用者
     * 自己的隱私設定決定（預設公開 → index）。
     */
    add_filter( 'pre_get_document_title', 'smacg_pp_filter_document_title', 99 );
    add_filter( 'rank_math/frontend/robots', 'smacg_pp_filter_robots', 99 );

    /*
     * canonical 另外用高優先序再掛一次。
     *
     * 上面第 258 行已經掛過 rank_math/frontend/canonical（優先序 10），
     * 但實測輸出的仍是首頁網址。Rank Math 的 generate_canonical() 會先
     * 判斷 is_front_page()——而 /u/xxx/ 經 rewrite 後只帶 smacg_pp_user
     * 一個參數，WordPress 原生解析會認為那是首頁；本檔雖然在
     * $wp_query 上把 is_front_page 改成 false，時序上仍可能晚於
     * Rank Math 取值。優先序 99 確保最後一手是我們的值。
     *
     * 同樣的道理套用在 description：首頁的描述會被套到每個人的個人頁。
     */
    add_filter( 'rank_math/frontend/canonical', 'smacg_pp_filter_seo_canonical', 99, 1 );
    add_filter( 'rank_math/frontend/description', 'smacg_pp_filter_description', 99, 1 );

    /* ★ 核心：用 template_include filter 切模板，讓 Blocksy 正常走完版面流程 */
    add_filter( 'template_include', function( $template ) {
        $custom = locate_template( 'page-public-profile.php' );
        return $custom ?: $template;
    }, 999 );

    /* ★ 告訴 Blocksy 走「全寬無 sidebar」layout */
    add_filter( 'blocksy:dynamic-styles-descriptor', '__return_empty_array' );
    add_filter( 'blocksy:options:single:page:default-layout', function() { return 'wide'; });
    add_filter( 'blocksy:pro:page:has-sidebar', '__return_false' );
    add_filter( 'option_blocksy_post_meta', function( $v ) {
        // 強制此頁為 wide / no sidebar / no hero
        return is_array( $v ) ? array_merge( $v, [
            'sidebar' => 'no',
            'content_style' => 'wide',
            'has_hero_section' => 'no',
        ]) : $v;
    });
}

/* ============================================================
   4. SEO Meta Tags
   ------------------------------------------------------------
   v1.1.0：
     - 偵測到 Yoast / Rank Math / AIOSEO 啟用時，不自前 echo canonical
       （改由上面的 filter 提供 URL）
     - OG / Twitter tags 仍照發（這些 SEO 外掛通常會跳過 user 頁面）
   ============================================================ */
function smacg_pp_render_meta_tags() {
    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return;

    $privacy = function_exists( 'smacg_get_user_privacy' )
        ? smacg_get_user_privacy( $user->ID )
        : [];

    $display = $user->display_name ?: $user->user_login;
    $bio     = get_user_meta( $user->ID, 'description', true );
    $url     = wxacg_get_public_profile_url( $user );

    // 頭像
    $avatar  = '';
    $aid     = (int) get_user_meta( $user->ID, 'smacg_avatar_id', true );
    if ( $aid && wp_attachment_is_image( $aid ) ) {
        $img = wp_get_attachment_image_src( $aid, 'full' );
        if ( $img ) $avatar = $img[0];
    }
    if ( ! $avatar ) {
        $avatar = get_avatar_url( $user->ID, [ 'size' => 400 ] );
    }

    // 隱私帳號 → noindex
    $is_private = empty( $privacy['public_profile'] );
    if ( $is_private ) {
        echo '<meta name="robots" content="noindex,nofollow">' . "\n";
    }

    $title = sprintf( '%s 的個人頁 - %s', $display, get_bloginfo( 'name' ) );

    /*
     * 單一排行清單的專屬分享卡片。
     *
     * 這是整個排行功能能不能被分享出去的關鍵：貼到 Threads／Discord 時，
     * 對方先看到卡片才決定要不要點。用清單名稱當標題、前幾名當描述、
     * 第一名的封面當圖，卡片就會長得像一張推薦榜而不是某人的個人頁。
     *
     * twitter:card 一併改成 summary_large_image——預設的 summary 是小方圖，
     * 封面直式海報在小卡裡幾乎看不清楚。
     */
    $tl = function_exists( 'wxacg_toplist_current_meta' ) ? wxacg_toplist_current_meta() : null;
    if ( $tl ) {
        printf( "<meta property=\"og:type\" content=\"article\">\n" );
        printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $tl['title'] ) );
        printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( wp_strip_all_tags( $tl['desc'] ) ) );
        printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $tl['url'] ) );
        printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $tl['image'] ) );
        printf( "<meta name=\"twitter:card\" content=\"summary_large_image\">\n" );
        printf( "<meta name=\"twitter:title\" content=\"%s\">\n", esc_attr( $tl['title'] ) );
        printf( "<meta name=\"twitter:description\" content=\"%s\">\n", esc_attr( wp_strip_all_tags( $tl['desc'] ) ) );
        printf( "<meta name=\"twitter:image\" content=\"%s\">\n", esc_url( $tl['image'] ) );

        // 已輸出排行專屬標籤，不要再輸出個人頁那組，否則同名標籤重複
        return;
    }
    $desc  = $bio ?: sprintf( '%s 在 %s 的追番清單與評分', $display, get_bloginfo( 'name' ) );

    echo "\n<!-- SMACG Public Profile Meta -->\n";
    printf( "<meta property=\"og:type\" content=\"profile\">\n" );
    printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $title ) );
    printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( wp_strip_all_tags( $desc ) ) );
    printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $url ) );
    printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $avatar ) );
    printf( "<meta property=\"profile:username\" content=\"%s\">\n", esc_attr( $user->user_login ) );
    printf( "<meta name=\"twitter:card\" content=\"summary\">\n" );
    printf( "<meta name=\"twitter:title\" content=\"%s\">\n", esc_attr( $title ) );
    printf( "<meta name=\"twitter:description\" content=\"%s\">\n", esc_attr( wp_strip_all_tags( $desc ) ) );
    printf( "<meta name=\"twitter:image\" content=\"%s\">\n", esc_url( $avatar ) );

    // v1.1.0：只有在沒裝 SEO 外掛時才自前發 canonical
    if ( ! wxacg_pp_has_seo_plugin() ) {
        printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $url ) );
    }
    echo "<!-- /SMACG Public Profile Meta -->\n\n";
}

/**
 * v1.1.0 helper：偵測是否有主流 SEO 外掛
 */
function wxacg_pp_has_seo_plugin() {
    return defined( 'WPSEO_VERSION' )                 // Yoast
        || defined( 'RANK_MATH_VERSION' )              // Rank Math
        || defined( 'AIOSEO_VERSION' )                 // All in One SEO
        || class_exists( 'WPSEO_Frontend' )
        || class_exists( 'RankMath' );
}

/**
 * v1.1.0 filter：覆寫 SEO 外掛的 canonical URL
 */
function smacg_pp_filter_seo_canonical( $canonical ) {
    /*
     * 不能只靠 smacg_is_public_profile_page()——它讀的
     * $GLOBALS['smacg_pp_user_obj'] 要等 smacg_pp_dispatch()
     * （template_redirect）才會設定。
     *
     * 但 Rank Math 的 Paper::get_canonical() 只計算一次就快取結果，
     * 可能早於 template_redirect 就取值；那時這個 filter 雖然已經掛上，
     * 卻會因為全域變數還沒設而直接原樣回傳，canonical 就永遠停在
     * generate_canonical() 給的首頁網址。
     *
     * 因此改為：全域變數優先，取不到就退回 query var 自行解析。
     * description / robots 兩條 filter 掛在 dispatch 之後執行，沒有這個
     * 時序問題，維持原樣不動。
     */
    $user = smacg_is_public_profile_page() ? wxacg_get_public_profile_user() : null;

    if ( ! $user ) {
        $username = (string) get_query_var( 'smacg_pp_user' );
        if ( $username === '' ) {
            return $canonical;
        }
        $username = urldecode( $username );
        $user     = get_user_by( 'login', $username ) ?: get_user_by( 'slug', $username );
    }

    if ( ! $user ) return $canonical;

    return wxacg_get_public_profile_url( $user );
}

/**
 * v1.1.0 filter：覆寫 SEO 外掛的 OG title
 */
function smacg_pp_filter_seo_title( $title ) {
    if ( ! smacg_is_public_profile_page() ) return $title;

    // 單一排行頁：改用排行名稱，否則分享卡片會顯示「某某的個人頁」
    $tl = function_exists( 'wxacg_toplist_current_meta' ) ? wxacg_toplist_current_meta() : null;
    if ( $tl ) return $tl['title'];

    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return $title;
    $display = $user->display_name ?: $user->user_login;
    return sprintf( '%s 的個人頁 - %s', $display, get_bloginfo( 'name' ) );
}

/**
 * 單一排行頁的 og:description / og:image。
 *
 * 與 smacg_pp_render_meta_tags() 共用 wxacg_toplist_current_meta()，
 * 兩條輸出路徑的內容因此必定一致——SEO 外掛那組與本外掛自己 printf 的
 * 那組若說法不同，社群平台取到哪一個就變成擲骰子。
 */
function wxacg_toplist_filter_og_desc( $desc ) {
    $tl = function_exists( 'wxacg_toplist_current_meta' ) ? wxacg_toplist_current_meta() : null;
    return $tl ? wp_strip_all_tags( $tl['desc'] ) : $desc;
}

function wxacg_toplist_filter_og_image( $image ) {
    $tl = function_exists( 'wxacg_toplist_current_meta' ) ? wxacg_toplist_current_meta() : null;
    return ( $tl && $tl['image'] ) ? $tl['image'] : $image;
}

/**
 * 瀏覽器分頁與搜尋結果顯示的 <title>。
 *
 * 與上面的 og:title 分開：那條只影響社群分享卡片。<title> 從未被設定，
 * 導致每個人的公開檔案都顯示首頁標題。文案刻意與 og:title 一致，
 * 分享出去與搜尋到的是同一個名字。
 *
 * 子頁（followers / following / watchlist…）附上區段名，避免同一個人的
 * 多個分頁在搜尋結果裡標題完全相同、分不出差別。
 */
function smacg_pp_filter_document_title( $title ) {
    if ( ! smacg_is_public_profile_page() ) return $title;
    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return $title;

    $display = $user->display_name ?: $user->user_login;

    $section_labels = [
        'followers' => '的粉絲',
        'following' => '追蹤中',
        'watchlist' => '的追番清單',
        'ratings'   => '的評分',
        'badges'    => '的成就',
        'activity'  => '的動態',
    ];

    $section = (string) get_query_var( 'smacg_pp_section' );
    $tab     = (string) get_query_var( 'smacg_pp_tab' );
    $key     = $section !== '' ? $section : $tab;
    $suffix  = $section_labels[ $key ] ?? '的個人頁';

    return sprintf( '%s%s - %s', $display, $suffix, get_bloginfo( 'name' ) );
}

/**
 * meta description：沿用個人頁自己的簡介，而非套用首頁描述。
 *
 * 與 canonical 同一個成因（Rank Math 把 /u/ 當首頁處理），若不接管，
 * 每個人的個人頁在搜尋結果裡的描述都會是「微笑動漫是…動漫資訊站」。
 */
function smacg_pp_filter_description( $desc ) {
    if ( ! smacg_is_public_profile_page() ) return $desc;
    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return $desc;

    $display = $user->display_name ?: $user->user_login;
    $bio     = trim( (string) get_user_meta( $user->ID, 'description', true ) );

    return $bio !== ''
        ? $bio
        : sprintf( '%s 在 %s 的追番清單與評分', $display, get_bloginfo( 'name' ) );
}

/**
 * robots：依使用者自己的隱私設定決定，而非交給 Rank Math 猜。
 *
 * /u/ 是 rewrite rule + fake post 生出來的虛擬頁面，Rank Math 認不得
 * 就套用預設，實測所有公開檔案都被輸出成 noindex——包括那些明確把
 * 檔案設為公開的使用者。個人頁其實是不錯的長尾內容（追番清單、評分），
 * 全部擋在搜尋之外沒有道理。
 *
 * 隱私優先：使用者關掉 public_profile 就 noindex，這條不能被覆蓋。
 */
function smacg_pp_filter_robots( $robots ) {
    if ( ! smacg_is_public_profile_page() ) return $robots;
    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return $robots;

    $privacy = function_exists( 'smacg_get_user_privacy' )
        ? smacg_get_user_privacy( $user->ID )
        : [];

    // 預設公開（見 smacg_get_user_privacy 的 $defaults）
    $is_public = ! empty( $privacy['public_profile'] );

    $robots['index']  = $is_public ? 'index' : 'noindex';
    $robots['follow'] = 'follow';

    return $robots;
}

/* ============================================================
   5. 提供給模板與其他檔案的 helper 函式
   ============================================================ */

/**
 * 取得任一使用者的公開頁 URL
 */
function wxacg_get_public_profile_url( $user_id_or_login ) {
    $user = null;
    if ( $user_id_or_login instanceof WP_User ) {
        $user = $user_id_or_login;
    } elseif ( is_numeric( $user_id_or_login ) ) {
        $user = get_userdata( (int) $user_id_or_login );
    } elseif ( is_string( $user_id_or_login ) ) {
        $user = get_user_by( 'login', $user_id_or_login );
    }
    if ( ! $user ) return home_url( '/' );

    $slug = SMACG_PUBLIC_PROFILE_SLUG;
    $name = $user->user_nicename ?: $user->user_login;

    return home_url( '/' . $slug . '/' . rawurlencode( $name ) . '/' );
}

/**
 * 在公開頁內取得當前正在顯示的 user 物件
 */
function wxacg_get_public_profile_user() {
    return $GLOBALS['smacg_pp_user_obj'] ?? null;
}

/**
 * 判斷當前頁面是否為公開個人頁
 *
 * v1.2.0：因為 smacg_pp_dispatch() 對 followers/following 子頁也會設定
 *         $GLOBALS['smacg_pp_user_obj']，本函式對所有子頁自動回傳 true，
 *         使主題 setup-enqueue.php 的 public-profile.css/js 沿用至 followers/following 頁。
 */
function smacg_is_public_profile_page() {
    return ! empty( $GLOBALS['smacg_pp_user_obj'] );
}

/**
 * 判斷觀看者能否看到某使用者的某區塊
 */
function wxacg_can_view_profile_section( $owner_id, $section ) {
    $owner_id = (int) $owner_id;
    if ( ! $owner_id ) return false;

    if ( get_current_user_id() === $owner_id ) {
        return true;
    }

    $privacy = function_exists( 'smacg_get_user_privacy' )
        ? smacg_get_user_privacy( $owner_id )
        : [];

    switch ( $section ) {
        case 'profile':
            return ! empty( $privacy['public_profile'] );
        case 'watchlist':
            return ! empty( $privacy['public_profile'] )
                && ! empty( $privacy['public_watchlist'] );
        case 'ratings':
            return ! empty( $privacy['public_profile'] );
        case 'activity':
            return ! empty( $privacy['public_profile'] );
        case 'email':
            return ! empty( $privacy['show_email'] );
        case 'continue_watching':
            return ! empty( $privacy['public_profile'] )
                && ! empty( $privacy['show_continue_watching'] );
        default:
            return false;
    }
}

/**
 * 判斷觀看者是否為頁面擁有者本人
 */
function wxacg_pp_is_owner() {
    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return false;
    return get_current_user_id() === (int) $user->ID;
}

/* ============================================================
   6. 新使用者預設隱私
   ------------------------------------------------------------
   v1.2.1 修復：回滾為單一 'smacg_privacy' 陣列 meta，
   與資料層（member-stats.php 的 smacg_get_user_privacy / member-ajax.php
   的 smacg_update_privacy）一致。

   背景：v1.2.0 曾改寫為 4 個扁平裸 key（public_profile / public_watchlist /
   show_email / show_continue_watching），目的是配合計畫中的 privacy.php
   抽離層；但 privacy.php 最終因與 member-stats.php 內既有的
   smacg_get_user_privacy() 重複宣告而被永久刪除，
   殘留的扁平裸 key 寫法導致新註冊用戶預設值被寫到沒人讀的位置。
   現在改回單一陣列寫法，並僅在尚未存在時才寫入，
   避免覆寫使用者後續儲存的偏好。
   ============================================================ */
add_action( 'user_register', 'smacg_pp_set_default_privacy', 20 );
function smacg_pp_set_default_privacy( $user_id ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) return;

    // 已存在則不覆寫，保留使用者自身設定（含其他來源預先寫入）。
    $existing = get_user_meta( $user_id, 'smacg_privacy', true );
    if ( is_array( $existing ) && ! empty( $existing ) ) {
        return;
    }

    update_user_meta( $user_id, 'smacg_privacy', [
        'show_email'             => 0, // 預設遮罩 email
        'public_profile'         => 1, // 預設公開個人頁
        'public_watchlist'       => 1, // 預設公開追番列表
        'show_continue_watching' => 1, // 預設顯示「繼續觀看」
    ] );
}

/* ============================================================
   7. 後台連結（admin bar 加「公開頁」捷徑）
   ============================================================ */
add_action( 'admin_bar_menu', 'smacg_pp_admin_bar_link', 100 );
function smacg_pp_admin_bar_link( $wp_admin_bar ) {
    if ( ! is_user_logged_in() ) return;
    if ( ! current_user_can( 'read' ) ) return;

    $wp_admin_bar->add_node( [
        'id'    => 'smacg-public-profile',
        'title' => '👁 查看公開頁',
        'href'  => wxacg_get_public_profile_url( get_current_user_id() ),
        'meta'  => [ 'title' => '查看你的公開個人頁' ],
    ] );
}

/* ============================================================
   8. Hero 區等級徽章注入（Batch 2A-4）
   ============================================================ */
add_action( 'wp_footer', function () {
    if ( ! smacg_is_public_profile_page() ) return;

    // v1.2.0：followers/following 子頁的 hero 由 followers-page.php 自行渲染，
    //         不需要此 JS 注入；只在主頁與 tab 子頁執行。
    $section = get_query_var( 'smacg_pp_section' );
    if ( $section === 'followers' || $section === 'following' ) return;

    $user = wxacg_get_public_profile_user();
    if ( ! $user ) return;

    $badge = function_exists( 'smacg_render_level_badge' )
        ? smacg_render_level_badge( $user->ID, 'lg', [ 'show_job' => true ] )
        : '';
    if ( ! $badge ) return;
    ?>
    <script>
    (function(){
        if (document.querySelector('.smacg-pp-hero .smacg-lvbadge')) return;
        var anchor = document.querySelector('.smacg-pp-hero h1, .smacg-pp-hero .pp-name, .pp-hero h1');
        if (!anchor) return;
        var wrap = document.createElement('div');
        wrap.className = 'smacg-pp-hero__lvbadge';
        wrap.style.marginTop = '8px';
        wrap.innerHTML = <?php echo wp_json_encode( $badge ); ?>;
        anchor.parentNode.insertBefore(wrap, anchor.nextSibling);
    })();
    </script>
    <?php
}, 99 );

/* ============================================================
   canonical：在檔案載入時就掛，不等 template_redirect
   ============================================================
   Rank Math 的 Paper::get_canonical() 只計算一次就把結果快取在
   $this->canonical，之後再掛 filter 都來不及：

       if ( is_null( $this->canonical ) ) { $this->generate_canonical(); }

   而 smacg_pp_dispatch() 是掛在 template_redirect 才執行的，若 Rank Math
   在那之前已經為了 og:url 之類取過值，個人頁的 canonical 就會停在
   generate_canonical() 裡 is_front_page() 分支給的首頁網址
   （/u/xxx/ 經 rewrite 後只帶 smacg_pp_user 一個參數，WordPress 原生
     解析會判定為首頁）。

   這裡在檔案載入階段就掛上，確保早於任何取值。filter 本身開頭就用
   smacg_is_public_profile_page() 判斷，非個人頁一律原樣回傳，
   全域掛載不會影響其他頁面。

   註：description 與 robots 用 template_redirect 掛（優先序 99）即可
   生效，實測確認過；只有 canonical 因為上述的一次性快取需要提早。
   ============================================================ */
add_filter( 'rank_math/frontend/canonical', 'smacg_pp_filter_seo_canonical', 99, 1 );
add_filter( 'wpseo_canonical',              'smacg_pp_filter_seo_canonical', 99, 1 );
add_filter( 'aioseo_canonical_url',         'smacg_pp_filter_seo_canonical', 99, 1 );
