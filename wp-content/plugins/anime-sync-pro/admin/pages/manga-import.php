<?php
/**
 * 檔案名稱: admin/pages/manga-import.php
 * 漫畫匯入頁面 HTML
 *
 * 由 Anime_Sync_Manga_Admin::render_page() 透過 include 載入。
 * 對應的 inline JS / AJAX 由 Anime_Sync_Manga_Admin 提供。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 權限已於 render_page() 檢查過，此處為雙保險。
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( '權限不足' );
}
?>
<div class="wrap">
	<h1>📖 漫畫匯入</h1>
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
