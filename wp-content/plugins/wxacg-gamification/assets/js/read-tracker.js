/**
 * SMACG Read Tracker — 文章閱讀 30 秒計時
 *
 * 規則：
 *   - 頁面 hidden 時暫停計時（切到其他分頁不算）
 *   - 達 SmacgRead.delay 秒（預設 30）→ AJAX 上報一次
 *   - 上報後本頁不再計時（per-load 鎖）
 *   - 後端 per-post 永久去重，重複上報無效
 *
 * @version 2.7.0
 */
(function () {
    'use strict';

    if (typeof window.SmacgRead === 'undefined') return;
    if (!SmacgRead.post_id || !SmacgRead.nonce) return;

    var elapsed = 0;
    var delay = parseInt(SmacgRead.delay, 10) || 30;
    var fired = false;
    var lastTick = Date.now();
    var timer = null;

    function tick() {
        if (fired) return;
        var now = Date.now();
        if (!document.hidden) {
            elapsed += (now - lastTick) / 1000;
            if (elapsed >= delay) {
                report();
            }
        }
        lastTick = now;
    }

    function report() {
        if (fired) return;
        fired = true;

        var data = new FormData();
        data.append('action', 'smacg_post_read');
        data.append('nonce', SmacgRead.nonce);
        data.append('post_id', SmacgRead.post_id);

        fetch(SmacgRead.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).catch(function () {
            // 失敗就算了，下次造訪會再試
            fired = false;
        });

        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        lastTick = Date.now();
    });

    timer = setInterval(tick, 1000);
})();
