<?php
/**
 * 微笑動漫首頁
 * Blocksy Child Theme
 * 無 Hero 時鐘版本
 */

defined( 'ABSPATH' ) || exit;

get_header();

/* ============================================================
 * 共用：文章封面
 * ============================================================ */
if ( ! function_exists( 'wxacg_home_get_thumb' ) ) {
    function wxacg_home_get_thumb( $post_id ) {
        $post_id = (int) $post_id;

        $thumb = get_the_post_thumbnail_url( $post_id, 'medium_large' );
        if ( $thumb ) {
            return $thumb;
        }

        $meta = get_post_meta( $post_id, 'anime_cover_image', true );
        if ( $meta ) {
            return $meta;
        }

        $content = (string) get_post_field( 'post_content', $post_id );

        if (
            $content &&
            preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches )
        ) {
            return $matches[1];
        }

        return '';
    }
}

/* ============================================================
 * 共用：文章卡片
 * ============================================================ */
if ( ! function_exists( 'wxacg_home_article_card' ) ) {
    function wxacg_home_article_card( $post_object ) {
        if ( ! $post_object instanceof WP_Post ) {
            return;
        }

        $post_id = (int) $post_object->ID;
        $title   = get_the_title( $post_id );
        $url     = get_permalink( $post_id );
        $thumb   = wxacg_home_get_thumb( $post_id );
        $cats    = get_the_category( $post_id );

        $category = ! empty( $cats )
            ? $cats[0]->name
            : '文章';

        $author = get_the_author_meta(
            'display_name',
            (int) $post_object->post_author
        );

        $excerpt = get_the_excerpt( $post_id );

        if ( ! $excerpt ) {
            $excerpt = wp_strip_all_tags(
                strip_shortcodes( $post_object->post_content )
            );
        }

        $excerpt = wp_trim_words( $excerpt, 30, '…' );

        $published_time = get_post_time( 'U', false, $post_id );
        $relative_time  = human_time_diff(
            $published_time,
            current_time( 'timestamp' )
        );
        ?>
        <article class="news-card glass">
            <a
                href="<?php echo esc_url( $url ); ?>"
                class="news-card__thumb"
                aria-label="<?php echo esc_attr( $title ); ?>"
            >
                <?php if ( $thumb ) : ?>
                    <img
                        src="<?php echo esc_url( $thumb ); ?>"
                        alt="<?php echo esc_attr( $title ); ?>"
                        loading="lazy"
                        decoding="async"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                    >

                    <div
                        class="news-card__placeholder"
                        style="display:none"
                        aria-hidden="true"
                    >
                        <i class="fa-solid fa-newspaper"></i>
                    </div>
                <?php else : ?>
                    <div
                        class="news-card__placeholder"
                        aria-hidden="true"
                    >
                        <i class="fa-solid fa-newspaper"></i>
                    </div>
                <?php endif; ?>

                <span class="news-card__cat news-tag tag-rose">
                    <?php echo esc_html( $category ); ?>
                </span>
            </a>

            <div class="news-card__body">
                <h3 class="news-card__title">
                    <a href="<?php echo esc_url( $url ); ?>">
                        <?php echo esc_html( $title ); ?>
                    </a>
                </h3>

                <?php if ( $excerpt ) : ?>
                    <p class="news-card__excerpt">
                        <?php echo esc_html( $excerpt ); ?>
                    </p>
                <?php endif; ?>

                <div class="news-card__meta">
                    <?php if ( $author ) : ?>
                        <span class="news-card__author">
                            <i class="fa-regular fa-user"></i>
                            <?php echo esc_html( $author ); ?>
                        </span>
                    <?php endif; ?>

                    <span>
                        <i class="fa-regular fa-calendar"></i>
                        <time datetime="<?php echo esc_attr( get_the_date( DATE_W3C, $post_id ) ); ?>">
                            <?php echo esc_html( get_the_date( 'Y-m-d', $post_id ) ); ?>
                        </time>
                    </span>

                    <span>
                        <i class="fa-regular fa-clock"></i>
                        <?php echo esc_html( $relative_time ); ?>前
                    </span>
                </div>
            </div>
        </article>
        <?php
    }
}

/* ============================================================
 * 共用：動漫卡片
 * ============================================================ */
/**
 * 現在是哪一季，回傳該季的 anime_start_date 區間（YYYYMMDD 字串）。
 *
 * 日本動畫的季度固定切在 1／4／7／10 月：
 *   冬 1–3　春 4–6　夏 7–9　秋 10–12
 *
 * 所以 10 月 1 日零點一到，首頁就會自動換成秋番，不需要人工切換，
 * 也不需要每季回來改一次常數。
 *
 * 回傳字串而不是時間戳，是為了直接餵給 anime_start_date 的 meta 比較——
 * 該欄位存的是補零的 YYYYMMDD 字串，字串比較的大小順序與日期一致。
 */
if ( ! function_exists( 'wxacg_home_current_season_range' ) ) {
    function wxacg_home_current_season_range(): array {
        $year  = (int) wp_date( 'Y' );
        $month = (int) wp_date( 'n' );

        // 1→1、4→4、7→7、10→10：往下取整到季度的起始月
        $start_month = (int) ( floor( ( $month - 1 ) / 3 ) * 3 + 1 );
        $end_month   = $start_month + 2;

        return [
            'from'  => sprintf( '%04d%02d01', $year, $start_month ),
            'to'    => sprintf( '%04d%02d%02d', $year, $end_month, (int) wp_date( 't', mktime( 0, 0, 0, $end_month, 1, $year ) ) ),
            'year'  => $year,
            'month' => $start_month,
        ];
    }
}

/**
 * 季度代號與中文名。'fall' / '秋'
 *
 * 用起始月當 key，跟 wxacg_home_current_season_range() 回傳的 month 對得上。
 */
if ( ! function_exists( 'wxacg_home_season_meta' ) ) {
    function wxacg_home_season_meta( int $start_month ): array {
        $map = [
            1  => [ 'key' => 'winter', 'name' => '冬' ],
            4  => [ 'key' => 'spring', 'name' => '春' ],
            7  => [ 'key' => 'summer', 'name' => '夏' ],
            10 => [ 'key' => 'fall',   'name' => '秋' ],
        ];

        return $map[ $start_month ] ?? $map[1];
    }
}

/**
 * 本季的 Hero 主視覺網址；沒有對應檔案就回空字串。
 *
 * 檔名固定為 assets/images/hero-{season}.webp（winter／spring／summer／fall），
 * 四張放齊之後每季會自動換，不需要有人記得回來改。
 *
 * ★ 刻意用 file_exists() 而不是無條件輸出
 *   圖還沒放上去時直接輸出網址，畫面會變成破圖，比沒有背景更糟。
 *   檔案不在就回空字串，Hero 維持原本的純 CSS 漸層，等圖進來自動生效。
 */
if ( ! function_exists( 'wxacg_home_season_hero_bg' ) ) {
    function wxacg_home_season_hero_bg( string $season_key ): string {
        $rel  = 'assets/images/hero-' . $season_key . '.webp';
        $path = get_stylesheet_directory() . '/' . $rel;

        if ( ! file_exists( $path ) ) {
            return '';
        }

        // 帶 filemtime 破快取，換圖之後不必等 CDN 過期
        return get_stylesheet_directory_uri() . '/' . $rel . '?v=' . filemtime( $path );
    }
}

/**
 * 換季倒數：距離下一季開播還有幾天，以及最早開播的幾部作品。
 *
 * 回傳 null 代表「現在不是倒數期間」，呼叫端就照常顯示原本的三張海報。
 *
 * ★ 為什麼只在開季前三週出現
 *   倒數的張力來自「快到了」。距離兩個月還在倒數只是佔版面，
 *   而開季後倒數就失去意義——那時該講的是「正在播」。
 *
 * ★ 為什麼查詢要快取
 *   這是首頁每次載入都會跑的額外查詢。內容一天只會變一次（天數），
 *   所以存 6 小時 transient；跨季那天最多晚 6 小時切換，可接受。
 */
/**
 * 倒數卡用的作品清單：依指定排序，取有封面的前 N 部。
 *
 * $order：'start'   ＝最早開播（倒數要回答「最先能看到什麼」）
 *         'popular' ＝話題強檔（依 anime_popularity，即 AniList 的關注人數）
 *
 * ★ 強檔為什麼用人氣而不是評分
 *   倒數期間的作品都還沒播，自身評分全是 0（正式站實測 48/48 部無分數），
 *   能判斷「話題性」的只剩關注人數。前作評分只有續作才有，
 *   拿它排會讓完全新作永遠排不上來。
 */
if ( ! function_exists( 'wxacg_home_countdown_pick' ) ) {
    function wxacg_home_countdown_pick( string $from, string $to, string $order, int $limit ): array {
        $args = [
            'post_type'              => 'anime',
            'post_status'            => 'publish',
            // 取多一點當緩衝：沒有封面的會被略過，取剛好 N 部可能補不滿
            'posts_per_page'         => $limit * 4,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                'relation' => 'AND',
                [
                    'key'     => 'anime_start_date',
                    'value'   => [ $from, $to ],
                    'compare' => 'BETWEEN',
                    'type'    => 'CHAR',
                ],
                [
                    'key'     => 'anime_format',
                    'value'   => [ 'TV', 'TV_SHORT', 'ONA' ],
                    'compare' => 'IN',
                ],
            ],
        ];

        if ( 'popular' === $order ) {
            $args['meta_key'] = 'anime_popularity';
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'DESC';
        } else {
            $args['meta_key'] = 'anime_start_date';
            $args['orderby']  = 'meta_value';
            $args['order']    = 'ASC';
        }

        $works = [];
        foreach ( ( new WP_Query( $args ) )->posts as $post_object ) {
            $post_id = (int) $post_object->ID;
            $cover   = get_post_meta( $post_id, 'anime_cover_image', true )
                ?: get_the_post_thumbnail_url( $post_id, 'medium' );

            // 沒有封面就跳過：Hero 是門面，開天窗比少一部嚴重
            if ( ! $cover ) {
                continue;
            }

            $start_raw = (string) get_post_meta( $post_id, 'anime_start_date', true );

            $works[] = [
                'title' => get_post_meta( $post_id, 'anime_title_chinese', true ) ?: get_the_title( $post_id ),
                'cover' => $cover,
                'url'   => get_permalink( $post_id ),
                'date'  => strlen( $start_raw ) === 8
                    ? ( (int) substr( $start_raw, 4, 2 ) ) . '/' . ( (int) substr( $start_raw, 6, 2 ) )
                    : '',
            ];

            if ( count( $works ) >= $limit ) {
                break;
            }
        }

        return $works;
    }
}

if ( ! function_exists( 'wxacg_home_season_countdown' ) ) {
    function wxacg_home_season_countdown(): ?array {
        $days_ahead = 21;
        // 卡片高 480px，扣掉標頭與按鈕大約放得下 5 列
        $limit      = 5;

        $year  = (int) wp_date( 'Y' );
        $month = (int) wp_date( 'n' );

        // 下一個季度切點：1／4／7／10 月的 1 號
        $next_month = (int) ( floor( ( $month - 1 ) / 3 ) * 3 + 4 );
        $next_year  = $year;
        if ( $next_month > 12 ) {
            $next_month = 1;
            $next_year++;
        }

        $today = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00' );
        $start = strtotime( sprintf( '%04d-%02d-01 00:00:00', $next_year, $next_month ) );
        $days  = (int) floor( ( $start - $today ) / DAY_IN_SECONDS );

        if ( $days < 1 || $days > $days_ahead ) {
            return null;
        }

        /*
         * 快取鍵要帶顯示部數與結構版本。
         *
         * 部數：少了它，把 3 部改成 5 部之後仍會讀到舊的 3 部快取，
         *       得等 6 小時過期才生效——本機就是這樣卡住的。
         * v2  ：快取內容從「一份清單」改成「最早／強檔兩份」，
         *       讀到舊格式會缺鍵，換鍵名讓舊快取直接失效。
         */
        $cache_key = sprintf( 'wxacg_home_countdown_v2_%04d%02d_%d', $next_year, $next_month, $limit );
        $lists     = get_transient( $cache_key );

        if ( ! is_array( $lists ) || ! isset( $lists['early'], $lists['hot'] ) ) {
            $from = sprintf( '%04d%02d01', $next_year, $next_month );
            $to   = sprintf( '%04d%02d31', $next_year, $next_month + 2 > 12 ? 12 : $next_month + 2 );

            $lists = [
                'early' => wxacg_home_countdown_pick( $from, $to, 'start', $limit ),
                'hot'   => wxacg_home_countdown_pick( $from, $to, 'popular', $limit ),
            ];

            set_transient( $cache_key, $lists, 6 * HOUR_IN_SECONDS );
        }

        if ( ! $lists['early'] && ! $lists['hot'] ) {
            return null;
        }

        $meta = wxacg_home_season_meta( $next_month );

        return [
            'days'  => $days,
            'label' => sprintf( '%d 年 %d 月新番', $next_year, $next_month ),
            'short' => sprintf( '%d %s番', $next_year, $meta['name'] ),
            'works' => $lists['early'],
            'hot'   => $lists['hot'],
            'url'   => home_url( sprintf( '/bangumi/%04d%02d/', $next_year, $next_month ) ),
        ];
    }
}

if ( ! function_exists( 'wxacg_home_anime_card' ) ) {
    function wxacg_home_anime_card( $post_object ) {
        if ( ! $post_object instanceof WP_Post ) {
            return;
        }

        $post_id = (int) $post_object->ID;

        $title = get_post_meta(
            $post_id,
            'anime_title_chinese',
            true
        );

        if ( ! $title ) {
            $title = $post_object->post_title;
        }

        $cover = get_post_meta(
            $post_id,
            'anime_cover_image',
            true
        );

        if ( ! $cover ) {
            $cover = get_the_post_thumbnail_url( $post_id, 'medium' );
        }

        $score = get_post_meta(
            $post_id,
            'smacg_site_score',
            true
        );

        if ( $score === '' || $score === null ) {
            $score = get_post_meta(
                $post_id,
                'anime_score_site',
                true
            );
        }

        $score_display = is_numeric( $score ) && (float) $score > 0
            ? number_format( (float) $score, 1 )
            : '';

        $fallback = function_exists( 'mb_substr' )
            ? mb_substr( $title, 0, 2 )
            : substr( $title, 0, 2 );
        ?>
        <a
            href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"
            class="wxacg-anime-card"
            title="<?php echo esc_attr( $title ); ?>"
        >
            <div class="wxacg-card-thumb">
                <?php if ( $cover ) : ?>
                    <img
                        src="<?php echo esc_url( $cover ); ?>"
                        alt="<?php echo esc_attr( $title ); ?>"
                        loading="lazy"
                        decoding="async"
                        onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';"
                    >

                    <div
                        class="wxacg-card-fb"
                        style="display:none"
                        aria-hidden="true"
                    >
                        <span><?php echo esc_html( $fallback ); ?></span>
                    </div>
                <?php else : ?>
                    <div class="wxacg-card-fb" aria-hidden="true">
                        <span><?php echo esc_html( $fallback ); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ( $score_display ) : ?>
                    <span
                        class="wxacg-card-score"
                        aria-label="本站評分 <?php echo esc_attr( $score_display ); ?>"
                    >
                        <i class="fa-solid fa-star"></i>
                        <?php echo esc_html( $score_display ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="wxacg-card-body">
                <h3 class="wxacg-card-title">
                    <?php echo esc_html( $title ); ?>
                </h3>
            </div>
        </a>
        <?php
    }
}

/* ============================================================
 * Hero 資料
 * ============================================================ */
$hero_posters = [
    [
        /*
         * 2026-09-19 立即註冊海報（媒體庫 ID 61260、567x1024 ≈ 5:9）。
         *
         * action=register：點擊開註冊彈窗，不是跳頁。
         * 彈窗來自 header.js 的 window.smacgOpenLoginModal('register')（header.js:145），
         * 而 Modal 本身只對未登入者輸出（header.php:55）——所以這一格在下面
         * 會被整個濾掉，登入者根本看不到，不需要在這裡處理備援。
         * url 仍保留一個真實網址，作為 JS 失效時的退路（見輸出區的 fallback）。
         */
        'img'    => 'https://weixiaoacg.com/wp-content/uploads/2026/09/hero-poster-signup.webp',
        'title'  => '立即註冊',
        // 退路用 wp_registration_url()，與本檔 2531 行既有的註冊 CTA 一致。
        // 不能用 /join/——那是「加入微笑動漫」招募頁（第三格的目標），不是註冊頁，
        // 拿它當註冊退路會在 JS 失效時把人安靜地送到錯的地方。
        'url'    => wp_registration_url(),
        'external' => false,
        'action' => 'register',
    ],
    [
        // 2026-09-19 換圖：加入社群海報（媒體庫 ID 61261、568x1024 ≈ 5:9）。
        // title 與 url 維持原樣——這張的訴求仍是加入 Discord，兩者本來就正確。
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/09/hero-poster-discord.webp',
        'title' => '加入 Discord',
        'url'   => 'https://discord.com/invite/yw73RBZgss',
        'external' => true,
    ],
    [
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/07/zyYiYfQY.webp',
        'title' => '加入微笑動漫',
        'url'   => home_url( '/join/' ),
        'external' => false,
    ],
];

/*
 * 「會員版把註冊那格換成追番清單」的邏輯不放這裡，改放在下方
 * $hero_poster_slots 切片處（搜尋 hero_poster_slots）。
 *
 * 原因：那個替換的條件之一是「倒數已結束」，而 $hero_countdown 要到
 * wxacg_home_season_countdown() 那行才定義，比這裡晚。寫在這會讀到 null，
 * 條件永遠不成立卻不會報錯——是那種安靜失效、很久才被發現的寫法。
 */

$hero_quotes = [
    [
        'quote'  => '拼命累積起來的東西，絕對不會背叛自己。',
        'source' => '《葬送的芙莉蓮》— 欣梅爾',
    ],
    [
        'quote'  => '你幹嘛要配合他們？你才是自己人生的主角啊。',
        'source' => '《路人超能100》— 靈幻新隆',
    ],
    [
        'quote'  => '我們總是在意自己錯過太多，卻不曾注意自己擁有多少。',
        'source' => '《我們仍未知道那天所看見的花名。》',
    ],
    [
        'quote'  => '我還在尋找我們的目的地，以及我們奔跑的意義。',
        'source' => '《強風吹拂》— 清瀨灰二',
    ],
    [
        'quote'  => '平凡的我啊，你還有閒工夫垂頭喪氣嗎？',
        'source' => '《排球少年》— 田中龍之介',
    ],
    [
        'quote'  => '我其實不討厭努力，學會原本不會的事，其實也不錯不是嗎？',
        'source' => '《Re:從零開始的異世界生活》— 菜月昴',
    ],
    [
        'quote'  => '沒有人知道結果會怎樣，只能選擇自己不會後悔的路。',
        'source' => '《進擊的巨人》— 里維·阿卡曼',
    ],
];

$hero_quote = $hero_quotes[
    array_rand( $hero_quotes )
];

$random_anime_url = add_query_arg(
    'action',
    'wxacg_random_anime',
    admin_url( 'admin-ajax.php' )
);
?>

<!-- ============================================================
     Hero：已完全移除時鐘
     ============================================================ -->
<?php
/*
 * Hero 的季節主視覺與換季倒數。
 *
 * 兩者都是「有就顯示、沒有就維持原樣」：
 *   ・季節圖沒放進 assets/images/ 時，hero-bg-layer 保持原本的純 CSS 漸層
 *   ・不在開季前三週時，右側維持原本的三張入口海報
 * 所以這段程式先上線也不會改變現在的畫面，圖與檔期到了才自動生效。
 */
$hero_season      = wxacg_home_season_meta( wxacg_home_current_season_range()['month'] );
$hero_season_bg   = wxacg_home_season_hero_bg( $hero_season['key'] );
$hero_countdown   = wxacg_home_season_countdown();
?>
<section class="hero-section<?php echo $hero_season_bg ? ' has-season-bg' : ''; ?>" id="hero" data-season="<?php echo esc_attr( $hero_season['key'] ); ?>">
    <?php if ( $hero_season_bg ) : ?>
        <div
            class="hero-season-bg"
            style="background-image:url('<?php echo esc_url( $hero_season_bg ); ?>');"
            aria-hidden="true"
        ></div>
    <?php endif; ?>

    <div
        class="hero-bg-layer"
        id="hero-bg"
        aria-hidden="true"
    ></div>

    <div class="hero-noise" aria-hidden="true"></div>

    <div class="container hero-content-wrap">

        <div class="hero-text">
            <div class="hero-eyebrow">
                <a
                    href="<?php echo esc_url( home_url( '/bangumi/' ) ); ?>"
                    class="chip active chip-link"
                >
                    <i class="fa-solid fa-calendar-days"></i>
                    動漫新番表
                </a>

                <a
                    href="<?php echo esc_url( home_url( '/join/' ) ); ?>"
                    class="chip chip-link"
                >
                    <i class="fa-solid fa-circle-info"></i>
                    微笑動漫組收人
                </a>
            </div>

            <h1 class="hero-title">
                繁體中文<br>動漫資料庫<br>
                <span class="line-gradient">追番清單<br>評分與成就</span><br>
                <span class="line-accent">掌握新番播出資訊</span>
            </h1>

            <p class="hero-subtitle hero-site-description">
                整理本季新番、動畫作品、播出進度、角色聲優、動漫新聞與深度專題，追蹤紀錄通通幫你保存。
            </p>

            <blockquote class="hero-quote">
                <p>
                    「<?php echo esc_html( $hero_quote['quote'] ); ?>」
                </p>
                <cite>
                    <?php echo esc_html( $hero_quote['source'] ); ?>
                </cite>
            </blockquote>

            <div class="hero-actions">
                <a
                    href="#season-section"
                    class="btn btn-primary"
                >
                    <i class="fa-solid fa-calendar-check"></i>
                    本季新番
                </a>

                <a
                    href="#news-section"
                    class="btn btn-secondary"
                >
                    <i class="fa-solid fa-newspaper"></i>
                    最新新聞
                </a>
            </div>
        </div>
        <div class="hero-side">

            <?php
            /*
             * 上排三格（直立）＋下方一條橫幅。
             *
             * ★ 倒數不再取代海報，而是「佔掉第三格」
             *   三張入口海報是全站常態導流（動漫資料庫／Discord／加入微笑動漫），
             *   為了三週的檔期資訊整段拿掉並不划算。改成倒數期間只顯示前兩張，
             *   第三格讓給直式倒數卡；開季後倒數消失、第三張海報自動回來，
             *   兩種狀態下都維持三格，版面高度不會跳動。
             */
            /*
             * 會員版：倒數結束後，把「立即註冊」那格換成「追番清單」
             * （媒體庫 ID 61453、567x1024 ≈ 5:9）。
             *
             * ★ 兩個條件缺一不可，而且是「替換」不是「移除」：
             *   ・倒數期間不換——第三格被倒數卡佔著，三格已滿，
             *     這時換掉註冊格對訪客與會員都沒有好處。
             *   ・少一格不會自動補滿：.hero-side 桌機固定寬
             *     calc(250px*3 + 12px*2)（style.css:2577），而 .hero-posters 是
             *     justify-content:flex-end、海報固定 250px 不伸縮，
             *     移除會變成靠右對齊、左側空出約 262px。
             *
             * 替換後的項目刻意不帶 action：它是一般連結，不觸發註冊彈窗。
             *
             * 依登入狀態輸出不同內容在這個站是安全的：setup-theme.php 的
             * template_redirect 對登入者一律送 nocache_headers()，
             * 不會發生「會員吃到快取住的訪客版」。
             */
            if ( ! $hero_countdown && is_user_logged_in() ) {
                foreach ( $hero_posters as $i => $p ) {
                    if ( empty( $p['action'] ) || 'register' !== $p['action'] ) {
                        continue;
                    }
                    $hero_posters[ $i ] = [
                        'img'      => 'https://weixiaoacg.com/wp-content/uploads/2026/09/hero-poster-watchlist.webp',
                        'title'    => '追番清單',
                        // 與本檔會員中心連結同一寫法：優先用 helper，沒有才退回 /mc/。
                        'url'      => function_exists( 'wxacg_get_member_center_url' )
                            ? wxacg_get_member_center_url()
                            : home_url( '/mc/' ),
                        'external' => false,
                    ];
                    break;
                }
            }

            $hero_poster_slots = $hero_countdown
                ? array_slice( $hero_posters, 0, 2 )
                : $hero_posters;
            ?>

            <div class="hero-posters hero-side__row<?php echo $hero_countdown ? ' has-cd' : ''; ?>" id="hero-posters">

                <?php foreach ( $hero_poster_slots as $index => $poster ) : ?>
                    <?php
                    /*
                     * action=register 的那一格不是連結而是彈窗觸發器：
                     * href 給 # 並掛 js-open-register，實際網址改放 data-fallback，
                     * 讓 JS 失效時仍有退路，且網址只在 $hero_posters 定義一次。
                     */
                    $poster_is_register = ! empty( $poster['action'] ) && 'register' === $poster['action'];
                    ?>
                    <a
                        href="<?php echo $poster_is_register ? '#' : esc_url( $poster['url'] ); ?>"
                        class="poster-item glass<?php echo $poster_is_register ? ' js-open-register' : ''; ?>"
                        title="<?php echo esc_attr( $poster['title'] ); ?>"
                        <?php if ( $poster_is_register ) : ?>
                            data-fallback="<?php echo esc_url( $poster['url'] ); ?>"
                        <?php elseif ( $poster['external'] ) : ?>
                            target="_blank"
                            rel="noopener noreferrer"
                        <?php endif; ?>
                    >
                        <img
                            src="<?php echo esc_url( $poster['img'] ); ?>"
                            alt="<?php echo esc_attr( $poster['title'] ); ?>"
                            width="250"
                            height="480"
                            decoding="async"
                            <?php if ( $index === 0 ) : ?>
                                loading="eager"
                                fetchpriority="high"
                                data-no-lazy="1"
                            <?php else : ?>
                                loading="lazy"
                            <?php endif; ?>
                            onerror="this.style.display='none';this.closest('.poster-item').classList.add('skeleton');"
                        >

                        <span class="poster-item__title">
                            <?php echo esc_html( $poster['title'] ); ?>
                        </span>
                    </a>
                <?php endforeach; ?>

                <?php
                /*
                 * 只有「實際被輸出的那幾格」裡有註冊海報時才送這段 JS。
                 * 判斷要看 $hero_poster_slots 而不是 $hero_posters——倒數期間只輸出前兩張，
                 * 看錯陣列會在沒有這一格的情況下送出一段永遠找不到目標的程式。
                 */
                $has_register_slot = false;
                foreach ( $hero_poster_slots as $slot ) {
                    if ( ! empty( $slot['action'] ) && 'register' === $slot['action'] ) {
                        $has_register_slot = true;
                        break;
                    }
                }
                ?>
                <?php if ( $has_register_slot ) : ?>
                <script>
                /*
                 * 註冊海報：開 header 的登入/註冊 Modal，不跳頁。
                 * smacgOpenLoginModal 定義在 header.js:145；Modal 只對訪客輸出
                 * （header.php:55），而這一格也只有訪客看得到，兩邊條件一致。
                 * 函式不存在時（JS 失效／載入順序意外）退回 data-fallback 的網址，
                 * 不讓點擊變成「什麼都沒發生」。
                 */
                (function () {
                    var el = document.querySelector('#hero-posters .js-open-register');
                    if (!el) { return; }
                    el.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (typeof window.smacgOpenLoginModal === 'function') {
                            window.smacgOpenLoginModal('register');
                            return;
                        }
                        var fb = el.getAttribute('data-fallback');
                        if (fb) { window.location.href = fb; }
                    });
                })();
                </script>
                <?php endif; ?>

                <?php if ( $hero_countdown ) : ?>
                    <div class="hero-cd">
                        <?php
                        /*
                         * 整張卡原本是一個 <a> 包住全部，點任何地方都跳到新番表。
                         * 但讀者看到作品縮圖時想去的是「那一部」，不是總表——
                         * 所以外層改成 div，作品各自成為連結，底部按鈕才連總表。
                         * （<a> 不能巢狀，這是必須換成 div 的原因。）
                         */
                        ?>
                        <a class="hero-cd__head" href="<?php echo esc_url( $hero_countdown['url'] ); ?>">
                            <span class="hero-cd__season"><?php echo esc_html( $hero_countdown['short'] ); ?></span>

                            <span class="hero-cd__count">
                                <strong><?php echo (int) $hero_countdown['days']; ?></strong>
                                <em>天後開播</em>
                            </span>
                        </a>

                        <?php
                        /*
                         * 兩個頁籤：最早開播／話題強檔。
                         *
                         * 兩份清單都直接輸出在 HTML 裡，切換只是 hidden 屬性的開關，
                         * 不打 AJAX——資料只有 10 筆，多一次請求反而更慢，
                         * 而且沒有 JS 的環境（爬蟲、閱讀模式）兩份內容都讀得到。
                         *
                         * 用 role="tablist"／tab／tabpanel 與 aria-selected，
                         * 讓螢幕閱讀器知道這是一組頁籤而不是兩顆普通按鈕。
                         */
                        $cd_tabs = [
                            'early' => [ '最早開播', $hero_countdown['works'] ],
                            'hot'   => [ '話題強檔', $hero_countdown['hot'] ],
                        ];
                        ?>
                        <div class="hero-cd__tabs" role="tablist" aria-label="倒數清單切換">
                            <?php $cd_i = 0; foreach ( $cd_tabs as $cd_key => $cd_tab ) : ?>
                                <button
                                    type="button"
                                    class="hero-cd__tab<?php echo 0 === $cd_i ? ' is-active' : ''; ?>"
                                    role="tab"
                                    id="hero-cd-tab-<?php echo esc_attr( $cd_key ); ?>"
                                    aria-controls="hero-cd-panel-<?php echo esc_attr( $cd_key ); ?>"
                                    aria-selected="<?php echo 0 === $cd_i ? 'true' : 'false'; ?>"
                                    tabindex="<?php echo 0 === $cd_i ? '0' : '-1'; ?>"
                                ><?php echo esc_html( $cd_tab[0] ); ?></button>
                            <?php $cd_i++; endforeach; ?>
                        </div>

                        <?php $cd_i = 0; foreach ( $cd_tabs as $cd_key => $cd_tab ) : ?>
                        <ul
                            class="hero-cd__list"
                            role="tabpanel"
                            id="hero-cd-panel-<?php echo esc_attr( $cd_key ); ?>"
                            aria-labelledby="hero-cd-tab-<?php echo esc_attr( $cd_key ); ?>"
                            <?php echo 0 === $cd_i ? '' : 'hidden'; ?>
                        >
                            <?php foreach ( $cd_tab[1] as $work ) : ?>
                                <li>
                                    <a href="<?php echo esc_url( $work['url'] ); ?>" title="<?php echo esc_attr( $work['title'] ); ?>">
                                        <img
                                            src="<?php echo esc_url( $work['cover'] ); ?>"
                                            alt="<?php echo esc_attr( $work['title'] ); ?> 封面圖"
                                            loading="lazy"
                                            width="30" height="42"
                                        >
                                        <span>
                                            <b><?php echo esc_html( $work['title'] ); ?></b>
                                            <?php if ( $work['date'] ) : ?>
                                                <i><?php echo esc_html( $work['date'] ); ?> 首播</i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php $cd_i++; endforeach; ?>

                        <script>
                        /*
                         * 頁籤切換：純 hidden 開關，無相依套件。
                         * 支援方向鍵左右切換（WAI-ARIA tabs 的標準操作）。
                         */
                        (function () {
                            var script = document.currentScript;
                            var root = script && script.closest('.hero-cd');
                            if (!root) { return; }
                            var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));

                            function activate(tab) {
                                tabs.forEach(function (t) {
                                    var on = (t === tab);
                                    t.classList.toggle('is-active', on);
                                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                                    t.setAttribute('tabindex', on ? '0' : '-1');
                                    var panel = document.getElementById(t.getAttribute('aria-controls'));
                                    if (panel) { panel.hidden = !on; }
                                });
                            }

                            tabs.forEach(function (tab, i) {
                                tab.addEventListener('click', function () { activate(tab); });
                                tab.addEventListener('keydown', function (e) {
                                    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
                                    e.preventDefault();
                                    var step = (e.key === 'ArrowRight') ? 1 : tabs.length - 1;
                                    var next = tabs[(i + step) % tabs.length];
                                    activate(next);
                                    next.focus();
                                });
                            });
                        })();
                        </script>

                        <a class="hero-cd__go" href="<?php echo esc_url( $hero_countdown['url'] ); ?>">看完整新番表 →</a>
                    </div>
                <?php endif; ?>

            </div>

            <?php
            /*
             * 橫幅：圖片由使用者自行製作，放在 assets/images/hero-banner.webp。
             * 與季節主視覺同樣用 file_exists() 判斷——檔案不在就整塊不輸出，
             * 不會出現破圖，圖放進來才自動生效。
             */
            $hero_banner_rel  = 'assets/images/hero-banner.webp';
            $hero_banner_path = get_stylesheet_directory() . '/' . $hero_banner_rel;
            ?>
            <?php
            /*
             * 2026-09-19 橫幅改為 LOFI 電台入口（原本指向新番表）。
             *
             * ⚠ 這個網址在站上有兩份：這裡，以及「會員」選單的 🎧LOFI 項目
             *   （nav_menu_item ID 60674，其 _menu_item_target 已設為 _blank）。
             *   站上沒有 /lofi/ 頁面，LOFI 就是這支外部 YouTube，所以只能寫死；
             *   日後要換頻道，兩邊都要改，改一邊會不一致。
             */
            // 2026-09-19 改指向頻道的 /live 分頁（原本是固定單支影片 watch?v=3Yk8RO_FuM0）。
            // 電台是持續開播，指到 /live 才會永遠落在「當前這一場」，不會播完就變死連結。
            $hero_banner_url = 'https://www.youtube.com/@%E5%BE%AE%E7%AC%91%E5%8B%95%E6%BC%AB/live';
            ?>
            <?php if ( file_exists( $hero_banner_path ) ) : ?>
                <a
                    class="hero-banner"
                    href="<?php echo esc_url( $hero_banner_url ); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="LOFI 電台開播，在新分頁開啟"
                >
                    <img
                        src="<?php echo esc_url( get_stylesheet_directory_uri() . '/' . $hero_banner_rel . '?v=' . filemtime( $hero_banner_path ) ); ?>"
                        alt="LOFI 電台開播"
                        loading="lazy"
                        decoding="async"
                    >
                </a>
            <?php endif; ?>

        </div><!-- .hero-side -->

    </div>
</section>

<?php
/* ============================================================
 * 本季 TV 新番資料
 * ============================================================ */
$current_timestamp = current_time( 'timestamp' );
$current_month     = (int) wp_date( 'n', $current_timestamp );
$current_year      = (int) wp_date( 'Y', $current_timestamp );
$current_weekday   = (int) wp_date( 'N', $current_timestamp );

/*
 * 本季季節的推算已移除：這一區改用 anime_status = RELEASING 判斷，
 * 不再需要季節名稱。下方「動漫作品推薦」另有一份 $next_season 推算，
 * 那個仍在使用，兩者互不相干。
 */

$weekday_labels = [
    0 => '全部',
    1 => '週一',
    2 => '週二',
    3 => '週三',
    4 => '週四',
    5 => '週五',
    6 => '週六',
    7 => '週日',
];

/*
 * 「本季 TV 新番」= 目前正在播出的 TV 作品。
 *
 * ★ 為什麼改用 anime_status 而不是季度
 *   原本的條件是 anime_season IN（本季, 上一季）。但 anime_season 存的是
 *   SUMMER／SPRING 這種「不含年份」的字串——2015 年的夏番跟 2026 年的夏番
 *   在這個條件下完全一樣。實測撈到 408 部，其中只有 66 部是今年的。
 *
 *   年份判斷原本寫在下面的 PHP 迴圈裡，但 posts_per_page 的 300 筆上限是在
 *   SQL 階段就生效的：408 部先被截成 300 部，PHP 才開始比對年份。被截掉的
 *   108 部裡就有本季作品，它們根本沒機會進到判斷。截哪些取決於 post_date
 *   排序，與季度無關，所以缺漏是隨機的——首頁因此只顯示 16 部、還整天沒有
 *   週三。
 *
 *   直接用播出狀態就沒有這個問題：實測 81 部，遠低於上限，不會被截斷，
 *   也不需要「本季／上一季」那套推算。語意也更直接——首頁要的就是現在
 *   正在播的。
 */
/*
 * ★ 2026-09-13：改回用「季度」定義本季，不再用 anime_status = RELEASING。
 *
 * 為什麼要改回來
 *
 *   RELEASING 的語意是「此刻正在播」，不等於「本季」。換季當下兩者差很多：
 *   正式站在 2026-09-13 實測，這個查詢撈到 76 部，其中 58 部是七月番、
 *   13 部是四月番；而十月番共 52 部，到 10 月 1 日當天只有 6 部會翻成
 *   RELEASING，其餘 46 部要各自等到自己的開播日。
 *
 *   也就是說開季那一週——流量最重要的一週——首頁的「本季新番」會是
 *   一整排即將完結的夏番，最該被看到的秋番反而幾乎不在。
 *
 * 那原本的截斷 bug 怎麼辦
 *
 *   當初改用 RELEASING 是為了避開「posts_per_page 300 在 SQL 階段就截斷、
 *   PHP 才比對年份」導致本季作品隨機漏掉的問題。這一版不是把年份判斷搬
 *   回 PHP，而是直接在 SQL 用 anime_start_date 的區間過濾——母體一開始
 *   就只有本季，300 的上限根本碰不到（本季 52 部）。
 *
 *   anime_start_date 存的是 YYYYMMDD 字串且補零，字串比較的大小順序與
 *   日期一致，所以 BETWEEN 可以直接用。
 *
 * 未開播的作品要不要顯示
 *
 *   要。開季前後讀者最想知道的就是「還有什麼要播、什麼時候播」。
 *   星期分組本來就會先讀 next_airing、沒有才退回 start_date，
 *   未開播作品有 start_date，一樣排得進星期，不需要額外處理。
 *
 * ★ 續播的長番必須留著（2026-09-13 補）
 *
 *   只用季度區間會把《ONE PIECE》（1999 開播）、《吉伊卡哇》（2022）、
 *   《數碼寶貝 BEATBREAK》（2025-10）這類仍在播的長番整批踢掉——
 *   它們在秋季照樣每週更新，是讀者每週會回來看的東西。
 *
 *   所以條件是聯集，不是單一區間：
 *     本季新開播（start_date 落在季度區間，含尚未開播）
 *     ∪ 仍在播（status = RELEASING，涵蓋跨季長番與二連續季度作品）
 *
 *   夏番收尾的那些會在自己的完結日翻成 FINISHED 而自動退場，
 *   不需要另外排除；換季當週它們確實還在播，本來就該留在畫面上。
 *
 *   截斷風險仍然不存在：兩邊聯集上限約 124 部（RELEASING 76 ＋ 秋季 48，
 *   且兩者有重疊），遠低於 posts_per_page 的 300。
 */
$season_range   = wxacg_home_current_season_range();
$season_query   = new WP_Query(
    [
        'post_type'              => 'anime',
        'post_status'            => 'publish',
        'posts_per_page'         => 300,
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_term_cache' => false,
        'meta_query'             => [
            'relation' => 'AND',
            [
                'key'     => 'anime_format',
                'value'   => [
                    'TV',
                    'TV_SHORT',
                ],
                'compare' => 'IN',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => 'anime_start_date',
                    'value'   => [ $season_range['from'], $season_range['to'] ],
                    'compare' => 'BETWEEN',
                    'type'    => 'CHAR',
                ],
                [
                    'key'     => 'anime_status',
                    'value'   => 'RELEASING',
                    'compare' => '=',
                ],
            ],
        ],
    ]
);

$anime_by_weekday = [ 0 => [] ];

for ( $day = 1; $day <= 7; $day++ ) {
    $anime_by_weekday[ $day ] = [];
}

$seen_anime = [];

if ( $season_query->have_posts() ) {
    while ( $season_query->have_posts() ) {
        $season_query->the_post();

        $post_id = get_the_ID();

        if ( isset( $seen_anime[ $post_id ] ) ) {
            continue;
        }

        $seen_anime[ $post_id ] = true;

        if (
            strpos(
                (string) get_post_field( 'post_name', $post_id ),
                '.html'
            ) !== false
        ) {
            continue;
        }

        $status = strtoupper(
            trim(
                (string) get_post_meta(
                    $post_id,
                    'anime_status',
                    true
                )
            )
        );

        $episode_total = (int) get_post_meta(
            $post_id,
            'anime_episodes',
            true
        );

        $episode_aired = (int) get_post_meta(
            $post_id,
            'anime_episodes_aired',
            true
        );

        /*
         * 季度／年份的二次過濾已移除：查詢本身就是 anime_status = RELEASING，
         * 撈出來的每一部都正在播出，不需要再判斷一次。
         * （原本的判斷寫在這裡，但 SQL 的 300 筆上限先一步截斷了資料，
         *   反而讓本季作品漏掉——詳見上方查詢的說明。）
         */

        $cover = get_post_meta(
            $post_id,
            'anime_cover_image',
            true
        );

        if ( ! $cover ) {
            $cover = get_the_post_thumbnail_url(
                $post_id,
                'medium'
            );
        }

        if ( ! $cover ) {
            continue;
        }

        $title = get_post_meta(
            $post_id,
            'anime_title_chinese',
            true
        );

        if ( ! $title ) {
            $title = get_the_title( $post_id );
        }

        $native_title = get_post_meta(
            $post_id,
            'anime_title_native',
            true
        );

        $site_score = (float) get_post_meta(
            $post_id,
            'smacg_site_score',
            true
        );

        if ( $site_score <= 0 ) {
            $site_score = (float) get_post_meta(
                $post_id,
                'anime_score_site',
                true
            );
        }

        $anilist_score = (float) get_post_meta(
            $post_id,
            'anime_score_anilist',
            true
        );

        if ( $site_score > 0 ) {
            $score = number_format( $site_score, 1 );
        } elseif ( $anilist_score > 0 ) {
            $score = number_format( $anilist_score / 10, 1 );
        } else {
            $score = '';
        }

        $episode_label = '';

        if ( $episode_total > 0 ) {
            if (
                $episode_aired > 0 &&
                $episode_aired < $episode_total
            ) {
                $episode_label =
                    $episode_aired .
                    '/' .
                    $episode_total .
                    ' 集';
            } else {
                $episode_label = $episode_total . ' 集';
            }
        } elseif ( $episode_aired > 0 ) {
            $episode_label = '第 ' . $episode_aired . ' 集';
        }

        $weekday     = 0;
        $next_airing = get_post_meta(
            $post_id,
            'anime_next_airing',
            true
        );

        /*
         * ★ anime_next_airing 有兩種歷史格式，解析一律走外掛的共用函式。
         *
         *   匯入端寫 JSON {"airingAt":…,"episode":…}，cron 曾經寫純 Unix
         *   時間戳，全站 137 筆中兩種各半。原本這裡先用 strtotime() ——它對
         *   純數字字串一律回 false——81 部正在播出的作品全部失敗，只靠下面
         *   anime_start_date 的後備救回 16 部，週三甚至完全空白。
         *
         *   後來改成 is_numeric() 判斷，數字那種修好了，JSON 那 51 筆仍然
         *   解析失敗。兩個讀取端各寫一份解析正是問題根源，因此統一改用
         *   wxacg_parse_next_airing()（includes/date-helpers.php）。
         */
        if ( $next_airing && function_exists( 'wxacg_parse_next_airing' ) ) {
            $parsed_airing = wxacg_parse_next_airing( $next_airing );

            if ( $parsed_airing['airingAt'] > 0 ) {
                $weekday = (int) wp_date(
                    'N',
                    $parsed_airing['airingAt']
                );
            }
        }

        if ( ! $weekday ) {
            $start_date = get_post_meta(
                $post_id,
                'anime_start_date',
                true
            );

            if ( $start_date ) {
                $start_timestamp = strtotime( $start_date );

                if ( $start_timestamp ) {
                    $weekday = (int) wp_date(
                        'N',
                        $start_timestamp
                    );
                }
            }
        }

        $anime_data = [
            'post_id'       => $post_id,
            'title'         => $title,
            'native_title'  => $native_title,
            'cover'         => $cover,
            'score'         => $score,
            'status'        => $status,
            'episode_total' => $episode_total,
            'episode_aired' => $episode_aired,
            'episode_label' => $episode_label,
            'next_airing'   => $next_airing,
            'weekday'       => $weekday,
            'url'           => get_permalink( $post_id ),
        ];

        $anime_by_weekday[0][] = $anime_data;

        if ( $weekday >= 1 && $weekday <= 7 ) {
            $anime_by_weekday[ $weekday ][] = $anime_data;
        }
    }

    wp_reset_postdata();
}

$season_total = count( $anime_by_weekday[0] );
?>

<!-- ============================================================
     本季 TV 新番
     ============================================================ -->
<section
    class="section season-section"
    id="season-section"
>
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fa-solid fa-calendar-days"></i>
                本季 TV 新番
            </h2>

            <?php if ( $season_total > 0 ) : ?>
                <div
                    class="tab-switch weekday-tabs"
                    id="weekday-tabs"
                    role="tablist"
                    aria-label="依播出星期篩選"
                >
                    <?php foreach ( $weekday_labels as $day => $label ) : ?>
                        <?php
                        $count = count(
                            $anime_by_weekday[ $day ]
                        );

                        if ( $day > 0 && $count === 0 ) {
                            continue;
                        }

                        $active = $day === $current_weekday;
                        ?>

                        <button
                            type="button"
                            class="tab-btn weekday-tab<?php echo $active ? ' active' : ''; ?>"
                            data-day="<?php echo esc_attr( $day ); ?>"
                            role="tab"
                            aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                        >
                            <?php echo esc_html( $label ); ?>

                            <?php if ( $day > 0 && $count > 0 ) : ?>
                                <span class="weekday-tab-count">
                                    <?php echo (int) $count; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <a
                href="<?php echo esc_url( home_url( '/bangumi/' ) ); ?>"
                class="section-link"
            >
                看完整新番表
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="section-description">
            依作品季度、播出狀態及預定播出日期整理；
            實際播出時間請以動畫官方公告及合法播放平台為準。
        </p>

        <?php if ( $season_total > 0 ) : ?>
            <div
                class="season-cards scroll-row"
                id="season-cards"
            >
                <?php foreach ( $anime_by_weekday as $group_day => $day_posts ) : ?>
                    <?php $current_group = $group_day === $current_weekday; ?>

                    <div
                        class="sf-day-group"
                        data-group="<?php echo esc_attr( $group_day ); ?>"
                        style="display:<?php echo $current_group ? 'contents' : 'none'; ?>;"
                    >
                        <?php foreach ( $day_posts as $anime ) : ?>
                            <?php
                            $status_raw = strtoupper(
                                trim( (string) $anime['status'] )
                            );

                            $is_airing =
                                $status_raw === 'RELEASING';

                            $is_upcoming =
                                $status_raw === 'NOT_YET_RELEASED';

                            if (
                                ! $is_airing &&
                                ! $is_upcoming &&
                                $status_raw !== 'FINISHED' &&
                                $status_raw !== 'CANCELLED'
                            ) {
                                if (
                                    ! empty( $anime['next_airing'] ) ||
                                    (
                                        $anime['episode_total'] > 0 &&
                                        $anime['episode_aired'] > 0 &&
                                        $anime['episode_aired'] <
                                        $anime['episode_total']
                                    )
                                ) {
                                    $is_airing = true;
                                }
                            }

                            if ( $is_airing ) {
                                $status_label = '連載中';
                                $status_class = 'status--on-air';
                            } elseif ( $is_upcoming ) {
                                $status_label = '即將開播';
                                $status_class = 'status--upcoming';
                            } else {
                                $status_label = '完結';
                                $status_class = 'status--finished';
                            }

                            $day_label =
                                $group_day === 0 &&
                                $anime['weekday'] >= 1
                                    ? $weekday_labels[
                                        $anime['weekday']
                                    ]
                                    : '';
                            ?>

                            <a
                                href="<?php echo esc_url( $anime['url'] ); ?>"
                                class="season-card glass"
                                data-day="<?php echo esc_attr( $anime['weekday'] ); ?>"
                                title="<?php echo esc_attr( $anime['title'] ); ?>"
                            >
                                <?php if ( $day_label ) : ?>
                                    <span class="season-card-day-badge">
                                        <?php echo esc_html( $day_label ); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ( $is_airing ) : ?>
                                    <span
                                        class="season-card-airing"
                                        aria-label="目前連載中"
                                    ></span>
                                <?php endif; ?>

                                <div class="season-card__cover-wrap">
                                    <img
                                        src="<?php echo esc_url( $anime['cover'] ); ?>"
                                        alt="<?php echo esc_attr( $anime['title'] ); ?>"
                                        class="season-card-img"
                                        loading="lazy"
                                        decoding="async"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                    >

                                    <div
                                        class="season-card-img-ph"
                                        style="display:none"
                                        aria-hidden="true"
                                    >
                                        🎬
                                    </div>

                                    <span
                                        class="season-card__status <?php echo esc_attr( $status_class ); ?>"
                                    >
                                        <?php echo esc_html( $status_label ); ?>
                                    </span>
                                </div>

                                <div class="season-card-body">
                                    <div class="season-card-title">
                                        <?php echo esc_html( $anime['title'] ); ?>
                                    </div>

                                    <?php
                                    if (
                                        $anime['native_title'] &&
                                        $anime['native_title'] !==
                                        $anime['title']
                                    ) :
                                        ?>
                                        <div class="season-card-jp">
                                            <?php echo esc_html( $anime['native_title'] ); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="season-card-meta">
                                        <?php if ( $anime['score'] ) : ?>
                                            <span class="season-card-score">
                                                ★ <?php echo esc_html( $anime['score'] ); ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ( $anime['episode_label'] ) : ?>
                                            <span class="season-card-ep">
                                                <?php echo esc_html( $anime['episode_label'] ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="season-empty glass">
                <i
                    class="fa-solid fa-calendar-xmark fa-2x"
                    aria-hidden="true"
                ></i>

                <p>
                    本季 TV 新番資料正在整理，
                    可先前往完整新番表查看其他季度作品。
                </p>

                <a
                    href="<?php echo esc_url( home_url( '/bangumi/' ) ); ?>"
                    class="btn btn-secondary"
                >
                    查看新番表
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    'use strict';

    const tabs = document.querySelectorAll(
        '#weekday-tabs .weekday-tab'
    );

    const groups = document.querySelectorAll(
        '#season-cards .sf-day-group'
    );

    if (!tabs.length || !groups.length) {
        return;
    }

    function showDay(day) {
        const selectedDay = String(day);

        tabs.forEach(function (tab) {
            const active =
                tab.dataset.day === selectedDay;

            tab.classList.toggle('active', active);
            tab.setAttribute(
                'aria-selected',
                active ? 'true' : 'false'
            );
        });

        groups.forEach(function (group) {
            const active =
                group.dataset.group === selectedDay;

            group.style.display =
                active ? 'contents' : 'none';
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            showDay(this.dataset.day);
        });
    });

    const browserDay = new Date().getDay();
    const today      = browserDay === 0 ? 7 : browserDay;

    const todayTab = document.querySelector(
        '#weekday-tabs .weekday-tab[data-day="' +
        today +
        '"]'
    );

    showDay(todayTab ? today : 0);
})();
</script>

<?php
/* ============================================================
 * 動漫作品推薦
 * ============================================================ */
if ( $current_month <= 3 ) {
    $next_season = 'SPRING';
    $next_year   = $current_year;
} elseif ( $current_month <= 6 ) {
    $next_season = 'SUMMER';
    $next_year   = $current_year;
} elseif ( $current_month <= 9 ) {
    $next_season = 'FALL';
    $next_year   = $current_year;
} else {
    $next_season = 'WINTER';
    $next_year   = $current_year + 1;
}

$trending_query = new WP_Query(
    [
        'post_type'      => 'anime',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'meta_key'       => 'anime_score_site_count',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]
);

$top_query = new WP_Query(
    [
        'post_type'      => 'anime',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'meta_key'       => 'anime_score_site',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]
);

/*
 * 「即將開播」依開播日由近而遠排序。
 *
 * 原本是 orderby => 'date'，排的是文章建檔時間（誰最近被匯入誰在前），
 * 與開播日無關。實測 FALL 2026 有 72 部、其中 10 月就佔 57 部，但顯示的
 * 六部裡只有兩部是 10 月，其餘是 11 月、9 月與開播日未定的作品。
 *
 * anime_start_date >= 今天：
 *   同季度資料中混有開播日已過的條目（實測 FALL 2026 有兩部標成 202601，
 *   屬上游資料錯誤）。不擋掉的話，升冪排序會把它們推到最前面。
 *
 * 注意 meta_key 會產生 INNER JOIN，開播日未定的作品因此不會出現。
 * 這對「即將開播」是正確的——日期未定本來就稱不上即將開播，且符合
 * 條件的作品有 66 部，六個位置不會缺料。
 */
$today_ymd = (int) current_time( 'Ymd' );

$upcoming_query = new WP_Query(
    [
        'post_type'      => 'anime',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'no_found_rows'  => true,
        'meta_key'       => 'anime_start_date',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'anime_season',
                'value'   => $next_season,
                'compare' => '=',
            ],
            [
                'key'     => 'anime_season_year',
                'value'   => $next_year,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ],
            [
                'key'     => 'anime_start_date',
                'value'   => $today_ymd,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ],
        ],
    ]
);
?>

<section
    class="section"
    id="hot-anime-section"
>
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fa-solid fa-star"></i>
                動漫作品推薦
            </h2>

            <div
                class="tab-switch"
                role="tablist"
                aria-label="動漫作品分類"
            >
                <button
                    type="button"
                    class="smacg-tab-btn active"
                    data-tab="trending"
                    role="tab"
                    aria-selected="true"
                >
                    本站熱門
                </button>

                <button
                    type="button"
                    class="smacg-tab-btn"
                    data-tab="top"
                    role="tab"
                    aria-selected="false"
                >
                    本站高評分
                </button>

                <button
                    type="button"
                    class="smacg-tab-btn"
                    data-tab="upcoming"
                    role="tab"
                    aria-selected="false"
                >
                    即將開播
                </button>
            </div>

            <a
                href="<?php echo esc_url( home_url( '/anime/' ) ); ?>"
                class="section-link"
            >
                更多作品
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="section-description">
            依本站評分、互動資料及作品播出狀態整理，
            不代表動畫官方或第三方平台排名。
        </p>

        <div
            class="wxacg-anime-grid"
            id="wxacg-tab-trending"
            role="tabpanel"
        >
            <?php if ( $trending_query->have_posts() ) : ?>
                <?php
                while ( $trending_query->have_posts() ) {
                    $trending_query->the_post();
                    wxacg_home_anime_card( get_post() );
                }

                wp_reset_postdata();
                ?>
            <?php else : ?>
                <p class="smacg-tab-empty">
                    目前尚無足夠的互動資料。
                </p>
            <?php endif; ?>
        </div>

        <div
            class="wxacg-anime-grid"
            id="wxacg-tab-top"
            role="tabpanel"
            hidden
        >
            <?php if ( $top_query->have_posts() ) : ?>
                <?php
                while ( $top_query->have_posts() ) {
                    $top_query->the_post();
                    wxacg_home_anime_card( get_post() );
                }

                wp_reset_postdata();
                ?>
            <?php else : ?>
                <p class="smacg-tab-empty">
                    目前尚無足夠的評分資料。
                </p>
            <?php endif; ?>
        </div>

        <div
            class="wxacg-anime-grid"
            id="wxacg-tab-upcoming"
            role="tabpanel"
            hidden
        >
            <?php if ( $upcoming_query->have_posts() ) : ?>
                <?php
                while ( $upcoming_query->have_posts() ) {
                    $upcoming_query->the_post();
                    wxacg_home_anime_card( get_post() );
                }

                wp_reset_postdata();
                ?>
            <?php else : ?>
                <p class="smacg-tab-empty">
                    下一季作品資料仍在整理。
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function () {
    'use strict';

    const buttons = document.querySelectorAll(
        '#hot-anime-section .smacg-tab-btn'
    );

    const panels = {
        trending: document.getElementById(
            'wxacg-tab-trending'
        ),
        top: document.getElementById(
            'wxacg-tab-top'
        ),
        upcoming: document.getElementById(
            'wxacg-tab-upcoming'
        )
    };

    if (!buttons.length) {
        return;
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            const target = this.dataset.tab;

            buttons.forEach(function (item) {
                const active = item === button;

                item.classList.toggle('active', active);
                item.setAttribute(
                    'aria-selected',
                    active ? 'true' : 'false'
                );
            });

            Object.keys(panels).forEach(function (key) {
                const panel = panels[key];

                if (!panel) {
                    return;
                }

                const active = key === target;

                panel.hidden = !active;
                panel.style.display =
                    active ? 'grid' : 'none';
            });
        });
    });
})();
</script>

<?php
/* ============================================================
 * 最新內容 Tabs
 * ============================================================ */
$news_tabs = [
    'all' => [
        'label' => '全部',
        'icon'  => 'fa-layer-group',
        'link'  => home_url( '/news/' ),
        'args'  => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [
                [
                    'taxonomy'         => 'category',
                    'field'            => 'slug',
                    'terms'            => [
                        'news',
                        'review',
                        'feature',
                    ],
                    'operator'         => 'IN',
                    'include_children' => true,
                ],
            ],
        ],
    ],
    'news' => [
        'label' => '新聞',
        'icon'  => 'fa-newspaper',
        'link'  => home_url( '/news/' ),
        'args'  => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'category_name'  => 'news',
        ],
    ],
    'review' => [
        'label' => '評論',
        'icon'  => 'fa-pen-fancy',
        'link'  => home_url( '/columns/' ),
        'args'  => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'category_name'  => 'review',
        ],
    ],
    'feature' => [
        'label' => '專題',
        'icon'  => 'fa-bookmark',
        'link'  => home_url( '/columns/' ),
        'args'  => [
            'post_type'      => 'post',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'category_name'  => 'feature',
        ],
    ],
];

foreach ( $news_tabs as &$news_tab ) {
    $news_tab['query'] = new WP_Query(
        $news_tab['args']
    );
}

unset( $news_tab );

$ticker_items = [];

if ( ! empty( $news_tabs['all']['query']->posts ) ) {
    foreach (
        array_slice(
            $news_tabs['all']['query']->posts,
            0,
            6
        ) as $ticker_post
    ) {
        $ticker_items[] = [
            'title' => get_the_title( $ticker_post ),
            'url'   => get_permalink( $ticker_post ),
        ];
    }
}
?>

<section
    class="section"
    id="news-section"
>
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fa-solid fa-newspaper"></i>
                最新動漫內容
            </h2>

            <a
                href="<?php echo esc_url( home_url( '/news/' ) ); ?>"
                class="section-link"
            >
                查看全部
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="section-description">
            收錄動漫新聞、作品評論與主題專題；
            外部消息以官方公告或原始發布來源為優先依據。
        </p>

        <?php if ( ! empty( $ticker_items ) ) : ?>
            <aside
                class="news-ticker-wrap"
                aria-label="最新動漫快訊"
            >
                <span class="news-ticker-label">
                    <i class="fa-solid fa-bolt"></i>
                    快訊
                </span>

                <div class="news-ticker-overflow">
                    <div
                        class="news-ticker-track"
                        id="tickerTrack"
                    >
                        <?php foreach ( $ticker_items as $item ) : ?>
                            <span>
                                <a href="<?php echo esc_url( $item['url'] ); ?>">
                                    <?php echo esc_html( $item['title'] ); ?>
                                </a>
                                &nbsp;&nbsp;·&nbsp;&nbsp;
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        <?php endif; ?>

        <div
            class="news-tabs"
            id="news-tabs"
            role="tablist"
            aria-label="最新內容分類"
        >
            <?php $first_tab = true; ?>

            <?php foreach ( $news_tabs as $tab_key => $tab ) : ?>
                <button
                    type="button"
                    class="news-tab-btn<?php echo $first_tab ? ' active' : ''; ?>"
                    role="tab"
                    data-tab="<?php echo esc_attr( $tab_key ); ?>"
                    aria-controls="news-panel-<?php echo esc_attr( $tab_key ); ?>"
                    aria-selected="<?php echo $first_tab ? 'true' : 'false'; ?>"
                >
                    <i class="fa-solid <?php echo esc_attr( $tab['icon'] ); ?>"></i>

                    <span class="news-tab-label">
                        <?php echo esc_html( $tab['label'] ); ?>
                    </span>

                    <?php if ( $tab['query']->found_posts > 0 ) : ?>
                        <span class="news-tab-count">
                            <?php echo (int) $tab['query']->found_posts; ?>
                        </span>
                    <?php endif; ?>
                </button>

                <?php $first_tab = false; ?>
            <?php endforeach; ?>
        </div>

        <?php $first_panel = true; ?>

        <?php foreach ( $news_tabs as $tab_key => $tab ) : ?>
            <div
                class="news-panel<?php echo $first_panel ? ' active' : ''; ?>"
                id="news-panel-<?php echo esc_attr( $tab_key ); ?>"
                role="tabpanel"
                <?php echo $first_panel ? '' : 'hidden'; ?>
            >
                <?php if ( $tab['query']->have_posts() ) : ?>
                    <div class="news-grid">
                        <?php
                        while ( $tab['query']->have_posts() ) {
                            $tab['query']->the_post();
                            wxacg_home_article_card( get_post() );
                        }

                        wp_reset_postdata();
                        ?>
                    </div>

                    <?php if ( $tab['query']->found_posts > 12 ) : ?>
                        <div class="news-panel-more">
                            <a
                                href="<?php echo esc_url( $tab['link'] ); ?>"
                                class="btn btn-secondary"
                            >
                                查看更多<?php echo esc_html( $tab['label'] ); ?>
                                <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="news-empty glass-mid">
                        <span class="news-empty-icon" aria-hidden="true">
                            📭
                        </span>

                        <p>
                            目前尚無<?php echo esc_html( $tab['label'] ); ?>內容。
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <?php $first_panel = false; ?>
        <?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    'use strict';

    const tabs = document.querySelectorAll(
        '#news-tabs .news-tab-btn'
    );

    const panels = document.querySelectorAll(
        '#news-section .news-panel'
    );

    if (!tabs.length || !panels.length) {
        return;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const target = this.dataset.tab;

            tabs.forEach(function (item) {
                const active = item === tab;

                item.classList.toggle('active', active);
                item.setAttribute(
                    'aria-selected',
                    active ? 'true' : 'false'
                );
            });

            panels.forEach(function (panel) {
                const active =
                    panel.id === 'news-panel-' + target;

                panel.classList.toggle('active', active);
                panel.hidden = !active;
            });
        });
    });
})();
</script>

<!-- ============================================================
     熱門話題（討論區最新動態）
     ============================================================
     原本這裡是「內容與資料原則」的四張靜態說明卡。那些內容在
     /about/ 與各作品頁的免責說明已有交代，放在首頁佔一整段版位卻
     不會有人回訪。

     改放討論區動態的理由：站上論壇有 8 個版、卻只有 3 個話題、
     7 位會員發過文——不是工具不好，是沒有人知道那裡有人在講話。
     首頁是流量最高的一頁，把討論拉到這裡曝光，比在論壇裡等人自己
     走進去有效。

     ★ 資料直接查 wpForo 的資料表，不呼叫它的模板函式
       wpforo_topic() 這類函式依賴 wpForo 自己的初始化流程，在首頁
       這個時機呼叫會 fatal（實測 CLI 環境即報
       "Call to a member function get_topic() on null"）。查表最穩，
       而且只有一次查詢。

     ★ 網址的 base 取自 wpforo_boards.slug，不是 get_permalink(pageid)
       兩者不一定相同：本站的 board slug 是 community，但它掛的頁面
       （pageid=55）slug 卻是 forum。用頁面網址組出來的
       /forum/{版}/{話題}/ 實測是 404，只有 /community/... 才通。
       也不寫死 community——那是後台可改的設定。
     ============================================================ -->
<?php
$wxacg_forum_topics = [];
$wxacg_forum_url    = '';

if ( class_exists( 'wpForo' ) || function_exists( 'wpforo_setting' ) ) {
    global $wpdb;

    $wxacg_board      = $wpdb->get_row( "SELECT boardid, slug FROM {$wpdb->prefix}wpforo_boards ORDER BY boardid ASC LIMIT 1", ARRAY_A );
    $wxacg_board_slug = trim( (string) ( $wxacg_board['slug'] ?? '' ), '/' );

    if ( $wxacg_board_slug !== '' ) {
        $wxacg_forum_url = home_url( '/' . $wxacg_board_slug . '/' );
    }

    if ( $wxacg_forum_url !== '' ) {
        /*
         * 依「最後活動時間」排序而非回覆數：使用者要看的是「現在有人在
         * 講什麼」，久遠的熱門話題擺首頁反而像沒人管。private / status
         * 過濾掉未公開與待審的話題。
         */
        $wxacg_forum_topics = $wpdb->get_results(
            "SELECT t.topicid, t.title, t.slug, t.posts, t.views, t.modified, t.userid,
                    f.slug AS forum_slug, f.title AS forum_title
               FROM {$wpdb->prefix}wpforo_topics t
               INNER JOIN {$wpdb->prefix}wpforo_forums f ON f.forumid = t.forumid
              WHERE t.private = 0 AND t.status = 0
              ORDER BY t.modified DESC
              LIMIT 4",
            ARRAY_A
        );
    }
}
?>

<?php if ( ! empty( $wxacg_forum_topics ) ) : ?>
<section
    class="section coming-soon-section"
    id="forum-hot-topics"
>
    <div class="container">
        <div class="section-header">
            <div>
                <h2 class="section-title">
                    🔥 熱門話題
                </h2>

                <p class="section-description">
                    討論區最近有人在聊這些，點進去一起說說看。
                </p>
            </div>

            <a href="<?php echo esc_url( $wxacg_forum_url ); ?>" class="section-link">
                前往討論區 →
            </a>
        </div>

        <div class="coming-cards-grid">
            <?php foreach ( $wxacg_forum_topics as $wxacg_t ) :
                $wxacg_topic_url = $wxacg_forum_url
                    . $wxacg_t['forum_slug'] . '/'
                    . $wxacg_t['slug'] . '/';

                // slug 在資料表裡已是 urlencode 過的形態，標題才是給人看的
                $wxacg_title = (string) $wxacg_t['title'];

                $wxacg_author = get_userdata( (int) $wxacg_t['userid'] );
                $wxacg_author_name = $wxacg_author
                    ? ( $wxacg_author->display_name ?: $wxacg_author->user_login )
                    : '訪客';

                // 「3 天前」比絕對日期更能傳達「這是不是最近的事」
                $wxacg_ago = human_time_diff(
                    strtotime( $wxacg_t['modified'] ),
                    current_time( 'timestamp' )
                );
                ?>
                <a href="<?php echo esc_url( $wxacg_topic_url ); ?>" class="coming-card glass forum-topic-card">
                    <div class="coming-card-icon" aria-hidden="true">💬</div>

                    <h3 class="coming-card-title">
                        <?php echo esc_html( $wxacg_title ); ?>
                    </h3>

                    <p class="coming-card-desc">
                        <?php echo esc_html( $wxacg_t['forum_title'] ); ?>
                        ・<?php echo esc_html( $wxacg_author_name ); ?>
                    </p>

                    <div class="forum-topic-meta">
                        <span>💬 <?php echo (int) $wxacg_t['posts']; ?></span>
                        <span>👁 <?php echo (int) $wxacg_t['views']; ?></span>
                        <span><?php echo esc_html( $wxacg_ago ); ?>前</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
/* ============================================================
 * 會員 CTA
 * ============================================================ */
/*
 * 6 階等級表以 Level_System::get_all_tiers() 為唯一來源
 * （透過 legacy wrapper smacg_get_all_member_tiers() 取得）。
 *
 * 原本這裡是一份寫死的副本，但 v2.7.0 等級公式的 DIVISOR 由 5 改為 50 後
 * 沒有同步更新，導致首頁顯示的 EXP 門檻全部只有實際值的十分之一
 * （例如 VIP 顯示 72,000，實際需要 720,000）。
 * 改讀權威來源後，日後調整等級曲線不需要再手動同步這份清單。
 *
 * 樣式標籤（tag-*）純屬前台呈現、等級系統不提供，因此仍留在這裡對應。
 */
$member_tier_tags = [
    'rookie'   => 'tag-cyan',
    'newcomer' => 'tag-green',
    'regular'  => 'tag-blue',
    'expert'   => 'tag-purple',
    'vip'      => 'tag-orange',
    'black'    => 'tag-locked',
];

$member_tiers = [];

if ( function_exists( 'smacg_get_all_member_tiers' ) ) {
    foreach ( (array) smacg_get_all_member_tiers() as $member_tier_row ) {
        if ( ! is_array( $member_tier_row ) ) {
            continue;
        }

        $member_tier_row_key = (string) ( $member_tier_row['key'] ?? '' );

        $member_tiers[] = [
            'tier'      => (int) ( $member_tier_row['tier'] ?? 0 ),
            'key'       => $member_tier_row_key,
            'title'     => (string) ( $member_tier_row['title'] ?? '' ),
            'icon'      => (string) ( $member_tier_row['icon'] ?? '' ),
            'min_level' => (int) ( $member_tier_row['min_level'] ?? 0 ),
            'min_exp'   => (int) ( $member_tier_row['min_exp'] ?? 0 ),
            'tag'       => $member_tier_tags[ $member_tier_row_key ] ?? 'tag-cyan',
        ];
    }
}

$member_info = null;

if (
    is_user_logged_in() &&
    function_exists( 'wxacg_get_user_level_info' )
) {
    $member_info = wxacg_get_user_level_info(
        get_current_user_id()
    );
}

$member_level = is_array( $member_info )
    ? (int) ( $member_info['level'] ?? 0 )
    : 0;

$member_exp = is_array( $member_info )
    ? (int) ( $member_info['exp'] ?? 0 )
    : 0;

$member_tier_key = is_array( $member_info )
    ? (string) ( $member_info['tier_key'] ?? '' )
    : '';

$member_percent = is_array( $member_info )
    ? (int) ( $member_info['percent'] ?? 0 )
    : 0;

$member_to_next = is_array( $member_info )
    ? (int) ( $member_info['to_next'] ?? 0 )
    : 0;

$member_percent = min(
    100,
    max( 0, $member_percent )
);
?>

<section class="section member-cta-section">
    <div class="container">
        <div class="member-cta-grid">

            <div class="member-cta-left">
                <span class="member-cta-badge">
                    <i class="fa-solid fa-user-plus"></i>
                    免費加入會員
                </span>

                <h2 class="member-cta-title">
                    <span class="cta-title-part">建立你的動漫收藏</span><span class="cta-title-part">與追番紀錄</span>
                </h2>

                <p class="member-cta-desc">
                    <span class="cta-desc-item">收藏作品</span><span class="cta-desc-sep">・</span><span class="cta-desc-item">記錄追番進度</span><span class="cta-desc-sep">・</span><span class="cta-desc-item">建立個人清單</span><br class="cta-desc-break" /><span class="cta-desc-item">參與討論</span><span class="cta-desc-sep">・</span><span class="cta-desc-item">解鎖會員成就</span>
                </p>

                <div class="member-cta-btns">
                    <?php if ( is_user_logged_in() ) : ?>
                        <a
                            href="<?php
                            echo esc_url(
                                function_exists( 'wxacg_get_member_center_url' )
                                    ? wxacg_get_member_center_url()
                                    : home_url( '/mc/' )
                            );
                            ?>"
                            class="btn btn-primary"
                        >
                            <i class="fa-solid fa-user"></i>
                            前往會員中心
                        </a>
                    <?php else : ?>
                        <button
                            type="button"
                            class="btn btn-primary"
                            id="smacg-cta-register-btn"
                        >
                            <i class="fa-solid fa-user-plus"></i>
                            免費註冊
                        </button>
                    <?php endif; ?>

                    <a
                        href="<?php echo esc_url( home_url( '/level-guide/' ) ); ?>"
                        class="btn btn-secondary"
                    >
                        <i class="fa-solid fa-compass"></i>
                        探索會員功能
                    </a>
                </div>
            </div>

            <div class="member-level-panel glass-mid">
                <?php if ( $member_info ) : ?>
                    <div class="member-level-progress-card">
                        <div class="mlp-row1">
                            <span class="mlp-tier-icon">
                                <?php echo esc_html( $member_info['icon'] ?? '🌱' ); ?>
                            </span>

                            <div class="mlp-row1-text">
                                <div class="mlp-tier-name">
                                    <?php echo esc_html( $member_info['title'] ?? '會員' ); ?>

                                    <span class="mlp-lv">
                                        Lv.<?php echo (int) $member_level; ?>
                                    </span>
                                </div>

                                <div class="mlp-exp-line">
                                    EXP
                                    <strong>
                                        <?php echo esc_html( number_format( $member_exp ) ); ?>
                                    </strong>

                                    <?php if ( ! ( $member_info['is_max'] ?? false ) ) : ?>
                                        ・距下一級還差
                                        <strong>
                                            <?php echo esc_html( number_format( $member_to_next ) ); ?>
                                        </strong>
                                    <?php else : ?>
                                        ・已達最高等級
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mlp-bar">
                            <div
                                class="mlp-bar-fill"
                                style="width:<?php echo (int) $member_percent; ?>%;"
                            ></div>
                        </div>

                        <div class="mlp-bar-percent">
                            <?php echo (int) $member_percent; ?>%
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $member_tiers ) ) : ?>
                <div class="member-level-title">
                    <?php echo $member_info ? '會員成長路徑' : '6 階會員成長路徑'; ?>
                </div>

                <div class="member-level-list">
                    <?php foreach ( $member_tiers as $tier ) : ?>
                        <?php
                        $reached =
                            $member_info &&
                            $member_level >=
                            $tier['min_level'];

                        $current =
                            $member_info &&
                            $member_tier_key ===
                            $tier['key'];

                        $locked = in_array(
                            $tier['key'],
                            [ 'vip', 'black' ],
                            true
                        );

                        $item_class = 'member-level-item';

                        if ( $locked && ! $reached ) {
                            $item_class .= ' member-level-locked';
                        }

                        if ( $reached ) {
                            $item_class .= ' member-level-reached';
                        }

                        if ( $current ) {
                            $item_class .= ' member-level-current';
                        }
                        ?>

                        <div class="<?php echo esc_attr( $item_class ); ?>">
                            <div class="member-level-icon">
                                <?php echo esc_html( $tier['icon'] ); ?>
                            </div>

                            <div class="member-level-info">
                                <div class="member-level-name">
                                    Lv.<?php echo (int) $tier['min_level']; ?>

                                    <?php if ( $current ) : ?>
                                        <span class="member-level-here">
                                            ← 你在這裡
                                        </span>
                                    <?php elseif ( $reached ) : ?>
                                        <i
                                            class="fa-solid fa-check member-level-check"
                                            aria-label="已達成"
                                        ></i>
                                    <?php endif; ?>
                                </div>

                                <div class="member-level-sub">
                                    <?php echo esc_html( $tier['title'] ); ?>
                                    ・
                                    <?php echo esc_html( number_format( $tier['min_exp'] ) ); ?>
                                    EXP
                                </div>
                            </div>

                            <span class="member-level-tag <?php echo esc_attr( $tier['tag'] ); ?>">
                                <?php if ( $locked && ! $reached ) : ?>
                                    <i
                                        class="fa-solid fa-lock"
                                        aria-label="尚未解鎖"
                                    ></i>
                                <?php else : ?>
                                    T<?php echo (int) $tier['tier']; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <a
                    href="<?php echo esc_url( home_url( '/level-guide/' ) ); ?>"
                    class="member-level-more"
                >
                    查看完整等級指南
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

        </div>
    </div>
</section>

<script>
(function () {
    'use strict';

    const button = document.getElementById(
        'smacg-cta-register-btn'
    );

    if (!button) {
        return;
    }

    button.addEventListener('click', function () {
        if (
            typeof window.smacgOpenLoginModal === 'function'
        ) {
            window.smacgOpenLoginModal('register');
            return;
        }

        window.location.href =
            <?php echo wp_json_encode( wp_registration_url() ); ?>;
    });
})();
</script>

<?php get_footer(); ?>
