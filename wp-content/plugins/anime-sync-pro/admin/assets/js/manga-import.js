/**
 * 漫畫匯入頁
 * File: admin/assets/js/manga-import.js
 *
 * 原本這段是內嵌在 class-manga-admin.php 的 heredoc 裡；加入批次功能後
 * 長度會讓那個 heredoc 難以維護，因此拆成獨立檔案。
 *
 * 設定由 PHP 以 aspMangaImport 傳入（ajaxUrl / nonce）。
 *
 * 批次匯入的作法：一次只送一部，逐筆等回應再送下一部。
 * 不併發的理由 ——
 *   1. 後端有速率限制（AniList 2 秒、Bangumi 1 秒），併發只會排隊
 *   2. 單部匯入會打 AniList + Bangumi + Wikipedia，併發容易觸發對方限流
 *   3. 逐筆才能給出正確的進度與可停止點
 */
( function () {
	'use strict';

	var CFG = window.aspMangaImport || {};

	if ( ! CFG.ajaxUrl ) {
		return;
	}

	var $  = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };

	var stopped   = false;
	var running   = false;
	var rankPage  = 0;

	/* ══════════════════════════════════════════════
	   分頁切換
	   ══════════════════════════════════════════════ */

	$$( '.asp-mi-tabs .nav-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			$$( '.asp-mi-tabs .nav-tab' ).forEach( function ( t ) {
				t.classList.remove( 'nav-tab-active' );
			} );
			tab.classList.add( 'nav-tab-active' );

			$$( '.asp-mi-panel' ).forEach( function ( p ) {
				p.style.display = ( p.dataset.panel === tab.dataset.tab ) ? '' : 'none';
			} );
		} );
	} );

	/* ══════════════════════════════════════════════
	   共用工具
	   ══════════════════════════════════════════════ */

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', CFG.nonce );

		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		return fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) { return r.json(); } );
	}

	function log( text, type ) {
		var box = $( '#asp-mi-log' );

		if ( ! box ) { return; }

		var colors = { ok: '#68de7c', err: '#ff6b6b', warn: '#f0c674', info: '#c3c6ce' };
		var line   = document.createElement( 'div' );

		line.style.color = colors[ type ] || colors.info;
		line.textContent = '[' + new Date().toLocaleTimeString( 'zh-TW', { hour12: false } ) + '] ' + text;

		box.appendChild( line );
		box.scrollTop = box.scrollHeight;
	}

	function setProgress( done, total ) {
		var pct = total > 0 ? Math.round( done / total * 100 ) : 0;

		$( '#asp-mi-progress-bar' ).style.width = pct + '%';
		$( '#asp-mi-progress-text' ).textContent = done + ' / ' + total + '（' + pct + '%）';
	}

	/** 解析 ID 清單：接受換行、逗號、空白分隔；去除非數字與重複。 */
	function parseIds( raw ) {
		var seen = {};

		return String( raw || '' )
			.split( /[\s,，、]+/ )
			.map( function ( s ) { return parseInt( s, 10 ); } )
			.filter( function ( n ) {
				if ( ! n || n <= 0 || seen[ n ] ) { return false; }
				seen[ n ] = true;
				return true;
			} );
	}

	/* ══════════════════════════════════════════════
	   單筆匯入（沿用原本的行為）
	   ══════════════════════════════════════════════ */

	var singleBtn = $( '#asp-manga-import-btn' );

	if ( singleBtn ) {
		singleBtn.addEventListener( 'click', function () {
			var id     = parseInt( $( '#asp-manga-anilist-id' ).value, 10 );
			var bgm    = $( '#asp-manga-bangumi-id' );
			var force  = $( '#asp-manga-force' );
			var result = $( '#asp-manga-result' );

			if ( ! id ) {
				result.innerHTML = '<span style="color:#d63638">請輸入 AniList 漫畫 ID</span>';
				return;
			}

			singleBtn.disabled = true;
			result.textContent = '匯入中，請稍候…';

			var payload = { anilist_id: id };

			if ( bgm && bgm.value )   { payload.bangumi_id = parseInt( bgm.value, 10 ) || 0; }
			if ( force && force.checked ) { payload.force = 1; }

			post( 'anime_sync_manga_import_single', payload )
				.then( function ( res ) {
					singleBtn.disabled = false;

					if ( res.success ) {
						var d     = res.data || {};
						var link  = d.edit_link ? ' <a href="' + d.edit_link + '">編輯此漫畫</a>' : '';
						var color = d.skipped ? '#dba617' : '#00a32a';

						result.innerHTML = '<span style="color:' + color + '">' +
							( d.message || '匯入成功' ) + '</span>' + link;
					} else {
						result.innerHTML = '<span style="color:#d63638">' +
							( ( res.data && res.data.message ) || '匯入失敗' ) + '</span>';
					}
				} )
				.catch( function () {
					singleBtn.disabled = false;
					result.innerHTML = '<span style="color:#d63638">網路錯誤，請重試</span>';
				} );
		} );
	}

	/* ══════════════════════════════════════════════
	   ID 清單：即時計數
	   ══════════════════════════════════════════════ */

	var batchBox = $( '#asp-mi-batch-ids' );

	if ( batchBox ) {
		batchBox.addEventListener( 'input', function () {
			$( '#asp-mi-batch-count' ).textContent = parseIds( batchBox.value ).length + ' 個 ID';
		} );
	}

	/* ══════════════════════════════════════════════
	   熱門排行
	   ══════════════════════════════════════════════ */

	function renderRankRows( items ) {
		var tbody = $( '#asp-mi-rank-tbody' );

		items.forEach( function ( item ) {
			var tr = document.createElement( 'tr' );

			var tdChk = document.createElement( 'td' );
			tdChk.className = 'check-column';

			var chk = document.createElement( 'input' );
			chk.type    = 'checkbox';
			chk.className = 'asp-mi-rank-check';
			chk.value   = item.anilist_id;
			chk.checked = ! item.imported;   // 已匯入的預設不勾
			chk.dataset.imported = item.imported ? '1' : '0';
			tdChk.appendChild( chk );

			function td( text ) {
				var c = document.createElement( 'td' );
				c.textContent = String( text === null || text === undefined ? '' : text );
				return c;
			}

			var title = item.title_native || item.title_romaji || '';

			if ( item.title_native && item.title_romaji && item.title_native !== item.title_romaji ) {
				title = item.title_native + '（' + item.title_romaji + '）';
			}

			var tdStatus = document.createElement( 'td' );
			tdStatus.innerHTML = item.imported
				? '<span style="color:#00a32a">✓ 已匯入</span>'
				: '<span style="color:#8c8f94">未匯入</span>';

			tr.appendChild( tdChk );
			tr.appendChild( td( item.anilist_id ) );
			tr.appendChild( td( title ) );
			tr.appendChild( td( item.format || '-' ) );
			tr.appendChild( td( item.volumes || '—' ) );
			tr.appendChild( td( item.popularity || 0 ) );
			tr.appendChild( tdStatus );

			tbody.appendChild( tr );
		} );
	}

	function loadRanking() {
		var status = $( '#asp-mi-rank-status' );

		rankPage += 1;
		status.textContent = '載入第 ' + rankPage + ' 頁…';

		post( 'anime_sync_manga_popularity', { page: rankPage } )
			.then( function ( res ) {
				if ( ! res.success ) {
					status.textContent = '載入失敗：' + ( ( res.data && res.data.message ) || '未知錯誤' );
					rankPage -= 1;
					return;
				}

				var data  = res.data || {};
				var items = data.items || [];

				renderRankRows( items );

				$( '#asp-mi-rank-wrap' ).style.display = '';

				var total = $$( '.asp-mi-rank-check' ).length;
				var isNew = $$( '.asp-mi-rank-check' ).filter( function ( c ) {
					return c.dataset.imported === '0';
				} ).length;

				status.textContent = '已載入 ' + total + ' 部（未匯入 ' + isNew + ' 部）';

				var hasNext = data.page_info && data.page_info.hasNextPage;
				$( '#asp-mi-rank-more' ).style.display = hasNext ? '' : 'none';
			} )
			.catch( function () {
				status.textContent = '網路錯誤';
				rankPage -= 1;
			} );
	}

	if ( $( '#asp-mi-rank-load' ) ) {
		$( '#asp-mi-rank-load' ).addEventListener( 'click', function () {
			this.style.display = 'none';
			loadRanking();
		} );
	}

	if ( $( '#asp-mi-rank-more' ) ) {
		$( '#asp-mi-rank-more' ).addEventListener( 'click', loadRanking );
	}

	if ( $( '#asp-mi-rank-all' ) ) {
		$( '#asp-mi-rank-all' ).addEventListener( 'change', function () {
			var on = this.checked;

			$$( '.asp-mi-rank-check' ).forEach( function ( c ) {
				// 已匯入的不隨全選變動，避免不小心整批重抓
				if ( c.dataset.imported === '1' ) { return; }
				c.checked = on;
			} );
		} );
	}

	/* ══════════════════════════════════════════════
	   從動畫原作
	   ══════════════════════════════════════════════ */

	function renderFromAnime( items ) {
		var tbody = $( '#asp-mi-fa-tbody' );
		tbody.innerHTML = '';

		items.forEach( function ( item, idx ) {
			var tr = document.createElement( 'tr' );
			tr.dataset.rank = idx;   // 供「限前 N 部」使用

			var tdChk = document.createElement( 'td' );
			tdChk.className = 'check-column';

			var chk = document.createElement( 'input' );
			chk.type      = 'checkbox';
			chk.className = 'asp-mi-fa-check';
			chk.value     = item.anilist_id;
			chk.checked   = false;   // 數量大，預設不勾，由「限前 N 部」帶
			chk.dataset.imported = item.imported ? '1' : '0';
			chk.disabled  = !! item.imported;
			tdChk.appendChild( chk );

			function td( text ) {
				var c = document.createElement( 'td' );
				c.textContent = String( text === null || text === undefined ? '' : text );
				return c;
			}

			var tdAnime = document.createElement( 'td' );
			var a = document.createElement( 'a' );
			a.href = item.anime_url || '#';
			a.target = '_blank';
			a.textContent = item.anime_title || '';
			tdAnime.appendChild( a );

			if ( item.anime_count > 1 ) {
				var note = document.createElement( 'span' );
				note.className = 'description';
				note.style.marginLeft = '6px';
				note.textContent = '等 ' + item.anime_count + ' 部';
				tdAnime.appendChild( note );
			}

			var tdStatus = document.createElement( 'td' );
			tdStatus.innerHTML = item.imported
				? '<span style="color:#00a32a">✓ 已匯入</span>'
				: '<span style="color:#8c8f94">未匯入</span>';

			tr.appendChild( tdChk );
			tr.appendChild( td( item.anilist_id ) );
			tr.appendChild( td( item.title || '(無標題)' ) );
			tr.appendChild( tdAnime );
			tr.appendChild( td( item.popularity || 0 ) );
			tr.appendChild( tdStatus );

			tbody.appendChild( tr );
		} );
	}

	if ( $( '#asp-mi-fa-load' ) ) {
		$( '#asp-mi-fa-load' ).addEventListener( 'click', function () {
			var status = $( '#asp-mi-fa-status' );

			this.disabled = true;
			status.textContent = '掃描中…';

			post( 'anime_sync_manga_from_anime', {} )
				.then( function ( res ) {
					$( '#asp-mi-fa-load' ).disabled = false;

					if ( ! res.success ) {
						status.textContent = '失敗：' + ( ( res.data && res.data.message ) || '未知錯誤' );
						return;
					}

					var d = res.data || {};

					renderFromAnime( d.items || [] );
					$( '#asp-mi-fa-wrap' ).style.display = '';
					status.textContent = '共 ' + d.total + ' 部原作漫畫，未匯入 ' + d.todo + ' 部';
				} )
				.catch( function () {
					$( '#asp-mi-fa-load' ).disabled = false;
					status.textContent = '網路錯誤';
				} );
		} );
	}

	/** 勾選排序在前 N 名、且尚未匯入的項目。 */
	function applyFaLimit() {
		var n = parseInt( $( '#asp-mi-fa-limit' ).value, 10 ) || 0;
		var picked = 0;

		$$( '#asp-mi-fa-tbody tr' ).forEach( function ( tr ) {
			var chk = $( '.asp-mi-fa-check', tr );

			if ( ! chk || chk.disabled ) { return; }

			chk.checked = ( picked < n );

			if ( chk.checked ) { picked++; }
		} );

		$( '#asp-mi-fa-status' ).textContent = '已勾選 ' + picked + ' 部';
	}

	if ( $( '#asp-mi-fa-applylimit' ) ) {
		$( '#asp-mi-fa-applylimit' ).addEventListener( 'click', applyFaLimit );
	}

	if ( $( '#asp-mi-fa-all' ) ) {
		$( '#asp-mi-fa-all' ).addEventListener( 'change', function () {
			var on = this.checked;

			$$( '.asp-mi-fa-check' ).forEach( function ( c ) {
				if ( c.disabled ) { return; }
				c.checked = on;
			} );
		} );
	}

	/* ══════════════════════════════════════════════
	   批次佇列
	   ══════════════════════════════════════════════ */

	function collectIds( source ) {
		if ( source === 'batch' ) {
			return parseIds( batchBox ? batchBox.value : '' );
		}

		var sel = ( source === 'fromanime' ) ? '.asp-mi-fa-check' : '.asp-mi-rank-check';

		return $$( sel )
			.filter( function ( c ) { return c.checked; } )
			.map( function ( c ) { return parseInt( c.value, 10 ); } )
			.filter( function ( n ) { return n > 0; } );
	}

	function runQueue( ids, force ) {
		if ( running ) { return; }

		running = true;
		stopped = false;

		$( '#asp-mi-progress-wrap' ).style.display = '';
		$( '#asp-mi-log' ).innerHTML = '';
		$$( '.asp-mi-run' ).forEach( function ( b ) { b.disabled = true; } );
		$$( '.asp-mi-stop' ).forEach( function ( b ) { b.style.display = ''; b.disabled = false; } );

		var done = 0, ok = 0, skip = 0, fail = 0;

		setProgress( 0, ids.length );
		log( '開始匯入 ' + ids.length + ' 部' + ( force ? '（強制覆蓋）' : '' ), 'info' );

		function next() {
			if ( stopped ) {
				log( '已停止（完成 ' + done + ' / ' + ids.length + '）', 'warn' );
				finish();
				return;
			}

			if ( done >= ids.length ) {
				log( '全部完成 —— 成功 ' + ok + '、略過 ' + skip + '、失敗 ' + fail, 'ok' );
				finish();
				return;
			}

			var id      = ids[ done ];
			var payload = { anilist_id: id };

			if ( force ) { payload.force = 1; }

			post( 'anime_sync_manga_import_single', payload )
				.then( function ( res ) {
					if ( res.success ) {
						var d = res.data || {};

						if ( d.skipped ) {
							skip++;
							log( '#' + id + ' 略過：' + ( d.message || '已存在' ), 'warn' );
						} else {
							ok++;
							log( '#' + id + ' ✓ ' + ( d.message || '成功' ), 'ok' );
						}
					} else {
						fail++;
						log( '#' + id + ' ✗ ' + ( ( res.data && res.data.message ) || '失敗' ), 'err' );
					}
				} )
				.catch( function () {
					fail++;
					log( '#' + id + ' ✗ 網路錯誤', 'err' );
				} )
				.then( function () {
					done++;
					setProgress( done, ids.length );
					next();
				} );
		}

		function finish() {
			running = false;
			$$( '.asp-mi-run' ).forEach( function ( b ) { b.disabled = false; } );
			$$( '.asp-mi-stop' ).forEach( function ( b ) { b.style.display = 'none'; } );
		}

		next();
	}

	$$( '.asp-mi-run' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var ids = collectIds( btn.dataset.source );

			if ( ! ids.length ) {
				alert( '沒有可匯入的 ID' );
				return;
			}

			// 找同一個分頁裡的強制覆蓋選項
			var panel = btn.closest( '.asp-mi-panel' );
			var force = panel ? $( '.asp-mi-force', panel ) : null;

			if ( ids.length > 50 &&
				! confirm( '共 ' + ids.length + ' 部，預估需要 ' +
					Math.ceil( ids.length * 8 / 60 ) + ' 分鐘。期間請保持此分頁開啟。要開始嗎？' ) ) {
				return;
			}

			runQueue( ids, force && force.checked );
		} );
	} );

	$$( '.asp-mi-stop' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			stopped = true;
			btn.disabled = true;
			btn.textContent = '停止中…';
		} );
	} );
}() );
