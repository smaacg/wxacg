<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Career Ajax — 職業選擇 AJAX endpoint
 *
 * Endpoint: wp_ajax_smacg_select_career
 * Nonce:    smacg_career_nonce
 *
 * 規則：
 *   - 需登入
 *   - Lv ≥ 10 才能選
 *   - 從 Career_Jobs::all() 的 8 職業擇一
 *   - 一旦選擇，3 個月內不可變更
 *
 * @since 2.0.0 (2026-05-15) 取代舊 4 職業版
 */
class Career_Ajax {

    private static $instance = null;

    /** 變更冷卻（3 個月） */
    const CHANGE_COOLDOWN = 3 * MONTH_IN_SECONDS;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_smacg_select_career', [ __CLASS__, 'handle' ] );
    }

    public static function handle() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => '請先登入' ], 401 );
        }
        if ( ! check_ajax_referer( 'smacg_career_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Nonce 驗證失敗' ], 403 );
        }

        $uid = get_current_user_id();

        /* 等級檢查（Lv 10 才能選） */
        $info = Level_System::get_user_level( $uid );
        if ( (int) $info['level'] < 10 ) {
            wp_send_json_error( [
                'message' => '需達到 Lv.10 才能選擇職業',
                'level'   => (int) $info['level'],
            ], 400 );
        }

        /* 已選 → 檢查冷卻 */
        $existing = Career_Jobs::user_job( $uid );
        if ( $existing ) {
            $chosen_at = (string) get_user_meta( $uid, 'smacg_job_chosen_at', true );
            $chosen_ts = $chosen_at ? strtotime( $chosen_at ) : 0;
            $remain    = $chosen_ts ? ( $chosen_ts + self::CHANGE_COOLDOWN - time() ) : 0;

            if ( $remain > 0 ) {
                wp_send_json_error( [
                    'message'      => sprintf(
                        '已選擇職業，%d 天後才能變更',
                        (int) ceil( $remain / DAY_IN_SECONDS )
                    ),
                    'current'      => $existing,
                    'cooldown_end' => $chosen_ts + self::CHANGE_COOLDOWN,
                ], 400 );
            }
        }

        /* 驗證職業 key */
        $job_key = sanitize_key( $_POST['career'] ?? '' );
        $jobs    = Career_Jobs::all();
        if ( ! isset( $jobs[ $job_key ] ) ) {
            wp_send_json_error( [ 'message' => '無效的職業代碼' ], 400 );
        }

        /* 寫入 */
        $ok = Career_Jobs::set_user_job( $uid, $job_key );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => '寫入失敗，請稍後再試' ], 500 );
        }

        $title = Career_Jobs::user_title( $uid );

        // 給成就系統掛勾（初次轉職），選擇/變更職業都會觸發，
        // 但實際只有第一次成功會拿到獎勵（見 First_Badge 的 once-cap）。
        do_action( 'smacg_career_selected', $uid, $job_key, (bool) $existing );

        wp_send_json_success( [
            'message'    => sprintf( '已選擇職業：%s', $jobs[ $job_key ]['label'] ),
            'job_key'    => $job_key,
            'job_label'  => $jobs[ $job_key ]['label'],
            'job_icon'   => $jobs[ $job_key ]['icon'],
            'title_name' => $title['title_name'] ?? '',
            'title_ref'  => $title['title_ref'] ?? '',
            'is_change'  => (bool) $existing,
        ] );
    }
}
