<?php
/**
 * Plugin Name: WXACG 後台介面
 * Description: 後台介面調整：側邊選單依使用頻率重排並分組、「限時挑戰」對非管理員隱藏、控制台加上「營運概況」小工具。停用本外掛即完全復原。
 * Version:     1.1.0
 * Author:      wxacg
 * Requires PHP: 8.0
 *
 * 資料夾名維持 wxacg-admin-menu（改名會讓外掛被視為新外掛而需要重新啟用）。
 *
 * @package WXACG_Admin_Menu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/dashboard-widget.php';

/**
 * 選單順序（單一來源）。
 *
 * 每個子陣列是一組，組與組之間會插入分隔線。要調整順序或新增項目
 * 只改這一個函式即可，其餘邏輯不必動。
 *
 * ★ slug 全部取自正式站實際傾印，不是猜的。要查目前有哪些 slug：
 *   在後台把滑鼠移到選單上看網址列，或看 $menu 全域變數的第 3 個元素。
 *
 * @return array<int, array<int, string>>
 */
function wxacg_admin_menu_groups(): array {
	return array(
		// ① 控制台 + 營運／分析（每天開站先看的）
		array(
			'index.php',                 // 控制台
			'anime-sync-pro',            // 動漫同步
			'googlesitekit-dashboard',   // Site Kit
			'rank-math',                 // Rank Math SEO
		),

		// ② 內容（每天編輯的）
		array(
			'wxacg-ai-news-engine',                    // AI 新聞部
			'edit.php?post_type=anime',                // 動畫
			'edit.php?post_type=manga',                // 漫畫
			/*
			 * 這四個接在漫畫後面。沒列進任何一組的項目會被歸到 $remaining，
			 * 一路掉到「工具」之後——新 CPT 上線時就是這樣跑到選單最下方。
			 */
			'edit.php?post_type=novel',                // 小說
			'edit.php?post_type=game',                 // 遊戲
			'edit.php?post_type=music',                // 音樂
			'edit.php?post_type=liveaction',           // 真人版
			'edit.php',                                // 文章
			'edit.php?post_type=wxacg_correction',     // 修正建議
			'edit.php?post_type=music_chart',          // 音樂榜單
			'upload.php',                              // 媒體
			'edit.php?post_type=page',                 // 頁面
			'edit-comments.php',                       // 留言
			'edit.php?post_type=wxacg_season_event',   // 限時挑戰
		),

		// ③ 其他外掛（偶爾才碰）
		array(
			'wpforo-overview',                      // wpForo
			'ultimatemember',                       // Ultimate Member
			'gamipress_achievements',               // 成就
			'gamipress',                            // GamiPress
			'wps_overview_page',                    // 統計資料
			'wsal-auditlog',                        // WP Activity Log
			'snippets',                             // 程式碼片段
			'edit.php?post_type=acf-field-group',   // ACF
			'cptui_main_menu',                      // CPT UI
			'litespeed',                            // LiteSpeed Cache
			'loco',                                 // Loco Translate
		),

		// ④ 系統
		array(
			'themes.php',            // 外觀
			'plugins.php',           // 外掛
			'options-general.php',   // 設定
			'users.php',             // 使用者
			'tools.php',             // 工具
		),
	);
}

add_filter( 'custom_menu_order', '__return_true' );

/**
 * 依 wxacg_admin_menu_groups() 重排後台選單。
 *
 * ★ 優先權 999 的理由：
 *   Google Site Kit 也掛了 custom_menu_order／menu_order（預設優先權 10），
 *   會把自己插到 index.php 後面。本外掛必須跑在它之後，結果才是最終順序；
 *   兩者不會衝突，只是後者覆蓋前者。
 *
 * ★ 為什麼分隔線要「該組真的有項目才放」：
 *   權限較低的角色（例如編輯看不到整組營運工具）會讓某一組整個變空。
 *   WordPress 清除重複分隔線的程式跑在 menu_order 過濾器「之前」，排完
 *   不會再清一次，固定插三條就會出現兩條黏在一起。
 *
 * @param array<int, string> $menu_order 目前的頂層選單 slug 順序。
 * @return array<int, string>
 */
add_filter(
	'menu_order',
	function ( array $menu_order ): array {

		// WordPress 內建的三條分隔線，依序取用
		$separators = array( 'separator1', 'separator2', 'separator-last' );

		/*
		 * 父選單改名對照。
		 *
		 * ★ 這一步不能省：WordPress 在某些情況會把頂層項目的 slug 改寫成
		 *   別的值，並把對照記在 $_wp_real_parent_file（wp-admin/includes/
		 *   menu.php:122）。實例：編輯看不到「使用者」，該項目會變成
		 *   profile.php。若照原 slug 比對會找不到，整個項目會被當成
		 *   「未列出」掉到清單最後面。
		 *
		 * ★ 只讀不寫。這張表是 WordPress 解析外掛頁面 hookname 的依據
		 *   （get_admin_page_parent → get_plugin_page_hookname → $admin_page_hooks），
		 *   本外掛曾經往裡面寫入自己的改寫，結果讓「動漫同步」底下的子頁對
		 *   編輯全部變成「無存取權限」。詳情見 2026-08-20 的修正紀錄。
		 */
		global $_wp_real_parent_file;
		$parent_map = is_array( $_wp_real_parent_file ) ? $_wp_real_parent_file : array();

		$remaining = $menu_order;   // 尚未安排的項目
		$ordered   = array();
		$sep_index = 0;
		$is_first  = true;

		foreach ( wxacg_admin_menu_groups() as $group ) {

			// 先撈出這一組實際存在（＝目前這個使用者看得到）的項目
			$found = array();
			foreach ( $group as $slug ) {
				$actual = $parent_map[ $slug ] ?? $slug;
				$i      = array_search( $actual, $remaining, true );
				if ( false !== $i ) {
					$found[] = $remaining[ $i ];
					unset( $remaining[ $i ] );
				}
			}

			// 整組都被權限擋掉 → 連分隔線都不放
			if ( ! $found ) {
				continue;
			}

			// 第一組前面不需要分隔線
			if ( ! $is_first && isset( $separators[ $sep_index ] ) ) {
				$sep = $separators[ $sep_index ];
				$j   = array_search( $sep, $remaining, true );
				if ( false !== $j ) {
					$ordered[] = $remaining[ $j ];
					unset( $remaining[ $j ] );
					++$sep_index;
				}
			}

			$ordered  = array_merge( $ordered, $found );
			$is_first = false;
		}

		/*
		 * 沒用到的分隔線要從 $menu 全域直接移除。
		 *
		 * ★ 不能只是「不放進回傳值」：menu_order 這個過濾器的結果是拿去
		 *   usort() 排序 $menu，不在清單裡的項目只會被排到最後面，不會消失。
		 *   若不處理，權限較低的角色（用不到三條分隔線）畫面最底下會多出
		 *   一條沒有意義的橫線。此處是唯一同時滿足「權限已過濾完」且
		 *   「排序尚未執行」的時機，所以在這裡處理。
		 */
		$unused = array_slice( $separators, $sep_index );
		if ( $unused ) {
			global $menu;
			if ( is_array( $menu ) ) {
				foreach ( $menu as $key => $item ) {
					if ( isset( $item[2] ) && in_array( $item[2], $unused, true ) ) {
						unset( $menu[ $key ] );
					}
				}
			}
			$remaining = array_diff( $remaining, $unused );
		}

		// 沒列到的（例如新裝的外掛）維持原相對順序，接在最後面
		return array_merge( $ordered, array_values( $remaining ) );
	},
	999
);

/**
 * 「限時挑戰」對非管理員隱藏。
 *
 * ★ 這只是「隱藏選單」，不是「擋權限」：
 *   該 CPT 用的是 edit_posts，編輯本來就有，知道網址仍然進得去。
 *   若要真正擋住，必須動角色權限（另外處理），不在本外掛範圍。
 *
 * ★ 優先權 999：要等所有外掛都註冊完選單才移除得掉。
 */
add_action(
	'admin_menu',
	function (): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		remove_menu_page( 'edit.php?post_type=wxacg_season_event' );
	},
	999
);
