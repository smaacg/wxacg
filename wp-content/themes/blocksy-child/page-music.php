<?php
/**
 * Template Name: 動漫音樂中心（Music Hub）
 * Version     : 2.5.0
 * Path        : blocksy-child/page-music.php
 * 佈局：左主欄（Hero輪播 + 雙榜）／右側欄（演唱會 + MV）
 */

if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

/* ===== 載入資料 ===== */
$chart  = function_exists( 'wxmc_build_chinese_chart' ) ? wxmc_build_chinese_chart( 50 ) : [];

$season_data  = function_exists( 'wxmc_build_season_chart' ) ? wxmc_build_season_chart( 50 ) : [];
$season_songs = ! empty( $season_data['songs'] ) ? $season_data['songs'] : [];

// Hero 輪播：新番榜 + 華人榜合併，去重後隨機抽 6 張，每次進頁都不同
$hero_pool = array_merge( $season_songs, $chart );
$hero_uniq = [];
foreach ( $hero_pool as $hs ) {
    $key = ! empty( $hs['audio_url'] ) ? $hs['audio_url'] : ( $hs['song'] . '|' . $hs['anime_title'] );
    if ( ! isset( $hero_uniq[ $key ] ) ) $hero_uniq[ $key ] = $hs;
}
$hero_pool = array_values( $hero_uniq );
shuffle( $hero_pool );
$heroes = array_slice( $hero_pool, 0, 6 );

if ( ! empty( $season_data['label'] ) ) {
    $season_label = $season_data['label'];
} else {
    $season_map = [ 'WINTER'=>'冬季','SPRING'=>'春季','SUMMER'=>'夏季','FALL'=>'秋季' ];
    $season_label = '';
    if ( ! empty( $season_data['season'] ) ) {
        $s_name = $season_map[ $season_data['season'] ] ?? $season_data['season'];
        $season_label = $season_data['year'] . ' ' . $s_name;
    }
}

$mvs      = function_exists( 'wxmc_get_mvs' )      ? wxmc_get_mvs( 6 )      : [];
$concerts = function_exists( 'wxmc_get_concerts' ) ? wxmc_get_concerts( 6 ) : [];

$stat_anime_count = 0; $stat_song_count = 0;
$anime_ids = get_posts([
    'post_type'=>'anime','posts_per_page'=>-1,'post_status'=>'publish','fields'=>'ids',
    'meta_query'=>[[ 'key'=>'anime_themes','compare'=>'EXISTS' ]],
]);
$stat_anime_count = count( $anime_ids );
foreach ( $anime_ids as $aid ) {
    $themes = json_decode( (string) get_post_meta( $aid, 'anime_themes', true ), true );
    if ( is_array( $themes ) ) $stat_song_count += count( $themes );
}

/* 榜單單列 */
if ( ! function_exists( 'wxmc_render_row' ) ) {
    function wxmc_render_row( $s, $rank ) {
        $type_class = strtolower( $s['type'] ) === 'ed' ? 'ed' : 'op';
        ?>
        <div class="oped-item">
          <div class="oped-num <?php echo esc_attr($type_class); ?>"><?php echo (int)$rank; ?></div>
          <?php if ( ! empty($s['cover']) ) : ?>
            <div class="oped-cover-placeholder" style="background-image:url('<?php echo esc_url($s['cover']); ?>');background-size:cover;background-position:center;"></div>
          <?php else : ?>
            <div class="oped-cover-placeholder">🎵</div>
          <?php endif; ?>
          <div class="oped-info">
            <div class="oped-song"><?php echo esc_html($s['song']); ?></div>
            <div class="oped-artist"><?php echo esc_html($s['artist'] ?: '—'); ?> · <a href="<?php echo esc_url($s['anime_url']); ?>"><?php echo esc_html($s['anime_title']); ?></a></div>
          </div>
          <span class="oped-tag <?php echo esc_attr($type_class); ?>"><?php echo esc_html( $s['type'] ?: 'OP' ); ?></span>
          <?php if ( ! empty($s['audio_url']) ) : ?>
            <button class="oped-play wxmc-play" data-audio="<?php echo esc_url($s['audio_url']); ?>" aria-label="試聽"><i class="fa-solid fa-play"></i></button>
          <?php endif; ?>
        </div>
        <?php
    }
}
?>

<main id="music-page" class="music-page">

  <!-- 頁面標頭 -->
  <section class="music-page-hero">
    <div class="container music-page-hero__inner">
      <div class="mph__head">
        <div class="mph__icon">🎵</div>
        <div>
          <div class="mph__eyebrow">ANIME MUSIC RANKING</div>
          <h1 class="mph__title">動漫歌曲排行榜</h1>
        </div>
      </div>
      <p class="mph__desc">動漫迷精選 OP／ED 主題曲人氣排名，依作品人氣與 Bangumi 評分綜合排序，每首可試聽並連結作品頁</p>
    </div>
  </section>

  <div class="music-page-wrapper">

    <?php if ( empty($chart) && empty($season_songs) ) : ?>
      <div class="music-section-card"><p style="padding:24px;text-align:center;">目前尚無榜單資料。</p></div>
    <?php else : ?>

    <!-- 大佈局：左主欄 + 右側欄 -->
    <div class="music-grid">

      <!-- ===================== 左主欄 ===================== -->
      <div class="music-main">

        <!-- Hero 輪播 -->
        <?php if ( ! empty($heroes) ) : ?>
        <div class="music-hero-carousel" id="wxmc-hero">
          <?php foreach ( $heroes as $i => $h ) : ?>
          <div class="music-hero hero-slide<?php echo $i===0?' is-active':''; ?>" data-index="<?php echo $i; ?>">
            <?php if ( ! empty($h['cover']) ) : ?>
              <div class="music-hero__bg" style="background-image:url('<?php echo esc_url($h['cover']); ?>');"></div>
            <?php endif; ?>
            <div class="music-hero__overlay"></div>
            <div class="music-hero__content">
              <div class="music-hero__eyebrow">
                <span class="music-hero__badge"><i class="fa-solid fa-star"></i> 精選推薦</span>
                <span class="music-hero__badge live"><i class="fa-solid fa-fire"></i> 熱門推薦</span>
              </div>
              <h2 class="music-hero__title"><?php echo esc_html($h['song']); ?></h2>
              <div class="music-hero__artist"><?php echo esc_html($h['artist'] ?: '—'); ?></div>
              <div class="music-hero__meta">
                <span class="music-hero__source"><?php echo esc_html( $h['type'] ?: 'OP' ); ?></span>
                <a class="music-hero__type" href="<?php echo esc_url($h['anime_url']); ?>"><i class="fa-solid fa-tv"></i> <?php echo esc_html($h['anime_title']); ?></a>
              </div>
            </div>
            <?php if ( ! empty($h['audio_url']) ) : ?>
            <div class="music-hero__controls">
              <button class="music-ctrl-btn play wxmc-play" data-audio="<?php echo esc_url($h['audio_url']); ?>" aria-label="試聽"><i class="fa-solid fa-play"></i></button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <!-- 輪播圓點 -->
          <div class="hero-dots">
            <?php foreach ( $heroes as $i => $h ) : ?>
              <button class="hero-dot<?php echo $i===0?' is-active':''; ?>" data-go="<?php echo $i; ?>" aria-label="第<?php echo $i+1; ?>張"></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- 雙榜並排 -->
        <div class="music-two-col">
          <div class="music-col">
            <?php if ( ! empty($season_songs) ) : ?>
            <div class="music-section-card">
              <div class="msc-header">
                <div class="msc-title"><i class="fa-solid fa-seedling"></i> <?php echo esc_html($season_label); ?>新番榜 TOP <?php echo count($season_songs); ?></div>
                <span class="msc-link">本季新番</span>
              </div>
              <div class="oped-list"><?php foreach ($season_songs as $idx=>$s) wxmc_render_row($s,$idx+1); ?></div>
            </div>
            <?php endif; ?>
          </div>
          <div class="music-col">
            <?php if ( ! empty($chart) ) : ?>
            <div class="music-section-card">
              <div class="msc-header">
                <div class="msc-title"><i class="fa-solid fa-ranking-star"></i> 華人動漫歌曲人氣榜 TOP <?php echo count($chart); ?></div>
                <span class="msc-link">人氣 + 評分綜合</span>
              </div>
              <div class="oped-list"><?php foreach ($chart as $idx=>$s) wxmc_render_row($s,$idx+1); ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- 統計列 -->
        <div class="music-section-card" style="margin-top:16px;">
          <div class="music-stats-strip">
            <div class="ms-stat"><i class="fa-solid fa-tv"></i><span class="ms-stat-num"><?php echo number_format($stat_anime_count); ?></span><span class="ms-stat-label">收錄動畫</span></div>
            <div class="ms-stat"><i class="fa-solid fa-music"></i><span class="ms-stat-num"><?php echo number_format($stat_song_count); ?></span><span class="ms-stat-label">收錄 OP/ED</span></div>
            <div class="ms-stat"><i class="fa-solid fa-ranking-star"></i><span class="ms-stat-num"><?php echo count($chart); ?></span><span class="ms-stat-label">華人榜曲目</span></div>
          </div>
        </div>

      </div><!-- /music-main -->

      <!-- ===================== 右側欄 ===================== -->
      <aside class="music-side">

        <!-- 演唱會 -->
        <div class="music-section-card">
          <div class="msc-header">
            <div class="msc-title"><i class="fa-solid fa-ticket"></i> 排隊等演唱會</div>
          </div>
          <?php if ( ! empty($concerts) ) : ?>
          <div class="concert-list">
            <?php foreach ($concerts as $c) : ?>
              <div class="concert-item">
                <?php if ( ! empty($c['cover']) ) : ?>
                  <div class="concert-cover" style="background-image:url('<?php echo esc_url($c['cover']); ?>');"></div>
                <?php else : ?>
                  <div class="concert-cover concert-cover--empty">🎪</div>
                <?php endif; ?>
                <div class="concert-info">
                  <div class="concert-title"><?php echo esc_html($c['title']); ?></div>
                  <?php if ( ! empty($c['date']) ) : ?>
                    <div class="concert-date-line"><i class="fa-solid fa-calendar-day"></i> <?php echo esc_html($c['date']); ?></div>
                  <?php endif; ?>
                  <div class="concert-sub"><?php if ($c['artist']) echo esc_html($c['artist']).' · '; echo esc_html($c['place'] ?: ''); ?></div>
                </div>
                <?php if ( ! empty($c['ticket']) ) : ?>
                  <a class="concert-btn" href="<?php echo esc_url($c['ticket']); ?>" target="_blank" rel="noopener">購票</a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else : ?>
            <p style="padding:16px;text-align:center;color:#888;font-size:13px;">目前尚無演唱會情報。</p>
          <?php endif; ?>
        </div>

        <!-- MV -->
        <div class="music-section-card" style="margin-top:16px;">
          <div class="msc-header">
            <div class="msc-title"><i class="fa-solid fa-clapperboard"></i> MV精選</div>
          </div>
          <?php if ( ! empty($mvs) ) : ?>
          <div class="mv-grid-side">
            <?php foreach ($mvs as $mv) : ?>
              <div class="mv-card wxmc-mv" data-yt="<?php echo esc_attr($mv['yt_id']); ?>" role="button" tabindex="0">
                <div class="mv-thumb" style="background-image:url('<?php echo esc_url($mv['thumb']); ?>');">
                  <span class="mv-play"><i class="fa-solid fa-play"></i></span>
                </div>
                <div class="mv-meta">
                  <div class="mv-title"><?php echo esc_html($mv['title']); ?></div>
                  <div class="mv-sub"><?php echo esc_html($mv['artist'] ?: '—'); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else : ?>
            <p style="padding:16px;text-align:center;color:#888;font-size:13px;">目前尚無 MV 資料。</p>
          <?php endif; ?>
        </div>

      </aside><!-- /music-side -->

    </div><!-- /music-grid -->

    <p style="text-align:center;color:#888;font-size:13px;margin:16px 0 32px;">
      榜單依作品人氣與 Bangumi 評分綜合排序，試聽音源由 AnimeThemes 提供。
    </p>

    <?php endif; ?>

  </div><!-- /music-page-wrapper -->
</main>

<!-- 試聽播放器 -->
<audio id="wxmc-audio" style="display:none"></audio>

<!-- MV 播放彈窗 -->
<div id="wxmc-mv-modal" class="wxmc-mv-modal" aria-hidden="true">
  <div class="wxmc-mv-backdrop"></div>
  <div class="wxmc-mv-box">
    <button class="wxmc-mv-close" aria-label="關閉">&times;</button>
    <div class="wxmc-mv-frame" id="wxmc-mv-frame"></div>
  </div>
</div>

<script>
(function(){
  /* 試聽播放器 */
  var audio = document.getElementById('wxmc-audio'), current=null;
  document.querySelectorAll('.wxmc-play').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var src=this.getAttribute('data-audio'), icon=this.querySelector('i');
      if(current===this && !audio.paused){ audio.pause(); icon.className='fa-solid fa-play'; return; }
      document.querySelectorAll('.wxmc-play i').forEach(function(i){ i.className='fa-solid fa-play'; });
      audio.src=src;
      audio.play().then(function(){ icon.className='fa-solid fa-pause'; current=btn; })
                  .catch(function(){ icon.className='fa-solid fa-play'; });
    });
  });
  audio.addEventListener('ended', function(){
    document.querySelectorAll('.wxmc-play i').forEach(function(i){ i.className='fa-solid fa-play'; });
    current=null;
  });

  /* Hero 輪播 */
  var hero=document.getElementById('wxmc-hero');
  if(hero){
    var slides=hero.querySelectorAll('.hero-slide');
    var dots=hero.querySelectorAll('.hero-dot');
    var idx=0, timer=null;
    function go(n){
      slides[idx].classList.remove('is-active');
      if(dots[idx]) dots[idx].classList.remove('is-active');
      idx=(n+slides.length)%slides.length;
      slides[idx].classList.add('is-active');
      if(dots[idx]) dots[idx].classList.add('is-active');
    }
    function next(){ go(idx+1); }
    function start(){ timer=setInterval(next,5000); }
    function stop(){ clearInterval(timer); }
    dots.forEach(function(d){
      d.addEventListener('click',function(){ stop(); go(parseInt(this.getAttribute('data-go'),10)); start(); });
    });
    hero.addEventListener('mouseenter',stop);
    hero.addEventListener('mouseleave',start);
    if(slides.length>1) start();
  }

  /* MV 彈窗播放 */
  var modal   = document.getElementById('wxmc-mv-modal');
  var frame   = document.getElementById('wxmc-mv-frame');
  var closeBtn= modal ? modal.querySelector('.wxmc-mv-close') : null;
  var backdrop= modal ? modal.querySelector('.wxmc-mv-backdrop') : null;
  function mvOpen(id){
    if(!id) return;
    frame.innerHTML = '<iframe width="100%" height="100%" '
      + 'src="https://www.youtube.com/embed/' + id + '?autoplay=1&rel=0" '
      + 'title="MV" frameborder="0" '
      + 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
      + 'allowfullscreen></iframe>';
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
  }
  function mvClose(){
    if(!modal) return;
    frame.innerHTML='';
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
  }
  document.querySelectorAll('.wxmc-mv').forEach(function(card){
    card.addEventListener('click', function(){ mvOpen(this.getAttribute('data-yt')); });
    card.addEventListener('keydown', function(e){
      if(e.key==='Enter'||e.key===' '){ e.preventDefault(); mvOpen(this.getAttribute('data-yt')); }
    });
  });
  if(closeBtn) closeBtn.addEventListener('click', mvClose);
  if(backdrop) backdrop.addEventListener('click', mvClose);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') mvClose(); });
})();
</script>

<?php get_footer(); ?>
