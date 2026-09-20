<?php
/**
 * YourAnimes 串流連結爬蟲
 *
 * 從 YourAnimes 動畫頁抓取台灣串流平台連結，自動填入 ACF 欄位並勾選對應 checkbox。
 *
 * 變更紀錄：
 * - [v1.7.0] run_single_sync() 失敗不再無聲：原本只有
 *            if ( ! is_wp_error( $result ) ) 這一支，抓取失敗時不寫 log、不重試、
 *            不留任何痕跡，使用者只會看到「串流是空的」卻查不出原因。
 *            以前一部一部人工貼網址時不明顯；改成匯入時自動觸發、一次連打好幾部
 *            之後就容易撞到——2026-09-02 實測一批 9 部有 3 部這樣默默失敗
 *            （重跑即成功，是暫時性錯誤）。正式站累積了 55 部「有網址但從未同步
 *            成功」的資料。
 *            現在失敗會記 warning（含錯誤碼與訊息）並排一次 5 分鐘後的重試，
 *            上限 2 次，用 post meta _anime_youranimes_retry 計數避免無限重排；
 *            成功後清掉計數。熔斷造成的失敗不計入重試次數——那是整站暫停，
 *            不是單筆的問題。
 *            另外 run_daily_sync() 的結果原本只報「失敗 N」，看不出是哪幾部，
 *            現在會另記一筆 warning 列出失敗的作品與錯誤碼（最多 10 筆）。
 * - [v1.6.0] write_to_acf() 尾端新增 maybe_fill_distributor()：同步抓到台灣自家平台
 *            （木棉花/羚邦/曼迪/回歸線/車庫）時，若代理商欄位為空，自動推導並填入
 *            anime_tw_distributor。僅在空白時填，不覆蓋人工已選的值。
 * - [v1.5.0] parse_streams() 中配區新增 YouTube 特判：改用連內文的 regex（PREG_SET_ORDER），
 *            YouTube 連結先抓 alt 文字，再用 youtube_alt_map 比對頻道關鍵字（如 Muse/木棉花、
 *            Ani-One），修正「木棉花 YouTube 國語配音連結在中配區被整個略過、抓不進來」的問題。
 *            非 YouTube 連結維持原本 domain_map 比對邏輯。
 * - [v1.4.0] 新增「填入網址即自動排程同步」機制，不再只依賴每日 cron 或手動按鈕：
 *            (1) 新增 maybe_auto_sync_on_meta_change()，監聽 updated_post_meta /
 *                added_post_meta，偵測 anime_youranimes_url 欄位有變化（且網址真的
 *                跟上次同步過的不同）時，用 wp_schedule_single_event 排一個 10 秒後
 *                執行的單次背景任務，避免影響後台儲存文章當下的反應速度。
 *            (2) 新增 run_single_sync()，實際執行單篇同步，成功後記錄
 *                _anime_youranimes_last_synced_url，避免同一網址重複觸發。
 *            (3) 每日 cron 保留作為安全網（處理排程當下撞到熔斷、或執行失敗的補漏）。
 * - [v1.3.0] parse_streams() 中配區改為抓「所有」符合平台的連結（不再只取一個代表連結）；
 *            write_to_acf() 改為組成多行「平台名稱|網址」格式寫入 anime_dub_url_mandarin，
 *            對齊前台 single-anime.php 的多平台解析邏輯與 ACF textarea 欄位。
 * - [v1.3.0] ajax_sync() 手動同步改為繞過 7 天快取（bypass_cache=true），
 *            避免使用者按下同步按鈕後仍拿到舊版 HTML 快取，誤以為沒生效。
 * - [v1.2.0] parse_streams() 新增中文配音區塊解析：以「中文配音/國語配音/中文版」關鍵字
 *            切開原音區與中配區。
 * - [v1.1.1] 時間窗修正：開播前 2 天 ~ 開播後 30 天（原為前 3 天 ~ 後 5 天）
 * - [v1.1.0] 新增 sync_post() 共用方法，手動按鈕與 cron 共用同一套抓取邏輯
 * - [v1.1.0] 新增每日 cron：開播前 2 天 ~ 開播後 30 天，自動抓取有 YourAnimes 網址的新番
 * - [v1.1.0] 「抓到頁但無平台」視為正常（尚無資料），不再計入 circuit breaker 失敗
 * - [v1.0.1] 新增 circuit breaker：連續 5 次失敗會暫停 1 小時，避免被對方擋 IP 後仍持續打
 * - [v1.0.1] error_log() 改用 Anime_Sync_Error_Logger 統一管理
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_YourAnimes_Fetcher {

    const CACHE_TTL          = 7 * DAY_IN_SECONDS;
    const RATE_LIMIT_MIN     = 2;
    const RATE_LIMIT_MAX     = 5;
    const TRANSIENT_PREFIX   = 'asp_youranimes_';

    // [v1.0.1] Circuit breaker
    const FAIL_COUNT_KEY     = 'asp_youranimes_fail_count';
    const CIRCUIT_OPEN_KEY   = 'asp_youranimes_circuit_open';
    const FAIL_THRESHOLD     = 5;            // 連續 5 次失敗即熔斷
    const CIRCUIT_OPEN_TTL   = HOUR_IN_SECONDS; // 熔斷 1 小時

    /*
     * [v1.1.0] Cron 窗口
     *
     * ⚠ [v1.5.0] 起這個日期窗口**不再是唯一條件**：run_daily_sync() 改成
     *   「anime_status = RELEASING 或 落在這個窗口內」取聯集。
     *   只靠日期會在季與季之間幾乎收不到東西（2026-09-20 實測當日只有 3 部），
     *   而且一部 12 集的番只有前 30 天會被同步。詳見該函式裡的說明。
     *   窗口保留是因為它還負責「開播前 2 天」——那時狀態還是 NOT_YET_RELEASED。
     */
    const CRON_HOOK          = 'asp_youranimes_daily';
    const WINDOW_BEFORE_DAYS = 2;   // 開播前 2 天開始
    const WINDOW_AFTER_DAYS  = 30;  // 開播後 30 天結束
    const CRON_BATCH_SIZE    = 100;  // 每次最多處理筆數

    /*
     * [v1.5.0] 過期重整（一次性回填，**刻意不掛排程**）：窗口外的作品永遠不會被重新同步
     *
     * 上面那個窗口（開播前 2 天～開播後 30 天）只照顧當季作品。老作品同步過一次之後
     * 就再也碰不到，而 YA 是人工編輯維護的——之後才新增的平台我們永遠不知道。
     *
     * 2026-09-20 抽樣 100 部實測：10 筆平台連結 YA 有、站上沒有，影響 5 部作品
     * （TIGER×DRAGON 2008 年的作品，YA 頁上 5 個平台錨點我們一個都沒寫，
     *  上次同步停在 2026-06-24）。
     *
     * ★ 這不是解析漏抓——那 5 部的 YA 頁面錨點都在、解析程式讀得到，純粹是資料過期。
     *
     * 取最久沒重整的一批重跑。母體是「已發布且有 YA 網址」的 1,804 部
     * （2026-09-20 實測；別用含草稿的 2,619，那是另一個口徑），推估補回約 180 筆。
     *
     * ★ 為什麼不掛排程（2026-09-20 與使用者討論後定案）
     *   收穫幾乎全在第一輪，之後每輪重抓 1,804 頁可能只換到零星幾筆。
     *   而 YA 回 `cache-control: private, no-cache, no-store`、**沒有 ETag 也沒有
     *   Last-Modified**（當日實測），條件式請求拿不到 304，每次重抓都是完整 134KB。
     *   YA 是免費、人工維護的個人站，又是我們台灣串流資料最重要的來源——
     *   為了一次性的收穫對它掛永久流量不划算也不厚道。
     *   所以做成 WP-CLI 一次性回填：`wp anime youranimes-refresh --loop`，
     *   跑完看實際產出再決定要不要加排程。
     *
     * ⚠ 排序**不能**用 anime_last_updated：那是 AniList 每日更新寫的，
     *   這個類別從頭到尾沒寫過它（2026-09-20 查證）。拿它排序的話，跑完同步
     *   它不會變 → 同一批 100 部每輪重跑、後面 1,700 部永遠輪不到。
     *   所以自己留一個戳記，**每處理一部就蓋一次**（成功、無資料、失敗都蓋），
     *   保證佇列一定往前走，壞掉的那幾部也不會卡住整條隊伍。
     */
    const STALE_BATCH_SIZE = 100;
    const STALE_STAMP_META = '_anime_youranimes_refreshed_at';

    // [v1.4.0] 單篇自動同步：填入網址後延遲執行的秒數（避免拖慢後台儲存反應）
    const AUTO_SYNC_HOOK  = 'asp_youranimes_single_sync';
    const AUTO_SYNC_DELAY = 10;

    public function __construct() {
        if ( defined( 'ANIME_YOURANIMES_ENABLED' ) && ! ANIME_YOURANIMES_ENABLED ) {
            return;
        }

        add_action( 'acf/render_field/name=anime_youranimes_url', [ $this, 'render_sync_button' ], 20 );
        add_action( 'wp_ajax_asp_sync_youranimes', [ $this, 'ajax_sync' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        // [v1.4.0] 偵測 anime_youranimes_url 欄位一有變化就自動排程單篇同步。
        add_action( 'updated_post_meta', [ $this, 'maybe_auto_sync_on_meta_change' ], 10, 4 );
        add_action( 'added_post_meta',   [ $this, 'maybe_auto_sync_on_meta_change' ], 10, 4 );
        add_action( self::AUTO_SYNC_HOOK, [ $this, 'run_single_sync' ], 10, 1 );

        // [v1.1.0] 每日 cron（保留作為安全網）
        add_filter( 'cron_schedules', [ $this, 'add_daily_schedule' ] );
        add_action( 'init', [ $this, 'maybe_schedule_cron' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_daily_sync' ] );
    }

    private function platform_map(): array {
        // ✅ [Registry] 改由 Anime_Sync_Streaming_Registry 統一管理
        return Anime_Sync_Streaming_Registry::get_domain_map();
    }

    private function youtube_alt_map(): array {
        // ✅ [Registry] 改由 Anime_Sync_Streaming_Registry 統一管理
        return Anime_Sync_Streaming_Registry::get_youtube_keyword_map();
    }

    public function render_sync_button( $field ) {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        $post_id = get_the_ID();
        if ( ! $post_id ) {
            global $post;
            $post_id = isset( $post->ID ) ? $post->ID : 0;
        }
        ?>
        <div class="asp-youranimes-sync-wrap" style="margin-top:10px;">
            <button type="button" class="button button-primary asp-youranimes-sync-btn"
                    data-post-id="<?php echo esc_attr( $post_id ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'asp_sync_youranimes_' . $post_id ) ); ?>">
                🌐 同步 YourAnimes 串流連結
            </button>
            <span class="asp-youranimes-sync-status" style="margin-left:10px;color:#666;"></span>
        </div>
        <?php
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        if ( get_post_type() !== 'anime' ) {
            return;
        }
        $script = "
        jQuery(document).on('click', '.asp-youranimes-sync-btn', function(e){
            e.preventDefault();
            var \$btn = jQuery(this);
            var \$status = \$btn.siblings('.asp-youranimes-sync-status');
            var postId = \$btn.data('post-id');
            var nonce = \$btn.data('nonce');
            var url = jQuery('input[name=\"acf[field_anime_youranimes_url]\"]').val();

            if (!url) {
                \$status.css('color','#d63638').text('⚠️ 請先填入 YourAnimes 網址');
                return;
            }

            \$btn.prop('disabled', true);
            \$status.css('color','#666').text('⏳ 同步中，請稍候 2-5 秒...');

            jQuery.post(ajaxurl, {
                action: 'asp_sync_youranimes',
                post_id: postId,
                nonce: nonce,
                url: url
            }, function(res){
                if (res.success) {
                    \$status.css('color','#00a32a').text('✅ ' + res.data.message);
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    \$status.css('color','#d63638').text('❌ ' + (res.data && res.data.message ? res.data.message : '同步失敗'));
                    \$btn.prop('disabled', false);
                }
            }).fail(function(){
                \$status.css('color','#d63638').text('❌ 網路錯誤，請稍後再試');
                \$btn.prop('disabled', false);
            });
        });
        ";
        wp_add_inline_script( 'jquery', $script );
    }

    /**
     * AJAX 處理：同步 YourAnimes 串流（手動按鈕）
     */
    public function ajax_sync() {
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
        $url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'asp_sync_youranimes_' . $post_id ) ) {
            wp_send_json_error( [ 'message' => '驗證失敗' ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => '權限不足' ] );
        }

        if ( empty( $url ) || ! preg_match( '#^https?://(www\.)?youranimes\.tw/animes/\d+#i', $url ) ) {
            wp_send_json_error( [ 'message' => '網址格式錯誤，請使用 https://youranimes.tw/animes/XXXX 格式' ] );
        }

        // 手動同步前，先確保欄位裡的網址已存檔
        update_post_meta( $post_id, 'anime_youranimes_url', $url );

        // Circuit breaker：熔斷中直接拒絕
        if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
            wp_send_json_error( [
                'message' => '近期連續同步失敗，YourAnimes 同步功能暫停 1 小時，請稍後再試或手動填入',
            ] );
        }

        // [v1.3.0] 手動同步改為繞過快取
        $result = $this->sync_post( $post_id, true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => '抓取失敗：' . $result->get_error_message() ] );
        }

        if ( empty( $result ) ) {
            wp_send_json_error( [ 'message' => '未找到任何串流平台，請手動填入（此頁可能為動態載入或舊資料）' ] );
        }

        // [v1.4.0] 手動同步成功也記錄 last_synced_url
        update_post_meta( $post_id, '_anime_youranimes_last_synced_url', $this->normalize_url( $url ) );

        wp_send_json_success( [
            'message' => sprintf( '同步完成！已更新 %d 個項目', count( $result ) ),
            'updated' => $result,
        ] );
    }

    // -------------------------------------------------------------------------
    // [v1.4.0] 填入網址即自動排程同步
    // -------------------------------------------------------------------------

    public function maybe_auto_sync_on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key !== 'anime_youranimes_url' ) {
            return;
        }
        if ( get_post_type( $post_id ) !== 'anime' ) {
            return;
        }

        $url = is_string( $meta_value ) ? trim( $meta_value ) : '';
        if ( empty( $url ) || ! preg_match( '#^https?://(www\.)?youranimes\.tw/animes/\d+#i', $url ) ) {
            return;
        }

        $normalized       = $this->normalize_url( $url );
        $last_synced_url  = get_post_meta( $post_id, '_anime_youranimes_last_synced_url', true );
        if ( $last_synced_url === $normalized ) {
            return;
        }

        if ( ! wp_next_scheduled( self::AUTO_SYNC_HOOK, [ $post_id ] ) ) {
            wp_schedule_single_event( time() + self::AUTO_SYNC_DELAY, self::AUTO_SYNC_HOOK, [ $post_id ] );
        }
    }

    /** 單篇同步失敗後的重試上限與間隔（post meta 計數，避免無限重排） */
    const RETRY_META      = '_anime_youranimes_retry';
    const RETRY_MAX       = 2;
    const RETRY_DELAY_SEC = 5 * MINUTE_IN_SECONDS;

    /**
     * 單篇自動同步。
     *
     * ★ 失敗時必須留下痕跡並重試。
     *
     * 原本只有 if ( ! is_wp_error( $result ) ) 這一支：抓取失敗時不寫 log、
     * 不重試、什麼都不留，使用者只會看到「串流是空的」卻查不出原因。
     * 以前一部一部人工貼網址時不明顯；改成匯入時自動觸發、一次連打好幾部之後，
     * 2026-09-02 實測一批 9 部就有 3 部這樣默默失敗（重跑即成功，是暫時性錯誤）。
     */
    public function run_single_sync( $post_id ) {
        $post_id = (int) $post_id;
        $url     = get_post_meta( $post_id, 'anime_youranimes_url', true );
        if ( empty( $url ) ) {
            return;
        }

        $post_title = get_the_title( $post_id ) ?: "ID {$post_id}";
        $result     = $this->sync_post( $post_id, true );

        if ( is_wp_error( $result ) ) {
            $retry = (int) get_post_meta( $post_id, self::RETRY_META, true );

            // 熔斷中不算重試次數：那是整站暫停，不是這一筆的問題，
            // 交給每日 cron 的安全網處理即可。
            if ( $result->get_error_code() === 'circuit_open' ) {
                $this->log_warning( "自動同步〔{$post_title}〕：熔斷中，本次略過" );
                return;
            }

            if ( $retry < self::RETRY_MAX ) {
                $retry++;
                update_post_meta( $post_id, self::RETRY_META, $retry );

                if ( ! wp_next_scheduled( self::AUTO_SYNC_HOOK, [ $post_id ] ) ) {
                    wp_schedule_single_event( time() + self::RETRY_DELAY_SEC, self::AUTO_SYNC_HOOK, [ $post_id ] );
                }

                $this->log_warning( sprintf(
                    '自動同步〔%s〕失敗（%s：%s），%d 分鐘後重試（第 %d／%d 次）',
                    $post_title,
                    $result->get_error_code(),
                    $result->get_error_message(),
                    self::RETRY_DELAY_SEC / MINUTE_IN_SECONDS,
                    $retry,
                    self::RETRY_MAX
                ) );
            } else {
                $this->log_warning( sprintf(
                    '自動同步〔%s〕失敗（%s：%s），已達重試上限 %d 次，放棄。'
                    . '每日 cron 仍會在開播窗口內再試；或到編輯頁按同步按鈕。',
                    $post_title,
                    $result->get_error_code(),
                    $result->get_error_message(),
                    self::RETRY_MAX
                ) );
            }
            return;
        }

        // 成功：清掉重試計數，記錄已同步的網址
        delete_post_meta( $post_id, self::RETRY_META );
        update_post_meta( $post_id, '_anime_youranimes_last_synced_url', $this->normalize_url( $url ) );

        if ( empty( $result ) ) {
            $this->log_info( "自動同步〔{$post_title}〕：抓到頁面但尚無串流平台資料" );
        } else {
            $this->log_info( "自動同步〔{$post_title}〕：成功更新 " . implode( '、', $result ) );
        }
    }

    // -------------------------------------------------------------------------
    // [v1.1.0] 共用核心：手動按鈕與 cron 共用
    // -------------------------------------------------------------------------

    /**
     * @param int  $post_id
     * @param bool $bypass_cache 繞過 7 天 HTML 快取
     * @param bool $fill_only    只補空白欄位、不覆蓋既有值（過期重整回填用，見 write_to_acf）
     */
    public function sync_post( $post_id, $bypass_cache = false, $fill_only = false ) {
        $url = get_post_meta( $post_id, 'anime_youranimes_url', true );

        if ( empty( $url ) || ! preg_match( '#^https?://(www\.)?youranimes\.tw/animes/\d+#i', $url ) ) {
            return new WP_Error( 'invalid_url', '網址格式錯誤或未填入' );
        }

        if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
            return new WP_Error( 'circuit_open', 'YourAnimes 同步暫停中（連續失敗熔斷）' );
        }

        $url = $this->normalize_url( $url );

        if ( $bypass_cache ) {
            delete_transient( self::TRANSIENT_PREFIX . md5( $url ) );
        }

        $html = $this->fetch_page( $url );

        if ( is_wp_error( $html ) ) {
            $this->record_failure();
            $this->log_warning( 'Fetch failed: ' . $html->get_error_message() . ' URL: ' . $url );
            return $html;
        }

        $streams = $this->parse_streams( $html );

        if ( empty( $streams ) ) {
            $this->reset_failures();
            return [];
        }

        $this->reset_failures();
        return $this->write_to_acf( $post_id, $streams, $fill_only );
    }

    // -------------------------------------------------------------------------
    // [v1.1.0] 每日 cron
    // -------------------------------------------------------------------------

    public function add_daily_schedule( $schedules ) {
        if ( ! isset( $schedules['daily'] ) ) {
            $schedules['daily'] = [
                'interval' => DAY_IN_SECONDS,
                'display'  => '每天一次',
            ];
        }
        return $schedules;
    }

    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }

    }

    /**
     * [v1.5.0] 過期重整：取最久沒重整的一批重跑。由 WP-CLI 呼叫，沒有排程。
     *
     * 為什麼不用 WP_Query：要「還沒蓋過戳記的排最前面」，而 meta_key 排序會把
     * 缺這個 meta 的作品整個濾掉——那正是首輪最該優先處理的一群（全部 1,804 部）。
     * 用 LEFT JOIN + COALESCE 才能讓它們排在最前。
     *
     * @param int $batch 這一輪處理幾部，0＝用 STALE_BATCH_SIZE
     * @return array{picked:int,ok:int,empty:int,fail:int,circuit:bool}
     */
    public function run_stale_refresh( int $batch = 0, string $status = 'publish' ): array {

        $stats = [ 'picked' => 0, 'ok' => 0, 'empty' => 0, 'fail' => 0, 'circuit' => false ];
        $batch = $batch > 0 ? $batch : self::STALE_BATCH_SIZE;

        if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
            $this->log_warning( '[回填] Circuit open，本輪過期重整整批跳過' );
            $stats['circuit'] = true;
            return $stats;
        }

        global $wpdb;

        $in = self::status_sql( $status );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ya.post_id
               FROM {$wpdb->postmeta} ya
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = ya.post_id AND p.post_type = 'anime' AND p.post_status IN ({$in})
               LEFT JOIN {$wpdb->postmeta} lu
                      ON lu.post_id = ya.post_id AND lu.meta_key = %s
              WHERE ya.meta_key = 'anime_youranimes_url'
                AND ya.meta_value LIKE %s
              ORDER BY COALESCE( lu.meta_value, '' ) ASC
              LIMIT %d",
            self::STALE_STAMP_META,
            '%youranimes.tw%',
            $batch
        ) );

        if ( empty( $ids ) ) {
            return $stats;
        }

        $stats['picked'] = count( $ids );

        $ok = 0;
        $empty = 0;
        $fail = 0;
        $fail_detail = [];

        foreach ( $ids as $pid ) {
            if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
                break;
            }

            $pid = (int) $pid;

            // fill_only＝true：回填只補缺，絕不覆寫人工修正或掃描器寫的網址
            $result = $this->sync_post( $pid, true, true );

            /*
             * 先蓋戳記再判斷結果：失敗的也要蓋，否則它會永遠排在隊首，
             * 每輪重試同一部、後面的永遠輪不到。
             */
            update_post_meta( $pid, self::STALE_STAMP_META, current_time( 'mysql' ) );

            if ( is_wp_error( $result ) ) {
                $fail++;
                if ( count( $fail_detail ) < 10 ) {
                    $fail_detail[] = sprintf( '%s(#%d %s)', get_the_title( $pid ) ?: '?', $pid, $result->get_error_code() );
                }
            } elseif ( empty( $result ) ) {
                $empty++;
            } else {
                $ok++;
            }
        }

        $this->log_info( sprintf(
            '[回填] 過期重整：更新 %d、尚無資料 %d、失敗 %d（本輪 %d 部）',
            $ok, $empty, $fail, count( $ids )
        ) );

        if ( $fail_detail ) {
            $this->log_warning( '[回填] 本輪過期重整失敗：' . implode( '、', $fail_detail )
                . ( $fail > count( $fail_detail ) ? sprintf( '…等 %d 部', $fail ) : '' ) );
        }

        $stats['ok']    = $ok;
        $stats['empty'] = $empty;
        $stats['fail']  = $fail;

        return $stats;
    }

    /**
     * 文章狀態白名單 → SQL 的 IN 子句。
     *
     * 只接受這三種寫法，其餘一律退回 'publish'——這個字串會直接拼進 SQL，
     * 不能讓外部值流進去。
     */
    private static function status_sql( string $status ): string {
        switch ( $status ) {
            case 'draft':
                return "'draft'";
            case 'any':
                return "'publish','draft'";
            default:
                return "'publish'";
        }
    }

    /**
     * 還有幾部沒蓋過戳記（給 CLI 顯示進度用）。
     */
    public function stale_remaining( string $status = 'publish' ): int {

        global $wpdb;

        $in = self::status_sql( $status );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->postmeta} ya
               INNER JOIN {$wpdb->posts} p
                       ON p.ID = ya.post_id AND p.post_type = 'anime' AND p.post_status IN ({$in})
               LEFT JOIN {$wpdb->postmeta} lu
                      ON lu.post_id = ya.post_id AND lu.meta_key = %s
              WHERE ya.meta_key = 'anime_youranimes_url'
                AND ya.meta_value LIKE %s
                AND lu.meta_id IS NULL",
            self::STALE_STAMP_META,
            '%youranimes.tw%'
        ) );
    }

    public function run_daily_sync() {
        if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
            $this->log_warning( '[Cron] Circuit open，本次每日同步整批跳過' );
            return;
        }

        $now = current_time( 'timestamp' );
        $upper_ymd = (int) gmdate( 'Ymd', strtotime( '+' . self::WINDOW_BEFORE_DAYS . ' days', $now ) );
        $lower_ymd = (int) gmdate( 'Ymd', strtotime( '-' . self::WINDOW_AFTER_DAYS . ' days', $now ) );

        $q = new WP_Query( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => self::CRON_BATCH_SIZE,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => 'anime_youranimes_url',
                    'value'   => 'youranimes.tw',
                    'compare' => 'LIKE',
                ],
                /*
                 * [v1.5.0] 「正在播的」或「日期窗口內的」，兩者取聯集。
                 *
                 * 原本只有下面那個日期窗口，而它綁 anime_start_date、只收開播後 30 天內。
                 * 2026-09-20 實測：那天窗口內**只有 3 部**（1,804 部裡），連續三天的日誌
                 * 都是「更新 1、尚無資料 2」——因為剛好卡在季與季之間，夏季番早就超過
                 * 開播後 30 天、秋季番還沒進開播前 2 天。
                 *
                 * 而且就算在播映期，一部 12 集的番也只有前 30 天會被同步，
                 * 第 5 集之後才上架的平台永遠抓不到。
                 *
                 * 改成同時收 anime_status = RELEASING（當日 64 部）後，涵蓋的是
                 * 「真正需要更新的那批」而不是靠日期硬切。日期窗口保留，
                 * 因為它還負責「開播前 2 天」那段——那時狀態還是 NOT_YET_RELEASED。
                 */
                [
                    'relation' => 'OR',
                    [
                        'key'     => 'anime_status',
                        'value'   => 'RELEASING',
                        'compare' => '=',
                    ],
                    [
                        'key'     => 'anime_start_date',
                        'value'   => [ $lower_ymd, $upper_ymd ],
                        'type'    => 'NUMERIC',
                        'compare' => 'BETWEEN',
                    ],
                ],
            ],
        ] );

        if ( empty( $q->posts ) ) {
            return;
        }

        $ok = 0;
        $empty = 0;
        $fail = 0;
        // 只記數字的話，事後看到「失敗 3」也不知道是哪幾部、為什麼失敗
        $fail_detail = [];

        foreach ( $q->posts as $pid ) {
            if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
                break;
            }

            $result = $this->sync_post( $pid, true );

            if ( is_wp_error( $result ) ) {
                $fail++;
                if ( count( $fail_detail ) < 10 ) {
                    $fail_detail[] = sprintf(
                        '%s(#%d %s)',
                        get_the_title( $pid ) ?: '?',
                        $pid,
                        $result->get_error_code()
                    );
                }
            } elseif ( empty( $result ) ) {
                $empty++;
            } else {
                $ok++;
                $url = get_post_meta( $pid, 'anime_youranimes_url', true );
                if ( ! empty( $url ) ) {
                    update_post_meta( $pid, '_anime_youranimes_last_synced_url', $this->normalize_url( $url ) );
                }
            }
        }

        $this->log_info( sprintf(
            '[Cron] 每日同步完成：更新 %d、尚無資料 %d、失敗 %d（本批 %d 部；正在播映 或 窗口 %s ~ %s）',
            $ok, $empty, $fail, count( $q->posts ), $lower_ymd, $upper_ymd
        ) );

        if ( $fail_detail ) {
            $this->log_warning( '[Cron] 本輪同步失敗：' . implode( '、', $fail_detail )
                . ( $fail > count( $fail_detail ) ? sprintf( '…等 %d 部', $fail ) : '' ) );
        }
    }

    // -------------------------------------------------------------------------
    // [v1.0.1] Circuit breaker
    // -------------------------------------------------------------------------

    private function record_failure(): void {
        $count = (int) get_transient( self::FAIL_COUNT_KEY );
        $count++;

        if ( $count >= self::FAIL_THRESHOLD ) {
            set_transient( self::CIRCUIT_OPEN_KEY, 1, self::CIRCUIT_OPEN_TTL );
            delete_transient( self::FAIL_COUNT_KEY );
            $this->log_warning( sprintf(
                'Circuit breaker OPEN：連續 %d 次失敗，YourAnimes 同步暫停 %d 分鐘',
                self::FAIL_THRESHOLD,
                self::CIRCUIT_OPEN_TTL / MINUTE_IN_SECONDS
            ) );
        } else {
            set_transient( self::FAIL_COUNT_KEY, $count, 30 * MINUTE_IN_SECONDS );
        }
    }

    private function reset_failures(): void {
        delete_transient( self::FAIL_COUNT_KEY );
    }

    /*
     * 正常結果與異常分開記錄。
     *
     * 原本所有訊息都走 log_warning()，「成功更新」「每日同步完成」這類正常
     * 結果也被記成 warning，把真正需要處理的警告稀釋掉（錯誤日誌一週
     * 5900 筆 info、583 筆 warning，其中大半是本類別的成功訊息）。
     * 例行結果改記 info，warning 只留給真正的異常：抓取失敗與熔斷。
     */
    private function log_info( string $message ): void {
        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::info( '[YourAnimes Fetcher] ' . $message );
        } else {
            error_log( '[YourAnimes Fetcher] ' . $message );
        }
    }

    private function log_warning( string $message ): void {
        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::warning( '[YourAnimes Fetcher] ' . $message );
        } else {
            error_log( '[YourAnimes Fetcher] ' . $message );
        }
    }

    // -------------------------------------------------------------------------
    // URL／抓頁／解析
    // -------------------------------------------------------------------------

    private function normalize_url( $url ) {
        $url = preg_replace( '#[\?\#].*$#', '', $url );
        $url = rtrim( $url, '/' );
        if ( ! preg_match( '#/onair$#', $url ) ) {
            $url = preg_replace( '#/(news|stats|episodes|comments)$#', '', $url );
            $url .= '/onair';
        }
        return $url;
    }

    private function fetch_page( $url ) {
        $cache_key = self::TRANSIENT_PREFIX . md5( $url );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $delay = wp_rand( self::RATE_LIMIT_MIN, self::RATE_LIMIT_MAX );
        sleep( $delay );

        $args = [
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'headers'     => [
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language'           => 'zh-TW,zh;q=0.9,en;q=0.8',
                'Accept-Encoding'           => 'gzip, deflate, br',
                'Referer'                   => 'https://www.google.com/',
                'Sec-Fetch-Dest'            => 'document',
                'Sec-Fetch-Mode'            => 'navigate',
                'Sec-Fetch-Site'            => 'cross-site',
                'Sec-Fetch-User'            => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ];

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'http_error', 'HTTP ' . $code );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return new WP_Error( 'empty_body', '回應內容為空' );
        }

        set_transient( $cache_key, $body, self::CACHE_TTL );
        return $body;
    }

    private function parse_streams( $html ) {
        $streams = [];

        $platform_map = $this->platform_map();
        $youtube_map  = $this->youtube_alt_map();

        $dub_pos = false;
        foreach ( [ '中文配音', '國語配音', '中文版' ] as $kw ) {
            $p = mb_strpos( $html, $kw );
            if ( $p !== false && ( $dub_pos === false || $p < $dub_pos ) ) {
                $dub_pos = $p;
            }
        }

        if ( $dub_pos !== false ) {
            $html_main = mb_substr( $html, 0, $dub_pos );
            $html_dub  = mb_substr( $html, $dub_pos );
        } else {
            $html_main = $html;
            $html_dub  = '';
        }

        $ani_one_chinese = null;
        $ani_one_first   = null;

        if ( preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#si', $html_main, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $href  = $match[1];
                $inner = $match[2];

                $host = parse_url( $href, PHP_URL_HOST );
                if ( ! $host ) {
                    continue;
                }
                $host = strtolower( $host );
                $host = preg_replace( '#^www\.#', '', $host );

                if ( strpos( $host, 'youtube.com' ) !== false || strpos( $host, 'youtu.be' ) !== false ) {
                    $alt = '';
                    if ( preg_match( '#alt=["\']([^"\']+)["\']#i', $inner, $am ) ) {
                        $alt = $am[1];
                    }
                    if ( empty( $alt ) ) {
                        $alt = wp_strip_all_tags( $inner );
                    }
                    if ( empty( $alt ) ) {
                        continue;
                    }
                    // YA 的 alt 會把撇號轉成 HTML 實體（It's Anime → It&#x27;s Anime），
                    // 不先還原的話，含撇號的頻道關鍵字永遠比對不到。
                    $alt = html_entity_decode( $alt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

                    foreach ( $youtube_map as $acf_key => $keywords ) {
                        foreach ( $keywords as $kw ) {
                            if ( stripos( $alt, $kw ) !== false ) {
                                if ( $acf_key === 'ani_one' ) {
                                    if ( stripos( $alt, '中文官方' ) !== false || stripos( $alt, '中文' ) !== false ) {
                                        $ani_one_chinese = $href;
                                    } elseif ( $ani_one_first === null ) {
                                        $ani_one_first = $href;
                                    }
                                    break 2;
                                }

                                if ( ! isset( $streams[ $acf_key ] ) ) {
                                    $streams[ $acf_key ] = $href;
                                }
                                break 2;
                            }
                        }
                    }
                    continue;
                }

                foreach ( $platform_map as $needle => $acf_key ) {
                    if ( strpos( $host, $needle ) !== false ) {
                        if ( ! isset( $streams[ $acf_key ] ) ) {
                            $streams[ $acf_key ] = $href;
                        }
                        break;
                    }
                }
            }

            if ( $ani_one_chinese ) {
                $streams['ani_one'] = $ani_one_chinese;
            } elseif ( $ani_one_first ) {
                $streams['ani_one'] = $ani_one_first;
            }
        }

        $dub_items = [];
        if ( $html_dub !== '' && preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#si', $html_dub, $dmatches, PREG_SET_ORDER ) ) {
            foreach ( $dmatches as $dm ) {
                $href  = $dm[1];
                $inner = $dm[2];

                $host = parse_url( $href, PHP_URL_HOST );
                if ( ! $host ) {
                    continue;
                }
                $host = strtolower( preg_replace( '#^www\.#', '', $host ) );

                if ( strpos( $host, 'youtube.com' ) !== false || strpos( $host, 'youtu.be' ) !== false ) {
                    $alt = '';
                    if ( preg_match( '#alt=["\']([^"\']+)["\']#i', $inner, $am ) ) {
                        $alt = $am[1];
                    }
                    if ( empty( $alt ) ) {
                        $alt = wp_strip_all_tags( $inner );
                    }
                    if ( $alt === '' ) {
                        continue;
                    }

                    foreach ( $youtube_map as $acf_key => $keywords ) {
                        foreach ( $keywords as $kw ) {
                            if ( stripos( $alt, $kw ) !== false ) {
                                if ( ! isset( $dub_items[ $acf_key ] ) ) {
                                    $dub_items[ $acf_key ] = $href;
                                }
                                break 2;
                            }
                        }
                    }
                    continue;
                }

                foreach ( $platform_map as $needle => $acf_key ) {
                    if ( strpos( $host, $needle ) !== false ) {
                        if ( ! isset( $dub_items[ $acf_key ] ) ) {
                            $dub_items[ $acf_key ] = $href;
                        }
                        break;
                    }
                }
            }
        }

        if ( ! empty( $dub_items ) ) {
            $streams['__dub_mandarin_multi'] = $dub_items;
        }

        return $streams;
    }

    /**
     * @param int   $post_id
     * @param array $streams
     * @param bool  $fill_only 只補空白、不覆蓋既有值
     *
     * 預設（false）維持原本的「以 YA 為準覆寫」——每日同步處理的是當季在地新番，
     * 那些欄位本來就是 YA 寫的，覆寫等於更新。
     *
     * 過期重整回填要傳 true：它掃的是全站 1,804 部，裡面有**人工修正過的網址**、
     * 掃描器寫的網址、以及查證後刻意保留的值（例如 Bilibili 那批搜尋頁連結）。
     * 無條件覆寫會把這些靜默改寫成 YA 的版本——而回填的目的只是「補缺」，
     * 不是「以 YA 為準重寫全站」。
     */
    private function write_to_acf( $post_id, $streams, $fill_only = false ) {
        $updated = [];

        if ( isset( $streams['__dub_mandarin_multi'] ) ) {
            $dub_items = $streams['__dub_mandarin_multi'];
            unset( $streams['__dub_mandarin_multi'] );

            $acf_choices = class_exists( 'Anime_Sync_Streaming_Registry' )
                ? Anime_Sync_Streaming_Registry::get_acf_choices()
                : [];

            $lines = [];
            foreach ( $dub_items as $acf_key => $url ) {
                $label   = $acf_choices[ $acf_key ] ?? $acf_key;
                $lines[] = $label . '|' . $url;
            }

            // 配音欄位照設計是人工維護的，回填模式一律不覆蓋既有內容
            $dub_existing = (string) get_post_meta( $post_id, 'anime_dub_url_mandarin', true );

            if ( ! empty( $lines ) && ! ( $fill_only && $dub_existing !== '' ) ) {
                update_post_meta( $post_id, 'anime_dub_url_mandarin', implode( "\n", $lines ) );

                $dub_lang = get_post_meta( $post_id, 'anime_dub_language', true );
                if ( ! is_array( $dub_lang ) ) {
                    $dub_lang = [];
                }
                if ( ! in_array( 'mandarin', $dub_lang, true ) ) {
                    $dub_lang[] = 'mandarin';
                    update_post_meta( $post_id, 'anime_dub_language', $dub_lang );
                }

                $updated[] = '國語配音（' . count( $lines ) . ' 平台）';
            }
        }

        $checked = get_post_meta( $post_id, 'anime_tw_streaming', true );
        if ( ! is_array( $checked ) ) {
            $checked = [];
        }

        foreach ( $streams as $acf_key => $url ) {

            if ( $fill_only
                && (string) get_post_meta( $post_id, 'anime_tw_streaming_url_' . $acf_key, true ) !== '' ) {
                /*
                 * 已有值就不動。但「有網址卻沒勾選」是真的會讓前台不顯示的不一致，
                 * 這個還是補上——它只會讓既有資料被看見，不會改寫任何網址。
                 */
                if ( ! in_array( $acf_key, $checked, true ) ) {
                    $checked[] = $acf_key;
                }
                continue;
            }

            update_post_meta( $post_id, 'anime_tw_streaming_url_' . $acf_key, $url );

            if ( ! in_array( $acf_key, $checked, true ) ) {
                $checked[] = $acf_key;
            }

            $updated[] = $acf_key;
        }

        update_post_meta( $post_id, 'anime_tw_streaming', $checked );

        // ── [v1.6.0] 自動推導台灣代理商（僅在代理商空白時；不覆蓋人工已選）──
        $this->maybe_fill_distributor( $post_id, $checked );

        // ── 自動提取 YouTube 清單網址並立即觸發同步 ──
        //
        // ★ 國際多語頻道（註冊表 yt_global，例如 It's Anime）排最後：
        //   YA 可能同時列出台灣代理商（木棉花、羚邦）與國際頻道。國際頻道標題是英文、
        //   清單還混著片段剪輯，若它剛好先出現在頁面上，就會搶走中文播放清單。
        //   順序：主要平台的台灣頻道 → 中文配音的台灣頻道 → 國際頻道。
        $yt_playlist_url = '';
        $yt_global_url   = '';
        foreach ( $streams as $acf_key => $url ) {
            if ( is_string( $url ) && ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) && strpos( $url, 'list=' ) !== false ) {
                if ( class_exists( 'Anime_Sync_Streaming_Registry' )
                    && Anime_Sync_Streaming_Registry::is_yt_global( (string) $acf_key ) ) {
                    if ( $yt_global_url === '' ) {
                        $yt_global_url = $url;
                    }
                    continue;
                }
                $yt_playlist_url = $url;
                break;
            }
        }
        if ( empty( $yt_playlist_url ) && isset( $dub_items ) && is_array( $dub_items ) ) {
            foreach ( $dub_items as $acf_key => $url ) {
                if ( is_string( $url ) && ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) && strpos( $url, 'list=' ) !== false ) {
                    $yt_playlist_url = $url;
                    break;
                }
            }
        }
        $yt_is_global = false;
        if ( empty( $yt_playlist_url ) && $yt_global_url !== '' ) {
            $yt_playlist_url = $yt_global_url;
            $yt_is_global    = true;
        }

        if ( ! empty( $yt_playlist_url ) ) {
            $current_yt_url = get_post_meta( $post_id, 'anime_yt_playlist_url', true );
            // 國際頻道只補空白：已經有播放清單（可能是手動填的台灣頻道）就沿用，不覆蓋
            if ( $yt_is_global && ! empty( $current_yt_url ) ) {
                $yt_playlist_url = $current_yt_url;
            }
            /*
             * 回填模式的兩道保護（2026-09-20）：
             *
             * 一、已有播放清單就完全不動。上面那個 $yt_is_global 判斷只擋國際頻道，
             *     台灣頻道的清單仍會覆寫既有值——包含人工填的。
             *
             * 二、不觸發下面的集數同步。回填要掃 1,804 部，逐部立即同步等於對
             *     YouTube Data API 打上千次，配額會被這個一次性作業吃光，
             *     連帶影響其他功能。集數本來就有自己的排程會處理。
             */
            if ( $fill_only && ! empty( $current_yt_url ) ) {
                return $updated;
            }

            if ( $current_yt_url !== $yt_playlist_url ) {
                if ( function_exists( 'update_field' ) ) {
                    update_field( 'field_anime_yt_playlist_url', $yt_playlist_url, $post_id );
                } else {
                    update_post_meta( $post_id, 'anime_yt_playlist_url', $yt_playlist_url );
                }
            }

            if ( $fill_only ) {
                return $updated;
            }

            // 立即觸發 YouTube 清單同步
            if ( class_exists( 'Anime_Sync_YouTube_Playlist_Sync' ) ) {
                $yt_sync = new Anime_Sync_YouTube_Playlist_Sync();
                $yt_res = $yt_sync->sync_post( $post_id, true );
                if ( ! is_wp_error( $yt_res ) && ! empty( $yt_res['msg'] ) ) {
                    $updated[] = 'YouTube 自動同步 (' . $yt_res['msg'] . ')';
                }
            }
        }

        return $updated;
    }

    /**
     * [v1.6.0] 依台灣串流平台自動推導代理商。
     * 只在 anime_tw_distributor 為空時才填，不覆蓋人工已選的值
     * （例如 aniplus 這種推不出來、需人工填的個案不會被動到）。
     *
     * @param int   $post_id
     * @param array $checked  anime_tw_streaming 已勾選的平台 key 陣列
     */
    private function maybe_fill_distributor( $post_id, $checked ): void {
        if ( ! is_array( $checked ) || empty( $checked ) ) {
            return;
        }

        // 已有代理商就不動（保護人工填的值）
        $current = get_post_meta( $post_id, 'anime_tw_distributor', true );
        if ( ! empty( $current ) ) {
            return;
        }

        // 平台 → 代理商 對應（優先序：由上到下，命中即停）
        $map = [
            'muse'         => 'muse',       // 木棉花
            'ani_one'      => 'linbang',    // 羚邦
            'mighty'       => 'medialink',  // 曼迪
            'tropicsanime' => 'tropic',     // 回歸線
            'anipass'      => 'garage',     // 車庫
            'garageplay'   => 'garage',     // 車庫
        ];

        foreach ( $map as $platform => $distributor ) {
            if ( in_array( $platform, $checked, true ) ) {
                // 用 update_field 同時寫值與 ACF field key，後台下拉才會正確顯示選中
                if ( function_exists( 'update_field' ) ) {
                    update_field( 'field_anime_tw_distributor', $distributor, $post_id );
                } else {
                    update_post_meta( $post_id, 'anime_tw_distributor', $distributor );
                }

                $this->log_info( sprintf(
                    '自動代理商〔%s〕：偵測到平台 %s → 代理商 %s',
                    get_the_title( $post_id ) ?: "ID {$post_id}",
                    $platform,
                    $distributor
                ) );
                break; // 命中一個就停
            }
        }
    }
}

/*
 * WP-CLI：YourAnimes 過期重整（一次性回填）
 *
 * 刻意做成手動指令而非排程，理由見 class 裡 STALE_BATCH_SIZE 上方的說明
 * ——簡單講：收穫是一次性的，而 YA 沒有 ETag／Last-Modified，
 * 每次重抓都是完整 134KB，不該為此對一個免費個人站掛永久流量。
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * 補回窗口外作品的 YourAnimes 平台連結。
	 *
	 * 進度存在每篇的 _anime_youranimes_refreshed_at，中斷後再跑會從沒處理過的接續，
	 * 不會重頭來過。
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : 每輪處理幾部，預設 100。
	 *
	 * [--loop]
	 * : 反覆執行直到全部跑過一輪為止。不加則只跑一輪。
	 *
	 * [--status=<status>]
	 * : publish（預設）／draft／any。
	 *   ★ 草稿要另外跑：每日同步與本指令預設都只處理已發布的作品，
	 *     而匯入產生的是草稿——它只在匯入當下同步過一次，之後 YA 新增的平台
	 *     永遠不會進來（2026-09-20 實測：1,382 部草稿有 YA 網址，0 部被重整過）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp anime youranimes-refresh --batch=20
	 *     wp anime youranimes-refresh --loop
	 *     wp anime youranimes-refresh --status=draft --loop
	 */
	WP_CLI::add_command( 'anime youranimes-refresh', function ( $args, $assoc_args ) {

		$batch  = (int) ( $assoc_args['batch'] ?? 0 );
		$loop   = isset( $assoc_args['loop'] );
		$status = (string) ( $assoc_args['status'] ?? 'publish' );

		if ( ! in_array( $status, [ 'publish', 'draft', 'any' ], true ) ) {
			WP_CLI::error( '--status 只能是 publish / draft / any' );
		}

		$fetcher = new Anime_Sync_YourAnimes_Fetcher();

		WP_CLI::log( sprintf( '處理範圍：%s', $status ) );
		$remaining = $fetcher->stale_remaining( $status );
		WP_CLI::log( sprintf( '尚未處理：%d 部', $remaining ) );

		if ( 0 === $remaining ) {
			WP_CLI::success( '全部都已經跑過一輪，沒有待處理的。' );
			return;
		}

		$round = 0;
		$tot   = [ 'picked' => 0, 'ok' => 0, 'empty' => 0, 'fail' => 0 ];

		do {
			$round++;
			$s = $fetcher->run_stale_refresh( $batch, $status );

			foreach ( [ 'picked', 'ok', 'empty', 'fail' ] as $k ) {
				$tot[ $k ] += $s[ $k ];
			}

			$remaining = $fetcher->stale_remaining( $status );

			WP_CLI::log( sprintf(
				'第 %d 輪：處理 %d、更新 %d、尚無資料 %d、失敗 %d；未處理剩 %d 部',
				$round, $s['picked'], $s['ok'], $s['empty'], $s['fail'], $remaining
			) );

			if ( $s['circuit'] ) {
				WP_CLI::warning( 'YourAnimes 熔斷中，本次停止。稍後再跑會從中斷處接續。' );
				break;
			}

			/*
			 * 收尾條件一定要看「未蓋戳記數」，不能看「這輪有沒有挑到東西」。
			 * 查詢本身沒有「只挑未蓋戳記」的條件（它是按戳記新舊排序、永遠挑得到
			 * 100 部），拿 picked===0 當條件的話 --loop 會無限繞圈重跑全站。
			 */
			if ( 0 === $remaining ) {
				break;
			}
		} while ( $loop );

		WP_CLI::success( sprintf(
			'共 %d 輪：處理 %d 部，更新 %d、尚無資料 %d、失敗 %d；未處理剩 %d 部',
			$round, $tot['picked'], $tot['ok'], $tot['empty'], $tot['fail'], $remaining
		) );
	} );
}
