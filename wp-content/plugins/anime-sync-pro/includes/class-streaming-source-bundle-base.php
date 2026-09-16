<?php
/**
 * 檔案名稱: includes/class-streaming-source-bundle-base.php
 * 「本機索引包」型來源的共用基底
 *
 * 為什麼有這一層
 * --------------
 * 巴哈、車庫 AniPASS、CatchPlay 三家有同一個限制：**正式站主機抓不到，台灣住宅 IP 可以**
 *   - 巴哈：Cloudflare 對機房 IP 一律人機驗證（403）
 *   - 車庫：WAF 擋機房 IP（403）
 *   - CatchPlay：CloudFront 依地區把 /tw/video/ 轉回首頁（302）
 * 所以「抓」這一步搬到使用者的 Windows 電腦（tools/build-bahamut-bundle.php，每週日排程），
 * 產出 data/source_{key}_bundle.json 隨 git push 部署，主機端只讀檔。
 *
 * 這三個子類別原本各自重複同一段「讀檔→解析→組 grouped」的程式碼（連錯誤訊息都一樣），
 * 且後台的「索引包過期」告警只寫在巴哈那一列。抽到這裡之後：
 *   - 讀檔與錯誤處理只有一份
 *   - bundle_info() 讓後台對三家一視同仁地顯示建包日期與過期告警
 *   - 新增同型平台只要宣告 BUNDLE_FILE 與 work_name()
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Anime_Sync_Streaming_Source_Bundle_Base extends Anime_Sync_Streaming_Source_Base {

	/** 超過這個天數沒更新就在後台標紅：本機排程每週一次，給兩輪的寬限。 */
	const BUNDLE_STALE_DAYS = 10;

	/** 子類別必須宣告 `const BUNDLE_FILE = 'data/source_xxx_bundle.json';` */

	/** 索引包在 repo 裡的絕對路徑。 */
	public static function bundle_path(): string {
		$dir = defined( 'ANIME_SYNC_PRO_DIR' ) ? ANIME_SYNC_PRO_DIR : dirname( __DIR__ ) . '/';
		return $dir . static::BUNDLE_FILE;
	}

	/** 記憶體中的索引包內容，避免同一輪重複讀檔與解析。 */
	private ?array $bundle_cache = null;

	/**
	 * 索引包的原始 JSON。除了 works，子類別可能還要讀自己的額外欄位
	 * （巴哈的 acg 對照表就是），所以開放取用而不是只回 works。
	 *
	 * @return array|null null＝檔案不存在或不是 JSON
	 */
	protected function bundle_raw(): ?array {

		if ( $this->bundle_cache !== null ) {
			return $this->bundle_cache;
		}

		$path = static::bundle_path();
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $path ), true );

		return $this->bundle_cache = ( is_array( $data ) ? $data : null );
	}

	/** 索引包來源沒有自己的 sitemap；收集一律走索引包。 */
	protected function sitemap_url(): string {
		return '';
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/**
	 * 讀索引包並組成 grouped。子類別要在讀完後再加工（例如巴哈合併 bangumi-data）時，
	 * 覆寫 collect_entries() 並呼叫這個方法拿基礎資料。
	 *
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function load_bundle() {

		$data = $this->bundle_raw();

		if ( $data === null ) {
			return new WP_Error( 'no_bundle', sprintf(
				'找不到%s索引包 %s，請在台灣 IP 執行 tools/build-bahamut-bundle.php 後部署',
				$this->label(),
				static::BUNDLE_FILE
			) );
		}

		if ( empty( $data['works'] ) || ! is_array( $data['works'] ) ) {
			return new WP_Error( 'bad_bundle', $this->label() . '索引包格式不對或沒有作品' );
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
	 * 預設：直接用索引包。需要在本機建包時改抓網路的子類別（巴哈、車庫）自行覆寫。
	 *
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {
		return $this->load_bundle();
	}

	/**
	 * 走基底原本的 sitemap 收集流程（含 sitemapindex 展開、parse_entry、時間預算）。
	 *
	 * ★ 為什麼需要這個方法：巴哈在本機建包時要「真的去抓 sitemap」，原本寫成
	 *   `parent::collect_entries()`。插進 Bundle_Base 這一層之後，parent 變成
	 *   「讀索引包」——建包會讀到上一份自己的產物、永遠不更新。PHP 沒有 grandparent
	 *   語法，所以在這裡明確開一個入口，子類別呼叫 $this->collect_from_sitemap()。
	 *
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_from_sitemap( float $started ) {
		return parent::collect_entries( $started );
	}

	/**
	 * 後台用：索引包的建包日期與規模。
	 *
	 * @return array{exists:bool,mtime:int,works:int,extra:string,stale:bool,file:string}
	 */
	public function bundle_info(): array {

		$path = static::bundle_path();
		$info = [ 'exists' => false, 'mtime' => 0, 'works' => 0, 'extra' => '', 'stale' => true, 'file' => static::BUNDLE_FILE ];

		if ( ! is_readable( $path ) ) {
			return $info;
		}

		$info['exists'] = true;
		$info['mtime']  = (int) filemtime( $path );
		$info['stale']  = ( time() - $info['mtime'] ) > self::BUNDLE_STALE_DAYS * DAY_IN_SECONDS;

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( is_array( $data ) ) {
			$info['works'] = is_array( $data['works'] ?? null ) ? count( $data['works'] ) : 0;
			// 巴哈另有 ACG 編號 → 動畫瘋 sn 的對照表，順便讓後台看得到
			if ( is_array( $data['acg'] ?? null ) ) {
				$info['extra'] = sprintf( 'ACG 對照 %s 筆', number_format_i18n( count( $data['acg'] ) ) );
			}
		}

		return $info;
	}
}
