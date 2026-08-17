<?php
/**
 * Category / Channel Archive Template
 *
 * Path: wp-content/themes/blocksy-child/category.php
 * @version 2.3.0 (2026-08-12)
 *
 * Changelog:
 *   2.3.0 (2026-08-12) AdSense「缺乏價值的內容」複查修正：
 *     - 提前判斷 found_posts，才呼叫 get_header()，藉此在 <head> 輸出前
 *       對「稀薄分類/頻道組合頁」與「分頁 2 頁以後」加上 noindex,follow。
 *       分類 × 頻道排列組合（如 /news/anime/、/review/vtuber/）篇數落差很大，
 *       是這支模板最主要的稀薄內容來源。
 *     - 分類/頻道組合頁沒有原生 term_link，WP 核心無法自動輸出正確 canonical，
 *       容易被判定跟母分類頁（如 /news/）重複 → 明確加上 self-canonical。
 *     - 空結果（0 篇）不再輸出跟正常頁一樣的完整殼版，改為明確的「尚無內容」頁。
 *     - THIN_THRESHOLD / NOINDEX_PAGED 常數集中在頂端，方便依站務策略調整。
 *
 *   2.2.0 (2026-06-21) SEO 關鍵字 + 效能強化版：
 *     - H1 / 標題關鍵字化：news→「動漫新聞」、review→「動漫評論」、feature→「動漫專題」
 *     - 新增 channel 組合動態標題：/news/anime/ → H1 自動變「動畫新聞」，
 *       精準命中「動畫新聞」「動畫評論」等長尾關鍵字
 *     - 熱門標籤全站 SQL 改用 transient 快取（6 小時），降低 DB 負擔、加快 TTFB
 *     - 輪播第一張圖改 loading=eager + fetchpriority=high，優化 LCP（Core Web Vitals）
 *   2.1.0 (2026-06-10) SEO/AEO 強化版（ItemList JSON-LD 等）
 *   2.0.0 (2026-05-16) AJAX 切換版
 *   1.0.0 初版
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── 站務策略設定 ──────────────────────────────────────────
// 分類/頻道組合頁篇數少於這個數字視為「稀薄」，加上 noindex,follow。
if ( ! defined( 'CAT_THIN_THRESHOLD' ) ) {
    define( 'CAT_THIN_THRESHOLD', 3 );
}
// true = 所有分頁第 2 頁以後一律 noindex（避免大量近重複分頁頁面稀釋權重）。
if ( ! defined( 'CAT_NOINDEX_PAGED' ) ) {
    define( 'CAT_NOINDEX_PAGED', true );
}

// ── 取得目前 archive 的 term ──
$queried        = get_queried_object();
$current_term   = ( $queried instanceof WP_Term ) ? $queried : null;
$current_tax    = $current_term ? $current_term->taxonomy : '';
$current_termid = $current_term ? $current_term->term_id  : 0;
$current_slug   = $current_term ? $current_term->slug     : '';

// ── 取得目前 channel（若有，例如 /news/cosplay/） ──
$current_channel = (string) get_query_var( 'channel' );

// ── channel 中文名對照（用於組合關鍵字標題，例如「動畫新聞」） ──
$channel_names = [
    'anime'       => '動畫',
    'manga'       => '漫畫',
    'novel'       => '輕小說',
    'game'        => '遊戲',
    'vtuber'      => 'VTuber',
    'cosplay'     => 'Cosplay',
    'ai-tools'    => 'AI 工具',
    'voice-actor' => '聲優',
    'music'       => '音樂',
    'merchandise' => '周邊',
    'event'       => '活動',
    'industry'    => '產業',
];

// ── 頁面標題 / 副標題（依分類動態顯示，關鍵字導向） ──
$page_titles = [
    'announcement' => [ '本站公告', '官方訊息・系統公告・重要通知' ],
    'news'         => [ '動漫新聞', '最新動畫新聞・新番情報・聲優消息・活動報導，每日更新' ],
    'review'       => [ '動漫評論', '動畫評論・深度解析・心得分享・作品評價' ],
    'feature'      => [ '動漫專題', '深度專題・年度回顧・主題企劃' ],
];

if ( 'category' === $current_tax && isset( $page_titles[ $current_slug ] ) ) {
    $hero_title    = $page_titles[ $current_slug ][0];
    $hero_subtitle = $page_titles[ $current_slug ][1];
    $hero_badge    = $current_term->name;

    // ★ 若有 channel（例如 /news/anime/），組合更精準的關鍵字標題
    if ( $current_channel !== '' && isset( $channel_names[ $current_channel ] ) ) {
        $ch        = $channel_names[ $current_channel ];
        $base_word = str_replace( '動漫', '', $page_titles[ $current_slug ][0] ); // 新聞 / 評論 / 專題
        $hero_title    = $ch . $base_word;                         // 例：動畫新聞
        $hero_subtitle = $ch . '相關的最新' . $base_word . '，每日更新';
        $hero_badge    = $ch;
    }
} elseif ( $current_term ) {
    $hero_title    = single_term_title( '', false );
    $hero_subtitle = $current_term->description ?: '相關文章列表';
    $hero_badge    = $current_term->name;
} else {
    $hero_title    = '所有文章';
    $hero_subtitle = '';
    $hero_badge    = '文章';
}

// ── 共用：依目前 archive 過濾 tax_query ──
$archive_tax_query = [];
if ( $current_term ) {
    $archive_tax_query[] = [
        'taxonomy' => $current_tax,
        'field'    => 'term_id',
        'terms'    => $current_termid,
    ];
}

/* ============================================================
 * 稀薄內容判斷（在 get_header() 之前執行，才能趕上 <head> 輸出）
 *
 * 主查詢 $wp_query 在模板被載入前就已由 WP 解析完成（含 channel
 * query var 的 pre_get_posts 過濾），這裡直接讀 found_posts 即可，
 * 不需要重新查一次。
 * ============================================================ */
global $wp_query;
$paged       = max( 1, (int) get_query_var( 'paged' ) );
$found_posts = isset( $wp_query ) ? (int) $wp_query->found_posts : 0;
$is_empty    = ( $found_posts === 0 );
$is_thin     = ( $found_posts > 0 && $found_posts < CAT_THIN_THRESHOLD );
$is_paged    = ( $paged > 1 );
$is_combo    = ( 'category' === $current_tax && $current_channel !== '' ); // 分類×頻道組合頁

// 組合頁本來就是母分類頁的子集合，篇數再多也跟母頁高度重疊，
// 稀薄門檻可以比一般分類頁更嚴一點。
if ( $is_combo && ! $is_empty && $found_posts < CAT_THIN_THRESHOLD ) {
    $is_thin = true;
}

// ── Robots 訊號 ──
add_filter( 'wp_robots', function ( $robots ) use ( $is_empty, $is_thin, $is_paged ) {
    if ( $is_empty || $is_thin || ( CAT_NOINDEX_PAGED && $is_paged ) ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }
    return $robots;
} );

// ── Self-canonical ──
// 組合頁（/news/anime/）沒有原生 term_link，WP 核心無法自動判斷正確網址，
// 一定要自己補上，否則容易被當成跟母分類頁 /news/ 重複。
add_filter( 'get_canonical_url', function ( $canonical_url ) use ( $current_tax, $current_term, $current_channel, $current_slug, $paged ) {
    if ( 'category' === $current_tax && $current_channel !== '' ) {
        $url = home_url( '/' . $current_slug . '/' . $current_channel . '/' );
    } elseif ( $current_term instanceof WP_Term ) {
        $link = get_term_link( $current_term );
        $url  = is_wp_error( $link ) ? home_url( '/' ) : $link;
    } else {
        return $canonical_url;
    }
    if ( $paged > 1 ) {
        $url = trailingslashit( $url ) . 'page/' . $paged . '/';
    }
    return $url;
} );

get_header();

// ── 輪播：本分類最新 5 篇 ──
$carousel_query = new WP_Query( [
    'post_type'           => 'post',
    'posts_per_page'      => 5,
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'tax_query'           => $archive_tax_query,
] );

// ── 熱門：本分類留言數最多 5 篇 ──
$popular_query = new WP_Query( [
    'post_type'           => 'post',
    'posts_per_page'      => 5,
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'orderby'             => 'comment_count',
    'order'               => 'DESC',
    'tax_query'           => $archive_tax_query,
] );

// ── 熱門標籤（全站，transient 快取 6 小時） ──
global $wpdb;
$popular_tags = get_transient( 'smacg_popular_tags_15' );
if ( false === $popular_tags ) {
    $popular_tags = $wpdb->get_results( "
        SELECT t.term_id, t.name, t.slug, COUNT(tr.object_id) AS count
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
        WHERE tt.taxonomy = 'post_tag'
          AND p.post_type = 'post'
          AND p.post_status = 'publish'
        GROUP BY t.term_id
        ORDER BY count DESC
        LIMIT 15
    " );
    set_transient( 'smacg_popular_tags_15', $popular_tags, 6 * HOUR_IN_SECONDS );
}

// ── Filter Tabs ──
$filter_label   = '';
$filter_terms   = [];
$filter_all_url = '';
if ( 'category' === $current_tax ) {
    $filter_label   = '頻道';
    $filter_terms   = get_terms( [ 'taxonomy' => 'channel', 'hide_empty' => true ] );
    $filter_all_url = get_term_link( $current_term );
} elseif ( 'channel' === $current_tax ) {
    $filter_label   = '類型';
    $filter_terms   = get_categories( [
        'slug'       => [ 'announcement', 'news', 'review', 'feature' ],
        'hide_empty' => false,
    ] );
    $filter_all_url = get_term_link( $current_term );
}

/* ============================================================
 * ItemList JSON-LD
 *
 * 只輸出「本頁主迴圈」的文章清單，幫助搜尋引擎 / AI 理解
 * 這是一個有序的文章列表。稀薄/空白頁不輸出，避免對空內容宣告 schema。
 *
 * ★ 不輸出 CollectionPage / BreadcrumbList / WebSite，
 *   那些由 Rank Math 負責，避免 schema 重複。
 * ★ paged 用 max(1, get_query_var('paged')) 計算 position 起始值，
 *   讓第 2 頁的 position 接續第 1 頁。
 * ============================================================ */
$itemlist_elements = [];
if ( ! $is_empty && isset( $wp_query ) && $wp_query->have_posts() ) {
    $per_page = (int) get_query_var( 'posts_per_page' ) ?: get_option( 'posts_per_page' );
    $base_pos = ( $paged - 1 ) * $per_page;
    $pos      = 0;

    foreach ( $wp_query->posts as $p ) {
        $pos++;
        $itemlist_elements[] = [
            '@type'    => 'ListItem',
            'position' => $base_pos + $pos,
            'url'      => get_permalink( $p->ID ),
            'name'     => wp_strip_all_tags( get_the_title( $p->ID ) ),
        ];
    }
}
?>

<?php if ( ! empty( $itemlist_elements ) ) :
    $itemlist_schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => $hero_title,
        'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
        'numberOfItems'   => count( $itemlist_elements ),
        'itemListElement' => $itemlist_elements,
    ];
?>
<script type="application/ld+json"><?php
    echo wp_json_encode( $itemlist_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
?></script>
<?php endif; ?>

<!-- 本頁專用 CSS（Swiper 由 CDN，建議日後在地化） -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" media="print" onload="this.media='all'" />
<noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" /></noscript>
<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/news.css' ); ?>" />

<!-- ===== PAGE HERO ===== -->
<div class="page-hero">
  <div class="container">
    <div class="page-badge"><i class="fa-solid fa-newspaper"></i> <?php echo esc_html( $hero_badge ); ?></div>
    <h1 class="page-title"><?php echo esc_html( $hero_title ); ?></h1>
    <?php if ( $hero_subtitle ) : ?>
      <p class="page-subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
    <?php endif; ?>
  </div>
</div>

<!-- ===== MAIN ===== -->
<main class="container" style="padding: 32px 0 64px;">

<?php if ( $is_empty ) : ?>

  <!-- ── 空結果：明確的友善頁面，而非跟有內容頁一樣的完整殼版 ── -->
  <?php if ( ! empty( $filter_terms ) ) : ?>
  <div class="news-filter" id="news-filter-bar"
       data-base-slug="<?php echo esc_attr( $current_slug ); ?>"
       data-base-tax="<?php echo esc_attr( $current_tax ); ?>">
    <a href="<?php echo esc_url( $filter_all_url ); ?>"
       class="news-filter-btn<?php echo $current_channel === '' ? ' active' : ''; ?>"
       data-ajax="1" data-target="all">全部</a>
    <?php foreach ( $filter_terms as $t ) :
        if ( 'category' === $current_tax ) {
            $tab_url    = user_trailingslashit( home_url( '/' . $current_slug . '/' . $t->slug . '/' ) );
            $is_active  = ( $current_channel === $t->slug );
        } else {
            $link      = get_term_link( $t );
            $tab_url   = is_wp_error( $link ) ? home_url( '/' . $t->slug . '/' . $current_slug . '/' ) : $link;
            $is_active = false;
        }
    ?>
      <a href="<?php echo esc_url( $tab_url ); ?>"
         class="news-filter-btn<?php echo $is_active ? ' active' : ''; ?>"
         data-ajax="1" data-target="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="news-layout">
    <div class="news-main-grid" style="text-align:center; padding: 48px 24px;">
      <p style="font-size:16px; color:rgba(255,255,255,.75); margin-bottom:24px;">
        這個分類目前還沒有任何文章，之後有新內容上架會自動出現在這裡。你可以先看看其他頻道或分類。
      </p>
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn-primary">回到首頁看看其他內容</a>
    </div>

    <aside class="news-sidebar">
      <?php if ( ! empty( $popular_tags ) ) : ?>
      <div class="sidebar-widget glass">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-tags" style="color:var(--accent-blue);"></i> 熱門標籤
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
    </aside>
  </div>

<?php else : ?>

  <!-- ── 海報輪播 ── -->
  <?php if ( $carousel_query->have_posts() ) :
    $carousel_i = 0; ?>
  <div class="news-carousel-wrap">
    <div class="swiper news-swiper">
      <div class="swiper-wrapper">
        <?php while ( $carousel_query->have_posts() ) : $carousel_query->the_post();
          $carousel_i++;
          $is_first  = ( 1 === $carousel_i ); // 首屏第一張：優化 LCP
          $cats      = get_the_category();
          $cat_label = ! empty( $cats ) ? esc_html( $cats[0]->name ) : '最新';

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
                   <?php if ( $is_first ) : ?>loading="eager" fetchpriority="high"<?php else : ?>loading="lazy"<?php endif; ?> />
            <?php else : ?>
              <div class="carousel-no-img">📰</div>
            <?php endif; ?>

            <div class="swiper-slide-caption">
              <div class="swiper-slide-tag"><?php echo $cat_label; ?></div>
              <div class="swiper-slide-title"><?php the_title(); ?></div>
              <div class="swiper-slide-meta">
                <i class="fa-regular fa-clock"></i> <?php echo get_the_date( 'Y-m-d' ); ?>
                &nbsp;·&nbsp;
                <i class="fa-regular fa-user"></i> <?php the_author(); ?>
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

  <!-- ── Filter Tabs（頻道 / 類型切換，AJAX） ── -->
  <?php if ( ! empty( $filter_terms ) ) : ?>
  <div class="news-filter" id="news-filter-bar"
       data-base-slug="<?php echo esc_attr( $current_slug ); ?>"
       data-base-tax="<?php echo esc_attr( $current_tax ); ?>">

    <a href="<?php echo esc_url( $filter_all_url ); ?>"
       class="news-filter-btn<?php echo $current_channel === '' ? ' active' : ''; ?>"
       data-ajax="1"
       data-target="all">全部</a>

    <?php foreach ( $filter_terms as $t ) :
        // ★ 改用 get_term_link()，避免手動拼字串造成壞連結 / 重複內容
        if ( 'category' === $current_tax ) {
            // category 頁的 tab 指向「同分類 + 指定 channel」的組合 URL。
            // 此組合無原生 term_link，仍以 home_url 拼接，但加上 trailingslashit 與 user_trailingslashit 確保結構一致。
            $tab_url    = user_trailingslashit( home_url( '/' . $current_slug . '/' . $t->slug . '/' ) );
            $target_val = $t->slug;
            $is_active  = ( $current_channel === $t->slug );
        } else {
            $link = get_term_link( $t );
            $tab_url    = is_wp_error( $link ) ? home_url( '/' . $t->slug . '/' . $current_slug . '/' ) : $link;
            $target_val = $t->slug;
            $is_active  = false;
        }
    ?>
      <a href="<?php echo esc_url( $tab_url ); ?>"
         class="news-filter-btn<?php echo $is_active ? ' active' : ''; ?>"
         data-ajax="1"
         data-target="<?php echo esc_attr( $target_val ); ?>">
        <?php echo esc_html( $t->name ); ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="news-layout">

    <!-- ── 主要新聞區（AJAX 切換目標容器） ── -->
    <div class="news-main-grid"
         id="news-list-root"
         data-content-type="<?php echo esc_attr( $current_slug ); ?>"
         data-channel="<?php echo esc_attr( $current_channel ); ?>">

      <?php
      // 列表本體拆成 partial，AJAX 也回傳同一份內容。
      // ★ 分頁語意（the_posts_pagination）應由 news-list.php 輸出，
      //   以確保 ?paged=2 等 URL 對爬蟲可見。
      set_query_var( 'news_main_query', $wp_query );
      get_template_part( 'template-parts/news-list' );
      ?>

    </div>

    <!-- ── 側欄 ── -->
    <aside class="news-sidebar">

      <!-- 熱門新聞 -->
      <div class="sidebar-widget glass">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-fire" style="color:#f97316;"></i> 熱門文章
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
        <?php endif; ?>
      </div>

      <!-- 熱門標籤 -->
      <?php if ( ! empty( $popular_tags ) ) : ?>
      <div class="sidebar-widget glass">
        <div class="sidebar-widget-title">
          <i class="fa-solid fa-tags" style="color:var(--accent-blue);"></i> 熱門標籤
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


    </aside>
  </div>

<?php endif; ?>

</main>

<!-- Swiper JS（加 defer 降低 render-blocking） -->
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>
<script>
(function () {
  'use strict';
  function initSwiper() {
    if ( typeof Swiper === 'undefined' ) { return setTimeout( initSwiper, 80 ); }
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
  }
  if ( document.readyState !== 'loading' ) initSwiper();
  else document.addEventListener('DOMContentLoaded', initSwiper);
})();
</script>

<?php
get_footer();