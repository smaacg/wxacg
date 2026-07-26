/**
 * Year Review Page JS
 * @version 1.0.0
 * @since   2026-05-13
 *
 * 功能:
 * - 區塊進場動畫 (IntersectionObserver)
 * - 數字 CountUp 動畫
 * - 月份柱狀圖動畫觸發
 * - 分享連結複製
 */
(function () {
    'use strict';

    var wrap = document.querySelector('.yr-wrap');
    if (!wrap) {
        return;
    }

    var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ────────────── 1. 區塊進場 + 觸發數字/長條動畫 ────────────── */
    var sections = document.querySelectorAll('.yr-section');

    if (prefersReduced) {
        sections.forEach(function (s) { s.classList.add('is-visible'); });
        document.querySelectorAll('.yr-stat-num[data-count]').forEach(function (el) {
            el.textContent = formatNumber(parseInt(el.getAttribute('data-count'), 10) || 0);
        });
        return;
    }

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');

                // 觸發數字動畫
                var nums = entry.target.querySelectorAll('.yr-stat-num[data-count]');
                nums.forEach(function (el) {
                    if (!el.dataset.animated) {
                        animateCount(el);
                        el.dataset.animated = '1';
                    }
                });

                // 月份長條圖不需 JS（CSS .is-visible 觸發）
                io.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.15,
        rootMargin: '0px 0px -50px 0px'
    });

    sections.forEach(function (s) { io.observe(s); });

    /* ────────────── 2. 數字 CountUp ────────────── */
    function animateCount(el) {
        var target = parseInt(el.getAttribute('data-count'), 10) || 0;
        var duration = 1200; // ms
        var start = performance.now();
        var startVal = 0;

        function tick(now) {
            var elapsed = now - start;
            var progress = Math.min(elapsed / duration, 1);
            // easeOutCubic
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.round(startVal + (target - startVal) * eased);
            el.textContent = formatNumber(current);
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = formatNumber(target);
            }
        }
        requestAnimationFrame(tick);
    }

    function formatNumber(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /* ────────────── 3. 分享按鈕 ────────────── */
    var shareBtn = document.querySelector('.yr-btn-share');
    var shareMsg = document.querySelector('.yr-share-msg');

    if (shareBtn) {
        shareBtn.addEventListener('click', function () {
            var url = window.location.href;

            if (navigator.share) {
                navigator.share({
                    title: document.title,
                    url: url
                }).catch(function () { copyToClipboard(url); });
            } else {
                copyToClipboard(url);
            }
        });
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showShareMsg('✅ 連結已複製!');
            }).catch(function () {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showShareMsg('✅ 連結已複製!');
        } catch (e) {
            showShareMsg('❌ 複製失敗,請手動複製網址');
        }
        document.body.removeChild(ta);
    }

    function showShareMsg(msg) {
        if (!shareMsg) return;
        shareMsg.textContent = msg;
        clearTimeout(showShareMsg._t);
        showShareMsg._t = setTimeout(function () {
            shareMsg.textContent = '';
        }, 3000);
    }

    /* ────────────── 4. 平滑捲動「往下捲動」提示 ────────────── */
    var scrollHint = document.querySelector('.yr-hero-scroll');
    if (scrollHint) {
        scrollHint.style.cursor = 'pointer';
        scrollHint.addEventListener('click', function () {
            var nextSection = document.querySelector('.yr-section:not(.yr-hero)');
            if (nextSection) {
                nextSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

})();
