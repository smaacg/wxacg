<?php
/**
 * Entity Migrator — 把 anime_cast_json / anime_staff_json 攤平進
 * wp_anime_characters / wp_anime_persons / wp_anime_relations 三張表。
 *
 * 用法(SSH):
 *   wp anime migrate-entities              # 全部作品
 *   wp anime migrate-entities --dry-run    # 只統計不寫入
 *   wp anime migrate-entities --post=2517  # 只處理單一作品(測試用)
 *
 * 特性:冪等。用 bgm_id 去重、relations 用 unique key,重跑不會產生重複。
 * 譯名策略:非空中文優先,後寫入者若非空則覆蓋(讓整理過的譯名勝出)。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Entity_Migrator {

	private $t_char;
	private $t_person;
	private $t_rel;

	public function __construct() {
		global $wpdb;
		$this->t_char   = $wpdb->prefix . 'anime_characters';
		$this->t_person = $wpdb->prefix . 'anime_persons';
		$this->t_rel    = $wpdb->prefix . 'anime_relations';
	}

	/**
	 * 主要遷移入口
	 *
	 * @param array $args  ['dry_run' => bool, 'post_id' => int(0=全部)]
	 * @return array 統計
	 */
	public function run( array $args = [] ): array {
		$dry_run = ! empty( $args['dry_run'] );
		$post_id = (int) ( $args['post_id'] ?? 0 );

		$stats = [
			'anime'      => 0,
			'characters' => 0,
			'persons'    => 0,
			'relations'  => 0,
			'skipped'    => 0,
		];

		if ( $post_id > 0 ) {
			$ids = [ $post_id ];
		} else {
			$ids = get_posts( [
				'post_type'      => 'anime',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
		}

		foreach ( $ids as $aid ) {
			$aid = (int) $aid;
			$this->migrate_one( $aid, $dry_run, $stats );
			$stats['anime']++;
		}

		return $stats;
	}

	/**
	 * 處理單一作品
	 */
	private function migrate_one( int $anime_id, bool $dry_run, array &$stats ): void {
		// ---- CAST ----
		$cast_raw = get_post_meta( $anime_id, 'anime_cast_json', true );
		$cast     = $this->decode( $cast_raw );

		foreach ( $cast as $c ) {
			$char_bgm = (int) ( $c['id'] ?? 0 );
			$char_nm  = trim( (string) ( $c['name'] ?? '' ) );
			$char_img = (string) ( $c['image'] ?? '' );
			$role     = trim( (string) ( $c['role'] ?? '' ) );

			if ( $char_bgm > 0 ) {
				if ( ! $dry_run ) {
					$this->upsert_character( $char_bgm, $char_nm, $char_img );
				}
				$stats['characters']++;
			}

			$vas = ( ! empty( $c['voice_actors'] ) && is_array( $c['voice_actors'] ) )
				? $c['voice_actors'] : [];

			if ( empty( $vas ) ) {
				// 角色沒聲優,仍寫一筆關聯(person=0),保留角色出現在此作品的紀錄
				if ( $char_bgm > 0 && ! $dry_run ) {
					$this->upsert_relation( $anime_id, $char_bgm, 0, 'cast', $role );
				}
				if ( $char_bgm > 0 ) {
					$stats['relations']++;
				}
				continue;
			}

			foreach ( $vas as $va ) {
				$p_bgm = (int) ( $va['id'] ?? 0 );
				$p_nm  = trim( (string) ( $va['name'] ?? '' ) );
				$p_img = (string) ( $va['image'] ?? '' );

				if ( $p_bgm > 0 ) {
					if ( ! $dry_run ) {
						$this->upsert_person( $p_bgm, $p_nm, $p_img, 'cv' );
					}
					$stats['persons']++;
				}

				if ( ! $dry_run ) {
					$this->upsert_relation( $anime_id, $char_bgm, $p_bgm, 'cast', $role );
				}
				$stats['relations']++;
			}
		}

		// ---- STAFF ----
		$staff_raw = get_post_meta( $anime_id, 'anime_staff_json', true );
		$staff     = $this->decode( $staff_raw );

		foreach ( $staff as $s ) {
			$p_bgm = (int) ( $s['id'] ?? 0 );
			$p_nm  = trim( (string) ( $s['name'] ?? '' ) );
			$p_img = (string) ( $s['image'] ?? '' );
			$role  = trim( (string) ( $s['role'] ?? '' ) );

			if ( $p_bgm <= 0 ) {
				$stats['skipped']++;
				continue;
			}

			if ( ! $dry_run ) {
				// staff 不覆蓋既有 type(若他已是 cv,保留 cv;新的才設 staff)
				$this->upsert_person( $p_bgm, $p_nm, $p_img, 'staff', false );
				$this->upsert_relation( $anime_id, 0, $p_bgm, 'staff', $role );
			}
			$stats['persons']++;
			$stats['relations']++;
		}
	}

	/**
	 * JSON decode 容錯
	 */
	private function decode( $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * upsert 角色:bgm_id 存在則更新(非空譯名/圖才覆蓋),否則新增
	 */
	private function upsert_character( int $bgm_id, string $name, string $image ): void {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$this->t_char} WHERE bgm_id = %d", $bgm_id )
		);

		if ( $exists ) {
			$set = [];
			if ( $name !== '' )  { $set['name'] = $name; }
			if ( $image !== '' ) { $set['image'] = $image; }
			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_char, $set, [ 'bgm_id' => $bgm_id ] );
			}
		} else {
			$wpdb->insert( $this->t_char, [
				'bgm_id' => $bgm_id,
				'name'   => $name,
				'image'  => $image,
			] );
		}
	}

	/**
	 * upsert 人物
	 *
	 * @param bool $set_type 是否允許設定/覆蓋 type(staff 階段傳 false,不搶 cv)
	 */
	private function upsert_person( int $bgm_id, string $name, string $image, string $type, bool $set_type = true ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, type FROM {$this->t_person} WHERE bgm_id = %d", $bgm_id ),
			ARRAY_A
		);

		if ( $row ) {
			$set = [];
			if ( $name !== '' )  { $set['name'] = $name; }
			if ( $image !== '' ) { $set['image'] = $image; }
			// type 只在允許、且現有不是 cv 時才更新(cv 優先保留)
			if ( $set_type && ( $row['type'] ?? '' ) !== 'cv' ) {
				$set['type'] = $type;
			}
			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_person, $set, [ 'bgm_id' => $bgm_id ] );
			}
		} else {
			$wpdb->insert( $this->t_person, [
				'bgm_id' => $bgm_id,
				'name'   => $name,
				'image'  => $image,
				'type'   => $type,
			] );
		}
	}

	/**
	 * upsert 關聯:靠 unique key(anime, char, person, role)去重
	 */
	private function upsert_relation( int $anime_id, int $char_bgm, int $person_bgm, string $rel_type, string $role ): void {
		global $wpdb;

		// role 上限 50,截斷保險
		$role = mb_substr( $role, 0, 50 );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->t_rel}
			 WHERE anime_id = %d AND character_bgm_id = %d AND person_bgm_id = %d AND role = %s",
			$anime_id, $char_bgm, $person_bgm, $role
		) );

		if ( $exists ) {
			return; // 已存在,冪等跳過
		}

		$wpdb->insert( $this->t_rel, [
			'anime_id'         => $anime_id,
			'character_bgm_id' => $char_bgm,
			'person_bgm_id'    => $person_bgm,
			'rel_type'         => $rel_type,
			'role'             => $role,
		] );
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime migrate-entities', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$post_id = isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0;

		$migrator = new Anime_Sync_Entity_Migrator();

		WP_CLI::log( $dry_run ? '=== DRY RUN(不寫入)===' : '=== 開始遷移 ===' );

		$stats = $migrator->run( [
			'dry_run' => $dry_run,
			'post_id' => $post_id,
		] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '處理作品數  : ' . $stats['anime'] );
		WP_CLI::log( '角色筆數    : ' . $stats['characters'] );
		WP_CLI::log( '人物筆數    : ' . $stats['persons'] );
		WP_CLI::log( '關聯筆數    : ' . $stats['relations'] );
		WP_CLI::log( '略過(無id) : ' . $stats['skipped'] );
		WP_CLI::success( $dry_run ? 'Dry run 完成(未寫入)' : '遷移完成' );
	} );
}
