/* ============================================================
   微笑動漫 — 動漫追蹤列（Track Bar）腳本
   路徑：/blocksy-child/assets/js/anime-status.js
   版本：16.0 — 2026-07-19
        - [移除] 檔案末尾「二、評分滑桿 + 送出」IIFE。
                 評分統一由 anime-rating.js 處理（走 REST ratings/{id}），
                 避免同一 #wacg-rating-form 被綁兩次 → 一次送出兩個請求。
        - 追蹤列（想看 / 追番中 / 已看完 / 棄番 / 收藏 / 進度）維持不變。
        - 漫畫頁沿用同一份腳本：postId / totalEp 皆讀自 .smacg-track-bar
          的 data-*，與 post type 無關。
   版本：15.2 — 新增「想看 (want)」狀態，4+1 按鈕設計
                想看 / 追番中 / 已看完 / 棄番 為互斥狀態
                收藏 為獨立可疊加狀態
   ============================================================ */
'use strict';

/* ============================================================
   追蹤列（Track Bar）
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {

    const cfg      = window.SmacgConfig || {};
    const bar      = document.querySelector('.smacg-track-bar');
    if (!bar) return;

    const postId   = parseInt(bar.dataset.postId, 10);
    const totalEp  = parseInt(bar.dataset.episodes, 10) || 0;
    const loggedIn = cfg.loggedIn === true || cfg.loggedIn === '1' || cfg.loggedIn === 1;
    const apiBase  = cfg.apiUrl  || '/wp-json/weixiaoacg/v1/';
    const nonce    = cfg.nonce   || '';

    /* 允許的狀態值（與後端 Anime_Sync_User_Status_Manager 對應） */
    const VALID_STATUS = ['want', 'watching', 'completed', 'dropped', 'paused'];

    /* ── 未登入彈出 Modal ── */
    function requireLogin() {
        if (typeof window.smacgOpenLoginModal === 'function') {
            window.smacgOpenLoginModal();
            return;
        }
        const modal = document.getElementById('login-modal');
        if (modal) {
            const loginTab    = modal.querySelector('.lm-tab[data-tab="login"]');
            const registerTab = modal.querySelector('.lm-tab[data-tab="register"]');
            const loginPanel  = document.getElementById('lm-panel-login');
            const regPanel    = document.getElementById('lm-panel-register');
            if (loginTab)    loginTab.classList.add('active');
            if (registerTab) registerTab.classList.remove('active');
            if (loginPanel)  loginPanel.hidden = false;
            if (regPanel)    regPanel.hidden    = true;
            document.body.style.overflow = 'hidden';
            setTimeout(function () { modal.classList.add('lm-open'); }, 10);
        }
    }

    /* ── 本地狀態 ── */
    let state = {
        status:       VALID_STATUS.includes(bar.dataset.status) ? bar.dataset.status : null,
        progress:     parseInt(bar.dataset.progress, 10) || 0,
        favorited:    bar.dataset.favorited   === '1',
        fullcleared:  bar.dataset.fullcleared === '1',
        _prevCleared: bar.dataset.fullcleared === '1',
    };

    /* ── 元素參考 ── */
    const statusBtns  = bar.querySelectorAll('.smacg-status-btn');
    const progCurrent = bar.querySelector('.smacg-prog-current');
    const progBar     = bar.querySelector('.smacg-prog-bar');
    const progBtns    = bar.querySelectorAll('.smacg-prog-btn');
    const favBtn      = bar.querySelector('.smacg-fav-btn');
    const clearBtn    = bar.querySelector('.smacg-clear-btn');
    const shareBtn    = bar.querySelector('.smacg-share-btn');
    const pointToast  = bar.querySelector('.smacg-point-toast');
    const shareModal  = document.getElementById('smacg-share-modal');
    const shareClose  = document.getElementById('smacg-share-close');
    const copyBtn     = document.getElementById('smacg-copy-link');

    /* ── 工具：呼叫 API ── */
    function callApi(action, value) {
        if (!loggedIn) {
            requireLogin();
            return Promise.reject('not_logged_in');
        }
        return fetch(apiBase + 'user-status/' + postId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: JSON.stringify({
                action:  action,
                value:   value,
            }),
        }).then(function (res) {
            if (!res.ok) throw new Error('API error ' + res.status);
            return res.json();
        });
    }

    /* ── 統一 Toast（取代 alert）── */
    function showTip(msg) {
        if (typeof window.smacgShowToast === 'function') {
            window.smacgShowToast(msg);
            return;
        }
        if (pointToast) {
            // 一般訊息用追蹤列右上角的小標籤，不套積分卡片樣式
            pointToast.classList.remove('is-points');
            pointToast.textContent = msg;
            pointToast.classList.remove('is-show');
            void pointToast.offsetWidth;
            pointToast.classList.add('is-show');
            setTimeout(function () { pointToast.classList.remove('is-show'); }, 1900);
        } else {
            alert(msg);
        }
    }

    /* ── 積分 Toast ──
       置中大卡片（.is-points），份量比照評分成功的彈窗，但自動消失。 */
    function showPointToast(points) {
        if (!points || points <= 0 || !pointToast) return;
        pointToast.innerHTML =
            '<span class="smacg-point-emoji">✨</span>' +
            '<span class="smacg-point-num">+' + points + '</span>' +
            '<span class="smacg-point-label">經驗值</span>';
        pointToast.classList.add('is-points');
        pointToast.classList.remove('is-show');
        void pointToast.offsetWidth;
        pointToast.classList.add('is-show');
        setTimeout(function () { pointToast.classList.remove('is-show'); }, 1900);
    }

    /* ── 彩紙 ── */
    function launchConfetti() {
        const wrap   = document.createElement('div');
        wrap.className = 'smacg-confetti-wrap';
        document.body.appendChild(wrap);
        const colors = ['#63a8ff','#8f6bff','#ff6bae','#ffd60a','#34c759','#ff9500'];
        for (let i = 0; i < 60; i++) {
            const piece = document.createElement('div');
            piece.className = 'smacg-confetti-piece';
            piece.style.left              = Math.random() * 100 + 'vw';
            piece.style.background        = colors[Math.floor(Math.random() * colors.length)];
            piece.style.animationDuration = (1.2 + Math.random() * 1.4) + 's';
            piece.style.animationDelay    = (Math.random() * 0.6) + 's';
            piece.style.width             = (6 + Math.random() * 6) + 'px';
            piece.style.height            = (6 + Math.random() * 6) + 'px';
            wrap.appendChild(piece);
        }
        setTimeout(function () { wrap.remove(); }, 2800);
    }

    /* ── Render ── */
    function renderStatus(newStatus) {
        statusBtns.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.dataset.value === newStatus);
        });
    }

    /*
     * 外部（目前是 review.js）在後端自動建立片單紀錄之後，用這個事件通知
     * 追蹤列同步。不讓外部直接改 DOM 是因為本模組的 state.status 也要跟著更新
     * ——否則使用者接著點同一顆按鈕時，切換邏輯
     * （state.status === value ? 'none' : value）會判斷錯，變成重設一次而非取消。
     */
    document.addEventListener('wxacg:status-changed', function (e) {
        const s = e && e.detail ? e.detail.status : null;

        if (!s || !VALID_STATUS.includes(s)) {
            return;
        }

        state.status = s;
        bar.dataset.status = s;
        renderStatus(s);
    });

    function renderProgress(prog) {
        if (progCurrent) progCurrent.textContent = prog;
        if (progBar && totalEp > 0) {
            const pct   = Math.min(100, Math.round((prog / totalEp) * 100));
            progBar.style.width = pct + '%';
            const pctEl = bar.querySelector('.smacg-prog-pct');
            if (pctEl) pctEl.textContent = pct + '%';
        }
        const labelEl = bar.querySelector('.smacg-prog-label');
        if (labelEl) {
            /* 漫畫頁沿用同一支腳本但語彙不同（閱讀 vs 觀看），
               故容器可用 data-progress-label / data-done-label 覆寫，
               沒設定時維持動畫頁原本的文案。 */
            const doneLabel = bar.dataset.doneLabel     || '🎉 已全破！';
            const progLabel = bar.dataset.progressLabel || '📺 觀看中';

            if (state.fullcleared)  labelEl.textContent = doneLabel;
            else if (prog > 0)      labelEl.textContent = progLabel;
            else                    labelEl.innerHTML   = '&nbsp;';
        }
    }

    function renderFav(fav) {
        if (!favBtn) return;
        favBtn.classList.toggle('is-active', fav);
        const icon = favBtn.querySelector('i');
        if (icon) icon.className = fav ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark';
        favBtn.title = fav ? '取消收藏' : '收藏';
    }

    function renderClear(cleared) {
        if (!clearBtn) return;
        clearBtn.classList.toggle('is-active', cleared);
        clearBtn.title = cleared ? '已全破' : '標記全破';
    }

    /* ── 初始渲染 ── */
    renderStatus(state.status);
    renderProgress(state.progress);
    renderFav(state.favorited);
    renderClear(state.fullcleared);

    /* ── 狀態按鈕（互斥：want / watching / completed / paused / dropped）── */
    statusBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!loggedIn) { requireLogin(); return; }

            const value = btn.dataset.value;

            // 🚫 未播出時的擋鎖規則：
            //   - 允許「想看 (want)」、「棄番 (dropped)」
            //   - 擋掉「追番中 (watching)」、「已看完 (completed)」
            //   舊版以 btn.disabled 為唯一依據；保留相容，但若是 want/dropped 應放行
            if (btn.disabled && value !== 'want' && value !== 'dropped') {
                const tip = btn.getAttribute('title') || '尚未播出，無法操作';
                showTip(tip);
                return;
            }

            // 防呆：未在白名單的 value 直接忽略
            if (!VALID_STATUS.includes(value)) return;

            const newVal    = state.status === value ? 'none' : value;
            const oldStatus = state.status;
            state.status = newVal === 'none' ? null : newVal;
            renderStatus(state.status);
            btn.classList.add('is-loading');

            callApi('status', newVal)
                .then(function (res) {
                    if (res.success) {
                        state.status = res.entry.status;
                        renderStatus(state.status);
                        // 已看完 → 自動補滿進度 + confetti
                        if (res.entry.status === 'completed' && totalEp > 0) {
                            state.progress    = totalEp;
                            state.fullcleared = true;
                            renderProgress(totalEp);
                            renderClear(true);
                            if (!state._prevCleared) launchConfetti();
                            state._prevCleared = true;
                        } else if (typeof res.entry.progress !== 'undefined') {
                            state.progress = res.entry.progress;
                            renderProgress(state.progress);
                        }
                        showPointToast(res.points_earned);

                        /*
                         * 剛看完 → 廣播事件，由 anime-rating.js 接手引導寫心得。
                         *
                         * 站上 30 人看完過作品，只有 4 人留下評論；而引導彈窗
                         * 原本只在「評分」後觸發，看完的人裡僅 6 人評過分，
                         * 等於多數人從沒被引導過。
                         *
                         * 用事件而非直接呼叫：anime-rating.js 只在動畫頁載入，
                         * 漫畫頁沒有監聽者時事件自然無作用，不必在這裡檢查一個
                         * 可能不存在的全域函式。
                         *
                         * oldStatus 比對確保只在「真的從其他狀態變成已看完」時
                         * 觸發，重複點同一顆或取消再點不會一直跳。
                         */
                        if (res.entry.status === 'completed' && oldStatus !== 'completed') {
                            document.dispatchEvent(new CustomEvent('smacg:watchCompleted', {
                                detail: { postId: postId }
                            }));
                        }
                    }
                })
                .catch(function () { state.status = oldStatus; renderStatus(state.status); })
                .finally(function () { btn.classList.remove('is-loading'); });
        });
    });

    /* ── 進度按鈕 ── */
    progBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!loggedIn) { requireLogin(); return; }
            const delta   = parseInt(btn.dataset.value, 10);
            const oldProg = state.progress;
            const newProg = Math.max(0, Math.min(totalEp || Infinity, oldProg + delta));
            if (newProg === oldProg) return;
            const oldStatus = state.status;
            state.progress = newProg;
            renderProgress(newProg);
            btn.classList.add('is-loading');
            callApi('progress', delta)
                .then(function (res) {
                    if (res.success) {
                        state.progress    = res.entry.progress;
                        state.fullcleared = res.entry.fullcleared;
                        state.status      = res.entry.status;
                        renderProgress(state.progress);
                        renderStatus(state.status);
                        if (res.entry.fullcleared && !state._prevCleared) {
                            renderClear(true);
                            launchConfetti();
                        }
                        state._prevCleared = res.entry.fullcleared;
                        showPointToast(res.points_earned);

                        /*
                         * 進度推到最後一集時，後端會自動把狀態改為已看完
                         * （class-user-status-manager.php 的 adjust_progress），
                         * 這條路徑同樣要引導寫心得——一集一集推進度的人正是
                         * 最認真追完的那群，不該只有按「已看完」的人被引導。
                         */
                        if (res.entry.status === 'completed' && oldStatus !== 'completed') {
                            document.dispatchEvent(new CustomEvent('smacg:watchCompleted', {
                                detail: { postId: postId }
                            }));
                        }
                    }
                })
                .catch(function () { state.progress = oldProg; renderProgress(oldProg); })
                .finally(function () { btn.classList.remove('is-loading'); });
        });
    });

    /* ── 收藏按鈕（獨立疊加狀態）── */
    if (favBtn) {
        favBtn.addEventListener('click', function () {
            if (!loggedIn) { requireLogin(); return; }
            const oldFav    = state.favorited;
            state.favorited = !oldFav;
            renderFav(state.favorited);
            favBtn.classList.add('is-loading');
            callApi('favorite', null)
                .then(function (res) {
                    if (res.success) {
                        state.favorited = res.entry.favorited;
                        renderFav(state.favorited);
                        showPointToast(res.points_earned);
                    }
                })
                .catch(function () { state.favorited = oldFav; renderFav(oldFav); })
                .finally(function () { favBtn.classList.remove('is-loading'); });
        });
    }

    /* ── 全破按鈕 ── */
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!loggedIn) { requireLogin(); return; }
            if (state.fullcleared) return;
            clearBtn.classList.add('is-loading');
            callApi('fullclear', null)
                .then(function (res) {
                    if (res.success) {
                        state.fullcleared  = res.entry.fullcleared;
                        state.progress     = res.entry.progress ?? totalEp;
                        state.status       = res.entry.status;
                        renderClear(state.fullcleared);
                        renderProgress(state.progress);
                        renderStatus(state.status);
                        if (state.fullcleared && !state._prevCleared) launchConfetti();
                        state._prevCleared = state.fullcleared;
                        showPointToast(res.points_earned);
                    }
                })
                .finally(function () { clearBtn.classList.remove('is-loading'); });
        });
    }

    /* ── 分享按鈕 ── */
    if (shareBtn && shareModal) {
        shareBtn.addEventListener('click', function () {
            const title = shareBtn.dataset.title || document.title;
            const url   = shareBtn.dataset.url   || location.href;
            if (navigator.share) {
                navigator.share({ title: title, url: url }).catch(function () {});
                return;
            }
            shareModal.classList.add('is-open');
        });
        if (shareClose) shareClose.addEventListener('click', function () { shareModal.classList.remove('is-open'); });
        shareModal.addEventListener('click', function (e) { if (e.target === shareModal) shareModal.classList.remove('is-open'); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') shareModal.classList.remove('is-open'); });
    }

    /* ── 複製連結 ── */
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const url = cfg.permalink || location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () {
                    copyBtn.textContent = '✅ 已複製！';
                    setTimeout(function () { copyBtn.textContent = '📋 複製連結'; }, 2000);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
                copyBtn.textContent = '✅ 已複製！';
                setTimeout(function () { copyBtn.textContent = '📋 複製連結'; }, 2000);
            }
        });
    }

    /* ── 閱讀積分：已移除 ──
       原本 3 秒後呼叫 smacg_read_article，寫入舊版 anime_total_points，
       該 meta 已無任何前台顯示或讀取端，屬遷移殘留，故移除呼叫。
       ── */

}); /* END DOMContentLoaded — 追蹤列 */

/* ============================================================
   註：原「二、評分滑桿 + 送出」區塊已於 v16.0 移除。
       評分邏輯統一由 anime-rating.js 負責（REST：ratings/{id}）。
   ============================================================ */
