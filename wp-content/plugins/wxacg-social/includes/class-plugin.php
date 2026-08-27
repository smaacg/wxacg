<?php
/**
 * SMACG Social — Plugin Bootstrap
 *
 * @package    weixiaoacg
 * @subpackage wxacg-social
 * @version    1.4.3
 * @since      1.0.0
 *
 * Changelog:
 * - 1.4.3 (2026-07-26)
 *   * 新增載入 legacy/corrections-notify.php（會員資料回報系統 — 通知 + 經驗值）。
 *     放在 is_admin() 區塊「外面」，因前後台皆需載入：
 *       - 前後台都要掛 add_filter('smacg_exp_rules') 注入 EXP 規則
 *       - 監聽 wxacg_correction_resolved / wxacg_correction_rejected
 *     修正先前漏 require 導致通知/經驗值/exp_awarded 皆未觸發的問題。
 * - 1.4.1 (2026-07-26)
 *   * 新增載入 legacy/corrections-form.php（會員資料回報系統 — 前台表單 + AJAX）。
 *     載入順序緊接在 corrections-cpt.php 之後，因為表單依賴：
 *       - Corrections_CPT::CATEGORIES / ::POST_TYPE / ::category_label()
 *     此檔僅註冊 shortcode 與 AJAX hook，無 class 需實例化，故單純 require 即可。
 * - 1.4.0 (2026-07-26)
 *   * 新增載入 legacy/corrections-cpt.php（會員資料回報系統 — CPT）。
 *     載入順序排在 announcement-notifier.php 之後（所有 notifications 模組載入完之後），
 *     因為後續 corrections-notify 會依賴：
 *       - wxacg_create_notification()   (notifications-system.php)
 *       - smacg_trigger_exp_event()     (wxacg-gamification plugin)
 * - 1.3.0 (2026-05-26)
 *   * 新增載入 legacy/announcement-notifier.php（公告自動廣播 + 前台橫幅）。
 *     載入順序排在 notifications-email.php 之後，因為依賴：
 *       - smacg_broadcast_system_notification()  (notifications-events.php)
 *       - has_category() / get_permalink() (WP core)
 * - 1.2.0 (2026-05-16)
 *   * 新增載入 legacy/followers-page.php（粉絲 / 追蹤中子頁）。
 *     載入順序排在 public-profile-render.php 之後，因為依賴：
 *       - wxacg_get_public_profile_url()  (public-profile.php)
 *       - wxacg_is_following()            (follow-system.php)
 *       - smacg_get_user_privacy()        (wxacg-members plugin)
 * - 1.1.0
 *   * 加入 notifications 系列模組與 public-profile 模組。
 * - 1.0.0
 *   * 初始版本：follow-system + follow-ajax。
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
            self::$instance->boot();
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}

    /**
     * 載入所有 legacy 檔案
     *
     * 載入順序很重要：
     *   1. follow-system          — 提供 wxacg_follow_user / wxacg_is_following 等核心 API
     *   2. follow-ajax            — 依賴 follow-system
     *   3. notifications-system   — 提供 wxacg_create_notification 核心 API
     *   4. notifications-events   — 監聽事件，會呼叫 wxacg_create_notification；
     *                                同時提供 smacg_broadcast_system_notification()
     *   5. notifications-ajax     — 通知 AJAX 端點
     *   6. notifications-render   — 鈴鐺 UI
     *   7. notifications-email    — Email 寄送
     *   8. public-profile         — 提供 wxacg_get_public_profile_url 給 notifications-events 使用
     *   9. public-profile-render  — 公開檔案頁面（會呼叫 follow + notifications API）
     *  10. followers-page         — 粉絲 / 追蹤中子頁（v1.2.0 新增，依賴 1, 8, 9）
     *  11. announcement-notifier  — 公告廣播 + 橫幅（v1.3.0 新增，依賴 4）
     *  12. corrections-cpt        — 會員資料回報 CPT（v1.4.0 新增）
     *  13. corrections-form       — 前台回報表單 + AJAX（v1.4.1 新增，依賴 12）
     *  14. corrections-admin      — 後台審核介面（v1.4.2 新增，僅後台）
     *  15. corrections-notify     — 通知 + 經驗值（v1.4.3 新增，前後台皆載入，依賴 3, 12）
     */
    private function boot(): void {
        $legacy = WXACG_SOCIAL_DIR . 'includes/legacy/';

        require_once $legacy . 'follow-system.php';
        require_once $legacy . 'follow-ajax.php';

        // 通知類型登錄表：必須在 notifications-system 之前，
        // 後者的 smacg_get_notification_prefs_defaults() 直接讀它。
        require_once $legacy . 'notification-types.php';

        require_once $legacy . 'notifications-system.php';
        require_once $legacy . 'notifications-events.php';
        require_once $legacy . 'notifications-ajax.php';
        require_once $legacy . 'notifications-render.php';
        require_once $legacy . 'notifications-email.php';

        require_once $legacy . 'public-profile.php';
        require_once $legacy . 'public-profile-render.php';

        // 會員自訂動漫排行（可命名、可分享）
        require_once $legacy . 'toplist-data.php';
        require_once $legacy . 'toplist-render.php';
        require_once $legacy . 'toplist-editor.php';
        require_once $legacy . 'toplist-ajax.php';
        require_once $legacy . 'toplist-card.php';
        require_once $legacy . 'toplist-views.php';
        require_once $legacy . 'toplist-gamify.php';

        // v1.2.0：粉絲 / 追蹤中子頁
        require_once $legacy . 'followers-page.php';

        // v1.3.0：公告通知（依賴 notifications-events.php 的 broadcast helper）
        require_once $legacy . 'announcement-notifier.php';

        // v1.4.0 / v1.4.1：會員資料回報系統（CPT + 前台表單）
        require_once $legacy . 'corrections-cpt.php';
        Corrections_CPT::instance();
        require_once $legacy . 'corrections-form.php';

        // v1.4.2：後台審核介面（僅後台載入）
        if ( is_admin() ) {
            require_once $legacy . 'corrections-admin.php';
            Corrections_Admin::instance();
        }

        // v1.4.3：通知 + 經驗值（前後台皆載入，以確保 EXP 規則 filter 掛上 + hook 監聽）
        require_once $legacy . 'corrections-notify.php';

        // 記錄版本
        $stored = get_option( 'wxacg_social_version' );
        if ( $stored !== WXACG_SOCIAL_VERSION ) {
            update_option( 'wxacg_social_version', WXACG_SOCIAL_VERSION );
        }
    }
}
