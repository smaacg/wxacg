<?php
/**
 * 檔案名稱: includes/class-streaming-source-myvideo.php
 * 台灣大哥大 MyVideo — 直接抓取來源
 *
 * 資料來源：https://www.myvideo.net.tw/sitemap.xml
 *   sitemapindex，28 個子檔在 /event/SEO_ALL/sitemapN.xml，總計約 58,000 筆
 *   （含華劇、韓劇、電影，動畫只是其中一部分；不需要先過濾，比對時站上
 *   沒有的自然配不到）。2026-09-15 實測從正式站主機（吉隆坡）全部 200。
 *
 * 條目格式：
 *   <loc>https://www.myvideo.net.tw/details/0/21602</loc>
 *   <video:title><![CDATA[名偵探柯南：沉默的15分鐘]]></video:title>
 *   標題有兩種層級：作品頁「工作細胞」與單集頁「工作細胞 第1集」，各有自己的
 *   details 網址。歸納規則是去尾端集數；挑網址時作品頁優先（見 pick_entry）。
 *
 * 本機實測（gain.php，站上 1,983 部）：召回率 73.5%、淨增益 29 部。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Myvideo extends Anime_Sync_Streaming_Source_Base {

	public function key(): string {
		return 'myvideo';
	}

	/** 作品頁從主機打得到：存在 200、不存在 404（2026-09-16 實測）。 */
	protected function provides_alive_check(): bool {
		return true;
	}

	/**
	 * 每天跑，讓覆核推得動：站上 908 筆 MyVideo 網址、每輪 150 筆，日跑約 6 天輪完一圈；
	 * 週跑要六週。索引仍是每 6 天才重建一次（見基底 run_locked 的說明），不會天天重抓 5.8 萬條目。
	 */
	protected function recurrence(): string {
		return 'daily';
	}

	protected function sitemap_url(): string {
		return 'https://www.myvideo.net.tw/sitemap.xml';
	}

	protected function parse_entry( string $block ): ?array {

		if ( ! preg_match( '#<loc>\s*([^<\s]+)\s*</loc>#i', $block, $loc ) ) {
			return null;
		}
		// CDATA 裡常帶換行，先拆殼再 trim
		if ( ! preg_match( '#<video:title>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</video:title>#si', $block, $title ) ) {
			return null;
		}

		$url = html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );

		// 只收作品／單集頁，活動頁等一律略過
		if ( strpos( $url, '/details/' ) === false ) {
			return null;
		}

		$t = html_entity_decode( trim( strip_tags( $title[1] ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' );

		return [ 'title' => $t, 'url' => $url, 'date' => '' ];
	}

	/** 去尾端集數：「第12集」「第331至430話」「第1-12集」。 */
	protected function work_name( string $title ): string {

		$prev = null;
		$t    = trim( $title );

		while ( $prev !== $t ) {
			$prev = $t;
			$t    = (string) preg_replace( '/\s*第\s*\d+\s*(?:至\s*\d+\s*)?[集話话]\s*$/u', '', $t );
			$t    = (string) preg_replace( '/\s*第\s*\d+\s*[-~－～]\s*\d+\s*[集話话]\s*$/u', '', $t );
			$t    = trim( $t );
		}

		return $t;
	}

	/**
	 * 作品頁優先：標題去集數後與原標題相同的那筆就是作品頁（「工作細胞」），
	 * 沒有作品頁才退到 details id 最小的單集。
	 *
	 * @param array<int,array{title:string,url:string,date?:string}> $entries
	 */
	protected function pick_entry( array $entries ): array {

		foreach ( $entries as $e ) {
			if ( $this->work_name( (string) $e['title'] ) === trim( (string) $e['title'] ) ) {
				return $e;
			}
		}

		usort( $entries, static function ( $a, $b ) {
			return self::details_id( $a['url'] ) <=> self::details_id( $b['url'] );
		} );

		return $entries[0];
	}

	private static function details_id( string $url ): int {
		return preg_match( '#/details/\d+/(\d+)#', $url, $m ) ? (int) $m[1] : PHP_INT_MAX;
	}
}
