<?php
/**
 * Template Name: Email 驗證頁
 *
 * @package weixiaoacg
 * @version 1.0.0 (2026-05-23)
 *
 * 用途：
 *   驗證信連結 https://weixiaoacg.com/verify-email/?uid=xxx&token=xxx
 *   的著陸頁，會自動呼叫 AJAX action `smacg_verify_email`
 *   並顯示驗證結果。
 *
 * 部署：
 *   1. 此檔案放在 blocksy-child/ 根目錄
 *   2. 後台 → 頁面 → 新增頁面
 *      - 標題：Email 驗證
 *      - Slug：verify-email
 *      - 頁面範本：選「Email 驗證頁」
 *      - 內容可留空（範本會自行渲染）
 *      - 發佈
 *   3. 驗證連結 https://weixiaoacg.com/verify-email/?uid=1&token=xxx 即可運作
 */
defined( 'ABSPATH' ) || exit;

get_header();

$uid_param   = isset( $_GET['uid'] )   ? (int) $_GET['uid']   : 0;
$token_param = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
$has_params  = ( $uid_param > 0 && $token_param !== '' );
?>

<main class="verify-email-page" style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:60px 20px;">
  <div class="verify-email-box" style="max-width:480px;width:100%;background:var(--glass-bg-mid,rgba(20,20,40,.7));backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:48px 32px;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.4);">

    <?php if ( ! $has_params ) : ?>
      <!-- 沒帶參數：顯示一般說明 -->
      <div style="font-size:56px;color:#f59e0b;margin-bottom:20px;">
        <i class="fa-solid fa-envelope"></i>
      </div>
      <h1 style="margin:0 0 14px;font-size:24px;color:#fff;">Email 驗證</h1>
      <p style="margin:0 0 8px;color:var(--text-muted,#aaa);font-size:14px;line-height:1.7;">
        此頁面用於驗證您的電子郵件。
      </p>
      <p style="margin:0 0 24px;color:var(--text-muted,#aaa);font-size:14px;line-height:1.7;">
        請至信箱點擊我們寄出的驗證連結，<br>
        或返回首頁繼續瀏覽。
      </p>
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="um-button" style="display:inline-block;padding:12px 28px;border-radius:10px;background:linear-gradient(90deg,#3b82f6,#8b5cf6);color:#fff;text-decoration:none;font-weight:600;">
        返回首頁
      </a>

    <?php else : ?>
      <!-- 有帶參數：顯示驗證中，由 JS 呼叫 AJAX -->
      <div id="verify-loading">
        <div style="font-size:56px;color:#3b82f6;margin-bottom:20px;">
          <i class="fa-solid fa-circle-notch fa-spin"></i>
        </div>
        <h1 style="margin:0 0 14px;font-size:22px;color:#fff;">驗證中…</h1>
        <p style="margin:0;color:var(--text-muted,#aaa);font-size:14px;">
          請稍候，正在確認您的驗證連結
        </p>
      </div>

      <div id="verify-success" style="display:none;">
        <div style="font-size:56px;color:#10b981;margin-bottom:20px;">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <h1 style="margin:0 0 14px;font-size:24px;color:#fff;">驗證成功 🎉</h1>
        <p id="verify-success-msg" style="margin:0 0 12px;color:var(--text-muted,#aaa);font-size:15px;line-height:1.7;">
          歡迎加入微笑動漫！
        </p>
        <p style="margin:0 0 28px;color:var(--text-muted,#aaa);font-size:13px;line-height:1.7;">
          您現在可以使用完整功能：追蹤、評分、收藏、留言。
        </p>
        <?php if ( is_user_logged_in() ) : ?>
          <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="um-button" style="display:inline-block;padding:12px 28px;border-radius:10px;background:linear-gradient(90deg,#10b981,#059669);color:#fff;text-decoration:none;font-weight:600;margin-right:8px;">
            <i class="fa-solid fa-house"></i> 返回首頁
          </a>
          <?php
          $profile_url = function_exists( 'wxacg_get_member_center_url' )
                         ? wxacg_get_member_center_url()
                         : home_url( '/' );
          ?>
          <a href="<?php echo esc_url( $profile_url ); ?>" class="um-button" style="display:inline-block;padding:12px 28px;border-radius:10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;text-decoration:none;font-weight:600;">
            <i class="fa-solid fa-user"></i> 我的頁面
          </a>
        <?php else : ?>
          <button type="button" onclick="if(window.smacgOpenLoginModal){window.smacgOpenLoginModal('login');}else{location.href='<?php echo esc_js( home_url( '/' ) ); ?>';}" class="um-button" style="padding:12px 28px;border-radius:10px;background:linear-gradient(90deg,#10b981,#059669);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:15px;">
            <i class="fa-solid fa-right-to-bracket"></i> 立即登入
          </button>
        <?php endif; ?>
      </div>

      <div id="verify-error" style="display:none;">
        <div style="font-size:56px;color:#ef4444;margin-bottom:20px;">
          <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <h1 style="margin:0 0 14px;font-size:24px;color:#fff;">驗證失敗</h1>
        <p id="verify-error-msg" style="margin:0 0 24px;color:var(--text-muted,#aaa);font-size:15px;line-height:1.7;">
          驗證連結無效或已過期
        </p>
        <div id="verify-error-resend-wrap" style="display:none;margin-bottom:20px;">
          <p style="margin:0 0 12px;color:var(--text-muted,#aaa);font-size:13px;">
            登入後可重寄新的驗證信
          </p>
        </div>
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="um-button" style="display:inline-block;padding:12px 28px;border-radius:10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;text-decoration:none;font-weight:600;">
          返回首頁
        </a>
      </div>

      <script>
      (function () {
        'use strict';

        var loadingEl = document.getElementById('verify-loading');
        var successEl = document.getElementById('verify-success');
        var errorEl   = document.getElementById('verify-error');
        var sucMsgEl  = document.getElementById('verify-success-msg');
        var errMsgEl  = document.getElementById('verify-error-msg');
        var resendWrap = document.getElementById('verify-error-resend-wrap');

        function show(el) {
          loadingEl.style.display = 'none';
          successEl.style.display = 'none';
          errorEl.style.display   = 'none';
          if (el) el.style.display = 'block';
        }

        var fd = new FormData();
        fd.append('action', 'smacg_verify_email');
        fd.append('uid',    <?php echo (int) $uid_param; ?>);
        fd.append('token',  <?php echo wp_json_encode( $token_param ); ?>);

        fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.success) {
              if (d.data && d.data.already) {
                sucMsgEl.textContent = '此信箱已通過驗證';
              } else if (d.data && d.data.msg) {
                sucMsgEl.textContent = d.data.msg;
              }
              show(successEl);
            } else {
              var msg = (d && d.data && d.data.msg) ? d.data.msg : '驗證連結無效或已過期';
              errMsgEl.textContent = msg;
              // 已登入但驗證失敗 → 顯示「可至首頁橫條重寄」提示
              <?php if ( is_user_logged_in() ) : ?>
              if (d && d.data && d.data.expired) {
                resendWrap.style.display = 'block';
              }
              <?php endif; ?>
              show(errorEl);
            }
          })
          .catch(function () {
            errMsgEl.textContent = '網路錯誤，請稍後再試';
            show(errorEl);
          });
      })();
      </script>
    <?php endif; ?>

  </div>
</main>

<?php
get_footer();
