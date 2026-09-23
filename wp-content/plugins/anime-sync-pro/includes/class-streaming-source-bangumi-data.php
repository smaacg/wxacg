<?php
/**
 * 檔案名稱: includes/class-streaming-source-bangumi-data.php
 * bangumi-data 開源資料集：用 Bangumi／AniList ID 對應各串流站，不比標題
 *
 * 為什麼
 * ------
 * 標題精確比對的召回率天花板約 74%（譯名差一個字就配不上）。
 * https://github.com/bangumi-data/bangumi-data 是社群維護的開源資料集，每部動畫列出
 * 各站點的 ID，站點包含台灣這幾家：gamer（動畫瘋）、muse_tw、ani_one、ani_one_asia、
 * tropics（回歸線）、mighty（曼迪）、bilibili_tw（netflix 是全球站點、不標地區，不能用）。每筆同時帶 bangumi 與 aniList ID，
 * 站上 2,032 部已發布作品幾乎全有 anime_bangumi_id 與 anime_anilist_id ——
 * **用 ID 對應，零譯名問題**。
 *
 * 2026-09-15 實測：整包 7.6MB（dist/data.json，jsDelivr 主機可抓、200），8,829 部，
 * 有 bangumi id 且含 gamer 的 1,729 部、muse_tw 445、ani_one 356＋asia 423、netflix 700、
 * bilibili_tw 85、tropics 61、mighty 27。解析峰值 74MB。
 *
 * 怎麼接（不另起一套機制）
 * ------------------------
 * 索引裡的鍵除了正規化後的標題，多放 `bgm:{id}` 與 `al:{id}`；基底 match_titles() 也帶上
 * 站上的兩個 ID；lookup() 先用 ID 鍵、有命中就以 ID 為準。寫入、來源標記、下架偵測、
 * 後台頁全部沿用。巴哈與 YouTube 頻道在既有收集之後 merge 進來（互補）；
 * Bilibili 台灣是只吃 bangumi-data 的來源（本檔下方的小類別）。
 *
 * 資料集一週更新一次，這裡快取 6 天；所有來源共用同一份快取，一週只下載一次。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Bangumi_Data_Feed {

	const URL       = 'https://cdn.jsdelivr.net/npm/bangumi-data@latest/dist/data.json';
	const CACHE_TTL = 6 * DAY_IN_SECONDS;
	const CIRCUIT   = 'asp_bgmdata_circuit';

	/**
	 * 只保留這些站點，快取檔才小（不到 1MB）。全部是 siteMeta 標了 TW 的站點；
	 * netflix 沒有 regions（全球）所以不在這裡，見檔尾說明。
	 *
	 * bangumi-data 的 37 個站點裡有 8 個涵蓋台灣，這裡全部收齊。
	 * `bilibili_hk_mo_tw`（港澳台，376 部）是 2026-09-16 補上的——先前只接了
	 * `bilibili_tw`（85 部）。佐證：站上「只有港澳台站點」的 146 部作品裡，
	 * 有 137 部 YourAnimes 早就標了 Bilibili，可見這個站點對台灣有效。
	 */
	const SITES = [ 'gamer', 'muse_tw', 'ani_one', 'ani_one_asia', 'tropics', 'mighty', 'bilibili_tw', 'bilibili_hk_mo_tw' ];

	/** @var array<string,array<string,string>>|null  'bgm:123' / 'al:456' → [ site => id ] */
	private static ?array $map = null;

	private static function cache_path(): string {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'anime-sync-pro/bangumi-data-map.json';
	}

	/**
	 * 取得對照表；快取過期或不存在才下載。失敗時回舊快取（有就用），沒有才回空。
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function map( bool $force = false ): array {

		if ( self::$map !== null && ! $force ) {
			return self::$map;
		}

		$path  = self::cache_path();
		$fresh = file_exists( $path ) && ( time() - (int) filemtime( $path ) ) < self::CACHE_TTL;

		if ( $fresh && ! $force ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			return self::$map = ( is_array( $data ) ? $data : [] );
		}

		$built = self::download_and_build();

		if ( is_array( $built ) ) {
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, (string) wp_json_encode( $built ) );
			return self::$map = $built;
		}

		// 下載失敗：舊快取有就先用，別讓整輪比對因為 CDN 抖一下就全空
		if ( file_exists( $path ) ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			return self::$map = ( is_array( $data ) ? $data : [] );
		}

		return self::$map = [];
	}

	/** @return array<string,array<string,string>>|WP_Error */
	private static function download_and_build() {

		if ( get_transient( self::CIRCUIT ) ) {
			return new WP_Error( 'circuit_open', 'bangumi-data 熔斷中' );
		}

		if ( class_exists( 'Anime_Sync_Performance' ) ) {
			// 7.6MB JSON 解碼峰值 74MB；只在現有上限低於 256M 時拉高（CLI 是 -1 不要動它）
			$cur = (string) ini_get( 'memory_limit' );
			if ( $cur !== '-1' && wp_convert_hr_to_bytes( $cur ) < 256 * MB_IN_BYTES ) {
				Anime_Sync_Performance::increase_memory_limit( '256M' );
			}
		}

		$res = wp_remote_get( self::URL, [ 'timeout' => 90, 'redirection' => 3, 'user-agent' => 'anime-sync-pro/1.0 (+https://weixiaoacg.com)' ] );

		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			set_transient( self::CIRCUIT, 1, HOUR_IN_SECONDS );
			self::log( 'bangumi-data 下載失敗：' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
			return new WP_Error( 'download_failed', 'bangumi-data 下載失敗' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		unset( $res );

		if ( ! is_array( $data ) || empty( $data['items'] ) ) {
			return new WP_Error( 'bad_json', 'bangumi-data 格式不對' );
		}

		$map = [];
		foreach ( (array) $data['items'] as $item ) {
			$sites = [];
			$bgm   = '';
			$al    = '';
			foreach ( (array) ( $item['sites'] ?? [] ) as $s ) {
				$site = (string) ( $s['site'] ?? '' );
				$id   = trim( (string) ( $s['id'] ?? '' ) );
				if ( $id === '' ) {
					continue;
				}
				if ( $site === 'bangumi' ) {
					$bgm = $id;
				} elseif ( $site === 'aniList' ) {
					$al = $id;
				} elseif ( in_array( $site, self::SITES, true ) ) {
					$sites[ $site ] = $id;
				}
			}
			if ( empty( $sites ) ) {
				continue;
			}
			if ( $bgm !== '' ) {
				$map[ 'bgm:' . $bgm ] = $sites;
			}
			if ( $al !== '' ) {
				$map[ 'al:' . $al ] = $sites;
			}
		}

		self::log( sprintf( 'bangumi-data 更新：%d 部、對照鍵 %d', count( $data['items'] ), count( $map ) ), 'info' );

		return $map;
	}

	/**
	 * 後台頁用：快取檔狀態。
	 *
	 * @return array{exists:bool,updated:int,keys:int,sites:array<string,int>}
	 */
	public static function cache_info(): array {
		$path = self::cache_path();
		$info = [ 'exists' => file_exists( $path ), 'updated' => 0, 'keys' => 0, 'sites' => [] ];
		if ( ! $info['exists'] ) {
			return $info;
		}
		$info['updated'] = (int) filemtime( $path );
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return $info;
		}
		$info['keys'] = count( $data );
		foreach ( $data as $k => $sites ) {
			if ( strpos( (string) $k, 'bgm:' ) !== 0 ) {
				continue;   // 每部作品有 bgm: 與 al: 兩個鍵，站點數只算一次
			}
			foreach ( (array) $sites as $site => $id ) {
				$info['sites'][ $site ] = ( $info['sites'][ $site ] ?? 0 ) + 1;
			}
		}
		return $info;
	}

	/** 站上一篇作品的 ID 鍵（給 match_titles 與 merge 用）。 */
	public static function post_id_keys( int $post_id ): array {
		$keys = [];
		$bgm  = (int) get_post_meta( $post_id, 'anime_bangumi_id', true );
		$al   = (int) get_post_meta( $post_id, 'anime_anilist_id', true );
		if ( $bgm > 0 ) {
			$keys[] = 'bgm:' . $bgm;
		}
		if ( $al > 0 ) {
			$keys[] = 'al:' . $al;
		}
		return $keys;
	}

	private static function log( string $m, string $level = 'warning' ): void {
		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::static_log( $level, '串流來源[bangumi-data]：' . $m );
		}
	}
}

/**
 * 只吃 bangumi-data 的來源（沒有自己的 sitemap／API）。子類別給站點 key 與網址樣板。
 */
abstract class Anime_Sync_Streaming_Source_Bangumi_Only extends Anime_Sync_Streaming_Source_Base {

	/** @return string[] bangumi-data 站點 key，可多個（例如 ani_one 與 ani_one_asia） */
	abstract protected function bangumi_sites(): array;

	abstract protected function url_for( string $site, string $id ): string;

	/**
	 * 索引不是平台的完整快照，所以不跑下架偵測。
	 *
	 * 這裡的「索引」是 bangumi-data 的 ID 對照表——只涵蓋那個開源資料集有對照的作品，
	 * 從來就不是「這個平台現在有什麼」。拿它反推下架，等於用一份不完整的清單刪正確的資料。
	 * 2026-09-23 量到 bilibili 寫過的 75 筆有 42 筆（56%）網址不在索引裡，
	 * 它們沒被判下架純粹是標題剛好配得到，不是機制擋住的。
	 *
	 * 代價：這類來源真的下架時不會自動偵測到。bilibili 存的是搜尋頁連結，
	 * 本來就沒有「這個網址失效」可言，偵測下架沒有意義。
	 */
	protected function index_covers_platform(): bool {
		return false;
	}

	protected function sitemap_url(): string {
		return '';
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	protected function work_name( string $title ): string {
		return trim( $title );
	}

	protected function collect_entries( float $started ) {
		$grouped = [];
		$n       = $this->merge_bangumi_data( $grouped, $this->bangumi_sites(), [ $this, 'url_for' ] );
		if ( $n === 0 && empty( Anime_Sync_Bangumi_Data_Feed::map() ) ) {
			return new WP_Error( 'no_feed', 'bangumi-data 對照表是空的（下載失敗且無舊快取）' );
		}
		return [ $grouped, $n ];
	}
}

/*
 * ⚠ 這裡曾有 Netflix 來源，2026-09-15 當天撤掉：bangumi-data 的 netflix 站點 siteMeta **沒有 regions**
 * （全球），其他台灣站點都標了 TW／HK-TW-MO。用它寫了 81 部，拿 TMDB 台灣資料反查：確認台灣有 20、
 * 台灣沒有 28（在台灣是 CatchPlay／LINE TV）。已靠來源標記整批撤回。Netflix 維持 AniList 匯入＋YA。
 * 要再接任何 bangumi-data 站點前，先看 siteMeta[站點]['regions'] 有沒有 TW。
 */

/**
 * Bilibili：bangumi-data 的 bilibili_tw（台灣）與 bilibili_hk_mo_tw（港澳台），
 * 網址都是 https://www.bilibili.com/bangumi/media/md{id}/。台灣專屬的排前面、優先採用。
 */
class Anime_Sync_Streaming_Source_Bilibili extends Anime_Sync_Streaming_Source_Bangumi_Only {
	public function key(): string { return 'bilibili'; }
	protected function bangumi_sites(): array { return [ 'bilibili_tw', 'bilibili_hk_mo_tw' ]; }
	protected function url_for( string $site, string $id ): string { return 'https://www.bilibili.com/bangumi/media/md' . rawurlencode( $id ) . '/'; }
}
