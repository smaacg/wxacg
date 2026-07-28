<?php
/**
 * Single Person 聲優/製作人員 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-person.php
 *
 * 由 class-entity-routing.php 於命中 /person/{id} 時載入。
 * 資料全走 Anime_Sync_Entity_Repository(唯讀,含快取)。
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

$works = $repo->get_person_works( $person_bgm_id ); // 預設只回 cast

get_header();
?>

<div class="asa-entity-page asa-person-page">

    <header class="asa-entity-header">
        <?php if ( $person['image'] !== '' ) : ?>
            <div class="asa-entity-avatar">
                <img src="<?php echo esc_url( $person['image'] ); ?>"
                     alt="<?php echo esc_attr( $person['name'] ); ?>"
                     loading="lazy">
            </div>
        <?php endif; ?>

        <div class="asa-entity-meta">
            <h1 class="asa-entity-name"><?php echo esc_html( $person['name'] ); ?></h1>

            <?php if ( $person['name_original'] !== '' && $person['name_original'] !== $person['name'] ) : ?>
                <p class="asa-entity-name-original"><?php echo esc_html( $person['name_original'] ); ?></p>
            <?php endif; ?>

            <p class="asa-entity-role-tag">
                <?php echo $person['type'] === 'cv' ? '聲優' : '製作人員'; ?>
            </p>
        </div>
    </header>

    <section class="asa-entity-works">
        <h2 class="asa-section-title">
            參與作品
            <span class="asa-count">(<?php echo count( $works ); ?>)</span>
        </h2>

        <?php if ( empty( $works ) ) : ?>
            <p class="asa-empty">目前沒有可顯示的作品。</p>
        <?php else : ?>
            <ul class="asa-works-grid">
                <?php foreach ( $works as $w ) : ?>
                    <li class="asa-work-card">
                        <a href="<?php echo esc_url( $w['url'] ); ?>" class="asa-work-link">
                            <?php if ( $w['cover'] !== '' ) : ?>
                                <span class="asa-work-cover">
                                    <img src="<?php echo esc_url( $w['cover'] ); ?>"
                                         alt="<?php echo esc_attr( $w['title'] ); ?>"
                                         loading="lazy">
                                </span>
                            <?php endif; ?>
                            <span class="asa-work-title"><?php echo esc_html( trim( (string) $w['title'] ) ); ?></span>
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
        <?php endif; ?>
    </section>

</div>

<?php
get_footer();