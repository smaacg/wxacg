<?php
/**
 * 檔案名稱: includes/class-youtube-playlist-sync.php
 *
 * 功能:定時 + 存檔時,讀取每篇 anime 的 YouTube 播放清單,抓取影片,
 *       自動追加進 ACF 欄位 anime_online_watch(只增不刪、不覆蓋手動內容)。
 *
 * 判斷邏輯(v1.7.x):
 *   - 先過 PV 黑名單:命中 PV/預告/OP/ED/主題曲/MV 等關鍵字的影片一律略過。
 *   - 通過黑名單的影片「全部收進來」:
 *       * 標題含集數(第X話 / #X / EPx)→ 標題顯示為「第X話」。
 *       * 無集數(OVA/OAD/馬拉松/特別篇等)→ 簡化為短標籤(OVA/OAD/馬拉松…),
 *         認不出類型才退回 YouTube 原標題。
 *   - 去重改用「影片 video_id」為鍵(不再用集數編號),
 *     同一清單多季/多作品(各自從第1話起算)不會互相蓋掉。
 *   - 依清單原始順序追加。
 *
 * 同步開關(anime_yt_sync_enabled):
 *   - 值為 '0'(關閉)→ cron / 存檔 / 填網址自動觸發 全部略過,不動內容。
 *   - 手動按「立即同步」按鈕 → 強制執行($force=true),無視開關。
 *
 * 觸發時機:
 *   1) 每小時 cron。 2) 存檔觸發。 3) 手動按鈕(強制)。 4) 填網址自動同步。
 *
 * 需在 wp-config.php 定義:
 *   define( 'SMACG_YT_API_KEY', '你的_YouTube_Data_API_v3_金鑰' );
 *
 * 修正紀錄:
 *   - [v1.7.2] 同步開關全面生效:sync_post() 加總閘門($enabled==='0' 則略過),
 *              手動按鈕以 $force=true 強制執行;cron query 移除 NOT EXISTS,
 *              僅同步明確啟用(=1)的文章。
 *   - [v1.7.1] 沒集數的特殊影片(OVA/OAD/馬拉松/特別篇等)標題簡化為短標籤,
 *              新增 guess_special_label();認不出類型才用原標題。
 *   - [v1.7.0] 改為「清單所有影片都收(僅過濾 PV/預告/OP/ED/主題曲)」:
 *              通過黑名單的影片全部保留;去重鍵由集數編號改為 video_id。
 *   - [v1.6.0] 對齊 YourAnimes「填網址即可同步」:JS 帶欄位值、AJAX 先寫回 meta、
 *              maybe_auto_sync_on_meta_change 自動排程。
 *   - [v1.5.0] 手動按鈕改 acf/render_field + admin_enqueue_scripts + 自動 reload。
 *   - [v1.3.0] 存檔觸發背景抓取。
 *   - [v1.2.0] 集數辨識支援「幕」;新增 extract_episode_range()。
 *   - [v1.1.0] 整合全站錯誤日誌。
 *
 * @package Anime_Sync_Pro
 * @version 1.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_YouTube_Playlist_Sync {

    /** 積極抓取視窗:播出時間後 N 小時內每小時抓 */
    const ACTIVE_WINDOW_HOURS = 72;

    /** 保底抓取間隔(連載中但無 next_airing):秒 */
    const FALLBACK_INTERVAL = DAY_IN_SECONDS;

    /** 填網址後延遲執行的秒數(避免拖慢後台儲存反應) */
    const AUTO_SYNC_DELAY = 5;

    /** PV / 預告 / OP / ED / 主題曲 關鍵字黑名單(標題命中則略過) */
    const PV_KEYWORDS = [
        'PV', '预告', '預告', 'trailer', 'teaser', 'CM',
        'ノンクレジット', 'ノンテロップ', 'non-credit', 'noncredit',
        'character', 'キャラクター', '聲優', '声優', 'interview', 'インタビュー',
        'OP主題', 'ED主題', 'OP theme', 'ED theme', 'MV', 'spot',
        '本予告', '特報', '弾', 'digest', 'ダイジェスト',
        '精華', 'highlight', 'ハイライト',
        '片頭', '片尾', '歌詞', 'lyric',
    ];

    public function __construct() {
        add_action( 'asp_yt_playlist_sync_cron', [ $this, 'run_scheduled_sync' ] );
        add_action( 'init', [ $this, 'maybe_schedule' ] );
        add_action( 'wp_ajax_asp_yt_sync_single', [ $this, 'ajax_sync_single' ] );

        add_action( 'save_post_anime', [ $this, 'on_save_schedule_sync' ], 20, 3 );
        add_action( 'asp_yt_sync_single_bg', [ $this, 'run_single_bg' ] );

        add_action( 'acf/render_field/name=anime_yt_playlist_url', [ $this, 'render_sync_button' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        add_action( 'updated_post_meta', [ $this, 'maybe_auto_sync_on_meta_change' ], 10, 4 );
        add_action( 'added_post_meta',   [ $this, 'maybe_auto_sync_on_meta_change' ], 10, 4 );
    }

    public function maybe_schedule(): void {
        if ( ! wp_next_scheduled( 'asp_yt_playlist_sync_cron' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'asp_yt_playlist_sync_cron' );
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( 'asp_yt_playlist_sync_cron' );
        if ( $ts ) {
            wp_unschedule_event( $ts, 'asp_yt_playlist_sync_cron' );
        }
    }

    // =====================================================================
    // 後台編輯頁「立即同步」按鈕
    // =====================================================================

    public function render_sync_button( $field ): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        $post_id = get_the_ID();
        if ( ! $post_id ) {
            global $post;
            $post_id = isset( $post->ID ) ? $post->ID : 0;
        }
        ?>
        <div class="asp-yt-sync-wrap" style="margin-top:10px;">
            <button type="button" class="button button-primary asp-yt-sync-btn"
                    data-post-id="<?php echo esc_attr( $post_id ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'asp_yt_sync' ) ); ?>">
                ▶️ 立即同步 YouTube 播放清單
            </button>
            <span class="asp-yt-sync-status" style="margin-left:10px;color:#666;"></span>
        </div>
        <?php
    }

    public function enqueue_admin_scripts( $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        if ( get_post_type() !== 'anime' ) {
            return;
        }
        $script = "
        jQuery(document).on('click', '.asp-yt-sync-btn', function(e){
            e.preventDefault();
            var \$btn = jQuery(this);
            var \$status = \$btn.siblings('.asp-yt-sync-status');
            var postId = \$btn.data('post-id');
            var nonce = \$btn.data('nonce');

            var playlistUrl = jQuery('input[name=\"acf[field_anime_yt_playlist_url]\"]').val();
            if (typeof playlistUrl === 'undefined' || playlistUrl === null) {
                playlistUrl = jQuery('.acf-field[data-name=\"anime_yt_playlist_url\"] input').val();
            }
            playlistUrl = playlistUrl || '';

            if (!playlistUrl) {
                \$status.css('color','#d63638').text('⚠️ 請先填入 YouTube 播放清單網址');
                return;
            }

            \$btn.prop('disabled', true);
            \$status.css('color','#666').text('⏳ 同步中,請稍候...');

            jQuery.post(ajaxurl, {
                action: 'asp_yt_sync_single',
                post_id: postId,
                nonce: nonce,
                playlist_url: playlistUrl
            }, function(res){
                if (res.success) {
                    var d = res.data || {};
                    \$status.css('color','#00a32a').text('✅ ' + (d.msg || '同步完成') + '(即將自動重新整理)');
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    \$status.css('color','#d63638').text('❌ ' + (res.data ? res.data : '同步失敗'));
                    \$btn.prop('disabled', false);
                }
            }).fail(function(){
                \$status.css('color','#d63638').text('❌ 網路錯誤,請稍後再試');
                \$btn.prop('disabled', false);
            });
        });
        ";
        wp_add_inline_script( 'jquery', $script );
    }

    // =====================================================================
    // 存檔觸發 → 背景抓一次
    // =====================================================================

    public function on_save_schedule_sync( int $post_id, $post, $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( get_post_type( $post_id ) !== 'anime' ) {
            return;
        }
        if ( get_post_status( $post_id ) !== 'publish' ) {
            return;
        }

        $playlist_url = (string) get_post_meta( $post_id, 'anime_yt_playlist_url', true );
        if ( trim( $playlist_url ) === '' ) {
            return;
        }

        $enabled = get_post_meta( $post_id, 'anime_yt_sync_enabled', true );
        if ( $enabled === '0' ) {
            return;
        }

        if ( ! defined( 'SMACG_YT_API_KEY' ) || ! SMACG_YT_API_KEY ) {
            return;
        }

        if ( ! wp_next_scheduled( 'asp_yt_sync_single_bg', [ $post_id ] ) ) {
            wp_schedule_single_event( time() + 5, 'asp_yt_sync_single_bg', [ $post_id ] ) ;
        }
    }

    public function run_single_bg( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        if ( ! defined( 'SMACG_YT_API_KEY' ) || ! SMACG_YT_API_KEY ) {
            return;
        }
        // 背景任務走自動流程(尊重開關)
        $this->sync_post( $post_id );
    }

    // =====================================================================
    // 填網址即自動排程同步
    // =====================================================================

    public function maybe_auto_sync_on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
        if ( $meta_key !== 'anime_yt_playlist_url' ) {
            return;
        }
        if ( get_post_type( $post_id ) !== 'anime' ) {
            return;
        }
        if ( ! defined( 'SMACG_YT_API_KEY' ) || ! SMACG_YT_API_KEY ) {
            return;
        }

        $enabled = get_post_meta( $post_id, 'anime_yt_sync_enabled', true );
        if ( $enabled === '0' ) {
            return;
        }

        $url = is_string( $meta_value ) ? trim( $meta_value ) : '';
        if ( $url === '' ) {
            return;
        }
        if ( $this->extract_playlist_id( $url ) === '' ) {
            return;
        }

        $last_synced_url = (string) get_post_meta( $post_id, '_anime_yt_last_synced_url', true );
        if ( $last_synced_url === $url ) {
            return;
        }

        if ( ! wp_next_scheduled( 'asp_yt_sync_single_bg', [ $post_id ] ) ) {
            wp_schedule_single_event( time() + self::AUTO_SYNC_DELAY, 'asp_yt_sync_single_bg', [ $post_id ] );
        }
    }

    // =====================================================================
    // 排程主流程
    // =====================================================================
    public function run_scheduled_sync(): void {
        if ( ! defined( 'SMACG_YT_API_KEY' ) || ! SMACG_YT_API_KEY ) {
            return;
        }

        $query = new WP_Query( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => 'anime_yt_playlist_url', 'value' => '', 'compare' => '!=' ],
                // v1.7.2: 只同步「明確啟用(=1)」的文章;移除 NOT EXISTS,避免未設定/關閉的被誤抓
                [ 'key' => 'anime_yt_sync_enabled', 'value' => '1', 'compare' => '=' ],
            ],
        ] );

        if ( empty( $query->posts ) ) {
            return;
        }

        foreach ( $query->posts as $post_id ) {
            if ( $this->should_sync_now( $post_id ) ) {
                $this->sync_post( $post_id );
                usleep( 300000 );
            }
        }
    }

    private function should_sync_now( int $post_id ): bool {
        $status    = (string) get_post_meta( $post_id, 'anime_status', true );
        $last_sync = (int) get_post_meta( $post_id, 'anime_yt_last_sync_ts', true );

        if ( in_array( $status, [ 'FINISHED', 'CANCELLED' ], true ) ) {
            return $last_sync === 0;
        }

        $next_airing = (string) get_post_meta( $post_id, 'anime_next_airing', true );
        $now         = current_time( 'timestamp' );

        if ( $next_airing !== '' ) {
            $air_ts = strtotime( $next_airing );
            if ( $air_ts ) {
                if ( $now >= $air_ts && $now <= $air_ts + self::ACTIVE_WINDOW_HOURS * HOUR_IN_SECONDS ) {
                    return true;
                }
                if ( $now < $air_ts ) {
                    return false;
                }
            }
        }

        return ( $now - $last_sync ) >= self::FALLBACK_INTERVAL;
    }

    // =====================================================================
    // 單篇同步核心(過 PV 黑名單後全部收,去重用 video_id,特殊集用短標籤)
    //
    // $force = true 時(手動按鈕),無視同步開關強制執行。
    // $force = false 時(cron/存檔/填網址),開關關閉(=0)則完全略過。
    // =====================================================================
    public function sync_post( int $post_id, bool $force = false ): array {
        $result = [ 'added' => 0, 'skipped' => 0, 'msg' => '' ];

        // ── [自動清理舊資料機制] ──
        $status = get_post_meta( $post_id, 'anime_status', true );
        $auto_closed = false;
        if ( $status === 'FINISHED' ) {
            $enabled = get_post_meta( $post_id, 'anime_yt_sync_enabled', true );
            if ( $enabled !== '0' ) {
                update_post_meta( $post_id, 'anime_yt_sync_enabled', '0' );
                $auto_closed = true;
                if ( ! $force ) {
                    $result['msg'] = '發現狀態為已完結，自動關閉排程，終止同步';
                    $this->update_post_log( $post_id, $result['msg'] );
                    return $result;
                }
            }
        }

        // 總閘門:非手動強制時,開關關閉(=0)則完全不同步
        if ( ! $force ) {
            $enabled = get_post_meta( $post_id, 'anime_yt_sync_enabled', true );
            if ( $enabled === '0' ) {
                $result['msg'] = '已關閉自動同步,略過';
                $this->update_post_log( $post_id, $result['msg'] );
                return $result;
            }
        }

        $locked = (array) get_post_meta( $post_id, 'anime_locked_fields', true );
        if ( in_array( 'anime_online_watch', $locked, true ) ) {
            $result['msg'] = '已鎖定 anime_online_watch,略過';
            $this->update_post_log( $post_id, $result['msg'] );
            return $result;
        }

        $playlist_url = (string) get_post_meta( $post_id, 'anime_yt_playlist_url', true );
        $playlist_id  = $this->extract_playlist_id( $playlist_url );
        if ( ! $playlist_id ) {
            $result['msg'] = '播放清單網址無效';

            /*
             * 網址無效屬於「資料本身有問題」，不修好就每一輪都會再發生。
             * 原本每輪都記一筆 warning，同一筆壞資料一天可洗掉數十行 log，
             * 把真正需要處理的警告淹沒。
             *
             * 改為只在「這個壞網址第一次被發現」時記錄：
             * 已回報過的網址存進 post meta，相同值就不再重複記。
             * 網址一被修改（不論改好或改成另一個壞值）就會與紀錄不同，
             * 屆時再記一次，因此不會漏掉新的問題。
             */
            $reported = (string) get_post_meta( $post_id, '_asp_yt_bad_url_reported', true );

            if ( $reported !== $playlist_url ) {
                update_post_meta( $post_id, '_asp_yt_bad_url_reported', $playlist_url );
                $this->write_log( $post_id, $result['msg'], 'warning' );
            }

            return $result;
        }

        // 網址有效：清掉「壞網址」紀錄，日後若再變壞才會重新回報。
        if ( '' !== (string) get_post_meta( $post_id, '_asp_yt_bad_url_reported', true ) ) {
            delete_post_meta( $post_id, '_asp_yt_bad_url_reported' );
        }

        $videos = $this->fetch_playlist_items( $playlist_id );
        if ( is_wp_error( $videos ) ) {
            $result['msg'] = 'API 錯誤:' . $videos->get_error_message();
            $this->write_log( $post_id, $result['msg'], 'error' );
            return $result;
        }

        // ── [新增] 自動依據 YouTube 頻道名稱推導台灣代理商 ──
        $channel_name = '';
        foreach ( $videos as $v ) {
            if ( ! empty( $v['channel'] ) ) {
                $channel_name = $v['channel'];
                break;
            }
        }
        if ( $channel_name !== '' ) {
            $this->maybe_fill_distributor_and_streaming( $post_id, $channel_name, $playlist_url );
        }

        $current_raw = (string) get_post_meta( $post_id, 'anime_online_watch', true );
        $lines       = ( $current_raw !== '' ) ? preg_split( '/\r\n|\r|\n/', $current_raw ) : [];

        // 蒐集欄位裡「已存在的 video_id」,去重用
        $existing_ids = [];
        $new_lines = [];
        foreach ( (array) $lines as $l ) {
            if ( preg_match( '#(?:youtu\.be/|[?&]v=|/embed/|/shorts/)([A-Za-z0-9_-]{6,})#', (string) $l, $mm ) ) {
                if ( $force ) {
                    // 手動同步時，直接捨棄舊的 YouTube 連結，準備重新抓取以修正排序
                    continue;
                }
                $existing_ids[ $mm[1] ] = true;
                $new_lines[] = $l;
            } else {
                // 非 YouTube 連結，永遠保留
                $new_lines[] = $l;
            }
        }
        $lines = $new_lines;

        foreach ( $videos as $v ) {
            // 1) 先過 PV/預告/OP/ED/主題曲 黑名單 → 命中就略過
            if ( $this->is_pv_title( $v['title'] ) ) {
                $result['skipped']++;
                continue;
            }

            // 2) 同一支影片已在欄位裡 → 略過(用 video_id 去重,支援多季重複集數)
            if ( isset( $existing_ids[ $v['video_id'] ] ) ) {
                $result['skipped']++;
                continue;
            }

            // 3) 通過黑名單的影片一律收:
            //    有集數 → 「第X話」;無集數 → 簡化短標籤(OVA/OAD/馬拉松…),認不出才用原標題
            $range = $this->extract_episode_range( $v['title'] );
            if ( $range !== null ) {
                [ $lo, $hi ] = $range;
                $label = ( $lo === $hi ) ? sprintf( '第%d話', $lo ) : sprintf( '第%d-%d話', $lo, $hi );
            } else {
                $label = $this->guess_special_label( $v['title'] );
            }

            $lines[] = sprintf( '%s|https://youtu.be/%s', $label, $v['video_id'] );
            $existing_ids[ $v['video_id'] ] = true;
            $result['added']++;
        }

        if ( $result['added'] > 0 ) {
            $new_value = implode( "\n", array_filter( $lines, static function ( $l ) {
                return trim( (string) $l ) !== '';
            } ) );
            update_post_meta( $post_id, 'anime_online_watch', $new_value );
        }

        update_post_meta( $post_id, 'anime_yt_last_sync_ts', current_time( 'timestamp' ) );
        update_post_meta( $post_id, 'anime_yt_last_sync', current_time( 'Y-m-d H:i:s' ) );
        update_post_meta( $post_id, '_anime_yt_last_synced_url', $playlist_url );

        $result['msg'] = sprintf( '新增 %d 支,略過 %d 支(PV/預告/OP/ED/已存在)', $result['added'], $result['skipped'] );
        if ( $auto_closed ) {
            $result['msg'] .= ' [已完結:已為您自動關閉未來排程]';
        }

        if ( $result['added'] > 0 ) {
            $this->write_log( $post_id, $result['msg'], 'info' );
        } else {
            $this->update_post_log( $post_id, $result['msg'] );
        }

        return $result;
    }

    // =====================================================================
    // YouTube API:抓取 playlist 全部影片(自動翻頁)
    // =====================================================================
    private function fetch_playlist_items( string $playlist_id ) {
        $items    = [];
        $page_tok = '';
        $guard    = 0;

        do {
            $guard++;
            if ( $guard > 20 ) {
                break;
            }

            $url = add_query_arg( [
                'part'       => 'snippet',
                'maxResults' => 50,
                'playlistId' => $playlist_id,
                'pageToken'  => $page_tok,
                'key'        => SMACG_YT_API_KEY,
            ], 'https://www.googleapis.com/youtube/v3/playlistItems' );

            $resp = wp_remote_get( $url, [ 'timeout' => 15 ] );
            if ( is_wp_error( $resp ) ) {
                return $resp;
            }

            $code = wp_remote_retrieve_response_code( $resp );
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );

            if ( $code !== 200 ) {
                $err = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
                return new WP_Error( 'yt_api', $err );
            }

            foreach ( (array) ( $body['items'] ?? [] ) as $it ) {
                $snippet  = $it['snippet'] ?? [];
                $video_id = $snippet['resourceId']['videoId'] ?? '';
                $title    = $snippet['title'] ?? '';

                if ( $video_id === '' || $title === 'Private video' || $title === 'Deleted video' ) {
                    continue;
                }
                
                $channel = $snippet['videoOwnerChannelTitle'] ?? $snippet['channelTitle'] ?? '';
                $items[] = [ 'video_id' => $video_id, 'title' => $title, 'channel' => $channel ];
            }

            $page_tok = $body['nextPageToken'] ?? '';
        } while ( $page_tok !== '' );

        return $items;
    }

    // =====================================================================
    // 工具方法
    // =====================================================================

    private function extract_playlist_id( string $url ): string {
        if ( preg_match( '/[?&]list=([A-Za-z0-9_-]+)/', $url, $m ) ) {
            return $m[1];
        }
        if ( preg_match( '/^(PL|UU|FL|OL|RD)[A-Za-z0-9_-]+$/', trim( $url ) ) ) {
            return trim( $url );
        }
        return '';
    }

    private function normalize_fullwidth_numbers( string $str ): string {
        $full = ['０','１','２','３','４','５','６','７','８','９'];
        $half = ['0','1','2','3','4','5','6','7','8','9'];
        return str_replace( $full, $half, $str );
    }

    private function extract_episode_number( string $title ): ?int {
        $t = trim( $title );
        $t = $this->normalize_fullwidth_numbers( $t );

        if ( preg_match( '/第\s*0*(\d{1,4})\s*[話话集回幕]/u', $t, $m ) ) {
            return (int) $m[1];
        }
        if ( preg_match( '/[#＃]\s*0*(\d{1,4})/u', $t, $m ) ) {
            return (int) $m[1];
        }
        if ( preg_match( '/\bEP(?:isode)?\.?\s*0*(\d{1,4})\b/i', $t, $m ) ) {
            return (int) $m[1];
        }
        return null;
    }

    private function extract_episode_range( string $title ): ?array {
        $t = trim( $title );
        $t = $this->normalize_fullwidth_numbers( $t );

        if ( preg_match( '/第\s*0*(\d{1,4})\s*[-~〜～]\s*0*(\d{1,4})\s*[話话集回幕]/u', $t, $m ) ) {
            $lo = (int) $m[1];
            $hi = (int) $m[2];
            return [ min( $lo, $hi ), max( $lo, $hi ) ];
        }

        if ( preg_match_all( '/第\s*0*(\d{1,4})\s*[話话集回幕]/u', $t, $ms ) && ! empty( $ms[1] ) ) {
            $nums = array_map( 'intval', $ms[1] );
            return [ min( $nums ), max( $nums ) ];
        }

        $single = $this->extract_episode_number( $t );
        if ( $single !== null ) {
            return [ $single, $single ];
        }

        return null;
    }

    private function is_pv_title( string $title ): bool {
        // 獨立判斷 OP / ED，前後不可有英文字母 (避免英文字母裡剛好包含 ed/op 導致誤判，例如 Need)
        if ( preg_match( '/(?<![a-zA-Z])(?:OP|ED)(?![a-zA-Z])/i', $title ) ) {
            return true;
        }

        $lower = mb_strtolower( $title );
        foreach ( self::PV_KEYWORDS as $kw ) {
            if ( mb_stripos( $lower, mb_strtolower( $kw ) ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * v1.7.1:把「沒有集數」的特殊影片標題,簡化成短標籤。
     * 依關鍵字判斷類型(OAD / OVA / 馬拉松 / 總集篇 / 特別篇 / 番外 / 前導),
     * 都認不出時,退回原標題(至少不漏掉)。
     * 注意:OAD 要在 OVA 之前判斷。
     */
    private function guess_special_label( string $title ): string {
        $t = mb_strtolower( $title );

        $map = [
            'OAD'    => [ 'oad' ],
            'OVA'    => [ 'ova' ],
            '馬拉松' => [ '馬拉松', '马拉松', 'marathon', '全集' ],
            '總集篇' => [ '總集篇', '总集篇', '總集編', 'recap' ],
            '特別篇' => [ '特別篇', '特别篇', 'special', 'sp' ],
            '番外篇' => [ '番外', 'extra' ],
            '前導'   => [ '前導', '前导' ],
        ];

        foreach ( $map as $label => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( mb_stripos( $t, mb_strtolower( $kw ) ) !== false ) {
                    return $label;
                }
            }
        }

        return $title; // 認不出 → 保留原標題
    }

    private function parse_existing_episodes( string $raw ): array {
        $eps   = [];
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        foreach ( (array) $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $title_part = $line;
            if ( strpos( $line, '|' ) !== false ) {
                $parts      = explode( '|', $line, 2 );
                $title_part = $parts[0];
            }
            $range = $this->extract_episode_range( $title_part );
            if ( $range !== null ) {
                [ $lo, $hi ] = $range;
                for ( $e = $lo; $e <= $hi; $e++ ) {
                    $eps[ $e ] = true;
                }
            }
        }
        return $eps;
    }

    private function update_post_log( int $post_id, string $msg ): void {
        $old   = (string) get_post_meta( $post_id, 'anime_yt_sync_log', true );
        $lines = $old !== '' ? explode( "\n", $old ) : [];
        array_unshift( $lines, current_time( 'Y-m-d H:i' ) . ' — ' . $msg );
        $lines = array_slice( $lines, 0, 5 );
        update_post_meta( $post_id, 'anime_yt_sync_log', implode( "\n", $lines ) );
    }

    private function write_log( int $post_id, string $msg, string $level = 'info' ): void {
        $this->update_post_log( $post_id, $msg );

        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            $title   = get_the_title( $post_id );
            $full    = sprintf( '[YouTube同步] %s(#%d):%s', $title, $post_id, $msg );
            $context = [
                'post_id' => $post_id,
                'title'   => $title,
                'source'  => 'youtube_playlist_sync',
            ];

            if ( $level === 'error' ) {
                Anime_Sync_Error_Logger::error( $full, $context );
            } elseif ( $level === 'warning' ) {
                Anime_Sync_Error_Logger::warning( $full, $context );
            } else {
                Anime_Sync_Error_Logger::info( $full, $context );
            }
        }
    }

    // =====================================================================
    // AJAX:後台手動同步單篇(強制執行,無視開關)
    // =====================================================================
    public function ajax_sync_single(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '權限不足' );
        }
        check_ajax_referer( 'asp_yt_sync', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id ) {
            wp_send_json_error( '缺少 post_id' );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( '權限不足(無法編輯此文章)' );
        }
        if ( ! defined( 'SMACG_YT_API_KEY' ) || ! SMACG_YT_API_KEY ) {
            wp_send_json_error( '尚未設定 SMACG_YT_API_KEY' );
        }

        if ( isset( $_POST['playlist_url'] ) ) {
            $playlist_url = esc_url_raw( trim( wp_unslash( $_POST['playlist_url'] ) ) );
            if ( $playlist_url !== '' ) {
                update_post_meta( $post_id, 'anime_yt_playlist_url', $playlist_url );
            }
        }

        // 手動按鈕 → 強制執行($force=true),無視同步開關
        $res = $this->sync_post( $post_id, true );
        wp_send_json_success( $res );
    }

    /**
     * 自動依據 YouTube 頻道名稱推導台灣代理商與串流平台 (僅在空白時填寫)
     */
    private function maybe_fill_distributor_and_streaming( int $post_id, string $channel_name, string $playlist_url ): void {
        $youtube_map = [];
        if ( class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
            $youtube_map = Anime_Sync_Streaming_Registry::get_youtube_keyword_map();
        }
        
        $matched_platform = '';
        foreach ( $youtube_map as $acf_key => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( mb_stripos( $channel_name, $kw ) !== false ) {
                    $matched_platform = $acf_key;
                    break 2;
                }
            }
        }

        // 如果找不到對應代理商平台，預設降級為一般的 youtube 平台
        $target_platform = $matched_platform ? $matched_platform : 'youtube';

        // 1. 自動推導代理商 (只有比對到特定平台才做)
        if ( $matched_platform ) {
            $current_distributor = get_post_meta( $post_id, 'anime_tw_distributor', true );
            if ( empty( $current_distributor ) ) {
                $map = [
                    'muse'         => 'muse',
                    'ani_one'      => 'linbang',
                    'mighty'       => 'medialink',
                    'tropicsanime' => 'tropic',
                    'anipass'      => 'garage',
                    'garageplay'   => 'garage',
                ];
                if ( isset( $map[ $matched_platform ] ) ) {
                    $distributor = $map[ $matched_platform ];
                    if ( function_exists( 'update_field' ) ) {
                        update_field( 'field_anime_tw_distributor', $distributor, $post_id );
                    } else {
                        update_post_meta( $post_id, 'anime_tw_distributor', $distributor );
                    }
                    $this->write_log( $post_id, "自動代理商：偵測到 YouTube 頻道 {$channel_name} → 對應代理商 {$distributor}", 'info' );
                }
            }
        }

        // 2. 自動打勾對應串流平台 (防呆：沒勾才勾)
        $checked_streams = get_post_meta( $post_id, 'anime_tw_streaming', true );
        if ( ! is_array( $checked_streams ) ) {
            $checked_streams = [];
        }
        if ( ! in_array( $target_platform, $checked_streams, true ) ) {
            $checked_streams[] = $target_platform;
            if ( function_exists( 'update_field' ) ) {
                update_field( 'field_anime_tw_streaming', $checked_streams, $post_id );
            } else {
                update_post_meta( $post_id, 'anime_tw_streaming', $checked_streams );
            }
            $this->write_log( $post_id, "自動串流：打勾 {$target_platform}", 'info' );
        }

        // 3. 自動填入網址 (防呆：格子空白才填)
        $stream_url_meta = 'anime_tw_streaming_url_' . $target_platform;
        $stream_url_field = 'field_anime_tw_streaming_url_' . $target_platform;
        $current_stream_url = get_post_meta( $post_id, $stream_url_meta, true );
        if ( empty( $current_stream_url ) ) {
            if ( function_exists( 'update_field' ) ) {
                update_field( $stream_url_field, $playlist_url, $post_id );
            } else {
                update_post_meta( $post_id, $stream_url_meta, $playlist_url );
            }
            $this->write_log( $post_id, "自動串流：填入 {$target_platform} 網址", 'info' );
        }
    }
}
