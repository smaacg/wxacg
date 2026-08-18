<?php
/**
 * 檔案名稱: admin/class-manga-admin.php
 * 漫畫匯入後台入口（選單頁 + AJAX）
 *
 * @package Anime_Sync_Pro
 * @version 1.4.0
 *
 * Changelog:
 *   1.4.0 (2026-07-19):
 *     - 新增「維基資料同步」metabox（掛 manga 側邊）：一鍵手動觸發
 *       Anime_Sync_Manga_Wiki_Cron::HOOK_SINGLE，立即抓維基/Wikidata/
 *       單行本封面，不必等 5 秒背景 cron 或每週排程。
 *     - 新增獨立 nonce（WIKI_NONCE_ACTION）、AJAX handler、inline JS，
 *       自成一體，不碰動畫 admin.js 與 ACF 欄位。
 *   1.3.0 (2026-07-17):
 *     - 新增 reorder_menu()（admin_menu priority 999）鎖定「動漫同步」子選單順序。
 *   1.2.0 (2026-07-16):
 *     - register_menu() 改掛到主選單 anime-sync-pro；權限對齊 manage_options；
 *       render_page() 改用 include admin/pages/manga-import.php。
 *   1.1.0 (2026-07-16):
 *     - handle_ajax_import_single() 改為對齊 import_single() 的陣列回傳。
 *   1.0.0: 初版。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Manga_Admin {

	/** @var Anime_Sync_Manga_Import_Manager|null */
	private $import_manager;

	/** 選單 slug */
	const PAGE_SLUG = 'anime-sync-manga-import';

	/** nonce action（匯入） */
	const NONCE_ACTION = 'anime_sync_manga_import';

	/** nonce action（維基同步） */
	const WIKI_NONCE_ACTION = 'anime_sync_manga_wiki_resync';

	/** 權限（對齊主選單其他頁） */
	const CAPABILITY = 'manage_options';

	public function __construct( $import_manager = null ) {
		$this->import_manager = $import_manager;

		add_action( 'admin_menu',            [ $this, 'register_menu' ]       );
		add_action( 'admin_menu',            [ $this, 'reorder_menu'  ], 999  ); // ★ 等所有子選單註冊完再重排
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ]      );

		// AJAX: 匯入單一漫畫
		add_action( 'wp_ajax_anime_sync_manga_import_single', [ $this, 'handle_ajax_import_single' ] );

		// AJAX: 熱門漫畫排行（批次匯入用）
		add_action( 'wp_ajax_anime_sync_manga_popularity', [ $this, 'handle_ajax_popularity' ] );

		// AJAX: 從站上動畫的原作關聯抽出漫畫清單
		add_action( 'wp_ajax_anime_sync_manga_from_anime', [ $this, 'handle_ajax_from_anime' ] );

		// ★ [1.4.0] 維基同步 metabox + 資產 + AJAX
		add_action( 'add_meta_boxes',        [ $this, 'register_wiki_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_wiki_assets' ]   );
		add_action( 'wp_ajax_anime_sync_manga_wiki_resync', [ $this, 'handle_ajax_wiki_resync' ] );

		// AJAX: MANGA MILLION 作品對照表（分批爬取，見 class-mangamillion-map.php）
		add_action( 'wp_ajax_anime_sync_mm_start', [ $this, 'handle_ajax_mm_start' ] );
		add_action( 'wp_ajax_anime_sync_mm_batch', [ $this, 'handle_ajax_mm_batch' ] );

		// AJAX: 出版社分類法回填
		add_action( 'wp_ajax_anime_sync_manga_publisher_backfill', [ $this, 'handle_ajax_publisher_backfill' ] );
	}

	/**
	 * AJAX：把既有漫畫的出版社 meta 補建成分類法詞彙。
	 *
	 * 新匯入的作品由 class-manga-wiki-cron.php 自動指派;這支只處理
	 * 分類法建立之前就存在的舊資料。純讀 meta、不打任何外部 API，
	 * 因此可以一次跑完，不需要分批。
	 */
	public function handle_ajax_publisher_backfill(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		if ( ! taxonomy_exists( 'manga_publisher_tax' ) ) {
			wp_send_json_error( [ 'message' => '出版社分類法尚未註冊' ], 500 );
		}

		if ( ! class_exists( 'Anime_Sync_Manga_Import_Manager' ) ) {
			wp_send_json_error( [ 'message' => '匯入管理器未載入' ], 500 );
		}

		$ids = get_posts(
			[
				'post_type'      => 'manga',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$assigned = 0;
		$skipped  = 0;

		foreach ( $ids as $post_id ) {
			$name = Anime_Sync_Manga_Import_Manager::normalize_publisher(
				(string) get_post_meta( $post_id, 'manga_jp_publishers', true )
			);

			if ( '' === $name ) {
				$skipped++;
				continue;
			}

			$term = term_exists( $name, 'manga_publisher_tax' );

			if ( ! $term ) {
				$term = wp_insert_term( $name, 'manga_publisher_tax' );
			}

			if ( is_wp_error( $term ) ) {
				$skipped++;
				continue;
			}

			$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;

			if ( $term_id > 0 ) {
				wp_set_post_terms( $post_id, [ $term_id ], 'manga_publisher_tax' );
				$assigned++;
			}
		}

		$terms = get_terms( [ 'taxonomy' => 'manga_publisher_tax', 'hide_empty' => true ] );

		wp_send_json_success(
			[
				'total'    => count( $ids ),
				'assigned' => $assigned,
				'skipped'  => $skipped,
				'terms'    => is_wp_error( $terms ) ? 0 : count( $terms ),
			]
		);
	}

	/**
	 * AJAX：開始建立 MANGA MILLION 對照表。
	 *
	 * 只負責解析 sitemap 取得待抓清單;實際抓取由前端分批呼叫
	 * handle_ajax_mm_batch()。383 頁一次抓完會撞執行時間上限，
	 * 分批才能顯示進度也才停得下來。
	 */
	public function handle_ajax_mm_start(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		if ( ! class_exists( 'Anime_Sync_MangaMillion_Map' ) ) {
			wp_send_json_error( [ 'message' => '對照表模組未載入' ], 500 );
		}

		$res = Anime_Sync_MangaMillion_Map::start();

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}

		wp_send_json_success( $res );
	}

	/**
	 * AJAX：抓取一個批次。
	 */
	public function handle_ajax_mm_batch(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		if ( ! class_exists( 'Anime_Sync_MangaMillion_Map' ) ) {
			wp_send_json_error( [ 'message' => '對照表模組未載入' ], 500 );
		}

		// 一批 25 頁、每頁間隔 0.7 秒，約 18 秒;放寬本次請求上限留餘裕
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		wp_send_json_success( Anime_Sync_MangaMillion_Map::crawl_batch( $offset ) );
	}

	/* ============================================================
	 * 選單註冊 —— 掛在「動漫同步」主選單底下
	 * ============================================================ */
	public function register_menu(): void {
		add_submenu_page(
			'anime-sync-pro',   // ★ 父選單改成動漫同步主選單
			'漫畫匯入',          // page_title
			'📖 漫畫匯入',       // menu_title（顯示於左側子選單）
			self::CAPABILITY,   // ★ 權限對齊主選單其他頁
			self::PAGE_SLUG,    // anime-sync-manga-import（不變）
			[ $this, 'render_page' ]
		);
	}

	/* ============================================================
	 * 重排子選單順序（1.3.0）
	 *
	 * 目標順序：
	 *   儀表板 → 動漫匯入 → 漫畫匯入 → 審核佇列 → 查看動漫 → 錯誤日誌 → 插件設定
	 * ============================================================ */
	public function reorder_menu(): void {
		global $submenu;

		$parent = 'anime-sync-pro';
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$order = [
			'anime-sync-pro',        // 儀表板
			'anime-sync-import',     // 動漫匯入
			self::PAGE_SLUG,         // 漫畫匯入 = anime-sync-manga-import
			'anime-sync-queue',      // 審核佇列
			'anime-sync-published',  // 查看動漫
			'anime-sync-logs',       // 錯誤日誌
			'anime-sync-settings',   // 插件設定
		];

		$rank = array_flip( $order );

		usort( $submenu[ $parent ], function ( $a, $b ) use ( $rank ) {
			// $a[2] / $b[2] 為 menu slug
			$ra = $rank[ $a[2] ] ?? 999;
			$rb = $rank[ $b[2] ] ?? 999;
			return $ra <=> $rb;
		} );
	}

	/* ============================================================
	 * 前端資產（匯入頁 JS）
	 * ============================================================ */
	public function enqueue_assets( string $hook ): void {
		// 只在本頁載入
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		/*
		 * v2.0.0：原本整段 JS 內嵌在此處的 heredoc 裡。加入批次匯入後
		 * 長度會讓這個函式難以維護，因此改為載入獨立檔案。
		 */
		// 常數名與 render_page() 一致，避免兩處各用一個而其中一個未定義。
		$rel  = 'admin/assets/js/manga-import.js';
		$base = defined( 'ANIME_SYNC_PRO_DIR' ) ? ANIME_SYNC_PRO_DIR : plugin_dir_path( dirname( __FILE__ ) );
		$url  = defined( 'ANIME_SYNC_PRO_URL' ) ? ANIME_SYNC_PRO_URL : plugin_dir_url( dirname( __FILE__ ) );
		$abs  = trailingslashit( $base ) . $rel;

		if ( ! file_exists( $abs ) ) {
			return;
		}

		wp_enqueue_script(
			'asp-manga-admin',
			trailingslashit( $url ) . $rel,
			[],
			filemtime( $abs ),
			true
		);

		wp_localize_script( 'asp-manga-admin', 'aspMangaImport', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		] );
	}

	/* ============================================================
	 * 選單頁渲染 —— include admin/pages/manga-import.php
	 * ============================================================ */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '權限不足' );
		}

		$base_dir  = defined( 'ANIME_SYNC_PRO_DIR' )
			? ANIME_SYNC_PRO_DIR
			: plugin_dir_path( dirname( __FILE__ ) );
		$file_path = $base_dir . 'admin/pages/manga-import.php';

		if ( file_exists( $file_path ) ) {
			include $file_path;
		} else {
			echo '<div class="wrap"><div class="notice notice-error"><p>找不到頁面：<code>admin/pages/manga-import.php</code></p></div></div>';
		}
	}

	/* ============================================================
	 * AJAX：匯入單一漫畫
	 * ============================================================ */
	public function handle_ajax_import_single(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		if ( ! $this->import_manager || ! method_exists( $this->import_manager, 'import_single' ) ) {
			wp_send_json_error( [ 'message' => '漫畫匯入管理器未就緒（請確認 ACF 已啟用）' ], 500 );
		}

		$anilist_id = isset( $_POST['anilist_id'] ) ? absint( $_POST['anilist_id'] ) : 0;
		$bangumi_id = isset( $_POST['bangumi_id'] ) ? absint( $_POST['bangumi_id'] ) : 0;
		$force      = ! empty( $_POST['force'] );

		if ( ! $anilist_id ) {
			wp_send_json_error( [ 'message' => '缺少 AniList 漫畫 ID' ], 400 );
		}

		// 單部匯入現在含維基/Wikidata 抓取，正常約 10 秒，但外部 API 慢的時候
		// 可能拖到主機預設的執行時間上限。這裡放寬本次請求的上限；
		// set_time_limit 不在主機的 disable_functions 內，可正常使用。
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		// import_single( anilist_id, bangumi_id, source, args ) → 回傳陣列
		$result = $this->import_manager->import_single(
			$anilist_id,
			$bangumi_id ?: null,
			'manual',
			[ 'force' => $force ]
		);

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [
				'message' => $result['message'] ?? '匯入失敗',
			] );
		}

		wp_send_json_success( [
			'message'     => $result['message'] ?? '漫畫匯入成功',
			'post_id'     => $result['post_id'] ?? 0,
			'edit_link'   => $result['edit_url'] ?? '',
			'skipped'     => ! empty( $result['skipped'] ),
			// 維基抓取結果（xxx:ok / xxx:no_data），讓前端 log 當場顯示完整度
			'wiki_status' => $result['wiki_status'] ?? '',
		] );
	}

	/**
	 * AJAX：取得 AniList 熱門漫畫排行。
	 *
	 * 供批次匯入頁勾選用；實際匯入仍走 handle_ajax_import_single()，
	 * 一次一部，前端以佇列逐筆送出。
	 */
	public function handle_ajax_popularity(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) {
			wp_send_json_error( [ 'message' => 'API Handler 未載入' ], 500 );
		}

		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		$api    = new Anime_Sync_API_Handler();
		$result = $api->fetch_anilist_manga_popularity( $page );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX：從站上動畫的 relations 抽出「原作漫畫」清單。
	 *
	 * 這個來源比全球人氣榜更適合本站：
	 *   · 每一部都對應站上已有的動畫 → 匯入後 link_related_anime() 一定配得到，
	 *     系列 term 自動繼承，不必手動設定
	 *   · 動畫化過的作品，台灣代理率明顯較高 → 才有台版 ISBN，
	 *     聯盟行銷連結才生得出來
	 *   · 動畫與漫畫互相連結，對站內連結結構有利
	 *
	 * 資料全部來自本地 postmeta，不打任何外部 API，因此可一次回傳全部。
	 */
	public function handle_ajax_from_anime(): void {

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		global $wpdb;

		// 1. 取出所有動畫的關聯資料與人氣（人氣用來排序，先做的比較有價值）
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, r.meta_value AS relations,
			        COALESCE( pop.meta_value, 0 ) AS popularity
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} r ON r.post_id = p.ID
			                          AND r.meta_key = 'anime_relations_json'
			                          AND r.meta_value <> ''
			 LEFT JOIN {$wpdb->postmeta} pop ON pop.post_id = p.ID
			                                 AND pop.meta_key = 'anime_popularity'
			 WHERE p.post_type = 'anime' AND p.post_status = 'publish'"
		);

		// 2. 已匯入的漫畫 AniList ID
		$existing = [];

		foreach ( (array) $wpdb->get_results(
			"SELECT m.post_id, m.meta_value AS aid FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'manga'
			 WHERE m.meta_key = 'anime_anilist_id'"
		) as $e ) {
			$existing[ (int) $e->aid ] = (int) $e->post_id;
		}

		// 3. 抽出 ADAPTATION／SOURCE 且型別為 MANGA 的關聯
		$found = [];

		foreach ( $rows as $row ) {
			$rels = json_decode( (string) $row->relations, true );

			if ( ! is_array( $rels ) ) {
				continue;
			}

			foreach ( $rels as $rel ) {
				if ( 'MANGA' !== ( $rel['type'] ?? '' ) ) {
					continue;
				}

				if ( ! in_array( $rel['relation_type'] ?? '', [ 'ADAPTATION', 'SOURCE' ], true ) ) {
					continue;
				}

				$aid = (int) ( $rel['id'] ?? 0 );

				if ( $aid <= 0 ) {
					continue;
				}

				/*
				 * 同一部漫畫可能被多部動畫（各季）指向。保留人氣最高的那部
				 * 作為代表來源，排序才反映真正的重要性。
				 */
				if ( isset( $found[ $aid ] ) && $found[ $aid ]['popularity'] >= (int) $row->popularity ) {
					$found[ $aid ]['anime_count']++;
					continue;
				}

				$found[ $aid ] = [
					'anilist_id'  => $aid,
					'title'       => (string) ( $rel['title'] ?? '' ),
					'anime_id'    => (int) $row->ID,
					'anime_title' => (string) $row->post_title,
					'popularity'  => (int) $row->popularity,
					'anime_count' => isset( $found[ $aid ] ) ? $found[ $aid ]['anime_count'] + 1 : 1,
				];
			}
		}

		// 4. 依代表動畫的人氣排序
		uasort( $found, static fn( $a, $b ) => $b['popularity'] <=> $a['popularity'] );

		$items    = [];
		$todo     = 0;

		foreach ( $found as $aid => $item ) {
			$post_id = $existing[ $aid ] ?? 0;

			if ( ! $post_id ) {
				$todo++;
			}

			$item['imported'] = $post_id > 0;
			$item['post_id']  = $post_id;
			$item['edit_url'] = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';
			$item['anime_url'] = get_permalink( $item['anime_id'] );

			$items[] = $item;
		}

		wp_send_json_success( [
			'total'    => count( $items ),
			'todo'     => $todo,
			'items'    => $items,
		] );
	}

	/* ============================================================
	 * ★ [1.4.0] Meta Box:重新抓取維基資料（只在 manga 編輯頁）
	 * ============================================================ */
	public function register_wiki_metabox(): void {
		add_meta_box(
			'manga_wiki_resync',
			'🔄 維基資料同步',
			[ $this, 'render_wiki_metabox' ],
			'manga',
			'side',
			'default'
		);
	}

	public function render_wiki_metabox( WP_Post $post ): void {
		$last_sync   = get_post_meta( $post->ID, 'manga_wiki_last_sync',   true );
		$last_status = get_post_meta( $post->ID, 'manga_wiki_last_status', true );
		$wiki_url    = get_post_meta( $post->ID, 'manga_wikipedia_url',    true );
		?>
		<div id="manga-wiki-resync-wrap">
			<?php if ( $last_sync ) : ?>
				<p style="margin:0 0 6px;font-size:11px;color:#666;">
					上次同步：<?php echo esc_html( $last_sync ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $last_status ) : ?>
				<p style="margin:0 0 6px;font-size:11px;color:#666;">
					狀態：<?php echo esc_html( $last_status ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $wiki_url ) : ?>
				<p style="margin:0 0 8px;font-size:11px;">
					<a href="<?php echo esc_url( $wiki_url ); ?>" target="_blank" rel="noopener">🔗 目前維基頁</a>
				</p>
			<?php else : ?>
				<p style="margin:0 0 8px;font-size:11px;color:#999;">尚未取得維基頁。</p>
			<?php endif; ?>

			<button
				type="button"
				id="manga-wiki-resync-btn"
				class="button button-secondary"
				style="width:100%;"
			>🔄 重新抓取維基資料</button>
			<p id="manga-wiki-resync-msg" style="margin:8px 0 0;min-height:20px;font-size:12px;"></p>
			<p style="margin:6px 0 0;font-size:11px;color:#999;">
				會重抓維基百科出版章節、Wikidata（出版社／雜誌／每卷 ISBN／獲獎）與單行本封面牆。需先儲存草稿取得文章 ID。
			</p>
		</div>
		<?php
	}

	/* ============================================================
	 * ★ [1.4.0] 資產：維基同步按鈕 inline JS（只在 manga 編輯頁）
	 * ============================================================ */
	public function enqueue_wiki_assets( string $hook ): void {
		// 只在 post 編輯 / 新增畫面
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'manga' ) {
			return;
		}

		$nonce = wp_create_nonce( self::WIKI_NONCE_ACTION );

		$js = <<<JS
(function(){
	document.addEventListener('DOMContentLoaded', function(){
		var btn = document.getElementById('manga-wiki-resync-btn');
		var msg = document.getElementById('manga-wiki-resync-msg');
		if(!btn) return;

		btn.addEventListener('click', function(){
			var postId = document.getElementById('post_ID')
				? document.getElementById('post_ID').value
				: (new URLSearchParams(window.location.search)).get('post');

			if(!postId){
				msg.style.color = '#d63638';
				msg.textContent = '請先儲存草稿以取得文章 ID，再執行同步。';
				return;
			}

			btn.disabled = true;
			msg.style.color = '#666';
			msg.textContent = '同步中，請稍候…（維基 + Wikidata + 封面約需數秒）';

			var body = new URLSearchParams();
			body.append('action', 'anime_sync_manga_wiki_resync');
			body.append('nonce', '{$nonce}');
			body.append('post_id', postId);

			fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.json(); })
				.then(function(res){
					if(res && res.success){
						msg.style.color = '#00a32a';
						msg.textContent = '✅ ' + ((res.data && res.data.message) || '同步完成') + '，頁面即將重新整理…';
						setTimeout(function(){ location.reload(); }, 1500);
					} else {
						btn.disabled = false;
						msg.style.color = '#d63638';
						var m = (res && res.data && res.data.message) ? res.data.message : '同步失敗';
						msg.textContent = '❌ ' + m;
					}
				})
				.catch(function(e){
					btn.disabled = false;
					msg.style.color = '#d63638';
					msg.textContent = '❌ 請求錯誤: ' + e.message;
				});
		});
	});
})();
JS;

		wp_register_script( 'asp-manga-wiki', '', [], ANIME_SYNC_PRO_VERSION, true );
		wp_enqueue_script( 'asp-manga-wiki' );
		wp_add_inline_script( 'asp-manga-wiki', $js );
	}

	/* ============================================================
	 * ★ [1.4.0] AJAX：手動觸發維基同步
	 * ============================================================ */
	public function handle_ajax_wiki_resync(): void {

		check_ajax_referer( self::WIKI_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '權限不足' ], 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || get_post_type( $post_id ) !== 'manga' ) {
			wp_send_json_error( [ 'message' => '無效的漫畫文章 ID' ], 400 );
		}

		if ( ! class_exists( 'Anime_Sync_Manga_Wiki_Cron' ) ) {
			wp_send_json_error( [ 'message' => '維基同步模組未載入' ], 500 );
		}

		// 直接同步執行（不排 cron），讓使用者立刻看到結果。
		do_action( Anime_Sync_Manga_Wiki_Cron::HOOK_SINGLE, $post_id, 'manual' );

		// 回傳最新狀態
		$last_status = get_post_meta( $post_id, 'manga_wiki_last_status', true );
		$last_sync   = get_post_meta( $post_id, 'manga_wiki_last_sync',   true );

		wp_send_json_success( [
			'message' => sprintf(
				'維基同步完成（%s / %s）',
				$last_status !== '' ? $last_status : '無狀態',
				$last_sync   !== '' ? $last_sync   : '無時間'
			),
		] );
	}
}
