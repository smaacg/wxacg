<?php
/**
 * 檔案名稱: includes/class-entity-favorites.php
 * 角色／聲優收藏
 *
 * ★ 為什麼另立一張表
 *   角色與聲優不是 WordPress 文章，而是 anime_characters（25,770 列）與
 *   anime_persons（10,410 列）的資料列，沒有 post_id 可以掛 postmeta。
 *
 *   也不能借用 anime_user_status 的 favorited 欄位——那張表是「追番清單」，
 *   會被 anime_user_status_stats、消息通知的收件人查詢、個人片單頁一起讀，
 *   混入實體收藏會讓「追番中 N 人」這類數字失真。
 *
 * ★ entity_id 存 bgm_id 而不是資料表的自增 id
 *   bgm_id 是兩張實體表的唯一鍵，也是網址 /character/{id} 用的值。
 *   實體表若因重新匯入而重建，自增 id 會變，bgm_id 不會——收藏才不會對錯人。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Entity_Favorites {

	const REST_NS = 'weixiaoacg/v1';

	/** 允許的實體類型 → 對應的資料表（不含前綴）。 */
	private static array $TYPES = [
		'character' => 'anime_characters',
		'person'    => 'anime_persons',
	];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wxacg_entity_favorites';
	}

	public static function is_valid_type( string $type ): bool {
		return isset( self::$TYPES[ $type ] );
	}

	// =========================================================================
	// 讀寫
	// =========================================================================

	/**
	 * 使用者是否已收藏。
	 */
	public static function is_favorited( int $user_id, string $type, int $entity_id ): bool {
		global $wpdb;

		if ( ! $user_id || ! self::is_valid_type( $type ) || ! $entity_id ) {
			return false;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . '
			  WHERE user_id = %d AND entity_type = %s AND entity_id = %d',
			$user_id,
			$type,
			$entity_id
		) );
	}

	/**
	 * 切換收藏狀態。
	 *
	 * @return array{favorited:bool,count:int}|WP_Error
	 */
	public static function toggle( int $user_id, string $type, int $entity_id ) {
		global $wpdb;

		if ( ! $user_id ) {
			return new WP_Error( 'not_logged_in', '請先登入', [ 'status' => 401 ] );
		}

		if ( ! self::is_valid_type( $type ) || ! $entity_id ) {
			return new WP_Error( 'invalid_entity', '參數不正確', [ 'status' => 400 ] );
		}

		// 確認實體真的存在，避免收藏到不存在的 ID
		if ( ! self::entity_exists( $type, $entity_id ) ) {
			return new WP_Error( 'entity_not_found', '找不到這個項目', [ 'status' => 404 ] );
		}

		$table = self::table();

		if ( self::is_favorited( $user_id, $type, $entity_id ) ) {
			$wpdb->delete(
				$table,
				[ 'user_id' => $user_id, 'entity_type' => $type, 'entity_id' => $entity_id ],
				[ '%d', '%s', '%d' ]
			);

			$now = false;
		} else {
			/*
			 * UNIQUE(user_id, entity_type, entity_id) 會擋掉重複。
			 * 連點兩下造成的競態由資料庫處理，不靠程式先查再寫。
			 */
			$wpdb->insert(
				$table,
				[
					'user_id'     => $user_id,
					'entity_type' => $type,
					'entity_id'   => $entity_id,
					'created_at'  => current_time( 'mysql' ),
				],
				[ '%d', '%s', '%d', '%s' ]
			);

			$now = true;

			/*
			 * 供 wxacg-gamification 掛「初次收藏角色／初次收藏聲優」的成就與經驗值。
			 * 只在「新增收藏」時觸發，取消收藏不發——否則反覆點就能刷。
			 *
			 * 是否為首次由監聽端自行判斷（它本來就有 first-action 的機制），
			 * 這裡只負責如實回報「這個人剛收藏了這個實體」。
			 */
			do_action( 'wxacg_entity_favorited', $user_id, $type, $entity_id );
		}

		return [
			'favorited' => $now,
			'count'     => self::count_for_entity( $type, $entity_id ),
		];
	}

	/**
	 * 某個角色／聲優被幾個人收藏。
	 */
	public static function count_for_entity( string $type, int $entity_id ): int {
		global $wpdb;

		if ( ! self::is_valid_type( $type ) || ! $entity_id ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . '
			  WHERE entity_type = %s AND entity_id = %d',
			$type,
			$entity_id
		) );
	}

	/**
	 * 使用者收藏的清單，附帶實體資料供直接渲染。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_user_favorites( int $user_id, string $type, int $limit = 200 ): array {
		global $wpdb;

		if ( ! $user_id || ! self::is_valid_type( $type ) ) {
			return [];
		}

		$entity_table = $wpdb->prefix . self::$TYPES[ $type ];

		/*
		 * 只有 anime_characters 有 name_cn，anime_persons 沒有這個欄位，
		 * 一起查會 SQL 錯誤——兩張表的結構本來就不同。
		 */
		$name_cn = ( 'character' === $type ) ? 'e.name_cn' : "'' AS name_cn";

		/*
		 * 直接 JOIN 實體表取名字與圖片，避免呼叫端逐筆再查一次。
		 * 實體被刪除時 INNER JOIN 會自然過濾掉，不會出現空白卡片。
		 */
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.entity_id, f.created_at,
			        e.name, {$name_cn}, e.image
			   FROM " . self::table() . " f
			   INNER JOIN {$entity_table} e ON e.bgm_id = f.entity_id
			  WHERE f.user_id = %d AND f.entity_type = %s
			  ORDER BY f.created_at DESC
			  LIMIT %d",
			$user_id,
			$type,
			max( 1, min( 500, $limit ) )
		), ARRAY_A );

		return (array) $rows;
	}

	/**
	 * 使用者各類型的收藏數。
	 *
	 * @return array<string,int>
	 */
	public static function count_by_type( int $user_id ): array {
		global $wpdb;

		$out = array_fill_keys( array_keys( self::$TYPES ), 0 );

		if ( ! $user_id ) {
			return $out;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT entity_type, COUNT(*) AS c FROM ' . self::table() . '
			  WHERE user_id = %d GROUP BY entity_type',
			$user_id
		) );

		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r->entity_type ] ) ) {
				$out[ $r->entity_type ] = (int) $r->c;
			}
		}

		return $out;
	}

	/**
	 * 實體是否存在（以 bgm_id 查）。
	 */
	private static function entity_exists( string $type, int $entity_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::$TYPES[ $type ];

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE bgm_id = %d",
			$entity_id
		) );
	}

	// =========================================================================
	// REST
	// =========================================================================

	public function register_routes(): void {
		register_rest_route( self::REST_NS, '/entity-favorite', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'api_toggle' ],
			'permission_callback' => static function () {
				return is_user_logged_in();
			},
			'args'                => [
				'type' => [
					'required'          => true,
					'validate_callback' => static function ( $v ) {
						return Anime_Sync_Entity_Favorites::is_valid_type( (string) $v );
					},
				],
				'id'   => [
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public function api_toggle( WP_REST_Request $req ) {
		$result = self::toggle(
			get_current_user_id(),
			(string) $req->get_param( 'type' ),
			(int) $req->get_param( 'id' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'success' => true ] + $result );
	}
}
