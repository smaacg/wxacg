/**
 * /upcoming-anime/ 即時篩選
 * Anime Sync Pro — upcoming-filters.js
 *
 * 只負責一件事：格式／原作類型／動漫類型可疊加的即時篩選，不重載頁面。
 * 資料來源是 archive-anime.php 在 $is_upcoming 時，於每張 .aaa-card 上
 * 補的 data-format / data-source / data-genres 屬性（source、genres 可能
 * 多值，用 | 分隔）。
 *
 * 刻意獨立成小檔案、不掛去主題的 bangumi.js：那支綁了星期分頁、時間表
 * 視圖、卡片觸控手勢等這頁完全用不到的邏輯，接上去是拖累不是重用。
 *
 * 篩選狀態不寫回網址（bangumi.js 那套季度頁會同步到 URL）——這頁刻意
 * 簡化掉，重新整理會重置篩選。
 *
 * 純原生 JS，不依賴 jQuery。
 */

'use strict';

(function () {

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function init() {
        var wrap = qs('#aaa-upcoming-filters');
        var grid = qs('#aaa-grid');
        if (!wrap || !grid) return;

        var cards = qsa('.aaa-card', grid);
        if (!cards.length) return;

        var state = { format: '', source: '', genre: '' };
        var countEl = qs('#aaa-visible-count');
        var resetBtn = qs('#aaa-upcoming-filter-reset');

        function hasActiveFilter() {
            return !!(state.format || state.source || state.genre);
        }

        function cardMatches(card) {
            if (state.format && (card.dataset.format || '') !== state.format) {
                return false;
            }
            if (state.source) {
                var sources = (card.dataset.source || '').split('|');
                if (sources.indexOf(state.source) < 0) return false;
            }
            if (state.genre) {
                var genres = (card.dataset.genres || '').split('|');
                if (genres.indexOf(state.genre) < 0) return false;
            }
            return true;
        }

        function apply() {
            var visible = 0;
            cards.forEach(function (card) {
                var ok = cardMatches(card);
                card.classList.toggle('is-hidden-by-filter', !ok);
                if (ok) visible++;
            });

            if (countEl) countEl.textContent = visible;

            qsa('.aaa-filter-btn[data-key]', wrap).forEach(function (btn) {
                var key = btn.getAttribute('data-key');
                var val = btn.getAttribute('data-value');
                btn.classList.toggle('active', state[key] === val);
            });

            if (resetBtn) resetBtn.hidden = !hasActiveFilter();
        }

        qsa('.aaa-filter-btn[data-key]', wrap).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-key');
                var val = btn.getAttribute('data-value');
                // 再點一次同一個值 = 取消該篩選
                state[key] = (state[key] === val) ? '' : val;
                apply();
            });
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                state = { format: '', source: '', genre: '' };
                apply();
            });
        }

        apply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
