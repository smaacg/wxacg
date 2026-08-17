/**
 * 微笑動漫 — page-template.js
 * v2.0.0 (2026-05-20)
 *
 * ============================================================
 * ⚠️ 此檔案已棄用（DEPRECATED）
 * ============================================================
 *
 * 變更紀錄：
 * - v2.0.0 (2026-05-20)：
 *   完全清空。所有功能已搬移至 nav.js v1.1.0。
 *
 *   原本的功能對照表：
 *   ┌──────────────────────┬──────────────────────────────┐
 *   │ 原功能               │ 現由 nav.js 哪個模組接管     │
 *   ├──────────────────────┼──────────────────────────────┤
 *   │ Sticky Header        │ StickyHeader                 │
 *   │ Mobile Nav 漢堡開關  │ MobileMenu                   │
 *   │ Dropdown 子選單      │ MobileMenu                   │
 *   │ 點外部關閉選單       │ MobileMenu                   │
 *   │ Search Overlay       │ SearchOverlay                │
 *   └──────────────────────┴──────────────────────────────┘
 *
 *   保留此檔案（而非刪除）的原因：
 *   1. functions.php 中的 wp_enqueue_script('weixiaoacg-page-template')
 *      仍會嘗試載入此檔案，刪除會造成 404 與 PHP warning。
 *   2. 未來如有頁面層級工具函式需要加入，可直接寫在此檔。
 *
 * 修復的 Bug：
 *   - 此檔案原本與 nav.js 重複綁定 #mobile-menu-toggle 的 click 事件，
 *     導致漢堡按鈕點擊後 mobile-open class 被先加後減，選單永遠打不開。
 *     詳見 nav.js v1.1.0 changelog。
 */

'use strict';

// 此檔案已棄用，所有邏輯由 nav.js v1.1.0 接管。
// 若未來需新增頁面層級的工具函式，可在此處編寫。

document.addEventListener('DOMContentLoaded', () => {
    console.log('[page-template.js v2.0.0] deprecated, all logic moved to nav.js');
});
