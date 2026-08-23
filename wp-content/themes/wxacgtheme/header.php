<?php
/**
 * 微笑動漫 Child Theme — header.php
 * @version 2.2.1 (2026-06-23)
 *
 * 變更紀錄：
 * - v2.2.1 (2026-06-23) fallback 選單「新番」連結修正：/season/ → /bangumi/，
 *   高亮判斷由 is_page('season') 改為 get_query_var('bangumi_view')。
 * - v2.2.0 (2026-05-30) 手機版搜尋改巴哈式：常駐搜尋框改為放大鏡 toggle，
 *   點擊後全寬展開（沿用 #header-search-input / dropdown，header.js 不變）。
 * - v2.1.0 (2026-05-30) 手機漢堡選單移到 .header-top 最左（logo 之前）。
 * - v2.0.0 (2026-05-28) 大幅精簡：Login Modal、inline JS/CSS 外移等。
 *
 * @package weixiaoacg
 */

defined( 'ABSPATH' ) || exit;

/* 共用品牌資源 */
/*
 * Logo 網址與尺寸選擇的理由見 functions.php 的 wxacg_brand_logo_url()。
 * 集中在那裡是為了讓 header 與 footer 共用同一份，不會再出現一邊換圖、
 * 另一邊停在舊圖的情況。
 */
$brand_logo_url = function_exists( 'wxacg_brand_logo_url' )
	? wxacg_brand_logo_url()
	: 'https://weixiaoacg.com/wp-content/uploads/2026/08/wxacglogo-300x288.webp';

// Header 裝飾橫幅：網址與說明見 functions.php 的 wxacg_header_banner_url()。
$header_banner_url    = function_exists( 'wxacg_header_banner_url' ) ? wxacg_header_banner_url() : '';
$header_banner_fg_url = function_exists( 'wxacg_header_banner_fg_url' ) ? wxacg_header_banner_fg_url() : '';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>" />
  <meta name="viewport" id="wxacg-viewport" content="width=device-width, initial-scale=1.0" />
  <script>
  (function(){
    try {
      if (localStorage.getItem('wxacg_view_mode') === 'desktop') {
        var vp = document.getElementById('wxacg-viewport');
        if (vp) { vp.setAttribute('content', 'width=1280, initial-scale=0.3, minimum-scale=0.2, maximum-scale=3.0, user-scalable=yes'); }
        document.documentElement.classList.add('view-as-desktop');
      }
    } catch(e){}
  })();
  </script>
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php
/* ────────────────────────────────────────
   登入 Modal（訪客才輸出）
   ──────────────────────────────────────── */
if ( ! is_user_logged_in() ) {
    get_template_part( 'template-parts/login-modal' );
}
?>

<!-- ════════════════════════════════════════
     Header
════════════════════════════════════════ -->
<header class="site-header glass-mid" id="site-header">

  <?php if ( $header_banner_url !== '' ) : ?>
  <!--
    裝飾橫幅：純視覺，對輔助技術隱藏；視差由 header-banner.js 處理。
    疊層順序（由下而上）：背景 → 前景物件 → 遮罩。
    遮罩必須蓋在前景之上，否則前景不會被壓暗，看起來像貼上去的貼紙。
  -->
  <div class="header-banner" id="header-banner" aria-hidden="true">
    <div class="header-banner__img" style="background-image:url('<?php echo esc_url( $header_banner_url ); ?>');"></div>
    <?php if ( $header_banner_fg_url !== '' ) : ?>
    <div class="header-banner__fg" style="background-image:url('<?php echo esc_url( $header_banner_fg_url ); ?>');"></div>
    <?php endif; ?>
    <div class="header-banner__scrim"></div>
  </div>
  <?php endif; ?>

  <div class="header-top container">

    <!-- 手機漢堡選單（左側，≤900px 顯示；click 事件由 nav.js 處理）-->
    <button class="btn-icon btn-ghost mobile-menu-btn mobile-menu-btn--left" id="mobile-menu-toggle" aria-label="開關選單" aria-expanded="false" aria-controls="primary-nav">
      <i class="fa-solid fa-bars" aria-hidden="true"></i>
    </button>

    <!-- Logo -->
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="site-logo" aria-label="微笑動漫首頁">
      <span class="logo-icon-box" aria-hidden="true">
        <img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="weixiaoacg" class="brand-logo-img" />
      </span>
      <div class="logo-text-wrap">
        <span class="logo-text">微笑動漫<span class="logo-plus"></span></span>
        <span class="logo-tagline">&nbsp;&nbsp;動漫的便利商店</span>
      </div>
    </a>

    <!-- 手機版：放大鏡 toggle（≤900px 顯示；click 事件由 nav.js 處理）-->
    <button type="button" class="header-search-toggle" id="header-search-toggle" aria-label="搜尋" aria-expanded="false">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
    </button>

    <!-- 搜尋（桌機常駐；手機點放大鏡才全寬展開）-->
    <div class="header-search-wrap" id="header-search-wrap">
      <div class="header-search-box" id="header-search-box">
        <i class="fa-solid fa-magnifying-glass search-icon" aria-hidden="true"></i>
        <input type="text" id="header-search-input" class="header-search-input" placeholder="搜尋…" autocomplete="off" aria-label="搜尋" />
        <button class="header-search-btn" id="header-search-submit" aria-label="送出搜尋">搜尋</button>
        <button type="button" class="header-search-close" id="header-search-close" aria-label="關閉搜尋">關閉</button>
      </div>
      <div class="header-search-dropdown" id="header-search-dropdown" aria-live="polite"></div>
    </div>

    <!-- 右側 Actions -->
    <div class="header-actions">

      <?php if ( is_user_logged_in() ) :
          $user        = wp_get_current_user();
          $profile_url = function_exists( 'wxacg_get_member_center_url' )
              ? wxacg_get_member_center_url()
              : home_url( '/' );
      ?>
        <!-- 我的收藏
             愛心配收藏比配贊助直覺，所以愛心給收藏，贊助改用咖啡杯。
             #favorites 會被 member.js 讀走切到對應分頁（見 member.js 讀
             location.hash 的那段），不是單純的頁內錨點。 -->
        <a href="<?php echo esc_url( home_url('/mc/#favorites') ); ?>"
           class="header-fav-btn"
           aria-label="我的收藏"
           title="我的收藏">
          <i class="fa-solid fa-heart" aria-hidden="true"></i>
        </a>

        <!-- 贊助 -->
        <a href="<?php echo esc_url( home_url('/sponsor/') ); ?>"
           class="header-sponsor-btn"
           aria-label="贊助我們"
           title="贊助我們">
          <i class="fa-solid fa-mug-hot" aria-hidden="true"></i>
        </a>


        <!-- 通知鈴鐺 -->
        <div class="smacg-bell-wrap smacg-bell-inline" id="smacg-bell-wrap">
          <button type="button" class="smacg-bell" id="smacg-bell" aria-label="通知" aria-expanded="false">
            <i class="fa-solid fa-bell"></i>
            <span class="smacg-bell-dot" id="smacg-bell-dot" hidden></span>
          </button>
          <div class="smacg-bell-panel" id="smacg-bell-panel" hidden>
            <div class="smacg-bell-panel-header">
              <strong>通知</strong>
              <select class="smacg-bell-sound-select" id="smacg-bell-sound-select" aria-label="通知音效">
                <option value="off">🔇 音效關閉</option>
                <option value="01">🔊 音效 1</option>
                <option value="02">🔊 音效 2</option>
                <option value="03">🔊 音效 3</option>
              </select>
              <button type="button" class="smacg-bell-mark-all" id="smacg-bell-mark-all">全部已讀</button>
            </div>
            <div class="smacg-bell-panel-list" id="smacg-bell-panel-list">
              <div class="smacg-bell-loading"><i class="fa-solid fa-spinner fa-spin"></i> 載入中…</div>
            </div>
            <div class="smacg-bell-panel-footer">
              <a href="<?php echo esc_url( $profile_url . '?tab=notifications' ); ?>">查看全部 →</a>
            </div>
          </div>
        </div>

        <!-- 已登入：頭像下拉選單 -->
        <div class="header-user-dropdown">
          <button class="header-avatar-btn" id="header-avatar-btn" aria-expanded="false" aria-haspopup="true">
            <?php echo get_avatar( $user->ID, 32, '', '', [ 'class' => 'header-avatar' ] ); ?>
            <span class="header-username"><?php echo esc_html( $user->display_name ); ?></span>
            <i class="fa-solid fa-chevron-down header-avatar-arrow" aria-hidden="true"></i>
          </button>

          <div class="header-user-menu" id="header-user-menu" role="menu">
            <a href="<?php echo esc_url( $profile_url ); ?>" role="menuitem"><i class="fa-solid fa-user"></i> 個人頁面</a>
            <a href="<?php echo esc_url( $profile_url . '#settings' ); ?>" role="menuitem"><i class="fa-solid fa-gear"></i> 設定</a>
            <a href="<?php echo esc_url( home_url( '/announcement/' ) ); ?>" role="menuitem"><i class="fa-solid fa-bullhorn"></i> 站務中心</a>
            <div class="header-user-menu-divider" role="separator"></div>
            <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="logout-link" id="smacg-logout-link" role="menuitem"><i class="fa-solid fa-right-from-bracket"></i> 登出</a>
          </div>
        </div>

      <?php else : ?>

        <!-- 未登入：登入／註冊按鈕 -->
        <button type="button" class="btn btn-ghost btn-sm header-login-btn" id="open-login-modal"><i class="fa-solid fa-right-to-bracket"></i> 登入</button>
        <button type="button" class="btn btn-primary btn-sm header-reg-btn" id="open-register-modal">註冊</button>

      <?php endif; ?>

    </div><!-- /.header-actions -->
  </div><!-- /.header-top -->

  <!-- 導覽列（≤900px 由 nav.js 控制顯示）-->
  <div class="header-nav-bar" id="header-nav-bar">
    <div class="container">
      <nav class="primary-nav" id="primary-nav" aria-label="主選單">
        <?php
        if ( has_nav_menu( 'primary-menu' ) ) {
            wp_nav_menu( [
                'theme_location' => 'primary-menu',
                'container'      => false,
                'fallback_cb'    => false,
                'items_wrap'     => '%3$s',
                'walker'         => new weixiaoacg_Nav_Walker(),
            ] );
        } else {
            $nav_links = [
                [ 'url' => home_url( '/' ),         'label' => '首頁',    'icon' => 'fa-solid fa-house',                 'check' => is_front_page() ],
                [ 'url' => home_url( '/bangumi/' ), 'label' => '新番',    'icon' => 'fa-solid fa-calendar-days',         'check' => (bool) get_query_var( 'bangumi_view' ) ],
                [ 'url' => home_url( '/news/' ),    'label' => '新聞',    'icon' => 'fa-solid fa-newspaper',             'check' => is_page( 'news' ) ],
                [ 'url' => home_url( '/anime/' ),   'label' => '動漫',    'icon' => 'fa-solid fa-film',                  'check' => ( is_post_type_archive( 'anime' ) || is_singular( 'anime' ) ) ],
                [ 'url' => home_url( '/ranking/' ), 'label' => '排行',    'icon' => 'fa-solid fa-trophy',                'check' => is_page( 'ranking' ) ],
                [ 'url' => home_url( '/music/' ),   'label' => '音樂',    'icon' => 'fa-solid fa-music',                 'check' => is_page( 'music' ) ],
                [ 'url' => home_url( '/cosplay/' ), 'label' => 'COSPLAY', 'icon' => 'fa-solid fa-wand-magic-sparkles',   'check' => is_page( 'cosplay' ) ],
                [ 'url' => home_url( '/about/' ),   'label' => '關於',    'icon' => 'fa-solid fa-circle-info',           'check' => is_page( 'about' ) ],
            ];
            foreach ( $nav_links as $link ) {
                $active = $link['check'] ? ' active' : '';
                echo '<div class="nav-item"><a href="' . esc_url( $link['url'] ) . '" class="nav-link' . $active . '"><i class="' . esc_attr( $link['icon'] ) . '" aria-hidden="true"></i> ' . esc_html( $link['label'] ) . '</a></div>';
            }
        }
        ?>
      </nav>
    </div>
  </div><!-- /.header-nav-bar -->

</header>

<!-- v1.9.1 Mobile Nav Fix：必須在 nav DOM 出現後立即執行 -->
<script>
(function () {
  'use strict';
  var BREAKPOINT = 900;
  function moveNav() {
    var nav = document.getElementById('primary-nav');
    if (!nav) return;
    var isMobile = window.innerWidth <= BREAKPOINT;
    var inBody = nav.parentElement === document.body;
    var origin = document.querySelector('#header-nav-bar .container');
    if (isMobile && !inBody) document.body.appendChild(nav);
    else if (!isMobile && inBody && origin) origin.appendChild(nav);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', moveNav);
  else moveNav();
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(moveNav, 200);
  });
})();
</script>