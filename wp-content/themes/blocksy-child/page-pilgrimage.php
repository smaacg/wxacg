<?php
/**
 * Template Name: 聖地巡禮（Pilgrimage Hub）
 * Description : 動漫聖地巡禮頁面 — 精選景點、地區探索、攻略文章
 * Version     : 1.0.0  (2026-05-16)
 *
 * Path: blocksy-child/page-pilgrimage.php
 *
 * 使用方式：
 *   1. WP 後台 → 頁面 → 新增頁面
 *   2. 標題：聖地巡禮
 *   3. Slug：pilgrimage
 *   4. 範本：聖地巡禮（Pilgrimage Hub）
 *   5. 對應 CSS：blocksy-child/assets/css/pilgrimage.css
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

/* ============================================================
   示範資料（之後可改成 WP_Query / CPT pilgrimage）
   ============================================================ */

// Hero 統計
$hero_stats = [
    [ 'icon'=>'fa-map-pin',   'text'=>'已收錄 342 個景點' ],
    [ 'icon'=>'fa-book-open', 'text'=>'87 篇攻略' ],
    [ 'icon'=>'fa-globe',     'text'=>'覆蓋 12 個縣市' ],
];

// 精選景點（大卡 4 張）
$featured_list = [
    [
        'emoji'   => '⛩️',
        'grad'    => 'linear-gradient(135deg,rgba(82,214,138,0.2),rgba(108,99,255,0.2))',
        'badge'   => '✨ 精選',
        'region'  => '京都',
        'title'   => '伏見稻荷大社 千本鳥居',
        'anime'   => '《神隱少女》主要場景原型',
        'address' => '京都市伏見區深草藪之內町 68',
        'visits'  => '8,240',
    ],
    [
        'emoji'   => '🏫',
        'grad'    => 'linear-gradient(135deg,rgba(111,236,255,0.2),rgba(108,99,255,0.2))',
        'badge'   => '🔥 熱門',
        'region'  => '東京',
        'title'   => '豐島區立西巣鴨中学校',
        'anime'   => '《你的名字》場景原型',
        'address' => '東京都豐島區西巣鴨 3-8-22',
        'visits'  => '12,640',
    ],
    [
        'emoji'   => '🌊',
        'grad'    => 'linear-gradient(135deg,rgba(255,107,174,0.2),rgba(168,85,247,0.2))',
        'badge'   => '✨ 精選',
        'region'  => '北海道',
        'title'   => '函館山夜景展望台',
        'anime'   => '《冰上的尤里》最終話場景',
        'address' => '北海道函館市函館山',
        'visits'  => '5,820',
    ],
    [
        'emoji'   => '🏙️',
        'grad'    => 'linear-gradient(135deg,rgba(255,165,0,0.2),rgba(255,107,107,0.15))',
        'badge'   => '🔥 熱門',
        'region'  => '東京',
        'title'   => '秋葉原電器街 中央通',
        'anime'   => '多部動漫 ACG 場景集中地',
        'address' => '東京都千代田區外神田 1 丁目',
        'visits'  => '24,100',
    ],
];

// 地區篩選
$region_tabs = [
    [ 'slug'=>'all',      'label'=>'🗾 全部',  'active'=>true ],
    [ 'slug'=>'tokyo',    'label'=>'🗼 東京' ],
    [ 'slug'=>'kyoto',    'label'=>'⛩️ 京都' ],
    [ 'slug'=>'osaka',    'label'=>'🏯 大阪' ],
    [ 'slug'=>'hokkaido', 'label'=>'❄️ 北海道' ],
    [ 'slug'=>'aichi',    'label'=>'🏭 愛知' ],
];

// 地區小卡（4 張）
$mini_list = [
    [ 'region'=>'tokyo','emoji'=>'🌉','grad'=>'linear-gradient(135deg,rgba(108,99,255,0.2),rgba(10,10,15,0.8))','title'=>'新宿御苑','sub'=>'東京・《言葉之庭》' ],
    [ 'region'=>'kyoto','emoji'=>'🏯','grad'=>'linear-gradient(135deg,rgba(255,107,174,0.2),rgba(10,10,15,0.8))','title'=>'東寺 五重塔','sub'=>'京都・《空之境界》' ],
    [ 'region'=>'aichi','emoji'=>'🏙️','grad'=>'linear-gradient(135deg,rgba(82,214,138,0.2),rgba(10,10,15,0.8))','title'=>'名古屋電視塔','sub'=>'愛知・《Angel Beats》' ],
    [ 'region'=>'osaka','emoji'=>'🎡','grad'=>'linear-gradient(135deg,rgba(255,193,7,0.2),rgba(10,10,15,0.8))','title'=>'大阪城天守閣','sub'=>'大阪・《咒術迴戰》' ],
];

// 攻略文章
$guide_list = [
    [
        'emoji'   => '🗾',
        'grad'    => 'linear-gradient(135deg,rgba(82,214,138,0.2),rgba(108,99,255,0.15))',
        'title'   => '《你的名字》東京聖地巡禮完全攻略：10 個必訪景點 + 交通路線',
        'excerpt' => '從新宿御苑到須賀神社，完整重現電影場景的一日自助遊路線規劃，含交通時刻表與拍攝技巧。',
        'region'  => '東京',
        'date'    => '2026/03/28',
        'views'   => '34,820',
    ],
    [
        'emoji'   => '⛩️',
        'grad'    => 'linear-gradient(135deg,rgba(255,107,174,0.2),rgba(168,85,247,0.15))',
        'title'   => '京都動漫聖地地圖：《神隱少女》與《魔法少女小圓》場景全解析',
        'excerpt' => '伏見稻荷的千本鳥居是現實版神隱少女通道，本篇帶你一探究竟，附詳細地圖與開放時間。',
        'region'  => '京都',
        'date'    => '2026/03/15',
        'views'   => '28,410',
    ],
    [
        'emoji'   => '❄️',
        'grad'    => 'linear-gradient(135deg,rgba(111,236,255,0.2),rgba(108,99,255,0.15))',
        'title'   => '北海道動漫聖地秋冬特輯：《冰上的尤里》函館場景 + 賞雪絕景',
        'excerpt' => '函館山夜景與《冰上的尤里》最終話場景完全重疊，本篇詳細介紹最佳拍攝角度與季節選擇。',
        'region'  => '北海道',
        'date'    => '2026/02/20',
        'views'   => '19,560',
    ],
];
?>

<main id="pilgrimage-page" class="pg-page">

  <!-- ============================ PAGE HERO ============================ -->
  <section class="pg-page-hero">
    <div class="pg-page-hero__inner">
      <div class="pgh__head">
        <div class="pgh__icon">📍</div>
        <div>
          <div class="pgh__eyebrow">ANIME PILGRIMAGE</div>
          <h1 class="pgh__title">聖地巡禮</h1>
        </div>
      </div>
      <p class="pgh__desc">走進動漫世界的現實原型地，探索聖地巡禮景點與旅遊攻略</p>
      <div class="pgh__stats">
        <?php foreach ( $hero_stats as $s ) : ?>
          <span class="pgh__stat"><i class="fa-solid <?php echo esc_attr( $s['icon'] ); ?>"></i> <?php echo esc_html( $s['text'] ); ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ============================ MAIN ============================ -->
  <div class="pg-main">

    <!-- 精選聖地 -->
    <div class="pg-section">
      <div class="pg-section-header">
        <div class="pg-section-title"><i class="fa-solid fa-map-location-dot"></i> 精選聖地景點</div>
        <a href="#" class="pg-section-link">全部景點 <i class="fa-solid fa-chevron-right"></i></a>
      </div>
      <div class="pilgrimage-grid">
        <?php foreach ( $featured_list as $i => $p ) : ?>
          <div class="pilg-card" data-pilg-id="<?php echo (int)($i+1); ?>">
            <div class="pilg-cover" style="background:<?php echo esc_attr( $p['grad'] ); ?>;">
              <?php echo $p['emoji']; ?>
              <span class="pilg-badge"><?php echo esc_html( $p['badge'] ); ?></span>
              <span class="pilg-region"><?php echo esc_html( $p['region'] ); ?></span>
            </div>
            <div class="pilg-body">
              <div class="pilg-title"><?php echo esc_html( $p['title'] ); ?></div>
              <div class="pilg-anime">🎬 <?php echo esc_html( $p['anime'] ); ?></div>
              <div class="pilg-address"><i class="fa-solid fa-location-dot"></i><?php echo esc_html( $p['address'] ); ?></div>
              <div class="pilg-foot">
                <span class="pilg-visits"><i class="fa-solid fa-person-walking"></i> 已有 <?php echo esc_html( $p['visits'] ); ?> 人打卡</span>
                <button class="pilg-map-btn" type="button"><i class="fa-solid fa-map"></i> 地圖</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 依地區探索 -->
    <div class="pg-section">
      <div class="pg-section-header">
        <div class="pg-section-title"><i class="fa-solid fa-earth-asia"></i> 依地區探索</div>
        <a href="#" class="pg-section-link">全部地區 <i class="fa-solid fa-chevron-right"></i></a>
      </div>
      <div class="region-tabs" id="pg-region-tabs">
        <?php foreach ( $region_tabs as $r ) : ?>
          <span class="region-tab<?php echo ! empty( $r['active'] ) ? ' active' : ''; ?>" data-region="<?php echo esc_attr( $r['slug'] ); ?>"><?php echo $r['label']; ?></span>
        <?php endforeach; ?>
      </div>
      <div class="pilg-grid-4">
        <?php foreach ( $mini_list as $m ) : ?>
          <div class="pilg-mini-card" data-region="<?php echo esc_attr( $m['region'] ); ?>">
            <div class="pilg-mini-thumb" style="background:<?php echo esc_attr( $m['grad'] ); ?>;"><?php echo $m['emoji']; ?></div>
            <div class="pilg-mini-body">
              <div class="pilg-mini-title"><?php echo esc_html( $m['title'] ); ?></div>
              <div class="pilg-mini-region"><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( $m['sub'] ); ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 旅遊攻略 -->
    <div class="pg-section">
      <div class="pg-section-header">
        <div class="pg-section-title"><i class="fa-solid fa-book-open"></i> 聖地巡禮攻略</div>
        <a href="#" class="pg-section-link">全部攻略 <i class="fa-solid fa-chevron-right"></i></a>
      </div>
      <div class="guide-list">
        <?php foreach ( $guide_list as $i => $g ) : ?>
          <div class="guide-item" data-guide-id="<?php echo (int)($i+1); ?>">
            <div class="guide-thumb" style="background:<?php echo esc_attr( $g['grad'] ); ?>;"><?php echo $g['emoji']; ?></div>
            <div class="guide-info">
              <div class="guide-title"><?php echo esc_html( $g['title'] ); ?></div>
              <div class="guide-excerpt"><?php echo esc_html( $g['excerpt'] ); ?></div>
              <div class="guide-meta">
                <span><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( $g['region'] ); ?></span>
                <span><i class="fa-regular fa-calendar"></i> <?php echo esc_html( $g['date'] ); ?></span>
                <span><i class="fa-regular fa-eye"></i> <?php echo esc_html( $g['views'] ); ?></span>
                <span class="guide-region-tag"><?php echo esc_html( $g['region'] ); ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Coming Soon -->
  <div class="pg-coming-soon-card">
    <div class="pg-coming-soon-inner">
      <div class="cs-icon">🚀</div>
      <div class="cs-text">更多內容即將上線！敬請期待 ✨</div>
      <div class="cs-sub">互動地圖、打卡功能、攝影分享社群、旅遊行程規劃工具開發中</div>
    </div>
  </div>

</main>

<script>
(function(){
  // 地區 tab 切換 active
  var tabs = document.getElementById('pg-region-tabs');
  if (!tabs) return;
  tabs.addEventListener('click', function(e){
    var t = e.target.closest('.region-tab');
    if (!t) return;
    tabs.querySelectorAll('.region-tab').forEach(function(x){ x.classList.remove('active'); });
    t.classList.add('active');
    var region = t.dataset.region;
    document.querySelectorAll('.pilg-mini-card').forEach(function(card){
      if (region === 'all' || card.dataset.region === region) {
        card.style.display = '';
      } else {
        card.style.display = 'none';
      }
    });
  });
})();
</script>

<?php get_footer(); ?>
