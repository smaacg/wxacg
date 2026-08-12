<?php
/**
 * Search Results — 微笑動漫 weixiaoacg
 *
 * @package weixiaoacg
 * @version 1.5.0 (2026-07-24)
 *   - 卡片式列表 + 左側類型色條（動畫紫 / 漫畫橘 / 文章藍）
 *   - 類型標籤精緻化；資料庫（anime/manga）顯示專屬標籤
 *   - 無特色圖時自動抓內文第一張圖當縮圖
 * @version 1.4.0 (2026-07-24)
 *   - 新增分類篩選 Tab（全部 / 資料庫 / 文章）
 * @version 1.3.0 (2026-07-24)
 *   - 移除 meta 作者顯示
 * @version 1.2.0 (2026-07-24)
 *   - 巴哈式橫向列表
 * @version 1.1.0 (2026-06-23)
 *   - 修正無結果區塊舊連結 /season/ → /bangumi/
 */
get_header();

$search_query = get_search_query();
$total        = $GLOBALS['wp_query']->found_posts;
$cur_stype    = isset( $_GET['stype'] ) ? sanitize_key( $_GET['stype'] ) : '';

// ── [Thin Content 防護] 搜尋頁強制 noindex ──
// 避免惡意爬蟲或不相干關鍵字產生無限網址，導致被視為垃圾農場
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
    $robots['index']  = 'noindex';
    $robots['follow'] = 'follow';
    unset( $robots['noarchive'], $robots['nosnippet'] );
    return $robots;
} );
?>

<main class="search-page">

  <!-- ── Hero 搜尋欄 ── -->
  <section class="search-hero">
    <div class="container">
      <p class="search-hero-label">
        <?php if ( $search_query ) : ?>
          共找到 <strong><?php echo number_format( $total ); ?></strong> 筆關於
          「<span class="search-hero-kw"><?php echo esc_html( $search_query ); ?></span>」的結果
        <?php else : ?>
          請輸入搜尋關鍵字
        <?php endif; ?>
      </p>

      <form role="search" method="get" action="<?php echo esc_url( home_url('/') ); ?>" class="search-hero-form">
        <div class="search-hero-box">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input
            type="search"
            name="s"
            class="search-hero-input"
            value="<?php echo esc_attr( $search_query ); ?>"
            placeholder="搜尋動漫、角色、新聞…"
            autocomplete="off"
            aria-label="搜尋"
            autofocus
          >
          <?php if ( $search_query ) : ?>
            <a href="<?php echo esc_url( home_url('/') ); ?>" class="search-hero-clear" title="清除搜尋" aria-label="清除搜尋">
              <i class="fa-solid fa-xmark"></i>
            </a>
          <?php endif; ?>
          <button type="submit" class="search-hero-submit">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <span>搜尋</span>
          </button>
        </div>
      </form>
    </div>
  </section>

  <!-- ── 分類篩選 Tab ── -->
  <?php if ( $search_query ) :
    $base_url = home_url( '/' );
    $tabs = [
      ''     => '全部',
      'db'   => '資料庫',
      'post' => '文章',
    ];
  ?>
  <div class="container">
    <nav class="search-tabs" aria-label="搜尋分類篩選">
      <?php foreach ( $tabs as $key => $label ) :
        $url = add_query_arg(
          array_filter([ 's' => $search_query, 'stype' => $key ]),
          $base_url
        );
        $is_active = ( $cur_stype === $key );
      ?>
        <a href="<?php echo esc_url( $url ); ?>"
           class="search-tab<?php echo $is_active ? ' is-active' : ''; ?>">
          <?php echo esc_html( $label ); ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
  <?php endif; ?>

  <!-- ── 搜尋結果 ── -->
  <div class="container search-body">

    <?php if ( have_posts() ) : ?>

      <div class="search-results-grid">
        <?php while ( have_posts() ) : the_post();
          $ptype = get_post_type();
          $type_class = $ptype === 'anime' ? 'type-anime'
                      : ( $ptype === 'manga' ? 'type-manga' : 'type-post' );
        ?>

          <article class="search-card <?php echo esc_attr( $type_class ); ?>" id="post-<?php the_ID(); ?>">

            <!-- 內容 -->
            <div class="search-card-body">

              <!-- 分類／類型標籤 -->
              <?php if ( $ptype === 'anime' ) : ?>
                <span class="search-card-cat search-card-cat--anime">🎬 動畫</span>
              <?php elseif ( $ptype === 'manga' ) : ?>
                <span class="search-card-cat search-card-cat--manga">📖 漫畫</span>
              <?php else :
                $cats = get_the_category();
                if ( $cats ) :
                  $cat = $cats[0];
              ?>
                <a href="<?php echo esc_url( get_category_link( $cat->term_id ) ); ?>"
                   class="search-card-cat news-tag tag-blue">
                  <?php echo esc_html( $cat->name ); ?>
                </a>
              <?php
                endif;
              endif;
              ?>

              <!-- 標題 -->
              <h2 class="search-card-title">
                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
              </h2>

              <!-- 摘要 -->
              <p class="search-card-excerpt">
                <?php echo wp_trim_words( get_the_excerpt(), 30, '…' ); ?>
              </p>

              <!-- Meta -->
              <div class="search-card-meta">
                <span>
                  <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                  <?php echo get_the_date('Y年m月d日'); ?>
                </span>
                <?php if ( comments_open() ) : ?>
                  <span>
                    <i class="fa-regular fa-comment" aria-hidden="true"></i>
                    <?php comments_number('0', '1', '%'); ?>
                  </span>
                <?php endif; ?>
              </div>

            </div><!-- /.search-card-body -->

            <!-- 縮圖：優先特色圖，沒有則抓內文第一張圖 -->
            <?php
            $thumb_html = '';
            if ( has_post_thumbnail() ) {
              $thumb_html = get_the_post_thumbnail( get_the_ID(), 'medium', [
                'class'   => 'search-card-img',
                'loading' => 'lazy',
                'alt'     => get_the_title(),
              ] );
            } else {
              $content = get_the_content();
              if ( $content && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
                $thumb_html = sprintf(
                  '<img src="%s" class="search-card-img" loading="lazy" alt="%s">',
                  esc_url( $m[1] ),
                  esc_attr( get_the_title() )
                );
              }
            }
            ?>
            <?php if ( $thumb_html ) : ?>
              <a href="<?php the_permalink(); ?>" class="search-card-thumb" tabindex="-1" aria-hidden="true">
                <?php echo $thumb_html; ?>
              </a>
            <?php endif; ?>

          </article>

        <?php endwhile; ?>
      </div><!-- /.search-results-grid -->

      <!-- ── 分頁 ── -->
      <?php if ( $total > get_option('posts_per_page') ) : ?>
        <nav class="search-pagination" aria-label="搜尋結果分頁">
          <?php
          echo paginate_links([
            'prev_text' => '<i class="fa-solid fa-chevron-left"></i> 上一頁',
            'next_text' => '下一頁 <i class="fa-solid fa-chevron-right"></i>',
            'type'      => 'list',
          ]);
          ?>
        </nav>
      <?php endif; ?>

    <?php else : ?>

      <!-- ── 無結果 ── -->
      <div class="search-empty">
        <div class="search-empty-icon">🔍</div>
        <h2 class="search-empty-title">找不到相關結果</h2>
        <p class="search-empty-desc">
          沒有找到關於「<strong><?php echo esc_html( $search_query ); ?></strong>」的內容。<br>
          試試看其他關鍵字，或瀏覽以下熱門分類：
        </p>
        <div class="search-empty-links">
          <a href="<?php echo esc_url( home_url('/bangumi/') ); ?>" class="search-empty-btn">
            <i class="fa-solid fa-calendar-days"></i> 新番導覽
          </a>
          <a href="<?php echo esc_url( home_url('/anime/') ); ?>" class="search-empty-btn">
            <i class="fa-solid fa-database"></i> 動漫百科
          </a>
          <a href="<?php echo esc_url( home_url('/ranking/') ); ?>" class="search-empty-btn">
            <i class="fa-solid fa-trophy"></i> 排行榜
          </a>
          <a href="<?php echo esc_url( home_url('/news/') ); ?>" class="search-empty-btn">
            <i class="fa-solid fa-newspaper"></i> 最新新聞
          </a>
        </div>
      </div>

    <?php endif; ?>

  </div><!-- /.container -->

</main>

<?php get_footer(); ?>
