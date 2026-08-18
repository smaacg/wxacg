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
		<p>取自 AniList 熱門漫畫排行，每次 50 部。已匯入的會標示並預設不勾選。</p>

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
</div>
