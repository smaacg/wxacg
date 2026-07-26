<?php
/**
 * Image Handler
 *
 * 處理 anime 封面圖片的下載、resize、CDN 與 API URL 模式。
 *
 * @package Anime_Sync_Pro
 * @version 1.1.1
 *
 * Changelog:
 *   1.1.1 (本版)
 *     - [Fix] build_cdn_url() cloudflare 分支對來源 URL 做 rawurlencode，
 *             與 imgproxy 分支一致；避免來源 URL 含 query/特殊字元時
 *             破壞 /cdn-cgi/image/ 路徑解析造成圖片 404
 *   1.1.0
 *     - H4: validate_url() timeout 8 → 5 秒，加 redirection 限制
 *     - H5: resize_image() 改 atomic write（.tmp + rename），失敗保留原檔
 *     - L3: 所有 error_log() 改用 Anime_Sync_Error_Logger，後台日誌頁可見
 *     - O1: build_cdn_url() 加 base_url 空值檢查，fallback 回原始 URL
 *     - O4: download_url() timeout 15 → 8 秒
 *     - 新增：下載 AniList 圖片時設定 User-Agent，避免未來被 hotlink 擋
 *     - 新增：resize 失敗時保留原檔（fallback 選項 A），前台 CSS aspect-ratio 救援
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Image_Handler {

    const COVER_WIDTH      = 460;
    const COVER_HEIGHT     = 651;
    const VALIDATE_TIMEOUT = 5;   // H4: 8 → 5
    const DOWNLOAD_TIMEOUT = 8;   // O4: 15 → 8
    const HTTP_USER_AGENT  = 'Mozilla/5.0 (compatible; AnimeSyncPro/1.1; +https://anime-sync-pro)';

    /**
     * 主入口：根據設定決定處理模式
     *
     * @param int    $post_id  anime post ID
     * @param string $url      AniList 原始封面 URL
     * @return bool            處理是否成功
     */
    public function handle_cover( int $post_id, string $url ): bool {
        if ( empty( $url ) ) {
            return false;
        }

        if ( ! $this->validate_url( $url ) ) {
            $this->log_warning( 'Cover URL validation failed', [
                'post_id' => $post_id,
                'url'     => $url,
            ] );
            return false;
        }

        $method = get_option( 'anime_sync_image_method', 'media_library' );

        switch ( $method ) {
            case 'cdn':
                return $this->handle_cdn( $post_id, $url );
            case 'api_url':
                return $this->handle_api_url( $post_id, $url );
            case 'media_library':
            default:
                return $this->handle_media_library( $post_id, $url );
        }
    }

    /**
     * 驗證 URL 是否可存取且為圖片
     */
    private function validate_url( string $url ): bool {
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }

        $response = wp_remote_head( $url, [
            'timeout'     => self::VALIDATE_TIMEOUT,  // H4
            'redirection' => 2,                       // H4: 限制重導
            'sslverify'   => false,
            'user-agent'  => self::HTTP_USER_AGENT,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return false;
        }

        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( strpos( (string) $content_type, 'image/' ) === false ) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // 模式 1：API URL（不下載，記原始網址）
    // =========================================================================

    private function handle_api_url( int $post_id, string $url ): bool {
        update_post_meta( $post_id, 'anime_cover_image_external', $url );
        update_post_meta( $post_id, 'anime_cover_image', $url );

        $this->log_info( 'Cover stored as external URL', [
            'post_id' => $post_id,
            'url'     => $url,
        ] );
        return true;
    }

    // =========================================================================
    // 模式 2：媒體庫（下載 + resize）
    // =========================================================================

    private function handle_media_library( int $post_id, string $url ): bool {
        $attachment_id = $this->download_and_upload( $post_id, $url );
        if ( ! $attachment_id ) {
            return false;
        }

        $this->resize_image( $attachment_id );

        $attachment_url = wp_get_attachment_url( $attachment_id );
        if ( $attachment_url ) {
            update_post_meta( $post_id, 'anime_cover_image', $attachment_url );
        }

        return true;
    }

    /**
     * 下載圖片到媒體庫並設為特色圖片
     */
    private function download_and_upload( int $post_id, string $url ): int {
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // 為這次下載設定 AniList-friendly User-Agent
        $ua_filter = function( $args, $request_url ) use ( $url ) {
            if ( $request_url === $url ) {
                $args['user-agent'] = self::HTTP_USER_AGENT;
            }
            return $args;
        };
        add_filter( 'http_request_args', $ua_filter, 10, 2 );

        $tmp = download_url( $url, self::DOWNLOAD_TIMEOUT );  // O4

        remove_filter( 'http_request_args', $ua_filter, 10 );

        if ( is_wp_error( $tmp ) ) {
            $this->log_warning( 'Image download failed', [
                'post_id' => $post_id,
                'url'     => $url,
                'error'   => $tmp->get_error_message(),
            ] );
            return 0;
        }

        $file_array = [
            'name'     => $this->sanitize_filename( $url ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            $this->log_warning( 'media_handle_sideload failed', [
                'post_id' => $post_id,
                'url'     => $url,
                'error'   => $attachment_id->get_error_message(),
            ] );
            return 0;
        }

        // 設為特色圖片
        set_post_thumbnail( $post_id, $attachment_id );

        // 設定 alt text（用 anime 中文標題）
        $title = get_post_meta( $post_id, 'anime_title_chinese', true );
        if ( ! empty( $title ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title . ' 封面' );
        }

        update_post_meta( $post_id, 'anime_cover_attachment_id', $attachment_id );

        $this->log_info( 'Image downloaded to media library', [
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
        ] );

        return (int) $attachment_id;
    }

    /**
     * Resize 圖片到指定尺寸（atomic write）
     *
     * H5 修正：
     *   - 原本直接覆蓋原檔，失敗會毀圖
     *   - 改為先寫到 .tmp，rename 取代原檔
     *   - 失敗則保留原檔（前台 CSS aspect-ratio 救援）
     */
    private function resize_image( int $attachment_id ): bool {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            $this->log_warning( 'Resize: source file not found', [
                'attachment_id' => $attachment_id,
            ] );
            return false;
        }

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            $this->log_warning( 'Resize: image editor init failed', [
                'attachment_id' => $attachment_id,
                'error'         => $editor->get_error_message(),
            ] );
            return false;
        }

        $resize_result = $editor->resize( self::COVER_WIDTH, self::COVER_HEIGHT, true );
        if ( is_wp_error( $resize_result ) ) {
            $this->log_warning( 'Resize: resize() failed (keeping original)', [
                'attachment_id' => $attachment_id,
                'error'         => $resize_result->get_error_message(),
            ] );
            return false;  // 保留原檔
        }

        // 原子寫入：先寫 .tmp
        $tmp_file = $file . '.tmp';
        $save_result = $editor->save( $tmp_file );

        if ( is_wp_error( $save_result ) ) {
            @unlink( $tmp_file );
            $this->log_warning( 'Resize: save to tmp failed (keeping original)', [
                'attachment_id' => $attachment_id,
                'error'         => $save_result->get_error_message(),
            ] );
            return false;
        }

        // tmp 寫入成功，rename 覆蓋原檔（OS 原子操作）
        if ( ! @rename( $tmp_file, $file ) ) {
            @unlink( $tmp_file );
            $this->log_warning( 'Resize: rename failed (keeping original)', [
                'attachment_id' => $attachment_id,
                'tmp'           => $tmp_file,
                'target'        => $file,
            ] );
            return false;
        }

        // 清理舊的 intermediate sizes（避免孤兒檔）
        $old_meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $old_meta['sizes'] ) ) {
            $dir = trailingslashit( dirname( $file ) );
            foreach ( $old_meta['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    @unlink( $dir . $size['file'] );
                }
            }
        }

        // 重新產生 metadata（含所有 intermediate sizes）
        $new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
        if ( ! empty( $new_meta ) ) {
            wp_update_attachment_metadata( $attachment_id, $new_meta );
        } else {
            $this->log_warning( 'Resize: metadata regeneration returned empty', [
                'attachment_id' => $attachment_id,
            ] );
        }

        clean_post_cache( $attachment_id );
        wp_cache_delete( $attachment_id, 'posts' );

        $this->log_info( 'Image resized successfully', [
            'attachment_id' => $attachment_id,
            'size'          => self::COVER_WIDTH . 'x' . self::COVER_HEIGHT,
        ] );

        return true;
    }

    // =========================================================================
    // 模式 3：CDN
    // =========================================================================

    private function handle_cdn( int $post_id, string $url ): bool {
        $cdn_url = $this->build_cdn_url( $url );

        update_post_meta( $post_id, 'anime_cover_image_cdn', $cdn_url );
        update_post_meta( $post_id, 'anime_cover_image', $cdn_url );

        $this->log_info( 'Cover stored as CDN URL', [
            'post_id' => $post_id,
            'cdn_url' => $cdn_url,
        ] );
        return true;
    }

    /**
     * 組 CDN URL
     *
     * O1 修正：base_url 為空時 fallback 回原始 URL
     * 1.1.1 修正：cloudflare 分支對來源 URL 編碼，與 imgproxy 分支一致
     */
    private function build_cdn_url( string $original_url ): string {
        $provider = get_option( 'anime_sync_cdn_provider', 'cloudflare' );
        $base_url = trim( (string) get_option( 'anime_sync_cdn_base_url', '' ) );

        // O1: 沒設定 base_url 就用原始 URL
        if ( empty( $base_url ) ) {
            $this->log_warning( 'CDN base_url not configured, falling back to original URL', [
                'provider' => $provider,
            ] );
            return $original_url;
        }

        $base_url = rtrim( $base_url, '/' );

        switch ( $provider ) {
            case 'imgproxy':
                // 範例：https://imgproxy.example.com/resize:fill:460:651/plain/<original>
                return $base_url
                    . '/resize:fill:' . self::COVER_WIDTH . ':' . self::COVER_HEIGHT
                    . '/plain/' . rawurlencode( $original_url );

            case 'cloudflare':
            default:
                // Cloudflare Image Resizing：/cdn-cgi/image/...
                // [1.1.1 Fix] 來源 URL 編碼，避免含 query/特殊字元時破壞路徑解析
                return $base_url
                    . '/cdn-cgi/image/width=' . self::COVER_WIDTH
                    . ',height=' . self::COVER_HEIGHT
                    . ',fit=cover/' . rawurlencode( $original_url );
        }
    }

    // =========================================================================
    // 清理工具
    // =========================================================================

    /**
     * 清理 anime-covers 子目錄裡的孤兒檔案
     *
     * @param bool $dry_run true 只列不刪
     * @return array        [ 'deleted' => int, 'kept' => int, 'files' => string[] ]
     */
    public function cleanup_orphan_covers( bool $dry_run = true ): array {
        $upload_dir = wp_upload_dir();
        $covers_dir = trailingslashit( $upload_dir['basedir'] ) . 'anime-covers';

        $stats = [ 'deleted' => 0, 'kept' => 0, 'files' => [] ];

        if ( ! is_dir( $covers_dir ) ) {
            return $stats;
        }

        $files = glob( $covers_dir . '/*' );
        if ( ! is_array( $files ) ) {
            return $stats;
        }

        global $wpdb;

        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) continue;

            $filename = basename( $file );

            // 檢查 wp_posts 是否有 attachment 引用此檔
            $attached = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND guid LIKE %s
                 LIMIT 1",
                '%' . $wpdb->esc_like( $filename )
            ) );

            if ( $attached ) {
                $stats['kept']++;
                continue;
            }

            $stats['files'][] = $filename;
            if ( ! $dry_run ) {
                @unlink( $file );
            }
            $stats['deleted']++;
        }

        $this->log_info( 'Orphan covers cleanup', array_merge( $stats, [ 'dry_run' => $dry_run ] ) );

        return $stats;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function sanitize_filename( string $url ): string {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $name = basename( (string) $path );
        $name = sanitize_file_name( $name );
        if ( empty( $name ) || $name === '.' ) {
            $name = 'anime-cover-' . wp_generate_uuid4() . '.jpg';
        }
        return $name;
    }

    // =========================================================================
    // Logger 統一封裝（L3）
    // =========================================================================

    private function log_info( string $message, array $context = [] ): void {
        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::info( '[ImageHandler] ' . $message, $context );
        }
    }

    private function log_warning( string $message, array $context = [] ): void {
        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::warning( '[ImageHandler] ' . $message, $context );
        } else {
            error_log( '[Anime Sync ImageHandler] ' . $message . ' ' . wp_json_encode( $context ) );
        }
    }
}
