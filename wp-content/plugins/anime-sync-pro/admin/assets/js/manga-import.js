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

	// 批次匯入的重試設定。傳輸失敗（逾時／502）多為暫時性，
	// 退避重試可避免整批只因一次抖動就少掉幾部。
	var MAX_TRY     = 3;
	var RETRY_WAIT  = [ 0, 3000, 8000 ];   // 索引為「已嘗試次數」
	var failedIds   = [];

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
		} ).then( function ( r ) {
			// ★ 先看 HTTP 狀態。主機逾時（502/504）回的是 HTML 錯誤頁，
			//   直接 r.json() 會拋出語意不明的 JSON 解析錯誤，讓所有失敗
			//   都被歸類成「網路錯誤」，查不出真正原因。
			//
			//   狀態碼要帶在錯誤物件上，呼叫端才有辦法判斷這次失敗是
			//   「等一下會好」還是「再試幾次都一樣」。
			if ( ! r.ok ) {
				return r.text().then( function ( t ) {
					var msg = 'HTTP ' + r.status;

					// WordPress 的 wp_send_json_error() 即使帶狀態碼，內容仍是
					// JSON，優先取它的訊息；取不到才退回狀態碼。
					try {
						var j = JSON.parse( t );

						if ( j && j.data && j.data.message ) {
							msg = j.data.message + '（HTTP ' + r.status + '）';
						}
					} catch ( e ) {
						if ( r.status === 502 || r.status === 504 ) {
							msg += '（主機逾時）';
						}
					}

					var err = new Error( msg );
					err.status = r.status;
					throw err;
				} );
			}

			return r.text().then( function ( t ) {
				try {
					return JSON.parse( t );
				} catch ( e ) {
					// 回應不是 JSON：多半是 PHP fatal 或外掛輸出了額外內容。
					// 留下前 200 字，方便判斷問題出在哪，不要吞掉。
					var err = new Error( '回應非 JSON：' + t.slice( 0, 200 ) );
					err.status = 0;
					throw err;
				}
			} );
		} );
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

			// ★ 一律預設不勾。原本是「未匯入就自動勾起來」，載入排行等於
			//   直接選好 49 部；再按一下匯入就送出約 9 分鐘的批次，而使用者
			//   其實沒有真的挑選過任何一部。改為必須明確選取。
			chk.checked = false;
			chk.dataset.imported = item.imported ? '1' : '0';
			chk.addEventListener( 'change', updateRankPicked );
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
				updateRankPicked();

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

	/** 目前勾了幾部——即時顯示，送出前一眼就知道規模。 */
	function updateRankPicked() {
		var el = $( '#asp-mi-rank-picked' );

		if ( ! el ) { return; }

		var n = $$( '.asp-mi-rank-check' ).filter( function ( c ) { return c.checked; } ).length;

		el.textContent = '已勾選 ' + n + ' 部';
		el.style.color = n > 50 ? '#d63638' : '';
	}

	/** 依排行順序勾選前 N 部尚未匯入的。 */
	function applyRankLimit() {
		var n = parseInt( $( '#asp-mi-rank-limit' ).value, 10 ) || 0;
		var picked = 0;

		$$( '.asp-mi-rank-check' ).forEach( function ( c ) {
			if ( c.dataset.imported === '1' ) { c.checked = false; return; }

			c.checked = ( picked < n );

			if ( c.checked ) { picked++; }
		} );

		updateRankPicked();
	}

	if ( $( '#asp-mi-rank-applylimit' ) ) {
		$( '#asp-mi-rank-applylimit' ).addEventListener( 'click', applyRankLimit );
	}

	if ( $( '#asp-mi-rank-all' ) ) {
		$( '#asp-mi-rank-all' ).addEventListener( 'change', function () {
			var on = this.checked;

			$$( '.asp-mi-rank-check' ).forEach( function ( c ) {
				// 已匯入的不隨全選變動，避免不小心整批重抓
				if ( c.dataset.imported === '1' ) { return; }
				c.checked = on;
			} );

			updateRankPicked();
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
			chk.addEventListener( 'change', updateFaPicked );
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

	/** 目前勾了幾部——即時顯示。這個分頁有 700 多個候選，更需要看得到規模。 */
	function updateFaPicked() {
		var el = $( '#asp-mi-fa-picked' );

		if ( ! el ) { return; }

		var n = $$( '.asp-mi-fa-check' ).filter( function ( c ) { return c.checked; } ).length;

		el.textContent = '已勾選 ' + n + ' 部';
		el.style.color = n > 50 ? '#d63638' : '';
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

		updateFaPicked();
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

			updateFaPicked();
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

	/**
	 * 列出最後仍失敗的 ID，並提供一鍵重跑。
	 *
	 * 沒有這一段的話，失敗紀錄只存在於 log 裡，批次一大就等於遺失，
	 * 使用者無從得知「哪幾部沒進來」。
	 */
	function renderFailed( force ) {
		var wrap = $( '#asp-mi-failed' );

		if ( ! wrap ) { return; }

		if ( ! failedIds.length ) {
			wrap.style.display = 'none';
			wrap.innerHTML     = '';
			return;
		}

		var list = failedIds.join( ', ' );

		wrap.style.display = '';
		wrap.innerHTML     = '';

		var title = document.createElement( 'p' );
		title.style.margin  = '0 0 6px';
		title.style.fontWeight = '600';
		title.textContent = '⚠️ 有 ' + failedIds.length + ' 部沒有匯入成功：';

		var box = document.createElement( 'textarea' );
		box.className    = 'large-text code';
		box.rows         = 2;
		box.readOnly     = true;
		box.value        = list;

		var btn = document.createElement( 'button' );
		btn.type      = 'button';
		btn.className = 'button button-primary';
		btn.textContent = '重試這 ' + failedIds.length + ' 部';
		btn.addEventListener( 'click', function () {
			runQueue( failedIds.slice(), force );
		} );

		var tip = document.createElement( 'span' );
		tip.className = 'description';
		tip.style.marginLeft = '8px';
		tip.textContent = '也可以複製上面的 ID，改用「📋 ID 清單」分頁重跑。';

		var row = document.createElement( 'p' );
		row.style.margin = '8px 0 0';
		row.appendChild( btn );
		row.appendChild( tip );

		wrap.appendChild( title );
		wrap.appendChild( box );
		wrap.appendChild( row );
	}

	function runQueue( ids, force ) {
		if ( running ) { return; }

		running = true;
		stopped = false;

		$( '#asp-mi-progress-wrap' ).style.display = '';
		$( '#asp-mi-log' ).innerHTML = '';

		if ( $( '#asp-mi-failed' ) ) {
			$( '#asp-mi-failed' ).style.display = 'none';
			$( '#asp-mi-failed' ).innerHTML     = '';
		}
		$$( '.asp-mi-run' ).forEach( function ( b ) { b.disabled = true; } );
		$$( '.asp-mi-stop' ).forEach( function ( b ) { b.style.display = ''; b.disabled = false; } );

		var done = 0, ok = 0, skip = 0, fail = 0, partial = 0;

		// ★ 失敗的 ID 要留下來。批次跑完後列出並提供一鍵重跑，
		//   否則 log 一長就找不到少了哪幾部，等同「匯入不完整」。
		failedIds = [];

		setProgress( 0, ids.length );
		log( '開始匯入 ' + ids.length + ' 部' + ( force ? '（強制覆蓋）' : '' ), 'info' );

		function next() {
			if ( stopped ) {
				log( '已停止（完成 ' + done + ' / ' + ids.length + '）', 'warn' );
				finish();
				return;
			}

			if ( done >= ids.length ) {
				var sum = '全部完成 —— 成功 ' + ok + '、略過 ' + skip + '、失敗 ' + fail;

				// 「成功但維基資料沒抓到」要單獨講。這種最危險：
				// 看起來成功，實際上資料不完整。
				if ( partial ) { sum += '（其中 ' + partial + ' 部維基資料未取得）'; }

				log( sum, ( fail || partial ) ? 'warn' : 'ok' );
				finish();
				return;
			}

			runOne( ids[ done ], 1 );
		}

		function advance() {
			done++;
			setProgress( done, ids.length );

			// ★ 用 setTimeout 跳出目前的 promise 鏈再進下一部。
			//   若直接呼叫 next()，遞迴會發生在 .then() 內部，後續任何
			//   同步例外都會被同一條 .catch() 接走，被誤判成「這部失敗」
			//   而觸發重試 —— 已經成功匯入的作品會被重跑一次。
			//   順帶避免 promise 鏈無限巢狀。
			setTimeout( next, 0 );
		}

		function runOne( id, tryNo ) {
			// ★ 重試時一律帶 force。傳輸失敗代表「不知道伺服器做到哪」——
			//   文章可能已經建立但維基還沒抓完。不帶 force 的話重試會得到
			//   「已存在，略過」，那部就永遠停在半完成狀態且看起來正常。
			var payload = { anilist_id: id };

			if ( force || tryNo > 1 ) { payload.force = 1; }

			post( 'anime_sync_manga_import_single', payload )
				.then( function ( res ) {
					if ( res.success ) {
						var d = res.data || {};

						if ( d.skipped ) {
							skip++;
							log( '#' + id + ' 略過：' + ( d.message || '已存在' ), 'warn' );
						} else {
							ok++;

							if ( ! wikiOk( d.wiki_status ) ) { partial++; }

							log( '#' + id + ' ✓ ' + ( d.message || '成功' ) + wikiNote( d.wiki_status ),
								wikiOk( d.wiki_status ) ? 'ok' : 'warn' );
						}

						advance();
						return;
					}

					// 伺服器正常回應但判定失敗（例：AniList 查無此 ID）。
					// 這類錯誤重試幾次結果都一樣，直接記下來就好。
					giveUp( id, ( res.data && res.data.message ) || '失敗' );
				} )
				.catch( function ( err ) {
					var code = ( err && err.status ) || 0;
					var msg  = ( err && err.message ) || '網路錯誤';

					// ── 分三類處理 ────────────────────────────────
					// ① 全域性錯誤：nonce 過期(403)、外掛未就緒(500)。
					//    後面每一部都會用同樣的方式失敗，硬跑完只是浪費時間，
					//    直接中止整批並講清楚要怎麼處理。
					if ( code === 403 || code === 500 ) {
						log( '✗ ' + msg, 'err' );
						log( '已中止整批 —— 這個錯誤會讓後面每一部都失敗。' +
							( code === 403 ? '請重新整理頁面後再跑一次。' : '請先確認外掛狀態。' ), 'err' );
						stopped = true;
						giveUp( id, msg );
						return;
					}

					// ② 這次請求本身有問題（參數錯、找不到路由），重試沒有意義。
					if ( code === 400 || code === 404 ) {
						giveUp( id, msg );
						return;
					}

					// ③ 其餘（0 連線失敗、408、429、502、503、504）多為暫時性，值得重試。
					retryOrGiveUp( id, tryNo, msg );
				} );
		}

		// 維基抓取結果：'xxx:ok' 才算完整，其餘（含空字串）都要標示出來。
		function wikiOk( s ) {
			return typeof s === 'string' && s.slice( -3 ) === ':ok';
		}

		function wikiNote( s ) {
			if ( wikiOk( s ) ) { return ''; }

			return s ? '（維基資料未取得：' + s + '）' : '（維基資料未取得）';
		}

		function retryOrGiveUp( id, tryNo, msg ) {
			if ( tryNo >= MAX_TRY ) {
				giveUp( id, msg + '（已重試 ' + MAX_TRY + ' 次）' );
				return;
			}

			var wait = RETRY_WAIT[ tryNo ] || 8000;

			log( '#' + id + ' ⟳ ' + msg + ' —— ' + ( wait / 1000 ) +
				' 秒後重試（第 ' + ( tryNo + 1 ) + ' / ' + MAX_TRY + ' 次）', 'warn' );

			setTimeout( function () {
				if ( stopped ) {
					advance();
					return;
				}

				runOne( id, tryNo + 1 );
			}, wait );
		}

		function giveUp( id, msg ) {
			fail++;
			failedIds.push( id );
			log( '#' + id + ' ✗ ' + msg, 'err' );
			advance();
		}

		function finish() {
			running = false;
			$$( '.asp-mi-run' ).forEach( function ( b ) { b.disabled = false; } );
			$$( '.asp-mi-stop' ).forEach( function ( b ) { b.style.display = 'none'; } );
			renderFailed( force );
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

			// 每部秒數取自實測（2026-08-18，5 部樣本）：
			//   鏈鋸人 9 秒、航海王 39 秒、進擊的巨人 12 秒、鬼滅 12 秒、東京喰種 12 秒
			// 平均 16.8 秒。卷數與關聯越多越慢，而排行前段剛好都是大部頭，
			// 因此不取更低的中位數，寧可估多不要估少。
			// 之後又加了 Bangumi /characters（CAST），限流 1 秒加回應約多 1～2 秒，
			// 故由 15 上調為 18。
			// （最早寫死 8 秒，是維基改為同步抓取之前的數字，已經不適用。）
			var SEC_PER_ITEM = 18;

			if ( ids.length > 20 ) {
				var mins = Math.ceil( ids.length * SEC_PER_ITEM / 60 );
				var est  = mins >= 60
					? Math.floor( mins / 60 ) + ' 小時 ' + ( mins % 60 ) + ' 分鐘'
					: mins + ' 分鐘';

				if ( ! confirm( '共 ' + ids.length + ' 部，預估需要約 ' + est +
					'。\n\n期間請保持此分頁開啟，關閉或電腦休眠會中斷。\n' +
					'已匯入的部分不會損壞，中斷後可以只跑剩下的。\n\n要開始嗎？' ) ) {
					return;
				}
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
