<?php
/**
 * 檔案名稱: admin/pages/import-tool.php
 * 安全修正：所有第三方資料插入 DOM 一律經過 escHtml() 或 .text()
 * @version 1.9.1
 * Changelog:
 *  - 1.9.1 (2026-07-06): 修正 AniList 斷線時後端回傳空 ID 導致的 #NaN 無限洗版：
 *                         - 新增 collectIds() 工具，收集勾選 ID 時過濾 NaN/0/負數
 *                         - runImportQueue() 進佇列前二次過濾，杜絕無效 ID 送出
 *                         - render 函式（season/ranking/announced）跳過無效 anilist_id 的列
 *  - 1.9.0 (2026-06-28): 動畫化決定 tab 強化：
 *                         - 年份選單「不限年份」改為「全部（含日期未定）」
 *                         - 查詢結果摘要顯示「其中 X 部日期未定」
 *                         - start_date 空值顯示「日期未定」標籤
 *                         - 說明文字更新，反映雙輪查詢邏輯
 *  - 1.8.0 (2026-06-27): 手機板響應式優化，Tab 改橫向捲動，表格改卡片，
 *                         篩選列改縱向堆疊，按鈕群組全寬，進度區塊適配小螢幕。
 *  - 1.7.0: 新增「動畫化決定」tab。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cn_converter    = new Anime_Sync_CN_Converter();
$converter_stats = $cn_converter->get_stats();
?>

<div class="wrap anime-sync-import-tool">
    <h1>匯入動畫工具</h1>

    <div class="notice notice-info inline asc-converter-notice">
        <p>
            <strong>🔍 繁簡轉換器狀態：</strong>
            詞條總數 <code><?php echo number_format( (int)( $converter_stats['dict_entry_count'] ?? 0 ) ); ?></code> 條 |
            <?php if ( ! empty( $converter_stats['dict_file_exists'] ) ) : ?>
                <span style="color:green;font-weight:bold;">✓ 運作正常</span>
                | <small>測試：「脚本」→「<?php echo esc_html( $cn_converter->convert( '脚本' ) ); ?>」</small>
            <?php else : ?>
                <span style="color:red;font-weight:bold;">❌ 字典載入失敗</span>
            <?php endif; ?>
            | <small>模式：<code><?php echo esc_html( $converter_stats['mode'] ?? 'unknown' ); ?></code></small>
        </p>
    </div>

    <!-- Tab 導覽列：手機橫向捲動 -->
    <div class="asc-tab-nav-wrap">
        <h2 class="nav-tab-wrapper asc-tab-nav">
            <a href="#single"    class="nav-tab nav-tab-active" data-tab="single">📥 單筆匯入</a>
            <a href="#season"    class="nav-tab" data-tab="season">📅 季度批次</a>
            <a href="#batch"     class="nav-tab" data-tab="batch">📋 ID 清單</a>
            <a href="#series"    class="nav-tab" data-tab="series">🔗 系列分析</a>
            <a href="#ranking"   class="nav-tab" data-tab="ranking">🏆 人氣排行</a>
            <a href="#announced" class="nav-tab" data-tab="announced">🎬 動畫化決定</a>
        </h2>
    </div>

    <!-- ================================================================
         TAB 1：單筆匯入
    ================================================================ -->
    <div id="tab-single" class="anime-sync-tab-content" style="display:block;">
        <div class="asc-card asc-card--narrow">
            <h3>透過 AniList ID 匯入</h3>
            <table class="form-table asc-form-table">
                <tr>
                    <th><label for="single-anilist-id">AniList ID</label></th>
                    <td>
                        <input type="number" id="single-anilist-id" class="regular-text" placeholder="例如: 1535">
                        <p class="description">請輸入作品在 AniList 網址結尾的數字。</p>
                    </td>
                </tr>
                <tr>
                    <th>選項</th>
                    <td>
                        <label>
                            <input type="checkbox" id="single-force-update">
                            強制接管卡住的同步鎖（一般情況不需勾選）
                        </label>
                    </td>
                </tr>
            </table>
            <p><button type="button" id="btn-single-import" class="button button-primary button-large asc-btn-full">開始匯入</button></p>
            <div id="single-import-result" style="margin-top:20px;padding:15px;display:none;border-radius:4px;"></div>
        </div>
    </div>

    <!-- ================================================================
         TAB 2：季度批次匯入
    ================================================================ -->
    <div id="tab-season" class="anime-sync-tab-content" style="display:none;">
        <div class="asc-card">
            <h3>按季度篩選</h3>
            <div class="asc-query-row">
                <div class="asc-query-field">
                    <label>年份</label>
                    <select id="season-year-select">
                        <?php for ( $y = date('Y')+1; $y >= 2000; $y-- ) : ?>
                            <option value="<?php echo esc_attr( $y ); ?>"<?php selected( date('Y'), $y ); ?>><?php echo esc_html( $y ); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="asc-query-field">
                    <label>季節</label>
                    <select id="season-select">
                        <option value="WINTER">冬季 (1–3月)</option>
                        <option value="SPRING">春季 (4–6月)</option>
                        <option value="SUMMER">夏季 (7–9月)</option>
                        <option value="FALL">秋季 (10–12月)</option>
                    </select>
                </div>
                <div class="asc-query-field asc-query-field--btn">
                    <label class="asc-label-hidden">查詢</label>
                    <button type="button" id="btn-season-query" class="button asc-btn-full">第一步：查詢季度清單</button>
                </div>
            </div>
            <div id="season-query-spinner" class="asc-spinner" style="display:none;">⏳ 查詢中，可能需要 10–30 秒…</div>

            <div id="season-preview" style="display:none;">
                <p id="season-preview-summary" class="description"></p>
                <div class="asc-format-filter-bar">
                    <strong>篩選格式：</strong>
                    <label><input type="checkbox" class="format-filter-check" value="TV" checked> TV</label>
                    <label><input type="checkbox" class="format-filter-check" value="MOVIE" checked> MOVIE</label>
                    <label><input type="checkbox" class="format-filter-check" value="OVA" checked> OVA</label>
                    <label><input type="checkbox" class="format-filter-check" value="ONA" checked> ONA</label>
                    <label><input type="checkbox" class="format-filter-check" value="SPECIAL" checked> SPECIAL</label>
                    <label><input type="checkbox" class="format-filter-check" value="MUSIC"> MUSIC</label>
                    <label style="margin-left:12px;border-left:1px solid #ccd0d4;padding-left:12px;">
                        <input type="checkbox" id="filter-not-imported"> 只顯示未匯入
                    </label>
                    <button type="button" id="btn-apply-format-filter" class="button button-small">套用篩選</button>
                    <span id="season-filter-count" class="asc-filter-count"></span>
                </div>
                <div class="asc-table-wrap">
                    <table class="wp-list-table widefat fixed striped asc-season-table asc-desktop-only">
                        <thead><tr>
                            <th class="asc-col-check"><input id="season-select-all" type="checkbox" checked></th>
                            <th class="asc-col-id">ID</th>
                            <th>名稱 (Romaji)</th>
                            <th class="asc-col-sm">格式</th>
                            <th class="asc-col-sm">集數</th>
                            <th class="asc-col-md">人氣</th>
                            <th class="asc-col-md">狀態</th>
                            <th class="asc-col-md">站內狀態</th>
                        </tr></thead>
                        <tbody id="season-anime-tbody"></tbody>
                    </table>
                    <div id="season-anime-cards" class="asc-mobile-cards asc-mobile-only"></div>
                </div>
                <div class="asc-action-row">
                    <button type="button" id="btn-season-import" class="button button-primary">第二步：批次匯入選中項</button>
                    <button type="button" id="btn-season-stop" class="button asc-btn-danger" style="display:none;">停止匯入</button>
                    <span id="season-throttle-notice" class="asc-throttle-notice" style="display:none;"></span>
                </div>
                <?php echo asc_progress_block('season'); ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         TAB 3：ID 清單批次匯入
    ================================================================ -->
    <div id="tab-batch" class="anime-sync-tab-content" style="display:none;">
        <div class="asc-card asc-card--narrow">
            <h3>大量 ID 清單匯入</h3>
            <p class="description">請輸入 AniList ID，用換行或逗號隔開。</p>
            <textarea id="batch-id-list" rows="8" class="large-text" placeholder="例如:&#10;1535&#10;21,20&#10;16498"></textarea>
            <p id="batch-id-count" class="asc-id-count">0 個 ID</p>
            <p><label><input type="checkbox" id="batch-force-update"> 強制接管卡住的同步鎖（一般情況不需勾選）</label></p>
            <div class="asc-action-row">
                <button type="button" id="btn-batch-import" class="button button-primary">開始批次匯入</button>
                <button type="button" id="btn-batch-stop" class="button asc-btn-danger" style="display:none;">停止</button>
                <span id="batch-throttle-notice" class="asc-throttle-notice" style="display:none;"></span>
            </div>
            <?php echo asc_progress_block('batch'); ?>
        </div>
    </div>

    <!-- ================================================================
         TAB 4：系列分析匯入
    ================================================================ -->
    <div id="tab-series" class="anime-sync-tab-content" style="display:none;">
        <div class="asc-card">
            <h3>🔗 系列分析與匯入</h3>
            <p class="description">輸入任意一部作品的 AniList ID，系統將自動追溯前作、列出完整系列，並標記哪些已匯入。</p>
            <div class="asc-query-row">
                <div class="asc-query-field asc-query-field--grow">
                    <label class="asc-label-hidden">AniList ID</label>
                    <input type="number" id="series-anilist-id" class="regular-text asc-input-full" placeholder="輸入任意一部的 AniList ID，例如 20958">
                </div>
                <div class="asc-query-field asc-query-field--btn">
                    <label class="asc-label-hidden">分析</label>
                    <button type="button" id="btn-analyze-series" class="button button-primary asc-btn-full">🔍 分析系列</button>
                </div>
            </div>
            <div id="series-analyze-spinner" class="asc-spinner" style="display:none;">⏳ 分析中，正在遞迴追溯前作…</div>
            <div id="series-result" style="display:none;">
                <div id="series-info" class="asc-info-box"></div>
                <div class="asc-table-wrap">
                    <table class="wp-list-table widefat fixed striped asc-series-table asc-desktop-only">
                        <thead><tr>
                            <th class="asc-col-check"><input id="series-select-all" type="checkbox" checked></th>
                            <th class="asc-col-id">ID</th>
                            <th>作品名稱</th>
                            <th class="asc-col-sm">格式</th>
                            <th class="asc-col-sm">年份</th>
                            <th class="asc-col-md">關聯類型</th>
                            <th class="asc-col-md">站內狀態</th>
                        </tr></thead>
                        <tbody id="series-tbody"></tbody>
                    </table>
                    <div id="series-cards" class="asc-mobile-cards asc-mobile-only"></div>
                </div>
                <div class="asc-action-row">
                    <button type="button" id="btn-series-import" class="button button-primary">📥 匯入選中作品並歸入系列</button>
                    <button type="button" id="btn-series-stop" class="button asc-btn-danger" style="display:none;">停止</button>
                    <span id="series-throttle-notice" class="asc-throttle-notice" style="display:none;"></span>
                </div>
                <?php echo asc_progress_block('series'); ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         TAB 5：人氣排行匯入
    ================================================================ -->
    <div id="tab-ranking" class="anime-sync-tab-content" style="display:none;">
        <div class="asc-card">
            <h3>🏆 AniList 人氣排行匯入</h3>
            <p class="description">依 AniList 人氣排行載入，每次 50 部，標記已匯入狀態，未匯入預設勾選。</p>
            <div class="asc-action-row asc-action-row--top">
                <button type="button" id="btn-ranking-load" class="button">📄 載入排行（第 <span id="ranking-page-num">1</span> 頁）</button>
                <button type="button" id="btn-ranking-more" class="button" style="display:none;">➕ 載入更多 50 部</button>
                <span id="ranking-load-spinner" class="asc-spinner" style="display:none;">⏳ 載入中…</span>
            </div>
            <div id="ranking-preview" style="display:none;">
                <p id="ranking-preview-summary" class="description"></p>
                <div class="asc-table-wrap">
                    <table class="wp-list-table widefat fixed striped asc-ranking-table asc-desktop-only">
                        <thead><tr>
                            <th class="asc-col-check"><input id="ranking-select-all" type="checkbox" checked></th>
                            <th class="asc-col-rank">#</th>
                            <th class="asc-col-cover">封面</th>
                            <th>作品名稱</th>
                            <th class="asc-col-sm">格式</th>
                            <th class="asc-col-sm">集數</th>
                            <th class="asc-col-md">人氣</th>
                            <th class="asc-col-md">站內狀態</th>
                        </tr></thead>
                        <tbody id="ranking-tbody"></tbody>
                    </table>
                    <div id="ranking-cards" class="asc-mobile-cards asc-mobile-only"></div>
                </div>
                <div class="asc-action-row">
                    <button type="button" id="btn-ranking-import" class="button button-primary" style="display:none;">📥 匯入選中作品</button>
                    <button type="button" id="btn-ranking-stop" class="button asc-btn-danger" style="display:none;">停止</button>
                    <span id="ranking-throttle-notice" class="asc-throttle-notice" style="display:none;"></span>
                </div>
                <?php echo asc_progress_block('ranking'); ?>
            </div>
        </div>
    </div>

    <!-- ================================================================
         TAB 6：動畫化決定（v1.9.0 強化）
    ================================================================ -->
    <div id="tab-announced" class="anime-sync-tab-content" style="display:none;">
        <div class="asc-card">
            <h3>🎬 動畫化決定查詢</h3>
            <p class="description">
                查詢 AniList 上狀態為「尚未放映（NOT_YET_RELEASED）」的作品，包含劇場版。
                選擇「<strong>全部（含日期未定）</strong>」時，系統會額外執行第二輪查詢，
                抓取已宣布動畫化但播出日期尚未確定的作品，更接近業界「動畫化決定・製作進行中」清單。
                已匯入的作品會自動標記。
            </p>

            <!-- 查詢條件列 -->
            <div class="asc-query-row">
                <div class="asc-query-field">
                    <label>預計年份</label>
                    <select id="announced-year-select">
                        <option value="0">📋 全部（含日期未定）</option>
                        <?php for ( $y = date('Y') + 3; $y >= date('Y'); $y-- ) : ?>
                            <option value="<?php echo esc_attr( $y ); ?>"<?php selected( date('Y'), $y ); ?>><?php echo esc_html( $y ); ?> 年</option>
                        <?php endfor; ?>
                        <option value="<?php echo esc_attr( date('Y') - 1 ); ?>"><?php echo esc_html( date('Y') - 1 ); ?> 年</option>
                    </select>
                </div>
                <div class="asc-query-field">
                    <label>排序方式</label>
                    <select id="announced-sort-select">
                        <option value="POPULARITY_DESC">人氣高到低</option>
                        <option value="START_DATE">預計開播日期</option>
                    </select>
                </div>
                <div class="asc-query-field asc-query-field--btn">
                    <label class="asc-label-hidden">查詢</label>
                    <button type="button" id="btn-announced-query" class="button asc-btn-full">第一步：查詢清單</button>
                </div>
            </div>
            <div id="announced-query-spinner" class="asc-spinner" style="display:none;">⏳ 查詢中，選擇「全部」時需額外查詢日期未定作品，約需 30–60 秒…</div>

            <!-- 格式篩選 -->
            <div class="asc-format-filter-bar">
                <strong>格式：</strong>
                <label><input type="checkbox" class="announced-format-check" value="TV" checked> TV</label>
                <label><input type="checkbox" class="announced-format-check" value="MOVIE" checked> MOVIE</label>
                <label><input type="checkbox" class="announced-format-check" value="OVA" checked> OVA</label>
                <label><input type="checkbox" class="announced-format-check" value="ONA" checked> ONA</label>
                <label><input type="checkbox" class="announced-format-check" value="SPECIAL"> SPECIAL</label>
                <label><input type="checkbox" class="announced-format-check" value="MUSIC"> MUSIC</label>
            </div>

            <!-- 結果區 -->
            <div id="announced-preview" style="display:none;">
                <p id="announced-preview-summary" class="description"></p>
                <div class="asc-table-wrap">
                    <table class="wp-list-table widefat fixed striped asc-announced-table asc-desktop-only">
                        <thead><tr>
                            <th class="asc-col-check"><input id="announced-select-all" type="checkbox" checked></th>
                            <th class="asc-col-id">ID</th>
                            <th>名稱 (Romaji)</th>
                            <th class="asc-col-sm">格式</th>
                            <th class="asc-col-sm">集數</th>
                            <th class="asc-col-md">預計開播</th>
                            <th class="asc-col-md">人氣</th>
                            <th class="asc-col-md">站內狀態</th>
                        </tr></thead>
                        <tbody id="announced-tbody"></tbody>
                    </table>
                    <div id="announced-cards" class="asc-mobile-cards asc-mobile-only"></div>
                </div>
                <div class="asc-action-row">
                    <button type="button" id="btn-announced-import" class="button button-primary">第二步：匯入選中作品（草稿）</button>
                    <button type="button" id="btn-announced-stop" class="button asc-btn-danger" style="display:none;">停止匯入</button>
                    <span id="announced-throttle-notice" class="asc-throttle-notice" style="display:none;"></span>
                </div>
                <?php echo asc_progress_block('announced'); ?>
            </div>
        </div>
    </div>

</div><!-- .anime-sync-import-tool -->

<?php
function asc_progress_block( $prefix ) {
    return '
    <div id="' . esc_attr($prefix) . '-progress-wrap" class="asc-progress-wrap" style="display:none;">
        <div class="asc-progress-bar-track">
            <div id="' . esc_attr($prefix) . '-progress-bar" class="asc-progress-bar-fill"></div>
        </div>
        <p id="' . esc_attr($prefix) . '-progress-text" class="asc-progress-text"></p>
        <div id="' . esc_attr($prefix) . '-import-log" class="asc-log-box"></div>
    </div>';
}
?>

<style>
/* =============================================================================
   全域 / 版面
============================================================================= */
.anime-sync-import-tool { max-width: none !important; }
#wpcontent { padding-right: 20px; }
.asc-converter-notice p { word-break: break-word; }

/* =============================================================================
   Tab 導覽：橫向捲動
============================================================================= */
.asc-tab-nav-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 0;
}
.asc-tab-nav {
    display: flex;
    flex-wrap: nowrap;
    white-space: nowrap;
    border-bottom: 1px solid #c3c4c7;
    margin-bottom: 0;
    padding-bottom: 0;
}
.asc-tab-nav .nav-tab { flex-shrink: 0; font-size: 13px; padding: 8px 12px; }
@media screen and (max-width: 782px) { .asc-tab-nav .nav-tab { font-size: 12px; padding: 7px 10px; } }
@media screen and (max-width: 480px) { .asc-tab-nav .nav-tab { font-size: 11px; padding: 6px 8px; } }

/* =============================================================================
   Card
============================================================================= */
.asc-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px 24px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    border-radius: 4px;
    margin-top: 20px;
    margin-bottom: 20px;
}
.asc-card--narrow { max-width: 680px; }
@media screen and (max-width: 782px) {
    .asc-card { padding: 14px 14px; }
    .asc-card--narrow { max-width: 100%; }
}

/* =============================================================================
   form-table 手機堆疊
============================================================================= */
.asc-form-table { width: 100%; }
@media screen and (max-width: 782px) {
    .asc-form-table tr,
    .asc-form-table th,
    .asc-form-table td { display: block; width: 100%; box-sizing: border-box; }
    .asc-form-table th { padding-bottom: 4px; font-weight: 600; }
    .asc-form-table td { padding-top: 0; }
    .asc-form-table input[type="number"],
    .asc-form-table .regular-text { width: 100% !important; box-sizing: border-box; }
}

/* =============================================================================
   查詢條件列
============================================================================= */
.asc-query-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.asc-query-field { display: flex; flex-direction: column; }
.asc-query-field--grow { flex: 1; min-width: 200px; }
.asc-query-field label { font-size: 13px; margin-bottom: 4px; font-weight: 600; }
.asc-label-hidden { visibility: hidden; }
.asc-query-field select,
.asc-query-field input[type="number"] { min-width: 100px; }
.asc-input-full { width: 100% !important; box-sizing: border-box; }
@media screen and (max-width: 600px) {
    .asc-query-row { flex-direction: column; gap: 10px; }
    .asc-query-field,
    .asc-query-field--grow { width: 100%; }
    .asc-query-field select,
    .asc-query-field input[type="number"],
    .asc-query-field .button { width: 100%; box-sizing: border-box; text-align: center; }
    .asc-label-hidden { display: none; }
}

/* =============================================================================
   格式篩選列
============================================================================= */
.asc-format-filter-bar {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 14px;
    padding: 10px 12px;
    background: #f6f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
}
.asc-filter-count { color: #666; font-size: 12px; margin-left: 4px; }
@media screen and (max-width: 480px) { .asc-format-filter-bar { gap: 8px; font-size: 12px; padding: 8px 10px; } }

/* =============================================================================
   操作按鈕列
============================================================================= */
.asc-action-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-top: 14px;
}
.asc-action-row--top { margin-bottom: 14px; margin-top: 0; }
.asc-throttle-notice { color: #d97706; font-weight: bold; }
.asc-btn-danger { color: red !important; }
.asc-btn-full { width: 100%; box-sizing: border-box; text-align: center; }
@media screen and (max-width: 600px) {
    .asc-action-row { flex-direction: column; align-items: stretch; }
    .asc-action-row .button { width: 100%; text-align: center; box-sizing: border-box; }
    .asc-throttle-notice { text-align: center; }
}

/* =============================================================================
   表格包裝
============================================================================= */
.asc-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.asc-table-wrap table { width: 100%; min-width: 560px; border: none; }

.asc-col-check  { width: 36px !important; }
.asc-col-id     { width: 70px !important; }
.asc-col-sm     { width: 70px !important; }
.asc-col-md     { width: 90px !important; }
.asc-col-cover  { width: 50px !important; }
.asc-col-rank   { width: 40px !important; }

.asc-desktop-only { display: table; }
.asc-mobile-only  { display: none; }
@media screen and (max-width: 782px) {
    .asc-desktop-only { display: none !important; }
    .asc-mobile-only  { display: block; }
}

/* =============================================================================
   日期未定標籤
============================================================================= */
.asc-no-date-badge {
    display: inline-block;
    background: #f0a500;
    color: #fff;
    font-size: 10px;
    font-weight: bold;
    padding: 1px 5px;
    border-radius: 3px;
    vertical-align: middle;
    margin-left: 2px;
}

/* =============================================================================
   手機卡片
============================================================================= */
.asc-mobile-cards { padding: 8px; background: #fafafa; }
.asc-import-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.asc-import-card.is-imported { opacity: .65; background: #f5f5f5; }
.asc-import-card__check { flex-shrink: 0; margin-top: 3px; }
.asc-import-card__cover { flex-shrink: 0; }
.asc-import-card__cover img { width: 42px; height: 60px; object-fit: cover; border-radius: 3px; display: block; }
.asc-import-card__body { flex: 1; min-width: 0; }
.asc-import-card__title { font-weight: 600; font-size: 13px; line-height: 1.4; margin-bottom: 6px; word-break: break-word; }
.asc-import-card__meta { display: flex; flex-wrap: wrap; gap: 6px; font-size: 11px; color: #555; }
.asc-import-card__meta-item { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
.asc-import-card__meta-item--nodate { background: #fff3cd; color: #856404; }
.asc-import-card__status { margin-top: 4px; }

/* =============================================================================
   進度區塊
============================================================================= */
.asc-progress-wrap { margin-top: 20px; }
.asc-progress-bar-track { background: #eee; height: 18px; border-radius: 9px; overflow: hidden; }
.asc-progress-bar-fill { background: #2271b1; width: 0%; height: 100%; transition: width .3s; }
.asc-progress-text { text-align: center; font-weight: bold; font-size: 13px; margin: 6px 0; }
.asc-id-count { color: #666; font-size: 13px; }

/* =============================================================================
   Log box
============================================================================= */
.asc-log-box {
    background: #111;
    color: #0f0;
    padding: 10px;
    height: 200px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 12px;
    margin-top: 10px;
    border-radius: 5px;
    word-break: break-all;
}
@media screen and (max-width: 480px) { .asc-log-box { height: 160px; font-size: 11px; } }
.log-success  { color: #0f0; }
.log-warning  { color: #f90; font-weight: bold; }
.log-error    { color: #f33; }
.log-skip     { color: #aaa; }
.log-info     { color: #3df; border-bottom: 1px solid #333; padding-bottom: 2px; margin-bottom: 5px; }
.log-throttle { color: #ff0; font-weight: bold; }

/* =============================================================================
   其他共用
============================================================================= */
.asc-spinner { display: inline-block; color: #d97706; font-weight: bold; margin: 8px 0; font-size: 13px; }
.asc-cover-thumb { width: 36px; height: 50px; object-fit: cover; border-radius: 2px; display: block; }
.asc-info-box { background: #f0f7ff; border: 1px solid #b8d4f5; border-radius: 4px; padding: 12px; margin-bottom: 15px; font-size: 13px; }
.status-imported { color: #46b450; font-weight: bold; }
.status-new      { color: #2271b1; }
#season-anime-tbody tr.format-hidden { display: none; }
#single-import-result.success { background: #edfaef; border: 1px solid #46b450; color: #235926; }
#single-import-result.warning { background: #fff8e5; border: 1px solid #d97706; color: #7a4b00; }
#single-import-result.error   { background: #fcf0f1; border: 1px solid #dc3232; color: #a42821; }
</style>

<script>
(function($){
    'use strict';

    /* =========================================================================
       工具函式
    ========================================================================= */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ★ v1.9.1：驗證單一 anilist_id，回傳有效整數或 null */
    function validId(raw) {
        var n = parseInt(raw, 10);
        return ( !isNaN(n) && n > 0 ) ? n : null;
    }

    /* ★ v1.9.1：收集勾選 checkbox 的 data-id，自動過濾 NaN/0/負數並去重 */
    function collectIds(selector) {
        var out = [];
        var seen = {};
        $(selector + ':checked').each(function(){
            var n = validId($(this).data('id'));
            if (n !== null && !seen[n]) { seen[n] = true; out.push(n); }
        });
        return out;
    }

    function formatStartDate(dateStr) {
        if (!dateStr || dateStr === '') {
            return '<span class="asc-no-date-badge">日期未定</span>';
        }
        return escHtml(dateStr);
    }

    function formatStartDateText(dateStr) {
        if (!dateStr || dateStr === '') return '日期未定';
        return dateStr;
    }

    function buildImportCard(item, checkClass) {
        var isImported = item.imported;
        var chk = $('<input type="checkbox">').addClass(checkClass).attr('data-id', item.anilist_id);
        if (isImported) chk.prop('disabled', true);
        else chk.prop('checked', true);

        var status = isImported
            ? $('<span class="status-imported">').text('✓ 已匯入')
            : $('<span class="status-new">').text('未匯入');

        var meta = $('<div class="asc-import-card__meta">');
        if (item.format) meta.append('<span class="asc-import-card__meta-item">' + escHtml(item.format) + '</span>');
        if (item.meta1Val) meta.append('<span class="asc-import-card__meta-item">' + escHtml(item.meta1Label) + '：' + escHtml(item.meta1Val) + '</span>');

        // 日期未定特殊樣式
        var dateVal = item.meta2Val || '';
        var dateClass = (!dateVal || dateVal === '日期未定') ? 'asc-import-card__meta-item asc-import-card__meta-item--nodate' : 'asc-import-card__meta-item';
        if (item.meta2Val !== undefined) {
            meta.append('<span class="' + dateClass + '">' + escHtml(item.meta2Label) + '：' + escHtml(dateVal || '日期未定') + '</span>');
        }
        if (item.popularity !== undefined) meta.append('<span class="asc-import-card__meta-item">人氣 ' + escHtml(String(item.popularity)) + '</span>');

        var body = $('<div class="asc-import-card__body">');
        body.append($('<div class="asc-import-card__title">').text(item.title_romaji));
        body.append(meta);
        body.append($('<div class="asc-import-card__status">').append(status));

        var card = $('<div class="asc-import-card">').toggleClass('is-imported', !!isImported);
        card.append($('<div class="asc-import-card__check">').append(chk));
        card.append(body);
        return card;
    }

    function buildImportCardWithCover(item, checkClass) {
        var card = buildImportCard(item, checkClass);
        if (item.cover_image) {
            var coverWrap = $('<div class="asc-import-card__cover">');
            coverWrap.append($('<img>').attr('src', item.cover_image).attr('alt', ''));
            card.find('.asc-import-card__check').after(coverWrap);
        }
        return card;
    }

    function appendLog(boxId, msg, cls) {
        var $box = $('#' + boxId);
        $box.append($('<div>').addClass(cls || 'log-info').text(msg));
        $box[0].scrollTop = $box[0].scrollHeight;
    }

    function updateProgress(prefix, done, total, success, skipped, failed) {
        var pct = total > 0 ? Math.round(done / total * 100) : 0;
        $('#' + prefix + '-progress-bar').css('width', pct + '%');
        $('#' + prefix + '-progress-text').text(
            '進度：' + done + ' / ' + total + '（成功 ' + success + '，跳過 ' + skipped + '，失敗 ' + failed + '）'
        );
    }

    /* =========================================================================
       Tab 切換
    ========================================================================= */
    $('.nav-tab-wrapper .nav-tab').on('click', function(e){
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.anime-sync-tab-content').hide();
        $('#tab-' + tab).show();
    });

    /* =========================================================================
       TAB 2：季度批次匯入
    ========================================================================= */
    var seasonData = [];

    $('#btn-season-query').on('click', function(){
        var year   = $('#season-year-select').val();
        var season = $('#season-select').val();
        $('#btn-season-query').prop('disabled', true);
        $('#season-query-spinner').show();
        $('#season-preview').hide();
        seasonData = [];

        $.post(animeSyncAdmin.ajaxUrl, {
            action: animeSyncAdmin.actions.query_season,
            nonce:  animeSyncAdmin.nonce,
            year:   year,
            season: season
        }, function(res){
            $('#btn-season-query').prop('disabled', false);
            $('#season-query-spinner').hide();
            if (!res.success) { alert('查詢失敗：' + (res.data || '未知錯誤')); return; }
            seasonData = res.data.list || [];
            if (seasonData.length === 0) { alert('查無此季度資料'); return; }
            renderSeasonTable(seasonData);
            $('#season-preview').show();
        }).fail(function(){
            $('#btn-season-query').prop('disabled', false);
            $('#season-query-spinner').hide();
            alert('網路錯誤');
        });
    });

    function renderSeasonTable(list) {
        var tbody = $('#season-anime-tbody').empty();
        var cards = $('#season-anime-cards').empty();
        var count = 0;

        $.each(list, function(i, item){
            // ★ v1.9.1：跳過無效 ID 的資料列，避免產生 data-id="NaN"
            var aid = validId(item.anilist_id);
            if (aid === null) { return true; }

            var imported  = item.imported;
            var chkCell   = imported
                ? '<input type="checkbox" class="season-item-check" data-id="' + aid + '" disabled>'
                : '<input type="checkbox" class="season-item-check" data-id="' + aid + '" checked>';
            var statusBadge = imported
                ? '<span class="status-imported">✓ 已匯入</span>'
                : '<span class="status-new">未匯入</span>';

            // data-imported 供「只顯示未匯入」篩選使用（卡片改用 is-imported class）
            var tr = $('<tr>')
                .attr('data-format', item.format || '')
                .attr('data-imported', imported ? '1' : '0')
                .html(
                '<td>' + chkCell + '</td>' +
                '<td>' + escHtml(String(aid)) + '</td>' +
                '<td>' + escHtml(item.title_romaji || '') + '</td>' +
                '<td>' + escHtml(item.format || '') + '</td>' +
                '<td>' + escHtml(String(item.episodes || '—')) + '</td>' +
                '<td>' + escHtml(String(item.popularity || 0)) + '</td>' +
                '<td>' + escHtml(item.status || '') + '</td>' +
                '<td>' + statusBadge + '</td>'
            );
            tbody.append(tr);

            var card = buildImportCard({
                anilist_id:   aid,
                title_romaji: item.title_romaji || '',
                format:       item.format || '',
                meta1Label:   '集數', meta1Val: item.episodes || '—',
                meta2Label:   '狀態', meta2Val: item.status || '',
                popularity:   item.popularity,
                imported:     imported
            }, 'season-item-check');
            card.attr('data-format', item.format || '');
            cards.append(card);

            if (!imported) count++;
        });

        $('#season-filter-count').text('未匯入 ' + count + ' 部');
        $('#season-select-all').off('change').on('change', function(){
            $('.season-item-check:not(:disabled)').prop('checked', $(this).prop('checked'));
        });
    }

    $('#btn-apply-format-filter').on('click', function(){
        var enabled = [];
        $('.format-filter-check:checked').each(function(){ enabled.push($(this).val()); });

        // 只顯示未匯入：表格看 data-imported，卡片看 is-imported class
        var onlyNew = $('#filter-not-imported').is(':checked');

        var visibleCount = 0;
        $('#season-anime-tbody tr').each(function(){
            var fmt  = $(this).attr('data-format') || '';
            var done = $(this).attr('data-imported') === '1';
            var show = enabled.indexOf(fmt) !== -1 && !(onlyNew && done);
            $(this).toggleClass('format-hidden', !show);
        });
        $('#season-anime-cards .asc-import-card').each(function(){
            var fmt  = $(this).attr('data-format') || '';
            var done = $(this).hasClass('is-imported');
            var show = enabled.indexOf(fmt) !== -1 && !(onlyNew && done);
            $(this).toggle(show);
            if (show && !done) visibleCount++;
        });
        $('#season-filter-count').text('篩選後未匯入 ' + visibleCount + ' 部');
    });

      var seasonStop = false;
    $('#btn-season-import').on('click', function(){
        // ★ v1.9.2：選擇器放寬為 .season-item-check，同時涵蓋桌機表格與手機卡片；
        //    collectIds 已內建 NaN 過濾 + 去重，重複的勾選會自動合併。
        //    （移除 #season-anime-tbody tr:not(.format-hidden) 限定，避免手機版
        //      或格式篩選情境下誤判為「未勾選」。）
        var ids = collectIds('.season-item-check');
        if (ids.length === 0) { alert('請勾選至少一部'); return; }
        seasonStop = false;
        runImportQueue('season', ids);
    });
    $('#btn-season-stop').on('click', function(){ seasonStop = true; $(this).text('停止中…').prop('disabled', true); });

    /* =========================================================================
       TAB 3：ID 清單批次匯入
    ========================================================================= */
    $('#batch-id-list').on('input', function(){
        var raw = $(this).val();
        var ids = raw.split(/[\n,]+/).map(function(s){ return s.trim(); }).filter(function(s){ return /^\d+$/.test(s); });
        $('#batch-id-count').text(ids.length + ' 個 ID');
    });

    var batchStop = false;
    $('#btn-batch-import').on('click', function(){
        var raw = $('#batch-id-list').val();
        var ids = raw.split(/[\n,]+/).map(function(s){ return parseInt(s.trim(), 10); }).filter(function(n){ return !isNaN(n) && n > 0; });
        if (ids.length === 0) { alert('請輸入有效的 ID'); return; }
        batchStop = false;
        runImportQueue('batch', ids);
    });
    $('#btn-batch-stop').on('click', function(){ batchStop = true; $(this).text('停止中…').prop('disabled', true); });

   /* =========================================================================
       TAB 5：人氣排行匯入
    ========================================================================= */
    var rankingPage = 1;
    var rankingStop = false;

    $('#btn-ranking-load').on('click', function(){
        rankingPage = 1;
        $('#ranking-tbody').empty();
        $('#ranking-cards').empty();
        loadRankingPage();
    });
    $('#btn-ranking-more').on('click', function(){ loadRankingPage(); });

    function loadRankingPage(){
        $('#ranking-load-spinner').show();
        $('#btn-ranking-load, #btn-ranking-more').prop('disabled', true);
        $.post(animeSyncAdmin.ajaxUrl, {
            // ★ 修正：後端註冊的 action 是 anime_sync_popularity_ranking，
            //         對應 animeSyncAdmin.actions.popularity_ranking。
            //         原本用 query_ranking（未註冊）→ undefined → admin-ajax 回 0/400 → 跳網路錯誤。
            action: animeSyncAdmin.actions.popularity_ranking,
            nonce:  animeSyncAdmin.nonce,
            page:   rankingPage
        }, function(res){
            $('#ranking-load-spinner').hide();
            $('#btn-ranking-load, #btn-ranking-more').prop('disabled', false);
            if (!res.success) {
                var errMsg = (res.data && res.data.message) ? res.data.message
                           : (typeof res.data === 'string' ? res.data : '');
                alert('載入失敗：' + (errMsg || '未知錯誤'));
                return;
            }
            var list = res.data.list || [];
            renderRankingRows(list, (rankingPage - 1) * 50);
            rankingPage++;
            $('#ranking-page-num').text(rankingPage);
            $('#ranking-preview, #btn-ranking-import, #btn-ranking-more').show();
            var imported = list.filter(function(i){ return i.imported; }).length;
            $('#ranking-preview-summary').text('本頁 ' + list.length + ' 部，已匯入 ' + imported + ' 部。');
        }).fail(function(xhr){
            $('#ranking-load-spinner').hide();
            $('#btn-ranking-load, #btn-ranking-more').prop('disabled', false);
            // ★ 強化錯誤訊息：顯示實際狀態碼與回應前幾字，方便定位問題
            var detail = xhr && xhr.status !== undefined ? ('狀態 ' + xhr.status) : '無回應';
            var body   = xhr && xhr.responseText ? ('：' + String(xhr.responseText).slice(0, 200)) : '';
            alert('請求失敗（' + detail + '）' + body);
            if (window.console) { console.error('ranking load fail', xhr); }
        });
    }

    function renderRankingRows(list, offset) {
        var tbody = $('#ranking-tbody');
        var cards = $('#ranking-cards');
        $.each(list, function(i, item){
            // ★ v1.9.1：跳過無效 ID 的資料列
            var aid = validId(item.anilist_id);
            if (aid === null) { return true; }

            var rank     = offset + i + 1;
            var imported = item.imported;
            var chk = imported
                ? '<input type="checkbox" class="ranking-item-check" data-id="' + aid + '" disabled>'
                : '<input type="checkbox" class="ranking-item-check" data-id="' + aid + '" checked>';
            var status = imported
                ? '<span class="status-imported">✓ 已匯入</span>'
                : '<span class="status-new">未匯入</span>';
            var coverSrc  = item.cover_image ? escHtml(item.cover_image) : '';
            var coverHtml = coverSrc ? '<img src="' + coverSrc + '" class="asc-cover-thumb" alt="">' : '—';

            tbody.append($('<tr>').html(
                '<td>' + chk + '</td>' +
                '<td>' + rank + '</td>' +
                '<td>' + coverHtml + '</td>' +
                '<td>' + escHtml(item.title_romaji || '') + '</td>' +
                '<td>' + escHtml(item.format || '') + '</td>' +
                '<td>' + escHtml(String(item.episodes || '—')) + '</td>' +
                '<td>' + escHtml(String(item.popularity || 0)) + '</td>' +
                '<td>' + status + '</td>'
            ));

            cards.append(buildImportCardWithCover({
                anilist_id:   aid,
                title_romaji: '#' + rank + ' ' + (item.title_romaji || ''),
                format:       item.format || '',
                meta1Label:   '集數', meta1Val: item.episodes || '—',
                meta2Label:   '排名', meta2Val: String(rank),
                popularity:   item.popularity,
                imported:     imported,
                cover_image:  item.cover_image || ''
            }, 'ranking-item-check'));
        });

        $('#ranking-select-all').off('change').on('change', function(){
            $('.ranking-item-check:not(:disabled)').prop('checked', $(this).prop('checked'));
        });
    }

    $('#btn-ranking-import').on('click', function(){
        var ids = collectIds('.ranking-item-check');
        if (ids.length === 0) { alert('請勾選至少一部'); return; }
        rankingStop = false;
        runImportQueue('ranking', ids);
    });
    $('#btn-ranking-stop').on('click', function(){ rankingStop = true; $(this).text('停止中…').prop('disabled', true); });

    /* =========================================================================
       TAB 6：動畫化決定（v1.9.0）
    ========================================================================= */
    var announcedStop = false;

    $('#btn-announced-query').on('click', function(){
        var year    = parseInt($('#announced-year-select').val()) || 0;
        var sort    = $('#announced-sort-select').val();
        var formats = [];
        $('.announced-format-check:checked').each(function(){ formats.push($(this).val()); });
        if (formats.length === 0) { alert('請至少勾選一種格式'); return; }

        $('#btn-announced-query').prop('disabled', true);
        $('#announced-query-spinner').show();
        $('#announced-preview').hide();

        $.post(animeSyncAdmin.ajaxUrl, {
            action:  animeSyncAdmin.actions.query_announced,
            nonce:   animeSyncAdmin.nonce,
            year:    year,
            sort:    sort,
            formats: formats
        }, function(res){
            $('#btn-announced-query').prop('disabled', false);
            $('#announced-query-spinner').hide();
            if (!res.success) { alert('查詢失敗：' + (res.data || '未知錯誤')); return; }

            var list        = res.data.list || [];
            var imported    = list.filter(function(i){ return i.imported; }).length;
            var noDateCount = res.data.no_date_count || 0;

            // 組合摘要文字
            var summaryText = '共找到 ' + list.length + ' 部';
            if (noDateCount > 0) {
                summaryText += '（其中 ' + noDateCount + ' 部日期未定）';
            }
            summaryText += '，' + imported + ' 部已匯入。';
            if (res.data.warning) summaryText += ' ⚠️ ' + res.data.warning;
            $('#announced-preview-summary').html(escHtml(summaryText) + '<strong id="announced-selected-badge" style="color:#63A8FF;margin-left:8px;">（已勾選 0 部）</strong>');

            var tbody = $('#announced-tbody').empty();
            var cards = $('#announced-cards').empty();

            $.each(list, function(i, item){
                // ★ v1.9.1：跳過無效 ID 的資料列
                var aid = validId(item.anilist_id);
                if (aid === null) { return true; }

                var isImported  = item.imported;
                var hasDate     = item.start_date && item.start_date !== '';
                var dateDisplay = hasDate ? escHtml(item.start_date) : '<span class="asc-no-date-badge">日期未定</span>';

                var chk = isImported
                    ? '<input type="checkbox" class="announced-item-check" data-id="' + aid + '" disabled>'
                    : '<input type="checkbox" class="announced-item-check" data-id="' + aid + '" checked>';
                var status = isImported
                    ? '<span class="status-imported">✓ 已匯入</span>'
                    : '<span class="status-new">未匯入</span>';

                tbody.append($('<tr>').toggleClass('status-imported-row', !!isImported).html(
                    '<td>' + chk + '</td>' +
                    '<td>' + escHtml(String(aid)) + '</td>' +
                    '<td>' + escHtml(item.title_romaji || '') + '</td>' +
                    '<td>' + escHtml(item.format || '') + '</td>' +
                    '<td>' + escHtml(String(item.episodes || '—')) + '</td>' +
                    '<td>' + dateDisplay + '</td>' +
                    '<td>' + escHtml(String(item.popularity || 0)) + '</td>' +
                    '<td>' + status + '</td>'
                ));

                cards.append(buildImportCard({
                    anilist_id:   aid,
                    title_romaji: item.title_romaji || '',
                    format:       item.format || '',
                    meta1Label:   '集數',    meta1Val: item.episodes || '—',
                    meta2Label:   '預計開播', meta2Val: formatStartDateText(item.start_date),
                    popularity:   item.popularity,
                    imported:     isImported
                }, 'announced-item-check'));
            });

            function updateAnnouncedSelectedCount() {
                var count = collectIds('.announced-item-check').length;
                $('#announced-selected-badge').text('（已勾選 ' + count + ' 部）');
                if (count > 0) {
                    $('#btn-announced-import').text('第二步：匯入選中的 ' + count + ' 部作品（草稿）');
                } else {
                    $('#btn-announced-import').text('第二步：匯入選中作品（草稿）');
                }
            }

            $('#announced-select-all').off('change').on('change', function(){
                $('.announced-item-check:not(:disabled)').prop('checked', $(this).prop('checked'));
                updateAnnouncedSelectedCount();
            });

            $(document).off('change', '.announced-item-check').on('change', '.announced-item-check', function(){
                var aid = $(this).data('id');
                var isChecked = $(this).prop('checked');
                if (aid) {
                    $('.announced-item-check[data-id="' + aid + '"]').prop('checked', isChecked);
                }
                updateAnnouncedSelectedCount();
            });

            updateAnnouncedSelectedCount();
            $('#announced-preview').show();
        }).fail(function(){
            $('#btn-announced-query').prop('disabled', false);
            $('#announced-query-spinner').hide();
            alert('網路錯誤，請重試');
        });
    });

    $('#btn-announced-import').on('click', function(){
        var ids = collectIds('.announced-item-check');
        if (ids.length === 0) { alert('請勾選至少一部'); return; }
        announcedStop = false;
        runImportQueue('announced', ids);
    });
    $('#btn-announced-stop').on('click', function(){ announcedStop = true; $(this).text('停止中…').prop('disabled', true); });

    /* =========================================================================
       共用：循序匯入佇列
    ========================================================================= */
    var stopFlags = { season: false, batch: false, series: false, ranking: false, announced: false };

    function runImportQueue(prefix, ids) {
        // ★ v1.9.1：進佇列前最後一道防線——過濾 NaN/0/負數並去重，杜絕 #NaN 洗版
        var seen = {};
        ids = (ids || []).filter(function(n){
            var v = validId(n);
            if (v === null || seen[v]) { return false; }
            seen[v] = true;
            return true;
        }).map(function(n){ return parseInt(n, 10); });

        if (ids.length === 0) {
            alert('沒有有效的 ID 可匯入（可能是查詢資料不完整，請稍後重新查詢）。');
            return;
        }

        stopFlags[prefix] = false;
        $('#btn-' + prefix + '-import').hide();
        $('#btn-' + prefix + '-stop').show().text('停止匯入').prop('disabled', false);
        $('#' + prefix + '-progress-wrap').show();
        $('#' + prefix + '-import-log').empty();
        $('#' + prefix + '-throttle-notice').hide();

        var total = ids.length, done = 0, success = 0, failed = 0, skipped = 0;
        updateProgress(prefix, done, total, success, skipped, failed);

        function next(index) {
            if (stopFlags[prefix] || index >= ids.length) {
                $('#btn-' + prefix + '-stop').hide();
                $('#btn-' + prefix + '-import').show();
                var msg = stopFlags[prefix]
                    ? '已停止。成功 ' + success + '，跳過 ' + skipped + '，失敗 ' + failed
                    : '✅ 完成！成功 ' + success + '，跳過 ' + skipped + '，失敗 ' + failed;
                appendLog(prefix + '-import-log', msg, stopFlags[prefix] ? 'log-warning' : 'log-success');
                return;
            }
            var id = ids[index];
            appendLog(prefix + '-import-log', '⏳ 匯入 AniList #' + id + ' …', 'log-info');

            $.post(animeSyncAdmin.ajaxUrl, {
                action:     'anime_sync_import_single',
                nonce:      animeSyncAdmin.nonce,
                anilist_id: id,
                force:      0
            }, function(res){
                done++;
                if (res.success) {
                    if (res.data && res.data.skipped) {
                        skipped++;
                        appendLog(prefix + '-import-log', '⏭ #' + id + ' 已存在，跳過', 'log-skip');
                    } else {
                        success++;
                        var title = (res.data && res.data.title) ? res.data.title : '#' + id;
                        appendLog(prefix + '-import-log', '✅ ' + escHtml(title) + ' 匯入成功', 'log-success');
                    }
                } else {
                    failed++;
                    var errMsg = (res.data && res.data.message) ? res.data.message : '失敗';
                    appendLog(prefix + '-import-log', '❌ #' + id + ' ' + escHtml(errMsg), 'log-error');
                }
                updateProgress(prefix, done, total, success, skipped, failed);
                setTimeout(function(){ next(index + 1); }, 800);
            }).fail(function(){
                done++; failed++;
                appendLog(prefix + '-import-log', '❌ #' + id + ' 網路錯誤', 'log-error');
                updateProgress(prefix, done, total, success, skipped, failed);
                setTimeout(function(){ next(index + 1); }, 800);
            });
        }
        next(0);
    }

    $('#btn-season-stop, #btn-batch-stop, #btn-series-stop, #btn-ranking-stop, #btn-announced-stop').on('click', function(){
        var prefix = $(this).attr('id').replace('btn-','').replace('-stop','');
        stopFlags[prefix] = true;
        $(this).text('停止中…').prop('disabled', true);
    });

})(jQuery);
</script>
