<?php
/**
 * 檔案名稱: includes/class-streaming-registry.php
 * 串流平台統一登錄表 — Single Source of Truth
 *
 * ✅ 新增平台只需在此檔案的 $PLATFORMS 加一筆，以下全部自動生效：
 *    - class-api-handler.php  parse_streaming_links()  → 自動識別 AniList 連結
 *    - class-acf-fields.php   get_tw_platforms()       → 自動產生 checkbox + URL 欄位
 *    - 主題模板 single-anime.php $provider_icon_map     → 自動載入 icon
 *
 * icon 對齊 public/assets/img/providers/ 實際檔名（.webp）
 * key  對齊 class-acf-fields.php get_tw_platforms() 原有 key（保持相容）
 *
 * @version 1.0.2
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Streaming_Registry {

    /**
     * ★ 新增平台只需在這裡加一筆 ★
     *
     * key    → 機器識別碼，與 ACF meta key、URL meta key 完全相同
     * label  → 顯示名稱
     * color  → 品牌色
     * icon   → public/assets/img/providers/ 下的實際檔名
     * match  → AniList externalLinks site 欄位比對關鍵字（不分大小寫）
     *           也比對 URL host（用於台灣平台）
     * global → true = 國際平台 / false = 台灣限定
     */
    private static array $PLATFORMS = [

        // ── 國際平台 ─────────────────────────────────────────────
        [
            'key'    => 'crunchyroll',
            'billing' => 'sub',
            'label'  => 'Crunchyroll',
            'color'  => '#F47521',
            'icon'   => 'crunchyroll_icon.webp',
            'match'  => ['crunchyroll'],
            'global' => true,
        ],
        [
            'key'    => 'netflix',
            'billing' => 'sub',
            'label'  => 'Netflix',
            'color'  => '#E50914',
            'icon'   => 'netflix_icon.webp',
            'match'  => ['netflix'],
            'global' => true,
        ],
        [
            'key'    => 'disney',
            'billing' => 'sub',
            'label'  => 'Disney+',
            'color'  => '#113CCF',
            'icon'   => 'disneyplus_icon.webp',
            'match'  => ['disney plus', 'disney+', 'disneyplus', 'disney.com'],
            'global' => true,
        ],
        [
            'key'    => 'appletv',
            'billing' => 'sub',
            'label'  => 'Apple TV+',
            'color'  => '#555555',
            'icon'   => 'appletv_icon.webp',
            'match'  => ['apple tv', 'appletv', 'tv.apple.com'],
            'global' => true,
        ],
        [
            'key'    => 'amazon',
            'billing' => 'sub',
            'label'  => 'Amazon Prime Video',
            'color'  => '#00A8E1',
            'icon'   => 'amazon_prime_video_icon.webp',
            'match'  => ['amazon prime video', 'amazon', 'primevideo'],
            'global' => true,
        ],
        [
            'key'    => 'hidive',
            'billing' => 'sub',
            'label'  => 'HIDIVE',
            'color'  => '#00AEEF',
            'icon'   => 'hidive_icon.webp',
            'match'  => ['hidive'],
            'global' => true,
        ],
        [
            'key'    => 'hulu',
            'billing' => 'sub',
            'label'  => 'Hulu',
            'color'  => '#1CE783',
            'icon'   => 'disneyplus_icon.webp',
            'match'  => ['hulu'],
            'global' => true,
        ],
        [
            'key'    => 'bilibili',
            'billing' => 'sub',
            'label'  => 'Bilibili 國際版',
            'color'  => '#00A1D6',
            'icon'   => 'bilibili_icon.webp',
            'match'  => ['bilibili.tv', 'bilibili.com', 'bilibili'],
            'global' => true,
        ],
        [
            'key'    => 'iqiyi',
            'billing' => 'sub',
            'label'  => '愛奇藝',
            'color'  => '#00BE06',
            'icon'   => 'iqiyi_icon.webp',
            'match'  => ['iqiyi', 'iq.com'],
            'global' => true,
        ],

        // ── 台灣限定平台 ─────────────────────────────────────────
        [
            'key'    => 'bahamut',
            'billing' => 'free',
            'label'  => '巴哈姆特動畫瘋',
            'color'  => '#009944',
            'icon'   => 'anigamer_icon.webp',
            'match'  => ['ani.gamer.com.tw', 'gamer.com.tw', 'anigamer'],
            'global' => false,
        ],
        [
            'key'    => 'hami',
            'billing' => 'sub',
            'label'  => '中華電信 Hami Video',
            'color'  => '#0068B7',
            'icon'   => 'hami_icon.webp',
            'match'  => ['hamivideo.hinet.net', 'hami'],
            'global' => false,
        ],
        [
            'key'    => 'myvideo',
            'billing' => 'sub',
            'label'  => '台灣大哥大 MyVideo',
            'color'  => '#D6001C',
            'icon'   => 'myvideo_icon.webp',
            'match'  => ['myvideo.net.tw', 'myvideo'],
            'global' => false,
        ],
        [
            'key'    => 'linetv',
            'billing' => 'sub',
            'label'  => 'LINE TV',
            'color'  => '#06C755',
            'icon'   => 'linetv_icon.webp',
            'match'  => ['linetv.tw', 'line tv', 'linetv'],
            'global' => false,
        ],
        [
            'key'    => 'friday',
            'billing' => 'sub',
            'label'  => 'friDay 影音',
            'color'  => '#E2001A',
            'icon'   => 'friday_icon.webp',
            'match'  => ['video.friday.tw', 'friday'],
            'global' => false,
        ],
        [
            'key'    => 'ofiii',
            'billing' => 'free',
            'label'  => 'Ofiii 歐飛',
            'color'  => '#FF6600',
            'icon'   => 'ofiii_icon.webp',
            'match'  => ['ofiii'],
            'global' => false,
        ],
        [
            'key'    => 'catchplay',
            'billing' => 'sub',
            'label'  => 'CatchPlay+',
            'color'  => '#E60012',
            'icon'   => 'catchplay_icon.webp',
            'match'  => ['catchplay.com', 'catchplay'],
            'global' => false,
        ],
             [
            'key'    => 'litv',
            'billing' => 'sub',
            'label'  => 'LiTV 立視線上影視',
            'color'  => '#E5002B',
            'icon'   => 'litv_icon.webp',
            'match'  => ['litv.tv', 'litv'],
            'global' => false,
        ],
        [
            'key'    => 'ptsplus',
            'billing' => 'free',
            'label'  => '公視(台語版)',
            'color'  => '#00A0E9',
            'icon'   => 'channels4.webp',
            'match'  => ['ptsplus.tv', 'pts.org.tw', 'ptsplus', '公視'],
            'global' => false,
        ],
        [
            'key'    => 'anipass',
            'billing' => 'free',
            'label'  => 'AniPASS 車庫娛樂旗下',
            'color'  => '#6B4FBB',
            'icon'   => 'anipass_icon.webp',
            'match'  => ['anipass'],
            'yt_keywords' => ['AniPASS', '車庫'],
            'global' => false,
        ],
        [
            'key'    => 'ani_one',
            'billing' => 'free',
            'label'  => 'Ani-One 羚邦集團 YouTube',
            'color'  => '#1A1A2E',
            'icon'   => 'ani-one.webp',
            'match'  => ['ani-one', 'ani_one', 'anione', 'ani-one.asia'],
            'yt_keywords' => ['Ani-One', 'AniOne', 'Ani One'],
            'global' => false,
        ],
        [
            'key'    => 'muse',
            'billing' => 'free',
            'label'  => 'Muse 木棉花 YouTube',
            'color'  => '#C8A951',
            'icon'   => 'Muse.webp',
            'match'  => ['muse.live', 'muse'],
            'yt_keywords' => ['Muse', '木棉花'],
            'global' => false,
        ],
        [
            'key'    => 'mighty',
            'billing' => 'free',
            'label'  => '曼迪 YouTube',
            'color'  => '#333333',
            'icon'   => 'Mighty.webp',
            'match'  => ['mighty'],
            'yt_keywords' => ['曼迪', 'Mighty'],
            'global' => false,
        ],
        [
            'key'    => 'ani_mi',
            'billing' => 'free',
            'label'  => 'Ani-Mi 動漫迷動畫頻道',
            'color'  => '#FF4500',
            'icon'   => 'ani-mi.webp',
            'match'  => ['ani-mi', 'ani_mi', 'animi'],
            'yt_keywords' => ['Ani-Mi', '動漫迷'],
            'global' => false,
        ],
        [
            'key'    => 'tropicsanime',
            'billing' => 'free',
            'label'  => '回歸線娛樂 YouTube',
            'color'  => '#2E8B57',
            'icon'   => 'tropicsanime.webp',
            'match'  => ['tropics'],
            'yt_keywords' => ['回歸線', 'Tropics'],
            'global' => false,
        ],
        [
            'key'    => 'renta',
            'billing' => 'rent',
            'label'  => 'renta! 亂搭',
            'color'  => '#E4007F',
            'icon'   => 'renta.webp',
            'match'  => ['tw.myrenta.com', 'renta'],
            'global' => false,
        ],
[
            'key'    => 'garageplay',
            'billing' => 'free',
            'label'  => '車庫娛樂',
            'color'  => '#E60012',           // 可依實際品牌色調整
            'icon'   => 'garageplay_icon.webp',
            'match'  => ['garageplay.tw', 'garageplay'],
            'global' => false,
        ],
        [
            'key'    => 'youtube',
            'billing' => 'free',
            'label'  => 'YouTube',
            'color'  => '#FF0000',
            'icon'   => 'youtube_icon.webp',
            'match'  => [],
            'yt_keywords' => ['YouTube', 'youtube', 'Official'],
            'global' => false,
        ],
    ];
    // =========================================================================
    // 公用 API
    // =========================================================================

    public static function all(): array {
        return self::$PLATFORMS;
    }

    public static function global_platforms(): array {
        return array_values( array_filter( self::$PLATFORMS, fn( $p ) => $p['global'] ) );
    }

    public static function tw_platforms(): array {
        return array_values( array_filter( self::$PLATFORMS, fn( $p ) => ! $p['global'] ) );
    }

    public static function get( string $key ): ?array {
        foreach ( self::$PLATFORMS as $p ) {
            if ( $p['key'] === $key ) return $p;
        }
        return null;
    }

    /**
     * 給 parse_streaming_links() 用：
     * 傳入 AniList site 名稱或 URL host，回傳平台 key；找不到回 null。
     */

    /**
     * 給 class-youranimes-fetcher.php platform_map() 用：
     * 回傳 [ needle => key, ... ]，以關鍵字比對 URL host
     *
     * ✅ [v1.0.2] 修正：改為收錄「所有」match 關鍵字（不再限定含 . 的 domain）。
     *    原本只收含 . 的關鍵字，導致 Netflix(['netflix'])、Crunchyroll、HIDIVE、
     *    Hulu、Ofiii、AniPASS 等「純品牌名、無 domain」的平台整筆被跳過，
     *    parse_streams() 永遠比不到 → 抓不到這些串流連結。
     *    現在全部收錄並統一轉小寫，配合 parse_streams() 的 strpos($host, $needle)
     *    做包含比對即可正確命中（例：strpos('netflix.com', 'netflix')）。
     */
    public static function get_domain_map(): array {
        $map = [];
        foreach ( self::$PLATFORMS as $p ) {
            foreach ( $p['match'] as $keyword ) {
                $needle = strtolower( trim( (string) $keyword ) );
                if ( $needle === '' ) {
                    continue;
                }
                // 同一 needle 已存在就不覆蓋，避免後面平台順序造成誤判
                if ( ! isset( $map[ $needle ] ) ) {
                    $map[ $needle ] = $p['key'];
                }
            }
        }
        return $map;
    }

    /**
     * 給 class-youranimes-fetcher.php youtube_alt_map() 用：
     * 回傳 [ key => [keywords], ... ]，比對 YouTube 頻道名稱
     */
    public static function get_youtube_keyword_map(): array {
        $map = [];
        foreach ( self::$PLATFORMS as $p ) {
            if ( ! empty( $p['yt_keywords'] ) ) {
                $map[ $p['key'] ] = $p['yt_keywords'];
            }
        }
        return $map;
    }

    public static function match_site( string $site_name, string $url = '' ): ?string {
        $site_lower = strtolower( trim( $site_name ) );
        $host       = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        foreach ( self::$PLATFORMS as $p ) {
            foreach ( $p['match'] as $keyword ) {
                $kw = strtolower( $keyword );
                // 比對 site 名稱
                if ( $site_lower !== '' && str_contains( $site_lower, $kw ) ) {
                    return $p['key'];
                }
                // 比對 URL host（台灣平台常用 domain 識別）
                if ( $host !== '' && ( $host === $kw || str_ends_with( $host, '.' . $kw ) ) ) {
                    return $p['key'];
                }
            }
        }
        return null;
    }

    /**
     * 給前台 single-anime.php $provider_icon_map 用：
     * 回傳 [ 'key' => ['label'=>..., 'color'=>..., 'icon'=>...], ... ]
     */
    public static function get_icon_map(): array {
        $map = [];
        foreach ( self::$PLATFORMS as $p ) {
            $map[ $p['key'] ] = [
                'label' => $p['label'],
                'color' => $p['color'],
                'icon'  => $p['icon'],
            ];
        }
        return $map;
    }

    /**
     * 給 class-acf-fields.php get_tw_platforms() 用：
     * 回傳 [ 'key' => 'label', ... ]，與原格式完全相容
     */
    public static function get_acf_choices(): array {
        $choices = [];
        foreach ( self::all() as $p ) {
            $choices[ $p['key'] ] = $p['label'];
        }
        return $choices;
    }

    /**
     * 收費模式分組用的標籤與顯示順序。
     *
     * 順序就是前台的呈現順序——免費排最前面，那是使用者最想先看到的。
     */
    public const BILLING_LABELS = [
        'free' => '免費觀看',
        'sub'  => '月租觀看',
        'rent' => '單次租看',
    ];

    /**
     * [ key => billing ]，給前台分組用。
     *
     * ⚠ 不少平台同時提供訂閱與單次租看（MyVideo／friDay／CatchPlay+），
     *   這裡填的是「主要」模式。實際方案以平台公告為準，
     *   前台的免責聲明已經有寫。
     */
    public static function get_billing_map(): array {
        $map = [];

        foreach ( self::all() as $p ) {
            $map[ $p['key'] ] = $p['billing'] ?? 'sub';
        }

        return $map;
    }

    /**
     * 給 single-anime.php 用（相容舊格式）：
     * 回傳 [ key => icon_filename, ... ]
     * 同時以 label 小寫為 key 提供第二份映射，讓海外平台 strtolower(site) 也能命中
     */
    public static function get_icon_map_flat(): array {
        $map = [];
        foreach ( self::$PLATFORMS as $p ) {
            if ( $p['icon'] === '' ) continue;
            // 主 key
            $map[ $p['key'] ] = $p['icon'];
            // label 小寫 key（海外平台 overseas_icon_map 用 strtolower(site) 查詢）
            $label_key = strtolower( $p['label'] );
            if ( $label_key !== $p['key'] ) {
                $map[ $label_key ] = $p['icon'];
            }
        }
        return $map;
    }

}
