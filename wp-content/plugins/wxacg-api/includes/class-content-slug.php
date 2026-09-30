<?php
/**
 * 文章 Slug 處理：Gemini AI 翻譯 + 中文防呆
 *
 * 行為與 blocksy-child v2.7.3 inc/content-slug.php 一致。
 *
 * 相依常數（仍由 blocksy-child/functions.php 或 wp-config.php 定義）：
 *   - WEIXIAOACG_GEMINI_API_KEY  Gemini API key
 *   - WEIXIAOACG_ID_CATS         使用日期 slug 的 category slug 陣列
 *   - WEIXIAOACG_LLM_CATS        使用 Gemini 翻譯的 category slug 陣列
 *
 * @package WxacgApi
 */

defined( 'ABSPATH' ) || exit;

class Wxacg_Api_Content_Slug {

    private const TRANSIENT_TTL      = DAY_IN_SECONDS;   // 成功結果快取 1 天
    private const TRANSIENT_TTL_FAIL = 5 * MINUTE_IN_SECONDS; // 失敗結果短快取，避免 API 掛掉時反覆重打
    private const GEMINI_MODEL       = 'gemini-2.0-flash';

    public function register_hooks(): void {
        add_filter( 'wp_insert_post_data', [ $this, 'filter_insert_post_data' ], 10, 2 );
        add_filter( 'wp_unique_post_slug', [ $this, 'filter_unique_post_slug' ], 10, 6 );
        add_action( 'admin_notices',       [ $this, 'admin_notice_chinese_slug' ] );
    }

    /* ──────────────────────────────────────────────
     * Gemini API 呼叫
     * ────────────────────────────────────────────── */
    public function gemini_slug( string $title ) {
        if ( $title === '' ) {
            return false;
        }

        $cache_key = 'wxacg_api_slug_' . md5( $title );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            // 失敗結果以特殊標記快取（避免與「空字串」混淆）
            return ( $cached === '__FAIL__' ) ? false : $cached;
        }

        $api_key = defined( 'WEIXIAOACG_GEMINI_API_KEY' ) ? WEIXIAOACG_GEMINI_API_KEY : '';
        if ( $api_key === '' ) {
            error_log( '[wxacg-api] Gemini API Key 未設定' );
            return false;
        }

        // ★ 修正：endpoint 不再夾帶 API key（避免金鑰寫進 server/CDN log）
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
                  . self::GEMINI_MODEL
                  . ':generateContent';

        $prompt = "Translate this Chinese article title into a short, SEO-friendly English URL slug "
                . "(lowercase, words separated by hyphens, no special characters, max 60 chars). "
                . "Only output the slug itself without any explanation.\n\nTitle: {$title}";

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'    => 'application/json',
                // ★ 修正：API key 改放 header
                'x-goog-api-key'  => $api_key,
            ],
            'body'    => wp_json_encode( [
                'contents' => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
                'generationConfig' => [
                    'temperature'     => 0.2,
                    'maxOutputTokens' => 60,
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[wxacg-api] Gemini API 錯誤：' . $response->get_error_message() );
            set_transient( $cache_key, '__FAIL__', self::TRANSIENT_TTL_FAIL );
            return false;
        }

        // ★ 修正：檢查 HTTP 狀態碼（4xx/5xx 不會被 is_wp_error 攔到）
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            error_log( '[wxacg-api] Gemini API 回應狀態碼異常：' . $code
                     . '，body：' . wp_remote_retrieve_body( $response ) );
            set_transient( $cache_key, '__FAIL__', self::TRANSIENT_TTL_FAIL );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $slug = trim( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' );
        $slug = sanitize_title( $slug );

        if ( $slug === '' ) {
            set_transient( $cache_key, '__FAIL__', self::TRANSIENT_TTL_FAIL );
            return false;
        }

        set_transient( $cache_key, $slug, self::TRANSIENT_TTL );
        return $slug;
    }

    /* ──────────────────────────────────────────────
     * 插入/更新文章時處理 slug
     * ────────────────────────────────────────────── */
    public function filter_insert_post_data( array $data, array $postarr ): array {
        if ( $data['post_type'] !== 'post' ) {
            return $data;
        }
        if ( in_array( $data['post_status'], [ 'auto-draft', 'inherit' ], true ) ) {
            return $data;
        }

        // 取得 category slugs
        $post_id = $postarr['ID'] ?? 0;
        $cats    = [];

        if ( $post_id ) {
            foreach ( wp_get_post_categories( $post_id ) as $tid ) {
                $term = get_term( $tid, 'category' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $cats[] = $term->slug;
                }
            }
        }
        if ( empty( $cats ) && ! empty( $postarr['post_category'] ) ) {
            foreach ( (array) $postarr['post_category'] as $tid ) {
                $term = get_term( $tid, 'category' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $cats[] = $term->slug;
                }
            }
        }

        $id_cats  = defined( 'WEIXIAOACG_ID_CATS' )  ? (array) WEIXIAOACG_ID_CATS  : [];
        $llm_cats = defined( 'WEIXIAOACG_LLM_CATS' ) ? (array) WEIXIAOACG_LLM_CATS : [];

        // ID 系列分類 → 日期 slug
        if ( array_intersect( $cats, $id_cats ) ) {
            /*
             * 日期 slug 只在「現有的還不是日期格式」時才產生。
             *
             *   這個方法掛在 wp_insert_post_data，而那個 filter 在「更新」文章
             *   時同樣會跑。若無條件覆寫 post_name，等於每存一次檔就換一次
             *   網址——改標題、修錯字、補圖、透過 API 改內容都會觸發，舊網址
             *   只能靠 WordPress 內建的 wp_old_slug_redirect 撐著 301；而
             *   Rank Math 的 redirections_post_redirect 一旦開啟就會移除該
             *   機制，屆時所有累積的舊網址會同時 404。
             *
             *   新文章（$post_id 為 0）照樣產生；分類後來才改成新聞、slug 還
             *   不是日期格式的也會補上——只有「已經是日期 slug」保持原值。
             *
             *   ⚠ 已有日期 slug 時要「明確寫回原值」，不能只是不動 $data。
             *   核心在 wp_insert_post() 裡有一段：狀態為 pending 且當下使用者
             *   對該篇沒有 publish_post 權限時（沒有登入使用者的 cron／CLI
             *   也算），會先把 $post_name 清成空字串；而寫入後那段補救
             *   （if empty post_name…）刻意排除 pending，不會補回來。
             *   此時若這裡不寫值，空字串就會被寫進資料庫、網址直接壞掉。
             *
             *   讀資料庫而不是看 $data['post_name']：$data 的值取決於呼叫端
             *   傳了什麼。用 'raw' 情境取值，跳過 sanitize_post_field() 的
             *   display 處理，日後有人掛 post_name filter 也不會讓判斷失準。
             *   注意這裡拿到的「更新前的值」有一個例外：把文章丟進垃圾桶時，
             *   核心會在本 filter 之前就把 __trashed 後綴寫進資料庫並清快取，
             *   所以 trash 那一次讀到的是帶後綴的值、不會符合日期格式。
             */
            $existing = $post_id > 0 ? (string) get_post_field( 'post_name', $post_id, 'raw' ) : '';

            if ( preg_match( '/^\d{8}-\d{6}-\d{3}$/', $existing ) ) {
                $data['post_name'] = $existing;
            } else {
                // ★ 修正：date() → current_time()，跟隨 WordPress 站台時區
                $data['post_name'] = current_time( 'Ymd-His' ) . '-' . wp_rand( 100, 999 );
            }

            return $data;
        }

        // LLM 系列分類 → Gemini 翻譯
        $title       = $data['post_title'];
        $slug        = $data['post_name'];
        $need_gemini = (bool) array_intersect( $cats, $llm_cats );

        // 強制條件：slug 含中文也送 Gemini
        if ( ! $need_gemini && preg_match( '/[\x{4e00}-\x{9fff}]/u', $slug ) ) {
            $need_gemini = true;
        }

        if ( $need_gemini ) {
            $new_slug = $this->gemini_slug( $title );
            if ( $new_slug ) {
                $data['post_name'] = $new_slug;
            }
        }

        return $data;
    }

    /* ──────────────────────────────────────────────
     * 中文 slug 兜底
     * ────────────────────────────────────────────── */
    public function filter_unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
        if ( $post_type !== 'post' ) {
            return $slug;
        }
        if ( preg_match( '/[\x{4e00}-\x{9fff}]/u', $slug ) ) {
            return 'post-' . $post_id;
        }
        return $slug;
    }

    /* ──────────────────────────────────────────────
     * Admin Notice
     * ────────────────────────────────────────────── */
    public function admin_notice_chinese_slug(): void {
        global $post;
        if ( ! $post || $post->post_type !== 'post' ) {
            return;
        }
        if ( ! preg_match( '/[\x{4e00}-\x{9fff}]/u', $post->post_name ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>⚠️ 警告：</strong>'
           . '此文章 slug 仍含中文（<code>' . esc_html( $post->post_name ) . '</code>），'
           . '請檢查分類設定或 Gemini API。</p></div>';
    }
}
