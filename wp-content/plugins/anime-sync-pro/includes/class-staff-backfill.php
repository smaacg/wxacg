<?php
/**
 * Staff Backfill — 把既有作品的 STAFF 補成完整名單。
 *
 * 2026-08-31 拿掉匯入端的職位白名單之後（class-api-handler.php 的
 * get_bgm_staff），新匯入與手動重新同步的作品會拿到完整名單，但既有
 * 1,751 部仍然只有白名單那 6-7 筆。這支負責把它們補齊。
 *
 * 直接驅動既有的 ajax_resync_bangumi()，不另寫抓取邏輯：那個方法本來就
 * 是「重新同步 Bangumi」按鈕在用的，會更新 staff 並尊重 anime_locked_fields
 * 的欄位鎖。重寫一份等於多一條會慢慢長歪的路徑。
 *
 * 節制：每 15 分鐘 8 部，跑完自動停。1,751 部約 55 小時。
 *
 * 比封面回補的 20 部保守很多，因為單部成本高得多——實測每部約 16 秒
 *（ajax_resync_bangumi 每部要打好幾次 Bangumi，加上速率限制的等待），
 * 12 部一輪要 191 秒。8 部讓單輪落在兩分鐘以內。
 *
 * 那個取捨是刻意的：2026-08 的事故是「單次進程持續 2-4 分鐘」加上每 5
 * 分鐘觸發造成進程堆疊。補完的總時間晚一天沒差，進程長度才是會咬人的。
 * 另外加了執行鎖，堵死重疊的可能（見 run()）。
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

	/**
	 * 每輪幾部。
	 *
	 * 實測 12 部要 191 秒（每部約 16 秒——ajax_resync_bangumi 每部要打
	 * 好幾次 Bangumi，加上速率限制的等待）。降到 8 部讓單輪落在兩分鐘
	 * 以內，代價是全站補完從 36 小時變成 55 小時。
	 *
	 * 這個取捨是刻意的：2026-08 的事故就是「單次進程持續 2-4 分鐘」，
	 * 補完的總時間晚一天沒有差別，進程長度才是會咬人的那個。
	 */
	const BATCH = 8;

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
		 * 實測單輪 12 部要 191 秒——ajax_resync_bangumi() 每部要打好幾次
		 * Bangumi（subject／persons／characters／episodes），加上速率限制的
		 * 等待。15 分鐘的間隔不會重疊，但網路慢的時候有可能拖過去，
		 * 那就會變成兩個進程同時在打同一批作品。
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

		$done   = 0;
		$failed = 0;
		$total  = 0;

		foreach ( $targets as $t ) {
			$post_id = (int) $t['post_id'];
			$bgm     = (int) $t['bgm'];

			$result = $handler->ajax_resync_bangumi( $post_id, $bgm );

			if ( is_wp_error( $result ) ) {
				/*
				 * 失敗不標記，下一輪會再排到。不像「查無資料」那種需要
				 * 永久跳過——這裡每一部在 Bangumi 上都存在，失敗多半是
				 * 連線問題，重試會成功。
				 */
				$failed++;

				error_log(
					'[anime-sync-pro] staff 回補失敗 post=' . $post_id
					. ' bgm=' . $bgm . ': ' . $result->get_error_message()
				);

				continue;
			}

			update_post_meta( $post_id, self::META_DONE, current_time( 'mysql' ) );

			$staff = json_decode( (string) get_post_meta( $post_id, 'anime_staff_json', true ), true );
			$total += is_array( $staff ) ? count( $staff ) : 0;
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
