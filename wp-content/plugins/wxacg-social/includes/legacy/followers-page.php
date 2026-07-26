<?php
/**
 * SMACG Social - Followers / Following Page (Legacy)
 *
 * @package    weixiaoacg
 * @subpackage wxacg-social
 * @version    1.0.2
 * @since      1.0.0
 *
 * Changelog:
 * - 1.0.2 (2026-05-26)
 *   * 【Bug E 修正】私人頁從 HTTP 200 改為 403，並加 X-Robots-Tag header
 *     與 <meta name="robots" content="noindex,nofollow">。
 *     原因：私人帳號的粉絲/追蹤列表不應被搜尋引擎索引，
 *     回 200 會讓爬蟲認為這是有效頁面並可能收錄空殼/隱私提示頁。
 * - 1.0.1 (2026-05-26)
 *   * 修正：get_user_by('login') 失敗時 fallback 到 'slug'，與 public-profile.php 對齊。
 *     避免中文 login 或 user_nicename ≠ user_login 的使用者進 /u/{nicename}/followers/ 時 404。
 * - 1.0.0 (2026-05-16)
 *   * 初始版本：template_redirect 處理、隱私 gate、24/page 分頁、AJAX 載入更多、卡片渲染。
 *   * 新增 smacg_is_mutual_follow() helper。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WXACG_FOLLOWERS_PAGE_SIZE' ) ) {
    define( 'WXACG_FOLLOWERS_PAGE_SIZE', 24 );
}

/* ========================================================================
 * 互追判定 helper
 * ====================================================================== */

if ( ! function_exists( 'smacg_is_mutual_follow' ) ) :
function smacg_is_mutual_follow( $user_a, $user_b ) {
    $user_a = (int) $user_a;
    $user_b = (int) $user_b;
    if ( $user_a <= 0 || $user_b <= 0 || $user_a === $user_b ) return false;
    if ( ! function_exists( 'wxacg_is_following' ) ) return false;
    return wxacg_is_following( $user_a, $user_b ) && wxacg_is_following( $user_b, $user_a );
}
endif;

/* ========================================================================
 * Template Redirect：偵測 followers/following 子頁並輸出
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_page_template_redirect' ) ) :
function wxacg_followers_page_template_redirect() {
    $section = get_query_var( 'smacg_pp_section' );
    if ( $section !== 'followers' && $section !== 'following' ) return;

    $username = get_query_var( 'smacg_pp_user' );
    if ( ! $username ) return;

    /* v1.0.1：login 失敗 fallback slug，與 public-profile.php 對齊 */
    $username = urldecode( $username );
    $user = get_user_by( 'login', $username );
    if ( ! $user ) $user = get_user_by( 'slug', $username );

    if ( ! $user || ! ( $user instanceof WP_User ) ) {
        status_header( 404 );
        nocache_headers();
        include get_query_template( '404' );
        exit;
    }

    $privacy = function_exists( 'smacg_get_user_privacy' )
        ? smacg_get_user_privacy( $user->ID )
        : [ 'public_profile' => '1' ];

    if ( empty( $privacy['public_profile'] ) && get_current_user_id() !== (int) $user->ID ) {
        wxacg_followers_render_private_page( $user, $section );
        exit;
    }

    wxacg_followers_render_page( $user, $section );
    exit;
}
add_action( 'template_redirect', 'wxacg_followers_page_template_redirect', 11 );
endif;

/* ========================================================================
 * 私人頁面渲染
 * ------------------------------------------------------------------------
 * v1.0.2 Bug E 修正：
 *   - status_header 從 200 改為 403
 *   - 加 X-Robots-Tag header（HTTP 層噪音 robot 也擋）
 *   - wp_head 注入 <meta name="robots" content="noindex,nofollow">
 *   - 防止搜尋引擎索引私人帳號的粉絲/追蹤殼頁
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_render_private_page' ) ) :
function wxacg_followers_render_private_page( $user, $section ) {
    status_header( 403 );
    nocache_headers();

    if ( ! headers_sent() ) {
        header( 'X-Robots-Tag: noindex, nofollow', true );
    }

    add_action( 'wp_head', function() {
        echo '<meta name="robots" content="noindex,nofollow">' . "\n";
    }, 1 );

    get_header();
    ?>
    <main class="smacg-followers-page smacg-followers-private">
        <div class="container">
            <section class="pp-private">
                <div class="pp-private-icon">🔒</div>
                <h1>此使用者已將個人頁設為私人</h1>
                <p>無法檢視 <strong><?php echo esc_html( $user->display_name ); ?></strong> 的<?php echo $section === 'followers' ? '粉絲' : '追蹤'; ?>列表。</p>
                <p><a class="pp-btn pp-btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">返回首頁</a></p>
            </section>
        </div>
    </main>
    <?php
    get_footer();
}
endif;

/* ========================================================================
 * 主頁面渲染
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_render_page' ) ) :
function wxacg_followers_render_page( $user, $section ) {
    $uid       = (int) $user->ID;
    $is_owner  = ( get_current_user_id() === $uid );
    $paged     = max( 1, (int) get_query_var( 'smacg_pp_paged' ) );
    $per_page  = WXACG_FOLLOWERS_PAGE_SIZE;
    $offset    = ( $paged - 1 ) * $per_page;

    $list_data = wxacg_followers_get_user_list( $uid, $section, $per_page, $offset );
    $user_ids  = $list_data['ids'];
    $total     = $list_data['total'];
    $total_pages = max( 1, (int) ceil( $total / $per_page ) );

    $title_label = ( $section === 'followers' ) ? '粉絲' : '追蹤中';
    $page_title  = sprintf( '%s 的%s - %s', $user->display_name, $title_label, get_bloginfo( 'name' ) );

    add_filter( 'pre_get_document_title', function() use ( $page_title ) { return $page_title; }, 99 );
    add_action( 'wp_head', function() use ( $user, $section ) {
        echo '<meta name="robots" content="noindex,follow">' . "\n";
        echo '<link rel="canonical" href="' . esc_url( wxacg_followers_get_page_url( $user, $section ) ) . '">' . "\n";
    }, 1 );


    nocache_headers();
    get_header();
    ?>
    <main class="smacg-followers-page" data-section="<?php echo esc_attr( $section ); ?>" data-user-id="<?php echo esc_attr( $uid ); ?>">
        <div class="container">

            <header class="smacg-followers-header">
                <a class="smacg-followers-back" href="<?php echo esc_url( wxacg_get_public_profile_url( $user ) ); ?>">
                    ← 返回 <?php echo esc_html( $user->display_name ); ?> 的個人頁
                </a>
                <h1 class="smacg-followers-title">
                    <?php echo esc_html( $user->display_name ); ?> 的<?php echo esc_html( $title_label ); ?>
                    <span class="smacg-followers-count">(<?php echo esc_html( number_format_i18n( $total ) ); ?>)</span>
                </h1>

                <nav class="smacg-followers-tabs">
                    <a class="smacg-followers-tab <?php echo $section === 'followers' ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url( wxacg_followers_get_page_url( $user, 'followers' ) ); ?>">
                        👥 粉絲
                    </a>
                    <a class="smacg-followers-tab <?php echo $section === 'following' ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url( wxacg_followers_get_page_url( $user, 'following' ) ); ?>">
                        ➡️ 追蹤中
                    </a>
                </nav>
            </header>

            <?php if ( empty( $user_ids ) ) : ?>
                <section class="smacg-followers-empty">
                    <div class="empty-icon">🌱</div>
                    <p>
                        <?php if ( $section === 'followers' ) : ?>
                            還沒有人追蹤 <?php echo esc_html( $user->display_name ); ?>
                        <?php else : ?>
                            <?php echo esc_html( $user->display_name ); ?> 尚未追蹤任何人
                        <?php endif; ?>
                    </p>
                </section>
            <?php else : ?>
                <section class="smacg-followers-grid pp-followers-grid"
                         data-section="<?php echo esc_attr( $section ); ?>"
                         data-user-id="<?php echo esc_attr( $uid ); ?>"
                         data-page="<?php echo esc_attr( $paged ); ?>"
                         data-total-pages="<?php echo esc_attr( $total_pages ); ?>">
                    <?php foreach ( $user_ids as $other_uid ) {
                        wxacg_followers_render_card( (int) $other_uid );
                    } ?>
                </section>

                <?php if ( $paged < $total_pages ) : ?>
                    <div class="smacg-followers-loadmore-wrap">
                        <button class="smacg-followers-loadmore pp-btn pp-btn-ghost"
                                data-section="<?php echo esc_attr( $section ); ?>"
                                data-user-id="<?php echo esc_attr( $uid ); ?>"
                                data-next-page="<?php echo esc_attr( $paged + 1 ); ?>"
                                data-total-pages="<?php echo esc_attr( $total_pages ); ?>"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'wxacg_followers_load' ) ); ?>">
                            載入更多
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </main>

    <?php wxacg_followers_render_inline_assets(); ?>
    <?php
    get_footer();
}
endif;

/* ========================================================================
 * 取得 followers / following ID 列表（含總數）
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_get_user_list' ) ) :
function wxacg_followers_get_user_list( $uid, $section, $limit, $offset ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wxacg_follows';

    if ( $section === 'followers' ) {
        $sql_count = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE following_id = %d",
            $uid
        );
        $sql_list = $wpdb->prepare(
            "SELECT follower_id FROM {$table} WHERE following_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $uid, $limit, $offset
        );
    } else {
        $sql_count = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE follower_id = %d",
            $uid
        );
        $sql_list = $wpdb->prepare(
            "SELECT following_id FROM {$table} WHERE follower_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $uid, $limit, $offset
        );
    }

    $total = (int) $wpdb->get_var( $sql_count );
    $ids   = array_map( 'intval', (array) $wpdb->get_col( $sql_list ) );

    return [ 'ids' => $ids, 'total' => $total ];
}
endif;

/* ========================================================================
 * 單張使用者卡片
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_render_card' ) ) :
function wxacg_followers_render_card( $other_uid ) {
    $other_uid = (int) $other_uid;
    if ( $other_uid <= 0 ) return;

    $other = get_user_by( 'id', $other_uid );
    if ( ! $other ) return;

    $profile_url = function_exists( 'wxacg_get_public_profile_url' )
        ? wxacg_get_public_profile_url( $other )
        : home_url( '/u/' . $other->user_login . '/' );

    $avatar_url  = get_avatar_url( $other_uid, [ 'size' => 96 ] );
    $name        = $other->display_name;

    $lvl_label = '';
    if ( function_exists( 'wxacg_get_user_level_info' ) ) {
        $lvl = wxacg_get_user_level_info( $other_uid );
        if ( is_array( $lvl ) && ! empty( $lvl['level'] ) ) {
            $icon  = $lvl['icon']  ?? '';
            $title = $lvl['title'] ?? '';
            $lvl_label = trim( $icon . ' Lv.' . (int) $lvl['level'] . ( $title ? ' ' . $title : '' ) );
        }
    }

    $is_following = false;
    $show_follow_btn = false;
    if ( is_user_logged_in() ) {
        $viewer_id = get_current_user_id();
        if ( $viewer_id !== $other_uid ) {
            $show_follow_btn = true;
            if ( function_exists( 'wxacg_is_following' ) ) {
                $is_following = wxacg_is_following( $viewer_id, $other_uid );
            }
        }
    }

    $is_mutual = false;
    if ( is_user_logged_in() && get_current_user_id() !== $other_uid ) {
        $is_mutual = smacg_is_mutual_follow( get_current_user_id(), $other_uid );
    }
    ?>
    <article class="smacg-follower-card" data-user-id="<?php echo esc_attr( $other_uid ); ?>">
        <a class="smacg-follower-avatar" href="<?php echo esc_url( $profile_url ); ?>">
            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" decoding="async">
        </a>
        <div class="smacg-follower-body">
            <a class="smacg-follower-name" href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $name ); ?></a>
            <?php if ( $lvl_label ) : ?>
                <div class="smacg-follower-level"><?php echo esc_html( $lvl_label ); ?></div>
            <?php endif; ?>
            <div class="smacg-follower-actions">
                <?php if ( $show_follow_btn ) : ?>
                    <button class="smacg-follow-btn smacg-follow-btn--sm <?php echo $is_following ? 'is-following' : ''; ?>"
                            data-user-id="<?php echo esc_attr( $other_uid ); ?>"
                            data-following="<?php echo $is_following ? '1' : '0'; ?>">
                        <?php echo $is_following ? '✓ 追蹤中' : '+ 追蹤'; ?>
                    </button>
                <?php endif; ?>
                <?php if ( $is_mutual ) : ?>
                    <span class="pp-mutual-badge pp-mutual-badge--sm" title="你們互相追蹤對方">🤝 互相追蹤</span>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}
endif;

/* ========================================================================
 * 取得 followers/following 頁面 URL
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_get_page_url' ) ) :
function wxacg_followers_get_page_url( $user, $section ) {
    $section = ( $section === 'following' ) ? 'following' : 'followers';
    $base    = function_exists( 'wxacg_get_public_profile_url' )
        ? wxacg_get_public_profile_url( $user )
        : home_url( '/u/' . $user->user_login . '/' );
    return trailingslashit( $base ) . $section . '/';
}
endif;

/* ========================================================================
 * AJAX：載入更多
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_ajax_load' ) ) :
function wxacg_followers_ajax_load() {
    check_ajax_referer( 'wxacg_followers_load', 'nonce' );

    $uid      = isset( $_POST['user_id'] )  ? (int) $_POST['user_id']    : 0;
    $section  = isset( $_POST['section'] )  ? sanitize_key( $_POST['section'] ) : '';
    $page     = isset( $_POST['page'] )     ? max( 1, (int) $_POST['page'] ) : 1;

    if ( $uid <= 0 || ! in_array( $section, [ 'followers', 'following' ], true ) ) {
        wp_send_json_error( [ 'message' => 'invalid_params' ] );
    }

    $user = get_user_by( 'id', $uid );
    if ( ! $user ) {
        wp_send_json_error( [ 'message' => 'user_not_found' ] );
    }

    $privacy = function_exists( 'smacg_get_user_privacy' )
        ? smacg_get_user_privacy( $uid )
        : [ 'public_profile' => '1' ];
    if ( empty( $privacy['public_profile'] ) && get_current_user_id() !== $uid ) {
        wp_send_json_error( [ 'message' => 'private_profile' ] );
    }

    $per_page    = WXACG_FOLLOWERS_PAGE_SIZE;
    $offset      = ( $page - 1 ) * $per_page;
    $list_data   = wxacg_followers_get_user_list( $uid, $section, $per_page, $offset );
    $total_pages = max( 1, (int) ceil( $list_data['total'] / $per_page ) );

    ob_start();
    foreach ( $list_data['ids'] as $other_uid ) {
        wxacg_followers_render_card( (int) $other_uid );
    }
    $html = ob_get_clean();

    wp_send_json_success( [
        'html'        => $html,
        'page'        => $page,
        'total_pages' => $total_pages,
        'has_more'    => ( $page < $total_pages ),
    ] );
}
add_action( 'wp_ajax_wxacg_followers_load',        'wxacg_followers_ajax_load' );
add_action( 'wp_ajax_nopriv_wxacg_followers_load', 'wxacg_followers_ajax_load' );
endif;

/* ========================================================================
 * 一次性 inline CSS + JS（僅在 followers/following 頁面輸出）
 * ====================================================================== */

if ( ! function_exists( 'wxacg_followers_render_inline_assets' ) ) :
function wxacg_followers_render_inline_assets() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
    <style id="smacg-followers-inline">
        .smacg-followers-page{padding:32px 0 64px}
        .smacg-followers-page .container{max-width:1080px;margin:0 auto;padding:0 16px}
        .smacg-followers-header{margin-bottom:24px}
        .smacg-followers-back{display:inline-block;margin-bottom:12px;color:var(--theme-palette-color-1,#4a6cf7);text-decoration:none;font-size:14px}
        .smacg-followers-back:hover{text-decoration:underline}
        .smacg-followers-title{margin:0 0 16px;font-size:24px;font-weight:700;color:#fff}
        .smacg-followers-title .smacg-followers-count{color:rgba(255,255,255,.7);font-weight:500;font-size:18px;margin-left:6px}
        .smacg-followers-tabs{display:flex;gap:8px;border-bottom:1px solid #e5e7eb;margin-bottom:8px}
        .smacg-followers-tab{padding:10px 16px;text-decoration:none;color:#555;border-bottom:2px solid transparent;font-weight:500;transition:all .15s ease}
        .smacg-followers-tab:hover{color:var(--theme-palette-color-1,#4a6cf7)}
        .smacg-followers-tab.is-active{color:var(--theme-palette-color-1,#4a6cf7);border-bottom-color:var(--theme-palette-color-1,#4a6cf7)}
        .smacg-followers-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-top:24px}
        .smacg-follower-card{display:flex;gap:12px;padding:12px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;transition:box-shadow .15s ease,transform .15s ease}
        .smacg-follower-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.06);transform:translateY(-2px)}
        .smacg-follower-avatar{flex:0 0 auto;width:56px;height:56px;border-radius:50%;overflow:hidden;display:block}
        .smacg-follower-avatar img{width:100%;height:100%;object-fit:cover;display:block}
        .smacg-follower-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
        .smacg-follower-name{font-weight:600;color:#222;text-decoration:none;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .smacg-follower-name:hover{color:var(--theme-palette-color-1,#4a6cf7)}
        .smacg-follower-level{font-size:12px;color:#888}
        .smacg-follower-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;align-items:center}
        .smacg-follow-btn--sm{padding:5px 12px;font-size:12px;border-radius:999px}
        .pp-mutual-badge--sm{font-size:11px;padding:4px 8px}
        .smacg-followers-empty{text-align:center;padding:64px 16px;color:#666}
        .smacg-followers-empty .empty-icon{font-size:48px;margin-bottom:12px}
        .smacg-followers-loadmore-wrap{text-align:center;margin-top:32px}
        .smacg-followers-loadmore{padding:10px 28px;cursor:pointer}
        .smacg-followers-loadmore[disabled]{opacity:.6;cursor:wait}
        .pp-private{text-align:center;padding:80px 16px}
        .pp-private-icon{font-size:64px;margin-bottom:16px}
        @media (max-width:640px){
            .smacg-followers-grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
            .smacg-follower-card{padding:10px;gap:10px}
            .smacg-follower-avatar{width:48px;height:48px}
            .smacg-followers-title{font-size:20px}
        }
    </style>
    <script id="smacg-followers-inline-js">
    (function(){
        'use strict';
        var btn = document.querySelector('.smacg-followers-loadmore');
        if (!btn) return;
        var grid = document.querySelector('.smacg-followers-grid');
        if (!grid) return;

        btn.addEventListener('click', function(){
            if (btn.disabled) return;
            btn.disabled = true;
            var original = btn.textContent;
            btn.textContent = '載入中…';

            var page    = parseInt(btn.dataset.nextPage, 10) || 2;
            var section = btn.dataset.section;
            var userId  = btn.dataset.userId;
            var nonce   = btn.dataset.nonce;
            var totalPages = parseInt(btn.dataset.totalPages, 10) || 1;

            var fd = new FormData();
            fd.append('action', 'wxacg_followers_load');
            fd.append('nonce', nonce);
            fd.append('user_id', userId);
            fd.append('section', section);
            fd.append('page', page);

            fetch('<?php echo esc_url_raw( $ajax_url ); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    btn.textContent = '載入失敗，請重試';
                    btn.disabled = false;
                    return;
                }
                var tmp = document.createElement('div');
                tmp.innerHTML = res.data.html;
                while (tmp.firstChild) grid.appendChild(tmp.firstChild);

                if (window.smacgFollowBindAll) {
                    window.smacgFollowBindAll();
                } else {
                    document.dispatchEvent(new CustomEvent('smacg:rebind-follow'));
                }

                var nextPage = page + 1;
                if (res.data.has_more && nextPage <= totalPages) {
                    btn.dataset.nextPage = nextPage;
                    btn.textContent = original;
                    btn.disabled = false;
                } else {
                    btn.parentNode.removeChild(btn);
                }
            })
            .catch(function(){
                btn.textContent = '載入失敗，請重試';
                btn.disabled = false;
            });
        });
    })();
    </script>
    <?php
}
endif;
