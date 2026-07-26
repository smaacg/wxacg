/**
 * Notifications UI
 * Version: 1.0.2 (2026-05-13)
 *
 * 功能：
 * 1. 鈴鐺 DOM 由 header.php 預先輸出，本檔只負責綁事件與互動
 * 2. 點鈴鐺打開/關閉下拉面板
 * 3. 60 秒輪詢未讀數
 * 4. 通知列表載入、標記已讀、刪除、全部已讀
 * 5. 會員中心通知 tab 互動（filter、loadmore、delete）
 * 6. 會員中心通知偏好 AJAX 儲存（IIFE #2）
 *
 * 依賴：window.smacgNotif = { ajax, nonce, loggedIn, mcUrl, pollInterval }
 *
 * v1.0.2: 移除 DOM 注入邏輯（buildBellHTML/injectBell），
 *         改由 header.php 直接在 .header-actions 內輸出鈴鐺，
 *         徹底避免與主題 header 元素衝突。
 */
(function () {
	'use strict';

	if (typeof window.smacgNotif === 'undefined') return;
	if (!window.smacgNotif.loggedIn) return;

	const CFG = window.smacgNotif;
	const POLL_MS = (CFG.pollInterval || 60) * 1000;

	let pollTimer = null;
	let panelOpen = false;
	let currentFilter = 'all';

	/* =========================================================
	   工具
	   ========================================================= */
	function api(action, params) {
		const body = new URLSearchParams({ action: action, nonce: CFG.nonce });
		if (params) {
			Object.keys(params).forEach((k) => body.append(k, params[k]));
		}
		return fetch(CFG.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		}).then((r) => r.json());
	}

	function escapeHtml(s) {
		if (s == null) return '';
		return String(s).replace(/[&<>"']/g, (m) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
		}[m]));
	}

	/* =========================================================
	   鈴鐺事件 (v1.0.2: DOM 已由 header.php 輸出)
	   ========================================================= */
	function bindBellEvents() {
		const bell = document.getElementById('smacg-bell');
		const panel = document.getElementById('smacg-bell-panel');
		if (!bell || !panel) return false;

		bell.addEventListener('click', (e) => {
			e.stopPropagation();
			togglePanel();
		});

		document.addEventListener('click', (e) => {
			if (!panelOpen) return;
			if (e.target.closest('#smacg-bell-wrap')) return;
			closePanel();
		});

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && panelOpen) closePanel();
		});

		const markAll = document.getElementById('smacg-bell-mark-all');
		if (markAll) {
			markAll.addEventListener('click', (e) => {
				e.stopPropagation();
				markAllRead();
			});
		}
		return true;
	}

	function togglePanel() {
		if (panelOpen) closePanel();
		else openPanel();
	}

	function openPanel() {
		const bell = document.getElementById('smacg-bell');
		const panel = document.getElementById('smacg-bell-panel');
		if (!panel) return;
		panel.hidden = false;
		bell.setAttribute('aria-expanded', 'true');
		panelOpen = true;
		loadPanelList();
	}

	function closePanel() {
		const bell = document.getElementById('smacg-bell');
		const panel = document.getElementById('smacg-bell-panel');
		if (!panel) return;
		panel.hidden = true;
		bell.setAttribute('aria-expanded', 'false');
		panelOpen = false;
	}

	/* =========================================================
	   下拉面板：載入清單
	   ========================================================= */
	function loadPanelList() {
		const list = document.getElementById('smacg-bell-panel-list');
		if (!list) return;
		list.innerHTML = '<div class="smacg-bell-loading"><i class="fa-solid fa-spinner fa-spin"></i> 載入中…</div>';

		api('smacg_notif_list', { limit: 10, offset: 0 }).then((res) => {
			if (!res || !res.success) {
				list.innerHTML = '<div class="smacg-bell-empty">載入失敗</div>';
				return;
			}
			renderPanelItems(res.data.items || []);
			updateUnreadBadge(res.data.unread || 0);
		});
	}

	function renderPanelItems(items) {
		const list = document.getElementById('smacg-bell-panel-list');
		if (!list) return;
		if (!items.length) {
			list.innerHTML = '<div class="smacg-bell-empty"><i class="fa-solid fa-bell-slash"></i><p>沒有通知</p></div>';
			return;
		}
		list.innerHTML = items.map(itemHTML).join('');

		list.querySelectorAll('.smacg-bell-item').forEach((el) => {
			el.addEventListener('click', () => {
				const id = parseInt(el.dataset.id, 10);
				if (id) markRead(id);
			});
		});
	}

	function itemHTML(n) {
		const unreadCls = n.is_read ? '' : ' is-unread';
		const avatar = n.actor_avatar
			? '<img src="' + escapeHtml(n.actor_avatar) + '" alt="" loading="lazy">'
			: '<i class="fa-solid ' + escapeHtml(n.icon || 'fa-bell') + '"></i>';
		return (
			'<a class="smacg-bell-item' + unreadCls + '" href="' + escapeHtml(n.url || '#') + '" data-id="' + n.id + '">' +
			'  <div class="smacg-bell-item-icon">' + avatar + '</div>' +
			'  <div class="smacg-bell-item-body">' +
			'    <p class="smacg-bell-item-title">' + escapeHtml(n.title) + '</p>' +
			(n.excerpt ? '    <p class="smacg-bell-item-excerpt">' + escapeHtml(n.excerpt) + '</p>' : '') +
			'    <p class="smacg-bell-item-time">' + escapeHtml(n.time_diff) + '</p>' +
			'  </div>' +
			'</a>'
		);
	}

	/* =========================================================
	   標記已讀 / 全部已讀
	   ========================================================= */
	function markRead(id) {
		return api('smacg_notif_mark_read', { id: id }).then((res) => {
			if (res && res.success) {
				updateUnreadBadge(res.data.unread || 0);
			}
		}).catch(() => {});
	}

	function markAllRead() {
		api('smacg_notif_mark_all_read').then((res) => {
			if (res && res.success) {
				updateUnreadBadge(0);
				if (panelOpen) loadPanelList();
				document.querySelectorAll('.smacg-notif-item.is-unread, .smacg-bell-item.is-unread')
					.forEach((el) => el.classList.remove('is-unread'));
				const mcBadge = document.querySelector('[data-unread-badge]');
				if (mcBadge) mcBadge.textContent = '0';
				const markAllBtn = document.querySelector('.smacg-notif-mark-all');
				if (markAllBtn) markAllBtn.disabled = true;
			}
		});
	}

	/* =========================================================
	   未讀數徽章
	   ========================================================= */
	function updateUnreadBadge(n) {
		const dot = document.getElementById('smacg-bell-dot');
		if (dot) {
			if (n > 0) {
				dot.textContent = n > 99 ? '99+' : String(n);
				dot.hidden = false;
			} else {
				dot.hidden = true;
			}
		}
		const mcBadge = document.querySelector('[data-unread-badge]');
		if (mcBadge) mcBadge.textContent = n;
	}

	/* =========================================================
	   輪詢
	   ========================================================= */
	function pollUnreadCount() {
		api('smacg_notif_unread_count').then((res) => {
			if (res && res.success) {
				updateUnreadBadge(res.data.unread || 0);
			}
		}).catch(() => {});
	}

	function startPolling() {
		pollUnreadCount();
		pollTimer = setInterval(pollUnreadCount, POLL_MS);
		document.addEventListener('visibilitychange', () => {
			if (!document.hidden) pollUnreadCount();
		});
	}

	/* =========================================================
	   會員中心通知 tab 互動
	   ========================================================= */
	function bindMemberCenterPage() {
		const page = document.querySelector('.smacg-notif-page');
		if (!page) return;

		page.querySelectorAll('.smacg-notif-filter').forEach((btn) => {
			btn.addEventListener('click', () => {
				page.querySelectorAll('.smacg-notif-filter').forEach((b) => b.classList.remove('active'));
				btn.classList.add('active');
				currentFilter = btn.dataset.filter;
				reloadMcList();
			});
		});

		const markAllBtn = page.querySelector('.smacg-notif-mark-all');
		if (markAllBtn) {
			markAllBtn.addEventListener('click', () => markAllRead());
		}

		page.addEventListener('click', (e) => {
			const delBtn = e.target.closest('[data-delete]');
			if (delBtn) {
				e.preventDefault();
				e.stopPropagation();
				const item = delBtn.closest('.smacg-notif-item');
				if (!item) return;
				const id = parseInt(item.dataset.id, 10);
				deleteItem(id, item);
				return;
			}

			const item = e.target.closest('.smacg-notif-item');
			if (item && item.classList.contains('is-unread')) {
				const id = parseInt(item.dataset.id, 10);
				if (id) markRead(id);
				item.classList.remove('is-unread');
			}
		});

		const moreBtn = page.querySelector('.smacg-notif-loadmore');
		if (moreBtn) {
			moreBtn.addEventListener('click', () => loadMoreMc(moreBtn));
		}
	}

	function deleteItem(id, el) {
		api('smacg_notif_delete', { id: id }).then((res) => {
			if (res && res.success) {
				el.style.transition = 'opacity .2s, transform .2s';
				el.style.opacity = '0';
				el.style.transform = 'translateX(20px)';
				setTimeout(() => el.remove(), 220);
				updateUnreadBadge(res.data.unread || 0);
			}
		});
	}

	function reloadMcList() {
		const list = document.querySelector('.smacg-notif-page-list');
		if (!list) return;
		list.innerHTML = '<div class="smacg-bell-loading"><i class="fa-solid fa-spinner fa-spin"></i> 載入中…</div>';

		api('smacg_notif_list', {
			limit: 20,
			offset: 0,
			unread_only: currentFilter === 'unread' ? 1 : 0,
		}).then((res) => {
			if (!res || !res.success) {
				list.innerHTML = '<div class="smacg-notif-empty">載入失敗</div>';
				return;
			}
			renderMcItems(res.data.items || []);
			updateUnreadBadge(res.data.unread || 0);

			const moreBtn = document.querySelector('.smacg-notif-loadmore');
			if (moreBtn) {
				moreBtn.dataset.offset = '20';
				moreBtn.style.display = (res.data.items.length >= 20) ? '' : 'none';
			}
		});
	}

	function renderMcItems(items) {
		const list = document.querySelector('.smacg-notif-page-list');
		if (!list) return;
		if (!items.length) {
			list.innerHTML = '<div class="smacg-notif-empty"><i class="fa-solid fa-bell-slash"></i><p>沒有通知</p></div>';
			return;
		}
		list.innerHTML = items.map(mcItemHTML).join('');
	}

	function mcItemHTML(n) {
		const unreadCls = n.is_read ? '' : ' is-unread';
		const iconHTML = '<span class="smacg-notif-item-badge smacg-notif-item-badge--lg"><i class="fa-solid ' + escapeHtml(n.icon || 'fa-bell') + '"></i></span>';
		const avatarHTML = n.actor_avatar
			? '<img src="' + escapeHtml(n.actor_avatar) + '" alt="" loading="lazy">' +
			  '<span class="smacg-notif-item-badge"><i class="fa-solid ' + escapeHtml(n.icon || 'fa-bell') + '"></i></span>'
			: iconHTML;

		return (
			'<a class="smacg-notif-item' + unreadCls + '" href="' + escapeHtml(n.url || '#') + '" data-id="' + n.id + '" data-type="' + escapeHtml(n.type) + '">' +
			'  <div class="smacg-notif-item-icon">' + avatarHTML + '</div>' +
			'  <div class="smacg-notif-item-body">' +
			'    <p class="smacg-notif-item-title">' + escapeHtml(n.title) + '</p>' +
			(n.excerpt ? '    <p class="smacg-notif-item-excerpt">' + escapeHtml(n.excerpt) + '</p>' : '') +
			'    <p class="smacg-notif-item-time">' + escapeHtml(n.time_diff) + '</p>' +
			'  </div>' +
			'  <button type="button" class="smacg-notif-item-delete" data-delete aria-label="刪除"><i class="fa-solid fa-xmark"></i></button>' +
			'</a>'
		);
	}

	function loadMoreMc(btn) {
		const offset = parseInt(btn.dataset.offset || '20', 10);
		btn.disabled = true;
		btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 載入中…';

		api('smacg_notif_list', {
			limit: 20,
			offset: offset,
			unread_only: currentFilter === 'unread' ? 1 : 0,
		}).then((res) => {
			btn.disabled = false;
			btn.innerHTML = '<i class="fa-solid fa-arrow-down"></i> 載入更多';

			if (!res || !res.success) return;
			const list = document.querySelector('.smacg-notif-page-list');
			const items = res.data.items || [];
			if (!items.length) {
				btn.style.display = 'none';
				return;
			}
			list.insertAdjacentHTML('beforeend', items.map(mcItemHTML).join(''));
			btn.dataset.offset = String(offset + items.length);
			if (items.length < 20) btn.style.display = 'none';
		});
	}

	/* =========================================================
	   啟動
	   ========================================================= */
	function init() {
		// 鈴鐺由 header.php 預渲染，這裡只負責綁事件
		if (bindBellEvents()) {
			startPolling();
		}
		bindMemberCenterPage();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();


/* =========================================================
   v1.1.0 (2026-05-13) - Batch 1C-4 通知偏好設定 AJAX 儲存
   ========================================================= */
(function () {
	'use strict';
	if (typeof window.smacgNotif === 'undefined') return;
	if (!window.smacgNotif.loggedIn) return;

	const CFG = window.smacgNotif;
	let saveTimer = null;

	function init() {
		const wrap = document.querySelector('.mc-notif-prefs');
		if (!wrap) return;

		wrap.addEventListener('change', () => {
			if (saveTimer) clearTimeout(saveTimer);
			saveTimer = setTimeout(() => savePrefs(wrap), 400);
		});
	}

	function savePrefs(wrap) {
		const status = wrap.querySelector('[data-status]');
		const inputs = wrap.querySelectorAll('[data-pref]');

		const body = new URLSearchParams({
			action: 'smacg_notif_save_prefs',
			nonce: CFG.nonce,
		});

		inputs.forEach((el) => {
			const key = el.dataset.pref;
			if (!key) return;
			if (el.type === 'checkbox') {
				body.append('prefs[' + key + ']', el.checked ? '1' : '0');
			} else {
				body.append('prefs[' + key + ']', el.value);
			}
			el.disabled = true;
		});

		showStatus(status, '儲存中…', '');

		fetch(CFG.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then((r) => r.json())
			.then((res) => {
				inputs.forEach((el) => { el.disabled = false; });
				if (res && res.success) {
					showStatus(status, '✓ 已儲存', 'is-ok');
				} else {
					const msg = (res && res.data && res.data.message) || '儲存失敗';
					showStatus(status, '✗ ' + msg, 'is-err');
				}
			})
			.catch(() => {
				inputs.forEach((el) => { el.disabled = false; });
				showStatus(status, '✗ 網路錯誤', 'is-err');
			});
	}

	function showStatus(el, text, cls) {
		if (!el) return;
		el.textContent = text;
		el.className = 'mc-notif-prefs-status' + (cls ? ' ' + cls : '');
		el.hidden = false;
		clearTimeout(showStatus._t);
		showStatus._t = setTimeout(() => {
			if (el.classList.contains('is-ok')) el.hidden = true;
		}, 1800);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
