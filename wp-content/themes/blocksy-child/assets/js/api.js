/* ============================================================
   微笑動漫 — Multi-Source API Helper
   ┌─ AniListAPI   graphql.anilist.co      (主鍵, 繁中名, banner)
   ├─ BangumiAPI   api.bgm.tv              (中文簡介, 製作, 集數)
   ├─ JikanAPI     api.jikan.moe/v4        (角色, MAL評分, 串流)
   └─ OpenCC       cdn.jsdelivr.net        (簡→繁轉換)
   ============================================================ */

/* ══════════════════════════════════════════════════════════════
   OPENCC — 簡體轉繁體
   ══════════════════════════════════════════════════════════════ */
const OpenCCHelper = (() => {
  let _converter = null;
  let _ready     = false;
  let _loading   = false;
  const _queue   = [];

  async function init() {
    if (_ready) return;
    if (_loading) return new Promise(r => _queue.push(r));
    _loading = true;

    return new Promise((resolve) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/opencc-js@1.0.5/dist/umd/full.js';
      script.onload = () => {
        try {
          _converter = OpenCC.Converter({ from: 'cn', to: 'twp' });
        } catch (e) {
          console.warn('OpenCC init failed:', e);
          _converter = null;
        }
        _ready = true;
        _loading = false;
        _queue.forEach(r => r());
        _queue.length = 0;
        resolve();
      };
      script.onerror = () => {
        _ready = true; _loading = false;
        _queue.forEach(r => r());
        resolve();
      };
      document.head.appendChild(script);
    });
  }

  function convert(text) {
    if (!text) return text;
    try {
      return _converter ? _converter(text) : text;
    } catch { return text; }
  }

  return { init, convert };
})();


/* ══════════════════════════════════════════════════════════════
   ANILIST API  (GraphQL)
   主鍵：AniList ID
   ══════════════════════════════════════════════════════════════ */
const AniListAPI = (() => {
  const ENDPOINT = 'https://graphql.anilist.co';

  async function _query(query, variables = {}) {
    const res = await fetch(ENDPOINT, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body:    JSON.stringify({ query, variables }),
    });
    if (!res.ok) throw new Error(`AniList HTTP ${res.status}`);
    const json = await res.json();
    if (json.errors?.length) throw new Error(json.errors[0].message);
    return json.data;
  }

  /* ── 作品詳情（單部） ── */
  async function getMedia(anilistId) {
    const data = await _query(`
      query ($id: Int) {
        Media(id: $id, type: ANIME) {
          id
          idMal
          title { romaji english native }
          coverImage { extraLarge large color }
          bannerImage
          description(asHtml: false)
          season seasonYear
          status
          episodes
          duration
          averageScore
          meanScore
          popularity
          favourites
          trending
          rankings { rank type context season year allTime }
          genres
          tags { name rank isMediaSpoiler }
          studios(isMain: true) { nodes { name siteUrl } }
          staff(perPage: 10) {
            edges {
              role
              node { name { full native } siteUrl }
            }
          }
          nextAiringEpisode { airingAt timeUntilAiring episode }
          startDate { year month day }
          endDate   { year month day }
          source
          countryOfOrigin
          isAdult
          siteUrl
          trailer { id site }
          externalLinks { url site type language }
          streamingEpisodes { title thumbnail url site }
          stats {
            statusDistribution { status amount }
          }
        }
      }
    `, { id: anilistId });
    return normalizeMedia(data.Media);
  }

  /* ── 搜尋（用名稱） ── */
  async function searchMedia(title, limit = 6) {
    const data = await _query(`
      query ($search: String, $perPage: Int) {
        Page(perPage: $perPage) {
          media(search: $search, type: ANIME, sort: SEARCH_MATCH) {
            id idMal
            title { romaji english native }
            coverImage { large color }
            season seasonYear averageScore episodes
            status
          }
        }
      }
    `, { search: title, perPage: limit });
    return (data.Page.media || []).map(normalizeMediaLight);
  }

  /* ── 本季熱門 ── */
  async function getSeasonalAnime(season, year, limit = 20) {
    const data = await _query(`
      query ($season: MediaSeason, $year: Int, $perPage: Int) {
        Page(perPage: $perPage) {
          media(season: $season, seasonYear: $year, type: ANIME,
                sort: POPULARITY_DESC, isAdult: false) {
            id idMal
            title { romaji english native }
            coverImage { large color }
            season seasonYear averageScore episodes
            status popularity
            studios(isMain: true) { nodes { name } }
            nextAiringEpisode { airingAt episode }
          }
        }
      }
    `, { season, year, perPage: limit });
    return (data.Page.media || []).map(normalizeMediaLight);
  }

  /* ── 排行榜 ── */
  async function getTopAnime(limit = 20) {
    const data = await _query(`
      query ($perPage: Int) {
        Page(perPage: $perPage) {
          media(type: ANIME, sort: SCORE_DESC, isAdult: false) {
            id idMal
            title { romaji english native }
            coverImage { large color }
            averageScore meanScore episodes
            status popularity favourites
            studios(isMain: true) { nodes { name } }
          }
        }
      }
    `, { perPage: limit });
    return (data.Page.media || []).map(normalizeMediaLight);
  }

  /* ── 正規化（完整）── */
  function normalizeMedia(m) {
    if (!m) return null;
    const startDate = m.startDate
      ? `${m.startDate.year || ''}/${String(m.startDate.month||'').padStart(2,'0')}/${String(m.startDate.day||'').padStart(2,'0')}`
      : '';

    // statusDistribution → watching / completed 數量
    const statusDist = {};
    for (const s of (m.stats?.statusDistribution || [])) {
      statusDist[s.status] = s.amount || 0;
    }

    // staff edges → 導演 / 原作者
    const staffEdges = m.staff?.edges || [];
    const findStaff = (role) => {
      const e = staffEdges.find(e => e.role === role);
      return e ? (e.node?.name?.full || e.node?.name?.native || '') : '';
    };

    return {
      id:           m.id,
      idMal:        m.idMal || null,
      // 標題：英文 > 羅馬拼音（AniList 無中文欄位，中文由 Bangumi 補充）
      titleChinese: '',  // 由 Bangumi 補充
      titleEnglish: m.title?.english || '',
      titleRomaji:  m.title?.romaji  || '',
      titleNative:  m.title?.native  || '',
      displayName:  m.title?.english || m.title?.romaji || '',
      // 圖片
      coverLarge:   m.coverImage?.extraLarge || m.coverImage?.large || '',
      coverColor:   m.coverImage?.color || '#1E242B',
      bannerImage:  m.bannerImage || '',
      // 描述（英文，後續可用 Bangumi 覆蓋）
      description:  (m.description || '').replace(/<[^>]*>/g, '').trim(),
      // 評分
      averageScore: m.averageScore || 0,
      meanScore:    m.meanScore    || 0,
      popularity:   m.popularity   || 0,
      favourites:   m.favourites   || 0,
      trending:     m.trending     || 0,
      // 基本資料
      season:       m.season     || '',
      seasonYear:   m.seasonYear || '',
      status:       m.status     || '',
      episodes:     m.episodes   || 0,
      duration:     m.duration   || 0,
      genres:       m.genres     || [],
      source:       m.source     || '',
      isAdult:      m.isAdult    || false,
      // 製作公司
      studios:      (m.studios?.nodes || []).map(s => s.name),
      // 播出資料
      startDate,
      endDate: m.endDate
        ? `${m.endDate.year || ''}/${String(m.endDate.month||'').padStart(2,'0')}/${String(m.endDate.day||'').padStart(2,'0')}`
        : '',
      nextAiring:   m.nextAiringEpisode || null,
      // 外部
      siteUrl:      m.siteUrl || '',
      trailer:      m.trailer || null,
      externalLinks: m.externalLinks || [],
      streamingEpisodes: m.streamingEpisodes || [],
      // tags（過濾劇透）
      tags: (m.tags || [])
        .filter(t => !t.isMediaSpoiler && t.rank >= 60)
        .slice(0, 6)
        .map(t => t.name),
      // statusDistribution
      statusWatching:   statusDist['CURRENT']   || 0,
      statusCompleted:  statusDist['COMPLETED']  || 0,
      statusDropped:    statusDist['DROPPED']    || 0,
      statusPlanning:   statusDist['PLANNING']   || 0,
      // rankings
      rankings: m.rankings || [],
      // characters（AniList，備用；角色詳情主要來自 Jikan）
      charactersRaw: (m.characters?.edges || []).map(e => ({
        id:      e.node?.id || 0,
        name:    e.node?.name?.full   || '',
        nameJp:  e.node?.name?.native || '',
        image:   e.node?.image?.medium || '',
        role:    e.role === 'MAIN' ? '主角' : e.role === 'SUPPORTING' ? '配角' : '背景',
        va:      e.voiceActors?.[0]?.name?.full   || '',
        vaImg:   e.voiceActors?.[0]?.image?.medium || '',
      })),
      // staff
      director: findStaff('Director') || findStaff('Chief Director') || '',
      author:   findStaff('Original Creator') || findStaff('Original Story') || '',
    };
  }

  function normalizeMediaLight(m) {
    return {
      id:           m.id,
      idMal:        m.idMal || null,
      titleChinese: '',              // 由 Bangumi enrichCardsWithChineseName 非同步補充
      titleEnglish: m.title?.english || '',
      titleRomaji:  m.title?.romaji  || '',
      titleNative:  m.title?.native  || '',
      // displayName 優先日文原名，再備用羅馬拼音，英文就算最後
      // getDisplayTitle 後續會優先 titleChinese(由Bangumi補)，再用 displayName
      displayName:  m.title?.native  || m.title?.romaji || m.title?.english || '',
      coverLarge:   m.coverImage?.large || '',
      coverColor:   m.coverImage?.color || '#1E242B',
      averageScore: m.averageScore || 0,
      episodes:     m.episodes || 0,
      status:       m.status   || '',
      popularity:   m.popularity || 0,
      season:       m.season     || '',
      seasonYear:   m.seasonYear || '',
      genres:       m.genres     || [],
      studios:      (m.studios?.nodes || []).map(s => s.name),
      nextAiring:   m.nextAiringEpisode || null,
    };
  }

  /* ── 工具函數 ── */
  function getCurrentSeason() {
    const month = new Date().getMonth() + 1;
    const year  = new Date().getFullYear();
    const season = month <= 3 ? 'WINTER' : month <= 6 ? 'SPRING' : month <= 9 ? 'SUMMER' : 'FALL';
    return { season, year };
  }

  function formatAniListScore(score) {
    if (!score) return '–';
    return (score / 10).toFixed(1); // 85 → 8.5
  }

  return {
    getMedia,
    searchMedia,
    getSeasonalAnime,
    getTopAnime,
    getCurrentSeason,
    formatAniListScore,
  };
})();


/* ══════════════════════════════════════════════════════════════
   共用工具函數（供 anime.js / main.js 使用）
   ══════════════════════════════════════════════════════════════ */

/**
 * translateSource — AniList source 英文 → 繁體中文
 * 若傳入未知值，原樣回傳（自動相容 AniList 未來新增的類型）
 * WP TODO: 若 ACF 有覆蓋欄位 'anime_source_zh' 優先使用
 */
function translateSource(source) {
  if (!source) return '—';
  const SOURCE_MAP = {
    'ORIGINAL':              '原創',
    'MANGA':                 '漫畫改編',
    'LIGHT_NOVEL':           '輕小說改編',
    'VISUAL_NOVEL':          '視覺小說改編',
    'VIDEO_GAME':            '電子遊戲改編',
    'OTHER':                 '其他',
    'NOVEL':                 '小說改編',
    'DOUJINSHI':             '同人誌改編',
    'ANIME':                 '動畫改編',
    'WEB_NOVEL':             '網路小說改編',
    'LIVE_ACTION':           '真人影視改編',
    'GAME':                  '遊戲改編',
    'COMIC':                 '漫畫改編',
    'MULTIMEDIA_PROJECT':    '多媒體企劃',
    'PICTURE_BOOK':          '繪本改編',
    'FOUR_KOMA':             '四格漫畫改編',
    'CARD_GAME':             '卡牌遊戲改編',
    'MUSIC':                 '音樂企劃',
  };
  return SOURCE_MAP[source] || source;
}

/**
 * formatNumber — 數字格式化為「萬」「千」或原始字串
 * @param {number|null|undefined} num
 * @returns {string}  e.g. 12345 → '1.2萬'，999 → '999'，falsy → '—'
 */
function formatNumber(num) {
  if (!num && num !== 0) return '—';
  if (num === 0)         return '0';
  if (num >= 100000000)  return `${(num / 100000000).toFixed(1)}億`;
  if (num >= 10000)      return `${(num / 10000).toFixed(1)}萬`;
  if (num >= 1000)       return `${(num / 1000).toFixed(1)}千`;
  return String(num);
}

/**
 * fillSidebarData — 將 media 資料填入帶有 data-field 屬性的元素
 * 並移除 skeleton-loading class，標記資料已載入完成
 * @param {Object} media - AniList normalizeMedia 正規化後的物件
 */
function fillSidebarData(media) {
  if (!media) return;

  // 欄位對照表：data-field → 取值函數
  const fieldMap = {
    // Hero stats card
    'favourites': () => formatNumber(media.favourites),
    'popularity': () => formatNumber(media.popularity),
    'watching':   () => formatNumber(media.statusWatching),
    'completed':  () => formatNumber(media.statusCompleted),
    'episodes':   () => media.episodes ? `${media.episodes} 集` : '—',
    'duration':   () => media.duration ? `${media.duration} 分鐘` : '—',
    'year':       () => media.seasonYear ? String(media.seasonYear) : '—',
    'source':     () => translateSource(media.source),
    'studio':     () => (media.studios || [])[0] || '—',
    // Meta Grid（由 renderMeta 動態產生，也透過 data-field 填寫）
    'author':     () => media.author   || '—',
    'director':   () => media.director || '—',
    // 社群動態 sidebar（用 data-field 統一填入）
    'comm-watching':   () => formatNumber(media.statusWatching || media.popularity),
    'comm-favourites': () => formatNumber(media.favourites),
    'comm-score':      () => media.averageScore ? (media.averageScore / 10).toFixed(1) : '—',
  };

  // 批量填入所有帶 data-field 屬性的元素
  document.querySelectorAll('[data-field]').forEach(el => {
    const field = el.dataset.field;
    if (field && fieldMap[field]) {
      const val = fieldMap[field]();
      el.textContent = val;
      el.classList.remove('skeleton-loading');
    }
  });

  // 也移除不在 fieldMap 但有 skeleton-loading 的 stats 元素
  document.querySelectorAll('.stats-item-num.skeleton-loading, .quick-info-val.skeleton-loading').forEach(el => {
    el.classList.remove('skeleton-loading');
  });
}


/* ══════════════════════════════════════════════════════════════
   BANGUMI API  (REST v0)
   用途：中文劇情簡介、製作名單、集數列表
   ══════════════════════════════════════════════════════════════ */
const BangumiAPI = (() => {
  const BASE    = 'https://api.bgm.tv';
  const BASE_V0 = 'https://api.bgm.tv/v0';

  let _queue = Promise.resolve();
  function _req(url, opts = {}) {
    // 用 .catch(() => null) 降級隔離錯誤，避免一個請求失敗導致整條 queue 塍死
    const task = _queue.then(() =>
      fetch(url, {
        headers: { 'Accept': 'application/json', 'User-Agent': 'weixiaoacg/1.0', ...opts.headers },
        ...opts,
      }).then(r => r.ok ? r.json() : Promise.reject(new Error(`Bgm HTTP ${r.status}`)))
    );
    // queue 継續到下一個任務（無論成否）
    _queue = task.catch(() => null);
    return task; // 回傳未降級的 promise，讓呼叫端自行 catch
  }

  function toHttps(url) {
    return url ? url.replace(/^http:\/\//i, 'https://') : '';
  }

  /* ── 用 AniList native title（日文名）搜尋 Bangumi ── */
  async function searchByTitle(title, limit = 3) {
    const encoded = encodeURIComponent(title);
    const data = await _req(`${BASE}/search/subject/${encoded}?type=2&responseGroup=small&max_results=${limit}`);
    return (data?.list || []).map(s => ({
      id:      s.id,
      name:    s.name    || '',
      nameCn:  s.name_cn || s.name,
      image:   toHttps(s.images?.large || ''),
      score:   s.rating?.score || 0,
      airDate: s.air_date || '',
    }));
  }

  /* ── 作品詳情（含 infobox）── */
  async function getSubjectById(id) {
    const s = await _req(`${BASE_V0}/subjects/${id}`);
    const info = _parseInfobox(s.infobox || []);
    return {
      id:      s.id,
      name:    s.name    || '',
      nameCn:  s.name_cn || s.name,
      summary: s.summary || '',
      image:   toHttps(s.images?.large || ''),
      score:   s.rating?.score  || 0,
      total:   s.rating?.total  || 0,
      airDate: s.date || '',
      eps:     s.eps  || 0,
      platform: s.platform || '',
      tags:    (s.tags || []).slice(0, 8).map(t => t.name),
      // infobox 解析
      studio:    info.studio   || info['動畫製作'] || '',
      director:  info.director || info['導演']     || '',
      music:     info.music    || info['音樂']     || '',
      source:    info.source   || info['原作']     || '',
      channel:   info.channel  || info['放送電視台'] || '',
      duration:  info.duration || info['每集長度'] || '',
    };
  }

  /* ── 角色（Bangumi 備用，主要用 Jikan）── */
  async function getCharacters(id) {
    const data = await _req(`${BASE_V0}/subjects/${id}/characters`);
    return (data || []).slice(0, 12).map(c => ({
      id:       c.id,
      name:     c.name    || '',
      nameCn:   c.name_cn || c.name,
      image:    toHttps(c.images?.large || c.images?.medium || ''),
      relation: c.relation || '',
      actors:   (c.actors || []).map(a => ({
        id: a.id, name: a.name,
        image: toHttps(a.images?.medium || ''),
      })),
    }));
  }

  /* ── 集數列表 ── */
  async function getEpisodes(id, limit = 100) {
    const data = await _req(`${BASE_V0}/episodes?subject_id=${id}&limit=${limit}`);
    return (data?.data || [])
      .filter(e => e.type === 0) // type=0 = 正集
      .map(e => ({
        id:      e.id,
        ep:      e.ep || 0,
        name:    e.name    || '',
        nameCn:  e.name_cn || e.name || '',
        airDate: e.airdate || '',
        duration: e.duration || 0,
        desc:    e.desc || '',
      }));
  }

  /* ── 關聯作品 ── */
  async function getRelations(id) {
    const data = await _req(`${BASE_V0}/subjects/${id}/subjects`);
    return (data || []).filter(r => r.type === 2).slice(0, 6).map(r => ({
      id:          r.id,
      name:        r.name    || '',
      nameCn:      r.name_cn || r.name,
      displayName: r.name_cn || r.name,
      image:       toHttps(r.images?.large || ''),
      relation:    r.relation || '',
    }));
  }

  /* ── 日曆（首頁用）── */
  async function getCalendar() {
    const data = await _req(`${BASE}/calendar`);
    const WEEKDAY_ZH = {1:'星期一',2:'星期二',3:'星期三',4:'星期四',5:'星期五',6:'星期六',7:'星期日'};
    return (data || []).map(group => ({
      weekday:   group.weekday?.id || 0,
      weekdayZh: group.weekday?.cn || WEEKDAY_ZH[group.weekday?.id] || '其他',
      items: (group.items || []).map(s => ({
        id:          s.id,
        name:        s.name    || '',
        nameCn:      s.name_cn || s.name,
        displayName: s.name_cn || s.name,
        image:       toHttps(s.images?.large || ''),
        score:       s.rating?.score || 0,
        doing:       s.collection?.doing || 0,
        airDate:     s.air_date || '',
        airWeekday:  s.air_weekday || 0,
      })),
    }));
  }

  function _parseInfobox(infobox) {
    const result = {};
    for (const item of infobox) {
      const val = Array.isArray(item.value)
        ? item.value.map(v => typeof v === 'object' ? v.v : v).join('、')
        : item.value;
      result[item.key] = val;
      // 英文 key 對照
      const keyMap = {
        '動畫製作':'studio','導演':'director','音樂':'music',
        '原作':'source','放送電視台':'channel','每集長度':'duration',
      };
      if (keyMap[item.key]) result[keyMap[item.key]] = val;
    }
    return result;
  }

  function formatScore(score) {
    return (!score || score === 0) ? '–' : parseFloat(score).toFixed(1);
  }
  function formatCount(n) {
    if (!n) return '–';
    if (n >= 10000) return `${(n/10000).toFixed(1)}萬`;
    if (n >= 1000)  return `${(n/1000).toFixed(1)}K`;
    return String(n);
  }
  function getBgmUrl(id) { return `https://bgm.tv/subject/${id}`; }

  const WEEKDAY_ZH = {1:'星期一',2:'星期二',3:'星期三',4:'星期四',5:'星期五',6:'星期六',7:'星期日'};

  /* ── 當季熱門（從 calendar 全部展平，依追番數排序）── */
  async function getCurrentSeason(limit = 20) {
    const calendar = await getCalendar();
    const all = [];
    for (const group of calendar) all.push(...group.items);
    return all
      .filter(a => a.doing > 0)
      .sort((a, b) => b.doing - a.doing)
      .slice(0, limit);
  }

  /* ── 搜尋（用關鍵字）── */
  async function searchAnime(keyword, limit = 6) {
    const encoded = encodeURIComponent(keyword);
    const data = await _req(`${BASE}/search/subject/${encoded}?type=2&responseGroup=small&max_results=${limit}`);
    return (data?.list || []).map(s => ({
      id:          s.id,
      name:        s.name    || '',
      nameCn:      s.name_cn || s.name,
      displayName: s.name_cn || s.name,
      image:       toHttps(s.images?.large || ''),
      score:       s.rating?.score || 0,
      airDate:     s.air_date || '',
      doing:       0,
      tags:        [],
    }));
  }

  /* ── 熱門動畫（Bangumi v0 search ranking）── */
  async function getTopAnime(limit = 12) {
    try {
      const res = await fetch(`${BASE_V0}/search/subjects`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'User-Agent': 'weixiaoacg/1.0' },
        body: JSON.stringify({ type: 2, sort: 'rank', filter: { type: [2] } }),
      });
      if (!res.ok) throw new Error('BGM search ' + res.status);
      const data = await res.json();
      return (data?.data || []).slice(0, limit).map(s => ({
        id:          s.id,
        name:        s.name    || '',
        nameCn:      s.name_cn || s.name,
        displayName: s.name_cn || s.name,
        image:       toHttps(s.images?.large || ''),
        score:       s.rating?.score || 0,
        doing:       0,
        airDate:     s.date || '',
        tags:        (s.tags || []).slice(0, 3).map(t => t.name),
      }));
    } catch { return []; }
  }

  return {
    searchByTitle, getSubjectById, getCharacters,
    getEpisodes, getRelations, getCalendar,
    getCurrentSeason, searchAnime, getTopAnime,
    formatScore, formatCount, getBgmUrl, WEEKDAY_ZH,
  };
})();


/* ══════════════════════════════════════════════════════════════
   JIKAN API  (MAL)
   用途：角色/聲優、MAL評分、串流平台
   ══════════════════════════════════════════════════════════════ */
const JikanAPI = (() => {
  const BASE = 'https://api.jikan.moe/v4';

  // 簡易 queue，間隔 350ms 避免 429
  let _queue = Promise.resolve();
  function _req(url) {
    _queue = _queue.then(() =>
      new Promise(r => setTimeout(r, 350)).then(() =>
        fetch(url).then(r => r.ok ? r.json() : Promise.reject(new Error(`Jikan HTTP ${r.status} ${url}`)))
      )
    );
    return _queue;
  }

  async function getAnimeById(malId) {
    const data = await _req(`${BASE}/anime/${malId}/full`);
    const a = data.data;
    if (!a) return null;
    return {
      malId:      a.mal_id,
      score:      a.score      || 0,
      scoredBy:   a.scored_by  || 0,
      rank:       a.rank       || 0,
      popularity: a.popularity || 0,
      members:    a.members    || 0,
      favorites:  a.favorites  || 0,
      // 海報（MAL 高品質）
      coverLarge: a.images?.jpg?.large_image_url || a.images?.jpg?.image_url || '',
      // 串流平台
      streaming:  (a.streaming || []).map(s => ({ name: s.name, url: s.url })),
      // 外部連結
      external:   (a.external || []).map(e => ({ name: e.name, url: e.url })),
      // trailer
      trailerUrl:      a.trailer?.url       || '',
      trailerEmbedUrl: a.trailer?.embed_url || '',
      // 播出時間
      broadcast: a.broadcast || null,
      // 評分詳情
      rating: a.rating || '',
    };
  }

  async function getCharacters(malId) {
    const data = await _req(`${BASE}/anime/${malId}/characters`);
    return (data.data || []).slice(0, 12).map(c => ({
      id:    c.character.mal_id,
      name:  c.character.name,
      image: c.character.images?.jpg?.image_url || '',
      role:  c.role === 'Main' ? '主角' : c.role === 'Supporting' ? '配角' : c.role,
      va:    c.voice_actors?.find(v => v.language === 'Japanese')?.person?.name || '',
      vaImg: c.voice_actors?.find(v => v.language === 'Japanese')?.person?.images?.jpg?.image_url || '',
    }));
  }

  async function searchAnime(title, limit = 5) {
    const data = await _req(`${BASE}/anime?q=${encodeURIComponent(title)}&limit=${limit}&type=tv`);
    return (data.data || []).map(a => ({
      malId: a.mal_id,
      title: a.title,
      image: a.images?.jpg?.large_image_url || '',
      score: a.score || 0,
    }));
  }

  /* ── 從 external links 取得 Bangumi ID（最精準的橋接方式）
     ⚠️  不走 _req queue，使用獨立 fetch 避免 350ms 延遲影響並行請求 ── */
  async function getBangumiId(malId) {
    if (!malId) return null;
    try {
      // 直接 fetch，不走 throttle queue
      const res  = await fetch(`${BASE}/anime/${malId}/external`);
      if (!res.ok) return null;
      const data = await res.json();
      const bgmLink = (data.data || []).find(
        e => e.name === 'Bangumi' ||
             /bangumi\.tv\/subject\//i.test(e.url) ||
             /bgm\.tv\/subject\//i.test(e.url)
      );
      if (!bgmLink) return null;
      // 從 URL 解析出數字 ID：https://bangumi.tv/subject/975
      const match = bgmLink.url.match(/subject\/(\d+)/);
      return match ? parseInt(match[1]) : null;
    } catch { return null; }
  }

  function formatScore(score) { return score ? parseFloat(score).toFixed(2) : '–'; }
  function formatCount(n) {
    if (!n) return '–';
    if (n >= 10000000) return `${(n/10000000).toFixed(1)}千萬`;
    if (n >= 100000)   return `${(n/10000).toFixed(0)}萬`;
    if (n >= 10000)    return `${(n/10000).toFixed(1)}萬`;
    if (n >= 1000)     return `${(n/1000).toFixed(1)}K`;
    return String(n);
  }

  return { getAnimeById, getCharacters, searchAnime, getBangumiId, formatScore, formatCount };
})();


/* ══════════════════════════════════════════════════════════════
   共用工具：標題相符判斷（供 main.js / anime.js 共用）
   ══════════════════════════════════════════════════════════════ */
function isTitleMatch(query, nameJp, nameCn) {
  if (!query) return false;
  const normalize = s => (s || '')
    .replace(/[\uff01-\uff5e]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
    .replace(/\u3000/g, ' ')
    .toLowerCase()
    .replace(/[\s\-\u2013\u2014\uff1a:\u00b7\u30fb\uff5e~\u266a\u2661\u2605\u2606!\uff01?\uff1f\u3002\u3001,\uff0c.\u2026\/\\]+/g, '')
    .replace(/^the\s*/i, '');

  const q  = normalize(query);
  const nj = normalize(nameJp || '');
  const nc = normalize(nameCn || '');
  if (!q || (!nj && !nc)) return false;

  if (nj === q || nc === q) return true;
  if (nj && (nj.includes(q) || q.includes(nj))) return true;
  if (nc && (nc.includes(q) || q.includes(nc))) return true;

  // 字元重疊率 >= 80%
  const _charOverlap = (a, b) => {
    if (!a || !b) return 0;
    const shorter = a.length <= b.length ? a : b;
    const longer  = a.length <= b.length ? b : a;
    let matched = 0;
    for (const ch of shorter) if (longer.includes(ch)) matched++;
    return matched / shorter.length;
  };
  if (nj && _charOverlap(q, nj) >= 0.80) return true;
  if (nc && _charOverlap(q, nc) >= 0.80) return true;
  return false;
}
