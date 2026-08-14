<?php
/**
 * Entity Migrator — 把 anime_cast_json / anime_staff_json 攤平進
 * wp_anime_characters / wp_anime_persons / wp_anime_relations 三張表。
 *
 * 用法(SSH):
 *   wp anime migrate-entities
 *   wp anime backfill-characters [--force] [--id=N] [--limit=N]
 *   wp anime backfill-persons    [--force] [--id=N] [--limit=N]
 *
 * @changelog
 *   1.8.1 (2026-07-30) — infobox 的 key(label)也做簡轉繁:
 *     修正 fetch_bgm_character_detail() / fetch_bgm_person_detail() 中
 *     $infobox_all 的 key 未經 $convert() 導致「事务所/唱片公司/出道
 *     时间/个人网站/性别」等 label 顯示簡體的問題。需 --force 重抓覆蓋。
 *   1.8.0 (2026-07-30) — 人物(聲優/製作)詳細欄位支援:
 *     新增 fetch_bgm_person_detail()、擴充 upsert_person() 抓 detail、
 *     新增 backfill_persons() 與 `wp anime backfill-persons` 指令。
 *     寫入 name_original / gender / birthday / bloodtype / height /
 *     summary / aliases_json / infobox_json。
 *     ⚠ 需先執行:
 *       ALTER TABLE wp_anime_persons ADD COLUMN height VARCHAR(20) NULL DEFAULT NULL AFTER bloodtype;
 *       ALTER TABLE wp_anime_persons ADD COLUMN aliases_json TEXT NULL DEFAULT NULL AFTER summary;
 *       ALTER TABLE wp_anime_persons ADD COLUMN infobox_json TEXT NULL DEFAULT NULL AFTER aliases_json;
 *   1.7.1 (2026-07-30) — backfill 新增 --limit 參數。
 *   1.7.0 (2026-07-29) — 角色身高/體重 + 完整 infobox 保存。
 *   1.6.0 ~ 1.0.0 — 早期版本。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Entity_Migrator {

	const BGM_CHARACTER_URL = 'https://api.bgm.tv/v0/characters/';
	const BGM_PERSON_URL    = 'https://api.bgm.tv/v0/persons/';
	const USER_AGENT        = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)';

	private $t_char;
	private $t_person;
	private $t_rel;

	private ?Anime_Sync_Rate_Limiter $rate_limiter;

	public function __construct( ?Anime_Sync_Rate_Limiter $rate_limiter = null ) {
		global $wpdb;
		$this->t_char   = $wpdb->prefix . 'anime_characters';
		$this->t_person = $wpdb->prefix . 'anime_persons';
		$this->t_rel    = $wpdb->prefix . 'anime_relations';

		$this->rate_limiter = $rate_limiter
			?? ( class_exists( 'Anime_Sync_Rate_Limiter' ) ? Anime_Sync_Rate_Limiter::get_instance() : null );
	}

	public function run( array $args = [] ): array {
		$dry_run  = ! empty( $args['dry_run'] );
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$deadline = isset( $args['deadline'] ) ? (int) $args['deadline'] : 0;

		$stats = [
			'anime'      => 0,
			'characters' => 0,
			'persons'    => 0,
			'relations'  => 0,
			'skipped'    => 0,
			'deferred'   => false,
		];

		if ( $post_id > 0 ) {
			$ids = [ $post_id ];
		} else {
			// 漫畫 STAFF（anime_staff_json，Bangumi 優先／AniList 備援）也要
			// 攤平進 wp_anime_relations，人物頁「其他作品」才看得到漫畫credit。
			$ids = get_posts( [
				'post_type'      => [ 'anime', 'manga' ],
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
		}

		foreach ( $ids as $aid ) {
			$aid = (int) $aid;

			$finished = $this->migrate_one( $aid, $dry_run, $stats, $deadline );
			$stats['anime']++;

			if ( ! $finished && $deadline > 0 ) {
				$stats['deferred'] = true;

				if ( defined( 'DOING_CRON' ) && DOING_CRON
					&& ! wp_next_scheduled( 'anime_sync_migrate_entities', [ $aid ] )
				) {
					wp_schedule_single_event( time() + 20, 'anime_sync_migrate_entities', [ $aid ] );
				}

				break;
			}
		}

		return $stats;
	}

	private function migrate_one( int $anime_id, bool $dry_run, array &$stats, int $deadline = 0 ): bool {
		// ---- CAST ----
		$cast_raw = get_post_meta( $anime_id, 'anime_cast_json', true );
		$cast     = $this->decode( $cast_raw );

		foreach ( $cast as $c ) {

			if ( $deadline > 0 && time() >= $deadline ) {
				return false;
			}

			$char_is_bgm = ( ( $c['source'] ?? '' ) === 'bangumi' );

			$char_bgm = $char_is_bgm ? (int) ( $c['id'] ?? 0 ) : 0;
			$char_nm  = trim( (string) ( $c['name'] ?? '' ) );
			$char_img = (string) ( $c['image'] ?? '' );
			$role     = trim( (string) ( $c['role'] ?? '' ) );

			if ( $char_bgm > 0 ) {
				if ( ! $dry_run ) {
					$this->upsert_character( $char_bgm, $char_nm, $char_img );
				}
				$stats['characters']++;
			} elseif ( ! $char_is_bgm ) {
				$stats['skipped']++;
			}

			$vas = ( ! empty( $c['voice_actors'] ) && is_array( $c['voice_actors'] ) )
				? $c['voice_actors'] : [];

			if ( empty( $vas ) ) {
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
				$this->upsert_person( $p_bgm, $p_nm, $p_img, 'staff', false );
				$this->upsert_relation( $anime_id, 0, $p_bgm, 'staff', $role );
			}
			$stats['persons']++;
			$stats['relations']++;
		}

		return true;
	}

	private function decode( $raw ): array {
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * upsert 角色。
	 */
	private function upsert_character( int $bgm_id, string $name, string $image ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, summary, gender, birthday, bloodtype, name_original, name_cn,
				        aliases_json, height, weight, infobox_json
				 FROM {$this->t_char} WHERE bgm_id = %d",
				$bgm_id
			),
			ARRAY_A
		);

		$needs_detail = ! $row
			|| ( trim( (string) ( $row['summary']      ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['aliases_json'] ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['name_cn']      ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['height']       ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['weight']       ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['infobox_json'] ?? '' ) ) === '' );
		$detail       = $needs_detail ? $this->fetch_bgm_character_detail( $bgm_id ) : [];

		$aliases_json = ( ! empty( $detail['aliases'] ) )
			? wp_json_encode( $detail['aliases'], JSON_UNESCAPED_UNICODE )
			: '';

		$infobox_json = ( ! empty( $detail['infobox'] ) )
			? wp_json_encode( $detail['infobox'], JSON_UNESCAPED_UNICODE )
			: '';

		if ( $row ) {
			$set = [];
			if ( $name !== '' )  { $set['name']  = $name; }
			if ( $image !== '' ) { $set['image'] = $image; }

			if ( ! empty( $detail ) ) {
				if ( trim( (string) ( $row['name_original'] ?? '' ) ) === '' && $detail['name_original'] !== '' ) {
					$set['name_original'] = $detail['name_original'];
				}
				if ( trim( (string) ( $row['name_cn'] ?? '' ) ) === '' && ( $detail['name_cn'] ?? '' ) !== '' ) {
					$set['name_cn'] = $detail['name_cn'];
				}
				if ( trim( (string) ( $row['gender'] ?? '' ) ) === '' && $detail['gender'] !== '' ) {
					$set['gender'] = $detail['gender'];
				}
				if ( trim( (string) ( $row['birthday'] ?? '' ) ) === '' && $detail['birthday'] !== '' ) {
					$set['birthday'] = $detail['birthday'];
				}
				if ( trim( (string) ( $row['bloodtype'] ?? '' ) ) === '' && $detail['bloodtype'] !== '' ) {
					$set['bloodtype'] = $detail['bloodtype'];
				}
				if ( trim( (string) ( $row['height'] ?? '' ) ) === '' && ( $detail['height'] ?? '' ) !== '' ) {
					$set['height'] = $detail['height'];
				}
				if ( trim( (string) ( $row['weight'] ?? '' ) ) === '' && ( $detail['weight'] ?? '' ) !== '' ) {
					$set['weight'] = $detail['weight'];
				}
				if ( trim( (string) ( $row['summary'] ?? '' ) ) === '' && $detail['summary'] !== '' ) {
					$set['summary'] = $detail['summary'];
				}
				if ( trim( (string) ( $row['aliases_json'] ?? '' ) ) === '' && $aliases_json !== '' ) {
					$set['aliases_json'] = $aliases_json;
				}
				if ( trim( (string) ( $row['infobox_json'] ?? '' ) ) === '' && $infobox_json !== '' ) {
					$set['infobox_json'] = $infobox_json;
				}
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_char, $set, [ 'bgm_id' => $bgm_id ] );
			}
		} else {
			$wpdb->insert( $this->t_char, [
				'bgm_id'        => $bgm_id,
				'name'          => $name,
				'name_cn'       => $detail['name_cn']       ?? '',
				'image'         => $image,
				'name_original' => $detail['name_original'] ?? '',
				'aliases_json'  => $aliases_json,
				'gender'        => $detail['gender']        ?? '',
				'birthday'      => $detail['birthday']      ?? '',
				'bloodtype'     => $detail['bloodtype']     ?? '',
				'height'        => $detail['height']        ?? '',
				'weight'        => $detail['weight']        ?? '',
				'summary'       => $detail['summary']       ?? '',
				'infobox_json'  => $infobox_json,
			] );
		}
	}

	/**
	 * 打 Bangumi /v0/characters/{id},解析角色詳細欄位。
	 */
	private function fetch_bgm_character_detail( int $bgm_id ): array {
		$empty = [
			'name_original' => '',
			'name_cn'       => '',
			'gender'        => '',
			'birthday'      => '',
			'bloodtype'     => '',
			'height'        => '',
			'weight'        => '',
			'summary'       => '',
			'aliases'       => [],
			'infobox'       => [],
		];

		if ( $bgm_id <= 0 ) {
			return $empty;
		}

		if ( $this->rate_limiter ) {
			$this->rate_limiter->wait_if_needed( 'bangumi' );
		}

		$response = wp_remote_get( self::BGM_CHARACTER_URL . $bgm_id, [
			'timeout' => 10,
			'headers' => [ 'User-Agent' => self::USER_AGENT ],
		] );

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $empty;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$has_opencc = class_exists( 'Anime_Sync_CN_Converter' );
		$convert    = static function ( string $s ) use ( $has_opencc ): string {
			$s = trim( $s );
			if ( $s === '' ) {
				return '';
			}
			return $has_opencc ? Anime_Sync_CN_Converter::static_convert( $s ) : $s;
		};

		$name_cn_top = trim( (string) ( $data['name'] ?? '' ) );
		$summary     = $convert( (string) ( $data['summary'] ?? '' ) );

		$gender_raw = strtolower( trim( (string) ( $data['gender'] ?? '' ) ) );
		$gender     = match ( $gender_raw ) {
			'male'   => '男',
			'female' => '女',
			''       => '',
			default  => (string) ( $data['gender'] ?? '' ),
		};

		$name_ja        = '';
		$name_cn_ibox   = '';
		$birthday       = '';
		$bloodtype      = '';
		$height         = '';
		$weight         = '';
		$aliases        = [];
		$infobox_all    = [];

		$infobox_skip = [
			'简体中文名', '簡體中文名', '别名', '別名',
			'引用来源', '引用來源', '来源', '來源',
		];

		foreach ( (array) ( $data['infobox'] ?? [] ) as $rowbox ) {
			$key   = trim( (string) ( $rowbox['key'] ?? '' ) );
			$value = $rowbox['value'] ?? '';

			if ( $key === '别名' || $key === '別名' ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $alias ) {
						$k = $convert( (string) ( $alias['k'] ?? '' ) );
						$v = $convert( (string) ( $alias['v'] ?? '' ) );
						if ( $v === '' ) {
							continue;
						}
						if ( $k === '' ) {
							$k = '別名' . ( count( $aliases ) + 1 );
						}
						$aliases[ $k ] = $v;
						if ( ( $k === '日文名' || $k === '日文名稱' ) && $name_ja === '' ) {
							$name_ja = $v;
						}
					}
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$parts = [];
				foreach ( $value as $v ) {
					if ( isset( $v['v'] ) && $v['v'] !== '' ) {
						$parts[] = $v['v'];
					}
				}
				$value = implode( '、', $parts );
			}
			$value = trim( (string) $value );
			if ( $value === '' ) {
				continue;
			}

			switch ( $key ) {
				case '简体中文名':
				case '簡體中文名':
					if ( $name_cn_ibox === '' ) {
						$name_cn_ibox = $value;
					}
					break;
				case '生日':
					$birthday = $value;
					break;
				case '血型':
					$bloodtype = $value;
					break;
				case '身高':
				case '身長':
					if ( $height === '' ) {
						$height = $value;
					}
					break;
				case '体重':
				case '體重':
					if ( $weight === '' ) {
						$weight = $value;
					}
					break;
				case '性别':
				case '性別':
					if ( $gender === '' ) {
						$gender = $value;
					}
					break;
			}

			if ( ! in_array( $key, $infobox_skip, true ) ) {
				$infobox_all[ $convert( $key ) ] = $convert( $value );
			}
		}

		$name_cn       = $name_cn_top !== '' ? $name_cn_top : $name_cn_ibox;
		$name_original = $name_ja !== '' ? $name_ja : $convert( $name_cn_ibox !== '' ? $name_cn_ibox : $name_cn_top );

		return [
			'name_original' => $name_original,
			'name_cn'       => $name_cn,
			'gender'        => $gender,
			'birthday'      => $birthday,
			'bloodtype'     => $bloodtype,
			'height'        => $height,
			'weight'        => $weight,
			'summary'       => $summary,
			'aliases'       => $aliases,
			'infobox'       => $infobox_all,
		];
	}

	/**
	 * 批次回填角色。
	 */
	public function backfill_characters( array $args = [] ): array {
		global $wpdb;

		$force  = ! empty( $args['force'] );
		$one_id = (int) ( $args['bgm_id'] ?? 0 );

		$stats = [ 'total' => 0, 'updated' => 0, 'no_change' => 0, 'failed' => 0 ];

		if ( $one_id > 0 ) {
			$ids = [ $one_id ];
		} elseif ( $force ) {
			$ids = $wpdb->get_col( "SELECT bgm_id FROM {$this->t_char} WHERE bgm_id > 0" );
		} else {
			$ids = $wpdb->get_col(
				"SELECT bgm_id FROM {$this->t_char}
				 WHERE bgm_id > 0
				 AND ( summary IS NULL OR summary = ''
				    OR aliases_json IS NULL OR aliases_json = ''
				    OR name_cn IS NULL OR name_cn = ''
				    OR height IS NULL OR height = ''
				    OR weight IS NULL OR weight = ''
				    OR infobox_json IS NULL OR infobox_json = '' )"
			);
		}

		$limit = (int) ( $args['limit'] ?? 0 );
		if ( $limit > 0 && count( $ids ) > $limit ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		foreach ( $ids as $bgm_id ) {
			$bgm_id = (int) $bgm_id;
			$stats['total']++;

			$detail = $this->fetch_bgm_character_detail( $bgm_id );

			$aliases     = $detail['aliases'] ?? [];
			$infobox     = $detail['infobox'] ?? [];
			$scalar_part = $detail;
			unset( $scalar_part['aliases'], $scalar_part['infobox'] );

			if ( empty( array_filter( $scalar_part ) ) && empty( $aliases ) && empty( $infobox ) ) {
				$stats['failed']++;
				sleep( 1 );
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT summary, gender, birthday, bloodtype, name_original, name_cn,
					        aliases_json, height, weight, infobox_json
					 FROM {$this->t_char} WHERE bgm_id = %d",
					$bgm_id
				),
				ARRAY_A
			);

			$set = [];
			foreach ( [ 'name_original', 'name_cn', 'gender', 'birthday', 'bloodtype', 'height', 'weight', 'summary' ] as $field ) {
				$current = trim( (string) ( $row[ $field ] ?? '' ) );
				$new     = (string) ( $detail[ $field ] ?? '' );
				if ( $new !== '' && ( $force || $current === '' ) ) {
					$set[ $field ] = $new;
				}
			}

			$cur_aliases = trim( (string) ( $row['aliases_json'] ?? '' ) );
			if ( ! empty( $aliases ) && ( $force || $cur_aliases === '' ) ) {
				$set['aliases_json'] = wp_json_encode( $aliases, JSON_UNESCAPED_UNICODE );
			}

			$cur_infobox = trim( (string) ( $row['infobox_json'] ?? '' ) );
			if ( ! empty( $infobox ) && ( $force || $cur_infobox === '' ) ) {
				$set['infobox_json'] = wp_json_encode( $infobox, JSON_UNESCAPED_UNICODE );
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_char, $set, [ 'bgm_id' => $bgm_id ] );
				$stats['updated']++;
			} else {
				$stats['no_change']++;
			}

			sleep( 1 );
		}

		return $stats;
	}

	/**
	 * upsert 人物(聲優 / 製作)。
	 * v1.8.0 — 打 detail 補 name_original / gender / birthday / bloodtype /
	 *          height / summary / aliases_json / infobox_json。
	 */
	private function upsert_person( int $bgm_id, string $name, string $image, string $type, bool $set_type = true ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, type, name_original, gender, birthday, bloodtype,
				        height, summary, aliases_json, infobox_json
				 FROM {$this->t_person} WHERE bgm_id = %d",
				$bgm_id
			),
			ARRAY_A
		);

		$needs_detail = ! $row
			|| ( trim( (string) ( $row['summary']       ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['aliases_json']  ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['name_original'] ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['height']        ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['infobox_json']  ?? '' ) ) === '' );
		$detail = $needs_detail ? $this->fetch_bgm_person_detail( $bgm_id ) : [];

		$aliases_json = ( ! empty( $detail['aliases'] ) )
			? wp_json_encode( $detail['aliases'], JSON_UNESCAPED_UNICODE )
			: '';
		$infobox_json = ( ! empty( $detail['infobox'] ) )
			? wp_json_encode( $detail['infobox'], JSON_UNESCAPED_UNICODE )
			: '';

		if ( $row ) {
			$set = [];
			if ( $name !== '' )  { $set['name']  = $name; }
			if ( $image !== '' ) { $set['image'] = $image; }
			if ( $set_type && ( $row['type'] ?? '' ) !== 'cv' ) {
				$set['type'] = $type;
			}

			if ( ! empty( $detail ) ) {
				if ( trim( (string) ( $row['name_original'] ?? '' ) ) === '' && ( $detail['name_original'] ?? '' ) !== '' ) {
					$set['name_original'] = $detail['name_original'];
				}
				if ( trim( (string) ( $row['gender'] ?? '' ) ) === '' && ( $detail['gender'] ?? '' ) !== '' ) {
					$set['gender'] = $detail['gender'];
				}
				if ( trim( (string) ( $row['birthday'] ?? '' ) ) === '' && ( $detail['birthday'] ?? '' ) !== '' ) {
					$set['birthday'] = $detail['birthday'];
				}
				if ( trim( (string) ( $row['bloodtype'] ?? '' ) ) === '' && ( $detail['bloodtype'] ?? '' ) !== '' ) {
					$set['bloodtype'] = $detail['bloodtype'];
				}
				if ( trim( (string) ( $row['height'] ?? '' ) ) === '' && ( $detail['height'] ?? '' ) !== '' ) {
					$set['height'] = $detail['height'];
				}
				if ( trim( (string) ( $row['summary'] ?? '' ) ) === '' && ( $detail['summary'] ?? '' ) !== '' ) {
					$set['summary'] = $detail['summary'];
				}
				if ( trim( (string) ( $row['aliases_json'] ?? '' ) ) === '' && $aliases_json !== '' ) {
					$set['aliases_json'] = $aliases_json;
				}
				if ( trim( (string) ( $row['infobox_json'] ?? '' ) ) === '' && $infobox_json !== '' ) {
					$set['infobox_json'] = $infobox_json;
				}
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_person, $set, [ 'bgm_id' => $bgm_id ] );
			}
		} else {
			$wpdb->insert( $this->t_person, [
				'bgm_id'        => $bgm_id,
				'name'          => $name,
				'image'         => $image,
				'type'          => $type,
				'name_original' => $detail['name_original'] ?? '',
				'gender'        => $detail['gender']        ?? '',
				'birthday'      => $detail['birthday']      ?? '',
				'bloodtype'     => $detail['bloodtype']     ?? '',
				'height'        => $detail['height']        ?? '',
				'summary'       => $detail['summary']       ?? '',
				'aliases_json'  => $aliases_json,
				'infobox_json'  => $infobox_json,
			] );
		}
	}

	/**
	 * 打 Bangumi /v0/persons/{id},解析人物詳細欄位。
	 * 結構與 characters 幾乎相同,沿用同套 infobox 解析。
	 */
	private function fetch_bgm_person_detail( int $bgm_id ): array {
		$empty = [
			'name_original' => '',
			'gender'        => '',
			'birthday'      => '',
			'bloodtype'     => '',
			'height'        => '',
			'summary'       => '',
			'aliases'       => [],
			'infobox'       => [],
		];

		if ( $bgm_id <= 0 ) {
			return $empty;
		}

		if ( $this->rate_limiter ) {
			$this->rate_limiter->wait_if_needed( 'bangumi' );
		}

		$response = wp_remote_get( self::BGM_PERSON_URL . $bgm_id, [
			'timeout' => 10,
			'headers' => [ 'User-Agent' => self::USER_AGENT ],
		] );

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $empty;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$has_opencc = class_exists( 'Anime_Sync_CN_Converter' );
		$convert    = static function ( string $s ) use ( $has_opencc ): string {
			$s = trim( $s );
			if ( $s === '' ) {
				return '';
			}
			return $has_opencc ? Anime_Sync_CN_Converter::static_convert( $s ) : $s;
		};

		$summary = $convert( (string) ( $data['summary'] ?? '' ) );

		$gender_raw = strtolower( trim( (string) ( $data['gender'] ?? '' ) ) );
		$gender     = match ( $gender_raw ) {
			'male'   => '男',
			'female' => '女',
			''       => '',
			default  => (string) ( $data['gender'] ?? '' ),
		};

		$name_ja     = '';
		$birthday    = '';
		$bloodtype   = '';
		$height      = '';
		$aliases     = [];
		$infobox_all = [];

		$infobox_skip = [
			'简体中文名', '簡體中文名', '别名', '別名',
			'引用来源', '引用來源', '来源', '來源',
		];

		foreach ( (array) ( $data['infobox'] ?? [] ) as $rowbox ) {
			$key   = trim( (string) ( $rowbox['key'] ?? '' ) );
			$value = $rowbox['value'] ?? '';

			if ( $key === '别名' || $key === '別名' ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $alias ) {
						$k = $convert( (string) ( $alias['k'] ?? '' ) );
						$v = $convert( (string) ( $alias['v'] ?? '' ) );
						if ( $v === '' ) {
							continue;
						}
						if ( $k === '' ) {
							$k = '別名' . ( count( $aliases ) + 1 );
						}
						$aliases[ $k ] = $v;
						if ( ( $k === '日文名' || $k === '日文名稱' ) && $name_ja === '' ) {
							$name_ja = $v;
						}
					}
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$parts = [];
				foreach ( $value as $v ) {
					if ( isset( $v['v'] ) && $v['v'] !== '' ) {
						$parts[] = $v['v'];
					}
				}
				$value = implode( '、', $parts );
			}
			$value = trim( (string) $value );
			if ( $value === '' ) {
				continue;
			}

			switch ( $key ) {
				case '生日':
					$birthday = $value;
					break;
				case '血型':
					$bloodtype = $value;
					break;
				case '身高':
				case '身長':
					if ( $height === '' ) {
						$height = $value;
					}
					break;
				case '性别':
				case '性別':
					if ( $gender === '' ) {
						$gender = $value;
					}
					break;
			}

			if ( ! in_array( $key, $infobox_skip, true ) ) {
				$infobox_all[ $convert( $key ) ] = $convert( $value );
			}
		}

		return [
			'name_original' => $name_ja,
			'gender'        => $gender,
			'birthday'      => $birthday,
			'bloodtype'     => $bloodtype,
			'height'        => $height,
			'summary'       => $summary,
			'aliases'       => $aliases,
			'infobox'       => $infobox_all,
		];
	}

	/**
	 * 批次回填人物(聲優/製作)。
	 * v1.8.0 — 新增。與 backfill_characters 對稱。
	 */
	public function backfill_persons( array $args = [] ): array {
		global $wpdb;

		$force  = ! empty( $args['force'] );
		$one_id = (int) ( $args['bgm_id'] ?? 0 );

		$stats = [ 'total' => 0, 'updated' => 0, 'no_change' => 0, 'failed' => 0 ];

		if ( $one_id > 0 ) {
			$ids = [ $one_id ];
		} elseif ( $force ) {
			$ids = $wpdb->get_col( "SELECT bgm_id FROM {$this->t_person} WHERE bgm_id > 0" );
		} else {
			$ids = $wpdb->get_col(
				"SELECT bgm_id FROM {$this->t_person}
				 WHERE bgm_id > 0
				 AND ( summary IS NULL OR summary = ''
				    OR aliases_json IS NULL OR aliases_json = ''
				    OR name_original IS NULL OR name_original = ''
				    OR height IS NULL OR height = ''
				    OR infobox_json IS NULL OR infobox_json = '' )"
			);
		}

		$limit = (int) ( $args['limit'] ?? 0 );
		if ( $limit > 0 && count( $ids ) > $limit ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		foreach ( $ids as $bgm_id ) {
			$bgm_id = (int) $bgm_id;
			$stats['total']++;

			$detail = $this->fetch_bgm_person_detail( $bgm_id );

			$aliases     = $detail['aliases'] ?? [];
			$infobox     = $detail['infobox'] ?? [];
			$scalar_part = $detail;
			unset( $scalar_part['aliases'], $scalar_part['infobox'] );

			if ( empty( array_filter( $scalar_part ) ) && empty( $aliases ) && empty( $infobox ) ) {
				$stats['failed']++;
				sleep( 1 );
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT summary, gender, birthday, bloodtype, name_original,
					        height, aliases_json, infobox_json
					 FROM {$this->t_person} WHERE bgm_id = %d",
					$bgm_id
				),
				ARRAY_A
			);

			$set = [];
			foreach ( [ 'name_original', 'gender', 'birthday', 'bloodtype', 'height', 'summary' ] as $field ) {
				$current = trim( (string) ( $row[ $field ] ?? '' ) );
				$new     = (string) ( $detail[ $field ] ?? '' );
				if ( $new !== '' && ( $force || $current === '' ) ) {
					$set[ $field ] = $new;
				}
			}

			$cur_aliases = trim( (string) ( $row['aliases_json'] ?? '' ) );
			if ( ! empty( $aliases ) && ( $force || $cur_aliases === '' ) ) {
				$set['aliases_json'] = wp_json_encode( $aliases, JSON_UNESCAPED_UNICODE );
			}

			$cur_infobox = trim( (string) ( $row['infobox_json'] ?? '' ) );
			if ( ! empty( $infobox ) && ( $force || $cur_infobox === '' ) ) {
				$set['infobox_json'] = wp_json_encode( $infobox, JSON_UNESCAPED_UNICODE );
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_person, $set, [ 'bgm_id' => $bgm_id ] );
				$stats['updated']++;
			} else {
				$stats['no_change']++;
			}

			sleep( 1 );
		}

		return $stats;
	}

	private function upsert_relation( int $anime_id, int $char_bgm, int $person_bgm, string $rel_type, string $role ): void {
		global $wpdb;

		$role = mb_substr( $role, 0, 50 );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->t_rel}
			 WHERE anime_id = %d AND character_bgm_id = %d AND person_bgm_id = %d AND role = %s",
			$anime_id, $char_bgm, $person_bgm, $role
		) );

		if ( $exists ) {
			return;
		}

		$wpdb->insert( $this->t_rel, [
			'anime_id'         => $anime_id,
			'character_bgm_id' => $char_bgm,
			'person_bgm_id'    => $person_bgm,
			'rel_type'         => $rel_type,
			'role'             => $role,
		] );
	}

	/**
	 * 就地把 wp_anime_characters / wp_anime_persons 已存在的
	 * aliases_json / infobox_json 用 Anime_Sync_CN_Converter 重新跑一次簡轉繁。
	 * 純文字轉換，不打 Bangumi API，不動其他欄位。
	 */
	public function convert_legacy_cn( array $args = [] ): array {
		global $wpdb;

		$dry_run = ! empty( $args['dry_run'] );
		$table   = $args['table'] ?? 'all';

		$targets = [];
		if ( $table === 'all' || $table === 'characters' ) {
			$targets['characters'] = $this->t_char;
		}
		if ( $table === 'all' || $table === 'persons' ) {
			$targets['persons'] = $this->t_person;
		}

		$has_opencc = class_exists( 'Anime_Sync_CN_Converter' );
		$convert    = static function ( string $s ) use ( $has_opencc ): string {
			$s = trim( $s );
			if ( $s === '' ) {
				return '';
			}
			return $has_opencc ? Anime_Sync_CN_Converter::static_convert( $s ) : $s;
		};

		$convert_json_field = static function ( ?string $json ) use ( $convert ): ?string {
			if ( $json === null || trim( $json ) === '' ) {
				return null;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				return null;
			}
			$out = [];
			foreach ( $data as $k => $v ) {
				$out[ $convert( (string) $k ) ] = is_string( $v ) ? $convert( $v ) : $v;
			}
			$new_json = wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
			return $new_json !== $json ? $new_json : null;
		};

		$stats = [];
		foreach ( $targets as $label => $tbl ) {
			$stats[ $label ] = [ 'scanned' => 0, 'changed' => 0, 'unchanged' => 0 ];

			$rows = $wpdb->get_results( "SELECT id, aliases_json, infobox_json FROM {$tbl}", ARRAY_A );
			foreach ( $rows as $row ) {
				$stats[ $label ]['scanned']++;

				$set         = [];
				$new_aliases = $convert_json_field( $row['aliases_json'] ?? null );
				if ( $new_aliases !== null ) {
					$set['aliases_json'] = $new_aliases;
				}
				$new_infobox = $convert_json_field( $row['infobox_json'] ?? null );
				if ( $new_infobox !== null ) {
					$set['infobox_json'] = $new_infobox;
				}

				if ( empty( $set ) ) {
					$stats[ $label ]['unchanged']++;
					continue;
				}

				$stats[ $label ]['changed']++;
				if ( ! $dry_run ) {
					$wpdb->update( $tbl, $set, [ 'id' => (int) $row['id'] ] );
				}
			}
		}

		return $stats;
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
		WP_CLI::log( '略過(anilist角色/無id) : ' . $stats['skipped'] );
		WP_CLI::success( $dry_run ? 'Dry run 完成(未寫入)' : '遷移完成' );
	} );

	WP_CLI::add_command( 'anime backfill-characters', function ( $args, $assoc_args ) {
		$force  = isset( $assoc_args['force'] );
		$bgm_id = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$migrator = new Anime_Sync_Entity_Migrator();

		WP_CLI::log( '=== 回填角色詳細欄位(簡體名 / summary / 性別 / 生日 / 血型 / 身高 / 體重 / 日文名 / 別名 / infobox)===' );
		if ( $force ) {
			WP_CLI::log( '模式:--force(連已有資料的角色也重抓)' );
		}
		if ( $limit > 0 ) {
			WP_CLI::log( '本批上限:--limit=' . $limit );
		}

		$stats = $migrator->backfill_characters( [
			'force'  => $force,
			'bgm_id' => $bgm_id,
			'limit'  => $limit,
		] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '掃描角色數  : ' . $stats['total'] );
		WP_CLI::log( '已更新      : ' . $stats['updated'] );
		WP_CLI::log( '無變更      : ' . $stats['no_change'] );
		WP_CLI::log( '抓取失敗    : ' . $stats['failed'] );
		WP_CLI::success( '回填完成' );
	} );

	WP_CLI::add_command( 'anime backfill-persons', function ( $args, $assoc_args ) {
		$force  = isset( $assoc_args['force'] );
		$bgm_id = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$migrator = new Anime_Sync_Entity_Migrator();

		WP_CLI::log( '=== 回填人物詳細欄位(日文名 / summary / 性別 / 生日 / 血型 / 身高 / 別名 / infobox)===' );
		if ( $force ) {
			WP_CLI::log( '模式:--force(連已有資料的人物也重抓)' );
		}
		if ( $limit > 0 ) {
			WP_CLI::log( '本批上限:--limit=' . $limit );
		}

		$stats = $migrator->backfill_persons( [
			'force'  => $force,
			'bgm_id' => $bgm_id,
			'limit'  => $limit,
		] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '掃描人物數  : ' . $stats['total'] );
		WP_CLI::log( '已更新      : ' . $stats['updated'] );
		WP_CLI::log( '無變更      : ' . $stats['no_change'] );
		WP_CLI::log( '抓取失敗    : ' . $stats['failed'] );
		WP_CLI::success( '回填完成' );
	} );

	WP_CLI::add_command( 'anime convert-legacy-cn', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$table   = $assoc_args['table'] ?? 'all';

		if ( ! in_array( $table, [ 'all', 'characters', 'persons' ], true ) ) {
			WP_CLI::error( '--table 只能是 all / characters / persons' );
			return;
		}

		$migrator = new Anime_Sync_Entity_Migrator();

		WP_CLI::log( '=== 就地簡轉繁:重跑已存在的 aliases_json / infobox_json(不打 Bangumi API)===' );
		WP_CLI::log( $dry_run ? '模式:--dry-run(只統計，不寫入)' : '模式:正式寫入' );

		$stats = $migrator->convert_legacy_cn( [
			'dry_run' => $dry_run,
			'table'   => $table,
		] );

		foreach ( $stats as $label => $s ) {
			WP_CLI::log( '─────────────────────────────' );
			WP_CLI::log( $label . ' 掃描筆數 : ' . $s['scanned'] );
			WP_CLI::log( $label . ' 需變更   : ' . $s['changed'] );
			WP_CLI::log( $label . ' 無變化   : ' . $s['unchanged'] );
		}
		WP_CLI::success( $dry_run ? 'Dry run 完成(未寫入)' : '轉換完成' );
	} );
}
