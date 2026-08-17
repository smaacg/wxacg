/**
 * AI Tools page filter
 * Path: blocksy-child/assets/js/ai-tools.js
 *
 * 純前端 filter，無 AJAX。
 * 點 filter 按鈕 → 切換 .tool-card 顯示/隱藏。
 *
 * @version 1.0.0
 * @since   2026-05-16
 */
(function () {
    'use strict';

    const filterBar = document.getElementById( 'ai-tool-filter' );
    const grid      = document.getElementById( 'ai-tools-grid' );
    const empty     = document.getElementById( 'ai-tools-empty' );

    if ( ! filterBar || ! grid ) return;

    const cards = grid.querySelectorAll( '.tool-card' );

    filterBar.addEventListener( 'click', function ( e ) {
        const btn = e.target.closest( '.tool-filter-btn' );
        if ( ! btn ) return;

        // 切換 active 樣式
        filterBar.querySelectorAll( '.tool-filter-btn' )
                 .forEach( b => b.classList.remove( 'active' ) );
        btn.classList.add( 'active' );

        const cat = btn.dataset.category || 'all';
        let visibleCount = 0;

        cards.forEach( card => {
            const match = ( cat === 'all' ) || ( card.dataset.category === cat );
            card.classList.toggle( 'is-hidden', ! match );
            if ( match ) visibleCount++;
        } );

        // Empty state
        if ( empty ) {
            empty.hidden = visibleCount > 0;
        }

        // Re-trigger 進場動畫
        cards.forEach( ( card, i ) => {
            if ( ! card.classList.contains( 'is-hidden' ) ) {
                card.style.animation = 'none';
                // 強制 reflow
                void card.offsetWidth;
                card.style.animation = `ai-card-in 0.4s ease ${ i * 0.04 }s both`;
            }
        } );
    } );
})();
