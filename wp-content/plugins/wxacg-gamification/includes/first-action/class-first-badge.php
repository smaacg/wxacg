<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * First_Badge — 「第一次動作」徽章 + EXP 獎勵
 *
 * 透過 smacg_exp_rules filter 掛入新的 once-cap EXP 規則，沿用
 * Exp_Events::award_with_cap() 既有的原子鎖（add_user_meta unique
 * insert）、失敗 rollback、升級偵測、賽季積分邏輯，不重造一套。
 * award_with_cap() 回傳 true（代表使用者第一次觸發這個動作）時，
 * 才額外頒發對應的 GamiPress 徽章（badges CPT）。
 *
 * 徽章解鎖後會經由既有的 badge_unlock 規則（class-exp-config.php）
 * 自動再疊加 20 EXP（掛在 gamipress_award_achievement hook）。
 * 下面 $rules 的 exp 數值已經扣掉這 20，讓「規則 exp + 自動疊加 20」
 * 等於使用者實際拿到的總額：
 *   初次登入／追蹤／加入片單／收藏 → 0 + 20 = 20
 *   初次留言／評分                 → 20 + 20 = 40
 *   初次完結作品                   → 40 + 20 = 60
 *   初次發表論壇文章               → 100 + 20 = 120
 *
 * season_score 一律設 0，比照 register / profile_* 既有作法：
 * 一次性里程碑獎勵不計入賽季排行，避免刷分。
 *
 * 徽章貼文（badges CPT）由 scripts/create-first-badges.php 一次性建立，
 * slug 需與下面 $rules 的 badge_slug 完全一致。
 *
 * @since 1.0.0 (2026-08-16)
 */
class First_Badge {

	private static $instance = null;

	private static $rules = [
		'first_login'              => [ 'badge_slug' => 'badge-first-login',             'exp' => 0,   'label' => '初次登入' ],
		'first_follow'              => [ 'badge_slug' => 'badge-first-follow',            'exp' => 0,   'label' => '初次追蹤' ],
		'first_watchlist_add'       => [ 'badge_slug' => 'badge-first-watchlist-add',     'exp' => 0,   'label' => '初次加入片單' ],
		'first_favorite'            => [ 'badge_slug' => 'badge-first-favorite',          'exp' => 0,   'label' => '初次收藏' ],
		'first_comment'             => [ 'badge_slug' => 'badge-first-comment',           'exp' => 20,  'label' => '初次留言' ],
		'first_rating'              => [ 'badge_slug' => 'badge-first-rating',            'exp' => 20,  'label' => '初次評分' ],
		'first_watchlist_complete'  => [ 'badge_slug' => 'badge-first-watchlist-complete', 'exp' => 40,  'label' => '初次完結作品' ],
		'first_forum_post'          => [ 'badge_slug' => 'badge-first-forum-post',        'exp' => 100, 'label' => '初次發表論壇文章' ],
	];

	private static $badge_id_cache = [];

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'smacg_exp_rules', [ __CLASS__, 'register_exp_rules' ] );

		add_action( 'wp_login',                  [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'comment_post',              [ __CLASS__, 'on_comment' ], 10, 2 );
		add_action( 'smacg_user_followed',       [ __CLASS__, 'on_follow' ], 10, 2 );
		add_action( 'smacg_rating_added',        [ __CLASS__, 'on_rating' ], 10, 3 );
		add_action( 'smacg_watchlist_added',     [ __CLASS__, 'on_watchlist_add' ], 10, 2 );
		add_action( 'smacg_favorite_added',      [ __CLASS__, 'on_favorite' ], 10, 2 );
		add_action( 'smacg_watchlist_completed', [ __CLASS__, 'on_watchlist_complete' ], 10, 2 );
		add_action( 'wpforo_after_add_topic',    [ __CLASS__, 'on_forum_topic' ], 10, 2 );
	}

	/**
	 * 把 first_* 規則掛進 Exp_Config 的規則表（不直接改 class-exp-config.php）。
	 */
	public static function register_exp_rules( $rules ) {
		foreach ( self::$rules as $key => $r ) {
			$rules[ $key ] = [
				'exp'          => (int) $r['exp'],
				'season_score' => 0,
				'cap_type'     => 'once',
				'cap_key'      => $key,
			];
		}
		return $rules;
	}

	/* =========================================================
	 * Hook callbacks
	 * ======================================================= */

	public static function on_login( $user_login, $user ) {
		if ( $user instanceof \WP_User ) {
			self::maybe_award( 'first_login', (int) $user->ID );
		}
	}

	public static function on_comment( $comment_id, $approved ) {
		$comment = get_comment( $comment_id );
		if ( $comment && (int) $comment->user_id > 0 ) {
			self::maybe_award( 'first_comment', (int) $comment->user_id );
		}
	}

	public static function on_follow( $follower_id, $following_id ) {
		self::maybe_award( 'first_follow', (int) $follower_id );
	}

	public static function on_rating( $user_id, $anime_id, $score_overall ) {
		self::maybe_award( 'first_rating', (int) $user_id );
	}

	public static function on_watchlist_add( $user_id, $anime_id ) {
		self::maybe_award( 'first_watchlist_add', (int) $user_id );
	}

	public static function on_favorite( $user_id, $anime_id ) {
		self::maybe_award( 'first_favorite', (int) $user_id );
	}

	public static function on_watchlist_complete( $user_id, $anime_id ) {
		self::maybe_award( 'first_watchlist_complete', (int) $user_id );
	}

	public static function on_forum_topic( $topic, $forum ) {
		// wpForo 的 topic 陣列鍵名沒有把握逐字確認，直接用當下請求的
		// 登入者 ID——新主題一定是當前使用者在同一個請求裡發的。
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			self::maybe_award( 'first_forum_post', $uid );
		}
	}

	/* =========================================================
	 * 核心
	 * ======================================================= */

	private static function maybe_award( $action_key, $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || ! isset( self::$rules[ $action_key ] ) ) {
			return;
		}

		// award_with_cap() 內建 once-cap 原子鎖：非第一次會直接回傳 false，
		// 不會重複發 EXP，這裡也就不會重複發徽章。
		$awarded = Exp_Events::award_with_cap( $uid, $action_key );
		if ( ! $awarded ) {
			return;
		}

		$badge_id = self::get_badge_post_id( self::$rules[ $action_key ]['badge_slug'] );
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
