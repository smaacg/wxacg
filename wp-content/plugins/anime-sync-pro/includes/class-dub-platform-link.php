<?php
/**
 * 配音連結 → 串流平台自動連動
 *
 * 解決什麼問題
 * ------------
 * 配音觀看連結（anime_dub_url_taigi / anime_dub_url_mandarin）是純人工欄位，
 * 沒有任何自動同步會寫，也只有作品頁在讀。結果是「有台語版可以在公視+看」
 * 這件事寫在配音欄位裡，卻從來不會進到 anime_tw_streaming。
 *
 * 實際後果：/streaming/ 上「公視(台語版)」顯示 0 部，但站上其實有 3 部
 * 掛著 ptsplus.tv 的台語版連結（葬送的芙莉蓮、路人超能100 與其迷你動畫）。
 * 公視+ 只上架台語版，所以它永遠不會出現在 YourAnimes 的一般串流區塊，
 * 那條自動同步的路徑抓不到它——只能靠這裡補。
 *
 * 做法
 * ----
 * 這兩個欄位一存檔就解析裡面的網址，用註冊表既有的 match_site() 比對平台，
 * 沒在 anime_tw_streaming 裡的就補進去。
 *
 * 只增不減：配音欄位被清空時不會移除平台——那可能是另一個來源加的，
 * 這裡沒有足夠資訊判斷該不該拿掉。
 *
 * 不覆蓋既有的平台網址：主串流網址（正片）比配音連結更適合當該平台的代表，
 * 只有在原本是空的時候才填。
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Dub_Platform_Link {

	/**
	 * 會觸發連動的配音欄位。
	 *
	 * 目前 ACF 只定義了這兩個（anime_dub_language 的選項也只有國語／台語）。
	 * 之後若新增語言，欄位名要一併加進來，否則新語言的連結一樣不會連動。
	 */
	private const DUB_FIELDS = [
		'anime_dub_url_taigi',
		'anime_dub_url_mandarin',
	];

	/** 防止自身寫入再次觸發 */
	private static bool $running = false;

	public static function init(): void {
		add_action( 'added_post_meta',   [ __CLASS__, 'on_meta' ], 20, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_meta' ], 20, 4 );
	}

	public static function on_meta( $meta_id, $post_id, $meta_key, $meta_value ): void {

		if ( self::$running || ! in_array( $meta_key, self::DUB_FIELDS, true ) ) {
			return;
		}
		if ( get_post_type( (int) $post_id ) !== 'anime' ) {
			return;
		}
		if ( ! class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
			return;
		}

		self::$running = true;
		self::sync( (int) $post_id, (string) $meta_value );
		self::$running = false;
	}

	/**
	 * 解析欄位內容裡的網址並補進串流平台清單。
	 *
	 * @return array<string> 這次新增的平台 key
	 */
	public static function sync( int $post_id, string $raw ): array {

		$urls = self::extract_urls( $raw );
		if ( ! $urls ) {
			return [];
		}

		$checked = get_post_meta( $post_id, 'anime_tw_streaming', true );
		if ( ! is_array( $checked ) ) {
			$checked = [];
		}

		$added = [];

		foreach ( $urls as $url ) {
			$key = Anime_Sync_Streaming_Registry::match_site( '', $url );
			if ( ! $key ) {
				continue;
			}

			if ( ! in_array( $key, $checked, true ) ) {
				$checked[] = $key;
				$added[]   = $key;
			}

			// 該平台還沒有代表網址時才填；正片網址優先，不覆蓋
			$url_key = 'anime_tw_streaming_url_' . $key;
			if ( trim( (string) get_post_meta( $post_id, $url_key, true ) ) === '' ) {
				update_post_meta( $post_id, $url_key, $url );
			}
		}

		if ( $added ) {
			update_post_meta( $post_id, 'anime_tw_streaming', array_values( array_unique( $checked ) ) );

			// 平台計數是快取的，不清的話 /streaming/ 最多要等 6 小時才會反映
			delete_transient( 'asp_streaming_counts_v3' );

			if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
				Anime_Sync_Error_Logger::info( sprintf(
					'配音連結連動：%s 補上串流平台 %s',
					get_the_title( $post_id ) ?: "#{$post_id}",
					implode( '、', $added )
				), [ 'post_id' => $post_id ] );
			}
		}

		return $added;
	}

	/**
	 * 從「多行、可帶 標籤|網址」的欄位內容抽出所有網址。
	 *
	 * 分隔符與 single-anime.php 的 $parse_dub_urls 一致，
	 * 兩邊對同一份資料的理解必須相同。
	 */
	private static function extract_urls( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return [];
		}

		$out     = [];
		$entries = preg_split( '/[,，、;；\r\n]+/u', $raw );

		foreach ( (array) $entries as $entry ) {
			$entry = trim( (string) $entry );
			if ( $entry === '' ) {
				continue;
			}

			// 「標籤|網址」取後半段
			if ( strpos( $entry, '|' ) !== false ) {
				$parts = explode( '|', $entry, 2 );
				$entry = trim( $parts[1] );
			}

			if ( preg_match( '#^https?://#i', $entry ) ) {
				$out[] = $entry;
			}
		}

		return $out;
	}
}
