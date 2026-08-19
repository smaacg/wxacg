/**
 * 角色／聲優頁的收藏按鈕。
 *
 * 只在登入狀態載入（見 class-entity-routing.php 的 enqueue_assets）——
 * 未登入時模板渲染的是導向登入頁的 <a>，不需要互動邏輯。
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.querySelector('.asa-fav-btn');

        if (!btn || typeof aspEntityFav === 'undefined') {
            return;
        }

        const icon = btn.querySelector('.asa-fav-icon');
        const text = btn.querySelector('.asa-fav-text');

        /** 依狀態更新外觀。 */
        function render(fav) {
            btn.classList.toggle('is-active', fav);
            btn.setAttribute('aria-pressed', fav ? 'true' : 'false');
            btn.title = fav ? '取消收藏' : '收藏';

            if (icon) icon.textContent = fav ? '❤️' : '🤍';
            if (text) text.textContent = fav ? '已收藏' : '收藏';
        }

        btn.addEventListener('click', function () {
            if (btn.disabled) {
                return;
            }

            /*
             * 樂觀更新：先翻轉外觀讓點擊有即時回饋，失敗再翻回來。
             * 期間 disable 按鈕，避免連點造成的競態
             * （伺服器端另有 UNIQUE 約束把關，這裡只是省掉無謂的請求）。
             */
            const before = btn.classList.contains('is-active');

            btn.disabled = true;
            render(!before);

            fetch(aspEntityFav.restUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': aspEntityFav.nonce,
                },
                body: JSON.stringify({
                    type: btn.dataset.entityType,
                    id: parseInt(btn.dataset.entityId, 10),
                }),
            })
                .then(function (res) {
                    return res.json().then(function (data) {
                        if (!res.ok) {
                            throw new Error((data && data.message) || '操作失敗');
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    // 以伺服器回報的狀態為準，不沿用樂觀更新的結果
                    render(!!data.favorited);
                })
                .catch(function (err) {
                    render(before);

                    if (window.console && console.warn) {
                        console.warn('[entity-favorite]', err.message);
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    });
})();
