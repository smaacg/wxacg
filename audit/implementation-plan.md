# 微笑動漫 AdSense "Thin Content" 改善實作計畫

本計畫旨在將微笑動漫從單純的「第三方資料庫聚合站」轉型為「具備原創編輯價值的動漫入口網站」，以通過 Google AdSense 的嚴格審查。

## ⚠️ User Review Required
請檢視以下計畫，確認是否同意建立新的 ACF 欄位作為原創資料層，以及是否同意大規模 NOINDEX 低價值標籤。確認無誤後，請點擊「Proceed」開始執行 P0 階段。

## ❓ Open Questions
1. 目前有超過 1600 個標籤（post_tag），是否同意我們將關聯文章數少於 3 篇的標籤全部設為 noindex？
2. 在 `single-anime.php` 中，我們預計加入「編輯摘要」與「台灣串流平台連結」，這些欄位是否要使用 ACF (Advanced Custom Fields) 新增，並整合進目前的編輯後台？
3. 新聞系統目前是手動發布還是有部分使用 RSS 自動匯入？如果是自動匯入，我們需要調整發布流程。

---

## Proposed Changes

### P0 階段：止血與防護 (最高優先級 - 阻擋垃圾索引)
這些修改不會動到資料庫，但會立即阻止 Google 繼續索引低價值頁面。

#### [MODIFY] [functions.php](file:///f:/fuck/app/public/wp-content/themes/blocksy-child/functions.php)
* **原因**: 網站有大量空標籤與空製作公司頁面，這些是被判定為 Thin Content 的元兇。
* **解決方案**: 加入動態 `noindex` 邏輯：
  1. 當 `is_tag()` 且文章數 < 3 時，輸出 `noindex` 標記。
  2. 當 `is_tax('anime_studio_tax')` 且文章數 < 3 時，輸出 `noindex` 標記。
* **優先級**: P0
* **測試方式**: 瀏覽前端只有 1 篇文章的標籤頁，檢查原始碼是否有 `<meta name="robots" content="noindex, follow">`。

#### [MODIFY] [single-character.php](file:///f:/fuck/app/public/wp-content/plugins/anime-sync-pro/public/templates/single-character.php) / [single-person.php](file:///f:/fuck/app/public/wp-content/plugins/anime-sync-pro/public/templates/single-person.php)
* **原因**: 雖然 `functions.php` 已經阻止了廣告載入，但這些頁面若無簡介，依然會被 Google 索引。
* **解決方案**: 如果外部資料庫返回的 `summary` 長度為空或極短，強制輸出 `noindex`。

---

### P1 階段：建立微笑動漫原創資料層 (核心價值重塑)
這是通過審查的絕對關鍵。我們必須在資料庫建立不被 API 覆蓋的原創欄位。

#### [NEW] ACF 原創欄位群組 (透過 PHP 或 ACF UI 建立)
* **解決方案**: 建立名為「微笑動漫原創資料」的 ACF 欄位群組，綁定至 `anime` post type。
  * `wxacg_editor_summary` (編輯精選摘要 - Textarea)
  * `wxacg_streaming_tw` (台灣合法串流連結 - Repeater)
  * `wxacg_watch_guide` (觀看指南/備註 - Wysiwyg)
* **優先級**: P1

#### [MODIFY] [single-anime.php](file:///f:/fuck/app/public/wp-content/plugins/anime-sync-pro/public/templates/single-anime.php)
* **解決方案**: 重構動漫單頁佈局。
  1. 將 `wxacg_editor_summary` 放在首屏最顯眼位置。
  2. 如果有設定串流連結，顯示「立即觀看」按鈕區塊。
  3. 將原先 API 抓取的冷硬 `synopsis` 移到較下方的折疊區塊。
* **測試方式**: 後台填寫資料後，確認前端版面正確渲染，且與外部同步資料明確分離。

---

### P2 階段：內部連結與 Knowledge Graph 強化

#### [MODIFY] [single.php](file:///f:/fuck/app/public/wp-content/themes/blocksy-child/single.php) (新聞文章)
* **原因**: 新聞文章目前較為孤立。
* **解決方案**: 開發一個 Shortcode 或 ACF 關聯欄位，允許編輯在新聞中輕易插入指定的「動漫資料庫卡片 (Anime Card)」，讓新聞流量導向資料庫。

#### [MODIFY] Sitemap 設定
* **解決方案**: 透過 Rank Math Filter，確保剛才設定為 `noindex` 的標籤與空角色頁，自動從 XML Sitemap 中剃除，節省爬蟲預算。

---

## Verification Plan

### Automated / Backend Tests
- 執行 WordPress Debug 模式確認無 PHP Notice/Fatal Error。
- 測試 Anime Sync Pro 手動觸發一次作品同步，確認「原創 ACF 欄位」內的資料沒有被清空或覆蓋。

### Manual Verification
- **SEO 爬蟲模擬**: 隨機挑選 10 個小於 3 篇文章的標籤頁，確認 Header 有回傳 `X-Robots-Tag: noindex` 或 HTML 內有 `<meta name="robots" content="noindex">`。
- **UI 檢查**: 在手機版與桌面版查看修改後的 `single-anime.php`，確認原創內容區塊排版不會破版，廣告不會擋住核心資訊。
