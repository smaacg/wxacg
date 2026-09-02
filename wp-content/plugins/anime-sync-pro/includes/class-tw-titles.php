<?php
/**
 * 檔案名稱: includes/class-tw-titles.php
 *
 * 把顯示文字裡的大陸譯名換成站上的台灣譯名。
 *
 * 解決什麼問題
 * ------------
 * Bangumi 的中文欄位（name_cn、人物與角色簡介）用的是大陸譯名。站上的作品標題
 * 已經是台灣官方譯名，但那些引用作品名的地方仍然是大陸譯名，例如：
 *
 *   相關專輯／遊戲區塊   name_cn =「間諜過家家」   站上標題是「SPY×FAMILY 間諜家家酒」
 *   A-1 Pictures 人物頁   簡介裡寫《千與千尋》     站上標題是「神隱少女」
 *
 * 為什麼不直接改資料
 * ------------------
 * name_cn 與集數資料每次同步都會被 Bangumi 蓋回去（relcover 每 7 天、集數同步更頻繁），
 * 改了等於白做。所以改在顯示層：資料保持 Bangumi 原樣，只有輸出時換成台灣譯名，
 * 重新同步不會壞，不喜歡也隨時可以移除。
 *
 * 對照表怎麼來
 * ------------
 * 從站上自己的資料即時建立：anime_title_simplified（Bangumi 的大陸譯名，刻意保留
 * 未轉換，供大陸使用者搜尋）簡轉繁之後，對上該作品的 post_title（台灣譯名）。
 * 因此你改了站上標題，對照表下次重建就會跟上，不需要另外維護一份清單。
 *
 * 四道過濾，寧可漏也不要錯
 * ------------------------
 *   1. 兩者相同 → 不收（沒有替換的必要）
 *   2. 一個大陸譯名對到多個台灣譯名 → 不收（無法判斷該用哪個）
 *   3. 台灣譯名 = 大陸譯名 + 季別／版本字樣 → 不收
 *      站上那筆剛好是續篇時會產生「Fate/Zero → Fate/Zero 第二季」這種錯誤對應，
 *      散文裡講的其實是整個系列。但「排球少年 → 排球少年!!」這種只差標點的要保留，
 *      所以是比對後綴長什麼樣，不是單純看有沒有前綴關係。
 *   4. 太短的不收，避免與一般詞彙碰撞
 *
 * 比對一律「完整相等」，不做子字串
 * --------------------------------
 *   整串模式：整個值就是一個作品名（name_cn、集數 name_cn）
 *   書名號模式：散文裡的《作品名》
 * 子字串比對會出事——《防風鈴》是作品名該換，但劇情簡介裡的「防風鈴」是社團名，
 * 台版翻譯也是這三個字，不該動。限定在完整值或書名號內就自然避開了。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_TW_Titles {

	/** 對照表快取（跨請求）。站上標題有變動時，最慢隔一天生效。 */
	const CACHE_KEY = 'asp_tw_title_map_v1';
	const CACHE_TTL = DAY_IN_SECONDS;

	/** 太短的譯名不收，避免與一般詞彙碰撞 */
	const MIN_LEN = 4;

	/** 同一次請求內的記憶體快取 */
	private static ?array $map = null;

	/**
	 * 人工補充的對照，會蓋過自動產生的表。
	 *
	 * 自動對照表是拿 anime_title_simplified 對 post_title 產生的，所以只涵蓋
	 * 「Bangumi 那筆的中文名剛好就是該作品在大陸的通稱」這種情況。實際文章裡
	 * 還會出現別的大陸叫法，例如 WIND BREAKER 在大陸也被叫做《防風鈴》
	 * （社團名），而該作品的 anime_title_simplified 是「防风少年」，
	 * 自動比對永遠產不出這一條。
	 *
	 * 只放你確認過的配對。因為比對限定在「整串完全相等」或「《》內完全相等」，
	 * 散文裡沒有書名號的同名詞不會被動到——例如作品簡介裡的
	 * 「防風鈴」都將毫不留情地肅清，那是劇中社團名，台版翻譯也是這三個字。
	 */
	private const MANUAL_OVERRIDES = [
		'防風鈴' => 'WIND BREAKER—防風少年—',
	];

	/**
	 * 季別／版本字樣。台灣譯名若只是「大陸譯名 + 這些字樣」，代表站上那筆是續篇或
	 * 特定版本，而引用處講的多半是整個系列，換過去會是錯的。
	 */
	private const EDITION_SUFFIX = '/^[\s:：\-－–—]*('
		. '第[一二三四五六七八九十\d]+[季期部]'
		. '|第\d+部分|Part\s*\d+'
		. '|\d+(?:st|nd|rd|th)?\s*Season|Season\s*\d+'
		. '|續篇|续篇|後篇|前篇|完結篇'
		. '|劇場版|電影版|映画|Movie'
		. '|OVA|OAD|SP|特別篇|特典|總集篇'
		. ')/iu';

	// =====================================================================
	// PUBLIC
	// =====================================================================

	/**
	 * 把文字裡的大陸譯名換成台灣譯名。
	 *
	 * @param string $text 已經是繁體的文字
	 * @return string
	 */
	public static function localize( string $text ): string {

		if ( $text === '' ) {
			return $text;
		}

		$map = self::get_map();
		if ( empty( $map ) ) {
			return $text;
		}

		// ① 整串模式：整個值就是一個作品名（name_cn 這類欄位）
		$trimmed = trim( $text );
		if ( isset( $map[ $trimmed ] ) ) {
			return $map[ $trimmed ];
		}

		// ② 書名號模式。沒有「《」就直接返回——static_convert() 呼叫極頻繁，
		//    這道快速跳出讓絕大多數文字不必進正規式。
		if ( mb_strpos( $text, '《' ) === false ) {
			return $text;
		}

		return (string) preg_replace_callback(
			'/《([^》]{2,80})》/u',
			static function ( array $m ) use ( $map ): string {
				$inner = trim( $m[1] );
				return isset( $map[ $inner ] ) ? '《' . $map[ $inner ] . '》' : $m[0];
			},
			$text
		);
	}

	/**
	 * 清除對照表快取。站上標題有大量變動（例如批次匯入）後可以呼叫。
	 */
	public static function flush(): void {
		self::$map = null;
		delete_transient( self::CACHE_KEY );
	}

	// =====================================================================
	// PRIVATE
	// =====================================================================

	/**
	 * 建立（並快取）大陸譯名 → 台灣譯名的對照表。
	 */
	private static function get_map(): array {

		if ( self::$map !== null ) {
			return self::$map;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			self::$map = $cached;
			return self::$map;
		}

		// 對照表建立過程本身會呼叫 static_convert()，而 static_convert() 又會呼叫
		// 本方法——先放一個空表擋住，避免無限遞迴。
		self::$map = [];

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.post_title, mm.meta_value AS simplified
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} mm
			     ON mm.post_id = p.ID AND mm.meta_key = 'anime_title_simplified'
			  WHERE p.post_type IN ( 'anime', 'manga', 'novel', 'game' )
			    AND p.post_status = 'publish'
			    AND mm.meta_value <> ''",
			ARRAY_A
		);

		// 先分組，才判斷得出「一個大陸譯名對到幾個台灣譯名」
		$grouped = [];
		foreach ( (array) $rows as $row ) {
			$simplified = trim( (string) $row['simplified'] );
			$tw         = trim( (string) $row['post_title'] );

			if ( $simplified === '' || $tw === '' || mb_strlen( $simplified ) < self::MIN_LEN ) {
				continue;
			}

			$cn = class_exists( 'Anime_Sync_CN_Converter' )
				? Anime_Sync_CN_Converter::static_convert( $simplified )
				: $simplified;

			$cn = trim( $cn );
			if ( $cn === '' || $cn === $tw ) {
				continue;
			}

			$grouped[ $cn ][ $tw ] = true;
		}

		$map = [];
		foreach ( $grouped as $cn => $tw_set ) {
			if ( count( $tw_set ) > 1 ) {
				continue;   // 過濾二：一名多譯，無法判斷
			}

			$tw = (string) array_key_first( $tw_set );

			// 過濾三：台灣譯名只是大陸譯名加上季別／版本字樣
			if ( mb_strpos( $tw, $cn ) === 0 ) {
				$suffix = mb_substr( $tw, mb_strlen( $cn ) );
				if ( $suffix !== '' && preg_match( self::EDITION_SUFFIX, $suffix ) ) {
					continue;
				}
			}

			$map[ $cn ] = $tw;
		}

		// 人工補充蓋過自動產生的結果
		$map = array_merge( $map, self::MANUAL_OVERRIDES );

		/**
		 * 讓外部再加對照，不必改這個檔案。
		 *
		 * @param array $map 大陸譯名 => 台灣譯名
		 */
		$map = (array) apply_filters( 'asp_tw_title_map', $map );

		set_transient( self::CACHE_KEY, $map, self::CACHE_TTL );
		self::$map = $map;

		return self::$map;
	}
}
