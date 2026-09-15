<?php
/**
 * 檔案名稱: includes/class-streaming-source-yt-channels.php
 * 各 YouTube 頻道的來源子類別（一家幾行，集中放一檔）
 *
 * 共用邏輯在 class-streaming-source-youtube.php；這裡只有頻道 ID 與命名慣例。
 * 頻道 ID 與命名樣本皆為 2026-09-15 用 YouTube Data API 實查。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Muse 木棉花 — 「Muse木棉花-TW」，595 個清單。
 * 清單名稱就是台灣譯名：「葬送的芙莉蓮」「BanG Dream! YUME∞MITA」。用預設規則。
 */
class Anime_Sync_Streaming_Source_Muse extends Anime_Sync_Streaming_Source_Youtube {
	public function key(): string { return 'muse'; }
	protected function bangumi_sites(): array { return [ 'muse_tw' ]; }
	protected function channel_id(): string { return 'UCgdwtyqBunlRb-i-7PnCssQ'; }
}

/**
 * Ani-One 羚邦 — 用「Ani-One中文官方動畫頻道」（408 個清單），不用 Ani-One Asia
 * （471 個，中英混排、順序不固定，難切）。命名：
 *   「🗨️《果然我的青春戀愛喜劇搞錯了。續》(繁中字幕)【Ani-One】」
 *   「🦾《怪獸8號》原創短篇動畫《鳴海的平日》(繁中字幕)【Ani-One】| 2026年7月新番」
 * 沒有《》的（10 週年祝賀、聲優訪談、馬拉松）不是作品，跳過。
 */
class Anime_Sync_Streaming_Source_Ani_One extends Anime_Sync_Streaming_Source_Youtube {
	public function key(): string { return 'ani_one'; }
	protected function bangumi_sites(): array { return [ 'ani_one', 'ani_one_asia' ]; }
	protected function channel_id(): string { return 'UC45ONEZZfMDZCnEhgYmVu-A'; }

	protected function parse_title( string $title ): string {
		if ( preg_match( '/粵語|中文配音|國語配音|中配/u', $title ) ) {
			return '';
		}
		return $this->title_from_brackets( $title );
	}
}

/**
 * 回歸線娛樂 — 99 個清單。「emoji《作品名》｜回歸線娛樂」，取《…》。
 */
class Anime_Sync_Streaming_Source_Tropicsanime extends Anime_Sync_Streaming_Source_Youtube {
	public function key(): string { return 'tropicsanime'; }
	protected function bangumi_sites(): array { return [ 'tropics' ]; }
	protected function channel_id(): string { return 'UCBxsPpM2YiwN6phyYgvc4Pw'; }

	protected function parse_title( string $title ): string {
		return $this->title_from_brackets( $title );
	}
}

/**
 * 曼迪 — 「曼迪Mighty TV動畫頻道」，44 個清單。命名：
 *   「🚩《終末起點》第2季｜2026年4月新番｜曼迪Mighty TV」→ 終末起點 第2季
 *   「🚩《終末起點》全集｜曼迪Mighty TV」                → 終末起點
 *   「🐸《KERORO軍曹》第二季（雙語）｜2005年4月｜曼迪Mighty TV」→ KERORO軍曹 第二季
 * 「（雙語）」是同一清單雙音軌，不是中配另清單，不跳過。
 */
class Anime_Sync_Streaming_Source_Mighty extends Anime_Sync_Streaming_Source_Youtube {
	public function key(): string { return 'mighty'; }
	protected function bangumi_sites(): array { return [ 'mighty' ]; }
	protected function channel_id(): string { return 'UCCrpNwDnc_ULP3tjUQsCkag'; }

	protected function parse_title( string $title ): string {
		return $this->title_from_brackets( $title );
	}
}

/**
 * Ani-Mi 動漫迷 — 「Ani-Mi動漫迷動畫頻道」，61 個清單。命名：
 *   「《宗門裏除了我都是臥底》 (繁中字幕)【Ani-Mi】」「《時光代理人》系列」「《時光代理人》(粵語配音)【Ani-Mi】」
 * 粵語配音跳過。
 */
class Anime_Sync_Streaming_Source_Ani_Mi extends Anime_Sync_Streaming_Source_Youtube {
	public function key(): string { return 'ani_mi'; }
	protected function channel_id(): string { return 'UCTs_U2LuIay1VmgFrbcweaw'; }

	protected function parse_title( string $title ): string {
		if ( preg_match( '/粵語|中文配音|國語配音|中配/u', $title ) ) {
			return '';
		}
		return $this->title_from_brackets( $title );
	}
}

/**
 * It's Anime（REMOW）— 57 個清單，多為英文：
 *   「[Spring 2026 Anime] KILL BLUE」「Witch Hat Atelier《魔法帽的工作室》」
 * 有《中文》就用中文；否則去掉開頭 [ … ] 標籤用英文，比對時多拿站上英文／羅馬字欄位。
 * 國際頻道，清單常混片段剪輯——只作平台網址，集數同步交給既有 yt_global 規則。
 */
class Anime_Sync_Streaming_Source_Its_Anime extends Anime_Sync_Streaming_Source_Youtube {
	public function key(): string { return 'its_anime'; }
	protected function channel_id(): string { return 'UCsj_CYajUSQ2ca8bYCMan9g'; }

	protected function parse_title( string $title ): string {
		if ( preg_match( '/《([^》]+)》/u', $title, $m ) ) {
			return trim( $m[1] );
		}
		$t = trim( (string) preg_replace( '/^(?:\[[^\]]*\]\s*)+/u', '', $title ) );
		return parent::parse_title( $t );
	}

	public function match_titles( int $post_id ): array {
		return array_values( array_filter( array_unique( [
			(string) get_the_title( $post_id ),
			(string) get_post_meta( $post_id, 'anime_title_chinese', true ),
			(string) get_post_meta( $post_id, 'anime_title_english', true ),
			(string) get_post_meta( $post_id, 'anime_title_romaji', true ),
		] ) ) );
	}
}
