<?php
/**
 * Performance Optimizer
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Performance {

    /**
     * 批次更新 post meta（停用 ACF 過濾器以提升效能）。
     *
     * @param int   $post_id WordPress 文章 ID。
     * @param array $fields  欄位陣列 [ meta_key => value ]。
     * @return void
     */
    public static function batch_update_acf( int $post_id, array $fields ): void {
        // ✅ 修正：加上 function_exists 保護，ACF Free 沒有這兩個函式
        if ( function_exists( 'acf_disable_filters' ) ) {
            acf_disable_filters();
        }

        foreach ( $fields as $field_name => $value ) {
            update_post_meta( $post_id, $field_name, $value );
        }

        if ( function_exists( 'acf_enable_filters' ) ) {
            acf_enable_filters();
        }
    }

    /**
     * 清理記憶體：只清除文章相關快取群組，不影響其他外掛。
     *
     * @return void
     */
    public static function clear_memory(): void {
        // ✅ 修正：改為只清除必要群組，避免影響整站快取
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'posts' );
            wp_cache_flush_group( 'post_meta' );
            wp_cache_flush_group( 'terms' );
        } else {
            // WordPress < 6.1 fallback
            wp_cache_flush();
        }

        // 強制 PHP 垃圾回收
        if ( function_exists( 'gc_collect_cycles' ) ) {
            gc_collect_cycles();
        }
    }

    /**
     * 分批處理項目，每批結束後清理記憶體。
     *
     * @param array    $items      待處理項目陣列。
     * @param callable $callback   處理函式，接收單一項目。
     * @param int      $batch_size 每批數量。
     * @return array               所有處理結果。
     */
    public static function batch_process( array $items, callable $callback, int $batch_size = 15 ): array {
        $results = [];
        $batches = array_chunk( $items, $batch_size );

        foreach ( $batches as $batch ) {
            foreach ( $batch as $item ) {
                $results[] = call_user_func( $callback, $item );
            }

            self::clear_memory();

            // 批次間短暫延遲，避免過載
            usleep( 100000 ); // 100ms
        }

        return $results;
    }

    /**
     * 直接從資料庫讀取審核佇列（繞過 WP_Query overhead）。
     *
     * @param int    $limit  最多返回幾筆。
     * @param string $status 狀態篩選。
     * @return array
     */
    public static function get_queue_items_raw( int $limit = 20, string $status = 'pending' ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'anime_review_queue';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, anilist_id, title, status, source, created_at
                 FROM {$table}
                 WHERE status = %s
                 ORDER BY created_at DESC
                 LIMIT %d",
                $status,
                absint( $limit )
            ),
            ARRAY_A
        );
    }

    /**
     * 壓縮 JSON 資料（用於審核佇列儲存）。
     *
     * @param array $data 待壓縮的資料陣列。
     * @return string     壓縮後的二進位字串。
     */
    public static function compress_json( array $data ): string {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        return gzcompress( $json, 9 );
    }

    /**
     * 解壓縮 JSON 資料。
     *
     * @param string $compressed 壓縮後的二進位字串。
     * @return array|false       解壓縮後的陣列，失敗返回 false。
     */
    public static function decompress_json( string $compressed ): array|false {
        $json = gzuncompress( $compressed );
        if ( false === $json ) {
            return false;
        }
        return json_decode( $json, true );
    }

    /**
     * 設定 PHP 執行時間上限。
     *
     * @param int $seconds 秒數。
     * @return void
     */
    public static function set_time_limit( int $seconds = 60 ): void {
        // ✅ 修正：移除 PHP 8.0 已廢棄的 safe_mode 檢查
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( $seconds );
        }
    }

    /**
     * 提高 PHP 記憶體上限。
     *
     * @param string $limit 記憶體限制（例：'512M'）。
     * @return void
     */
    public static function increase_memory_limit( string $limit = '512M' ): void {
        if ( function_exists( 'ini_set' ) ) {
            @ini_set( 'memory_limit', $limit );
        }
    }

    /**
     * 取得目前 PHP 記憶體使用狀況。
     *
     * @return array { current, peak, limit }
     */
    public static function get_memory_usage(): array {
        return [
            'current' => size_format( memory_get_usage( true ) ),
            'peak'    => size_format( memory_get_peak_usage( true ) ),
            'limit'   => ini_get( 'memory_limit' ),
        ];
    }

    /**
     * 寫入快取（Transient）。
     *
     * @param string $key        快取鍵名。
     * @param mixed  $data       快取資料。
     * @param int    $expiration 有效秒數（預設 1 小時）。
     * @return void
     */
    public static function cache_set( string $key, mixed $data, int $expiration = 3600 ): void {
        set_transient( 'anime_sync_cache_' . $key, $data, $expiration );
    }

    /**
     * 讀取快取（Transient）。
     *
     * @param string $key 快取鍵名。
     * @return mixed|false 快取資料，不存在返回 false。
     */
    public static function cache_get( string $key ): mixed {
        return get_transient( 'anime_sync_cache_' . $key );
    }

    /**
     * 清除所有插件快取（Transient）。
     *
     * @return void
     */
    public static function clear_all_caches(): void {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_anime_sync_cache_%'
                OR option_name LIKE '_transient_timeout_anime_sync_cache_%'"
        );

        self::clear_memory();
    }
}
