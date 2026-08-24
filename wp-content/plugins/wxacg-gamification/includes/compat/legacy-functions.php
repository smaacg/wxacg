<?php
/**
 * 主題模板 / 舊外掛仍使用的程序式函式名稱橋接
 *
 * v2.8.0 (2026-06-07)
 *   - smacg_get_all_exp_rules() 新增 wpForo 兩個 label：
 *     forum_topic（論壇發表主題）、forum_reply（論壇回覆）
 *
 * v2.0.0 (2026-05-15)
 *   - 改採 sqrt(exp/5) 公式 + 6 階會員稱號 + 8 職業天命之路
 *   - 廢除舊 8 階轉職稱號 / 4 職業 Career_Ajax
 *
 * v2.0.1 (2026-05-17)
 *   - smacg_ranking_user_position() 新增 rank_last_season 支援
 *
 * v2.0.2 (2026-05-22)
 *   - Fix BugE：smacg_grant_exp() 第 4 參數 $args 原本被丟棄
 *     現透傳給 Level_System::grant_exp( $uid, $amount, $reason, $args )
 *
 * v2.0.3 (2026-05-22)
 *   - Fix：smacg_get_all_exp_rules() 缺 9 個中文 label，導致 Level Guide
 *     顯示英文 key（birthday / anniversary / profile_avatar / profile_bio /
 *     profile_birthday / profile_complete / read_post / share_post / favorite_add）
 *   - 改善：cap_text 支援 daily_max（顯示「每日 N 次」而非固定「每日 1 次」）
 *   - 改善：smacg_describe_exp_cap() 接受可選 $daily_max 參數
 *
 * @package SMACG_Gamification
 */
defined( 'ABSPATH' ) || exit;

use WXACG\Gamification\Gamipress_Bridge;
use WXACG\Gamification\Level_System;
use WXACG\Gamification\Career_Jobs;
use WXACG\Gamification\Ranking_System;
use WXACG\Gamification\Rank_Season;
use WXACG\Gamification\Rank_Tier;
use WXACG\Gamification\Event_Tracker;

if ( ! defined( 'WXACG_MAX_LEVEL' ) ) define( 'WXACG_MAX_LEVEL', 200 );

/* ========================================================== GamiPress ========================================================== */
if ( ! function_exists( 'smacg_gamipress_active' ) ) {
    function smacg_gamipress_active() { return Gamipress_Bridge::active(); }
}
if ( ! function_exists( 'smacg_get_user_exp' ) ) {
    function smacg_get_user_exp( $uid ) { return Gamipress_Bridge::get_user_exp( $uid ); }
}
if ( ! function_exists( 'wxacg_award_exp' ) ) {
    function wxacg_award_exp( $uid, $amount, $reason = '', $args = [] ) {
        return Gamipress_Bridge::award_exp( $uid, $amount, $reason, $args );
    }
}
if ( ! function_exists( 'smacg_deduct_exp' ) ) {
    function smacg_deduct_exp( $uid, $amount, $reason = '' ) {
        return Gamipress_Bridge::deduct_exp( $uid, $amount, $reason );
    }
}
if ( ! function_exists( 'smacg_get_exp_log' ) ) {
    function smacg_get_exp_log( $uid, $limit = 50 ) { return Gamipress_Bridge::get_exp_log( $uid, $limit ); }
}
if ( ! function_exists( 'smacg_get_user_badge_ids' ) ) {
    function smacg_get_user_badge_ids( $uid ) { return Gamipress_Bridge::get_user_badge_ids( $uid ); }
}
if ( ! function_exists( 'smacg_get_user_badge_count' ) ) {
    function smacg_get_user_badge_count( $uid ) { return Gamipress_Bridge::get_user_badge_count( $uid ); }
}
if ( ! function_exists( 'smacg_award_badge' ) ) {
    function smacg_award_badge( $uid, $badge_post_id ) { return Gamipress_Bridge::award_badge( $uid, $badge_post_id ); }
}
if ( ! function_exists( 'wxacg_get_achievement_icon' ) ) {
    /**
     * 依成就貼文的 post_name（slug）給對應的 Font Awesome 圖示 class，
     * 沒有縮圖時取代原本統一的 fa-trophy。找不到對應項目時回退 fa-trophy。
     *
     * @param string $slug 成就貼文的 post_name，例如 badge-first-favorite
     * @return string 完整的 fa-solid fa-xxx class
     */
    function wxacg_get_achievement_icon( $slug ) {
        $map = [
            'badge-first-login'              => 'fa-right-to-bracket',
            'badge-first-follow'              => 'fa-user-plus',
            'badge-first-watchlist-add'       => 'fa-bookmark',
            'badge-first-favorite'            => 'fa-heart',
            'badge-first-comment'             => 'fa-comment',
            'badge-first-rating'              => 'fa-star',
            'badge-first-watchlist-complete'  => 'fa-flag-checkered',
            'badge-first-forum-post'          => 'fa-comments',
            'badge-first-dice-roll'           => 'fa-dice',
            'badge-first-career-change'       => 'fa-briefcase',
            'badge-first-review'              => 'fa-pen-to-square',
            'badge-first-dice-roll-wife'      => 'fa-venus',
            'badge-first-dice-roll-husband'   => 'fa-mars',
            'badge-first-dice-roll-seiyuu'    => 'fa-microphone',
        ];

        if ( isset( $map[ $slug ] ) ) {
            return 'fa-solid ' . $map[ $slug ];
        }

        /*
         * 累積型里程碑徽章（badge-watch-3、badge-review-100…）共 20 個。
         * 用前綴比對而非逐一列舉——每類四階，列舉會變成 20 行重複設定，
         * 日後調整階梯數字還得同步修改。
         *
         * 前綴不會與上面的「初次」徽章相撞：badge-favorite- 不匹配
         * badge-first-favorite，badge-rating- 也不匹配 badge-first-rating。
         */
        $milestone_icons = [
            'badge-watch-'    => 'fa-flag-checkered',
            'badge-review-'   => 'fa-pen-to-square',
            'badge-rating-'   => 'fa-star',
            'badge-favorite-' => 'fa-heart',
            'badge-streak-'   => 'fa-fire',
        ];
        foreach ( $milestone_icons as $prefix => $icon ) {
            if ( strpos( $slug, $prefix ) === 0 ) {
                return 'fa-solid ' . $icon;
            }
        }

        return 'fa-solid fa-trophy';
    }
}

/* ========================================================== Level ========================================================== */
if ( ! function_exists( 'smacg_calc_user_level' ) ) {
    function smacg_calc_user_level( $exp ) { return Level_System::calc_level_from_exp( $exp ); }
}
if ( ! function_exists( 'smacg_calc_level_from_exp' ) ) {
    function smacg_calc_level_from_exp( $exp ) { return Level_System::calc_level_from_exp( $exp ); }
}
if ( ! function_exists( 'smacg_level_to_exp' ) ) {
    function smacg_level_to_exp( $level ) { return Level_System::level_to_exp( $level ); }
}
if ( ! function_exists( 'smacg_get_level_table' ) ) {
    function smacg_get_level_table() { return Level_System::get_level_table(); }
}
if ( ! function_exists( 'smacg_get_full_level_table' ) ) {
    function smacg_get_full_level_table() { return Level_System::get_level_table(); }
}
if ( ! function_exists( 'wxacg_get_user_level_info' ) ) {
    function wxacg_get_user_level_info( $uid ) { return Level_System::get_user_level( $uid ); }
}
if ( ! function_exists( 'smacg_get_level_tier' ) ) {
    function smacg_get_level_tier( $level ) { return Level_System::get_tier( $level ); }
}
if ( ! function_exists( 'smacg_get_level_title' ) ) {
    function smacg_get_level_title( $level ) {
        $t = Level_System::get_tier( $level );
        return $t['icon'] . ' ' . $t['title'];
    }
}
if ( ! function_exists( 'smacg_get_all_member_tiers' ) ) {
    function smacg_get_all_member_tiers() { return Level_System::get_all_tiers(); }
}
if ( ! function_exists( 'smacg_grant_exp' ) ) {
    /**
     * ★ v2.0.2 BugE：透傳 $args 給 Level_System::grant_exp()
     */
    function smacg_grant_exp( $uid, $amount, $reason = '', $args = [] ) {
        return Level_System::grant_exp( $uid, $amount, $reason, is_array( $args ) ? $args : [] );
    }
}

/* ========================================================== Career Jobs ========================================================== */
if ( ! function_exists( 'smacg_get_jobs' ) ) {
    function smacg_get_jobs() { return Career_Jobs::all(); }
}
if ( ! function_exists( 'smacg_get_career_milestones' ) ) {
    function smacg_get_career_milestones() { return Career_Jobs::milestones(); }
}
if ( ! function_exists( 'smacg_get_career_stage' ) ) {
    function smacg_get_career_stage( $level ) { return Career_Jobs::career_stage( $level ); }
}
if ( ! function_exists( 'smacg_get_user_job' ) ) {
    function smacg_get_user_job( $uid ) { return Career_Jobs::user_job( $uid ); }
}
if ( ! function_exists( 'smacg_set_user_job' ) ) {
    function smacg_set_user_job( $uid, $key ) { return Career_Jobs::set_user_job( $uid, $key ); }
}
if ( ! function_exists( 'smacg_get_user_job_title' ) ) {
    function smacg_get_user_job_title( $uid ) { return Career_Jobs::user_title( $uid ); }
}

/* ========================================================== EXP event ========================================================== */
if ( ! function_exists( 'smacg_trigger_exp_event' ) ) {
    function smacg_trigger_exp_event( $uid, $action_key, $extra_args = [] ) {
        return \WXACG\Gamification\Exp_Events::award_with_cap( $uid, $action_key, $extra_args );
    }
}

/* ========================================================== Ranking ========================================================== */
if ( ! function_exists( 'smacg_ranking_get' ) ) {
    function smacg_ranking_get( $type, $page = 1, $per_page = 20 ) {
        return Ranking_System::get( $type, $page, $per_page );
    }
}
if ( ! function_exists( 'smacg_ranking_user_position' ) ) {
    function smacg_ranking_user_position( $type, $uid ) {
        if ( $type === 'rank_season' ) {
            $info = Rank_Season::get_user_info( (int) $uid );
            return $info['rank'] > 0 ? $info['rank'] : null;
        }
        if ( $type === 'rank_last_season' ) {
            return Rank_Season::get_archive_user_position( (int) $uid );
        }
        return Ranking_System::user_position( $type, $uid );
    }
}
if ( ! function_exists( 'smacg_ranking_rebuild' ) ) {
    function smacg_ranking_rebuild( $type = null ) {
        return $type ? Ranking_System::rebuild_type( $type ) : Ranking_System::rebuild_all();
    }
}
if ( ! function_exists( 'smacg_user_appears_in_ranking' ) ) {
    function smacg_user_appears_in_ranking( $uid ) {
        return get_user_meta( (int) $uid, WXACG_RANKING_META_KEY, true ) !== '0';
    }
}

/* ========================================================== Rank Season ========================================================== */
if ( ! function_exists( 'smacg_get_user_rank_season_info' ) ) {
    function smacg_get_user_rank_season_info( $uid, $season_code = null ) {
        return Rank_Season::get_user_info( (int) $uid, $season_code );
    }
}
if ( ! function_exists( 'smacg_get_rank_season_leaderboard' ) ) {
    function smacg_get_rank_season_leaderboard( $limit = 100, $offset = 0, $season_code = null ) {
        return Rank_Season::get_leaderboard( $limit, $offset, $season_code );
    }
}
if ( ! function_exists( 'smacg_get_current_season_code' ) ) {
    function smacg_get_current_season_code() { return Rank_Tier::current_season_code(); }
}
if ( ! function_exists( 'smacg_get_season_label' ) ) {
    function smacg_get_season_label( $code = null ) {
        return Rank_Tier::season_label( $code ?: Rank_Tier::current_season_code() );
    }
}
if ( ! function_exists( 'smacg_get_user_career_peak_tier' ) ) {
    function smacg_get_user_career_peak_tier( $uid ) {
        $peak = get_user_meta( (int) $uid, 'smacg_rank_career_peak', true );
        return is_array( $peak ) ? $peak : null;
    }
}

/* ========================================================== Season Event ========================================================== */
if ( ! function_exists( 'smacg_get_event_meta' ) ) {
    function smacg_get_event_meta( $event_id ) {
        return \WXACG\Gamification\Event_CPT::get_meta( $event_id );
    }
}
if ( ! function_exists( 'smacg_get_user_event_progress' ) ) {
    function smacg_get_user_event_progress( $event_id, $uid ) {
        return Event_Tracker::get_progress( $event_id, $uid );
    }
}
if ( ! function_exists( 'smacg_get_event_leaderboard' ) ) {
    function smacg_get_event_leaderboard( $event_id, $limit = 20 ) {
        return Event_Tracker::get_leaderboard( $event_id, $limit );
    }
}
if ( ! function_exists( 'smacg_get_event_progress_count' ) ) {
    function smacg_get_event_progress_count( $event_id ) {
        return Event_Tracker::get_progress_count( $event_id );
    }
}

/* ========================================================== Level Guide 頁面 ========================================================== */
if ( ! function_exists( 'smacg_get_all_exp_rules' ) ) {
    /**
     * ★ v2.0.3：補齊 9 個中文 label，並讓 cap_text 反映 daily_max
     * ★ v2.8.0：新增 wpForo forum_topic / forum_reply
     *
     * label 結構：[ icon, 中文標題, 副標說明 ]
     */
    /**
     * @param bool $include_badge_rules 是否納入徽章類規則（first_* / milestone_*）。
     *                                  預設 false，理由見下方迴圈內的說明。
     */
    function smacg_get_all_exp_rules( $include_badge_rules = false ) {
        $rules  = \WXACG\Gamification\Exp_Config::rules();
        $labels = [
            /* 帳號類 */
            'register'           => [ '🎉', '註冊帳號',       '完成註冊立即獲得' ],
            'daily_login'        => [ '📅', '每日登入',       '每天首次登入' ],
            'streak_7'           => [ '🔥', '連續登入 7 天',  '里程碑獎勵' ],
            'streak_30'          => [ '💎', '連續登入 30 天', '里程碑獎勵' ],
            'birthday'           => [ '🎂', '生日當天',       '生日當天首次登入' ],
            'anniversary'        => [ '🎊', '註冊週年',       '註冊滿一年紀念' ],

            /* 個人資料完善（一次性） */
            'profile_avatar'     => [ '🖼️', '上傳頭像',       '首次上傳個人頭像' ],
            'profile_bio'        => [ '📝', '填寫自我介紹',   '自我介紹至少 10 字' ],
            'profile_birthday'   => [ '🎂', '設定生日',       '完成生日欄位' ],
            'profile_complete'   => [ '✨', '完成個人資料',   '頭像 + 自介 + 生日全部完成' ],

            /* 內容互動 */
            'read_post'          => [ '📖', '閱讀文章',       '單篇文章閱讀完成' ],
            'share_post'         => [ '📤', '分享文章',       '透過分享按鈕分享' ],
            'comment_post'       => [ '💬', '發表評論',       '評論通過審核後' ],
            'review_long'        => [ '📝', '發表長評',       '長評至少 80 字' ],

            /* 社交 */
            'follow_action'      => [ '👥', '追蹤他人',       '追蹤其他會員' ],
            'followed_by'        => [ '⭐', '被他人追蹤',     '獲得新粉絲' ],

            /* 觀看記錄 */
            'watchlist_add'      => [ '🎬', '加入清單',       '加入想看 / 在看 / 已看' ],
            'watchlist_complete' => [ '🏁', '完成觀看',       '將動畫標為已完成' ],
            'rating_add'         => [ '⭐', '評分動畫',       '為動畫評分' ],
            'favorite_add'       => [ '❤️', '加入收藏',       '收藏喜愛的動畫' ],

            /* 徽章 */
            'badge_unlock'       => [ '🏆', '解鎖徽章',       '獲得任一徽章預設值' ],

            /* wpForo 論壇 */
            'forum_topic'        => [ '🗣️', '論壇發表主題',   '在論壇發起新主題' ],
            'forum_reply'        => [ '💭', '論壇回覆',       '在論壇回覆主題' ],
        ];

        $out = [];
        foreach ( $rules as $key => $rule ) {
            /*
             * 徽章類規則不列入「EXP 來源」。
             *
             * first_*（13 條）與 milestone_*（20 條）是由 First_Badge /
             * Milestone_Badge 透過 smacg_exp_rules filter 掛進來的，性質是
             * 「解鎖徽章時的附帶獎勵」，不是使用者可以主動執行的行為——
             * 沒有人會為了拿 first_login 而去「做 first_login」。
             * badge_tier_complete_*（5 條，集齊同稀有度成就的加碼獎勵）
             * 性質相同，一併排除。
             *
             * 它們也沒有中文 label，會落到下面的 fallback 印出原始 key，
             * 等級指南上就出現一整片「first_watchlist_add」「milestone_watch_3」。
             * 補 label 不是好解法：33 條塞進來會讓清單從 24 條膨脹到 57 條，
             * 而成就區塊本來就完整呈現了這些內容（且分入門／累積兩組）。
             *
             * 需要完整清單（例如後台除錯）時傳入 true。
             */
            if (
                ! $include_badge_rules
                && ( strpos( $key, 'first_' ) === 0 || strpos( $key, 'milestone_' ) === 0 || strpos( $key, 'badge_tier_complete_' ) === 0 )
            ) {
                continue;
            }

            /*
             * 規則可自帶 icon / label / desc，優先採用。
             *
             * 由外掛透過 smacg_exp_rules filter 注入的規則（例如
             * wxacg-social 的 correction_approved「資料回報被採納」）
             * 會把描述放在規則自己身上——本檔的對照表不可能預先知道
             * 其他外掛要加什麼。原本只查表，那些規則就落到 fallback
             * 印出原始 key。
             */
            $meta      = $labels[ $key ] ?? [ '📌', $key, '' ];
            $cap_type  = $rule['cap_type'] ?? null;
            $daily_max = isset( $rule['daily_max'] ) ? (int) $rule['daily_max'] : 1;
            $out[ $key ] = [
                'key'          => $key,
                'icon'         => $rule['icon']  ?? $meta[0],
                'label'        => $rule['label'] ?? $meta[1],
                'desc'         => $rule['desc']  ?? $meta[2],
                'exp'          => (int) $rule['exp'],
                'season_score' => isset( $rule['season_score'] ) ? (int) $rule['season_score'] : 0,
                'cap_type'     => $cap_type,
                'daily_max'    => $daily_max,
                'cap_text'     => smacg_describe_exp_cap( $cap_type, $daily_max ),
            ];
        }
        return $out;
    }
}

if ( ! function_exists( 'smacg_describe_exp_cap' ) ) {
    /**
     * ★ v2.0.3：daily 類型若 daily_max > 1，顯示「每日 N 次」
     */
    function smacg_describe_exp_cap( $cap_type, $daily_max = 1 ) {
        if ( $cap_type === 'once' )  return '一次性';
        if ( $cap_type === 'daily' ) {
            $n = max( 1, (int) $daily_max );
            return $n > 1 ? sprintf( '每日 %d 次', $n ) : '每日 1 次';
        }
        return '無上限';
    }
}

if ( ! function_exists( 'smacg_get_all_rank_tiers' ) ) {
    function smacg_get_all_rank_tiers() {
        $tiers = \WXACG\Gamification\Rank_Tier::TIERS;
        $out   = [];
        foreach ( $tiers as $row ) {
            $out[] = [
                'key'      => $row[0],
                'division' => $row[1],
                'min'      => $row[2],
                'label'    => $row[3],
                'color'    => $row[4],
                'icon'     => \WXACG\Gamification\Rank_Tier::ICONS[ $row[0] ] ?? '🎖️',
            ];
        }
        return $out;
    }
}

if ( ! function_exists( 'smacg_get_all_seasons_schedule' ) ) {
    function smacg_get_all_seasons_schedule() {
        $cur_year = (int) date( 'Y', current_time( 'timestamp' ) );
        $codes    = [];
        foreach ( [ $cur_year, $cur_year + 1 ] as $y ) {
            foreach ( [ 'spring', 'summer', 'fall', 'winter' ] as $s ) {
                $codes[] = $y . '-' . $s;
            }
        }

        $now = current_time( 'timestamp' );
        $out = [];
        foreach ( $codes as $code ) {
            [ $start, $end ] = \WXACG\Gamification\Rank_Tier::season_range( $code );
            $status = ( $now < $start ) ? 'upcoming'
                    : ( ( $now <= $end ) ? 'active' : 'ended' );
            $out[] = [
                'code'   => $code,
                'label'  => \WXACG\Gamification\Rank_Tier::season_label( $code ),
                'start'  => $start,
                'end'    => $end,
                'status' => $status,
            ];
        }
        return $out;
    }
}
