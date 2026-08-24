<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * EXP 事件監聽
 *
 * v2.10.1 (2026-06-11)
 *   - Fix（重大）：Google 社群登入出現「嚴重錯誤」白畫面。
 *     原因：on_nsl_login() / on_nsl_register() 直接對 $provider 做
 *     (string) 轉型，但 Nextend Social Login 傳入的是物件
 *     （NextendSocialProviderGoogle，無 __toString），PHP 8.3 觸發
 *     "Object of class ... could not be converted to string" Fatal。
 *     新增 normalize_nsl_provider()：物件用 getId() 取名，字串原樣處理。
 *
 * v2.10.0 (2026-06-11)
 *   - Feat：Email 驗證獎勵統一搬遷至本檔，集中由 Exp_Events 管理。
 *     處理 4 種情境：
 *       (1) smacg_email_verified action → 發 Lv.10 5000 EXP 補滿獎勵
 *       (2) NSL 新註冊用戶 → 自動視為已驗證並發獎
 *       (3) NSL 既有用戶下次登入 → wp_login 兜底發獎
 *       (4) 管理員訪問 admin → 一次性補發既有 NSL 用戶
 *     完全沿用 award_with_cap()/Gamipress_Bridge 既有發放管道，
 *     不破壞 once 鎖、season_score、level_up 流程。
 *
 * v2.9.0 (2026-06-11)
 *   - Fix：Nextend Social Login（Google 等）註冊領不到「註冊帳號」獎勵。
 *
 * v2.8.0 (2026-06-07)
 *   - Feat：wpForo 論壇 hooks
 *
 * v2.7.2 (2026-05-23)
 *   - Feat：streak 里程碑改為循環式
 *
 * v2.7.1 (2026-05-22)
 *   - Fix BugP：once / dedupe_key 改用 add_user_meta(unique=true) 樂觀搶鎖
 *
 * v2.7.0 (2026-05-22)
 *   - Feat：award_with_cap() 改用規則的 season_score 寫入賽季積分
 *
 * v2.6.4 (2026-05-20)
 *   - watchlist/rating per-anime 去重
 */
class Exp_Events {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'user_register', function ( $uid ) {
            self::award_with_cap( $uid, 'register' );
        }, 20 );

        // v2.9.0：Nextend Social Login（Google 等）註冊保險絲
        // 因 register 用 once 鎖，與上面的 user_register 不會重複發放
        // 優先序 99：確保在 Nextend 把帳號 meta 都寫完後才發獎
        add_action( 'nsl_register_new_user', function ( $user_id, $provider ) {
            self::award_with_cap( (int) $user_id, 'register' );
        }, 99, 2 );

        add_action( 'wp_login', [ __CLASS__, 'on_login' ], 20, 2 );

        add_action( 'comment_post', [ __CLASS__, 'on_comment_post' ], 20, 3 );
        add_action( 'transition_comment_status', [ __CLASS__, 'on_comment_approved' ], 20, 3 );

        /*
         * 自建評論系統（anime-sync-pro 的 wxacg_review CPT）。
         *
         * 留言區 2026-08-18 由 wpDiscuz 換成自建系統後，送出評論走的是
         * wxacg_review_submitted，不再經過 WordPress 原生的 comment_post，
         * 因此上面那條掛不到，評論一直拿不到 EXP（徽章有給，是因為
         * First_Badge 有監聽這個 action）。
         *
         * 共用 comment_post 規則與每日上限：兩者是「發表留言」的兩種實作，
         * 不該因為換了系統就能各拿一次。
         */
        add_action( 'wxacg_review_submitted', [ __CLASS__, 'on_review_submitted' ], 20, 4 );

        add_action( 'smacg_user_followed', [ __CLASS__, 'on_follow' ], 20, 2 );

        add_action( 'smacg_watchlist_added',     [ __CLASS__, 'on_watchlist_added' ],     20, 2 );
        add_action( 'smacg_watchlist_completed', [ __CLASS__, 'on_watchlist_completed' ], 20, 2 );
        add_action( 'smacg_rating_added',        [ __CLASS__, 'on_rating_added' ],        20, 2 );

        add_action( 'gamipress_award_achievement', [ __CLASS__, 'on_badge_unlock' ], 20, 2 );

        add_action( 'smacg_post_read',         [ __CLASS__, 'on_post_read' ],         20, 2 );
        add_action( 'smacg_post_shared',       [ __CLASS__, 'on_post_shared' ],       20, 2 );
        add_action( 'smacg_favorite_added',    [ __CLASS__, 'on_favorite_added' ],    20, 2 );
        add_action( 'smacg_profile_avatar',    [ __CLASS__, 'on_profile_avatar' ],    20, 1 );
        add_action( 'smacg_profile_bio',       [ __CLASS__, 'on_profile_bio' ],       20, 1 );
        add_action( 'smacg_profile_birthday',  [ __CLASS__, 'on_profile_birthday' ],  20, 1 );
        add_action( 'smacg_birthday_today',    [ __CLASS__, 'on_birthday_today' ],    20, 1 );
        add_action( 'smacg_anniversary_today', [ __CLASS__, 'on_anniversary_today' ], 20, 2 );

        /* wpForo 論壇 */
        add_action( 'wpforo_after_add_topic', [ __CLASS__, 'on_forum_topic' ], 20, 1 );
        add_action( 'wpforo_after_add_post',  [ __CLASS__, 'on_forum_reply' ], 20, 2 );

        add_action( 'smacg_exp_daily_reset', [ __CLASS__, 'daily_reset' ] );

        /* ====================================================
         * v2.10.0：Email 驗證獎勵
         * ==================================================== */

        // 1. smacg_email_verified action → 補滿至 Lv.10
        add_action( 'smacg_email_verified', [ __CLASS__, 'on_email_verified' ], 10, 2 );

        // 2. UM 內建 email 驗證
        add_action( 'um_after_email_confirmation', [ __CLASS__, 'on_um_email_confirmed' ], 99, 1 );

        // 3. NSL 新註冊 → 註冊當下直接視為已驗證
        // 優先序 100：排在 nsl_register_new_user(99) 註冊獎之後
        add_action( 'nsl_register_new_user', [ __CLASS__, 'on_nsl_register' ], 100, 2 );

        // 4. NSL 登入 hook（部分 NSL 版本支援）
        add_action( 'nsl_login', [ __CLASS__, 'on_nsl_login' ], 100, 2 );

        // 5. wp_login 兜底：掃描已串接 social 的用戶
        // 優先序 5：早於既有 on_login(20)
        add_action( 'wp_login', [ __CLASS__, 'on_login_social_backfill' ], 5, 2 );

        // 6. 一次性補發既有 NSL 用戶（admin_init 觸發、option 鎖死）
        add_action( 'admin_init', [ __CLASS__, 'maybe_backfill_social_users' ], 999 );
    }

    private static function reason_labels() {
        $labels = [
            'register'           => '註冊帳號',
            'daily_login'        => '每日首次登入',
            'streak_7'           => '連續登入 7 天',
            'streak_30'          => '連續登入 30 天',
            'comment_post'       => '發表留言',
            'follow_action'      => '追蹤其他會員',
            'followed_by'        => '獲得新粉絲',
            'watchlist_add'      => '加入觀看清單',
            'watchlist_complete' => '完成觀看',
            'rating_add'         => '評分動畫',
            'badge_unlock'       => '解鎖徽章',
            'review_long'        => '發表長評',
            'read_post'          => '閱讀文章',
            'share_post'         => '分享文章',
            'favorite_add'       => '收藏動畫',
            'profile_avatar'     => '設定頭像',
            'profile_bio'        => '撰寫自我介紹',
            'profile_birthday'   => '設定生日',
            'profile_complete'   => '完成個人資料',
            'birthday'           => '生日禮金',
            'anniversary'        => '註冊週年慶',
            'forum_topic'        => '論壇發表主題',
            'forum_reply'        => '論壇回覆',
            'email_verified'     => 'Email 驗證',
            'badge_tier_complete_common'    => '成就集滿：普通',
            'badge_tier_complete_uncommon'  => '成就集滿：少見',
            'badge_tier_complete_rare'      => '成就集滿：稀有',
            'badge_tier_complete_epic'      => '成就集滿：史詩',
            'badge_tier_complete_legendary' => '成就集滿：傳說（全部解鎖）',
        ];
        return apply_filters( 'smacg_exp_reason_labels', $labels );
    }

    private static function get_reason_label( $action_key ) {
        $labels = self::reason_labels();
        return $labels[ $action_key ] ?? ( 'EXP: ' . $action_key );
    }

    public static function award_with_cap( $uid, $action_key, $extra_args = [] ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return false;

        $rule = Exp_Config::get( $action_key );
        if ( ! $rule ) return false;

        $amount = (int) ( $extra_args['exp_override'] ?? $rule['exp'] );
        if ( $amount <= 0 ) return false;

        $acquired_locks = [];

        // 1a：dedupe_key 永久去重
        $dedupe_key = isset( $extra_args['dedupe_key'] ) ? (string) $extra_args['dedupe_key'] : '';
        if ( $dedupe_key !== '' ) {
            $ok = add_user_meta( $uid, $dedupe_key, current_time( 'mysql' ), true );
            if ( ! $ok ) {
                return false;
            }
            $acquired_locks[] = $dedupe_key;
        }

        // 1b：once cap
        $once_meta = null;
        if ( $rule['cap_type'] === 'once' && $rule['cap_key'] ) {
            $once_meta = 'smacg_exp_once_' . $rule['cap_key'];
            $ok = add_user_meta( $uid, $once_meta, current_time( 'mysql' ), true );
            if ( ! $ok ) {
                self::rollback_locks( $uid, $acquired_locks );
                return false;
            }
            $acquired_locks[] = $once_meta;
        }

        // 1c：daily cap
        //
        // 名額必須在發 EXP「之前」就原子搶下。
        // 舊寫法是先讀計數判斷、發完 EXP 才 update_user_meta 寫回，
        // 兩個並發請求會同時讀到未達上限的舊值、雙雙通過檢查而超發
        // （各動作有自己的 dedupe_key，擋不住彼此）。
        $daily_meta = null;
        if ( $rule['cap_type'] === 'daily' && $rule['cap_key'] ) {
            $today     = current_time( 'Ymd' );
            $meta_key  = 'smacg_exp_daily_' . $rule['cap_key'] . '_' . $today;
            $daily_max = (int) ( $rule['daily_max'] ?? 1 );

            if ( ! self::claim_daily_slot( $uid, $meta_key, $daily_max ) ) {
                self::rollback_locks( $uid, $acquired_locks );
                return false;
            }

            $daily_meta = $meta_key;
        }

        $before = function_exists( 'smacg_calc_level_from_exp' )
            ? smacg_calc_level_from_exp( Gamipress_Bridge::get_user_exp( $uid ) )
            : 0;

        $reason = $extra_args['reason'] ?? self::get_reason_label( $action_key );

        $ok = Gamipress_Bridge::award_exp( $uid, $amount, $reason, $extra_args );
        if ( ! $ok ) {
            self::rollback_locks( $uid, $acquired_locks );

            // 發放失敗就把先前搶下的名額還回去，不佔用今日額度。
            if ( $daily_meta ) {
                self::release_daily_slot( $uid, $daily_meta );
            }

            return false;
        }

        $season_score = isset( $rule['season_score'] ) ? (int) $rule['season_score'] : $amount;
        if ( $season_score > 0 && class_exists( '\WXACG\Gamification\Rank_Season' ) ) {
            Rank_Season::add_score( $uid, $season_score );
        }

        $after = function_exists( 'smacg_calc_level_from_exp' )
            ? smacg_calc_level_from_exp( Gamipress_Bridge::get_user_exp( $uid ) )
            : 0;

        if ( $after > $before ) {
            self::handle_level_up( $uid, $before, $after );
        }

        return true;
    }

    /**
     * 原子搶下今日名額。
     *
     * 與同函式裡 dedupe_key / once cap 使用 add_user_meta( ..., true )
     * 唯一插入的精神一致，只是每日上限允許多次，所以改用
     * 「條件式 UPDATE + rows_affected」當作原子鎖
     * （與 class-event-tracker.php::settle_one() 的作法相同）。
     *
     * @return bool 搶到名額為 true；已達上限為 false。
     */
    private static function claim_daily_slot( $uid, $meta_key, $max ) {
        $uid = (int) $uid;
        $max = (int) $max;

        if ( $uid <= 0 || $max < 1 || $meta_key === '' ) {
            return false;
        }

        // 今天第一次：唯一插入成功就等於搶到第 1 個名額。
        // 兩個並發請求只會有一個插入成功，另一個往下走 UPDATE 路徑。
        if ( add_user_meta( $uid, $meta_key, '1', true ) ) {
            return true;
        }

        global $wpdb;

        // 已有計數：把「未達上限」寫進 WHERE，
        // 由資料庫保證同一列同時只有一個請求能加成功。
        $rows = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->usermeta}
             SET meta_value = CAST( meta_value AS UNSIGNED ) + 1
             WHERE user_id = %d
               AND meta_key = %s
               AND CAST( meta_value AS UNSIGNED ) < %d",
            $uid,
            $meta_key,
            $max
        ) );

        if ( $rows > 0 ) {
            // 繞過 WP API 直接改 DB，必須自行清掉 user meta 快取。
            wp_cache_delete( $uid, 'user_meta' );
            return true;
        }

        return false;
    }

    /**
     * 歸還今日名額（EXP 發放失敗時回滾用）。
     */
    private static function release_daily_slot( $uid, $meta_key ) {
        $uid = (int) $uid;

        if ( $uid <= 0 || $meta_key === '' ) {
            return;
        }

        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->usermeta}
             SET meta_value = CAST( meta_value AS UNSIGNED ) - 1
             WHERE user_id = %d
               AND meta_key = %s
               AND CAST( meta_value AS UNSIGNED ) > 0",
            $uid,
            $meta_key
        ) );

        wp_cache_delete( $uid, 'user_meta' );
    }

    private static function rollback_locks( $uid, array $meta_keys ) {
        $uid = (int) $uid;
        if ( $uid <= 0 || empty( $meta_keys ) ) return;

        foreach ( $meta_keys as $key ) {
            if ( $key !== '' ) {
                delete_user_meta( $uid, $key );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SMACG] award_with_cap rolled back locks for uid=%d: %s',
                $uid,
                implode( ',', $meta_keys )
            ) );
        }
    }

    public static function handle_level_up( $uid, $from_level, $to_level ) {
        $job = function_exists( 'smacg_get_job_by_level' )
            ? smacg_get_job_by_level( $to_level )
            : '';

        do_action( 'smacg_level_up', $uid, $to_level, $job );

        $milestones = [ 10, 30, 70, 120, 200 ];
        foreach ( $milestones as $m ) {
            if ( $from_level < $m && $to_level >= $m ) {
                $meta = 'smacg_milestone_lv_' . $m;
                if ( get_user_meta( $uid, $meta, true ) ) continue;
                update_user_meta( $uid, $meta, current_time( 'mysql' ) );
                do_action( 'smacg_level_milestone', $uid, $m, $from_level, $to_level );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf( '[SMACG] user #%d hit milestone Lv.%d', $uid, $m ) );
                }
            }
        }
    }

    /* ============================================================
     * v2.10.0：Email 驗證獎勵核心
     * ============================================================ */

    /**
     * 統一發放函式：標記驗證 + 補滿至 Lv.10（5000 EXP）
     *
     * 防呆：once 鎖（smacg_verify_bonus_given）保證只能領一次
     *
     * @param int    $uid   使用者 ID
     * @param string $via   觸發來源（email_link / um_confirm / nsl_google / wp_login_backfill ...）
     */
    public static function award_email_verified( $uid, $via = 'manual' ) {
        $uid = (int) $uid;
        $via = sanitize_key( (string) $via );
        if ( $uid <= 0 ) return;

        $user = get_user_by( 'id', $uid );
        if ( ! $user || empty( $user->user_email ) ) return;

        // once 鎖：已領過獎勵就跳過
        if ( get_user_meta( $uid, 'smacg_verify_bonus_given', true ) ) return;

        // 1. 寫驗證標記（讓 smacg_user_needs_email_verify 回 false）
        update_user_meta( $uid, 'smacg_email_verified',     1 );
        update_user_meta( $uid, 'smacg_email_verified_at',  time() );
        update_user_meta( $uid, 'smacg_email_verified_via', $via );

        // 2. 清掉驗證流程殘留
        delete_user_meta( $uid, 'smacg_email_verify_token' );
        delete_user_meta( $uid, 'smacg_email_verify_sent_at' );
        wp_clear_scheduled_hook( 'smacg_delayed_verify_notif', [ $uid ] );

        // 3. 計算需要補多少 EXP 才到 Lv.10（5000 EXP）
        $target_exp  = 5000;
        $current_exp = Gamipress_Bridge::get_user_exp( $uid );
        $bonus       = max( 0, $target_exp - $current_exp );

        // 4. 發放 EXP（透過 Gamipress_Bridge，沿用既有管道）
        if ( $bonus > 0 ) {
            $before = function_exists( 'smacg_calc_level_from_exp' )
                ? smacg_calc_level_from_exp( $current_exp )
                : 0;

            $reason = 'Email 驗證獎勵（' . $via . '）';
            Gamipress_Bridge::award_exp( $uid, $bonus, $reason, [
                'source' => 'email_verified',
                'via'    => $via,
            ] );

            $after = function_exists( 'smacg_calc_level_from_exp' )
                ? smacg_calc_level_from_exp( Gamipress_Bridge::get_user_exp( $uid ) )
                : 0;

            if ( $after > $before ) {
                self::handle_level_up( $uid, $before, $after );
            }
        }

        // 5. 記錄已發放（防止重複領取）
        update_user_meta( $uid, 'smacg_verify_bonus_given', current_time( 'mysql' ) );
        update_user_meta( $uid, 'smacg_verify_bonus_exp',   $bonus );

        // 6. 清除驗證通知冷卻
        delete_transient( 'smacg_verify_notif_' . $uid );

        // 7. 發送歡迎通知
        if ( function_exists( 'wxacg_create_notification' ) ) {
            wxacg_create_notification( [
                'user_id'     => $uid,
                'type'        => 'system',
                'object_type' => 'user',
                'object_id'   => $uid,
                'data'        => [
                    'kind'    => 'verify_bonus',
                    'title'   => '🎉 驗證成功！歡迎加入微笑動漫',
                    'excerpt' => '恭喜獲得 ' . number_format( $bonus ) . ' EXP 並升級到 Lv.10（新人 🌿）！開始探索動漫世界吧',
                    'url'     => home_url( '/mc/' ),
                    'icon'    => '🎉',
                ],
                'force'       => true,
            ] );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[SMACG Verify Bonus] uid=%d bonus=%d via=%s', $uid, $bonus, $via ) );
        }
    }

    /**
     * v2.10.1：把 NSL 的 $provider 參數正規化成字串 provider 名稱
     *
     * Nextend Social Login 傳入的可能是：
     *   - NextendSocialProviderGoogle 等物件（需呼叫 getId()，回傳 "google" 等）
     *   - 字串（舊版或其他 hook）
     *
     * 直接對物件做 (string) 轉型在 PHP 8.x 會 Fatal：
     *   "Object of class ... could not be converted to string"
     */
    private static function normalize_nsl_provider( $provider ) {
        if ( is_object( $provider ) ) {
            if ( method_exists( $provider, 'getId' ) ) {
                return sanitize_key( (string) $provider->getId() );
            }
            return sanitize_key( get_class( $provider ) );
        }
        return sanitize_key( (string) $provider );
    }

    /**
     * Hook 1：smacg_email_verified action（一般點驗證信流程）
     */
    public static function on_email_verified( $uid, $via = 'email_link' ) {
        self::award_email_verified( (int) $uid, (string) $via );
    }

    /**
     * Hook 2：UM 內建 email 確認
     */
    public static function on_um_email_confirmed( $user_id ) {
        self::award_email_verified( (int) $user_id, 'um_confirm' );
    }

    /**
     * Hook 3：NSL 新註冊（Google / FB / Apple…）
     * 註冊當下直接視為已驗證
     */
    public static function on_nsl_register( $user_id, $provider ) {
        $provider_key = self::normalize_nsl_provider( $provider );
        self::award_email_verified( (int) $user_id, 'nsl_' . $provider_key );
    }

    /**
     * Hook 4：NSL 既有用戶登入
     */
    public static function on_nsl_login( $user_id, $provider ) {
        $provider_key = self::normalize_nsl_provider( $provider );
        self::award_email_verified( (int) $user_id, 'nsl_login_' . $provider_key );
    }

    /**
     * Hook 5：wp_login 兜底
     * 掃描使用者是否有任何 social provider meta
     */
    public static function on_login_social_backfill( $login, $user ) {
        if ( ! $user instanceof \WP_User ) return;
        $uid = (int) $user->ID;

        // 已領獎 → 跳過
        if ( get_user_meta( $uid, 'smacg_verify_bonus_given', true ) ) return;

        // 已標記驗證但還沒領獎 → 直接發
        if ( (int) get_user_meta( $uid, 'smacg_email_verified', true ) === 1 ) {
            self::award_email_verified( $uid, 'wp_login_already_verified' );
            return;
        }

        // 檢查是否有 social provider 串接
        if ( self::user_has_social_provider( $uid ) ) {
            self::award_email_verified( $uid, 'wp_login_social' );
        }
    }

    /**
     * Hook 6：一次性補發既有 NSL 用戶
     * 管理員訪問 admin 時觸發，跑完用 option 鎖死
     */
    public static function maybe_backfill_social_users() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( get_option( 'smacg_exp_email_verify_backfill_v1' ) ) return;

        global $wpdb;

        // 撈所有有 social meta 的 user_id
        $user_ids = $wpdb->get_col( "
            SELECT DISTINCT user_id FROM {$wpdb->usermeta}
            WHERE meta_key IN (
                'nsl-linked-providers',
                'google_identifier',
                'facebook_identifier',
                'apple_identifier',
                'line_identifier',
                'twitter_identifier'
            )
        " );

        $count = 0;
        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;
            if ( get_user_meta( $uid, 'smacg_verify_bonus_given', true ) ) continue;
            self::award_email_verified( $uid, 'backfill_social' );
            $count++;
        }

        update_option( 'smacg_exp_email_verify_backfill_v1', [
            'done_at' => time(),
            'count'   => $count,
        ] );

        add_action( 'admin_notices', function () use ( $count ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
               . '<strong>[SMACG Gamification]</strong> 已補發 '
               . (int) $count
               . ' 位 Social Login 用戶的 Email 驗證獎勵。</p></div>';
        } );
    }

    /**
     * 檢查使用者是否有任何 social provider 串接
     */
    private static function user_has_social_provider( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return false;

        // NSL v3+
        $linked = get_user_meta( $uid, 'nsl-linked-providers', true );
        if ( ! empty( $linked ) ) return true;

        // 各 provider identifier
        foreach ( [ 'google', 'facebook', 'apple', 'twitter', 'line' ] as $p ) {
            if ( get_user_meta( $uid, $p . '_identifier', true ) ) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     * 既有事件監聽器
     * ============================================================ */

    /**
     * 每日登入
     */
    public static function on_login( $user_login, $user ) {
        if ( ! $user instanceof \WP_User ) return;
        $uid = (int) $user->ID;

        self::award_with_cap( $uid, 'daily_login' );

        $today  = current_time( 'Ymd' );
        $last   = (string) get_user_meta( $uid, 'smacg_last_login_date', true );
        $streak = (int) get_user_meta( $uid, 'smacg_login_streak', true );

        if ( $last === $today ) return;

        $yesterday = date( 'Ymd', strtotime( '-1 day', current_time( 'timestamp' ) ) );

        if ( $last === $yesterday ) {
            $streak += 1;
        } else {
            $streak = 1;
            if ( $last !== '' ) {
                $gen = (int) get_user_meta( $uid, 'smacg_streak_gen', true );
                update_user_meta( $uid, 'smacg_streak_gen', $gen + 1 );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[SMACG] streak broken for uid=%d (last=%s, today=%s) gen=%d',
                        $uid, $last, $today, $gen + 1
                    ) );
                }
            }
        }

        update_user_meta( $uid, 'smacg_last_login_date', $today );
        update_user_meta( $uid, 'smacg_login_streak',    $streak );

        $gen = (int) get_user_meta( $uid, 'smacg_streak_gen', true );

        if ( $streak > 0 && $streak % 7 === 0 ) {
            $cycle = (int) ( $streak / 7 );
            self::award_with_cap( $uid, 'streak_7', [
                'dedupe_key' => sprintf( 'smacg_exp_streak7_g%d_c%d', $gen, $cycle ),
                'reason'     => sprintf( '連續登入 %d 天', $streak ),
            ] );
            do_action( 'smacg_streak_milestone', $uid, 7, $cycle, $gen );
        }

        if ( $streak > 0 && $streak % 30 === 0 ) {
            $cycle = (int) ( $streak / 30 );
            self::award_with_cap( $uid, 'streak_30', [
                'dedupe_key' => sprintf( 'smacg_exp_streak30_g%d_c%d', $gen, $cycle ),
                'reason'     => sprintf( '連續登入 %d 天', $streak ),
            ] );
            do_action( 'smacg_streak_milestone', $uid, 30, $cycle, $gen );
        }
    }

    public static function on_comment_post( $comment_id, $approved, $commentdata ) {
        if ( $approved !== 1 ) return;
        $uid = (int) ( $commentdata['user_id'] ?? 0 );
        if ( $uid <= 0 ) return;

        $meta = 'smacg_exp_comment_' . $comment_id;
        if ( get_user_meta( $uid, $meta, true ) ) return;
        update_user_meta( $uid, $meta, 1 );

        self::award_with_cap( $uid, 'comment_post' );
    }

    public static function on_comment_approved( $new_status, $old_status, $comment ) {
        if ( $new_status !== 'approved' || $old_status === 'approved' ) return;
        $uid = (int) $comment->user_id;
        if ( $uid <= 0 ) return;

        $meta = 'smacg_exp_comment_' . $comment->comment_ID;
        if ( get_user_meta( $uid, $meta, true ) ) return;
        update_user_meta( $uid, $meta, 1 );

        self::award_with_cap( $uid, 'comment_post' );
    }

    /**
     * 自建評論系統送出評論（wxacg_review_submitted）。
     *
     * 只在新建時觸發，編輯自己的評論不會再發（見 class-review-manager.php
     * 的 $is_new 判定）。但「回覆」不套用 upsert，每則回覆都算新的，
     * 因此以 review_id 做永久去重，再由每日上限把關。
     *
     * ★ 長評走 review_long（50 EXP），但必須排除回覆：
     *   class-review-manager.php 的字數檢查是
     *     $min_len = ( $track === TRACK_LONG && ! $is_reply ) ? 80 : 1;
     *   回覆即使帶 track=long 也只要 1 字就能送出，若只看 track 就給高分，
     *   在長評串下回一個字即可拿走 50 EXP。action 簽章沒有傳 reply 資訊，
     *   因此這裡自行讀 meta 判斷。
     *
     * @param int    $uid       發表者。
     * @param int    $review_id 評論 CPT 的 post ID。
     * @param int    $anime_id  作品 ID（本函式未用，保留與 action 簽章一致）。
     * @param string $track     short（吐槽）或 long（長評）。
     */
    public static function on_review_submitted( $uid, $review_id, $anime_id = 0, $track = '' ) {
        $uid       = (int) $uid;
        $review_id = (int) $review_id;
        if ( $uid <= 0 || $review_id <= 0 ) return;

        $is_reply   = (int) get_post_meta( $review_id, '_wxacg_review_reply_to', true ) > 0;
        $action_key = ( $track === 'long' && ! $is_reply ) ? 'review_long' : 'comment_post';

        self::award_with_cap( $uid, $action_key, [
            'dedupe_key' => 'smacg_exp_review_' . $review_id,
        ] );
    }

    public static function on_follow( $follower_id, $followee_id ) {
        $follower_id = (int) $follower_id;
        $followee_id = (int) $followee_id;
        if ( $follower_id > 0 ) self::award_with_cap( $follower_id, 'follow_action' );
        if ( $followee_id > 0 ) self::award_with_cap( $followee_id, 'followed_by' );
    }

    public static function on_rating_added( $uid, $anime_id = 0 ) {
        $uid      = (int) $uid;
        $anime_id = (int) $anime_id;
        if ( $uid <= 0 ) return;

        $args = [];
        if ( $anime_id > 0 ) {
            $args['dedupe_key'] = 'smacg_exp_rating_' . $anime_id;
            $args['anime_id']   = $anime_id;
        }

        self::award_with_cap( $uid, 'rating_add', $args );
    }

    public static function on_watchlist_added( $uid, $anime_id = 0 ) {
        $uid      = (int) $uid;
        $anime_id = (int) $anime_id;
        if ( $uid <= 0 ) return;

        $args = [];
        if ( $anime_id > 0 ) {
            $args['dedupe_key'] = 'smacg_exp_wladd_' . $anime_id;
            $args['anime_id']   = $anime_id;
        }

        self::award_with_cap( $uid, 'watchlist_add', $args );
    }

    public static function on_watchlist_completed( $uid, $anime_id = 0 ) {
        $uid      = (int) $uid;
        $anime_id = (int) $anime_id;
        if ( $uid <= 0 ) return;

        $args = [];
        if ( $anime_id > 0 ) {
            $args['dedupe_key'] = 'smacg_exp_wlcomp_' . $anime_id;
            $args['anime_id']   = $anime_id;
        }

        self::award_with_cap( $uid, 'watchlist_complete', $args );
    }

    public static function on_badge_unlock( $user_id, $achievement_id ) {
        $user_id        = (int) $user_id;
        $achievement_id = (int) $achievement_id;
        if ( $user_id <= 0 || $achievement_id <= 0 ) return;

        $post = get_post( $achievement_id );
        if ( ! $post || $post->post_type !== WXACG_BADGE_SLUG ) return;

        $meta = 'smacg_exp_badge_' . $achievement_id;
        if ( get_user_meta( $user_id, $meta, true ) ) return;
        update_user_meta( $user_id, $meta, 1 );

        $custom = (int) get_post_meta( $achievement_id, '_smacg_badge_exp', true );

        $badge_reason = sprintf( '解鎖徽章「%s」', $post->post_title );
        $args = $custom > 0
            ? [ 'exp_override' => $custom, 'achievement_id' => $achievement_id, 'reason' => $badge_reason ]
            : [ 'achievement_id' => $achievement_id, 'reason' => $badge_reason ];

        self::award_with_cap( $user_id, 'badge_unlock', $args );
    }

    public static function on_post_read( $uid, $post_id = 0 ) {
        $uid     = (int) $uid;
        $post_id = (int) $post_id;
        if ( $uid <= 0 || $post_id <= 0 ) return;

        if ( get_post_type( $post_id ) !== 'post' ) return;

        self::award_with_cap( $uid, 'read_post', [
            'dedupe_key' => 'smacg_exp_read_' . $post_id,
            'post_id'    => $post_id,
        ] );
    }

    public static function on_post_shared( $uid, $post_id = 0 ) {
        $uid     = (int) $uid;
        $post_id = (int) $post_id;
        if ( $uid <= 0 || $post_id <= 0 ) return;

        self::award_with_cap( $uid, 'share_post', [
            'dedupe_key' => 'smacg_exp_share_' . $post_id,
            'post_id'    => $post_id,
        ] );
    }

    public static function on_favorite_added( $uid, $anime_id = 0 ) {
        $uid      = (int) $uid;
        $anime_id = (int) $anime_id;
        if ( $uid <= 0 || $anime_id <= 0 ) return;

        self::award_with_cap( $uid, 'favorite_add', [
            'dedupe_key' => 'smacg_exp_favorite_' . $anime_id,
            'anime_id'   => $anime_id,
        ] );
    }

    public static function on_profile_avatar( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return;
        self::award_with_cap( $uid, 'profile_avatar' );
        self::maybe_award_profile_complete( $uid );
    }

    public static function on_profile_bio( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return;
        self::award_with_cap( $uid, 'profile_bio' );
        self::maybe_award_profile_complete( $uid );
    }

    public static function on_profile_birthday( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return;
        self::award_with_cap( $uid, 'profile_birthday' );
        self::maybe_award_profile_complete( $uid );
    }

    private static function maybe_award_profile_complete( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return;

        $has_avatar   = (bool) get_user_meta( $uid, 'smacg_exp_once_profile_avatar', true );
        $has_bio      = (bool) get_user_meta( $uid, 'smacg_exp_once_profile_bio', true );
        $has_birthday = (bool) get_user_meta( $uid, 'smacg_exp_once_profile_birthday', true );

        if ( $has_avatar && $has_bio && $has_birthday ) {
            self::award_with_cap( $uid, 'profile_complete' );
        }
    }

    public static function on_birthday_today( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return;

        $year = (int) current_time( 'Y' );
        self::award_with_cap( $uid, 'birthday', [
            'dedupe_key' => 'smacg_exp_birthday_' . $year,
        ] );
    }

    public static function on_anniversary_today( $uid, $years = 1 ) {
        $uid   = (int) $uid;
        $years = max( 1, (int) $years );
        if ( $uid <= 0 ) return;

        self::award_with_cap( $uid, 'anniversary', [
            'dedupe_key' => 'smacg_exp_anniversary_y' . $years,
            'reason'     => sprintf( '註冊 %d 週年慶', $years ),
        ] );
    }

    /**
     * wpForo 發新主題給分
     */
    public static function on_forum_topic( $topic ) {
        $uid = self::get_wpforo_uid( $topic );
        if ( $uid <= 0 ) return;

        $tid  = is_array( $topic ) ? (int) ( $topic['topicid'] ?? 0 ) : 0;
        $args = [];
        if ( $tid > 0 ) {
            $args['dedupe_key'] = 'smacg_exp_forum_topic_' . $tid;
        }

        self::award_with_cap( $uid, 'forum_topic', $args );
    }

    /**
     * wpForo 發回覆給分
     */
    public static function on_forum_reply( $post, $topic = [] ) {
        if ( ! is_array( $post ) ) return;
        if ( ! empty( $post['is_first_post'] ) ) return; // 主題開頭文，已由 on_forum_topic 處理

        $uid = self::get_wpforo_uid( $post );
        if ( $uid <= 0 ) return;

        $pid  = (int) ( $post['postid'] ?? 0 );
        $args = [];
        if ( $pid > 0 ) {
            $args['dedupe_key'] = 'smacg_exp_forum_reply_' . $pid;
        }

        self::award_with_cap( $uid, 'forum_reply', $args );
    }

    private static function get_wpforo_uid( $data ) {
        if ( ! is_array( $data ) ) return 0;
        $uid = (int) ( $data['userid'] ?? 0 );
        return $uid > 0 ? $uid : 0;
    }

    public static function daily_reset() {
        global $wpdb;
        $now_ts = current_time( 'timestamp' );
        $total  = 0;

        for ( $d = 2; $d <= 32; $d++ ) {
            $ymd  = date( 'Ymd', strtotime( "-{$d} days", $now_ts ) );
            $like = 'smacg_exp_daily_%_' . $ymd;

            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $like
            ) );
            $total += (int) $deleted;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[SMACG] Daily EXP reset complete: %d rows deleted', $total ) );
        }
    }
}
