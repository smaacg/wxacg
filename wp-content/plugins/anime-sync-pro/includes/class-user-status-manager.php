<?php
/**
 * User Status Manager
 *
 * 使用者追蹤狀態 CRUD + REST API + 快取
 *
 * v1.1.0 (2026-07-19)
 *   - Feat：支援漫畫（manga）等多 CPT 追蹤。
 *     * api_update() post type 檢查由寫死 'anime' 改為 ANIME_SYNC_PRO_CPTS 白名單。
 *     * 新增 get_total_units()：動畫讀 anime_episodes，漫畫讀 manga_chapters（退回 manga_volumes）。
 *       set_status() / adjust_progress() / set_progress() 進度上限改用此 helper。
 *     * 未播出（NOT_YET_RELEASED）擋鎖只對 post_type === 'anime' 生效，漫畫不受限。
 *   - 資料表 anime_user_status 欄位 anime_id 沿用（其值即 post_id，與 CPT 無關）。
 *
 * v1.0.2 (2026-05-22)
 *   - Feat：toggle_favorite() 改為先查當前值再決定，
 *           只在「首次設定為 favorited=1」（null→1 或 0→1）時觸發
 *           do_action('smacg_favorite_added', $user_id, $anime_id)
 *           供 SMACG Gamification v2.7.0 計算收藏 EXP / 賽季積分
 *   - 防刷分由 gamification 端 per-anime user_meta（smacg_exp_favorite_{anime_id}）永久去重，
 *           重複切換 取消／再收藏 不會再給 EXP
 *
 * v1.0.1 (2026-05-20)
 *   - Feat：set_status() 在「首次進入某狀態」時觸發 SMACG Gamification hook：
 *           * want / watching  → do_action('smacg_watchlist_added',     $uid, $anime_id)
 *           * completed        → do_action('smacg_watchlist_completed', $uid, $anime_id)
 *           狀態未實際變更（例如本來就 completed 再點一次 completed）不觸發
 *   - Feat：adjust_progress() 進度自動推升至完成時，
 *           若 UPDATE 實際影響到 row 才觸發 smacg_watchlist_completed
 *   - 防刷分由 gamification 端 per-anime user_meta 去重把關
 *
 * @package Anime_Sync_Pro
 * @version 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_User_Status_Manager {

    const STATUS_WANT      = 0;
    const STATUS_WATCHING  = 1;
    const STATUS_COMPLETED = 2;
    const STATUS_DROPPED   = 3;
    /*
     * 暫停：看到一半停著，之後可能會回來（等下一季、等單行本、單純沒空）。
     * 與棄坑的差別在「還想繼續」，因此偏好推算時仍視為喜歡，不像棄坑要排除。
     * status 欄位是 tinyint，新增值 4 不需要改主表結構。
     */
    const STATUS_PAUSED    = 4;

    private const STATUS_MAP = [
        'want'      => self::STATUS_WANT,
        'watching'  => self::STATUS_WATCHING,
        'completed' => self::STATUS_COMPLETED,
        'dropped'   => self::STATUS_DROPPED,
        'paused'    => self::STATUS_PAUSED,
    ];

    private const STATUS_REVERSE = [
        self::STATUS_WANT      => 'want',
        self::STATUS_WATCHING  => 'watching',
        self::STATUS_COMPLETED => 'completed',
        self::STATUS_DROPPED   => 'dropped',
        self::STATUS_PAUSED    => 'paused',
    ];

    private const RATE_LIMIT_MAX    = 30;
    private const RATE_LIMIT_PERIOD = MINUTE_IN_SECONDS;

    private const CACHE_GROUP   = 'anime_user_status';
    private const CACHE_TTL_ONE = 60;
    private const CACHE_TTL_LST = 300;

    // 單一作品彙總統計：與 Cron 同一個 cache group，
    // 每 15 分鐘重算後 flush_ranking_cache() 會一併清掉，不需另外失效。
    private const CACHE_TTL_STATS = 600;

    // 個人化推薦：改用 transient 保存，避免被上述 group flush 連帶清空；
    // 使用者自己更動追蹤清單時由 flush_cache() 主動失效。
    private const CACHE_TTL_RECO  = HOUR_IN_SECONDS;
    private const RECO_SAMPLE_MAX = 30;  // 最多取幾筆追蹤紀錄推算偏好
    private const RECO_GENRE_MAX  = 3;   // 取前幾名類型作為推薦依據
    private const RECO_POOL_MAX   = 24;  // 候選池大小

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /* ──────────────────────────────────────────────
     * REST 路由
     * ────────────────────────────────────────────── */

    public function register_routes(): void {
        $ns = 'weixiaoacg/v1';

        register_rest_route( $ns, '/user-status/list', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'api_get_my_list' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/user-status/(?P<anime_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'api_get_one' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'api_update' ],
                'permission_callback' => [ $this, 'require_login' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'api_delete' ],
                'permission_callback' => [ $this, 'require_login' ],
            ],
        ] );
    }

    public function require_login() {
        return is_user_logged_in()
            ? true
            : new WP_Error( 'rest_forbidden', '請先登入', [ 'status' => 401 ] );
    }

    /* ──────────────────────────────────────────────
     * ★ v1.1.0：允許追蹤的 post type 白名單
     * ────────────────────────────────────────────── */
    private function allowed_post_types(): array {
        $cpts = defined( 'ANIME_SYNC_PRO_CPTS' )
            ? array_map( 'trim', explode( ',', ANIME_SYNC_PRO_CPTS ) )
            : [ 'anime', 'manga' ];
        // 保底至少含 anime / manga
        return array_values( array_unique( array_merge( [ 'anime', 'manga' ], $cpts ) ) );
    }

    /* ──────────────────────────────────────────────
     * ★ v1.1.0：依 post type 回傳進度總量
     *   動畫 → anime_episodes
     *   漫畫 → manga_chapters（優先），退回 manga_volumes
     * ────────────────────────────────────────────── */
    private function get_total_units( int $post_id ): int {
        $pt = get_post_type( $post_id );
        if ( $pt === 'manga' ) {
            $c = (int) get_post_meta( $post_id, 'manga_chapters', true );
            if ( $c > 0 ) return $c;
            return (int) get_post_meta( $post_id, 'manga_volumes', true );
        }
        return (int) get_post_meta( $post_id, 'anime_episodes', true );
    }

    /* ──────────────────────────────────────────────
     * REST callback
     * ────────────────────────────────────────────── */

    public function api_get_one( WP_REST_Request $req ) {
        $user_id  = get_current_user_id();
        $anime_id = (int) $req['anime_id'];

        if ( ! $user_id ) {
            return rest_ensure_response( [
                'logged_in' => false,
                'data'      => $this->empty_entry(),
            ] );
        }

        return rest_ensure_response( [
            'logged_in' => true,
            'data'      => $this->get_entry( $user_id, $anime_id ),
        ] );
    }

    public function api_get_my_list( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $list    = $this->get_user_list( $user_id );
        return rest_ensure_response( [
            'success' => true,
            'count'   => count( $list ),
            'data'    => $list,
        ] );
    }

    public function api_update( WP_REST_Request $req ) {
        $user_id  = get_current_user_id();
        $anime_id = (int) $req['anime_id'];
        $action   = sanitize_key( $req->get_param( 'action' ) );
        $value    = $req->get_param( 'value' );

        // ★ v1.1.0：放寬 post type 檢查（anime / manga / …）
        if ( ! in_array( get_post_type( $anime_id ), $this->allowed_post_types(), true ) ) {
            return new WP_Error( 'invalid_post', '作品不存在', [ 'status' => 400 ] );
        }

        if ( ! $this->check_rate_limit( $user_id ) ) {
            return new WP_Error( 'rate_limited', '操作過於頻繁，請稍候 1 分鐘', [ 'status' => 429 ] );
        }

        $result = false;
        switch ( $action ) {
            case 'status':
                /*
                 * 前端點擊「已選中」的狀態按鈕代表取消追番，送出的值是 'none'
                 * （見 themes/wxacgtheme/assets/js/anime-status.js 的
                 *   const newVal = state.status === value ? 'none' : value）。
                 *
                 * 'none' 不在 STATUS_MAP 裡，原本會讓 set_status() 回傳 false，
                 * 被上層當成資料庫寫入失敗：記一筆 error 日誌、回傳「儲存失敗」500。
                 * 使用者按第二下想取消，看到的是系統錯誤，狀態還留著——
                 * 近 30 天發生 40 次，其中 19 次集中在追番人數最多的作品。
                 */
                $result = ( 'none' === $value )
                    ? $this->clear_status( $user_id, $anime_id )
                    : $this->set_status( $user_id, $anime_id, (string) $value );
                break;
            case 'progress':
                $result = $this->adjust_progress( $user_id, $anime_id, (int) $value );
                break;
            case 'progress_set':
                $result = $this->set_progress( $user_id, $anime_id, (int) $value );
                break;
            case 'favorite':
                $result = $this->toggle_favorite( $user_id, $anime_id );
                break;
            case 'fullclear':
                $result = $this->toggle_fullclear( $user_id, $anime_id );
                break;
            case 'note':
                $result = $this->set_note( $user_id, $anime_id, (string) $value );
                break;
            case 'private':
                $result = $this->set_private( $user_id, $anime_id, (int) $value );
                break;
            default:
                return new WP_Error( 'invalid_action', '不支援的動作', [ 'status' => 400 ] );
        }

        /*
         * 業務規則拒絕（例如未播出作品不能標記為追番中）由下層回傳 WP_Error，
         * 帶著使用者看得懂的原因直接往上傳，不記 error 日誌——那不是系統故障。
         */
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $result === false ) {
            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::error( 'User status write failed', [
                    'user_id'  => $user_id,
                    'anime_id' => $anime_id,
                    'action'   => $action,
                    'value'    => is_scalar( $value ) ? (string) $value : gettype( $value ),
                    'db_error' => $GLOBALS['wpdb']->last_error,
                ] );
            }
            return new WP_Error( 'db_error', '儲存失敗', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'success'       => true,
            'entry'         => $this->get_entry( $user_id, $anime_id, false ),
            'points_earned' => 0,
        ] );
    }

    public function api_delete( WP_REST_Request $req ) {
        $user_id  = get_current_user_id();
        $anime_id = (int) $req['anime_id'];

        global $wpdb;
        $result = $wpdb->delete(
            $wpdb->prefix . 'anime_user_status',
            [ 'user_id' => $user_id, 'anime_id' => $anime_id ],
            [ '%d', '%d' ]
        );

        if ( $result === false ) {
            return new WP_Error( 'db_error', '刪除失敗', [ 'status' => 500 ] );
        }

        $this->flush_cache( $user_id, $anime_id );

        return rest_ensure_response( [ 'success' => true, 'message' => '已移除' ] );
    }

    /* ──────────────────────────────────────────────
     * 寫入方法（皆使用 ON DUPLICATE KEY UPDATE 原子 upsert）
     * ────────────────────────────────────────────── */

    /**
     * @return bool|WP_Error true 成功／false 系統錯誤／WP_Error 業務規則拒絕（帶原因）
     */
    private function set_status( int $user_id, int $anime_id, string $status ) {
        if ( ! isset( self::STATUS_MAP[ $status ] ) ) return false;

        // 🚫 未播出動畫只能點「想看」「棄坑」，不能點「追番中」「已看完」
        // ★ v1.1.0：僅對 post_type === 'anime' 生效，漫畫無此概念
        if ( in_array( $status, [ 'watching', 'completed' ], true )
             && get_post_type( $anime_id ) === 'anime' ) {
            $airing = get_post_meta( $anime_id, 'anime_status', true );
            if ( $airing === 'NOT_YET_RELEASED' ) {
                /*
                 * 這是業務規則拒絕，不是系統錯誤。
                 * 原本回傳 false，與資料庫寫入失敗共用同一個值，導致上層
                 * 記一筆 error 日誌、並對使用者顯示「儲存失敗」——訊息完全
                 * 沒說明真正的原因。改為丟出帶原因的 WP_Error。
                 */
                return new WP_Error(
                    'not_yet_released',
                    '這部作品尚未播出，目前只能標記「想看」或「棄坑」。',
                    [ 'status' => 400 ]
                );
            }
        }

        $status_int = self::STATUS_MAP[ $status ];
        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';
        $now   = current_time( 'mysql' );

        // v1.0.1：先查詢前一個狀態，用來判斷是否「實際變更」
        $prev_status_int = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE user_id = %d AND anime_id = %d",
            $user_id, $anime_id
        ) );
        $prev_status_int = ( $prev_status_int === null ) ? null : (int) $prev_status_int;

        // 點「已看完」時，自動補滿進度（★ v1.1.0：依 post type 取總量）
        $auto_progress = null;
        if ( $status_int === self::STATUS_COMPLETED ) {
            $total_ep = $this->get_total_units( $anime_id );
            if ( $total_ep > 0 ) {
                $auto_progress = $total_ep;
            }
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (user_id, anime_id, status, progress, started_at, completed_at)
             VALUES (%d, %d, %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                status       = VALUES(status),
                progress     = IF(VALUES(status) = %d AND VALUES(progress) > 0, VALUES(progress), progress),
                started_at   = COALESCE(started_at, VALUES(started_at)),
                completed_at = IF(VALUES(status) = %d, VALUES(completed_at), completed_at)",
            $user_id,
            $anime_id,
            $status_int,
            $auto_progress ?? 0,
            $now,
            $status_int === self::STATUS_COMPLETED ? $now : null,
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;

        $this->flush_cache( $user_id, $anime_id );

        // v1.0.1：只在「狀態實際變更」時觸發 gamification hook
        if ( $prev_status_int !== $status_int ) {

            if ( in_array( $status_int, [ self::STATUS_WANT, self::STATUS_WATCHING ], true ) ) {
                $prev_was_in_list = in_array(
                    $prev_status_int,
                    [ self::STATUS_WANT, self::STATUS_WATCHING ],
                    true
                );
                if ( ! $prev_was_in_list ) {
                    do_action( 'smacg_watchlist_added', $user_id, $anime_id );
                }
            }

            if ( $status_int === self::STATUS_COMPLETED ) {
                do_action( 'smacg_watchlist_completed', $user_id, $anime_id );
            }
        }

        return true;
    }


    /**
     * 取消追番：清掉狀態，保留其他資料。
     *
     * ★ 為什麼是 UPDATE status = NULL 而不是刪除整列
     *   status 欄位本來就允許 NULL（Null=YES, Default=NULL），而且站上已有
     *   43 列處於這個狀態——adjust_progress()／toggle_favorite() 等函式
     *   INSERT 時都不帶 status，所以「有互動紀錄但沒設狀態」是既有的正常情形，
     *   adjust_progress() 內部也有 `status IS NULL OR status != %d` 的判斷。
     *
     *   刪除整列會連帶失去 progress（66 列有值）、favorited（19 列）、
     *   note、fullcleared。而收藏在前端是獨立的 data-action="favorite" 按鈕，
     *   使用者按「取消追番」時不會預期收藏也一起消失。
     *
     * 找不到該列時回傳 true：使用者本來就沒有追番紀錄，取消的結果與預期一致，
     * 不該回報成失敗。
     */
    private function clear_status( int $user_id, int $anime_id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'anime_user_status';

        $prev = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE user_id = %d AND anime_id = %d",
            $user_id,
            $anime_id
        ) );

        // 沒有紀錄，或狀態本來就是空的——無事可做，視為成功。
        if ( null === $prev ) {
            return true;
        }

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = NULL, updated_at = %s
              WHERE user_id = %d AND anime_id = %d",
            current_time( 'mysql' ),
            $user_id,
            $anime_id
        ) );

        if ( false === $result ) {
            return false;
        }

        $this->flush_cache( $user_id, $anime_id );

        return true;
    }


    private function adjust_progress( int $user_id, int $anime_id, int $delta ): bool {
        $total_ep = $this->get_total_units( $anime_id ); // ★ v1.1.0
        $max      = $total_ep > 0 ? $total_ep : 9999;

        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';
        $now   = current_time( 'mysql' );

        // 第一階段：原子 upsert（progress + started_at）
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id, anime_id, progress, started_at)
             VALUES (%d, %d, GREATEST(0, %d), %s)
             ON DUPLICATE KEY UPDATE
                progress = GREATEST(0, LEAST(progress + %d, %d)),
                started_at = COALESCE(started_at, VALUES(started_at))",
            $user_id, $anime_id, max( 0, $delta ), $now, $delta, $max
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;

        // 第二階段：若進度已加到滿，且已知總量 → 自動標記為已看完
        if ( $total_ep > 0 ) {
            $current = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT progress FROM {$table} WHERE user_id = %d AND anime_id = %d",
                $user_id, $anime_id
            ) );

            if ( $current >= $total_ep ) {
                $affected = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table}
                     SET status       = %d,
                         fullcleared  = 1,
                         completed_at = COALESCE(completed_at, %s)
                     WHERE user_id = %d AND anime_id = %d
                       AND (status IS NULL OR status != %d)",
                    self::STATUS_COMPLETED,
                    $now,
                    $user_id,
                    $anime_id,
                    self::STATUS_COMPLETED
                ) );

                if ( $affected ) {
                    do_action( 'smacg_watchlist_completed', $user_id, $anime_id );
                }
            }
        }

        $this->flush_cache( $user_id, $anime_id );
        return true;
    }


    private function set_progress( int $user_id, int $anime_id, int $progress ): bool {
        $max = $this->get_total_units( $anime_id ); // ★ v1.1.0
        if ( $max <= 0 ) $max = 9999;
        $progress = max( 0, min( $progress, $max ) );

        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id, anime_id, progress)
             VALUES (%d, %d, %d)
             ON DUPLICATE KEY UPDATE progress = VALUES(progress)",
            $user_id, $anime_id, $progress
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;
        $this->flush_cache( $user_id, $anime_id );
        return true;
    }

    /**
     * 切換收藏
     */
    private function toggle_favorite( int $user_id, int $anime_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';

        $prev = $wpdb->get_var( $wpdb->prepare(
            "SELECT favorited FROM {$table} WHERE user_id = %d AND anime_id = %d",
            $user_id, $anime_id
        ) );
        $prev_int = ( $prev === null ) ? null : (int) $prev;

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id, anime_id, favorited)
             VALUES (%d, %d, 1)
             ON DUPLICATE KEY UPDATE favorited = 1 - favorited",
            $user_id, $anime_id
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;
        $this->flush_cache( $user_id, $anime_id );

        $is_now_favorited = ( $prev_int === null || $prev_int === 0 );
        if ( $is_now_favorited ) {
            do_action( 'smacg_favorite_added', $user_id, $anime_id );
        }

        return true;
    }

    private function toggle_fullclear( int $user_id, int $anime_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id, anime_id, fullcleared)
             VALUES (%d, %d, 1)
             ON DUPLICATE KEY UPDATE fullcleared = 1 - fullcleared",
            $user_id, $anime_id
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;
        $this->flush_cache( $user_id, $anime_id );
        return true;
    }

    private function set_note( int $user_id, int $anime_id, string $note ): bool {
        $note = mb_substr( wp_strip_all_tags( $note ), 0, 500 );

        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id, anime_id, note)
             VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE note = VALUES(note)",
            $user_id, $anime_id, $note
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;
        $this->flush_cache( $user_id, $anime_id );
        return true;
    }

    private function set_private( int $user_id, int $anime_id, int $is_private ): bool {
        $is_private = $is_private ? 1 : 0;

        global $wpdb;
        $table = $wpdb->prefix . 'anime_user_status';

        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (user_id, anime_id, is_private)
             VALUES (%d, %d, %d)
             ON DUPLICATE KEY UPDATE is_private = VALUES(is_private)",
            $user_id, $anime_id, $is_private
        );

        $result = $wpdb->query( $sql );
        if ( $result === false ) return false;
        $this->flush_cache( $user_id, $anime_id );
        return true;
    }

    /* ──────────────────────────────────────────────
     * 讀取方法（含快取）
     * ────────────────────────────────────────────── */

    public function get_entry( int $user_id, int $anime_id, bool $use_cache = true ): array {
        if ( ! $user_id || ! $anime_id ) return $this->empty_entry();

        $key = "us_{$user_id}_{$anime_id}";
        if ( $use_cache ) {
            $cached = wp_cache_get( $key, self::CACHE_GROUP );
            if ( false !== $cached ) return $cached;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, progress, favorited, fullcleared,
                    started_at, completed_at, note, is_private,
                    created_at, updated_at
             FROM {$wpdb->prefix}anime_user_status
             WHERE user_id = %d AND anime_id = %d",
            $user_id, $anime_id
        ), ARRAY_A );

        $entry = $row ? $this->normalize_row( $row ) : $this->empty_entry();
        wp_cache_set( $key, $entry, self::CACHE_GROUP, self::CACHE_TTL_ONE );

        return $entry;
    }

    public function get_user_list( int $user_id, bool $use_cache = true ): array {
        if ( ! $user_id ) return [];

        $key = "us_list_{$user_id}";
        if ( $use_cache ) {
            $cached = wp_cache_get( $key, self::CACHE_GROUP );
            if ( false !== $cached ) return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT anime_id, status, progress, favorited, fullcleared,
                    started_at, completed_at, note, is_private,
                    created_at, updated_at
             FROM {$wpdb->prefix}anime_user_status
             WHERE user_id = %d
             ORDER BY updated_at DESC",
            $user_id
        ), ARRAY_A );

        $list = [];
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $entry = $this->normalize_row( $r );
                $entry['anime_id'] = (int) $r['anime_id'];
                $list[] = $entry;
            }
        }

        wp_cache_set( $key, $list, self::CACHE_GROUP, self::CACHE_TTL_LST );
        return $list;
    }

    public function get_ranking( string $type = 'favorited', int $limit = 20 ): array {
        $allow = [ 'favorited', 'watching', 'completed', 'want', 'dropped', 'paused', 'total' ];
        if ( ! in_array( $type, $allow, true ) ) $type = 'favorited';

        $limit = max( 1, min( 100, $limit ) );
        $cache_key = "us_rank_{$type}_{$limit}";
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) return $cached;

        global $wpdb;
        $col = "{$type}_count";
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT anime_id, {$col} AS cnt
             FROM {$wpdb->prefix}anime_user_status_stats
             WHERE {$col} > 0
             ORDER BY {$col} DESC, anime_id ASC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        $rows = $rows ?: [];
        wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, 600 );
        return $rows;
    }

    /* ──────────────────────────────────────────────
     * 單一作品的追蹤統計（全站彙總）
     * ──────────────────────────────────────────────
     * 資料來自 anime_user_status_stats，由 Anime_Sync_User_Status_Cron
     * 每 15 分鐘重算；此處純唯讀，不會觸發即時彙總查詢。
     *
     * 回傳的是「全站彙總數字」而非個人資料，
     * 因此可安全輸出在對所有訪客共用的快取頁面上。
     * ────────────────────────────────────────────── */

    public function get_stats_for_anime( int $anime_id, bool $use_cache = true ): array {
        $empty = [
            'want'      => 0,
            'watching'  => 0,
            'completed' => 0,
            'dropped'   => 0,
            'paused'    => 0,
            'favorited' => 0,
            'total'     => 0,
        ];

        if ( ! $anime_id ) return $empty;

        $key = "us_stats_{$anime_id}";
        if ( $use_cache ) {
            $cached = wp_cache_get( $key, self::CACHE_GROUP );
            if ( false !== $cached ) return $cached;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT want_count, watching_count, completed_count,
                    dropped_count, paused_count, favorited_count, total_count
             FROM {$wpdb->prefix}anime_user_status_stats
             WHERE anime_id = %d",
            $anime_id
        ), ARRAY_A );

        $stats = $row
            ? [
                'want'      => (int) $row['want_count'],
                'watching'  => (int) $row['watching_count'],
                'completed' => (int) $row['completed_count'],
                'dropped'   => (int) $row['dropped_count'],
                'paused'    => (int) $row['paused_count'],
                'favorited' => (int) $row['favorited_count'],
                'total'     => (int) $row['total_count'],
            ]
            : $empty;

        wp_cache_set( $key, $stats, self::CACHE_GROUP, self::CACHE_TTL_STATS );

        return $stats;
    }

    /* ──────────────────────────────────────────────
     * 個人化推薦
     * ──────────────────────────────────────────────
     * 以使用者自己的追蹤紀錄推算偏好類型（genre），
     * 再找出同類型、但他尚未追過的作品。
     *
     * ⚠ 回傳結果因人而異，呼叫端必須確認只輸出給該使用者本人。
     *   （本站登入者有獨立的 LSCache 分區，不與訪客頁面共用）
     *
     * @param int $user_id    對象使用者
     * @param int $exclude_id 目前頁面的作品 ID（不推薦自己）
     * @param int $limit      需要幾筆
     * @return int[] 作品 post ID 陣列
     * ────────────────────────────────────────────── */

    /**
     * 取得推薦作品 ID。
     *
     * 兩段式：優先用使用者自己的追番偏好；沒有追番紀錄（或未登入）時，
     * 退回「與當前這部作品類型相近」的作品。
     *
     * 之所以要這層退路：追番清單為空就直接回空陣列的話，整個推薦區塊
     * 對「還沒開始追番的人」與訪客完全不出現——而那正是最需要被推坑的族群。
     * 推薦區塊只出現在作品頁上，當前作品的類型本身就是免費且精準的訊號，
     * 不需要額外蒐集任何使用者資料。
     *
     * @param int    $user_id    0 代表未登入。
     * @param int    $exclude_id 當前作品 ID，會從結果排除，同時作為相似推薦的依據。
     * @param int    $limit      取幾筆。
     * @param string $source     out：實際採用的來源，watchlist | similar | ''。
     * @return int[]
     */
    public function get_recommendations( int $user_id, int $exclude_id = 0, int $limit = 6, string &$source = '' ): array {
        $limit  = max( 1, min( self::RECO_POOL_MAX, $limit ) );
        $source = '';

        $pool = $user_id > 0
            ? $this->get_recommendation_pool( $user_id )
            : [];

        if ( ! empty( $pool ) ) {
            $source = 'watchlist';
        } elseif ( $exclude_id > 0 ) {
            $pool = $this->get_similar_pool( (int) $exclude_id );

            if ( ! empty( $pool ) ) {
                $source = 'similar';
            }
        }

        if ( empty( $pool ) ) return [];

        if ( $exclude_id ) {
            $pool = array_values( array_diff( $pool, [ (int) $exclude_id ] ) );
        }

        return array_slice( $pool, 0, $limit );
    }

    /**
     * 相似作品候選池：與指定作品共享類型的其他作品。
     *
     * 快取以「作品」為單位（非使用者），因此同一部作品的所有訪客共用一份，
     * 比逐使用者計算便宜很多。
     *
     * @return int[]
     */
    private function get_similar_pool( int $post_id ): array {
        $key    = "asp_reco_similar_{$post_id}";
        $cached = get_transient( $key );

        if ( is_array( $cached ) ) return $cached;

        $pool = $this->build_similar_pool( $post_id );

        set_transient( $key, $pool, self::CACHE_TTL_RECO );

        return $pool;
    }

    /**
     * @return int[]
     */
    private function build_similar_pool( int $post_id ): array {
        $terms = get_the_terms( $post_id, 'genre' );

        if ( ! is_array( $terms ) || empty( $terms ) ) return [];

        // 類型太多時只取前幾個，避免 tax_query 命中範圍過寬失去相似性。
        $genre_ids = array_slice(
            array_map( static fn( $t ) => (int) $t->term_id, $terms ),
            0,
            self::RECO_GENRE_MAX
        );

        $ids = get_posts( [
            'post_type'              => 'anime',
            'post_status'            => 'publish',
            'posts_per_page'         => self::RECO_POOL_MAX,
            'fields'                 => 'ids',
            'orderby'                => 'rand',
            'post__not_in'           => [ $post_id ],
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => [
                [
                    'taxonomy' => 'genre',
                    'field'    => 'term_id',
                    'terms'    => $genre_ids,
                ],
            ],
            'meta_query'             => self::exclude_unaired_meta_query(),
        ] );

        return array_map( 'intval', (array) $ids );
    }

    /**
     * 推薦一律排除尚未播出的作品。
     *
     * 推薦的用途是「現在就能去看」，未播出作品點進去沒有集數、多半也還沒有
     * 完整班底資料，放進推薦只會佔掉版位。
     *
     * 用 OR 併上 NOT EXISTS：meta_query 的 != 比較對「根本沒有這個 meta」的
     * 文章不會成立（JOIN 不到），少了這條會把缺 anime_status 的作品一起濾掉。
     */
    private static function exclude_unaired_meta_query(): array {
        return [
            'relation' => 'OR',
            [
                'key'     => 'anime_status',
                'value'   => 'NOT_YET_RELEASED',
                'compare' => '!=',
            ],
            [
                'key'     => 'anime_status',
                'compare' => 'NOT EXISTS',
            ],
        ];
    }

    /**
     * 候選池（含快取）。
     *
     * 快取整個候選池而非「本頁最終結果」，
     * 讓使用者在不同作品頁之間切換時共用同一份運算結果。
     */
    private function get_recommendation_pool( int $user_id ): array {
        $key    = "asp_reco_{$user_id}";
        $cached = get_transient( $key );

        if ( is_array( $cached ) ) return $cached;

        $pool = $this->build_recommendation_pool( $user_id );

        set_transient( $key, $pool, self::CACHE_TTL_RECO );

        return $pool;
    }

    /**
     * 實際運算候選池。
     */
    private function build_recommendation_pool( int $user_id ): array {
        $list = $this->get_user_list( $user_id );
        if ( empty( $list ) ) return [];

        $seen_ids  = [];
        $liked_ids = [];

        foreach ( $list as $entry ) {
            $aid = (int) ( $entry['anime_id'] ?? 0 );
            if ( ! $aid ) continue;

            // 追蹤過的一律排除，不再重複推薦。
            $seen_ids[] = $aid;

            // 但棄坑的作品不列入偏好推算，避免推出更多他不喜歡的類型。
            if ( 'dropped' === ( $entry['status'] ?? null ) ) continue;

            $liked_ids[] = $aid;
        }

        if ( empty( $liked_ids ) ) return [];

        // get_user_list() 已依 updated_at DESC 排序，
        // 只取最近的數筆推算偏好，避免清單過長時掃描成本失控。
        $sample_ids = array_slice( $liked_ids, 0, self::RECO_SAMPLE_MAX );

        $terms = wp_get_object_terms( $sample_ids, 'genre', [
            'fields' => 'all_with_object_id',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];

        // 統計各類型出現次數，取最常出現的前幾名。
        $genre_hits = [];
        foreach ( $terms as $term ) {
            $tid = (int) $term->term_id;
            $genre_hits[ $tid ] = ( $genre_hits[ $tid ] ?? 0 ) + 1;
        }

        arsort( $genre_hits );
        $top_genres = array_slice(
            array_keys( $genre_hits ),
            0,
            self::RECO_GENRE_MAX
        );

        if ( empty( $top_genres ) ) return [];

        $ids = get_posts( [
            'post_type'              => 'anime',
            'post_status'            => 'publish',
            'posts_per_page'         => self::RECO_POOL_MAX,
            'fields'                 => 'ids',
            'orderby'                => 'rand',
            'post__not_in'           => $seen_ids,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => [
                [
                    'taxonomy' => 'genre',
                    'field'    => 'term_id',
                    'terms'    => $top_genres,
                ],
            ],
            'meta_query'             => self::exclude_unaired_meta_query(),
        ] );

        return array_map( 'intval', (array) $ids );
    }

    /* ──────────────────────────────────────────────
     * 內部 helper
     * ────────────────────────────────────────────── */

    private function normalize_row( array $row ): array {
        $status_int = $row['status'];
        return [
            'status'       => ( $status_int !== null && isset( self::STATUS_REVERSE[ (int) $status_int ] ) )
                                ? self::STATUS_REVERSE[ (int) $status_int ] : null,
            'progress'     => (int) $row['progress'],
            'favorited'    => (bool) $row['favorited'],
            'fullcleared'  => (bool) $row['fullcleared'],
            'started_at'   => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'note'         => $row['note'] ?? null,
            'is_private'   => (bool) ( $row['is_private'] ?? 0 ),
            'created_at'   => $row['created_at'] ?? null,
            'updated_at'   => $row['updated_at'] ?? null,
        ];
    }

    private function empty_entry(): array {
        return [
            'status'       => null,
            'progress'     => 0,
            'favorited'    => false,
            'fullcleared'  => false,
            'started_at'   => null,
            'completed_at' => null,
            'note'         => null,
            'is_private'   => false,
            'created_at'   => null,
            'updated_at'   => null,
        ];
    }

    private function check_rate_limit( int $user_id ): bool {
        $key   = "asp_us_rate_{$user_id}";
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT_MAX ) return false;
        set_transient( $key, $count + 1, self::RATE_LIMIT_PERIOD );
        return true;
    }

    private function flush_cache( int $user_id, int $anime_id ): void {
        wp_cache_delete( "us_{$user_id}_{$anime_id}", self::CACHE_GROUP );
        wp_cache_delete( "us_list_{$user_id}",        self::CACHE_GROUP );
        delete_transient( 'smacg_stats_' . $user_id );

        // 追蹤清單一變動，個人化推薦的候選池就過期了
        // （新追的作品要從推薦中排除、偏好類型也可能改變）。
        delete_transient( "asp_reco_{$user_id}" );
    }
}
