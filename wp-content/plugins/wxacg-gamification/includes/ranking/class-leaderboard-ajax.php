<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Leaderboard AJAX
 *
 * Action:  wp_ajax_smacg_get_ranking / wp_ajax_nopriv_smacg_get_ranking
 * Params:  type (string), page (int), per_page (int)
 *
 * v1.1：新增 rank_season 類型（TFT 段位賽季排行）
 * v1.2 (2026-05-17)：新增 rank_last_season 類型（上一季已結算的 archive 排行）
 *                    若 archive 尚無資料 → 回傳 empty_reason='no_settled_season'
 * v1.2.1 (2026-05-22)：
 *   - Fix BugK：rank_season / rank_last_season 分支在 wp_send_json_success() 後
 *               缺少 explicit return，雖然 wp_send_json_success() 內部會 wp_die()
 *               不會實際往下執行，但若：
 *                 1) 未來 WP 改變 wp_send_json_success 行為（不再 die）
 *                 2) 單元測試 mock 掉 wp_die()
 *               則 rank_season 之後會繼續執行 Ranking_System::get( 'rank_season' )
 *               造成 wp_send_json_success 被呼叫兩次（送出無效 JSON）。
 *               加上 return; 為防禦性程式設計。
 * v2.1.0 (2026-07-26)：
 *   - 新增：所有分支統一回傳 my_pos（登入者在該榜單的名次），
 *     供前端 Hero 區「我的名次」在切換 tab 時即時更新，
 *     修正之前 my_pos 缺欄位、只能靠 PHP 首次渲染值的限制。
 *   - 新增：rank_season 分支額外回傳 my_tier（登入者目前段位資料：
 *     icon / label / color / score / percent / to_next / next_label），
 *     供前端 Hero 區段位徽章卡片在切到「本季牌位排行」tab 時同步更新。
 *     其他 tab（exp_total / rank_last_season）不回傳 my_tier，
 *     前端會據此自動隱藏徽章卡片（「目前段位」只對本季有意義）。
 * v2.2.0 (2026-07-26)：
 *   - 新增：rank_season 分支回傳 season_end（本季結算時間戳），
 *     供前端 Hero/Tabs 區倒數計時使用。PHP 首次渲染（page-ranking-users.php）
 *     只提供保底初始值，實際顯示的倒數以每次 AJAX 回傳的 season_end 為準，
 *     避免使用者長時間停留在頁面、跨過賽季結算時間點後，
 *     前端仍顯示舊賽季的過期倒數。
 *   - rank_last_season（已結算 archive）不回傳 season_end：
 *     已結束的賽季沒有「距離結算」的概念。
 */
class Leaderboard_Ajax {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_smacg_get_ranking',        [ __CLASS__, 'handle' ] );
        add_action( 'wp_ajax_nopriv_smacg_get_ranking', [ __CLASS__, 'handle' ] );
    }

    public static function handle() {
        $type     = sanitize_text_field( $_POST['type']     ?? $_GET['type']     ?? 'exp_total' );
        $page     = max( 1, (int) ( $_POST['page']     ?? $_GET['page']     ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $_POST['per_page'] ?? $_GET['per_page'] ?? WXACG_RANKING_PAGE_SIZE ) ) );

        if ( ! in_array( $type, WXACG_RANKING_TYPES, true ) ) {
            wp_send_json_error( [ 'message' => '無效的排行榜類型' ], 400 );
        }

        // v2.1.0：統一在此算「我的名次」，讓 Hero 區塊能跟著 tab 切換即時更新
        $current_uid = get_current_user_id();
        $my_pos      = ( $current_uid && function_exists( 'smacg_ranking_user_position' ) )
            ? smacg_ranking_user_position( $type, $current_uid )
            : null;

        // 賽季排位走獨立資料源（當季）
        if ( $type === 'rank_season' ) {
            $data = self::get_rank_season( $page, $per_page );
            $data['my_pos'] = $my_pos;

            // v2.1.0：本季牌位 tab 額外回傳「我的目前段位」，供 Hero 徽章即時更新
            if ( $current_uid && function_exists( 'smacg_get_user_rank_season_info' ) ) {
                $season_info = smacg_get_user_rank_season_info( $current_uid );
                if ( $season_info ) {
                    $data['my_tier'] = [
                        'key'        => $season_info['tier']['key']   ?? '',
                        'label'      => $season_info['tier']['label'] ?? '',
                        'icon'       => $season_info['tier']['icon']  ?? '',
                        'color'      => $season_info['tier']['color'] ?? '',
                        'score'      => $season_info['score']         ?? 0,
                        'percent'    => $season_info['progress']['percent']    ?? 0,
                        'is_max'     => $season_info['progress']['is_max']     ?? false,
                        'to_next'    => $season_info['progress']['to_next']    ?? 0,
                        'next_label' => $season_info['progress']['next_label'] ?? '',
                    ];
                }
            }

            wp_send_json_success( $data );
            return; // ★ v1.2.1 BugK：防禦性 return
        }

        // v1.2：上一季排位走 archive 資料源
        if ( $type === 'rank_last_season' ) {
            $data = self::get_rank_last_season( $page, $per_page );
            $data['my_pos'] = $my_pos;
            // 上季 tab 不回傳 my_tier / season_end：兩者都只對「本季」有意義
            wp_send_json_success( $data );
            return; // ★ v1.2.1 BugK：防禦性 return
        }

        $result = Ranking_System::get( $type, $page, $per_page );
        $result['my_pos'] = $my_pos;
        wp_send_json_success( $result );
    }

    /**
     * ★ v2.2.0 新增：取得本季結算時間戳（給前端倒數用）
     *
     * 從 smacg_get_all_seasons_schedule() 找出 status === 'active' 的賽季，
     * 回傳其 end（unix timestamp）。找不到則回傳 0（前端會隱藏倒數）。
     */
    private static function get_current_season_end_ts() {
        if ( function_exists( 'smacg_get_all_seasons_schedule' ) ) {
            foreach ( smacg_get_all_seasons_schedule() as $s ) {
                if ( ( $s['status'] ?? '' ) === 'active' ) {
                    return (int) ( $s['end'] ?? 0 );
                }
            }
        }
        return 0;
    }

    /**
     * 賽季排位 leaderboard（當季）
     */
    private static function get_rank_season( $page, $per_page ) {
        $offset = ( $page - 1 ) * $per_page;
        $rows   = Rank_Season::get_leaderboard( $per_page, $offset );

        $items = [];
        foreach ( $rows as $r ) {
            $u = get_userdata( $r['user_id'] );
            if ( ! $u ) continue;

            $items[] = [
                'rank_pos'    => $r['rank'],
                'user_id'     => $r['user_id'],
                'score'       => $r['score'],
                'display_name'=> $u->display_name ?: $u->user_login,
                'avatar_url'  => get_avatar_url( $r['user_id'], [ 'size' => 64 ] ),
                'profile_url' => function_exists( 'wxacg_get_public_profile_url' )
                    ? wxacg_get_public_profile_url( $u )
                    : home_url( '/u/' . urlencode( $u->user_login ) . '/' ),
                'extra' => [
                    'tier_key'   => $r['tier']['key'],
                    'tier_label' => $r['tier']['label'],
                    'tier_icon'  => $r['tier']['icon'],
                    'tier_color' => $r['tier']['color'],
                ],
            ];
        }

        return [
            'type'        => 'rank_season',
            'season_code' => Rank_Tier::current_season_code(),
            'season_label'=> Rank_Tier::season_label( Rank_Tier::current_season_code() ),
            'season_end'  => self::get_current_season_end_ts(), // ★ v2.2.0 新增
            'page'        => $page,
            'per_page'    => $per_page,
            'items'       => $items,
            'total'       => self::get_rank_season_total(),
        ];
    }

    private static function get_rank_season_total() {
        global $wpdb;
        $tbl  = Rank_Season::table_current();
        $code = Rank_Tier::current_season_code();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE season_code=%s AND season_score > 0",
            $code
        ) );
    }

    /**
     * 上一季排位 leaderboard（archive）— v1.2
     *
     * 從 wp_smacg_rank_season_archive 撈最近一個結算過的賽季資料；
     * 若 archive 為空（系統還沒經歷過任何換季）→ 回傳 empty_reason='no_settled_season'
     */
    private static function get_rank_last_season( $page, $per_page ) {
        $offset = ( $page - 1 ) * $per_page;
        $data   = Rank_Season::get_archive_leaderboard( null, $per_page, $offset );

        // 尚無已結算賽季
        if ( empty( $data['season_code'] ) ) {
            return [
                'type'         => 'rank_last_season',
                'season_code'  => '',
                'season_label' => '',
                'page'         => 1,
                'per_page'     => $per_page,
                'items'        => [],
                'total'        => 0,
                'empty_reason' => 'no_settled_season',
                'empty_message'=> '本賽季尚未結束，等到結算後就能看到完整的上季排行～',
            ];
        }

        $items = [];
        foreach ( $data['items'] as $r ) {
            $u = get_userdata( $r['user_id'] );
            if ( ! $u ) continue;

            $items[] = [
                'rank_pos'    => $r['rank'],
                'user_id'     => $r['user_id'],
                'score'       => $r['score'],
                'display_name'=> $u->display_name ?: $u->user_login,
                'avatar_url'  => get_avatar_url( $r['user_id'], [ 'size' => 64 ] ),
                'profile_url' => function_exists( 'wxacg_get_public_profile_url' )
                    ? wxacg_get_public_profile_url( $u )
                    : home_url( '/u/' . urlencode( $u->user_login ) . '/' ),
                'extra' => [
                    'tier_key'   => $r['tier']['key'],
                    'tier_label' => $r['tier']['label'],
                    'tier_icon'  => $r['tier']['icon'],
                    'tier_color' => $r['tier']['color'],
                ],
            ];
        }

        return [
            'type'         => 'rank_last_season',
            'season_code'  => $data['season_code'],
            'season_label' => $data['season_label'],
            'page'         => $page,
            'per_page'     => $per_page,
            'items'        => $items,
            'total'        => (int) $data['total'],
        ];
    }
}