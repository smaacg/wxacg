# 原創資料層計畫 (Editorial Value Plan)

為解決 AdSense 拒絕理由中「缺乏獨特價值」的問題，必須在資料庫層面與前端展示層面，明確切割「第三方同步資料」與「微笑動漫原創資料」。

## 資料分層架構 (Data Layer Architecture)

### Layer 1: SOURCE DATA (第三方同步資料)
這是用來支撐網站豐富度的基石，但**絕對不能宣稱為原創**。

* **來源**：AniList, Bangumi, MyAnimeList, AnimeThemes API
* **欄位**：
  * 原文名稱 (Romaji, Native)
  * 放送日期 (Start/End Date)
  * 動畫季別 (Season/Year)
  * 外部評分 (MAL Score, Bangumi Score, AniList Score)
  * 官方大綱 (Synopsis) - *若無授權翻譯，可能有版權與重複內容風險*
  * OP / ED 基礎資料 (Song Title, Artist)
  * 角色列表與關聯 (Characters)
  * 製作人員 (Staff)
  * 製作公司 (Studios)
* **管理原則**：保留同步機制，允許外部資料更新時覆寫。

---

### Layer 2: WEIXIAOACG ORIGINAL DATA (微笑動漫原創資料層)
這是 **SEO 與 AdSense 審核通過的核心關鍵**，也是提供給使用者的真正價值。

* **來源**：編輯人工撰寫、社群貢獻、在地化整理。
* **欄位 (新增需求)**：
  1. **微笑動漫編輯摘要 (Editor's Summary)**：有別於維基百科或官方大綱，用 100-200 字口語化介紹「這部作品為什麼值得看」。
  2. **台灣/港澳合法串流資訊 (Legal Streaming in TW/HK)**：巴哈姆特動畫瘋、Netflix、KKTV、木棉花 YouTube 等連結。
  3. **觀看指南 (Watch Order/Guide)**：針對多季、劇場版的系列作，提供正確的觀看順序建議。
  4. **在地化譯名 (Localized Titles)**：清楚區分台灣常見譯名、香港譯名、大陸譯名（如：*葬送的芙莉蓮*）。
  5. **編輯備註 (Editor's Notes)**：例如「第 8 集有神作畫」、「原作漫畫已完結」等補充資訊。
  6. **人工校正標籤 (Curated Tags)**：避免使用外部 API 抓來的大量無意義英文標籤，改用精簡、有在地共鳴的中文分類標籤。
* **管理原則**：**絕對禁止**被外部 API 同步覆寫。必須在資料庫設計獨立的 ACF 欄位或 Custom Meta，與 Sync Pro 的資料脫鉤。

---

## 頁面重構展示建議 (Front-end Display)

未來的 `single-anime.php` 必須重新編排版面：

1. **首屏 (Hero Section)**: 主視覺 + 在地化譯名 + **台灣合法串流播放按鈕 (獨特工具價值)**。
2. **第一區塊**: 微笑動漫編輯摘要 (取代生硬的官方簡介)。
3. **第二區塊**: 系列觀看指南 (如果是系列作) 或 編輯推薦語。
4. **第三區塊 (折疊或下移)**: 第三方大綱、詳細 Staff、外部評分。

透過這樣的結構，Google 爬蟲第一時間抓取到的是獨特的在地化資訊，而非全網皆有的資料。
