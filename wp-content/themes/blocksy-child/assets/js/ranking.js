/* ============================================================
   微笑動漫 — ranking.js
   v1.3.0  (2026-05-27)
   
   改動摘要（v1.2 → v1.3）：
   - 站內排行支援 3 類榜單：rating / favorites / views
     * rating    沿用既有貝葉斯加權評分榜
     * favorites 新增，撈 wp_anime_user_status.favorited=1 GROUP BY anime_id
     * views     新增，透過 WP Statistics 撈瀏覽數，支援 daily/weekly/monthly/yearly
   - rankInitSiteSubTabs() 三類都呼叫 API（不再 placeholder 擋下）
   - 切到 views 子分類時，period-btns 重新顯示
   - rankRenderList() 依榜別顯示對應單位（X 人評分 / X 人收藏 / X 次瀏覽）
   - 後端 endpoint：/wp-json/weixiaoacg/v1/ranking/site?rank_type=...&period=...
     完整契約見：anime-sync-pro/includes/class-rating-manager.php

   ★ 側欄路線圖：見 rankInitSidebar() 上方註解
   ============================================================ */
'use strict';

/* ============================================================
   平台設定
   ============================================================ */
const PLATFORMS = {
  site: {
    label: 'weixiaoacg+', icon: '⭐', color: '#6c63ff',
    desc: '來自本站會員的真實評分，採用貝葉斯加權公式，確保評分公平可信',
    tags: ['本站真實數據', '貝葉斯加權', '四維度評分'],
    link: null
  },
  anilist: {
    label: 'AniList', icon: '🌐', color: '#02a9ff',
    desc: '來自 AniList 的全球評分排行，以現代介面和精細評分系統著稱',
    tags: ['歐美用戶為主', '評分細緻', '社群活躍'],
    link: 'https://anilist.co'
  },
  mal: {
    label: 'MAL / Jikan', icon: '📊', color: '#2e51a2',
    desc: '來自 MyAnimeList，全球最大動漫資料庫，歷史資料最完整',
    tags: ['全球最大', '歷史最完整', '用戶最多'],
    link: 'https://myanimelist.net'
  },
  bangumi: {
    label: 'Bangumi', icon: '🎯', color: '#f09199',
    desc: '來自 Bangumi 番組計畫，華語圈最完整的動漫資料庫',
    tags: ['華語圈最完整', '聲優資料詳盡', '中文社群'],
    link: 'https://bgm.tv'
  }
};

/* ============================================================
   v1.3.0：站內子榜的卡片文案（依 rank_type 切換平台介紹卡）
   ============================================================ */
const SITE_SUB_CARDS = {
  rating: {
    desc: '來自本站會員的真實評分，採用貝葉斯加權公式，至少 1 人評分即入榜',
    tags: ['本站真實數據', '貝葉斯加權', '四維度評分']
  },
  favorites: {
    desc: '本站會員收藏動漫的累計排行，至少 3 人收藏才入榜',
    tags: ['真實收藏行為', '會員品味指標', '長期累積']
  },
  views: {
    desc: '透過 WP Statistics 統計站內動漫頁面瀏覽次數，至少 10 次瀏覽才入榜',
    tags: ['真實瀏覽數據', 'GDPR 友善', '可切換時段']
  }
};

/* ============================================================
   STATE
   ============================================================ */
let rankState = {
  platform: 'anilist',
  period:   'weekly',
  rankType: 'rating'   // 站內子分類：rating / favorites / views
};

/* 快取，避免重複 API 請求 */
const _cache = {};

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  rankInitPlatformTabs();
  rankInitPeriodBtns();
  rankInitSiteSubTabs();
  rankInitParticles();
  rankInitSwipe();
  rankRenderAll();
  rankInitSidebar();
});

/* ============================================================
   平台 Tab
   v1.3.0：站內平台時，period-btns 顯隱由「目前 rankType 是不是 views」決定
   ============================================================ */
function rankInitPlatformTabs() {
  document.querySelectorAll('.rank-platform-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      if (tab.disabled) return;

      rankState.platform = tab.dataset.platform;

      document.querySelectorAll('.rank-platform-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      // v1.3.0：依平台與 rankType 決定 UI 元素顯隱
      applyPlatformUIState();

      rankRenderAll();
    });
  });

  /* 預設選中 anilist */
  const defaultTab = document.querySelector('.rank-platform-tab[data-platform="anilist"]');
  if (defaultTab) {
    document.querySelectorAll('.rank-platform-tab').forEach(t => t.classList.remove('active'));
    defaultTab.classList.add('active');
  }
}

/* ============================================================
   v1.3.0：統一控制 period-btns / site-sub-tabs / site-rank-note 的顯隱
   呼叫時機：平台切換、站內子分類切換、初始化
   
   規則：
   - 外部平台 (anilist/mal/bangumi)：顯示 period-btns，隱藏 site-sub-tabs/site-rank-note
   - site + rating:    隱藏 period-btns，顯示 site-sub-tabs 與 site-rank-note
   - site + favorites: 隱藏 period-btns，顯示 site-sub-tabs 與 site-rank-note
   - site + views:     顯示 period-btns（讓使用者切日週月年），顯示 site-sub-tabs
   ============================================================ */
function applyPlatformUIState() {
  const isSite       = rankState.platform === 'site';
  const showPeriod   = !isSite || rankState.rankType === 'views';
  const periodRow    = document.getElementById('period-btns');
  const siteSubRow   = document.getElementById('site-sub-tabs');
  const siteNote     = document.getElementById('site-rank-note');

  if (periodRow)  periodRow.style.display  = showPeriod ? '' : 'none';
  if (siteSubRow) siteSubRow.style.display = isSite ? 'flex' : 'none';
  if (siteNote)   siteNote.style.display   = isSite ? 'block' : 'none';
}

/* ============================================================
   站內子分類 Tab
   v1.3.0：三類都呼叫 API（不再寫死 placeholder）
   切到 views 時重新顯示 period-btns
   ============================================================ */
function rankInitSiteSubTabs() {
  document.querySelectorAll('.site-sub-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      rankState.rankType = btn.dataset.rankType;

      document.querySelectorAll('.site-sub-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      // 切換子分類時，更新 UI 元素顯隱（views 要顯示週期、其他要隱藏）
      applyPlatformUIState();

      // 清除舊快取後重新拉取
      const cacheKey = `site_${rankState.rankType}_${rankState.period}`;
      delete _cache[cacheKey];
      rankFetchAndRender();
    });
  });
}

/* ============================================================
   週期 Tab
   ============================================================ */
function rankInitPeriodBtns() {
  document.querySelectorAll('.period-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      rankState.period = btn.dataset.period;
      document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      rankFetchAndRender();
    });
  });
}

/* ============================================================
   RENDER ALL
   ============================================================ */
function rankRenderAll() {
  rankRenderPlatformCard(rankState.platform);

  /* v1.4.0：預設視圖已由 PHP 伺服器端渲染（見 page-ranking.php）。
     首次載入時若標記相符，就直接沿用現成的 HTML，
     不再重新抓一次資料、也不會讓已顯示的內容閃一下。
     使用者一旦切換頁籤或期間，就照常走 rankFetchAndRender()。 */
  const listEl = document.getElementById('rank-list');

  if (
    listEl &&
    listEl.dataset.prerendered === '1' &&
    listEl.dataset.platform === rankState.platform &&
    listEl.dataset.period === rankState.period
  ) {
    updateCountInfo(listEl.querySelectorAll('.rank-card').length);
    listEl.dataset.prerendered = '0';
    return;
  }

  rankFetchAndRender();
}

/* ============================================================
   平台介紹卡
   v1.3.0：站內平台時，文案依 rankType 切換（rating / favorites / views）
   ============================================================ */
function rankRenderPlatformCard(platform) {
  const p = PLATFORMS[platform];
  if (!p) return;
  const card = document.getElementById('platform-info-card');
  if (!card) return;

  // v1.3.0：站內平台依 rankType 取對應文案
  let desc = p.desc;
  let tags = p.tags;
  if (platform === 'site') {
    const subCard = SITE_SUB_CARDS[rankState.rankType] || SITE_SUB_CARDS.rating;
    desc = subCard.desc;
    tags = subCard.tags;
  }

  card.innerHTML = `
    <div style="display:flex;align-items:flex-start;gap:14px;">
      <div style="width:36px;height:36px;border-radius:10px;background:${p.color}22;color:${p.color};
                  display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
        ${p.icon}
      </div>
      <div style="min-width:0;">
        <div style="font-weight:700;color:${p.color};margin-bottom:4px;">${p.label}</div>
        <p style="font-size:12px;color:var(--text-muted,rgba(208,215,224,.55));margin:0 0 8px;line-height:1.6;">${desc}</p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          ${tags.map(t => `<span style="font-size:10px;padding:2px 8px;border-radius:50px;
            background:${p.color}15;border:1px solid ${p.color}30;color:${p.color};">${t}</span>`).join('')}
          ${p.link ? `<a href="${p.link}" target="_blank" rel="noopener"
            style="font-size:10px;padding:2px 8px;border-radius:50px;color:var(--text-muted);
            border:1px solid var(--glass-border,rgba(255,255,255,.08));text-decoration:none;">
            前往 ${p.label} ↗</a>` : ''}
        </div>
      </div>
    </div>`;
  card.style.borderColor = `${p.color}44`;
}

/* ============================================================
   Skeleton Loading
   ============================================================ */
function rankShowSkeleton() {
  const listEl = document.getElementById('rank-list');
  if (!listEl) return;
  listEl.innerHTML = Array(10).fill(`
    <div class="rank-loading">
      <div class="skeleton" style="height:90px;border-radius:16px;margin-bottom:8px;"></div>
    </div>`).join('');
}

/* ============================================================
   API FETCH + RENDER
   v1.3.0：站內 3 類都呼叫 fetchSiteRanking（不再擋下非 rating）
   ============================================================ */
async function rankFetchAndRender() {
  const { platform, period, rankType } = rankState;

  // 快取 key：站內依 rankType + period（views 才用 period），其他平台依 platform + period
  const cacheKey = platform === 'site'
    ? `site_${rankType}_${rankType === 'views' ? period : 'all'}`
    : `${platform}_${period}`;

  rankShowSkeleton();

  try {
    let items = _cache[cacheKey];
    if (!items) {
      if (platform === 'site') {
        items = await fetchSiteRanking(20, rankType, period);
      } else if (platform === 'anilist' || platform === 'mal' || platform === 'bangumi') {
        items = await fetchExternalRanking(platform, period);
      } else {
        items = [];
      }
      _cache[cacheKey] = items;
    }
    // v1.3.0：每次重渲染前，先更新平台介紹卡（rankType 可能已變）
    rankRenderPlatformCard(platform);
    rankRenderList(items);
  } catch (e) {
    console.error('[ranking]', e);
    const listEl = document.getElementById('rank-list');
    if (listEl) listEl.innerHTML = `
      <div style="text-align:center;padding:48px 0;color:var(--text-muted);">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:28px;display:block;margin-bottom:12px;"></i>
        資料載入失敗，請稍後再試
      </div>`;
  }
}

/* ============================================================
   weixiaoacg+ 站內排行（v1.3.0：支援 rank_type / period）
   呼叫 /wp-json/weixiaoacg/v1/ranking/site
   後端契約見：anime-sync-pro/includes/class-rating-manager.php
   ============================================================ */
async function fetchSiteRanking(limit = 20, rankType = 'rating', period = 'monthly') {
  const params = new URLSearchParams({ limit: String(limit), rank_type: rankType });

  // views 才需要 period；rating / favorites 後端會忽略
  if (rankType === 'views') params.set('period', period);

  const res = await fetch(
    `/wp-json/weixiaoacg/v1/ranking/site?${params.toString()}`,
    { headers: { 'Accept': 'application/json' } }
  );

  if (!res.ok) throw new Error(`Site Ranking API HTTP ${res.status}`);

  const data = await res.json();

  if (!Array.isArray(data) || data.length === 0) return [];

  return data.map((item, i) => ({
    rank:     i + 1,
    titleZh:  item.title  || '未命名',
    titleJp:  '',
    cover:    item.cover  || '',
    score:    item.score  != null ? Number(item.score).toFixed(2) : null,
    scoredBy: item.vote_count || 0,
    genres:   [],
    year:     '',
    // 4 維分項：只有 rating 榜會有
    avgStory:     item.avg_story     != null ? Number(item.avg_story).toFixed(1)     : null,
    avgMusic:     item.avg_music     != null ? Number(item.avg_music).toFixed(1)     : null,
    avgAnimation: item.avg_animation != null ? Number(item.avg_animation).toFixed(1) : null,
    avgVoice:     item.avg_voice     != null ? Number(item.avg_voice).toFixed(1)     : null,
    isSite:   true,
    // v1.3.0：站內子榜類型
    rankType: item.rank_type || rankType,
    url:      item.url || '#',
    animeId:  item.anime_id || null
  }));
}

/* ============================================================
   AniList API
   ============================================================ */
/* ============================================================
   外部平台排行（AniList / MAL / Bangumi）

   v1.4.0：改由本站後端代抓。

   原本這三支是在瀏覽器端直接打第三方 API，造成：
     1. 排行內容不在 HTML 裡，搜尋引擎抓到空容器
     2. 每位訪客都要等三次跨國往返
     3. Jikan 速率限制（3 req/s）以「每位訪客」為單位消耗，流量一大就失敗

   後端契約見：blocksy-child/inc/ranking-feed.php
     GET /wp-json/weixiaoacg/v1/ranking/external?platform=&period=
     回傳 { platform, period, updated, items: [...] }
   items 的欄位結構與原本前端自行正規化的結果完全一致，
   因此 rankRenderList() 不需要改動。
   ============================================================ */
async function fetchExternalRanking(platform, period) {
  const params = new URLSearchParams({ platform, period });

  const res = await fetch(
    `/wp-json/weixiaoacg/v1/ranking/external?${params.toString()}`,
    { headers: { 'Accept': 'application/json' } }
  );

  if (!res.ok) throw new Error(`Ranking API HTTP ${res.status}`);

  const data = await res.json();
  return data?.items || [];
}

/* ============================================================
   渲染列表
   v1.3.0：站內 3 類榜單依 rankType 顯示不同單位
     rating    → 「X 人評分」 + 4 維分項
     favorites → 「X 人收藏」 + 💖 圖示
     views     → 「X 次瀏覽」 + 🔥 圖示
   外部平台維持原邏輯
   ============================================================ */
function rankRenderList(items) {
  const listEl = document.getElementById('rank-list');
  if (!listEl) return;

  const { platform, period, rankType } = rankState;
  const p = PLATFORMS[platform];
  const color = p?.color || '#63a8ff';

  if (!items || !items.length) {
    listEl.innerHTML = `
      <div style="text-align:center;padding:48px 0;color:var(--text-muted);">
        <i class="fa-solid fa-box-open" style="font-size:28px;display:block;margin-bottom:12px;"></i>
        ${getEmptyMessage(platform, rankType)}
      </div>`;
    updateCountInfo(0);
    return;
  }

  listEl.innerHTML = items.map(item => {
    const numClass = item.rank === 1 ? 'rank-card__num--top1'
                   : item.rank === 2 ? 'rank-card__num--top2'
                   : item.rank === 3 ? 'rank-card__num--top3' : '';

    const crown = item.rank === 1 ? '<span class="rank-card__crown">👑</span>' : '';

    // v1.3.0：score 區塊依站內 rankType 切換
    let scoreHtml = '';
    if (item.isSite && rankType === 'favorites') {
      scoreHtml = `<div class="rank-card__score" style="color:${color};font-size:20px;">💖</div>`;
    } else if (item.isSite && rankType === 'views') {
      scoreHtml = `<div class="rank-card__score" style="color:${color};font-size:20px;">🔥</div>`;
    } else if (item.score) {
      scoreHtml = `<div class="rank-card__score" style="color:${color};">${item.score}</div>
                   <div class="rank-card__score-label">/ 10</div>`;
    }

    // v1.3.0：votes 文字依 rankType 切換
    let votesHtml = '';
    if (item.scoredBy) {
      const num = Number(item.scoredBy).toLocaleString();
      let unit = '人評分';
      if (item.isSite && rankType === 'favorites') unit = '人收藏';
      else if (item.isSite && rankType === 'views') unit = '次瀏覽';
      votesHtml = `<div class="rank-card__votes">${num} ${unit}</div>`;
    }

    const genres = (item.genres || []).map(g =>
      `<span class="rank-card__tag">${g}</span>`).join('');

    const yearTag = item.year
      ? `<span class="rank-card__tag rank-card__tag--year">${item.year}</span>`
      : '';

    // 4 維分項：只有 rating 榜會顯示（favorites/views 後端傳 null，自然不顯示）
    const siteSubScores = item.isSite && (item.avgStory || item.avgMusic || item.avgAnimation || item.avgVoice)
      ? `<div class="rank-card__site-sub" style="font-size:10px;color:var(--text-muted);margin-top:4px;line-height:1.8;">
           ${item.avgStory     ? `劇情 <strong style="color:${color};">${item.avgStory}</strong>` : ''}
           ${item.avgMusic     ? `&nbsp;· 音樂 <strong style="color:${color};">${item.avgMusic}</strong>` : ''}
           ${item.avgAnimation ? `&nbsp;· 作畫 <strong style="color:${color};">${item.avgAnimation}</strong>` : ''}
           ${item.avgVoice     ? `&nbsp;· 聲優 <strong style="color:${color};">${item.avgVoice}</strong>` : ''}
         </div>`
      : '';

    const href   = item.url || '#';
    const target = item.isSite ? '' : 'target="_blank" rel="noopener noreferrer"';

    return `
    <a class="rank-card" href="${href}" ${target}>
      <div class="rank-card__rank">
        ${crown}
        <div class="rank-card__num ${numClass}">${item.rank}</div>
      </div>
      <div class="rank-card__cover">
        ${item.cover
          ? `<img src="${item.cover}" alt="${item.titleZh}" loading="lazy"
               onerror="this.parentElement.innerHTML='<div class=rank-card__cover-fb>🎬</div>';">`
          : `<div class="rank-card__cover-fb">🎬</div>`}
      </div>
      <div class="rank-card__body">
        <div class="rank-card__title">${item.titleZh}</div>
        ${item.titleJp && item.titleJp !== item.titleZh
          ? `<div class="rank-card__native">${item.titleJp}</div>` : ''}
        <div class="rank-card__tags">${genres}${yearTag}</div>
        ${siteSubScores}
      </div>
      <div class="rank-card__meta">
        ${scoreHtml}
        ${votesHtml}
        <div class="rank-card__action" style="color:${color};border-color:${color}44;">
          ${item.isSite ? '查看' : '詳情'} →
        </div>
      </div>
    </a>`;
  }).join('');

  updateCountInfo(items.length);
}

/* ============================================================
   v1.3.0：空資料訊息
   ============================================================ */
function getEmptyMessage(platform, rankType) {
  if (platform !== 'site') return '此條件暫無資料';
  if (rankType === 'favorites') return '目前尚無收藏資料，成為第一個收藏者吧！';
  if (rankType === 'views')     return '此時段尚無瀏覽資料，請改選其他時段';
  return '目前尚無評分資料，成為第一個評分的人吧！';
}

/* ============================================================
   v1.3.0：計數列文字（依平台與 rankType 動態組合）
   ============================================================ */
function updateCountInfo(count) {
  const countEl = document.getElementById('rank-count-info');
  if (!countEl) return;

  const { platform, period, rankType } = rankState;
  const periodLabels = { daily:'今日', weekly:'本週', monthly:'本月', yearly:'年度' };
  const p = PLATFORMS[platform];

  if (platform === 'site') {
    let label = '評分排行';
    if (rankType === 'favorites') label = '收藏排行';
    else if (rankType === 'views') label = `${periodLabels[period] || '本月'}瀏覽排行`;
    countEl.textContent = `weixiaoacg+ ${label} · Top ${count}`;
  } else {
    countEl.textContent = `${periodLabels[period] || '本週'} ${p?.label || ''} 排行 · Top ${count}`;
  }
}

/* ============================================================
   SIDEBAR（本週新上榜 / 本週排名變動）
   
   ┌─────────────────────────────────────────────────────────┐
   │ 目前狀態：方案 1 暫存版（v1.3.0）                          │
   │ ─────────────────────────────────────────────────────── │
   │ 本函式目前只顯示靜態 placeholder（"評分系統開發中"...）     │
   │                                                          │
   │ 未來升級到方案 3 時，本函式需要改為 async：                │
   │   1. fetch('/wp-json/weixiaoacg/v1/ranking/site/new'      │
   │             + '?period=weekly')                          │
   │   2. fetch('/wp-json/weixiaoacg/v1/ranking/site/movers'   │
   │             + '?period=weekly')                          │
   │   3. 渲染為 .sb-rank-list 內的卡片清單                    │
   │   4. 加 loading skeleton（可參考 rankShowSkeleton 模式）  │
   │   5. 加 error fallback（網路錯誤時保留目前的 placeholder）│
   │                                                          │
   │ 後端 endpoint 需先實作，完整路線圖見：                    │
   │   anime-sync-pro/includes/class-rating-manager.php       │
   │   開頭的 ROADMAP 區塊                                     │
   │                                                          │
   │ 注意：側欄顯示「站內資料」，**不**跟著主榜平台切換而變化   │
   │      （rankInitPlatformTabs 內無需呼叫本函式）           │
   │                                                          │
   │ 觸發實作的條件：站內已評分動漫數 ≥ 100 部                  │
   └─────────────────────────────────────────────────────────┘
   ============================================================ */
function rankInitSidebar() {
  const newEl = document.getElementById('sidebar-new-list');
  if (newEl) {
    newEl.innerHTML = `
      <div style="text-align:center;padding:20px 0;color:var(--text-muted,rgba(208,215,224,.55));font-size:12px;line-height:1.8;">
        <i class="fa-solid fa-clock" style="font-size:20px;display:block;margin-bottom:8px;opacity:.5;"></i>
        評分系統開發中<br>敬請期待
      </div>`;
  }

  const moverEl = document.getElementById('sidebar-movers-list');
  if (moverEl) {
    moverEl.innerHTML = `
      <div style="text-align:center;padding:20px 0;color:var(--text-muted,rgba(208,215,224,.55));font-size:12px;line-height:1.8;">
        <i class="fa-solid fa-chart-line" style="font-size:20px;display:block;margin-bottom:8px;opacity:.5;"></i>
        站內排名數據<br>即將上線
      </div>`;
  }
}

/* ============================================================
   PARTICLES
   ============================================================ */
function rankInitParticles() {
  const canvas = document.getElementById('rank-particles');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W = canvas.width = canvas.offsetWidth;
  let H = canvas.height = canvas.offsetHeight;

  const dots = Array.from({ length: 60 }, () => ({
    x: Math.random() * W, y: Math.random() * H,
    r: Math.random() * 1.5 + 0.4,
    vx: (Math.random() - 0.5) * 0.3,
    vy: (Math.random() - 0.5) * 0.3,
    a: Math.random()
  }));

  (function draw() {
    ctx.clearRect(0, 0, W, H);
    dots.forEach(d => {
      ctx.beginPath();
      ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(180,160,255,${d.a * 0.6})`;
      ctx.fill();
      d.x += d.vx; d.y += d.vy;
      if (d.x < 0) d.x = W; if (d.x > W) d.x = 0;
      if (d.y < 0) d.y = H; if (d.y > H) d.y = 0;
    });
    requestAnimationFrame(draw);
  })();

  window.addEventListener('resize', () => {
    W = canvas.width = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
  });
}

/* ============================================================
   SWIPE（手機左右滑動切換平台）
   ============================================================ */
function rankInitSwipe() {
  const el = document.querySelector('.rank-main');
  if (!el) return;
  let startX = 0, startY = 0;

  el.addEventListener('touchstart', e => {
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
  }, { passive: true });

  el.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - startX;
    const dy = e.changedTouches[0].clientY - startY;
    if (Math.abs(dx) < 60 || Math.abs(dy) > Math.abs(dx) * 0.8) return;

    const tabs = Array.from(document.querySelectorAll('.rank-platform-tab'));
    const cur  = tabs.findIndex(t => t.classList.contains('active'));
    if (cur === -1) return;
    const next = dx < 0 ? Math.min(cur + 1, tabs.length - 1) : Math.max(cur - 1, 0);
    if (next !== cur) {
      tabs[next].click();
      tabs[next].scrollIntoView({ inline: 'center', behavior: 'smooth' });
    }
  }, { passive: true });
}
