<?php
/**
 * 檔案名稱: includes/class-youranimes-title-index.php
 * YourAnimes 全站標題索引 — 季度新番表配不到時的備援
 *
 * ★ 為什麼需要這個
 *   既有的 class-youranimes-season-index.php 只讀季度新番表
 *   （/bangumi/YYYYMM），而那份表不收劇場版與 OVA。實測站上 353 部已有
 *   YourAnimes 網址的作品，用 resolve() 反過來配：
 *
 *       TV        269 部 → 232 命中（86.2%）
 *       TV_SHORT    5 部 →   5 命中（100%）
 *       ONA        12 部 →  10 命中（83.3%）
 *       SPECIAL    10 部 →   6 命中（60.0%）
 *       MOVIE      49 部 →   0 命中（0%）
 *       OVA         8 部 →   0 命中（0%）
 *
 *   劇場版與 OVA 是整整 0%——那 57 部站上的網址全是人工補的。
 *
 * ★ 資料從哪來
 *   youranimes.tw/sitemap.xml 列出全站 6,478 個作品頁（ID 1–6576），
 *   但只有網址沒有標題，所以必須逐頁抓 JSON-LD 取 name／alternateName／
 *   datePublished。robots.txt 是 `User-agent: *  Allow: /`，允許抓取。
 *
 * ★ 為什麼一定要日期當第二道條件
 *   只比對標題會誤配，而且錯得很難發現。實例：MAL 給
 *   「擅長捉弄人的高木同學 劇場版」(MAL 49722) 的日文原名是
 *   『からかい上手の高木さん』，與 TV 第一季一字不差。只比標題的話劇場版
 *   會配到 TV 版的頁面，然後把 TV 版的台灣串流寫進劇場版。
 *
 *   加上 datePublished 就擋掉了：TV 版頁面是 2018-01-08、劇場版是
 *   2022-06-10。實測 57 部劇場版/OVA：
 *       標題精確相符 48（84.2%）、日期相符 50（87.7%）、
 *       兩者同時相符 42（73.7%）。
 *   對照現況 0%，而且 YA 頁面 100% 都有 datePublished。
 *
 *   再加一道「符合的候選必須唯一」，與既有程式移除模糊比對時的紀律一致
 *   （見 class-youranimes-season-index.php::resolve() 的長註解）。
 *
 * ★ 抓取順序由新到舊
 *   使用者現在會匯入的是近期作品，ID 大的先抓，第一輪（300 頁）就涵蓋
 *   最新的 300 部。若由小到大，新作品要等 22 小時後才輪到，索引在建立
 *   期間形同無用。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_YourAnimes_Title_Index {

	const INDEX_FILE   = 'ya_title_index.json';
	const STATE_OPTION = 'anime_sync_ya_title_index_state';
	const HOOK_HOURLY  = 'anime_sync_ya_title_index_build';

	/** 每輪抓幾頁。1 req/s，300 頁約 6～7 分鐘，全站 6,478 頁約 22 小時建完。 */
	const PAGES_PER_RUN = 300;

	/*
	 * 本輪的時間預算（秒）。
	 *
	 * ★ 沒有這道保險，索引會永遠建不起來。
	 *   300 頁 × 約 1.3 秒 ≈ 390 秒，超過多數主機的 max_execution_time。
	 *   而進度只在迴圈跑完後才寫檔——被硬中斷就什麼都沒存，下一輪重跑
	 *   同樣的 300 頁，永遠停在原地。
	 *
	 *   預算用盡就正常收尾、存好進度，下一輪從斷點continue。做法與
	 *   class-cron-manager.php 的 BATCH_TIME_BUDGET 一致。
	 */
	const TIME_BUDGET = 200;

	/*
	 * 每處理這麼多頁就先存一次。
	 *
	 * ★ 這個數字踩過一次坑，別再調大。
	 *   原本設 50，等於要跑到約 65 秒才第一次落地。正式站的 cron 是靠外部
	 *   觸發（wp-config 有 DISABLE_WP_CRON），實際允許的執行時間有限——
	 *   2026-09-12 首次上線時排程確實觸發了，卻連 state option 都沒建立，
	 *   就是在第一次存檔前被砍掉，整批白做。手動用 WP-CLI 跑同一支則完全
	 *   正常（3 頁 3.1 秒），可見不是程式邏輯的問題。
	 *
	 *   10 頁約 13 秒，被砍最多只損失這麼多。時間預算維持 200 秒是樂觀值：
	 *   執行環境給多少就用多少，給得少也不會前功盡棄。
	 */
	const SAVE_EVERY = 10;

	/** 請求間隔（微秒）。對方是小站，1 秒一頁已是客氣的節奏。 */
	const REQUEST_INTERVAL_US = 1000000;

	const SITEMAP_URL       = 'https://youranimes.tw/sitemap.xml';
	const SITEMAP_CACHE_KEY = 'asp_ya_sitemap_ids';
	const SITEMAP_CACHE_TTL = DAY_IN_SECONDS;

	/** 連續失敗這麼多次就中止本輪，剩下的留到下一輪。 */
	const ABORT_AFTER_FAILURES = 10;

	/** 記憶體中的索引，避免同一次請求重複讀檔。 */
	private static ?array $index = null;

	public function __construct() {
		add_action( self::HOOK_HOURLY, [ $this, 'run_build_batch' ] );
	}

	// -------------------------------------------------------------------------
	// 排程
	// -------------------------------------------------------------------------

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_HOURLY ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::HOOK_HOURLY );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK_HOURLY );

		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_HOURLY );
		}

		wp_clear_scheduled_hook( self::HOOK_HOURLY );
	}

	// -------------------------------------------------------------------------
	// 查詢
	// -------------------------------------------------------------------------

	/**
	 * 用標題＋開播日反查 YourAnimes 網址。
	 *
	 * @param string[] $titles     可用來比對的標題（日文原名、羅馬字…）。
	 * @param string   $start_date 站上的 anime_start_date（YYYYMMDD）。
	 * @return string 命中回傳完整網址，否則空字串。
	 */
	public static function lookup( array $titles, string $start_date ): string {

		$date = preg_replace( '/[^0-9]/', '', $start_date );

		// 沒有日期就不比對——少了第二道條件，誤配的風險見檔頭說明
		if ( strlen( (string) $date ) !== 8 ) {
			return '';
		}

		$index = self::load_index();

		if ( empty( $index ) ) {
			return '';
		}

		$hits = [];

		foreach ( $titles as $title ) {
			$key = self::normalize( (string) $title );

			if ( $key === '' || ! isset( $index[ $key ] ) ) {
				continue;
			}

			foreach ( (array) $index[ $key ] as $entry ) {
				$entry_date = preg_replace( '/[^0-9]/', '', (string) ( $entry['d'] ?? '' ) );

				if ( $entry_date === $date ) {
					$hits[ (int) $entry['i'] ] = true;
				}
			}
		}

		/*
		 * 唯一才採用。兩個以上代表同名同日期，分不出是哪一部——
		 * 寧可不配，也不要把別部作品的串流寫進來。
		 */
		if ( count( $hits ) !== 1 ) {
			return '';
		}

		return 'https://youranimes.tw/animes/' . array_key_first( $hits );
	}

	// -------------------------------------------------------------------------
	// 建立索引
	// -------------------------------------------------------------------------

	public function run_build_batch(): void {
		$this->build_batch( self::PAGES_PER_RUN );
	}

	/**
	 * 抓一批頁面並併進索引。
	 *
	 * @return array{fetched:int,indexed:int,failed:int,remaining:int}
	 */
	public function build_batch( int $limit ): array {

		$stats = [ 'fetched' => 0, 'indexed' => 0, 'failed' => 0, 'remaining' => 0 ];

		if ( class_exists( 'Anime_Sync_Performance' ) ) {
			Anime_Sync_Performance::set_time_limit( self::TIME_BUDGET + 60 );
		}

		$started = microtime( true );

		$state   = self::get_state();
		$pending = $state['pending'];

		// 佇列空了就重新從 sitemap 建（新作品的 ID 會比既有的大）
		if ( empty( $pending ) ) {
			$pending = self::build_pending_from_sitemap( $state['done'] );

			if ( empty( $pending ) ) {
				return $stats;
			}
		}

		$index = self::load_index();
		$done  = $state['done'];

		$consecutive_failures = 0;
		$processed            = 0;

		foreach ( $pending as $pos => $id ) {

			if ( $processed >= $limit ) {
				break;
			}

			// 時間預算用盡就收尾，已抓到的照樣存起來，下一輪從斷點繼續
			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				break;
			}

			$processed++;

			if ( $processed > 1 ) {
				usleep( self::REQUEST_INTERVAL_US );
			}

			$html = Anime_Sync_YourAnimes_Season_Index::fetch_html_public(
				'https://youranimes.tw/animes/' . $id
			);

			if ( is_wp_error( $html ) ) {
				$stats['failed']++;
				$consecutive_failures++;

				/*
				 * 連續失敗代表對方掛了或熔斷開啟，繼續打沒有意義。
				 * 這一批未處理的留在佇列裡，下一輪再來。
				 */
				if ( $consecutive_failures >= self::ABORT_AFTER_FAILURES ) {
					break;
				}

				continue;
			}

			$consecutive_failures = 0;
			$stats['fetched']++;

			$meta = self::parse_page( (string) $html );

			// 本輪不再處理這一頁
			unset( $pending[ $pos ] );

			/*
			 * ★ 沒有日期的不記進 done，下一輪要重抓。
			 *
			 *   還沒公布檔期的新作品，YourAnimes 的 JSON-LD 沒有
			 *   datePublished（實測 ID 6569–6576 全都沒有）。而 lookup()
			 *   要靠日期當第二道條件，沒日期就配不了。
			 *
			 *   若在這裡記成 done，等它日後公布檔期，索引也永遠不會補上
			 *   ——而那正好是使用者最常匯入的那批新番。留在待抓清單裡，
			 *   下一輪（sitemap 重建後）會再看一次。
			 */
			if ( empty( $meta['titles'] ) || $meta['date'] === '' ) {
				continue;
			}

			$done[ $id ] = true;

			foreach ( $meta['titles'] as $title ) {
				foreach ( self::index_keys( $title ) as $key ) {

					if ( ! isset( $index[ $key ] ) ) {
						$index[ $key ] = [];
					}

					// 同一個 id 不重複塞（name 與 alternateName 正規化後可能相同）
					$dup = false;
					foreach ( $index[ $key ] as $existing ) {
						if ( (int) $existing['i'] === $id ) {
							$dup = true;
							break;
						}
					}

					if ( ! $dup ) {
						$index[ $key ][] = [ 'i' => $id, 'd' => $meta['date'] ];
					}
				}
			}

			$stats['indexed']++;

			/*
			 * 中途也存。時間預算擋不住所有情況（主機硬砍、記憶體不足、
			 * 部署重啟），只在最後存的話那些情況會整批白做。
			 */
			if ( $processed % self::SAVE_EVERY === 0 ) {
				self::persist( $index, $pending, $done );
			}
		}

		$stats['remaining'] = count( $pending );

		self::persist( $index, $pending, $done );

		return $stats;
	}

	/** 把索引與進度一起落地。兩者必須同時更新，否則會重抓或漏抓。 */
	private static function persist( array $index, array $pending, array $done ): void {
		self::save_index( $index );
		self::save_state( [
			'pending' => array_values( $pending ),
			'done'    => $done,
			'updated' => time(),
			'keys'    => count( $index ),
		] );
	}

	/**
	 * 從 sitemap 取出還沒抓過的作品 ID，由新到舊排序。
	 *
	 * @param array<int,bool> $done 已經抓過的 ID。
	 * @return int[]
	 */
	private static function build_pending_from_sitemap( array $done ): array {

		$ids = get_transient( self::SITEMAP_CACHE_KEY );

		if ( ! is_array( $ids ) ) {
			$xml = Anime_Sync_YourAnimes_Season_Index::fetch_html_public( self::SITEMAP_URL );

			if ( is_wp_error( $xml ) ) {
				self::log_warning( 'sitemap 抓取失敗：' . $xml->get_error_message() );
				return [];
			}

			$ids = [];

			if ( preg_match_all( '#<loc>\s*https?://(?:www\.)?youranimes\.tw/animes/(\d+)\s*</loc>#i', (string) $xml, $m ) ) {
				$ids = array_map( 'intval', $m[1] );
			}

			if ( empty( $ids ) ) {
				self::log_warning( 'sitemap 解析不到任何作品頁' );
				return [];
			}

			$ids = array_values( array_unique( $ids ) );
			rsort( $ids, SORT_NUMERIC );   // 由新到舊，理由見檔頭

			set_transient( self::SITEMAP_CACHE_KEY, $ids, self::SITEMAP_CACHE_TTL );
		}

		$pending = [];

		foreach ( $ids as $id ) {
			if ( empty( $done[ $id ] ) ) {
				$pending[] = (int) $id;
			}
		}

		return $pending;
	}

	/**
	 * 從作品頁的 JSON-LD 取出標題與上映日期。
	 *
	 * 頁面裡有兩段 JSON-LD：一段是網站本身（name 為「YourAnimes 你的動畫」），
	 * 一段才是作品。前者要跳過，否則整站每一頁都會被同一個標題索引起來。
	 *
	 * @return array{titles:string[],date:string}
	 */
	private static function parse_page( string $html ): array {

		$titles = [];
		$date   = '';

		if ( ! preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#su', $html, $m ) ) {
			return [ 'titles' => [], 'date' => '' ];
		}

		foreach ( $m[1] as $json ) {
			$data = json_decode( trim( $json ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$name = (string) ( $data['name'] ?? '' );

			// 網站本身那一段，不是作品
			if ( $name === '' || mb_strpos( $name, 'YourAnimes' ) !== false ) {
				continue;
			}

			$titles[] = $name;

			foreach ( (array) ( $data['alternateName'] ?? [] ) as $alt ) {
				if ( is_string( $alt ) && $alt !== '' ) {
					$titles[] = $alt;
				}
			}

			if ( $date === '' && ! empty( $data['datePublished'] ) ) {
				$date = (string) $data['datePublished'];
			}
		}

		return [ 'titles' => array_values( array_unique( $titles ) ), 'date' => $date ];
	}

	// -------------------------------------------------------------------------
	// 狀態與儲存
	// -------------------------------------------------------------------------

	/**
	 * 建立進度，供設定頁顯示。
	 *
	 * @return array{done:int,total:int,pending:int,keys:int,updated:int,ready:bool}
	 */
	public static function get_status(): array {

		$state = self::get_state();
		$ids   = get_transient( self::SITEMAP_CACHE_KEY );

		$done  = count( $state['done'] );
		$total = is_array( $ids ) ? count( $ids ) : $done + count( $state['pending'] );

		return [
			'done'    => $done,
			'total'   => $total,
			'pending' => count( $state['pending'] ),
			'keys'    => (int) $state['keys'],
			'updated' => (int) $state['updated'],
			'ready'   => $done > 0,
		];
	}

	private static function get_state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return [
			'pending' => is_array( $state['pending'] ?? null ) ? $state['pending'] : [],
			'done'    => is_array( $state['done'] ?? null ) ? $state['done'] : [],
			'updated' => (int) ( $state['updated'] ?? 0 ),
			'keys'    => (int) ( $state['keys'] ?? 0 ),
		];
	}

	private static function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function index_path(): string {
		$dir = wp_upload_dir();

		return trailingslashit( $dir['basedir'] ) . 'anime-sync-pro/' . self::INDEX_FILE;
	}

	private static function load_index(): array {

		if ( self::$index !== null ) {
			return self::$index;
		}

		$path = self::index_path();

		if ( ! file_exists( $path ) ) {
			self::$index = [];
			return self::$index;
		}

		$raw  = (string) file_get_contents( $path );
		$data = json_decode( $raw, true );

		self::$index = is_array( $data ) ? $data : [];

		return self::$index;
	}

	private static function save_index( array $index ): void {

		$path = self::index_path();
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		file_put_contents(
			$path,
			(string) wp_json_encode( $index, JSON_UNESCAPED_UNICODE )
		);

		self::$index = $index;
	}

	/**
	 * 一個標題要建立哪幾個索引鍵。
	 *
	 * ★ 為什麼要多一個去掉「劇場版」的別名
	 *   YourAnimes 的劇場版條目常寫成『劇場版「作品名」』，而 MAL／AniList
	 *   給的日文原名通常就是『作品名』本身。實例：高木同學劇場版
	 *   （MAL 49722）的 ja 是『からかい上手の高木さん』，YA 那頁的
	 *   alternateName 是『劇場版「からかい上手の高木さん」』——正規化會去掉
	 *   「」但留下「劇場版」，於是配不到。
	 *
	 * ★ 為什麼這樣不會誤配
	 *   去掉前綴後，劇場版與 TV 版會共用同一個鍵（都是
	 *   『からかい上手の高木さん』）。單看標題確實分不出來，但 lookup()
	 *   還要求 datePublished 相符且候選唯一——TV 版是 2018-01-08、劇場版是
	 *   2022-06-10，日期一比就分開了。別名放寬的是召回，不是精度。
	 *
	 * @return string[] 去重後的索引鍵（空字串已排除）。
	 */
	private static function index_keys( string $title ): array {

		$keys = [];

		$base = self::normalize( $title );

		if ( $base !== '' ) {
			$keys[] = $base;
		}

		/*
		 * 正規化已經把「」（）等符號與空白拿掉，所以這裡只需要處理
		 * 剩下的純文字前後綴。
		 */
		$stripped = preg_replace(
			'/^(?:劇場版|劇场版|映画|gekijouban|gekijoban|themovie|movie)/u',
			'',
			$base
		);
		$stripped = preg_replace(
			'/(?:劇場版|映画|themovie|movie)$/u',
			'',
			(string) $stripped
		);
		$stripped = trim( (string) $stripped );

		if ( $stripped !== '' && $stripped !== $base ) {
			$keys[] = $stripped;
		}

		return array_values( array_unique( $keys ) );
	}

	/** 與季度索引共用同一套正規化規則，兩邊的鍵才對得起來。 */
	private static function normalize( string $s ): string {
		if ( ! class_exists( 'Anime_Sync_YourAnimes_Season_Index' ) ) {
			return '';
		}

		return Anime_Sync_YourAnimes_Season_Index::normalize_public( $s );
	}

	private static function log_warning( string $message ): void {
		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::warning( 'YourAnimes 標題索引：' . $message );
		}
	}
}
