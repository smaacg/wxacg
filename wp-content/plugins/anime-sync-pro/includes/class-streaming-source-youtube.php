<?php
/**
 * 檔案名稱: includes/class-streaming-source-youtube.php
 * YouTube 頻道型串流來源的共用子基底
 *
 * 台灣代理商在 YouTube 上的正版頻道（木棉花、Ani-One、回歸線、It's Anime、曼迪、
 * Ani-Mi）每部作品一個播放清單，清單名稱就是作品名。用 YouTube Data API
 * playlists.list 列出頻道全部清單即為該平台的作品目錄——官方 API、零被擋風險，
 * 每頁 50 筆只花 1 單位配額（每日 10,000），一家最多幾十頁。
 *
 * 金鑰沿用站上既有的 SMACG_YT_API_KEY（class-youtube-playlist-sync.php 也用它）。
 *
 * 每個頻道一個小子類別，只需提供：
 *   key()             → 登錄表 key
 *   channel_id()      → 頻道 ID
 *   parse_title()     → 清單名稱 → 作品名（各家命名慣例不同：木棉花就是作品名；
 *                       Ani-One 是「英文｜中文【Ani-One Asia ULTRA】」；
 *                       回歸線是「emoji《作品名》｜回歸線娛樂」）
 *
 * 寫入時除了平台網址（播放清單網址），若作品的 anime_yt_playlist_url 還是空的，
 * 一併填入並觸發既有的 YouTube 播放清單同步——這樣集數也會跟著進來，
 * 做法與 class-youranimes-fetcher.php::write_to_acf() 那段一致。
 *
 * 2026-09-15 實測：木棉花 595 清單、Ani-One 471、回歸線 99。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Anime_Sync_Streaming_Source_Youtube extends Anime_Sync_Streaming_Source_Base {

	const API_PLAYLISTS = 'https://www.googleapis.com/youtube/v3/playlists';
	const PER_PAGE      = 50;
	const MAX_PAGES     = 40;   // 2,000 個清單，保險上限

	/** 頻道 ID（UC…）。 */
	abstract protected function channel_id(): string;

	/**
	 * 播放清單名稱 → 作品名。回空字串代表這個清單不是作品（例如「PV 合集」）。
	 * 預設：去掉【…】與前後空白；中文配音版跳過。子類別依命名慣例覆寫。
	 */
	protected function parse_title( string $title ): string {

		$t = trim( (string) preg_replace( '/【[^】]*】/u', '', $title ) );

		// 中配是另一條清單，主網址要原音版；中配欄位本輪不寫
		if ( preg_match( '/中文配音|國語配音|中配|國語版|台語/u', $t ) ) {
			return '';
		}

		return $t;
	}

	/** 這一層負責 API；work_name() 交給 parse_title()。 */
	protected function work_name( string $title ): string {
		return $this->parse_title( $title );
	}

	/**
	 * 台灣代理商頻道最常見的命名：「emoji《作品名》第2季（雙語）｜2026年4月新番｜頻道名」。
	 * 取《…》內文，再把緊接在 》後面、第一個 ｜ 之前的季別接上；
	 * 「全集」「（雙語）」「(繁中字幕)」「系列」這類與作品識別無關的字樣去掉。
	 * 沒有《》回空字串——那多半是訪談、馬拉松、祝賀影片，不是作品。
	 *
	 * @return string 作品名（含季別），或空字串
	 */
	protected function title_from_brackets( string $title ): string {

		if ( ! preg_match( '/《([^》]+)》([^｜|]*)/u', $title, $m ) ) {
			return '';
		}

		$name  = trim( $m[1] );
		$after = (string) preg_replace( '/【[^】]*】|\([^)]*\)|（[^）]*）|全集|系列|新番/u', '', $m[2] );
		$after = trim( $after );

		return trim( $name . ( $after !== '' ? ' ' . $after : '' ) );
	}

	protected function sitemap_url(): string {
		return '';
	}

	protected function parse_entry( string $block ): ?array {
		return null;
	}

	/**
	 * 覆寫收集：翻完頻道的播放清單。
	 *
	 * @param float $started
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		$key = defined( 'SMACG_YT_API_KEY' ) ? (string) SMACG_YT_API_KEY : '';

		if ( $key === '' ) {
			return new WP_Error( 'no_api_key', 'wp-config 未定義 SMACG_YT_API_KEY' );
		}

		$grouped = [];
		$entries = 0;
		$token   = '';

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {

			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				$this->log_warning( sprintf( '時間預算用盡，翻到第 %d 頁為止', $page - 1 ) );
				break;
			}

			$url = add_query_arg( array_filter( [
				'part'       => 'snippet,contentDetails',
				'maxResults' => self::PER_PAGE,
				'channelId'  => $this->channel_id(),
				'pageToken'  => $token,
				'key'        => $key,
			] ), self::API_PLAYLISTS );

			$body = $this->fetch( $url, [ 'accept' => 'application/json' ] );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$data = json_decode( (string) $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_Error( 'bad_json', 'YouTube API 回應不是 JSON' );
			}

			if ( ! empty( $data['error'] ) ) {
				// 配額用盡、金鑰失效都會在這裡出現，原文留給 log
				return new WP_Error( 'yt_api_error', (string) ( $data['error']['message'] ?? 'unknown' ) );
			}

			foreach ( (array) ( $data['items'] ?? [] ) as $pl ) {

				$id    = (string) ( $pl['id'] ?? '' );
				$title = (string) ( $pl['snippet']['title'] ?? '' );
				$count = (int) ( $pl['contentDetails']['itemCount'] ?? 0 );

				// 空清單不是作品
				if ( $id === '' || $title === '' || $count === 0 ) {
					continue;
				}

				$w = $this->work_name( $title );
				if ( $w === '' ) {
					continue;
				}

				$entries++;
				$this->add_entry( $grouped, $w, [
					'title' => $title,
					'url'   => 'https://www.youtube.com/playlist?list=' . $id,
					'date'  => '',
				] );
			}

			$token = (string) ( $data['nextPageToken'] ?? '' );
			if ( $token === '' ) {
				break;
			}
		}

		return [ $grouped, $entries ];
	}

	/**
	 * 同一作品多個清單時（例如「作品」與「作品 第二季」歸納後同名不會發生，但
	 * 「作品」與「作品 PV」會），影片數多的那個比較可能是正片清單。
	 */
	protected function pick_entry( array $entries ): array {
		// entries 只帶 title/url，沒有 itemCount；保守取第一筆（API 依建立時間新→舊回傳，
		// 新建立的通常是正片清單）。日後要精挑再把 itemCount 帶進 entry。
		return $entries[0];
	}

	/**
	 * 除了平台網址，順手填 anime_yt_playlist_url 並觸發集數同步。
	 * 只在該欄位空白時填，不覆蓋人工或 YA 填的清單。
	 */
	protected function write( int $post_id, string $url ): bool {

		if ( ! parent::write( $post_id, $url ) ) {
			return false;
		}

		$current = trim( (string) get_post_meta( $post_id, 'anime_yt_playlist_url', true ) );

		if ( $current === '' && strpos( $url, 'list=' ) !== false ) {

			if ( function_exists( 'update_field' ) ) {
				update_field( 'field_anime_yt_playlist_url', $url, $post_id );
			} else {
				update_post_meta( $post_id, 'anime_yt_playlist_url', $url );
			}

			if ( class_exists( 'Anime_Sync_YouTube_Playlist_Sync' ) ) {
				( new Anime_Sync_YouTube_Playlist_Sync() )->sync_post( $post_id, true );
			}
		}

		return true;
	}
}
