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

class Anime_Sync_Streaming_Source_Bahamut extends Anime_Sync_Streaming_Source_Bundle_Base {

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

	/** 巴哈 ACG 資料庫作品頁（bangumi-data gamer 站點的 id 就是這個 s=）與動畫瘋作品頁 */
	const ACG_DETAIL_URL  = 'https://acg.gamer.com.tw/acgDetail.php?s=';
	const ANIME_VIDEO_URL = 'https://ani.gamer.com.tw/animeVideo.php?sn=';

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
			return $this->collect_from_sitemap( $started );
		}

		// 讀檔、解析、組 grouped 都在 Bundle_Base（三家共用）
		$loaded = $this->load_bundle();
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		[ $grouped, $entries ] = $loaded;

		$data = (array) $this->bundle_raw();

		/*
		 * bangumi-data 的 gamer 站點給的是巴哈「ACG 編號」（acgDetail.php?s=），不是動畫瘋的 sn。
		 * 索引包裡的 acg 對照（本機建包時從 acgDetail 頁「動畫瘋線上看」區塊解析，見 resolve_acg_sn）
		 * 把它換成動畫瘋作品頁；0 代表那頁沒有動畫瘋區塊（不在動畫瘋上）→ 不收。
		 * 還沒解析到的（bangumi-data 新增、本機還沒建包）也不收：2026-09-15 首次解析 1,691 個編號
		 * 有 42 個（2.5%）沒有動畫瘋區塊，若先用 acgDetail 網址頂著，這 2.5% 會被寫成錯的巴哈連結，
		 * 而且 write() 只補空白、之後不會自己換成動畫瘋網址。寧可等下週建包。
		 */
		$acg      = is_array( $data['acg'] ?? null ) ? $data['acg'] : [];
		$entries += $this->merge_bangumi_data( $grouped, [ 'gamer' ], static function ( string $site, string $id ) use ( $acg ): string {
			$sn = (int) ( $acg[ $id ] ?? 0 );
			return $sn > 0 ? self::ANIME_VIDEO_URL . $sn : '';
		} );

		return [ $grouped, $entries ];
	}

	/**
	 * ACG 編號 → 動畫瘋第一集 sn 的對照表。沿用上一包已解析出的（>0），只抓新出現的
	 * 與上次沒有動畫瘋區塊的（0，可能後來上架）。本機建包專用。
	 *
	 * @param array<string,int> $prev     上一包的 acg 對照
	 * @param callable|null     $progress fn( int $done, int $total, string $acg_id, ?int $sn )
	 * @return array<string,int>
	 */
	protected function resolve_acg_map( array $prev, ?callable $progress = null ): array {

		if ( ! class_exists( 'Anime_Sync_Bangumi_Data_Feed' ) ) {
			return $prev;
		}

		$ids = [];
		foreach ( Anime_Sync_Bangumi_Data_Feed::map() as $sites ) {
			if ( ! empty( $sites['gamer'] ) ) {
				$ids[ (string) $sites['gamer'] ] = true;
			}
		}

		$acg  = [];
		$todo = [];
		foreach ( array_keys( $ids ) as $id ) {
			$id = (string) $id;
			if ( isset( $prev[ $id ] ) && (int) $prev[ $id ] > 0 ) {
				$acg[ $id ] = (int) $prev[ $id ];
			} else {
				$todo[] = $id;
			}
		}

		$done = 0;
		foreach ( $todo as $id ) {
			$sn = $this->resolve_acg_sn( $id );
			if ( $sn === null ) {
				// 抓不到就保留上一包的值（若有），下次建包再試；不把失敗當成「不在動畫瘋」
				if ( isset( $prev[ $id ] ) ) {
					$acg[ $id ] = (int) $prev[ $id ];
				}
			} else {
				$acg[ $id ] = $sn;
			}
			$done++;
			if ( $progress ) {
				$progress( $done, count( $todo ), $id, $sn );
			}
			usleep( 400000 );
		}

		ksort( $acg, SORT_NATURAL );   // 順序固定，沒新資料的那週 git diff 才會是空的

		return $acg;
	}

	/**
	 * 讀 acgDetail 頁，取「動畫瘋線上看」區塊第一個 animeVideo sn（就是第 1 集）。
	 *
	 * 2026-09-15 實測 s=142899：`<h4>…動畫瘋線上看</h4><div class="ACG-list5"><div class="seasonACG">
	 * <ul><li><a href="//ani.gamer.com.tw/animeVideo.php?sn=49914">1</a>…`；沒上動畫瘋的作品
	 * （s=103776）整頁沒有這個區塊。ani.gamer.com.tw/animeRef.php?sn= 不能用，回的是系統訊息頁。
	 *
	 * @return int|null 第一集 sn；0＝該頁沒有動畫瘋區塊；null＝抓取失敗或頁面不是作品頁
	 */
	protected function resolve_acg_sn( string $acg_id ): ?int {

		$res = wp_remote_get( self::ACG_DETAIL_URL . rawurlencode( $acg_id ), [
			'timeout'    => 30,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
		] );

		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return null;
		}

		$html = (string) wp_remote_retrieve_body( $res );

		// 系統訊息頁、Cloudflare 驗證頁都是 200，但不是作品頁；不能把它們當成「沒有動畫瘋」
		if ( strpos( $html, '系統訊息' ) !== false || strpos( $html, 'ACG-' ) === false ) {
			return null;
		}

		$pos = strpos( $html, '動畫瘋線上看' );
		if ( $pos === false ) {
			return 0;
		}
		if ( preg_match( '#animeVideo\.php\?sn=(\d+)#', $html, $m, 0, $pos ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * 給 tools/build-bahamut-bundle.php 用：在台灣 IP 抓 sitemap，輸出索引包。
	 *
	 * @return array{entries:int,works:int,bytes:int}|WP_Error
	 */
	public function export_bundle( string $path, ?callable $progress = null ) {

		$collected = $this->collect_from_sitemap( microtime( true ) );

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

		// ACG 編號 → 動畫瘋 sn：沿用上一包，只補新的（見 resolve_acg_map）
		$prev = [];
		if ( is_readable( $path ) ) {
			$old  = json_decode( (string) file_get_contents( $path ), true );
			$prev = is_array( $old['acg'] ?? null ) ? $old['acg'] : [];
		}
		$acg = $this->resolve_acg_map( $prev, $progress );

		/*
		 * 刻意不放時間戳。索引包由本機排程每週產出並自動 commit，
		 * 有時間戳就每週必產生一個內容其實沒變的 commit；拿掉之後
		 * 巴哈沒新作品的那週 git diff 為空、不 commit。建包時間看 git log。
		 */
		$json = wp_json_encode( [
			'source'  => $this->sitemap_url(),
			'entries' => $entries,
			'works'   => $works,
			'acg'     => $acg,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( file_put_contents( $path, (string) $json ) === false ) {
			return new WP_Error( 'write_failed', '寫不進 ' . $path );
		}

		return [ 'entries' => $entries, 'works' => count( $works ), 'acg' => count( $acg ), 'acg_prev' => count( $prev ), 'bytes' => strlen( (string) $json ) ];
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
