<?php get_header(); ?>

<!-- ============================================================
     HERO
     ============================================================ -->
<?php
/* ── Hero 海報：手動指定圖片連結 ── */
$hero_posters = [
    [
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/07/5jrnQqTH.webp',
        'title' => '查看動漫',
        'url'   => home_url('/anime/'),
    ],
    [
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/07/Discord1.webp',
        'title' => '加入Discord',
        'url'   => ('https://discord.com/invite/yw73RBZgss'),
    ],
    [
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/07/zyYiYfQY.webp',
        'title' => '加入我們',
        'url'   => ('/join/'),
    ],
];

/* ── Hero 語錄：7 組動漫勵志語錄，隨機顯示一組 ── */
$hero_quotes = [
    [
        'line1'  => '拼命累積',
        'line2'  => '起來的東西',
        'line3'  => '絕對不會背叛自己',
        'source' => '《葬送的芙莉蓮》— 欣梅爾',
    ],
    [
        'line1'  => '你幹嘛要配合他們', 
        'line2'  => '你才是自己',
        'line3'  => '人生的主角啊 ',
        'source' => '《路人超能100》—靈幻新隆',
    ],
    [
        'line1'  => '我們總是在意自己',
        'line2'  => '錯過太多',
        'line3'  => '卻不曾注意自己擁有多少',
        'source' => '《我們仍未知道那天所看見的花名。》',
    ],
    [
        'line1'  => '我還在尋找',
        'line2'  => '我們的目的地',
        'line3'  => '我們奔跑的意義',
        'source' => '《強風吹拂》— 清瀨灰二',
    ],
    [
        'line1'  => '平凡的我啊',
        'line2'  => '你還有閒工',
        'line3'  => '夫垂頭喪氣嗎？',
        'source' => '《排球少年》— 田中龍之介',
    ],
    [
        'line1'  => '我其實不討厭努力',
        'line2'  => '學會原本不會的事',
        'line3'  => '其實也不錯不是嗎？',
        'source' => '《Re:從零開始的異世界生活》— 菜月昴',
    ],
    [
        'line1'  => '沒有人知道結果會怎樣',
        'line2'  => '只能選擇自己',
        'line3'  => '不會後悔的路',
        'source' => '《進擊的巨人》— 里維·阿卡曼',
    ],
];
$hero_quote = $hero_quotes[ array_rand( $hero_quotes ) ];
?>

<section class="hero-section" id="hero">
  <div class="hero-bg-layer" id="hero-bg"></div>
  <div class="hero-noise"></div>
  <div class="container hero-content-wrap">

    <div class="hero-text">
<div class="hero-eyebrow">
  <a href="<?php echo esc_url( home_url('/bangumi/') ); ?>" class="chip active chip-link">
    <i class="fa-solid fa-calendar-days"></i> 動漫新番表
  </a>
  <a href="<?php echo esc_url( home_url('/join/') ); ?>" class="chip chip-link">
    <i class="fa-solid fa-circle-info"></i> 微笑動漫組收人
  </a>
</div>
      <h1 class="hero-title">
        <?php echo esc_html( $hero_quote['line1'] ); ?><br>
        <span class="line-gradient"><?php echo esc_html( $hero_quote['line2'] ); ?></span><br>
        <span class="line-accent"><?php echo esc_html( $hero_quote['line3'] ); ?></span>
      </h1>
      <p class="hero-subtitle">
       <?php echo esc_html( $hero_quote['source'] ); ?><br />
      </p>

      <!-- 毛玻璃時鐘 -->
      <div class="hero-stats">
        <div class="hero-clock glass">
          <div class="hero-clock-time" id="hero-clock-time">--:--:--</div>
          <div class="hero-clock-bottom">
            <span class="hero-clock-date" id="hero-clock-date">---- / -- / --</span>
            <span class="hero-clock-sep">・</span>
            <span class="hero-clock-weekday" id="hero-clock-weekday">---</span>
          </div>
        </div>
      </div>

      <div class="hero-actions">
        <a href="#season-section" class="btn btn-primary">
          <i class="fa-solid fa-calendar-check"></i> 本季新番
        </a>
        <a href="<?php echo esc_url( home_url('/?wxacg_random_anime=1') ); ?>"
           class="btn btn-secondary"
           rel="nofollow">
          <i class="fa-solid fa-dice"></i> 抽一部動漫
        </a>
      </div>
    </div>

    <!-- Hero 海報 -->
    <div class="hero-posters" id="hero-posters">
      <?php foreach ( $hero_posters as $i => $poster ) : ?>
      <a href="<?php echo esc_url( $poster['url'] ); ?>"
         class="poster-item glass"
         title="<?php echo esc_attr( $poster['title'] ); ?>">
        <img src="<?php echo esc_url( $poster['img'] ); ?>"
             alt="<?php echo esc_attr( $poster['title'] ); ?>"
             width="250" height="480"
             <?php if ( $i === 0 ) : ?>loading="eager" fetchpriority="high"<?php else : ?>loading="lazy"<?php endif; ?>
             onerror="this.style.display='none';this.closest('.poster-item').classList.add('skeleton');">
        <span class="poster-item__title"><?php echo esc_html( $poster['title'] ); ?></span>
      </a>
      <?php endforeach; ?>
    </div>

  </div>
</section>

<script>
(function () {
    const timeEl   = document.getElementById('hero-clock-time');
    const dateEl   = document.getElementById('hero-clock-date');
    const weekEl   = document.getElementById('hero-clock-weekday');
    const weekdays = ['星期日','星期一','星期二','星期三','星期四','星期五','星期六'];

    function tick() {
        const now = new Date();
        const hh  = String(now.getHours()).padStart(2, '0');
        const mm  = String(now.getMinutes()).padStart(2, '0');
        const ss  = String(now.getSeconds()).padStart(2, '0');
        const y   = now.getFullYear();
        const mo  = String(now.getMonth() + 1).padStart(2, '0');
        const d   = String(now.getDate()).padStart(2, '0');

        if (timeEl) timeEl.textContent = `${hh}：${mm}：${ss}`;
        if (dateEl) dateEl.textContent = `${y} / ${mo} / ${d}`;
        if (weekEl) weekEl.textContent = weekdays[now.getDay()];
    }

    tick();
    setInterval(tick, 1000);
})();
</script>

<!-- ============================================================
     最新新聞（v2.4.0 — Tab 切換：全部 / 新聞 / 評論 / 專題）
     ============================================================ -->
<?php
/**
 * 重用 page-columns.php 的卡片渲染函式
 * 若 columns 頁面已執行過則函式已存在；否則於此載入
 */
if ( ! function_exists( 'asd_render_column_card' ) ) {
    $_columns_tpl = get_stylesheet_directory() . '/page-columns.php';
    // 不直接 include 整個 page 模板（會輸出 header/footer），
    // 改為在這裡定義一份等效的精簡版本作為備援
    if ( ! function_exists( 'asd_get_card_thumb_url' ) ) {
        function asd_get_card_thumb_url( $post_id ) {
            $thumb = get_the_post_thumbnail_url( $post_id, 'medium' );
            if ( $thumb ) return $thumb;
            $meta = get_post_meta( $post_id, 'anime_cover_image', true );
            if ( $meta ) return $meta;
            preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', get_post_field( 'post_content', $post_id ), $m );
            return $m[1] ?? '';
        }
    }
    function asd_render_column_card( $post ) {
        $pid   = $post->ID;
        $thumb = asd_get_card_thumb_url( $pid );
        $cats  = get_the_category( $pid );
        $cat   = ! empty( $cats ) ? esc_html( $cats[0]->name ) : '文章';
        $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
        ?>
        <a href="<?php echo esc_url( get_permalink( $pid ) ); ?>" class="news-card glass">
            <div class="news-card__thumb">
                <?php if ( $thumb ) : ?>
                    <img src="<?php echo esc_url( $thumb ); ?>"
                         alt="<?php echo esc_attr( get_the_title( $pid ) ); ?>"
                         loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="news-card__placeholder" style="display:none">
                        <i class="fa-solid fa-newspaper"></i>
                    </div>
                <?php else : ?>
                    <div class="news-card__placeholder">
                        <i class="fa-solid fa-newspaper"></i>
                    </div>
                <?php endif; ?>
                <span class="news-card__cat news-tag tag-rose"><?php echo $cat; ?></span>
            </div>
            <div class="news-card__body">
                <h3 class="news-card__title"><?php echo esc_html( get_the_title( $pid ) ); ?></h3>
                <?php if ( $excerpt ) : ?>
                    <p class="news-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
                <?php endif; ?>
                <div class="news-card__meta">
                    <span><i class="fa-regular fa-clock"></i>
                        <?php echo human_time_diff( get_post_time( 'U', false, $pid ), current_time( 'timestamp' ) ); ?>前
                    </span>
                </div>
            </div>
        </a>
        <?php
    }
}

/* ── 4 組 WP_Query 預載（PHP 一次撈，零延遲切換） ── */
$smacg_news_tabs = [
    'all' => [
        'label'   => '全部',
        'icon'    => 'fa-layer-group',
        'link'    => home_url( '/news/' ),
        'args'    => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'category_name'  => 'news,review,feature',
        ],
    ],
    'news' => [
        'label'   => '新聞',
        'icon'    => 'fa-newspaper',
        'link'    => home_url( '/news/' ),
        'args'    => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'category_name'  => 'news',
        ],
    ],
    'review' => [
        'label'   => '評論',
        'icon'    => 'fa-pen-fancy',
        'link'    => home_url( '/columns/' ),
        'args'    => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'category_name'  => 'review',
        ],
    ],
    'feature' => [
        'label'   => '專題',
        'icon'    => 'fa-bookmark',
        'link'    => home_url( '/columns/' ),
        'args'    => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'category_name'  => 'feature',
        ],
    ],
];

/* 預先執行所有 query 並存入 results */
foreach ( $smacg_news_tabs as $key => &$tab ) {
    $tab['query'] = new WP_Query( $tab['args'] );
}
unset( $tab );

/* ── 跑馬燈：取「全部」前 6 筆標題 ── */
$ticker_items = [];
if ( $smacg_news_tabs['all']['query']->have_posts() ) {
    foreach ( array_slice( $smacg_news_tabs['all']['query']->posts, 0, 6 ) as $p ) {
        $ticker_items[] = esc_html( $p->post_title );
    }
}
if ( empty( $ticker_items ) ) {
    $ticker_items = [
        'SPY×FAMILY Season 3 製作確認',
        '進擊的巨人 OST 原聲帶全球發行',
        '台灣 ACG 展覽 2026 舉辦日期公布',
        '咒術迴戰最終章動畫化正式宣布',
        'LiSA 台灣演唱會門票即日起開放購票',
    ];
}
?>

<section class="section" id="news-section">
  <div class="container">

    <!-- 標題列 -->
    <div class="section-header">
      <h2 class="section-title">
        <i class="fa-solid fa-newspaper" style="margin-right:8px;"></i>最新新聞
      </h2>
      <a href="<?php echo esc_url( home_url('/news/') ); ?>" class="section-link">
        查看全部 <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

    <!-- 跑馬燈 -->
    <div class="news-ticker-wrap">
      <span class="news-ticker-label"><i class="fa-solid fa-bolt"></i> 快訊</span>
      <div class="news-ticker-overflow">
        <div class="news-ticker-track" id="tickerTrack">
          <?php foreach ( $ticker_items as $item ) : ?>
            <span><?php echo $item; ?>&nbsp;&nbsp;·&nbsp;&nbsp;</span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Tab 切換按鈕 -->
    <div class="news-tabs" id="news-tabs" role="tablist">
      <?php $first = true; foreach ( $smacg_news_tabs as $key => $tab ) :
        $count = $tab['query']->found_posts;
      ?>
        <button type="button"
                class="news-tab-btn<?php echo $first ? ' active' : ''; ?>"
                role="tab"
                data-tab="<?php echo esc_attr( $key ); ?>"
                aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
          <i class="fa-solid <?php echo esc_attr( $tab['icon'] ); ?>"></i>
          <span class="news-tab-label"><?php echo esc_html( $tab['label'] ); ?></span>
          <?php if ( $count > 0 ) : ?>
            <span class="news-tab-count"><?php echo (int) $count; ?></span>
          <?php endif; ?>
        </button>
      <?php $first = false; endforeach; ?>
    </div>

    <!-- 4 個面板（PHP 預載，CSS 控制顯示） -->
    <?php $first = true; foreach ( $smacg_news_tabs as $key => $tab ) : ?>
      <div class="news-panel<?php echo $first ? ' active' : ''; ?>"
           id="news-panel-<?php echo esc_attr( $key ); ?>"
           role="tabpanel"
           <?php echo $first ? '' : 'hidden'; ?>>

        <?php if ( $tab['query']->have_posts() ) : ?>
          <div class="news-grid">
            <?php while ( $tab['query']->have_posts() ) :
              $tab['query']->the_post();
              asd_render_column_card( get_post() );
            endwhile; wp_reset_postdata(); ?>
          </div>

          <?php if ( $tab['query']->found_posts > 12 ) : ?>
            <div class="news-panel-more">
              <a href="<?php echo esc_url( $tab['link'] ); ?>" class="btn btn-secondary">
                查看更多<?php echo esc_html( $tab['label'] ); ?>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
            </div>
          <?php endif; ?>

        <?php else : ?>
          <div class="news-empty glass-mid">
            <span style="font-size:2rem;">📭</span>
            <p>目前尚無<?php echo esc_html( $tab['label'] ); ?>，請稍後回來查看。</p>
          </div>
        <?php endif; ?>

      </div>
    <?php $first = false; endforeach; ?>

  </div>
</section>

<script>
/* ── 最新新聞 Tab 切換（純 CSS 顯示控制，零延遲） ── */
(function () {
    const tabs   = document.querySelectorAll('#news-tabs .news-tab-btn');
    const panels = document.querySelectorAll('#news-section .news-panel');
    if ( !tabs.length || !panels.length ) return;

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const target = this.dataset.tab;

            tabs.forEach(function (t) {
                const isActive = t === tab;
                t.classList.toggle('active', isActive);
                t.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            panels.forEach(function (p) {
                const match = p.id === 'news-panel-' + target;
                p.classList.toggle('active', match);
                if ( match ) {
                    p.removeAttribute('hidden');
                } else {
                    p.setAttribute('hidden', '');
                }
            });
        });
    });
})();
</script>

<!-- ============================================================
     動漫新番表（原「本季新番導航」）
     ------------------------------------------------------------
     @version 1.4.0 — 2026-07-02
     Changelog:
       1.4.0 (2026-07-02) 修:黄泉使者等連載番被截掉(posts_per_page 120→300);
                          修:條件 A/B 放寬年份硬比對,改用 season + 集數/狀態輔助;
                          新增:三態徽章(連載中/即將開播/完結),修 NOT_YET_RELEASED 被誤標完結。
       1.3.0 (2026-07-02) 效能重寫:SQL 只留 season IN + format IN 兩個等值比對(可走索引),
                          年份/前一季連載判斷改到 PHP 迴圈,避開巢狀 OR 多重 JOIN 造成的逾時。
       1.1.0 (2026-06-22) 僅顯示 TV／TV 短番,排除劇場版/OVA/ONA/特別篇/MV。
       1.0.0 初版
     ============================================================ -->
<?php
/* ── 動態計算當前季度 ── */
$_current_month = (int) date('n');
$_current_year  = (int) date('Y');
$_current_day   = (int) date('N'); // 1=週一…7=週日

if ( $_current_month <= 3 )      { $_current_season = 'WINTER'; }
elseif ( $_current_month <= 6 )  { $_current_season = 'SPRING'; }
elseif ( $_current_month <= 9 )  { $_current_season = 'SUMMER'; }
else                              { $_current_season = 'FALL';   }

$_weekday_zh = [ 0=>'全部', 1=>'週一', 2=>'週二', 3=>'週三', 4=>'週四', 5=>'週五', 6=>'週六', 7=>'週日' ];

/* ── 計算「前一季」（給跨季連載用） ── */
$_prev_map = [
    'WINTER' => [ 'FALL',   $_current_year - 1 ],
    'SPRING' => [ 'WINTER', $_current_year     ],
    'SUMMER' => [ 'SPRING', $_current_year     ],
    'FALL'   => [ 'SUMMER', $_current_year     ],
];
list( $_prev_season, $_prev_year ) = $_prev_map[ $_current_season ];

/* ── [1.4.0] SQL 只用 season IN + format IN 兩個等值比對(可走索引),
   避開巢狀 OR 多重 JOIN。上限拉到 300 避免連載番被截掉。
   年份/前一季連載判斷移到 PHP 迴圈做。 */
$_season_query = new WP_Query( [
    'post_type'      => 'anime',
    'post_status'    => 'publish',
    'posts_per_page' => 300,
    'no_found_rows'  => true,
    'meta_query'     => [
        'relation' => 'AND',
        [ 'key' => 'anime_season', 'value' => [ $_current_season, $_prev_season ], 'compare' => 'IN' ],
        [ 'key' => 'anime_format', 'value' => [ 'TV', 'TV_SHORT' ],                'compare' => 'IN' ],
    ],
] );

/* ── 整理資料並分組 ── */
$_by_weekday = [ 0 => [] ];
for ( $i = 1; $i <= 7; $i++ ) $_by_weekday[$i] = [];

$_seen_pids = [];

if ( $_season_query->have_posts() ) :
    while ( $_season_query->have_posts() ) : $_season_query->the_post();
        $pid = get_the_ID();

        if ( isset( $_seen_pids[ $pid ] ) ) continue;
        $_seen_pids[ $pid ] = true;

        if ( strpos( get_post_field( 'post_name', $pid ), '.html' ) !== false ) continue;
        /* [1.4.1] 用 PHP 過濾(取代重的巢狀 OR meta_query) */
        $_pa_season = get_post_meta( $pid, 'anime_season', true );
        $_pa_year   = (int) get_post_meta( $pid, 'anime_season_year', true );
        $_pa_status = strtoupper( trim( (string) get_post_meta( $pid, 'anime_status', true ) ) );
        $_ep_total  = (int) get_post_meta( $pid, 'anime_episodes', true );
        $_ep_aired  = (int) get_post_meta( $pid, 'anime_episodes_aired', true );

        // 是否仍在連載:狀態 RELEASING,或已播集數未達總集數
        $_still_on = ( $_pa_status === 'RELEASING' )
                     || ( $_ep_total > 0 && $_ep_aired > 0 && $_ep_aired < $_ep_total );

        $_keep = false;
        // 條件 A:本季 + 本年 → 留
        if ( $_pa_season === $_current_season && $_pa_year === $_current_year ) {
            $_keep = true;
        }
        // 條件 B:前一季 + 前一季年份 + 仍在連載 → 留(修 4 月跨季番消失)
        elseif ( $_pa_season === $_prev_season && $_pa_year === $_prev_year && $_still_on ) {
            $_keep = true;
        }
        if ( ! $_keep ) continue;

        /* [1.4.1] 封面判斷移到 PHP(取代原 SQL 的 anime_cover_image != '') */
        $cover = get_post_meta( $pid, 'anime_cover_image', true )
                 ?: get_the_post_thumbnail_url( $pid, 'medium' );
        if ( ! $cover ) continue;

        $title    = get_post_meta( $pid, 'anime_title_chinese', true ) ?: get_the_title();
        // 日文標題 meta key:anime_title_native
        $title_jp = get_post_meta( $pid, 'anime_title_native', true ) ?: '';

        $site_score  = (float) get_post_meta( $pid, 'smacg_site_score', true );
        $anilist_raw = (float) get_post_meta( $pid, 'anime_score_anilist', true );
        if ( $site_score > 0 ) {
            $score = number_format( $site_score, 1 );
        } elseif ( $anilist_raw > 0 ) {
            $score = number_format( $anilist_raw / 10, 1 );
        } else {
            $score = '';
        }


        $status   = $_pa_status; // 已在上面取過
        $ep_total = $_ep_total;
        $ep_aired = $_ep_aired;

        $ep_label = '';
        if ( $ep_total > 0 ) {
            $ep_label = ( $ep_aired > 0 && $ep_aired < $ep_total )
                ? "{$ep_aired}/{$ep_total} 集"
                : "{$ep_total} 集";
        } elseif ( $ep_aired > 0 ) {
            $ep_label = "第 {$ep_aired} 集";
        }

        $weekday     = 0;
        $next_airing = get_post_meta( $pid, 'anime_next_airing', true );
        if ( $next_airing ) {
            $ts = strtotime( $next_airing );
            if ( $ts ) $weekday = (int) date( 'N', $ts );
        }
        if ( ! $weekday ) {
            $start = get_post_meta( $pid, 'anime_start_date', true );
            if ( $start ) {
                $ts = strtotime( $start );
                if ( $ts ) $weekday = (int) date( 'N', $ts );
            }
        }

        $post_data = [
            'pid'         => $pid,
            'title'       => $title,
            'title_jp'    => $title_jp,
            'cover'       => $cover,
            'score'       => $score,
            'status'      => $status,
            'ep_total'    => $ep_total,
            'ep_aired'    => $ep_aired,
            'next_airing' => $next_airing,
            'ep_label'    => $ep_label,
            'weekday'     => $weekday,
            'url'         => get_permalink( $pid ),
        ];

        $_by_weekday[0][] = $post_data;
        if ( $weekday >= 1 && $weekday <= 7 ) $_by_weekday[$weekday][] = $post_data;

    endwhile;
    wp_reset_postdata();
endif;

$_season_total = count( $_by_weekday[0] );
?>

<section class="section season-section" id="season-section">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">
        <i class="fa-solid fa-calendar-days" style="margin-right:8px;"></i> 本季 TV 新番
      </h2>

      <!-- 星期 Tabs -->
      <div class="tab-switch weekday-tabs" id="weekday-tabs">
        <?php foreach ( $_weekday_zh as $d => $label ) :
          $cnt = count( $_by_weekday[$d] );
          if ( $d > 0 && $cnt === 0 ) continue;
          $is_active = ( $d === $_current_day ) ? ' active' : ( $d === 0 && $_current_day === 0 ? ' active' : '' );
        ?>
        <button class="tab-btn weekday-tab<?php echo $is_active; ?>"
                data-day="<?php echo $d; ?>">
          <?php echo esc_html( $label ); ?>
          <?php if ( $d > 0 && $cnt > 0 ) : ?>
            <span style="font-size:10px;opacity:.65;margin-left:3px;"><?php echo $cnt; ?></span>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>

      <a href="<?php echo esc_url( home_url('/bangumi/') ); ?>" class="section-link">
        看完整新番表 <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

    <?php if ( $_season_total > 0 ) : ?>
    <div class="season-cards scroll-row" id="season-cards">

      <?php foreach ( $_by_weekday as $_day_id => $_day_posts ) :
        $is_current = ( $_day_id === $_current_day ) || ( $_day_id === 0 && $_current_day === 0 );
      ?>
      <div class="sf-day-group"
           data-group="<?php echo esc_attr( $_day_id ); ?>"
           style="display:<?php echo $is_current ? 'contents' : 'none'; ?>;">

        <?php foreach ( $_day_posts as $p ) :
          /* [1.4.0] 三態判斷:連載中 / 即將開播 / 完結 */
          $status_raw  = strtoupper( trim( (string) $p['status'] ) );
          $is_airing   = ( $status_raw === 'RELEASING' );
          $is_upcoming = ( $status_raw === 'NOT_YET_RELEASED' );

          // 容錯:狀態不明但有下集時間或集數進度 → 視為連載中
          if ( ! $is_airing && ! $is_upcoming && $status_raw !== 'FINISHED' && $status_raw !== 'CANCELLED' ) {
              if ( ! empty( $p['next_airing'] )
                   || ( $p['ep_total'] > 0 && $p['ep_aired'] > 0 && $p['ep_aired'] < $p['ep_total'] ) ) {
                  $is_airing = true;
              }
          }

          if ( $is_airing ) {
              $status_label = '連載中'; $status_class = 'status--on-air';
          } elseif ( $is_upcoming ) {
              $status_label = '即將開播'; $status_class = 'status--upcoming';
          } else {
              $status_label = '完結'; $status_class = 'status--finished';
          }

          $day_label = ( $_day_id === 0 && $p['weekday'] >= 1 ) ? $_weekday_zh[ $p['weekday'] ] : '';
        ?>
        <a href="<?php echo esc_url( $p['url'] ); ?>"
           class="season-card glass"
           data-day="<?php echo esc_attr( $p['weekday'] ); ?>"
           title="<?php echo esc_attr( $p['title'] ); ?>">

          <?php if ( $day_label ) : ?>
            <span class="season-card-day-badge"><?php echo esc_html( $day_label ); ?></span>
          <?php endif; ?>
          <?php if ( $is_airing ) : ?>
            <span class="season-card-airing"></span>
          <?php endif; ?>

          <div class="season-card__cover-wrap">
            <?php if ( $p['cover'] ) : ?>
              <img src="<?php echo esc_url( $p['cover'] ); ?>"
                   alt="<?php echo esc_attr( $p['title'] ); ?>"
                   class="season-card-img" loading="lazy"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
              <div class="season-card-img-ph" style="display:none;">🎬</div>
            <?php else : ?>
              <div class="season-card-img-ph">🎬</div>
            <?php endif; ?>

            <span class="season-card__status <?php echo esc_attr( $status_class ); ?>">
              <?php echo esc_html( $status_label ); ?>
            </span>
          </div>

          <div class="season-card-body">
            <div class="season-card-title"><?php echo esc_html( $p['title'] ); ?></div>
            <?php if ( $p['title_jp'] && $p['title_jp'] !== $p['title'] ) : ?>
              <div class="season-card-jp"><?php echo esc_html( $p['title_jp'] ); ?></div>
            <?php endif; ?>
            <div class="season-card-meta">
              <?php if ( $p['score'] ) : ?>
                <span class="season-card-score">★ <?php echo esc_html( $p['score'] ); ?></span>
              <?php endif; ?>
              <?php if ( $p['ep_label'] ) : ?>
                <span class="season-card-ep" style="font-size:11px;color:var(--text-muted);">
                  <?php echo esc_html( $p['ep_label'] ); ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

        </a>
        <?php endforeach; ?>

      </div><!-- .sf-day-group -->
      <?php endforeach; ?>

    </div><!-- #season-cards -->

    <?php else : ?>
    <div class="season-empty glass">
      <i class="fa-solid fa-calendar-xmark fa-2x" aria-hidden="true"></i>
      <p>本季 TV 新番資料準備中,敬請期待。</p>
    </div>
    <?php endif; ?>

  </div>
</section>

<script>
(function () {
    const tabs   = document.querySelectorAll('#weekday-tabs .weekday-tab');
    const groups = document.querySelectorAll('#season-cards .sf-day-group');
    if ( !tabs.length ) return;

    function showDay(day) {
        tabs.forEach(t => t.classList.toggle('active', t.dataset.day == day));
        groups.forEach(function(g) {
            const match = parseInt(g.dataset.group) === parseInt(day);
            g.style.display = match ? 'contents' : 'none';
        });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            showDay(this.dataset.day);
        });
    });

    const jsDay = new Date().getDay();
    const today = jsDay === 0 ? 7 : jsDay;
    const todayTab = document.querySelector(`#weekday-tabs .weekday-tab[data-day="${today}"]`);
    if (todayTab) {
        showDay(today);
    } else {
        showDay(0);
    }
})();
</script>


<!-- ============================================================
     熱門作品
     ============================================================ -->
<?php
/* ── 動態計算：下一季 ── */
$current_month = (int) date('n');
$current_year  = (int) date('Y');

if ( $current_month >= 1 && $current_month <= 3 ) {
    $next_season = 'SPRING'; $next_year = $current_year;
} elseif ( $current_month >= 4 && $current_month <= 6 ) {
    $next_season = 'SUMMER'; $next_year = $current_year;
} elseif ( $current_month >= 7 && $current_month <= 9 ) {
    $next_season = 'FALL';   $next_year = $current_year;
} else {
    $next_season = 'WINTER'; $next_year = $current_year + 1;
}

if ( ! function_exists( 'smacg_get_anime' ) ) {
    function smacg_get_anime( $orderby_meta, $order = 'DESC', $extra_meta = [] ) {
        $args = [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'meta_key'       => $orderby_meta,
            'orderby'        => 'meta_value_num',
            'order'          => $order,
            'no_found_rows'  => true,
        ];
        if ( ! empty( $extra_meta ) ) {
            $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $extra_meta );
        }
        return new WP_Query( $args );
    }
}

if ( ! function_exists( 'smacg_anime_card' ) ) {
    function smacg_anime_card( $post ) {
        $id    = $post->ID;
        $title = get_post_meta( $id, 'anime_title_chinese', true ) ?: $post->post_title;
        $cover = get_post_meta( $id, 'anime_cover_image', true );
        $score = get_post_meta( $id, 'anime_score_site', true );
        $url   = get_permalink( $id );

        $score_display = $score ? number_format( (float) $score, 1 ) : null;
        $fb = mb_substr( $title, 0, 2 );
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="wxacg-anime-card">
            <div class="wxacg-card-thumb">
                <?php if ( $cover ) : ?>
                    <img src="<?php echo esc_url( $cover ); ?>"
                         alt="<?php echo esc_attr( $title ); ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="wxacg-card-fb" style="display:none"><span><?php echo esc_html( $fb ); ?></span></div>
                <?php else : ?>
                    <div class="wxacg-card-fb"><span><?php echo esc_html( $fb ); ?></span></div>
                <?php endif; ?>
                <?php if ( $score_display ) : ?>
                    <span class="wxacg-card-score">
                        <i class="fa-solid fa-star"></i> <?php echo esc_html( $score_display ); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="wxacg-card-body">
                <h3 class="wxacg-card-title"><?php echo esc_html( $title ); ?></h3>
            </div>
        </a>
        <?php
    }
}
?>

<section class="section" id="hot-anime-section">
  <div class="container">

    <div class="section-header">
      <h2 class="section-title">熱門作品</h2>
      <div class="tab-switch">
        <button class="smacg-tab-btn active" data-tab="trending">大家都在看</button>
        <button class="smacg-tab-btn" data-tab="top">歷年神作</button>
        <button class="smacg-tab-btn" data-tab="upcoming">即將開播</button>
      </div>
      <a href="<?php echo esc_url( home_url('/anime/') ); ?>" class="section-link">
        更多作品 <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

    <div class="wxacg-anime-grid" id="wxacg-tab-trending">
        <?php
        $q = smacg_get_anime( 'anime_score_site_count' );
        if ( $q->have_posts() ) :
            while ( $q->have_posts() ) { $q->the_post(); smacg_anime_card( get_post() ); }
            wp_reset_postdata();
        else : ?>
            <p class="smacg-tab-empty">暫無資料</p>
        <?php endif; ?>
    </div>

    <div class="wxacg-anime-grid" id="wxacg-tab-top" style="display:none">
        <?php
        $q = smacg_get_anime( 'anime_score_site' );
        if ( $q->have_posts() ) :
            while ( $q->have_posts() ) { $q->the_post(); smacg_anime_card( get_post() ); }
            wp_reset_postdata();
        else : ?>
            <p class="smacg-tab-empty">暫無資料</p>
        <?php endif; ?>
    </div>

    <div class="wxacg-anime-grid" id="wxacg-tab-upcoming" style="display:none">
        <?php
        $q = new WP_Query( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => 'anime_season',      'value' => $next_season, 'compare' => '=' ],
                [ 'key' => 'anime_season_year', 'value' => $next_year,   'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ] );
        if ( $q->have_posts() ) :
            while ( $q->have_posts() ) { $q->the_post(); smacg_anime_card( get_post() ); }
            wp_reset_postdata();
        else : ?>
            <p class="smacg-tab-empty">下一季暫無資料</p>
        <?php endif; ?>
    </div>

  </div>
</section>

<script>
(function () {
    const btns   = document.querySelectorAll('#hot-anime-section .smacg-tab-btn');
    const panels = {
        trending : document.getElementById('wxacg-tab-trending'),
        top      : document.getElementById('wxacg-tab-top'),
        upcoming : document.getElementById('wxacg-tab-upcoming'),
    };

    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = this.dataset.tab;
            btns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            Object.keys(panels).forEach(function (key) {
                panels[key].style.display = key === target ? 'grid' : 'none';
            });
        });
    });
})();
</script>

<!-- ============================================================
     未來場景 Coming Soon
     ============================================================ -->
<section class="section coming-soon-section">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title">微笑動漫未來場景</h2>
        <p style="font-size:13px; color:var(--text-muted); margin-top:6px;">更多精彩，陸續展開</p>
      </div>
    </div>
    <div class="coming-cards-grid">

      <div class="coming-card glass">
        <div class="coming-card-icon">🎵</div>
        <div class="coming-card-title">動漫音樂</div>
        <div class="coming-card-desc">OP/ED 主題曲・OST 原聲帶・聲優演唱會情報</div>
        <span class="coming-card-btn"><i class="fa-solid fa-clock"></i> 敬請期待</span>
      </div>

      <div class="coming-card glass">
        <div class="coming-card-icon">🎭</div>
        <div class="coming-card-title">Cosplayer</div>
        <div class="coming-card-desc">人氣 Coser 介紹・活動場照・作品妝造解析</div>
        <span class="coming-card-btn"><i class="fa-solid fa-clock"></i> 敬請期待</span>
      </div>

      <div class="coming-card glass">
        <div class="coming-card-icon">📺</div>
        <div class="coming-card-title">Vtuber</div>
        <div class="coming-card-desc">主流 Vtuber 介紹・熱門剪輯・直播動態</div>
        <span class="coming-card-btn"><i class="fa-solid fa-clock"></i> 敬請期待</span>
      </div>

      <div class="coming-card glass">
        <div class="coming-card-icon">📚</div>
        <div class="coming-card-title">漫畫小說</div>
        <div class="coming-card-desc">原作漫畫・輕小說・改編作品對照</div>
        <span class="coming-card-btn"><i class="fa-solid fa-clock"></i> 敬請期待</span>
      </div>

    </div>
  </div>
</section>

<!-- ============================================================
     會員 CTA（v2.0 — 改用 level-guide 真實 6 階資料）
     ============================================================ -->
<?php
/* ── 6 階會員稱號（與 level-guide 同步） ── */
$smacg_tiers = [
    [ 'tier' => 1, 'key' => 'rookie',   'title' => '新進會員', 'icon' => '🌱', 'color' => '#8d99ae', 'min_level' => 1,   'min_exp' => 5,      'tag' => 'tag-cyan'   ],
    [ 'tier' => 2, 'key' => 'newcomer', 'title' => '新客',     'icon' => '🌿', 'color' => '#06a77d', 'min_level' => 10,  'min_exp' => 500,    'tag' => 'tag-green'  ],
    [ 'tier' => 3, 'key' => 'regular',  'title' => '常客',     'icon' => '📺', 'color' => '#3a86ff', 'min_level' => 30,  'min_exp' => 4500,   'tag' => 'tag-blue'   ],
    [ 'tier' => 4, 'key' => 'expert',   'title' => '熟客',     'icon' => '🎬', 'color' => '#6a4c93', 'min_level' => 70,  'min_exp' => 24500,  'tag' => 'tag-purple' ],
    [ 'tier' => 5, 'key' => 'vip',      'title' => 'VIP',      'icon' => '👑', 'color' => '#b8860b', 'min_level' => 120, 'min_exp' => 72000,  'tag' => 'tag-orange' ],
    [ 'tier' => 6, 'key' => 'black',    'title' => '黑卡會員', 'icon' => '💎', 'color' => '#1a1a1a', 'min_level' => 200, 'min_exp' => 200000, 'tag' => 'tag-locked' ],
];

/* ── 已登入用戶：取得當前等級資訊 ── */
$smacg_user_info = null;
if ( is_user_logged_in() && function_exists( 'wxacg_get_user_level_info' ) ) {
    $smacg_user_info = wxacg_get_user_level_info( get_current_user_id() );
}
$smacg_user_level    = is_array( $smacg_user_info ) ? (int) ( $smacg_user_info['level']    ?? 0 ) : 0;
$smacg_user_exp      = is_array( $smacg_user_info ) ? (int) ( $smacg_user_info['exp']      ?? 0 ) : 0;
$smacg_user_tier_key = is_array( $smacg_user_info ) ? (string) ( $smacg_user_info['tier_key'] ?? '' ) : '';
$smacg_user_percent  = is_array( $smacg_user_info ) ? (int) ( $smacg_user_info['percent']  ?? 0 ) : 0;
$smacg_to_next       = is_array( $smacg_user_info ) ? (int) ( $smacg_user_info['to_next']  ?? 0 ) : 0;
?>

<section class="section member-cta-section">
  <div class="container">
    <div class="member-cta-grid">

      <!-- 左欄：CTA 文案 + 按鈕 -->
      <div class="member-cta-left">
        <span class="member-cta-badge"><i class="fa-solid fa-user-plus"></i> 免費加入會員</span>
        <h2 class="member-cta-title">打造你的玻璃收藏牆</h2>
        <p class="member-cta-desc">收藏作品・追番進度・私房清單・解鎖成就・展示頁面</p>

        <div class="member-cta-btns">
          <?php if ( is_user_logged_in() ) : ?>
            <a href="<?php echo esc_url( function_exists('wxacg_get_member_center_url') ? wxacg_get_member_center_url() : home_url('/') ); ?>"
               class="btn btn-primary">
              <i class="fa-solid fa-user"></i> 前往會員中心
            </a>
          <?php else : ?>
            <button type="button" class="btn btn-primary" id="smacg-cta-register-btn">
              <i class="fa-solid fa-user-plus"></i> 免費註冊
            </button>
          <?php endif; ?>

          <a href="<?php echo esc_url( home_url('/level-guide/') ); ?>" class="btn btn-secondary">
            <i class="fa-solid fa-compass"></i> 探索功能
          </a>
        </div>
      </div>

      <!-- 右欄：會員成長路徑 -->
      <div class="member-level-panel glass-mid">

        <?php if ( $smacg_user_info ) : ?>
          <!-- 已登入：顯示當前進度 -->
          <div class="member-level-progress-card">
            <div class="mlp-row1">
              <span class="mlp-tier-icon" style="color: <?php echo esc_attr( $smacg_user_info['color'] ?? '#fff' ); ?>;">
                <?php echo esc_html( $smacg_user_info['icon'] ?? '🌱' ); ?>
              </span>
              <div class="mlp-row1-text">
                <div class="mlp-tier-name">
                  <?php echo esc_html( $smacg_user_info['title'] ?? '會員' ); ?>
                  <span class="mlp-lv">Lv.<?php echo esc_html( $smacg_user_level ); ?></span>
                </div>
                <div class="mlp-exp-line">
                  EXP <strong><?php echo number_format( $smacg_user_exp ); ?></strong>
                  <?php if ( ! ( $smacg_user_info['is_max'] ?? false ) ) : ?>
                    ・距下一級還差 <strong><?php echo number_format( $smacg_to_next ); ?></strong>
                  <?php else : ?>
                    ・已達最高等級
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="mlp-bar">
              <div class="mlp-bar-fill" style="width: <?php echo (int) $smacg_user_percent; ?>%;"></div>
            </div>
            <div class="mlp-bar-percent"><?php echo (int) $smacg_user_percent; ?>%</div>
          </div>
        <?php endif; ?>

        <div class="member-level-title">
          <?php echo $smacg_user_info ? '會員成長路徑' : '6 階會員成長路徑'; ?>
        </div>

        <div class="member-level-list">
          <?php foreach ( $smacg_tiers as $tier ) :
            $is_reached = $smacg_user_info && $smacg_user_level >= $tier['min_level'];
            $is_current = $smacg_user_info && $smacg_user_tier_key === $tier['key'];
            $is_locked  = in_array( $tier['key'], [ 'vip', 'black' ], true );

            $item_class = 'member-level-item';
            if ( $is_locked && ! $is_reached )           $item_class .= ' member-level-locked';
            if ( $is_reached )                            $item_class .= ' member-level-reached';
            if ( $is_current )                            $item_class .= ' member-level-current';
          ?>
          <div class="<?php echo esc_attr( $item_class ); ?>">
            <div class="member-level-icon"><?php echo esc_html( $tier['icon'] ); ?></div>
            <div class="member-level-info">
              <div class="member-level-name">
                Lv.<?php echo (int) $tier['min_level']; ?>
                <?php if ( $is_current ) : ?>
                  <span class="member-level-here">← 你在這裡</span>
                <?php elseif ( $is_reached ) : ?>
                  <i class="fa-solid fa-check member-level-check"></i>
                <?php endif; ?>
              </div>
              <div class="member-level-sub">
                <?php echo esc_html( $tier['title'] ); ?>
                ・<?php echo number_format( $tier['min_exp'] ); ?> EXP
              </div>
            </div>
            <span class="member-level-tag <?php echo esc_attr( $tier['tag'] ); ?>">
              <?php if ( $is_locked && ! $is_reached ) : ?>
                <i class="fa-solid fa-lock"></i>
              <?php else : ?>
                T<?php echo (int) $tier['tier']; ?>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>

        <a href="<?php echo esc_url( home_url('/level-guide/') ); ?>" class="member-level-more">
          查看完整等級指南 <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>

    </div>
  </div>
</section>

<script>
/* ── 免費註冊按鈕 → 觸發 header 的註冊彈窗 ── */
(function () {
    const btn = document.getElementById('smacg-cta-register-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (typeof window.smacgOpenLoginModal === 'function') {
            window.smacgOpenLoginModal('register');
        } else {
            // fallback：彈窗 JS 還沒載入時退回原生註冊頁
            window.location.href = '<?php echo esc_js( wp_registration_url() ); ?>';
        }
    });
})();
</script>

<?php get_footer(); ?>