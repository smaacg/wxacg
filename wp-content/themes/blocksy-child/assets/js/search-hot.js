/* ============================================================
   微笑動漫 — 最近搜尋 + 熱門搜尋
   路徑：/blocksy-child/assets/js/search-hot.js
   版本：1.0.0 (2026-07-24)
   說明：
     - 輸入框聚焦且為空 → 顯示「最近搜尋(localStorage)＋熱門搜尋(REST)」
     - 開始打字(≥2字) → 交回 header.js 原本的即時搜尋結果
     - 送出搜尋時 → 記錄到 localStorage，並打 REST 熱門 +1
   ============================================================ */
'use strict';

(function () {

    const REST_BASE = '/wp-json/weixiaoacg/v1/search-hot';
    const LS_KEY    = 'wxacg_recent_search';
    const MAX_RECENT = 10;

    const input    = document.getElementById('header-search-input');
    const dropdown = document.getElementById('header-search-dropdown');
    const submitBtn = document.getElementById('header-search-submit');
    if (!input || !dropdown) return;

    let hotCache = null; // 快取熱門，避免每次聚焦都打 API

    /* ── localStorage：最近搜尋 ── */
    function getRecent() {
        try {
            const arr = JSON.parse(localStorage.getItem(LS_KEY) || '[]');
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }
    function addRecent(q) {
        q = (q || '').trim();
        if (!q) return;
        let arr = getRecent().filter(function (x) { return x !== q; });
        arr.unshift(q);
        arr = arr.slice(0, MAX_RECENT);
        try { localStorage.setItem(LS_KEY, JSON.stringify(arr)); } catch (e) {}
    }
    function removeRecent(q) {
        const arr = getRecent().filter(function (x) { return x !== q; });
        try { localStorage.setItem(LS_KEY, JSON.stringify(arr)); } catch (e) {}
    }
    function clearRecent() {
        try { localStorage.removeItem(LS_KEY); } catch (e) {}
    }

    /* ── 記錄熱門（送出時 +1）── */
    function hitHot(q) {
        q = (q || '').trim();
        if (!q) return;
        fetch(REST_BASE + '/hit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ q: q }),
            keepalive: true
        }).catch(function () {});
    }

    /* ── 讀取熱門 ── */
    function loadHot() {
        if (hotCache) return Promise.resolve(hotCache);
        return fetch(REST_BASE + '/top?limit=8', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                hotCache = (d && d.success && Array.isArray(d.data)) ? d.data : [];
                return hotCache;
            })
            .catch(function () { return []; });
    }

    /* ── HTML escape ── */
    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    /* ── 渲染面板 ── */
    function renderPanel(hot) {
        const recent = getRecent();
        let html = '';

        if (recent.length) {
            html += '<div class="wxacg-sh-block">';
            html += '<div class="wxacg-sh-head"><i class="fa-solid fa-clock-rotate-left"></i> 最近搜尋'
                 +  '<button type="button" class="wxacg-sh-clear" data-sh-clear>清除</button></div>';
            html += '<div class="wxacg-sh-tags">';
            recent.forEach(function (q) {
                html += '<span class="wxacg-sh-tag" data-sh-q="' + esc(q) + '">'
                     +  esc(q)
                     +  '<i class="fa-solid fa-xmark wxacg-sh-tag-x" data-sh-remove="' + esc(q) + '"></i>'
                     +  '</span>';
            });
            html += '</div></div>';
        }

        if (hot && hot.length) {
            html += '<div class="wxacg-sh-block">';
            html += '<div class="wxacg-sh-head"><i class="fa-solid fa-fire"></i> 熱門搜尋</div>';
            html += '<div class="wxacg-sh-tags">';
            hot.forEach(function (item) {
                html += '<span class="wxacg-sh-tag wxacg-sh-tag--hot" data-sh-q="' + esc(item.q) + '">'
                     +  esc(item.q) + '</span>';
            });
            html += '</div></div>';
        }

        if (!html) {
            dropdown.classList.remove('open');
            dropdown.innerHTML = '';
            return;
        }

        dropdown.innerHTML = html;
        dropdown.classList.add('open');
    }

    /* ── 顯示「聚焦面板」（僅在空字串時）── */
    function showFocusPanel() {
        if (input.value.trim().length >= 2) return; // 有在打字 → 交給 header.js
        loadHot().then(renderPanel);
    }

    /* ── 送出（帶入關鍵字並跳頁）── */
    function submitQuery(q) {
        q = (q || '').trim();
        if (!q) return;
        input.value = q;
        addRecent(q);
        hitHot(q);
        const H = (typeof weixiaoacg_header !== 'undefined') ? weixiaoacg_header : {};
        if (H.search_url) {
            window.location.href = H.search_url + encodeURIComponent(q);
        }
    }

    /* ── 事件：聚焦顯示面板 ── */
    input.addEventListener('focus', showFocusPanel);
    input.addEventListener('click', showFocusPanel);

    /* ── 事件：打字時若清空又變回空 → 重新顯示面板 ── */
    input.addEventListener('input', function () {
        if (input.value.trim().length === 0) showFocusPanel();
    });

    /* ── 事件：記錄自己送出的關鍵字（Enter / 搜尋鈕）── */
    // header.js 也會處理跳頁，這裡只負責「記錄」，不重複跳頁。
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            const q = input.value.trim();
            if (q) { addRecent(q); hitHot(q); }
        }
    });
    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            const q = input.value.trim();
            if (q) { addRecent(q); hitHot(q); }
        });
    }

    /* ── 事件：點面板裡的標籤 / 刪除 / 清除 ── */
    dropdown.addEventListener('click', function (e) {
        // 刪除單一最近搜尋
        const rm = e.target.closest('[data-sh-remove]');
        if (rm) {
            e.stopPropagation();
            removeRecent(rm.getAttribute('data-sh-remove'));
            loadHot().then(renderPanel);
            return;
        }
        // 清除全部
        const clr = e.target.closest('[data-sh-clear]');
        if (clr) {
            e.stopPropagation();
            clearRecent();
            loadHot().then(renderPanel);
            return;
        }
        // 點標籤 → 直接搜尋
        const tag = e.target.closest('[data-sh-q]');
        if (tag) {
            e.preventDefault();
            submitQuery(tag.getAttribute('data-sh-q'));
        }
    });

})();
