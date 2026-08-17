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

    /*
     * 可用軌道由容器宣告（data-tracks），預設兩軌都有以相容既有的動漫頁。
     * 新聞只給短評：長評那套有標題、80 字下限，是為作品心得設計的，
     * 對新聞留言沒有意義。
     */
    let tracks = String(root.dataset.tracks || 'short,long')
        .split(',')
        .map(function (t) { return t.trim(); })
        .filter(function (t) { return t === 'short' || t === 'long'; });
    if (!tracks.length) { tracks = ['short']; }

    let currentTrack = tracks[0];
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

    /* ── 輕量標記：內容一律以純文字儲存，顯示時才轉成標籤 ──
     *
     * 不存 HTML 是刻意的：存純文字就沒有 XSS 的疑慮，也不必在後端維護
     * 一份允許標籤白名單。轉換一定在 escapeHtml() 之後做，此時使用者輸入
     * 的 < > & 已經是實體字元，只有我們自己產生的標籤會生效。
     */
    function formatText(escaped) {
        return escaped
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
            // 連結只接受 http/https，避免 javascript: 之類的協定
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g,
                '<a href="$2" target="_blank" rel="nofollow noopener">$1</a>')
            // @提及 → 個人頁連結（只認 nicename，與後端解析規則一致）
            .replace(/@([a-zA-Z0-9_.\-]{1,60})/g,
                '<a class="asd-review-mention" href="/u/$1/">@$1</a>')
            .replace(/\n/g, '<br>');
    }

    const TOOLBAR_HTML =
        '<div class="asd-review-toolbar-fmt">' +
            '<button type="button" class="asd-review-fmt-btn" data-fmt="bold" title="粗體"><b>B</b></button>' +
            '<button type="button" class="asd-review-fmt-btn" data-fmt="italic" title="斜體"><i>I</i></button>' +
            '<button type="button" class="asd-review-fmt-btn" data-fmt="link" title="連結">🔗</button>' +
        '</div>';

    /** 把標記套在選取範圍上；沒選取就插入標記並把游標放中間 */
    function bindToolbar(scope, textarea) {
        scope.querySelectorAll('.asd-review-fmt-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const start = textarea.selectionStart;
                const end   = textarea.selectionEnd;
                const sel   = textarea.value.slice(start, end);
                const fmt   = btn.dataset.fmt;

                let before = '**', after = '**';
                if (fmt === 'italic') { before = '*';  after = '*'; }
                if (fmt === 'link')   { before = '[';  after = '](https://)'; }

                const text = before + (sel || (fmt === 'link' ? '連結文字' : '')) + after;
                textarea.setRangeText(text, start, end, 'end');

                // 沒選取文字時把游標移到標記中間，可以直接接著打
                if (!sel && fmt !== 'link') {
                    const pos = start + before.length;
                    textarea.setSelectionRange(pos, pos);
                }
                textarea.focus();
                textarea.dispatchEvent(new Event('input'));
            });
        });
    }

    /* ── @提及自動完成 ──
     *
     * 只在游標緊鄰的 @token 上觸發（往前找到 @、中間不能有空白），
     * 因此貼上含 email 的內容不會誤跳選單。
     */
    function bindMention(textarea) {
        if (!loggedIn) { return; }

        const box = document.createElement('div');
        box.className = 'asd-review-mention-box';
        box.hidden = true;
        textarea.parentNode.insertBefore(box, textarea.nextSibling);

        let items = [];
        let active = -1;
        let timer = null;

        function close() {
            box.hidden = true;
            box.innerHTML = '';
            items = [];
            active = -1;
        }

        function currentToken() {
            const pos = textarea.selectionStart;
            const before = textarea.value.slice(0, pos);
            const m = before.match(/@([a-zA-Z0-9_.\-]*)$/);
            if (!m) { return null; }
            // @ 前面必須是開頭或空白，避免 email 之類的字串誤判
            const prev = before.charAt(before.length - m[0].length - 1);
            if (prev && !/\s/.test(prev)) { return null; }
            return { q: m[1], start: pos - m[1].length };
        }

        function render() {
            if (!items.length) { close(); return; }
            box.innerHTML = items.map(function (u, i) {
                return '<button type="button" class="asd-review-mention-item' +
                    (i === active ? ' is-active' : '') + '" data-nicename="' +
                    escapeHtml(u.nicename) + '">' +
                    '<img src="' + escapeHtml(u.avatar) + '" alt="" loading="lazy">' +
                    '<span>' + escapeHtml(u.name) + '</span>' +
                    '<small>@' + escapeHtml(u.nicename) + '</small>' +
                    '</button>';
            }).join('');
            box.hidden = false;

            box.querySelectorAll('.asd-review-mention-item').forEach(function (el) {
                el.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // 避免 textarea 失焦導致 token 位置跑掉
                    pick(el.dataset.nicename);
                });
            });
        }

        function pick(nicename) {
            const tok = currentToken();
            if (!tok) { close(); return; }
            textarea.setRangeText(nicename + ' ', tok.start, textarea.selectionStart, 'end');
            close();
            textarea.focus();
            textarea.dispatchEvent(new Event('input'));
        }

        textarea.addEventListener('input', function () {
            const tok = currentToken();
            if (!tok || tok.q.length < 1) { close(); return; }

            clearTimeout(timer);
            timer = setTimeout(function () {
                callApi('reviews/mention-search?q=' + encodeURIComponent(tok.q), 'GET')
                    .then(function (data) {
                        items = data.items || [];
                        active = items.length ? 0 : -1;
                        render();
                    })
                    .catch(close);
            }, 200);
        });

        textarea.addEventListener('keydown', function (e) {
            if (box.hidden || !items.length) { return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                render();
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                pick(items[active].nicename);
            } else if (e.key === 'Escape') {
                close();
            }
        });

        textarea.addEventListener('blur', function () { setTimeout(close, 150); });
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
    const trackLabels = { short: '吐槽（短評）', long: '評論（長評）' };

    // 只有一種軌道時不顯示切換列，避免出現一顆按不動的分頁
    const tabsHtml = tracks.length > 1
        ? '<div class="asd-review-tabs">' +
              tracks.map(function (t, i) {
                  return '<button type="button" class="asd-review-track-btn' +
                      (i === 0 ? ' is-active' : '') +
                      '" data-track="' + t + '">' + trackLabels[t] + '</button>';
              }).join('') +
          '</div>'
        : '';

    root.innerHTML =
        tabsHtml +
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
                TOOLBAR_HTML +
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
        bindToolbar(formWrap, contentInput);
        bindMention(contentInput);
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

    /**
     * @param {object} item      評論資料
     * @param {Array}  replies   這則底下的回覆（只有主留言會有；巢狀固定一層）
     */
    function renderCard(item, replies) {
        const isReply      = !!item.reply_to;
        const spoilerClass = item.spoiler ? ' is-spoiler' : '';
        const episodeTag = item.episode > 0 ? '<span class="asd-review-episode-tag">第 ' + item.episode + ' 集</span>' : '';
        const scoreTag = item.score ? '<span class="asd-review-score-tag">★ ' + item.score + '</span>' : '';
        const statusTag = item.watch_status && WATCH_STATUS_LABEL[item.watch_status]
            ? '<span class="asd-review-status-tag">' + WATCH_STATUS_LABEL[item.watch_status] + '</span>' : '';

        const bodyHtml = item.track === 'long'
            ? (item.title ? '<h4 class="asd-review-card-title">' + escapeHtml(item.title) + '</h4>' : '') +
              (item.excerpt ? '<p class="asd-review-card-excerpt">' + escapeHtml(item.excerpt) + '</p>' : '') +
              '<div class="asd-review-card-content">' + formatText(escapeHtml(item.content)) + '</div>'
            : '<div class="asd-review-card-content">' + formatText(escapeHtml(item.content)) + '</div>';

        const deleteBtn = item.is_mine
            ? '<button type="button" class="asd-review-delete-btn" data-id="' + item.id + '">刪除</button>' : '';

        // 只有主留言能被回覆（巢狀一層），回覆本身不再顯示回覆鈕
        const replyBtn = isReply
            ? ''
            : '<button type="button" class="asd-review-reply-btn" data-id="' + item.id + '">💬 回覆</button>';

        // 追蹤同樣只給主留言：一串討論＝母評論加它底下的回覆
        const followBtn = isReply
            ? ''
            : '<button type="button" class="asd-review-follow-btn' +
              (item.following ? ' is-active' : '') + '" data-id="' + item.id + '">' +
              (item.following ? '🔔 追蹤中' : '🔕 追蹤') +
              (item.follower_count > 0 ? ' <span>' + item.follower_count + '</span>' : '') +
              '</button>';

        const repliesHtml = (replies && replies.length)
            ? '<div class="asd-review-replies">' +
                  replies.map(function (r) { return renderCard(r, null); }).join('') +
              '</div>'
            : '';

        return (
            '<div class="asd-review-card' + spoilerClass + (isReply ? ' is-reply' : '') + '" data-id="' + item.id + '">' +
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
                    replyBtn +
                    followBtn +
                '</div>' +
                '<div class="asd-review-reply-form" hidden></div>' +
                repliesHtml +
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
            /*
             * API 回傳的是平的清單，這裡組成一層巢狀。
             * 找不到母評論的回覆（母評論已被刪除）不能直接丟掉，
             * 否則內容會憑空消失，改當成主留言顯示。
             */
            const repliesOf = {};
            const parents   = [];
            const idSet     = {};

            items.forEach(function (it) { idSet[it.id] = true; });

            items.forEach(function (it) {
                if (it.reply_to && idSet[it.reply_to]) {
                    (repliesOf[it.reply_to] = repliesOf[it.reply_to] || []).push(it);
                } else {
                    parents.push(it);
                }
            });

            listWrap.innerHTML = parents.map(function (p) {
                return renderCard(p, repliesOf[p.id]);
            }).join('');
            bindCardEvents();
        }).catch(function () {
            listWrap.innerHTML = '<p class="asd-review-empty">評論載入失敗，請重新整理頁面再試一次</p>';
        });
    }

    /* ── 回覆表單（點「回覆」才展開，避免每則都掛一份表單）── */
    function bindReplyButtons() {
        listWrap.querySelectorAll('.asd-review-reply-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!loggedIn) { requireLogin(); return; }

                const card = btn.closest('.asd-review-card');
                const wrap = card.querySelector('.asd-review-reply-form');

                // 再按一次收合
                if (!wrap.hidden) {
                    wrap.hidden = true;
                    wrap.innerHTML = '';
                    return;
                }

                wrap.hidden = false;
                wrap.innerHTML =
                    TOOLBAR_HTML +
                    '<textarea class="asd-review-reply-input" rows="2" maxlength="300" placeholder="回覆這則留言…"></textarea>' +
                    '<div class="asd-review-reply-actions">' +
                        '<span class="asd-review-reply-msg"></span>' +
                        '<button type="button" class="asd-review-reply-cancel">取消</button>' +
                        '<button type="button" class="asd-review-reply-send">送出</button>' +
                    '</div>';

                const input  = wrap.querySelector('.asd-review-reply-input');
                const msgEl  = wrap.querySelector('.asd-review-reply-msg');
                const sendEl = wrap.querySelector('.asd-review-reply-send');

                bindToolbar(wrap, input);
                bindMention(input);
                input.focus();

                wrap.querySelector('.asd-review-reply-cancel').addEventListener('click', function () {
                    wrap.hidden = true;
                    wrap.innerHTML = '';
                });

                sendEl.addEventListener('click', function () {
                    const text = input.value.trim();
                    if (!text) { showMsg(msgEl, '請先輸入內容', true); return; }

                    sendEl.disabled = true;
                    callApi('reviews/' + animeId, 'POST', {
                        track:    'short',
                        content:  text,
                        reply_to: parseInt(btn.dataset.id, 10)
                    }).then(function () {
                        loadList();
                    }).catch(function (err) {
                        showMsg(msgEl, err.message || '送出失敗', true);
                    }).finally(function () {
                        sendEl.disabled = false;
                    });
                });
            });
        });
    }

    /* ── 追蹤討論串 ── */
    function bindFollowButtons() {
        listWrap.querySelectorAll('.asd-review-follow-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!loggedIn) { requireLogin(); return; }

                btn.disabled = true;
                callApi('reviews/item/' + btn.dataset.id + '/follow', 'POST').then(function (res) {
                    const n = res.follower_count > 0 ? ' <span>' + res.follower_count + '</span>' : '';
                    btn.classList.toggle('is-active', !!res.following);
                    btn.innerHTML = (res.following ? '🔔 追蹤中' : '🔕 追蹤') + n;
                }).catch(function (err) {
                    if (typeof window.smacgToast === 'function') {
                        window.smacgToast(err.message || '操作失敗');
                    }
                }).finally(function () {
                    btn.disabled = false;
                });
            });
        });
    }

    function bindCardEvents() {
        bindReplyButtons();
        bindFollowButtons();

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
