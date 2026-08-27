<?php
/**
 * 檔案名稱: includes/legacy/notification-types.php
 * 通知類型統一登錄表 — Single Source of Truth
 *
 * ✅ 新增通知類型只需在此檔案的 wxacg_notification_types() 加一筆，以下自動生效：
 *    - smacg_get_notification_prefs_defaults()      預設偏好（notifications-system.php）
 *    - wxacg_render_notification_prefs_card()       /mc/#settings 的開關（wxacg-members）
 *
 * ★ 為什麼要有這支檔案
 *   同一份清單原本寫死在兩個外掛的兩個地方，而且兩處不同步不會報錯：
 *
 *     只加 defaults、忘了設定頁 → 通知會送，但會員看不到也關不掉，
 *                                 只能整組關閉所有通知
 *     只加設定頁、忘了 defaults → wxacg_should_notify() 永遠回 false，
 *                                 通知靜默不送。呼叫端多半不檢查回傳值，
 *                                 完全無聲
 *
 *   後者實際發生過：event_ended 從上線以來一則都沒送出去過（季度活動辦了
 *   157 季），直到 2026-08-19 做一致性稽核才發現。同期還有 mention /
 *   thread / rank_up 三個類型會員關不掉。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wxacg_notification_types' ) ) {
	/**
	 * ★ 新增通知類型只需在這裡加一筆 ★
	 *
	 * key   → 傳給 wxacg_create_notification() 的 'type'。一旦上線就不要再改，
	 *         已存的通知列是靠它對應設定的。
	 *
	 * 每筆的欄位：
	 *   icon    設定頁顯示的 emoji
	 *   name    設定頁的類型名稱
	 *   desc    設定頁的說明文字
	 *   site    站內通知（鈴鐺）預設值，1 開 / 0 關
	 *   email   Email 通知預設值，1 開 / 0 關
	 *   always  設為 true 表示「交易型通知」：呼叫端以 force=true 直送，
	 *           不受偏好影響，因此不產生偏好鍵、也不出現在設定頁。
	 *           列在這裡是為了讓本表成為所有通知類型的完整清冊——
	 *           新增類型時才不會有人漏想「這個要不要開關」。
	 *
	 * 陣列順序即設定頁的顯示順序。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function wxacg_notification_types(): array {
		return [
			'follow' => [
				'icon'  => '👥',
				'name'  => '有人追蹤我',
				'desc'  => '當有用戶開始追蹤你時通知',
				'site'  => 1,
				'email' => 0,
			],
			'comment_reply' => [
				'icon'  => '💬',
				'name'  => '留言被回覆',
				'desc'  => '當有人回覆你的留言時通知',
				'site'  => 1,
				'email' => 0,
			],
			'mention' => [
				'icon'  => '📣',
				'name'  => '被 @提及',
				'desc'  => '有人在評論中 @你時通知',
				'site'  => 1,
				'email' => 0,
			],
			'thread' => [
				'icon'  => '🧵',
				'name'  => '追蹤的討論串有回覆',
				'desc'  => '你追蹤的討論串出現新回覆時通知',
				'site'  => 1,
				'email' => 0,
			],
			'rating' => [
				'icon'  => '⭐',
				'name'  => '收藏的動畫有人評分',
				'desc'  => '當你收藏的作品收到新評分時通知',
				'site'  => 1,
				'email' => 0,
			],
			'anime_update' => [
				'icon'  => '📰',
				'name'  => '追蹤的作品有新消息',
				'desc'  => '你標記「想看／追番中／暫停」的作品公開新視覺圖、宣布播出日期時通知',
				'site'  => 1,
				'email' => 0,
			],
			/*
			 * 與 anime_update 分開的理由是頻率差一個量級：視覺圖／預告一部
			 * 作品發生幾次，新集數連載期間每週一次。合在一起的話，被預告洗煩
			 * 而關掉的人會連「我追的番更新了」也一起關掉。
			 *
			 * email 預設 0：先觀察站內鈴鐺的開啟率，確認誤報率可接受再開對外寄信。
			 */
			'episode_aired' => [
				'icon'  => '📺',
				'name'  => '追蹤的作品播出新集數',
				'desc'  => '你標記「想看／追番中／暫停」的連載作品播出新一集時通知',
				'site'  => 1,
				'email' => 0,
			],
			'level_up' => [
				'icon'  => '🎖',
				'name'  => '等級提升',
				'desc'  => '當你升級時通知',
				'site'  => 1,
				'email' => 0,
			],
			'rank_up' => [
				'icon'  => '📈',
				'name'  => '排名提升',
				'desc'  => '當你在排行榜的名次上升時通知',
				'site'  => 1,
				'email' => 0,
			],
			'badge' => [
				'icon'  => '🏅',
				'name'  => '獲得徽章',
				'desc'  => '當你解鎖新徽章時通知',
				'site'  => 1,
				'email' => 0,
			],
			'event_ended' => [
				'icon'  => '🏁',
				'name'  => '季度活動結束',
				'desc'  => '你參加的季度活動結束、公布最終排名時通知',
				'site'  => 1,
				'email' => 0,
			],
			'system' => [
				'icon'  => '📢',
				'name'  => '系統公告',
				'desc'  => '網站重要更新與公告',
				'site'  => 1,
				'email' => 0,
			],

			/*
			 * ── 以下為交易型通知（always）──
			 * 呼叫端一律 force=true，不受偏好影響，不產生偏好鍵、不進設定頁。
			 * 列在此處只為保持清冊完整。
			 */
			'correction_resolved' => [
				'always' => true,
				'name'   => '資料回報已採納',
			],
			'correction_rejected' => [
				'always' => true,
				'name'   => '資料回報未採納',
			],
			'event_completed' => [
				'always' => true,
				'name'   => '完成季度活動挑戰',
			],
		];
	}
}

if ( ! function_exists( 'wxacg_notification_types_configurable' ) ) {
	/**
	 * 可由會員自行開關的類型（排除 always）。
	 *
	 * 設定頁與預設偏好都只看這一份。
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function wxacg_notification_types_configurable(): array {
		return array_filter(
			wxacg_notification_types(),
			static function ( array $t ): bool {
				return empty( $t['always'] );
			}
		);
	}
}
