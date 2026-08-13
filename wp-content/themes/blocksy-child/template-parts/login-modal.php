<?php
/**
 * 登入 / 註冊 / 註冊成功 Modal
 *
 * @package weixiaoacg
 * @version 1.1.0 (2026-06-01) 巴哈式即時驗證：每欄狀態列 + 確認密碼欄
 *
 * 依賴：
 *   - SMACG_REGISTER_USERNAME_MIN / MAX / PW_MIN_LEN 常數
 *   - assets/js/header.js 提供互動
 *   - assets/css/header.css 提供 .lm-* 樣式
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) return;

$brand_logo_url = isset( $brand_logo_url )
    ? $brand_logo_url
    : 'https://weixiaoacg.com/wp-content/uploads/2026/06/DHBdKsLa.webp';
?>
<div id="login-modal" class="lm-overlay" role="dialog" aria-modal="true" aria-label="登入">
  <div class="lm-box">

    <button class="lm-close" id="lm-close" aria-label="關閉"><i class="fa-solid fa-xmark"></i></button>

    <div class="lm-logo">
      <span class="logo-icon-box" aria-hidden="true">
        <img src="<?php echo esc_url( $brand_logo_url ); ?>" alt="weixiaoacg" class="brand-logo-img" />
      </span>
      <span class="logo-text">微笑動漫<span class="logo-plus"></span></span>
    </div>

    <p class="lm-subtitle">登入以解鎖完整功能</p>

    <div class="lm-tabs">
      <button class="lm-tab active" data-tab="login">登入</button>
      <button class="lm-tab" data-tab="register">註冊</button>
    </div>

    <!-- 登入表單 -->
    <div class="lm-panel" id="lm-panel-login">
      <form id="lm-login-form" class="lm-custom-form">
        <div class="lm-field">
          <label for="lm-username">使用者名稱或電子郵件</label>
          <input type="text" id="lm-username" name="log" autocomplete="username" required />
        </div>
        <div class="lm-field">
          <label for="lm-password">密碼</label>
          <div class="lm-pw-wrap">
            <input type="password" id="lm-password" name="pwd" autocomplete="current-password" required />
            <button type="button" class="lm-pw-toggle" data-target="lm-password" aria-label="顯示密碼" tabindex="-1"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
          </div>
        </div>
        <div class="lm-field lm-remember">
          <label><input type="checkbox" name="rememberme" value="1" /> 保持登入狀態</label>
        </div>
        <div id="lm-login-error" class="lm-error" style="display:none"></div>
        <button type="submit" class="um-button">登入</button>
        <div class="lm-links">
          <button type="button" class="lm-link-btn lm-switch-register">還沒有帳號？註冊</button>
          <a href="<?php echo esc_url( home_url( '/password-reset/' ) ); ?>">忘記密碼？</a>
        </div>
      </form>

      <?php if ( class_exists( 'NextendSocialLogin', false ) ) : ?>
      <div class="lm-social-divider"><span>或使用以下方式登入</span></div>
      <div class="lm-social-buttons"><?php echo NextendSocialLogin::renderButtonsWithContainer(); ?></div>
      <?php endif; ?>
    </div>

    <!-- 註冊表單 -->
    <div class="lm-panel" id="lm-panel-register" hidden>
      <form id="lm-register-form" class="lm-custom-form" novalidate>

        <div class="lm-field">
          <label for="lm-reg-username">使用者名稱</label>
          <input type="text" id="lm-reg-username" name="user_login" autocomplete="username" required
                 minlength="<?php echo (int) SMACG_REGISTER_USERNAME_MIN; ?>"
                 maxlength="<?php echo (int) SMACG_REGISTER_USERNAME_MAX; ?>" />
          <small class="lm-field-hint"><?php echo (int) SMACG_REGISTER_USERNAME_MIN; ?>–<?php echo (int) SMACG_REGISTER_USERNAME_MAX; ?> 字元，僅限英文、數字、底線</small>
          <small class="lm-field-status" data-for="lm-reg-username" aria-live="polite"></small>
        </div>

        <div class="lm-field">
          <label for="lm-reg-email">電子郵件</label>
          <input type="email" id="lm-reg-email" name="user_email" autocomplete="email" required />
          <small class="lm-field-status" data-for="lm-reg-email" aria-live="polite"></small>
        </div>

        <div class="lm-field">
          <label for="lm-reg-password">密碼</label>
          <div class="lm-pw-wrap">
            <input type="password" id="lm-reg-password" name="user_password" autocomplete="new-password" required
                   minlength="<?php echo (int) SMACG_REGISTER_PW_MIN_LEN; ?>" />
            <button type="button" class="lm-pw-toggle" data-target="lm-reg-password" aria-label="顯示密碼" tabindex="-1"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
          </div>
          <small class="lm-field-hint">至少 <?php echo (int) SMACG_REGISTER_PW_MIN_LEN; ?> 字元，必須包含英文字母和數字</small>
          <small class="lm-field-status" data-for="lm-reg-password" aria-live="polite"></small>
        </div>

        <div class="lm-field">
          <label for="lm-reg-password2">確認密碼</label>
          <div class="lm-pw-wrap">
            <input type="password" id="lm-reg-password2" name="user_password2" autocomplete="new-password" required />
            <button type="button" class="lm-pw-toggle" data-target="lm-reg-password2" aria-label="顯示密碼" tabindex="-1"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
          </div>
          <small class="lm-field-status" data-for="lm-reg-password2" aria-live="polite"></small>
        </div>

        <!-- Honeypot -->
        <div class="lm-honeypot" aria-hidden="true">
          <label>Website（請勿填寫）<input type="text" name="reg_website" tabindex="-1" autocomplete="off" value="" /></label>
        </div>

        <div id="lm-register-error" class="lm-error" style="display:none"></div>
        <div id="lm-register-success" class="lm-success" style="display:none"></div>
        <button type="submit" class="um-button" id="lm-register-submit" disabled>註冊</button>
        <p class="lm-switch-login-wrap">已有帳號？<button type="button" class="lm-switch-login lm-link-btn">登入</button></p>
      </form>

      <p class="lm-terms-hint">註冊即代表你同意 <a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>" target="_blank">使用條款</a> 及 <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>" target="_blank">隱私政策</a></p>

      <?php if ( class_exists( 'NextendSocialLogin', false ) ) : ?>
      <div class="lm-social-divider"><span>或使用以下方式註冊</span></div>
      <div class="lm-social-buttons"><?php echo NextendSocialLogin::renderButtonsWithContainer(); ?></div>
      <?php endif; ?>
    </div>

    <!-- 註冊成功面板 -->
    <div class="lm-panel" id="lm-panel-verify-sent" hidden>
      <div class="lm-verify-sent-inner">
        <div class="lm-verify-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h3 class="lm-verify-title">註冊成功！</h3>
        <p class="lm-verify-text">我們已將驗證信寄到：<br><strong id="lm-verify-email-shown"></strong></p>
        <p class="lm-verify-sub">請至信箱點擊驗證連結完成註冊。<br>連結 24 小時內有效。</p>
        <div class="lm-verify-tip"><i class="fa-solid fa-circle-info"></i> 沒收到信？請檢查垃圾郵件夾，或稍候再試。</div>
        <button type="button" class="um-button lm-switch-login lm-verify-back">返回登入</button>
      </div>
    </div>

  </div>
</div>
