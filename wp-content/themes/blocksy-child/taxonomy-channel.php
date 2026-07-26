<?php
/**
 * Channel Taxonomy Archive Template
 *
 * Path: wp-content/themes/blocksy-child/taxonomy-channel.php
 * @version 1.0.0 (2026-05-31)
 *
 * 說明：
 *   channel 是自訂 taxonomy，WordPress 模板階層不會自動套用 category.php，
 *   若無此檔，/channel/{slug}/ 會掉到父主題 Blocksy 的 archive 模板，
 *   導致沒套用本站玻璃擬態版面與 news.css。
 *   本檔直接複用 category.php（其中已含 'channel' === $current_tax 分支），
 *   讓頻道頁與分類頁共用同一套版面與資源載入。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$smacg_cat_tpl = locate_template( 'category.php' );

if ( $smacg_cat_tpl ) {
    require $smacg_cat_tpl;
} else {
    // 極端 fallback：理論上不會走到這
    get_template_part( 'index' );
}
