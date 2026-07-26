<?php
/**
 * Plugin Name: WXACG Catalog API
 * Description: 為 Anime Sync Pro 動畫與漫畫資料提供唯讀 Catalog REST API。
 * Version: 1.1.0-beta1
 * Author: weixiaoacg
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: wxacg-catalog-api
 */

defined( 'ABSPATH' ) || exit;

define( 'WXACG_CATALOG_API_VERSION', '1.1.0-beta1' );
define( 'WXACG_CATALOG_API_FILE', __FILE__ );
define( 'WXACG_CATALOG_API_DIR', plugin_dir_path( __FILE__ ) );
define( 'WXACG_CATALOG_API_URL', plugin_dir_url( __FILE__ ) );
define(
	'WXACG_CATALOG_API_BASENAME',
	plugin_basename( __FILE__ )
);

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Wxacg_Catalog_Api_';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$name = substr( $class, strlen( $prefix ) );

		if ( false === $name || '' === $name ) {
			return;
		}

		if ( 0 === strpos( $name, 'V2_' ) ) {
			$name = substr( $name, 3 );
			$dir  = WXACG_CATALOG_API_DIR . 'v2/';
		} else {
			$dir = WXACG_CATALOG_API_DIR . 'includes/';
		}

		$name = strtolower( str_replace( '_', '-', $name ) );
		$file = $dir . 'class-' . $name . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$installed = (string) get_option(
			'wxacg_catalog_api_version',
			''
		);

		if ( WXACG_CATALOG_API_VERSION !== $installed ) {
			if ( class_exists( 'Wxacg_Catalog_Api_Cache' ) ) {
				Wxacg_Catalog_Api_Cache::bump_version();
			}

			update_option(
				'wxacg_catalog_api_version',
				WXACG_CATALOG_API_VERSION,
				false
			);
		}

		Wxacg_Catalog_Api_Plugin::instance()->init();
	},
	20
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if (
			false === get_option(
				'wxacg_catalog_cache_version',
				false
			)
		) {
			add_option(
				'wxacg_catalog_cache_version',
				1,
				'',
				false
			);
		}

		update_option(
			'wxacg_catalog_api_version',
			WXACG_CATALOG_API_VERSION,
			false
		);

		update_option(
			'wxacg_catalog_api_activated_at',
			current_time( 'mysql' ),
			false
		);
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( 'Wxacg_Catalog_Api_Cache' ) ) {
			Wxacg_Catalog_Api_Cache::bump_version();
		}
	}
);
