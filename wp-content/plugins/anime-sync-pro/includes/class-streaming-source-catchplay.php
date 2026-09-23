<?php
/**
 * 檔案名稱: includes/class-streaming-source-catchplay.php
 * CatchPlay+ — 直接抓取來源（本機索引包；作品頁只對台灣 IP 開放）
 *
 * 2026-09-15／16 實測
 * -------------------
 *   - robots.txt `User-agent: *` 全部允許並列 sitemap：
 *       /tw/series-sitemap.xml → /tw/series-001-sitemap.xml（1,230 個 /tw/video/{uuid}）
 *       /tw/movie-sitemap.xml  → /tw/movie-001-sitemap.xml（5,333 個）
 *     只有網址沒標題，要逐頁抓。
 *   - 作品頁 500KB SSR，Range: bytes=0-65535 回 206，前 64KB 的 og:title：
 *       「《SPY x FAMILY 間諜家家酒．第2季》線上看｜CATCHPLAY+ 正版日本動畫動漫專區」   ← 動畫
 *       「《九條好漢在一班》線上看｜共1季26集｜CATCHPLAY+ 正版影集專區」                 ← 真人劇，不收
 *     「動畫動漫專區」後綴就是動畫過濾器（同名真人版：死亡筆記本、銀魂電影靠它擋掉）。
 *   - ⚠ **正式站主機（吉隆坡）抓作品頁一律 302 轉回首頁**（CloudFront 依 IP 地區擋，sitemap 本身可抓）。
 *     我 2026-09-15 只在本機測就先做成主機逐頁爬，上線第一批 300 頁全拿到首頁、0 條目——
 *     又一次違反「從主機實抓一次才算數」。所以改成跟巴哈／車庫一樣：本機建索引包、主機只讀檔。
 *   - 本機每頁約 1 秒（64KB）。6,563 頁首輪約 2 小時；之後每週只抓 sitemap 新出現的 uuid，
 *     靠 asp-tools 目錄裡的本機快取（uuid → 標題或 null）記住已抓過的，快取不進 repo。
 *
 * @package Anime_Sync_Pro
 * @version 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Catchplay extends Anime_Sync_Streaming_Source_Bundle_Base {

	const BUNDLE_FILE     = 'data/source_catchplay_bundle.json';
	const SITEMAP_INDEXES = [ 'https://www.catchplay.com/tw/series-sitemap.xml', 'https://www.catchplay.com/tw/movie-sitemap.xml' ];
	const VIDEO_URL       = 'https://www.catchplay.com/tw/video/%s';
	const RANGE_BYTES     = 65536;
	const PAGE_INTERVAL_US = 300000;

	public function key(): string {
		return 'catchplay';
	}

	/**
	 * 索引不是平台的完整快照，所以不跑下架偵測。
	 *
	 * 2026-09-23 實例：「星期一的豐滿」（#58315）與其 SP（#58317）被判疑似下架，
	 * 但從台灣 IP 實抓兩頁都回 200、og:title 正常（對照：亂編的 uuid 回 404，
	 * 所以判斷方法可信）。作品活著，只是 CatchPlay 自己的 sitemap 沒列它們——
	 * #58315 的 uuid 從來沒進過本機建包快取，#58317 有快取標題卻因這次 sitemap
	 * 沒列而落榜（09/16 的索引包還有它，09/20 的就沒了）。
	 *
	 * 量測：寫過的 167 筆有 102 筆（61%）網址不在索引裡，其中 100 筆是靠標題
	 * 配得到才沒被誤判——那是運氣，不是機制。
	 *
	 * 代價：CatchPlay 真的下架時不會自動偵測到。但主機（吉隆坡）抓作品頁一律被
	 * 302 轉回首頁，本來就沒有可靠的偵測手段；與其留一個會刪掉正確資料的機制，
	 * 不如關掉。索引仍照常用來「發現」新作品，只是不反推下架。
	 */
	protected function index_covers_platform(): bool {
		return false;
	}

	/** 「SPY x FAMILY 間諜家家酒．第2季」→「SPY x FAMILY 間諜家家酒 第2季」；全形間隔點是 CatchPlay 的季別分隔 */
	protected function work_name( string $title ): string {
		$t = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$t = (string) preg_replace( '/[．·]\s*(第\s*\d+\s*季)$/u', ' $1', $t );
		return trim( $t );
	}

	/* 讀索引包、錯誤處理、bundle_info() 全在 Anime_Sync_Streaming_Source_Bundle_Base；
	   本機建包走下方 export_bundle()，主機端只會讀檔。 */

	/**
	 * og:title →（動畫才有）作品名。
	 *
	 * @return string|null 作品名；非動畫專區／中配版／認不得回 null
	 */
	public function title_from_page( string $html ): ?string {

		if ( ! preg_match( '#<meta\s+property="og:title"\s+content="([^"]*)"#i', $html, $m ) ) {
			return null;
		}
		$og = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( strpos( $og, '動畫動漫專區' ) === false && strpos( $og, '動畫專區' ) === false ) {
			return null;
		}
		if ( ! preg_match( '/《(.+?)》/u', $og, $t ) ) {
			return null;
		}

		$title = $this->work_name( $t[1] );
		if ( $title === '' || preg_match( '/[（(]\s*(?:國語|中配|中文配音|雙語)\s*(?:版)?\s*[）)]/u', $title ) ) {
			return null;
		}
		return $title;
	}

	/**
	 * 本機建包：讀兩個 sitemap 拿全部 uuid → 沒抓過的逐頁抓前 64KB → 動畫的進索引包。
	 *
	 * @param string        $path       索引包輸出位置（repo 內）
	 * @param string        $cache_path 本機快取（uuid → 作品名或 null；不進 repo）
	 * @param callable|null $progress   fn( int $done, int $total, string $uuid, ?string $title )
	 * @param int           $max_fetch  這一輪最多抓幾頁（0＝不限）
	 * @return array{entries:int,works:int,fetched:int,cached:int,bytes:int}|WP_Error
	 */
	public function export_bundle( string $path, string $cache_path, ?callable $progress = null, int $max_fetch = 0 ) {

		$uuids = [];
		foreach ( self::SITEMAP_INDEXES as $index_url ) {
			$xml = $this->fetch( $index_url );
			if ( is_wp_error( $xml ) ) {
				return $xml;
			}
			$subs = preg_match_all( '#<loc>\s*(https://www\.catchplay\.com/tw/[^<\s]+-sitemap\.xml)\s*</loc>#i', (string) $xml, $sm ) ? $sm[1] : [ $index_url ];
			foreach ( $subs as $sub ) {
				if ( $sub !== $index_url ) {
					usleep( self::CHILD_INTERVAL_US );
					$xml = $this->fetch( $sub );
					if ( is_wp_error( $xml ) ) {
						return $xml;
					}
				}
				if ( preg_match_all( '#<loc>\s*https://www\.catchplay\.com/tw/video/([0-9a-f-]{36})\s*</loc>#i', (string) $xml, $m ) ) {
					foreach ( $m[1] as $u ) {
						$uuids[ $u ] = 1;
					}
				}
			}
		}
		if ( count( $uuids ) < 1000 ) {
			return new WP_Error( 'no_ids', sprintf( 'sitemap 只解析到 %d 個 uuid（預期 6,500 上下），平台可能改版', count( $uuids ) ) );
		}

		$cache = is_readable( $cache_path ) ? json_decode( (string) file_get_contents( $cache_path ), true ) : [];
		$cache = is_array( $cache ) ? $cache : [];

		$todo = [];
		foreach ( array_keys( $uuids ) as $u ) {
			if ( ! array_key_exists( $u, $cache ) ) {
				$todo[] = $u;
			}
		}
		if ( $max_fetch > 0 ) {
			$todo = array_slice( $todo, 0, $max_fetch );
		}

		$fetched = 0;
		foreach ( $todo as $u ) {
			if ( $fetched > 0 ) {
				usleep( self::PAGE_INTERVAL_US );
			}
			$html = $this->fetch( sprintf( self::VIDEO_URL, $u ), [ 'range_bytes' => self::RANGE_BYTES, 'accept' => 'text/html', 'allow_404' => true ] );
			$fetched++;
			if ( is_wp_error( $html ) ) {
				if ( $html->get_error_code() === 'circuit_open' ) {
					break;   // 快取照存，下次接著抓
				}
				if ( $html->get_error_code() === 'not_found' ) {
					$cache[ $u ] = null;   // sitemap 殘留的死連結
				}
				continue;   // 其他錯誤不記，下次再試
			}
			$title       = $this->title_from_page( (string) $html );
			$cache[ $u ] = $title;
			if ( $progress ) {
				$progress( $fetched, count( $todo ), $u, $title );
			}
			if ( $fetched % 50 === 0 ) {
				file_put_contents( $cache_path, (string) wp_json_encode( $cache, JSON_UNESCAPED_UNICODE ) );
			}
		}
		file_put_contents( $cache_path, (string) wp_json_encode( $cache, JSON_UNESCAPED_UNICODE ) );

		// 只有 sitemap 裡還在的 uuid 才進索引包（下架的頁面會從 sitemap 消失）
		$grouped = [];
		$entries = 0;
		foreach ( array_keys( $uuids ) as $u ) {
			$title = $cache[ $u ] ?? null;
			if ( ! is_string( $title ) || $title === '' ) {
				continue;
			}
			$entries++;
			$this->add_entry( $grouped, $title, [ 'title' => $title, 'url' => sprintf( self::VIDEO_URL, $u ), 'date' => '' ] );
		}

		$works = [];
		foreach ( $grouped as $name => $picked ) {
			$works[] = [ 'n' => (string) $name, 'u' => (string) $picked['url'] ];
		}
		usort( $works, static fn( $a, $b ) => strcmp( $a['n'], $b['n'] ) );

		$json = wp_json_encode( [ 'source' => 'catchplay sitemap + og:title', 'entries' => $entries, 'works' => $works ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( file_put_contents( $path, (string) $json ) === false ) {
			return new WP_Error( 'write_failed', '寫不進 ' . $path );
		}

		return [ 'entries' => $entries, 'works' => count( $works ), 'fetched' => $fetched, 'cached' => count( $cache ), 'bytes' => strlen( (string) $json ) ];
	}

	/** 同一作品多個 uuid（重上架）：取 uuid 字串最小，穩定即可 */
	protected function pick_entry( array $entries ): array {
		usort( $entries, static fn( $a, $b ) => strcmp( $a['url'], $b['url'] ) );
		return $entries[0];
	}
}
