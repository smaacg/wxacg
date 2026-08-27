<?php
/**
 * 會員自訂動漫排行 — 資料層
 *
 * 會員可以建立有主題的排行清單（例如「催淚動漫推薦 前10」），排好順序後
 * 分享到站外。分享網址是永久的，所以一開始就設計成多清單 + 具名 id。
 *
 * 為什麼存 user_meta 而不建資料表：
 *   排行本質是「一串有順序的作品 ID」，而且每人上限 5 個清單、每清單上限
 *   20 部，序列化後不過幾百 bytes。存 user_meta 省掉建表、class-installer
 *   的 migration 與升級相容處理。等到需要「誰把某部排第一」這種反向查詢
 *   （全站聚合票選）時再評估建表，屆時資料可以直接從 user_meta 匯出。
 *
 * 資料結構（user_meta wxacg_toplists）：
 *   [
 *     [ 'id' => 1, 'title' => '催淚動漫推薦', 'size' => 10,
 *       'items' => [ 123, 456, ... ], 'public' => true,
 *       'created' => 'Y-m-d H:i:s', 'updated' => 'Y-m-d H:i:s' ],
 *     ...
 *   ]
 *   陣列中 items 的順序即名次，不另存 position 欄位。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** user_meta 的鍵名 */
if ( ! defined( 'WXACG_TOPLIST_META' ) ) {
    define( 'WXACG_TOPLIST_META', 'wxacg_toplists' );
}

/** 每人最多幾個清單 */
if ( ! defined( 'WXACG_TOPLIST_MAX' ) ) {
    define( 'WXACG_TOPLIST_MAX', 5 );
}

/** 清單標題的最大字數（以字元計，非 bytes——中文標題才不會被砍） */
if ( ! defined( 'WXACG_TOPLIST_TITLE_MAX' ) ) {
    define( 'WXACG_TOPLIST_TITLE_MAX', 30 );
}

/**
 * AJAX 用的 nonce action 名稱。
 *
 * 定義在資料層而不是 toplist-ajax.php：編輯器要用它產生 nonce、AJAX 端點
 * 要用它驗證，兩邊都相依於此。放在只有其中一方載入的檔案裡，就會變成
 * 隱性的載入順序相依——實測 toplist-editor.php 單獨載入時會直接 fatal。
 */
if ( ! defined( 'WXACG_TOPLIST_NONCE' ) ) {
    define( 'WXACG_TOPLIST_NONCE', 'wxacg_toplist_nonce' );
}

/**
 * 允許的清單長度選項。
 *
 * 會員可自行在 10 與 20 之間切換，預設 10——TOP 10 好懂，文字版貼到留言板
 * 的長度也剛好。
 */
function wxacg_toplist_sizes(): array {
    return (array) apply_filters( 'wxacg_toplist_sizes', [ 10, 20 ] );
}

/**
 * 可以被排進清單的文章類型。
 *
 * 用 filter 開放，日後要讓漫畫也能排進去不必改這裡。
 */
function wxacg_toplist_post_types(): array {
    return (array) apply_filters( 'wxacg_toplist_post_types', [ 'anime' ] );
}

/**
 * 取出某位會員的全部排行清單。
 *
 * 一律回傳結構完整的陣列——舊資料缺欄位時補上預設值，呼叫端不必到處
 * 寫 ?? 判斷。
 *
 * @param int $user_id 會員 ID。
 * @return array 清單陣列，沒有時回傳空陣列。
 */
function wxacg_toplist_get_all( int $user_id ): array {
    if ( $user_id <= 0 ) {
        return [];
    }

    $raw = get_user_meta( $user_id, WXACG_TOPLIST_META, true );
    if ( ! is_array( $raw ) ) {
        return [];
    }

    $out = [];
    foreach ( $raw as $row ) {
        if ( ! is_array( $row ) || empty( $row['id'] ) ) {
            continue;
        }

        $out[] = [
            'id'      => (int) $row['id'],
            'title'   => (string) ( $row['title'] ?? '' ),
            'size'    => (int) ( $row['size'] ?? 10 ),
            'items'   => array_values( array_map( 'intval', (array) ( $row['items'] ?? [] ) ) ),
            'public'  => ! empty( $row['public'] ),
            'created' => (string) ( $row['created'] ?? '' ),
            'updated' => (string) ( $row['updated'] ?? '' ),
        ];
    }

    return $out;
}

/**
 * 取出單一排行清單。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 * @return array|null 找不到時回傳 null。
 */
function wxacg_toplist_get( int $user_id, int $list_id ): ?array {
    foreach ( wxacg_toplist_get_all( $user_id ) as $list ) {
        if ( $list['id'] === $list_id ) {
            return $list;
        }
    }

    return null;
}

/**
 * 清洗並驗證要排進清單的作品 ID。
 *
 * 三道處理，順序有意義：
 *   1. 去重——同一部只能出現一次，否則名次會有歧義。
 *   2. 只留實際存在且已發佈的允許類型文章——避免草稿、已刪除或被塞入
 *      其他 post type 的 ID 混進來（前端傳來的值一律不可信）。
 *   3. 截斷到 size——超過上限的直接丟棄，不報錯。
 *
 * @param array $ids  原始 ID 陣列。
 * @param int   $size 長度上限。
 * @return array 清洗後的 ID 陣列。
 */
function wxacg_toplist_sanitize_items( array $ids, int $size ): array {
    $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    if ( ! $ids ) {
        return [];
    }

    $allowed = wxacg_toplist_post_types();
    $clean   = [];

    foreach ( $ids as $id ) {
        if ( count( $clean ) >= $size ) {
            break;
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            continue;
        }
        if ( ! in_array( $post->post_type, $allowed, true ) ) {
            continue;
        }

        $clean[] = $id;
    }

    return $clean;
}

/**
 * 清洗清單標題。
 *
 * 空標題會退回預設值而不是拒絕儲存——使用者只是還沒想好名字，不該因此
 * 丟失他已經排好的順序。
 *
 * @param string $title 原始標題。
 * @return string 清洗後的標題。
 */
function wxacg_toplist_sanitize_title( string $title ): string {
    $title = trim( sanitize_text_field( $title ) );

    if ( $title === '' ) {
        return '我的動漫排行';
    }

    if ( mb_strlen( $title ) > WXACG_TOPLIST_TITLE_MAX ) {
        $title = mb_substr( $title, 0, WXACG_TOPLIST_TITLE_MAX );
    }

    return $title;
}

/**
 * 建立或更新一個排行清單。
 *
 * $data['id'] 有值且該清單存在時視為更新，否則視為新增。
 *
 * @param int   $user_id 會員 ID。
 * @param array $data    [ 'id'?, 'title', 'size', 'items', 'public' ]。
 * @return int|WP_Error 成功回傳清單 ID。
 */
function wxacg_toplist_save( int $user_id, array $data ) {
    if ( $user_id <= 0 ) {
        return new WP_Error( 'wxacg_toplist_no_user', '未指定會員' );
    }

    $lists = wxacg_toplist_get_all( $user_id );
    $now   = current_time( 'mysql' );

    $size = (int) ( $data['size'] ?? 10 );
    if ( ! in_array( $size, wxacg_toplist_sizes(), true ) ) {
        $size = 10;
    }

    $list_id = (int) ( $data['id'] ?? 0 );
    $index   = null;

    if ( $list_id > 0 ) {
        foreach ( $lists as $i => $row ) {
            if ( $row['id'] === $list_id ) {
                $index = $i;
                break;
            }
        }
    }

    // 找不到對應清單時視為新增；此時才檢查數量上限
    if ( $index === null ) {
        if ( count( $lists ) >= WXACG_TOPLIST_MAX ) {
            return new WP_Error(
                'wxacg_toplist_limit',
                sprintf( '最多只能建立 %d 個排行清單', WXACG_TOPLIST_MAX )
            );
        }

        // 新 ID 取現有最大值 +1，不重用已刪除的 ID——否則舊分享網址會指向
        // 完全不同的清單內容。
        $max_id  = 0;
        foreach ( $lists as $row ) {
            $max_id = max( $max_id, $row['id'] );
        }
        $list_id = $max_id + 1;

        $lists[] = [
            'id'      => $list_id,
            'title'   => wxacg_toplist_sanitize_title( (string) ( $data['title'] ?? '' ) ),
            'size'    => $size,
            'items'   => wxacg_toplist_sanitize_items( (array) ( $data['items'] ?? [] ), $size ),
            'public'  => ! empty( $data['public'] ),
            'created' => $now,
            'updated' => $now,
        ];
    } else {
        $lists[ $index ]['title']   = wxacg_toplist_sanitize_title( (string) ( $data['title'] ?? $lists[ $index ]['title'] ) );
        $lists[ $index ]['size']    = $size;
        $lists[ $index ]['items']   = wxacg_toplist_sanitize_items( (array) ( $data['items'] ?? [] ), $size );
        $lists[ $index ]['public']  = ! empty( $data['public'] );
        $lists[ $index ]['updated'] = $now;
    }

    update_user_meta( $user_id, WXACG_TOPLIST_META, $lists );

    /**
     * 排行清單已儲存。
     *
     * 供 EXP／徽章與分享卡片產生掛載，資料層本身不處理那些副作用。
     *
     * @param int   $user_id 會員 ID。
     * @param int   $list_id 清單 ID。
     * @param bool  $is_new  是否為新建立。
     */
    do_action( 'wxacg_toplist_saved', $user_id, $list_id, $index === null );

    return $list_id;
}

/**
 * 刪除一個排行清單。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 * @return bool 是否真的刪掉了。
 */
function wxacg_toplist_delete( int $user_id, int $list_id ): bool {
    $lists = wxacg_toplist_get_all( $user_id );
    $kept  = [];
    $found = false;

    foreach ( $lists as $row ) {
        if ( $row['id'] === $list_id ) {
            $found = true;
            continue;
        }
        $kept[] = $row;
    }

    if ( ! $found ) {
        return false;
    }

    update_user_meta( $user_id, WXACG_TOPLIST_META, $kept );

    /**
     * 排行清單已刪除。
     *
     * @param int $user_id 會員 ID。
     * @param int $list_id 清單 ID。
     */
    do_action( 'wxacg_toplist_deleted', $user_id, $list_id );

    return true;
}

/**
 * 某個清單是否可以被指定的瀏覽者看到。
 *
 * 兩層判斷：清單自己的 public 旗標，以及會員的公開檔案設定——公開檔案
 * 關閉時，連帶所有清單都不對外顯示，不能只看清單自己的設定。
 *
 * @param array $list     清單資料。
 * @param int   $owner_id 清單擁有者。
 * @param int   $viewer_id 瀏覽者，0 代表未登入。
 */
function wxacg_toplist_can_view( array $list, int $owner_id, int $viewer_id = 0 ): bool {
    // 本人永遠看得到自己的清單
    if ( $viewer_id > 0 && $viewer_id === $owner_id ) {
        return true;
    }

    if ( empty( $list['public'] ) ) {
        return false;
    }

    // 尊重會員的公開檔案總開關（與 public-profile.php 用的是同一份設定）
    if ( function_exists( 'smacg_get_user_privacy' ) ) {
        $privacy = smacg_get_user_privacy( $owner_id );
        if ( empty( $privacy['public_profile'] ) ) {
            return false;
        }
    }

    return true;
}

/**
 * 目前這個請求如果是在看單一排行，回傳它的分享中繼資料。
 *
 * 抽出來共用是必要的：站上同時有兩條輸出路徑——public-profile.php 自己
 * printf 的 og:* 標籤，以及 Rank Math 透過 filter 產生的那一組。兩邊各自
 * 計算的話會輸出內容不一致的重複標籤，而社群平台通常只取第一個，結果
 * 就是卡片顯示個人頁標題而不是排行名稱。
 *
 * @return array|null [ title, desc, image, url, list ]；不是排行頁時回傳 null。
 */
function wxacg_toplist_current_meta(): ?array {
    $list_id = (int) get_query_var( 'smacg_pp_toplist' );
    if ( $list_id <= 0 ) {
        return null;
    }

    $user = function_exists( 'wxacg_get_public_profile_user' ) ? wxacg_get_public_profile_user() : null;
    if ( ! $user ) {
        return null;
    }

    $list = wxacg_toplist_get( $user->ID, $list_id );
    if ( ! $list || ! wxacg_toplist_can_view( $list, $user->ID, get_current_user_id() ) ) {
        return null;
    }

    $display = $user->display_name ?: $user->user_login;

    // 描述放前 5 名——夠讓人一眼判斷合不合胃口，又不會超過各平台的截斷長度
    $names = [];
    foreach ( array_slice( $list['items'], 0, 5 ) as $i => $pid ) {
        $t = get_the_title( (int) $pid );
        if ( $t !== '' ) {
            $names[] = ( $i + 1 ) . '. ' . $t;
        }
    }

    $count = count( $list['items'] );

    /*
     * 卡片圖三層退路：
     *   1. 前三名合成的 1200×630 橫式拼圖（社群大圖卡的正確比例）
     *   2. 第一名的封面（直式，會被平台裁切但至少相關）
     *   3. 會員頭像
     */
    $image = '';
    if ( function_exists( 'wxacg_toplist_card_url' ) ) {
        $image = wxacg_toplist_card_url( $user->ID, $list_id );
    }
    if ( $image === '' && ! empty( $list['items'][0] ) ) {
        $image = (string) get_the_post_thumbnail_url( (int) $list['items'][0], 'full' );
    }
    if ( $image === '' ) {
        $image = (string) get_avatar_url( $user->ID, [ 'size' => 400 ] );
    }

    return [
        'title' => sprintf( '%s TOP%d — %s', $list['title'], $count, $display ),
        'desc'  => $names
            ? implode( '　', $names ) . ( $count > 5 ? '…' : '' )
            : sprintf( '%s 在 %s 的動漫排行', $display, get_bloginfo( 'name' ) ),
        'image' => $image,
        'url'   => wxacg_toplist_permalink( $user->ID, $list_id ),
        'list'  => $list,
    ];
}

/**
 * 取得某個清單的公開網址。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 */
function wxacg_toplist_permalink( int $user_id, int $list_id ): string {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return home_url( '/' );
    }

    // 直接接在既有的公開檔案網址後面，不自己拼 slug——SMACG_PUBLIC_PROFILE_SLUG
    // 若日後調整，這裡會自動跟著。
    if ( function_exists( 'wxacg_get_public_profile_url' ) ) {
        return trailingslashit( wxacg_get_public_profile_url( $user ) ) . 'toplist/' . $list_id . '/';
    }

    $slug = defined( 'SMACG_PUBLIC_PROFILE_SLUG' ) ? SMACG_PUBLIC_PROFILE_SLUG : 'u';

    return home_url( sprintf( '/%s/%s/toplist/%d/', $slug, $user->user_nicename, $list_id ) );
}
