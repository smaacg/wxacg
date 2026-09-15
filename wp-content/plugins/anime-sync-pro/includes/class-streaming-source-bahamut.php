<?php
/**
 * 檔案名稱: includes/class-streaming-source-bahamut.php
 * 巴哈姆特動畫瘋 — 直接抓取來源
 *
 * 資料來源：https://ani.gamer.com.tw/sitemap/sitemap.xml
 *   2026-09-15 實測：單一 urlset、21.6MB、34,824 個 <url>，每筆帶
 *   video:title／publication_date／rating／view_count／thumbnail。
 *   robots.txt 對 UA:* 只擋 /ajax/、search、token 等，animeVideo.php 未禁。
 *
 * 標題格式 100% 規律（34,824 筆沒有一筆例外）：
 *     作品名 [12]
 *     作品名 [12] [中文配音]
 *     作品名 [電影]
 *   去掉尾端所有 [標記] 就是作品名；歸納後 1,857 部，與 YourAnimes 巴哈頁
 *   的 1,812 部吻合。季別直接寫在作品名裡（「咒術迴戰 第二季」），正是台灣
 *   官方譯名格式。
 *
 * 網址：<loc> 是「單集」的 animeVideo.php?sn=NNNN。站上欄位歷來存的就是這種
 *   格式（見 class-acf-fields.php 該欄位說明），所以直接沿用，挑法見 pick_entry()。
 *
 * publication_date 是「該集上架時間」，不是開播日，不能當第二道比對條件
 *   （provides_start_date 回 false）。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Bahamut extends Anime_Sync_Streaming_Source_Base {

	/**
	 * 隨 repo 部署的索引包（相對外掛根目錄）。
	 *
	 * ★ 為什麼不從主機抓
	 *   正式站主機在吉隆坡，巴哈對它回 403 `cf-mitigated: challenge`（Cloudflare
	 *   人機驗證），連首頁都擋；台灣 IP 則正常。不繞驗證。
	 *   最便宜的路：在台灣 IP 的電腦跑 tools/build-bahamut-bundle.php 抓 sitemap
	 *   產出這個檔（約 250KB），git push 隨部署上去；主機端只讀檔，零外連。
	 *   歸納好的作品清單放這裡，正規化與索引鍵仍在主機端用同一套 normalize()
	 *   算，跟其他來源完全一致。
	 */
	const BUNDLE_FILE = 'data/source_bahamut_bundle.json';

	public function key(): string {
		return 'bahamut';
	}

	protected function sitemap_url(): string {
		return 'https://ani.gamer.com.tw/sitemap/sitemap.xml';
	}

	/**
	 * 主機端：從索引包讀；本機建包時（定義 ASP_BUNDLE_BUILD）才真的抓 sitemap。
	 *
	 * @param float $started
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		if ( defined( 'ASP_BUNDLE_BUILD' ) && ASP_BUNDLE_BUILD ) {
			return parent::collect_entries( $started );
		}

		$path = self::bundle_path();

		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'no_bundle', '找不到巴哈索引包 ' . self::BUNDLE_FILE . '，請在台灣 IP 執行 tools/build-bahamut-bundle.php 後部署' );
		}

		$data = json_decode( (string) file_get_contents( $path ), true );

		if ( ! is_array( $data ) || empty( $data['works'] ) || ! is_array( $data['works'] ) ) {
			return new WP_Error( 'bad_bundle', '巴哈索引包格式不對或沒有作品' );
		}

		$grouped = [];
		foreach ( $data['works'] as $w ) {
			$n = trim( (string) ( $w['n'] ?? '' ) );
			$u = trim( (string) ( $w['u'] ?? '' ) );
			if ( $n === '' || $u === '' ) {
				continue;
			}
			$grouped[ $n ] = [ 'title' => $n, 'url' => $u, 'date' => '' ];
		}

		return [ $grouped, (int) ( $data['entries'] ?? count( $grouped ) ) ];
	}

	/**
	 * 給 tools/build-bahamut-bundle.php 用：在台灣 IP 抓 sitemap，輸出索引包。
	 *
	 * @return array{entries:int,works:int,bytes:int}|WP_Error
	 */
	public function export_bundle( string $path ) {

		$collected = parent::collect_entries( microtime( true ) );

		if ( is_wp_error( $collected ) ) {
			return $collected;
		}

		[ $grouped, $entries ] = $collected;

		if ( empty( $grouped ) ) {
			return new WP_Error( 'no_entries', 'sitemap 解析不到任何作品' );
		}

		$works = [];
		foreach ( $grouped as $name => $picked ) {
			$works[] = [ 'n' => (string) $name, 'u' => (string) $picked['url'] ];
		}

		$json = wp_json_encode( [
			'built'   => time(),
			'source'  => $this->sitemap_url(),
			'entries' => $entries,
			'works'   => $works,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( file_put_contents( $path, (string) $json ) === false ) {
			return new WP_Error( 'write_failed', '寫不進 ' . $path );
		}

		return [ 'entries' => $entries, 'works' => count( $works ), 'bytes' => strlen( (string) $json ) ];
	}

	public static function bundle_path(): string {
		$dir = defined( 'ANIME_SYNC_PRO_DIR' ) ? ANIME_SYNC_PRO_DIR : dirname( __DIR__ ) . '/';
		return $dir . self::BUNDLE_FILE;
	}

	protected function parse_entry( string $block ): ?array {

		if ( ! preg_match( '#<loc>\s*([^<\s]+)\s*</loc>#i', $block, $loc ) ) {
			return null;
		}
		if ( ! preg_match( '#<video:title>(.*?)</video:title>#si', $block, $title ) ) {
			return null;
		}

		$url = html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );

		// 只收作品播放頁；sitemap 裡若混入其他頁面一律略過
		if ( strpos( $url, 'animeVideo.php?sn=' ) === false ) {
			return null;
		}

		$t = html_entity_decode( trim( strip_tags( $title[1] ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' );

		$date = '';
		if ( preg_match( '#<video:publication_date>\s*([^<\s]+)\s*</video:publication_date>#i', $block, $d ) ) {
			$date = substr( trim( $d[1] ), 0, 10 );
		}

		return [ 'title' => $t, 'url' => $url, 'date' => $date ];
	}

	/** 去掉尾端所有 [標記]。實測 34,824 筆歸納後只有 5 筆殘留方括號，可忽略。 */
	protected function work_name( string $title ): string {

		$prev = null;
		$t    = trim( $title );

		while ( $prev !== $t ) {
			$prev = $t;
			$t    = trim( (string) preg_replace( '/\s*\[[^\]]*\]\s*$/u', '', $t ) );
		}

		return $t;
	}

	/**
	 * 一部作品有幾十筆單集條目，存哪一筆？
	 *
	 *   1. 先排除 [中文配音]——那是另一條播放清單，站上另有 anime_dub_url_mandarin
	 *      欄位放它，這裡不混進主網址（本輪不寫中配欄位，留待之後）。
	 *   2. 標題尾端是 [1] 的就是第一集，優先。
	 *   3. 都沒有（電影、或第一集已下架）就取 sn 最小的，那通常也是最早的一集。
	 *
	 * @param array<int,array{title:string,url:string,date?:string}> $entries
	 */
	protected function pick_entry( array $entries ): array {

		$main = array_values( array_filter( $entries, static function ( $e ) {
			return mb_strpos( (string) $e['title'], '[中文配音]' ) === false;
		} ) );

		if ( empty( $main ) ) {
			$main = $entries;
		}

		foreach ( $main as $e ) {
			if ( preg_match( '/\[1\]\s*(?:\[[^\]]*\]\s*)*$/u', (string) $e['title'] ) ) {
				return $e;
			}
		}

		usort( $main, static function ( $a, $b ) {
			return self::sn( $a['url'] ) <=> self::sn( $b['url'] );
		} );

		return $main[0];
	}

	private static function sn( string $url ): int {
		return preg_match( '/sn=(\d+)/', $url, $m ) ? (int) $m[1] : PHP_INT_MAX;
	}
}
