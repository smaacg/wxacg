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
 * 使用者實際拿到的總額 = 下面 $rules 的 exp + 自動疊加 20：
 *   初次登入／追蹤／加入片單／收藏／抽動漫 → 10 + 20 = 30
 *   初次留言／評分                         → 20 + 20 = 40
 *   初次轉職／發表評論                     → 20/40 + 20 = 40/60
 *   初次完結作品                           → 40 + 20 = 60
 *   初次發表論壇文章                       → 100 + 20 = 120
 *
 * ⚠ exp 不可設為 0：Exp_Events::award_with_cap() 要求金額必須 > 0，
 * 0 或負數會直接 return false，連 once 鎖都不會鎖、徽章也不會發
 * （v1.0.0 上線後第一筆「初次收藏」實測就是踩到這個，v1.0.1 修正）。
 *
 * season_score 一律設 0，比照 register / profile_* 既有作法：
 * 一次性里程碑獎勵不計入賽季排行，避免刷分。
 *
 * 徽章貼文（badges CPT）由一次性 wp eval 腳本建立（未落地成檔案，
 * 對話記錄可查），slug 需與下面 $rules 的 badge_slug 完全一致。
 *
 * @since 1.0.0 (2026-08-16)
 * @version 1.0.1 (2026-08-16) — 修正 exp=0 導致 once 鎖完全不觸發的 bug
 * @version 1.1.0 (2026-08-16) — 新增初次抽動漫／初次轉職
 * @version 1.2.0 (2026-08-17) — 新增初次發表評論（wxacg_review_submitted）
 */
class First_Badge {

	private static $instance = null;

	/*
	 * v1.0.1 修正：exp 不能設 0——Exp_Events::award_with_cap() 內建
	 * 「金額必須 > 0」的防呆檢查（金額 0 或負數直接 return false，
	 * 連 once 鎖都不會鎖，等於整個動作沒發生）。原本想靠 badge_unlock
	 * 規則自動疊加的 20 EXP 湊數、自己這邊填 0，結果變成完全不會觸發。
	 * 改成每個動作都給實際的正數 EXP，總到手金額（規則值 + 自動疊加 20）
	 * 不用剛好湊整數，正確運作比數字好看重要。
	 */
	private static $rules = [
		'first_login'              => [ 'badge_slug' => 'badge-first-login',             'exp' => 10,  'label' => '初次登入' ],
		'first_follow'              => [ 'badge_slug' => 'badge-first-follow',            'exp' => 10,  'label' => '初次追蹤' ],
		'first_watchlist_add'       => [ 'badge_slug' => 'badge-first-watchlist-add',     'exp' => 10,  'label' => '初次加入片單' ],
		'first_favorite'            => [ 'badge_slug' => 'badge-first-favorite',          'exp' => 10,  'label' => '初次收藏' ],
		'first_comment'             => [ 'badge_slug' => 'badge-first-comment',           'exp' => 20,  'label' => '初次留言' ],
		'first_rating'              => [ 'badge_slug' => 'badge-first-rating',            'exp' => 20,  'label' => '初次評分' ],
		'first_watchlist_complete'  => [ 'badge_slug' => 'badge-first-watchlist-complete', 'exp' => 40,  'label' => '初次完結作品' ],
		'first_forum_post'          => [ 'badge_slug' => 'badge-first-forum-post',        'exp' => 100, 'label' => '初次發表論壇文章' ],
		'first_dice_roll'           => [ 'badge_slug' => 'badge-first-dice-roll',         'exp' => 10,  'label' => '初次抽動漫' ],
		'first_career_change'       => [ 'badge_slug' => 'badge-first-career-change',     'exp' => 20,  'label' => '初次轉職' ],
		'first_review'              => [ 'badge_slug' => 'badge-first-review',           'exp' => 40,  'label' => '初次發表評論' ],
	];

	private static $badge_id_cache = [];

	public static function instance() {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'smacg_exp_rules', [ __CLASS__, 'register_exp_rules' ] );
		add_filter( 'smacg_exp_reason_labels', [ __CLASS__, 'register_reason_labels' ] );

		add_action( 'wp_login',                  [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'comment_post',              [ __CLASS__, 'on_comment' ], 10, 2 );
		add_action( 'smacg_user_followed',       [ __CLASS__, 'on_follow' ], 10, 2 );
		add_action( 'smacg_rating_added',        [ __CLASS__, 'on_rating' ], 10, 3 );
		add_action( 'smacg_watchlist_added',     [ __CLASS__, 'on_watchlist_add' ], 10, 2 );
		add_action( 'smacg_favorite_added',      [ __CLASS__, 'on_favorite' ], 10, 2 );
		add_action( 'smacg_watchlist_completed', [ __CLASS__, 'on_watchlist_complete' ], 10, 2 );
		add_action( 'wpforo_after_add_topic',    [ __CLASS__, 'on_forum_topic' ], 10, 2 );
		add_action( 'wxacg_random_anime_used',   [ __CLASS__, 'on_dice_roll' ], 10, 1 );
		add_action( 'smacg_career_selected',     [ __CLASS__, 'on_career_change' ], 10, 3 );
		add_action( 'wxacg_review_submitted',    [ __CLASS__, 'on_review_submitted' ], 10, 4 );
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

	/**
	 * 把 first_* 規則的中文 label 掛進 EXP 紀錄的原因說明表，
	 * 否則 Exp_Events::get_reason_label() 找不到對應會 fallback 成
	 * 「EXP: first_xxx」這種原始 action key（點數紀錄頁面看到的 bug）。
	 */
	public static function register_reason_labels( $labels ) {
		foreach ( self::$rules as $key => $r ) {
			$labels[ $key ] = $r['label'];
		}
		return $labels;
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

	public static function on_dice_roll( $uid ) {
		self::maybe_award( 'first_dice_roll', (int) $uid );
	}

	public static function on_career_change( $uid, $job_key, $is_change ) {
		self::maybe_award( 'first_career_change', (int) $uid );
	}

	public static function on_review_submitted( $uid, $review_id, $anime_id, $track ) {
		self::maybe_award( 'first_review', (int) $uid );
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
