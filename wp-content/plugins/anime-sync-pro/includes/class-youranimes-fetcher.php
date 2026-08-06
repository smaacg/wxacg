<?php
/**
 * YourAnimes 串流連結爬蟲
 *
 * 從 YourAnimes 動畫頁抓取台灣串流平台連結，自動填入 ACF 欄位並勾選對應 checkbox。
 *
 * 變更紀錄：
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

    // [v1.1.0] Cron 窗口
    const CRON_HOOK          = 'asp_youranimes_daily';
    const WINDOW_BEFORE_DAYS = 2;   // 開播前 2 天開始
    const WINDOW_AFTER_DAYS  = 30;  // 開播後 30 天結束
    const CRON_BATCH_SIZE    = 100;  // 每次最多處理筆數

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

    public function run_single_sync( $post_id ) {
        $post_id = (int) $post_id;
        $url     = get_post_meta( $post_id, 'anime_youranimes_url', true );
        if ( empty( $url ) ) {
            return;
        }

        $result = $this->sync_post( $post_id, true );

        if ( ! is_wp_error( $result ) ) {
            update_post_meta( $post_id, '_anime_youranimes_last_synced_url', $this->normalize_url( $url ) );

            $post_title = get_the_title( $post_id ) ?: "ID {$post_id}";
            if ( empty( $result ) ) {
                $this->log_warning( "自動同步〔{$post_title}〕：抓到頁面但尚無串流平台資料" );
            } else {
                $this->log_warning( "自動同步〔{$post_title}〕：成功更新 " . implode( '、', $result ) );
            }
        }
    }

    // -------------------------------------------------------------------------
    // [v1.1.0] 共用核心：手動按鈕與 cron 共用
    // -------------------------------------------------------------------------

    public function sync_post( $post_id, $bypass_cache = false ) {
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
        return $this->write_to_acf( $post_id, $streams );
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
                [
                    'key'     => 'anime_start_date',
                    'value'   => [ $lower_ymd, $upper_ymd ],
                    'type'    => 'NUMERIC',
                    'compare' => 'BETWEEN',
                ],
            ],
        ] );

        if ( empty( $q->posts ) ) {
            return;
        }

        $ok = 0;
        $empty = 0;
        $fail = 0;

        foreach ( $q->posts as $pid ) {
            if ( get_transient( self::CIRCUIT_OPEN_KEY ) ) {
                break;
            }

            $result = $this->sync_post( $pid, true );

            if ( is_wp_error( $result ) ) {
                $fail++;
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

        $this->log_warning( sprintf(
            '[Cron] 每日同步完成：更新 %d、尚無資料 %d、失敗 %d（窗口 %s ~ %s）',
            $ok, $empty, $fail, $lower_ymd, $upper_ymd
        ) );
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

    private function write_to_acf( $post_id, $streams ) {
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

            if ( ! empty( $lines ) ) {
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
            update_post_meta( $post_id, 'anime_tw_streaming_url_' . $acf_key, $url );

            if ( ! in_array( $acf_key, $checked, true ) ) {
                $checked[] = $acf_key;
            }

            $updated[] = $acf_key;
        }

        update_post_meta( $post_id, 'anime_tw_streaming', $checked );

        // ── [v1.6.0] 自動推導台灣代理商（僅在代理商空白時；不覆蓋人工已選）──
        $this->maybe_fill_distributor( $post_id, $checked );

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
        if ( $current !== '' && $current !== null ) {
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

                $this->log_warning( sprintf(
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
