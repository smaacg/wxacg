/**
 * 會員自訂動漫排行 — 編輯器
 *
 * 排序用 ↑↓ 按鈕而非拖曳：HTML5 drag & drop 在觸控裝置上支援很差，
 * 而本站行動流量佔比不低。↑↓ 在所有裝置與鍵盤操作下都能用，也不必
 * 引入第三方函式庫。
 *
 * 所有使用者提供的字串一律用 textContent 寫入，不拼接 innerHTML——
 * 排行標題與作品名稱都可能含有 < > & 等字元。
 *
 * @version 1.0.0
 */
(function () {
  'use strict';

  const root = document.getElementById('wxtl-editor');
  if (!root) { return; }

  let boot;
  try {
    boot = JSON.parse(root.dataset.boot || '{}');
  } catch (e) {
    return;
  }

  const tabsEl = document.getElementById('wxtl-tabs');
  const bodyEl = document.getElementById('wxtl-body');
  const newBtn = document.getElementById('wxtl-new');
  if (!tabsEl || !bodyEl) { return; }

  const poolMap = {};
  (boot.pool || []).forEach(function (p) { poolMap[p.id] = p; });

  const state = {
    lists: (boot.lists || []).map(cloneList),
    index: (boot.lists && boot.lists.length) ? 0 : -1,
    dirty: false,
    saving: false
  };

  function cloneList(l) {
    return {
      id: l.id || 0,
      title: l.title || '',
      size: l.size || 10,
      items: (l.items || []).slice(),
      public: !!l.public
    };
  }

  function current() {
    return state.index >= 0 ? state.lists[state.index] : null;
  }

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  /* ── 分頁列 ── */
  function renderTabs() {
    tabsEl.textContent = '';

    state.lists.forEach(function (l, i) {
      const b = el('button', 'wxtl-ed-tab' + (i === state.index ? ' is-active' : ''),
        l.title || '未命名');
      b.type = 'button';
      b.addEventListener('click', function () {
        if (!confirmDiscard()) { return; }
        state.index = i;
        state.dirty = false;
        render();
      });
      tabsEl.appendChild(b);
    });

    if (newBtn) {
      newBtn.disabled = state.lists.length >= (boot.max || 5);
      newBtn.title = newBtn.disabled
        ? '已達排行數量上限（' + (boot.max || 5) + ' 個）'
        : '';
    }
  }

  function confirmDiscard() {
    if (!state.dirty) { return true; }
    return window.confirm('目前的變更尚未儲存，要放棄嗎？');
  }

  /* ── 編輯區 ── */
  function render() {
    renderTabs();
    bodyEl.textContent = '';

    const list = current();
    if (!list) {
      const p = el('p', 'wxtl-empty', '還沒有排行。按「＋ 新增排行」開始建立你的第一份推薦榜。');
      bodyEl.appendChild(p);
      return;
    }

    bodyEl.appendChild(renderSettings(list));
    bodyEl.appendChild(renderRanked(list));
    bodyEl.appendChild(renderPicker(list));
    bodyEl.appendChild(renderActions(list));
  }

  /* 標題、長度、公開設定 */
  function renderSettings(list) {
    const wrap = el('div', 'wxtl-ed-settings');

    const nameField = el('label', 'wxtl-field');
    nameField.appendChild(el('span', 'wxtl-field-label', '排行名稱'));
    const input = el('input', 'wxtl-input');
    input.type = 'text';
    input.value = list.title;
    input.maxLength = boot.titleMax || 30;
    input.placeholder = '例如：催淚動漫推薦';
    input.addEventListener('input', function () {
      list.title = input.value;
      state.dirty = true;
      renderTabs();
    });
    nameField.appendChild(input);
    wrap.appendChild(nameField);

    const sizeField = el('label', 'wxtl-field wxtl-field--sm');
    sizeField.appendChild(el('span', 'wxtl-field-label', '長度'));
    const sel = el('select', 'wxtl-input');
    (boot.sizes || [10, 20]).forEach(function (s) {
      const o = el('option', null, 'TOP ' + s);
      o.value = String(s);
      if (Number(list.size) === s) { o.selected = true; }
      sel.appendChild(o);
    });
    sel.addEventListener('change', function () {
      list.size = Number(sel.value);
      // 縮短長度時截斷超出的名次，避免使用者以為還留著
      if (list.items.length > list.size) {
        list.items = list.items.slice(0, list.size);
      }
      state.dirty = true;
      render();
    });
    sizeField.appendChild(sel);
    wrap.appendChild(sizeField);

    const pubField = el('label', 'wxtl-field wxtl-field--check');
    const chk = el('input');
    chk.type = 'checkbox';
    chk.checked = !!list.public;
    chk.addEventListener('change', function () {
      list.public = chk.checked;
      state.dirty = true;
    });
    pubField.appendChild(chk);
    pubField.appendChild(el('span', null, '公開（別人才看得到、連結才分享得出去）'));
    wrap.appendChild(pubField);

    return wrap;
  }

  /* 已排名的作品 */
  function renderRanked(list) {
    const wrap = el('div', 'wxtl-ed-ranked');
    wrap.appendChild(el('h3', 'wxtl-ed-h3',
      '排行內容（' + list.items.length + ' / ' + list.size + '）'));

    if (!list.items.length) {
      wrap.appendChild(el('p', 'wxtl-empty', '從下方挑選作品加入排行。'));
      return wrap;
    }

    const ol = el('ol', 'wxtl-ed-list');

    list.items.forEach(function (id, i) {
      const info = poolMap[id];
      const li = el('li', 'wxtl-ed-row');

      li.appendChild(el('span', 'wxtl-ed-rank', String(i + 1)));

      const thumb = el('span', 'wxtl-ed-thumb');
      if (info && info.cover) {
        const img = document.createElement('img');
        img.src = info.cover;
        img.alt = '';
        img.loading = 'lazy';
        thumb.appendChild(img);
      }
      li.appendChild(thumb);

      li.appendChild(el('span', 'wxtl-ed-name', info ? info.title : ('作品 #' + id)));

      const ctrl = el('span', 'wxtl-ed-ctrl');

      const up = el('button', 'wxtl-mini', '↑');
      up.type = 'button';
      up.disabled = (i === 0);
      up.setAttribute('aria-label', '往上移一名');
      up.addEventListener('click', function () { move(list, i, -1); });
      ctrl.appendChild(up);

      const down = el('button', 'wxtl-mini', '↓');
      down.type = 'button';
      down.disabled = (i === list.items.length - 1);
      down.setAttribute('aria-label', '往下移一名');
      down.addEventListener('click', function () { move(list, i, 1); });
      ctrl.appendChild(down);

      const rm = el('button', 'wxtl-mini wxtl-mini--danger', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', '從排行移除');
      rm.addEventListener('click', function () {
        list.items.splice(i, 1);
        state.dirty = true;
        render();
      });
      ctrl.appendChild(rm);

      li.appendChild(ctrl);
      ol.appendChild(li);
    });

    wrap.appendChild(ol);
    return wrap;
  }

  function move(list, i, delta) {
    const j = i + delta;
    if (j < 0 || j >= list.items.length) { return; }
    const tmp = list.items[i];
    list.items[i] = list.items[j];
    list.items[j] = tmp;
    state.dirty = true;
    render();
  }

  /* 可加入的候選作品 */
  function renderPicker(list) {
    const wrap = el('div', 'wxtl-ed-picker');
    wrap.appendChild(el('h3', 'wxtl-ed-h3', '從我的清單加入'));

    const avail = (boot.pool || []).filter(function (p) {
      return list.items.indexOf(p.id) === -1;
    });

    if (!avail.length) {
      wrap.appendChild(el('p', 'wxtl-empty',
        list.items.length ? '清單裡的作品都已加入這個排行。' : '你的追番清單還是空的。'));
      return wrap;
    }

    const full = list.items.length >= list.size;
    if (full) {
      wrap.appendChild(el('p', 'wxtl-note',
        '已達 TOP ' + list.size + ' 上限，要加入其他作品請先移除一部，或把長度改成 20。'));
    }

    const search = el('input', 'wxtl-input wxtl-search');
    search.type = 'search';
    search.placeholder = '搜尋自己的清單…';
    wrap.appendChild(search);

    const grid = el('div', 'wxtl-ed-pool');

    function paint(filter) {
      grid.textContent = '';
      const kw = (filter || '').trim().toLowerCase();

      avail.forEach(function (p) {
        if (kw && p.title.toLowerCase().indexOf(kw) === -1) { return; }

        const b = el('button', 'wxtl-pool-item');
        b.type = 'button';
        b.disabled = full;

        if (p.cover) {
          const img = document.createElement('img');
          img.src = p.cover;
          img.alt = '';
          img.loading = 'lazy';
          b.appendChild(img);
        }
        b.appendChild(el('span', 'wxtl-pool-name', p.title));

        b.addEventListener('click', function () {
          if (list.items.length >= list.size) { return; }
          list.items.push(p.id);
          state.dirty = true;
          render();
        });

        grid.appendChild(b);
      });

      if (!grid.children.length) {
        grid.appendChild(el('p', 'wxtl-empty', '找不到符合的作品。'));
      }
    }

    search.addEventListener('input', function () { paint(search.value); });
    paint('');

    wrap.appendChild(grid);
    return wrap;
  }

  /* 儲存 / 刪除 */
  function renderActions(list) {
    const wrap = el('div', 'wxtl-ed-actions');

    const save = el('button', 'wxtl-btn wxtl-btn-primary', '💾 儲存排行');
    save.type = 'button';
    save.addEventListener('click', function () { saveList(list, save); });
    wrap.appendChild(save);

    if (list.id) {
      const view = el('a', 'wxtl-btn', '🔗 查看公開頁');
      view.href = listUrl(list.id);
      view.target = '_blank';
      view.rel = 'noopener';
      wrap.appendChild(view);
    }

    const del = el('button', 'wxtl-btn wxtl-btn-danger', '🗑️ 刪除');
    del.type = 'button';
    del.addEventListener('click', function () { deleteList(list, del); });
    wrap.appendChild(del);

    const msg = el('span', 'wxtl-msg');
    msg.id = 'wxtl-msg';
    wrap.appendChild(msg);

    return wrap;
  }

  function listUrl(id) {
    // baseUrl 形如 .../toplist/0/ ——把結尾的 0 換成實際 ID
    return String(boot.baseUrl || '').replace(/\/0\/?$/, '/' + id + '/');
  }

  function say(text, isError) {
    const m = document.getElementById('wxtl-msg');
    if (!m) { return; }
    m.textContent = text;
    m.className = 'wxtl-msg' + (isError ? ' is-error' : ' is-ok');
    clearTimeout(m._t);
    m._t = setTimeout(function () { m.textContent = ''; m.className = 'wxtl-msg'; }, 4000);
  }

  function post(action, data, done) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', boot.nonce);
    Object.keys(data).forEach(function (k) { body.set(k, data[k]); });

    fetch(boot.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(done)
      .catch(function () { done({ success: false, data: { msg: '連線失敗，請稍後再試' } }); });
  }

  function saveList(list, btn) {
    if (state.saving) { return; }
    state.saving = true;
    btn.disabled = true;

    post('wxacg_toplist_save', {
      id: list.id || 0,
      title: list.title,
      size: list.size,
      items: list.items.join(','),
      public: list.public ? '1' : '0'
    }, function (res) {
      state.saving = false;
      btn.disabled = false;

      if (!res || !res.success) {
        say((res && res.data && res.data.msg) || '儲存失敗', true);
        return;
      }

      list.id = res.data.id;
      state.dirty = false;

      // 後端會剔除已刪除或非動畫的項目，數量對不上時告知使用者
      if (res.data.sent > res.data.kept) {
        say('已儲存（有 ' + (res.data.sent - res.data.kept) + ' 部作品已失效，已自動移除）');
        list.items = (res.data.list && res.data.list.items) || list.items;
      } else {
        say('已儲存');
      }

      render();
    });
  }

  function deleteList(list, btn) {
    if (!list.id) {
      // 還沒存過，直接從畫面移除
      state.lists.splice(state.index, 1);
      state.index = state.lists.length ? 0 : -1;
      state.dirty = false;
      render();
      return;
    }

    if (!window.confirm('確定刪除「' + (list.title || '未命名') + '」？此動作無法復原。')) {
      return;
    }

    btn.disabled = true;
    post('wxacg_toplist_delete', { id: list.id }, function (res) {
      btn.disabled = false;

      if (!res || !res.success) {
        say((res && res.data && res.data.msg) || '刪除失敗', true);
        return;
      }

      state.lists.splice(state.index, 1);
      state.index = state.lists.length ? 0 : -1;
      state.dirty = false;
      render();
      say('已刪除');
    });
  }

  /* ── 新增 ── */
  if (newBtn) {
    newBtn.addEventListener('click', function () {
      if (state.lists.length >= (boot.max || 5)) { return; }
      if (!confirmDiscard()) { return; }

      state.lists.push({ id: 0, title: '', size: 10, items: [], public: true });
      state.index = state.lists.length - 1;
      state.dirty = true;
      render();

      const input = bodyEl.querySelector('.wxtl-input');
      if (input) { input.focus(); }
    });
  }

  // 離開頁面前提醒未存的變更
  window.addEventListener('beforeunload', function (e) {
    if (!state.dirty) { return; }
    e.preventDefault();
    e.returnValue = '';
  });

  render();
})();
