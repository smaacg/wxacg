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
		'linetv'  => 'Anime_Sync_Streaming_Source_Linetv',
		'hami'    => 'Anime_Sync_Streaming_Source_Hami',
		'catchplay' => 'Anime_Sync_Streaming_Source_Catchplay',
		'garageplay' => 'Anime_Sync_Streaming_Source_Garageplay',   // AniPASS 專區，本機索引包（同巴哈）
		// YouTube 頻道型（class-streaming-source-yt-channels.php）
		'muse'         => 'Anime_Sync_Streaming_Source_Muse',
		'ani_one'      => 'Anime_Sync_Streaming_Source_Ani_One',
		'tropicsanime' => 'Anime_Sync_Streaming_Source_Tropicsanime',
		'mighty'       => 'Anime_Sync_Streaming_Source_Mighty',
		'ani_mi'       => 'Anime_Sync_Streaming_Source_Ani_Mi',
		'its_anime'    => 'Anime_Sync_Streaming_Source_Its_Anime',
		// 只靠 bangumi-data ID 對應的來源（沒有自己的 sitemap／API；class-streaming-source-bangumi-data.php）
		'bilibili'     => 'Anime_Sync_Streaming_Source_Bilibili',
		// 只覆核、不發現（class-streaming-source-verify-only.php）
		'amazon'       => 'Anime_Sync_Streaming_Source_Amazon',
		'appletv'      => 'Anime_Sync_Streaming_Source_Appletv',
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

	/**
	 * 索引是「每次整份重建」還是「增量合併」。
	 * sitemap／清單頁一輪就能拿到全目錄的來源用重建；LINE TV 這種要逐頁抓
	 * 7,000 多頁、一輪只做得完一批的來源用增量：本輪抓到的併進既有索引。
	 */
	protected function incremental(): bool {
		return false;
	}

	/** 排程頻率。重建型一週一次夠；增量型要每小時推進一批。 */
	protected function recurrence(): string {
		return 'weekly';
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

	/**
	 * 後台「立即執行」用的 admin-ajax 端點（nopriv，靠一次性 token）。
	 *
	 * 為什麼不用 wp-cron 排一次性事件：這台主機 DISABLE_WP_CRON、由外部每 ~10 分鐘踢一次，
	 * 單事件排進去還要跟匯入產生的幾十個背景事件排隊；spawn_cron() 的 0.01 秒非阻塞回圈請求
	 * 在 HTTPS 握手完成前就被切斷，根本沒送到。2026-09-15 實測按下去半小時都沒跑。
	 * 改成自己對 admin-ajax 發一個**阻塞**回圈請求：對方驗過 token 後先把回應結束
	 * （fastcgi_finish_request；沒有就靠 ignore_user_abort），再跑跟排程一模一樣的 run_locked()。
	 */
	const ASYNC_ACTION = 'anime_sync_streaming_source_async';

	public function __construct() {
		add_action( $this->hook(), [ $this, 'run_scheduled' ] );
		if ( ! has_action( 'wp_ajax_nopriv_' . self::ASYNC_ACTION ) ) {
			add_action( 'wp_ajax_nopriv_' . self::ASYNC_ACTION, [ self::class, 'handle_async' ] );
			add_action( 'wp_ajax_' . self::ASYNC_ACTION, [ self::class, 'handle_async' ] );
		}
	}

	/**
	 * 後台按鈕：發回圈請求讓另一個 PHP 程序去跑。該來源正在執行（鎖住）時回 false。
	 * 回圈請求最多等 5 秒——正常情況對方幾百毫秒就回「accepted」；等不到也沒關係，
	 * 對方 ignore_user_abort 會繼續跑，這裡不吞錯只是不阻塞後台。
	 */
	public static function dispatch_async( string $key, bool $write ): bool {

		$src = self::make( $key );
		if ( ! $src || $src->is_running() ) {
			return false;
		}

		$token = wp_generate_password( 32, false );
		set_transient( 'asp_src_async_' . $token, [ 'key' => $key, 'write' => $write ], 10 * MINUTE_IN_SECONDS );

		$res = wp_remote_post( admin_url( 'admin-ajax.php' ), [
			'timeout'   => 5,
			'blocking'  => true,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => [ 'action' => self::ASYNC_ACTION, 'token' => $token ],
		] );

		if ( is_wp_error( $res ) && class_exists( 'Anime_Sync_Error_Logger' ) ) {
			// 逾時是預期內（對方沒有 fastcgi_finish_request 時會等滿 5 秒），其他錯誤要留痕
			if ( strpos( $res->get_error_message(), 'timed out' ) === false ) {
				Anime_Sync_Error_Logger::warning( '串流來源[' . $key . ']：後台立即執行的回圈請求失敗：' . $res->get_error_message() );
			}
		}

		return true;
	}

	/** admin-ajax 端點：驗 token → 先結束回應 → 跑 run_locked('[後台]')。 */
	public static function handle_async(): void {

		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_POST['token'] ?? '' ) );
		$job   = $token !== '' ? get_transient( 'asp_src_async_' . $token ) : false;

		if ( ! is_array( $job ) || empty( $job['key'] ) ) {
			wp_die( 'bad token', '', [ 'response' => 403 ] );
		}
		delete_transient( 'asp_src_async_' . $token );   // 一次性，重放無效

		$src = self::make( (string) $job['key'] );
		if ( ! $src ) {
			wp_die( 'bad key', '', [ 'response' => 400 ] );
		}

		ignore_user_abort( true );
		set_time_limit( 0 );

		// 先把回應送出去並結束連線，工作留在這個程序繼續跑
		echo 'accepted';
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();
		}

		$src->run_locked( '[後台]', ! empty( $job['write'] ) );
		wp_die();
	}

	/** 這個來源現在是否正在執行（排程或後台按鈕都會上同一把鎖）。 */
	public function is_running(): bool {
		return (bool) get_transient( $this->lock_key() );
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

	/**
	 * 只有正式站排程；其餘環境一律不排，而且順手把既有的清掉。
	 *
	 * ★ 為什麼是「確認是正式站才排」而不是「偵測到本機才擋」
	 *
	 *   這個 repo 會被其他人 clone／pull 到自己的開發環境。而 wp-config.php
	 *   **不在版控裡**（.gitignore 第 13 行），所以別人的環境不會有
	 *   WP_ENVIRONMENT_TYPE='local' 這行——WordPress 預設回 'production'，
	 *   只認 'local' 的守門在他們那邊等於完全失效（fail-open：認不出來就照跑）。
	 *
	 *   改成比對 home_url() 的網域：認不出來就不排程（fail-safe）。
	 *   別人 clone 下去什麼都不必設定就是安全的，正式站則照常。
	 *   網域寫成類別常數，換網域只改一處——避免專案註解抱怨過的
	 *   「硬編碼主機判斷不可攜、換主機即失效」。
	 *
	 * ★ 為什麼串流特別需要這道門
	 *   每一輪都要抓 sitemap 建索引（MyVideo 5.8 萬條目、Ofiii 11 萬、LiTV 14 萬），
	 *   在開發機上跑只會把站拖慢；抓到的資料寫進對方自己的資料庫，對正式站毫無用處，
	 *   還會讓兩邊資料對不起來、日後比對時誤判。
	 *
	 * ★ 為什麼要順手 unschedule()
	 *   加這道門之前可能已經排過了，只擋新註冊的話那些排程會一直留在資料庫裡。
	 *   這樣寫等於自我清理：非正式站下次進後台就會把殘留的清掉。
	 */
	public function schedule(): void {

		if ( ! self::is_production_site() ) {
			$this->unschedule();
			return;
		}

		if ( ! wp_next_scheduled( $this->hook() ) ) {
			wp_schedule_event( time() + 900, $this->recurrence(), $this->hook() );
		}
	}

	/** 正式站網域。換網域時只改這裡。 */
	const PRODUCTION_HOST = 'weixiaoacg.com';

	/**
	 * 這裡是不是正式站？
	 *
	 * 兩個條件都要成立，任一不符就當作不是（寧可不排程，也不要在別人的機器上亂跑）：
	 *   1. home_url() 的 host 等於 PRODUCTION_HOST（含 www. 前綴一併接受）
	 *   2. 環境類型不是 'local'（本機即使把網址設成正式站網域也擋得住）
	 */
	public static function is_production_site(): bool {

		if ( function_exists( 'wp_get_environment_type' )
			&& wp_get_environment_type() === 'local' ) {
			return false;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( preg_replace( '/^www\./', '', $host ) );

		return $host === self::PRODUCTION_HOST;
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
		$this->run_locked( '[排程]', (string) get_option( self::WRITE_OPTION, '0' ) === '1' );
	}

	/**
	 * 排程與後台按鈕共用：上鎖 → 重建索引並比對 → 寫一筆結果日誌。
	 * 日誌標籤（[排程]／[後台]／[手動]）讓後台頁「上次執行結果」欄能把三種來路都列出來——
	 * 之前只認 [排程]，CLI 手動寫入 139 部後那格還顯示「命中 0」，跟旁邊「已寫入」對不上。
	 */
	public function run_locked( string $tag, bool $write ): void {

		if ( get_transient( $this->lock_key() ) ) {
			$this->log_warning( '上一輪還在執行，本次跳過' );
			return;
		}

		set_transient( $this->lock_key(), 1, self::LOCK_TTL );

		try {
			/*
			 * ★ 排程一定要 rebuild。run() 預設沿用既有索引（讓 CLI dry-run 不必
			 *   每次重抓 90MB），但排程的意義就是「平台這週新上架的作品要進來」，
			 *   不重建等於永遠停在第一次建索引的那一天。
			 *
			 * ★ 但「排程頻率」與「索引重建頻率」要分開（2026-09-16）。
			 *   加了網址覆核之後，排程必須跑得夠密（每輪只覆核一批，MyVideo 908 筆
			 *   若維持週排程要六週才輪完一圈，下架一個半月後才發現，等於沒做）；
			 *   可是索引重建是另一回事——MyVideo 5.8 萬條目、Ofiii 11 萬、LiTV 14 萬，
			 *   天天重抓既浪費對方頻寬也浪費我們的時間預算，而平台一天內的新上架量
			 *   本來就少。所以：排程可以每天跑，索引仍然每 REBUILD_INTERVAL_DAYS 天一次。
			 */
			$last_build = (int) ( $this->status()['built'] ?? 0 );
			$rebuild    = ( $last_build <= 0 ) || ( time() - $last_build ) >= self::REBUILD_INTERVAL_DAYS * DAY_IN_SECONDS;

			$r = $this->run( [ 'write' => $write, 'rebuild' => $rebuild ] );

			$this->log_result( $tag, $write, $r );
		} finally {
			delete_transient( $this->lock_key() );
		}
	}

	/** 結果日誌的統一格式；CLI 也走這裡（標籤 [手動]）。 */
	public function log_result( string $tag, bool $write, array $r ): void {
		if ( ( $r['error'] ?? '' ) !== '' ) {
			$this->log_warning( $tag . ' 建索引失敗：' . $r['error'] );
			return;
		}
		$end = '';
		if ( ! empty( $r['end'] ) && $r['end']['checked'] > 0 ) {
			$end = sprintf(
				'；覆核 %d（還在 %d、不存在 %d、移除 %d）',
				$r['end']['checked'],
				$r['end']['alive'] ?? 0,
				$r['end']['dead'] ?? 0,
				( $r['end']['dead_removed'] ?? 0 ) + ( $r['end']['removed'] ?? 0 )
			);
			if ( ( $r['end']['soon'] ?? 0 ) > 0 ) {
				$end .= sprintf( '、14 天內到期 %d', $r['end']['soon'] );
			}
		}
		$this->log_info( sprintf(
			'%s 索引 %d 部；比對 %d、命中 %d、多重候選 %d；%s %d%s',
			$tag, $r['works'], $r['scanned'], $r['hit'], $r['multi'],
			$write ? '已寫入' : '（dry-run，未寫入）', $r['written'], $end
		) );
	}

	// =====================================================================
	// 主流程
	// =====================================================================

	/**
	 * @param array{write?:bool,limit?:int,rebuild?:bool} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {

		$started = microtime( true );
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

		/*
		 * ── 0. 純覆核來源：沒有可列舉的目錄，只驗證站上既有網址還能不能看 ──
		 *
		 * Prime、Apple TV 屬於這種。它們不建索引也不找新作品（新連結來自匯入時的
		 * AniList externalLinks），所以要在索引階段之前分流——否則 build_index()
		 * 會因為收不到任何條目而回 no_entries 錯誤，整輪中斷。
		 */
		if ( $this->verify_only() ) {
			$stats['gone'] = [ 'checked' => 0, 'marked' => 0, 'cleared' => 0, 'removed' => 0, 'samples' => [] ];
			$stats['end']  = $this->recheck_urls( $write, $started );

			if ( $write && ( ( $stats['end']['dead_removed'] ?? 0 ) + ( $stats['end']['removed'] ?? 0 ) ) > 0 ) {
				$this->purge_streaming_pages();
			}

			return $stats;
		}

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

			$titles = $this->match_titles( $id );
			$r      = $this->lookup( $titles, (string) get_post_meta( $id, 'anime_start_date', true ) );
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

		/*
		 * ── 3. 覆核：作品頁還在不在（有到期日的順便解析）──
		 *
		 * 主機打得到作品頁的來源走這條。證據是平台第一手回應（404／400＝不存在），
		 * 所以**不限網址是誰寫的**——YA 給的、人工貼的、我們自己配的，全部都覆核。
		 *
		 * 2026-09-16 量到的缺口正是這個：全站 9,271 個平台網址只有 996 個帶來源標記，
		 * 其餘 89% 從來沒有人檢查過還在不在（check_gone 只看自己寫的）。
		 */
		$stats['end'] = null;
		if ( $this->provides_alive_check() || $this->provides_end_date() ) {
			$stats['end'] = $this->recheck_urls( $write, $started );
		}

		/*
		 * ── 4. 退路：主機打不到作品頁的來源（巴哈／車庫被 Cloudflare 擋、CatchPlay 被地區擋）
		 *      只能用索引比對。索引比對會被譯名差異誤判成下架，所以僅限我們自己寫過的那些。
		 */
		$stats['gone'] = [ 'checked' => 0, 'marked' => 0, 'cleared' => 0, 'removed' => 0, 'samples' => [] ];
		if ( ! $this->provides_alive_check() && $this->index_is_complete() ) {
			$stats['gone'] = $this->check_gone( $write );
		}

		// 到期日或覆核有動到資料就要清：/streaming/ 的「即將下架」清單與作品頁標籤都吃這份資料
		$touched = $stats['written'] > 0
			|| $stats['gone']['removed'] > 0
			|| ( $write && ( ( $stats['end']['dated'] ?? 0 ) + ( $stats['end']['removed'] ?? 0 ) + ( $stats['end']['dead_removed'] ?? 0 ) ) > 0 );

		if ( $touched ) {
			$this->purge_streaming_pages();
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

		/*
		 * 增量型來源：本輪只抓到一批，要把既有索引裡的作品併回來再重算索引，
		 * 否則每輪都會把上一輪的成果洗掉。以作品名為鍵、本輪的新條目優先。
		 */
		if ( $this->incremental() ) {
			foreach ( $this->load_index() as $rows ) {
				foreach ( (array) $rows as $row ) {
					$n = (string) ( $row['n'] ?? '' );
					if ( $n !== '' && ! isset( $grouped[ $n ] ) ) {
						$grouped[ $n ] = [ 'title' => $n, 'url' => (string) ( $row['u'] ?? '' ), 'date' => (string) ( $row['d'] ?? '' ) ];
					}
				}
			}
		}

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
	 * 這個來源要拿站上哪些標題去比對。
	 *
	 * 預設以中文為主：台灣平台的列表只有中文譯名。post_title 排第一——匯入
	 * 寫進去的 anime_title_chinese 是大陸譯名逐字簡轉繁，而站上多數標題已被
	 * 人工改成台灣官方譯名，改的結果在 post_title（見 class-import-manager.php）。
	 *
	 * 清單名稱是英文的來源（It's Anime）覆寫這裡改用英文／羅馬字欄位。
	 *
	 * @return string[]
	 */
	public function match_titles( int $post_id ): array {
		return array_values( array_filter( array_unique( array_merge(
			// ID 鍵放前面：lookup() 先用 ID，有命中就以 ID 為準（見 lookup 的說明）
			class_exists( 'Anime_Sync_Bangumi_Data_Feed' ) ? Anime_Sync_Bangumi_Data_Feed::post_id_keys( $post_id ) : [],
			[
				(string) get_the_title( $post_id ),
				(string) get_post_meta( $post_id, 'anime_title_chinese', true ),
			]
		) ) ) );
	}

	/**
	 * 把 bangumi-data 對照表裡屬於這個平台的條目併進 $grouped，鍵是 `bgm:{id}`／`al:{id}`。
	 *
	 * 這些鍵經 normalize() 會變成 `bgm123`——兩邊（索引與 match_titles）走同一個
	 * normalize，所以一致；標題不可能正規化成這種形狀，不會撞。
	 *
	 * @param string[] $sites   bangumi-data 站點 key，多個時前面的優先
	 * @param callable $url_for fn( string $site, string $id ): string
	 * @return int 併入的條目數
	 */
	protected function merge_bangumi_data( array &$grouped, array $sites, callable $url_for ): int {

		if ( ! class_exists( 'Anime_Sync_Bangumi_Data_Feed' ) ) {
			return 0;
		}

		$n = 0;
		foreach ( Anime_Sync_Bangumi_Data_Feed::map() as $id_key => $site_ids ) {
			foreach ( $sites as $site ) {
				if ( empty( $site_ids[ $site ] ) ) {
					continue;
				}
				$url = (string) $url_for( $site, (string) $site_ids[ $site ] );
				if ( $url === '' ) {
					break;
				}
				// 鍵本身就是作品名，add_entry 保留第一筆（前面的站點優先）
				$this->add_entry( $grouped, $id_key, [ 'title' => $id_key, 'url' => $url, 'date' => '' ] );
				$n++;
				break;
			}
		}

		return $n;
	}

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

		/*
		 * 兩段：先 ID 鍵（bgm:／al:，來自 bangumi-data），再標題。
		 * ID 有命中就以 ID 為準、不跟標題混算唯一性——否則「ID 配到播放清單 A、標題配到
		 * 播放清單 B」會被判成多重候選而放棄，等於白接了 ID 對照。
		 */
		$id_titles = array_values( array_filter( $titles, static fn( $t ) => preg_match( '/^(bgm|al):\d+$/', (string) $t ) ) );
		$tx_titles = array_values( array_diff( $titles, $id_titles ) );

		$hits = [];

		foreach ( [ $id_titles, $tx_titles ] as $pass ) {
			foreach ( $pass as $t ) {

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
			if ( ! empty( $hits ) ) {
				break;   // ID 那段有結果就不看標題
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
	// 下架偵測
	// =====================================================================

	/** 連續幾輪配不到才視為下架並移除。防平台暫時抽片或改名一週。 */
	const GONE_STRIKES = 3;

	/**
	 * 這一輪的索引是不是「平台現在全部有什麼」的完整快照。
	 * 重建型來源每輪都是；增量型（LINE TV）只有佇列清空時才是，
	 * 否則會把還沒爬到的當成下架。
	 */
	protected function index_is_complete(): bool {
		return ! $this->incremental();
	}

	protected function gone_meta_key(): string {
		return '_anime_tw_streaming_gone_' . $this->key();
	}

	/**
	 * 反向檢查：我們寫過的作品，這輪索引裡還配得到嗎？
	 *
	 * ★ 只看帶來源標記的（我們自己寫的）。那些當初是「完全相符＋唯一」配到的，
	 *   同一套規則現在配不到，才有理由相信是平台拿掉了。YA 或人工寫的沒有這個
	 *   前提——譯名差異就會配不到（召回率天花板 74%），不能拿來判下架；
	 *   那些只在後台列成「疑似」給人看，不自動動。
	 *
	 * 第一次配不到：記 gone meta「YYYY-MM-DD|1」，前台顯示「可能已下架」。
	 * 連續 GONE_STRIKES 輪：取消勾選、刪網址與標記。中間任何一輪又配到：清掉 gone。
	 *
	 * @return array{checked:int,marked:int,cleared:int,removed:int,samples:string[]}
	 */
	protected function check_gone( bool $write ): array {
		global $wpdb;

		$r = [ 'checked' => 0, 'marked' => 0, 'cleared' => 0, 'removed' => 0, 'samples' => [] ];

		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$this->src_meta_key()
		) ) );

		foreach ( $ids as $id ) {

			$r['checked']++;
			$hit  = $this->lookup( $this->match_titles( $id ), (string) get_post_meta( $id, 'anime_start_date', true ) );
			$gone = (string) get_post_meta( $id, $this->gone_meta_key(), true );

			if ( $hit['status'] === 'hit' ) {
				if ( $gone !== '' ) {
					delete_post_meta( $id, $this->gone_meta_key() );
					$r['cleared']++;
				}
				continue;
			}

			// 配不到：累計 strike
			$strike = 1;
			if ( $gone !== '' && preg_match( '/\|(\d+)$/', $gone, $m ) ) {
				$strike = (int) $m[1] + 1;
			}

			if ( $strike >= self::GONE_STRIKES && $write ) {
				delete_post_meta( $id, $this->url_meta_key() );
				delete_post_meta( $id, $this->src_meta_key() );
				delete_post_meta( $id, $this->gone_meta_key() );
				$checked = get_post_meta( $id, 'anime_tw_streaming', true );
				if ( is_array( $checked ) ) {
					update_post_meta( $id, 'anime_tw_streaming', array_values( array_filter( $checked, fn( $k ) => $k !== $this->key() ) ) );
				}
				do_action( 'litespeed_purge_post', $id );
				$r['removed']++;
				if ( count( $r['samples'] ) < 10 ) {
					$r['samples'][] = sprintf( '移除 #%d %s', $id, get_the_title( $id ) );
				}
				continue;
			}

			update_post_meta( $id, $this->gone_meta_key(), gmdate( 'Y-m-d' ) . '|' . $strike );
			do_action( 'litespeed_purge_post', $id );
			$r['marked']++;
			if ( count( $r['samples'] ) < 10 ) {
				$r['samples'][] = sprintf( '疑似下架 #%d %s（第 %d 輪）', $id, get_the_title( $id ), $strike );
			}
		}

		if ( $r['marked'] || $r['removed'] ) {
			$this->log_warning( sprintf( '下架偵測：檢查 %d、疑似 %d、移除 %d、恢復 %d', $r['checked'], $r['marked'], $r['removed'], $r['cleared'] ) );
		}

		return $r;
	}

	// =====================================================================
	// 授權到期日：平台自己宣告的下架日期（比「三輪配不到」準得多）
	// =====================================================================

	/*
	 * 2026-09-15 發現 Hami 每個作品頁都寫「下架時間 2026年09月24日」，Ofiii／LiTV 的
	 * JSON-LD 也有 expires。這是平台第一手的下架日期，而且站上該平台的網址（不管是
	 * 我們配的、YA 給的、人工貼的）全部都能查，不像 check_gone() 只敢動自己寫過的。
	 *
	 * 機制通用、各來源只覆寫 provides_end_date() 與 parse_end_date()：
	 *   - meta `_anime_tw_streaming_end_{key}` = 「到期日|上次查核日」，到期日可為空（頁面沒寫）
	 *   - 每輪只查一批（END_BATCH）：沒查過的 → 45 天內到期的 → 超過 30 天沒再查的
	 *   - 到期前 END_NOTIFY_DAYS 天發一則 streaming 事件通知追番者（指紋含日期，續約改期會再發一次）
	 *   - 到期日過了才「覆核」：作品頁 404 或不再列日期 → 真下架，移除該平台（留 `_ended_` 標記可還原）；
	 *     頁面給了新日期 → 續約，只更新日期。不到期不動、不推論。
	 *   - dry-run 只抽 END_DRY_SAMPLE 部看得出解析對不對，不寫任何 meta、不發事件
	 */
	/** 索引重建的最小間隔；排程可以比這個密（見 run_locked 的說明）。 */
	const REBUILD_INTERVAL_DAYS = 6;

	const END_BATCH        = 150;
	const END_DRY_SAMPLE   = 20;
	const END_INTERVAL_US  = 500000;
	const END_SOON_DAYS    = 45;   // 這個範圍內到期的每輪重查（續約會改日期）
	const END_RECHECK_DAYS = 30;   // 其餘至少每 30 天重查一次
	const END_NOTIFY_DAYS  = 14;   // 到期前幾天發事件
	const END_FRONT_DAYS   = 30;   // 前台顯示「授權至 M/D」與「即將下架」清單的範圍

	/** 這個來源的作品頁有沒有明文到期日可解析。 */
	protected function provides_end_date(): bool {
		return false;
	}

	/**
	 * 正式站主機打得到這個平台的作品頁，而且「作品不存在」有明確的狀態碼嗎？
	 *
	 * 為真的來源走 recheck_urls()：用平台第一手回應判斷還在不在，因此**不限網址是誰寫的**
	 * （YA、人工、我們自己）都能覆核。為假的來源只能退回 check_gone() 的索引比對，
	 * 而索引比對會被譯名差異誤判，所以那條路只敢動自己寫過的。
	 *
	 * 2026-09-16 從主機實測（存在／不存在）：
	 *   MyVideo 200/404、Ofiii 200/404、LiTV 200/404、LINE TV 200/404、Hami 200/404、friDay 200/**400**
	 *   CatchPlay 302/302（兩者都轉回首頁，分不出來）、巴哈與 Crunchyroll 403/403（Cloudflare 擋機房 IP）
	 */
	protected function provides_alive_check(): bool {
		return false;
	}

	/** 哪些 HTTP 狀態碼代表「這個作品不存在」。friDay 是 400 不是 404。 */
	protected function alive_missing_codes(): array {
		return [ 404 ];
	}

	/**
	 * 每輪覆核幾筆。預設 END_BATCH；本身就要大量抓頁面的來源（LINE TV 每小時爬 300 頁
	 * 建索引）要調小，免得對同一個平台一小時打 450 次。
	 */
	protected function recheck_batch(): int {
		return self::END_BATCH;
	}

	/**
	 * 「只覆核、不發現」的來源。
	 *
	 * Prime Video 與 Apple TV 沒有可列舉的台灣目錄（Prime 無 sitemap 且分類頁是前端渲染；
	 * Apple TV 的 sitemap 要下載 234MB 才能濾出台灣），所以不建索引、不找新作品，
	 * 只驗證站上既有網址（AniList 匯入寫的）現在還能不能看。跳過 run() 的索引與比對階段。
	 */
	protected function verify_only(): bool {
		return false;
	}

	/**
	 * 用頁面內容判斷還在不在——狀態碼看不出來的平台用這個。
	 *
	 * Prime 對台灣看不到的作品照樣回 200，要看主要按鈕是不是
	 * 「您所在地區的 Prime Video 無法繼續觀看此內容」；
	 * Apple TV 的目錄頁與真正有在賣的頁面也都是 200，差別在有沒有 iTunes 商店的播放資料。
	 *
	 * 也收網址：有些判斷只看網址就成立——站上有 2 筆 Apple TV 網址是美國區／日本區
	 * （AniList 給錯地區），那種連結對台灣讀者無效，不必抓頁面就能判定。
	 *
	 * @return bool|null true＝還在、false＝已下架或本地區看不到、null＝判斷不出來（不動它）
	 */
	protected function parse_alive( string $html, string $url = '' ): ?bool {
		return null;
	}

	/**
	 * 覆核前改寫網址。AniList 給的 Prime 連結是全球版（primevideo.com/detail/{ASIN}），
	 * 那種網址在台灣打開判斷不出可看性，要改成 /-/zh_TW/detail/{ASIN} 才問得到答案。
	 * 只影響「拿去抓的網址」，不動資料庫裡存的值。
	 */
	protected function recheck_url( string $url ): string {
		return $url;
	}

	/**
	 * 從作品頁取到期日。
	 *
	 * @return string|null 'Y-m-d'；''＝頁面正常但沒寫到期日（例如長期授權）；null＝頁面結構認不得
	 */
	protected function parse_end_date( string $html ): ?string {
		return null;
	}

	/** 抓作品頁的 fetch() 選項（例如只要前 64KB）。 */
	protected function end_date_fetch_opts(): array {
		return [ 'accept' => 'text/html', 'allow_404' => true ];
	}

	public static function end_meta_key_for( string $key ): string {
		return '_anime_tw_streaming_end_' . $key;
	}

	protected function end_meta_key(): string {
		return self::end_meta_key_for( $this->key() );
	}

	/** 「到期日|查核日」拆開；缺的用空字串。 */
	public static function split_end_meta( string $raw ): array {
		$p = explode( '|', $raw, 2 );
		return [ 'end' => trim( $p[0] ?? '' ), 'checked' => trim( $p[1] ?? '' ) ];
	}

	/**
	 * 查一批作品頁、更新到期日、到期覆核、發到期前通知。
	 *
	 * @return array{checked:int,dated:int,undated:int,unparsed:int,soon:int,notified:int,renewed:int,removed:int,samples:string[]}
	 */
	protected function recheck_urls( bool $write, float $started ): array {
		global $wpdb;

		$r = [ 'checked' => 0, 'alive' => 0, 'dead' => 0, 'dead_removed' => 0, 'dated' => 0, 'undated' => 0, 'unparsed' => 0, 'soon' => 0, 'notified' => 0, 'renewed' => 0, 'removed' => 0, 'samples' => [] ];

		$today  = current_time( 'Y-m-d' );
		$soon   = gmdate( 'Y-m-d', strtotime( $today ) + self::END_SOON_DAYS * DAY_IN_SECONDS );
		$stale  = gmdate( 'Y-m-d', strtotime( $today ) - self::END_RECHECK_DAYS * DAY_IN_SECONDS );

		// 站上所有有這個平台網址的已發布作品（不限來源）＋現有到期 meta
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, u.meta_value AS url, e.meta_value AS end_meta
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = %s AND u.meta_value <> ''
			   LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
			  WHERE p.post_type = 'anime' AND p.post_status = 'publish'",
			$this->url_meta_key(),
			$this->end_meta_key()
		) );

		// 排優先序：到期已過或 45 天內到期 → 沒查過 → 超過 30 天沒查；其餘略過
		$due = [];
		foreach ( (array) $rows as $row ) {
			$m = self::split_end_meta( (string) $row->end_meta );
			if ( $m['checked'] === '' ) {
				$prio = 1;
			} elseif ( $m['end'] !== '' && $m['end'] <= $soon ) {
				$prio = $m['end'] < $today ? 0 : 1;
			} elseif ( $m['checked'] <= $stale ) {
				$prio = 2;
			} else {
				continue;
			}
			$due[] = [ 'prio' => $prio, 'id' => (int) $row->ID, 'url' => (string) $row->url, 'end' => $m['end'] ];
		}
		usort( $due, static fn( $a, $b ) => $a['prio'] <=> $b['prio'] ?: $a['id'] <=> $b['id'] );

		$limit = $write ? $this->recheck_batch() : self::END_DRY_SAMPLE;
		$due   = array_slice( $due, 0, $limit );

		foreach ( $due as $i => $job ) {

			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				break;
			}
			if ( $i > 0 ) {
				usleep( self::END_INTERVAL_US );
			}

			$id   = $job['id'];
			$opts = $this->end_date_fetch_opts();
			$opts['missing_codes'] = $this->alive_missing_codes();
			$html = $this->fetch( $this->recheck_url( $job['url'] ), $opts );
			$r['checked']++;

			$label = sprintf( '#%d %s', $id, mb_substr( (string) get_the_title( $id ), 0, 24 ) );

			if ( is_wp_error( $html ) ) {

				if ( $html->get_error_code() === 'circuit_open' ) {
					break;
				}

				/*
				 * 平台回「這個作品不存在」——第一手證據，所以不限網址是誰寫的都能處理。
				 * 但仍要連續 GONE_STRIKES 輪才移除：平台改版、暫時抽片、CDN 抽風都可能回一次 404，
				 * 而移除是會讓讀者少一個觀看管道的破壞性動作，寧可晚三輪也不要錯殺。
				 */
				if ( $html->get_error_code() === 'not_found' ) {
					$this->mark_dead( $id, $label, $job['end'], $write, $r, '頁面不存在' );
				}

				// 其他錯誤（逾時、5xx）什麼都不做：那是我們這端或對方暫時的問題，不是下架
				continue;
			}

			/*
			 * 狀態碼是 200，但內容可能寫著「你的地區看不到」或「這頁只是目錄、沒有在賣」。
			 * 有實作 parse_alive() 的來源要再問一次；回 null 表示判斷不出來，當作還在、不動它。
			 */
			if ( $this->parse_alive( (string) $html, (string) $job['url'] ) === false ) {
				$this->mark_dead( $id, $label, $job['end'], $write, $r, '頁面顯示本地區無法觀看' );
				continue;
			}

			/*
			 * 頁面拿得到＝還在架上。先清掉先前累積的 strike，
			 * 否則「這輪 404、下輪正常、再下輪 404」會被湊成三輪誤刪。
			 */
			$r['alive']++;
			if ( $write && (string) get_post_meta( $id, $this->gone_meta_key(), true ) !== '' ) {
				delete_post_meta( $id, $this->gone_meta_key() );
				do_action( 'litespeed_purge_post', $id );
			}

			// 沒有到期日可解析的來源到此為止，只記查核日（下次輪到它的時間往後推）
			if ( ! $this->provides_end_date() ) {
				if ( $write ) {
					update_post_meta( $id, $this->end_meta_key(), '|' . $today );
				}
				continue;
			}

			$end = $this->parse_end_date( (string) $html );

			if ( $end === null ) {
				$r['unparsed']++;
				if ( $write ) {
					// 記查核日以免每輪都重抓同一批認不得的頁；解析錯了會在後台的 unparsed 計數看到
					update_post_meta( $id, $this->end_meta_key(), $job['end'] . '|' . $today );
				}
				continue;
			}

			if ( $end === '' ) {
				$r['undated']++;
				// 之前有到期日、現在頁面不再列日期，而且已過期 → 平台把它拿掉了
				if ( $job['end'] !== '' && $job['end'] < $today ) {
					$r['removed']++;
					if ( count( $r['samples'] ) < 10 ) {
						$r['samples'][] = '到期且頁面已無日期，移除：' . $label;
					}
					if ( $write ) {
						$this->remove_ended( $id, $job['end'] );
					}
					continue;
				}
				if ( $write ) {
					update_post_meta( $id, $this->end_meta_key(), '|' . $today );
				}
				continue;
			}

			$r['dated']++;

			if ( $job['end'] !== '' && $job['end'] < $today && $end > $job['end'] ) {
				$r['renewed']++;
				if ( count( $r['samples'] ) < 10 ) {
					$r['samples'][] = '續約：' . $label . '（' . $job['end'] . ' → ' . $end . '）';
				}
			}

			if ( $write ) {
				update_post_meta( $id, $this->end_meta_key(), $end . '|' . $today );
			}

			// 到期日已過但頁面仍列同一個過去日期：平台還沒下架、只是沒更新，先不動
			if ( $end < $today ) {
				continue;
			}

			$days_left = (int) floor( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			if ( $days_left <= self::END_NOTIFY_DAYS ) {
				$r['soon']++;
				if ( count( $r['samples'] ) < 10 ) {
					$r['samples'][] = sprintf( '%d 天後到期：%s（%s）', $days_left, $label, $end );
				}
				if ( $write && $this->notify_ending( $id, $end ) ) {
					$r['notified']++;
				}
			}
		}

		if ( $r['unparsed'] >= 5 ) {
			$this->log_warning( sprintf( '到期日解析：%d 頁認不得（本輪 %d 頁），作品頁結構可能改了', $r['unparsed'], $r['checked'] ) );
		}
		if ( $r['removed'] || $r['notified'] ) {
			$this->log_info( sprintf( '到期日：查 %d、有日期 %d、14 天內到期 %d、發通知 %d、續約 %d、到期移除 %d', $r['checked'], $r['dated'], $r['soon'], $r['notified'], $r['renewed'], $r['removed'] ) );
		}

		return $r;
	}

	/**
	 * 到期覆核確認真的下架：取消勾選、刪網址與各標記，留 `_anime_tw_streaming_ended_{key}`
	 * （「到期日@移除日」）當還原線索。這裡會動到 YA／人工寫的網址——依據是平台明文日期加頁面覆核，
	 * 不是推論，跟 check_gone() 的保守不同。
	 */
	/**
	 * 覆核判定「不在架上」的共同處理：累計 strike，滿 GONE_STRIKES 輪才真的移除。
	 *
	 * 兩種證據都走這裡——HTTP 狀態碼說不存在（404／400），或頁面內容說本地區看不到。
	 * 兩者都是平台第一手回應，所以不限網址是誰寫的。
	 *
	 * @param array $r 覆核統計，會就地更新
	 */
	/*
	 * ★ 刻意不在下架時發通知（2026-09-16 使用者決定）。
	 *   移除只留 _ended_ 標記與作品頁的「近期下架」標示，不推播給追番會員——
	 *   下架是每天都在發生的日常異動，逐筆通知會變成洗版。
	 *   「到期前 14 天」的預告通知（notify_ending）保留，那是讀者還來得及看完的資訊。
	 */

	private function mark_dead( int $post_id, string $label, string $end, bool $write, array &$r, string $reason ): void {

		$r['dead']++;

		$gone   = (string) get_post_meta( $post_id, $this->gone_meta_key(), true );
		$strike = ( $gone !== '' && preg_match( '/\|(\d+)$/', $gone, $m ) ) ? (int) $m[1] + 1 : 1;

		if ( $strike >= self::GONE_STRIKES ) {
			$r['dead_removed']++;
			if ( count( $r['samples'] ) < 10 ) {
				$r['samples'][] = sprintf( '%s滿 %d 輪，移除：%s', $reason, self::GONE_STRIKES, $label );
			}
			if ( $write ) {
				$this->remove_ended( $post_id, $end );
			}
			return;
		}

		if ( count( $r['samples'] ) < 10 ) {
			$r['samples'][] = sprintf( '%s（第 %d/%d 輪）：%s', $reason, $strike, self::GONE_STRIKES, $label );
		}
		if ( $write ) {
			update_post_meta( $post_id, $this->gone_meta_key(), gmdate( 'Y-m-d' ) . '|' . $strike );
			do_action( 'litespeed_purge_post', $post_id );
		}
	}

	protected function remove_ended( int $post_id, string $end ): void {
		delete_post_meta( $post_id, $this->url_meta_key() );
		delete_post_meta( $post_id, $this->src_meta_key() );
		delete_post_meta( $post_id, $this->gone_meta_key() );
		delete_post_meta( $post_id, $this->end_meta_key() );
		/*
		 * 還原線索：`到期日@移除日`。到期日為空字串代表這筆不是「授權到期」而是
		 * 「作品頁連續 N 輪回不存在」——兩種移除理由要分得出來，日後要復原或追查才有依據。
		 */
		update_post_meta( $post_id, '_anime_tw_streaming_ended_' . $this->key(), $end . '@' . current_time( 'Y-m-d' ) );
		$checked = get_post_meta( $post_id, 'anime_tw_streaming', true );
		if ( is_array( $checked ) ) {
			update_post_meta( $post_id, 'anime_tw_streaming', array_values( array_filter( $checked, fn( $k ) => $k !== $this->key() ) ) );
		}
		do_action( 'litespeed_purge_post', $post_id );
	}

	/**
	 * 到期前通知：一則 streaming 事件，指紋帶到期日，同一個日期只發一次；
	 * 平台續約改了日期會再發（那也是讀者想知道的）。走事件系統既有的通知與前台顯示。
	 */
	protected function notify_ending( int $post_id, string $end ): bool {
		if ( ! class_exists( 'Anime_Sync_Anime_Events' ) ) {
			return false;
		}
		$ts      = strtotime( $end );
		$summary = sprintf( '%s 授權至 %d 月 %d 日，之後將從該平台下架', $this->label(), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
		$id      = Anime_Sync_Anime_Events::record( [
			'anime_id'    => $post_id,
			'event_type'  => 'streaming',
			'fingerprint' => 'end:' . $this->key() . ':' . $end,
			'summary'     => $summary,
			'source'      => 'platform',
			'payload'     => [ 'platform' => $this->key(), 'end_date' => $end ],
		] );
		if ( $id <= 0 ) {
			return false;   // 0＝同一日期已發過，-1＝失敗；兩者都不算本輪新通知
		}
		return Anime_Sync_Anime_Events::publish( $id, $summary );
	}

	/**
	 * 前台用：某作品各平台的未來到期日（只回 END_FRONT_DAYS 內的）。
	 *
	 * @return array<string,string> 平台 key → 'Y-m-d'
	 */
	public static function ending_soon_for_post( int $post_id ): array {
		$out   = [];
		$today = current_time( 'Y-m-d' );
		$limit = gmdate( 'Y-m-d', strtotime( $today ) + self::END_FRONT_DAYS * DAY_IN_SECONDS );
		foreach ( (array) get_post_meta( $post_id ) as $k => $v ) {
			if ( strpos( (string) $k, '_anime_tw_streaming_end_' ) !== 0 ) {
				continue;
			}
			$m = self::split_end_meta( (string) ( $v[0] ?? '' ) );
			if ( $m['end'] !== '' && $m['end'] >= $today && $m['end'] <= $limit ) {
				$out[ substr( (string) $k, strlen( '_anime_tw_streaming_end_' ) ) ] = $m['end'];
			}
		}
		return $out;
	}

	const ENDING_CACHE_KEY = 'asp_streaming_ending_soon_v1';

	/**
	 * 前台總覽用：全站 END_FRONT_DAYS 內即將下架的清單（快取 6 小時，寫入／移除時一起清）。
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,platform:string,end:string}>
	 */
	public static function ending_soon_list( int $limit = 60 ): array {
		global $wpdb;

		$cached = get_transient( self::ENDING_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit );
		}

		$today = current_time( 'Y-m-d' );
		$until = gmdate( 'Y-m-d', strtotime( $today ) + self::END_FRONT_DAYS * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			"SELECT m.post_id, m.meta_key, m.meta_value, p.post_title
			   FROM {$wpdb->postmeta} m
			   JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'anime' AND p.post_status = 'publish'
			  WHERE m.meta_key LIKE '\_anime\_tw\_streaming\_end\_%' AND m.meta_value <> '' AND m.meta_value NOT LIKE '|%'"
		);

		$list = [];
		foreach ( (array) $rows as $row ) {
			$m = self::split_end_meta( (string) $row->meta_value );
			if ( $m['end'] === '' || $m['end'] < $today || $m['end'] > $until ) {
				continue;
			}
			$list[] = [
				'post_id'  => (int) $row->post_id,
				'title'    => (string) $row->post_title,
				'url'      => (string) get_permalink( (int) $row->post_id ),
				'platform' => substr( (string) $row->meta_key, strlen( '_anime_tw_streaming_end_' ) ),
				'end'      => $m['end'],
			];
		}
		usort( $list, static fn( $a, $b ) => strcmp( $a['end'], $b['end'] ) ?: strcmp( $a['title'], $b['title'] ) );

		set_transient( self::ENDING_CACHE_KEY, $list, 6 * HOUR_IN_SECONDS );

		return array_slice( $list, 0, $limit );
	}

	/**
	 * 後台頁用：這個平台的到期日統計。
	 *
	 * @return array{known:int,soon:int,expired:int}
	 */
	public function end_stats(): array {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$until = gmdate( 'Y-m-d', strtotime( $today ) + self::END_FRONT_DAYS * DAY_IN_SECONDS );
		$vals  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $this->end_meta_key() ) );

		/*
		 * total：站上這個平台的網址總數（覆核的分母，不限誰寫的）
		 * checked：已經覆核過的筆數
		 * suspect：目前累積 strike、尚未滿三輪的「疑似下架」
		 * has_end_date：這個來源解不解得出到期日——決定後台要顯示「到期日」還是只顯示「覆核」，
		 *               只做存活覆核的五家（MyVideo／Ofiii／LiTV／friDay／LINE TV）沒有到期日，
		 *               顯示「有日期 0」會讓人以為壞了。
		 */
		$total   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type = 'anime' AND p.post_status = 'publish'",
			$this->url_meta_key()
		) );
		$suspect = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $this->gone_meta_key() ) );

		$s = [
			'known'        => 0,
			'soon'         => 0,
			'expired'      => 0,
			'checked'      => count( $vals ),
			'total'        => $total,
			'suspect'      => $suspect,
			'has_end_date' => $this->provides_end_date(),
			'can_recheck'  => $this->provides_alive_check() || $this->provides_end_date(),
		];
		foreach ( $vals as $v ) {
			$m = self::split_end_meta( (string) $v );
			if ( $m['end'] === '' ) {
				continue;
			}
			$s['known']++;
			if ( $m['end'] < $today ) {
				$s['expired']++;
			} elseif ( $m['end'] <= $until ) {
				$s['soon']++;
			}
		}
		return $s;
	}

	// =====================================================================
	// 單篇即時同步：新匯入的作品不必等週排程
	// =====================================================================

	/**
	 * 用磁碟上現有的索引對一篇作品比對五家平台，有命中且欄位空白就寫。
	 *
	 * 零外連：只讀 uploads 裡的索引 JSON（每家幾百 KB～2MB），幾十毫秒。
	 * 給匯入流程當場呼叫，讀者匯入完馬上看得到直接來源補上的平台；
	 * 週排程照常跑，負責把索引更新到最新。
	 *
	 * 尊重 WRITE_OPTION：那是整條線的安全閘，關著就只回報命中不寫。
	 *
	 * @return array<string,string> 有寫入的平台 key → 網址
	 */
	public static function sync_post_from_indexes( int $post_id ): array {

		$written = [];
		$write   = (string) get_option( self::WRITE_OPTION, '0' ) === '1';

		$start = (string) get_post_meta( $post_id, 'anime_start_date', true );

		foreach ( self::available_keys() as $key ) {
			$src = self::make( $key );
			if ( ! $src || empty( $src->load_index() ) ) {
				continue;
			}
			$titles = $src->match_titles( $post_id );
			if ( empty( $titles ) ) {
				continue;
			}
			$r = $src->lookup( $titles, $start );
			if ( $r['status'] !== 'hit' ) {
				continue;
			}
			if ( $write && $src->write( $post_id, $r['url'] ) ) {
				$written[ $key ] = $r['url'];
			}
		}

		if ( $written ) {
			self::purge_streaming_pages();
		}

		return $written;
	}

	/**
	 * 寫入後讓公開頁面反映新資料。
	 *
	 * 兩層快取都要清：
	 *   1. 外掛自己的計數 transient（/streaming/ 各平台收錄數，6 小時）
	 *   2. LiteSpeed 頁面快取。/streaming/ 是虛擬路由、作品頁的 meta 又是直接
	 *      update_post_meta 寫的，兩者都不會觸發 LiteSpeed 隨文章儲存的自動清除，
	 *      不清的話用戶最多看 7 天舊頁（max-age=604800）。
	 *      LiteSpeed 沒啟用時 do_action 沒人接，什麼都不會發生。
	 */
	protected static function purge_streaming_pages(): void {

		if ( ! class_exists( 'Anime_Sync_Streaming_Routing' ) ) {
			return;
		}

		delete_transient( Anime_Sync_Streaming_Routing::COUNT_CACHE_KEY );
		delete_transient( self::ENDING_CACHE_KEY );

		$urls = [ Anime_Sync_Streaming_Routing::index_url() ];
		foreach ( self::available_keys() as $key ) {
			$urls[] = Anime_Sync_Streaming_Routing::platform_url( $key );
		}

		/*
		 * 一定要靜音。`litespeed_purge_url` 這個 action 只接一個參數，每清一個網址就往後台塞一則
		 * 「清除網址 /streaming/xxx/」通知，13 個來源 × 每次寫入 = 後台被 14 則綠色通知淹掉
		 * （2026-09-15 使用者反映）。LiteSpeed 的 purge_url() 第三個參數 $quite 就是給這種背景清除用的，
		 * 直接呼叫類別；LiteSpeed 不在或版本沒有這個方法時退回 action（會有通知，但至少有清）。
		 */
		$purge = class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'cls' ) ? \LiteSpeed\Purge::cls() : null;
		foreach ( $urls as $url ) {
			if ( $purge && method_exists( $purge, 'purge_url' ) ) {
				$purge->purge_url( $url, false, true );
			} else {
				do_action( 'litespeed_purge_url', $url );
			}
		}
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

		/*
		 * 重新上架就把「曾經下架」的紀錄清掉。
		 *
		 * _anime_tw_streaming_ended_{key} 是覆核移除時留的還原線索，但平台下架後又重新上架
		 * 是常見的事（授權續約、分季重上）。不清的話這筆標記會永遠留著，而且會累積——
		 * 作品頁就可能同時出現「Hami 按鈕」和「近期下架：Hami」，自相矛盾。
		 * 前台雖然另外用「網址當下有沒有值」擋住了這個矛盾，但那是顯示層的補救；
		 * 資料本身該在這裡就修乾淨，否則日後換一種判斷方式又會露出來。
		 */
		delete_post_meta( $post_id, '_anime_tw_streaming_ended_' . $this->key() );
		delete_post_meta( $post_id, $this->gone_meta_key() );

		$checked = get_post_meta( $post_id, 'anime_tw_streaming', true );
		if ( ! is_array( $checked ) ) {
			$checked = [];
		}
		if ( ! in_array( $this->key(), $checked, true ) ) {
			$checked[] = $this->key();
			update_post_meta( $post_id, 'anime_tw_streaming', $checked );
		}

		// 作品頁的串流區塊要立刻反映，見 purge_streaming_pages() 的說明
		do_action( 'litespeed_purge_post', $post_id );

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

		/*
		 * ★ 最後一定要再小寫一次，否則同一部作品的兩種季別寫法會得到不同的索引鍵。
		 *
		 *   normalize_public() 內部的順序是「先把 第2期／2期／シーズン2／Season 2 換成 @S2，
		 *   最後整串 mb_strtolower」，所以它吐出來的季別標記是小寫的 @s2；
		 *   而上面這兩段是在那之後才跑的，換出來的是大寫 @S2。結果：
		 *       「進擊的巨人 第2季」 → 進擊的巨人@s2   （normalize_public 處理，被小寫化）
		 *       「進擊的巨人 第二季」→ 進擊的巨人@S2   （中文數字它不認，由這裡處理，沒被小寫化）
		 *   兩個鍵不同 → 站上寫「第二季」、平台寫「第2季」就永遠配不到。
		 *   （normalize_public 只認 2~6，季數 ≥7 時大小寫還會再翻一次，更亂。）
		 *
		 *   2026-09-16 由 tests/test-streaming-sources.php 抓到。改動會讓索引鍵變形，
		 *   但排程每輪都 rebuild，下一輪即一致；空窗期只會「少配到」不會「配錯」。
		 */
		return mb_strtolower( $s, 'UTF-8' );
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
	 * @param array{range_bytes?:int,accept?:string,method?:string,body?:array,allow_404?:bool} $opts
	 *        range_bytes：只要前 N bytes（LINE TV 作品頁 500KB，<title> 在前 64KB 內；
	 *        對方回 206，實測 2026-09-15）。accept：覆寫 Accept 標頭（抓 HTML 時用）。
	 *        method／body：POST 表單（Hami 的翻頁端點 ui26_page.do 只吃 POST）。
	 *        allow_404：404 回 WP_Error('not_found') 但**不計入熔斷**——到期日覆核時，
	 *        作品真的下架了頁面就是 404，那是答案不是故障，連三個 404 不能把整個來源熔掉。
	 *        missing_codes：同上，但自訂哪些狀態碼代表「這個作品不存在」。
	 *        平台不一定用 404：friDay 對不存在的 id 回 **400**「資料錯誤」（2026-09-16 測三個 id 都一致）。
	 * @return string|WP_Error
	 */
	protected function fetch( string $url, array $opts = [] ) {

		if ( $this->is_circuit_open() ) {
			return new WP_Error( 'circuit_open', '熔斷中，暫停對 ' . $this->label() . ' 的請求' );
		}

		$headers = [
			'Accept'          => (string) ( $opts['accept'] ?? 'application/xml,text/xml;q=0.9,*/*;q=0.8' ),
			'Accept-Language' => 'zh-TW,zh;q=0.9,en;q=0.8',
		];

		$args = [
			'timeout'     => self::HTTP_TIMEOUT,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
		];

		$range = (int) ( $opts['range_bytes'] ?? 0 );
		if ( $range > 0 ) {
			$headers['Range'] = 'bytes=0-' . ( $range - 1 );
			// 對方不理 Range 照樣回整頁時，至少在這裡截斷，不把 500KB 全讀進來
			$args['limit_response_size'] = $range;
		}

		$args['headers'] = $headers;

		if ( strtoupper( (string) ( $opts['method'] ?? 'GET' ) ) === 'POST' ) {
			$args['method'] = 'POST';
			$args['body']   = (array) ( $opts['body'] ?? [] );
			$res = wp_remote_post( $url, $args );
		} else {
			$res = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $res ) ) {
			$this->record_failure();
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		$missing = array_map( 'intval', (array) ( $opts['missing_codes'] ?? [] ) );
		if ( ! empty( $opts['allow_404'] ) ) {
			$missing[] = 404;
		}
		if ( $missing && in_array( $code, $missing, true ) ) {
			// 「這個作品不存在」是答案不是故障，所以不計入熔斷（否則一輪連三個下架就把來源熔掉）
			return new WP_Error( 'not_found', 'HTTP ' . $code );
		}
		// 206 = Range 請求成功的部分內容
		if ( $code !== 200 && $code !== 206 ) {
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
			// 增量型來源多印抓取進度（已抓／待抓／sitemap 總數）
			if ( method_exists( $src, 'progress' ) ) {
				$p = $src->progress();
				WP_CLI::log( sprintf( '爬取進度：已抓 %d／待抓 %d／sitemap 共 %d', $p['done'], $p['pending'], $p['total'] ) );
			}
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

		// 抽樣（--limit）不記，數字不代表全量；其餘 CLI 執行都寫日誌，後台頁才看得到
		if ( $limit === 0 ) {
			$src->log_result( '[手動]', $write, $r );
		}

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( sprintf( '索引：%s，%d 部作品', $r['built'] ? '本次重建（' . $r['entries'] . ' 條目）' : '沿用既有', $r['works'] ) );
		WP_CLI::log( sprintf( '站上尚無此平台網址：%d 部；本次比對 %d 部%s', $r['candidates'], $r['scanned'], $limit > 0 ? '（隨機抽樣）' : '' ) );
		WP_CLI::log( sprintf( '  唯一命中 %d｜多重候選（放棄）%d｜未命中 %d', $r['hit'], $r['multi'], $r['miss'] ) );
		if ( $write ) {
			WP_CLI::log( sprintf( '  已寫入 %d 部', $r['written'] ) );
		}
		if ( ! empty( $r['gone'] ) && $r['gone']['checked'] > 0 ) {
			WP_CLI::log( sprintf( '下架偵測：檢查 %d｜疑似 %d｜恢復 %d｜移除 %d%s',
				$r['gone']['checked'], $r['gone']['marked'], $r['gone']['cleared'], $r['gone']['removed'],
				$write ? '' : '（dry-run 只標記不移除）' ) );
			foreach ( $r['gone']['samples'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		}
		if ( ! empty( $r['end'] ) ) {
			$e = $r['end'];
			WP_CLI::log( sprintf( '網址覆核（站上這個平台的全部網址，不限誰寫的）：查 %d 頁｜還在 %d｜不存在 %d｜滿 %d 輪移除 %d%s',
				$e['checked'], $e['alive'] ?? 0, $e['dead'] ?? 0, Anime_Sync_Streaming_Source_Base::GONE_STRIKES, $e['dead_removed'] ?? 0,
				$write ? '' : '（dry-run 只抽 ' . Anime_Sync_Streaming_Source_Base::END_DRY_SAMPLE . ' 頁、不寫入）' ) );
			if ( ( $e['dated'] ?? 0 ) > 0 || ( $e['unparsed'] ?? 0 ) > 0 ) {
				WP_CLI::log( sprintf( '  到期日：有日期 %d｜無日期 %d｜認不得 %d｜14 天內到期 %d｜發通知 %d｜續約 %d｜到期移除 %d',
					$e['dated'], $e['undated'], $e['unparsed'], $e['soon'], $e['notified'], $e['renewed'], $e['removed'] ) );
			}
			foreach ( $e['samples'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
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
