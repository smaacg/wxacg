<?php
/**
 * 會員自訂動漫排行 — EXP 與成就
 *
 * 排行本身只是「排完清單、貼出去」，缺少繼續回來的理由。站上已有完整的
 * EXP／徽章系統，接上去的成本只有一個 filter 加一個 hook，卻能讓這個功能
 * 併入會員原本就在意的成長迴圈。
 *
 * 刻意不對「編輯既有排行」給分——否則反覆按儲存就能刷分。只有「建立新的
 * 排行」與「第一次把排行填滿」才給，兩者都是一次性的實質進展。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ============================================================
   1. 注入 EXP 規則
   ============================================================ */
add_filter( 'smacg_exp_rules', function ( array $rules ): array {

    if ( ! isset( $rules['toplist_created'] ) ) {
        $rules['toplist_created'] = [
            'icon'         => 'fa-trophy',
            'label'        => '建立動漫排行',
            'desc'         => '建立一份自訂的動漫排行清單',
            'exp'          => 300,
            'season_score' => 0,
            'cap_type'     => 'daily',
            'cap_key'      => 'toplist_created',
            // 每人最多 5 個排行，每日上限 2 個已足夠，也擋掉建了刪、刪了建的刷分
            'daily_max'    => 2,
        ];
    }

    if ( ! isset( $rules['toplist_completed'] ) ) {
        $rules['toplist_completed'] = [
            'icon'         => 'fa-ranking-star',
            'label'        => '排行填滿',
            'desc'         => '把一份排行填到設定的名次上限',
            'exp'          => 500,
            'season_score' => 0,
            'cap_type'     => 'daily',
            'cap_key'      => 'toplist_completed',
            'daily_max'    => 2,
        ];
    }

    return $rules;
} );

/* ============================================================
   2. 中文原因標籤（點數紀錄頁才不會顯示英文 action key）
   ============================================================ */
add_filter( 'smacg_exp_reason_labels', function ( array $labels ): array {
    $labels['toplist_created']   = '建立動漫排行';
    $labels['toplist_completed'] = '排行填滿';
    return $labels;
} );

/* ============================================================
   3. 儲存排行時發放
   ============================================================ */
add_action( 'wxacg_toplist_saved', function ( int $user_id, int $list_id, bool $is_new ): void {

    if ( ! $user_id || ! function_exists( 'smacg_trigger_exp_event' ) ) {
        return;
    }

    /* ---- 3a. 建立新排行 ---- */
    if ( $is_new ) {
        smacg_trigger_exp_event( $user_id, 'toplist_created', [
            'object_id'  => $list_id,
            // dedupe_key 讓同一個清單 ID 永遠只給一次，即使刪掉重建也不會
            // 拿到相同 ID（wxacg_toplist_save() 的 ID 不重用）
            'dedupe_key' => 'wxacg_toplist_new_' . $user_id . '_' . $list_id,
        ] );
    }

    /* ---- 3b. 第一次填滿 ---- */
    $list = wxacg_toplist_get( $user_id, $list_id );
    if ( ! $list ) {
        return;
    }

    if ( count( $list['items'] ) < (int) $list['size'] ) {
        return;
    }

    smacg_trigger_exp_event( $user_id, 'toplist_completed', [
        'object_id'  => $list_id,
        // 同一個清單只給一次；改成 TOP20 再填滿也不會重複發
        'dedupe_key' => 'wxacg_toplist_full_' . $user_id . '_' . $list_id,
    ] );

    /**
     * 排行已填滿。
     *
     * 供徽章掛載——GamiPress 的成就可以直接監聽這個 action，不必改本檔。
     *
     * @param int $user_id 會員 ID。
     * @param int $list_id 清單 ID。
     */
    do_action( 'wxacg_toplist_completed', $user_id, $list_id );

}, 10, 3 );
