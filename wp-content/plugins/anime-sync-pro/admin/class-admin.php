<?php
/**
 * 檔案名稱: admin/class-admin.php
 * Plugin Admin Class - 管理員後台邏輯（AJAX + 資產載入）
 *
 * @package Anime_Sync_Pro
 * @version 1.9.1
 *
 * Changelog:
 *  - 1.9.1 (2026-07-05):
 *      • [Fix 403] handle_ajax_query_season() 與 handle_ajax_query_announced()
 *        兩處 wp_remote_post 直打 AniList 時補上 User-Agent，修正缺 UA 遭
 *        AniList/Cloudflare 攔截導致「季度查詢 / 動畫化決定查詢」HTTP 403。
 *        UA 統一引用 Anime_Sync_API_Handler::USER_AGENT，與 helper 一致。
 *  - 1.9.0 (2026-07-03):
 *      • 新增 handle_ajax_resync_anilist_images() — 從 AniList 重抓封面 + 橫幅圖片
 *        （方案 A：只補圖、尊重 anime_locked_fields 鎖定，不動狀態/評分/集數）。
 *      • 註冊新 AJAX action: anime_resync_anilist_images。
 *      • 新增 register_resync_anilist_metabox() / render_resync_anilist_metabox()
 *        — 動畫編輯頁側欄「🖼️ 重新同步 AniList 圖片」按鈕（內嵌 JS，零依賴）。
 *  - 1.8.0 (2026-06-28):
 *      • handle_ajax_query_announced() 加入第二輪查詢：
 *        專抓 startDate.year = null（日期未定）的 NOT_YET_RELEASED 作品。
 *      • 頁數上限動態調整：有年份 10 頁（500筆），不限年份 20 頁（1000筆）。
 *      • 回傳新增 no_date_count 欄位。
 *  - 1.7.0 (2026-06-27):
 *      • 新增 handle_ajax_query_announced() — 查詢 AniList NOT_YET_RELEASED 作品。
 *      • 註冊新 AJAX action: anime_sync_query_announced。
 *      • enqueue_admin_assets() actions 陣列新增 query_announced。
 *  - 1.6.0 (2026-06-23):
 *      • 新增 add_anime_filters() — 後台動畫列表新增四個篩選下拉選單。
 *      • 新增 filter_anime_query() — 套用篩選條件到 WP_Query。
 *  - 1.5.0 (2026-06-19):
 *      • 新增 handle_ajax_reschedule_cron()
 *  - 1.4.0 (2026-06-11):
 *      • handle_ajax_query_season() 加入四層成人內容過濾。
 *  - 1.3.0 (2026-05-10):
 *      • 新增 handle_ajax_clear_log_files()
 *  - 1.2.0 (2026-05-10):
 *      • 建構子加入 import_manager 防呆。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Admin {

    /** @var Anime_Sync_Import_Manager|null */
    private $import_manager;

    public function __construct( $import_manager = null ) {

        if ( ! is_object( $import_manager ) || ! method_exists( $import_manager, 'import_single' ) ) {
            if ( class_exists( 'Anime_Sync_Import_Manager' ) ) {
                try {
                    $import_manager = new Anime_Sync_Import_Manager();
                } catch ( \Throwable $e ) {
                    $this->log_error( 'Anime_Sync_Import_Manager 建立失敗', [ 'error' => $e->getMessage() ] );
                    $import_manager = null;
                }
            } else {
                $this->log_error( 'Anime_Sync_Admin 建構失敗：找不到 Anime_Sync_Import_Manager 類別', [
                    'received_type' => gettype( $import_manager ),
                ] );
                $import_manager = null;
            }
        }
        $this->import_manager = $import_manager;

        add_action( 'admin_menu',            [ $this, 'register_admin_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX endpoints
        add_action( 'wp_ajax_anime_sync_import_single',      [ $this, 'handle_ajax_import_single'      ] );
        add_action( 'wp_ajax_anime_sync_query_season',       [ $this, 'handle_ajax_query_season'       ] );
        add_action( 'wp_ajax_anime_sync_update_map',         [ $this, 'handle_ajax_update_map'         ] );
        add_action( 'wp_ajax_anime_sync_clear_cache',        [ $this, 'handle_ajax_clear_cache'        ] );
        add_action( 'wp_ajax_anime_sync_clear_logs',         [ $this, 'handle_ajax_clear_logs'         ] );
        add_action( 'wp_ajax_anime_clear_old_logs',          [ $this, 'handle_ajax_clear_logs'         ] );
        add_action( 'wp_ajax_anime_sync_clear_log_files',    [ $this, 'handle_ajax_clear_log_files'    ] );
        add_action( 'wp_ajax_anime_sync_bulk_action',        [ $this, 'handle_ajax_bulk_action'        ] );
        add_action( 'wp_ajax_anime_sync_save_bangumi_id',    [ $this, 'handle_ajax_save_bangumi_id'    ] );
        add_action( 'wp_ajax_anime_sync_enrich_single',      [ $this, 'handle_ajax_enrich_single'      ] );
        add_action( 'wp_ajax_anime_sync_analyze_series',     [ $this, 'handle_ajax_analyze_series'     ] );
        add_action( 'wp_ajax_anime_sync_import_series',      [ $this, 'handle_ajax_import_series'      ] );
        add_action( 'wp_ajax_anime_sync_popularity_ranking', [ $this, 'handle_ajax_popularity_ranking' ] );
        add_action( 'wp_ajax_anime_resync_bangumi',          [ $this, 'handle_ajax_resync_bangumi'     ] );
        add_action( 'wp_ajax_anime_sync_scan_series_gaps',   [ $this, 'handle_ajax_scan_series_gaps'   ] );
        add_action( 'wp_ajax_anime_sync_reschedule_cron',    [ $this, 'handle_ajax_reschedule_cron'    ] );
        add_action( 'wp_ajax_anime_sync_query_announced',    [ $this, 'handle_ajax_query_announced'    ] );
        // ★ [1.9.0] 新增：重新同步 AniList 圖片
        add_action( 'wp_ajax_anime_resync_anilist_images',   [ $this, 'handle_ajax_resync_anilist_images' ] );

        // Meta box
                // ★ 已移除「一鍵轉繁體」按鈕（保留方法備用，僅停用 meta box 註冊）
        add_action( 'wp_ajax_anime_sync_convert_post', [ $this, 'ajax_convert_post_to_tw'   ] );
        // ★ [1.9.0] 新增：AniList 圖片重新同步 meta box
        add_action( 'add_meta_boxes',                  [ $this, 'register_resync_anilist_metabox' ] );

        // 1.6.0 後台列表篩選器
        add_action( 'restrict_manage_posts', [ $this, 'add_anime_filters'  ] );
        add_filter( 'parse_query',           [ $this, 'filter_anime_query' ] );
    }

    // =========================================================================
    // PRIVATE Helpers
    // =========================================================================

    private function log_error( string $message, array $context = [] ): void {
        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::error( $message, $context );
        }
    }

    private function require_import_manager(): bool {
        if ( ! $this->import_manager ) {
            wp_send_json_error( [
                'message' => 'Import Manager 未初始化，請至 WP 後台「外掛」停用後重新啟用 Anime Sync Pro。',
            ] );
            return false;
        }
        return true;
    }

    private function import_and_enrich( int $anilist_id, array $args = [] ): array {
        if ( ! $this->import_manager ) {
            return [ 'success' => false, 'message' => 'Import Manager 未初始化' ];
        }

        $source = sanitize_key( $args['source'] ?? 'manual' );
        $force  = ! empty( $args['force'] );

        $result = $this->import_manager->import_single( $anilist_id, null, $source ?: 'manual', [
            'force' => $force,
        ] );

        if ( empty( $result['success'] ) || empty( $result['post_id'] ) || ! empty( $result['skip_enrich'] ) ) {
            return $result;
        }

        $post_id = (int) $result['post_id'];
        wp_cache_flush();

        if ( ! empty( $result['mal_id'] ) ) {
            update_post_meta( $post_id, 'anime_mal_id', (int) $result['mal_id'] );
        }

        if ( class_exists( 'Anime_Sync_API_Handler' ) ) {
            delete_post_meta( $post_id, '_enriched_at' );
            $api    = new Anime_Sync_API_Handler();
            $enrich = $api->enrich_anime_data( $post_id );
            if ( ! is_wp_error( $enrich ) ) {
                $result['enriched'] = array_keys( $enrich );
            } else {
                $result['enrich_error'] = $enrich->get_error_message();
            }
        }

        delete_transient( 'anime_sync_series_gaps' );
        delete_transient( 'anime_sync_series_gaps_selected' );

        return $result;
    }

    // =========================================================================
    // Admin Menu
    // =========================================================================

    public function register_admin_menu(): void {
        $cap = 'manage_options';
        add_menu_page(
            '動漫同步 Pro', '動漫同步', $cap,
            'anime-sync-pro',
            [ $this, 'render_dashboard' ],
            'dashicons-video-alt', 30
        );
        add_submenu_page( 'anime-sync-pro', '儀表板',   '儀表板',   $cap, 'anime-sync-pro',       [ $this, 'render_dashboard'      ] );
        add_submenu_page( 'anime-sync-pro', '動漫匯入', '🎬 動漫匯入', $cap, 'anime-sync-import',    [ $this, 'render_import_tool'    ] );
        add_submenu_page( 'anime-sync-pro', '審核佇列', '審核佇列', $cap, 'anime-sync-queue',     [ $this, 'render_review_queue'   ] );
        add_submenu_page( 'anime-sync-pro', '查看動漫', '查看動漫', $cap, 'anime-sync-published', [ $this, 'render_published_page' ] );
        add_submenu_page( 'anime-sync-pro', '錯誤日誌', '錯誤日誌', $cap, 'anime-sync-logs',      [ $this, 'render_logs_page'      ] );
        add_submenu_page( 'anime-sync-pro', '插件設定', '插件設定', $cap, 'anime-sync-settings',  [ $this, 'render_settings'       ] );
        add_submenu_page( 'anime-sync-pro', '操作紀錄', '操作紀錄', $cap, 'anime-sync-activity', [ $this, 'render_activity_page' ] );
    }

    private function safe_include_page( string $file_name ): void {
        $base_dir  = defined( 'ANIME_SYNC_PRO_DIR' ) ? ANIME_SYNC_PRO_DIR : plugin_dir_path( dirname( __FILE__ ) );
        $file_path = $base_dir . 'admin/pages/' . $file_name;
        if ( file_exists( $file_path ) ) {
            include $file_path;
        } else {
            echo '<div class="wrap"><div class="notice notice-error"><p>找不到頁面：<code>'
                . esc_html( $file_name ) . '</code></p></div></div>';
        }
    }

    public function render_dashboard()      { $this->safe_include_page( 'dashboard.php'      ); }
    public function render_import_tool()    { $this->safe_include_page( 'import-tool.php'    ); }
    public function render_review_queue()   { $this->safe_include_page( 'review-queue.php'   ); }
    public function render_published_page() { $this->safe_include_page( 'published-list.php' ); }
    public function render_logs_page()      { $this->safe_include_page( 'logs.php'           ); }
    public function render_settings()       { $this->safe_include_page( 'settings.php'       ); }
    public function render_activity_page() { $this->safe_include_page( 'activity-log.php' ); }
    
    // =========================================================================
    // Convert Meta Box
    // =========================================================================

    public function register_convert_meta_box(): void {
        add_meta_box(
            'anime_sync_convert', '🔄 簡繁轉換',
            [ $this, 'render_convert_meta_box' ],
            'anime', 'side', 'high'
        );
    }

    public function render_convert_meta_box( \WP_Post $post ): void {
        $nonce   = wp_create_nonce( 'anime_sync_nonce' );
        $post_id = $post->ID;
        ?>
        <button type="button"
                class="button button-primary"
                style="width:100%;margin-bottom:8px"
                onclick="asdConvertPost('<?php echo esc_js( (string) $post_id ); ?>', '<?php echo esc_js( $nonce ); ?>', this)">
            🔄 一鍵轉繁體
        </button>
        <p id="asd-convert-msg" style="margin:0;font-size:13px;display:none"></p>
        <script>
        function asdConvertPost(postId, nonce, btn) {
            var msg = document.getElementById('asd-convert-msg');
            btn.disabled = true;
            btn.textContent = '⏳ 轉換中...';
            msg.style.display = 'none';
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:  'anime_sync_convert_post',
                    nonce:   nonce,
                    post_id: postId,
                })
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.textContent = '🔄 一鍵轉繁體';
                msg.style.display = 'block';
                if (data.success) {
                    msg.style.color = '#2271b1';
                    msg.textContent = '✅ 轉換完成，正在重新整理...';
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    msg.style.color = '#d63638';
                    msg.textContent = '❌ ' + (data.data || '轉換失敗');
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = '🔄 一鍵轉繁體';
                msg.style.display = 'block';
                msg.style.color = '#d63638';
                msg.textContent = '❌ 網路錯誤，請重試';
            });
        }
        </script>
        <?php
    }

    public function ajax_convert_post_to_tw(): void {
        check_ajax_referer( 'anime_sync_nonce', 'nonce' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( '缺少 post_id' );

        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( '權限不足' );
        if ( get_post_type( $post_id ) !== 'anime' )       wp_send_json_error( '文章類型錯誤' );
        if ( ! class_exists( 'Anime_Sync_CN_Converter' ) ) wp_send_json_error( '找不到 CN Converter 類別' );

        @set_time_limit( 120 );

        $post = get_post( $post_id );
        if ( ! $post ) wp_send_json_error( '找不到文章' );

        $cn      = new Anime_Sync_CN_Converter();
        $updated = [ 'post' => [], 'meta' => [], 'acf' => [] ];

        $post_update = [ 'ID' => $post_id ];

        $new_title = $cn->convert( (string) $post->post_title );
        if ( $new_title !== $post->post_title ) {
            $post_update['post_title'] = $new_title;
            $updated['post'][]         = 'post_title';
        }

        $new_content = $cn->convert( (string) $post->post_content );
        if ( $new_content !== $post->post_content ) {
            $post_update['post_content'] = $new_content;
            $updated['post'][]           = 'post_content';
        }

        $new_excerpt = $cn->convert( (string) $post->post_excerpt );
        if ( $new_excerpt !== $post->post_excerpt ) {
            $post_update['post_excerpt'] = $new_excerpt;
            $updated['post'][]           = 'post_excerpt';
        }

        if ( count( $post_update ) > 1 ) {
            wp_update_post( $post_update );
        }

        foreach ( $this->get_convert_target_meta_keys() as $meta_key ) {
            $old_value = get_post_meta( $post_id, $meta_key, true );
            if ( $old_value === '' || $old_value === null ) continue;
            $new_value = $this->convert_meta_value( $cn, $meta_key, $old_value );
            update_post_meta( $post_id, $meta_key, $new_value );
            if ( $new_value !== $old_value ) $updated['meta'][] = $meta_key;
        }

        foreach ( $this->get_dynamic_convertible_meta( $post_id ) as $meta_key ) {
            if ( in_array( $meta_key, $this->get_convert_target_meta_keys(), true ) ) continue;
            $old_value = get_post_meta( $post_id, $meta_key, true );
            $new_value = $this->convert_meta_value( $cn, $meta_key, $old_value );
            if ( $new_value !== $old_value ) {
                update_post_meta( $post_id, $meta_key, $new_value );
                $updated['meta'][] = $meta_key;
            }
        }

        if ( function_exists( 'get_field_objects' ) && function_exists( 'update_field' ) ) {
            $acf_fields = get_field_objects( $post_id, false, true );
            if ( is_array( $acf_fields ) ) {
                foreach ( $acf_fields as $field ) {
                    $field_key  = $field['key']   ?? '';
                    $field_name = $field['name']  ?? '';
                    $old_value  = $field['value'] ?? null;
                    if ( ! $field_key || ! $field_name ) continue;
                    if ( ! $this->is_convertible_acf_field( $field_name ) ) continue;
                    $new_value = is_string( $old_value )
                        ? $this->convert_meta_value( $cn, $field_name, $old_value )
                        : $cn->convert_mixed( $old_value );
                    update_field( $field_key, $new_value, $post_id );
                    if ( $new_value !== $old_value ) $updated['acf'][] = $field_name;
                }
            }
        }

        clean_post_cache( $post_id );
        wp_send_json_success( [ 'message' => '轉換完成', 'updated' => $updated ] );
    }

    private function get_convert_target_meta_keys(): array {
        return [
            'anime_title_chinese',
            'anime_synopsis_chinese',
            'anime_studios',
            'anime_staff_json',
            'anime_cast_json',
            'anime_episodes_json',
        ];
    }

    private function get_dynamic_convertible_meta( int $post_id ): array {
        $all_meta = get_post_meta( $post_id );
        if ( ! is_array( $all_meta ) ) return [];
        $keys = [];
        foreach ( array_keys( $all_meta ) as $meta_key ) {
            if ( $this->is_convertible_meta_key( $meta_key ) ) $keys[] = $meta_key;
        }
        return $keys;
    }

    private function is_convertible_meta_key( string $meta_key ): bool {
        if ( $meta_key === '' || $meta_key[0] === '_' ) return false;

        $blocked_exact = [
            'anime_anilist_id','anime_mal_id','anime_bangumi_id','bangumi_id',
            'anime_score_anilist','anime_score_bangumi','anime_score_mal',
            'anime_popularity','anime_episodes','anime_duration',
            'anime_season_year','anime_next_airing','anime_sync_time',
            'anime_last_sync','anime_animethemes_id','anime_animethemes_slug','animethemes_slug',
        ];
        if ( in_array( $meta_key, $blocked_exact, true ) ) return false;

        $blocked_fragments = [
            'url','image','slug','score','date','time','id','status','format',
            'season','source','streaming','external_links','official_site',
            'twitter','wikipedia','banner','cover','trailer','relations_json','themes',
        ];
        foreach ( $blocked_fragments as $fragment ) {
            if ( strpos( $meta_key, $fragment ) !== false ) return false;
        }

        return true;
    }

    private function is_convertible_acf_field( string $field_name ): bool {
        return in_array( $field_name, [
            'anime_title_chinese','anime_synopsis_chinese','anime_studios',
            'anime_staff_json','anime_cast_json','anime_episodes_json',
            'anime_faq_json','anime_tw_broadcast',
            'anime_tw_distributor_custom','anime_tw_streaming_other',
        ], true );
    }

    private function convert_meta_value( Anime_Sync_CN_Converter $cn, string $meta_key, $value ) {
        if ( ! is_string( $value ) ) return $cn->convert_mixed( $value );
        $trimmed = trim( $value );
        if ( $trimmed === '' ) return $value;
        if ( in_array( $meta_key, [
            'anime_staff_json','anime_cast_json','anime_episodes_json','anime_faq_json'
        ], true ) ) {
            return $cn->convert_json_string( $value );
        }
        return $cn->convert( $value );
    }

    // =========================================================================
    // Meta Box: 重新同步 AniList 圖片（★ 1.9.0 新增）
    //
    // 用途：新番提前匯入時 AniList 常給暫定圖 / 尚無橫幅，官方之後才補上或
    //   換成正式主視覺。此按鈕只重抓 coverImage + bannerImage，尊重
    //   anime_locked_fields 鎖定，不動狀態/評分/集數。純手動觸發，不進 cron。
    //
    // JS 內嵌於 render（比照 render_convert_meta_box 一鍵轉繁體），零依賴 admin.js。
    // =========================================================================

    public function register_resync_anilist_metabox(): void {
        add_meta_box(
            'anime_resync_anilist_images',
            '🖼️ 重新同步 AniList 圖片',
            [ $this, 'render_resync_anilist_metabox' ],
            'anime', 'side', 'default'
        );
    }

    public function render_resync_anilist_metabox( \WP_Post $post ): void {
        $anilist_id = get_post_meta( $post->ID, 'anime_anilist_id',  true );
        $cover      = get_post_meta( $post->ID, 'anime_cover_image',  true );
        $banner     = get_post_meta( $post->ID, 'anime_banner_image', true );
        $nonce      = wp_create_nonce( 'anime_sync_admin_nonce' );
        $post_id    = (int) $post->ID;
        ?>
        <div id="anime-resync-al-wrap">
            <?php if ( $anilist_id ) : ?>
                <p style="margin:0 0 8px;">AniList ID：<strong><?php echo esc_html( $anilist_id ); ?></strong></p>
            <?php else : ?>
                <p style="margin:0 0 8px;color:#d63638;">尚未設定 AniList ID，無法同步。</p>
            <?php endif; ?>

            <p style="margin:0 0 8px;font-size:11px;color:#666;">
                封面：<?php echo $cover ? '✅' : '⚠️ 空'; ?>　橫幅：<?php echo $banner ? '✅' : '⚠️ 空'; ?>
            </p>
            <p style="margin:0 0 8px;font-size:11px;color:#999;">
                從 AniList 重抓封面 + 橫幅（暫定圖換正式圖、補橫幅用）。在下方「同步控制」鎖定的圖片會自動跳過。
            </p>

            <button type="button"
                    class="button button-secondary"
                    style="width:100%;"
                    <?php disabled( ! $anilist_id ); ?>
                    onclick="asdResyncAnilistImages('<?php echo esc_js( (string) $post_id ); ?>', '<?php echo esc_js( $nonce ); ?>', this)">
                🖼️ 重新同步 AniList 圖片
            </button>
            <p id="asd-resync-al-msg" style="margin:8px 0 0;min-height:20px;font-size:12px;"></p>
        </div>
        <script>
        function asdResyncAnilistImages(postId, nonce, btn) {
            var msg = document.getElementById('asd-resync-al-msg');
            var original = btn.textContent;
            btn.disabled = true;
            btn.textContent = '⏳ 同步中…';
            msg.style.color = '#666';
            msg.textContent = '';
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:  'anime_resync_anilist_images',
                    nonce:   nonce,
                    post_id: postId,
                })
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.textContent = original;
                if (data.success) {
                    msg.style.color = '#2271b1';
                    msg.textContent = (data.data && data.data.message) ? data.data.message : '✅ 完成，正在重新整理…';
                    setTimeout(function(){ location.reload(); }, 1200);
                } else {
                    msg.style.color = '#d63638';
                    msg.textContent = '❌ ' + ((data.data && data.data.message) ? data.data.message : '同步失敗');
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = original;
                msg.style.color = '#d63638';
                msg.textContent = '❌ 網路錯誤，請重試';
            });
        }
        </script>
        <?php
    }

    // =========================================================================
    // AJAX: Single Import
    // =========================================================================

    public function handle_ajax_import_single(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );
        if ( ! $this->require_import_manager() ) return;

        @set_time_limit( 180 );

        $anilist_id = isset( $_POST['anilist_id'] ) ? intval( $_POST['anilist_id'] ) : 0;
        $force      = ! empty( $_POST['force'] ) || ! empty( $_POST['force_update'] );
        if ( ! $anilist_id ) wp_send_json_error( [ 'message' => '無效的 ID' ] );

        $result = $this->import_and_enrich( $anilist_id, [
            'source' => 'manual',
            'force'  => $force,
        ] );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? '匯入失敗' ] );
        }

        wp_send_json_success( $result );
    }

    // =========================================================================
    // AJAX: Enrich Single
    // =========================================================================

    public function handle_ajax_enrich_single(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );
        if ( ! $this->require_import_manager() ) return;

        @set_time_limit( 120 );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( [ 'message' => '無效的 post_id' ] );
        $result = $this->import_manager->enrich_single( $post_id );
        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        wp_send_json_success( [ 'message' => '補抓完成', 'enriched' => array_keys( $result ) ] );
    }

    // =========================================================================
    // AJAX: Query Season
    // =========================================================================

    public function handle_ajax_query_season(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $season  = strtoupper( sanitize_text_field( $_POST['season'] ?? '' ) );
        $year    = intval( $_POST['year'] ?? date( 'Y' ) );
        $allowed = [ 'WINTER', 'SPRING', 'SUMMER', 'FALL' ];
        if ( ! in_array( $season, $allowed, true ) || ! $year ) {
            wp_send_json_error( '請選擇有效的年份與季節' );
        }

        $query = '
        query($season:MediaSeason,$year:Int,$page:Int){
            Page(page:$page,perPage:50){
                pageInfo { hasNextPage }
                media(season:$season,seasonYear:$year,type:ANIME,isAdult:false,sort:POPULARITY_DESC){
                    id idMal title{romaji} format episodes popularity status
                    isAdult genres tags { name isAdult }
                }
            }
        }';

        $all_list = [];
        $page     = 1;

        do {
            $res  = null;
            $code = 0;

            for ( $attempt = 0; $attempt < 3; $attempt++ ) {
                $res = wp_remote_post( 'https://graphql.anilist.co', [
                    'body'    => json_encode( [
                        'query'     => $query,
                        'variables' => [ 'season' => $season, 'year' => $year, 'page' => $page ],
                    ] ),
                    // ★ [1.9.1 Fix 403] 補 User-Agent，避免 AniList/Cloudflare 攔截
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                        'User-Agent'   => Anime_Sync_API_Handler::USER_AGENT,
                    ],
                    'timeout' => 20,
                ] );

                if ( is_wp_error( $res ) ) { if ( $attempt < 2 ) sleep( 3 ); continue; }

                $code = (int) wp_remote_retrieve_response_code( $res );

                if ( $code === 429 ) {
                    $retry_after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
                    $wait        = $retry_after > 0 ? $retry_after : 10;
                    if ( $wait > 30 ) {
                        if ( ! empty( $all_list ) ) {
                            wp_send_json_success( [
                                'list'    => $all_list,
                                'total'   => count( $all_list ),
                                'warning' => 'AniList 速率限制，已載入前 ' . count( $all_list ) . ' 筆，請稍後重新查詢',
                            ] );
                        }
                        wp_send_json_error( 'AniList 請求過於頻繁（429），請稍後 ' . $wait . ' 秒再試' );
                    }
                    sleep( $wait );
                    continue;
                }

                if ( $code === 200 ) break;
                if ( $attempt < 2 ) sleep( 2 );
            }

            if ( is_wp_error( $res ) || $code !== 200 ) {
                wp_send_json_error( 'AniList 查詢失敗 (HTTP ' . $code . ')' );
            }

            $body     = json_decode( wp_remote_retrieve_body( $res ), true );
            $list     = $body['data']['Page']['media'] ?? [];
            $has_next = ! empty( $body['data']['Page']['pageInfo']['hasNextPage'] );

            foreach ( $list as $item ) {
                $anilist_id = (int) ( $item['id'] ?? 0 );
                if ( ! $anilist_id ) continue;
                if ( ! empty( $item['isAdult'] ) ) continue;

                $genres = (array) ( $item['genres'] ?? [] );
                if ( in_array( 'Hentai', $genres, true ) || in_array( 'Erotica', $genres, true ) ) continue;

                $tags = (array) ( $item['tags'] ?? [] );
                foreach ( $tags as $tag ) {
                    if ( ! empty( $tag['isAdult'] ) ) continue 2;
                }

                $title_lower     = strtolower( (string) ( $item['title']['romaji'] ?? '' ) );
                $title_blacklist = [ 'hamehara', 'furachi', 'muchuu no tou', 'oshiri dandy', 'cheat item kanrikyoku' ];
                foreach ( $title_blacklist as $kw ) {
                    if ( $kw !== '' && strpos( $title_lower, $kw ) !== false ) continue 2;
                }

                $existing_ids = get_posts( [
                    'post_type'        => 'anime',
                    'meta_key'         => 'anime_anilist_id',
                    'meta_value'       => $anilist_id,
                    'posts_per_page'   => 1,
                    'fields'           => 'ids',
                    'post_status'      => 'any',
                    'no_found_rows'    => true,
                    'suppress_filters' => true,
                ] );

                $all_list[] = [
                    'anilist_id'   => $anilist_id,
                    'mal_id'       => (int)    ( $item['idMal']           ?? 0  ),
                    'title_romaji' => (string) ( $item['title']['romaji'] ?? '' ),
                    'format'       => (string) ( $item['format']          ?? '' ),
                    'episodes'     => (int)    ( $item['episodes']        ?? 0  ),
                    'popularity'   => (int)    ( $item['popularity']      ?? 0  ),
                    'status'       => (string) ( $item['status']          ?? '' ),
                    'imported'     => ! empty( $existing_ids ),
                ];
            }

            $page++;
        } while ( $has_next && $page <= 10 );

        wp_send_json_success( [ 'list' => $all_list, 'total' => count( $all_list ) ] );
    }

    // =========================================================================
    // AJAX: Query Announced（v1.8.0 大幅強化）
    // =========================================================================

    public function handle_ajax_query_announced(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $year    = intval( $_POST['year']   ?? 0 );
        $sort    = sanitize_key( $_POST['sort'] ?? 'POPULARITY_DESC' );
        $formats = isset( $_POST['formats'] ) ? (array) $_POST['formats'] : [];
        $formats = array_filter( array_map( 'sanitize_key', $formats ) );

        $allowed_sorts   = [ 'POPULARITY_DESC', 'START_DATE' ];
        $allowed_formats = [ 'TV', 'MOVIE', 'OVA', 'ONA', 'SPECIAL', 'MUSIC' ];

        if ( ! in_array( $sort, $allowed_sorts, true ) ) $sort = 'POPULARITY_DESC';
        $formats = array_values( array_intersect( $formats, $allowed_formats ) );
        if ( empty( $formats ) ) $formats = [ 'TV', 'MOVIE', 'OVA', 'ONA', 'SPECIAL' ];

        $use_year = $year >= 2000 && $year <= 2100;

        $gql_format_filter = 'format_in:[' . implode( ',', $formats ) . '],';

        $gql_year_filter = $use_year
            ? 'startDate_greater:' . ( $year * 10000 ) . ',startDate_lesser:' . ( ( $year + 1 ) * 10000 ) . ','
            : '';

        // 頁數上限：有年份 10 頁（500筆），不限年份 20 頁（1000筆）
        $max_pages = $use_year ? 10 : 20;

        // ── 共用 GraphQL 片段 ─────────────────────────────────────────────
        $media_fields = '
            id idMal
            title { romaji }
            format episodes popularity
            startDate { year month day }
            isAdult genres
            tags { name isAdult }
        ';

        // ── 第一輪查詢：有年份過濾 or 不限年份（現有邏輯）────────────────
        $query_main = '
        query($page:Int){
            Page(page:$page,perPage:50){
                pageInfo { hasNextPage }
                media(' . $gql_format_filter . $gql_year_filter . 'type:ANIME,status:NOT_YET_RELEASED,isAdult:false,sort:' . $sort . '){
                    ' . $media_fields . '
                }
            }
        }';

        $all_list     = [];
        $seen_ids     = [];   // 用於去重
        $no_date_count = 0;

        // ── 執行查詢（支援多頁）───────────────────────────────────────────
        $fetch_pages = function( string $gql, int $limit ) use ( &$all_list, &$seen_ids, &$no_date_count ): ?string {
            $page = 1;
            do {
                $res  = null;
                $code = 0;

                for ( $attempt = 0; $attempt < 3; $attempt++ ) {
                    $res = wp_remote_post( 'https://graphql.anilist.co', [
                        'body'    => json_encode( [ 'query' => $gql, 'variables' => [ 'page' => $page ] ] ),
                        // ★ [1.9.1 Fix 403] 補 User-Agent，避免 AniList/Cloudflare 攔截
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Accept'       => 'application/json',
                            'User-Agent'   => Anime_Sync_API_Handler::USER_AGENT,
                        ],
                        'timeout' => 25,
                    ] );

                    if ( is_wp_error( $res ) ) { if ( $attempt < 2 ) sleep( 3 ); continue; }

                    $code = (int) wp_remote_retrieve_response_code( $res );

                    if ( $code === 429 ) {
                        $retry_after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
                        $wait        = $retry_after > 0 ? $retry_after : 10;
                        if ( $wait > 30 ) {
                            return 'AniList 速率限制（429），請稍後 ' . $wait . ' 秒再試';
                        }
                        sleep( $wait );
                        continue;
                    }

                    if ( $code === 200 ) break;
                    if ( $attempt < 2 ) sleep( 2 );
                }

                if ( is_wp_error( $res ) || $code !== 200 ) {
                    return 'AniList 查詢失敗 (HTTP ' . $code . ')';
                }

                $body     = json_decode( wp_remote_retrieve_body( $res ), true );
                $media_list = $body['data']['Page']['media'] ?? [];
                $has_next = ! empty( $body['data']['Page']['pageInfo']['hasNextPage'] );

                foreach ( $media_list as $item ) {
                    $anilist_id = (int) ( $item['id'] ?? 0 );
                    if ( ! $anilist_id ) continue;
                    if ( isset( $seen_ids[ $anilist_id ] ) ) continue; // 去重

                    // 四層成人內容過濾
                    if ( ! empty( $item['isAdult'] ) ) continue;
                    $genres = (array) ( $item['genres'] ?? [] );
                    if ( in_array( 'Hentai', $genres, true ) || in_array( 'Erotica', $genres, true ) ) continue;
                    $tags = (array) ( $item['tags'] ?? [] );
                    foreach ( $tags as $tag ) {
                        if ( ! empty( $tag['isAdult'] ) ) continue 2;
                    }
                    $title_lower     = strtolower( (string) ( $item['title']['romaji'] ?? '' ) );
                    $title_blacklist = [ 'hamehara', 'furachi', 'muchuu no tou', 'oshiri dandy', 'cheat item kanrikyoku' ];
                    foreach ( $title_blacklist as $kw ) {
                        if ( $kw !== '' && strpos( $title_lower, $kw ) !== false ) continue 2;
                    }

                    // 組合預計開播日期
                    $sd    = $item['startDate'] ?? [];
                    $start = '';
                    if ( ! empty( $sd['year'] ) ) {
                        $start = $sd['year'];
                        if ( ! empty( $sd['month'] ) ) {
                            $start .= '-' . str_pad( $sd['month'], 2, '0', STR_PAD_LEFT );
                            if ( ! empty( $sd['day'] ) ) {
                                $start .= '-' . str_pad( $sd['day'], 2, '0', STR_PAD_LEFT );
                            }
                        }
                    } else {
                        $no_date_count++;
                    }

                    // 站內已匯入檢查
                    $existing_ids = get_posts( [
                        'post_type'        => 'anime',
                        'meta_key'         => 'anime_anilist_id',
                        'meta_value'       => $anilist_id,
                        'posts_per_page'   => 1,
                        'fields'           => 'ids',
                        'post_status'      => 'any',
                        'no_found_rows'    => true,
                        'suppress_filters' => true,
                    ] );

                    $seen_ids[ $anilist_id ] = true;
                    $all_list[] = [
                        'anilist_id'   => $anilist_id,
                        'mal_id'       => (int)    ( $item['idMal']           ?? 0  ),
                        'title_romaji' => (string) ( $item['title']['romaji'] ?? '' ),
                        'format'       => (string) ( $item['format']          ?? '' ),
                        'episodes'     => (int)    ( $item['episodes']        ?? 0  ),
                        'popularity'   => (int)    ( $item['popularity']      ?? 0  ),
                        'start_date'   => $start,
                        'imported'     => ! empty( $existing_ids ),
                    ];
                }

                $page++;
            } while ( $has_next && $page <= $limit );

            return null; // null = 成功
        };

        // 執行第一輪
        $error = $fetch_pages( $query_main, $max_pages );
        if ( $error !== null && empty( $all_list ) ) {
            wp_send_json_error( $error );
        }

        // ── 第二輪查詢：專抓 startDate.year = null 的日期未定作品 ─────────
        // 只在「不限年份」或「查詢年份 >= 當前年份」時才執行，避免查過去年份時多餘的請求
        $run_no_date_query = ! $use_year || $year >= (int) date( 'Y' );

        if ( $run_no_date_query ) {
            // startDate_lesser: 10000 相當於 year < 1，
            // 配合 NOT_YET_RELEASED 可篩出 startDate.year = null 的作品
            $query_no_date = '
            query($page:Int){
                Page(page:$page,perPage:50){
                    pageInfo { hasNextPage }
                    media(' . $gql_format_filter . 'type:ANIME,status:NOT_YET_RELEASED,isAdult:false,startDate_lesser:10000,sort:POPULARITY_DESC){
                        ' . $media_fields . '
                    }
                }
            }';

            // 第二輪最多 4 頁（200筆），日期未定的作品總量不多
            $fetch_pages( $query_no_date, 4 );
        }

        // 若兩輪都有速率限制但有部分資料，加 warning 回傳
        $warning = $error !== null ? '⚠️ ' . $error . '，以下為已載入的部分結果。' : '';

        wp_send_json_success( [
            'list'          => array_values( $all_list ),
            'total'         => count( $all_list ),
            'no_date_count' => $no_date_count,
            'warning'       => $warning,
        ] );
    }

    // =========================================================================
    // AJAX: Bulk Action
    // =========================================================================

    public function handle_ajax_bulk_action(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $bulk     = sanitize_key( $_POST['bulk'] ?? '' );
        $post_ids = array_filter( array_map( 'intval', (array) ( $_POST['post_ids'] ?? [] ) ) );

        if ( ! $bulk || empty( $post_ids ) ) wp_send_json_error( '參數錯誤' );

        $allowed = [ 'publish', 'draft', 'delete', 'refetch' ];
        if ( ! in_array( $bulk, $allowed, true ) ) wp_send_json_error( '無效的批次動作' );

        $success = 0;
        $failed  = 0;

        foreach ( $post_ids as $pid ) {
            if ( get_post_type( $pid ) !== 'anime' ) { $failed++; continue; }

            switch ( $bulk ) {
                case 'publish':
                    $r = wp_update_post( [ 'ID' => $pid, 'post_status' => 'publish' ] );
                    $r ? $success++ : $failed++;
                    break;
                case 'draft':
                    $r = wp_update_post( [ 'ID' => $pid, 'post_status' => 'draft' ] );
                    $r ? $success++ : $failed++;
                    break;
                case 'delete':
                    $r = wp_delete_post( $pid, true );
                    $r ? $success++ : $failed++;
                    break;
                case 'refetch':
                    $anilist_id = (int) get_post_meta( $pid, 'anime_anilist_id', true );
                    if ( ! $anilist_id ) { $failed++; break; }
                    $result = $this->import_and_enrich( $anilist_id, [ 'source' => 'bulk-refetch', 'force' => true ] );
                    ! empty( $result['success'] ) ? $success++ : $failed++;
                    break;
            }
        }

        wp_send_json_success( [
            'success' => $success,
            'failed'  => $failed,
            'message' => sprintf( '完成：成功 %d 筆，失敗 %d 筆', $success, $failed ),
        ] );
    }

    // =========================================================================
    // AJAX: Save Bangumi ID
    // =========================================================================

    public function handle_ajax_save_bangumi_id(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $post_id    = intval( $_POST['post_id'] ?? 0 );
        $bangumi_id = intval( $_POST['bangumi_id'] ?? 0 );
        if ( ! $post_id || ! $bangumi_id ) wp_send_json_error( '參數錯誤' );
        if ( get_post_type( $post_id ) !== 'anime' ) wp_send_json_error( '文章類型錯誤' );
        update_post_meta( $post_id, 'anime_bangumi_id', $bangumi_id );
        update_post_meta( $post_id, 'bangumi_id', $bangumi_id );
        delete_post_meta( $post_id, '_bangumi_id_pending' );
        update_post_meta( $post_id, '_bangumi_id_manually_set', 1 );
        wp_send_json_success( [ 'bangumi_id' => $bangumi_id ] );
    }

    // =========================================================================
    // AJAX: Update Map
    // =========================================================================

    public function handle_ajax_update_map(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );
        @set_time_limit( 180 );
        if ( ! class_exists( 'Anime_Sync_ID_Mapper' ) ) wp_send_json_error( '找不到 ID Mapper 類別' );
        $mapper = new Anime_Sync_ID_Mapper();
        $result = $mapper->download_and_cache_map();
        if ( $result ) {
            $status = $mapper->get_map_status();
            wp_send_json_success( [
                'message'          => '對照表更新成功',
                'al_count'         => $status['al_count']         ?? 0,
                'mal_count'        => $status['mal_count']        ?? 0,
                'ext_mal_count'    => $status['ext_mal_count']    ?? 0,
                'ext_anidb_count'  => $status['ext_anidb_count']  ?? 0,
                'ext_last_updated' => $status['ext_last_updated'] ?? '',
            ] );
        } else {
            wp_send_json_error( '下載失敗，請檢查網路連線' );
        }
    }

    // =========================================================================
    // AJAX: Clear Cache
    // =========================================================================

    public function handle_ajax_clear_cache(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );
        global $wpdb;
        $count = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_anime_sync_%'
             OR option_name LIKE '_transient_timeout_anime_sync_%'"
        );
        wp_send_json_success( '已清除 ' . (int) $count . ' 筆快取' );
    }

    // =========================================================================
    // AJAX: Clear DB Logs
    // =========================================================================

    public function handle_ajax_clear_logs(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );
        if ( ! class_exists( 'Anime_Sync_Error_Logger' ) ) wp_send_json_error( '找不到 Logger 類別' );

        $days  = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 30;
        $count = ( new Anime_Sync_Error_Logger() )->delete_old_logs( $days );

        wp_send_json_success( [
            'count'   => $count,
            'days'    => $days,
            'message' => sprintf( '已清除 %d 筆 %d 天前的日誌', $count, $days ),
        ] );
    }

    // =========================================================================
    // AJAX: Clear Log Files
    // =========================================================================

    public function handle_ajax_clear_log_files(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) wp_send_json_error( 'WordPress uploads 目錄不可用：' . $upload_dir['error'] );

        $base_dir  = trailingslashit( $upload_dir['basedir'] );
        $log_dir   = $base_dir . 'anime-sync-pro/logs/';
        $real_base = realpath( $base_dir );
        $real_log  = realpath( $log_dir );

        if ( ! $real_log || ! $real_base || strpos( $real_log, $real_base ) !== 0 ) {
            wp_send_json_success( [ 'count' => 0, 'total_size' => 0, 'message' => 'Log 目錄不存在或路徑無效，無檔案可刪除。' ] );
        }

        if ( ! is_dir( $real_log ) ) {
            wp_send_json_success( [ 'count' => 0, 'total_size' => 0, 'message' => 'Log 目錄不存在，無檔案可刪除。' ] );
        }

        $files      = glob( trailingslashit( $real_log ) . '*.log' ) ?: [];
        $deleted    = 0;
        $failed     = 0;
        $total_size = 0;
        $errors     = [];

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) continue;
            $real_file = realpath( $file );
            if ( ! $real_file || strpos( $real_file, $real_log ) !== 0 ) { $failed++; continue; }
            $size = (int) filesize( $real_file );
            if ( @unlink( $real_file ) ) {
                $deleted++;
                $total_size += $size;
            } else {
                $failed++;
                $errors[] = basename( $real_file );
            }
        }

        $this->log_error( '手動清除 Log 檔案', [
            'deleted'    => $deleted,
            'failed'     => $failed,
            'total_size' => $total_size,
            'errors'     => $errors,
        ] );

        wp_send_json_success( [
            'count'      => $deleted,
            'failed'     => $failed,
            'total_size' => $total_size,
            'message'    => sprintf(
                '已刪除 %d 個 .log 檔案（釋放 %s）%s',
                $deleted,
                size_format( $total_size ),
                $failed > 0 ? sprintf( '；失敗 %d 個', $failed ) : ''
            ),
        ] );
    }

    // =========================================================================
    // AJAX: Reschedule Cron
    // =========================================================================

    public function handle_ajax_reschedule_cron(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );
        if ( ! class_exists( 'Anime_Sync_Cron_Manager' ) ) wp_send_json_error( '找不到 Cron Manager 類別' );

        Anime_Sync_Cron_Manager::reschedule_all();

        $fmt = static fn( $ts ) => $ts ? wp_date( 'Y-m-d H:i', $ts ) : '未排程';

        $this->log_error( '手動強制重新排程 cron', [
            'daily'      => wp_next_scheduled( 'anime_sync_daily_score_update' ),
            'themes_eps' => wp_next_scheduled( 'anime_sync_themes_episodes_update' ),
            'weekly'     => wp_next_scheduled( 'anime_sync_weekly_cleanup' ),
            'update_map' => wp_next_scheduled( 'anime_sync_update_anime_map' ),
        ] );

        wp_send_json_success( [
            'message' => sprintf(
                '已重新排程：每日 %s ／ 主題曲+集數 %s ／ 每週清理 %s ／ ID對照表 %s',
                $fmt( wp_next_scheduled( 'anime_sync_daily_score_update' ) ),
                $fmt( wp_next_scheduled( 'anime_sync_themes_episodes_update' ) ),
                $fmt( wp_next_scheduled( 'anime_sync_weekly_cleanup' ) ),
                $fmt( wp_next_scheduled( 'anime_sync_update_anime_map' ) )
            ),
        ] );
    }

    // =========================================================================
    // AJAX: Analyze Series
    // =========================================================================

    public function handle_ajax_analyze_series(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );
        if ( ! $this->require_import_manager() ) return;

        @set_time_limit( 180 );

        $anilist_id = intval( $_POST['anilist_id'] ?? 0 );
        if ( ! $anilist_id ) wp_send_json_error( [ 'message' => '無效的 AniList ID' ] );

        $result = $this->import_manager->analyze_series( $anilist_id );

        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        if ( empty( $result['nodes'] ) ) wp_send_json_error( [ 'message' => '找不到系列資料，請確認 ID 是否正確' ] );

        $nodes    = $result['nodes'];
        $total    = count( $nodes );
        $imported = count( array_filter( $nodes, fn( $n ) => ! empty( $n['imported'] ) ) );

        wp_send_json_success( [
            'root_id'       => $result['root_id'],
            'series_name'   => $result['series_name'],
            'series_romaji' => $result['series_romaji'] ?? '',
            'tree'          => $nodes,
            'total'         => $total,
            'imported'      => $imported,
        ] );
    }

    // =========================================================================
    // AJAX: Import Series
    // =========================================================================

    public function handle_ajax_import_series(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );
        if ( ! $this->require_import_manager() ) return;

        @set_time_limit( 180 );

        $anilist_id    = intval( $_POST['anilist_id'] ?? 0 );
        $series_name   = sanitize_text_field( $_POST['series_name'] ?? '' );
        $root_id       = intval( $_POST['root_id'] ?? 0 );
        $series_romaji = sanitize_text_field( $_POST['series_romaji'] ?? '' );

        if ( ! $anilist_id ) wp_send_json_error( [ 'message' => '無效的 AniList ID' ] );

        $force  = ! empty( $_POST['force'] ) || ! empty( $_POST['force_update'] );
        $result = $this->import_and_enrich( $anilist_id, [ 'source' => 'series', 'force' => $force ] );

        if ( ! empty( $result['success'] ) && ! empty( $result['post_id'] ) && $series_name !== '' ) {
            $this->import_manager->assign_series_taxonomy(
                (int) $result['post_id'], $series_name, $root_id, $series_romaji
            );
            $result['series_assigned'] = true;
        }

        if ( empty( $result['success'] ) ) wp_send_json_error( [ 'message' => $result['message'] ?? '系列匯入失敗' ] );

        wp_send_json_success( $result );
    }

    // =========================================================================
    // AJAX: Popularity Ranking
    // =========================================================================

    public function handle_ajax_popularity_ranking(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );
        if ( ! $this->require_import_manager() ) return;

        $page = max( 1, intval( $_POST['page'] ?? 1 ) );

        if ( ! method_exists( $this->import_manager, 'get_popularity_ranking' ) ) {
            wp_send_json_error( [ 'message' => '功能不可用，請確認外掛版本是否為 1.0.5+' ] );
        }

        $result = $this->import_manager->get_popularity_ranking( $page );
        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        wp_send_json_success( $result );
    }

    // =========================================================================
    // AJAX: Resync Bangumi
    // =========================================================================

    public function handle_ajax_resync_bangumi(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );

        $post_id    = intval( $_POST['post_id'] ?? 0 );
        $bangumi_id = intval( $_POST['bangumi_id'] ?? 0 );

        if ( ! $post_id )    wp_send_json_error( [ 'message' => '無效的 post_id' ] );
        if ( ! $bangumi_id ) wp_send_json_error( [ 'message' => '請先填入 Bangumi ID 並儲存文章。' ] );
        if ( get_post_type( $post_id ) !== 'anime' ) wp_send_json_error( [ 'message' => '文章類型錯誤' ] );
        if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) wp_send_json_error( [ 'message' => '找不到 API Handler 類別' ] );

        $api    = new Anime_Sync_API_Handler();
        $result = $api->ajax_resync_bangumi( $post_id, $bangumi_id );

        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        wp_send_json_success( [ 'message' => '✅ 同步完成', 'updated' => $result ] );
    }

    // =========================================================================
    // AJAX: Resync AniList Images（★ 1.9.0 新增，只補封面 + 橫幅，尊重鎖定）
    // =========================================================================

    public function handle_ajax_resync_anilist_images(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '權限不足' ] );

        $post_id = intval( $_POST['post_id'] ?? 0 );

        if ( ! $post_id ) wp_send_json_error( [ 'message' => '無效的 post_id' ] );
        if ( get_post_type( $post_id ) !== 'anime' ) wp_send_json_error( [ 'message' => '文章類型錯誤' ] );
        if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) wp_send_json_error( [ 'message' => '找不到 API Handler 類別' ] );

        @set_time_limit( 60 );

        $api    = new Anime_Sync_API_Handler();
        $result = $api->ajax_resync_anilist_images( $post_id );

        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );

        // 組人話訊息
        $updated = $result['updated'] ?? [];
        $skipped = $result['skipped'] ?? [];

        $label_map = [
            'anime_cover_image'  => '封面',
            'anime_banner_image' => '橫幅',
        ];
        $updated_cn = array_map( fn( $k ) => $label_map[ $k ] ?? $k, $updated );
        $skipped_cn = array_map( fn( $k ) => $label_map[ $k ] ?? $k, $skipped );

        $msg_parts = [];
        if ( ! empty( $updated_cn ) ) $msg_parts[] = '已更新：' . implode( '、', $updated_cn );
        if ( ! empty( $skipped_cn ) ) $msg_parts[] = '已跳過（鎖定）：' . implode( '、', $skipped_cn );
        if ( empty( $result['has_banner'] ) ) $msg_parts[] = 'AniList 目前尚無橫幅圖';
        if ( empty( $msg_parts ) ) $msg_parts[] = 'AniList 無圖片資料可更新';

        wp_send_json_success( [
            'message' => '✅ ' . implode( '；', $msg_parts ),
            'result'  => $result,
        ] );
    }

    // =========================================================================
    // AJAX: Scan Series Gaps
    // =========================================================================

    public function handle_ajax_scan_series_gaps(): void {
        check_ajax_referer( 'anime_sync_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $force            = ! empty( $_POST['force'] );
        $cache_key        = 'anime_sync_series_gaps';
        $selected_ids_str = isset( $_POST['selected_ids'] ) ? sanitize_text_field( $_POST['selected_ids'] ) : '';
        $selected_ids     = [];

        if ( ! empty( $selected_ids_str ) ) {
            $selected_ids = array_filter( array_map( 'intval', explode( ',', $selected_ids_str ) ) );
        }

        if ( empty( $selected_ids ) ) wp_send_json_error( '請先在審核列表勾選要掃描的動漫作品' );

        if ( ! $force ) {
            $cached = get_transient( $cache_key . '_selected' );
            if ( false !== $cached && ! empty( $cached['ids'] ) && $cached['ids'] === $selected_ids ) {
                wp_send_json_success( [
                    'gaps'      => $cached['gaps'],
                    'cached'    => true,
                    'cached_at' => get_option( 'anime_sync_series_gaps_time', '' ),
                ] );
            }
        }

        $relation_types = [ 'PREQUEL', 'SEQUEL', 'PARENT', 'SIDE_STORY', 'SPIN_OFF' ];
        $gaps           = [];

        $posts = get_posts( [
            'post__in'       => $selected_ids,
            'post_type'      => 'anime',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        foreach ( $posts as $post_id ) {
            $relations_raw = get_post_meta( $post_id, 'anime_relations_json', true );
            if ( empty( $relations_raw ) ) continue;

            $relations = json_decode( $relations_raw, true );
            if ( ! is_array( $relations ) ) continue;

            $source_title      = get_post_meta( $post_id, 'anime_title_chinese', true ) ?: get_the_title( $post_id );
            $source_anilist_id = get_post_meta( $post_id, 'anime_anilist_id', true );

            foreach ( $relations as $rel ) {
                $type = strtoupper( $rel['relation_type'] ?? '' );
                if ( ! in_array( $type, $relation_types, true ) ) continue;

                $rel_anilist_id = isset( $rel['id'] ) ? intval( $rel['id'] ) : 0;
                if ( ! $rel_anilist_id ) continue;

                $existing = get_posts( [
                    'post_type'      => 'anime',
                    'post_status'    => [ 'publish', 'draft' ],
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'meta_query'     => [ [ 'key' => 'anime_anilist_id', 'value' => $rel_anilist_id, 'type' => 'NUMERIC' ] ],
                ] );

                if ( empty( $existing ) ) {
                    $type_cn_map = [
                        'PREQUEL'     => '前傳',   'SEQUEL'    => '續集',
                        'PARENT'      => '主系列', 'SIDE_STORY' => '外傳',
                        'SPIN_OFF'    => '衍生作品', 'ALTERNATIVE' => '平行世界',
                        'CHARACTER'   => '角色衍生', 'SUMMARY'   => '總集篇',
                        'OTHER'       => '其他',
                    ];
                    $gaps[] = [
                        'source_id'          => $post_id,
                        'source_title'       => $source_title,
                        'source_anilist_id'  => $source_anilist_id,
                        'source_url'         => get_edit_post_link( $post_id ),
                        'relation_type'      => $type,
                        'relation_type_cn'   => $type_cn_map[ $type ] ?? $type,
                        'missing_anilist_id' => $rel_anilist_id,
                        'missing_title'      => $rel['title'] ?? '',
                    ];
                }
            }
        }

        set_transient( $cache_key . '_selected', [ 'ids' => $selected_ids, 'gaps' => $gaps ], 6 * HOUR_IN_SECONDS );
        set_transient( $cache_key, $gaps, 6 * HOUR_IN_SECONDS );
        update_option( 'anime_sync_series_gaps_time', current_time( 'mysql' ) );

        wp_send_json_success( [
            'gaps'      => $gaps,
            'cached'    => false,
            'cached_at' => current_time( 'mysql' ),
            'total'     => count( $gaps ),
        ] );
    }

      // =========================================================================
    // 1.6.0 後台動畫列表篩選器
    // =========================================================================

    public function add_anime_filters(): void {
        global $typenow;
        if ( $typenow !== 'anime' ) return;

        $current_status = sanitize_text_field( $_GET['anime_status_filter'] ?? '' );
        $status_options = [
            ''                 => '全部狀態',
            'RELEASING'        => '放映中',
            'FINISHED'         => '已完結',
            'NOT_YET_RELEASED' => '未放映',
        ];
        echo '<select name="anime_status_filter">';
        foreach ( $status_options as $val => $label ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $current_status, $val, false ), esc_html( $label ) );
        }
        echo '</select> ';

        $current_season = sanitize_text_field( $_GET['anime_season_filter'] ?? '' );
        $seasons        = get_terms( [ 'taxonomy' => 'anime_season_tax', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'DESC', 'number' => 100 ] );
        echo '<select name="anime_season_filter">';
        echo '<option value="">全部季度</option>';
        if ( ! is_wp_error( $seasons ) ) {
            foreach ( $seasons as $term ) {
                if ( preg_match( '/^\d{4}$/', $term->slug ) ) continue;
                printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $current_season, $term->slug, false ), esc_html( $term->name ) );
            }
        }
        echo '</select> ';

        $current_format = sanitize_text_field( $_GET['anime_format_filter'] ?? '' );
        $formats        = get_terms( [ 'taxonomy' => 'anime_format_tax', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
        echo '<select name="anime_format_filter">';
        echo '<option value="">全部格式</option>';
        if ( ! is_wp_error( $formats ) ) {
            foreach ( $formats as $term ) {
                printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $current_format, $term->slug, false ), esc_html( $term->name ) );
            }
        }
        echo '</select> ';

        $current_series = sanitize_text_field( $_GET['anime_series_filter'] ?? '' );
        $series_list    = get_terms( [ 'taxonomy' => 'anime_series_tax', 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC', 'number' => 200 ] );
        echo '<select name="anime_series_filter">';
        echo '<option value="">全部系列</option>';
        if ( ! is_wp_error( $series_list ) ) {
            foreach ( $series_list as $term ) {
                printf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $current_series, $term->slug, false ), esc_html( $term->name ) );
            }
        }
        echo '</select> ';

        // ★ 人氣排序
        $current_pop_sort = sanitize_text_field( $_GET['anime_popularity_sort'] ?? '' );
        echo '<select name="anime_popularity_sort">';
        printf( '<option value="">%s</option>', '預設排序' );
        printf( '<option value="desc"%s>%s</option>', selected( $current_pop_sort, 'desc', false ), '人氣：高 → 低' );
        printf( '<option value="asc"%s>%s</option>',  selected( $current_pop_sort, 'asc',  false ), '人氣：低 → 高' );
        echo '</select>';
    }

    public function filter_anime_query( \WP_Query $query ): void {
        global $pagenow, $typenow;
        if ( $pagenow !== 'edit.php' || $typenow !== 'anime' || ! is_admin() ) return;
        if ( ! $query->is_main_query() ) return;

        $meta_query = $query->get( 'meta_query' ) ?: [];
        $tax_query  = $query->get( 'tax_query' )  ?: [];

        $status = sanitize_text_field( $_GET['anime_status_filter'] ?? '' );
        if ( $status !== '' ) {
            $meta_query[] = [ 'key' => 'anime_status', 'value' => $status, 'compare' => '=' ];
        }

        $season = sanitize_text_field( $_GET['anime_season_filter'] ?? '' );
        if ( $season !== '' ) {
            $tax_query[] = [ 'taxonomy' => 'anime_season_tax', 'field' => 'slug', 'terms' => $season ];
        }

        $format = sanitize_text_field( $_GET['anime_format_filter'] ?? '' );
        if ( $format !== '' ) {
            $tax_query[] = [ 'taxonomy' => 'anime_format_tax', 'field' => 'slug', 'terms' => $format ];
        }

        $series = sanitize_text_field( $_GET['anime_series_filter'] ?? '' );
        if ( $series !== '' ) {
            $tax_query[] = [ 'taxonomy' => 'anime_series_tax', 'field' => 'slug', 'terms' => $series ];
        }

        if ( ! empty( $meta_query ) ) $query->set( 'meta_query', $meta_query );
        if ( ! empty( $tax_query ) )  $query->set( 'tax_query',  $tax_query );

        // ★ 人氣排序（高→低 / 低→高）
        $pop_sort = sanitize_text_field( $_GET['anime_popularity_sort'] ?? '' );
        if ( $pop_sort === 'desc' || $pop_sort === 'asc' ) {
            $query->set( 'meta_key', 'anime_popularity' );
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'order', $pop_sort === 'asc' ? 'ASC' : 'DESC' );
        }
    }

    // =========================================================================
    // Enqueue Admin Assets
    // =========================================================================

    public function enqueue_admin_assets( string $hook ): void {
        $is_plugin_page = strpos( $hook, 'anime-sync' ) !== false;
        $is_anime_edit  = in_array( $hook, [ 'post.php', 'post-new.php' ], true )
            && ( get_post_type() === 'anime' || ( sanitize_key( $_GET['post_type'] ?? '' ) === 'anime' ) );

        if ( ! $is_plugin_page && ! $is_anime_edit ) return;

        $url = defined( 'ANIME_SYNC_PRO_URL' ) ? ANIME_SYNC_PRO_URL : plugin_dir_url( dirname( __FILE__ ) );

        if ( defined( 'ANIME_SYNC_PRO_VERSION' ) ) {
            $version = ANIME_SYNC_PRO_VERSION;
        } elseif ( defined( 'ANIME_SYNC_VERSION' ) ) {
            $version = ANIME_SYNC_VERSION;
        } else {
            $version = '1.0.0';
        }

        wp_enqueue_style(  'anime-sync-admin', $url . 'admin/assets/css/admin.css', [],          $version );
        wp_enqueue_script( 'anime-sync-admin', $url . 'admin/assets/js/admin.js',  [ 'jquery' ], $version, true );

        wp_localize_script( 'anime-sync-admin', 'animeSyncAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'anime_sync_admin_nonce' ),
            'actions' => [
                'import_single'          => 'anime_sync_import_single',
                'query_season'           => 'anime_sync_query_season',
                'query_announced'        => 'anime_sync_query_announced',
                'analyze_series'         => 'anime_sync_analyze_series',
                'import_series'          => 'anime_sync_import_series',
                'popularity_ranking'     => 'anime_sync_popularity_ranking',
                'bulk_action'            => 'anime_sync_bulk_action',
                'resync_bangumi'         => 'anime_resync_bangumi',
                'resync_anilist_images'  => 'anime_resync_anilist_images', // ★ 1.9.0 新增
                'enrich_single'          => 'anime_sync_enrich_single',
                'save_bangumi_id'        => 'anime_sync_save_bangumi_id',
                'update_map'             => 'anime_sync_update_map',
                'clear_cache'            => 'anime_sync_clear_cache',
                'clear_logs'             => 'anime_sync_clear_logs',
                'clear_log_files'        => 'anime_sync_clear_log_files',
                'scan_series_gaps'       => 'anime_sync_scan_series_gaps',
                'reschedule_cron'        => 'anime_sync_reschedule_cron',
                'convert_post'           => 'anime_sync_convert_post',
            ],
            'i18n' => [
                'network_error'   => '網路錯誤，請重試。',
                'unknown_error'   => '未知錯誤',
                'stop'            => '停止',
                'stopping'        => '停止中…',
                'invalid_id'      => '請輸入有效的 AniList ID。',
                'importing'       => '匯入中…',
                'start_import'    => '開始匯入',
                'import_failed'   => '匯入失敗。',
                'import_done'     => '匯入完成。成功 {d}/{t}',
                'import_stopped'  => '已停止匯入。',
                'edit_post'       => '編輯文章',
                'bangumi_pending' => 'Bangumi ID 未能自動解析，請至審核佇列手動填寫。',
                'querying'        => '查詢中…',
                'query_season'    => '第一步：查詢季度動畫清單',
                'query_failed'    => '查詢失敗。',
                'select_format'   => '請至少選擇一種格式。',
                'found_count'     => '共找到 {n} 部，顯示 {s} 部',
                'select_anime'    => '請勾選至少一部動畫。',
                'no_ids'          => '請輸入至少一個 ID。',
                'id_count_suffix' => ' 個 ID',
                'syncing'         => '同步中，請稍候…',
                'sync_success'    => '✅ 同步完成，頁面即將重新整理…',
                'sync_error'      => '❌ 同步失敗',
                'confirm_resync'  => '確定要重新同步 Bangumi 資料嗎？鎖定欄位將被跳過。',
                'error_no_id'     => '請先填入 Bangumi ID。',
            ],
        ] );
    }

} // end class Anime_Sync_Admin
