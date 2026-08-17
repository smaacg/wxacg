<?php
/**
 * Template Name: COSPLAY 專區（Cosplay Hub）
 * Description: 動漫 COSPLAY 作品、活動、攻略頁面
 * Version: 1.0.0 (2026-05-16)
 * Path: blocksy-child/page-cosplay.php
 */
if ( ! defined('ABSPATH') ) exit;
get_header();

/* ============================================================
 *  假資料區（之後改成 CPT: cosplay_work / cosplay_event）
 * ============================================================ */

// Hero filter chips
$filter_chips = [
  ['slug'=>'all',      'label'=>'全部',      'active'=>true],
  ['slug'=>'female',   'label'=>'女性角色'],
  ['slug'=>'male',     'label'=>'男性角色'],
  ['slug'=>'mecha',    'label'=>'機甲'],
  ['slug'=>'kimono',   'label'=>'和風'],
  ['slug'=>'school',   'label'=>'校園制服'],
  ['slug'=>'original', 'label'=>'原創改編'],
];

// Hero carousel (主打作品)
$hero_carousel = [
  'emoji' => '👘',
  'grad'  => 'linear-gradient(135deg,#ff6b9d 0%,#c06cff 100%)',
  'badge' => '🏆 本週冠軍',
  'title' => '雷電將軍 完整還原',
  'author'=> '@Misaki_Cos',
  'likes' => '24,580',
  'views' => '180K',
  'tags'  => ['原神','女性角色','還原系'],
];

// Focus 角色（左欄）
$focus_list = [
  ['emoji'=>'🌙','grad'=>'linear-gradient(135deg,#ffb6e6 0%,#a890ff 100%)','name'=>'月亮公主 セレニティ','series'=>'美少女戰士'],
  ['emoji'=>'⚡','grad'=>'linear-gradient(135deg,#9c4dff 0%,#ff3d8a 100%)','name'=>'雷電將軍','series'=>'原神'],
  ['emoji'=>'🔥','grad'=>'linear-gradient(135deg,#ff6b35 0%,#ffaa00 100%)','name'=>'炎柱 煉獄杏壽郎','series'=>'鬼滅之刃'],
  ['emoji'=>'🌀','grad'=>'linear-gradient(135deg,#3d5dff 0%,#7d4dff 100%)','name'=>'五條悟','series'=>'咒術迴戰'],
];

// 近期活動（中欄）
$event_list = [
  ['date'=>'2026/04/26','title'=>'Fancy Frontier 43 開拓動漫祭','venue'=>'台大綜合體育館','status'=>'報名中','status_type'=>'hot'],
  ['date'=>'2026/05/10','title'=>'Petit Fancy 41 小拓','venue'=>'集思北科大會議中心','status'=>'即將開放','status_type'=>'soon'],
  ['date'=>'2026/06/07','title'=>'Comic World Taiwan 60','venue'=>'花博爭艷館','status'=>'規劃中','status_type'=>'normal'],
];

// 攝影作品 Masonry（中欄）
$photo_gallery = [
  ['emoji'=>'🌸','grad'=>'linear-gradient(135deg,#ff9bc7 0%,#c084fc 100%)','name'=>'夜叉姬','likes'=>'5,820','hot'=>true],
  ['emoji'=>'⚔️','grad'=>'linear-gradient(135deg,#4d6bff 0%,#a84dff 100%)','name'=>'劍心','likes'=>'3,940','hot'=>false],
  ['emoji'=>'🎭','grad'=>'linear-gradient(135deg,#ff4d6b 0%,#ff8c42 100%)','name'=>'黑羽快斗','likes'=>'4,102','hot'=>true],
  ['emoji'=>'🌊','grad'=>'linear-gradient(135deg,#4dc4ff 0%,#4d8aff 100%)','name'=>'水著版洛琪希','likes'=>'1,674','hot'=>false],
  ['emoji'=>'❄️','grad'=>'linear-gradient(135deg,#a8e0ff 0%,#7dabff 100%)','name'=>'雪女','likes'=>'2,231','hot'=>false],
  ['emoji'=>'🔮','grad'=>'linear-gradient(135deg,#8a4dff 0%,#ff4dc4 100%)','name'=>'魔女夏','likes'=>'3,560','hot'=>false],
];

// 造型深度分析（中欄）
$analysis_articles = [
  ['title'=>'雷電將軍服裝紋樣完全考據：從浮世繪到次世代渲染','views'=>'12,480'],
  ['title'=>'鬼滅之刃柱級服裝結構解析：和服改良與機能性平衡','views'=>'9,231'],
  ['title'=>'機甲類 Cosplay 的 EVA 板材選擇與熱塑加工指南','views'=>'7,855'],
];

// 本週人氣作品（右欄）
$weekly_works = [
  ['emoji'=>'🌟','grad'=>'linear-gradient(135deg,#ff6b9d 0%,#ffa84d 100%)','title'=>'星野アイ 偶像舞台版','author'=>'@Ai_idol','likes'=>'4,102'],
  ['emoji'=>'🗡️','grad'=>'linear-gradient(135deg,#4d4dff 0%,#ff4d8a 100%)','title'=>'蕾姆 戰鬥型態','author'=>'@RemFan','likes'=>'3,210'],
  ['emoji'=>'🌺','grad'=>'linear-gradient(135deg,#ff8aa8 0%,#ffd24d 100%)','title'=>'禰豆子 進化型','author'=>'@Nezuko_C','likes'=>'2,847'],
];

// 攝影與化妝技術教學（右欄）
$tech_cards = [
  ['icon'=>'📷','title'=>'動漫展 Cosplay 戶外攝影燈光技巧','desc'=>'活用反光板 + 補光燈，逆光也能拍出立體感'],
  ['icon'=>'💄','title'=>'特效化妝：暗黑角色眼妝還原教學','desc'=>'雙色眼影 + 假血膠應用，5 步驟還原 BOSS 級壓迫感'],
];

// 道具製作攻略（右欄）
$props_list = [
  ['emoji'=>'⚔️','title'=>'巨型武器：EVA 板輕量化製作','difficulty'=>'進階','diff_type'=>'hard','desc'=>'總重控制 1.5kg 內，含分段組裝與磁吸接合'],
  ['emoji'=>'👑','title'=>'發光皇冠：透明樹脂 + LED 嵌入','difficulty'=>'中級','diff_type'=>'mid','desc'=>'UV 樹脂翻模技法，附電路接線圖'],
  ['emoji'=>'🛡️','title'=>'機甲護肩：3D 列印與打磨上色','difficulty'=>'進階','diff_type'=>'hard','desc'=>'PLA + 補土 + 噴漆，金屬質感 8 步驟'],
];
?>

<main id="cosplay-page" class="cs-page">

  <!-- HERO -->
  <section class="cs-page-hero">
    <div class="csh__inner">
      <div class="csh__eyebrow"><span class="csh__badge">COSPLAY GALLERY</span></div>
      <h1 class="csh__title">COSPLAY 專區</h1>
      <p class="csh__desc">收錄熱門角色還原、攝影作品、活動資訊與道具製作攻略。</p>
      <div class="csh__filter" id="cs-filter-chips">
        <?php foreach($filter_chips as $c): ?>
          <span class="cs-chip<?= !empty($c['active'])?' active':''?>" data-filter="<?= esc_attr($c['slug']) ?>"><?= esc_html($c['label']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- 三欄佈局 -->
  <div class="cosplay-layout">

    <!-- ============ 左欄 ============ -->
    <aside class="cs-col cs-col-left">

      <!-- 主打作品 Carousel -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">本週主打</h2>
        </div>
        <div class="cs-carousel-card">
          <div class="cs-carousel-cover" style="background:<?= esc_attr($hero_carousel['grad']) ?>;">
            <span class="cs-carousel-emoji"><?= $hero_carousel['emoji'] ?></span>
            <span class="cs-carousel-badge"><?= esc_html($hero_carousel['badge']) ?></span>
          </div>
          <div class="cs-carousel-body">
            <h3 class="cs-carousel-title"><?= esc_html($hero_carousel['title']) ?></h3>
            <div class="cs-carousel-author"><?= esc_html($hero_carousel['author']) ?></div>
            <div class="cs-carousel-meta">
              <span><i class="fa-solid fa-heart"></i> <?= esc_html($hero_carousel['likes']) ?></span>
              <span><i class="fa-solid fa-eye"></i> <?= esc_html($hero_carousel['views']) ?></span>
            </div>
            <div class="cs-carousel-tags">
              <?php foreach($hero_carousel['tags'] as $t): ?>
                <span class="cs-tag"><?= esc_html($t) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- Focus 角色 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">焦點角色</h2>
          <a class="cs-more" href="#">更多 <i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <div class="cs-focus-grid">
          <?php foreach($focus_list as $f): ?>
            <div class="cs-focus-card">
              <div class="cs-focus-thumb" style="background:<?= esc_attr($f['grad']) ?>;"><?= $f['emoji'] ?></div>
              <div class="cs-focus-info">
                <div class="cs-focus-name"><?= esc_html($f['name']) ?></div>
                <div class="cs-focus-series"><?= esc_html($f['series']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </aside>

    <!-- ============ 中欄 ============ -->
    <section class="cs-col cs-col-mid">

      <!-- 近期活動 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">近期活動</h2>
          <a class="cs-more" href="#">全部 <i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <div class="cs-event-list">
          <?php foreach($event_list as $e): ?>
            <div class="cs-event-item">
              <div class="cs-event-date"><?= esc_html($e['date']) ?></div>
              <div class="cs-event-info">
                <div class="cs-event-title"><?= esc_html($e['title']) ?></div>
                <div class="cs-event-venue"><i class="fa-solid fa-location-dot"></i> <?= esc_html($e['venue']) ?></div>
              </div>
              <span class="cs-event-status cs-event-status--<?= esc_attr($e['status_type']) ?>"><?= esc_html($e['status']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- 攝影作品 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">攝影作品集</h2>
          <a class="cs-more" href="#">更多 <i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <div class="cs-photo-grid">
          <?php foreach($photo_gallery as $i=>$p): ?>
            <div class="cs-photo-item" data-photo-id="<?= ($i+1) ?>">
              <div class="cs-photo-cover" style="background:<?= esc_attr($p['grad']) ?>;">
                <span class="cs-photo-emoji"><?= $p['emoji'] ?></span>
                <?php if (!empty($p['hot'])): ?><span class="cs-photo-hot">HOT</span><?php endif; ?>
              </div>
              <div class="cs-photo-meta">
                <div class="cs-photo-name"><?= esc_html($p['name']) ?></div>
                <div class="cs-photo-likes"><i class="fa-solid fa-heart"></i> <?= esc_html($p['likes']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- 造型深度分析 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">造型深度分析</h2>
          <a class="cs-more" href="#">全部 <i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <div class="cs-analysis-list">
          <?php foreach($analysis_articles as $i=>$a): ?>
            <div class="cs-analysis-item" data-article-id="<?= ($i+1) ?>">
              <div class="cs-analysis-num"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></div>
              <div class="cs-analysis-info">
                <div class="cs-analysis-title"><?= esc_html($a['title']) ?></div>
                <div class="cs-analysis-meta"><i class="fa-solid fa-eye"></i> <?= esc_html($a['views']) ?> 觀看</div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </section>

    <!-- ============ 右欄 ============ -->
    <aside class="cs-col cs-col-right">

      <!-- 本週人氣作品 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">本週人氣</h2>
        </div>
        <div class="cs-weekly-list">
          <?php foreach($weekly_works as $i=>$w): ?>
            <div class="cs-weekly-item" data-photo-id="<?= ($i+7) ?>">
              <div class="cs-weekly-rank">#<?= ($i+1) ?></div>
              <div class="cs-weekly-thumb" style="background:<?= esc_attr($w['grad']) ?>;"><?= $w['emoji'] ?></div>
              <div class="cs-weekly-info">
                <div class="cs-weekly-title"><?= esc_html($w['title']) ?></div>
                <div class="cs-weekly-author"><?= esc_html($w['author']) ?></div>
              </div>
              <div class="cs-weekly-likes"><i class="fa-solid fa-heart"></i> <?= esc_html($w['likes']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- 技術教學 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">攝影 / 化妝教學</h2>
        </div>
        <div class="cs-tech-list">
          <?php foreach($tech_cards as $t): ?>
            <div class="cs-tech-card">
              <div class="cs-tech-icon"><?= $t['icon'] ?></div>
              <div class="cs-tech-body">
                <div class="cs-tech-title"><?= esc_html($t['title']) ?></div>
                <div class="cs-tech-desc"><?= esc_html($t['desc']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- 道具製作 -->
      <section class="cs-section-card">
        <div class="cs-section-head">
          <h2 class="cs-section-title">道具製作攻略</h2>
          <a class="cs-more" href="#">更多 <i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <div class="cs-props-list">
          <?php foreach($props_list as $i=>$p): ?>
            <div class="cs-props-item" data-prop-id="<?= ($i+1) ?>">
              <div class="cs-props-emoji"><?= $p['emoji'] ?></div>
              <div class="cs-props-info">
                <div class="cs-props-title">
                  <?= esc_html($p['title']) ?>
                  <span class="cs-diff cs-diff--<?= esc_attr($p['diff_type']) ?>"><?= esc_html($p['difficulty']) ?></span>
                </div>
                <div class="cs-props-desc"><?= esc_html($p['desc']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </aside>

  </div><!-- /.cosplay-layout -->

  <!-- COMING SOON -->
  <div class="cs-coming-soon-card">
    <div class="cs-cs-inner">
      <div class="cs-cs-icon">🚀</div>
      <div class="cs-cs-text">
        <h3>更多功能即將上線</h3>
        <p>個人 Coser 主頁、作品投票、攝影師媒合、活動行事曆訂閱、AI 角色配對。</p>
      </div>
    </div>
  </div>

</main>

<script>
(function(){
  // Filter chips
  var chips = document.getElementById('cs-filter-chips');
  if (chips) {
    chips.addEventListener('click', function(e){
      var c = e.target.closest('.cs-chip');
      if (!c) return;
      chips.querySelectorAll('.cs-chip').forEach(x=>x.classList.remove('active'));
      c.classList.add('active');
      // 之後接 AJAX 篩選
    });
  }
})();
</script>

<?php get_footer(); ?>
