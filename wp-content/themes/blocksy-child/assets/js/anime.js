/* ============================================================
   微笑動漫 — Anime Detail Page Script
   資料來源：
     AniList  → 繁中名、banner、評分、倒數
     Bangumi  → 中文簡介(OpenCC)、集數列表、關聯作品
     Jikan    → 角色/聲優、MAL評分、串流平台
     AnimeThemes → OP/ED 試聽
   主鍵：AniList ID（URL: anime.html?id=ANILIST_ID）
   ============================================================ */

'use strict';

const WATCHLIST_KEY = 'smile_watchlist';
const PROGRESS_KEY  = 'smile_progress';

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', async () => {
  const params    = new URLSearchParams(window.location.search);
  const rawId     = params.get('id');
  const bgmId     = params.get('bgm');    // 新番導航傳入的 Bangumi ID

  // Sticky header
  const header = document.getElementById('site-header');
  if (header) window.addEventListener('scroll', () =>
    header.classList.toggle('scrolled', window.scrollY > 50));

  // 先初始化 OpenCC
  await OpenCCHelper.init();

  initDiscussionTabs();
  initToast();

  // WordPress 部署時：若 weixiaoacgAcf 已由 wp_localize_script 注入，優先套用 ACF 資料
  // 純前端預覽環境下此函式會因 window.weixiaoacgAcf 不存在而直接 return
  applyAcfData();

  /* ── 支援 ?bgm=BANGUMI_ID：先橋接到 AniList ID ── */
  if (bgmId && !rawId) {
    await loadAnimePageFromBgmId(parseInt(bgmId));
    return;
  }

  if (!rawId) { showError('請提供有效的作品 ID（?id=ANILIST_ID 或 ?bgm=BGM_ID）'); return; }
  await loadAnimePage(parseInt(rawId));
});

/* 從 Bangumi ID 橋接到 AniList ID，再載入詳情頁 */
async function loadAnimePageFromBgmId(bgmId) {
  try {
    const loadingEl = document.getElementById('page-loading');
    if (loadingEl) loadingEl.textContent = '正在橋接資料來源…';

    // Step 1：從 Bangumi 取得作品名稱
    const bgmSubject = await BangumiAPI.getSubjectById(bgmId);
    if (!bgmSubject) { showError('Bangumi 找不到此作品'); return; }

    const searchTitle = bgmSubject.name || bgmSubject.nameCn || '';
    if (!searchTitle) { showError('無法取得作品名稱'); return; }

    // Step 2：用名稱搜尋 AniList，取最接近的結果
    let best = null;
    try {
      const results = await AniListAPI.searchMedia(searchTitle, 8);
      if (results.length) {
        // 優先取標題相符的結果，再 fallback 到第一筆
        best = results.find(r =>
          isTitleMatch(searchTitle, r.titleNative, r.titleEnglish) ||
          isTitleMatch(searchTitle, r.titleRomaji,  r.titleEnglish)
        ) || results[0];
      }
    } catch { /* AniList 搜尋失敗，best 維持 null */ }

    if (!best) {
      showError(`找不到「${searchTitle}」對應的 AniList 條目，請改用 AniList ID 查詢。`);
      return;
    }

    // Step 3：更新 URL（不重新載入頁面）
    const newUrl = `${window.location.pathname}?id=${best.id}`;
    window.history.replaceState({}, '', newUrl);

    // Step 4：用 AniList ID 載入詳情（同時傳入已知的 bgmId）
    await loadAnimePage(best.id, bgmId);

  } catch (err) {
    console.error('loadAnimePageFromBgmId error:', err);
    showError('資料橋接失敗，請稍後重試。');
  }
}


/* ============================================================
   MAIN LOAD — 四 API 整合流程
   @param anilistId   AniList ID
   @param presetBgmId 已知的 Bangumi ID（從新番導航帶入，可跳過 Jikan external 查詢）
   ============================================================ */
async function loadAnimePage(anilistId, presetBgmId = null) {
  try {
    /* ── Step 1：AniList 主資料 ── */
    const media = await AniListAPI.getMedia(anilistId);
    if (!media) { showError('找不到此作品（AniList ID 不存在）'); return; }

    // 顯示主內容
    document.getElementById('page-loading').style.display = 'none';
    document.getElementById('anime-main').style.display   = 'block';

    // 先用 AniList 資料渲染 Hero（最快）
    renderHeroFromAniList(media);
    renderProgress({ id: anilistId, eps: media.episodes });
    renderAiringCountdown(media.nextAiring);

    // 儲存全域狀態
    window._currentAnime       = { anilist_id: anilistId, mal_id: media.idMal, episodes: media.episodes };
    window._currentAnimeMedia  = media;   // 供 renderCharacters / handleUpcomingState 使用

    // 第一次即可填入 sidebar data（AniList 已有 favourites / popularity / statusDist 等）
    fillSidebarData(media);

    // 未播出偵測
    handleUpcomingState(media);

    // 頁面標題（繁中優先）
    const _pageTitle = (typeof getDisplayTitle === 'function') ? getDisplayTitle(media) : (media.displayName || media.titleRomaji || '動畫作品');
    document.getElementById('page-title').textContent = `${_pageTitle} — 微笑動漫`;

    /* ── Step 2：並行抓取補充資料 ── */
    const malId = media.idMal;

    // 若已有 presetBgmId（從新番導航帶入），直接用，省去 Jikan external 查詢
    let jikanData      = null;
    let bgmIdFromJikan = presetBgmId || null;

    if (presetBgmId) {
      // 只需要 Jikan 主資料，getBangumiId 已知可跳過
      const [jRes] = await Promise.allSettled([
        malId ? JikanAPI.getAnimeById(malId) : Promise.resolve(null),
      ]);
      jikanData = jRes.status === 'fulfilled' ? jRes.value : null;
    } else {
      // getBangumiId 使用獨立 fetch（不走 Jikan queue）→ 可与 getAnimeById 真正並行
      const [jikanRes, bgmIdRes] = await Promise.allSettled([
        malId ? JikanAPI.getAnimeById(malId)  : Promise.resolve(null),
        malId ? JikanAPI.getBangumiId(malId)  : Promise.resolve(null),
      ]);
      jikanData      = jikanRes.status === 'fulfilled' ? jikanRes.value : null;
      bgmIdFromJikan = bgmIdRes.status === 'fulfilled' ? bgmIdRes.value : null;
    }

    // 用準確的 bgmId 拉取 Bangumi 資料
    const bgmRes  = await Promise.allSettled([ findAndLoadBangumi(media, bgmIdFromJikan) ]);
    const bgmData = bgmRes[0].status === 'fulfilled' ? bgmRes[0].value : null;

    // 補強 Hero（Bangumi 中文名稱 + MAL 評分）
    if (bgmData) {
      const cnName = OpenCCHelper.convert(bgmData.nameCn || bgmData.name || '');
      if (cnName) {
        // 用繁體中文名稱更新 Hero 標題
        media.titleChinese = cnName;
        media.displayName  = cnName;
        setText('anime-title-main', cnName);
        setText('anime-title-bc', cnName.length > 20 ? cnName.slice(0, 20) + '…' : cnName);
      }
      // Bangumi 補充 director / author（若 AniList staff 無資料）
      if (!media.director && bgmData.director) media.director = OpenCCHelper.convert(bgmData.director);
      if (!media.author   && bgmData.source)   media.author   = OpenCCHelper.convert(bgmData.source);
    }
    enhanceHeroScores(media, jikanData, bgmData);

    // 再次充入 sidebar data（第二次更新，包含 Bangumi 補充後的 director/author）
    fillSidebarData(media);

    // 渲染 Meta Grid
    renderMeta(media, jikanData, bgmData);

    // 渲染劇情簡介（Bangumi 中文優先）
    renderSynopsis(media, bgmData);

    // 渲染串流平台（Jikan）
    renderPlatforms(media, jikanData);

    // 渲染台灣播出資訊卡（SEO: 在地化內容）
    // SEO: 台灣連結不加 nofollow，提升在地 SERP 表現
    renderTaiwanBroadcast(media, jikanData, bgmData);

    // 渲染外部連結分區（舊版 fallback）
    renderExternalLinks(media, jikanData, bgmData);

    // 渲染精簡外部連結（新版：Wikipedia / 官方 / Twitter / TikTok）
    renderSimpleExternalLinks(media, jikanData);

    // 渲染 STAFF
    renderStaff(media, jikanData, bgmData);

    // 動態 SEO（updateSeoMeta + injectSchemaJsonLd 含 FAQ）
    updatePageSEO(media, bgmData);
    updateSeoMeta(media, bgmData);
    injectSchemaJsonLd(media, bgmData, jikanData);

    /* ── Step 3：角色（Jikan 主 + Bangumi 中文名稱；fallback 用 AniList characters）── */
    if (malId) {
      const bgmCharData = bgmData?.bgmId ? await BangumiAPI.getCharacters(bgmData.bgmId).catch(() => []) : [];
      JikanAPI.getCharacters(malId)
        .then(chars => renderCharacters(chars, bgmCharData))
        .catch(() => {
          // Jikan 失敗：使用 AniList charactersRaw 作為 fallback
          const anilistChars = (media.charactersRaw || []).map(c => ({
            name:   c.name, nameCn: c.nameJp, nameJp: c.nameJp,
            image:  c.image, role: c.role,
            va:     c.va,    vaImg: c.vaImg,
          }));
          renderCharacters(anilistChars, bgmCharData);
        });
    } else if (media.charactersRaw?.length) {
      // 沒有 MAL ID，直接用 AniList 角色
      const anilistChars = (media.charactersRaw || []).map(c => ({
        name: c.name, nameCn: c.nameJp, nameJp: c.nameJp,
        image: c.image, role: c.role, va: c.va, vaImg: c.vaImg,
      }));
      renderCharacters(anilistChars, []);
    }

    /* ── Step 4：關聯作品（Bangumi）── */
    if (bgmData?.bgmId) {
      BangumiAPI.getRelations(bgmData.bgmId).then(rels => renderRelations(rels)).catch(() => {});
    }

    /* ── Step 5：集數列表（Bangumi）── */
    if (bgmData?.bgmId) {
      BangumiAPI.getEpisodes(bgmData.bgmId).then(eps => renderEpisodes(eps)).catch(() => {});
    }

    /* ── Step 6：社群統計 ── */
    renderCommunityStats(media, jikanData);

    /* ── Step 7：AnimeThemes OP/ED ── */
    if (malId) tryFetchAnimeThemes(malId, media.titleNative || media.titleRomaji);

    /* ── Step 8：PV Trailer（AniList trailer）── */
    renderPV(media, jikanData);

  } catch (err) {
    console.error('loadAnimePage error:', err);
    showError('載入失敗，請檢查網路連線後重試。');
  }
}

/* 橋接 Bangumi：
   Step 1 (最精準) — 直接使用已從 Jikan external 取得的 bgmId
   Step 2 (後備)   — 標題搜尋 + 嚴格標題相似度比對
*/
async function findAndLoadBangumi(media, knownBgmId = null) {
  /* ── Step 1：已知 bgmId，直接查詢（100% 精準）── */
  if (knownBgmId) {
    try {
      const subject = await BangumiAPI.getSubjectById(knownBgmId);
      if (subject) return { bgmId: knownBgmId, ...subject };
    } catch { /* 繼續 fallback */ }
  }

  /* ── Step 2：標題搜尋 fallback（用於沒有 MAL ID 的作品）── */
  const candidates = [
    media.titleNative,
    media.titleRomaji,
    media.titleEnglish,
  ].filter((t, i, arr) => t && arr.indexOf(t) === i);

  for (const title of candidates) {
    try {
      const results = await BangumiAPI.searchByTitle(title, 5);
      if (!results.length) continue;

      // 嚴格比對標題，避免拿到錯誤作品
      const matched = results.filter(r => isTitleMatch(title, r.name, r.nameCn));
      if (!matched.length) continue;

      for (const r of matched) {
        try {
          const subject = await BangumiAPI.getSubjectById(r.id);
          if (subject?.summary && subject.summary.length > 20) {
            return { bgmId: r.id, ...subject };
          }
        } catch { /* 跳過 */ }
      }
    } catch { /* 繼續下一個標題 */ }
  }
  return null;
}

/* isTitleMatch / charOverlap 已移至 api.js 全域定義，此處不重複宣告 */


/* ============================================================
   RENDER HERO（AniList 資料，立即渲染）
   ============================================================ */
function renderHeroFromAniList(media) {
  // Banner 背景（優先用 bannerImage，其次封面模糊）
  const heroBg = document.getElementById('anime-hero-bg');
  if (heroBg) {
    if (media.bannerImage) {
      heroBg.style.backgroundImage = `url('${media.bannerImage}')`;
      heroBg.classList.add('has-banner');
    } else if (media.coverLarge) {
      heroBg.style.backgroundImage = `url('${media.coverLarge}')`;
    }
  }

  // 主色調套用到 Hero（accent glow）
  if (media.coverColor) {
    const hero = document.getElementById('anime-hero');
    if (hero) hero.style.setProperty('--hero-accent', media.coverColor);
    document.documentElement.style.setProperty('--hero-accent', media.coverColor);
  }

  // 封面海報
  const posterEl = document.getElementById('anime-poster');
  if (posterEl) {
    posterEl.src = media.coverLarge;
    posterEl.alt = media.displayName;
    posterEl.onerror = () => { posterEl.style.display = 'none'; };
    posterEl.onload  = () => {
      document.getElementById('hero-poster-skeleton')?.remove();
      posterEl.classList.add('loaded');
    };
  }

  // MAL / AniList / Bangumi / 官方 / Twitter 連結
  const malLink = document.getElementById('mal-link');
  if (malLink && media.idMal) malLink.href = `https://myanimelist.net/anime/${media.idMal}`;
  const alLink = document.getElementById('al-link');
  if (alLink && media.siteUrl) alLink.href = media.siteUrl;

  // 官方網站（externalLinks type=OFFICIAL）
  const officialEl = document.getElementById('official-link');
  if (officialEl) {
    const officialLink = (media.externalLinks || []).find(l =>
      l.type === 'OFFICIAL' || (l.site || '').toLowerCase().includes('official')
    );
    if (officialLink?.url) {
      officialEl.href = officialLink.url;
      officialEl.style.display = '';
    }
  }

  // Twitter / X 連結
  const twitterEl = document.getElementById('twitter-link');
  if (twitterEl) {
    const twitterLink = (media.externalLinks || []).find(l =>
      l.site === 'Twitter' || l.site === 'X'
    );
    if (twitterLink?.url) {
      twitterEl.href = twitterLink.url;
      twitterEl.style.display = '';
    }
  }

  // 副標題（原作名稱）
  // 若 titleNative 為日文（含平假名/片假名/漢字），顯示日文原名；
  // 若為羅馬字或英文（非日文），則顯示英文標題作為副標，
  // 若主標已是英文且副標也是英文則隱藏副標。
  const rawJaTitle = (typeof getJaTitle === 'function') ? getJaTitle(media) : (media.titleNative || '');
  const isJapaneseScript = /[\u3040-\u30ff\u3400-\u4dbf\u4e00-\u9fff]/.test(rawJaTitle);
  const altTitle = isJapaneseScript
    ? rawJaTitle  // 是日文→顯示日文
    : (media.titleEnglish && media.titleEnglish !== rawJaTitle ? media.titleEnglish : rawJaTitle);

  const jpEl = document.getElementById('anime-title-jp');
  if (jpEl) {
    if (altTitle) {
      jpEl.textContent = altTitle;
      jpEl.setAttribute('lang', isJapaneseScript ? 'ja' : 'en');
      jpEl.setAttribute('itemprop', 'alternateName');
      jpEl.style.display = '';
    } else {
      jpEl.textContent = '';
      jpEl.style.display = 'none';
    }
  }
  const jaTitle = altTitle; // 供後續引用

  // 主標題（繁中優先 → getDisplayTitle）
  const displayTitle = (typeof getDisplayTitle === 'function')
    ? getDisplayTitle(media)
    : (media.titleChinese || media.displayName || media.titleRomaji || '未知作品');
  const titleMain = document.getElementById('anime-title-main');
  if (titleMain) {
    titleMain.textContent = displayTitle;
    titleMain.setAttribute('lang', 'zh-TW');
  }
  setText('anime-title-bc', truncate(displayTitle, 20));

  // Tags
  const tagsEl = document.getElementById('anime-tags');
  if (tagsEl) {
    const statusZh = {
      FINISHED:         '已完結',
      RELEASING:        '播出中',
      NOT_YET_AIRED:    '即將播出',
      NOT_YET_RELEASED: '即將播出',
      CANCELLED:        '已中止',
      HIATUS:           '暫停播映',
    };
    const statusTxt = statusZh[media.status] || '';
    const statusDot = media.status === 'RELEASING'        ? '🟢 '
                    : media.status === 'NOT_YET_AIRED'    ? '🔵 '
                    : media.status === 'NOT_YET_RELEASED' ? '🔵 '
                    : '⚫ ';
    tagsEl.innerHTML = `
      ${statusTxt ? `<span class="chip">${statusDot}${statusTxt}</span>` : ''}
      ${media.seasonYear ? `<span class="chip"><i class="fa-solid fa-calendar-days"></i> ${media.seasonYear}</span>` : ''}
      ${media.episodes   ? `<span class="chip"><i class="fa-solid fa-film"></i> ${media.episodes} 集</span>` : ''}
      ${(media.genres || []).slice(0, 3).map(g => `<span class="chip tag-genre">${g}</span>`).join('')}
    `;
  }

  // Hero Stats（AniList 評分，後續會補 MAL）
  renderHeroStats(media, null, null);

  // 還原 watchlist / collect 狀態
  restoreUserState(anilistIdFromUrl());
}

function renderHeroStats(media, jikanData, bgmData) {
  const statsEl = document.getElementById('anime-hero-stats');
  if (!statsEl) return;

  const alScore  = AniListAPI.formatAniListScore(media.averageScore);
  const malScore = jikanData ? JikanAPI.formatScore(jikanData.score) : null;
  const bgmScore = bgmData   ? BangumiAPI.formatScore(bgmData.score) : null;

  statsEl.innerHTML = `
    ${alScore !== '–' ? `
      <div class="hero-stat-item">
        <i class="fa-solid fa-star" style="color:#02A9FF;"></i>
        <span class="val">AL ${alScore}</span>
        ${media.popularity ? `<span>(${JikanAPI.formatCount(media.popularity)} 人氣)</span>` : ''}
      </div>` : ''}
    ${malScore && malScore !== '–' ? `
      <div class="hero-stat-item">
        <i class="fa-solid fa-star" style="color:#FFD580;"></i>
        <span class="val">MAL ${malScore}</span>
        ${jikanData?.members ? `<span>(${JikanAPI.formatCount(jikanData.members)} 收藏)</span>` : ''}
      </div>` : ''}
    ${bgmScore && bgmScore !== '–' ? `
      <div class="hero-stat-item">
        <i class="fa-solid fa-star" style="color:#F25D8E;"></i>
        <span class="val">BGM ${bgmScore}</span>
      </div>` : ''}
    ${(media.studios || [])[0] ? `
      <div class="hero-stat-item">
        <i class="fa-solid fa-building"></i>
        <span class="val">${escapeHtml((media.studios || [])[0])}</span>
      </div>` : ''}
  `;
}

function enhanceHeroScores(media, jikanData, bgmData) {
  renderHeroStats(media, jikanData, bgmData);
}


/* ============================================================
   AIRING COUNTDOWN（下集播出倒數）
   ============================================================ */
function renderAiringCountdown(nextAiring) {
  const el = document.getElementById('airing-countdown');
  if (!el) return;
  if (!nextAiring) { el.style.display = 'none'; return; }

  el.style.display = 'flex';
  updateCountdown(nextAiring.airingAt, nextAiring.episode, el);

  // 每秒更新
  window._countdownTimer = setInterval(() => {
    updateCountdown(nextAiring.airingAt, nextAiring.episode, el);
  }, 1000);
}

function updateCountdown(airingAt, episode, el) {
  const diff = airingAt * 1000 - Date.now();
  if (diff <= 0) {
    el.innerHTML = `<i class="fa-solid fa-circle-check" style="color:var(--accent-cyan);"></i>
      第 ${episode} 集已播出！`;
    clearInterval(window._countdownTimer);
    return;
  }
  const d  = Math.floor(diff / 86400000);
  const h  = Math.floor((diff % 86400000) / 3600000);
  const m  = Math.floor((diff % 3600000)  / 60000);
  const s  = Math.floor((diff % 60000)    / 1000);
  el.innerHTML = `
    <i class="fa-solid fa-clock" style="color:var(--accent-cyan);"></i>
    第 <strong>${episode}</strong> 集距播出
    <span class="cd-block">${d}<small>天</small></span>
    <span class="cd-block">${String(h).padStart(2,'0')}<small>時</small></span>
    <span class="cd-block">${String(m).padStart(2,'0')}<small>分</small></span>
    <span class="cd-block">${String(s).padStart(2,'0')}<small>秒</small></span>
  `;
}


/* ============================================================
   RENDER PROGRESS
   ============================================================ */
function renderProgress(info) {
  const total   = info.eps || 0;
  const key     = `${PROGRESS_KEY}_${info.id}`;
  const current = parseInt(localStorage.getItem(key) || '0');
  setText('progress-total', total || '?');
  updateProgressUI(current, total, info.id);
}

function updateProgressUI(current, total, id) {
  const pct = total > 0 ? Math.round((current / total) * 100) : 0;
  setText('progress-current', current);
  setText('progress-pct', pct + '%');
  const fill = document.getElementById('progress-bar-fill');
  if (fill) fill.style.width = pct + '%';
  const hint = document.getElementById('progress-hint');
  if (hint) {
    if (total > 0 && current < total)   hint.textContent = `還剩 ${total - current} 集 — 加油！`;
    else if (total > 0 && current >= total) hint.textContent = '🎉 已全破！';
    else hint.textContent = '';
  }
  const btn = document.getElementById('btn-complete');
  if (btn && total > 0) btn.style.display = current >= total ? 'none' : '';
}


/* ============================================================
   RENDER META GRID（包含 author / director / source data-field）
   ============================================================ */
function renderMeta(media, jikanData, bgmData) {
  const grid = document.getElementById('anime-meta-grid');
  if (!grid) return;

  const startDate = media.startDate || bgmData?.airDate || '–';
  const studio    = (media.studios || [])[0] || bgmData?.studio || '–';
  const duration  = media.duration ? `${media.duration} 分鐘` : bgmData?.duration || '–';
  const sourceZh  = translateSource(media.source || '') || bgmData?.source || '–';

  // 導演、原作者：AniList staff 優先，其次 Bangumi
  const director = media.director || (bgmData ? OpenCCHelper.convert(bgmData.director || '') : '') || '–';
  const author   = media.author   || (bgmData ? OpenCCHelper.convert(bgmData.source   || '') : '') || '–';
  const music    = bgmData ? OpenCCHelper.convert(bgmData.music || '') : '–';

  const malRank   = jikanData?.rank    ? `#${jikanData.rank}` : '–';
  const malScore  = jikanData?.score   ? JikanAPI.formatScore(jikanData.score) : '–';
  const malMember = jikanData?.members ? JikanAPI.formatCount(jikanData.members) : '–';
  const bgmScore  = bgmData?.score     ? BangumiAPI.formatScore(bgmData.score) : '–';

  // 播出日（weekday + time，來自 Jikan broadcast）
  let broadcastStr = '–';
  if (jikanData?.broadcast?.day && jikanData?.broadcast?.time) {
    const dayZh = {
      Mondays:'週一', Tuesdays:'週二', Wednesdays:'週三',
      Thursdays:'週四', Fridays:'週五', Saturdays:'週六', Sundays:'週日',
    };
    broadcastStr = `${dayZh[jikanData.broadcast.day] || jikanData.broadcast.day} ${jikanData.broadcast.time} JST`;
  }

  // 原作來源：翻譯為中文，不顯示日文原名
  // 若 AniList source 無資料，從 Bangumi infobox 的「原作」欄位取得
  let sourceDisplay = sourceZh;
  if ((!sourceDisplay || sourceDisplay === '–') && bgmData) {
    const bgmSrc = bgmData.source ? OpenCCHelper.convert(bgmData.source) : '';
    sourceDisplay = bgmSrc || '–';
  }
  // 若原作者為日文名（含日文字元）且有 Bangumi 中文原作者，優先顯示中文
  let authorDisplay = author;
  if (bgmData?.infobox) {
    const authorInfo = bgmData.infobox.find?.(i => i.key === '作者' || i.key === '原作');
    if (authorInfo?.value) authorDisplay = OpenCCHelper.convert(authorInfo.value);
  }

  // rows: 包含 data-field 供 fillSidebarData 更新
  const rows = [
    { icon:'fa-calendar-days',   label:'首播日期',   value: startDate },
    { icon:'fa-tv',              label:'類型',       value: bgmData?.platform || 'TV' },
    { icon:'fa-film',            label:'集數',       field:'episodes',
      value: media.episodes ? `${media.episodes} 集` : bgmData?.eps ? `${bgmData.eps} 集` : '–' },
    { icon:'fa-clock',           label:'每集長度',   field:'duration', value: duration },
    { icon:'fa-broadcast-tower', label:'播出時間',   value: broadcastStr },
    { icon:'fa-building',        label:'製作公司',   field:'studio',   value: studio },
    { icon:'fa-user-tie',        label:'導演',       field:'director', value: director },
    { icon:'fa-pen-nib',         label:'原作者',     field:'author',   value: authorDisplay },
    { icon:'fa-music',           label:'音樂',       value: music },
    { icon:'fa-book',            label:'原作來源',   field:'source',   value: sourceDisplay },
    { icon:'fa-star',            label:'評分',
      value: `AL ${AniListAPI.formatAniListScore(media.averageScore)} / MAL ${malScore} / BGM ${bgmScore}` },
    { icon:'fa-users',           label:'MAL 收藏',  value: malMember },
    { icon:'fa-arrow-up-right-from-square', label:'排名',
      value: `MAL ${malRank} &nbsp;|&nbsp; <a href="${media.siteUrl}" target="_blank" rel="noopener" style="color:var(--accent-blue);">AniList</a>`,
      html: true },
  ];

  grid.innerHTML = rows.map(r => `
    <div class="meta-item glass">
      <div class="meta-label">
        <i class="fa-solid ${r.icon}" style="color:var(--accent-blue);font-size:11px;"></i>
        ${r.label}
      </div>
      <div class="meta-value"${r.field ? ` data-field="${r.field}"` : ''}>${r.html ? r.value : escapeHtml(String(r.value))}</div>
    </div>`).join('');
}


/* ============================================================
   RENDER SYNOPSIS（Bangumi 中文優先 + OpenCC 轉繁）
   ============================================================ */
async function renderSynopsis(media, bgmData) {
  const textEl   = document.getElementById('synopsis-text');
  const sourceEl = document.getElementById('synopsis-source');
  if (!textEl) return;

  textEl.textContent = '載入中…';

  let text      = '';
  let sourceTxt = '';

  if (bgmData?.summary && bgmData.summary.trim().length > 10) {
    // 確保 OpenCC 初始化完成
    await OpenCCHelper.init();

    // Bangumi BBCode 全面清理
    const raw = bgmData.summary
      .replace(/\[img\][\s\S]*?\[\/img\]/gi, '')                        // 移除圖片
      .replace(/\[url=?[^\]]*\]([\s\S]*?)\[\/url\]/gi, '$1')            // url 保留文字
      .replace(/\[\/?(b|i|u|s|size|color|quote|code|spoiler)[^\]]*\]/gi, '') // 排版標記
      .replace(/\(From[^)]*\)/gi, '')                                    // 移除 (From xxx)
      .replace(/(?:Source|資料來源|来源)[:：][^\n]*/gi, '')              // 移除 Source 行
      .replace(/\n{3,}/g, '\n\n')
      .trim();

    text      = OpenCCHelper.convert(raw);
    sourceTxt = 'Bangumi';

  } else if (media.description && media.description.trim().length > 10) {
    // AniList 描述（清理 HTML 標籤 + HTML 實體）
    text = media.description
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<[^>]*>/g, '')
      .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"').replace(/&#039;/g, "'")
      .replace(/\n{3,}/g, '\n\n')
      .trim();
    sourceTxt = 'AniList（英文）';
  }

  if (!text) text = '暫無劇情介紹。';
  textEl.textContent = text;

  // 顯示資料來源標示
  if (sourceEl) {
    if (sourceTxt) {
      sourceEl.textContent = `資料來源：${sourceTxt}`;
      sourceEl.style.display = '';
    } else {
      sourceEl.style.display = 'none';
    }
  }

  const toggleBtn = document.getElementById('synopsis-toggle');
  if (toggleBtn) toggleBtn.style.display = text.length > 200 ? '' : 'none';
}

window.toggleSynopsis = function() {
  const textEl    = document.getElementById('synopsis-text');
  const toggleBtn = document.getElementById('synopsis-toggle');
  if (!textEl) return;
  const expanded = textEl.classList.toggle('expanded');
  if (toggleBtn) toggleBtn.innerHTML = expanded
    ? '收起 <i class="fa-solid fa-chevron-up"></i>'
    : '顯示更多 <i class="fa-solid fa-chevron-down"></i>';
};


/* ============================================================
   HANDLE UPCOMING STATE（status=NOT_YET_AIRED 切換顯示）
   ============================================================ */
function handleUpcomingState(media) {
  const isUpcoming = media.status === 'NOT_YET_AIRED' || media.status === 'NOT_YET_RELEASED';

  // 倒數計時横幅
  const banner = document.getElementById('upcoming-countdown-banner');
  if (banner) banner.style.display = isUpcoming ? 'block' : 'none';

  if (isUpcoming && media.startDate) {
    // 計算首播倒數
    const [y, m, d] = media.startDate.split('/').map(Number);
    const target = new Date(y, (m || 1) - 1, d || 1);
    updateUpcomingCountdown(target);
    window._upcomingTimer = setInterval(() => updateUpcomingCountdown(target), 1000);
  }

  // 展示 placeholder sections
  const phs = [
    'upcoming-themes-ph',
    'upcoming-platforms-ph',
    'upcoming-chars-ph',
    'upcoming-rating-ph',
  ];
  phs.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = isUpcoming ? 'block' : 'none';
  });

  // 未播出時降低某些 section 的顯示優先度
  if (isUpcoming) {
    // 隱藏主要角色骨架 placeholder（已播出時才顯示）
    const charsGrid = document.getElementById('chars-grid');
    if (charsGrid) charsGrid.style.display = 'none';
  }
}

function updateUpcomingCountdown(target) {
  const diff = target - Date.now();
  const dayEl  = document.getElementById('countdown-days');
  const timeEl = document.getElementById('countdown-time');
  if (!dayEl || !timeEl) return;

  if (diff <= 0) {
    clearInterval(window._upcomingTimer);
    dayEl.closest('.upcoming-countdown-banner') && (dayEl.closest('.upcoming-countdown-banner').style.display = 'none');
    return;
  }
  const d = Math.floor(diff / 86400000);
  const h = Math.floor((diff % 86400000) / 3600000);
  const m = Math.floor((diff % 3600000)  / 60000);
  const s = Math.floor((diff % 60000)    / 1000);

  dayEl.textContent = String(d);
  // countdown-time 儲存的 .countdown-num span
  const numEl = timeEl.querySelector('.countdown-num');
  if (numEl) {
    numEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  }
}


/* ============================================================
   RENDER EXTERNAL LINKS（官方 / 台灣串流 / 國際串流 / 台灣代理商）
   ============================================================ */
function renderExternalLinks(media, jikanData, bgmData) {
  const section = document.getElementById('external-links-section');
  if (!section) return;

  // 平台名稱 → 是否為台灣串流
  const TW_STREAM_SITES = new Set([
    '巴哈姆特動畫風', 'Bahamut', 'CatchPlay', 'LiTV', 'MyVideo', '中華電信', 'KKTV',
    'Friday Video', 'Hami Video', 'Vidol', 'CATCHPLAY+', 'friDay影音',
  ]);
  const INTL_STREAM_SITES = new Set([
    'Crunchyroll','Netflix','Hulu','Amazon Prime Video','Amazon','HIDIVE',
    'Funimation','Disney+','Disney Plus','Bilibili','iQIYI','iQiyi',
    'VRV','Wakanim','ADN','Muse Asia',
  ]);

  // 合並 AniList externalLinks + Jikan streaming
  const allLinks = [
    ...(media.externalLinks || []).map(l => ({ name: l.site, url: l.url, type: l.type, lang: l.language })),
    ...(jikanData?.streaming || []).map(s => ({ name: s.name, url: s.url, type: 'STREAMING', lang: null })),
    ...(jikanData?.external  || []).map(e => ({ name: e.name, url: e.url, type: 'INFO',      lang: null })),
  ];

  // 去重（同名稱只保留第一筆）
  const seen = new Set();
  const unique = allLinks.filter(l => { if (seen.has(l.name)) return false; seen.add(l.name); return true; });

  // 分區
  const official = unique.filter(l =>
    l.type === 'OFFICIAL' || l.name === 'Official Site' ||
    l.name.toLowerCase().includes('official') ||
    (l.type === 'INFO' && !INTL_STREAM_SITES.has(l.name) && !TW_STREAM_SITES.has(l.name))
  );
  const twStream = unique.filter(l => TW_STREAM_SITES.has(l.name));
  const intlStream = unique.filter(l =>
    INTL_STREAM_SITES.has(l.name) ||
    l.type === 'STREAMING' && !TW_STREAM_SITES.has(l.name)
  );

  // 臺灣代理商（從 Bangumi externalLinks 拉取，目前暂不對接，保留 fallback）
  // WP TODO: 可透過 ACF 'anime_tw_agency' 補充台灣代理商資訊
  const agencyEl       = document.getElementById('ext-agency-items');
  const agencyFallback = document.getElementById('ext-agency-fallback');
  // Bangumi externalLinks 目前 API 未提供台灣代理商資訊，保留 fallback

  // PLATFORM_ICONS 對照
  const PLATFORM_ICONS = {
    'Crunchyroll':       { icon:'fa-solid fa-play-circle',    color:'#F78B2D' },
    'Netflix':           { icon:'fa-brands fa-netflix',       color:'#E50914' },
    'Amazon':            { icon:'fa-brands fa-amazon',        color:'#FF9900' },
    'Amazon Prime Video':{ icon:'fa-brands fa-amazon',        color:'#00A8E0' },
    'HIDIVE':            { icon:'fa-solid fa-play-circle',    color:'#00BAFF' },
    'Funimation':        { icon:'fa-solid fa-play-circle',    color:'#410099' },
    'Disney Plus':       { icon:'fa-brands fa-disney',        color:'#113CCF' },
    'Disney+':           { icon:'fa-brands fa-disney',        color:'#113CCF' },
    'Hulu':              { icon:'fa-solid fa-play-circle',    color:'#1CE783' },
    'Bilibili':          { icon:'fa-solid fa-b',              color:'#00A1D6' },
    'iQiyi':             { icon:'fa-solid fa-play-circle',    color:'#00BE06' },
    'iQIYI':             { icon:'fa-solid fa-play-circle',    color:'#00BE06' },
    '巴哈姆特動畫風':     { icon:'fa-solid fa-dragon',         color:'#003F9F' },
    'KKTV':              { icon:'fa-solid fa-play-circle',    color:'#FF5500' },
    'CatchPlay':         { icon:'fa-solid fa-film',           color:'#E5004F' },
    'CATCHPLAY+':        { icon:'fa-solid fa-film',           color:'#E5004F' },
    'Muse Asia':         { icon:'fa-solid fa-play-circle',    color:'#CC0000' },
    'Official Site':     { icon:'fa-solid fa-globe',          color:'var(--accent-blue)' },
  };

  function renderLinkGroup(items, containerId, sectionId, isOfficial = false) {
    const container = document.getElementById(containerId);
    const sectionEl = document.getElementById(sectionId);
    if (!container || !items.length) return;
    if (sectionEl) sectionEl.style.display = '';

    container.innerHTML = items.map(l => {
      const cfg = PLATFORM_ICONS[l.name] || { icon:'fa-solid fa-arrow-up-right-from-square', color:'var(--text-muted)' };
      const cls = isOfficial ? 'official-site-btn' : 'external-link-btn';
      return `
        <a href="${l.url}" target="_blank" rel="noopener" class="${cls}">
          <i class="${cfg.icon}" style="color:${cfg.color};"></i>
          ${escapeHtml(l.name)}
        </a>`;
    }).join('');
  }

  const hasAny = official.length || twStream.length || intlStream.length;
  if (hasAny) {
    section.style.display = '';
    renderLinkGroup(official,  'ext-official-items', 'ext-group-official', true);
    renderLinkGroup(twStream,  'ext-tw-items',       'ext-group-tw');
    renderLinkGroup(intlStream,'ext-intl-items',     'ext-group-intl');
  } else {
    section.style.display = 'none';
  }
}


/* ============================================================
   DYNAMIC SEO UPDATE
   ============================================================ */
function updatePageSEO(media, bgmData) {
  // 繁中優先（getDisplayTitle），英文只作 SEO 用途
  const title   = (typeof getDisplayTitle === 'function') ? getDisplayTitle(media) : (media.displayName || media.titleRomaji || '動畫作品');
  const titleEn = (typeof getEnTitle === 'function') ? getEnTitle(media) : (media.titleEnglish || media.titleRomaji || '');
  const titleJp = (typeof getJaTitle === 'function') ? getJaTitle(media) : (media.titleNative || '');
  const studio  = (media.studios || [])[0] || '';
  const year    = media.seasonYear || '';
  const eps     = media.episodes ? `${media.episodes} 集` : '';
  const src     = translateSource(media.source || '');
  const score   = media.averageScore ? (media.averageScore / 10).toFixed(1) : '';
  const cover   = media.bannerImage || media.coverLarge || '';

  // 小於 150 字的動態 description
  const synopsisRaw = bgmData?.summary || media.description || '';
  const shortDesc   = synopsisRaw.replace(/\n/g, ' ').slice(0, 120).trim();
  const desc = shortDesc
    ? `${shortDesc}… |《${title}》${year ? ` ${year}年` : ''}播出動畫，將在微笑動漫追蹤。`
    : `《${title}》${year ? ` ${year}年` : ''}動畫詳情―劇情介紹、角色、聲優、OP/ED、合法觀看平台。`;

  // 关鍵字
  const kw = [title, titleJp, studio, year, src, '動畫', '微笑動漫']
    .filter(Boolean).join(',');

  const pageUrl = window.location.href;

  // 更新 DOM meta
  const setMeta = (id, attr, val) => {
    const el = document.getElementById(id);
    if (el && val) el.setAttribute(attr, val);
  };
  const setContent = (id, val) => setMeta(id, 'content', val);

  document.getElementById('page-title').textContent = `${title} — 微笑動漫`;
  setContent('meta-desc',  desc);
  setContent('meta-kw',    kw);
  setContent('og-title',   `${title} — 微笑動漫`);
  setContent('og-desc',    desc);
  setContent('og-image',   cover);
  setContent('og-url',     pageUrl);
  setContent('tw-title',   `${title} — 微笑動漫`);
  setContent('tw-desc',    desc);
  setContent('tw-image',   cover);

  const canonEl = document.getElementById('canonical-link');
  if (canonEl) canonEl.href = pageUrl;

  // JSON-LD TVSeries
  const tvSchema = document.getElementById('jsonld-tvseries');
  if (tvSchema) {
    const schema = {
      '@context': 'https://schema.org',
      '@type': 'TVSeries',
      'name': title,
      'alternativeHeadline': titleJp,
      'description': shortDesc || desc,
      'image': cover,
      'datePublished': media.startDate ? media.startDate.replace(/\//g, '-') : '',
      'numberOfEpisodes': media.episodes || undefined,
      'productionCompany': studio ? { '@type': 'Organization', 'name': studio } : undefined,
      'aggregateRating': media.averageScore ? {
        '@type': 'AggregateRating',
        'ratingValue': (media.averageScore / 10).toFixed(1),
        'bestRating': '10',
        'ratingCount': String(media.popularity || media.favourites || 0),
      } : undefined,
      'url': pageUrl,
    };
    // 對象欄位移除 undefined
    tvSchema.textContent = JSON.stringify(schema, (_, v) => v === undefined ? undefined : v, 2);
  }

  // JSON-LD BreadcrumbList
  const bcSchema = document.getElementById('jsonld-breadcrumb');
  if (bcSchema) {
    const bc = JSON.parse(bcSchema.textContent);
    bc.itemListElement[2].name = title;
    bc.itemListElement[2].item = pageUrl;
    bcSchema.textContent = JSON.stringify(bc, null, 2);
  }

  // FAQ Schema JSON-LD — 動態列數、接受答案
  const faqSchema = document.getElementById('jsonld-faq');
  if (faqSchema) {
    try {
      const faq = JSON.parse(faqSchema.textContent);
      // 集數問題
      if (eps) faq.mainEntity[1].acceptedAnswer.text = `《${title}》共 ${eps}，詳細集數請參閱本頁「作品資料」欄。`;
      // 原作類型
      if (src && src !== '—') faq.mainEntity[2].acceptedAnswer.text = `《${title}》原作類型為「${src}」。`;
      faqSchema.textContent = JSON.stringify(faq, null, 2);
    } catch (e) { console.warn('[SEO] FAQ schema update failed:', e); }
  }

  // FAQ HTML 已移除前端顯示，JSON-LD 仍保留供 SEO 用途

  // 最後資料更新時間（讓 Google 知道資料新鮮度）
  // SEO: 更新 <time datetime=""> 有助 Google 評估資料時效性
  const now = new Date();
  const nowStr = now.toISOString();
  const nowZhStr = `${now.getFullYear()} 年 ${now.getMonth()+1} 月 ${now.getDate()} 日`;
  const updateEls = document.querySelectorAll('#last-update-time, #footer-last-update');
  updateEls.forEach(el => {
    if (el) {
      el.textContent = nowZhStr;
      el.setAttribute('datetime', nowStr);
    }
  });

  // 編輯說明補充作品標題
  // SEO: 編輯說明強化 E-E-A-T Expertise 信號，提升 Google 對本站動漫資訊可信度的評估
  const editorNoteEl = document.getElementById('editor-note-text');
  if (editorNoteEl) {
    editorNoteEl.innerHTML = `本頁《<strong>${title}</strong>》資訊由微笑動漫編輯團隊整合自 AniList、Bangumi 番組計畫及 MyAnimeList 等三大主流動漫資料庫，並佐以官方公告交叉比對。如發現資料誤差，歡迎至下方討論區留言回報，我們將在 24 小時內確認並更新。`;
  }

  console.log(`[SEO] updatePageSEO 完成 ─ title: ${title}｜titleJp: ${titleJp}｜titleEn（SEO only）: ${titleEn}`);

  /* WP TODO: 對象擴充說明：
     - Rank Math 'rank_math_title' / 'rank_math_description' post meta 覆蓋頁面標題
     - WP slug 由 ACF 'anime_slug' 或 post_name 決定，不依賴 ?id= query
     - draft 同步：在 wp-cron 每小時比對 AniList status，若變更為 RELEASING 則自動發佈
     - 首播後 cron job：一週內一次拉取評分 / 人氣數，專載資料入 ACF
     - 綜合評分公式調整： scored_by 權重 < popularity 權重
     - api-sync.php 新增欄位：coffee_url / coffee_image_url / coffee_btn_text
     - single-anime.php: main title → get_field('weixiaoacg_title_zh'), subtitle → get_field('weixiaoacg_title_ja')
     - SEO meta: 英文標題只用於 og:title / twitter:title / JSON-LD alternateName，不顯示於前端 UI
  */
}


/* ============================================================
   RENDER TAIWAN BROADCAST CARD（台灣播出資訊卡）
   SEO: 台灣在地化資訊、台灣連結不加 nofollow
   WP TODO: 台灣代理商 → get_field('weixiaoacg_distributor_tw')
            台灣播出時間 → get_field('weixiaoacg_air_time_tw')
   ============================================================ */
function renderTaiwanBroadcast(media, jikanData, bgmData) {
  const section = document.getElementById('tw-broadcast-section');
  if (!section) return;

  // 台灣串流平台清單（不加 nofollow，台灣在地連結）
  const TW_PLATFORMS = {
    '巴哈姆特動畫瘋': { url: 'https://ani.gamer.com.tw', icon: '🎮', color: '#0078D4' },
    'friDay 影音':    { url: 'https://Friday.video',       icon: '📺', color: '#E50914' },
    'KKTV':           { url: 'https://www.kktv.me',         icon: '📱', color: '#6C35DE' },
    'myVideo':        { url: 'https://www.myvideo.net.tw',  icon: '📹', color: '#00B8D9' },
    'LiTV':           { url: 'https://www.litv.tv',         icon: '🌐', color: '#FF6600' },
  };

  // 從 AniList externalLinks 找台灣平台
  const externalLinks = media.externalLinks || [];
  const twPlatforms = [];

  // 一定加入巴哈姆特動畫瘋（台灣最大動漫串流）
  twPlatforms.push({ name:'巴哈姆特動畫瘋', url:'https://ani.gamer.com.tw', icon:'🎮', color:'#0078D4' });

  // 從外部連結找其他台灣平台
  externalLinks.forEach(link => {
    const name = link.site || '';
    if (name.includes('friDay') || name.includes('Friday')) {
      twPlatforms.push({ name: 'friDay 影音', url: link.url, icon: '📺', color: '#E50914' });
    } else if (name.includes('KKTV')) {
      twPlatforms.push({ name: 'KKTV', url: link.url, icon: '📱', color: '#6C35DE' });
    } else if (name.includes('myVideo')) {
      twPlatforms.push({ name: 'myVideo', url: link.url, icon: '📹', color: '#00B8D9' });
    }
  });

  // 渲染台灣播出時間（來自 AniList nextAiringEpisode，轉換為台灣時間）
  const twAirTimeEl = document.getElementById('tw-air-time');
  if (twAirTimeEl) {
    if (media.nextAiringEpisode) {
      const atTimestamp = media.nextAiringEpisode.airingAt * 1000;
      const twDate = new Date(atTimestamp);
      const twDateStr = twDate.toLocaleString('zh-TW', {
        timeZone: 'Asia/Taipei',
        month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
      twAirTimeEl.textContent = `${twDateStr}（台灣時間）第 ${media.nextAiringEpisode.episode} 集`;
    } else if (media.status === 'FINISHED') {
      twAirTimeEl.textContent = '本作已完結播出';
    } else {
      twAirTimeEl.textContent = '播出時間待確認';
    }
  }

  // 渲染台灣代理商
  const twDistEl = document.getElementById('tw-distributor');
  if (twDistEl) {
    // 從 bgmData 或 AniList 取得（WP 化後由 ACF 覆蓋）
    const dist = bgmData?.infobox?.find(i => i.key === '發行' || i.key === '代理商' || i.key === '台灣代理');
    if (dist?.value) {
      twDistEl.textContent = dist.value;
    } else {
      twDistEl.innerHTML = '<span style="color:var(--text-muted);font-size:12px;">待確認（歡迎在討論區補充）</span>';
    }
  }

  // 渲染台灣串流平台按鈕（台灣連結不加 nofollow）
  const twPlEl = document.getElementById('tw-platforms');
  if (twPlEl) {
    twPlEl.innerHTML = twPlatforms.map(p => `
      <a href="${p.url}" target="_blank" rel="noopener"
         class="tw-platform-btn"
         style="background:${p.color}18;border-color:${p.color}44;color:${p.color};"
         title="${p.name} 觀看合法動漫">
        ${p.icon} ${p.name}
      </a>
    `).join('');
  }

  // 顯示台灣播出資訊區塊
  section.style.display = '';
  console.log('[SEO] 台灣播出資訊卡已渲染，共', twPlatforms.length, '個平台');
}


/* ============================================================
   RENDER PLATFORMS（Jikan streaming + AniList external）
   ============================================================ */
function renderPlatforms(media, jikanData) {
  const grid = document.getElementById('platforms-grid');
  if (!grid) return;

  const PLATFORM_ICONS = {
    'Crunchyroll':  { icon:'fa-play-circle', color:'#F78B2D' },
    'Netflix':      { icon:'fa-brands fa-netflix', color:'#E50914' },
    'Amazon':       { icon:'fa-brands fa-amazon', color:'#FF9900' },
    'HIDIVE':       { icon:'fa-play-circle', color:'#00BAFF' },
    'Funimation':   { icon:'fa-play-circle', color:'#410099' },
    'Disney Plus':  { icon:'fa-brands fa-disney', color:'#113CCF' },
    'Hulu':         { icon:'fa-play-circle', color:'#1CE783' },
    'Bilibili':     { icon:'fa-b', color:'#00A1D6' },
    'iQiyi':        { icon:'fa-play-circle', color:'#00BE06' },
  };

  // 合併 Jikan streaming + AniList externalLinks（過濾串流平台）
  const streamSites = new Set(['Crunchyroll','Netflix','Hulu','Amazon','HIDIVE','Funimation','Disney Plus']);
  let platforms = (jikanData?.streaming || []).map(s => ({ name: s.name, url: s.url }));

  if (platforms.length === 0) {
    // fallback：AniList externalLinks
    platforms = (media.externalLinks || [])
      .filter(l => streamSites.has(l.site))
      .map(l => ({ name: l.site, url: l.url }));
  }

  // 台灣在地平台（一定加入巴哈姆特，再比對外部連結加入其他）
  const TW_PLATFORMS_MAP = {
    'fri':'friDay 影音',
    'friday':'friDay 影音',
    'kktv':'KKTV',
    'myvideo':'myVideo',
    'litv':'LiTV',
    'catchplay':'CatchPlay+',
    'hamivideo':'Hami Video',
    'vidol':'Vidol',
  };

  // 從 AniList externalLinks 找台灣平台
  (media.externalLinks || []).forEach(link => {
    const siteL = (link.site || '').toLowerCase();
    const urlL  = (link.url  || '').toLowerCase();
    Object.entries(TW_PLATFORMS_MAP).forEach(([key, name]) => {
      if ((siteL.includes(key) || urlL.includes(key)) &&
          !platforms.some(p => p.name === name)) {
        platforms.push({ name, url: link.url, region:'台灣' });
      }
    });
  });

  // 一定加入巴哈姆特
  const hasBaha = platforms.some(p => p.name.includes('Baha') || p.name.includes('巴哈'));
  if (!hasBaha) platforms.unshift({ name:'巴哈姆特動畫瘋', url:'https://ani.gamer.com.tw', region:'台灣' });

  if (platforms.length === 0) return; // 沒資料就維持預設 HTML

  // 區分台灣 vs 國際平台
  const twPlatformNames = new Set(['巴哈姆特動畫瘋','friDay 影音','KKTV','myVideo','LiTV','CatchPlay+','Hami Video','Vidol']);
  const twPlatforms_   = platforms.filter(p => twPlatformNames.has(p.name) || p.region === '台灣');
  const intlPlatforms_ = platforms.filter(p => !twPlatformNames.has(p.name) && p.region !== '台灣');

  const PLATFORM_ICONS_FULL = {
    ...PLATFORM_ICONS,
    '巴哈姆特動畫瘋': { icon:'fa-solid fa-dragon', color:'#0078D4' },
    'friDay 影音':    { icon:'fa-solid fa-play-circle', color:'#E50914' },
    'KKTV':           { icon:'fa-solid fa-play-circle', color:'#6C35DE' },
    'myVideo':        { icon:'fa-solid fa-play-circle', color:'#00B8D9' },
    'LiTV':           { icon:'fa-solid fa-play-circle', color:'#FF6600' },
    'CatchPlay+':     { icon:'fa-solid fa-film', color:'#E5004F' },
    'Hami Video':     { icon:'fa-solid fa-play-circle', color:'#00B4D8' },
    'Vidol':          { icon:'fa-solid fa-play-circle', color:'#E8175D' },
  };

  function mkPlatformCards(list) {
    return list.map(p => {
      const cfg       = PLATFORM_ICONS_FULL[p.name] || { icon:'fa-solid fa-play-circle', color:'var(--accent-blue)' };
      const iconClass = cfg.icon.startsWith('fa-brands') ? cfg.icon : cfg.icon;
      return `
        <a href="${p.url}" target="_blank" rel="noopener" class="platform-card glass">
          <i class="${iconClass} platform-icon" style="color:${cfg.color};font-size:18px;"></i>
          <div>
            <div class="platform-name">${escapeHtml(p.name)}</div>
            <div class="platform-region">${escapeHtml(p.region || '線上觀看')}</div>
          </div>
          <span class="platform-status status-airing">上架中</span>
          <i class="fa-solid fa-arrow-up-right-from-square" style="color:var(--text-muted);margin-left:auto;font-size:11px;"></i>
        </a>`;
    }).join('');
  }

  let html = '';
  if (twPlatforms_.length) {
    html += `<div class="platforms-group-label"><i class="fa-solid fa-flag" style="color:var(--accent-pink);"></i> 🇹🇼 台灣</div>`;
    html += mkPlatformCards(twPlatforms_);
  }
  if (intlPlatforms_.length) {
    html += `<div class="platforms-group-label" style="margin-top:12px;"><i class="fa-solid fa-earth-asia" style="color:var(--accent-blue);"></i> 國際</div>`;
    html += mkPlatformCards(intlPlatforms_);
  }
  grid.innerHTML = html;
}


/* ============================================================
   RENDER PV TRAILER（手動 PV 列表 + AniList trailer fallback）
   版面說明：PV 列表由 #pv-list 中的 .pv-item[data-yt] 定義（人工填入）
   首個 .pv-item.active 自動載入，點擊其他項目切換
   ============================================================ */
function renderPV(media, jikanData) {
  const section = document.getElementById('pv-section');
  if (!section) return;

  const iframe  = document.getElementById('pv-iframe');
  const pvList  = document.getElementById('pv-list');

  // 收集所有手動 PV 項目
  const pvItems = pvList ? Array.from(pvList.querySelectorAll('.pv-item[data-yt]')) : [];

  if (pvItems.length === 0) {
    // 無手動 PV → 嘗試 AniList trailer
    if (media.trailer?.site === 'youtube' && media.trailer?.id) {
      // 自動建立一個 PV 項目
      if (pvList) {
        pvList.innerHTML = `
          <div class="pv-item active" data-yt="${media.trailer.id}" onclick="switchPV(this)">
            <i class="fa-brands fa-youtube" style="color:#FF4444;"></i>
            <span>PV</span>
          </div>`;
        pvItems.push(pvList.querySelector('.pv-item'));
      }
    } else if (jikanData?.trailerEmbedUrl) {
      if (iframe) iframe.src = jikanData.trailerEmbedUrl;
      section.style.display = '';
      return;
    } else {
      section.style.display = 'none';
      return;
    }
  }

  // 載入第一個 active 項目
  const firstActive = pvList?.querySelector('.pv-item.active') || pvItems[0];
  if (firstActive && iframe) {
    const ytId = firstActive.dataset.yt;
    if (ytId) iframe.src = `https://www.youtube.com/embed/${ytId}?rel=0&modestbranding=1`;
    firstActive.classList.add('active');
  }

  section.style.display = '';
}

/* PV 切換（全域，供 onclick 呼叫）*/
window.switchPV = function(el) {
  const pvList = document.getElementById('pv-list');
  const iframe  = document.getElementById('pv-iframe');
  if (!iframe || !el) return;
  // 移除所有 active
  pvList?.querySelectorAll('.pv-item').forEach(i => i.classList.remove('active'));
  el.classList.add('active');
  const ytId = el.dataset.yt;
  if (ytId) iframe.src = `https://www.youtube.com/embed/${ytId}?rel=0&modestbranding=1`;
};


/* ============================================================
   RENDER STAFF（製作人員）
   資料來源：Bangumi infobox（中文優先） > Jikan staff > AniList
   ============================================================ */
function renderStaff(media, jikanData, bgmData) {
  const section = document.getElementById('staff-section');
  const grid    = document.getElementById('staff-grid');
  if (!section || !grid) return;

  const staffList = [];

  const ROLE_ZH = {
    'Director':'導演', 'Series Director':'系列導演', 'Chief Director':'總導演',
    'Original Creator':'原作', 'Script':'腳本', 'Music':'音樂',
    'Character Design':'人物設定', 'Sound Director':'音響監督',
    'Animation Director':'作畫監督', 'Art Director':'美術監督',
    'Storyboard':'分鏡', 'Episode Director':'分鏡導演',
  };

  // Step 1：優先從 Bangumi infobox 取得中文 STAFF
  if (bgmData?.infobox?.length) {
    const BGM_ROLE_MAP = {
      '導演':'導演', '監督':'監督', '系列構成':'系列構成', '腳本':'腳本',
      '音樂':'音樂', '人物原案':'人物原案', '人物設計':'人物設計',
      '總監督':'總監督', '音響監督':'音響監督', '美術監督':'美術監督',
      '原作':'原作', '啟動':'導演', '導演（全部）':'導演',
    };
    bgmData.infobox.forEach(item => {
      const roleZh = BGM_ROLE_MAP[item.key];
      if (!roleZh) return;
      const rawVal = Array.isArray(item.value)
        ? item.value.map(v => v.v || v).join('/')
        : (item.value || '');
      const nameZh = OpenCCHelper.convert(String(rawVal).trim());
      if (nameZh && nameZh !== '–') {
        staffList.push({ roleZh, name: nameZh, fromBgm: true });
      }
    });
  }

  // Step 2：补充 Bangumi director/music 未被 infobox 涵蓋的部分
  if (bgmData) {
    const alreadyHasDir = staffList.some(s => s.roleZh === '導演');
    const alreadyHasMus = staffList.some(s => s.roleZh === '音樂');
    if (!alreadyHasDir && bgmData.director) {
      staffList.push({ roleZh:'導演', name: OpenCCHelper.convert(bgmData.director), fromBgm: true });
    }
    if (!alreadyHasMus && bgmData.music) {
      staffList.push({ roleZh:'音樂', name: OpenCCHelper.convert(bgmData.music), fromBgm: true });
    }
  }

  // Step 3：若 Bangumi 無資料，從 Jikan staff 取得
  if (staffList.length === 0 && jikanData?.staff?.length) {
    const MAIN_ROLES = ['Director','Original Creator','Series Director','Chief Director','Script','Music','Character Design','Sound Director','Animation Director','Art Director'];
    jikanData.staff.forEach(s => {
      const roles = (s.positions || []).filter(r => MAIN_ROLES.some(mr => r.includes(mr)));
      if (!roles.length) return;
      roles.forEach(role => {
        const roleZh = ROLE_ZH[role] || role;
        staffList.push({
          roleZh,
          name: s.person?.name || '',
          nameJa: '',
        });
      });
    });
  }

  // Step 4：AniList director/author 作為最後後備
  if (staffList.length === 0) {
    if (media.director) staffList.push({ roleZh:'導演', name: media.director });
    if (media.author)   staffList.push({ roleZh:'原作', name: media.author });
  }

  if (!staffList.length) return;

  section.style.display = '';
  grid.innerHTML = staffList.map(s => `
    <div class="staff-item">
      <span class="staff-role">${escapeHtml(s.roleZh)}</span>
      <div>
        <span class="staff-name">${escapeHtml(s.name)}</span>
        ${s.nameJa ? `<span class="staff-name-ja">${escapeHtml(s.nameJa)}</span>` : ''}
      </div>
    </div>
  `).join('');
}

/* ============================================================
   RENDER SIMPLE EXTERNAL LINKS（精簡外部連結）
   只顯示：Wikipedia / 官方網站 / 官方 Twitter(X) / 官方 TikTok
   ============================================================ */
function renderSimpleExternalLinks(media, jikanData) {
  const section = document.getElementById('ext-links-simple-section');
  const wrap    = document.getElementById('ext-simple-wrap');
  if (!section || !wrap) return;

  const links = [];

  const exLinks = media.externalLinks || [];

  // 官方網站
  const official = exLinks.find(l =>
    l.type === 'OFFICIAL' || l.site === 'Official Site' ||
    (l.site || '').toLowerCase().includes('official')
  );
  if (official?.url) links.push({ icon:'fa-solid fa-globe', label:'官方網站', url: official.url, color:'var(--accent-blue)' });

  // Twitter / X
  const twitter = exLinks.find(l => l.site === 'Twitter' || l.site === 'X');
  if (twitter?.url) links.push({ icon:'fa-brands fa-x-twitter', label:'官方 X', url: twitter.url, color:'#fff' });

  // TikTok / 抖音（官方抖音 / TikTok）
  const tiktok = exLinks.find(l =>
    l.site === 'TikTok' || l.site === '抖音' ||
    (l.site||'').toLowerCase().includes('tiktok') ||
    (l.url||'').includes('tiktok') ||
    (l.url||'').includes('douyin')
  );
  if (tiktok?.url) links.push({ icon:'fa-brands fa-tiktok', label:'官方抖音', url: tiktok.url, color:'#ff0050' });

  // Wikipedia（從 jikan external 找）
  const wiki = (jikanData?.external || []).find(e => (e.name||'').toLowerCase().includes('wikipedia') || (e.url||'').includes('wikipedia'));
  if (wiki?.url) links.push({ icon:'fa-brands fa-wikipedia-w', label:'Wikipedia', url: wiki.url, color:'var(--text-secondary)' });
  // 備選：AniList externalLinks 中找 wikipedia
  if (!wiki) {
    const wikiAL = exLinks.find(l => (l.site||'').toLowerCase().includes('wikipedia') || (l.url||'').includes('wikipedia'));
    if (wikiAL?.url) links.push({ icon:'fa-brands fa-wikipedia-w', label:'Wikipedia', url: wikiAL.url, color:'var(--text-secondary)' });
  }

  if (!links.length) { section.style.display = 'none'; return; }

  section.style.display = '';
  wrap.innerHTML = links.map(l => `
    <a href="${escapeHtml(l.url)}" target="_blank" rel="noopener" class="ext-simple-btn">
      <i class="${l.icon}" style="color:${l.color};"></i>
      ${escapeHtml(l.label)}
    </a>
  `).join('');
}

/* ============================================================
   RENDER CHARACTERS（Jikan）— 2:3 矩形卡片設計（移至 CAST 區塊）
   名稱優先順序：Bangumi 繁中 > AniList preferred > 英文
   ============================================================ */
function renderCharacters(chars, bgmChars) {
  const grid = document.getElementById('chars-grid');
  if (!grid) return;

  // 未播出時展示 placeholder
  const isUpcoming = window._currentAnimeMedia?.status === 'NOT_YET_AIRED' ||
                     window._currentAnimeMedia?.status === 'NOT_YET_RELEASED';
  if (isUpcoming) return; // placeholder 已透過 handleUpcomingState 顯示

  grid.style.display = ''; // 恢復顯示

  if (!chars?.length) {
    grid.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:12px 0;grid-column:1/-1;">暫無角色資料</div>`;
    return;
  }

  // 建立 Bangumi 角色中文名稱對照（多鍵對照，提高匹配率）
  // bgmChars: [{ id, nameCn, name, ... }]
  const bgmNameMap = {};
  const bgmNameMapByZh = {}; // 以日文名（bgm.name）為鍵
  if (bgmChars?.length) {
    bgmChars.forEach(bc => {
      // 以日文名為鍵（Bangumi 的 name 通常是日文）
      const jpKey = (bc.name || '').trim().toLowerCase();
      if (jpKey) {
        const zhVal = OpenCCHelper.convert(bc.nameCn || bc.name || '');
        bgmNameMap[jpKey] = zhVal;
        bgmNameMapByZh[jpKey] = zhVal;
      }
      // 同時以中文名為鍵
      if (bc.nameCn) {
        const cnKey = bc.nameCn.trim().toLowerCase();
        bgmNameMap[cnKey] = OpenCCHelper.convert(bc.nameCn);
      }
    });
  }

  grid.innerHTML = chars.slice(0, 12).map(c => {
    const isMain  = c.role === '主角';
    const mainCls = isMain ? ' is-main' : '';

    // 名稱優先：Bangumi 繁中 > Jikan 日文名 > 英文名（CAST 顯示中文）
    // c.name = Jikan 英文名（如 "Natsuki Subaru"）
    // c.nameJp = 日文名（如 "ナツキ・スバル"）
    // c.nameCn = Bangumi 中文名（若有）
    const jNameKey = (c.name || '').trim().toLowerCase();
    const jpNameKey = (c.nameJp || '').trim().toLowerCase();

    // 1. 優先用已知的 Bangumi 中文名
    // 2. 嘗試用日文名匹配 bgmNameMap
    // 3. 用英文名匹配 bgmNameMap
    // 4. 直接用 nameCn（已設定的中文名）
    const zhName = c.nameCn
      ? OpenCCHelper.convert(c.nameCn)
      : (bgmNameMap[jpNameKey] || bgmNameMap[jNameKey] || '');

    // 若無中文名，嘗試用日文名（比英文名更適合展示）
    const showName = zhName || c.nameJp || c.name || '—';
    // 副標：顯示日文原名（若主標不是日文）
    const isJapanese = /[\u3040-\u30ff\u3400-\u4dbf\u4e00-\u9fff]/.test(showName);
    const subName = isJapanese
      ? (c.name && showName !== c.name ? c.name : '') // 日文主標時副標顯示英文
      : (c.nameJp || ''); // 非日文主標時副標顯示日文

    // 聲優中文名（若 Bangumi 角色有聲優中文名，優先顯示）
    const vaDisplay = c.vaCn ? OpenCCHelper.convert(c.vaCn) : (c.va || '');

    const vaThumb = c.vaImg
      ? `<div class="char-va-thumb"><img src="${c.vaImg}" alt="${escapeHtml(vaDisplay)}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=\\"char-va-thumb-ph\\"><i class=\\"fa-solid fa-microphone\\"></i></div>';"></div>`
      : vaDisplay
        ? `<div class="char-va-thumb"><div class="char-va-thumb-ph"><i class="fa-solid fa-microphone"></i></div></div>`
        : '';

    return `
      <div class="character-card${mainCls}" title="${escapeHtml(showName)}${vaDisplay ? ' / CV: ' + escapeHtml(vaDisplay) : ''}">
        <div class="char-poster-wrap">
          ${c.image
            ? `<img class="char-poster" src="${c.image}" alt="${escapeHtml(showName)}" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling && (this.nextElementSibling.style.display='flex');" />`
            : ''}
          <div class="char-img-ph" style="${c.image ? 'display:none;' : ''}"><i class="fa-solid fa-user"></i></div>
          <span class="char-role-badge">${escapeHtml(c.role || '')}</span>
          ${vaThumb}
          <div class="char-overlay">
            <span class="char-name-zh">${escapeHtml(showName)}</span>
            ${subName ? `<span class="char-name-jp">${escapeHtml(subName)}</span>` : ''}
            ${vaDisplay ? `<span class="char-va-name"><i class="fa-solid fa-microphone" style="font-size:9px;opacity:0.7;"></i> ${escapeHtml(vaDisplay)}</span>` : ''}
          </div>
        </div>
      </div>`;
  }).join('');
}


/* ============================================================
   RENDER EPISODES（Bangumi 集數列表）
   ============================================================ */
function renderEpisodes(episodes) {
  const section = document.getElementById('episodes-section');
  const listEl  = document.getElementById('episodes-list');
  if (!section || !listEl || !episodes?.length) return;

  section.style.display = 'block';

  // 預設顯示前 3 集，點「顯示更多」才展開全部
  const SHOW_DEFAULT = 3;
  const showAll = episodes.length <= SHOW_DEFAULT || listEl.dataset.showAll === '1';
  const display = showAll ? episodes : episodes.slice(0, SHOW_DEFAULT); // 顯示前3集

  const progress = parseInt(localStorage.getItem(`${PROGRESS_KEY}_${anilistIdFromUrl()}`) || '0');

  listEl.innerHTML = display.map(e => {
    const watched = e.ep > 0 && e.ep <= progress;
    const title   = OpenCCHelper.convert(e.nameCn || e.name || `第 ${e.ep} 集`);
    return `
      <div class="ep-item glass${watched ? ' ep-watched' : ''}" onclick="toggleEpWatch(${e.ep}, this)">
        <div class="ep-num">${e.ep || '–'}</div>
        <div class="ep-info">
          <div class="ep-title">${escapeHtml(title)}</div>
          ${e.airDate ? `<div class="ep-date">${e.airDate}</div>` : ''}
        </div>
        <div class="ep-check">
          <i class="fa-solid ${watched ? 'fa-circle-check' : 'fa-circle'}" style="color:${watched ? 'var(--accent-cyan)' : 'var(--glass-border)'};"></i>
        </div>
      </div>`;
  }).join('');

  if (!showAll && episodes.length > SHOW_DEFAULT) {
    listEl.innerHTML += `
      <button class="themes-show-more" onclick="epShowMore()">
        <i class="fa-solid fa-chevron-down"></i> 顯示更多 / 全部（共 ${episodes.length} 集）
      </button>`;
  }

  // 儲存 episodes 供 toggle 用
  window._episodes = episodes;
}

window.toggleEpWatch = function(epNum, el) {
  const id  = anilistIdFromUrl();
  const key = `${PROGRESS_KEY}_${id}`;
  const anime = window._currentAnime;
  if (!anime) return;

  // 點擊集數 → 設定進度到該集
  const current = parseInt(localStorage.getItem(key) || '0');
  const newProgress = current >= epNum ? epNum - 1 : epNum;
  localStorage.setItem(key, Math.max(0, newProgress));
  updateProgressUI(Math.max(0, newProgress), anime.episodes, id);

  // 重新渲染集數列表
  if (window._episodes) renderEpisodes(window._episodes);
  showToast(newProgress >= epNum ? `已標記第 ${epNum} 集看完 ✓` : `已取消第 ${epNum} 集`, 'info');
};

window.epShowMore = function() {
  const listEl = document.getElementById('episodes-list');
  if (listEl) { listEl.dataset.showAll = '1'; if (window._episodes) renderEpisodes(window._episodes); }
};


/* ============================================================
   RENDER RELATIONS（Bangumi 關聯作品）
   ============================================================ */
function renderRelations(relations) {
  const listEl = document.getElementById('rec-list');
  if (!listEl) return;

  if (!relations?.length) {
    listEl.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:12px 0;">暫無關聯作品</div>`;
    return;
  }

  listEl.innerHTML = relations.map(r => {
    // rel.id 是 Bangumi ID，連結到 Bangumi 頁面
    const bgmUrl      = BangumiAPI.getBgmUrl(r.id);
    const displayName = OpenCCHelper.convert(r.nameCn || r.name || '未知');
    const RELATION_ZH = {
      'prequel': '前傳', 'sequel': '續傳', 'alternative': '其他版本',
      'spinoff': '外傳', 'summary': '總集', 'side story': '外傳',
      'parent story': '正傳', 'character': '重要角色',
      'other': '相關', '': '相關',
    };
    const relationZh = RELATION_ZH[(r.relation || '').toLowerCase()] || r.relation || '相關';
    return `
    <a class="rec-item" href="${bgmUrl}" target="_blank" rel="noopener">
      <img class="rec-thumb" src="${r.image}" alt="${escapeHtml(displayName)}" loading="lazy"
           onerror="this.src='https://via.placeholder.com/48x64/1E242B/7B8594?text=?'" />
      <div class="rec-info">
        <div class="rec-title">${escapeHtml(displayName)}</div>
        <div class="rec-score" style="font-size:11px;color:var(--text-muted);margin-top:2px;">
          <i class="fa-solid fa-link" style="font-size:9px;"></i> ${escapeHtml(relationZh)}
        </div>
      </div>
    </a>`;
  }).join('');

  // 更新側欄標題
  const title = document.querySelector('#rec-list')?.closest('.sidebar-card')?.querySelector('.sidebar-title');
  if (title) title.innerHTML = `<i class="fa-solid fa-link" style="color:var(--accent-violet);"></i> 關聯作品`;
}


/* ============================================================
   RENDER COMMUNITY STATS
   ============================================================ */
function renderCommunityStats(media, jikanData) {
  setText('comm-watching',  jikanData ? JikanAPI.formatCount(jikanData.members)    : JikanAPI.formatCount(media.popularity));
  setText('comm-completed', jikanData ? JikanAPI.formatCount(jikanData.favorites)  : '–');
  setText('comm-score',     jikanData ? JikanAPI.formatScore(jikanData.score)       : AniListAPI.formatAniListScore(media.averageScore));
}


/* ============================================================
   ANIMETHEMES OP/ED
   ============================================================ */
let _themes = [], _filteredThemes = [], _currentIdx = -1, _isPlaying = false;

async function tryFetchAnimeThemes(malId, fallbackName) {
  const section = document.getElementById('themes-section');
  const listEl  = document.getElementById('themes-list');
  if (!section || !listEl) return;

  section.style.display = 'block';
  listEl.innerHTML = `
    <div class="skeleton glass" style="height:62px;border-radius:var(--radius-lg);"></div>
    <div class="skeleton glass" style="height:62px;border-radius:var(--radius-lg);"></div>
    <div class="skeleton glass" style="height:62px;border-radius:var(--radius-lg);"></div>`;

  try {
    const url  = `https://api.animethemes.moe/anime?filter[has]=resources&filter[site]=MyAnimeList&filter[external_id]=${malId}&include=animethemes.animethemeentries.videos,animethemes.song.artists`;
    const res  = await fetch(url);
    if (!res.ok) throw new Error('AT ' + res.status);
    const json = await res.json();
    const entry = json.anime?.[0];
    if (!entry?.animethemes?.length) { section.style.display = 'none'; return; }
    processThemesData(entry);
  } catch {
    // 退而求其次用名稱搜尋
    try {
      const res2 = await fetch(`https://api.animethemes.moe/search?q=${encodeURIComponent(fallbackName || '')}&fields[search]=anime&include[anime]=animethemes.animethemeentries.videos,animethemes.song.artists`);
      if (!res2.ok) throw new Error();
      const j2  = await res2.json();
      const e2  = j2.search?.anime?.[0];
      if (e2?.animethemes?.length) { processThemesData(e2); return; }
    } catch {}
    section.style.display = 'none';
  }
}

function processThemesData(entry) {
  const animeSlug = entry.slug || '';
  _themes = (entry.animethemes || []).map(t => {
    let video = null;
    for (const e of (t.animethemeentries || [])) { const v = e.videos?.[0]; if (v?.basename) { video = v; break; } }
    if (!video) return null;
    return {
      type: t.type, slug: t.slug, animeSlug,
      title: t.song?.title || '未知曲目',
      artists: (t.song?.artists || []).map(a => a.name).join('、') || '未知歌手',
      episodes: t.animethemeentries?.[0]?.episodes || '',
      videoUrl: `https://v.animethemes.moe/${video.basename}`,
    };
  }).filter(Boolean);

  const section = document.getElementById('themes-section');
  if (!_themes.length) { if (section) section.style.display = 'none'; return; }
  if (section) section.style.display = 'block';
  _filteredThemes = [..._themes];
  renderThemesList(_filteredThemes);
  initThemesTabs();
  initPlayerControls();
}

function renderThemesList(themes) {
  const listEl = document.getElementById('themes-list');
  if (!listEl) return;
  if (!themes.length) {
    listEl.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:12px 0;text-align:center;"><i class="fa-solid fa-music" style="opacity:.3;font-size:24px;display:block;margin-bottom:8px;"></i>此類型暫無主題曲</div>`;
    return;
  }
  const LIMIT = 6;
  const showAll = themes.length <= LIMIT || listEl.dataset.showAll === '1';
  const display = showAll ? themes : themes.slice(0, LIMIT);
  listEl.innerHTML = display.map(t => {
    const idx  = _themes.indexOf(t);
    const playing = idx === _currentIdx && _isPlaying;
    return `
      <div class="theme-item glass${playing ? ' theme-item-playing' : ''}" data-idx="${idx}" onclick="playTheme(${idx})">
        <div class="theme-play-btn" id="theme-play-${idx}">
          <i class="fa-solid ${playing ? 'fa-pause' : 'fa-play'}"></i>
        </div>
        <div class="theme-info">
          <div class="theme-slug">
            <span class="theme-type-badge ${t.type==='OP'?'theme-type-op':'theme-type-ed'}">${t.type}</span>
            <span class="theme-slug-text">${escapeHtml(t.slug)}</span>
            ${t.episodes ? `<span class="theme-episodes">EP ${escapeHtml(t.episodes)}</span>` : ''}
          </div>
          <div class="theme-title">${escapeHtml(t.title)}</div>
          <div class="theme-artist"><i class="fa-solid fa-microphone-lines"></i> ${escapeHtml(t.artists)}</div>
        </div>
        <button class="theme-ext-btn"
          onclick="event.stopPropagation();window.open('https://animethemes.moe/anime/${encodeURIComponent(t.animeSlug||t.slug.toLowerCase())}','_blank')"
          title="AnimeThemes"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>
      </div>`;
  }).join('') + (!showAll && themes.length > LIMIT ? `
    <button class="themes-show-more" onclick="themesShowMore()">
      <i class="fa-solid fa-chevron-down"></i> 顯示更多（共 ${themes.length} 首）
    </button>` : '');
}

window.themesShowMore = function() {
  const el = document.getElementById('themes-list');
  if (el) { el.dataset.showAll='1'; renderThemesList(_filteredThemes); }
};

function initThemesTabs() {
  document.querySelectorAll('.themes-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.themes-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      const type = tab.dataset.type;
      _filteredThemes = type === 'all' ? [..._themes] : _themes.filter(t => t.type === type);
      const el = document.getElementById('themes-list');
      if (el) delete el.dataset.showAll;
      renderThemesList(_filteredThemes);
    });
  });
}

window.playTheme = function(idx) {
  if (idx < 0 || idx >= _themes.length) return;
  const t = _themes[idx];
  _currentIdx = idx;
  document.querySelectorAll('.theme-play-btn i').forEach(i => i.className = 'fa-solid fa-play');
  const bar = document.getElementById('now-playing-bar');
  if (bar) { bar.style.display = 'flex'; bar.classList.remove('playing'); }
  setText('now-playing-title', `${t.slug} — ${t.title}`);
  setText('now-playing-sub', t.artists);
  const video = document.getElementById('themes-video');
  if (!video) return;
  video.src = t.videoUrl; video.load();
  video.play().then(() => {
    _isPlaying = true; updatePlayIcon(true);
    if (bar) bar.classList.add('playing');
    const btn = document.getElementById(`theme-play-${idx}`);
    if (btn) btn.querySelector('i').className = 'fa-solid fa-pause';
    showToast(`▶ ${t.title}`, 'info');
  }).catch(() => showToast('播放失敗', 'info'));
};

function initPlayerControls() {
  const video = document.getElementById('themes-video');
  const play  = document.getElementById('np-play-pause');
  const prev  = document.getElementById('np-prev');
  const next  = document.getElementById('np-next');
  const close = document.getElementById('np-close');
  const track = document.getElementById('np-bar-track');
  if (!video) return;

  play?.addEventListener('click', () => {
    const bar = document.getElementById('now-playing-bar');
    if (_isPlaying) {
      video.pause(); _isPlaying = false; updatePlayIcon(false);
      bar?.classList.remove('playing');
      document.getElementById(`theme-play-${_currentIdx}`)?.querySelector('i').className && (document.getElementById(`theme-play-${_currentIdx}`).querySelector('i').className = 'fa-solid fa-play');
    } else {
      video.play(); _isPlaying = true; updatePlayIcon(true);
      bar?.classList.add('playing');
      document.getElementById(`theme-play-${_currentIdx}`)?.querySelector('i') && (document.getElementById(`theme-play-${_currentIdx}`).querySelector('i').className = 'fa-solid fa-pause');
    }
  });
  prev?.addEventListener('click', () => window.playTheme((_currentIdx - 1 + _themes.length) % _themes.length));
  next?.addEventListener('click', () => window.playTheme((_currentIdx + 1) % _themes.length));
  video.addEventListener('ended', () => window.playTheme((_currentIdx + 1) % _themes.length));
  video.addEventListener('timeupdate', () => {
    if (!video.duration) return;
    const pct = (video.currentTime / video.duration) * 100;
    const fill = document.getElementById('np-bar-fill');
    if (fill) fill.style.width = pct + '%';
    setText('np-current', formatTime(video.currentTime));
    setText('np-duration', formatTime(video.duration));
  });
  track?.addEventListener('click', e => {
    if (!video.duration) return;
    const r = track.getBoundingClientRect();
    video.currentTime = ((e.clientX - r.left) / r.width) * video.duration;
  });
  close?.addEventListener('click', () => {
    video.pause(); video.src=''; _isPlaying=false; _currentIdx=-1;
    const bar = document.getElementById('now-playing-bar');
    if (bar) { bar.style.display='none'; bar.classList.remove('playing'); }
    document.querySelectorAll('.theme-play-btn i').forEach(i => i.className='fa-solid fa-play');
    document.querySelectorAll('.theme-item-playing').forEach(el => el.classList.remove('theme-item-playing'));
  });
}

function updatePlayIcon(p) {
  const i = document.getElementById('np-play-icon');
  if (i) i.className = p ? 'fa-solid fa-pause' : 'fa-solid fa-play';
}
function formatTime(s) {
  if (isNaN(s)||s===Infinity) return '0:00';
  return `${Math.floor(s/60)}:${Math.floor(s%60).toString().padStart(2,'0')}`;
}


/* ============================================================
   PROGRESS CONTROLS
   ============================================================ */
window.incrementEp = function() {
  const a = window._currentAnime; if (!a) return;
  const key = `${PROGRESS_KEY}_${a.anilist_id}`;
  let cur = parseInt(localStorage.getItem(key)||'0');
  if (a.episodes > 0 && cur >= a.episodes) { showToast('已達最終集 🏆','info'); return; }
  cur++; localStorage.setItem(key, cur);
  updateProgressUI(cur, a.episodes, a.anilist_id);
  if (window._episodes) renderEpisodes(window._episodes);
  showToast(cur===1?'成就解鎖：追番先鋒 ⚡':`第 ${cur} 集 ✓`,'info');
};
window.decrementEp = function() {
  const a = window._currentAnime; if (!a) return;
  const key = `${PROGRESS_KEY}_${a.anilist_id}`;
  let cur = parseInt(localStorage.getItem(key)||'0');
  if (cur<=0) return; cur--;
  localStorage.setItem(key, cur);
  updateProgressUI(cur, a.episodes, a.anilist_id);
  if (window._episodes) renderEpisodes(window._episodes);
};
window.markComplete = function() {
  const a = window._currentAnime; if (!a) return;
  if (!a.episodes) { showToast('集數未知','info'); return; }
  const key = `${PROGRESS_KEY}_${a.anilist_id}`;
  localStorage.setItem(key, a.episodes);
  updateProgressUI(a.episodes, a.episodes, a.anilist_id);
  window.setStatus('completed');
  if (window._episodes) renderEpisodes(window._episodes);
  showToast('🏆 全破！+50 XP','success');
};


/* ============================================================
   WATCHLIST / COLLECT / SHARE
   ============================================================ */
window.setStatus = function(status) {
  const id = anilistIdFromUrl();
  const list = JSON.parse(localStorage.getItem(WATCHLIST_KEY)||'{}');
  list[id] = { status, updatedAt: Date.now() };
  localStorage.setItem(WATCHLIST_KEY, JSON.stringify(list));
  document.querySelectorAll('.action-btn').forEach(b => b.classList.remove('active'));
  const map = { watching:'btn-watching', wishlist:'btn-wishlist', completed:'btn-completed' };
  document.getElementById(map[status])?.classList.add('active');
  const labels = { watching:'已加入追番清單 📺', wishlist:'已加入想看清單 🔖', completed:'已標記看完 ✅' };
  showToast(labels[status]||'已更新','success');
};

window.toggleCollect = function() {
  const id  = anilistIdFromUrl();
  const key = `smile_collect_${id}`;
  const icon = document.getElementById('btn-collect')?.querySelector('i');
  if (localStorage.getItem(key)) {
    localStorage.removeItem(key);
    if (icon) icon.className = 'fa-regular fa-bookmark';
    showToast('已從收藏移除','info');
  } else {
    localStorage.setItem(key,'1');
    if (icon) icon.className = 'fa-solid fa-bookmark';
    showToast('已加入收藏牆 ⭐','success');
  }
};

window.shareAnime = function() {
  if (navigator.share) navigator.share({ title: document.title, url: location.href });
  else navigator.clipboard?.writeText(location.href).then(() => showToast('連結已複製 🔗','info'));
};

function restoreUserState(id) {
  const savedList = JSON.parse(localStorage.getItem(WATCHLIST_KEY)||'{}');
  const status    = savedList[id]?.status;
  if (status) {
    document.querySelectorAll('.action-btn').forEach(b => b.classList.remove('active'));
    const map = { watching:'btn-watching', wishlist:'btn-wishlist', completed:'btn-completed' };
    document.getElementById(map[status])?.classList.add('active');
  }
  if (localStorage.getItem(`smile_collect_${id}`)) {
    const btn = document.getElementById('btn-collect');
    if (btn) btn.querySelector('i').className = 'fa-solid fa-bookmark';
  }
}


/* ============================================================
   DISCUSSION
   ============================================================ */
function initDiscussionTabs() {
  document.querySelectorAll('.disc-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.disc-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
    });
  });
}

window.revealSpoiler = function(el) {
  el.querySelector('.spoiler-content')?.classList.add('revealed');
  el.querySelector('.spoiler-mask')?.classList.add('hidden');
  el.style.cursor = 'default';
};

window.submitComment = function() {
  const input = document.getElementById('comment-input');
  const spoiler = document.getElementById('spoiler-check')?.checked;
  if (!input?.value.trim()) { showToast('請輸入留言內容','info'); return; }
  input.value = '';
  if (document.getElementById('spoiler-check')) document.getElementById('spoiler-check').checked = false;
  showToast(spoiler?'劇透留言已發表':'留言已發表 ✓','success');
};


/* ============================================================
   TOAST
   ============================================================ */
let toastContainer;
function initToast() {
  toastContainer = document.getElementById('toast-container');
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.className = 'toast-container';
    document.body.appendChild(toastContainer);
  }
}
function showToast(msg, type='info') {
  if (!toastContainer) initToast();
  const icons = { success:'fa-circle-check', info:'fa-circle-info', error:'fa-circle-xmark' };
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.innerHTML = `<i class="fa-solid ${icons[type]||icons.info}"></i> ${msg}`;
  toastContainer.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 500); }, 3200);
}
window.showToast = showToast;


/* ============================================================
   UTILITIES
   ============================================================ */
function setText(id, text) { const el=document.getElementById(id); if(el) el.textContent=text; }
function truncate(str, len) { return str.length<=len?str:str.slice(0,len)+'…'; }
function escapeHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function anilistIdFromUrl() {
  return new URLSearchParams(window.location.search).get('id');
}
function showError(msg) {
  const el = document.getElementById('page-loading');
  if (el) el.innerHTML = `
    <div style="text-align:center;color:var(--text-muted);padding:60px 20px;">
      <i class="fa-solid fa-circle-exclamation" style="font-size:48px;color:var(--accent-pink);display:block;margin-bottom:16px;"></i>
      <p style="font-size:16px;margin-bottom:8px;">${escapeHtml(msg)}</p>
      <a href="index.html" class="btn btn-ghost btn-sm" style="margin-top:16px;">← 返回首頁</a>
    </div>`;
}

/* ============================================================
   ACF DATA-ACF RENDERER（WordPress 整合輔助）

   用途：
   1. 靜態前端（GenSpark / 預覽）— API 資料已由上方各 render 函式填入，
      data-acf 屬性僅作為標記，供 WP 模板識別對應的 ACF 欄位。
   2. WordPress 部署 — 以下函式可在 WP 模板初始化時被呼叫，
      從 wp_localize_script 注入的 window.weixiaoacgAcf 物件讀取 ACF 值，
      覆蓋前端動態資料（確保 SSR 結果優先）。

   WP TODO: 在 functions.php 中：
     wp_localize_script('weixiaoacg-anime', 'weixiaoacgAcf', [
       'weixiaoacg_title_zh'     => get_field('weixiaoacg_title_zh'),
       'weixiaoacg_title_ja'     => get_field('weixiaoacg_title_ja'),
       'weixiaoacg_episodes'     => get_field('weixiaoacg_episodes'),
       'weixiaoacg_duration'     => get_field('weixiaoacg_duration'),
       'weixiaoacg_year'         => get_field('weixiaoacg_year'),
       'weixiaoacg_source'       => get_field('weixiaoacg_source'),
       'weixiaoacg_studio'       => get_field('weixiaoacg_studio'),
       'weixiaoacg_director'     => get_field('weixiaoacg_director'),
       'weixiaoacg_author'       => get_field('weixiaoacg_author'),
       'weixiaoacg_official_site'=> get_field('weixiaoacg_official_site'),
       'weixiaoacg_twitter_url'  => get_field('weixiaoacg_twitter_url'),
       'weixiaoacg_mal_id'       => get_field('weixiaoacg_mal_id'),
       'weixiaoacg_favourites'   => get_field('weixiaoacg_favourites'),
       'weixiaoacg_popularity'   => get_field('weixiaoacg_popularity'),
       'weixiaoacg_stat_watching'=> get_field('weixiaoacg_stat_watching'),
       'weixiaoacg_stat_completed'=> get_field('weixiaoacg_stat_completed'),
       'weixiaoacg_score_anilist'=> get_field('weixiaoacg_score_anilist'),
       'weixiaoacg_distributor_tw'=> get_field('weixiaoacg_distributor_tw'),
       'weixiaoacg_platform_tw'  => get_field('weixiaoacg_platform_tw'),  // repeater array
       'weixiaoacg_op_ed'        => get_field('weixiaoacg_op_ed'),         // repeater array
       'weixiaoacg_characters'   => get_field('weixiaoacg_characters'),    // repeater array
     ]);
   ============================================================ */

/**
 * 讀取 window.weixiaoacgAcf（WP 注入）並用其值覆蓋 [data-acf] 元素。
 * 純文字欄位：直接設定 textContent。
 * 連結欄位（href）：設定 href。
 * 陣列欄位（platform_tw / op_ed / characters）：呼叫對應 render 函式。
 *
 * 於 DOMContentLoaded 後、API fetch 之前呼叫，
 * 讓 WordPress SSR 資料立即顯示，後續 API 補充資料再覆蓋。
 */
function applyAcfData() {
  /** @type {Object} */
  const acf = window.weixiaoacgAcf;
  if (!acf || typeof acf !== 'object') return; // 非 WP 環境，跳過

  /* ── 文字欄位（data-acf 對應 acf 物件 key）── */
  const TEXT_FIELDS = [
    'weixiaoacg_title_zh', 'weixiaoacg_title_ja',
    'weixiaoacg_episodes', 'weixiaoacg_duration', 'weixiaoacg_year',
    'weixiaoacg_source',   'weixiaoacg_studio',
    'weixiaoacg_director', 'weixiaoacg_author',
    'weixiaoacg_favourites', 'weixiaoacg_popularity',
    'weixiaoacg_stat_watching', 'weixiaoacg_stat_completed', 'weixiaoacg_score_anilist',
    'weixiaoacg_distributor_tw',
  ];

  TEXT_FIELDS.forEach(fieldName => {
    if (!acf[fieldName]) return;
    document.querySelectorAll(`[data-acf="${fieldName}"]`).forEach(el => {
      el.textContent = acf[fieldName];
      el.classList.remove('skeleton-loading');
    });
  });

  /* ── 連結欄位（href）── */
  const HREF_FIELDS = {
    'weixiaoacg_official_site': '#official-link',
    'weixiaoacg_twitter_url':   '#twitter-link',
  };
  Object.entries(HREF_FIELDS).forEach(([field, selector]) => {
    if (!acf[field]) return;
    const el = document.querySelector(selector);
    if (el) { el.href = acf[field]; el.style.display = ''; }
  });

  /* ── MAL ID → 組成 href ── */
  if (acf['weixiaoacg_mal_id']) {
    const malEl = document.getElementById('mal-link');
    if (malEl) malEl.href = `https://myanimelist.net/anime/${acf['weixiaoacg_mal_id']}`;
  }

  /* ── 陣列欄位：台灣平台 weixiaoacg_platform_tw ── */
  if (Array.isArray(acf['weixiaoacg_platform_tw']) && acf['weixiaoacg_platform_tw'].length) {
    _renderAcfPlatforms(acf['weixiaoacg_platform_tw']);
  }

  /* ── 陣列欄位：OP/ED weixiaoacg_op_ed ── */
  if (Array.isArray(acf['weixiaoacg_op_ed']) && acf['weixiaoacg_op_ed'].length) {
    _renderAcfOpEd(acf['weixiaoacg_op_ed']);
  }

  /* ── 陣列欄位：角色 weixiaoacg_characters ── */
  if (Array.isArray(acf['weixiaoacg_characters']) && acf['weixiaoacg_characters'].length) {
    _renderAcfCharacters(acf['weixiaoacg_characters']);
  }
}

/* ── 台灣平台渲染（ACF repeater：[{name, url}]）── */
function _renderAcfPlatforms(platforms) {
  const grid = document.getElementById('platforms-grid');
  if (!grid || !platforms.length) return;

  const PLATFORM_ICONS = {
    '巴哈姆特動畫瘋': { icon:'fa-solid fa-dragon',       color:'#003F9F' },
    '巴哈姆特動畫風': { icon:'fa-solid fa-dragon',       color:'#003F9F' },
    'KKTV':          { icon:'fa-solid fa-play-circle', color:'#FF5500' },
    'CatchPlay':     { icon:'fa-solid fa-film',        color:'#E5004F' },
    'CATCHPLAY+':    { icon:'fa-solid fa-film',        color:'#E5004F' },
    'LiTV':          { icon:'fa-solid fa-play-circle', color:'#F7941D' },
    'MyVideo':       { icon:'fa-solid fa-play-circle', color:'#006DC6' },
    'Vidol':         { icon:'fa-solid fa-play-circle', color:'#FF0058' },
    'Hami Video':    { icon:'fa-solid fa-play-circle', color:'#00B0FF' },
    'friDay影音':    { icon:'fa-solid fa-play-circle', color:'#FF6600' },
    'Netflix':       { icon:'fa-brands fa-netflix',    color:'#E50914' },
    'Crunchyroll':   { icon:'fa-solid fa-play-circle', color:'#F78B2D' },
  };

  // 合併，避免重複
  const existing = grid.querySelectorAll('.platform-card');
  const existNames = new Set([...existing].map(el => el.dataset.platformName || ''));

  const newHtml = platforms
    .filter(p => !existNames.has(p.name))
    .map(p => {
      const cfg = PLATFORM_ICONS[p.name] || { icon:'fa-solid fa-play-circle', color:'var(--accent-blue)' };
      return `
        <a href="${escapeHtml(p.url)}" target="_blank" rel="noopener"
           class="platform-card glass" data-platform-name="${escapeHtml(p.name)}"
           data-acf-item="weixiaoacg_platform_tw">
          <i class="${cfg.icon} platform-icon" style="color:${cfg.color};"></i>
          <div>
            <div class="platform-name">${escapeHtml(p.name)}</div>
            <div class="platform-region">台灣</div>
          </div>
          <span class="platform-status status-airing">上架中</span>
          <i class="fa-solid fa-arrow-up-right-from-square" style="color:var(--text-muted);margin-left:auto;"></i>
        </a>`;
    }).join('');

  if (newHtml) grid.insertAdjacentHTML('afterbegin', newHtml);
}

/* ── OP/ED 渲染（ACF repeater：[{type, title, artist, video_url}]）── */
function _renderAcfOpEd(opEdList) {
  const section = document.getElementById('themes-section');
  const listEl  = document.getElementById('themes-list');
  if (!listEl || !opEdList.length) return;
  if (section) section.style.display = 'block';

  // 若已有 AnimeThemes 資料（_themes 不為空），合併
  const existSlugs = new Set(_themes.map(t => t.slug));

  opEdList.forEach((item, idx) => {
    // 避免重複
    if (item.slug && existSlugs.has(item.slug)) return;

    const typeLabel = (item.type || '').startsWith('OP') ? 'OP' : 'ED';
    const card = document.createElement('div');
    card.className = 'theme-item glass';
    card.dataset.acfItem = 'weixiaoacg_op_ed';
    card.innerHTML = `
      <button class="theme-play-btn" onclick="playTheme(${_themes.length + idx})" title="播放">
        <i class="fa-solid fa-play play-icon" id="play-icon-acf-${idx}"></i>
      </button>
      <span class="theme-type-badge badge-${typeLabel.toLowerCase()}">${escapeHtml(item.type || typeLabel)}</span>
      <div class="theme-info">
        <div class="theme-title">${escapeHtml(item.title || '—')}</div>
        <div class="theme-artist">${escapeHtml(item.artist || '')}</div>
      </div>`;
    listEl.appendChild(card);

    // 補充到 _themes 讓播放器可用
    if (item.video_url) {
      _themes.push({
        type: typeLabel, slug: item.slug || `acf-${idx}`,
        title: item.title || '', artists: item.artist || '',
        videoUrl: item.video_url, episodes: '',
      });
    }
  });
}

/* ── 角色渲染（ACF repeater：[{name, name_jp, image, role, va_name, va_image}]）── */
function _renderAcfCharacters(characters) {
  const grid = document.getElementById('chars-grid');
  if (!grid || !characters.length) return;

  grid.style.display = '';
  // 清除骨架
  grid.querySelectorAll('.skeleton-card').forEach(el => el.remove());

  const html = characters.slice(0, 12).map(c => {
    const isMain  = c.role === '主角';
    const mainCls = isMain ? ' is-main' : '';
    const showName = c.name_zh || c.name || c.name_jp || '';
    const subName  = showName !== c.name_jp ? c.name_jp : '';

    const vaThumb = c.va_image
      ? `<div class="char-va-thumb"><img src="${escapeHtml(c.va_image)}" alt="${escapeHtml(c.va_name || '')}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=\\"char-va-thumb-ph\\"><i class=\\"fa-solid fa-microphone\\"></i></div>';"></div>`
      : c.va_name
        ? `<div class="char-va-thumb"><div class="char-va-thumb-ph"><i class="fa-solid fa-microphone"></i></div></div>`
        : '';

    return `
      <div class="character-card${mainCls}" data-acf-item="weixiaoacg_characters" title="${escapeHtml(showName)}">
        <div class="char-poster-wrap">
          ${c.image
            ? `<img class="char-poster" src="${escapeHtml(c.image)}" alt="${escapeHtml(showName)}" loading="lazy"
                 onerror="this.style.display='none';" />`
            : ''}
          <div class="char-img-ph" style="${c.image ? 'display:none;' : ''}"><i class="fa-solid fa-user"></i></div>
          <span class="char-role-badge">${escapeHtml(c.role || '')}</span>
          ${vaThumb}
          <div class="char-overlay">
            <span class="char-name-zh">${escapeHtml(showName)}</span>
            ${subName ? `<span class="char-name-jp">${escapeHtml(subName)}</span>` : ''}
            ${c.va_name ? `<span class="char-va-name"><i class="fa-solid fa-microphone" style="font-size:9px;"></i> ${escapeHtml(c.va_name)}</span>` : ''}
          </div>
        </div>
      </div>`;
  }).join('');

  grid.innerHTML = html;
}


/* ============================================================
   SEO / E-E-A-T 升級函式
   ============================================================ */

/**
 * updateSeoMeta(media, bgmData)
 * ─ 動態更新 <head> 內的 meta 標籤（title、description、OG、Twitter）
 * ─ 主標題使用繁體中文（getDisplayTitle）
 * ─ 英文標題只用於 SEO meta（不顯示在前端）
 * <!-- WP SEO: 此函式輸出對應 Rank Math / ACF 欄位覆蓋邏輯 -->
 */
function updateSeoMeta(media, bgmData) {
  // 標題 / 副標
  const zhTitle  = (typeof getDisplayTitle === 'function') ? getDisplayTitle(media) : (media.displayName || media.titleRomaji || '動畫作品');
  const enTitle  = (typeof getEnTitle === 'function')      ? getEnTitle(media)      : (media.titleEnglish || media.titleRomaji || '');
  const jaTitle  = (typeof getJaTitle === 'function')      ? getJaTitle(media)      : (media.titleNative || '');
  const studio   = (media.studios || [])[0] || '';
  const year     = media.seasonYear ? `${media.seasonYear} 年` : '';
  const cover    = media.bannerImage || media.coverLarge || '';
  const pageUrl  = window.location.href;

  // 動態 description（繁中）
  const synopsisRaw = bgmData?.summary || media.description || '';
  const cleaned = synopsisRaw
    .replace(/\[img\][\s\S]*?\[\/img\]/gi, '')
    .replace(/\[url=?[^\]]*\]([\s\S]*?)\[\/url\]/gi, '$1')
    .replace(/\[\/?(b|i|u|s|size|color|quote|code|spoiler)[^\]]*\]/gi, '')
    .replace(/<[^>]*>/g, '').replace(/&[a-z]+;/gi, ' ')
    .replace(/\n/g, ' ').slice(0, 120).trim();
  const desc = cleaned
    ? `${cleaned}… |《${zhTitle}》${year}動畫，在微笑動漫追蹤所有情報。`
    : `《${zhTitle}》${year}動畫詳情——劇情介紹、角色、聲優、OP/ED、台灣合法觀看平台與評分。`;

  // 關鍵字（含英文、日文，提升多語搜尋）
  const kw = [zhTitle, jaTitle, enTitle, studio, year, '動畫', '微笑動漫']
    .filter(Boolean).join(',');

  const set = (id, attr, val) => {
    const el = document.getElementById(id);
    if (el && val) el.setAttribute(attr, val);
  };

  // <title>
  const pageTitle = document.getElementById('page-title');
  if (pageTitle) pageTitle.textContent = `${zhTitle} — 微笑動漫`;

  // meta description / keywords / canonical
  set('meta-desc', 'content', desc);
  set('meta-kw',   'content', kw);
  const canon = document.getElementById('canonical-link');
  if (canon) canon.href = pageUrl;

  // Open Graph
  set('og-title', 'content', `${zhTitle} — 微笑動漫`);
  set('og-desc',  'content', desc);
  set('og-image', 'content', cover);
  set('og-url',   'content', pageUrl);

  // Twitter Card
  set('tw-title', 'content', `${zhTitle} — 微笑動漫`);
  set('tw-desc',  'content', desc);
  set('tw-image', 'content', cover);

  console.log('[SEO] Meta tags updated — title:', zhTitle, '| desc length:', desc.length);
}

/**
 * injectSchemaJsonLd(media, bgmData, jikanData)
 * ─ 更新 JSON-LD TVSeries、BreadcrumbList、FAQPage
 * <!-- WP SEO: Rank Math Schema builder 在 WP 端會覆蓋下列 JSON-LD -->
 */
function injectSchemaJsonLd(media, bgmData, jikanData) {
  const zhTitle  = (typeof getDisplayTitle === 'function') ? getDisplayTitle(media) : (media.displayName || '動畫作品');
  const jaTitle  = (typeof getJaTitle === 'function')      ? getJaTitle(media)      : (media.titleNative || '');
  const enTitle  = (typeof getEnTitle === 'function')      ? getEnTitle(media)      : (media.titleEnglish || '');
  const cover    = media.bannerImage || media.coverLarge || '';
  const studio   = (media.studios || [])[0] || '';
  const pageUrl  = window.location.href;
  const src      = (typeof translateSource === 'function') ? translateSource(media.source || '') : '';

  // 1. TVSeries
  const tvEl = document.getElementById('jsonld-tvseries');
  if (tvEl) {
    const synRaw = bgmData?.summary || media.description || '';
    const synClean = synRaw
      .replace(/\[img\][\s\S]*?\[\/img\]/gi, '')
      .replace(/\[url=?[^\]]*\]([\s\S]*?)\[\/url\]/gi, '$1')
      .replace(/\[\/?(b|i|u|s|size|color|quote|code|spoiler)[^\]]*\]/gi, '')
      .replace(/<[^>]*>/g, '').replace(/&[a-z]+;/gi, ' ')
      .replace(/\n/g, ' ').slice(0, 250).trim();

    const schema = {
      '@context': 'https://schema.org',
      '@type': 'TVSeries',
      'name': zhTitle,
      'alternateName': [jaTitle, enTitle].filter(Boolean),
      'description': synClean || `《${zhTitle}》動畫作品詳情。`,
      'image': cover,
      'datePublished': media.startDate ? media.startDate.replace(/\//g, '-') : undefined,
      'numberOfEpisodes': media.episodes || undefined,
      'productionCompany': studio ? { '@type': 'Organization', 'name': studio } : undefined,
      'aggregateRating': media.averageScore ? {
        '@type': 'AggregateRating',
        'ratingValue': (media.averageScore / 10).toFixed(1),
        'bestRating': '10',
        'ratingCount': String(media.popularity || 0),
      } : undefined,
      'url': pageUrl,
      'inLanguage': 'ja',
      'countryOfOrigin': { '@type': 'Country', 'name': 'JP' },
    };
    // 移除 undefined
    tvEl.textContent = JSON.stringify(schema, (_, v) => v === undefined ? undefined : v, 2);
  }

  // 2. BreadcrumbList
  const bcEl = document.getElementById('jsonld-breadcrumb');
  if (bcEl) {
    try {
      const bc = JSON.parse(bcEl.textContent);
      bc.itemListElement[2].name = zhTitle;
      bc.itemListElement[2].item = pageUrl;
      bcEl.textContent = JSON.stringify(bc, null, 2);
    } catch { /* JSON parse 失敗時跳過 */ }
  }

  // 3. FAQPage（動態填入）
  fillFaqContent(media, bgmData);

  console.log('[SEO] JSON-LD injected — TVSeries:', zhTitle);
}

/**
 * fillFaqContent(media, bgmData)
 * ─ 動態填入 FAQ JSON-LD 的問答內容
 * ─ 同時更新 HTML 頁面上的 FAQ 區塊（若存在 #faq-section）
 * <!-- WP SEO: FAQ 內容可由 ACF 'weixiaoacg_faq' repeater 覆蓋 -->
 */
function fillFaqContent(media, bgmData) {
  const zhTitle  = (typeof getDisplayTitle === 'function') ? getDisplayTitle(media) : (media.displayName || '此動畫');
  const eps      = media.episodes ? `${media.episodes} 集` : '';
  const src      = (typeof translateSource === 'function') ? translateSource(media.source || '') : '';
  const studio   = (media.studios || [])[0] || '';
  const score    = media.averageScore ? (media.averageScore / 10).toFixed(1) : '';
  const year     = media.seasonYear || '';

  // 動態 FAQ 問答
  const faqs = [
    {
      q: `《${zhTitle}》哪裡可以合法觀看？`,
      a: `《${zhTitle}》在台灣可透過巴哈姆特動畫瘋、Crunchyroll、Netflix 等合法平台觀看。詳細上架資訊請參閱本頁「合法觀看平台」區塊。`,
    },
    {
      q: `《${zhTitle}》共有幾集？`,
      a: eps
        ? `《${zhTitle}》全劇共 ${eps}，詳細集數與播出時間表請參閱本頁「作品資料」欄位。`
        : `《${zhTitle}》集數資訊請參閱本頁「作品資料」欄，由多平台資料自動彙整。`,
    },
    {
      q: `《${zhTitle}》是什麼類型的動畫？`,
      a: src && src !== '—'
        ? `《${zhTitle}》原作類型為「${src}」，由 ${studio || '製作公司'} 製作，${year ? `${year} 年` : ''}播出。`
        : `《${zhTitle}》的原作來源與類型資訊顯示於本頁「作品資料」欄中。`,
    },
  ];

  if (score) {
    faqs.push({
      q: `《${zhTitle}》評分如何？`,
      a: `《${zhTitle}》目前 AniList 評分為 ${score}/10，相關評分資訊詳見本頁評分概況區塊。`,
    });
  }

  // 更新 JSON-LD FAQ
  const faqSchema = document.getElementById('jsonld-faq');
  if (faqSchema) {
    const schema = {
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      'mainEntity': faqs.map(f => ({
        '@type': 'Question',
        'name': f.q,
        'acceptedAnswer': { '@type': 'Answer', 'text': f.a },
      })),
    };
    faqSchema.textContent = JSON.stringify(schema, null, 2);
  }

  // 更新 HTML FAQ 區塊（若有 #faq-section）
  const faqSection = document.getElementById('faq-section');
  if (faqSection) {
    const listEl = faqSection.querySelector('.faq-list');
    if (listEl) {
      listEl.innerHTML = faqs.map((f, idx) => `
        <div class="faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
          <div class="faq-question" onclick="toggleFaq(this)" aria-expanded="false">
            <span itemprop="name">${escapeHtml(f.q)}</span>
            <i class="fa-solid fa-chevron-down faq-arrow"></i>
          </div>
          <div class="faq-answer glass" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
            <p itemprop="text">${escapeHtml(f.a)}</p>
          </div>
        </div>`).join('');
    }
  }

  console.log('[SEO] FAQ content filled — title:', zhTitle, '| items:', faqs.length);
}

/* FAQ 開合切換 */
window.toggleFaq = function(questionEl) {
  const item = questionEl.closest('.faq-item');
  if (!item) return;
  const answer  = item.querySelector('.faq-answer');
  const isOpen  = item.classList.toggle('faq-open');
  questionEl.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  const icon = questionEl.querySelector('.faq-arrow');
  if (icon) icon.style.transform = isOpen ? 'rotate(180deg)' : '';
  if (answer) answer.style.display = isOpen ? 'block' : 'none';
};
