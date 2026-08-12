<?php
/**
 * 編輯內容填寫率統計
 * 放到 WordPress 根目錄後用瀏覽器訪問一次即可
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/wp-load.php';

global $wpdb;

$total_anime = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='anime' AND post_status='publish'"
);

$has_editorial = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
     WHERE meta_key='anime_editorial_note' AND meta_value != ''"
);

$has_synopsis_ch = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
     WHERE meta_key='anime_synopsis_chinese' AND meta_value != ''"
);

$has_synopsis_any = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
     WHERE p.post_type='anime' AND p.post_status='publish'
     AND pm.meta_key IN ('anime_synopsis_chinese','anime_synopsis')
     AND pm.meta_value != ''"
);

$has_tw_streaming = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
     WHERE meta_key IN ('anime_streaming_list','anime_tw_streaming')
     AND meta_value NOT IN ('','a:0:{}')"
);

$zero_editorial = $total_anime - $has_editorial;

header('Content-Type: text/plain; charset=utf-8');
echo "=== 微笑動漫 Editorial 內容填寫率 ===\n\n";
printf("anime 總數（已發布）：%d\n", $total_anime);
printf("有編輯短評（editorial_note）：%d（%.1f%%）\n", $has_editorial, $total_anime ? ($has_editorial / $total_anime * 100) : 0);
printf("無編輯短評：%d（%.1f%%）\n", $zero_editorial, $total_anime ? ($zero_editorial / $total_anime * 100) : 0);
printf("有中文簡介（synopsis_chinese）：%d（%.1f%%）\n", $has_synopsis_ch, $total_anime ? ($has_synopsis_ch / $total_anime * 100) : 0);
printf("有任何簡介（中文或英文）：%d（%.1f%%）\n", $has_synopsis_any, $total_anime ? ($has_synopsis_any / $total_anime * 100) : 0);
printf("有台灣串流資料：%d（%.1f%%）\n", $has_tw_streaming, $total_anime ? ($has_tw_streaming / $total_anime * 100) : 0);
echo "\n=== 結論 ===\n";
$no_synopsis_no_editorial = $total_anime - $has_synopsis_any;
printf("完全無簡介也無編輯短評（Thin Content 風險最高）：約 %d 部\n", $no_synopsis_no_editorial);
