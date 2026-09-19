<?php
/**
 * 檔案名稱: includes/class-streaming-source-ptsplus.php
 * 公視+ (PTS+) — 直接抓取來源（分批增量爬節目頁）
 *
 * 為什麼要逐頁爬
 * --------------
 * 2026-09-19 從正式站主機實測：
 *   - sitemap.xml 有 2,550 個 /zh/programs/ 網址（1,162 個節目、1,388 個季度頁），
 *     但只有網址、沒有標題、沒有 lastmod
 *   - 分類頁（categories/KIDS_AND_FAMILY…）是前端渲染，SSR 不含節目清單，
 *     整頁只找得到一個 name（「小公視猜你喜歡」）
 *   - 節目頁 34KB，__NEXT_DATA__ 只有 ProgramForOG（id／名稱／簡介／封面），
 *     但 <meta property="og:title"> 就是「公視+ | 節目名」，夠用
 *
 * 只爬節目主頁、不爬季度頁
 * ------------------------
 * 公視的 season 是**語言版本**不是季數（葬送的芙莉蓮底下有台語版、雙語版兩個 season），
 * 而節目主頁是所有版本的共同入口，當作品的標準連結最合適。
 * 語言版本自成一個節目的（搖曳露營△（雙語版）、櫻桃小丸子（台語版）…）本來就在
 * 主頁層級，不會因為跳過季度頁而漏掉。這也讓每輪要抓的頁數少一半。
 *
 * ⚠ 不存在的節目回 **HTTP 200 不是 404**
 * --------------------------------------
 * 2026-09-19 實測同格式假 UUID：HTTP 200、32KB、og:title 是「公視+ | 404」。
 * 所以存活判斷**不能看狀態碼**，只能看標題，見 parse_alive()。
 * 同日實測 Range: bytes=0-65535 也不支援（回 200 整份），沒辦法像 LINE TV 那樣省流量。
 *
 * 2026-09-19 本機比對：站上 4,037 個標題與公視節目名完全相同的有 14 部
 * （葬送的芙莉蓮、國王排名、路人超能100、搖曳露營△（雙語版）、窗邊的小荳荳（雙語版）…）。
 * 數量不多，但公視+ 免費，對其中幾部可能是台灣唯一的合法觀看管道。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Ptsplus extends Anime_Sync_Streaming_Source_Base {

	const SITEMAP_URL  = 'https://www.ptsplus.tv/sitemap.xml';
	const PROGRAM_URL  = 'https://www.ptsplus.tv/zh/programs/%s';
	const QUEUE_OPTION = 'anime_sync_src_queue_ptsplus';

	/** 每輪最多處理幾頁；配合 0.5 秒間隔，1,162 頁約 10 輪（10 小時）抓完首輪 */
	const BATCH            = 120;
	const PAGE_INTERVAL_US = 500000;

	/** 佇列空了之後隔多久重抓 sitemap 找新節目（sitemap 沒 lastmod，只能整份比對） */
	const SITEMAP_REFRESH = 6 * HOUR_IN_SECONDS;

	/** 連續失敗這麼多頁就停本輪，剩下的留給下一輪 */
	const ABORT_AFTER = 10;

	/** 語言版本後綴：公視全形與半形括號都用過（（台語版）與 (台語版) 實際都出現） */
	const VERSION_SUFFIX = '/\s*[（(](?:台語版|雙語版|國語版|台文字幕版)[）)]\s*$/u';

	public function key(): string {
		return 'ptsplus';
	}

	/** 節目頁從主機打得到（2026-09-19 實測 200）；死活靠標題判斷，見 parse_alive()。 */
	protected function provides_alive_check(): bool {
		return true;
	}

	protected function recurrence(): string {
		return 'hourly';
	}

	protected function sitemap_url(): string {
		return self::SITEMAP_URL;
	}

	protected function incremental(): bool {
		return true;
	}

	/** 佇列清空才算完整快照，否則還沒爬到的會被當成下架。 */
	protected function index_is_complete(): bool {
		$q = $this->queue();
		return $q['total'] > 0 && empty( $q['pending'] );
	}

	/** 條目由 collect_entries() 直接組好，這裡不會被呼叫。 */
	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/**
	 * 去掉語言版本後綴，讓「搖曳露營△（雙語版）」對得上站上的「搖曳露營△」。
	 *
	 * 刻意**不**切「 - 」：公視有不少節目名本身就含破折號
	 * （「歐吉桑騎士 – 阿順阿忠的中年危機」），切了會把真名截斷。
	 * 季度頁標題才是「節目名 - 版本名」，而我們不爬季度頁。
	 */
	protected function work_name( string $title ): string {
		return trim( (string) preg_replace( self::VERSION_SUFFIX, '', trim( $title ) ) );
	}

	/**
	 * 同一個作品可能對到多個節目（原版／台語版／雙語版各自成節目）。
	 * 取捨：原版（無後綴）優先，其次雙語版，都沒有才取第一筆。
	 * 原版是所有語言版本的共同入口，當站上唯一那一格連結最不會誤導。
	 *
	 * @param array<int,array{title:string,url:string,date?:string}> $entries
	 */
	protected function pick_entry( array $entries ): array {

		foreach ( $entries as $e ) {
			if ( ! preg_match( self::VERSION_SUFFIX, (string) $e['title'] ) ) {
				return $e;
			}
		}

		foreach ( $entries as $e ) {
			if ( preg_match( '/\s*[（(]雙語版[）)]\s*$/u', (string) $e['title'] ) ) {
				return $e;
			}
		}

		return $entries[0];
	}

	/**
	 * 存活判斷：**不能看 HTTP 狀態碼**，公視對不存在的節目一律回 200。
	 * 只有 og:title 是「404」才算死；抓不到標題回 null（不下結論，不誤殺）。
	 */
	protected function parse_alive( string $html, string $url = '' ): ?bool {

		$title = self::og_title( $html );

		if ( $title === '' ) {
			return null;
		}

		return $title !== '404';
	}

	/**
	 * 覆寫收集：從佇列取一批節目頁抓標題。
	 *
	 * @param float $started
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		$q = $this->queue();

		// ── 佇列空了：重抓 sitemap 找還沒抓過的節目 ──
		if ( empty( $q['pending'] ) ) {

			if ( $q['sitemap_at'] > 0 && ( time() - $q['sitemap_at'] ) < self::SITEMAP_REFRESH ) {
				// 冷卻期內沒新工作，回空批；增量合併會保住既有索引
				return [ [], 0 ];
			}

			$xml = $this->fetch( self::SITEMAP_URL );
			if ( is_wp_error( $xml ) ) {
				return $xml;
			}

			/*
			 * 節目網址不只出現在 <loc>，也出現在 hreflang 的 <xhtml:link href>，
			 * 所以不綁 <loc>、直接抓網址本身再去重（2,550 個網址去重後 1,162 個節目）。
			 * 季度頁網址的前半段就是它所屬節目的 id，一起收進來正好補齊。
			 */
			if ( ! preg_match_all( '#/zh/programs/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})#i', (string) $xml, $m ) ) {
				return new WP_Error( 'no_ids', 'sitemap.xml 解析不到任何節目 id，平台可能改版' );
			}

			$ids = array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );

			$pending = [];
			foreach ( $ids as $id ) {
				if ( ! isset( $q['done'][ $id ] ) ) {
					$pending[] = $id;
				}
			}

			$q['pending']    = $pending;
			$q['sitemap_at'] = time();
			$q['total']      = count( $ids );
			$this->save_queue( $q );

			if ( empty( $pending ) ) {
				return [ [], 0 ];
			}
		}

		// ── 抓一批 ──
		$grouped   = [];
		$entries   = 0;
		$processed = 0;
		$failures  = 0;

		while ( ! empty( $q['pending'] ) && $processed < self::BATCH ) {

			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				break;
			}

			$id = (string) array_shift( $q['pending'] );
			$processed++;

			if ( $processed > 1 ) {
				usleep( self::PAGE_INTERVAL_US );
			}

			$url  = sprintf( self::PROGRAM_URL, $id );
			$html = $this->fetch( $url, [ 'accept' => 'text/html' ] );

			if ( is_wp_error( $html ) ) {
				if ( $html->get_error_code() === 'circuit_open' ) {
					array_unshift( $q['pending'], $id );
					$this->save_queue( $q );
					return $html;
				}
				// 這一頁放回佇列尾端下輪再試；連續失敗太多就停
				$q['pending'][] = $id;
				if ( ++$failures >= self::ABORT_AFTER ) {
					break;
				}
				continue;
			}

			$failures         = 0;
			$q['done'][ $id ] = 1;

			$parsed = $this->parse_page( (string) $html );
			if ( $parsed === null ) {
				continue;   // 已下架（og:title 是 404）或抓不到標題：標已抓、不進索引
			}

			$entries++;
			$this->add_entry( $grouped, $parsed['title'], [ 'title' => $parsed['title'], 'url' => $url, 'date' => '' ] );

			// 每 10 頁存一次進度，被砍最多只損失這麼多
			if ( $processed % 10 === 0 ) {
				$this->save_queue( $q );
			}
		}

		$this->save_queue( $q );

		return [ $grouped, $entries ];
	}

	/**
	 * 從節目頁取名稱；已下架（og:title 為 404）或沒有標題回 null。
	 *
	 * @return array{title:string}|null
	 */
	protected function parse_page( string $html ): ?array {

		$title = self::og_title( $html );

		if ( $title === '' || $title === '404' ) {
			return null;
		}

		return [ 'title' => $title ];
	}

	/**
	 * 取 og:title 的節目名部分。格式是「公視+ | 葬送的芙莉蓮」，
	 * 季度頁則是「公視+ | 葬送的芙莉蓮 - 台語版」。
	 */
	private static function og_title( string $html ): string {

		if ( ! preg_match( '#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m ) ) {
			return '';
		}

		$title = html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 站名前綴切掉；沒有前綴（公視改版）就原樣回傳，不要回空字串害存活判斷誤殺
		$parts = explode( ' | ', $title, 2 );

		return trim( $parts[1] ?? $parts[0] );
	}

	// ── 佇列狀態 ──

	/** @return array{pending:string[],done:array<string,int>,sitemap_at:int,total:int} */
	protected function queue(): array {
		$q = get_option( self::QUEUE_OPTION, [] );
		return [
			'pending'    => is_array( $q['pending'] ?? null ) ? $q['pending'] : [],
			'done'       => is_array( $q['done'] ?? null ) ? $q['done'] : [],
			'sitemap_at' => (int) ( $q['sitemap_at'] ?? 0 ),
			'total'      => (int) ( $q['total'] ?? 0 ),
		];
	}

	protected function save_queue( array $q ): void {
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/** 給 --status 用：抓取進度。 */
	public function progress(): array {
		$q = $this->queue();
		return [ 'done' => count( $q['done'] ), 'pending' => count( $q['pending'] ), 'total' => $q['total'] ];
	}
}
