/**
 * Career Selection - 職業選擇前端
 *
 * @package weixiaoacg
 * @subpackage Gamification
 * @version 1.0.0 (2026-05-14)
 *
 * Batch 2A-4：
 *   - 監聽 .smacg-career-card[data-job] 點擊
 *   - 二次確認（confirm dialog）
 *   - AJAX → smacg_select_career
 *   - 成功 → toast + 重新整理；失敗 → toast
 *
 * 依賴：smacgCareer（由 setup-enqueue.php localize）
 *   - ajax, nonce, loggedIn, userLevel, currentJob, loginUrl
 */
(function () {
    'use strict';

    if (typeof smacgCareer === 'undefined') return;

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var grid = document.querySelector('.smacg-career-grid');
        if (!grid) return;

        grid.addEventListener('click', onCardClick);
    }

    function onCardClick(e) {
        var card = e.target.closest('.smacg-career-card[data-job]');
        if (!card) return;

        e.preventDefault();

        // 未登入
        if (!smacgCareer.loggedIn) {
            window.location.href = smacgCareer.loginUrl || '/wp-login.php';
            return;
        }

        // 已選 → 不能改（A 方案）
        if (smacgCareer.currentJob) {
            toast('你已選擇職業，無法變更', 'err');
            return;
        }

        // 等級不夠
        if (parseInt(smacgCareer.userLevel, 10) < 10) {
            toast('需要 Lv.10 才能選擇職業', 'err');
            return;
        }

        var jobKey   = card.getAttribute('data-job');
        var jobLabel = card.querySelector('.smacg-career-card__label');
        var labelTxt = jobLabel ? jobLabel.textContent.trim() : jobKey;

        // 二次確認
        var ok = window.confirm(
            '確定要成為「' + labelTxt + '」嗎？\n\n' +
            '⚠️ 職業一經選定，將無法變更。'
        );
        if (!ok) return;

        submit(card, jobKey);
    }

    function submit(card, jobKey) {
        // UI lock
        var allCards = document.querySelectorAll('.smacg-career-card[data-job]');
        allCards.forEach(function (c) { c.classList.add('is-locked'); });
        card.classList.add('is-loading');

        var fd = new FormData();
        fd.append('action', 'smacg_select_career');
        fd.append('nonce',  smacgCareer.nonce);
        fd.append('job',    jobKey);

        fetch(smacgCareer.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            card.classList.remove('is-loading');
            if (res && res.success) {
                toast(res.data.message || '已選定職業', 'ok');
                // 1.5s 後重新整理頁面
                setTimeout(function () { window.location.reload(); }, 1500);
            } else {
                allCards.forEach(function (c) { c.classList.remove('is-locked'); });
                var msg = (res && res.data && res.data.message) ? res.data.message : '操作失敗，請稍後再試';
                toast(msg, 'err');
            }
        })
        .catch(function () {
            card.classList.remove('is-loading');
            allCards.forEach(function (c) { c.classList.remove('is-locked'); });
            toast('網路錯誤，請稍後再試', 'err');
        });
    }

    // ----- Toast -----
    function toast(msg, type) {
        var t = document.createElement('div');
        t.className = 'smacg-career-toast smacg-career-toast--' + (type === 'ok' ? 'ok' : 'err');
        t.textContent = msg;
        document.body.appendChild(t);
        // force reflow → 顯示動畫
        // eslint-disable-next-line no-unused-expressions
        t.offsetHeight;
        t.classList.add('is-show');
        setTimeout(function () {
            t.classList.remove('is-show');
            setTimeout(function () { t.remove(); }, 250);
        }, 2200);
    }
})();
