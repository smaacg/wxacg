<?php
/**
 * 檔案名稱: includes/class-rate-limiter.php
 *
 * @version 1.3.0
 *
 * Changelog:
 *   1.3.0 — [Fix MAL 官方 API 無節流] $limits 新增 'mal' => 1000。
 *           原本 MAL 官方 API v2（api.myanimelist.net）在 api-handler
 *           裡是以 wait_if_needed('jikan') 節流，但 'jikan' 與 'mal'
 *           語意混用；且若呼叫端傳入未登記的 api_name，wait_if_needed()
 *           會直接 return（無任何等待）造成密集請求被 MAL 短暫限流。
 *           新增獨立 'mal' key（1 req/s，符合 MAL 官方建議），供
 *           api-handler 的 fetch_mal_score() / fetch_jikan_theme_natives()
 *           改用 wait_if_needed('mal')。保留 'jikan' key 以相容舊呼叫。
 *
 *   1.2.0 — [Optimize] Cron 精度補強
 *           - [新增] 靜態屬性 $last_request_ms：在同一次 cron 執行內以
 *             PHP 記憶體記錄最後請求時間，優先於 Transient 使用。
 *             原本純靠 Transient 計時在無 Object Cache 環境下每次讀寫
 *             都是 DB 查詢（延遲 5~20ms），計算出的 elapsed 偏低，
 *             導致等待時間比預期長，批次速度不穩定。
 *             靜態變數在同一 PHP 行程中精度為微秒級，Transient
 *             僅保留作跨 cron 執行的狀態橋接。
 *
 *   1.1.1 — 修正靜態呼叫 Error_Logger::log() 的 deprecated 警告
 *           - [變更] handle_rate_limit_error() 改用 Error_Logger::warning()
 *           - [變更] check_remaining() 改用 Error_Logger::warning()
 *
 *   1.1.0 — singleton + Retry-After 整合 + API 統計
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Rate_Limiter {

    private static ?self $instance = null;

    private const STATS_OPTION = 'anime_sync_api_stats';

    /**
     * ✅ [v1.2.0] 同一 PHP 行程內的最後請求毫秒時間戳（靜態，跨呼叫保留）。
     * 優先於 Transient 使用，確保同一次 cron 執行內的限速精度為微秒級。
     *
     * @var array<string, int>
     */
    private static array $last_request_ms = [];

    private array $limits = [
        'anilist'     => 2000,
        'jikan'       => 1200,
        'mal'         => 1000,  // ✅ [v1.3.0] MAL 官方 API v2：1 req/s（官方建議）
        'bangumi'     => 1000,
        'animethemes' => 700,
        'deepl'       => 500,   // 角色/聲優簡介翻譯，避免批次回補時打太快
    ];

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function __clone() {}

    public function __wakeup(): void {
        throw new \Exception( 'Cannot unserialize singleton Anime_Sync_Rate_Limiter' );
    }

    // -------------------------------------------------------------------------
    // 速率控制
    // -------------------------------------------------------------------------

    /**
     * ✅ [v1.2.0] 優先讀取靜態記憶體中的時間（同一 cron 執行內精度最高），
     * Transient 僅用於跨次 cron 的狀態橋接。
     */
    public function wait_if_needed( string $api_name ): void {
        $api_name = strtolower( $api_name );

        if ( ! isset( $this->limits[ $api_name ] ) ) {
            return;
        }

        $interval    = $this->limits[ $api_name ];
        $transient_key = 'anime_sync_last_request_' . $api_name;

        // ✅ 優先用靜態變數（毫秒精度，無 DB 延遲）；
        //    靜態變數不存在才 fallback 到 Transient（跨 cron 橋接）。
        $last_ms = self::$last_request_ms[ $api_name ]
            ?? ( (int) get_transient( $transient_key ) ?: 0 );

        if ( $last_ms > 0 ) {
            $now_ms  = (int) round( microtime( true ) * 1000 );
            $elapsed = $now_ms - $last_ms;

            if ( $elapsed < $interval ) {
                usleep( ( $interval - $elapsed ) * 1000 );
            }
        }

        // ✅ 同時更新靜態變數與 Transient，確保跨次 cron 也能銜接。
        $now_after = (int) round( microtime( true ) * 1000 );
        self::$last_request_ms[ $api_name ] = $now_after;
        set_transient( $transient_key, $now_after, 60 );
    }

    // -------------------------------------------------------------------------
    // 429 處理
    // -------------------------------------------------------------------------

    /**
     * 解析 429 回應並回傳建議等待秒數（5~300）
     */
    public function handle_rate_limit_error( $response, string $api_name ): int {
        $retry_after = 60;

        if ( is_array( $response ) && isset( $response['headers'] ) ) {
            $headers = $response['headers'];
        } elseif ( is_array( $response ) && (
            isset( $response['Retry-After'] ) ||
            isset( $response['retry-after'] )
        ) ) {
            $headers = $response;
        } else {
            $headers = wp_remote_retrieve_headers( $response );
        }

        if ( ! empty( $headers['retry-after'] ) ) {
            $retry_after = (int) $headers['retry-after'];
        } elseif ( ! empty( $headers['Retry-After'] ) ) {
            $retry_after = (int) $headers['Retry-After'];
        } elseif ( ! empty( $headers['x-ratelimit-reset'] ) ) {
            $retry_after = max( 1, (int) $headers['x-ratelimit-reset'] - time() );
        } elseif ( ! empty( $headers['X-RateLimit-Reset'] ) ) {
            $retry_after = max( 1, (int) $headers['X-RateLimit-Reset'] - time() );
        }

        $retry_after = max( 5, min( 300, $retry_after ) );

        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::warning( sprintf(
                '%s API rate limited, suggested wait %d seconds',
                ucfirst( $api_name ),
                $retry_after
            ), [
                'api'         => $api_name,
                'retry_after' => $retry_after,
            ] );
        }

        return $retry_after;
    }

    /**
     * 檢查剩餘配額（< 10% 警告並 sleep 5 秒）
     */
    public function check_remaining( $response, string $api_name ): void {
        if ( is_array( $response ) && isset( $response['headers'] ) ) {
            $headers = $response['headers'];
        } else {
            $headers = wp_remote_retrieve_headers( $response );
        }

        $remaining_raw = $headers['x-ratelimit-remaining'] ?? $headers['X-RateLimit-Remaining'] ?? null;
        if ( $remaining_raw === null ) return;

        $remaining = (int) $remaining_raw;
        $limit_raw = $headers['x-ratelimit-limit'] ?? $headers['X-RateLimit-Limit'] ?? 90;
        $limit     = max( 1, (int) $limit_raw );

        $percentage = ( $remaining / $limit ) * 100;

        if ( $percentage < 10 ) {
            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::warning( sprintf(
                    '%s API quota low: %d/%d remaining (%.1f%%)',
                    ucfirst( $api_name ),
                    $remaining,
                    $limit,
                    $percentage
                ), [
                    'api'        => $api_name,
                    'remaining'  => $remaining,
                    'limit'      => $limit,
                    'percentage' => $percentage,
                ] );
            }
            sleep( 5 );
        }
    }

    // -------------------------------------------------------------------------
    // API 呼叫統計
    // -------------------------------------------------------------------------

    public function record_stat( string $api, string $type ): void {
        $api  = strtolower( $api );
        $type = strtolower( $type );

        $valid_types = [ 'success', 'failed', 'rate_limited', 'retry' ];
        if ( ! in_array( $type, $valid_types, true ) ) {
            return;
        }

        $stats = get_option( self::STATS_OPTION, [] );
        if ( ! is_array( $stats ) ) {
            $stats = [];
        }

        if ( ! isset( $stats[ $api ] ) ) {
            $stats[ $api ] = [
                'success'      => 0,
                'failed'       => 0,
                'rate_limited' => 0,
                'retry'        => 0,
                'last_updated' => '',
            ];
        }

        $stats[ $api ][ $type ]++;
        $stats[ $api ]['last_updated'] = current_time( 'mysql' );

        update_option( self::STATS_OPTION, $stats, false );
    }

    public function get_stats(): array {
        $stats = get_option( self::STATS_OPTION, [] );
        return is_array( $stats ) ? $stats : [];
    }

    public function reset_stats(): void {
        delete_option( self::STATS_OPTION );
    }
}
