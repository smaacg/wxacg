<?php
/**
 * Smacg API — 主類別
 *
 * 負責統籌外掛內所有模組的實例化與初始化。
 *
 * @package WxacgApi
 */

defined( 'ABSPATH' ) || exit;

final class Wxacg_Api_Plugin {

    /**
     * Singleton
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * 已實例化的模組
     *
     * @var array<string, object>
     */
    private $modules = [];

    /**
     * 取得單例
     */
    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * 初始化所有模組
     */
    public function init(): void {

        // 模組 1：REST 路由
        if ( class_exists( 'Wxacg_Api_Rest_Routes' ) ) {
            $this->modules['rest']   = new Wxacg_Api_Rest_Routes();
            $this->modules['rest']->register_hooks();
        }

        // 模組 2：內容 Slug 處理（Gemini 翻譯）
        if ( class_exists( 'Wxacg_Api_Content_Slug' ) ) {
            $this->modules['slug']   = new Wxacg_Api_Content_Slug();
            $this->modules['slug']->register_hooks();
        }

        // 模組 3：外部連結 target/rel 自動處理
        if ( class_exists( 'Wxacg_Api_External_Links' ) ) {
            $this->modules['links']  = new Wxacg_Api_External_Links();
            $this->modules['links']->register_hooks();
        }
    }

    /**
     * 取得已初始化的模組（debug 用）
     */
    public function get_module( string $key ) {
        return $this->modules[ $key ] ?? null;
    }
}
