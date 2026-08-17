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
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/07/5jrnQqTH.webp',
        'title' => '查看動漫資料庫',
        'url'   => home_url( '/anime/' ),
        'external' => false,
    ],
    [
        'img'   => 'https://weixiaoacg.com/wp-content/uploads/2026/07/Discord1.webp',
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
<section class="hero-section" id="hero">
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
                <span class="line-gradient">查詢新番<br>角色與聲優</span><br>
                <span class="line-accent">掌握動漫播出資訊</span>
            </h1>

            <p class="hero-subtitle hero-site-description">
                整理本季新番、動畫作品、播出進度、角色聲優、
                動漫新聞、評論與專題內容。
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

        <div
            class="hero-posters"
            id="hero-posters"
            aria-label="首頁推薦入口"
        >
            <?php foreach ( $hero_posters as $index => $poster ) : ?>
                <a
                    href="<?php echo esc_url( $poster['url'] ); ?>"
                    class="poster-item glass"
                    title="<?php echo esc_attr( $poster['title'] ); ?>"
                    <?php if ( $poster['external'] ) : ?>
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
        </div>

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

if ( $current_month <= 3 ) {
    $current_season = 'WINTER';
} elseif ( $current_month <= 6 ) {
    $current_season = 'SPRING';
} elseif ( $current_month <= 9 ) {
    $current_season = 'SUMMER';
} else {
    $current_season = 'FALL';
}

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

$previous_map = [
    'WINTER' => [ 'FALL', $current_year - 1 ],
    'SPRING' => [ 'WINTER', $current_year ],
    'SUMMER' => [ 'SPRING', $current_year ],
    'FALL'   => [ 'SUMMER', $current_year ],
];

list(
    $previous_season,
    $previous_year
) = $previous_map[ $current_season ];

$season_query = new WP_Query(
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
                'key'     => 'anime_season',
                'value'   => [
                    $current_season,
                    $previous_season,
                ],
                'compare' => 'IN',
            ],
            [
                'key'     => 'anime_format',
                'value'   => [
                    'TV',
                    'TV_SHORT',
                ],
                'compare' => 'IN',
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

        $anime_season = (string) get_post_meta(
            $post_id,
            'anime_season',
            true
        );

        $anime_year = (int) get_post_meta(
            $post_id,
            'anime_season_year',
            true
        );

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

        $still_airing =
            $status === 'RELEASING' ||
            (
                $episode_total > 0 &&
                $episode_aired > 0 &&
                $episode_aired < $episode_total
            );

        $keep = false;

        if (
            $anime_season === $current_season &&
            $anime_year === $current_year
        ) {
            $keep = true;
        } elseif (
            $anime_season === $previous_season &&
            $anime_year === $previous_year &&
            $still_airing
        ) {
            $keep = true;
        }

        if ( ! $keep ) {
            continue;
        }

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

        if ( $next_airing ) {
            $airing_timestamp = strtotime( $next_airing );

            if ( $airing_timestamp ) {
                $weekday = (int) wp_date(
                    'N',
                    $airing_timestamp
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

$upcoming_query = new WP_Query(
    [
        'post_type'      => 'anime',
        'post_status'    => 'publish',
        'posts_per_page' => 6,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
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
     內容與資料原則
     ============================================================ -->
<section
    class="section coming-soon-section"
    id="content-principles"
>
    <div class="container">
        <div class="section-header">
            <div>
                <h2 class="section-title">
                    內容與資料原則
                </h2>

                <p class="section-description">
                    說明本站如何整理、更新及修正動漫資料。
                </p>
            </div>
        </div>

        <div class="coming-cards-grid">
            <article class="coming-card glass">
                <div class="coming-card-icon" aria-hidden="true">
                    🗂️
                </div>

                <h3 class="coming-card-title">
                    動漫資料整理
                </h3>

                <p class="coming-card-desc">
                    整理作品名稱、季度、播出狀態、集數、
                    角色及聲優資訊，並持續更新與修正。
                </p>
            </article>

            <article class="coming-card glass">
                <div class="coming-card-icon" aria-hidden="true">
                    📰
                </div>

                <h3 class="coming-card-title">
                    新聞來源
                </h3>

                <p class="coming-card-desc">
                    新聞以動畫官方網站、官方社群、
                    製作委員會及合法發布來源為主要依據。
                </p>
            </article>

            <article class="coming-card glass">
                <div class="coming-card-icon" aria-hidden="true">
                    ✍️
                </div>

                <h3 class="coming-card-title">
                    評論與專題
                </h3>

                <p class="coming-card-desc">
                    評論及專題加入本站整理、分析與觀點，
                    並與單純新聞消息清楚區分。
                </p>
            </article>

            <article class="coming-card glass">
                <div class="coming-card-icon" aria-hidden="true">
                    🛠️
                </div>

                <h3 class="coming-card-title">
                    錯誤修正
                </h3>

                <p class="coming-card-desc">
                    若發現作品名稱、播出日期或人物資料有誤，
                    歡迎透過網站聯絡方式提出修正。
                </p>
            </article>
        </div>
    </div>
</section>

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
                    建立你的動漫收藏與追番紀錄
                </h2>

                <p class="member-cta-desc">
                    收藏作品・記錄追番進度・建立個人清單・
                    參與討論・解鎖會員成就
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
