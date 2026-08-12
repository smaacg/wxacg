<?php
/**
 * Single Character 角色 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-character.php
 *
 * Changelog:
 *   1.6.2 (2026-08-12)
 *     - [新增] 判定為 thin content 時，寫入 $GLOBALS['asa_page_is_thin']，
 *              讓 child theme functions.php 的 AdSense 腳本載入判斷
 *              直接讀這個旗標，不用再自己查一次 Anime_Sync_Entity_Repository。
 *   1.6.1 (2026-08-12)
 *     - [新增] 無簡介(character_summary 為空)時，透過 RankMath
 *              rank_math/frontend/robots filter 動態輸出 noindex,follow，
 *              避免大量純資料卡片頁被 Google 判定為 Thin Content。
 *   1.6.0 (2026-07-29)
 *     - [新增] 基本資料加身高、體重;新增星座(由 birthday 推算,不進 DB)。
 *     - [新增] 「BGM 資料」通用展開區塊:把 repository 回傳的 infobox
 *              (排除已顯示的性別/生日/身高/體重等)全部列出。
 *   1.5.1 (2026-07-29) - [修正] summary 清 [mask]/[/mask] 劇透 BBCode。
 *   1.5.0 (2026-07-29) - [新增] 四語主名(繁/簡/日/英)。
 *   1.4.1 (2026-07-29) - [修正] 檔尾 parse error;函式 function_exists 包裹。
 *   1.4.0 (2026-07-29) - [改版] 兩欄版型、糾錯按鈕、wpDiscuz 留言。
 *   1.3.1 / 1.3.0 / 1.2.0 / 1.1.0 / 1.0.0 — 早期版本。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$character_bgm_id = (int) get_query_var( 'asa_character_id' );

$repo      = new Anime_Sync_Entity_Repository();
$character = $repo->get_character( $character_bgm_id );

if ( null === $character ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
    include get_query_template( '404' );
    return;
}

$works       = $repo->get_character_works( $character_bgm_id );
$works_count = count( $works );

/* ── 提取唯一的配音員 (CV) ── */
$character_cvs = [];
$cvs_map       = [];
foreach ( $works as $w ) {
    if ( ! empty( $w['voice_actors'] ) ) {
        foreach ( $w['voice_actors'] as $va ) {
            $va_id = (int) $va['bgm_id'];
            if ( $va_id > 0 && ! isset( $cvs_map[ $va_id ] ) ) {
                $cvs_map[ $va_id ] = true;
                $character_cvs[]   = $va;
            }
        }
    }
}

/* ── 星座:由「N月N日」推算,無法解析回空字串 ── */
if ( ! function_exists( 'asa_zodiac_from_birthday' ) ) {
    function asa_zodiac_from_birthday( string $birthday ): string {
        if ( ! preg_match( '/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $birthday, $m ) ) {
            return '';
        }
        $mon = (int) $m[1];
        $day = (int) $m[2];
        if ( $mon < 1 || $mon > 12 || $day < 1 || $day > 31 ) {
            return '';
        }
        $signs = [
            1  => [ 20, '摩羯座', '水瓶座' ],
            2  => [ 19, '水瓶座', '雙魚座' ],
            3  => [ 21, '雙魚座', '牡羊座' ],
            4  => [ 20, '牡羊座', '金牛座' ],
            5  => [ 21, '金牛座', '雙子座' ],
            6  => [ 22, '雙子座', '巨蟹座' ],
            7  => [ 23, '巨蟹座', '獅子座' ],
            8  => [ 23, '獅子座', '處女座' ],
            9  => [ 23, '處女座', '天秤座' ],
            10 => [ 24, '天秤座', '天蠍座' ],
            11 => [ 23, '天蠍座', '射手座' ],
            12 => [ 22, '射手座', '摩羯座' ],
        ];
        [ $cut, $before, $after ] = $signs[ $mon ];
        return $day < $cut ? $before : $after;
    }
}

/* ── 姓名縮寫 fallback（無立繪時使用） ── */
$character_fallback = trim( wp_strip_all_tags( (string) $character['name'] ) );
$character_fallback = $character_fallback === '' ? 'AN' : ( function_exists( 'mb_substr' ) ? mb_substr( $character_fallback, 0, 2 ) : substr( $character_fallback, 0, 2 ) );

/* ── 四語主名:繁 name / 簡 name_cn / 日 name_original / 英(從別名抽) ── */
$name_tw = trim( (string) $character['name'] );
$name_cn = isset( $character['name_cn'] ) ? trim( (string) $character['name_cn'] ) : '';
$name_ja = trim( (string) $character['name_original'] );

$character_aliases = ( isset( $character['aliases'] ) && is_array( $character['aliases'] ) ) ? $character['aliases'] : [];

$name_en = '';
foreach ( $character_aliases as $alias ) {
    $a_label = isset( $alias['label'] ) ? trim( (string) $alias['label'] ) : '';
    $a_value = isset( $alias['value'] ) ? trim( (string) $alias['value'] ) : '';
    if ( $a_value === '' ) continue;
    if ( $a_label === '英文名' || $a_label === '英文名稱' ) {
        $name_en = $a_value;
        break;
    }
}

$name_multi = [];
if ( $name_cn !== '' && $name_cn !== $name_tw ) $name_multi[] = [ 'lang' => '簡', 'value' => $name_cn ];
if ( $name_ja !== '' && $name_ja !== $name_tw ) $name_multi[] = [ 'lang' => '日', 'value' => $name_ja ];
if ( $name_en !== '' )                          $name_multi[] = [ 'lang' => 'EN', 'value' => $name_en ];

/* ── 基本資料：性別 / 生日 / 星座 / 血型 / 身高 / 體重 / 其餘別名 ── */
$basic_info_rows = [];
if ( ! empty( $character['gender'] ) ) {
    $basic_info_rows[] = [ '性別', $character['gender'] ];
}
if ( ! empty( $character['birthday'] ) ) {
    $basic_info_rows[] = [ '生日', $character['birthday'] ];
    $zodiac = asa_zodiac_from_birthday( (string) $character['birthday'] );
    if ( $zodiac !== '' ) {
        $basic_info_rows[] = [ '星座', $zodiac ];
    }
}
if ( ! empty( $character['bloodtype'] ) ) {
    $basic_info_rows[] = [ '血型', $character['bloodtype'] ];
}
if ( ! empty( $character['height'] ) ) {
    $basic_info_rows[] = [ '身高', $character['height'] ];
}
if ( ! empty( $character['weight'] ) ) {
    $basic_info_rows[] = [ '體重', $character['weight'] ];
}

/* 其餘別名:跳過已在標題區顯示的簡/日/英,其餘(純假名、羅馬字等)保留 */
$alias_skip_labels = [ '英文名', '英文名稱', '简体中文名', '簡體中文名', '日文名', '日文名稱' ];
foreach ( $character_aliases as $alias ) {
    $a_label = isset( $alias['label'] ) ? trim( (string) $alias['label'] ) : '';
    $a_value = isset( $alias['value'] ) ? trim( (string) $alias['value'] ) : '';
    if ( $a_value === '' ) continue;
    if ( in_array( $a_label, $alias_skip_labels, true ) ) continue;
    if ( $a_value === $name_cn || $a_value === $name_ja || $a_value === $name_en ) continue;
    $basic_info_rows[] = [ $a_label !== '' ? $a_label : '別名', $a_value ];
}

/* ── BGM 其他資料:infobox 通用展開,排除已在基本資料顯示的欄位 ── */
$infobox_all  = ( isset( $character['infobox'] ) && is_array( $character['infobox'] ) ) ? $character['infobox'] : [];
$infobox_skip = [ '性别', '性別', '生日', '血型', '身高', '身長', '体重', '體重' ];
$extra_info_rows = [];
foreach ( $infobox_all as $item ) {
    $i_label = isset( $item['label'] ) ? trim( (string) $item['label'] ) : '';
    $i_value = isset( $item['value'] ) ? trim( (string) $item['value'] ) : '';
    if ( $i_value === '' || $i_label === '' ) continue;
    if ( in_array( $i_label, $infobox_skip, true ) ) continue;
    $extra_info_rows[] = [ $i_label, $i_value ];
}

/* summary:先清掉 Bangumi BBCode 劇透標籤 [mask]/[/mask],再 strip HTML */
$character_summary = isset( $character['summary'] )
    ? trim( wp_strip_all_tags( str_replace( [ '[mask]', '[/mask]' ], '', (string) $character['summary'] ) ) )
    : '';

/* ── 關聯角色 ── */
$character_relations = method_exists( $repo, 'get_character_relations' ) ? (array) $repo->get_character_relations( $character_bgm_id ) : [];

/* ── JSON-LD：Person schema ── */
$schema = [
    '@context'                  => 'https://schema.org',
    '@type'                     => 'Person',
    'name'                      => $character['name'],
    'url'                       => home_url( '/character/' . $character['bgm_id'] . '/' ),
    'disambiguatingDescription' => '動漫作品中的虛構角色',
];
$schema_alt = [];
if ( $name_ja !== '' && $name_ja !== $character['name'] ) $schema_alt[] = $name_ja;
if ( $name_cn !== '' && $name_cn !== $character['name'] ) $schema_alt[] = $name_cn;
if ( $name_en !== '' )                                    $schema_alt[] = $name_en;
if ( ! empty( $schema_alt ) ) {
    $schema['alternateName'] = count( $schema_alt ) === 1 ? $schema_alt[0] : $schema_alt;
}
if ( $character['image'] !== '' ) {
    $schema['image'] = $character['image'];
}
if ( ! empty( $character['gender'] ) && in_array( $character['gender'], [ '男', '女' ], true ) ) {
    $schema['gender'] = ( $character['gender'] === '男' ) ? 'Male' : 'Female';
}
if ( $character_summary !== '' ) {
    $schema['description'] = $character_summary;
}
$same_as = [];
if ( $character['bgm_id'] > 0 ) {
    $same_as[] = 'https://bgm.tv/character/' . $character['bgm_id'];
}
if ( $character['anilist_id'] > 0 ) {
    $same_as[] = 'https://anilist.co/character/' . $character['anilist_id'];
}
if ( $character['mal_id'] > 0 ) {
    $same_as[] = 'https://myanimelist.net/character/' . $character['mal_id'];
}
if ( ! empty( $same_as ) ) {
    $schema['sameAs'] = $same_as;
}

/* ── 外部連結按鈕 ── */
$external_links = [];
if ( $character['bgm_id'] > 0 ) {
    $external_links[] = [ 'label' => 'Bangumi', 'icon' => '🍡', 'url' => 'https://bgm.tv/character/' . $character['bgm_id'] ];
}
if ( $character['anilist_id'] > 0 ) {
    $external_links[] = [ 'label' => 'AniList', 'icon' => '🔵', 'url' => 'https://anilist.co/character/' . $character['anilist_id'] ];
}
if ( $character['mal_id'] > 0 ) {
    $external_links[] = [ 'label' => 'MyAnimeList', 'icon' => '🔵', 'url' => 'https://myanimelist.net/character/' . $character['mal_id'] ];
}

$character_permalink = home_url( '/character/' . $character['bgm_id'] . '/' );

if ( ! function_exists( 'asa_get_character_comment_post_id' ) ) {
    function asa_get_character_comment_post_id( array $character ) {
        $bgm_id = (int) ( $character['bgm_id'] ?? 0 );
        if ( $bgm_id <= 0 ) return 0;

        $existing = get_posts( [
            'post_type'      => 'asa_char_comments',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'asa_character_bgm_id',
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
            'post_title'     => wp_strip_all_tags( (string) $character['name'] ) . '（角色留言）',
            'comment_status' => 'open',
            'ping_status'    => 'closed',
        ], true );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            return 0;
        }

        update_post_meta( $new_id, 'asa_character_bgm_id', $bgm_id );
        return (int) $new_id;
    }
}

$character_comment_post_id = asa_get_character_comment_post_id( $character );

/* ── [v1.6.1] 無簡介時透過 RankMath filter 動態 noindex,follow ──
 * 角色頁若沒有原創簡介文字，只剩「名字 + 圖 + 作品列表」，屬於資料
 * 卡片型頁面，Google 會視為機器生成的薄內容。這裡不直接印
 * <meta name="robots">，而是掛 RankMath 的 rank_math/frontend/robots
 * filter，避免和 RankMath 自己輸出的 robots meta 重複衝突。
 * 未來該角色補上簡介後，$character_summary 不再是空字串，
 * 此區塊就不會觸發，頁面自動恢復可索引。
 *
 * [v1.6.2] 同時把判斷結果寫進 $GLOBALS['asa_page_is_thin']，
 * 讓 child theme functions.php 的 AdSense 腳本載入判斷（掛在
 * wp_head，執行時機晚於這裡）可以直接讀這個旗標，不用再自己
 * 呼叫一次 Anime_Sync_Entity_Repository 查詢同一筆角色資料。
 */
$asa_has_real_content = ( $character_summary !== '' );
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

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>

<div class="asa-entity-page asa-character-page">

    <nav class="asa-breadcrumb" aria-label="麵包屑導航">
        <ol>
            <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
            <?php if ( ! empty( $works ) ) : ?>
                <li><a href="<?php echo esc_url( $works[0]['url'] ); ?>"><?php echo esc_html( $works[0]['title'] ); ?></a></li>
            <?php endif; ?>
            <li><?php echo esc_html( $character['name'] ); ?></li>
        </ol>
    </nav>


    <div class="asa-entity-layout">

        <aside class="asa-layout-side">
            <div class="asa-side-card">

                <div class="asa-side-avatar">
                    <?php if ( $character['image'] !== '' ) : ?>
                        <img src="<?php echo esc_url( $character['image'] ); ?>"
                             alt="<?php echo esc_attr( $character['name'] ); ?>"
                             loading="eager"
                             onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <div class="asa-entity-avatar-fb" style="display:none"><span><?php echo esc_html( $character_fallback ); ?></span></div>
                    <?php else : ?>
                        <div class="asa-entity-avatar-fb"><span><?php echo esc_html( $character_fallback ); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="asa-side-name">
                    <h1 class="asa-side-name-main"><?php echo esc_html( $name_tw !== '' ? $name_tw : $character['name'] ); ?></h1>
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
                        🎬 登場 <strong><?php echo (int) $works_count; ?></strong> 部作品
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $character_cvs ) || ! empty( $basic_info_rows ) ) : ?>
                    <div class="asa-infolist">
                        <?php if ( ! empty( $character_cvs ) ) : ?>
                            <div class="asa-infolist-row" style="align-items: center;">
                                <span class="asa-infolist-label">聲優(CV)</span>
                                <span class="asa-infolist-val" style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    <?php foreach ( $character_cvs as $cv ) : ?>
                                        <a href="<?php echo esc_url( $cv['url'] ); ?>" class="asa-role-badge" style="margin:0;">
                                            <?php echo esc_html( $cv['name'] ); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </span>
                            </div>
                        <?php endif; ?>

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
                                <span class="asa-infolist-label"><?php echo esc_html( $row[0] ); ?></span>
                                <span class="asa-infolist-val"><?php echo esc_html( $row[1] ); ?></span>
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
                <span class="asa-entity-label">🎭 角色個人頁</span>

                <div class="asa-entity-actions">
                    <?php if ( is_user_logged_in() ) : ?>
                        <a href="#asa-sec-corrections" class="asa-action-btn" id="asa-hero-corr-btn">✏ 糾錯回報</a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( wp_login_url( $character_permalink . '#asa-sec-corrections' ) ); ?>" class="asa-action-btn">✏ 糾錯回報</a>
                    <?php endif; ?>
                </div>
            </header>

                  <?php if ( $character_summary !== '' ) : ?>
                <section class="asa-entity-summary">
                    <h2 class="asa-section-title">角色介紹</h2>
                    <?php echo wpautop( esc_html( $character_summary ) ); ?>
                </section>
            <?php endif; ?>


            <section class="asa-entity-works">
                <div class="asa-section-title-row">
                    <h2 class="asa-section-title">
                        登場作品
                        <span class="asa-count">(<?php echo (int) $works_count; ?>)</span>
                    </h2>

                    <?php if ( $works_count > 6 ) : ?>
                        <input type="search" id="asa-work-search" class="asa-work-search"
                               placeholder="🔍 搜尋作品名稱…" autocomplete="off"
                               aria-label="搜尋登場作品">
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

                                <?php if ( ! empty( $w['voice_actors'] ) ) : ?>
                                    <p class="asa-work-cv">
                                        CV：
                                        <?php
                                        $cv_links = [];
                                        foreach ( $w['voice_actors'] as $va ) {
                                            $cv_links[] = '<a href="' . esc_url( $va['url'] ) . '">'
                                                . esc_html( $va['name'] ) . '</a>';
                                        }
                                        echo implode( '、', $cv_links );
                                        ?>
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

            <?php if ( ! empty( $character_relations ) ) :
                $relation_groups = [];
                foreach ( $character_relations as $rel ) {
                    $rel_type = isset( $rel['relation'] ) ? trim( (string) $rel['relation'] ) : '';
                    if ( $rel_type === '' ) $rel_type = '其他';
                    $relation_groups[ $rel_type ][] = $rel;
                }
            ?>
                <section class="asa-entity-relations">
                    <h2 class="asa-section-title">關聯角色</h2>

                    <?php foreach ( $relation_groups as $group_name => $items ) : ?>
                        <div class="asa-relations-group">
                            <h3 class="asa-relations-group-title"><?php echo esc_html( $group_name ); ?></h3>
                            <div class="asa-relations-grid">
                                <?php foreach ( $items as $item ) :
                                    $r_name   = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
                                    $r_url    = isset( $item['url'] ) ? $item['url'] : '#';
                                    $r_avatar = isset( $item['avatar'] ) ? $item['avatar'] : '';
                                    $r_fb     = $r_name === '' ? '?' : ( function_exists( 'mb_substr' ) ? mb_substr( $r_name, 0, 1 ) : substr( $r_name, 0, 1 ) );
                                ?>
                                    <a class="asa-relation-card" href="<?php echo esc_url( $r_url ); ?>">
                                        <span class="asa-relation-avatar">
                                            <?php if ( $r_avatar !== '' ) : ?>
                                                <img src="<?php echo esc_url( $r_avatar ); ?>"
                                                     alt="<?php echo esc_attr( $r_name ); ?>"
                                                     loading="lazy"
                                                     onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                <span class="asa-relation-avatar-fb" style="display:none"><?php echo esc_html( $r_fb ); ?></span>
                                            <?php else : ?>
                                                <span class="asa-relation-avatar-fb"><?php echo esc_html( $r_fb ); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="asa-relation-name"><?php echo esc_html( $r_name ); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <section class="asa-entity-corrections" id="asa-sec-corrections">
                <h2 class="asa-section-title">✏ 糾錯回報</h2>
                <?php
                if ( $character_comment_post_id > 0 ) {
                    global $post;
                    $__asa_original_post = $post;

                    $post = get_post( $character_comment_post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
                    setup_postdata( $post );

                    echo do_shortcode( '[wxacg_correction_form]' );

                    $post = $__asa_original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
                    wp_reset_postdata();
                } else {
                    echo '<p style="color:var(--asa-text-dim);font-size:.85rem;">糾錯表單暫時無法載入，請稍後再試。</p>';
                }
                ?>
            </section>

            <?php if ( $character_comment_post_id > 0 ) : ?>
                <section class="asa-entity-comments" id="asa-sec-comments">
                    <h2 class="asa-section-title">💬 留言</h2>
                    <?php
                    global $post, $wp_query;
                    $__asa_original_post = $post;

                    $post = get_post( $character_comment_post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
                    setup_postdata( $post );

                    // 暫時把主查詢偽裝成 singular,讓 wpDiscuz 的 is_singular() 通過
                    $__asa_saved_is_singular     = $wp_query->is_singular;
                    $__asa_saved_is_single       = $wp_query->is_single;
                    $__asa_saved_queried_obj     = $wp_query->queried_object;
                    $__asa_saved_queried_obj_id  = $wp_query->queried_object_id;

                    $wp_query->is_singular       = true;
                    $wp_query->is_single         = true;
                    $wp_query->queried_object    = $post;
                    $wp_query->queried_object_id = $post->ID;

                    comments_template();

                    // 還原
                    $wp_query->is_singular       = $__asa_saved_is_singular;
                    $wp_query->is_single         = $__asa_saved_is_single;
                    $wp_query->queried_object    = $__asa_saved_queried_obj;
                    $wp_query->queried_object_id = $__asa_saved_queried_obj_id;

                    $post = $__asa_original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
                    wp_reset_postdata();
                    ?>
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