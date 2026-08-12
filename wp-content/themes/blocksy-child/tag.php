<?php
/**
 * Tag Archive Template
 *
 * Path: wp-content/themes/blocksy-child/tag.php
 * @version 2.0.0 (2026-08-12)
 *
 * v2.0.0 變更（AdSense「缺乏價值的內容」複查修正）：
 *   1. 提前執行主查詢，取得 found_posts 後才輸出 <head>，
 *      藉此對「稀薄標籤頁」與「分頁 2 頁以後」加上 noindex,follow。
 *   2. 空標籤頁（0 篇）不再輸出跟有內容頁一樣的完整殼版，
 *      改為明確的「尚無內容」友善頁 + noindex，避免被視為空洞頁面。
 *   3. hero_subtitle 不再是固定死文案，改用該標籤實際資料
 *      （文章/動畫篇數、最新更新時間）動態組出，降低跨標籤頁重複文字比例。
 *   4. 加上 self-canonical，避免分頁 / thin 變體與正規標籤網址互相competing。
 *   5. THIN_TAG_THRESHOLD / NOINDEX_ALL_PAGED 常數集中在檔案頂端，方便依站務策略調整。
 *
 * 設計依據 category.php，但簡化為標籤檔案：
 *   - 不需要 channel/category 互切的 filter tabs
 *   - 自動撈所有「掛 post_tag」的 public CPT（含 post + anime + 未來 manga/novel/game/music）
 *   - 共用 template-parts/news-list.php，視覺一致
 *   - 共用 news.css，無需新增樣式
 *
 * 注意：因為 anime CPT 有 post_tag taxonomy（由 anime-sync-pro v1.2.1 註冊），
 * 標籤頁需同時撈 post 與 anime 兩種 post_type，否則點 #男主角 進來會空白。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── 站務策略設定 ──────────────────────────────────────────
// 少於這個篇數的標籤頁視為「稀薄」，加上 noindex,follow（頁面仍可瀏覽，只是不建議被收錄）。
if ( ! defined( 'TAG_THIN_THRESHOLD' ) ) {
    define( 'TAG_THIN_THRESHOLD', 3 );
}
// true = 所有分頁第 2 頁以後一律 noindex（業界常見作法，避免大量近重複分頁頁面稀釋權重）。
if ( ! defined( 'TAG_NOINDEX_PAGED' ) ) {
    define( 'TAG_NOINDEX_PAGED', true );
}

// ── 取得目前 tag ──
$queried      = get_queried_object();
$current_tag  = ( $queried instanceof WP_Term ) ? $queried : null;

// ── 動態抓所有「掛 post_tag」且 public 的 CPT ──
$tag_post_types = get_post_types( [ 'public' => true ], 'names' );
$tag_post_types = array_values( array_filter( $tag_post_types, function( $t ) {
    return in_array( 'post_tag', get_object_taxonomies( $t ), true );
} ) );
if ( empty( $tag_post_types ) ) {
    $tag_post_types = [ 'post' ];
}

// ── 共用 tax_query ──
$archive_tax_query = [];
if ( $current_tag ) {
    $archive_tax_query[] = [
        'taxonomy' => 'post_tag',
        'field'    => 'term_id',
        'terms'    => $current_tag->term_id,
    ];
}

// ── 提前執行主查詢（在 get_header() 之前），才能在 <head> 輸出前就知道 found_posts ──
global $wp_query;
$paged = max( 1, get_query_var( 'paged' ) );
$wp_query = new WP_Query( [
    'post_type'           => $tag_post_types,
    'posts_per_page'      => get_option( 'posts_per_page', 10 ),
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'paged'               => $paged,
    'tax_query'           => $archive_tax_query,
] );

$found_posts = (int) $wp_query->found_posts;
$is_empty    = ( $found_posts === 0 );
$is_thin     = ( $found_posts > 0 && $found_posts < TAG_THIN_THRESHOLD );
$is_paged    = ( $paged > 1 );

// ── Robots 訊號：稀薄 / 空白 / 分頁一律 noindex,follow ──
// （follow 讓 Googlebot 仍可順著連結探索到你真正有價值的單篇內容）
add_filter( 'wp_robots', function ( $robots ) use ( $is_empty, $is_thin, $is_paged ) {
    if ( $is_empty || $is_thin || ( TAG_NOINDEX_PAGED && $is_paged ) ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }
    return $robots;
} );

// ── Self-canonical：避免分頁 / thin 變體與正規標籤網址互相競爭 ──
add_filter( 'get_canonical_url', function ( $canonical_url ) use ( $current_tag ) {
    if ( $current_tag instanceof WP_Term ) {
        return get_tag_link( $current_tag->term_id );
    }
    return $canonical_url;
} );

// ── hero 文案：優先使用管理員手動填寫的標籤描述（真正獨特內容）；
//    沒有的話，改用「該標籤實際資料」動態組字，而不是固定死文案，
//    降低跨標籤頁的重複文字比例 ──
$hero_title = $current_tag ? '#' . $current_tag->name : '所有標籤';
$hero_badge = $current_tag ? $current_tag->name : '標籤';

if ( $current_tag && $current_tag->description ) {
    $hero_subtitle = $current_tag->description;
} elseif ( $is_empty ) {
    $hero_subtitle = '目前還沒有內容掛上這個標籤，你可以先看看其他熱門標籤。';
} else {
    // 統計本標籤內各 post_type 的篇數，組出「3 篇文章、2 部動畫」這種真實資料句子
    $type_counts = [];
    foreach ( $tag_post_types as $pt ) {
        $c = new WP_Query( [
            'post_type'           => $pt,
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'fields'               => 'ids',
            'ignore_sticky_posts' => true,
            'tax_query'           => $archive_tax_query,
        ] );
        if ( $c->found_posts > 0 ) {
            $label = ( $pt === 'anime' ) ? '部動畫' : ( ( $pt === 'post' ) ? '篇文章' : $pt );
            $type_counts[] = $c->found_posts . ' ' . $label;
        }
        wp_reset_postdata();
    }
    $hero_subtitle = $type_counts
        ? '目前共有 ' . implode( '、', $type_counts ) . ' 掛有此標籤'
        : '所有掛有此標籤的文章與動畫';
}

get_header();
?>

<!-- 共用 category 頁同一份 CSS / Swiper -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/news.css' ); ?>" />

<!-- ===== PAGE HERO ===== -->
<div class="page-hero">
  <div class="container">
    <div class="page-badge"><i class="fa-solid fa-hashtag"></i> <?php echo esc_html( $hero_badge ); ?></div>
    <h1 class="page-title"><?php echo esc_html( $hero_title ); ?></h1>
    <?php if ( $hero_subtitle ) : ?>
      <p class="page-subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
    <?php endif; ?>
  </div>
</div>

<!-- ===== MAIN ===== -->
<main class="container" style="padding: 32px 0 64px;">

<?php if ( $is_empty ) : ?>

  <!-- ── 空標籤頁：明確的友善頁面，而非跟有內容頁一樣的完整殼版 ── -->
  <div class="news-layout">
    <div class="news-main-grid" style="text-align:center; padding: 48px 24px;">
      <p style="font-size:16px; color:rgba(255,255,255,.75); margin-bottom:24px;">
        這個標籤底下目前還沒有任何文章或動畫，之後有新內容上架會自動出現在這裡。
      </p>
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn-primary">回到首頁看看其他內容</a>
    </div>

    <aside class="news-sidebar">
      <?php
      $popular_tags_empty = get_tags( [
          'orderby'    => 'count',
          'order'      => 'DESC',
          'number'     => 15,
          'hide_empty' => true,
          'exclude'    => $current_tag ? [ $current_tag->term_id ] : [],
      ] );
      if ( ! empty( $popular_tags_empty ) ) : ?>
      <div class="sidebar-widget glass">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-tags" style="color:var(--accent-blue);"></i> 熱門標籤
        </div>
        <div class="tag-cloud">
          <?php foreach ( $popular_tags_empty as $tag ) : ?>
          <a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>" class="tag-pill">
            #<?php echo esc_html( $tag->name ); ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </aside>
  </div>

<?php else : ?>

  <?php
  // ── 輪播：本標籤最新 5 篇（混合所有 CPT） ──
  $carousel_query = new WP_Query( [
      'post_type'           => $tag_post_types,
      'posts_per_page'      => 5,
      'post_status'         => 'publish',
      'ignore_sticky_posts' => true,
      'tax_query'           => $archive_tax_query,
  ] );

  // ── 熱門：本標籤留言數最多 5 篇 ──
  $popular_query = new WP_Query( [
      'post_type'           => $tag_post_types,
      'posts_per_page'      => 5,
      'post_status'         => 'publish',
      'ignore_sticky_posts' => true,
      'orderby'             => 'comment_count',
      'order'               => 'DESC',
      'tax_query'           => $archive_tax_query,
  ] );

  // ── 熱門標籤（全站，排除目前這個） ──
  $popular_tags = get_tags( [
      'orderby'    => 'count',
      'order'      => 'DESC',
      'number'     => 15,
      'hide_empty' => true,
      'exclude'    => $current_tag ? [ $current_tag->term_id ] : [],
  ] );
  ?>

  <!-- ── 海報輪播 ── -->
  <?php if ( $carousel_query->have_posts() ) : ?>
  <div class="news-carousel-wrap">
    <div class="swiper news-swiper">
      <div class="swiper-wrapper">
        <?php while ( $carousel_query->have_posts() ) : $carousel_query->the_post();
          $post_type_label = get_post_type() === 'anime' ? '動畫' : '文章';

          $carousel_img_url = '';
          if ( has_post_thumbnail() ) {
              $carousel_img_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
          } else {
              preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', get_the_content(), $m );
              if ( ! empty( $m[1] ) ) $carousel_img_url = $m[1];
          }
        ?>
        <div class="swiper-slide">
          <a href="<?php the_permalink(); ?>" class="swiper-slide-inner">
            <?php if ( $carousel_img_url ) : ?>
              <div class="swiper-slide-bg" style="background-image: url('<?php echo esc_url( $carousel_img_url ); ?>');"></div>
              <img class="carousel-main-img"
                   src="<?php echo esc_url( $carousel_img_url ); ?>"
                   alt="<?php echo esc_attr( get_the_title() ); ?>"
                   loading="lazy" />
            <?php else : ?>
              <div class="carousel-no-img">📰</div>
            <?php endif; ?>

            <div class="swiper-slide-caption">
              <div class="swiper-slide-tag"><?php echo esc_html( $post_type_label ); ?></div>
              <div class="swiper-slide-title"><?php the_title(); ?></div>
              <div class="swiper-slide-meta">
                <i class="fa-regular fa-clock"></i> <?php echo get_the_date( 'Y-m-d' ); ?>
              </div>
            </div>
          </a>
        </div>
        <?php endwhile; wp_reset_postdata(); ?>
      </div>
      <div class="swiper-pagination"></div>
      <div class="swiper-button-prev"></div>
      <div class="swiper-button-next"></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="news-layout">

    <!-- ── 主要列表 ── -->
    <div class="news-main-grid"
         id="news-list-root"
         data-content-type="tag"
         data-tag-slug="<?php echo esc_attr( $current_tag ? $current_tag->slug : '' ); ?>">

      <?php
      set_query_var( 'news_main_query', $wp_query );
      get_template_part( 'template-parts/news-list' );
      ?>

    </div>

    <!-- ── 側欄 ── -->
    <aside class="news-sidebar">

      <!-- 此標籤熱門 -->
      <div class="sidebar-widget glass">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-fire" style="color:#f97316;"></i> 此標籤熱門
        </div>
        <?php if ( $popular_query->have_posts() ) :
          $pop_i = 0;
          while ( $popular_query->have_posts() ) : $popular_query->the_post();
            $pop_i++;
            $is_top = $pop_i <= 3 ? 'top-3' : '';
        ?>
        <a href="<?php the_permalink(); ?>" class="sidebar-list-item">
          <div class="sidebar-item-num <?php echo $is_top; ?>"><?php echo $pop_i; ?></div>
          <div>
            <div class="sidebar-item-title"><?php the_title(); ?></div>
            <div class="sidebar-item-date">
              <?php echo human_time_diff( get_the_time( 'U' ), current_time( 'timestamp' ) ); ?>前
            </div>
          </div>
        </a>
        <?php endwhile; wp_reset_postdata(); ?>
        <?php else : ?>
          <p style="color:rgba(255,255,255,.6);font-size:13px;">尚無熱門資料</p>
        <?php endif; ?>
      </div>

      <!-- 其他熱門標籤 -->
      <?php if ( ! empty( $popular_tags ) ) : ?>
      <div class="sidebar-widget glass">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-tags" style="color:var(--accent-blue);"></i> 其他熱門標籤
        </div>
        <div class="tag-cloud">
          <?php foreach ( $popular_tags as $tag ) : ?>
          <a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>" class="tag-pill">
            #<?php echo esc_html( $tag->name ); ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 訂閱快報 -->
      <div class="sidebar-widget glass subscribe-box">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-bell" style="color:var(--accent-blue);"></i> 訂閱快報
        </div>
        <p class="subscribe-desc">每週精選重要動漫資訊，直送你的信箱</p>
        <input type="email" class="subscribe-input" placeholder="your@email.com" />
        <button class="btn btn-primary subscribe-btn">訂閱</button>
      </div>

    </aside>
  </div>

<?php endif; ?>

</main>

<!-- Swiper JS -->
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
<script>
(function () {
  'use strict';
  if ( document.querySelector('.news-swiper') ) {
    new Swiper('.news-swiper', {
      loop:       true,
      autoplay:   { delay: 5000, disableOnInteraction: false },
      speed:      700,
      effect:     'fade',
      fadeEffect: { crossFade: true },
      pagination: { el: '.swiper-pagination', clickable: true },
      navigation: { nextEl: '.swiper-button-next', prevEl: '.swiper-button-prev' },
      a11y:       { enabled: true },
    });
  }
})();
</script>

<?php
get_footer();