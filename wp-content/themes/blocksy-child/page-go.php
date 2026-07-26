<?php
/**
 * Template Name: 外部連結中轉頁
 * Template Post Type: page
 */

$raw_url = isset( $_GET['url'] ) ? trim( $_GET['url'] ) : '';
$target  = filter_var( $raw_url, FILTER_VALIDATE_URL ) ? $raw_url : '';

$host = $target ? parse_url( $target, PHP_URL_HOST ) : '';
$host = $host ? preg_replace( '/^www\./', '', $host ) : '';

$site_host = parse_url( home_url(), PHP_URL_HOST );
if ( $host && $host === $site_host ) {
    wp_redirect( $target );
    exit;
}

get_header();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>即將離開 <?php bloginfo( 'name' ); ?></title>
  <?php wp_head(); ?>
  <style>
    .go-wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: var(--bg-base, #0d0f1a);
    }
    .go-card {
      background: var(--glass-bg, rgba(255,255,255,0.04));
      border: 1px solid var(--glass-border, rgba(255,255,255,0.08));
      border-radius: 24px;
      padding: 48px 40px;
      max-width: 480px;
      width: 100%;
      text-align: center;
      box-shadow: 0 24px 64px rgba(0,0,0,0.4);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }
    .go-icon {
      width: 72px;
      height: 72px;
      border-radius: 20px;
      background: rgba(255,180,0,0.12);
      border: 1px solid rgba(255,180,0,0.25);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
      margin: 0 auto 24px;
    }
    .go-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--text-primary, #e8eaf0);
      margin: 0 0 10px;
    }
    .go-subtitle {
      font-size: 13px;
      color: var(--text-muted, rgba(208,215,224,0.55));
      margin: 0 0 28px;
      line-height: 1.7;
    }
    .go-url-box {
      background: rgba(255,180,0,0.06);
      border: 1px solid rgba(255,180,0,0.2);
      border-radius: 12px;
      padding: 14px 16px;
      margin: 0 0 28px;
      text-align: left;
    }
    .go-url-box__label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.08em;
      color: rgba(255,180,0,0.7);
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .go-url-box__host {
      font-size: 15px;
      font-weight: 700;
      color: #ffb400;
      word-break: break-all;
      margin-bottom: 4px;
    }
    .go-url-box__full {
      font-size: 11px;
      color: var(--text-muted, rgba(208,215,224,0.45));
      word-break: break-all;
      line-height: 1.5;
    }
    .go-warning {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      background: rgba(255,80,80,0.07);
      border: 1px solid rgba(255,80,80,0.18);
      border-radius: 12px;
      padding: 12px 14px;
      margin: 0 0 28px;
      text-align: left;
    }
    .go-warning__icon {
      font-size: 14px;
      flex-shrink: 0;
      margin-top: 1px;
      color: #ff6b6b;
    }
    .go-warning__text {
      font-size: 12px;
      color: var(--text-muted, rgba(208,215,224,0.6));
      line-height: 1.7;
    }
    .go-warning__text strong {
      color: #ff6b6b;
      font-weight: 600;
    }
    .go-btns {
      display: flex;
      gap: 10px;
      flex-direction: column;
    }
    .go-btn-confirm {
      display: block;
      width: 100%;
      padding: 14px 20px;
      border-radius: 12px;
      background: linear-gradient(135deg, #ffb400 0%, #ff8c00 100%);
      color: #0d0f1a;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      border: none;
      cursor: pointer;
      transition: opacity 0.2s, transform 0.15s;
    }
    .go-btn-confirm:hover {
      opacity: 0.88;
      transform: translateY(-1px);
      color: #0d0f1a;
      text-decoration: none;
    }
    .go-btn-back {
      display: block;
      width: 100%;
      padding: 13px 20px;
      border-radius: 12px;
      background: transparent;
      color: var(--text-muted, rgba(208,215,224,0.55));
      font-size: 13px;
      font-weight: 500;
      text-decoration: none;
      border: 1px solid var(--glass-border, rgba(255,255,255,0.08));
      cursor: pointer;
      transition: border-color 0.2s, color 0.2s;
    }
    .go-btn-back:hover {
      border-color: rgba(255,255,255,0.2);
      color: var(--text-primary, #e8eaf0);
      text-decoration: none;
    }
    .go-invalid {
      color: #ff6b6b;
      font-size: 14px;
      padding: 20px 0;
    }
    @media (max-width: 480px) {
      .go-card { padding: 36px 24px; }
    }
  </style>
</head>
<body <?php body_class(); ?>>

<div class="go-wrap">
  <div class="go-card">

    <?php if ( $target ) : ?>

      <div class="go-icon">⚠️</div>
      <h1 class="go-title">即將離開 <?php bloginfo( 'name' ); ?></h1>
      <p class="go-subtitle">
        你即將前往外部網站，請確認連結安全後再繼續。<br>
        本站對外部網站的內容不負任何責任。
      </p>

      <div class="go-url-box">
        <div class="go-url-box__label">目的地網站</div>
        <?php if ( $host ) : ?>
          <div class="go-url-box__host"><?php echo esc_html( $host ); ?></div>
        <?php endif; ?>
        <div class="go-url-box__full"><?php echo esc_html( $target ); ?></div>
      </div>

      <div class="go-warning">
        <span class="go-warning__icon">🔒</span>
        <div class="go-warning__text">
          <strong><?php bloginfo( 'name' ); ?> 不對外部網站的內容、安全性及隱私政策負責。</strong>
          若你不確定此連結是否安全，請點「返回上一頁」。
        </div>
      </div>

      <div class="go-btns">
        <?php /* ★ 修改：移除 target="_blank"，在原分頁直接跳走；加 data-go-confirm 放行攔截 */ ?>
        <a class="go-btn-confirm"
           href="<?php echo esc_url( $target ); ?>"
           rel="noopener noreferrer"
           data-go-confirm>
          確認離開，前往外部網站 →
        </a>
        <a class="go-btn-back" href="javascript:history.back();">
          ← 返回上一頁
        </a>
      </div>

    <?php else : ?>

      <div class="go-icon">❌</div>
      <h1 class="go-title">連結無效</h1>
      <p class="go-invalid">此連結不存在或格式不正確。</p>
      <a class="go-btn-back" href="<?php echo esc_url( home_url() ); ?>">
        ← 回到首頁
      </a>

    <?php endif; ?>

  </div>
</div>

<?php wp_footer(); ?>
</body>
</html>
