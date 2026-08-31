<?php
/**
 * 關聯回補 — 把 Bangumi 的跨媒體關聯與封面網址寫進 wp_wxacg_subject_relations。
 *
 * 做兩件事，因為資料來自同一次 API 回應：
 *   1. 補封面網址（既有列的 cover_url 是 NULL）
 *   2. 補整組關聯列（表裡一列都沒有的作品）
 *
 * 為什麼會有「一列都沒有」的作品：那張表原本只有外部腳本
 * scratchpad/bgm/relations.php 會寫，匯入流程完全沒碰它，所以腳本跑過
 * 之後才進站的作品從頭到尾沒有任何一列（實測正式站 162 部）。
 * 這支就是來治這個根的——新作品由 save_post_anime 觀察者自動排程補齊，
 * 匯入流程一行都不用改。
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
 * 節制：每 15 分鐘 20 部，上限約 1,900 次/天。2026-08 的實體回補 cron 是
 * 每 5 分鐘 60 次（約 17,000 次/天），把正式站 load average 推到 22.7、
 * Cloudflare 開始回 522/525，最後只能緊急關閉。這支是它的 1/9。
 *
 * ★ 1.2.0 起這支不再「跑完自動停止」，改成常駐的定期同步（RESYNC_DAYS）。
 *
 * 原本的設計是一次性回補 + save_post 觀察者顧新作品，跑完就關掉排程。
 * 那個假設是「關聯抓一次就固定了」，但實測不成立：拿官方 dump 算全站
 * 30,284 組動畫→音樂關聯，71% 的專輯在動畫開播 30 天後才發行、47% 在
 * 一季播完之後才發行、16% 超過一年。抓一次就凍結等於長期缺一半專輯。
 *
 * 穩態負載是每天約 252 次（1,761 部 ÷ 7 天），只有上面那個上限的 13%，
 * 所以常駐並不會把當初「會收斂」的安全性讓出去。詳見 is_stale()。
 *
 * Changelog:
 *   1.2.0 (2026-08-31) — 加入 RESYNC_DAYS 定期重新同步；finish() 改為
 *                        idle()（不再自動關閉排程）。
 *   1.1.0 (2026-08-30) — 補關聯列（原本只補封面）；新增 save_post 觀察者。
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

	const API_TMPL     = 'https://api.bgm.tv/v0/subjects/%d/subjects';
	const SUBJECT_TMPL = 'https://api.bgm.tv/v0/subjects/';

	/* 新作品存檔後補這一部（單次事件，見 watch_save） */
	const HOOK_ONE = 'anime_sync_relcover_one';

	/*
	 * 「這部已經問過 Bangumi 了」的標記。
	 *
	 * 少了它回補就不會收斂：Bangumi 上真的沒有任何關聯的作品，處理完
	 * 仍然是零列，下一輪又會被「找沒有關聯列的作品」撈出來，永遠跑不完。
	 * 跟 cover_url 的 NULL／'' 是同一個道理——要分得出「還沒問」和
	 * 「問過了，對方就是沒有」。
	 */
	const META_DONE = '_asp_relcover_done';

	/*
	 * 需要額外取 platform 的型別：遊戲(4) 與三次元(6)。
	 *
	 * 這兩種的分組靠 platform（4001 遊戲／4005 桌遊、6002 電影／6003
	 * 舞台劇），但關聯端點沒有這個欄位，只能對每筆再打一次
	 * /v0/subjects/{id}。其他型別不需要，也就不多花那次呼叫。
	 *
	 * 附帶一提：回應裡**每一種**型別都會寫進表裡，不只前台在讀的三種。
	 * 既有資料裡書籍(1) 2,413 列、動畫(2) 2,745 列都在（外部腳本灌的），
	 * 新作品只寫三種會讓同一張表出現兩種形狀，日後很難追。
	 */
	const PLATFORM_TYPES = [ 4, 6 ];

	/* 同一輪內每部作品之間的間隔秒數，別讓 20 次呼叫擠在同一秒 */
	const SLEEP_BETWEEN = 1;

	/**
	 * 每部作品多久重新問一次 Bangumi（天）。
	 *
	 * 理由與負載試算見 is_stale()。要調快調慢改這個數字就好，
	 * 邏輯不必動；改小會線性增加每日 API 次數（1,761 ÷ 天數）。
	 */
	const RESYNC_DAYS = 7;

	/*
	 * 單次補的執行鎖存活秒數。
	 *
	 * 一次單次補最壞情況是 1 次關聯呼叫 + 幾次 platform 呼叫，抓 30 秒
	 * 綽綽有餘。設太長會讓正常的「一部一部匯入」被誤判成擁塞而丟給批次
	 *（結果還是會補到，只是慢一點）；設太短則擋不住 WP-Cron 一次跑完
	 * 上百個事件的情況。
	 */
	const LOCK_TTL = 30;

	public function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );

		/*
		 * 新作品自動補齊，不必等人手動開回補。
		 *
		 * 掛 save_post 是觀察者，匯入流程一行都不用改——匯入用的是
		 * wp_insert_post / wp_update_post（class-import-manager.php:202-204），
		 * 所以一定會觸發。
		 */
		add_action( 'save_post_anime', [ $this, 'watch_save' ], 20, 3 );
		add_action( self::HOOK_ONE, [ $this, 'run_one' ] );
	}

	/**
	 * 作品存檔 → 缺資料的話排一個單次事件補它。
	 *
	 * 為什麼是「延遲執行」而不是當場補：
	 *
	 *   1. save_post 觸發時 anime_bangumi_id 這個 postmeta 可能還沒寫入。
	 *      匯入器是 wp_insert_post() 之後才 update_post_meta()
	 *     （class-import-manager.php:215/234/235），連 wp_after_insert_post
	 *      都還是太早。延遲執行就繞開這個順序問題，不必去猜對方的寫入順序。
	 *   2. 匯入當下打外部 API 會拖慢匯入，失敗還要處理重試。
	 *   3. 一次匯入幾百部時，隨機分散避免瞬間幾百次 API。
	 *
	 * 相同 hook + 參數 WP 本來就會去重，不必自己防重複排程。
	 */
	public function watch_save( $post_id, $post, $update ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		/*
		 * 檢查放在這裡而不是處理器裡：已經補齊的作品重新編輯時連事件
		 * 都不產生，省下整條 cron 路徑。
		 */
		if ( ! $this->needs_backfill( (int) $post_id ) ) {
			return;
		}

		wp_schedule_single_event(
			time() + 60 + wp_rand( 0, 540 ),
			self::HOOK_ONE,
			[ (int) $post_id ]
		);
	}

	/**
	 * 這部作品還需不需要補。
	 *
	 * 三種需要：一列都沒有（且沒問過）、有列但封面還是 NULL、
	 * 或距離上次同步已經超過 RESYNC_DAYS。
	 */
	private function needs_backfill( int $post_id ): bool {
		global $wpdb;

		$table = $this->table();

		$stat = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
				        SUM(cover_url IS NULL) AS pending,
				        MAX(synced_at) AS last_sync
				 FROM {$table} WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);

		$total   = (int) ( $stat['total'] ?? 0 );
		$pending = (int) ( $stat['pending'] ?? 0 );

		if ( 0 === $total ) {
			/*
			 * 從沒抓到任何關聯。
			 *
			 * 問過一次就不再問——這種作品在 Bangumi 上本來就沒有關聯條目，
			 * 每輪重問只是白打。真的之後長出來了，靠 save_post 觀察者
			 * 或人工重新同步處理。
			 */
			return '' === (string) get_post_meta( $post_id, self::META_DONE, true );
		}

		if ( $pending > 0 ) {
			return true;
		}

		return $this->is_stale( (string) ( $stat['last_sync'] ?? '' ) );
	}

	/**
	 * 上次同步是不是已經過期。
	 *
	 * 為什麼需要定期重問（原本抓完就凍結）：
	 *
	 *   專輯不是跟動畫同時存在的。拿 Bangumi 官方 dump 算過全站
	 *   30,284 組「動畫→音樂」關聯，專輯發行日相對於動畫開播日：
	 *     播出前 12.6% ／ 0-30 天 16.2% ／ 31-90 天 24.2%
	 *     91-180 天 17.9% ／ 181-365 天 13.0% ／ 超過一年 16.1%
	 *   也就是 71% 的專輯在開播 30 天後才出現、47% 在一季播完後才出現。
	 *   抓一次就不再問，等於長期缺一半的專輯。
	 *   而且有 17% 的作品在開播兩年後還會長出新專輯（平均 2.4 張），
	 *   所以不能「舊番就停掃」。
	 *
	 * 為什麼是 7 天：
	 *
	 *   Bangumi 官方 dump 是每週發布，7 天已經是那份資料本身的新鮮度上限，
	 *   再快沒有意義。而用輪詢達到同樣的新鮮度，比解析 dump 的 ZIP
	 *   少寫兩百行，也不必多一個外部依賴。
	 *
	 * 負載（1,761 部 ÷ 7 天 ≈ 每天 252 次）：
	 *   Rate_Limiter 對 bangumi 壓在 1 req/s（理論上限 86,400/天），這是 0.3%；
	 *   2026-08-28 那次全站回補實跑約 1,920 次/天，這是它的 13%。
	 *   對方也沒有節流跡象：連打 10 次全部 200、無 rate limit 標頭，
	 *   且 API 在 Cloudflare 快取後面。
	 */
	private function is_stale( string $last_sync ): bool {
		if ( '' === $last_sync ) {
			/* 舊資料沒有 synced_at，當成過期，補一次就會寫上 */
			return true;
		}

		$ts = strtotime( $last_sync );

		if ( ! $ts ) {
			return true;
		}

		/*
		 * 時區：synced_at 是用 current_time( 'mysql' ) 寫的，也就是站台
		 * 當地時間（Asia/Taipei，UTC+8），不是 UTC。strtotime() 在 WP 底下
		 * 以 UTC 解讀，所以要跟同樣基準的 current_time( 'timestamp' ) 比，
		 * 拿 time() 比會差 8 小時——不會壞掉，但每筆都會晚 8 小時才判定過期。
		 */
		return ( current_time( 'timestamp' ) - $ts ) > ( self::RESYNC_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * 單次事件的處理器。
	 *
	 * 有一道擁塞閘：同一時間只讓一個單次補實際執行，擠在一起的那些
	 * 改成打開批次回補，由它以每 15 分鐘 20 部的速度慢慢消化。
	 *
	 * 為什麼需要這道閘——WP-Cron 會在**一次請求裡跑完所有到期的事件**。
	 * 一次發佈幾百部草稿（站上實測有 556 部 anime 草稿，519 部有 bgm_id
	 * 且全部沒有關聯列），就會有上百個事件在同一個窗口到期，變成單一個
	 * PHP 進程連續打上百次 Bangumi API。那正是 2026-08 事故的形狀。
	 *
	 * 擠不進來的不重排也不丟掉：批次回補的佇列條件本來就涵蓋它們
	 *（沒有關聯列 或 cover_url IS NULL），下一輪自然會輪到。
	 * 重排幾百個事件只會製造大量沒有意義的 option 寫入。
	 */
	public function run_one( $post_id ): void {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return;
		}

		/* 短鎖：拿不到就代表這一波不只一部，走批次 */
		if ( ! $this->acquire_lock() ) {
			$this->switch_to_batch();

			return;
		}

		$this->backfill_post( $post_id );
	}

	/**
	 * 取得單次補的執行鎖。
	 *
	 * 用 add_option 的原子性做鎖（autoload=no）。transient 在有物件快取
	 * 的環境下 set 不是原子的，兩個同時到期的事件可能都拿到鎖。
	 */
	private function acquire_lock(): bool {
		$key = 'anime_sync_relcover_lock';
		$now = time();

		/* 過期的鎖要能被搶走，否則一次逾時就永遠卡住 */
		$held = (int) get_option( $key, 0 );

		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			return false;
		}

		if ( $held > 0 ) {
			update_option( $key, $now, false );

			return true;
		}

		return add_option( $key, $now, '', false );
	}

	/**
	 * 大量待補時改走批次回補。
	 *
	 * 批次本來就是為這種量設計的（每 15 分鐘 20 部、補完自動停），
	 * 不必另外寫一套限流。
	 */
	private function switch_to_batch(): void {
		if ( 'on' === get_option( self::OPTION_MODE, 'off' ) ) {
			return;
		}

		update_option( self::OPTION_MODE, 'on' );

		self::schedule();

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info(
				'單次補擁塞，已自動開啟批次關聯回補'
			);
		}
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

		/*
		 * 兩種待補：① 有關聯列但封面還沒補 ② 一列都沒有（外部腳本跑過
		 * 之後才進站的作品）。② 在表裡查不到，只能從 wp_posts 那邊找。
		 */
		$no_cover = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id)
			 FROM {$table}
			 WHERE cover_url IS NULL AND source_bgm_id > 0"
		);

		$no_rows = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m
			     ON m.post_id = p.ID AND m.meta_key = 'anime_bangumi_id' AND m.meta_value > 0
			 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
			   AND NOT EXISTS (
			       SELECT 1 FROM {$table} r WHERE r.post_id = p.ID
			   )
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->postmeta} d
			       WHERE d.post_id = p.ID AND d.meta_key = '_asp_relcover_done'
			   )"
		);

		/* 第三段：資料完整但已過期，等著重新同步的（見 is_stale()） */
		$stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
				     SELECT post_id
				     FROM {$table}
				     WHERE source_bgm_id > 0
				     GROUP BY post_id
				     HAVING MAX(synced_at) < %s OR MAX(synced_at) IS NULL
				 ) x",
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::RESYNC_DAYS * DAY_IN_SECONDS )
			)
		);

		/*
		 * $no_cover 與 $stale 可能重疊（缺封面的那批也可能已經過期），
		 * 這裡不去重——這個數字是給後台看進度的概數，寧可略高也不要
		 * 為它多跑一次昂貴的 DISTINCT。
		 */
		return $no_cover + $no_rows + $stale;
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

		/*
		 * 補完「有列缺封面」的之後，才輪到「一列都沒有」的。
		 *
		 * 分兩段查而不是 UNION：前者只掃關聯表（有索引、很快），後者要
		 * JOIN wp_posts + postmeta。先做便宜的，等它清空了那個貴的查詢
		 * 一輪也只跑一次。
		 */
		if ( count( $targets ) < self::BATCH ) {
			$need = self::BATCH - count( $targets );

			$orphans = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS post_id, m.meta_value AS bgm
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m
					     ON m.post_id = p.ID AND m.meta_key = 'anime_bangumi_id' AND m.meta_value > 0
					 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
					   AND NOT EXISTS (
					       SELECT 1 FROM {$table} r WHERE r.post_id = p.ID
					   )
					   AND NOT EXISTS (
					       SELECT 1 FROM {$wpdb->postmeta} d
					       WHERE d.post_id = p.ID AND d.meta_key = '_asp_relcover_done'
					   )
					 ORDER BY p.ID ASC
					 LIMIT %d",
					$need
				),
				ARRAY_A
			);

			$targets = array_merge( $targets, $orphans );
		}

		/*
		 * 第三段：資料完整但已經過期的，重新問一次（見 is_stale()）。
		 *
		 * 排在最後是刻意的優先序——「缺封面」「沒關聯列」是明確的缺口，
		 * 補起來讓畫面立刻正確；定期重新同步只是為了跟上新發行的專輯，
		 * 晚一輪沒有差別。前兩段清空之後這段才會吃到配額。
		 *
		 * 依 synced_at 由舊到新排：最久沒問的先問，輪替才會平均。
		 */
		if ( count( $targets ) < self::BATCH ) {
			$need = self::BATCH - count( $targets );

			$picked = array_map( 'intval', wp_list_pluck( $targets, 'post_id' ) );
			$not_in = $picked ? ' AND post_id NOT IN (' . implode( ',', $picked ) . ')' : '';

			$stale = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, MAX(source_bgm_id) AS bgm
					 FROM {$table}
					 WHERE source_bgm_id > 0 {$not_in}
					 GROUP BY post_id
					 HAVING MAX(synced_at) < %s OR MAX(synced_at) IS NULL
					 ORDER BY MAX(synced_at) ASC
					 LIMIT %d",
					gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::RESYNC_DAYS * DAY_IN_SECONDS ),
					$need
				),
				ARRAY_A
			);

			$targets = array_merge( $targets, $stale );
		}

		if ( empty( $targets ) ) {
			$this->idle();

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
		$fail  = [ 'ok' => false, 'filled' => 0, 'rows' => 0, 'added' => 0 ];

		if ( $bgm_id <= 0 ) {
			$bgm_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(source_bgm_id) FROM {$table} WHERE post_id = %d",
					$post_id
				)
			);
		}

		/*
		 * 表裡一列都沒有的作品（實測正式站 162 部）自然也沒有 source_bgm_id，
		 * 退回 postmeta 找。這正是要治的根：關聯資料只有外部腳本會寫，
		 * 腳本跑過之後才進站的作品從頭到尾沒有任何一列。
		 */
		if ( $bgm_id <= 0 ) {
			$bgm_id = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
		}

		if ( $bgm_id <= 0 ) {
			return $fail;
		}

		$items = $this->fetch_relations( $bgm_id );

		/*
		 * 取不到就什麼都不寫——這一部下一輪會再排到。
		 * 不寫 '' 的理由：'' 的語意是「Bangumi 說沒有這張圖」，
		 * 拿它來標記「這次連線失敗」會讓本來有圖的條目被永久誤判成無圖。
		 */
		if ( null === $items ) {
			return $fail;
		}

		$result = $this->apply_map( $post_id, $bgm_id, $items );

		/* 問過了就記下來——包括「對方回空陣列」的情況，否則永遠重排 */
		update_post_meta( $post_id, self::META_DONE, current_time( 'mysql' ) );

		return [ 'ok' => true ] + $result;
	}

	/**
	 * 取某作品的關聯原始清單。
	 *
	 * @return array<int,array>|null 以 bgm_id 為鍵；null 代表這次取失敗
	 *                               （不是「沒有關聯」——那是空陣列）
	 */
	private function fetch_relations( int $bgm_id ): ?array {
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

		$out = [];

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			/*
			 * 封面取 common（/r/400/，實測 29KB）。
			 * 另外兩個尺寸：/r/100/ 3.7KB、原圖 73KB。
			 * 400px 對封面牆剛好；存原圖再由前端自己拼 /r/400/ 會依賴
			 * 對方的網址規則，不划算。
			 */
			$cover = isset( $row['images'] ) && is_array( $row['images'] )
				? (string) ( $row['images']['common'] ?? $row['images']['large'] ?? '' )
				: '';

			$out[ (int) $row['id'] ] = [
				'bgm_id'   => (int) $row['id'],
				'type'     => (int) ( $row['type'] ?? 0 ),
				'relation' => (string) ( $row['relation'] ?? '' ),
				'name'     => (string) ( $row['name'] ?? '' ),
				'name_cn'  => (string) ( $row['name_cn'] ?? '' ),
				'cover'    => $cover,
			];
		}

		return $out;
	}

	/**
	 * 取單一條目的 platform 字串（"游戏"／"电影"／"舞台剧"…）。
	 *
	 * 關聯端點沒有這個欄位，只能對每筆遊戲／三次元多打一次。
	 * 一部作品通常只有 0-2 筆這種條目，成本可接受；其餘型別不呼叫。
	 *
	 * @return string 取不到就空字串——分組會歸「其他」，不猜。
	 */
	private function fetch_platform( int $bgm_id ): string {
		$res = wp_remote_get(
			self::SUBJECT_TMPL . $bgm_id,
			[
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'weixiaoacg/1.0 (+https://weixiaoacg.com)',
					'Accept'     => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			/* 拿不到 platform 不算致命，關聯列照樣建立 */
			return '';
		}

		$d = json_decode( wp_remote_retrieve_body( $res ), true );

		return is_array( $d ) ? trim( (string) ( $d['platform'] ?? '' ) ) : '';
	}

	/**
	 * 把回應寫回資料庫：既有列補封面，缺少的列補建。
	 *
	 * 兩件事一起做而不是分開，因為資料來源是同一次回應。原本這張表只有
	 * 外部腳本 scratchpad/bgm/relations.php 會寫，匯入流程完全沒碰，
	 * 所以腳本跑過之後才進站的作品連一列都沒有（實測正式站 162 部）。
	 *
	 * 既有列沒出現在回應裡的，cover_url 寫 ''（不是留 NULL）——那代表
	 * Bangumi 這邊已經沒有這筆關聯或它沒有圖，留 NULL 會讓這一部永遠
	 * 排在待補佇列裡，回補就收斂不了。
	 *
	 * @param array<int,array> $items fetch_relations() 的回傳
	 * @return array{filled:int, rows:int, added:int}
	 */
	private function apply_map( int $post_id, int $source_bgm_id, array $items ): array {
		global $wpdb;

		$table = $this->table();

		/* ── 1. 既有列：補封面 ── */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, bgm_id FROM {$table} WHERE post_id = %d AND cover_url IS NULL",
				$post_id
			),
			ARRAY_A
		);

		$filled = 0;

		foreach ( $rows as $row ) {
			$url = $items[ (int) $row['bgm_id'] ]['cover'] ?? '';

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

		/* ── 2. 缺少的列：補建 ── */
		$existing = $wpdb->get_col(
			$wpdb->prepare( "SELECT bgm_id FROM {$table} WHERE post_id = %d", $post_id )
		);

		$existing = array_flip( array_map( 'intval', (array) $existing ) );
		$added    = 0;

		foreach ( $items as $bgm_id => $item ) {
			if ( isset( $existing[ $bgm_id ] ) ) {
				continue;
			}

			/*
			 * relation 是中文字串（"片头曲"），platform 整個欄位不在關聯
			 * 端點裡。兩者都靠 repository 的既有標籤表反查，不另寫對照表。
			 * 對不到就 0，顯示時歸「其他」——不猜一個意思不對的代碼。
			 *
			 * 用 method_exists 而不是 class_exists：部署不是原子操作，
			 * 這支的新版可能先落地、repository 還是舊版，那時類別存在
			 * 但方法不存在，class_exists 擋不住，會直接 Fatal。
			 * 擋掉的話最多是代碼填 0（歸「其他」），不會炸掉整個 cron。
			 */
			$can_map = method_exists(
				'Anime_Sync_Subject_Relations_Repository',
				'relation_code'
			);

			$relation_type = $can_map
				? Anime_Sync_Subject_Relations_Repository::relation_code( $item['relation'], $item['type'] )
				: 0;

			$platform = 0;

			if ( $can_map && in_array( $item['type'], self::PLATFORM_TYPES, true ) ) {
				$platform = Anime_Sync_Subject_Relations_Repository::platform_code(
					$this->fetch_platform( $bgm_id ),
					$item['type']
				);
			}

			$wpdb->insert(
				$table,
				[
					'post_id'       => $post_id,
					'source_bgm_id' => $source_bgm_id,
					'bgm_id'        => $bgm_id,
					'subject_type'  => $item['type'],
					'relation_type' => $relation_type,
					'platform'      => $platform,
					'name'          => $item['name'],
					'name_cn'       => $item['name_cn'],
					'local_post_id' => 0,
					'cover_url'     => $item['cover'],
					'synced_at'     => current_time( 'mysql' ),
				],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
			);

			if ( $wpdb->rows_affected > 0 ) {
				$added++;

				if ( '' !== $item['cover'] ) {
					$filled++;
				}
			}
		}

		if ( $added > 0 ) {
			/* 新增了列，前台的分組快取要失效，否則 6 小時內看不到 */
			$this->flush_cache( $post_id );
		}

		return [
			'filled' => $filled,
			'rows'   => count( $rows ),
			'added'  => $added,
		];
	}

	/**
	 * 清掉某作品的關聯查詢快取。
	 *
	 * repository 以 post_id + subject_type 為單位存 6 小時 transient，
	 * 補完不清的話使用者最多要等 6 小時才看得到——新作品剛匯入就查看的
	 * 情況下，那等於「補了跟沒補一樣」。
	 */
	private function flush_cache( int $post_id ): void {
		if ( ! defined( 'Anime_Sync_Subject_Relations_Repository::CACHE_VER' ) ) {
			return;
		}

		$ver = Anime_Sync_Subject_Relations_Repository::CACHE_VER;

		foreach ( [ 1, 2, 3, 4, 6 ] as $type ) {
			delete_transient( sprintf( 'asp_subjrel_%s_%d_%d', $ver, $post_id, $type ) );
		}
	}

	/**
	 * 補完了：關掉自己。
	 *
	 * 這支是一次性作業不是常駐服務，跑完就該消失。新增作品由匯入流程
	 * 帶進來的列會是 NULL，屆時把 mode 打開再跑一輪即可。
	 */
	/**
	 * 目前沒有待處理的，這一輪空轉。
	 *
	 * ★ 這裡原本叫 finish()，會把開關轉 off 並移除排程。加入 RESYNC_DAYS
	 * 之後那是錯的：這支已經從「一次性回補」變成「常駐的定期同步」，
	 * 佇列空只代表「這一刻剛好都同步過了」，7 天後會再有一批到期。
	 * 若照舊自動關閉，第一次清空就會把定期同步永久停掉——而且很難察覺，
	 * 因為畫面上看起來一切正常，只是資料從此不再更新。
	 *
	 * 要真的停掉請用後台的開關（OPTION_MODE），那是人為的明確意思表示。
	 */
	private function idle(): void {
		update_option(
			self::OPTION_STAT,
			[
				'last_run'  => current_time( 'mysql' ),
				'idle'      => 1,
				'remaining' => 0,
			],
			false
		);
	}
}
