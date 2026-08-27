<?php
/**
 * 會員自訂動漫排行 — 分享卡片圖產生
 *
 * 社群平台的大圖卡是 1200×630 橫式，而動漫封面是 3:4 直式——直接拿第一名
 * 的封面當 og:image，在 Threads / Discord 上會被裁掉上下或大量留白。把前
 * 三名拼成一張橫圖，卡片才會像一份榜單。
 *
 * 成本控制：圖在「儲存排行」時產生一次並寫成檔案，瀏覽時只是讀靜態圖，
 * 零運算。不在 wp_head 或每次瀏覽時合成——那會讓每個分享連結被點一次就
 * 跑一次 GD。
 *
 * 失敗一律靜默退回單張封面：卡片好不好看是加分項，不該因為某張圖解不開
 * 就讓整個分享功能不能用。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** 卡片尺寸（社群平台大圖卡的標準比例） */
if ( ! defined( 'WXACG_TOPLIST_CARD_W' ) ) {
    define( 'WXACG_TOPLIST_CARD_W', 1200 );
}
if ( ! defined( 'WXACG_TOPLIST_CARD_H' ) ) {
    define( 'WXACG_TOPLIST_CARD_H', 630 );
}

/**
 * 卡片檔案在 uploads 底下的相對路徑。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 */
function wxacg_toplist_card_relpath( int $user_id, int $list_id ): string {
    return 'wxacg-toplist/' . $user_id . '-' . $list_id . '.jpg';
}

/**
 * 卡片圖的網址；檔案不存在時回傳空字串。
 *
 * 網址帶上檔案修改時間當版本參數——社群平台會長期快取 og:image，排行
 * 改了卻沿用同一個網址的話，分享出去仍是舊的封面組合。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 */
function wxacg_toplist_card_url( int $user_id, int $list_id ): string {
    $up   = wp_upload_dir();
    $rel  = wxacg_toplist_card_relpath( $user_id, $list_id );
    $path = trailingslashit( $up['basedir'] ) . $rel;

    if ( ! file_exists( $path ) ) {
        return '';
    }

    return trailingslashit( $up['baseurl'] ) . $rel . '?v=' . filemtime( $path );
}

/**
 * 依副檔名開啟圖檔。
 *
 * 站上封面以 jpg 為主，另有少量 webp / png / gif，以及 1 張 avif。
 * 解不開的格式回傳 null，由呼叫端跳過該張。
 *
 * @param string $path 圖檔絕對路徑。
 * @return resource|GdImage|null
 */
function wxacg_toplist_open_image( string $path ) {
    if ( ! is_readable( $path ) ) {
        return null;
    }

    $type = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

    try {
        switch ( $type ) {
            case 'jpg':
            case 'jpeg':
                return @imagecreatefromjpeg( $path ) ?: null;
            case 'png':
                return @imagecreatefrompng( $path ) ?: null;
            case 'webp':
                return function_exists( 'imagecreatefromwebp' ) ? ( @imagecreatefromwebp( $path ) ?: null ) : null;
            case 'gif':
                return @imagecreatefromgif( $path ) ?: null;
            case 'avif':
                return function_exists( 'imagecreatefromavif' ) ? ( @imagecreatefromavif( $path ) ?: null ) : null;
        }
    } catch ( \Throwable $e ) {
        return null;
    }

    return null;
}

/**
 * 取得某篇作品封面的本機檔案路徑。
 *
 * 優先用 WordPress 產生的中間尺寸而不是原圖——原圖可能是 2000px 以上的
 * 大檔，一次開三張很容易吃掉記憶體上限，而卡片裡每張只有 345px 寬。
 *
 * @param int $post_id 作品 ID。
 */
function wxacg_toplist_cover_path( int $post_id ): string {
    $aid = (int) get_post_thumbnail_id( $post_id );
    if ( ! $aid ) {
        return '';
    }

    $file = get_attached_file( $aid );
    if ( ! $file || ! file_exists( $file ) ) {
        return '';
    }

    $meta = wp_get_attachment_metadata( $aid );
    if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
        return $file;
    }

    // 挑寬度 >= 345 之中最小的那個，剛好夠用又不浪費
    $dir  = trailingslashit( dirname( $file ) );
    $best = '';
    $bw   = PHP_INT_MAX;

    foreach ( $meta['sizes'] as $s ) {
        $w = (int) ( $s['width'] ?? 0 );
        if ( $w >= 345 && $w < $bw && ! empty( $s['file'] ) && file_exists( $dir . $s['file'] ) ) {
            $bw   = $w;
            $best = $dir . $s['file'];
        }
    }

    return $best ?: $file;
}

/**
 * 產生排行的分享卡片圖。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 * @return bool 是否成功產生。
 */
function wxacg_toplist_generate_card( int $user_id, int $list_id ): bool {

    if ( ! function_exists( 'imagecreatetruecolor' ) ) {
        return false;
    }

    $list = wxacg_toplist_get( $user_id, $list_id );
    if ( ! $list || empty( $list['items'] ) ) {
        return false;
    }

    // 只用前三名——三張 3:4 封面在 1200×630 裡各佔 345px 寬，尺寸夠大看得清，
    // 也對應「前三名」這個大家看得懂的概念
    $paths = [];
    foreach ( array_slice( $list['items'], 0, 3 ) as $pid ) {
        $p = wxacg_toplist_cover_path( (int) $pid );
        if ( $p !== '' ) {
            $paths[] = $p;
        }
    }

    if ( empty( $paths ) ) {
        return false;
    }

    $canvas = imagecreatetruecolor( WXACG_TOPLIST_CARD_W, WXACG_TOPLIST_CARD_H );
    if ( ! $canvas ) {
        return false;
    }

    // 深色底，與站上配色一致；封面之間的縫隙看起來才不像破圖
    $bg = imagecolorallocate( $canvas, 15, 20, 32 );
    imagefilledrectangle( $canvas, 0, 0, WXACG_TOPLIST_CARD_W, WXACG_TOPLIST_CARD_H, $bg );

    $n      = count( $paths );
    $ch     = 460;                       // 封面高度
    $cw     = (int) round( $ch * 3 / 4 ); // 3:4 → 345
    $gap    = 24;
    $total  = $n * $cw + ( $n - 1 ) * $gap;
    $startX = (int) round( ( WXACG_TOPLIST_CARD_W - $total ) / 2 );
    $y      = (int) round( ( WXACG_TOPLIST_CARD_H - $ch ) / 2 );

    $drawn = 0;
    foreach ( $paths as $i => $path ) {
        $src = wxacg_toplist_open_image( $path );
        if ( ! $src ) {
            continue;
        }

        $sw = imagesx( $src );
        $sh = imagesy( $src );

        /*
         * 以「填滿」方式裁切而非變形縮放：封面比例雖然多半是 3:4，但站上
         * 有少數不是，直接拉伸會讓角色變形。
         */
        $scale = max( $cw / $sw, $ch / $sh );
        $useW  = (int) round( $cw / $scale );
        $useH  = (int) round( $ch / $scale );
        $srcX  = (int) round( ( $sw - $useW ) / 2 );
        $srcY  = (int) round( ( $sh - $useH ) / 2 );

        imagecopyresampled(
            $canvas, $src,
            $startX + $i * ( $cw + $gap ), $y,
            $srcX, $srcY,
            $cw, $ch,
            $useW, $useH
        );

        imagedestroy( $src );
        $drawn++;
    }

    if ( $drawn === 0 ) {
        imagedestroy( $canvas );
        return false;
    }

    $up  = wp_upload_dir();
    $rel = wxacg_toplist_card_relpath( $user_id, $list_id );
    $abs = trailingslashit( $up['basedir'] ) . $rel;

    if ( ! wp_mkdir_p( dirname( $abs ) ) ) {
        imagedestroy( $canvas );
        return false;
    }

    $ok = imagejpeg( $canvas, $abs, 82 );
    imagedestroy( $canvas );

    return (bool) $ok;
}

/* ============================================================
   儲存排行時重新產生卡片
   ============================================================ */
add_action( 'wxacg_toplist_saved', function ( int $user_id, int $list_id ): void {
    wxacg_toplist_generate_card( $user_id, $list_id );
}, 20, 2 );

/* ============================================================
   刪除排行時一併移除卡片檔，不留孤兒檔案
   ============================================================ */
add_action( 'wxacg_toplist_deleted', function ( int $user_id, int $list_id ): void {
    $up  = wp_upload_dir();
    $abs = trailingslashit( $up['basedir'] ) . wxacg_toplist_card_relpath( $user_id, $list_id );
    if ( file_exists( $abs ) ) {
        @unlink( $abs );
    }
}, 10, 2 );
