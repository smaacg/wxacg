/**
 * Frontend JavaScript
 * Anime Sync Pro — frontend.js
 * 純原生 JS，不依賴 jQuery
 */

'use strict';

function safeInit(name, fn) {
    try {
        fn();
    } catch (err) {
        console.error('[Anime Sync Pro] init failed:', name, err);
    }
}

function asdInit() {
    if (window.__asdFrontendInited) return;
    if (!document.body) return;

    safeInit('lazy-load', initLazyLoad);
    safeInit('tabs', initTabs);
    safeInit('toggle-expand', initToggleExpand);
    safeInit('music-player', initMusicPlayer);
    safeInit('countdown', initCountdown);
    safeInit('pv-tabs', initPvTabs);
    safeInit('ow-tabs', initOwTabs);
    safeInit('music-swap', initMusicSwap);
    safeInit('toc', initToc);
    safeInit('album-modal', initAlbumModal);

    
    window.__asdFrontendInited = true;
    window.__asdFrontendBootedAt = Date.now();

    if (window.animeSyncData && window.animeSyncData.debug) {
        console.info('[Anime Sync Pro] frontend booted');
    }
}

window.asdInit = asdInit;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', asdInit, { once: true });
} else {
    asdInit();
}

window.addEventListener('load', asdInit, { once: true });
window.addEventListener('pageshow', function () {
    if (!window.__asdFrontendInited) {
        asdInit();
    }
});

// ========================================
// 圖片 Lazy Load
// ========================================
function initLazyLoad() {
    if (!('IntersectionObserver' in window)) return;

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;

            var img = entry.target;
            if (img.dataset.src) {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
            }
            observer.unobserve(img);
        });
    }, { rootMargin: '100px' });

    document.querySelectorAll('img[data-src]').forEach(function (img) {
        observer.observe(img);
    });
}

// ========================================
// Tabs：高亮 + smooth scroll
// ========================================
function initTabs() {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.asd-tab'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('.asd-section[id]'));
    if (!tabs.length || !sections.length) return;

    function setActiveTabById(id) {
        tabs.forEach(function (tab) {
            var href = tab.getAttribute('href');
            tab.classList.toggle('is-active', href === '#' + id);
        });
    }

    function getScrollOffset() {
        var nav = document.querySelector('.asd-tabs');
        var navHeight = nav ? nav.offsetHeight : 0;
        return navHeight + 16;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            var href = tab.getAttribute('href');
            if (!href || href.charAt(0) !== '#') return;

            var target = document.querySelector(href);
            if (!target) return;

            e.preventDefault();

            var offset = getScrollOffset();
            var top = target.getBoundingClientRect().top + window.pageYOffset - offset;

            window.scrollTo({
                top: top,
                behavior: 'smooth'
            });

            setActiveTabById(target.id);
        });
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            var visibleEntries = entries
                .filter(function (entry) { return entry.isIntersecting; })
                .sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });

            if (visibleEntries.length) {
                setActiveTabById(visibleEntries[0].target.id);
            }
        }, {
            rootMargin: '-25% 0px -55% 0px',
            threshold: 0
        });

        sections.forEach(function (section) {
            observer.observe(section);
        });
    } else {
        function onScroll() {
            var currentId = '';
            var trigger = getScrollOffset() + 20;

            sections.forEach(function (section) {
                var rect = section.getBoundingClientRect();
                if (rect.top <= trigger) {
                    currentId = section.id;
                }
            });

            if (currentId) setActiveTabById(currentId);
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        onScroll();
    }
}

// ========================================
// 集數 / Staff / Cast 展開收合
// ========================================
function initToggleExpand() {
    bindToggle({
        buttonSelector: '.asd-ep-toggle',
        itemSelector: '.asd-ep-row',
        hiddenClass: 'asd-ep-hidden',
        visibleCount: 3,
        unit: '集'
    });

    bindToggle({
        buttonSelector: '.asd-staff-toggle',
        itemSelector: '.asd-staff-card-v2, .asd-staff-card',
        hiddenClass: 'asd-staff-hidden',
        visibleCount: 6,
        unit: '人'
    });

    bindToggle({
        buttonSelector: '.asd-cast-toggle',
        itemSelector: '.asd-cast-card, .asd-cast-card-v2',
        hiddenClass: 'asd-cast-hidden',
        visibleCount: 6,
        unit: '人'
    });

    // 相關專輯：項目分在好幾個組裡（片頭曲／原聲集／角色歌…），
    // 收合時整組被藏光的話標題也要跟著藏，否則會留下空標題。
    bindToggle({
        buttonSelector: '.asd-album-toggle',
        itemSelector: '.asd-album-item',
        hiddenClass: 'asd-album-hidden',
        groupSelector: '.asd-album-group',
        groupHiddenClass: 'asd-album-group-hidden',
        visibleCount: 6,
        unit: '張'
    });

    function bindToggle(config) {
        var buttons = document.querySelectorAll(config.buttonSelector);
        if (!buttons.length) return;

        buttons.forEach(function (btn) {
            // 內容被抽換後（音樂頁不跳頁切換）會再跑一次 initToggleExpand，
            // 舊按鈕若沒標記就會被綁第二次，一次點擊觸發兩回。
            if (btn.dataset.asdToggleBound === '1') return;
            btn.dataset.asdToggleBound = '1';

            var section = btn.closest('section');
            if (!section) return;

            var items = Array.prototype.slice.call(section.querySelectorAll(config.itemSelector));
            if (!items.length) return;

            if (items.length <= config.visibleCount) {
                btn.style.display = 'none';
                return;
            }

            btn.textContent = '顯示全部 ' + items.length + ' ' + config.unit + ' ▼';

            // 分組容器（選用）。只有 config 有給 groupSelector 才處理，
            // 既有的集數／Staff／Cast 不傳，行為完全不變。
            var groups = config.groupSelector
                ? Array.prototype.slice.call(section.querySelectorAll(config.groupSelector))
                : [];

            // 某組底下還有沒有看得見的項目
            function syncGroups() {
                if (!groups.length) return;

                groups.forEach(function (group) {
                    var groupItems = Array.prototype.slice.call(
                        group.querySelectorAll(config.itemSelector)
                    );

                    var anyVisible = groupItems.some(function (item) {
                        return !item.classList.contains(config.hiddenClass);
                    });

                    group.classList.toggle(config.groupHiddenClass, !anyVisible);
                });
            }

            btn.addEventListener('click', function () {
                var expanded = btn.classList.contains('is-expanded');

                if (expanded) {
                    items.forEach(function (item, index) {
                        if (index >= config.visibleCount) {
                            item.classList.add(config.hiddenClass);
                        } else {
                            item.classList.remove(config.hiddenClass);
                        }
                    });

                    syncGroups();

                    btn.classList.remove('is-expanded');
                    btn.textContent = '顯示全部 ' + items.length + ' ' + config.unit + ' ▼';

                    var top = section.getBoundingClientRect().top + window.pageYOffset - getStickyOffset();
                    window.scrollTo({
                        top: top,
                        behavior: 'smooth'
                    });
                } else {
                    items.forEach(function (item) {
                        item.classList.remove(config.hiddenClass);
                    });

                    syncGroups();

                    btn.classList.add('is-expanded');
                    btn.textContent = '收起 ▲';
                }
            });
        });
    }

    function getStickyOffset() {
        var nav = document.querySelector('.asd-tabs');
        return (nav ? nav.offsetHeight : 0) + 16;
    }
}

// ========================================
// 音樂播放器
// ========================================
function initMusicPlayer() {
    if (document.body.dataset.asdMusicInited === '1') return;
    document.body.dataset.asdMusicInited = '1';

    var currentMedia = null;
    var currentBtn = null;
    var currentBar = null;
    var currentTime = null;
    var rafId = null;

    function cancelProgress() {
        if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
    }

    function resetUI(btn, bar, time) {
        if (btn) btn.classList.remove('is-playing');
        if (bar) bar.style.width = '0%';
        if (time) time.textContent = '0:00';
    }

    function formatTime(sec) {
        sec = isFinite(sec) ? sec : 0;
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function updateProgress(media, bar, time) {
        cancelProgress();

        function loop() {
            if (media && !media.paused && !media.ended) {
                if (media.duration) {
                    var pct = (media.currentTime / media.duration) * 100;
                    if (bar) bar.style.width = pct + '%';
                    if (time) time.textContent = formatTime(media.currentTime);
                }
                rafId = requestAnimationFrame(loop);
            }
        }

        rafId = requestAnimationFrame(loop);
    }

    function stopMedia(media) {
        if (!media) return;
        try {
            media.pause();
            media.currentTime = 0;
        } catch (e) {}
    }

    function playAudioFirst(audioEl, audioSrc, videoEl, videoSrc) {
        function tryAudio() {
            if (!audioEl || !audioSrc) {
                return Promise.reject(new Error('no audio src'));
            }
            audioEl.src = audioSrc;
            audioEl.load();
            return audioEl.play().then(function () {
                return audioEl;
            });
        }

        function tryVideoFallback() {
            if (!videoEl || !videoSrc) {
                return Promise.reject(new Error('no video fallback'));
            }
            videoEl.src = videoSrc;
            videoEl.muted = false;
            videoEl.volume = 1;
            videoEl.load();
            return videoEl.play().then(function () {
                return videoEl;
            });
        }

        return tryAudio().catch(function () {
            return tryVideoFallback();
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.asd-music-play-btn');
        if (!btn) return;

        var wrap = btn.closest('.asd-music-player-wrap');
        if (!wrap) return;

        var audio = wrap.querySelector('.asd-music-audio');
        var video = wrap.querySelector('.asd-music-video');
        var bar = wrap.querySelector('.asd-music-progress-bar');
        var time = wrap.querySelector('.asd-music-time');
        var openLink = wrap.querySelector('.asd-music-open-link');

        var audioSrc = (wrap.dataset.audioSrc || '').trim();
        var videoSrc = (wrap.dataset.videoSrc || '').trim();

        var sameWrapPlaying = currentMedia && wrap.contains(currentMedia) && !currentMedia.paused;

        if (sameWrapPlaying) {
            currentMedia.pause();
            resetUI(btn, bar, time);
            cancelProgress();
            return;
        }

        if (currentMedia) {
            stopMedia(currentMedia);
            resetUI(currentBtn, currentBar, currentTime);
            cancelProgress();
        }

        playAudioFirst(audio, audioSrc, video, videoSrc).then(function (media) {
            currentMedia = media;
            currentBtn = btn;
            currentBar = bar;
            currentTime = time;

            btn.classList.add('is-playing');
            updateProgress(media, bar, time);

            media.onended = function () {
                resetUI(btn, bar, time);
                if (currentMedia === media) {
                    currentMedia = null;
                    currentBtn = null;
                    currentBar = null;
                    currentTime = null;
                }
                cancelProgress();
            };
        }).catch(function () {
            resetUI(btn, bar, time);
            cancelProgress();

            if (openLink && openLink.href) {
                alert('此瀏覽器無法直接播放此主題曲，請改點「看片」。');
            } else {
                alert('目前無可播放來源。');
            }
        });
    });

    document.querySelectorAll('.asd-music-progress-wrap').forEach(function (progressWrap) {
        progressWrap.addEventListener('click', function (ev) {
            var wrap = progressWrap.closest('.asd-music-player-wrap');
            if (!wrap) return;

            var media = currentMedia && wrap.contains(currentMedia) ? currentMedia : null;
            if (!media || !media.duration) return;

            var rect = progressWrap.getBoundingClientRect();
            var ratio = (ev.clientX - rect.left) / rect.width;
            ratio = Math.max(0, Math.min(1, ratio));
            media.currentTime = ratio * media.duration;
        });
    });

    // 縮圖懶載入：捲到畫面上才真的去抓影片（data-src 換成 src），避免
    // 一進頁面就對 animethemes.moe 發一堆請求。載入後用 loadedmetadata
    // 強制 seek 到 0.1 秒觸發畫面渲染（preload="metadata" 不保證會自動
    // 畫出第一幀，不然縮圖會一直是黑框）。
    var thumbVideos = document.querySelectorAll('.asd-music-thumb-video');
    if (thumbVideos.length) {
        function loadThumb(video) {
            var src = video.dataset.src || '';
            if (!src || video.src) return;
            video.addEventListener('loadedmetadata', function () {
                try {
                    video.currentTime = Math.min(0.1, (video.duration || 1) / 2);
                } catch (e) {}
            });
            video.preload = 'metadata';
            video.src = src;
        }

        if ('IntersectionObserver' in window) {
            var thumbObserver = new IntersectionObserver(function (entries, observer) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    loadThumb(entry.target);
                    observer.unobserve(entry.target);
                });
            }, { rootMargin: '200px 0px' });

            thumbVideos.forEach(function (video) {
                thumbObserver.observe(video);
            });
        } else {
            // 沒有 IntersectionObserver 支援的舊瀏覽器，直接全部載入
            thumbVideos.forEach(loadThumb);
        }
    }

    // MV 縮圖：點下去用燈箱（同頁蓋一層），以原始尺寸播放，不開新分頁、不跳走
    var mvModal      = document.getElementById('asd-mv-modal');
    var mvModalVideo = document.getElementById('asd-mv-modal-video');
    var mvModalClose = document.getElementById('asd-mv-modal-close');
    var mvModalError = document.getElementById('asd-mv-modal-error');

    if (mvModalVideo && mvModalError) {
        // 影片真的載入失敗（404／格式不支援／CORS 等）時顯示訊息，
        // 不要讓使用者只看到一片空白的播放器猜不出發生什麼事。
        mvModalVideo.addEventListener('error', function () {
            var err = mvModalVideo.error;
            console.warn('[MV modal] 影片載入失敗：', err && err.code, err && err.message);
            mvModalError.hidden = false;
        });
    }

    function openMvModal(src) {
        if (!mvModal || !mvModalVideo || !src) return;
        if (mvModalError) mvModalError.hidden = true;
        mvModalVideo.src = src;
        mvModal.hidden = false;
        mvModalVideo.play().catch(function (err) {
            // 常見是瀏覽器自動播放政策擋下。controls 還在，使用者可以
            // 自己按播放；這裡把原因印出來，方便之後排查，不要靜默吞掉。
            console.warn('[MV modal] 自動播放失敗，需要手動按播放：', err && err.name, err && err.message);
        });
    }

    function closeMvModal() {
        if (!mvModal || !mvModalVideo) return;
        mvModalVideo.pause();
        mvModalVideo.removeAttribute('src');
        mvModalVideo.load();
        mvModal.hidden = true;
        if (mvModalError) mvModalError.hidden = true;
    }

    if (mvModal && mvModalVideo) {
        document.querySelectorAll('.asd-music-thumb-slot').forEach(function (slot) {
            function openFromSlot() {
                var video = slot.querySelector('.asd-music-thumb-video');
                var src = video ? (video.currentSrc || video.getAttribute('src') || video.dataset.src || '') : '';
                openMvModal(src);
            }

            slot.addEventListener('click', openFromSlot);
            slot.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    openFromSlot();
                }
            });
        });

        if (mvModalClose) mvModalClose.addEventListener('click', closeMvModal);
        mvModal.addEventListener('click', function (e) {
            if (e.target === mvModal) closeMvModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !mvModal.hidden) closeMvModal();
        });
    }
}

// ========================================
// 播出倒數計時
// 顯示風格：1天 3時 12分 5秒
// ========================================
function initCountdown() {
    var countdowns = document.querySelectorAll('.asd-countdown[data-ts]');
    if (!countdowns.length) return;

    function updateCountdowns() {
        var now = Math.floor(Date.now() / 1000);

        countdowns.forEach(function (el) {
            var ts = parseInt(el.getAttribute('data-ts'), 10);
            if (isNaN(ts)) return;

            var diff = ts - now;

            if (diff <= 0) {
                el.textContent = '已播出';
                return;
            }

            var d = Math.floor(diff / 86400);
            var h = Math.floor((diff % 86400) / 3600);
            var m = Math.floor((diff % 3600) / 60);
            var s = diff % 60;

            el.textContent =
                (d > 0 ? d + '天 ' : '') +
                h + '時 ' +
                m + '分 ' +
                s + '秒';
        });
    }

    updateCountdowns();
    setInterval(updateCountdowns, 1000);
}
/* ── Header scroll glass effect ── */
(function () {
  var header = document.querySelector('.site-header');
  if (!header) return;
  var cls = 'asd-header--scrolled';
  function onScroll() {
    if (window.scrollY > 40) {
      header.classList.add(cls);
    } else {
      header.classList.remove(cls);
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();

// ========================================
// PV Tabs（多支預告片切換 + lazy iframe）
// ========================================
function initPvTabs() {
    var boxes = document.querySelectorAll('.asd-pv-box');
    if (!boxes.length) return;

    boxes.forEach(function (box) {
        var tabs   = Array.prototype.slice.call(box.querySelectorAll('.asd-pv-tab'));
        var panels = Array.prototype.slice.call(box.querySelectorAll('.asd-pv-panel'));

        // 點擊縮圖播放（注入 iframe）
        box.addEventListener('click', function (e) {
            var playBtn = e.target.closest('.asd-pv-play');
            if (!playBtn) return;

            var holder = playBtn.closest('.asd-trailer-wrap');
            if (!holder) return;

            var vid   = playBtn.getAttribute('data-pv-id') || '';
            var title = playBtn.getAttribute('data-pv-title') || '';
            if (!vid) return;

            var iframe = document.createElement('iframe');
            iframe.src = 'https://www.youtube.com/embed/' + vid + '?autoplay=1';
            iframe.title = title;
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allow',
                'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            iframe.setAttribute('allowfullscreen', '');
            iframe.loading = 'lazy';

            holder.innerHTML = '';
            holder.appendChild(iframe);
        });

        // Tab 切換
        if (!tabs.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var idx = tab.getAttribute('data-pv-index');

                tabs.forEach(function (t) {
                    var active = t.getAttribute('data-pv-index') === idx;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                panels.forEach(function (p) {
                    p.classList.toggle('is-active',
                        p.getAttribute('data-pv-index') === idx);
                });

                // 切換到非當前播放的 tab，停掉其他 tab 的 iframe（避免背景繼續播）
                panels.forEach(function (p) {
                    if (p.getAttribute('data-pv-index') === idx) return;
                    var iframes = p.querySelectorAll('iframe');
                    iframes.forEach(function (f) {
                        try {
                            // 透過 postMessage 暫停（YouTube IFrame API 通用法）
                            f.contentWindow.postMessage(
                                '{"event":"command","func":"pauseVideo","args":""}',
                                '*'
                            );
                        } catch (_) {}
                    });
                });
            });
        });
    });
}

// ========================================
// 線上看 Tabs（多支 YouTube 切換，邏輯同 PV）
// ========================================
function initOwTabs() {
    var boxes = document.querySelectorAll('.asd-ow-box');
    if (!boxes.length) return;

    boxes.forEach(function (box) {
        var tabs   = Array.prototype.slice.call(box.querySelectorAll('.asd-ow-tab'));
        var panels = Array.prototype.slice.call(box.querySelectorAll('.asd-ow-panel'));
        if (!tabs.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var idx = tab.getAttribute('data-ow-index');

                tabs.forEach(function (t) {
                    var active = t.getAttribute('data-ow-index') === idx;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                panels.forEach(function (p) {
                    p.classList.toggle('is-active',
                        p.getAttribute('data-ow-index') === idx);
                });

                // 切走的面板：暫停其 iframe，避免背景繼續播
                panels.forEach(function (p) {
                    if (p.getAttribute('data-ow-index') === idx) return;
                    p.querySelectorAll('iframe').forEach(function (f) {
                        try {
                            f.contentWindow.postMessage(
                                '{"event":"command","func":"pauseVideo","args":""}',
                                '*'
                            );
                        } catch (_) {}
                    });
                });
            });
        });
    });
}

/* =========================================================================
   視覺圖切換器
   點縮圖換主視覺。模板只有在 2 張以上時才輸出 .asd-visual-switcher，
   因此這裡找不到節點就直接結束。
   ========================================================================= */
document.addEventListener('DOMContentLoaded', function () {
    var switcher = document.querySelector('.asd-visual-switcher');
    var poster   = document.querySelector('.asd-poster-img');

    if (!switcher || !poster) {
        return;
    }

    switcher.addEventListener('click', function (e) {
        var btn = e.target.closest('.asd-visual-thumb');

        if (!btn || !btn.dataset.full) {
            return;
        }

        poster.src = btn.dataset.full;
        poster.removeAttribute('srcset');

        switcher.querySelectorAll('.asd-visual-thumb').forEach(function (b) {
            b.classList.toggle('is-active', b === btn);
        });
    });
});

// ========================================
// 音樂頁不跳頁切換（/anime/{slug}/music/）
// ----------------------------------------
// 動畫頁 ⇄ 音樂頁互相切換時不重新載入整頁，做法接近 AniList 的
// /anime/849/xxx/staff：網址會變、上一頁能回、但畫面不閃。
//
// 漸進增強——這裡做的每件事失敗都會退回瀏覽器原本的換頁行為：
//   * 沒有 History API / fetch / DOMParser → 完全不攔截
//   * fetch 失敗、回應不是 200、抽不到 .asd-wrap → location.href 硬轉
// 伺服器端兩個網址本來就各自輸出完整 HTML，爬蟲與關掉 JS 的訪客
// 拿到的東西不變，SEO 不受影響。
// ========================================
// ========================================
// 作品頁分頁切換（/anime/{slug}/characters/ 等）
// ----------------------------------------
// 所有面板都已經在 HTML 裡（伺服器一次全部輸出），這裡只負責
// 顯示／隱藏 + 改網址。沒有 fetch，所以切換是 0 秒。
//
// 為什麼不用 fetch 抽換（前一版的做法）：
//   內容分散在 6 個網址會把 Google 權重切成 6 份互相稀釋，
//   AI 引擎抓一個網址也只拿得到 1/6。而且實測一次 fetch 要 2 秒，
//   得再補預抓與載入動畫去掩蓋——問題的根源是分散，不是速度。
//
// 漸進增強：沒有 History API 就完全不攔截，走瀏覽器原本的換頁，
// 伺服器端每個子檢視網址都會輸出完整頁面並自動啟用對應面板。
// ========================================
function initMusicSwap() {
    if (!window.history || !window.history.pushState) return;

    var main = document.getElementById('asd-main');
    if (!main) return;

    if (document.body.dataset.asdPanelsInited === '1') return;
    document.body.dataset.asdPanelsInited = '1';

    var panels = Array.prototype.slice.call(main.querySelectorAll('.asd-panel[data-asd-panel]'));
    if (!panels.length) return;

    var tabs = Array.prototype.slice.call(
        document.querySelectorAll('.asd-tabs--views a.asd-tab')
    );

    function stripHash(url) {
        var i = url.indexOf('#');
        return i === -1 ? url : url.slice(0, i);
    }

    /*
     * 從網址拆出「哪部作品、哪個面板」。
     *
     *   /anime/{slug}/          → { slug: slug, view: '' }（總覽）
     *   /anime/{slug}/music/    → { slug: slug, view: 'music' }
     *   其他                    → null
     *
     * slug 也要拿出來：內文入口攔截時得確認是同一部作品，否則點到
     * 別部作品的 /music/ 會變成切本頁的面板、內容卻是錯的。
     */
    function parseUrl(url) {
        var a = document.createElement('a');
        a.href = url;

        var m = a.pathname.match(/^\/anime\/([^\/]+)(?:\/([a-z]+))?\/?$/);

        return m ? { slug: m[1], view: m[2] || '' } : null;
    }

    // 從網址推出目前是哪個面板：/anime/{slug}/characters/ → 'characters'
    function viewFromUrl(url) {
        var p = parseUrl(url);

        return p ? p.view : '';
    }

    // 這個面板存不存在於本頁（不存在就別攔，交給瀏覽器正常導覽）
    function hasPanel(view) {
        return panels.some(function (p) {
            return p.dataset.asdPanel === view;
        });
    }

    /*
     * 把畫面帶回內容頂端（tab 列正下方）。
     *
     * 不能拿 nav 自己的位置來算：它是 position:sticky，捲到深處時
     * getBoundingClientRect().top 回的是「黏住的位置」而不是它在文件裡
     * 的位置，算出來會等於目前的捲動量，等於沒動。改用不會黏的 #asd-main。
     */
    function scrollToContentTop() {
        var nav   = document.querySelector('.asd-tabs');
        var stick = 0;
        var navH  = 0;

        if (nav) {
            stick = parseInt(window.getComputedStyle(nav).top, 10);

            if (isNaN(stick)) stick = 0;

            navH = nav.offsetHeight;
        }

        window.scrollTo({
            top: Math.max(0, main.getBoundingClientRect().top + window.pageYOffset - stick - navH - 8),
            behavior: 'auto'
        });
    }

    // 目前這一頁是哪部作品（pushState 只會換 view，slug 不變）
    var currentAnime = parseUrl(location.href);

    function activate(view, url, push) {
        panels.forEach(function (p) {
            p.hidden = (p.dataset.asdPanel !== view);
        });

        tabs.forEach(function (t) {
            var on = viewFromUrl(t.href) === view;

            t.classList.toggle('is-active', on);

            if (on) {
                t.setAttribute('aria-current', 'page');
            } else {
                t.removeAttribute('aria-current');
            }
        });

        if (push) {
            history.pushState({ asdPanel: view }, '', url);
        }

        // 換了面板才需要重跑的：bindToggle 直接綁按鈕（已用
        // data-asd-toggle-bound 防重複），lazy-load 要接手新露出的圖。
        safeInit('toggle-expand', initToggleExpand);
        safeInit('lazy-load', initLazyLoad);

        /* 面板換了，左側目錄要跟著重建 */
        if (typeof window.asdRebuildToc === 'function') {
            safeInit('toc-rebuild', window.asdRebuildToc);
        }

        // 捲動位置不動——tab 列是 sticky，跳回頂端很突兀。
        // 只在 tab 列被推出畫面時才拉回來。
        var nav = document.querySelector('.asd-tabs');

        if (!nav) return;

        var rect = nav.getBoundingClientRect();

        if (rect.bottom > 0 && rect.top < window.innerHeight) return;

        var stick = parseInt(window.getComputedStyle(nav).top, 10);

        if (isNaN(stick)) stick = 0;

        window.scrollTo({
            top: Math.max(0, rect.top + window.pageYOffset - stick),
            behavior: 'auto'
        });
    }

    // 進站時把 history 第一筆標記起來，回上一頁才認得出是自己的
    history.replaceState({ asdPanel: viewFromUrl(location.href) }, '', location.href);

    document.addEventListener('click', function (e) {
        // 保留 Ctrl/Cmd/中鍵開新分頁
        if (e.defaultPrevented) return;
        if (e.button !== 0) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        var link = e.target.closest('a');
        if (!link || !link.href) return;
        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download')) return;
        if (link.origin !== location.origin) return;

        var isTab = link.matches('.asd-tabs--views a.asd-tab');

        // Hero 按鈕列指向總覽某區塊（串流／線上看／預告／糾錯）：
        // 那些區塊在總覽面板裡，要先切回總覽才看得到。
        var isHeroAnchor = link.matches('.asd-hero-actions a[href*="#asd-sec-"]');

        /*
         * 內文裡指向子檢視的入口（「🎼 相關專輯 →」「查看全部角色 →」…）。
         *
         * 原本只認 tab 列和 Hero 按鈕列這兩種選擇器，這些入口不在名單裡，
         * 點下去是整頁重載。改成看網址本身：同一部作品、而且那個面板就在
         * 這一頁上，就當成換面板——日後再加新入口不必回來補白名單。
         */
        var isEntry = false;

        if (!isTab && !isHeroAnchor && currentAnime && main.contains(link)) {
            var dest = parseUrl(link.href);

            isEntry = !!dest &&
                dest.slug === currentAnime.slug &&
                hasPanel(dest.view);
        }

        if (!isTab && !isHeroAnchor && !isEntry) return;

        var view = viewFromUrl(link.href);

        if (isHeroAnchor) {
            if (view !== '') return; // 指向子檢視的錨點，交給瀏覽器

            e.preventDefault();
            activate('', stripHash(link.href), true);

            var target = document.getElementById(link.hash.slice(1));

            if (target) {
                var nav2 = document.querySelector('.asd-tabs');
                var off = (nav2 ? nav2.offsetHeight : 0) + 16;

                window.scrollTo({
                    top: target.getBoundingClientRect().top + window.pageYOffset - off,
                    behavior: 'smooth'
                });
            }

            return;
        }

        if (stripHash(link.href) === stripHash(location.href)) return;

        e.preventDefault();
        activate(view, link.href, true);

        /*
         * 從頁面深處的入口切過去時要把畫面帶回內容頂端。
         *
         * activate() 刻意保持捲動位置——從 tab 列點的話人本來就在上面，
         * 原地不動才自然。但入口在內文很下面，新面板通常短很多，留在
         * 原本的高度會落在空白處。
         */
        if (isEntry) scrollToContentTop();
    });

    window.addEventListener('popstate', function (e) {
        if (!e.state || typeof e.state.asdPanel === 'undefined') return;

        activate(e.state.asdPanel, location.href, false);
    });
}

// ========================================
// 左側快速導覽（目錄）
// ----------------------------------------
// 從「目前可見的面板」自動生成，切 tab 會跟著重建，
// 不必在模板裡各寫一份、也不會有漏掉的區塊。
//
// 只在夠寬的螢幕出現（容器 1200px，兩側各要留 ~200px），
// 窄螢幕會擋到內容，不如不要。
// ========================================
function initToc() {
    var MIN_WIDTH = 1400;

    var main = document.getElementById('asd-main');
    if (!main) return;

    if (document.getElementById('asd-toc')) return;

    var nav = document.createElement('nav');
    nav.className = 'asd-toc';
    nav.id = 'asd-toc';
    nav.setAttribute('aria-label', '頁面目錄');

    var btn = document.createElement('button');
    btn.className = 'asd-toc__btn';
    btn.type = 'button';
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="asd-toc__icon" aria-hidden="true">☰</span><span>目錄</span>';

    // 隱藏鈕。放在展開的清單裡，不佔收起狀態的空間。
    var close = document.createElement('button');
    close.className = 'asd-toc__close';
    close.type = 'button';
    close.setAttribute('aria-label', '隱藏目錄');
    close.textContent = '×';

    var head = document.createElement('div');
    head.className = 'asd-toc__head';
    head.innerHTML = '<span>目錄</span>';
    head.appendChild(close);

    var list = document.createElement('ul');
    list.className = 'asd-toc__list';

    var panel = document.createElement('div');
    panel.className = 'asd-toc__panel';
    panel.appendChild(head);
    panel.appendChild(list);

    nav.appendChild(btn);
    nav.appendChild(panel);
    document.body.appendChild(nav);

    /*
     * 隱藏後要有辦法叫回來，否則使用者關掉就再也找不到。
     * 左側邊緣留一個細長的把手，點了還原。
     */
    var restore = document.createElement('button');
    restore.className = 'asd-toc-restore';
    restore.type = 'button';
    restore.setAttribute('aria-label', '顯示目錄');
    restore.textContent = '›';
    restore.hidden = true;
    document.body.appendChild(restore);

    var STORE_KEY = 'asd_toc_hidden';

    /* localStorage 在無痕模式或封鎖 cookie 時會直接丟例外，一律包起來 */
    function readHidden() {
        try {
            return localStorage.getItem(STORE_KEY) === '1';
        } catch (err) {
            return false;
        }
    }

    function writeHidden(v) {
        try {
            if (v) {
                localStorage.setItem(STORE_KEY, '1');
            } else {
                localStorage.removeItem(STORE_KEY);
            }
        } catch (err) {
            /* 存不了就算了，這輪照樣有效，只是下次不記得 */
        }
    }

    var userHidden = readHidden();

    var links = [];

    // 依目前可見的面板重建清單
    /*
     * 目錄用固定短標籤，不從 <h2> 抓字。
     *
     * 區塊標題是給該區塊看的，格式各有需要：帶數量（「預告片（3）」）、
     * 帶符號（「▶ 官方線上看」）、甚至帶整個作品名（「《無職轉生 第三季
     * ～到了異世界就拿出真本事～》原作漫畫哪裡看?」）。那些放進目錄就是
     * 長短不一還會折行。目錄要的是一眼掃完，所以另外給短名。
     */
    var LABELS = {
        'asd-sec-synopsis':    '劇情簡介',
        'asd-sec-stream':      '串流平台',
        'asd-sec-trailer':     '預告片',
        'asd-sec-online':      '線上看',
        'asd-sec-music':       '主題曲',
        'asd-sec-events':      '最新動態',
        'asd-sec-editorial':   '編輯短評',
        'asd-sec-cast':        '角色',
        'asd-sec-staff':       '製作人員',
        'asd-sec-episodes':    '集數',
        'asd-sec-albums':      '相關專輯',
        'asd-sec-games':       '相關遊戲',
        'asd-sec-liveaction':  '真人版',
        'asd-sec-manga':       '原作漫畫',
        'asd-sec-faq':         '常見問題',
        'asd-sec-links':       '資料來源',
        'asd-sec-reviews':     '評論',
        'asd-sec-corrections': '糾錯回報'
    };

    // 對照表沒有的（之後新增區塊忘了加）才退回標題文字
    function fallbackLabel(sec) {
        var h = sec.querySelector('.asd-section-title');
        if (!h) return '';

        return h.textContent
            .replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{25A0}-\u{25FF}️]/gu, '')
            .replace(/[（(]\s*\d+\s*[）)]\s*$/, '')
            .trim();
    }

    function build() {
        list.innerHTML = '';
        links = [];

        var secs = Array.prototype.slice.call(
            main.querySelectorAll('.asd-panel:not([hidden]) > section.asd-section[id]')
        );

        secs.forEach(function (sec) {
            var text = LABELS[sec.id] || fallbackLabel(sec);
            if (!text) return;

            var li = document.createElement('li');
            var a = document.createElement('a');

            a.href = '#' + sec.id;
            a.textContent = text;
            a.dataset.target = sec.id;

            li.appendChild(a);
            list.appendChild(li);
            links.push(a);
        });

        sync();
    }

    /* 三個條件決定看不看得到：使用者是否關掉、螢幕寬度、有沒有東西可列 */
    function sync() {
        var tooNarrow = window.innerWidth < MIN_WIDTH;
        var nothing   = links.length < 2;

        nav.hidden     = ( userHidden || tooNarrow || nothing );
        restore.hidden = ! ( userHidden && ! tooNarrow && ! nothing );
    }

    function offset() {
        var tabs = document.querySelector('.asd-tabs');
        return (tabs ? tabs.offsetHeight : 0) + 24;
    }

    list.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (!a) return;

        var target = document.getElementById(a.dataset.target);
        if (!target) return;

        e.preventDefault();

        window.scrollTo({
            top: target.getBoundingClientRect().top + window.pageYOffset - offset(),
            behavior: 'smooth'
        });
    });

    btn.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    close.addEventListener('click', function (e) {
        e.stopPropagation();

        userHidden = true;
        writeHidden(true);
        nav.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        sync();
    });

    restore.addEventListener('click', function () {
        userHidden = false;
        writeHidden(false);
        sync();
    });

    // 捲到哪一段就highlight哪一段
    function spy() {
        if (nav.hidden || !links.length) return;

        var line = offset() + 40;
        var current = links[0];

        links.forEach(function (a) {
            var el = document.getElementById(a.dataset.target);
            if (!el) return;

            if (el.getBoundingClientRect().top <= line) current = a;
        });

        links.forEach(function (a) {
            a.classList.toggle('is-current', a === current);
        });
    }

    build();
    spy();

    window.addEventListener('scroll', spy, { passive: true });
    window.addEventListener('resize', sync);

    // 切 tab 之後面板變了，重建
    window.asdRebuildToc = function () {
        build();
        spy();
    };
}

// ========================================
// 專輯詳情彈窗
// ----------------------------------------
// 點專輯名 → 在本頁展開封面／藝術家／發售日，不離開網站。
//
// 資料向 /wp-json/anime-sync/v1/bgm-subject/{id} 取（後端代理 Bangumi，
// 見 class-bgm-subject-proxy.php）。不直接 fetch api.bgm.tv 是因為
// 對方沒開 CORS，而且集中在後端才有快取與頻率控制。
//
// 只在點下去時才取一筆——全站 5,849 張專輯，預抓要 1.5~2 小時而且
// 大半不會被點開。
// ========================================
function initAlbumModal() {
    if (typeof window.fetch !== 'function') return;
    if (document.getElementById('asd-album-modal')) return;

    /*
     * 用 localize 的 restUrl（class-frontend.php 已提供 anime-sync/v1/），
     * 不要寫死 /wp-json/——WordPress 可能裝在子目錄，或 REST 走
     * ?rest_route= 的形式，寫死會抓不到。
     */
    var API = (
        (window.animeSyncData && window.animeSyncData.restUrl)
            ? window.animeSyncData.restUrl
            : '/wp-json/anime-sync/v1/'
    ) + 'bgm-subject/';

    var cache = {};
    var lastFocus = null;

    var modal = document.createElement('div');
    modal.className = 'asd-amodal';
    modal.id = 'asd-album-modal';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    /*
     * 中性講法：同一個彈窗現在也用在相關遊戲與真人版上
     *（見 anime-relation-groups.php），寫死「專輯資訊」會念錯。
     */
    modal.setAttribute('aria-label', '條目資訊');

    modal.innerHTML =
        '<div class="asd-amodal__backdrop" data-close></div>' +
        '<div class="asd-amodal__box">' +
            '<button type="button" class="asd-amodal__close" aria-label="關閉" data-close>×</button>' +
            '<div class="asd-amodal__body"></div>' +
        '</div>';

    document.body.appendChild(modal);

    var body = modal.querySelector('.asd-amodal__body');

    function open() {
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('asd-amodal-open');
        modal.querySelector('.asd-amodal__close').focus();
    }

    function close() {
        modal.hidden = true;
        document.body.classList.remove('asd-amodal-open');

        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderLoading(name) {
        body.innerHTML =
            '<div class="asd-amodal__head"><h3>' + esc(name) + '</h3></div>' +
            '<div class="asd-amodal__loading">' +
                '<div class="asd-amodal__dots"><span></span><span></span><span></span></div>' +
                '<p>讀取資料…</p>' +
            '</div>';
    }

    function renderError(name, msg) {
        body.innerHTML =
            '<div class="asd-amodal__head"><h3>' + esc(name) + '</h3></div>' +
            '<p class="asd-amodal__err">' + esc(msg) + '</p>';
    }

    function render(d) {
        var rows = (d.info || []).map(function (r) {
            return '<div class="asd-amodal__row">' +
                '<dt>' + esc(r.key) + '</dt><dd>' + esc(r.value) + '</dd></div>';
        }).join('');

        body.innerHTML =
            '<div class="asd-amodal__head">' +
                '<h3>' + esc(d.title) + '</h3>' +
                (d.sub ? '<p class="asd-amodal__sub">' + esc(d.sub) + '</p>' : '') +
            '</div>' +
            '<div class="asd-amodal__main">' +
                (d.cover
                    ? '<div class="asd-amodal__cover"><img src="' + esc(d.cover) +
                      '" alt="' + esc(d.title) + '" loading="lazy" decoding="async"></div>'
                    : '') +
                '<dl class="asd-amodal__info">' +
                    (d.date ? '<div class="asd-amodal__row"><dt>發售日</dt><dd>' + esc(d.date) + '</dd></div>' : '') +
                    rows +
                '</dl>' +
            '</div>' +
            /* 來源標註不顯示（使用者指定） */
            (d.summary ? '<p class="asd-amodal__summary">' + esc(d.summary) + '</p>' : '');
    }

    function load(id, name) {
        renderLoading(name);
        open();

        if (cache[id]) {
            render(cache[id]);
            return;
        }

        fetch(API + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (d) {
                cache[id] = d;
                render(d);
            })
            .catch(function (err) {
                // 不吞錯：主控台留紀錄，畫面也明確告訴使用者
                console.error('[Anime Sync Pro] album modal failed:', err);
                renderError(name, '目前取不到這個條目的資料，請稍後再試。');
            });
    }

    document.addEventListener('click', function (e) {
        /*
         * 名稱和封面都能開彈窗。
         *
         * 選擇器改成認 data-bgm-id 而不是認名稱那個 class——封面是這張卡
         * 最大的目標，使用者的直覺是點圖不是點字。模板在封面上也放了同一個
         * 屬性，這裡就不必為封面另綁一組事件。
         *
         * 限定在 .asd-album-item 之內：data-bgm-id 這種通用屬性日後可能被
         * 別的地方用到，不先框住範圍的話會誤攔。
         */
        var hit = e.target.closest('[data-bgm-id]');
        var card = hit && hit.closest('.asd-album-item');

        if (hit && card) {
            /*
             * 標題一律從卡片上的名稱取，不要用 hit.textContent——
             * 點封面時那是空字串（<img>）或一個音符（佔位符）。
             */
            var nameEl = card.querySelector('.asd-album-name');

            load(hit.dataset.bgmId, nameEl ? nameEl.textContent.trim() : '');

            return;
        }

        if (!modal.hidden && e.target.closest('[data-close]')) close();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) close();
    });
}
