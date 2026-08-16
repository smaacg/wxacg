<?php
/**
 * Author Archive Template
 *
 * Path: wp-content/themes/blocksy-child/author.php
 * @version 1.0.0 (2026-08-16)
 *
 * 原本沒有 author.php，WordPress 一路退回主題最通用的樣板，完全沒有
 * 站內的卡片/縮圖樣式。這裡直接沿用 category.php 已經在用的
 * news.css + template-parts/news-list.php（文章卡片含縮圖），
 * 只是把查詢主體從分類換成作者，並加一個作者簡介區塊
 * （沿用 single.php 的 .single-author-box 同一套視覺）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$author_id      = (int) get_queried_object_id();
$author_name    = get_the_author_meta( 'display_name', $author_id );
$author_bio     = trim( (string) get_the_author_meta( 'description', $author_id ) );
$author_website = get_the_author_meta( 'user_url', $author_id );
$author_avatar  = get_avatar_url( $author_id, [ 'size' => 96 ] );
$author_post_cnt = (int) count_user_posts( $author_id, 'post', true );

get_header();
?>

<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/news.css' ); ?>" />

<!-- ===== PAGE HERO：作者資訊 ===== -->
<div class="page-hero">
  <div class="container">
    <div class="author-archive-box">
      <img
        src="<?php echo esc_url( $author_avatar ); ?>"
        alt="<?php echo esc_attr( $author_name ); ?>"
        class="author-archive-avatar"
        width="96" height="96"
        loading="eager"
        decoding="async"
      />
      <div class="author-archive-info">
        <span class="page-badge"><i class="fa-solid fa-pen-nib"></i> 作者</span>
        <h1 class="page-title"><?php echo esc_html( $author_name ); ?></h1>
        <?php if ( $author_bio !== '' ) : ?>
          <p class="page-subtitle"><?php echo esc_html( $author_bio ); ?></p>
        <?php endif; ?>
        <div class="author-archive-meta">
          <span>
            <i class="fa-solid fa-newspaper" aria-hidden="true"></i>
            已發表 <?php echo esc_html( number_format_i18n( $author_post_cnt ) ); ?> 篇文章
          </span>
          <?php if ( $author_website ) : ?>
            <a href="<?php echo esc_url( $author_website ); ?>" target="_blank" rel="noopener noreferrer nofollow">
              <i class="fa-solid fa-link" aria-hidden="true"></i> 個人網站
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== MAIN：文章卡片列表（含縮圖） ===== -->
<main class="container" style="padding: 32px 0 64px;">
  <div class="news-main-grid">
    <?php get_template_part( 'template-parts/news-list' ); ?>
  </div>
</main>

<style>
.author-archive-box {
  display: flex;
  gap: 20px;
  align-items: flex-start;
}
.author-archive-avatar {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  object-fit: cover;
  flex: 0 0 auto;
  border: 2px solid var(--glass-border, rgba(255, 255, 255, .14));
}
.author-archive-info {
  min-width: 0;
  flex: 1 1 auto;
}
.author-archive-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  align-items: center;
  margin-top: 14px;
  font-size: 13px;
  color: var(--text-muted, #9aa4b2);
}
.author-archive-meta a {
  color: var(--accent-blue, #60a5fa);
  text-decoration: none;
  font-weight: 700;
}
.author-archive-meta a:hover,
.author-archive-meta a:focus {
  text-decoration: underline;
}
@media (max-width: 600px) {
  .author-archive-box { flex-direction: column; align-items: center; text-align: center; }
  .author-archive-meta { justify-content: center; }
}
</style>

<?php get_footer(); ?>
