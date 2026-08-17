/* ============================================================
   留言區顯示留言者的動漫評分小標
   路徑：/blocksy-child/assets/js/comment-rating.js
   版本：1.0 — 2026-07-27
   說明：從 SmacgConfig.commentRatings（user_nicename→分數）對照，
         每則 wpDiscuz 留言若作者有評過分，就在留言內容前加「⭐ X.X」。
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
