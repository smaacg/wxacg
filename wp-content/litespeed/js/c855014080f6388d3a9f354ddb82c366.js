'use strict';function getDisplayTitle(anime){if(!anime)return'未知作品';return(anime.titleChinese||anime.title_zh||anime.displayName||anime.titleNative||anime.titleRomaji||anime.titleEnglish||'未知作品')}
function getJaTitle(anime){if(!anime)return'';return anime.titleNative||anime.title_ja||''}
function getEnTitle(anime){if(!anime)return'';return anime.titleEnglish||anime.titleRomaji||''}
function getCoverImage(anime,size='large'){if(!anime)return'';if(size==='xl'&&anime.coverXl)return anime.coverXl;if(size==='large'&&anime.coverLarge)return anime.coverLarge;if(size==='medium'&&anime.coverMedium)return anime.coverMedium;return anime.coverLarge||anime.coverMedium||''}
function translateStatus(status){const map={FINISHED:'已完結',RELEASING:'連載中',NOT_YET_RELEASED:'尚未播出',CANCELLED:'已取消',HIATUS:'停播',};return map[status]||status||'—'}
function translateSource(source){const map={ORIGINAL:'原創',MANGA:'漫畫',LIGHT_NOVEL:'輕小說',VISUAL_NOVEL:'視覺小說',VIDEO_GAME:'遊戲',OTHER:'其他',NOVEL:'小說',DOUJINSHI:'同人誌',ANIME:'動畫',WEB_NOVEL:'網路小說',LIVE_ACTION:'真人作品',GAME:'遊戲',COMIC:'漫畫',MULTIMEDIA_PROJECT:'多媒體企劃',PICTURE_BOOK:'繪本',CARD_GAME:'卡牌遊戲',MUSIC:'音樂',FOUR_KOMA_MANGA:'四格漫畫',BOOK:'書籍',};return map[source]||source||'—'}
function translateSeason(season){const map={WINTER:'冬季',SPRING:'春季',SUMMER:'夏季',FALL:'秋季',};return map[season]||season||'—'}
function formatAniListScore(n){if(!n||isNaN(n))return'—';return(n/10).toFixed(1)}
function formatNumber(n){if(!n&&n!==0)return'—';if(n>=100_000_000)return(n/100_000_000).toFixed(1)+'億';if(n>=10_000)return(n/10_000).toFixed(1)+'萬';if(n>=1_000)return(n/1_000).toFixed(1)+'千';return String(n)}
function formatFuzzyDate(dateObj){if(!dateObj||!dateObj.year)return'—';const y=dateObj.year;const m=dateObj.month?`${dateObj.month} 月`:'';const d=dateObj.day?`${dateObj.day} 日`:'';return `${y} 年 ${m} ${d}`.trim()}
function getCurrentSeason(){const now=new Date();const month=now.getMonth()+1;const year=now.getFullYear();let season;if(month<=3)season='WINTER';else if(month<=6)season='SPRING';else if(month<=9)season='SUMMER';else season='FALL';return{season,year}}
function applyCardTitles(cardEl,anime){if(!cardEl||!anime)return;const titleEl=cardEl.querySelector('.card-title, .anime-title');const subtitleEl=cardEl.querySelector('.card-subtitle, .anime-subtitle, .card-original-title');if(titleEl){titleEl.textContent=getDisplayTitle(anime);titleEl.setAttribute('lang','zh-TW')}
const jaTitle=getJaTitle(anime);if(subtitleEl&&jaTitle){subtitleEl.textContent=jaTitle;subtitleEl.setAttribute('lang','ja');subtitleEl.style.display=''}else if(subtitleEl){subtitleEl.style.display='none'}}
if(typeof module!=='undefined'&&module.exports){module.exports={getDisplayTitle,getJaTitle,getEnTitle,getCoverImage,translateStatus,translateSource,translateSeason,formatAniListScore,formatNumber,formatFuzzyDate,getCurrentSeason,applyCardTitles}}
console.log('[Utils] 共用工具函式庫已載入（getDisplayTitle, getJaTitle, translateSource, formatNumber…）')
;