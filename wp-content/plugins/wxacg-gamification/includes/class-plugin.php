<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * v2.7.2 (2026-05-23)
 *   - 載入 Daily_Login_Fallback：解決使用者長期保持登入狀態而拿不到
 *     daily_login EXP 的問題（WordPress logged-in cookie 預設 14 天，
 *     單靠 wp_login hook 只在「重新登入」時觸發）。
 *
 * v2.7.0 (2026-05-22)
 *   - 載入 v2.7.0 新管道：
 *       Read_Tracker      閱讀文章 EXP（單篇 30 秒）
 *       Share_Tracker     分享文章 EXP（點擊 .share-btn）
 *       Profile_Tracker   個人資料完善 EXP（avatar/bio/birthday）
 *       Anniversary_Cron  每日掃生日/註冊週年
 *
 * v2.0.2 (2026-05-20)
 *   - Fix #15-1：enqueue_leaderboard_css 加上頁面條件
 *
 * v2.0.1 (2026-05-20)
 *   - Fix #12：enqueue_career_select_js 優先級 25 → 15
 *
 * v2.0.0 (2026-05-15)
 *   - 載入新 Career_Jobs（8 職業）
 *   - Level_System / Career_Ajax 改用新版 sqrt 公式 + 6 階稱號
 */
class Plugin {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
            self::$instance->load();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function load() {
        $base = WXACG_GAMIFY_DIR . 'includes/';

        // 1. GamiPress Bridge
        require_once $base . 'gamipress/class-gamipress-bridge.php';
        Gamipress_Bridge::instance();

        // 2. Level（新版：sqrt 公式 + 6 階會員稱號）
        require_once $base . 'level/class-level-system.php';
        Level_System::instance();

        // 3. EXP
        require_once $base . 'exp/class-exp-config.php';
        require_once $base . 'exp/class-exp-events.php';
        Exp_Events::instance();

        // 3a. Daily Login Fallback（v2.7.2）
        //     必須在 Exp_Events 之後載入（依賴 Exp_Events::award_with_cap）
        require_once $base . 'exp/class-daily-login-fallback.php';
        Daily_Login_Fallback::instance();

        // 3b. EXP 新管道（v2.7.0）
        require_once $base . 'exp/class-read-tracker.php';
        Read_Tracker::instance();
        require_once $base . 'exp/class-share-tracker.php';
        Share_Tracker::instance();
        require_once $base . 'exp/class-anniversary-cron.php';
        Anniversary_Cron::instance();
        require_once $base . 'profile/class-profile-tracker.php';
        Profile_Tracker::instance();

        // 3c. 「第一次動作」徽章 + EXP（1.0.0）
        //     必須在 Exp_Events / Gamipress_Bridge 之後載入
        //     （依賴 Exp_Events::award_with_cap、Gamipress_Bridge::award_badge）
        require_once $base . 'first-action/class-first-badge.php';
        First_Badge::instance();

        // 4. Career Jobs（8 職業 × 4 階稱號）+ AJAX endpoint
        require_once $base . 'level/class-career-jobs.php';
        Career_Jobs::instance();
        require_once $base . 'level/class-career-ajax.php';
        Career_Ajax::instance();

        // 5. Level UI（留言徽章 / hero 大徽章）
        require_once $base . 'level/class-level-badge.php';
        Level_Badge::instance();

        // 6. Ranking
        require_once $base . 'ranking/class-ranking-system.php';
        Ranking_System::instance();
        require_once $base . 'ranking/class-ranking-cron.php';
        Ranking_Cron::instance();
        // Ranking_Privacy 已移除：只註冊了一支沒有呼叫端的 AJAX 切換與
        // 一份沒有消費者的 localize，前端切換按鈕從未實作。
        // 排行榜仍會排除 WXACG_RANKING_META_KEY 為 '0' 的使用者
        // （見 Ranking_System::excluded_user_ids()），該過濾邏輯直接讀 meta，
        // 不依賴此類別，日後要重做開關時後端不必重寫。
        require_once $base . 'ranking/class-leaderboard-ajax.php';
        Leaderboard_Ajax::instance();
        require_once $base . 'ranking/class-leaderboard-widget.php';
        Leaderboard_Widget::instance();

        // 6b. Rank Season（TFT 段位賽季）
        require_once $base . 'ranking/class-rank-tier.php';
        require_once $base . 'ranking/class-rank-season.php';
        Rank_Season::instance();

        // 7. Season Event
        require_once $base . 'season-event/class-event-cpt.php';
        Event_CPT::instance();
        require_once $base . 'season-event/class-event-tracker.php';
        Event_Tracker::instance();
        require_once $base . 'season-event/class-event-settle.php';
        Event_Settle::instance();
        if ( is_admin() ) {
            require_once $base . 'season-event/class-event-admin.php';
            Event_Admin::instance();
        }

        // 8. GamiPress Notification Bridge
        require_once $base . 'gamipress/class-gamipress-notif-bridge.php';
        Gamipress_Notif_Bridge::instance();

        // 9. REST
        require_once $base . 'rest/class-rest-api.php';
        Rest_Api::instance();

        // 10. Compat
        require_once $base . 'compat/legacy-functions.php';

        // === Hooks ===
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_career_select_js' ], 15 );
        add_action( 'wp_enqueue_scripts', [ $this, 'localize_career_nonce' ], 20 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_leaderboard_css' ], 20 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_season_event_css' ], 20 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_rank_season_css' ], 20 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_level_guide_assets' ], 20 );
        add_action( 'init', [ $this, 'maybe_upgrade_db' ], 5 );
        add_action( 'init', [ $this, 'maybe_flush_rewrite' ], 99 );

        // v2.7.0：確保 anniversary cron 已排程（活躍升級時用）
        add_action( 'init', [ 'WXACG\Gamification\Anniversary_Cron', 'schedule' ], 6 );
    }

    public function localize_career_nonce() {
        if ( ! is_user_logged_in() ) return;
        $data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'smacg_career_nonce' ),
        ];
        if ( wp_script_is( 'smacg-career-select', 'enqueued' ) ) {
            wp_localize_script( 'smacg-career-select', 'SmacgCareer', $data );
            return;
        }
        add_action( 'wp_footer', function () use ( $data ) {
            echo '<script>window.SmacgCareer = ' . wp_json_encode( $data ) . ';</script>';
        }, 5 );
    }

    public function enqueue_leaderboard_css() {
        if ( ! self::should_load_leaderboard_css() ) return;

        $f = get_stylesheet_directory() . '/assets/css/leaderboard-widget.css';
        if ( file_exists( $f ) ) {
            wp_enqueue_style( 'smacg-leaderboard-widget',
                get_stylesheet_directory_uri() . '/assets/css/leaderboard-widget.css',
                [], filemtime( $f ) );
        }
    }

    private static function should_load_leaderboard_css() {
        if ( is_page_template( 'page-ranking-users.php' ) ) return true;
        if ( is_page( 'ranking-users' ) || is_page( 'ranking' ) ) return true;
        if ( is_page( 'mc' ) || is_page_template( 'page-member.php' ) ) return true;
        if ( is_active_widget( false, false, 'smacg_leaderboard', true ) ) return true;
        if ( is_singular() ) {
            $post = get_post();
            if ( $post && has_shortcode( $post->post_content, 'smacg_leaderboard' ) ) {
                return true;
            }
        }
        return (bool) apply_filters( 'smacg_force_load_leaderboard_css', false );
    }

    public function enqueue_season_event_css() {
        if ( ! ( is_singular( WXACG_EVENT_CPT ) || is_post_type_archive( WXACG_EVENT_CPT ) ) ) return;
        $f = get_stylesheet_directory() . '/assets/css/season-event.css';
        if ( file_exists( $f ) ) {
            wp_enqueue_style( 'smacg-season-event',
                get_stylesheet_directory_uri() . '/assets/css/season-event.css',
                [], filemtime( $f ) );
        }
    }

    public function enqueue_rank_season_css() {
        if ( ! is_page_template( 'page-ranking-users.php' ) && ! is_page( 'mc' ) ) return;
        $f = get_stylesheet_directory() . '/assets/css/rank-season.css';
        if ( file_exists( $f ) ) {
            wp_enqueue_style( 'smacg-rank-season',
                get_stylesheet_directory_uri() . '/assets/css/rank-season.css',
                [], filemtime( $f ) );
        }
    }

    public function enqueue_level_guide_assets() {
        if ( ! is_page_template( 'page-level-guide.php' ) ) return;

        $css = get_stylesheet_directory() . '/assets/css/level-guide.css';
        if ( file_exists( $css ) ) {
            wp_enqueue_style( 'smacg-level-guide',
                get_stylesheet_directory_uri() . '/assets/css/level-guide.css',
                [], filemtime( $css ) );
        }

        $js = get_stylesheet_directory() . '/assets/js/level-guide.js';
        if ( file_exists( $js ) ) {
            wp_enqueue_script( 'smacg-level-guide',
                get_stylesheet_directory_uri() . '/assets/js/level-guide.js',
                [], filemtime( $js ), true );
        }
    }

    public function enqueue_career_select_js() {
        if ( ! is_user_logged_in() ) return;
        if ( ! is_page_template( 'page-member.php' )
            && ! is_page_template( 'page-level-guide.php' )
            && ! is_page( 'mc' ) ) return;

        $js = get_stylesheet_directory() . '/assets/js/career-select.js';
        if ( file_exists( $js ) ) {
            wp_enqueue_script( 'smacg-career-select',
                get_stylesheet_directory_uri() . '/assets/js/career-select.js',
                [], filemtime( $js ), true );

            wp_localize_script( 'smacg-career-select', 'SmacgCareer', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'smacg_career_nonce' ),
            ] );
        }
    }

    public function maybe_upgrade_db() {
        require_once WXACG_GAMIFY_DIR . 'includes/class-activator.php';
        if ( get_option( 'smacg_ranking_db_version', '0' ) !== WXACG_RANKING_DB_VERSION ) {
            Activator::install_ranking_tables();
        }
        if ( get_option( 'smacg_event_db_version', '0' ) !== WXACG_EVENT_DB_VERSION ) {
            Activator::install_event_tables();
        }
        if ( get_option( 'smacg_rank_season_db_version', '0' ) !== WXACG_RANK_SEASON_DB_VERSION ) {
            Activator::install_rank_season_tables();
        }
    }

    public function maybe_flush_rewrite() {
        if ( get_option( 'smacg_event_cpt_flushed', '1' ) === '0' ) {
            flush_rewrite_rules( false );
            update_option( 'smacg_event_cpt_flushed', '1' );
        }
    }
}
