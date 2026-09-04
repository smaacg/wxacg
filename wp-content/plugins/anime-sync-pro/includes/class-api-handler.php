<?php
/**
 * 檔案名稱: includes/class-api-handler.php
 *
 * @version 1.5.1
 *
 * Changelog:
 *   1.5.1 (2026-09-02)
 *     — [Feat 供匯入判定系列使用] fetch_anilist_node_bundle() 的查詢補上 season
 *         欄位（原本只有 seasonYear）：系列命名要靠季別去查 YourAnimes 的季度
 *         新番表。新增兩個公開包裝 find_series_root_public() 與
 *         get_anilist_node_public()，供 class-import-manager.php 在每次匯入時
 *         判定系列根源與取根源節點資料，做法比照既有的 fetch_bgm_data_public()。
 *         注意 anime_sync_al_node_* transient 與 fetch_anilist_node_data() 共用，
 *         舊快取沒有 season 欄位——取不到時會自動略過 YourAnimes 那層，
 *         6 小時後快取自然更新。
 *   1.5.0 (2026-09-01)
 *     — [Fix 系列分析逾時] expand_series_tree() 改為「經由 PARENT 抵達的節點
 *         只收錄、不再往外展開」。PARENT 會把子作品接回母系列，長壽 IP 因此
 *         整串被拉進來：實測《水星的魔女》(AniList 155158) 經 PARENT 連到 1979
 *         初代鋼彈，展開到 93 部仍未收斂，AJAX 的 set_time_limit(180) 必定逾時。
 *         這不只是效能問題——系列名稱取自 root 節點，整個鋼彈宇宙會被掛上
 *         「水星的魔女」這個 term。修正後該系列收斂為 4 部。
 *     — [Perf 合併重複查詢] 新增 fetch_anilist_node_bundle()，一次請求同時取回
 *         節點顯示資料與關係（兩者本就是同一個 Media 物件），並照舊寫回
 *         anime_sync_al_node_* / anime_sync_al_relations_* 兩組 transient。
 *         同時移除 expand_series_tree() 迴圈末端多餘的 wait_if_needed('anilist')
 *         ——anilist_request() 內部已各自節流，該行是同一輪的第三次等待。
 *         每節點耗時由 6 秒降為 2 秒。
 *     — [Feat 節點上限] 新增 MAX_SERIES_NODES（70）。超過即停止展開並標記
 *         has_failure，讓前端拿到部分結果與 incomplete 旗標，而非逾時白畫面。
 *     — [Fix romaji 空 term] 節點若已匯入，title_chinese 改用站內中文標題。
 *         AniList 無中文標題，原本一律退回 romaji，導致 assign_series_taxonomy()
 *         另外建出沒有文章的 romaji 空 term（正式站已累積 250 個）。
 *     — [Fix 命名取錯節點] get_series_tree() 命名改為「root 是 TV 就用 root，
 *         否則取樹中第一個 TV 節點」。root 由 find_series_root() 沿 PREQUEL
 *         往上取得，常是 PROLOGUE / OVA 等前導短篇，會產生「…PROLOGUE」這種
 *         帶字尾的系列名，與既有 term 差一個字尾而重複建立。系列名稱在後台
 *         是唯讀顯示、無法人工修正，偏差只會默默累積。
 *   1.4.0 (2026-08-11)
 *     — [Fix MAL 節流標籤] fetch_mal_score() 與 fetch_jikan_theme_natives() 的
 *         wait_if_needed() 由 'jikan' 改為 'mal'（需搭配 class-rate-limiter.php
 *         v1.3.0 新增的 'mal' 節流 key，1 req/s）；record_stat 統計標籤維持 'jikan' 不變。
 *     — [Fix _enriched_at 誤鎖] enrich_anime_data() 若「有 MAL ID 但當次未抓到分」，
 *         不再無條件寫入 _enriched_at / 刪除 _needs_enrich，改寫 _enriched_partial_at
 *         保留待補狀態，讓後續 enrich 或每日排程可再次抓分，根治「當次 MAL API
 *         暫時性失敗導致分數永久卡 0」的歷史問題來源。
 *   1.3.1 (2026-07-18)
 *     — [Fix MAL 分數抓取失敗無重試] fetch_mal_score() 加入最多 3 次重試：
 *         cURL 錯誤與 429/5xx 暫時性錯誤才重試（4xx 如 404 直接放棄不重試），
 *         並補上 record_stat() 統計呼叫（success/failed/rate_limited/retry），
 *         使 jikan 呼叫狀況能出現在 Anime_Sync_Rate_Limiter::get_stats() 裡。
 *         根因：enrich_anime_data() 只會執行一次（_enriched_at 寫入後
 *         enrich_single() 會拒絕重跑），若當次 Jikan API 剛好逾時或被
 *         限流，MAL 分數會永久卡在 0 且不會再被重試。
 *   1.3.0 (2026-07-17)
 *     — [Feat AnimeThemes by-slug] fetch_animethemes() 抽出共用解析器
 *         parse_animethemes_payload()；新增 fetch_animethemes_by_slug()，
 *         可用 AnimeThemes slug 直接查 /anime/{slug} 端點抓主題曲。
 *         供 CRON「slug 優先、mal_id 備援」使用，根治 mal_id 失效時
 *         主題曲永遠抓不到的問題。回傳結構與 by-mal 完全一致。
 *   1.2.1 (2026-06-19)
 *     — [Fix 整欄鎖一致性] enrich_anime_data() 寫入前檢查 anime_locked_fields。
 *   1.2.0 (2026-06-18)
 *     — [Fix L6] fetch_mal_score() 裸 error_log() 改用 logger / 刪除。
 *     — [Fix 集數鎖定] enrich 寫入 episodes 尊重 locked ids。
 *   1.1.0 — Rate limiter 整合強化。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_API_Handler {

    const ANILIST_ENDPOINT  = 'https://graphql.anilist.co';
    const BGM_SUBJECT_URL   = 'https://api.bgm.tv/v0/subjects/';
    const BGM_LEGACY_SUBJECT_URL = 'https://api.bgm.tv/subject/';
    const BGM_EPISODES_URL  = 'https://api.bgm.tv/v0/episodes';
    const ANIMETHEMES_URL   = 'https://api.animethemes.moe/anime';
    const WIKI_ZH_API       = 'https://zh.wikipedia.org/w/api.php';
    const WIKI_EN_REST      = 'https://en.wikipedia.org/api/rest_v1/page/summary/';

    // ACG 新增：統一 User-Agent 常數
    const USER_AGENT = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)';

    const SERIES_RELATION_TYPES = [
        'PREQUEL',
        'SEQUEL',
        'SIDE_STORY',
        'SPIN_OFF',
        'ALTERNATIVE',
        'PARENT',
    ];

    /**
     * 系列樹單次展開的節點上限。
     *
     * 系列分析走 AJAX,handler 是 set_time_limit(180);展開每個節點要一次
     * AniList 請求,而 rate limiter 對 anilist 的間隔是 2000ms,因此上限必須
     * 讓「節點數 × 2 秒」明顯低於 180 秒。70 部約 140 秒,站上最大的系列
     * （Fate 48 部）在範圍內。超過上限時回傳部分結果並標記 incomplete,
     * 而不是讓整個請求逾時變成白畫面。
     */
    const MAX_SERIES_NODES = 70;

    private Anime_Sync_Rate_Limiter $rate_limiter;
    private ?Anime_Sync_ID_Mapper   $id_mapper;

    public function __construct(
        ?Anime_Sync_Rate_Limiter $rate_limiter = null,
        ?Anime_Sync_ID_Mapper    $id_mapper    = null
    ) {
        // 1.1.0：rate-limiter constructor 已改 private，必須用 get_instance()
        $this->rate_limiter = $rate_limiter ?? Anime_Sync_Rate_Limiter::get_instance();
        $this->id_mapper    = $id_mapper    ?? new Anime_Sync_ID_Mapper();
    }

    private function get_animethemes_meta( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [ 'id' => '', 'slug' => '' ];
        }

        $id_raw      = get_post_meta( $post_id, 'anime_animethemes_id', true );
        $slug        = trim( (string) get_post_meta( $post_id, 'anime_animethemes_slug', true ) );
        $legacy_slug = trim( (string) get_post_meta( $post_id, 'animethemes_slug', true ) );
        $id          = '';

        if ( is_scalar( $id_raw ) ) {
            $id_raw = trim( (string) $id_raw );
            if ( $id_raw !== '' ) {
                if ( ctype_digit( $id_raw ) ) {
                    $id = $id_raw;
                } elseif ( $slug === '' ) {
                    // 舊版曾把 slug 寫進 anime_animethemes_id，這裡自動轉回 slug。
                    $slug = $id_raw;
                }
            }
        }

        if ( $slug === '' ) {
            $slug = $legacy_slug;
        }

        return [
            'id'   => $id,
            'slug' => $slug,
        ];
    }

    /**
     * 取出「原作」的國別。
     *
     * ★ 不可用 Media.countryOfOrigin —— 那是「動畫的製作國」，不是原作國別。
     *   最常見的韓漫改編就是「韓國原作 ＋ 日本製作」，兩者不同：
     *
     *     我獨自升級      source=OTHER, countryOfOrigin=JP（A-1 Pictures）
     *                     relations: ADAPTATION → MANGA, countryOfOrigin=KR
     *     全知讀者視角    同上模式
     *
     *   只有動畫本身也在當地製作時兩者才會一致（例：伊蓮娜．埃沃的觀察日誌，
     *   製作公司 Laftel 是韓國公司，兩邊都是 KR），這是特例而非通則。
     *
     *   因此改看 relations 裡 relationType = ADAPTATION 的節點；那才是原作。
     *   同一份查詢已經有 relations，補兩個欄位即可，無額外 API 成本。
     *
     * @param array $media AniList Media 節點。
     * @return string 兩碼國別，判斷不出時為空字串。
     */
    private static function extract_source_country( array $media ): string {
        /*
         * ★ 這裡回傳的是「原作的國別」，沒有原作就是空字串——不可拿製作國
         *   充數。曾經退回製作國，結果《那個夏天》（韓國原創 ONA，relations
         *   為空）被標成「韓國漫畫改編」，但它根本不是改編作品。
         *
         *   動畫的製作國另存於 anime_country，需要時從那裡取。
         */
        if ( 'ORIGINAL' === strtoupper( (string) ( $media['source'] ?? '' ) ) ) {
            return '';
        }

        $edges = $media['relations']['edges'] ?? [];

        if ( ! is_array( $edges ) ) {
            return '';
        }

        // 原作可能是漫畫、小說或單篇，一律視為來源候選。
        $source_formats = [ 'MANGA', 'NOVEL', 'ONE_SHOT' ];
        $candidates     = [];

        foreach ( $edges as $edge ) {
            if ( ! is_array( $edge ) ) {
                continue;
            }

            if ( 'ADAPTATION' !== ( $edge['relationType'] ?? '' ) ) {
                continue;
            }

            $node = $edge['node'] ?? [];

            if ( ! is_array( $node ) ) {
                continue;
            }

            $format = strtoupper( (string) ( $node['format'] ?? '' ) );

            if ( '' !== $format && ! in_array( $format, $source_formats, true ) ) {
                continue;
            }

            $country = strtoupper( trim( (string) ( $node['countryOfOrigin'] ?? '' ) ) );

            if ( '' !== $country ) {
                $candidates[] = $country;
            }
        }

        // 查不到原作關聯就是沒有原作，回傳空字串（理由見函式開頭）。
        if ( ! $candidates ) {
            return '';
        }

        /*
         * ★ ADAPTATION 不分「原作」與「衍生改編」，兩個方向掛同一種關聯，
         *   因此可能同時出現多個國別。實例：《數碼寶貝大冒險》同時關聯到
         *   一部 CN 的改編漫畫與一部 JP 的小說，取第一個就會誤判成中國作品。
         *
         *   規則：候選裡只要有 JP 就採 JP。日本作品的外語改編屬衍生品；
         *   真正的外國原作（我獨自升級、神之塔）不會有 JP 的 ADAPTATION。
         */
        if ( in_array( 'JP', $candidates, true ) ) {
            return 'JP';
        }

        return $candidates[0];
    }

  // =========================================================================
    // PUBLIC – 核心匯入（ACB）目標 < 15 秒
    // =========================================================================

    public function get_core_anime_data( int $anilist_id, int $post_id = 0, ?int $bangumi_id = null ): array|WP_Error {

        $anilist_raw = $this->fetch_anilist_data( $anilist_id );
        if ( is_wp_error( $anilist_raw ) ) return $anilist_raw;

        $media = $anilist_raw['data']['Media'] ?? null;
        if ( empty( $media ) ) {
            return new WP_Error( 'anilist_empty', "AniList returned no data for ID {$anilist_id}." );
        }

        $mal_id         = isset( $media['idMal'] ) && $media['idMal'] !== null ? (int) $media['idMal'] : null;
        $title_romaji   = $media['title']['romaji']  ?? '';
        $title_english  = $media['title']['english'] ?? '';
        $title_native   = $media['title']['native']  ?? '';
        $season_year    = $media['seasonYear']        ?? 0;
        /*
         * 季度必須有年份支撐才算數。
         *
         * 「宣布動畫化、製作進行中」的作品，AniList 常常 season 有值但
         * seasonYear 是 null——片商只先發製作消息，連 2027 還 2028 都沒定。
         * 照抄會得到「冬季，第 0 年」這種不指涉任何時間的假資料：前台組不出
         * 完整字串，季度分類法還會把它歸到錯的季別底下。
         * 無年份時一律留空，誠實表示「檔期未定」。
         */
        $season         = ( (int) $season_year > 0 ) ? ( $media['season'] ?? '' ) : '';
        $episodes       = (int) ( $media['episodes'] ?? 0 );
        $external_links = $media['externalLinks']     ?? [];
        $animethemes_meta = $this->get_animethemes_meta( $post_id );

        if ( ! $bangumi_id || $bangumi_id <= 0 ) {
            $bangumi_id = $this->id_mapper->get_bangumi_id( [
                'anilist_id'     => $anilist_id,
                'mal_id'         => $mal_id,
                'post_id'        => $post_id,
                'title_native'   => $this->build_season_aware_native( $title_native, $title_romaji ),
                'title_romaji'   => $title_romaji,
                'title_chinese'  => '',
                'season_year'    => $season_year,
                'season'         => $season,
                'episodes'       => $episodes,
                'external_links' => $external_links,
            ] );
        }

        $bgm_data = null;
        if ( $bangumi_id && $bangumi_id > 0 ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $result = $this->get_bangumi_data( $bangumi_id );
            if ( ! is_wp_error( $result ) && is_array( $result ) ) {
                $bgm_data = $result;
            }
        }

        $title_chinese_raw = '';
        if ( $bgm_data ) {
            $title_chinese_raw = $bgm_data['name_cn'] ?? $bgm_data['name'] ?? '';
        }
        if ( $title_chinese_raw === '' && $bangumi_id ) {
            $cached = $this->id_mapper->get_chinese_title( $bangumi_id );
            if ( $cached ) $title_chinese_raw = $cached;
        }
        $title_chinese = $title_chinese_raw !== ''
            ? Anime_Sync_CN_Converter::static_convert( $title_chinese_raw )
            : '';

        // ★ 保留 Bangumi 原始简体标题（不经 OpenCC），供大陆用户搜寻
        $title_simplified = $bgm_data ? trim( (string) ( $bgm_data['name_cn'] ?? '' ) ) : '';

        $synopsis_chinese = '';
        $synopsis_english = '';
        if ( $bgm_data && ! empty( $bgm_data['summary'] ) ) {
            $synopsis_chinese = $this->clean_synopsis( $bgm_data['summary'] );
            if ( $synopsis_chinese !== '' ) {
                $synopsis_chinese = Anime_Sync_CN_Converter::static_convert( $synopsis_chinese );
            }
        }
        if ( ! empty( $media['description'] ) ) {
            $synopsis_english = $this->clean_synopsis( $media['description'] );
        }

        $score_anilist = isset( $media['averageScore'] ) ? (int) $media['averageScore'] : 0;
        $score_bangumi = 0;
        if ( $bgm_data ) {
            $raw = $bgm_data['rating']['score'] ?? $bgm_data['score'] ?? null;
            if ( $raw !== null ) $score_bangumi = (int) round( (float) $raw * 10 );
        }
     


        $studios = [];
        foreach ( $media['studios']['nodes'] ?? [] as $studio ) {
            if ( ! empty( $studio['name'] ) ) $studios[] = $studio['name'];
        }

        $start_date   = $this->parse_fuzzy_date( $media['startDate'] ?? [] );
        $end_date     = $this->parse_fuzzy_date( $media['endDate']   ?? [] );
        $streaming    = $this->parse_streaming_links( $external_links );
        $parsed_links = $this->parse_external_links( $external_links );
        $staff        = $this->parse_staff( $media['staff']['edges']         ?? [] );
        $cast         = $this->parse_cast(  $media['characters']['edges']    ?? [] );
        $relations    = $this->parse_relations( $media['relations']['edges'] ?? [] );

        $trailer_url = '';
        if ( ! empty( $media['trailer'] ) ) {
            $t_id   = $media['trailer']['id']   ?? '';
            $t_site = $media['trailer']['site']  ?? '';
            if ( $t_id !== '' && strtolower( $t_site ) === 'youtube' ) {
                $trailer_url = "https://www.youtube.com/watch?v={$t_id}";
            }
        }

        $next_airing = null;
        if ( ! empty( $media['nextAiringEpisode'] ) ) {
            $next_airing = [
                'airingAt' => $media['nextAiringEpisode']['airingAt'] ?? 0,
                'episode'  => $media['nextAiringEpisode']['episode']  ?? 0,
            ];
        }

        $anime_tags = [];
        foreach ( $media['tags'] ?? [] as $tag ) {
            if ( ! empty( $tag['isMediaSpoiler'] ) ) continue;
            if ( ! empty( $tag['name'] ) ) $anime_tags[] = $tag['name'];
        }

        return [
            'anilist_id'             => $anilist_id,
            'mal_id'                 => $mal_id,
            'bangumi_id'             => $bangumi_id,
            'anime_animethemes_id'   => $animethemes_meta['id'],
            'anime_animethemes_slug' => $animethemes_meta['slug'],
            'animethemes_slug'       => $animethemes_meta['slug'],
            'anime_title_chinese'    => $title_chinese,
            'anime_title_simplified' => $title_simplified,
            'anime_title_romaji'     => $title_romaji,
            'anime_title_english'    => $title_english,
            'anime_title_native'     => $title_native,
            'anime_format'           => $media['format'] ?? '',
            'anime_status'           => $media['status'] ?? '',
            'anime_season'           => $season,
            'anime_season_year'      => $season_year,
            'anime_source'           => $media['source'] ?? '',
            // 動畫本身的製作國。注意這不等於原作國別，見下一行。
            'anime_country'          => $media['countryOfOrigin'] ?? '',
            // 原作國別：取自 ADAPTATION 關聯，才是判斷「韓漫改編」該用的值。
            'anime_source_country'   => self::extract_source_country( $media ),
            'anime_episodes'         => $episodes,
            'anime_duration'         => (int) ( $media['duration'] ?? 0 ),
            'anime_studios'          => implode( ', ', $studios ),
            'anime_score_anilist'    => $score_anilist,
            'anime_score_bangumi'    => $score_bangumi,
            'anime_score_mal'        => 0,
            'anime_popularity'       => (int) ( $media['popularity'] ?? 0 ),
            'anime_cover_image'      => $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? '',
            'anime_banner_image'     => $media['bannerImage'] ?? '',
            'anime_trailer_url'      => $trailer_url,
            'anime_synopsis_chinese' => $synopsis_chinese,
            'anime_synopsis_english' => $synopsis_english,
            'anime_start_date'       => $start_date,
            'anime_end_date'         => $end_date,
            'anime_streaming'        => wp_json_encode( $streaming,      JSON_UNESCAPED_UNICODE ),
            'anime_themes'           => '[]',
            'anime_staff_json'       => wp_json_encode( $staff,          JSON_UNESCAPED_UNICODE ),
            'anime_cast_json'        => wp_json_encode( $cast,           JSON_UNESCAPED_UNICODE ),
            'anime_relations_json'   => wp_json_encode( $relations,      JSON_UNESCAPED_UNICODE ),
            'anime_episodes_json'    => '[]',
            'anime_official_site'    => $parsed_links['official_site']   ?? '',
            'anime_twitter_url'      => $parsed_links['twitter_url']     ?? '',
            'anime_tiktok_url'       => $parsed_links['tiktok_url']      ?? '',
            'anime_wikipedia_url'    => '',
            'anime_external_links'   => wp_json_encode( $external_links, JSON_UNESCAPED_UNICODE ),
            'anime_next_airing'      => $next_airing ? wp_json_encode( $next_airing ) : '',
            'anime_genres'           => $media['genres'] ?? [],
            'anime_tags'             => $anime_tags,
            '_bgm_raw'               => $bgm_data,
            '_needs_enrich'          => true,
        ];
    }

    // =========================================================================
    // PUBLIC – 補抓第二段資料（ACB）
    // =========================================================================

    public function enrich_anime_data( int $post_id ): array|WP_Error {

        $anilist_id = (int) get_post_meta( $post_id, 'anime_anilist_id', true );

        $bangumi_id = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
        if ( ! $bangumi_id ) {
            $bangumi_id = (int) get_post_meta( $post_id, 'bangumi_id', true );
            if ( $bangumi_id > 0 ) {
                update_post_meta( $post_id, 'anime_bangumi_id', $bangumi_id );
            }
        }

        $mal_id = (int) get_post_meta( $post_id, 'anime_mal_id', true );
        if ( ! $mal_id ) {
            $mal_id = (int) get_post_meta( $post_id, 'mal_id', true );
            if ( $mal_id > 0 ) {
                update_post_meta( $post_id, 'anime_mal_id', $mal_id );
            }
        }
        $existing_animethemes_meta = $this->get_animethemes_meta( $post_id );
        $title_chinese = (string) get_post_meta( $post_id, 'anime_title_chinese', true );
        $title_native  = (string) get_post_meta( $post_id, 'anime_title_native',  true );
        $title_romaji  = (string) get_post_meta( $post_id, 'anime_title_romaji',  true );
        $title_english = (string) get_post_meta( $post_id, 'anime_title_english', true );

        if ( ! $anilist_id ) {
            return new WP_Error( 'missing_anilist_id', "Post {$post_id} has no anime_anilist_id." );
        }

        // ★ [1.2.1] 讀取整欄鎖清單（與 save_post_meta / ajax_resync_bangumi 同一 meta key）。
        $locked_fields = (array) get_post_meta( $post_id, 'anime_locked_fields', true );

        $enriched = [];

        if ( $existing_animethemes_meta['id'] !== '' ) {
            $enriched['anime_animethemes_id'] = $existing_animethemes_meta['id'];
        }
        if ( $existing_animethemes_meta['slug'] !== '' ) {
            $enriched['anime_animethemes_slug'] = $existing_animethemes_meta['slug'];
            $enriched['animethemes_slug']       = $existing_animethemes_meta['slug'];
        }

        if ( $bangumi_id > 0 ) {
            $bgm_subject = $this->get_bangumi_data( $bangumi_id );
            $bgm_infobox = ( ! is_wp_error( $bgm_subject ) && is_array( $bgm_subject ) ) ? ( $bgm_subject['infobox'] ?? [] ) : [];
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $bgm_staff = $this->get_bgm_staff( $bangumi_id, $bgm_infobox );
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $bgm_chars = $this->get_bgm_chars( $bangumi_id );


            /*
             * ACF 修正：Bangumi 直接取代 AniList，不合併。
             *
             * ★ 護欄：上游筆數比現有少時不覆蓋（只增不減）。
             *
             * 情境一 — 續季條目尚未建完：
             *   Bangumi 對新一季常常只先建「原作／動畫製作」兩筆，主要班底
             *   要等一段時間才補。此時覆蓋等於把完整資料換成殘缺資料。
             *
             * 情境二 — 手動修正過 Bangumi ID：
             *   mapper 早期可能配到第一季，匯入了完整 staff；之後人工把 ID
             *   改成正確的續季條目，但 staff JSON 沒重抓。這種「舊資料」其實
             *   多半仍然正確（同系列班底不變），重新同步反而會洗掉它。
             *   實例：post 2590 判處勇者刑第二季，本地 10 筆 vs 上游 2 筆。
             *
             * 上游較多才更新，是唯一不會造成資訊損失的方向。
             */
            $keep_if_fewer = function ( string $meta_key, array $incoming ) use ( $post_id ): bool {
                if ( empty( $incoming ) ) {
                    return false;
                }

                $current = json_decode( (string) get_post_meta( $post_id, $meta_key, true ), true );
                $current = is_array( $current ) ? $current : [];

                if ( empty( $current ) ) {
                    return true;
                }

                /*
                 * 現有資料若不是 Bangumi 來的（早期 AniList 匯入的殘留），
                 * 一律讓 Bangumi 取代——staff / cast 以 Bangumi 為準是本站
                 * 既定政策，AniList 只是沒有 Bangumi 資料時的暫代。
                 * 筆數多寡在這裡不該有否決權：AniList 常把每位配角、每個
                 * 細項職位都列出來，數量多但不是我們要的口徑。
                 */
                $from_bangumi = false;
                foreach ( $current as $entry ) {
                    $src = isset( $entry['source'] ) ? (string) $entry['source'] : '';
                    if ( str_starts_with( $src, 'bangumi' ) ) {
                        $from_bangumi = true;
                        break;
                    }
                }

                if ( ! $from_bangumi ) {
                    /*
                     * [新增] Bangumi 端資料還沒成形時不覆蓋。
                     *
                     * 未播出續季的 Bangumi 條目常常只先掛一筆佔位角色，此時若
                     * 照上面的政策無條件取代 AniList 資料，前台 cast 會從數筆
                     * 掉到 1 筆，看起來像資料遺失——實測 26 部這類作品，
                     * Bangumi 端全部都只有 1 筆，沒有任何一部覆蓋後會變好。
                     *
                     * 這與函式開頭 empty( $incoming ) 就不覆蓋是同一個概念，
                     * 只是把「完全沒有」延伸到「少到還沒成形」，不是推翻
                     * 「以 Bangumi 為準」的既定政策：Bangumi 一旦有像樣的
                     * 名單（達門檻），仍然照舊取代 AniList，筆數多寡不影響。
                     */
                    $min_entries = (int) apply_filters(
                        'anime_sync_cross_source_min_entries',
                        2,
                        $meta_key,
                        $post_id
                    );

                    return count( $incoming ) >= $min_entries;
                }

                // 兩邊都是 Bangumi：只增不減（續季條目常只先建原作／動畫製作兩筆）
                return count( $incoming ) >= count( $current );
            };

            if ( $keep_if_fewer( 'anime_staff_json', $bgm_staff ) ) {
                $enriched['anime_staff_json'] = wp_json_encode( $bgm_staff, JSON_UNESCAPED_UNICODE );
            }
            if ( $keep_if_fewer( 'anime_cast_json', $bgm_chars ) ) {
                $enriched['anime_cast_json'] = wp_json_encode( $bgm_chars, JSON_UNESCAPED_UNICODE );
            }

            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $bgm_episodes = $this->fetch_bgm_episodes( $bangumi_id, false, $post_id );
            if ( ! empty( $bgm_episodes ) ) {
                if ( in_array( 'anime_episodes_json', $locked_fields, true ) ) {
                    // 整欄已鎖定：不寫入，保留現有集數。
                } else {
                    $enriched['anime_episodes_json'] = wp_json_encode(
                        $this->merge_episodes_respecting_locks( $post_id, $bgm_episodes ),
                        JSON_UNESCAPED_UNICODE
                    );
                }
            }
        }

        $mal_score_pending = false;
        if ( $mal_id > 0 ) {
            $score_mal = $this->fetch_mal_score( $mal_id );
            if ( $score_mal > 0 ) {
                $enriched['anime_score_mal'] = $score_mal;
            } else {
                // 有 MAL ID 卻沒抓到分：可能是暫時性失敗（逾時/限流），也可能 MAL 尚未開分。
                // 這裡不當作「已完成」，改由方法尾端決定是否保留重試機會，避免被 _enriched_at 永久鎖死。
                $mal_score_pending = true;
            }
        }

        $wiki_url = $this->fetch_wikipedia_url( $title_chinese, $title_native, $title_romaji, $title_english );
        if ( $wiki_url !== '' ) $enriched['anime_wikipedia_url'] = $wiki_url;

        // ★ [1.3.0] 主題曲抓取：slug 優先，mal_id 備援
        $at_slug = $existing_animethemes_meta['slug'];
        $themes_result = [];
        if ( $at_slug !== '' ) {
            $themes_result = $this->fetch_animethemes_by_slug( $at_slug, $mal_id );
        }
        if ( empty( $themes_result['themes'] ) && $mal_id > 0 ) {
            $themes_result = $this->fetch_animethemes( $mal_id );
        }

        if ( ! empty( $themes_result ) ) {
            // ★ [1.2.1] anime_themes 整欄鎖：鎖定時不寫入主題曲，保留現值。
            if ( ! empty( $themes_result['themes'] ) && ! in_array( 'anime_themes', $locked_fields, true ) ) {
                $enriched['anime_themes'] = wp_json_encode( $themes_result['themes'], JSON_UNESCAPED_UNICODE );
            }

            $themes_id = isset( $themes_result['id'] ) && $themes_result['id'] !== null
                ? trim( (string) $themes_result['id'] )
                : '';
            $themes_slug = trim( (string) ( $themes_result['slug'] ?? '' ) );

            if ( $themes_id !== '' ) {
                $enriched['anime_animethemes_id'] = $themes_id;
            }
            if ( $themes_slug !== '' ) {
                $enriched['anime_animethemes_slug'] = $themes_slug;
                $enriched['animethemes_slug']       = $themes_slug;
            }
        }
        
        $json_fields = [
            'anime_themes', 'anime_streaming', 'anime_staff_json',
            'anime_cast_json', 'anime_relations_json', 'anime_episodes_json',
        ];
        foreach ( $enriched as $key => $value ) {
            if ( in_array( $key, $json_fields, true ) && is_string( $value ) ) {
                update_post_meta( $post_id, $key, wp_slash( $value ) );
            } else {
                update_post_meta( $post_id, $key, $value );
            }
        }
        // ★ MAL 分尚未抓到時，不刪除 _needs_enrich、不寫 _enriched_at，
        //   保留「待補」狀態，讓後續 enrich 或每日排程能再次嘗試抓 MAL 分，
        //   避免當次 API 暫時性失敗造成分數永久卡 0（根治 481 篇歷史問題的來源）。
        //   MAL 是否「尚未開分」由每日排程 backfill 端負責確認並標記，這裡只負責不誤鎖。
        if ( $mal_score_pending ) {
            update_post_meta( $post_id, '_enriched_partial_at', current_time( 'mysql' ) );
        } else {
            delete_post_meta( $post_id, '_needs_enrich' );
            update_post_meta( $post_id, '_enriched_at', current_time( 'mysql' ) );
        }

        return $enriched;
    }
    /**
     * ★ [v1.2.0] 合併集數時尊重鎖定。
     */
    private function merge_episodes_respecting_locks( int $post_id, array $new_episodes ): array {
        $locked_ids = json_decode( (string) get_post_meta( $post_id, 'anime_episodes_locked_ids', true ), true );
        if ( ! is_array( $locked_ids ) || empty( $locked_ids ) ) {
            return $new_episodes;
        }
        $locked_index = array_flip( $locked_ids );

        $old_episodes = json_decode( (string) get_post_meta( $post_id, 'anime_episodes_json', true ), true );
        if ( ! is_array( $old_episodes ) ) {
            $old_episodes = [];
        }

        $merged    = [];
        $seen_ids  = [];
        foreach ( $old_episodes as $ep ) {
            $ep_id = $ep['id'] ?? null;
            if ( $ep_id !== null && isset( $locked_index[ $ep_id ] ) ) {
                $merged[]          = $ep;
                $seen_ids[ $ep_id ] = true;
            }
        }

        foreach ( $new_episodes as $ep ) {
            $ep_id = $ep['id'] ?? null;
            if ( $ep_id !== null && isset( $seen_ids[ $ep_id ] ) ) {
                continue;
            }
            $merged[] = $ep;
            if ( $ep_id !== null ) {
                $seen_ids[ $ep_id ] = true;
            }
        }

        return $merged;
    }

     // =========================================================================
    // PUBLIC – 完整匯入（供 Cron 全量同步）
    // =========================================================================

    public function get_full_anime_data( int $anilist_id, int $post_id = 0, ?int $bangumi_id = null ): array|WP_Error {

        $anilist_raw = $this->fetch_anilist_data( $anilist_id );
        if ( is_wp_error( $anilist_raw ) ) return $anilist_raw;

        $media = $anilist_raw['data']['Media'] ?? null;
        if ( empty( $media ) ) {
            return new WP_Error( 'anilist_empty', "AniList returned no data for ID {$anilist_id}." );
        }

        $mal_id         = isset( $media['idMal'] ) && $media['idMal'] !== null ? (int) $media['idMal'] : null;
        $title_romaji   = $media['title']['romaji']  ?? '';
        $title_english  = $media['title']['english'] ?? '';
        $title_native   = $media['title']['native']  ?? '';
        $season_year    = $media['seasonYear']        ?? 0;
        /*
         * 季度必須有年份支撐才算數。
         *
         * 「宣布動畫化、製作進行中」的作品，AniList 常常 season 有值但
         * seasonYear 是 null——片商只先發製作消息，連 2027 還 2028 都沒定。
         * 照抄會得到「冬季，第 0 年」這種不指涉任何時間的假資料：前台組不出
         * 完整字串，季度分類法還會把它歸到錯的季別底下。
         * 無年份時一律留空，誠實表示「檔期未定」。
         */
        $season         = ( (int) $season_year > 0 ) ? ( $media['season'] ?? '' ) : '';
        $episodes       = (int) ( $media['episodes'] ?? 0 );
        $external_links = $media['externalLinks']     ?? [];
        $existing_animethemes_meta = $this->get_animethemes_meta( $post_id );

        if ( ! $bangumi_id || $bangumi_id <= 0 ) {
            $bangumi_id = $this->id_mapper->get_bangumi_id( [
                'anilist_id'     => $anilist_id,
                'mal_id'         => $mal_id,
                'post_id'        => $post_id,
                'title_native'   => $this->build_season_aware_native( $title_native, $title_romaji ),
                'title_romaji'   => $title_romaji,
                'title_chinese'  => '',
                'season_year'    => $season_year,
                'season'         => $season,
                'episodes'       => $episodes,
                'external_links' => $external_links,
            ] );
        }

        $bgm_data  = null;
        $bgm_staff = [];
        $bgm_chars = [];

        if ( $bangumi_id && $bangumi_id > 0 ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $bgm_result = $this->get_bangumi_data( $bangumi_id );
            if ( ! is_wp_error( $bgm_result ) && is_array( $bgm_result ) ) {
                $bgm_data = $bgm_result;
                $this->rate_limiter->wait_if_needed( 'bangumi' );
                $bgm_staff = $this->get_bgm_staff( $bangumi_id, $bgm_data['infobox'] ?? [] );
                $this->rate_limiter->wait_if_needed( 'bangumi' );
                $bgm_chars = $this->get_bgm_chars( $bangumi_id );
            }
        }

        $title_chinese_raw = '';
        if ( $bgm_data ) $title_chinese_raw = $bgm_data['name_cn'] ?? $bgm_data['name'] ?? '';
        if ( $title_chinese_raw === '' && $bangumi_id ) {
            $cached = $this->id_mapper->get_chinese_title( $bangumi_id );
            if ( $cached ) $title_chinese_raw = $cached;
        }
        $title_chinese = $title_chinese_raw !== ''
            ? Anime_Sync_CN_Converter::static_convert( $title_chinese_raw )
            : '';

        // ★ 保留 Bangumi 原始简体标题（不经 OpenCC），供大陆用户搜寻
        $title_simplified = $bgm_data ? trim( (string) ( $bgm_data['name_cn'] ?? '' ) ) : '';

        $synopsis_chinese = '';
        $synopsis_english = '';
        if ( $bgm_data && ! empty( $bgm_data['summary'] ) ) {
            $synopsis_chinese = $this->clean_synopsis( $bgm_data['summary'] );
            if ( $synopsis_chinese !== '' ) $synopsis_chinese = Anime_Sync_CN_Converter::static_convert( $synopsis_chinese );
        }
        if ( ! empty( $media['description'] ) ) $synopsis_english = $this->clean_synopsis( $media['description'] );

        $score_anilist = isset( $media['averageScore'] ) ? (int) $media['averageScore'] : 0;
        $score_bangumi = 0;
        if ( $bgm_data ) {
            $raw = $bgm_data['rating']['score'] ?? $bgm_data['score'] ?? null;
            if ( $raw !== null ) $score_bangumi = (int) round( (float) $raw * 10 );
        }
        $score_mal = ( $mal_id && $mal_id > 0 ) ? $this->fetch_mal_score( $mal_id ) : 0;

        $studios = [];
        foreach ( $media['studios']['nodes'] ?? [] as $s ) {
            if ( ! empty( $s['name'] ) ) $studios[] = $s['name'];
        }

        $start_date         = $this->parse_fuzzy_date( $media['startDate'] ?? [] );
        $end_date           = $this->parse_fuzzy_date( $media['endDate']   ?? [] );
        $streaming          = $this->parse_streaming_links( $external_links );
        $parsed_links       = $this->parse_external_links( $external_links );
        $wikipedia_url      = $this->fetch_wikipedia_url( $title_chinese, $title_native, $title_romaji, $title_english );

        // ★ [1.3.0] 主題曲抓取：slug 優先，mal_id 備援
        $animethemes_result = [];
        if ( $existing_animethemes_meta['slug'] !== '' ) {
            $animethemes_result = $this->fetch_animethemes_by_slug( $existing_animethemes_meta['slug'], (int) $mal_id );
        }
        if ( empty( $animethemes_result['themes'] ) && $mal_id ) {
            $animethemes_result = $this->fetch_animethemes( $mal_id );
        }

        $themes             = $animethemes_result['themes'] ?? [];
        $animethemes_id     = isset( $animethemes_result['id'] ) && $animethemes_result['id'] !== null
            ? trim( (string) $animethemes_result['id'] )
            : '';
        $animethemes_slug   = trim( (string) ( $animethemes_result['slug'] ?? '' ) );

        if ( $animethemes_id === '' ) {
            $animethemes_id = $existing_animethemes_meta['id'];
        }
        if ( $animethemes_slug === '' ) {
            $animethemes_slug = $existing_animethemes_meta['slug'];
        }

        $episodes_json = '[]';
        if ( $bangumi_id && $bangumi_id > 0 ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $bgm_episodes  = $this->fetch_bgm_episodes( $bangumi_id, false, $post_id );
            $episodes_json = wp_json_encode( $bgm_episodes, JSON_UNESCAPED_UNICODE );
        }

        $staff     = $this->parse_staff( $media['staff']['edges']         ?? [] );
        $cast      = $this->parse_cast(  $media['characters']['edges']    ?? [] );
        $relations = $this->parse_relations( $media['relations']['edges'] ?? [] );

        // ACF 修正：有 Bangumi 就完全取代 AniList，沒有才 fallback
        if ( ! empty( $bgm_staff ) && ! is_wp_error( $bgm_staff ) ) $staff = $bgm_staff;
        if ( ! empty( $bgm_chars ) && ! is_wp_error( $bgm_chars ) ) $cast  = $bgm_chars;

        $trailer_url = '';
        if ( ! empty( $media['trailer'] ) ) {
            $t_id   = $media['trailer']['id']   ?? '';
            $t_site = $media['trailer']['site']  ?? '';
            if ( $t_id !== '' && strtolower( $t_site ) === 'youtube' ) {
                $trailer_url = "https://www.youtube.com/watch?v={$t_id}";
            }
        }

        $next_airing = null;
        if ( ! empty( $media['nextAiringEpisode'] ) ) {
            $next_airing = [
                'airingAt' => $media['nextAiringEpisode']['airingAt'] ?? 0,
                'episode'  => $media['nextAiringEpisode']['episode']  ?? 0,
            ];
        }

        $anime_tags = [];
        foreach ( $media['tags'] ?? [] as $tag ) {
            if ( ! empty( $tag['isMediaSpoiler'] ) ) continue;
            if ( ! empty( $tag['name'] ) ) $anime_tags[] = $tag['name'];
        }

        return [
            'anilist_id'             => $anilist_id,
            'mal_id'                 => $mal_id,
            'bangumi_id'             => $bangumi_id,
            'anime_animethemes_id'   => $animethemes_id,
            'anime_animethemes_slug' => $animethemes_slug,
            'animethemes_slug'       => $animethemes_slug,
            'anime_title_chinese'    => $title_chinese,
            'anime_title_simplified' => $title_simplified,
            'anime_title_romaji'     => $title_romaji,
            'anime_title_english'    => $title_english,
            'anime_title_native'     => $title_native,
            'anime_format'           => $media['format'] ?? '',
            'anime_status'           => $media['status'] ?? '',
            'anime_season'           => $season,
            'anime_season_year'      => $season_year,
            'anime_source'           => $media['source'] ?? '',
            // 動畫本身的製作國。注意這不等於原作國別，見下一行。
            'anime_country'          => $media['countryOfOrigin'] ?? '',
            // 原作國別：取自 ADAPTATION 關聯，才是判斷「韓漫改編」該用的值。
            'anime_source_country'   => self::extract_source_country( $media ),
            'anime_episodes'         => $episodes,
            'anime_duration'         => (int) ( $media['duration'] ?? 0 ),
            'anime_studios'          => implode( ', ', $studios ),
            'anime_score_anilist'    => $score_anilist,
            'anime_score_bangumi'    => $score_bangumi,
            'anime_score_mal'        => $score_mal,
            'anime_popularity'       => (int) ( $media['popularity'] ?? 0 ),
            'anime_cover_image'      => $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? '',
            'anime_banner_image'     => $media['bannerImage'] ?? '',
            'anime_trailer_url'      => $trailer_url,
            'anime_synopsis_chinese' => $synopsis_chinese,
            'anime_synopsis_english' => $synopsis_english,
            'anime_start_date'       => $start_date,
            'anime_end_date'         => $end_date,
            'anime_streaming'        => wp_json_encode( $streaming,      JSON_UNESCAPED_UNICODE ),
            'anime_themes'           => wp_json_encode( $themes,         JSON_UNESCAPED_UNICODE ),
            'anime_staff_json'       => wp_json_encode( $staff,          JSON_UNESCAPED_UNICODE ),
            'anime_cast_json'        => wp_json_encode( $cast,           JSON_UNESCAPED_UNICODE ),
            'anime_relations_json'   => wp_json_encode( $relations,      JSON_UNESCAPED_UNICODE ),
            'anime_episodes_json'    => $episodes_json,
            'anime_official_site'    => $parsed_links['official_site']   ?? '',
            'anime_twitter_url'      => $parsed_links['twitter_url']     ?? '',
            'anime_tiktok_url'       => $parsed_links['tiktok_url']      ?? '',
            'anime_wikipedia_url'    => $wikipedia_url,
            'anime_external_links'   => wp_json_encode( $external_links, JSON_UNESCAPED_UNICODE ),
            'anime_next_airing'      => $next_airing ? wp_json_encode( $next_airing ) : '',
            'anime_genres'           => $media['genres'] ?? [],
            'anime_tags'             => $anime_tags,
            '_bgm_raw'               => $bgm_data,
        ];
    }


      // =========================================================================
    // PUBLIC – 漫畫完整匯入
    // =========================================================================
    public function get_manga_data( int $anilist_id, int $post_id = 0, ?int $bangumi_id = null ): array|WP_Error {

        $anilist_raw = $this->fetch_anilist_manga_data( $anilist_id );
        if ( is_wp_error( $anilist_raw ) ) return $anilist_raw;

        $media = $anilist_raw['data']['Media'] ?? null;
        if ( empty( $media ) ) {
            return new WP_Error( 'anilist_empty', "AniList returned no MANGA data for ID {$anilist_id}." );
        }

        $mal_id        = isset( $media['idMal'] ) && $media['idMal'] !== null ? (int) $media['idMal'] : null;
        $title_romaji  = $media['title']['romaji']  ?? '';
        $title_english = $media['title']['english'] ?? '';
        $title_native  = $media['title']['native']  ?? '';

        $author = '';
        $artist = '';
        foreach ( $media['staff']['edges'] ?? [] as $edge ) {
            $role = strtolower( $edge['role'] ?? '' );
            $name = $edge['node']['name']['full'] ?? ( $edge['node']['name']['native'] ?? '' );
            if ( $name === '' ) continue;
            if ( str_contains( $role, 'story' ) && $author === '' ) {
                $author = $name;
            }
            if ( str_contains( $role, 'art' ) && $artist === '' ) {
                $artist = $name;
            }
        }
        if ( $author !== '' && $artist === $author ) {
            $artist = '';
        }

        if ( ! $bangumi_id || $bangumi_id <= 0 ) {
            $manga_mapper = new Anime_Sync_Manga_ID_Mapper();
            $auto_id = $manga_mapper->get_bangumi_id( [
                'title_native'  => $title_native,
                'title_romaji'  => $title_romaji,
                'title_chinese' => '',
                'start_year'    => (int) ( $media['startDate']['year'] ?? 0 ),
                'volumes'       => isset( $media['volumes'] ) ? (int) $media['volumes'] : 0,
                'chapters'      => isset( $media['chapters'] ) ? (int) $media['chapters'] : 0,
            ] );
            if ( $auto_id ) {
                $bangumi_id = $auto_id;
            }
        }

        $bgm_data = null;
        if ( $bangumi_id && $bangumi_id > 0 ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $result = $this->get_bangumi_data( $bangumi_id );
            if ( ! is_wp_error( $result ) && is_array( $result ) ) {
                $bgm_data = $result;
            }
        }

        // STAFF：Bangumi 中文姓名優先，AniList 只在 Bangumi 抓不到時當備援
        // （與動畫 get_bgm_staff() 覆蓋 AniList 的邏輯一致）。
        $staff = $this->parse_staff( $media['staff']['edges'] ?? [] );
        $cast  = [];

        if ( $bangumi_id && $bangumi_id > 0 ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $bgm_staff = $this->get_bgm_manga_staff( $bangumi_id, $bgm_data['infobox'] ?? [] );
            if ( ! empty( $bgm_staff ) ) {
                $staff = $bgm_staff;
            }

            /*
             * CAST：與動畫共用 get_bgm_chars()，資料一律來自 Bangumi。
             *
             * 動畫那邊 AniList 也有 characters 可當備援，但漫畫的 AniList
             * 查詢（fetch_anilist_manga()）本來就沒有 characters 欄位，
             * 因此沒有備援來源——抓不到就是空陣列，不另外補。
             *
             * 漫畫角色多半沒有聲優，voice_actors 為空屬正常，前台會自動
             * 不顯示聲優那一行。
             */
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $cast = $this->get_bgm_chars( $bangumi_id );
        }

        $title_chinese_raw = '';
        if ( $bgm_data ) {
            $title_chinese_raw = $bgm_data['name_cn'] ?? $bgm_data['name'] ?? '';
        }
        $title_chinese = $title_chinese_raw !== ''
            ? Anime_Sync_CN_Converter::static_convert( $title_chinese_raw )
            : '';

        // ★ 保留 Bangumi 原始简体标题（不经 OpenCC），供大陆用户搜寻
        $title_simplified = $bgm_data ? trim( (string) ( $bgm_data['name_cn'] ?? '' ) ) : '';

        $synopsis_chinese = '';
        if ( $bgm_data && ! empty( $bgm_data['summary'] ) ) {
            $synopsis_chinese = $this->clean_synopsis( $bgm_data['summary'] );
            if ( $synopsis_chinese !== '' ) {
                $synopsis_chinese = Anime_Sync_CN_Converter::static_convert( $synopsis_chinese );
            }
        }
        if ( $synopsis_chinese === '' && ! empty( $media['description'] ) ) {
            $synopsis_chinese = $this->clean_synopsis( $media['description'] );
        }

        $score_anilist = isset( $media['averageScore'] ) ? (int) $media['averageScore'] : 0;
        $score_bangumi = 0;
        if ( $bgm_data ) {
            $raw = $bgm_data['rating']['score'] ?? $bgm_data['score'] ?? null;
            if ( $raw !== null ) $score_bangumi = (int) round( (float) $raw * 10 );
        }
        // 漫畫必須走 MAL 的 manga 端點，用 anime 端點會撈到同號的無關動畫。
        $score_mal = ( $mal_id && $mal_id > 0 ) ? $this->fetch_mal_score( $mal_id, 'manga' ) : 0;


        $bgm_author = $bgm_data ? $this->extract_manga_author( $bgm_data['infobox'] ?? [] ) : '';
        if ( $bgm_author !== '' ) {
            $author = Anime_Sync_CN_Converter::static_convert( $bgm_author );
        }

        $tw = $bgm_data ? $this->extract_manga_tw_edition( $bgm_data['infobox'] ?? [] ) : [];

        $cover = $media['coverImage']['extraLarge']
            ?? $media['coverImage']['large']
            ?? ( $bgm_data['images']['large'] ?? '' );

        $start_date = $this->parse_fuzzy_date( $media['startDate'] ?? [] );
        $end_date   = $this->parse_fuzzy_date( $media['endDate']   ?? [] );

        $related_anime_anilist_id = 0;
        foreach ( $media['relations']['edges'] ?? [] as $edge ) {
            $node = $edge['node'] ?? [];
            if ( ( $node['type'] ?? '' ) === 'ANIME' ) {
                $related_anime_anilist_id = (int) ( $node['id'] ?? 0 );
                break;
            }
        }

        $manga_tags = [];
        foreach ( $media['tags'] ?? [] as $tag ) {
            if ( ! empty( $tag['isMediaSpoiler'] ) ) continue;
            if ( ! empty( $tag['name'] ) ) $manga_tags[] = $tag['name'];
        }

        return [
            'anilist_id'               => $anilist_id,
            'mal_id'                   => $mal_id,
            'bangumi_id'               => $bangumi_id,
            'anime_title_chinese'      => $title_chinese,
            'anime_title_simplified'   => $title_simplified,
            'anime_title_romaji'       => $title_romaji,
            'anime_title_english'      => $title_english,
            'anime_title_native'       => $title_native,
            'anime_format'             => $media['format'] ?? '',
            'anime_status'             => $media['status'] ?? '',
            'anime_source'             => $media['source'] ?? '',
            // 同上：漫畫查詢本來就有 countryOfOrigin，之前抓了卻沒存。
            // 漫畫本身就是原作，製作國即原作國別，兩個欄位同值。
            'anime_country'            => $media['countryOfOrigin'] ?? '',
            'anime_source_country'     => $media['countryOfOrigin'] ?? '',
            'anime_score_anilist'      => $score_anilist,
            'anime_score_bangumi'      => $score_bangumi,
            'anime_score_mal'          => $score_mal,   // ★ 新增
            'anime_popularity'         => (int) ( $media['popularity'] ?? 0 ),
            'anime_start_date'         => $start_date,
            'anime_end_date'           => $end_date,
            'anime_cover_image'        => $cover,
            'anime_banner_image'       => $media['bannerImage'] ?? '',
            'anime_synopsis_chinese'   => $synopsis_chinese,
            'anime_staff_json'         => wp_json_encode( $staff, JSON_UNESCAPED_UNICODE ),
            'anime_cast_json'          => wp_json_encode( $cast,  JSON_UNESCAPED_UNICODE ),
            'manga_chapters'           => isset( $media['chapters'] ) && $media['chapters'] !== null ? (int) $media['chapters'] : '',
            'manga_volumes'            => isset( $media['volumes'] )  && $media['volumes']  !== null ? (int) $media['volumes']  : '',
            'manga_author'             => $author,
            'manga_artist'             => $artist,
            'manga_tw_publisher'       => $tw['publisher']    ?? '',
            'manga_tw_translator'      => $tw['translator']   ?? '',
            'manga_tw_release_date'    => $tw['release_date'] ?? '',
            'manga_related_anime_al'   => $related_anime_anilist_id,
            'anime_genres'             => $media['genres'] ?? [],
            'anime_tags'               => $manga_tags,
            '_bgm_raw'                 => $bgm_data,
        ];
    }


    // =========================================================================
    // PRIVATE – AniList MANGA 單部查詢
    // =========================================================================
    private function fetch_anilist_manga_data( int $anilist_id ): array|WP_Error {

        $cache_key = 'anime_sync_anilist_manga_' . $anilist_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $query = '
        query ($id: Int) {
          Media(id: $id, type: MANGA) {
            id idMal
            title { romaji english native }
            status format source
            chapters volumes
            startDate { year month day }
            endDate   { year month day }
            averageScore popularity
            bannerImage
            coverImage { extraLarge large }
            description(asHtml: false)
            genres
            tags { name isMediaSpoiler }
            countryOfOrigin
            staff(sort: RELEVANCE, perPage: 10) {
              edges {
                role
                node { id name { full native } image { large } }
              }
            }
            relations {
              edges {
                relationType
                node { id type title { romaji native } format }
              }
            }
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'id' => $anilist_id ], 15 );
        if ( is_wp_error( $decoded ) ) return $decoded;

        if ( empty( $decoded['data']['Media'] ) ) {
            return new WP_Error( 'anilist_empty', "AniList returned no MANGA Media for ID {$anilist_id}." );
        }

        set_transient( $cache_key, $decoded, 6 * HOUR_IN_SECONDS );
        return $decoded;
    }

    // =========================================================================
    // PRIVATE – 從 Bangumi infobox 抓漫畫作者
    // =========================================================================
    private function extract_manga_author( array $infobox ): string {
        foreach ( $infobox as $row ) {
            if ( ( $row['key'] ?? '' ) !== '作者' ) continue;
            $value = $row['value'] ?? '';
            if ( is_array( $value ) ) {
                $parts = [];
                foreach ( $value as $v ) {
                    if ( isset( $v['v'] ) && $v['v'] !== '' ) $parts[] = $v['v'];
                }
                $value = implode( '、', $parts );
            }
            return trim( (string) $value );
        }
        return '';
    }

    // =========================================================================
    // PRIVATE – 從 Bangumi infobox 抓台版代理資訊
    // =========================================================================
    private function extract_manga_tw_edition( array $infobox ): array {
        $result = [ 'publisher' => '', 'translator' => '', 'release_date' => '' ];

        foreach ( $infobox as $row ) {
            $key   = $row['key']   ?? '';
            $value = $row['value'] ?? '';

            if ( mb_strpos( $key, '版本' ) !== 0 || ! is_array( $value ) ) {
                continue;
            }

            $sub = [];
            foreach ( $value as $v ) {
                $k = $v['k'] ?? '';
                $val = trim( (string) ( $v['v'] ?? '' ) );
                if ( $k !== '' && $val !== '' ) {
                    $sub[ $k ] = $val;
                }
            }

            $lang       = $sub['语言'] ?? $sub['語言'] ?? '';
            $publisher  = $sub['出版社'] ?? '';
            $tw_pubs    = [ '尖端', '東立', '东立', '青文', '長鴻', '长鸿', '台灣角川', '台湾角川', '角川' ];
            $is_tw      = ( mb_strpos( $lang, '繁' ) !== false );
            if ( ! $is_tw ) {
                foreach ( $tw_pubs as $p ) {
                    if ( mb_strpos( $publisher, $p ) !== false ) { $is_tw = true; break; }
                }
            }
            if ( ! $is_tw ) continue;

            $result['publisher']    = Anime_Sync_CN_Converter::static_convert( $publisher );
            $result['translator']   = Anime_Sync_CN_Converter::static_convert( $sub['译者'] ?? $sub['譯者'] ?? '' );
            $result['release_date'] = $sub['发售日'] ?? $sub['發售日'] ?? '';
            break;
        }

        return $result;
    }

    // =========================================================================
    // PUBLIC – 系列樹（ACD）
    // =========================================================================

    public function get_series_tree( int $anilist_id ): array|WP_Error {

        $cache_key = 'anime_sync_series_tree_' . $anilist_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $root_id = $this->find_series_root( $anilist_id );
        if ( is_wp_error( $root_id ) ) return $root_id;

        $expanded = $this->expand_series_tree( $root_id );
        if ( is_wp_error( $expanded ) ) return $expanded;

        $nodes       = $expanded['nodes'];
        $has_failure = $expanded['has_failure'];

        $series_name   = '';
        $series_romaji = '';

        /*
         * 命名節點的選法：
         *
         * root 是 find_series_root() 沿 PREQUEL 一路往上找到的最前面那部，
         * 常常是 PROLOGUE / OVA / SPECIAL 這類前導短篇，拿它命名會得到
         * 「機動戰士鋼彈 水星的魔女 PROLOGUE」這種帶字尾的名稱，跟站上既有的
         * 「機動戰士鋼彈 水星的魔女」差一個字尾，於是又長出一個新 term。
         * 系列名稱是唯讀顯示、使用者無法在畫面上修正，這種偏差只會默默累積。
         *
         * 因此改為：root 本身是 TV 就用 root，否則取樹中第一個 TV 節點；
         * 整棵樹都沒有 TV（純劇場版系列等）才退回 root。
         */
        $naming_node = null;
        foreach ( $nodes as $node ) {
            if ( (int) $node['anilist_id'] === $root_id ) {
                $naming_node = $node;
                break;
            }
        }
        if ( $naming_node !== null && ( $naming_node['format'] ?? '' ) !== 'TV' ) {
            foreach ( $nodes as $node ) {
                if ( ( $node['format'] ?? '' ) === 'TV' ) {
                    $naming_node = $node;
                    break;
                }
            }
        }

        if ( is_array( $naming_node ) ) {

            /*
             * 命名優先序，與 class-import-manager.php 的 resolve_series_name() 一致：
             *   1. 站內中文標題（該節點已匯入時才有）
             *   2. YourAnimes 季度新番表的台灣官方譯名
             *   3. romaji
             *
             * 第 2 層是後補的：分析階段整個系列通常都還沒匯入，title_chinese 全是空的，
             * 於是系列名稱一律退回 romaji——實測「杜鵑婚約」透過系列分析匯入後，
             * 系列被命名成「Kakkou no Iinazuke」。一般匯入那條路徑早就會查 YourAnimes，
             * 這裡漏掉了，同一份邏輯散在兩處的典型後果。
             */
            $series_name = (string) ( $naming_node['title_chinese'] ?? '' );

            if ( $series_name === '' && class_exists( 'Anime_Sync_YourAnimes_Season_Index' ) ) {
                $match = Anime_Sync_YourAnimes_Season_Index::resolve( [
                    'anime_season'        => $naming_node['season']       ?? '',
                    'anime_season_year'   => $naming_node['season_year']  ?? 0,
                    'anime_title_native'  => $naming_node['title_native'] ?? '',
                    'anime_title_romaji'  => $naming_node['title_romaji'] ?? '',
                    'anime_title_english' => '',
                ] );
                if ( $match && ! empty( $match['tw_title_ok'] ) ) {
                    $series_name = (string) $match['tw_title'];
                }
            }

            if ( $series_name === '' ) {
                $series_name = (string) ( $naming_node['title_romaji'] ?? '' );
            }

            // 去季別字尾的規則集中在 Anime_Sync_TW_Titles，避免與
            // class-import-manager.php 的 resolve_series_name() 各寫一份而漏改
            if ( class_exists( 'Anime_Sync_TW_Titles' ) ) {
                $series_name = Anime_Sync_TW_Titles::strip_season_suffix( $series_name );
            }
            $series_name   = trim( $series_name );
            $series_romaji = $naming_node['title_romaji'] ?? '';
        }

        $result = [
            'root_id'       => $root_id,
            'series_name'   => $series_name,
            'series_romaji' => $series_romaji,
            'nodes'         => $nodes,
            'incomplete'    => $has_failure,
        ];

        $ttl = $has_failure ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );

        return $result;
    }

    // =========================================================================
    // PUBLIC – AniList 人氣排行（ACD + ACE）
    // =========================================================================

    public function fetch_anilist_popularity( int $page = 1 ): array|WP_Error {

        $cache_key = 'anime_sync_popularity_p' . $page;
        $cached    = get_transient( $cache_key );
        // ★ 站內狀態不吃這份快取，一律在 decorate 階段即時計算，理由見該方法註解。
        if ( $cached !== false ) return $this->decorate_popularity_items( $cached );

        $query = '
        query ($page: Int) {
          Page(page: $page, perPage: 50) {
            pageInfo { total currentPage hasNextPage }
            media(type: ANIME, sort: POPULARITY_DESC) {
              id
              title { romaji native }
              coverImage { large }
              format
              status
              seasonYear
              episodes
              popularity
            }
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'page' => $page ], 15 );
        if ( is_wp_error( $decoded ) ) return $decoded;

        $page_obj = $decoded['data']['Page'] ?? null;
        if ( ! $page_obj ) {
            return new WP_Error( 'anilist_no_page', 'AniList popularity: no Page in response.' );
        }

        // ★ 這裡只放 AniList 回傳的內容，站內狀態不摻進來（見 decorate 註解）。
        $items = [];
        foreach ( $page_obj['media'] ?? [] as $media ) {
            $items[] = [
                'anilist_id'   => (int) ( $media['id'] ?? 0 ),
                'title_romaji' => $media['title']['romaji']  ?? '',
                'title_native' => $media['title']['native']  ?? '',
                'cover_image'  => $media['coverImage']['large'] ?? '',
                'format'       => $media['format']     ?? '',
                'status'       => $media['status']     ?? '',
                'season_year'  => $media['seasonYear'] ?? 0,
                'episodes'     => (int) ( $media['episodes'] ?? 0 ),
                'popularity'   => (int) ( $media['popularity'] ?? 0 ),
            ];
        }

        $result = [
            'page_info' => $page_obj['pageInfo'] ?? [],
            'items'     => $items,
        ];

        set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );
        return $this->decorate_popularity_items( $result );
    }

    /**
     * 替人氣排行的每一筆補上站內狀態（imported / post_id / edit_url）。
     *
     * ★ 為什麼站內狀態不跟 AniList 資料一起快取：
     *   這三個欄位反映的是本站資料庫，跟 AniList 回傳的內容無關。原本兩者
     *   一起被冰在 30 分鐘的 transient 裡，而且全站沒有任何一處會在匯入成功
     *   後清掉它，導致剛匯入完的作品在排行頁仍顯示「未匯入」，最長要等半
     *   小時才會反映。改成每次讀取時即時計算，就不需要任何 invalidate 邏輯。
     *
     * ★ 為什麼不沿用 find_existing_post()：
     *   那個方法是單筆查詢，且自帶 5 分鐘物件快取（本站有 persistent object
     *   cache），一頁 50 筆就是 50 次 WP_Query 而且同樣會延遲。這裡改用一次
     *   批次查詢，既即時又比原本快。post_status 的排除條件對齊 WP_Query 的
     *   'any'（排除 trash 與 auto-draft），避免兩邊判定不一致。
     */
    private function decorate_popularity_items( array $result ): array {
        $items = $result['items'] ?? [];
        if ( ! $items ) return $result;

        $ids = array_values( array_filter( array_map(
            static fn( $i ) => (int) ( $i['anilist_id'] ?? 0 ),
            $items
        ) ) );
        if ( ! $ids ) return $result;

        global $wpdb;
        $in   = implode( ',', array_map( 'intval', $ids ) );
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS al_id, p.ID AS post_id
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = 'anime_anilist_id'
                AND pm.meta_value IN ($in)
                AND p.post_type = 'anime'
                AND p.post_status NOT IN ( 'trash', 'auto-draft' )
              ORDER BY p.post_date DESC",
            ARRAY_A
        );

        // 同一個 AniList ID 若有多篇，取最新那篇（與原本 WP_Query 預設排序一致）
        $map = [];
        foreach ( $rows as $row ) {
            $al = (int) $row['al_id'];
            if ( ! isset( $map[ $al ] ) ) $map[ $al ] = (int) $row['post_id'];
        }

        foreach ( $result['items'] as &$item ) {
            $post_id          = $map[ (int) ( $item['anilist_id'] ?? 0 ) ] ?? 0;
            $item['imported'] = $post_id > 0;
            $item['post_id']  = $post_id;
            $item['edit_url'] = $post_id > 0 ? (string) get_edit_post_link( $post_id, 'raw' ) : '';
        }
        unset( $item );

        return $result;
    }

    /**
     * AniList 熱門漫畫排行（供漫畫批次匯入使用）。
     *
     * 刻意另開一個方法而非替 fetch_anilist_popularity() 加參數：動畫版
     * 回傳 episodes／season_year，漫畫版要的是 chapters／volumes／起始年，
     * 欄位對不上；共用一個方法只會讓兩邊都得寫判斷。動畫那邊的呼叫端
     * 因此完全不受影響。
     *
     * @param int $page 頁碼，每頁 50 筆。
     * @return array|WP_Error
     */
    public function fetch_anilist_manga_popularity( int $page = 1 ): array|WP_Error {

        $cache_key = 'anime_sync_manga_popularity_p' . $page;
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        $query = '
        query ($page: Int) {
          Page(page: $page, perPage: 50) {
            pageInfo { total currentPage hasNextPage }
            media(type: MANGA, sort: POPULARITY_DESC) {
              id
              title { romaji native }
              coverImage { large }
              format
              status
              chapters
              volumes
              startDate { year }
              popularity
              countryOfOrigin
            }
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'page' => $page ], 15 );

        if ( is_wp_error( $decoded ) ) {
            return $decoded;
        }

        $page_obj = $decoded['data']['Page'] ?? null;

        if ( ! $page_obj ) {
            return new WP_Error( 'anilist_no_page', 'AniList manga popularity: no Page in response.' );
        }

        $items = [];

        foreach ( $page_obj['media'] ?? [] as $media ) {
            $al_id   = (int) ( $media['id'] ?? 0 );
            $post_id = $this->find_existing_manga_post( $al_id );

            $items[] = [
                'anilist_id'   => $al_id,
                'title_romaji' => $media['title']['romaji'] ?? '',
                'title_native' => $media['title']['native'] ?? '',
                'cover_image'  => $media['coverImage']['large'] ?? '',
                'format'       => $media['format'] ?? '',
                'status'       => $media['status'] ?? '',
                'chapters'     => (int) ( $media['chapters'] ?? 0 ),
                'volumes'      => (int) ( $media['volumes'] ?? 0 ),
                'start_year'   => (int) ( $media['startDate']['year'] ?? 0 ),
                'country'      => $media['countryOfOrigin'] ?? '',
                'popularity'   => (int) ( $media['popularity'] ?? 0 ),
                'imported'     => $post_id > 0,
                'post_id'      => $post_id,
                'edit_url'     => $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '',
            ];
        }

        $result = [
            'page_info' => $page_obj['pageInfo'] ?? [],
            'items'     => $items,
        ];

        set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );

        return $result;
    }

    /**
     * 查漫畫是否已匯入。與 find_existing_post() 同樣邏輯，只差 post_type。
     *
     * @param int $anilist_id AniList 漫畫 ID。
     * @return int 文章 ID，未匯入時為 0。
     */
    private function find_existing_manga_post( int $anilist_id ): int {
        if ( $anilist_id <= 0 ) {
            return 0;
        }

        $cache_key = 'anime_sync_existing_manga_' . $anilist_id;
        $cached    = wp_cache_get( $cache_key, 'anime_sync' );

        if ( $cached !== false ) {
            return (int) $cached;
        }

        $q = new WP_Query( [
            'post_type'      => 'manga',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ [
                'key'     => 'anime_anilist_id',
                'value'   => $anilist_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ] ],
        ] );

        $post_id = ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
        wp_cache_set( $cache_key, $post_id, 'anime_sync', 300 );

        return $post_id;
    }

    // =========================================================================
    // PUBLIC – 重新同步 Bangumi（ACG 新增）
    // =========================================================================

    public function ajax_resync_bangumi( int $post_id, int $bangumi_id ): array|WP_Error {

        $this->rate_limiter->wait_if_needed( 'bangumi' );
        $bgm_data = $this->get_bangumi_data( $bangumi_id );
        if ( is_wp_error( $bgm_data ) ) return $bgm_data;

        $updated = [];
        $skipped = [];

        $locked_fields = (array) get_post_meta( $post_id, 'anime_locked_fields', true );

        $is_locked = static function ( string $key ) use ( $locked_fields ): bool {
            return in_array( $key, $locked_fields, true );
        };

               // 2. 中文標題（繁體 + 簡體）
        $title_raw = $bgm_data['name_cn'] ?? $bgm_data['name'] ?? '';
        if ( $title_raw !== '' ) {

            // 2a. 繁體標題（經 OpenCC 轉換）
            if ( $is_locked( 'anime_title_chinese' ) ) {
                $skipped[] = 'anime_title_chinese';
            } else {
                $title_chinese = Anime_Sync_CN_Converter::static_convert( (string) $title_raw );
                update_post_meta( $post_id, 'anime_title_chinese', $title_chinese );
                $updated[] = 'anime_title_chinese';
            }

            // 2b. 簡體標題（Bangumi name_cn 原文，不轉換）
            if ( $is_locked( 'anime_title_simplified' ) ) {
                $skipped[] = 'anime_title_simplified';
            } else {
                $title_simplified = trim( (string) ( $bgm_data['name_cn'] ?? '' ) );
                update_post_meta( $post_id, 'anime_title_simplified', $title_simplified );
                $updated[] = 'anime_title_simplified';
            }
        }

        // 3. 中文簡介
        if ( ! empty( $bgm_data['summary'] ) ) {
            if ( $is_locked( 'anime_synopsis_chinese' ) ) {
                $skipped[] = 'anime_synopsis_chinese';
            } else {
                $synopsis = $this->clean_synopsis( $bgm_data['summary'] );
                if ( $synopsis !== '' ) {
                    $synopsis = Anime_Sync_CN_Converter::static_convert( $synopsis );
                }
                update_post_meta( $post_id, 'anime_synopsis_chinese', $synopsis );
                $updated[] = 'anime_synopsis_chinese';
            }
        }

        // 4. 封面圖
        $bgm_cover = $bgm_data['images']['large'] ?? $bgm_data['images']['medium'] ?? '';
        if ( $bgm_cover !== '' ) {
            if ( $is_locked( 'anime_cover_image' ) ) {
                $skipped[] = 'anime_cover_image';
            } else {
                update_post_meta( $post_id, 'anime_cover_image', $bgm_cover );
                $updated[] = 'anime_cover_image';
            }
        }

        // 5. Bangumi 評分（評分不設鎖定，永遠更新）
        $raw_score = $bgm_data['rating']['score'] ?? $bgm_data['score'] ?? null;
        if ( $raw_score !== null ) {
            $score_bangumi = (int) round( (float) $raw_score * 10 );
            update_post_meta( $post_id, 'anime_score_bangumi', $score_bangumi );
            $updated[] = 'anime_score_bangumi';
        }

        // 6. 工作人員
        $this->rate_limiter->wait_if_needed( 'bangumi' );
        $bgm_staff = $this->get_bgm_staff( $bangumi_id, $bgm_data['infobox'] ?? [] );
        if ( ! empty( $bgm_staff ) ) {
            if ( $is_locked( 'anime_staff_json' ) ) {
                $skipped[] = 'anime_staff_json';
            } else {
                // wp_slash：update_post_meta 內部會 wp_unslash，不補會吃掉 JSON 的 \"
                update_post_meta( $post_id, 'anime_staff_json', wp_slash( wp_json_encode( $bgm_staff, JSON_UNESCAPED_UNICODE ) ) );
                $updated[] = 'anime_staff_json';
            }
        }

        // 7. 角色
        $this->rate_limiter->wait_if_needed( 'bangumi' );
        $bgm_chars = $this->get_bgm_chars( $bangumi_id );
        if ( ! empty( $bgm_chars ) ) {
            if ( $is_locked( 'anime_cast_json' ) ) {
                $skipped[] = 'anime_cast_json';
            } else {
                update_post_meta( $post_id, 'anime_cast_json', wp_slash( wp_json_encode( $bgm_chars, JSON_UNESCAPED_UNICODE ) ) );
                $updated[] = 'anime_cast_json';
            }
        }

        // 8. 集數
        $this->rate_limiter->wait_if_needed( 'bangumi' );
        $bgm_episodes = $this->fetch_bgm_episodes( $bangumi_id, false, $post_id );
        if ( ! empty( $bgm_episodes ) ) {
            if ( $is_locked( 'anime_episodes_json' ) ) {
                $skipped[] = 'anime_episodes_json';
            } else {
                update_post_meta( $post_id, 'anime_episodes_json', wp_slash( wp_json_encode( $bgm_episodes, JSON_UNESCAPED_UNICODE ) ) );
                $updated[] = 'anime_episodes_json';
            }
        }

        // 9. 更新同步時間
        update_post_meta( $post_id, 'anime_sync_time',    current_time( 'mysql' ) );
        update_post_meta( $post_id, 'anime_last_sync',    current_time( 'mysql' ) );
        update_post_meta( $post_id, 'anime_last_updated', current_time( 'mysql' ) );

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    // =========================================================================
    // PUBLIC – 重新同步 AniList 封面 / 橫幅圖片（方案 A）
    // =========================================================================

    public function ajax_resync_anilist_images( int $post_id ): array|WP_Error {

        $anilist_id = (int) get_post_meta( $post_id, 'anime_anilist_id', true );
        if ( $anilist_id <= 0 ) {
            return new WP_Error( 'missing_anilist_id', "文章 {$post_id} 沒有 AniList ID，無法同步圖片。" );
        }

        delete_transient( 'anime_sync_anilist_' . $anilist_id );

        $anilist_raw = $this->fetch_anilist_data( $anilist_id );
        if ( is_wp_error( $anilist_raw ) ) {
            return $anilist_raw;
        }

        $media = $anilist_raw['data']['Media'] ?? null;
        if ( empty( $media ) ) {
            return new WP_Error( 'anilist_empty', "AniList 查無 ID {$anilist_id} 的資料。" );
        }

        $cover  = $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? '';
        $banner = $media['bannerImage'] ?? '';

        $locked_fields = (array) get_post_meta( $post_id, 'anime_locked_fields', true );
        $is_locked = static function ( string $key ) use ( $locked_fields ): bool {
            return in_array( $key, $locked_fields, true );
        };

        $updated = [];
        $skipped = [];

        if ( $cover !== '' ) {
            if ( $is_locked( 'anime_cover_image' ) ) {
                $skipped[] = 'anime_cover_image';
            } else {
                update_post_meta( $post_id, 'anime_cover_image', esc_url_raw( $cover ) );
                $updated[] = 'anime_cover_image';
            }
        }

        if ( $banner !== '' ) {
            if ( $is_locked( 'anime_banner_image' ) ) {
                $skipped[] = 'anime_banner_image';
            } else {
                update_post_meta( $post_id, 'anime_banner_image', esc_url_raw( $banner ) );
                $updated[] = 'anime_banner_image';
            }
        }

        update_post_meta( $post_id, 'anime_last_sync',    current_time( 'mysql' ) );
        update_post_meta( $post_id, 'anime_last_updated', current_time( 'mysql' ) );

        return [
            'updated'    => $updated,
            'skipped'    => $skipped,
            'has_banner' => $banner !== '',
        ];
    }

    // =========================================================================
    // PRIVATE – 找系列根源
    // =========================================================================

    private function find_series_root( int $anilist_id, array $visited = [] ): int|WP_Error {
        if ( in_array( $anilist_id, $visited, true ) ) return $anilist_id;
        $visited[] = $anilist_id;

        $relations = $this->fetch_anilist_relations( $anilist_id );
        if ( is_wp_error( $relations ) ) return $anilist_id;

        foreach ( $relations as $rel ) {
            if ( $rel['type'] === 'PREQUEL' && ! empty( $rel['node_id'] ) && ( $rel['node_type'] ?? '' ) === 'ANIME' ) {
                return $this->find_series_root( (int) $rel['node_id'], $visited );
            }
        }
        return $anilist_id;
    }

    // =========================================================================
    // PRIVATE – 取單一作品的關係列表
    // =========================================================================

    private function fetch_anilist_relations( int $anilist_id ): array|WP_Error {

        $cache_key = 'anime_sync_al_relations_' . $anilist_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $query = '
        query ($id: Int) {
          Media(id: $id, type: ANIME) {
            relations {
              edges {
                relationType
                node { id type }
              }
            }
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'id' => $anilist_id ], 12 );
        if ( is_wp_error( $decoded ) ) return $decoded;

        $edges = $decoded['data']['Media']['relations']['edges'] ?? [];

        $result = [];
        foreach ( $edges as $edge ) {
            $result[] = [
                'type'      => $edge['relationType']  ?? '',
                'node_id'   => $edge['node']['id']    ?? 0,
                'node_type' => $edge['node']['type']  ?? '',
            ];
        }

        set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
        return $result;
    }

    // =========================================================================
    // PRIVATE – BFS 展開系列樹（ACE）
    // =========================================================================

    private function expand_series_tree( int $root_id ): array|WP_Error {

        /*
         * 佇列元素為 [ anilist_id, 是否經由 PARENT 抵達 ]。
         *
         * PARENT 代表「這部是某個更大母作品的子作品」。若照常從母作品繼續
         * 往外展開,長壽 IP 會整串被拉進來:實測《水星的魔女》經 PARENT 連到
         * 1979 年初代鋼彈,而初代鋼彈本身就有 36 條符合條件的邊,展開到 93 部
         * 仍未收斂。這不只跑不完,結果也是錯的——系列名稱取自 root 節點,
         * 整個鋼彈宇宙會被掛上「水星的魔女」這個 term。
         *
         * 因此經由 PARENT 抵達的節點「只收錄、不再往外展開」:母作品仍會
         * 出現在清單上供參考,但不會成為擴散的跳板。
         */
        $queue        = [ [ $root_id, false ] ];
        $visited      = [];
        $nodes        = [];
        $relation_map = [ $root_id => '' ];
        $has_failure  = false;

        while ( ! empty( $queue ) ) {

            // 達節點上限即停止,回傳部分結果並標記不完整,避免整個 AJAX 逾時。
            if ( count( $nodes ) >= self::MAX_SERIES_NODES ) {
                $has_failure = true;
                if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                    Anime_Sync_Error_Logger::warning( '系列展開：達節點上限，結果不完整', [
                        'root_id' => $root_id,
                        'limit'   => self::MAX_SERIES_NODES,
                    ] );
                }
                break;
            }

            [ $current_id, $via_parent ] = array_shift( $queue );
            if ( in_array( $current_id, $visited, true ) ) continue;
            $visited[] = $current_id;

            /*
             * 一次請求同時取回「節點顯示資料」與「關係」。
             * 兩者本來就是同一個 Media(id, type: ANIME) 物件,原本卻分成
             * fetch_anilist_node_data() + fetch_anilist_relations() 兩次請求,
             * 每次都各自過一次 2000ms 的 anilist 節流,等於每個節點多花一倍時間。
             */
            $bundle = $this->fetch_anilist_node_bundle( $current_id );
            if ( is_wp_error( $bundle ) ) {
                $has_failure = true;
                if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                    Anime_Sync_Error_Logger::warning( '系列展開：節點資料抓取失敗', [
                        'anilist_id' => $current_id,
                        'error'      => $bundle->get_error_message(),
                    ] );
                }
                continue;
            }

            $node_data = $bundle['node'];
            $relations = $bundle['relations'];

            $post_id = $this->find_existing_post( $current_id );

            /*
             * AniList 沒有中文標題,fetch_anilist_node_bundle() 只能回 romaji。
             * 站上已匯入的作品改用站內中文標題,否則系列名稱會退回 romaji,
             * 在 assign_series_taxonomy() 另外長出一個沒有文章的 romaji 空 term。
             */
            if ( $post_id > 0 ) {
                $node_data['title_chinese'] = (string) (
                    get_post_meta( $post_id, 'anime_title_chinese', true ) ?: get_the_title( $post_id )
                );
            }

            $node_data['relation_type'] = $relation_map[ $current_id ] ?? '';
            $node_data['imported']      = $post_id > 0;
            $node_data['post_id']       = $post_id;
            $node_data['edit_url']      = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';
            $nodes[]                    = $node_data;

            // 經由 PARENT 抵達的母作品:已收錄,但不從它繼續展開（理由見上方註解）。
            if ( $via_parent ) {
                continue;
            }

            foreach ( $relations as $rel ) {
                $nid = (int) ( $rel['node_id'] ?? 0 );
                if (
                    $nid > 0 &&
                    in_array( $rel['type'], self::SERIES_RELATION_TYPES, true ) &&
                    ( $rel['node_type'] ?? '' ) === 'ANIME' &&
                    ! in_array( $nid, $visited, true )
                ) {
                    if ( ! isset( $relation_map[ $nid ] ) ) {
                        $relation_map[ $nid ] = $rel['type'];
                    }
                    $queue[] = [ $nid, $rel['type'] === 'PARENT' ];
                }
            }
        }

        return [
            'nodes'       => $nodes,
            'has_failure' => $has_failure,
        ];
    }

    // =========================================================================
    // PRIVATE – 一次取回節點顯示資料 + 關係（供系列樹 BFS 使用）
    //
    // 節點資料與關係本來就同屬一個 Media(id, type: ANIME) 物件,分兩次請求
    // 等於白付一次 2000ms 的 anilist 節流。這裡合併成一次,並且照舊寫回
    // fetch_anilist_node_data() / fetch_anilist_relations() 各自的 transient,
    // 讓 find_series_root() 與重複分析仍然吃得到快取。
    // =========================================================================

    private function fetch_anilist_node_bundle( int $anilist_id ): array|WP_Error {

        $node_key = 'anime_sync_al_node_' . $anilist_id;
        $rel_key  = 'anime_sync_al_relations_' . $anilist_id;

        // 兩份快取都在才算命中;只有一份時仍要發請求,否則會拿到半套資料。
        $node_cached = get_transient( $node_key );
        $rel_cached  = get_transient( $rel_key );
        if ( $node_cached !== false && $rel_cached !== false ) {
            return [
                'node'      => (array) $node_cached,
                'relations' => (array) $rel_cached,
            ];
        }

        $query = '
        query ($id: Int) {
          Media(id: $id, type: ANIME) {
            id
            title { romaji native }
            coverImage { large }
            format
            season
            seasonYear
            relations {
              edges {
                relationType
                node { id type }
              }
            }
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'id' => $anilist_id ], 12 );
        if ( is_wp_error( $decoded ) ) return $decoded;

        $media = $decoded['data']['Media'] ?? null;
        if ( empty( $media ) ) {
            return new WP_Error( 'anilist_node_empty', "AniList returned no node data for ID {$anilist_id}." );
        }

        // 欄位結構必須與 fetch_anilist_node_data() 完全一致——兩者共用同一組 transient。
        // season 是本方法多帶的：系列命名要靠它去查 YourAnimes 的季度新番表。
        $node = [
            'anilist_id'    => (int) ( $media['id'] ?? $anilist_id ),
            'title_chinese' => '',
            'title_romaji'  => $media['title']['romaji'] ?? '',
            'title_native'  => $media['title']['native'] ?? '',
            'cover_image'   => $media['coverImage']['large'] ?? '',
            'format'        => $media['format']     ?? '',
            'season'        => $media['season']     ?? '',
            'season_year'   => $media['seasonYear'] ?? 0,
        ];

        // 同上,結構須與 fetch_anilist_relations() 一致。
        $relations = [];
        foreach ( (array) ( $media['relations']['edges'] ?? [] ) as $edge ) {
            $relations[] = [
                'type'      => $edge['relationType']  ?? '',
                'node_id'   => $edge['node']['id']    ?? 0,
                'node_type' => $edge['node']['type']  ?? '',
            ];
        }

        set_transient( $node_key, $node, 6 * HOUR_IN_SECONDS );
        set_transient( $rel_key, $relations, 6 * HOUR_IN_SECONDS );

        return [ 'node' => $node, 'relations' => $relations ];
    }

    // =========================================================================
    // PRIVATE – 取單一節點顯示資料
    //
    // 系列樹 BFS 已改用 fetch_anilist_node_bundle();本方法保留供單獨取用,
    // 兩者共用 anime_sync_al_node_* 這組 transient,欄位結構必須一致。
    // =========================================================================

    private function fetch_anilist_node_data( int $anilist_id ): array|WP_Error {

        $cache_key = 'anime_sync_al_node_' . $anilist_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $query = '
        query ($id: Int) {
          Media(id: $id, type: ANIME) {
            id
            title { romaji native }
            coverImage { large }
            format
            seasonYear
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'id' => $anilist_id ], 12 );
        if ( is_wp_error( $decoded ) ) return $decoded;

        $media = $decoded['data']['Media'] ?? null;
        if ( empty( $media ) ) {
            return new WP_Error( 'anilist_node_empty', "AniList returned no node data for ID {$anilist_id}." );
        }

        $node = [
            'anilist_id'    => (int) ( $media['id'] ?? $anilist_id ),
            'title_chinese' => '',
            'title_romaji'  => $media['title']['romaji'] ?? '',
            'title_native'  => $media['title']['native'] ?? '',
            'cover_image'   => $media['coverImage']['large'] ?? '',
            'format'        => $media['format']     ?? '',
            'season_year'   => $media['seasonYear'] ?? 0,
        ];

        set_transient( $cache_key, $node, 6 * HOUR_IN_SECONDS );
        return $node;
    }

    // =========================================================================
    // PRIVATE – 查找已存在的文章
    // =========================================================================

    private function find_existing_post( int $anilist_id ): int {
        if ( $anilist_id <= 0 ) return 0;

        $cache_key = 'anime_sync_existing_post_' . $anilist_id;
        $cached    = wp_cache_get( $cache_key, 'anime_sync' );
        if ( $cached !== false ) return (int) $cached;

        $q = new WP_Query( [
            'post_type'      => 'anime',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ [
                'key'     => 'anime_anilist_id',
                'value'   => $anilist_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ] ],
        ] );

        $post_id = ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
        wp_cache_set( $cache_key, $post_id, 'anime_sync', 300 );
        return $post_id;
    }

    // =========================================================================
    // PRIVATE – AniList 單部完整查詢
    // =========================================================================

    private function fetch_anilist_data( int $anilist_id ): array|WP_Error {

        $cache_key = 'anime_sync_anilist_' . $anilist_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $query = '
        query ($id: Int) {
          Media(id: $id, type: ANIME) {
            id idMal
            title { romaji english native }
            status format episodes duration source season seasonYear
            countryOfOrigin
            startDate { year month day }
            endDate   { year month day }
            averageScore popularity
            bannerImage
            coverImage { extraLarge large }
            description(asHtml: false)
            genres
            tags { name isMediaSpoiler }
            trailer { id site }
            nextAiringEpisode { airingAt episode }
            externalLinks { url site type language }
            studios(isMain: true) { nodes { name } }
            staff(sort: RELEVANCE, perPage: 25) {
              edges {
                role
                node { id name { full native } image { large } }
              }
            }
            characters(sort: ROLE, perPage: 25) {
              edges {
                role
                node { id name { full native } image { large } }
                voiceActors(language: JAPANESE) {
                  id name { full native } image { large }
                }
              }
            }
            relations {
              edges {
                relationType
                node { id type format countryOfOrigin title { romaji } }
              }
            }
          }
        }';

        $decoded = $this->anilist_request( $query, [ 'id' => $anilist_id ], 15 );
        if ( is_wp_error( $decoded ) ) return $decoded;

        if ( empty( $decoded['data']['Media'] ) ) {
            return new WP_Error( 'anilist_empty', "AniList returned no Media for ID {$anilist_id}." );
        }

        set_transient( $cache_key, $decoded, 6 * HOUR_IN_SECONDS );
        return $decoded;
    }

      // =========================================================================
    // PRIVATE – MAL 評分（ACH）
    // ★ [v1.3.1] 加入最多 3 次重試：cURL 錯誤與 429/5xx 才重試，
    //   4xx（如 404 查無此 MAL ID）不重試直接放棄；並補上 record_stat()
    //   統計呼叫，讓 jikan 的成功/失敗/限流/重試次數能出現在
    //   Anime_Sync_Rate_Limiter::get_stats() 裡，方便日後排查。
    // ★ [v1.4.8] 資料來源由 Jikan 改為 MyAnimeList 官方 API v2（直連 MAL，
    //   不經 Jikan，避免 Jikan 504 及 2026-10 停用問題）。Client ID 存於
    //   wp-config.php 常數 MAL_CLIENT_ID。節流/統計標籤沿用 'jikan' 不變。
    // =========================================================================

    /**
     * 取得 MAL 平均分（0–100）。
     *
     * @param int|null $mal_id
     * @param string   $type   'anime' 或 'manga'。
     *                         MAL 的動畫與漫畫是兩套獨立的 ID 空間，端點也不同；
     *                         拿漫畫 ID 去查 /v2/anime/ 不是查無資料，就是撈到
     *                         另一部剛好同號的無關動畫，寫進去就是錯的分數。
     */
    private function fetch_mal_score( ?int $mal_id, string $type = 'anime' ): int {
        if ( ! $mal_id || $mal_id <= 0 ) return 0;

        $type = ( 'manga' === $type ) ? 'manga' : 'anime';

        // 快取鍵帶上 type：動畫與漫畫的 ID 會重號，共用鍵會互相污染。
        $cache_key = 'anime_sync_mal_score_' . $type . '_' . $mal_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (int) $cached;

        // ✅ [v1.4.8] 官方 API 需要 Client ID（存於 wp-config.php，勿硬寫於程式碼）
        $client_id = defined( 'MAL_CLIENT_ID' ) ? MAL_CLIENT_ID : '';
        if ( $client_id === '' ) {
            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::warning( 'MAL 官方 API 未設定 Client ID（wp-config.php 缺 MAL_CLIENT_ID）', [
                    'mal_id' => $mal_id,
                ] );
            }
            return 0;
        }

        $max_attempts = 3;
        $attempt      = 0;

        while ( $attempt < $max_attempts ) {
            $attempt++;

            $this->rate_limiter->wait_if_needed( 'mal' );

            // ✅ [v1.4.8] 官方 API v2：?fields=mean 只取平均分，回傳最精簡
            $url = 'https://api.myanimelist.net/v2/' . $type . '/' . $mal_id . '?fields=mean';
            $ch  = curl_init();
            curl_setopt_array( $ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [ 'X-MAL-CLIENT-ID: ' . $client_id ],
            ] );
            $body = curl_exec( $ch );
            $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $err  = curl_error( $ch );
            curl_close( $ch );

            // cURL 層級錯誤（DNS / 連線逾時等）：重試
            if ( $err !== '' ) {
                $this->rate_limiter->record_stat( 'jikan', 'failed' );
                if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                    Anime_Sync_Error_Logger::warning( 'MAL cURL 錯誤', [
                        'mal_id'  => $mal_id,
                        'error'   => $err,
                        'attempt' => $attempt,
                    ] );
                }
                if ( $attempt < $max_attempts ) {
                    sleep( 3 );
                    $this->rate_limiter->record_stat( 'jikan', 'retry' );
                    continue;
                }
                return 0;
            }

            // 429（限流）與 5xx（伺服端暫時性錯誤）：重試
            if ( $code === 429 || $code >= 500 ) {
                $this->rate_limiter->record_stat( 'jikan', 'rate_limited' );
                if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                    Anime_Sync_Error_Logger::warning( 'MAL 暫時性錯誤，將重試', [
                        'mal_id'  => $mal_id,
                        'code'    => $code,
                        'attempt' => $attempt,
                    ] );
                }
                if ( $attempt < $max_attempts ) {
                    sleep( $code === 429 ? 5 : 3 );
                    $this->rate_limiter->record_stat( 'jikan', 'retry' );
                    continue;
                }
                $this->rate_limiter->record_stat( 'jikan', 'failed' );
                return 0;
            }

            // 其他 4xx（如 404 查無此 MAL ID、401/403 Client ID 無效）：不重試，直接放棄
            if ( $code !== 200 ) {
                $this->rate_limiter->record_stat( 'jikan', 'failed' );

                /*
                 * ★ 404 要快取，否則同一個壞 ID 會被無限重打。
                 *
                 * 本函式內部確實不重試（直接 return 0），但它是被外部佇列反覆
                 * 呼叫的——而 404 這條路徑原本完全不設 transient，於是下一輪
                 * 又是一次全新的請求。正式站實測：近 30 天 167 次，全部集中在
                 * 兩個不存在的 ID（999999、638290），對應兩篇文章。
                 *
                 * 404 的語意是「這個 ID 在 MAL 不存在」，屬於本地資料壞掉，
                 * 重試一萬次也不會成功——這與 class-cron-manager.php 對 AniList
                 * 404 的判斷一致（見 ANILIST_404_MAX_RETRY 的註解）。
                 *
                 * 不永久封鎖：MAL 條目偶爾會重建，一週後放行一次重試，
                 * 成本是每個壞 ID 每週一次請求，而不是每天數次。
                 * 401/403 是憑證問題，屬於全站性故障，不該記在單一 ID 上，
                 * 因此只快取 404。
                 */
                if ( 404 === $code ) {
                    set_transient( $cache_key, 0, WEEK_IN_SECONDS );
                }

                if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                    Anime_Sync_Error_Logger::warning( 'MAL HTTP 非 200', [
                        'mal_id' => $mal_id,
                        'code'   => $code,
                        'cached' => 404 === $code ? '已快取 7 天，暫停重試' : '未快取',
                    ] );
                }

                return 0;
            }

            $data  = json_decode( $body, true );
            // ✅ [v1.4.8] 官方 API v2 平均分欄位為 mean（0–10），換算為 0–100 制
            $score = isset( $data['mean'] ) ? (int) round( (float) $data['mean'] * 10 ) : 0;

            $this->rate_limiter->record_stat( 'jikan', 'success' );
            // 有分才快取 12 小時;查無 mean(score=0)只快取 10 分鐘,讓之後能較快重試
            set_transient( $cache_key, $score, $score > 0 ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
            return $score;
        }

        return 0;
    }


    // =========================================================================
    // PRIVATE – Wikipedia URL
    // =========================================================================

    private function fetch_wikipedia_url( string $title_chinese, string $title_native, string $title_romaji, string $title_english ): string {
        $candidates = [ $title_chinese, $title_native, $title_romaji, $title_english ];

        if ( $title_chinese !== '' ) {
            $url = $this->search_wiki_zh( $title_chinese, $candidates );
            if ( $url !== '' ) return $url;
        }
        if ( $title_native !== '' ) {
            $url = $this->search_wiki_zh( $title_native, $candidates );
            if ( $url !== '' ) return $url;
        }

        foreach ( [ $title_english, $title_romaji ] as $try ) {
            $try = trim( $try );
            if ( $try === '' ) continue;
            $url = $this->search_wiki_en( $try, $candidates );
            if ( $url !== '' ) return $url;
        }

        return '';
    }

    private function search_wiki_zh( string $title, array $candidates = [] ): string {
        $hits = $this->query_wiki_search( self::WIKI_ZH_API, $title );
        foreach ( $hits as $hit ) {
            $page_title = $hit['title'] ?? '';
            if ( $page_title === '' ) continue;
            if ( $this->wiki_title_matches( $page_title, $candidates ) ) {
                return 'https://zh.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $page_title ) );
            }
        }
        return '';
    }

    private function search_wiki_en( string $title, array $candidates = [] ): string {
        $hits = $this->query_wiki_search( 'https://en.wikipedia.org/w/api.php', $title );
        foreach ( $hits as $hit ) {
            $page_title = $hit['title'] ?? '';
            if ( $page_title === '' ) continue;
            if ( $this->wiki_title_matches( $page_title, $candidates ) ) {
                return 'https://en.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $page_title ) );
            }
        }
        return '';
    }

    private function query_wiki_search( string $api_url, string $title ): array {
        $title = trim( $title );
        if ( $title === '' ) return [];

        $response = wp_remote_get( add_query_arg( [
            'action'      => 'query',
            'list'        => 'search',
            'srsearch'    => $title,
            'srlimit'     => 5,
            'srnamespace' => 0,
            'format'      => 'json',
        ], $api_url ), [
            'timeout'    => 5,
            'user-agent' => self::USER_AGENT,
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['query']['search'] ?? [];
    }

    private function wiki_title_matches( string $page_title, array $candidates ): bool {
        $page_title = preg_replace( '/\s*[\(（][^\)）]*[\)）]\s*$/u', '', (string) $page_title );
        $page_lower = mb_strtolower( $page_title );

        foreach ( $candidates as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( $candidate === '' ) continue;
            $cand_lower = mb_strtolower( $candidate );

            if ( mb_strpos( $page_lower, $cand_lower ) !== false || mb_strpos( $cand_lower, $page_lower ) !== false ) {
                return true;
            }

            similar_text( $page_lower, $cand_lower, $percent );
            if ( $percent >= 35 ) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // PRIVATE – Bangumi 資料（ACG：統一 User-Agent）
    // =========================================================================

    private function get_bangumi_data( int $bangumi_id ): array|WP_Error {

        $cache_key = 'anime_sync_bgm_subject_' . $bangumi_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get( self::BGM_SUBJECT_URL . $bangumi_id, [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) ) return $response;
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'bgm_http_error', "Bangumi returned HTTP {$code} for ID {$bangumi_id}." );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data ) ) {
            return new WP_Error( 'bgm_empty', "Bangumi returned empty data for ID {$bangumi_id}." );
        }

        set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    private function get_bgm_staff( int $bangumi_id, array $infobox = [] ): array {
        $cache_key = 'anime_sync_bgm_staff_' . $bangumi_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $response = wp_remote_get( self::BGM_SUBJECT_URL . $bangumi_id . '/persons', [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return [];

        $persons = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $persons ) ) return [];

        /*
         * 2026-08-31：這裡原本是一份 14 種職位的白名單，只收名單內的職位。
         *
         * 拿掉的理由是使用者的判斷：「要尊重製作方，全部顯示」。實測
         * Bangumi 一部作品可以有 50 幾種職位（原画、第二原画、补间动画、
         * 色彩指定、摄影监督…），白名單只留下其中 6-7 筆，一整批實際參與
         * 製作的人被隱形。
         *
         * 那份名單沒有刪掉，改放到 Anime_Sync_Staff_Roles::PRIMARY，
         * 用途從「過濾」變成「排序與分組」——前台把那幾種排在最前面，
         * 其餘依職位分組列在後面。既有的職位判斷保留，只是不再擋資料。
         *
         * 規模（14 部隨機抽樣）：平均 121 筆、最多 475 筆、最少 1 筆。
         * 實測 475 筆的 JSON 是 70KB，json_decode 多花 0.34ms，
         * 相對於頁面 150ms 的 TTFB 可以忽略，所以維持存在 postmeta。
         */
        $staff = [];
        foreach ( $persons as $p ) {
            /*
             * 職位名稱在這裡就正規化好存下去，前台只查表不轉換。
             * Bangumi 回的是簡體（导演、企画协力、总制片人），280 種裡
             * LABELS 只蓋到 66 種，其餘交給 CN_Converter。詳見 normalize()。
             */
            $role = class_exists( 'Anime_Sync_Staff_Roles' )
                ? Anime_Sync_Staff_Roles::normalize( (string) ( $p['relation'] ?? '' ) )
                : ( $p['relation'] ?? '' );

            if ( '' !== trim( (string) $role ) ) {
                $staff[] = [
                    'id'     => $p['id']             ?? 0,
                    /*
                     * 人名不做簡繁轉換。
                     *
                     * 轉換器會把日文漢字當成簡體處理，產生不存在的寫法：
                     *   岩里祐穂 → 巖裡祐穂（岩→巖、里→裡）
                     *   前田佳織里 → 前田佳織裡、日高里菜 → 日高裡菜
                     * 實測站上受損 678 筆。職稱（role）仍然要轉，那是中文用語。
                     */
                    'name'   => (string) ( $p['name'] ?? '' ),
                    'role'   => $role,
                    'image'  => $p['images']['large'] ?? $p['images']['medium'] ?? '',
                    'source' => 'bangumi',
                ];
            }
        }

        $has_original = false;
        foreach ( $staff as $s ) {
            if ( $s['role'] === '原作' ) { $has_original = true; break; }
        }
        if ( ! $has_original && ! empty( $infobox ) ) {
            $original_name = $this->extract_infobox_original( $infobox );
            if ( $original_name !== '' ) {
                $staff[] = [
                    'id'     => 0,
                    // 原作者姓名，同樣不做簡繁轉換（見上方 'name' 的說明）
                    'name'   => (string) $original_name,
                    'role'   => '原作',
                    'image'  => '',
                    'source' => 'bangumi_infobox',
                ];
            }
        }

        usort( $staff, function( $a, $b ) {
            if ( $a['role'] === '原作' ) return -1;
            if ( $b['role'] === '原作' ) return 1;
            return 0;
        } );

        set_transient( $cache_key, $staff, 12 * HOUR_IN_SECONDS );
        return $staff;
    }

    private function extract_infobox_original( array $infobox ): string {
        foreach ( $infobox as $row ) {
            $key = $row['key'] ?? '';
            if ( $key !== '原作' && $key !== '原著' ) continue;

            $value = $row['value'] ?? '';
            if ( is_array( $value ) ) {
                $parts = [];
                foreach ( $value as $v ) {
                    if ( isset( $v['v'] ) && $v['v'] !== '' ) $parts[] = $v['v'];
                }
                $value = implode( '、', $parts );
            }
            $value = (string) $value;

            $value = preg_replace( '/[（(].*?[)）]/u', '', $value );
            $value = preg_split( '/[；;]/u', $value )[0] ?? $value;
            $value = trim( $value );

            return $value;
        }
        return '';
    }

    /**
     * 漫畫版 STAFF：與 get_bgm_staff() 共用 /persons 端點與中文姓名轉換，
     * 但不套用動畫那份職位白名單（导演/系列构成/音楽…全是動畫製作職位，
     * 對漫畫不適用）。漫畫 persons 名單本來就短，先不過濾，
     * 之後看實際同步結果需要再補白名單。
     */
    private function get_bgm_manga_staff( int $bangumi_id, array $infobox = [] ): array {
        $cache_key = 'anime_sync_bgm_manga_staff_' . $bangumi_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $response = wp_remote_get( self::BGM_SUBJECT_URL . $bangumi_id . '/persons', [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return [];

        $persons = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $persons ) ) return [];

        $staff = [];
        foreach ( $persons as $p ) {
            $role = trim( (string) ( $p['relation'] ?? '' ) );
            $name = $p['name'] ?? '';
            if ( $role === '' || $name === '' ) continue;

            /*
             * 職位名稱轉繁。動畫那邊走 Staff_Roles::normalize()（多一層
             * 製作職位對照表，处理 导演→監督 這種用語差異），漫畫用不到
             * 那份表——實際出現的是 连载杂志／译者／插图／作画 這類，
             * 純簡繁轉換就夠，所以直接用 CN_Converter。
             */
            if ( class_exists( 'Anime_Sync_CN_Converter' ) ) {
                $role = Anime_Sync_CN_Converter::static_convert( $role );
            }

            $staff[] = [
                'id'     => $p['id']             ?? 0,
                // 人名不轉（見 get_bgm_staff 的說明）；role 上面已單獨轉過
                'name'   => (string) $name,
                'role'   => $role,
                'image'  => $p['images']['large'] ?? $p['images']['medium'] ?? '',
                'source' => 'bangumi',
            ];
        }

        $has_original = false;
        foreach ( $staff as $s ) {
            if ( $s['role'] === '原作' || $s['role'] === '原著' ) { $has_original = true; break; }
        }
        if ( ! $has_original && ! empty( $infobox ) ) {
            $original_name = $this->extract_infobox_original( $infobox );
            if ( $original_name !== '' ) {
                $staff[] = [
                    'id'     => 0,
                    // 原作者姓名，同樣不做簡繁轉換（見上方 'name' 的說明）
                    'name'   => (string) $original_name,
                    'role'   => '原作',
                    'image'  => '',
                    'source' => 'bangumi_infobox',
                ];
            }
        }

        usort( $staff, function( $a, $b ) {
            if ( $a['role'] === '原作' ) return -1;
            if ( $b['role'] === '原作' ) return 1;
            return 0;
        } );

        set_transient( $cache_key, $staff, 12 * HOUR_IN_SECONDS );
        return $staff;
    }

    private function get_bgm_chars( int $bangumi_id ): array {
        $cache_key = 'anime_sync_bgm_chars_' . $bangumi_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $chars = $this->get_bgm_chars_legacy( $bangumi_id );
        if ( empty( $chars ) ) {
            $this->rate_limiter->wait_if_needed( 'bangumi' );
            $chars = $this->get_bgm_chars_v0( $bangumi_id );
        }

        if ( ! empty( $chars ) ) {
            set_transient( $cache_key, $chars, 12 * HOUR_IN_SECONDS );
        }

        return $chars;
    }

    /**
     * 走 Bangumi 舊版 API(responseGroup=large)。
     * 這個端點的 actors 陣列會依官網「CV 在前、其他語言配音在後」的順序回傳
     * (對應官網頁面 attr-rlt-primary 標記)，voice_actors[0] 才是真正的原版聲優；
     * 其餘（中配/英配等）保留在陣列後面，不丟棄，留給日後多語言功能使用。
     * role_name === '闲角'（モブキャラクター等背景角色集合）也完整保留，
     * 是否顯示交給前台樣板決定，這裡只負責把 Bangumi 的資料原樣存好。
     */
    private function get_bgm_chars_legacy( int $bangumi_id ): array {
        $response = wp_remote_get( self::BGM_LEGACY_SUBJECT_URL . $bangumi_id . '?responseGroup=large', [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return [];

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || ! isset( $data['crt'] ) || ! is_array( $data['crt'] ) ) return [];

        $chars = [];
        foreach ( $data['crt'] as $c ) {
            $role = $c['role_name'] ?? '';

            $va = [];
            foreach ( $c['actors'] ?? [] as $a ) {
                $va[] = [
                    'id'    => $a['id']             ?? 0,
                    // 聲優本人姓名，不做簡繁轉換（見 get_bgm_staff 的說明）
                    'name'  => (string) ( $a['name'] ?? '' ),
                    'image' => $a['images']['large'] ?? '',
                ];
            }
            $chars[] = [
                'id'           => $c['id']             ?? 0,
                'name'         => Anime_Sync_CN_Converter::static_convert( $c['name'] ?? '' ),
                'role'         => $role,
                'image'        => $c['images']['large'] ?? $c['images']['medium'] ?? '',
                'voice_actors' => $va,
                'source'       => 'bangumi',
            ];
        }

        return $chars;
    }

    /**
     * Fallback：v0 新版 API。actors 陣列未排序、無語言標記，voice_actors[0]
     * 不保證是原版聲優，只在舊版 API 打不通時使用。
     */
    private function get_bgm_chars_v0( int $bangumi_id ): array {
        $response = wp_remote_get( self::BGM_SUBJECT_URL . $bangumi_id . '/characters', [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return [];

        $chars_raw = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $chars_raw ) ) return [];

        $chars = [];
        foreach ( $chars_raw as $c ) {
            $va = [];
            foreach ( $c['actors'] ?? [] as $a ) {
                $va[] = [
                    'id'    => $a['id']             ?? 0,
                    // 聲優本人姓名，不做簡繁轉換（見 get_bgm_staff 的說明）
                    'name' => (string) ( $a['name'] ?? '' ),
                    'image' => $a['images']['large'] ?? '',
                ];
            }
            $chars[] = [
                'id'           => $c['id']             ?? 0,
                'name' => Anime_Sync_CN_Converter::static_convert( $c['name'] ?? '' ),
                'role'         => $c['relation']        ?? '',
                'image'        => $c['images']['large'] ?? $c['images']['medium'] ?? '',
                'voice_actors' => $va,
                'source'       => 'bangumi',
            ];
        }

        return $chars;
    }

    /**
     * 取回 Bangumi 集數（本篇 + SP）。
     *
     * 原本查詢寫死 type=0，只拿本篇，SP 從來抓不到——例如 K-ON!! (bgm 3774)
     * 的「番外編 計画！」(episode 53829、type=1) 就一直不在集數列表裡。
     *
     * 改為不帶 type 參數一次取回全部再於本地分流：實測同一個條目回 27 筆
     * （26 本篇 + 1 SP），請求數與原本相同，不增加 Bangumi 負擔。
     * Bangumi 的 type：0 本篇、1 SP、2 OP、3 ED、4 預告、6 其他；
     * OP／ED／預告不是觀看單位，不收。
     *
     * $post_id 供 select_episodes_for_post() 判定「單集作品對到母條目」的
     * 錯配情境，未傳入時維持「本篇 + SP 全給」的行為。
     */
    public function fetch_bgm_episodes( int $bangumi_id, bool $force_refresh = false, int $post_id = 0 ): array {
        $cache_key = 'anime_sync_bgm_eps_' . $bangumi_id;

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                $sets = $this->normalize_episode_cache( $cached );
                return $this->localize_chinese_episode_names(
                    $this->select_episodes_for_post( $sets['main'], $sets['sp'], $post_id ),
                    $post_id
                );
            }
        }

        $response = wp_remote_get( add_query_arg( [
            'subject_id' => $bangumi_id,
            'limit'      => 100,
            'offset'     => 0,
        ], self::BGM_EPISODES_URL ), [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $eps  = $body['data'] ?? [];
        if ( ! is_array( $eps ) ) {
            return [];
        }

        $main = [];
        $sp   = [];

        foreach ( $eps as $ep ) {
            $type = (int) ( $ep['type'] ?? 0 );

            if ( $type !== 0 && $type !== 1 ) {
                continue;
            }

            $row = [
                'id'      => $ep['id']      ?? 0,
                'ep'      => $ep['ep']      ?? 0,
                'sort'    => $ep['sort']    ?? 0,
                'type'    => $type,
                'name'    => $ep['name']    ?? '',
                'name_cn' => $ep['name_cn'] ?? '',
                'airdate' => $ep['airdate'] ?? '',
                'comment' => (int) ( $ep['comment'] ?? 0 ),
            ];

            if ( $type === 1 ) {
                $sp[] = $row;
            } else {
                $main[] = $row;
            }
        }

        set_transient( $cache_key, [ 'main' => $main, 'sp' => $sp ], 12 * HOUR_IN_SECONDS );

        return $this->localize_chinese_episode_names(
            $this->select_episodes_for_post( $main, $sp, $post_id ),
            $post_id
        );
    }

    /**
     * 中文圈作品（CN/TW/HK）：把簡體原文轉繁體填進 name_cn。
     *
     * ★ 為什麼需要這個：
     *   Bangumi 的 name 是「原文」、name_cn 是「中文譯名」。日本動畫的
     *   name 是日文、name_cn 是（簡體）中文，前台顯示 name_cn 並轉繁即可。
     *   但中國／台灣／香港製作的動畫，Bangumi 認為「原文已經是中文」，
     *   於是 name 放簡體中文、name_cn 直接留空。前台在 name_cn 為空時會
     *   fallback 顯示 name，而那條路徑沒有繁簡轉換，簡體就這樣露出到前台
     *   （實例：《時光代理人》顯示「只许输，不许赢」）。
     *
     * ★ 為什麼用 anime_country 判斷，而不是猜字元：
     *   實測過三種自動判斷都不可靠——日文假名偵測會漏掉純漢字日文標題；
     *   簡體字偵測會把日文新字體（学/体/万/会/来/断）誤判成簡體；
     *   逐字比對則無法區分「日文漢字」與「中文繁體」。anime_country 來自
     *   AniList 的 countryOfOrigin，是明確的來源國事實，不用猜。
     *
     * ★ 為什麼放在快取之後：
     *   transient 只以 bangumi_id 為 key、不含 post_id，所以快取一律存
     *   Bangumi 原始資料，轉換留到取用時依當前作品的來源國決定，
     *   既避免不同作品共用快取造成污染，已存在的快取也能立即受惠。
     *
     * @param array $episodes 已挑選好的集數陣列。
     * @param int   $post_id  對應的 anime 文章 ID；0 代表無從判斷來源國。
     * @return array
     */
    private function localize_chinese_episode_names( array $episodes, int $post_id ): array {
        if ( $post_id <= 0 || empty( $episodes ) || ! class_exists( 'Anime_Sync_CN_Converter' ) ) {
            return $episodes;
        }

        $country = strtoupper( trim( (string) get_post_meta( $post_id, 'anime_country', true ) ) );
        if ( ! in_array( $country, [ 'CN', 'TW', 'HK' ], true ) ) {
            return $episodes;
        }

        foreach ( $episodes as &$ep ) {
            if ( ! is_array( $ep ) ) {
                continue;
            }
            $name    = trim( (string) ( $ep['name'] ?? '' ) );
            $name_cn = trim( (string) ( $ep['name_cn'] ?? '' ) );

            // 只補空的 name_cn；Bangumi 已提供譯名時尊重原資料，不覆蓋。
            if ( $name === '' || $name_cn !== '' ) {
                continue;
            }
            $ep['name_cn'] = Anime_Sync_CN_Converter::static_convert( $name );
        }
        unset( $ep );

        return $episodes;
    }

    /**
     * 相容舊版快取結構。
     *
     * v1 存的是平面陣列（只有本篇、無 type 欄位）。快取未過期前仍會讀到，
     * 一律視為本篇，SP 待快取過期後的下一次抓取自然補上。
     */
    private function normalize_episode_cache( $cached ): array {
        if ( is_array( $cached ) && isset( $cached['main'] ) ) {
            return [
                'main' => (array) $cached['main'],
                'sp'   => (array) ( $cached['sp'] ?? [] ),
            ];
        }

        return [ 'main' => (array) $cached, 'sp' => [] ];
    }

    /**
     * 決定這篇該拿哪些集數。
     *
     * 錯配情境：SP／OVA 在 AniList 有獨立條目，Bangumi 卻只把它當成母作品的
     * 一集，mapper 因此只能對應到母條目的 bgm_id。照抓會變成「單集 OVA 的
     * 集數列表顯示母作品整季 26 話」——實例 K-ON!!: Keikaku!（AniList 9734、
     * OVA、episodes=1）對到 K-ON!! 母條目 bgm 3774，站上共 36 篇有同樣狀況。
     *
     * 判定需要三個條件同時成立：
     *   1. AniList 說這部只有 1 集
     *   2. Bangumi 本篇不只 1 集
     *   3. 該條目底下有 SP
     *
     * 第 3 點不可省。只看前兩點會誤判「Bangumi 把一部劇場版拆成數個部分、
     * AniList 算 1 集」的正常情況——實測 37 篇符合前兩點的作品裡有 16 篇
     * 屬於這類（例：數碼寶貝 tri. 各章 bgm 條目本篇 4~5 筆但無 SP、
     * 新世紀福音戰士劇場版 2 筆無 SP），那些本篇是正確資料，清掉會變成
     * 空的集數列表。有 SP 存在才代表這個 bgm_id 是母條目、本作是其中的 SP。
     */
    private function select_episodes_for_post( array $main, array $sp, int $post_id ): array {
        if ( $post_id > 0 && count( $main ) > 1 && ! empty( $sp ) ) {
            $declared = (int) get_post_meta( $post_id, 'anime_episodes', true );

            if ( $declared === 1 ) {
                return $sp;
            }
        }

        return array_merge( $main, $sp );
    }

    // =========================================================================
    // PRIVATE – AnimeThemes（ACF / ACG）
    // v1.3.0 — fetch_animethemes() 精簡為呼叫共用解析器 parse_animethemes_payload()；
    //          新增 fetch_animethemes_by_slug() 供 slug 直查。
    // =========================================================================

    /**
     * 以 MAL ID 查 AnimeThemes（原路徑，回傳 [ 'slug' => ..., 'themes' => [...] ]）。
     */
    public function fetch_animethemes( ?int $mal_id ): array {
        if ( ! $mal_id || $mal_id <= 0 ) return [];

        $cache_key = 'anime_sync_themes_' . $mal_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $this->rate_limiter->wait_if_needed( 'animethemes' );
        $response = wp_remote_get( add_query_arg( [
            'filter[has]'         => 'resources',
            'filter[site]'        => 'MyAnimeList',
            'filter[external_id]' => $mal_id,
            'include'             => 'animethemes.animethemeentries.videos.audio,animethemes.song.artists',
            'fields[anime]'       => 'slug',
            'fields[animetheme]'  => 'type,sequence,slug',
            'fields[song]'        => 'title',
            'fields[artist]'      => 'name',
            'fields[video]'       => 'link,resolution',
            'fields[audio]'       => 'link',
        ], self::ANIMETHEMES_URL ), [
            'timeout' => 8,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) return [];

        $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        $anime_arr = $body['anime'] ?? [];
        if ( empty( $anime_arr ) ) return [];

        $result = $this->parse_animethemes_payload( $anime_arr, (int) $mal_id );
        set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );
        return $result;
    }

    /**
     * ★ [v1.3.0] 以 AnimeThemes slug 直接查 /anime/{slug} 端點。
     *
     * 供 CRON「slug 優先」策略使用：當 mal_id 缺失或以 mal_id 查無結果時，
     * 改用使用者手填的 slug 直接抓主題曲。回傳結構與 fetch_animethemes() 完全一致。
     *
     * @param string   $slug              AnimeThemes slug（例：shingeki_no_kyojin_the_final_season_kanketsuhen_kouhen）。
     * @param int|null $mal_id_for_native 若有 MAL ID，仍用它抓 Jikan 日文對照表；沒有就傳 0。
     * @return array   [ 'slug' => string, 'themes' => array ]，查無回 []。
     */
    public function fetch_animethemes_by_slug( string $slug, ?int $mal_id_for_native = 0 ): array {
        $slug = trim( $slug );
        if ( $slug === '' ) return [];

        $cache_key = 'anime_sync_themes_slug_' . md5( $slug );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $this->rate_limiter->wait_if_needed( 'animethemes' );

        // by-slug 端點：/anime/{slug}，用 query string 帶 include / fields。
        $url = self::ANIMETHEMES_URL . '/' . rawurlencode( $slug );
        $response = wp_remote_get( add_query_arg( [
            'include'            => 'animethemes.animethemeentries.videos.audio,animethemes.song.artists',
            'fields[anime]'      => 'slug',
            'fields[animetheme]' => 'type,sequence,slug',
            'fields[song]'       => 'title',
            'fields[artist]'     => 'name',
            'fields[video]'      => 'link,resolution',
            'fields[audio]'      => 'link',
        ], $url ), [
            'timeout' => 8,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        // 404（slug 不存在）或其他非 200 都視為查無，回 [] 讓上層 fallback。
        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // by-slug 端點回傳最外層是單一 anime 物件（不是 anime 陣列），
        // 包成陣列後丟給共用解析器，維持與 by-mal 相同的處理路徑。
        $anime_obj = $body['anime'] ?? null;
        if ( empty( $anime_obj ) ) return [];

        $result = $this->parse_animethemes_payload( [ $anime_obj ], (int) $mal_id_for_native );

        // 若 API 未回 slug（理論上會回），至少保底填入使用者傳入的 slug。
        if ( ( $result['slug'] ?? '' ) === '' ) {
            $result['slug'] = $slug;
        }

        set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );
        return $result;
    }

    /**
     * ★ [v1.3.0] 共用解析器：把 AnimeThemes 的 anime 陣列解析成 themes 結構。
     *
     * 由 fetch_animethemes()（by mal_id）與 fetch_animethemes_by_slug()（by slug）共用，
     * 避免解析邏輯有兩份。行為與 v1.2.1 原 fetch_animethemes() 內迴圈完全一致。
     *
     * @param array $anime_arr AnimeThemes API 回傳的 anime 陣列（by-slug 需自行包成陣列）。
     * @param int   $mal_id    用來抓 Jikan 日文標題對照的 MAL ID；0 代表跳過日文對照。
     * @return array [ 'slug' => string, 'themes' => array ]
     */
    /**
     * 這位歌手的正式名稱本來就是拉丁字母，不該被換成日文寫法。
     *
     * 多數日本歌手的拉丁名只是轉寫（Konomi Suzuki ↔ 鈴木このみ），換成日文
     * 才是正確的；但有一類歌手的官方名稱本身就是拉丁字母，MusicBrainz 卻
     * 給了片假名（Aimer → エメ、RIIZE → ライズ、muque → ムク），照著顯示
     * 反而是錯的。判斷準則：拉丁名若是日文的轉寫，兩者發音會對得上
     * （エメ 轉回羅馬字是 Eme，與 Aimer 對不上）；要程式自動比對得先做
     * 片假名轉羅馬字，成本與誤判率都不低，數量又少，因此用明列的方式處理。
     *
     * 需要增補時可用 wxacg_artist_latin_names 篩選器，不必改動程式碼。
     *
     * @param string $name AnimeThemes 提供的歌手名。
     */
    private function artist_keeps_latin_name( string $name ): bool {
        static $set = null;

        if ( $set === null ) {
            $list = (array) apply_filters( 'wxacg_artist_latin_names', [
                // MusicBrainz 目前會給片假名、但官方名是拉丁字母的
                'Aimer', 'RIIZE', 'muque', 'Lucky Kilimanjaro', 'Polkadot Stingray',
                // 官方名即拉丁字母，先列出避免日後來源資料變動而被改寫
                'LiSA', 'KOTOKO', 'ClariS', 'fripSide', 'EGOIST', 'ReoNa', 'milet',
                'YOASOBI', 'RADWIMPS', 'Ado', 'Eve', 'ZAQ', 'ASCA', 'TrySail',
                'GARNiDELiA', 'MYTH & ROID', 'FLOW', 'ONE OK ROCK', 'BUMP OF CHICKEN',
                'SPYAIR', 'UVERworld', 'King Gnu', 'Vaundy', 'Aqours', 'DECO*27',
            ] );

            $set = [];
            foreach ( $list as $n ) {
                $key = mb_strtolower( preg_replace( '/\s+/u', '', (string) $n ) );
                if ( $key !== '' ) {
                    $set[ $key ] = true;
                }
            }
        }

        return isset( $set[ mb_strtolower( preg_replace( '/\s+/u', '', $name ) ) ] );
    }

    /**
     * 查站內既有資料中，這位歌手是否已經有原文名。
     *
     * 對照表由所有 anime_themes 掃出來，以正規化後的羅馬字名為鍵。
     * 快取 12 小時：匯入是分批進行的，同一輪內大量重複查詢不必每次掃資料庫；
     * 過期重建才能把新查到的原文納入，形成「查到一次就長期受益」。
     *
     * @param string $name AnimeThemes 提供的歌手名（多為羅馬字）。
     * @return string 找到的原文名；沒有則回空字串。
     */
    private function lookup_known_artist_native( string $name ): string {
        static $map = null;

        if ( $map === null ) {
            $cached = get_transient( 'anime_sync_artist_native_map' );

            if ( is_array( $cached ) ) {
                $map = $cached;
            } else {
                global $wpdb;
                $map = [];

                $rows = $wpdb->get_col(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = 'anime_themes' AND meta_value <> '' AND meta_value <> '[]'"
                );

                foreach ( $rows as $json ) {
                    $themes = json_decode( (string) $json, true );
                    if ( ! is_array( $themes ) ) {
                        continue;
                    }

                    foreach ( $themes as $t ) {
                        foreach ( (array) ( $t['artists'] ?? [] ) as $a ) {
                            $native = trim( (string) ( $a['name_native'] ?? '' ) );
                            $romaji = trim( (string) ( $a['name'] ?? '' ) );

                            if ( $native === '' || $romaji === '' ) {
                                continue;
                            }

                            $key = $this->normalize_title( $romaji );
                            if ( $key !== '' && ! isset( $map[ $key ] ) ) {
                                $map[ $key ] = $native;
                            }
                        }
                    }
                }

                set_transient( 'anime_sync_artist_native_map', $map, 12 * HOUR_IN_SECONDS );
            }
        }

        return $map[ $this->normalize_title( $name ) ] ?? '';
    }

    private function parse_animethemes_payload( array $anime_arr, int $mal_id ): array {
        if ( empty( $anime_arr ) ) return [ 'slug' => '', 'themes' => [] ];

        $jikan_map = ( $mal_id > 0 ) ? $this->fetch_jikan_theme_natives( $mal_id ) : [];

        $slug_blacklist = [ '-EN', '-EN4Kids', '-YorinukiGintamaSan', '-Lyrics', '-Subbed', '-NCBDv2' ];

        $slug   = '';
        $themes = [];

        foreach ( $anime_arr as $anime_obj ) {
            if ( $slug === '' ) $slug = $anime_obj['slug'] ?? '';

            foreach ( $anime_obj['animethemes'] ?? [] as $theme ) {

                $theme_slug     = $theme['slug'] ?? '';
                $is_blacklisted = false;
                foreach ( $slug_blacklist as $suffix ) {
                    if ( str_contains( $theme_slug, $suffix ) ) {
                        $is_blacklisted = true;
                        break;
                    }
                }
                if ( $is_blacklisted ) continue;

                $entries    = $theme['animethemeentries'] ?? [];
                $entry      = ! empty( $entries ) ? $entries[0] : [];
                $videos     = $entry['videos'] ?? [];
                $best_video = [];
                $best_res   = 0;
                foreach ( $videos as $v ) {
                    $res = (int) ( $v['resolution'] ?? 0 );
                    if ( $res > $best_res ) {
                        $best_res   = $res;
                        $best_video = $v;
                    }
                }
                if ( empty( $best_video ) && ! empty( $videos ) ) $best_video = $videos[0];

                $audio_url = $best_video['audio']['link'] ?? '';
                $video_url = $best_video['link']          ?? '';

                $song_title   = $theme['song']['title'] ?? '';
                $title_native = $jikan_map[ $this->normalize_title( $song_title ) ] ?? '';

                $artists = [];
                foreach ( $theme['song']['artists'] ?? [] as $a ) {
                    $name = trim( $a['name'] ?? '' );
                    if ( $name === '' ) continue;
                    $mb   = $this->fetch_mb_artist( $name );

                    $name_native = trim( (string) ( $mb['name_native'] ?? '' ) );

                    if ( $this->artist_keeps_latin_name( $name ) ) {
                        // 官方名就是拉丁字母，清掉日文寫法讓顯示端退回原名
                        $name_native = '';
                    } elseif ( $name_native === '' ) {
                        /*
                         * MusicBrainz 用名字查有時對不到（拼法差異、同名藝人、
                         * 資料缺漏），該歌手就只剩羅馬字可顯示。但同一位歌手很可能
                         * 在別部作品已經查到過原文，這裡改查站內既有資料當後援，
                         * 讓「查過一次就一直有」而不是每次重新賭 MB 查不查得到。
                         */
                        $name_native = $this->lookup_known_artist_native( $name );
                    }

                    $artists[] = [
                        'name'        => $name,
                        'name_native' => $name_native,
                        'name_legal'  => $mb['name_legal']  ?? '',
                        'mbid'        => $mb['mbid']         ?? '',
                    ];
                }

                $themes[] = [
                    'type'         => $theme['type']     ?? '',
                    'sequence'     => $theme['sequence'] ?? null,
                    'slug'         => $theme_slug,
                    'title'        => $song_title,
                    'title_native' => $title_native,
                    'artists'      => $artists,
                    'audio_url'    => $audio_url,
                    'video_url'    => $video_url,
                    'resolution'   => $best_video['resolution'] ?? '',
                    'spoiler'      => ! empty( $entry['spoiler'] ),
                    'nsfw'         => ! empty( $entry['nsfw'] ),
                ];
            }
        }

        return [ 'slug' => $slug, 'themes' => $themes ];
    }

       // =========================================================================
    // PRIVATE – MAL 官方 API 日文主題曲標題對照表
    // ★ [v1.4.9] 資料來源由 Jikan /themes 改為 MyAnimeList 官方 API v2 的
    //   opening_themes / ending_themes 隱藏欄位（避免 Jikan 504 與 2026-10 停用）。
    //   官方 text 格式："羅馬名 (日文名)" by 演唱者 (演唱者日文) (eps 範圍)；
    //   日文歌名取歌名部分第一組括號內、且含中日文字元者。函式名沿用不變，
    //   呼叫端 parse_animethemes_payload() 無需調整。
    // =========================================================================
    private function fetch_jikan_theme_natives( int $mal_id ): array {
        $cache_key = 'anime_sync_mal_themes_' . $mal_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        $client_id = defined( 'MAL_CLIENT_ID' ) ? MAL_CLIENT_ID : '';
        if ( $client_id === '' ) {
            if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
                Anime_Sync_Error_Logger::warning( 'MAL 官方 API 未設定 Client ID（主題曲日文對照略過）', [
                    'mal_id' => $mal_id,
                ] );
            }
            set_transient( $cache_key, [], 6 * HOUR_IN_SECONDS );
            return [];
        }

        $this->rate_limiter->wait_if_needed( 'mal' );
        $response = wp_remote_get(
            'https://api.myanimelist.net/v2/anime/' . $mal_id . '?fields=opening_themes,ending_themes',
            [
                'timeout' => 10,
                'headers' => [
                    'User-Agent'      => self::USER_AGENT,
                    'X-MAL-CLIENT-ID' => $client_id,
                ],
            ]
        );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            set_transient( $cache_key, [], 6 * HOUR_IN_SECONDS );
            return [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $all  = array_merge(
            $data['opening_themes'] ?? [],
            $data['ending_themes']  ?? []
        );

        $map = [];
        foreach ( $all as $entry ) {
            $text = is_array( $entry ) ? ( $entry['text'] ?? '' ) : '';
            if ( ! is_string( $text ) || $text === '' ) continue;

            // 去掉開頭 "#2: " 之類的編號
            $clean = preg_replace( '/^#?\d+:\s*/', '', $text );

            // 只取歌名部分（第一個 " by " 之前）
            $song_part = preg_split( '/\s+by\s+/', $clean )[0] ?? $clean;
            $song_part = trim( $song_part, " \t\n\r\0\x0B\"'" );

            // 第一組括號內若含中日文字元（含平/片假名）→ 視為日文歌名
            $native = '';
            if ( preg_match( '/\(([^)]+)\)/', $song_part, $m ) ) {
                $cand = trim( $m[1] );
                if ( preg_match( '/[\x{3000}-\x{9FFF}\x{F900}-\x{FAFF}\x{3040}-\x{30FF}]/u', $cand ) ) {
                    $native = $cand;
                }
            }

            // 羅馬名 = 去掉第一組括號（及其後）的部分
            $romaji = trim( preg_replace( '/\s*\([^)]*\).*$/', '', $song_part ) );

            if ( $romaji !== '' && $native !== '' ) {
                $map[ $this->normalize_title( $romaji ) ] = $native;
            }
        }

        set_transient( $cache_key, $map, 24 * HOUR_IN_SECONDS );
        return $map;
    }

    // =========================================================================
    // PRIVATE – 標題正規化（用於 AT ↔ Jikan 對照）
    // =========================================================================
    private function normalize_title( string $title ): string {
        $title = mb_strtolower( trim( $title ) );

        $title = str_replace( '×', 'x', $title );

        $sup_map = [
            '⁰'=>'0','¹'=>'1','²'=>'2','³'=>'3','⁴'=>'4',
            '⁵'=>'5','⁶'=>'6','⁷'=>'7','⁸'=>'8','⁹'=>'9',
        ];
        $title = strtr( $title, $sup_map );

        $title = preg_replace( '/[^\p{L}\p{N}]/u', '', $title );

        return $title ?? '';
    }

    // =========================================================================
    // PRIVATE – MusicBrainz artist 查詢
    // =========================================================================
    private function fetch_mb_artist( string $name ): array {
        $cache_key = 'anime_sync_mb_artist_' . md5( $name );
        $cached    = get_option( $cache_key );
        if ( $cached !== false ) return (array) $cached;

        sleep( 1 ); // MB rate limit 1 req/s

        $url = add_query_arg( [
            'query' => $name,
            'fmt'   => 'json',
            'limit' => 5,
        ], 'https://musicbrainz.org/ws/2/artist' );

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $empty = [ 'mbid' => '', 'name_native' => '', 'name_legal' => '' ];
            update_option( $cache_key, $empty, false );
            return $empty;
        }

        $body    = json_decode( wp_remote_retrieve_body( $response ), true );
        $artists = $body['artists'] ?? [];

        if ( empty( $artists ) ) {
            $empty = [ 'mbid' => '', 'name_native' => '', 'name_legal' => '' ];
            update_option( $cache_key, $empty, false );
            return $empty;
        }

        $pick = null;
        foreach ( $artists as $a ) {
            if ( ( $a['score'] ?? 0 ) >= 70 ) {
                $pick = $a;
                break;
            }
        }
        if ( ! $pick ) $pick = $artists[0];

        $name_native = '';
        $name_legal  = '';
        foreach ( $pick['aliases'] ?? [] as $alias ) {
            if ( ( $alias['locale'] ?? '' ) === 'ja'
                 && ( $alias['type'] ?? '' ) === 'Artist name'
                 && ! empty( $alias['primary'] ) ) {
                $name_native = $alias['name'];
            }
            if ( ( $alias['type'] ?? '' ) === 'Legal name' && $name_legal === '' ) {
                $name_legal = $alias['name'];
            }
        }

        $result = [
            'mbid'        => $pick['id']   ?? '',
            'name_native' => $name_native,
            'name_legal'  => $name_legal,
        ];

        update_option( $cache_key, $result, false );
        return $result;
    }

    // =========================================================================
    // PRIVATE – 解析器
    // =========================================================================

    private function build_season_aware_native( string $native, string $romaji ): string {
        if ( $native === '' ) return $native;
        if ( preg_match( '/(?:season\s*\d+|\d+(?:st|nd|rd|th)\s+season|part\s*\d+|cour\s*\d+|第\s*\d+\s*[期季部])/iu', $native ) ) {
            return $native;
        }
        if ( $romaji !== '' && preg_match( '/(season\s*\d+|\d+(?:st|nd|rd|th)\s+season|part\s*\d+|cour\s*\d+)/i', $romaji, $m ) ) {
            return $native . ' ' . $m[1];
        }
        return $native;
    }

    private function parse_fuzzy_date( array $date ): string {
        $y = (int) ( $date['year']  ?? 0 );
        $m = (int) ( $date['month'] ?? 0 );
        $d = (int) ( $date['day']   ?? 0 );

        if ( ! $y ) {
            return '';
        }
        if ( ! $m || ! $d ) {
            return '';
        }
        return sprintf( '%04d%02d%02d', $y, $m, $d );
    }

    private function parse_streaming_links( array $links ): array {
        $result = [ 'taiwan' => [], 'overseas' => [] ];
        $seen   = [];

        foreach ( $links as $link ) {
            $site = trim( (string) ( $link['site'] ?? '' ) );
            $url  = trim( (string) ( $link['url']  ?? '' ) );
            $type = strtoupper( (string) ( $link['type'] ?? '' ) );

            if ( $url === '' ) continue;
            if ( $type !== '' && $type !== 'STREAMING' ) continue;

            $dedup_key = strtolower( $site . '|' . $url );
            if ( isset( $seen[ $dedup_key ] ) ) continue;
            $seen[ $dedup_key ] = true;

            $platform_key = Anime_Sync_Streaming_Registry::match_site( $site, $url );
            if ( $platform_key === null ) continue;

            $platform = Anime_Sync_Streaming_Registry::get( $platform_key );
            $entry    = [ 'site' => $platform['label'], 'key' => $platform_key, 'url' => $url ];

            if ( $platform['global'] ) {
                $result['overseas'][] = $entry;
            } else {
                $result['taiwan'][]   = $entry;
            }
        }

        return $result;
    }

    private function parse_external_links( array $links ): array {
        $result = [ 'official_site' => '', 'twitter_url' => '', 'tiktok_url' => '' ];
        foreach ( $links as $link ) {
            $site = strtolower( $link['site'] ?? '' );
            $url  = $link['url'] ?? '';
            if ( $site === 'twitter' || $site === 'x' ) $result['twitter_url'] = $url;
            if ( $site === 'tiktok' ) $result['tiktok_url'] = $url;
            if ( in_array( $link['type'] ?? '', [ 'OFFICIAL', 'INFO' ], true ) && $result['official_site'] === '' ) {
                $result['official_site'] = $url;
            }
        }
        return $result;
    }

    private function parse_staff( array $edges ): array {
        $staff = [];
        foreach ( $edges as $edge ) {
            $node = $edge['node'] ?? [];
            if ( empty( $node['id'] ) ) continue;
            $staff[] = [
                'id'     => (int) $node['id'],
                'name'   => $node['name']['full']   ?? '',
                'native' => $node['name']['native'] ?? '',
                'role'   => $edge['role']           ?? '',
                'image'  => $node['image']['large'] ?? '',
                'source' => 'anilist',
            ];
        }
        return $staff;
    }

    private function parse_cast( array $edges ): array {
        $cast = [];
        foreach ( $edges as $edge ) {
            $node = $edge['node'] ?? [];
            if ( empty( $node['id'] ) ) continue;
            $vas = [];
            foreach ( $edge['voiceActors'] ?? [] as $va ) {
                $vas[] = [
                    'id'     => (int) ( $va['id'] ?? 0 ),
                    'name'   => $va['name']['full']   ?? '',
                    'native' => $va['name']['native'] ?? '',
                    'image'  => $va['image']['large'] ?? '',
                ];
            }
            $cast[] = [
                'id'           => (int) $node['id'],
                'name'         => $node['name']['full']   ?? '',
                'native'       => $node['name']['native'] ?? '',
                'role'         => $edge['role']           ?? '',
                'image'        => $node['image']['large'] ?? '',
                'voice_actors' => $vas,
                'source'       => 'anilist',
            ];
        }
        return $cast;
    }

    private function parse_relations( array $edges ): array {
        $relations = [];
        foreach ( $edges as $edge ) {
            $node = $edge['node'] ?? [];
            if ( empty( $node['id'] ) ) continue;
            $relations[] = [
                'id'            => (int) $node['id'],
                'type'          => $node['type']            ?? '',
                'relation_type' => $edge['relationType']    ?? '',
                'title'         => $node['title']['romaji'] ?? '',
            ];
        }
        return $relations;
    }

    private function clean_synopsis( string $text ): string {
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $text );
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\(Source:.*?\)/si', '', $text );
        $text = preg_replace( '/\[Written by.*?\]/si', '', $text );
        return trim( $text );
    }

    // =========================================================================
    // PUBLIC – 公開包裝（供 AJAX 直接呼叫）
    // =========================================================================
    public function fetch_bgm_data_public( int $bangumi_id ): array|WP_Error {
        return $this->get_bangumi_data( $bangumi_id );
    }

    /**
     * 沿 PREQUEL 往上找出系列根源的 AniList ID。
     *
     * 給 class-import-manager.php 在每一次匯入時判定系列用。
     * 內部走 fetch_anilist_relations()，該方法有 6 小時 transient 快取，
     * 同一系列連續匯入時多數節點都會直接命中快取。
     */
    public function find_series_root_public( int $anilist_id ): int {
        $root = $this->find_series_root( $anilist_id );
        return is_wp_error( $root ) ? $anilist_id : (int) $root;
    }

    /**
     * 取單一節點的顯示資料（含 season / season_year），供系列命名查 YourAnimes 用。
     */
    public function get_anilist_node_public( int $anilist_id ): array|WP_Error {
        $bundle = $this->fetch_anilist_node_bundle( $anilist_id );
        return is_wp_error( $bundle ) ? $bundle : $bundle['node'];
    }
    /**
     * MAL 的主題曲日文對照表，供一次性回填使用。
     *
     * 回傳 [ 正規化後的羅馬字歌名 => 日文歌名 ]。
     * 包裝而非複製解析邏輯——MAL 的文字格式（"Inori (祈り)" by …）
     * 只該有一處實作，兩份會漂移。
     */
    public function get_theme_natives_public( int $mal_id ): array {
        return $this->fetch_jikan_theme_natives( $mal_id );
    }

    /**
     * 提供給回填端做 key 正規化，確保與同步時用的是同一套規則。
     */
    public function normalize_title_public( string $title ): string {
        return $this->normalize_title( $title );
    }

    /**
     * 用站內既有主題曲資料反查歌手日文名（不打任何外部 API）。
     *
     * 同一位歌手常在多部作品出現，其中一部查到過 MusicBrainz 就會留下
     * name_native，這裡把它套用到其他還沒有的地方。
     */
    public function lookup_artist_native_public( string $romaji_name ): string {
        return $this->lookup_known_artist_native( $romaji_name );
    }

    public function get_bgm_staff_public( int $bangumi_id ): array {
        return $this->get_bgm_staff( $bangumi_id );
    }
    public function get_bgm_chars_public( int $bangumi_id ): array {
        return $this->get_bgm_chars( $bangumi_id );
    }
    public function clean_synopsis_public( string $text ): string {
        return $this->clean_synopsis( $text );
    }
    public function fetch_mal_score_public( ?int $mal_id ): int {
        return $this->fetch_mal_score( $mal_id );
    }

    // =========================================================================
    // PRIVATE – AniList 請求 helper（1.1.0 新增）
    // =========================================================================
    private function anilist_request( string $query, array $variables, int $timeout = 15 ): array|WP_Error {

        $payload = wp_json_encode( [
            'query'     => $query,
            'variables' => $variables,
        ] );

        $args = [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => self::USER_AGENT,
            ],
            'body'    => $payload,
        ];

        $max_attempts = 3;
        $attempt      = 0;
        $last_error   = null;

        while ( $attempt < $max_attempts ) {
            $attempt++;

            $this->rate_limiter->wait_if_needed( 'anilist' );
            $response = wp_remote_post( self::ANILIST_ENDPOINT, $args );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                $this->rate_limiter->record_stat( 'anilist', 'failed' );
                if ( $attempt < $max_attempts ) {
                    sleep( 5 );
                    $this->rate_limiter->record_stat( 'anilist', 'retry' );
                    continue;
                }
                return $last_error;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( $code === 200 ) {
                $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! is_array( $decoded ) ) {
                    $this->rate_limiter->record_stat( 'anilist', 'failed' );
                    return new WP_Error( 'anilist_decode_error', 'AniList response JSON decode failed.' );
                }
                $this->rate_limiter->record_stat( 'anilist', 'success' );
                return $decoded;
            }

            if ( $code === 429 ) {
                $this->rate_limiter->record_stat( 'anilist', 'rate_limited' );
                if ( $attempt < $max_attempts ) {
                    $wait = $this->rate_limiter->handle_rate_limit_error( $response, 'anilist' );
                    sleep( $wait );
                    $this->rate_limiter->record_stat( 'anilist', 'retry' );
                    continue;
                }
                $this->rate_limiter->record_stat( 'anilist', 'failed' );
                return new WP_Error( 'anilist_rate_limited', 'AniList rate limit exceeded after 3 attempts.' );
            }

            $this->rate_limiter->record_stat( 'anilist', 'failed' );
            return new WP_Error( 'anilist_http_error', "AniList returned HTTP {$code}." );
        }

        return $last_error ?: new WP_Error( 'anilist_unknown', 'AniList request failed.' );
    }

}