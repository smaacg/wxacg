<?php
/**
 * Entity Repository — 角色/聲優/製作人員 的唯讀查詢層。
 *
 * Changelog:
 *   1.8.0 (2026-08-31)
 *     - [修正] 人物頁同一部作品出現多張重複卡片：關聯表一個職位一列，
 *              hydrate_works() 一列一筆。新增 merge_works_by_anime()
 *              依 anime_id 合併，職位與飾演角色併成 roles/characters
 *              陣列。實測正式站 4,298 組（同一人同一部掛多職位）。
 *     - [修正] clean_role() 加查表正規化，不讓簡體職位漏到畫面上
 *              （动画制作→動畫製作、导演→監督、闲角→閒角…）。
 *              純陣列查表每次 0.000034 ms，等於免費。
 *              簡繁歸一後也順帶讓「同職位簡繁兩版」的重複卡片消失。
 *     - CACHE_VER v5→v6（回傳結構多了 roles/characters）。
 *   1.7.0 (2026-07-30)
 *     - [新增] get_person() 回傳 height / aliases / infobox。比照 get_character:
 *       SQL 加讀 height / aliases_json / infobox_json;aliases 由
 *       decode_aliases_json() 解、infobox 由 decode_infobox_json() 解。
 *       (由 class-entity-migrator.php v1.8.0 寫入對應資料庫欄位。)
 *   1.6.0 (2026-07-29) — get_character() 回傳 height / weight / infobox。
 *   1.5.0 (2026-07-29) — get_character() 回傳 name_cn。
 *   1.4.1 (2026-07-29) — 角色作品次要排序改「熱度高→低」。CACHE_VER v4→v5。
 *   1.4.0 (2026-07-29) — hydrate_works() 加 $oldest_first。CACHE_VER v3→v4。
 *   1.3.0 (2026-07-28) — 角色別名改讀 aliases_json。
 *   1.2.0 (2026-07-28) — 移除 get_character_photos()。CACHE_VER v1→v2。
 *   1.1.0 (2026-07-28) — gender/birthday/bloodtype/summary/aliases、關聯。
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
	const CACHE_VER = 'v6';

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
				        gender, birthday, bloodtype, height, summary,
				        aliases_json, infobox_json
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
			'height'        => $this->fallback_text( $row['height'] ?? '' ),   // ★ [1.7.0]
			'summary'       => $this->fallback_text( $row['summary'] ?? '' ),
			'aliases'       => $this->decode_aliases_json( $row['aliases_json'] ?? '' ), // ★ [1.7.0]
			'infobox'       => $this->decode_infobox_json( $row['infobox_json'] ?? '' ), // ★ [1.7.0]
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

		$works = $this->merge_works_by_anime( $works );

		set_transient( $cache_key, $works, self::CACHE_TTL );
		return $works;
	}

	/**
	 * 同一部作品合併成一筆。
	 *
	 * 關聯表是一個職位一列，所以同一個人在同一部作品掛兩個職位就有兩列，
	 * hydrate_works() 一列一筆，人物頁就會出現兩張一模一樣的卡片。
	 * MAPPA 在《全修。》同時是「動畫製作」與「原作」就是這種情況，
	 * 實測正式站有 4,298 組（同一人同一部作品掛多個職位）。
	 *
	 * 合併之後每部作品一張卡，職位與飾演角色併成陣列。單一職位的情況
	 * roles 就只有一個元素，模板不必分兩種寫法。
	 *
	 * 注意這裡不處理「同一職位的簡繁兩版」那種真重複——那是資料問題，
	 * 已在 class-entity-migrator.php 的寫入端轉繁堵掉。這支只負責把
	 * 本來就不同的職位收攏成一張卡。
	 *
	 * @param array[] $works hydrate_works() 的輸出，已依職位權重與年份排序
	 * @return array[] 每部作品一筆，多出 roles / characters 兩個欄位
	 */
	private function merge_works_by_anime( array $works ): array {
		$merged = [];

		foreach ( $works as $w ) {
			$aid = (int) ( $w['anime_id'] ?? 0 );

			if ( ! isset( $merged[ $aid ] ) ) {
				$w['roles']      = [];
				$w['characters'] = [];
				$merged[ $aid ]  = $w;
			}

			$role = trim( (string) ( $w['role'] ?? '' ) );

			if ( '' !== $role && ! in_array( $role, $merged[ $aid ]['roles'], true ) ) {
				$merged[ $aid ]['roles'][] = $role;
			}

			$char_name = trim( (string) ( $w['character_name'] ?? '' ) );

			if ( '' !== $char_name ) {
				$char_id = (int) ( $w['character_bgm_id'] ?? 0 );
				$key     = $char_id > 0 ? 'id:' . $char_id : 'nm:' . $char_name;

				/* 同一個角色可能因為多列而重覆出現，用 key 去重 */
				$merged[ $aid ]['characters'][ $key ] = [
					'bgm_id' => $char_id,
					'name'   => $char_name,
					'url'    => (string) ( $w['character_url'] ?? '' ),
				];
			}
		}

		/* 收尾：characters 的去重 key 不需要留給模板 */
		foreach ( $merged as &$m ) {
			$m['characters'] = array_values( $m['characters'] );
		}
		unset( $m );

		return array_values( $merged );
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
				        gender, birthday, bloodtype, height, weight, summary,
				        aliases_json, infobox_json
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
			'height'        => $this->fallback_text( $row['height'] ?? '' ),
			'weight'        => $this->fallback_text( $row['weight'] ?? '' ),
			'summary'       => $this->fallback_text( $row['summary'] ?? '' ),
			'aliases'       => $this->decode_aliases_json( $row['aliases_json'] ?? '' ),
			'infobox'       => $this->decode_infobox_json( $row['infobox_json'] ?? '' ),
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
			true
		);

		set_transient( $cache_key, $works, self::CACHE_TTL );
		return $works;
	}

	public function get_character_relations( int $bgm_id ): array {
		global $wpdb;
		if ( $bgm_id <= 0 ) {
			return [];
		}

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
				continue;
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
	 * 把 infobox_json ( { "性别":"男","身高":"173cm", ... } )
	 * 解成模板要的 [ ['label'=>..,'value'=>..], ... ]。空/失敗回空陣列。
	 */
	private function decode_infobox_json( ?string $json ): array {
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return [];
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return [];
		}

		$rows = [];
		foreach ( $data as $key => $val ) {
			if ( is_array( $val ) ) {
				$val = implode( '、', array_map( 'strval', $val ) );
			}
			$value = trim( (string) $val );
			$label = is_string( $key ) ? trim( $key ) : '';
			if ( '' === $value ) {
				continue;
			}
			$rows[] = [ 'label' => $label, 'value' => $value ];
		}

		return $rows;
	}

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
			if ( $by_popularity ) {
				$pop = ( (int) $b['_popularity'] ) <=> ( (int) $a['_popularity'] );
				if ( 0 !== $pop ) {
					return $pop;
				}
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

		// 漫畫 STAFF 也會攤平進同一張關聯表，這裡要放行 manga 才能組出
		// 標題/封面/連結，否則會被下面的 null 檢查悄悄濾掉、漫畫作品永遠不顯示。
		$posts = get_posts( [
			'post_type'      => [ 'anime', 'manga' ],
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

	/**
	 * 顯示層的職位名稱正規化：不讓簡體漏到畫面上。
	 *
	 * 正本清源是在寫入端（class-entity-migrator.php 寫關聯前就轉繁）
	 * 加上既有資料的一次性修正。這裡是第三道保險——查表而已，實測每次
	 * 0.000034 ms（CN_Converter 是 0.39 ms，差一萬倍），等於免費，
	 * 卻能保證任何漏網的匯入路徑都不會把簡體端到使用者面前。
	 *
	 * CAST_ROLES 補的是 Staff_Roles::LABELS 沒收的三個：LABELS 是製作
	 * 職位對照表，「闲角」是角色定位不屬於它；「声库」「副導演」則是
	 * 冷門到沒被收進去（正式站各 5 列、1 列）。
	 */
	private const CAST_ROLES = [
		'闲角'   => '閒角',
		'声库'   => '聲庫',
		'副導演' => '副監督',
	];

	private function clean_role( ?string $role ): string {
		$role = trim( (string) $role );
		$role = str_replace( [ "\xE3\x80\x80", "\xC2\xA0" ], '', $role );
		$role = trim( $role );

		if ( '' === $role ) {
			return $role;
		}

		if ( isset( self::CAST_ROLES[ $role ] ) ) {
			return self::CAST_ROLES[ $role ];
		}

		if ( class_exists( 'Anime_Sync_Staff_Roles' ) ) {
			$labels = Anime_Sync_Staff_Roles::LABELS;

			if ( isset( $labels[ $role ] ) ) {
				return $labels[ $role ];
			}
		}

		return $role;
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
