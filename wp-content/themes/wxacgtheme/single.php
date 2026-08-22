<?php
/**
 * Single Post Template  v3.1  2026-08-12
 *
 * 變更（v3.1 — E-E-A-T / AdSense 品質優化）：
 * - [E-E-A-T] 作者姓名改為可點擊連結，導向作者頁（原本無法點擊）。
 * - [E-E-A-T] 新增「作者資訊卡」：頭像、簡介（若有填寫才顯示）、發文數、
 *             作者所有文章連結，並加上 Person schema（itemprop / JSON-LD）。
 * - [E-E-A-T] 標題區同時顯示「發布日期」與「最後更新日期」（若不同才顯示更新日期），
 *             讓 Google 與讀者都能判斷內容新鮮度。
 * - [SEO]     新增 Article JSON-LD（含 author、datePublished、dateModified），
 *             沿用既有「偵測 Rank Math 已輸出則略過」的防重複邏輯。
 *
 * 變更（v3.0）：
 * - [SEO/AEO] 發布/更新時間改用語意化 <time datetime>。
 * - [SEO]     麵包屑補 BreadcrumbList JSON-LD（偵測 Rank Math 已輸出則略過，避免重複）。
 * - [GEO/AEO] article 使用語意標籤、正確 h1/h2 層級，利於 AI 與精選摘要擷取。
 * - [UX]      順序調整為：內文 → 作者資訊卡 → 上下篇 → 留言 → 延伸閱讀。
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

/* ============================================================
   [v3.1] Thin Content 防護：短文判斷 + noindex
   必須在 get_header() 之前執行，才能趕上 wp_head()。
   当前訬者已設定必須是 is_singular('post')，所以直接擷內文。
   ============================================================ */
if ( have_posts() ) {
    global $post;
    $smacg_content_raw = apply_filters( 'the_content', $post->post_content ?? '' );
    $smacg_word_count  = mb_strlen( wp_strip_all_tags( $smacg_content_raw ), 'UTF-8' );
    if ( $smacg_word_count < 200 ) {
        add_filter( 'rank_math/frontend/robots', function ( $robots ) {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'follow';
            unset( $robots['noarchive'], $robots['nosnippet'] );
            return $robots;
        } );
    }
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

/* ── 作者資料（E-E-A-T：頭像 / 簡介 / 發文數 / 作者頁連結） ─── */
$post_obj        = get_post( $post_id );
$author_id       = (int) ( ! empty( $post_obj->post_author ) ? $post_obj->post_author : 1 );
$author_name     = get_the_author_meta( 'display_name', $author_id );
if ( empty( $author_name ) ) {
    $author_name = '斯麥又';
}
$author_bio      = trim( (string) get_the_author_meta( 'description', $author_id ) );
$author_archive  = get_author_posts_url( $author_id );
$author_avatar   = get_avatar_url( $author_id, [ 'size' => 96 ] );
$author_website  = get_the_author_meta( 'user_url', $author_id );
$author_post_cnt = (int) count_user_posts( $author_id, 'post', true );

/* ── 發布 / 最後更新時間（兩者不同才顯示「更新」） ───────── */
$published_gmt = get_the_date( 'c' );
$modified_gmt  = get_the_modified_date( 'c' );
$is_updated    = ( get_the_date( 'Y-m-d H:i' ) !== get_the_modified_date( 'Y-m-d H:i' ) );

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

/* ── Rank Math 是否已接管 schema 輸出（避免重複） ───────── */
$smacg_rankmath_active           = ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) );
$smacg_output_breadcrumb_schema  = ! $smacg_rankmath_active;
$smacg_output_article_schema     = ! $smacg_rankmath_active;
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

  <?php if ( $smacg_output_article_schema ) :
      $article_schema = [
          '@context'         => 'https://schema.org',
          '@type'            => 'Article',
          'headline'         => get_the_title(),
          'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink() ],
          'datePublished'    => $published_gmt,
          'dateModified'     => $modified_gmt,
          'author'           => [
              '@type' => 'Person',
              'name'  => $author_name,
              'url'   => $author_archive,
          ],
          'publisher'        => [
              '@type' => 'Organization',
              'name'  => get_bloginfo( 'name' ),
          ],
      ];
      if ( has_post_thumbnail() ) {
          $article_schema['image'] = [ get_the_post_thumbnail_url( $post_id, 'full' ) ];
      }
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $article_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
  <?php endif; ?>

  <?php if ( (int) $post_id === 846 ) :
      $faq_schema = [
          '@context'   => 'https://schema.org',
          '@type'      => 'FAQPage',
          'mainEntity' => [
              [
                  '@type'          => 'Question',
                  'name'           => 'Anime1 是合法正版網站嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '不是。Anime1.me 未持有台灣各動畫代理商的合法授權，在法律與產業定義上屬於非官方未授權串流網站。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '在 Anime1 線上看動畫安全嗎？會中毒嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '單純瀏覽網頁不一定直接中毒，但未授權網站常充斥惡意彈窗廣告、假下載按鈕、釣魚詐騙及不明 APK 下載，存在極高的個資洩漏與資安風險。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '單純線上看 Anime1 會犯法被警察抓嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '依經濟部智慧財產局解釋，單純透過網路瀏覽影音且無下載保存或公開傳播，一般觀眾通常不涉及刑事責任；但若下載檔案、使用 P2P 傳播或公開轉傳連結則可能觸法。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => 'Anime1 不能看或進不去怎麼辦？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '切勿在網路上隨意尋找未知鏡像站或安裝第三方外掛。建議直接前往巴哈姆特動畫瘋、Muse 木棉花 YouTube 或 Ani-One 尋找正版授權片單。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '台灣有哪些免費又合法的 Anime1 替代平台？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '首推「巴哈姆特動畫瘋」（免費含廣告）、「Muse 木棉花 YouTube 頻道」與「Ani-One 中文官方頻道」，皆為合法授權且免費觀看。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '使用廣告攔截器（AdBlock）看盜版就安全了嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '不一定。廣告攔截器僅能過濾部分視覺廣告，無法防範假網域釣魚、後端個資蒐集或惡意腳本，亦無法改變觀看未授權內容的本質。',
                  ],
              ],
          ],
      ];
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $faq_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
  <?php endif; ?>

  <?php if ( (int) $post_id === 648 ) :
      $faq_schema_648 = [
          '@context'   => 'https://schema.org',
          '@type'      => 'FAQPage',
          'mainEntity' => [
              [
                  '@type'          => 'Question',
                  'name'           => '漫改動畫一定比原創動畫好看嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '不一定。漫改動畫具備穩定的分鏡與既有粉絲基礎，但若製作預算與工期不足仍會崩壞；原創動畫雖無原作背書、風險較高，但一旦成功往往能成為名留青史的現象級神作。關鍵在於製作組的改編誠意與統籌實力。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '如何快速查詢一部動畫的原作是什麼？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '最便捷的方式是前往「巴哈姆特動畫瘋」的作品簡介頁，或查閱「維基百科」、「Bangumi 番組計畫」與「MyAnimeList」，作品資訊欄皆會明確標註為原創、漫畫改編、輕小說改編或遊戲改編。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '輕小說改編動畫為什麼常被說「資訊量爆炸」？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '輕小說依賴大量角色內心獨白、數值設定與世界觀描述。改編為 20 分鐘動畫時，若直譯旁白會使節奏沉悶，若大幅刪減又易造成動機交代不清，極度考驗編劇的資訊重組與視覺化演出功力。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '為什麼有些遊戲改編動畫能封神，有些卻變成黑歷史？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '成功的遊戲改編（如《賽博龐克：邊緣行者》）通常採用同世界觀新創劇本，或精準重構主線情感（如《命運石之門》、《CLANNAD》）；失敗案例往往因強行塞入多路線劇情、刪減關鍵支線或將主角扁平化而引發原作玩家不滿。',
                  ],
              ],
          ],
      ];
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $faq_schema_648, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
  <?php endif; ?>

  <?php if ( (int) $post_id === 31402 ) :
      $faq_schema_31402 = [
          '@context'   => 'https://schema.org',
          '@type'      => 'FAQPage',
          'mainEntity' => [
              [
                  '@type'          => 'Question',
                  'name'           => '木棉花與 Ani-One 在 YouTube 免費放動畫，他們靠什麼賺錢？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '代理商主要透過三個管道獲利：(1) YouTube 官方廣告分潤；(2) 帶動作品熱度後，銷售周邊商品、模型公仔、授權快閃店與電影版票房；(3) 將二輪播映權分銷給電視台與其他 OTT 平台。因此在 YouTube 觀看正版，能切實為代理商帶來合法收益！',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '為什麼有些熱門動畫在巴哈姆特動畫瘋找不到？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '因為該動畫被國際大型串流平台（如 Netflix 或 Disney+）以高價買斷了「全球或台灣獨家播放權」（如《迷宮飯》、《死神千年血戰》）。獨家合約期間內，其他任何平台皆無法上架。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '出國旅遊或留學時，可以用巴哈姆特動畫瘋看動畫嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '不行。動畫瘋的授權範圍嚴格限定於「台灣、澎湖、金門、馬祖地區」，系統會透過 IP 進行地理限制（Geo-blocking）。在海外可轉往當地合法平台（如 Crunchyroll、當地 Netflix）或使用木棉花/Ani-One 支援海外部分地區授權的 YouTube 片單。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '日本動畫本身有 4K 畫質嗎？為什麼大部分平台只提供 1080p？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '日本電視動畫在製作端（數位繪圖與合成）的原生解析度多為 720p 至 1080p，廣播規格亦為 1080i/p。因此 1080p 是最還原原作細節的標準解析度。Netflix 等平台的 4K 規格多採用高階硬體升頻或針對劇場版特別母帶進行壓制。',
                  ],
              ],
          ],
      ];
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $faq_schema_31402, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
  <?php endif; ?>

  <?php if ( (int) $post_id === 1210 ) :
      $faq_schema_1210 = [
          '@context'   => 'https://schema.org',
          '@type'      => 'FAQPage',
          'mainEntity' => [
              [
                  '@type'          => 'Question',
                  'name'           => '台灣用戶使用這些 AI 助理需要掛 VPN 翻牆嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '目前 ChatGPT、Claude、Gemini、DeepSeek、Grok 等主流平台在台灣均有開放直接連線註冊與使用，大部分無需使用 VPN。唯少數中國大陸服務（如豆包部分進階功能）可能需要中國大陸手機號碼驗證，國際版則可自由存取。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '免費版 AI 夠用嗎？什麼情況下值得每個月花 $20 美元訂閱付費版？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '如果只是日常查資料、寫短郵件、聊天閒聊，免費版（如 ChatGPT GPT-4o mini、Gemini 1.5 Flash、DeepSeek）已完全足夠。但如果你是靠寫作或寫程式賺錢的專業工作者，付費版的更長上下文、深度推理（o1/Sonnet 3.5）、無次數限制與專屬功能（Canvas/Artifacts）每天能為你省下數小時，投資報酬率極高。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '繁體中文（台灣用語習慣）目前哪一款 AI 表現最自然道地？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '經過多輪盲測，Claude 3.5 Sonnet 在台灣繁體中文的表現最受好評，語氣自然、用詞精準且符合台灣文化習慣；ChatGPT 表現居次；中國陣營模型（如 Kimi、豆包）雖然理解繁中無礙，但偶爾會夾雜對岸慣用語。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '寫程式、除錯（Coding）與重構目前業界最推薦哪一款？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '目前軟體工程界首推 Claude 3.5 Sonnet（配合 Cursor、Windsurf 等 AI 編輯器體驗封神），其次為 DeepSeek R1 / V3（開源高性價比推理）與 ChatGPT o1 系列（演算法難題深度思考）。',
                  ],
              ],
          ],
      ];
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $faq_schema_1210, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
  <?php endif; ?>

  <?php if ( (int) $post_id === 31439 ) :
      $faq_schema_31439 = [
          '@context'   => 'https://schema.org',
          '@type'      => 'FAQPage',
          'mainEntity' => [
              [
                  '@type'          => 'Question',
                  'name'           => '艾連為什麼非得發動「地鳴」踩踏 80% 人類？艾連真的有其他選擇嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '在作品世界觀中，帕拉迪島面臨全球軍事科技躍進與各國對艾爾迪亞人根深蒂固的仇恨。島內科技落後數十年，吉克的「安樂死計劃」等同於讓島民慢性滅絕，而常規和平談判在宣戰大會後徹底破滅。艾連發動地鳴是為了摧毀全球軍事工業、替帕拉迪島爭取至少數十年的生存和平時間，並藉由讓同伴親手阻止自己，將拯救世界的英雄地位留給阿爾敏與米卡莎，徹底消滅巨人之力。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '始祖尤彌爾為何等待了兩千年？為什麼解開巨人之力詛咒的關鍵是米卡莎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '始祖尤彌爾兩千年來在「道路」中受困於對弗里茲王扭曲的依戀與奴性。米卡莎雖然深愛艾連，但在世界存亡關頭，她依然選擇親手斬殺艾連以拯救全人類。米卡莎的舉動向尤彌爾證明了「深愛一個人，不代表必須順從他的毀滅行為，愛與自由可以並存」，尤彌爾從中獲得救贖與釋懷，巨人之力才隨之徹底消失。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '動畫片尾字幕最後「少年與巨人之樹」的畫面是什麼意思？帕拉迪島最後毀滅了嗎？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '片尾展示了艾連長眠的山丘在數百年乃至數千年後的演變，帕拉迪島歷經現代繁榮與未來的再次戰火，最後一名少年帶著獵犬走向了酷似巨人之力源頭的大樹。這並非代表巨人之力一定會復活，而是諫山創對人類歷史最深刻的冷靜觀察：只要人類存在，爭鬥就不會徹底消失；但只要有愛與對話的意志，歷史也永遠有重生的希望。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '動畫《The Final Season 完結篇》相較於漫畫原作結局，做了哪些重大優化？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '動畫版在諫山創親自參與下大幅優化了艾連與阿爾敏在道路中的最終對話。刪除了漫畫原作中易引發誤解的「謝謝你為了我們成為殺人魔」爭議台詞，改為更深刻真摯的對白，阿爾敏承認自己也享受過牆外的探索紅利，並承諾「我們一起在地獄相見」，使整段情感更具說服力與層次感。',
                  ],
              ],
              [
                  '@type'          => 'Question',
                  'name'           => '《進擊的巨人》全劇正確觀看順序為何？台灣有哪些合法線上看平台？',
                  'acceptedAnswer' => [
                      '@type' => 'Answer',
                      'text'  => '全劇完整順序為：第一季（25話）➔ 第二季（12話）➔ 第三季 Part 1 & 2（22話）➔ 最終季 Part 1 & 2（28話）➔ 完結篇前篇（電視特別篇1）➔ 完結篇後篇（電視特別篇2）。目前在台灣可透過巴哈姆特動畫瘋、Netflix 台灣、Muse 木棉花官方 YouTube 頻道、friDay 影音、Hami Video 等合法平台完整觀賞全劇高畫質繁中字幕。',
                  ],
              ],
          ],
      ];
  ?>
  <script type="application/ld+json"><?php echo wp_json_encode( $faq_schema_31439, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
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
        <span>
          <i class="fa-regular fa-user" aria-hidden="true"></i>
          <a href="<?php echo esc_url( $author_archive ); ?>" class="single-meta-author-link" rel="author">
            <?php echo esc_html( $author_name ); ?>
          </a>
        </span>
        <span>
          <i class="fa-regular fa-clock" aria-hidden="true"></i>
          <time datetime="<?php echo esc_attr( $published_gmt ); ?>"><?php echo esc_html( get_the_date( 'Y-m-d' ) ); ?></time>
        </span>
        <?php if ( $is_updated ) : ?>
        <span class="single-meta-updated">
          <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
          更新於
          <time datetime="<?php echo esc_attr( $modified_gmt ); ?>"><?php echo esc_html( get_the_modified_date( 'Y-m-d' ) ); ?></time>
        </span>
        <?php endif; ?>
        <span><i class="fa-regular fa-eye" aria-hidden="true"></i> 約 <?php echo (int) $read_minutes; ?> 分鐘閱讀</span>
        <?php if ( comments_open() || get_comments_number() ) : ?>
        <span><i class="fa-regular fa-comment" aria-hidden="true"></i> <?php comments_number( '0 留言', '1 留言', '% 留言' ); ?></span>
        <?php endif; ?>
      </div>
    </header>

    <!-- ── 主圖 ── -->
    <?php if ( has_post_thumbnail() ) : 
        $thumb_full_url = get_the_post_thumbnail_url( null, 'full' );
    ?>
    <figure class="single-cover">
      <a href="<?php echo esc_url( $thumb_full_url ); ?>" class="single-cover-zoom" title="<?php echo esc_attr( get_the_title() ); ?>">
        <?php the_post_thumbnail( 'full', [ 'alt' => get_the_title(), 'loading' => 'eager', 'fetchpriority' => 'high' ] ); ?>
        <span class="cover-zoom-badge">
          <i class="fa-solid fa-magnifying-glass-plus"></i> 點擊放大完整圖表
        </span>
      </a>
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

    <!-- ── 作者資訊卡（E-E-A-T） ──
         有無填寫「個人簡介」都能正常顯示：
         簡介只在非空時才輸出，避免出現空白區塊。 -->
    <aside class="single-author-box glass-light" itemscope itemtype="https://schema.org/Person" aria-label="作者資訊">
      <a href="<?php echo esc_url( $author_archive ); ?>" class="author-avatar-link" aria-hidden="true" tabindex="-1">
        <img
          src="<?php echo esc_url( $author_avatar ); ?>"
          alt="<?php echo esc_attr( $author_name ); ?>"
          class="author-avatar"
          itemprop="image"
          width="64" height="64"
          loading="lazy"
          decoding="async"
        />
      </a>

      <div class="author-info">
        <div class="author-name-row">
          <span class="author-eyebrow">本文作者</span>
          <a href="<?php echo esc_url( $author_archive ); ?>" class="author-name" itemprop="name" rel="author">
            <?php echo esc_html( $author_name ); ?>
          </a>
        </div>

        <?php if ( $author_bio !== '' ) : ?>
        <p class="author-bio" itemprop="description"><?php echo esc_html( $author_bio ); ?></p>
        <?php endif; ?>

        <div class="author-meta-row">
          <span class="author-post-count">
            <i class="fa-solid fa-pen-nib" aria-hidden="true"></i>
            已發表 <?php echo esc_html( number_format_i18n( $author_post_cnt ) ); ?> 篇文章
          </span>

          <a href="<?php echo esc_url( $author_archive ); ?>" class="author-link" itemprop="url">
            查看 <?php echo esc_html( $author_name ); ?> 的所有文章
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
          </a>

          <?php if ( $author_website ) : ?>
          <a href="<?php echo esc_url( $author_website ); ?>" class="author-link" target="_blank" rel="noopener noreferrer nofollow">
            個人網站
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </aside>

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

  <!-- ── 評論（自建系統，與動漫頁共用）── -->
  <?php if ( class_exists( 'Anime_Sync_Review_Manager' )
      && Anime_Sync_Review_Manager::is_reviewable( get_the_ID() ) ) : ?>
  <section class="single-comments" aria-label="留言區">
    <h2 class="asd-section-title">💬 留言</h2>
    <?php
    /*
     * 沿用動漫頁的容器與 review.js。新聞沒有集數概念，data-episodes 給空陣列，
     * 前端就不會顯示集數選單；data-anime-id 是既有屬性名，前端已在讀，
     * 這裡沿用以免同時改前後端，等系統統一完成後再一起正名。
     */
    ?>
    <div
      class="asd-review-root"
      id="asd-review-root"
      data-anime-id="<?php echo (int) get_the_ID(); ?>"
      data-episodes="[]"
      data-tracks="short"
      data-noun="留言"
    >
      <p class="asd-review-loading">評論載入中…</p>
    </div>
  </section>
  <?php endif; ?>

  <?php
  /*
   * wpDiscuz 留言區已於 2026-08-18 移除，改由上方自建評論系統統一承接。
   * 原有留言已遷移，wp_comments 資料保留未動，需要時重新啟用外掛並
   * 還原本區塊即可切回。
   */
  ?>

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

<!-- ── 作者資訊卡樣式（E-E-A-T） ── -->
<style>
.single-author-box{
  display:flex; gap:16px; align-items:flex-start;
  margin:28px 0; padding:20px 22px; border-radius:16px;
}
.single-author-box .author-avatar{
  width:64px; height:64px; border-radius:50%; object-fit:cover; flex:0 0 auto;
  border:2px solid var(--glass-border, rgba(255,255,255,.12));
}
.single-author-box .author-info{ min-width:0; flex:1 1 auto; }
.single-author-box .author-name-row{
  display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; margin-bottom:6px;
}
.single-author-box .author-eyebrow{
  font-size:12px; font-weight:700; letter-spacing:.5px;
  color: var(--text-muted, #9aa4b2); text-transform:uppercase;
}
.single-author-box .author-name{
  font-size:16px; font-weight:800; color: var(--text-primary, #fff);
  text-decoration:none;
}
.single-author-box .author-name:hover,
.single-author-box .author-name:focus{ text-decoration:underline; }
.single-author-box .author-bio{
  margin:0 0 10px; font-size:13.5px; line-height:1.75;
  color: var(--text-muted, #9aa4b2);
}
.single-author-box .author-meta-row{
  display:flex; flex-wrap:wrap; gap:10px 18px; align-items:center;
  font-size:12.5px; color: var(--text-muted, #9aa4b2);
}
.single-author-box .author-link{
  color: var(--accent-blue, #60a5fa); text-decoration:none; font-weight:700;
}
.single-author-box .author-link:hover,
.single-author-box .author-link:focus{ text-decoration:underline; }
.single-meta-author-link{ color:inherit; text-decoration:none; }
.single-meta-author-link:hover,
.single-meta-author-link:focus{ text-decoration:underline; }
.single-meta-updated{ opacity:.85; }
@media (max-width:600px){
  .single-author-box{ flex-direction:column; align-items:flex-start; }
}
</style>

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

<!-- ── 圖片燈箱放大 Lightbox ── -->
<div id="smacg-lightbox" class="smacg-lightbox" aria-hidden="true" role="dialog">
  <div class="smacg-lightbox-overlay"></div>
  <div class="smacg-lightbox-box">
    <button class="smacg-lightbox-close" aria-label="關閉放大視窗">&times;</button>
    <img id="smacg-lightbox-img" src="" alt="放大圖" />
    <div id="smacg-lightbox-caption" class="smacg-lightbox-caption"></div>
  </div>
</div>

<script>
(function() {
  var lb = document.getElementById('smacg-lightbox');
  var lbImg = document.getElementById('smacg-lightbox-img');
  var lbCap = document.getElementById('smacg-lightbox-caption');
  if (!lb || !lbImg) return;

  function openLb(src, title) {
    if (!src) return;
    lbImg.src = src;
    lbCap.textContent = title || '';
    lb.classList.add('is-active');
    lb.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeLb() {
    lb.classList.remove('is-active');
    lb.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // Cover zoom click
  var coverZoom = document.querySelector('.single-cover-zoom');
  if (coverZoom) {
    coverZoom.addEventListener('click', function(e) {
      e.preventDefault();
      openLb(this.href, this.getAttribute('title') || '完整評比圖表');
    });
  }

  // Content images click
  document.querySelectorAll('.single-content img, .plat-poster-card img').forEach(function(img) {
    img.style.cursor = 'zoom-in';
    img.addEventListener('click', function() {
      var fullSrc = this.getAttribute('data-full-url') || this.currentSrc || this.src;
      openLb(fullSrc, this.alt || '');
    });
  });

  lb.querySelector('.smacg-lightbox-close').addEventListener('click', closeLb);
  lb.querySelector('.smacg-lightbox-overlay').addEventListener('click', closeLb);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && lb.classList.contains('is-active')) {
      closeLb();
    }
  });
})();
</script>

<?php get_footer();