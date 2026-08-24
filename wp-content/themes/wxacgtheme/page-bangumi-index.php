<?php
/**
 * 新番表入口頁（/bangumi/）
 *
 * @package weixiaoacg
 * @version 1.4.0 (2026-08-24)
 *   - hero 導覽新增「檔期未定作品」按鈕，連到既有的 /upcoming-anime/
 *     （anime-sync-pro 的「製作決定但尚未播出」列表）。新番表只收錄
 *     AniList 已確認季度的作品，這裡讓「宣布動畫化但檔期還沒定」的
 *     作品有地方可以查，不會讓使用者以為站上沒收錄。
 * v1.3.0 (2026-06-23)
 *   - 修正 <title> 不輸出：移除 remove_action(_wp_render_title_tag)，
 *     改由 WordPress 正常輸出 <title>，內容仍由 pre_get_document_title 控制
 *   - 精選封面 WP_Query 加 ignore_sticky_posts，避免置頂文章干擾排序
 * v1.2.0 (2026-06-23)
 *   - hero 新增「上一季 / 下一季」導航按鈕（用 smacg_bangumi_shift_ym）
 *   - 下一季僅在有作品時顯示，避免點進空白頁（SEO 友善）
 * v1.1.0 (2026-06-21)
 *   - year_min / year_max 查詢排除 0 與非四位數（修正「0 至 2027 年」）
 *   - 季度卡 / 精選連結錨文字帶「YYYY年M月新番表」關鍵字
 *   - H1 維持「新番表」主關鍵字
 * v1.0.0 (2026-06-21) 302 跳轉改為實體著陸頁
 *
 * 載入方式：由 bangumi-loader.php 的 template_redirect（view=current）include
 * URL：/bangumi/
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
 * 1. 本季資訊 + 統計
 * ============================================================ */
$cur_ym  = smacg_bangumi_current_ym();
$cur_ctx = smacg_bangumi_parse_ym( $cur_ym );
$cur_url = home_url( "/bangumi/{$cur_ym}/" );

global $wpdb;

/* 小工具：查某一季的作品數 */
$smacg_count_season = function ( $year, $season ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		   FROM {$wpdb->posts} p
		   INNER JOIN {$wpdb->postmeta} my ON my.post_id = p.ID AND my.meta_key = 'anime_season_year'
		   INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = 'anime_season'
		  WHERE p.post_type   = 'anime'
		    AND p.post_status = 'publish'
		    AND my.meta_value = %d
		    AND ms.meta_value = %s",
		(int) $year,
		$season
	) );
};

/* 本季作品數 */
$cur_count = $smacg_count_season( $cur_ctx['year'], $cur_ctx['season'] );

/* ---- 上一季 / 下一季資訊（給 hero 導航按鈕用）---- */
$prev_ym    = smacg_bangumi_shift_ym( $cur_ym, -1 );   // 例：202604 → 202601
$prev_ctx   = smacg_bangumi_parse_ym( $prev_ym );
$prev_url   = home_url( "/bangumi/{$prev_ym}/" );
$prev_count = $smacg_count_season( $prev_ctx['year'], $prev_ctx['season'] );

$next_ym    = smacg_bangumi_shift_ym( $cur_ym, 1 );    // 例：202604 → 202607
$next_ctx   = smacg_bangumi_parse_ym( $next_ym );
$next_url   = home_url( "/bangumi/{$next_ym}/" );
$next_count = $smacg_count_season( $next_ctx['year'], $next_ctx['season'] );

/* 全站總作品數 + 涵蓋年份範圍（排除 0 與非四位數，修正「0 年」） */
$total_anime = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts}
	  WHERE post_type='anime' AND post_status='publish'"
);
$year_min = (int) $wpdb->get_var(
	"SELECT MIN(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta}
	  WHERE meta_key='anime_season_year'
	    AND meta_value REGEXP '^[0-9]{4}$'
	    AND CAST(meta_value AS UNSIGNED) >= 1960"
);
$year_max = (int) $wpdb->get_var(
	"SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta}
	  WHERE meta_key='anime_season_year'
	    AND meta_value REGEXP '^[0-9]{4}$'
	    AND CAST(meta_value AS UNSIGNED) <= 2100"
);
/* 保險：若查不到年份，用本季年份頂上，避免顯示 0 */
if ( $year_min <= 0 ) $year_min = (int) $cur_ctx['year'];
if ( $year_max <= 0 ) $year_max = (int) $cur_ctx['year'];

/* 本季精選封面（取人氣前 6 部當視覺）*/
$feat = new WP_Query( [
	'post_type'           => 'anime',
	'post_status'         => 'publish',
	'posts_per_page'      => 6,
	'no_found_rows'       => true,
	'ignore_sticky_posts' => true,
	'meta_query'          => [
		'relation' => 'AND',
		[ 'key' => 'anime_season',      'value' => $cur_ctx['season'], 'compare' => '=' ],
		[ 'key' => 'anime_season_year', 'value' => (int) $cur_ctx['year'], 'compare' => '=', 'type' => 'NUMERIC' ],
	],
	'orderby'             => 'meta_value_num',
	'meta_key'            => 'anime_popularity',
	'order'               => 'DESC',
] );
$feat_items = [];
if ( $feat->have_posts() ) {
	foreach ( $feat->posts as $fp ) {
		$cover = get_post_meta( $fp->ID, 'anime_cover_image', true );
		$title = get_post_meta( $fp->ID, 'anime_title_chinese', true ) ?: $fp->post_title;
		$feat_items[] = [
			'url'   => get_permalink( $fp->ID ),
			'title' => $title,
			'cover' => $cover,
		];
	}
}
wp_reset_postdata();

/* ============================================================
 * 2. 近年季度卡（當前年往回 2 年，共 3 年 × 4 季）
 * ============================================================ */
$season_order = [ 'WINTER', 'SPRING', 'SUMMER', 'FALL' ];
$season_meta  = [
	'WINTER' => [ 'zh' => '冬', 'icon' => '❄️', 'month' => '01', 'color' => '#67e8f9' ],
	'SPRING' => [ 'zh' => '春', 'icon' => '🌸', 'month' => '04', 'color' => '#f9a8d4' ],
	'SUMMER' => [ 'zh' => '夏', 'icon' => '🌊', 'month' => '07', 'color' => '#60a5fa' ],
	'FALL'   => [ 'zh' => '秋', 'icon' => '🍁', 'month' => '10', 'color' => '#fb923c' ],
];

$rows = $wpdb->get_results(
	"SELECT my.meta_value AS year, ms.meta_value AS season, COUNT(*) AS cnt
	   FROM {$wpdb->posts} p
	   INNER JOIN {$wpdb->postmeta} my ON my.post_id = p.ID AND my.meta_key = 'anime_season_year'
	   INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = 'anime_season'
	  WHERE p.post_type='anime' AND p.post_status='publish'
	    AND my.meta_value REGEXP '^[0-9]{4}$' AND ms.meta_value <> ''
	  GROUP BY my.meta_value, ms.meta_value",
	ARRAY_A
);
$cnt_map = [];
foreach ( $rows as $r ) {
	$cnt_map[ (int) $r['year'] ][ strtoupper( $r['season'] ) ] = (int) $r['cnt'];
}

$this_year  = (int) $cur_ctx['year'];
$show_years = [ $this_year, $this_year - 1, $this_year - 2 ];

/* ============================================================
 * 3. SEO
 * ============================================================ */
$canonical = home_url( '/bangumi/' );
$seo_title = sprintf( '新番表｜%d–%d 年日本動畫季度總覽・本季 %d 部 - 微笑動漫', $year_min, $year_max, $cur_count );
$seo_desc  = sprintf(
	'微笑動漫新番表，完整收錄 %d 至 %d 年日本動畫季度列表，本季（%s）共 %d 部。提供每部動畫的中文大綱、製作人員、OP/ED、PV 預告，以及台灣串流平台（巴哈動畫瘋、Netflix、Muse 木棉花、Ani-One 等）與海外播出資訊。',
	$year_min, $year_max, $cur_ctx['label'], $cur_count
);
$first_cover = $feat_items[0]['cover'] ?? '';

$seo_ctx = [
	'label'       => '新番表',
	'canonical'   => $canonical,
	'title'       => $seo_title,
	'description' => $seo_desc,
	'og_image'    => $first_cover,
];

if ( function_exists( 'smacg_bangumi_render_meta' ) ) {
	add_action( 'wp_head', function () use ( $seo_ctx ) { smacg_bangumi_render_meta( $seo_ctx ); }, 1 );
}
if ( function_exists( 'smacg_bangumi_render_og' ) ) {
	add_action( 'wp_head', function () use ( $seo_ctx ) { smacg_bangumi_render_og( $seo_ctx ); }, 2 );
}

/* ------------------------------------------------------------
 * <title> 處理（v1.3.0 修正）
 * 不再 remove_action _wp_render_title_tag —— 讓 WordPress 正常輸出 <title>，
 * 內容由 pre_get_document_title 篩選器決定。
 * （Blocksy 已有 add_theme_support('title-tag')，故 _wp_render_title_tag 會正常掛載）
 * ------------------------------------------------------------ */
add_filter( 'pre_get_document_title', function () use ( $seo_title ) { return $seo_title; }, 99 );

/* Schema：CollectionPage + WebSite + BreadcrumbList */
add_action( 'wp_head', function () use ( $seo_ctx, $canonical ) {
	$home = home_url( '/' );
	$graph = [
		[
			'@type'       => 'CollectionPage',
			'@id'         => $canonical . '#webpage',
			'url'         => $canonical,
			'name'        => $seo_ctx['title'],
			'description' => $seo_ctx['description'],
			'inLanguage'  => 'zh-TW',
			'isPartOf'    => [ '@id' => $home . '#website' ],
			'breadcrumb'  => [ '@id' => $canonical . '#breadcrumb' ],
		],
		[
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => '微笑動漫',
			'inLanguage'      => 'zh-TW',
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => $home . '?s={search_term_string}',
				],
				'query-input' => 'required name=search_term_string',
			],
		],
		[
			'@type'           => 'BreadcrumbList',
			'@id'             => $canonical . '#breadcrumb',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => '首頁',   'item' => $home ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => '新番表', 'item' => $canonical ],
			],
		],
	];
	echo '<script type="application/ld+json">' . wp_json_encode( [
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}, 3 );

get_header();
?>

<main class="bgm-arc-main">

  <nav class="bangumi-breadcrumb" aria-label="breadcrumb">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a>
    <i class="fa-solid fa-chevron-right" style="font-size:10px;opacity:.5;"></i>
    <span class="bc-current">新番表</span>
  </nav>

  <!-- ===== Hero ===== -->
  <section class="bgm-arc-hero">
    <div class="bgm-arc-hero-inner">
      <div class="bgm-arc-hero-badge">
        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i> 日本動畫季度總覽
      </div>
      <h1 class="bgm-arc-hero-title">新番表</h1>
      <p class="bgm-arc-hero-sub">
        完整收錄 <strong><?php echo (int) $year_min; ?></strong>–<strong><?php echo (int) $year_max; ?></strong> 年共
        <strong><?php echo (int) $total_anime; ?></strong> 部日本動畫，
        本季（<?php echo esc_html( $cur_ctx['label'] ); ?>）<strong><?php echo (int) $cur_count; ?></strong> 部
      </p>
      <nav class="bgm-arc-back">
        <?php if ( $prev_count > 0 ) : ?>
        <a class="bgm-nav-btn is-prev" href="<?php echo esc_url( $prev_url ); ?>"
           aria-label="前往<?php echo esc_attr( $prev_ctx['label'] ); ?>，共 <?php echo (int) $prev_count; ?> 部">
          ← <?php echo esc_html( $season_meta[ $prev_ctx['season'] ]['icon'] ); ?> 上一季：<?php echo esc_html( $prev_ctx['label'] ); ?>
        </a>
        <?php endif; ?>

        <a class="bgm-nav-btn is-current" href="<?php echo esc_url( $cur_url ); ?>">
          <?php echo esc_html( $season_meta[ $cur_ctx['season'] ]['icon'] ); ?> 查看<?php echo esc_html( $cur_ctx['label'] ); ?>
        </a>

        <?php if ( $next_count > 0 ) : ?>
        <a class="bgm-nav-btn is-next" href="<?php echo esc_url( $next_url ); ?>"
           aria-label="前往<?php echo esc_attr( $next_ctx['label'] ); ?>，共 <?php echo (int) $next_count; ?> 部">
          <?php echo esc_html( $season_meta[ $next_ctx['season'] ]['icon'] ); ?> 下一季：<?php echo esc_html( $next_ctx['label'] ); ?> →
        </a>
        <?php endif; ?>

        <a class="bgm-nav-btn is-archive" href="<?php echo esc_url( home_url( '/bangumi/archive/' ) ); ?>">📚 歷年存檔</a>
        <a class="bgm-nav-btn is-upcoming" href="<?php echo esc_url( home_url( '/upcoming-anime/' ) ); ?>">🎬 檔期未定作品</a>
      </nav>
    </div>
  </section>

  <!-- ===== 本季精選封面 ===== -->
  <?php if ( $feat_items ) : ?>
  <section class="bgm-index-feat">
    <h2 class="bgm-index-h2"><?php echo esc_html( $cur_ctx['label'] ); ?>　熱門作品</h2>
    <div class="bgm-index-feat-grid">
      <?php foreach ( $feat_items as $fi ) : ?>
        <a class="bgm-index-feat-card" href="<?php echo esc_url( $fi['url'] ); ?>" title="<?php echo esc_attr( $fi['title'] ); ?>">
          <?php if ( $fi['cover'] ) : ?>
            <img src="<?php echo esc_url( $fi['cover'] ); ?>" alt="<?php echo esc_attr( $fi['title'] ); ?>" loading="lazy">
          <?php endif; ?>
          <span class="bgm-index-feat-title"><?php echo esc_html( $fi['title'] ); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="bgm-index-feat-more">
      <a href="<?php echo esc_url( $cur_url ); ?>">看完整<?php echo esc_html( $cur_ctx['label'] ); ?> →</a>
    </div>
  </section>
  <?php endif; ?>

  <!-- ===== 各年季度卡 ===== -->
  <section class="bgm-index-seasons">
    <h2 class="bgm-index-h2">依季度瀏覽新番表</h2>
    <div class="bgm-arc-list">
      <?php foreach ( $show_years as $year ) :
        $year_total = array_sum( $cnt_map[ $year ] ?? [] );
      ?>
      <section class="bgm-arc-year">
        <header class="bgm-arc-year-head">
          <h3 class="bgm-arc-year-title">
            <span class="bgm-arc-year-num"><?php echo (int) $year; ?></span>
            <span class="bgm-arc-year-suffix">年新番表</span>
          </h3>
          <span class="bgm-arc-year-count"><?php echo (int) $year_total; ?> 部</span>
        </header>
        <div class="bgm-arc-seasons">
          <?php foreach ( $season_order as $s ):
            $cnt    = $cnt_map[ $year ][ $s ] ?? 0;
            $meta   = $season_meta[ $s ];
            $url    = home_url( "/bangumi/{$year}{$meta['month']}/" );
            $is_cur = ( $year === $this_year && $s === $cur_ctx['season'] );
            $kw     = sprintf( '%d年%d月新番表', $year, (int) $meta['month'] );
          ?>
          <a class="bgm-arc-season<?php echo $cnt === 0 ? ' is-empty' : ''; echo $is_cur ? ' is-current' : ''; ?>"
             href="<?php echo esc_url( $url ); ?>"
             title="<?php echo esc_attr( $kw ); ?>"
             aria-label="<?php echo esc_attr( $kw . '，共 ' . $cnt . ' 部' ); ?>"
             style="--s-color: <?php echo esc_attr( $meta['color'] ); ?>;"
             data-count="<?php echo (int) $cnt; ?>">
            <span class="bgm-arc-season-icon" aria-hidden="true"><?php echo $meta['icon']; ?></span>
            <span class="bgm-arc-season-name"><?php echo esc_html( $meta['zh'] ); ?>季</span>
            <span class="bgm-arc-season-cnt"><?php echo (int) $cnt; ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    </div>
    <div class="bgm-index-feat-more">
      <a href="<?php echo esc_url( home_url( '/bangumi/archive/' ) ); ?>">查看全部歷年新番表 →</a>
    </div>
  </section>

  <!-- ===== 介紹文 ===== -->
  <section class="bgm-index-intro">
    <h2 class="bgm-index-h2">關於微笑動漫新番表</h2>
    <p>微笑動漫新番表完整整理每一季（冬季 1 月、春季 4 月、夏季 7 月、秋季 10 月）的日本電視動畫播出資訊。每部作品都提供中文標題與大綱、製作公司、監督與原作、聲優卡司、OP/ED 主題曲、PV 預告片，以及最重要的——台灣可以在哪些串流平台觀看。</p>
    <p>我們特別整理了台灣常見的播放平台上架資訊，包含巴哈姆特動畫瘋、Netflix、Disney+、Muse 木棉花、Ani-One、Bilibili 台灣、friDay 影音、Hami Video、CatchPlay+、LINE TV 等，讓你不必到處找，一頁就能確認本季新番在台灣哪裡看得到。</p>
    <p>想看最新一季，點上方「查看<?php echo esc_html( $cur_ctx['label'] ); ?>」；想回顧過去作品，可從「依季度瀏覽新番表」或「歷年存檔」進入任一年份與季度。</p>
  </section>

</main>

<?php get_footer(); ?>
