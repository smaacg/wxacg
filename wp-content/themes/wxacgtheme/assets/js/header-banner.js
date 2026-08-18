/**
 * Header 裝飾橫幅 — 滑鼠視差
 * File: assets/js/header-banner.js
 *
 * 只在 header.php 有輸出 #header-banner 時才會有作用；沒有橫幅時
 * 本檔第一行就 return，不留任何監聽器。
 *
 * 設計要點：
 *   - 只用 transform，走合成層，不觸發重排
 *   - mousemove 以 requestAnimationFrame 節流，一幀最多算一次
 *   - 沒有 hover 能力的裝置（觸控）不啟用
 *   - 尊重 prefers-reduced-motion
 *   - 滑鼠離開視窗時緩慢歸位，避免圖卡在偏移狀態
 */
( function () {
	'use strict';

	var banner = document.getElementById( 'header-banner' );

	if ( ! banner ) {
		return;
	}

	var img = banner.querySelector( '.header-banner__img' );

	if ( ! img ) {
		return;
	}

	// 觸控裝置沒有游標，視差無從觸發
	if ( window.matchMedia && ! window.matchMedia( '( hover: hover )' ).matches ) {
		return;
	}

	// 使用者要求減少動態
	if ( window.matchMedia && window.matchMedia( '( prefers-reduced-motion: reduce )' ).matches ) {
		return;
	}

	/* 位移幅度（px）。太大會露出邊界，太小看不出效果。 */
	var MAX_X = 18;
	var MAX_Y = 6;

	var targetX = 0;
	var targetY = 0;
	var ticking = false;

	function apply() {
		ticking = false;
		img.style.transform = 'translate3d(' + targetX.toFixed( 2 ) + 'px, ' +
			targetY.toFixed( 2 ) + 'px, 0)';
	}

	function request() {
		if ( ticking ) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame( apply );
	}

	function onMove( e ) {
		var w = window.innerWidth  || 1;
		var h = window.innerHeight || 1;

		/*
		 * 換算成 -1 ~ 1。用整個視窗而非 header 本身為基準，
		 * 這樣游標在頁面任何位置移動都會帶動橫幅，效果比較連續
		 * （B 站也是這種作法）。
		 */
		var nx = ( e.clientX / w ) * 2 - 1;
		var ny = ( e.clientY / h ) * 2 - 1;

		// 反向位移：游標往右，圖往左，視覺上像是往深處看
		targetX = -nx * MAX_X;
		targetY = -ny * MAX_Y;

		request();
	}

	function onLeave() {
		targetX = 0;
		targetY = 0;
		request();
	}

	document.addEventListener( 'mousemove', onMove, { passive: true } );
	document.addEventListener( 'mouseleave', onLeave );
	window.addEventListener( 'blur', onLeave );
}() );
