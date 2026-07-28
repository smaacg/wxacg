<?php
/**
 * Single Character 角色 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-character.php
 *
 * 由 class-entity-routing.php 於命中 /character/{id} 時載入。
 *
 * Changelog:
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

/* ── JSON-LD：Person schema(虛構角色沒有 Character type 被 Google 廣泛支援，
   用 Person + disambiguatingDescription 標註，是常見替代做法） ── */
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
    $external_links[] = [ 'label' => 'MyAnimeList', 'icon' => '🔵', 'url' => 'https://myanimelist.net/character/' . $character['mal_id'] ];
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