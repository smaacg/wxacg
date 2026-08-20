<?php
/**
 * 檔案名稱: includes/class-cron-manager.php
 * Cron Manager — 排程同步管理
 *
 * 修正紀錄：
 * - [v1.5.4] [Fix 評分回補誤殺未開分作品] backfill_score_for_post() 抓不到 MAL 分時，
 *            先以 confirm_mal_has_no_score() 直查官方 API 確認是否「尚未開分」；確認
 *            未開分者打 anime_mal_no_score=1 標記，不再累加 retry_count（避免達上限
 *            SCORE_BACKFILL_MAX_RETRY 後被永久排除）。build_score_backfill_queue() 亦
 *            排除已標記 anime_mal_no_score=1 的作品。MAL 節流由 wait_if_needed('jikan')
 *            改為 wait_if_needed('mal')（需搭配 class-rate-limiter.php v1.3.0 新增的
 *            'mal' 節流 key）。
 * - [v1.5.3] [Log 時間顯示對齊] _run_entity_backfill_inner() 內寫入
 *            anime_sync_last_entity_backfill 訊息字串的 3 處時間戳，由 gmdate()
 *            改為 current_time()，讓「訊息內文時間」與錯誤日誌列表右側 created_at
 *            欄位（站台本地時區）一致，不再相差時區偏移量。
 *            ※ 僅改「給人看的字串」；其餘 gmdate('Ymd'/'n'/'Y') 為日期比對邏輯
 *              （對齊資料庫以 UTC 存的 anime_start_date / anime_end_date），
 *              一律維持 UTC 不動，避免佇列篩選與季度判斷偏移。
 * - [v1.5.2] [Entity Backfill 全自動接力] _run_entity_backfill_inner() 於「目前模式
 *            已無真正待補項目」時，自動切換到下一階段，免去人工 WP-CLI / 後台切換：
 *            persons(聲優) 補完 → 自動切 characters(角色)；
 *            characters(角色) 補完 → 自動切 off(關閉，停止空轉)。
 *            穩健判斷（避免「假空」誤切）：只有在「扣掉跳過名單後 LIMIT 查詢為 0」
 *            且「繞過跳過名單、全站該類欄位缺漏數也為 0」兩條件同時成立時，才視為
 *            真正補完並切換模式；若只是這批剛好全落在跳過名單內（全站仍有缺漏），
 *            則維持原模式不切換，等清空跳過名單或 BGM 補資料後再繼續。
 *            不新增任何跨執行的狀態 option，判斷為單次即時查詢，無誤切風險。
 * - [v1.5.1] [Entity Backfill 併入] 將原 mu-plugins/char-person-backfill-cron.php
 *            （角色/聲優自動回補）整支併入本類別，成為「任務七」，該 mu-plugin
 *            檔案可直接刪除：
 *            (1) 新增每 5 分鐘排程 anime_sync_five_min 與 HOOK_ENTITY_BACKFILL，
 *                activate()/deactivate() 一併管理排程生命週期。
 *            (2) 開關由原本硬編碼 define('MY_BACKFILL_MODE') 改為 option
 *                anime_sync_entity_backfill_mode（'characters'|'persons'|'off'，
 *                預設 'off'），批次量 option anime_sync_entity_backfill_batch
 *                （預設 60），切換不再需要改程式碼。
 *            (3) 環境守門改用標準 wp_get_environment_type() === 'local' 判斷，
 *                移除原本硬編碼的主機絕對路徑檢查（不可攜、換主機即失效）。
 *            (4) 一次性遷移 maybe_migrate_legacy_backfill_options()：搬移舊
 *                my_backfill_skip_chars / my_backfill_skip_persons 跳過名單至新
 *                option key（保留已累積的「BGM 無資料」名單，避免重打 API），
 *                清除舊 my_backfill_last / my_backfill_persons_skip_reset_done，
 *                並 wp_clear_scheduled_hook('my_backfill_event') 移除 mu-plugin
 *                刪檔後殘留的孤兒排程。
 *            (5) 跳過邏輯不變：只有 BGM「整筆抓不到」(failed>0 且 updated=0)
 *                的 bgm_id 才記入跳過名單；聲優只以 infobox_json 判斷缺漏
 *                （summary/height/birthday 為 BGM 常缺選填欄位，納入判斷會
 *                導致集體筆名等條目反覆重撈、進度卡死）。
 * - [v1.5.0] [Fix 未定檔期死結 + seasonYear 同步] 根治「動畫化確定但未定檔期」的作品
 *            開播日/播出年份永遠是舊資料的問題（詳見原檔說明）。
 * - [v1.4.9] [Fix 缺失方法] 補上遺失的 sync_episodes_for_post()（詳見原檔說明）。
 * - [v1.4.8] [Score Backfill] 新增「完結作品評分回補」佇列機制（詳見原檔說明）。
 * - [v1.4.7] [BGM Score Retry] 新增 Bangumi 評分持續重試機制（詳見原檔說明）。
 * - [v1.4.5] [MAL Score Retry] 新增 MAL 評分持續重試機制（詳見原檔說明）。
 * - [v1.4.4] [Fix MAL ID 補完]（詳見原檔說明）。
 * - [v1.4.3] [Start Date Auto-Fix]（詳見原檔說明）。
 * - [v1.4.2] [Fix 403] anilist_request() 補 User-Agent（詳見原檔說明）。
 * - [v1.4.1] [Date Format Unify] 完結日統一 Ymd（詳見原檔說明）。
 * - [v1.4.0] [Themes Speedup] 集數同步改 15 分鐘、批次 20（詳見原檔說明）。
 * - [v1.3.9] [New Anime Priority] 佇列新番優先（詳見原檔說明）。
 * - 其餘 v1.3.x / v1.2.x 修正紀錄請參閱先前版本。
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
    const HOOK_TRANSLATE_SUMMARIES    = 'anime_sync_translate_summaries';

    /*
     * ★ 2026-08-17 新增：緊急集數檢查。
     *
     * episodes_aired 只靠每日動態更新的大佇列（79 部/批 20/每小時）輪到才會
     * 刷新，一部作品剛開播完新的一集，最慢要等快 4 小時才會被排到，這段
     * 空窗期間站上顯示的「已播集數」跟評論分頁的可選集數都會是舊的。
     *
     * 這裡不加速整條大佇列（那樣會浪費 AniList API 額度在不需要更新的
     * 作品上），改成每 15 分鐘輕量掃一次「排定開播時間已經過去、但狀態
     * 還沒被刷新」的作品——用本站已經存好的 anime_next_airing 直接篩選，
     * 不用額外呼叫 API 就能鎖定候選名單，通常同時間只有個位數幾部符合，
     * 對 API 額度幾乎沒有額外負擔，卻能把「新的一集播出」這種事件的
     * 反應時間從最慢 4 小時壓到最慢 15 分鐘。
     */
    const HOOK_URGENT_EPISODE_CHECK = 'anime_sync_urgent_episode_check';
    const LOCK_TTL_URGENT_EPISODE   = 290;
    const URGENT_EPISODE_BATCH_SIZE = 10;

    const LOCK_TTL_DAILY           = 1800;
    const LOCK_TTL_SEASON          = 3600;
    const LOCK_TTL_THEMES_EPISODES = 1800;
    const LOCK_TTL_WEEKLY          = 600;

    const DAILY_QUEUE_OPTION = 'anime_sync_daily_queue';
    const DAILY_BATCH_SIZE   = 20;

    /**
     * 每日動態更新的熔斷門檻：同一批內連續失敗達此數量即中止本批。
     * 用於上游整個不可用時（例如 AniList 全站回 403）避免逐筆空打，
     * 未處理項目會退回佇列由下批重試。
     */
    const DAILY_ABORT_AFTER_FAILURES = 5;

    /**
     * AniList 回 404（查無此 ID）連續達此次數即標記失效、停止重試。
     *
     * 404 與 403 是完全不同的兩件事：403 是 AniList 維修／暫時停用，
     * 整個服務不可用，熔斷後下批重試即可自行恢復；404 是「這個 ID 在
     * AniList 已經不存在」，屬於本地資料壞掉，重試一萬次也不會成功。
     * 沒有這道門檻時，壞掉的一筆會每輪重撞（實例：post 2608 連續 14 天
     * 撞了 36 次），且因為未播出作品屬 PRIO_UPCOMING 最高優先，每輪
     * 都排在最前面，浪費的請求還特別集中。
     */
    const ANILIST_404_MAX_RETRY  = 3;
    const ANILIST_404_COUNT_META = 'anime_anilist_404_count';
    const ANILIST_DEAD_ID_META   = 'anime_anilist_id_dead';

    const THEMES_QUEUE_OPTION = 'anime_sync_themes_episodes_queue';
    const THEMES_BATCH_SIZE   = 20;

    const DAILY_QUEUE_CACHE_KEY = 'anime_sync_daily_queue_build';
    const DAILY_QUEUE_CACHE_TTL = 300;

    const UPCOMING_WINDOW_DAYS = 30;

    const THEMES_UPCOMING_WINDOW_DAYS = 7;

    const POPULARITY_SYNC_INTERVAL = DAY_IN_SECONDS;

    const COOLDOWN_THEMES_RELEASING   = 3 * DAY_IN_SECONDS;
    const COOLDOWN_THEMES_FINISHED    = 7 * DAY_IN_SECONDS;
    const COOLDOWN_EPISODES_RELEASING = 6 * HOUR_IN_SECONDS;
    const COOLDOWN_EPISODES_FINISHED  = 3 * DAY_IN_SECONDS;

    const PRIO_UPCOMING  = 0;
    const PRIO_RELEASING = 1;
    const PRIO_FINISHED  = 2;

    const FALLBACK_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    const SCORE_BACKFILL_QUEUE_OPTION       = 'anime_sync_score_backfill_queue';
    const SCORE_BACKFILL_BATCH_SIZE_DEFAULT = 15;
    const SCORE_BACKFILL_REBUILD_INTERVAL   = 30 * DAY_IN_SECONDS;
    const SCORE_BACKFILL_MAX_RETRY          = 6;
    const SCORE_RETRY_COUNT_META            = 'anime_score_retry_count';
    const LAST_SCORE_BACKFILL_BUILD_OPTION  = 'anime_sync_last_score_backfill_build';

    // ✅ [v1.5.1] 任務七：角色/聲優 BGM 資料回補（原 mu-plugin 併入）
    const HOOK_ENTITY_BACKFILL              = 'anime_sync_entity_backfill';
    const LOCK_TTL_ENTITY_BACKFILL          = 290;
    const ENTITY_BACKFILL_MODE_OPTION       = 'anime_sync_entity_backfill_mode';
    const ENTITY_BACKFILL_BATCH_OPTION      = 'anime_sync_entity_backfill_batch';
    const ENTITY_BACKFILL_BATCH_DEFAULT     = 60;
    const ENTITY_BACKFILL_SKIP_CHARS_OPTION   = 'anime_sync_backfill_skip_chars';
    const ENTITY_BACKFILL_SKIP_PERSONS_OPTION = 'anime_sync_backfill_skip_persons';
    const ENTITY_BACKFILL_LAST_OPTION       = 'anime_sync_last_entity_backfill';
    const ENTITY_BACKFILL_MIGRATED_OPTION   = 'anime_sync_entity_backfill_migrated';

    private Anime_Sync_Import_Manager $import_manager;
    private Anime_Sync_Error_Logger   $logger;
    private Anime_Sync_Rate_Limiter   $rate_limiter;
    private Anime_Sync_API_Handler $api_handler;

    /** 最近一次 anilist_request() 的 HTTP 狀態碼，供呼叫端區分 403／404 */
    private int $last_anilist_http_code = 0;

    public function __construct( Anime_Sync_Import_Manager $import_manager ) {
        $this->import_manager = $import_manager;
        $this->logger         = new Anime_Sync_Error_Logger();
        $this->rate_limiter   = Anime_Sync_Rate_Limiter::get_instance();
        $this->api_handler    = new Anime_Sync_API_Handler( $this->rate_limiter );

        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );

        add_action( self::HOOK_DAILY_SCORE_UPDATE,     [ $this, 'run_daily_score_update' ] );
        add_action( self::HOOK_WEEKLY_CLEANUP,         [ $this, 'run_weekly_cleanup' ] );
        add_action( self::HOOK_UPDATE_MAP,             [ $this, 'run_update_map' ] );
        add_action( self::HOOK_SEASON_IMPORT,          [ $this, 'run_season_auto_import' ], 10, 2 );
        add_action( self::HOOK_THEMES_EPISODES_UPDATE, [ $this, 'run_themes_episodes_update' ] );
        add_action( self::HOOK_ENTITY_BACKFILL,        [ $this, 'run_entity_backfill' ] );
        add_action( self::HOOK_TRANSLATE_SUMMARIES,    [ $this, 'run_translate_summaries' ] );
        add_action( self::HOOK_URGENT_EPISODE_CHECK,   [ $this, 'run_urgent_episode_check' ] );

        /*
         * 自我修復排程：只在 activate() 裡註冊的話，已經在線上跑的站台
         * 靠 git push 部署不會觸發 register_activation_hook，新加的排程
         * 永遠不會被排進去。這裡改成每次 plugins_loaded 都檢查一次，
         * wp_next_scheduled() 已存在時是極低成本的一次查詢，不影響效能。
         */
        if ( ! wp_next_scheduled( self::HOOK_URGENT_EPISODE_CHECK ) ) {
            wp_schedule_event( time() + 120, 'anime_sync_quarter_hour', self::HOOK_URGENT_EPISODE_CHECK );
        }
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
            'anime_sync_quarter_hour' => [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Anime Sync: 每15分鐘', 'anime-sync-pro' ),
            ],
            'anime_sync_five_min' => [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Anime Sync: 每5分鐘', 'anime-sync-pro' ),
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

        $existing_schedules = wp_get_schedules();
        if ( ! array_key_exists( 'anime_sync_weekly', $existing_schedules ) ||
             ! array_key_exists( 'anime_sync_hourly', $existing_schedules ) ||
             ! array_key_exists( 'anime_sync_quarter_hour', $existing_schedules ) ||
             ! array_key_exists( 'anime_sync_five_min', $existing_schedules ) ) {

            add_filter( 'cron_schedules', static function ( $schedules ) {
                return array_merge( (array) $schedules, self::get_custom_schedules() );
            } );
        }

        if ( ! wp_next_scheduled( self::HOOK_DAILY_SCORE_UPDATE ) ) {
            wp_schedule_event( time() + 300, 'anime_sync_hourly', self::HOOK_DAILY_SCORE_UPDATE );
        }

        if ( ! wp_next_scheduled( self::HOOK_THEMES_EPISODES_UPDATE ) ) {
            wp_schedule_event( time() + 600, 'anime_sync_quarter_hour', self::HOOK_THEMES_EPISODES_UPDATE );
        }

        if ( ! wp_next_scheduled( self::HOOK_URGENT_EPISODE_CHECK ) ) {
            wp_schedule_event( time() + 120, 'anime_sync_quarter_hour', self::HOOK_URGENT_EPISODE_CHECK );
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

        // 季度自動匯入：run_season_auto_import() 原本就寫好，但一直沒被排程過。
        // 抓當季 AniList 新作清單逐筆匯入，新增項目一律 draft，等人工在審核佇列複核發佈。
        if ( ! wp_next_scheduled( self::HOOK_SEASON_IMPORT ) ) {
            wp_schedule_event(
                strtotime( 'next tuesday 03:00:00' ),
                'anime_sync_weekly',
                self::HOOK_SEASON_IMPORT
            );
        }

        if ( ! wp_next_scheduled( self::HOOK_ENTITY_BACKFILL ) ) {
            wp_schedule_event( time() + 60, 'anime_sync_five_min', self::HOOK_ENTITY_BACKFILL );
        }

        /*
         * 角色/聲優簡介翻譯：日文用 DeepL 翻繁中，已是中文的走 OpenCC。
         * 沒設定 DeepL 金鑰時 run_translate_summaries() 會直接跳過，不影響其他排程。
         *
         * ★ 改為每日執行（原為每週）：待翻約 15,700 筆／306 萬字元，
         *   每週一次要 6.1 個月，但 DeepL 免費額度（100 萬字元／月）其實
         *   只需 3.1 個月，等於額度用不到一半、時間卻多花一倍。改成每日後
         *   額度會成為唯一瓶頸；當月額度用盡時 translate_entity_summaries()
         *   會以 skipped_quota 自行跳過，等次月額度重置再繼續，不會出錯。
         */
        if ( ! wp_next_scheduled( self::HOOK_TRANSLATE_SUMMARIES ) ) {
            wp_schedule_event(
                strtotime( 'tomorrow 03:00:00' ),
                'daily',
                self::HOOK_TRANSLATE_SUMMARIES
            );
        }

        self::maybe_migrate_legacy_backfill_options();
    }

    public static function deactivate(): void {
        $hooks = [
            self::HOOK_DAILY_SCORE_UPDATE,
            self::HOOK_WEEKLY_CLEANUP,
            self::HOOK_SEASON_IMPORT,
            self::HOOK_UPDATE_MAP,
            self::HOOK_THEMES_EPISODES_UPDATE,
            self::HOOK_ENTITY_BACKFILL,
            self::HOOK_TRANSLATE_SUMMARIES,
            self::HOOK_URGENT_EPISODE_CHECK,
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

    private static function maybe_migrate_legacy_backfill_options(): void {
        if ( get_option( self::ENTITY_BACKFILL_MIGRATED_OPTION ) ) {
            return;
        }

        $legacy_map = [
            'my_backfill_skip_chars'   => self::ENTITY_BACKFILL_SKIP_CHARS_OPTION,
            'my_backfill_skip_persons' => self::ENTITY_BACKFILL_SKIP_PERSONS_OPTION,
        ];
        foreach ( $legacy_map as $old_key => $new_key ) {
            $old_val = get_option( $old_key, null );
            if ( is_array( $old_val ) && ! empty( $old_val ) && get_option( $new_key, null ) === null ) {
                add_option( $new_key, array_values( array_unique( array_map( 'intval', $old_val ) ) ), '', 'no' );
            }
            delete_option( $old_key );
        }

        delete_option( 'my_backfill_last' );
        delete_option( 'my_backfill_persons_skip_reset_done' );

        wp_clear_scheduled_hook( 'my_backfill_event' );

        update_option( self::ENTITY_BACKFILL_MIGRATED_OPTION, 1, false );
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

        /*
         * 熔斷：連續失敗達門檻就中止本批。
         *
         * 上游整個掛掉時（例如 AniList 曾以 HTTP 403 回覆
         * "The AniList API has been temporarily disabled"），原本會把整批
         * 逐筆打完，每筆各記一行警告。實際上第一筆就足以判斷「服務不可用」，
         * 後續請求純屬浪費，且會把真正需要處理的警告洗掉。
         *
         * 中止時未處理的項目會退回佇列，下批（下一次排程）照常重試，
         * 因此上游恢復後會自動復原，不需人工介入。
         */
        $consecutive_failures = 0;
        $aborted_at           = null;

        foreach ( $batch as $index => $post_id ) {
            $post_id = (int) $post_id;

            if ( $consecutive_failures >= self::DAILY_ABORT_AFTER_FAILURES ) {
                $aborted_at = $index;
                break;
            }

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
                    $consecutive_failures = 0; // 兜底成功，視為服務仍可用
                } else {
                    $failed++;

                    /*
                     * 404 不計入熔斷。
                     *
                     * 熔斷的用途是判斷「上游整個不可用」，而 404 代表上游好端端地
                     * 回答了「這個 ID 不存在」——服務是正常的，壞的是本地這一筆。
                     * 混在一起算會有兩個後果：連續幾筆 ID 失效會被誤判成 API 掛掉
                     * 而中止整批；而且這筆永遠不會成功，卻每輪都重試。
                     */
                    if ( $this->last_anilist_http_code === 404 ) {
                        $this->mark_anilist_id_404( $post_id, $anilist_id, $post_title );
                    } else {
                        $consecutive_failures++;
                        $this->logger->log( 'warning', "每日動態更新〔{$post_title}〕：失敗", [
                            'post_id'    => $post_id,
                            'anilist_id' => $anilist_id,
                            'http_code'  => $this->last_anilist_http_code,
                        ] );
                    }
                }
            } elseif ( $result === 'skipped' ) {
                $skipped++;
                $consecutive_failures = 0;
                $this->clear_anilist_404_state( $post_id );
                $this->logger->log( 'info', "每日動態更新〔{$post_title}〕：無變動" );
            } else {
                $updated++;
                $consecutive_failures = 0;
                $this->clear_anilist_404_state( $post_id );
                $detail = str_contains( $result, ':' ) ? '（' . explode( ':', $result, 2 )[1] . '）' : '';
                $this->logger->log( 'info', "每日動態更新〔{$post_title}〕：已更新{$detail}" );
                $this->purge_post_cache( $post_id );
            }
        }

        /*
         * 熔斷後：把本批未處理的項目退回佇列最前面，下批重試。
         * 不動已累計的統計數字，本輪結算仍照實反映。
         */
        if ( $aborted_at !== null ) {
            $unprocessed = array_slice( $batch, $aborted_at );
            $remaining   = array_merge( $unprocessed, $remaining );

            $this->logger->log( 'warning', sprintf(
                '每日動態更新：連續 %d 筆失敗，判定上游暫時不可用，本批提前中止（%d 部退回佇列，下批重試）',
                self::DAILY_ABORT_AFTER_FAILURES,
                count( $unprocessed )
            ) );
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
     * 記錄一次 AniList 404；連續達門檻就標記失效並停止重試。
     *
     * 標記後 build_daily_queue() 不再納入這篇，等人工確認新的 anilist_id
     * （AniList 條目重建時舊 ID 會被刪除，新 ID 通常只差幾號）。
     * 修好後任何一次成功同步都會由 clear_anilist_404_state() 清掉標記。
     */
    private function mark_anilist_id_404( int $post_id, int $anilist_id, string $post_title ): void {
        $count = (int) get_post_meta( $post_id, self::ANILIST_404_COUNT_META, true ) + 1;
        update_post_meta( $post_id, self::ANILIST_404_COUNT_META, $count );

        if ( $count < self::ANILIST_404_MAX_RETRY ) {
            $this->logger->log( 'warning', sprintf(
                '每日動態更新〔%s〕：AniList 查無此 ID（%d/%d）',
                $post_title,
                $count,
                self::ANILIST_404_MAX_RETRY
            ), [
                'post_id'    => $post_id,
                'anilist_id' => $anilist_id,
            ] );
            return;
        }

        update_post_meta( $post_id, self::ANILIST_DEAD_ID_META, current_time( 'mysql' ) );

        $this->logger->log( 'warning', sprintf(
            '〔%s〕anilist_id %d 已失效，已停止自動重試，請人工確認新的 AniList ID',
            $post_title,
            $anilist_id
        ), [
            'post_id'    => $post_id,
            'anilist_id' => $anilist_id,
            'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
        ] );
    }

    /** 同步成功即視為 ID 有效，清掉先前的 404 計數與失效標記 */
    private function clear_anilist_404_state( int $post_id ): void {
        if ( get_post_meta( $post_id, self::ANILIST_404_COUNT_META, true ) === '' ) {
            return;
        }

        delete_post_meta( $post_id, self::ANILIST_404_COUNT_META );
        delete_post_meta( $post_id, self::ANILIST_DEAD_ID_META );
    }

    private function build_daily_queue(): array {
        $cached = get_transient( self::DAILY_QUEUE_CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

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
                'relation' => 'AND',
                [
                    'key'     => 'anime_status',
                    'value'   => [ 'RELEASING', 'NOT_YET_RELEASED', 'FINISHED' ],
                    'compare' => 'IN',
                ],
                // anilist_id 已確認失效的排除在外，不再每輪空撞
                [
                    'key'     => self::ANILIST_DEAD_ID_META,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        $excluded_formats = [ 'MOVIE', 'OVA', 'SPECIAL' ];

        $items = [];
        foreach ( $candidate_ids as $id ) {

            $id = (int) $id;

            $status = (string) get_post_meta( $id, 'anime_status', true );

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
                if ( $start <= 0 ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_UPCOMING, 'sort' => 0 ];
                } elseif ( $start <= $window_ymd && $start >= $cutoff_ymd ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_UPCOMING, 'sort' => $start ];
                }
            } elseif ( $status === 'FINISHED' ) {
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
     * 每 15 分鐘掃一次「排定開播時間已經過去、狀態卻還沒被刷新」的作品，
     * 立刻補抓，不用等大佇列輪到（詳見上方常數區塊的說明）。
     */
    public function run_urgent_episode_check(): void {
        if ( get_transient( 'anime_sync_lock_urgent_episode' ) ) {
            return;
        }
        set_transient( 'anime_sync_lock_urgent_episode', 1, self::LOCK_TTL_URGENT_EPISODE );

        try {
            $this->_run_urgent_episode_check_inner();
        } finally {
            delete_transient( 'anime_sync_lock_urgent_episode' );
        }
    }

    private function _run_urgent_episode_check_inner(): void {
        $candidate_ids = get_posts( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => self::URGENT_EPISODE_BATCH_SIZE,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => 'anime_status',
                    'value' => 'RELEASING',
                ],
                [
                    'key'     => 'anime_next_airing',
                    'value'   => time(),
                    'compare' => '<',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => self::ANILIST_DEAD_ID_META,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        if ( empty( $candidate_ids ) ) {
            return;
        }

        $updated = 0;

        foreach ( $candidate_ids as $post_id ) {
            $post_id    = (int) $post_id;
            $anilist_id = (int) get_post_meta( $post_id, 'anime_anilist_id', true );
            if ( ! $anilist_id ) {
                continue;
            }

            $post_title = get_the_title( $post_id ) ?: "ID {$post_id}";

            $this->rate_limiter->wait_if_needed( 'anilist' );
            $result = $this->sync_dynamic_for_post( $post_id, $anilist_id );

            if ( $result === 'failed' ) {
                if ( $this->last_anilist_http_code === 404 ) {
                    $this->mark_anilist_id_404( $post_id, $anilist_id, $post_title );
                }
                continue;
            }

            if ( $result !== 'skipped' ) {
                $updated++;
                $detail = str_contains( $result, ':' ) ? '（' . explode( ':', $result, 2 )[1] . '）' : '';
                $this->logger->log( 'info', "緊急集數檢查〔{$post_title}〕：已更新{$detail}" );
                $this->purge_post_cache( $post_id );
            }
        }

        if ( $updated > 0 ) {
            $this->logger->log( 'info', "緊急集數檢查：本輪共更新 {$updated} 部" );
        }
    }

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

        /*
         * 新作／續作判斷：AniList 上有指向「動畫」的 PREQUEL 關聯即視為續作。
         *
         * 這份資料是搭 fetch_anilist_dynamic() 既有查詢的順風車一起取回的
         * （同一個請求多要一個欄位），不額外發送任何 API 請求。
         * 供番組表「新作／續作」分頁使用，前台只讀這個欄位、不查外部 API。
         *
         * 使用者若在後台手動修正過（欄位被鎖定），這裡不覆寫。
         */
        if ( ! $is_locked( 'anime_has_prequel' ) && isset( $media['relations']['edges'] ) ) {
            $has_prequel = 0;
            foreach ( (array) $media['relations']['edges'] as $edge ) {
                if ( ( $edge['relationType'] ?? '' ) === 'PREQUEL'
                     && ( $edge['node']['type'] ?? '' ) === 'ANIME' ) {
                    $has_prequel = 1;
                    break;
                }
            }

            $old_prequel = get_post_meta( $post_id, 'anime_has_prequel', true );
            if ( $old_prequel === '' || (int) $old_prequel !== $has_prequel ) {
                update_post_meta( $post_id, 'anime_has_prequel', $has_prequel );
                $diff[] = '作品類型 ' . ( $has_prequel ? '續作' : '新作' );
            }
        }

        if ( ! $is_locked( 'anime_status' ) && isset( $media['status'] ) && $media['status'] !== '' ) {
            $old_val = (string) get_post_meta( $post_id, 'anime_status', true );
            $new_val = (string) $media['status'];
            if ( $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_status', $new_val );
                $diff[] = '狀態 ' . $old_val . '→' . $new_val;
            }
        }

        if ( ! $is_locked( 'anime_mal_id' ) ) {
            $current_mal_id = (int) get_post_meta( $post_id, 'anime_mal_id', true );
            $new_mal_id     = isset( $media['idMal'] ) && $media['idMal'] !== null ? (int) $media['idMal'] : 0;

            if ( $current_mal_id <= 0 && $new_mal_id > 0 ) {
                update_post_meta( $post_id, 'anime_mal_id', $new_mal_id );
                $diff[] = 'MAL ID 補上 ' . $new_mal_id;

                delete_post_meta( $post_id, '_enriched_at' );
                if ( ! wp_next_scheduled( 'anime_sync_enrich_post', [ $post_id ] ) ) {
                    wp_schedule_single_event( time() + 60, 'anime_sync_enrich_post', [ $post_id ] );
                }
            }
        }

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

        if ( ! $is_locked( 'anime_score_bangumi' ) ) {
            $bgm_id_for_score = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
            if ( $bgm_id_for_score <= 0 ) {
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

        if ( ! $is_locked( 'anime_next_airing' ) ) {
            if ( isset( $media['nextAiringEpisode']['airingAt'] ) ) {
                /*
                 * ★ 統一寫成 JSON，不再寫純時間戳。
                 *
                 *   匯入端（class-api-handler.php）一直寫 JSON
                 *   {"airingAt":…,"episode":…}，這裡卻只寫時間戳，於是同一個
                 *   欄位出現兩種格式，實際值取決於哪一支程式最後寫過（全站
                 *   137 筆中 86 筆數字、51 筆 JSON）。首頁只解析數字那種，
                 *   造成 51 部作品的播出星期分類永遠失敗。
                 *
                 *   純數字版還會遺失集數，讀取端得改用 anime_episodes_aired
                 *   推算，而那個欄位未必準。統一成 JSON 資訊比較完整。
                 */
                update_post_meta(
                    $post_id,
                    'anime_next_airing',
                    wxacg_encode_next_airing(
                        (int) $media['nextAiringEpisode']['airingAt'],
                        (int) ( $media['nextAiringEpisode']['episode'] ?? 0 )
                    )
                );
            } else {
                delete_post_meta( $post_id, 'anime_next_airing' );
            }
        }

        if ( ! $is_locked( 'anime_score_anilist' ) && isset( $media['averageScore'] ) && $media['averageScore'] !== null ) {
            $old_val = (int) get_post_meta( $post_id, 'anime_score_anilist', true );
            $new_val = (int) $media['averageScore'];
            if ( $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_score_anilist', $new_val );
                $diff[] = 'AniList評分 ' . $old_val . '→' . $new_val;
            }
        }

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

        /*
         * 季度必須有年份支撐才寫入（與 class-api-handler.php 的匯入端一致）。
         *
         * 「宣布動畫化、製作進行中」的作品，AniList 常常 season 有值但
         * seasonYear 是 null——片商只先發製作消息，年份都還沒定。寫進去會得到
         * 「冬季，第 0 年」這種不指涉任何時間的資料。
         *
         * 這裡少了這道檢查的話，就算把既有壞資料清乾淨，下一次每日更新
         * 又會原封不動寫回來。
         */
        $upstream_season_year = (int) ( $media['seasonYear'] ?? 0 );

        if ( ! $is_locked( 'anime_season' )
             && isset( $media['season'] )
             && $media['season'] !== null
             && $media['season'] !== ''
             && $upstream_season_year > 0 ) {
            $old_val = (string) get_post_meta( $post_id, 'anime_season', true );
            $new_val = (string) $media['season'];
            if ( $old_val !== $new_val ) {
                update_post_meta( $post_id, 'anime_season', $new_val );
                $diff[] = '季度 ' . $old_val . '→' . $new_val;
            }
        }

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

        /*
         * ★ 精度規則與上面的 anime_start_date 對齊：年月日俱全才寫入。
         *
         *   原本只要有「年」就寫、月日缺就補 1，於是「只知道 2026 年完結」
         *   被存成 20260101，前台顯示成精確的 1 月 1 日——那是站方憑空補出來
         *   的假精度，會誤導讀者。同一支程式裡兩個日期欄位用不同規則，沒有
         *   理由；匯入端的 parse_fuzzy_date() 也是年月日俱全才寫。
         */
        if ( ! $is_locked( 'anime_end_date' )
          && ! empty( $media['endDate']['year'] )
          && ! empty( $media['endDate']['month'] )
          && ! empty( $media['endDate']['day'] ) ) {
            $end_date = sprintf(
                '%04d%02d%02d',
                (int) $media['endDate']['year'],
                (int) $media['endDate']['month'],
                (int) $media['endDate']['day']
            );
            $old_val = (string) get_post_meta( $post_id, 'anime_end_date', true );
            if ( $old_val !== $end_date ) {
                update_post_meta( $post_id, 'anime_end_date', $end_date );
                $diff[] = '完結日 ' . $old_val . '→' . $end_date;
            }
        }

        update_post_meta( $post_id, 'anime_dynamic_synced_at', current_time( 'mysql' ) );

        $this->detect_upstream_cast_growth( $post_id, $media, $diff );

        if ( empty( $diff ) ) {
            return 'skipped';
        }
        return 'updated:' . implode( '、', $diff );
    }

    /**
     * 以 AniList 的 staff／characters 筆數變化，偵測上游是否補了班底資料。
     *
     * 為什麼用 pageInfo.total：
     *   這是唯一免費的變更訊號——查詢每小時本來就在打，只多兩個整數
     *   （實測整包回應 343 bytes），請求數完全不變。未播出作品的 staff／
     *   cast 會隨宣傳進度陸續補齊，但那些欄位匯入後就沒有任何機制再更新，
     *   等於資料停在建檔當下。
     *
     * 500 的意義：
     *   AniList 對這個 total 有 500 的截斷上限，長篇大作（NARUTO、ONE PIECE、
     *   進擊的巨人）一律回 500，不是真實筆數。但目標族群——未播出與新作品
     *   ——筆數遠低於此（實測琉璃龍 1、小紅帽 10），完全可用。達到 500 就
     *   跳過判斷，不拿假數字誤觸。
     *
     * 為什麼首次不觸發：
     *   既有作品都還沒有這兩個 meta，若把「無→有」視為變化，第一輪就會對
     *   全站每一部排 enrich。只有在基準值建立之後的變動才算數。
     */
    private function detect_upstream_cast_growth( int $post_id, array $media, array &$diff ): void {
        $totals = [
            'anime_al_staff_total' => [
                'value' => (int) ( $media['staff']['pageInfo']['total'] ?? 0 ),
                'label' => 'AniList staff',
            ],
            'anime_al_cast_total'  => [
                'value' => (int) ( $media['characters']['pageInfo']['total'] ?? 0 ),
                'label' => 'AniList cast',
            ],
        ];

        $grew = false;

        foreach ( $totals as $meta_key => $info ) {
            $total = $info['value'];

            if ( $total <= 0 || $total >= 500 ) {
                continue;
            }

            $stored = get_post_meta( $post_id, $meta_key, true );
            update_post_meta( $post_id, $meta_key, $total );

            if ( $stored === '' ) {
                continue;
            }

            if ( (int) $stored !== $total ) {
                $grew   = true;
                $diff[] = sprintf( '%s %d→%d', $info['label'], (int) $stored, $total );
            }
        }

        if ( ! $grew ) {
            return;
        }

        /*
         * 上游確實動了才排 enrich。走既有的單次事件機制，非同步執行，
         * 不在每小時這輪裡同步打 Bangumi／Jikan／AnimeThemes。
         * enrich 端的 keep_if_fewer() 會處理覆蓋護欄（只增不減、尊重鎖定）。
         */
        delete_post_meta( $post_id, '_enriched_at' );

        if ( ! wp_next_scheduled( 'anime_sync_enrich_post', [ $post_id ] ) ) {
            wp_schedule_single_event( time() + 60, 'anime_sync_enrich_post', [ $post_id ] );
        }
    }

    private function fetch_anilist_dynamic( int $anilist_id ): ?array {
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
                staff { pageInfo { total } }
                characters { pageInfo { total } }
                relations {
                    edges {
                        relationType
                        node { type }
                    }
                }
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
    // =========================================================================

    private function run_score_backfill_batch(): void {
        $queue      = get_option( self::SCORE_BACKFILL_QUEUE_OPTION, null );
        $last_build = (int) get_option( self::LAST_SCORE_BACKFILL_BUILD_OPTION, 0 );
        $build_due  = ( time() - $last_build ) >= self::SCORE_BACKFILL_REBUILD_INTERVAL;

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

            $mal_no_score = (int) get_post_meta( $id, 'anime_mal_no_score', true ) === 1;

            $needs_mal = $mal_id > 0
                && ! $mal_no_score
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

    private function backfill_score_for_post( int $post_id ): void {
        $locked = get_post_meta( $post_id, 'anime_locked_fields', true );
        if ( ! is_array( $locked ) ) {
            $locked = [];
        }

        $got_something = false;

        $mal_id      = (int) get_post_meta( $post_id, 'anime_mal_id', true );
        $current_mal = (int) get_post_meta( $post_id, 'anime_score_mal', true );

        $mal_no_score_flag = (int) get_post_meta( $post_id, 'anime_mal_no_score', true ) === 1;

        if ( $mal_id > 0 && $current_mal <= 0 && ! $mal_no_score_flag && ! in_array( 'anime_score_mal', $locked, true ) ) {
            $this->rate_limiter->wait_if_needed( 'mal' );
            $score = $this->api_handler->fetch_mal_score_public( $mal_id );
            if ( $score > 0 ) {
                update_post_meta( $post_id, 'anime_score_mal', $score );
                $got_something = true;
            } else {
                // 抓到 0：需區分「MAL 尚未開分」與「暫時性抓取失敗」。
                // 直接查 MAL 官方 API v2，看回應是否含 mean 欄位。
                $mal_confirmed_no_score = $this->confirm_mal_has_no_score( $mal_id );
                if ( $mal_confirmed_no_score === true ) {
                    // MAL 官方確認尚未開分：打標記，之後佇列會跳過，不再浪費請求，也不累加 retry。
                    update_post_meta( $post_id, 'anime_mal_no_score', 1 );
                    $this->logger->log( 'info', '評分回補：MAL 尚未開分，標記略過', [
                        'post_id' => $post_id,
                        'mal_id'  => $mal_id,
                    ] );
                }
                // $mal_confirmed_no_score === false（MAL 有分卻沒抓到）或 null（確認請求也失敗）：
                // 維持原行為，讓後面的 retry 計數處理（屬暫時性失敗，之後會重試）。
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

    /**
     * ★ 確認某個 MAL ID 在官方 API 是否「尚未開分」。
     *
     * 回傳：
     *   true  = MAL 官方回 200 但無 mean 欄位（確認尚未開分）→ 呼叫端可打標記略過。
     *   false = MAL 官方回 200 且有 mean（有分只是這次沒抓到）→ 屬暫時性失敗，應重試。
     *   null  = 確認請求本身失敗（cURL 錯誤 / 非 200 / Client ID 缺失）→ 無法判定，視為暫時性失敗。
     *
     * @param int $mal_id
     * @return bool|null
     */
    private function confirm_mal_has_no_score( int $mal_id ): ?bool {
        if ( $mal_id <= 0 ) {
            return null;
        }

        $client_id = defined( 'MAL_CLIENT_ID' ) ? MAL_CLIENT_ID : '';
        if ( $client_id === '' ) {
            return null; // 無 Client ID，無法確認
        }

        $this->rate_limiter->wait_if_needed( 'mal' );

        $response = wp_remote_get(
            'https://api.myanimelist.net/v2/anime/' . $mal_id . '?fields=mean',
            [
                'timeout' => 15,
                'headers' => [
                    'User-Agent'      => self::get_anilist_user_agent(),
                    'X-MAL-CLIENT-ID' => $client_id,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }
        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        // 有 mean 且 > 0 → 有分（false=不是沒開分）；否則 → 確認尚未開分（true）。
        if ( isset( $data['mean'] ) && (float) $data['mean'] > 0 ) {
            return false;
        }
        return true;
    }

    // =========================================================================
    // ✅ [v1.5.1] 任務七：角色/聲優 BGM 資料回補（原 mu-plugin 併入）
    // ✅ [v1.5.2] 新增全自動接力：persons → characters → off
    // ✅ [v1.5.3] log 內文時間改用 current_time()（僅顯示字串，邏輯不變）
    //
    // 開關（一般不需手動；系統會自己接力。仍可用 option / 後台強制切換）：
    //   wp option update anime_sync_entity_backfill_mode characters
    //   wp option update anime_sync_entity_backfill_mode persons
    //   wp option update anime_sync_entity_backfill_mode off
    // 批次量：
    //   wp option update anime_sync_entity_backfill_batch 60
    // =========================================================================

    public function run_entity_backfill(): void {
        if ( function_exists( 'wp_get_environment_type' )
             && wp_get_environment_type() === 'local' ) {
            return;
        }

        $mode = (string) get_option( self::ENTITY_BACKFILL_MODE_OPTION, 'off' );
        if ( ! in_array( $mode, [ 'characters', 'persons' ], true ) ) {
            return; // 'off' 或無效值
        }

        if ( get_transient( 'anime_sync_lock_entity_backfill' ) ) {
            return;
        }
        set_transient( 'anime_sync_lock_entity_backfill', 1, self::LOCK_TTL_ENTITY_BACKFILL );

        try {
            $this->_run_entity_backfill_inner( $mode );
        } finally {
            delete_transient( 'anime_sync_lock_entity_backfill' );
        }
    }

    private function _run_entity_backfill_inner( string $mode ): void {
        global $wpdb;

        if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
            $this->logger->log( 'error', '實體回補：Anime_Sync_Entity_Migrator 尚未載入' );
            return;
        }

        $migrator = new Anime_Sync_Entity_Migrator( $this->rate_limiter );

        $batch = (int) get_option( self::ENTITY_BACKFILL_BATCH_OPTION, self::ENTITY_BACKFILL_BATCH_DEFAULT );
        if ( $batch <= 0 ) {
            $batch = self::ENTITY_BACKFILL_BATCH_DEFAULT;
        }

        if ( $mode === 'persons' ) {
            $table    = $wpdb->prefix . 'anime_persons';
            $method   = 'backfill_persons';
            $skip_key = self::ENTITY_BACKFILL_SKIP_PERSONS_OPTION;
            $where_missing = "( infobox_json IS NULL OR infobox_json = '' )";
        } else {
            $table    = $wpdb->prefix . 'anime_characters';
            $method   = 'backfill_characters';
            $skip_key = self::ENTITY_BACKFILL_SKIP_CHARS_OPTION;
            $where_missing = "( summary IS NULL OR summary = ''
                             OR name_cn IS NULL OR name_cn = ''
                             OR infobox_json IS NULL OR infobox_json = '' )";
        }

        $skip = get_option( $skip_key, [] );
        if ( ! is_array( $skip ) ) {
            $skip = [];
        }

        $not_in = '';
        if ( ! empty( $skip ) ) {
            $skip_ints = array_map( 'intval', $skip );
            $not_in    = ' AND bgm_id NOT IN (' . implode( ',', $skip_ints ) . ')';
        }

        // $table / $where_missing / $not_in 皆為內部組成（skip 已 intval），無外部輸入。
        $ids = $wpdb->get_col(
            "SELECT bgm_id FROM {$table}
             WHERE bgm_id > 0 AND {$where_missing} {$not_in}
             LIMIT {$batch}"
        );

        // =====================================================================
        // ✅ [v1.5.2] 目前模式（扣掉跳過名單後）已無待補項目
        //   → 需判斷是「真的補完」還是「這批剛好全在跳過名單裡（假空）」，
        //     只有真的補完才自動切換到下一階段。
        // =====================================================================
        if ( empty( $ids ) ) {

            // 繞過跳過名單，查全站該類仍有多少缺漏（真實剩餘量）。
            $true_remaining = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table}
                 WHERE bgm_id > 0 AND {$where_missing}"
            );

            if ( $true_remaining > 0 ) {
                // 「假空」：仍有缺漏，只是全落在跳過名單內（BGM 整筆無資料）。
                // 維持原模式不切換，避免把還沒補完的階段誤判為完成。
                self::update_cron_option(
                    self::ENTITY_BACKFILL_LAST_OPTION,
                    current_time( 'Y-m-d H:i:s' ) . ' | ' . $table
                        . ' 剩餘 ' . $true_remaining . ' 筆全在跳過名單內（BGM 無資料），維持 '
                        . $mode . ' 模式，不切換 (跳過名單 ' . count( $skip ) . ' 筆)'
                );
                return;
            }

            // 「真空」：全站該類已無任何缺漏 → 自動接力到下一階段。
            //   persons(聲優) 補完 → characters(角色)
            //   characters(角色) 補完 → off（停止空轉）
            $next_mode = ( $mode === 'persons' ) ? 'characters' : 'off';
            update_option( self::ENTITY_BACKFILL_MODE_OPTION, $next_mode );

            $note = ( $next_mode === 'off' )
                ? '角色與聲優皆已補完，自動關閉回補'
                : '聲優已補完，自動切換為角色（characters）模式';

            self::update_cron_option(
                self::ENTITY_BACKFILL_LAST_OPTION,
                current_time( 'Y-m-d H:i:s' ) . ' | ' . $table
                    . ' 已無待補（跳過名單 ' . count( $skip ) . ' 筆）→ ' . $note
            );
            $this->logger->log( 'info', '實體回補：' . $note );
            return;
        }

        $updated = 0;
        $no_data = 0;

        foreach ( $ids as $id ) {
            $id = (int) $id;
            $r  = [ 'updated' => 0, 'failed' => 0 ];
            try {
                $r = $migrator->{$method}( [ 'bgm_id' => $id, 'force' => true ] );
            } catch ( \Throwable $e ) {
                $this->logger->log( 'error', '實體回補例外 bgm_id=' . $id . ' : ' . $e->getMessage() );
            }
            if ( ! empty( $r['updated'] ) && $r['updated'] > 0 ) {
                $updated++;
            } elseif ( ! empty( $r['failed'] ) && $r['failed'] > 0 ) {
                $skip[] = $id;
                $no_data++;
            }
        }

        $skip = array_values( array_unique( array_map( 'intval', $skip ) ) );
        self::update_cron_option( $skip_key, $skip );

        $summary = current_time( 'Y-m-d H:i:s' ) . ' | ' . $table
            . ' 這批 ' . count( $ids ) . ' 筆，實補=' . $updated
            . '，BGM無資料跳過=' . $no_data
            . '，跳過名單累計=' . count( $skip );

        self::update_cron_option( self::ENTITY_BACKFILL_LAST_OPTION, $summary );
        $this->logger->log( 'info', '實體回補：' . $summary );
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
    // 任務：角色/聲優簡介翻譯（日文→繁中，DeepL；已是中文則走 OpenCC）
    // =========================================================================

    /**
     * 每次執行有上限（--limit 概念，這裡固定 300），避免單次排程就把整月
     * DeepL 免費額度用光；新角色/聲優也是靠這個排程逐週撿到，不用另外掛
     * 同步時即時翻譯的 hook。沒設定 DeepL 金鑰時，migrator 內部方法會直接
     * 回傳 error 訊息，這裡記錄一筆 log 就跳過，不影響其他排程。
     */
    public function run_translate_summaries(): void {
        if ( get_transient( 'anime_sync_lock_translate_summaries' ) ) {
            return;
        }
        // 沿用季度匯入同一顆鎖 TTL（1 小時）：這個工作跟季度匯入一樣是
        // 逐筆迴圈＋rate limit，5 分鐘的 ENTITY_BACKFILL 鎖對它太短。
        set_transient( 'anime_sync_lock_translate_summaries', 1, self::LOCK_TTL_SEASON );

        try {
            if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
                return;
            }

            $migrator    = new Anime_Sync_Entity_Migrator( $this->rate_limiter );
            $batch_limit = 300;

            $char_stats = $migrator->translate_character_summaries( [ 'limit' => $batch_limit ] );
            if ( isset( $char_stats['error'] ) ) {
                $this->logger->log( 'info', '角色簡介翻譯：' . $char_stats['error'] );
            } else {
                $this->logger->log( 'info', '角色簡介翻譯完成', $char_stats );
            }

            $person_stats = $migrator->translate_person_summaries( [ 'limit' => $batch_limit ] );
            if ( isset( $person_stats['error'] ) ) {
                $this->logger->log( 'info', '人物簡介翻譯：' . $person_stats['error'] );
            } else {
                $this->logger->log( 'info', '人物簡介翻譯完成', $person_stats );
            }
        } finally {
            delete_transient( 'anime_sync_lock_translate_summaries' );
        }
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

    private function build_themes_episodes_queue(): array {
        $window_start = (int) gmdate( 'Ymd', strtotime( '-' . self::THEMES_UPCOMING_WINDOW_DAYS . ' days' ) );
        $window_end   = (int) gmdate( 'Ymd', strtotime( '+' . self::THEMES_UPCOMING_WINDOW_DAYS . ' days' ) );
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
                $end = (int) get_post_meta( $id, 'anime_end_date', true );
                if ( ( $end > 0 && $end >= $cutoff_ymd ) || $this->themes_still_empty( $id ) ) {
                    $items[] = [ 'id' => $id, 'prio' => self::PRIO_FINISHED, 'sort' => $end ];
                }
            }
        }

        return self::sort_queue_new_first( $items );
    }

    /**
     * 已完結作品若主題曲仍是空陣列，即使完結超過 30 天也繼續排進候選名單
     * （AnimeThemes 常常是作品完結後才慢慢補資料）；一旦抓到資料就自動畢業，
     * 不會再被排入。
     */
    private function themes_still_empty( int $post_id ): bool {
        $themes = json_decode( (string) get_post_meta( $post_id, 'anime_themes', true ), true );
        return ! is_array( $themes ) || count( $themes ) === 0;
    }

    private function sync_themes_for_post( int $post_id ): int {
        $mal_id = (int) get_post_meta( $post_id, 'anime_mal_id', true );

        $at_slug = trim( (string) get_post_meta( $post_id, 'anime_animethemes_slug', true ) );
        if ( $at_slug === '' ) {
            $at_slug = trim( (string) get_post_meta( $post_id, 'animethemes_slug', true ) );
        }

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

        $api_result = $this->import_manager->fetch_themes_only( $mal_id, $at_slug );

        if ( empty( $api_result['themes'] ) || ! is_array( $api_result['themes'] ) ) {
            update_post_meta( $post_id, 'anime_themes_synced_at', current_time( 'mysql' ) );
            return 0;
        }

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

    private function sync_episodes_for_post( int $post_id ): int {
        $bgm_id = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
        if ( $bgm_id <= 0 ) {
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
            return 0;
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
        $new_episodes = $this->import_manager->fetch_episodes_only( $bgm_id, false, $post_id );

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
                    continue;
                }

                $idx      = $old_index[ $ep_id ];
                $existing = $old_episodes[ $idx ];

                foreach ( [ 'name', 'name_cn', 'airdate' ] as $field ) {
                    $existing_val = trim( (string) ( $existing[ $field ] ?? '' ) );
                    $new_val      = trim( (string) ( $new_ep[ $field ]  ?? '' ) );
                    if ( $existing_val === '' && $new_val !== '' ) {
                        $existing[ $field ] = $new_val;
                        $changed++;
                    }
                }

                $new_comment = (int) ( $new_ep['comment'] ?? 0 );
                if ( (int) ( $existing['comment'] ?? 0 ) !== $new_comment ) {
                    $existing['comment'] = $new_comment;
                }
                if ( isset( $new_ep['ep'] ) && ( $existing['ep'] ?? null ) !== $new_ep['ep'] ) {
                    $existing['ep'] = $new_ep['ep'];
                }

                $old_episodes[ $idx ] = $existing;
            } else {
                $old_episodes[]       = $new_ep;
                $old_index[ $ep_id ]  = count( $old_episodes ) - 1;
                $changed++;
            }
        }

        if ( $changed === 0 ) {
            return 0;
        }

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

    private function anilist_request( string $query, array $variables = [] ): ?array {
        $this->last_anilist_http_code = 0;

        $response = wp_remote_post( 'https://graphql.anilist.co', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
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

        $this->last_anilist_http_code = (int) $code;

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