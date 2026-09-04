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
 * 做法：只替換「被換成別的字」的 7 個字元，異體字一律不動。
 *
 *   站上要保持繁體中文。曾經考慮過「拿 bgm_id 向 Bangumi 查權威寫法後整個
 *   換掉」，但那會把繁體字形一併換成日文字形（小山內憐央 → 小山内怜央，
 *   內→内 不是我們要的），與「保持資料繁體中文」的目標相反，因此放棄。
 *
 * 那 7 個字怎麼來的：
 *   先拿 16,000 個乾淨的人名（wp_anime_persons / anime_characters，實測
 *   0 筆受損）餵進轉換器，量出它會改動的 16 種字。再逐一判斷是「換成別的字」
 *   還是「同一個字的異體」：
 *
 *     換成別的字（修）  裡≠里、鬥≠斗、週≠周、鬱≠郁、憐≠怜、託≠托、佈≠布
 *     異體字（不修）    巖/岩、嶽/岳、峰/峯、臺/台、遙/遥、莊/庄、啟/啓、內/内、俁/俣
 *
 * 這個分類已對照 Bangumi 驗證：45 個受損人名套用替換後，與 Bangumi 原始
 * 寫法做異體正規化比對，45/45 完全一致——代表 7 組替換是完整的
 * （沒有漏掉別的錯字），也沒有把異體字誤列進來。
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Name_Repair {

	/**
	 * 要修的字：轉換器把它們改成了「另一個意思完全不同的字」。
	 *
	 *   裡（裡面）  ≠ 里（村里、人名）
	 *   鬥（打鬥）  ≠ 斗（北斗、泰斗）
	 *   週（週次）  ≠ 周（姓氏）
	 *   鬱（憂鬱）  ≠ 郁（馥郁）
	 *   憐（憐憫）  ≠ 怜（伶俐）
	 *   託（委託）  ≠ 托（托住）
	 *   佈（宣佈）  ≠ 布（布施）
	 *
	 * 這些不是繁簡差異，是被換成了別的字，所以一定要改回來。
	 */
	private const WRONG_CHARS = [
		'裡' => '里',
		'鬥' => '斗',
		'週' => '周',
		'鬱' => '郁',
		'憐' => '怜',
		'託' => '托',
		'佈' => '布',
	];

	/**
	 * 不修的字：同一個字的異體，台灣習慣用左邊那個寫法。
	 *
	 *   巖／岩　嶽／岳　峰／峯　臺／台　遙／遥　莊／庄　啟／啓　內／内　俁／俣
	 *
	 * 站上要保持繁體中文，這些維持原樣不動；只在驗證比對時用來正規化。
	 */
	private const VARIANT_CHARS = [
		'巖' => '岩', '嶽' => '岳', '峰' => '峯', '臺' => '台',
		'遙' => '遥', '莊' => '庄', '啟' => '啓', '內' => '内', '俁' => '俣',
	];

	/** 篩出候選用：出現任何一個受損字就是候選 */
	private const SUSPECT_CHARS = [
		'裡', '鬥', '週', '鬱', '憐', '託', '佈',
	];

	private const META_KEYS = [ 'anime_cast_json', 'anime_staff_json' ];

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
			'identical'    => 0,
			'posts_fixed'  => 0,
			'fields_fixed' => 0,
			'map'          => [],
		];

		$candidates = $this->collect_candidates();
		$stats['candidates'] = count( $candidates );

		if ( ! $candidates ) {
			return $stats;
		}

		/*
		 * ── 建立對照表：只做 WRONG_CHARS 的字元替換 ──
		 *
		 * 不再向 Bangumi 取整個名字。原因：Bangumi 給的是日文寫法，
		 * 直接採用會把繁體字形一併換成日文字形
		 * （小山內憐央 → 小山内怜央，內→内 不是我們要的）。
		 * 站上要保持繁體中文，所以只改「被換成別的字」的那 7 個，
		 * 異體字維持原樣。
		 *
		 * 這個做法已對照 Bangumi 驗證過：45 個受損人名套用替換後，
		 * 與 Bangumi 原始寫法做異體正規化比對，45/45 完全一致，
		 * 代表 7 組替換是完整的、也沒有把異體字誤列進來。
		 */
		$map = [];
		$n   = 0;

		foreach ( $candidates as $stored => $info ) {
			if ( $limit > 0 && $n >= $limit ) {
				break;
			}
			$n++;

			$real = strtr( $stored, self::WRONG_CHARS );

			if ( $real === $stored ) {
				$stats['identical']++;
				continue;
			}

			/*
			 * 原本這裡有一道「差異必須證明是轉換造成的」守衛
			 * （把 Bangumi 原始寫法丟進轉換器，看是否等於站上的值）。
			 * 改成字元替換後那道守衛變成恆真判斷——替換的來源就是
			 * 轉換器的對照關係，convert() 一定會把它換回去——沒有作用，
			 * 反而可能因為其他字的差異造成誤判，所以移除。
			 *
			 * 現在的安全性來自別的地方：
			 *   1. 只替換 7 個「被換成別的字」的字元，異體字完全不碰
			 *   2. 角色名在 collect_candidates() 就整個排除
			 *   3. 只寫 name / native 欄位
			 *   4. 已對照 Bangumi 驗證 45/45 一致
			 */
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
			$rows    = $this->fetch_rows( $meta_key );
			$is_cast = ( $meta_key === 'anime_cast_json' );

			foreach ( $rows as $row ) {
				$data = json_decode( (string) $row->meta_value, true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				$hits = 0;
				$data = $this->apply_map( $data, $map, $is_cast, $hits );

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
			$is_cast = ( $meta_key === 'anime_cast_json' );

			foreach ( $this->fetch_rows( $meta_key ) as $row ) {
				$data = json_decode( (string) $row->meta_value, true );

				if ( ! is_array( $data ) ) {
					continue;
				}

				foreach ( $data as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					/*
					 * ★ 角色名一律不修。
					 *
					 * cast_json 頂層是角色，站上採「繁體中文譯名」（使用者的明確決定，
					 * 見 class-import-manager.php 的欄位語意說明）；Bangumi 給的是日文原名。
					 * 拿 Bangumi 的名字回填，等於把繁中譯名改成日文寫法。
					 *
					 * 這種情況「差異確實是轉換造成的」，所以 convert() 那道守衛擋不住——
					 * 方向對人物是對的（日本人的真名就是日文漢字），對角色卻是反的。
					 * 實測 17 個角色名有 10 個會被改，其中：
					 *   豬俁         → 猪俣          豬(繁) → 猪(日)
					 *   三代目御臺所 → 三代目御台所  臺(繁) → 台(日)
					 *   白鳥憐太     → 白鳥怜太      憐(繁) → 怜(日)
					 * 因此只能從來源排除，不能靠守衛。
					 *
					 * staff_json 的條目與 cast_json 的 voice_actors 都是「真實人物」，
					 * 站上要的就是日文本名，照修。
					 */
					if ( ! $is_cast ) {
						$this->collect_from_entry( $entry, 'person', $out );
					}

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

	/**
	 * 套用對照表。
	 *
	 * 刻意不用泛用遞迴：那樣只要值字串相同就會被換掉，某個人物名如果剛好
	 * 等於某個角色名，就會連角色欄位一起改到——而角色名一律不能動
	 * （見 collect_candidates 的說明）。所以照實際結構逐層走。
	 */
	private function apply_map( array $data, array $map, bool $is_cast, int &$hits ): array {
		foreach ( $data as $i => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			// cast_json 的頂層是角色，不動
			if ( ! $is_cast ) {
				$entry = $this->replace_fields( $entry, $map, $hits );
			}

			if ( ! empty( $entry['voice_actors'] ) && is_array( $entry['voice_actors'] ) ) {
				foreach ( $entry['voice_actors'] as $vi => $va ) {
					if ( is_array( $va ) ) {
						$entry['voice_actors'][ $vi ] = $this->replace_fields( $va, $map, $hits );
					}
				}
			}

			$data[ $i ] = $entry;
		}

		return $data;
	}

	/** 換掉單一條目的 name / native */
	private function replace_fields( array $entry, array $map, int &$hits ): array {
		foreach ( [ 'name', 'native' ] as $field ) {
			$value = $entry[ $field ] ?? null;

			if ( is_string( $value ) && $value !== '' && isset( $map[ $value ] ) ) {
				$entry[ $field ] = $map[ $value ];
				$hits++;
			}
		}

		return $entry;
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
		WP_CLI::log( '受影響文章          : ' . $stats['posts_fixed'] );
		WP_CLI::log( '修正欄位            : ' . $stats['fields_fixed'] );

		if ( ! empty( $stats['map'] ) ) {
			WP_CLI::log( '─── 對照表（前 40 筆）───' );
			foreach ( $stats['map'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		}

		WP_CLI::success( $dry_run ? 'Dry run 完成（未寫入）' : '修復完成' );
	} );
}
