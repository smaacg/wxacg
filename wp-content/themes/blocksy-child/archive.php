<?php
/**
 * 泛用封存頁樣板（保底 fallback）
 *
 * Path: wp-content/themes/blocksy-child/archive.php
 * @version 1.0.0 (2026-08-17)
 *
 * 給沒有專屬樣板的封存頁用（category.php／tag.php／taxonomy-channel.php
 * 已經涵蓋主要情境，這裡只是保底）。共用 news.css + news-list partial，
 * 視覺跟其他列表頁一致。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/news.css' ); ?>" />

<div class="page-hero">
  <div class="container">
    <h1 class="page-title"><?php the_archive_title(); ?></h1>
    <?php $archive_desc = get_the_archive_description(); ?>
    <?php if ( $archive_desc ) : ?>
      <p class="page-subtitle"><?php echo wp_kses_post( $archive_desc ); ?></p>
    <?php endif; ?>
  </div>
</div>

<main class="container" style="padding: 32px 0 64px;">
  <div class="news-main-grid">
    <?php get_template_part( 'template-parts/news-list' ); ?>
  </div>
</main>

<?php get_footer(); ?>
