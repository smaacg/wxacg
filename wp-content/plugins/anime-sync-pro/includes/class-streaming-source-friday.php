<?php
/**
 * 檔案名稱: includes/class-streaming-source-friday.php
 * friDay 影音 — 直接抓取來源（爬動漫專區分頁清單，不是 sitemap）
 *
 * friDay 沒有 sitemap（robots.txt 沒宣告、/sitemap.xml 不存在），但動漫專區有
 * 伺服器端渲染的分頁清單，每頁 24 筆、結構乾淨：
 *
 *     https://video.friday.tw/anime/filter/all/all/all?page=N
 *     <a href="/anime/detail/5124" title="淘氣鼠兄弟 第2季">
 *
 * title 屬性就是作品層級的台灣譯名（季別寫在裡面），detail 網址就是站上要存
 * 的網址，不必再進作品頁。2026-09-15 實測從正式站主機 200。
 *
 * 抓法：從 page=1 往後翻，連續兩頁沒有任何作品就停（單頁偶發失敗不會中斷整批），
 * 每頁間隔 1 秒，受基底 TIME_BUDGET 約束。全專區幾十頁，一輪幾十秒。
 *
 * robots.txt 對 UA:* 只擋 /search 與 /m/search，清單頁未禁。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Friday extends Anime_Sync_Streaming_Source_Base {

	const LIST_URL       = 'https://video.friday.tw/anime/filter/all/all/all?page=%d';
	const BASE_URL       = 'https://video.friday.tw';
	const MAX_PAGES      = 200;   // 保險上限；實測全專區遠低於此
	const STOP_AFTER_EMPTY = 2;   // 連續幾頁空白才視為翻完

	public function key(): string {
		return 'friday';
	}

	/** 作品頁從主機打得到：存在 200。 */
	protected function provides_alive_check(): bool {
		return true;
	}

	/** 每天跑讓覆核推得動（站上 863 筆）；索引（21 頁清單）仍每 6 天才重建。 */
	protected function recurrence(): string {
		return 'daily';
	}

	/** friDay 對不存在的 id 回 **400**「資料錯誤」而不是 404（2026-09-16 測三個 id 都一致）。 */
	protected function alive_missing_codes(): array {
		return [ 400, 404 ];
	}

	/** 沒有 sitemap；collect_entries() 已覆寫，這裡只是滿足抽象宣告。 */
	protected function sitemap_url(): string {
		return '';
	}

	/** 條目不是從 sitemap 區塊來的；collect_entries() 直接組好，這裡不會被呼叫。 */
	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/** title 屬性已經是作品層級，原樣即可。 */
	protected function work_name( string $title ): string {
		return trim( $title );
	}

	/**
	 * 覆寫收集方式：翻分頁清單頁。
	 *
	 * @param float $started
	 * @return array{0:array<string,array<int,array>>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		$grouped = [];
		$entries = 0;
		$empty   = 0;
		$fetched = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {

			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				$this->log_warning( sprintf( '時間預算用盡，翻到第 %d 頁為止', $page - 1 ) );
				break;
			}

			if ( $page > 1 ) {
				usleep( self::CHILD_INTERVAL_US );
			}

			$html = $this->fetch( sprintf( self::LIST_URL, $page ) );

			if ( is_wp_error( $html ) ) {
				if ( $html->get_error_code() === 'circuit_open' ) {
					return $html;
				}
				// 單頁失敗當空頁計，連續兩次才停；不因一次逾時丟掉整批
				$empty++;
				if ( $empty >= self::STOP_AFTER_EMPTY ) {
					break;
				}
				continue;
			}

			$fetched++;

			if ( ! preg_match_all( '#<a[^>]*href="(/anime/detail/\d+)"[^>]*\btitle="([^"]*)"#u', (string) $html, $m, PREG_SET_ORDER ) ) {
				$empty++;
				if ( $empty >= self::STOP_AFTER_EMPTY ) {
					break;
				}
				continue;
			}

			$empty = 0;

			foreach ( $m as $one ) {
				$title = html_entity_decode( trim( $one[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$w     = $this->work_name( $title );
				if ( $w === '' ) {
					continue;
				}
				$entries++;
				$this->add_entry( $grouped, $w, [ 'title' => $title, 'url' => self::BASE_URL . $one[1], 'date' => '' ] );
			}
		}

		// 一頁都沒抓到成功，讓 run() 回報錯誤而不是「0 部作品」
		if ( $fetched === 0 ) {
			return new WP_Error( 'no_pages', '動漫專區清單頁一頁都抓不到' );
		}

		return [ $grouped, $entries ];
	}
}
