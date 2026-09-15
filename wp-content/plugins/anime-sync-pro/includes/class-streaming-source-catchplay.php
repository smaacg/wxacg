<?php
/**
 * 檔案名稱: includes/class-streaming-source-catchplay.php
 * CatchPlay+ — 直接抓取來源（sitemap 給網址、作品頁前 64KB 給標題，分批增量）
 *
 * 2026-09-15 實測（先前記成「4KB 純 SPA 殼、無公開資料」是錯的，當時抓到的是沒渲染的殼）
 * -----------------------------------------------------------------------------
 *   - robots.txt `User-agent: *` 全部允許，並列出 sitemap：
 *       /tw/series-sitemap.xml → /tw/series-001-sitemap.xml（1,230 個 /tw/video/{uuid}）
 *       /tw/movie-sitemap.xml  → /tw/movie-001-sitemap.xml（5,333 個）
 *     sitemap 只有網址沒有標題，跟 LINE TV 一樣要逐頁抓
 *   - 作品頁 500KB SSR，但 Range: bytes=0-65535 回 206，前 64KB 就有：
 *       <meta property="og:title" content="《SPY x FAMILY 間諜家家酒．第2季》線上看｜CATCHPLAY+ 正版日本動畫動漫專區">
 *       <meta property="og:title" content="《魔法少女☆伊莉雅：LICHT無名的少女》線上看｜CATCHPLAY+｜Ani-One 正版日本動畫動漫專區">
 *       <meta property="og:title" content="《九條好漢在一班》線上看｜共1季26集｜CATCHPLAY+ 正版影集專區">   ← 真人劇，不收
 *     動畫一律帶「動畫動漫專區」後綴（Ani-One 與非 Ani-One 皆是）；真人影集是「正版影集專區」、電影「正版電影專區」。
 *     這個後綴就是動畫過濾器——同名真人版（死亡筆記本、銀魂電影）靠它擋掉。
 *   - 動畫 curation 頁 /tw/search/list?args=1012 說 totalCount 1,341，但清單 SSR 只 58 筆、翻頁走 GraphQL，不用。
 *   - 站上 630 個 CatchPlay 標記（YA 給的）網址全是 /tw/video/{uuid}
 *
 * 首輪 6,563 頁 × 64KB ≈ 420MB，每小時 300 頁約一天；之後每 6 小時比對 sitemap 只補新 uuid。
 * 排程每小時、索引增量合併、佇列存 option，全部沿用基底與 LINE TV 的做法。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Catchplay extends Anime_Sync_Streaming_Source_Base {

	const SITEMAP_INDEXES = [ 'https://www.catchplay.com/tw/series-sitemap.xml', 'https://www.catchplay.com/tw/movie-sitemap.xml' ];
	const VIDEO_URL       = 'https://www.catchplay.com/tw/video/%s';
	const QUEUE_OPTION    = 'anime_sync_src_queue_catchplay';

	const BATCH            = 300;
	const PAGE_INTERVAL_US = 500000;
	const RANGE_BYTES      = 65536;
	const SITEMAP_REFRESH  = 6 * HOUR_IN_SECONDS;
	const ABORT_AFTER      = 10;

	public function key(): string {
		return 'catchplay';
	}

	protected function sitemap_url(): string {
		return self::SITEMAP_INDEXES[0];
	}

	protected function incremental(): bool {
		return true;
	}

	protected function recurrence(): string {
		return 'hourly';
	}

	protected function index_is_complete(): bool {
		$q = $this->queue();
		return $q['total'] > 0 && empty( $q['pending'] );
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/** 「SPY x FAMILY 間諜家家酒．第2季」→「SPY x FAMILY 間諜家家酒 第2季」；全形間隔點是 CatchPlay 的季別分隔 */
	protected function work_name( string $title ): string {
		$t = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$t = (string) preg_replace( '/[．·]\s*(第\s*\d+\s*季)$/u', ' $1', $t );
		return trim( $t );
	}

	/**
	 * 佇列空了就重讀兩個 sitemap 索引找新 uuid；否則抓一批作品頁前 64KB 取 og:title。
	 *
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		$q = $this->queue();

		if ( empty( $q['pending'] ) ) {

			if ( $q['sitemap_at'] > 0 && ( time() - $q['sitemap_at'] ) < self::SITEMAP_REFRESH ) {
				return [ [], 0 ];
			}

			$uuids = [];
			foreach ( self::SITEMAP_INDEXES as $index_url ) {
				$xml = $this->fetch( $index_url );
				if ( is_wp_error( $xml ) ) {
					return $xml;
				}
				// 索引檔列子檔；沒有子檔（直接是 urlset）就把自己當子檔
				$subs = preg_match_all( '#<loc>\s*(https://www\.catchplay\.com/tw/[^<\s]+-sitemap\.xml)\s*</loc>#i', (string) $xml, $sm ) ? $sm[1] : [ $index_url ];
				foreach ( $subs as $i => $sub ) {
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

			if ( empty( $uuids ) ) {
				return new WP_Error( 'no_ids', 'sitemap 解析不到任何 /tw/video/ uuid，平台可能改版' );
			}

			$pending = [];
			foreach ( array_keys( $uuids ) as $u ) {
				if ( ! isset( $q['done'][ $u ] ) ) {
					$pending[] = $u;
				}
			}

			$q['pending']    = $pending;
			$q['sitemap_at'] = time();
			$q['total']      = count( $uuids );
			$this->save_queue( $q );

			if ( empty( $pending ) ) {
				return [ [], 0 ];
			}
		}

		$grouped   = [];
		$entries   = 0;
		$processed = 0;
		$failures  = 0;

		while ( ! empty( $q['pending'] ) && $processed < self::BATCH ) {

			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				break;
			}

			$uuid = (string) array_shift( $q['pending'] );
			$processed++;

			if ( $processed > 1 ) {
				usleep( self::PAGE_INTERVAL_US );
			}

			$url  = sprintf( self::VIDEO_URL, $uuid );
			$html = $this->fetch( $url, [ 'range_bytes' => self::RANGE_BYTES, 'accept' => 'text/html', 'allow_404' => true ] );

			if ( is_wp_error( $html ) ) {
				if ( $html->get_error_code() === 'circuit_open' ) {
					array_unshift( $q['pending'], $uuid );
					$this->save_queue( $q );
					return $html;
				}
				if ( $html->get_error_code() === 'not_found' ) {
					$q['done'][ $uuid ] = 1;   // sitemap 裡殘留的死連結，標已抓、不重試
					continue;
				}
				$q['pending'][] = $uuid;
				if ( ++$failures >= self::ABORT_AFTER ) {
					break;
				}
				continue;
			}

			$failures = 0;
			$q['done'][ $uuid ] = 1;

			$parsed = $this->parse_page( (string) $html );
			if ( $parsed === null ) {
				continue;
			}

			$entries++;
			$this->add_entry( $grouped, $parsed['title'], [ 'title' => $parsed['title'], 'url' => $url, 'date' => '' ] );

			if ( $processed % 10 === 0 ) {
				$this->save_queue( $q );
			}
		}

		$this->save_queue( $q );

		return [ $grouped, $entries ];
	}

	/**
	 * og:title「《作品名》線上看｜…｜CATCHPLAY+ 正版日本動畫動漫專區」→ 作品名；非動畫專區回 null。
	 *
	 * @return array{title:string}|null
	 */
	protected function parse_page( string $html ): ?array {

		if ( ! preg_match( '#<meta\s+property="og:title"\s+content="([^"]*)"#i', $html, $m ) ) {
			return null;
		}
		$og = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 動畫過濾：後綴一定帶「動畫動漫專區」；真人影集／電影分別是「正版影集專區」「正版電影專區」
		if ( strpos( $og, '動畫動漫專區' ) === false && strpos( $og, '動畫專區' ) === false ) {
			return null;
		}

		if ( ! preg_match( '/《(.+?)》/u', $og, $t ) ) {
			return null;
		}

		$title = $this->work_name( $t[1] );
		if ( $title === '' ) {
			return null;
		}

		// 中文配音版另有一頁，站上主網址要原音版
		if ( preg_match( '/[（(]\s*(?:國語|中配|中文配音|雙語)\s*(?:版)?\s*[）)]/u', $title ) ) {
			return null;
		}

		return [ 'title' => $title ];
	}

	// ── 佇列 ──

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

	public function progress(): array {
		$q = $this->queue();
		return [ 'done' => count( $q['done'] ), 'pending' => count( $q['pending'] ), 'total' => $q['total'] ];
	}
}
