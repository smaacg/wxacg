<?php
/**
 * SMACG Social — Corrections 通知 + 經驗值
 *
 * 監聽 corrections-admin.php 觸發的 hook：
 *   - wxacg_correction_resolved  → 發「已採納」通知 + 送 EXP
 *   - wxacg_correction_rejected  → 發「未採納」通知（不給 EXP）
 *
 * EXP 規則透過 add_filter('smacg_exp_rules') 注入，不改 gamification 插件。
 * 依賴：
 *   - wxacg_create_notification()  (notifications-system.php)
 *   - smacg_trigger_exp_event()    (wxacg-gamification plugin)
 *
 * @version 1.0.0
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

/* ============================================================
   1. 注入 EXP 規則：correction_approved
   ============================================================ */
add_filter( 'smacg_exp_rules', function ( array $rules ): array {
    if ( ! isset( $rules['correction_approved'] ) ) {
        $rules['correction_approved'] = [
            'icon'         => 'fa-flag-checkered',
            'label'        => '資料回報被採納',
            'desc'         => '回報的資料錯誤經審核採納',
            'exp'          => 666,          // 一般經驗值
            'season_score' => 0,            // 不計賽季積分
            'cap_type'     => 'daily',
            'cap_key'      => 'correction',
            'daily_max'    => 5,            // 每日最多 5 筆
        ];
    }
    return $rules;
} );

/* ============================================================
   2. 已採納：發通知 + 送 EXP
   ============================================================ */
add_action( 'wxacg_correction_resolved', function ( int $post_id, int $reporter, int $anime_id ): void {

    if ( ! $reporter ) {
        return;
    }

    // 防重複發放：已發過就跳過
    if ( get_post_meta( $post_id, '_wxacg_corr_exp_awarded', true ) === '1' ) {
        return;
    }

    $cat_key   = get_post_meta( $post_id, '_wxacg_corr_category', true );
    $cat_label = Corrections_CPT::category_label( $cat_key );
    $title     = $anime_id ? get_the_title( $anime_id ) : '';
    $url       = $anime_id ? get_permalink( $anime_id ) : home_url( '/' );

    /* ---- 2a. 送經驗值（走現有 gamification 事件系統）---- */
    if ( function_exists( 'smacg_trigger_exp_event' ) ) {
        // daily cap 由 gamification 的 cap 機制把關，這裡直接觸發
        smacg_trigger_exp_event( $reporter, 'correction_approved', [
            'object_id' => $post_id,
        ] );
    }

    /* ---- 2b. 發站內通知 ---- */
    if ( function_exists( 'wxacg_create_notification' ) ) {
        wxacg_create_notification( [
            'user_id'     => $reporter,
            'type'        => 'correction_resolved',
            'object_type' => 'anime',
            'object_id'   => $anime_id,
            'data'        => [
                'title'   => '你的資料回報已被採納 🎉',
                'excerpt' => sprintf(
                    '「%s」的%s回報已採納，感謝你的貢獻！已送上 666 經驗值。',
                    $title,
                    $cat_label
                ),
                'url'     => $url,
                'icon'    => 'fa-flag-checkered',
            ],
            'force'       => true,   // 強制發送，忽略使用者通知偏好
        ] );
    }

    // 標記已發放，避免重複點「已修正」重複給分
    update_post_meta( $post_id, '_wxacg_corr_exp_awarded', '1' );

}, 10, 3 );

/* ============================================================
   3. 已駁回：發通知（不給 EXP）
   ============================================================ */
add_action( 'wxacg_correction_rejected', function ( int $post_id, int $reporter, string $reason ): void {

    if ( ! $reporter || ! function_exists( 'wxacg_create_notification' ) ) {
        return;
    }

    $anime_id = (int) get_post_meta( $post_id, '_wxacg_corr_anime_id', true );
    $title    = $anime_id ? get_the_title( $anime_id ) : '';
    $url      = $anime_id ? get_permalink( $anime_id ) : home_url( '/' );

    $excerpt = $reason
        ? sprintf( '「%s」的回報未採納：%s', $title, $reason )
        : sprintf( '「%s」的回報經審核後未採納，仍感謝你的參與。', $title );

    wxacg_create_notification( [
        'user_id'     => $reporter,
        'type'        => 'correction_rejected',
        'object_type' => 'anime',
        'object_id'   => $anime_id,
        'data'        => [
            'title'   => '你的資料回報結果',
            'excerpt' => $excerpt,
            'url'     => $url,
            'icon'    => 'fa-info-circle',
        ],
        'force'       => true,
    ] );

}, 10, 3 );
