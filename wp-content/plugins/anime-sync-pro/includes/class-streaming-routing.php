<?php
/**
 * Streaming Routing (串流平台總覽與各平台作品頁)
 * Path: wp-content/plugins/anime-sync-pro/includes/class-streaming-routing.php
 * Version: 1.0.0 (2026-09-03)
 *
 * 功能：攔截 /streaming/ 與 /streaming/{platform}/，載入對應模板。
 *      平台清單來自 Anime_Sync_Streaming_Registry，作品來自
 *      anime_tw_streaming / anime_streaming 這兩個 meta。
 *
 * 設計對齊 class-series-index.php 與 class-entity-routing.php：
 *   - 靜態 class + __CLASS__ 註冊
 *   - rewrite 用 'top' 優先
 *   - template_include 先找子主題，再退回外掛內建
 *   - 不在檔尾 init()，由主外掛 plugins_loaded 統一呼叫
 *   - flush 靠主外掛版本號 +1 觸發（anime_sync_flush_rewrite flag）
 *
 * ★ 與 class-entity-routing.php 不同，這裡「不」加 noindex。
 *   人物／角色頁 noindex 是因為內容全來自 Bangumi、站上沒有原創觀點，
 *   三萬多頁聚合內容曾導致 AdSense 退件（見該檔 filter_entity_robots 註解）。
 *   平台頁的性質不同：它是站內資料的整理與比較，數量只有十幾頁，
 *   而且正是台灣使用者會搜尋的「XX 有哪些動畫可以看」。
 *   已實測 /series/（同樣機制的虛擬頁）robots 為 index，故不需額外處理。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Routing {

	const SLUG        = 'streaming';
	const QV_INDEX    = 'asp_streaming_index';
	const QV_PLATFORM = 'asp_streaming_platform';

	/**
	 * 單一平台要有自己的頁面所需的最少作品數。
	 *
	 * 只有幾部作品的平台做成獨立頁就是薄內容，對整站評價有害無益
	 * （人物頁那次的教訓）。不足門檻的平台仍會出現在總覽頁的列表，
	 * 只是不給獨立網址。
	 */
	const MIN_WORKS = 50;

	/** 平台作品數統計的快取 */
	const COUNT_CACHE_KEY = 'asp_streaming_counts_v1';
	const COUNT_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_action( 'init',             [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars',       [ __CLASS__, 'add_query_var' ] );
		add_filter( 'template_include', [ __CLASS__, 'load_template' ] );

		add_filter( 'pre_get_document_title',   [ __CLASS__, 'filter_title' ], 99 );
		add_filter( 'rank_math/frontend/title', [ __CLASS__, 'filter_title' ], 99 );
	}

	public static function add_rewrite(): void {
		// 單一平台要排在總覽之前，否則 /streaming/netflix/ 會被總覽規則吃掉
		add_rewrite_rule(
			'^' . self::SLUG . '/([a-z0-9_\-]+)/?$',
			'index.php?' . self::QV_PLATFORM . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . self::SLUG . '/?$',
			'index.php?' . self::QV_INDEX . '=1',
			'top'
		);
	}

	public static function add_query_var( $vars ) {
		$vars[] = self::QV_INDEX;
		$vars[] = self::QV_PLATFORM;
		return $vars;
	}

	public static function load_template( $template ) {

		if ( (int) get_query_var( self::QV_INDEX ) === 1 ) {
			return self::resolve_template( 'streaming-index.php', $template );
		}

		$key = self::current_platform_key();
		if ( $key !== '' ) {
			return self::resolve_template( 'streaming-platform.php', $template );
		}

		// /streaming/{不存在的平台}/ → 交還給 WordPress 走 404
		if ( get_query_var( self::QV_PLATFORM ) !== '' ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}

		return $template;
	}

	/** 目前請求的平台 key；不是平台頁或該平台不存在時回空字串 */
	public static function current_platform_key(): string {
		$raw = (string) get_query_var( self::QV_PLATFORM );
		if ( $raw === '' || ! class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
			return '';
		}
		return Anime_Sync_Streaming_Registry::get( $raw ) ? $raw : '';
	}

	public static function is_streaming_page(): bool {
		return (int) get_query_var( self::QV_INDEX ) === 1 || self::current_platform_key() !== '';
	}

	/**
	 * 虛擬頁沒有主查詢可以取標題，不覆寫的話會顯示成首頁最新文章的標題
	 * （class-entity-routing.php 踩過這個坑）。
	 */
	public static function filter_title( $title ) {

		if ( ! self::is_streaming_page() ) {
			return $title;
		}

		$sep  = ' - ';
		$site = get_bloginfo( 'name' );
		$key  = self::current_platform_key();

		if ( $key !== '' ) {
			$p     = Anime_Sync_Streaming_Registry::get( $key );
			$label = $p['label'] ?? $key;
			return $label . ' 動畫線上看清單' . $sep . $site;
		}

		return '台灣動畫串流平台一覽' . $sep . $site;
	}

	/**
	 * 各平台的已發布作品數。
	 *
	 * 一次掃完所有 anime_tw_streaming 值再統計，不要對每個平台各跑一次
	 * meta_query——27 個平台就是 27 次全表掃描。
	 */
	public static function get_counts( bool $bypass_cache = false ): array {

		if ( ! $bypass_cache ) {
			$cached = get_transient( self::COUNT_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT mm.meta_value
			   FROM {$wpdb->postmeta} mm
			   JOIN {$wpdb->posts} p ON p.ID = mm.post_id
			  WHERE mm.meta_key = 'anime_tw_streaming'
			    AND p.post_type = 'anime'
			    AND p.post_status = 'publish'"
		);

		$counts = [];
		foreach ( (array) $rows as $raw ) {
			$keys = maybe_unserialize( $raw );
			if ( ! is_array( $keys ) ) {
				continue;
			}
			foreach ( $keys as $k ) {
				$k = trim( (string) $k );
				if ( $k !== '' ) {
					$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $counts );
		set_transient( self::COUNT_CACHE_KEY, $counts, self::COUNT_CACHE_TTL );

		return $counts;
	}

	/** 該平台是否夠格擁有獨立頁面 */
	public static function has_own_page( string $key ): bool {
		$counts = self::get_counts();
		return ( $counts[ $key ] ?? 0 ) >= self::MIN_WORKS;
	}

	public static function platform_url( string $key ): string {
		return home_url( '/' . self::SLUG . '/' . $key . '/' );
	}

	public static function index_url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** 圖示網址；沒有圖示回空字串 */
	public static function icon_url( string $icon ): string {
		if ( $icon === '' ) {
			return '';
		}
		return plugin_dir_url( dirname( __FILE__ ) ) . 'public/assets/img/providers/' . $icon;
	}

	private static function resolve_template( string $file, $fallback ) {
		$child = get_stylesheet_directory() . '/' . $file;
		if ( file_exists( $child ) ) {
			return $child;
		}
		$plugin = plugin_dir_path( __FILE__ ) . '../public/templates/' . $file;
		if ( file_exists( $plugin ) ) {
			return $plugin;
		}
		return $fallback;
	}
}
