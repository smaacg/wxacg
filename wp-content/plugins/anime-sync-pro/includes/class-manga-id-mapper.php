<?php
/**
 * Class Anime_Sync_Manga_ID_Mapper
 *
 * 漫畫專用 Bangumi ID 自動對照器。
 *
 * 為什麼要獨立一個類別，而不共用 Anime_Sync_ID_Mapper？
 *   1. Anime_Sync_ID_Mapper 的離線索引（al_index / mal_index /
 *      BangumiExtLinker / anime-offline-database）全部只含「動畫」，
 *      漫畫查不到，那些 Layer 對漫畫是死路。
 *   2. 它的 query_bangumi_search() 寫死 filter type=[2]（動畫），
 *      漫畫是 type=1（書籍），會被 Bangumi 直接濾掉。
 *   3. 漫畫沒有有意義的「集數(eps)」，驗證要改看卷數/話數與年份、標題。
 *
 * 因此本類別只保留「Bangumi 搜尋 type=1 + 標題正規化 + 評分比對」，
 * 標題正規化與季號/羅馬數字處理直接沿用動畫版的成熟邏輯（複製精簡版）。
 *
 * ⚠ 準確度說明：漫畫沒有離線對照表可先命中，只能靠標題搜尋 + 相似度，
 *   誤配風險高於動畫。建議搭配手動 Bangumi ID + resync 作為修正手段。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Manga_ID_Mapper {

    const BGM_SEARCH_URL  = 'https://api.bgm.tv/v0/search/subjects';
    const BGM_SUBJECT_URL = 'https://api.bgm.tv/v0/subjects/';
    const USER_AGENT      = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)';

    // type 1 = 書籍（含漫畫/小說）。Bangumi 沒有「純漫畫」的獨立 type，
    // 漫畫與輕小說同屬 book，故搜尋後需靠 platform / 標題進一步判斷。
    const BGM_TYPE_BOOK = 1;

    const DEBUG_LOG = false;

    private ?string $last_error = null;

    // =========================================================================
    // PUBLIC – 主查詢
    // =========================================================================

    /**
     * 依 AniList 漫畫資料自動找 Bangumi ID。
     *
     * @param array $manga_data 需含：
     *   - title_native  (string) 日文原名（主要搜尋依據）
     *   - title_romaji  (string) 羅馬字（備援）
     *   - title_chinese (string) 中文名（備援，通常匯入時為空）
     *   - start_year    (int)    連載開始年（用於年份加減分，可為 0）
     *   - volumes       (int)    卷數（可為 0，弱驗證）
     *   - chapters      (int)    話數（可為 0，目前僅備用）
     * @return int|null 找到回傳 Bangumi subject ID，否則 null。
     */
    public function get_bangumi_id( array $manga_data ): ?int {

        $title_native  = trim( (string) ( $manga_data['title_native']  ?? '' ) );
        $title_romaji  = trim( (string) ( $manga_data['title_romaji']  ?? '' ) );
        $title_chinese = trim( (string) ( $manga_data['title_chinese'] ?? '' ) );
        $start_year    = (int) ( $manga_data['start_year'] ?? 0 );
        $volumes       = (int) ( $manga_data['volumes']    ?? 0 );

        // 依序嘗試：日文原名 → 中文名 → 羅馬字。第一個命中即回傳。
        foreach ( [ $title_native, $title_chinese, $title_romaji ] as $title ) {
            if ( $title === '' ) continue;
            $bgm_id = $this->search_manga_by_title( $title, $start_year, $volumes );
            if ( $bgm_id ) {
                return $bgm_id;
            }
        }

        $this->last_error = 'Bangumi manga ID not found for: ' . $title_native;
        return null;
    }

    public function get_last_error(): ?string {
        return $this->last_error;
    }

    // =========================================================================
    // PRIVATE – 標題搜尋（type=1）
    // =========================================================================

    private function search_manga_by_title( string $title, int $year, int $volumes ): ?int {
        $title = trim( $title );
        if ( $title === '' ) return null;

        $subject_map = [];
        foreach ( $this->build_search_variants( $title ) as $variant ) {
            foreach ( $this->query_bangumi_search( $variant ) as $subject ) {
                $bgm_id = (int) ( $subject['id'] ?? 0 );
                if ( $bgm_id <= 0 ) continue;
                $subject_map[ $bgm_id ] ??= $subject;
            }
        }

        if ( empty( $subject_map ) ) return null;

        return $this->match_best_result( array_values( $subject_map ), $title, $year, $volumes );
    }

    private function query_bangumi_search( string $keyword ): array {
        $keyword = trim( $keyword );
        if ( $keyword === '' ) return [];

        $url  = add_query_arg( [ 'limit' => 25, 'offset' => 0 ], self::BGM_SEARCH_URL );
        $args = [
            'timeout'    => 15,
            'user-agent' => self::USER_AGENT,
            'headers'    => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
            // ★ 關鍵差異：type=1（書籍/漫畫），與動畫 mapper 的 type=2 不同。
            'body'       => wp_json_encode( [
                'keyword' => $keyword,
                'filter'  => [ 'type' => [ self::BGM_TYPE_BOOK ] ],
            ] ),
        ];

        for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
            $response = wp_remote_post( $url, $args );
            if ( ! is_wp_error( $response ) ) {
                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( $code === 200 ) {
                    $data = json_decode( wp_remote_retrieve_body( $response ), true );
                    return is_array( $data['data'] ?? null ) ? $data['data'] : [];
                }
                $this->last_error = "Bangumi manga search HTTP {$code} for: {$keyword}";
            } else {
                $this->last_error = 'Bangumi manga search failed: ' . $response->get_error_message();
            }
            if ( $attempt < 2 ) sleep( 1 );
        }
        return [];
    }

    // =========================================================================
    // PRIVATE – 最佳結果評分
    // 漫畫無 eps 可驗證，改用：標題相似度（主）+ 年份（次）+ 卷數（弱）。
    // =========================================================================

    private function match_best_result( array $results, string $title, int $year, int $volumes ): ?int {
        if ( empty( $results ) ) return null;

        $input_base     = $this->normalize_title( $title );
        $input_preserve = $this->normalize_title_preserve_season( $title );
        $input_signal   = $this->extract_season_signal( $title );

        $candidates = [];

        foreach ( $results as $subject ) {
            $bgm_id = (int) ( $subject['id'] ?? 0 );
            if ( $bgm_id <= 0 ) continue;

            $name_cn = (string) ( $subject['name_cn'] ?? '' );
            $name_ja = (string) ( $subject['name']    ?? '' );

            $cn_base = $this->normalize_title( $name_cn );
            $ja_base = $this->normalize_title( $name_ja );
            $cn_full = $this->normalize_title_preserve_season( $name_cn );
            $ja_full = $this->normalize_title_preserve_season( $name_ja );

            $s1 = $s2 = $s3 = $s4 = 0.0;
            similar_text( $input_base, $cn_base, $s1 );
            similar_text( $input_base, $ja_base, $s2 );
            similar_text( $input_preserve, $cn_full, $s3 );
            similar_text( $input_preserve, $ja_full, $s4 );
            $sim = max( $s1, $s2, $s3, $s4 );

            if ( $sim < 42.0 ) continue;

            // 季號/卷次不一致直接排除（例：第2部 vs 第1部），除非相似度極高。
            $subject_signal = $this->extract_subject_season_signal( $subject );
            if (
                ! empty( $input_signal['number'] ) &&
                ! empty( $subject_signal['number'] ) &&
                (int) $input_signal['number'] !== (int) $subject_signal['number'] &&
                $sim < 90.0
            ) {
                continue;
            }

            $bgm_year  = $this->get_bangumi_year( $subject );
            $year_diff = ( $year > 0 && $bgm_year > 0 ) ? abs( $bgm_year - $year ) : 999;

            $score = $sim;

            // 年份加減分（漫畫連載開始年，容忍度比動畫寬，因收錄日期常有落差）。
            if ( $year_diff !== 999 ) {
                if     ( $year_diff === 0 ) $score += 8;
                elseif ( $year_diff <= 1 )  $score += 5;
                elseif ( $year_diff <= 2 )  $score += 2;
                elseif ( $year_diff <= 4 )  $score -= 2;
                else                        $score -= 8;
            }

            // 卷數弱驗證：Bangumi 漫畫的 eps 有時表示卷數，有值且相近才小幅加分。
            $bgm_eps = (int) ( $subject['eps'] ?? 0 );
            if ( $volumes > 0 && $bgm_eps > 0 ) {
                $vol_diff = abs( $bgm_eps - $volumes );
                if     ( $vol_diff === 0 ) $score += 3;
                elseif ( $vol_diff <= 2 )  $score += 1;
            }

            if ( self::DEBUG_LOG ) {
                error_log( sprintf(
                    '[MANGA MATCH] IN=%s | BGM=%s / %s | SIM=%.1f | SCORE=%.1f | YD=%s',
                    $input_preserve, $name_ja, $name_cn, $sim, $score, $year_diff
                ) );
            }

            $candidates[] = [
                'id'        => $bgm_id,
                'sim'       => $sim,
                'score'     => $score,
                'year_diff' => $year_diff,
            ];
        }

        if ( empty( $candidates ) ) return null;

        usort( $candidates, static function ( $a, $b ) {
            return $b['score'] <=> $a['score']
                ?: $a['year_diff'] <=> $b['year_diff']
                ?: $b['sim'] <=> $a['sim'];
        } );

        $best = $candidates[0];

        if ( self::DEBUG_LOG ) {
            error_log( '[MANGA MATCH] Selected ID=' . $best['id'] . ' score=' . $best['score'] . ' sim=' . $best['sim'] );
        }

        // 門檻比動畫略嚴：漫畫無離線表背書，寧可漏配也不要誤配。
        if ( $best['score'] >= 60 || $best['sim'] >= 72.0 ) {
            return $best['id'];
        }

        return null;
    }

    // =========================================================================
    // PRIVATE – 搜尋變體（沿用動畫版精簡邏輯）
    // =========================================================================

    private function build_search_variants( string $title ): array {
        $title = trim( $title );
        if ( $title === '' ) return [];

        $variants = [
            $title,
            $this->normalize_title_preserve_season( $title ),
            $this->normalize_title( $title ),
        ];

        $without_brackets = $this->strip_bracket_text( $title );
        if ( $without_brackets !== '' && $without_brackets !== $title ) {
            $variants[] = $without_brackets;
            $variants[] = $this->normalize_title_preserve_season( $without_brackets );
            $variants[] = $this->normalize_title( $without_brackets );
        }

        $base_title = $this->strip_season_markers( $without_brackets ?: $title );
        if ( $base_title !== '' ) {
            $variants[] = $base_title;
            $variants[] = $this->normalize_title( $base_title );
        }

        $cleaned = [];
        foreach ( $variants as $v ) {
            $v = trim( preg_replace( '/\s+/u', ' ', (string) $v ) );
            if ( $v !== '' ) $cleaned[ $v ] = true;
        }
        return array_keys( $cleaned );
    }

    // =========================================================================
    // PRIVATE – 標題正規化（複製自動畫 mapper，行為一致）
    // =========================================================================

    private function normalize_title( string $title ): string {
        if ( $title === '' ) return '';
        $title = $this->normalize_title_preserve_season( $title );
        $title = $this->strip_season_markers( $title );
        $title = preg_replace( '/\s+\d+$/u', '', (string) $title );
        $title = preg_replace( '/\s+/u', ' ', (string) $title );
        return trim( (string) $title );
    }

    private function normalize_title_preserve_season( string $title ): string {
        if ( $title === '' ) return '';

        $title = str_replace( '　', ' ', $title );
        $title = mb_strtolower( $title, 'UTF-8' );

        $roman_map = [
            'ⅻ' => '12', 'ⅺ' => '11', 'ⅹ' => '10',
            'ⅸ' => '9',  'ⅷ' => '8',  'ⅶ' => '7',
            'ⅵ' => '6',  'ⅴ' => '5',  'ⅳ' => '4',
            'ⅲ' => '3',  'ⅱ' => '2',  'ⅰ' => '1',
        ];
        $title = str_replace( array_keys( $roman_map ), array_values( $roman_map ), $title );
        $title = preg_replace_callback( '/\b([ivxlcdm]+)\b/u', fn( $m ) => (string) $this->roman_to_int( $m[1] ), $title );
        $title = str_replace( [ '＆', '&amp;', '&' ], ' and ', $title );
        $title = str_replace( [ '＋', '+' ], ' plus ', $title );
        $title = str_replace( [ '：', ':', '・', '／', '/', '—', '–', '―', '〜', '~', '“', '”', '「', '」', '『', '』', '【', '】', '[', ']' ], ' ', $title );
        $title = preg_replace( '/[\(\（][^\)\）]*[\)\）]/u', ' ', $title );
        $title = preg_replace( '/\b(19|20)\d{2}\b/u', ' ', $title );
        $title = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $title );
        $title = preg_replace( '/\s+/u', ' ', $title );

        return trim( (string) $title );
    }

    private function strip_bracket_text( string $title ): string {
        $title = preg_replace( '/[\(\（\[【].*?[\)\）\]】]/u', ' ', $title );
        $title = preg_replace( '/\s+/u', ' ', (string) $title );
        return trim( (string) $title );
    }

    private function strip_season_markers( string $title ): string {
        $title = preg_replace(
            [
                '/\b\d+(?:st|nd|rd|th)\s+season\b/ui',
                '/\bseason\s*\d+\b/ui',
                '/\bpart\s*\d+\b/ui',
                '/第\s*\d+\s*(?:部|篇|章)\b/u',
            ],
            ' ',
            $title
        );
        $title = preg_replace( '/\s+/u', ' ', (string) $title );
        return trim( (string) $title );
    }

    private function extract_season_signal( string $title ): array {
        $title = mb_strtolower( trim( $title ), 'UTF-8' );
        if ( $title === '' ) return [];

        $patterns = [
            '/\b(\d+)(?:st|nd|rd|th)\s+season\b/u' => 'season',
            '/\bseason\s*(\d+)\b/u'                => 'season',
            '/\bpart\s*(\d+)\b/u'                  => 'part',
            '/第\s*(\d+)\s*(?:部|篇|章)\b/u'         => 'part',
        ];

        foreach ( $patterns as $pattern => $kind ) {
            if ( preg_match( $pattern, $title, $m ) ) {
                $number = $this->normalize_season_number_token( $m[1] ?? '' );
                if ( $number > 0 ) return [ 'kind' => $kind, 'number' => $number ];
            }
        }
        return [];
    }

    private function extract_subject_season_signal( array $subject ): array {
        foreach ( [ (string) ( $subject['name'] ?? '' ), (string) ( $subject['name_cn'] ?? '' ) ] as $candidate ) {
            $signal = $this->extract_season_signal( $candidate );
            if ( ! empty( $signal ) ) return $signal;
        }
        return [];
    }

    private function normalize_season_number_token( string $token ): int {
        $token = trim( mb_strtolower( $token, 'UTF-8' ) );
        if ( $token === '' ) return 0;
        if ( ctype_digit( $token ) ) return (int) $token;
        return $this->roman_to_int( $token );
    }

    private function roman_to_int( string $roman ): int {
        $roman = strtoupper( trim( $roman ) );
        if ( $roman === '' ) return 0;
        $map   = [ 'I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000 ];
        $total = 0;
        $prev  = 0;
        foreach ( array_reverse( str_split( $roman ) ) as $char ) {
            $value = $map[ $char ] ?? 0;
            if ( $value === 0 ) return 0;
            if ( $value < $prev ) {
                $total -= $value;
            } else {
                $total += $value;
                $prev = $value;
            }
        }
        return $total;
    }

    private function get_bangumi_year( array $subject ): int {
        foreach ( [ 'date', 'air_date' ] as $key ) {
            $val = $subject[ $key ] ?? '';
            if ( $val !== '' ) {
                $year = (int) substr( $val, 0, 4 );
                if ( $year > 1900 ) return $year;
            }
        }
        return 0;
    }
}
