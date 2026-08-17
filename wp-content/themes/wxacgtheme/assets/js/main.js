/* ============================================================
   微笑動漫 — Main Page Script
   資料來源：
     AniList  → 熱門作品、歷年神作、即將開播
     Bangumi  → （本季新番週曆已改為 PHP 輸出，此處停用）
   ============================================================ */

'use strict';

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', async () => {
  // 先初始化 OpenCC，確保簡繁轉換可用
  await OpenCCHelper.init();

  initSearch();
  initTabs();
  // loadHeroPosters();     // 已改為 PHP 靜態輸出，不需要 JS 動態載入
  // loadSeasonSection();   // 已改為 PHP + WordPress 資料庫輸出
  loadAnimeGrid('trending');
  initPollClick();
  initChartTabs();
  initWikiCats();
});


/* ============================================================
   SEARCH
   ============================================================ */
function initSearch() {
  const toggle   = document.getElementById('search-toggle');
  const overlay  = document.getElementById('search-overlay');
  const closeBtn = document.getElementById('search-close');
  const input    = document.getElementById('search-input');

  if (!toggle) return;

  toggle.addEventListener('click', () => {
    overlay.classList.toggle('open');
    if (overlay.classList.contains('open')) setTimeout(() => input?.focus(), 100);
  });
  if (closeBtn) closeBtn.addEventListener('click', () => overlay.classList.remove('open'));

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') overlay?.classList.remove('open');
  });

  if (input) {
    input.addEventListener('keydown', async e => {
      if (e.key !== 'Enter' || !input.value.trim()) return;
      const kw = input.value.trim();
      overlay.classList.remove('open');
      showToast(`搜尋「${kw}」中…`, 'info');
      try {
        const results = await AniListAPI.searchMedia(kw, 6);
        if (results.length > 0) {
          showToast(`找到 ${results.length} 部相關作品`, 'success');
        } else {
          showToast('找不到相關作品', 'info');
        }
      } catch {
        showToast('搜尋暫時無法使用', 'info');
      }
    });
  }
}


/* ============================================================
   TABS（熱門作品 tab）
   ============================================================ */
function initTabs() {
  const btns = document.querySelectorAll('.tab-btn:not(.weekday-tab)');
  btns.forEach(btn => {
    btn.addEventListener('click', () => {
      btns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      loadAnimeGrid(btn.dataset.tab);
    });
  });
}

function initChartTabs() {
  document.querySelectorAll('.chart-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
    });
  });
}

function initWikiCats() {
  document.querySelectorAll('.wiki-cat').forEach(cat => {
    cat.addEventListener('click', () => {
      document.querySelectorAll('.wiki-cat').forEach(c => c.classList.remove('active'));
      cat.classList.add('active');
    });
  });
}


/* ============================================================
   ANIME GRID（熱門 / 本季 / 即將開播）— AniList
   ============================================================ */
async function loadAnimeGrid(tab = 'trending') {
  const grid = document.getElementById('anime-grid');
  if (!grid) return;

  // Skeleton
  grid.innerHTML = Array(6).fill(
    `<div class="anime-card skeleton glass" style="height:290px; border-radius:20px;"></div>`
  ).join('');

  try {
    let animes = [];
    const { season, year } = AniListAPI.getCurrentSeason();

    if (tab === 'trending') {
      animes = await AniListAPI.getSeasonalAnime(season, year, 12);
    } else if (tab === 'top') {
      animes = await AniListAPI.getTopAnime(12);
    } else if (tab === 'upcoming') {
      const now   = new Date();
      const month = now.getMonth() + 1;
      const year  = now.getFullYear();
      const SEASONS      = ['WINTER','SPRING','SUMMER','FALL'];
      const curSeasonIdx  = Math.floor((month - 1) / 3);
      const nextSeasonIdx = (curSeasonIdx + 1) % 4;
      const nextSeason    = SEASONS[nextSeasonIdx];
      const nextYear      = nextSeasonIdx === 0 ? year + 1 : year;
      animes = await AniListAPI.getSeasonalAnime(nextSeason, nextYear, 12);
    } else {
      animes = await AniListAPI.getSeasonalAnime(season, year, 12);
    }

    const display = animes.slice(0, 6);
    if (display.length === 0) throw new Error('Empty');

    grid.innerHTML = display.map((a, i) => renderAnimeCard(a, i + 1)).join('');

    // 點擊進入作品頁
    grid.querySelectorAll('.anime-card').forEach(card => {
      card.addEventListener('click', () => {
        const id = card.dataset.id;
        if (id) goToAnime(id);
      });
    });

    // Hover 按鈕
    grid.querySelectorAll('.overlay-btn').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const card   = btn.closest('.anime-card');
        const id     = card?.dataset.id;
        const action = btn.dataset.action;
        if (action === 'collect') showToast('已加入收藏清單', 'success');
        else if (action === 'detail' && id) goToAnime(id);
      });
    });

    // 非同步補充 Bangumi 中文名稱
    enrichCardsWithChineseName(display);

  } catch (err) {
    console.warn('Anime grid load failed:', err);
    grid.innerHTML = `
      <div style="grid-column:1/-1; text-align:center; color:var(--text-muted); padding:40px 0;">
        <i class="fa-solid fa-triangle-exclamation"></i> 資料載入失敗，請稍後重試
      </div>`;
  }
}

function renderAnimeCard(a, rank) {
  const isBangumi  = !!a.doing && !a.coverLarge;
  const score      = isBangumi
    ? BangumiAPI.formatScore(a.score)
    : AniListAPI.formatAniListScore(a.averageScore);
  const popularity = isBangumi
    ? BangumiAPI.formatCount(a.doing)
    : (a.popularity ? (a.popularity >= 10000 ? `${(a.popularity/10000).toFixed(1)}萬` : String(a.popularity)) : null);
  const airDate    = isBangumi
    ? (a.airDate || '').slice(0, 7)
    : (a.seasonYear ? `${a.seasonYear}` : '');
  const image      = isBangumi ? a.image : (a.coverLarge || '');

  const _getTitle   = typeof getDisplayTitle === 'function' ? getDisplayTitle : (x) =>
    (x.titleChinese || x.titleNative || x.titleRomaji || '未知');
  const _getJa      = typeof getJaTitle === 'function' ? getJaTitle : (x) => (x.titleNative || '');
  const displayName = isBangumi
    ? (a.displayName || a.name || '未知')
    : _getTitle(a);
  const jaTitle     = _getJa(a);
  const titleSub    = (!isBangumi && jaTitle && jaTitle !== displayName) ? jaTitle : '';

  const statusStr = isBangumi
    ? (a.doing > 0 ? '追番中' : '已完結')
    : ({'RELEASING':'播出中','FINISHED':'已完結','NOT_YET_RELEASED':'即將播出'}[a.status] || '');
  const statusCls = (a.status === 'RELEASING' || (isBangumi && a.doing > 0))
    ? 'status-airing' : 'status-finished';

  const tags = (a.genres || []).slice(0, 2).map(t =>
    `<span class="chip tag-genre">${t}</span>`
  ).join('');

  return `
    <div class="anime-card glass" data-id="${a.id}"
         data-native="${(a.titleNative || '').replace(/"/g,'&quot;')}"
         style="cursor:pointer;">
      <div class="anime-card-img-wrap">
        <img class="anime-card-img" src="${image}" alt="${displayName}" loading="lazy"
          onerror="this.style.background='var(--glass-bg-mid)'; this.style.display='block'; this.style.height='200px';" />
        <div class="anime-card-rank">#${rank}</div>
        ${score !== '–' ? `<div class="anime-card-score"><i class="fa-solid fa-star" style="font-size:9px;"></i> ${score}</div>` : ''}
        <div class="anime-card-overlay">
          <div class="overlay-actions">
            <button class="overlay-btn primary" data-action="detail">詳細資訊</button>
            <button class="overlay-btn" data-action="collect">收藏</button>
          </div>
        </div>
      </div>
      <div class="anime-card-body">
        <div class="anime-card-title">${displayName}</div>
        ${titleSub ? `<div class="anime-card-title-ja" lang="ja" style="font-size:11px; color:var(--text-muted); margin-bottom:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${titleSub}</div>` : ''}
        <div class="anime-card-genres">${tags}</div>
        <div class="anime-card-stat">
          ${popularity
            ? `<span class="stat-item ${statusCls}"><i class="fa-solid fa-circle" style="font-size:7px;"></i> ${popularity} 人氣</span>`
            : statusStr ? `<span class="stat-item ${statusCls}"><i class="fa-solid fa-circle" style="font-size:7px;"></i> ${statusStr}</span>` : ''}
          ${airDate ? `<span class="stat-item"><i class="fa-solid fa-calendar"></i> ${airDate}</span>` : ''}
        </div>
      </div>
    </div>
  `;
}

/* 非同步為 AniList 卡片補充 Bangumi 繁體中文名稱 */
async function enrichCardsWithChineseName(animes) {
  const grid = document.getElementById('anime-grid');
  if (!grid) return;

  for (const a of animes) {
    const nativeTitle = a.titleNative;
    if (!nativeTitle) continue;

    try {
      const results = await BangumiAPI.searchByTitle(nativeTitle, 3);
      if (!results.length) continue;

      const matched = results.find(r => isTitleMatch(nativeTitle, r.name, r.nameCn));
      if (!matched) continue;

      const cnRaw = matched.nameCn || matched.name || '';
      const cn    = OpenCCHelper.convert(cnRaw);
      if (!cn || cn === nativeTitle || !/[\u4e00-\u9fff]/.test(cn)) continue;

      const card = grid.querySelector(`.anime-card[data-id="${a.id}"]`);
      if (!card) continue;
      const titleEl = card.querySelector('.anime-card-title');
      if (titleEl) titleEl.textContent = cn;

      let subEl = card.querySelector('.anime-card-title-ja, .anime-card-title-en');
      if (!subEl) {
        subEl = document.createElement('div');
        subEl.className = 'anime-card-title-ja';
        subEl.setAttribute('lang', 'ja');
        subEl.style.cssText = 'font-size:11px;color:var(--text-muted);margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        titleEl?.insertAdjacentElement('afterend', subEl);
      }
      subEl.textContent = nativeTitle;
    } catch { /* 單筆失敗不影響其他 */ }
  }
}


/* ============================================================
   NAVIGATION
   ============================================================ */
function goToAnime(id) {
  window.location.href = `anime.html?id=${id}`;
}


/* ============================================================
   POLLS
   ============================================================ */
function initPollClick() {
  document.querySelectorAll('.poll-option').forEach(opt => {
    opt.addEventListener('click', () => showToast('感謝你的投票！', 'success'));
  });
}


/* ============================================================
   TOAST
   ============================================================ */
function showToast(msg, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const icons = { success: 'fa-circle-check', info: 'fa-circle-info', error: 'fa-circle-xmark' };
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i> ${msg}`;
  container.appendChild(toast);

  requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 400);
  }, 3000);
}
