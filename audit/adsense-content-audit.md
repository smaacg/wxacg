# 網站內容與 AdSense 審查稽核報告 (AdSense Content Audit)

## 1. 網站目前主要內容類型與 URL 結構

透過系統核心架構與資料庫結構分析，目前網站包含以下主要內容：

### WordPress Post Types
* **`anime` (動漫作品)**: 984 筆 (核心頁面，但其中 986 筆文章的 `post_content` 長度小於 100 字元，高度依賴 Meta Data)。
* **`post` (新聞/文章)**: 90 筆。
* **`manga` (漫畫作品)**: 2 筆。
* **`page` (靜態頁面)**: 27 筆。
* **`music_chart`, `music_concert`, `music_mv`**: 音樂與演唱會相關。
* **`asa_char_comments`**: 作為角色留言掛載用的隱藏 Post Type。

### Taxonomies (分類法)
* **`anime_season_tax` (季番)**: 189 個。
* **`anime_series_tax` (系列作品)**: 381 個。
* **`anime_studio_tax` (製作公司)**: 338 個。
* **`anime_format_tax` (作品格式)**: 7 個。
* **`post_tag` (文章標籤)**: 1634 個 (極多，潛在的 Thin Content 來源)。
* **`category` (新聞分類)**: 5 個。
* **`genre` (曲風/流派)**: 27 個。

### 虛擬實體頁面 (透過 query_var 動態產生，無實體 Post)
* **角色頁 (`/character/{id}/`)**: 透過 `asa_character_id` 解析，依賴外部/快取資料 (`Anime_Sync_Entity_Repository`)。
* **人物/聲優頁 (`/person/{id}/`)**: 透過 `asa_person_id` 解析。

---

## 2. 內容健康度與索引統計

基於資料庫與程式碼邏輯的掃描結果：

* **總計 Anime 作品數**: 984
* **空內容/短內容 Anime 比例**: 幾乎 100% (986 筆文章 `post_content` < 100 字元，代表內容完全由 ACF 或外掛欄位拼湊，缺乏原生「文章正文」)。
* **自動產生/無獨特價值的分類頁**: 1634 個 `post_tag` 與 338 個 `anime_studio_tax`。若無自訂介紹，這些都是標準的「低價值聚合頁」。
* **角色與人物頁**: 由於不是實體 Post，無法在後台進行 SEO Meta 的個別精細編輯，若外部 API 缺乏簡介，就會產生大量的「只有名字和圖片」的空洞頁面。

---

## 3. 頁面類型風險評估 (AdSense "Thin Content")

| 頁面類型 | AdSense 風險級別 | 原因分析 |
| :--- | :--- | :--- |
| **動漫單頁 (`/anime/`)** | **極高** | 高度依賴第三方資料 (AniList/Bangumi/MAL)，缺乏在地化的「編輯原創內容」。如果只有標題、圖片、外部簡介與 Staff 列表，Google 會判定為「無附加價值的聚合站」(Scraped/Aggregated content)。 |
| **角色/聲優頁 (`/character/`, `/person/`)** | **極高** | 只有名字、圖片與可能不存在的簡介。屬於典型的 Thin Content。 |
| **標籤與分類頁 (`/tag/`, `/anime_studio/`)** | **高** | 預設沒有自訂描述，只是一堆文章的列表。如果一個標籤下只有 1~2 篇文章，對使用者毫無獨立價值。 |
| **季番頁 (`/season/`)** | **中** | 如果只是當季動畫的網格列表，沒有編輯推薦、觀看指南或季度總評，同樣屬於聚合頁。 |
| **新聞頁 (`/news/` - `post`)** | **中低** | 如果是人工撰寫或有加入編輯觀點，則為高價值；但若是直接翻譯或 AI 總結第三方新聞，則有重複內容風險。 |

## 4. 稽核結論

網站目前的技術架構（Anime Sync Pro + ACF）非常適合建立結構化的資料庫，但這正是 **SEO 與 AdSense 的最大致命傷**。Google 能夠輕易識別出這些結構化資料與 MAL、AniList 高度重疊。

**最嚴重的問題在於：網站缺乏「只有微笑動漫才有的原創資料層」。** 
如果把網站的第三方 API 拔掉，網站幾乎沒有自己的靈魂（編輯產出）。這正是被判定為「缺乏價值的內容」的根本原因。
