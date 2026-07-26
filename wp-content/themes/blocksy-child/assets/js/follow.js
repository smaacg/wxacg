/**
 * Follow Button Handler
 * Version: 1.0.0 (2026-05-13)
 *
 * 處理頁面上所有 .smacg-follow-btn 的點擊：
 * - data-user-id   被追蹤者 ID
 * - data-state     'follow' | 'following' | 'login'
 * - aria-busy 防止重複送出
 *
 * 依賴：window.smacgFollow = { ajax, nonce, loggedIn, loginUrl }
 * Toast：複用 public-profile.css 的 .pp-toast（公開頁已 inline 注入；其他頁會走 fallback）
 */
(function () {
	'use strict';

	if (typeof window.smacgFollow === 'undefined') {
		return;
	}

	const CFG = window.smacgFollow;

	/* ----------------------------------------------------------
	   Toast（簡易版，公開頁外的頁面用）
	   ---------------------------------------------------------- */
	function toast(msg, type) {
		type = type || 'ok';
		let el = document.querySelector('.pp-toast');
		if (!el) {
			el = document.createElement('div');
			el.className = 'pp-toast';
			document.body.appendChild(el);
		}
		el.textContent = msg;
		el.className = 'pp-toast pp-toast--' + type + ' pp-toast--show';
		clearTimeout(toast._t);
		toast._t = setTimeout(() => {
			el.classList.remove('pp-toast--show');
		}, 2200);
	}

	/* ----------------------------------------------------------
	   更新按鈕狀態
	   ---------------------------------------------------------- */
	function setBtnState(btn, state) {
		btn.dataset.state = state;
		btn.removeAttribute('aria-busy');
		btn.disabled = false;

		const icon = btn.querySelector('i');
		const label = btn.querySelector('.smacg-follow-label');

		if (state === 'following') {
			btn.classList.remove('smacg-follow-btn--idle');
			btn.classList.add('smacg-follow-btn--following');
			if (icon) icon.className = 'fa-solid fa-user-check';
			if (label) label.textContent = '追蹤中';
		} else if (state === 'follow') {
			btn.classList.remove('smacg-follow-btn--following');
			btn.classList.add('smacg-follow-btn--idle');
			if (icon) icon.className = 'fa-solid fa-user-plus';
			if (label) label.textContent = '追蹤';
		}
	}

	/* ----------------------------------------------------------
	   同步所有相同 target 的按鈕（頁面上可能多個）
	   ---------------------------------------------------------- */
	function syncAllBtns(targetId, state) {
		document
			.querySelectorAll('.smacg-follow-btn[data-user-id="' + targetId + '"]')
			.forEach((b) => setBtnState(b, state));
	}

	/* ----------------------------------------------------------
	   更新計數顯示（hero 區的粉絲數）
	   ---------------------------------------------------------- */
	function updateCounts(data) {
		if (typeof data.followers_count !== 'undefined') {
			document
				.querySelectorAll('[data-followers-of="' + (data.target_id || '') + '"], .pp-followers-count')
				.forEach((el) => {
					el.textContent = data.followers_count;
				});
		}
	}

	/* ----------------------------------------------------------
	   AJAX
	   ---------------------------------------------------------- */
	function sendRequest(action, targetId) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', CFG.nonce);
		fd.append('target_id', targetId);

		return fetch(CFG.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		}).then((r) => r.json());
	}

	/* ----------------------------------------------------------
	   點擊處理
	   ---------------------------------------------------------- */
	document.addEventListener('click', function (e) {
		const btn = e.target.closest('.smacg-follow-btn');
		if (!btn) return;

		e.preventDefault();

		// 未登入：導向登入頁
		if (!CFG.loggedIn) {
			window.location.href = CFG.loginUrl || '/wp-login.php';
			return;
		}

		const targetId = parseInt(btn.dataset.userId, 10);
		if (!targetId) {
			toast('使用者無效', 'err');
			return;
		}

		// 防連點
		if (btn.getAttribute('aria-busy') === 'true') return;
		btn.setAttribute('aria-busy', 'true');
		btn.disabled = true;

		const currentState = btn.dataset.state || 'follow';
		const action = currentState === 'following' ? 'smacg_unfollow' : 'wxacg_follow';

		sendRequest(action, targetId)
			.then((res) => {
				if (res && res.success) {
					const newState = res.data.is_following ? 'following' : 'follow';
					syncAllBtns(targetId, newState);
					updateCounts({
						target_id: targetId,
						followers_count: res.data.followers_count,
					});
					toast(res.data.message || (newState === 'following' ? '已追蹤' : '已取消追蹤'), 'ok');
				} else {
					const msg = (res && res.data && res.data.message) || '操作失敗';
					toast(msg, 'err');
					btn.removeAttribute('aria-busy');
					btn.disabled = false;

					// 若是 login_required，導向登入
					if (res && res.data && res.data.code === 'login_required' && res.data.login) {
						setTimeout(() => {
							window.location.href = res.data.login;
						}, 800);
					}
				}
			})
			.catch(() => {
				toast('網路錯誤，請稍後再試', 'err');
				btn.removeAttribute('aria-busy');
				btn.disabled = false;
			});
	});
})();
