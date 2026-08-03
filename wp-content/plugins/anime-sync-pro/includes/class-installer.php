<?php
/**
 * Installer Class
 *
 * @package Anime_Sync_Pro
 * @version 1.3.2
 *
 * Changelog:
 *   1.3.2 — [Entity] 角色 / 聲優獨立實體資料表
 *           - [新增] create_tables() 尾端新增三張表：
 *                   anime_characters（角色）、anime_persons（聲優/staff）、
 *                   anime_relations（作品↔角色↔人 關聯）
 *           - [新增] characters / persons 皆含 bgm_id(unique) + anilist_id + mal_id，
 *                   供未來對接三大站 API
 *           - [變更] DB_VERSION 1.0 → 1.1
 *   1.3.1 — [Fix-M3] DB 版本寫入
 *           - [新增] 定義 DB_VERSION 常數，activate() 與 maybe_upgrade()
 *                   皆寫入 update_option('anime_sync_db_version', self::DB_VERSION)，
 *                   修正 dashboard 永遠顯示「—」的問題（根治，非讀取端容錯）
 *   1.3.0 — Taxonomy seeder 整合（取代 setup-taxonomy.php）
 *           - [新增] seed_taxonomy_terms() 整支內建 category / channel / genre /
 *                   anime_format_tax / anime_season_tax 的種子建立邏輯
 *           - [新增] activate() / maybe_upgrade() 設 transient
 *                   'anime_sync_pending_seed'，由主檔 init priority 99 觸發 seed
 *           - [新增] run_pending_seed() 公開方法，供主檔 init 階段呼叫
 *           - [改進] 季度年份範圍動態計算：當年-3 ~ 當年+1（N=5），
 *                   每次升級自動往後滑動，不再寫死 2000–2035
 *           - [改進] 舊資料保護：若 weixiaoacg_taxonomy_v5_done 已存在，
 *                   只補季度年份，不覆蓋使用者可能改過的 category/channel/genre
 *           - [移除] setup-taxonomy.php 已停用，可從外掛根目錄刪除
 *   1.2.0 — 安裝器優化
 *           - [修正] activate() 移除多餘的 flush_rewrite_rules()
 *           - [修正] deactivate() 移除 flush_rewrite_rules()
 *           - [改進] 新增 maybe_upgrade()
 *           - [改進] create_upload_dirs() 加入錯誤回傳
 *           - [改進] set_default_options() 改用 ?? 方式
 *           - [新增] is_table_missing() 工具方法
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Installer {

	/**
	 * 資料庫結構版本（Fix-M3）
	 * 每次 create_tables() 的 schema 有實質變動時手動 +1，
	 * activate()/maybe_upgrade() 會寫入 option 'anime_sync_db_version'，
	 * dashboard 系統資訊即可正確顯示，不再 fallback 成「—」。
	 */
	private const DB_VERSION = '1.2';

	/**
	 * 季度 seed：往前 N 年 + 當年 + 當年+1 的範圍
	 * end_year   = 當年 + 1
	 * start_year = end_year - (SEASON_YEARS_RANGE - 1)
	 * SEASON_YEARS_RANGE = 5 → 共建 5 個年份（例：2026 時建 2023~2027）
	 */
	private const SEASON_YEARS_RANGE = 5;

	/**
	 * 預設選項定義（升級時也會用到）
	 */
	private function get_default_options(): array {
		return [
			'anime_sync_cn_method'           => 'dict',
			'anime_sync_image_method'        => 'api_url',
			'anime_sync_cdn_provider'        => 'cloudflare',
			'anime_sync_cdn_base_url'        => '',
			'anime_sync_api_delay'           => 1000,
			'anime_sync_batch_size'          => 15,
			'anime_sync_log_email_notify'    => 1,
			'anime_sync_log_retention_days'  => 30,
			'anime_sync_debug_mode'          => 0,
			'anime_sync_delete_on_uninstall' => 0,
		];
	}

	// =========================================================================
	// 啟用 / 停用
	// =========================================================================

	public function activate(): void {
		$this->create_tables();
		$this->set_default_options();
		$this->create_upload_dirs();
		$this->register_cpt_for_flush();

		// 設置 seed 旗標：activate 階段 taxonomy 還沒註冊，
		// 由主檔 init priority 99 透過 run_pending_seed() 執行
		set_transient( 'anime_sync_pending_seed', 1, HOUR_IN_SECONDS );

		update_option( 'anime_sync_activated_at', current_time( 'mysql' ) );
		update_option( 'anime_sync_version',      ANIME_SYNC_PRO_VERSION );
		update_option( 'anime_sync_db_version',   self::DB_VERSION ); // [Fix-M3]
	}

	public function deactivate(): void {
		delete_transient( 'anime_sync_pending_count' );
		delete_transient( 'anime_sync_pending_seed' );
	}

	// =========================================================================
	// 升級檢查
	// =========================================================================

	public function maybe_upgrade(): bool {
		$current_version = get_option( 'anime_sync_version', '0.0.0' );
		$db_version      = get_option( 'anime_sync_db_version', '' );

		if ( version_compare( $current_version, ANIME_SYNC_PRO_VERSION, '>=' ) && $db_version === self::DB_VERSION ) {
			// [Fix-M3] 即使外掛版本沒變，若 db_version 從未寫入（舊站升級而來），
			// 也補寫一次，確保 dashboard 不再顯示「—」。
			if ( $db_version === '' ) {
				update_option( 'anime_sync_db_version', self::DB_VERSION );
			}
			return false;
		}

		$this->create_tables();
		$this->set_default_options();

		// 升級時也設 seed 旗標：每次版本變動會自動補建當年新季度
		set_transient( 'anime_sync_pending_seed', 1, HOUR_IN_SECONDS );

		update_option( 'anime_sync_version',           ANIME_SYNC_PRO_VERSION );
		update_option( 'anime_sync_db_version',        self::DB_VERSION ); // [Fix-M3]
		update_option( 'anime_sync_last_upgrade_at',   current_time( 'mysql' ) );
		update_option( 'anime_sync_last_upgrade_from', $current_version );
		update_option( 'anime_sync_flush_rewrite',     1 );

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info(
				"Anime Sync Pro upgraded from {$current_version} to " . ANIME_SYNC_PRO_VERSION
			);
		}

		return true;
	}

	// =========================================================================
	// Seed 觸發（由主檔 init priority 99 呼叫）
	// =========================================================================

	/**
	 * 檢查並執行 pending seed
	 *
	 * 主檔應在 init priority 99（CPT 與 taxonomy 註冊完成後）呼叫：
	 *   add_action( 'init', function () {
	 *       ( new Anime_Sync_Installer() )->run_pending_seed();
	 *   }, 99 );
	 */
	public function run_pending_seed(): void {
		if ( ! get_transient( 'anime_sync_pending_seed' ) ) {
			return;
		}

		// 防呆：taxonomy 沒註冊就不跑（避免 wp_insert_term 失敗）
		if ( ! taxonomy_exists( 'channel' ) || ! taxonomy_exists( 'anime_season_tax' ) ) {
			return;
		}

		$result = $this->seed_taxonomy_terms();

		delete_transient( 'anime_sync_pending_seed' );

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info(
				'Taxonomy seed completed',
				$result
			);
		}
	}

	// =========================================================================
	// Taxonomy 種子（取代 setup-taxonomy.php）
	// =========================================================================

	/**
	 * 建立所有 taxonomy 種子 term
	 *
	 * @return array{created:int, skipped:int, failed:int, errors:array<string>}
	 */
	public function seed_taxonomy_terms(): array {
		$stats = [ 'created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [] ];

		$is_first_seed = ! get_option( 'weixiaoacg_taxonomy_v5_done' );

		// ─────────────────────────────────────────────
		// 首次 seed 才建：category / channel / genre / anime_format_tax
		// （已建過則跳過，避免覆蓋使用者改過的中文名稱）
		// ─────────────────────────────────────────────
		if ( $is_first_seed ) {
			// 1. 文章內容型 category
			$editorial_categories = [
				[ '公告', 'announcement' ],
				[ '新聞', 'news'         ],
				[ '評論', 'review'       ],
				[ '專題', 'feature'      ],
			];
			foreach ( $editorial_categories as [ $name, $slug ] ) {
				$this->upsert_term( $name, 'category', [ 'slug' => $slug ], $stats );
			}

			// 2. 文章頻道 channel
			$channels = [
				[ '動漫',    'anime'        ],
				[ '漫畫',    'manga'        ],
				[ '輕小說',  'novel'        ],
				[ '遊戲',    'game'         ],
				[ 'VTuber',  'vtuber'       ],
				[ 'Cosplay', 'cosplay'      ],
				[ 'AI工具',  'ai-tools'     ],
				[ '聲優',    'voice-actor'  ],
				[ '音樂',    'music'        ],
				[ '周邊',    'merchandise'  ],
				[ '活動',    'event'        ],
				[ '業界',    'industry'     ],
			];
			foreach ( $channels as [ $name, $slug ] ) {
				$this->upsert_term( $name, 'channel', [ 'slug' => $slug ], $stats );
			}

			// 3. genre
			$genres = [
				[ '動作',     'action'        ],
				[ '冒險',     'adventure'     ],
				[ '喜劇',     'comedy'        ],
				[ '劇情',     'drama'         ],
				[ '奇幻',     'fantasy'       ],
				[ '恐怖',     'horror'        ],
				[ '魔法少女', 'mahou-shoujo'  ],
				[ '機甲',     'mecha'         ],
				[ '音樂',     'music'         ],
				[ '推理',     'mystery'       ],
				[ '懸疑',     'suspense'      ],
				[ '心理',     'psychological' ],
				[ '科幻',     'sci-fi'        ],
				[ '日常',     'slice-of-life' ],
				[ '運動',     'sports'        ],
				[ '超自然',   'supernatural'  ],
				[ '驚悚',     'thriller'      ],
				[ '異世界',   'isekai'        ],
				[ '後宮',     'harem'         ],
				[ '百合',     'yuri'          ],
				[ '耽美',     'bl'            ],
				[ '歷史',     'historical'    ],
				[ '武俠',     'wuxia'         ],
				[ '校園',     'school'        ],
				[ '兒童',     'kids'          ],
				[ '輕色情',   'ecchi'         ],
				[ '戀愛',     'romance'       ],
			];
			foreach ( $genres as [ $name, $slug ] ) {
				$this->upsert_term( $name, 'genre', [ 'slug' => $slug ], $stats );
			}

			// 4. anime_format_tax
			$formats = [
				[ 'TV',     'tv'       ],
				[ 'TV短篇', 'tv-short' ],
				[ '劇場版', 'movie'    ],
				[ 'OVA',    'ova'      ],
				[ 'ONA',    'ona'      ],
				[ '特別篇', 'special'  ],
				[ '音樂MV', 'music'    ],
			];
			foreach ( $formats as [ $name, $slug ] ) {
				$this->upsert_term( $name, 'anime_format_tax', [ 'slug' => $slug ], $stats );
			}
		}

		// ─────────────────────────────────────────────
		// 5. anime_season_tax — 動態範圍（每次 seed 都跑，補新年份）
		// ─────────────────────────────────────────────
		$end_year   = (int) wp_date( 'Y' ) + 1;
		$start_year = $end_year - ( self::SEASON_YEARS_RANGE - 1 );

		$seasons = [
			'winter' => '冬季',
			'spring' => '春季',
			'summer' => '夏季',
			'fall'   => '秋季',
		];

		for ( $year = $start_year; $year <= $end_year; $year++ ) {
			$parent_id = $this->upsert_term(
				(string) $year,
				'anime_season_tax',
				[ 'slug' => (string) $year ],
				$stats
			);

			if ( $parent_id <= 0 ) {
				continue; // 父 term 建立失敗就跳過子 term
			}

			foreach ( $seasons as $suffix => $label ) {
				$this->upsert_term(
					"{$year} {$label}",
					'anime_season_tax',
					[
						'slug'   => "{$year}-{$suffix}",
						'parent' => $parent_id,
					],
					$stats
				);
			}
		}

		update_option( 'weixiaoacg_taxonomy_v5_done', current_time( 'mysql' ) );

		return $stats;
	}

	/**
	 * 內部 upsert：依 slug 找不到才建立，找到則跳過（不覆蓋名稱避免破壞使用者編輯）
	 *
	 * @return int term_id（0 表失敗）
	 */
	private function upsert_term( string $name, string $taxonomy, array $args, array &$stats ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$stats['failed']++;
			$stats['errors'][] = "taxonomy not exists: {$taxonomy}";
			return 0;
		}

		$slug   = $args['slug'] ?? sanitize_title( $name );
		$parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;

		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing && ! is_wp_error( $existing ) ) {
			$stats['skipped']++;
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, $taxonomy, [
			'slug'   => $slug,
			'parent' => $parent,
		] );

		if ( is_wp_error( $result ) ) {
			// term_exists 是良性錯誤（可能與其他 taxonomy 重名）
			if ( $result->get_error_code() === 'term_exists' ) {
				$existing_id = (int) ( $result->get_error_data() ?: 0 );
				$stats['skipped']++;
				return $existing_id;
			}
			$stats['failed']++;
			$stats['errors'][] = "{$taxonomy}/{$slug}: " . $result->get_error_message();
			return 0;
		}

		$stats['created']++;
		return (int) $result['term_id'];
	}

	// =========================================================================
	// 資料表建立
	// =========================================================================

	private function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$queue_table = $wpdb->prefix . 'anime_review_queue';
		$queue_sql   = "CREATE TABLE IF NOT EXISTS {$queue_table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			anilist_id  INT(11) UNSIGNED NOT NULL,
			title       VARCHAR(255) NOT NULL DEFAULT '',
			api_data    LONGBLOB,
			status      ENUM('pending','approved','rejected','published') NOT NULL DEFAULT 'pending',
			source      VARCHAR(20) NOT NULL DEFAULT 'manual',
			wp_post_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			created_at  DATETIME NOT NULL,
			updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY anilist_id (anilist_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $queue_sql );

		$logs_table = $wpdb->prefix . 'anime_sync_logs';
		$logs_sql   = "CREATE TABLE IF NOT EXISTS {$logs_table} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level      ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
			message    TEXT NOT NULL,
			context    LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $logs_sql );

		$ratings_table = $wpdb->prefix . 'anime_ratings';
		$ratings_sql   = "CREATE TABLE IF NOT EXISTS {$ratings_table} (
			id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			anime_id         BIGINT(20) UNSIGNED NOT NULL,
			user_id          BIGINT(20) UNSIGNED NOT NULL,
			score_story      DECIMAL(4,2) NOT NULL DEFAULT 0.00,
			score_music      DECIMAL(4,2) NOT NULL DEFAULT 0.00,
			score_animation  DECIMAL(4,2) NOT NULL DEFAULT 0.00,
			score_voice      DECIMAL(4,2) NOT NULL DEFAULT 0.00,
			score_overall    DECIMAL(4,2) NOT NULL DEFAULT 0.00,
			weight           DECIMAL(4,2) NOT NULL DEFAULT 1.00,
			created_at       DATETIME NOT NULL,
			updated_at       DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_anime (user_id, anime_id),
			KEY anime_id (anime_id),
			KEY score_overall (score_overall)
		) {$charset_collate};";
		dbDelta( $ratings_sql );

		$user_status_table = $wpdb->prefix . 'anime_user_status';
		$user_status_sql   = "CREATE TABLE IF NOT EXISTS {$user_status_table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT(20) UNSIGNED NOT NULL,
			anime_id      BIGINT(20) UNSIGNED NOT NULL,
			status        TINYINT UNSIGNED DEFAULT NULL,
			progress      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			favorited     TINYINT(1) NOT NULL DEFAULT 0,
			fullcleared   TINYINT(1) NOT NULL DEFAULT 0,
			started_at    DATETIME DEFAULT NULL,
			completed_at  DATETIME DEFAULT NULL,
			note          VARCHAR(500) DEFAULT NULL,
			is_private    TINYINT(1) NOT NULL DEFAULT 0,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_anime (user_id, anime_id),
			KEY user_status (user_id, status, updated_at),
			KEY user_favorited (user_id, favorited, updated_at),
			KEY anime_status (anime_id, status),
			KEY anime_favorited (anime_id, favorited),
			KEY updated_at (updated_at)
		) {$charset_collate};";
		dbDelta( $user_status_sql );

		$us_stats_table = $wpdb->prefix . 'anime_user_status_stats';
		$us_stats_sql   = "CREATE TABLE IF NOT EXISTS {$us_stats_table} (
			anime_id        BIGINT(20) UNSIGNED NOT NULL,
			want_count      INT UNSIGNED NOT NULL DEFAULT 0,
			watching_count  INT UNSIGNED NOT NULL DEFAULT 0,
			completed_count INT UNSIGNED NOT NULL DEFAULT 0,
			dropped_count   INT UNSIGNED NOT NULL DEFAULT 0,
			favorited_count INT UNSIGNED NOT NULL DEFAULT 0,
			total_count     INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (anime_id),
			KEY watching_count (watching_count),
			KEY favorited_count (favorited_count),
			KEY total_count (total_count)
		) {$charset_collate};";
		dbDelta( $us_stats_sql );

		// =====================================================================
		// v1.1 新增：角色 / 聲優獨立實體 + 關聯表（以 bgm id 為去重主鍵）
		// 純新增，不影響上方既有 5 張表。留 anilist_id / mal_id 供未來對接三大站。
		// =====================================================================

		// 角色表
		$characters_table = $wpdb->prefix . 'anime_characters';
		$characters_sql   = "CREATE TABLE IF NOT EXISTS {$characters_table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bgm_id         BIGINT(20) UNSIGNED NOT NULL,
			anilist_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			mal_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			name           VARCHAR(255) NOT NULL DEFAULT '',
			name_cn        VARCHAR(255) NOT NULL DEFAULT '',
			name_original  VARCHAR(255) NOT NULL DEFAULT '',
			gender         VARCHAR(20) NOT NULL DEFAULT '',
			birthday       VARCHAR(50) NOT NULL DEFAULT '',
			bloodtype      VARCHAR(20) NOT NULL DEFAULT '',
			height         VARCHAR(50) NOT NULL DEFAULT '',
			weight         VARCHAR(50) NOT NULL DEFAULT '',
			summary        LONGTEXT,
			aliases_json   LONGTEXT,
			infobox_json   LONGTEXT,
			image          TEXT,
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY bgm_id (bgm_id),
			KEY anilist_id (anilist_id),
			KEY mal_id (mal_id),
			KEY name (name)
		) {$charset_collate};";
		dbDelta( $characters_sql );

		// 人物表（聲優 cv / 未來製作 staff，用 type 區分主要身分）
		$persons_table = $wpdb->prefix . 'anime_persons';
		$persons_sql   = "CREATE TABLE IF NOT EXISTS {$persons_table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bgm_id         BIGINT(20) UNSIGNED NOT NULL,
			anilist_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			mal_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			name           VARCHAR(255) NOT NULL DEFAULT '',
			name_original  VARCHAR(255) NOT NULL DEFAULT '',
			gender         VARCHAR(20) NOT NULL DEFAULT '',
			birthday       VARCHAR(50) NOT NULL DEFAULT '',
			bloodtype      VARCHAR(20) NOT NULL DEFAULT '',
			height         VARCHAR(50) NOT NULL DEFAULT '',
			weight         VARCHAR(50) NOT NULL DEFAULT '',
			summary        LONGTEXT,
			aliases_json   LONGTEXT,
			infobox_json   LONGTEXT,
			image          TEXT,
			type           VARCHAR(20) NOT NULL DEFAULT 'cv',
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY bgm_id (bgm_id),
			KEY anilist_id (anilist_id),
			KEY mal_id (mal_id),
			KEY name (name),
			KEY type (type)
		) {$charset_collate};";
		dbDelta( $persons_sql );

		// 關聯表（作品 ↔ 角色 ↔ 聲優 / staff）
		$relations_table = $wpdb->prefix . 'anime_relations';
		$relations_sql   = "CREATE TABLE IF NOT EXISTS {$relations_table} (
			id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			anime_id          BIGINT(20) UNSIGNED NOT NULL,
			character_bgm_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			person_bgm_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			rel_type          VARCHAR(10) NOT NULL DEFAULT 'cast',
			role              VARCHAR(50) NOT NULL DEFAULT '',
			created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_rel (anime_id, character_bgm_id, person_bgm_id, role),
			KEY anime_id (anime_id),
			KEY character_bgm_id (character_bgm_id),
			KEY person_bgm_id (person_bgm_id),
			KEY rel_type (rel_type),
			KEY role (role)
		) {$charset_collate};";
		dbDelta( $relations_sql );
	}

	public function is_table_missing( string $table_name_without_prefix ): bool {
		global $wpdb;
		$full_name = $wpdb->prefix . $table_name_without_prefix;
		$result    = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name )
		);
		return $result !== $full_name;
	}

	// =========================================================================
	// 預設選項
	// =========================================================================

	private function set_default_options(): void {
		foreach ( $this->get_default_options() as $key => $value ) {
			if ( get_option( $key, '__not_set__' ) === '__not_set__' ) {
				add_option( $key, $value );
			}
		}
	}

	// =========================================================================
	// 上傳目錄
	// =========================================================================

	private function create_upload_dirs(): array {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
				Anime_Sync_Error_Logger::error(
					'Cannot create upload dirs: ' . $upload_dir['error']
				);
			}
			return [ 'created' => 0, 'failed' => 0, 'errors' => [ $upload_dir['error'] ] ];
		}

		$dirs = [
			$upload_dir['basedir'] . '/anime-sync-pro',
			$upload_dir['basedir'] . '/anime-covers',
			$upload_dir['basedir'] . '/anime-sync-cache',
		];

		$created = 0;
		$failed  = 0;
		$errors  = [];

		foreach ( $dirs as $dir ) {
			if ( file_exists( $dir ) ) {
				continue;
			}

			if ( ! wp_mkdir_p( $dir ) ) {
				$failed++;
				$errors[] = "Failed to create: {$dir}";
				continue;
			}

			$htaccess_path = $dir . '/.htaccess';
			$index_path    = $dir . '/index.php';

			if ( ! file_exists( $htaccess_path ) ) {
				@file_put_contents( $htaccess_path, "Options -Indexes\n" );
			}
			if ( ! file_exists( $index_path ) ) {
				@file_put_contents( $index_path, "<?php // Silence is golden.\n" );
			}

			$created++;
		}

		if ( $failed > 0 && class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::warning(
				"create_upload_dirs: {$failed} dir(s) failed",
				[ 'errors' => $errors ]
			);
		}

		return [
			'created' => $created,
			'failed'  => $failed,
			'errors'  => $errors,
		];
	}

	// =========================================================================
	// Rewrite 觸發旗標
	// =========================================================================

	private function register_cpt_for_flush(): void {
		update_option( 'anime_sync_flush_rewrite', 1 );
	}
}
