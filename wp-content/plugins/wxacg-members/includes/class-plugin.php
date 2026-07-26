<?php
/**
 * SMACG Members – Plugin Bootstrap
 *
 * @package WXACG\Members
 * @version 1.1.0
 *
 * Changelog:
 *   1.1.0 (2026-05-16) 新增載入 legacy/privacy.php（提供
 *                      smacg_get_user_privacy / smacg_update_user_privacy /
 *                      smacg_mask_email 三支既有但未定義的函式）。
 *   1.0.0 (2026-05-15) 首版：從主題搬遷會員核心模組。
 */

namespace WXACG\Members;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * 取得單例。
	 *
	 * @return Plugin
	 */
	public static function instance() {
    if ( self::$instance === null ) {
        self::$instance = new self();
        self::$instance->boot();
    }
    return self::$instance;
}

	/**
	 * 私有建構子。
	 */
	private function __construct() {}

	/**
	 * 載入 legacy 檔案並更新版本記錄。
	 *
	 * 載入順序（依依賴關係）：
	 *   1. member-functions.php  純工具與常數
	 *   2. privacy.php           隱私 API（v1.1.0 新增）
	 *   3. member-stats.php      統計（可能讀 privacy）
	 *   4. member-render.php     UI 渲染（讀 privacy / stats）
	 *   5. member-ajax.php       AJAX endpoint（讀 privacy / render）
	 *   6. um-integration.php    UM 整合（選擇性）
	 *
	 * @return void
	 */
	public function boot() {
		$dir = WXACG_MEMBERS_DIR . 'includes/legacy/';

		$files = [
			'member-functions.php',
			'member-stats.php',
			'member-render.php',
			'member-ajax.php',
		];

		foreach ( $files as $file ) {
			$path = $dir . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		// UM 整合（選擇性）
		if ( $this->is_um_active() ) {
			$um_path = $dir . 'um-integration.php';
			if ( file_exists( $um_path ) ) {
				require_once $um_path;
			}
		}

		// 版本記錄
		$stored = get_option( 'wxacg_members_version' );
		if ( $stored !== WXACG_MEMBERS_VERSION ) {
			update_option( 'wxacg_members_version', WXACG_MEMBERS_VERSION );
		}
	}

	/**
	 * 偵測 Ultimate Member 外掛是否啟用。
	 *
	 * @return bool
	 */
	private function is_um_active() {
		return class_exists( 'UM' ) || function_exists( 'um_user' );
	}
}

// ✅ [效能優化] 在非 UM 相關頁面移除 FontAwesome CSS
add_action( 'wp_enqueue_scripts', function() {
    // 只在以下情況保留：登入頁、註冊頁、會員中心頁
    $keep = is_page( array( 'login', 'register', 'password-reset', 'member', 'user' ) )
         || ( function_exists( 'um_is_core_page' ) && (
                um_is_core_page( 'login' ) ||
                um_is_core_page( 'register' ) ||
                um_is_core_page( 'user' )
            ));
    if ( ! $keep ) {
        wp_dequeue_style( 'um_fontawesome' );
        wp_deregister_style( 'um_fontawesome' );
        wp_dequeue_style( 'um_fonticons_ii' );
        wp_deregister_style( 'um_fonticons_ii' );
        wp_dequeue_style( 'um_fonticons_fa' );
        wp_deregister_style( 'um_fonticons_fa' );
    }
}, 99 );
