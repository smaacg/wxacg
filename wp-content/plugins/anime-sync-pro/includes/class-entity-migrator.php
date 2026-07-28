<?php
/**
 * Entity Migrator — 把 anime_cast_json / anime_staff_json 攤平進
 * wp_anime_characters / wp_anime_persons / wp_anime_relations 三張表。
 *
 * 用法(SSH):
 *   wp anime migrate-entities              # 全部作品
 *   wp anime migrate-entities --dry-run    # 只統計不寫入
 *   wp anime migrate-entities --post=2517  # 只處理單一作品(測試用)
 *   wp anime backfill-characters           # 回填既有角色空白的 summary/infobox
 *   wp anime backfill-characters --force   # 連已有 summary 的角色也重抓
 *   wp anime backfill-characters --id=107704   # 只回填單一角色(測試用)
 *
 * 特性:冪等。用 bgm_id 去重、relations 用 unique key,重跑不會產生重複。
 * 譯名策略:非空中文優先,後寫入者若非空則覆蓋(讓整理過的譯名勝出)。
 *
 * @changelog
 *   1.4.0 (2026-07-28) — 別名(aliases)支援:
 *     fetch_bgm_character_detail() 新增解析 infobox 的「别名」巢狀陣列
 *     (日文名 / 纯假名 / 罗马字 等),回傳 aliases 陣列並以 JSON 存入
 *     新欄位 aliases_json。name_original 改為「優先日文名,退而簡體轉繁」,
 *     修正先前誤存簡體名的問題。upsert_character() 與 backfill_characters()
 *     同步寫入 aliases_json。
 *     ⚠ 需先執行:ALTER TABLE wp_anime_characters
 *         ADD COLUMN aliases_json TEXT NULL DEFAULT NULL AFTER name_original;
 *   1.3.0 (2026-07-28) — 角色詳細欄位回填:
 *     upsert_character() 在寫入時另打 Bangumi /v0/characters/{id},
 *     解析 summary(簡介,經 OpenCC 轉繁) 與 infobox(性別/生日/血型/
 *     日文名),補齊 gender / birthday / bloodtype / name_original /
 *     summary 五欄。僅在該角色 summary 目前為空(NULL/'')時才打 API,
 *     已填過的角色重跑不會重打,維持冪等且不狂打 API。
 *     新增 WP-CLI 指令 `wp anime backfill-characters`,可單獨批次回填
 *     既有(已進表但欄位空)的角色,無需整個重新遷移。打 API 之間
 *     sleep(1) 節流,避免觸發 Bangumi rate limit。
 *   1.2.0 (2026-07-28) — 修正 source guard 邏輯(取代 1.1.0 的錯誤版本):
 *     匯入策略為「優先 AniList、Bangumi 輔助」,實測後確認:
 *       - character 的 id 只有 source==='bangumi' 時才是真 bgm_id;
 *         source==='anilist' 時是 AniList character id(假 bgm_id)。
 *       - voice_actor 與 staff 的 id 永遠是真 bgm_id(與外層 source 無關)。
 *     因此改為「只在寫入 character 時判 source」:
 *       - CAST 項 source!=='bangumi' 時,不寫 character(角色綁 0),
 *         但底下的 voice_actor 照常寫入 person 與 relation。
 *       - STAFF 不再判 source,一律以 id>0 為準寫入。
 *     停止使用 1.1.0 的「整項 skip」邏輯,該版會誤擋合法聲優。
 *   1.1.0 — (已作廢) CAST/STAFF 整項 source guard,會誤擋 anilist 項下的合法聲優。
 *   1.0.0 — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Entity_Migrator {

	const BGM_CHARACTER_URL = 'https://api.bgm.tv/v0/characters/';
	const USER_AGENT        = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)';

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
			// [1.2.0] character 只有 source==='bangumi' 時 id 才是真 bgm_id。
			//         非 bangumi 時角色不寫(綁 0),但底下聲優照常寫入。
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
				// 角色來自 anilist,id 不可信,不建 character,計入 skipped
				$stats['skipped']++;
			}

			$vas = ( ! empty( $c['voice_actors'] ) && is_array( $c['voice_actors'] ) )
				? $c['voice_actors'] : [];

			if ( empty( $vas ) ) {
				// 角色沒聲優,仍寫一筆關聯(person=0),保留角色出現在此作品的紀錄
				// (僅在角色是合法 bgm 角色時才寫,anilist 角色沒有合法 char_bgm 可綁)
				if ( $char_bgm > 0 && ! $dry_run ) {
					$this->upsert_relation( $anime_id, $char_bgm, 0, 'cast', $role );
				}
				if ( $char_bgm > 0 ) {
					$stats['relations']++;
				}
				continue;
			}

			foreach ( $vas as $va ) {
				// [1.2.0] voice_actor 的 id 永遠是真 bgm_id,照常寫入,不判 source。
				$p_bgm = (int) ( $va['id'] ?? 0 );
				$p_nm  = trim( (string) ( $va['name'] ?? '' ) );
				$p_img = (string) ( $va['image'] ?? '' );

				if ( $p_bgm > 0 ) {
					if ( ! $dry_run ) {
						$this->upsert_person( $p_bgm, $p_nm, $p_img, 'cv' );
					}
					$stats['persons']++;
				}

				// relation 的 character_bgm_id:合法角色用真 id,anilist 角色綁 0。
				// 保留「此聲優出現在此作品」的紀錄。
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
			// [1.2.0] staff 的 id 永遠是真 bgm_id,不再判 source,僅以 id>0 為準。
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
	 * upsert 角色:bgm_id 存在則更新(非空譯名/圖才覆蓋),否則新增。
	 *
	 * v1.4.0 — 另存 aliases_json(別名)。
	 * v1.3.0 — 寫入時另打 /v0/characters/{id} 補 summary / infobox
	 *          (gender / birthday / bloodtype / name_original)。
	 *          僅在該角色 summary 目前為空時才打 API,已填過的角色重跑不重打。
	 */
	private function upsert_character( int $bgm_id, string $name, string $image ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, summary, gender, birthday, bloodtype, name_original, aliases_json
				 FROM {$this->t_char} WHERE bgm_id = %d",
				$bgm_id
			),
			ARRAY_A
		);

		// 只有「新角色」、「summary 為空」或「aliases_json 為空」才打詳細端點。
		// v1.4.0：加入 aliases_json 空白判斷，避免已有 summary 但無別名的角色漏填。
		$needs_detail = ! $row
			|| ( trim( (string) ( $row['summary']      ?? '' ) ) === '' )
			|| ( trim( (string) ( $row['aliases_json'] ?? '' ) ) === '' );
		$detail       = $needs_detail ? $this->fetch_bgm_character_detail( $bgm_id ) : [];


		// 別名:非空才 JSON 編碼,空陣列存 ''(方便判空)。
		$aliases_json = ( ! empty( $detail['aliases'] ) )
			? wp_json_encode( $detail['aliases'], JSON_UNESCAPED_UNICODE )
			: '';

		if ( $row ) {
			$set = [];
			if ( $name !== '' )  { $set['name']  = $name; }
			if ( $image !== '' ) { $set['image'] = $image; }

			// 只在既有欄位為空時才用 API 值回填(不覆蓋人工整理過的資料)。
			if ( ! empty( $detail ) ) {
				if ( trim( (string) ( $row['name_original'] ?? '' ) ) === '' && $detail['name_original'] !== '' ) {
					$set['name_original'] = $detail['name_original'];
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
				if ( trim( (string) ( $row['summary'] ?? '' ) ) === '' && $detail['summary'] !== '' ) {
					$set['summary'] = $detail['summary'];
				}
				// 別名:既有為空且抓到新的才寫。
				if ( trim( (string) ( $row['aliases_json'] ?? '' ) ) === '' && $aliases_json !== '' ) {
					$set['aliases_json'] = $aliases_json;
				}
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_char, $set, [ 'bgm_id' => $bgm_id ] );
			}
		} else {
			$wpdb->insert( $this->t_char, [
				'bgm_id'        => $bgm_id,
				'name'          => $name,
				'image'         => $image,
				'name_original' => $detail['name_original'] ?? '',
				'aliases_json'  => $aliases_json,
				'gender'        => $detail['gender']        ?? '',
				'birthday'      => $detail['birthday']      ?? '',
				'bloodtype'     => $detail['bloodtype']     ?? '',
				'summary'       => $detail['summary']       ?? '',
			] );
		}
	}

	/**
	 * v1.4.0 — 打 Bangumi /v0/characters/{id},解析角色詳細欄位。
	 *
	 * 回傳:[ 'name_original' => '', 'gender' => '', 'birthday' => '',
	 *        'bloodtype' => '', 'summary' => '', 'aliases' => [] ]
	 * 任何失敗都回全空陣列(aliases 為空陣列),呼叫端據此不覆蓋。
	 *
	 * @param int $bgm_id
	 * @return array
	 */
	private function fetch_bgm_character_detail( int $bgm_id ): array {
		$empty = [
			'name_original' => '',
			'gender'        => '',
			'birthday'      => '',
			'bloodtype'     => '',
			'summary'       => '',
			'aliases'       => [],
		];

		if ( $bgm_id <= 0 ) {
			return $empty;
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

		// summary(角色簡介),轉繁。
		$summary = $convert( (string) ( $data['summary'] ?? '' ) );

		// gender:Bangumi 頂層可能有 gender('male'/'female'/'' 或中文)。
		$gender_raw = strtolower( trim( (string) ( $data['gender'] ?? '' ) ) );
		$gender     = match ( $gender_raw ) {
			'male'   => '男',
			'female' => '女',
			''       => '',
			default  => (string) ( $data['gender'] ?? '' ),
		};

		// infobox:陣列,每筆 [ 'key' => .., 'value' => .. ]。
		$name_ja   = ''; // 日文名(藏在「别名」巢狀陣列內)
		$name_cn   = ''; // 简体中文名(top-level)
		$birthday  = '';
		$bloodtype = '';
		$aliases   = []; // 完整別名 k => v

		foreach ( (array) ( $data['infobox'] ?? [] ) as $rowbox ) {
			$key   = trim( (string) ( $rowbox['key'] ?? '' ) );
			$value = $rowbox['value'] ?? '';

			// [1.4.0] 「别名」的 value 是巢狀陣列 [ {k,v}, ... ],需單獨展開。
			if ( $key === '别名' || $key === '別名' ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $alias ) {
						$k = trim( (string) ( $alias['k'] ?? '' ) );
						$v = trim( (string) ( $alias['v'] ?? '' ) );
						if ( $v === '' ) {
							continue;
						}
						// 無標籤別名(k 空)用序號當 key,避免互相覆蓋。
						if ( $k === '' ) {
							$k = '別名' . ( count( $aliases ) + 1 );
						}
						$aliases[ $k ] = $v;
						// 順手抓日文名,供 name_original 優先使用。
						if ( ( $k === '日文名' || $k === '日文名稱' ) && $name_ja === '' ) {
							$name_ja = $v;
						}
					}
				}
				continue;
			}

			// 其餘欄位:value 若仍是陣列(保險),壓成頓號字串。
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
					if ( $name_cn === '' ) {
						$name_cn = $value;
					}
					break;
				case '生日':
					$birthday = $value;
					break;
				case '血型':
					$bloodtype = $value;
					break;
				case '性别':
				case '性別':
					if ( $gender === '' ) {
						$gender = $value;
					}
					break;
			}
		}

		// name_original 優先日文名;無日文名時退而用简体中文名(轉繁)。
		$name_original = $name_ja !== '' ? $name_ja : $convert( $name_cn );

		return [
			'name_original' => $name_original,
			'gender'        => $gender,
			'birthday'      => $birthday,
			'bloodtype'     => $bloodtype,
			'summary'       => $summary,
			'aliases'       => $aliases,
		];
	}

	/**
	 * v1.4.0 — 批次回填既有角色的空白詳細欄位(供 backfill-characters 指令用)。
	 *
	 * @param array $args ['force' => bool 連有 summary 的也重抓, 'bgm_id' => int 只處理單一角色]
	 * @return array 統計
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
			// 只抓 summary 為 NULL/'' 或 aliases_json 為空的角色。
			$ids = $wpdb->get_col(
				"SELECT bgm_id FROM {$this->t_char}
				 WHERE bgm_id > 0
				 AND ( summary IS NULL OR summary = '' OR aliases_json IS NULL OR aliases_json = '' )"
			);
		}

		foreach ( $ids as $bgm_id ) {
			$bgm_id = (int) $bgm_id;
			$stats['total']++;

			$detail = $this->fetch_bgm_character_detail( $bgm_id );

			// aliases 是陣列,array_filter 對它無意義,先抽出來單獨判。
			$aliases      = $detail['aliases'] ?? [];
			$scalar_part  = $detail;
			unset( $scalar_part['aliases'] );

			if ( empty( array_filter( $scalar_part ) ) && empty( $aliases ) ) {
				$stats['failed']++;
				sleep( 1 ); // Bangumi 節流。
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT summary, gender, birthday, bloodtype, name_original, aliases_json
					 FROM {$this->t_char} WHERE bgm_id = %d",
					$bgm_id
				),
				ARRAY_A
			);

			$set = [];
			foreach ( [ 'name_original', 'gender', 'birthday', 'bloodtype', 'summary' ] as $field ) {
				$current = trim( (string) ( $row[ $field ] ?? '' ) );
				$new     = (string) ( $detail[ $field ] ?? '' );
				if ( $new !== '' && ( $force || $current === '' ) ) {
					$set[ $field ] = $new;
				}
			}

			// 別名:既有為空(或 --force)且抓到新的才寫。
			$cur_aliases = trim( (string) ( $row['aliases_json'] ?? '' ) );
			if ( ! empty( $aliases ) && ( $force || $cur_aliases === '' ) ) {
				$set['aliases_json'] = wp_json_encode( $aliases, JSON_UNESCAPED_UNICODE );
			}

			if ( ! empty( $set ) ) {
				$wpdb->update( $this->t_char, $set, [ 'bgm_id' => $bgm_id ] );
				$stats['updated']++;
			} else {
				$stats['no_change']++;
			}

			sleep( 1 ); // 每筆之間 sleep 1 秒,避免觸發 Bangumi rate limit。
		}

		return $stats;
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
		WP_CLI::log( '略過(anilist角色/無id) : ' . $stats['skipped'] );
		WP_CLI::success( $dry_run ? 'Dry run 完成(未寫入)' : '遷移完成' );
	} );

	/**
	 * v1.4.0 — 回填既有角色的 summary / infobox / 別名 空白欄位。
	 *   wp anime backfill-characters            # 只補 summary 或別名為空的角色
	 *   wp anime backfill-characters --force     # 連已有資料的也重抓
	 *   wp anime backfill-characters --id=107704 # 只回填單一角色(測試用)
	 */
	WP_CLI::add_command( 'anime backfill-characters', function ( $args, $assoc_args ) {
		$force  = isset( $assoc_args['force'] );
		$bgm_id = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;

		$migrator = new Anime_Sync_Entity_Migrator();

		WP_CLI::log( '=== 回填角色詳細欄位(summary / 性別 / 生日 / 血型 / 日文名 / 別名)===' );
		if ( $force ) {
			WP_CLI::log( '模式:--force(連已有資料的角色也重抓)' );
		}

		$stats = $migrator->backfill_characters( [
			'force'  => $force,
			'bgm_id' => $bgm_id,
		] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '掃描角色數  : ' . $stats['total'] );
		WP_CLI::log( '已更新      : ' . $stats['updated'] );
		WP_CLI::log( '無變更      : ' . $stats['no_change'] );
		WP_CLI::log( '抓取失敗    : ' . $stats['failed'] );
		WP_CLI::success( '回填完成' );
	} );
}
