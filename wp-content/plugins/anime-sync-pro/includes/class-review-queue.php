<?php
/**
 * Review Queue Manager
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_Review_Queue {

    private $wpdb;
    private string $table_name;

    public function __construct() {
        global $wpdb;
        $this->wpdb       = $wpdb;
        $this->table_name = $wpdb->prefix . 'anime_review_queue';
    }


    private function get_preferred_title( array $api_data ): string {
        $title = $api_data['anime_title_chinese']
            ?? $api_data['anime_title_native']
            ?? $api_data['anime_title_romaji']
            ?? '';

        if ( $title === '' && ! empty( $api_data['title'] ) && is_array( $api_data['title'] ) ) {
            $title = $api_data['title']['chinese_traditional']
                ?? $api_data['title']['native']
                ?? $api_data['title']['romaji']
                ?? '';
        }

        return sanitize_text_field( (string) $title );
    }

    private function decode_json_array( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function normalize_api_data( array $api_data ): array {
        $data = $api_data;

        $data['title'] = is_array( $data['title'] ?? null ) ? $data['title'] : [];
        $data['title']['chinese_traditional'] = $data['title']['chinese_traditional'] ?? ( $data['anime_title_chinese'] ?? '' );
        $data['title']['romaji']              = $data['title']['romaji'] ?? ( $data['anime_title_romaji'] ?? '' );
        $data['title']['english']             = $data['title']['english'] ?? ( $data['anime_title_english'] ?? '' );
        $data['title']['native']              = $data['title']['native'] ?? ( $data['anime_title_native'] ?? '' );

        $data['cover_image'] = $data['cover_image'] ?? ( $data['anime_cover_image'] ?? '' );
        $data['format']      = $data['format'] ?? ( $data['anime_format'] ?? 'TV' );
        $data['season']      = $data['season'] ?? ( $data['anime_season'] ?? '' );
        $data['year']        = $data['year'] ?? ( $data['anime_season_year'] ?? 0 );
        $data['episodes']    = $data['episodes'] ?? ( $data['anime_episodes'] ?? 0 );
        $data['duration']    = $data['duration'] ?? ( $data['anime_duration'] ?? 0 );
        $data['popularity']  = $data['popularity'] ?? ( $data['anime_popularity'] ?? 0 );
        $data['source']      = $data['source'] ?? ( $data['anime_source'] ?? '' );
        $data['status']      = $data['status'] ?? ( $data['anime_status'] ?? '' );

        $data['score'] = is_array( $data['score'] ?? null ) ? $data['score'] : [];
        $data['score']['anilist'] = $data['score']['anilist'] ?? ( $data['anime_score_anilist'] ?? 0 );
        $data['score']['mal']     = $data['score']['mal'] ?? ( $data['anime_score_mal'] ?? 0 );
        $data['score']['bangumi'] = $data['score']['bangumi'] ?? ( $data['anime_score_bangumi'] ?? 0 );

        $data['synopsis'] = is_array( $data['synopsis'] ?? null ) ? $data['synopsis'] : [];
        $data['synopsis']['chinese_traditional'] = $data['synopsis']['chinese_traditional'] ?? ( $data['anime_synopsis_chinese'] ?? '' );
        $data['synopsis']['english']             = $data['synopsis']['english'] ?? ( $data['anime_synopsis_english'] ?? '' );

        $data['id_anilist'] = $data['id_anilist'] ?? ( $data['anilist_id'] ?? 0 );
        $data['id_mal']     = $data['id_mal'] ?? ( $data['mal_id'] ?? 0 );
        $data['id_bangumi'] = $data['id_bangumi'] ?? ( $data['bangumi_id'] ?? 0 );

        if ( empty( $data['studios'] ) && ! empty( $data['anime_studios'] ) ) {
            $studios = array_filter( array_map( 'trim', explode( ',', (string) $data['anime_studios'] ) ) );
            $data['studios'] = array_map( static fn( $name ) => [ 'name' => $name ], $studios );
        }

        if ( empty( $data['genres'] ) && ! empty( $data['anime_genres'] ) && is_array( $data['anime_genres'] ) ) {
            $data['genres'] = array_values( $data['anime_genres'] );
        }

        if ( empty( $data['music'] ) || ! is_array( $data['music'] ) ) {
            $themes = $this->decode_json_array( $data['anime_themes'] ?? [] );
            $music  = [ 'openings' => [], 'endings' => [] ];

            foreach ( $themes as $theme ) {
                if ( ! is_array( $theme ) ) {
                    continue;
                }

                $type  = strtoupper( (string) ( $theme['type'] ?? '' ) );
                $entry = [
                    'title'  => (string) ( $theme['title'] ?? '' ),
                    'artist' => (string) ( $theme['artist'] ?? '' ),
                ];

                if ( strpos( $type, 'OP' ) === 0 ) {
                    $music['openings'][] = $entry;
                } elseif ( strpos( $type, 'ED' ) === 0 ) {
                    $music['endings'][] = $entry;
                }
            }

            $data['music'] = $music;
        }

        return $data;
    }

    // =========================================================================
    // 新增
    // =========================================================================

    /**
     * 新增項目到審核佇列。
     *
     * @param int    $anilist_id AniList ID。
     * @param array  $api_data   完整 API 資料陣列（merge_api_data 輸出格式）。
     * @param string $source     來源（manual / auto / season）。
     * @return int|false         佇列 ID，失敗返回 false。
     */
    public function add( int $anilist_id, array $api_data, string $source = 'manual' ): int|false {
        // 重複檢查
        if ( $this->get_item_by_anilist_id( $anilist_id ) ) {
            return false;
        }

        $api_data = $this->normalize_api_data( $api_data );
        $title    = $this->get_preferred_title( $api_data );

        // 壓縮 JSON 資料
        $compressed_data = gzcompress(
            wp_json_encode( $api_data, JSON_UNESCAPED_UNICODE ),
            9
        );

        $result = $this->wpdb->insert(
            $this->table_name,
            [
                'anilist_id' => absint( $anilist_id ),
                'title'      => $title, // ✅ 獨立儲存標題欄位
                'api_data'   => $compressed_data,
                'status'     => 'pending',
                'source'     => sanitize_text_field( $source ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $result ) {
            return false;
        }

        return (int) $this->wpdb->insert_id;
    }

    // =========================================================================
    // 查詢
    // =========================================================================

    /**
     * 取得佇列項目列表（分頁）。
     *
     * @param int    $page     頁碼（從 1 開始）。
     * @param int    $per_page 每頁筆數。
     * @param string $status   狀態篩選（空字串表示不篩選）。
     * @return array           佇列項目陣列。
     */
    public function get_items( int $page = 1, int $per_page = 20, string $status = 'pending' ): array {
        $offset = ( $page - 1 ) * $per_page;

        // ✅ 修正：不使用 JSON_EXTRACT（api_data 是 BLOB），改讀獨立的 title 欄位
        if ( ! empty( $status ) ) {
            $query = $this->wpdb->prepare(
                "SELECT id, anilist_id, title, status, source, created_at, wp_post_id
                 FROM {$this->table_name}
                 WHERE status = %s
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $status,
                $per_page,
                $offset
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT id, anilist_id, title, status, source, created_at, wp_post_id
                 FROM {$this->table_name}
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $per_page,
                $offset
            );
        }

        return $this->wpdb->get_results( $query, ARRAY_A ) ?: [];
    }

    /**
     * 取得單一佇列項目（含解壓縮 API 資料）。
     *
     * @param int $queue_id 佇列 ID。
     * @return array|null   項目資料，不存在返回 null。
     */
    public function get_item( int $queue_id ): ?array {
        $item = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                absint( $queue_id )
            ),
            ARRAY_A
        );

        if ( ! $item ) {
            return null;
        }

        // 解壓縮 JSON
        if ( ! empty( $item['api_data'] ) ) {
            $decompressed = gzuncompress( $item['api_data'] );
            if ( false === $decompressed ) {
                $decompressed = $item['api_data'];
            }

            $decoded = json_decode( $decompressed, true );
            $item['api_data'] = is_array( $decoded )
                ? $this->normalize_api_data( $decoded )
                : [];
        } else {
            $item['api_data'] = [];
        }

        return $item;
    }

    /**
     * 以 AniList ID 查找佇列項目。
     *
     * @param int $anilist_id AniList ID。
     * @return array|null     項目資料，不存在返回 null。
     */
    public function get_item_by_anilist_id( int $anilist_id ): ?array {
        $item_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE anilist_id = %d",
                absint( $anilist_id )
            )
        );

        if ( ! $item_id ) {
            return null;
        }

        return $this->get_item( (int) $item_id );
    }

    /**
     * 取得佇列總數。
     *
     * @param string|null $status 狀態篩選（null 表示不篩選）。
     * @return int                總數。
     */
    public function get_count( ?string $status = null ): int {
        if ( ! empty( $status ) ) {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
                    $status
                )
            );
        } else {
            $count = $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name}"
            );
        }

        return (int) $count;
    }

    // =========================================================================
    // 更新
    // =========================================================================

    /**
     * 更新項目狀態。
     *
     * @param int      $queue_id   佇列 ID。
     * @param string   $new_status 新狀態。
     * @param int|null $wp_post_id WordPress 文章 ID（選填）。
     * @return bool                成功返回 true。
     */
    public function update_status( int $queue_id, string $new_status, ?int $wp_post_id = null ): bool {
        // ✅ 修正：動態產生 data 和 format 陣列，避免數量不一致
        $data    = [ 'status' => sanitize_text_field( $new_status ) ];
        $formats = [ '%s' ];

        if ( null !== $wp_post_id ) {
            $data['wp_post_id'] = absint( $wp_post_id );
            $formats[]          = '%d';
        }

        $result = $this->wpdb->update(
            $this->table_name,
            $data,
            [ 'id' => absint( $queue_id ) ],
            $formats,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * 更新佇列項目的 API 資料（重新取得後覆寫）。
     *
     * @param int   $queue_id 佇列 ID。
     * @param array $api_data 新的 API 資料。
     * @return bool           成功返回 true。
     */
    public function update_api_data( int $queue_id, array $api_data ): bool {
        $api_data   = $this->normalize_api_data( $api_data );
        $compressed = gzcompress(
            wp_json_encode( $api_data, JSON_UNESCAPED_UNICODE ),
            9
        );

        // 同步更新標題
        $title = $this->get_preferred_title( $api_data );

        $result = $this->wpdb->update(
            $this->table_name,
            [
                'api_data' => $compressed,
                'title'    => $title,
            ],
            [ 'id' => absint( $queue_id ) ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    // =========================================================================
    // 刪除
    // =========================================================================

    /**
     * 刪除單一佇列項目。
     *
     * @param int $queue_id 佇列 ID。
     * @return bool         成功返回 true。
     */
    public function delete( int $queue_id ): bool {
        $result = $this->wpdb->delete(
            $this->table_name,
            [ 'id' => absint( $queue_id ) ],
            [ '%d' ]
        );

        return $result !== false;
    }

    // =========================================================================
    // 批次操作
    // =========================================================================

    /**
     * 批次刪除佇列項目。
     *
     * @param array $queue_ids 佇列 ID 陣列。
     * @return int             成功刪除的數量。
     */
    public function batch_delete( array $queue_ids ): int {
        if ( empty( $queue_ids ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $queue_ids as $id ) {
            if ( $this->delete( (int) $id ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 批次核准佇列項目（狀態改為 approved）。
     *
     * @param array $queue_ids 佇列 ID 陣列。
     * @return int             成功核准的數量。
     */
    public function batch_approve( array $queue_ids ): int {
        if ( empty( $queue_ids ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $queue_ids as $id ) {
            if ( $this->update_status( (int) $id, 'approved' ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 批次拒絕佇列項目（狀態改為 rejected）。
     *
     * @param array $queue_ids 佇列 ID 陣列。
     * @return int             成功拒絕的數量。
     */
    public function batch_reject( array $queue_ids ): int {
        if ( empty( $queue_ids ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $queue_ids as $id ) {
            if ( $this->update_status( (int) $id, 'rejected' ) ) {
                $count++;
            }
        }

        return $count;
    }
}
