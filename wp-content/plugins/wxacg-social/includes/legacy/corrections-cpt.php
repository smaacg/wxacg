<?php
/**
 * SMACG Social — Corrections（資料回報）CPT
 *
 * 會員回報 anime 條目資料錯誤，進待審區由管理員審核。
 * 待審記錄用自訂 post type wxacg_correction 儲存（public=false，僅後台可見）。
 * 完全不碰正式 anime 資料，審核通過後由管理員手動修改正式欄位。
 *
 * Meta keys（_wxacg_corr_* 前綴）：
 *   _wxacg_corr_anime_id    int     被回報的 anime post ID
 *   _wxacg_corr_category    string  回報類別（basic/synopsis/staff/cast/theme/episodes/other）
 *   _wxacg_corr_detail      text    會員描述的問題內容
 *   _wxacg_corr_source      string  選填的參考來源網址
 *   _wxacg_corr_reporter    int     回報會員的 user ID
 *   _wxacg_corr_status      string  pending / resolved / rejected
 *   _wxacg_corr_admin_note  text    管理員處理備註（駁回原因等）
 *   _wxacg_corr_exp_awarded '1'     已發過 EXP 的標記（防重複發放）
 *
 * @version 1.2.0  修正選單氣泡：改用 admin_menu 注入原生氣泡，避免 HTML 被轉義成亂碼
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

final class Corrections_CPT {

    const POST_TYPE = 'wxacg_correction';

    /** 回報類別 */
    const CATEGORIES = [
        'basic'    => '基本資訊錯誤',
        'synopsis' => '故事簡介錯誤',
        'staff'    => 'STAFF 錯誤',
        'cast'     => 'CAST 錯誤',
        'theme'    => '主題曲錯誤',
        'episodes' => '集數列表錯誤',
        'other'    => '其他',
    ];

    private static ?Corrections_CPT $instance = null;

    public static function instance(): Corrections_CPT {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ __CLASS__, 'register' ], 10 );
        // 用 admin_menu 注入待處理氣泡（不要塞進 labels，否則會被轉義成亂碼）
        add_action( 'admin_menu', [ __CLASS__, 'add_menu_bubble' ], 999 );
    }

    /** 目前待處理（pending）筆數 */
    public static function pending_count(): int {
        $q = new \WP_Query( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [ [ 'key' => '_wxacg_corr_status', 'value' => 'pending' ] ],
        ] );
        return (int) $q->found_posts;
    }

    public static function register(): void {

        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => '修正建議',
                'singular_name' => '修正建議',
                'menu_name'     => '修正建議',   // 純文字，氣泡另由 add_menu_bubble() 注入
                'all_items'     => '所有回報',
                'edit_item'     => '檢視回報',
                'search_items'  => '搜尋回報',
                'not_found'     => '目前沒有回報',
            ],
            'public'              => false,   // 不對外公開
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,    // 後台可見
            'show_in_menu'        => true,
            'show_in_rest'        => false,
            'menu_icon'           => 'dashicons-flag',
            'menu_position'       => 27,      // 排在 gamification（26）之後
            'has_archive'         => false,
            'rewrite'             => false,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,

            /*
             * 這個 CPT 只用來承接前台使用者送出的回報，後台不應該手動新增。
             * create_posts 設為 do_not_allow 會一併移除「新增文章」子選單、
             * 列表頁的「新增」按鈕，並擋掉直接開 post-new.php 的路徑——
             * 不只是把選單藏起來而已。
             *
             * 前台提交不受影響：corrections-form.php 的 AJAX handler 是用
             * wp_insert_post() 直接建立，不經過 create_posts 檢查。
             * 檢視／編輯／刪除既有回報也不受影響，仍沿用 post 的預設權限。
             */
            'capabilities'        => [
                'create_posts' => 'do_not_allow',
            ],
        ] );
    }

    /**
     * 在後台選單標題注入待處理數字氣泡（WordPress 原生氣泡樣式）。
     * 直接改全域 $menu 陣列的標題，HTML 不會被轉義，可正常顯示紅色氣泡。
     */
    public static function add_menu_bubble(): void {
        global $menu;
        if ( ! is_array( $menu ) ) {
            return;
        }

        $pending = self::pending_count();
        if ( $pending < 1 ) {
            return;
        }

        $slug   = 'edit.php?post_type=' . self::POST_TYPE;
        $bubble = sprintf(
            ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
            $pending
        );

        foreach ( $menu as $key => $item ) {
            // $item[2] 為選單 slug
            if ( isset( $item[2] ) && $item[2] === $slug ) {
                $menu[ $key ][0] .= $bubble;
                break;
            }
        }
    }

    /** 類別 key → 中文標籤 */
    public static function category_label( string $key ): string {
        return self::CATEGORIES[ $key ] ?? $key;
    }
}
