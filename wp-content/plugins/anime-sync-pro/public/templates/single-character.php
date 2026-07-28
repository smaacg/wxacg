<?php
/**
 * Single Character 角色 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-character.php
 *
 * 由 class-entity-routing.php 於命中 /character/{id} 時載入。
 *
 * Changelog:
 *   1.3.1 (2026-07-28)
 *     - [移除] 相簿 Gallery 區塊（連同未定義的 $character_photos 變數）。
 *              repository 1.2.0 已移除 get_character_photos()，本區塊
 *              永遠不渲染且會產生 undefined variable Notice，故整段刪除。
 *   1.3.0 (2026-07-28)
 *     - [新增] 參考 bgm.tv 角色頁版型，新增「基本資料」區塊：性別、
 *              生日、血型、別名/暱稱/稱號（$character['gender'] /
 *              ['birthday'] / ['bloodtype'] / ['aliases']）。
 *              全部欄位都是 optional，沒資料就不顯示這個區塊。
 *     - [新增] 簡介段落（$character['summary']），對齊 bgm 角色頁
 *              左上角色描述文字。
 *     - [新增] 關聯角色 Relations（$repo->get_character_relations()），
 *              依關係類型（朋友/親屬/配偶...）分組顯示，圓形頭像 +
 *              無圖 fallback 色塊。同樣是 optional，資料庫沒建置
 *              對應方法時整段不渲染。
 *     - [調整] 頁面區塊順序改為：Header → 基本資料 → 簡介 →
 *              登場作品 → 關聯角色，對齊 bgm 角色頁的
 *              資訊優先順序。
 *   1.2.0 (2026-07-28)
 *     - [改版] Hero 區塊比照 single-anime.php 視覺語彙重做：
 *              大頭照改為直式海報比例(3:4)並限制寬度，不再是過大的
 *              140x140 正方形；無圖時改用漸層色塊 + 姓名縮寫 fallback
 *              （對齊 asd-poster-fallback 的做法）。
 *     - [新增] Hero 徽章列（身份 / 登場作品數，asa-ebadge），
 *              對齊 asd-hero-badges 的圓角膠囊樣式。
 *     - [新增] Hero 外部連結按鈕列（Bangumi / AniList / MyAnimeList，
 *              直接沿用既有 $same_as 來源的 ID，不重複計算），
 *              對齊 asd-hero-actions 的 ghost 按鈕樣式。
 *     - [新增] 作品卡封面 fallback：無封面圖時顯示作品名縮寫色塊，
 *              取代原本「沒有圖就整個 cover 區塊消失」的做法。
 *     - [移除] 舊版 asa-entity-role-tag 純文字身份標籤（改由徽章列呈現）。
 *   1.1.0 (2026-07-28)
 *     - [新增] 麵包屑導航(參考 series-index.php)
 *     - [新增] Person JSON-LD 結構化資料(disambiguatingDescription 標註為虛構角色,
 *              含 sameAs → bgm/anilist/mal)
 *     - [新增] 登場作品數 > 6 時顯示即時搜尋框(純前端過濾,參考 series-index.php)
 *     - [優化] 空狀態加入圖示,與 series-index.php 視覺語彙一致
 *   1.0.0
 *     - [新增] 初版。
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

/* ── [1.2.0] 姓名縮寫 fallback（無立繪時使用，對齊 asd 的 $fallback_text 邏輯） ── */
$character_fallback = trim( wp_strip_all_tags( (string) $character['name'] ) );
$character_fallback = $character_fallback === '' ? 'AN' : ( function_exists( 'mb_substr' ) ? mb_substr( $character_fallback, 0, 2 ) : substr( $character_fallback, 0, 2 ) );

/* ── [1.3.0] 基本資料：性別 / 生日 / 血型（皆為 optional 欄位） ──
   期望的資料形狀：
     $character['gender']    string  例如 '男'
     $character['birthday']  string  例如 '甲龍曆407年/公曆11月22日'
     $character['bloodtype'] string  例如 'A'
     $character['aliases']   array   例如 [ ['label' => '暱稱', 'value' => '魯迪'], ... ]
     $character['summary']   string  角色簡介純文字（會自動 strip tags 再 esc）
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

$character_summary = isset( $character['summary'] ) ? trim( wp_strip_all_tags( (string) $character['summary'] ) ) : '';

/* ── [1.4.0] 關聯角色：repository 尚未實作對應方法時自動略過 ──
   期望的資料形狀：
     get_character_relations($id) => [ ['relation' => '朋友', 'name' => ..., 'url' => ..., 'avatar' => ... (可選)], ... ]
*/
$character_relations = method_exists( $repo, 'get_character_relations' ) ? (array) $repo->get_character_relations( $character_bgm_id ) : [];
/* ── JSON-LD：Person schema(虛構角色沒有 Character type 被 Google 廣泛支援，
   用 Person + disambiguatingDescription 標註，是常見替代做法) ── */
$schema = [
    '@context'                 => 'https://schema.org',
    '@type'                    => 'Person',
    'name'                     => $character['name'],
    'url'                      => home_url( '/character/' . $character['bgm_id'] . '/' ),
    'disambiguatingDescription'=> '動漫作品中的虛構角色',
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

/* ── [1.2.0] Hero 外部連結按鈕（沿用上面已算好的 ID，不重複判斷） ── */
$external_links = [];
if ( $character['bgm_id'] > 0 ) {
    $external_links[] = [ 'label' => 'Bangumi', 'icon' => '🍡', 'url' => 'https://bgm.tv/character/' . $character['bgm_id'] ];
}
if ( $character['anilist_id'] > 0 ) {
    $external_links[] = [ 'label' => 'AniList', 'icon' => '🔵', 'url' => 'https://anilist.co/character/' . $character['anilist_id'] ];
}
if ( $character['mal_id'] > 0 ) {
    $external_links[] = [ 'label' => 'MyAnimeList', 'icon' => '🔵', 'url' => 'https://myanimelist.net/people/' . $character['mal_id'] ];
}

get_header();
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="asa-entity-page asa-character-page">

    <nav class="asa-breadcrumb" aria-label="麵包屑導航">
        <ol>
            <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
            <li>角色</li>
            <li><?php echo esc_html( $character['name'] ); ?></li>
        </ol>
    </nav>

    <header class="asa-entity-header">

        <div class="asa-entity-avatar-wrap">
            <div class="asa-entity-avatar">
                <?php if ( $character['image'] !== '' ) : ?>
                    <img src="<?php echo esc_url( $character['image'] ); ?>"
                         alt="<?php echo esc_attr( $character['name'] ); ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="asa-entity-avatar-fb" style="display:none"><span><?php echo esc_html( $character_fallback ); ?></span></div>
                <?php else : ?>
                    <div class="asa-entity-avatar-fb"><span><?php echo esc_html( $character_fallback ); ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="asa-entity-meta">
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

            <?php if ( ! empty( $external_links ) ) : ?>
                <div class="asa-entity-actions">
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
    </header>

    <?php if ( ! empty( $basic_info_rows ) || ! empty( $character_aliases ) ) : ?>
        <section class="asa-basic-info">
            <h2 class="asa-section-title">基本資料</h2>

            <?php if ( ! empty( $basic_info_rows ) ) : ?>
                <div class="asa-basic-info-grid">
                    <?php foreach ( $basic_info_rows as $row ) : ?>
                        <div class="asa-info-item">
                            <span class="asa-info-item-label"><?php echo esc_html( $row[0] ); ?></span>
                            <span class="asa-info-item-val"><?php echo esc_html( $row[1] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $character_aliases ) ) : ?>
                <div class="asa-alias-list">
                    <?php foreach ( $character_aliases as $alias ) :
                        $a_label = isset( $alias['label'] ) ? trim( (string) $alias['label'] ) : '';
                        $a_value = isset( $alias['value'] ) ? trim( (string) $alias['value'] ) : '';
                        if ( $a_value === '' ) continue;
                    ?>
                        <div class="asa-alias-row">
                            <?php if ( $a_label !== '' ) : ?>
                                <span class="asa-alias-label"><?php echo esc_html( $a_label ); ?></span>
                            <?php endif; ?>
                            <span class="asa-alias-val"><?php echo esc_html( $a_value ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

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
        /* 依關係類型分組，保留第一次出現的順序（對齊 bgm 角色頁的分組呈現） */
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
