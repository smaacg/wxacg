<?php
/**
 * 檔案名稱: includes/class-cron-manager.php
 * Cron Manager — 排程同步管理
 *
 * 修正紀錄：
 * - [v1.5.0] [Fix 未定檔期死結 + seasonYear 同步] 根治「動畫化確定但未定檔期」的作品
 *            開播日/播出年份永遠是舊資料的問題：
 *            背景：build_daily_queue() 的 NOT_YET_RELEASED 分支原本要求
 *            start_date 落在「未來 30 天 ~ 過去 30 天」窗口內才納入佇列。但未定
 *            檔期的作品 anime_start_date 為 0 / 空，永遠不符合窗口，導致它們
 *            從來不會被每小時 cron 碰到。等 AniList 之後公布了日期，cron 也
 *            掃不到、開播日與 seasonYear 永遠補不上，形成「要有日期才進佇列、
 *            要進佇列才能補日期」的死結。
 *            修正：
 *            (1) build_daily_queue() NOT_YET_RELEASED 分支新增：start_date <= 0
 *                （未定檔期）也一律納入監控，sort 設 0（同級最後）。有開播日者
 *                維持原本 30 天窗口邏輯不變。
 *            (2) fetch_anilist_dynamic() 的 GraphQL query 加入 season / seasonYear。
 *            (3) sync_dynamic_for_post() 新增 seasonYear / season 回寫區塊：
 *                AniList 提供非 0 年份 / 非空季度且未鎖定時自動寫入
 *                anime_season_year / anime_season，前台即可正常顯示。
 *            提醒：如需從後台鎖定播出年份 / 季度不被 cron 覆蓋，
 *            register_sync_control() 的 choices 需加入 anime_season_year /
 *            anime_season 兩個 key（本檔已支援 $is_locked 判斷）。
 * - [v1.4.9] [Fix 缺失方法] 補上遺失的 sync_episodes_for_post()：run_themes_episodes_update()
 *            的 _run_themes_episodes_inner() 每 15 分鐘會呼叫 $this->sync_episodes_for_post(
 *            $post_id )，但此方法先前未被定義，導致每次執行到第一部作品就
 *            Fatal Error（Call to undefined method），使當批（最多 20 部）主題曲＋
 *            集數同步全部中斷，佇列也無法正常往下推進。
 *            新增 sync_episodes_for_post()：
 *            (1) 讀取 anime_bangumi_id（相容 legacy bangumi_id），透過
 *                import_manager->fetch_episodes_only() 取得 Bangumi 集數列表。
 *            (2) 冷卻期沿用既有常數 COOLDOWN_EPISODES_RELEASING（放映中 6 小時）／
 *                COOLDOWN_EPISODES_FINISHED（完結 3 天），寫入新的
 *                anime_episodes_synced_at 追蹤時間。
 *            (3) 整欄鎖定：anime_locked_fields 含 anime_episodes_json 時完全不動
 *                （與 sync_themes_for_post()／enrich_anime_data() 一致）。
 *            (4) 單集鎖定：沿用 class-api-handler.php merge_episodes_respecting_locks()
 *                同一把 anime_episodes_locked_ids，鎖定的單集不覆蓋任何欄位。
 *            (5) 「只增不改／補空」：已存在的集數（以 Bangumi episode id 為唯一鍵）
 *                只補空欄位（name / name_cn / airdate），不覆蓋人工已填值；
 *                comment（吐槽數）與 ep 編號無人工編輯疑慮，直接同步更新；新集數
 *                直接加入，最後依 ep 排序寫回。
 * - [v1.4.8] [Score Backfill] 新增「完結作品評分回補」佇列機制，解決舊番/OVA/
 *            劇場版評分永久卡在 0 的問題：
 *            背景：build_daily_queue() 排除 MOVIE/OVA/SPECIAL 格式，且完結作品
 *            只納入「完結日 30 天內」的窗口，代表舊作品/特典 OVA/劇場版永遠不會
 *            被每小時 cron 碰到，一旦匯入當下 Jikan/Bangumi 剛好連不上（如 Jikan
 *            服務不穩定期），評分就永久卡 0，無人工介入不會恢復。
 *            新增：
 *            (1) build_score_backfill_queue()：每 30 天建立一次佇列，掃描「全部」
 *                已完結作品（不分格式、不限完結多久）中 anime_score_mal 或
 *                anime_score_bangumi 仍為 0 的項目。
 *            (2) run_score_backfill_batch()：搭現有每小時排程（HOOK_DAILY_SCORE_UPDATE）
 *                便車，不新增排程間隔。每次只吃一小批（預設 15 篇，可用選項
 *                anime_sync_score_backfill_batch_size 調整），避免對 Jikan/Bangumi
 *                造成負擔，也避免單次執行時間過長。
 *            (3) 重試上限（SCORE_BACKFILL_MAX_RETRY，預設 6 次＝約 6 個月）：
 *                超過上限的作品视为「該來源本身就沒有評分資料」，不再自動重試，
 *                避免每月白跑浪費 API 額度；成功補到分數後重試計數會重置。
 *            (4) 佇列會隨作品增加自然變大，處理速度由批次量與觸發頻率（每小時）
 *                共同決定，可隨著作品規模成長調高批次量因應。
 * - [v1.4.7] [BGM Score Retry] 新增 Bangumi 評分持續重試機制，根治新番
 *            Bangumi 評分永遠卡在 0 的問題（與 v1.4.5 MAL 評分重試對稱）：
 *            sync_dynamic_for_post() 新增區塊：只要目前 anime_score_bangumi
 *            仍為 0 且已有 anime_bangumi_id（相容 legacy bangumi_id），
 *            每小時 cron 就會呼叫 fetch_bgm_data_public() 重新抓 subject，
 *            從 rating.score 換算後寫入，直到 Bangumi 累積足夠票數、
 *            回傳非 0 分數為止。
 *            背景：anime_score_bangumi 原本只在匯入（get_core_anime_data /
 *            get_full_anime_data）或手動 AJAX resync 時寫入；enrich_anime_data()
 *            根本不碰 BGM 評分。新番匯入當下 Bangumi 尚未開放評分（score=0）時，
 *            之後沒有任何自動排程回頭補抓，前台因此永遠不顯示 BGM 評分。
 *            注意：get_bangumi_data() 有 12 小時 transient 快取
 *            （anime_sync_bgm_subject_{id}），重試會受快取影響，最壞情況
 *            延遲一個快取週期才補上，對每小時 cron 可接受。
 * - [v1.4.5] [MAL Score Retry] 新增 MAL 評分持續重試機制，根治新番 MAL 評分
 *            永遠卡在 0 的問題：
 *            (1) 新增 private Anime_Sync_API_Handler $api_handler 屬性，
 *                並在 constructor 實例化（沿用同一個 rate_limiter 實例）。
 *            (2) sync_dynamic_for_post() 新增區塊：只要目前 anime_score_mal
 *                仍為 0 且已有 anime_mal_id，每小時 cron 就會呼叫
 *                fetch_mal_score_public() 重新嘗試抓取，直到 MAL 累積足夠
 *                票數、Jikan 回傳非 0 分數為止才停止重試。
 *            背景：原本 MAL 評分只在「匯入後第一次背景 enrich」以及
 *            v1.4.4「idMal 從 0 補上時觸發的單次 enrich」這兩個時機點有機會
 *            寫入；若那兩次 Jikan 回傳的 score 剛好是 null（新番票數不足常見
 *            情況），就永遠不會再重試，前台因此不顯示 MAL 評分。
 * - [v1.4.4] [Fix MAL ID 補完] 新番匯入當下 AniList 常還沒串上 idMal，導致
 *            anime_mal_id 永遠卡在 0，MAL 評分也就永遠抓不到。新增：
 *            (1) fetch_anilist_dynamic() 的 GraphQL query 加入 idMal。
 *            (2) sync_dynamic_for_post() 每小時檢查一次：若目前沒有 mal_id，
 *                但 AniList 這次回傳了 idMal，就補上並觸發一次背景 enrich，
 *                讓 MAL 分數等資料自動補齊，不需要人工介入。
 * - [v1.4.3] [Start Date Auto-Fix] 新增「開播日自動回寫」機制，根治提早匯入導致的
 *            開播日不準問題：
 *            (1) fetch_anilist_dynamic() 的 GraphQL query 加入 startDate { year month day }。
 *            (2) sync_dynamic_for_post() 新增開播日回寫區塊，「僅在 AniList 提供完整
 *                年月日時」才更新 anime_start_date，避免 AniList 尚未定檔（只有年份）時
 *                被補成 YYYY0101 污染。提早匯入的作品，待 AniList 定檔後由每小時 cron
 *                自動補正為正確開播日，無需手動修改。
 * - [v1.4.2] [Fix 403] anilist_request() 補上 User-Agent 標頭，修復 cron 端
 *            （每日動態 sync / 季度匯入）AniList 請求因缺 UA 被 Cloudflare
 *            判定為機器人而回傳 HTTP 403 的問題。優先引用
 *            Anime_Sync_API_Handler::USER_AGENT 常數以維持單一真相來源；
 *            若該常數在 cron 情境未載入則退回固定瀏覽器風格 UA，避免 fatal。
 * - [v1.4.1] [Date Format Unify] 完結日統一為純數字 Ymd 格式：
 *            (1) sync_dynamic_for_post() 寫入 anime_end_date 由 'Y-m-d' 改回 'Ymd'
 *                純數字，與匯入原生格式及 anime_start_date 一致，消除格式污染源。
 *            (2) build_daily_queue() / build_themes_episodes_queue() 的完結日比較
 *                改用純數字（int）比對，與 start_date 判斷邏輯統一，避免帶橫線 vs
 *                純數字混比導致的字串比較地雷。
 * - [v1.4.0] [Themes Speedup] 集數同步排程間隔由「每小時」改為「每15分鐘」
 *            （新增 anime_sync_quarter_hour 排程），並將 THEMES_BATCH_SIZE 由 10 提到 20。
 *            140 部一輪由約 14 小時縮短至約 1.75 小時。daily 排程維持每小時不變。
 * - [v1.3.9] [New Anime Priority] build_daily_queue() 與 build_themes_episodes_queue()
 *            佇列改為「新番優先」排序：即將/剛開播(NOT_YET_RELEASED) > 放映中(RELEASING)
 *            > 剛完結(FINISHED)，同級再按開播日/完結日由新到舊。
 * - [v1.3.8] [Queue SQL Fix] 兩個佇列改用「簡單 IN 撈狀態 + PHP 過濾」，避開多層
 *            巢狀 meta_query 生成錯誤 SQL 回空的地雷。
 * - [v1.3.7] [New Anime Fix] 集數佇列新增 NOT_YET_RELEASED 分支並移除當季硬限制；
 *            daily 迴圈加入「開播日已到則本地兜底翻 RELEASING」。
 * - [v1.3.6] [Popularity Throttle] sync_dynamic_for_post() 人氣改為 24 小時低頻更新。
 * - [v1.3.5] [Smart NYR Queue] build_daily_queue() 即將開播番改用「開播日 30 天內」。
 * - [v1.3.4] 狀態篩選新增 NOT_YET_RELEASED（後由 v1.3.5 收斂）。
 * - [v1.3.2] 完結緩衝 30 天；排除 MOVIE / OVA / SPECIAL。
 * - [v1.3.1] activate() 加入排程間隔保護。
 * - [v1.3.0] 主題曲與集數同步加入冷卻期機制。
 * - [v1.2.9] 全面 Cron 優化（分批佇列、autoload=false option、佇列快取）。
 * - [v1.2.8] 任務一改每小時分批（DAILY_BATCH_SIZE=20）。
 * - [v1.2.5] 集數同步由「只增不改」改為「增＋補空／更新」。
 * - [v1.2.3] 新增 purge_post_cache()。
 * - [v1.2.0] 任務一改用 sync_dynamic_for_post()。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Cron_Manager {

    const HOOK_DAILY_SCORE_UPDATE     = 'anime_sync_daily_score_update';
    const HOOK_WEEKLY_CLEANUP         = 'anime_sync_weekly_cleanup';
    const HOOK_SEASON_IMPORT          = 'anime_sync_season_auto_import';
    const HOOK_UPDATE_MAP             = 'anime_sync_update_anime_map';
    const HOOK_THEMES_EPISODES_UPDATE = 'anime_sync_themes_episodes_update';

    const LOCK_TTL_DAILY           = 1800;
    const LOCK_TTL_SEASON          = 3600;
    const LOCK_TTL_THEMES_EPISODES = 1800;
    const LOCK_TTL_WEEKLY          = 600;

    const DAILY_QUEUE_OPTION = 'anime_sync_daily_queue';
    const DAILY_BATCH_SIZE   = 20;

    const THEMES_QUEUE_OPTION = 'anime_sync_themes_episodes_queue';
    // ✅ [v1.4.0] 集數批次由 10 提到 20
    const THEMES_BATCH_SIZE   = 20;

    const DAILY_QUEUE_CACHE_KEY = 'anime_sync_daily_queue_build';
    const DAILY_QUEUE_CACHE_TTL = 300;

    // ✅ [v1.3.5] 即將開播番納入監控的提前天數（每日動態佇列）
    const UPCOMING_WINDOW_DAYS = 30;

    // ✅ [v1.3.7] 主題曲＋集數同步的即將/剛開播窗口（開播日前後天數）
    const THEMES_UPCOMING_WINDOW_DAYS = 7;

    // ✅ [v1.3.6] 人氣低頻更新間隔
    const POPULARITY_SYNC_INTERVAL = DAY_IN_SECONDS;

    // ✅ [v1.3.0] 冷卻期常數（秒）
    const COOLDOWN_THEMES_RELEASING   = 3 * DAY_IN_SECONDS;
    const COOLDOWN_THEMES_FINISHED    = 7 * DAY_IN_SECONDS;
    const COOLDOWN_EPISODES_RELEASING = 6 * HOUR_IN_SECONDS;
    const COOLDOWN_EPISODES_FINISHED  = 3 * DAY_IN_SECONDS;

    // ✅ [v1.3.9] 佇列排序優先級（數字越小越優先）
    const PRIO_UPCOMING  = 0; // 即將/剛開播（NOT_YET_RELEASED）
    const PRIO_RELEASING = 1; // 放映中
    const PRIO_FINISHED  = 2; // 剛完結

    // ✅ [v1.4.2] cron 端 AniList 請求的退回 UA（當 API Handler 常數未載入時使用）
    const FALLBACK_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // ✅ [v1.4.8] 完結作品評分回補佇列
    const SCORE_BACKFILL_QUEUE_OPTION       = 'anime_sync_score_backfill_queue';
    const SCORE_BACKFILL_BATCH_SIZE_DEFAULT = 15;
    // 佇列每 30 天重新建立一次（掃描全站，範圍會隨作品增加自然變大）
    const SCORE_BACKFILL_REBUILD_INTERVAL   = 30 * DAY_IN_SECONDS;
    // 累積失敗達此次數（約等於試了這麼多個月），視為該來源本身無評分資料，不再自動重試
    const SCORE_BACKFILL_MAX_RETRY          = 6;
    const SCORE_RETRY_COUNT_META            = 'anime_score_retry_count';
    const LAST_SCORE_BACKFILL_BUILD_OPTION  = 'anime_sync_last_score_backfill_build';

    private Anime_Sync_Import_Manager $import_manager;
    private Anime_Sync_Error_Logger   $logger;
    private Anime_Sync_Rate_Limiter   $rate_limiter;

    // ✅ [v1.4.5] 新增：持有 API Handler 實例，供 MAL 評分重試呼叫
    //   fetch_mal_score_public()。沿用同一個 rate_limiter 實例，
    //   確保 Jikan 速率限制統計與其他呼叫共用同一份計數。
    //   ✅ [v1.4.7] 同一實例亦供 BGM 評分重試呼叫 fetch_bgm_data_public()。
    private Anime_Sync_API_Handler $api_handler;

    public function __construct( Anime_Sync_Import_Manager $import_manager ) {
        $this->import_manager = $import_manager;
        $this->logger         = new Anime_Sync_Error_Logger();
        $this->rate_limiter   = Anime_Sync_Rate_Limiter::get_instance();

        // ✅ [v1.4.5] 沿用同一個 rate_limiter 實例建立 API Handler
        $this->api_handler    = new Anime_Sync_API_Handler( $this->rate_limiter );

        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );

        add_action( self::HOOK_DAILY_SCORE_UPDATE,     [ $this, 'run_daily_score_update' ] );
        add_action( self::HOOK_WEEKLY_CLEANUP,         [ $this, 'run_weekly_cleanup' ] );
        add_action( self::HOOK_UPDATE_MAP,             [ $this, 'run_update_map' ] );
        add_action( self::HOOK_SEASON_IMPORT,          [ $this, 'run_season_auto_import' ], 10, 2 );
        add_action( self::HOOK_THEMES_EPISODES_UPDATE, [ $this, 'run_themes_episodes_update' ] );
    }

    // =========================================================================
    // 排程間隔
    // =========================================================================

    public static function get_custom_schedules(): array {
        return [
            'anime_sync_twice_daily' => [
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => __( 'Anime Sync: 每12小時', 'anime-sync-pro' ),
            ],
            'anime_sync_weekly' => [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Anime Sync: 每週', 'anime-sync-pro' ),
            ],
            'anime_sync_hourly' => [
                'interval' => HOUR_IN_SECONDS,
                'display'  => __( 'Anime Sync: 每小時', 'anime-sync-pro' ),
            ],
            // ✅ [v1.4.0] 新增 15 分鐘間隔，供集數同步加速使用
            'anime_sync_quarter_hour' => [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Anime Sync: 每15分鐘', 'anime-sync-pro' ),
            ],
        ];
    }

    public function add_custom_schedules( array $schedules ): array {
        return array_merge( $schedules, self::get_custom_schedules() );
    }

    // =========================================================================
    // 啟用／停用
    // =========================================================================

    public static function activate(): void {

        // ✅ [v1.3.1] 確保自訂排程間隔已被 WordPress 認識
        $existing_schedules = wp_get_schedules();
        if ( ! array_key_exists( 'anime_sync_weekly', $existing_schedules ) ||
             ! array_key_exists( 'anime_sync_hourly', $existing_schedules ) ||
             ! array_key_exists( 'anime_sync_quarter_hour', $existing_schedules ) ) {

            add_filter( 'cron_schedules', static function ( $schedules ) {
                return array_merge( (array) $schedules, self::get_custom_schedules() );
            } );
        }

        if ( ! wp_next_scheduled( self::HOOK_DAILY_SCORE_UPDATE ) ) {
            wp_schedule_event( time() + 300, 'anime_sync_hourly', self::HOOK_DAILY_SCORE_UPDATE );
        }

        if ( ! wp_next_scheduled( self::HOOK_THEMES_EPISODES_UPDATE ) ) {
            // ✅ [v1.4.0] 集數同步改用 15 分鐘間隔
            wp_schedule_event( time() + 600, 'anime_sync_quarter_hour', self::HOOK_THEMES_EPISODES_UPDATE );
        }

        if ( ! wp_next_scheduled( self::HOOK_WEEKLY_CLEANUP ) ) {
            wp_schedule_event(
                strtotime( 'next sunday 04:00:00' ),
                'anime_sync_weekly',
                self::HOOK_WEEKLY_CLEANUP
            );
        }

        if ( ! wp_next_scheduled( self::HOOK_UPDATE_MAP ) ) {
            wp_schedule_event(
                strtotime( 'next monday 02:00:00' ),
                'anime_sync_weekly',
                self::HOOK_UPDATE_MAP
            );
        }
    }

    public static function deactivate(): void {
        $hooks = [
            self::HOOK_DAILY_SCORE_UPDATE,
            self::HOOK_WEEKLY_CLEANUP,
            self::HOOK_SEASON_IMPORT,
            self::HOOK_UPDATE_MAP,
            self::HOOK_THEMES_EPISODES_UPDATE,
        ];
        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }

    public static function reschedule_all(): void {
        self::deactivate();
        self::activate();
    }

    // =========================================================================
    // ✅ [v1.2.9] 共用 helper：寫入 Cron 專用 option（autoload = false）
    // =========================================================================

    private static function update_cron_option( string $key, $value ): void {
        if ( get_option( $key ) === false ) {
            add_option( $key, $value, '', 'no' );
        } else {
            global $wpdb;
            $wpdb->update(
                $wpdb->options,
                [
                    'option_value' => maybe_serialize( $value ),
                    'autoload'     => 'no',
                ],
                [ 'option_name' => $key ]
            );
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( $key, 'options' );
        }
    }

    // =========================================================================
    // ✅ [v1.3.9] 共用 helper：依「新番優先」排序佇列並回傳 ID 陣列
    // =========================================================================
    private static function sort_queue_new_first( array $items ): array {
        usort( $items, static function ( $a, $b ) {
            if ( $a['prio'] !== $b['prio'] ) {
                return $a['prio'] <=> $b['prio'];
            }
            return $b['sort'] <=> $a['sort'];
        } );

        return array_map( 'intval', array_column( $items, 'id' ) );
    }

    // =========================================================================
    // ✅ [v1.4.2] 共用 helper：取得 AniList 請求用的 User-Agent
    //   優先使用 API Handler 的常數（單一真相來源）；未載入時退回固定 UA，避免 fatal。
    // =========================================================================
    private static function get_anilist_user_agent(): string {
        if ( class_exists( 'Anime_Sync_API_Handler' )
             && defined( 'Anime_Sync_API_Handler::USER_AGENT' ) ) {
            $ua = (string) constant( 'Anime_Sync_API_Handler::USER_AGENT' );
            if ( $ua !== '' ) {
                return $ua;
            }
        }
        return self::FALLBACK_USER_AGENT;
    }

    // =========================================================================
    // 共用：清除單篇文章快取 + 更新「最後更新」時間
    // =========================================================================

    private function purge_post_cache( int $post_id, bool $bump_modified = true ): void {
        if ( $post_id <= 0 ) {
            return;
        }

        if ( $bump_modified ) {
            $now     = current_time( 'mysql' );
            $now_gmt = current_time( 'mysql', true );

            wp_update_post( [
                'ID'                => $post_id,
                'post_modified'     => $now,
                'post_modified_gmt' => $now_gmt,
            ] );
        } elseif ( function_exists( 'clean_post_cache' ) ) {
            clean_post_cache( $post_id );
        }

        do_action( 'litespeed_purge_post', $post_id );
    }

    // =========================================================================
    // 任務一：每日動態欄位更新
    // =========================================================================

    public function run_daily_score_update(): void {
        if ( get_transient( 'anime_sync_lock_daily' ) ) {
            $this->logger->log( 'warning', '每日動態更新：已有另一個程序在執行，本次跳過' );
            return;
        }
        set_transient( 'anime_sync_lock_daily', 1, self::LOCK_TTL_DAILY );

        try {
            $this->_run_daily_score_update_inner();
            // ✅ [v1.4.8] 搭同一個每小時排程便車，處理完結作品評分回補佇列
            $this->run_score_backfill_batch();
        } finally {
            delete_transient( 'anime_sync_lock_daily' );
        }
    }

    private function _run_daily_score_update_inner(): void {
        Anime_Sync_Performance::set_time_limit( 300 );
        Anime_Sync_Performance::increase_memory_limit( '256M' );

        $queue = get_option( self::DAILY_QUEUE_OPTION, null );

        if ( ! is_array( $queue ) || empty( $queue ) ) {
            $queue = $this->build_daily_queue();

            if ( empty( $queue ) ) {
                $this->logger->log( 'info', '每日動態更新：目前無符合條件的作品，本輪結束' );
                self::update_cron_option( self::DAILY_QUEUE_OPTION, [] );
                return;
            }

            self::update_cron_option( 'anime_sync_daily_round_updated', 0 );
            self::update_cron_option( 'anime_sync_daily_round_skipped', 0 );
            self::update_cron_option( 'anime_sync_daily_round_failed',  0 );

            $this->logger->log( 'info', sprintf(
                '每日動態更新：開始新一輪，共 %d 部待處理（每批 %d 部）',
                count( $queue ),
                self::DAILY_BATCH_SIZE
            ) );
        }

        $batch_size = (int) get_option( 'anime_sync_daily_batch_size', self::DAILY_BATCH_SIZE );
        if ( $batch_size <= 0 ) {
            $batch_size = self::DAILY_BATCH_SIZE;
        }

        $batch     = array_slice( $queue, 0, $batch_size );
        $remaining = array_slice( $queue, $batch_size );

        $updated = (int) get_option( 'anime_sync_daily_round_updated', 0 );
        $skipped = (int) get_option( 'anime_sync_daily_round_skipped', 0 );
        $failed  = (int) get_option( 'anime_sync_daily_round_failed',  0 );

        foreach ( $batch as $post_id ) {
            $post_id = (int) $post_id;

            if ( get_post_status( $post_id ) !== 'publish' ) {
                continue;
            }

            $anilist_id = (int) get_post_meta( $post_id, 'anime_anilist_id', true );
            if ( ! $anilist_id ) {
                continue;
            }

            $post_title = get_the_title( $post_id ) ?: "ID {$post_id}";

            $this->rate_limiter->wait_if_needed( 'anilist' );

            $result = $this->sync_dynamic_for_post( $post_id, $anilist_id );

            if ( $result === 'failed' ) {
                // ✅ [v1.3.7] 兜底：API 失敗時，若開播日已到但狀態仍為 NOT_YET_RELEASED，本地強制翻 RELEASING
                $cur_status = (string) get_post_meta( $post_id, 'anime_status', true );
                $start_raw  = (string) get_post_meta( $post_id, 'anime_start_date', true );
                if ( $cur_status === 'NOT_YET_RELEASED'
                     && ctype_digit( $start_raw )
                     && (int) $start_raw <= (int) gmdate( 'Ymd' ) ) {
                    update_post_meta( $post_id, 'anime_status', 'RELEASING' );
                    $updated++;
                    $this->logger->log( 'info', "每日動態更新〔{$post_title}〕：API 失敗，依開播日兜底翻 RELEASING", [
                        'post_id'    => $post_id,
                        'anilist_id' => $anilist_id,
                    ] );
                    $this->purge_post_cache( $post_id );
                } else {
                    $failed++;
                    $this->logger->log( 'warning', "每日動態更新〔{$post_title}〕：失敗", [
                        'post_id'    => $post_id,
                        'anilist_id' => $anilist_id,
                    ] );
                }
            } elseif ( $result === 'skipped' ) {
                $skipped++;
                $this->logger->log( 'info', "每日動態更新〔{$post_title}〕：無變動" );
            } else {
                $updated++;
                $detail = str_contains( $result, ':' ) ? '（' . explode( ':', $result, 2 )[1] . '）' : '';
                $this->logger->log( 'info', "每日動態更新〔{$post_title}〕：已更新{$detail}" );
                $this->purge_post_cache( $post_id );
            }
        }

        self::update_cron_option( self::DAILY_QUEUE_OPTION, array_values( $remaining ) );
        self::update_cron_option( 'anime_sync_daily_round_updated', $updated );
        self::update_cron_option( 'anime_sync_daily_round_skipped', $skipped );
        self::update_cron_option( 'anime_sync_daily_round_failed',  $failed );

        if ( empty( $remaining ) ) {
            $this->logger->log( 'info', sprintf(
                '每日動態更新完成（整輪）：更新 %d / 略過 %d / 失敗 %d',
                $updated,
                $skipped,
                $failed
            ) );
            self::update_cron_option( 'anime_sync_last_daily_run', current_time( 'mysql' ) );
        } else {
            $this->logger->log( 'info', sprintf(
                '每日動態更新：本批處理完成，剩餘 %d 部待下批處理',
                count( $remaining )
            ) );
        }
    }

    /**
     * 建立每日待處理佇列。
     * ✅ [v1.5.0] NOT_YET_RELEASED 且尚無開播日（start_date=0）者也納入監控,
     *   修正「未定檔期作品永遠進不了佇列 → seasonYear/開播日永遠補不上」的死結。
     * ✅ [v1.4.1] 完結日比較改用純數字（int），與 start_date 判斷統一。
     * ✅ [v1.3.9] 新番優先排序（即將開播 > 放映中 > 剛完結，同級按日期由新到舊）。
     * ✅ [v1.3.8] 簡單 IN 撈狀態 + PHP 過濾，避免多層巢狀 meta_query 生成錯誤 SQL。
     *   - 排除 MOVIE / OVA / SPECIAL 格式（僅限已完結）。
     *   - RELEASING：全納入。
     *   - NOT_YET_RELEASED：開播日在未來 30 天內，或尚未定檔（start_date=0）。
     *   - FINISHED：完結日在 30 天內。
     * ✅ [v1.2.9] 5 分鐘短效快取，防止重複觸發時的多餘 DB full scan。
     */
    private function build_daily_queue(): array {
        $cached = get_transient( self::DAILY_QUEUE_CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        // ✅ [v1.4.1] 完結日 cutoff 改用純數字 Ymd
        $cutoff_ymd = (int) gmdate( 'Ymd', strtotime( '-30 days' ) );
        $today_ymd  = (int) gmdate( 'Ymd' );
        $window_ymd = (int) gmdate( 'Ymd', strtotime( '+' . self::UPCOMING_WINDOW_DAYS . ' days' ) );

        $candidate_ids = get_posts( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'anime_status',
                    'value'   => [ 'RELEASING', 'NOT_YET_RELEASED', 'FINISHED' ],
                    'compare' => 'IN',
                ],
            ],
        ] );

        $excluded_formats = [ 'MOVIE', 'OVA', 'SPECIAL' ];

        $items = [];
        foreach ( $candidate_ids as $id ) {

            $id = (int) $id;

            $status = (string) get_post_meta( $id, 'anime_status', true );

            // ✅ 只排除「已完結」的 MOVIE / OVA / SPECIAL（狀態不會再變）；
            //    尚未播出 / 放映中的特別篇、劇場版、OVA 仍需納入，才能讓
            //    開播即完結的單集作品狀態被 cron 自動翻正。
            $format = (string) get_post_meta( $id, 'anime_format', true );
            if ( $status === 'FINISHED'
                 && in_array( $format, $excluded_formats, true ) ) {
                continue;
            }

            if ( $status === 'RELEASING' ) {
                $start   = (int) get_post_meta( $id, 'anime_start_date', true );
                $items[] = [ 'id' => $id, 'prio' => self::PRIO_RELEASING, 'sort' => $start ];
            } elseif ( $status === 'NOT_YET_RELEASED' ) {
                $start = (int) get_post_meta( $id, 'anime_start_date', true );
                // ✅ [v1.5.0] 未定檔期（start_date 為 0 或空）也一律納入監控，
                //    否則 AniList 之後定檔了 cron 也永遠掃不到、開播日/seasonYear
                //    永遠補不上（死結）。sort 設 0，排在同級（PRIO_UPCOMING）最後。
                //    有開播日者維持原本 30 天窗口邏輯不變。
                if ( $start <= 0 ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_UPCOMING, 'sort' => 0 ];
                } elseif ( $start <= $window_ymd && $start >= $cutoff_ymd ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_UPCOMING, 'sort' => $start ];
                }
            } elseif ( $status === 'FINISHED' ) {
                // ✅ [v1.4.1] 純數字比較
                $end = (int) get_post_meta( $id, 'anime_end_date', true );
                if ( $end > 0 && $end >= $cutoff_ymd ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_FINISHED, 'sort' => $end ];
                }
            }
        }

        $result = self::sort_queue_new_first( $items );

        set_transient( self::DAILY_QUEUE_CACHE_KEY, $result, self::DAILY_QUEUE_CACHE_TTL );

        return $result;
    }

    /**
     * @return string 'updated' | 'skipped' | 'failed'
     */
    private function sync_dynamic_for_post( int $post_id, int $anilist_id ): string {

        $media = $this->fetch_anilist_dynamic( $anilist_id );
        if ( $media === null ) {
            $this->logger->log( 'warning', '每日動態更新：AniList 查詢失敗', [
                'post_id'    => $post_id,
                'anilist_id' => $anilist_id,
            ] );
            return 'failed';
        }

        $locked = get_post_meta( $post_id, 'anime_locked_fields', true );
        if ( ! is_array( $locked ) ) {
            $locked = [];
        }
        $is_locked = static fn( string $key ): bool => in_array( $key, $locked, true );

        $diff = [];

        // 狀態
        if ( ! $is_locked( 'anime_status' ) && isset( $media['status'] ) && $media['status'] !== '' ) {
            $old_val = (string) get_post_meta( $post_id, 'anime_status', true );
            $new_val = (string) $media['status'];
            if ( $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_status', $new_val );
                $diff[] = '狀態 ' . $old_val . '→' . $new_val;
            }
        }

        // ✅ [v1.4.4] MAL ID 補完：新番匯入當下 AniList 常還沒串上 idMal，導致
        //   anime_mal_id 永遠卡在 0，MAL 評分也就永遠抓不到。這裡每小時
        //   檢查一次：若目前沒有 mal_id，但 AniList 這次回傳了 idMal，就補上
        //   並觸發一次背景 enrich，讓 MAL 分數等資料自動補齊，不需要
        //   人工介入。
        if ( ! $is_locked( 'anime_mal_id' ) ) {
            $current_mal_id = (int) get_post_meta( $post_id, 'anime_mal_id', true );
            $new_mal_id     = isset( $media['idMal'] ) && $media['idMal'] !== null ? (int) $media['idMal'] : 0;

            if ( $current_mal_id <= 0 && $new_mal_id > 0 ) {
                update_post_meta( $post_id, 'anime_mal_id', $new_mal_id );
                $diff[] = 'MAL ID 補上 ' . $new_mal_id;

                // 觸發一次背景 enrich，讓 MAL 分數 / AnimeThemes 主題曲趁機一起補上
                delete_post_meta( $post_id, '_enriched_at' );
                if ( ! wp_next_scheduled( 'anime_sync_enrich_post', [ $post_id ] ) ) {
                    wp_schedule_single_event( time() + 60, 'anime_sync_enrich_post', [ $post_id ] );
                }
            }
        }

        // ✅ [v1.4.5] MAL 評分持續重試：只要目前仍是 0 分且已有 mal_id，
        //   每小時 cron 就會再嘗試抓一次，直到 Jikan 回傳非 0 分數為止。
        //   解決新番剛匯入時 MAL 票數不足、fetch_mal_score() 回傳 0 導致
        //   enrich 階段沒寫入、之後又沒有任何排程重試的問題。
        //   （放映中/即將播出的作品專用；完結作品的長期重試改由
        //   v1.4.8 的 run_score_backfill_batch() 負責，兩者不衝突。）
        if ( ! $is_locked( 'anime_score_mal' ) ) {
            $mal_id_for_score = (int) get_post_meta( $post_id, 'anime_mal_id', true );
            $current_mal_score = (int) get_post_meta( $post_id, 'anime_score_mal', true );

            if ( $mal_id_for_score > 0 && $current_mal_score <= 0 ) {
                $this->rate_limiter->wait_if_needed( 'jikan' );
                $new_mal_score = $this->api_handler->fetch_mal_score_public( $mal_id_for_score );

                if ( $new_mal_score > 0 ) {
                    update_post_meta( $post_id, 'anime_score_mal', $new_mal_score );
                    $diff[] = 'MAL評分 0→' . $new_mal_score;
                }
            }
        }

        // ✅ [v1.4.7] Bangumi 評分持續重試：只要目前仍是 0 分且已有 bangumi_id，
        //   每小時 cron 就會再抓一次 subject，從 rating.score 換算後寫入，直到
        //   Bangumi 累積足夠票數、回傳非 0 分數為止。（與 v1.4.5 MAL 評分重試對稱。）
        //   解決新番匯入時 Bangumi 尚未開放評分（score=0），之後又沒有任何排程
        //   回頭補抓（enrich_anime_data() 不碰 BGM 評分），導致前台永遠不顯示
        //   BGM 評分的問題。
        //   注意：get_bangumi_data() 有 12 小時 transient 快取，重試會受快取影響，
        //   最壞情況延遲一個快取週期才補上，對每小時 cron 可接受。
        if ( ! $is_locked( 'anime_score_bangumi' ) ) {
            $bgm_id_for_score = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
            if ( $bgm_id_for_score <= 0 ) {
                // 相容 legacy key
                $bgm_id_for_score = (int) get_post_meta( $post_id, 'bangumi_id', true );
            }
            $current_bgm_score = (int) get_post_meta( $post_id, 'anime_score_bangumi', true );

            if ( $bgm_id_for_score > 0 && $current_bgm_score <= 0 ) {
                $this->rate_limiter->wait_if_needed( 'bangumi' );
                $bgm_subject = $this->api_handler->fetch_bgm_data_public( $bgm_id_for_score );

                if ( ! is_wp_error( $bgm_subject ) && is_array( $bgm_subject ) ) {
                    $raw = $bgm_subject['rating']['score'] ?? $bgm_subject['score'] ?? null;
                    if ( $raw !== null ) {
                        $new_bgm_score = (int) round( (float) $raw * 10 );
                        if ( $new_bgm_score > 0 ) {
                            update_post_meta( $post_id, 'anime_score_bangumi', $new_bgm_score );
                            $diff[] = 'BGM評分 0→' . $new_bgm_score;
                        }
                    }
                }
            }
        }

        // 已播集數
        if ( ! $is_locked( 'anime_episodes_aired' ) ) {
            $aired = null;
            if ( isset( $media['nextAiringEpisode']['episode'] ) ) {
                $aired = max( 0, (int) $media['nextAiringEpisode']['episode'] - 1 );
            } elseif ( ( $media['status'] ?? '' ) === 'FINISHED' && ! empty( $media['episodes'] ) ) {
                $aired = (int) $media['episodes'];
            }
            if ( $aired !== null ) {
                $old_val = (int) get_post_meta( $post_id, 'anime_episodes_aired', true );
                if ( $old_val !== $aired ) {
                    update_post_meta( $post_id, 'anime_episodes_aired', $aired );
                    $diff[] = '已播 ' . $old_val . '→' . $aired . '集';
                }
            }
        }

        // 下次播出時間
        if ( ! $is_locked( 'anime_next_airing' ) ) {
            if ( isset( $media['nextAiringEpisode']['airingAt'] ) ) {
                update_post_meta( $post_id, 'anime_next_airing', (int) $media['nextAiringEpisode']['airingAt'] );
            } else {
                delete_post_meta( $post_id, 'anime_next_airing' );
            }
        }

        // 評分（維持每小時同步 — 核心欄位）
        if ( ! $is_locked( 'anime_score_anilist' ) && isset( $media['averageScore'] ) && $media['averageScore'] !== null ) {
            $old_val = (int) get_post_meta( $post_id, 'anime_score_anilist', true );
            $new_val = (int) $media['averageScore'];
            if ( $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_score_anilist', $new_val );
                $diff[] = 'AniList評分 ' . $old_val . '→' . $new_val;
            }
        }

        // ✅ [v1.3.6] 人氣：低頻更新（每 24 小時最多一次）
        if ( ! $is_locked( 'anime_popularity' ) && isset( $media['popularity'] ) && $media['popularity'] !== null ) {
            $pop_last_sync = (string) get_post_meta( $post_id, 'anime_popularity_synced_at', true );
            $pop_due       = ( $pop_last_sync === '' )
                || ( ( time() - strtotime( $pop_last_sync ) ) >= self::POPULARITY_SYNC_INTERVAL );

            if ( $pop_due ) {
                $old_val = (int) get_post_meta( $post_id, 'anime_popularity', true );
                $new_val = (int) $media['popularity'];
                if ( $old_val !== $new_val ) {
                    update_post_meta( $post_id, 'anime_popularity', $new_val );
                    $diff[] = '人氣 ' . $old_val . '→' . $new_val;
                }
                update_post_meta( $post_id, 'anime_popularity_synced_at', current_time( 'mysql' ) );
            }
        }

        // ✅ [v1.5.0] 播出年份 seasonYear：AniList 之後補上年份時自動回寫。
        //   前台 single-anime.php 對 0 視為「尚未公布」，補上後即正常顯示。
        //   僅在 AniList 回傳非 0 年份且未鎖定時才寫入，避免把已有年份覆蓋成 0。
        if ( ! $is_locked( 'anime_season_year' )
             && isset( $media['seasonYear'] )
             && $media['seasonYear'] !== null ) {
            $old_val = (int) get_post_meta( $post_id, 'anime_season_year', true );
            $new_val = (int) $media['seasonYear'];
            if ( $new_val > 0 && $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_season_year', $new_val );
                $diff[] = '播出年份 ' . $old_val . '→' . $new_val;
            }
        }

        // ✅ [v1.5.0] 季度 season（WINTER / SPRING / SUMMER / FALL）
        if ( ! $is_locked( 'anime_season' )
             && isset( $media['season'] )
             && $media['season'] !== null
             && $media['season'] !== '' ) {
            $old_val = (string) get_post_meta( $post_id, 'anime_season', true );
            $new_val = (string) $media['season'];
            if ( $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_season', $new_val );
                $diff[] = '季度 ' . $old_val . '→' . $new_val;
            }
        }

        // ✅ [v1.4.3] 開播日：僅在 AniList 提供完整年月日時才回寫，避免尚未定檔（只有年份）
        //   時被補成 YYYY0101 污染。提早匯入的作品待 AniList 定檔後由每小時 cron 自動補正。
        if ( ! $is_locked( 'anime_start_date' )
             && ! empty( $media['startDate']['year'] )
             && ! empty( $media['startDate']['month'] )
             && ! empty( $media['startDate']['day'] ) ) {
            $start_date = sprintf(
                '%04d%02d%02d',
                (int) $media['startDate']['year'],
                (int) $media['startDate']['month'],
                (int) $media['startDate']['day']
            );
            $old_val = (string) get_post_meta( $post_id, 'anime_start_date', true );
            if ( $old_val !== $start_date ) {
                update_post_meta( $post_id, 'anime_start_date', $start_date );
                $diff[] = '開播日 ' . $old_val . '→' . $start_date;
            }
        }

        // 完結日
        // ✅ [v1.4.1] 寫入純數字 Ymd 格式（與匯入原生格式及 start_date 一致），不再污染成帶橫線
        if ( ! $is_locked( 'anime_end_date' ) && ! empty( $media['endDate']['year'] ) ) {
            $end_date = sprintf(
                '%04d%02d%02d',
                (int) $media['endDate']['year'],
                (int) ( $media['endDate']['month'] ?? 1 ),
                (int) ( $media['endDate']['day'] ?? 1 )
            );
            $old_val = (string) get_post_meta( $post_id, 'anime_end_date', true );
            if ( $old_val !== $end_date ) {
                update_post_meta( $post_id, 'anime_end_date', $end_date );
                $diff[] = '完結日 ' . $old_val . '→' . $end_date;
            }
        }

        update_post_meta( $post_id, 'anime_dynamic_synced_at', current_time( 'mysql' ) );

        if ( empty( $diff ) ) {
            return 'skipped';
        }
        return 'updated:' . implode( '、', $diff );
    }

    private function fetch_anilist_dynamic( int $anilist_id ): ?array {
        // ✅ [v1.5.0] 加入 season / seasonYear，供未定檔期作品定檔後自動回寫播出年份/季度。
        $query = <<<'GQL'
        query ($id: Int) {
            Media(id: $id, type: ANIME) {
                idMal
                status
                episodes
                averageScore
                popularity
                season
                seasonYear
                startDate { year month day }
                endDate { year month day }
                nextAiringEpisode { airingAt episode }
            }
        }
        GQL;

        $body = $this->anilist_request( $query, [ 'id' => $anilist_id ] );
        if ( $body === null ) {
            return null;
        }

        $media = $body['data']['Media'] ?? null;
        return is_array( $media ) ? $media : null;
    }

    // =========================================================================
    // ✅ [v1.4.8] 任務六：完結作品評分回補（AniList/MAL/Bangumi）
    //
    // 動機：build_daily_queue() 只納入「完結日 30 天內」且非 MOVIE/OVA/SPECIAL
    // 的作品，代表大量舊番、特典 OVA、劇場版永遠不會被每小時 cron 碰到。若匯入
    // 當下 Jikan/Bangumi 剛好連不上（服務不穩定期），評分會永久卡 0，無人工介入
    // 不會恢復。這裡新增一個獨立的長週期佇列，範圍涵蓋「全部」已完結作品，不分
    // 格式、不限完結多久，但頻率壓低（30天建一次佇列、每小時只吃一小批），
    // 兼顧「久久同步一次」與「規模擴大後仍可控」兩個目標。
    // =========================================================================

    /**
     * 搭現有每小時排程（run_daily_score_update）便車執行，不新增排程間隔。
     * 每次只處理一小批，批次大小可用選項 anime_sync_score_backfill_batch_size
     * 調整（預設 15），作品規模變大時只需調高此選項，無需改程式碼或排程頻率。
     */
    private function run_score_backfill_batch(): void {
        $queue      = get_option( self::SCORE_BACKFILL_QUEUE_OPTION, null );
        $last_build = (int) get_option( self::LAST_SCORE_BACKFILL_BUILD_OPTION, 0 );
        $build_due  = ( time() - $last_build ) >= self::SCORE_BACKFILL_REBUILD_INTERVAL;

        // 佇列不存在，或已清空且到了重建週期 → 重新掃描全站建立新一輪佇列
        if ( ! is_array( $queue ) || ( empty( $queue ) && $build_due ) ) {
            $queue = $this->build_score_backfill_queue();
            self::update_cron_option( self::LAST_SCORE_BACKFILL_BUILD_OPTION, time() );

            if ( empty( $queue ) ) {
                $this->logger->log( 'info', '評分回補：目前無待補評分的完結作品，本輪結束' );
                self::update_cron_option( self::SCORE_BACKFILL_QUEUE_OPTION, [] );
                return;
            }

            $this->logger->log( 'info', sprintf(
                '評分回補：建立新一輪佇列，共 %d 部完結作品待補評分',
                count( $queue )
            ) );
        }

        // 佇列已空但尚未到重建週期 → 這次無事可做，安靜結束
        if ( empty( $queue ) ) {
            return;
        }

        $batch_size = (int) get_option( 'anime_sync_score_backfill_batch_size', self::SCORE_BACKFILL_BATCH_SIZE_DEFAULT );
        if ( $batch_size <= 0 ) {
            $batch_size = self::SCORE_BACKFILL_BATCH_SIZE_DEFAULT;
        }

        $batch     = array_slice( $queue, 0, $batch_size );
        $remaining = array_slice( $queue, $batch_size );

        foreach ( $batch as $post_id ) {
            $post_id = (int) $post_id;
            if ( get_post_status( $post_id ) !== 'publish' ) {
                continue;
            }
            $this->backfill_score_for_post( $post_id );
        }

        self::update_cron_option( self::SCORE_BACKFILL_QUEUE_OPTION, array_values( $remaining ) );

        if ( empty( $remaining ) ) {
            $this->logger->log( 'info', '評分回補：本輪佇列已全部處理完成' );
        } else {
            $this->logger->log( 'info', sprintf(
                '評分回補：本批完成，剩餘 %d 部待下次處理',
                count( $remaining )
            ) );
        }
    }

    /**
     * 掃描全站「已完結」作品中，MAL 或 Bangumi 評分仍為 0 的項目。
     * 不受格式（MOVIE/OVA/SPECIAL）限制、不受完結多久限制。
     * 累積重試已達上限（SCORE_BACKFILL_MAX_RETRY）的項目會被跳過，
     * 視為該來源本身沒有評分資料，不再浪費 API 額度。
     */
    private function build_score_backfill_queue(): array {
        $candidate_ids = get_posts( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'anime_status',
                    'value'   => 'FINISHED',
                    'compare' => '=',
                ],
            ],
        ] );

        $items = [];
        foreach ( $candidate_ids as $id ) {
            $id = (int) $id;

            $retry = (int) get_post_meta( $id, self::SCORE_RETRY_COUNT_META, true );
            if ( $retry >= self::SCORE_BACKFILL_MAX_RETRY ) {
                continue;
            }

            $locked = get_post_meta( $id, 'anime_locked_fields', true );
            if ( ! is_array( $locked ) ) {
                $locked = [];
            }

            $mal_id = (int) get_post_meta( $id, 'anime_mal_id', true );
            $bgm_id = (int) get_post_meta( $id, 'anime_bangumi_id', true );
            if ( $bgm_id <= 0 ) {
                $bgm_id = (int) get_post_meta( $id, 'bangumi_id', true );
            }

            $needs_mal = $mal_id > 0
                && ! in_array( 'anime_score_mal', $locked, true )
                && (int) get_post_meta( $id, 'anime_score_mal', true ) <= 0;

            $needs_bgm = $bgm_id > 0
                && ! in_array( 'anime_score_bangumi', $locked, true )
                && (int) get_post_meta( $id, 'anime_score_bangumi', true ) <= 0;

            if ( $needs_mal || $needs_bgm ) {
                $items[] = $id;
            }
        }

        return $items;
    }

    /**
     * 針對單篇文章嘗試補上 MAL / Bangumi 評分。
     * 成功補到任一分數即重置重試計數；全部失敗則重試計數 +1。
     */
    private function backfill_score_for_post( int $post_id ): void {
        $locked = get_post_meta( $post_id, 'anime_locked_fields', true );
        if ( ! is_array( $locked ) ) {
            $locked = [];
        }

        $got_something = false;

        $mal_id      = (int) get_post_meta( $post_id, 'anime_mal_id', true );
        $current_mal = (int) get_post_meta( $post_id, 'anime_score_mal', true );

        if ( $mal_id > 0 && $current_mal <= 0 && ! in_array( 'anime_score_mal', $locked, true ) ) {
            $this->rate_limiter->wait_if_needed( 'jikan' );
            $score = $this->api_handler->fetch_mal_score_public( $mal_id );
            if ( $score > 0 ) {
                update_post_meta( $post_id, 'anime_score_mal', $score );
                $got_something = true;
            }
        }

        $bgm_id = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
        if ( $bgm_id <= 0 ) {
            $bgm_id = (int) get_post_meta( $post_id, 'bangumi_id', true );
        }
        $current_bgm = (int) get_post_meta( $post_id, 'anime_score_bangumi', true );

        if ( $bgm_id > 0 && $current_bgm <= 0 && ! in_array( 'anime_score_bangumi', $locked, true ) ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $subject = $this->api_handler->fetch_bgm_data_public( $bgm_id );
            if ( ! is_wp_error( $subject ) && is_array( $subject ) ) {
                $raw = $subject['rating']['score'] ?? $subject['score'] ?? null;
                if ( $raw !== null ) {
                    $new_score = (int) round( (float) $raw * 10 );
                    if ( $new_score > 0 ) {
                        update_post_meta( $post_id, 'anime_score_bangumi', $new_score );
                        $got_something = true;
                    }
                }
            }
        }

        if ( $got_something ) {
            delete_post_meta( $post_id, self::SCORE_RETRY_COUNT_META );
            $this->purge_post_cache( $post_id );
            $title = get_the_title( $post_id ) ?: "ID {$post_id}";
            $this->logger->log( 'info', "評分回補〔{$title}〕：補上評分" );
        } else {
            $retry = (int) get_post_meta( $post_id, self::SCORE_RETRY_COUNT_META, true );
            update_post_meta( $post_id, self::SCORE_RETRY_COUNT_META, $retry + 1 );
        }
    }

    // =========================================================================
    // 任務二：每週清理
    // =========================================================================

    public function run_weekly_cleanup(): void {
        if ( get_transient( 'anime_sync_lock_weekly' ) ) {
            $this->logger->log( 'warning', '每週清理：已有另一個程序在執行，本次跳過' );
            return;
        }
        set_transient( 'anime_sync_lock_weekly', 1, self::LOCK_TTL_WEEKLY );

        try {
            $this->_run_weekly_cleanup_inner();
        } finally {
            delete_transient( 'anime_sync_lock_weekly' );
        }
    }

    private function _run_weekly_cleanup_inner(): void {
        $this->logger->log( 'info', '每週清理開始' );

        $retention_days = (int) get_option( 'anime_sync_log_retention_days', 30 );
        $deleted_logs   = $this->logger->delete_old_logs( $retention_days );
        $this->logger->log( 'info', "已清除 {$deleted_logs} 筆舊日誌" );

        Anime_Sync_Performance::clear_all_caches();

        global $wpdb;

        if ( ! get_transient( 'anime_sync_lock_daily' ) &&
             ! get_transient( 'anime_sync_lock_themes_episodes' ) ) {

            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_anime_sync_last_request_%'
                    OR option_name LIKE '_transient_timeout_anime_sync_last_request_%'
                    OR option_name LIKE '_transient_anime_sync_import_lock_%'
                    OR option_name LIKE '_transient_timeout_anime_sync_import_lock_%'"
            );

            $this->logger->log( 'info', '已清除 Rate Limiter Transient' );
        } else {
            $this->logger->log( 'info', '每週清理：偵測到批次任務正在執行，跳過 Rate Limiter Transient 清理' );
        }

        $this->logger->log( 'info', '每週清理完成' );
        self::update_cron_option( 'anime_sync_last_weekly_cleanup', current_time( 'mysql' ) );
    }

    // =========================================================================
    // 任務三：季度自動匯入
    // =========================================================================

    public function run_season_auto_import( string $season = '', int $year = 0 ): array {
        if ( get_transient( 'anime_sync_lock_season' ) ) {
            $this->logger->log( 'warning', '季度匯入：已有另一個程序在執行，本次跳過' );
            return [ 'success' => false, 'message' => '已鎖定，跳過', 'imported' => 0 ];
        }
        set_transient( 'anime_sync_lock_season', 1, self::LOCK_TTL_SEASON );

        try {
            return $this->_run_season_import_inner( $season, $year );
        } finally {
            delete_transient( 'anime_sync_lock_season' );
        }
    }

    private function _run_season_import_inner( string $season, int $year ): array {
        Anime_Sync_Performance::set_time_limit( 600 );
        Anime_Sync_Performance::increase_memory_limit( '512M' );

        if ( empty( $season ) || $year === 0 ) {
            [ $season, $year ] = $this->get_current_season();
        }

        $this->logger->log( 'info', "季度匯入開始：{$year} {$season}" );

        $media_list = $this->fetch_season_list( $season, $year );

        if ( empty( $media_list ) ) {
            $this->logger->log( 'warning', "季度匯入：{$year} {$season} 無資料" );
            return [ 'success' => false, 'message' => '無資料', 'imported' => 0 ];
        }

        $imported = 0;
        $skipped  = 0;
        $failed   = 0;

        Anime_Sync_Performance::batch_process(
            $media_list,
            function( array $media ) use ( &$imported, &$skipped, &$failed ): void {
                $anilist_id = (int) ( $media['id'] ?? 0 );
                if ( ! $anilist_id ) return;

                $this->rate_limiter->wait_if_needed( 'anilist' );

                $result = $this->import_manager->import_single( $anilist_id, null, 'anilist' );

                if ( ! empty( $result['skipped'] ) ) {
                    $skipped++;
                } elseif ( ! empty( $result['success'] ) ) {
                    $imported++;
                } else {
                    $failed++;
                    $this->logger->log( 'warning', '季度匯入單筆失敗', [
                        'anilist_id' => $anilist_id,
                        'error'      => $result['message'] ?? '未知錯誤',
                    ] );
                }
            },
            15
        );

        $summary = [
            'success'  => true,
            'season'   => $season,
            'year'     => $year,
            'total'    => count( $media_list ),
            'imported' => $imported,
            'skipped'  => $skipped,
            'failed'   => $failed,
        ];

        $this->logger->log( 'info', '季度匯入完成', $summary );
        return $summary;
    }

    // =========================================================================
    // 任務四：Bangumi ID 地圖更新
    // =========================================================================

    public function run_update_map(): void {
        $this->logger->log( 'info', 'Bangumi ID 地圖更新開始' );

        $mapper = new Anime_Sync_ID_Mapper();
        $result = $mapper->download_and_cache_map();

        if ( $result ) {
            $this->logger->log( 'info', 'Bangumi ID 地圖更新成功，寫入 ' . $result . ' bytes' );
        } else {
            $this->logger->log( 'error', 'Bangumi ID 地圖更新失敗' );
        }
    }

    // =========================================================================
    // 任務五：主題曲＋集數同步
    // =========================================================================

    public function run_themes_episodes_update(): void {
        if ( get_transient( 'anime_sync_lock_themes_episodes' ) ) {
            $this->logger->log( 'warning', '主題曲＋集數同步：已有另一個程序在執行，本次跳過' );
            return;
        }
        set_transient( 'anime_sync_lock_themes_episodes', 1, self::LOCK_TTL_THEMES_EPISODES );

        try {
            $this->_run_themes_episodes_inner();
        } finally {
            delete_transient( 'anime_sync_lock_themes_episodes' );
        }
    }

    private function _run_themes_episodes_inner(): void {
        Anime_Sync_Performance::set_time_limit( 300 );
        Anime_Sync_Performance::increase_memory_limit( '256M' );

        $queue = get_option( self::THEMES_QUEUE_OPTION, null );

        if ( ! is_array( $queue ) || empty( $queue ) ) {
            $queue = $this->build_themes_episodes_queue();

            if ( empty( $queue ) ) {
                $this->logger->log( 'info', '主題曲＋集數同步：目前無符合條件的作品，本輪結束' );
                self::update_cron_option( self::THEMES_QUEUE_OPTION, [] );
                return;
            }

            self::update_cron_option( 'anime_sync_themes_round_themes_updated',   0 );
            self::update_cron_option( 'anime_sync_themes_round_episodes_updated', 0 );
            self::update_cron_option( 'anime_sync_themes_round_theme_added',      0 );
            self::update_cron_option( 'anime_sync_themes_round_ep_added',         0 );

            $this->logger->log( 'info', sprintf(
                '主題曲＋集數同步：開始新一輪，共 %d 部待處理（每批 %d 部）',
                count( $queue ),
                self::THEMES_BATCH_SIZE
            ) );
        }

        $batch     = array_slice( $queue, 0, self::THEMES_BATCH_SIZE );
        $remaining = array_slice( $queue, self::THEMES_BATCH_SIZE );

        $themes_updated    = (int) get_option( 'anime_sync_themes_round_themes_updated',   0 );
        $episodes_updated  = (int) get_option( 'anime_sync_themes_round_episodes_updated', 0 );
        $theme_added_total = (int) get_option( 'anime_sync_themes_round_theme_added',      0 );
        $ep_added_total    = (int) get_option( 'anime_sync_themes_round_ep_added',         0 );

        foreach ( $batch as $post_id ) {
            $post_id = (int) $post_id;

            if ( get_post_status( $post_id ) !== 'publish' ) {
                continue;
            }

            $added_themes = $this->sync_themes_for_post( $post_id );
            if ( $added_themes > 0 ) {
                $themes_updated++;
                $theme_added_total += $added_themes;
            }

            $added_eps = $this->sync_episodes_for_post( $post_id );
            if ( $added_eps > 0 ) {
                $episodes_updated++;
                $ep_added_total += $added_eps;
            }

            if ( $added_themes > 0 || $added_eps > 0 ) {
                $this->purge_post_cache( $post_id );
            }
        }

        self::update_cron_option( self::THEMES_QUEUE_OPTION,                       array_values( $remaining ) );
        self::update_cron_option( 'anime_sync_themes_round_themes_updated',   $themes_updated    );
        self::update_cron_option( 'anime_sync_themes_round_episodes_updated', $episodes_updated  );
        self::update_cron_option( 'anime_sync_themes_round_theme_added',      $theme_added_total );
        self::update_cron_option( 'anime_sync_themes_round_ep_added',         $ep_added_total    );

        if ( empty( $remaining ) ) {
            $this->logger->log( 'info', sprintf(
                '主題曲＋集數同步完成（整輪）：主題曲 %d 部（共新增 %d 首）/ 集數 %d 部（共新增或補欄 %d 集）',
                $themes_updated,
                $theme_added_total,
                $episodes_updated,
                $ep_added_total
            ) );
            self::update_cron_option( 'anime_sync_last_themes_episodes_run', current_time( 'mysql' ) );
        } else {
            $this->logger->log( 'info', sprintf(
                '主題曲＋集數同步：本批處理完成，剩餘 %d 部待下批處理',
                count( $remaining )
            ) );
        }
    }

    /**
     * 建立主題曲＋集數待處理佇列。
     * ✅ [v1.4.1] 完結日比較改用純數字（int），與 start_date 判斷統一。
     * ✅ [v1.3.9] 新番優先排序（即將開播 > 放映中 > 剛完結，同級按日期由新到舊）。
     * ✅ [v1.3.8] 簡單 IN 撈狀態 + PHP 過濾，避免多層巢狀 meta_query 生成錯誤 SQL。
     *   - RELEASING：全納入。
     *   - NOT_YET_RELEASED：開播日在 ±7 天窗口內。
     *   - FINISHED：完結日在 30 天內。
     */
    private function build_themes_episodes_queue(): array {
        $window_start = (int) gmdate( 'Ymd', strtotime( '-' . self::THEMES_UPCOMING_WINDOW_DAYS . ' days' ) );
        $window_end   = (int) gmdate( 'Ymd', strtotime( '+' . self::THEMES_UPCOMING_WINDOW_DAYS . ' days' ) );
        // ✅ [v1.4.1] 完結日 cutoff 改用純數字 Ymd
        $cutoff_ymd   = (int) gmdate( 'Ymd', strtotime( '-30 days' ) );

        $candidate_ids = get_posts( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'anime_status',
                    'value'   => [ 'RELEASING', 'NOT_YET_RELEASED', 'FINISHED' ],
                    'compare' => 'IN',
                ],
            ],
        ] );

        $items = [];
        foreach ( $candidate_ids as $id ) {
            $id     = (int) $id;
            $status = (string) get_post_meta( $id, 'anime_status', true );

            if ( $status === 'RELEASING' ) {
                $start   = (int) get_post_meta( $id, 'anime_start_date', true );
                $items[] = [ 'id' => $id, 'prio' => self::PRIO_RELEASING, 'sort' => $start ];
            } elseif ( $status === 'NOT_YET_RELEASED' ) {
                $start = (int) get_post_meta( $id, 'anime_start_date', true );
                if ( $start >= $window_start && $start <= $window_end ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_UPCOMING, 'sort' => $start ];
                }
            } elseif ( $status === 'FINISHED' ) {
                // ✅ [v1.4.1] 純數字比較
                $end = (int) get_post_meta( $id, 'anime_end_date', true );
                if ( $end > 0 && $end >= $cutoff_ymd ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_FINISHED, 'sort' => $end ];
                }
            }
        }

        return self::sort_queue_new_first( $items );
    }

      /**
     * ✅ [v1.3.0] 同步單篇主題曲（只增不改）。冷卻期：完結 7 天 / 放映中 3 天。
     * ✅ [v1.4.6] slug 優先、mal_id 備援：
     *   - 舊行為只認 anime_mal_id，mal_id 為 0（新番常見）時直接 return，
     *     使用者手填的 AnimeThemes slug 永遠用不到。
     *   - 新行為改讀 anime_mal_id + anime_animethemes_slug（與 api-handler
     *     的 get_animethemes_meta() 同一 key）；兩者至少有一個就繼續，
     *     並把 slug 傳給 fetch_themes_only( $mal_id, $slug )。
     */
    private function sync_themes_for_post( int $post_id ): int {
        $mal_id = (int) get_post_meta( $post_id, 'anime_mal_id', true );

        // ✅ [v1.4.6] 讀取手填的 AnimeThemes slug（與 api-handler get_animethemes_meta 一致）
        $at_slug = trim( (string) get_post_meta( $post_id, 'anime_animethemes_slug', true ) );
        if ( $at_slug === '' ) {
            // 舊資料相容：legacy 欄位
            $at_slug = trim( (string) get_post_meta( $post_id, 'animethemes_slug', true ) );
        }

        // ✅ [v1.4.6] mal_id 與 slug 兩者皆空才放棄；有其中一個就繼續。
        if ( $mal_id <= 0 && $at_slug === '' ) {
            return 0;
        }

        $status    = (string) get_post_meta( $post_id, 'anime_status', true );
        $last_sync = (string) get_post_meta( $post_id, 'anime_themes_synced_at', true );
        $cooldown  = ( $status === 'FINISHED' )
            ? self::COOLDOWN_THEMES_FINISHED
            : self::COOLDOWN_THEMES_RELEASING;

        if ( $last_sync !== '' && ( time() - strtotime( $last_sync ) ) < $cooldown ) {
            return 0;
        }

        $this->rate_limiter->wait_if_needed( 'animethemes' );

        // ✅ [v1.4.6] 傳入 slug：fetch_themes_only() 內部 slug 優先、mal_id 備援。
        $api_result = $this->import_manager->fetch_themes_only( $mal_id, $at_slug );

        if ( empty( $api_result['themes'] ) || ! is_array( $api_result['themes'] ) ) {
            update_post_meta( $post_id, 'anime_themes_synced_at', current_time( 'mysql' ) );
            return 0;
        }

        // ✅ [v1.4.6] 若這次是靠 slug 抓到、且回傳帶了 AnimeThemes 數字 id，
        //   順手補寫 anime_animethemes_id，之後就能走更穩的 by-id 路徑（若你有實作）。
        //   同時把 API 回傳的正規 slug 回寫，修正使用者可能手打的小差異。
        if ( ! empty( $api_result['slug'] ) ) {
            $canonical_slug = trim( (string) $api_result['slug'] );
            if ( $canonical_slug !== '' && $canonical_slug !== $at_slug ) {
                update_post_meta( $post_id, 'anime_animethemes_slug', $canonical_slug );
                update_post_meta( $post_id, 'animethemes_slug', $canonical_slug );
            }
        }

        $old_themes = json_decode( (string) get_post_meta( $post_id, 'anime_themes', true ), true );
        if ( ! is_array( $old_themes ) ) {
            $old_themes = [];
        }

        $locked_keys = json_decode( (string) get_post_meta( $post_id, 'anime_themes_locked_keys', true ), true );
        if ( ! is_array( $locked_keys ) ) {
            $locked_keys = [];
        }
        $locked_index = array_flip( $locked_keys );

        $make_key = static fn( $t ): string => ( $t['type'] ?? '' ) . ':' . ( $t['sequence'] ?? '' );

$old_index = [];
foreach ( $old_themes as $t ) {
    $old_index[ $make_key( $t ) ] = true;
}

$added = 0;
foreach ( $api_result['themes'] as $new_theme ) {
    $key = $make_key( $new_theme );
    if ( isset( $locked_index[ $key ] ) ) continue;

    if ( isset( $old_index[ $key ] ) ) {
        // ✅ 已存在的曲目：只補「音檔/影片連結」，不動歌名/歌手（避免蓋掉人工修改）
        foreach ( $old_themes as &$existing ) {
            if ( $make_key( $existing ) !== $key ) continue;

            $existing_audio = trim( $existing['audio_url'] ?? '' );
            $existing_video = trim( $existing['video_url'] ?? '' );
            $new_audio      = trim( $new_theme['audio_url'] ?? '' );
            $new_video      = trim( $new_theme['video_url'] ?? '' );

            if ( $existing_audio === '' && $new_audio !== '' ) {
                $existing['audio_url'] = $new_audio;
                $added++;
            }
            if ( $existing_video === '' && $new_video !== '' ) {
                $existing['video_url'] = $new_video;
                $added++;
            }
            break;
        }
        unset( $existing );
        continue;
    }

    $old_themes[]      = $new_theme;
    $old_index[ $key ] = true;
    $added++;
}

        update_post_meta( $post_id, 'anime_themes_synced_at', current_time( 'mysql' ) );

        if ( $added === 0 ) {
            return 0;
        }

        update_post_meta(
            $post_id,
            'anime_themes',
            wp_json_encode( array_values( $old_themes ), JSON_UNESCAPED_UNICODE )
        );

        $post_title = get_the_title( $post_id ) ?: "ID {$post_id}";
        $this->logger->log( 'info', "主題曲同步〔{$post_title}〕：新增 {$added} 首", [
            'post_id' => $post_id,
            'mal_id'  => $mal_id,
            'slug'    => $at_slug,
        ] );

        return $added;
    }

    /**
     * ✅ [v1.4.9] 同步單篇集數列表（只增不改／補空）。
     *
     * 補上先前遺失的方法：_run_themes_episodes_inner() 每 15 分鐘會呼叫
     * $this->sync_episodes_for_post( $post_id )，但此方法先前未被定義，
     * 導致每次執行到批次中第一部需要更新的作品就 Fatal Error（Call to
     * undefined method），使整批（最多 20 部）主題曲＋集數同步全部中斷、
     * 佇列無法正常往下推進。
     *
     * 行為對齊 class-api-handler.php 的 merge_episodes_respecting_locks()
     * 與 sync_themes_for_post() 的既有慣例：
     *   - 冷卻期：完結 COOLDOWN_EPISODES_FINISHED（3 天）／
     *     放映中 COOLDOWN_EPISODES_RELEASING（6 小時），寫入
     *     anime_episodes_synced_at 追蹤時間（無論本次有無抓到新資料都會
     *     更新，避免 API 暫時性失敗時每次 cron 都重打 Bangumi）。
     *   - 整欄鎖定：anime_locked_fields 含 anime_episodes_json 時完全不動。
     *   - 單集鎖定：anime_episodes_locked_ids（與 API Handler 合併集數時
     *     使用的同一把鎖）內的集數 id 完全跳過，不覆蓋任何欄位。
     *   - 只增不改／補空：已存在的集數（以 Bangumi 集數 id 為唯一鍵）只補
     *     空欄位（name / name_cn / airdate），不覆蓋人工已填值；comment
     *     （吐槽數）與 ep 編號無人工編輯疑慮，直接同步更新；新集數直接
     *     加入，最後依 ep 排序寫回，避免 Bangumi 回傳順序跳動導致前台
     *     顯示錯亂。
     *
     * @return int 本次新增或補上的欄位／集數數量（0 = 無變動）
     */
    private function sync_episodes_for_post( int $post_id ): int {
        $bgm_id = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
        if ( $bgm_id <= 0 ) {
            // 相容 legacy key
            $bgm_id = (int) get_post_meta( $post_id, 'bangumi_id', true );
        }
        if ( $bgm_id <= 0 ) {
            return 0;
        }

        $locked = get_post_meta( $post_id, 'anime_locked_fields', true );
        if ( ! is_array( $locked ) ) {
            $locked = [];
        }
        if ( in_array( 'anime_episodes_json', $locked, true ) ) {
            return 0; // 整欄「鎖定集數列表」，完全不動
        }

        $status    = (string) get_post_meta( $post_id, 'anime_status', true );
        $last_sync = (string) get_post_meta( $post_id, 'anime_episodes_synced_at', true );
        $cooldown  = ( $status === 'FINISHED' )
            ? self::COOLDOWN_EPISODES_FINISHED
            : self::COOLDOWN_EPISODES_RELEASING;

        if ( $last_sync !== '' && ( time() - strtotime( $last_sync ) ) < $cooldown ) {
            return 0;
        }

        $this->rate_limiter->wait_if_needed( 'bangumi' );
        $new_episodes = $this->import_manager->fetch_episodes_only( $bgm_id );

        // 無論本次有沒有抓到新資料，都先蓋掉同步時間，讓冷卻期照常生效，
        // 避免 API 暫時性失敗時每次 cron 都重打 Bangumi。
        update_post_meta( $post_id, 'anime_episodes_synced_at', current_time( 'mysql' ) );

        if ( empty( $new_episodes ) || ! is_array( $new_episodes ) ) {
            return 0;
        }

        $old_episodes = json_decode( (string) get_post_meta( $post_id, 'anime_episodes_json', true ), true );
        if ( ! is_array( $old_episodes ) ) {
            $old_episodes = [];
        }

        $locked_ids = json_decode( (string) get_post_meta( $post_id, 'anime_episodes_locked_ids', true ), true );
        if ( ! is_array( $locked_ids ) ) {
            $locked_ids = [];
        }
        $locked_index = array_flip( $locked_ids );

        $old_index = [];
        foreach ( $old_episodes as $i => $ep ) {
            $ep_id = $ep['id'] ?? null;
            if ( $ep_id !== null ) {
                $old_index[ $ep_id ] = $i;
            }
        }

        $changed = 0;

        foreach ( $new_episodes as $new_ep ) {
            $ep_id = $new_ep['id'] ?? null;
            if ( $ep_id === null ) {
                continue;
            }

            if ( isset( $old_index[ $ep_id ] ) ) {
                if ( isset( $locked_index[ $ep_id ] ) ) {
                    continue; // 此集被人工鎖定，完全跳過
                }

                $idx      = $old_index[ $ep_id ];
                $existing = $old_episodes[ $idx ];

                // 只補空欄位，不覆蓋人工已填的值
                foreach ( [ 'name', 'name_cn', 'airdate' ] as $field ) {
                    $existing_val = trim( (string) ( $existing[ $field ] ?? '' ) );
                    $new_val      = trim( (string) ( $new_ep[ $field ]  ?? '' ) );
                    if ( $existing_val === '' && $new_val !== '' ) {
                        $existing[ $field ] = $new_val;
                        $changed++;
                    }
                }

                // comment（吐槽數）與 ep 編號沒有人工編輯疑慮，直接同步更新
                $new_comment = (int) ( $new_ep['comment'] ?? 0 );
                if ( (int) ( $existing['comment'] ?? 0 ) !== $new_comment ) {
                    $existing['comment'] = $new_comment;
                }
                if ( isset( $new_ep['ep'] ) && ( $existing['ep'] ?? null ) !== $new_ep['ep'] ) {
                    $existing['ep'] = $new_ep['ep'];
                }

                $old_episodes[ $idx ] = $existing;
            } else {
                // 新集數，直接加入
                $old_episodes[]       = $new_ep;
                $old_index[ $ep_id ]  = count( $old_episodes ) - 1;
                $changed++;
            }
        }

        if ( $changed === 0 ) {
            return 0;
        }

        // 依集數編號排序，避免 Bangumi 回傳順序跳動導致前台顯示錯亂
        usort( $old_episodes, static function ( $a, $b ) {
            return ( $a['ep'] ?? 0 ) <=> ( $b['ep'] ?? 0 );
        } );

        update_post_meta(
            $post_id,
            'anime_episodes_json',
            wp_json_encode( array_values( $old_episodes ), JSON_UNESCAPED_UNICODE )
        );

        $post_title = get_the_title( $post_id ) ?: "ID {$post_id}";
        $this->logger->log( 'info', "集數同步〔{$post_title}〕：新增或補上 {$changed} 筆", [
            'post_id'    => $post_id,
            'bangumi_id' => $bgm_id,
        ] );

        return $changed;
    }

    // =========================================================================
    // 輔助方法
    // =========================================================================

    private function get_current_season(): array {
        $month = (int) gmdate( 'n' );
        $year  = (int) gmdate( 'Y' );

        $season = match ( true ) {
            $month >= 1  && $month <= 3  => 'WINTER',
            $month >= 4  && $month <= 6  => 'SPRING',
            $month >= 7  && $month <= 9  => 'SUMMER',
            default                      => 'FALL',
        };

        return [ $season, $year ];
    }

    private function fetch_season_list( string $season, int $year ): array {
        $query = <<<'GQL'
        query ($season: MediaSeason, $year: Int, $page: Int) {
            Page(page: $page, perPage: 50) {
                pageInfo { hasNextPage }
                media(season: $season, seasonYear: $year, type: ANIME, sort: POPULARITY_DESC) {
                    id
                    title { romaji native english }
                    status
                    format
                }
            }
        }
        GQL;

        $all  = [];
        $page = 1;

        do {
            $this->rate_limiter->wait_if_needed( 'anilist' );

            $body = $this->anilist_request( $query, [
                'season' => $season,
                'year'   => $year,
                'page'   => $page,
            ] );

            if ( $body === null ) break;

            $page_data = $body['data']['Page'] ?? [];
            $media     = $page_data['media'] ?? [];

            if ( empty( $media ) ) break;

            $all      = array_merge( $all, $media );
            $has_next = $page_data['pageInfo']['hasNextPage'] ?? false;
            $page++;

        } while ( $has_next && $page <= 10 );

        return $all;
    }

    /**
     * ✅ [v1.4.2] 補上 User-Agent 標頭，修復缺 UA 被 Cloudflare 判定機器人回 403 的問題。
     */
    private function anilist_request( string $query, array $variables = [] ): ?array {
        $response = wp_remote_post( 'https://graphql.anilist.co', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                // ✅ [v1.4.2] 缺此標頭會被 AniList 前端的 Cloudflare 攔截回 403
                'User-Agent'   => self::get_anilist_user_agent(),
            ],
            'body' => wp_json_encode( [
                'query'     => $query,
                'variables' => $variables,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->logger->log( 'error', 'AniList request failed: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 429 ) {
            $wait = $this->rate_limiter->handle_rate_limit_error( $response, 'anilist' );
            sleep( $wait );
            return null;
        }

        if ( $code !== 200 ) {
            $this->logger->log( 'warning', "AniList request returned HTTP {$code}" );
            return null;
        }

        $this->rate_limiter->check_remaining( $response, 'anilist' );

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return null;
        }

        return $body;
    }
}
