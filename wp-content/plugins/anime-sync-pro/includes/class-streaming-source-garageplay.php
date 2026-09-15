<?php
/**
 * 檔案名稱: includes/class-streaming-source-garageplay.php
 * 車庫娛樂 AniPASS 免費動畫專區 — 直接抓取來源（本機索引包，同巴哈模式）
 *
 * 2026-09-15 實測
 * ---------------
 *   - AniPASS 不是 YouTube 頻道（@AniPASS 是空帳號），是車庫官網的專區：
 *       清單 https://garageplay.tw/anipass/AnipassVideo（153 部、不分頁、卡片只有圖與「全 N 集」沒有標題）
 *       作品 https://garageplay.tw/anipass/AnipassVideo/Player/{id}，og:title「作品名 | Anipass 動畫」
 *     站上既有的車庫網址（YA 給的 18 筆）全是這個 Player 網址，所以寫進 garageplay 這個 key。
 *   - 正式站主機（吉隆坡）被 WAF 擋 403；用 check-host 測 14 國機房：6 個 403、5 個 302 正常，
 *     不是巴哈那種「機房一律擋」，但也不值得為 153 部去開 GCP。台灣住宅 IP 正常且伺服器渲染，
 *     所以跟巴哈一樣：本機週日建索引包 data/source_garageplay_bundle.json 隨 git push 部署，主機只讀檔。
 *   - 沒有到期日欄位。
 *
 * 索引包由 tools/build-bahamut-bundle.php 順便產出（同一支排程、同一次 commit）。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Garageplay extends Anime_Sync_Streaming_Source_Base {

	const BUNDLE_FILE = 'data/source_garageplay_bundle.json';
	const LIST_URL    = 'https://garageplay.tw/anipass/AnipassVideo';
	const PLAYER_URL  = 'https://garageplay.tw/anipass/AnipassVideo/Player/%d';
	const PAGE_INTERVAL_US = 500000;

	public function key(): string {
		return 'garageplay';
	}

	protected function sitemap_url(): string {
		return '';
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/** 「灰色：幻影扳機 動畫版 Stargazer | Anipass 動畫」→ 去掉站名尾綴 */
	protected function work_name( string $title ): string {
		$t = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$t = (string) preg_replace( '/\s*\|\s*Anipass\s*動畫\s*$/iu', '', $t );
		return trim( $t );
	}

	public static function bundle_path(): string {
		$dir = defined( 'ANIME_SYNC_PRO_DIR' ) ? ANIME_SYNC_PRO_DIR : dirname( __DIR__ ) . '/';
		return $dir . self::BUNDLE_FILE;
	}

	/**
	 * 主機端讀索引包；本機建包（定義 ASP_BUNDLE_BUILD）才真的爬。
	 *
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		if ( defined( 'ASP_BUNDLE_BUILD' ) && ASP_BUNDLE_BUILD ) {
			return $this->crawl( $started );
		}

		$path = self::bundle_path();
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'no_bundle', '找不到車庫索引包 ' . self::BUNDLE_FILE . '，請在台灣 IP 執行 tools/build-bahamut-bundle.php 後部署' );
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || empty( $data['works'] ) || ! is_array( $data['works'] ) ) {
			return new WP_Error( 'bad_bundle', '車庫索引包格式不對或沒有作品' );
		}

		$grouped = [];
		foreach ( $data['works'] as $w ) {
			$n = trim( (string) ( $w['n'] ?? '' ) );
			$u = trim( (string) ( $w['u'] ?? '' ) );
			if ( $n !== '' && $u !== '' ) {
				$grouped[ $n ] = [ 'title' => $n, 'url' => $u, 'date' => '' ];
			}
		}

		return [ $grouped, (int) ( $data['entries'] ?? count( $grouped ) ) ];
	}

	/**
	 * 本機建包：清單頁取 Player id → 每頁 og:title。153 頁約 2 分鐘。
	 *
	 * @param callable|null $progress fn( int $done, int $total, string $title )
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function crawl( float $started, ?callable $progress = null ) {

		$html = $this->fetch( self::LIST_URL, [ 'accept' => 'text/html' ] );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		if ( ! preg_match_all( '#/anipass/AnipassVideo/Player/(\d+)#', (string) $html, $m ) ) {
			return new WP_Error( 'no_ids', 'AniPASS 清單頁解析不到任何 Player id，平台可能改版' );
		}

		$ids = array_values( array_unique( array_map( 'intval', $m[1] ) ) );
		sort( $ids );

		$grouped = [];
		$entries = 0;
		$done    = 0;

		foreach ( $ids as $id ) {
			if ( $done > 0 ) {
				usleep( self::PAGE_INTERVAL_US );
			}
			$page = $this->fetch( sprintf( self::PLAYER_URL, $id ), [ 'accept' => 'text/html', 'allow_404' => true ] );
			$done++;
			if ( is_wp_error( $page ) ) {
				if ( $page->get_error_code() === 'circuit_open' ) {
					return $page;
				}
				continue;
			}
			if ( ! preg_match( '#<meta\s+property="og:title"\s+content="([^"]*)"#i', (string) $page, $t ) ) {
				continue;
			}
			$work = $this->work_name( $t[1] );
			if ( $work === '' || $work === '動畫' ) {
				continue;   // 「動畫 | Anipass 動畫」是清單頁本身，不是作品
			}
			// 中文配音版另有一頁（「…(國語版)」），站上主網址要原音版，跟其他來源一致
			if ( preg_match( '/[（(]\s*國語版?\s*[）)]\s*$/u', $work ) ) {
				continue;
			}
			$entries++;
			$this->add_entry( $grouped, $work, [ 'title' => $t[1], 'url' => sprintf( self::PLAYER_URL, $id ), 'date' => '' ] );
			if ( $progress ) {
				$progress( $done, count( $ids ), $work );
			}
		}

		if ( count( $grouped ) < 50 ) {
			return new WP_Error( 'too_few', sprintf( '只收到 %d 部（預期 150 上下），平台可能改版', count( $grouped ) ) );
		}

		return [ $grouped, $entries ];
	}

	/** 同名多個 Player（重播）取 id 最小 */
	protected function pick_entry( array $entries ): array {
		usort( $entries, static function ( $a, $b ) {
			preg_match( '/(\d+)$/', $a['url'], $x );
			preg_match( '/(\d+)$/', $b['url'], $y );
			return ( (int) ( $x[1] ?? 0 ) ) <=> ( (int) ( $y[1] ?? 0 ) );
		} );
		return $entries[0];
	}

	/**
	 * 給建包工具用：爬完寫索引包。跟巴哈一樣不放時間戳，內容沒變就不產生 commit。
	 *
	 * @return array{entries:int,works:int,bytes:int}|WP_Error
	 */
	public function export_bundle( string $path, ?callable $progress = null ) {

		$collected = $this->crawl( microtime( true ), $progress );
		if ( is_wp_error( $collected ) ) {
			return $collected;
		}
		[ $grouped, $entries ] = $collected;

		$works = [];
		foreach ( $grouped as $name => $picked ) {
			$works[] = [ 'n' => (string) $name, 'u' => (string) $picked['url'] ];
		}
		usort( $works, static fn( $a, $b ) => strcmp( $a['u'], $b['u'] ) );

		$json = wp_json_encode( [ 'source' => self::LIST_URL, 'entries' => $entries, 'works' => $works ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( file_put_contents( $path, (string) $json ) === false ) {
			return new WP_Error( 'write_failed', '寫不進 ' . $path );
		}

		return [ 'entries' => $entries, 'works' => count( $works ), 'bytes' => strlen( (string) $json ) ];
	}
}
