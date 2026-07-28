<?php
/**
 * Single Character 角色 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-character.php
 *
 * 由 class-entity-routing.php 於命中 /character/{id} 時載入。
 *
 * Changelog:
 *   1.4.0 (2026-07-29)
 *     - [改版] 套用 entity.css v1.4.0 的 bgm.tv 式兩欄版型：
 *              .asa-entity-layout > .asa-layout-side（頭像 + 基本資料 asa-infolist
 *              + 外部連結按鈕）/ .asa-layout-main（Header 名稱徽章 + 簡介 +
 *              登場作品 + 關聯角色）。
 *              修正舊版 .asa-entity-avatar 頭像在寬螢幕下因 max-width:42vw
 *              (無上限) 被撐到將近 800px 寬、900px+ 高的問題 —
 *              新版頭像改放進固定 260px 的側欄容器，不再吃 42vw。
 *     - [新增] Header 加入「✏ 糾錯回報」按鈕，比照 single-anime.php 的
 *              asd-action-btn 樣式與 data-action，錨點指向
 *              #asa-sec-corrections。未登入時導向登入頁再導回。
 *     - [新增] 底部「基本資料回報」表單區塊 [wxacg_correction_form]，
 *              沿用 anime 頁相同 shortcode（若該 shortcode 需要 entity
 *              type/id 參數，請依你們後台實作補上，目前先原樣帶入）。
 *     - [保留] 留言（comments_template()）本頁尚未加入 — 見下方說明。
 *              character 資料來自自訂 repository（以 bgm_id 為主鍵），
 *              並非 WP 原生 post，comments_template() 需要綁定一個真的
 *              $post 物件才能運作，所以無法像 single-anime.php 一樣直接
 *              呼叫。這段留言到聊天室裡跟你確認要怎麼接。
 *   （以下 1.3.1 起沿用原邏輯不變）
 *   1.3.1 (2026-07-28)
 *     - [移除] 相簿 Gallery 區塊。
 *   1.3.0 (2026-07-28)
 *     - [新增] 基本資料/簡介/關聯角色區塊。
 *   1.2.0 (2026-07-28)
 *     - [改版] Hero 區塊重做、fallback、外部連結按鈕。
 *   1.1.0 / 1.0.0 — 初版。
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

/* ── 姓名縮寫 fallback（無立繪時使用） ── */
$character_fallback = trim( wp_strip_all_tags( (string) $character['name'] ) );
$character_fallback = $character_fallback === '' ? 'AN' : ( function_exists( 'mb_substr' ) ? mb_substr( $character_fallback, 0, 2 ) : substr( $character_fallback, 0, 2 ) );

/* ── 基本資料：性別 / 生日 / 血型（皆為 optional 欄位） ──
   期望的資料形狀：
     $character['gender']    string  例如 '男'
     $character['birthday']  string  例如 '3月2日'
     $character['bloodtype'] string  例如 'B'
     $character['aliases']   array   例如 [ ['label' => '暱稱', 'value' => '...'], ... ]
     $character['summary']   string  角色簡介純文字
*/
$basic_info_rows = [];
if ( ! empty( $character['gender'] ) ) {
    $basic_info_rows[] = [ '性別', $character['gender'] ];
}
if ( ! empty( $character['birthday'] ) ) {
    $basic_info_rows[] = [ '生日', $character['birthday'] ];
}
if ( ! empty( $character['bloodtype'] ) ) {
    $basic_info_rows[] = [ '血型', $character['bloodtype'] ];
}

$character_aliases = ( isset( $character['aliases'] ) && is_array( $character['aliases'] ) ) ? $character['aliases'] : [];
foreach ( $character_aliases as $alias ) {
    $a_label = isset( $alias['label'] ) ? trim( (string) $alias['label'] ) : '';
    $a_value = isset( $alias['value'] ) ? trim( (string) $alias['value'] ) : '';
    if ( $a_value === '' ) continue;
    $basic_info_rows[] = [ $a_label !== '' ? $a_label : '別名', $a_value ];
}

$character_summary = isset( $character['summary'] ) ? trim( wp_strip_all_tags( (string) $character['summary'] ) ) : '';

/* ── 關聯角色：repository 尚未實作對應方法時自動略過 ── */
$character_relations = method_exists( $repo, 'get_character_relations' ) ? (array) $repo->get_character_relations( $character_bgm_id ) : [];

/* ── JSON-LD：Person schema ── */
$schema = [
    '@context'                  => 'https://schema.org',
    '@type'                     => 'Person',
    'name'                      => $character['name'],
    'url'                       => home_url( '/character/' . $character['bgm_id'] . '/' ),
    'disambiguatingDescription' => '動漫作品中的虛構角色',
];
if ( $character['name_original'] !== '' && $character['name_original'] !== $character['name'] ) {
    $schema['alternateName'] = $character['name_original'];
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

/* ── 外部連結按鈕（放到左側欄） ── */
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

/* ── 糾錯回報連結：見下方 header 內的 if/else，直接用 $character_permalink 組登入導回網址 ── */
$character_permalink = home_url( '/character/' . $character['bgm_id'] . '/' );

/* ── [1.4.0] wpDiscuz 留言：影子 Post ──
 * wpDiscuz／WP 原生留言系統一律綁在真正的 $post（comment_post_ID）上，
 * 而角色頁的 $character 是用 bgm_id 查 repository 撈出來的陣列，不是
 * WP post，沒有現成的 post_id 可以掛留言。
 *
 * 解法：每個角色對應一篇「影子 post」（post_type = asa_char_comments，
 * 非公開、不會被收錄索引、只拿來當留言的掛勾），以 meta
 * asa_character_bgm_id 尋找／建立，找不到就自動建一篇。
 *
 * [重要] 這個 post type 也要記得去 wpDiscuz 後台設定：
 *   wpDiscuz → Settings → General → 載入文章類型
 *   把 asa_char_comments 打勾，留言 UI 才會完整顯示（跟 anime 頁的
 *   備註是同一件事）。
 *
 * [建議] 目前為求本檔案能獨立運作，註冊 post type 的程式碼直接寫在
 * 這個模板裡（並用 post_type_exists 防重複註冊）。正式環境建議把
 * asa_register_char_comment_cpt() 移到主外掛的 init hook 裡，效能更好、
 * 也才會在還沒進到這個模板前就完成註冊。
 */
if ( ! post_type_exists( 'asa_char_comments' ) ) {
    register_post_type( 'asa_char_comments', [
        'label'               => '角色留言掛載',
        // [重要] wpDiscuz「Settings → General → 載入文章類型」的核取方塊清單，
        // 是依 public === true 的 post type 撈出來的；上一版設成 public => false
        // 導致這個 post type 完全不會出現在清單裡，選不到、也開不了留言。
        // 這裡改成 public => true，但用其他旗標把它「隱形」：
        //   - publicly_queryable => false → 前台不會有單獨網址可以直接開到
        //   - exclude_from_search => true → 不會被搜尋收錄
        //   - rewrite => false / has_archive => false → 不產生 permalink 規則
        //   - show_in_menu => false → 不會出現在後台側邊選單
        // show_ui 保留 true，是因為部分外掛（含 wpDiscuz）判斷「可用文章類型」
        // 時除了 public 還會看 show_ui；show_in_menu=false 已經足夠把它從側邊欄藏起來。
        'public'              => true,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_nav_menus'   => false,
        'show_in_admin_bar'   => false,
        'show_in_rest'        => false,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title', 'comments' ],
    ] );
}

/**
 * 取得（或建立）角色對應的留言掛載 post，回傳 post_id。
 *
 * @param array $character 來自 $repo->get_character() 的角色資料。
 * @return int
 */
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

$character_comment_post_id = asa_get_character_comment_post_id( $character );

get_header();
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>

<div class="asa-entity-page asa-character-page">

    <nav class="asa-breadcrumb" aria-label="麵包屑導航">
        <ol>
            <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
            <li>角色</li>
            <li><?php echo esc_html( $character['name'] ); ?></li>
        </ol>
    </nav>

    <div class="asa-entity-layout">

        <!-- ============ 左欄：頭像 + 基本資料 + 外部連結 ============ -->
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

        <!-- ============ 右欄：名稱／簡介／作品／關聯 ============ -->
        <div class="asa-layout-main">

            <header class="asa-entity-header">
                <span class="asa-entity-label">🎭 角色個別頁</span>
                <h1 class="asa-entity-name"><?php echo esc_html( $character['name'] ); ?></h1>
                <?php if ( $character['name_original'] !== '' && $character['name_original'] !== $character['name'] ) : ?>
                    <p class="asa-entity-name-original"><?php echo esc_html( $character['name_original'] ); ?></p>
                <?php endif; ?>

                <div class="asa-entity-badges">
                    <span class="asa-ebadge">角色</span>
                    <?php if ( $works_count > 0 ) : ?>
                        <span class="asa-ebadge asa-ebadge--accent">登場 <?php echo (int) $works_count; ?> 部作品</span>
                    <?php endif; ?>
                </div>

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
                                        echo implode( '、', $cv_links ); // 已 esc,安全
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

            <!-- ============ 糾錯回報表單 ============ -->
            <section class="asa-entity-corrections" id="asa-sec-corrections">
                <h2 class="asa-section-title">✏ 糾錯回報</h2>
                <?php
                /*
                 * [待確認] 沿用 anime 頁相同的 [wxacg_correction_form] shortcode。
                 * anime 頁的表單能自動抓到當下這篇 WP 文章的 post_id，但角色頁
                 * 的資料不是 WP post，是 repository 用 bgm_id 撈出來的陣列，
                 * 所以這裡先把 bgm_id / 類型帶成參數，實際欄位名稱要對照你們
                 * shortcode handler 目前吃的 attribute 再調整。
                 */
                echo do_shortcode(
                    '[wxacg_correction_form entity_type="character" entity_id="' . (int) $character['bgm_id'] . '"]'
                );
                ?>
            </section>

            <?php /* ============ 留言（wpDiscuz，掛在影子 post 上） ============ */ ?>
            <?php if ( $character_comment_post_id > 0 ) : ?>
                <section class="asa-entity-comments" id="asa-sec-comments">
                    <h2 class="asa-section-title">💬 留言</h2>
                    <?php
                    /*
                     * comments_template() 內部依賴全域 $post / $wp_query->post
                     * 來判斷 comment_post_ID、open_status 等，所以這裡要暫時把
                     * 全域 $post 換成影子 post，跑完再用 wp_reset_postdata()
                     * 換回原本頁面的 query（雖然這頁本來就沒有真正的主 post，
                     * 但養成 reset 的習慣比較保險，避免影響到後面任何用到
                     * global $post 的程式碼，例如頁尾的追蹤程式碼或外掛）。
                     */
                    global $post;
                    $__asa_original_post = $post;

                    $post = get_post( $character_comment_post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
                    setup_postdata( $post );

                    comments_template();

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