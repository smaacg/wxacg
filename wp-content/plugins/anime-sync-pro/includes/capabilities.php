<?php
/**
 * 後台權限的單一來源。
 *
 * ★ 為什麼需要這個檔案：
 *   「消息審核」（class-events-admin.php）與「短評控管」（ai-editorial-tool.php）
 *   這兩個編輯級工具，原本各自把 manage_options 寫死在 7 個地方——選單註冊、
 *   表單處理、AJAX handler、畫面 render 各有一份。要調整誰能使用就得逐處修改，
 *   漏掉任何一處都是實際的權限漏洞（例如選單放行了但 render 仍擋，或更糟：
 *   選單擋了但 AJAX 沒擋）。改為全部從這裡取得，只有這一個地方需要維護。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wxacg_editorial_tools_cap' ) ) {
	/**
	 * 編輯級後台工具（消息審核、短評控管）所需的權限。
	 *
	 * ★ 為什麼用原生的 edit_others_posts，而不是自訂 capability：
	 *     administrator ✅ / editor ✅ / author ❌ / contributor ❌ / subscriber ❌
	 *   剛好等於「編輯以上」，語意也吻合（能編輯他人內容者才有編審權）。
	 *   自訂 capability 必須寫進 wp_user_roles 這個 option，還得處理停用時的
	 *   清除，改回來也會留殘餘設定；用原生權限則是改回程式碼即完全復原。
	 *
	 * ★ 要改成別的權限時，改這裡一行即可；不想動程式碼則掛
	 *   wxacg_editorial_tools_cap 這個 filter 覆寫。
	 *
	 * @return string 權限名稱。
	 */
	function wxacg_editorial_tools_cap(): string {
		return (string) apply_filters( 'wxacg_editorial_tools_cap', 'edit_others_posts' );
	}
}
