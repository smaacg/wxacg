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

    function bindToggle(config) {
        var buttons = document.querySelectorAll(config.buttonSelector);
        if (!buttons.length) return;

        buttons.forEach(function (btn) {
            var section = btn.closest('section');
            if (!section) return;

            var items = Array.prototype.slice.call(section.querySelectorAll(config.itemSelector));
            if (!items.length) return;

            if (items.length <= config.visibleCount) {
                btn.style.display = 'none';
                return;
            }

            btn.textContent = '顯示全部 ' + items.length + ' ' + config.unit + ' ▼';

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
