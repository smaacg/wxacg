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

		// ★ [1.4.0] 維基同步 metabox + 資產 + AJAX
		add_action( 'add_meta_boxes',        [ $this, 'register_wiki_metabox' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_wiki_assets' ]   );
		add_action( 'wp_ajax_anime_sync_manga_wiki_resync', [ $this, 'handle_ajax_wiki_resync' ] );
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
	 * 前端資產（匯入頁 inline JS，免額外檔案）
	 * ============================================================ */
	public function enqueue_assets( string $hook ): void {
		// 只在本頁載入
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION );

		$js = <<<JS
(function(){
	document.addEventListener('DOMContentLoaded', function(){
		var btn    = document.getElementById('asp-manga-import-btn');
		var input  = document.getElementById('asp-manga-anilist-id');
		var bgm    = document.getElementById('asp-manga-bangumi-id');
		var force  = document.getElementById('asp-manga-force');
		var result = document.getElementById('asp-manga-result');
		if(!btn) return;

		btn.addEventListener('click', function(){
			var anilistId = parseInt(input.value, 10);
			if(!anilistId){ result.innerHTML = '<span style="color:#d63638">請輸入 AniList 漫畫 ID</span>'; return; }

			btn.disabled = true;
			result.innerHTML = '匯入中，請稍候…';

			var body = new URLSearchParams();
			body.append('action', 'anime_sync_manga_import_single');
			body.append('nonce', '{$nonce}');
			body.append('anilist_id', anilistId);
			if(bgm && bgm.value){ body.append('bangumi_id', parseInt(bgm.value, 10) || 0); }
			if(force && force.checked){ body.append('force', '1'); }

			fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.json(); })
				.then(function(res){
					btn.disabled = false;
					if(res.success){
						var d = res.data || {};
						var link = d.edit_link ? ('<a href="'+d.edit_link+'">編輯此漫畫</a>') : '';
						var color = d.skipped ? '#dba617' : '#00a32a';
						result.innerHTML = '<span style="color:'+color+'">'+(d.message||'匯入成功')+'</span> '+link;
					} else {
						result.innerHTML = '<span style="color:#d63638">❌ '+((res.data&&res.data.message)||'匯入失敗')+'</span>';
					}
				})
				.catch(function(e){
					btn.disabled = false;
					result.innerHTML = '<span style="color:#d63638">❌ 請求錯誤: '+e.message+'</span>';
				});
		});
	});
})();
JS;

		wp_register_script( 'asp-manga-admin', '', [], ANIME_SYNC_PRO_VERSION, true );
		wp_enqueue_script( 'asp-manga-admin' );
		wp_add_inline_script( 'asp-manga-admin', $js );
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
			'message'   => $result['message'] ?? '漫畫匯入成功',
			'post_id'   => $result['post_id'] ?? 0,
			'edit_link' => $result['edit_url'] ?? '',
			'skipped'   => ! empty( $result['skipped'] ),
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
