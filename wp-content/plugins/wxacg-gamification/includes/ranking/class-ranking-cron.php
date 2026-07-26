<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Ranking cron + admin bar 重算按鈕（搬自 theme/inc/ranking-cron.php）
 *
 * v1.1.0 (2026-05-20)
 *   - Fix #15-2 / #15-4：on_recalc() 加上 transient lock，
 *     避免兩次 cron(或手動 rebuild) 並發執行 rebuild_all()
 *     造成 wp_smacg_rankings 表 PRIMARY KEY 衝突 / DB 浪費
 *
 *     鎖機制：
 *       - 取得鎖 → set_transient('smacg_ranking_rebuild_lock', 1, 10min)
 *       - 釋放鎖 → delete_transient(...) 於 finally
 *       - 鎖存在 → 直接 return（下一次 cron 會再試）
 *       - 鎖預設 10 分鐘自動到期（防止異常 die 之後永久鎖住）
 */
class Ranking_Cron {

    private static $instance = null;

    /** Transient lock key */
    const LOCK_KEY = 'smacg_ranking_rebuild_lock';

    /** Lock TTL (秒)：10 分鐘 — 預設 cron 每小時跑一次，10 分鐘足夠 rebuild 完 */
    const LOCK_TTL = 600;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'smacg_ranking_recalc',             [ __CLASS__, 'on_recalc' ] );
        add_action( 'smacg_ranking_monthly_purge',      [ __CLASS__, 'on_purge' ] );
        add_action( 'admin_bar_menu',                   [ __CLASS__, 'admin_bar_button' ], 100 );
        add_action( 'admin_post_smacg_ranking_rebuild', [ __CLASS__, 'handle_manual_rebuild' ] );
        add_action( 'admin_notices',                    [ __CLASS__, 'maybe_show_rebuild_notice' ] );
    }

    /**
     * ★ Fix #15-2/#15-4：包 transient lock 防止重疊執行
     */
    public static function on_recalc() {
        // 取得鎖（NX 語意：transient 不存在才寫入）
        if ( get_transient( self::LOCK_KEY ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SMACG] Ranking recalc skipped: another job is running' );
            }
            return;
        }
        set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

        try {
            Ranking_System::rebuild_all();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SMACG] Ranking auto-recalc complete at ' . current_time( 'mysql' ) );
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SMACG] Ranking recalc exception: ' . $e->getMessage() );
            }
        } finally {
            delete_transient( self::LOCK_KEY );
        }
    }

    public static function on_purge() {
        $n = Ranking_System::purge_old_monthly( 13 );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[SMACG] Monthly EXP purge: deleted $n rows" );
        }
    }

    public static function admin_bar_button( $bar ) {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=smacg_ranking_rebuild' ), 'smacg_ranking_rebuild' );
        $bar->add_node( [
            'id'    => 'smacg-ranking-rebuild',
            'title' => '🏆 重算排行榜',
            'href'  => $url,
            'meta'  => [ 'title' => '立即重新計算所有排行榜' ],
        ] );
    }

    /**
     * 手動重算：同樣套用鎖機制，避免管理員連點兩次按鈕
     */
    public static function handle_manual_rebuild() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '無權限', 403 );
        check_admin_referer( 'smacg_ranking_rebuild' );

        if ( get_transient( self::LOCK_KEY ) ) {
            set_transient(
                'smacg_ranking_rebuild_msg',
                '⏳ 排行榜正在重算中，請稍候再試（最多 10 分鐘）',
                60
            );
            wp_safe_redirect( wp_get_referer() ?: admin_url() );
            exit;
        }
        set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

        try {
            $count = Ranking_System::rebuild_all();
            set_transient( 'smacg_ranking_rebuild_msg', sprintf( '✅ 已重新計算 %d 筆排行榜資料', $count ), 60 );
        } catch ( \Throwable $e ) {
            set_transient( 'smacg_ranking_rebuild_msg', '❌ 重算失敗：' . $e->getMessage(), 60 );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SMACG] Manual rebuild exception: ' . $e->getMessage() );
            }
        } finally {
            delete_transient( self::LOCK_KEY );
        }

        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    public static function maybe_show_rebuild_notice() {
        $msg = get_transient( 'smacg_ranking_rebuild_msg' );
        if ( ! $msg ) return;
        delete_transient( 'smacg_ranking_rebuild_msg' );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }
}
