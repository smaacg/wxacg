<?php
/**
 * 檔案名稱: includes/class-acf-fields.php
 *
 * 修正紀錄(對應 repo 完整體檢):
 *   - Bug 1: anime_studio → anime_studios(對齊 import-manager / single-anime / CONTEXT.md)
 *   - 修正 2: anime_score_anilist step 0.01 → 1
 *   - 修正 3: anime_score_bangumi instructions 文字
 *   - 修正 4: anime_trailer_url 用 <br> 換行
 *   - 修正 5: 用 acf/prepare_field hook 讓 readonly 真的生效
 *   - 修正 6: wrapper width 第一排重排為 25/25/25/25
 *   - 修正 7: 加上 crunchyroll(對齊 single-anime.php $provider_icon_map)
 *   - 修正 8: anime_anilist_id required 改為 0(避免 wp_insert_post race)
 *   - 修正 9: 移除 anime 古騰堡編輯器 + 隱藏評分資訊群組(改為 cron 自動維護)
 *   - 修正 10: anime_start_date / anime_end_date return_format 由 Y-m-d 改為 Ymd,
 *              對齊全系統純數字 Ymd 儲存格式(cron 與 YourAnimes fetcher 的 NUMERIC 比對)
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_ACF_Fields {

    public function __construct() {
        add_action( 'acf/init',         [ $this, 'register_all_field_groups' ] );
        add_action( 'add_meta_boxes',   [ $this, 'register_resync_metabox' ] );

        // 修正 5:讓 readonly 真的生效(免費版 ACF 透過 wrapper class + CSS)
        add_action( 'acf/input/admin_head', [ $this, 'inject_readonly_css' ] );

        // 修正 9:移除 anime / manga 文章類型的古騰堡編輯器(編輯只用 ACF 欄位)
        add_action( 'init', [ $this, 'remove_anime_editor' ], 20 );

        // 新增:編輯畫面右下角「回到頂部」浮動按鈕
        add_action( 'admin_footer', [ $this, 'inject_back_to_top_button' ] );

        // 捷徑方塊的 JavaScript 與 AJAX
        add_action( 'admin_footer', [ $this, 'inject_shortcut_scripts' ] );
        add_action( 'wp_ajax_asp_shortcut_save_and_sync', [ $this, 'ajax_shortcut_save_and_sync' ] );
        
        // AI 輔助區塊的 JavaScript 與 AJAX
        add_action( 'admin_footer', [ $this, 'inject_ai_shortcut_scripts' ] );
        add_action( 'wp_ajax_asp_shortcut_ai_save_post', [ $this, 'ajax_shortcut_ai_save_post' ] );
        add_action( 'wp_ajax_asp_shortcut_ai_save_user', [ $this, 'ajax_shortcut_ai_save_user' ] );
        add_action( 'wp_ajax_asp_shortcut_ai_generate', [ $this, 'ajax_shortcut_ai_generate' ] );
        
        // CAST 字典管理與翻譯 AJAX
        add_action( 'wp_ajax_asp_shortcut_ai_cast_translate', [ $this, 'ajax_shortcut_ai_cast_translate' ] );
        add_action( 'wp_ajax_asp_cast_dict_load', [ $this, 'ajax_cast_dict_load' ] );
        add_action( 'wp_ajax_asp_cast_dict_save', [ $this, 'ajax_cast_dict_save' ] );

        $this->register_mirror_hooks();
    }

    private function register_mirror_hooks(): void {
        $mirror_fields = [
            'shortcut_anime_title_chinese'    => 'anime_title_chinese',
            'shortcut_anime_title_simplified' => 'anime_title_simplified',
            'shortcut_anime_title_native'     => 'anime_title_native',
            'shortcut_anime_youranimes_url'   => 'anime_youranimes_url',
            'shortcut_anime_tw_distributor'   => 'anime_tw_distributor',
            'shortcut_anime_tw_distributor_custom' => 'anime_tw_distributor_custom',
            'shortcut_anime_yt_playlist_url'  => 'anime_yt_playlist_url',
            'shortcut_anime_online_watch'     => 'anime_online_watch',
            'shortcut_anime_trailer_url'      => 'anime_trailer_url',
            'shortcut_anime_wikipedia_url'    => 'anime_wikipedia_url',
            // AI 輔助區塊鏡像
            'shortcut_anime_synopsis_chinese' => 'anime_synopsis_chinese',
            'shortcut_anime_faq_json'         => 'anime_faq_json',
            'shortcut_anime_cast_json'        => 'anime_cast_json',
        ];

        // AI 輔助生成開關 User Meta 載入機制 (使用者獨立偏好)
        $ai_toggles = ['shortcut_ai_generate_synopsis', 'shortcut_ai_generate_faq', 'shortcut_ai_generate_cast'];
        foreach ( $ai_toggles as $toggle ) {
            add_filter( "acf/load_value/name={$toggle}", function( $value, $post_id, $field ) use ( $toggle ) {
                $user_val = get_user_meta( get_current_user_id(), 'asp_ai_pref_' . $toggle, true );
                if ( $user_val !== '' ) {
                    return intval( $user_val );
                }
                return 1; // 預設開啟
            }, 10, 3 );
        }

        foreach ( $mirror_fields as $shortcut => $real_key ) {
            add_filter( "acf/load_value/name={$shortcut}", function( $value, $post_id, $field ) use ( $real_key ) {
                return get_post_meta( $post_id, $real_key, true );
            }, 10, 3 );
            
            add_filter( "acf/update_value/name={$shortcut}", function( $value, $post_id, $field ) use ( $real_key ) {
                update_post_meta( $post_id, $real_key, $value );
                return null;
            }, 10, 3 );
        }

        // 處理 Taxonomy 鏡像 (純文字框版)
        add_filter( 'acf/load_value/name=shortcut_anime_series_tax', function( $value, $post_id, $field ) {
            $terms = wp_get_object_terms( $post_id, 'anime_series_tax', [ 'fields' => 'names' ] );
            return is_wp_error( $terms ) || empty( $terms ) ? '' : implode( ',', $terms );
        }, 10, 3 );

        add_filter( 'acf/update_value/name=shortcut_anime_series_tax', function( $value, $post_id, $field ) {
            $term_names = [];
            if ( ! empty( $value ) && is_string( $value ) ) {
                $parts = explode( ',', $value );
                foreach ( $parts as $part ) {
                    $part = trim( $part );
                    if ( ! empty( $part ) ) {
                        $term_names[] = $part;
                    }
                }
            }
            wp_set_object_terms( $post_id, $term_names, 'anime_series_tax', false );
            return null;
        }, 10, 3 );
    }

    /**
     * 修正 9:移除 anime / manga 內文編輯器(古騰堡 / 傳統編輯器)
     */
    public function remove_anime_editor(): void {
        remove_post_type_support( 'anime', 'editor' );
        remove_post_type_support( 'manga', 'editor' );
    }

    public function register_all_field_groups(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        $this->register_shortcuts();
        $this->register_ai_shortcuts(); // 新增區塊 2：AI 輔助內容
        $this->register_basic_info();
        $this->register_ratings();
        $this->register_synopsis();
        $this->register_media();
        $this->register_production();
        $this->register_themes_and_streaming();
        $this->register_external_links();
        $this->register_taiwan_info();
        $this->register_faq();
        $this->register_sync_control();
        $this->register_post_related_anime();   // ← 新增這一行
        $this->register_manga_fields();      // ← 加這行,啟用漫畫欄位
        $this->register_manga_publication(); // ← 加這行,啟用漫畫出版資訊欄位(日出版社/雜誌/每卷 ISBN)
        $this->register_manga_external();    // ← 加這行,啟用漫畫外部資料庫 ID 欄位
        $this->register_manga_preview();     // ← 加這行,啟用漫畫試閱/免費閱讀欄位
    }
    
    // =========================================================================
    // 群組 1:基本資訊
    // =========================================================================
    private function register_basic_info(): void {
        acf_add_local_field_group( [
            'key'                   => 'group_anime_basic_info',
            'title'                 => '📋 基本資訊',
            'fields'                => [
                [
                    'key'           => 'field_anime_anilist_id',
                    'label'         => 'AniList ID',
                    'name'          => 'anime_anilist_id',
                    'type'          => 'number',
                    'instructions'  => '請填入 AniList 作品 ID(數字),例如:21。',
                    'required'      => 0, // 修正 8:不強制 required,避免 wp_insert_post race
                    'min'           => 1,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_mal_id',
                    'label'         => 'MyAnimeList ID',
                    'name'          => 'anime_mal_id',
                    'type'          => 'number',
                    'instructions'  => '由 AniList API 自動填入(idMal 欄位)。若為空表示 MAL 無對應條目。',
                    'required'      => 0,
                    'min'           => 1,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_bangumi_id',
                    'label'         => 'Bangumi ID',
                    'name'          => 'anime_bangumi_id',
                    'type'          => 'number',
                    'instructions'  => '由三層查找自動填入。若自動查找失敗,請手動填入 Bangumi 條目 ID。',
                    'required'      => 0,
                    'min'           => 1,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_animethemes_id',
                    'label'         => 'AnimeThemes Anime ID',
                    'name'          => 'anime_animethemes_id',
                    'type'          => 'text',
                    'instructions'  => '由 AnimeThemes API 自動填入 anime.id。舊資料若把 slug 寫在這,系統會在重新同步時自動搬到下方 Slug 欄位。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '25' ], // 修正 6:第一排統一 25
                ],
                [
                    'key'           => 'field_anime_animethemes_slug',
                    'label'         => 'AnimeThemes Slug',
                    'name'          => 'anime_animethemes_slug',
                    'type'          => 'text',
                    'instructions'  => 'AnimeThemes slug(例如 shingeki-no-kyojin)。找不到 anime.id 時,系統與人工補抓都會以此欄位作為 fallback。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_chinese',
                    'label'         => '中文標題(台灣繁體)',
                    'name'          => 'anime_title_chinese',
                    'type'          => 'text',
                    'instructions'  => '優先使用 Bangumi name_cn,若為空則 fallback 至 AniList english → AniList romaji。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_simplified',
                    'label'         => '简体标题（简体中文）',
                    'name'          => 'anime_title_simplified',
                    'type'          => 'text',
                    'instructions'  => '由 Bangumi name_cn 原样填入（不经 OpenCC 转换），保留简体供大陆用户搜寻。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],

                [
                    'key'           => 'field_anime_title_native',
                    'label'         => '日文原名',
                    'name'          => 'anime_title_native',
                    'type'          => 'text',
                    'instructions'  => '由 AniList title.native 自動填入(日文原始標題)。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_romaji',
                    'label'         => 'Romaji 標題',
                    'name'          => 'anime_title_romaji',
                    'type'          => 'text',
                    'instructions'  => '由 AniList title.romaji 自動填入。同時作為文章 slug 的產生來源。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_english',
                    'label'         => '英文標題',
                    'name'          => 'anime_title_english',
                    'type'          => 'text',
                    'instructions'  => '由 AniList title.english 自動填入。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_format',
                    'label'         => '作品類型',
                    'name'          => 'anime_format',
                    'type'          => 'select',
                    'instructions'  => '由 AniList format 欄位自動填入。',
                    'required'      => 0,
                    'choices'       => [
                        'TV'        => '電視動漫 (TV)',
                        'TV_SHORT'  => '短篇電視動漫 (TV_SHORT)',
                        'MOVIE'     => '劇場版 (MOVIE)',
                        'SPECIAL'   => '特別篇 (SPECIAL)',
                        'OVA'       => 'OVA',
                        'ONA'       => '網路動漫 (ONA)',
                        'MUSIC'     => '音樂 (MUSIC)',
                    ],
                    'default_value' => 'TV',
                    'wrapper'       => [ 'width' => '33' ],
                ],
                [
                    'key'           => 'field_anime_status',
                    'label'         => '播出狀態',
                    'name'          => 'anime_status',
                    'type'          => 'select',
                    'instructions'  => '由每日 cron 自動更新。',
                    'required'      => 0,
                    'choices'       => [
                        'FINISHED'          => '已完結',
                        'RELEASING'         => '連載中',
                        'NOT_YET_RELEASED'  => '尚未播出',
                        'CANCELLED'         => '已取消',
                        'HIATUS'            => '休播中',
                    ],
                    'default_value' => 'FINISHED',
                    'wrapper'       => [ 'width' => '33' ],
                ],
             [
    'key'           => 'field_anime_source',
    'label'         => '原作來源',
    'name'          => 'anime_source',
    'type'          => 'select',
    'instructions'  => '由 AniList source 欄位自動填入。',
    'required'      => 0,
    'choices'       => [
        'ORIGINAL'           => '原創',
        'MANGA'              => '漫畫',
        'LIGHT_NOVEL'        => '輕小說',
        'VISUAL_NOVEL'       => '視覺小說',
        'VIDEO_GAME'         => '電子遊戲',
        'GAME'               => 'Comic Game（桌遊/卡牌）',
        'NOVEL'              => '小說',
        'WEB_NOVEL'          => '網路小說',
        'DOUJINSHI'          => '同人誌',
        'ANIME'              => '動畫',
        'COMIC'              => '歐美漫畫',
        'LIVE_ACTION'        => '真人影視',
        'MULTIMEDIA_PROJECT' => '多媒體企劃',
        'PICTURE_BOOK'       => '繪本',
        'OTHER'              => '其他',
    ],
    'default_value' => '',
    'wrapper'       => [ 'width' => '34' ],
],
                [
                    'key'           => 'field_anime_season',
                    'label'         => '播出季度',
                    'name'          => 'anime_season',
                    'type'          => 'select',
                    'instructions'  => '由 AniList season 欄位自動填入。',
                    'required'      => 0,
                    'choices'       => [
                        'WINTER' => '冬季(1月)',
                        'SPRING' => '春季(4月)',
                        'SUMMER' => '夏季(7月)',
                        'FALL'   => '秋季(10月)',
                    ],
                    'wrapper'       => [ 'width' => '50' ],
                ],
[
    'key'           => 'field_anime_season_year',
    'label'         => '播出年份',
    'name'          => 'anime_season_year',
    'type'          => 'number',
    'instructions'  => '由 AniList seasonYear 欄位自動填入。動畫化確定但未定檔期時為 0，前台顯示「尚未公布」。',
    'required'      => 0,
    'min'           => 0,        // ← 由 1900 改為 0，允許未定檔期作品（值為 0）通過驗證存檔
    'max'           => 2100,
    'step'          => 1,
    'wrapper'       => [ 'width' => '50' ],
],
                [
                    'key'           => 'field_anime_episodes',
                    'label'         => '總集數',
                    'name'          => 'anime_episodes',
                    'type'          => 'number',
                    'instructions'  => '由 AniList episodes 欄位自動填入。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_episodes_aired',
                    'label'         => '已播集數',
                    'name'          => 'anime_episodes_aired',
                    'type'          => 'number',
                    'instructions'  => '播出中時由每日 cron 自動更新。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_duration',
                    'label'         => '每集時長(分鐘)',
                    'name'          => 'anime_duration',
                    'type'          => 'number',
                    'instructions'  => '由 AniList duration 欄位自動填入。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'            => 'field_anime_start_date',
                    'label'          => '開播日期',
                    'name'           => 'anime_start_date',
                    'type'           => 'date_picker',
                    'instructions'   => '由 AniList startDate 欄位自動填入。儲存為純數字 Ymd(例:20260701)。', // 修正 10
                    'required'       => 0,
                    'display_format' => 'Y-m-d',
                    'return_format'  => 'Ymd', // 修正 10:對齊全系統純數字 Ymd(cron / YourAnimes NUMERIC 比對)
                    'first_day'      => 1,
                    'wrapper'        => [ 'width' => '33' ],
                ],
                [
                    'key'            => 'field_anime_end_date',
                    'label'          => '完結日期',
                    'name'           => 'anime_end_date',
                    'type'           => 'date_picker',
                    'instructions'   => '完結後由 cron 自動填入,播出中時留空。儲存為純數字 Ymd(例:20260930)。', // 修正 10
                    'required'       => 0,
                    'display_format' => 'Y-m-d',
                    'return_format'  => 'Ymd', // 修正 10:對齊全系統純數字 Ymd(cron / YourAnimes NUMERIC 比對)
                    'first_day'      => 1,
                    'wrapper'        => [ 'width' => '33' ],
                ],
                [
                    'key'           => 'field_anime_next_airing',
                    'label'         => '下一集播出時間',
                    'name'          => 'anime_next_airing',
                    'type'          => 'text',
                    'instructions'  => '格式:YYYY-MM-DD HH:MM(台灣時間)。由每日 cron 自動更新;完結後清空。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '34' ],
                ],
            ],
            'location'              => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'            => 10,
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active'                => true,
        ] );
    }

    // =========================================================================
    // 群組 2:評分資訊(修正 9:隱藏,改為 cron 自動維護,後台不顯示)
    // =========================================================================
    private function register_ratings(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_ratings',
            'title'  => '⭐ 評分資訊',
            'fields' => [
                [
                    'key'           => 'field_anime_score_anilist',
                    'label'         => 'AniList 評分',
                    'name'          => 'anime_score_anilist',
                    'type'          => 'number',
                    'instructions'  => '範圍 0–100(原始分數)。由每週 cron 自動更新,前台顯示時除以 10。',
                    'required'      => 0,
                    'min'           => 0,
                    'max'           => 100,
                    'step'          => 1, // 修正 2:原 0.01 改為 1
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_score_mal',
                    'label'         => 'MyAnimeList 評分',
                    'name'          => 'anime_score_mal',
                    'type'          => 'number',
                    'instructions'  => '範圍 0–100(原始分數 × 10 儲存)。由每週 cron 透過 MyAnimeList 官方 API 自動更新,前台顯示時除以 10。',
                    'required'      => 0,
                    'min'           => 0,
                    'max'           => 100,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_score_bangumi',
                    'label'         => 'Bangumi 評分',
                    'name'          => 'anime_score_bangumi',
                    'type'          => 'number',
                    'instructions'  => '範圍 0–100(原始分數 × 10 儲存)。由每週 cron 自動更新,前台顯示時除以 10。', // 修正 3
                    'required'      => 0,
                    'min'           => 0,
                    'max'           => 100,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_popularity',
                    'label'         => 'AniList 人氣數',
                    'name'          => 'anime_popularity',
                    'type'          => 'number',
                    'instructions'  => '由 AniList popularity 欄位自動填入(收藏人數)。每週更新。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 20,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => false, // 修正 9:隱藏此群組(cron 自動維護,不需人工編輯)
        ] );
    }

    // =========================================================================
    // 群組 3:簡介
    // =========================================================================
    private function register_synopsis(): void {

        // 取得目前正在編輯的文章 ID
        $pid = 0;
        if ( is_admin() ) {
            if ( isset( $_GET['post'] ) ) {
                $pid = (int) $_GET['post'];
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $pid = (int) $_POST['post_ID'];
            }
        }

        $current_title    = $pid > 0 ? get_the_title( $pid ) : '';
        $title_for_prompt = $current_title !== '' ? $current_title : '（請填入作品名稱）';

        // ---------------------------------------------------------------------
        // 自動抓取「補充辨識」資料（與 FAQ 相同）
        // ---------------------------------------------------------------------
        $extra_parts = [];
        if ( $pid > 0 ) {

            $extra_map = [
                '日文原名' => 'anime_title_native',
                '原作來源' => 'anime_source',
                '作品類型' => 'anime_format',
                '播出季度' => 'anime_season',
                '播出年份' => 'anime_season_year',
            ];

            $season_label = [
                'WINTER' => '冬季', 'SPRING' => '春季',
                'SUMMER' => '夏季', 'FALL' => '秋季', 'AUTUMN' => '秋季',
            ];
            $source_label = [
                'MANGA'        => '漫畫',
                'ORIGINAL'     => '原創',
                'LIGHT_NOVEL'  => '輕小說',
                'NOVEL'        => '小說',
                'VISUAL_NOVEL' => '視覺小說',
                'VIDEO_GAME'   => '電玩遊戲',
                'GAME'         => '遊戲',
                'WEB_NOVEL'    => '網路小說',
                'WEB_MANGA'    => '網路漫畫',
                'DOUJINSHI'    => '同人誌',
                'ANIME'        => '動畫',
                'OTHER'        => '其他',
            ];
            $format_label = [
                'TV'       => '電視動畫',
                'TV_SHORT' => '電視短篇動畫',
                'MOVIE'    => '劇場版',
                'OVA'      => 'OVA',
                'ONA'      => 'ONA（網路動畫）',
                'SPECIAL'  => '特別篇',
                'MUSIC'    => '音樂 MV',
            ];

            foreach ( $extra_map as $label => $meta_key ) {
                $val = get_post_meta( $pid, $meta_key, true );
                if ( ! is_string( $val ) || $val === '' ) {
                    continue;
                }
                $upper = strtoupper( $val );

                if ( $meta_key === 'anime_season' && isset( $season_label[ $upper ] ) ) {
                    $val = $season_label[ $upper ];
                } elseif ( $meta_key === 'anime_source' && isset( $source_label[ $upper ] ) ) {
                    $val = $source_label[ $upper ];
                } elseif ( $meta_key === 'anime_format' && isset( $format_label[ $upper ] ) ) {
                    $val = $format_label[ $upper ];
                }

                $extra_parts[] = "{$label}：{$val}";
            }
        }

        if ( ! empty( $extra_parts ) ) {
            $extra_line = '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
        } else {
            $extra_line = "【補充辨識】（選填,例：第二季 / 劇場版 / TV版）\n";
        }

        // ---------------------------------------------------------------------
        // 組 prompt 文字
        // ---------------------------------------------------------------------
        $prompt  = "請將故事簡介／作品簡介，翻譯成台灣翻譯版本（繁體中文、台灣用語）。\n\n";
        $prompt .= "【作品名稱】{$title_for_prompt}　\n";
        $prompt .= $extra_line;

        // ---------------------------------------------------------------------
        // 說明文字 + 可複製框
        // ---------------------------------------------------------------------
        $ta_id = 'anime_synopsis_prompt_' . ( $pid > 0 ? $pid : 'new' );

        $instructions  = '優先使用 Bangumi summary（自動簡繁轉換）。若無資料，可用下方提示詞產生後貼回。<br>';
        $instructions .= '<strong>📋 作品名稱與補充辨識已自動帶入。點框內後按 Ctrl+A 再 Ctrl+C：</strong>';
        $instructions .= '<span style="display:block; margin:8px 0;">';
        $instructions .= '<textarea id="' . esc_attr( $ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:140px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $prompt ) . '</textarea>';
        $instructions .= '</span>';

        acf_add_local_field_group( [
            'key'    => 'group_anime_synopsis',
            'title'  => '📝 簡介',
            'fields' => [
                [
                    'key'          => 'field_anime_synopsis_chinese',
                    'label'        => '中文簡介（台灣繁體）',
                    'name'         => 'anime_synopsis_chinese',
                    'type'         => 'textarea',
                    'instructions' => $instructions,
                    'required'     => 0,
                    'rows'         => 6,
                    'new_lines'    => 'br',
                    'wrapper'      => [ 'width' => '100' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order' => 30,
            'position'   => 'normal',
            'style'      => 'default',
            'active'     => true,
        ] );
    }


    // =========================================================================
    // 群組 4:媒體素材
    // =========================================================================
    private function register_media(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_media',
            'title'  => '🖼️ 媒體素材',
            'fields' => [
                [
                    'key'           => 'field_anime_cover_image',
                    'label'         => '封面圖片網址',
                    'name'          => 'anime_cover_image',
                    'type'          => 'url',
                    'instructions'  => '由 AniList coverImage.extraLarge 自動填入。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_banner_image',
                    'label'         => '橫幅圖片網址',
                    'name'          => 'anime_banner_image',
                    'type'          => 'url',
                    'instructions'  => '由 AniList bannerImage 自動填入。可留空。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_trailer_url',
                    'label'         => 'YouTube 預告片網址(支援多支 PV)',
                    'name'          => 'anime_trailer_url',
                    'type'          => 'textarea',
                    // 修正 4:用 <br> 換行(ACF instruction 會 wpautop)
                    'instructions'  => '可填一支或多支 YouTube 網址,分隔方式:換行 / 逗號 / 分號 / 空格 皆可。<br>'
                                     . '可選擇加標題(用 | 分隔),未填標題會自動編號 PV 1、PV 2…<br><br>'
                                     . '<strong>範例(單支):</strong><br>'
                                     . '<code>https://www.youtube.com/watch?v=XXXXX</code><br><br>'
                                     . '<strong>範例(多支,每行一筆):</strong><br>'
                                     . '<code>https://youtu.be/abc12345678 | PV</code><br>'
                                     . '<code>https://youtu.be/def09876543 | PV2</code><br>'
                                     . '<code>https://youtu.be/ghi13579246 | PV3</code>',
                    'rows'          => 4,
                    'new_lines'     => '',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 40,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }

  // =========================================================================
    // 群組 5:製作資訊
    // =========================================================================
    private function register_production(): void {

           // 取得目前正在編輯的文章 ID
        $pid = 0;
        if ( is_admin() ) {
            if ( isset( $_GET['post'] ) ) {
                $pid = (int) $_GET['post'];
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $pid = (int) $_POST['post_ID'];
            }
        }

        $current_title    = $pid > 0 ? get_the_title( $pid ) : '';
        $title_for_prompt = $current_title !== '' ? $current_title : '（請填入作品名稱）';

        // ---------------------------------------------------------------------
        // 自動抓取「補充辨識」資料
        // ---------------------------------------------------------------------
        $extra_parts = [];
        if ( $pid > 0 ) {

            $extra_map = [
                '日文原名' => 'anime_title_native',
                '原作來源' => 'anime_source',
                '作品類型' => 'anime_format',
                '播出季度' => 'anime_season',
                '播出年份' => 'anime_season_year',
            ];

            $season_label = [
                'WINTER' => '冬季', 'SPRING' => '春季',
                'SUMMER' => '夏季', 'FALL' => '秋季', 'AUTUMN' => '秋季',
            ];
$source_label = [
    'ORIGINAL'           => '原創',
    'MANGA'              => '漫畫',
    'LIGHT_NOVEL'        => '輕小說',
    'VISUAL_NOVEL'       => '視覺小說',
    'VIDEO_GAME'         => '電子遊戲',
    'OTHER'              => '其他',
    'NOVEL'              => '小說',
    'DOUJINSHI'          => '同人誌',
    'ANIME'              => '動畫',
    'WEB_NOVEL'          => '網路小說',
    'LIVE_ACTION'        => '真人影視',
    'GAME'               => 'Comic Game（桌遊/卡牌）',
    'COMIC'              => '歐美漫畫',
    'MULTIMEDIA_PROJECT' => '多媒體企劃',
    'PICTURE_BOOK'       => '繪本',
];
            $format_label = [
                'TV'       => '電視動畫',
                'TV_SHORT' => '電視短篇動畫',
                'MOVIE'    => '劇場版',
                'OVA'      => 'OVA',
                'ONA'      => 'ONA（網路動畫）',
                'SPECIAL'  => '特別篇',
                'MUSIC'    => '音樂 MV',
            ];

            foreach ( $extra_map as $label => $meta_key ) {
                $val = get_post_meta( $pid, $meta_key, true );
                if ( ! is_string( $val ) || $val === '' ) {
                    continue;
                }
                $upper = strtoupper( $val );

                if ( $meta_key === 'anime_season' && isset( $season_label[ $upper ] ) ) {
                    $val = $season_label[ $upper ];
                } elseif ( $meta_key === 'anime_source' && isset( $source_label[ $upper ] ) ) {
                    $val = $source_label[ $upper ];
                } elseif ( $meta_key === 'anime_format' && isset( $format_label[ $upper ] ) ) {
                    $val = $format_label[ $upper ];
                }

                $extra_parts[] = "{$label}：{$val}";
            }
        }

        if ( ! empty( $extra_parts ) ) {
            $extra_line = '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
        } else {
            $extra_line = "【補充辨識】（選填,例：第二季 / 劇場版 / TV版）\n";
        }

        // ---------------------------------------------------------------------
        // CAST JSON 譯名在地化指令文字
        // ---------------------------------------------------------------------
        
$cast_prompt = "你是熟悉台灣 ACG 圈譯名的翻譯校對員。請把以下 CAST JSON 的「角色名(name)」與「聲優名(voice_actors 的 name)」改成台灣慣用中文譯名。\n";
$cast_prompt .= "\n【作品名稱】{$title_for_prompt}\n";
$cast_prompt .= "{$extra_line}\n";
$cast_prompt .= "最重要的前提:\n";
$cast_prompt .= "你必須「實際上網開啟網頁查證」,不可僅憑記憶或推測。新番你的記憶很可能沒有或過時。\n";
$cast_prompt .= "查證來源優先順序:\n";
$cast_prompt .= "① 台灣代理商/平台官方(木棉花 Muse、曼迪、羚邦、Netflix、巴哈姆特動畫瘋)的官網或官方社群(FB/IG/X)——有台灣官方代理版本時,以其角色譯名為最高依據\n";
$cast_prompt .= "② 中文維基百科台灣版(zh-hant)\n";
$cast_prompt .= "③ 日文官網、日文維基(確認原文對應,避免張冠李戴)\n";
$cast_prompt .= "④ 萌娘百科/百度(僅輔助確認角色存在與原文對應,為大陸譯名,不可直接採用)\n\n";
$cast_prompt .= "若查無台灣代理官方譯名(常見於老作品、冷門番、未代理作品):\n";
$cast_prompt .= "- 依②③來源查證後,採用台灣 ACG 圈普遍使用之慣用譯名(而非直接照搬大陸慣用譯名)\n\n";
$cast_prompt .= "若同一角色/聲優查到多個不同譯名版本(例如代理商譯名與圈內舊譯不同):\n";
$cast_prompt .= "- 以來源優先順序最高者為準,直接採用,不需列出其他版本讓我選擇\n\n";
$cast_prompt .= "修改後請提供一份「核對清單」告訴我:\n";
$cast_prompt .= "- 每個角色/聲優的新譯名、原文、來源(需具體網址,無法附網址代表沒查到,該筆不要採用,原樣保留)\n\n";
$cast_prompt .= "JSON 結構規則(嚴格遵守):\n";
$cast_prompt .= "- 不可增減欄位、不可改 key、不可改順序、不可改 id、image、role、source\n";
$cast_prompt .= "- image 網址一字不可動\n\n";
$cast_prompt .= "輸出前請自我檢查:\n";
$cast_prompt .= "- 每個修改過的角色/聲優是否都有具體來源網址?\n";
$cast_prompt .= "- JSON 是否為合法格式(無多餘逗號、雙引號皆為半形)?\n";
$cast_prompt .= "- 是否誤動了 id / image / role / source?\n\n";
$cast_prompt .= "最後單獨輸出完整 JSON,放程式碼框內供一鍵複製。框內只有 JSON,結構與我給的完全一致,所有 image 網址保持原樣。\n\n";

$cast_prompt .= "以下是 JSON:\n";
        $cast_ta_id = 'anime_cast_prompt_' . ( $pid > 0 ? $pid : 'new' );

        $cast_instructions  = '由 Bangumi CAST API 自動填入(多為日文原名/大陸譯名),匯入後人工整理。整理後請在「同步控制」勾選「鎖定 CAST 角色資料」,避免下次同步被覆蓋。<br><br>';
        $cast_instructions .= '<strong>📋 譯名在地化指令(點框內後按 Ctrl+A 再 Ctrl+C,務必先填【作品名稱】,連同 CAST JSON 一起貼給可上網的 AI):</strong>';
        $cast_instructions .= '<span style="display:block; margin:8px 0;">';
        $cast_instructions .= '<textarea id="' . esc_attr( $cast_ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:260px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $cast_prompt ) . '</textarea>';
        $cast_instructions .= '</span>';
        $cast_instructions .= '<strong>⚠️ 必填【作品名稱】;核對清單若某筆「來源」沒附具體網址,代表 AI 沒真的查到,該筆譯名別採用;貼回前掃一眼程式碼框,確認 image 網址未被更動。</strong>';

        acf_add_local_field_group( [
            'key'    => 'group_anime_production',
            'title'  => '🎬 製作資訊',
            'fields' => [
                [
                    'key'           => 'field_anime_studios', // 修正 1
                    'label'         => '製作公司',
                    'name'          => 'anime_studios',       // 修正 1:單數 → 複數
                    'type'          => 'text',
                    'instructions'  => '由 AniList studios 自動填入(逗號分隔字串)。可手動編輯。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_staff_json',
                    'label'         => 'STAFF 資料(JSON)',
                    'name'          => 'anime_staff_json',
                    'type'          => 'textarea',
                    'instructions'  => '由 Bangumi STAFF API 自動填入。可手動修正繁簡轉換錯誤後儲存。修改後請在「同步控制」勾選「鎖定 STAFF 製作資料」。',
                    'required'      => 0,
                    'rows'          => 6,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_cast_json',
                    'label'         => 'CAST 角色資料(JSON)',
                    'name'          => 'anime_cast_json',
                    'type'          => 'textarea',
                    'instructions'  => $cast_instructions,
                    'required'      => 0,
                    'rows'          => 6,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_episodes_json',
                    'label'         => '集數列表(JSON)',
                    'name'          => 'anime_episodes_json',
                    'type'          => 'textarea',
                    'instructions'  => '由 Bangumi Episodes API 自動填入。修改後請在「同步控制」勾選「鎖定集數列表」。格式:[{"ep":1,"name":"...","name_cn":"...","airdate":"YYYY-MM-DD"}]',
                    'required'      => 0,
                    'rows'          => 6,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 50,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }
    
// =========================================================================
// 群組 M1:漫畫資料(AniList + Bangumi + 台灣代理)
// location 綁定 post_type == manga,不影響動畫。
// =========================================================================
private function register_manga_fields(): void {
	acf_add_local_field_group( [
		'key'    => 'group_manga_data',
		'title'  => '📖 漫畫資料',
		'fields' => [

			// ---- 識別 ID(手動填,AniList 必填) ----
			[
				'key'          => 'field_manga_anilist_id',
				'label'        => 'AniList ID(漫畫)',
				'name'         => 'anime_anilist_id', // 沿用共用 key,find_existing 靠它
				'type'         => 'number',
				'instructions' => '必填。AniList 漫畫頁網址中的數字,例如 anilist.co/manga/147149 → 填 147149。',
				'required'     => 0,
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_bangumi_id',
				'label'        => 'Bangumi ID',
				'name'         => 'anime_bangumi_id', // 沿用共用 key
				'type'         => 'number',
				'instructions' => '選填。用於補中文標題/簡介/台灣代理資訊。',
				'required'     => 0,
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_mal_id',
				'label'        => 'MAL ID',
				'name'         => 'anime_mal_id',
				'type'         => 'number',
				'instructions' => '選填。MAL 評分來源。', // ← 改:已用於評分
				'required'     => 0,
				'wrapper'      => [ 'width' => '34' ],
			],

			// ---- 標題(沿用共用 key,可手動改) ----
			[
				'key'     => 'field_manga_title_chinese',
				'label'   => '中文標題',
				'name'    => 'anime_title_chinese',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_simplified',
				'label'   => '简体标题',
				'name'    => 'anime_title_simplified',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_native',
				'label'   => '日文原名',
				'name'    => 'anime_title_native',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_romaji',
				'label'   => '羅馬字標題',
				'name'    => 'anime_title_romaji',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_english',
				'label'   => '英文標題',
				'name'    => 'anime_title_english',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],

			// ---- Cron 自動更新的客觀欄位 ----
			[
				'key'          => 'field_manga_status',
				'label'        => '連載狀態',
				'name'         => 'anime_status', // 沿用共用 key,後台徽章能複用
				'type'         => 'select',
				'instructions' => 'Cron 會自動更新。若 AniList 標錯(例:實際休刊卻標連載中),可手動改後在下方勾選鎖定。',
				'choices'      => [
					'RELEASING'        => '連載中',
					'FINISHED'         => '已完結',
					'HIATUS'           => '休刊中',
					'NOT_YET_RELEASED' => '未發售',
					'CANCELLED'        => '已腰斬',
				],
				'allow_null'   => 1,
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_status_locked',
				'label'        => '鎖定連載狀態',
				'name'         => 'manga_status_locked',
				'type'         => 'true_false',
				'instructions' => '勾選後,Cron 不再自動覆蓋上面的連載狀態(用於 AniList 標錯的個案)。',
				'ui'           => 1,
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_chapters',
				'label'        => '話數',
				'name'         => 'manga_chapters',
				'type'         => 'number',
				'instructions' => 'Cron 自動更新。連載中常為空,前台顯示「連載中・未定」。',
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_volumes',
				'label'        => '卷數(日版)',
				'name'         => 'manga_volumes',
				'type'         => 'number',
				'instructions' => 'Cron 自動更新。連載中常為空。',
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_format',
				'label'        => '類型',
				'name'         => 'anime_format',
				'type'         => 'text',
				'instructions' => 'MANGA / ONE_SHOT / NOVEL 等,由 AniList 帶入。',
				'wrapper'      => [ 'width' => '34' ],
			],
			[
				'key'     => 'field_manga_start_date',
				'label'   => '開始連載日',
				'name'    => 'anime_start_date',
				'type'    => 'text',
				'instructions' => '格式 Ymd(如 20210715)。',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_end_date',
				'label'   => '完結日期',
				'name'    => 'anime_end_date',
				'type'    => 'text',
				'instructions' => '格式 Ymd。未完結留空。',
				'wrapper' => [ 'width' => '50' ],
			],

			// ---- 評分(四欄統一 25 寬,加入 MAL) ----
			[
				'key'     => 'field_manga_score_anilist',
				'label'   => 'AniList 評分',
				'name'    => 'anime_score_anilist',
				'type'    => 'number',
				'instructions' => '0–100。前台可 ÷10 顯示。Cron 自動更新。',
				'wrapper' => [ 'width' => '25' ], // ← 33 改 25
			],
			[
				'key'     => 'field_manga_score_bangumi',
				'label'   => 'Bangumi 評分',
				'name'    => 'anime_score_bangumi',
				'type'    => 'number',
				'instructions' => '0–100(原始 ×10 儲存)。前台 ÷10 顯示。Cron 自動更新。',
				'wrapper' => [ 'width' => '25' ], // ← 33 改 25
			],
			[
				'key'     => 'field_manga_score_mal', // ← 新增
				'label'   => 'MAL 評分',
				'name'    => 'anime_score_mal',
				'type'    => 'number',
				'instructions' => '0–100(原始 ×10 儲存)。前台 ÷10 顯示。Cron 透過 Jikan/MAL 自動更新。',
				'wrapper' => [ 'width' => '25' ],
			],
			[
				'key'     => 'field_manga_popularity',
				'label'   => '人氣值',
				'name'    => 'anime_popularity',
				'type'    => 'number',
				'wrapper' => [ 'width' => '25' ], // ← 34 改 25
			],

			// ---- 內容(匯入後可手動改,靠 anime_locked_fields 保護) ----
			[
				'key'     => 'field_manga_synopsis',
				'label'   => '簡介',
				'name'    => 'anime_synopsis_chinese',
				'type'    => 'textarea',
				'instructions' => '優先 Bangumi 中文,無則 fallback AniList。',
				'rows'    => 5,
			],
			[
				'key'     => 'field_manga_author',
				'label'   => '作者(原作/Story)',
				'name'    => 'manga_author',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_artist',
				'label'   => '作畫(Art)',
				'name'    => 'manga_artist',
				'type'    => 'text',
				'instructions' => '作者與作畫常為同一人。',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'   => 'field_manga_cover',
				'label' => '封面圖 URL',
				'name'  => 'anime_cover_image', // 沿用共用 key,封面下載邏輯能複用
				'type'  => 'text',
			],

			// ---- 台灣代理資訊(純手動,SEO 差異化重點) ----
			[
				'key'          => 'field_manga_tw_publisher',
				'label'        => '台灣出版社',
				'name'         => 'manga_tw_publisher',
				'type'         => 'text',
				'instructions' => '如:東立、尖端、青文、長鴻。',
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_tw_translator',
				'label'   => '譯者',
				'name'    => 'manga_tw_translator',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_tw_volumes',
				'label'        => '台版集數',
				'name'         => 'manga_tw_volumes',
				'type'         => 'number',
				'instructions' => '常與日版不同。',
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_tw_release_date',
				'label'   => '台版發售日',
				'name'    => 'manga_tw_release_date',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_purchase_url',
				'label'        => '購買連結',
				'name'         => 'manga_purchase_url',
				'type'         => 'url',
				'instructions' => '博客來 / 台版購買頁。',
			],

			// ---- 關聯動畫(雙向連結用) ----
			[
				'key'          => 'field_manga_related_anime',
				'label'        => '關聯動畫',
				'name'         => 'manga_related_anime',
				'type'         => 'post_object',
				'instructions' => '選擇對應的動畫作品,前台做「動畫化」雙向連結。',
				'post_type'    => [ 'anime' ],
				'return_format'=> 'id',
				'ui'           => 1,
				'allow_null'   => 1,
				'multiple'     => 0,
			],

			// ---- 鎖定欄位(沿用動畫同一機制) ----
			[
				'key'          => 'field_manga_locked_fields',
				'label'        => '🔒 鎖定欄位(同步時不覆蓋)',
				'name'         => 'anime_locked_fields', // 沿用!import manager 的鎖定判斷認這個 key
				'type'         => 'checkbox',
				'instructions' => '勾選的欄位在 Cron / 重新同步時不會被覆蓋,保護你手動修改的內容。',
				'choices'      => [
					'anime_title_chinese'    => '中文標題',
					'anime_synopsis_chinese' => '簡介',
					'anime_cover_image'      => '封面圖',
					'manga_author'           => '作者',
					'manga_artist'           => '作畫',
				],
				'layout'       => 'horizontal',
			],
		],
		'location'   => [
			[ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
		],
		'menu_order' => 10,
		'position'   => 'normal',
		'style'      => 'default',
		'active'     => true,
	] );
}

    // =========================================================================
    // 群組 M2:漫畫出版資訊(日出版社/連載雜誌/每卷 ISBN/多地區代理)
    // location 綁定 post_type == manga
    // =========================================================================
    private function register_manga_publication(): void {
        acf_add_local_field_group( [
            'key'    => 'group_manga_publication',
            'title'  => '📚 出版資訊',
            'fields' => [
                [
                    'key'          => 'field_manga_jp_publishers',
                    'label'        => '日本出版社',
                    'name'         => 'manga_jp_publishers',
                    'type'         => 'text',
                    'instructions' => '例如 集英社、講談社、史克威爾艾尼克斯。同步時由維基 infobox / Wikidata 自動帶入。',
                ],
                [
                    'key'          => 'field_manga_magazine',
                    'label'        => '連載雜誌',
                    'name'         => 'manga_magazine',
                    'type'         => 'text',
                    'instructions' => '例如 週刊少年Jump、月刊BIG GANGAN、週刊Young Magazine。以維基 infobox 為主。',
                ],
                [
                    'key'          => 'field_manga_first_publish_date',
                    'label'        => '首刊發表日',
                    'name'         => 'manga_first_publish_date',
                    'type'         => 'text',
                    'instructions' => 'YYYY-MM-DD。來自 Wikidata P577 或維基出版表第 1 卷。',
                ],
                [
                    'key'          => 'field_manga_hk_publisher',
                    'label'        => '香港代理商',
                    'name'         => 'manga_hk_publisher',
                    'type'         => 'text',
                    'instructions' => '例如 玉皇朝、天下出版。港澳讀者最關心的欄位之一。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_cn_publisher',
                    'label'        => '中國大陸代理商',
                    'name'         => 'manga_cn_publisher',
                    'type'         => 'text',
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_volumes_jp',
                    'label'        => '日版已出卷數',
                    'name'         => 'manga_volumes_jp',
                    'type'         => 'number',
                    'instructions' => '維基出版表統計的實際卷數(最準)。',
                    'wrapper'      => [ 'width' => '33' ],
                ],
                [
                    'key'          => 'field_manga_volumes_hk',
                    'label'        => '港版已出卷數',
                    'name'         => 'manga_volumes_hk',
                    'type'         => 'number',
                    'wrapper'      => [ 'width' => '33' ],
                ],
                [
                    'key'          => 'field_manga_volumes_cn',
                    'label'        => '陸版已出卷數',
                    'name'         => 'manga_volumes_cn',
                    'type'         => 'number',
                    'wrapper'      => [ 'width' => '34' ],
                ],
                [
                    'key'          => 'field_manga_volumes_json',
                    'label'        => '每卷發售資訊 (JSON)',
                    'name'         => 'manga_volumes_json',
                    'type'         => 'textarea',
                    'rows'         => 6,
                    'instructions' => '自動來自維基百科「出版」章節。前端範本 json_decode 後展開。一般不用手改。',
                ],
                [
                    'key'          => 'field_manga_volumes_summary',
                    'label'        => '每卷資訊可讀表 (Markdown)',
                    'name'         => 'manga_volumes_summary',
                    'type'         => 'textarea',
                    'rows'         => 10,
                    'instructions' => '若只想讀,看這一欄就好。與 JSON 同步自動產生。',
                ],
                [
                    'key'          => 'field_manga_volume_covers', // ← 新增:單行本封面牆
                    'label'        => '📚 單行本封面 (JSON)',
                    'name'         => 'manga_volume_covers',
                    'type'         => 'textarea',
                    'rows'         => 6,
                    'instructions' => '由 Bangumi 關聯條目自動抓取、封面下載至本地。格式:[{"vol":1,"cover":"本地URL","bgm_id":344360}]。前台封面牆讀此欄位,勿手改。',
                    'wrapper'      => [ 'width' => '100', 'class' => 'asp-readonly' ],
                ],
                [
                    'key'          => 'field_manga_awards',
                    'label'        => '獲獎紀錄',
                    'name'         => 'manga_awards',
                    'type'         => 'textarea',
                    'rows'         => 3,
                    'instructions' => '來自 Wikidata P166。例如「這本漫畫真厲害!」、漫畫大賞等。',
                ],
                [
                    'key'          => 'field_manga_wiki_last_sync',
                    'label'        => '🕒 上次維基同步時間',
                    'name'         => 'manga_wiki_last_sync',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_wiki_last_status',
                    'label'        => '同步狀態',
                    'name'         => 'manga_wiki_last_status',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => [ 'width' => '50' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
            ],
            'menu_order' => 15,
            'position'   => 'normal',
            'style'      => 'default',
            'active'     => true,
        ] );
    }


    // =========================================================================
    // 群組 M3:資料來源(精簡版)
    // 以台港澳為主,移除 MangaUpdates / ANN / 豆瓣 / 百度 / Comic Walker /
    // Niconico / Google KG 等 wikidata-fetcher 已不再提供的冷門外部 ID。
    // 只保留 QID 與維基網址,方便回查資料來源。顯示於側邊。
    // =========================================================================
    private function register_manga_external(): void {
        acf_add_local_field_group( [
            'key'    => 'group_manga_external_ids',
            'title'  => '🔗 資料來源',
            'fields' => [
                [
                    'key'          => 'field_manga_wikidata_qid',
                    'label'        => 'Wikidata QID',
                    'name'         => 'manga_wikidata_qid',
                    'type'         => 'text',
                    'instructions' => '同步時自動帶入,方便回查資料來源。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_wikipedia_url',
                    'label'        => '維基百科網址',
                    'name'         => 'manga_wikipedia_url',
                    'type'         => 'url',
                    // ★改:此欄現可「手動填」作為維基查找依據。
                    'instructions' => '中文維基頁網址,例如 https://zh.wikipedia.org/wiki/膽大黨。<br>'
                                    . '<strong>手動填寫後,按「🔄 重新抓取維基資料」會優先用此網址對應的條目去抓</strong>'
                                    . '(適用譯名對不上、簡繁轉換仍查不到的作品)。<br>'
                                    . '留空則系統自動用中文標題(轉繁後)查找,查到會自動回填此欄。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
            ],
            'menu_order' => 30,
            'position'   => 'side',
            'style'      => 'default',
            'active'     => true,
        ] );
    }

    // =========================================================================
    // 群組 M4:漫畫試閱 / 免費閱讀(合法試閱連結)
    // location 綁定 post_type == manga
    // =========================================================================
    private function register_manga_preview(): void {
        acf_add_local_field_group( [
            'key'    => 'group_manga_preview',
            'title'  => '📖 試閱 / 免費閱讀',
            'fields' => [
                [
                    'key'          => 'field_manga_preview_url',
                    'label'        => '免費閱讀 / 試閱連結',
                    'name'         => 'manga_preview_url',
                    'type'         => 'url',
                    'instructions' => '貼上合法免費閱讀或試閱網址,例如 Book☆Walker 台灣、少年Jump+、Comic Walker 的試閱頁。',
                    'required'     => 0,
                    'placeholder'  => 'https://www.bookwalker.com.tw/...',
                ],
                [
                    'key'          => 'field_manga_preview_source_type',
                    'label'        => '來源類型',
                    'name'         => 'manga_preview_source_type',
                    'type'         => 'select',
                    'choices'      => [
                        ''                  => '—',
                        'trial'             => '試閱(前幾話或前幾頁)',
                        'official_free'     => '官方完全免費',
                        'limited_time_free' => '期間限定免費',
                        'aggregator'        => '聚合區(例 weixiaoacg)',
                    ],
                    'default_value' => '',
                    'allow_null'    => 1,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_preview_note',
                    'label'        => '備註',
                    'name'         => 'manga_preview_note',
                    'type'         => 'text',
                    'instructions' => '例如「免費看到第 30 話」、「2026-12-31 前全集免費」。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
            ],
            'menu_order' => 20,
            'position'   => 'normal',
            'style'      => 'default',
            'active'     => true,
        ] );
    }

    // =========================================================================
    // 群組 6:主題曲與串流平台
    // =========================================================================
    private function register_themes_and_streaming(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_themes_streaming',
            'title'  => '🎵 主題曲與串流平台',
            'fields' => [
                [
                    'key'           => 'field_anime_themes_json',
                    'label'         => 'OP/ED 主題曲資料(JSON)',
                    'name'          => 'anime_themes',
                    'type'          => 'textarea',
                    // 開放手動編輯：可修改歌名與歌手
                    'instructions'  => '由 AnimeThemes API 自動抓取，可手動修改歌名與歌手。<br>'
                                     . '⚠️ 只改 <code>title</code> 與 <code>artists</code>，<strong>請勿更動 <code>type</code> 與 <code>sequence</code></strong>（否則同步會誤判成新歌而重複新增）。<br>'
                                     . 'AnimeThemes 之後補的新歌仍會自動加入，不會覆蓋您已改的內容。',
                    'required'      => 0,
                    'rows'          => 8,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ], // 已移除 asp-readonly，開放編輯
                ],
                [
                    'key'           => 'field_anime_streaming_json',
                    'label'         => '串流平台資料(JSON)',
                    'name'          => 'anime_streaming',
                    'type'          => 'textarea',
                    'instructions'  => '由 AniList externalLinks(type: STREAMING)自動填入。請勿手動編輯。',
                    'required'      => 0,
                    'rows'          => 4,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100', 'class' => 'asp-readonly' ], // 維持唯讀
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 60,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }


    // =========================================================================
    // 群組 7:外部連結
    // =========================================================================
    private function register_external_links(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_external_links',
            'title'  => '🔗 外部連結',
            'fields' => [
                [
                    'key'           => 'field_anime_official_site',
                    'label'         => '官方網站',
                    'name'          => 'anime_official_site',
                    'type'          => 'url',
                    'instructions'  => '由 AniList externalLinks 自動填入。可人工覆寫。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_twitter_url',
                    'label'         => 'Twitter / X 官方帳號',
                    'name'          => 'anime_twitter_url',
                    'type'          => 'url',
                    'instructions'  => '由 AniList externalLinks 自動填入。可人工覆寫。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_wikipedia_url',
                    'label'         => 'Wikipedia 頁面',
                    'name'          => 'anime_wikipedia_url',
                    'type'          => 'url',
                    'instructions'  => '請人工填入中文或日文維基百科連結。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_tiktok_url',
                    'label'         => 'TikTok 官方帳號',
                    'name'          => 'anime_tiktok_url',
                    'type'          => 'url',
                    'instructions'  => '請人工填入 TikTok 官方帳號連結(選填)。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 70,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }
    
    

    // =========================================================================
    // 群組 8:台灣在地資訊
    // =========================================================================
    private function register_taiwan_info(): void {

        $platforms  = $this->get_tw_platforms();
        $url_fields = [];

        foreach ( $platforms as $key => $label ) {
            $url_fields[] = [
                'key'          => 'field_anime_tw_streaming_url_' . $key,
                'label'        => $label . ' 直達連結',
                'name'         => 'anime_tw_streaming_url_' . $key,
                'type'         => 'url',
                'instructions' => '勾選上方「' . $label . '」後,可在此填入該動漫的直達連結(留空則顯示純文字)。',
                'required'     => 0,
                'wrapper'      => [ 'width' => '50' ],
            ];
        }

        acf_add_local_field_group( [
            'key'    => 'group_anime_taiwan_info',
            'title'  => '🇹🇼 台灣在地資訊',
            'fields' => array_merge(
                [
                    [
                        'key'           => 'field_anime_tw_streaming',
                        'label'         => '台灣串流平台',
                        'name'          => 'anime_tw_streaming',
                        'type'          => 'checkbox',
                        'instructions'  => '勾選有上架的平台;下方可對應填入該動漫的直達連結。',
                        'required'      => 0,
                        'choices'       => $platforms,
                        'layout'        => 'horizontal',
                        'toggle'        => 0,
                        'return_format' => 'value',
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                ],
                $url_fields,
                [
                    [
                        'key'           => 'field_anime_tw_streaming_other',
                        'label'         => '其他串流平台(自訂)',
                        'name'          => 'anime_tw_streaming_other',
                        'type'          => 'text',
                        'instructions'  => '上方平台以外的服務,多個請用逗號分隔。',
                        'required'      => 0,
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                    [
    'key'           => 'field_anime_no_streaming_google',
    'label'         => 'Google 搜尋連結（無串流平台時顯示）',
    'name'          => 'anime_no_streaming_google',
    'type'          => 'url',
    'instructions'  => '當本作在台灣尚無任何串流平台上架時，可填入一個 Google 搜尋結果連結，供使用者自行查找。<br>'
                     . '留空的話，前台可自動用作品名稱組出搜尋連結。<br>'
                     . '範例：<code>https://www.google.com/search?q=作品名稱+線上看</code>',
    'required'      => 0,
    'placeholder'   => 'https://www.google.com/search?q=...',
    'wrapper'       => [ 'width' => '100' ],
],
                    [
                        'key'           => 'field_anime_tw_distributor',
                        'label'         => '台灣代理商/發行商',
                        'name'          => 'anime_tw_distributor',
                        'type'          => 'select',
                        'instructions'  => '請選擇台灣代理商;若不在清單中請選「其他(自訂)」並於下方填寫。',
                        'required'      => 0,
                        'choices'       => [
                            ''            => '── 請選擇 ──',
                            'muse'        => '木棉花',
                            'medialink'   => '曼迪傳播',
                            'linbang'     => '羚邦',
                            'tropic'      => '回歸線娛樂',
                            'proware'     => '普威爾',
                            'kadokawa'    => '台灣角川',
                            'gungho'      => '群英社',
                            'tien'        => '提恩傳媒',
                            'garage'      => '車庫娛樂',
                            'carsun'      => '采昌國際',
                            'jbf'         => '日本橋文化(JBF)',
                            'righttime'   => '利得時代(Right Time)',
                            'aniplus'     => 'ANIPLUS Asia',
                            'tongli'      => '東立出版社',
                            'remow'       => 'REMOW',
                            'gaga'        => 'GaGa OOLala',
                            'other'       => '其他(自訂)',
                        ],
                        'default_value' => '',
                        'allow_null'    => 1,
                        'wrapper'       => [ 'width' => '50' ],
                    ],
                    [
                        'key'           => 'field_anime_tw_distributor_custom',
                        'label'         => '台灣代理商(自訂名稱)',
                        'name'          => 'anime_tw_distributor_custom',
                        'type'          => 'text',
                        'instructions'  => '僅在上方選「其他(自訂)」時生效。',
                        'required'      => 0,
                        'wrapper'       => [ 'width' => '50' ],
                    ],
                    [
                        'key'           => 'field_anime_tw_broadcast',
                        'label'         => '台灣播出時間',
                        'name'          => 'anime_tw_broadcast',
                        'type'          => 'text',
                        'instructions'  => '請人工填入台灣播出時間(例:每週六 23:00 Netflix)。',
                        'required'      => 0,
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                    [
                        'key'           => 'field_anime_dub_language',
                        'label'         => '配音語言版本',
                        'name'          => 'anime_dub_language',
                        'type'          => 'checkbox',
                        'instructions'  => '勾選本作在台灣有提供的配音版本(可複選)。用於前台標示與 SEO。',
                        'required'      => 0,
                        'choices'       => [
                            'mandarin' => '國語配音',
                            'taigi'    => '台語配音',
                        ],
                        'layout'        => 'horizontal',
                        'toggle'        => 0,
                        'return_format' => 'value',
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                    [
                        'key'          => 'field_anime_dub_url_taigi',
                        'label'        => '台語配音 觀看連結',
                        'name'         => 'anime_dub_url_taigi',
                        'type'         => 'url',
                        'instructions' => '若上方勾選「台語配音」,填入可觀看台語版的平台連結(多為公視+)。留空則只顯示文字標示。',
                        'required'     => 0,
                        'wrapper'      => [ 'width' => '50' ],
                    ],
                    [
    'key'          => 'field_anime_dub_url_mandarin',
    'label'        => '國語配音 觀看連結（可填多平台）',
    'name'         => 'anime_dub_url_mandarin',
    'type'         => 'textarea',
    'instructions' => '可填一個或多個平台連結，換行或逗號分隔皆可。<br>'
                     . '格式：<code>平台名稱|網址</code>，未填名稱則統一顯示「國語配音」。<br><br>'
                     . '<strong>範例：</strong><br>'
                     . '<code>巴哈動畫瘋|https://ani.gamer.com.tw/animeVideo.php?sn=xxxx</code><br>'
                     . '<code>Ofiii|https://www.ofiii.com/xxxx</code><br>'
                     . '<code>LineTV|https://www.linetv.tw/xxxx</code>',
    'rows'         => 4,
    'new_lines'    => '',
    'required'     => 0,
    'wrapper'      => [ 'width' => '50' ],
],
                           [
                        'key'           => 'field_anime_youranimes_url',
                        'label'         => 'YourAnimes 網址',
                        'name'          => 'anime_youranimes_url',
                        'type'          => 'url',
                        'instructions'  => '貼上 YourAnimes 動畫頁網址https://youranimes.tw/animes/xxxx/onair',
                        'required'      => 0,
                        'placeholder'   => 'https://youranimes.tw/animes/XXXX/onair',
                        'wrapper'       => [ 'width' => '100', 'class' => 'anime-youranimes-url-field' ],
                    ],
                      [
                        'key'          => 'field_anime_online_watch',
                        'label'        => '線上看（YouTube 嵌入）',
                        'name'         => 'anime_online_watch',
                        'type'         => 'textarea',
                        'instructions' => '貼 YouTube 網址，一行一支，前台會嵌入播放器。可選加標題：<code>標題|網址</code>。<br>'
                                        . '支援 watch?v=、youtu.be、/embed/、/shorts/ 各種格式。<br><br>'
                                        . '<strong>依照網址填入範例：</strong><br>'
                                        . '<code>第1話|https://youtu.be/XXXXXXXX</code><br>'
                                        . '<code>第2話|</code><br>'
                                        . '<code>第3話|</code>',
                        'rows'         => 4,
                        'new_lines'    => '',
                        'required'     => 0,
                        'wrapper'      => [ 'width' => '100' ],
                    ],

                    // ▶️ YouTube 播放清單自動同步（原獨立群組，改併入本群組，緊接線上看下方）
                    [
                        'key'          => 'field_anime_yt_playlist_url',
                        'label'        => 'YouTube 播放清單網址',
                        'name'         => 'anime_yt_playlist_url',
                        'type'         => 'url',
                        'instructions' => '貼上該作品的官方 YouTube 播放清單網址,系統會自動抓取新集數並補進上方「線上看」。<br>'
                                        . '格式:<code>https://www.youtube.com/playlist?list=PLxxxxxxxx</code><br>'
                                        . '只認標題含「第X話 / #X / EPx」的影片,PV/預告會自動略過。',
                        'required'     => 0,
                        'placeholder'  => 'https://www.youtube.com/playlist?list=PL...',
                        'wrapper'      => [ 'width' => '100' ],
                    ],
                    [
                        'key'          => 'field_anime_yt_sync_enabled',
                        'label'        => '啟用自動同步',
                        'name'         => 'anime_yt_sync_enabled',
                        'type'         => 'true_false',
                        'instructions' => '關閉後此作品不再自動抓取(適合已完結、不需再更新的作品)。',
                        'default_value'=> 1,
                        'ui'           => 1,
                        'wrapper'      => [ 'width' => '50' ],
                    ],
                    [
                        'key'          => 'field_anime_yt_last_sync',
                        'label'        => '上次 YT 同步時間',
                        'name'         => 'anime_yt_last_sync',
                        'type'         => 'text',
                        'instructions' => '系統自動記錄,請勿手動修改。',
                        'required'     => 0,
                        'wrapper'      => [ 'width' => '50', 'class' => 'asp-readonly' ],
                    ],
                    [
                        'key'          => 'field_anime_yt_sync_log',
                        'label'        => '同步紀錄',
                        'name'         => 'anime_yt_sync_log',
                        'type'         => 'textarea',
                        'instructions' => '最近幾次自動同步的結果,方便排查。系統自動維護。',
                        'required'     => 0,
                        'rows'         => 3,
                        'wrapper'      => [ 'width' => '100', 'class' => 'asp-readonly' ],
                    ],
                ]
            ),
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 80,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }


    // =========================================================================
    // 群組 9：常見問題（FAQ）
    // =========================================================================

    private function register_faq(): void {

        // 取得目前正在編輯的文章 ID
        $pid = 0;
        if ( is_admin() ) {
            if ( isset( $_GET['post'] ) ) {
                $pid = (int) $_GET['post'];
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $pid = (int) $_POST['post_ID'];
            }
        }

        $current_title    = $pid > 0 ? get_the_title( $pid ) : '';
        $title_for_prompt = $current_title !== '' ? $current_title : '（請填入作品名稱）';

        // ---------------------------------------------------------------------
        // 自動抓取「補充辨識」資料
        // ---------------------------------------------------------------------
        $extra_parts = [];
        if ( $pid > 0 ) {

            $extra_map = [
                '日文原名' => 'anime_title_native',
                '原作來源' => 'anime_source',
                '作品類型' => 'anime_format',
                '播出季度' => 'anime_season',
                '播出年份' => 'anime_season_year',
            ];

            $season_label = [
                'WINTER' => '冬季', 'SPRING' => '春季',
                'SUMMER' => '夏季', 'FALL' => '秋季', 'AUTUMN' => '秋季',
            ];
            $source_label = [
                'MANGA'        => '漫畫',
                'ORIGINAL'     => '原創',
                'LIGHT_NOVEL'  => '輕小說',
                'NOVEL'        => '小說',
                'VISUAL_NOVEL' => '視覺小說',
                'VIDEO_GAME'   => '電玩遊戲',
                'GAME'         => '遊戲',
                'WEB_NOVEL'    => '網路小說',
                'WEB_MANGA'    => '網路漫畫',
                'DOUJINSHI'    => '同人誌',
                'ANIME'        => '動畫',
                'OTHER'        => '其他',
            ];
            $format_label = [
                'TV'       => '電視動畫',
                'TV_SHORT' => '電視短篇動畫',
                'MOVIE'    => '劇場版',
                'OVA'      => 'OVA',
                'ONA'      => 'ONA（網路動畫）',
                'SPECIAL'  => '特別篇',
                'MUSIC'    => '音樂 MV',
            ];

            foreach ( $extra_map as $label => $meta_key ) {
                $val = get_post_meta( $pid, $meta_key, true );
                if ( ! is_string( $val ) || $val === '' ) {
                    continue;
                }
                $upper = strtoupper( $val );

                if ( $meta_key === 'anime_season' && isset( $season_label[ $upper ] ) ) {
                    $val = $season_label[ $upper ];
                } elseif ( $meta_key === 'anime_source' && isset( $source_label[ $upper ] ) ) {
                    $val = $source_label[ $upper ];
                } elseif ( $meta_key === 'anime_format' && isset( $format_label[ $upper ] ) ) {
                    $val = $format_label[ $upper ];
                }

                $extra_parts[] = "{$label}：{$val}";
            }
        }

        if ( ! empty( $extra_parts ) ) {
            $extra_line = '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
        } else {
            $extra_line = "【補充辨識】（選填,例：第二季 / 劇場版 / TV版）\n";
        }

        // ---------------------------------------------------------------------
        // 組 prompt 文字
        // ---------------------------------------------------------------------
        $prompt  = "你是動漫資料編輯。請上網搜尋我提供的作品，並撰寫 FAQ。\n\n";
        $prompt .= "【作品名稱】{$title_for_prompt}　\n";
        $prompt .= $extra_line . "\n";
        $prompt .= "搜尋與撰寫規則：\n";
        $prompt .= "1. 動筆前先核對：以上方【補充辨識】的日文原名、年份、媒體形式、季別，確認鎖定的是「正確的作品、正確的季別」。若有同名作品、多季、TV版/劇場版/重製版，務必區分清楚，不可混用不同季別的資訊。\n";
        $prompt .= "2. 來源優先順序：① 中文維基百科（主要比對依據，核對譯名、季別、集數）→ ② 日文維基百科、官方網站（故事設定若官網有明確說明可優先參考）→ ③ AniList、MyAnimeList、巴哈姆特（輔助補漏與交叉驗證）。避免個人部落格或來路不明的網站，並盡量交叉比對至少兩個來源。\n";
        $prompt .= "3. 【原創改寫】不得整句照抄任何來源原文。請綜合多個來源後，用你自己的話重新敘述，確保內容原創，不與維基百科逐字重複。\n";
        $prompt .= "4. 【防止虛構】僅根據查證到的資料撰寫。若某項資訊無法交叉確認，寧可省略該題，絕不虛構或用內部記憶推測。\n";
        $prompt .= "5. 題數規則：資料充足產出 3~5 題；資料有限則產出 1~2 題；若完全查無可靠資料，輸出空陣列 []。\n";
        $prompt .= "6. 問題須聚焦「劇情設定、世界觀、角色背景、故事主軸」等內容面向，而非製作或播出時程。答案須簡明扼要，嚴格不涉及關鍵轉折、結局或重大伏筆（不劇透）。\n";
        $prompt .= "7. 【SEO】每題問題開頭須包含完整作品名稱。例如：「動畫《{$title_for_prompt}》的故事背景與核心主軸是什麼？」\n";
        $prompt .= "8. 每題答案以繁體中文撰寫，長度約 50 字左右，簡潔不冗長。\n";
        $prompt .= "9. 來源請「另外用純文字」告訴我方便核對，不要寫進 JSON 的答案內容裡。\n\n";
        $prompt .= "輸出格式（嚴格遵守）：\n";
        $prompt .= "- 只輸出一個 JSON 陣列，放在程式碼框內供一鍵複製。\n";
        $prompt .= "- 每個物件僅含 q 與 a 兩個欄位，不得加入 source 或其他欄位。\n";
        $prompt .= "- 必須是合法 JSON：使用半形雙引號（不可用全形引號），結尾不得有多餘逗號，不得加入註解。\n";
        $prompt .= "- 查無資料時，程式碼框內輸出：[]\n\n";
        $prompt .= '範例：[{"q":"問題一","a":"答案一"},{"q":"問題二","a":"答案二"}]';

       // ---------------------------------------------------------------------
        // 說明文字 + 複製按鈕（clipboard API 優先，失敗退回 execCommand）
        // ---------------------------------------------------------------------
        $ta_id  = 'anime_faq_prompt_' . ( $pid > 0 ? $pid : 'new' );
        $btn_id = $ta_id . '_btn';

        $instructions  = '完全人工輸入，留空則不顯示 FAQ 區塊與 Schema.org FAQPage。<br>';
        $instructions .= '<strong>格式範例：</strong> <code>[{"q":"問題一","a":"答案一"}]</code><br><br>';
        $instructions .= '<strong>📋 作品名稱與補充辨識已自動帶入。點框內後按 Ctrl+A 再 Ctrl+C：</strong>';
        $instructions .= '<span style="display:block; margin:8px 0;">';
        $instructions .= '<textarea id="' . esc_attr( $ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:220px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $prompt ) . '</textarea>';
        $instructions .= '</span>';
        $instructions .= '<strong>⚠️ AI 回覆中，來源說明在上、JSON 程式碼框在下；用程式碼框右上角複製鈕取得 JSON，貼回前確認只有 q/a 兩個欄位。</strong>';

        acf_add_local_field_group( [
            'key'    => 'group_anime_faq',
            'title'  => '❓ 常見問題（FAQ）',
            'fields' => [
                [
                    'key'           => 'field_anime_faq_json',
                    'label'         => 'FAQ JSON',
                    'name'          => 'anime_faq_json',
                    'type'          => 'textarea',
                    'instructions'  => $instructions,
                    'required'      => 0,
                    'rows'          => 8,
                    'new_lines'     => '',
                    'placeholder'   => '[{"q":"問題","a":"答案"}]',
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 85,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }



    // =========================================================================
    // 群組 10:同步控制
    // =========================================================================
    private function register_sync_control(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_sync_control',
            'title'  => '⚙️ 同步控制',
            'fields' => [
                [
                    'key'           => 'field_anime_last_sync',
                    'label'         => '上次 API 同步時間',
                    'name'          => 'anime_last_sync',
                    'type'          => 'text',
                    'instructions'  => '由系統自動記錄。請勿手動修改。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50', 'class' => 'asp-readonly' ], // 修正 5
                ],
                [
                    'key'           => 'field_anime_last_updated',
                    'label'         => '資料最後更新時間',
                    'name'          => 'anime_last_updated',
                    'type'          => 'text',
                    'instructions'  => '每次任何欄位更新時由系統自動記錄。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50', 'class' => 'asp-readonly' ], // 修正 5
                ],
                [
                    'key'           => 'field_anime_locked_fields',
                    'label'         => '鎖定欄位(防止自動覆寫)',
                    'name'          => 'anime_locked_fields',
                    'type'          => 'checkbox',
                    'instructions'  => '勾選後,自動更新 cron 與重新同步 Bangumi 將跳過該欄位,保留您的人工修改。',
                    'required'      => 0,
                    'choices'       => [
                        'anime_title_chinese'    => '中文標題',
                        'anime_synopsis_chinese' => '中文簡介',
                        'anime_cover_image'      => '封面圖片',
                        'anime_banner_image'     => '橫幅圖片',
                        'anime_trailer_url'      => 'YouTube 預告片',
                        'anime_cast_json'        => 'CAST 角色資料',
                        'anime_staff_json'       => 'STAFF 製作資料',
                        'anime_episodes_json'    => '集數列表',
                    ],
                    'layout'        => 'horizontal',
                    'toggle'        => 0,
                    'return_format' => 'value',
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 90,
            'position'    => 'side',
            'style'       => 'default',
            'active'      => true,
        ] );
    }
    
        // =========================================================================
    // 群組 11:文章關聯動畫(v1.1.0 新增)
    //
    // 用途:在 feature / review 文章後台勾選「這篇影評/前導是寫哪部動畫」,
    //      single-anime.php 依此 meta 反查並顯示「無雷前導 / 有雷影評」按鈕。
    //
    // 資料結構:
    //   meta_key:   related_anime
    //   meta_value: ACF 序列化陣列 of anime post IDs(多選)
    //
    // 顯示條件:post_type=post 且 category in (feature, review)
    //   ACF location 用 OR:post_category == feature OR post_category == review
    // =========================================================================
    private function register_post_related_anime(): void {
        acf_add_local_field_group( [
            'key'    => 'group_post_related_anime',
            'title'  => '🔗 關聯動畫',
            'fields' => [
                [
                    'key'           => 'field_post_related_anime',
                    'label'         => '關聯動畫',
                    'name'          => 'related_anime',
                    'type'          => 'post_object',
                    'instructions'  => '勾選此影評 / 前導文章是寫哪部動畫(可多選)。動畫單頁會自動顯示對應按鈕。',
                    'required'      => 0,
                    'post_type'     => [ 'anime' ],
                    'taxonomy'      => '',
                    'allow_null'    => 1,
                    'multiple'      => 1,
                    'return_format' => 'id',
                    'ui'            => 1,
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_category', 'operator' => '==', 'value' => 'category:feature' ] ],
                [ [ 'param' => 'post_category', 'operator' => '==', 'value' => 'category:review'  ] ],
            ],
            'menu_order'      => 5,
            'position'        => 'side',
            'style'           => 'default',
            'label_placement' => 'top',
            'active'          => true,
        ] );
    }

    // =========================================================================
    // Meta Box:重新同步 Bangumi
    // =========================================================================
    public function register_resync_metabox(): void {
        add_meta_box(
            'anime_resync_bangumi',
            '🔄 重新同步 Bangumi',
            [ $this, 'render_resync_metabox' ],
            'anime',
            'side',
            'default'
        );
    }

    public function render_resync_metabox( WP_Post $post ): void {
        $bangumi_id = get_post_meta( $post->ID, 'anime_bangumi_id', true );
        $last_sync  = get_post_meta( $post->ID, 'anime_last_sync',  true );
        ?>
        <div id="anime-resync-wrap">
            <?php if ( $bangumi_id ) : ?>
                <p style="margin:0 0 8px;">
                    Bangumi ID:<strong><?php echo esc_html( $bangumi_id ); ?></strong>
                </p>
            <?php else : ?>
                <p style="margin:0 0 8px;color:#999;">尚未設定 Bangumi ID。</p>
            <?php endif; ?>
            <?php if ( $last_sync ) : ?>
                <p style="margin:0 0 8px;font-size:11px;color:#666;">
                    上次同步:<?php echo esc_html( $last_sync ); ?>
                </p>
            <?php endif; ?>
            <button
                type="button"
                id="anime-resync-bangumi-btn"
                class="button button-secondary"
                style="width:100%;"
            >
                🔄 重新同步 Bangumi 資料
            </button>
            <p id="anime-resync-bangumi-msg" style="margin:8px 0 0;min-height:20px;font-size:12px;"></p>
        </div>
        <?php
    }

    // =========================================================================
    // 修正 5:用 CSS 讓 readonly 真的生效
    // =========================================================================
    public function inject_readonly_css(): void {
        ?>
        <style>
            .acf-field.asp-readonly input[type="text"],
            .acf-field.asp-readonly textarea {
                background: #f6f7f7 !important;
                color: #50575e !important;
                pointer-events: none;
                cursor: not-allowed;
            }
            .acf-field.asp-readonly .acf-label label::after {
                content: ' (唯讀)';
                color: #999;
                font-weight: normal;
                font-size: 11px;
            }
        </style>
        <?php
    }
    // =========================================================================
    // 新增:編輯畫面右下角「回到頂部」浮動按鈕
    // 只在文章編輯(post.php / post-new.php)畫面顯示,避免污染其他後台頁面
    // =========================================================================
    public function inject_back_to_top_button(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->base, [ 'post', 'post-new' ], true ) ) {
            return;
        }
        ?>
        <button
            type="button"
            id="asp-back-to-top-btn"
            title="回到頂部"
            style="
                position: fixed;
                right: 24px;
                bottom: 24px;
                z-index: 99999;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                border: none;
                background: #2271b1;
                color: #fff;
                font-size: 20px;
                line-height: 1;
                cursor: pointer;
                box-shadow: 0 2px 8px rgba(0,0,0,0.25);
                display: none;
                align-items: center;
                justify-content: center;
            "
        >↑</button>
        <script>
        (function () {
            var btn = document.getElementById('asp-back-to-top-btn');
            if (!btn) return;

            function toggleBtn() {
                if (window.scrollY > 300) {
                    btn.style.display = 'flex';
                } else {
                    btn.style.display = 'none';
                }
            }

            window.addEventListener('scroll', toggleBtn, { passive: true });
            toggleBtn();

            btn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
        </script>
        <?php
    }

    public function inject_shortcut_scripts(): void {
        global $pagenow;
        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) || get_post_type() !== 'anime' ) {
            return;
        }
        ?>
        <style>
            /* 壓縮捷徑方塊的垂直空間，保留輸入框原本大小 */
            #acf-group_anime_shortcuts .inside, #acf-group_anime_shortcuts > .inside, #acf-group_anime_shortcuts .acf-fields {
                padding: 0 !important; margin: 0 !important;
            }
            #acf-group_anime_shortcuts .acf-field {
                padding: 6px 12px !important; margin: 0 !important;
            }
            #acf-group_anime_shortcuts .acf-label {
                margin: 0 0 3px !important;
            }
            #acf-group_anime_shortcuts .acf-label label {
                font-size: 13px !important;
                color: #444;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // 確保 ACF 已經載入完畢
            function injectButton() {
                if ($('#asp-btn-save-sync').length) return; // 避免重複加入
                
                // 設定絕對定位：左側 50% 往回拉 50% 寬度，達到完美置中
                var $btnHTML = $('<button type="button" id="asp-btn-save-sync" class="button button-primary" style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); z-index: 10; height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: flex; align-items: center; box-sizing: border-box;">💾 儲存捷徑變更</button>');
                
                // 尋找外框的 Header
                var $header = $('#acf-group_anime_shortcuts .postbox-header');
                if ($header.length === 0) {
                    $header = $('#acf-group_anime_shortcuts .hndle'); // 傳統版
                }
                
                // 將 Header 設為相對定位，並塞入按鈕
                $header.css('position', 'relative').append($btnHTML);
                
                // 將「YouTube 預告片網址」的灰色範例標籤加到標題同一行
                var $trailerLabel = $('.acf-field[data-name="shortcut_anime_trailer_url"] .acf-label label');
                if ($trailerLabel.length && $trailerLabel.find('.asp-trailer-example').length === 0) {
                    $trailerLabel.append(' <span class="asp-trailer-example" style="background-color: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 12px; color: #3c434a; font-weight: normal; margin-left: 8px;">範例:https://youtu.be/abc12345678 | PV</span>');
                }

                // 將「YourAnimes 網址」的灰色範例標籤加到標題同一行
                var $yaLabel = $('.acf-field[data-name="shortcut_anime_youranimes_url"] .acf-label label');
                if ($yaLabel.length && $yaLabel.find('.asp-ya-example').length === 0) {
                    $yaLabel.append(' <span class="asp-ya-example" style="background-color: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 12px; color: #3c434a; font-weight: normal; margin-left: 8px;">範例: https://youranimes.tw/animes/xxxx/onair</span>');
                }

                // 將「Wikipedia」旁邊加上開啟連結按鈕
                var $wikiLabel = $('.acf-field[data-name="shortcut_anime_wikipedia_url"] .acf-label label');
                if ($wikiLabel.length && $wikiLabel.find('.asp-wiki-link-btn').length === 0) {
                    var $wikiBtn = $('<a href="#" class="asp-wiki-link-btn" target="_blank" style="margin-left: 10px; font-size: 12px; text-decoration: none; color: #2271b1; background-color: #f0f0f1; padding: 2px 8px; border-radius: 3px; font-weight: normal;">🔗 點擊維基連結</a>');
                    $wikiLabel.append($wikiBtn);

                    $wikiBtn.on('click', function(e) {
                        var currentUrl = $('.acf-field[data-name="shortcut_anime_wikipedia_url"] input[type="url"]').val();
                        if (!currentUrl) {
                            e.preventDefault();
                            alert('請先在下方輸入 Wikipedia 網址');
                        } else {
                            $(this).attr('href', currentUrl);
                        }
                    });
                }

                // 將「YouTube 播放清單」旁邊加上開啟連結按鈕
                var $ytLabel = $('.acf-field[data-name="shortcut_anime_yt_playlist_url"] .acf-label label');
                if ($ytLabel.length && $ytLabel.find('.asp-yt-link-btn').length === 0) {
                    var $ytBtn = $('<a href="#" class="asp-yt-link-btn" target="_blank" style="margin-left: 10px; font-size: 12px; text-decoration: none; color: #2271b1; background-color: #f0f0f1; padding: 2px 8px; border-radius: 3px; font-weight: normal;">🔗 點擊YT連結</a>');
                    $ytLabel.append($ytBtn);

                    $ytBtn.on('click', function(e) {
                        var currentUrl = $('.acf-field[data-name="shortcut_anime_yt_playlist_url"] input[type="url"]').val();
                        if (!currentUrl) {
                            e.preventDefault();
                            alert('請先在下方輸入 YouTube 播放清單網址');
                        } else {
                            $(this).attr('href', currentUrl);
                        }
                    });
                }
            }

            // 針對傳統編輯器
            injectButton();
            
            // 針對某些動態載入的情況（雙重保險）
            if (typeof acf !== 'undefined') {
                acf.addAction('ready', injectButton);
            }

            $(document).on('click', '#asp-btn-save-sync', function(e) {
                e.preventDefault();
                if (typeof acf === 'undefined') return;
                
                var $btn = $(this);
                var postId = $('#post_ID').val();
                var fields = {};
                
                try {
                    // 使用 ACF JS API 抓取捷徑方塊內的所有欄位值
                    acf.getFields({parent: $('#acf-group_anime_shortcuts')}).forEach(function(field) {
                        if (field.data.name) {
                            fields[field.data.name] = field.val();
                        }
                    });
                } catch(e) {
                    alert('讀取欄位值時發生錯誤，請重新整理頁面後再試。');
                    return;
                }

                $btn.text('⏳ 處理中...').prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'asp_shortcut_save_and_sync',
                    nonce: '<?php echo wp_create_nonce("asp_shortcut_sync"); ?>',
                    post_id: postId,
                    fields: fields
                }, function(res) {
                    if (res.success) {
                        if (res.data.action === 'synced') {
                            $btn.text('✅ 已儲存並同步完成！即將重整...');
                        } else {
                            $btn.text('✅ 捷徑已儲存！即將重整...');
                        }
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        alert('錯誤: ' + (res.data.message || res.data));
                        $btn.text('💾 儲存捷徑變更').prop('disabled', false);
                    }
                }).fail(function() {
                    alert('發生網路錯誤，請重試！');
                    $btn.text('💾 儲存捷徑變更').prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_shortcut_save_and_sync(): void {
        check_ajax_referer( 'asp_shortcut_sync', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( '權限不足' );
        }
        
        $fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];
        
        $mapping = [
            'shortcut_anime_title_chinese'    => 'anime_title_chinese',
            'shortcut_anime_title_simplified' => 'anime_title_simplified',
            'shortcut_anime_title_native'     => 'anime_title_native',
            'shortcut_anime_youranimes_url'   => 'anime_youranimes_url',
            'shortcut_anime_tw_distributor'   => 'anime_tw_distributor',
            'shortcut_anime_tw_distributor_custom' => 'anime_tw_distributor_custom',
            'shortcut_anime_yt_playlist_url'  => 'anime_yt_playlist_url',
            'shortcut_anime_online_watch'     => 'anime_online_watch',
            'shortcut_anime_trailer_url'      => 'anime_trailer_url',
            'shortcut_anime_wikipedia_url'    => 'anime_wikipedia_url',
        ];
        
        $old_ya_url = get_post_meta( $post_id, 'anime_youranimes_url', true );
        $new_ya_url = isset( $fields['shortcut_anime_youranimes_url'] ) ? $fields['shortcut_anime_youranimes_url'] : '';
        
        $old_yt_url = get_post_meta( $post_id, 'anime_yt_playlist_url', true );
        $new_yt_url = isset( $fields['shortcut_anime_yt_playlist_url'] ) ? $fields['shortcut_anime_yt_playlist_url'] : '';

        // 儲存一般 Post Meta
        foreach ( $mapping as $shortcut => $real_key ) {
            if ( isset( $fields[$shortcut] ) ) {
                update_post_meta( $post_id, $real_key, $fields[$shortcut] );
            }
        }
        
        // 儲存 Taxonomy (系列標籤) - 從純文字解析
        if ( isset( $fields['shortcut_anime_series_tax'] ) ) {
            $value = $fields['shortcut_anime_series_tax'];
            $term_names = [];
            if ( ! empty( $value ) && is_string( $value ) ) {
                $parts = explode( ',', $value );
                foreach ( $parts as $part ) {
                    $part = trim( $part );
                    if ( ! empty( $part ) ) {
                        $term_names[] = $part;
                    }
                }
            }
            wp_set_object_terms( $post_id, $term_names, 'anime_series_tax', false );
        }
        
        // 智慧判斷：YourAnimes 網址是否有改變？
        $triggered_ya_sync = false;
        $ya_triggered_yt_sync = false;
        if ( ! empty( $new_ya_url ) && $new_ya_url !== $old_ya_url ) {
            if ( class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
                $fetcher = new Anime_Sync_YourAnimes_Fetcher();
                $res = $fetcher->sync_post( $post_id, true );
                if ( is_wp_error( $res ) ) {
                    wp_send_json_error( [ 'message' => '資料已儲存，但 YourAnimes 同步失敗：' . $res->get_error_message() ] );
                } else if ( is_array( $res ) ) {
                    foreach ( $res as $msg ) {
                        if ( mb_strpos( $msg, 'YouTube 自動同步' ) !== false ) {
                            $ya_triggered_yt_sync = true;
                            break;
                        }
                    }
                }
                $triggered_ya_sync = true;
            }
        }
        
        // 智慧判斷：YouTube 網址是否有改變？ (如果剛剛 YA 同步沒有連帶執行 YT 同步，但 YT 網址有變，就要單獨跑 YT 同步)
        $triggered_yt_sync = false;
        if ( ! $ya_triggered_yt_sync && ! empty( $new_yt_url ) && $new_yt_url !== $old_yt_url ) {
            if ( class_exists( 'Anime_Sync_YouTube_Playlist_Sync' ) ) {
                $yt_sync = new Anime_Sync_YouTube_Playlist_Sync();
                $res = $yt_sync->sync_post( $post_id, true );
                // 注意：YouTube 同步回傳的是陣列 ['added' => x, 'skipped' => y, 'msg' => '...']
                if ( is_array( $res ) && isset( $res['msg'] ) && mb_strpos( $res['msg'], '錯誤' ) !== false ) {
                    wp_send_json_error( [ 'message' => '資料已儲存，但 YouTube 同步失敗：' . $res['msg'] ] );
                }
                $triggered_yt_sync = true;
            }
        }
        
        wp_send_json_success( [
            'message' => '儲存成功！',
            'action'  => ( $triggered_ya_sync || $triggered_yt_sync ) ? 'synced' : 'saved'
        ] );
    }

    private function register_shortcuts(): void {
        acf_add_local_field_group( [
            'key'                   => 'group_anime_shortcuts',
            'title'                 => '🚀 編輯捷徑方塊',
            'fields'                => [
                [
                    'key'     => 'field_shortcut_anime_title_chinese',
                    'label'   => '中文標題 (台灣繁體)',
                    'name'    => 'shortcut_anime_title_chinese',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_title_simplified',
                    'label'   => '簡體標題',
                    'name'    => 'shortcut_anime_title_simplified',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_title_native',
                    'label'   => '日文原名',
                    'name'    => 'shortcut_anime_title_native',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_series_tax',
                    'label'   => '系列 (多個請用半形逗號 , 分隔)',
                    'name'    => 'shortcut_anime_series_tax',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_youranimes_url',
                    'label'   => 'YourAnimes 網址',
                    'name'    => 'shortcut_anime_youranimes_url',
                    'type'    => 'url',
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_yt_playlist_url',
                    'label'   => 'YouTube 播放清單網址',
                    'name'    => 'shortcut_anime_yt_playlist_url',
                    'type'    => 'url',
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_shortcut_anime_trailer_url',
                    'label'        => 'YouTube 預告片網址',
                    'name'         => 'shortcut_anime_trailer_url',
                    'type'    => 'textarea',
                    'rows'    => 3,
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_online_watch',
                    'label'   => '線上看（YouTube 嵌入）',
                    'name'    => 'shortcut_anime_online_watch',
                    'type'    => 'textarea',
                    'rows'    => 3,
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_wikipedia_url',
                    'label'   => '外部連結-Wikipedia 頁面',
                    'name'    => 'shortcut_anime_wikipedia_url',
                    'type'    => 'url',
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_shortcut_anime_tw_distributor',
                    'label'         => '台灣代理商/發行商',
                    'name'          => 'shortcut_anime_tw_distributor',
                    'type'          => 'select',
                    'choices'       => [
                        ''            => '── 請選擇 ──',
                        'muse'        => '木棉花',
                        'medialink'   => '曼迪傳播',
                        'linbang'     => '羚邦',
                        'tropic'      => '回歸線娛樂',
                        'proware'     => '普威爾',
                        'kadokawa'    => '台灣角川',
                        'gungho'      => '群英社',
                        'tien'        => '提恩傳媒',
                        'garage'      => '車庫娛樂',
                        'carsun'      => '采昌國際',
                        'jbf'         => '日本橋文化(JBF)',
                        'righttime'   => '利得時代(Right Time)',
                        'aniplus'     => 'ANIPLUS Asia',
                        'tongli'      => '東立出版社',
                        'remow'       => 'REMOW',
                        'gaga'        => 'GaGa OOLala',
                        'other'       => '其他(自訂)',
                    ],
                    'default_value' => '',
                    'allow_null'    => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_shortcut_anime_tw_distributor_custom',
                    'label'         => '台灣代理商(自訂名稱)',
                    'name'          => 'shortcut_anime_tw_distributor_custom',
                    'type'          => 'text',
                    'wrapper'       => [ 'width' => '25' ],
                ],
            ],
            'location'              => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'anime',
                    ],
                ],
            ],
            'menu_order'            => 0,
            'position'              => 'acf_after_title',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen'        => '',
            'active'                => true,
            'description'           => '',
        ] );
    }

    private function register_ai_shortcuts(): void {
        acf_add_local_field_group( [
            'key'                   => 'group_anime_shortcuts_ai',
            'title'                 => '🤖 AI 輔助捷徑方塊',
            'fields'                => [
                [
                    'key'     => 'field_shortcut_ai_top_ui',
                    'label'   => '',
                    'name'    => '',
                    'type'    => 'message',
                    'message' => '<div id="asp-ai-top-ui"></div><div id="asp-ai-console" style="display:none; margin-top:10px; padding:10px; background:#1e1e1e; color:#00ff00; font-family:monospace; font-size:13px; border-radius:4px; max-height:200px; overflow-y:auto; overflow-x:hidden; word-break:break-all;"></div>',
                    'new_lines' => '',
                    'esc_html' => 0,
                ],
                [
                    'key'     => 'field_shortcut_ai_generate_synopsis',
                    'label'   => '啟用 AI 生成簡介',
                    'name'    => 'shortcut_ai_generate_synopsis',
                    'type'    => 'true_false',
                    'ui'      => 1,
                    'default_value' => 1,
                    'wrapper' => [ 'width' => '20' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_synopsis_chinese',
                    'label'   => '中文簡介 (台灣繁體)',
                    'name'    => 'shortcut_anime_synopsis_chinese',
                    'type'    => 'textarea',
                    'rows'    => 4,
                    'wrapper' => [ 'width' => '80' ],
                ],
                [
                    'key'     => 'field_shortcut_ai_generate_faq',
                    'label'   => '啟用 AI 生成 FAQ',
                    'name'    => 'shortcut_ai_generate_faq',
                    'type'    => 'true_false',
                    'ui'      => 1,
                    'default_value' => 1,
                    'wrapper' => [ 'width' => '20' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_faq_json',
                    'label'   => 'FAQ JSON',
                    'name'    => 'shortcut_anime_faq_json',
                    'type'    => 'textarea',
                    'rows'    => 4,
                    'wrapper' => [ 'width' => '80' ],
                ],
                [
                    'key'     => 'field_shortcut_ai_generate_cast',
                    'label'   => '啟用 AI 生成 CAST',
                    'name'    => 'shortcut_ai_generate_cast',
                    'type'    => 'true_false',
                    'ui'      => 1,
                    'default_value' => 1,
                    'wrapper' => [ 'width' => '20' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_cast_json',
                    'label'   => 'CAST 角色資料 JSON',
                    'name'    => 'shortcut_anime_cast_json',
                    'type'    => 'textarea',
                    'rows'    => 4,
                    'wrapper' => [ 'width' => '80' ],
                ],
                [
                    'key'     => 'field_shortcut_ai_settings_ui',
                    'label'   => '',
                    'name'    => '',
                    'type'    => 'message',
                    'message' => '<div id="asp-ai-settings-container"></div>',
                    'new_lines' => '',
                    'esc_html' => 0,
                ],
            ],
            'location'              => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'anime',
                    ],
                ],
            ],
            'menu_order'            => 1, // 緊黏在區塊 1 下方
            'position'              => 'acf_after_title',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen'        => '',
            'active'                => true,
            'description'           => '',
        ] );
    }

    // =========================================================================
    // Helper:台灣串流平台定義
    // =========================================================================
    private function get_tw_platforms(): array {
        // ✅ [Registry] 改由 Anime_Sync_Streaming_Registry 統一管理
        // 新增平台只需修改 class-streaming-registry.php，此處無需改動
        return Anime_Sync_Streaming_Registry::get_acf_choices();
    }

        // =========================================================================
    // 靜態輔助方法
    // =========================================================================
    public static function get_auto_update_fields(): array {
        return [
            'anime_episodes_aired' => '已播集數',
            'anime_status'         => '播出狀態',
            'anime_next_airing'    => '下一集播出時間',
            'anime_score_anilist'  => 'AniList 評分',
            'anime_score_mal'      => 'MAL 評分',
            'anime_score_bangumi'  => 'Bangumi 評分',
            'anime_popularity'     => 'AniList 人氣數',
            'anime_end_date'       => '完結日期',
        ];
    }

    public static function get_enrich_fields(): array {
        return [
            'anime_cast_json'     => 'CAST 角色資料',
            'anime_staff_json'    => 'STAFF 製作資料',
            'anime_episodes_json' => '集數列表',
            'anime_themes'        => 'OP/ED 主題曲資料',
        ];
    }
    // =========================================================================
    // AI 輔助捷徑方塊功能
    // =========================================================================

    public function inject_ai_shortcut_scripts(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'anime' ) return;

        $user_id = get_current_user_id();
        $provider = get_user_meta($user_id, 'asp_ai_provider', true) ?: 'gemini';
        $api_key = get_user_meta($user_id, 'asp_ai_api_key', true) ?: '';
        $model = get_user_meta($user_id, 'asp_ai_model_name', true) ?: 'gemini-3.6-flash';

        ?>
        <style>
            #asp-ai-settings-accordion { margin-top: 20px; border: 1px solid #ccd0d4; background: #fff; }
            .asp-ai-settings-content { display: flex; align-items: center; gap: 15px; padding: 10px 15px; flex-wrap: wrap; }
            .asp-ai-settings-item { display: flex; align-items: center; gap: 8px; }
            .asp-ai-settings-item label { font-weight: bold; margin: 0; white-space: nowrap; }
            .asp-ai-settings-item input, .asp-ai-settings-item select { margin: 0 !important; max-width: 250px; }
            
            /* 壓縮版面留白 */
            #acf-group_anime_shortcuts_ai .inside, #acf-group_anime_shortcuts_ai > .inside, #acf-group_anime_shortcuts_ai .acf-fields { padding: 0 !important; margin: 0 !important; border: none !important; }
            #acf-group_anime_shortcuts_ai .acf-field { padding: 6px 12px !important; margin: 0 !important; }
            #acf-group_anime_shortcuts_ai .acf-label { margin-bottom: 3px !important; }
            

            #asp-ai-settings-accordion { margin: 0 !important; padding: 0 !important; border-top: 1px solid #ddd; border-left: none; border-right: none; border-bottom: none; transform: translateY(-1px); }
        </style>
        <script>
        jQuery(document).ready(function($) {
            function initAIPanel() {
                if ($('#asp-btn-ai-generate').length) return;

                // 1. 注入頂部雙按鈕
                var $header = $('#acf-group_anime_shortcuts_ai .postbox-header');
                if ($header.length === 0) $header = $('#acf-group_anime_shortcuts_ai .hndle');
                
                var $btnGroup = $('<div style="position: absolute; right: 215px; top: 50%; transform: translateY(-50%); z-index: 10; display:flex; gap: 8px; align-items: center;">' +
                    '<button type="button" id="asp-btn-ai-generate" class="button button-secondary" style="height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: flex; align-items: center; box-sizing: border-box;">✨ 執行 AI 輔助生成</button>' +
                    '<button type="button" id="asp-btn-ai-save" class="button button-primary" style="height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: flex; align-items: center; box-sizing: border-box;">💾 儲存 AI 輔助區塊</button>' +
                '</div>');;
                
                $header.css('position', 'relative').append($btnGroup);

                // 1.5 注入 Console 狀態監控面板 (放置在所有欄位最上方)
                if ($('#asp-ai-console-box').length === 0) {
                    var consoleHTML = '<div id="asp-ai-console-box" style="background:#1e1e1e; color:#0f0; padding:10px 15px; margin:0 !important; border-bottom:1px solid #444; border-top:none; height:100px; overflow-y:auto; font-family:monospace; line-height:1.4; width: 100%; box-sizing: border-box;">[系統就緒] AI 輔助生成模組已載入，等待指令...<br>請先在最底部「⚙️ AI 帳號設定面板」填入 API Key，再點擊上方按鈕開始生成！</div>' +
                        '<div style="background:#111; padding:4px 15px; border-bottom:1px solid #333; display:flex; align-items:center; gap:8px;">' +
                        '<label style="color:#888; font-size:11px; font-family:monospace; cursor:pointer; display:flex; align-items:center; gap:5px;" title="開啟後，每批次送出前會在 Console 印出完整的 System Prompt 與 User Prompt，方便確認 AI 確實收到正確指令">' +
                        '<input type="checkbox" id="asp-ai-debug-mode" style="margin:0;"> 🔍 Debug 模式（印出完整 AI 指令）' +
                        '</label></div>';
                    
                    var $fieldsContainer = $('#acf-group_anime_shortcuts_ai .acf-fields');
                    if ($fieldsContainer.length > 0) {
                        $fieldsContainer.prepend(consoleHTML);
                        // 強制消除 .acf-fields 自身的 padding（CSS 可能被 WP 預設蓋掉）
                        $fieldsContainer[0].style.setProperty('padding', '0', 'important');
                        $fieldsContainer[0].style.setProperty('margin', '0', 'important');
                        $fieldsContainer[0].style.setProperty('border', 'none', 'important');
                    } else {
                        $('#acf-group_anime_shortcuts_ai .inside').prepend(consoleHTML);
                    }
                }

                // 強制清除第一欄上方 / 最後一欄下方的 padding（用 setTimeout 等 ACF 完全渲染完）
                setTimeout(function() {
                    var $fields = $('#acf-group_anime_shortcuts_ai .acf-fields > .acf-field');
                    if ($fields.length > 0) {
                        // 針對最上方的兩個欄位 (Switch & Synopsis)
                        $fields.filter('[data-name="shortcut_ai_generate_synopsis"], [data-name="shortcut_anime_synopsis_chinese"]').each(function() {
                            this.style.setProperty('padding-top', '0', 'important');
                            this.style.setProperty('margin-top', '0', 'important');
                            $(this).find('.acf-label, .acf-input').each(function() {
                                this.style.setProperty('margin-top', '0', 'important');
                                this.style.setProperty('padding-top', '0', 'important');
                            });
                        });
                        
                        var $synopsisSwitchInput = $fields.filter('[data-name="shortcut_ai_generate_synopsis"]').find('.acf-input');
                        if ($synopsisSwitchInput.length > 0 && $('#asp-force-ai-translate').length === 0) {
                            $synopsisSwitchInput.css({ 'display': 'flex', 'align-items': 'center', 'gap': '10px' });
                            var $forceLabel = $('<label style="font-size:12px; display:flex; align-items:center; cursor:pointer; margin-left:15px;"><input type="checkbox" id="asp-force-ai-translate" style="margin:0 5px 0 0;">強制給AI翻譯</label>');
                            $forceLabel.on('click', function(e) { e.stopPropagation(); });
                            $forceLabel.find('#asp-force-ai-translate').on('click', function(e) { e.stopPropagation(); });
                            $synopsisSwitchInput.find('.acf-switch').after($forceLabel);
                        }
                        
                        // 針對最下方的兩個欄位 (Switch & CAST JSON)
                        $fields.filter('[data-name="shortcut_ai_generate_cast"], [data-name="shortcut_anime_cast_json"]').each(function() {
                            this.style.setProperty('padding-bottom', '0', 'important');
                            this.style.setProperty('margin-bottom', '0', 'important');
                            this.style.setProperty('border-bottom', 'none', 'important');
                            $(this).find('.acf-label, .acf-input, textarea').each(function() {
                                this.style.setProperty('margin-bottom', '0', 'important');
                                this.style.setProperty('padding-bottom', '0', 'important');
                            });
                        });
                    }
                }, 300);

                // 2. 注入底部設定面板 (直接掛載到區塊內容的最下方)
                if ($('#asp-ai-settings-accordion').length === 0) {
                    var settingsHTML = `
                    <div id="asp-ai-settings-accordion">
                        <div style="padding: 10px 15px; font-weight: bold; background: #f8f9fa; border-bottom: 1px solid #ccd0d4;">⚙️ AI 帳號設定面板</div>
                        <div class="asp-ai-settings-content">
                            <button type="button" id="asp-btn-ai-settings-save" class="button button-secondary">💾 儲存 AI 設定</button>
                            <span id="asp-ai-settings-msg" style="color:green; display:none; white-space:nowrap;">已儲存！</span>
                            
                            <div class="asp-ai-settings-item">
                                <label>AI 供應商</label>
                                <select id="asp_ai_provider">
                                    <option value="gemini" ${'<?php echo $provider; ?>'==='gemini'?'selected':''}>Google Gemini</option>
                                    <option value="openai" ${'<?php echo $provider; ?>'==='openai'?'selected':''}>OpenAI (ChatGPT)</option>
                                    <option value="claude" ${'<?php echo $provider; ?>'==='claude'?'selected':''}>Anthropic Claude</option>
                                </select>
                            </div>
                            
                            <div class="asp-ai-settings-item">
                                <label>API Key</label>
                                <input type="password" id="asp_ai_api_key" value="<?php echo esc_attr($api_key); ?>" placeholder="輸入您的金鑰...">
                            </div>
                            
                            <div class="asp-ai-settings-item">
                                <label>模型名稱</label>
                                <input type="text" id="asp_ai_model_name" value="<?php echo esc_attr($model); ?>" placeholder="gemini-3.6-flash">
                            </div>
                        </div>
                    </div>`;
                    $('#acf-group_anime_shortcuts_ai .inside').append(settingsHTML);
                }

                // 3. 鏡像複製 CAST 提示詞到左側
                var $castSwitchField = $('#acf-group_anime_shortcuts_ai .acf-field[data-name="shortcut_ai_generate_cast"]');
                if ($castSwitchField.length > 0 && $('#asp-mirrored-prompt').length === 0) {
                    // 尋找包含提示詞的來源 textarea
                    var $sourceTextarea = $('textarea').filter(function() {
                        return $(this).val().indexOf('你是熟悉台灣 ACG') > -1;
                    }).first();
                    
                    if ($sourceTextarea.length > 0) {
                        var mirroredValue = $sourceTextarea.val();
                        var $mirroredBox = $('<div id="asp-mirrored-prompt" style="margin-left: 10px; flex: 1; min-width: 0;">' + 
                            '<div style="font-size:9px; font-weight:bold; color:#888; margin-bottom:1px; line-height:1;">📋 提示詞(點擊複製)</div>' +
                            '<textarea readonly title="點擊全選複製" style="width:100%; height:80px; background:#f5f5f5; color:#444; font-size:11px; border:1px solid #ccc; resize:none; padding:2px 4px; box-sizing:border-box; line-height:1.2;" onclick="this.select();"></textarea>' + 
                            '</div>');
                        $mirroredBox.find('textarea').val(mirroredValue);
                        
                        var $inputWrap = $castSwitchField.find('.acf-input');
                        $inputWrap.css({
                            'display': 'flex',
                            'align-items': 'flex-start'
                        });
                        $inputWrap.append($mirroredBox);
                        
                        // 注入字典管理按鈕 (在開關正下方)
                        var $switch = $inputWrap.find('.acf-true-false').first();
                        if ($switch.length > 0) {
                            $switch.wrap('<div style="display:flex; flex-direction:column; gap:5px;"></div>');
                            $switch.after('<button type="button" id="asp-btn-manage-dict" class="button button-small" style="font-size:11px; padding:2px 8px; line-height:1.2; text-align:center;">📚 字典管理</button>');
                        }
                    }
                }
                
                // 4. 注入字典管理 Modal HTML
                if ($('#asp-dict-modal').length === 0) {
                    var modalHTML = `
                    <div id="asp-dict-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; justify-content:center; align-items:center;">
                        <div style="background:#fff; width:600px; max-width:90%; height:500px; max-height:90vh; display:flex; flex-direction:column; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                            <div style="padding:15px 20px; border-bottom:1px solid #e2e4e7; display:flex; justify-content:space-between; align-items:center; background:#f8f9fa; border-radius:8px 8px 0 0;">
                                <h3 style="margin:0; font-size:16px;">📚 CAST 翻譯字典管理</h3>
                                <button type="button" class="asp-dict-close" style="background:none; border:none; font-size:20px; cursor:pointer; padding:0; color:#666;">&times;</button>
                            </div>
                            <div style="padding:15px 20px; border-bottom:1px solid #e2e4e7; background:#fff; display:flex; gap:10px;">
                                <input type="text" id="asp-dict-search" placeholder="🔍 搜尋日文原文或中文譯名..." style="flex:1; padding:5px 10px;">
                                <button type="button" id="asp-dict-clear-aa" class="button" title="清除原文與譯名完全相同的無效記錄">🧹 清除 A=A</button>
                                <button type="button" id="asp-dict-save" class="button button-primary">💾 儲存修改</button>
                            </div>
                            <div id="asp-dict-list" style="flex:1; overflow-y:auto; padding:15px 20px; background:#f0f0f1; display:flex; flex-direction:column; gap:10px;">
                                <div style="text-align:center; padding:20px; color:#888;">正在載入字典...</div>
                            </div>
                        </div>
                    </div>`;
                    $('body').append(modalHTML);
                }
            }

            if (typeof acf !== 'undefined') {
                acf.addAction('ready', initAIPanel);
            }
            initAIPanel();
            
            // 字典管理事件綁定
            var fullDictData = { va: {}, char: {} };
            
            function renderDictList(filter = '') {
                var $list = $('#asp-dict-list');
                $list.empty();
                
                var items = [];
                $.each(fullDictData.va, function(k, v) { items.push({ type: 'va', key: k, val: v, label: '聲優' }); });
                $.each(fullDictData.char, function(k, v) { items.push({ type: 'char', key: k, val: v, label: '角色' }); });
                
                if (filter) {
                    var lowerFilter = filter.toLowerCase();
                    items = items.filter(i => i.key.toLowerCase().includes(lowerFilter) || i.val.toLowerCase().includes(lowerFilter));
                }
                
                if (items.length === 0) {
                    $list.append('<div style="text-align:center; padding:20px; color:#888;">' + (filter ? '找不到符合的結果' : '字典目前是空的') + '</div>');
                    return;
                }
                
                $.each(items, function(i, item) {
                    var displayKey = item.type === 'char' ? item.key.replace('|||', ' - ') : item.key;
                    var html = `
                    <div style="display:flex; align-items:center; background:#fff; padding:8px 12px; border-radius:4px; border:1px solid #ddd; gap:10px;">
                        <span style="font-size:11px; padding:2px 4px; background:#e0e0e0; border-radius:3px;">${item.label}</span>
                        <div style="flex:1; font-weight:bold; font-size:13px; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${displayKey}">${displayKey}</div>
                        <span style="color:#888;">➔</span>
                        <input type="text" class="asp-dict-input" data-type="${item.type}" data-key="${item.key}" value="${item.val}" style="flex:1; padding:3px 8px; font-size:13px;">
                    </div>`;
                    $list.append(html);
                });
            }

            $(document).on('click', '#asp-dict-clear-aa', function(e) {
                e.preventDefault();
                if (!confirm('警告：此功能會刪除字典中「原文」與「譯名」完全相同的角色紀錄。\n（此功能僅對「角色」有效，聲優紀錄不會受到影響）。\n\n您確定要繼續清除嗎？')) {
                    return;
                }
                
                var clearCount = 0;
                $.each(fullDictData.char, function(k, v) {
                    var oriName = k.indexOf('|||') !== -1 ? k.split('|||')[1] : k;
                    if (oriName === v) {
                        delete fullDictData.char[k];
                        clearCount++;
                    }
                });
                
                alert('已清除 ' + clearCount + ' 筆 A=A 的紀錄！\n請記得點擊右方的「💾 儲存修改」才會正式寫入檔案。');
                renderDictList($('#asp-dict-search').val());
            });

            $(document).on('click', '#asp-btn-manage-dict', function(e) {
                e.preventDefault();
                $('#asp-dict-modal').css('display', 'flex');
                $('#asp-dict-list').html('<div style="text-align:center; padding:20px; color:#888;">正在載入字典...</div>');
                $('#asp-dict-search').val('');
                
                $.post(ajaxurl, {
                    action: 'asp_cast_dict_load',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        fullDictData = res.data || { va: {}, char: {} };
                        renderDictList();
                    } else {
                        $('#asp-dict-list').html('<div style="color:red; text-align:center; padding:20px;">載入失敗：' + res.data + '</div>');
                    }
                });
            });

            $(document).on('click', '.asp-dict-close', function(e) {
                e.preventDefault();
                $('#asp-dict-modal').hide();
            });

            $(document).on('input', '#asp-dict-search', function(e) {
                renderDictList($(this).val());
            });

            $(document).on('click', '#asp-dict-save', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true).text('儲存中...');
                
                // 收集畫面上被修改的值
                $('.asp-dict-input').each(function() {
                    var type = $(this).data('type');
                    var key = String($(this).data('key'));
                    var val = $(this).val().trim();
                    if (fullDictData[type] && fullDictData[type][key] !== undefined) {
                        fullDictData[type][key] = val;
                    }
                });
                
                $.post(ajaxurl, {
                    action: 'asp_cast_dict_save',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    dict_data: JSON.stringify(fullDictData)
                }, function(res) {
                    $btn.prop('disabled', false).text('💾 儲存修改');
                    if (res.success) {
                        alert('字典已成功儲存！');
                    } else {
                        alert('儲存失敗：' + res.data);
                    }
                });
            });

            function logAI(msg, isError = false) {
                var $console = $('#asp-ai-console-box');
                var time = new Date().toLocaleTimeString('zh-TW', { hour12: false });
                var color = isError ? '#ff4d4d' : '#00ff00';
                $console.append(`<div style="color:${color}">[${time}] ${msg}</div>`);
                $console.scrollTop($console[0].scrollHeight);
            }

            // 儲存設定
            $(document).on('click', '#asp-btn-ai-settings-save', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('儲存中...');
                var f_synopsis = acf.getField($('.acf-field[data-name="shortcut_ai_generate_synopsis"]'));
                var f_faq      = acf.getField($('.acf-field[data-name="shortcut_ai_generate_faq"]'));
                var f_cast     = acf.getField($('.acf-field[data-name="shortcut_ai_generate_cast"]'));
                
                var pref_synopsis = f_synopsis ? f_synopsis.val() : 1;
                var pref_faq      = f_faq ? f_faq.val() : 1;
                var pref_cast     = f_cast ? f_cast.val() : 1;

                $.post(ajaxurl, {
                    action: 'asp_shortcut_ai_save_user',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    provider: $('#asp_ai_provider').val(),
                    api_key: $('#asp_ai_api_key').val(),
                    model: $('#asp_ai_model_name').val(),
                    pref_synopsis: pref_synopsis,
                    pref_faq: pref_faq,
                    pref_cast: pref_cast
                }, function(res) {
                    btn.prop('disabled', false).text('💾 儲存 AI 設定');
                    if(res.success) {
                        $('#asp-ai-settings-msg').show().delay(2000).fadeOut();
                    } else {
                        alert('儲存失敗: ' + (res.data || ''));
                    }
                });
            });

            // 儲存文章區塊
            $(document).on('click', '#asp-btn-ai-save', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('⏳ 儲存中...');
                
                var fields = {};
                acf.getFields({parent: $('#acf-group_anime_shortcuts_ai')}).forEach(function(field) {
                    if (field.data.name && field.data.name.startsWith('shortcut_anime_')) {
                        fields[field.data.name] = field.val();
                    }
                });

                $.post(ajaxurl, {
                    action: 'asp_shortcut_ai_save_post',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    post_id: $('#post_ID').val(),
                    fields: fields
                }, function(res) {
                    btn.prop('disabled', false).text('💾 儲存 AI 輔助區塊');
                    if(res.success) {
                        logAI('✅ AI 捷徑區塊已成功儲存至文章！網頁即將重整...');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        logAI('❌ 儲存失敗: ' + (res.data || ''), true);
                    }
                });
            });

            async function processCastTranslation(jsonStr, animeTitle, targetField) {
                var taskName = 'CAST';
                try {
                    var parsed = JSON.parse(jsonStr);
                    if (!Array.isArray(parsed)) throw new Error('Root is not array');
                } catch(e) {
                    logAI(`⚠️ [CAST] 捷徑框內的 JSON 格式無效，無法解析。`, true);
                    return;
                }
                
                // 1. 萃取唯一名單 (雙軌)
                var uniqueVa = {};
                var uniqueChar = {};
                
                // 嘗試獲取系列名稱作為 namespace，讓同系列作品(如劇場版)可共用角色字典
                var f_series = acf.getField($('.acf-field[data-name="shortcut_anime_series_tax"]'));
                var seriesName = f_series && f_series.val() ? String(f_series.val()).trim() : '';
                
                // 如果 ACF 欄位沒抓到 (可能是在原生 metabox 剛輸入還沒存檔)，則直接抓取原生 taxonomy 隱藏欄位
                if (!seriesName) {
                    var $nativeTax = $('#tax-input-anime_series_tax');
                    if ($nativeTax.length) {
                        seriesName = $nativeTax.val().trim();
                    }
                }
                
                var namespace = seriesName ? seriesName.split(',')[0].trim() : animeTitle;
                
                parsed.forEach(item => {
                    var charName = item.name ? String(item.name).trim() : '';
                    if (charName) {
                        var charKey = namespace + '|||' + charName;
                        uniqueChar[charKey] = charName;
                    }
                    if (item.voice_actors && Array.isArray(item.voice_actors)) {
                        item.voice_actors.forEach(va => {
                            var vaName = va.name ? va.name.trim() : '';
                            if (vaName) uniqueVa[vaName] = vaName;
                        });
                    }
                });
                
                var charKeys = Object.keys(uniqueChar);
                var vaKeys = Object.keys(uniqueVa);
                var totalNames = charKeys.length + vaKeys.length;
                
                logAI(`▶️ [CAST] 解析完成。共發現 ${charKeys.length} 個角色，${vaKeys.length} 位聲優 (總計去重後 ${totalNames} 筆)。`);
                if (totalNames === 0) {
                    logAI(`✅ [CAST] 無需翻譯的名稱。`);
                    return;
                }
                
                // 2. 準備分批
                var allItems = [];
                charKeys.forEach(k => allItems.push({ type: 'char', key: k, text: uniqueChar[k] }));
                vaKeys.forEach(k => allItems.push({ type: 'va', key: k, text: uniqueVa[k] }));
                
                var batchSize = 150;
                var globalMapping = { va: {}, char: {} };
                
                var f_ori_name = acf.getField($('.acf-field[data-name="anime_title_native"]'));
                var oriName = f_ori_name ? String(f_ori_name.val()) : '';
                
                var f_source = acf.getField($('.acf-field[data-name="anime_source"]'));
                var sourceVal = f_source && f_source.val() ? f_source.val() : '';
                var source = (typeof sourceVal === 'object' && sourceVal !== null && sourceVal.label) ? sourceVal.label : String(sourceVal);
                
                var f_format = acf.getField($('.acf-field[data-name="anime_format"]'));
                var formatVal = f_format && f_format.val() ? f_format.val() : '';
                var format = (typeof formatVal === 'object' && formatVal !== null && formatVal.label) ? formatVal.label : String(formatVal);
                
                var f_season = acf.getField($('.acf-field[data-name="anime_season"]'));
                var seasonVal = f_season && f_season.val() ? f_season.val() : '';
                var season = (typeof seasonVal === 'object' && seasonVal !== null && seasonVal.label) ? seasonVal.label : String(seasonVal);
                
                var f_year = acf.getField($('.acf-field[data-name="anime_season_year"]'));
                var year = f_year ? String(f_year.val()) : '';
                
                var extraContext = `日文原名：${oriName}／原作來源：${source}／作品類型：${format}／播出季度：${season}／播出年份：${year}`;
                
                logAI(`▶️ [CAST] 擷取的背景特徵：【作品名稱】${animeTitle} 【補充】${extraContext}`);
                logAI(`▶️ [CAST] 開始分批查證 (每批 ${batchSize} 筆)...`);
                
                for (var i = 0; i < allItems.length; i += batchSize) {
                    var batch = allItems.slice(i, i + batchSize);
                    var batchNum = Math.floor(i / batchSize) + 1;
                    var totalBatches = Math.ceil(allItems.length / batchSize);
                    
                    logAI(`▶️ [CAST] 正在處理第 ${batchNum}/${totalBatches} 批 (${batch.length} 筆)...`);
                    
                    var success = false;
                    var retries = 0;
                    var isDebug = $('#asp-ai-debug-mode').is(':checked');
                    
                    while (!success && retries <= 2) {
                        try {
                            // Debug 模式：印出本批次即將送出的完整名單
                            if (isDebug && retries === 0) {
                                var debugList = batch.map((item, idx) => {
                                    var label = item.type === 'va' ? '【聲優】' : '【角色】';
                                    return (idx + 1) + '. ' + label + ' ' + item.text;
                                }).join('\n');
                                logAI(`🔍 [DEBUG] 批次 ${batchNum} System Prompt:\n----\n你是熟悉台灣 ACG 圈譯名的翻譯校對員...(已儲存在後端，請查看 debug_prompts)\n----`);
                                logAI(`🔍 [DEBUG] 批次 ${batchNum} User Prompt:\n----\n【作品名稱】${animeTitle}\n【補充辨識】${extraContext}\n\n請查證以下名單：\n${debugList}\n----`);
                            }
                            
                            var res = await $.post(ajaxurl, {
                                action: 'asp_shortcut_ai_cast_translate',
                                nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                                title: animeTitle,
                                context: extraContext,
                                items: JSON.stringify(batch),
                                debug: isDebug ? 1 : 0
                            });
                            
                            if (res.success && res.data && res.data.mapping) {
                                if (res.data.stats) {
                                    logAI(`▶️ [CAST] 批次 ${batchNum} 完成：快取命中 ${res.data.stats.cached} 筆，實際交由 AI 翻譯 ${res.data.stats.api} 筆。`);
                                }
                                // Debug 模式：印出後端確認收到的完整 prompt（Server Side 視角）
                                if (isDebug && res.data.debug_prompts) {
                                    logAI(`🔍 [DEBUG][後端確認] System Prompt 全文：\n====\n${res.data.debug_prompts.system}\n====`);
                                    logAI(`🔍 [DEBUG][後端確認] User Prompt 全文：\n====\n${res.data.debug_prompts.user}\n====`);
                                }
                                
                                Object.assign(globalMapping.va, res.data.mapping.va || {});
                                Object.assign(globalMapping.char, res.data.mapping.char || {});
                                
                                var missing = 0;
                                batch.forEach(item => {
                                    if (!res.data.mapping[item.type] || !res.data.mapping[item.type][item.key]) {
                                        missing++;
                                    }
                                });
                                
                                if (missing > 0 && retries < 2) {
                                    logAI(`⚠️ [CAST] 批次 ${batchNum} 發現 ${missing} 筆遺漏，發起第 ${retries + 1} 次重試...`, true);
                                    batch = batch.filter(item => !res.data.mapping[item.type] || !res.data.mapping[item.type][item.key]);
                                    retries++;
                                    continue;
                                } else {
                                    if (missing > 0) logAI(`⚠️ [CAST] 批次 ${batchNum} 經重試仍有 ${missing} 筆遺漏，將保留原文。`, true);
                                    success = true;
                                    
                                    // 若這批有實際交由 AI 翻譯，且不是最後一批，強制等待 3 秒以避免觸發 429 頻率限制
                                    if (res.data.stats && res.data.stats.api > 0 && batchNum < totalBatches) {
                                        logAI(`⏳ [CAST] 為保護 API 額度並避免頻率過高，自動冷卻等待 3 秒...`);
                                        await new Promise(r => setTimeout(r, 3000));
                                    }
                                }
                            } else {
                                var errMsg = res.data || '未知錯誤';
                                if (errMsg.includes('quota') || errMsg.includes('429')) {
                                    logAI(`❌ [CAST] API 額度耗盡或頻率過高。進度已保存在快取中，請稍候重新點擊生成接續！`, true);
                                    return;
                                }
                                throw new Error(errMsg);
                            }
                        } catch (err) {
                            if (retries < 2) {
                                retries++;
                                logAI(`⚠️ [CAST] 批次 ${batchNum} 錯誤 (${err.message})，3秒後重試 (${retries}/2)...`, true);
                                await new Promise(r => setTimeout(r, 3000));
                            } else {
                                logAI(`❌ [CAST] 批次 ${batchNum} 多次重試失敗，終止。`, true);
                                return;
                            }
                        }
                    }
                }
                
                logAI(`▶️ [CAST] 所有批次完成，正在替換原始 JSON...`);
                
                parsed.forEach(item => {
                    var charName = item.name ? String(item.name).trim() : '';
                    if (charName) {
                        var charKey = namespace + '|||' + charName;
                        if (globalMapping.char && globalMapping.char[charKey]) {
                            item.name = globalMapping.char[charKey];
                        }
                    }
                    if (item.voice_actors && Array.isArray(item.voice_actors)) {
                        item.voice_actors.forEach(va => {
                            var vaName = va.name ? va.name.trim() : '';
                            if (vaName && globalMapping.va && globalMapping.va[vaName]) {
                                va.name = globalMapping.va[vaName];
                            }
                        });
                    }
                });
                
                var finalJson = JSON.stringify(parsed);
                var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                if (f_target) f_target.val(finalJson);
                var nativeField = targetField.replace('shortcut_', '');
                var f_native = acf.getField($('.acf-field[data-name="' + nativeField + '"]'));
                if (f_native) f_native.val(finalJson);
                
                logAI(`✅ [CAST] 替換完成並已寫回欄位！`);
            }

            // 執行生成
            $(document).on('click', '#asp-btn-ai-generate', async function(e) {
                e.preventDefault();
                var btn = $(this);
                if(!$('#asp_ai_api_key').val()) {
                    alert('請先在底部設定您的 API Key！');
                    return;
                }
                
                // 檢查哪些開關被打開
                var tasks = [];
                var f_gen_synopsis = acf.getField($('.acf-field[data-name="shortcut_ai_generate_synopsis"]'));
                var f_gen_faq      = acf.getField($('.acf-field[data-name="shortcut_ai_generate_faq"]'));
                var f_gen_cast     = acf.getField($('.acf-field[data-name="shortcut_ai_generate_cast"]'));
                
                if (f_gen_synopsis && f_gen_synopsis.val()) tasks.push('synopsis');
                if (f_gen_faq && f_gen_faq.val()) tasks.push('faq');
                if (f_gen_cast && f_gen_cast.val()) tasks.push('cast');
                
                if(tasks.length === 0) {
                    alert('請至少開啟一個要生成的項目！');
                    return;
                }

                // [優化 2] 每次生成前自動清空 Log 面板
                $('#asp-ai-console-box').empty();

                btn.prop('disabled', true).text('⏳ 生成中...');
                logAI('🚀 開始執行 AI 生成工作流...');
                
                try {
                    for(var i=0; i<tasks.length; i++) {
                        var task = tasks[i];
                        var sysPrompt = '';
                        var userPrompt = '';
                        var targetField = '';
                        var taskName = '';
                        
                        var f_title = acf.getField($('.acf-field[data-name="shortcut_anime_title_chinese"]'));
                        var title = (f_title && f_title.val()) ? String(f_title.val()).trim() : $('#title').val();
                        
                        if (task === 'synopsis') {
                            taskName = '中文簡介';
                            targetField = 'shortcut_anime_synopsis_chinese';
                            var $descTextarea = $('.acf-field[data-name="anime_synopsis_chinese"] .description textarea');
                            sysPrompt = $descTextarea.length ? $descTextarea.val().trim() : $('.acf-field[data-name="anime_synopsis_chinese"] .description').text().trim();
                            var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                            userPrompt = (f_target && f_target.val()) ? String(f_target.val()).trim() : '';
                            var forceTranslate = $('#asp-force-ai-translate').is(':checked');

                            if (userPrompt === '') {
                                userPrompt = '但目前沒有提供原文草稿。請直接上網搜尋該部作品的簡介，並撰寫一份繁體中文版本的簡介。作品名稱：' + title + '\n\n⚠️ 重要規則：請直接輸出純簡介內容，絕對不要加上「以下是...的簡介」或任何開場白與對話詞彙。';
                            } else {
                                var isJP = /[\u3040-\u30ff]/.test(userPrompt);
                                var isSC = /[个们这会发说样么进觉动视频观听剧弹网络传统战击异龙剑门飞机关爱与为从来给让设计创办产认写读记买卖国军队员宝梦灵处总极难尽仅虽迟远贫穷华丽]/.test(userPrompt);
                                var englishCharCount = (userPrompt.match(/[a-zA-Z]/g) || []).length;
                                var isEN = englishCharCount > (userPrompt.length * 0.4);
                                
                                var mainlandTerms = /(視頻|軟件|網絡|質量|激活|屏幕|鼠標|程序|服務器|硬盤|默認|賬號|鏈接|彈幕|B站|UP主|番劇|追番|補番|製作組|製作方|譯製|二創|三創|鬼畜|小夥伴|手辦|病嬌|網盤|高清)/;
                                var hasMainlandTerms = mainlandTerms.test(userPrompt);

                                if (forceTranslate) {
                                    userPrompt = "請將以下原文翻譯並潤飾成「台灣繁體中文」，用語需符合台灣 ACG 圈習慣。請直接輸出結果，不要加上任何額外的對話詞彙或解釋。\n\n原文草稿：\n" + userPrompt;
                                } else if (isJP || isSC || isEN) {
                                    userPrompt = "請將以下原文翻譯並潤飾成「台灣繁體中文」，用語需符合台灣 ACG 圈習慣。請直接輸出結果，不要加上任何額外的對話詞彙或解釋。\n\n原文草稿：\n" + userPrompt;
                                } else if (hasMainlandTerms) {
                                    userPrompt = "以下是一篇繁體中文簡介，但包含大陸用語。請將其轉換並潤飾為「台灣 ACG 圈慣用語」的台灣繁體中文。請直接輸出結果，不要加上任何額外的對話詞彙或解釋。\n\n原文草稿：\n" + userPrompt;
                                } else {
                                    logAI(`✅ [${taskName}] 偵測到純繁體中文且無大陸用語，已自動跳過，不消耗 AI 額度。`);
                                    continue;
                                }
                            }
                        } else if (task === 'faq') {
                            taskName = 'FAQ';
                            targetField = 'shortcut_anime_faq_json';
                            var $descTextarea = $('.acf-field[data-name="anime_faq_json"] .description textarea');
                            sysPrompt = $descTextarea.length ? $descTextarea.val().trim() : $('.acf-field[data-name="anime_faq_json"] .description').text().trim();
                            userPrompt = '請嚴格依照上述規則，直接輸出 JSON 陣列，不要包含任何開場白或解釋。';

                            var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                            var currentFaqVal = (f_target && f_target.val()) ? String(f_target.val()).trim() : '';
                            
                            // 去除字串內的空白與換行，避免遇到 [ \n ] 被誤判為「已有資料」
                            var compactFaq = currentFaqVal.replace(/\s/g, '');
                            
                            if (currentFaqVal !== '' && compactFaq !== '[]') {
                                logAI(`✅ [${taskName}] 偵測到已有 FAQ 資料，已自動跳過，不消耗 AI 額度。`);
                                continue;
                            }
                        } else if (task === 'cast') {
                            taskName = 'CAST';
                            targetField = 'shortcut_anime_cast_json';
                            var $descTextarea = $('.acf-field[data-name="anime_cast_json"] .description textarea');
                            sysPrompt = $descTextarea.length ? $descTextarea.val().trim() : $('.acf-field[data-name="anime_cast_json"] .description').text().trim();
                            var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                            userPrompt = (f_target && f_target.val()) ? String(f_target.val()).trim() : '';
                            if (userPrompt === '') {
                                logAI(`⚠️ [${taskName}] 捷徑框內沒有提供原始 JSON，已自動跳過，不消耗 AI 額度。`);
                                continue;
                            }
                        }
                        
                        logAI(`▶️ 正在生成 [${taskName}]...`);
                        
                        try {
                            if (task === 'cast') {
                                await processCastTranslation(userPrompt, title, targetField);
                                
                                // 若 CAST 後還有其他任務，也要經過冷卻，以免觸發 429
                                if (i < tasks.length - 1) {
                                    logAI(`⏳ 避免請求過密，等待冷卻 2 秒後繼續下一個任務...`);
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                }
                                continue;
                            }

                            // Debug 模式：送出前在 Console 印出完整指令
                            var isDebug = $('#asp-ai-debug-mode').is(':checked');
                            if (isDebug) {
                                logAI(`🔍 [DEBUG][${taskName}] System Prompt:\n====\n${sysPrompt}\n====`);
                                logAI(`🔍 [DEBUG][${taskName}] User Prompt:\n====\n${userPrompt}\n====`);
                            }
                            
                            var res = await $.post(ajaxurl, {
                                action: 'asp_shortcut_ai_generate',
                                nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                                system_prompt: sysPrompt,
                                user_prompt: userPrompt,
                                debug: isDebug ? 1 : 0
                            });
                            
                            // [優化 3] 更嚴謹的空字串防呆判斷
                            if (res.success && res.data && typeof res.data.result === 'string') {
                                var text = res.data.result;
                                // Debug 模式：印出後端確認收到的完整 prompt
                                if (isDebug && res.data.debug_prompts) {
                                    logAI(`🔍 [DEBUG][${taskName}][後端確認] System Prompt：\n====\n${res.data.debug_prompts.system}\n====`);
                                    logAI(`🔍 [DEBUG][${taskName}][後端確認] User Prompt：\n====\n${res.data.debug_prompts.user}\n====`);
                                }
                                
                                // [優化 4] 智慧判斷 AI 是否找不到資料
                                var isFailed = false;
                                var failMsg = '';

                                if (task === 'faq') {
                                    // 去除可能包覆的 markdown 語法
                                    text = text.replace(/```[a-zA-Z]*\n?/g, '').replace(/```/g, '').trim();
                                    var firstBracket = text.indexOf('[');
                                    var lastBracket = text.lastIndexOf(']');
                                    
                                    if (firstBracket === -1 || lastBracket === -1 || lastBracket <= firstBracket) {
                                        isFailed = true;
                                        failMsg = '未能產出有效的 JSON 格式';
                                    } else {
                                        // 擷取陣列字串
                                        text = text.substring(firstBracket, lastBracket + 1);
                                        try {
                                            var parsed = JSON.parse(text);
                                            // 如果成功解析，則轉換為壓縮格式
                                            text = JSON.stringify(parsed);
                                            if (text === '[]') {
                                                isFailed = true;
                                                failMsg = 'AI 回傳了空陣列 (查無資料)';
                                            }
                                        } catch (e) {
                                            // 解析失敗，可能是提取範圍包含了多餘文字 (如來源說明中包含了 ']')
                                            isFailed = true;
                                            failMsg = '未能產出有效的 JSON 格式 (夾雜無法解析的字串)';
                                        }
                                    }
                                } else if (task === 'synopsis') {
                                    if (text.length < 60 && (text.includes('抱歉') || text.includes('找不到') || text.includes('查無') || text.includes('無法') || text.includes('沒有'))) {
                                        isFailed = true;
                                        failMsg = 'AI 回報找不到相關資料';
                                    }
                                }

                                if (isFailed) {
                                    logAI(`⚠️ [${taskName}] ${failMsg}，已自動跳過寫入。(AI 原始回覆: ${res.data.result.replace(/\n/g, ' ').substring(0, 40)}...)`, true);
                                } else {
                                    var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                                    if (f_target) {
                                        f_target.val(text);
                                        logAI(`✅ [${taskName}] 生成完畢！(獲得 ${text.length} 字元) 並成功填入捷徑框！`);
                                    } else {
                                        logAI(`⚠️ [${taskName}] 警告：找不到名稱為 ${targetField} 的欄位，無法填入！(獲得 ${text.length} 字元)`, true);
                                        // 嘗試使用舊方法
                                        if (acf.getField(targetField)) acf.getField(targetField).val(text);
                                    }
                                    
                                    // [優化 1] 即時同步更新底層原生欄位，避免 Race Condition
                                    var nativeField = targetField.replace('shortcut_', '');
                                    var f_native = acf.getField($('.acf-field[data-name="' + nativeField + '"]'));
                                    if (f_native) {
                                        f_native.val(text);
                                        logAI(`✅ [${taskName}] 已同步回填至底層原生欄位！`);
                                    }
                                }
                            } else {
                                var errorMsg = res.data || '未知錯誤';
                                if (typeof errorMsg === 'string' && errorMsg.toLowerCase().includes('quota')) {
                                    errorMsg += ' (💡 提示：這通常代表您的 AI 模型免費額度已耗盡，或請求過於頻繁。請稍後再試，或前往 Google AI Studio 檢查/更換 API Key 方案。)';
                                }
                                logAI(`❌ [${taskName}] 發生錯誤: ` + errorMsg, true);
                            }
                        } catch(err) {
                            // [優化 3] 捕捉並印出具體錯誤細節
                            var errDetail = err.responseText ? err.responseText : (err.statusText || '未知錯誤');
                            if (typeof errDetail === 'string' && errDetail.toLowerCase().includes('quota')) {
                                errDetail += ' (💡 提示：這通常代表您的 AI 模型免費額度已耗盡，或請求過於頻繁。請稍後再試，或前往 Google AI Studio 檢查/更換 API Key 方案。)';
                            }
                            logAI(`❌ [${taskName}] 請求失敗，請檢查網路連線或 API Key 狀態。細節：${errDetail}`, true);
                        }

                        // [新增] 冷卻時間機制：如果不是最後一項任務，則等待 2 秒避免觸發 Google API Rate Limit
                        if (i < tasks.length - 1) {
                            logAI(`⏳ 避免請求過密，等待冷卻 2 秒後繼續下一個任務...`);
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                    }
                    
                    logAI('🎉 所有選定項目生成工作流已結束！確認無誤後記得按右邊的儲存喔！');
                } finally {
                    // 不論任何情況（正常完成、提早 return、未預期例外）都確保按鈕被解鎖
                    btn.prop('disabled', false).text('🤖 執行 AI 輔助生成');
                }
            });
        });
        </script>
        <?php
    }

    public function ajax_shortcut_ai_save_user(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error('權限不足');
        
        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'asp_ai_provider', sanitize_text_field($_POST['provider']) );
        update_user_meta( $user_id, 'asp_ai_api_key', sanitize_text_field($_POST['api_key']) );
        update_user_meta( $user_id, 'asp_ai_model_name', sanitize_text_field($_POST['model']) );
        
        if ( isset($_POST['pref_synopsis']) ) update_user_meta( $user_id, 'asp_ai_pref_shortcut_ai_generate_synopsis', intval($_POST['pref_synopsis']) );
        if ( isset($_POST['pref_faq']) ) update_user_meta( $user_id, 'asp_ai_pref_shortcut_ai_generate_faq', intval($_POST['pref_faq']) );
        if ( isset($_POST['pref_cast']) ) update_user_meta( $user_id, 'asp_ai_pref_shortcut_ai_generate_cast', intval($_POST['pref_cast']) );
        
        wp_send_json_success();
    }

    public function ajax_shortcut_ai_save_post(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( '權限不足' );

        $fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash($_POST['fields']) : [];
        $mapping = [
            'shortcut_anime_synopsis_chinese' => 'anime_synopsis_chinese',
            'shortcut_anime_faq_json'         => 'anime_faq_json',
            'shortcut_anime_cast_json'        => 'anime_cast_json',
        ];

        foreach ( $mapping as $shortcut => $real_key ) {
            if ( isset( $fields[$shortcut] ) ) {
                update_post_meta( $post_id, $real_key, $fields[$shortcut] );
            }
        }
        wp_send_json_success();
    }

    public function ajax_shortcut_ai_generate(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );

        $user_id = get_current_user_id();
        $provider = get_user_meta( $user_id, 'asp_ai_provider', true ) ?: 'gemini';
        $api_key  = get_user_meta( $user_id, 'asp_ai_api_key', true );
        $model    = get_user_meta( $user_id, 'asp_ai_model_name', true );
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( '未設定 API Key' );
        }

        $debug = ! empty( $_POST['debug'] ) && intval( $_POST['debug'] ) === 1;
        
        $system_prompt = isset( $_POST['system_prompt'] ) ? wp_unslash( $_POST['system_prompt'] ) : '';
        $user_prompt   = isset( $_POST['user_prompt'] ) ? wp_unslash( $_POST['user_prompt'] ) : '';

        $result_text = '';

        if ( $provider === 'openai' ) {
            if ( empty( $model ) ) $model = 'gpt-4o';
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'timeout' => 60,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode( [
                    'model'    => $model,
                    'messages' => [
                        [ 'role' => 'system', 'content' => $system_prompt ],
                        [ 'role' => 'user', 'content' => $user_prompt ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) {
                $err_msg = $response->get_error_message();
                if ( strpos( $err_msg, 'cURL error 28' ) !== false ) $err_msg = '連線逾時 (超過 60 秒未收到 AI 回應，請稍後再試)。';
                wp_send_json_error( $err_msg );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error'] ) ) wp_send_json_error( $body['error']['message'] );
            $result_text = $body['choices'][0]['message']['content'] ?? '';

        } elseif ( $provider === 'claude' ) {
            if ( empty( $model ) ) $model = 'claude-3-5-sonnet-20240620';
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'timeout' => 60,
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                ],
                'body' => wp_json_encode( [
                    'model'      => $model,
                    'max_tokens' => 8192,
                    'system'     => $system_prompt,
                    'messages'   => [
                        [ 'role' => 'user', 'content' => $user_prompt ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) {
                $err_msg = $response->get_error_message();
                if ( strpos( $err_msg, 'cURL error 28' ) !== false ) $err_msg = '連線逾時 (超過 60 秒未收到 AI 回應，請稍後再試)。';
                wp_send_json_error( $err_msg );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error'] ) ) wp_send_json_error( $body['error']['message'] );
            $result_text = $body['content'][0]['text'] ?? '';

        } else {
            // 預設 Gemini
            if ( empty( $model ) ) $model = 'gemini-3.6-flash';
            // Gemini 1.5 支援 system_instruction
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [ [ 'text' => $user_prompt ] ]
                    ]
                ]
            ];
            if ( ! empty( $system_prompt ) ) {
                $payload['system_instruction'] = [
                    'parts' => [ [ 'text' => $system_prompt ] ]
                ];
            }
            $response = wp_remote_post( $url, [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
            ] );
            if ( is_wp_error( $response ) ) {
                $err_msg = $response->get_error_message();
                if ( strpos( $err_msg, 'cURL error 28' ) !== false ) $err_msg = '連線逾時 (超過 60 秒未收到 AI 回應，請稍後再試)。';
                wp_send_json_error( $err_msg );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error'] ) ) {
                $err_msg = $body['error']['message'];
                if ( strpos( $err_msg, 'currently experiencing high demand' ) !== false ) {
                    $err_msg = '目前此 AI 模型正處於高負載狀態，這通常是暫時的，請稍後再試。';
                }
                wp_send_json_error( $err_msg );
            }
            $result_text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        $response_data = [ 'result' => $result_text ];
        if ( $debug ) {
            $response_data['debug_prompts'] = [
                'system' => $system_prompt,
                'user'   => $user_prompt
            ];
        }
        wp_send_json_success( $response_data );
    }

    // =========================================================================
    // CAST 字典管理與翻譯 Backend
    // =========================================================================

    private function get_cast_dict_path(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/asp_cast_cache.json';
    }

    private function get_cast_dict(): array {
        $file = $this->get_cast_dict_path();
        if ( ! file_exists( $file ) ) {
            return [ 'va' => [], 'char' => [] ];
        }
        $json = file_get_contents( $file );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return [ 'va' => [], 'char' => [] ];
        }
        return $data;
    }

    private function update_cast_dict( array $new_data, bool $merge = true ): void {
        $file = $this->get_cast_dict_path();
        if ( $merge ) {
            $existing = $this->get_cast_dict();
            $existing['va']   = array_merge( $existing['va'] ?? [], $new_data['va'] ?? [] );
            $existing['char'] = array_merge( $existing['char'] ?? [], $new_data['char'] ?? [] );
            $data_to_save = $existing;
        } else {
            $data_to_save = [
                'va'   => $new_data['va'] ?? [],
                'char' => $new_data['char'] ?? []
            ];
        }
        $json = wp_json_encode( $data_to_save, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        if ( $json === false ) {
            error_log( 'asp_cast_cache.json encode failed: ' . json_last_error_msg() );
            return;
        }
        file_put_contents( $file, $json, LOCK_EX );
        error_log( 'asp_cast_cache.json updated. va: ' . count($data_to_save['va']) . ', char: ' . count($data_to_save['char']) );
    }

    public function ajax_cast_dict_load(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );
        wp_send_json_success( $this->get_cast_dict() );
    }

    public function ajax_cast_dict_save(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );
        
        $json = isset( $_POST['dict_data'] ) ? wp_unslash( $_POST['dict_data'] ) : '';
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) wp_send_json_error( '格式錯誤' );
        
        $this->update_cast_dict( $data, false ); // 完全覆蓋
        wp_send_json_success();
    }

    public function ajax_shortcut_ai_cast_translate(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );

        $user_id = get_current_user_id();
        $provider = get_user_meta( $user_id, 'asp_ai_provider', true ) ?: 'gemini';
        $api_key  = get_user_meta( $user_id, 'asp_ai_api_key', true );
        $model    = get_user_meta( $user_id, 'asp_ai_model_name', true );
        
        if ( empty( $api_key ) ) wp_send_json_error( '未設定 API Key' );

        $debug = ! empty( $_POST['debug'] ) && intval( $_POST['debug'] ) === 1;

        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';
        $items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
        $items = json_decode( $items_json, true );

        if ( empty( $items ) || ! is_array( $items ) ) wp_send_json_error( '無效的查證清單' );

        // 1. 雙軌快取過濾：先檢查快取中是否已經有答案
        $global_dict = $this->get_cast_dict();
        $unknown_items = [];
        $known_mapping = [ 'va' => [], 'char' => [] ];

        foreach ( $items as $item ) {
            // 防禦性驗證：確保必要欄位都存在且型別正確
            if ( empty( $item['type'] ) || empty( $item['key'] ) || ! isset( $item['text'] ) ) {
                continue;
            }
            $type = $item['type']; // 'va' or 'char'
            $key  = $item['key'];
            $text = $item['text'];
            
            if ( isset( $global_dict[$type][$key] ) && $global_dict[$type][$key] !== '' ) {
                $known_mapping[$type][$key] = $global_dict[$type][$key];
            } else {
                // 檢查是否「已經是中文譯名」？
                // 如果目前傳進來的 $text 已經存在於字典的「譯名(Value)」當中
                // 我們可以直接認定它已經翻譯過了，快取命中，譯名就是它自己。
                $is_already_translated = false;
                
                if ( $type === 'char' ) {
                    // 角色限制在同一個 namespace 下尋找
                    $parts = explode('|||', $key);
                    if ( count($parts) === 2 ) {
                        $namespace = $parts[0];
                        foreach ( $global_dict['char'] as $dict_key => $dict_val ) {
                            if ( str_starts_with( $dict_key, $namespace . '|||' ) ) {
                                if ( $dict_val === $text ) {
                                    $is_already_translated = true;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    // 聲優直接在所有聲優的譯名中尋找
                    if ( in_array( $text, $global_dict['va'], true ) ) {
                        $is_already_translated = true;
                    }
                }

                if ( $is_already_translated ) {
                    $known_mapping[$type][$key] = $text; // 譯名就是自己
                } else {
                    $unknown_items[] = $item;
                }
            }
        }

        // 如果全部都在快取裡了，直接回傳
        if ( empty( $unknown_items ) ) {
            $response_data = [ 
                'mapping' => $known_mapping,
                'stats'   => [
                    'total'  => count($items),
                    'cached' => count($items),
                    'api'    => 0
                ]
            ];
            if ( $debug ) {
                $response_data['debug_prompts'] = [
                    'system' => '(全部快取命中，本批次未送出 AI 請求)',
                    'user'   => '(全部快取命中，本批次未送出 AI 請求)'
                ];
            }
            wp_send_json_success( $response_data );
            return;
        }

        // 2. 組裝要餵給 AI 的資料
        $prompt_list = [];
        foreach ( $unknown_items as $idx => $item ) {
            $label = $item['type'] === 'va' ? '【聲優】' : '【角色】';
            $text = $item['text']; // 原文
            $prompt_list[] = ($idx + 1) . ". {$label} {$text}";
        }
        $prompt_text = implode( "\n", $prompt_list );

        $system_prompt = "你是熟悉台灣 ACG 圈譯名的翻譯校對員。請把名單的「角色名」與「聲優名」改成台灣慣用中文譯名。\n\n"
                       . "【最重要的前提】\n"
                       . "你必須「實際上網開啟網頁查證」，不可僅憑記憶或推測。新番你的記憶很可能沒有或過時。\n"
                       . "【查證來源優先順序】\n"
                       . "① 台灣代理商/平台官方(木棉花 Muse、曼迪、羚邦、Netflix、巴哈姆特動畫瘋)的官網或官方社群(FB/IG/X)——有台灣官方代理版本時,以其角色譯名為最高依據\n"
                       . "② 中文維基百科台灣版(zh-hant)\n"
                       . "③ 日文官網、日文維基(確認原文對應,避免張冠李戴)\n"
                       . "④ 萌娘百科/百度(僅輔助確認角色存在與原文對應,為大陸譯名,不可直接採用)\n\n"
                       . "若查無台灣代理官方譯名(常見於老作品、冷門番、未代理作品)：依②③來源查證後，採用台灣 ACG 圈普遍使用之慣用譯名(而非直接照搬大陸慣用譯名)。\n"
                       . "若同一角色/聲優查到多個不同譯名版本：以來源優先順序最高者為準，直接採用，不需列出其他版本。\n"
                       . "若查證後確定無對應中文譯名，請原樣保留或略過該筆。\n\n"
                       . "請嚴格以 JSON 陣列格式回傳，格式範例：\n"
                       . "[\n"
                       . "  {\"type\": \"char\", \"text\": \"原文角色名\", \"translated\": \"繁體中文譯名\"},\n"
                       . "  {\"type\": \"va\", \"text\": \"原文聲優名\", \"translated\": \"繁體漢字寫法\"}\n"
                       . "]\n"
                       . "注意：因為系統採用全自動 API 批次映射，請【絕對不要】包含任何額外的解釋文字、核對清單或 markdown 標籤，只能輸出純 JSON 陣列。";

        $user_prompt = "【作品名稱】{$title}\n【補充辨識】{$context}\n\n請查證以下名單：\n" . $prompt_text;

        // 3. 發送 API 請求
        $result_text = '';

        if ( $provider === 'openai' ) {
            if ( empty( $model ) ) $model = 'gpt-4o';
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ],
                'body' => wp_json_encode( [
                    'model' => $model,
                    'response_format' => [ 'type' => 'json_object' ],
                    'messages' => [
                        [ 'role' => 'system', 'content' => $system_prompt . "\n(由於 OpenAI JSON 模式限制，請將陣列包裝在 {\"result\": [...]} 中)" ],
                        [ 'role' => 'user', 'content' => $user_prompt ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error'] ) ) wp_send_json_error( $body['error']['message'] );
            $result_text = $body['choices'][0]['message']['content'] ?? '';
            
            $parsed = json_decode( $result_text, true );
            if ( isset($parsed['result']) ) {
                $result_text = wp_json_encode( $parsed['result'], JSON_UNESCAPED_UNICODE );
            }

        } elseif ( $provider === 'claude' ) {
            if ( empty( $model ) ) $model = 'claude-3-5-sonnet-20240620';
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json', 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01' ],
                'body' => wp_json_encode( [
                    'model' => $model,
                    'max_tokens' => 8192,
                    'system' => $system_prompt,
                    'messages' => [ [ 'role' => 'user', 'content' => $user_prompt ] ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error'] ) ) wp_send_json_error( $body['error']['message'] );
            $result_text = $body['content'][0]['text'] ?? '';

        } else {
            if ( empty( $model ) ) $model = 'gemini-3.6-flash';
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
            $payload = [
                'contents' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $user_prompt ] ] ] ],
                'system_instruction' => [ 'parts' => [ [ 'text' => $system_prompt ] ] ]
            ];
            $response = wp_remote_post( $url, [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
            ] );
            if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error'] ) ) wp_send_json_error( $body['error']['message'] );
            $result_text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        // 4. 解析 AI 回傳的 JSON
        $result_text = trim( preg_replace('/```json|```/i', '', $result_text) );
        $ai_parsed = json_decode( $result_text, true );

        if ( ! is_array( $ai_parsed ) ) {
            wp_send_json_error( 'AI 未能回傳有效的 JSON 陣列' );
        }

        // 5. 組合新字典並寫入快取
        $new_dict = [ 'va' => [], 'char' => [] ];
        
        $text_to_item = [];
        foreach ( $unknown_items as $uitem ) {
            $text_to_item[ $uitem['type'] . '_' . $uitem['text'] ] = $uitem['key'];
        }

        foreach ( $ai_parsed as $res_item ) {
            if ( isset( $res_item['type'], $res_item['text'], $res_item['translated'] ) ) {
                $type  = $res_item['type'];
                $text  = $res_item['text'];
                $trans = trim( $res_item['translated'] );
                
                $lookup_key = $type . '_' . $text;
                if ( isset( $text_to_item[ $lookup_key ] ) && $trans !== '' ) {
                    $original_key = $text_to_item[ $lookup_key ];
                    $new_dict[$type][$original_key] = $trans;
                    $known_mapping[$type][$original_key] = $trans;
                }
            }
        }

        if ( ! empty( $new_dict['va'] ) || ! empty( $new_dict['char'] ) ) {
            $this->update_cast_dict( $new_dict, true ); // Merge
        }

        $response_data = [ 
            'mapping' => $known_mapping,
            'stats'   => [
                'total'  => count($items),
                'cached' => count($items) - count($unknown_items),
                'api'    => count($unknown_items)
            ]
        ];
        if ( $debug ) {
            $response_data['debug_prompts'] = [
                'system' => $system_prompt,
                'user'   => $user_prompt
            ];
        }
        wp_send_json_success( $response_data );
    }

}
