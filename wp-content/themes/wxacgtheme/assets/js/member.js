/**
 * Member Center JS v2.2.0 (2026-05-13)
 * - v2.0.1: 收藏分頁改用 data-favorited 判斷
 * - v2.0.2: 新增頭像即時上傳（AJAX）
 * - v2.0.3: 支援 URL ?tab= / ?profiletab= / #hash 自動切換分頁；切換時同步 hash
 * - v2.1.0 (Batch B): 留言分頁載入（.mc-loadmore-comments）
 * - v2.2.0: 頭像上傳優化（A/B/C/D/E/G）
 *   - C: 客戶端 canvas 壓縮（JPEG 85%，最大 1000x1000，~400 KB）
 *   - D: XHR upload.progress 進度條（取代 fetch）
 *   - E+G: Cropper.js 預覽+裁切 Modal，輸出 400x400 圓形預覽框
 *   - 上限同步：5 MB（壓縮前）
 *   - GIF 已移除（前後端一致）
 */
(function ($) {
  'use strict';
  if (typeof smacgMember === 'undefined') return;

  const $wrap = $('.mc-wrap');
  if (!$wrap.length) return;

  const PAGE_SIZE = matchMedia('(max-width: 480px)').matches ? 12 : 20;

  /* ===== Tabs ===== */
  function switchTab(name, updateHash) {
    const $tab = $(`.mc-tab[data-tab="${name}"]`);
    if (!$tab.length) return false;
    $('.mc-tab').removeClass('active');
    $tab.addClass('active');
    $('.mc-panel').removeClass('active');
    $(`.mc-panel[data-panel="${name}"]`).addClass('active');
    if (name === 'stats') animateBars();
    if (updateHash && history.replaceState) {
      history.replaceState(null, '', '#' + name);
    }
    return true;
  }

  $wrap.on('click', '.mc-tab', function () {
    const t = $(this).data('tab');
    switchTab(t, true);
  });

  /* ===== URL 自動切換（v2.0.3） ===== */
  (function initTabFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const fromQuery = params.get('tab') || params.get('profiletab');
    const fromHash  = (window.location.hash || '').replace('#', '');
    const target    = fromQuery || fromHash;
    if (!target) return;

    setTimeout(function () {
      if (switchTab(target, false)) {
        const $nav = $('.mc-tabs');
        if ($nav.length) {
          $('html, body').animate({ scrollTop: $nav.offset().top - 80 }, 300);
        }
      }
    }, 50);
  })();

  $(window).on('hashchange', function () {
    const t = (window.location.hash || '').replace('#', '');
    if (t) switchTab(t, false);
  });

  /* ===== 圖表動畫 ===== */
  function animateBars() {
    $('.mc-bar-fill').each(function () {
      const w = this.style.width;
      this.style.width = '0';
      requestAnimationFrame(() => { this.style.width = w; });
    });
  }
  if ($('.mc-panel[data-panel="stats"].active').length) animateBars();

  /* ===== Filter ===== */
  let currentFilter = 'all';
  $wrap.on('click', '.mc-filter-btn', function () {
    currentFilter = String($(this).data('filter'));
    $('.mc-filter-btn').removeClass('active');
    $(this).addClass('active');

    $('#mc-watchlist-grid .mc-anime-card').each(function () {
      const $c = $(this);
      const status = String($c.data('status') || '');
      const fav = String($c.data('favorited')) === '1';
      let show = false;
      if (currentFilter === 'all') show = true;
      else if (currentFilter === 'favorited') show = fav;
      else show = (status === currentFilter);
      $c.toggle(show);
    });
  });

  /* ===== Search ===== */
  let searchTimer;
  $wrap.on('input', '.mc-search', function () {
    clearTimeout(searchTimer);
    const $input = $(this);
    const target = $input.data('target');
    const q = $input.val().trim().toLowerCase();
    searchTimer = setTimeout(() => {
      $(`#mc-${target}-grid`).find('.mc-anime-card').each(function () {
        const t = $(this).data('title') || '';
        $(this).toggle(t.indexOf(q) !== -1);
      });
    }, 200);
  });

  /* ===== Sort ===== */
  $wrap.on('change', '.mc-sort', function () {
    reloadList($(this).data('target'), { sort: $(this).val(), offset: 0, replace: true });
  });

  /* ===== Load more（watchlist / ratings） ===== */
  $wrap.on('click', '.mc-loadmore', function () {
    if ($(this).hasClass('mc-loadmore-comments')) return;

    const $btn = $(this);
    if ($btn.prop('disabled')) return;
    reloadList($btn.data('type'), {
      offset: parseInt($btn.data('loaded'), 10) || 0,
      append: true, $btn
    });
  });

  /* ===== AJAX ===== */
  function reloadList(type, opts) {
    const $btn = opts.$btn || $(`.mc-loadmore[data-type="${type}"]`);
    const $grid = $(`#mc-${type}-grid`);
    const $sort = $(`.mc-sort[data-target="${type}"]`);
    const $search = $(`.mc-search[data-target="${type}"]`);

    $btn.addClass('loading').prop('disabled', true);

    $.ajax({
      url: smacgMember.ajax, method: 'POST', dataType: 'json',
      data: {
        action: 'smacg_member_loadmore',
        nonce: smacgMember.nonce,
        type, offset: opts.offset || 0, limit: PAGE_SIZE,
        filter: type === 'watchlist' ? currentFilter : 'all',
        sort: opts.sort || $sort.val() || 'updated',
        search: $search.val() || '',
      }
    }).done(res => {
      if (!res.success) { alert(res.data?.msg || '載入失敗'); return; }
      if (opts.replace) $grid.html(res.data.html);
      else $grid.append(res.data.html);
      $btn.data('loaded', res.data.loaded);
      if (res.data.has_more) $btn.find('span').text(res.data.total - res.data.loaded);
      else $btn.closest('.mc-loadmore-wrap').remove();
    }).fail(() => alert('網路錯誤，請稍後再試'))
      .always(() => $btn.removeClass('loading').prop('disabled', false));
  }

  /* =========================================================
   * Batch B (v2.1.0)：留言載入更多
   * ========================================================= */
  $wrap.on('click', '.mc-loadmore-comments', function () {
    const $btn = $(this);
    if ($btn.prop('disabled')) return;

    const loaded = parseInt($btn.data('loaded'), 10) || 0;
    const total  = parseInt($btn.data('total'), 10) || 0;
    const nonce  = $btn.data('nonce');

    if (loaded >= total) {
      $btn.closest('.mc-loadmore-wrap').remove();
      return;
    }

    $btn.addClass('loading').prop('disabled', true);
    const originalLabel = $btn.html();
    $btn.text('載入中…');

    $.ajax({
      url: smacgMember.ajax,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'smacg_load_more_comments',
        nonce:  nonce,
        offset: loaded
      }
    })
    .done(function (res) {
      if (!res || !res.success) {
        alert((res && res.data && res.data.msg) || '載入失敗');
        $btn.html(originalLabel);
        return;
      }

      $('#mc-cmt-list').append(res.data.html);
      $btn.data('loaded', res.data.loaded);

      if (res.data.has_more) {
        const remain = res.data.total - res.data.loaded;
        $btn.html('載入更多（剩 <span>' + remain + '</span>）');
      } else {
        $btn.closest('.mc-loadmore-wrap').fadeOut(200, function () {
          $(this).remove();
        });
      }
    })
    .fail(function () {
      alert('網路錯誤，請稍後再試');
      $btn.html(originalLabel);
    })
    .always(function () {
      $btn.removeClass('loading').prop('disabled', false);
    });
  });

  /* =========================================================
   * v2.2.0：頭像上傳（A + B + C + D + E + G）
   * ---------------------------------------------------------
   * Flow：
   *   1. 使用者選檔
   *   2. 前端驗證（格式、大小 5 MB）
   *   3. 開 Cropper.js modal（圓形預覽）
   *   4. 確認 → canvas 輸出 400x400 → JPEG 85% 壓縮
   *   5. XHR 上傳 + progress 進度條
   *   6. 成功後同步全站頭像
   * ========================================================= */

  const AVATAR_MAX_SIZE   = 5 * 1024 * 1024;  // 5 MB 上限（壓縮前）
  const AVATAR_OUTPUT_DIM = 400;              // 最終輸出尺寸
  const AVATAR_JPEG_QUAL  = 0.85;             // 壓縮品質
  const AVATAR_ACCEPT     = ['image/jpeg', 'image/png', 'image/webp'];

  const $avatarInput  = $('#mc-avatar-input');
  const $avatarImg    = $('#mc-avatar-img');
  const $avatarMsg    = $('#mc-avatar-msg');
  const $progress     = $('#mc-avatar-progress');
  const $progressFill = $progress.find('.mc-avatar-progress-fill');
  const $progressText = $progress.find('.mc-avatar-progress-text');

  const $modal        = $('#mc-cropper-modal');
  const $modalImg     = $('#mc-cropper-image');
  const $btnConfirm   = $('#mc-cropper-confirm');
  const $btnCancel    = $('#mc-cropper-cancel');
  const $btnClose     = $('#mc-cropper-close');

  let cropperInstance = null;
  let lastObjectUrl   = null;  // 用來釋放記憶體

  /* ---------- 工具：顯示訊息 ---------- */
  function showAvatarMsg(text, type) {
    $avatarMsg
      .attr('class', 'mc-avatar-msg mc-avatar-msg--' + type)
      .text(text)
      .show();
  }
  function hideAvatarMsg(delay) {
    setTimeout(() => $avatarMsg.fadeOut(200), delay || 0);
  }

  /* ---------- 工具：進度條 ---------- */
  function showProgress() {
    $progress.removeClass('is-done is-error').prop('hidden', false);
    $progressFill.css('width', '0%');
    $progressText.text('0%');
  }
  function updateProgress(pct) {
    pct = Math.max(0, Math.min(100, Math.round(pct)));
    $progressFill.css('width', pct + '%');
    $progressText.text(pct + '%');
  }
  function finishProgress(ok) {
    $progress.addClass(ok ? 'is-done' : 'is-error');
    $progressFill.css('width', '100%');
    $progressText.text(ok ? '完成' : '失敗');
    setTimeout(() => $progress.prop('hidden', true), 1500);
  }

  /* ---------- 開啟 Cropper Modal ---------- */
  function openCropperModal(objectUrl) {
    $modalImg.attr('src', objectUrl);
    $modal.prop('hidden', false).attr('aria-hidden', 'false');
    document.body.classList.add('mc-cropper-open');

    // 確保 Cropper.js 已載入
    if (typeof Cropper === 'undefined') {
      showAvatarMsg('裁切工具載入失敗，請重新整理頁面', 'error');
      closeCropperModal();
      return;
    }

    // 銷毀舊實例（防止重複開啟記憶體洩漏）
    if (cropperInstance) {
      cropperInstance.destroy();
      cropperInstance = null;
    }

    // 等圖片載入後初始化 Cropper
    $modalImg.one('load', function () {
      cropperInstance = new Cropper($modalImg[0], {
        aspectRatio: 1,           // 強制 1:1
        viewMode:    1,           // 裁切框不能超出圖片
        dragMode:    'move',      // 拖曳整張圖
        autoCropArea: 0.9,        // 預設裁切框佔 90%
        movable:     true,
        zoomable:    true,
        scalable:    false,
        rotatable:   false,
        cropBoxResizable: true,
        cropBoxMovable:   true,
        toggleDragModeOnDblclick: false,
        background:  false,
        responsive:  true,
        checkOrientation: true,    // 自動讀 EXIF 旋轉
      });
    });
  }

  /* ---------- 關閉 Cropper Modal ---------- */
  function closeCropperModal() {
    $modal.prop('hidden', true).attr('aria-hidden', 'true');
    document.body.classList.remove('mc-cropper-open');

    if (cropperInstance) {
      cropperInstance.destroy();
      cropperInstance = null;
    }
    if (lastObjectUrl) {
      URL.revokeObjectURL(lastObjectUrl);
      lastObjectUrl = null;
    }
    $modalImg.removeAttr('src');
    $avatarInput.val('');  // 清掉 input，允許重選相同檔案
  }

  /* ---------- 主流程：選檔 ---------- */
  $avatarInput.on('change', function () {
    const file = this.files && this.files[0];
    if (!file) return;

    // 驗證 MIME
    if (!AVATAR_ACCEPT.includes(file.type)) {
      showAvatarMsg('僅支援 JPG / PNG / WebP', 'error');
      this.value = '';
      hideAvatarMsg(3000);
      return;
    }

    // 驗證大小
    if (file.size > AVATAR_MAX_SIZE) {
      showAvatarMsg('檔案過大（上限 5 MB）', 'error');
      this.value = '';
      hideAvatarMsg(3000);
      return;
    }

    // 開 Cropper modal
    if (lastObjectUrl) URL.revokeObjectURL(lastObjectUrl);
    lastObjectUrl = URL.createObjectURL(file);
    openCropperModal(lastObjectUrl);
  });

  /* ---------- Modal：取消 / 關閉 / 點背景 ---------- */
  $btnCancel.on('click', closeCropperModal);
  $btnClose.on('click', closeCropperModal);
  $modal.on('click', '.mc-cropper-backdrop', closeCropperModal);

  // ESC 鍵
  $(document).on('keydown.mcCropper', function (e) {
    if (e.key === 'Escape' && !$modal.prop('hidden')) {
      closeCropperModal();
    }
  });

  /* ---------- Modal：確認上傳 ---------- */
  $btnConfirm.on('click', function () {
    if (!cropperInstance) return;

    const $btn = $(this);
    $btn.prop('disabled', true).attr('aria-busy', 'true');
    $btnCancel.prop('disabled', true);

    // 取得裁切後 canvas，輸出 400x400
    const canvas = cropperInstance.getCroppedCanvas({
      width:           AVATAR_OUTPUT_DIM,
      height:          AVATAR_OUTPUT_DIM,
      imageSmoothingEnabled: true,
      imageSmoothingQuality: 'high',
      fillColor:       '#ffffff',  // PNG 透明背景補白（避免黑底）
    });

    if (!canvas) {
      showAvatarMsg('裁切失敗，請重試', 'error');
      $btn.prop('disabled', false).removeAttr('aria-busy');
      $btnCancel.prop('disabled', false);
      return;
    }

    // C：canvas → Blob（JPEG 85%）
    canvas.toBlob(function (blob) {
      if (!blob) {
        showAvatarMsg('壓縮失敗，請重試', 'error');
        $btn.prop('disabled', false).removeAttr('aria-busy');
        $btnCancel.prop('disabled', false);
        return;
      }

      uploadAvatarBlob(blob);

    }, 'image/jpeg', AVATAR_JPEG_QUAL);
  });

  /* ---------- XHR 上傳 + 進度條（D） ---------- */
  function uploadAvatarBlob(blob) {
    const fd = new FormData();
    fd.append('action', 'smacg_upload_avatar');
    fd.append('nonce',  smacgMember.nonce);
    // 給檔名（後端會 hash 重新命名，這裡只是占位）
    fd.append('avatar', blob, 'avatar.jpg');

    // 先關閉 modal（讓使用者看到進度條）
    closeCropperModal();

    // 顯示進度條 & 半透明預覽
    showProgress();
    showAvatarMsg('上傳中…', 'info');
    $avatarImg.css('opacity', '.5');

    // 即時把裁切後 blob 設成預覽
    const previewUrl = URL.createObjectURL(blob);
    $avatarImg.attr('src', previewUrl);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', smacgMember.ajax, true);
    xhr.withCredentials = true;

    // 進度
    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) {
        updateProgress((e.loaded / e.total) * 100);
      }
    };

    xhr.upload.onloadstart = function () {
      updateProgress(0);
    };

    xhr.upload.onload = function () {
      updateProgress(99);  // 等待伺服器處理
    };

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      URL.revokeObjectURL(previewUrl);

      let res;
      try {
        res = JSON.parse(xhr.responseText);
      } catch (e) {
        res = null;
      }

      if (xhr.status >= 200 && xhr.status < 300 && res && res.success) {
        // 成功
        finishProgress(true);
        const finalUrl = res.data.url + '?t=' + Date.now();
        $avatarImg.css('opacity', '1').attr('src', finalUrl);
        showAvatarMsg(res.data.msg || '頭像已更新', 'success');
        hideAvatarMsg(2500);

        // 同步全站頭像
        $('img.avatar, .um-user-avatar img').each(function () {
          const $img = $(this);
          const src  = $img.attr('src') || '';
          if (src.includes('gravatar.com') || src.includes('avatar')) {
            $img.attr('src', finalUrl);
          }
        });

      } else {
        // 失敗
        finishProgress(false);
        $avatarImg.css('opacity', '1');

        let msg = '上傳失敗';
        if (res && res.data && res.data.msg) {
          msg = res.data.msg;
        } else if (xhr.status === 429) {
          msg = '更換太頻繁，請稍後再試';
        } else if (xhr.status === 401) {
          msg = '請重新登入';
        } else if (xhr.status === 0) {
          msg = '網路錯誤';
        }
        showAvatarMsg(msg, 'error');
        hideAvatarMsg(4000);

        // 還原舊頭像（從 alt 重抓不可靠，乾脆刷新 src）
        // 這裡用既有 img 的 src 移除 ?t= 重抓
        setTimeout(function () {
          const oldSrc = $avatarImg.attr('src').split('?')[0];
          $avatarImg.attr('src', oldSrc + '?r=' + Date.now());
        }, 500);
      }
    };

    xhr.onerror = function () {
      URL.revokeObjectURL(previewUrl);
      finishProgress(false);
      $avatarImg.css('opacity', '1');
      showAvatarMsg('網路錯誤', 'error');
      hideAvatarMsg(4000);
    };

    xhr.send(fd);
  }

  /* ===== 設定面板：重設密碼按鈕（v2.0.3） ===== */
  $wrap.on('click', '.mc-settings-reset-pwd', function (e) {
    e.preventDefault();
    window.location.href = smacgMember.passwordResetUrl || '/password-reset/';
  });

  /* ===== 設定面板：基本資料 inline 儲存 ===== */
  $wrap.on('submit', '#mc-profile-form', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $msg  = $('#mc-profile-msg');
    const $btn  = $form.find('button[type=submit]');

    $btn.prop('disabled', true).text('儲存中…');
    $msg.hide();

    $.post(smacgMember.ajax, {
      action: 'smacg_update_profile',
      nonce: $form.find('[name=smacg_profile_nonce]').val(),
      display_name: $form.find('[name=display_name]').val(),
      description:  $form.find('[name=description]').val(),
    }, null, 'json')
    .done(res => {
      if (res.success) {
        $msg.text(res.data.msg).attr('class','mc-set-msg mc-set-msg--ok').show();
        $('.mc-hero-name').contents().filter(function(){return this.nodeType===3;}).first()
          .replaceWith(document.createTextNode($form.find('[name=display_name]').val() + ' '));
      } else {
        $msg.text((res.data && res.data.msg) || '儲存失敗').attr('class','mc-set-msg mc-set-msg--err').show();
      }
    })
    .fail(() => $msg.text('網路錯誤').attr('class','mc-set-msg mc-set-msg--err').show())
    .always(() => $btn.prop('disabled', false).text('儲存變更'));
  });

  /* =========================================================
   * P0-1: 隱私 toggle 開關
   * ========================================================= */
  $wrap.on('change', '.mc-privacy-form input[type=checkbox]', function(){
      const $box   = $(this);
      const $form  = $box.closest('.mc-privacy-form');
      const $row   = $box.closest('.mc-toggle-row');
      const $msg   = $('#mc-privacy-msg');
      const key    = $box.data('key');
      const value  = $box.is(':checked') ? 1 : 0;
      const nonce  = $form.data('nonce');

      $box.prop('disabled', true);
      $.post(smacgMember.ajax, {
          action: 'smacg_update_privacy',
          nonce:  nonce,
          key:    key,
          value:  value
      }, null, 'json')
      .done(r => {
          if (r.success) {
              $msg.text(r.data.msg).attr('class','mc-set-msg mc-set-msg--ok').show();
              setTimeout(()=>$msg.fadeOut(),1800);

              $row.addClass('is-saved');
              setTimeout(()=>$row.removeClass('is-saved'), 1200);
          } else {
              $box.prop('checked', !value);
              $msg.text(r.data.msg || '儲存失敗').attr('class','mc-set-msg mc-set-msg--err').show();
          }
      })
      .fail(()=>{
          $box.prop('checked', !value);
          $msg.text('網路錯誤').attr('class','mc-set-msg mc-set-msg--err').show();
      })
      .always(()=> $box.prop('disabled', false));
  });

  /* =========================================================
   * P0-2: 卡片快速操作（+1 集 / 完成 / 移除）
   * ========================================================= */
  const REST_BASE = (window.wpApiSettings && wpApiSettings.root)
      ? wpApiSettings.root + 'weixiaoacg/v1/user-status/'
      : '/wp-json/weixiaoacg/v1/user-status/';
  const REST_NONCE = (window.wpApiSettings && wpApiSettings.nonce) || '';

  function restCall(animeId, method, body){
      return fetch(REST_BASE + animeId, {
          method: method,
          credentials: 'same-origin',
          headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce':   REST_NONCE
          },
          body: body ? JSON.stringify(body) : undefined
      }).then(r => r.json());
  }

  function toast(text, ok){
      let $t = $('#mc-toast');
      if(!$t.length){
          $t = $('<div id="mc-toast" class="mc-toast"></div>').appendTo('body');
      }
      $t.text(text)
        .attr('class', 'mc-toast ' + (ok ? 'mc-toast--ok' : 'mc-toast--err'))
        .stop(true,true).fadeIn(120).delay(1400).fadeOut(300);
  }

  // +1 集
  $wrap.on('click', '.mc-card-btn--plus', function(){
      const $btn  = $(this);
      const $bar  = $btn.closest('.mc-card-actions');
      const id    = $bar.data('anime');
      const total = parseInt($bar.data('total'),10) || 0;
      let   cur   = parseInt($bar.data('progress'),10) || 0;
      if(total && cur >= total){
          toast('已達總集數', false); return;
      }
      $btn.prop('disabled', true);
      restCall(id, 'POST', {action:'progress', value: 1}).then(r => {
          if(r && r.success){
              cur += 1;
              $bar.data('progress', cur);
              const $card = $btn.closest('.mc-anime-card, .mc-card');
              const $fill = $card.find('.mc-progress-bar, .mc-card-progress-fill');
              const $txt  = $card.find('.mc-progress-text, .mc-card-progress-text');
              if(total){
                  const pct = Math.min(100, Math.round(cur/total*100));
                  $fill.css('width', pct + '%');
                  $txt.text(cur + ' / ' + total);
                  if(cur >= total){
                      toast('🎉 已看完所有集數！', true);
                  } else {
                      toast('進度 +1 ✓', true);
                  }
              } else {
                  $txt.text(cur + ' 集');
                  toast('進度 +1 ✓', true);
              }
          } else {
              toast((r && r.message) || '更新失敗', false);
          }
      }).catch(() => toast('網路錯誤', false))
        .finally(() => $btn.prop('disabled', false));
  });

  // 標記完成
  $wrap.on('click', '.mc-card-btn--done', function(){
      const $btn = $(this);
      const id   = $btn.closest('.mc-card-actions').data('anime');
      if(!confirm('確定標記為看完？')) return;
      $btn.prop('disabled', true);
      restCall(id, 'POST', {action:'status', value: 'completed'}).then(r => {
          if(r && r.success){
              toast('已標記完成 ✓', true);
              setTimeout(()=>location.reload(), 600);
          } else {
              toast((r && r.message) || '更新失敗', false);
              $btn.prop('disabled', false);
          }
      }).catch(()=>{ toast('網路錯誤', false); $btn.prop('disabled', false); });
  });

  // 從清單移除
  $wrap.on('click', '.mc-card-btn--remove', function(){
      const $btn  = $(this);
      const $card = $btn.closest('.mc-anime-card, .mc-card');
      const id    = $btn.closest('.mc-card-actions').data('anime');
      if(!confirm('確定要從清單中移除？此動作不可復原。')) return;
      $btn.prop('disabled', true);
      restCall(id, 'DELETE').then(r => {
          if(r && r.success){
              $card.fadeOut(200, function(){ $(this).remove(); });
              toast('已移除 ✓', true);
          } else {
              toast((r && r.message) || '移除失敗', false);
              $btn.prop('disabled', false);
          }
      }).catch(()=>{ toast('網路錯誤', false); $btn.prop('disabled', false); });
  });

  /* =========================================================
   * P1-2: Continue Watching 左右箭頭捲動
   * ========================================================= */
  $wrap.on('click', '.mc-continue-arrow', function () {
    const $scroll = $('#mc-continue-scroll');
    if (!$scroll.length) return;
    const dir = $(this).data('dir');
    const cardWidth = $scroll.find('.mc-continue-card').outerWidth(true) || 200;
    const distance  = cardWidth * 2;
    $scroll[0].scrollBy({
      left: dir === 'next' ? distance : -distance,
      behavior: 'smooth'
    });
  });

  // 進度滿時自動移除 Continue Watching 卡片
  $wrap.on('click', '.mc-continue-card .mc-card-btn--plus', function () {
    const $card    = $(this).closest('.mc-continue-card');
    const $actions = $(this).closest('.mc-card-actions');
    const total    = parseInt($actions.data('total'), 10) || 0;
    setTimeout(function () {
      const cur = parseInt($actions.data('progress'), 10) || 0;
      if (total > 0 && cur >= total) {
        $card.fadeOut(400, function () {
          $(this).remove();
          if (!$('#mc-continue-scroll .mc-continue-card').length) {
            $('#mc-continue-section').fadeOut(300);
          }
        });
      }
    }, 800);
  });

})(jQuery);
