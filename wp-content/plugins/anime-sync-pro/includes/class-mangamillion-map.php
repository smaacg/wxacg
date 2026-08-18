<?php
/**
 * 檔案名稱: includes/class-mangamillion-map.php
 *
 * MANGA MILLION（集英社官方免費線上看）作品對照表。
 *
 * 它是集英社為百週年推出的官方免費閱讀服務，無需註冊、有簡體中文，
 * 對「哪裡可以線上看」是最有價值的一格。但它的搜尋是瀏覽器端算的，
 * 產不出搜尋連結（實測:亂碼查詢也會被原樣回填進頁面，三種查詢回傳
 * 長度幾乎相同），因此改為預先建立「作品名 → 網址」對照表。
 *
 * 資料怎麼來:
 *   1. /sitemap.xml 是索引，列出 13 個語系各自的 sitemap
 *      ★ 檔名帶時間戳（sitemap-zh-CN-20260818083001-1.xml），不能寫死，
 *        每次都要從索引解析
 *   2. zh-CN 的 sitemap 有 383 個 /zh-CN/title/{數字}
 *   3. 每個作品頁的 <title> 是伺服器端輸出的，格式固定
 *      「作品名 - 作者 | MANGA MILLION」
 *
 * 比對怎麼做（實測 60/383 的樣本即命中站上全部 5 部集英社作品）:
 *   · 對方是簡體 → 用既有的 OpenCC 轉繁，兩邊在同一個字集上比
 *   · 對方有些作品掛英文名（Chainsaw Man、Tokyo Ghoul）
 *     → 我們的 english / romaji 欄位也各比一輪
 *   · 沒中的多半是別家出版社（講談社、史克威爾艾尼克斯），
 *     本來就不會在這個平台上，不是比對失敗
 *
 * @package Anime_Sync_Pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_MangaMillion_Map {

	/** 對照表:正規化後的名稱 => 作品頁網址。資料量約 50KB，不可自動載入。 */
	const OPTION_MAP = 'anime_sync_mangamillion_map';

	/** 待抓取的作品頁網址清單（爬取期間暫存）。 */
	const OPTION_QUEUE = 'anime_sync_mangamillion_queue';

	/** 上次完成時間與統計。 */
	const OPTION_META = 'anime_sync_mangamillion_meta';

	const SITEMAP_INDEX = 'https://mangamillion.shueisha.co.jp/sitemap.xml';

	/** 每次 AJAX 批次處理幾頁。25 頁約 18 秒，留足餘裕不會逾時。 */
	const BATCH_SIZE = 25;

	/** 每次請求之間的間隔（微秒）。對方是別人的伺服器，不要打太急。 */
	const SLEEP_US = 700000;

	/**
	 * 取得 zh-CN sitemap 裡所有作品頁網址。
	 *
	 * @return array|WP_Error 網址陣列。
	 */
	public static function fetch_title_urls() {
		$body = self::http_get( self::SITEMAP_INDEX );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// ★ 檔名含時間戳，必須從索引解析，不能寫死
		if ( ! preg_match( '#<loc>([^<]*sitemap-zh-CN[^<]*)</loc>#', $body, $m ) ) {
			return new WP_Error( 'no_zhcn_sitemap', 'sitemap 索引中找不到 zh-CN 的項目' );
		}

		$sitemap = self::http_get( trim( $m[1] ) );

		if ( is_wp_error( $sitemap ) ) {
			return $sitemap;
		}

		if ( ! preg_match_all( '#<loc>(https://[^<]*?/zh-CN/title/\d+)</loc>#', $sitemap, $mm ) ) {
			return new WP_Error( 'no_titles', 'zh-CN sitemap 中沒有作品頁網址' );
		}

		return array_values( array_unique( $mm[1] ) );
	}

	/**
	 * 開始一輪爬取:把待處理的網址存起來，並清空舊佇列。
	 *
	 * 對照表本身「不」在這裡清空——爬到一半失敗時，舊表還能繼續服務，
	 * 總比前台突然少一格好。新資料會逐批覆蓋上去。
	 *
	 * @return array|WP_Error [ 'total' => int ]
	 */
	public static function start() {
		$urls = self::fetch_title_urls();

		if ( is_wp_error( $urls ) ) {
			return $urls;
		}

		update_option( self::OPTION_QUEUE, $urls, false );

		return [ 'total' => count( $urls ) ];
	}

	/**
	 * 處理一個批次。
	 *
	 * @param int $offset 從佇列的第幾筆開始。
	 * @return array [ 'done' => int, 'total' => int, 'added' => int, 'finished' => bool ]
	 */
	public static function crawl_batch( int $offset ): array {
		$queue = (array) get_option( self::OPTION_QUEUE, [] );
		$total = count( $queue );

		if ( 0 === $total ) {
			return [ 'done' => 0, 'total' => 0, 'added' => 0, 'finished' => true ];
		}

		$map   = (array) get_option( self::OPTION_MAP, [] );
		$slice = array_slice( $queue, $offset, self::BATCH_SIZE );
		$added = 0;

		foreach ( $slice as $url ) {
			$body = self::http_get( $url );

			if ( is_wp_error( $body ) ) {
				continue;   // 單頁失敗不中斷整輪，下次重跑會補上
			}

			if ( ! preg_match( '#<title>(.*?)</title>#su', $body, $t ) ) {
				continue;
			}

			// 「作品名 - 作者 | MANGA MILLION」——取第一段
			$raw = trim( explode( ' - ', trim( $t[1] ) )[0] );

			if ( '' === $raw ) {
				continue;
			}

			foreach ( self::name_variants( $raw ) as $key ) {
				if ( '' !== $key ) {
					$map[ $key ] = $url;
				}
			}

			$added++;
			usleep( self::SLEEP_US );
		}

		update_option( self::OPTION_MAP, $map, false );

		$done     = min( $offset + self::BATCH_SIZE, $total );
		$finished = ( $done >= $total );

		if ( $finished ) {
			update_option(
				self::OPTION_META,
				[
					'updated' => current_time( 'mysql' ),
					'titles'  => $total,
					'keys'    => count( $map ),
				],
				false
			);
			delete_option( self::OPTION_QUEUE );
		}

		return [
			'done'     => $done,
			'total'    => $total,
			'added'    => $added,
			'finished' => $finished,
		];
	}

	/**
	 * 查出某篇漫畫在 MANGA MILLION 上的網址。
	 *
	 * @param int $post_id 漫畫文章 ID。
	 * @return string 找不到時為空字串。
	 */
	public static function lookup( int $post_id ): string {
		$map = (array) get_option( self::OPTION_MAP, [] );

		if ( ! $map ) {
			return '';
		}

		$candidates = [
			get_post_meta( $post_id, 'anime_title_chinese', true ),
			get_post_meta( $post_id, 'anime_title_english', true ),
			get_post_meta( $post_id, 'anime_title_romaji', true ),
			get_post_meta( $post_id, 'anime_title_native', true ),
		];

		foreach ( $candidates as $candidate ) {
			$key = self::normalize( (string) $candidate );

			if ( '' !== $key && isset( $map[ $key ] ) ) {
				return (string) $map[ $key ];
			}
		}

		return '';
	}

	/** 對照表的統計資訊，供後台顯示。 */
	public static function stats(): array {
		$meta = (array) get_option( self::OPTION_META, [] );
		$map  = (array) get_option( self::OPTION_MAP, [] );

		return [
			'updated' => $meta['updated'] ?? '',
			'titles'  => (int) ( $meta['titles'] ?? 0 ),
			'keys'    => count( $map ),
		];
	}

	/**
	 * 一個作品名要產生哪些比對鍵。
	 *
	 * 對方是簡體站，我們的資料是繁體，因此把對方的名稱轉繁後也存一份;
	 * 兩份都存是因為有些名稱轉換前後相同（例:航海王），存兩次沒有壞處。
	 *
	 * @param string $raw 對方頁面上的作品名。
	 * @return string[]
	 */
	private static function name_variants( string $raw ): array {
		$variants = [ self::normalize( $raw ) ];

		if ( class_exists( 'Anime_Sync_CN_Converter' ) ) {
			$variants[] = self::normalize( Anime_Sync_CN_Converter::static_convert( $raw ) );
		}

		return array_unique( $variants );
	}

	/**
	 * 正規化:去掉不影響辨識的符號與大小寫差異。
	 *
	 * 兩邊的標點習慣不同（【我推之子】、輝夜大小姐～天才們的…），
	 * 不正規化會因為一個全形括號就對不上。
	 *
	 * @param string $s
	 * @return string
	 */
	private static function normalize( string $s ): string {
		$s = trim( $s );

		if ( '' === $s ) {
			return '';
		}

		$s = preg_replace( '/[\s　【】\[\]（）()～~・:：\-—_,、。!！?？\'"]/u', '', $s );

		return mb_strtolower( (string) $s, 'UTF-8' );
	}

	/**
	 * 共用的 HTTP GET。
	 *
	 * @param string $url
	 * @return string|WP_Error 回應內容。
	 */
	private static function http_get( string $url ) {
		$res = wp_remote_get(
			$url,
			[
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => [ 'User-Agent' => 'Mozilla/5.0 (compatible; wxacg/1.0; +https://weixiaoacg.com)' ],
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( 200 !== $code ) {
			return new WP_Error( 'http_error', "HTTP {$code}：{$url}" );
		}

		return (string) wp_remote_retrieve_body( $res );
	}
}
