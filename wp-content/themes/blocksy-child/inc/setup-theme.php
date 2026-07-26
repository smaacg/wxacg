<?php
/**
 * 主題設定：after_setup_theme / 選單 / 防快取 / Widgets / 摘要 / 搜尋表單
 *
 * @package weixiaoacg
 * @subpackage Setup
 *
 * @version 1.3.0 (2026-06-01)
 * 變更紀錄：
 * - v1.3.0 (2026-06-01) 修正「登入後仍顯示未登入、可點註冊」：
 *     移除 v1.2.0 對「訪客版」硬塞 no-store 的 else 分支。該分支讓全站
 *     每個頁面都被標成不可快取，不僅毀掉效能，且在 bfcache 行為上反而
 *     更不可預測。bfcache 重用舊訪客版的問題已改由前端
 *     header.js v1.4.0 的 location.replace(url + 一次性參數) 解決。
 *     現在僅對「登入者」送 nocache_headers()，訪客版交給瀏覽器正常快取。
 * - v1.2.0 (2026-06-01) 修正「登入後仍須按 F5」：訪客版頁面新增 no-store，
 *     阻止瀏覽器把訪客版整頁存進 bfcache / disk cache。（已於 v1.3.0 移除）
 * - v1.1.0 (2026-06-01) 防快取擴大：登入者／任何頁面送出 no-cache。
 *     改用 WP 內建 nocache_headers()。
 */
defined( 'ABSPATH' ) || exit;

/* ================================================================
   主題設定
   ================================================================ */
add_action( 'after_setup_theme', function() {
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'title-tag' );
    add_theme_support( 'html5', ['search-form','comment-form','comment-list','gallery','caption','style','script'] );
    add_theme_support( 'custom-logo', ['height'=>40,'width'=>180,'flex-height'=>true,'flex-width'=>true] );
    add_theme_support( 'menus' );

    foreach ([
        'weixiaoacg-cover'  => [300, 420, true],
        'weixiaoacg-banner' => [1280,400, true],
        'weixiaoacg-thumb'  => [160, 224, true],
        'anime-thumb'   => [300, 420, true],
        'news-thumb'    => [800, 450, true],
        'season-thumb'  => [180, 260, true],
    ] as $name => [$w,$h,$c]) add_image_size($name,$w,$h,$c);

    load_child_theme_textdomain( 'weixiaoacg', weixiaoacg_THEME_DIR . '/languages' );
} );

/* ================================================================
   選單
   ================================================================ */
add_action( 'after_setup_theme', function() {
    register_nav_menus([
        'primary-menu' => '主導覽選單', 'footer-menu'  => '頁腳選單',
        'more-menu'    => '更多下拉選單','primary'     => '主選單',
        'secondary'    => '次要選單',   'footer-col-1' => '頁腳欄位 1',
        'footer-col-2' => '頁腳欄位 2', 'footer-col-3' => '頁腳欄位 3',
        'footer-col-4' => '頁腳欄位 4',
    ]);
} );

add_action( 'admin_init', function() {
    $locs = get_nav_menu_locations();
    foreach ( array_keys( get_registered_nav_menus() ) as $loc )
        if ( empty($locs[$loc]) ) $locs[$loc] = 0;
    set_theme_mod( 'nav_menu_locations', $locs );
} );

add_filter( 'wp_nav_menu_objects', function($items,$args) {
    $in = is_user_logged_in();
    foreach ( $items as $k => $item ) {
        $c = (array)$item->classes;
        if ( in_array('logged-in-only',$c) && ! $in ) unset($items[$k]);
        if ( in_array('logged-out-only',$c) && $in  ) unset($items[$k]);
    }
    return $items;
}, 10, 2 );

/* ================================================================
   防快取：僅對登入者送 no-cache（v1.3.0）
   ----------------------------------------------------------------
   登入者瀏覽任何頁面，一律送出 nocache_headers()，永遠拿最新登入版，
   避免吃到伺服器層或瀏覽器快取下來的「訪客版」整頁。

   訪客版不再硬塞 no-store，讓瀏覽器正常快取以保效能；
   「登入後 bfcache 重用舊訪客版」的問題，已由前端 header.js v1.4.0
   的 location.replace(url + 一次性參數) 從根本解決。
   ================================================================ */
add_action('template_redirect', function() {

    // 後台、AJAX、REST 不處理，避免影響其他流程
    if ( is_admin() || wp_doing_ajax() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
        return;
    }

    $is_um  = function_exists('um_is_core_page') && um_is_core_page('user');
    $is_mem = is_page_template('page-member.php');

    if ( is_user_logged_in() || $is_um || $is_mem ) {
        // 登入者：完整 no-cache
        nocache_headers(); // WP 內建：no-cache, no-store, must-revalidate, max-age=0 等組合
    }
    // 訪客版：不額外送 header，交給瀏覽器正常快取
}, 1);

/* ================================================================
   摘要
   ================================================================ */
add_filter('excerpt_length', fn()=>60);
add_filter('excerpt_more',   fn()=>'…');

/* ================================================================
   搜尋表單
   ================================================================ */
add_filter('get_search_form', function() {
    ob_start(); ?>
    <form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
        <div class="search-box glass-light">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="search" id="search-input" name="s"
                   placeholder="<?php esc_attr_e('搜尋動畫、角色、聲優、新聞…','weixiaoacg'); ?>"
                   value="<?php echo esc_attr(get_search_query()); ?>" autocomplete="off"/>
            <button type="submit" class="btn-icon btn-ghost" aria-label="<?php esc_attr_e('搜尋','weixiaoacg'); ?>">
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
    </form>
    <?php return ob_get_clean();
});

/* ================================================================
   Widgets
   ================================================================ */
add_action('widgets_init', function() {
    register_sidebar(['name'=>'頁腳 Widget 區 1','id'=>'footer-1',
        'before_widget'=>'<div class="footer-widget">','after_widget'=>'</div>',
        'before_title'=>'<h5 class="footer-widget-title">','after_title'=>'</h5>']);
    register_sidebar(['name'=>'側欄 Widget 區','id'=>'sidebar-1',
        'before_widget'=>'<div class="sidebar-card glass-mid">','after_widget'=>'</div>',
        'before_title'=>'<div class="rank-sidebar-title">','after_title'=>'</div>']);
});
