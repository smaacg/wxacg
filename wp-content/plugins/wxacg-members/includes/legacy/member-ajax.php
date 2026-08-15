<?php
/**
 * 會員相關 AJAX endpoints + 頭像上傳 + 頭像顯示 filters
 *
 * @package weixiaoacg
 * @subpackage Member
 *
 * Version: 2.6.0 (2026-06-01)
 *  - v2.6.0: 登入 handler 業界強化（weixiaoacg_ajax_login）
 *    - nonce 失效不再 die，改回明確「頁面已逾時」訊息（解決快取造成 nonce 過期被誤判成帳密錯誤）
 *    - 區分錯誤類型：未填帳號 / 未填密碼 / 帳密錯 / 帳號待審或停用
 *    - 登入失敗節流：同 IP 15 分鐘內連續失敗達 8 次即暫時鎖定（防暴力破解）
 *    - 成功清除失敗計數、寫入 smacg_last_login，redirect 可被 smacg_login_redirect filter 覆寫
 *    - 登入成功/失敗皆寫 error_log 供除錯
 *
 * Version: 2.5.0 (2026-06-01) 註冊流程改為「註冊即登入、Email 驗證非強制」
 * Version: 2.4.0 (2026-05-23) 註冊防護全套（Honeypot / IP 限制 / 密碼強度 / 黑名單 / 驗證信）
 * Version: 2.3.0 (2026-05-22) Gamification v2.7.0 整合（bio / avatar / birthday 事件）
 * Version: 2.2.0 (2026-05-13) Batch B 留言分頁 + 頭像上傳優化
 *
 * 註：此檔案未來會整檔搬到 plugin（anime-sync-pro/public/）。
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   已移除：評分 / 收藏 / 進度 / 閱讀加分的舊 admin-ajax 端點
   ------------------------------------------------------------
   以下端點在改走 REST 後就沒有任何呼叫端，屬於遷移殘留：
     - smacg_submit_rating_detail  → weixiaoacg/v1/ratings（class-rating-manager.php）
     - weixiaoacg_submit_rating    → 同上
     - weixiaoacg_toggle_favorite  → weixiaoacg/v1/user-status（class-user-status-manager.php）
     - weixiaoacg_update_progress  → 同上
     - weixiaoacg_resync_bangumi   → Anime_Sync_API_Handler 後台介面
     - smacg_get_my_rating         → weixiaoacg/v1/ratings/{postId}（見 anime-rating.js v1.5）
     - smacg_read_article          → 舊版 anime_total_points 加分，寫入後無任何前台讀取
                                      端；文章閱讀已改由 wxacg-gamification 的
                                      smacg_post_read（class-read-tracker.php）發 EXP

   舊版寫入的 smacg_site_score_* post meta 仍保留在資料庫中，
   由 wxacg_is_thin_anime_page() 當作歷史資料的 fallback 讀取
   （現行寫入者為 class-rating-manager.php 的 anime_score_site_count）。
   ============================================================ */

/* ============================================================
   AJAX：站內搜尋
   ============================================================ */
add_action( 'wp_ajax_weixiaoacg_search',        'weixiaoacg_ajax_search' );
add_action( 'wp_ajax_nopriv_weixiaoacg_search', 'weixiaoacg_ajax_search' );
function weixiaoacg_ajax_search() {
    check_ajax_referer( 'weixiaoacg_nonce', 'nonce' );
    $kw   = sanitize_text_field( $_POST['query'] ?? $_POST['keyword'] ?? '' );
    $type = sanitize_text_field( $_POST['type'] ?? 'all' );
    if ( strlen( $kw ) < 2 ) wp_send_json_error( [ 'msg' => '關鍵字太短' ] );

    $types = match ( $type ) {
        'anime'     => [ 'anime' ],
        'manga'     => [ 'manga' ],
        'character' => [ 'character' ],
        'va'        => [ 'voice-actor' ],
        'music'     => [ 'music' ],
        default     => [ 'anime', 'manga', 'novel', 'game', 'character', 'voice-actor', 'post' ],
    };

    $q   = new WP_Query( [ 's' => $kw, 'post_type' => $types, 'posts_per_page' => 12, 'post_status' => 'publish' ] );
    $res = [];
    while ( $q->have_posts() ) {
        $q->the_post();
        $pid   = get_the_ID();
        $res[] = [
            'id'       => $pid,
            'title'    => get_the_title(),
            'title_zh' => weixiaoacg_acf( 'weixiaoacg_title_zh', $pid, get_the_title() ),
            'type'     => get_post_type(),
            'url'      => get_permalink(),
            'thumb'    => get_the_post_thumbnail_url( $pid, 'weixiaoacg-thumb' ) ?: weixiaoacg_acf( 'weixiaoacg_cover_url', $pid ),
            'score'    => weixiaoacg_acf( 'weixiaoacg_score_anilist', $pid, 0 ),
        ];
    }
    wp_reset_postdata();
    wp_send_json_success( $res );
}

/* ============================================================
   AJAX：訪客 → AJAX 登入（v2.6.0 業界強化版）
   - nonce 失效給明確「頁面已逾時」訊息（不 die，避免被誤判成帳密錯誤）
   - 區分錯誤類型：未填 / 帳密錯 / 帳號待審或停用
   - 登入失敗節流：同 IP 15 分鐘內連續失敗達上限即暫時鎖定
   - 成功清除失敗計數、寫入 smacg_last_login，redirect 可被 filter 覆寫
   ============================================================ */
add_action( 'wp_ajax_nopriv_weixiaoacg_ajax_login', function () {

    // 1) nonce：失敗不 die，回明確逾時訊息（解決快取造成的 nonce 過期誤判）
    if ( ! check_ajax_referer( 'weixiaoacg_nonce', 'nonce', false ) ) {
        wp_send_json_error( [
            'msg'        => '頁面已逾時，請重新整理頁面後再登入',
            'nonce_fail' => true,
        ], 403 );
    }

    // 2) 登入失敗節流（防暴力破解）
    $ip       = function_exists( 'smacg_get_register_ip' ) ? smacg_get_register_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    $fail_key = 'smacg_login_fail_' . md5( $ip );
    $fail_max = 8;                          // 15 分鐘內最多 8 次失敗
    $fail_ttl = 15 * MINUTE_IN_SECONDS;
    $fail_cnt = (int) get_transient( $fail_key );
    if ( $fail_cnt >= $fail_max ) {
        wp_send_json_error( [
            'msg'    => '登入失敗次數過多，請於 15 分鐘後再試，或使用「忘記密碼」重設',
            'locked' => true,
        ], 429 );
    }

    // 3) 欄位檢查（區分未填帳號 / 未填密碼）
    $u = sanitize_user( $_POST['log'] ?? '' );
    $p = (string) ( $_POST['pwd'] ?? '' );
    if ( $u === '' ) wp_send_json_error( [ 'msg' => '請輸入使用者名稱或電子郵件' ] );
    if ( $p === '' ) wp_send_json_error( [ 'msg' => '請輸入密碼' ] );

    // 4) 驗證帳密
    $user = wp_signon( [
        'user_login'    => $u,
        'user_password' => $p,
        'remember'      => ! empty( $_POST['rememberme'] ),
    ], is_ssl() );

    if ( is_wp_error( $user ) ) {
        // 失敗計數 +1
        set_transient( $fail_key, $fail_cnt + 1, $fail_ttl );

        // 區分錯誤類型（不洩漏帳號是否存在，但對停用帳號給提示）
        $code = $user->get_error_code();
        $msg  = '帳號或密碼錯誤，請再試一次';
        if ( $code === 'empty_username' || $code === 'empty_password' ) {
            $msg = '請輸入帳號和密碼';
        } elseif ( strpos( (string) $code, 'spam' ) !== false || $code === 'pending_approval' ) {
            $msg = '此帳號尚待審核或已被停用，請聯繫管理員';
        }

        error_log( sprintf(
            '[SMACG Login] FAIL code=%s login=%s ip=%s attempt=%d',
            $code, $u, $ip, $fail_cnt + 1
        ) );

        wp_send_json_error( [ 'msg' => $msg ] );
    }

    // 5) 成功：清除失敗計數、記錄登入時間
    delete_transient( $fail_key );
    update_user_meta( $user->ID, 'smacg_last_login', time() );

    error_log( sprintf( '[SMACG Login] OK uid=%d login=%s ip=%s', $user->ID, $u, $ip ) );

    // redirect 可被 filter 覆寫（日後可改導向會員中心：wxacg_get_member_center_url()）
    wp_send_json_success( [
        'msg'      => '登入成功',
        'redirect' => apply_filters( 'smacg_login_redirect', home_url( '/' ), $user ),
    ] );
} );

/* ============================================================
   v2.4.0：註冊防護 — Helpers
   ============================================================ */

/**
 * 取得當前請求的 IP（含 Cloudflare、Hostinger 代理 header 偵測）
 * @return string
 */
function smacg_get_register_ip() {
    $candidates = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
    foreach ( $candidates as $k ) {
        if ( ! empty( $_SERVER[ $k ] ) ) {
            $ip = trim( explode( ',', $_SERVER[ $k ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * 寄發驗證信
 * @return bool
 */
function smacg_send_verify_email( $user_id, $email, $token ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) return false;

    $verify_url = add_query_arg(
        [ 'uid' => $user_id, 'token' => $token ],
        home_url( '/verify-email/' )
    );

    $username  = $user->user_login;
    $site_name = get_bloginfo( 'name' );
    $expire_hr = (int) ( SMACG_REGISTER_VERIFY_EXPIRE / HOUR_IN_SECONDS );

    $subject = sprintf( '【%s】請驗證您的電子郵件', $site_name );

    $message  = "您好 {$username}，\n\n";
    $message .= "歡迎加入{$site_name}！請點擊以下連結完成電子郵件驗證：\n\n";
    $message .= $verify_url . "\n\n";
    $message .= "此連結 {$expire_hr} 小時內有效。\n";
    $message .= "如非您本人註冊，請忽略此信。\n\n";
    $message .= "─────────\n";
    $message .= "{$site_name}\n";
    $message .= home_url( '/' ) . "\n";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        sprintf( 'From: %s <%s>', $site_name, get_option( 'admin_email' ) ),
    ];

    $sent = wp_mail( $email, $subject, $message, $headers );

    error_log( sprintf(
        '[SMACG Register] verify mail uid=%d email=%s sent=%s',
        $user_id, $email, $sent ? 'OK' : 'FAIL'
    ) );

    return (bool) $sent;
}

/* ============================================================
   v2.5.0：訪客 → AJAX 註冊（保留完整防護，註冊即登入）
   ============================================================ */
add_action( 'wp_ajax_nopriv_weixiaoacg_ajax_register', function () {
    check_ajax_referer( 'weixiaoacg_nonce', 'nonce' );

    /* ===== Step 1: Honeypot ===== */
    if ( ! empty( $_POST['reg_website'] ) ) {
        error_log( sprintf(
            '[SMACG Register] honeypot triggered ip=%s ua=%s',
            smacg_get_register_ip(),
            substr( $_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 200 )
        ) );
        wp_send_json_error( [ 'msg' => '註冊失敗，請重試' ] );
    }

    /* ===== Step 2: IP 限制 ===== */
    $ip = smacg_get_register_ip();

    // 2-1：冷卻
    $last_key = 'smacg_reg_ip_last_' . md5( $ip );
    $last_ts  = (int) get_transient( $last_key );
    if ( $last_ts ) {
        $diff = time() - $last_ts;
        if ( $diff < SMACG_REGISTER_COOLDOWN ) {
            $wait = SMACG_REGISTER_COOLDOWN - $diff;
            wp_send_json_error( [ 'msg' => "註冊太頻繁，請於 {$wait} 秒後再試" ] );
        }
    }

    // 2-2：同 IP 每日上限
    $count_key = 'smacg_reg_ip_count_' . md5( $ip );
    $count     = (int) get_transient( $count_key );
    if ( $count >= SMACG_REGISTER_IP_DAILY_LIMIT ) {
        error_log( sprintf( '[SMACG Register] daily limit hit ip=%s count=%d', $ip, $count ) );
        wp_send_json_error( [ 'msg' => '今日註冊次數已達上限，請明天再試' ] );
    }

    /* ===== Step 3: 欄位驗證 ===== */
    $username = sanitize_user( $_POST['user_login'] ?? '', true );
    $email    = sanitize_email( $_POST['user_email'] ?? '' );
    $password = (string) ( $_POST['user_password'] ?? '' );

    if ( ! $username ) wp_send_json_error( [ 'msg' => '請輸入使用者名稱' ] );
    if ( ! $email || ! is_email( $email ) ) wp_send_json_error( [ 'msg' => '請輸入有效的電子郵件' ] );
    if ( ! $password ) wp_send_json_error( [ 'msg' => '請輸入密碼' ] );

    $name_len = mb_strlen( $username );
    if ( $name_len < SMACG_REGISTER_USERNAME_MIN || $name_len > SMACG_REGISTER_USERNAME_MAX ) {
        wp_send_json_error( [ 'msg' => sprintf(
            '使用者名稱長度需介於 %d–%d 字元',
            SMACG_REGISTER_USERNAME_MIN, SMACG_REGISTER_USERNAME_MAX
        ) ] );
    }

    $blocked_names = [
        'admin', 'administrator', 'root', 'superuser', 'sysadmin', 'webmaster',
        'support', 'help', 'service', 'official',
        'test', 'demo', 'null', 'undefined', 'guest', 'anonymous',
        'weixiao', 'weixiaoacg', 'smacg', '微笑動漫', '微笑',
    ];
    if ( in_array( mb_strtolower( $username ), $blocked_names, true ) ) {
        wp_send_json_error( [ 'msg' => '此使用者名稱不可使用，請換一個' ] );
    }

    if ( ! preg_match( '/^[\w\x{4e00}-\x{9fff}\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{ac00}-\x{d7af}]+$/u', $username ) ) {
        wp_send_json_error( [ 'msg' => '使用者名稱僅可包含中英數字與底線' ] );
    }

    if ( strlen( $password ) < SMACG_REGISTER_PW_MIN_LEN ) {
        wp_send_json_error( [ 'msg' => sprintf( '密碼至少需 %d 個字元', SMACG_REGISTER_PW_MIN_LEN ) ] );
    }
    if ( ! preg_match( '/[A-Za-z]/', $password ) || ! preg_match( '/[0-9]/', $password ) ) {
        wp_send_json_error( [ 'msg' => '密碼必須包含英文字母和數字' ] );
    }

    $email_domain          = strtolower( substr( strrchr( $email, '@' ), 1 ) );
    $blocked_email_domains = [
        'mailinator.com', '10minutemail.com', '10minutemail.net',
        'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org',
        'tempmail.com', 'tempmail.net', 'temp-mail.org', 'temp-mail.io',
        'yopmail.com', 'throwaway.email', 'trashmail.com',
        'maildrop.cc', 'mintemail.com', 'sharklasers.com',
        'getnada.com', 'fakemail.net', 'mvrht.net',
    ];
    if ( in_array( $email_domain, $blocked_email_domains, true ) ) {
        wp_send_json_error( [ 'msg' => '不接受拋棄式信箱，請使用常用的電子郵件' ] );
    }

    if ( username_exists( $username ) ) wp_send_json_error( [ 'msg' => '此使用者名稱已被使用' ] );
    if ( email_exists( $email ) )       wp_send_json_error( [ 'msg' => '此電子郵件已被註冊' ] );

    /* ===== Step 4: 建立帳號 ===== */
    $user_id = wp_create_user( $username, $password, $email );
    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( [ 'msg' => $user_id->get_error_message() ] );
    }

    if ( function_exists( 'um_fetch_user' ) ) {
        update_user_meta( $user_id, 'account_status', 'approved' );
        ( new WP_User( $user_id ) )->set_role( 'subscriber' );
    }

    /* ===== Step 5: 寫入驗證 meta（驗證非強制，但仍記錄狀態供領獎勵）===== */
    $token = wp_generate_password( 32, false, false );
    update_user_meta( $user_id, 'smacg_email_verified',       0 );
    update_user_meta( $user_id, 'smacg_email_verify_token',   $token );
    update_user_meta( $user_id, 'smacg_email_verify_expires', time() + SMACG_REGISTER_VERIFY_EXPIRE );
    update_user_meta( $user_id, 'smacg_register_time',        time() );
    update_user_meta( $user_id, 'smacg_register_ip',          $ip );

    /* ===== Step 6: 更新 IP transient ===== */
    set_transient( $last_key,  time(),     DAY_IN_SECONDS );
    set_transient( $count_key, $count + 1, DAY_IN_SECONDS );

    /* ===== Step 7: 寄驗證信（背景照寄，不阻擋登入）===== */
    $sent = smacg_send_verify_email( $user_id, $email, $token );

    /* ===== Step 8: 自動登入（驗證非強制，使用者自行決定是否驗證）===== */
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );
    do_action( 'wp_login', $username, get_userdata( $user_id ) );
    update_user_meta( $user_id, 'smacg_last_login', time() );

    error_log( sprintf(
        '[SMACG Register] success+autologin uid=%d username=%s email=%s ip=%s mail_sent=%s',
        $user_id, $username, $email, $ip, $sent ? 'OK' : 'FAIL'
    ) );

    wp_send_json_success( [
        'msg'        => '註冊成功，已為您登入！可稍後至會員中心驗證 Email 領取獎勵',
        'email'      => $email,
        'verify'     => false,
        'mail_sent'  => $sent,
        'logged_in'  => true,
        'redirect'   => apply_filters( 'smacg_login_redirect', home_url( '/' ), get_userdata( $user_id ) ),
    ] );
} );

/* ============================================================
   v2.4.0：AJAX 驗證 Email（驗證連結著陸頁呼叫）
   GET /verify-email/?uid=xxx&token=xxx → page-verify-email.php → 此 AJAX
   ============================================================ */
add_action( 'wp_ajax_smacg_verify_email',        'smacg_ajax_verify_email' );
add_action( 'wp_ajax_nopriv_smacg_verify_email', 'smacg_ajax_verify_email' );
function smacg_ajax_verify_email() {
    $uid   = (int) ( $_REQUEST['uid'] ?? 0 );
    $token = sanitize_text_field( $_REQUEST['token'] ?? '' );

    if ( ! $uid || ! $token ) wp_send_json_error( [ 'msg' => '驗證連結不完整' ], 400 );

    $user = get_userdata( $uid );
    if ( ! $user ) wp_send_json_error( [ 'msg' => '使用者不存在或已被刪除' ], 404 );

    if ( (int) get_user_meta( $uid, 'smacg_email_verified', true ) === 1 ) {
        wp_send_json_success( [ 'msg' => '此信箱已通過驗證', 'already' => true ] );
    }

    $saved_token = (string) get_user_meta( $uid, 'smacg_email_verify_token', true );
    $expires     = (int) get_user_meta( $uid, 'smacg_email_verify_expires', true );

    if ( ! $saved_token || ! hash_equals( $saved_token, $token ) ) {
        wp_send_json_error( [ 'msg' => '驗證連結無效，請重新申請' ], 403 );
    }
    if ( $expires && time() > $expires ) {
        wp_send_json_error( [ 'msg' => '驗證連結已過期，請重新申請', 'expired' => true ], 403 );
    }

    update_user_meta( $uid, 'smacg_email_verified', 1 );
    delete_user_meta( $uid, 'smacg_email_verify_token' );
    delete_user_meta( $uid, 'smacg_email_verify_expires' );

    error_log( sprintf( '[SMACG Register] verified uid=%d', $uid ) );
    do_action( 'smacg_email_verified', $uid );

    wp_send_json_success( [ 'msg' => '驗證成功！歡迎加入微笑動漫 🎉', 'username' => $user->user_login ] );
}

/* ============================================================
   v2.4.0：AJAX 重寄驗證信（橫條按鈕呼叫）
   ============================================================ */
add_action( 'wp_ajax_smacg_resend_verify_email', function () {
    check_ajax_referer( 'smacg_resend_verify', 'nonce' );

    $uid = get_current_user_id();
    if ( ! $uid ) wp_send_json_error( [ 'msg' => '請先登入' ], 401 );

    if ( (int) get_user_meta( $uid, 'smacg_email_verified', true ) === 1 ) {
        wp_send_json_error( [ 'msg' => '此信箱已通過驗證，無需重寄' ] );
    }

    $cooldown_key = 'smacg_resend_verify_' . $uid;
    if ( get_transient( $cooldown_key ) ) {
        wp_send_json_error( [ 'msg' => '請稍候 5 分鐘後再試' ] );
    }

    $user = get_userdata( $uid );
    if ( ! $user || ! $user->user_email ) wp_send_json_error( [ 'msg' => '找不到您的信箱' ] );

    $token = wp_generate_password( 32, false, false );
    update_user_meta( $uid, 'smacg_email_verify_token',   $token );
    update_user_meta( $uid, 'smacg_email_verify_expires', time() + SMACG_REGISTER_VERIFY_EXPIRE );

    $sent = smacg_send_verify_email( $uid, $user->user_email, $token );
    set_transient( $cooldown_key, 1, 5 * MINUTE_IN_SECONDS );

    $sent
        ? wp_send_json_success( [ 'msg' => '已重寄至 ' . $user->user_email ] )
        : wp_send_json_error( [ 'msg' => '寄送失敗，請稍後再試或聯繫管理員' ] );
} );

/* ============================================================
   v2.5.0：Cron — 每日 04:00 清除「未驗證且從未登入過」的殭屍帳號
   （驗證非強制後，僅刪從未登入的空帳號，保護活躍用戶）
   ============================================================ */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'smacg_cleanup_unverified_users' ) ) {
        $tomorrow_4am = strtotime( 'tomorrow 04:00:00', current_time( 'timestamp' ) );
        wp_schedule_event( $tomorrow_4am, 'daily', 'smacg_cleanup_unverified_users' );
    }
} );

add_action( 'smacg_cleanup_unverified_users', function () {
    $cutoff = time() - SMACG_REGISTER_UNVERIFIED_TTL;

    $users = get_users( [
        'meta_query' => [
            'relation' => 'AND',
            [
                'key'     => 'smacg_email_verified',
                'value'   => 1,
                'compare' => '!=',
            ],
            [
                'key'     => 'smacg_register_time',
                'value'   => $cutoff,
                'compare' => '<',
                'type'    => 'NUMERIC',
            ],
            // v2.5.0：僅刪「從未登入過」的殭屍帳號，保護活躍用戶
            [
                'key'     => 'smacg_last_login',
                'compare' => 'NOT EXISTS',
            ],
        ],
        'fields' => [ 'ID', 'user_login', 'user_email' ],
        'number' => 100,
    ] );

    if ( empty( $users ) ) {
        error_log( '[SMACG Register] cleanup: no zombie users to delete' );
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    $deleted = 0;
    foreach ( $users as $u ) {
        $wp_user = new WP_User( $u->ID );
        if ( array_intersect( [ 'administrator', 'editor' ], $wp_user->roles ) ) continue;

        if ( wp_delete_user( $u->ID ) ) {
            $deleted++;
            error_log( sprintf(
                '[SMACG Register] cleanup deleted uid=%d login=%s email=%s',
                $u->ID, $u->user_login, $u->user_email
            ) );
        }
    }

    error_log( sprintf( '[SMACG Register] cleanup: deleted %d zombie users', $deleted ) );
} );

// 外掛停用時清掉 cron
register_deactivation_hook( __FILE__, function () {
    $ts = wp_next_scheduled( 'smacg_cleanup_unverified_users' );
    if ( $ts ) wp_unschedule_event( $ts, 'smacg_cleanup_unverified_users' );
} );

/* ============================================================
   會員中心：member.js / member.css enqueue
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! smacg_is_member_page() ) return;

    $js_path  = get_stylesheet_directory() . '/assets/js/member.js';
    $css_path = get_stylesheet_directory() . '/assets/css/member.css';

    if ( file_exists( $js_path ) ) {
        wp_enqueue_script(
            'smacg-member',
            get_stylesheet_directory_uri() . '/assets/js/member.js',
            [ 'jquery' ],
            filemtime( $js_path ),
            true
        );
        wp_localize_script( 'smacg-member', 'smacgMember', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'smacg_member_nonce' ),
        ] );
        wp_localize_script( 'smacg-member', 'wpApiSettings', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    if ( file_exists( $css_path ) ) {
        wp_enqueue_style(
            'smacg-member-css',
            get_stylesheet_directory_uri() . '/assets/css/member.css',
            [],
            filemtime( $css_path )
        );
    }
}, 20 );

/* ============================================================
   會員中心：載入更多清單／評分
   ============================================================ */
add_action( 'wp_ajax_smacg_member_loadmore', function () {
    check_ajax_referer( 'smacg_member_nonce', 'nonce' );

    $uid = get_current_user_id();
    if ( ! $uid ) wp_send_json_error( [ 'msg' => '請先登入' ], 401 );

    $type   = sanitize_key( $_POST['type'] ?? '' );
    $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
    $limit  = min( 50, max( 1, (int) ( $_POST['limit'] ?? 20 ) ) );
    $filter = sanitize_key( $_POST['filter'] ?? 'all' );
    $sort   = sanitize_key( $_POST['sort'] ?? 'updated' );
    $search = sanitize_text_field( $_POST['search'] ?? '' );

    if ( $type === 'watchlist' ) {
        $items = smacg_build_watchlist( $uid );

        if ( $filter === 'favorited' ) {
            $items = array_filter( $items, fn( $w ) => ! empty( $w['favorited'] ) );
        } elseif ( $filter !== 'all' && $filter !== '' ) {
            $items = array_filter( $items, fn( $w ) => ( $w['status'] ?? '' ) === $filter );
        }

        if ( $search !== '' ) {
            $q     = mb_strtolower( $search );
            $items = array_filter( $items, fn( $w ) => str_contains( mb_strtolower( get_the_title( $w['post_id'] ) ), $q ) );
        }
        $items = smacg_sort_watchlist( array_values( $items ), $sort );

    } elseif ( $type === 'ratings' ) {
        $items = smacg_get_user_ratings( $uid );
        if ( $search !== '' ) {
            $q     = mb_strtolower( $search );
            $items = array_filter( $items, fn( $r ) => str_contains( mb_strtolower( get_the_title( $r['anime_id'] ) ), $q ) );
        }
        usort( $items, fn( $a, $b ) => match ( $sort ) {
            'score-desc' => (float) $b['overall_score'] <=> (float) $a['overall_score'],
            'score-asc'  => (float) $a['overall_score'] <=> (float) $b['overall_score'],
            default      => strcmp( $b['updated_at'] ?? '', $a['updated_at'] ?? '' ),
        } );
    } else {
        wp_send_json_error( [ 'msg' => '參數錯誤' ], 400 );
    }

    $items = array_values( $items );
    $slice = array_slice( $items, $offset, $limit );

    ob_start();
    foreach ( $slice as $row ) {
        if ( $type === 'watchlist' ) {
            smacg_render_anime_card( $row['post_id'], $row );
        } else {
            smacg_render_anime_card( (int) $row['anime_id'], [ 'user_score' => (float) $row['overall_score'] ] );
        }
    }
    wp_send_json_success( [
        'html'     => ob_get_clean(),
        'loaded'   => $offset + count( $slice ),
        'total'    => count( $items ),
        'has_more' => ( $offset + count( $slice ) ) < count( $items ),
    ] );
} );

/* ============================================================
   會員中心：更新基本資料 AJAX
   ============================================================ */
add_action( 'wp_ajax_smacg_update_profile', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '請先登入' ] );
    check_ajax_referer( 'smacg_update_profile', 'nonce' );

    $uid     = get_current_user_id();
    $display = sanitize_text_field( $_POST['display_name'] ?? '' );
    $bio     = sanitize_textarea_field( $_POST['description'] ?? '' );

    if ( $display === '' ) wp_send_json_error( [ 'msg' => '顯示名稱不可為空' ] );
    if ( mb_strlen( $display ) > 40 || mb_strlen( $bio ) > 300 ) {
        wp_send_json_error( [ 'msg' => '欄位長度超出限制' ] );
    }

    $old_bio = (string) get_user_meta( $uid, 'description', true );

    $r = wp_update_user( [
        'ID'           => $uid,
        'display_name' => $display,
        'nickname'     => $display,
        'description'  => $bio,
    ] );

    if ( is_wp_error( $r ) ) wp_send_json_error( [ 'msg' => $r->get_error_message() ] );
    if ( function_exists( 'UM' ) ) UM()->user()->remove_cache( $uid );

    if ( trim( $bio ) !== trim( $old_bio ) ) {
        do_action( 'smacg_bio_updated', $uid, $bio );
    }

    wp_send_json_success( [ 'msg' => '已儲存 ✓' ] );
} );

/* ============================================================
   P0-1: 隱私設定 AJAX handler
   ============================================================ */
add_action( 'wp_ajax_smacg_update_privacy', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '請先登入' ] );
    check_ajax_referer( 'smacg_privacy', 'nonce' );
    $uid = get_current_user_id();

    $allowed = [ 'show_email', 'public_profile', 'public_watchlist', 'show_continue_watching' ];
    $current = function_exists( 'smacg_get_user_privacy' ) ? smacg_get_user_privacy( $uid ) : [];

    $key = sanitize_key( $_POST['key'] ?? '' );
    if ( ! in_array( $key, $allowed, true ) ) wp_send_json_error( [ 'msg' => '無效參數' ] );

    $current[ $key ] = ! empty( $_POST['value'] ) ? 1 : 0;
    update_user_meta( $uid, 'smacg_privacy', $current );
    wp_send_json_success( [ 'msg' => '已儲存 ✓', 'privacy' => $current ] );
} );

/* ============================================================
   Batch B (v2.1.0)：留言分頁載入
   ============================================================ */
add_action( 'wp_ajax_smacg_load_more_comments', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '請先登入' ], 401 );
    check_ajax_referer( 'smacg_load_more_comments', 'nonce' );

    $uid    = get_current_user_id();
    $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

    if ( ! defined( 'SMACG_COMMENT_PAGE_SIZE' ) ) define( 'SMACG_COMMENT_PAGE_SIZE', 20 );
    $limit = SMACG_COMMENT_PAGE_SIZE;

    $total = (int) get_comments( [ 'user_id' => $uid, 'status' => 'approve', 'count' => true ] );

    $cmts = get_comments( [
        'user_id' => $uid,
        'status'  => 'approve',
        'number'  => $limit,
        'offset'  => $offset,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
    ] );

    ob_start();
    foreach ( $cmts as $c ) {
        printf(
            '<li><a href="%s"><b>%s</b><p>%s</p><small>%s</small></a></li>',
            esc_url( get_comment_link( $c ) ),
            esc_html( get_the_title( $c->comment_post_ID ) ),
            esc_html( wp_trim_words( $c->comment_content, 40 ) ),
            esc_html( mysql2date( 'Y-m-d H:i', $c->comment_date ) )
        );
    }
    $html = ob_get_clean();

    $loaded = $offset + count( $cmts );
    wp_send_json_success( [
        'html'     => $html,
        'loaded'   => $loaded,
        'total'    => $total,
        'has_more' => $loaded < $total,
    ] );
} );

/* ============================================================
   頭像上傳 AJAX（業界標準版 v2.2.0）
   ============================================================ */
add_action( 'wp_ajax_smacg_upload_avatar', function () {
    check_ajax_referer( 'smacg_member_nonce', 'nonce' );

    $uid = get_current_user_id();
    if ( ! $uid ) wp_send_json_error( [ 'msg' => '請先登入' ], 401 );

    /* ===== 防濫用 ===== */
    $last = (int) get_user_meta( $uid, 'smacg_avatar_last_upload', true );
    if ( $last && ( time() - $last ) < 120 ) {
        $wait = 120 - ( time() - $last );
        wp_send_json_error( [ 'msg' => "更換太頻繁，請於 {$wait} 秒後再試" ], 429 );
    }

    $today_key   = 'smacg_avatar_count_' . date( 'Ymd' );
    $today_count = (int) get_user_meta( $uid, $today_key, true );
    if ( $today_count >= 5 ) {
        wp_send_json_error( [ 'msg' => '今日更換次數已達上限（5 次），請明天再試' ], 429 );
    }

    /* ===== 檔案驗證 ===== */
    if ( empty( $_FILES['avatar'] ) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'msg' => '上傳失敗，請重試' ], 400 );
    }
    $file = $_FILES['avatar'];

    if ( $file['size'] > 5 * 1024 * 1024 ) {
        wp_send_json_error( [ 'msg' => '檔案過大（上限 5 MB）' ], 400 );
    }

    $allowed = [ 'image/jpeg', 'image/png', 'image/webp' ];

    // wp_check_filetype() 副檔名不認識時回傳的是 type => false（鍵存在），
    // 所以 ?? 永遠不會觸發後面的 fallback。改用明確判斷，
    // 否則像 .jfif 這種合法 JPEG 會因副檔名不在 WP 白名單而被誤擋。
    $mime = wp_check_filetype( $file['name'] )['type'];

    if ( ! $mime ) {
        $mime = @mime_content_type( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    if ( ! in_array( $mime, $allowed, true ) ) {
        wp_send_json_error( [ 'msg' => '僅支援 JPG / PNG / WEBP' ], 400 );
    }

    $info          = @getimagesize( $file['tmp_name'] );
    $allowed_types = [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ];
    if ( ! $info || ! in_array( $info[2], $allowed_types, true ) ) {
        wp_send_json_error( [ 'msg' => '檔案不是有效的圖片' ], 400 );
    }

    /* ===== 上傳到媒體庫 ===== */
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $rename = function ( $f ) use ( $uid ) {
        $ext       = strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) );
        $f['name'] = 'avatar_' . substr( md5( $uid . microtime( true ) ), 0, 16 ) . '.' . $ext;
        return $f;
    };
    add_filter( 'wp_handle_upload_prefilter', $rename );
    $attach_id = media_handle_upload( 'avatar', 0 );
    remove_filter( 'wp_handle_upload_prefilter', $rename );

    if ( is_wp_error( $attach_id ) ) {
        wp_send_json_error( [ 'msg' => $attach_id->get_error_message() ], 500 );
    }

    /* ===== 強制 resize 400x400 + 85% 品質 ===== */
    $path   = get_attached_file( $attach_id );
    $editor = wp_get_image_editor( $path );
    if ( ! is_wp_error( $editor ) ) {
        $editor->resize( 400, 400, true );
        $editor->set_quality( 85 );
        $editor->save( $path );
        wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $path ) );
    }

    /* ===== 刪除舊頭像 ===== */
    $old = (int) get_user_meta( $uid, 'smacg_avatar_id', true );
    if ( $old && $old !== $attach_id ) {
        wp_delete_attachment( $old, true );
    }

    /* ===== 寫入 meta + 計次 ===== */
    update_user_meta( $uid, 'smacg_avatar_id', $attach_id );
    update_user_meta( $uid, 'smacg_avatar_last_upload', time() );
    update_user_meta( $uid, $today_key, $today_count + 1 );

    $url = wp_get_attachment_url( $attach_id );

    if ( function_exists( 'um_fetch_user' ) ) {
        update_user_meta( $uid, 'profile_photo', basename( $url ) );
        update_user_meta( $uid, 'synced_profile_photo', $url );
    }

    error_log( sprintf(
        '[SMACG Avatar] uid=%d attach_id=%d size=%d mime=%s today_count=%d ip=%s',
        $uid, $attach_id, (int) $file['size'], $mime, $today_count + 1, $_SERVER['REMOTE_ADDR'] ?? '-'
    ) );

    do_action( 'smacg_avatar_uploaded', $uid );

    wp_send_json_success( [ 'url' => $url, 'msg' => '頭像已更新' ] );
} );

/* ============================================================
   v2.3.0：生日設定 AJAX
   ============================================================ */
add_action( 'wp_ajax_smacg_update_birthday', function () {
    if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '請先登入' ], 401 );
    check_ajax_referer( 'smacg_update_birthday', 'nonce' );

    $uid      = get_current_user_id();
    $birthday = sanitize_text_field( $_POST['birthday'] ?? '' );

    if ( ! preg_match( '/^(\d{4}-)?\d{2}-\d{2}$/', $birthday ) ) {
        wp_send_json_error( [ 'msg' => '生日格式錯誤，請使用 YYYY-MM-DD 或 MM-DD' ] );
    }

    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthday ) ) {
        $parts = explode( '-', $birthday );
        if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
            wp_send_json_error( [ 'msg' => '無效的日期' ] );
        }
    } else {
        $parts = explode( '-', $birthday );
        if ( ! checkdate( (int) $parts[0], (int) $parts[1], 2024 ) ) {
            wp_send_json_error( [ 'msg' => '無效的日期' ] );
        }
    }

    $old = (string) get_user_meta( $uid, 'smacg_birthday', true );
    update_user_meta( $uid, 'smacg_birthday', $birthday );

    if ( $old !== $birthday ) {
        do_action( 'smacg_birthday_updated', $uid, $birthday );
    }

    wp_send_json_success( [ 'msg' => '已儲存 ✓', 'birthday' => $birthday ] );
} );

/* ============================================================
   頭像顯示 Filters：覆蓋 WP / UM 預設頭像
   ============================================================ */

/** 由 id_or_email 解析出 user_id（共用） */
function smacg_resolve_avatar_uid( $id_or_email ) {
    if ( is_numeric( $id_or_email ) ) {
        return (int) $id_or_email;
    } elseif ( $id_or_email instanceof WP_User ) {
        return (int) $id_or_email->ID;
    } elseif ( $id_or_email instanceof WP_Comment ) {
        return (int) $id_or_email->user_id;
    } elseif ( is_object( $id_or_email ) ) {
        return (int) ( $id_or_email->user_id ?? $id_or_email->ID ?? 0 );
    } elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
        $u = get_user_by( 'email', $id_or_email );
        return $u ? (int) $u->ID : 0;
    }
    return 0;
}

/* 讓 get_avatar_url 全站優先讀 smacg_avatar_id（含 cache-bust） */
add_filter( 'get_avatar_url', function ( $url, $id_or_email, $args ) {
    $uid = smacg_resolve_avatar_uid( $id_or_email );
    if ( ! $uid ) return $url;

    $aid = (int) get_user_meta( $uid, 'smacg_avatar_id', true );
    if ( ! $aid || ! wp_attachment_is_image( $aid ) ) return $url;

    $size = isset( $args['size'] ) ? (int) $args['size'] : 96;
    $img  = wp_get_attachment_image_src( $aid, [ $size, $size ] );
    if ( ! $img ) return $url;

    return add_query_arg( 'v', get_post_modified_time( 'U', false, $aid ), $img[0] );
}, 9999, 3 );

/* 攔截整個 get_avatar HTML 輸出 */
add_filter( 'get_avatar', function ( $html, $id_or_email, $size, $default, $alt, $args ) {
    $uid = smacg_resolve_avatar_uid( $id_or_email );
    if ( ! $uid ) return $html;

    $aid = (int) get_user_meta( $uid, 'smacg_avatar_id', true );
    if ( ! $aid || ! wp_attachment_is_image( $aid ) ) return $html;

    $img = wp_get_attachment_image_src( $aid, [ $size, $size ] );
    if ( ! $img ) return $html;

    $src  = $img[0] . '?v=' . get_post_modified_time( 'U', false, $aid );
    $size = (int) $size;

    return sprintf(
        '<img src="%s" class="avatar avatar-%d photo" width="%d" height="%d" alt="%s" loading="lazy" />',
        esc_url( $src ), $size, $size, $size, esc_attr( $alt ?: '' )
    );
}, 9999, 6 );

/* ★ 覆蓋 UM 的頭像 URL */
add_filter( 'um_user_avatar_url_filter', function ( $avatar_uri, $user_id ) {
    $user_id = (int) $user_id;
    if ( ! $user_id ) return $avatar_uri;

    $aid = (int) get_user_meta( $user_id, 'smacg_avatar_id', true );
    if ( $aid && wp_attachment_is_image( $aid ) ) {
        $img = wp_get_attachment_image_src( $aid, 'medium' );
        if ( $img ) return $img[0] . '?v=' . get_post_modified_time( 'U', false, $aid );
    }
    return $avatar_uri;
}, 9999, 2 );

/* 移除 UM 對 <img> 加的 um-avatar-default class */
add_filter( 'get_avatar', function ( $html, $id_or_email ) {
    $uid = smacg_resolve_avatar_uid( $id_or_email );
    if ( ! $uid ) return $html;
    if ( ! get_user_meta( $uid, 'smacg_avatar_id', true ) ) return $html;

    $html = preg_replace( '/\sum-avatar-default/', '', $html );
    $html = preg_replace( '/\sonerror="[^"]*"/', '', $html );
    $html = preg_replace( '/\sdata-default="[^"]*"/', '', $html );
    return $html;
}, 99999, 2 );

/* ============================================================
   v2.5.0：巴哈式即時查重（帳號 / Email 是否可用）
   前端 header.js debounce 後呼叫，沿用 weixiaoacg_nonce
   ============================================================ */
add_action( 'wp_ajax_nopriv_weixiaoacg_check_field', 'weixiaoacg_ajax_check_field' );
add_action( 'wp_ajax_weixiaoacg_check_field',        'weixiaoacg_ajax_check_field' );
function weixiaoacg_ajax_check_field() {
    check_ajax_referer( 'weixiaoacg_nonce', 'nonce' );

    $field = sanitize_key( $_POST['field'] ?? '' );
    $value = trim( (string) ( $_POST['value'] ?? '' ) );

    if ( $field === 'user_login' ) {
        $username = sanitize_user( $value, true );
        $len      = mb_strlen( $username );

        if ( $username === '' ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '請輸入使用者名稱' ] );
        }
        if ( $len < SMACG_REGISTER_USERNAME_MIN || $len > SMACG_REGISTER_USERNAME_MAX ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => sprintf(
                '長度需介於 %d–%d 字元', SMACG_REGISTER_USERNAME_MIN, SMACG_REGISTER_USERNAME_MAX
            ) ] );
        }
        if ( ! preg_match( '/^[\w\x{4e00}-\x{9fff}\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{ac00}-\x{d7af}]+$/u', $username ) ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '僅可包含中英數字與底線' ] );
        }
        $blocked = [
            'admin', 'administrator', 'root', 'superuser', 'sysadmin', 'webmaster',
            'support', 'help', 'service', 'official',
            'test', 'demo', 'null', 'undefined', 'guest', 'anonymous',
            'weixiao', 'weixiaoacg', 'smacg', '微笑動漫', '微笑',
        ];
        if ( in_array( mb_strtolower( $username ), $blocked, true ) ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '此名稱不可使用' ] );
        }
        if ( username_exists( $username ) ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '此使用者名稱已被使用' ] );
        }
        wp_send_json_success( [ 'ok' => true, 'msg' => '此使用者名稱可使用' ] );
    }

    if ( $field === 'user_email' ) {
        $email = sanitize_email( $value );
        if ( $email === '' || ! is_email( $email ) ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '請輸入有效的電子郵件' ] );
        }
        $domain  = strtolower( substr( strrchr( $email, '@' ), 1 ) );
        $blocked = [
            'mailinator.com', '10minutemail.com', '10minutemail.net',
            'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org',
            'tempmail.com', 'tempmail.net', 'temp-mail.org', 'temp-mail.io',
            'yopmail.com', 'throwaway.email', 'trashmail.com',
            'maildrop.cc', 'mintemail.com', 'sharklasers.com',
            'getnada.com', 'fakemail.net', 'mvrht.net',
        ];
        if ( in_array( $domain, $blocked, true ) ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '不接受拋棄式信箱' ] );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error( [ 'ok' => false, 'msg' => '此電子郵件已被註冊' ] );
        }
        wp_send_json_success( [ 'ok' => true, 'msg' => '此電子郵件可使用' ] );
    }

    wp_send_json_error( [ 'ok' => false, 'msg' => '未知欄位' ] );
}
