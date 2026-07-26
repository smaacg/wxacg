/**
 * Public Profile JS - /u/{username}/
 * Version: 1.1.0 (2026-05-16)
 *
 * 功能：
 *  - Tab 切換 + URL hash 同步
 *  - 清單篩選（沿用 member.js 風格）
 *  - 分享按鈕（Web Share API + 複製連結 fallback）
 *
 * Changelog:
 * - 1.1.0 (2026-05-16) Bug #18 修正：selector 與 PHP render 端對齊
 *   * .pp-filter-btn          → .pp-filter
 *   * active class 'active'   → 'pp-filter-active'（與 PHP 端一致）
 *   * #pp-watchlist-grid      → .pp-watchlist .pp-anime-grid
 *   * .pp-btn-share           → .pp-share-btn
 *   * 移除 .pp-btn-follow（追蹤按鈕已由 follow.js 接管 .smacg-follow-btn）
 *   * 'favorited' 篩選同時匹配 data-favorited="1" 或 data-status="favorited"
 * - 1.0.0 (2026-05-13) 初始版本
 */
(function () {
  'use strict';

  const wrap = document.querySelector('.pp-wrap');
  if (!wrap) return;

  /* =========================================================
   * Tab 切換
   * ========================================================= */
  const tabs   = wrap.querySelectorAll('.pp-tab');
  const panels = wrap.querySelectorAll('.pp-panel');

  function switchTab(name, updateHash) {
    let found = false;
    tabs.forEach(t => {
      const match = t.dataset.tab === name;
      t.classList.toggle('active', match);
      if (match) found = true;
    });
    panels.forEach(p => {
      p.classList.toggle('active', p.dataset.panel === name);
    });
    if (found && updateHash && history.replaceState) {
      history.replaceState(null, '', '#' + name);
    }
    return found;
  }

  tabs.forEach(t => {
    t.addEventListener('click', () => switchTab(t.dataset.tab, true));
  });

  // 初始 hash → tab
  (function initFromHash() {
    const hash = (window.location.hash || '').replace('#', '');
    if (hash) {
      setTimeout(() => switchTab(hash, false), 30);
    }
  })();

  window.addEventListener('hashchange', () => {
    const hash = (window.location.hash || '').replace('#', '');
    if (hash) switchTab(hash, false);
  });

  /* =========================================================
   * Watchlist 篩選
   *  - selector 對齊 PHP：.pp-filter / .pp-filter-active
   *  - 卡片容器：.pp-watchlist .pp-anime-grid
   *  - 卡片屬性：data-status / data-favorited
   * ========================================================= */
  const filterBtns = wrap.querySelectorAll('.pp-watchlist .pp-filter');
  const watchlistGrid = wrap.querySelector('.pp-watchlist .pp-anime-grid');

  function applyFilter(filter) {
    if (!watchlistGrid) return;
    const cards = watchlistGrid.querySelectorAll('.pp-anime-card');
    cards.forEach(card => {
      const status = card.dataset.status || '';
      const fav    = card.dataset.favorited === '1';
      let show     = false;

      if (filter === 'all') {
        show = true;
      } else if (filter === 'favorited') {
        // 同時匹配 data-status="favorited" 或 data-favorited="1"
        show = fav || status === 'favorited';
      } else {
        show = (status === filter);
      }
      card.style.display = show ? '' : 'none';
    });
  }

  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const filter = btn.dataset.filter || 'all';
      filterBtns.forEach(b => b.classList.toggle('pp-filter-active', b === btn));
      applyFilter(filter);
    });
  });

  /* =========================================================
   * 分享按鈕（PHP class: .pp-share-btn）
   * ========================================================= */
  const shareBtn = wrap.querySelector('.pp-share-btn');
  if (shareBtn) {
    shareBtn.addEventListener('click', async () => {
      const url   = shareBtn.dataset.url   || window.location.href;
      const title = shareBtn.dataset.title || document.title;

      // 優先用原生 Web Share API（手機）
      if (navigator.share) {
        try {
          await navigator.share({ title, url });
          return;
        } catch (e) {
          if (e.name === 'AbortError') return;
        }
      }

      // Fallback：複製到剪貼簿
      try {
        await navigator.clipboard.writeText(url);
        toast('連結已複製 ✓', true);
      } catch (e) {
        const input = document.createElement('input');
        input.value = url;
        document.body.appendChild(input);
        input.select();
        try {
          document.execCommand('copy');
          toast('連結已複製 ✓', true);
        } catch (err) {
          toast('複製失敗，請手動複製網址', false);
        }
        document.body.removeChild(input);
      }
    });
  }

  /* =========================================================
   * Toast（公開頁專用）
   * ========================================================= */
  function toast(text, ok) {
    let el = document.getElementById('pp-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'pp-toast';
      el.className = 'pp-toast';
      document.body.appendChild(el);
    }
    el.textContent = text;
    el.className = 'pp-toast pp-toast--show pp-toast--' + (ok ? 'ok' : 'err');

    clearTimeout(el._t);
    el._t = setTimeout(() => {
      el.classList.remove('pp-toast--show');
    }, 1800);
  }

  /* =========================================================
   * 追蹤按鈕：由 follow.js 統一接管 .smacg-follow-btn
   * 此處不再處理。
   * ========================================================= */

  /* =========================================================
   * 平滑滾動到 tab（從 URL hash 進來時）
   * ========================================================= */
  (function scrollToTabIfHash() {
    if (!window.location.hash) return;
    const tabsNav = wrap.querySelector('.pp-tabs');
    if (!tabsNav) return;
    setTimeout(() => {
      const top = tabsNav.getBoundingClientRect().top + window.pageYOffset - 80;
      window.scrollTo({ top, behavior: 'smooth' });
    }, 100);
  })();

})();
