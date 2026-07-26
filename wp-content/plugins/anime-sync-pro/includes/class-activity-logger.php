<?php
/**
 * 檔案名稱: includes/class-activity-logger.php
 * 動畫／漫畫操作紀錄 — 記錄「誰」在「何時」匯入 / 發布 / 更新 / 刪除了哪一篇作品，
 * 並提供給後台頁面使用的統計 / 查詢方法。
 *
 * @package Anime_Sync_Pro
 * @version 1.2.0
 *
 * 使用方式（請在主外掛檔案，跟其他 includes 一起載入 + 初始化）：
 *   require_once ANIME_SYNC_PRO_DIR . 'includes/class-activity-logger.php';
 *   new Anime_Sync_Activity_Logger();
 *
 * 資料表：{$wpdb->prefix}asp_activity_log
 *   id | user_id | user_name | action | post_id | post_title | created_at
 *   action 可能值：imported / published / updated / trashed / deleted
 *
 * Changelog:
 *   1.2.0 — 新增 manga 監聽（POST_TYPE → POST_TYPES 陣列）、
 *           新增 imported 動作獨立統計、修正 get_summary/get_totals
 *           兩個分支都補齊 imported 欄位。
 *   1.1.0 — maybe_upgrade_table() 改為建構子內直接執行，不再掛
 *           plugins_loaded（避免在 plugins_loaded 內才註冊
 *           plugins_loaded 導致錯過本輪 hook、表建立失敗）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Activity_Logger {

    /** 資料表版本，改動資料表結構時提高版本號即會自動 dbDelta */
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'asp_activity_log_db_version';

    /** 監聽的文章類型（★ 1.2.0 新增 manga） */
    const POST_TYPES = [ 'anime', 'manga' ];

    public function __construct() {
        // 資料表建立/升級（用 option 版本號比對，避免每次請求都跑 dbDelta）
        // 直接執行，不再掛 plugins_loaded — 本類別本身就是在 plugins_loaded 內
        // 被 new 出來的，若在此才註冊 plugins_loaded，容易錯過本輪 hook 導致建表失敗。
        $this->maybe_upgrade_table();

        // 監聽事件
        add_action( 'transition_post_status', [ $this, 'log_transition' ], 20, 3 );
        add_action( 'before_delete_post',      [ $this, 'log_delete'     ], 10, 2 );

        // 後台頁面用的 AJAX（清除舊紀錄，比照錯誤日誌頁面）
        add_action( 'wp_ajax_asp_clear_activity_log', [ $this, 'handle_ajax_clear_old_logs' ] );

        // 後台頁面用的 AJAX（一次性補登舊紀錄）
        add_action( 'wp_ajax_asp_backfill_activity_log', [ $this, 'handle_ajax_backfill' ] );
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public function handle_ajax_clear_old_logs(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $days  = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 90;
        $count = $this->delete_old_logs( $days );

        wp_send_json_success( [
            'count'   => $count,
            'days'    => $days,
            'message' => sprintf( '已清除 %d 筆 %d 天前的操作紀錄', $count, $days ),
        ] );
    }

    public function handle_ajax_backfill(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        @set_time_limit( 120 );

        $result = $this->backfill_published();

        wp_send_json_success( [
            'result'  => $result,
            'message' => sprintf(
                '補登完成：新增 %d 筆「發布」紀錄，略過 %d 筆（已有紀錄不重複補），共檢查 %d 篇作品。',
                $result['inserted'], $result['skipped'], $result['total']
            ),
        ] );
    }


    // =========================================================================
    // 資料表
    // =========================================================================

    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'asp_activity_log';
    }

    public function maybe_upgrade_table(): void {
        if ( get_option( self::OPTION_KEY ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table_name      = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_name VARCHAR(191) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL DEFAULT '',
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_title VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::OPTION_KEY, self::DB_VERSION );
    }

    // =========================================================================
    // 寫入
    // =========================================================================

    private function write( string $action, \WP_Post $post ): void {
        self::record( $action, $post );
    }

    /**
     * 靜態寫入方法，供外部類別（例如 Import Manager / Manga Import Manager）
     * 直接呼叫記錄「匯入」動作，不需要 new 一個 Activity_Logger 實例
     * （避免重複註冊 hook 造成重複記錄）。
     */
    public static function record( string $action, \WP_Post $post ): void {
        global $wpdb;

        $user_id   = get_current_user_id();
        $user      = $user_id ? get_userdata( $user_id ) : false;
        $user_name = $user ? $user->display_name : '系統／排程任務';

        $wpdb->insert(
            $wpdb->prefix . 'asp_activity_log',
            [
                'user_id'    => $user_id,
                'user_name'  => $user_name,
                'action'     => $action,
                'post_id'    => $post->ID,
                'post_title' => mb_substr( (string) $post->post_title, 0, 255 ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * 掛在 transition_post_status。
     * 這個 hook 每次 wp_insert_post()／wp_update_post() 都會觸發一次
     * （即使狀態沒變），所以「已發布 → 已發布」剛好可以拿來當作「更新」事件。
     */
    public function log_transition( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post->ID ) ) return;
        if ( $new_status === 'auto-draft' || $new_status === 'inherit' ) return;

        if ( $new_status === 'publish' && $old_status !== 'publish' ) {
            // 草稿／待審 → 發布
            $this->write( 'published', $post );
        } elseif ( $new_status === 'publish' && $old_status === 'publish' ) {
            // 已發布文章再次儲存 = 修改
            $this->write( 'updated', $post );
        } elseif ( $new_status === 'trash' && $old_status !== 'trash' ) {
            $this->write( 'trashed', $post );
        }
    }

    /** 掛在 before_delete_post（永久刪除，含清空垃圾桶） */
    public function log_delete( int $post_id, \WP_Post $post ): void {
        if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) return;
        $this->write( 'deleted', $post );
    }

    // =========================================================================
    // 查詢 — 給後台頁面用
    // =========================================================================

    /** 依使用者彙總（近 N 天，days=0 代表全部時間不限制）：匯入/發布/修改/刪除幾篇 */
    public function get_summary( int $days = 30 ): array {
        global $wpdb;
        $table = $this->table_name();

        if ( $days > 0 ) {
            $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, user_name,
                        SUM(action = 'imported')  AS imported,
                        SUM(action = 'published') AS published,
                        SUM(action = 'updated')   AS updated,
                        SUM(action IN ('trashed','deleted')) AS removed,
                        COUNT(*) AS total
                 FROM {$table}
                 WHERE created_at >= %s
                 GROUP BY user_id, user_name
                 ORDER BY total DESC",
                $since
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results(
                "SELECT user_id, user_name,
                        SUM(action = 'imported')  AS imported,
                        SUM(action = 'published') AS published,
                        SUM(action = 'updated')   AS updated,
                        SUM(action IN ('trashed','deleted')) AS removed,
                        COUNT(*) AS total
                 FROM {$table}
                 GROUP BY user_id, user_name
                 ORDER BY total DESC",
                ARRAY_A
            );
        }

        return $rows ?: [];
    }

    /** 全站總計（近 N 天，days=0 代表全部時間不限制） */
    public function get_totals( int $days = 30 ): array {
        global $wpdb;
        $table = $this->table_name();

        if ( $days > 0 ) {
            $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    SUM(action = 'imported')  AS imported,
                    SUM(action = 'published') AS published,
                    SUM(action = 'updated')   AS updated,
                    SUM(action IN ('trashed','deleted')) AS removed,
                    COUNT(*) AS total
                 FROM {$table}
                 WHERE created_at >= %s",
                $since
            ), ARRAY_A );
        } else {
            $row = $wpdb->get_row(
                "SELECT
                    SUM(action = 'imported')  AS imported,
                    SUM(action = 'published') AS published,
                    SUM(action = 'updated')   AS updated,
                    SUM(action IN ('trashed','deleted')) AS removed,
                    COUNT(*) AS total
                 FROM {$table}",
                ARRAY_A
            );
        }

        return $row ?: [ 'imported' => 0, 'published' => 0, 'updated' => 0, 'removed' => 0, 'total' => 0 ];
    }

    /** 明細列表（可篩選動作類型 / 使用者） */
    public function get_logs( int $limit = 100, string $action = '', int $user_id = 0 ): array {
        global $wpdb;
        $table = $this->table_name();

        $where  = [ '1=1' ];
        $params = [];

        if ( $action !== '' ) {
            $where[]  = 'action = %s';
            $params[] = $action;
        }
        if ( $user_id > 0 ) {
            $where[]  = 'user_id = %d';
            $params[] = $user_id;
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
             . ' ORDER BY created_at DESC LIMIT %d';
        $params[] = $limit;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
    }

    /** 給篩選下拉選單用：曾經出現過的使用者清單 */
    public function get_distinct_users(): array {
        global $wpdb;
        $table = $this->table_name();
        return $wpdb->get_results(
            "SELECT DISTINCT user_id, user_name FROM {$table} WHERE user_id > 0 ORDER BY user_name ASC",
            ARRAY_A
        ) ?: [];
    }

    /** 清除舊紀錄（比照錯誤日誌頁面的「清除 N 天前」設計） */
    public function delete_old_logs( int $days ): int {
        global $wpdb;
        $table  = $this->table_name();
        $before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s", $before
        ) );
    }

    // =========================================================================
    // 一次性補登舊紀錄
    //
    // 用途：這個模組上線之前就已經發布的動畫／漫畫，資料表裡本來沒有任何紀錄。
    // 這個方法會替「目前狀態為 publish」的每一篇作品，用它現有的
    // post_author（作者）+ post_date（發布日期）補一筆 action='published'
    // 的紀錄，讓統計頁至少能看到「歷史上這些作者各發布了幾篇」。
    //
    // 限制（無法補的部分，務必先讓使用者知道）：
    //   - 只能補「發布」這個動作。誰之後又改過幾次、改了什麼，或當初是誰
    //     匯入的，這種歷史本來就沒有留下任何線索，補不出來。
    //   - post_author 是「目前」文章的作者欄位，如果文章曾經轉手（換過
    //     作者）也只會補到現在登記的這個人，不代表當初真的是他發布的。
    //   - 可重複點擊：每篇文章若已經有一筆 published 紀錄就會跳過，
    //     不會重複新增。
    // =========================================================================

    public function backfill_published(): array {
        global $wpdb;
        $table = $this->table_name();

        $post_ids = get_posts( [
            'post_type'      => self::POST_TYPES,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $inserted = 0;
        $skipped  = 0;

        foreach ( $post_ids as $post_id ) {

            $already = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND action = 'published'",
                $post_id
            ) );

            if ( $already > 0 ) {
                $skipped++;
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            $author_id   = (int) $post->post_author;
            $author      = $author_id ? get_userdata( $author_id ) : false;
            $author_name = $author ? $author->display_name : '（未知使用者）';

            $created_at = ( $post->post_date && $post->post_date !== '0000-00-00 00:00:00' )
                ? $post->post_date
                : current_time( 'mysql' );

            $wpdb->insert(
                $table,
                [
                    'user_id'    => $author_id,
                    'user_name'  => $author_name,
                    'action'     => 'published',
                    'post_id'    => $post_id,
                    'post_title' => mb_substr( (string) $post->post_title, 0, 255 ),
                    'created_at' => $created_at,
                ],
                [ '%d', '%s', '%s', '%d', '%s', '%s' ]
            );

            $inserted++;
        }

        update_option( 'asp_activity_log_backfilled_at', current_time( 'mysql' ) );

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'total'    => count( $post_ids ),
        ];
    }
}