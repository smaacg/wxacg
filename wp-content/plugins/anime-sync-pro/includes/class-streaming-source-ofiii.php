<?php
/**
 * 檔案名稱: includes/class-streaming-source-ofiii.php
 * Ofiii 歐飛 — 直接抓取來源
 *
 * 資料來源：https://www.ofiii.com/sitemap.xml
 *   sitemapindex，12 個子檔在 LiTV 的 CDN（fino.svc.litv.tv/ofiii/pc/sitemap/），
 *   每檔約 7.6MB、9,000+ 筆 video:title。基底一檔一檔抓、解析完即丟，
 *   峰值記憶體是單檔的量不是總量。2026-09-15 實測從正式站主機全部 200。
 *
 * 條目格式與 LiTV 同一套（同集團）：集數夾在中間、後面接副標題
 *   作品名 第1季 第12集 副標題
 *   作品名 第2季 第0集
 *   作品名 集                 ← 單獨一個「集」
 *   作品名                    ← 作品頁
 * 所以歸納不能只看尾端，要從「第N集」處整段截斷；只截集數不截季別
 * （季別是作品識別的一部分，截掉會讓各季擠成同一個鍵變多重候選）；
 * 第一季不寫季別才是常態，「第1季」要去掉才配得上站上的無季別標題。
 *
 * 本機實測（gain.php，站上 1,983 部）：召回率 74.6%、淨增益 33 部。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Ofiii extends Anime_Sync_Streaming_Source_Base {

	public function key(): string {
		return 'ofiii';
	}

	/** 作品頁從主機打得到：存在 200、不存在 404（2026-09-16 實測；LiTV 子類別繼承這個設定）。 */
	protected function provides_alive_check(): bool {
		return true;
	}

	/** 每天跑讓覆核推得動（Ofiii 618 筆、LiTV 658 筆）；索引仍每 6 天才重建。LiTV 繼承這個設定。 */
	protected function recurrence(): string {
		return 'daily';
	}

	protected function sitemap_url(): string {
		return 'https://www.ofiii.com/sitemap.xml';
	}

	protected function parse_entry( string $block ): ?array {

		if ( ! preg_match( '#<loc>\s*([^<\s]+)\s*</loc>#i', $block, $loc ) ) {
			return null;
		}
		// 只有影片條目才有 video:title，沒有的就是分類頁，自然略過
		if ( ! preg_match( '#<video:title>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</video:title>#si', $block, $title ) ) {
			return null;
		}

		return [
			'title' => html_entity_decode( trim( strip_tags( $title[1] ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			'url'   => html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			'date'  => '',
		];
	}

	protected function work_name( string $title ): string {

		$t = trim( $title );

		// 從集數標記處整段截斷（後面是副標題，與作品名無關）
		$t = (string) preg_split( '/\s*第\s*\d+\s*(?:至\s*\d+\s*)?[集話话]/u', $t )[0];
		$t = (string) preg_split( '/\s+集\s*$/u', $t )[0];

		$prev = null;
		while ( $prev !== $t ) {
			$prev = $t;
			$t    = (string) preg_replace( '/\s*\[[^\]]*\]\s*$/u', '', $t );
			$t    = (string) preg_replace( '/\s*第\s*\d+\s*[-~－～]\s*\d+\s*[集話话]\s*$/u', '', $t );
			$t    = (string) preg_replace( '/\s*第\s*(?:1|一)\s*季\s*$/u', '', $t );
			$t    = trim( $t );
		}

		return $t;
	}

	/**
	 * 作品頁優先（標題去集數後與原標題相同）；沒有就取集數最小的那集；
	 * 連集數都認不出來就取第一筆。
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
			return self::episode_no( $a['title'] ) <=> self::episode_no( $b['title'] );
		} );

		return $entries[0];
	}

	private static function episode_no( string $title ): int {
		return preg_match( '/第\s*(\d+)\s*[集話话]/u', $title, $m ) ? (int) $m[1] : PHP_INT_MAX;
	}
}
