<?php
/**
 * Single Person 聲優/製作人員 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-person.php
 *
 * 由 class-entity-routing.php 於命中 /person/{id} 時載入。
 * 資料全走 Anime_Sync_Entity_Repository(唯讀,含快取)。
 *
 * Changelog:
 *   1.2.0 (2026-07-28)
 *     - [改版] Hero 區塊比照 single-anime.php 視覺語彙重做：
 *              大頭照改為直式海報比例(3:4)並限制寬度，不再是過大的
 *              140x140 正方形；無圖時改用漸層色塊 + 姓名縮寫 fallback
 *              （對齊 asd-poster-fallback 的做法）。
 *     - [新增] Hero 徽章列（身份 / 參與作品數，asa-ebadge），
 *              對齊 asd-hero-badges 的圓角膠囊樣式。
 *     - [新增] Hero 外部連結按鈕列（Bangumi / AniList / MyAnimeList，
 *              直接沿用既有 $same_as 來源的 ID，不重複計算），
 *              對齊 asd-hero-actions 的 ghost 按鈕樣式。
 *     - [新增] 作品卡封面 fallback：無封面圖時顯示姓名縮寫色塊，
 *              取代原本「沒有圖就整個 cover 區塊消失」的做法。
 *     - [移除] 舊版 asa-entity-role-tag 純文字身份標籤（改由徽章列呈現）。
 *   1.1.0 (2026-07-28)
 *     - [新增] 麵包屑導航(參考 series-index.php)
 *     - [新增] Person JSON-LD 結構化資料(含 sameAs → bgm/anilist/mal)
 *     - [新增] 作品數 > 6 時顯示即時搜尋框(純前端過濾,參考 series-index.php)
 *     - [優化] 空狀態加入圖示,與 series-index.php 視覺語彙一致
 *   1.0.0
 *     - [新增] 初版。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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

$works       = $repo->get_person_works( $person_bgm_id ); // 預設只回 cast
$works_count = count( $works );
$is_cv       = ( $person['type'] === 'cv' );
$role_label  = $is_cv ? '聲優' : '製作人員';

/* ── [1.2.0] 姓名縮寫 fallback（無大頭照時使用，對齊 asd 的 $fallback_text 邏輯） ── */
$person_fallback = trim( wp_strip_all_tags( (string) $person['name'] ) );
$person_fallback = $person_fallback === '' ? 'AN' : ( function_exists( 'mb_substr' ) ? mb_substr( $person_fallback, 0, 2 ) : substr( $person_fallback, 0, 2 ) );

/* ── JSON-LD：Person schema，含 sameAs 外部資料庫連結 ── */
$schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'Person',
    'name'     => $person['name'],
    'url'      => home_url( '/person/' . $person['bgm_id'] . '/' ),
];
if ( $person['name_original'] !== '' && $person['name_original'] !== $person['name'] ) {
    $schema['alternateName'] = $person['name_original'];
}
if ( $person['image'] !== '' ) {
    $schema['image'] = $person['image'];
}
if ( $is_cv ) {
    $schema['jobTitle'] = '聲優';
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

/* ── [1.2.0] Hero 外部連結按鈕（沿用上面已算好的 ID，不重複判斷） ── */
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

    <header class="asa-entity-header">

        <div class="asa-entity-avatar-wrap">
            <div class="asa-entity-avatar">
                <?php if ( $person['image'] !== '' ) : ?>
                    <img src="<?php echo esc_url( $person['image'] ); ?>"
                         alt="<?php echo esc_attr( $person['name'] ); ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="asa-entity-avatar-fb" style="display:none"><span><?php echo esc_html( $person_fallback ); ?></span></div>
                <?php else : ?>
                    <div class="asa-entity-avatar-fb"><span><?php echo esc_html( $person_fallback ); ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="asa-entity-meta">
            <span class="asa-entity-label"><?php echo $is_cv ? '🎙️ 聲優個別頁' : '🛠️ 製作人員個別頁'; ?></span>
            <h1 class="asa-entity-name"><?php echo esc_html( $person['name'] ); ?></h1>

            <?php if ( $person['name_original'] !== '' && $person['name_original'] !== $person['name'] ) : ?>
                <p class="asa-entity-name-original"><?php echo esc_html( $person['name_original'] ); ?></p>
            <?php endif; ?>

            <div class="asa-entity-badges">
                <span class="asa-ebadge"><?php echo esc_html( $role_label ); ?></span>
                <?php if ( $works_count > 0 ) : ?>
                    <span class="asa-ebadge asa-ebadge--accent">參與 <?php echo (int) $works_count; ?> 部作品</span>
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

                        <?php if ( ! empty( $w['character_name'] ) ) : ?>
                            <p class="asa-work-character">
                                <?php if ( (int) $w['character_bgm_id'] > 0 ) : ?>
                                    飾
                                    <a href="<?php echo esc_url( $repo->get_character( (int) $w['character_bgm_id'] )['url'] ?? '#' ); ?>">
                                        <?php echo esc_html( $w['character_name'] ); ?>
                                    </a>
                                <?php else : ?>
                                    飾 <?php echo esc_html( $w['character_name'] ); ?>
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