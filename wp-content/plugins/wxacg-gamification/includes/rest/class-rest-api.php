<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

class Rest_Api {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route( 'smacg/v1', '/user-level', [
            'methods'             => 'GET',
            'permission_callback' => 'is_user_logged_in',
            'callback'            => function () {
                if ( ! function_exists( 'wxacg_get_user_level' ) ) {
                    return new \WP_Error( 'no_level_fn', 'Level system not loaded', [ 'status' => 503 ] );
                }
                return rest_ensure_response( wxacg_get_user_level( get_current_user_id() ) );
            },
        ] );
    }
}
