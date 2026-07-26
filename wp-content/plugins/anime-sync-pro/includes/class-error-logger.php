<?php
/**
 * 檔案名稱: includes/class-error-logger.php
 * 版本: 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Error_Logger {

    const LEVEL_INFO     = 'info';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_ERROR    = 'error';
    const LEVEL_CRITICAL = 'critical';

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'anime_sync_logs';
    }

    // =========================================================================
    // INSTANCE – 寫入日誌
    // =========================================================================

    public function log( string $level, string $message, array $context = [] ): void {
        $allowed = [ self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL ];
        if ( ! in_array( $level, $allowed, true ) ) {
            $level = self::LEVEL_INFO;
        }

        $this->wpdb->insert(
            $this->table,
            [
                'level'      => $level,
                'message'    => mb_substr( $message, 0, 1000 ),
                'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        if ( $level === self::LEVEL_CRITICAL ) {
            $this->send_critical_email( $message, $context );
        }
    }

    // =========================================================================
    // INSTANCE – 查詢
    // =========================================================================

    public function get_recent_logs( int $limit = 100, ?string $level = null ): array {
        $level = $level ?? '';  // null → 空字串，查全部

        if ( $level !== '' ) {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM `{$this->table}` WHERE level = %s ORDER BY id DESC LIMIT %d",
                    $level, $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM `{$this->table}` ORDER BY id DESC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        }

        if ( ! $rows ) return [];

        foreach ( $rows as &$row ) {
            $row['context'] = json_decode( $row['context'] ?? '{}', true ) ?: [];
        }
        unset( $row );

        return $rows;
    }

    public function delete_old_logs( int $days = 30 ): int {
        if ( $days <= 0 ) {
            $this->wpdb->query( "TRUNCATE TABLE `{$this->table}`" );
            return -1;
        }
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        return (int) $this->wpdb->rows_affected;
    }

    public function get_statistics( int $days = 7 ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT level, COUNT(*) AS cnt
                 FROM `{$this->table}`
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY level",
                $days
            ),
            ARRAY_A
        );

        $stats = [
            'total'    => 0,
            'info'     => 0,
            'warning'  => 0,
            'error'    => 0,
            'critical' => 0,
        ];

        foreach ( $rows ?: [] as $row ) {
            $lvl = $row['level'] ?? '';
            $cnt = (int) ( $row['cnt'] ?? 0 );
            if ( isset( $stats[ $lvl ] ) ) {
                $stats[ $lvl ] = $cnt;
                $stats['total'] += $cnt;
            }
        }

        return $stats;
    }

    // =========================================================================
    // PRIVATE – 寄送 Critical 通知
    // =========================================================================

    private function send_critical_email( string $message, array $context ): void {
        if ( ! get_option( 'anime_sync_log_email_notify', false ) ) {
            return;
        }
        $to      = get_option( 'admin_email' );
        $subject = '[Anime Sync Pro] Critical Error';
        $body    = "Message: {$message}\n\nContext:\n" . print_r( $context, true );
        wp_mail( $to, $subject, $body );
    }

    // =========================================================================
    // STATIC helpers
    // =========================================================================

    /**
     * 靜態快捷方法，供外部檔案直接呼叫。
     * 用法：Anime_Sync_Error_Logger::static_log( 'warning', '訊息', [] );
     */
    public static function static_log( string $level, string $message, array $context = [] ): void {
        ( new self() )->log( $level, $message, $context );
    }

    public static function info( string $message, array $context = [] ): void {
        ( new self() )->log( self::LEVEL_INFO, $message, $context );
    }

    public static function warning( string $message, array $context = [] ): void {
        ( new self() )->log( self::LEVEL_WARNING, $message, $context );
    }

    public static function error( string $message, array $context = [] ): void {
        ( new self() )->log( self::LEVEL_ERROR, $message, $context );
    }

    public static function critical( string $message, array $context = [] ): void {
        ( new self() )->log( self::LEVEL_CRITICAL, $message, $context );
    }

    /**
     * 舊版相容：靜態清除過期日誌。
     */
    public static function clear_old_logs( int $days = 30 ): int {
        return ( new self() )->delete_old_logs( $days );
    }
}
