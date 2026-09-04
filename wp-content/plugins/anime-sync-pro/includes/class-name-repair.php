<?php
/**
 * 人名簡繁轉換受損修復
 *
 * 站上的 cast/staff 資料來自 Bangumi（簡體），程式有一道簡繁轉換把
 * 「导演→導演」這類用語轉成繁體。但那道轉換原本被套用到整份 JSON，
 * 包括日本人的名字——轉換器分不出 `里` 是人名還是「裡面」的意思，
 * 於是把 `前田佳織里` 改成 `前田佳織裡`，變成一個不存在的名字。
 *
 * 寫入端已於 class-api-handler.php / class-import-manager.php 修正
 * （人名欄位不再轉換），本檔負責修復修正前就已寫進資料庫的內容。
 *
 * 用法：
 *   wp anime repair-corrupted-names --dry-run       # 列出完整對照表，不寫入
 *   wp anime repair-corrupted-names --limit=100     # 只處理前 N 種受損人名
 *   wp anime repair-corrupted-names                 # 正式修復
 *
 * 做法：
 *   不用字元規則反推。實測發現同一個名字可能有多個字同時被改壞
 *   （立岩優里 → 立巖優裡，岩與里都錯），單一規則只能修一半。
 *   因此改為「用受損字集合偵測 → 拿 bgm_id 向 Bangumi 查權威寫法」，
 *   查不到的一律跳過，不猜。
 *
 * 受損字集合的來源：
 *   拿 16,000 個乾淨的人名（wp_anime_persons / anime_characters，
 *   實測 0 筆受損）餵進轉換器，記錄哪些字會被改掉。實測結果為
 *   里→裡 94、岩→巖 43、托→託 26、斗→鬥 20 等 16 種。
 *   這是量出來的，不是列舉猜的。
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Name_Repair {

	/** 轉換器會產生的「受損字」，用來篩出候選（實測推導，見檔頭說明） */
	private const SUSPECT_CHARS = [
		'裡', '巖', '託', '鬥', '週', '嶽', '臺', '佈',
		'遙', '鬱', '憐', '峰', '莊', '啟', '俁',
	];

	private const META_KEYS = [ 'anime_cast_json', 'anime_staff_json' ];

	/** 兩次 Bangumi 請求之間的間隔（毫秒） */
	private const BGM_INTERVAL_MS = 900;

	/**
	 * @param array{dry_run?:bool,limit?:int} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {
		$dry_run = ! empty( $args['dry_run'] );
		$limit   = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

		$stats = [
			'candidates'   => 0,
			'resolved'     => 0,
			'unresolved'   => 0,
			'identical'    => 0,
			'not_conversion' => 0,
			'posts_fixed'  => 0,
			'fields_fixed' => 0,
			'map'          => [],
			'unresolved_list' => [],
			'not_conversion_list' => [],
		];

		$candidates = $this->collect_candidates();
		$stats['candidates'] = count( $candidates );

		if ( ! $candidates ) {
			return $stats;
		}

		/* ── 向 Bangumi 查權威寫法 ── */
		$map = [];
		$n   = 0;

		foreach ( $candidates as $stored => $info ) {
			if ( $limit > 0 && $n >= $limit ) {
				break;
			}
			$n++;

			$real = $this->fetch_authoritative_name( (int) $info['id'], $info['kind'] );

			if ( $real === '' ) {
				$stats['unresolved']++;
				if ( count( $stats['unresolved_list'] ) < 20 ) {
					$stats['unresolved_list'][] = $stored . '（' . $info['kind'] . ' id ' . $info['id'] . '）';
				}
				continue;
			}

			if ( $real === $stored ) {
				// Bangumi 本來就是這樣寫，不是受損
				$stats['identical']++;
				continue;
			}

			/*
			 * 差異必須「證明得出來是簡繁轉換造成的」才算受損。
			 *
			 * 判斷方式：把 Bangumi 的原始寫法丟進同一個轉換器，
			 * 如果輸出正好等於站上存的值，那站上這個值就是它被轉壞的結果。
			 *
			 *   convert(前田佳織里) = 前田佳織裡 = 站上的值  → 確定受損，修
			 *   convert(高峯葉月)   = 高峰葉月   = 站上的值  → 確定受損，修
			 *
			 * 過不了這道檢查的一律跳過，例如：
			 *
			 *   角色的中文譯名 vs Bangumi 的日文原名
			 *     convert(灰ヶ峰ゆりう) ≠ 灰之峰百合生
			 *     convert(村の長老)     ≠ 村莊長老
			 *     convert(ハルカ)       ≠ 遙香
			 *   —— 這是翻譯差異不是轉換損壞，改了會毀掉使用者要的繁中譯名
			 *      （角色名採繁中譯名是明確決定，見 class-import-manager.php）
			 *
			 *   Bangumi 那邊自己改過名字的情況也會被這道檢查擋下來，
			 *   不會借修復之名把無關的改動一起帶進來。
			 */
			if ( ! class_exists( 'Anime_Sync_CN_Converter' )
				|| Anime_Sync_CN_Converter::static_convert( $real ) !== $stored ) {
				$stats['not_conversion']++;
				if ( count( $stats['not_conversion_list'] ) < 20 ) {
					$stats['not_conversion_list'][] = sprintf( '%s ←→ %s', $stored, $real );
				}
				continue;
			}

			$map[ $stored ] = $real;
			$stats['resolved']++;

			if ( count( $stats['map'] ) < 40 ) {
				$stats['map'][] = sprintf( '%s → %s', $stored, $real );
			}
		}

		if ( ! $map ) {
			return $stats;
		}

		/* ── 套用對照表 ── */
		foreach ( self::META_KEYS as $meta_key ) {
			$rows = $this->fetch_rows( $meta_key );

			foreach ( $rows as $row ) {
				$data = json_decode( (string) $row->meta_value, true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				$hits = 0;
				$data = $this->apply_map( $data, $map, $hits );

				if ( $hits === 0 ) {
					continue;
				}

				$stats['posts_fixed']++;
				$stats['fields_fixed'] += $hits;

				if ( ! $dry_run ) {
					update_post_meta(
						(int) $row->post_id,
						$meta_key,
						wp_slash( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) )
					);
				}
			}
		}

		return $stats;
	}

	/**
	 * 掃出所有含受損字的人名，連同它的 bgm id 與類型。
	 *
	 * staff_json 的條目、cast_json 的 voice_actors 都是「人物」；
	 * cast_json 的頂層條目是「角色」。兩者在 Bangumi 是不同端點，
	 * id 空間也不同，搞混會查到不相干的東西。
	 */
	private function collect_candidates(): array {
		$out = [];

		foreach ( self::META_KEYS as $meta_key ) {
			$kind_top = ( $meta_key === 'anime_cast_json' ) ? 'character' : 'person';

			foreach ( $this->fetch_rows( $meta_key ) as $row ) {
				$data = json_decode( (string) $row->meta_value, true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				foreach ( $data as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					$this->collect_from_entry( $entry, $kind_top, $out );

					foreach ( (array) ( $entry['voice_actors'] ?? [] ) as $va ) {
						if ( is_array( $va ) ) {
							$this->collect_from_entry( $va, 'person', $out );
						}
					}
				}
			}
		}

		return $out;
	}

	private function collect_from_entry( array $entry, string $kind, array &$out ): void {
		$id = (int) ( $entry['id'] ?? 0 );

		if ( $id <= 0 ) {
			return;
		}

		foreach ( [ 'name', 'native' ] as $field ) {
			$value = trim( (string) ( $entry[ $field ] ?? '' ) );

			if ( $value === '' || isset( $out[ $value ] ) || ! self::looks_corrupted( $value ) ) {
				continue;
			}

			$out[ $value ] = [ 'id' => $id, 'kind' => $kind ];
		}
	}

	private static function looks_corrupted( string $value ): bool {
		foreach ( self::SUSPECT_CHARS as $ch ) {
			if ( mb_strpos( $value, $ch ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/** 只取含受損字的列，避免把整表拉進記憶體 */
	private function fetch_rows( string $meta_key ): array {
		global $wpdb;

		$like = [];
		foreach ( self::SUSPECT_CHARS as $ch ) {
			$like[] = $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( $ch ) . '%' );
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				  WHERE meta_key = %s AND meta_value <> '' AND ( " . implode( ' OR ', $like ) . ' )',
				$meta_key
			)
		);
	}

	private function fetch_authoritative_name( int $id, string $kind ): string {
		usleep( self::BGM_INTERVAL_MS * 1000 );

		$endpoint = ( $kind === 'character' ) ? 'characters' : 'persons';

		$res = wp_remote_get(
			'https://api.bgm.tv/v0/' . $endpoint . '/' . $id,
			[
				'timeout' => 12,
				'headers' => [ 'User-Agent' => 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)' ],
			]
		);

		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		return trim( (string) ( $body['name'] ?? '' ) );
	}

	/** 遞迴套用對照表，只換 name / native 欄位 */
	private function apply_map( array $data, array $map, int &$hits ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->apply_map( $value, $map, $hits );
				continue;
			}

			if ( ! is_string( $key ) || ! in_array( $key, [ 'name', 'native' ], true ) ) {
				continue;
			}

			if ( ! is_string( $value ) || ! isset( $map[ $value ] ) ) {
				continue;
			}

			$data[ $key ] = $map[ $value ];
			$hits++;
		}

		return $data;
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime repair-corrupted-names', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		WP_CLI::log( $dry_run ? '=== DRY RUN（不寫入）===' : '=== 開始修復受損人名 ===' );

		if ( $limit > 0 ) {
			WP_CLI::log( '本批上限：--limit=' . $limit . ' 種人名' );
		}

		$repair = new Anime_Sync_Name_Repair();
		$stats  = $repair->run( [ 'dry_run' => $dry_run, 'limit' => $limit ] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '偵測到疑似受損人名 : ' . $stats['candidates'] );
		WP_CLI::log( '向 Bangumi 查到修正 : ' . $stats['resolved'] );
		WP_CLI::log( 'Bangumi 本來就這樣  : ' . $stats['identical'] . '（非受損，跳過）' );
		WP_CLI::log( '差異非轉換造成      : ' . $stats['not_conversion'] . '（多為角色的繁中譯名，跳過）' );
		WP_CLI::log( '查不到／略過        : ' . $stats['unresolved'] );
		WP_CLI::log( '受影響文章          : ' . $stats['posts_fixed'] );
		WP_CLI::log( '修正欄位            : ' . $stats['fields_fixed'] );

		if ( ! empty( $stats['map'] ) ) {
			WP_CLI::log( '─── 對照表（前 40 筆）───' );
			foreach ( $stats['map'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		}

		if ( ! empty( $stats['not_conversion_list'] ) ) {
			WP_CLI::log( '─── 差異非轉換造成（維持原樣）───' );
			foreach ( $stats['not_conversion_list'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		}

		if ( ! empty( $stats['unresolved_list'] ) ) {
			WP_CLI::log( '─── 查不到的（維持原樣）───' );
			foreach ( $stats['unresolved_list'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		}

		WP_CLI::success( $dry_run ? 'Dry run 完成（未寫入）' : '修復完成' );
	} );
}
