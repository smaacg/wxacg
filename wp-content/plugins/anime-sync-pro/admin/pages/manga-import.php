<?php
/**
 * 檔案名稱: admin/pages/manga-import.php
 * 漫畫匯入頁面 HTML
 *
 * 由 Anime_Sync_Manga_Admin::render_page() 透過 include 載入。
 * 對應的 JS 為 admin/assets/js/manga-import.js（由 enqueue_assets() 載入）。
 *
 * @package Anime_Sync_Pro
 * @version 2.0.0
 *
 * v2.0.0（2026-08-18）：新增批次匯入。
 *   原本只有單筆輸入框，要建庫存得一部一部貼。加入兩種批次方式：
 *     · ID 清單  —— 已知道要哪些作品時直接貼一串
 *     · 熱門排行 —— 從 AniList 熱門漫畫勾選，適合從零建立
 *   兩者都走同一個佇列：一次送一部，逐筆顯示結果，可中途停止。
 *   刻意不做「季度」與「系列分析」——漫畫沒有季度概念，AniList 的漫畫
 *   續作關聯也很稀疏，做了沒有實益。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 權限已於 render_page() 檢查過，此處為雙保險。
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( '權限不足' );
}
?>
<div class="wrap asp-manga-import">
	<h1>📖 漫畫匯入</h1>

	<h2 class="nav-tab-wrapper asp-mi-tabs">
		<a href="#" class="nav-tab nav-tab-active" data-tab="single">✏️ 單筆匯入</a>
		<a href="#" class="nav-tab" data-tab="batch">📋 ID 清單</a>
		<a href="#" class="nav-tab" data-tab="fromanime">🔗 從動畫原作</a>
		<a href="#" class="nav-tab" data-tab="ranking">🏆 熱門排行</a>
	</h2>

	<?php /* ── 分頁 1：單筆 ── */ ?>
	<div class="asp-mi-panel" data-panel="single">
		<p>輸入 AniList 漫畫 ID（網址 anilist.co/manga/<strong>147149</strong> 中的數字）。Bangumi ID 選填，用於補中文標題、簡介與台版資訊。</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="asp-manga-anilist-id">AniList 漫畫 ID <span style="color:#d63638">*</span></label>
				</th>
				<td>
					<input type="number" id="asp-manga-anilist-id" class="regular-text" placeholder="例如 147149">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="asp-manga-bangumi-id">Bangumi ID（選填）</label>
				</th>
				<td>
					<input type="number" id="asp-manga-bangumi-id" class="regular-text" placeholder="例如 445083">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="asp-manga-force">強制覆蓋</label>
				</th>
				<td>
					<label>
						<input type="checkbox" id="asp-manga-force">
						已存在時仍重新抓取並覆蓋（會保留已鎖定欄位與手填台版資訊）
					</label>
				</td>
			</tr>
		</table>

		<p>
			<button type="button" class="button button-primary" id="asp-manga-import-btn">開始匯入</button>
		</p>

		<div id="asp-manga-result" style="margin-top:15px;font-size:14px;"></div>
	</div>

	<?php /* ── 分頁 2：ID 清單 ── */ ?>
	<div class="asp-mi-panel" data-panel="batch" style="display:none;">
		<p>一行一個或以逗號分隔的 AniList 漫畫 ID。重複的會自動去除。</p>

		<textarea id="asp-mi-batch-ids" rows="8" class="large-text code"
			placeholder="147149&#10;132029&#10;85143"></textarea>

		<p>
			<span id="asp-mi-batch-count" class="description">0 個 ID</span>
		</p>

		<p>
			<label>
				<input type="checkbox" class="asp-mi-force">
				強制覆蓋已存在的作品
			</label>
		</p>

		<p>
			<button type="button" class="button button-primary asp-mi-run" data-source="batch">開始批次匯入</button>
			<button type="button" class="button asp-mi-stop" style="display:none;">停止</button>
		</p>
	</div>

	<?php /* ── 分頁 3：從動畫原作 ── */ ?>
	<div class="asp-mi-panel" data-panel="fromanime" style="display:none;">
		<p>
			掃描站上動畫的關聯資料，列出它們的<strong>原作漫畫</strong>。
			這些作品匯入後會自動對應到既有動畫、繼承系列，而且動畫化過的作品
			台灣代理率較高，比較有機會取得台版 ISBN。
		</p>
		<p class="description">資料取自本地資料庫，不會呼叫外部 API，載入很快。</p>

		<p>
			<button type="button" class="button" id="asp-mi-fa-load">掃描原作漫畫</button>
			<span id="asp-mi-fa-status" class="description" style="margin-left:8px;"></span>
		</p>

		<div id="asp-mi-fa-wrap" style="display:none;">
			<p>
				<label><input type="checkbox" id="asp-mi-fa-all"> 全選／全不選（僅未匯入）</label>
				<label style="margin-left:16px;">
					<input type="checkbox" class="asp-mi-force">
					強制覆蓋已存在的作品
				</label>
				<label style="margin-left:16px;">
					限前
					<input type="number" id="asp-mi-fa-limit" value="50" min="1" max="1000" style="width:70px;">
					部（依對應動畫的人氣排序）
					<button type="button" class="button button-small" id="asp-mi-fa-applylimit">套用</button>
				</label>
				<strong id="asp-mi-fa-picked" style="margin-left:16px;">已勾選 0 部</strong>
			</p>

			<div style="max-height:460px;overflow:auto;border:1px solid #c3c4c7;background:#fff;">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"></td>
							<th style="width:90px;">AniList</th>
							<th>漫畫</th>
							<th>對應動畫</th>
							<th style="width:90px;">動畫人氣</th>
							<th style="width:90px;">狀態</th>
						</tr>
					</thead>
					<tbody id="asp-mi-fa-tbody"></tbody>
				</table>
			</div>

			<p style="margin-top:12px;">
				<button type="button" class="button button-primary asp-mi-run" data-source="fromanime">匯入勾選的作品</button>
				<button type="button" class="button asp-mi-stop" style="display:none;">停止</button>
			</p>
		</div>
	</div>

	<?php /* ── 分頁 4：熱門排行 ── */ ?>
	<div class="asp-mi-panel" data-panel="ranking" style="display:none;">
		<p>取自 AniList 熱門漫畫排行，每次 50 部。已匯入的會標示，要重抓可自行勾選並開啟強制覆蓋。</p>
		<p class="description">預設全部不勾選——請用下方的「勾選前 N 部」或自行點選，避免不小心送出整批。</p>

		<p>
			<button type="button" class="button" id="asp-mi-rank-load">載入排行</button>
			<button type="button" class="button" id="asp-mi-rank-more" style="display:none;">載入更多</button>
			<span id="asp-mi-rank-status" class="description" style="margin-left:8px;"></span>
		</p>

		<div id="asp-mi-rank-wrap" style="display:none;">
			<p>
				<label><input type="checkbox" id="asp-mi-rank-all"> 全選／全不選（僅未匯入）</label>
				<label style="margin-left:16px;">
					<input type="checkbox" class="asp-mi-force">
					強制覆蓋已存在的作品
				</label>
				<label style="margin-left:16px;">
					勾選前
					<input type="number" id="asp-mi-rank-limit" value="5" min="1" max="1000" style="width:70px;">
					部（依排行順序）
					<button type="button" class="button button-small" id="asp-mi-rank-applylimit">套用</button>
				</label>
				<strong id="asp-mi-rank-picked" style="margin-left:16px;">已勾選 0 部</strong>
			</p>

			<div style="max-height:460px;overflow:auto;border:1px solid #c3c4c7;background:#fff;">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"></td>
							<th style="width:90px;">AniList</th>
							<th>標題</th>
							<th style="width:90px;">形式</th>
							<th style="width:70px;">卷數</th>
							<th style="width:90px;">人氣</th>
							<th style="width:90px;">狀態</th>
						</tr>
					</thead>
					<tbody id="asp-mi-rank-tbody"></tbody>
				</table>
			</div>

			<p style="margin-top:12px;">
				<button type="button" class="button button-primary asp-mi-run" data-source="ranking">匯入勾選的作品</button>
				<button type="button" class="button asp-mi-stop" style="display:none;">停止</button>
			</p>
		</div>
	</div>

	<?php /* ── 批次共用：進度與紀錄 ── */ ?>
	<div id="asp-mi-progress-wrap" style="display:none;margin-top:20px;">
		<div style="background:#dcdcde;border-radius:3px;height:18px;overflow:hidden;">
			<div id="asp-mi-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
		</div>
		<p id="asp-mi-progress-text" style="margin:6px 0;font-weight:600;"></p>
		<div id="asp-mi-log" style="max-height:320px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:10px;font-family:monospace;font-size:12px;line-height:1.6;border-radius:3px;"></div>

		<?php /* 失敗清單與重跑按鈕，由 JS 於批次結束時填入 */ ?>
		<div id="asp-mi-failed" style="display:none;margin-top:14px;padding:12px;border-left:4px solid #d63638;background:#fcf0f1;"></div>
	</div>

	<?php
	/*
	 * MANGA MILLION 對照表。
	 *
	 * 這不是匯入方式，是「線上看」區塊的資料來源，因此獨立成一塊放在頁尾，
	 * 不佔用上方的分頁。
	 */
	$asp_mm_stats = class_exists( 'Anime_Sync_MangaMillion_Map' )
		? Anime_Sync_MangaMillion_Map::stats()
		: [ 'updated' => '', 'titles' => 0, 'keys' => 0 ];
	?>
	<hr style="margin:32px 0 24px;">

	<h2>🔗 MANGA MILLION 對照表</h2>

	<p>
		MANGA MILLION 是集英社官方的免費線上看服務（免註冊、有中文）。
		它的站內搜尋是瀏覽器端算的，產不出搜尋連結，因此改為預先抓一份
		「作品名 → 網址」對照表，漫畫頁的「線上看」區塊會自動比對。
	</p>
	<p class="description">
		比對方式:對方的簡體名會先轉繁，再與本站的中文／英文／羅馬字標題逐一比對。
		比對不到的多半是別家出版社的作品（講談社、史克威爾艾尼克斯等），
		本來就不會在這個平台上。
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">目前狀態</th>
			<td>
				<?php if ( $asp_mm_stats['updated'] ) : ?>
					<strong><?php echo esc_html( number_format( $asp_mm_stats['titles'] ) ); ?></strong> 部作品、
					<strong><?php echo esc_html( number_format( $asp_mm_stats['keys'] ) ); ?></strong> 個比對鍵
					<span class="description">（上次更新:<?php echo esc_html( $asp_mm_stats['updated'] ); ?>）</span>
				<?php else : ?>
					<span style="color:#d63638;">尚未建立</span>
					<span class="description">—— 建立之前，漫畫頁不會出現 MANGA MILLION 這一格</span>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<p>
		<button type="button" class="button button-primary" id="asp-mm-run">建立／更新對照表</button>
		<button type="button" class="button" id="asp-mm-stop" style="display:none;">停止</button>
		<span id="asp-mm-status" class="description" style="margin-left:8px;"></span>
	</p>
	<p class="description">
		約 380 頁，每頁間隔 0.7 秒，全程約 5～6 分鐘。期間請保持此分頁開啟。
		對方的作品目錄不會天天變動，<strong>不需要經常重跑</strong>，幾個月一次即可。
	</p>

	<div id="asp-mm-progress-wrap" style="display:none;margin-top:12px;max-width:720px;">
		<div style="background:#dcdcde;border-radius:3px;height:18px;overflow:hidden;">
			<div id="asp-mm-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
		</div>
		<p id="asp-mm-progress-text" style="margin:6px 0 0;font-weight:600;"></p>
	</div>

	<hr style="margin:32px 0 24px;">

	<h2>🏢 出版社分類法</h2>

	<p>
		漫畫列表頁的「出版社」瀏覽區用的是分類法封存頁，與動畫列表的季度區同一套機制。
		新匯入的作品會在維基資料抓取完成後自動指派，<strong>這個按鈕只用來補既有的舊資料</strong>。
	</p>
	<p class="description">
		純讀既有欄位、不打任何外部 API，一秒內完成。
		抓不到出版社、或標示為個人出版的作品會略過，不建立「未知」這種佔位詞。
	</p>

	<p>
		<button type="button" class="button" id="asp-pub-backfill">回填出版社分類法</button>
		<span id="asp-pub-status" class="description" style="margin-left:8px;"></span>
	</p>
</div>
