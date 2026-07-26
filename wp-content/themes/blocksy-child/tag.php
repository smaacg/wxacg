<?php
/**
 * Tag Archive Template
 *
 * Path: wp-content/themes/blocksy-child/tag.php
 * @version 1.0.0 (2026-05-26)
 *
 * 設計依據 category.php v2.0.0，但簡化為標籤檔案：
 *   - 不需要 channel/category 互切的 filter tabs
 *   - 自動撈所有「掛 post_tag」的 public CPT（含 post + anime + 未來 manga/novel/game/music）
 *   - 共用 template-parts/news-list.php，視覺一致
 *   - 共用 news.css，無需新增樣式
 *
 * 注意：因為 anime CPT 有 post_tag taxonomy（由 anime-sync-pro v1.2.1 註冊），
 * 標籤頁需同時撈 post 與 anime 兩種 post_type，否則點 #男主角 進來會空白。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

// ── 取得目前 tag ──
$queried      = get_queried_object();
$current_tag  = ( $queried instanceof WP_Term ) ? $queried : null;

$hero_title    = $current_tag ? '#' . $current_tag->name : '所有標籤';
$hero_subtitle = $current_tag && $current_tag->description
    ? $current_tag->description
    : '所有掛有此標籤的文章與動畫';
$hero_badge    = $current_tag ? $current_tag->name : '標籤';

// ── 動態抓所有「掛 post_tag」且 public 的 CPT ──
// 避免寫死，未來 anime-sync-pro 開放 manga/novel/game/music 時自動納入
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

// ── 改寫主迴圈：包含 anime 等 CPT ──
// 注意：functions.php 的 pre_get_posts 已經處理過 is_tag()，
// 但這裡再 override 一次 $wp_query 比較保險。
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
