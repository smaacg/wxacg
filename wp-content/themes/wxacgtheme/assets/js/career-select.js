/**
 * Career Select — 職業選擇 AJAX 流程
 * v2.0.0 (2026-05-15)
 *
 * 流程：
 *   1. 使用者點 8 卡之一 → 跳出確認對話框
 *   2. 「再想想」→ 關閉對話框
 *   3. 「確認選擇」→ POST 到 wp-admin/admin-ajax.php?action=smacg_select_career
 *   4. 成功 → 顯示訊息 + 1.2 秒後重新載入頁面（顯示「進化路線」狀態）
 *   5. 失敗 → 顯示錯誤訊息（冷卻中、未登入、Lv 不足等）
 *
 * 依賴：window.SmacgCareer = { ajax_url, nonce }（由 class-plugin.php localize）
 */
(function () {
    'use strict';

    if (typeof window.SmacgCareer === 'undefined') {
        console.warn('[Career] SmacgCareer config not found');
        return;
    }

    /* ─────────── 元素 ─────────── */
    const grid    = document.querySelector('.mc-career-choose .mc-career-grid');
    const modal   = document.getElementById('mc-career-confirm');
    const changeBtn = document.getElementById('mc-career-change-btn');
    if (!grid && !changeBtn) return;

    /* ─────────── 狀態 ─────────── */
    let pendingKey   = '';
    let pendingLabel = '';
    let isSubmitting = false;

    /* ─────────── 工具 ─────────── */
    function showMsg(text, type) {
        if (!modal) return;
        const msg = modal.querySelector('#mc-career-confirm-msg');
        if (!msg) return;
        msg.textContent = text;
        msg.classList.remove('is-error', 'is-success');
        msg.classList.add(type === 'error' ? 'is-error' : 'is-success');
        msg.hidden = false;
    }

    function clearMsg() {
        if (!modal) return;
        const msg = modal.querySelector('#mc-career-confirm-msg');
        if (!msg) return;
        msg.hidden = true;
        msg.textContent = '';
        msg.classList.remove('is-error', 'is-success');
    }

    function openModal(key, label) {
        if (!modal) return;
        pendingKey   = key;
        pendingLabel = label;
        clearMsg();

        const lbl = modal.querySelector('#mc-career-confirm-label');
        if (lbl) lbl.textContent = label;

        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        /* 對焦到「確認選擇」按鈕 */
        const confirmBtn = modal.querySelector('[data-action="confirm"]');
        if (confirmBtn) setTimeout(() => confirmBtn.focus(), 50);
    }

    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
        pendingKey = '';
        pendingLabel = '';
        clearMsg();
    }

    /* ─────────── AJAX 送出 ─────────── */
    async function submitCareer() {
        if (isSubmitting || !pendingKey) return;
        isSubmitting = true;

        const confirmBtn = modal.querySelector('[data-action="confirm"]');
        const cancelBtn  = modal.querySelector('[data-action="cancel"]');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.textContent = '送出中…';
        }
        if (cancelBtn) cancelBtn.disabled = true;

        const fd = new FormData();
        fd.append('action', 'smacg_select_career');
        fd.append('nonce',  window.SmacgCareer.nonce);
        fd.append('career', pendingKey);

        try {
            const res  = await fetch(window.SmacgCareer.ajax_url, {
                method:      'POST',
                credentials: 'same-origin',
                body:        fd
            });
            const json = await res.json();

            if (json.success) {
                showMsg('✓ ' + (json.data.message || '已選擇職業'), 'success');
                /* 1.2 秒後刷新，顯示進化路線狀態 */
                setTimeout(() => window.location.reload(), 1200);
            } else {
                const errMsg = (json.data && json.data.message) || '送出失敗，請稍後再試';
                showMsg('✗ ' + errMsg, 'error');
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '確認選擇';
                }
                if (cancelBtn) cancelBtn.disabled = false;
                isSubmitting = false;
            }
        } catch (err) {
            console.error('[Career] AJAX error', err);
            showMsg('✗ 網路錯誤，請稍後再試', 'error');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = '確認選擇';
            }
            if (cancelBtn) cancelBtn.disabled = false;
            isSubmitting = false;
        }
    }

    /* ─────────── 綁定：8 卡點擊 ─────────── */
    if (grid) {
        grid.addEventListener('click', function (e) {
            const card = e.target.closest('.mc-career-card');
            if (!card) return;
            const key   = card.dataset.jobKey   || '';
            const label = card.dataset.jobLabel || card.querySelector('.mc-career-card-name')?.textContent || '';
            if (!key) return;
            openModal(key, label);
        });
    }

    /* ─────────── 綁定：「變更職業」按鈕（冷卻結束後出現）─────────── */
    if (changeBtn) {
        changeBtn.addEventListener('click', function () {
            if (!confirm('變更職業會重置進化路線稱號，確定要繼續嗎？')) return;
            /* 簡化做法：清除 meta 後重新整理，使用者會回到「選擇器」狀態 */
            const fd = new FormData();
            fd.append('action', 'smacg_select_career');
            fd.append('nonce',  window.SmacgCareer.nonce);
            fd.append('career', ''); /* 空 → 後端會回錯誤 */

            /* 觸發後端的冷卻檢查；冷卻已過 → 進入「重新選擇」流程 */
            window.location.href = window.location.pathname + '?tab=career&change=1';
        });
    }

    /* ─────────── 綁定：對話框操作 ─────────── */
    if (modal) {
        modal.addEventListener('click', function (e) {
            /* 點背景關閉 */
            if (e.target === modal) {
                if (!isSubmitting) closeModal();
                return;
            }
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const action = btn.dataset.action;
            if (action === 'cancel') {
                if (!isSubmitting) closeModal();
            } else if (action === 'confirm') {
                submitCareer();
            }
        });

        /* Esc 關閉 */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden && !isSubmitting) {
                closeModal();
            }
        });
    }
})();
