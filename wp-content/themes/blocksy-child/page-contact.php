<?php
/**
 * Template Name: 聯絡我們
 */

/*
 * SEO 標題。
 *
 * 後台頁面標題為英文 slug「contact」，直接輸出會是「contact - 微笑動漫」。
 * 沿用 page-bangumi*.php 既有的 pre_get_document_title 作法覆寫，
 * 不動後台頁面標題（避免連帶影響選單與頁面上的 H1）。
 */
add_filter( 'pre_get_document_title', function () {
	return '聯絡微笑動漫｜問題回報、版權申訴與合作洽談';
}, 99 );

get_header(); ?>

<main class="static-page">

  <!-- Hero -->
  <section class="static-hero static-hero--compact">
    <div class="static-hero-inner">
      <h1 class="static-hero-title">聯絡我們</h1>
      <p class="static-hero-desc">有任何問題、建議或版權申訴，歡迎隨時與我們聯繫。</p>
    </div>
  </section>

  <div class="static-content container">

    <!-- 聯絡資訊卡片 -->
    <section class="static-section">
      <div class="static-grid-3">

        <div class="glass-light static-card static-card--center">
          <div class="static-item-icon">✉️</div>
          <h4 class="static-card-title">電子郵件</h4>
          <p>一般詢問、合作洽談</p>
          <a href="mailto:weixiaoacg.com@gmail.com" class="static-link" style="justify-content:center;">
            weixiaoacg.com@gmail.com
          </a>
        </div>

        <div class="glass-light static-card static-card--center">
          <div class="static-item-icon">⚖️</div>
          <h4 class="static-card-title">版權申訴</h4>
          <p>版權侵權通報，72 小時內回覆</p>
          <a href="mailto:weixiaoacg.com@gmail.com" class="static-link" style="justify-content:center;">
            weixiaoacg.com@gmail.com
          </a>
        </div>

        <div class="glass-light static-card static-card--center">
          <div class="static-item-icon">🐛</div>
          <h4 class="static-card-title">問題回報</h4>
          <p>網站錯誤、資料異常回報</p>
          <a href="mailto:weixiaoacg.com@gmail.com" class="static-link" style="justify-content:center;">
            weixiaoacg.com@gmail.com
          </a>
        </div>

      </div>
    </section>

    <!-- 聯絡表單 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">✉</span>
        <h2 class="static-section-title">傳送訊息</h2>
      </div>

      <div class="glass-light static-card">
        <?php
        $form_sent     = false;
        $form_error    = '';
        $field_name    = '';
        $field_email   = '';
        $field_msg     = '';
        $field_correct = '';

        $field_type = sanitize_text_field( $_POST['contact_type'] ?? $_GET['type'] ?? '' );
        $field_ref  = esc_url_raw( $_GET['ref'] ?? '' );

        if ( isset( $_POST['contact_submit'] ) ) {

            // ── Honeypot ──
            if ( ! empty( $_POST['contact_website'] ) ) {
                $form_error = '安全驗證失敗，請重新整理頁面後再試。';
            }

            // ── 頻率限制 ──
            if ( empty( $form_error ) ) {
                $ip_key   = 'contact_limit_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
                $ip_count = (int) get_transient( $ip_key );
                if ( $ip_count >= 7 ) {
                    $form_error = '您的送出次數過於頻繁，請一小時後再試。';
                } else {
                    set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );
                }
            }

            // ── Nonce ──
            if ( empty( $form_error ) ) {
                if ( ! isset( $_POST['contact_nonce'] ) || ! wp_verify_nonce( $_POST['contact_nonce'], 'weixiaoacg_contact' ) ) {
                    $form_error = '安全驗證失敗，請重新整理頁面後再試。';
                }
            }

            // ── 欄位驗證 ──
            if ( empty( $form_error ) ) {
                $field_name    = sanitize_text_field(     $_POST['contact_name']    ?? '' );
                $field_email   = sanitize_email(          $_POST['contact_email']   ?? '' );
                $field_type    = sanitize_text_field(     $_POST['contact_type']    ?? '' );
                $field_ref     = esc_url_raw(             $_POST['contact_ref']     ?? '' );
                $field_correct = sanitize_text_field(     $_POST['contact_correct'] ?? '' );
                $field_msg     = sanitize_textarea_field( $_POST['contact_message'] ?? '' );

                if ( empty( $field_name ) ) {
                    $form_error = '請填寫您的名稱。';
                } elseif ( ! is_email( $field_email ) ) {
                    $form_error = '請輸入有效的電子郵件地址。';
                } elseif ( strtolower( $field_email ) === strtolower( 'weixiaoacg.com@gmail.com' ) ) {
                    $form_error = '請輸入您自己的電子郵件地址。';
                } elseif ( empty( $field_msg ) || mb_strlen( $field_msg ) < 10 ) {
                    $form_error = '訊息內容至少需要 10 個字元。';
                }
            }

            // ── 圖片上傳驗證（最多 5 張，name="contact_image[]"） ──
            $attachment_paths = [];
            $max_images       = 5;

            if ( empty( $form_error ) && ! empty( $_FILES['contact_image']['name'] ) && is_array( $_FILES['contact_image']['name'] ) ) {
                $names  = $_FILES['contact_image']['name'];
                $count  = count( array_filter( $names, static function ( $n ) { return $n !== ''; } ) );

                if ( $count > $max_images ) {
                    $form_error = "最多只能上傳 {$max_images} 張圖片。";
                } else {
                    $allowed  = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
                    $max_size = 5 * 1024 * 1024; // 5MB／張

                    $upload_dir = wp_upload_dir();
                    $tmp_dir    = $upload_dir['basedir'] . '/contact-tmp/';

                    if ( ! file_exists( $tmp_dir ) ) {
                        wp_mkdir_p( $tmp_dir );
                        // 防止直接瀏覽
                        file_put_contents( $tmp_dir . '.htaccess', 'deny from all' );
                    }

                    foreach ( $names as $i => $name ) {
                        if ( $name === '' ) {
                            continue; // 該欄位沒選檔案
                        }

                        $file_error = $_FILES['contact_image']['error'][ $i ];
                        $file_type  = $_FILES['contact_image']['type'][ $i ];
                        $file_size  = $_FILES['contact_image']['size'][ $i ];
                        $file_tmp   = $_FILES['contact_image']['tmp_name'][ $i ];

                        if ( $file_error !== UPLOAD_ERR_OK ) {
                            $form_error = '圖片上傳失敗，請重試。';
                            break;
                        }
                        if ( ! in_array( $file_type, $allowed, true ) ) {
                            $form_error = '僅支援 JPG、PNG、GIF、WebP 格式的圖片。';
                            break;
                        }
                        if ( $file_size > $max_size ) {
                            $form_error = '每張圖片大小不能超過 5MB。';
                            break;
                        }

                        $ext           = pathinfo( $name, PATHINFO_EXTENSION );
                        $safe_filename = 'contact_' . time() . '_' . wp_generate_password( 8, false ) . '.' . $ext;
                        $dest_path     = $tmp_dir . $safe_filename;

                        if ( ! move_uploaded_file( $file_tmp, $dest_path ) ) {
                            $form_error = '圖片儲存失敗，請重試。';
                            break;
                        }

                        $attachment_paths[] = $dest_path;
                    }

                    // 驗證中途失敗：把已經搬過去的檔案清掉，不留孤兒檔
                    if ( $form_error ) {
                        foreach ( $attachment_paths as $p ) {
                            if ( file_exists( $p ) ) {
                                unlink( $p );
                            }
                        }
                        $attachment_paths = [];
                    }
                }
            }

            // ── 發送郵件 ──
            if ( empty( $form_error ) ) {
                $type_labels = [
                    'general'   => '一般詢問',
                    'bug'       => '問題回報／資料糾錯',
                    'copyright' => '版權申訴',
                    'suggest'   => '功能建議',
                    'cooperate' => '合作洽談',
                    'other'     => '其他',
                ];
                $type_label = $type_labels[ $field_type ] ?? '未分類';

                $subject = '[微笑動漫] ' . $type_label . '：來自 ' . $field_name;
                $body    = "【聯絡類型】{$type_label}\n"
                         . "【姓名】{$field_name}\n"
                         . "【Email】{$field_email}\n";

                if ( ! empty( $field_ref ) ) {
                    $body .= "【來源頁面】{$field_ref}\n";
                }
                if ( ! empty( $field_correct ) ) {
                    $body .= "【正確資料應為】{$field_correct}\n";
                }
                if ( ! empty( $attachment_paths ) ) {
                    $body .= '【附件】已附上 ' . count( $attachment_paths ) . " 張截圖\n";
                }

                $body .= "\n【訊息內容】\n{$field_msg}\n\n"
                       . "---\n送出時間：" . current_time( 'Y-m-d H:i:s' ) . "\n"
                       . "IP：" . sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );

                $headers = [
                    'Content-Type: text/plain; charset=UTF-8',
                    'Reply-To: ' . $field_name . ' <' . $field_email . '>',
                ];

                $sent = wp_mail( 'weixiaoacg.com@gmail.com', $subject, $body, $headers, $attachment_paths );

                // 暫存圖片用完立刻刪除
                foreach ( $attachment_paths as $p ) {
                    if ( file_exists( $p ) ) {
                        unlink( $p );
                    }
                }

                if ( $sent ) {

                    // 自動確認信給用戶
                    $auto_subject = '【微笑動漫】已收到您的來信';
                    $auto_body    = "您好，{$field_name}！\n\n"
                                  . "感謝您聯絡微笑動漫。\n\n"
                                  . "我們已收到您的來信，目前正在處理中，\n"
                                  . "將盡快回覆您，請耐心等候。\n\n"
                                  . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                                  . "【您的來信摘要】\n"
                                  . "聯絡類型：{$type_label}\n"
                                  . "送出時間：" . current_time( 'Y-m-d H:i:s' ) . "\n"
                                  . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
                                  . "預計回覆時間：1–3 個工作天\n"
                                  . "版權申訴類來信：72 小時內優先處理\n\n"
                                  . "※ 此為系統自動發送，請勿直接回覆此信。\n"
                                  . "※ 如需補充說明，請重新至聯絡頁面送出：\n"
                                  . home_url( '/contact/' ) . "\n\n"
                                  . "— 微笑動漫 團隊\n"
                                  . home_url() . "\n";

                    $auto_headers = [
                        'Content-Type: text/plain; charset=UTF-8',
                        'From: 微笑動漫 <weixiaoacg.com@gmail.com>',
                    ];

                    wp_mail( $field_email, $auto_subject, $auto_body, $auto_headers );

                    $form_sent = true;

                } else {
                    $form_error = '郵件發送失敗，請直接寄信至 weixiaoacg.com@gmail.com。';
                }
            }
        }
        ?>

        <?php if ( $form_sent ) : ?>
        <div class="contact-success">
          <div class="contact-success-icon">✅</div>
          <h3>訊息已送出！</h3>
          <p>感謝您的來信，我們通常會在 <strong>1–3 個工作天</strong>內回覆您。<br>
          一封確認信已寄送至 <strong><?php echo esc_html( $field_email ); ?></strong>，請注意查收。</p>
          <a href="<?php echo esc_url( get_permalink() ); ?>" class="btn btn-primary" style="margin-top:16px;">
            <i class="fa-solid fa-arrow-left"></i> 返回聯絡頁面
          </a>
        </div>

        <?php else : ?>

        <?php if ( $form_error ) : ?>
        <div class="contact-error">
          <i class="fa-solid fa-circle-exclamation"></i>
          <?php echo esc_html( $form_error ); ?>
        </div>
        <?php endif; ?>

        <?php if ( $field_type === 'bug' && $field_ref ) : ?>
        <div class="static-notice" style="margin-bottom:20px;padding:16px 20px;font-size:1.15rem;line-height:1.7;border-radius:10px;background:rgba(124,92,255,0.15);border:1px solid rgba(124,92,255,0.45);color:#fff;">
          <i class="fa-solid fa-circle-info" style="font-size:1.25rem;color:#b9a6ff;margin-right:8px;"></i>
          <strong style="font-size:1.15rem;">正在回報頁面：</strong>
          <a href="<?php echo esc_url( $field_ref ); ?>" target="_blank" class="static-link" style="margin:6px 0 0 0;display:block;word-break:break-all;font-size:1.05rem;font-weight:600;color:#cfc3ff;text-decoration:underline;">
            <?php echo esc_url( $field_ref ); ?>
          </a>
        </div>
        <?php endif; ?>

        <form class="contact-form" method="post" action="" enctype="multipart/form-data">
          <?php wp_nonce_field( 'weixiaoacg_contact', 'contact_nonce' ); ?>
          <input type="hidden" name="contact_submit" value="1">
          <input type="hidden" name="contact_ref" value="<?php echo esc_attr( $field_ref ); ?>">
          <input type="text" name="contact_website" style="display:none !important;" tabindex="-1" autocomplete="off" aria-hidden="true">

          <div class="contact-row">

            <!-- 名稱 -->
            <div class="contact-field">
              <label class="contact-label" for="contact_name">
                <i class="fa-solid fa-user"></i> 您的名稱 <span class="contact-required">*</span>
              </label>
              <input
                type="text"
                id="contact_name"
                name="contact_name"
                class="contact-input"
                placeholder="請輸入您的名稱或暱稱"
                value="<?php echo esc_attr( $field_name ); ?>"
                required
              >
            </div>

            <!-- Email -->
            <div class="contact-field">
              <label class="contact-label" for="contact_email">
                <i class="fa-solid fa-envelope"></i> 電子郵件 <span class="contact-required">*</span>
              </label>
              <input
                type="email"
                id="contact_email"
                name="contact_email"
                class="contact-input"
                placeholder="your@email.com"
                value="<?php echo esc_attr( $field_email ); ?>"
                required
              >
              <p class="contact-field-hint">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>請填寫正確的電子郵件，回覆將寄送至此信箱。</span>
              </p>
            </div>

          </div>

          <!-- 詢問類型 -->
          <div class="contact-field">
            <label class="contact-label" for="contact_type">
              <i class="fa-solid fa-tag"></i> 詢問類型
            </label>
            <select id="contact_type" name="contact_type" class="contact-input contact-select">
              <option value="general"   <?php selected( $field_type, 'general' );   ?>>一般詢問</option>
              <option value="bug"       <?php selected( $field_type, 'bug' );       ?>>問題回報／資料糾錯</option>
              <option value="copyright" <?php selected( $field_type, 'copyright' ); ?>>版權申訴</option>
              <option value="suggest"   <?php selected( $field_type, 'suggest' );   ?>>功能建議</option>
              <option value="cooperate" <?php selected( $field_type, 'cooperate' ); ?>>合作洽談</option>
              <option value="other"     <?php selected( $field_type, 'other' );     ?>>其他</option>
            </select>
          </div>

          <!-- 糾錯欄位 -->
          <div class="contact-field" id="contact-correct-wrap" style="<?php echo $field_type === 'bug' ? '' : 'display:none;'; ?>">
            <label class="contact-label" for="contact_correct">
              <i class="fa-solid fa-circle-check"></i> 正確資料應為
            </label>
            <input
              type="text"
              id="contact_correct"
              name="contact_correct"
              class="contact-input"
              placeholder="請填寫正確的資料內容（如：正確的中文名稱、集數等）"
              value="<?php echo esc_attr( $field_correct ); ?>"
            >
          </div>

          <!-- 訊息內容 -->
          <div class="contact-field">
            <label class="contact-label" for="contact_message">
              <i class="fa-solid fa-message"></i> 訊息內容 <span class="contact-required">*</span>
            </label>
            <textarea
              id="contact_message"
              name="contact_message"
              class="contact-input contact-textarea"
              placeholder="請詳細描述您的問題或建議（至少 10 個字）..."
              rows="6"
              required
            ><?php echo esc_textarea( $field_msg ); ?></textarea>
          </div>

          <!-- 圖片上傳（最多 5 張） -->
          <div class="contact-field">
            <label class="contact-label" for="contact_image">
              <i class="fa-solid fa-image"></i> 附上截圖（選填，最多 5 張）
            </label>
            <div class="contact-upload-wrap" id="contact-upload-wrap">
              <input
                type="file"
                id="contact_image"
                name="contact_image[]"
                class="contact-upload-input"
                accept="image/jpeg,image/png,image/gif,image/webp"
                multiple
              >
              <label for="contact_image" class="contact-upload-label">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span id="contact-upload-text">點擊或拖曳圖片至此上傳</span>
                <small>支援 JPG、PNG、GIF、WebP，單張最大 5MB，最多 5 張</small>
              </label>
              <div class="contact-upload-preview contact-upload-preview--grid" id="contact-upload-preview" hidden></div>
            </div>
          </div>

          <!-- 送出按鈕 -->
          <div class="contact-submit-wrap">
            <p class="contact-hint">
              <i class="fa-solid fa-lock"></i>
              您的資料受到保護，我們不會將您的資訊分享給第三方。
            </p>
            <button type="submit" class="btn btn-primary contact-submit-btn">
              <i class="fa-solid fa-paper-plane"></i> 送出訊息
            </button>
          </div>

        </form>
        <?php endif; ?>
      </div>
    </section>

    <!-- 常見問題 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">FAQ</span>
        <h2 class="static-section-title">常見問題</h2>
      </div>
      <div class="contact-faq">
        <?php
        $faqs = [
          [
            'q' => '通常多久會收到回覆？',
            'a' => '我們通常會在 1–3 個工作天內回覆。版權申訴相關事項會優先處理，保證 72 小時內回覆。',
          ],
          [
            'q' => '我發現網站有資料錯誤，該怎麼回報？',
            'a' => '請選擇詢問類型「問題回報／資料糾錯」，並在訊息中說明錯誤的動畫名稱及具體問題，我們會盡快核實並修正。你也可以直接在動漫頁面點擊「✏ 糾錯回報」按鈕，系統會自動帶入該頁面連結。',
          ],
          [
            'q' => '我想申請合作或投稿，該如何聯絡？',
            'a' => '請選擇詢問類型「合作洽談」，並在訊息中詳述您的合作提案，我們會評估後回覆您。',
          ],
          [
            'q' => '我認為本站使用了我的版權內容，該怎麼辦？',
            'a' => '請選擇詢問類型「版權申訴」，並提供具體的版權資料及侵權內容連結，我們保證 72 小時內處理。',
          ],
          [
            'q' => '我忘記密碼或帳號有問題怎麼辦？',
            'a' => '帳號相關問題請至登入頁面點選「忘記密碼」進行重設，若仍有問題再透過本頁聯絡我們。',
          ],
        ];
        foreach ( $faqs as $i => $faq ) : ?>
        <div class="contact-faq-item glass-light">
          <button class="contact-faq-q" aria-expanded="false" data-faq="<?php echo $i; ?>">
            <span><?php echo esc_html( $faq['q'] ); ?></span>
            <i class="fa-solid fa-chevron-down contact-faq-arrow"></i>
          </button>
          <div class="contact-faq-a" id="faq-<?php echo $i; ?>" hidden>
            <p><?php echo esc_html( $faq['a'] ); ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 底部 CTA -->
    <section class="static-cta glass-mid">
      <div class="static-cta-icon">🙏</div>
      <h3 class="static-cta-title">感謝你支持微笑動漫</h3>
      <p class="static-cta-desc">
        每一則回饋都讓我們變得更好。<br>
        我們在這裡，慢慢長大。<br><br>
        如果你也想讓這裡走得更久，歡迎請我們喝杯咖啡 ❤️
      </p>
      <div class="static-cta-actions">
        <a href="<?php echo esc_url( home_url('/') ); ?>" class="btn btn-primary">
          <i class="fa-solid fa-house"></i> 回到首頁
        </a>
        <a href="<?php echo esc_url( home_url('/sponsor/') ); ?>" class="btn btn-secondary">
          <i class="fa-solid fa-mug-hot"></i> 贊助我們
        </a>
        <a href="<?php echo esc_url( home_url('/join/') ); ?>" class="btn btn-secondary">
          <i class="fa-solid fa-user-plus"></i> 加入我們
        </a>
      </div>
    </section>

  </div>
</main>

<style>
/* ── 聯絡表單輸入字加亮（局部覆蓋，只影響本頁表單） ── */
.contact-form .contact-input,
.contact-form .contact-textarea,
.contact-form .contact-select {
  color: #f5f5f5 !important;
  font-size: 1.05rem !important;
}
.contact-form .contact-input::placeholder,
.contact-form .contact-textarea::placeholder {
  color: #b8b8c8 !important;
  opacity: 1 !important;
}
.contact-form .contact-select option {
  color: #1a1a1a !important;
  background: #ffffff !important;
}
</style>

<script>
/* ── FAQ 展開收合 ── */
document.querySelectorAll('.contact-faq-q').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var idx    = this.dataset.faq;
    var answer = document.getElementById('faq-' + idx);
    var isOpen = this.getAttribute('aria-expanded') === 'true';
    document.querySelectorAll('.contact-faq-q').forEach(function(b) {
      b.setAttribute('aria-expanded', 'false');
      b.closest('.contact-faq-item').classList.remove('faq-open');
    });
    document.querySelectorAll('.contact-faq-a').forEach(function(a) { a.hidden = true; });
    if (!isOpen) {
      this.setAttribute('aria-expanded', 'true');
      this.closest('.contact-faq-item').classList.add('faq-open');
      answer.hidden = false;
    }
  });
});

/* ── 糾錯欄位顯示控制 ── */
var typeSelect  = document.getElementById('contact_type');
var correctWrap = document.getElementById('contact-correct-wrap');
if (typeSelect && correctWrap) {
  typeSelect.addEventListener('change', function() {
    correctWrap.style.display = this.value === 'bug' ? '' : 'none';
  });
}

/* ── 圖片上傳預覽（最多 5 張） ── */
var fileInput   = document.getElementById('contact_image');
var uploadWrap  = document.getElementById('contact-upload-wrap');
var previewWrap = document.getElementById('contact-upload-preview');
var uploadText  = document.getElementById('contact-upload-text');
var MAX_CONTACT_IMAGES = 5;

function contactSetFiles(fileList) {
  // 用 DataTransfer 重建 FileList，超過上限的部分直接捨棄
  var dt    = new DataTransfer();
  var total = 0;
  Array.prototype.forEach.call(fileList, function (f) {
    if (total < MAX_CONTACT_IMAGES) {
      dt.items.add(f);
      total++;
    }
  });
  if (fileList.length > MAX_CONTACT_IMAGES) {
    alert('最多只能上傳 ' + MAX_CONTACT_IMAGES + ' 張圖片，已自動取前 ' + MAX_CONTACT_IMAGES + ' 張。');
  }
  fileInput.files = dt.files;
  contactRenderPreviews();
}

function contactRemoveFileAt(idx) {
  var dt = new DataTransfer();
  Array.prototype.forEach.call(fileInput.files, function (f, i) {
    if (i !== idx) dt.items.add(f);
  });
  fileInput.files = dt.files;
  contactRenderPreviews();
}

function contactRenderPreviews() {
  previewWrap.innerHTML = '';
  var files = fileInput.files;

  if (!files || !files.length) {
    previewWrap.hidden = true;
    uploadWrap.classList.remove('has-preview');
    uploadText.textContent = '點擊或拖曳圖片至此上傳';
    return;
  }

  previewWrap.hidden = false;
  uploadWrap.classList.add('has-preview');
  uploadText.textContent = files.length + ' 張圖片已選擇（點縮圖右上角可移除）';

  Array.prototype.forEach.call(files, function (file, idx) {
    var reader = new FileReader();
    reader.onload = function (e) {
      var item = document.createElement('div');
      item.className = 'contact-upload-preview-item';
      item.innerHTML =
        '<img src="' + e.target.result + '" alt="預覽 ' + (idx + 1) + '">' +
        '<button type="button" class="contact-upload-remove" data-idx="' + idx + '" aria-label="移除這張圖片">' +
        '  <i class="fa-solid fa-xmark"></i>' +
        '</button>';
      previewWrap.appendChild(item);
      item.querySelector('.contact-upload-remove').addEventListener('click', function () {
        contactRemoveFileAt(idx);
      });
    };
    reader.readAsDataURL(file);
  });
}

if (fileInput) {
  fileInput.addEventListener('change', function () {
    contactSetFiles(this.files);
  });

  // 拖曳上傳
  var uploadLabel = document.querySelector('.contact-upload-label');
  if (uploadLabel) {
    uploadLabel.addEventListener('dragover',  function(e) { e.preventDefault(); this.classList.add('drag-over'); });
    uploadLabel.addEventListener('dragleave', function()  { this.classList.remove('drag-over'); });
    uploadLabel.addEventListener('drop', function(e) {
      e.preventDefault();
      this.classList.remove('drag-over');
      if (e.dataTransfer.files.length) {
        contactSetFiles(e.dataTransfer.files);
      }
    });
  }
}
</script>

<?php get_footer(); ?>
