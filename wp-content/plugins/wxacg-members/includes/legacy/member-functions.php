<?php
/**
 * 會員相關 helper：輔助函式 / 舊點數系統（已 deprecated）/ cooldown
 *
 * @package weixiaoacg
 * @subpackage Member
 *
 * Changelog:
 * - v1.3.0 (2026-05-14) — Hotfix：
 *   - [移除] wxacg_get_user_level()（與 level-system.php 思路衝突）
 *     - 舊回傳 array、新需求是「依 EXP 算 level」走 smacg_calc_user_level()
 *     - 改用 wxacg_get_user_level_legacy() 名稱保留舊邏輯，避免撞名 fatal
 *   - [說明] 外部請改呼叫：
 *     - wxacg_get_user_level_info( $uid )    — 取得完整等級資訊（GamiPress EXP）
 *     - smacg_calc_user_level( $exp )         — 由 EXP 算 level
 *     - wxacg_get_user_level_legacy( $uid )   — 舊版 anime_total_points 計算（僅限相容用途）
 * - v1.2.0 (2026-05-14) — Batch 2A-3：
 *   - [移除] comment_post / um_user_login hook（改由 inc/exp-events.php 處理）
 * - v1.1.0 (2026-05-13)：新增 wxacg_get_member_center_url()
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   輔助函式
   ============================================================ */
function weixiaoacg_get_user_level_int(): int {
    if (!is_user_logged_in()) return 0;
    $uid = get_current_user_id();
    $lv  = (int)get_user_meta($uid,'weixiaoacg_user_level',true);
    if (!$lv && function_exists('um_user')) {
        $role = um_user('role');
        $lv = match($role) { 'weixiaoacg_vip'=>3,'weixiaoacg_pro'=>2,default=>1 };
    }
    return $lv ?: 1;
}

function weixiaoacg_get_user_points(int $uid=0): int {
    return (int)get_user_meta($uid?:get_current_user_id(),'weixiaoacg_points',true);
}

if (!function_exists('weixiaoacg_get_news_thumb')) {
    function weixiaoacg_get_news_thumb(int $post_id, string $size='news-thumb'): string {
        if ($url = get_the_post_thumbnail_url($post_id,$size)) return $url;
        $c = get_post($post_id)->post_content ?? '';
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/',$c,$m)) return $m[1];
        return function_exists('get_field') ? (get_field('weixiaoacg_cover_url',$post_id)?:'') : '';
    }
}

if (!function_exists('weixiaoacg_get_anilist'))  { function weixiaoacg_get_anilist($id)     { return null; } }
if (!function_exists('weixiaoacg_get_bangumi'))  { function weixiaoacg_get_bangumi($id,$t=2){ return null; } }

if (!function_exists('weixiaoacg_acf')) {
    function weixiaoacg_acf(string $key, $post_id = false, $default = '') {
        if (!function_exists('get_field')) return $default;
        $v = get_field($key, $post_id);
        return ($v === null || $v === false || $v === '') ? $default : $v;
    }
}

/* ============================================================
   共用判斷：是否為會員中心頁面
   ============================================================ */
if (!function_exists('smacg_is_member_page')) {
    function smacg_is_member_page(): bool {
        if (!function_exists('um_is_core_page')) return is_page_template('page-member.php');
        return um_is_core_page('user')
            || get_query_var('um_user')
            || is_page_template('page-member.php');
    }
}

/* ============================================================
   會員中心 URL 解析（v1.1.0）
   ============================================================ */
if (!function_exists('wxacg_get_member_center_url')) {
    function wxacg_get_member_center_url(): string {
        static $cached = null;
        if ($cached !== null) return $cached;

        $hit = wp_cache_get('smacg_mc_url', 'smacg');
        if ($hit !== false && is_string($hit) && $hit !== '') {
            $cached = $hit;
            return apply_filters('smacg_member_center_url', $cached);
        }

        $url   = '';
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_wp_page_template',
            'meta_value'     => 'page-member.php',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        if (!empty($pages)) {
            $url = get_permalink($pages[0]);
        }

        if (!$url) {
            $url = home_url('/');
        }

        wp_cache_set('smacg_mc_url', $url, 'smacg', HOUR_IN_SECONDS);
        $cached = $url;

        return apply_filters('smacg_member_center_url', $cached);
    }
}

if (!function_exists('smacg_flush_member_center_url_cache')) {
    function smacg_flush_member_center_url_cache($post_id = 0) {
        if ($post_id) {
            $tpl = get_post_meta($post_id, '_wp_page_template', true);
            if ($tpl !== 'page-member.php' && get_post_type($post_id) !== 'page') return;
        }
        wp_cache_delete('smacg_mc_url', 'smacg');
    }
    add_action('save_post_page',   'smacg_flush_member_center_url_cache');
    add_action('deleted_post',     'smacg_flush_member_center_url_cache');
    add_action('switch_theme',     'smacg_flush_member_center_url_cache');
}

/* ============================================================
   @deprecated v1.3.0 — 舊 anime_total_points 點數系統
   ============================================================
   保留函式定義以避免他處仍呼叫導致 fatal error，
   但相關 hook（comment_post / um_user_login）已移除。
   未來會在 Batch 2A-5 完全清除。

   注意：原本的 wxacg_get_user_level() 已移除（與 level-system.php
   命名空間衝突）。舊邏輯改名為 wxacg_get_user_level_legacy()。
   ============================================================ */
if (!function_exists('smacg_get_levels')) {
    function smacg_get_levels(): array {
        return [
            ['min'=>0,    'label'=>'🌱 新手',   'key'=>'newbie'],
            ['min'=>100,  'label'=>'⭐ 動漫迷', 'key'=>'lover'],
            ['min'=>500,  'label'=>'💫 老手',   'key'=>'veteran'],
            ['min'=>2000, 'label'=>'🔥 狂熱者', 'key'=>'fanatic'],
            ['min'=>5000, 'label'=>'👑 大師',   'key'=>'master'],
        ];
    }
}

/**
 * @deprecated v1.3.0 改用 wxacg_get_user_level_info()（GamiPress EXP）
 *             僅保留給可能仍引用舊回傳結構的第三方程式碼。
 *
 * @param int $uid
 * @return array
 */
if (!function_exists('wxacg_get_user_level_legacy')) {
    function wxacg_get_user_level_legacy(int $uid): array {
        $pts = (int) get_user_meta($uid, 'anime_total_points', true);
        $levels = smacg_get_levels();
        $cur  = $levels[0];
        $next = null;
        foreach ($levels as $i => $l) {
            if ($pts >= $l['min']) {
                $cur  = $l;
                $next = $levels[$i + 1] ?? null;
            }
        }
        $pct = 100;
        if ($next) {
            $r = $next['min'] - $cur['min'];
            $e = $pts - $cur['min'];
            $pct = $r > 0 ? min(100, round($e / $r * 100)) : 100;
        }
        return [
            'points'       => $pts,
            'current'      => $cur,
            'next'         => $next,
            'progress_pct' => $pct,
        ];
    }
}

/**
 * @deprecated v1.3.0 改用 wxacg_award_exp()
 */
if (!function_exists('smacg_add_points')) {
    function smacg_add_points(int $uid, int $pts, string $reason=''): void {
        if ($pts <= 0 || !$uid) return;
        update_user_meta($uid,'anime_total_points',(int)get_user_meta($uid,'anime_total_points',true)+$pts);
        $log = json_decode(get_user_meta($uid,'anime_points_log',true)?:'[]',true);
        $log[] = ['points'=>$pts,'reason'=>$reason,'time'=>time()];
        if (count($log) > 100) $log = array_slice($log,-100);
        update_user_meta($uid,'anime_points_log',wp_json_encode($log));
    }
}

if (!function_exists('smacg_check_cooldown')) {
    function smacg_check_cooldown(int $uid, string $action, int $post_id): bool {
        $key = "smacg_cd_{$action}_{$post_id}";
        if (time()-(int)get_user_meta($uid,$key,true) < DAY_IN_SECONDS) return false;
        update_user_meta($uid,$key,time());
        return true;
    }
}

/* ============================================================
   v1.2.0：以下 hook 已移除（改由 inc/exp-events.php 處理）
   ============================================================
   - comment_post 加分
   - um_user_login 每日登入加分
   ============================================================ */

/* ============================================================
   watchlist 排序 helper
   ============================================================ */
if (!function_exists('smacg_sort_watchlist')) {
    function smacg_sort_watchlist(array $list, $sort): array {
        usort($list, fn($a, $b) => match ($sort) {
            'title'    => strcmp(get_the_title($a['post_id']), get_the_title($b['post_id'])),
            'progress' => (int)$b['progress'] <=> (int)$a['progress'],
            'year'     => (int) get_post_meta($b['post_id'], 'anime_season_year', true)
                      <=> (int) get_post_meta($a['post_id'], 'anime_season_year', true),
            default    => strcmp($b['updated'] ?? '', $a['updated'] ?? ''),
        });
        return $list;
    }
}