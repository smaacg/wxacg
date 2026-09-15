<?php
/**
 * 檔案名稱: includes/class-youranimes-news-scan.php
 * YourAnimes「最近更新」掃描 — 把人工編輯的上架／下架公告變成站上的事件與下架訊號
 *
 * 為什麼需要
 * ----------
 * 平台目錄的索引比對（class-streaming-source-base.php::check_gone()）只知道
 * 「這部還在／不在平台清單上」，不知道原因。YourAnimes 的 /animes/recent-updated
 * 是編輯手寫的異動：
 *     「木棉花的版權授權到期，於串流平台下架」（2026-09-13）
 *     「由曼迪授權，於各平台重新上架」
 *     「車庫娛樂宣布將在2026年10月在台灣上映」
 * 有日期、有原因、有代理商。站上已有 anime_youranimes_url 的作品可用 YA 的 _id
 * **精確對應**，完全不碰標題比對。
 *
 * 資料在頁面內嵌的（跳脫過的）JSON 字串裡，每部：
 *     {"_id":"992","name":"再見了，我的克拉默 First Touch","jpName":"…",
 *      "news":[{"date":"2026-09-13","info":"木棉花的版權授權到期，於串流平台下架","sourceUrl":""}],
 *      "newsDate":"2026-09-13"}
 * 一頁 50 部，每日掃一次足夠（編輯一天更新不了 50 部）。
 *
 * 做什麼
 * ------
 *   1. 每則 news → Anime_Sync_Anime_Events::record()，event_type 'streaming'，
 *      指紋 = 日期|原文，重掃不會重複。'streaming' 不在自動發布名單，進待審清單。
 *   2. 原文含「下架」且能從代理商關鍵字（登錄表 yt_keywords）推出平台 key 時，
 *      對該作品的該平台標 _anime_tw_streaming_gone_{key}「日期|2」——
 *      只差一輪就會被 check_gone() 移除，等於「YA 說下架 + 平台清單也沒有」雙重確認才動。
 *      不直接移除：YA 的文字是人寫的，偶有寫錯代理商。
 *
 * robots.txt 對所有 UA Allow；一天一個請求。熔斷與節流沿用 season-index 的
 * fetch_html_public()（與 YA 其他抓取共用同一個熔斷）。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_YourAnimes_News_Scan {

	const URL          = 'https://youranimes.tw/animes/recent-updated';
	const HOOK         = 'anime_sync_ya_news_scan';
	const STATE_OPTION = 'anime_sync_ya_news_scan_state';
	const LOCK_KEY     = 'anime_sync_lock_ya_news_scan';
	const LOCK_TTL     = 120;

	public function __construct() {
		add_action( self::HOOK, [ $this, 'run_scheduled' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 1200, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function run_scheduled(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
		try {
			$r = $this->run();
			if ( ! is_wp_error( $r ) ) {
				self::log( 'info', sprintf( '[排程] 條目 %d、對應到站上 %d、新事件 %d、標疑似下架 %d', $r['entries'], $r['mapped'], $r['events'], $r['gone'] ) );
			} else {
				self::log( 'warning', '[排程] 失敗：' . $r->get_error_message() );
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * @return array{entries:int,mapped:int,events:int,gone:int,samples:string[]}|WP_Error
	 */
	public function run( bool $dry_run = false ) {

		if ( ! class_exists( 'Anime_Sync_YourAnimes_Season_Index' ) || ! class_exists( 'Anime_Sync_Anime_Events' ) ) {
			return new WP_Error( 'missing_dep', '缺少 Season_Index 或 Anime_Events' );
		}

		$html = Anime_Sync_YourAnimes_Season_Index::fetch_html_public( self::URL );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$feed = self::parse_feed( (string) $html );
		if ( empty( $feed ) ) {
			return new WP_Error( 'no_entries', 'recent-updated 解析不到任何條目，頁面可能改版' );
		}

		$r = [ 'entries' => count( $feed ), 'mapped' => 0, 'events' => 0, 'gone' => 0, 'samples' => [] ];

		foreach ( $feed as $ya_id => $item ) {

			$post_id = self::post_for_ya_id( (int) $ya_id );
			if ( ! $post_id ) {
				continue;
			}
			$r['mapped']++;

			/*
			 * 下架標記只看**最新一則**公告。
			 * 實例（正式站 dry-run）：流浪神差 2025-01-01「曼迪授權到期，於串流平台下架」、
			 * 2026-09-11「由曼迪授權，於各平台重新上架」——舊的下架早被新的上架推翻，
			 * 逐則看到「下架」就標會標錯。事件照每則記（那是歷史），gone 只依最新狀態。
			 */
			$latest = null;
			foreach ( $item['news'] as $news ) {
				if ( $latest === null || strcmp( (string) $news['date'], (string) $latest['date'] ) > 0 ) {
					$latest = $news;
				}
			}

			foreach ( $item['news'] as $news ) {

				$date = (string) $news['date'];
				$info = (string) $news['info'];
				if ( $info === '' ) {
					continue;
				}

				$platform = self::platform_from_text( $info );
				$kind     = self::kind_of( $info );

				/*
				 * YA 的所有訊息都收、都直接發布（使用者 2026-09-15 定案：YA 是可信來源，
				 * 這類資訊不需要他審）。中間曾一度只收上架／下架——那是「進待審太多」的問題，
				 * 改成直接發布後就不存在了。分不出類型的（other）仍跳過，不硬塞錯誤標籤。
				 */
				if ( self::event_type_for( $kind ) === '' ) {
					continue;
				}

				// 太舊的不收（見 MAX_AGE_DAYS）
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ( time() - strtotime( $date ) ) > self::MAX_AGE_DAYS * DAY_IN_SECONDS ) {
					continue;
				}

				if ( ! $dry_run ) {
					$id = Anime_Sync_Anime_Events::record( [
						'anime_id'    => $post_id,
						'event_type'  => self::event_type_for( $kind ),
						'fingerprint' => $date . '|' . $info,
						'event_date'  => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
						'summary'     => $info,
						'source'      => 'youranimes',
						'payload'     => [ 'ya_id' => (int) $ya_id, 'kind' => $kind, 'platform' => $platform, 'info' => $info ],
					] );
					if ( $id > 0 ) {
						$r['events']++;
						/*
						 * YA 的上架／下架是編輯查證後寫的事實，文案就是原文，沒有需要人判斷的地方
						 * ——使用者 2026-09-15 明確表示這類不需要他審。直接發布；追番會員通知沿用
						 * 事件系統的 14 天防洗版規則。其他來源（AniList 視覺圖等）不受影響，照走待審。
						 */
						Anime_Sync_Anime_Events::publish( $id, $info, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );
					}
				} else {
					$r['events']++;
				}

				// 最新一則是下架且推得出平台 → 標第 2 輪疑似下架，交給 check_gone() 雙重確認
				if ( $kind === 'gone' && $platform !== '' && $news === $latest && ! $dry_run ) {
					$meta = '_anime_tw_streaming_gone_' . $platform;
					$cur  = (string) get_post_meta( $post_id, $meta, true );
					$has  = (string) get_post_meta( $post_id, 'anime_tw_streaming_url_' . $platform, true ) !== '';
					if ( $has && $cur === '' ) {
						update_post_meta( $post_id, $meta, gmdate( 'Y-m-d' ) . '|2' );
						do_action( 'litespeed_purge_post', $post_id );
						$r['gone']++;
					}
				}

				if ( count( $r['samples'] ) < 12 ) {
					$r['samples'][] = sprintf( '#%d %s ｜%s｜%s%s', $post_id, mb_substr( (string) get_the_title( $post_id ), 0, 18 ), $date, $info, $platform ? "→{$platform}" : '' );
				}
			}
		}

		update_option( self::STATE_OPTION, [ 'ran' => time(), 'entries' => $r['entries'], 'mapped' => $r['mapped'], 'events' => $r['events'] ], false );

		return $r;
	}

	// =====================================================================
	// 純函式：解析與判讀（無 WP 依賴，可離線測試）
	// =====================================================================

	/**
	 * 從頁面 HTML 解出 { ya_id => { name, jp, news:[{date,info}] } }。
	 *
	 * 資料是嵌在 script 裡、跳脫過一到兩層的 JSON 字串。先把 \" 與 \/ 還原，
	 * 再以 {"_id":" 為界切段——每段就是一部作品的欄位，不必完整 JSON decode
	 * （整個 payload 是 Nuxt 的巢狀結構，decode 成本高又易被改版打壞）。
	 *
	 * @return array<int,array{name:string,jp:string,news:array<int,array{date:string,info:string}>}>
	 */
	public static function parse_feed( string $html ): array {

		$s = str_replace( [ '\\\\"', '\\"', '\\/' ], [ '"', '"', '/' ], $html );

		$chunks = preg_split( '/(?=\{"_id":")/u', $s );
		$out    = [];

		foreach ( (array) $chunks as $c ) {

			if ( ! preg_match( '/^\{"_id":"(\d+)"/u', $c, $id ) ) {
				continue;
			}
			if ( ! preg_match( '/"name":"((?:[^"\\\\]|\\\\.)*)"/u', $c, $nm ) ) {
				continue;
			}
			if ( ! preg_match( '/"news":\[((?:\{[^{}]*\},?)*)\]/u', $c, $nw ) ) {
				continue;
			}

			preg_match( '/"jpName":"((?:[^"\\\\]|\\\\.)*)"/u', $c, $jp );
			preg_match_all( '/\{"date":"([^"]*)","info":"((?:[^"\\\\]|\\\\.)*)"/u', $nw[1], $n, PREG_SET_ORDER );

			$news = [];
			foreach ( $n as $x ) {
				$news[] = [ 'date' => trim( $x[1] ), 'info' => trim( stripcslashes( $x[2] ) ) ];
			}

			$out[ (int) $id[1] ] = [
				'name' => stripcslashes( $nm[1] ),
				'jp'   => stripcslashes( $jp[1] ?? '' ),
				'news' => $news,
			];
		}

		return $out;
	}

	/**
	 * 公告分類：
	 *   gone      「下架」                         → 事件類型 streaming ＋ 下架訊號
	 *   live      「上架」                         → streaming
	 *   schedule  「播出／上映／開播／停播／順延」 → 事件類型 schedule（進待審，不自動發）
	 *   other     視覺圖、PV、配音名單…            → 不記，AniList 差異掃描已在管
	 */
	public static function kind_of( string $info ): string {
		if ( preg_match( '/下架/u', $info ) ) {
			return 'gone';
		}
		if ( preg_match( '/上架/u', $info ) ) {
			return 'live';
		}
		// 順序：先認得出具體物件的（影片、圖、人），最後才是時程；一則公告常同時提到圖與播出日
		if ( preg_match( '/宣傳影片|PV|預告|CM|特報|影片/iu', $info ) ) {
			return 'trailer';
		}
		if ( preg_match( '/視覺圖|視覚圖|海報|主視覺|KV/iu', $info ) ) {
			return 'visual';
		}
		if ( preg_match( '/聲優|配音|CAST|演出/iu', $info ) ) {
			return 'cast';
		}
		if ( preg_match( '/動畫化|製作決定|完結|最終季|續篇|新作/u', $info ) ) {
			return 'status';
		}
		if ( preg_match( '/播出|上映|開播|停播|順延|延後|提前|時程|檔期/u', $info ) ) {
			return 'schedule';
		}
		return 'other';
	}

	/**
	 * 從 YA 收哪幾類。
	 *
	 * ★ 只收 AniList 差異掃描給不了的：串流上下架（只有 YA 有）、播出時程（YA 是台灣
	 *   視角，「木棉花宣布 10/16 在台灣上映」AniList 沒有）、播出狀態（動畫化、停播）。
	 *   視覺圖／PV／聲優 AniList 那條已經自動發布、而且有真的圖和影片——YA 再發一則
	 *   純文字的「公開主視覺圖」就是同一件事兩則消息、追番的人收到兩次通知。
	 *   要開回來只需把 key 加進這個陣列。
	 */
	private const PUBLISH_KINDS = [ 'gone' => 'streaming', 'live' => 'streaming', 'schedule' => 'schedule', 'status' => 'status' ];

	/**
	 * kind → Anime_Sync_Anime_Events 的 event_type（全部是既有類型，不新增）；
	 * 不在 PUBLISH_KINDS 的回空字串，呼叫端跳過。
	 */
	public static function event_type_for( string $kind ): string {
		return self::PUBLISH_KINDS[ $kind ] ?? '';
	}

	/** 只收這麼多天內的公告；YA 一部作品會留好幾年的歷史，第一次掃不該把它們全灌進待審。 */
	const MAX_AGE_DAYS = 90;

	/**
	 * 從公告文字推平台 key：用登錄表的 yt_keywords（木棉花→muse、曼迪→mighty、
	 * 羚邦／Ani-One→ani_one、車庫→garageplay…）。推不出回空字串，寧缺勿錯。
	 */
	public static function platform_from_text( string $info ): string {

		if ( ! class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
			return '';
		}

		$hit = '';
		// 對照表是 平台 key → 關鍵字陣列（例：muse → ['Muse','木棉花']）
		foreach ( Anime_Sync_Streaming_Registry::get_youtube_keyword_map() as $key => $keywords ) {
			foreach ( (array) $keywords as $keyword ) {
				// 「YouTube」「Official」這種泛用字不算代理商
				if ( $keyword === '' || in_array( strtolower( (string) $keyword ), [ 'youtube', 'official' ], true ) ) {
					continue;
				}
				if ( mb_stripos( $info, (string) $keyword ) !== false ) {
					if ( $hit !== '' && $hit !== $key ) {
						return '';   // 兩家代理商都出現，分不清
					}
					$hit = (string) $key;
				}
			}
		}

		return $hit;
	}

	// =====================================================================
	// WP 相依
	// =====================================================================

	/**
	 * 站上哪篇作品的 anime_youranimes_url 指向這個 YA id。
	 * 草稿、待審、排程也算——使用者匯入後常先放草稿，公告照樣要進消息審核。
	 * 已發布的優先（同一 YA id 若草稿與正式並存，取正式那篇）。
	 */
	protected static function post_for_ya_id( int $ya_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			   JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'anime'
			    AND p.post_status IN ('publish','draft','pending','future')
			  WHERE pm.meta_key = 'anime_youranimes_url'
			    AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )
			  ORDER BY FIELD(p.post_status,'publish','future','pending','draft'), p.ID DESC
			  LIMIT 1",
			'%/animes/' . $ya_id,
			'%/animes/' . $ya_id . '/%'
		) );
	}

	private static function log( string $level, string $m ): void {
		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::static_log( $level, 'YA 最近更新掃描：' . $m );
		}
	}
}

// =========================================================================
// WP-CLI：wp anime ya-news-scan [--dry-run]
// =========================================================================

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'anime ya-news-scan', function ( $args, $assoc_args ) {
		$dry = isset( $assoc_args['dry-run'] );
		WP_CLI::log( $dry ? '=== YA 最近更新掃描（DRY RUN）===' : '=== YA 最近更新掃描 ===' );
		$r = ( new Anime_Sync_YourAnimes_News_Scan() )->run( $dry );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::log( sprintf( '條目 %d｜對應到站上 %d｜%s事件 %d｜標疑似下架 %d', $r['entries'], $r['mapped'], $dry ? '會建' : '新建', $r['events'], $r['gone'] ) );
		foreach ( $r['samples'] as $s ) {
			WP_CLI::log( '  ' . $s );
		}
		WP_CLI::success( '完成' );
	} );
}
