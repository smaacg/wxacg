<?php
/**
 * Admin Page: Settings
 *
 * @package Anime_Sync_Pro
 * @version 5.3.0
 *
 * Changelog:
 *  - 5.4.0 (2026-08-10):
 *      • [Entity Backfill] 新增「角色/聲優資料回補」設定卡片（搭配
 *        class-cron-manager.php v1.5.1 任務七）：
 *        - 回補模式下拉（關閉 / 角色 / 聲優）寫入 option
 *          anime_sync_entity_backfill_mode，不再需要 WP-CLI。
 *        - 批次量（anime_sync_entity_backfill_batch，10–200）。
 *        - 顯示上次執行摘要（anime_sync_last_entity_backfill）與
 *          兩份跳過名單筆數，附清空跳過名單按鈕（AJAX）。
 *        - 排程狀態列表新增「角色/聲優回補」列。
 *  - 5.3.0 (2026-06-27):
 *      • 手機板響應式全面優化。
 *      • Settings Card 在手機板移除 max-width 限制，全寬顯示。
 *      • form-table 在手機板改為 block 堆疊（th/td 各佔一行）。
 *      • 排程狀態列在手機板垂直堆疊，避免時間文字換行破版。
 *      • 快取管理 / DB 日誌 / Log 檔案操作列在手機板全寬垂直排列。
 *      • select 下拉在手機板全寬。
 *      • Toast 在手機板移至底部置中。
 *      • JS 全部從 const/let 改為 var 確保 PHP 7.x 環境相容。
 *  - 5.2.0 (2026-06-24):
 *      • [Fix-Schedule] 儲存設定時重排 cron 改用 anime_sync_hourly。
 *      • [Fix-Time] $fmt_datetime helper 移除 wp_date() 時區轉換。
 *  - 5.1.0 (2026-06-19):
 *      • 強制重新排程按鈕改為真正呼叫 AJAX action anime_sync_reschedule_cron。
 *  - 5.0.0 (2026-05-10):
 *      • 拆分「清除 DB 日誌」與「清除 Log 檔案」為兩個獨立按鈕。
 *      • 加上儲存按鈕 disabled 防重複提交。
 *      • alert() 全部改為 toast 提示。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
}

/* ───────────────────────────────────────────────
   一次性遷移：舊鍵 → 新鍵
─────────────────────────────────────────────── */
if ( ! get_option( 'anime_sync_settings_migrated_v2' ) ) {
    $old_daily = get_option( 'anime_sync_daily_hour_taipei', null );
    if ( $old_daily !== null && $old_daily !== false ) {
        $existing_new = get_option( 'anime_sync_daily_hour', null );
        if ( $existing_new === null || $existing_new === false ) {
            update_option( 'anime_sync_daily_hour', (int) $old_daily );
        }
        delete_option( 'anime_sync_daily_hour_taipei' );
    }
    $old_batch = get_option( 'anime_sync_rating_batch_size', null );
    if ( $old_batch !== null && $old_batch !== false ) {
        $existing_new = get_option( 'anime_sync_batch_size', null );
        if ( $existing_new === null || $existing_new === false ) {
            update_option( 'anime_sync_batch_size', (int) $old_batch );
        }
        delete_option( 'anime_sync_rating_batch_size' );
    }
    delete_option( 'anime_sync_weekly_hour_taipei' );
    update_option( 'anime_sync_settings_migrated_v2', 1 );
}

/* ───────────────────────────────────────────────
   Helper: 站台時區 / UTC 轉換
─────────────────────────────────────────────── */
$site_tz_string = wp_timezone_string();
$site_tz        = wp_timezone();

$local_to_utc_hour = static function ( int $local_hour ) use ( $site_tz ): int {
    try {
        $dt = new \DateTimeImmutable( sprintf( '%s %02d:00:00', wp_date( 'Y-m-d' ), $local_hour ), $site_tz );
        return (int) $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'G' );
    } catch ( \Throwable $e ) {
        return $local_hour;
    }
};

$utc_to_local_hour = static function ( int $utc_hour ) use ( $site_tz ): int {
    try {
        $dt = new \DateTimeImmutable( sprintf( '%s %02d:00:00', gmdate( 'Y-m-d' ), $utc_hour ), new \DateTimeZone( 'UTC' ) );
        return (int) $dt->setTimezone( $site_tz )->format( 'G' );
    } catch ( \Throwable $e ) {
        return $utc_hour;
    }
};

$fmt_datetime = static function ( $raw ): string {
    if ( empty( $raw ) ) return '—';
    if ( is_numeric( $raw ) ) return date( 'Y-m-d H:i', (int) $raw );
    $str = (string) $raw;
    return strlen( $str ) >= 16 ? substr( $str, 0, 16 ) : $str;
};

/* ───────────────────────────────────────────────
   Handle form save
─────────────────────────────────────────────── */
$saved = false;
if (
    isset( $_POST['anime_sync_settings_nonce'] ) &&
    wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anime_sync_settings_nonce'] ) ), 'anime_sync_save_settings' )
) {
    $old_daily_hour_utc = (int) get_option( 'anime_sync_daily_hour', 3 );

    $new_site_name = isset( $_POST['anime_sync_site_name'] )
        ? sanitize_text_field( wp_unslash( $_POST['anime_sync_site_name'] ) ) : '';
    $new_site_url  = isset( $_POST['anime_sync_site_url'] )
        ? esc_url_raw( wp_unslash( $_POST['anime_sync_site_url'] ) ) : '';

    $local_hour_input   = isset( $_POST['anime_sync_daily_hour_local'] )
        ? max( 0, min( 23, (int) $_POST['anime_sync_daily_hour_local'] ) ) : 11;
    $new_daily_hour_utc = $local_to_utc_hour( $local_hour_input );

    $new_batch_size    = isset( $_POST['anime_sync_batch_size'] )
        ? max( 5, min( 100, (int) $_POST['anime_sync_batch_size'] ) ) : 15;

    // ✅ [5.4.0] 角色/聲優回補設定
    $new_backfill_mode = isset( $_POST['anime_sync_entity_backfill_mode'] )
        ? sanitize_key( wp_unslash( $_POST['anime_sync_entity_backfill_mode'] ) ) : 'off';
    if ( ! in_array( $new_backfill_mode, array( 'off', 'characters', 'persons' ), true ) ) {
        $new_backfill_mode = 'off';
    }
    $new_backfill_batch = isset( $_POST['anime_sync_entity_backfill_batch'] )
        ? max( 10, min( 200, (int) $_POST['anime_sync_entity_backfill_batch'] ) ) : 60;
    $new_log_retention = isset( $_POST['anime_sync_log_retention_days'] )
        ? max( 1, min( 365, (int) $_POST['anime_sync_log_retention_days'] ) ) : 30;
    $new_debug_mode    = isset( $_POST['anime_sync_debug_mode'] ) ? 1 : 0;

    // ✅ 角色/聲優簡介翻譯：DeepL API 金鑰（Free 版金鑰結尾是 :fx）。
    // 空字串代表「不變更」，避免每次存檔沒填就把已存的金鑰清空。
    $new_deepl_key_raw = isset( $_POST['anime_sync_deepl_api_key'] )
        ? trim( sanitize_text_field( wp_unslash( $_POST['anime_sync_deepl_api_key'] ) ) ) : '';

    update_option( 'anime_sync_site_name',          $new_site_name );
    update_option( 'anime_sync_site_url',           $new_site_url );
    update_option( 'anime_sync_daily_hour',         $new_daily_hour_utc );
    update_option( 'anime_sync_batch_size',         $new_batch_size );
    update_option( 'anime_sync_log_retention_days', $new_log_retention );
    update_option( 'anime_sync_debug_mode',         $new_debug_mode );

    if ( $new_deepl_key_raw !== '' ) {
        update_option( 'anime_sync_deepl_api_key', $new_deepl_key_raw );
    }

    // ✅ [5.4.0] 角色/聲優回補（autoload 不影響前台，量小直接 update_option 即可）
    update_option( 'anime_sync_entity_backfill_mode',  $new_backfill_mode );
    update_option( 'anime_sync_entity_backfill_batch', $new_backfill_batch );

    if ( $new_daily_hour_utc !== $old_daily_hour_utc ) {
        $daily_hook  = 'anime_sync_daily_score_update';
        $today_utc   = strtotime( gmdate( "Y-m-d {$new_daily_hour_utc}:00:00" ) );
        $start_daily = $today_utc < time() ? $today_utc + DAY_IN_SECONDS : $today_utc;
        wp_clear_scheduled_hook( $daily_hook );
        wp_schedule_event( $start_daily, 'anime_sync_hourly', $daily_hook );

        $themes_hook  = 'anime_sync_themes_episodes_update';
        $themes_hour  = ( $new_daily_hour_utc + 2 ) % 24;
        $today_themes = strtotime( gmdate( "Y-m-d {$themes_hour}:30:00" ) );
        $start_themes = $today_themes < time() ? $today_themes + DAY_IN_SECONDS : $today_themes;
        wp_clear_scheduled_hook( $themes_hook );
        wp_schedule_event( $start_themes, 'anime_sync_hourly', $themes_hook );
    }

    $saved = true;
}

/* ───────────────────────────────────────────────
   Read current values
─────────────────────────────────────────────── */
$site_name        = get_option( 'anime_sync_site_name', get_bloginfo( 'name' ) );
$site_url_opt     = get_option( 'anime_sync_site_url',  get_site_url() );
$daily_hour_utc   = (int) get_option( 'anime_sync_daily_hour',         3 );
$daily_hour_local = $utc_to_local_hour( $daily_hour_utc );
$batch_size       = (int) get_option( 'anime_sync_batch_size',        15 );
$log_retention    = (int) get_option( 'anime_sync_log_retention_days', 30 );
$debug_mode       = (int) get_option( 'anime_sync_debug_mode',          0 );

// ✅ 角色/聲優簡介翻譯：DeepL 金鑰只顯示狀態，絕不把已存的金鑰值印回頁面原始碼。
$deepl_key_current  = trim( (string) get_option( 'anime_sync_deepl_api_key', '' ) );
$deepl_key_is_set   = $deepl_key_current !== '';
$deepl_key_is_free  = $deepl_key_is_set && str_ends_with( $deepl_key_current, ':fx' );

// ✅ [5.4.0] 角色/聲優回補目前狀態
$backfill_mode  = (string) get_option( 'anime_sync_entity_backfill_mode', 'off' );
if ( ! in_array( $backfill_mode, array( 'off', 'characters', 'persons' ), true ) ) {
    $backfill_mode = 'off';
}
$backfill_batch = (int) get_option( 'anime_sync_entity_backfill_batch', 60 );
if ( $backfill_batch <= 0 ) {
    $backfill_batch = 60;
}
$backfill_last  = (string) get_option( 'anime_sync_last_entity_backfill', '' );

$backfill_skip_chars   = get_option( 'anime_sync_backfill_skip_chars', array() );
$backfill_skip_persons = get_option( 'anime_sync_backfill_skip_persons', array() );
$skip_chars_count   = is_array( $backfill_skip_chars )   ? count( $backfill_skip_chars )   : 0;
$skip_persons_count = is_array( $backfill_skip_persons ) ? count( $backfill_skip_persons ) : 0;

// 待補數量統計（與 Cron Manager 任務七的 where 條件一致）
global $wpdb;
$backfill_pending_chars = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}anime_characters
     WHERE bgm_id > 0 AND ( summary IS NULL OR summary = ''
        OR name_cn IS NULL OR name_cn = ''
        OR infobox_json IS NULL OR infobox_json = '' )"
);
$backfill_pending_persons = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}anime_persons
     WHERE bgm_id > 0 AND ( infobox_json IS NULL OR infobox_json = '' )"
);

/* ────────────────────────────────────────────────────────────
   回補預估剩餘時間

   上面那兩個數字是「原始待補量」，看了不知道還要跑多久，每次都得
   自己換算。這裡把它算成時間直接顯示。

   三個地方刻意不寫死：

   1. 每批筆數取 $backfill_batch，不是常數 60 ——
      使用者可以在這一頁把它改成 10~200，寫死會讓估算失準。

   2. 排程間隔讀 wp_get_schedules() 的實際註冊值，不是寫死 300 秒 ——
      間隔定義在 Cron Manager 的 cron_schedules filter 裡
      （anime_sync_five_min => 5 * MINUTE_IN_SECONDS），日後若調整，
      這裡會自動跟著變。

   3. 估算前先扣掉跳過名單。跳過名單裡的條目是 BGM 整筆查無資料、
      永遠補不起來的，但它們的欄位仍是空的，所以「原始待補量」把它們
      也算了進去。不扣掉會一直高估，而且永遠等不到歸零。
   ──────────────────────────────────────────────────────────── */
$backfill_schedules = wp_get_schedules();
$backfill_interval  = isset( $backfill_schedules['anime_sync_five_min']['interval'] )
    ? (int) $backfill_schedules['anime_sync_five_min']['interval']
    : 5 * MINUTE_IN_SECONDS;
if ( $backfill_interval <= 0 ) {
    $backfill_interval = 5 * MINUTE_IN_SECONDS;
}

// 扣除跳過名單後、真正還會被處理的量
$backfill_todo_chars   = max( 0, $backfill_pending_chars   - $skip_chars_count );
$backfill_todo_persons = max( 0, $backfill_pending_persons - $skip_persons_count );

// 每小時處理量 = 每批筆數 × 每小時執行次數
$backfill_per_hour = (int) round( $backfill_batch * ( HOUR_IN_SECONDS / $backfill_interval ) );

/**
 * 把小時數格式化成好讀的字串。
 *
 * @param float $hours 預估小時數
 * @return string
 */
$backfill_format_eta = static function ( $hours ) {
    $hours = (float) $hours;
    if ( $hours <= 0 ) {
        return __( '即將完成', 'anime-sync-pro' );
    }
    if ( $hours < 1 ) {
        return sprintf(
            /* translators: %d: 分鐘數 */
            __( '約 %d 分鐘', 'anime-sync-pro' ),
            max( 1, (int) round( $hours * 60 ) )
        );
    }
    if ( $hours < 48 ) {
        return sprintf(
            /* translators: %s: 小時數 */
            __( '約 %s 小時', 'anime-sync-pro' ),
            number_format_i18n( $hours, 1 )
        );
    }
    return sprintf(
        /* translators: %s: 天數 */
        __( '約 %s 天', 'anime-sync-pro' ),
        number_format_i18n( $hours / 24, 1 )
    );
};

$plugin_version = defined( 'ANIME_SYNC_PRO_VERSION' )
    ? ANIME_SYNC_PRO_VERSION
    : ( defined( 'ANIME_SYNC_VERSION' ) ? ANIME_SYNC_VERSION : '1.0.0' );

/* ───────────────────────────────────────────────
   Bangumi ID 對照表狀態
─────────────────────────────────────────────── */
$map_exists  = false;
$map_count   = 0;
$mal_count   = 0;
$map_size    = 0;
$map_updated = '—';
$map_age_h   = 0;

if ( class_exists( 'Anime_Sync_ID_Mapper' ) ) {
    $mapper     = new Anime_Sync_ID_Mapper();
    $map_status = $mapper->get_map_status();
    $map_exists  = ! empty( $map_status['exists'] );
    $map_count   = (int) ( isset( $map_status['entry_count'] ) ? $map_status['entry_count'] : 0 );
    $mal_count   = (int) ( isset( $map_status['mal_count'] )   ? $map_status['mal_count']   : 0 );
    $map_size    = (int) ( isset( $map_status['size'] )        ? $map_status['size']         : 0 );
    $map_updated = ! empty( $map_status['last_updated'] ) ? $fmt_datetime( $map_status['last_updated'] ) : '—';
    $map_age_h   = (float) ( isset( $map_status['age_hours'] ) ? $map_status['age_hours'] : 0 );
}

/* ───────────────────────────────────────────────
   Log file info
─────────────────────────────────────────────── */
$upload_dir  = wp_upload_dir();
$log_dir     = trailingslashit( $upload_dir['basedir'] ) . 'anime-sync-pro/logs/';
$log_files   = is_dir( $log_dir ) ? ( glob( $log_dir . '*.log' ) ?: array() ) : array();
$log_count   = count( $log_files );
$log_size    = 0;
foreach ( $log_files as $lf ) {
    if ( is_file( $lf ) ) $log_size += (int) filesize( $lf );
}
$log_size_kb = round( $log_size / 1024, 1 );

/* ───────────────────────────────────────────────
   DB 日誌數量
─────────────────────────────────────────────── */
$db_log_total = 0;
if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
    global $wpdb;
    $db_log_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}anime_sync_logs" );
}

/* ───────────────────────────────────────────────
   Cron 狀態
─────────────────────────────────────────────── */
$next_daily      = wp_next_scheduled( 'anime_sync_daily_score_update' );
$next_themes_eps = wp_next_scheduled( 'anime_sync_themes_episodes_update' );
$next_weekly     = wp_next_scheduled( 'anime_sync_weekly_cleanup' );
$next_update_map = wp_next_scheduled( 'anime_sync_update_anime_map' );
$next_backfill   = wp_next_scheduled( 'anime_sync_entity_backfill' ); // ✅ [5.4.0]
$last_daily_run  = get_option( 'anime_sync_last_daily_run', '' );
$last_weekly_run = get_option( 'anime_sync_last_weekly_cleanup', '' );
$last_themes_run = get_option( 'anime_sync_last_themes_episodes_run', '' );

$admin_nonce = wp_create_nonce( 'anime_sync_admin_nonce' );

$cron_rows = array(
    array( '每日評分同步',     $next_daily,      $last_daily_run  ),
    array( '主題曲＋集數同步', $next_themes_eps, $last_themes_run ),
    array( '每週清理',         $next_weekly,     $last_weekly_run ),
    array( 'ID 對照表更新',    $next_update_map, ''               ),
    array( '角色/聲優回補',    $next_backfill,   ''               ), // ✅ [5.4.0]
);
?>

<div class="wrap asc-settings-wrap">

    <h1 class="asc-settings-title">
        <?php esc_html_e( '設定', 'anime-sync-pro' ); ?>
        <span class="asc-settings-version">v<?php echo esc_html( $plugin_version ); ?></span>
    </h1>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( '設定已儲存。', 'anime-sync-pro' ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="anime-sync-settings-form">
        <?php wp_nonce_field( 'anime_sync_save_settings', 'anime_sync_settings_nonce' ); ?>

        <!-- ───────────────── 網站識別 ───────────────── -->
        <div class="asc-card">
            <h2 class="asc-card-title">
                <span class="dashicons dashicons-admin-site"></span>
                <?php esc_html_e( '網站識別 (User-Agent)', 'anime-sync-pro' ); ?>
            </h2>
            <p class="description asc-card-desc">
                <?php esc_html_e( '用於 API 請求的 User-Agent 標頭，部分 API（如 Bangumi）要求必填。', 'anime-sync-pro' ); ?>
            </p>
            <table class="form-table asc-form-table">
                <tr>
                    <th scope="row">
                        <label for="anime_sync_site_name"><?php esc_html_e( '網站名稱', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="anime_sync_site_name" name="anime_sync_site_name"
                               value="<?php echo esc_attr( $site_name ); ?>" class="regular-text asc-full-input" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="anime_sync_site_url"><?php esc_html_e( '網站 URL', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <input type="url" id="anime_sync_site_url" name="anime_sync_site_url"
                               value="<?php echo esc_attr( $site_url_opt ); ?>" class="regular-text asc-full-input" />
                        <p class="description">
                            <?php printf(
                                esc_html__( '產生的 UA：%s', 'anime-sync-pro' ),
                                '<code class="asc-ua-code">' . esc_html( $site_name . '/' . $plugin_version . ' (' . $site_url_opt . ')' ) . '</code>'
                            ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ───────────────── DeepL 翻譯 ───────────────── -->
        <div class="asc-card">
            <h2 class="asc-card-title">
                <span class="dashicons dashicons-translation"></span>
                <?php esc_html_e( '角色/聲優簡介翻譯（DeepL）', 'anime-sync-pro' ); ?>
            </h2>
            <p class="description asc-card-desc">
                <?php esc_html_e( '僅用於把角色/聲優簡介裡還是日文的部分翻成繁體中文；已經是中文的一律走 OpenCC 簡轉繁，不會消耗 DeepL 額度。姓名翻譯仍走既有的 CAST 字典（AI provider），跟這裡是分開的兩件事。', 'anime-sync-pro' ); ?>
            </p>
            <table class="form-table asc-form-table">
                <tr>
                    <th scope="row">
                        <label for="anime_sync_deepl_api_key"><?php esc_html_e( 'DeepL API 金鑰', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="anime_sync_deepl_api_key" name="anime_sync_deepl_api_key"
                               value="" autocomplete="off"
                               placeholder="<?php echo esc_attr( $deepl_key_is_set ? '●●●●●●●●●●●●（已設定，留空表示不變更）' : '例如：342ebdc3-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx' ); ?>"
                               class="regular-text asc-full-input" />
                        <p class="description">
                            <?php if ( $deepl_key_is_set ) : ?>
                                ✅ <?php echo esc_html( $deepl_key_is_free
                                    ? __( '目前已設定金鑰（Free 版）。', 'anime-sync-pro' )
                                    : __( '目前已設定金鑰（Pro 版）。', 'anime-sync-pro' ) ); ?>
                            <?php else : ?>
                                <?php esc_html_e( '尚未設定。免費版金鑰結尾會是「:fx」，每月 50 萬字元額度；沒設定金鑰時，翻譯排程會自動跳過，不影響其他排程任務。', 'anime-sync-pro' ); ?>
                            <?php endif; ?>
                            <?php esc_html_e( '欄位留空並儲存＝維持原本設定，不會被清空。', 'anime-sync-pro' ); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e( '批次翻譯每週三凌晨 3 點自動執行一次（每次上限 300 筆），也可以用 WP-CLI 手動跑：', 'anime-sync-pro' ); ?>
                            <code>wp anime translate-summaries --type=all --limit=300</code>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ───────────────── 排程設定 ───────────────── -->
        <div class="asc-card">
            <h2 class="asc-card-title">
                <span class="dashicons dashicons-clock"></span>
                <?php esc_html_e( '排程設定', 'anime-sync-pro' ); ?>
            </h2>
            <p class="description asc-card-desc">
                <?php printf(
                    esc_html__( '時間以站台時區（%s）顯示，內部以 UTC 儲存。每週清理與 ID 對照表更新為固定排程。', 'anime-sync-pro' ),
                    '<code>' . esc_html( $site_tz_string ) . '</code>'
                ); ?>
            </p>
            <p class="description asc-card-desc asc-warn-text">
                ⚠️ <?php esc_html_e( '注意：每日同步時間僅影響初始排程起始點，實際為每小時分批執行，修改後請點「強制重新排程」。', 'anime-sync-pro' ); ?>
            </p>
            <table class="form-table asc-form-table">
                <tr>
                    <th scope="row">
                        <label for="anime_sync_daily_hour_local"><?php esc_html_e( '每日同步起始時間', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <select id="anime_sync_daily_hour_local" name="anime_sync_daily_hour_local"
                                class="asc-select">
                            <?php for ( $h = 0; $h < 24; $h++ ) :
                                $utc_h = $local_to_utc_hour( $h );
                            ?>
                                <option value="<?php echo esc_attr( $h ); ?>" <?php selected( $daily_hour_local, $h ); ?>>
                                    <?php echo esc_html( sprintf( '%02d:00 %s（UTC %02d:00）', $h, $site_tz_string, $utc_h ) ); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( '每小時執行一批（20 部），此時間為第一批的起始點。主題曲＋集數同步固定在此時間 +2 小時。', 'anime-sync-pro' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="anime_sync_batch_size"><?php esc_html_e( '匯入批次大小', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="anime_sync_batch_size" name="anime_sync_batch_size"
                               value="<?php echo esc_attr( $batch_size ); ?>" min="5" max="100"
                               class="small-text" />
                        <p class="description"><?php esc_html_e( '建議 15–30。每批次處理完後釋放記憶體。', 'anime-sync-pro' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ───────────────── 角色/聲優資料回補 ───────────────── -->
        <div class="asc-card">
            <h2 class="asc-card-title">
                <span class="dashicons dashicons-groups"></span>
                <?php esc_html_e( '角色/聲優資料回補（BGM）', 'anime-sync-pro' ); ?>
            </h2>
            <p class="description asc-card-desc">
            <?php esc_html_e( '每 5 分鐘補一批欄位缺漏的角色 / 聲優資料（來源 Bangumi）。一次只跑一種模式，系統會自動接力：聲優（persons）補完後自動切換為角色（characters），角色補完後自動切回關閉（off），過程無需人工介入。若想調整順序或手動指定，仍可在下方切換。BGM 完全無資料的條目會自動記入跳過名單，不再重試；此類條目全站僅剩它們時，系統會維持原模式不誤切。', 'anime-sync-pro' ); ?>
            </p>
            <table class="form-table asc-form-table">
                <tr>
                    <th scope="row">
                        <label for="anime_sync_entity_backfill_mode"><?php esc_html_e( '回補模式', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <select id="anime_sync_entity_backfill_mode" name="anime_sync_entity_backfill_mode" class="asc-select">
                            <option value="off"        <?php selected( $backfill_mode, 'off' ); ?>><?php esc_html_e( '關閉', 'anime-sync-pro' ); ?></option>
                            <option value="characters" <?php selected( $backfill_mode, 'characters' ); ?>><?php esc_html_e( '角色（characters）', 'anime-sync-pro' ); ?></option>
                            <option value="persons"    <?php selected( $backfill_mode, 'persons' ); ?>><?php esc_html_e( '聲優/人物（persons）', 'anime-sync-pro' ); ?></option>
                        </select>
                        <?php if ( $backfill_mode !== 'off' ) : ?>
                            <span class="asc-text-ok" style="margin-left:6px;">● <?php esc_html_e( '執行中', 'anime-sync-pro' ); ?></span>
                        <?php else : ?>
                            <span style="color:#999;margin-left:6px;">○ <?php esc_html_e( '已關閉', 'anime-sync-pro' ); ?></span>
                        <?php endif; ?>
                        <p class="description">
                            <?php printf(
                                esc_html__( '待補：角色 %1$s 筆 / 聲優 %2$s 筆（未扣除跳過名單）', 'anime-sync-pro' ),
                                '<strong>' . esc_html( number_format_i18n( $backfill_pending_chars ) ) . '</strong>',
                                '<strong>' . esc_html( number_format_i18n( $backfill_pending_persons ) ) . '</strong>'
                            ); ?>
                            <br>
                            <?php printf(
                                esc_html__( '扣除跳過名單後實際待補：角色 %1$s 筆 / 聲優 %2$s 筆', 'anime-sync-pro' ),
                                '<strong>' . esc_html( number_format_i18n( $backfill_todo_chars ) ) . '</strong>',
                                '<strong>' . esc_html( number_format_i18n( $backfill_todo_persons ) ) . '</strong>'
                            ); ?>

                            <?php
                            /*
                             * 預估剩餘時間。
                             *
                             * 「目前階段」只算現在跑的那一種；「全部補完」要把之後
                             * 會接力的階段一起算進去 —— 系統一次只跑一種模式，
                             * persons 補完才會切到 characters，所以在 persons 模式下
                             * 角色那批的等待時間包含了聲優還要跑的時間。
                             */
                            if ( $backfill_mode !== 'off' && $backfill_per_hour > 0 ) :
                                $stage_label = ( $backfill_mode === 'persons' )
                                    ? __( '聲優', 'anime-sync-pro' )
                                    : __( '角色', 'anime-sync-pro' );

                                $stage_todo = ( $backfill_mode === 'persons' )
                                    ? $backfill_todo_persons
                                    : $backfill_todo_chars;

                                // persons 模式下，全部補完 = 聲優 + 之後接力的角色
                                $all_todo = ( $backfill_mode === 'persons' )
                                    ? $backfill_todo_persons + $backfill_todo_chars
                                    : $backfill_todo_chars;

                                // 目前階段的「原始」待補量，用來判斷是不是「假空」
                                $stage_pending = ( $backfill_mode === 'persons' )
                                    ? $backfill_pending_persons
                                    : $backfill_pending_chars;
                                ?>
                                <br>
                                <?php if ( $stage_todo === 0 && $stage_pending > 0 ) : ?>
                                    <?php
                                    /*
                                     * 「假空」狀態：這個階段還有缺漏，但全部落在跳過名單內
                                     * （BGM 整筆查無資料）。Cron 遇到這種情況會刻意維持原模式
                                     * 不切換（見 class-cron-manager.php 的 $true_remaining 判斷），
                                     * 也就是說它不會完成、也不會前進。
                                     *
                                     * 這裡必須跟「即將完成」區分開 —— 兩者的待補量都是 0，
                                     * 但意義完全相反：一個是快好了，一個是永遠不會好。
                                     */
                                    ?>
                                    <span style="color:#d63638;">⚠️
                                    <?php printf(
                                        esc_html__( '目前階段（%1$s）剩餘 %2$s 筆全部在跳過名單內（BGM 無資料），不會再處理，也不會自動切換到下一階段。', 'anime-sync-pro' ),
                                        esc_html( $stage_label ),
                                        '<strong>' . esc_html( number_format_i18n( $stage_pending ) ) . '</strong>'
                                    ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="asc-text-ok">⏳
                                    <?php printf(
                                        esc_html__( '目前階段（%1$s）預估 %2$s，全部補完預估 %3$s', 'anime-sync-pro' ),
                                        esc_html( $stage_label ),
                                        '<strong>' . esc_html( $backfill_format_eta( $stage_todo / $backfill_per_hour ) ) . '</strong>',
                                        '<strong>' . esc_html( $backfill_format_eta( $all_todo / $backfill_per_hour ) ) . '</strong>'
                                    ); ?>
                                    </span>
                                <?php endif; ?>
                                <br>
                                <span style="color:#888;">
                                <?php printf(
                                    esc_html__( '（依目前設定推算：每批 %1$s 筆 × 每 %2$s 分鐘一次 = %3$s 筆/小時）', 'anime-sync-pro' ),
                                    esc_html( number_format_i18n( $backfill_batch ) ),
                                    esc_html( number_format_i18n( $backfill_interval / MINUTE_IN_SECONDS ) ),
                                    esc_html( number_format_i18n( $backfill_per_hour ) )
                                ); ?>
                                </span>
                            <?php endif; ?>

                            <br>
                            <span style="color:#0073aa;">💡 <?php esc_html_e( '此模式會自動接力：補完會自己切到下一階段，最終自動關閉，通常不需手動更改。', 'anime-sync-pro' ); ?></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="anime_sync_entity_backfill_batch"><?php esc_html_e( '每批筆數', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="anime_sync_entity_backfill_batch"
                               name="anime_sync_entity_backfill_batch"
                               value="<?php echo esc_attr( $backfill_batch ); ?>"
                               min="10" max="200" class="small-text" />
                        <p class="description"><?php esc_html_e( '預設 60。每 5 分鐘執行一批，調高可加快進度但增加 Bangumi API 負擔。', 'anime-sync-pro' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '上次執行', 'anime-sync-pro' ); ?></th>
                    <td>
                        <?php if ( $backfill_last !== '' ) : ?>
                            <code style="font-size:12px;word-break:break-all;"><?php echo esc_html( $backfill_last ); ?></code>
                        <?php else : ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '跳過名單', 'anime-sync-pro' ); ?></th>
                    <td>
                        <div class="asc-db-log-info">
                            <?php printf(
                                esc_html__( '角色 %1$s 筆 / 聲優 %2$s 筆（BGM 整筆無資料，不再重試）', 'anime-sync-pro' ),
                                esc_html( number_format_i18n( $skip_chars_count ) ),
                                esc_html( number_format_i18n( $skip_persons_count ) )
                            ); ?>
                        </div>
                        <div class="asc-action-row">
                            <button type="button" id="btn-clear-backfill-skip" class="button button-secondary"
                                    <?php disabled( $skip_chars_count + $skip_persons_count, 0 ); ?>>
                                <?php esc_html_e( '清空跳過名單（重新重試）', 'anime-sync-pro' ); ?>
                            </button>
                            <span id="clear-backfill-skip-result" class="asc-action-result"></span>
                        </div>
                        <p class="description" style="margin-top:8px;">
                            <?php esc_html_e( '若 BGM 之後補上了某些條目資料，清空名單後會重新嘗試抓取。', 'anime-sync-pro' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ───────────────── 排程狀態 ───────────────── -->
        <div class="asc-card">
            <h2 class="asc-card-title">
                <span class="dashicons dashicons-calendar-alt"></span>
                <?php esc_html_e( '排程狀態', 'anime-sync-pro' ); ?>
                <button type="button" id="btn-reschedule-all" class="button button-small asc-card-title-btn">
                    <?php esc_html_e( '強制重新排程', 'anime-sync-pro' ); ?>
                </button>
            </h2>
            <div class="asc-cron-list">
                <?php foreach ( $cron_rows as $row ) :
                    $label    = $row[0];
                    $next_ts  = $row[1];
                    $last_str = $row[2];
                    $is_overdue = $next_ts && ( $next_ts < time() - 300 );
                ?>
                    <div class="asc-cron-row">
                        <span class="asc-cron-label"><?php echo esc_html( $label ); ?></span>
                        <span class="asc-cron-info">
                            <?php if ( $next_ts ) : ?>
                                <span class="<?php echo $is_overdue ? 'asc-text-err' : 'asc-text-ok'; ?>">
                                    <?php echo $is_overdue ? '⚠️' : '✓'; ?>
                                </span>
                                <?php echo esc_html( '下次：' . $fmt_datetime( $next_ts ) ); ?>
                                <?php if ( $is_overdue ) : ?>
                                    <span class="asc-text-err asc-cron-overdue">（已逾期）</span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="asc-text-err">❌ 未排程</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $last_str ) ) : ?>
                                <span class="asc-cron-last">
                                    上次：<?php echo esc_html( $fmt_datetime( $last_str ) ); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ───────────────── 記錄與偵錯 ───────────────── -->
        <div class="asc-card">
            <h2 class="asc-card-title">
                <span class="dashicons dashicons-media-text"></span>
                <?php esc_html_e( '記錄與偵錯', 'anime-sync-pro' ); ?>
            </h2>
            <table class="form-table asc-form-table">
                <tr>
                    <th scope="row">
                        <label for="anime_sync_log_retention_days"><?php esc_html_e( '記錄保留天數', 'anime-sync-pro' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="anime_sync_log_retention_days"
                               name="anime_sync_log_retention_days"
                               value="<?php echo esc_attr( $log_retention ); ?>"
                               min="1" max="365" class="small-text" />
                        <p class="description"><?php esc_html_e( '每週清理時，超過此天數的 DB 日誌會被自動刪除。', 'anime-sync-pro' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '偵錯模式', 'anime-sync-pro' ); ?></th>
                    <td>
                        <label class="asc-checkbox-label">
                            <input type="checkbox" name="anime_sync_debug_mode" value="1"
                                   <?php checked( $debug_mode, 1 ); ?> />
                            <?php esc_html_e( '啟用詳細偵錯記錄（記錄 API 請求 raw payload）', 'anime-sync-pro' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( '建議僅在排查問題時開啟，平時關閉可減少 DB 寫入。', 'anime-sync-pro' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" id="btn-save-settings" class="button button-primary">
                <?php esc_html_e( '儲存設定', 'anime-sync-pro' ); ?>
            </button>
        </p>
    </form>

    <!-- ───────────────── Bangumi ID 對照表 ───────────────── -->
    <div class="asc-card">
        <h2 class="asc-card-title">
            <span class="dashicons dashicons-database"></span>
            <?php esc_html_e( 'Bangumi ID 對照表 (anime_map.json)', 'anime-sync-pro' ); ?>
        </h2>
        <table class="form-table asc-form-table">
            <tr>
                <th scope="row"><?php esc_html_e( '檔案狀態', 'anime-sync-pro' ); ?></th>
                <td>
                    <?php if ( $map_exists ) : ?>
                        <span class="asc-text-ok">✓</span>
                        <?php printf(
                            esc_html__( '存在 · %1$s 筆（MAL %2$s 筆）· %3$s · 更新：%4$s', 'anime-sync-pro' ),
                            esc_html( number_format_i18n( $map_count ) ),
                            esc_html( number_format_i18n( $mal_count ) ),
                            esc_html( size_format( $map_size ) ),
                            esc_html( $map_updated )
                        ); ?>
                        <?php if ( $map_age_h > 168 ) : ?>
                            <span class="asc-text-err asc-map-warn">
                                ⚠️ <?php esc_html_e( '超過 7 天未更新', 'anime-sync-pro' ); ?>
                            </span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="asc-text-err">
                            ✗ <?php esc_html_e( '檔案不存在，請點擊下方下載', 'anime-sync-pro' ); ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '手動更新', 'anime-sync-pro' ); ?></th>
                <td>
                    <div class="asc-action-row">
                        <button type="button" id="btn-update-map" class="button button-secondary">
                            <?php esc_html_e( '立即下載 / 更新對照表', 'anime-sync-pro' ); ?>
                        </button>
                        <span id="update-map-result" class="asc-action-result"></span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- ───────────────── 手動功能 ───────────────── -->
    <div class="asc-card">
        <h2 class="asc-card-title">
            <span class="dashicons dashicons-admin-tools"></span>
            <?php esc_html_e( '手動功能', 'anime-sync-pro' ); ?>
        </h2>
        <table class="form-table asc-form-table">
            <tr>
                <th scope="row"><?php esc_html_e( '快取管理', 'anime-sync-pro' ); ?></th>
                <td>
                    <div class="asc-action-row">
                        <button type="button" id="btn-clear-cache" class="button button-secondary">
                            <?php esc_html_e( '清除外掛 Transient 快取', 'anime-sync-pro' ); ?>
                        </button>
                        <span id="clear-cache-result" class="asc-action-result"></span>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'DB 日誌管理', 'anime-sync-pro' ); ?></th>
                <td>
                    <div class="asc-db-log-info">
                        <?php printf(
                            esc_html__( '資料表 %1$s 共 %2$s 筆', 'anime-sync-pro' ),
                            '<code>' . esc_html( ( isset( $wpdb ) ? $wpdb->prefix : 'wp_' ) ) . 'anime_sync_logs</code>',
                            esc_html( number_format_i18n( $db_log_total ) )
                        ); ?>
                    </div>
                    <div class="asc-action-row asc-action-row--stack">
                        <div class="asc-action-inline">
                            <label for="db-log-clear-days" class="asc-inline-label">
                                <?php esc_html_e( '清除範圍：', 'anime-sync-pro' ); ?>
                            </label>
                            <select id="db-log-clear-days" class="asc-select">
                                <option value="7">7 天前</option>
                                <option value="30" selected>30 天前</option>
                                <option value="60">60 天前</option>
                                <option value="90">90 天前</option>
                                <option value="all"><?php esc_html_e( '全部（危險）', 'anime-sync-pro' ); ?></option>
                            </select>
                            <button type="button" id="btn-clear-db-logs" class="button button-secondary">
                                <?php esc_html_e( '清除 DB 日誌', 'anime-sync-pro' ); ?>
                            </button>
                        </div>
                        <span id="clear-db-logs-result" class="asc-action-result"></span>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Log 檔案管理', 'anime-sync-pro' ); ?></th>
                <td>
                    <div class="asc-log-file-info">
                        <?php printf(
                            esc_html__( '%1$s 共 %2$d 個檔案，佔用 %3$s KB', 'anime-sync-pro' ),
                            '<code class="asc-path-code">' . esc_html( str_replace( ABSPATH, '/', $log_dir ) ) . '</code>',
                            $log_count,
                            esc_html( $log_size_kb )
                        ); ?>
                    </div>
                    <div class="asc-action-row">
                        <button type="button" id="btn-clear-log-files" class="button button-secondary"
                                <?php disabled( $log_count, 0 ); ?>>
                            <?php esc_html_e( '刪除所有 .log 檔案', 'anime-sync-pro' ); ?>
                        </button>
                        <span id="clear-log-files-result" class="asc-action-result"></span>
                    </div>
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e( '此操作僅刪除 uploads/anime-sync-pro/logs/ 內的 .log 檔案，不影響 DB 日誌。', 'anime-sync-pro' ); ?>
                    </p>
                </td>
            </tr>
        </table>
    </div>

    <!-- ───────────────── 系統資訊 ───────────────── -->
    <div class="asc-card">
        <h2 class="asc-card-title">
            <span class="dashicons dashicons-info"></span>
            <?php esc_html_e( '系統資訊', 'anime-sync-pro' ); ?>
        </h2>
        <table class="form-table asc-form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'PHP 版本', 'anime-sync-pro' ); ?></th>
                <td>
                    <?php echo esc_html( PHP_VERSION ); ?>
                    <?php if ( version_compare( PHP_VERSION, '8.0', '<' ) ) : ?>
                        <span class="asc-text-err asc-php-warn">⚠️ 建議升級至 PHP 8.0+</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'WordPress 版本', 'anime-sync-pro' ); ?></th>
                <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '外掛版本', 'anime-sync-pro' ); ?></th>
                <td><?php echo esc_html( $plugin_version ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '站台時區', 'anime-sync-pro' ); ?></th>
                <td><code><?php echo esc_html( $site_tz_string ); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'mal_index 筆數', 'anime-sync-pro' ); ?></th>
                <td><?php echo esc_html( number_format_i18n( $mal_count ) ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '對照表更新時間', 'anime-sync-pro' ); ?></th>
                <td><?php echo esc_html( $map_updated ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( '對照表已存放', 'anime-sync-pro' ); ?></th>
                <td>
                    <?php printf( esc_html__( '%.1f 小時', 'anime-sync-pro' ), $map_age_h ); ?>
                    <?php if ( $map_age_h <= 168 ) : ?>
                        <span class="asc-text-ok">✓ 正常</span>
                    <?php else : ?>
                        <span class="asc-text-err">⚠️ 建議更新</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

</div><!-- .wrap -->

<!-- Toast -->
<div id="settings-toast" class="asc-toast" style="display:none;"></div>

<!-- ───────────────── JS ───────────────── -->
<script>
( function ( $ ) {
    'use strict';

    var nonce = '<?php echo esc_js( $admin_nonce ); ?>';

    function showToast( message, isError ) {
        var $t = $( '#settings-toast' );
        $t.text( message )
          .css( 'background', isError ? '#dc3232' : '#46b450' )
          .fadeIn( 200 );
        setTimeout( function () { $t.fadeOut( 300 ); }, 2500 );
    }

    /* ── 儲存防重複 ── */
    $( '#anime-sync-settings-form' ).on( 'submit', function () {
        $( '#btn-save-settings' ).prop( 'disabled', true ).text( '儲存中…' );
    } );

    /* ── 下載對照表 ── */
    $( '#btn-update-map' ).on( 'click', function () {
        var $btn = $( this );
        var orig = $btn.text();
        $btn.prop( 'disabled', true ).text( '下載中…' );
        $.post( ajaxurl, { action: 'anime_sync_update_map', nonce: nonce }, function ( resp ) {
            $btn.prop( 'disabled', false ).text( orig );
            if ( resp && resp.success ) {
                showToast( '更新成功' );
                setTimeout( function () { location.reload(); }, 1000 );
            } else {
                showToast( ( resp && resp.data ) || '更新失敗', true );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( orig );
            showToast( '網路錯誤', true );
        } );
    } );

    /* ── 清除 transient 快取 ── */
    $( '#btn-clear-cache' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#clear-cache-result' );
        $btn.prop( 'disabled', true );
        $.post( ajaxurl, { action: 'anime_sync_clear_cache', nonce: nonce }, function ( resp ) {
            $btn.prop( 'disabled', false );
            if ( resp && resp.success ) {
                $result.text( typeof resp.data === 'string' ? resp.data : '已清除' ).css( 'color', '#46b450' );
                showToast( '快取已清除' );
            } else {
                $result.text( '失敗' ).css( 'color', '#dc3232' );
                showToast( '清除失敗', true );
            }
            setTimeout( function () {
                $result.fadeOut( 2000, function () { $result.text( '' ).show(); } );
            }, 1500 );
        } );
    } );

    /* ── 清除 DB 日誌 ── */
    $( '#btn-clear-db-logs' ).on( 'click', function () {
        var $btn  = $( this );
        var days  = $( '#db-log-clear-days' ).val();
        var isAll = ( days === 'all' );
        var orig  = $btn.text();

        if ( ! confirm( isAll
            ? '⚠️ 確定清除「全部」DB 日誌嗎？此操作無法復原！'
            : '確定清除 ' + days + ' 天前的 DB 日誌嗎？' ) ) {
            return;
        }

        $btn.prop( 'disabled', true ).text( '清除中…' );
        $.post( ajaxurl, {
            action : 'anime_clear_old_logs',
            nonce  : nonce,
            days   : isAll ? 9999 : parseInt( days, 10 ),
        }, function ( resp ) {
            $btn.prop( 'disabled', false ).text( orig );
            if ( resp && resp.success ) {
                showToast( ( resp.data && resp.data.message ) || '已清除' );
                setTimeout( function () { location.reload(); }, 1000 );
            } else {
                showToast( ( resp && resp.data ) || '清除失敗', true );
            }
        } ).fail( function ( xhr ) {
            $btn.prop( 'disabled', false ).text( orig );
            showToast( '網路錯誤 (HTTP ' + xhr.status + ')', true );
        } );
    } );

    /* ── 清除 Log 檔案 ── */
    $( '#btn-clear-log-files' ).on( 'click', function () {
        if ( ! confirm( '確定刪除所有 .log 檔案嗎？此操作無法復原！' ) ) return;
        var $btn = $( this );
        var orig = $btn.text();
        $btn.prop( 'disabled', true ).text( '刪除中…' );
        $.post( ajaxurl, { action: 'anime_sync_clear_log_files', nonce: nonce }, function ( resp ) {
            $btn.prop( 'disabled', false ).text( orig );
            if ( resp && resp.success ) {
                showToast( '已刪除 .log 檔案' );
                setTimeout( function () { location.reload(); }, 1000 );
            } else {
                showToast( ( resp && resp.data ) || '刪除失敗', true );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( orig );
            showToast( '網路錯誤', true );
        } );
    } );

    /* ── 清空回補跳過名單 ── */
    $( '#btn-clear-backfill-skip' ).on( 'click', function () {
        if ( ! confirm( '確定清空角色與聲優的跳過名單嗎？\n清空後這些條目會重新打 Bangumi API 嘗試抓取。' ) ) return;
        var $btn    = $( this );
        var $result = $( '#clear-backfill-skip-result' );
        var orig    = $btn.text();
        $btn.prop( 'disabled', true ).text( '清空中…' );
        $.post( ajaxurl, { action: 'anime_sync_clear_backfill_skip', nonce: nonce }, function ( resp ) {
            $btn.prop( 'disabled', false ).text( orig );
            if ( resp && resp.success ) {
                $result.text( ( resp.data && resp.data.message ) || '已清空' ).css( 'color', '#46b450' );
                showToast( '跳過名單已清空' );
                setTimeout( function () { location.reload(); }, 1000 );
            } else {
                $result.text( '失敗' ).css( 'color', '#dc3232' );
                showToast( ( resp && resp.data ) || '清空失敗', true );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( orig );
            showToast( '網路錯誤', true );
        } );
    } );

    /* ── 強制重新排程 ── */
    $( '#btn-reschedule-all' ).on( 'click', function () {
        if ( ! confirm( '將會清除並重新建立所有 cron 排程，繼續嗎？' ) ) return;
        var $btn = $( this );
        var orig = $btn.text();
        $btn.prop( 'disabled', true ).text( '重排中…' );
        $.post( ajaxurl, { action: 'anime_sync_reschedule_cron', nonce: nonce }, function ( resp ) {
            $btn.prop( 'disabled', false ).text( orig );
            if ( resp && resp.success ) {
                showToast( ( resp.data && resp.data.message ) || '已重新排程' );
                setTimeout( function () { location.reload(); }, 1200 );
            } else {
                showToast( ( resp && resp.data ) || '重排失敗', true );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( orig );
            showToast( '網路錯誤', true );
        } );
    } );

} )( jQuery );
</script>

<!-- ───────────────── CSS ───────────────── -->
<style>

/* ═══════════════════════════════════════════
   基礎
═══════════════════════════════════════════ */
.asc-settings-wrap  { max-width: 100%; }
.asc-settings-title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.asc-settings-version { font-size: 13px; color: #888; font-weight: normal; }

/* ═══════════════════════════════════════════
   Card
═══════════════════════════════════════════ */
.asc-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 20px 24px;
    margin-top: 20px;
    max-width: 960px;
}
.asc-card-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 15px;
}
.asc-card-title .dashicons {
    font-size: 18px; width: 18px; height: 18px;
    color: #0073aa; flex-shrink: 0;
}
.asc-card-title-btn { margin-left: auto !important; }
.asc-card-desc { margin-bottom: 12px !important; }
.asc-warn-text { color: #d63638 !important; }

/* ═══════════════════════════════════════════
   Form table
═══════════════════════════════════════════ */
.asc-form-table { margin-top: 0 !important; }
.asc-form-table th {
    width: 180px;
    font-weight: 600;
    padding: 12px 10px 12px 0;
    vertical-align: top;
}
.asc-form-table td { padding: 10px 0; vertical-align: top; }
.asc-full-input  { width: 100% !important; max-width: 400px; box-sizing: border-box; }
.asc-ua-code     { font-size: 11px; word-break: break-all; }
.asc-select      { min-width: 120px; }
.asc-checkbox-label { display: flex; align-items: flex-start; gap: 6px; cursor: pointer; }

/* ═══════════════════════════════════════════
   Cron list
═══════════════════════════════════════════ */
.asc-cron-list { display: flex; flex-direction: column; gap: 0; }
.asc-cron-row {
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 6px 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f5f5f5;
    font-size: 13px;
}
.asc-cron-row:last-child { border-bottom: none; }
.asc-cron-label { font-weight: 600; min-width: 140px; flex-shrink: 0; }
.asc-cron-info  { flex: 1; display: flex; align-items: center; flex-wrap: wrap; gap: 4px 10px; }
.asc-cron-last  { color: #777; font-size: 12px; }
.asc-cron-overdue { font-size: 12px; }

/* ═══════════════════════════════════════════
   Action rows
═══════════════════════════════════════════ */
.asc-action-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}
.asc-action-row--stack { flex-direction: column; align-items: flex-start; }
.asc-action-inline {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}
.asc-inline-label { font-size: 13px; white-space: nowrap; }
.asc-action-result { font-size: 13px; }
.asc-db-log-info  { margin-bottom: 10px; font-size: 13px; }
.asc-log-file-info{ margin-bottom: 10px; font-size: 13px; }
.asc-path-code    { font-size: 11px; word-break: break-all; }
.asc-map-warn     { margin-left: 8px; }
.asc-php-warn     { margin-left: 8px; }

/* ═══════════════════════════════════════════
   Status text
═══════════════════════════════════════════ */
.asc-text-ok  { color: #46b450; font-weight: 600; }
.asc-text-err { color: #dc3232; font-weight: 600; }

/* ═══════════════════════════════════════════
   Disabled
═══════════════════════════════════════════ */
.asc-settings-wrap .button[disabled] { opacity: .6; cursor: not-allowed; }

/* ═══════════════════════════════════════════
   Toast
═══════════════════════════════════════════ */
.asc-toast {
    position: fixed;
    top: 50px; right: 20px;
    z-index: 99999;
    padding: 12px 20px;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    font-size: 14px;
    color: #fff;
    max-width: calc( 100vw - 40px );
}

/* ═══════════════════════════════════════════
   RESPONSIVE ≤ 782px
═══════════════════════════════════════════ */
@media screen and ( max-width: 782px ) {

    /* Card 全寬 */
    .asc-card { max-width: 100%; padding: 14px 14px; }
    .asc-card-title { font-size: 14px; }
    .asc-card-title-btn { margin-left: 0 !important; width: 100%; text-align: center; order: 10; }

    /* form-table → block 堆疊 */
    .asc-form-table,
    .asc-form-table tbody,
    .asc-form-table tr,
    .asc-form-table th,
    .asc-form-table td { display: block; width: 100% !important; box-sizing: border-box; }
    .asc-form-table tr  { border-bottom: 1px solid #f0f0f0; padding: 4px 0; }
    .asc-form-table tr:last-child { border-bottom: none; }
    .asc-form-table th  {
        padding: 8px 0 2px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .3px;
        color: #777;
        font-weight: 600;
    }
    .asc-form-table td  { padding: 2px 0 10px; }

    /* input 全寬 */
    .asc-full-input { max-width: 100%; }
    .asc-select     { width: 100%; }
    .regular-text   { width: 100% !important; box-sizing: border-box; }

    /* Cron rows → 垂直 */
    .asc-cron-label { min-width: unset; width: 100%; }
    .asc-cron-info  { width: 100%; flex-direction: column; align-items: flex-start; gap: 2px; }

    /* Action rows → 垂直 */
    .asc-action-inline { flex-direction: column; align-items: flex-start; width: 100%; }
    .asc-action-inline .button,
    .asc-action-inline .asc-select { width: 100%; text-align: center; box-sizing: border-box; }
    .asc-action-row .button { width: 100%; text-align: center; box-sizing: border-box; }
    .asc-action-row--stack  { gap: 6px; }

    /* Toast → 底部 */
    .asc-toast {
        top: auto;
        bottom: 20px;
        left: 16px;
        right: 16px;
        text-align: center;
    }

    /* submit button */
    .submit .button-primary { width: 100%; text-align: center; box-sizing: border-box; }
}

/* ≤ 480px */
@media screen and ( max-width: 480px ) {
    .asc-card { padding: 12px; }
    .asc-settings-title { font-size: 18px; }
}
</style>
