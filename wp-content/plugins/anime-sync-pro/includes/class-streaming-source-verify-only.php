<?php
/**
 * 檔案名稱: includes/class-streaming-source-verify-only.php
 * 「只覆核、不發現」的來源：Prime Video 與 Apple TV
 *
 * 為什麼只覆核
 * ------------
 * 這兩家沒有可用的台灣目錄可以列舉，所以我們不主動找新作品（新連結仍由匯入時的
 * AniList externalLinks 提供），但**可以驗證站上既有的網址現在還能不能看**：
 *
 *   - Prime Video：沒有 sitemap（四個常見路徑都 404、robots 也沒宣告），
 *     /collection/Anime 與 /categories 在本機與主機都是前端渲染、抓不到作品連結，
 *     /search 與 /api 被 robots 擋。但作品頁主機抓得到，而且看得出可看狀態。
 *   - Apple TV：有 sitemap，但台灣作品散在 1,232 個子檔裡（show 250＋movie 740…），
 *     要下載約 234MB 才能濾出來，而且索引沒有 lastmod、每輪都得重抓整包。
 *     站上 Apple TV 只有 76 筆，成本效益不成立。
 *
 * 2026-09-16 實測到的可看狀態判斷
 * -------------------------------
 *   Prime：台灣看不到的作品照樣回 200，但主要按鈕會寫
 *          「您所在地區的 Prime Video 無法繼續觀看此內容」；可看的則有
 *          data-testid="entitlement-message"（例如「以 Prime 會員資格觀看」）。
 *          ⚠ 對照組很重要：可看的頁面也會出現「您所在」三個字（在觀看派對的介面字串裡），
 *          所以必須比對整句，不能只找關鍵詞。
 *          站上 26 筆實測：可看 20、台灣無法觀看 5、判不出 1；本機（台灣 IP）與主機（馬來西亞 IP）
 *          結果完全一致，所以主機量到的就是台灣視角。
 *   Apple TV：台灣頁存在不等於 Apple 有賣——《葬送的芙莉蓮》也有 /tw/show/ 頁面。
 *          真正在賣的頁面 SSR 裡有 "channelId":"tvs.sbd.9001"（iTunes 商店）或 4000（Apple TV+），
 *          只是目錄頁的沒有。商店由網址的 /tw/ 決定，與連線 IP 無關（本機與主機結果一致）。
 *          站上 76 筆實測：可買租 70、只有目錄頁 4、非台灣區網址 2。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Anime_Sync_Streaming_Source_Verify_Only extends Anime_Sync_Streaming_Source_Base {

	/** 不建索引、不找新作品，run() 會在索引階段之前分流。 */
	protected function verify_only(): bool {
		return true;
	}

	/** 作品頁抓得到、看得出狀態，所以可以覆核站上全部網址（不限誰寫的）。 */
	protected function provides_alive_check(): bool {
		return true;
	}

	/** 這兩家的覆核比較重（整頁數百 KB），每輪少一點。 */
	protected function recheck_batch(): int {
		return 60;
	}

	protected function recurrence(): string {
		return 'daily';
	}

	// ── 以下是基底的抽象宣告，純覆核來源用不到 ──

	protected function sitemap_url(): string {
		return '';
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	protected function work_name( string $title ): string {
		return trim( $title );
	}
}

/**
 * Prime Video：驗證站上既有網址在台灣能不能看。
 */
class Anime_Sync_Streaming_Source_Amazon extends Anime_Sync_Streaming_Source_Verify_Only {

	/** 台灣看不到時，主要按鈕會顯示這一整句 */
	const BLOCKED_TEXT = '無法繼續觀看此內容';

	public function key(): string {
		return 'amazon';
	}

	/**
	 * AniList 給的是全球網址（primevideo.com/detail/{ASIN}），在台灣打開兩種訊息都不會出現、
	 * 判斷不出可看性；改成台灣區路徑就問得到答案（ASIN 相同）。站上 26 筆裡有 12 筆是這種。
	 * 只改「拿去抓的網址」，資料庫裡存的不動——那是讀者實際會點的連結。
	 */
	protected function recheck_url( string $url ): string {
		if ( strpos( $url, '/-/' ) !== false ) {
			return $url;   // 已經帶地區路徑
		}
		return (string) preg_replace( '#^(https://www\.primevideo\.com)/detail/#', '$1/-/zh_TW/detail/', $url );
	}

	protected function parse_alive( string $html, string $url = '' ): ?bool {

		if ( strpos( $html, self::BLOCKED_TEXT ) !== false ) {
			return false;
		}

		// 有可觀看方式的說明（「以 Prime 會員資格觀看」等）就是還在
		if ( preg_match( '#data-testid="entitlement-message"#', $html ) ) {
			return true;
		}

		/*
		 * 兩種訊息都沒有：多半是純租買、或本來就是別的地區的商品頁（站上有 1 筆日文標題的）。
		 * 判斷不出來就不要動它——寧可漏掉一筆下架，也不要誤刪讀者還能看的連結。
		 */
		return null;
	}
}

/**
 * Apple TV：驗證站上既有網址在台灣是不是真的買得到／租得到。
 */
class Anime_Sync_Streaming_Source_Appletv extends Anime_Sync_Streaming_Source_Verify_Only {

	/** tvs.sbd.9001＝iTunes 商店（買／租）、tvs.sbd.4000＝Apple TV+ 訂閱 */
	const STORE_CHANNELS = [ 'tvs.sbd.9001', 'tvs.sbd.4000' ];

	public function key(): string {
		return 'appletv';
	}

	protected function parse_alive( string $html, string $url = '' ): ?bool {

		/*
		 * 網址不是台灣商店就直接判定無效：站上有 2 筆是 /us/ 與 /jp/（AniList 給錯地區），
		 * 那種連結點過去是美國／日本的商店，對台灣讀者沒有意義。不必抓頁面也知道。
		 */
		if ( $url !== '' && strpos( $url, 'tv.apple.com/tw/' ) === false ) {
			return false;
		}

		foreach ( self::STORE_CHANNELS as $ch ) {
			if ( strpos( $html, '"channelId":"' . $ch . '"' ) !== false ) {
				return true;
			}
		}

		/*
		 * 頁面在、但沒有任何商店頻道＝只是目錄頁（Apple TV 會替沒在賣的作品也建頁面，
		 * 例如《葬送的芙莉蓮》）。這種連結點過去買不到，視為無效。
		 *
		 * ⚠ 2026-09-20：Apple 改版，這個判斷只在**舊結構**下才成立。
		 *   實測三個仍可正常觀看的作品頁（青之蘆葦、來自深淵總集篇前／後編）：
		 *     - "channelId" 一次都不出現（舊結構整個沒了）
		 *     - "canonicalId" 也消失了，但 umc.cmc. 還在 → 舊寫法會掉進這裡回 false
		 *     - offers／playables／price／buyParams 全部 0：上架資訊已搬到前端，
		 *       主機抓到的 HTML 裡**沒有任何可據以判斷的依據**
		 *   （頁面裡確實找得到 tvs.sbd.4000，但它在 "target":{"type":"Brand"} 的
		 *     Apple TV+ 推銷區塊裡，每頁都有，拿它當證據會把 133 筆全判成還在。）
		 *
		 *   所以改成：拿 "canonicalId"（改版後消失的那個欄位）當「舊結構」的標記。
		 *   舊結構的目錄頁仍然判得出買不到；新結構缺少判斷依據，回 null 不下結論。
		 *   真正下架的頁面回 HTTP 404（2026-09-20 以假 umc.cmc id 實測），
		 *   由 alive_missing_codes() 那條路徑處理，不受這裡影響。
		 *
		 *   代價：新結構下分不出「目錄頁」與「可購買」，那種連結會留著。
		 *   留一個可能買不到的連結，遠好過誤刪 133 筆真的能看的。
		 */
		if ( strpos( $html, '"canonicalId":"' ) !== false ) {
			return false;
		}

		return null;
	}
}
