<?php
/**
 * 日期／播出時間欄位的共用解析與格式化。
 *
 * ★ 為什麼需要這個檔案
 *   同一個欄位的解析散在多處各寫一份，是本專案反覆出事的原因。實例：
 *
 *   anime_next_airing 有兩種格式並存——匯入端（class-api-handler.php）寫
 *   JSON `{"airingAt":…,"episode":…}`，cron（class-cron-manager.php）寫純
 *   Unix 時間戳。全站 137 筆裡 86 筆是數字、51 筆是 JSON，取決於哪一支
 *   程式最後寫過。動畫頁兩種都處理，首頁只處理數字，導致那 51 部作品在
 *   首頁的播出星期分類永遠失敗。
 *
 *   寫入端已統一為 JSON（見 wxacg_encode_next_airing），但既有資料仍是
 *   兩種格式，且日後仍可能有其他來源寫入，因此讀取一律走這裡。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wxacg_parse_next_airing' ) ) {
	/**
	 * 解析 anime_next_airing，兩種歷史格式都吃。
	 *
	 * @param mixed $raw meta 原始值：JSON 字串、Unix 時間戳字串／整數，或陣列。
	 * @return array{airingAt:int, episode:int} airingAt 為 0 代表無效。
	 */
	function wxacg_parse_next_airing( $raw ): array {
		$empty = [ 'airingAt' => 0, 'episode' => 0 ];

		if ( empty( $raw ) ) {
			return $empty;
		}

		// 已經是陣列（ACF 可能回傳解碼後的值）
		if ( is_array( $raw ) ) {
			return [
				'airingAt' => (int) ( $raw['airingAt'] ?? 0 ),
				'episode'  => (int) ( $raw['episode'] ?? 0 ),
			];
		}

		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return $empty;
		}

		// 純數字 = 舊的 cron 寫法，只有時間戳沒有集數
		if ( ctype_digit( $raw ) ) {
			return [ 'airingAt' => (int) $raw, 'episode' => 0 ];
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return [
				'airingAt' => (int) ( $decoded['airingAt'] ?? 0 ),
				'episode'  => (int) ( $decoded['episode'] ?? 0 ),
			];
		}

		return $empty;
	}
}

if ( ! function_exists( 'wxacg_encode_next_airing' ) ) {
	/**
	 * 產生要寫入 anime_next_airing 的值。
	 *
	 * ★ 統一寫成 JSON 而不是純時間戳：JSON 版多帶 episode，資訊比較完整。
	 *   改成純數字會遺失集數，讀取端得改用 anime_episodes_aired 推算，而那個
	 *   欄位未必準。動畫頁本來就支援 JSON，統一成 JSON 的改動面最小。
	 *
	 * @param int $airing_at Unix 時間戳。
	 * @param int $episode   集數，0 表示未知。
	 * @return string 寫入用的字串；時間戳無效時回空字串。
	 */
	function wxacg_encode_next_airing( int $airing_at, int $episode = 0 ): string {
		if ( $airing_at <= 0 ) {
			return '';
		}

		return (string) wp_json_encode(
			[
				'airingAt' => $airing_at,
				'episode'  => max( 0, $episode ),
			]
		);
	}
}

if ( ! function_exists( 'wxacg_fuzzy_date_to_meta' ) ) {
	/**
	 * 把掃描器快照的可變精度日期轉成 meta 用的 8 碼格式。
	 *
	 * ★ 兩套格式各有用途，不能混用：
	 *   - 快照用可變精度（2026 / 2026-10 / 2026-10-09），因為「2026 變成
	 *     2026-10」本身就是一則值得記錄的消息，精度差異必須保留。
	 *   - meta 用 8 碼純數字（20261009），因為前台與 cron 都拿它做 NUMERIC
	 *     比較（見 page-bangumi.php 的 meta_query）。
	 *
	 * ★ 精度不足時回空字串，與匯入端的 parse_fuzzy_date() 規則一致：
	 *   年月日俱全才寫入。這樣「上游只知道 2026 年 10 月」就不會被硬補成
	 *   20261001 而顯示成假的精確日期。
	 *
	 * @param string $fuzzy 快照格式的日期。
	 * @return string 8 碼日期；精度不足回空字串。
	 */
	function wxacg_fuzzy_date_to_meta( string $fuzzy ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $fuzzy ), $m ) ) {
			return '';
		}

		return sprintf( '%04d%02d%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
	}
}
