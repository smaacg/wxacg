/**
 * News Filter AJAX
 * 路徑：blocksy-child/assets/js/news-filter.js
 *
 * 攔截 .news-filter-btn 點擊與 .news-pagination 分頁點擊，
 * 透過 AJAX 替換 #news-list-root 內容，
 * 並透過 History API 同步網址，
 * 支援瀏覽器前進 / 後退。
 *
 * @version 1.0.0
 * @since   2026-05-16
 */
(function () {
    'use strict';

    const cfg = window.smacgNewsFilter || {};
    if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
        console.warn( '[news-filter] missing config' );
        return;
    }

    const root      = document.getElementById( 'news-list-root' );
    const filterBar = document.getElementById( 'news-filter-bar' );
    if ( ! root || ! filterBar ) {
        return;
    }

    const contentType = filterBar.dataset.baseSlug || 'news';
    let isLoading = false;

    // ── 共用：執行 AJAX 並替換內容 ──
    function loadList( params, pushUrl ) {
        if ( isLoading ) return;
        isLoading = true;

        root.classList.add( 'is-loading' );
        // 滾動到列表上方（不要硬切）
        const rect = root.getBoundingClientRect();
        if ( rect.top < 0 ) {
            window.scrollTo( { top: window.scrollY + rect.top - 80, behavior: 'smooth' } );
        }

        const body = new URLSearchParams();
        body.append( 'action',       'smacg_news_filter' );
        body.append( 'nonce',        cfg.nonce );
        body.append( 'content_type', params.contentType );
        body.append( 'channel',      params.channel || '' );
        body.append( 'paged',        params.paged || 1 );

        fetch( cfg.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:        body,
        } )
        .then( r => r.json() )
        .then( res => {
            if ( ! res || ! res.success ) {
                throw new Error( res?.data?.message || 'Unknown error' );
            }

            // 淡出 → 替換 → 淡入
            root.style.opacity = '0';
            setTimeout( () => {
                root.innerHTML  = res.data.html;
                root.dataset.channel = params.channel || '';
                root.style.opacity = '';
                root.classList.remove( 'is-loading' );

                // 更新網址
                if ( pushUrl && res.data.url ) {
                    history.pushState(
                        { contentType: params.contentType, channel: params.channel, paged: params.paged },
                        '',
                        res.data.url
                    );
                }

                isLoading = false;
            }, 180 );
        } )
        .catch( err => {
            console.error( '[news-filter]', err );
            root.classList.remove( 'is-loading' );
            isLoading = false;
        } );
    }

    // ── Filter Tab 點擊 ──
    filterBar.addEventListener( 'click', function ( e ) {
        const btn = e.target.closest( '[data-ajax="1"]' );
        if ( ! btn ) return;

        e.preventDefault();

        // active 樣式切換
        filterBar.querySelectorAll( '.news-filter-btn' ).forEach( b => b.classList.remove( 'active' ) );
        btn.classList.add( 'active' );

        const target = btn.dataset.target || 'all';
        loadList( {
            contentType: contentType,
            channel:     target === 'all' ? '' : target,
            paged:       1,
        }, true );
    } );

    // ── 分頁點擊（事件委派）──
    root.addEventListener( 'click', function ( e ) {
        const link = e.target.closest( '.news-pagination a' );
        if ( ! link ) return;

        e.preventDefault();

        // 從 URL 解析頁碼
        const m = link.href.match( /\/page\/(\d+)\/?|[?&]paged=(\d+)/ );
        const paged = m ? parseInt( m[1] || m[2], 10 ) : 1;

        loadList( {
            contentType: contentType,
            channel:     root.dataset.channel || '',
            paged:       paged,
        }, true );
    } );

    // ── 瀏覽器上一頁 / 下一頁 ──
    window.addEventListener( 'popstate', function ( e ) {
        const st = e.state;
        if ( ! st || ! st.contentType ) {
            // 沒有狀態（首次進入或外站連入），直接刷新
            window.location.reload();
            return;
        }

        // 同步 active tab
        filterBar.querySelectorAll( '.news-filter-btn' ).forEach( b => {
            const t = b.dataset.target || 'all';
            const want = st.channel || 'all';
            b.classList.toggle( 'active', t === want );
        } );

        loadList( {
            contentType: st.contentType,
            channel:     st.channel || '',
            paged:       st.paged || 1,
        }, false );
    } );

    // ── 初始化 history state，方便第一次 popstate 還原 ──
    if ( ! history.state ) {
        history.replaceState( {
            contentType: contentType,
            channel:     root.dataset.channel || '',
            paged:       1,
        }, '' );
    }
})();
