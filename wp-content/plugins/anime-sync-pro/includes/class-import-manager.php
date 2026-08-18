<?php
/**
 * 檔案名稱: includes/class-import-manager.php
 *
 * @version 1.5.2
 * 1.5.2 (2026-07-17) — [Feat AnimeThemes by-slug] fetch_themes_only() 新增
 *           選填 $slug 參數，實作「slug 優先、mal_id 備援」：有 slug 先走
 *           api_handler->fetch_animethemes_by_slug()，查無再退回 fetch_animethemes()。
 *           簽章向下相容（$slug 預設 ''），舊呼叫端不受影響。
 * 1.5.1 (2026-06-27) — [Fix] set_featured_image() 下載成功後把本機 URL 寫回
 *           anime_cover_image meta，番組表模板不再顯示 AniList CDN 圖片。
 *           - 已有 thumbnail 時：只補寫 anime_cover_image（若 meta 還是外部 URL）。
 *           - 新下載時：download 後 set_post_thumbnail 並同步更新 anime_cover_image。
 *           - 避免重複下載：已有 thumbnail 直接 return，不再建立重複媒體庫附件。
 *           - wp_remote_get 補上 user-agent，降低 AniList CDN 封鎖機率。
 *
 * 1.5.0 (2026-06-27) — [Fix] 已存在文章自動跳過匯入，防止誤觸覆蓋人工編輯資料。
 * 1.4.1 (2026-06-19) — [Fix 標籤英文] anime_tags 改為「只建立對照表命中的中文標籤」。
 * 1.4.0 (2026-06-18) — [Fix 集數/主題曲被清空] save_post_meta() 累積型欄位保護。
 * 1.3.0 (2026-06-12) — [Fix] 統一鎖定機制。
 * 1.2.0 (2026-06-10) — [Feature] 成人動漫過濾。
 * 1.1.1 (2026-05-21) — [Fix] 更新時保留現有 post_status。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Import_Manager {

	private Anime_Sync_API_Handler $api_handler;
	private Anime_Sync_CN_Converter $cn_converter;

	public function __construct(
		Anime_Sync_API_Handler $api_handler,
		Anime_Sync_CN_Converter $cn_converter
	) {
		$this->api_handler  = $api_handler;
		$this->cn_converter = $cn_converter;
	}

	// =========================================================================
	// PUBLIC – 單筆匯入
	// =========================================================================

	public function import_single( int $anilist_id, ?int $bangumi_id = null, string $source = 'manual', array $args = [] ): array {

		$force      = ! empty( $args['force'] );
		$lock_token = $this->acquire_import_lock( $anilist_id, $force );

		if ( $lock_token === '' ) {
			$existing_id = $this->find_existing( $anilist_id );

			if ( $existing_id > 0 ) {
				$existing_bangumi_id = (int) get_post_meta( $existing_id, 'anime_bangumi_id', true );
				if ( $existing_bangumi_id <= 0 ) {
					$existing_bangumi_id = (int) get_post_meta( $existing_id, 'bangumi_id', true );
				}

				return [
					'success'         => true,
					'skipped'         => true,
					'skip_enrich'     => true,
					'message'         => '此作品已有同步程序，已直接沿用既有草稿',
					'post_id'         => $existing_id,
					'title'           => get_the_title( $existing_id ) ?: "ID {$anilist_id}",
					'edit_url'        => get_edit_post_link( $existing_id, 'raw' ),
					'bangumi_missing' => $existing_bangumi_id <= 0,
					'needs_enrich'    => ! (bool) get_post_meta( $existing_id, '_enriched_at', true ),
				];
			}

			return [
				'success' => false,
				'message' => '此作品正在同步中，請稍後再試',
				'locked'  => true,
			];
		}

		try {
			$existing_id = $this->find_existing( $anilist_id );
			$is_update   = (bool) $existing_id;

			// ★ [1.5.0] 已存在文章直接跳過，防止誤觸重新匯入覆蓋資料。
			if ( $is_update && ! $force ) {
				return [
					'success'      => true,
					'skipped'      => true,
					'skip_enrich'  => true,
					'message'      => '⚠️ 已存在，跳過匯入（需更新請勾選「強制覆蓋」）',
					'post_id'      => $existing_id,
					'title'        => get_the_title( $existing_id ),
					'edit_url'     => get_edit_post_link( $existing_id, 'raw' ),
					'needs_enrich' => false,
				];
			}

			if ( ( ! $bangumi_id || $bangumi_id <= 0 ) && $existing_id > 0 ) {
				$stored_bangumi_id = (int) get_post_meta( $existing_id, 'anime_bangumi_id', true );
				if ( $stored_bangumi_id <= 0 ) {
					$stored_bangumi_id = (int) get_post_meta( $existing_id, 'bangumi_id', true );
				}
				if ( $stored_bangumi_id > 0 ) {
					$bangumi_id = $stored_bangumi_id;
				}
			}

			$anime_data = $this->api_handler->get_core_anime_data( $anilist_id, $existing_id, $bangumi_id );

			if ( is_wp_error( $anime_data ) ) {
				return [
					'success' => false,
					'message' => '資料取得失敗：' . $anime_data->get_error_message(),
				];
			}

			if ( empty( $anime_data['anilist_id'] ) ) {
				return [
					'success' => false,
					'message' => '無效的 AniList 資料（缺少 anilist_id）',
				];
			}

			if ( $this->is_adult_content( $anime_data ) ) {
				$blocked_title = $anime_data['anime_title_chinese']
					?: ( $anime_data['anime_title_romaji'] ?? "ID {$anilist_id}" );

				if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
					Anime_Sync_Error_Logger::log( 'info', '已略過成人作品匯入', [
						'anilist_id' => $anilist_id,
						'title'      => $blocked_title,
					] );
				}

				return [
					'success'        => true,
					'skipped'        => true,
					'skip_enrich'    => true,
					'adult_filtered' => true,
					'message'        => "🔞 已略過成人作品 – {$blocked_title} (ID {$anilist_id})",
					'title'          => $blocked_title,
					'needs_enrich'   => false,
				];
			}

			$has_bangumi   = ! empty( $anime_data['bangumi_id'] ) && (int) $anime_data['bangumi_id'] > 0;
			$has_chinese   = ! empty( $anime_data['anime_title_chinese'] );
			$has_synopsis  = ! empty( $anime_data['anime_synopsis_chinese'] );
			$has_cover     = ! empty( $anime_data['anime_cover_image'] );
			$has_streaming = ! empty( $anime_data['anime_streaming'] ) && $anime_data['anime_streaming'] !== '[]';

			$summary = implode( ' | ', array_filter( [
				$has_chinese   ? '✅ 中文標題' : '⚠️ 無中文標題',
				$has_bangumi   ? '✅ Bangumi'  : '⚠️ 缺 Bangumi',
				$has_synopsis  ? '✅ 簡介'     : null,
				$has_cover     ? '✅ 封面'     : '⚠️ 無封面',
				$has_streaming ? '✅ 串流'     : null,
				'⏳ 待補抓：聲優/主題曲/Wikipedia',
			] ) );

			if ( ! $is_update ) {
				$existing_id = $this->find_existing( $anilist_id );
				$is_update   = (bool) $existing_id;
			}

			if ( $is_update ) {
				$existing_post = get_post( $existing_id );
				$post_title    = ( $existing_post && trim( $existing_post->post_title ) !== '' )
					? $existing_post->post_title
					: ( ! empty( $anime_data['anime_title_chinese'] )
						? (string) $anime_data['anime_title_chinese']
						: ( $anime_data['anime_title_romaji'] ?? "Anime {$anilist_id}" ) );
			} else {
				$post_title = ! empty( $anime_data['anime_title_chinese'] )
					? (string) $anime_data['anime_title_chinese']
					: ( $anime_data['anime_title_romaji'] ?? "Anime {$anilist_id}" );
			}

			$post_slug   = $this->generate_slug( $anime_data, $existing_id );
			$post_fields = $this->extract_post_fields( $anime_data, $existing_id );

			$post_status = 'draft';
			if ( $is_update && $existing_id > 0 ) {
				$current_status = get_post_status( $existing_id );
				if ( $current_status && ! in_array( $current_status, [ 'auto-draft', 'trash' ], true ) ) {
					$post_status = $current_status;
				}
			}

			$post_data = [
				'post_type'   => 'anime',
				'post_title'  => $post_title,
				'post_name'   => $post_slug,
				'post_status' => $post_status,
				'post_author' => get_current_user_id() ?: 1,
			];

			if ( ! empty( $post_fields ) ) {
				$post_data = array_merge( $post_data, $post_fields );
			}

			if ( $is_update ) {
				$post_data['ID'] = $existing_id;
				$post_id = wp_update_post( $post_data, true );
			} else {
				$post_id = wp_insert_post( $post_data, true );
			}

			if ( is_wp_error( $post_id ) ) {
				return [
					'success' => false,
					'message' => '文章建立失敗：' . $post_id->get_error_message(),
				];
			}

			$this->save_post_meta( $post_id, $anime_data );
			update_post_meta( $post_id, 'anime_last_updated', current_time( 'mysql' ) );
			
			if ( class_exists( 'Anime_Sync_Activity_Logger' ) ) {
				$log_post = get_post( $post_id );
				if ( $log_post ) {
					Anime_Sync_Activity_Logger::record( 'imported', $log_post );
				}
			}

			if ( ! $is_update ) {
				$this->apply_first_import_locks( $post_id, $anime_data );
			}

			if ( ! empty( $anime_data['anime_cover_image'] ) ) {
				$this->set_featured_image( $post_id, $anime_data['anime_cover_image'], $post_title );
			}

			$this->save_taxonomies( $post_id, $anime_data );

			update_post_meta( $post_id, '_import_source', sanitize_text_field( $source ) );
			update_post_meta( $post_id, 'anime_last_sync', current_time( 'mysql' ) );
			delete_post_meta( $post_id, '_enriched_at' );

			if ( ! wp_next_scheduled( 'anime_sync_enrich_post', [ $post_id ] ) ) {
				$slot  = ( $post_id % 40 );
				$delay = 60 + ( $slot * 90 );
				wp_schedule_single_event( time() + $delay, 'anime_sync_enrich_post', [ $post_id ] );
			}

			$display_title   = $anime_data['anime_title_chinese'] ?: $anime_data['anime_title_romaji'] ?: "ID {$anilist_id}";
			$action_label    = $is_update ? '已更新' : '已匯入';
			$base_message    = "{$action_label} – {$display_title} (ID {$anilist_id})";
			$bangumi_missing = ! $has_bangumi;

			if ( $bangumi_missing ) {
				$base_message .= ' ⚠️ Bangumi ID 未找到，將於背景補抓';
			}

			return [
				'success'         => true,
				'message'         => $base_message,
				'post_id'         => $post_id,
				'mal_id'          => $anime_data['mal_id'] ?? 0,
				'title'           => $display_title,
				'edit_url'        => get_edit_post_link( $post_id, 'raw' ),
				'summary'         => $summary,
				'bangumi_missing' => $bangumi_missing,
				'needs_enrich'    => true,
			];
		} finally {
			$this->release_import_lock( $anilist_id, $lock_token );
		}
	}

	// =========================================================================
	// PUBLIC – 補抓第二段資料
	// =========================================================================

	public function enrich_single( int $post_id ): array|\WP_Error {
		if ( get_post_meta( $post_id, '_enriched_at', true ) ) {
			return new \WP_Error( 'already_enriched', "Post {$post_id} already enriched." );
		}

		$result = $this->api_handler->enrich_anime_data( $post_id );

		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_enriched_at', current_time( 'mysql' ) );
			delete_post_meta( $post_id, '_needs_enrich' );
		}

		return $result;
	}

	public function analyze_series( int $anilist_id ): array|\WP_Error {
		return $this->api_handler->get_series_tree( $anilist_id );
	}

	public function get_popularity_ranking( int $page = 1 ): array|\WP_Error {
		return $this->api_handler->fetch_anilist_popularity( $page );
	}

	public function assign_series_taxonomy( int $post_id, string $series_name, int $root_id = 0, string $series_romaji = '' ): bool {
		if ( ! $post_id || $series_name === '' ) return false;

		$series_name = trim( $series_name );
		$term        = term_exists( $series_name, 'anime_series_tax' );

		if ( ! $term ) {
			$slug   = $series_romaji !== '' ? sanitize_title( $series_romaji ) : sanitize_title( $series_name );
			$result = wp_insert_term( $series_name, 'anime_series_tax', [ 'slug' => $slug ] );
			if ( is_wp_error( $result ) ) return false;
			$term_id = (int) $result['term_id'];
		} else {
			$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
		}

		$result = wp_set_post_terms( $post_id, [ $term_id ], 'anime_series_tax', false );
		if ( is_wp_error( $result ) ) return false;

		if ( $root_id > 0 ) {
			update_post_meta( $post_id, '_series_root_anilist_id', $root_id );
		}

		return true;
	}

	// =========================================================================
	// PUBLIC – 只抓主題曲
	// ★ [1.5.2] slug 優先、mal_id 備援。
	//   $slug 有值時先走 by-slug 端點；查無（回空）再退回 mal_id。
	//   兩者皆無或都查不到 → 回 []。簽章向下相容（$slug 預設 ''）。
	// =========================================================================

	public function fetch_themes_only( int $mal_id, string $slug = '' ): array {
		$slug = trim( $slug );

		// 1. slug 優先：有填就先用 slug 直查 AnimeThemes。
		if ( $slug !== '' ) {
			$by_slug = $this->api_handler->fetch_animethemes_by_slug( $slug, $mal_id );
			if ( ! empty( $by_slug['themes'] ) ) {
				return $by_slug;
			}
		}

		// 2. 備援：用 mal_id 查。
		if ( $mal_id > 0 ) {
			return $this->api_handler->fetch_animethemes( $mal_id );
		}

		// 3. 兩者皆無或都查不到。
		return [];
	}

	public function fetch_episodes_only( int $bangumi_id, bool $force_refresh = false, int $post_id = 0 ): array {
		if ( $bangumi_id <= 0 ) {
			return [];
		}
		return $this->api_handler->fetch_bgm_episodes( $bangumi_id, $force_refresh, $post_id );
	}

	// =========================================================================
	// PRIVATE – [1.2.0] 成人動漫判斷
	// =========================================================================

	private function is_adult_content( array $anime_data ): bool {

		if ( ! empty( $anime_data['anime_is_adult'] ) ) {
			return true;
		}

		$blocked_genres = [
			'Hentai', 'hentai',
			'成人',
		];

		if ( ! empty( $anime_data['anime_genres'] ) && is_array( $anime_data['anime_genres'] ) ) {
			foreach ( $anime_data['anime_genres'] as $genre_name ) {
				$genre_name = trim( (string) $genre_name );
				if ( $genre_name === '' ) continue;
				if ( in_array( $genre_name, $blocked_genres, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// =========================================================================
	// PRIVATE – [1.3.0] 統一讀取鎖定清單
	// =========================================================================

	private function get_locked_fields( int $post_id ): array {
		$locked = get_post_meta( $post_id, 'anime_locked_fields', true );
		return is_array( $locked ) ? $locked : [];
	}

	// =========================================================================
	// PRIVATE – [1.3.0] 首次匯入鎖定（no-op）
	// =========================================================================

	private function apply_first_import_locks( int $post_id, array $data ): void {
		// no-op：鎖定改由 anime_locked_fields（ACF UI）統一管理。
	}

	// =========================================================================
	// PRIVATE – 產生 Slug
	// =========================================================================

	private function generate_slug( array $data, int $exclude_id = 0 ): string {
		$candidates = array_filter( [
			$data['anime_title_romaji'] ?? '',
			$data['anime_title_english'] ?? '',
			'anime-' . ( $data['anilist_id'] ?? 0 ),
		] );

		$raw  = reset( $candidates );
		$slug = sanitize_title( $raw );
		if ( $slug === '' ) $slug = 'anime-' . ( $data['anilist_id'] ?? 0 );

		$original = $slug;
		$suffix   = 1;

		while ( true ) {
			$found = get_page_by_path( $slug, OBJECT, 'anime' );
			if ( ! $found || ( $exclude_id > 0 && (int) $found->ID === $exclude_id ) ) {
				break;
			}
			$slug = $original . '-' . $suffix++;
		}

		return $slug;
	}

	// =========================================================================
	// PRIVATE – 儲存 Post Meta
	// =========================================================================

	private function save_post_meta( int $post_id, array $data ): void {
		$animethemes_id   = isset( $data['anime_animethemes_id'] ) ? trim( (string) $data['anime_animethemes_id'] ) : '';
		$animethemes_slug = isset( $data['anime_animethemes_slug'] )
			? trim( (string) $data['anime_animethemes_slug'] )
			: trim( (string) ( $data['animethemes_slug'] ?? '' ) );

		if ( $animethemes_id !== '' && ! ctype_digit( $animethemes_id ) && $animethemes_slug === '' ) {
			$animethemes_slug = $animethemes_id;
			$animethemes_id   = '';
		}

		$meta_map = [
			'anime_anilist_id'       => $data['anilist_id'] ?? 0,
			'anime_mal_id'           => $data['mal_id'] ?? 0,
			'anime_animethemes_id'   => $animethemes_id,
			'anime_animethemes_slug' => $animethemes_slug,
			'animethemes_slug'       => $animethemes_slug,
			'anime_title_chinese'    => $data['anime_title_chinese'] ?? '',
			'anime_title_simplified' => $data['anime_title_simplified'] ?? '',
			'anime_title_romaji'     => $data['anime_title_romaji'] ?? '',
			'anime_title_english'    => $data['anime_title_english'] ?? '',
			'anime_title_native'     => $data['anime_title_native'] ?? '',
			'anime_format'           => $data['anime_format'] ?? '',
			'anime_status'           => $data['anime_status'] ?? '',
			'anime_season'           => strtoupper( $data['anime_season'] ?? '' ),
			'anime_season_year'      => $data['anime_season_year'] ?? 0,
			'anime_source'           => $data['anime_source'] ?? '',
			'anime_episodes'         => $data['anime_episodes'] ?? 0,
			'anime_duration'         => $data['anime_duration'] ?? 0,
			'anime_studios'          => $data['anime_studios'] ?? '',
			'anime_score_anilist'    => $data['anime_score_anilist'] ?? 0,
			'anime_score_bangumi'    => $data['anime_score_bangumi'] ?? 0,
			'anime_score_mal'        => $data['anime_score_mal'] ?? 0,
			'anime_popularity'       => $data['anime_popularity'] ?? 0,
			// ★ [1.5.1] anime_cover_image 在此先存 AniList URL 作為 fallback；
			//            set_featured_image() 下載成功後會覆寫成本機 URL。
			'anime_cover_image'      => $data['anime_cover_image'] ?? '',
			'anime_banner_image'     => $data['anime_banner_image'] ?? '',
			'anime_trailer_url'      => $data['anime_trailer_url'] ?? '',
			'anime_synopsis_chinese' => $data['anime_synopsis_chinese'] ?? '',
			'anime_synopsis_english' => $data['anime_synopsis_english'] ?? '',
			'anime_start_date'       => $data['anime_start_date'] ?? '',
			'anime_end_date'         => $data['anime_end_date'] ?? '',
			'anime_streaming'        => $data['anime_streaming'] ?? '[]',
			'anime_staff_json'       => $data['anime_staff_json'] ?? '[]',
			'anime_cast_json'        => $data['anime_cast_json'] ?? '[]',
			'anime_relations_json'   => $data['anime_relations_json'] ?? '[]',
            'anime_official_site'    => $data['anime_official_site'] ?? '',
            'anime_twitter_url'      => $data['anime_twitter_url'] ?? '',
            'anime_tiktok_url'       => $data['anime_tiktok_url'] ?? '',
            'anime_wikipedia_url'    => $data['anime_wikipedia_url'] ?? '',
			'anime_external_links'   => $data['anime_external_links'] ?? '[]',
			'anime_next_airing'      => $data['anime_next_airing'] ?? '',
			'anime_sync_time'        => current_time( 'mysql' ),
		];

		$locked = $this->get_locked_fields( $post_id );

		foreach ( $meta_map as $key => $value ) {
    if ( $key !== 'anime_sync_time' && in_array( $key, $locked, true ) ) {
        continue;
    }
    // ★ [1.5.1] anime_cover_image 若已是本機 URL（含自己網域），
    //            不要被 AniList URL 蓋掉。
    if ( $key === 'anime_cover_image' ) {
        $current = get_post_meta( $post_id, 'anime_cover_image', true );
        if ( $current && strpos( $current, home_url() ) !== false ) {
            continue; // 已是本機 URL，跳過，保留本機圖
        }
    }
    // ★ [Fix MAL 分數被洗成 0] get_core_anime_data() 快速匯入階段固定回傳 0，
    //   真正的 MAL 分數要等 enrich_anime_data() 第二段才抓。若這裡新值是 0，
    //   代表根本沒有新資料，不該覆蓋掉現有分數（尤其是 force 重新匯入時）。
    if ( $key === 'anime_score_mal' && (int) $value <= 0 ) {
        $current = (int) get_post_meta( $post_id, 'anime_score_mal', true );
        if ( $current > 0 ) {
            continue; // 已有分數，新值是 0（尚未抓），跳過保留現值
        }
    }
    update_post_meta( $post_id, $key, $this->prepare_meta_value( $key, $value ) );
}

		// ★★ [1.4.0] 累積型欄位保護
		$accumulative_keys = [ 'anime_episodes_json', 'anime_themes' ];
		foreach ( $accumulative_keys as $accum_key ) {
			if ( in_array( $accum_key, $locked, true ) ) {
				continue;
			}
			$incoming = $data[ $accum_key ] ?? '';
			if ( is_array( $incoming ) ) {
				$incoming = wp_json_encode( $incoming, JSON_UNESCAPED_UNICODE );
			}
			$incoming = is_string( $incoming ) ? trim( $incoming ) : '';

			if ( $incoming !== '' && $incoming !== '[]' ) {
				update_post_meta( $post_id, $accum_key, $this->prepare_meta_value( $accum_key, $incoming ) );
			}
		}

		// Bangumi ID 處理
		$bgm_id_raw       = $data['bangumi_id'] ?? null;
		$bgm_id           = $bgm_id_raw !== null ? abs( intval( $bgm_id_raw ) ) : 0;
		$manually_set     = (bool) get_post_meta( $post_id, '_bangumi_id_manually_set', true );
		$existing_bgm_id  = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
		$existing_bangumi = $existing_bgm_id > 0 ? $existing_bgm_id : (int) get_post_meta( $post_id, 'bangumi_id', true );

		if ( $bgm_id > 0 && ! $manually_set ) {
			update_post_meta( $post_id, 'anime_bangumi_id', $bgm_id );
			update_post_meta( $post_id, 'bangumi_id', $bgm_id );
			delete_post_meta( $post_id, '_bangumi_id_pending' );
		} elseif ( ! $manually_set ) {
			if ( $existing_bangumi > 0 ) {
				update_post_meta( $post_id, 'anime_bangumi_id', $existing_bangumi );
				update_post_meta( $post_id, 'bangumi_id', $existing_bangumi );
				delete_post_meta( $post_id, '_bangumi_id_pending' );
			} else {
				delete_post_meta( $post_id, 'anime_bangumi_id' );
				delete_post_meta( $post_id, 'bangumi_id' );
				update_post_meta( $post_id, '_bangumi_id_pending', 1 );
			}
		}

		if ( ! empty( $data['_needs_enrich'] ) ) {
			update_post_meta( $post_id, '_needs_enrich', 1 );
		}
	}

	private function prepare_meta_value( string $key, $value ) {
		if ( $this->is_json_meta_key( $key ) ) {
			$converted = is_string( $value )
				? $this->cn_converter->convert_json_string( $value )
				: wp_json_encode( $this->cn_converter->convert_mixed( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			return $this->fix_staff_role_terms( $converted );
		}

		if ( $this->is_convertible_text_meta_key( $key ) ) {
			return is_string( $value ) ? $this->cn_converter->convert( $value ) : $value;
		}

		return $value;
	}

	private function fix_staff_role_terms( string $json ): string {
		if ( $json === '' ) {
			return $json;
		}

		$fixes = [
			'指令碼' => '劇本',
			'腳本'   => '劇本',
		];

		return preg_replace_callback(
			'/"role"\s*:\s*"([^"]*)"/u',
			static function ( array $m ) use ( $fixes ): string {
				$role = strtr( $m[1], $fixes );
				return '"role":"' . $role . '"';
			},
			$json
		);
	}

	private function is_convertible_text_meta_key( string $key ): bool {
		return in_array( $key, [
			'anime_synopsis_chinese',
			'anime_studios',
		], true );
	}

	private function is_json_meta_key( string $key ): bool {
		return in_array( $key, [
			'anime_staff_json',
			'anime_cast_json',
			'anime_episodes_json',
		], true );
	}

	private function extract_post_fields( array $data, int $existing_id = 0 ): array {
		$content_candidates = [
			'post_content', 'content', 'article_content',
			'generated_content', 'draft_content', 'body',
		];

		$excerpt_candidates = [
			'post_excerpt', 'excerpt', 'article_excerpt',
			'generated_excerpt', 'summary',
		];

		$post_fields   = [];
		$existing_post = null;

		$content = $this->pick_first_string( $data, $content_candidates );
		if ( $content !== '' ) {
			$post_fields['post_content'] = $this->cn_converter->convert( $content );
		} elseif ( $existing_id > 0 ) {
			$existing_post = get_post( $existing_id );
			if ( $existing_post && ! empty( $existing_post->post_content ) ) {
				$post_fields['post_content'] = $existing_post->post_content;
			}
		}

		$excerpt = $this->pick_first_string( $data, $excerpt_candidates );
		if ( $excerpt !== '' ) {
			$post_fields['post_excerpt'] = $this->cn_converter->convert( $excerpt );
		} elseif ( $existing_id > 0 ) {
			$existing_post = $existing_post ?: get_post( $existing_id );
			if ( $existing_post && ! empty( $existing_post->post_excerpt ) ) {
				$post_fields['post_excerpt'] = $existing_post->post_excerpt;
			}
		}

		return $post_fields;
	}

	private function pick_first_string( array $data, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$value = trim( $data[ $key ] );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}
		return '';
	}

	// =========================================================================
	// PRIVATE – [1.5.1] 設定特色圖片並同步寫回 anime_cover_image 本機 URL
	// =========================================================================

	private function set_featured_image( int $post_id, string $image_url, string $title ): void {
		$locked = $this->get_locked_fields( $post_id );

		// 封面已被使用者鎖定，完全不動。
		if ( in_array( 'anime_cover_image', $locked, true ) ) {
			return;
		}

		// ★ 已有特色圖片：不重複下載，只補寫 anime_cover_image（若還是外部 URL）。
		if ( has_post_thumbnail( $post_id ) ) {
			$existing_thumb_id  = get_post_thumbnail_id( $post_id );
			$existing_local_url = wp_get_attachment_url( $existing_thumb_id );
			$current_cover_meta = get_post_meta( $post_id, 'anime_cover_image', true );

			if ( $existing_local_url && $current_cover_meta !== $existing_local_url ) {
				update_post_meta( $post_id, 'anime_cover_image', $existing_local_url );
			}
			return;
		}

		// ★ 尚無特色圖片：下載並建立附件。
		$upload_dir = wp_upload_dir();
		$filename   = sanitize_file_name( 'anime-cover-' . $post_id . '-' . md5( $image_url ) . '.jpg' );
		$file_path  = $upload_dir['path'] . '/' . $filename;

		if ( ! file_exists( $file_path ) ) {
			$response = wp_remote_get( $image_url, [
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (compatible; AnimeSync/1.0; +' . home_url() . ')',
			] );
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				// 下載失敗：anime_cover_image 保留 AniList URL（save_post_meta 已寫入）。
				return;
			}
			$image_data = wp_remote_retrieve_body( $response );
			if ( empty( $image_data ) ) return;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file_path, $image_data );
		}

		$file_type  = wp_check_filetype( $filename );
		$attachment = [
			'post_mime_type' => $file_type['type'] ?: 'image/jpeg',
			'post_title'     => sanitize_text_field( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attach_id = wp_insert_attachment( $attachment, $file_path, $post_id );
		if ( is_wp_error( $attach_id ) ) return;

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $attach_data );
		set_post_thumbnail( $post_id, $attach_id );

		// ★★ [1.5.1] 核心修正：下載成功後把本機 URL 覆寫進 anime_cover_image，
		//              番組表模板讀到的就是自己網站的圖片，不再指向 AniList CDN。
		$local_url = wp_get_attachment_url( $attach_id );
		if ( $local_url ) {
			update_post_meta( $post_id, 'anime_cover_image', $local_url );
		}
	}

	// =========================================================================
	// PRIVATE – 儲存分類法
	// =========================================================================

	private function save_taxonomies( int $post_id, array $data ): void {

		if ( ! empty( $data['anime_genres'] ) && is_array( $data['anime_genres'] ) ) {
			$genre_map = $this->get_anilist_genre_map();
			$genre_ids = [];

			foreach ( $data['anime_genres'] as $genre_name ) {
				$genre_name = trim( (string) $genre_name );
				if ( $genre_name === '' ) continue;

				$zh_name = $genre_map[ $genre_name ] ?? $genre_name;

				$term = term_exists( $zh_name, 'genre' );
				if ( ! $term ) {
					$term = wp_insert_term( $zh_name, 'genre' );
				}
				if ( ! is_wp_error( $term ) ) {
					$genre_ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				}
			}

			if ( ! empty( $genre_ids ) ) {
				wp_set_post_terms( $post_id, $genre_ids, 'genre' );
			}
		}

		$season_year = (int) ( $data['anime_season_year'] ?? 0 );
		$season      = strtoupper( $data['anime_season'] ?? '' );

		if ( $season_year && $season ) {
			$season_map = [
				'SPRING' => '春季', 'SUMMER' => '夏季',
				'FALL'   => '秋季', 'WINTER' => '冬季',
			];
			$season_suffix_map = [
				'SPRING' => 'spring', 'SUMMER' => 'summer',
				'FALL'   => 'fall',   'WINTER' => 'winter',
			];

			$season_zh    = $season_map[ $season ] ?? ucfirst( strtolower( $season ) );
			$season_label = "{$season_year} {$season_zh}";
			$season_slug  = $season_suffix_map[ $season ] ?? sanitize_title( $season );

			$year_term_id = $this->ensure_year_parent_term( $season_year );

			$child_slug = "{$season_year}-{$season_slug}";
			$child_term = get_term_by( 'slug', $child_slug, 'anime_season_tax' );

			if ( ! $child_term ) {
				$insert_args = [ 'slug' => $child_slug ];
				if ( $year_term_id > 0 ) {
					$insert_args['parent'] = $year_term_id;
				}
				$result = wp_insert_term( $season_label, 'anime_season_tax', $insert_args );

				if ( is_wp_error( $result ) && $result->get_error_code() === 'term_exists' ) {
					$existing_id = (int) ( $result->get_error_data() ?: 0 );
					if ( $existing_id > 0 ) {
						$child_term_id = $existing_id;
					}
				} elseif ( ! is_wp_error( $result ) ) {
					$child_term_id = (int) $result['term_id'];
				}
			} else {
				$child_term_id = (int) $child_term->term_id;

				if ( $year_term_id > 0 && (int) $child_term->parent === 0 ) {
					wp_update_term( $child_term_id, 'anime_season_tax', [ 'parent' => $year_term_id ] );
				}
			}

			if ( ! empty( $child_term_id ) ) {
				wp_set_post_terms( $post_id, [ $child_term_id ], 'anime_season_tax' );
			}
		}

		$format = $data['anime_format'] ?? '';
		if ( $format !== '' ) {
			$format_zh_map = $this->get_anilist_format_map();
			$format_key    = strtoupper( $format );
			$format_slug   = strtolower( str_replace( '_', '-', $format ) );

			$format_zh_name = $format_zh_map[ $format_key ]['name'] ?? ucfirst( strtolower( $format_key ) );
			$format_zh_slug = $format_zh_map[ $format_key ]['slug'] ?? $format_slug;

			$term = get_term_by( 'slug', $format_zh_slug, 'anime_format_tax' );
			if ( ! $term ) {
				$result = wp_insert_term( $format_zh_name, 'anime_format_tax', [ 'slug' => $format_zh_slug ] );
				if ( ! is_wp_error( $result ) ) {
					$tid = (int) $result['term_id'];
				} elseif ( $result->get_error_code() === 'term_exists' ) {
					$tid = (int) ( $result->get_error_data() ?: 0 );
				}
			} else {
				$tid = (int) $term->term_id;
			}

			if ( ! empty( $tid ) ) {
				wp_set_post_terms( $post_id, [ $tid ], 'anime_format_tax' );
			}
		}

		/*
		 * 原作類型 → anime_source_tax
		 * 比照上方 format 的做法：以 slug 為準找詞彙，缺的就建。
		 * 對照表見 anime_sync_get_source_tax_map()（anime-sync-pro.php）。
		 * 既有作品的補寫由 `wp anime-sync backfill-source-tax` 處理。
		 */
		$source = $data['anime_source'] ?? '';

		if ( $source !== '' && function_exists( 'anime_sync_get_source_tax_map' ) ) {
			$source_map  = anime_sync_get_source_tax_map();

			// 原作國別可補足 AniList source 分不出來的韓漫／國漫。
			$source_key  = function_exists( 'anime_sync_resolve_source_key' )
				? anime_sync_resolve_source_key(
					(string) $source,
					(string) ( $data['anime_source_country'] ?? '' )
				)
				: strtoupper( trim( (string) $source ) );
			$source_name = $source_map[ $source_key ]['name'] ?? '';
			$source_slug = $source_map[ $source_key ]['slug'] ?? '';

			// 對照表沒有的代碼不建詞彙：寧可少一個連結，也不要生出一堆髒詞彙。
			if ( $source_name !== '' && $source_slug !== '' ) {
				$source_tid  = 0;
				$source_term = get_term_by( 'slug', $source_slug, 'anime_source_tax' );

				if ( ! $source_term ) {
					$source_result = wp_insert_term(
						$source_name,
						'anime_source_tax',
						[ 'slug' => $source_slug ]
					);

					if ( ! is_wp_error( $source_result ) ) {
						$source_tid = (int) $source_result['term_id'];
					} elseif ( $source_result->get_error_code() === 'term_exists' ) {
						$source_tid = (int) ( $source_result->get_error_data() ?: 0 );
					}
				} else {
					$source_tid = (int) $source_term->term_id;
				}

				if ( $source_tid > 0 ) {
					wp_set_post_terms( $post_id, [ $source_tid ], 'anime_source_tax' );
				}
			}
		}

		if ( ! empty( $data['anime_tags'] ) && is_array( $data['anime_tags'] ) ) {
			$tag_ids = [];
			foreach ( $data['anime_tags'] as $tag_name ) {
				$tag_name = trim( (string) $tag_name );
				if ( $tag_name === '' ) continue;
				$zh_name = $this->resolve_tag_name( $tag_name );
				if ( $zh_name === '' ) continue;
				$tag_id  = $this->find_or_create_tag( $zh_name );
				if ( $tag_id ) $tag_ids[] = $tag_id;
			}
			if ( ! empty( $tag_ids ) ) wp_set_post_terms( $post_id, $tag_ids, 'post_tag' );
		}

		$studios_raw = $data['anime_studios'] ?? '';
		if ( ! empty( $studios_raw ) ) {
			$studio_names    = array_filter( array_map( 'trim', explode( ',', $studios_raw ) ) );
			$studio_term_ids = [];
			foreach ( $studio_names as $studio_name ) {
				if ( $studio_name === '' ) continue;
				$term = term_exists( $studio_name, 'anime_studio_tax' );
				if ( ! $term ) {
					$term = wp_insert_term( $studio_name, 'anime_studio_tax', [
						'slug' => sanitize_title( $studio_name ),
					] );
				}
				if ( ! is_wp_error( $term ) ) {
					$studio_term_ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				}
			}
			if ( ! empty( $studio_term_ids ) ) {
				wp_set_object_terms( $post_id, $studio_term_ids, 'anime_studio_tax', false );
			}
		}
	}

	private function ensure_year_parent_term( int $year ): int {
		if ( $year <= 0 ) return 0;

		$slug = (string) $year;
		$term = get_term_by( 'slug', $slug, 'anime_season_tax' );

		if ( $term ) {
			return (int) $term->term_id;
		}

		$result = wp_insert_term( (string) $year, 'anime_season_tax', [ 'slug' => $slug ] );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_code() === 'term_exists' ) {
				$existing_id = (int) ( $result->get_error_data() ?: 0 );
				return $existing_id;
			}
			return 0;
		}

		return (int) $result['term_id'];
	}

	private function get_anilist_genre_map(): array {
		return [
			'Action' => '動作', 'Adventure' => '冒險', 'Comedy' => '喜劇',
			'Drama' => '劇情', 'Ecchi' => '輕色情', 'Fantasy' => '奇幻',
			'Hentai' => '成人', 'Horror' => '恐怖', 'Mahou Shoujo' => '魔法少女',
			'Mecha' => '機甲', 'Music' => '音樂', 'Mystery' => '推理',
			'Psychological' => '心理', 'Romance' => '戀愛', 'Sci-Fi' => '科幻',
			'Slice of Life' => '日常', 'Sports' => '運動', 'Supernatural' => '超自然',
			'Thriller' => '驚悚',
		];
	}

	private function get_anilist_format_map(): array {
		return [
			'TV'       => [ 'name' => 'TV',     'slug' => 'tv'       ],
			'TV_SHORT' => [ 'name' => 'TV短篇', 'slug' => 'tv-short' ],
			'MOVIE'    => [ 'name' => '劇場版', 'slug' => 'movie'    ],
			'OVA'      => [ 'name' => 'OVA',    'slug' => 'ova'      ],
			'ONA'      => [ 'name' => 'ONA',    'slug' => 'ona'      ],
			'SPECIAL'  => [ 'name' => '特別篇', 'slug' => 'special'  ],
			'MUSIC'    => [ 'name' => '音樂MV', 'slug' => 'music'    ],
		];
	}

	private function map_streaming_to_tw_fields( int $post_id, string $external_links_json ): void {
		$links = json_decode( $external_links_json, true );
		if ( ! is_array( $links ) || empty( $links ) ) return;

		$platform_map = [
			'Crunchyroll'        => 'anime_tw_streaming_url_crunchyroll',
			'Netflix'            => 'anime_tw_streaming_url_netflix',
			'Disney Plus'        => 'anime_tw_streaming_url_disney',
			'Disney+'            => 'anime_tw_streaming_url_disney',
			'Amazon Prime Video' => 'anime_tw_streaming_url_amazon',
			'Hulu'               => 'anime_tw_streaming_url_hulu',
			'HIDIVE'             => 'anime_tw_streaming_url_hidive',
			'Bilibili'           => 'anime_tw_streaming_url_bilibili',
			'YouTube'            => 'anime_tw_streaming_url_youtube',
			'WeTV'               => 'anime_tw_streaming_url_wetv',
			'Viu'                => 'anime_tw_streaming_url_viu',
			'Ani-One Asia'       => 'anime_tw_streaming_url_ani_one',
			'Muse Asia'          => 'anime_tw_streaming_url_muse',
		];

		$platform_to_checkbox = [
			'Netflix'      => 'netflix',
			'Disney Plus'  => 'disney',
			'Disney+'      => 'disney',
			'Crunchyroll'  => 'crunchyroll',
			'Ani-One Asia' => 'ani_one',
			'Muse Asia'    => 'muse',
		];

		$checked_platforms = get_post_meta( $post_id, 'anime_tw_streaming', true );
		if ( ! is_array( $checked_platforms ) ) {
			$checked_platforms = [];
		}

		$has_existing = ! empty( $checked_platforms );

		foreach ( $links as $link ) {
			$site = $link['site'] ?? '';
			$url  = $link['url']  ?? '';
			$type = strtoupper( $link['type'] ?? '' );

			if ( $site === '' || $url === '' ) continue;
			if ( $type !== '' && $type !== 'STREAMING' ) continue;

			if ( $site === 'YouTube' ) {
				if ( stripos( $url, 'AniOneAsia' ) !== false || stripos( $url, 'ani-one' ) !== false ) {
					$site = 'Ani-One Asia';
				} elseif ( stripos( $url, 'MuseAsia' ) !== false || stripos( $url, 'muse' ) !== false ) {
					$site = 'Muse Asia';
				}
			}

			if ( isset( $platform_map[ $site ] ) ) {
				$meta_key = $platform_map[ $site ];
				$existing = get_post_meta( $post_id, $meta_key, true );
				if ( empty( $existing ) ) {
					update_post_meta( $post_id, $meta_key, esc_url_raw( $url ) );
				}
			}

			if ( ! $has_existing && isset( $platform_to_checkbox[ $site ] ) ) {
				$val = $platform_to_checkbox[ $site ];
				if ( ! in_array( $val, $checked_platforms, true ) ) {
					$checked_platforms[] = $val;
				}
			}
		}

		if ( ! $has_existing && ! empty( $checked_platforms ) ) {
			update_post_meta( $post_id, 'anime_tw_streaming', array_values( $checked_platforms ) );
		}
	}

	private function resolve_tag_name( string $en_name ): string {
		$map = $this->get_tag_map();
		return $map[ $en_name ] ?? '';
	}

	private function google_translate( string $text ): string {
		return '';
	}

	private function find_or_create_tag( string $name ): ?int {
		$name = trim( $name );
		if ( $name === '' ) return null;
		$term = term_exists( $name, 'post_tag' );
		if ( ! $term ) $term = wp_insert_term( $name, 'post_tag' );
		if ( is_wp_error( $term ) ) return null;
		return is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	}

	private function get_tag_map(): array {
		return [
			'Amnesia' => '失憶', 'Revenge' => '復仇', 'Reincarnation' => '轉生',
			'Time Travel' => '時間旅行', 'Time Loop' => '時間循環', 'Isekai' => '異世界',
			'Parallel World' => '平行世界', 'Virtual Reality' => '虛擬實境',
			'Post-Apocalyptic' => '末日後', 'Dystopia' => '反烏托邦',
			'Historical' => '歷史', 'Space' => '宇宙', 'Cyberpunk' => '賽博龐克',
			'Mythology' => '神話', 'Anti-Hero' => '反英雄', 'Tsundere' => '傲嬌',
			'Yandere' => '病嬌', 'Tragedy' => '悲劇', 'Comedy' => '喜劇',
			'Romance' => '戀愛', 'Harem' => '後宮', 'Slice of Life' => '日常',
			'School Life' => '校園生活', 'Magic' => '魔法', 'Superpowers' => '超能力',
			'Supernatural' => '超自然', 'Demons' => '惡魔', 'Vampires' => '吸血鬼',
			'Action' => '動作', 'Martial Arts' => '武術', 'Mechs' => '機甲',
			'Military' => '軍事', 'War' => '戰爭', 'Survival' => '求生',
			'Idol' => '偶像', 'Detective' => '偵探', 'Samurai' => '武士',
			'Ninja' => '忍者', 'Psychological' => '心理', 'Gore' => '血腥暴力',
			'Horror' => '恐怖', 'Ecchi' => '輕微色情', 'Moe' => '萌',
			'Music' => '音樂', 'Sports' => '運動', 'Cooking' => '料理',
			'Shounen' => '少年', 'Shoujo' => '少女', 'Seinen' => '青年',
			'Josei' => '女性向', 'Mecha' => '機器人', 'Sci-Fi' => '科幻',
			'Adventure' => '冒險', 'Mystery' => '推理', 'Thriller' => '驚悚',
			'Drama' => '劇情', 'Family' => '家庭', 'Kids' => '兒童',
		];
	}

	private function get_import_lock_key( int $anilist_id ): string {
		return 'anime_sync_import_lock_' . $anilist_id;
	}

	private function acquire_import_lock( int $anilist_id, bool $force = false ): string {
		if ( $anilist_id <= 0 ) {
			return '';
		}

		$key      = $this->get_import_lock_key( $anilist_id );
		$existing = get_transient( $key );

		if ( is_array( $existing ) && ! empty( $existing['token'] ) ) {
			$age = time() - absint( $existing['created_at'] ?? 0 );
			if ( ! $force || $age < 30 ) {
				return '';
			}
		}

		$token = wp_generate_uuid4();
		set_transient( $key, [
			'token'      => $token,
			'created_at' => time(),
		], 2 * MINUTE_IN_SECONDS );

		$stored = get_transient( $key );
		if ( is_array( $stored ) && ( $stored['token'] ?? '' ) === $token ) {
			return $token;
		}

		return '';
	}

	private function release_import_lock( int $anilist_id, string $token ): void {
		if ( $anilist_id <= 0 || $token === '' ) {
			return;
		}

		$key    = $this->get_import_lock_key( $anilist_id );
		$stored = get_transient( $key );
		if ( is_array( $stored ) && ( $stored['token'] ?? '' ) === $token ) {
			delete_transient( $key );
		}
	}

	private function find_existing( int $anilist_id ): int {
		if ( $anilist_id <= 0 ) return 0;

		$query = new WP_Query( [
			'post_type'      => 'anime',
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => 'anime_anilist_id',
					'value'   => $anilist_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		if ( count( $query->posts ) > 1 && class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::log( 'warning', '偵測到重複 anime_anilist_id 文章', [
				'anilist_id' => $anilist_id,
				'post_ids'   => array_map( 'intval', $query->posts ),
			] );
		}

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}
}
