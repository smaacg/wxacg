<?php
/**
 * Entity Repository — 角色/聲優/製作人員 的唯讀查詢層。
 *
 * 資料來源:
 *   - wp_anime_characters / wp_anime_persons / wp_anime_relations
 *   - wp_anime_character_relations（角色↔角色 關聯，自我參照一對多，若表存在）
 *
 * 別名(aliases)改為存於 wp_anime_characters.aliases_json（JSON 欄位，
 * 由 class-entity-migrator.php v1.4.0 寫入），不再使用獨立別名表。
 *
 * 相簿功能已移除：bgm.tv 官方 API 的角色/人物 detail 端點只回傳
 * 單張 images(small/grid/large/medium)，無多圖相簿資料源，
 * 故不提供 get_character_photos()。
 *
 * Changelog:
 *   1.5.0 (2026-07-29)
 *     - [新增] get_character() 回傳 name_cn（Bangumi 原始簡體主名，
 *       由 class-entity-migrator.php v1.6.0 寫入 wp_anime_characters.name_cn）。
 *       供 single-character.php 四語主名顯示（繁 name / 簡 name_cn /
 *       日 name_original / 英 別名「英文名」）。get_character() 不走 transient，
 *       故 CACHE_VER 不變（v5）。
 *   1.4.1 (2026-07-29)
 *     - [修正] 角色作品次要排序由「年份舊→新」改為「熱度高→低」（anime_popularity），
 *       解決前傳和 OAD 暴日期比本篇更舊導致错排第一的問題。
 *     - CACHE_VER v4 → v5。
 *   1.4.0 (2026-07-29)
 *     - [修正] hydrate_works() 加入 $oldest_first 參數：
 *       get_character_works() 傳 true（舊→新），本篇/原作排最前，
 *       麵包屑取 $works[0] 即為正確的原始作品。
 *       get_person_works() 維持預設 false（新→舊）。
 *     - CACHE_VER v3 → v4（排序方向變更，舊快取需整批失效）。
 *   1.3.0 (2026-07-28)
 *     - [改版] get_character() 的別名改讀 wp_anime_characters.aliases_json
 *       (JSON 欄位)，新增 decode_aliases_json() 解碼成模板要的
 *       [ ['label'=>..,'value'=>..], ... ] 格式。獨立別名表
 *       wp_anime_character_aliases 實際未建置，故 character 不再查它。
 *       (get_person 暫維持原 get_entity_aliases 呼叫，person 別名尚未導入。)
 *   1.2.0 (2026-07-28)
 *     - [移除] get_character_photos()、$t_char_photo：相簿功能無穩定
 *       資料源，移除以簡化維護範圍。
 *     - CACHE_VER 由 v1 → v2（結構調整，舊快取需整批失效）。
 *   1.1.0 (2026-07-28)
 *     - [新增] get_character() / get_person() 補上 gender / birthday /
 *       bloodtype / summary / aliases。
 *     - [新增] get_character_relations()：角色↔角色 關聯，LEFT JOIN
 *       角色表，對方資料不存在時自動跳過。
 *   1.0.0 — 初版查詢層。
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

	private $t_char_alias;
	private $t_person_alias;
	private $t_char_rel;

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const CACHE_VER = 'v5';

	const META_TITLE_ZH     = 'anime_title_chinese';
	const META_TITLE_ROMAJI = 'anime_title_romaji';
	const META_TITLE_EN     = 'anime_title_english';
	const META_COVER        = 'anime_cover_image';
	const META_YEAR         = 'anime_season_year';

	const PLACEHOLDER_PERSON = '';
	const PLACEHOLDER_CHAR   = '';

	public function __construct() {
		global $wpdb;
		$this->t_char   = $wpdb->prefix . 'anime_characters';
		$this->t_person = $wpdb->prefix . 'anime_persons';
		$this->t_rel    = $wpdb->prefix . 'anime_relations';

		$this->t_char_alias   = $wpdb->prefix . 'anime_character_aliases';
		$this->t_person_alias = $wpdb->prefix . 'anime_person_aliases';
		$this->t_char_rel     = $wpdb->prefix . 'anime_character_relations';
	}

	/* =====================================================================
	 * 單一實體:人物(聲優 / 製作)
	 * ===================================================================== */

	public function get_person( int $bgm_id ): ?array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT bgm_id, name, name_original, image, type, anilist_id, mal_id,
				        gender, birthday, bloodtype, summary
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
			'gender'        => $this->fallback_text( $row['gender'] ?? '' ),
			'birthday'      => $this->fallback_text( $row['birthday'] ?? '' ),
			'bloodtype'     => $this->fallback_text( $row['bloodtype'] ?? '' ),
			'summary'       => $this->fallback_text( $row['summary'] ?? '' ),
			'aliases'       => [], // person 別名尚未導入,先回空陣列(原查獨立表已停用)。
			'url'           => $this->person_url( (int) $row['bgm_id'], $row['name'] ),
		];
	}

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

	public function get_character( int $bgm_id ): ?array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT bgm_id, name, name_cn, name_original, image, anilist_id, mal_id,
				        gender, birthday, bloodtype, summary, aliases_json
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
			'name_cn'       => $this->fallback_text( $row['name_cn'] ?? '' ),
			'name_original' => (string) $row['name_original'],
			'image'         => $this->fallback_image( $row['image'], self::PLACEHOLDER_CHAR ),
			'anilist_id'    => (int) $row['anilist_id'],
			'mal_id'        => (int) $row['mal_id'],
			'gender'        => $this->fallback_text( $row['gender'] ?? '' ),
			'birthday'      => $this->fallback_text( $row['birthday'] ?? '' ),
			'bloodtype'     => $this->fallback_text( $row['bloodtype'] ?? '' ),
			'summary'       => $this->fallback_text( $row['summary'] ?? '' ),
			'aliases'       => $this->decode_aliases_json( $row['aliases_json'] ?? '' ),
			'url'           => $this->character_url( (int) $row['bgm_id'], $row['name'] ),
		];
	}

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

		$grouped = [];
		foreach ( $rows as $row ) {
			$aid = (int) $row['anime_id'];
			if ( ! isset( $grouped[ $aid ] ) ) {
				$grouped[ $aid ] = [
					'role'         => $this->clean_role( $row['role'] ),
					'voice_actors' => [],
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
			},
			true  // 角色登場作品：用熱度降序，本篇熱度高排 [0]
		);

		set_transient( $cache_key, $works, self::CACHE_TTL );
		return $works;
	}

	/**
	 * 取某角色的關聯角色(朋友/親屬/配偶/對手/師生等)。
	 * 若 wp_anime_character_relations 表不存在,回空陣列(模板整段不渲染)。
	 */
	public function get_character_relations( int $bgm_id ): array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return [];
		}

		// 表不存在時直接回空,避免 SQL 錯誤。
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->t_char_rel )
		);
		if ( $exists !== $this->t_char_rel ) {
			return [];
		}

		$cache_key = $this->cache_key( 'character_relations', [ $bgm_id ] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cr.relation_label, cr.related_character_bgm_id,
				        c.name AS c_name, c.name_original AS c_name_orig, c.image AS c_image
				 FROM {$this->t_char_rel} cr
				 LEFT JOIN {$this->t_char} c ON c.bgm_id = cr.related_character_bgm_id
				 WHERE cr.character_bgm_id = %d
				 ORDER BY cr.sort_order ASC, cr.id ASC",
				$bgm_id
			),
			ARRAY_A
		);

		$relations = [];
		foreach ( (array) $rows as $row ) {
			$rc_bgm = (int) $row['related_character_bgm_id'];
			if ( $rc_bgm <= 0 || null === $row['c_name'] ) {
				continue; // 對方角色資料不存在 → 跳過,避免死連結
			}
			$name = $this->fallback_name( $row['c_name'], $row['c_name_orig'] );
			if ( '' === $name ) {
				continue;
			}
			$relations[] = [
				'relation' => $this->clean_role( $row['relation_label'] ),
				'name'     => $name,
				'avatar'   => $this->fallback_image( $row['c_image'], self::PLACEHOLDER_CHAR ),
				'url'      => $this->character_url( $rc_bgm, $row['c_name'] ),
			];
		}

		set_transient( $cache_key, $relations, self::CACHE_TTL );
		return $relations;
	}

	/* =====================================================================
	 * 單一作品:cast / staff
	 * ===================================================================== */

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
	 * v1.3.0 — 把 wp_anime_characters.aliases_json
	 * ( { "日文名":"蝶野 雛", "纯假名":"ちょうの ひな", ... } )
	 * 解碼成模板要的 [ ['label'=>..,'value'=>..], ... ]。
	 * 空值/解碼失敗回空陣列。也容忍已是 [ {label,value} ] 格式的輸入。
	 */
	private function decode_aliases_json( ?string $json ): array {
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return [];
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return [];
		}

		$aliases = [];
		foreach ( $data as $key => $val ) {
			// 情況一:關聯陣列 "label" => "value"(migrator v1.4.0 的格式)。
			if ( is_string( $val ) || is_numeric( $val ) ) {
				$value = trim( (string) $val );
				if ( '' === $value ) {
					continue;
				}
				$aliases[] = [
					'label' => is_string( $key ) ? trim( $key ) : '',
					'value' => $value,
				];
				continue;
			}

			// 情況二:已是 [ 'label'=>.., 'value'=>.. ] 物件(相容用)。
			if ( is_array( $val ) ) {
				$value = trim( (string) ( $val['value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				$aliases[] = [
					'label' => trim( (string) ( $val['label'] ?? '' ) ),
					'value' => $value,
				];
			}
		}

		return $aliases;
	}

	/**
	 * (保留備用) 從獨立別名表查別名。目前 character 已改讀 aliases_json,
	 * 此方法暫無呼叫端;若未來導入 person 別名表可沿用。
	 */
	private function get_entity_aliases( string $table, string $fk_column, int $bgm_id ): array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT label, value FROM {$table}
				 WHERE {$fk_column} = %d
				 ORDER BY sort_order ASC, id ASC",
				$bgm_id
			),
			ARRAY_A
		);

		$aliases = [];
		foreach ( (array) $rows as $row ) {
			$value = trim( (string) ( $row['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$aliases[] = [
				'label' => trim( (string) ( $row['label'] ?? '' ) ),
				'value' => $value,
			];
		}

		return $aliases;
	}

	private function hydrate_works( array $rows, callable $extra_cb, bool $by_popularity = false ): array {
		if ( empty( $rows ) ) {
			return [];
		}

		$anime_ids = array_values( array_unique( array_map(
			function ( $r ) { return (int) $r['anime_id']; },
			$rows
		) ) );

		$meta_cache = $this->batch_anime_meta( $anime_ids );

		$works = [];
		foreach ( $rows as $row ) {
			$aid = (int) $row['anime_id'];
			$m   = $meta_cache[ $aid ] ?? null;

			if ( null === $m ) {
				continue;
			}

			$base = [
				'anime_id'    => $aid,
				'title'       => $m['title'],
				'cover'       => $m['cover'],
				'url'         => $m['url'],
				'_year'       => $m['year'],
				'_popularity' => $m['popularity'],
			];

			$works[] = array_merge( $base, (array) $extra_cb( $row ) );
		}

		usort( $works, function ( $a, $b ) use ( $by_popularity ) {
			$rw = $this->role_weight( $a['role'] ?? '' ) <=> $this->role_weight( $b['role'] ?? '' );
			if ( 0 !== $rw ) {
				return $rw;
			}
			// $by_popularity=true（角色頁）：熱度高→低。
			// 本篇熱度遠高於 OAD/前傳/番外，出來的 $works[0] 就是本篇。
			// $by_popularity=false（聲優頁）：新→舊年份，最新代表作優先。
			if ( $by_popularity ) {
				$pop = ( (int) $b['_popularity'] ) <=> ( (int) $a['_popularity'] );
				if ( 0 !== $pop ) {
					return $pop;
				}
				// 熱度相同時再用年份舊→新當次要鍵
				return ( (int) $a['_year'] ) <=> ( (int) $b['_year'] );
			}
			return ( (int) $b['_year'] ) <=> ( (int) $a['_year'] );
		} );

		foreach ( $works as &$w ) {
			unset( $w['_year'], $w['_popularity'] );
		}
		unset( $w );

		return $works;
	}

	private function batch_anime_meta( array $anime_ids ): array {
		if ( empty( $anime_ids ) ) {
			return [];
		}

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
				'title'      => $title,
				'cover'      => (string) get_post_meta( $pid, self::META_COVER, true ),
				'url'        => (string) get_permalink( $pid ),
				'year'       => (int) get_post_meta( $pid, self::META_YEAR, true ),
				'popularity' => (int) get_post_meta( $pid, 'anime_popularity', true ),
			];
		}

		return $out;
	}

	private function role_weight( string $role ): int {
		$role = $this->clean_role( $role );
		$map  = [ '主角' => 0, '配角' => 1, '客串' => 2 ];
		return $map[ $role ] ?? 3;
	}

	private function clean_role( ?string $role ): string {
		$role = trim( (string) $role );
		$role = str_replace( [ "\xE3\x80\x80", "\xC2\xA0" ], '', $role );
		return trim( $role );
	}

	private function fallback_name( ?string $name, ?string $original ): string {
		$name = trim( (string) $name );
		if ( '' !== $name ) {
			return $name;
		}
		return trim( (string) $original );
	}

	private function fallback_image( ?string $image, string $placeholder ): string {
		$image = trim( (string) $image );
		return '' !== $image ? $image : $placeholder;
	}

	private function fallback_text( ?string $value ): string {
		return trim( (string) $value );
	}

	private function person_url( int $bgm_id, ?string $name = '' ): string {
		$slug = $this->url_slug( $name );
		return home_url( "/person/{$bgm_id}/" . $slug );
	}

	private function character_url( int $bgm_id, ?string $name = '' ): string {
		$slug = $this->url_slug( $name );
		return home_url( "/character/{$bgm_id}/" . $slug );
	}

	private function url_slug( ?string $name ): string {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		$name = str_replace( ' ', '-', $name );
		return rawurlencode( $name );
	}

	private function cache_key( string $scope, array $parts ): string {
		return 'asp_ent_' . self::CACHE_VER . '_' . $scope . '_' . implode( '_', $parts );
	}
}
