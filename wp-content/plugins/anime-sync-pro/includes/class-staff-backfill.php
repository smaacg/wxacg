<?php
/**
 * Staff Backfill — 把既有作品的 STAFF 補成完整名單。
 *
 * 2026-08-31 拿掉匯入端的職位白名單之後（class-api-handler.php 的
 * get_bgm_staff），新匯入與手動重新同步的作品會拿到完整名單，但既有
 * 1,751 部仍然只有白名單那 6-7 筆。這支負責把它們補齊。
 *
 * ★ 只寫 anime_staff_json 一個欄位，絕不驅動整包重新同步。
 *
 * 第一版是直接呼叫 ajax_resync_bangumi()，因為它「本來就是重新同步按鈕
 * 在用的、而且尊重欄位鎖」。那是錯的，而且會造成不可逆的損失：
 *
 *   它更新的是七組欄位——中文標題（繁＋簡）、中文簡介、封面圖、
 *   Bangumi 評分、工作人員、角色、集數——沒上鎖的一律覆蓋。
 *   實測正式站 1,760 部已發佈作品裡，只有 26 部鎖了中文標題。
 *   而站上 1,740 部裡有 994 部的標題與「Bangumi name_cn 經簡繁轉換」
 *   的結果不同，因為用的是台灣官方譯名：
 *     魔法帽的工作室 ← 尖帽子的魔法工房
 *     Dr.STONE 新石紀 ← 石紀元 科學與未來
 *     海盜戰記 ← 冰海戰記
 *     咒術迴戰 ← 咒術回戰
 *   跑下去等於用中國譯名覆蓋掉 1,700 多部的策展成果，而且沒有備份。
 *
 * 「有鎖機制」不等於「實際有鎖」。改成只呼叫 get_bgm_staff_public()，
 * 那支只打 /persons 一個端點，其餘六組欄位完全不碰。
 *
 * 節制：每 15 分鐘 8 部，跑完自動停。
 * 改成單一端點之後每部只剩一次 API 呼叫，比第一版快得多，
 * 但批量維持 8 部——2026-08 的事故是「單次進程持續 2-4 分鐘」加上高頻
 * 觸發造成進程堆疊，寧可慢一點。另有執行鎖堵死重疊（見 run()）。
 *
 * Changelog:
 *   1.0.0 (2026-08-31) — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Staff_Backfill {

	const HOOK        = 'anime_sync_staff_backfill';
	const OPTION_MODE = 'anime_sync_staff_backfill_mode';
	const OPTION_STAT = 'anime_sync_staff_backfill_stat';

	/** 每部作品補完就記一次，避免重跑 */
	const META_DONE = '_asp_staff_full';

	/** 空結果的重試次數累計，見 MAX_RETRY */
	const META_RETRY = '_asp_staff_retry';

	/**
	 * 拿到空結果幾次之後就放棄、標記完成。
	 *
	 * get_bgm_staff() 對「連線失敗」和「Bangumi 上真的沒有 staff」都回
	 * 空陣列，分不出來。不標記完成的話後者會永遠留在待補池裡：
	 * remaining() 不歸零 → finish() 不觸發 → 排程永遠不自動關閉，
	 * 每 15 分鐘為了那幾部白打 Bangumi，無限期。
	 *
	 * 規模是量出來的，不是猜的：拿本機 896 部比對 dump 的
	 * subject-persons.jsonlines（215 萬列），有 7 部完全沒有 staff 關聯，
	 * 逐一問線上 API 也都回 []。0.78%，正式站 1,751 部推估約 14 部。
	 *
	 * 3 次是「真的沒有」與「暫時連不上」的折衷：前者三輪後收斂，
	 * 後者仍有三次機會。額外成本 14 × 2 = 28 次呼叫，可以忽略。
	 */
	const MAX_RETRY = 3;

	/**
	 * 每輪幾部。
	 *
	 * 實測改成單一端點之後，8 部只要 24 秒（每部約 2.5 秒，其中 2 秒是
	 * SLEEP_BETWEEN 的自我節制，真正的 API 呼叫不到 0.5 秒）。第一版是
	 * 16 秒／部，快了六倍以上。
	 *
	 * BATCH 原本沿用第一版的 8，那是為「12 部 191 秒」調的數字，換掉
	 * 實作之後沒回頭重算。以每部 2.5 秒計，30 部約 75 秒：
	 *
	 *   8 部  → 單輪 24 秒、全站 1,751 部要 55 小時、API 0.5 次／分
	 *   30 部 → 單輪 75 秒、全站 15 小時、API 2 次／分
	 *
	 * 2026-08 的事故是 entity backfill 每 5 分鐘打 60 次（12 次／分）、
	 * 單次進程 2-4 分鐘造成堆疊。30 部是它的 1/6 頻率，75 秒也還在
	 * 兩分鐘門檻內，另有執行鎖堵死重疊（見 run()）。
	 */
	const BATCH = 30;

	/** 15 分鐘排程，由 Anime_Sync_Cron_Manager 註冊 */
	const SCHEDULE = 'anime_sync_quarter_hour';

	/* 同一輪內每部之間的間隔秒數 */
	const SLEEP_BETWEEN = 2;

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

	/**
	 * 還有幾部沒補。
	 */
	public static function remaining(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} b
			     ON b.post_id = p.ID AND b.meta_key = 'anime_bangumi_id' AND b.meta_value > 0
			 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->postmeta} d
			       WHERE d.post_id = p.ID AND d.meta_key = '" . self::META_DONE . "'
			   )"
		);
	}

	public function run(): void {
		if ( 'on' !== get_option( self::OPTION_MODE, 'off' ) ) {
			return;
		}

		/*
		 * 執行鎖。
		 *
		 * 單輪只要 20 秒，15 分鐘的間隔照理不可能重疊。但 Bangumi 逾時
		 * 或速率限制排隊的時候單輪可能拖很久，那就會變成兩個進程同時在
		 * 打同一批作品。
		 *
		 * 2026-08 的事故正是「單次進程持續 2-4 分鐘」加上高頻觸發造成的
		 * 進程堆疊。間隔已經拉到 15 分鐘，再加一道鎖就堵死重疊的可能。
		 * TTL 給 20 分鐘：比一輪的預期耗時長很多，但短於兩次排程的間隔，
		 * 萬一進程被中斷也不會卡住下一輪太久。
		 */
		if ( get_transient( 'anime_sync_lock_staff_backfill' ) ) {
			return;
		}

		set_transient( 'anime_sync_lock_staff_backfill', 1, 20 * MINUTE_IN_SECONDS );

		try {
			$this->run_batch();
		} finally {
			delete_transient( 'anime_sync_lock_staff_backfill' );
		}
	}

	private function run_batch(): void {
		global $wpdb;

		/*
		 * 挑還沒補的。用 postmeta 標記而不是比對 staff 筆數——筆數少不代表
		 * 沒補過，有些作品在 Bangumi 上本來就只有 1 位 staff（抽樣實測有
		 * 兩部是 1 筆）。標記才分得出「補過了」與「補了但就是少」。
		 */
		$targets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS post_id, b.meta_value AS bgm
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} b
				     ON b.post_id = p.ID AND b.meta_key = 'anime_bangumi_id' AND b.meta_value > 0
				 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
				   AND NOT EXISTS (
				       SELECT 1 FROM {$wpdb->postmeta} d
				       WHERE d.post_id = p.ID AND d.meta_key = %s
				   )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				self::META_DONE,
				self::BATCH
			),
			ARRAY_A
		);

		if ( empty( $targets ) ) {
			$this->finish();

			return;
		}

		$handler = $this->make_handler();

		if ( ! $handler ) {
			return;
		}

		$done    = 0;
		$failed  = 0;
		$total   = 0;
		$skipped = 0;
		$empty   = 0;

		foreach ( $targets as $t ) {
			$post_id = (int) $t['post_id'];
			$bgm     = (int) $t['bgm'];

			/*
			 * 欄位鎖：作者勾了「不自動更新」就跳過，並且標記成處理過——
			 * 那是明確的意思表示，不該每輪再問一次 Bangumi。
			 */
			$locked = (array) get_post_meta( $post_id, 'anime_locked_fields', true );

			if ( in_array( 'anime_staff_json', $locked, true ) ) {
				update_post_meta( $post_id, self::META_DONE, current_time( 'mysql' ) );
				$skipped++;

				continue;
			}

			$staff = $handler->get_bgm_staff_public( $bgm );

			/*
			 * 不留 transient。
			 *
			 * get_bgm_staff() 內部會把結果快取 12 小時，那是為後台「重新
			 * 同步 Bangumi」按鈕設計的——同一部可能被連按好幾次。回補路徑
			 * 每部只讀一次，那份快取完全用不到，資料已經寫進 postmeta 了。
			 *
			 * 留著的代價：實測平均 39.2 KB／筆，全站跑完 wp_options 會從
			 * 4.8 MB 撐到約 72 MB（14 倍），而真正該存的 postmeta 只有
			 * 3.6 MB。在一台已經有負載問題的共享主機上這是白付的。
			 */
			delete_transient( 'anime_sync_bgm_staff_' . $bgm );

			if ( empty( $staff ) ) {
				/*
				 * 空的分兩種：連線失敗，或這部在 Bangumi 上真的沒有 staff，
				 * 而 get_bgm_staff() 兩種都回空陣列。累計到 MAX_RETRY 就
				 * 標記完成，否則後者會卡住收斂（見 MAX_RETRY 的說明）。
				 */
				$retry = (int) get_post_meta( $post_id, self::META_RETRY, true ) + 1;

				if ( $retry >= self::MAX_RETRY ) {
					update_post_meta( $post_id, self::META_DONE, current_time( 'mysql' ) );
					delete_post_meta( $post_id, self::META_RETRY );
					$empty++;
				} else {
					update_post_meta( $post_id, self::META_RETRY, $retry );
					$failed++;
				}

				continue;
			}

			update_post_meta(
				$post_id,
				'anime_staff_json',
				/* wp_slash：update_post_meta 內部會 wp_unslash，不補會吃掉 JSON 的引號跳脫 */
				wp_slash( wp_json_encode( $staff, JSON_UNESCAPED_UNICODE ) )
			);

			update_post_meta( $post_id, self::META_DONE, current_time( 'mysql' ) );

			/* 之前失敗過的把計數清掉，不要留在 postmeta 裡 */
			delete_post_meta( $post_id, self::META_RETRY );

			$total += count( $staff );
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
				'last_fail'  => $failed,
				'last_lock'  => $skipped,
				'last_empty' => $empty,
				'last_staff' => $total,
				'remaining'  => self::remaining(),
			],
			false
		);
	}

	/**
	 * 組出 API handler。
	 *
	 * 這幾個依賴在前台請求裡不會被實例化（見主檔的 is_admin() 判斷），
	 * 而 wp-cron 正是由前台請求觸發的，所以這裡自己組一份。
	 */
	private function make_handler(): ?Anime_Sync_API_Handler {
		if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) {
			return null;
		}

		$rate = class_exists( 'Anime_Sync_Rate_Limiter' )
			? Anime_Sync_Rate_Limiter::get_instance()
			: null;

		$mapper = class_exists( 'Anime_Sync_ID_Mapper' )
			? new Anime_Sync_ID_Mapper( $rate )
			: null;

		return new Anime_Sync_API_Handler( $rate, $mapper );
	}

	/**
	 * 補完了：關掉自己。一次性作業不是常駐服務。
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
			Anime_Sync_Error_Logger::info( 'STAFF 完整名單回補完成，已自動停止排程' );
		}
	}
}
