/**
 * SMACG Share Tracker — 分享按鈕點擊上報
 *
 * 監聽 blocksy-child/single.php 內的 .share-btn（含 .share-copy）。
 * 點擊任一分享按鈕 → AJAX 上報一次（per-page 鎖，同頁多次點擊只算一次）。
 * 後端 per-post 永久去重。
 *
 * @version 2.7.0
 */
(function () {
    'use strict';

    if (typeof window.SmacgShare === 'undefined') return;
    if (!SmacgShare.post_id || !SmacgShare.nonce) return;

    var fired = false;

    function report() {
        if (fired) return;
        fired = true;

        var data = new FormData();
        data.append('action', 'smacg_post_shared');
        data.append('nonce', SmacgShare.nonce);
        data.append('post_id', SmacgShare.post_id);

        fetch(SmacgShare.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).catch(function () {
            fired = false;
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.share-btn');
        if (!btn) return;
        // 任何 .share-btn 包含 .share-copy 都算
        report();
    }, true);
})();
