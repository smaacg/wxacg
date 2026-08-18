/**
 * Admin JavaScript
 * File: admin/assets/js/admin.js
 *
 * 對齊修正：
 * 1. 所有 AJAX action 名稱從 animeSyncAdmin.actions.xxx 讀取，不再硬編碼
 * 2. 所有 i18n 字串從 animeSyncAdmin.i18n.xxx 讀取，與 PHP localize 完全對齊
 * 3. 移除 animeSyncAdmin.i18n?.xxx 的 optional chaining，統一用 helper 取值
 * 4. 所有外部資料插入 DOM 一律用 .text() / DOM 節點，不拼 HTML
 * 5. [清理] 移除死碼 loadDashboardStats()/setStatCell()
 *    （action 'anime_sync_get_stats' 後端已無 handler，dashboard 自 v1.1.0
 *     改 server-side render）。window.animeSyncBulkAction 為活碼，保留。
 *
 * v1.2.3 修正（匯入誤報 / 背景重複匯入）：
 *   - 格式篩選只隱藏列，不再強制取消勾選。
 *   - 系列 / 熱門 各自加重入鎖，防連點造成背景迴圈重複送 API。
 *
 * 2026-08-18：季度批次、ID 清單、人氣排行三段匯入實作已移除。
 * 這三段與 admin/pages/import-tool.php 內嵌的版本重複，而本檔會載入所有
 * anime-sync 頁面，兩份程式同時綁定同一批按鈕，造成每次操作重複送出 API、
 * 兩邊搶寫同一組進度 UI。詳見各區塊保留的說明註解。
 *
 * 本檔目前仍負責：單筆匯入、系列分析與系列匯入、批次操作（bulk action）、
 * Bangumi 重新同步。這幾項在 import-tool.php 沒有對應實作，不可移除。
 */

/* global jQuery, ajaxurl, animeSyncAdmin */
( function ( $ ) {
    'use strict';

    $( function () {

        if ( window.animeSyncAdminBooted ) {
            return;
        }
        window.animeSyncAdminBooted = true;

        /* ══════════════════════════════════════════════════════════════
           CONFIG — 從 PHP localize 讀取，建立本地常數
        ══════════════════════════════════════════════════════════════ */

        const AJAX_URL = animeSyncAdmin.ajaxUrl || ajaxurl;
        const NONCE    = animeSyncAdmin.nonce;

        /* action 名稱對照表（唯一來源） */
        const A = animeSyncAdmin.actions || {};

        /* i18n helper：找不到時用 fallback */
        function t( key, fallback ) {
            const i18n = animeSyncAdmin.i18n || {};
            return i18n[ key ] !== undefined ? i18n[ key ] : ( fallback || key );
        }

        /* ══════════════════════════════════════════════════════════════
           UTILITIES
        ══════════════════════════════════════════════════════════════ */

        function escHtml( str ) {
            if ( str === null || str === undefined ) { return ''; }
            return String( str )
                .replace( /&/g,  '&amp;'  )
                .replace( /</g,  '&lt;'   )
                .replace( />/g,  '&gt;'   )
                .replace( /"/g,  '&quot;' )
                .replace( /'/g,  '&#39;'  );
        }

        /* ★ log 安全輸出：永遠用 .text()，拒絕 HTML 注入 */
        function logLine( $log, text, type ) {
            const ts    = new Date().toLocaleTimeString( 'zh-TW', { hour12: false } );
            const $line = $( '<div>' )
                .addClass( 'log-' + ( type || 'info' ) )
                .text( '[' + ts + '] ' + String( text ) );
            $log.append( $line );
            $log.scrollTop( $log[ 0 ].scrollHeight );
        }

        function updateProgress( $bar, $text, done, total ) {
            const pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
            $bar.css( 'width', pct + '%' );
            $text.text( done + ' / ' + total + '  (' + pct + '%)' );
        }

        function delay( ms ) {
            return new Promise( function ( resolve ) { setTimeout( resolve, ms ); } );
        }

        function ajaxPost( data ) {
            data.nonce = NONCE;
            return $.post( AJAX_URL, data );
        }

        function parseIdList( raw ) {
            return raw
                .split( /[\n,\s]+/ )
                .map( function ( s ) { return parseInt( $.trim( s ), 10 ); } )
                .filter( function ( n ) { return ! isNaN( n ) && n > 0; } )
                .filter( function ( n, i, arr ) { return arr.indexOf( n ) === i; } );
        }

        /* 安全建立純文字 <td> */
        function makeTd( text ) {
            return $( '<td>' ).text( String( text === null || text === undefined ? '' : text ) );
        }

        /* ══════════════════════════════════════════════════════════════
           TAB SWITCHING
        ══════════════════════════════════════════════════════════════ */

        function switchTab( tab ) {
            $( '.anime-sync-import-tool .nav-tab' ).removeClass( 'nav-tab-active' );
            $( '.anime-sync-import-tool .nav-tab[data-tab="' + tab + '"]' ).addClass( 'nav-tab-active' );
            $( '.anime-sync-tab-content' ).hide();
            $( '#tab-' + tab ).show();
        }

        $( document ).on( 'click', '.anime-sync-import-tool .nav-tab', function ( e ) {
            e.preventDefault();
            const tab = $( this ).data( 'tab' );
            switchTab( tab );
            if ( tab ) {
                window.location.hash = tab;
            }
        } );

        const initialTab = window.location.hash.replace( '#', '' );
        if ( initialTab && $( '.anime-sync-import-tool .nav-tab[data-tab="' + initialTab + '"]' ).length ) {
            switchTab( initialTab );
        } else {
            switchTab( 'single' );
        }

        /* ══════════════════════════════════════════════════════════════
           SINGLE IMPORT
           ★ action 從 A.import_single 讀取
           ★ i18n 從 t() 讀取
        ══════════════════════════════════════════════════════════════ */

        $( '#btn-single-import' ).on( 'click', function () {
            const anilistId   = $.trim( $( '#single-anilist-id' ).val() );
            const forceUpdate = $( '#single-force-update' ).is( ':checked' ) ? 1 : 0;
            const $btn        = $( this );
            const $result     = $( '#single-import-result' );

            if ( ! anilistId || isNaN( anilistId ) || parseInt( anilistId ) < 1 ) {
                $result.empty().removeClass( 'success' ).addClass( 'error' )
                    .text( t( 'invalid_id', '請輸入有效的 AniList ID。' ) ).show();
                return;
            }

            $btn.prop( 'disabled', true ).text( t( 'importing', '匯入中…' ) );
            $result.hide().empty().removeClass( 'success error' );

            ajaxPost( {
                action     : A.import_single,   // ★ 從 actions 物件讀取
                anilist_id : parseInt( anilistId ),
                force      : forceUpdate,
                force_update: forceUpdate,
            } ).done( function ( resp ) {
                $result.empty();
                if ( resp.success ) {
                    const d = resp.data;
                    $result.addClass( 'success' );
                    $result.append( $( '<strong>' ).text( '✓ ' + String( d.title || '' ) ) );
                    if ( d.post_id && d.edit_url ) {
                        $result.append( $( '<br>' ) ).append(
                            $( '<a>' ).attr( 'href', d.edit_url ).attr( 'target', '_blank' )
                                .text( t( 'edit_post', '編輯文章' ) + ' #' + d.post_id )
                        );
                    }
                    if ( d.bangumi_pending ) {
                        $result.append( $( '<br>' ) ).append(
                            $( '<span>' ).css( 'color', '#f0a500' )
                                .text( '⚠ ' + t( 'bangumi_pending', 'Bangumi ID 未能自動解析。' ) )
                        );
                    }
                    if ( d.errors && d.errors.length ) {
                        $result.append( $( '<br>' ) ).append(
                            $( '<small>' ).css( 'color', '#dc3232' )
                                .text( d.errors.map( String ).join( '；' ) )
                        );
                    }
                } else {
                    $result.addClass( 'error' ).text(
                        '✗ ' + String( resp.data || t( 'import_failed', '匯入失敗。' ) )
                    );
                }
                $result.show();
            } ).fail( function () {
                $result.empty().addClass( 'error' )
                    .text( t( 'network_error', '網路錯誤，請重試。' ) ).show();
            } ).always( function () {
                $btn.prop( 'disabled', false ).text( t( 'start_import', '開始匯入' ) );
            } );
        } );

        /* ══════════════════════════════════════════════════════════════
           SEASON BATCH IMPORT — 已於 2026-08-18 移除

           本檔原有一份季度匯入實作（v1.2.3），但 admin/pages/import-tool.php
           內嵌了一份較新的版本（v1.9.2），兩者同時載入且綁定同一批按鈕：
             #btn-season-query / #btn-season-import / #btn-season-stop

           後果：
             · 按一次查詢會打兩次 AniList，匯入會跑兩套迴圈
             · 兩邊寫同一組進度 UI（import-tool.php 以 $prefix 動態產生
               season-progress-bar / season-import-log），互相覆蓋
             · 本檔的計數用未限定範圍的 $('.season-item-check:checked')，
               把手機卡片的 checkbox 一併算入，顯示「已勾選 190 部」
               但實際只有 95 部（117 部作品各有表格與卡片兩個 checkbox）

           import-tool.php 的版本功能完整涵蓋本區塊，且另有 ID 去重、
           NaN 過濾與手機卡片支援，因此保留該版本、移除本區塊。
           季度匯入相關邏輯請一律改動 import-tool.php。
        ══════════════════════════════════════════════════════════════ */

        /* ══════════════════════════════════════════════════════════════
           BATCH IMPORT (ID list) — 已於 2026-08-18 移除

           與 admin/pages/import-tool.php 內嵌的版本重複，兩者同時載入且
           綁定同一批按鈕（#btn-batch-import / #btn-batch-stop），造成每次
           匯入重複送出 API、兩邊搶寫同一組進度 UI
           （import-tool.php 以 asc_progress_block('batch') 產生）。
           理由與季度匯入相同，請一律改動 import-tool.php。
        ══════════════════════════════════════════════════════════════ */
        /* ══════════════════════════════════════════════════════════════
           SERIES IMPORT
           ★ action 從 A.analyze_series / A.import_series 讀取
        ══════════════════════════════════════════════════════════════ */

        let seriesImportStop = false;
        let seriesImporting  = false;   // ★ 新增：重入鎖
        let seriesMeta       = { series_name: '', root_id: 0, series_romaji: '' };

        $( '#btn-analyze-series' ).on( 'click', function () {
            const id = parseInt( $( '#series-anilist-id' ).val() );
            if ( ! id || id <= 0 ) { alert( t( 'invalid_id', '請輸入有效的 AniList ID。' ) ); return; }

            const $btn = $( this ).prop( 'disabled', true ).text( '分析中…' );
            $( '#series-analyze-spinner' ).show();
            $( '#series-result' ).hide();

            ajaxPost( { action: A.analyze_series, anilist_id: id } )
            .done( function ( res ) {
                if ( res.success && res.data.tree ) {
                    const d = res.data;
                    seriesMeta = { series_name: d.series_name, root_id: d.root_id, series_romaji: d.series_romaji || '' };

                    const $info = $( '#series-info' ).empty();
                    $info.append( $( '<strong>' ).text( '🎯 系列名稱：' + String( d.series_name ) ) );
                    $info.append( document.createTextNode( '　根源 ID：' + d.root_id + '　共 ' + d.total + ' 部　' ) );
                    $info.append( $( '<span>' ).css( 'color', 'green' ).text( '已匯入 ' + d.imported + ' 部' ) );
                    $info.append( document.createTextNode( '　' ) );
                    $info.append( $( '<span>' ).css( 'color', '#d97706' ).text( '待匯入 ' + ( d.total - d.imported ) + ' 部' ) );

                    // ★ 後端標記資料不完整（AniList 429 斷頭）時，紅字警告
                    if ( d.incomplete ) {
                        $info.append( $( '<br>' ) );
                        $info.append(
                            $( '<span>' ).css( { color: '#dc3232', 'font-weight': 'bold' } )
                                .text( '⚠ 注意：本次分析期間 AniList 連線受限，系列可能不完整，建議約 5 分鐘後重新分析一次。' )
                        );
                    }

                    renderSeriesTable( d.tree );
                    $( '#series-result' ).show();
                    $( '#btn-series-import' ).prop( 'disabled', false );
                } else {
                    alert( String( ( res.data && res.data.message ) ? res.data.message : '分析失敗' ).replace( /<[^>]*>/g, '' ) );
                }
            } ).fail( function () { alert( t( 'network_error', '網路錯誤，請重試。' ) ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( '🔍 分析系列' ); $( '#series-analyze-spinner' ).hide(); } );
        } );

        function renderSeriesTable( tree ) {
            const labelMap = { PREQUEL:'前作', SEQUEL:'續作', SIDE_STORY:'外傳', SPIN_OFF:'衍生', ALTERNATIVE:'平行', PARENT:'主作品' };
            const $tbody   = $( '#series-tbody' ).empty();

            $.each( tree, function ( i, node ) {
                const aid     = parseInt( node.anilist_id );
                const name    = String( node.title_chinese || node.title_romaji || node.title_native || ( 'ID ' + aid ) );
                const relType = node.relation_type ? ( labelMap[ node.relation_type ] || node.relation_type ) : '根源';

                const $chk = $( '<input type="checkbox">' )
                    .addClass( 'series-item-check' ).val( aid ).prop( 'checked', ! node.imported );

                const $nameTd = $( '<td>' ).text( name );
                if ( node.imported && node.edit_url ) {
                    $nameTd.append( ' ' ).append(
                        $( '<a>' ).attr( 'href', node.edit_url ).attr( 'target', '_blank' ).css( 'font-size', '11px' ).text( '[編輯]' )
                    );
                }

                const $impSpan = $( '<span>' )
                    .addClass( node.imported ? 'status-imported' : 'status-new' )
                    .text( node.imported ? '✅ 已匯入' : '⬜ 未匯入' );

                const $tr = $( '<tr>' );
                $tr.append( $( '<td>' ).append( $chk ) );
                $tr.append( makeTd( aid ) );
                $tr.append( $nameTd );
                $tr.append( makeTd( node.format      || '—' ) );
                $tr.append( makeTd( node.season_year || '—' ) );
                $tr.append( makeTd( relType ) );
                $tr.append( $( '<td>' ).append( $impSpan ) );
                $tbody.append( $tr );
            } );
        }

        $( '#series-select-all' ).on( 'change', function () {
            $( '.series-item-check' ).prop( 'checked', this.checked );
        } );

        $( '#btn-series-import' ).on( 'click', async function () {
            if ( seriesImporting ) { return; }   // ★ 防連點

            const ids = $( '.series-item-check:checked' ).map( function () { return parseInt( $( this ).val() ); } ).get();
            if ( ! ids.length ) { alert( '請至少選擇一部' ); return; }

            seriesImporting  = true;   // ★ 上鎖
            seriesImportStop = false;
            const $btnImport = $( this );
            const $btnStop   = $( '#btn-series-stop' );
            const $bar       = $( '#series-progress-bar' );
            const $text      = $( '#series-progress-text' );
            const $log       = $( '#series-import-log' );

            $btnImport.prop( 'disabled', true );
            $btnStop.show().prop( 'disabled', false ).text( t( 'stop', '停止' ) );
            $( '#series-progress-wrap' ).show();
            $log.empty();
            updateProgress( $bar, $text, 0, ids.length );

            let done = 0;

            try {
                for ( const anilistId of ids ) {
                    if ( seriesImportStop ) { logLine( $log, t( 'import_stopped', '已停止匯入。' ), 'info' ); break; }
                    logLine( $log, 'AniList #' + anilistId + ' …', 'info' );
                    try {
                        const resp = await $.post( AJAX_URL, {
                            action         : A.import_series,   // ★ 從 actions 讀取
                            nonce          : NONCE,
                            anilist_id     : anilistId,
                            series_name    : seriesMeta.series_name,
                            root_id        : seriesMeta.root_id,
                            series_romaji  : seriesMeta.series_romaji,
                        } );
                        done++;
                        updateProgress( $bar, $text, done, ids.length );
                        if ( resp.success ) {
                            const d     = resp.data;
                            const extra = d.series_assigned ? ' 🔗 已歸入系列' : '';
                            logLine( $log,
                                '✓ ' + String( d.title || 'AniList #' + anilistId ) + String( d.message || '' ) + extra,
                                d.bangumi_missing ? 'warning' : ( d.skipped ? 'skip' : 'success' )
                            );
                        } else {
                            logLine( $log, '✗ AniList #' + anilistId + '：' + String( ( resp.data && resp.data.message ) || t( 'unknown_error', '未知錯誤' ) ), 'error' );
                        }
                    } catch ( e ) {
                        done++;
                        updateProgress( $bar, $text, done, ids.length );
                        logLine( $log, '✗ AniList #' + anilistId + '：網路錯誤', 'error' );
                    }
                    if ( ! seriesImportStop && done < ids.length ) { await delay( 3200 ); }
                }

                logLine( $log, '── 匯入完成 ──', 'info' );
            } finally {
                seriesImporting = false;   // ★ 解鎖
                $btnImport.prop( 'disabled', false );
                $btnStop.hide();
            }
        } );

        $( '#btn-series-stop' ).on( 'click', function () {
            seriesImportStop = true;
            $( this ).prop( 'disabled', true ).text( t( 'stopping', '停止中…' ) );
        } );

        /* ══════════════════════════════════════════════════════════════
           POPULARITY RANKING — 已於 2026-08-18 移除

           與 admin/pages/import-tool.php 內嵌的版本重複，兩者同時載入且
           綁定同一批按鈕（#btn-ranking-load / -more / -import / -stop），
           造成每次操作重複送出 API、兩邊搶寫同一組進度 UI
           （import-tool.php 以 asc_progress_block('ranking') 產生）。
           理由與季度匯入相同，請一律改動 import-tool.php。
        ══════════════════════════════════════════════════════════════ */
        /* ══════════════════════════════════════════════════════════════
           DASHBOARD STATS
           [清理 v1.2.2] 已移除 loadDashboardStats() 與 setStatCell()。
           原因：action 'anime_sync_get_stats' 後端無對應 handler，
           dashboard 自 v1.1.0 改 server-side render（SQL 直接撈），
           此段 JS 為永不執行的死碼。
           注意：以下 window.animeSyncBulkAction 為「活碼」，走 A.bulk_action，
                 供文章列表批次操作呼叫，務必保留。
        ══════════════════════════════════════════════════════════════ */

        window.animeSyncBulkAction = function ( action, postIds, callback ) {
            ajaxPost( { action: A.bulk_action, bulk: action, post_ids: postIds } ).done( callback );
        };

        /* ══════════════════════════════════════════════════════════════
           RESYNC BANGUMI
           ★ action 從 A.resync_bangumi 讀取
           ★ i18n 全部從 t() 讀取
        ══════════════════════════════════════════════════════════════ */

        $( document ).on(
            'input change',
            '#acf-field_anime_bangumi_id, input[name="acf[field_anime_bangumi_id]"]',
            function () {
                const val = parseInt( $( this ).val(), 10 );
                $( '#anime-resync-bangumi-btn' ).prop( 'disabled', ! ( val > 0 ) );
            }
        );

        $( '#anime-resync-bangumi-btn' ).on( 'click', function () {
            const $btn = $( this );
            const $msg = $( '#anime-resync-bangumi-msg' );

            const postId = $( '#post_ID' ).val()
                        || new URLSearchParams( window.location.search ).get( 'post' )
                        || '0';

            const bangumiId = $( '#acf-field_anime_bangumi_id' ).val()
                           || $( 'input[name="acf[field_anime_bangumi_id]"]' ).val()
                           || '';

            if ( ! postId || postId === '0' ) {
                $msg.css( 'color', '#d63638' ).text( '請先儲存草稿以取得文章 ID，再執行同步。' );
                return;
            }
            if ( ! bangumiId || parseInt( bangumiId, 10 ) <= 0 ) {
                $msg.css( 'color', '#d63638' ).text( t( 'error_no_id', '請先填入 Bangumi ID。' ) );
                return;
            }

            $btn.prop( 'disabled', true );
            $msg.css( 'color', '#666' ).text( t( 'syncing', '同步中，請稍候…' ) );

            $.ajax( {
                url      : AJAX_URL,
                type     : 'POST',
                data     : { action: A.resync_bangumi, nonce: NONCE, post_id: postId, bangumi_id: bangumiId },
                dataType : 'json',
                timeout  : 60000,
            } ).done( function ( res ) {
                if ( res && res.success ) {
                    $msg.css( 'color', '#00a32a' ).text( t( 'sync_success', '✅ 同步完成，頁面即將重新整理…' ) );
                    setTimeout( function () { location.reload(); }, 1500 );
                } else {
                    const errMsg = ( res && res.data && res.data.message )
                        ? String( res.data.message )
                        : ( res && res.data
                            ? ( typeof res.data === 'string' ? res.data : JSON.stringify( res.data ) )
                            : t( 'unknown_error', '未知錯誤' ) );
                    $msg.css( 'color', '#d63638' ).text( '❌ ' + errMsg );
                    $btn.prop( 'disabled', false );
                }
            } ).fail( function ( xhr, status ) {
                const detail = status === 'timeout'
                    ? '請求逾時，請重試。'
                    : t( 'network_error', '網路錯誤，請重試。' );
                $msg.css( 'color', '#d63638' ).text( '❌ ' + detail );
                $btn.prop( 'disabled', false );
            } );
        } );

    } ); // end document.ready

} )( jQuery );
