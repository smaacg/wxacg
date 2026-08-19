<?php
/**
 * Member Center - Render Layer
 *
 * 各 tab 的 HTML 輸出。清單先渲染前 N 筆，其餘 AJAX 載入（桌機 20 / 行動 12）。
 *
 * @package WXACG\Members
 * @version 1.3.0
 *
 * Changelog:
 *   1.3.0 (2026-05-22)
 *     - smacg_render_points()：在原本「等級摘要 + EXP 紀錄」上方新增
 *       「段位積分摘要卡」，顯示當季賽季分、目前段位、名次、距離下一段
 *       還差幾分。資料源：smacg_get_user_rank_season_info()。
 *       與 page-level-guide.php 的 #season-score 區塊互相對應。
 *     - 補強 EXP 與賽季分的視覺區隔（永久 vs. 賽季重置）。
 *   1.2.0 (2026-05-22)
 *     - smacg_render_settings()：基本資料新增生日輸入區 + 獨立 nonce + AJAX。
 *   1.1.0 (2026-05-16)
 *     - wxacg_render_notification_prefs_card() / smacg_render_settings()
 *       的 fallback 預設值改呼叫資料層 defaults。
 *   1.0.0 (2026-05-15) 首版。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'SMACG_PAGE_SIZE' ) )         define( 'SMACG_PAGE_SIZE', 20 );
if ( ! defined( 'SMACG_COMMENT_PAGE_SIZE' ) ) define( 'SMACG_COMMENT_PAGE_SIZE', 20 );

/* ========== 共用：單張 anime 卡片 ========== */
function wxacg_render_anime_card( $pid, $extra = [] ) {
    if ( ! $pid || get_post_status( $pid ) !== 'publish' ) return;

    $title     = get_the_title( $pid );
    $permalink = get_permalink( $pid );
    $thumb     = wxacg_get_card_thumb_url( $pid );
    $score     = get_post_meta( $pid, 'anime_score_anilist', true );
    $score     = $score ? round( $score / 10, 1 ) : null;
    $year      = (int) get_post_meta( $pid, 'anime_season_year', true );
    $eps       = (int) get_post_meta( $pid, 'anime_episodes', true );

    $status     = $extra['status']     ?? '';
    $progress   = (int) ( $extra['progress'] ?? 0 );
    $favorited  = ! empty( $extra['favorited'] );
    $user_score = $extra['user_score'] ?? null;

    $status_label = [
        'watching'  => '追番中', 'completed' => '已看完', 'want' => '想看',
        'dropped'   => '棄番',   'favorited' => '收藏',
        'paused'    => '暫停',
    ];
    ?>
    <article class="mc-anime-card"
         data-status="<?php echo esc_attr( $status ); ?>"
         data-favorited="<?php echo $favorited ? '1' : '0'; ?>"
         data-title="<?php echo esc_attr( mb_strtolower( $title ) ); ?>">

        <a href="<?php echo esc_url( $permalink ); ?>" class="mc-card-thumb">
            <?php if ( $thumb ): ?>
                <?php if ( function_exists( 'smacg_picture_tag' ) && has_post_thumbnail( $pid ) ) {
                    echo smacg_picture_tag( $pid, 'medium', $title );
                } else { ?>
                    <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
                <?php } ?>
            <?php else: ?>
                <div class="mc-card-thumb-ph" aria-hidden="true">🎬</div>
            <?php endif; ?>
            <?php if ( $favorited ): ?><span class="mc-card-heart" title="已收藏">♥</span><?php endif; ?>
            <?php if ( $status && isset( $status_label[ $status ] ) ): ?>
                <span class="mc-card-status mc-card-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label[ $status ] ); ?></span>
            <?php endif; ?>
        </a>
        <div class="mc-card-body">
            <h4 class="mc-card-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h4>
            <div class="mc-card-meta">
                <?php if ( $year ):  ?><span><?php echo $year; ?></span><?php endif; ?>
                <?php if ( $eps ):   ?><span><?php echo $eps; ?> 集</span><?php endif; ?>
                <?php if ( $score ): ?><span class="mc-card-score">⭐ <?php echo $score; ?></span><?php endif; ?>
            </div>
            <?php if ( $status === 'watching' && $eps ): ?>
                <div class="mc-card-progress">
                    <div class="mc-card-progress-fill" style="width:<?php echo min( 100, round( $progress / $eps * 100 ) ); ?>%"></div>
                    <span><?php echo $progress; ?> / <?php echo $eps; ?></span>
                </div>
            <?php endif; ?>
            <?php if ( $user_score !== null ): ?>
                <div class="mc-card-userscore">我的評分：<b><?php echo number_format( (float) $user_score, 1 ); ?></b></div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

/* ========== 縮圖 fallback ========== */
function wxacg_get_card_thumb_url( $pid ) {
    if ( has_post_thumbnail( $pid ) ) return get_the_post_thumbnail_url( $pid, 'medium' );
    $meta = get_post_meta( $pid, 'anime_cover_image', true );
    if ( $meta ) return esc_url( $meta );
    $content = get_post_field( 'post_content', $pid );
    if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $m ) ) return $m[1];
    return '';
}

/* ===== Tab 1：總覽 Dashboard ===== */
function smacg_render_dashboard( $watchlist, $stats, $recent_cmt, $points_log, $plan_label, $uid = 0 ) {
    $watching = array_slice( array_filter( $watchlist, fn( $w ) => $w['status'] === 'watching' ), 0, 6 );

    $activity = ( $uid > 0 && function_exists( 'smacg_get_recent_activity' ) )
        ? smacg_get_recent_activity( $uid, 15 )
        : [];
    $current_year = (int) date( 'Y' );
    ?>
    <div class="mc-dash-grid">
        <div class="mc-widget mc-widget--watching">
            <h3>🎬 追番中 <small>（最近 6 部）</small></h3>
            <?php if ( $watching ): ?>
                <div class="mc-card-grid mc-card-grid--compact">
                    <?php foreach ( $watching as $w ) wxacg_render_anime_card( $w['post_id'], $w ); ?>
                </div>
            <?php else: ?>
                <p class="mc-empty">尚未追番，<a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>">去找喜歡的作品</a> →</p>
            <?php endif; ?>
        </div>

        <div class="mc-widget mc-widget--quick">
            <h3>📊 快速概覽</h3>
            <ul class="mc-quick-list">
                <li><span class="mc-dot mc-dot--watching"></span>追番中 <b><?php echo (int) $stats['counts']['watching']; ?></b></li>
                <li><span class="mc-dot mc-dot--completed"></span>已看完 <b><?php echo (int) $stats['counts']['completed']; ?></b></li>
                <li><span class="mc-dot mc-dot--want"></span>想看 <b><?php echo (int) $stats['counts']['want']; ?></b></li>
                <li><span class="mc-dot mc-dot--favorited"></span>收藏 <b><?php echo (int) $stats['counts']['favorited']; ?></b></li>
                <li><span class="mc-dot mc-dot--dropped"></span>棄番 <b><?php echo (int) $stats['counts']['dropped']; ?></b></li>
                <li><span class="mc-dot mc-dot--paused"></span>暫停 <b><?php echo (int) ( $stats['counts']['paused'] ?? 0 ); ?></b></li>
            </ul>
            <div class="mc-watchtime">
                <span>累計觀看</span>
                <b><?php echo (int) $stats['watch_time']['days']; ?> 天</b>
                <small>（<?php echo number_format( (int) $stats['watch_time']['hours'] ); ?> 小時）</small>
            </div>
        </div>

        <div class="mc-widget mc-widget--plan">
            <h3>👤 我的方案</h3>
            <div class="mc-plan-card"><?php echo esc_html( $plan_label ); ?></div>
            <a href="<?php echo esc_url( home_url( '/sponsor/' ) ); ?>" class="mc-btn-upgrade">升級方案 →</a>
        </div>

        <div class="mc-widget mc-widget--comments">
            <h3>💬 最近留言</h3>
            <?php if ( $recent_cmt ): ?>
                <ul class="mc-cmt-list">
                    <?php foreach ( $recent_cmt as $c ): ?>
                        <li>
                            <a href="<?php echo esc_url( get_comment_link( $c ) ); ?>">
                                <b><?php echo esc_html( get_the_title( $c->comment_post_ID ) ); ?></b>
                                <p><?php echo esc_html( wp_trim_words( $c->comment_content, 20 ) ); ?></p>
                                <small><?php echo human_time_diff( strtotime( $c->comment_date ), current_time( 'timestamp' ) ); ?> 前</small>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="mc-empty">還沒留言</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $uid > 0 ): ?>
        <a href="<?php echo esc_url( home_url( '/year-review/?year=' . $current_year ) ); ?>" class="mc-yr-entry" aria-label="查看 <?php echo $current_year; ?> 年度回顧">
            <div class="mc-yr-entry-icon">✨</div>
            <div class="mc-yr-entry-body">
                <div class="mc-yr-entry-title">查看 <?php echo $current_year; ?> 年度回顧</div>
                <div class="mc-yr-entry-sub">看看這一年你和動畫的故事 →</div>
            </div>
            <div class="mc-yr-entry-arrow">›</div>
        </a>
    <?php endif; ?>

    <?php if ( ! empty( $activity ) ): ?>
        <div class="mc-widget mc-widget--timeline">
            <h3>📜 最近活動 <small>（最新 15 筆）</small></h3>
            <?php wxacg_render_activity_timeline( $activity ); ?>
        </div>
    <?php endif; ?>
    <?php
}

/* ===== 最近活動時間軸 ===== */
function wxacg_render_activity_timeline( $events ) {
    if ( empty( $events ) ) {
        echo '<p class="mc-empty">最近沒有活動，開始追番吧！</p>';
        return;
    }
    ?>
    <ul class="mc-timeline">
        <?php foreach ( $events as $e ):
            $color      = $e['color'] ?? 'accent';
            $link       = $e['link']  ?? '';
            $post_title = ! empty( $e['post_id'] ) ? get_the_title( $e['post_id'] ) : '';
        ?>
            <li class="mc-timeline-item mc-timeline-item--<?php echo esc_attr( $color ); ?>">
                <div class="mc-timeline-dot" aria-hidden="true">
                    <span class="mc-timeline-icon"><?php echo esc_html( $e['icon'] ?? '•' ); ?></span>
                </div>
                <div class="mc-timeline-body">
                    <div class="mc-timeline-main">
                        <span class="mc-timeline-action"><?php echo esc_html( $e['title'] ?? '' ); ?></span>
                        <?php if ( $post_title ): ?>
                            <?php if ( $link ): ?>
                                <a class="mc-timeline-target" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $post_title ); ?></a>
                            <?php else: ?>
                                <span class="mc-timeline-target"><?php echo esc_html( $post_title ); ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ( ! empty( $e['meta'] ) ): ?>
                            <span class="mc-timeline-meta"><?php echo esc_html( $e['meta'] ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="mc-timeline-time"><?php echo esc_html( $e['time_human'] ?? '—' ); ?></div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

/* ===== Tab 2：我的清單 ===== */
function smacg_render_watchlist( $watchlist, $counts ) {
    $first = array_slice( $watchlist, 0, SMACG_PAGE_SIZE );
    $total = count( $watchlist );
    ?>
    <div class="mc-list-toolbar">
        <div class="mc-filter-bar" role="tablist">
            <button class="mc-filter-btn active" data-filter="all">全部 (<?php echo (int) $counts['all']; ?>)</button>
            <button class="mc-filter-btn" data-filter="watching">追番中 (<?php echo (int) $counts['watching']; ?>)</button>
            <button class="mc-filter-btn" data-filter="completed">已看完 (<?php echo (int) $counts['completed']; ?>)</button>
            <button class="mc-filter-btn" data-filter="want">想看 (<?php echo (int) $counts['want']; ?>)</button>
            <button class="mc-filter-btn" data-filter="favorited">收藏 (<?php echo (int) $counts['favorited']; ?>)</button>
            <button class="mc-filter-btn" data-filter="paused">暫停 (<?php echo (int) ( $counts['paused'] ?? 0 ); ?>)</button>
            <button class="mc-filter-btn" data-filter="dropped">棄番 (<?php echo (int) $counts['dropped']; ?>)</button>
        </div>
        <div class="mc-list-tools">
            <input type="search" class="mc-search" placeholder="🔍 搜尋標題…" data-target="watchlist">
            <select class="mc-sort" data-target="watchlist">
                <option value="updated">最近更新</option>
                <option value="title">標題</option>
                <option value="progress">進度</option>
                <option value="year">年份</option>
            </select>
        </div>
    </div>

    <div class="mc-card-grid" id="mc-watchlist-grid">
        <?php foreach ( $first as $w ) wxacg_render_anime_card( $w['post_id'], $w ); ?>
    </div>

    <?php if ( $total > SMACG_PAGE_SIZE ): ?>
        <div class="mc-loadmore-wrap">
            <button class="mc-loadmore" data-type="watchlist" data-loaded="<?php echo count( $first ); ?>" data-total="<?php echo $total; ?>">
                載入更多（剩 <span><?php echo $total - count( $first ); ?></span>）
            </button>
        </div>
        <script type="application/json" id="mc-watchlist-data"><?php echo wp_json_encode( $watchlist ); ?></script>
    <?php endif; ?>
    <?php
}

/* ===== Tab 3：統計 ===== */
function smacg_render_stats( $s ) {
    $r = $s['rating'];
    $completion_rate = isset( $s['completion_rate'] ) ? (float) $s['completion_rate'] : null;
    ?>
    <div class="mc-stats-grid">

        <div class="mc-stats-banner">
            <div>
                <span class="mc-banner-label">你已經沉浸在動畫世界</span>
                <b class="mc-banner-num"><?php echo (int) $s['watch_time']['days']; ?></b>
                <span class="mc-banner-unit">天</span>
            </div>
            <div class="mc-banner-sub">
                共 <?php echo number_format( (int) $s['watch_time']['hours'] ); ?> 小時 ·
                追完 <?php echo (int) $s['counts']['completed']; ?> 部作品
            </div>
        </div>

        <?php if ( $completion_rate !== null ):
            // 暫停也是「開始了但還沒完成」，計入分母才不會讓完成率虛高
            $denom = (int) $s['counts']['completed'] + (int) $s['counts']['dropped']
                + (int) $s['counts']['watching'] + (int) ( $s['counts']['paused'] ?? 0 );
            [ $emoji, $remark ] = match ( true ) {
                $completion_rate >= 80 => [ '🏆', '超強毅力，幾乎部部追完！' ],
                $completion_rate >= 60 => [ '✨', '完成率不錯，繼續保持！' ],
                $completion_rate >= 40 => [ '👀', '加油，可以多看完幾部！' ],
                $completion_rate >= 20 => [ '🤔', '挑戰一下，把追番中的看完吧！' ],
                default                => [ '🌱', '剛開始，慢慢累積！' ],
            };
        ?>
            <div class="mc-stats-card mc-stats-card--completion">
                <h3><?php echo $emoji; ?> 完成率</h3>
                <div class="mc-completion-wrap">
                    <div class="mc-completion-big"><b><?php echo $completion_rate; ?></b><span>%</span></div>
                    <div class="mc-completion-bar">
                        <div class="mc-completion-fill" style="width:<?php echo min( 100, $completion_rate ); ?>%"></div>
                    </div>
                    <div class="mc-completion-meta">
                        已看完 <b><?php echo (int) $s['counts']['completed']; ?></b> /
                        計入 <b><?php echo $denom; ?></b> 部
                        <small>（含追番中、已看完、棄番）</small>
                    </div>
                    <p class="mc-completion-remark"><?php echo esc_html( $remark ); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="mc-stats-card">
            <h3>⭐ 評分分布</h3>
            <?php if ( $r['count'] ): ?>
                <div class="mc-stats-summary">
                    <div><b><?php echo $r['avg']; ?></b><span>平均分</span></div>
                    <div><b><?php echo $r['count']; ?></b><span>評分數</span></div>
                </div>
                <?php
                $max = max( $r['distribution'] ) ?: 1;
                for ( $i = 10; $i >= 1; $i-- ):
                    $c = (int) ( $r['distribution'][ $i ] ?? 0 );
                    $w = $c ? round( $c / $max * 100 ) : 0;
                ?>
                    <div class="mc-bar-row">
                        <span class="mc-bar-label"><?php echo $i; ?> 分</span>
                        <div class="mc-bar"><div class="mc-bar-fill" style="width:<?php echo $w; ?>%"></div></div>
                        <span class="mc-bar-count"><?php echo $c; ?></span>
                    </div>
                <?php endfor; ?>
            <?php else: ?>
                <p class="mc-empty">還沒給作品評分</p>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $s['genres'] ) ):
            $colors = [ '#ff6bae','#6bb6ff','#a78bfa','#fbbf24','#34d399','#f87171','#60a5fa','#c084fc' ];
            $stops = []; $acc = 0;
            foreach ( $s['genres'] as $i => $g ) {
                $start = $acc;
                $acc  += (int) $g['percent'];
                $stops[] = $colors[ $i % 8 ] . " {$start}% {$acc}%";
            }
            $pie = 'conic-gradient(' . implode( ',', $stops ) . ')';
        ?>
            <div class="mc-stats-card">
                <h3>🎭 類型偏好 <small>Top 8</small></h3>
                <div class="mc-pie-wrap">
                    <div class="mc-pie" style="background:<?php echo esc_attr( $pie ); ?>"></div>
                    <ul class="mc-pie-legend">
                        <?php foreach ( $s['genres'] as $i => $g ): ?>
                            <li>
                                <span class="mc-pie-dot" style="background:<?php echo $colors[ $i % 8 ]; ?>"></span>
                                <?php echo esc_html( $g['name'] ); ?>
                                <b><?php echo (int) $g['percent']; ?>%</b>
                                <small>（<?php echo (int) $g['count']; ?>）</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $s['studios'] ) ): ?>
            <div class="mc-stats-card">
                <h3>🏢 製作公司 <small>Top 5</small></h3>
                <ol class="mc-rank-list">
                    <?php foreach ( $s['studios'] as $i => $st ): ?>
                        <li>
                            <span class="mc-rank-num">#<?php echo $i + 1; ?></span>
                            <span class="mc-rank-name"><?php echo esc_html( $st['name'] ); ?></span>
                            <span class="mc-rank-count"><?php echo (int) $st['count']; ?> 部</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $s['years'] ) ):
            $ymax = max( array_column( $s['years'], 'count' ) ) ?: 1; ?>
            <div class="mc-stats-card">
                <h3>📅 年代分布</h3>
                <?php foreach ( $s['years'] as $y ): $w = round( $y['count'] / $ymax * 100 ); ?>
                    <div class="mc-bar-row">
                        <span class="mc-bar-label"><?php echo esc_html( $y['year'] ); ?></span>
                        <div class="mc-bar"><div class="mc-bar-fill mc-bar-fill--alt" style="width:<?php echo $w; ?>%"></div></div>
                        <span class="mc-bar-count"><?php echo (int) $y['count']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $r['top3'] ) ): ?>
            <div class="mc-stats-card">
                <h3>🏆 我給高分的作品</h3>
                <div class="mc-card-grid mc-card-grid--compact">
                    <?php foreach ( $r['top3'] as $row ) wxacg_render_anime_card( $row['post_id'], [ 'user_score' => $row['score'] ] ); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $r['bottom3'] ) && count( $r['bottom3'] ) >= 3 ): ?>
            <div class="mc-stats-card">
                <h3>👀 我給低分的作品</h3>
                <div class="mc-card-grid mc-card-grid--compact">
                    <?php foreach ( $r['bottom3'] as $row ) wxacg_render_anime_card( $row['post_id'], [ 'user_score' => $row['score'] ] ); ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
    <?php
}

/* ===== Tab 4：評分 ===== */
function smacg_render_ratings( $ratings, $rating_stats ) {
    $total = count( $ratings );
    $first = array_slice( $ratings, 0, SMACG_PAGE_SIZE );

    if ( ! $total ) {
        echo '<p class="mc-empty">還沒給任何作品評分</p>';
        return;
    }
    ?>
    <div class="mc-rating-summary">
        <div><b><?php echo $rating_stats['avg']; ?></b><span>平均分</span></div>
        <div><b><?php echo $total; ?></b><span>已評分</span></div>
    </div>

    <div class="mc-list-tools">
        <input type="search" class="mc-search" placeholder="🔍 搜尋標題…" data-target="ratings">
        <select class="mc-sort" data-target="ratings">
            <option value="updated">最近評分</option>
            <option value="score-desc">分數高 → 低</option>
            <option value="score-asc">分數低 → 高</option>
        </select>
    </div>

    <div class="mc-card-grid" id="mc-ratings-grid">
        <?php foreach ( $first as $r ) wxacg_render_anime_card( (int) $r['anime_id'], [ 'user_score' => (float) $r['overall_score'] ] ); ?>
    </div>

    <?php if ( $total > SMACG_PAGE_SIZE ): ?>
        <div class="mc-loadmore-wrap">
            <button class="mc-loadmore" data-type="ratings" data-loaded="<?php echo count( $first ); ?>" data-total="<?php echo $total; ?>">
                載入更多（剩 <span><?php echo $total - count( $first ); ?></span>）
            </button>
        </div>
        <script type="application/json" id="mc-ratings-data"><?php echo wp_json_encode( $ratings ); ?></script>
    <?php endif; ?>
    <?php
}

/* ===== Tab 5：留言 ===== */
function smacg_render_comments( $uid ) {
    $uid = (int) $uid;
    if ( $uid <= 0 ) { echo '<p class="mc-empty">尚無留言</p>'; return; }

    $total = (int) get_comments( [ 'user_id' => $uid, 'status' => 'approve', 'count' => true ] );
    if ( ! $total ) { echo '<p class="mc-empty">尚無留言</p>'; return; }

    $cmts = get_comments( [
        'user_id' => $uid,
        'status'  => 'approve',
        'number'  => SMACG_COMMENT_PAGE_SIZE,
        'offset'  => 0,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
    ] );

    $loaded = count( $cmts );
    $nonce  = wp_create_nonce( 'smacg_load_more_comments' );
    ?>
    <div class="mc-comments-wrap">
        <div class="mc-comments-meta">共 <b><?php echo $total; ?></b> 則留言</div>

        <ul class="mc-cmt-fulllist" id="mc-cmt-list">
            <?php foreach ( $cmts as $c ): ?>
                <li>
                    <a href="<?php echo esc_url( get_comment_link( $c ) ); ?>">
                        <b><?php echo esc_html( get_the_title( $c->comment_post_ID ) ); ?></b>
                        <p><?php echo esc_html( wp_trim_words( $c->comment_content, 40 ) ); ?></p>
                        <small><?php echo esc_html( mysql2date( 'Y-m-d H:i', $c->comment_date ) ); ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ( $total > $loaded ): ?>
            <div class="mc-loadmore-wrap">
                <button type="button" class="mc-loadmore mc-loadmore-comments"
                        data-loaded="<?php echo $loaded; ?>"
                        data-total="<?php echo $total; ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    載入更多（剩 <span><?php echo $total - $loaded; ?></span>）
                </button>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ===== Tab 6：點數 / EXP（v1.3.2 — 修正 next_tier 資料來源）===== */
function smacg_render_points( $points = null, $lvl = null, $log = null ) {
    $uid = (int) ( get_query_var( 'smacg_view_user' ) ?: get_current_user_id() );
    if ( $uid <= 0 ) { echo '<p class="mc-empty">請先登入</p>'; return; }

    $info = function_exists( 'wxacg_get_user_level_info' ) ? wxacg_get_user_level_info( $uid ) : [];
    $log  = function_exists( 'smacg_get_exp_log' )         ? smacg_get_exp_log( $uid, 50 )    : [];

    // 缺鍵防護：補上預設值
    $info = array_merge( [
        'exp'             => 0,
        'level'           => 1,
        'title'           => '見習',
        'icon'            => '🌱',
        'percent'         => 0,
        'in_level_exp'    => 0,
        'level_total_exp' => 0,
        'to_next'         => 0,
        'is_max'          => false,
        'next_floor'      => 0,
    ], is_array( $info ) ? $info : [] );

    $max_level = defined( 'WXACG_MAX_LEVEL' ) ? WXACG_MAX_LEVEL : 200;
    $is_max    = ! empty( $info['is_max'] );

    // v1.3.2：段位資料（賽季積分）— 直接走 Rank_Season::get_user_info 的結構
    $rank_info = function_exists( 'smacg_get_user_rank_season_info' )
        ? smacg_get_user_rank_season_info( $uid )
        : null;

    // 缺鍵防護（對齊 Rank_Season::get_user_info 的回傳結構）
    $rank_info = array_merge( [
        'score'         => 0,
        'rank'          => 0,
        'season_code'   => '',
        'season_label'  => '本季',
        'tier'          => [
            'key'   => 'iron',
            'label' => '鐵 IV',
            'icon'  => '🥉',
            'color' => '#6b6b6b',
            'min'   => 0,
        ],
        'progress'      => [
            'is_max'     => false,
            'cur_min'    => 0,
            'next_min'   => 100,
            'to_next'    => 100,
            'percent'    => 0,
            'next_label' => '鐵 III',
        ],
    ], is_array( $rank_info ) ? $rank_info : [] );

    $rank_score  = (int) ( $rank_info['score'] ?? 0 );
    $rank_tier   = is_array( $rank_info['tier'] ?? null ) ? $rank_info['tier'] : [];
    $tier_label  = $rank_tier['label'] ?? '未定段';
    $tier_icon   = $rank_tier['icon']  ?? '🎖️';
    $tier_color  = $rank_tier['color'] ?? '#9b7dff';
    $position    = (int) ( $rank_info['rank'] ?? 0 );  // ← Rank_Season 用 'rank' 不是 'position'
    $season_lbl  = $rank_info['season_label'] ?? '本季';

    // ── 從 progress 子陣列讀進度資料 ──
    $prog          = is_array( $rank_info['progress'] ?? null ) ? $rank_info['progress'] : [];
    $is_apex       = ! empty( $prog['is_max'] );
    $to_next_pts   = (int) ( $prog['to_next'] ?? 0 );
    $tier_progress = (int) ( $prog['percent'] ?? 0 );
    $next_label    = (string) ( $prog['next_label'] ?? '' );
    $cur_min       = (int) ( $prog['cur_min'] ?? 0 );
    $next_min      = (int) ( $prog['next_min'] ?? 0 );
    ?>

    <?php /* ====== v1.3.2：點數雙卡（EXP + 賽季分）====== */ ?>
    <div class="mc-points-dual">

        <?php /* ─── 左卡：等級 / EXP（永久累積） ─── */ ?>
        <div class="smacg-level-summary mc-points-card mc-points-card--exp <?php echo $is_max ? 'is-max' : ''; ?>">
            <div class="mc-points-card-head">
                <span class="mc-points-card-tag mc-points-card-tag--exp">永久累積</span>
                <span class="mc-points-card-title">⚡ 等級 / EXP</span>
            </div>

            <div class="smacg-level-header">
                <div class="smacg-level-icon"><?php echo esc_html( $info['icon'] ); ?></div>
                <div class="smacg-level-meta">
                    <div class="lvl-number">Lv.<?php echo (int) $info['level']; ?> <small>/ <?php echo (int) $max_level; ?></small></div>
                    <div class="lvl-title"><?php echo esc_html( $info['title'] ); ?></div>
                </div>
                <div class="smacg-level-exp">
                    <div class="exp-num"><?php echo number_format( (int) $info['exp'] ); ?></div>
                    <div class="exp-label">EXP</div>
                </div>
            </div>

            <div class="smacg-level-bar">
                <div class="smacg-level-bar-fill" style="width:<?php echo (int) $info['percent']; ?>%"></div>
            </div>

            <div class="smacg-level-progress-text">
                <?php if ( ! empty( $info['is_max'] ) ): ?>
                    <span>🎉 已達最高等級！</span>
                    <span class="mc-progress-pct"><?php echo number_format( (int) $info['exp'] ); ?> EXP</span>
                <?php else: ?>
                    <span>距離 Lv.<?php echo (int) $info['level'] + 1; ?> 還差 <strong><?php echo number_format( (int) $info['to_next'] ); ?></strong> EXP</span>
                    <span class="mc-progress-pct"><?php echo (int) $info['percent']; ?>%</span>
                <?php endif; ?>
            </div>

            <p class="mc-points-card-foot">
                <a href="<?php echo esc_url( home_url( '/level-guide/#exp' ) ); ?>">查看 EXP 取得方式 →</a>
            </p>
        </div>

        <?php /* ─── 右卡：段位 / 賽季積分（每季重置） ─── */ ?>
        <div class="smacg-rank-summary mc-points-card mc-points-card--rank <?php echo $is_apex ? 'is-apex' : ''; ?>"
             style="--rank-color: <?php echo esc_attr( $tier_color ); ?>;">
            <div class="mc-points-card-head">
                <span class="mc-points-card-tag mc-points-card-tag--rank">每季重置</span>
                <span class="mc-points-card-title">🏆 段位 / 賽季分（<?php echo esc_html( $season_lbl ); ?>）</span>
            </div>

            <div class="smacg-rank-header">
                <div class="smacg-rank-icon"><?php echo esc_html( $tier_icon ); ?></div>
                <div class="smacg-rank-meta">
                    <div class="rank-tier-label"><?php echo esc_html( $tier_label ); ?></div>
                    <div class="rank-position">
                        <?php if ( $position > 0 ): ?>
                            排行 <b>#<?php echo number_format( $position ); ?></b>
                        <?php else: ?>
                            <small>本季尚未上榜</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="smacg-rank-score">
                    <div class="rank-score-num"><?php echo number_format( $rank_score ); ?></div>
                    <div class="rank-score-label">賽季分</div>
                </div>
            </div>

            <div class="smacg-rank-bar">
                <div class="smacg-rank-bar-fill" style="width:<?php echo (int) $tier_progress; ?>%;"></div>
            </div>

            <div class="smacg-rank-progress-text">
                <?php if ( $is_apex ): ?>
                    <span>🔥 已達頂峰段位，繼續守榜！</span>
                    <span class="mc-progress-pct"><?php echo number_format( $rank_score ); ?> 分</span>
                <?php elseif ( $to_next_pts > 0 && $next_label !== '' ): ?>
                    <span>距離 <b><?php echo esc_html( $next_label ); ?></b> 還差 <strong><?php echo number_format( $to_next_pts ); ?></strong> 分</span>
                    <span class="mc-progress-pct"><?php echo (int) $tier_progress; ?>%</span>
                <?php else: ?>
                    <span>多參與互動累積賽季分！</span>
                    <span class="mc-progress-pct"><?php echo number_format( $rank_score ); ?> 分</span>
                <?php endif; ?>
            </div>

            <p class="mc-points-card-foot">
                <a href="<?php echo esc_url( home_url( '/level-guide/#season-score' ) ); ?>">查看段位積分來源 →</a>
                <span class="mc-points-card-sep">·</span>
                <a href="<?php echo esc_url( home_url( '/ranking-users/?tab=rank_season' ) ); ?>">查看排行榜 →</a>
            </p>
        </div>
    </div>

    <h3 class="mc-section-title">📜 最近 50 筆 EXP 紀錄</h3>
    <p class="mc-section-hint">下表僅顯示 EXP 變動。賽季積分變動詳見排行榜頁。</p>

    <?php if ( $log ): ?>
        <table class="mc-points-table">
            <thead><tr><th>時間</th><th>變動</th><th>原因</th></tr></thead>
            <tbody>
                <?php foreach ( $log as $row ):
                    $v   = (int) ( $row['change_value'] ?? 0 );
                    $cls = $v >= 0 ? 'pos' : 'neg';
                ?>
                    <tr>
                        <td data-label="時間"><?php echo esc_html( $row['created_at'] ?? '' ); ?></td>
                        <td data-label="變動" class="mc-pt-<?php echo $cls; ?>"><?php echo ( $v >= 0 ? '+' : '' ) . number_format( $v ); ?></td>
                        <td data-label="原因"><?php echo esc_html( $row['reason'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="mc-empty">尚無 EXP 紀錄。多多參與活動就能累積經驗值！</p>
    <?php endif; ?>
    <?php
}

/* ===== Tab：🏆 徽章 ===== */
function smacg_render_badges( $uid ) {
    $uid = (int) $uid;
    if ( $uid <= 0 ) { echo '<p class="mc-empty">請先登入</p>'; return; }

    if ( ! function_exists( 'smacg_gamipress_active' ) || ! smacg_gamipress_active() ) {
        echo '<div class="mc-empty"><p>GamiPress 尚未啟用，徽章功能無法顯示。</p><p><small>請聯絡管理員設定。</small></p></div>';
        return;
    }

    $badge_slug = defined( 'WXACG_BADGE_SLUG' ) ? WXACG_BADGE_SLUG : 'badge';
    $all_badges = get_posts( [
        'post_type'      => $badge_slug,
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ] );

    if ( empty( $all_badges ) ) {
        echo '<div class="mc-empty"><p>目前還沒有任何徽章。</p>';
        if ( current_user_can( 'manage_options' ) ) {
            echo '<p><small>管理員可至 <a href="' . esc_url( admin_url( 'edit.php?post_type=' . $badge_slug ) ) . '">GamiPress 後台</a> 建立徽章。</small></p>';
        }
        echo '</div>';
        return;
    }

    $earned_ids = function_exists( 'smacg_get_user_badge_ids' ) ? smacg_get_user_badge_ids( $uid ) : [];
    $earned_map = array_flip( $earned_ids );
    $total      = count( $all_badges );
    $unlocked   = count( $earned_ids );
    $percent    = $total > 0 ? round( $unlocked / $total * 100 ) : 0;
    ?>
    <div class="mc-badges-wrap">
        <div class="mc-badges-summary">
            <div class="mc-badges-summary-num"><b><?php echo $unlocked; ?></b><span>/ <?php echo $total; ?></span></div>
            <div class="mc-badges-summary-bar">
                <div class="mc-badges-summary-bar-fill" style="width:<?php echo $percent; ?>%"></div>
            </div>
            <p class="mc-badges-summary-text">已解鎖 <?php echo $percent; ?>%</p>
        </div>

        <div class="mc-badges-grid">
            <?php foreach ( $all_badges as $badge ):
                $is_unlocked = isset( $earned_map[ $badge->ID ] );
                $thumb       = get_the_post_thumbnail_url( $badge->ID, 'thumbnail' );
                $excerpt     = mb_strimwidth( wp_strip_all_tags( $badge->post_excerpt ?: $badge->post_content ), 0, 60, '…' );
            ?>
                <div class="mc-badge-card <?php echo $is_unlocked ? 'is-unlocked' : 'is-locked'; ?>">
                    <div class="mc-badge-icon">
                        <?php if ( $thumb ): ?>
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $badge->post_title ); ?>" loading="lazy">
                        <?php else:
                            $badge_icon = function_exists( 'wxacg_get_achievement_icon' )
                                ? wxacg_get_achievement_icon( $badge->post_name )
                                : 'fa-solid fa-trophy';
                        ?>
                            <i class="<?php echo esc_attr( $badge_icon ); ?>"></i>
                        <?php endif; ?>
                        <?php if ( ! $is_unlocked ): ?>
                            <span class="mc-badge-lock"><i class="fa-solid fa-lock"></i></span>
                        <?php endif; ?>
                    </div>
                    <div class="mc-badge-info">
                        <h4 class="mc-badge-title"><?php echo esc_html( $badge->post_title ); ?></h4>
                        <?php if ( $excerpt ): ?><p class="mc-badge-desc"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/* ===== Tab：🎯 職業（與 v2.0.0 相同，未動）===== */
function smacg_render_career( $uid, $lvl_info = null, $job_title = null ) {
    $uid = (int) $uid;
    if ( $uid <= 0 ) { echo '<p class="mc-empty">請先登入</p>'; return; }

    if ( ! is_array( $lvl_info ) && function_exists( 'wxacg_get_user_level_info' ) ) {
        $lvl_info = wxacg_get_user_level_info( $uid );
    }
    if ( ! is_array( $job_title ) && function_exists( 'smacg_get_user_job_title' ) ) {
        $job_title = smacg_get_user_job_title( $uid );
    }
    $lvl_info  = is_array( $lvl_info )  ? $lvl_info  : [ 'level' => 1, 'exp' => 0 ];
    $job_title = is_array( $job_title ) ? $job_title : [];

    $level = (int) ( $lvl_info['level'] ?? 1 );
    $exp   = (int) ( $lvl_info['exp']   ?? 0 );

    if ( $level < 10 ) {
        $exp_needed = max( 0, 500 - $exp );
        $percent    = $exp >= 500 ? 100 : (int) floor( $exp / 500 * 100 );
        ?>
        <div class="mc-career-locked">
            <div class="mc-career-locked-icon">🔒</div>
            <h3>職業系統 Lv.10 解鎖</h3>
            <p>目前 Lv.<?php echo $level; ?>，距離一轉還需 <b><?php echo number_format( $exp_needed ); ?> EXP</b></p>
            <div class="mc-career-locked-bar">
                <div class="mc-career-locked-fill" style="width:<?php echo $percent; ?>%"></div>
            </div>
            <p class="mc-career-locked-hint">繼續觀看動畫、寫評論、追蹤朋友來累積 EXP！</p>
            <p class="mc-career-locked-link">
                <a href="<?php echo esc_url( home_url( '/level-guide/#career' ) ); ?>">查看 8 大職業介紹 →</a>
            </p>
        </div>
        <?php
        return;
    }

    if ( ! function_exists( 'smacg_get_jobs' ) ) {
        echo '<div class="mc-empty"><p>職業系統尚未載入，請聯絡管理員</p></div>';
        return;
    }

    $jobs         = smacg_get_jobs();
    $current_job  = function_exists( 'smacg_get_user_job' )         ? smacg_get_user_job( $uid )       : '';
    $career_stage = function_exists( 'smacg_get_career_stage' )     ? smacg_get_career_stage( $level ) : 0;
    $milestones   = function_exists( 'smacg_get_career_milestones' )? smacg_get_career_milestones()   : [];

    if ( ! $current_job ) {
        ?>
        <div class="mc-career-choose" data-mode="select">
            <h2>🎯 選擇你的職業</h2>
            <p class="mc-career-intro">
                恭喜達成 Lv.10！請從下列 8 種職業中選擇一個作為你的天命之路。
                <br><small>※ 選擇後 <b>3 個月內無法變更</b>，每次升級會自動進化稱號。</small>
            </p>

            <div class="mc-career-grid">
                <?php foreach ( $jobs as $key => $job ): ?>
                    <button class="mc-career-card"
                            data-job-key="<?php echo esc_attr( $key ); ?>"
                            data-job-label="<?php echo esc_attr( $job['label'] ); ?>"
                            type="button"
                            aria-label="選擇職業：<?php echo esc_attr( $job['label'] ); ?>">
                        <div class="mc-career-card-icon"><?php echo esc_html( $job['icon'] ); ?></div>
                        <h4 class="mc-career-card-name"><?php echo esc_html( $job['label'] ); ?></h4>
                        <?php if ( ! empty( $job['desc'] ) ): ?>
                            <p class="mc-career-card-desc"><?php echo esc_html( $job['desc'] ); ?></p>
                        <?php endif; ?>
                        <ul class="mc-career-card-path">
                            <?php foreach ( $job['titles'] as $stage => $t ):
                                $milestone_lv = (int) ( $milestones[ $stage ]['level'] ?? 0 ); ?>
                                <li><small>Lv.<?php echo $milestone_lv; ?></small> <?php echo esc_html( $t['name'] ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <span class="mc-career-card-pick">選擇此職業 →</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <p class="mc-career-note">
                <i class="fa-solid fa-circle-info"></i>
                看不到適合的？先逛逛 <a href="<?php echo esc_url( home_url( '/level-guide/#career' ) ); ?>">完整職業介紹</a>，再決定。
            </p>
        </div>

        <div class="mc-career-confirm" id="mc-career-confirm" hidden role="dialog" aria-modal="true" aria-labelledby="mc-career-confirm-title">
            <div class="mc-career-confirm-inner">
                <h3 id="mc-career-confirm-title">確認選擇？</h3>
                <p>你即將選擇職業：<b id="mc-career-confirm-label"></b></p>
                <p class="mc-career-confirm-warn">⚠ 選擇後 3 個月內無法變更，請慎重考慮。</p>
                <div class="mc-career-confirm-actions">
                    <button type="button" class="mc-btn-secondary" data-action="cancel">再想想</button>
                    <button type="button" class="mc-btn-primary"   data-action="confirm">確認選擇</button>
                </div>
                <p class="mc-career-confirm-msg" id="mc-career-confirm-msg" hidden></p>
            </div>
        </div>
        <?php
        return;
    }

    if ( ! isset( $jobs[ $current_job ] ) ) {
        echo '<div class="mc-empty"><p>職業資料異常，請聯絡管理員</p></div>';
        return;
    }

    $job        = $jobs[ $current_job ];
    $chosen_at  = get_user_meta( $uid, 'smacg_job_chosen_at', true );
    $chosen_ts  = $chosen_at ? strtotime( $chosen_at ) : 0;

    $cooldown_end = $chosen_ts ? ( $chosen_ts + 3 * MONTH_IN_SECONDS ) : 0;
    $can_change   = $cooldown_end > 0 && time() >= $cooldown_end;
    $remain_days  = $can_change ? 0 : (int) ceil( max( 0, $cooldown_end - time() ) / DAY_IN_SECONDS );
    ?>
    <div class="mc-career-view" data-mode="view" data-current-job="<?php echo esc_attr( $current_job ); ?>">
        <div class="mc-career-current">
            <div class="mc-career-current-icon"><?php echo esc_html( $job['icon'] ); ?></div>
            <div class="mc-career-current-body">
                <h2><?php echo esc_html( $job['label'] ); ?></h2>
                <?php if ( ! empty( $job_title ) ): ?>
                    <p class="mc-career-current-title">
                        當前稱號：<b><?php echo esc_html( $job_title['title_name'] ?? '' ); ?></b>
                        <?php if ( ! empty( $job_title['title_ref'] ) ): ?>
                            <small>（動漫梗：<?php echo esc_html( $job_title['title_ref'] ); ?>）</small>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ( $chosen_at ): ?>
                    <p class="mc-career-current-meta">
                        <small>
                            選擇於 <?php echo esc_html( mysql2date( 'Y-m-d', $chosen_at ) ); ?>
                            <?php if ( $can_change ): ?>
                                · <span class="mc-career-can-change">可變更職業</span>
                            <?php else: ?>
                                · 距離可變更還有 <b><?php echo $remain_days; ?> 天</b>
                            <?php endif; ?>
                        </small>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <h3 class="mc-career-path-title">🎬 進化路線</h3>
        <ol class="mc-career-path">
            <?php
            // 起點：Lv.1，顯示帳號註冊日期（不算職業任何一轉，純粹當作路線的起始標記）
            $reg_date_str = '';
            $user_data    = get_userdata( $uid );
            if ( $user_data && $user_data->user_registered ) {
                $reg_date_str = mysql2date( 'Y-m-d', $user_data->user_registered );
            }
            ?>
            <li class="mc-career-path-item is-done">
                <div class="mc-career-path-marker" aria-hidden="true">
                    <i class="fa-solid fa-check"></i>
                </div>
                <div class="mc-career-path-body">
                    <div class="mc-career-path-stage">🌱 起點 · Lv.1</div>
                    <div class="mc-career-path-name">新進會員</div>
                    <?php if ( $reg_date_str ): ?>
                        <div class="mc-career-path-ref"><small>註冊於 <?php echo esc_html( $reg_date_str ); ?></small></div>
                    <?php endif; ?>
                </div>
            </li>

            <?php foreach ( $job['titles'] as $stage => $t ):
                $milestone    = $milestones[ $stage ] ?? [ 'level' => 0, 'icon' => '•', 'label' => '' ];
                $milestone_lv = (int) $milestone['level'];
                $reached      = $career_stage >= $stage;
                $current      = $career_stage === $stage;
                $cls          = $reached ? ( $current ? 'is-current' : 'is-done' ) : 'is-locked';
            ?>
                <li class="mc-career-path-item <?php echo $cls; ?>">
                    <div class="mc-career-path-marker" aria-hidden="true">
                        <i class="fa-solid fa-<?php echo $reached ? 'check' : 'lock'; ?>"></i>
                    </div>
                    <div class="mc-career-path-body">
                        <div class="mc-career-path-stage">
                            <?php echo esc_html( $milestone['icon'] ); ?>
                            <?php echo (int) $stage; ?> 轉 · Lv.<?php echo $milestone_lv; ?>
                            <?php if ( $current ): ?>
                                <span class="mc-career-path-now">當前</span>
                            <?php endif; ?>
                        </div>
                        <div class="mc-career-path-name"><?php echo esc_html( $t['name'] ); ?></div>
                        <div class="mc-career-path-ref"><small>動漫梗：<?php echo esc_html( $t['ref'] ); ?></small></div>
                    </div>
                </li>
            <?php endforeach; ?>

            <?php
            // 終點：Lv.200，職業轉階最高只到 5 轉（Lv.170），這裡是整條路線的封頂標記
            $graduated = $level >= 200;
            $end_cls   = $graduated ? 'is-done' : 'is-locked';
            ?>
            <li class="mc-career-path-item <?php echo $end_cls; ?>">
                <div class="mc-career-path-marker" aria-hidden="true">
                    <i class="fa-solid fa-<?php echo $graduated ? 'check' : 'lock'; ?>"></i>
                </div>
                <div class="mc-career-path-body">
                    <div class="mc-career-path-stage">💎 終點 · Lv.200</div>
                    <div class="mc-career-path-name">畢業五轉</div>
                </div>
            </li>
        </ol>

        <?php if ( $can_change ): ?>
            <div class="mc-career-change">
                <p class="mc-career-change-hint">已超過 3 個月冷卻期，可變更職業：</p>
                <button type="button" class="mc-btn-secondary" id="mc-career-change-btn" data-action="change">變更職業 →</button>
            </div>
        <?php endif; ?>
    </div>
    <?php
}


/* ===== Tab 7：設定（v1.2.0 — 與你原本檔案完全相同）===== */
function smacg_render_settings( $user, $privacy = null, $is_owner = true ) {
    if ( $privacy === null && function_exists( 'smacg_get_user_privacy' ) ) {
        $privacy = smacg_get_user_privacy( $user->ID );
    }

    $fallback_defaults = function_exists( 'smacg_get_user_privacy_defaults' )
        ? smacg_get_user_privacy_defaults()
        : [
            'show_email'             => '0',
            'public_profile'         => '1',
            'public_watchlist'       => '1',
            'show_continue_watching' => '1',
        ];

    $privacy = wp_parse_args( (array) $privacy, $fallback_defaults );

    $nonce = wp_create_nonce( 'smacg_privacy' );

    $birthday       = (string) get_user_meta( $user->ID, 'smacg_birthday', true );
    $birthday_nonce = wp_create_nonce( 'smacg_update_birthday' );
    $birthday_set   = (bool) get_user_meta( $user->ID, 'smacg_exp_once_profile_birthday', true );

    $toggles = [
        'show_email'             => [ '公開 Email',       '關閉時其他人看到的 email 會遮罩為 a***@example.com' ],
        'public_profile'         => [ '公開個人頁',       '關閉後只有你本人能瀏覽此頁面' ],
        'public_watchlist'       => [ '公開追番列表',     '關閉後其他用戶看不到你的觀看記錄' ],
        'show_continue_watching' => [ '顯示「繼續觀看」', '關閉後會員頁不再顯示頂部的橫向追番列' ],
    ];
    ?>
    <div class="mc-settings-grid">

        <div class="mc-set-card">
            <h3 class="mc-set-title"><i class="fa-solid fa-id-card"></i> 基本資料</h3>
            <form id="mc-profile-form" class="mc-set-form">
                <?php wp_nonce_field( 'smacg_update_profile', 'smacg_profile_nonce' ); ?>
                <label class="mc-set-label">
                    <span>顯示名稱</span>
                    <input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" maxlength="40" required>
                </label>
                <label class="mc-set-label">
                    <span>個人簡介</span>
                    <textarea name="description" rows="3" maxlength="300" placeholder="跟其他用戶介紹一下自己…"><?php echo esc_textarea( $user->description ); ?></textarea>
                </label>
                <div class="mc-set-actions">
                    <button type="submit" class="mc-btn-primary">儲存變更</button>
                    <span id="mc-profile-msg" class="mc-set-msg" style="display:none"></span>
                </div>
            </form>

            <form id="mc-birthday-form" class="mc-set-form mc-set-form--birthday" data-nonce="<?php echo esc_attr( $birthday_nonce ); ?>" style="margin-top:1.2rem;border-top:1px dashed var(--mc-border,#e5e7eb);padding-top:1.2rem;">
                <label class="mc-set-label">
                    <span>🎂 生日 <small><?php echo $birthday_set ? '（已領取 +10 EXP）' : '（首次設定可領 +10 EXP）'; ?></small></span>
                    <input type="date"
                           name="birthday"
                           id="mc-birthday-input"
                           value="<?php echo esc_attr( $birthday ); ?>"
                           min="1900-01-01"
                           max="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
                           autocomplete="bday">
                </label>
                <p class="mc-set-hint" style="margin:-.4rem 0 .6rem;font-size:.85em;color:var(--mc-muted,#6b7280);">
                    🎁 設定生日後，每年生日當天可獲得 <b>+50 EXP</b> 生日禮金，並有機會解鎖生日專屬徽章。
                    <br><small>※ 生日不會公開顯示，僅用於發送生日獎勵。</small>
                </p>
                <div class="mc-set-actions">
                    <button type="submit" class="mc-btn-primary">儲存生日</button>
                    <span id="mc-birthday-msg" class="mc-set-msg" style="display:none"></span>
                </div>
            </form>
        </div>

        <div class="mc-set-card">
            <h3 class="mc-set-title"><i class="fa-solid fa-user-shield"></i> 隱私 & 顯示</h3>
            <div class="mc-privacy-form" data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php foreach ( $toggles as $key => [ $name, $desc ] ): ?>
                    <div class="mc-toggle-row">
                        <div class="mc-toggle-info">
                            <div class="mc-toggle-name"><?php echo esc_html( $name ); ?></div>
                            <div class="mc-toggle-desc"><?php echo esc_html( $desc ); ?></div>
                        </div>
                        <label class="mc-toggle">
                            <input type="checkbox" data-key="<?php echo esc_attr( $key ); ?>" <?php checked( $privacy[ $key ], 1 ); ?>>
                            <span class="mc-toggle-slider"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div id="mc-privacy-msg" class="mc-set-msg" style="display:none"></div>
            </div>
        </div>

        <?php wxacg_render_notification_prefs_card( $user->ID ); ?>

        <div class="mc-set-card">
            <h3 class="mc-set-title"><i class="fa-solid fa-key"></i> 帳號安全</h3>
            <p class="mc-set-hint">若需變更密碼，請點下方按鈕，系統會寄送重設信件至你的 email。</p>
            <div class="mc-set-actions">
                <a class="mc-btn-secondary mc-settings-reset-pwd" href="<?php echo esc_url( home_url( '/password-reset/' ) ); ?>">
                    <i class="fa-solid fa-rotate"></i> 重設密碼
                </a>
            </div>
        </div>

        <div class="mc-set-card mc-set-card--danger">
            <h3 class="mc-set-title"><i class="fa-solid fa-right-from-bracket"></i> 結束工作階段</h3>
            <p class="mc-set-hint">登出後將回到網站首頁。</p>
            <div class="mc-set-actions">
                <a class="mc-btn-danger" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> 登出帳號
                </a>
            </div>
        </div>

    </div>

    <script>
    (function(){
        var form = document.getElementById('mc-birthday-form');
        if (!form) return;
        var input = document.getElementById('mc-birthday-input');
        var msg   = document.getElementById('mc-birthday-msg');
        var ajax  = (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';

        function showMsg(text, ok){
            if (!msg) return;
            msg.textContent = text;
            msg.style.display = 'inline-block';
            msg.style.color = ok ? '#16a34a' : '#dc2626';
            if (ok) setTimeout(function(){ msg.style.display = 'none'; }, 4000);
        }

        form.addEventListener('submit', function(e){
            e.preventDefault();
            if (!input || !input.value) {
                showMsg('請選擇生日日期', false);
                return;
            }
            var fd = new FormData();
            fd.append('action',   'smacg_update_birthday');
            fd.append('nonce',    form.getAttribute('data-nonce') || '');
            fd.append('birthday', input.value);

            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.textContent = '儲存中…'; }

            fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    if (json && json.success) {
                        showMsg('✓ 生日已儲存', true);
                    } else {
                        var m = (json && json.data && json.data.msg) ? json.data.msg : '儲存失敗，請稍後再試';
                        showMsg('✗ ' + m, false);
                    }
                })
                .catch(function(){
                    showMsg('✗ 網路錯誤，請稍後再試', false);
                })
                .finally(function(){
                    if (btn) { btn.disabled = false; btn.textContent = '儲存生日'; }
                });
        });
    })();
    </script>
    <?php
}

/* ===== 通知偏好卡片（v1.1.0 — 與你原本檔案完全相同）===== */
function wxacg_render_notification_prefs_card( $uid ) {
    $uid = (int) $uid;
    if ( $uid <= 0 ) return;

    $fallback_defaults = function_exists( 'smacg_get_notification_prefs_defaults' )
        ? smacg_get_notification_prefs_defaults()
        : [
            'follow_site'         => 1, 'follow_email'        => 0,
            'comment_reply_site'  => 1, 'comment_reply_email' => 0,
            'rating_site'         => 1, 'rating_email'        => 0,
            'level_up_site'       => 1, 'level_up_email'      => 0,
            'badge_site'          => 1, 'badge_email'         => 0,
            'system_site'         => 1, 'system_email'        => 0,
            'email_digest'        => 'off',
        ];

    $prefs = function_exists( 'smacg_get_notification_prefs' )
        ? smacg_get_notification_prefs( $uid )
        : ( get_user_meta( $uid, 'smacg_notification_prefs', true ) ?: [] );
    if ( ! is_array( $prefs ) ) $prefs = [];

    $prefs = wp_parse_args( $prefs, $fallback_defaults );
    $nonce = wp_create_nonce( 'smacg_notif_save_prefs' );

    /*
     * 顯示哪些開關由 wxacg-social 的 legacy/notification-types.php 決定，
     * 與 smacg_get_notification_prefs_defaults() 同一份來源。
     *
     * 這裡原本是寫死的第二份清單，跟 defaults 各自維護：只加 defaults
     * 會員就看不到也關不掉，只加這裡通知則會靜默不送。兩種都發生過。
     *
     * wxacg-social 的 plugins_loaded 優先序是 12、本外掛是 10，載入時
     * 取不到函式；但本函式是在頁面渲染時才呼叫，那時已經載入完成。
     * 仍保留 function_exists 防護，缺少時退回舊的六項寫死清單。
     */
    $types = [];

    if ( function_exists( 'wxacg_notification_types_configurable' ) ) {
        foreach ( wxacg_notification_types_configurable() as $key => $t ) {
            $types[ $key ] = [ $t['icon'] ?? '🔔', $t['name'] ?? $key, $t['desc'] ?? '' ];
        }
    } else {
        $types = [
            'follow'        => [ '👥', '有人追蹤我',          '當有用戶開始追蹤你時通知' ],
            'comment_reply' => [ '💬', '留言被回覆',          '當有人回覆你的留言時通知' ],
            'rating'        => [ '⭐', '收藏的動畫有人評分',  '當你收藏的作品收到新評分時通知' ],
            'level_up'      => [ '🎖', '等級提升',            '當你升級時通知' ],
            'badge'         => [ '🏅', '獲得徽章',            '當你解鎖新徽章時通知' ],
            'system'        => [ '📢', '系統公告',            '網站重要更新與公告' ],
        ];
    }
    ?>
    <div class="mc-set-card mc-set-card--notif-prefs" data-uid="<?php echo $uid; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <h3 class="mc-set-title"><i class="fa-solid fa-bell"></i> 通知偏好</h3>
        <p class="mc-set-hint">選擇你想收到哪些類型的通知。「站內」會顯示在鈴鐺/通知頁，「Email」會依下方摘要頻率寄送。</p>

        <div class="mc-notif-prefs-table">
            <div class="mc-notif-prefs-head">
                <span class="mc-notif-col-name">通知類型</span>
                <span class="mc-notif-col-toggle">站內</span>
                <span class="mc-notif-col-toggle">Email</span>
            </div>

            <?php foreach ( $types as $key => [ $icon, $name, $desc ] ):
                $site_key  = $key . '_site';
                $email_key = $key . '_email';
            ?>
                <div class="mc-notif-prefs-row">
                    <div class="mc-notif-col-name">
                        <span class="mc-notif-type-icon"><?php echo $icon; ?></span>
                        <div class="mc-notif-type-text">
                            <div class="mc-notif-type-name"><?php echo esc_html( $name ); ?></div>
                            <div class="mc-notif-type-desc"><?php echo esc_html( $desc ); ?></div>
                        </div>
                    </div>
                    <div class="mc-notif-col-toggle">
                        <label class="mc-toggle mc-toggle--sm">
                            <input type="checkbox" class="mc-notif-pref" data-key="<?php echo esc_attr( $site_key ); ?>" <?php checked( ! empty( $prefs[ $site_key ] ) ); ?>>
                            <span class="mc-toggle-slider"></span>
                        </label>
                    </div>
                    <div class="mc-notif-col-toggle">
                        <label class="mc-toggle mc-toggle--sm">
                            <input type="checkbox" class="mc-notif-pref" data-key="<?php echo esc_attr( $email_key ); ?>" <?php checked( ! empty( $prefs[ $email_key ] ) ); ?>>
                            <span class="mc-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mc-notif-digest-row">
            <label class="mc-set-label mc-set-label--inline">
                <span><i class="fa-solid fa-envelope"></i> Email 摘要頻率</span>
                <select class="mc-notif-digest" data-key="email_digest">
                    <option value="off"    <?php selected( $prefs['email_digest'], 'off' ); ?>>不寄送</option>
                    <option value="daily"  <?php selected( $prefs['email_digest'], 'daily' ); ?>>每日 20:00（推薦）</option>
                    <option value="weekly" <?php selected( $prefs['email_digest'], 'weekly' ); ?>>每週日 20:00</option>
                </select>
            </label>
            <p class="mc-set-hint">摘要會把過去 24 小時（或 7 天）的所有通知整理成一封信寄出，而不是每則發一封。</p>
        </div>

        <div id="mc-notif-prefs-msg" class="mc-set-msg" style="display:none"></div>
    </div>
    <?php
}

/* ===== 繼續觀看橫向列（與你原本檔案完全相同）===== */
function smacg_render_continue_watching( $watchlist ) {
    if ( empty( $watchlist ) || ! is_array( $watchlist ) ) return;

    $continue = [];
    foreach ( $watchlist as $w ) {
        if ( ( $w['status'] ?? '' ) !== 'watching' ) continue;
        $pid = (int) ( $w['post_id'] ?? 0 );
        if ( ! $pid ) continue;

        $total = (int) get_post_meta( $pid, 'anime_episodes', true );
        $prog  = (int) ( $w['progress'] ?? 0 );
        if ( $total > 0 && $prog >= $total ) continue;

        $w['_total']   = $total;
        $w['_percent'] = $total > 0 ? min( 100, round( $prog / $total * 100 ) ) : 0;
        $continue[]    = $w;
        if ( count( $continue ) >= 10 ) break;
    }

    if ( empty( $continue ) ) {
        ?>
        <section class="mc-continue-section mc-continue-empty">
            <div class="mc-continue-header">
                <h2 class="mc-continue-title">🎬 繼續觀看</h2>
            </div>
            <div class="mc-continue-empty-box">
                <p>目前沒有觀看中的動畫</p>
                <a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>" class="mc-btn-primary">
                    <i class="fa-solid fa-compass"></i> 去找一部來追
                </a>
            </div>
        </section>
        <?php
        return;
    }
    ?>
    <section class="mc-continue-section" id="mc-continue-section">
        <div class="mc-continue-header">
            <h2 class="mc-continue-title">🎬 繼續觀看 <small>（<?php echo count( $continue ); ?>）</small></h2>
            <div class="mc-continue-nav">
                <button type="button" class="mc-continue-arrow" data-dir="prev" aria-label="向左捲動">‹</button>
                <button type="button" class="mc-continue-arrow" data-dir="next" aria-label="向右捲動">›</button>
            </div>
        </div>

        <div class="mc-continue-scroll" id="mc-continue-scroll">
            <?php foreach ( $continue as $w ):
                $pid       = (int) $w['post_id'];
                $title     = get_the_title( $pid );
                $permalink = get_permalink( $pid );
                $thumb     = wxacg_get_card_thumb_url( $pid );
                $total     = (int) $w['_total'];
                $prog      = (int) $w['progress'];
                $percent   = (int) $w['_percent'];
            ?>
                <article class="mc-anime-card mc-continue-card"
                         data-anime-id="<?php echo $pid; ?>"
                         data-status="watching"
                         data-title="<?php echo esc_attr( mb_strtolower( $title ) ); ?>">

                    <div class="mc-card-actions" data-anime="<?php echo $pid; ?>" data-progress="<?php echo $prog; ?>" data-total="<?php echo $total; ?>">
                        <button type="button" class="mc-card-btn mc-card-btn--plus" title="進度 +1"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" class="mc-card-btn mc-card-btn--done" title="標記完成"><i class="fa-solid fa-check"></i></button>
                    </div>

                    <a href="<?php echo esc_url( $permalink ); ?>" class="mc-card-thumb">
                        <?php if ( $thumb ): ?>
                            <?php if ( function_exists( 'smacg_picture_tag' ) && has_post_thumbnail( $pid ) ) {
                                echo smacg_picture_tag( $pid, 'medium', $title );
                            } else { ?>
                                <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
                            <?php } ?>
                        <?php else: ?>
                            <div class="mc-card-thumb-ph" aria-hidden="true">🎬</div>
                        <?php endif; ?>
                    </a>

                    <div class="mc-card-body">
                        <h4 class="mc-card-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h4>
                        <div class="mc-card-progress">
                            <div class="mc-card-progress-fill" style="width:<?php echo $percent; ?>%"></div>
                            <span class="mc-card-progress-text"><?php echo $prog; ?> / <?php echo $total ?: '?'; ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/* ===== 我的收藏（角色／聲優）===== */

/**
 * 會員中心的「❤️ 我的收藏」分頁。
 *
 * 與「我的清單」分開：那是作品的追番狀態（anime_user_status），
 * 這是角色／聲優的實體收藏（wxacg_entity_favorites），
 * 兩者的資料來源與語意都不同。
 *
 * 角色與聲優不是 WordPress 文章，無法沿用 wxacg_render_anime_card()，
 * 但共用 .mc-card-grid / .mc-anime-card 的樣式，視覺上與其他分頁一致。
 */
function wxacg_render_entity_favorites( $uid ) {
    $uid = (int) $uid;

    if ( $uid <= 0 || ! class_exists( 'Anime_Sync_Entity_Favorites' ) ) {
        echo '<p class="mc-empty">收藏模組尚未載入</p>';
        return;
    }

    $groups = [
        'character' => [ '🎭', '角色', '/character/' ],
        'person'    => [ '🎤', '聲優', '/person/' ],
    ];

    $counts = Anime_Sync_Entity_Favorites::count_by_type( $uid );
    $total  = array_sum( $counts );

    if ( $total === 0 ) {
        ?>
        <p class="mc-empty">
            還沒有收藏任何角色或聲優。<br>
            到角色頁或聲優頁點右上的 🤍 就能加入收藏。
        </p>
        <?php
        return;
    }

    foreach ( $groups as $type => [ $icon, $label, $base ] ) {
        $items = Anime_Sync_Entity_Favorites::get_user_favorites( $uid, $type );

        if ( ! $items ) {
            continue;
        }
        ?>
        <div class="mc-list-toolbar">
            <h3 class="mc-fav-group-title"><?php echo $icon; ?> <?php echo esc_html( $label ); ?>（<?php echo count( $items ); ?>）</h3>
        </div>

        <div class="mc-card-grid">
            <?php foreach ( $items as $it ) :
                // 角色有中文名就優先顯示；聲優表沒有 name_cn，會是空字串
                $name = $it['name_cn'] !== '' ? $it['name_cn'] : $it['name'];
                $url  = home_url( $base . (int) $it['entity_id'] . '/' );
                ?>
                <article class="mc-anime-card mc-entity-card">
                    <a href="<?php echo esc_url( $url ); ?>" class="mc-card-thumb">
                        <?php if ( ! empty( $it['image'] ) ) : ?>
                            <img src="<?php echo esc_url( $it['image'] ); ?>"
                                 alt="<?php echo esc_attr( $name ); ?>" loading="lazy">
                        <?php else : ?>
                            <span class="mc-card-noimg"><?php echo $icon; ?></span>
                        <?php endif; ?>
                    </a>

                    <div class="mc-card-body">
                        <a href="<?php echo esc_url( $url ); ?>" class="mc-card-title"><?php echo esc_html( $name ); ?></a>
                        <?php if ( $it['name_cn'] !== '' && $it['name'] !== $it['name_cn'] ) : ?>
                            <span class="mc-card-sub"><?php echo esc_html( $it['name'] ); ?></span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
