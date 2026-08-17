<?php
/**
 * 為 wpDiscuz 富文字編輯器工具列補回「暴雷（Spoiler）」按鈕。
 *
 * wpDiscuz 7 核心已內建暴雷邏輯（wpd-editor.js 的 spoiler handler，
 * 產生 [spoiler title="..."]...[/spoiler] 短碼），但預設沒把觸發按鈕
 * 輸出到工具列。這裡用官方 filter 把按鈕加回去。
 *
 * @package weixiaoacg
 * @since   2.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'wpdiscuz_editor_buttons',
	function ( $editorButtons, $uniqueId ) {
		// 避免重複加入（保險）。
		foreach ( (array) $editorButtons as $btn ) {
			if ( isset( $btn['name'] ) && 'spoiler' === $btn['name'] ) {
				return $editorButtons;
			}
		}

		$editorButtons[] = [
			'class' => 'wpd-editor-btn-spoiler',
			'title' => '暴雷隱藏（先選取要遮住的文字再按）',
			'value' => '',
			'name'  => 'spoiler',
			'svg'   => 'SPOILER',
		];

		return $editorButtons;
	},
	10,
	2
);
