<?php
/**
 * ACF 欄位群組：漫畫
 *
 * 從 class-acf-fields.php 抽出（原本 6000+ 行單檔，動畫與漫畫混在一起）。
 * 以 trait 而非獨立類別實作：方法內容原封不動搬移，$this-> 與既有的
 * private helper 全部照常可用，不改變任何行為或 ACF 註冊方式。
 *
 * 本檔僅含 location 綁定 post_type == manga 的群組：
 *   group_manga_data / group_manga_publication
 *   group_manga_external_ids / group_manga_preview
 *
 * @package Anime_Sync_Pro
 */

defined( 'ABSPATH' ) || exit;

trait Anime_Sync_ACF_Manga_Field_Groups {

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
			[
				'key'          => 'field_manga_staff_json',
				'label'        => 'STAFF 資料(JSON)',
				'name'         => 'anime_staff_json', // 沿用動畫共用 key，前台/人物頁交叉連結邏輯能複用
				'type'         => 'textarea',
				'instructions' => 'Bangumi 優先、AniList 備援自動填入。可手動修正後儲存，記得在下方「鎖定欄位」勾選避免被同步覆蓋。',
				'required'     => 0,
				'rows'         => 6,
				'new_lines'    => '',
				'wrapper'      => [ 'width' => '100' ],
			],
			[
				'key'          => 'field_manga_cast_json',
				'label'        => 'CAST 資料(JSON)',
				'name'         => 'anime_cast_json', // 同樣沿用動畫共用 key
				'type'         => 'textarea',
				'instructions' => 'Bangumi 角色資料自動填入。漫畫多半沒有聲優，聲優欄位空著屬正常。可手動修正後儲存，記得在下方「鎖定欄位」勾選避免被同步覆蓋。',
				'required'     => 0,
				'rows'         => 6,
				'new_lines'    => '',
				'wrapper'      => [ 'width' => '100' ],
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

}
