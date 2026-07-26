<?php
/**
 * Single Post Template  v3.0  2026-06-05
 *
 * 變更（v3.0）：
 * - [SEO/AEO] 發布/更新時間改用語意化 <time datetime>。
 * - [SEO]     麵包屑補 BreadcrumbList JSON-LD（偵測 Rank Math 已輸出則略過，避免重複）。
 * - [GEO/AEO] article 使用語意標籤、正確 h1/h2 層級，利於 AI 與精選摘要擷取。
 * - [UX]      順序調整為：內文 → 上下篇 → 留言 → 延伸閱讀。
 * - 沿用 v2.5 職責分離版型與安全退場機制。
 *
 * Path: wp-content/themes/blocksy-child/single.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   判斷是否為真實單篇文章
   ============================================================ */
$smacg_bail = false;

if ( ! is_singular( 'post' ) || is_page() ) $smacg_bail = true;

if ( function_exists( 'um_is_core_page' ) && (
        um_is_core_page( 'user' )     || get_query_var( 'um_user' ) ||
        um_is_core_page( 'account' )  || um_is_core_page( 'login' ) ||
        um_is_core_page( 'register' ) || um_is_core_page( 'password-reset' ) ||
        um_is_core_page( 'logout' )   || um_is_core_page( 'members' )
   ) ) {
    $smacg_bail = true;
}

if ( function_exists( 'is_wpforo_page' ) && is_wpforo_page() ) {
    $smacg_bail = true;
}

$req_uri = $_SERVER['REQUEST_URI'] ?? '';
foreach ( [ '/community', '/account', '/forum' ] as $needle ) {
    if ( stripos( $req_uri, $needle ) !== false ) { $smacg_bail = true; break; }
}

/* ============================================================
   ★ 退場模式：最簡 page 渲染
   ============================================================ */
if ( $smacg_bail ) {
    get_header();
    ?>
    <main class="container single-wrap" style="padding: 24px 0 64px;">
      <article class="single-article">
        <div class="single-content">
          <?php
          if ( have_posts() ) {
              while ( have_posts() ) { the_post(); the_content(); }
          }
          ?>
        </div>
      </article>
    </main>
    <?php
    get_footer();
    return;
}

get_header();

/* ── 取得目前文章的 category + channel ─────────────────── */
$post_id      = get_the_ID();
$primary_cat  = null;
$primary_chan = null;

$cats = get_the_category( $post_id );
if ( ! empty( $cats ) ) {
    $editorial_slugs = [ 'announcement', 'news', 'review', 'feature' ];
    foreach ( $cats as $c ) {
        if ( in_array( $c->slug, $editorial_slugs, true ) ) { $primary_cat = $c; break; }
    }
    if ( ! $primary_cat ) $primary_cat = $cats[0];
}

$chans = get_the_terms( $post_id, 'channel' );
if ( ! empty( $chans ) && ! is_wp_error( $chans ) ) {
    $primary_chan = $chans[0];
}

/* ── 第二道防護：黑名單時不顯示分享列與相關文章 ────────── */
$smacg_skip_share_related = false;
$skip_slugs    = [ 'community', 'forum', 'forums', 'account', 'privacy' ];
$current_slug  = get_post_field( 'post_name', $post_id );
if ( in_array( $current_slug, $skip_slugs, true ) ) $smacg_skip_share_related = true;

/* ── 閱讀時間估算（中文約 400 字/分） ─────────────────── */
$content_text = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
$word_count   = mb_strlen( $content_text, 'UTF-8' );
$read_minutes = max( 1, (int) ceil( $word_count / 400 ) );

/* ── 同分類熱門文章（側欄） ──────────────────────────── */
$sidebar_tax_query = [];
if ( $primary_cat ) {
    $sidebar_tax_query[] = [
        'taxonomy' => 'category',
        'field'    => 'term_id',
        'terms'    => $primary_cat->term_id,
    ];
}
$popular_query = new WP_Query( [
    'post_type'           => 'post',
    'posts_per_page'      => 5,
    'post__not_in'        => [ $post_id ],
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'orderby'             => 'comment_count',
    'order'               => 'DESC',
    'tax_query'           => $sidebar_tax_query,
] );

/* ── 相關文章（同 category + 同 channel 優先） ───────── */
$related_args = [
    'post_type'           => 'post',
    'posts_per_page'      => 6,
    'post__not_in'        => [ $post_id ],
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
    'orderby'             => 'date',
    'order'               => 'DESC',
];
$related_tax = [];
if ( $primary_cat ) {
    $related_tax[] = [ 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $primary_cat->term_id ];
}
if ( $primary_chan ) {
    $related_tax[] = [ 'taxonomy' => 'channel', 'field' => 'term_id', 'terms' => $primary_chan->term_id ];
}
if ( count( $related_tax ) > 1 ) $related_tax['relation'] = 'AND';
if ( ! empty( $related_tax ) )   $related_args['tax_query'] = $related_tax;

$related_query = new WP_Query( $related_args );

if ( $related_query->found_posts < 6 && $primary_cat ) {
    $related_query = new WP_Query( [
        'post_type'           => 'post',
        'posts_per_page'      => 6,
        'post__not_in'        => [ $post_id ],
        'post_status'         => 'publish',
        'ignore_sticky_posts' => true,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'tax_query'           => [[ 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $primary_cat->term_id ]],
    ] );
}

/* ── 熱門標籤（僅統計 post 文章） ─────────────────────── */
global $wpdb;
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

/* ── 社群分享連結 ────────────────────────────────────── */
$share_url   = get_permalink();
$share_title = get_the_title();
$share_enc_u = rawurlencode( $share_url );
$share_enc_t = rawurlencode( $share_title );
$share_links = [
    'facebook' => [ 'name' => 'Facebook', 'icon' => 'fa-brands fa-facebook-f', 'color' => '#1877f2', 'url' => "https://www.facebook.com/sharer/sharer.php?u={$share_enc_u}" ],
    'x'        => [ 'name' => 'X',        'icon' => 'fa-brands fa-x-twitter',  'color' => '#000000', 'url' => "https://twitter.com/intent/tweet?url={$share_enc_u}&text={$share_enc_t}" ],
    'line'     => [ 'name' => 'LINE',     'icon' => 'fa-brands fa-line',       'color' => '#06c755', 'url' => "https://social-plugins.line.me/lineit/share?url={$share_enc_u}" ],
    'threads'  => [ 'name' => 'Threads',  'icon' => 'fa-brands fa-threads',    'color' => '#101010', 'url' => "https://www.threads.net/intent/post?text={$share_enc_t}%20{$share_enc_u}" ],
];

/* ── BreadcrumbList JSON-LD（Rank Math 已輸出則略過，避免重複） ── */
$smacg_output_breadcrumb_schema = ! ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) );
?>

<main class="container single-wrap">
  <div class="single-grid">

  <?php while ( have_posts() ) : the_post(); ?>

  <!-- ── 麵包屑 ── -->
  <nav class="breadcrumb" aria-label="麵包屑導覽">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a>
    <span class="sep" aria-hidden="true">›</span>
    <?php if ( $primary_cat ) : ?>
      <a href="<?php echo esc_url( get_term_link( $primary_cat ) ); ?>"><?php echo esc_html( $primary_cat->name ); ?></a>
      <span class="sep" aria-hidden="true">›</span>
    <?php endif; ?>
    <?php if ( $primary_chan ) : ?>
      <a href="<?php echo esc_url( get_term_link( $primary_chan ) ); ?>"><?php echo esc_html( $primary_chan->name ); ?></a>
      <span class="sep" aria-hidden="true">›</span>
    <?php endif; ?>
    <span class="current"><?php echo esc_html( wp_trim_words( get_the_title(), 16, '…' ) ); ?></span>
  </nav>

  <?php if ( $smacg_output_breadcrumb_schema ) :
      $bc_items = [];
      $bc_pos   = 1;
      $bc_items[] = [ '@type' => 'ListItem', 'position' => $bc_pos++, 'name' => '首頁', 'item' => home_url( '/' ) ];
      if ( $primary_cat )  $bc_items[] = [ '@type' => 'ListItem', 'position' => $bc_pos++, 'name' => $primary_cat->name,  'item' => get_term_link( $primary_cat ) ];
      if ( $primary_chan ) $bc_items[] = [ '@type' => 'ListItem', 'position' => $bc_pos++, 'name' => $primary_chan->name, 'item' => get_term_link( $primary_chan ) ];
      $bc_items[] = [ '@type' => 'ListItem', 'position' => $bc_pos++, 'name' => get_the_title(), 'item' => get_permalink() ];
      $bc_schema = [ '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $bc_items ];
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $bc_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
  <?php endif; ?>

  <article id="post-<?php the_ID(); ?>" <?php post_class( 'single-article' ); ?>>

    <!-- ── 標題區 ── -->
    <header class="single-header">
      <div class="single-tags">
        <?php if ( $primary_cat ) : ?>
          <a class="single-tag cat" href="<?php echo esc_url( get_term_link( $primary_cat ) ); ?>"><?php echo esc_html( $primary_cat->name ); ?></a>
        <?php endif; ?>
        <?php if ( $primary_chan ) : ?>
          <a class="single-tag chan" href="<?php echo esc_url( get_term_link( $primary_chan ) ); ?>"><?php echo esc_html( $primary_chan->name ); ?></a>
        <?php endif; ?>
      </div>

      <h1 class="single-title"><?php the_title(); ?></h1>

      <div class="single-meta">
        <span><i class="fa-regular fa-user" aria-hidden="true"></i> <?php the_author(); ?></span>
        <span>
          <i class="fa-regular fa-clock" aria-hidden="true"></i>
          <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'Y-m-d' ) ); ?></time>
        </span>
        <span><i class="fa-regular fa-eye" aria-hidden="true"></i> 約 <?php echo (int) $read_minutes; ?> 分鐘閱讀</span>
        <?php if ( comments_open() || get_comments_number() ) : ?>
        <span><i class="fa-regular fa-comment" aria-hidden="true"></i> <?php comments_number( '0 留言', '1 留言', '% 留言' ); ?></span>
        <?php endif; ?>
      </div>
    </header>

    <!-- ── 主圖 ── -->
    <?php if ( has_post_thumbnail() ) : ?>
    <figure class="single-cover">
      <?php the_post_thumbnail( 'full', [ 'alt' => get_the_title(), 'loading' => 'eager', 'fetchpriority' => 'high' ] ); ?>
    </figure>
    <?php endif; ?>

    <!-- ── 內容 ── -->
    <div class="single-content">
      <?php the_content(); ?>
      <?php wp_link_pages( [ 'before' => '<div class="single-pagelinks">頁次：', 'after' => '</div>' ] ); ?>
    </div>

    <!-- ── 文章標籤 ── -->
    <?php $post_tags = get_the_tags(); if ( $post_tags ) : ?>
    <div class="single-post-tags">
      <i class="fa-solid fa-tags" aria-hidden="true"></i>
      <?php foreach ( $post_tags as $t ) : ?>
        <a href="<?php echo esc_url( get_tag_link( $t->term_id ) ); ?>" class="tag-pill">#<?php echo esc_html( $t->name ); ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── 社群分享列 ── -->
    <?php if ( ! $smacg_skip_share_related ) : ?>
    <div class="single-share" aria-label="分享文章">
      <span class="share-label"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> 分享到</span>
      <div class="share-buttons">
        <?php foreach ( $share_links as $key => $s ) : ?>
          <a class="share-btn share-<?php echo esc_attr( $key ); ?>"
             href="<?php echo esc_url( $s['url'] ); ?>"
             target="_blank" rel="noopener noreferrer"
             style="--share-color: <?php echo esc_attr( $s['color'] ); ?>;"
             aria-label="分享到 <?php echo esc_attr( $s['name'] ); ?>"
             data-go-confirm="1">
            <i class="<?php echo esc_attr( $s['icon'] ); ?>" aria-hidden="true"></i>
            <span><?php echo esc_html( $s['name'] ); ?></span>
          </a>
        <?php endforeach; ?>
        <button type="button" class="share-btn share-copy" data-share-url="<?php echo esc_attr( $share_url ); ?>" aria-label="複製連結">
          <i class="fa-solid fa-link" aria-hidden="true"></i>
          <span>複製連結</span>
        </button>
      </div>
    </div>
    <?php endif; ?>

  </article>

  <!-- ── 上下篇 ── -->
  <nav class="single-nav" aria-label="文章導覽">
    <div class="single-nav-prev">
      <?php previous_post_link( '%link', '<span class="nav-label"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> 上一篇</span><span class="nav-title">%title</span>', true ); ?>
    </div>
    <div class="single-nav-next">
      <?php next_post_link( '%link', '<span class="nav-label">下一篇 <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span><span class="nav-title">%title</span>', true ); ?>
    </div>
  </nav>

  <!-- ── 留言（在延伸閱讀之前） ── -->
  <?php if ( comments_open() || get_comments_number() ) : ?>
  <section class="single-comments" aria-label="留言區">
    <?php comments_template(); ?>
  </section>
  <?php endif; ?>

  <!-- ── 延伸閱讀（黑名單時隱藏） ── -->
  <?php if ( ! $smacg_skip_share_related && $related_query->have_posts() ) : ?>
  <section class="related-section" aria-label="延伸閱讀">
    <h2 class="section-title"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> 延伸閱讀</h2>
    <div class="news-card-list related-grid">
      <?php while ( $related_query->have_posts() ) : $related_query->the_post();
        $rcats    = get_the_category();
        $rcat_lbl = ! empty( $rcats ) ? esc_html( $rcats[0]->name ) : '文章';
      ?>
      <a href="<?php the_permalink(); ?>" class="news-card glass">
        <div class="news-card-img">
          <?php if ( has_post_thumbnail() ) : ?>
            <?php the_post_thumbnail( 'medium', [ 'alt' => get_the_title(), 'loading' => 'lazy' ] ); ?>
          <?php else :
            preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', get_the_content(), $rm );
            if ( ! empty( $rm[1] ) ) : ?>
              <img src="<?php echo esc_url( $rm[1] ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy" />
            <?php else : ?>
              <span class="news-card-placeholder">📰</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="news-card-body">
          <div class="news-card-tag"><?php echo $rcat_lbl; ?></div>
          <div class="news-card-title"><?php the_title(); ?></div>
          <div class="news-card-meta">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'Y-m-d' ) ); ?></time>
          </div>
        </div>
      </a>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php endwhile; ?>

  <!-- ── 側欄 ── -->
  <aside class="news-sidebar single-sidebar">

    <?php if ( $popular_query->have_posts() ) : ?>
    <div class="sidebar-widget glass">
      <div class="sidebar-widget-title">
        <i class="fa-solid fa-fire" style="color:#f97316;" aria-hidden="true"></i>
        <?php echo $primary_cat ? esc_html( $primary_cat->name ) : ''; ?>熱門
      </div>
      <?php $pop_i = 0;
      while ( $popular_query->have_posts() ) : $popular_query->the_post();
        $pop_i++; $is_top = $pop_i <= 3 ? 'top-3' : '';
      ?>
      <a href="<?php the_permalink(); ?>" class="sidebar-list-item">
        <div class="sidebar-item-num <?php echo $is_top; ?>"><?php echo $pop_i; ?></div>
        <div>
          <div class="sidebar-item-title"><?php the_title(); ?></div>
          <div class="sidebar-item-date"><?php echo human_time_diff( get_the_time( 'U' ), time() ); ?>前</div>
        </div>
      </a>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $popular_tags ) ) : ?>
    <div class="sidebar-widget glass">
      <div class="sidebar-widget-title"><i class="fa-solid fa-tags" style="color:var(--accent-blue);" aria-hidden="true"></i> 熱門標籤</div>
      <div class="tag-cloud">
        <?php foreach ( $popular_tags as $tag ) : ?>
          <a href="<?php echo esc_url( get_tag_link( $tag->term_id ) ); ?>" class="tag-pill">#<?php echo esc_html( $tag->name ); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="sidebar-widget glass subscribe-box">
      <div class="sidebar-widget-title"><i class="fa-solid fa-bell" style="color:var(--accent-blue);" aria-hidden="true"></i> 訂閱快報</div>
      <p class="subscribe-desc">每週精選重要動漫資訊，直送你的信箱</p>
      <input type="email" class="subscribe-input" placeholder="your@email.com" />
      <button class="btn btn-primary subscribe-btn">訂閱</button>
    </div>

  </aside>

  </div><!-- /.single-grid -->
</main>

<!-- ── 複製連結互動 ── -->
<script>
(function(){
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.share-copy');
    if (!btn) return;
    e.preventDefault();
    var url = btn.getAttribute('data-share-url') || location.href;
    var done = function(){
      var span = btn.querySelector('span');
      if (!span) return;
      var orig = span.textContent;
      span.textContent = '已複製 ✓';
      btn.classList.add('copied');
      setTimeout(function(){ span.textContent = orig; btn.classList.remove('copied'); }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(function(){ prompt('請手動複製以下連結：', url); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = url; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (err) { prompt('請手動複製以下連結：', url); }
      document.body.removeChild(ta);
    }
  });
})();
</script>

<?php get_footer();
