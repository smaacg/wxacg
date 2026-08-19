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

    /*
     * 用詞由容器宣告：「吐槽這部作品」是動漫專用的講法，
     * 新聞頁沿用會很奇怪，因此把名詞抽出來。
     */
    const noun = root.dataset.noun || '';

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

    /* ── 發表時間 ──
     *
     * API 回傳的 date 是 ISO 8601（含時區），交給 Date 解析即可，
     * 不自行拆字串——手動拆會在時區與夏令時間上出錯。
     */
    function parseDate(iso) {
        const d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    /** 相對時間：剛剛／N 分鐘前／N 小時前／N 天前，超過一年顯示日期 */
    function timeAgo(iso) {
        const d = parseDate(iso);
        if (!d) return '';

        const sec = Math.floor((Date.now() - d.getTime()) / 1000);

        // 時鐘誤差或伺服器時間略快時可能是負數，一律當成剛剛
        if (sec < 60)     return '剛剛';
        if (sec < 3600)   return Math.floor(sec / 60) + ' 分鐘前';
        if (sec < 86400)  return Math.floor(sec / 3600) + ' 小時前';
        if (sec < 2592000) return Math.floor(sec / 86400) + ' 天前';
        if (sec < 31536000) return Math.floor(sec / 2592000) + ' 個月前';

        return d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
    }

    /** 完整時間，給 title 屬性用 */
    function formatFullTime(iso) {
        const d = parseDate(iso);
        if (!d) return '';

        const p = function (n) { return n < 10 ? '0' + n : String(n); };

        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
               ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    /* ── 輕量標記：內容一律以純文字儲存，顯示時才轉成標籤 ──
     *
     * 不存 HTML 是刻意的：存純文字就沒有 XSS 的疑慮，也不必在後端維護
     * 一份允許標籤白名單。轉換一定在 escapeHtml() 之後做，此時使用者輸入
     * 的 < > & 已經是實體字元，只有我們自己產生的標籤會生效。
     */
    function formatText(escaped) {
        /*
         * ★ 不支援外部連結（含手打的 [文字](網址) 語法）。
         *   評論是會員制沒錯，但仍擋不住註冊後貼廣告連結；只把工具列按鈕
         *   藏起來沒有用，知道語法的人照樣能打出可點擊連結，因此連轉換
         *   一併移除。網址會以純文字呈現：看得到、可複製，但不可點擊，
         *   廣告價值大幅降低。
         *
         *   站內的 @提及仍轉成個人頁連結，那是自家網址，沒有導流疑慮。
         */
        return escaped
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
            // @ 前必須是開頭或空白，避免把網址裡的 @someone 當成提及
            .replace(/(^|\s)@([a-zA-Z0-9_.\-]{1,60})/g,
                '$1<a class="asd-review-mention" href="/u/$2/">@$2</a>')
            .replace(/\n/g, '<br>');
    }

    const TOOLBAR_HTML =
        '<div class="asd-review-toolbar-fmt">' +
            '<button type="button" class="asd-review-fmt-btn" data-fmt="bold" title="粗體"><b>B</b></button>' +
            '<button type="button" class="asd-review-fmt-btn" data-fmt="italic" title="斜體"><i>I</i></button>' +
        '</div>';

    /** 把標記套在選取範圍上；沒選取就插入標記並把游標放中間 */
    function bindToolbar(scope, textarea) {
        scope.querySelectorAll('.asd-review-fmt-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const start = textarea.selectionStart;
                const end   = textarea.selectionEnd;
                const sel   = textarea.value.slice(start, end);
                const fmt   = btn.dataset.fmt;

                const before = fmt === 'italic' ? '*' : '**';
                const after  = before;

                textarea.setRangeText(before + sel + after, start, end, 'end');

                // 沒選取文字時把游標移到標記中間，可以直接接著打
                if (!sel) {
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
                    (currentTrack === 'long'
                        ? '寫下你的完整心得（至少 80 字）…'
                        : (noun ? '留下你的' + noun + '…' : '一句話吐槽這部作品…')) +
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
            callApi('reviews/' + animeId, 'POST', payload).then(function (data) {
                contentInput.value = '';
                counter.textContent = '0 / ' + minLen + '+';

                /*
                 * 後端在「片單裡還沒有這部」時會依播出狀態自動建立紀錄
                 * （未播出→想看／放送中→追番中／完結→已看完），
                 * 並以 auto_status 回報實際寫入的狀態。
                 *
                 * 一定要明講：使用者的個人片單被改動了，靜默處理會被討厭。
                 * 提示裡直接指向上方追蹤列，讓他能立刻改。
                 */
                /*
                 * WATCH_STATUS_LABEL 定義在本檔後段，但這裡是 click 回呼、
                 * 執行時模組早已初始化完畢，不會踩到 const 的暫時死區。
                 */
                const autoLabel = data && data.auto_status
                    ? (WATCH_STATUS_LABEL[data.auto_status] || data.auto_status)
                    : '';

                /*
                 * 有自動標記時延長到 10 秒——這則訊息要使用者讀完、判斷狀態
                 * 對不對、必要時往上捲去改，3 秒不夠。單純的「發表成功」
                 * 不需要動作，維持 3 秒即可。
                 */
                showMsg(
                    msgEl,
                    autoLabel
                        ? '發表成功！已將這部標記為「' + autoLabel + '」，可在上方追蹤列調整。'
                        : '發表成功！',
                    false,
                    autoLabel ? 10000 : 3000
                );

                /*
                 * 通知追蹤列同步反白。追蹤列由 anime-status.js 以獨立模組作用域
                 * 管理，這裡不直接改 DOM——它內部的 state.status 也必須更新，
                 * 否則使用者接著點同一顆狀態按鈕時會判斷成「重設」而非「取消」。
                 */
                if (data && data.auto_status) {
                    document.dispatchEvent(new CustomEvent('wxacg:status-changed', {
                        detail: { status: data.auto_status },
                    }));
                }

                loadList();
            }).catch(function (err) {
                showMsg(msgEl, err.message || '發表失敗，請稍後再試', true);
            }).finally(function () {
                submitBtn.disabled = false;
            });
        });
    }

    /**
     * 顯示提示訊息。
     *
     * @param {HTMLElement} el
     * @param {string}      text
     * @param {boolean}     isError
     * @param {number}      [ms]  顯示毫秒數；傳 0 表示不自動隱藏，由使用者關閉。
     *
     * ★ 為什麼要記住 timer
     *   原本每次都新開一個 setTimeout 卻不清掉舊的。連續送出兩則留言時，
     *   第一則的計時器會在第二則訊息還沒讀完就把它關掉——看起來像「閃一下
     *   就不見」。
     */
    let msgTimer = null;

    function showMsg(el, text, isError, ms) {
        if (msgTimer) {
            clearTimeout(msgTimer);
            msgTimer = null;
        }

        el.textContent = text;
        el.style.display = 'block';
        el.classList.toggle('is-error', !!isError);

        const delay = (ms === undefined) ? 3000 : ms;

        if (delay > 0) {
            msgTimer = setTimeout(function () {
                el.style.display = 'none';
                msgTimer = null;
            }, delay);
        }
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
            ? '<button type="button" class="asd-review-edit-btn" data-id="' + item.id + '">編輯</button>' +
              '<button type="button" class="asd-review-delete-btn" data-id="' + item.id + '">刪除</button>'
            : '';

        // 發表後被改過就留下痕跡，讓對不上的回覆有跡可循
        const editedTag = item.edited_at
            ? '<span class="asd-review-edited-tag" title="最後編輯：' + escapeHtml(item.edited_at) + '">已編輯</span>'
            : '';

        /*
         * 發表時間。API 一直都有回傳 date（ISO 8601），只是先前沒有顯示，
         * 讀者無從判斷這則留言是今天的還是半年前的。
         *
         * 顯示相對時間（3 分鐘前／2 天前），完整時間放進 title 供滑鼠停留查看；
         * <time datetime> 讓機器也讀得到。
         */
        const timeTag = item.date
            ? '<time class="asd-review-time" datetime="' + escapeHtml(item.date) + '"' +
                  ' title="' + escapeHtml(formatFullTime(item.date)) + '">' +
                  escapeHtml(timeAgo(item.date)) +
              '</time>'
            : '';

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
            '<div class="asd-review-card' + spoilerClass + (isReply ? ' is-reply' : '') +
                '" data-id="' + item.id +
                // 編輯時要回填原始純文字，不能用顯示後的 HTML
                '" data-raw="' + escapeHtml(item.content) + '">' +
                '<div class="asd-review-card-head">' +
                    // 頭像與名稱都連到個人頁，方便從留言認識發言者
                    '<a class="asd-review-author-link" href="' + escapeHtml(item.author_url || '#') + '">' +
                        '<img class="asd-review-avatar" src="' + escapeHtml(item.avatar) + '" alt="" loading="lazy">' +
                    '</a>' +
                    '<div class="asd-review-card-meta">' +
                        '<a class="asd-review-author" href="' + escapeHtml(item.author_url || '#') + '">' +
                            escapeHtml(item.author) +
                        '</a>' +
                        episodeTag + statusTag + scoreTag + editedTag + timeTag +
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
                const emptyNoun = currentTrack === 'long' ? '評論' : (noun || '吐槽');
                listWrap.innerHTML = '<p class="asd-review-empty">還沒有人發表' + emptyNoun + '，來當第一個吧！</p>';
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

    /* ── 就地編輯自己的評論 ── */
    function bindEditButtons() {
        listWrap.querySelectorAll('.asd-review-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const card = btn.closest('.asd-review-card');
                const body = card.querySelector('.asd-review-card-body');

                if (card.dataset.editing === '1') { loadList(); return; }
                card.dataset.editing = '1';
                btn.textContent = '取消';

                // 用原始純文字編輯，不能拿顯示用的 HTML 回填，
                // 否則標記會被轉成標籤後再存回去
                const raw = card.dataset.raw || '';

                body.innerHTML =
                    TOOLBAR_HTML +
                    '<textarea class="asd-review-edit-input" rows="4"></textarea>' +
                    '<div class="asd-review-reply-actions">' +
                        '<span class="asd-review-reply-msg"></span>' +
                        '<button type="button" class="asd-review-edit-save">儲存</button>' +
                    '</div>';

                const input = body.querySelector('.asd-review-edit-input');
                const msgEl = body.querySelector('.asd-review-reply-msg');
                const save  = body.querySelector('.asd-review-edit-save');

                input.value = raw;
                bindToolbar(body, input);
                bindMention(input);
                input.focus();

                save.addEventListener('click', function () {
                    const text = input.value.trim();
                    if (!text) { showMsg(msgEl, '內容不能空白', true); return; }

                    save.disabled = true;
                    callApi('reviews/item/' + btn.dataset.id + '/edit', 'POST', { content: text })
                        .then(function () { loadList(); })
                        .catch(function (err) {
                            showMsg(msgEl, err.message || '儲存失敗', true);
                            save.disabled = false;
                        });
                });
            });
        });
    }

    function bindCardEvents() {
        bindReplyButtons();
        bindFollowButtons();
        bindEditButtons();

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
