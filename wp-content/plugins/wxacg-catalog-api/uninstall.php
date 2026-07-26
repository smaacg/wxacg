<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wxacg_catalog_api_activated_at' );
delete_option( 'wxacg_catalog_cache_version' );

global $wpdb;

$prefix = $wpdb->esc_like( '_transient_wxacg_catalog_' ) . '%';
$timeout_prefix = $wpdb->esc_like(
	'_transient_timeout_wxacg_catalog_'
) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE %s
		   OR option_name LIKE %s",
		$prefix,
		$timeout_prefix
	)
);

/*
 * 不刪除 anime/manga 文章、post meta、taxonomy、評分或收藏資料。
 * 這些資料不屬於 WXACG Catalog API。
 */
