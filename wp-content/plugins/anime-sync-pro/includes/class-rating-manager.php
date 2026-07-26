<?php
/**
 * Rating Manager Class
 * 負責 WeixiaoACG+ 評分系統的核心邏輯、資料庫讀寫、REST API
 *
 * Bug fixes（沿用 v1.1）：
 *   #1 加權平均：SUM(score * weight) / SUM(weight)
 *   #2 get_user_weight() null check
 *   #3 評分頻率：每分鐘最多 5 次
 *   #4 全站平均 10 分鐘 transient 快取
 *   #5 wpdb 寫入失敗時記錄並回退 rate count
 *   #7 update_post_meta_scores() 回傳 stats
 *   #8 排行榜撈 3 倍後 PHP 重新依 weighted 排序
 *
 * v1.2.0 優化：
 *   #A 排行榜 5 分鐘 transient 快取（key 區分 limit）
 *   #B 提交評分時統一清除排行榜 + 全站平均快取
 *   #C get_stats() 分布查詢合併進主查詢（少一次 SQL round-trip）
 *   #D get_user_weight() 加 request scope 快取
 *
 * v1.2.1 (2026-05-20)：
 *   - Feat：首次評分時觸發 do_action('smacg_rating_added', $user_id, $anime_id)
 *
 * v1.3.0 (2026-05-27)：
 *   - Feat：/ranking/site 加 rank_type 參數（rating / favorites / views）
 *
 * v1.4.0 (2026-07-17)：★漫畫支援
 *   - Feat：api_submit_rating() 允許 post_type = anime 或 manga（同表 anime_id 欄位共用）
 *   - Feat：/ranking/site 加 post_type 參數（預設 anime，可選 manga），
 *           讓動漫榜與漫畫榜「完全分開」——三個 build 函式皆依 post_type 過濾，
 *           且快取 key 帶入 post_type，兩者互不干擾。
 *   - Note：get_stats / get_user_rating / 提交寫入皆與 post_type 無關（本來就通用），
 *           唯排行榜組裝需要過濾，故只在三個 build 函式加過濾。
 *
 * v1.5.0 (2026-07-24)：★刪除評分
 *   - Feat：新增 DELETE /ratings/{id} 端點與 api_delete_rating()，
 *           讓登入使用者可刪除自己對某部作品的評分。刪除後沿用
 *           flush_caches_after_write() + update_post_meta_scores() 重算平均。
 *           刪到 0 人時 get_stats() 回 score => null，前端顯示自動變「—」。
 *   - Note：僅新增，未更動任何既有方法。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Rating_Manager {

    private string $table;

    private int $min_votes = 5;

    private const RATE_LIMIT_MAX    = 5;
    private const RATE_LIMIT_PERIOD = MINUTE_IN_SECONDS;

    private const GLOBAL_AVG_CACHE_TTL = 10 * MINUTE_IN_SECONDS;

    private const RANKING_FETCH_MULTIPLIER = 3;

    private const RANKING_CACHE_TTL    = 5 * MINUTE_IN_SECONDS;
    private const RANKING_CACHE_PREFIX = 'asp_ranking_site_';

    private const FAVORITES_CACHE_TTL = 60 * MINUTE_IN_SECONDS;
    private const VIEWS_CACHE_TTL     = 30 * MINUTE_IN_SECONDS;

    private const MIN_COUNT_FAVORITES = 3;
    private const MIN_COUNT_VIEWS     = 10;

    /**
     * v1.4.0：排行榜允許的 post_type
     */
    private const ALLOWED_POST_TYPES = [ 'anime', 'manga' ];

    private static array $user_weight_cache = [];

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'anime_ratings';
        $this->register_rest_routes();
    }

    // ================================================================
    // REST API 路由註冊
    // ================================================================

    private function register_rest_routes(): void {
        add_action( 'rest_api_init', function () {

            register_rest_route( 'weixiaoacg/v1', '/ratings/(?P<anime_id>\d+)', [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'api_get_ratings' ],
                    'permission_callback' => '__return_true',
                    'args'                => [
                        'anime_id' => [
                            'required'          => true,
                            'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                        ],
                    ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'api_submit_rating' ],
                    'permission_callback' => [ $this, 'require_login' ],
                    'args'                => [
                        'anime_id' => [
                            'required'          => true,
                            'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                        ],
                        'score_story' => [
                            'required'          => true,
                            'validate_callback' => [ $this, 'validate_score' ],
                            'sanitize_callback' => fn( $v ) => round( (float) $v, 1 ),
                        ],
                        'score_music' => [
                            'required'          => true,
                            'validate_callback' => [ $this, 'validate_score' ],
                            'sanitize_callback' => fn( $v ) => round( (float) $v, 1 ),
                        ],
                        'score_animation' => [
                            'required'          => true,
                            'validate_callback' => [ $this, 'validate_score' ],
                            'sanitize_callback' => fn( $v ) => round( (float) $v, 1 ),
                        ],
                        'score_voice' => [
                            'required'          => true,
                            'validate_callback' => [ $this, 'validate_score' ],
                            'sanitize_callback' => fn( $v ) => round( (float) $v, 1 ),
                        ],
                    ],
                ],
                // v1.5.0：刪除目前使用者對此作品的評分
                [
                    'methods'             => 'DELETE',
                    'callback'            => [ $this, 'api_delete_rating' ],
                    'permission_callback' => [ $this, 'require_login' ],
                    'args'                => [
                        'anime_id' => [
                            'required'          => true,
                            'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                        ],
                    ],
                ],
            ] );

            // v1.3.0 + v1.4.0：ranking/site，加入 rank_type / period / min_count / post_type
            register_rest_route( 'weixiaoacg/v1', '/ranking/site', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'api_get_site_ranking' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'limit' => [
                        'default'           => 20,
                        'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 50,
                        'sanitize_callback' => fn( $v ) => (int) $v,
                    ],
                    'rank_type' => [
                        'default'           => 'rating',
                        'validate_callback' => fn( $v ) => in_array( $v, [ 'rating', 'favorites', 'views' ], true ),
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'period' => [
                        'default'           => 'monthly',
                        'validate_callback' => fn( $v ) => in_array( $v, [ 'daily', 'weekly', 'monthly', 'yearly' ], true ),
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'min_count' => [
                        'default'           => 0,
                        'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= 0,
                        'sanitize_callback' => fn( $v ) => (int) $v,
                    ],
                    // v1.4.0：動漫榜 / 漫畫榜分流
                    'post_type' => [
                        'default'           => 'anime',
                        'validate_callback' => fn( $v ) => in_array( $v, self::ALLOWED_POST_TYPES, true ),
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ] );

        } );
    }

    // ================================================================
    // Permission Callbacks
    // ================================================================

    public function require_login( WP_REST_Request $request ): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'rest_forbidden',
                '請先登入才能評分',
                [ 'status' => 401 ]
            );
        }
        return true;
    }

    public function validate_score( $value ): bool {
        $v = (float) $value;
        return $v >= 1.0 && $v <= 10.0;
    }

    // ================================================================
    // API：取得評分統計 + 目前使用者的評分
    // ================================================================

    public function api_get_ratings( WP_REST_Request $request ): WP_REST_Response {
        $anime_id = (int) $request->get_param( 'anime_id' );
        $stats    = $this->get_stats( $anime_id );
        $my_score = null;

        if ( is_user_logged_in() ) {
            $my_score = $this->get_user_rating( $anime_id, get_current_user_id() );
        }

        return rest_ensure_response( [
            'stats'    => $stats,
            'my_score' => $my_score,
        ] );
    }

    // ================================================================
    // API：提交 / 修改評分
    // ================================================================

    public function api_submit_rating( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $anime_id    = (int) $request->get_param( 'anime_id' );
        $user_id     = get_current_user_id();
        $score_story = (float) $request->get_param( 'score_story' );
        $score_music = (float) $request->get_param( 'score_music' );
        $score_anim  = (float) $request->get_param( 'score_animation' );
        $score_voice = (float) $request->get_param( 'score_voice' );

        // v1.4.0：允許 anime 或 manga（同表共用 anime_id 欄位）
        if ( ! in_array( get_post_type( $anime_id ), self::ALLOWED_POST_TYPES, true ) ) {
            return new WP_Error( 'invalid_target', '找不到此動畫或漫畫', [ 'status' => 404 ] );
        }

        // Bug #3：防刷頻率限制
        $rate_key   = 'asp_rate_user_' . $user_id;
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= self::RATE_LIMIT_MAX ) {
            return new WP_Error(
                'rate_limited',
                '評分過於頻繁，請稍候 1 分鐘後再試',
                [ 'status' => 429 ]
            );
        }
        set_transient( $rate_key, $rate_count + 1, self::RATE_LIMIT_PERIOD );

        $score_overall = round( ( $score_story + $score_music + $score_anim + $score_voice ) / 4, 2 );
        $weight        = $this->get_user_weight( $user_id );

        global $wpdb;
        $now      = current_time( 'mysql' );
        $existing = $this->get_user_rating( $anime_id, $user_id );

        if ( $existing ) {
            $result = $wpdb->update(
                $this->table,
                [
                    'score_story'     => $score_story,
                    'score_music'     => $score_music,
                    'score_animation' => $score_anim,
                    'score_voice'     => $score_voice,
                    'score_overall'   => $score_overall,
                    'weight'          => $weight,
                    'updated_at'      => $now,
                ],
                [ 'anime_id' => $anime_id, 'user_id' => $user_id ],
                [ '%f', '%f', '%f', '%f', '%f', '%f', '%s' ],
                [ '%d', '%d' ]
            );
        } else {
            $result = $wpdb->insert(
                $this->table,
                [
                    'anime_id'        => $anime_id,
                    'user_id'         => $user_id,
                    'score_story'     => $score_story,
                    'score_music'     => $score_music,
                    'score_animation' => $score_anim,
                    'score_voice'     => $score_voice,
                    'score_overall'   => $score_overall,
                    'weight'          => $weight,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ],
                [ '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s' ]
            );
        }

        if ( $result === false ) {
            $current = (int) get_transient( $rate_key );
            if ( $current > 0 ) {
                set_transient( $rate_key, $current - 1, self::RATE_LIMIT_PERIOD );
            }

            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::error(
                    'Rating DB write failed',
                    [
                        'anime_id'  => $anime_id,
                        'user_id'   => $user_id,
                        'operation' => $existing ? 'update' : 'insert',
                        'db_error'  => $wpdb->last_error,
                    ]
                );
            }

            return new WP_Error(
                'db_write_failed',
                '儲存評分時發生錯誤，請稍後再試',
                [ 'status' => 500 ]
            );
        }

        $this->flush_caches_after_write();

        $stats = $this->update_post_meta_scores( $anime_id );

        if ( ! $existing ) {
            do_action( 'smacg_rating_added', $user_id, $anime_id, $score_overall );
        }

        return rest_ensure_response( [
            'success'  => true,
            'message'  => $existing ? '評分已更新' : '評分成功',
            'stats'    => $stats,
            'my_score' => $this->get_user_rating( $anime_id, $user_id ),
        ] );
    }

    // ================================================================
    // API：刪除目前使用者對某部的評分（v1.5.0 新增）
    // ================================================================

    public function api_delete_rating( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $anime_id = (int) $request->get_param( 'anime_id' );
        $user_id  = get_current_user_id();

        // 與提交一致：僅允許 anime 或 manga
        if ( ! in_array( get_post_type( $anime_id ), self::ALLOWED_POST_TYPES, true ) ) {
            return new WP_Error( 'invalid_target', '找不到此動畫或漫畫', [ 'status' => 404 ] );
        }

        // 沒評過就不用刪
        $existing = $this->get_user_rating( $anime_id, $user_id );
        if ( ! $existing ) {
            return new WP_Error( 'not_found', '你尚未對此作品評分', [ 'status' => 404 ] );
        }

        global $wpdb;
        $result = $wpdb->delete(
            $this->table,
            [ 'anime_id' => $anime_id, 'user_id' => $user_id ],
            [ '%d', '%d' ]
        );

        if ( $result === false ) {
            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::error(
                    'Rating delete failed',
                    [
                        'anime_id' => $anime_id,
                        'user_id'  => $user_id,
                        'db_error' => $wpdb->last_error,
                    ]
                );
            }
            return new WP_Error( 'db_delete_failed', '刪除評分時發生錯誤，請稍後再試', [ 'status' => 500 ] );
        }

        // 沿用既有：清快取 + 重算 post meta 平均分
        $this->flush_caches_after_write();
        $stats = $this->update_post_meta_scores( $anime_id );

        return rest_ensure_response( [
            'success' => true,
            'message' => '評分已刪除',
            'stats'   => $stats,
        ] );
    }

    // ================================================================
    // API：站內排行榜（v1.4.0：加 post_type 分流）
    // ================================================================

    public function api_get_site_ranking( WP_REST_Request $request ): WP_REST_Response {
        $limit     = (int) $request->get_param( 'limit' );
        $rank_type = (string) $request->get_param( 'rank_type' );
        $period    = (string) $request->get_param( 'period' );
        $min_count = (int) $request->get_param( 'min_count' );
        $post_type = (string) $request->get_param( 'post_type' );

        // 防呆：post_type 僅允許白名單
        if ( ! in_array( $post_type, self::ALLOWED_POST_TYPES, true ) ) {
            $post_type = 'anime';
        }

        // v1.4.0：快取 key 帶入 post_type，動漫榜/漫畫榜互不覆蓋
        $cache_key = match ( $rank_type ) {
            'favorites' => self::RANKING_CACHE_PREFIX . $post_type . '_fav_' . $limit . '_' . $min_count,
            'views'     => self::RANKING_CACHE_PREFIX . $post_type . '_views_' . $period . '_' . $limit . '_' . $min_count,
            default     => self::RANKING_CACHE_PREFIX . $post_type . '_' . $limit,
        };

        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return rest_ensure_response( $cached );
        }

        $result = match ( $rank_type ) {
            'favorites' => $this->build_favorites_ranking( $limit, $min_count, $post_type ),
            'views'     => $this->build_views_ranking( $period, $limit, $min_count, $post_type ),
            default     => $this->build_site_ranking( $limit, $post_type ),
        };

        $ttl = match ( $rank_type ) {
            'favorites' => self::FAVORITES_CACHE_TTL,
            'views'     => self::VIEWS_CACHE_TTL,
            default     => self::RANKING_CACHE_TTL,
        };

        set_transient( $cache_key, $result, $ttl );

        return rest_ensure_response( $result );
    }

    /**
     * 評分榜（rating）
     * v1.4.0：加 $post_type，逐筆迴圈過濾
     */
    private function build_site_ranking( int $limit, string $post_type = 'anime' ): array {
        global $wpdb;

        // 因為要在 PHP 端依 post_type 過濾，撈更多備量避免過濾後不足
        $fetch_limit = $limit * self::RANKING_FETCH_MULTIPLIER;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                anime_id,
                COUNT(*) AS vote_count,
                SUM(weight) AS total_weight,
                SUM(score_overall   * weight) / NULLIF(SUM(weight), 0) AS avg_overall,
                SUM(score_story     * weight) / NULLIF(SUM(weight), 0) AS avg_story,
                SUM(score_music     * weight) / NULLIF(SUM(weight), 0) AS avg_music,
                SUM(score_animation * weight) / NULLIF(SUM(weight), 0) AS avg_animation,
                SUM(score_voice     * weight) / NULLIF(SUM(weight), 0) AS avg_voice
             FROM {$this->table}
             GROUP BY anime_id
             HAVING vote_count >= 1
             ORDER BY avg_overall DESC
             LIMIT %d",
            $fetch_limit
        ) );

        $global_avg = $this->get_global_average();
        $result     = [];

        foreach ( $rows as $row ) {
            $post = get_post( (int) $row->anime_id );
            if ( ! $post || $post->post_status !== 'publish' ) {
                continue;
            }
            // v1.4.0：post_type 過濾（動漫榜/漫畫榜分開）
            if ( $post->post_type !== $post_type ) {
                continue;
            }

            $weighted = $this->calc_weighted_score(
                (float) $row->avg_overall,
                (int)   $row->vote_count,
                $global_avg
            );

            $result[] = [
                'anime_id'      => (int) $row->anime_id,
                'title'         => get_post_meta( $post->ID, 'anime_title_chinese', true ) ?: $post->post_title,
                'cover'         => get_post_meta( $post->ID, 'anime_cover_image', true ),
                'url'           => get_permalink( $post->ID ),
                'vote_count'    => (int) $row->vote_count,
                'score'         => round( $weighted, 2 ),
                'avg_story'     => round( (float) $row->avg_story, 2 ),
                'avg_music'     => round( (float) $row->avg_music, 2 ),
                'avg_animation' => round( (float) $row->avg_animation, 2 ),
                'avg_voice'     => round( (float) $row->avg_voice, 2 ),
                'post_type'     => $post_type,
            ];
        }

        usort( $result, fn( $a, $b ) => $b['score'] <=> $a['score'] );
        return array_slice( $result, 0, $limit );
    }

    /**
     * 收藏數榜
     * v1.4.0：加 $post_type，逐筆迴圈過濾
     */
    private function build_favorites_ranking( int $limit, int $min_count = 0, string $post_type = 'anime' ): array {
        global $wpdb;

        $min       = $min_count > 0 ? $min_count : self::MIN_COUNT_FAVORITES;
        $status_tb = $wpdb->prefix . 'anime_user_status';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like( $status_tb )
        ) );
        if ( $exists !== $status_tb ) {
            return [];
        }

        // 因為要在 PHP 端依 post_type 過濾，撈 3 倍備量
        $fetch_limit = $limit * self::RANKING_FETCH_MULTIPLIER;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT anime_id, COUNT(*) AS fav_count
             FROM {$status_tb}
             WHERE favorited = 1
             GROUP BY anime_id
             HAVING fav_count >= %d
             ORDER BY fav_count DESC
             LIMIT %d",
            $min,
            $fetch_limit
        ) );

        if ( empty( $rows ) ) {
            return [];
        }

        $result = [];
        foreach ( $rows as $row ) {
            $post = get_post( (int) $row->anime_id );
            if ( ! $post || $post->post_status !== 'publish' ) {
                continue;
            }
            // v1.4.0：post_type 過濾
            if ( $post->post_type !== $post_type ) {
                continue;
            }

            $result[] = [
                'anime_id'      => (int) $row->anime_id,
                'title'         => get_post_meta( $post->ID, 'anime_title_chinese', true ) ?: $post->post_title,
                'cover'         => get_post_meta( $post->ID, 'anime_cover_image', true ),
                'url'           => get_permalink( $post->ID ),
                'vote_count'    => (int) $row->fav_count,
                'fav_count'     => (int) $row->fav_count,
                'score'         => null,
                'avg_story'     => null,
                'avg_music'     => null,
                'avg_animation' => null,
                'avg_voice'     => null,
                'rank_type'     => 'favorites',
                'post_type'     => $post_type,
            ];

            if ( count( $result ) >= $limit ) break;
        }

        return $result;
    }

    /**
     * 瀏覽數榜（透過 WP Statistics）
     * v1.4.0：加 $post_type，並把 WP Statistics 查詢的 CPT 參數改用 $post_type
     */
    private function build_views_ranking( string $period, int $limit, int $min_count = 0, string $post_type = 'anime' ): array {
        if ( ! function_exists( 'wp_statistics_get_top_pages' ) ) {
            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::error(
                    'Views ranking: WP Statistics plugin not active',
                    [ 'period' => $period, 'post_type' => $post_type ]
                );
            }
            return [];
        }

        $min = $min_count > 0 ? $min_count : self::MIN_COUNT_VIEWS;

        $end_date = current_time( 'Y-m-d' );
        $start_date = match ( $period ) {
            'daily'   => $end_date,
            'weekly'  => date( 'Y-m-d', strtotime( '-7 days' ) ),
            'monthly' => date( 'Y-m-d', strtotime( '-30 days' ) ),
            'yearly'  => date( 'Y-m-d', strtotime( '-365 days' ) ),
            default   => date( 'Y-m-d', strtotime( '-30 days' ) ),
        };

        $fetch_limit = $limit * 2;

        // v1.4.0：CPT 參數改為傳入的 $post_type（anime 或 manga）
        $top_pages = wp_statistics_get_top_pages( $start_date, $end_date, $fetch_limit, $post_type );

        if ( ! is_array( $top_pages ) || empty( $top_pages ) ) {
            return [];
        }

        $result = [];
        $rank   = 0;
        foreach ( $top_pages as $row ) {
            if ( ! is_array( $row ) || count( $row ) < 5 ) continue;

            $views   = (int) ( $row[1] ?? 0 );
            $page_id = (int) ( $row[2] ?? 0 );

            if ( $views < $min ) continue;
            if ( $page_id <= 0 )  continue;

            $post = get_post( $page_id );
            // v1.4.0：post_type 過濾改用 $post_type
            if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== $post_type ) {
                continue;
            }

            $result[] = [
                'anime_id'      => $page_id,
                'title'         => get_post_meta( $page_id, 'anime_title_chinese', true ) ?: $post->post_title,
                'cover'         => get_post_meta( $page_id, 'anime_cover_image', true ),
                'url'           => get_permalink( $page_id ),
                'vote_count'    => $views,
                'view_count'    => $views,
                'score'         => null,
                'avg_story'     => null,
                'avg_music'     => null,
                'avg_animation' => null,
                'avg_voice'     => null,
                'rank_type'     => 'views',
                'period'        => $period,
                'post_type'     => $post_type,
            ];

            if ( ++$rank >= $limit ) break;
        }

        return $result;
    }

    // ================================================================
    // 核心：取得單部動畫/漫畫的統計資料（與 post_type 無關，未改動）
    // ================================================================

    public function get_stats( int $anime_id ): array {
        global $wpdb;

        $sql = "SELECT
                COUNT(*) AS vote_count,
                SUM(weight) AS total_weight,
                SUM(score_overall   * weight) / NULLIF(SUM(weight), 0) AS avg_overall,
                SUM(score_story     * weight) / NULLIF(SUM(weight), 0) AS avg_story,
                SUM(score_music     * weight) / NULLIF(SUM(weight), 0) AS avg_music,
                SUM(score_animation * weight) / NULLIF(SUM(weight), 0) AS avg_animation,
                SUM(score_voice     * weight) / NULLIF(SUM(weight), 0) AS avg_voice,
                SUM(CASE WHEN FLOOR(score_overall) = 1  THEN 1 ELSE 0 END) AS d1,
                SUM(CASE WHEN FLOOR(score_overall) = 2  THEN 1 ELSE 0 END) AS d2,
                SUM(CASE WHEN FLOOR(score_overall) = 3  THEN 1 ELSE 0 END) AS d3,
                SUM(CASE WHEN FLOOR(score_overall) = 4  THEN 1 ELSE 0 END) AS d4,
                SUM(CASE WHEN FLOOR(score_overall) = 5  THEN 1 ELSE 0 END) AS d5,
                SUM(CASE WHEN FLOOR(score_overall) = 6  THEN 1 ELSE 0 END) AS d6,
                SUM(CASE WHEN FLOOR(score_overall) = 7  THEN 1 ELSE 0 END) AS d7,
                SUM(CASE WHEN FLOOR(score_overall) = 8  THEN 1 ELSE 0 END) AS d8,
                SUM(CASE WHEN FLOOR(score_overall) = 9  THEN 1 ELSE 0 END) AS d9,
                SUM(CASE WHEN FLOOR(score_overall) = 10 THEN 1 ELSE 0 END) AS d10
             FROM {$this->table}
             WHERE anime_id = %d";

        $row = $wpdb->get_row( $wpdb->prepare( $sql, $anime_id ) );

        if ( ! $row || (int) $row->vote_count === 0 ) {
            return [
                'vote_count'    => 0,
                'score'         => null,
                'avg_story'     => null,
                'avg_music'     => null,
                'avg_animation' => null,
                'avg_voice'     => null,
                'distribution'  => array_fill( 1, 10, 0 ),
            ];
        }

        $global_avg = $this->get_global_average();
        $weighted   = $this->calc_weighted_score(
            (float) $row->avg_overall,
            (int)   $row->vote_count,
            $global_avg
        );

        $distribution = [];
        for ( $i = 1; $i <= 10; $i++ ) {
            $distribution[ $i ] = (int) ( $row->{'d' . $i} ?? 0 );
        }

        return [
            'vote_count'    => (int) $row->vote_count,
            'score'         => round( $weighted, 2 ),
            'avg_story'     => round( (float) $row->avg_story, 2 ),
            'avg_music'     => round( (float) $row->avg_music, 2 ),
            'avg_animation' => round( (float) $row->avg_animation, 2 ),
            'avg_voice'     => round( (float) $row->avg_voice, 2 ),
            'distribution'  => $distribution,
        ];
    }

    // ================================================================
    // 取得特定使用者對某部的評分（與 post_type 無關，未改動）
    // ================================================================

    public function get_user_rating( int $anime_id, int $user_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT score_story, score_music, score_animation, score_voice, score_overall, updated_at
             FROM {$this->table}
             WHERE anime_id = %d AND user_id = %d
             LIMIT 1",
            $anime_id,
            $user_id
        ) );

        if ( ! $row ) return null;

        return [
            'score_story'     => (float) $row->score_story,
            'score_music'     => (float) $row->score_music,
            'score_animation' => (float) $row->score_animation,
            'score_voice'     => (float) $row->score_voice,
            'score_overall'   => (float) $row->score_overall,
            'updated_at'      => $row->updated_at,
        ];
    }

    // ================================================================
    // 加權評分公式（未改動）
    // ================================================================

    private function calc_weighted_score( float $avg, int $votes, float $global_avg ): float {
        $m = $this->min_votes;
        return ( $votes / ( $votes + $m ) ) * $avg
             + ( $m    / ( $votes + $m ) ) * $global_avg;
    }

    // ================================================================
    // 全站所有評分的平均值（未改動）
    // ================================================================

    private function get_global_average(): float {
        $cached = get_transient( 'asp_global_avg' );
        if ( $cached !== false ) {
            return (float) $cached;
        }

        global $wpdb;
        $avg = (float) $wpdb->get_var(
            "SELECT AVG(score_overall) FROM {$this->table}"
        );
        $avg = $avg > 0 ? $avg : 7.0;

        set_transient( 'asp_global_avg', $avg, self::GLOBAL_AVG_CACHE_TTL );
        return $avg;
    }

    // ================================================================
    // 使用者權重計算（未改動）
    // ================================================================

    private function get_user_weight( int $user_id ): float {
        if ( isset( self::$user_weight_cache[ $user_id ] ) ) {
            return self::$user_weight_cache[ $user_id ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_registered ) ) {
            return self::$user_weight_cache[ $user_id ] = 1.0;
        }

        $reg_date = strtotime( $user->user_registered );
        if ( $reg_date === false ) {
            return self::$user_weight_cache[ $user_id ] = 1.0;
        }

        $days_old = ( time() - $reg_date ) / DAY_IN_SECONDS;

        global $wpdb;
        $rated_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT anime_id) FROM {$this->table} WHERE user_id = %d",
            $user_id
        ) );

        if ( $days_old >= 30 && $rated_count >= 10 ) {
            $weight = 1.5;
        } elseif ( $days_old < 7 ) {
            $weight = 0.5;
        } else {
            $weight = 1.0;
        }

        return self::$user_weight_cache[ $user_id ] = $weight;
    }

    // ================================================================
    // 更新 post meta（未改動）
    // ================================================================

    private function update_post_meta_scores( int $anime_id ): array {
        $stats = $this->get_stats( $anime_id );
        update_post_meta( $anime_id, 'anime_score_site',       $stats['score'] ?? '' );
        update_post_meta( $anime_id, 'anime_score_site_count', $stats['vote_count'] ?? 0 );
        return $stats;
    }

    // ================================================================
    // 清除寫入相關快取（未改動，前綴一致所以動漫/漫畫榜快取都會清掉）
    // ================================================================

    private function flush_caches_after_write(): void {
        delete_transient( 'asp_global_avg' );

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
                OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_' . self::RANKING_CACHE_PREFIX ) . '%',
            $wpdb->esc_like( '_transient_timeout_' . self::RANKING_CACHE_PREFIX ) . '%'
        ) );
    }
}
