/* ============================================================
   微笑動漫 — 動漫「評論」腳本
   路徑：/blocksy-child/assets/js/review.js
   版本：1.0 — 2026-08-17

   短評（吐槽，1 字起跳，可掛整部作品或單一集）+ 長評（結構化長文，
   80 字起跳，固定整部作品）雙軌。依賴 window.SmacgConfig
  （apiUrl / nonce / loggedIn / postId，由 anime-status.js 同一批
   localize，見 setup-enqueue.php）。
   ============================================================ */
'use strict';

document.addEventListener('DOMContentLoaded', function () {

    const root = document.getElementById('asd-review-root');
    if (!root) return;

    const cfg      = window.SmacgConfig || {};
    const apiBase  = cfg.apiUrl || '/wp-json/weixiaoacg/v1/';
    const nonce    = cfg.nonce  || '';
    const loggedIn = cfg.loggedIn === true || cfg.loggedIn === '1' || cfg.loggedIn === 1;
    const animeId  = parseInt(root.dataset.animeId, 10);
    let episodes   = [];
    try { episodes = JSON.parse(root.dataset.episodes || '[]'); } catch (e) { episodes = []; }

    let currentTrack = 'short';
    let currentSort  = 'new';

    function requireLogin() {
        if (typeof window.smacgOpenLoginModal === 'function') {
            window.smacgOpenLoginModal();
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function callApi(path, method, body) {
        const opts = {
            method: method,
            headers: { 'X-WP-Nonce': nonce },
        };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(apiBase + path, opts).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    const err = new Error((data && data.message) || ('API error ' + res.status));
                    err.data = data;
                    throw err;
                }
                return data;
            });
        });
    }

    /* ── 骨架 ── */
    root.innerHTML =
        '<div class="asd-review-tabs">' +
            '<button type="button" class="asd-review-track-btn is-active" data-track="short">吐槽（短評）</button>' +
            '<button type="button" class="asd-review-track-btn" data-track="long">評論（長評）</button>' +
        '</div>' +
        '<div class="asd-review-form-wrap"></div>' +
        '<div class="asd-review-toolbar">' +
            '<div class="asd-review-sort">' +
                '<button type="button" class="asd-review-sort-btn is-active" data-sort="new">最新</button>' +
                '<button type="button" class="asd-review-sort-btn" data-sort="hot">熱門</button>' +
            '</div>' +
        '</div>' +
        '<div class="asd-review-list"><p class="asd-review-loading">評論載入中…</p></div>';

    const formWrap = root.querySelector('.asd-review-form-wrap');
    const listWrap = root.querySelector('.asd-review-list');

    root.querySelectorAll('.asd-review-track-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentTrack = btn.dataset.track;
            root.querySelectorAll('.asd-review-track-btn').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            renderForm();
            loadList();
        });
    });

    root.querySelectorAll('.asd-review-sort-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentSort = btn.dataset.sort;
            root.querySelectorAll('.asd-review-sort-btn').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            loadList();
        });
    });

    /* ── 送出表單 ── */
    function renderForm() {
        if (!loggedIn) {
            formWrap.innerHTML =
                '<p class="asd-review-login-hint">' +
                    '<a href="#" class="asd-review-login-link">登入</a>後即可發表評論' +
                '</p>';
            formWrap.querySelector('.asd-review-login-link').addEventListener('click', function (e) {
                e.preventDefault();
                requireLogin();
            });
            return;
        }

        const minLen = currentTrack === 'long' ? 80 : 1;
        const maxLen = currentTrack === 'long' ? 20000 : 300;

        let episodeSelect = '';
        if (currentTrack === 'short' && episodes.length > 0) {
            episodeSelect =
                '<select class="asd-review-episode-select">' +
                    '<option value="0">整部作品</option>' +
                    episodes.map(function (ep) {
                        return '<option value="' + ep + '">第 ' + ep + ' 集</option>';
                    }).join('') +
                '</select>';
        }

        formWrap.innerHTML =
            '<div class="asd-review-form">' +
                (currentTrack === 'long'
                    ? '<input type="text" class="asd-review-title-input" placeholder="標題（選填）" maxlength="100">' +
                      '<input type="text" class="asd-review-excerpt-input" placeholder="摘要（選填，20–60 字）" maxlength="60">'
                    : '') +
                episodeSelect +
                '<textarea class="asd-review-content-input" placeholder="' +
                    (currentTrack === 'long' ? '寫下你的完整心得（至少 80 字）…' : '一句話吐槽這部作品…') +
                    '" maxlength="' + maxLen + '"></textarea>' +
                '<div class="asd-review-form-footer">' +
                    '<label class="asd-review-spoiler-check">' +
                        '<input type="checkbox" class="asd-review-spoiler-input"> 含雷（劇透）' +
                    '</label>' +
                    '<span class="asd-review-counter">0 / ' + minLen + '+</span>' +
                    '<button type="button" class="asd-review-submit-btn">送出</button>' +
                '</div>' +
                '<p class="asd-review-form-msg" style="display:none"></p>' +
            '</div>';

        const contentInput = formWrap.querySelector('.asd-review-content-input');
        const counter      = formWrap.querySelector('.asd-review-counter');
        const submitBtn    = formWrap.querySelector('.asd-review-submit-btn');
        const msgEl        = formWrap.querySelector('.asd-review-form-msg');

        contentInput.addEventListener('input', function () {
            const len = Array.from(contentInput.value.trim()).length;
            counter.textContent = len + ' / ' + minLen + '+';
            counter.classList.toggle('is-ok', len >= minLen);
        });

        submitBtn.addEventListener('click', function () {
            const content = contentInput.value.trim();
            const len = Array.from(content).length;
            if (len < minLen) {
                showMsg(msgEl, '內容至少要 ' + minLen + ' 字（目前 ' + len + ' 字）', true);
                return;
            }

            const payload = {
                track: currentTrack,
                content: content,
                spoiler: formWrap.querySelector('.asd-review-spoiler-input').checked ? 1 : 0,
            };
            const epSelect = formWrap.querySelector('.asd-review-episode-select');
            if (epSelect) {
                payload.episode = parseInt(epSelect.value, 10) || 0;
            }
            if (currentTrack === 'long') {
                payload.title   = formWrap.querySelector('.asd-review-title-input').value.trim();
                payload.excerpt = formWrap.querySelector('.asd-review-excerpt-input').value.trim();
            }

            submitBtn.disabled = true;
            callApi('reviews/' + animeId, 'POST', payload).then(function () {
                contentInput.value = '';
                counter.textContent = '0 / ' + minLen + '+';
                showMsg(msgEl, '發表成功！', false);
                loadList();
            }).catch(function (err) {
                showMsg(msgEl, err.message || '發表失敗，請稍後再試', true);
            }).finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    function showMsg(el, text, isError) {
        el.textContent = text;
        el.style.display = 'block';
        el.classList.toggle('is-error', !!isError);
        setTimeout(function () { el.style.display = 'none'; }, 3000);
    }

    /* ── 列表 ── */
    const WATCH_STATUS_LABEL = {
        want: '想看', watching: '在看', completed: '看過', dropped: '棄番', paused: '擱置',
    };

    function renderCard(item) {
        const spoilerClass = item.spoiler ? ' is-spoiler' : '';
        const episodeTag = item.episode > 0 ? '<span class="asd-review-episode-tag">第 ' + item.episode + ' 集</span>' : '';
        const scoreTag = item.score ? '<span class="asd-review-score-tag">★ ' + item.score + '</span>' : '';
        const statusTag = item.watch_status && WATCH_STATUS_LABEL[item.watch_status]
            ? '<span class="asd-review-status-tag">' + WATCH_STATUS_LABEL[item.watch_status] + '</span>' : '';

        const bodyHtml = item.track === 'long'
            ? (item.title ? '<h4 class="asd-review-card-title">' + escapeHtml(item.title) + '</h4>' : '') +
              (item.excerpt ? '<p class="asd-review-card-excerpt">' + escapeHtml(item.excerpt) + '</p>' : '') +
              '<div class="asd-review-card-content">' + escapeHtml(item.content).replace(/\n/g, '<br>') + '</div>'
            : '<div class="asd-review-card-content">' + escapeHtml(item.content).replace(/\n/g, '<br>') + '</div>';

        const deleteBtn = item.is_mine
            ? '<button type="button" class="asd-review-delete-btn" data-id="' + item.id + '">刪除</button>' : '';

        return (
            '<div class="asd-review-card' + spoilerClass + '" data-id="' + item.id + '">' +
                '<div class="asd-review-card-head">' +
                    '<img class="asd-review-avatar" src="' + escapeHtml(item.avatar) + '" alt="" loading="lazy">' +
                    '<div class="asd-review-card-meta">' +
                        '<span class="asd-review-author">' + escapeHtml(item.author) + '</span>' +
                        episodeTag + statusTag + scoreTag +
                    '</div>' +
                    deleteBtn +
                '</div>' +
                '<div class="asd-review-card-body">' +
                    (item.spoiler
                        ? '<div class="asd-review-spoiler-mask"><button type="button" class="asd-review-reveal-btn">⚠ 含雷內容，點擊查看</button></div>' +
                          '<div class="asd-review-spoiler-content" hidden>' + bodyHtml + '</div>'
                        : bodyHtml) +
                '</div>' +
                '<div class="asd-review-card-foot">' +
                    '<button type="button" class="asd-review-vote-btn asd-review-like-btn' + (item.my_vote === 1 ? ' is-active' : '') + '" data-id="' + item.id + '" data-type="like">👍 <span>' + item.like_count + '</span></button>' +
                    '<button type="button" class="asd-review-vote-btn asd-review-dislike-btn' + (item.my_vote === -1 ? ' is-active' : '') + '" data-id="' + item.id + '" data-type="dislike">👎 <span>' + item.dislike_count + '</span></button>' +
                '</div>' +
            '</div>'
        );
    }

    function loadList() {
        listWrap.innerHTML = '<p class="asd-review-loading">評論載入中…</p>';
        callApi('reviews/' + animeId + '?track=' + currentTrack + '&sort=' + currentSort, 'GET').then(function (data) {
            const items = data.items || [];
            if (items.length === 0) {
                listWrap.innerHTML = '<p class="asd-review-empty">還沒有人發表' + (currentTrack === 'long' ? '評論' : '吐槽') + '，來當第一個吧！</p>';
                return;
            }
            listWrap.innerHTML = items.map(renderCard).join('');
            bindCardEvents();
        }).catch(function () {
            listWrap.innerHTML = '<p class="asd-review-empty">評論載入失敗，請重新整理頁面再試一次</p>';
        });
    }

    function bindCardEvents() {
        listWrap.querySelectorAll('.asd-review-reveal-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const card = btn.closest('.asd-review-card');
                card.querySelector('.asd-review-spoiler-mask').hidden = true;
                card.querySelector('.asd-review-spoiler-content').hidden = false;
            });
        });

        listWrap.querySelectorAll('.asd-review-vote-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!loggedIn) { requireLogin(); return; }
                const id   = btn.dataset.id;
                const type = btn.dataset.type;
                callApi('reviews/item/' + id + '/vote', 'POST', { type: type }).then(function (res) {
                    const card = listWrap.querySelector('.asd-review-card[data-id="' + id + '"]');
                    if (!card) return;
                    const likeBtn    = card.querySelector('.asd-review-like-btn');
                    const dislikeBtn = card.querySelector('.asd-review-dislike-btn');
                    likeBtn.querySelector('span').textContent    = res.like_count;
                    dislikeBtn.querySelector('span').textContent = res.dislike_count;
                    likeBtn.classList.toggle('is-active', res.my_vote === 1);
                    dislikeBtn.classList.toggle('is-active', res.my_vote === -1);
                }).catch(function (err) {
                    if (typeof window.smacgToast === 'function') {
                        window.smacgToast(err.message || '操作失敗');
                    }
                });
            });
        });

        listWrap.querySelectorAll('.asd-review-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm('確定要刪除這則評論嗎？')) return;
                callApi('reviews/item/' + btn.dataset.id, 'DELETE').then(function () {
                    loadList();
                }).catch(function (err) {
                    if (typeof window.smacgToast === 'function') {
                        window.smacgToast(err.message || '刪除失敗');
                    }
                });
            });
        });
    }

    renderForm();
    loadList();
});
