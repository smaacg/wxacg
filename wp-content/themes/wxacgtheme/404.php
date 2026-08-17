<?php
/**
 * 404 Template — 微笑動漫 weixiaoacg
 *
 * @package weixiaoacg
 */
get_header();
?>

<main class="page-404" id="page-404">

  <!-- 飄落粒子背景 -->
  <div class="page-404__floats" aria-hidden="true" id="p404-floats"></div>

  <!-- 紫色光暈 -->
  <div class="page-404__bg" aria-hidden="true"></div>

  <!-- Canvas 星塵 -->
  <canvas id="p404-canvas" class="p404-canvas" aria-hidden="true"></canvas>

  <!-- 主內容 -->
  <div class="page-404__content">

    <div class="page-404__number" aria-label="404">404</div>

    <h1 class="page-404__subtitle">
      <span class="page-404__grad-text">嗚呀！頁面走丟了</span>
    </h1>

    <p class="page-404__copy">
      這個頁面可能已被刪除、更名，或者只是去追新番忘了回來。<br>
      不過別擔心，我們的動漫宇宙還有好多等著你探索！🚀
    </p>

    <div class="page-404__actions">
      <a href="<?php echo esc_url( home_url('/') ); ?>" class="btn btn-primary">
        <i class="fa-solid fa-house" aria-hidden="true"></i> 回到首頁
      </a>
      <a href="<?php echo esc_url( home_url('/anime/') ); ?>" class="btn btn-secondary">
        <i class="fa-solid fa-database" aria-hidden="true"></i> 動漫百科
      </a>
      <a href="<?php echo esc_url( home_url('/ranking/') ); ?>" class="btn btn-ghost">
        <i class="fa-solid fa-trophy" aria-hidden="true"></i> 排行榜
      </a>
    </div>

    <!-- 搜尋框 -->
    <div class="page-404__search">
      <form role="search" method="get" action="<?php echo esc_url( home_url('/') ); ?>">
        <div class="page-404__search-box">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <input
            type="search"
            name="s"
            placeholder="搜尋動漫、角色、新聞…"
            autocomplete="off"
            aria-label="搜尋"
          >
          <button type="submit" class="page-404__search-btn">搜尋</button>
        </div>
      </form>
    </div>

    <!-- 快速連結 -->
    <div class="page-404__quick">
      <p class="page-404__quick-label">或者去這些地方看看：</p>
      <div class="page-404__quick-links">
        <a href="<?php echo esc_url( home_url('/bangumi/') ); ?>" class="page-404__quick-btn">
          <i class="fa-solid fa-calendar-days" aria-hidden="true"></i> 新番導覽
        </a>
        <a href="<?php echo esc_url( home_url('/news/') ); ?>" class="page-404__quick-btn">
          <i class="fa-solid fa-newspaper" aria-hidden="true"></i> 最新新聞
        </a>
        <a href="<?php echo esc_url( home_url('/ranking/') ); ?>" class="page-404__quick-btn">
          <i class="fa-solid fa-ranking-star" aria-hidden="true"></i> 排行榜
        </a>
        <a href="<?php echo esc_url( home_url('/about/') ); ?>" class="page-404__quick-btn">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i> 關於我們
        </a>
        <a href="<?php echo esc_url( home_url('/contact/') ); ?>" class="page-404__quick-btn">
          <i class="fa-solid fa-envelope" aria-hidden="true"></i> 聯絡我們
        </a>
      </div>
    </div>

  </div><!-- /.page-404__content -->

</main>

<!-- JS：星塵 Canvas ＋ 飄落粒子 -->
<script>
(function () {
  'use strict';

  /* ── Canvas 星塵 ── */
  var canvas = document.getElementById('p404-canvas');
  if (canvas) {
    var ctx   = canvas.getContext('2d');
    var stars = [];
    var W, H;
    var STAR_COUNT = 90;

    function resize() {
      W = canvas.width  = canvas.offsetWidth;
      H = canvas.height = canvas.offsetHeight;
    }

    function initStars() {
      stars = [];
      for (var i = 0; i < STAR_COUNT; i++) {
        stars.push({
          x      : Math.random() * W,
          y      : Math.random() * H,
          r      : Math.random() * 1.8 + 0.3,
          speed  : Math.random() * 0.4 + 0.1,
          alpha  : Math.random() * 0.7 + 0.2,
          flicker: Math.random() * Math.PI * 2
        });
      }
    }

    function drawStars() {
      ctx.clearRect(0, 0, W, H);
      stars.forEach(function (s) {
        s.flicker += 0.02;
        var a = s.alpha * (0.7 + 0.3 * Math.sin(s.flicker));
        ctx.beginPath();
        ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(180,160,255,' + a + ')';
        ctx.fill();
        s.y -= s.speed;
        if (s.y < -4) { s.y = H + 4; s.x = Math.random() * W; }
      });
      requestAnimationFrame(drawStars);
    }

    resize();
    initStars();
    requestAnimationFrame(drawStars);
    window.addEventListener('resize', function () { resize(); initStars(); }, { passive: true });
  }

  /* ── 飄落元素 ── */
  var floatContainer = document.getElementById('p404-floats');
  if (floatContainer) {
    var symbols = [
      '🌸','⭐','🌙','✨','🍃','🎌','💫','🌟',
      '🔮','🗡️','🌸','✨','⭐','💫','🌙','🍃'
    ];
    symbols.forEach(function (sym, i) {
      var el = document.createElement('div');
      el.className   = 'p404-float-el';
      el.textContent = sym;
      el.setAttribute('aria-hidden', 'true');
      el.style.left             = ((i / symbols.length) * 100 + Math.random() * 4) + '%';
      el.style.animationDuration= (8 + Math.random() * 10) + 's';
      el.style.animationDelay   = (-Math.random() * 10) + 's';
      el.style.fontSize         = (14 + Math.random() * 12) + 'px';
      el.style.opacity          = (0.08 + Math.random() * 0.12).toFixed(2);
      floatContainer.appendChild(el);
    });
  }

})();
</script>

<?php get_footer(); ?>
