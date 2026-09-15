/**
 * 文章內 YouTube 嵌入改成「點擊才載入」（facade）。
 *
 * ★ 為什麼要有這支
 *
 *   1. 縮圖被裁掉
 *      YouTube 播放器會自己挑縮圖，而它挑的 hqdefault / sddefault 是 4:3
 *      （480×360 / 640×480），填進我們 16:9 的容器時上下會被裁掉。
 *      2026-09-15 使用者回報《藥師少女的獨語 第三季》的 PV 底部
 *      「Season3 Main Trailer」被切一半——那行字在完整的 16:9 縮圖
 *      （maxresdefault，1280×720）裡是完整的，就貼在下緣。
 *      這是 iframe 內部的行為，從外面改 CSS 改不到；自己畫縮圖才有解。
 *
 *   2. 一頁十幾個播放器
 *      2026 秋番懶人包一篇就有 12 個嵌入。原本每個 iframe 都會去載
 *      YouTube 的播放器，而多數讀者只會點一兩支。改成點了才載，
 *      捲過整篇的成本從 12 個播放器降成 12 張圖。
 *
 * ★ 做法與取捨
 *
 *   純前端漸進增強，不動文章內容：抓 .wxa-video 裡的 iframe，記下 src、
 *   拿掉它（沒有 src 就不會載入），改放一張 maxresdefault 縮圖與播放鈕；
 *   點擊時把 src 放回去並加 autoplay=1。
 *
 *   JS 沒跑或認不出影片 ID 時完全不動作，維持原本的 iframe，不會變成空白。
 *
 *   iframe 本來就有 loading="lazy"，所以捲到才載入的行為原本就有；
 *   這支真正省下的是「捲過去之後」的載入，以及修好縮圖比例。
 */
(function () {
	'use strict';

	var THUMB = 'https://i.ytimg.com/vi/';

	document.addEventListener('DOMContentLoaded', function () {
		var wraps = document.querySelectorAll('.wxa-video');
		if (!wraps.length) {
			return;
		}

		Array.prototype.forEach.call(wraps, function (wrap) {
			var iframe = wrap.querySelector('iframe');
			if (!iframe) {
				return;
			}

			var src = iframe.getAttribute('src') || '';
			var match = src.match(/\/embed\/([A-Za-z0-9_-]{6,})/);
			if (!match) {
				/* 認不出影片 ID（非 YouTube 或網址格式改過）就整個不處理 */
				return;
			}

			buildFacade(wrap, iframe, src, match[1]);
		});
	});

	function buildFacade(wrap, iframe, src, videoId) {
		var label = iframe.getAttribute('title') || '播放影片';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'wxa-video__facade';
		btn.setAttribute('aria-label', label);

		var img = document.createElement('img');
		img.className = 'wxa-video__thumb';
		img.alt = '';
		img.loading = 'lazy';
		img.decoding = 'async';
		img.src = THUMB + videoId + '/maxresdefault.jpg';

		/*
		 * maxresdefault 不是每支影片都有（早期或低畫質上傳就沒有），
		 * 缺的時候 YouTube 回一張 120×90 的灰底佔位圖而不是 404，
		 * 所以除了 error 事件，也要檢查載進來的尺寸。
		 * 退回 hqdefault 時掛 is-fallback，由 CSS 用 object-fit 裁成 16:9——
		 * 那是沒有更好來源時的妥協，至少不會變形。
		 */
		img.addEventListener('error', function () {
			useFallback(img, videoId);
		});
		img.addEventListener('load', function () {
			if (img.naturalWidth > 0 && img.naturalWidth < 200) {
				useFallback(img, videoId);
			}
		});

		var play = document.createElement('span');
		play.className = 'wxa-video__play';
		play.setAttribute('aria-hidden', 'true');

		btn.appendChild(img);
		btn.appendChild(play);

		btn.addEventListener('click', function () {
			/* 使用者明確點了才播，加 autoplay 才不會要求他再點一次 */
			iframe.setAttribute('src', src + (src.indexOf('?') > -1 ? '&' : '?') + 'autoplay=1');
			iframe.hidden = false;
			wrap.classList.remove('is-facade');
			btn.remove();
		});

		/* 先拿掉 src 再隱藏：沒有 src 的 iframe 不會發出任何請求 */
		iframe.removeAttribute('src');
		iframe.hidden = true;

		wrap.classList.add('is-facade');
		wrap.appendChild(btn);
	}

	function useFallback(img, videoId) {
		if (img.dataset.fallback === '1') {
			return;
		}
		img.dataset.fallback = '1';
		img.classList.add('is-fallback');
		img.src = THUMB + videoId + '/hqdefault.jpg';
	}
})();
