<?php
/**
 * 檔案名稱: includes/class-manga-import-manager.php
 *
 * 漫畫匯入管理器（一次抓完,無兩段式 enrich）。
 * 沿用動畫的鎖欄位機制（anime_locked_fields）、封面下載、成人過濾。
 * 與 Anime_Sync_Import_Manager 平行,共用同一個 api_handler / cn_converter。
 *
 * @package Anime_Sync_Pro
 * @version 1.3.1
 *
 * Changelog:
 *   1.3.1 — [簡體標題] save_post_meta() 的 $meta_map 新增 anime_title_simplified，
 *           來源為 get_manga_data() 回傳的 Bangumi 原始簡體標題（name_cn）。
 *           此 key 不在 prepare_meta_value() 的 $convertible 清單內，故原樣
 *           寫入、不經 OpenCC 簡繁轉換，保留簡體供大陸用戶搜尋。
 *   1.3.0 — [外部連結] save_post_meta() 新增 manga_external_links 寫入：
 *           把 get_manga_data() 回傳的 AniList externalLinks（官網 / Shonen
 *           Jump+ / MANGA Plus / Twitter / Naver / VIZ 等）以 JSON 存進
 *           manga_external_links meta，供 single-manga.php 外部連結區迴圈輸出。
 *           因是陣列型資料，獨立於 $meta_map 純量迴圈處理；鎖定判斷沿用
 *           anime_locked_fields；空陣列時保留舊值不覆蓋。
 *   1.2.0 — [地基修正] 兩項跨媒體系列必要修正：
 *           (1) link_related_anime() 找到關聯動畫後，新增 inherit_series_terms()，
 *               把動畫身上的 anime_series_tax 系列 term 複製到漫畫，讓
 *               archive-series.php 主查詢的 tax_query 能直接撈到漫畫，
 *               不再依賴 manga_related_anime 反查 fallback。小說/遊戲
 *               import manager 未來可沿用同一套邏輯。
 *           (2) save_post_meta() 卷數/話數同時寫入相容 key（anime_volumes /
 *               anime_chapters），使前台 smacg_get_meta($pid,'volumes') /
 *               ('chapters') 讀得到，卡片才能顯示「X 卷 / X 話」。
 *               根因：前台讀 _smacg_ 或 anime_ 前綴，但匯入只寫 manga_ 前綴。
 *   1.1.0 — 匯入成功後呼叫 Anime_Sync_Activity_Logger::record('imported', ...)，
 *           讓「操作紀錄」頁能獨立統計漫畫的匯入次數（與發布分開）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Manga_Import_Manager {

	private Anime_Sync_API_Handler  $api_handler;
	private Anime_Sync_CN_Converter $cn_converter;

	public function __construct(
		Anime_Sync_API_Handler  $api_handler,
		Anime_Sync_CN_Converter $cn_converter
	) {
		$this->api_handler  = $api_handler;
		$this->cn_converter = $cn_converter;
	}

	// =========================================================================
	// PUBLIC – 單筆漫畫匯入
	// =========================================================================
	public function import_single( int $anilist_id, ?int $bangumi_id = null, string $source = 'manual', array $args = [] ): array {

		if ( $anilist_id <= 0 ) {
			return [ 'success' => false, 'message' => '無效的 AniList ID' ];
		}

		$force      = ! empty( $args['force'] );
		$lock_token = $this->acquire_import_lock( $anilist_id, $force );

		if ( $lock_token === '' ) {
			return [
				'success' => false,
				'message' => '此漫畫正在同步中,請稍後再試',
				'locked'  => true,
			];
		}

		try {
			$existing_id = $this->find_existing( $anilist_id );
			$is_update   = (bool) $existing_id;

			// 已存在且未強制:跳過,防止覆蓋人工編輯。
			if ( $is_update && ! $force ) {
				return [
					'success'  => true,
					'skipped'  => true,
					'message'  => '⚠️ 已存在,跳過匯入（需更新請勾選「強制覆蓋」）',
					'post_id'  => $existing_id,
					'title'    => get_the_title( $existing_id ),
					'edit_url' => get_edit_post_link( $existing_id, 'raw' ),
				];
			}

			// 沿用既有 Bangumi ID
			if ( ( ! $bangumi_id || $bangumi_id <= 0 ) && $existing_id > 0 ) {
				$stored = (int) get_post_meta( $existing_id, 'anime_bangumi_id', true );
				if ( $stored > 0 ) $bangumi_id = $stored;
			}

			// 抓資料
			$data = $this->api_handler->get_manga_data( $anilist_id, $existing_id, $bangumi_id );
			if ( is_wp_error( $data ) ) {
				return [ 'success' => false, 'message' => '資料取得失敗:' . $data->get_error_message() ];
			}
			if ( empty( $data['anilist_id'] ) ) {
				return [ 'success' => false, 'message' => '無效的 AniList 資料（缺少 anilist_id）' ];
			}

			// 成人過濾
			if ( $this->is_adult_content( $data ) ) {
				$blocked = $data['anime_title_chinese'] ?: ( $data['anime_title_romaji'] ?? "ID {$anilist_id}" );
				return [
					'success'        => true,
					'skipped'        => true,
					'adult_filtered' => true,
					'message'        => "🔞 已略過成人作品 – {$blocked} (ID {$anilist_id})",
					'title'          => $blocked,
				];
			}

			// 標題
			if ( $is_update ) {
				$existing_post = get_post( $existing_id );
				$post_title    = ( $existing_post && trim( $existing_post->post_title ) !== '' )
					? $existing_post->post_title
					: ( $data['anime_title_chinese'] ?: ( $data['anime_title_romaji'] ?? "Manga {$anilist_id}" ) );
			} else {
				$post_title = $data['anime_title_chinese'] ?: ( $data['anime_title_romaji'] ?? "Manga {$anilist_id}" );
			}

			$post_slug = $this->generate_slug( $data, $existing_id );

			// 保留現有狀態
			$post_status = 'draft';
			if ( $is_update && $existing_id > 0 ) {
				$current = get_post_status( $existing_id );
				if ( $current && ! in_array( $current, [ 'auto-draft', 'trash' ], true ) ) {
					$post_status = $current;
				}
			}

			$post_data = [
				'post_type'   => 'manga',   // ★ 關鍵:manga 而非 anime
				'post_title'  => $post_title,
				'post_name'   => $post_slug,
				'post_status' => $post_status,
				'post_author' => get_current_user_id() ?: 1,
			];

			if ( $is_update ) {
				$post_data['ID'] = $existing_id;
				$post_id = wp_update_post( $post_data, true );
			} else {
				$post_id = wp_insert_post( $post_data, true );
			}

			if ( is_wp_error( $post_id ) ) {
				return [ 'success' => false, 'message' => '文章建立失敗:' . $post_id->get_error_message() ];
			}

			// 寫入 meta
			$this->save_post_meta( $post_id, $data );

			// ★ [1.1.0] 記錄「匯入」動作，與「發布」統計分開
			if ( class_exists( 'Anime_Sync_Activity_Logger' ) ) {
				$log_post = get_post( $post_id );
				if ( $log_post ) {
					Anime_Sync_Activity_Logger::record( 'imported', $log_post );
				}
			}

			// 封面下載（沿用鎖定判斷 anime_cover_image）
			if ( ! empty( $data['anime_cover_image'] ) ) {
				$this->set_featured_image( $post_id, $data['anime_cover_image'], $post_title );
			}

			// 關聯動畫:AniList ID → post ID（並繼承系列 term）
			$this->link_related_anime( $post_id, (int) ( $data['manga_related_anime_al'] ?? 0 ) );

			// 分類（類型 / 標籤）
			$this->save_taxonomies( $post_id, $data );

			update_post_meta( $post_id, '_import_source', sanitize_text_field( $source ) );
			update_post_meta( $post_id, 'anime_last_sync',    current_time( 'mysql' ) );
			update_post_meta( $post_id, 'anime_last_updated', current_time( 'mysql' ) );

			// 非同步拓展維基/Wikidata 資料(避免拖慢匯入回應)
			if ( class_exists( 'Anime_Sync_Manga_Wiki_Cron' ) ) {
				wp_schedule_single_event(
					time() + 5,
					Anime_Sync_Manga_Wiki_Cron::HOOK_SINGLE,
					[ (int) $post_id, 'import' ]
				);
			}

			$display_title = $data['anime_title_chinese'] ?: $data['anime_title_romaji'] ?: "ID {$anilist_id}";
			$action        = $is_update ? '已更新' : '已匯入';

			return [
				'success'  => true,
				'message'  => "{$action} – {$display_title} (ID {$anilist_id})",
				'post_id'  => $post_id,
				'title'    => $display_title,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			];
		} finally {
			$this->release_import_lock( $anilist_id, $lock_token );
		}
	}

	// =========================================================================
	// PRIVATE – 寫入 Post Meta（沿用 anime_locked_fields 鎖定）
	// =========================================================================
	private function save_post_meta( int $post_id, array $data ): void {

		$meta_map = [
			// 共用 anime_ key
			'anime_anilist_id'       => $data['anilist_id'] ?? 0,
			'anime_mal_id'           => $data['mal_id'] ?? 0,
			'anime_bangumi_id'       => $data['bangumi_id'] ?? 0,
			'anime_title_chinese'    => $data['anime_title_chinese'] ?? '',
			// ★ [1.3.1] 簡體標題（Bangumi name_cn 原樣，不在 $convertible 清單，不經 OpenCC）
			'anime_title_simplified' => $data['anime_title_simplified'] ?? '',
			'anime_title_romaji'     => $data['anime_title_romaji'] ?? '',
			'anime_title_english'    => $data['anime_title_english'] ?? '',
			'anime_title_native'     => $data['anime_title_native'] ?? '',
			'anime_format'           => $data['anime_format'] ?? '',
			'anime_status'           => $data['anime_status'] ?? '',
			'anime_source'           => $data['anime_source'] ?? '',
			'anime_score_anilist'    => $data['anime_score_anilist'] ?? 0,
			'anime_score_bangumi'    => $data['anime_score_bangumi'] ?? 0,
		    'anime_score_mal'        => $data['anime_score_mal'] ?? 0,
			'anime_popularity'       => $data['anime_popularity'] ?? 0,
			'anime_start_date'       => $data['anime_start_date'] ?? '',
			'anime_end_date'         => $data['anime_end_date'] ?? '',
			'anime_cover_image'      => $data['anime_cover_image'] ?? '',
			'anime_banner_image'     => $data['anime_banner_image'] ?? '',
			'anime_synopsis_chinese' => $data['anime_synopsis_chinese'] ?? '',
			// 漫畫獨有 manga_ key
			'manga_chapters'         => $data['manga_chapters'] ?? '',
			'manga_volumes'          => $data['manga_volumes'] ?? '',
			'manga_author'           => $data['manga_author'] ?? '',
			'manga_artist'           => $data['manga_artist'] ?? '',
			'manga_tw_publisher'     => $data['manga_tw_publisher'] ?? '',
			'manga_tw_translator'    => $data['manga_tw_translator'] ?? '',
			'manga_tw_release_date'  => $data['manga_tw_release_date'] ?? '',
			'anime_sync_time'        => current_time( 'mysql' ),
		];

		$locked = $this->get_locked_fields( $post_id );

		foreach ( $meta_map as $key => $value ) {
			// sync_time 一律更新;其餘鎖定就跳過
			if ( $key !== 'anime_sync_time' && in_array( $key, $locked, true ) ) {
				continue;
			}

			// 封面:已是本機 URL 就不被 AniList URL 蓋掉
			if ( $key === 'anime_cover_image' ) {
				$current = get_post_meta( $post_id, 'anime_cover_image', true );
				if ( $current && strpos( $current, home_url() ) !== false ) {
					continue;
				}
			}

			// 台版代理:抓不到（空字串）時不要覆蓋你已手填的值
			if ( in_array( $key, [ 'manga_tw_publisher', 'manga_tw_translator', 'manga_tw_release_date' ], true ) ) {
				$incoming = trim( (string) $value );
				if ( $incoming === '' ) {
					continue; // 空值不覆蓋,保留人工填的
				}
			}

			// 話數/卷數:空字串代表未定,不要用空值蓋掉舊值
			if ( in_array( $key, [ 'manga_chapters', 'manga_volumes' ], true ) ) {
				if ( $value === '' || $value === null ) {
					continue;
				}
			}

			$value = $this->prepare_meta_value( $key, $value );
			update_post_meta( $post_id, $key, $value );
		}

		// ★ [1.2.0] 卷數/話數相容 key：前台 smacg_get_meta($pid,'volumes'/'chapters')
		//   會找 _smacg_ 或 anime_ 前綴，但上面只寫了 manga_ 前綴，導致卡片
		//   讀不到「X 卷 / X 話」。這裡把非空的卷數/話數同步寫一份 anime_ 前綴，
		//   讓前台讀得到；同時鎖定與空值規則沿用（鎖定或空值就不寫）。
		$this->mirror_count_meta( $post_id, 'manga_volumes',  'anime_volumes',  $data['manga_volumes']  ?? '', $locked );
		$this->mirror_count_meta( $post_id, 'manga_chapters', 'anime_chapters', $data['manga_chapters'] ?? '', $locked );

		// ★ [1.3.0] 外部連結(AniList externalLinks)存成 JSON。
		//   官網 / Shonen Jump+ / MANGA Plus / Twitter / Naver / VIZ 等。
		//   陣列型資料,不走上面的 $meta_map 純量迴圈,獨立處理。
		//   鎖定判斷:manga_external_links 在 locked 內就不覆蓋。
		//   空陣列時保留舊值,避免 AniList 暫時抓不到就把好資料清掉。
		if ( ! in_array( 'manga_external_links', $locked, true ) ) {
			$ext = $data['manga_external_links'] ?? null;
			if ( is_array( $ext ) && ! empty( $ext ) ) {
				update_post_meta(
					$post_id,
					'manga_external_links',
					wp_json_encode( $ext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				);
			}
		}
	}

	/**
	 * ★ [1.2.0] 把 manga_ 前綴的卷數/話數鏡射一份到 anime_ 前綴，供前台讀取。
	 *
	 * 規則與主迴圈一致：
	 *   - 來源整欄鎖定（manga_volumes / manga_chapters 在 locked 內）→ 不寫。
	 *   - 空字串 / null（代表未定）→ 不寫，避免用空值蓋掉舊值。
	 *
	 * @param int    $post_id
	 * @param string $src_key   來源 key（manga_volumes / manga_chapters）
	 * @param string $dst_key   鏡射目標 key（anime_volumes / anime_chapters）
	 * @param mixed  $value     來源值
	 * @param array  $locked    鎖定欄位清單
	 */
	private function mirror_count_meta( int $post_id, string $src_key, string $dst_key, $value, array $locked ): void {
		if ( in_array( $src_key, $locked, true ) ) {
			return; // 來源鎖定，鏡射也不動
		}
		if ( $value === '' || $value === null ) {
			return; // 空值不覆蓋
		}
		update_post_meta( $post_id, $dst_key, (int) $value );
	}

	private function prepare_meta_value( string $key, $value ) {
		// 需簡繁轉換的文字欄位
		$convertible = [ 'anime_synopsis_chinese', 'manga_author', 'manga_artist', 'manga_tw_publisher', 'manga_tw_translator' ];
		if ( in_array( $key, $convertible, true ) && is_string( $value ) ) {
			return $this->cn_converter->convert( $value );
		}
		return $value;
	}

	// =========================================================================
	// PRIVATE – 關聯動畫:AniList ID → 站內 anime post ID（並繼承系列 term）
	// =========================================================================
	private function link_related_anime( int $post_id, int $anime_anilist_id ): void {
		if ( $anime_anilist_id <= 0 ) return;

		$q = new WP_Query( [
			'post_type'      => 'anime',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [
				'key'     => 'anime_anilist_id',
				'value'   => $anime_anilist_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			] ],
		] );

		if ( empty( $q->posts ) ) return;

		$anime_id = (int) $q->posts[0];
		update_post_meta( $post_id, 'manga_related_anime', $anime_id );

		// ★ [1.2.0 地基修正] 把關聯動畫的系列 term 複製到漫畫身上，
		//   讓漫畫能被 archive-series.php 主查詢的 tax_query 直接撈到，
		//   不再依賴 manga_related_anime 反查 fallback。
		$this->inherit_series_terms( $post_id, $anime_id );
	}

	/**
	 * ★ [1.2.0 地基修正] 從關聯動畫繼承 anime_series_tax 系列 term。
	 *
	 * anime_series_tax 已在主檔（anime-sync-pro.php）以 ANIME_SYNC_PRO_CPTS
	 * 常數註冊給五種 CPT（anime/manga/novel/game/music），所以這裡
	 * wp_set_object_terms 對 manga 一定有效。小說 / 遊戲 import manager
	 * 未來可沿用同一套邏輯。
	 *
	 * 前提：來源動畫必須先存在站內且已被打上系列 term（由動畫匯入的
	 * 系列樹邏輯負責）。若動畫沒有系列 term，則此處不動作，漫畫仍可
	 * 靠 archive-series.php 既有的 manga_related_anime 反查 fallback 顯示。
	 *
	 * @param int $target_id 要打 term 的作品（漫畫）post ID。
	 * @param int $anime_id  來源動畫 post ID。
	 */
	private function inherit_series_terms( int $target_id, int $anime_id ): void {
		if ( $target_id <= 0 || $anime_id <= 0 ) return;

		$series_terms = wp_get_object_terms( $anime_id, 'anime_series_tax', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $series_terms ) || empty( $series_terms ) ) return;

		wp_set_object_terms( $target_id, array_map( 'intval', $series_terms ), 'anime_series_tax', false );
	}

	// =========================================================================
	// PRIVATE – 分類（類型 genre / 標籤 post_tag）
	// 注意:漫畫沒有季度/製作公司/格式 taxonomy,只做 genre 和 tag。
	// =========================================================================
	private function save_taxonomies( int $post_id, array $data ): void {

		if ( ! empty( $data['anime_genres'] ) && is_array( $data['anime_genres'] ) ) {
			$genre_map = $this->get_genre_map();
			$genre_ids = [];
			foreach ( $data['anime_genres'] as $g ) {
				$g = trim( (string) $g );
				if ( $g === '' ) continue;
				$zh   = $genre_map[ $g ] ?? $g;
				$term = term_exists( $zh, 'genre' );
				if ( ! $term ) $term = wp_insert_term( $zh, 'genre' );
				if ( ! is_wp_error( $term ) ) {
					$genre_ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
				}
			}
			if ( ! empty( $genre_ids ) ) {
				wp_set_post_terms( $post_id, $genre_ids, 'genre' );
			}
		}
	}

	private function get_genre_map(): array {
		return [
			'Action' => '動作', 'Adventure' => '冒險', 'Comedy' => '喜劇',
			'Drama' => '劇情', 'Ecchi' => '輕色情', 'Fantasy' => '奇幻',
			'Horror' => '恐怖', 'Mahou Shoujo' => '魔法少女', 'Mecha' => '機甲',
			'Music' => '音樂', 'Mystery' => '推理', 'Psychological' => '心理',
			'Romance' => '戀愛', 'Sci-Fi' => '科幻', 'Slice of Life' => '日常',
			'Sports' => '運動', 'Supernatural' => '超自然', 'Thriller' => '驚悚',
		];
	}

	// =========================================================================
	// PRIVATE – 成人過濾
	// =========================================================================
	private function is_adult_content( array $data ): bool {
		if ( ! empty( $data['anime_is_adult'] ) ) return true;
		$blocked = [ 'Hentai', 'hentai', '成人' ];
		if ( ! empty( $data['anime_genres'] ) && is_array( $data['anime_genres'] ) ) {
			foreach ( $data['anime_genres'] as $g ) {
				if ( in_array( trim( (string) $g ), $blocked, true ) ) return true;
			}
		}
		return false;
	}

	// =========================================================================
	// PRIVATE – 鎖定欄位
	// =========================================================================
	private function get_locked_fields( int $post_id ): array {
		$locked = get_post_meta( $post_id, 'anime_locked_fields', true );
		return is_array( $locked ) ? $locked : [];
	}

	// =========================================================================
	// PRIVATE – 產生 slug（查重範圍 = manga,不是 anime）
	// =========================================================================
	private function generate_slug( array $data, int $exclude_id = 0 ): string {
		$candidates = array_filter( [
			$data['anime_title_romaji'] ?? '',
			$data['anime_title_english'] ?? '',
			'manga-' . ( $data['anilist_id'] ?? 0 ),
		] );

		$raw  = reset( $candidates );
		$slug = sanitize_title( $raw );
		if ( $slug === '' ) $slug = 'manga-' . ( $data['anilist_id'] ?? 0 );

		$original = $slug;
		$suffix   = 1;

		while ( true ) {
			// ★ 查 manga,不查 anime → 動畫漫畫同名不互相干擾
			$found = get_page_by_path( $slug, OBJECT, 'manga' );
			if ( ! $found || ( $exclude_id > 0 && (int) $found->ID === $exclude_id ) ) {
				break;
			}
			$slug = $original . '-' . $suffix++;
		}

		return $slug;
	}

	// =========================================================================
	// PRIVATE – 查找已存在漫畫（查 manga post type）
	// =========================================================================
	private function find_existing( int $anilist_id ): int {
		if ( $anilist_id <= 0 ) return 0;

		$q = new WP_Query( [
			'post_type'      => 'manga',   // ★ 查 manga
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

		return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
	}

	// =========================================================================
	// PRIVATE – 特色圖片下載（沿用動畫做法,鎖定判斷 anime_cover_image）
	// =========================================================================
	private function set_featured_image( int $post_id, string $image_url, string $title ): void {
		$locked = $this->get_locked_fields( $post_id );
		if ( in_array( 'anime_cover_image', $locked, true ) ) return;

		if ( has_post_thumbnail( $post_id ) ) {
			$thumb_id  = get_post_thumbnail_id( $post_id );
			$local_url = wp_get_attachment_url( $thumb_id );
			$current   = get_post_meta( $post_id, 'anime_cover_image', true );
			if ( $local_url && $current !== $local_url ) {
				update_post_meta( $post_id, 'anime_cover_image', $local_url );
			}
			return;
		}

		$upload_dir = wp_upload_dir();
		$filename   = sanitize_file_name( 'manga-cover-' . $post_id . '-' . md5( $image_url ) . '.jpg' );
		$file_path  = $upload_dir['path'] . '/' . $filename;

		if ( ! file_exists( $file_path ) ) {
			$response = wp_remote_get( $image_url, [
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (compatible; AnimeSync/1.0; +' . home_url() . ')',
			] );
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return;
			$image_data = wp_remote_retrieve_body( $response );
			if ( empty( $image_data ) ) return;
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

		$local_url = wp_get_attachment_url( $attach_id );
		if ( $local_url ) {
			update_post_meta( $post_id, 'anime_cover_image', $local_url );
		}
	}

	// =========================================================================
	// PRIVATE – 匯入鎖（防並發,與動畫獨立命名空間）
	// =========================================================================
	private function get_import_lock_key( int $anilist_id ): string {
		return 'manga_sync_import_lock_' . $anilist_id;
	}

	private function acquire_import_lock( int $anilist_id, bool $force = false ): string {
		if ( $anilist_id <= 0 ) return '';

		$key      = $this->get_import_lock_key( $anilist_id );
		$existing = get_transient( $key );

		if ( is_array( $existing ) && ! empty( $existing['token'] ) ) {
			$age = time() - absint( $existing['created_at'] ?? 0 );
			if ( ! $force || $age < 30 ) return '';
		}

		$token = wp_generate_uuid4();
		set_transient( $key, [ 'token' => $token, 'created_at' => time() ], 2 * MINUTE_IN_SECONDS );

		$stored = get_transient( $key );
		return ( is_array( $stored ) && ( $stored['token'] ?? '' ) === $token ) ? $token : '';
	}

	private function release_import_lock( int $anilist_id, string $token ): void {
		if ( $anilist_id <= 0 || $token === '' ) return;
		$key    = $this->get_import_lock_key( $anilist_id );
		$stored = get_transient( $key );
		if ( is_array( $stored ) && ( $stored['token'] ?? '' ) === $token ) {
			delete_transient( $key );
		}
	}
}
