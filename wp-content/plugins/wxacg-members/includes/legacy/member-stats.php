<?php
/**
 * Member Center - Stats & Data Layer
 * Version: 2.1.0 (2026-05-13)
 *
 * 所有資料抓取 / 統計計算集中於此。重點：
 * - 一律批次預載 post（解 N+1）
 * - 觀看時數使用 anime_duration 真實值，fallback 24
 * - 收藏列入獨立分類,不再誤判為「想看」
 *
 * v2.0.1 變更:
 *   - 修正 wp_anime_ratings 真實欄位名稱
 *     (score_overall / score_story / score_music / score_animation / score_voice)
 *   - 對外仍以 overall_score 等命名回傳,下游程式碼無需改動
 *   - 評分表存在性檢查（避免表不存在時 SQL fatal）
 *   - 會員方案 (smacg_get_plan_label) 加入 wxacg_vvip / wxacg_vip / vvip / vip 等 role
 *
 * v2.0.2 變更:
 *   - 新增 smacg_get_user_privacy() / smacg_mask_email()
 *
 * v2.0.3 變更 (Batch B):
 *   - smacg_calc_member_stats() 加入 5 分鐘 transient cache
 *     key: smacg_stats_{uid},由 Anime_Sync_User_Status_Manager::flush_cache() 失效
 *   - 新增 completion_rate 欄位（完成率）
 *   - 函式簽章新增第三個參數 $uid（cache key 用）
 *
 * v2.1.0 變更 (Batch C):
 *   - 新增 smacg_get_recent_activity($uid, $limit) — #9 最近活動時間軸
 *     聚合 4 個來源:watchlist updated_at / ratings updated_at / points_log / comments
 *     回傳統一格式 ['type','time','post_id','title','meta','icon','color']
 *   - 新增 smacg_calc_year_review($uid, $year) — #14 年度回顧
 *     計算該年度 watchlist + ratings 的完整統計（總計、月份、Top 類型/工作室/評分、徽章）
 *     1 小時 transient cache,key: smacg_yearreview_{uid}_{year}
 */
if (!defined('ABSPATH')) exit;

/* ===== 等級 ===== */
function smacg_calc_level($points) {
    // 每級門檻:100, 300, 600, 1000, 1500, 2100, 2800, 3600, 4500, 5500...
    $thresholds = [0,100,300,600,1000,1500,2100,2800,3600,4500,5500];
    $level = 1; $cur = 0; $next = 100;
    foreach ($thresholds as $i => $t) {
        if ($points >= $t) { $level = $i + 1; $cur = $t; $next = $thresholds[$i+1] ?? $t + 1000; }
        else break;
    }
    $span    = max(1, $next - $cur);
    $percent = min(100, round(($points - $cur) / $span * 100));
    $titles  = ['新手','見習','愛好者','資深','達人','專家','大師','傳奇','神級','至尊','超凡'];
    return [
        'level'   => $level,
        'current' => $cur,
        'next'    => $next,
        'percent' => $percent,
        'title'   => $titles[$level-1] ?? '至尊',
    ];
}

/* ===== 會員方案（兼容 wxacg_* / um_* / WP 內建 role）===== */
function smacg_get_plan_label($user) {
    $roles = (array) $user->roles;

    // VVIP 優先判斷
    foreach (['wxacg_vvip', 'vvip'] as $r) {
        if (in_array($r, $roles, true)) return '👑 VVIP 會員';
    }
    // VIP / Premium
    foreach (['wxacg_vip', 'wxacg_pro', 'vip', 'um_vip', 'um_premium'] as $r) {
        if (in_array($r, $roles, true)) return '⭐ VIP 會員';
    }
    // 管理 / 編輯
    if (in_array('administrator', $roles, true)) return '管理員';
    if (in_array('editor', $roles, true))        return '編輯';

    return '免費會員';
}

/* ===== 清單建構（修復收藏歸類）===== */
function smacg_build_watchlist($uid) {
    if (!class_exists('Anime_Sync_User_Status_Manager')) return [];
    $mgr  = new Anime_Sync_User_Status_Manager();
    $rows = $mgr->get_user_list($uid);
    if (empty($rows)) return [];

    // 批次預載 post（解 N+1）
    $ids = array_column($rows, 'anime_id');
    smacg_prime_posts($ids);

    $list = [];
    foreach ($rows as $r) {
        $pid = (int) $r['anime_id'];
        if (get_post_status($pid) !== 'publish') continue;

        $status    = $r['status'] ?? '';   // 'want'|'watching'|'completed'|'dropped'|''
        $favorited = !empty($r['favorited']);
        $fullclear = !empty($r['fullcleared']);

        // 修復:純收藏（無 status）不再誤判為 planned
        if ($status === '') {
            if (!$favorited && !$fullclear) continue;
            $display = $favorited ? 'favorited' : 'completed';
        } else {
            $display = $status;
        }

        $list[] = [
            'post_id'   => $pid,
            'status'    => $display,
            'favorited' => $favorited,
            'fullclear' => $fullclear,
            'progress'  => (int) ($r['progress'] ?? 0),
            'note'      => $r['note'] ?? '',
            'updated'   => $r['updated_at'] ?? '',
        ];
    }
    return $list;
}

/* 批次預載 post（避免 get_the_title 等觸發 N 次 SQL）*/
function smacg_prime_posts(array $ids) {
    $ids = array_unique(array_filter(array_map('intval', $ids)));
    if (!$ids) return;
    _prime_post_caches($ids, true, true); // WP core,會一次抓 post + meta + term
}

/* ===== 評分（批次預載 + 欄位正規化）===== */
function smacg_get_user_ratings($uid) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'anime_ratings';

    // 表不存在則回空,避免 SQL fatal
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl)) !== $tbl) {
        return [];
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT anime_id,
                score_overall,
                score_story,
                score_music,
                score_animation,
                score_voice,
                updated_at
         FROM {$tbl}
         WHERE user_id = %d
         ORDER BY updated_at DESC",
        $uid
    ), ARRAY_A);

    if (!$rows) return [];

    // 正規化欄位:對外仍使用 overall_score 等命名
    $normalized = [];
    foreach ($rows as $r) {
        $normalized[] = [
            'anime_id'        => (int) $r['anime_id'],
            'overall_score'   => (float) $r['score_overall'],
            'story_score'     => (float) $r['score_story'],
            'music_score'     => (float) $r['score_music'],
            'animation_score' => (float) $r['score_animation'],
            'voice_score'     => (float) $r['score_voice'],
            'updated_at'      => $r['updated_at'],
        ];
    }

    smacg_prime_posts(array_column($normalized, 'anime_id'));
    return $normalized;
}

/* ===== 點數紀錄 ===== */
function smacg_get_points_log($uid, $limit = 50) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'smacg_points_log';

    // 表不存在則回空,避免錯誤
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl)) !== $tbl) return [];

    return $wpdb->get_results($wpdb->prepare(
        "SELECT change_value, reason, created_at FROM {$tbl}
         WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
        $uid, $limit
    ), ARRAY_A) ?: [];
}

/* ===== 最近留言 ===== */
function smacg_get_recent_comments($uid, $limit = 5) {
    return get_comments([
        'user_id' => $uid,
        'number'  => $limit,
        'status'  => 'approve',
        'orderby' => 'comment_date',
        'order'   => 'DESC',
    ]);
}

/* ===========================================================
 *  統計核心:一次走訪 watchlist + ratings,產出所有圖表資料
 *
 *  v2.0.3:加入 5 分鐘 transient cache + 完成率
 *  - $uid 為 0 時不使用 cache（向下相容舊呼叫端）
 *  - cache 由 Anime_Sync_User_Status_Manager::flush_cache() 在
 *    使用者新增/修改/刪除任何 watchlist 項目時自動失效
 * =========================================================== */
function smacg_calc_member_stats($watchlist, $ratings, $uid = 0) {
    // ---- Cache 查詢 ----
    $uid = (int) $uid;
    if ($uid > 0) {
        $cached = get_transient('smacg_stats_' . $uid);
        if (is_array($cached) && !empty($cached['_cache_version']) && $cached['_cache_version'] === '2.0.3') {
            return $cached;
        }
    }

    // ---- 計數 ----
    $counts = ['all'=>0,'watching'=>0,'completed'=>0,'want'=>0,'favorited'=>0,'dropped'=>0,'paused'=>0];
    $genre_map = $studio_map = $year_map = [];
    $total_min = 0;

    foreach ($watchlist as $w) {
        $counts['all']++;
        if (isset($counts[$w['status']])) $counts[$w['status']]++;
        if ($w['favorited'] && $w['status'] !== 'favorited') $counts['favorited']++; // 重疊計入

        // 只計算「已看完」的觀看時數
        if ($w['status'] === 'completed' || $w['fullclear']) {
            $pid = $w['post_id'];
            $ep  = (int) get_post_meta($pid, 'anime_episodes', true) ?: 12;
            $dur = (int) get_post_meta($pid, 'anime_duration', true) ?: 24;
            $total_min += $ep * $dur;
        } elseif ($w['status'] === 'watching') {
            $pid = $w['post_id'];
            $dur = (int) get_post_meta($pid, 'anime_duration', true) ?: 24;
            $total_min += $w['progress'] * $dur;
        }

        // 類型 / 製作公司 / 年代
        $pid = $w['post_id'];
        $genres = wp_get_post_terms($pid, 'genre', ['fields' => 'names']);
        if (!is_wp_error($genres)) {
            foreach ($genres as $g) $genre_map[$g] = ($genre_map[$g] ?? 0) + 1;
        }
        $studios = wp_get_post_terms($pid, 'anime_studio_tax', ['fields' => 'names']);
        if (is_wp_error($studios) || !$studios) {
            $meta = get_post_meta($pid, 'anime_studios', true);
            $studios = $meta ? array_map('trim', explode(',', $meta)) : [];
        }
        foreach ($studios as $s) if ($s) $studio_map[$s] = ($studio_map[$s] ?? 0) + 1;

        $year = (int) get_post_meta($pid, 'anime_season_year', true);
        if ($year) {
            $bucket = $year < 2000 ? '~1999' : (intval($year/10)*10) . 's';
            $year_map[$bucket] = ($year_map[$bucket] ?? 0) + 1;
        }
    }

    // ---- 完成率（v2.0.3 新增）----
    // 公式:completed / (completed + dropped + watching + paused) × 100
    // 排除「想看」與純收藏,因為這些還沒開始追,不該影響完成率
    // 暫停屬於「已開始但未完成」,與 watching 同理計入分母
    $denominator = $counts['completed'] + $counts['dropped']
        + $counts['watching'] + $counts['paused'];
    $completion_rate = $denominator > 0
        ? round($counts['completed'] / $denominator * 100, 1)
        : 0;

    // ---- 評分統計 ----
    $rcount = count($ratings);
    $rsum = 0; $dist = array_fill(1, 10, 0);
    $score_rows = [];
    foreach ($ratings as $r) {
        $s = (float) $r['overall_score'];
        $rsum += $s;
        $bucket = max(1, min(10, (int) ceil($s)));
        $dist[$bucket]++;
        $score_rows[] = ['post_id' => (int)$r['anime_id'], 'score' => $s];
    }
    usort($score_rows, fn($a,$b)=>$b['score']<=>$a['score']);
    $top3    = array_slice($score_rows, 0, 3);
    $bottom3 = array_slice(array_reverse($score_rows), 0, 3);

    // ---- 排序 genre / studio / year ----
    arsort($genre_map); arsort($studio_map); ksort($year_map);

    $genre_total = array_sum($genre_map) ?: 1;
    $genres_top = [];
    foreach (array_slice($genre_map, 0, 8, true) as $name => $c) {
        $genres_top[] = ['name'=>$name, 'count'=>$c, 'percent'=>round($c / $genre_total * 100, 1)];
    }
    $studios_top = [];
    foreach (array_slice($studio_map, 0, 5, true) as $name => $c) {
        $studios_top[] = ['name'=>$name, 'count'=>$c];
    }
    $years_arr = [];
    foreach ($year_map as $y => $c) $years_arr[] = ['year'=>$y, 'count'=>$c];

    $result = [
        'counts'          => $counts,
        'completion_rate' => $completion_rate, // v2.0.3
        'watch_time' => [
            'minutes' => $total_min,
            'hours'   => floor($total_min / 60),
            'days'    => round($total_min / 1440, 1),
        ],
        'rating' => [
            'count'        => $rcount,
            'avg'          => $rcount ? round($rsum / $rcount, 2) : 0,
            'distribution' => $dist,
            'top3'         => $top3,
            'bottom3'      => $bottom3,
        ],
        'genres'         => $genres_top,
        'studios'        => $studios_top,
        'years'          => $years_arr,
        '_cache_version' => '2.0.3',
        '_cached_at'     => time(),
    ];

    // ---- 寫入 cache（5 分鐘）----
    if ($uid > 0) {
        set_transient('smacg_stats_' . $uid, $result, 5 * MINUTE_IN_SECONDS);
    }

    return $result;
}

/**
 * 取得使用者隱私設定（v2.0.2 新增）
 * 四個布林值:show_email, public_profile, public_watchlist, show_continue_watching
 */
function smacg_get_user_privacy( $uid ) {
    $defaults = [
        'show_email'             => 0, // 預設遮罩 email
        'public_profile'         => 1, // 預設公開個人頁
        'public_watchlist'       => 1, // 預設公開追番列表
        'show_continue_watching' => 1, // 預設顯示「繼續觀看」橫向列（P1-2）
    ];
    $saved = get_user_meta( $uid, 'smacg_privacy', true );
    if ( ! is_array( $saved ) ) $saved = [];
    return array_merge( $defaults, $saved );
}

/**
 * 遮罩 email:a***@gmail.com
 */
function smacg_mask_email( $email ) {
    if ( ! $email || strpos( $email, '@' ) === false ) return '';
    list( $name, $domain ) = explode( '@', $email, 2 );
    $len = mb_strlen( $name );
    if ( $len <= 1 ) return $name . '***@' . $domain;
    return mb_substr( $name, 0, 1 ) . str_repeat( '*', min( 3, $len - 1 ) ) . '@' . $domain;
}

/* ===========================================================
 *  v2.1.0 — Batch C #9:最近活動時間軸
 *  -----------------------------------------------------------
 *  聚合 4 個來源:
 *    1. watchlist 的 updated_at（狀態變動 = 看完 / 追番 / 想看 / 棄番）
 *    2. ratings 的 updated_at（給予評分）
 *    3. smacg_points_log（獲得點數）
 *    4. comments（發表留言）
 *
 *  回傳統一格式:
 *    [
 *      'type'    => 'watchlist'|'rating'|'points'|'comment',
 *      'subtype' => 'completed'|'watching'|'want'|'dropped'|''（僅 watchlist）,
 *      'time'    => unix timestamp,
 *      'time_human' => '3 天前',
 *      'post_id' => 0 或文章 ID,
 *      'title'   => 顯示文字,
 *      'meta'    => 額外資訊（評分、點數變動）,
 *      'icon'    => emoji,
 *      'color'   => CSS 變數名,
 *      'link'    => 連結 URL,
 *    ]
 * =========================================================== */
function smacg_get_recent_activity( $uid, $limit = 20 ) {
    $uid = (int) $uid;
    if ( $uid <= 0 ) return [];

    $events = [];

    // ---- 來源 1:watchlist ----
    if ( class_exists( 'Anime_Sync_User_Status_Manager' ) ) {
        $mgr  = new Anime_Sync_User_Status_Manager();
        $rows = $mgr->get_user_list( $uid );
        if ( $rows ) {
            $status_label = [
                'completed' => [ '看完了', '✅', 'completed' ],
                'watching'  => [ '開始追', '👀', 'watching' ],
                'want'      => [ '加入想看', '⭐', 'want' ],
                'paused'    => [ '暫停追看', '⏸', 'paused' ],
                'dropped'   => [ '棄番了', '😴', 'dropped' ],
            ];
            foreach ( $rows as $r ) {
                $status = $r['status'] ?? '';
                $updated = $r['updated_at'] ?? '';
                if ( ! $status || ! $updated ) continue;
                if ( ! isset( $status_label[ $status ] ) ) continue;

                $pid = (int) ( $r['anime_id'] ?? 0 );
                if ( ! $pid || get_post_status( $pid ) !== 'publish' ) continue;

                [ $label, $icon, $color ] = $status_label[ $status ];
                $events[] = [
                    'type'    => 'watchlist',
                    'subtype' => $status,
                    'time'    => strtotime( $updated ),
                    'post_id' => $pid,
                    'title'   => $label,
                    'meta'    => '',
                    'icon'    => $icon,
                    'color'   => $color,
                    'link'    => get_permalink( $pid ),
                ];
            }
        }
    }

    // ---- 來源 2:ratings ----
    global $wpdb;
    $tbl_rating = $wpdb->prefix . 'anime_ratings';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl_rating ) ) === $tbl_rating ) {
        $rate_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT anime_id, score_overall, updated_at
             FROM {$tbl_rating}
             WHERE user_id = %d
             ORDER BY updated_at DESC
             LIMIT %d",
            $uid, $limit * 2
        ), ARRAY_A );

        if ( $rate_rows ) {
            foreach ( $rate_rows as $r ) {
                $pid = (int) $r['anime_id'];
                if ( ! $pid || get_post_status( $pid ) !== 'publish' ) continue;
                $events[] = [
                    'type'    => 'rating',
                    'subtype' => '',
                    'time'    => strtotime( $r['updated_at'] ),
                    'post_id' => $pid,
                    'title'   => '評分',
                    'meta'    => number_format( (float) $r['score_overall'], 1 ) . ' 分',
                    'icon'    => '⭐',
                    'color'   => 'want',
                    'link'    => get_permalink( $pid ),
                ];
            }
        }
    }

    // ---- 來源 3:points_log ----
    $tbl_points = $wpdb->prefix . 'smacg_points_log';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl_points ) ) === $tbl_points ) {
        $pt_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT change_value, reason, created_at
             FROM {$tbl_points}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $uid, $limit * 2
        ), ARRAY_A );

        if ( $pt_rows ) {
            foreach ( $pt_rows as $p ) {
                $v = (int) $p['change_value'];
                if ( $v === 0 ) continue;
                $events[] = [
                    'type'    => 'points',
                    'subtype' => $v >= 0 ? 'gain' : 'loss',
                    'time'    => strtotime( $p['created_at'] ),
                    'post_id' => 0,
                    'title'   => $p['reason'] ?: '點數變動',
                    'meta'    => ( $v >= 0 ? '+' : '' ) . $v . ' 點',
                    'icon'    => $v >= 0 ? '🎁' : '💸',
                    'color'   => $v >= 0 ? 'completed' : 'dropped',
                    'link'    => '',
                ];
            }
        }
    }

    // ---- 來源 4:comments ----
    $cmts = get_comments( [
        'user_id' => $uid,
        'status'  => 'approve',
        'number'  => $limit,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
    ] );
    if ( $cmts ) {
        foreach ( $cmts as $c ) {
            $pid = (int) $c->comment_post_ID;
            $events[] = [
                'type'    => 'comment',
                'subtype' => '',
                'time'    => strtotime( $c->comment_date ),
                'post_id' => $pid,
                'title'   => '留言',
                'meta'    => wp_trim_words( $c->comment_content, 15 ),
                'icon'    => '💬',
                'color'   => 'accent-2',
                'link'    => get_comment_link( $c ),
            ];
        }
    }

    // ---- 排序 + 截斷 + 加上人類可讀時間 ----
    usort( $events, fn( $a, $b ) => $b['time'] <=> $a['time'] );
    $events = array_slice( $events, 0, $limit );

    $now = current_time( 'timestamp' );
    foreach ( $events as &$e ) {
        if ( ! $e['time'] ) {
            $e['time_human'] = '—';
            continue;
        }
        $diff = $now - $e['time'];
        if ( $diff < 60 ) {
            $e['time_human'] = '剛剛';
        } elseif ( $diff < 86400 * 7 ) {
            $e['time_human'] = human_time_diff( $e['time'], $now ) . '前';
        } else {
            $e['time_human'] = wp_date( 'Y-m-d', $e['time'] );
        }
    }
    unset( $e );

    return $events;
}

/* ===========================================================
 *  v2.1.0 — Batch C #14:年度回顧資料計算
 *  -----------------------------------------------------------
 *  彙整指定年度的 watchlist + ratings 並回傳:
 *    - total_completed / total_watching / total_rated
 *    - total_episodes  / total_minutes / total_hours / total_days
 *    - monthly[1..12] = count
 *    - peak_month / peak_month_count
 *    - top_genres / top_studios / top_rated
 *    - badges[] = ['icon','name','desc']
 *
 *  Cache:1 小時 transient,key = smacg_yearreview_{uid}_{year}
 *  cache 由 Anime_Sync_User_Status_Manager::flush_cache() 在 watchlist 變動時同步失效
 *  （class-user-status-manager.php 需把 yearreview key 加入 flush 清單,
 *  若尚未加入,最差情況也只是有 1 小時延遲）
 * =========================================================== */
function smacg_calc_year_review( $uid, $year ) {
    $uid  = (int) $uid;
    $year = (int) $year;
    if ( $uid <= 0 || $year < 2020 ) {
        return smacg_year_review_empty();
    }

    // ---- Cache 查詢 ----
    $cache_key = 'smacg_yearreview_' . $uid . '_' . $year;
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) && ! empty( $cached['_cache_version'] ) && $cached['_cache_version'] === '1.0.0' ) {
        return $cached;
    }

    $year_start = strtotime( $year . '-01-01 00:00:00' );
    $year_end   = strtotime( ( $year + 1 ) . '-01-01 00:00:00' );

    // ---- 抓 watchlist 並過濾「該年度有變動」的項目 ----
    $watchlist = smacg_build_watchlist( $uid );
    $in_year   = [];
    foreach ( $watchlist as $w ) {
        $ts = $w['updated'] ? strtotime( $w['updated'] ) : 0;
        if ( $ts >= $year_start && $ts < $year_end ) {
            $w['_ts'] = $ts;
            $in_year[] = $w;
        }
    }

    // ---- 統計 ----
    $total_completed = 0;
    $total_watching  = 0;
    $total_episodes  = 0;
    $total_minutes   = 0;
    $monthly         = array_fill( 1, 12, 0 );
    $genre_map = $studio_map = [];

    foreach ( $in_year as $w ) {
        $month = (int) wp_date( 'n', $w['_ts'] );
        if ( $month >= 1 && $month <= 12 ) {
            $monthly[ $month ]++;
        }

        $pid = $w['post_id'];
        $ep  = (int) get_post_meta( $pid, 'anime_episodes', true ) ?: 12;
        $dur = (int) get_post_meta( $pid, 'anime_duration', true ) ?: 24;

        if ( $w['status'] === 'completed' || $w['fullclear'] ) {
            $total_completed++;
            $total_episodes += $ep;
            $total_minutes  += $ep * $dur;
        } elseif ( $w['status'] === 'watching' ) {
            $total_watching++;
            $total_episodes += $w['progress'];
            $total_minutes  += $w['progress'] * $dur;
        }

        // 類型
        $genres = wp_get_post_terms( $pid, 'genre', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $genres ) ) {
            foreach ( $genres as $g ) {
                $genre_map[ $g ] = ( $genre_map[ $g ] ?? 0 ) + 1;
            }
        }
        // 工作室
        $studios = wp_get_post_terms( $pid, 'anime_studio_tax', [ 'fields' => 'names' ] );
        if ( is_wp_error( $studios ) || ! $studios ) {
            $meta = get_post_meta( $pid, 'anime_studios', true );
            $studios = $meta ? array_map( 'trim', explode( ',', $meta ) ) : [];
        }
        foreach ( $studios as $s ) {
            if ( $s ) $studio_map[ $s ] = ( $studio_map[ $s ] ?? 0 ) + 1;
        }
    }

    // ---- 該年度的評分 ----
    $ratings = smacg_get_user_ratings( $uid );
    $year_ratings = [];
    foreach ( $ratings as $r ) {
        $ts = $r['updated_at'] ? strtotime( $r['updated_at'] ) : 0;
        if ( $ts >= $year_start && $ts < $year_end ) {
            $year_ratings[] = [
                'post_id' => $r['anime_id'],
                'score'   => (float) $r['overall_score'],
            ];
        }
    }
    $total_rated = count( $year_ratings );

    // Top Rated
    usort( $year_ratings, fn( $a, $b ) => $b['score'] <=> $a['score'] );
    $top_rated = array_slice( $year_ratings, 0, 5 );

    // ---- Peak month ----
    $peak_month = 0;
    $peak_month_count = 0;
    foreach ( $monthly as $m => $c ) {
        if ( $c > $peak_month_count ) {
            $peak_month = $m;
            $peak_month_count = $c;
        }
    }

    // ---- Top Genres / Studios ----
    arsort( $genre_map );
    arsort( $studio_map );
    $top_genres = [];
    foreach ( array_slice( $genre_map, 0, 5, true ) as $name => $c ) {
        $top_genres[] = [ 'name' => $name, 'count' => $c ];
    }
    $top_studios = [];
    foreach ( array_slice( $studio_map, 0, 5, true ) as $name => $c ) {
        $top_studios[] = [ 'name' => $name, 'count' => $c ];
    }

    // ---- 徽章 ----
    $badges = smacg_calc_year_badges( [
        'total_completed' => $total_completed,
        'total_watching'  => $total_watching,
        'total_rated'     => $total_rated,
        'total_hours'     => floor( $total_minutes / 60 ),
        'genre_count'     => count( $genre_map ),
        'monthly'         => $monthly,
        'peak_month_count'=> $peak_month_count,
    ] );

    $result = [
        'year'             => $year,
        'total_completed'  => $total_completed,
        'total_watching'   => $total_watching,
        'total_rated'      => $total_rated,
        'total_episodes'   => $total_episodes,
        'total_minutes'    => $total_minutes,
        'total_hours'      => floor( $total_minutes / 60 ),
        'total_days'       => round( $total_minutes / 1440, 1 ),
        'monthly'          => $monthly,
        'peak_month'       => $peak_month,
        'peak_month_count' => $peak_month_count,
        'top_genres'       => $top_genres,
        'top_studios'      => $top_studios,
        'top_rated'        => $top_rated,
        'badges'           => $badges,
        '_cache_version'   => '1.0.0',
        '_cached_at'       => time(),
    ];

    // ---- 寫入 cache（1 小時）----
    set_transient( $cache_key, $result, HOUR_IN_SECONDS );

    return $result;
}

/**
 * 年度回顧:無資料時的預設回傳結構
 */
function smacg_year_review_empty() {
    return [
        'year'             => (int) date( 'Y' ),
        'total_completed'  => 0,
        'total_watching'   => 0,
        'total_rated'      => 0,
        'total_episodes'   => 0,
        'total_minutes'    => 0,
        'total_hours'      => 0,
        'total_days'       => 0,
        'monthly'          => array_fill( 1, 12, 0 ),
        'peak_month'       => 0,
        'peak_month_count' => 0,
        'top_genres'       => [],
        'top_studios'      => [],
        'top_rated'        => [],
        'badges'           => [],
        '_cache_version'   => '1.0.0',
        '_cached_at'       => time(),
    ];
}

/**
 * 年度回顧:計算徽章
 */
function smacg_calc_year_badges( $s ) {
    $badges = [];

    // 看完數量徽章
    if ( $s['total_completed'] >= 100 ) {
        $badges[] = [ 'icon' => '🏆', 'name' => '百番達人', 'desc' => '今年看完超過 100 部!' ];
    } elseif ( $s['total_completed'] >= 50 ) {
        $badges[] = [ 'icon' => '🥇', 'name' => '半百勇者', 'desc' => '今年看完 50 部以上' ];
    } elseif ( $s['total_completed'] >= 20 ) {
        $badges[] = [ 'icon' => '🥈', 'name' => '追番達人', 'desc' => '今年看完 20 部以上' ];
    } elseif ( $s['total_completed'] >= 10 ) {
        $badges[] = [ 'icon' => '🥉', 'name' => '入門追番', 'desc' => '今年看完 10 部以上' ];
    } elseif ( $s['total_completed'] >= 5 ) {
        $badges[] = [ 'icon' => '🌟', 'name' => '小試身手', 'desc' => '今年看完 5 部以上' ];
    }

    // 評分徽章
    if ( $s['total_rated'] >= 50 ) {
        $badges[] = [ 'icon' => '⭐', 'name' => '評分大師', 'desc' => '為 50+ 部作品評分' ];
    } elseif ( $s['total_rated'] >= 20 ) {
        $badges[] = [ 'icon' => '✨', 'name' => '評分愛好者', 'desc' => '為 20+ 部作品評分' ];
    } elseif ( $s['total_rated'] >= 10 ) {
        $badges[] = [ 'icon' => '💫', 'name' => '熱心評分人', 'desc' => '為 10+ 部作品評分' ];
    }

    // 觀看時數徽章
    if ( $s['total_hours'] >= 500 ) {
        $badges[] = [ 'icon' => '⏰', 'name' => '時間旅人', 'desc' => '累計觀看超過 500 小時' ];
    } elseif ( $s['total_hours'] >= 200 ) {
        $badges[] = [ 'icon' => '📺', 'name' => '沉浸體驗', 'desc' => '累計觀看超過 200 小時' ];
    } elseif ( $s['total_hours'] >= 50 ) {
        $badges[] = [ 'icon' => '🎬', 'name' => '觀影新手', 'desc' => '累計觀看超過 50 小時' ];
    }

    // 類型多元徽章
    if ( $s['genre_count'] >= 10 ) {
        $badges[] = [ 'icon' => '🌈', 'name' => '什麼都看', 'desc' => '涉獵 10+ 種類型' ];
    } elseif ( $s['genre_count'] >= 5 ) {
        $badges[] = [ 'icon' => '🎭', 'name' => '類型探索者', 'desc' => '涉獵 5+ 種類型' ];
    }

    // 連續觀看徽章
    $months_active = 0;
    foreach ( $s['monthly'] as $c ) {
        if ( $c > 0 ) $months_active++;
    }
    if ( $months_active >= 12 ) {
        $badges[] = [ 'icon' => '🔥', 'name' => '全年無休', 'desc' => '每個月都有觀影紀錄' ];
    } elseif ( $months_active >= 9 ) {
        $badges[] = [ 'icon' => '📅', 'name' => '熱情滿滿', 'desc' => $months_active . ' 個月有觀影紀錄' ];
    } elseif ( $months_active >= 6 ) {
        $badges[] = [ 'icon' => '🗓️', 'name' => '穩定追番', 'desc' => $months_active . ' 個月有觀影紀錄' ];
    }

    // 高峰月徽章
    if ( $s['peak_month_count'] >= 20 ) {
        $badges[] = [ 'icon' => '🚀', 'name' => '爆肝模式', 'desc' => '單月看完 20+ 部' ];
    } elseif ( $s['peak_month_count'] >= 10 ) {
        $badges[] = [ 'icon' => '💪', 'name' => '高峰月', 'desc' => '單月看完 10+ 部' ];
    }

    return $badges;
}