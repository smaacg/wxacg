const OpenCCHelper=(()=>{let _converter=null;let _ready=!1;let _loading=!1;const _queue=[];async function init(){if(_ready)return;if(_loading)return new Promise(r=>_queue.push(r));_loading=!0;return new Promise((resolve)=>{const script=document.createElement('script');script.src='https://cdn.jsdelivr.net/npm/opencc-js@1.0.5/dist/umd/full.js';script.onload=()=>{try{_converter=OpenCC.Converter({from:'cn',to:'twp'})}catch(e){console.warn('OpenCC init failed:',e);_converter=null}
_ready=!0;_loading=!1;_queue.forEach(r=>r());_queue.length=0;resolve()};script.onerror=()=>{_ready=!0;_loading=!1;_queue.forEach(r=>r());resolve()};document.head.appendChild(script)})}
function convert(text){if(!text)return text;try{return _converter?_converter(text):text}catch{return text}}
return{init,convert}})();const AniListAPI=(()=>{const ENDPOINT='https://graphql.anilist.co';async function _query(query,variables={}){const res=await fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({query,variables}),});if(!res.ok)throw new Error(`AniList HTTP ${res.status}`);const json=await res.json();if(json.errors?.length)throw new Error(json.errors[0].message);return json.data}
async function getMedia(anilistId){const data=await _query(`
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
    `,{id:anilistId});return normalizeMedia(data.Media)}
async function searchMedia(title,limit=6){const data=await _query(`
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
    `,{search:title,perPage:limit});return(data.Page.media||[]).map(normalizeMediaLight)}
async function getSeasonalAnime(season,year,limit=20){const data=await _query(`
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
    `,{season,year,perPage:limit});return(data.Page.media||[]).map(normalizeMediaLight)}
async function getTopAnime(limit=20){const data=await _query(`
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
    `,{perPage:limit});return(data.Page.media||[]).map(normalizeMediaLight)}
function normalizeMedia(m){if(!m)return null;const startDate=m.startDate?`${m.startDate.year || ''}/${String(m.startDate.month||'').padStart(2,'0')}/${String(m.startDate.day||'').padStart(2,'0')}`:'';const statusDist={};for(const s of(m.stats?.statusDistribution||[])){statusDist[s.status]=s.amount||0}
const staffEdges=m.staff?.edges||[];const findStaff=(role)=>{const e=staffEdges.find(e=>e.role===role);return e?(e.node?.name?.full||e.node?.name?.native||''):''};return{id:m.id,idMal:m.idMal||null,titleChinese:'',titleEnglish:m.title?.english||'',titleRomaji:m.title?.romaji||'',titleNative:m.title?.native||'',displayName:m.title?.english||m.title?.romaji||'',coverLarge:m.coverImage?.extraLarge||m.coverImage?.large||'',coverColor:m.coverImage?.color||'#1E242B',bannerImage:m.bannerImage||'',description:(m.description||'').replace(/<[^>]*>/g,'').trim(),averageScore:m.averageScore||0,meanScore:m.meanScore||0,popularity:m.popularity||0,favourites:m.favourites||0,trending:m.trending||0,season:m.season||'',seasonYear:m.seasonYear||'',status:m.status||'',episodes:m.episodes||0,duration:m.duration||0,genres:m.genres||[],source:m.source||'',isAdult:m.isAdult||!1,studios:(m.studios?.nodes||[]).map(s=>s.name),startDate,endDate:m.endDate?`${m.endDate.year || ''}/${String(m.endDate.month||'').padStart(2,'0')}/${String(m.endDate.day||'').padStart(2,'0')}`:'',nextAiring:m.nextAiringEpisode||null,siteUrl:m.siteUrl||'',trailer:m.trailer||null,externalLinks:m.externalLinks||[],streamingEpisodes:m.streamingEpisodes||[],tags:(m.tags||[]).filter(t=>!t.isMediaSpoiler&&t.rank>=60).slice(0,6).map(t=>t.name),statusWatching:statusDist.CURRENT||0,statusCompleted:statusDist.COMPLETED||0,statusDropped:statusDist.DROPPED||0,statusPlanning:statusDist.PLANNING||0,rankings:m.rankings||[],charactersRaw:(m.characters?.edges||[]).map(e=>({id:e.node?.id||0,name:e.node?.name?.full||'',nameJp:e.node?.name?.native||'',image:e.node?.image?.medium||'',role:e.role==='MAIN'?'主角':e.role==='SUPPORTING'?'配角':'背景',va:e.voiceActors?.[0]?.name?.full||'',vaImg:e.voiceActors?.[0]?.image?.medium||'',})),director:findStaff('Director')||findStaff('Chief Director')||'',author:findStaff('Original Creator')||findStaff('Original Story')||'',}}
function normalizeMediaLight(m){return{id:m.id,idMal:m.idMal||null,titleChinese:'',titleEnglish:m.title?.english||'',titleRomaji:m.title?.romaji||'',titleNative:m.title?.native||'',displayName:m.title?.native||m.title?.romaji||m.title?.english||'',coverLarge:m.coverImage?.large||'',coverColor:m.coverImage?.color||'#1E242B',averageScore:m.averageScore||0,episodes:m.episodes||0,status:m.status||'',popularity:m.popularity||0,season:m.season||'',seasonYear:m.seasonYear||'',genres:m.genres||[],studios:(m.studios?.nodes||[]).map(s=>s.name),nextAiring:m.nextAiringEpisode||null,}}
function getCurrentSeason(){const month=new Date().getMonth()+1;const year=new Date().getFullYear();const season=month<=3?'WINTER':month<=6?'SPRING':month<=9?'SUMMER':'FALL';return{season,year}}
function formatAniListScore(score){if(!score)return'–';return(score/10).toFixed(1)}
return{getMedia,searchMedia,getSeasonalAnime,getTopAnime,getCurrentSeason,formatAniListScore,}})();function translateSource(source){if(!source)return'—';const SOURCE_MAP={'ORIGINAL':'原創','MANGA':'漫畫改編','LIGHT_NOVEL':'輕小說改編','VISUAL_NOVEL':'視覺小說改編','VIDEO_GAME':'電子遊戲改編','OTHER':'其他','NOVEL':'小說改編','DOUJINSHI':'同人誌改編','ANIME':'動畫改編','WEB_NOVEL':'網路小說改編','LIVE_ACTION':'真人影視改編','GAME':'遊戲改編','COMIC':'漫畫改編','MULTIMEDIA_PROJECT':'多媒體企劃','PICTURE_BOOK':'繪本改編','FOUR_KOMA':'四格漫畫改編','CARD_GAME':'卡牌遊戲改編','MUSIC':'音樂企劃',};return SOURCE_MAP[source]||source}
function formatNumber(num){if(!num&&num!==0)return'—';if(num===0)return'0';if(num>=100000000)return `${(num / 100000000).toFixed(1)}億`;if(num>=10000)return `${(num / 10000).toFixed(1)}萬`;if(num>=1000)return `${(num / 1000).toFixed(1)}千`;return String(num)}
function fillSidebarData(media){if(!media)return;const fieldMap={'favourites':()=>formatNumber(media.favourites),'popularity':()=>formatNumber(media.popularity),'watching':()=>formatNumber(media.statusWatching),'completed':()=>formatNumber(media.statusCompleted),'episodes':()=>media.episodes?`${media.episodes} 集`:'—','duration':()=>media.duration?`${media.duration} 分鐘`:'—','year':()=>media.seasonYear?String(media.seasonYear):'—','source':()=>translateSource(media.source),'studio':()=>(media.studios||[])[0]||'—','author':()=>media.author||'—','director':()=>media.director||'—','comm-watching':()=>formatNumber(media.statusWatching||media.popularity),'comm-favourites':()=>formatNumber(media.favourites),'comm-score':()=>media.averageScore?(media.averageScore/10).toFixed(1):'—',};document.querySelectorAll('[data-field]').forEach(el=>{const field=el.dataset.field;if(field&&fieldMap[field]){const val=fieldMap[field]();el.textContent=val;el.classList.remove('skeleton-loading')}});document.querySelectorAll('.stats-item-num.skeleton-loading, .quick-info-val.skeleton-loading').forEach(el=>{el.classList.remove('skeleton-loading')})}
const BangumiAPI=(()=>{const BASE='https://api.bgm.tv';const BASE_V0='https://api.bgm.tv/v0';let _queue=Promise.resolve();function _req(url,opts={}){const task=_queue.then(()=>fetch(url,{headers:{'Accept':'application/json','User-Agent':'weixiaoacg/1.0',...opts.headers},...opts,}).then(r=>r.ok?r.json():Promise.reject(new Error(`Bgm HTTP ${r.status}`))));_queue=task.catch(()=>null);return task}
function toHttps(url){return url?url.replace(/^http:\/\//i,'https://'):''}
async function searchByTitle(title,limit=3){const encoded=encodeURIComponent(title);const data=await _req(`${BASE}/search/subject/${encoded}?type=2&responseGroup=small&max_results=${limit}`);return(data?.list||[]).map(s=>({id:s.id,name:s.name||'',nameCn:s.name_cn||s.name,image:toHttps(s.images?.large||''),score:s.rating?.score||0,airDate:s.air_date||'',}))}
async function getSubjectById(id){const s=await _req(`${BASE_V0}/subjects/${id}`);const info=_parseInfobox(s.infobox||[]);return{id:s.id,name:s.name||'',nameCn:s.name_cn||s.name,summary:s.summary||'',image:toHttps(s.images?.large||''),score:s.rating?.score||0,total:s.rating?.total||0,airDate:s.date||'',eps:s.eps||0,platform:s.platform||'',tags:(s.tags||[]).slice(0,8).map(t=>t.name),studio:info.studio||info.動畫製作||'',director:info.director||info.導演||'',music:info.music||info.音樂||'',source:info.source||info.原作||'',channel:info.channel||info.放送電視台||'',duration:info.duration||info.每集長度||'',}}
async function getCharacters(id){const data=await _req(`${BASE_V0}/subjects/${id}/characters`);return(data||[]).slice(0,12).map(c=>({id:c.id,name:c.name||'',nameCn:c.name_cn||c.name,image:toHttps(c.images?.large||c.images?.medium||''),relation:c.relation||'',actors:(c.actors||[]).map(a=>({id:a.id,name:a.name,image:toHttps(a.images?.medium||''),})),}))}
async function getEpisodes(id,limit=100){const data=await _req(`${BASE_V0}/episodes?subject_id=${id}&limit=${limit}`);return(data?.data||[]).filter(e=>e.type===0).map(e=>({id:e.id,ep:e.ep||0,name:e.name||'',nameCn:e.name_cn||e.name||'',airDate:e.airdate||'',duration:e.duration||0,desc:e.desc||'',}))}
async function getRelations(id){const data=await _req(`${BASE_V0}/subjects/${id}/subjects`);return(data||[]).filter(r=>r.type===2).slice(0,6).map(r=>({id:r.id,name:r.name||'',nameCn:r.name_cn||r.name,displayName:r.name_cn||r.name,image:toHttps(r.images?.large||''),relation:r.relation||'',}))}
async function getCalendar(){const data=await _req(`${BASE}/calendar`);const WEEKDAY_ZH={1:'星期一',2:'星期二',3:'星期三',4:'星期四',5:'星期五',6:'星期六',7:'星期日'};return(data||[]).map(group=>({weekday:group.weekday?.id||0,weekdayZh:group.weekday?.cn||WEEKDAY_ZH[group.weekday?.id]||'其他',items:(group.items||[]).map(s=>({id:s.id,name:s.name||'',nameCn:s.name_cn||s.name,displayName:s.name_cn||s.name,image:toHttps(s.images?.large||''),score:s.rating?.score||0,doing:s.collection?.doing||0,airDate:s.air_date||'',airWeekday:s.air_weekday||0,})),}))}
function _parseInfobox(infobox){const result={};for(const item of infobox){const val=Array.isArray(item.value)?item.value.map(v=>typeof v==='object'?v.v:v).join('、'):item.value;result[item.key]=val;const keyMap={'動畫製作':'studio','導演':'director','音樂':'music','原作':'source','放送電視台':'channel','每集長度':'duration',};if(keyMap[item.key])result[keyMap[item.key]]=val}
return result}
function formatScore(score){return(!score||score===0)?'–':parseFloat(score).toFixed(1)}
function formatCount(n){if(!n)return'–';if(n>=10000)return `${(n/10000).toFixed(1)}萬`;if(n>=1000)return `${(n/1000).toFixed(1)}K`;return String(n)}
function getBgmUrl(id){return `https://bgm.tv/subject/${id}`}
const WEEKDAY_ZH={1:'星期一',2:'星期二',3:'星期三',4:'星期四',5:'星期五',6:'星期六',7:'星期日'};async function getCurrentSeason(limit=20){const calendar=await getCalendar();const all=[];for(const group of calendar)all.push(...group.items);return all.filter(a=>a.doing>0).sort((a,b)=>b.doing-a.doing).slice(0,limit)}
async function searchAnime(keyword,limit=6){const encoded=encodeURIComponent(keyword);const data=await _req(`${BASE}/search/subject/${encoded}?type=2&responseGroup=small&max_results=${limit}`);return(data?.list||[]).map(s=>({id:s.id,name:s.name||'',nameCn:s.name_cn||s.name,displayName:s.name_cn||s.name,image:toHttps(s.images?.large||''),score:s.rating?.score||0,airDate:s.air_date||'',doing:0,tags:[],}))}
async function getTopAnime(limit=12){try{const res=await fetch(`${BASE_V0}/search/subjects`,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','User-Agent':'weixiaoacg/1.0'},body:JSON.stringify({type:2,sort:'rank',filter:{type:[2]}}),});if(!res.ok)throw new Error('BGM search '+res.status);const data=await res.json();return(data?.data||[]).slice(0,limit).map(s=>({id:s.id,name:s.name||'',nameCn:s.name_cn||s.name,displayName:s.name_cn||s.name,image:toHttps(s.images?.large||''),score:s.rating?.score||0,doing:0,airDate:s.date||'',tags:(s.tags||[]).slice(0,3).map(t=>t.name),}))}catch{return[]}}
return{searchByTitle,getSubjectById,getCharacters,getEpisodes,getRelations,getCalendar,getCurrentSeason,searchAnime,getTopAnime,formatScore,formatCount,getBgmUrl,WEEKDAY_ZH,}})();const JikanAPI=(()=>{const BASE='https://api.jikan.moe/v4';let _queue=Promise.resolve();function _req(url){_queue=_queue.then(()=>new Promise(r=>setTimeout(r,350)).then(()=>fetch(url).then(r=>r.ok?r.json():Promise.reject(new Error(`Jikan HTTP ${r.status} ${url}`)))));return _queue}
async function getAnimeById(malId){const data=await _req(`${BASE}/anime/${malId}/full`);const a=data.data;if(!a)return null;return{malId:a.mal_id,score:a.score||0,scoredBy:a.scored_by||0,rank:a.rank||0,popularity:a.popularity||0,members:a.members||0,favorites:a.favorites||0,coverLarge:a.images?.jpg?.large_image_url||a.images?.jpg?.image_url||'',streaming:(a.streaming||[]).map(s=>({name:s.name,url:s.url})),external:(a.external||[]).map(e=>({name:e.name,url:e.url})),trailerUrl:a.trailer?.url||'',trailerEmbedUrl:a.trailer?.embed_url||'',broadcast:a.broadcast||null,rating:a.rating||'',}}
async function getCharacters(malId){const data=await _req(`${BASE}/anime/${malId}/characters`);return(data.data||[]).slice(0,12).map(c=>({id:c.character.mal_id,name:c.character.name,image:c.character.images?.jpg?.image_url||'',role:c.role==='Main'?'主角':c.role==='Supporting'?'配角':c.role,va:c.voice_actors?.find(v=>v.language==='Japanese')?.person?.name||'',vaImg:c.voice_actors?.find(v=>v.language==='Japanese')?.person?.images?.jpg?.image_url||'',}))}
async function searchAnime(title,limit=5){const data=await _req(`${BASE}/anime?q=${encodeURIComponent(title)}&limit=${limit}&type=tv`);return(data.data||[]).map(a=>({malId:a.mal_id,title:a.title,image:a.images?.jpg?.large_image_url||'',score:a.score||0,}))}
async function getBangumiId(malId){if(!malId)return null;try{const res=await fetch(`${BASE}/anime/${malId}/external`);if(!res.ok)return null;const data=await res.json();const bgmLink=(data.data||[]).find(e=>e.name==='Bangumi'||/bangumi\.tv\/subject\//i.test(e.url)||/bgm\.tv\/subject\//i.test(e.url));if(!bgmLink)return null;const match=bgmLink.url.match(/subject\/(\d+)/);return match?parseInt(match[1]):null}catch{return null}}
function formatScore(score){return score?parseFloat(score).toFixed(2):'–'}
function formatCount(n){if(!n)return'–';if(n>=10000000)return `${(n/10000000).toFixed(1)}千萬`;if(n>=100000)return `${(n/10000).toFixed(0)}萬`;if(n>=10000)return `${(n/10000).toFixed(1)}萬`;if(n>=1000)return `${(n/1000).toFixed(1)}K`;return String(n)}
return{getAnimeById,getCharacters,searchAnime,getBangumiId,formatScore,formatCount}})();function isTitleMatch(query,nameJp,nameCn){if(!query)return!1;const normalize=s=>(s||'').replace(/[\uff01-\uff5e]/g,c=>String.fromCharCode(c.charCodeAt(0)-0xFEE0)).replace(/\u3000/g,' ').toLowerCase().replace(/[\s\-\u2013\u2014\uff1a:\u00b7\u30fb\uff5e~\u266a\u2661\u2605\u2606!\uff01?\uff1f\u3002\u3001,\uff0c.\u2026\/\\]+/g,'').replace(/^the\s*/i,'');const q=normalize(query);const nj=normalize(nameJp||'');const nc=normalize(nameCn||'');if(!q||(!nj&&!nc))return!1;if(nj===q||nc===q)return!0;if(nj&&(nj.includes(q)||q.includes(nj)))return!0;if(nc&&(nc.includes(q)||q.includes(nc)))return!0;const _charOverlap=(a,b)=>{if(!a||!b)return 0;const shorter=a.length<=b.length?a:b;const longer=a.length<=b.length?b:a;let matched=0;for(const ch of shorter)if(longer.includes(ch))matched++;return matched/shorter.length};if(nj&&_charOverlap(q,nj)>=0.80)return!0;if(nc&&_charOverlap(q,nc)>=0.80)return!0;return!1}
;