<?php
/**
 * AJAX News Filter
 * 路徑：blocksy-child/inc/ajax-news-filter.php
 *
 * 提供端點：wp-admin/admin-ajax.php?action=smacg_news_filter
 *
 * 入參：
 *   content_type  string  news / review / feature / announcement
 *   channel       string  anime / manga / cosplay …（空字串 = 全部）
 *   paged         int     頁碼，預設 1
 *   nonce         string  smacg_news_filter
 *
 * 回傳 JSON：
 *   { success: true, data: { html: '<div…>…</div>', url: '/news/cosplay/' } }
 *
 * @version 1.0.0
 * @since   2026-05-16
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 允許的清單（與 class-editorial-routing.php 對齊）
const SMACG_NEWS_CONTENT_TYPES = [ 'announcement', 'news', 'review', 'feature' ];
const SMACG_NEWS_CHANNELS      = [
    'anime', 'manga', 'novel', 'game', 'vtuber', 'cosplay', 'ai-tools',
    'voice-actor', 'music', 'merchandise', 'event', 'industry',
];

add_action( 'wp_ajax_smacg_news_filter',        'smacg_ajax_news_filter' );
add_action( 'wp_ajax_nopriv_smacg_news_filter', 'smacg_ajax_news_filter' );

function smacg_ajax_news_filter() {

    // ── nonce 驗證 ──
    if ( ! check_ajax_referer( 'smacg_news_filter', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
    }

    // ── 參數清理 ──
    $content_type = isset( $_POST['content_type'] ) ? sanitize_key( $_POST['content_type'] ) : '';
    $channel_raw  = isset( $_POST['channel'] ) ? sanitize_text_field( $_POST['channel'] ) : '';
    $channel      = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $channel_raw ) );
    $paged        = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;

    if ( ! in_array( $content_type, SMACG_NEWS_CONTENT_TYPES, true ) ) {
        wp_send_json_error( [ 'message' => 'Invalid content_type' ], 400 );
    }
    if ( $channel !== '' && ! in_array( $channel, SMACG_NEWS_CHANNELS, true ) ) {
        wp_send_json_error( [ 'message' => 'Invalid channel' ], 400 );
    }

    // ── 構建查詢 ──
    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'paged'          => $paged,
        'posts_per_page' => (int) get_option( 'posts_per_page', 10 ),
        'category_name'  => $content_type,
        'ignore_sticky_posts' => true,
    ];

    if ( $channel !== '' ) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'channel',
                'field'    => 'slug',
                'terms'    => $channel,
            ],
        ];
    }

    $q = new WP_Query( $args );

    // ── 還原 canonical URL（給 History API 使用）──
    $canonical_url = $channel !== ''
        ? home_url( "/{$content_type}/{$channel}/" )
        : home_url( "/{$content_type}/" );
    if ( $paged > 1 ) {
        $canonical_url = trailingslashit( $canonical_url ) . "page/{$paged}/";
    }

    // ── 渲染 partial ──
    set_query_var( 'news_main_query', $q );
    ob_start();
    get_template_part( 'template-parts/news-list' );
    $html = ob_get_clean();

    wp_reset_postdata();

    wp_send_json_success( [
        'html'  => $html,
        'url'   => $canonical_url,
        'total' => (int) $q->found_posts,
    ] );
}
