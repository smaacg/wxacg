/* ============================================================
   微笑動漫 — 動漫評分滑桿 + 送出 + 已評分/刪除 + 引導留言
   路徑：/blocksy-child/assets/js/anime-rating.js
   版本：1.5 — 2026-07-28
   說明：評分走 REST：GET/POST/DELETE weixiaoacg/v1/ratings/{postId}
   Changelog:
     1.5 — [修復] 回訪頁面時滑桿永遠停在 5 的問題。
           原本回填只依賴 window.SmacgUserRating（模板寫死 5）與從未被派發的
           smacg:userRatingReady 事件（模板去打不存在/格式不符的舊 admin-ajax
           smacg_get_my_rating，故事件從未觸發）。
           改為：登入時本檔直接 GET weixiaoacg/v1/ratings/{postId}，讀取 my_score
           回填四條滑桿並標記「已評分」（按鈕轉「更新我的評分」+ 顯示刪除區）。
           不再依賴模板內嵌 JS；anime 與 manga 兩頁共用本檔，一併修復。
     1.4 — [改進] 引導留言、刪除確認、刪除成功提示全改為自訂置中彈窗
           （取代瀏覽器原生 confirm / alert）
     1.3 — [新增] 已評分狀態顯示（按鈕變「更新我的評分」+「✓ 你已評分」+ 刪除鈕）
           [新增] 刪除評分（DELETE ratings/{id}），成功後分數/滑桿重置
           [新增] 評分成功後引導使用者到底部 wpDiscuz 留言並預填
     1.2 — 漫畫頁一併載入；評分改由本檔單一處理（REST）
     1.1 — 監聽 smacg:userRatingReady；data-action 登入提示
     1.0 — 從 anime-status.js 拆出
   ============================================================ */
'use strict';

document.addEventListener('DOMContentLoaded', function () {

    /* ── 公用：把單一滑桿設成指定值，並同步右側數字 ── */
    function setSliderValue(sliderId, value) {
        const slider = document.getElementById(sliderId);
        if (!slider) return;
        const num = parseFloat(value);
        if (!isFinite(num)) return;
        const min = parseFloat(slider.min) || 1;
        const max = parseFloat(slider.max) || 10;
        const clamped = Math.min(max, Math.max(min, num));
        slider.value = clamped;
        const valEl = document.getElementById(sliderId + '-val');
        if (valEl) valEl.textContent = clamped.toFixed(1);
    }

    /* ── 公用：自訂彈窗 ──────────────────────────────
       smacgModal({emoji, title, html, okText, cancelText, onOk, onCancel})
       - 有 cancelText → 確認框（兩顆按鈕）
       - 無 cancelText → 提示框（單顆按鈕）
    ────────────────────────────────────────────── */
    function smacgModal(opts) {
        opts = opts || {};
        const old = document.getElementById('wacg-comment-modal');
        if (old) old.remove();

        const hasCancel = !!opts.cancelText;
        const overlay = document.createElement('div');
        overlay.id = 'wacg-comment-modal';
        overlay.className = 'wacg-modal-overlay';
        overlay.innerHTML =
            '<div class="wacg-modal-box" role="dialog" aria-modal="true">' +
            (opts.emoji ? '<div class="wacg-modal-emoji">' + opts.emoji + '</div>' : '') +
            (opts.title ? '<h3 class="wacg-modal-title">' + opts.title + '</h3>' : '') +
            '<p class="wacg-modal-text">' + (opts.html || '') + '</p>' +
            '<div class="wacg-modal-actions">' +
            (hasCancel
                ? '<button type="button" class="wacg-modal-btn wacg-modal-cancel">' + opts.cancelText + '</button>'
                : '') +
            '<button type="button" class="wacg-modal-btn wacg-modal-ok">' + (opts.okText || '確定') + '</button>' +
            '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function () { overlay.classList.add('is-open'); });

        function close() {
            overlay.classList.remove('is-open');
            setTimeout(function () { overlay.remove(); }, 250);
            document.removeEventListener('keydown', onEsc);
        }
        function onEsc(ev) {
            if (ev.key === 'Escape') { close(); if (typeof opts.onCancel === 'function') opts.onCancel(); }
        }

        overlay.querySelector('.wacg-modal-ok').addEventListener('click', function () {
            close();
            if (typeof opts.onOk === 'function') opts.onOk();
        });
        const cancelBtn = overlay.querySelector('.wacg-modal-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                close();
                if (typeof opts.onCancel === 'function') opts.onCancel();
            });
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { close(); if (typeof opts.onCancel === 'function') opts.onCancel(); }
        });
        document.addEventListener('keydown', onEsc);
    }

    /* ── Slider 即時顯示 ── */
    document.querySelectorAll('.wacg-slider').forEach(function (slider) {
        const valEl = document.getElementById(slider.id + '-val');
        if (valEl) valEl.textContent = parseFloat(slider.value).toFixed(1);
        slider.addEventListener('input', function () {
            if (valEl) valEl.textContent = parseFloat(this.value).toFixed(1);
        });
    });

    /* ── 初始套用 window.SmacgUserRating（模板預設值，通常為 5） ── */
    if (window.SmacgUserRating && typeof window.SmacgUserRating === 'object') {
        applyUserRating(window.SmacgUserRating);
    }

    /* ── [v1.5] 登入時主動向 REST 拉「我的評分」回填滑桿 + 標記已評分 ──
       取代原本失效的路徑（模板打不存在的 smacg_get_my_rating admin-ajax）。
       本檔直接 GET weixiaoacg/v1/ratings/{postId}，用與送出相同的
       SmacgConfig.apiUrl / SmacgConfig.nonce，必然通過認證。
    ── */
    (function () {
        const cfg = window.SmacgConfig || {};
        if (!cfg.loggedIn) return;
        const postId = parseInt(cfg.postId, 10) || 0;
        const apiUrl = cfg.apiUrl || '/wp-json/weixiaoacg/v1/';
        const nonce = cfg.nonce || '';
        if (!postId) return;

        fetch(apiUrl + 'ratings/' + postId, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) {
                if (!res || !res.my_score) return;   // 沒評過 → 維持預設 5
                const m = res.my_score;
                applyUserRating({
                    story: parseFloat(m.score_story),
                    music: parseFloat(m.score_music),
                    animation: parseFloat(m.score_animation),
                    voice: parseFloat(m.score_voice)
                });
                markAsRated();
            })
            .catch(function () { });
    })();

    /* ── 監聽模板 fetch 完成事件（保留相容；v1.5 起本檔已自行回填） ── */
    document.addEventListener('smacg:userRatingReady', function (e) {
        if (e && e.detail) {
            applyUserRating(e.detail);
            markAsRated();
        }
    });

    function applyUserRating(r) {
        if (!r) return;
        setSliderValue('slider-story', r.story);
        setSliderValue('slider-music', r.music);
        setSliderValue('slider-animation', r.animation);
        setSliderValue('slider-voice', r.voice);
    }

    /* ── 標記為「已評分」：按鈕文字 + 顯示已評分/刪除區 ── */
    function markAsRated() {
        const btn = document.getElementById('wacg-submit-btn');
        if (btn && btn.textContent.indexOf('更新') === -1) {
            btn.textContent = '更新我的評分';
        }
        const box = document.getElementById('wacg-rated-actions');
        if (box) box.style.display = 'flex';
    }

    /* ── 登入提示按鈕 ── */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-action="smacg-login-prompt"]');
        if (!btn) return;
        e.preventDefault();
        if (typeof window.smacgOpenLoginModal === 'function') {
            window.smacgOpenLoginModal();
            return;
        }
        const cfg = window.SmacgConfig || {};
        const loginUrl = cfg.loginUrl
            || ('/wp-login.php?redirect_to=' + encodeURIComponent(window.location.href));
        window.location.href = loginUrl;
    });

    /* ── 刪除評分 ── */
    document.addEventListener('click', function (e) {
        const delBtn = e.target.closest('#wacg-delete-btn');
        if (!delBtn) return;
        e.preventDefault();

        smacgModal({
            emoji: '🗑',
            title: '刪除評分',
            html: '確定要刪除你對這部作品的評分嗎？<br>此動作無法復原。',
            okText: '確定刪除',
            cancelText: '取消',
            onOk: function () { doDeleteRating(delBtn); }
        });
    });

    function doDeleteRating(delBtn) {
        const cfg = window.SmacgConfig || {};
        const postId = parseInt(cfg.postId, 10) || 0;
        const apiUrl = cfg.apiUrl || '/wp-json/weixiaoacg/v1/';
        const nonce = cfg.nonce || '';
        if (!postId) return;

        delBtn.disabled = true;
        delBtn.textContent = '刪除中…';

        fetch(apiUrl + 'ratings/' + postId, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    document.querySelectorAll('.wacg-score-main, .wacg-hero-score').forEach(function (el) {
                        el.textContent = '—';
                    });
                    ['story', 'music', 'animation', 'voice'].forEach(function (k) {
                        const el = document.querySelector('.wacg-cat-' + k);
                        if (el) el.textContent = '—';
                        setSliderValue('slider-' + k, 5);
                    });
                    const vc = document.querySelector('.wacg-vote-count');
                    if (vc) vc.textContent = '';

                    const subBtn = document.getElementById('wacg-submit-btn');
                    if (subBtn) {
                        subBtn.textContent = '送出評分';
                        subBtn.disabled = false;
                        subBtn.style.background = '';
                    }
                    const box = document.getElementById('wacg-rated-actions');
                    if (box) box.style.display = 'none';

                    smacgModal({
                        emoji: '✅',
                        title: '已刪除',
                        html: '已刪除你對這部作品的評分。',
                        okText: '好'
                    });
                } else {
                    smacgModal({
                        emoji: '⚠️',
                        title: '刪除失敗',
                        html: (data && data.message) || '刪除失敗，請稍後再試。',
                        okText: '好'
                    });
                    delBtn.disabled = false;
                    delBtn.textContent = '🗑 刪除評分';
                }
            })
            .catch(function () {
                smacgModal({
                    emoji: '⚠️',
                    title: '刪除失敗',
                    html: '刪除失敗，請稍後再試。',
                    okText: '好'
                });
                delBtn.disabled = false;
                delBtn.textContent = '🗑 刪除評分';
            });
    }

    /* ── 送出評分 ── */
    const ratingForm = document.getElementById('wacg-rating-form');
    const submitBtn = document.getElementById('wacg-submit-btn');
    if (!ratingForm) return;

    ratingForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const cfg = window.SmacgConfig || {};
        const postId = parseInt(cfg.postId, 10) || 0;
        const apiUrl = cfg.apiUrl || '/wp-json/weixiaoacg/v1/';
        const nonce = cfg.nonce || '';

        if (!postId) {
            smacgModal({ emoji: '⚠️', title: '無法評分', html: '找不到作品 ID（SmacgConfig 未注入）。', okText: '好' });
            console.error('SmacgConfig:', cfg);
            return;
        }
        if (!cfg.loggedIn) {
            smacgModal({ emoji: '🔒', title: '請先登入', html: '登入後才能評分喔！', okText: '好' });
            return;
        }

        const story = parseFloat(document.getElementById('slider-story')?.value || 5);
        const music = parseFloat(document.getElementById('slider-music')?.value || 5);
        const animation = parseFloat(document.getElementById('slider-animation')?.value || 5);
        const voice = parseFloat(document.getElementById('slider-voice')?.value || 5);

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = '送出中…';
        }

        fetch(apiUrl + 'ratings/' + postId, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: JSON.stringify({
                score_story: story,
                score_music: music,
                score_animation: animation,
                score_voice: voice,
            }),
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                console.log('評分回應:', data);
                if (data.success) {
                    if (submitBtn) {
                        submitBtn.textContent = '✅ ' + (data.message || '評分完成！');
                        submitBtn.style.background = 'var(--asd-score-al, #02a9ff)';
                        submitBtn.disabled = false;
                    }
                    const stats = data.stats || {};
                    if (stats.score) {
                        document.querySelectorAll('.wacg-score-main, .wacg-hero-score').forEach(function (el) {
                            el.textContent = parseFloat(stats.score).toFixed(1);
                        });
                    }
                    const map = {
                        avg_story: '.wacg-cat-story',
                        avg_music: '.wacg-cat-music',
                        avg_animation: '.wacg-cat-animation',
                        avg_voice: '.wacg-cat-voice',
                    };
                    Object.keys(map).forEach(function (k) {
                        if (stats[k] != null) {
                            const el = document.querySelector(map[k]);
                            if (el) el.textContent = parseFloat(stats[k]).toFixed(1);
                        }
                    });
                    if (stats.vote_count) {
                        const el = document.querySelector('.wacg-vote-count');
                        if (el) el.textContent = stats.vote_count + ' 人評分';
                    }

                    // 顯示「已評分/刪除」區
                    const box = document.getElementById('wacg-rated-actions');
                    if (box) box.style.display = 'flex';

                    // 引導留言
                    setTimeout(function () {
                        smacgPromptComment(story, music, animation, voice);
                    }, 700);

                } else {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = '送出評分';
                    }
                    smacgModal({
                        emoji: '⚠️',
                        title: '評分失敗',
                        html: (data.message || '評分失敗') + (data.code ? '<br><small>錯誤代碼：' + data.code + '</small>' : ''),
                        okText: '好'
                    });
                }
            })
            .catch(function (err) {
                console.error('評分送出失敗:', err);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '送出評分';
                }
                smacgModal({ emoji: '⚠️', title: '送出失敗', html: '評分送出失敗，請稍後再試。', okText: '好' });
            });
    });

    /* ── 評分成功後：引導留言（自訂彈窗版） ── */
    function smacgPromptComment(story, music, animation, voice) {
        const avg = ((story + music + animation + voice) / 4).toFixed(1);

        /*
         * 留言區 2026-08-18 由 wpDiscuz 改為自建評論系統（review.js），
         * 區塊 id 變成 asd-sec-reviews。原本只找 asd-sec-comments /
         * wpdcom / comments 三個 id，改版後三個都不存在，這裡直接 return，
         * 評分成功的引導彈窗就再也沒出現過。
         * 自建系統擺第一順位，wpDiscuz 的 id 保留當後備。
         */
        const section = document.getElementById('asd-sec-reviews')
            || document.getElementById('asd-sec-comments')
            || document.getElementById('wpdcom')
            || document.getElementById('comments');
        if (!section) return;

        smacgModal({
            emoji: '🎉',
            title: '感謝評分！',
            html: '你給了 <strong>' + avg + '</strong> 分<br>要不要順便留下你的心得？',
            okText: '好，去留言',
            cancelText: '下次再說',
            onOk: function () {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setTimeout(function () {
                    const editor =
                        document.querySelector('.asd-review-content-input') ||
                        document.querySelector('#wpdcom .wpd-form-foot textarea') ||
                        document.querySelector('#wpdcom textarea.wpd-field-textarea') ||
                        document.querySelector('#wpdiscuz-textarea-0') ||
                        document.querySelector('#wpdcom textarea') ||
                        document.querySelector('#respond textarea') ||
                        document.querySelector('#comment');
                    if (!editor) return;
                    if (!editor.value || editor.value.trim() === '') {
                        editor.value = '我給了 ' + avg + ' 分，因為';
                        /*
                         * 自建評論的字數計數器綁在 input 事件上（review.js），
                         * 直接指派 value 不會觸發，計數器會停在 0 與實際內容不符。
                         */
                        editor.dispatchEvent(new Event('input'));
                    }
                    editor.focus();
                    try {
                        const len = editor.value.length;
                        editor.setSelectionRange(len, len);
                    } catch (e) { }
                }, 700);
            }
        });
    }

});
