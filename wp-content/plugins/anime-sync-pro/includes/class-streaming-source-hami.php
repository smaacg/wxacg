<?php
/**
 * 檔案名稱: includes/class-streaming-source-hami.php
 * 中華電信 Hami Video — 直接抓取來源（動漫分類頁＋翻頁端點）＋ 作品頁授權到期日
 *
 * 2026-09-15 實測（先前記成「robots 全站 Disallow」是錯的，那段是 GPTBot 專用）
 * -----------------------------------------------------------------------
 *   - robots.txt `User-agent: *` 只擋 /hamivideo/search.do、filter.do、login、subscribe、trailer、/hamivideo/api/；
 *     分類頁、翻頁端點、作品頁都允許。這裡一個都不碰被擋的路徑。
 *   - 動漫入口 `/影劇館⁺/動漫/{子分類}.do`，16 個子分類；每頁 JS 內有 `'menuId':'173'` 與 `var maxCount = 83`
 *   - 翻頁：POST /ui26_page.do {menuId, f:'new', str: page*24}，回一段 HTML：
 *       <div class="list_item"><a href="/product/335037.do?cs=2" … gaEventUI('|26','流浪神差')>…<h3>流浪神差</h3>
 *     一頁 24 筆，偶爾 20~23（被過濾的項目），**結尾要看 maxCount 不能看 <24**
 *   - 全目錄 1,562 部、112 次請求、84 秒（本機）；子分類間高度重疊（Ani-One 452 部幾乎全在別的分類裡）
 *   - 作品頁 `/product/{id}.do` 約 190KB，不支援 Range；<title>「作品 - 線上看 - 動漫 - 子類型 | HamiVideo」，
 *     頁內明文「下架時間 2026年09月24日」（到期的作品放在 hidden span 的 data 屬性裡，格式相同）
 *   - 標題慣例：季用 `S2`、分割用 `P1`／`P2`；站上寫「第二季」「第2部分」
 *
 * 站上 1,169 個 Hami 網址（YA 給的）全是 product 頁，所以到期日對全部作品都查得到，
 * 不只我們自己配到的——這是接 Hami 的主要價值（它是站上標記最多的平台，之前完全沒有下架偵測）。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Hami extends Anime_Sync_Streaming_Source_Base {

	const BASE_URL    = 'https://hamivideo.hinet.net';
	const PAGE_URL    = self::BASE_URL . '/ui26_page.do';
	const PRODUCT_URL = self::BASE_URL . '/product/%d.do';

	/** 動漫子分類；順序無關，取聯集 */
	const GENRES = [ '新番連載', '熱血動作', '魔幻冒險', '青春浪漫', '輕鬆幽默', '推理懸疑', '科技未來', '靈異神怪', '運動競技', '料理美食', '音樂偶像', '綜合其他', '劇場特區', 'Ani-One', 'Crunchyroll', 'PILI' ];

	const PER_PAGE         = 24;
	const MAX_PAGES        = 40;       // 最大分類 473 部 = 20 頁，留兩倍餘裕防呆
	const REQUEST_INTERVAL_US = 400000;

	public function key(): string {
		return 'hami';
	}

	/** 沒有 sitemap，收集全在 collect_entries()。 */
	protected function sitemap_url(): string {
		return '';
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/**
	 * 「骸骨騎士大人異世界冒險中S2」→「骸骨騎士大人異世界冒險中 第2季」（normalize 會拉平成 @S2）
	 * 「犬夜叉P1」→「犬夜叉」；「…P2」→「… 第2部分」（站上分割檔的寫法）
	 */
	protected function work_name( string $title ): string {
		$t = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$t = (string) preg_replace( '/\s*P1$/u', '', $t );
		$t = (string) preg_replace_callback( '/\s*P(\d+)$/u', static fn( $m ) => ' 第' . $m[1] . '部分', $t );
		$t = (string) preg_replace_callback( '/\s*S(\d+)(\s*第\d+部分)?$/u', static fn( $m ) => ' 第' . $m[1] . '季' . ( $m[2] ?? '' ), $t );
		return trim( $t );
	}

	/**
	 * 16 個子分類頁各翻到 maxCount 為止，聯集就是全目錄。
	 * 超過時間預算回 WP_Error：半套索引會讓 check_gone() 把沒收到的當下架，寧可沿用上一輪。
	 *
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		$grouped = [];
		$entries = 0;
		$req     = 0;

		foreach ( self::GENRES as $genre ) {

			$url  = self::BASE_URL . '/' . rawurlencode( '影劇館⁺' ) . '/' . rawurlencode( '動漫' ) . '/' . rawurlencode( $genre ) . '.do';
			$html = $this->fetch( $url, [ 'accept' => 'text/html' ] );
			$req++;
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			$html = (string) $html;

			// 分類頁本體的卡片（onclick 的 gaEventUI 第二個參數就是作品名）
			$entries += $this->add_cards( $grouped, $html );

			$menu = preg_match( "/'menuId'\s*:\s*'(\d+)'/", $html, $mm ) ? $mm[1] : '';
			$max  = preg_match( '/var maxCount\s*=\s*(\d+)/', $html, $mx ) ? (int) $mx[1] : 0;
			if ( $menu === '' ) {
				$this->log_warning( '子分類「' . $genre . '」頁面找不到 menuId，平台可能改版；本輪只收到卡片' );
				continue;
			}

			for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {

				if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
					return new WP_Error( 'time_budget', sprintf( '時間預算用盡（%d 次請求、%d 部），沿用上一輪索引', $req, count( $grouped ) ) );
				}
				usleep( self::REQUEST_INTERVAL_US );

				$part = $this->fetch( self::PAGE_URL, [
					'accept' => 'text/html',
					'method' => 'POST',
					'body'   => [ 'menuId' => $menu, 'f' => 'new', 'str' => $page * self::PER_PAGE ],
				] );
				$req++;
				if ( is_wp_error( $part ) ) {
					if ( $part->get_error_code() === 'circuit_open' ) {
						return $part;
					}
					break;   // 這一分類翻頁失敗就停，其他分類照收（大多重疊）
				}

				$got = $this->add_list_items( $grouped, (string) $part );
				$entries += $got;

				if ( substr_count( (string) $part, 'class="list_item"' ) === 0 ) {
					break;
				}
				if ( $max > 0 && ( $page + 1 ) * self::PER_PAGE >= $max ) {
					break;
				}
			}
		}

		if ( count( $grouped ) < 500 ) {
			// 2026-09-15 全目錄 1,562 部；掉到三分之一以下多半是端點改了，不能拿去判下架
			return new WP_Error( 'too_few', sprintf( '只收到 %d 部（預期 1,500 上下），平台可能改版，沿用上一輪索引', count( $grouped ) ) );
		}

		return [ $grouped, $entries ];
	}

	/** 分類頁本體：href="/product/ID.do…" … gaEventUI('…','作品名') */
	private function add_cards( array &$grouped, string $html ): int {
		$n = 0;
		if ( preg_match_all( '#href="/product/(\d+)\.do[^"]*"[^>]*gaEventUI\(\'[^\']*\',\'([^\']*)\'#u', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$n += $this->add_product( $grouped, (int) $x[1], $x[2] );
			}
		}
		return $n;
	}

	/** 翻頁端點：<div class="list_item">…/product/ID.do…<h3>作品名</h3> */
	private function add_list_items( array &$grouped, string $html ): int {
		$n = 0;
		if ( preg_match_all( '#<div class="list_item">.*?href="/product/(\d+)\.do.*?<h3>(.*?)</h3>#su', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$n += $this->add_product( $grouped, (int) $x[1], strip_tags( $x[2] ) );
			}
		}
		return $n;
	}

	private function add_product( array &$grouped, int $id, string $title ): int {
		$work = $this->work_name( $title );
		if ( $id <= 0 || $work === '' ) {
			return 0;
		}
		$this->add_entry( $grouped, $work, [ 'title' => trim( $title ), 'url' => sprintf( self::PRODUCT_URL, $id ), 'date' => '' ] );
		return 1;
	}

	/** 同一作品名多個 product（重播版本）：取 id 最小的，通常是最早上架的正篇 */
	protected function pick_entry( array $entries ): array {
		usort( $entries, static fn( $a, $b ) => strcmp( $a['url'], $b['url'] ) );
		usort( $entries, static function ( $a, $b ) {
			preg_match( '/(\d+)\.do/', $a['url'], $x );
			preg_match( '/(\d+)\.do/', $b['url'], $y );
			return ( (int) ( $x[1] ?? 0 ) ) <=> ( (int) ( $y[1] ?? 0 ) );
		} );
		return $entries[0];
	}

	// ── 授權到期日 ──

	protected function provides_end_date(): bool {
		return true;
	}

	/** 作品頁 190KB、不支援 Range，只能整頁抓；404＝真的下架。 */
	protected function end_date_fetch_opts(): array {
		return [ 'accept' => 'text/html', 'allow_404' => true ];
	}

	/**
	 * 「下架時間&nbsp;2026年10月31日」或到期作品的「下架時間&nbsp;<span class="endDate0" hidden data="2026年09月24日">」。
	 * 頁面裡沒有「下架時間」這個欄位＝不是作品頁（系統訊息、轉址到首頁）→ null。
	 */
	protected function parse_end_date( string $html ): ?string {
		$pos = strpos( $html, '下架時間' );
		if ( $pos === false ) {
			return null;
		}
		$chunk = substr( $html, $pos, 1200 );
		if ( preg_match( '/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $chunk, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return '';
	}
}
