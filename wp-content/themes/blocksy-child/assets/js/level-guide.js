/**
 * Level Guide Page interactions
 * - TOC active section highlight (IntersectionObserver)
 * - Level table expand / collapse
 * - Season countdown timer
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initTocHighlight();
    initLevelTableToggle();
    initSeasonCountdown();
  });

  /* ---------- TOC 章節高亮 ---------- */
  function initTocHighlight() {
    var sections = document.querySelectorAll('.lg-section[id]');
    var tocLinks = document.querySelectorAll('.lg-toc__item');
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
          if (link) link.classList.add('is-active');
        }
      });
    }, { rootMargin: '-30% 0px -60% 0px' });

    sections.forEach(function (s) { observer.observe(s); });
  }

  /* ---------- 等級表展開 / 收合 ---------- */
  function initLevelTableToggle() {
    var btn   = document.getElementById('lg-level-toggle');
    var table = document.getElementById('lg-level-table');
    if (!btn || !table) return;

    btn.addEventListener('click', function () {
      var expanded = table.classList.toggle('is-expanded');
      var icon  = btn.querySelector('i');
      var label = btn.querySelector('span');
      if (expanded) {
        icon.className = 'fa-solid fa-chevron-up';
        label.textContent = '收合等級表';
      } else {
        icon.className = 'fa-solid fa-chevron-down';
        label.textContent = '展開全部 200 級';
        // 滾回章節頂部
        document.getElementById('level').scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  }

  /* ---------- 賽季倒數 ---------- */
  function initSeasonCountdown() {
    var holder = document.querySelector('.lg-season-now');
    var output = document.getElementById('lg-countdown');
    if (!holder || !output) return;

    var endTs = parseInt(holder.getAttribute('data-end'), 10) * 1000;
    if (!endTs || isNaN(endTs)) return;

    function tick() {
      var diff = endTs - Date.now();
      if (diff <= 0) {
        output.textContent = '賽季已結束';
        clearInterval(timer);
        return;
      }
      var d = Math.floor(diff / 86400000);
      var h = Math.floor((diff % 86400000) / 3600000);
      var m = Math.floor((diff % 3600000) / 60000);
      var s = Math.floor((diff % 60000) / 1000);
      output.textContent = d + ' 天 ' +
        String(h).padStart(2, '0') + ':' +
        String(m).padStart(2, '0') + ':' +
        String(s).padStart(2, '0');
    }
    tick();
    var timer = setInterval(tick, 1000);
  }
})();
