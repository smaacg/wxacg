<?php
/**
 * 檔案名稱: includes/bgm-bbcode.php
 *
 * Bangumi 簡介的 BBCode 處理（共用單一來源）。
 *
 * 原本 single-person.php 與 single-character.php 各自帶一份一字不差的
 * asa_render_bgm_bbcode()，另外各自又寫了一份純文字版的剝除正規式。
 * 同一份邏輯散成四處的後果是「改一邊、漏一邊」——本外掛已經發生過
 * （get_bgm_staff() 修好、get_bgm_manga_staff() 漏掉）。這裡集中成一份，
 * 兩個模板都呼叫同一組函式。做法比照 includes/date-helpers.php。
 *
 * 支援的標記（白名單，其餘一律當純文字）：
 *
 *   [mask]   劇透 → 拆掉標籤只留內文
 *   [url=]   連結 → <a>，網址過 esc_url()
 *   [b]      粗體 → <strong>
 *   [s]      刪除線 → <s>（Bangumi 用來標示已裁撤／已離職／已併入，語意要留）
 *   [u]      底線 → <u>
 *   [size=]  字級 → 只拆標籤，不套樣式
 *   [color=] 顏色 → 只拆標籤，不套樣式
 *   [center] 置中 → 只拆標籤，不套樣式
 *
 * ★ 白名單而非通吃，而且「只認小寫」，這兩點都是刻意的：
 *   正式站簡介裡有 [Alexandros]、[Champagne]（樂團名，人物 #35808 本身就是
 *   這個樂團）、[UBW]（「Fate／stay night [UBW]」）、[JACK]（東京喰種外傳）、
 *   [milk]（藝名）。無差別剝除方括號會把這些名字吃掉。
 *
 * ★ size／color／center 只拆標籤不套樣式，是因為那是 Bangumi 那邊的視覺設定，
 *   套到本站會跟版型與深淺色主題衝突——實測有 [color=#CAEAF6] 這種淺藍，
 *   在淺色底幾乎看不見。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 白名單標記的剝除正規式（純文字版與 HTML 版共用同一份定義）。
 *
 * 長的替代項要排在短的前面：否則 [url=x] 會先試到 u 這個分支。
 * 不加 i 修飾子——大寫的 [UBW]／[JACK] 是作品名，不是標記。
 */
if ( ! defined( 'ASA_BGM_BBCODE_PATTERN' ) ) {
    define(
        'ASA_BGM_BBCODE_PATTERN',
        '/\[\/?(?:url(?:=[^\]]*)?|size(?:=[^\]]*)?|color(?:=[^\]]*)?|center|mask|b|s|u)\]/u'
    );
}

/**
 * 純文字版：拿掉 BBCode 標記符號只留內文。
 * 給 JSON-LD schema description 與 thin-content 判斷用。
 */
if ( ! function_exists( 'asa_strip_bgm_bbcode' ) ) {
    function asa_strip_bgm_bbcode( string $raw ): string {
        if ( $raw === '' ) return '';
        return trim( wp_strip_all_tags( preg_replace( ASA_BGM_BBCODE_PATTERN, '', $raw ) ) );
    }
}

/**
 * HTML 版：把 BBCode 轉成安全的 HTML，給前台簡介區塊顯示用。
 * 網址一律過 esc_url()、文字一律過 esc_html()，不信任來源內容，避免 XSS。
 */
if ( ! function_exists( 'asa_render_bgm_bbcode' ) ) {
    function asa_render_bgm_bbcode( string $raw ): string {
        if ( $raw === '' ) return '';

        // 劇透標籤：先拆掉標籤本身，只留內文
        $raw = str_replace( [ '[mask]', '[/mask]' ], '', $raw );

        // 用 placeholder 保護連結，避免內容被後面的 esc_html() 動到
        $links = [];
        $protect_link = static function ( string $href, string $label ) use ( &$links ): string {
            $token = "\x01ASA_LINK_" . count( $links ) . "\x02";
            $links[ $token ] = '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( $label ) . '</a>';
            return $token;
        };

        // [url=網址]文字[/url]
        $raw = preg_replace_callback(
            '/\[url=(https?:\/\/[^\]\s]+)\](.*?)\[\/url\]/su',
            static function ( array $m ) use ( $protect_link ): string {
                return $protect_link( $m[1], $m[2] );
            },
            $raw
        );
        // 裸網址形式 [url]網址[/url]
        $raw = preg_replace_callback(
            '/\[url\](https?:\/\/[^\]\s]+)\[\/url\]/su',
            static function ( array $m ) use ( $protect_link ): string {
                return $protect_link( $m[1], $m[1] );
            },
            $raw
        );

        // 其餘文字整段跳脫，任何殘留標記或惡意內容都只會被當純文字顯示。
        // esc_html() 不會動到方括號，也不會動到上面的 placeholder，
        // 因此下面幾步仍然比對得到。
        $escaped = esc_html( $raw );

        // [b]/[/b] 粗體（此時作用在已跳脫過的文字上，安全）
        $escaped = str_replace( [ '[b]', '[/b]' ], [ '<strong>', '</strong>' ], $escaped );

        /*
         * [s] 刪除線、[u] 底線：要求「必須成對」才轉換。
         * 落單的標記留作純文字，不會產生未閉合的 <s>／<u> 汙染後面版面。
         * 內文可能夾著上面的連結 placeholder，用 s 修飾子讓 . 跨行比對。
         */
        $escaped = preg_replace( '/\[s\](.*?)\[\/s\]/su', '<s>$1</s>', $escaped );
        $escaped = preg_replace( '/\[u\](.*?)\[\/u\]/su', '<u>$1</u>', $escaped );

        /*
         * [size=]／[color=]／[center]：只拆標籤、保留內文，不套視覺樣式。
         * 逐個標籤剝除而非「開頭…結尾」成對比對——正式站實測有
         * [center][b][color=#CAEAF6][size=18]…[/size][/color][/b][/center]
         * 這種交錯巢狀，成對比對會配錯（[color=] 對到 [/size]）而留下孤兒標籤。
         */
        $escaped = preg_replace( '/\[\/?(?:size(?:=[^\]]*)?|color(?:=[^\]]*)?|center)\]/u', '', $escaped );

        // 換回保護起來的連結
        $escaped = strtr( $escaped, $links );

        return $escaped;
    }
}
