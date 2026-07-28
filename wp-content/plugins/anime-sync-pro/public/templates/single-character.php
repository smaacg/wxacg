<?php
/**
 * Single Character 角色 個別頁
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-character.php
 *
 * 由 class-entity-routing.php 於命中 /character/{id} 時載入。
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

$works = $repo->get_character_works( $character_bgm_id );

get_header();
?>

<div class="asa-entity-page asa-character-page">

    <header class="asa-entity-header">
        <?php if ( $character['image'] !== '' ) : ?>
            <div class="asa-entity-avatar">
                <img src="<?php echo esc_url( $character['image'] ); ?>"
                     alt="<?php echo esc_attr( $character['name'] ); ?>"
                     loading="lazy">
            </div>
        <?php endif; ?>

        <div class="asa-entity-meta">
            <h1 class="asa-entity-name"><?php echo esc_html( $character['name'] ); ?></h1>
            <?php if ( $character['name_original'] !== '' && $character['name_original'] !== $character['name'] ) : ?>
                <p class="asa-entity-name-original"><?php echo esc_html( $character['name_original'] ); ?></p>
            <?php endif; ?>
            <p class="asa-entity-role-tag">角色</p>
        </div>
    </header>

    <section class="asa-entity-works">
        <h2 class="asa-section-title">
            登場作品
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
                            <span class="asa-work-title"><?php echo esc_html( $w['title'] ); ?></span>
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
        <?php endif; ?>
    </section>

</div>

<?php
get_footer();
