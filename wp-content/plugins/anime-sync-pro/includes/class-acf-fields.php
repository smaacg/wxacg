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
                    'instructions'  => '由 AniList seasonYear 欄位自動填入。',
                    'required'      => 0,
                    'min'           => 1900,
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
        $instructions .= '<div style="margin:8px 0;">';
        $instructions .= '<textarea id="' . esc_attr( $ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:140px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $prompt ) . '</textarea>';
        $instructions .= '</div>';

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
$cast_prompt .= "① 台灣代理商/平台官方(木棉花 Muse、曼迪、羚邦、Netflix、巴哈姆特動畫瘋)的官網或官方社群(FB/IG/X)——台灣配音版角色譯名為最高依據\n";
$cast_prompt .= "② 中文維基百科台灣版(zh-hant)\n";
$cast_prompt .= "③ 日文官網、日文維基(確認原文對應)\n";
$cast_prompt .= "④ 萌娘百科/百度(僅輔助確認角色存在,為大陸譯名,不可直接採用)\n";
$cast_prompt .= "修改後告訴我資料來源\n";
$cast_prompt .= "JSON 結構:不可增減欄位、不可改 key、不可改順序、不可改 id、image、role、source(image 網址一字不可動)\n";
$cast_prompt .= "最後單獨輸出完整 JSON,放程式碼框內供一鍵複製。框內只有 JSON,結構與我給的完全一致,所有 image 網址保持原樣。\n\n";

$cast_prompt .= "以下是 JSON:\n";
$cast_prompt .= "(貼上 CAST JSON)";

        $cast_ta_id = 'anime_cast_prompt_' . ( $pid > 0 ? $pid : 'new' );

        $cast_instructions  = '由 Bangumi CAST API 自動填入(多為日文原名/大陸譯名),匯入後人工整理。整理後請在「同步控制」勾選「鎖定 CAST 角色資料」,避免下次同步被覆蓋。<br><br>';
        $cast_instructions .= '<strong>📋 譯名在地化指令(點框內後按 Ctrl+A 再 Ctrl+C,務必先填【作品名稱】,連同 CAST JSON 一起貼給可上網的 AI):</strong>';
        $cast_instructions .= '<div style="margin:8px 0;">';
        $cast_instructions .= '<textarea id="' . esc_attr( $cast_ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:260px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $cast_prompt ) . '</textarea>';
        $cast_instructions .= '</div>';
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
        $prompt .= "8. 每題答案以繁體中文撰寫，長度約 50~120 字，簡潔不冗長。\n";
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
        $instructions .= '<div style="margin:8px 0;">';
        $instructions .= '<textarea id="' . esc_attr( $ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:220px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $prompt ) . '</textarea>';
        $instructions .= '</div>';
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
}
