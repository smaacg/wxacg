<?php
/**
 * 泛用 Page 樣板（保底 fallback）
 *
 * Path: wp-content/themes/blocksy-child/page.php
 * @version 1.0.0 (2026-08-17)
 *
 * 給後台新增頁面時沒有指定專屬樣板（page-*.php）的情況用。站上絕大多數
 * 頁面都已經指定了專屬樣板，這裡只是保底。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<main class="container single-wrap" style="padding: 24px 0 64px;">
  <?php if ( have_posts() ) : ?>
    <?php while ( have_posts() ) : the_post(); ?>
      <article class="single-article">
        <h1 class="single-title"><?php the_title(); ?></h1>
        <div class="single-content">
          <?php the_content(); ?>
        </div>
      </article>
    <?php endwhile; ?>
  <?php else : ?>
    <p>找不到這個頁面。</p>
  <?php endif; ?>
</main>

<?php get_footer(); ?>
