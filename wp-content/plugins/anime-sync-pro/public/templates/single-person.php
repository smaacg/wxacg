<?php
/**
 * Single Person 聲優/製作人員 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-person.php
 *
 * Changelog:
 *   1.5.4 (2026-08-16)
 *     - [修正] 簡介顯示 Bangumi BBCode 原始碼（[b]/[url=]標籤未轉換）:
 *              新增 asa_render_bgm_bbcode()，把 [b]、[url=]/[url]、[mask]
 *              轉成安全的 HTML（連結過 esc_url()，文字過 esc_html()）。
 *              JSON-LD schema description 另外走純文字版，拿掉 BBCode 符號。
 *   1.5.3 (2026-08-12)
 *     - [新增] 判定為 thin content 時，寫入 $GLOBALS['asa_page_is_thin']，
 *              讓 child theme functions.php 的 AdSense 腳本載入判斷
 *              直接讀這個旗標，不用再自己查一次 Anime_Sync_Entity_Repository。
 *   1.5.2 (2026-08-12)
 *     - [新增] 無簡介(person_summary 為空)時，透過 RankMath
 *              rank_math/frontend/robots filter 動態輸出 noindex,follow，
 *              避免大量純資料卡片頁被 Google 判定為 Thin Content。
 *   1.5.1 (2026-07-30)
 *     - [修正] 星座判斷錯誤(12/2 誤判天蠍→正確射手);改用起始日邏輯。
 *     - [新增] 「其他資料」中的網址/社群值自動轉為可點連結
 *              (個人網站/官網/X/Twitter/Instagram/微博 等)。
 *   1.5.0 (2026-07-30)
 *     - [新增] 生日欄位自動附上年紀與星座。
 *   1.4.0 (2026-07-30)
 *     - [改版] 版型比照 single-character.php 兩欄化。
 *     - [新增] 身高、別名併入基本資料;「其他資料」infobox 通用展開。
 *   1.3.0 (2026-07-28) - [新增] 基本資料、簡介區塊。
 *   1.2.0 (2026-07-28) - [改版] Hero 直式海報、徽章、外部連結。
 *   1.1.0 (2026-07-28) - [新增] 麵包屑、JSON-LD、作品搜尋。
 *   1.0.0 - [新增] 初版。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── 解析生日字串 → [year, month, day]（任一項無法解析為 0） ── */
if ( ! function_exists( 'asa_parse_birthday' ) ) {
    function asa_parse_birthday( string $raw ): array {
        $raw = trim( $raw );
        $y = 0; $m = 0; $d = 0;

        // 格式 1：YYYY-MM-DD 或 YYYY/MM/DD
        if ( preg_match( '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $raw, $mm ) ) {
            return [ (int) $mm[1], (int) $mm[2], (int) $mm[3] ];
        }
        // 格式 2：YYYY年MM月DD日（年份可省略）
        if ( preg_match( '/(\d{4})\s*年/u', $raw, $mm ) ) $y = (int) $mm[1];
        if ( preg_match( '/(\d{1,2})\s*月/u', $raw, $mm ) ) $m = (int) $mm[1];
        if ( preg_match( '/(\d{1,2})\s*日/u', $raw, $mm ) ) $d = (int) $mm[1];

        return [ $y, $m, $d ];
    }
}

/* ── 由月日判斷星座（以起始日為準） ── */
if ( ! function_exists( 'asa_zodiac_sign' ) ) {
    function asa_zodiac_sign( int $m, int $d ): string {
        if ( $m < 1 || $m > 12 || $d < 1 ) return '';
        // 各星座起始日：從該日(含)起進入此星座
        $z = [
            [ '摩羯座', 12, 22 ], [ '水瓶座', 1, 20 ], [ '雙魚座', 2, 19 ],
            [ '牡羊座', 3, 21 ],  [ '金牛座', 4, 20 ], [ '雙子座', 5, 21 ],
            [ '巨蟹座', 6, 22 ],  [ '獅子座', 7, 23 ], [ '處女座', 8, 23 ],
            [ '天秤座', 9, 23 ],  [ '天蠍座', 10, 24 ], [ '射手座', 11, 23 ],
        ];
        foreach ( $z as [ $name, $zm, $zd ] ) {
            if ( $m === $zm && $d >= $zd ) return $name;
            if ( $name === '摩羯座' && $m === 1 && $d <= 19 ) return $name; // 摩羯跨年
        }
        // 落在起始日之前 → 前一個月的星座
        $prevMap = [
            1 => '摩羯座', 2 => '水瓶座', 3 => '雙魚座', 4 => '牡羊座',
            5 => '金牛座', 6 => '雙子座', 7 => '巨蟹座', 8 => '獅子座',
            9 => '處女座', 10 => '天秤座', 11 => '天蠍座', 12 => '射手座',
        ];
        return $prevMap[ $m ] ?? '';
    }
}

/* ── 由年月日算實歲（無年份回 0） ── */
if ( ! function_exists( 'asa_calc_age' ) ) {
    function asa_calc_age( int $y, int $m, int $d ): int {
        if ( $y < 1900 || $m < 1 || $d < 1 ) return 0;
        $today = new DateTime( 'now', wp_timezone() );
        $birth = DateTime::createFromFormat( 'Y-n-j', sprintf( '%d-%d-%d', $y, $m, $d ), wp_timezone() );
        if ( ! $birth ) return 0;
        return (int) $birth->diff( $today )->y;
    }
}

/* ── 依 label / value 判斷是否為連結，回傳 [url, text] 或 null ── */
if ( ! function_exists( 'asa_infobox_link' ) ) {
    function asa_infobox_link( string $label, string $value ): ?array {
        $value = trim( $value );
        if ( $value === '' ) return null;

        // 已是完整網址
        if ( preg_match( '#^https?://#i', $value ) ) {
            return [ $value, $value ];
        }

        $label_l = mb_strtolower( $label );
        $is_x  = ( strpos( $label_l, 'x' ) !== false || strpos( $label_l, 'twitter' ) !== false );
        $is_ig = ( strpos( $label_l, 'instagram' ) !== false || $label_l === 'ig' );
        $is_wb = ( strpos( $label, '微博' ) !== false || strpos( $label_l, 'weibo' ) !== false );

        // @handle 或純帳號
        $handle = ltrim( $value, '@＠' );
        if ( $is_x && preg_match( '/^[A-Za-z0-9_]{1,30}$/', $handle ) ) {
            return [ 'https://x.com/' . $handle, '@' . $handle ];
        }
        if ( $is_ig && preg_match( '/^[A-Za-z0-9_.]{1,30}$/', $handle ) ) {
            return [ 'https://instagram.com/' . $handle, '@' . $handle ];
        }
        if ( $is_wb ) {
            return null; // 微博 ID 規則不定，維持純文字
        }
        // 沒有 http 但看起來像網域
        if ( preg_match( '#^[a-z0-9-]+(\.[a-z0-9-]+)+#i', $value ) && strpos( $value, ' ' ) === false ) {
            return [ 'https://' . $value, $value ];
        }
        return null;
    }
}

/* ── 把 Bangumi 簡介裡的 BBCode 轉成安全的 HTML ──
 * 只認得 Bangumi 實際會用到的幾種標記：[b]、[url=]/[url]、[mask]（劇透，直接拆掉標籤只留內文）。
 * 網址一律過 esc_url()、文字一律過 esc_html()，不信任來源內容，避免 XSS。
 */
if ( ! function_exists( 'asa_render_bgm_bbcode' ) ) {
    function asa_render_bgm_bbcode( string $raw ): string {
        if ( $raw === '' ) return '';

        // 劇透標籤：先拆掉標籤本身，只留內文（跟 single-character.php 既有作法一致）
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

        // 其餘文字整段跳脫，任何殘留標記或惡意內容都只會被當純文字顯示
        $escaped = esc_html( $raw );

        // [b]/[/b] 粗體（此時作用在已跳脫過的文字上，安全）
        $escaped = str_replace( [ '[b]', '[/b]' ], [ '<strong>', '</strong>' ], $escaped );

        // 換回保護起來的連結
        $escaped = strtr( $escaped, $links );

        return $escaped;
    }
}

$person_bgm_id = (int) get_query_var( 'asa_person_id' );

$repo   = new Anime_Sync_Entity_Repository();
$person = $repo->get_person( $person_bgm_id );

// 找不到人物 → 走原生 404
if ( null === $person ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
    include get_query_template( '404' );
    return;
}

$is_cv       = ( $person['type'] === 'cv' );
$works       = $repo->get_person_works( $person_bgm_id, $is_cv ); // CV 只回 cast；staff 類人物回全部(含 staff 紀錄)
$works_count = count( $works );
$role_label  = $is_cv ? '聲優' : '製作人員';

/* ── 姓名縮寫 fallback（無大頭照時使用） ── */
$person_fallback = trim( wp_strip_all_tags( (string) $person['name'] ) );
$person_fallback = $person_fallback === '' ? 'AN' : ( function_exists( 'mb_substr' ) ? mb_substr( $person_fallback, 0, 2 ) : substr( $person_fallback, 0, 2 ) );

/* ── 主名 + 原文名（聲優通常只有日文原名） ── */
$name_tw = trim( (string) $person['name'] );
$name_ja = trim( (string) $person['name_original'] );

$person_aliases = ( isset( $person['aliases'] ) && is_array( $person['aliases'] ) ) ? $person['aliases'] : [];

/* 從別名抽英文名（若有） */
$name_en = '';
foreach ( $person_aliases as $alias ) {
    $a_label = isset( $alias['label'] ) ? trim( (string) $alias['label'] ) : '';
    $a_value = isset( $alias['value'] ) ? trim( (string) $alias['value'] ) : '';
    if ( $a_value === '' ) continue;
    if ( $a_label === '英文名' || $a_label === '英文名稱' ) {
        $name_en = $a_value;
        break;
    }
}

$name_multi = [];
if ( $name_ja !== '' && $name_ja !== $name_tw ) $name_multi[] = [ 'lang' => '日', 'value' => $name_ja ];
if ( $name_en !== '' )                          $name_multi[] = [ 'lang' => 'EN', 'value' => $name_en ];

/* ── 基本資料：性別 / 生日(附年紀+星座) / 血型 / 身高 + 其餘別名 ── */
$basic_info_rows = [];
if ( ! empty( $person['gender'] ) ) {
    $basic_info_rows[] = [ '性別', $person['gender'] ];
}
if ( ! empty( $person['birthday'] ) ) {
    [ $by, $bm, $bd ] = asa_parse_birthday( (string) $person['birthday'] );
    $bday_display = (string) $person['birthday'];

    $extra_bits = [];
    $age = asa_calc_age( $by, $bm, $bd );
    if ( $age > 0 ) {
        $extra_bits[] = $age . ' 歲';
    }
    $zodiac = asa_zodiac_sign( $bm, $bd );
    if ( $zodiac !== '' ) {
        $extra_bits[] = $zodiac;
    }
    if ( ! empty( $extra_bits ) ) {
        $bday_display .= '（' . implode( ' · ', $extra_bits ) . '）';
    }

    $basic_info_rows[] = [ '生日', $bday_display ];
}
if ( ! empty( $person['bloodtype'] ) ) {
    $basic_info_rows[] = [ '血型', $person['bloodtype'] ];
}
if ( ! empty( $person['height'] ) ) {
    $basic_info_rows[] = [ '身高', $person['height'] ];
}

/* 其餘別名:跳過已在標題區顯示的日/英,其餘保留 */
$alias_skip_labels = [ '英文名', '英文名稱', '日文名', '日文名稱' ];
foreach ( $person_aliases as $alias ) {
    $a_label = isset( $alias['label'] ) ? trim( (string) $alias['label'] ) : '';
    $a_value = isset( $alias['value'] ) ? trim( (string) $alias['value'] ) : '';
    if ( $a_value === '' ) continue;
    if ( in_array( $a_label, $alias_skip_labels, true ) ) continue;
    if ( $a_value === $name_ja || $a_value === $name_en ) continue;
    $basic_info_rows[] = [ $a_label !== '' ? $a_label : '別名', $a_value ];
}

/* ── BGM 其他資料:infobox 通用展開,排除已在基本資料顯示的欄位 ── */
$infobox_all  = ( isset( $person['infobox'] ) && is_array( $person['infobox'] ) ) ? $person['infobox'] : [];
$infobox_skip = [ '性别', '性別', '生日', '血型', '身高', '身長' ];
$extra_info_rows = [];
foreach ( $infobox_all as $item ) {
    $i_label = isset( $item['label'] ) ? trim( (string) $item['label'] ) : '';
    $i_value = isset( $item['value'] ) ? trim( (string) $item['value'] ) : '';
    if ( $i_value === '' || $i_label === '' ) continue;
    if ( in_array( $i_label, $infobox_skip, true ) ) continue;
    // 判斷是否為連結
    $link = asa_infobox_link( $i_label, $i_value );
    $extra_info_rows[] = [ 'label' => $i_label, 'value' => $i_value, 'link' => $link ];
}

$person_summary_raw = isset( $person['summary'] ) ? trim( (string) $person['summary'] ) : '';

/* 純文字版：拿掉 BBCode 標記符號，給 JSON-LD schema description／thin-content 判斷用 */
$person_summary = $person_summary_raw !== ''
    ? trim( wp_strip_all_tags( preg_replace( '/\[\/?(?:b|url(?:=[^\]]*)?|mask)\]/u', '', $person_summary_raw ) ) )
    : '';

/* HTML 版：BBCode 轉安全 HTML，給前台簡介區塊顯示用 */
$person_summary_html = $person_summary_raw !== '' ? wpautop( asa_render_bgm_bbcode( $person_summary_raw ) ) : '';

/* ── JSON-LD：Person schema ── */
$schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'Person',
    'name'     => $person['name'],
    'url'      => home_url( '/person/' . $person['bgm_id'] . '/' ),
];
if ( $name_ja !== '' && $name_ja !== $person['name'] ) {
    $schema['alternateName'] = $name_ja;
}
if ( $person['image'] !== '' ) {
    $schema['image'] = $person['image'];
}
if ( $is_cv ) {
    $schema['jobTitle'] = '聲優';
}
if ( ! empty( $person['gender'] ) && in_array( $person['gender'], [ '男', '女' ], true ) ) {
    $schema['gender'] = ( $person['gender'] === '男' ) ? 'Male' : 'Female';
}
if ( $person_summary !== '' ) {
    $schema['description'] = $person_summary;
}
$same_as = [];
if ( $person['bgm_id'] > 0 ) {
    $same_as[] = 'https://bgm.tv/person/' . $person['bgm_id'];
}
if ( $person['anilist_id'] > 0 ) {
    $same_as[] = 'https://anilist.co/staff/' . $person['anilist_id'];
}
if ( $person['mal_id'] > 0 ) {
    $same_as[] = 'https://myanimelist.net/people/' . $person['mal_id'];
}
if ( ! empty( $same_as ) ) {
    $schema['sameAs'] = $same_as;
}

/* ── 外部連結按鈕 ── */
$external_links = [];
if ( $person['bgm_id'] > 0 ) {
    $external_links[] = [ 'label' => 'Bangumi', 'icon' => '🍡', 'url' => 'https://bgm.tv/person/' . $person['bgm_id'] ];
}
if ( $person['anilist_id'] > 0 ) {
    $external_links[] = [ 'label' => 'AniList', 'icon' => '🔵', 'url' => 'https://anilist.co/staff/' . $person['anilist_id'] ];
}
if ( $person['mal_id'] > 0 ) {
    $external_links[] = [ 'label' => 'MyAnimeList', 'icon' => '🔵', 'url' => 'https://myanimelist.net/people/' . $person['mal_id'] ];
}

/* ── [v1.5.2] 無簡介時透過 RankMath filter 動態 noindex,follow ──
 * 聲優/製作人員頁若沒有原創簡介文字，只剩「名字 + 圖 + 作品列表」，屬於
 * 資料卡片型頁面，Google 會視為機器生成的薄內容。這裡不直接印
 * <meta name="robots">，而是掛 RankMath 的 rank_math/frontend/robots
 * filter，避免和 RankMath 自己輸出的 robots meta 重複衝突。
 * 未來該人物補上簡介後，$person_summary 不再是空字串，
 * 此區塊就不會觸發，頁面自動恢復可索引。
 *
 * [v1.5.3] 同時把判斷結果寫進 $GLOBALS['asa_page_is_thin']，
 * 讓 child theme functions.php 的 AdSense 腳本載入判斷（掛在
 * wp_head，執行時機晚於這裡）可以直接讀這個旗標，不用再自己
 * 呼叫一次 Anime_Sync_Entity_Repository 查詢同一筆人物資料。
 */
$asa_has_real_content = ( $person_summary !== '' );
if ( ! $asa_has_real_content ) {
    $GLOBALS['asa_page_is_thin'] = true;
    add_filter( 'rank_math/frontend/robots', function ( $robots ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
        unset( $robots['noarchive'], $robots['nosnippet'] );
        return $robots;
    } );
}

get_header();
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="asa-entity-page asa-person-page">

    <nav class="asa-breadcrumb" aria-label="麵包屑導航">
        <ol>
            <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
            <li><?php echo esc_html( $role_label ); ?></li>
            <li><?php echo esc_html( $person['name'] ); ?></li>
        </ol>
    </nav>

    <div class="asa-entity-layout">

        <aside class="asa-layout-side">
            <div class="asa-side-card">

                <div class="asa-side-avatar">
                    <?php if ( $person['image'] !== '' ) : ?>
                        <img src="<?php echo esc_url( $person['image'] ); ?>"
                             alt="<?php echo esc_attr( $person['name'] ); ?>"
                             loading="eager"
                             onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="asa-entity-avatar-fb" style="display:none"><span><?php echo esc_html( $person_fallback ); ?></span></div>
                    <?php else : ?>
                        <div class="asa-entity-avatar-fb"><span><?php echo esc_html( $person_fallback ); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="asa-side-name">
                    <h1 class="asa-side-name-main"><?php echo esc_html( $name_tw !== '' ? $name_tw : $person['name'] ); ?></h1>
                    <?php if ( ! empty( $name_multi ) ) : ?>
                        <div class="asa-side-name-langs">
                            <?php foreach ( $name_multi as $nm ) : ?>
                                <p class="asa-side-name-alt">
                                    <span class="asa-name-lang-tag"><?php echo esc_html( $nm['lang'] ); ?></span>
                                    <span class="asa-name-lang-val"><?php echo esc_html( $nm['value'] ); ?></span>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $works_count > 0 ) : ?>
                    <div class="asa-side-works-count">
                        🎬 參與 <strong><?php echo (int) $works_count; ?></strong> 部作品
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $basic_info_rows ) ) : ?>
                    <div class="asa-infolist">
                        <?php foreach ( $basic_info_rows as $row ) : ?>
                            <div class="asa-infolist-row">
                                <span class="asa-infolist-label"><?php echo esc_html( $row[0] ); ?></span>
                                <span class="asa-infolist-val"><?php echo esc_html( $row[1] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $extra_info_rows ) ) : ?>
                    <div class="asa-infolist asa-infolist-extra">
                        <div class="asa-infolist-subtitle">其他資料</div>
                        <?php foreach ( $extra_info_rows as $row ) : ?>
                            <div class="asa-infolist-row">
                                <span class="asa-infolist-label"><?php echo esc_html( $row['label'] ); ?></span>
                                <span class="asa-infolist-val">
                                    <?php if ( is_array( $row['link'] ) ) : ?>
                                        <a href="<?php echo esc_url( $row['link'][0] ); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="asa-infolist-link"><?php echo esc_html( $row['link'][1] ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $row['value'] ); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $external_links ) ) : ?>
                    <div class="asa-side-actions">
                        <?php foreach ( $external_links as $el ) : ?>
                            <a href="<?php echo esc_url( $el['url'] ); ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="asa-action-btn">
                                <span><?php echo esc_html( $el['icon'] ); ?></span>
                                <span><?php echo esc_html( $el['label'] ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </aside>

        <div class="asa-layout-main">

            <header class="asa-entity-header">
                <span class="asa-entity-label"><?php echo $is_cv ? '🎙️ 聲優個人頁' : '🛠️ 製作人員個人頁'; ?></span>
            </header>

            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <?php
                $ase_nonce = wp_create_nonce( 'asp_entity_edit' );
                $ase_ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
                ?>
                <div class="asp-entity-edit-block" style="margin:12px 0;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">
                    <button type="button" class="asp-entity-edit-toggle button" style="cursor:pointer;">✏️ 修正資料（僅管理員可見）</button>
                    <div class="asp-entity-edit-form" hidden style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                        <label style="display:block;">姓名<br>
                            <input type="text" class="ase-f-name" value="<?php echo esc_attr( $person['name'] ); ?>" style="width:100%;max-width:400px;">
                        </label>
                        <label style="display:block;">簡介<br>
                            <textarea class="ase-f-summary" rows="6" style="width:100%;max-width:600px;font-family:inherit;"><?php echo esc_textarea( $person['summary'] ?? '' ); ?></textarea><br>
                            <button type="button" class="asp-entity-deepl-btn button" style="margin-top:4px;">🌐 DeepL 翻譯建議</button>
                        </label>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;">
                            <label>性別 <input type="text" class="ase-f-gender" value="<?php echo esc_attr( $person['gender'] ?? '' ); ?>" style="width:80px;"></label>
                            <label>生日 <input type="text" class="ase-f-birthday" value="<?php echo esc_attr( $person['birthday'] ?? '' ); ?>" style="width:120px;"></label>
                            <label>血型 <input type="text" class="ase-f-bloodtype" value="<?php echo esc_attr( $person['bloodtype'] ?? '' ); ?>" style="width:60px;"></label>
                            <label>身高 <input type="text" class="ase-f-height" value="<?php echo esc_attr( $person['height'] ?? '' ); ?>" style="width:80px;"></label>
                        </div>
                        <div>
                            <button type="button" class="asp-entity-save-btn button button-primary">💾 儲存</button>
                            <span class="asp-entity-edit-status" style="margin-left:8px;font-size:12px;color:#666;"></span>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    'use strict';
                    var block = document.currentScript.previousElementSibling;
                    if (!block || !block.classList.contains('asp-entity-edit-block')) {
                        block = document.querySelector('.asp-entity-edit-block');
                    }
                    if (!block) return;

                    var toggle = block.querySelector('.asp-entity-edit-toggle');
                    var form   = block.querySelector('.asp-entity-edit-form');
                    var status = block.querySelector('.asp-entity-edit-status');
                    var ajaxUrl = <?php echo wp_json_encode( $ase_ajax ); ?>;
                    var nonce   = <?php echo wp_json_encode( $ase_nonce ); ?>;
                    var bgmId   = <?php echo (int) $person_bgm_id; ?>;

                    toggle.addEventListener('click', function () {
                        form.hidden = !form.hidden;
                    });

                    block.querySelector('.asp-entity-deepl-btn').addEventListener('click', function () {
                        var btn = this;
                        var ta  = block.querySelector('.ase-f-summary');
                        var text = ta.value.trim();
                        if (!text) { status.textContent = '簡介是空的，沒東西可翻'; return; }
                        btn.disabled = true;
                        status.textContent = '翻譯中…';
                        var body = new URLSearchParams();
                        body.set('action', 'asp_entity_deepl_suggest');
                        body.set('nonce', nonce);
                        body.set('text', text);
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                btn.disabled = false;
                                if (res.success) {
                                    ta.value = res.data.translated;
                                    status.textContent = '✅ 已填入翻譯建議，記得按下方「儲存」才會存檔';
                                } else {
                                    status.textContent = '❌ ' + (res.data || '翻譯失敗');
                                }
                            })
                            .catch(function () {
                                btn.disabled = false;
                                status.textContent = '❌ 網路錯誤';
                            });
                    });

                    block.querySelector('.asp-entity-save-btn').addEventListener('click', function () {
                        var btn = this;
                        btn.disabled = true;
                        status.textContent = '儲存中…';
                        var body = new URLSearchParams();
                        body.set('action', 'asp_entity_save_edit');
                        body.set('nonce', nonce);
                        body.set('entity_type', 'person');
                        body.set('bgm_id', String(bgmId));
                        body.set('name', block.querySelector('.ase-f-name').value);
                        body.set('summary', block.querySelector('.ase-f-summary').value);
                        body.set('gender', block.querySelector('.ase-f-gender').value);
                        body.set('birthday', block.querySelector('.ase-f-birthday').value);
                        body.set('bloodtype', block.querySelector('.ase-f-bloodtype').value);
                        body.set('height', block.querySelector('.ase-f-height').value);
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                btn.disabled = false;
                                status.textContent = res.success ? '✅ 已儲存，重新整理頁面可看到最新內容' : '❌ ' + (res.data || '儲存失敗');
                            })
                            .catch(function () {
                                btn.disabled = false;
                                status.textContent = '❌ 網路錯誤';
                            });
                    });
                })();
                </script>
            <?php endif; ?>

            <?php if ( $person_summary !== '' ) : ?>
                <section class="asa-entity-summary">
                    <h2 class="asa-section-title">簡介</h2>
                    <?php echo $person_summary_html; ?>
                </section>
            <?php endif; ?>

            <section class="asa-entity-works">
                <div class="asa-section-title-row">
                    <h2 class="asa-section-title">
                        參與作品
                        <span class="asa-count">(<?php echo (int) $works_count; ?>)</span>
                    </h2>

                    <?php if ( $works_count > 6 ) : ?>
                        <input type="search" id="asa-work-search" class="asa-work-search"
                               placeholder="🔍 搜尋作品名稱…" autocomplete="off"
                               aria-label="搜尋參與作品">
                    <?php endif; ?>
                </div>

                <?php if ( empty( $works ) ) : ?>
                    <div class="asa-empty">
                        <div class="asa-empty-icon">📭</div>
                        <p>目前沒有可顯示的作品。</p>
                    </div>
                <?php else : ?>
                    <ul class="asa-works-grid" id="asa-works-grid">
                        <?php foreach ( $works as $w ) :
                            $w_title    = trim( (string) $w['title'] );
                            $w_fallback = $w_title === '' ? '動漫' : ( function_exists( 'mb_substr' ) ? mb_substr( $w_title, 0, 2 ) : substr( $w_title, 0, 2 ) );
                        ?>
                            <li class="asa-work-card" data-title="<?php echo esc_attr( mb_strtolower( $w_title ) ); ?>">
                                <a href="<?php echo esc_url( $w['url'] ); ?>" class="asa-work-link">
                                    <span class="asa-work-cover">
                                        <?php if ( $w['cover'] !== '' ) : ?>
                                            <img src="<?php echo esc_url( $w['cover'] ); ?>"
                                                 alt="<?php echo esc_attr( $w_title ); ?>"
                                                 loading="lazy"
                                                 onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                            <span class="asa-work-cover-fb" style="display:none"><?php echo esc_html( $w_fallback ); ?></span>
                                        <?php else : ?>
                                            <span class="asa-work-cover-fb"><?php echo esc_html( $w_fallback ); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="asa-work-title"><?php echo esc_html( $w_title ); ?></span>
                                </a>

                                <?php if ( ! empty( $w['character_name'] ) || trim( (string) $w['role'] ) !== '' ) : ?>
                                    <p class="asa-work-character">
                                        <?php if ( ! empty( $w['character_name'] ) ) : ?>
                                            <?php if ( (int) $w['character_bgm_id'] > 0 ) : ?>
                                                飾
                                                <a href="<?php echo esc_url( $repo->get_character( (int) $w['character_bgm_id'] )['url'] ?? '#' ); ?>">
                                                    <?php echo esc_html( $w['character_name'] ); ?>
                                                </a>
                                            <?php else : ?>
                                                飾 <?php echo esc_html( $w['character_name'] ); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ( trim( (string) $w['role'] ) !== '' ) : ?>
                                            <span class="asa-role-badge"><?php echo esc_html( trim( $w['role'] ) ); ?></span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <p class="asa-noresult" id="asa-noresult" hidden>
                        找不到符合「<span id="asa-noresult-kw"></span>」的作品。
                    </p>
                <?php endif; ?>
            </section>

            <?php
            /*
             * 留言：與角色頁相同做法。人物本身不是文章，因此用一篇影子文章
             * 當留言載體；沿用 asa_char_comments 這個既有類型（只是換一個
             * meta key 區分），可以省掉再註冊一個文章類型，也不必再擴充
             * Anime_Sync_Review_Manager::allowed_post_types()。
             *
             * 載體採「第一次有人瀏覽時才建立」，避免為一萬多筆人物預先產生
             * 用不到的文章。
             */
            if ( ! function_exists( 'asa_get_person_comment_post_id' ) ) {
                function asa_get_person_comment_post_id( array $person ) {
                    $bgm_id = (int) ( $person['bgm_id'] ?? 0 );
                    if ( $bgm_id <= 0 ) {
                        return 0;
                    }

                    $existing = get_posts( [
                        'post_type'      => 'asa_char_comments',
                        'post_status'    => 'publish',
                        'posts_per_page' => 1,
                        'no_found_rows'  => true,
                        'meta_query'     => [
                            [
                                'key'     => 'asa_person_bgm_id',
                                'value'   => $bgm_id,
                                'compare' => '=',
                                'type'    => 'NUMERIC',
                            ],
                        ],
                    ] );

                    if ( ! empty( $existing ) ) {
                        return (int) $existing[0]->ID;
                    }

                    $new_id = wp_insert_post( [
                        'post_type'      => 'asa_char_comments',
                        'post_status'    => 'publish',
                        'post_title'     => wp_strip_all_tags( (string) $person['name'] ) . '（人物留言）',
                        'comment_status' => 'closed',
                        'ping_status'    => 'closed',
                    ], true );

                    if ( is_wp_error( $new_id ) || ! $new_id ) {
                        return 0;
                    }

                    update_post_meta( $new_id, 'asa_person_bgm_id', $bgm_id );
                    return (int) $new_id;
                }
            }

            $person_comment_post_id = asa_get_person_comment_post_id( $person );
            ?>

            <?php if ( $person_comment_post_id > 0 ) : ?>
                <section class="asa-entity-comments" id="asa-sec-comments">
                    <h2 class="asa-section-title">💬 留言</h2>
                    <div
                        class="asd-review-root"
                        id="asd-review-root"
                        data-anime-id="<?php echo (int) $person_comment_post_id; ?>"
                        data-episodes="[]"
                        data-tracks="short"
                        data-noun="留言"
                    >
                        <p class="asd-review-loading">留言載入中…</p>
                    </div>
                </section>
            <?php endif; ?>

        </div><!-- /.asa-layout-main -->

    </div><!-- /.asa-entity-layout -->

</div>

<?php if ( $works_count > 6 ) : ?>
<script>
(function () {
    'use strict';
    var input = document.getElementById('asa-work-search');
    var grid  = document.getElementById('asa-works-grid');
    var noRes = document.getElementById('asa-noresult');
    var noResKw = document.getElementById('asa-noresult-kw');
    if (!input || !grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.asa-work-card'));

    function filter() {
        var kw = input.value.trim().toLowerCase();
        var shown = 0;
        cards.forEach(function (c) {
            var title = c.getAttribute('data-title') || '';
            var match = kw === '' || title.indexOf(kw) !== -1;
            c.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        if (noRes) {
            if (shown === 0 && kw !== '') {
                noRes.removeAttribute('hidden');
                if (noResKw) noResKw.textContent = input.value.trim();
            } else {
                noRes.setAttribute('hidden', '');
            }
        }
    }

    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(filter, 120);
    });
})();
</script>
<?php endif; ?>

<?php
get_footer();