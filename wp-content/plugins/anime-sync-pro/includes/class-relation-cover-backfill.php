<?php
/**
 * 關聯封面回補 — 把 Bangumi 的封面「網址」寫進 wp_wxacg_subject_relations。
 *
 * 存網址不存檔案。實測（2026-08-30，正式站主機與本機各量一輪）：
 *   lain.bgm.tv   TTFB 48–57ms
 *   本站 CF 邊緣   TTFB 52–64ms（cf-cache-status: HIT，台北節點）
 * 兩邊速度相同，下載 43,344 張圖（人物 10,797 + 角色 26,698 + 專輯 5,849）
 * 換不到速度，只換來 1.7GB、inode、備份與失效重抓的維護成本。
 * STAFF／CAST 的頭像本來就是這個做法（網址存在 anime_staff_json，
 * 圖片由 Bangumi CDN 送），這裡只是讓專輯／遊戲／真人版跟上。
 *
 * 為什麼一次抓一部作品，而不是一張專輯一次：
 *
 *   /v0/subjects/{id}/subjects 一次回傳該作品**所有**關聯，而且每筆都帶
 *   images。以作品為單位只要 1,264 次呼叫；以專輯為單位要 5,849 次。
 *   而且同一次回應裡連遊戲、三次元的封面都拿到了。
 *   最壞情況實測（subject 975）：205 筆 / 95KB 一次拿完，不分頁。
 *
 * 為什麼不用 Bangumi Archive dump：
 *   官方 README 的 Subject 欄位表沒有圖片欄位
 *  （id/type/name/name_cn/infobox/platform/summary/nsfw/date/favorite/
 *    series/tags/score/score_details/rank/meta_tags）。
 *
 * 節制：每 15 分鐘 20 部，約 1,900 次/天，64 輪（約 16 小時）跑完後
 * **自動停止**。這是刻意設計——2026-08 的實體回補 cron 是每 5 分鐘 60 次
 *（約 17,000 次/天）且常駐，把正式站 load average 推到 22.7、Cloudflare
 * 開始回 522/525，最後只能緊急關閉。這支是它的 1/9，而且會收斂。
 *
 * Changelog:
 *   1.0.0 (2026-08-30) — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Relation_Cover_Backfill {

	const HOOK        = 'anime_sync_relcover_backfill';
	const OPTION_MODE = 'anime_sync_relcover_mode';
	const OPTION_STAT = 'anime_sync_relcover_stat';

	/** 每輪處理幾部作品。改大之前先看檔頭關於負載的說明。 */
	const BATCH = 20;

	/** 15 分鐘排程，由 Anime_Sync_Cron_Manager 註冊，這裡直接用 */
	const SCHEDULE = 'anime_sync_quarter_hour';

	const API_TMPL = 'https://api.bgm.tv/v0/subjects/%d/subjects';

	/* 同一輪內每部作品之間的間隔秒數，別讓 20 次呼叫擠在同一秒 */
	const SLEEP_BETWEEN = 1;

	public function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );

		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}

		wp_clear_scheduled_hook( self::HOOK );
	}

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wxacg_subject_relations';
	}

	/**
	 * 還有幾部作品沒補完。給後台顯示進度，也給 run() 判斷要不要收工。
	 */
	public function remaining(): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id)
			 FROM {$table}
			 WHERE cover_url IS NULL AND source_bgm_id > 0"
		);
	}

	public function run(): void {
		if ( 'on' !== get_option( self::OPTION_MODE, 'off' ) ) {
			return;
		}

		global $wpdb;

		$table = $this->table();

		/*
		 * 挑還有 NULL 的作品。GROUP BY 而不是 DISTINCT，是因為要一併帶出
		 * source_bgm_id——它就是母作品的 Bangumi subject id，不必再查 postmeta。
		 */
		$targets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, MAX(source_bgm_id) AS bgm
				 FROM {$table}
				 WHERE cover_url IS NULL AND source_bgm_id > 0
				 GROUP BY post_id
				 ORDER BY post_id ASC
				 LIMIT %d",
				self::BATCH
			),
			ARRAY_A
		);

		if ( empty( $targets ) ) {
			$this->finish();

			return;
		}

		$done   = 0;
		$filled = 0;
		$failed = 0;

		foreach ( $targets as $t ) {
			$r = $this->backfill_post( (int) $t['post_id'], (int) $t['bgm'] );

			if ( false === $r['ok'] ) {
				$failed++;
				continue;
			}

			$filled += $r['filled'];
			$done++;

			if ( self::SLEEP_BETWEEN > 0 ) {
				sleep( self::SLEEP_BETWEEN );
			}
		}

		update_option(
			self::OPTION_STAT,
			[
				'last_run'   => current_time( 'mysql' ),
				'last_done'  => $done,
				'last_fill'  => $filled,
				'last_fail'  => $failed,
				'remaining'  => $this->remaining(),
			],
			false
		);
	}

	/**
	 * 補單一作品。
	 *
	 * 公開方法：批次回補用它，之後後台的「重試這一部」與新作品匯入完
	 * 補一次也用它——只有一個地方知道怎麼補，不會兩套邏輯各自演化。
	 *
	 * @param int $bgm_id 母作品的 Bangumi subject id。傳 0 會自己去表裡查。
	 * @return array{ok:bool, filled:int, rows:int}
	 */
	public function backfill_post( int $post_id, int $bgm_id = 0 ): array {
		global $wpdb;

		$table = $this->table();

		if ( $bgm_id <= 0 ) {
			$bgm_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(source_bgm_id) FROM {$table} WHERE post_id = %d",
					$post_id
				)
			);
		}

		if ( $bgm_id <= 0 ) {
			return [ 'ok' => false, 'filled' => 0, 'rows' => 0 ];
		}

		$map = $this->fetch_cover_map( $bgm_id );

		/*
		 * 取不到就什麼都不寫——這一部下一輪會再排到。
		 * 不寫 '' 的理由：'' 的語意是「Bangumi 說沒有這張圖」，
		 * 拿它來標記「這次連線失敗」會讓本來有圖的條目被永久誤判成無圖。
		 */
		if ( null === $map ) {
			return [ 'ok' => false, 'filled' => 0, 'rows' => 0 ];
		}

		return [ 'ok' => true ] + $this->apply_map( $post_id, $map );
	}

	/**
	 * 取某作品的關聯，整理成 bgm_id => 封面網址。
	 *
	 * @return array<int,string>|null null 代表這次取失敗（不是「沒有封面」）
	 */
	private function fetch_cover_map( int $bgm_id ): ?array {
		$res = wp_remote_get(
			sprintf( self::API_TMPL, $bgm_id ),
			[
				'timeout' => 20,
				'headers' => [
					/* Bangumi 要求標明來源，不帶會被擋 */
					'User-Agent' => 'weixiaoacg/1.0 (+https://weixiaoacg.com)',
					'Accept'     => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			error_log(
				'[anime-sync-pro] relcover 連線失敗 bgm=' . $bgm_id
				. ': ' . $res->get_error_message()
			);

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( 200 !== $code ) {
			error_log( '[anime-sync-pro] relcover HTTP ' . $code . ' bgm=' . $bgm_id );

			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $data ) ) {
			error_log( '[anime-sync-pro] relcover 格式不符 bgm=' . $bgm_id );

			return null;
		}

		$map = [];

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			/*
			 * 取 common（/r/400/，實測 29KB）。
			 * 另外兩個尺寸：/r/100/ 3.7KB、原圖 73KB。
			 * 400px 對之後要做的封面牆剛好，當 48px 縮圖略大但可接受；
			 * 存原圖再由前端自己拼 /r/400/ 會依賴對方的網址規則，不划算。
			 */
			$map[ (int) $row['id'] ] = isset( $row['images'] ) && is_array( $row['images'] )
				? (string) ( $row['images']['common'] ?? $row['images']['large'] ?? '' )
				: '';
		}

		return $map;
	}

	/**
	 * 把封面寫回該作品的所有關聯列。
	 *
	 * 回應裡沒出現的 bgm_id 一律寫 ''（不是留 NULL）——那代表 Bangumi 這邊
	 * 已經沒有這筆關聯或它沒有圖，留 NULL 會讓這一部永遠排在待補佇列裡，
	 * 回補就收斂不了。
	 *
	 * @param array<int,string> $map
	 * @return array{filled:int, rows:int}
	 */
	private function apply_map( int $post_id, array $map ): array {
		global $wpdb;

		$table = $this->table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, bgm_id FROM {$table} WHERE post_id = %d AND cover_url IS NULL",
				$post_id
			),
			ARRAY_A
		);

		$filled = 0;

		foreach ( $rows as $row ) {
			$url = $map[ (int) $row['bgm_id'] ] ?? '';

			$wpdb->update(
				$table,
				[ 'cover_url' => $url ],
				[ 'id' => (int) $row['id'] ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( '' !== $url ) {
				$filled++;
			}
		}

		return [ 'filled' => $filled, 'rows' => count( $rows ) ];
	}

	/**
	 * 補完了：關掉自己。
	 *
	 * 這支是一次性作業不是常駐服務，跑完就該消失。新增作品由匯入流程
	 * 帶進來的列會是 NULL，屆時把 mode 打開再跑一輪即可。
	 */
	private function finish(): void {
		update_option( self::OPTION_MODE, 'off' );

		self::unschedule();

		update_option(
			self::OPTION_STAT,
			[
				'last_run'  => current_time( 'mysql' ),
				'finished'  => 1,
				'remaining' => 0,
			],
			false
		);

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info( '關聯封面回補完成，已自動停止排程' );
		}
	}
}
