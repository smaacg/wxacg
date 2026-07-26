/*!
 * File: blocksy-child/assets/js/bangumi.js
 * Version: 1.4.0
 * Date: 2026-05-19
 *
 * Changelog
 *  v1.4.0 (2026-05-19) P0 + P1 完整升級
 *         - 新增多維篩選器（平台 / 格式 / 來源 / 類型 / 追番狀態）
 *         - 新增即時搜尋（debounce 180ms，含 title/jp/en/cast/staff/studio）
 *         - 新增視圖切換（grid / list / schedule），切換時保留所有篩選狀態
 *         - 新增 LocalStorage 記憶（視圖偏好）
 *         - 篩選/搜尋條件自動同步 URL query string，可分享連結
 *         - 重設按鈕一鍵清空所有篩選
 *         - schedule 視圖內點擊作品 → 切回 grid 並展開該卡
 *  v1.3.0 - Hybrid 展開（桌面手風琴 / 手機 modal）
 *  v1.2.0 - sorting 改用 .bgm-card 與 data-* 屬性
 *  v1.1.0 - 從 page-bangumi.php 抽離
 *  v1.0.0 - 初版
 */
(function () {
    'use strict';

    /* ====================================================
     * 共用：環境偵測 + 工具
     * ==================================================== */
    var mqMobile = window.matchMedia('(max-width: 768px)');
    function isMobile() { return mqMobile.matches; }

    var LS_VIEW_KEY = 'bgm_view_v1';

    function debounce(fn, wait) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* ====================================================
     * 1. Weekday 切換（保留 v1.1.0 邏輯，整合到 filter）
     * ==================================================== */
    var currentDay = 'all';
    function initWeekday() {
        var buttons = qsa('.bgm-day');
        if (!buttons.length) return;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                currentDay = btn.getAttribute('data-day');
                buttons.forEach(function (b) {
                    var on = (b === btn);
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                applyFilters();
                closeAllExpanded();
            });
        });
    }

    /* ====================================================
     * 2. Sort（保留 v1.2.0 邏輯）
     * ==================================================== */
    var originalOrder = new Map();

    function initSort() {
        var select = document.getElementById('bgm-sort');
        if (!select) return;

        qsa('.bgm-grid').forEach(function (grid) {
            originalOrder.set(grid, Array.prototype.slice.call(grid.querySelectorAll(':scope > .bgm-card')));
        });

        select.addEventListener('change', function () {
            applySort();
            closeAllExpanded();
        });
    }

    function applySort() {
        var select = document.getElementById('bgm-sort');
        if (!select) return;
        var mode = select.value;

        qsa('.bgm-grid').forEach(function (grid) {
            var cards;
            if (mode === 'default') {
                cards = (originalOrder.get(grid) || []).slice();
            } else {
                cards = Array.prototype.slice.call(grid.querySelectorAll(':scope > .bgm-card'));
                var key = mode === 'ep' ? 'ep' : (mode === 'popularity' ? 'pop' : 'score');
                cards.sort(function (a, b) {
                    var va = parseFloat(a.dataset[key]) || 0;
                    var vb = parseFloat(b.dataset[key]) || 0;
                    return vb - va;
                });
            }
            cards.forEach(function (c) { grid.appendChild(c); });
        });
    }

    /* ====================================================
     * 3. 篩選器 + 即時搜尋（v1.4.0 新）
     * ==================================================== */
    var filterState = {
        q:        '',
        platform: '',
        format:   '',
        source:   '',
        genre:    '',
        status:   ''
    };

    function readFiltersFromURL() {
        var p = new URLSearchParams(location.search);
        ['q', 'platform', 'format', 'source', 'genre', 'status'].forEach(function (k) {
            if (p.has(k)) filterState[k] = p.get(k) || '';
        });
        var day = p.get('day');
        if (day) currentDay = day;
        var view = p.get('view');
        if (view && ['grid', 'list', 'schedule'].indexOf(view) >= 0) {
            currentView = view;
        }
    }

    function writeFiltersToURL() {
        var p = new URLSearchParams(location.search);
        ['q', 'platform', 'format', 'source', 'genre', 'status'].forEach(function (k) {
            if (filterState[k]) p.set(k, filterState[k]);
            else p.delete(k);
        });
        if (currentDay && currentDay !== 'all') p.set('day', currentDay);
        else p.delete('day');
        if (currentView && currentView !== 'grid') p.set('view', currentView);
        else p.delete('view');

        var qstr = p.toString();
        var newUrl = location.pathname + (qstr ? '?' + qstr : '') + location.hash;
        if (newUrl !== location.pathname + location.search + location.hash) {
            history.replaceState(null, '', newUrl);
        }
    }

    function initFilters() {
        // 同步初始 select / search 值
        var search = qs('#bgm-search');
        if (search && filterState.q) search.value = filterState.q;

        qsa('.bgm-fil').forEach(function (sel) {
            var key = sel.getAttribute('data-filter');
            if (filterState[key]) sel.value = filterState[key];
            sel.addEventListener('change', function () {
                filterState[key] = sel.value;
                applyFilters();
                writeFiltersToURL();
            });
        });

        if (search) {
            var clearBtn = qs('#bgm-search-clear');
            var onInput = debounce(function () {
                filterState.q = search.value.trim();
                applyFilters();
                writeFiltersToURL();
                if (clearBtn) clearBtn.hidden = !search.value;
            }, 180);
            search.addEventListener('input', onInput);
            if (clearBtn) {
                clearBtn.hidden = !search.value;
                clearBtn.addEventListener('click', function () {
                    search.value = '';
                    filterState.q = '';
                    clearBtn.hidden = true;
                    applyFilters();
                    writeFiltersToURL();
                    search.focus();
                });
            }
        }

        var reset = qs('#bgm-fil-reset');
        if (reset) reset.addEventListener('click', resetAllFilters);

        var emptyReset = qs('#bgm-empty-reset');
        if (emptyReset) emptyReset.addEventListener('click', resetAllFilters);

        // 初始套用
        applyFilters();
    }

    function resetAllFilters() {
        filterState = { q: '', platform: '', format: '', source: '', genre: '', status: '' };
        currentDay = 'all';

        var search = qs('#bgm-search');
        if (search) search.value = '';
        var clearBtn = qs('#bgm-search-clear');
        if (clearBtn) clearBtn.hidden = true;

        qsa('.bgm-fil').forEach(function (sel) { sel.value = ''; });

        qsa('.bgm-day').forEach(function (b) {
            var on = b.getAttribute('data-day') === 'all';
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        applyFilters();
        writeFiltersToURL();
    }

    function cardMatches(card) {
        // weekday
        if (currentDay && currentDay !== 'all') {
            if (String(card.dataset.day || '0') !== String(currentDay)) return false;
        }
        // platform
        if (filterState.platform) {
            var plats = (card.dataset.platforms || '').split(',');
            if (plats.indexOf(filterState.platform) < 0) return false;
        }
        // format
        if (filterState.format) {
            if ((card.dataset.format || '') !== filterState.format) return false;
        }
        // source
        if (filterState.source) {
            if ((card.dataset.source || '') !== filterState.source) return false;
        }
        // genre
        if (filterState.genre) {
            var genres = (card.dataset.genres || '').split('|');
            if (genres.indexOf(filterState.genre) < 0) return false;
        }
        // status
        if (filterState.status) {
            var st = card.dataset.status || '__none__';
            if (st !== filterState.status) return false;
        }
        // search
        if (filterState.q) {
            var idx = card.dataset.search || '';
            var q = filterState.q.toLowerCase();
            if (idx.indexOf(q) < 0) return false;
        }
        return true;
    }

    function applyFilters() {
        var cards = qsa('.bgm-card');
        var visible = 0;
        var visibleIds = {};
        cards.forEach(function (c) {
            var ok = cardMatches(c);
            c.classList.toggle('is-hidden-by-filter', !ok);
            if (ok) {
                visible++;
                visibleIds[c.dataset.animeId] = true;
            }
        });

        // group 空白隱藏
        qsa('.bgm-group').forEach(function (g) {
            var has = g.querySelector('.bgm-card:not(.is-hidden-by-filter)');
            g.classList.toggle('is-empty-after-filter', !has);
        });

        // schedule 同步隱藏
        qsa('.bgm-sched-item').forEach(function (it) {
            var id = it.dataset.animeId;
            it.classList.toggle('is-hidden-by-filter', !visibleIds[id]);
        });

        // 計數
        var cnt = qs('#bgm-visible-count');
        if (cnt) cnt.textContent = visible;

        // 提示徽章
        var hint = qs('#bgm-fil-hint');
        if (hint) {
            var active = hasActiveFilter();
            hint.hidden = !active;
        }

        // 重設按鈕高亮
        var reset = qs('#bgm-fil-reset');
        if (reset) reset.classList.toggle('is-show', hasActiveFilter());

        // 各 select 高亮
        qsa('.bgm-fil').forEach(function (sel) {
            sel.classList.toggle('is-active', !!sel.value);
        });

        // 空狀態
        var empty = qs('#bgm-empty-filter');
        if (empty) empty.hidden = (visible > 0);
    }

    function hasActiveFilter() {
        return !!(filterState.q || filterState.platform || filterState.format ||
                  filterState.source || filterState.genre || filterState.status ||
                  (currentDay && currentDay !== 'all'));
    }

    /* ====================================================
     * 4. 視圖切換（grid / list / schedule）
     * ==================================================== */
    var currentView = 'grid';

    function initViewSwitch() {
        // 嘗試從 LocalStorage 讀回
        try {
            var saved = localStorage.getItem(LS_VIEW_KEY);
            if (saved && ['grid', 'list', 'schedule'].indexOf(saved) >= 0 && !currentView) {
                currentView = saved;
            }
        } catch (e) {}

        var buttons = qsa('.bgm-view-btn');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var v = btn.getAttribute('data-view');
                setView(v, true);
            });
        });

        // 套用初始視圖
        setView(currentView, false);
    }

    function setView(v, persist) {
        if (['grid', 'list', 'schedule'].indexOf(v) < 0) v = 'grid';
        currentView = v;

        // 按鈕狀態
        qsa('.bgm-view-btn').forEach(function (b) {
            var on = b.getAttribute('data-view') === v;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        // body class（控制顯示）
        document.body.classList.remove('bgm-view-grid-mode', 'bgm-view-list-mode', 'bgm-view-schedule-mode');
        document.body.classList.add('bgm-view-' + v + '-mode');

        // schedule 時關閉所有展開
        if (v === 'schedule') closeAllExpanded();

        if (persist) {
            try { localStorage.setItem(LS_VIEW_KEY, v); } catch (e) {}
            writeFiltersToURL();
            // 切換到 schedule 也要重套用篩選（讓 sched-item 同步）
            applyFilters();
        }
    }

    /* ====================================================
     * 5. Schedule 點擊：切回 grid 並展開
     * ==================================================== */
    function initScheduleClick() {
        qsa('.bgm-sched-item').forEach(function (item) {
            item.addEventListener('click', function (e) {
                e.preventDefault();
                var id = item.getAttribute('data-anime-id');
                if (!id) return;
                // 切回 grid
                setView('grid', true);
                // 等 DOM 切換後再展開
                setTimeout(function () {
                    var card = qs('.bgm-card[data-anime-id="' + id + '"]');
                    if (card) {
                        // 若被 weekday 篩掉，先切回全部
                        if (card.classList.contains('is-hidden-by-filter')) {
                            var allBtn = qs('.bgm-day[data-day="all"]');
                            if (allBtn) allBtn.click();
                        }
                        toggleCard(card);
                        var rect = card.getBoundingClientRect();
                        if (rect.top < 0 || rect.top > window.innerHeight) {
                            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                }, 80);
            });
        });
    }

    /* ====================================================
     * 6. Hybrid 展開：桌面手風琴 / 手機 modal
     * ==================================================== */
    var currentExpandedId = null;
    var sheetEl, sheetBody, lastFocus;

    function getSheet() {
        if (!sheetEl) {
            sheetEl   = document.getElementById('bgm-sheet');
            sheetBody = sheetEl ? sheetEl.querySelector('.bgm-sheet-body') : null;
        }
        return sheetEl;
    }

    function closeAllExpanded() {
        qsa('.bgm-card.is-expanded').forEach(function (card) {
            card.classList.remove('is-expanded');
            var detail = card.querySelector('.bgm-detail');
            if (detail) detail.setAttribute('hidden', '');
            var trigger = card.querySelector('.bgm-card-trigger');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
            stopPvInside(card);
        });
        currentExpandedId = null;
    }

    function openDesktopAccordion(card) {
        closeAllExpanded();
        card.classList.add('is-expanded');
        var detail = card.querySelector('.bgm-detail');
        if (detail) detail.removeAttribute('hidden');
        var trigger = card.querySelector('.bgm-card-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        currentExpandedId = card.getAttribute('data-anime-id');

        var newHash = '#anime-' + currentExpandedId;
        if (location.hash !== newHash) {
            history.replaceState(null, '', location.pathname + location.search + newHash);
        }

        setTimeout(function () {
            var rect = card.getBoundingClientRect();
            if (rect.top < 80 || rect.top > window.innerHeight - 200) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 80);

        bindPvLazyLoad(detail);
    }

    function openMobileSheet(card) {
        var sheet = getSheet();
        if (!sheet || !sheetBody) return;

        var detail = card.querySelector('.bgm-detail');
        if (!detail) return;

        sheetBody.innerHTML = detail.innerHTML;

        lastFocus = document.activeElement;
        sheet.classList.add('is-open');
        sheet.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        currentExpandedId = card.getAttribute('data-anime-id');
        var newHash = '#anime-' + currentExpandedId;
        if (location.hash !== newHash) {
            history.replaceState(null, '', location.pathname + location.search + newHash);
        }

        var closeBtn = sheet.querySelector('.bgm-sheet-close');
        if (closeBtn) closeBtn.focus();

        bindPvLazyLoad(sheetBody);
    }

    function closeMobileSheet() {
        var sheet = getSheet();
        if (!sheet) return;
        sheet.classList.remove('is-open');
        sheet.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (sheetBody) {
            stopPvInside(sheetBody);
            setTimeout(function () {
                if (!sheet.classList.contains('is-open')) sheetBody.innerHTML = '';
            }, 350);
        }
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); lastFocus = null; }
        if (location.hash && location.hash.indexOf('#anime-') === 0) {
            history.replaceState(null, '', location.pathname + location.search);
        }
        currentExpandedId = null;
    }

function toggleCard(card) {
    if (!card) return;
    // v1.5.0：直接跳轉作品頁
    var url = card.getAttribute('data-url');
    if (url) {
        window.location.href = url;
        return;
    }
    // fallback：原本手風琴
    var id = card.getAttribute('data-anime-id');
    if (currentExpandedId === id) {
        closeAllExpanded();
    } else {
        openDesktopAccordion(card);
    }
}

    function initCardToggle() {
        qsa('.bgm-card-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                var card = trigger.closest('.bgm-card');
                toggleCard(card);
            });
        });
    }

    /* ====================================================
     * 7. Sheet 控制
     * ==================================================== */
    function initSheet() {
        var sheet = getSheet();
        if (!sheet) return;

        sheet.addEventListener('click', function (e) {
            if (e.target.closest('[data-bgm-close]')) {
                closeMobileSheet();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sheet.classList.contains('is-open')) {
                closeMobileSheet();
            }
        });

        var startY = 0, currentY = 0, swiping = false;
        var panel = sheet.querySelector('.bgm-sheet-panel');
        if (!panel) return;

        panel.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) return;
            startY = e.touches[0].clientY;
            swiping = (panel.scrollTop === 0);
        }, { passive: true });

        panel.addEventListener('touchmove', function (e) {
            if (!swiping) return;
            currentY = e.touches[0].clientY;
            var diff = currentY - startY;
            if (diff > 0) {
                panel.style.transform = 'translateY(' + diff + 'px)';
            }
        }, { passive: true });

        panel.addEventListener('touchend', function () {
            if (!swiping) return;
            var diff = currentY - startY;
            panel.style.transform = '';
            if (diff > 100) {
                closeMobileSheet();
            }
            swiping = false;
            startY = currentY = 0;
        });
    }

    /* ====================================================
     * 8. YouTube PV lazy load
     * ==================================================== */
    function bindPvLazyLoad(scope) {
        if (!scope) return;
        qsa('.bgm-pv[data-vid]', scope).forEach(function (btn) {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var vid = btn.getAttribute('data-vid');
                if (!vid) return;
                var iframe = document.createElement('iframe');
                iframe.src = 'https://www.youtube-nocookie.com/embed/' + vid + '?autoplay=1&rel=0';
                iframe.title = btn.getAttribute('aria-label') || 'YouTube 預告片';
                iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
                iframe.allowFullscreen = true;
                iframe.loading = 'lazy';
                btn.innerHTML = '';
                btn.appendChild(iframe);
                btn.classList.add('is-playing');
            });
        });
    }

    function stopPvInside(scope) {
        if (!scope) return;
        qsa('.bgm-pv.is-playing', scope).forEach(function (btn) {
            var vid = btn.getAttribute('data-vid');
            if (!vid) return;
            btn.innerHTML =
                '<img src="https://i.ytimg.com/vi/' + vid + '/hqdefault.jpg" alt="" loading="lazy">' +
                '<span class="bgm-pv-play">▶</span>' +
                '<span class="bgm-pv-title">' + (btn.getAttribute('aria-label') || '') + '</span>';
            btn.classList.remove('is-playing');
            btn.dataset.bound = '';
            bindPvLazyLoad(btn.parentElement);
        });
    }

    /* ====================================================
     * 9. URL hash 進入：開啟對應卡片
     * ==================================================== */
    function openFromHash() {
        var hash = location.hash;
        if (!hash || hash.indexOf('#anime-') !== 0) return;
        var id = hash.slice(7);
        if (!/^\d+$/.test(id)) return;
        var card = qs('.bgm-card[data-anime-id="' + id + '"]');
        if (card) {
            // 若被篩選隱藏，先重設
            if (card.classList.contains('is-hidden-by-filter')) {
                resetAllFilters();
            }
            // 若隸屬被隱藏的 weekday group，切回「全部」
            var group = card.closest('.bgm-group');
            if (group && getComputedStyle(group).display === 'none') {
                var allBtn = qs('.bgm-day[data-day="all"]');
                if (allBtn) allBtn.click();
            }
            // 若目前在 schedule 視圖，切回 grid
            if (currentView === 'schedule') {
                setView('grid', true);
            }
            setTimeout(function () { toggleCard(card); }, 100);
        }
    }

    /* ====================================================
     * 10. matchMedia 切換：桌面 ↔ 手機
     * ==================================================== */
    function handleMqChange() {
        if (currentExpandedId) {
            var card = qs('.bgm-card[data-anime-id="' + currentExpandedId + '"]');
            if (!card) return;
            if (isMobile()) {
                closeAllExpanded();
                openMobileSheet(card);
            } else {
                closeMobileSheet();
                setTimeout(function () { openDesktopAccordion(card); }, 50);
            }
        }
    }

    /* ====================================================
     * 11. Archive 頁面（保留 v1.0.0 邏輯）
     * ==================================================== */
    function initArchive() {
        var years = qsa('.bgm-arc-year');
        if (!years.length) return;

        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });
            years.forEach(function (y) { io.observe(y); });
        } else {
            years.forEach(function (y) { y.classList.add('is-visible'); });
        }

        var seasons = qsa('.bgm-arc-season:not(.is-empty)');
        seasons.forEach(function (s, idx) {
            s.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowRight' && seasons[idx + 1]) {
                    e.preventDefault();
                    seasons[idx + 1].focus();
                } else if (e.key === 'ArrowLeft' && seasons[idx - 1]) {
                    e.preventDefault();
                    seasons[idx - 1].focus();
                }
            });
        });
    }

/* ====================================================
 * 11.5 OP/ED MV Lightbox（v1.4.1 新）
 * ==================================================== */
var mvBox, mvStage, mvTitleEl, mvVideoEl;

function ensureMvBox() {
    if (mvBox) return mvBox;
    mvBox = document.createElement('div');
    mvBox.className = 'bgm-mv-lightbox';
    mvBox.setAttribute('role', 'dialog');
    mvBox.setAttribute('aria-modal', 'true');
    mvBox.innerHTML =
        '<div class="bgm-mv-stage">' +
            '<div class="bgm-mv-title"></div>' +
            '<button type="button" class="bgm-mv-close" aria-label="關閉">×</button>' +
            '<video controls playsinline preload="metadata"></video>' +
        '</div>';
    document.body.appendChild(mvBox);
    mvStage   = mvBox.querySelector('.bgm-mv-stage');
    mvTitleEl = mvBox.querySelector('.bgm-mv-title');
    mvVideoEl = mvBox.querySelector('video');

    // 點關閉按鈕 或 黑色背景區
    mvBox.addEventListener('click', function (e) {
        if (e.target === mvBox || e.target.closest('.bgm-mv-close')) {
            closeMv();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && mvBox.classList.contains('is-open')) {
            closeMv();
        }
    });
    return mvBox;
}

function openMv(url, title) {
    ensureMvBox();
    mvTitleEl.textContent = title || 'MV';
    mvVideoEl.src = url;
    mvVideoEl.currentTime = 0;
    mvBox.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    // 等動畫進場再播
    setTimeout(function () {
        var p = mvVideoEl.play();
        if (p && typeof p.catch === 'function') p.catch(function () {});
    }, 150);
}

function closeMv() {
    if (!mvBox) return;
    mvVideoEl.pause();
    mvVideoEl.removeAttribute('src');
    mvVideoEl.load();
    mvBox.classList.remove('is-open');
    document.body.style.overflow = '';
}

function initMvLightbox() {
    // 事件委派：整個 document 一次綁定，sheet 內動態 clone 的也吃得到
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.bgm-theme-mv');
        if (!btn) return;
        e.preventDefault();
        var url = btn.getAttribute('data-mv');
        var title = btn.getAttribute('data-title') || '';
        if (url) openMv(url, title);
    });

    // 點同部作品的另一個音訊時，自動暫停其他正在播的（節流）
    document.addEventListener('play', function (e) {
        if (e.target.classList && e.target.classList.contains('bgm-theme-audio')) {
            document.querySelectorAll('audio.bgm-theme-audio').forEach(function (a) {
                if (a !== e.target && !a.paused) a.pause();
            });
        }
    }, true);
}

    /* ====================================================
     * 12. Init
     * ==================================================== */
    function init() {
        // 先讀 URL 還原狀態
        readFiltersFromURL();

        initWeekday();
        initSort();
        initFilters();
        initViewSwitch();
        initScheduleClick();
        initCardToggle();
        initSheet();
        initArchive();
        initMvLightbox();

        openFromHash();

        if (mqMobile.addEventListener) {
            mqMobile.addEventListener('change', handleMqChange);
        } else if (mqMobile.addListener) {
            mqMobile.addListener(handleMqChange);
        }

        window.addEventListener('hashchange', function () {
            if (!location.hash || location.hash.indexOf('#anime-') !== 0) {
                if (isMobile()) closeMobileSheet();
                else            closeAllExpanded();
            } else {
                openFromHash();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
