<?php
/**
 * 檔案名稱: includes/class-streaming-source-linetv.php
 * LINE TV — 直接抓取來源（分批增量爬作品頁）
 *
 * 為什麼要逐頁爬
 * --------------
 * 2026-09-15 從正式站主機實測：
 *   - sitemap_drama.xml 有 7,142 個 /drama/{id}，但只有網址、沒有標題、沒有 lastmod
 *   - channel/2 是動畫頻道，但頻道頁與各 genre 頁都只內嵌同一份 57 部預設內容
 *     （SPA 退回渲染），沒有伺服器端全目錄
 *   - 作品頁 500KB，但 Range: bytes=0-65535 回 206，前 64KB 就有
 *     <title data-react-helmet>作品名相關影音｜免費線上看｜LINE TV-精彩隨看</title>
 *     與 "genres":[{"key":"動畫"…}]，能判斷是不是動畫
 *
 * 所以只能一頁一頁抓標題，但每頁只拿 64KB、每輪只做一批、進度存 option 續跑。
 * 首輪 7,142 頁約 450MB、每小時 300 頁一天抓完；之後每小時只補 sitemap 裡新出現的 ID。
 *
 * 只有 genres 含「動畫／動漫」的才進索引，其餘（戲劇、電影、綜藝）標成已抓、不留。
 * 標題以「(國語)」「(中配)」開頭的是中文配音版，另有一個 drama id，跳過不進索引
 * （站上主網址要原音版；中配欄位本輪不寫）。
 *
 * 尾綴有兩種寫法：「…相關影音｜免費線上看｜LINE TV-精彩隨看」與「…相關影音｜線上看｜…」，
 * 一律從「相關影音｜」起砍掉。
 *
 * 排程每小時（recurrence()），索引增量合併（incremental()），詳見基底。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Source_Linetv extends Anime_Sync_Streaming_Source_Base {

	const SITEMAP_URL    = 'https://www.linetv.tw/sitemap_drama.xml';
	const DRAMA_URL      = 'https://www.linetv.tw/drama/%d';
	const QUEUE_OPTION   = 'anime_sync_src_queue_linetv';

	/** 每輪最多處理幾頁；配合 0.5 秒間隔與 200 秒時間預算，實際約 300 頁 */
	const BATCH          = 300;
	const PAGE_INTERVAL_US = 500000;
	const RANGE_BYTES    = 65536;

	/** 佇列空了之後，隔多久才重抓 sitemap 找新 ID（sitemap 沒 lastmod，只能整份比對） */
	const SITEMAP_REFRESH = 6 * HOUR_IN_SECONDS;

	/** 連續失敗這麼多頁就停本輪，剩下的留給下一輪 */
	const ABORT_AFTER = 10;

	public function key(): string {
		return 'linetv';
	}

	/** 作品頁從主機打得到：存在 200、不存在 404（2026-09-16 實測）。 */
	protected function provides_alive_check(): bool {
		return true;
	}

	/**
	 * 覆核批次調小：本來每小時就要爬 BATCH（300）頁作品頁建索引，
	 * 再加 150 頁覆核等於一小時對 LINE TV 打 450 次。每小時 40 筆，
	 * 792 筆網址約 20 小時輪完一圈，已經比其他家都密。
	 */
	protected function recheck_batch(): int {
		return 40;
	}

	protected function sitemap_url(): string {
		return self::SITEMAP_URL;
	}

	protected function incremental(): bool {
		return true;
	}

	protected function recurrence(): string {
		return 'hourly';
	}

	/** 佇列清空（首輪爬完、之後每次補新 ID 也清空）才算完整快照，否則沒爬到的會被當下架。 */
	protected function index_is_complete(): bool {
		$q = $this->queue();
		return $q['total'] > 0 && empty( $q['pending'] );
	}

	/** 條目由 collect_entries() 直接組好，這裡不會被呼叫。 */
	protected function parse_entry( string $block ): ?array {
		return null;
	}

	protected function work_name( string $title ): string {
		$t = (string) preg_replace( '/相關影音｜.*$/u', '', trim( $title ) );
		return trim( $t );
	}

	/**
	 * 覆寫收集：從佇列取一批作品頁抓標題。
	 *
	 * @param float $started
	 * @return array{0:array<string,array>,1:int}|WP_Error
	 */
	protected function collect_entries( float $started ) {

		$q = $this->queue();

		// ── 佇列空了：重抓 sitemap 找還沒抓過的 ID ──
		if ( empty( $q['pending'] ) ) {

			if ( $q['sitemap_at'] > 0 && ( time() - $q['sitemap_at'] ) < self::SITEMAP_REFRESH ) {
				// 冷卻期內沒新工作，直接回空批；增量合併會保住既有索引
				return [ [], 0 ];
			}

			$xml = $this->fetch( self::SITEMAP_URL );
			if ( is_wp_error( $xml ) ) {
				return $xml;
			}

			if ( ! preg_match_all( '#<loc>\s*https://www\.linetv\.tw/drama/(\d+)\s*</loc>#i', (string) $xml, $m ) ) {
				return new WP_Error( 'no_ids', 'sitemap_drama.xml 解析不到任何 drama id，平台可能改版' );
			}

			$ids = array_map( 'intval', $m[1] );
			// 新的先抓：LINE TV 的 id 遞增，大的就是新上架的
			rsort( $ids );

			$pending = [];
			foreach ( $ids as $id ) {
				if ( ! isset( $q['done'][ $id ] ) ) {
					$pending[] = $id;
				}
			}

			$q['pending']    = $pending;
			$q['sitemap_at'] = time();
			$q['total']      = count( $ids );
			$this->save_queue( $q );

			if ( empty( $pending ) ) {
				return [ [], 0 ];
			}
		}

		// ── 抓一批 ──
		$grouped   = [];
		$entries   = 0;
		$processed = 0;
		$failures  = 0;

		while ( ! empty( $q['pending'] ) && $processed < self::BATCH ) {

			if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				break;
			}

			$id = (int) array_shift( $q['pending'] );
			$processed++;

			if ( $processed > 1 ) {
				usleep( self::PAGE_INTERVAL_US );
			}

			$html = $this->fetch( sprintf( self::DRAMA_URL, $id ), [ 'range_bytes' => self::RANGE_BYTES, 'accept' => 'text/html' ] );

			if ( is_wp_error( $html ) ) {
				if ( $html->get_error_code() === 'circuit_open' ) {
					array_unshift( $q['pending'], $id );
					$this->save_queue( $q );
					return $html;
				}
				// 這一頁放回佇列尾端下輪再試；連續失敗太多就停
				$q['pending'][] = $id;
				if ( ++$failures >= self::ABORT_AFTER ) {
					break;
				}
				continue;
			}

			$failures = 0;
			$q['done'][ $id ] = 1;

			$parsed = $this->parse_page( (string) $html );
			if ( $parsed === null ) {
				continue;   // 不是動畫、或中配版、或抓不到標題：標已抓，不進索引
			}

			$entries++;
			$this->add_entry( $grouped, $parsed['title'], [ 'title' => $parsed['title'], 'url' => sprintf( self::DRAMA_URL, $id ), 'date' => '' ] );

			// 每 10 頁存一次進度，被砍最多只損失這麼多（title-index 的教訓）
			if ( $processed % 10 === 0 ) {
				$this->save_queue( $q );
			}
		}

		$this->save_queue( $q );

		return [ $grouped, $entries ];
	}

	/**
	 * 從作品頁前 64KB 取作品名；非動畫或中配版回 null。
	 *
	 * @return array{title:string}|null
	 */
	protected function parse_page( string $html ): ?array {

		if ( ! preg_match( '#<title[^>]*>([^<]*)</title>#i', $html, $m ) ) {
			return null;
		}

		$title = html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = $this->work_name( $title );

		if ( $title === '' ) {
			return null;
		}

		// 中文配音版另有 id，跳過
		if ( preg_match( '/^[（(]\s*(?:國語|中配|中文配音|台語)\s*[）)]/u', $title ) ) {
			return null;
		}

		// 只收動畫：genres 裡要有「動畫」或「動漫」
		if ( ! preg_match( '/"genres":\[(.*?)\]/su', $html, $g ) ) {
			return null;
		}
		if ( ! preg_match( '/"key":"(?:動畫|動漫)"/u', $g[1] ) ) {
			return null;
		}

		return [ 'title' => $title ];
	}

	// ── 佇列狀態 ──

	/** @return array{pending:int[],done:array<int,int>,sitemap_at:int,total:int} */
	protected function queue(): array {
		$q = get_option( self::QUEUE_OPTION, [] );
		return [
			'pending'    => is_array( $q['pending'] ?? null ) ? $q['pending'] : [],
			'done'       => is_array( $q['done'] ?? null ) ? $q['done'] : [],
			'sitemap_at' => (int) ( $q['sitemap_at'] ?? 0 ),
			'total'      => (int) ( $q['total'] ?? 0 ),
		];
	}

	protected function save_queue( array $q ): void {
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/** 給 --status 用：抓取進度。 */
	public function progress(): array {
		$q = $this->queue();
		return [ 'done' => count( $q['done'] ), 'pending' => count( $q['pending'] ), 'total' => $q['total'] ];
	}
}
