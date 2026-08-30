<?php
/**
 * Bangumi 主題資料代理 — 給前台彈窗用。
 *
 * 前台點一張專輯時要顯示封面、藝術家、發售日等資料。這些不在
 * wp_wxacg_subject_relations 裡（那張表只存名稱與關聯類型，
 * Bangumi Archive 的 dump 本身也不含圖片），得向 API 取。
 *
 * 為什麼要走後端代理而不是瀏覽器直連 api.bgm.tv：
 *
 *   1. CORS——對方沒開放跨來源，瀏覽器 fetch 會被擋。
 *   2. 快取——同一張專輯被不同使用者點開很常見，代理這層存 7 天，
 *      不必每次都打對方 API。
 *   3. 節制——把請求集中在自己的伺服器，才控制得住頻率。
 *
 * 只做「按需求取一筆」，不預抓 5,849 張：使用者實際會點開的是少數，
 * 全部預抓要 1.5~2 小時而且大半用不到。
 *
 * Changelog:
 *   1.0.0 (2026-08-29) — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Bgm_Subject_Proxy {

	const ROUTE_NS   = 'anime-sync/v1';
	const ROUTE_PATH = '/bgm-subject/(?P<id>\d+)';

	const CACHE_TTL = 7 * DAY_IN_SECONDS;
	const CACHE_VER = 'v4';

	/* 失敗也要快取一小段，否則壞掉的 id 會被反覆重打 */
	const FAIL_TTL = 6 * HOUR_IN_SECONDS;

	const API_BASE = 'https://api.bgm.tv/v0/subjects/';

	/*
	 * infobox 只挑對使用者有意義的，其餘不輸出。
	 *
	 * 三種條目型別的 key 完全不重疊——音樂是艺术家/作曲/厂牌，遊戲是
	 * 平台/开发/发行，三次元是上映日/导演/主演。同一張表放三組，
	 * 對不到的 key 本來就不會輸出，所以不必依型別分表。
	 *
	 * 三次元實測有 38 個欄位（連照明、裝飾、錄音都有），這裡只收 8 個：
	 * 彈窗是「這是什麼東西」的快速一瞥，不是劇組名單。
	 */
	const INFO_KEYS = [
		/* 音樂 */
		'艺术家'   => '藝術家',
		'作曲'     => '作曲',
		'编曲'     => '編曲',
		'作词'     => '作詞',
		'厂牌'     => '廠牌',
		'发售日期' => '發售日',
		'价格'     => '價格',
		'碟片数'   => '碟片數',
		'播放格式' => '格式',

		/* 遊戲 */
		'平台'     => '平台',
		'游戏类型' => '類型',
		'发行日期' => '發行日',
		'售价'     => '售價',
		'开发'     => '開發',
		'发行'     => '發行',
		'游玩人数' => '遊玩人數',

		/* 三次元（真人版／電影／舞台劇） */
		'上映日'    => '上映日',
		'片长'      => '片長',
		'类型'      => '類型',
		'国家/地区' => '國家／地區',
		'导演'      => '導演',
		'编剧'      => '編劇',
		'主演'      => '主演',
		'制作公司'  => '製作公司',
	];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	public static function register_route() {
		register_rest_route(
			self::ROUTE_NS,
			self::ROUTE_PATH,
			[
				'methods'  => 'GET',
				'callback' => [ __CLASS__, 'handle' ],
				/* 公開資料，任何人都能讀；沒有寫入行為 */
				'permission_callback' => '__return_true',
				'args'     => [
					'id' => [
						'validate_callback' => static function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0;
						},
					],
				],
			]
		);
	}

	public static function handle( $request ) {
		$id = (int) $request['id'];

		if ( $id <= 0 ) {
			return new WP_Error( 'asp_bad_id', '無效的 ID', [ 'status' => 400 ] );
		}

		$key    = 'asp_bgmsub_' . self::CACHE_VER . '_' . $id;
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			/* 失敗紀錄也存在快取裡，要分得出來 */
			if ( ! empty( $cached['__failed'] ) ) {
				return new WP_Error(
					'asp_bgm_unavailable',
					'目前取不到這個條目的資料',
					[ 'status' => 502 ]
				);
			}

			return rest_ensure_response( $cached );
		}

		$res = wp_remote_get(
			self::API_BASE . $id,
			[
				'timeout' => 12,
				'headers' => [
					/* Bangumi 要求標明來源,不帶會被擋 */
					'User-Agent' => 'weixiaoacg/1.0 (+https://weixiaoacg.com)',
					'Accept'     => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			/* 不吞錯：記下來,前台也收到明確的失敗 */
			error_log( '[anime-sync-pro] bgm-subject 取得失敗 id=' . $id . ': ' . $res->get_error_message() );
			set_transient( $key, [ '__failed' => 1 ], self::FAIL_TTL );

			return new WP_Error( 'asp_bgm_http', '連線失敗', [ 'status' => 502 ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( 200 !== $code ) {
			error_log( '[anime-sync-pro] bgm-subject HTTP ' . $code . ' id=' . $id );
			set_transient( $key, [ '__failed' => 1 ], self::FAIL_TTL );

			return new WP_Error(
				'asp_bgm_status',
				'來源回應 ' . $code,
				[ 'status' => 404 === $code ? 404 : 502 ]
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $data ) ) {
			set_transient( $key, [ '__failed' => 1 ], self::FAIL_TTL );

			return new WP_Error( 'asp_bgm_parse', '來源資料格式不符', [ 'status' => 502 ] );
		}

		$out = self::shape( $data, $id );

		set_transient( $key, $out, self::CACHE_TTL );

		return rest_ensure_response( $out );
	}

	/**
	 * 簡體轉繁體，但避開日文。
	 *
	 * infobox 的值跟 name_cn／summary 不一樣：後兩者按定義就是簡體中文，
	 * 直接轉沒問題；infobox 的值卻是混語言的——遊戲的「開發／發行」大量
	 * 是日本公司名。轉換器分不出日文和簡體，會把
	 * 「アソビモ株式会社」轉成「アソビモ株式會社」，日文的 会社 被改成
	 * 中文的 會社，那是錯的。
	 *
	 * 判別方式：含假名（平假名／片假名）就是日文，整段不轉。
	 *
	 * 已知限制——純漢字的日文名（例如 東宝）仍會被轉成 東寶。要根治得做
	 * 語言判定，成本遠高於效益；這個啟發式擋掉了絕大多數實際案例，
	 * 因為日本公司名多半帶片假名。
	 */
	private static function to_traditional( string $s ): string {
		if ( '' === $s || ! class_exists( 'Anime_Sync_CN_Converter' ) ) {
			return $s;
		}

		/* \x{3040}-\x{309F} 平假名，\x{30A0}-\x{30FF} 片假名 */
		if ( preg_match( '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $s ) ) {
			return $s;
		}

		return Anime_Sync_CN_Converter::static_convert( $s );
	}

	/**
	 * 把日期正規化成 Y-m-d，用來比對兩個欄位是不是同一天。
	 *
	 * 不能只是「去掉非數字再比」——Bangumi 兩種寫法的補零方式不一樣：
	 *   2024-04-18   → 去掉非數字得 20240418
	 *   2024年4月18日 → 去掉非數字得 2024418
	 * 兩者其實是同一天，卻比不出相等，日期就會在彈窗裡出現兩次
	 *（實測遊戲條目 星之翼 同時有「發售日 2024-04-18」與
	 *  「發行日 2024年4月18日」）。取前三組數字再補零才對得起來。
	 *
	 * @return string 對不出日期就回空字串（呼叫端要當成「不比對」處理，
	 *                不能讓兩個空字串互相判定為重複）。
	 */
	private static function date_key( string $s ): string {
		if ( ! preg_match_all( '/\d+/', $s, $m ) || count( $m[0] ) < 3 ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', (int) $m[0][0], (int) $m[0][1], (int) $m[0][2] );
	}

	/**
	 * 只回前台會用到的欄位，不把整包原始資料丟出去。
	 */
	private static function shape( array $d, int $id ): array {
		$cover = '';

		foreach ( [ 'common', 'medium', 'large', 'small' ] as $k ) {
			if ( ! empty( $d['images'][ $k ] ) ) {
				$cover = (string) $d['images'][ $k ];
				break;
			}
		}

		$info = [];

		if ( ! empty( $d['infobox'] ) && is_array( $d['infobox'] ) ) {
			foreach ( $d['infobox'] as $row ) {
				$k = (string) ( $row['key'] ?? '' );

				if ( ! isset( self::INFO_KEYS[ $k ] ) ) {
					continue;
				}

				$v = $row['value'] ?? '';

				/* 別名那類是陣列,取值串起來 */
				if ( is_array( $v ) ) {
					$parts = [];

					foreach ( $v as $item ) {
						$parts[] = is_array( $item )
							? (string) ( $item['v'] ?? '' )
							: (string) $item;
					}

					$v = implode( '、', array_filter( $parts ) );
				}

				$v = trim( (string) $v );

				if ( '' === $v ) {
					continue;
				}

				$v = self::to_traditional( $v );

				$info[] = [
					'key'   => self::INFO_KEYS[ $k ],
					'value' => $v,
				];
			}
		}

		$name_cn = trim( (string) ( $d['name_cn'] ?? '' ) );
		$name    = trim( (string) ( $d['name'] ?? '' ) );

		/* 站上是繁體，Bangumi 的中文名是簡體 */
		if ( '' !== $name_cn && class_exists( 'Anime_Sync_CN_Converter' ) ) {
			$name_cn = Anime_Sync_CN_Converter::static_convert( $name_cn );
		}

		$summary = trim( (string) ( $d['summary'] ?? '' ) );

		if ( '' !== $summary && class_exists( 'Anime_Sync_CN_Converter' ) ) {
			$summary = Anime_Sync_CN_Converter::static_convert( $summary );
		}

		/*
		 * 日期不要出現兩次。
		 *
		 * 頂層 date 與 infobox 的「發售日／發行日／上映日」是同一個日子，
		 * 兩邊都輸出的話彈窗會連續印兩行一模一樣的東西。
		 *
		 * 留 infobox 那個、拿掉頂層 date：infobox 的標籤有講清楚是哪一種
		 * 日期（專輯是發售日、遊戲是發行日、電影是上映日），頂層 date
		 * 只是個沒有名字的日期。
		 *
		 * 只在「真的重複」時才拿掉——infobox 沒有日期欄位的條目仍然需要
		 * 頂層 date，否則反而少一項資訊。
		 */
		$date       = trim( (string) ( $d['date'] ?? '' ) );
		$date_dupes = false;
		$date_key   = self::date_key( $date );

		if ( '' !== $date_key ) {
			foreach ( $info as $row ) {
				if ( self::date_key( $row['value'] ) === $date_key ) {
					$date_dupes = true;
					break;
				}
			}
		}

		return [
			'id'      => $id,
			'title'   => '' !== $name_cn ? $name_cn : $name,
			'sub'     => ( '' !== $name_cn && $name_cn !== $name ) ? $name : '',
			'cover'   => $cover,
			'date'    => $date_dupes ? '' : $date,
			'summary' => $summary,
			'info'    => $info,
			'source'  => 'https://bgm.tv/subject/' . $id,
		];
	}
}
