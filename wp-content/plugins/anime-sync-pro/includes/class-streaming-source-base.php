<?php
/**
 * 檔案名稱: includes/class-streaming-source-base.php
 * 串流平台「直接抓取」共用基底 — 走 sitemap，不爬列表分頁
 *
 * 為什麼有這一層
 * --------------
 * 台灣八個平台的勾選與網址全部來自 YourAnimes 那一頁的解析結果：來源、觸發、
 * 成敗、新鮮度、正確性五層都是單點。要讓每家平台各自獨立，得直接去平台拿。
 *
 * 2026-09-15 實測八家後的結論（數字都在下面，別再重估）：
 *
 *   - 走 sitemap，不要爬列表頁。巴哈 sitemap.xml 一次請求 21.6MB 就有全站
 *     34,824 集／1,857 部作品，含台灣譯名與上架日；MyVideo、Ofiii、LiTV 也都有
 *     帶標題的 video sitemap。列表分頁那條路要幾百次請求、每家一套 HTML 解析器。
 *   - 每家平台真正需要客製的只有一件事：把「集數層級的標題」歸納成「作品名」
 *     （巴哈 `作品 [12]`、MyVideo `作品 第12集`、LiTV `作品 第1季 第12集 副標`）。
 *     其餘全部一樣，放這裡。
 *   - 比對只做完全相符＋候選唯一。召回率天花板約 74%（巴哈 73.6%、MyVideo
 *     73.5%、Ofiii 74.6%），那是中文譯名精確比對的極限；模糊比對已於
 *     2026-09-02 因錯誤率 20~40% 被移除，這裡不重新引入。
 *   - 淨增益很小（四家合計 82 個標記）。做這件事的理由不是那 82 個，是
 *     YA 掛掉不會八個平台一起斷、平台清單能反向偵測下架、以及站上舊番補齊
 *     之後 YA 的盲區（劇場版／OVA）會由這裡接手。
 *
 * 寫入紀律
 * --------
 *   - 只補空白：該平台網址欄位已有值就不動，無論那個值是 YA 寫的還是人工貼的。
 *   - 每筆寫入同時留來源標記 `_anime_tw_streaming_src_{key}`，之後要做下架偵測
 *     或來源切換時才分得出「誰寫的」。今天不記，日後補不回來。
 *   - 排程預設「只建索引、不寫入」（option anime_sync_streaming_source_write
 *     為 1 才寫）。先在正式站用 --dry-run 看過命中樣本，確認沒配錯再開。
 *
 * 不動的東西
 * ----------
 *   class-youranimes-*.php 四支一行都不碰；熔斷 transient 也不共用（兩個不同
 *   的站，一邊被擋不該把另一邊一起停掉）。
 *
 * 新增一家平台 = 新增一個子類別（約 50 行）＋ 在下面 SOURCES 加一行。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Anime_Sync_Streaming_Source_Base {

	// =====================================================================
	// 已接上的平台。新增平台在這裡加一行。
	// =====================================================================

	/**
	 * @var array<string,string> 平台 key → 子類別名
	 *
	 * 巴哈不從主機抓（正式站在吉隆坡，巴哈對它回 403 Cloudflare 人機驗證），
	 * 而是讀隨 repo 部署的索引包 data/source_bahamut_bundle.json，
	 * 由 tools/build-bahamut-bundle.php 在台灣 IP 的電腦產出。見該子類別檔頭。
	 */
	private const SOURCES = [
		'myvideo' => 'Anime_Sync_Streaming_Source_Myvideo',
		'ofiii'   => 'Anime_Sync_Streaming_Source_Ofiii',
		'litv'    => 'Anime_Sync_Streaming_Source_Litv',
		'friday'  => 'Anime_Sync_Streaming_Source_Friday',
		'bahamut' => 'Anime_Sync_Streaming_Source_Bahamut',
	];

	/** @return string[] */
	public static function available_keys(): array {
		return array_keys( self::SOURCES );
	}

	public static function make( string $key ): ?self {
		$cls = self::SOURCES[ $key ] ?? '';
		return ( $cls !== '' && class_exists( $cls ) ) ? new $cls() : null;
	}

	// =====================================================================
	// 子類別必須提供的四件事
	// =====================================================================

	/** 平台 key，必須與 Anime_Sync_Streaming_Registry 的 key 一致。 */
	abstract public function key(): string;

	/** sitemap 入口。可以是 urlset，也可以是 sitemapindex（會自動展開）。 */
	abstract protected function sitemap_url(): string;

	/**
	 * 把 sitemap 裡一個 <url>…</url> 區塊解析成一筆條目。
	 *
	 * @return array{title:string,url:string,date?:string}|null 不是作品條目回 null
	 */
	abstract protected function parse_entry( string $block ): ?array;

	/**
	 * 集數層級標題 → 作品名。每家格式不同，這是唯一要客製的邏輯。
	 * 回空字串代表這筆不是作品（預告、片段），整筆丟掉。
	 */
	abstract protected function work_name( string $title ): string;

	/**
	 * 同一部作品有多筆條目（每集一筆）時，挑哪一筆的網址存進站上。
	 * 預設取第一筆；巴哈會覆寫成「第一集優先，否則 sn 最小」。
	 *
	 * @param array<int,array{title:string,url:string,date?:string}> $entries
	 */
	protected function pick_entry( array $entries ): array {
		return $entries[0];
	}

	/**
	 * 這個來源的條目有沒有「開播日」可當第二道比對條件。
	 * 巴哈 sitemap 的 publication_date 是「該集上架時間」不是開播日，所以是 false。
	 * 子類別要老實回答，不要為了讓命中率好看而謊報。
	 */
	protected function provides_start_date(): bool {
		return false;
	}

	// =====================================================================
	// 常數
	// =====================================================================

	const HTTP_TIMEOUT       = 60;   // 巴哈 sitemap 21.6MB，15 秒不夠
	const TIME_BUDGET        = 200;  // 與 class-youranimes-title-index.php 一致
	const LOCK_TTL           = 280;
	const FAIL_THRESHOLD     = 3;
	const FAIL_TTL           = 30 * MINUTE_IN_SECONDS;
	const CIRCUIT_OPEN_TTL   = HOUR_IN_SECONDS;
	const CHILD_INTERVAL_US  = 1000000;   // 展開子 sitemap 時每檔隔 1 秒

	/** 排程預設不寫入，見檔頭「寫入紀律」。 */
	const WRITE_OPTION = 'anime_sync_streaming_source_write';

	/** 記憶體中的索引，避免同一次請求重複讀檔。 */
	private ?array $index = null;

	public function __construct() {
		add_action( $this->hook(), [ $this, 'run_scheduled' ] );
	}

	// =====================================================================
	// 排程：每週一次，建構子自我修復註冊
	// =====================================================================

	/*
	 * ★ 這個 repo 靠 git push 部署，register_activation_hook 不會觸發。
	 *   主外掛 plugins_loaded 那段會 new 這個類別並呼叫 schedule()，
	 *   那才是真正生效的註冊點；activation 那份只是保持一致。
	 */

	public function hook(): string {
		return 'anime_sync_streaming_source_' . $this->key();
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( $this->hook() ) ) {
			wp_schedule_event( time() + 900, 'weekly', $this->hook() );
		}
	}

	public function unschedule(): void {
		$ts = wp_next_scheduled( $this->hook() );
		if ( $ts ) {
			wp_unschedule_event( $ts, $this->hook() );
		}
		wp_clear_scheduled_hook( $this->hook() );
	}

	private function lock_key(): string {
		return 'anime_sync_lock_src_' . $this->key();
	}

	/** 排程進入點：建索引 → 比對 → 依 option 決定寫不寫。 */
	public function run_scheduled(): void {

		if ( get_transient( $this->lock_key() ) ) {
			$this->log_warning( '上一輪還在執行，本次跳過' );
			return;
		}

		set_transient( $this->lock_key(), 1, self::LOCK_TTL );

		try {
			$write = (string) get_option( self::WRITE_OPTION, '0' ) === '1';

			/*
			 * ★ 排程一定要 rebuild。run() 預設沿用既有索引（讓 CLI dry-run 不必
			 *   每次重抓 90MB），但排程的意義就是「平台這週新上架的作品要進來」，
			 *   不重建等於永遠停在第一次建索引的那一天。
			 */
			$r = $this->run( [ 'write' => $write, 'rebuild' => true ] );

			$this->log_info( sprintf(
				'[排程] 索引 %d 部；比對 %d、命中 %d、多重候選 %d；%s %d',
				$r['works'], $r['scanned'], $r['hit'], $r['multi'],
				$write ? '已寫入' : '（未開啟寫入，dry-run）', $r['written']
			) );
		} finally {
			delete_transient( $this->lock_key() );
		}
	}

	// =====================================================================
	// 主流程
	// =====================================================================

	/**
	 * @param array{write?:bool,limit?:int,rebuild?:bool} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {

		$write   = ! empty( $args['write'] );
		$limit   = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
		$rebuild = ! empty( $args['rebuild'] );

		if ( class_exists( 'Anime_Sync_Performance' ) ) {
			Anime_Sync_Performance::set_time_limit( self::TIME_BUDGET + 60 );
			/*
			 * ★ 一定要拉記憶體。wp-config 沒設 WP_MEMORY_LIMIT，cron／前台請求
			 *   跑在 WordPress 預設的 40MB 上；巴哈 sitemap 一檔 21.6MB，下載進來
			 *   再 preg_match_all 切 34,824 塊，本機實測峰值 72MB，40MB 必 fatal。
			 *   256M 與 class-cron-manager.php 其他大任務的做法一致。
			 *
			 * ★ 但只在現有上限比 256M 低時才動。正式站 WP-CLI 的 memory_limit 是 -1
			 *   （無上限），無條件設 256M 等於把 CLI 的上限調低——2026-09-15 LiTV
			 *   dry-run 就是這樣在 CLI 撞到 256M fatal 的。
			 */
			$cur = (string) ini_get( 'memory_limit' );
			if ( $cur !== '-1' && wp_convert_hr_to_bytes( $cur ) < 256 * MB_IN_BYTES ) {
				Anime_Sync_Performance::increase_memory_limit( '256M' );
			}
		}

		$stats = [
			'source'   => $this->key(),
			'works'    => 0,
			'entries'  => 0,
			'built'    => false,
			'error'    => '',
			'circuit'  => false,
			'candidates' => 0,
			'scanned'  => 0,
			'hit'      => 0,
			'multi'    => 0,
			'miss'     => 0,
			'written'  => 0,
			'hit_samples'   => [],
			'multi_samples' => [],
			'miss_samples'  => [],
		];

		// ── 1. 索引：沒有、或要求重建，才去抓 sitemap ──
		$index = $this->load_index();

		if ( empty( $index ) || $rebuild ) {
			$built = $this->build_index();

			if ( is_wp_error( $built ) ) {
				$stats['error']   = $built->get_error_code() . '：' . $built->get_error_message();
				$stats['circuit'] = $built->get_error_code() === 'circuit_open';
				return $stats;
			}

			$stats['built']   = true;
			$stats['entries'] = (int) $built['entries'];
			$index            = $this->load_index();
		}

		$stats['works'] = count( $index );

		// ── 2. 比對站上尚無本平台網址的作品 ──
		$ids = $this->candidates_without_url( $limit );
		$stats['candidates'] = $this->count_candidates_without_url();

		foreach ( $ids as $id ) {

			$stats['scanned']++;

			/*
			 * 比對用的標題以中文為主：平台列表只有中文譯名。
			 * post_title 排第一——匯入寫進去的 anime_title_chinese 是大陸譯名
			 * 逐字簡轉繁，而站上多數標題已被人工改成台灣官方譯名，改的結果
			 * 在 post_title（見 class-import-manager.php import_single()）。
			 */
			$titles = array_values( array_filter( array_unique( [
				(string) get_the_title( $id ),
				(string) get_post_meta( $id, 'anime_title_chinese', true ),
			] ) ) );

			$r     = $this->lookup( $titles, (string) get_post_meta( $id, 'anime_start_date', true ) );
			$label = sprintf( '#%d %s', $id, mb_substr( (string) get_the_title( $id ), 0, 30 ) );

			if ( $r['status'] === 'hit' ) {
				$stats['hit']++;
				if ( count( $stats['hit_samples'] ) < 20 ) {
					$stats['hit_samples'][] = $label . ' → ' . $r['url'];
				}
				if ( $write && $this->write( $id, $r['url'] ) ) {
					$stats['written']++;
				}
				continue;
			}

			if ( $r['status'] === 'multi' ) {
				$stats['multi']++;
				if ( count( $stats['multi_samples'] ) < 10 ) {
					$stats['multi_samples'][] = $label . '（' . $r['candidates'] . ' 個候選）';
				}
				continue;
			}

			$stats['miss']++;
			if ( count( $stats['miss_samples'] ) < 10 ) {
				$stats['miss_samples'][] = $label;
			}
		}

		// 有寫入就讓 /streaming/ 的計數重算（沿用該處的快取鍵，不另外發明）
		if ( $stats['written'] > 0 && class_exists( 'Anime_Sync_Streaming_Routing' ) ) {
			delete_transient( Anime_Sync_Streaming_Routing::COUNT_CACHE_KEY );
		}

		return $stats;
	}

	// =====================================================================
	// 建索引
	// =====================================================================

	/**
	 * 收集條目 → 歸納成作品 → 存 JSON。
	 *
	 * 收集方式由 collect_entries() 決定：預設走 sitemap（巴哈、MyVideo、Ofiii、
	 * LiTV），沒有 sitemap 的平台（friDay 只有分頁清單頁）覆寫它改爬 HTML。
	 * 歸納、索引、比對、寫入全部共用，不因收集方式不同而分兩套。
	 *
	 * @return array{entries:int,works:int}|WP_Error
	 */
	public function build_index() {

		$started   = microtime( true );
		$collected = $this->collect_entries( $started );

		if ( is_wp_error( $collected ) ) {
			return $collected;
		}

		[ $grouped, $entries ] = $collected;

		if ( empty( $grouped ) ) {
			return new WP_Error( 'no_entries', '解析不到任何作品條目，平台可能改版' );
		}

		return $this->finish_index( $grouped, $entries );
	}

	/**
	 * 預設收集方式：sitemap（含展開 sitemapindex）。
	 *
	 * @param float $started microtime，供時間預算判斷
	 * @return array{0:array<string,array<int,array>>,1:int}|WP_Error  [作品名 → entries[], 條目數]
	 */
	protected function collect_entries( float $started ) {

		$root = $this->fetch( $this->sitemap_url() );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		// sitemapindex → 逐檔展開；urlset → 就這一檔
		$files = [];
		if ( stripos( $root, '<sitemapindex' ) !== false ) {
			if ( preg_match_all( '#<sitemap>.*?<loc>\s*([^<\s]+)\s*</loc>.*?</sitemap>#si', $root, $m ) ) {
				$files = $m[1];
			}
			if ( empty( $files ) ) {
				return new WP_Error( 'empty_index', 'sitemapindex 裡沒有任何子檔' );
			}
		}

		$grouped = [];   // 作品名 → entries[]
		$entries = 0;

		$bodies = empty( $files ) ? [ $root ] : [];
		unset( $root );

		$pending = $files;

		while ( true ) {

			if ( empty( $bodies ) ) {
				if ( empty( $pending ) ) {
					break;
				}
				if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
					$this->log_warning( sprintf( '時間預算用盡，子 sitemap 還剩 %d 檔未處理', count( $pending ) ) );
					break;
				}
				usleep( self::CHILD_INTERVAL_US );
				$next = array_shift( $pending );
				$body = $this->fetch( (string) $next );
				if ( is_wp_error( $body ) ) {
					if ( $body->get_error_code() === 'circuit_open' ) {
						return $body;
					}
					$this->log_warning( '子 sitemap 抓取失敗：' . $next . '（' . $body->get_error_code() . '）' );
					continue;
				}
				$bodies[] = $body;
				unset( $body );
			}

			$xml = array_shift( $bodies );

			if ( ! preg_match_all( '#<url>(.*?)</url>#si', $xml, $blocks ) ) {
				continue;
			}
			unset( $xml );

			foreach ( $blocks[1] as $block ) {

				$e = $this->parse_entry( $block );
				if ( $e === null || $e['url'] === '' || $e['title'] === '' ) {
					continue;
				}

				$w = $this->work_name( $e['title'] );
				if ( $w === '' ) {
					continue;
				}

				$entries++;
				$this->add_entry( $grouped, $w, $e );
			}

			unset( $blocks );
		}

		return [ $grouped, $entries ];
	}

	/**
	 * 把一筆條目併進作品，邊收集邊挑，每部作品只留目前最好的一筆。
	 *
	 * ★ 不能把每一集都存起來等最後再挑。LiTV 有 132,987 個單集條目、24,035 部
	 *   作品，全部留在記憶體本機量到 130MB，加上正式站 WordPress 本身的基礎
	 *   用量就超過 256MB——2026-09-15 部署後 dry-run 當場 fatal。
	 *   pick_entry() 的規則是優先序（作品頁 > 第一集 > id 最小），兩兩比較的
	 *   結果與全域挑選相同，所以每來一筆就跟現有那筆比一次即可。
	 *
	 * @param array<string,array> $grouped 作品名 → 目前挑出的那一筆
	 */
	protected function add_entry( array &$grouped, string $work, array $entry ): void {
		$grouped[ $work ] = isset( $grouped[ $work ] )
			? $this->pick_entry( [ $grouped[ $work ], $entry ] )
			: $entry;
	}

	/**
	 * 把「作品名 → 挑出的那一筆」歸納成索引並落地。收集方式無關，所有來源共用。
	 *
	 * @param array<string,array> $grouped
	 * @return array{entries:int,works:int}
	 */
	protected function finish_index( array $grouped, int $entries ): array {

		// 歸納成索引：正規化鍵 → [ {u: 網址, n: 作品名, d?: 開播日} ]
		$index = [];

		foreach ( $grouped as $work => $picked ) {

			$row = [ 'u' => (string) $picked['url'], 'n' => (string) $work ];

			if ( $this->provides_start_date() ) {
				$d = preg_replace( '/[^0-9]/', '', (string) ( $picked['date'] ?? '' ) );
				if ( strlen( (string) $d ) === 8 ) {
					$row['d'] = $d;
				}
			}

			foreach ( $this->index_keys( (string) $work ) as $k ) {
				$index[ $k ][] = $row;
			}
		}

		$this->save_index( $index );

		update_option( 'anime_sync_src_state_' . $this->key(), [
			'built'   => time(),
			'entries' => $entries,
			'works'   => count( $grouped ),
			'keys'    => count( $index ),
		], false );

		return [ 'entries' => $entries, 'works' => count( $grouped ) ];
	}

	// =====================================================================
	// 比對
	// =====================================================================

	/**
	 * 回傳狀態而不是單純字串：「配不到」與「配到多個所以放棄」是兩件事，
	 * 前者要改召回、後者要補第二道條件，報告必須分得開。
	 *
	 * @param string[] $titles
	 * @return array{status:string,url:string,candidates:int}  status: hit|multi|miss
	 */
	public function lookup( array $titles, string $start_date = '' ): array {

		$index = $this->load_index();

		if ( empty( $index ) ) {
			return [ 'status' => 'miss', 'url' => '', 'candidates' => 0 ];
		}

		$date = preg_replace( '/[^0-9]/', '', $start_date );
		$date = strlen( (string) $date ) === 8 ? (string) $date : '';

		$hits = [];

		foreach ( $titles as $t ) {

			$k = $this->normalize( (string) $t );
			if ( $k === '' || ! isset( $index[ $k ] ) ) {
				continue;
			}

			foreach ( (array) $index[ $k ] as $row ) {
				// 索引條目有日期才比日期；沒有日期時唯一性是唯一的防線，不因此放寬
				if ( isset( $row['d'] ) && $date !== '' && (string) $row['d'] !== $date ) {
					continue;
				}
				$hits[ (string) $row['u'] ] = true;
			}
		}

		$n = count( $hits );

		if ( $n === 1 ) {
			return [ 'status' => 'hit', 'url' => (string) array_key_first( $hits ), 'candidates' => 1 ];
		}

		// 兩個以上代表分不出是哪一部，寧可不配也不要把別部作品的串流寫進來
		return [ 'status' => $n > 1 ? 'multi' : 'miss', 'url' => '', 'candidates' => $n ];
	}

	// =====================================================================
	// 寫入：只補空白
	// =====================================================================

	protected function url_meta_key(): string {
		return 'anime_tw_streaming_url_' . $this->key();
	}

	protected function src_meta_key(): string {
		return '_anime_tw_streaming_src_' . $this->key();
	}

	/**
	 * 欄位對齊 class-youranimes-fetcher.php::write_to_acf()：
	 * `anime_tw_streaming` 是 key 陣列，`anime_tw_streaming_url_{key}` 是網址。
	 * 差別只有兩點：這裡絕不覆蓋既有網址；多留一筆來源標記。
	 */
	protected function write( int $post_id, string $url ): bool {

		$current = (string) get_post_meta( $post_id, $this->url_meta_key(), true );
		if ( $current !== '' ) {
			return false;   // 已有值（YA 或人工），不動
		}

		update_post_meta( $post_id, $this->url_meta_key(), $url );
		update_post_meta( $post_id, $this->src_meta_key(), 'source:' . $this->key() . '@' . gmdate( 'Y-m-d' ) );

		$checked = get_post_meta( $post_id, 'anime_tw_streaming', true );
		if ( ! is_array( $checked ) ) {
			$checked = [];
		}
		if ( ! in_array( $this->key(), $checked, true ) ) {
			$checked[] = $this->key();
			update_post_meta( $post_id, 'anime_tw_streaming', $checked );
		}

		return true;
	}

	// =====================================================================
	// 候選作品
	// =====================================================================

	/**
	 * 尚未有本平台網址的已發布作品。
	 *
	 * ★ 帶 limit 時一律隨機取樣。
	 *   post ID 順序與作品新舊高度相關，取前 N 筆全是當季新番，命中率會被
	 *   高估一倍（2026-09-06 YourAnimes 回補乾跑就是這樣錯的：前 120 筆 76.7%，
	 *   全量只有 40.4%）。ORDER BY p.ID 在抽樣情境是警訊，不是預設。
	 *
	 * @return int[]
	 */
	protected function candidates_without_url( int $limit = 0 ): array {
		global $wpdb;

		$order = $limit > 0 ? 'ORDER BY RAND()' : 'ORDER BY p.ID DESC';
		$lim   = $limit > 0 ? $wpdb->prepare( 'LIMIT %d', $limit ) : '';

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} u
			     ON u.post_id = p.ID AND u.meta_key = %s AND u.meta_value <> ''
			  WHERE p.post_type = 'anime' AND p.post_status = 'publish'
			    AND u.post_id IS NULL
			  {$order} {$lim}",
			$this->url_meta_key()
		) ) );
	}

	protected function count_candidates_without_url(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} u
			     ON u.post_id = p.ID AND u.meta_key = %s AND u.meta_value <> ''
			  WHERE p.post_type = 'anime' AND p.post_status = 'publish'
			    AND u.post_id IS NULL",
			$this->url_meta_key()
		) );
	}

	// =====================================================================
	// 正規化
	// =====================================================================

	/**
	 * 主體用 Anime_Sync_YourAnimes_Season_Index::normalize_public()——那套規則
	 * （全半形、日文字形、標點、空白、季別）已在正式站驗證，另寫一份等於維護
	 * 兩套字形表。
	 *
	 * 後面多補一道季別：既有 normalize() 的季別 regex 跑在「去空白之前」，
	 * 巴哈寫的「第 2 季」帶空格躲過了；站上寫的「第二季」是中文數字也躲過。
	 * 這裡在去空白之後再拉平一次。實測只多救回 1 部，但它是正確的，成本五行。
	 * 索引與查詢兩邊都走這個方法，一定一致。
	 */
	protected function normalize( string $s ): string {

		if ( $s === '' ) {
			return '';
		}

		if ( class_exists( 'Anime_Sync_YourAnimes_Season_Index' ) ) {
			$s = Anime_Sync_YourAnimes_Season_Index::normalize_public( $s );
		} else {
			$s = mb_strtolower( (string) preg_replace( '/[\s\x{3000}]+/u', '', $s ), 'UTF-8' );
		}

		foreach ( [ '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10 ] as $cn => $n ) {
			$s = (string) preg_replace( '/第' . $cn . '[期季]/u', '@S' . $n, $s );
		}
		for ( $n = 2; $n <= 12; $n++ ) {
			$s = (string) preg_replace( '/(第' . $n . '[期季]|' . $n . '(?:nd|rd|th)?season|season' . $n . ')/iu', '@S' . $n, $s );
		}

		return $s;
	}

	/**
	 * 一個作品名要建立哪幾個索引鍵。
	 *
	 * 「去掉劇場版前後綴當別名」抄自 class-youranimes-title-index.php，但那邊
	 * 安全的前提是 lookup() 還有**開播日**當第二道條件：TV 版與劇場版共用同一個
	 * 鍵，靠日期分開。直接抓取的來源全部沒有日期，去掉前綴後只要平台剛好沒有
	 * TV 版那部，劇場版就成了「唯一候選」被錯配——2026-09-15 巴哈 dry-run 抽查
	 * 就抓到兩筆：《少女與戰車》配到劇場版、《擅長捉弄人的高木同學》配到劇場版
	 * （後者正是那份 memo 舉的例子，我抄了機制沒抄前提）。
	 *
	 * 所以這個別名只在 provides_start_date() 為 true 時才建。目前沒有任何直接
	 * 來源提供開播日，等於全部關閉；精度靠「完全相符＋候選唯一」。
	 *
	 * @return string[]
	 */
	protected function index_keys( string $title ): array {

		$keys = [];
		$base = $this->normalize( $title );

		if ( $base !== '' ) {
			$keys[] = $base;
		}

		if ( ! $this->provides_start_date() ) {
			return $keys;
		}

		$stripped = preg_replace( '/^(?:劇場版|劇场版|映画|電影|gekijouban|gekijoban|themovie|movie)/u', '', (string) $base );
		$stripped = preg_replace( '/(?:劇場版|映画|themovie|movie)$/u', '', (string) $stripped );
		$stripped = trim( (string) $stripped );

		if ( $stripped !== '' && $stripped !== $base ) {
			$keys[] = $stripped;
		}

		return array_values( array_unique( $keys ) );
	}

	// =====================================================================
	// 抓取與熔斷（每個來源各自一組）
	// =====================================================================

	private function fail_key(): string {
		return 'asp_src_fail_' . $this->key();
	}

	private function circuit_key(): string {
		return 'asp_src_circuit_' . $this->key();
	}

	public function is_circuit_open(): bool {
		return (bool) get_transient( $this->circuit_key() );
	}

	/**
	 * 標頭沿用 class-youranimes-fetcher.php::fetch_page() 那組（正式站實證可用）。
	 * 不快取：sitemap 一週抓一次，快取 21MB 進 transient 不划算。
	 *
	 * @return string|WP_Error
	 */
	protected function fetch( string $url ) {

		if ( $this->is_circuit_open() ) {
			return new WP_Error( 'circuit_open', '熔斷中，暫停對 ' . $this->label() . ' 的請求' );
		}

		$res = wp_remote_get( $url, [
			'timeout'     => self::HTTP_TIMEOUT,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'headers'     => [
				'Accept'          => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'zh-TW,zh;q=0.9,en;q=0.8',
			],
		] );

		if ( is_wp_error( $res ) ) {
			$this->record_failure();
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code !== 200 ) {
			$this->record_failure();
			return new WP_Error( 'http_error', 'HTTP ' . $code );
		}

		$body = (string) wp_remote_retrieve_body( $res );
		if ( $body === '' ) {
			$this->record_failure();
			return new WP_Error( 'empty_body', '回應內容為空' );
		}

		delete_transient( $this->fail_key() );
		return $body;
	}

	private function record_failure(): void {
		$n = (int) get_transient( $this->fail_key() ) + 1;
		set_transient( $this->fail_key(), $n, self::FAIL_TTL );

		if ( $n >= self::FAIL_THRESHOLD ) {
			set_transient( $this->circuit_key(), 1, self::CIRCUIT_OPEN_TTL );
			delete_transient( $this->fail_key() );
			$this->log_warning( sprintf( '連續 %d 次失敗，暫停 %d 分鐘', $n, (int) ( self::CIRCUIT_OPEN_TTL / MINUTE_IN_SECONDS ) ) );
		}
	}

	// =====================================================================
	// 索引檔
	// =====================================================================

	protected function index_path(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'anime-sync-pro/source_' . $this->key() . '_index.json';
	}

	public function load_index(): array {

		if ( $this->index !== null ) {
			return $this->index;
		}

		$path = $this->index_path();
		if ( ! file_exists( $path ) ) {
			return $this->index = [];
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		return $this->index = ( is_array( $data ) ? $data : [] );
	}

	protected function save_index( array $index ): void {

		$path = $this->index_path();
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		file_put_contents( $path, (string) wp_json_encode( $index, JSON_UNESCAPED_UNICODE ) );
		$this->index = $index;
	}

	/** @return array<string,mixed> */
	public function status(): array {
		$s = get_option( 'anime_sync_src_state_' . $this->key(), [] );
		return is_array( $s ) ? $s : [];
	}

	// =====================================================================
	// 雜項
	// =====================================================================

	/** 顯示名稱取自登錄表，不在這裡再維護一份平台清單。 */
	public function label(): string {
		if ( class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
			$p = Anime_Sync_Streaming_Registry::get( $this->key() );
			if ( is_array( $p ) && ! empty( $p['label'] ) ) {
				return (string) $p['label'];
			}
		}
		return $this->key();
	}

	protected function log_info( string $m ): void {
		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info( '串流來源[' . $this->key() . ']：' . $m );
		}
	}

	protected function log_warning( string $m ): void {
		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::warning( '串流來源[' . $this->key() . ']：' . $m );
		}
	}
}

// =========================================================================
// WP-CLI
// =========================================================================

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/*
	 * wp anime streaming-source --platform=bahamut --dry-run [--limit=100] [--rebuild]
	 * wp anime streaming-source --platform=bahamut --write
	 * wp anime streaming-source --platform=bahamut --status
	 */
	WP_CLI::add_command( 'anime streaming-source', function ( $args, $assoc_args ) {

		$key = (string) ( $assoc_args['platform'] ?? '' );
		$src = Anime_Sync_Streaming_Source_Base::make( $key );

		if ( $src === null ) {
			WP_CLI::error( '--platform 必填，可用：' . implode( '、', Anime_Sync_Streaming_Source_Base::available_keys() ) );
		}

		if ( isset( $assoc_args['status'] ) ) {
			$s = $src->status();
			WP_CLI::log( empty( $s ) ? '尚未建立索引' : sprintf(
				'索引建立於 %s：%d 條目 → %d 部作品 → %d 個鍵；排程寫入=%s',
				// 用站台時區顯示，主機是 UTC+8，gmdate 會少 8 小時讓人以為索引是半夜建的
				wp_date( 'Y-m-d H:i', (int) $s['built'] ), $s['entries'], $s['works'], $s['keys'],
				(string) get_option( Anime_Sync_Streaming_Source_Base::WRITE_OPTION, '0' ) === '1' ? '開' : '關'
			) );
			return;
		}

		$write = isset( $assoc_args['write'] );
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		if ( $write && $limit > 0 ) {
			WP_CLI::error( '--write 不接受 --limit：寫入要全量跑，抽樣寫入會留下半套資料' );
		}

		WP_CLI::log( sprintf( '=== %s（%s）===', $src->label(), $write ? '寫入模式' : 'DRY RUN，不寫入' ) );

		$r = $src->run( [
			'write'   => $write,
			'limit'   => $limit,
			'rebuild' => isset( $assoc_args['rebuild'] ),
		] );

		if ( $r['error'] !== '' ) {
			WP_CLI::error( '建索引失敗：' . $r['error'] );
		}

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( sprintf( '索引：%s，%d 部作品', $r['built'] ? '本次重建（' . $r['entries'] . ' 條目）' : '沿用既有', $r['works'] ) );
		WP_CLI::log( sprintf( '站上尚無此平台網址：%d 部；本次比對 %d 部%s', $r['candidates'], $r['scanned'], $limit > 0 ? '（隨機抽樣）' : '' ) );
		WP_CLI::log( sprintf( '  唯一命中 %d｜多重候選（放棄）%d｜未命中 %d', $r['hit'], $r['multi'], $r['miss'] ) );
		if ( $write ) {
			WP_CLI::log( sprintf( '  已寫入 %d 部', $r['written'] ) );
		}

		foreach ( [ 'hit_samples' => '命中樣本', 'multi_samples' => '多重候選樣本', 'miss_samples' => '未命中樣本' ] as $k => $t ) {
			if ( ! empty( $r[ $k ] ) ) {
				WP_CLI::log( '' );
				WP_CLI::log( '-- ' . $t . ' --' );
				foreach ( $r[ $k ] as $line ) {
					WP_CLI::log( '  ' . $line );
				}
			}
		}

		WP_CLI::success( $write ? '完成' : 'dry-run 完成，未寫入任何資料' );
	} );
}
