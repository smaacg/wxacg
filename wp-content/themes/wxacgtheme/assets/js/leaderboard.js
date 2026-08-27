/**
 * Leaderboard /ranking-users/ Front-end
 * @version 2.3.0 (2026-08-16)
 *
 * v2.3.0 變更：
 *   - 復原 exp_monthly / followers / badges 三個 type 的 label / 單位
 *     （v2.0.0 曾隨 page 精簡成 3 tab 而移除，現 page-ranking-users.php v2.5.0
 *     重新提供對應 tab）。這 3 種走 Ranking_System::get() 的既有格式
 *     （level + job_title、無 tier），normalizeRow() 與 renderRow() 無需調整。
 *
 * v2.2.0 變更：
 *   - 新增：本季結算倒數（.ranku-season-countdown）。
 *     PHP 首次渲染（page-ranking-users.php）只提供保底初始值（data-end），
 *     實際顯示的倒數以每次 AJAX 回傳的 season_end 為準
 *     （對應 class-leaderboard-ajax.php v2.2.0 新增的 season_end 欄位），
 *     避免使用者長時間停留在頁面、跨過賽季結算時間點後仍顯示過期倒數。
 *   - 倒數只在 rank_season tab 顯示；其他 tab（exp_total /
 *     rank_last_season）隱藏並停止計時器，避免無意義的背景計時。
 *   - tab 切換與 init 流程簡化：不再各自呼叫倒數同步，統一交給
 *     renderList() 依 AJAX 回傳資料處理，避免兩邊 state 不一致。
 *
 * v2.1.0 變更：
 *   - 新增：Hero 區段位徽章（.ranku-hero__tier）跟著 tab 切換即時更新。
 *     搭配後端 class-leaderboard-ajax.php v2.1.0 新增的 my_tier 欄位：
 *       - 切到 rank_season（本季牌位排行）且後端回傳 my_tier → 更新徽章內容並顯示
 *       - 切到其他 tab（exp_total / rank_last_season）→ 隱藏徽章
 *         （「目前段位」只對本季有意義，其他榜單顯示會造成誤解）
 *
 * v2.0.1 變更：
 *   - Fix：「我的名次」被洗成「未上榜」的 bug。
 *     後端 smacg_get_ranking AJAX 回應未包含 my_pos 欄位，
 *     原本 `data.my_pos ? ... : '未上榜'` 會在每次列表載入後
 *     無條件把 PHP 初次渲染算好的正確名次覆蓋成「未上榜」。
 *     現改為：只有後端「明確回傳」my_pos 時才改寫，否則保留 PHP 算好的值。
 *
 * v2.0.0 變更：
 *   - 移除 exp_monthly / followers / badges 三個 type（page 已不再產生對應 tab）
 *   - 新增 rank_season / rank_last_season type 的 label / 單位 / 渲染
 *   - 修正欄位對齊 bug：後端回 avatar_url / display_name / score / extra.tier_*
 *     舊版只認 avatar / display / score_fmt → 改成同時 fallback
 *   - 新增 empty_reason 處理（上季 archive 尚未結算時顯示說明）
 *   - 移除隱私 toggle 邏輯（UI 已從 page 中拿掉）
 *   - 牌位 tab 顯示 tier badge（圖示 + 段位名稱）取代 Lv.
 */
(function () {
  'use strict';

  if (typeof window.smacgRanku === 'undefined') {
    console.warn('[ranku] smacgRanku localize missing');
    return;
  }

  const cfg = window.smacgRanku;
  const tabsEl    = document.getElementById('ranku-tabs');
  const listEl    = document.getElementById('ranku-list');
  const pageEl    = document.getElementById('ranku-pagination');
  const prevBtn   = document.getElementById('ranku-prev');
  const nextBtn   = document.getElementById('ranku-next');
  const pageInfo  = document.getElementById('ranku-page-info');
  const countInfo = document.getElementById('ranku-count-info');
  const updatedEl = document.getElementById('ranku-updated-time');
  const mePosEl   = document.getElementById('ranku-me-pos');
  const heroMeEl  = document.getElementById('ranku-hero-me');
  const tierEl    = document.querySelector('.ranku-hero__tier');
  const heroTitleEl  = document.getElementById('ranku-hero-title-text');
  const toastBox  = document.getElementById('ranku-toast-container');
  const seasonCountdownEl = document.getElementById('ranku-season-countdown'); // ★ v2.2.0 新增

  if (!tabsEl || !listEl) return;

  /* ---------- state ---------- */
  const state = {
    type: cfg.defaultTab || 'exp_total',
    page: 1,
    perPage: 20,
    totalPage: 1,
    loading: false,
  };

  /* ---------- labels ---------- */
  const typeLabel = {
    exp_total:        '等級排行',
    rank_season:      '本季牌位排行',
    rank_last_season: '上季牌位排行',
    exp_monthly:      '本月 EXP 榜',
    login_streak:     '簽到榜',
    followers:        '人氣榜',
    /*
     * 分頁實際送出的 type 是 achievements，badges 是沒人用的舊名。
     * 只留 badges 的話成就榜標題會退回「排行」、單位會是空的。
     */
    achievements:     '成就榜',
    badges:           '徽章榜',
  };
  /*
   * 沒有登記在這裡的類型，單位會是空字串——使用者只看到一個沒有單位的
   * 數字，分不出是天數、分數還是筆數。login_streak 的 score 是連續簽到
   * 天數，一定要標「天」。
   */
  const scoreUnit = {
    exp_total:        'EXP',
    rank_season:      '分',
    rank_last_season: '分',
    exp_monthly:      'EXP',
    login_streak:     '天',
    followers:        '粉絲',
    achievements:     '枚',
    badges:           '枚',
  };

  const isRankTab = (t) => t === 'rank_season' || t === 'rank_last_season';

  /* ---------- helpers ---------- */
  function toast(msg, kind) {
    if (!toastBox) return;
    const t = document.createElement('div');
    t.className = 'ranku-toast ranku-toast--' + (kind || 'ok');
    t.textContent = msg;
    toastBox.appendChild(t);
    requestAnimationFrame(() => t.classList.add('ranku-toast--show'));
    setTimeout(() => {
      t.classList.remove('ranku-toast--show');
      setTimeout(() => t.remove(), 300);
    }, 2400);
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function medal(pos) {
    if (pos === 1) return '🥇';
    if (pos === 2) return '🥈';
    if (pos === 3) return '🥉';
    return '#' + pos;
  }

  function fmtNum(n) {
    const v = Number(n || 0);
    return v.toLocaleString();
  }

  /* ---------- 欄位 fallback：兼容兩種後端格式 ----------
   * Ranking_System::get() 回傳：{rank, score, display_name, avatar, profile_url, level, job_title}
   * Leaderboard_Ajax::get_rank_season() 回傳：{rank_pos, score, display_name, avatar_url, profile_url, extra:{tier_*}}
   */
  function normalizeRow(r) {
    return {
      rank:        r.rank ?? r.rank_pos ?? 0,
      user_id:     r.user_id,
      display:     r.display_name ?? r.display ?? '',
      avatar:      r.avatar_url ?? r.avatar ?? '',
      profile_url: r.profile_url ?? '#',
      score:       r.score ?? 0,
      score_fmt:   r.score_fmt ?? fmtNum(r.score ?? 0),
      level:       r.level ?? null,
      job_title:   r.job_title ?? r.title ?? '',
      tier:        r.extra && r.extra.tier_key ? {
        key:   r.extra.tier_key,
        label: r.extra.tier_label,
        icon:  r.extra.tier_icon,
        color: r.extra.tier_color,
      } : null,
    };
  }

  /* ---------- render ---------- */
  function renderRow(raw) {
    const r    = normalizeRow(raw);
    const isMe = cfg.currentUid && Number(cfg.currentUid) === Number(r.user_id);
    const cls  = [
      'ranku-row',
      'ranku-row--' + r.rank,
      isMe ? 'ranku-row--me' : '',
    ].filter(Boolean).join(' ');

    const posHtml = r.rank <= 3
      ? `<span class="ranku-pos-medal">${medal(r.rank)}</span>`
      : escapeHtml(String(r.rank));

    /* 牌位 tab：顯示 tier badge；等級 tab：顯示 Lv.X + 職業 */
    let metaHtml = '';
    if (r.tier) {
      const color = r.tier.color || '#888';
      metaHtml = `
        <span class="ranku-tier-badge"
              style="--tier-color:${color};color:${color};border-color:${color}55;background:${color}1a;">
          <span class="ranku-tier-icon">${escapeHtml(r.tier.icon || '🎖️')}</span>
          <span class="ranku-tier-label">${escapeHtml(r.tier.label || '')}</span>
        </span>`;
    } else {
      metaHtml = `
        ${r.level ? `<span class="ranku-lv">Lv.${escapeHtml(String(r.level))}</span>` : ''}
        ${r.job_title ? `<span class="ranku-tier">${escapeHtml(r.job_title)}</span>` : ''}
      `;
    }

    return `
      <div class="${cls}" data-uid="${r.user_id}">
        <div class="ranku-pos">${posHtml}</div>
        <img class="ranku-avatar" src="${escapeHtml(r.avatar)}" alt="${escapeHtml(r.display)}" loading="lazy">
        <div class="ranku-userinfo">
          <h4 class="ranku-name">
            <a href="${escapeHtml(r.profile_url)}">${escapeHtml(r.display)}</a>
          </h4>
          <div class="ranku-meta">${metaHtml}</div>
        </div>
        <div class="ranku-score-wrap">
          <div class="ranku-score">${escapeHtml(r.score_fmt)}</div>
          <span class="ranku-score-unit">${escapeHtml(scoreUnit[state.type] || '')}</span>
        </div>
      </div>
    `;
  }

  function renderEmpty(data) {
    /* 上季 archive 尚未結算的專屬訊息 */
    if (data && data.empty_reason === 'no_settled_season') {
      const msg = data.empty_message || '本賽季尚未結束，等到結算後就能看到完整的上季排行～';
      listEl.innerHTML = `
        <div class="ranku-empty">
          <i class="fa-solid fa-scroll" style="font-size:32px;color:var(--accent-violet,#a78bfa);"></i>
          <h3 class="ranku-empty-title">上季排行尚未產生</h3>
          <p class="ranku-empty-desc">${escapeHtml(msg)}</p>
          <button type="button" class="btn btn-secondary" id="ranku-jump-current"
                  style="margin-top:14px;font-size:13px;">
            <i class="fa-solid fa-trophy"></i> 查看本季牌位排行
          </button>
        </div>`;

      const jumpBtn = document.getElementById('ranku-jump-current');
      if (jumpBtn) {
        jumpBtn.addEventListener('click', () => {
          const currentTab = tabsEl.querySelector('.ranku-tab[data-type="rank_season"]');
          if (currentTab) currentTab.click();
        });
      }
      return;
    }

    /* 一般空狀態 */
    listEl.innerHTML = `
      <div class="ranku-empty">
        <i class="fa-solid fa-hourglass-half"></i>
        <h3 class="ranku-empty-title">尚無排行資料</h3>
        <p class="ranku-empty-desc">系統將每小時自動更新，請稍後再來看看～</p>
      </div>`;
  }

  function renderList(data) {
    /* 後端兩種格式：rows (Ranking_System) 或 items (Leaderboard_Ajax 賽季) */
    const rows = data.rows ?? data.items ?? [];

    if (!rows.length) {
      renderEmpty(data);
      pageEl.hidden = true;
      updateCountInfo(data);
      // 注意：空列表時不再覆蓋「我的名次」，理由同下方 mePos 區塊
      maybeUpdateMyPos(data);
      updateHeroTier(data);
      syncSeasonCountdown(data); // ★ v2.2.0 新增
      return;
    }

    listEl.innerHTML = rows.map(renderRow).join('');

    /* pagination */
    const total    = Number(data.total ?? rows.length);
    const perPage  = Number(data.per_page ?? state.perPage);
    const totalPg  = data.total_page ?? Math.max(1, Math.ceil(total / perPage));
    state.totalPage = Math.max(1, totalPg);

    if (state.totalPage <= 1) {
      pageEl.hidden = true;
    } else {
      pageEl.hidden = false;
      pageInfo.textContent = `${state.page} / ${state.totalPage}`;
      prevBtn.disabled = state.page <= 1;
      nextBtn.disabled = state.page >= state.totalPage;
    }

    updateCountInfo(data);

    if (updatedEl && data.updated_at) updatedEl.textContent = data.updated_at;

    maybeUpdateMyPos(data);
    updateHeroTier(data);
    syncSeasonCountdown(data); // ★ v2.2.0 新增
  }

  /* ---------- 我的名次：僅在後端明確回傳 my_pos 時才改寫 ----------
   * v2.0.1 Fix：
   * 目前 smacg_get_ranking AJAX 回應不含 my_pos 欄位，
   * 因此這裡會「保留」PHP 初次渲染（smacg_ranking_user_position）算好的名次，
   * 不再無條件覆蓋成「未上榜」。
   *
   * 之後若後端補上 my_pos（例如在 wp_send_json_success 前加入
   * $data['my_pos'] = smacg_ranking_user_position($type, $uid);），
   * 切換 tab 時這裡就會自動依後端值即時更新，無需再改前端。
   */
  function maybeUpdateMyPos(data) {
    if (!mePosEl) return;
    if (heroMeEl) heroMeEl.dataset.tab = state.type;

    // 後端沒給 my_pos（undefined / null）→ 保留 PHP 算好的值，不動它
    if (typeof data.my_pos === 'undefined' || data.my_pos === null) return;

    mePosEl.textContent = data.my_pos ? '#' + data.my_pos : '未上榜';
  }

  /* ---------- Hero 段位徽章：只在「本季牌位排行」tab 顯示 ---------- v2.1.0 新增
   * - rank_season tab 且後端回傳 my_tier → 更新徽章內容並顯示
   * - 其他 tab（exp_total / rank_last_season）→ 隱藏徽章
   *   （「目前段位」只對本季有意義，其他榜單顯示會造成誤解）
   * 對應後端：class-leaderboard-ajax.php v2.1.0 新增的 my_tier 欄位
   */
  function updateHeroTier(data) {
    if (!tierEl) return;

    if (state.type !== 'rank_season' || !data.my_tier) {
      tierEl.style.display = 'none';
      return;
    }

    const t = data.my_tier;
    tierEl.style.display = '';
    tierEl.style.setProperty('--tier-color', t.color || '#888');

    const iconEl   = tierEl.querySelector('.ranku-hero__tier-icon');
    const labelEl  = tierEl.querySelector('.ranku-hero__tier-label');
    const fillEl   = tierEl.querySelector('.ranku-hero__tier-fill');
    const toNextEl = tierEl.querySelector('.ranku-hero__tier-to-next');
    const scoreEl  = tierEl.querySelector('.ranku-hero__tier-meta span:first-child');

    if (iconEl)  iconEl.textContent  = t.icon  || '';
    if (labelEl) labelEl.textContent = t.label || '';
    if (fillEl)  fillEl.style.width  = (t.percent || 0) + '%';
    if (scoreEl) scoreEl.textContent = fmtNum(t.score) + ' 賽季積分';

    if (toNextEl) {
      toNextEl.textContent = t.is_max
        ? '已達最高段位'
        : `距離 ${t.next_label} 還差 ${t.to_next} 分`;
    }
  }

  /* ---------- 本季結算倒數 ---------- v2.2.0 新增
   * - 只在 rank_season tab 顯示；其他 tab 隱藏並停止計時器
   * - data.season_end（AJAX 回傳，class-leaderboard-ajax.php v2.2.0 新增）
   *   若存在就覆蓋 data-end 上的 PHP 首屏保底值，確保長時間停留頁面、
   *   跨過賽季結算時間點後，倒數仍以後端最新資料為準
   */
  let countdownTimer = null;

  function formatCountdown(endTs) {
    const diff = endTs - Math.floor(Date.now() / 1000);
    if (diff <= 0) return '即將結算';
    const d = Math.floor(diff / 86400);
    const h = Math.floor((diff % 86400) / 3600);
    const m = Math.floor((diff % 3600) / 60);
    if (d > 0) return `${d} 天 ${h} 小時`;
    if (h > 0) return `${h} 小時 ${m} 分`;
    return `${Math.max(m, 1)} 分鐘`;
  }

  function tickCountdown() {
    if (!seasonCountdownEl) return;
    const endTs  = Number(seasonCountdownEl.dataset.end || 0);
    const textEl = seasonCountdownEl.querySelector('.js-ranku-countdown');
    if (textEl && endTs) textEl.textContent = formatCountdown(endTs);
  }

  function startCountdown() {
    stopCountdown();
    tickCountdown();
    countdownTimer = setInterval(tickCountdown, 60000); // 每分鐘更新一次
  }

  function stopCountdown() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  }

  function syncSeasonCountdown(data) {
    if (!seasonCountdownEl) return;

    if (state.type !== 'rank_season') {
      seasonCountdownEl.style.display = 'none';
      stopCountdown();
      return;
    }

    // 用 AJAX 回傳的 season_end 覆蓋首屏保底值（若有）
    if (data && data.season_end) {
      seasonCountdownEl.dataset.end = String(data.season_end);
    }

    seasonCountdownEl.style.display = '';
    startCountdown();
  }

  /*
   * 各榜的分數單位不同（EXP／天／粉絲數），只給一個數字使用者分不出代表
   * 什麼——實際收到「簽到榜的數字代表什麼」的疑問才補這段說明。
   * 文案與 page-ranking-users.php 的 $ranku_descs 一致，兩邊要一起改。
   */
  const typeDesc = {
    exp_total:        '依累積 EXP 排名，EXP 只增不減。',
    rank_season:      '依本季賽季積分排名，每季結算後重新開始。',
    rank_last_season: '上一季結算後的最終排名。',
    exp_monthly:      '依本月獲得的 EXP 排名，每月一號重新計算。',
    login_streak:     '數字是連續簽到天數。登入就自動簽到，中斷一天會從頭算起。',
    achievements:     '依獲得的成就徽章數量排名。',
  };

  function updateTabDesc() {
    const el = document.getElementById('ranku-tab-desc');
    if (!el) return;
    const t = typeDesc[state.type] || '';
    el.textContent = t;
    el.hidden = (t === '');
  }

  function updateCountInfo(data) {
    if (!countInfo) return;
    updateTabDesc();
    const label   = typeLabel[state.type] || '排行';
    const total   = Number(data?.total ?? 0);
    const extra   = data?.season_label ? ` · ${data.season_label}` : '';

    if (total > 0) {
      countInfo.textContent = `${label} · Top ${total}${extra}`;
      countInfo.style.display = '';
      return;
    }

    /*
     * 沒有資料時（例如徽章榜在還沒有人解鎖徽章前 total 為 0），
     * 原本會退化成只印一次 label，跟左邊的分頁按鈕文字完全一樣，
     * 看起來像同一個名稱被顯示了兩次。這種情況直接隱藏；
     * 只有在有額外資訊（賽季標籤）時才留著。
     */
    if (extra) {
      countInfo.textContent = `${label}${extra}`;
      countInfo.style.display = '';
    } else {
      countInfo.textContent = '';
      countInfo.style.display = 'none';
    }
  }

  function renderLoading() {
    listEl.innerHTML = Array(5).fill(0).map(() =>
      `<div class="skeleton" style="height:78px;border-radius:14px;margin-bottom:10px;"></div>`
    ).join('');
  }

  /* ---------- fetch ---------- */
  function load() {
    if (state.loading) return;
    state.loading = true;
    renderLoading();

    const params = new URLSearchParams({
      action:   'smacg_get_ranking',
      type:     state.type,
      page:     state.page,
      per_page: state.perPage,
    });

    fetch(cfg.ajax + '?' + params.toString(), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(res => {
        state.loading = false;
        if (!res.success) {
          toast(res.data?.message || '載入失敗', 'err');
          listEl.innerHTML = `<div class="ranku-empty"><i class="fa-solid fa-circle-exclamation"></i><h3 class="ranku-empty-title">載入失敗</h3><p class="ranku-empty-desc">請稍後再試</p></div>`;
          return;
        }
        renderList(res.data);
        /* 更新網址 query（不重新整理） */
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('tab', state.type);
          window.history.replaceState({}, '', url.toString());
        } catch (e) {}
      })
      .catch(() => {
        state.loading = false;
        toast('網路錯誤', 'err');
      });
  }

/* ---------- events ---------- */
  tabsEl.addEventListener('click', e => {
    const btn = e.target.closest('.ranku-tab');
    if (!btn) return;
    const type = btn.dataset.type;
    if (!type || type === state.type) return;
    tabsEl.querySelectorAll('.ranku-tab').forEach(b => {
      b.classList.toggle('active', b === btn);
      b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
    });
    state.type = type;
    state.page = 1;

    // Hero 標題文字跟著 tab 切換
    if (heroTitleEl && typeLabel[type]) {
      heroTitleEl.textContent = typeLabel[type];
    }

    load(); // renderList 完成後會依 AJAX 資料同步倒數 / 段位徽章 / 我的名次
  });
  prevBtn?.addEventListener('click', () => {
    if (state.page > 1) { state.page--; load(); window.scrollTo({ top: listEl.offsetTop - 80, behavior: 'smooth' }); }
  });
  nextBtn?.addEventListener('click', () => {
    if (state.page < state.totalPage) { state.page++; load(); window.scrollTo({ top: listEl.offsetTop - 80, behavior: 'smooth' }); }
  });
  /* ---------- init ---------- */
  load(); // 首次載入完成後 renderList 會自動同步倒數等狀態
})();