<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Milestone_Badge — 累積型里程碑徽章
 *
 * 與 First_Badge 的差別：那是「第一次做某事」（一次性事件），
 * 這是「累積做到 N 次」（需要即時計數）。兩者共用同一套底層機制：
 * 透過 smacg_exp_rules filter 掛入 once-cap 規則，沿用
 * Exp_Events::award_with_cap() 的原子鎖與升級偵測，達標時才發徽章。
 *
 * ★ 為什麼要做累積型
 *   站上原本 13 個徽章全是「初次 XXX」，使用者做完一輪就沒有目標了。
 *   而成就頁會把**未解鎖**的徽章一併顯示，所以徽章的作用不只是獎勵
 *   已完成的行為，更是「告訴使用者可以做什麼」——2026-08-22 的漏斗
 *   顯示 78 人加過片單、只有 4 人寫過評論，那些人需要的正是一個
 *   看得見的目標。
 *
 * ★ 門檻為什麼設 3/10/30/100
 *   依站上實際分布決定，不照抄常見的 10/50/100：當時全站最高是評分
 *   53 次、評論 12 則、看完 10 部，若最低階就設 10，多數人連第一階
 *   都搆不到，等於沒有回饋。3 是刻意設低的入門階。
 *
 * ★ 計數採即時查詢而非累加計數器
 *   資料本來就在 anime_user_status / anime_ratings / wxacg_review，
 *   另外維護一份計數器會有不同步的風險（例如使用者刪評論）。
 *   只在使用者實際觸發動作時查一次，不影響一般瀏覽。
 *
 * @since 1.0.0 (2026-08-22)
 */
class Milestone_Badge {

	private static $instance = null;

	/** 各類別的門檻階梯 */
	const TIERS        = [ 3, 10, 30, 100 ];
	/** 連續登入是天數，另有階梯 */
	const STREAK_TIERS = [ 7, 30, 100, 365 ];

	/**
	 * 類別定義。
	 * label_tpl 的 %d 會替換成門檻數字。
	 * exp 依階層遞增，與 First_Badge 一樣會再自動疊加 badge_unlock 的 20 EXP。
	 */
	private static $types = [
		'watch' => [
			'slug_base' => 'badge-watch',
			'label_tpl' => '看完 %d 部作品',
			'desc_tpl'  => '累積看完 %d 部作品。',
		],
		'review' => [
			'slug_base' => 'badge-review',
			'label_tpl' => '發表 %d 則評論',
			'desc_tpl'  => '累積發表 %d 則評論或吐槽。',
		],
		'rating' => [
			'slug_base' => 'badge-rating',
			'label_tpl' => '評分 %d 部作品',
			'desc_tpl'  => '累積為 %d 部作品評分。',
		],
		'favorite' => [
			'slug_base' => 'badge-favorite',
			'label_tpl' => '收藏 %d 部作品',
			'desc_tpl'  => '累積收藏 %d 部作品。',
		],
		'streak' => [
			'slug_base' => 'badge-streak',
			'label_tpl' => '連續登入 %d 天',
			'desc_tpl'  => '連續登入 %d 天。',
			'tiers'     => self::STREAK_TIERS,
		],
	];

	/** 各階對應的 EXP（與 TIERS / STREAK_TIERS 同索引） */
	const TIER_EXP = [ 30, 80, 200, 500 ];

	private static $badge_id_cache = [];

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'smacg_exp_rules', [ __CLASS__, 'register_exp_rules' ] );
		$this->register_hooks();
	}

	/* =========================================================
	 * 規則與定義
	 * ======================================================= */

	/** 取得某類別的階梯（streak 用天數階梯，其餘用次數階梯） */
	public static function tiers_for( $type ) {
		return self::$types[ $type ]['tiers'] ?? self::TIERS;
	}

	public static function get_types() {
		return self::$types;
	}

	/** action_key 命名：milestone_{type}_{target}，例如 milestone_review_10 */
	public static function action_key( $type, $target ) {
		return 'milestone_' . $type . '_' . $target;
	}

	public static function badge_slug( $type, $target ) {
		return self::$types[ $type ]['slug_base'] . '-' . $target;
	}

	/**
	 * 掛入 EXP 規則。
	 * 比照 First_Badge：once cap、season_score 0（一次性里程碑不計賽季排行，
	 * 避免與賽季積分的刷分防護衝突）。
	 */
	public static function register_exp_rules( $rules ) {
		foreach ( self::$types as $type => $conf ) {
			$tiers = self::tiers_for( $type );
			foreach ( $tiers as $i => $target ) {
				$key = self::action_key( $type, $target );
				$rules[ $key ] = [
					'exp'          => (int) ( self::TIER_EXP[ $i ] ?? 30 ),
					'season_score' => 0,
					'cap_type'     => 'once',
					'cap_key'      => $key,
				];
			}
		}
		return $rules;
	}

	/* =========================================================
	 * 觸發
	 * ======================================================= */

	private function register_hooks() {
		// 看完作品
		add_action( 'smacg_watchlist_completed', function ( $uid ) {
			self::check( 'watch', $uid );
		}, 30, 1 );

		// 發表評論（自建評論系統）
		add_action( 'wxacg_review_submitted', function ( $uid ) {
			self::check( 'review', $uid );
		}, 30, 1 );

		// 評分
		add_action( 'smacg_rating_added', function ( $uid ) {
			self::check( 'rating', $uid );
		}, 30, 1 );

		// 收藏
		add_action( 'smacg_favorite_added', function ( $uid ) {
			self::check( 'favorite', $uid );
		}, 30, 1 );

		// 連續登入：streak 在 on_login 更新完才檢查，優先序排在其後
		add_action( 'wp_login', function ( $login, $user ) {
			if ( $user instanceof \WP_User ) {
				self::check( 'streak', $user->ID );
			}
		}, 30, 2 );
	}

	/**
	 * 檢查某類別目前的累積數，補發所有已達標但尚未發過的階層。
	 *
	 * 一次檢查全部階層而非只檢查當前這階：既有使用者第一次觸發時
	 * 可能已經遠超過低階門檻（例如已評分 53 次），要讓他一次拿齊。
	 */
	public static function check( $type, $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || ! isset( self::$types[ $type ] ) ) return;

		$tiers = self::tiers_for( $type );

		// 已經拿到最高階就不必再查計數（省下一次 COUNT 查詢）
		$top_key = self::action_key( $type, end( $tiers ) );
		if ( get_user_meta( $uid, 'smacg_exp_once_' . $top_key, true ) ) return;

		$count = self::count_for( $type, $uid );
		if ( $count <= 0 ) return;

		foreach ( $tiers as $target ) {
			if ( $count < $target ) break;   // 階梯遞增，未達標即可停
			self::award( $type, $target, $uid );
		}
	}

	/**
	 * 目前累積數。
	 *
	 * 直接查來源資料表，不另外維護計數器——使用者刪除評論或取消收藏時
	 * 計數器會不同步，而這些資料本來就查得到。
	 */
	public static function count_for( $type, $uid ) {
		global $wpdb;
		$uid = (int) $uid;

		switch ( $type ) {
			case 'watch':
				return (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}anime_user_status
					 WHERE user_id = %d AND status = 2",
					$uid
				) );

			case 'review':
				return (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts}
					 WHERE post_type = 'wxacg_review'
					   AND post_status = 'publish'
					   AND post_author = %d",
					$uid
				) );

			case 'rating':
				return (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}anime_ratings WHERE user_id = %d",
					$uid
				) );

			case 'favorite':
				return (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}anime_user_status
					 WHERE user_id = %d AND favorited = 1",
					$uid
				) );

			case 'streak':
				return (int) get_user_meta( $uid, 'smacg_login_streak', true );
		}

		return 0;
	}

	private static function award( $type, $target, $uid ) {
		$key = self::action_key( $type, $target );

		// once-cap 原子鎖：非第一次會回傳 false，不會重複發 EXP 與徽章
		if ( ! Exp_Events::award_with_cap( $uid, $key ) ) return;

		$badge_id = self::get_badge_post_id( self::badge_slug( $type, $target ) );
		if ( $badge_id > 0 ) {
			Gamipress_Bridge::award_badge( $uid, $badge_id );
		}
	}

	private static function get_badge_post_id( $slug ) {
		if ( isset( self::$badge_id_cache[ $slug ] ) ) {
			return self::$badge_id_cache[ $slug ];
		}
		$post = get_page_by_path( $slug, OBJECT, WXACG_BADGE_SLUG );
		$id   = $post ? (int) $post->ID : 0;
		self::$badge_id_cache[ $slug ] = $id;
		return $id;
	}
}
