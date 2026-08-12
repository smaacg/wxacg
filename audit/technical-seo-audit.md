# 網站技術 SEO 稽核 (Technical SEO Audit)

## 1. Sitemap (網站地圖)
* **現狀**: 使用 Rank Math SEO。
* **問題點**: 
  * 目前 Sitemap 可能包含了大量我們準備 NOINDEX 的頁面（例如空標籤、空角色頁）。
  * 透過 query_var 產生的角色/人物頁面，如果沒有實體 Post，**不會**出現在預設的 Sitemap 中。
* **解法**:
  * 必須透過 Rank Math 的 `rank_math/sitemap/entry` filter，把低品質 (Thin Content) 的實體頁面從 Sitemap 中拔除。
  * 只有真正有高內容價值的頁面才留在 Sitemap。

## 2. Robots.txt
* **現狀**: 預設 WordPress 設定。
* **解法**: 建議明確禁止爬蟲抓取無用的系統路徑與參數：
  ```
  Disallow: /?s=
  Disallow: /wp-json/
  Disallow: /page/
  Disallow: *?replytocom
  ```

## 3. Canonical Tags (標準網址)
* **現狀**: Rank Math 自動處理。
* **問題點**: 參數頁面 (如排序參數 `?sort=rating`) 必須指回乾淨的原始 URL。虛擬的角色頁面 (`/character/{id}/`) 必須確保 Canonical Tag 指向自己，而非首頁或其他奇怪的路徑。

## 4. 結構化資料 (Structured Data)
* **現狀**: 網站具備豐富的動漫資料。
* **解法**:
  * 動漫單頁應該輸出 `Movie` 或 `TVSeries` Schema。
  * 如果頁面有外部評分，應該轉換為 `AggregateRating` 讓 Google 搜尋結果顯示星星。
  * 確保新聞文章輸出正統的 `NewsArticle` 或 `Article` Schema。

## 5. 爬蟲預算控制 (Crawl Budget)
網站資料量大 (近千部作品，未來可能更多)，如果讓 Google 每天爬數千個沒內容的標籤頁或角色頁，會浪費爬蟲預算，導致真正重要的新聞或主力作品更新時，Google 遲遲不來抓。這是大量執行條件式 noindex 的最大主因。
