/* ============================================================
   微笑動漫 — 共用工具函式庫 (utils.js)
   包含：
     - getDisplayTitle(anime)   繁中優先的顯示標題
     - getJaTitle(anime)        日文原名（副標題）
     - getCoverImage(anime)     封面圖 URL
     - translateStatus(status)  狀態翻譯
     - translateSource(source)  原作來源翻譯（19 種）
     - translateSeason(season)  季節翻譯
     - formatAniListScore(n)    評分格式化
     - formatNumber(n)          數字中文單位
     - getCurrentSeason()       當前季節
   ============================================================
   WP 化說明：
     - getDisplayTitle → get_field('weixiaoacg_title_zh') || $post->post_title
     - getJaTitle      → get_field('weixiaoacg_title_ja')
   ============================================================ */
'use strict';

/* ── 1. 顯示標題（繁中 > 使用者偏好 > 日文 > 羅馬拼音 > fallback）── */

/**
 * 取得動畫的繁體中文優先顯示標題。
 * 優先順序：
 *   1. anime.titleChinese  （Bangumi 搜回的繁中名）
 *   2. anime.title_zh      （ACF / WP 欄位）
 *   3. anime.displayName   （AniList userPreferred，通常是日文或英文）
 *   4. anime.titleNative   （日文原名）
 *   5. anime.titleRomaji   （羅馬拼音）
 *   6. '未知作品'
 *
 * @param {Object} anime  normalize 後的 AniList media 物件
 * @returns {string}
 */
function getDisplayTitle(anime) {
  if (!anime) return '未知作品';
  return (
    anime.titleChinese   ||  // Bangumi 繁中名
    anime.title_zh        ||  // WP ACF 欄位
    anime.displayName     ||  // AniList userPreferred
    anime.titleNative     ||  // 日文
    anime.titleRomaji     ||  // 羅馬拼音
    anime.titleEnglish    ||  // 英文（最低優先）
    '未知作品'
  );
}

/**
 * 取得日文原名（用於副標題）。
 * 優先順序：
 *   1. anime.titleNative  （AniList 日文原名）
 *   2. anime.title_ja     （WP ACF 欄位）
 *   3. ''                 （空字串，呼叫方自行判斷是否顯示）
 *
 * @param {Object} anime
 * @returns {string}
 */
function getJaTitle(anime) {
  if (!anime) return '';
  return anime.titleNative || anime.title_ja || '';
}

/**
 * 取得英文標題（僅 SEO 用途，不顯示在前端）。
 * @param {Object} anime
 * @returns {string}
 */
function getEnTitle(anime) {
  if (!anime) return '';
  return anime.titleEnglish || anime.titleRomaji || '';
}

/**
 * 取得封面圖片 URL。
 * @param {Object} anime
 * @param {'large'|'medium'|'xl'} size
 * @returns {string}
 */
function getCoverImage(anime, size = 'large') {
  if (!anime) return '';
  if (size === 'xl'    && anime.coverXl)     return anime.coverXl;
  if (size === 'large' && anime.coverLarge)  return anime.coverLarge;
  if (size === 'medium' && anime.coverMedium) return anime.coverMedium;
  return anime.coverLarge || anime.coverMedium || '';
}

/* ── 2. 翻譯函式 ───────────────────────────────────────────── */

/**
 * AniList status → 繁體中文
 * @param {string} status
 * @returns {string}
 */
function translateStatus(status) {
  const map = {
    FINISHED:         '已完結',
    RELEASING:        '連載中',
    NOT_YET_RELEASED: '尚未播出',
    CANCELLED:        '已取消',
    HIATUS:           '停播',
  };
  return map[status] || status || '—';
}

/**
 * AniList source → 繁體中文（19 種）
 * @param {string} source
 * @returns {string}
 */
function translateSource(source) {
  const map = {
    ORIGINAL:         '原創',
    MANGA:            '漫畫',
    LIGHT_NOVEL:      '輕小說',
    VISUAL_NOVEL:     '視覺小說',
    VIDEO_GAME:       '遊戲',
    OTHER:            '其他',
    NOVEL:            '小說',
    DOUJINSHI:        '同人誌',
    ANIME:            '動畫',
    WEB_NOVEL:        '網路小說',
    LIVE_ACTION:      '真人作品',
    GAME:             '遊戲',
    COMIC:            '漫畫',
    MULTIMEDIA_PROJECT: '多媒體企劃',
    PICTURE_BOOK:     '繪本',
    CARD_GAME:        '卡牌遊戲',
    MUSIC:            '音樂',
    FOUR_KOMA_MANGA:  '四格漫畫',
    BOOK:             '書籍',
  };
  return map[source] || source || '—';
}

/**
 * AniList season → 繁體中文
 * @param {string} season
 * @returns {string}
 */
function translateSeason(season) {
  const map = {
    WINTER: '冬季',
    SPRING: '春季',
    SUMMER: '夏季',
    FALL:   '秋季',
  };
  return map[season] || season || '—';
}

/* ── 3. 格式化函式 ─────────────────────────────────────────── */

/**
 * AniList 評分（0-100）→ 顯示用字串（8.5）
 * @param {number|null} n
 * @returns {string}
 */
function formatAniListScore(n) {
  if (!n || isNaN(n)) return '—';
  return (n / 10).toFixed(1);
}

/**
 * 大數字 → 中文單位（萬、千）
 * @param {number|null} n
 * @returns {string}
 */
function formatNumber(n) {
  if (!n && n !== 0) return '—';
  if (n >= 100_000_000) return (n / 100_000_000).toFixed(1) + '億';
  if (n >= 10_000)      return (n / 10_000).toFixed(1) + '萬';
  if (n >= 1_000)       return (n / 1_000).toFixed(1) + '千';
  return String(n);
}

/**
 * 日期物件 → 繁中格式（2025 年 1 月 5 日）
 * @param {{year?:number, month?:number, day?:number}|null} dateObj
 * @returns {string}
 */
function formatFuzzyDate(dateObj) {
  if (!dateObj || !dateObj.year) return '—';
  const y = dateObj.year;
  const m = dateObj.month ? `${dateObj.month} 月` : '';
  const d = dateObj.day   ? `${dateObj.day} 日`   : '';
  return `${y} 年 ${m} ${d}`.trim();
}

/* ── 4. 季節工具 ───────────────────────────────────────────── */

/**
 * 取得目前的季節與年份
 * @returns {{ season: 'WINTER'|'SPRING'|'SUMMER'|'FALL', year: number }}
 */
function getCurrentSeason() {
  const now   = new Date();
  const month = now.getMonth() + 1; // 1-12
  const year  = now.getFullYear();
  let season;
  if      (month <= 3)  season = 'WINTER';
  else if (month <= 6)  season = 'SPRING';
  else if (month <= 9)  season = 'SUMMER';
  else                  season = 'FALL';
  return { season, year };
}

/* ── 5. 卡片標題輔助（anime card 共用）──────────────────────── */

/**
 * 為動畫卡片設定顯示標題與日文副標題。
 * 會更新 .card-title 及 .card-subtitle（若存在）。
 *
 * @param {HTMLElement} cardEl   動畫卡片根元素
 * @param {Object}      anime    normalize 後的 media 物件
 */
function applyCardTitles(cardEl, anime) {
  if (!cardEl || !anime) return;
  const titleEl    = cardEl.querySelector('.card-title, .anime-title');
  const subtitleEl = cardEl.querySelector('.card-subtitle, .anime-subtitle, .card-original-title');

  if (titleEl) {
    titleEl.textContent = getDisplayTitle(anime);
    titleEl.setAttribute('lang', 'zh-TW');
  }

  const jaTitle = getJaTitle(anime);
  if (subtitleEl && jaTitle) {
    subtitleEl.textContent = jaTitle;
    subtitleEl.setAttribute('lang', 'ja');
    subtitleEl.style.display = '';
  } else if (subtitleEl) {
    subtitleEl.style.display = 'none';
  }
}

/* ── 6. 匯出（若在 module 環境）────────────────────────────── */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    getDisplayTitle, getJaTitle, getEnTitle, getCoverImage,
    translateStatus, translateSource, translateSeason,
    formatAniListScore, formatNumber, formatFuzzyDate,
    getCurrentSeason, applyCardTitles
  };
}

console.log('[Utils] 共用工具函式庫已載入（getDisplayTitle, getJaTitle, translateSource, formatNumber…）');
