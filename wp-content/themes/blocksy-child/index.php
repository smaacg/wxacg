<?php
/**
 * 主樣板（保底 fallback）
 *
 * Path: wp-content/themes/blocksy-child/index.php
 * @version 1.0.0 (2026-08-17)
 *
 * WordPress 規定每個主題都必須有這個檔案才算合法主題。實務上幾乎不
 * 會被用到——站上每種內容類型都已經有專屬樣板（single.php、
 * category.php、tag.php、各種 page-*.php…），這裡只是保底，避免真的
 * 遇到沒有專屬樣板的情境時整頁空白或報錯。
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
    <p>找不到內容。</p>
  <?php endif; ?>
</main>

<?php get_footer(); ?>
