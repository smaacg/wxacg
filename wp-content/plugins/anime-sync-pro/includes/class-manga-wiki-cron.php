<?php
/**
 * 檔案名稱: includes/class-manga-wiki-cron.php
 *
 * 每週背景 cron:遍歷所有 manga posts,
 *   1) 拿 Wikidata 結構化資料(輕,只取可信欄位:獲獎/QID/維基網址)
 *   2) 拿維基百科「出版」章節(重,每卷 ISBN + infobox 為 magazine/publisher/首刊日 主來源)
 *   3) 拿 Bangumi 分卷封面(單行本封面牆用,下載 400px 到本地)
 * 寫入對應 ACF/postmeta,鎖定欄位不覆蓋。
 *
 * @package Anime_Sync_Pro
 * @version 1.4.0
 *
 * v1.4.0 新增(維基查找命中率修正):
 *   - [新增] 查維基的標題改用「瀑布優先序」決定 $wiki_query_title:
 *              ① 手填維基網址 manga_wikipedia_url → 解析出條目名(最高優先)
 *              ② 手填條目名 manga_wiki_title(可選欄位)
 *              ③ 中文標題經 OpenCC S2TWP 轉繁(解決簡繁不一致,如 胆大党→膽大黨)
 *              ④ 原始中文標題(最後 fallback)
 *            find_qid_by_zh_title() 與 fetch_by_title() 皆改用 $wiki_query_title。
 *   - [保護] 使用者手填 manga_wikipedia_url 時,Wikidata 回傳的 wikipedia_url
 *            不覆蓋手填值(手填最可靠)。
 *
 * v1.3.0:第 3 資料源 Bangumi 關聯條目每卷封面下載至本地,存 manga_volume_covers。
 * v1.2.1:first_publish 改以 infobox 開始日為主來源。
 * v1.2.0:台/港/陸出版社與地區卷數寫入;日版卷數以 infobox 為權威。
 * v1.1.0:Wikidata 白名單化;magazine/publisher 以 infobox 為主。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Manga_Wiki_Cron {

	const HOOK_WEEKLY = 'anime_sync_manga_wiki_weekly';
	const HOOK_SINGLE = 'anime_sync_manga_wiki_single';

	const BATCH_SIZE    = 20;
	const SLEEP_BETWEEN = 2;

	public function __construct() {
		add_action( self::HOOK_WEEKLY, [ $this, 'run_weekly' ] );
		add_action( self::HOOK_SINGLE, [ $this, 'run_single' ], 10, 2 );
		add_filter( 'cron_schedules', [ $this, 'add_weekly_schedule' ] );
	}

	public function add_weekly_schedule( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( '每週一次', 'anime-sync-pro' ),
			];
		}
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_WEEKLY ) ) {
			$next = strtotime( 'next sunday 03:00:00' );
			if ( $next === false ) $next = time() + 7 * DAY_IN_SECONDS;
			wp_schedule_event( $next, 'weekly', self::HOOK_WEEKLY );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK_WEEKLY );
		if ( $ts ) wp_unschedule_event( $ts, self::HOOK_WEEKLY );
		wp_clear_scheduled_hook( self::HOOK_WEEKLY );
		wp_clear_scheduled_hook( self::HOOK_SINGLE );
	}

	public function run_weekly(): void {
		$q = new WP_Query( [
			'post_type'      => 'manga',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		if ( empty( $q->posts ) ) return;

		$offset = 0;
		foreach ( $q->posts as $post_id ) {
			wp_schedule_single_event(
				time() + ( $offset * self::SLEEP_BETWEEN ),
				self::HOOK_SINGLE,
				[ (int) $post_id, 'cron' ]
			);
			$offset++;
		}

		update_option( 'anime_sync_manga_wiki_last_run', current_time( 'mysql' ) );
	}

	public function run_single( int $post_id, string $source = 'cron' ): void {
		if ( $post_id <= 0 ) return;
		if ( get_post_type( $post_id ) !== 'manga' ) return;

		$anilist_id = (int) get_post_meta( $post_id, 'anime_anilist_id', true );
		$zh_title   = (string) ( get_post_meta( $post_id, 'anime_title_chinese', true ) ?: get_the_title( $post_id ) );

		// =====================================================================
		// ★v1.4.0 決定「拿去查維基的標題」$wiki_query_title(瀑布優先序)
		// =====================================================================
		$manual_url   = trim( (string) get_post_meta( $post_id, 'manga_wikipedia_url', true ) );
		$manual_title = trim( (string) get_post_meta( $post_id, 'manga_wiki_title', true ) );
		$had_manual_url = ( $manual_url !== '' );

		// ① 手填網址存在但沒填條目名 → 從網址末段解析條目名
		//    例:https://zh.wikipedia.org/wiki/膽大黨 → 膽大黨
		if ( $manual_title === '' && $manual_url !== '' ) {
			$path = wp_parse_url( $manual_url, PHP_URL_PATH );
			if ( $path ) {
				$seg = rawurldecode( basename( $path ) );
				// 去掉可能的 "wiki/" 殘留或空段
				if ( $seg !== '' && strtolower( $seg ) !== 'wiki' ) {
					$manual_title = $seg;
				}
			}
		}

		// ③ 中文標題轉繁(OpenCC S2TWP):解決 胆大党 → 膽大黨 之類簡繁不一致
		$zh_title_trad = ( $zh_title !== '' && class_exists( 'Anime_Sync_CN_Converter' ) )
			? Anime_Sync_CN_Converter::static_convert( $zh_title )
			: $zh_title;

		// 最終瀑布:① 手填條目名(含由網址解析) → ③ 繁體標題 → ④ 原標題
		$wiki_query_title = '';
		foreach ( [ $manual_title, $zh_title_trad, $zh_title ] as $cand ) {
			$cand = trim( (string) $cand );
			if ( $cand !== '' ) { $wiki_query_title = $cand; break; }
		}

		if ( ! class_exists( 'Anime_Sync_Wikidata_Fetcher' ) ) return;

		$wd     = new Anime_Sync_Wikidata_Fetcher();
		$wd_raw = [];

		// ---- 1. Wikidata:AniList ID → QID → entity ----
		$qid = '';
		if ( $anilist_id > 0 && method_exists( $wd, 'get_zh_title_by_anilist' ) ) {
			$al = $wd->get_zh_title_by_anilist( $anilist_id );
			if ( is_array( $al ) && ! empty( $al['qid'] ) ) {
				$qid = (string) $al['qid'];
				if ( $zh_title === '' && ! empty( $al['zh_title'] ) ) {
					$zh_title = (string) $al['zh_title'];
				}
			}
		}
		// ★v1.4.0 用瀑布標題找 QID(原本用簡體常查不到)
		if ( $qid === '' && $wiki_query_title !== '' && method_exists( $wd, 'find_qid_by_zh_title' ) ) {
			$qid = (string) $wd->find_qid_by_zh_title( $wiki_query_title );
		}
		if ( $qid !== '' && method_exists( $wd, 'fetch_entity' ) ) {
			$wd_raw = $wd->fetch_entity( $qid );
		}

		// ---- 只挑「可信」的 Wikidata 欄位 ----
		// 注意:first_publish 不從 Wikidata 取(P577 語意不符),交給下方 infobox。
		$data = [];
		if ( is_array( $wd_raw ) ) {
			if ( ! empty( $wd_raw['manga_awards'] ) ) {
				$data['manga_awards'] = $wd_raw['manga_awards'];
			}
			if ( $qid !== '' ) {
				$data['manga_wikidata_qid'] = $qid;
			}
			// ★v1.4.0 維基網址:使用者「沒有」手填時才用 Wikidata 值;
			//   手填時完全不碰 manga_wikipedia_url,保留人工指定。
			//
			// ★修正:鍵名原本寫成 $wd_raw['wikipedia_url'],但 fetch_entity()
			//   回傳的鍵一律帶 manga_ 前綴（見 class-wikidata-fetcher.php），
			//   條件永遠為 false，Wikidata 查到的維基網址從來沒被寫入過。
			if ( ! empty( $wd_raw['manga_wikipedia_url'] ) && ! $had_manual_url ) {
				$data['manga_wikipedia_url'] = $wd_raw['manga_wikipedia_url'];
			}
		}

		// ---- 2. Wikipedia wikitext(每卷 ISBN + infobox 為 magazine/publisher/首刊日 主來源)----
		//   ★v1.4.0 改用 $wiki_query_title 查詢(繁體/手填),命中率大幅提升。
		if ( class_exists( 'Anime_Sync_Wiki_Manga_Fetcher' ) && $wiki_query_title !== '' ) {
			$wp   = new Anime_Sync_Wiki_Manga_Fetcher();
			$wiki = $wp->fetch_by_title( $wiki_query_title );

			$table_vol_count = 0;
			if ( ! empty( $wiki['volumes'] ) ) {
				$data['manga_volumes_json']    = wp_json_encode( $wiki['volumes'], JSON_UNESCAPED_UNICODE );
				$data['manga_volumes_summary'] = $wiki['volumes_markdown'] ?? '';
				$table_vol_count               = count( $wiki['volumes'] );
			}

			// infobox 為 magazine / publisher / 地區出版社 / 首刊日 的「主來源」。
			if ( ! empty( $wiki['infobox'] ) ) {
				$ib = $wiki['infobox'];

				// 各地區出版社(日/台/港/陸)
				$pub_map = [
					'publisher_jp' => 'manga_jp_publishers',
					'publisher_tw' => 'manga_tw_publisher',
					'publisher_hk' => 'manga_hk_publisher',
					'publisher_cn' => 'manga_cn_publisher',
				];
				foreach ( $pub_map as $src => $dst ) {
					if ( ! empty( $ib[ $src ] ) ) {
						$data[ $dst ] = mb_substr( $ib[ $src ], 0, 200 );
					}
				}

				// 連載雜誌
				if ( ! empty( $ib['magazine'] ) ) {
					$data['manga_magazine'] = mb_substr( $ib['magazine'], 0, 200 );
				}

				// 地區卷數:港/台/陸,直接由 infobox 帶。
				foreach ( [ 'volumes_tw', 'volumes_hk', 'volumes_cn' ] as $k ) {
					if ( ! empty( $ib[ $k ] ) ) {
						$data[ 'manga_' . $k ] = $ib[ $k ];
					}
				}

				// 日版卷數:infobox 冊數為權威值,優先;出版表列數僅在 infobox 缺時補。
				if ( ! empty( $ib['volumes_jp'] ) ) {
					$data['manga_volumes_jp'] = (int) $ib['volumes_jp'];
				} elseif ( $table_vol_count > 0 ) {
					$data['manga_volumes_jp'] = $table_vol_count;
				}

				// 首刊日(v1.2.1):infobox 開始日(連載開始日)為主來源,直接覆蓋 Wikidata。
				if ( ! empty( $ib['start_date'] ) ) {
					$data['manga_first_publish_date'] = $ib['start_date'];
				}
			}
		}

		// ---- 3. Bangumi 分卷封面(單行本封面牆用,v1.3.0)----
		$bangumi_id = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
		if ( $bangumi_id > 0 && class_exists( 'Anime_Sync_BGM_Volume_Covers' ) ) {
			$bgm_covers = new Anime_Sync_BGM_Volume_Covers();
			$covers     = $bgm_covers->fetch( $bangumi_id, $post_id );
			if ( ! empty( $covers ) ) {
				$data['manga_volume_covers'] = wp_json_encode(
					$covers,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);
			}
		}

		if ( empty( $data ) ) {
			update_post_meta( $post_id, 'manga_wiki_last_sync', current_time( 'mysql' ) );
			update_post_meta( $post_id, 'manga_wiki_last_status', $source . ':no_data' );
			return;
		}

		// ---- 4. 寫入(尊重鎖定欄位)----
		$locked = get_post_meta( $post_id, 'anime_locked_fields', true );
		if ( ! is_array( $locked ) ) $locked = [];

		/*
		 * ★ 中文維基的條目簡繁混雜，出版社／雜誌這類文字欄位直接寫入會在
		 *   前台出現簡體（實例：post 3753 的日本出版社顯示「华文天下（出品）
		 *   国文出版社（出版）」）。本檔第 124 行的標題已有轉換，寫入迴圈
		 *   卻沒有，這裡補上。
		 *
		 *   只轉純文字欄位；日期、卷數、網址、狀態等不可經過轉換。
		 */
		$convertible = [
			'manga_jp_publishers',
			'manga_tw_publisher',
			'manga_hk_publisher',
			'manga_cn_publisher',
			'manga_magazine',
		];

		foreach ( $data as $key => $val ) {
			if ( in_array( $key, $locked, true ) ) continue;
			if ( $val === '' || $val === null ) continue;

			if ( is_string( $val )
				&& in_array( $key, $convertible, true )
				&& class_exists( 'Anime_Sync_CN_Converter' ) ) {
				$val = Anime_Sync_CN_Converter::static_convert( $val );
			}

			update_post_meta( $post_id, $key, $val );
		}

		/*
		 * 出版社分類法要在這裡指派，不能放在匯入流程裡。
		 *
		 * manga_jp_publishers 是本檔（Wikidata P123）寫入的，AniList 匯入
		 * 當下根本還沒有這個值;若只在 class-manga-import-manager.php 的
		 * save_taxonomies() 指派，永遠會拿到空字串。
		 *
		 * 放在寫入迴圈之後，讀的是剛寫進去、已經過簡繁轉換的值。
		 */
		if ( class_exists( 'Anime_Sync_Manga_Import_Manager' ) ) {
			$publisher = (string) get_post_meta( $post_id, 'manga_jp_publishers', true );
			$name      = Anime_Sync_Manga_Import_Manager::normalize_publisher( $publisher );

			if ( '' !== $name && taxonomy_exists( 'manga_publisher_tax' ) ) {
				$term = term_exists( $name, 'manga_publisher_tax' );

				if ( ! $term ) {
					$term = wp_insert_term( $name, 'manga_publisher_tax' );
				}

				if ( ! is_wp_error( $term ) ) {
					$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;

					if ( $term_id > 0 ) {
						wp_set_post_terms( $post_id, [ $term_id ], 'manga_publisher_tax' );
					}
				}
			}
		}

		update_post_meta( $post_id, 'manga_wiki_last_sync', current_time( 'mysql' ) );
		update_post_meta( $post_id, 'manga_wiki_last_status', $source . ':ok' );
	}
}
