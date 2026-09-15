/**
 * Level Guide Page interactions
 * - 量測固定頁首高度，供 sticky 章節導覽定位
 * - TOC active section highlight (IntersectionObserver)
 * - 職業天命分頁切換
 * - Season countdown timer
 *
 * 2026-09-15：本檔原本整支都沒有作用——裡面找的 .lg-toc__item、
 * .lg-section、#lg-level-toggle、#lg-level-table、.lg-season-now、
 * #lg-countdown 在改版成 guide-* class 之後全部不存在，
 * 三個功能（目錄高亮、等級表收合、賽季倒數）都靜靜地 return 掉。
 * 此次把選擇器接回現行 markup，並移除等級表收合（對應的 200 級表
 * 已經不在頁面上了，現在的 #level 是 6 階會員身份卡）。
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initHeaderOffset();
    initTocHighlight();
    initJobTabs();
    initSeasonCountdown();
    initHashLanding();
  });

  /* ---------- 固定頁首高度 ---------- */
  /*
   * 章節導覽要吸附在頁首正下方，但 #site-header 是 position:sticky top:0
   * 且高度由內容撐出來（實測桌機 155px、手機 60px），
   * 寫死任何一個值在另一個斷點都會錯位，所以量完寫進 --lg-header-h 給 CSS 用。
   *
   * 順便算出錨點要讓開的總高度（頁首 + 吸附導覽列 + 一點餘裕）寫進
   * --lg-anchor-offset，給 .guide-section 的 scroll-margin-top 用——
   * 沒有它的話跳到某一節，該節標題會整個被導覽列蓋住。
   */
  function initHeaderOffset() {
    var header = document.getElementById('site-header');
    var toc    = document.querySelector('.level-guide-page .guide-toc');

    function apply() {
      var hh = header ? Math.round(header.getBoundingClientRect().height) : 0;
      var th = toc ? Math.round(toc.getBoundingClientRect().height) : 0;
      var root = document.documentElement;
      root.style.setProperty('--lg-header-h', hh + 'px');
      root.style.setProperty('--lg-anchor-offset', (hh + th + 12) + 'px');
    }

    apply();
    window.addEventListener('resize', apply);
  }

  /* ---------- TOC 章節高亮 ---------- */
  function initTocHighlight() {
    var sections = document.querySelectorAll('.guide-section[id]');
    var tocLinks = document.querySelectorAll('.guide-toc-chip');
    if (!sections.length || !tocLinks.length) return;

    var byId = {};
    tocLinks.forEach(function (a) {
      var id = a.getAttribute('href').replace('#', '');
      byId[id] = a;
    });

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          tocLinks.forEach(function (a) { a.classList.remove('is-active'); });
          var link = byId[entry.target.id];
          if (link) {
            link.classList.add('is-active');
            keepChipInView(link);
          }
        }
      });
    }, { rootMargin: '-30% 0px -60% 0px' });

    sections.forEach(function (s) { observer.observe(s); });
  }

  /*
   * 手機版導覽列是橫向捲動的，高亮到畫面外的 chip 要自己帶回視野。
   * 這裡只動導覽列本身的 scrollLeft，不用 scrollIntoView——
   * 後者會連帶捲動整個頁面，跟使用者正在做的捲動打架。
   */
  function keepChipInView(link) {
    var nav = link.parentNode;
    if (!nav || nav.scrollWidth <= nav.clientWidth) return;
    nav.scrollLeft = link.offsetLeft - (nav.clientWidth - link.offsetWidth) / 2;
  }

  /* ---------- 職業天命分頁 ---------- */
  /*
   * 9 張職業卡全部攤開時，手機要滑掉 7 個螢幕（實測 5,350px，佔全頁 30.7%），
   * 是整頁最長的一段。改成一次只顯示一張。
   *
   * 卡片仍然整批由 PHP 輸出，只由 JS 補 hidden：
   * 沒有 JS 時維持原本全部展開的樣子，搜尋引擎也照樣讀得到完整內容。
   * 代價是被收起來的職業 Ctrl+F 找不到。
   */
  function initJobTabs() {
    var grid = document.querySelector('.level-guide-page .guide-job-grid');
    if (!grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.guide-job-card'));
    if (cards.length < 2) return;

    var tabs = document.createElement('div');
    tabs.className = 'guide-job-tabs';
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', '職業切換');

    var buttons = cards.map(function (card, i) {
      var icon = card.querySelector('.guide-job-icon');
      var name = card.querySelector('.guide-job-name');

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'guide-job-tab';
      btn.setAttribute('role', 'tab');

      if (card.id) {
        btn.id = 'tab-' + card.id;
        btn.setAttribute('aria-controls', card.id);
        card.setAttribute('role', 'tabpanel');
        card.setAttribute('aria-labelledby', btn.id);
      }

      var sIcon = document.createElement('span');
      sIcon.className = 'guide-job-tab-icon';
      sIcon.textContent = icon ? icon.textContent.trim() : '';

      var sName = document.createElement('span');
      sName.className = 'guide-job-tab-name';
      sName.textContent = name ? name.textContent.trim() : ('職業 ' + (i + 1));

      btn.appendChild(sIcon);
      btn.appendChild(sName);

      // 自己的職業在分頁列上也要看得出來，不用點開才知道
      if (card.classList.contains('is-mine')) btn.classList.add('is-mine');

      btn.addEventListener('click', function () { activate(i); });
      btn.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowRight') next = (i + 1) % cards.length;
        else if (e.key === 'ArrowLeft') next = (i - 1 + cards.length) % cards.length;
        if (next === null) return;
        e.preventDefault();
        activate(next);
        buttons[next].focus();
      });

      tabs.appendChild(btn);
      return btn;
    });

    function activate(idx) {
      cards.forEach(function (c, i) { c.hidden = (i !== idx); });
      buttons.forEach(function (b, i) {
        var on = (i === idx);
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
      });
      revealTab(buttons[idx]);
    }

    /*
     * 分頁列在手機是橫向捲動的，選中的那個可能落在畫面外。
     * 只有真的沒完整露出時才捲，免得點一個本來就看得到的分頁還被推來推去。
     */
    function revealTab(btn) {
      if (!btn || tabs.scrollWidth <= tabs.clientWidth) return;
      var left  = tabs.scrollLeft;
      var right = left + tabs.clientWidth;
      if (btn.offsetLeft >= left && btn.offsetLeft + btn.offsetWidth <= right) return;
      tabs.scrollLeft = Math.max(0, btn.offsetLeft - (tabs.clientWidth - btn.offsetWidth) / 2);
    }

    /*
     * 捲軸在這裡是隱藏的，沒有「可以往右拉」的視覺提示，
     * 使用者會以為溢出的分頁不見了（2026-09-15 回報）。
     * 有溢出時掛上 is-scrollable，由 CSS 在右緣加漸層；捲到底就拿掉。
     */
    function syncScrollHint() {
      var overflow = tabs.scrollWidth > tabs.clientWidth + 1;
      tabs.classList.toggle('is-scrollable', overflow);
      tabs.classList.toggle(
        'is-at-end',
        overflow && tabs.scrollLeft + tabs.clientWidth >= tabs.scrollWidth - 1
      );
    }

    grid.parentNode.insertBefore(tabs, grid);
    grid.classList.add('is-tabbed');
    tabs.addEventListener('scroll', syncScrollHint, { passive: true });
    window.addEventListener('resize', syncScrollHint);

    // 預設顯示自己的職業，未登入或還沒選職業就顯示第一個
    var mine = -1;
    cards.forEach(function (c, i) {
      if (mine === -1 && c.classList.contains('is-mine')) mine = i;
    });
    activate(mine > -1 ? mine : 0);
    syncScrollHint();
  }

  /* ---------- 錨點落點修正 ---------- */
  /*
   * 帶 #section 進站時落點會差很多——實測 #achievements 差 3,193px，
   * 整個停在上一節。原因是瀏覽器在頁面還沒載完時就跳了，之後上方
   * 延遲載入的圖片與廣告把版面撐開，目標早就被推到別的位置。
   *
   * 這是既有問題（修改前後誤差分別是 3,193px 與 3,120px，幾乎一樣），
   * 不是分頁或吸附導覽列造成的，但既然會讓人以為新功能壞掉，一併修掉。
   *
   * 載入完成後重對一次，400ms 後再對一次（廣告通常會再撐一輪）。
   * 使用者只要自己動過捲軸就不再插手，免得跟他搶。
   */
  function initHashLanding() {
    var id = (location.hash || '').replace('#', '');
    if (!id) return;

    var target = document.getElementById(decodeURIComponent(id));
    if (!target) return;

    var userMoved = false;
    var expired   = false;

    function markMoved() { userMoved = true; }
    ['wheel', 'touchmove', 'keydown', 'mousedown'].forEach(function (ev) {
      window.addEventListener(ev, markMoved, { passive: true, once: true });
    });

    function settle() {
      if (userMoved || expired) return;
      /*
       * behavior 必須指定 instant。glass.css 對 html 下了 scroll-behavior:smooth，
       * 預設的 auto 會沿用它變成平滑捲動，而下面每次版面變動都會再呼叫一次，
       * 動畫還沒跑到就被重啟——實測落點永遠差兩千多 px，就是卡在這裡。
       *
       * scrollIntoView 會套用 CSS 的 scroll-margin-top，標題才不會被導覽列蓋住。
       */
      target.scrollIntoView({ behavior: 'instant', block: 'start' });
    }

    /*
     * 只在 load 校正一次不夠：實測廣告與延遲圖片在 load 之後還會再撐開版面，
     * 校正完目標又被推走（第一版只補 load + 400ms，落點仍差 3,169px）。
     * 改成盯著版面高度變化跟著校正，3 秒後收手，使用者一動就不再插手。
     */
    window.addEventListener('load', settle);
    setTimeout(function () { expired = true; }, 3000);

    if (window.ResizeObserver) {
      var ro = new ResizeObserver(settle);
      ro.observe(document.body);
      setTimeout(function () { ro.disconnect(); }, 3000);
    } else {
      [200, 600, 1200, 2400].forEach(function (ms) { setTimeout(settle, ms); });
    }
  }

  /* ---------- 賽季倒數 ---------- */
  function initSeasonCountdown() {
    var holders = document.querySelectorAll('.guide-season-countdown[data-end]');
    if (!holders.length) return;

    var items = [];
    holders.forEach(function (holder) {
      var output = holder.querySelector('.js-countdown');
      var endTs = parseInt(holder.getAttribute('data-end'), 10) * 1000;
      if (output && endTs && !isNaN(endTs)) {
        items.push({ output: output, end: endTs });
      }
    });
    if (!items.length) return;

    function tick() {
      var alive = 0;
      items.forEach(function (it) {
        var diff = it.end - Date.now();
        if (diff <= 0) {
          it.output.textContent = '賽季已結束';
          return;
        }
        alive++;
        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff % 86400000) / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);
        it.output.textContent = d + ' 天 ' +
          String(h).padStart(2, '0') + ':' +
          String(m).padStart(2, '0') + ':' +
          String(s).padStart(2, '0');
      });
      if (!alive) clearInterval(timer);
    }

    tick();
    var timer = setInterval(tick, 1000);
  }
})();
