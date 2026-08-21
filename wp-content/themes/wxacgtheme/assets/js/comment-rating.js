/* ============================================================
   留言區顯示留言者的動漫評分小標
   路徑：/blocksy-child/assets/js/comment-rating.js
   版本：1.0 — 2026-07-27
   說明：從 SmacgConfig.commentRatings（user_nicename→分數）對照，
         每則 wpDiscuz 留言若作者有評過分，就在留言內容前加「⭐ X.X」。

   ⚠ 2026-08-22 起已停止載入（見 inc/setup-enqueue.php）
   ------------------------------------------------------------
   留言區 2026-08-18 由 wpDiscuz 改為自建評論系統後，本檔依賴的
   .wpd-comment / .wpd-comment-text / #wpdcom 元素都不再存在，
   整支腳本不會有任何作用。

   同樣的功能已由自建系統以更好的方式提供：後端直接回傳分數
   （anime-sync-pro/includes/class-review-manager.php 的 'score'
     欄位），前端 review.js 渲染成 .asd-review-score-tag，
   不需要前端拿使用者名稱去對照。

   檔案保留未刪，與各模板註解的做法一致：日後若要切回 wpDiscuz，
   恢復 setup-enqueue.php 的載入行與 commentRatings 查詢即可。
   ============================================================ */
'use strict';

(function () {
    var cfg = window.SmacgConfig || {};
    var ratings = cfg.commentRatings || {};
    // 沒有任何評分資料就不啟動
    if (!ratings || Object.keys(ratings).length === 0) return;

    /* 從留言 class 取 user_nicename：class 內 comment-author-XXX */
    function getNicename(commentEl) {
        var cls = commentEl.className || '';
        var m = cls.match(/comment-author-([^\s]+)/);
        return m && m[1] ? m[1].toLowerCase() : '';
    }

    /* 對單一留言插入評分小標 */
    function decorate(commentEl) {
        if (!commentEl || commentEl.dataset.wacgRated === '1') return;

        var nicename = getNicename(commentEl);
        if (!nicename || !(nicename in ratings)) return;

        var textEl = commentEl.querySelector('.wpd-comment-text');
        if (!textEl) return;

        // 已插過就跳過
        if (textEl.querySelector('.wacg-comment-score')) {
            commentEl.dataset.wacgRated = '1';
            return;
        }

        var score = parseFloat(ratings[nicename]);
        if (!isFinite(score)) return;

        var badge = document.createElement('span');
        badge.className = 'wacg-comment-score';
        badge.textContent = '⭐ ' + score.toFixed(1);

        textEl.insertBefore(badge, textEl.firstChild);
        commentEl.dataset.wacgRated = '1';
    }

    /* 掃描一個範圍內所有留言 */
    function scan(root) {
        var scope = root || document;
        var comments = scope.querySelectorAll('.wpd-comment');
        comments.forEach(decorate);
        // root 本身若就是留言
        if (root && root.classList && root.classList.contains('wpd-comment')) {
            decorate(root);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);

        // wpDiscuz AJAX 載入留言 → MutationObserver 持續處理後續留言
        var target = document.getElementById('wpdcom') || document.body;
        var obs = new MutationObserver(function (mutations) {
            mutations.forEach(function (mu) {
                mu.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        });
        obs.observe(target, { childList: true, subtree: true });
    });
})();
