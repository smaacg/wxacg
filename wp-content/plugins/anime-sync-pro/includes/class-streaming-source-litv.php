<?php
/**
 * 檔案名稱: includes/class-streaming-source-litv.php
 * LiTV 立視線上影視 — 直接抓取來源
 *
 * 資料來源：https://www.litv.tv/sitemap.xml
 *   sitemapindex，15 個子檔 /sitemapN.xml，每檔約 7MB、8,500+ 筆 video:title，
 *   絕大多數是台灣本土戲劇與綜藝，動畫只佔一小部分（不必先過濾，站上沒有的
 *   自然配不到）。2026-09-15 實測從正式站主機全部 200。
 *
 * 條目格式與 Ofiii 完全同一套（同集團、同 CDN 產生器）：
 *   作品名 第1季 第12集 副標題
 *   作品名 集
 *   作品名
 * 所以歸納與挑集邏輯直接繼承 Ofiii 子類別，這裡只換 key 與 sitemap 網址。
 * 若日後兩家格式分家，再把 work_name()／pick_entry() 覆寫回來即可。
 *
 * 本機實測（gain.php，站上 1,983 部）：召回率 58.9%、淨增益 13 部。
 *   召回率比 Ofiii（74.6%）低，因為 LiTV 條目的副標題與集數寫法更雜
 *   （「第1季 第1A集」、「集」單獨出現）；歸納規則已涵蓋觀察到的樣式。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Litv extends Anime_Sync_Streaming_Source_Ofiii {

	public function key(): string {
		return 'litv';
	}

	protected function sitemap_url(): string {
		return 'https://www.litv.tv/sitemap.xml';
	}
}
