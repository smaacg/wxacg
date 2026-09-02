<?php
/**
 * 檔案名稱: includes/class-youranimes-season-index.php
 *
 * YourAnimes 季度新番表索引：匯入時自動解析出該作品的 YourAnimes 連結與台灣官方譯名。
 *
 * 解決什麼問題
 * ------------
 * 匯入帶回來的 anime_title_chinese 來源是 Bangumi 的 name_cn（大陸譯名）逐字簡轉繁
 * （見 class-api-handler.php get_core_anime_data()）。實測正式站 1,783 部已發布作品，
 * 其中 1,020 部（57.2%）的標題被人工改過，改的內容幾乎都是「大陸譯名 → 台灣官方譯名」：
 *
 *     自動「槍神 觀星之人」            → 人工「TRIGUN STARGAZE」
 *     自動「石紀元 科學與未來 第3部分」→ 人工「Dr.STONE 新石紀 SCIENCE FUTURE」
 *     自動「最強王者的第二人生 第二季」→ 人工「終末起點 第二季」
 *
 * 同一份資料還能解掉另一件人工作業：原本必須自己去 youranimes.tw 找到該作品的網址、
 * 貼進 anime_youranimes_url 才能同步台灣串流。
 *
 * 資料來源
 * --------
 * https://youranimes.tw/bangumi/{YYYYMM}（季度新番表，月份 01/04/07/10 對應冬春夏秋）
 * 該頁內嵌 JSON-LD 的 ItemList，一次請求就拿到整季（實測 2026 年 1 月 99 部）：
 *
 *     "name"              台灣官方譯名
 *     "alternateName"     日文原名（字串或陣列）
 *     "url"               /animes/{id}
 *     "sameAs"            官方網站、官方 X、Wikipedia
 *     "datePublished"     開播日
 *     "numberOfEpisodes"  集數
 *     "productionCompany" 製作公司
 *
 * 對方 robots.txt 對所有 UA 皆為 Allow。一季只抓一次，解析後的索引快取 7 天；
 * 不快取原始 HTML（該頁 3.2MB，存進 transient 不划算）。
 *
 * 比對策略
 * --------
 * 只做「完全相符」，兩層，命中即停；落空回傳 null（呼叫端維持現行行為）。
 *
 *   1. ja_native  日文原名完全相同（正規化後）
 *   2. latin      羅馬字／英文名對上 name 或 alternateName
 *                 （Fate/strange Fake、TRIGUN STARGAZE 這類中日文同名的作品靠這層）
 *
 * 曾經還有 date_eps（開播日+集數）與 prefix（前綴相符）兩層模糊比對，
 * 2026-09-02 實跑後移除——錯誤率 20~40%，而且配錯時連台灣串流都會從錯誤作品的
 * 頁面抓進來。詳細案例與判斷理由寫在 resolve() 尾端的註解。
 *
 * 正規化必須處理繁體與日文漢字的字形差異：站上的 anime_title_native 經過簡繁轉換，
 * 「違国日記」變成「違國日記」、「死滅回游」變成「死滅迴游」，不處理就配不上。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_YourAnimes_Season_Index {

	/**
	 * 解析後索引的快取前綴與存活時間。
	 *
	 * 前綴帶版本號：索引的欄位結構一旦調整（例如新增 tw_title_ok），
	 * 舊快取會缺欄位而讓判斷靜默走到 false，症狀是「功能好像沒生效」卻不報錯。
	 * 改結構時同時把版本號往上加，舊快取自然失效。
	 */
	const CACHE_PREFIX = 'asp_ya_season_v2_';
	const CACHE_TTL    = 7 * DAY_IN_SECONDS;

	/** 抓不到季度表時的短快取，避免每次匯入都重打同一個壞季度 */
	const MISS_TTL = 3 * HOUR_IN_SECONDS;

	/** AniList 季別 → YourAnimes 季度代碼的月份 */
	const SEASON_MONTH = [
		'WINTER' => '01',
		'SPRING' => '04',
		'SUMMER' => '07',
		'FALL'   => '10',
	];

	/**
	 * 繁體 → 日文新字體的常見字形對照。
	 *
	 * 站上的日文原名被簡繁轉換動過，這裡轉回日文字形才比對得上。
	 * 只收「日文確實使用該新字體」的字，不做通用簡繁轉換。
	 */
	const KANJI_VARIANTS = [
		'國' => '国', '迴' => '回', '學' => '学', '會' => '会', '來' => '来',
		'萬' => '万', '聲' => '声', '戀' => '恋', '實' => '実', '觀' => '観',
		'藝' => '芸', '醫' => '医', '齒' => '歯', '圖' => '図', '戰' => '戦',
		'驛' => '駅', '點' => '点', '當' => '当', '體' => '体', '歸' => '帰',
		'廣' => '広', '應' => '応', '營' => '営', '險' => '険', '傳' => '伝',
		'樂' => '楽', '數' => '数', '舊' => '旧', '關' => '関', '單' => '単',
		'發' => '発', '蟲' => '虫', '龍' => '竜', '靈' => '霊', '壽' => '寿',
		'戲' => '戯', '緣' => '縁', '總' => '総', '齡' => '齢', '寫' => '写',
		'畫' => '画', '證' => '証', '讀' => '読', '賣' => '売', '轉' => '転',
		'鐵' => '鉄', '雙' => '双', '髮' => '髪', '黨' => '党', '亂' => '乱',
	];

	// =====================================================================
	// PUBLIC – 主要進入點
	// =====================================================================

	/**
	 * 從匯入資料解析出對應的 YourAnimes 條目。
	 *
	 * @param array $anime_data get_core_anime_data() 的回傳結構
	 * @return array|null 命中時回傳條目（含 match_method），否則 null
	 */
	public static function resolve( array $anime_data ): ?array {

		if ( defined( 'ANIME_YOURANIMES_ENABLED' ) && ! ANIME_YOURANIMES_ENABLED ) {
			return null;
		}

		$season = strtoupper( trim( (string) ( $anime_data['anime_season'] ?? '' ) ) );
		$year   = (int) ( $anime_data['anime_season_year'] ?? 0 );

		if ( ! isset( self::SEASON_MONTH[ $season ] ) || $year < 1960 || $year > 2100 ) {
			return null;
		}

		$index = self::get_season_index( $year, $season );
		if ( empty( $index ) ) {
			return null;
		}

		$native  = (string) ( $anime_data['anime_title_native']  ?? '' );
		$romaji  = (string) ( $anime_data['anime_title_romaji']  ?? '' );
		$english = (string) ( $anime_data['anime_title_english'] ?? '' );

		// ── 第 1 層：日文原名 ──
		$by_alt = [];
		foreach ( $index as $row ) {
			foreach ( $row['alts'] as $alt ) {
				$key = self::normalize( $alt );
				if ( $key !== '' && ! isset( $by_alt[ $key ] ) ) {
					$by_alt[ $key ] = $row;
				}
			}
		}
		$hit = self::lookup( $by_alt, [ $native ] );
		if ( $hit ) {
			return $hit + [ 'match_method' => 'ja_native' ];
		}

		// ── 第 2 層：羅馬字／英文名 對 name 或 alternateName ──
		$by_any = $by_alt;
		foreach ( $index as $row ) {
			$key = self::normalize( $row['tw_title'] );
			if ( $key !== '' && ! isset( $by_any[ $key ] ) ) {
				$by_any[ $key ] = $row;
			}
		}
		$hit = self::lookup( $by_any, [ $romaji, $english ] );
		if ( $hit ) {
			return $hit + [ 'match_method' => 'latin' ];
		}

		/*
		 * 到這裡沒命中就放棄。
		 *
		 * ── 為什麼只留「完全相符」兩層 ──
		 *
		 * 曾經還有兩層模糊比對，2026-09-02 對 120 部草稿實跑後全數移除：
		 *
		 *   date_eps（開播日 + 集數）  寫入 6 筆，2 筆配到完全無關的作品
		 *       アニラとココラ                 → 娑婆氣
		 *       さわらないで小手指くんミニアニメ劇場 → 胖子與愛情以及過錯！
		 *     短篇動畫常常同日開播、集數又相同，唯一性檢查擋不住。
		 *
		 *   prefix（正規化後前綴相符且唯一）  寫入 10 筆，2～4 筆有問題
		 *       機械じかけのマリーミニアニメ   → 機械女僕‧瑪麗（本篇）
		 *       劇場版 Idol光之美少女…         → TV 本篇
		 *       ぷちきゅあ… シーズン3         → 本篇
		 *     衍生作品的標題本來就是「本篇 + 後綴」，前綴比對必然配到本篇。
		 *     試過用「衍生作品關鍵字必須兩邊一致」擋，但關鍵字常常只出現在
		 *     中文標題而不在日文原名（劇場版那筆就是），擋不乾淨，
		 *     反而誤傷了原本正確的案例。
		 *
		 * 錯誤的比對不只寫錯標題——連台灣串流都會從錯誤作品的頁面抓進來，
		 * 而那是使用者很難自己發現的。這兩層合計只貢獻約 14% 的命中，
		 * 用這個比例換取「配對必定正確」是划算的。
		 *
		 * 命中不了的維持原本的 Bangumi 譯名，與加這個功能之前完全一樣。
		 */
		return null;
	}

	/**
	 * 取得（並快取）某一季的解析後索引。
	 *
	 * @return array<int,array> 每筆含 ya_id / url / tw_title / alts / date / eps / studio / official_site / twitter
	 */
	public static function get_season_index( int $year, string $season, bool $bypass_cache = false ): array {

		$season = strtoupper( trim( $season ) );
		if ( ! isset( self::SEASON_MONTH[ $season ] ) ) {
			return [];
		}

		$code      = sprintf( '%04d%s', $year, self::SEASON_MONTH[ $season ] );
		$cache_key = self::CACHE_PREFIX . $code;

		if ( ! $bypass_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// 抓失敗時存的是空陣列以外的標記，避免每次匯入都重打
			if ( $cached === 'miss' ) {
				return [];
			}
		}

		$html = self::fetch_season_page( $code );
		if ( is_wp_error( $html ) ) {
			set_transient( $cache_key, 'miss', self::MISS_TTL );
			self::log_warning( sprintf( '季度表抓取失敗 %s：%s', $code, $html->get_error_message() ) );
			return [];
		}

		$index = self::parse_jsonld( $html );
		if ( empty( $index ) ) {
			set_transient( $cache_key, 'miss', self::MISS_TTL );
			self::log_warning( sprintf( '季度表解析不到 JSON-LD ItemList：%s', $code ) );
			return [];
		}

		set_transient( $cache_key, $index, self::CACHE_TTL );
		return $index;
	}

	// =====================================================================
	// PRIVATE – 取得與解析
	// =====================================================================

	/**
	 * 抓季度表 HTML。
	 *
	 * 熔斷器狀態與 Anime_Sync_YourAnimes_Fetcher 共用同一組 option/transient key
	 * ——打的是同一個站，失敗計數本來就該一起算。
	 */
	private static function fetch_season_page( string $code ) {

		if ( class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
			if ( get_transient( Anime_Sync_YourAnimes_Fetcher::CIRCUIT_OPEN_KEY ) ) {
				return new WP_Error( 'circuit_open', '熔斷中，暫停對 YourAnimes 的請求' );
			}
		}

		$url = 'https://youranimes.tw/bangumi/' . $code;

		/*
		 * 逾時設 15 秒，與 class-youranimes-fetcher.php 的 fetch_page() 一致。
		 * 匯入的 AJAX handler 是 set_time_limit(120)～(180)，這裡最壞情況會吃掉
		 * 其中 15 秒——但只發生在該季度第一次匯入（之後吃快取），
		 * 且抓失敗會存 3 小時的 miss 標記，不會每筆都重試。
		 */
		$response = wp_remote_get( $url, [
			'timeout'     => 15,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'headers'     => [
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'zh-TW,zh;q=0.9,en;q=0.8',
			],
		] );

		if ( is_wp_error( $response ) ) {
			self::record_failure();
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			self::record_failure();
			return new WP_Error( 'http_error', 'HTTP ' . $http_code );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( $body === '' ) {
			self::record_failure();
			return new WP_Error( 'empty_body', '回應內容為空' );
		}

		self::reset_failures();
		return $body;
	}

	/**
	 * 從頁面裡的 JSON-LD 取出 ItemList，轉成比對用的索引。
	 */
	private static function parse_jsonld( string $html ): array {

		if ( ! preg_match_all( '#<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>#su', $html, $m ) ) {
			return [];
		}

		$items = null;
		foreach ( $m[1] as $json ) {
			$decoded = json_decode( trim( $json ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['itemListElement'] ) ) {
				$items = $decoded['itemListElement'];
				break;
			}
		}
		if ( ! is_array( $items ) ) {
			return [];
		}

		$index = [];
		foreach ( $items as $element ) {
			$item = $element['item'] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = (string) ( $item['url'] ?? '' );
			if ( ! preg_match( '#/animes/(\d+)#', $url, $mm ) ) {
				continue;
			}

			// alternateName 可能是字串也可能是陣列（多個別名）
			$alts = [];
			foreach ( (array) ( $item['alternateName'] ?? [] ) as $alt ) {
				if ( is_string( $alt ) && trim( $alt ) !== '' ) {
					$alts[] = trim( $alt );
				}
			}

			// productionCompany 可能是物件也可能是物件陣列
			$company = $item['productionCompany'] ?? null;
			$studio  = '';
			if ( is_array( $company ) ) {
				$studio = (string) ( $company['name'] ?? ( $company[0]['name'] ?? '' ) );
			}

			$official = '';
			$twitter  = '';
			foreach ( (array) ( $item['sameAs'] ?? [] ) as $link ) {
				if ( ! is_string( $link ) ) {
					continue;
				}
				$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
				if ( $twitter === '' && ( str_contains( $host, 'twitter.com' ) || str_contains( $host, 'x.com' ) ) ) {
					$twitter = $link;
				} elseif ( $official === '' && ! str_contains( $host, 'wikipedia.org' )
					&& ! str_contains( $host, 'twitter.com' ) && ! str_contains( $host, 'x.com' ) ) {
					$official = $link;
				}
			}

			$tw_title = trim( (string) ( $item['name'] ?? '' ) );

			$index[] = [
				'ya_id'         => $mm[1],
				'url'           => 'https://youranimes.tw/animes/' . $mm[1],
				'tw_title'      => $tw_title,
				'tw_title_ok'   => self::is_usable_tw_title( $tw_title, $alts ),
				'alts'          => $alts,
				'date'          => substr( preg_replace( '/\D/', '', (string) ( $item['datePublished'] ?? '' ) ), 0, 8 ),
				'eps'           => (int) ( $item['numberOfEpisodes'] ?? 0 ),
				'studio'        => $studio,
				'official_site' => $official,
				'twitter'       => $twitter,
			];
		}

		return $index;
	}

	// =====================================================================
	// PRIVATE – 工具
	// =====================================================================

	/**
	 * 判斷 YourAnimes 的 name 是不是真的中文譯名，可以拿來覆蓋站上標題。
	 *
	 * 該站在作品「還沒有台灣譯名」時，name 會直接放日文原名，例如：
	 *     #5926 name =「最推しの義兄を愛でるため、長生きします！」
	 * 站上原本已經有「為了我最愛的義兄，我要長命百歲！」這種堪用的中文標題，
	 * 直接覆蓋會變成退步。
	 *
	 * 兩道判斷：
	 *   1. 含有真正的假名字母就退回。注意必須排除「・」(U+30FB) 與「ー」(U+30FC)
	 *      ——它們雖然落在片假名區塊，卻是中文標題常用的標點，站上實測有
	 *      「香格里拉・開拓異境」「安妮・雪莉」「ーBONUS STAGEー」這些用法，
	 *      一併擋掉會誤傷。
	 *   2. name 與日文原名相同（正規化後）代表根本沒翻譯，也退回。
	 *
	 * 判斷不過只是「不覆蓋標題」，YourAnimes 連結照樣寫入——那個沒有爭議。
	 */
	private static function is_usable_tw_title( string $tw_title, array $alts ): bool {

		if ( trim( $tw_title ) === '' ) {
			return false;
		}

		// 平假名 U+3040–309F、片假名字母 U+30A1–30FA 與 U+30FD–30FF
		//（刻意跳過 U+30FB「・」與 U+30FC「ー」）
		if ( preg_match( '/[\x{3040}-\x{309F}\x{30A1}-\x{30FA}\x{30FD}-\x{30FF}]/u', $tw_title ) ) {
			return false;
		}

		$normalized = self::normalize( $tw_title );
		foreach ( $alts as $alt ) {
			if ( $normalized !== '' && $normalized === self::normalize( $alt ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 在索引表裡依序找第一個命中的鍵。
	 */
	private static function lookup( array $map, array $candidates ): ?array {
		foreach ( $candidates as $candidate ) {
			$key = self::normalize( (string) $candidate );
			if ( $key !== '' && isset( $map[ $key ] ) ) {
				return $map[ $key ];
			}
		}
		return null;
	}

	/**
	 * 標題正規化：全半形、字形、標點、空白、季別寫法一律拉平。
	 */
	private static function normalize( string $s ): string {
		if ( $s === '' ) {
			return '';
		}

		// 全形英數與片假名寫法統一
		if ( function_exists( 'mb_convert_kana' ) ) {
			$s = mb_convert_kana( $s, 'asKV', 'UTF-8' );
		}

		// 繁體字形 → 日文新字體（站上的日文原名被簡繁轉換動過）
		$s = strtr( $s, self::KANJI_VARIANTS );

		/*
		 * 季別寫法一律拉成同一個標記：第2期 / 2期 / シーズン2 / Season 2 / 2nd Season。
		 *
		 * 「シーズン」那一項是後來補的：少了它，「ぷちきゅあ〜Precure Fairies〜
		 * シーズン3」正規化後仍保留季別字樣，前綴比對會把它配到本篇。
		 */
		foreach ( [ 2, 3, 4, 5, 6 ] as $n ) {
			$s = preg_replace(
				'/(第' . $n . '[期季]|' . $n . '期|シーズン\s*' . $n . '|' . $n . '(?:nd|rd|th)\s*season|season\s*' . $n . ')/iu',
				'@S' . $n,
				$s
			);
		}

		$s = preg_replace( '/[\s\x{3000}]+/u', '', $s );
		$s = preg_replace( '/[!！?？~〜～・:：,，.。\-—–_「」『』【】\[\]()（）"“”\'’&＆\/]/u', '', $s );

		return mb_strtolower( (string) $s, 'UTF-8' );
	}

	/*
	 * 失敗計數必須用 transient，不能用 option。
	 *
	 * class-youranimes-fetcher.php 的 record_failure() 是
	 * get_transient() / set_transient( ..., 30 分鐘 )。這裡若改用 option，
	 * 兩邊會各記各的，宣稱的「熔斷器狀態共用」就不成立——本類別的失敗
	 * 永遠累積不到門檻，也不會觸發熔斷，還會多留一筆用不到的 option。
	 */
	private static function record_failure(): void {
		if ( ! class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
			return;
		}

		$count = (int) get_transient( Anime_Sync_YourAnimes_Fetcher::FAIL_COUNT_KEY ) + 1;

		if ( $count >= Anime_Sync_YourAnimes_Fetcher::FAIL_THRESHOLD ) {
			set_transient(
				Anime_Sync_YourAnimes_Fetcher::CIRCUIT_OPEN_KEY,
				1,
				Anime_Sync_YourAnimes_Fetcher::CIRCUIT_OPEN_TTL
			);
			delete_transient( Anime_Sync_YourAnimes_Fetcher::FAIL_COUNT_KEY );
			self::log_warning( sprintf(
				'連續 %d 次失敗，已熔斷 %d 分鐘',
				Anime_Sync_YourAnimes_Fetcher::FAIL_THRESHOLD,
				Anime_Sync_YourAnimes_Fetcher::CIRCUIT_OPEN_TTL / MINUTE_IN_SECONDS
			) );
		} else {
			set_transient( Anime_Sync_YourAnimes_Fetcher::FAIL_COUNT_KEY, $count, 30 * MINUTE_IN_SECONDS );
		}
	}

	private static function reset_failures(): void {
		if ( ! class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
			return;
		}
		delete_transient( Anime_Sync_YourAnimes_Fetcher::FAIL_COUNT_KEY );
	}

	private static function log_warning( string $message ): void {
		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::warning( '[YourAnimes 季度表] ' . $message );
		}
	}
}
