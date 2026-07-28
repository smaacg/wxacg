<?php
/**
 * Entity Repository — 角色/聲優/製作人員 的唯讀查詢層。
 *
 * 資料來源:wp_anime_characters / wp_anime_persons / wp_anime_relations
 * 三張表(由 Entity Migrator 攤平而來)。
 *
 * 設計原則:
 *   1. 容錯:缺欄位一律回 fallback(空字串、placeholder),不回 null。
 *   2. relations 表為查詢主軸(bgm_id 一定在),不依賴實體表被填滿。
 *   3. 只回乾淨陣列,呈現交給前端/API。
 *   4. 重查詢(熱門聲優)加 transient 快取,降 DB 壓力 + 護 API。
 *
 * 前端與外部 API 共用此層。
 *
 * Changelog:
 *   1.0.1 (2026-07-28)
 *     - [修正] role 加入 clean_role() 清理半形/全形空格,排序更準、顯示乾淨。
 *   1.0.0 (2026-07-28)
 *     - [新增] 初版查詢層。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Entity_Repository {

	private $t_char;
	private $t_person;
	private $t_rel;

	/** 快取時間(秒)。作品/關聯變動不頻繁,設 6 小時。 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** 快取版本;改欄位或邏輯時 +1,一鍵讓所有舊快取失效。 */
	const CACHE_VER = 'v1';

	/** ACF meta key(已於 class-acf-fields.php 確認) */
	const META_TITLE_ZH    = 'anime_title_chinese';
	const META_TITLE_ROMAJI = 'anime_title_romaji';
	const META_TITLE_EN    = 'anime_title_english';
	const META_COVER       = 'anime_cover_image';
	const META_YEAR        = 'anime_season_year';

	/** 缺圖時的預設圖(前端可再覆蓋) */
	const PLACEHOLDER_PERSON = '';
	const PLACEHOLDER_CHAR   = '';

	public function __construct() {
		global $wpdb;
		$this->t_char   = $wpdb->prefix . 'anime_characters';
		$this->t_person = $wpdb->prefix . 'anime_persons';
		$this->t_rel    = $wpdb->prefix . 'anime_relations';
	}

	/* =====================================================================
	 * 單一實體:人物(聲優 / 製作)
	 * ===================================================================== */

	/**
	 * 取單一人物基本資料。
	 *
	 * @param int $bgm_id
	 * @return array|null  找不到回 null;找到回乾淨陣列。
	 */
	public function get_person( int $bgm_id ): ?array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT bgm_id, name, name_original, image, type, anilist_id, mal_id
				 FROM {$this->t_person} WHERE bgm_id = %d",
				$bgm_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'bgm_id'        => (int) $row['bgm_id'],
			'name'          => $this->fallback_name( $row['name'], $row['name_original'] ),
			'name_original' => (string) $row['name_original'],
			'image'         => $this->fallback_image( $row['image'], self::PLACEHOLDER_PERSON ),
			'type'          => (string) ( $row['type'] ?: 'cv' ),
			'anilist_id'    => (int) $row['anilist_id'],
			'mal_id'        => (int) $row['mal_id'],
			'url'           => $this->person_url( (int) $row['bgm_id'], $row['name'] ),
		];
	}

	/**
	 * 取某人物參與的所有作品(依角色主/配 + 年份新到舊排序)。
	 *
	 * 每部作品回傳:
	 *   anime_id, title, cover, url,
	 *   character_name, character_bgm_id, character_image, role
	 *
	 * @param int  $bgm_id
	 * @param bool $cast_only  只回配音(cast),排除 staff。預設 true。
	 * @return array
	 */
	public function get_person_works( int $bgm_id, bool $cast_only = true ): array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return [];
		}

		$cache_key = $this->cache_key( 'person_works', [ $bgm_id, (int) $cast_only ] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$where_type = $cast_only ? "AND r.rel_type = 'cast'" : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.anime_id, r.character_bgm_id, r.role, r.rel_type,
				        c.name AS char_name, c.name_original AS char_name_orig, c.image AS char_image
				 FROM {$this->t_rel} r
				 LEFT JOIN {$this->t_char} c ON c.bgm_id = r.character_bgm_id
				 WHERE r.person_bgm_id = %d {$where_type}",
				$bgm_id
			),
			ARRAY_A
		);

		$works = $this->hydrate_works(
			$rows,
			function ( $row ) {
				return [
					'character_bgm_id' => (int) $row['character_bgm_id'],
					'character_name'   => $this->fallback_name( $row['char_name'], $row['char_name_orig'] ),
					'character_image'  => $this->fallback_image( $row['char_image'], self::PLACEHOLDER_CHAR ),
					'character_url'    => $this->character_url( (int) $row['character_bgm_id'], $row['char_name'] ),
					'role'             => $this->clean_role( $row['role'] ),
					'rel_type'         => (string) $row['rel_type'],
				];
			}
		);

		set_transient( $cache_key, $works, self::CACHE_TTL );
		return $works;
	}

	/* =====================================================================
	 * 單一實體:角色
	 * ===================================================================== */

	/**
	 * 取單一角色基本資料。
	 */
	public function get_character( int $bgm_id ): ?array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT bgm_id, name, name_original, image, anilist_id, mal_id
				 FROM {$this->t_char} WHERE bgm_id = %d",
				$bgm_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'bgm_id'        => (int) $row['bgm_id'],
			'name'          => $this->fallback_name( $row['name'], $row['name_original'] ),
			'name_original' => (string) $row['name_original'],
			'image'         => $this->fallback_image( $row['image'], self::PLACEHOLDER_CHAR ),
			'anilist_id'    => (int) $row['anilist_id'],
			'mal_id'        => (int) $row['mal_id'],
			'url'           => $this->character_url( (int) $row['bgm_id'], $row['name'] ),
		];
	}

	/**
	 * 取某角色出現的所有作品 + 各作品的配音員。
	 *
	 * 每部作品回傳:
	 *   anime_id, title, cover, url, role,
	 *   voice_actors[] => { bgm_id, name, image, url }
	 */
	public function get_character_works( int $bgm_id ): array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return [];
		}

		$cache_key = $this->cache_key( 'character_works', [ $bgm_id ] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.anime_id, r.person_bgm_id, r.role,
				        p.name AS p_name, p.name_original AS p_name_orig, p.image AS p_image
				 FROM {$this->t_rel} r
				 LEFT JOIN {$this->t_person} p ON p.bgm_id = r.person_bgm_id
				 WHERE r.character_bgm_id = %d AND r.rel_type = 'cast'",
				$bgm_id
			),
			ARRAY_A
		);

		// 先依 anime_id 聚合,一部作品可能多個配音員(多語版等)
		$grouped = [];
		foreach ( $rows as $row ) {
			$aid = (int) $row['anime_id'];
			if ( ! isset( $grouped[ $aid ] ) ) {
				$grouped[ $aid ] = [
					'role'          => $this->clean_role( $row['role'] ),
					'voice_actors'  => [],
				];
			}
			$p_bgm = (int) $row['person_bgm_id'];
			if ( $p_bgm > 0 ) {
				$grouped[ $aid ]['voice_actors'][] = [
					'bgm_id' => $p_bgm,
					'name'   => $this->fallback_name( $row['p_name'], $row['p_name_orig'] ),
					'image'  => $this->fallback_image( $row['p_image'], self::PLACEHOLDER_PERSON ),
					'url'    => $this->person_url( $p_bgm, $row['p_name'] ),
				];
			}
		}

		// 攤成 works 陣列並補作品資訊 + 排序
		$pseudo_rows = [];
		foreach ( $grouped as $aid => $data ) {
			$pseudo_rows[] = [
				'anime_id'     => $aid,
				'role'         => $data['role'],
				'voice_actors' => $data['voice_actors'],
			];
		}

		$works = $this->hydrate_works(
			$pseudo_rows,
			function ( $row ) {
				return [
					'role'         => $this->clean_role( $row['role'] ),
					'voice_actors' => $row['voice_actors'],
				];
			}
		);

		set_transient( $cache_key, $works, self::CACHE_TTL );
		return $works;
	}

	/* =====================================================================
	 * 單一作品:cast / staff(給 single-anime.php 加超連結用)
	 * ===================================================================== */

	/**
	 * 取某作品的完整 cast(角色 + 配音員),依主/配排序。
	 */
	public function get_anime_cast( int $anime_id ): array {
		global $wpdb;
		if ( $anime_id <= 0 ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.character_bgm_id, r.person_bgm_id, r.role,
				        c.name AS c_name, c.name_original AS c_name_orig, c.image AS c_image,
				        p.name AS p_name, p.name_original AS p_name_orig, p.image AS p_image
				 FROM {$this->t_rel} r
				 LEFT JOIN {$this->t_char} c ON c.bgm_id = r.character_bgm_id
				 LEFT JOIN {$this->t_person} p ON p.bgm_id = r.person_bgm_id
				 WHERE r.anime_id = %d AND r.rel_type = 'cast'",
				$anime_id
			),
			ARRAY_A
		);

		// 依角色聚合
		$grouped = [];
		foreach ( $rows as $row ) {
			$cbgm = (int) $row['character_bgm_id'];
			if ( ! isset( $grouped[ $cbgm ] ) ) {
				$grouped[ $cbgm ] = [
					'character_bgm_id' => $cbgm,
					'character_name'   => $this->fallback_name( $row['c_name'], $row['c_name_orig'] ),
					'character_image'  => $this->fallback_image( $row['c_image'], self::PLACEHOLDER_CHAR ),
					'character_url'    => $this->character_url( $cbgm, $row['c_name'] ),
					'role'             => $this->clean_role( $row['role'] ),
					'voice_actors'     => [],
				];
			}
			$pbgm = (int) $row['person_bgm_id'];
			if ( $pbgm > 0 ) {
				$grouped[ $cbgm ]['voice_actors'][] = [
					'bgm_id' => $pbgm,
					'name'   => $this->fallback_name( $row['p_name'], $row['p_name_orig'] ),
					'image'  => $this->fallback_image( $row['p_image'], self::PLACEHOLDER_PERSON ),
					'url'    => $this->person_url( $pbgm, $row['p_name'] ),
				];
			}
		}

		$list = array_values( $grouped );
		usort( $list, function ( $a, $b ) {
			return $this->role_weight( $a['role'] ) <=> $this->role_weight( $b['role'] );
		} );

		return $list;
	}

	/**
	 * 取某作品的完整 staff(製作人員),同一人多職位會分開列。
	 */
	public function get_anime_staff( int $anime_id ): array {
		global $wpdb;
		if ( $anime_id <= 0 ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.person_bgm_id, r.role,
				        p.name AS p_name, p.name_original AS p_name_orig, p.image AS p_image
				 FROM {$this->t_rel} r
				 LEFT JOIN {$this->t_person} p ON p.bgm_id = r.person_bgm_id
				 WHERE r.anime_id = %d AND r.rel_type = 'staff'",
				$anime_id
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows as $row ) {
			$pbgm = (int) $row['person_bgm_id'];
			if ( $pbgm <= 0 ) {
				continue;
			}
			$out[] = [
				'bgm_id' => $pbgm,
				'name'   => $this->fallback_name( $row['p_name'], $row['p_name_orig'] ),
				'image'  => $this->fallback_image( $row['p_image'], self::PLACEHOLDER_PERSON ),
				'role'   => $this->clean_role( $row['role'] ),
				'url'    => $this->person_url( $pbgm, $row['p_name'] ),
			];
		}

		return $out;
	}

	/* =====================================================================
	 * 內部工具
	 * ===================================================================== */

	/**
	 * 把 relation rows 補上作品資訊(標題/封面/年份/permalink),
	 * 排序(主角優先 → 年份新到舊),再套用 extra 欄位。
	 *
	 * @param array    $rows       至少含 anime_id、role
	 * @param callable $extra_cb   針對每列額外欄位的產生器
	 * @return array
	 */
	private function hydrate_works( array $rows, callable $extra_cb ): array {
		if ( empty( $rows ) ) {
			return [];
		}

		// 收集不重複 anime_id,一次批量抓 meta(避免 N+1)
		$anime_ids = array_values( array_unique( array_map(
			function ( $r ) { return (int) $r['anime_id']; },
			$rows
		) ) );

		$meta_cache = $this->batch_anime_meta( $anime_ids );

		$works = [];
		foreach ( $rows as $row ) {
			$aid = (int) $row['anime_id'];
			$m   = $meta_cache[ $aid ] ?? null;

			// 作品已被刪除或非 publish → 跳過,避免死連結
			if ( null === $m ) {
				continue;
			}

			$base = [
				'anime_id' => $aid,
				'title'    => $m['title'],
				'cover'    => $m['cover'],
				'url'      => $m['url'],
				'_year'    => $m['year'],  // 排序用,回傳前移除
			];

			$works[] = array_merge( $base, (array) $extra_cb( $row ) );
		}

		// 排序:主角優先 → 年份新到舊
		usort( $works, function ( $a, $b ) {
			$rw = $this->role_weight( $a['role'] ?? '' ) <=> $this->role_weight( $b['role'] ?? '' );
			if ( 0 !== $rw ) {
				return $rw;
			}
			return ( (int) $b['_year'] ) <=> ( (int) $a['_year'] );
		} );

		// 移除排序用臨時欄位
		foreach ( $works as &$w ) {
			unset( $w['_year'] );
		}
		unset( $w );

		return $works;
	}

	/**
	 * 批量抓多部作品的 meta,回 [ anime_id => [title, cover, url, year] ]。
	 * 只回 publish 狀態的作品(其餘視為不存在)。
	 */
	private function batch_anime_meta( array $anime_ids ): array {
		if ( empty( $anime_ids ) ) {
			return [];
		}

		// 一次 get_posts 拉出有效作品,順便暖 meta 快取
		$posts = get_posts( [
			'post_type'      => 'anime',
			'post_status'    => 'publish',
			'post__in'       => $anime_ids,
			'posts_per_page' => -1,
			'orderby'        => 'post__in',
			'fields'         => 'ids',
		] );

		$out = [];
		foreach ( $posts as $pid ) {
			$pid = (int) $pid;

			$title_zh = (string) get_post_meta( $pid, self::META_TITLE_ZH, true );
			$title    = $title_zh !== ''
				? $title_zh
				: ( (string) get_post_meta( $pid, self::META_TITLE_ROMAJI, true )
					?: (string) get_post_meta( $pid, self::META_TITLE_EN, true )
					?: get_the_title( $pid ) );

			$out[ $pid ] = [
				'title' => $title,
				'cover' => (string) get_post_meta( $pid, self::META_COVER, true ),
				'url'   => (string) get_permalink( $pid ),
				'year'  => (int) get_post_meta( $pid, self::META_YEAR, true ),
			];
		}

		return $out;
	}

	/**
	 * 角色排序權重:主角 0 → 配角 1 → 客串 2 → 其他 3。
	 */
	private function role_weight( string $role ): int {
		$role = $this->clean_role( $role );
		$map  = [ '主角' => 0, '配角' => 1, '客串' => 2 ];
		return $map[ $role ] ?? 3;
	}

	/**
	 * 清理 role 髒資料:半形/全形空格、不斷行空格。
	 */
	private function clean_role( ?string $role ): string {
		$role = trim( (string) $role );
		$role = str_replace( [ "\xE3\x80\x80", "\xC2\xA0" ], '', $role ); // 全形空格、不斷行空格
		return trim( $role );
	}

	/**
	 * 譯名 fallback:中文名空 → 原文名 → 空字串。
	 */
	private function fallback_name( ?string $name, ?string $original ): string {
		$name = trim( (string) $name );
		if ( '' !== $name ) {
			return $name;
		}
		return trim( (string) $original );
	}

	/**
	 * 圖片 fallback:空 → placeholder。
	 */
	private function fallback_image( ?string $image, string $placeholder ): string {
		$image = trim( (string) $image );
		return '' !== $image ? $image : $placeholder;
	}

	/**
	 * 人物頁 URL:/person/{bgm_id}/{name}(name 為 SEO 裝飾,可選)。
	 */
	private function person_url( int $bgm_id, ?string $name = '' ): string {
		$slug = $this->url_slug( $name );
		return home_url( "/person/{$bgm_id}/" . $slug );
	}

	/**
	 * 角色頁 URL:/character/{bgm_id}/{name}。
	 */
	private function character_url( int $bgm_id, ?string $name = '' ): string {
		$slug = $this->url_slug( $name );
		return home_url( "/character/{$bgm_id}/" . $slug );
	}

	/**
	 * 把名字轉成 URL 安全片段(保留中日文,空則回空)。
	 */
	private function url_slug( ?string $name ): string {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		// 保留可讀性;空白轉 -,rawurlencode 交給 WP 輸出時處理
		$name = str_replace( ' ', '-', $name );
		return rawurlencode( $name );
	}

	/**
	 * 快取鍵:含版本號,一改邏輯就整批失效。
	 */
	private function cache_key( string $scope, array $parts ): string {
		return 'asp_ent_' . self::CACHE_VER . '_' . $scope . '_' . implode( '_', $parts );
	}
}
