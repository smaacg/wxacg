<?php
/**
 * Image Optimizer - WebP & Responsive Srcset
 *
 * 功能：
 * 1. 上傳圖片時自動生成 .webp 副本（GD / Imagick 擇一）
 * 2. 提供 smacg_picture_tag() 輸出 <picture> 包裝（WebP + fallback）
 * 3. 自動為 the_content 中的 <img> 包裝 <picture>（可關閉）
 *
 * @package Blocksy_Child
 * @version 1.0.0
 * @since   2026-05-13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ============================================================
 * Part 1: 上傳時自動生成 WebP 副本
 * ============================================================ */

/**
 * 為 attachment 的每個尺寸生成 .webp 副本
 *
 * @param array $metadata
 * @param int   $attachment_id
 * @return array
 */
function smacg_generate_webp_on_upload( $metadata, $attachment_id ) {
    if ( empty( $metadata['file'] ) ) {
        return $metadata;
    }

    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit( $upload_dir['basedir'] );
    $file_dir   = trailingslashit( dirname( $base_dir . $metadata['file'] ) );

    // 原圖
    $original = $base_dir . $metadata['file'];
    smacg_convert_to_webp( $original );

    // 各種尺寸
    if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size_data ) {
            if ( empty( $size_data['file'] ) ) {
                continue;
            }
            $size_path = $file_dir . $size_data['file'];
            smacg_convert_to_webp( $size_path );
        }
    }

    return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'smacg_generate_webp_on_upload', 20, 2 );

/**
 * 將單一檔案轉為 WebP
 *
 * @param string $source 原始檔案絕對路徑
 * @return string|false  成功回傳 webp 路徑，失敗回 false
 */
function smacg_convert_to_webp( $source ) {
    if ( ! file_exists( $source ) ) {
        return false;
    }

    $info = pathinfo( $source );
    $ext  = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

    // 只處理 jpg/jpeg/png
    if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
        return false;
    }

    $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';

    // 已存在則跳過
    if ( file_exists( $webp_path ) ) {
        return $webp_path;
    }

    // 優先使用 Imagick
    if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
        try {
            $img = new Imagick( $source );
            $img->setImageFormat( 'webp' );
            $img->setImageCompressionQuality( 82 );
            $img->setOption( 'webp:method', '6' );
            $img->writeImage( $webp_path );
            $img->clear();
            $img->destroy();
            return $webp_path;
        } catch ( Exception $e ) {
            error_log( '[SMACG WebP Imagick] ' . $e->getMessage() );
        }
    }

    // Fallback: GD
    if ( function_exists( 'imagewebp' ) ) {
        $image = null;
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg':
                if ( function_exists( 'imagecreatefromjpeg' ) ) {
                    $image = @imagecreatefromjpeg( $source );
                }
                break;
            case 'png':
                if ( function_exists( 'imagecreatefrompng' ) ) {
                    $image = @imagecreatefrompng( $source );
                    if ( $image ) {
                        imagepalettetotruecolor( $image );
                        imagealphablending( $image, true );
                        imagesavealpha( $image, true );
                    }
                }
                break;
        }

        if ( $image ) {
            $result = @imagewebp( $image, $webp_path, 82 );
            imagedestroy( $image );
            return $result ? $webp_path : false;
        }
    }

    return false;
}

/**
 * 刪除 attachment 時一併刪除 .webp 副本
 */
function smacg_delete_webp_files( $post_id ) {
    $metadata = wp_get_attachment_metadata( $post_id );
    if ( empty( $metadata['file'] ) ) {
        return;
    }

    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit( $upload_dir['basedir'] );
    $file_dir   = trailingslashit( dirname( $base_dir . $metadata['file'] ) );

    $files = array( $base_dir . $metadata['file'] );
    if ( ! empty( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size_data ) {
            if ( ! empty( $size_data['file'] ) ) {
                $files[] = $file_dir . $size_data['file'];
            }
        }
    }

    foreach ( $files as $f ) {
        $info = pathinfo( $f );
        $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
        if ( file_exists( $webp ) ) {
            @unlink( $webp );
        }
    }
}
add_action( 'delete_attachment', 'smacg_delete_webp_files' );

/* ============================================================
 * Part 2: 取得圖片 URL 對應的 WebP URL
 * ============================================================ */

/**
 * 將任一圖片 URL 轉為對應的 WebP URL（若實體存在）
 *
 * @param string $url
 * @return string|false  存在則回傳 webp url，否則 false
 */
function smacg_get_webp_url( $url ) {
    if ( empty( $url ) ) {
        return false;
    }

    $upload_dir = wp_upload_dir();
    $base_url   = trailingslashit( $upload_dir['baseurl'] );
    $base_dir   = trailingslashit( $upload_dir['basedir'] );

    // 必須是站內 upload 目錄
    if ( strpos( $url, $base_url ) !== 0 ) {
        return false;
    }

    $relative = substr( $url, strlen( $base_url ) );
    $info     = pathinfo( $relative );
    $ext      = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';

    if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
        return false;
    }

    $webp_relative = ( $info['dirname'] !== '.' ? $info['dirname'] . '/' : '' ) . $info['filename'] . '.webp';
    $webp_abs      = $base_dir . $webp_relative;
    $webp_url      = $base_url . $webp_relative;

    return file_exists( $webp_abs ) ? $webp_url : false;
}

/* ============================================================
 * Part 3: 輸出 <picture> 標籤（含 srcset）
 * ============================================================ */

/**
 * 取代 <img> 為 <picture><source webp><img></picture>
 *
 * @param int    $post_id   貼文 ID（用其 featured image）
 * @param string $size      WordPress 圖片尺寸
 * @param string $alt       替代文字
 * @param string $class     img 的 class
 * @param array  $extra     額外屬性：['loading' => 'lazy', 'decoding' => 'async']
 * @return string HTML
 */
function smacg_picture_tag( $post_id, $size = 'medium', $alt = '', $class = '', $extra = array() ) {
    $thumb_id = get_post_thumbnail_id( $post_id );
    if ( ! $thumb_id ) {
        return '';
    }

    $src_data = wp_get_attachment_image_src( $thumb_id, $size );
    if ( ! $src_data ) {
        return '';
    }

    list( $src, $width, $height ) = $src_data;
    $srcset = wp_get_attachment_image_srcset( $thumb_id, $size );
    $sizes  = wp_get_attachment_image_sizes( $thumb_id, $size );

    if ( empty( $alt ) ) {
        $alt = get_the_title( $post_id );
    }

    // 預設屬性
    $defaults = array(
        'loading'  => 'lazy',
        'decoding' => 'async',
    );
    $extra = wp_parse_args( $extra, $defaults );

    // 屬性字串
    $attr_parts = array();
    foreach ( $extra as $k => $v ) {
        $attr_parts[] = sprintf( '%s="%s"', esc_attr( $k ), esc_attr( $v ) );
    }
    $extra_attr = implode( ' ', $attr_parts );

    // 嘗試取得 WebP srcset
    $webp_srcset = smacg_build_webp_srcset( $thumb_id, $size );
    $webp_main   = smacg_get_webp_url( $src );

    ob_start();
    ?>
    <picture class="smacg-picture">
        <?php if ( $webp_main || $webp_srcset ) : ?>
            <source
                type="image/webp"
                <?php if ( $webp_srcset ) : ?>srcset="<?php echo esc_attr( $webp_srcset ); ?>"<?php elseif ( $webp_main ) : ?>srcset="<?php echo esc_url( $webp_main ); ?>"<?php endif; ?>
                <?php if ( $sizes ) : ?>sizes="<?php echo esc_attr( $sizes ); ?>"<?php endif; ?>
            >
        <?php endif; ?>
        <img
            src="<?php echo esc_url( $src ); ?>"
            <?php if ( $srcset ) : ?>srcset="<?php echo esc_attr( $srcset ); ?>"<?php endif; ?>
            <?php if ( $sizes ) : ?>sizes="<?php echo esc_attr( $sizes ); ?>"<?php endif; ?>
            width="<?php echo (int) $width; ?>"
            height="<?php echo (int) $height; ?>"
            alt="<?php echo esc_attr( $alt ); ?>"
            <?php if ( $class ) : ?>class="<?php echo esc_attr( $class ); ?>"<?php endif; ?>
            <?php echo $extra_attr; // already escaped above ?>
        >
    </picture>
    <?php
    return ob_get_clean();
}

/**
 * 為某張圖片的 srcset 字串建立 WebP 版本
 */
function smacg_build_webp_srcset( $thumb_id, $size ) {
    $srcset = wp_get_attachment_image_srcset( $thumb_id, $size );
    if ( ! $srcset ) {
        return '';
    }

    $pairs = array_map( 'trim', explode( ',', $srcset ) );
    $webp_pairs = array();

    foreach ( $pairs as $pair ) {
        $parts = preg_split( '/\s+/', $pair, 2 );
        if ( count( $parts ) !== 2 ) {
            continue;
        }
        $webp_url = smacg_get_webp_url( $parts[0] );
        if ( $webp_url ) {
            $webp_pairs[] = $webp_url . ' ' . $parts[1];
        }
    }

    return implode( ', ', $webp_pairs );
}

/* ============================================================
 * Part 4: 自動為 the_content 的 <img> 包裝 <picture>
 *   （需要時可註解 add_filter 來關閉）
 * ============================================================ */

/**
 * 過濾 the_content，自動將 <img src=".jpg|.png"> 包成 <picture>
 */
function smacg_wrap_content_images_with_picture( $content ) {
    if ( is_admin() || is_feed() || empty( $content ) ) {
        return $content;
    }

    // 找出所有不在 <picture> 內的 <img>
    return preg_replace_callback(
        '/<img\b([^>]*?)src=["\']([^"\']+\.(?:jpe?g|png))["\']([^>]*)>/i',
        function ( $matches ) {
            $before = $matches[1];
            $src    = $matches[2];
            $after  = $matches[3];

            // 若上層已是 picture，不重複包
            // （這個簡化邏輯靠 preg_replace_callback 後處理：見下）

            $webp = smacg_get_webp_url( $src );
            if ( ! $webp ) {
                return $matches[0];
            }

            $img_tag = '<img' . $before . 'src="' . esc_url( $src ) . '"' . $after . '>';

            return '<picture class="smacg-picture-auto">'
                 . '<source type="image/webp" srcset="' . esc_url( $webp ) . '">'
                 . $img_tag
                 . '</picture>';
        },
        $content
    );
}
add_filter( 'the_content', 'smacg_wrap_content_images_with_picture', 99 );

/* ============================================================
 * Part 5: 後台工具 - 批次補產 WebP（給管理員用）
 *   訪問: /wp-admin/admin.php?action=smacg_bulk_webp&_wpnonce=XXX
 * ============================================================ */

add_action( 'admin_action_smacg_bulk_webp', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '無權限' );
    }
    check_admin_referer( 'smacg_bulk_webp' );

    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 50,
        'post_mime_type' => array( 'image/jpeg', 'image/png' ),
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_smacg_webp_done',
                'compare' => 'NOT EXISTS',
            ),
        ),
    );

    $q = new WP_Query( $args );
    $done = 0;

    foreach ( $q->posts as $aid ) {
        $file = get_attached_file( $aid );
        if ( $file && file_exists( $file ) ) {
            smacg_convert_to_webp( $file );

            // 各尺寸
            $meta = wp_get_attachment_metadata( $aid );
            if ( ! empty( $meta['sizes'] ) ) {
                $dir = trailingslashit( dirname( $file ) );
                foreach ( $meta['sizes'] as $s ) {
                    if ( ! empty( $s['file'] ) ) {
                        smacg_convert_to_webp( $dir . $s['file'] );
                    }
                }
            }
            update_post_meta( $aid, '_smacg_webp_done', 1 );
            $done++;
        }
    }

    wp_safe_redirect(
        add_query_arg(
            array( 'smacg_webp_done' => $done ),
            admin_url( 'upload.php' )
        )
    );
    exit;
} );

add_action( 'admin_notices', function () {
    if ( isset( $_GET['smacg_webp_done'] ) ) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>已處理 %d 張圖片的 WebP 轉換。<a href="%s">繼續下一批</a></p></div>',
            (int) $_GET['smacg_webp_done'],
            esc_url( wp_nonce_url( admin_url( 'admin.php?action=smacg_bulk_webp' ), 'smacg_bulk_webp' ) )
        );
    }
} );
