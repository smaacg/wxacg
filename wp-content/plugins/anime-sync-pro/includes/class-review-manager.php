<?php
/**
 * Review Manager — 動漫「評論」系統
 *
 * 短評（吐槽，1 字起跳，可掛在整部作品或單一集）+ 長評（結構化長文，
 * 80 字起跳，固定掛在整部作品）雙軌設計，比照 Bangumi／巴哈動畫瘋的
 * 單集互動模式。評論本體走 wxacg_review CPT（wp_posts/wp_postmeta），
 * 讚／倒讚走獨立的 wp_wxacg_review_votes 表（需要去重＋計數）。
 *
 * 不重造分數欄位：評論卡片顯示的分數直接引用使用者在
 * Anime_Sync_Rating_Manager（wp_anime_ratings）已經填過的分數。
 *
 * 發表門檻：使用者要在 wp_anime_user_status 有這部作品的紀錄
 * （想看／在看／看過／棄番／擱置皆可，不限定看完）才能發表。
 *
 * @since 1.6.1 (2026-08-17)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Review_Manager {

	const CPT = 'wxacg_review';

	const TRACK_SHORT = 'short';
	const TRACK_LONG  = 'long';

	const MIN_LEN_SHORT = 1;
	const MIN_LEN_LONG  = 80;
	const MAX_LEN_SHORT = 300;
	const MAX_LEN_LONG  = 20000;
	const MAX_LEN_EXCERPT = 60;
	const MIN_LEN_EXCERPT = 20;

	/** 回覆指向的母評論 ID；0 或不存在代表本身是主留言 */
	const META_REPLY_TO = '_wxacg_review_reply_to';

	/**
	 * 追蹤這串討論的使用者 ID。
	 *
	 * 刻意用多筆 meta（一位追蹤者一筆）而不是存成陣列，這樣新增／取消
	 * 都是單筆操作，不會有兩人同時追蹤時互相覆寫的競態問題。
	 */
	const META_FOLLOWER = '_wxacg_review_follower';

	/** 最後編輯時間；有值即代表發表後被改過 */
	const META_EDITED_AT = '_wxacg_review_edited_at';

	/**
	 * 可以留評論的文章類型。
	 *
	 * 資料層本來就是通用的（用 post_parent 指向目標文章），只有驗證寫死了
	 * anime，因此擴展到新聞只需放寬這裡。之後要再加其他類型，改這個清單或
	 * 掛 wxacg_review_post_types 篩選器即可，不必再動驗證邏輯。
	 *
	 * @return string[]
	 */
	public static function allowed_post_types(): array {
		return (array) apply_filters( 'wxacg_review_post_types', [ 'anime', 'post' ] );
	}

	/**
	 * 有人回覆評論時，發站內通知給母評論作者。
	 *
	 * 沿用 wxacg-social 既有的 comment_reply 類型與 data 欄位格式，
	 * 因此使用者原本的「回覆通知」開關可以直接套用到這裡，不必新增設定；
	 * 對方關掉的話 wxacg_create_notification() 內部就會擋掉。
	 * 通知系統本身也會處理「不通知自己」。
	 *
	 * @param int    $review_id 這則回覆的 ID。
	 * @param int    $reply_to  母評論 ID。
	 * @param int    $target_id 目標文章（動漫或新聞）ID。
	 * @param int    $actor_id  發表回覆的人。
	 * @param string $content   回覆內容。
	 */
	private static function notify_reply( int $review_id, int $reply_to, int $target_id, int $actor_id, string $content ): void {
		if ( ! function_exists( 'wxacg_create_notification' ) ) {
			return;
		}

		$parent = get_post( $reply_to );
		if ( ! $parent ) {
			return;
		}

		$parent_uid = (int) $parent->post_author;
		if ( ! $parent_uid || $parent_uid === $actor_id ) {
			return;
		}

		$actor = get_userdata( $actor_id );
		if ( ! $actor ) {
			return;
		}

		$excerpt = wp_strip_all_tags( $content );
		if ( mb_strlen( $excerpt ) > 80 ) {
			$excerpt = mb_substr( $excerpt, 0, 80 ) . '…';
		}

		wxacg_create_notification( [
			'user_id'     => $parent_uid,
			'type'        => 'comment_reply',
			'actor_id'    => $actor_id,
			'object_type' => 'review',
			'object_id'   => $review_id,
			'data'        => [
				'title'      => sprintf( '%s 回覆了你的評論', $actor->display_name ?: $actor->user_login ),
				'excerpt'    => $excerpt,
				// 兩種頁型的評論區容器 id 相同，用它當錨點即可
				'url'        => get_permalink( $target_id ) . '#asd-review-root',
				'icon'       => 'fa-reply',
				'post_title' => get_the_title( $target_id ),
			],
		] );
	}

	/**
	 * 該文章類型可用的評論軌道。
	 *
	 * 長評有標題、80 字下限，是為作品心得設計的；新聞留言用不到，
	 * 因此新聞只開放短評。前端 data-tracks 會宣告同一份清單，這裡是
	 * 後端把關，避免有人繞過介面直接送 track=long。
	 *
	 * @param int $post_id 目標文章 ID。
	 * @return string[]
	 */
	public static function allowed_tracks( int $post_id ): array {
		$tracks = get_post_type( $post_id ) === 'anime'
			? [ self::TRACK_SHORT, self::TRACK_LONG ]
			: [ self::TRACK_SHORT ];

		return (array) apply_filters( 'wxacg_review_tracks', $tracks, $post_id );
	}

	/**
	 * 目標文章是否可以被評論。
	 *
	 * @param int  $post_id          目標文章 ID。
	 * @param bool $require_publish  是否要求已發布（發表時要求，讀取時不要求）。
	 */
	public static function is_reviewable( int $post_id, bool $require_publish = false ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( ! in_array( get_post_type( $post_id ), self::allowed_post_types(), true ) ) {
			return false;
		}

		return ! $require_publish || get_post_status( $post_id ) === 'publish';
	}

	const VOTE_LIKE    = 1;
	const VOTE_DISLIKE = -1;

	const RATE_LIMIT_MAX    = 10;
	const RATE_LIMIT_PERIOD = HOUR_IN_SECONDS;

	// 投票是低成本的單一點擊，門檻給寬一點，但還是要有上限避免灌票
	const VOTE_RATE_LIMIT_MAX    = 60;
	const VOTE_RATE_LIMIT_PERIOD = HOUR_IN_SECONDS;

	public function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/* =========================================================
	 * CPT
	 * ======================================================= */

	public function register_cpt(): void {
		register_post_type( self::CPT, [
			'labels' => [
				'name'          => '評論',
				'singular_name' => '評論',
				'all_items'     => '所有評論',
				'edit_item'     => '編輯評論',
				'search_items'  => '搜尋評論',
				'not_found'     => '找不到評論',
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=anime',
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'supports'            => [ 'title', 'editor', 'author', 'custom-fields' ],
		] );
	}

	/* =========================================================
	 * REST 路由
	 * ======================================================= */

	public function register_routes(): void {
		$ns = 'weixiaoacg/v1';

		register_rest_route( $ns, '/reviews/(?P<anime_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'api_list' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'api_submit' ],
				'permission_callback' => [ $this, 'require_login' ],
			],
		] );

		register_rest_route( $ns, '/reviews/item/(?P<review_id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'api_delete' ],
			'permission_callback' => [ $this, 'require_login' ],
		] );

		register_rest_route( $ns, '/reviews/item/(?P<review_id>\d+)/vote', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'api_vote' ],
			'permission_callback' => [ $this, 'require_login' ],
		] );

		// @提及的使用者搜尋。限登入者使用，避免變成對外的會員名單查詢介面。
		register_rest_route( $ns, '/reviews/mention-search', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'api_mention_search' ],
			'permission_callback' => [ $this, 'require_login' ],
		] );

		register_rest_route( $ns, '/reviews/item/(?P<review_id>\d+)/follow', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'api_follow' ],
			'permission_callback' => [ $this, 'require_login' ],
		] );

		register_rest_route( $ns, '/reviews/item/(?P<review_id>\d+)/edit', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'api_edit' ],
			'permission_callback' => [ $this, 'require_login' ],
		] );
	}

	/**
	 * 編輯自己的評論。
	 *
	 * 不設編輯時限：這是會員制社群，比照 MAL／AniList／Bangumi 的做法讓
	 * 作者隨時能修正錯字。改為以「已編輯」標記維持透明度——原文變動後
	 * 底下的回覆可能對不上，留下痕跡讓讀者有跡可循，比直接鎖定溫和。
	 *
	 * 編輯不重跑 @提及與追蹤通知：那些只在首次發表時送出，
	 * 否則反覆編輯會變成騷擾同一個人的手段。
	 */
	public function api_edit( WP_REST_Request $req ) {
		$review_id = (int) $req['review_id'];
		$uid       = get_current_user_id();

		$review = get_post( $review_id );
		if ( ! $review || $review->post_type !== self::CPT || $review->post_status !== 'publish' ) {
			return new WP_Error( 'invalid_review', '找不到這則評論', [ 'status' => 404 ] );
		}

		if ( (int) $review->post_author !== $uid ) {
			return new WP_Error( 'not_owner', '只能編輯自己的評論', [ 'status' => 403 ] );
		}

		$content = trim( sanitize_textarea_field( (string) $req->get_param( 'content' ) ) );
		$track   = get_post_meta( $review_id, '_wxacg_review_track', true ) ?: self::TRACK_SHORT;

		// 回覆一律比照短評的長度規則
		$is_reply = (int) get_post_meta( $review_id, self::META_REPLY_TO, true ) > 0;
		$min_len  = ( $track === self::TRACK_LONG && ! $is_reply ) ? self::MIN_LEN_LONG : self::MIN_LEN_SHORT;
		$max_len  = ( $track === self::TRACK_LONG && ! $is_reply ) ? self::MAX_LEN_LONG : self::MAX_LEN_SHORT;

		$len = mb_strlen( $content );
		if ( $len < $min_len ) {
			return new WP_Error( 'too_short', sprintf( '內容至少需要 %d 個字', $min_len ), [ 'status' => 400 ] );
		}
		if ( $len > $max_len ) {
			return new WP_Error( 'too_long', sprintf( '內容不能超過 %d 個字', $max_len ), [ 'status' => 400 ] );
		}

		$updated = wp_update_post( [
			'ID'           => $review_id,
			'post_content' => $content,
		], true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_post_meta( $review_id, self::META_EDITED_AT, current_time( 'mysql' ) );

		if ( null !== $req->get_param( 'spoiler' ) ) {
			update_post_meta( $review_id, '_wxacg_review_spoiler', $req->get_param( 'spoiler' ) ? 1 : 0 );
		}

		return [
			'id'         => $review_id,
			'content'    => $content,
			'edited_at'  => get_post_meta( $review_id, self::META_EDITED_AT, true ),
		];
	}

	/**
	 * 切換「追蹤這串討論」。
	 *
	 * 只有主留言可以被追蹤：一串討論＝母評論加它底下的回覆，
	 * 追蹤回覆沒有意義，也會讓通知對象難以界定。
	 */
	public function api_follow( WP_REST_Request $req ) {
		$review_id = (int) $req['review_id'];
		$uid       = get_current_user_id();

		$review = get_post( $review_id );
		if ( ! $review || $review->post_type !== self::CPT || $review->post_status !== 'publish' ) {
			return new WP_Error( 'invalid_review', '找不到這則評論', [ 'status' => 404 ] );
		}

		if ( (int) get_post_meta( $review_id, self::META_REPLY_TO, true ) > 0 ) {
			return new WP_Error( 'not_thread', '只能追蹤主留言', [ 'status' => 400 ] );
		}

		$following = in_array( $uid, self::get_followers( $review_id ), true );

		if ( $following ) {
			delete_post_meta( $review_id, self::META_FOLLOWER, $uid );
		} else {
			add_post_meta( $review_id, self::META_FOLLOWER, $uid );
		}

		return [
			'following'      => ! $following,
			'follower_count' => count( self::get_followers( $review_id ) ),
		];
	}

	/**
	 * 取得追蹤這串討論的使用者 ID 清單。
	 *
	 * @return int[]
	 */
	private static function get_followers( int $review_id ): array {
		return array_map( 'intval', (array) get_post_meta( $review_id, self::META_FOLLOWER ) );
	}

	/**
	 * 通知追蹤這串討論的人。
	 *
	 * @param int   $review_id 新回覆的 ID。
	 * @param int   $thread_id 討論串（母評論）ID。
	 * @param int   $target_id 目標文章 ID。
	 * @param int   $actor_id  發表回覆的人。
	 * @param string $content  回覆內容。
	 * @param int[] $exclude   已用其他方式通知過的人，避免同一則發多次。
	 */
	private static function notify_thread_followers( int $review_id, int $thread_id, int $target_id, int $actor_id, string $content, array $exclude = [] ): void {
		if ( ! function_exists( 'wxacg_create_notification' ) ) {
			return;
		}

		$followers = self::get_followers( $thread_id );
		if ( ! $followers ) {
			return;
		}

		$actor = get_userdata( $actor_id );
		if ( ! $actor ) {
			return;
		}

		$excerpt = wp_strip_all_tags( $content );
		if ( mb_strlen( $excerpt ) > 80 ) {
			$excerpt = mb_substr( $excerpt, 0, 80 ) . '…';
		}

		foreach ( array_unique( $followers ) as $uid ) {
			if ( $uid === $actor_id || in_array( $uid, $exclude, true ) ) {
				continue;
			}

			wxacg_create_notification( [
				'user_id'     => $uid,
				'type'        => 'thread',
				'actor_id'    => $actor_id,
				'object_type' => 'review',
				'object_id'   => $review_id,
				'data'        => [
					'title'      => sprintf( '%s 在你追蹤的討論串中回覆', $actor->display_name ?: $actor->user_login ),
					'excerpt'    => $excerpt,
					'url'        => get_permalink( $target_id ) . '#asd-review-root',
					'icon'       => 'fa-bell',
					'post_title' => get_the_title( $target_id ),
				],
			] );
		}
	}

	/**
	 * 提供 @提及的自動完成候選。
	 *
	 * 回傳 nicename 是因為個人頁網址走 /u/{nicename}/，而且它是 URL 安全的；
	 * 顯示名稱可能含空白或中文，不適合直接當提及標記。
	 */
	public function api_mention_search( WP_REST_Request $req ) {
		$q = trim( (string) $req->get_param( 'q' ) );

		if ( mb_strlen( $q ) < 1 ) {
			return [ 'items' => [] ];
		}

		$users = get_users( [
			'search'         => '*' . $q . '*',
			'search_columns' => [ 'user_login', 'user_nicename', 'display_name' ],
			'number'         => 8,
			'fields'         => [ 'ID', 'user_nicename', 'display_name' ],
		] );

		$items = [];
		foreach ( $users as $u ) {
			$items[] = [
				'id'       => (int) $u->ID,
				'nicename' => $u->user_nicename,
				'name'     => $u->display_name ?: $u->user_nicename,
				'avatar'   => get_avatar_url( $u->ID, [ 'size' => 32 ] ),
			];
		}

		return [ 'items' => $items ];
	}

	/**
	 * 解析內容中的 @提及並發通知。
	 *
	 * 只認 nicename（ASCII、URL 安全），因為顯示名稱可能含空白或中文，
	 * 無法可靠地界定提及標記的結尾。
	 *
	 * @param int    $review_id 這則評論 ID。
	 * @param int    $target_id 目標文章 ID。
	 * @param int    $actor_id  發表者。
	 * @param string $content   內容。
	 * @param int    $skip_uid  已因回覆通知過的人，避免同一則發兩次通知。
	 * @return int[] 實際發出通知的使用者 ID，供後續的追蹤通知排除。
	 */
	private static function notify_mentions( int $review_id, int $target_id, int $actor_id, string $content, int $skip_uid = 0 ): array {
		if ( ! function_exists( 'wxacg_create_notification' ) ) {
			return [];
		}

		/*
		 * @ 前面必須是開頭或空白，與前端顯示規則一致。
		 * 否則像 https://x.com/@someone 這種網址也會被當成提及，
		 * 讓不相干的會員收到通知。
		 */
		if ( ! preg_match_all( '/(?:^|\s)@([a-zA-Z0-9_.\-]{1,60})/u', $content, $m ) ) {
			return [];
		}

		$actor = get_userdata( $actor_id );
		if ( ! $actor ) {
			return [];
		}

		$excerpt = wp_strip_all_tags( $content );
		if ( mb_strlen( $excerpt ) > 80 ) {
			$excerpt = mb_substr( $excerpt, 0, 80 ) . '…';
		}

		$notified = [];
		$result   = [];
		foreach ( array_unique( $m[1] ) as $nicename ) {
			$user = get_user_by( 'slug', $nicename );
			if ( ! $user ) {
				continue;
			}

			$uid = (int) $user->ID;

			// 不通知自己；已經因為「回覆」通知過的人也不再重複打擾
			if ( $uid === $actor_id || $uid === $skip_uid || isset( $notified[ $uid ] ) ) {
				continue;
			}
			$notified[ $uid ] = true;
			$result[]         = $uid;

			wxacg_create_notification( [
				'user_id'     => $uid,
				'type'        => 'mention',
				'actor_id'    => $actor_id,
				'object_type' => 'review',
				'object_id'   => $review_id,
				'data'        => [
					'title'      => sprintf( '%s 在評論中提到你', $actor->display_name ?: $actor->user_login ),
					'excerpt'    => $excerpt,
					'url'        => get_permalink( $target_id ) . '#asd-review-root',
					'icon'       => 'fa-at',
					'post_title' => get_the_title( $target_id ),
				],
			] );
		}

		return $result;
	}

	public function require_login() {
		return is_user_logged_in()
			? true
			: new WP_Error( 'rest_forbidden', '請先登入', [ 'status' => 401 ] );
	}

	/* =========================================================
	 * GET /reviews/{anime_id}
	 * ======================================================= */

	public function api_list( WP_REST_Request $req ) {
		$anime_id = (int) $req['anime_id'];
		if ( ! self::is_reviewable( $anime_id ) ) {
			return new WP_Error( 'invalid_target', '找不到這篇內容', [ 'status' => 404 ] );
		}

		$track   = $req->get_param( 'track' );
		$episode = $req->get_param( 'episode' );
		$sort    = $req->get_param( 'sort' ) === 'hot' ? 'hot' : 'new';
		$page    = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = 20;

		$meta_query = [];
		if ( in_array( $track, [ self::TRACK_SHORT, self::TRACK_LONG ], true ) ) {
			$meta_query[] = [ 'key' => '_wxacg_review_track', 'value' => $track ];
		}
		if ( $episode !== null && $episode !== '' ) {
			$meta_query[] = [ 'key' => '_wxacg_review_episode', 'value' => (int) $episode ];
		}

		$query_args = [
			'post_type'              => self::CPT,
			'post_parent'            => $anime_id,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		];
		if ( $meta_query ) {
			$query_args['meta_query'] = $meta_query;
		}

		$posts = get_posts( $query_args );

		global $wpdb;
		$votes_table = $wpdb->prefix . 'wxacg_review_votes';
		$uid         = get_current_user_id();

		$rating_mgr = class_exists( 'Anime_Sync_Rating_Manager' ) ? new Anime_Sync_Rating_Manager() : null;
		$status_mgr = class_exists( 'Anime_Sync_User_Status_Manager' ) ? new Anime_Sync_User_Status_Manager() : null;

		$rows = [];
		foreach ( $posts as $post ) {
			$author_id = (int) $post->post_author;

			$like_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$votes_table} WHERE review_id = %d AND vote_type = %d",
				$post->ID, self::VOTE_LIKE
			) );
			$dislike_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$votes_table} WHERE review_id = %d AND vote_type = %d",
				$post->ID, self::VOTE_DISLIKE
			) );

			$my_vote = 0;
			if ( $uid > 0 ) {
				$my_vote = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT vote_type FROM {$votes_table} WHERE review_id = %d AND user_id = %d",
					$post->ID, $uid
				) );
			}

			$score = null;
			if ( $rating_mgr ) {
				$r = $rating_mgr->get_user_rating( $anime_id, $author_id );
				if ( $r ) {
					$score = $r['score_overall'];
				}
			}

			$watch_status = null;
			if ( $status_mgr ) {
				$entry = $status_mgr->get_entry( $author_id, $anime_id );
				$watch_status = $entry['status'];
			}

			$followers = self::get_followers( $post->ID );

			$rows[] = [
				'id'             => $post->ID,
				'reply_to'       => (int) get_post_meta( $post->ID, self::META_REPLY_TO, true ),
				'following'      => $uid > 0 && in_array( $uid, $followers, true ),
				'follower_count' => count( $followers ),
				'edited_at'      => (string) get_post_meta( $post->ID, self::META_EDITED_AT, true ),
				'track'      => get_post_meta( $post->ID, '_wxacg_review_track', true ) ?: self::TRACK_SHORT,
				'episode'    => (int) get_post_meta( $post->ID, '_wxacg_review_episode', true ),
				'spoiler'    => (bool) get_post_meta( $post->ID, '_wxacg_review_spoiler', true ),
				'title'      => get_the_title( $post ),
				'excerpt'    => $post->post_excerpt,
				'content'    => $post->post_content,
				'date'       => get_the_date( 'c', $post ),
				'author_id'  => $author_id,
				'author'     => get_the_author_meta( 'display_name', $author_id ),
				'avatar'     => get_avatar_url( $author_id, [ 'size' => 48 ] ),
				'score'      => $score,
				'watch_status' => $watch_status,
				'like_count'    => $like_count,
				'dislike_count' => $dislike_count,
				'net_score'     => $like_count - $dislike_count,
				'my_vote'       => $my_vote,
				'is_mine'       => $uid > 0 && $uid === $author_id,
			];
		}

		if ( $sort === 'hot' ) {
			usort( $rows, function ( $a, $b ) {
				if ( $a['net_score'] !== $b['net_score'] ) {
					return $b['net_score'] <=> $a['net_score'];
				}
				return strcmp( $b['date'], $a['date'] );
			} );
		}

		$total   = count( $rows );
		$offset  = ( $page - 1 ) * $per_page;
		$paged   = array_slice( $rows, $offset, $per_page );

		return rest_ensure_response( [
			'items'    => $paged,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		] );
	}

	/* =========================================================
	 * POST /reviews/{anime_id}
	 * ======================================================= */

	public function api_submit( WP_REST_Request $req ) {
		$anime_id = (int) $req['anime_id'];
		if ( ! self::is_reviewable( $anime_id, true ) ) {
			return new WP_Error( 'invalid_target', '找不到這篇內容', [ 'status' => 404 ] );
		}

		$uid = get_current_user_id();

		if ( ! $this->check_rate_limit( $uid ) ) {
			return new WP_Error( 'rate_limited', '發表太頻繁，請稍後再試', [ 'status' => 429 ] );
		}

		// 收藏門檻：片單裡要有這部作品的紀錄（任何狀態皆可）
		if ( class_exists( 'Anime_Sync_User_Status_Manager' ) ) {
			$status_mgr = new Anime_Sync_User_Status_Manager();
			$entry      = $status_mgr->get_entry( $uid, $anime_id );
			if ( empty( $entry['status'] ) ) {
				return new WP_Error(
					'not_in_list',
					'請先把這部作品加入片單（想看／在看／看過皆可）才能發表評論',
					[ 'status' => 403 ]
				);
			}
		}

		$track = $req->get_param( 'track' ) === self::TRACK_LONG ? self::TRACK_LONG : self::TRACK_SHORT;

		// 後端把關：該文章類型不開放的軌道一律退回（例如新聞不收長評）
		if ( ! in_array( $track, self::allowed_tracks( $anime_id ), true ) ) {
			return new WP_Error( 'invalid_track', '這篇內容不支援這種評論類型', [ 'status' => 400 ] );
		}

		/*
		 * 回覆：只做一層。回覆「回覆」時自動歸到它的母評論底下，
		 * 避免無限縮排在手機上被擠扁，也省去遞迴查詢。
		 */
		$reply_to = (int) $req->get_param( 'reply_to' );
		if ( $reply_to > 0 ) {
			$parent = get_post( $reply_to );

			if ( ! $parent
				|| $parent->post_type !== self::CPT
				|| (int) $parent->post_parent !== $anime_id
				|| $parent->post_status !== 'publish' ) {
				return new WP_Error( 'invalid_reply_target', '找不到要回覆的評論', [ 'status' => 404 ] );
			}

			$grandparent = (int) get_post_meta( $reply_to, self::META_REPLY_TO, true );
			if ( $grandparent > 0 ) {
				$reply_to = $grandparent;
			}
		}

		$episode = (int) $req->get_param( 'episode' );
		if ( $episode < 0 ) {
			$episode = 0;
		}
		if ( $track === self::TRACK_LONG ) {
			// 長評固定是整部作品層級，不開放掛在單集
			$episode = 0;
		}

		// 防呆：擋掉還沒播出的集數（前端下拉選單本來就只列已播出的，
		// 這裡是防有人繞過前端直接打 API）。用彙總計數當簡易上限，
		// 不逐集比對 airdate——這個 meta 本來就是同步排程在維護的，
		// 精準度夠擋惡意繞過，不需要在 REST 端重算一次完整集數列表。
		if ( $episode > 0 && function_exists( 'smacg_get_meta' ) ) {
			$ep_aired = (int) smacg_get_meta( $anime_id, 'episodes_aired', 0 );
			if ( $ep_aired > 0 && $episode > $ep_aired ) {
				return new WP_Error(
					'episode_not_aired',
					'這一集還沒播出，暫時無法發表評論',
					[ 'status' => 400 ]
				);
			}
		}

		$spoiler = $req->get_param( 'spoiler' ) ? 1 : 0;
		// 純文字儲存（前端顯示時會 escape 再把 \n 轉成 <br>），不留 HTML 標籤，
		// 避免 wp_kses_post() 允許的標籤跟前端的跳脫顯示邏輯打架。
		$content = trim( sanitize_textarea_field( (string) $req->get_param( 'content' ) ) );
		$content_len = mb_strlen( $content );

		$min_len = $track === self::TRACK_LONG ? self::MIN_LEN_LONG : self::MIN_LEN_SHORT;
		$max_len = $track === self::TRACK_LONG ? self::MAX_LEN_LONG : self::MAX_LEN_SHORT;
		if ( $content_len < $min_len ) {
			return new WP_Error(
				'content_too_short',
				sprintf( '內容至少要 %d 字（目前 %d 字）', $min_len, $content_len ),
				[ 'status' => 400 ]
			);
		}
		if ( $content_len > $max_len ) {
			return new WP_Error(
				'content_too_long',
				sprintf( '內容最多 %d 字（目前 %d 字）', $max_len, $content_len ),
				[ 'status' => 400 ]
			);
		}

		$title   = '';
		$excerpt = '';
		if ( $track === self::TRACK_LONG ) {
			$title   = sanitize_text_field( (string) $req->get_param( 'title' ) );
			$excerpt = sanitize_text_field( (string) $req->get_param( 'excerpt' ) );
			if ( $excerpt !== '' ) {
				$excerpt_len = mb_strlen( $excerpt );
				if ( $excerpt_len < self::MIN_LEN_EXCERPT || $excerpt_len > self::MAX_LEN_EXCERPT ) {
					return new WP_Error(
						'excerpt_length',
						sprintf( '摘要長度需介於 %d–%d 字之間（選填，不填就留空）', self::MIN_LEN_EXCERPT, self::MAX_LEN_EXCERPT ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		/*
		 * upsert：同一人對同一部作品的同一軌（＋同一集）已有評論就更新，不重複建立。
		 *
		 * ★ 回覆不套用這條規則。回覆本來就該能對同一則討論串發表多次，
		 *   若沿用 upsert，使用者的第二則回覆會直接覆蓋掉第一則。
		 */
		$existing = [];
		if ( 0 === $reply_to ) {
			$existing = get_posts( [
				'post_type'      => self::CPT,
				'post_parent'    => $anime_id,
				'author'         => $uid,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => [
					[ 'key' => '_wxacg_review_track', 'value' => $track ],
					[ 'key' => '_wxacg_review_episode', 'value' => $episode ],
					[ 'key' => self::META_REPLY_TO, 'compare' => 'NOT EXISTS' ],
				],
				'fields' => 'ids',
			] );
		}

		$postarr = [
			'post_type'    => self::CPT,
			'post_parent'  => $anime_id,
			'post_author'  => $uid,
			'post_status'  => 'publish',
			'post_title'   => $title !== '' ? $title : ( $track === self::TRACK_LONG ? '（無標題評論）' : '' ),
			'post_excerpt' => $excerpt,
			'post_content' => $content,
		];

		$is_new = empty( $existing );
		if ( ! $is_new ) {
			$postarr['ID'] = (int) $existing[0];
			$review_id     = wp_update_post( $postarr, true );
		} else {
			$review_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $review_id ) ) {
			return $review_id;
		}

		update_post_meta( $review_id, '_wxacg_review_track', $track );
		update_post_meta( $review_id, '_wxacg_review_episode', $episode );
		update_post_meta( $review_id, '_wxacg_review_spoiler', $spoiler );

		// 主留言不寫這個 meta，讓上面 upsert 的 NOT EXISTS 條件能正確區分兩者
		if ( $reply_to > 0 ) {
			update_post_meta( $review_id, self::META_REPLY_TO, $reply_to );

			// 只在新回覆時通知，編輯既有回覆不重複打擾對方
			if ( $is_new ) {
				self::notify_reply( (int) $review_id, $reply_to, $anime_id, $uid, $content );
			}
		} else {
			delete_post_meta( $review_id, self::META_REPLY_TO );
		}

		/*
		 * 通知的優先順序：回覆 → @提及 → 追蹤討論串。
		 * 同一個人可能同時符合多個條件，因此逐層把已通知過的人排除，
		 * 避免一則評論讓同一個人收到兩三則通知。
		 */
		if ( $is_new ) {
			$reply_author = $reply_to > 0 ? (int) get_post_field( 'post_author', $reply_to ) : 0;

			$mentioned = self::notify_mentions( (int) $review_id, $anime_id, $uid, $content, $reply_author );

			if ( $reply_to > 0 ) {
				$already = array_merge( [ $reply_author ], $mentioned );
				self::notify_thread_followers( (int) $review_id, $reply_to, $anime_id, $uid, $content, $already );
			}

			// 發表主留言者自動追蹤自己開的串，回覆者也自動追蹤該串
			$thread_id = $reply_to > 0 ? $reply_to : (int) $review_id;
			if ( ! in_array( $uid, self::get_followers( $thread_id ), true ) ) {
				add_post_meta( $thread_id, self::META_FOLLOWER, $uid );
			}
		}

		if ( $is_new ) {
			do_action( 'wxacg_review_submitted', $uid, $review_id, $anime_id, $track );
		}

		return rest_ensure_response( [
			'success'   => true,
			'review_id' => $review_id,
			'is_new'    => $is_new,
		] );
	}

	/* =========================================================
	 * DELETE /reviews/item/{review_id}
	 * ======================================================= */

	public function api_delete( WP_REST_Request $req ) {
		$review_id = (int) $req['review_id'];
		$post      = get_post( $review_id );

		if ( ! $post || $post->post_type !== self::CPT ) {
			return new WP_Error( 'not_found', '找不到這則評論', [ 'status' => 404 ] );
		}

		$uid = get_current_user_id();
		if ( (int) $post->post_author !== $uid && ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'forbidden', '沒有權限刪除這則評論', [ 'status' => 403 ] );
		}

		$ok = wp_delete_post( $review_id, true );
		if ( ! $ok ) {
			return new WP_Error( 'delete_failed', '刪除失敗，請稍後再試', [ 'status' => 500 ] );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wxacg_review_votes', [ 'review_id' => $review_id ] );

		return rest_ensure_response( [ 'success' => true ] );
	}

	/* =========================================================
	 * POST /reviews/item/{review_id}/vote
	 * ======================================================= */

	public function api_vote( WP_REST_Request $req ) {
		$review_id = (int) $req['review_id'];
		$post      = get_post( $review_id );

		if ( ! $post || $post->post_type !== self::CPT || $post->post_status !== 'publish' ) {
			return new WP_Error( 'not_found', '找不到這則評論', [ 'status' => 404 ] );
		}

		$uid = get_current_user_id();
		if ( (int) $post->post_author === $uid ) {
			return new WP_Error( 'self_vote', '不能對自己的評論按讚／倒讚', [ 'status' => 403 ] );
		}

		if ( ! $this->check_vote_rate_limit( $uid ) ) {
			return new WP_Error( 'rate_limited', '操作太頻繁，請稍後再試', [ 'status' => 429 ] );
		}

		$type = $req->get_param( 'type' );
		if ( ! in_array( $type, [ 'like', 'dislike' ], true ) ) {
			return new WP_Error( 'invalid_type', '無效的投票類型', [ 'status' => 400 ] );
		}
		$vote_value = $type === 'like' ? self::VOTE_LIKE : self::VOTE_DISLIKE;

		global $wpdb;
		$table   = $wpdb->prefix . 'wxacg_review_votes';
		$current = $wpdb->get_var( $wpdb->prepare(
			"SELECT vote_type FROM {$table} WHERE review_id = %d AND user_id = %d",
			$review_id, $uid
		) );

		if ( $current !== null && (int) $current === $vote_value ) {
			// 再按一次 = 取消投票
			$wpdb->delete( $table, [ 'review_id' => $review_id, 'user_id' => $uid ] );
			$my_vote = 0;
		} elseif ( $current !== null ) {
			$wpdb->update(
				$table,
				[ 'vote_type' => $vote_value ],
				[ 'review_id' => $review_id, 'user_id' => $uid ]
			);
			$my_vote = $vote_value;
		} else {
			$wpdb->insert( $table, [
				'review_id'  => $review_id,
				'user_id'    => $uid,
				'vote_type'  => $vote_value,
				'created_at' => current_time( 'mysql' ),
			] );
			$my_vote = $vote_value;
		}

		$like_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE review_id = %d AND vote_type = %d",
			$review_id, self::VOTE_LIKE
		) );
		$dislike_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE review_id = %d AND vote_type = %d",
			$review_id, self::VOTE_DISLIKE
		) );

		return rest_ensure_response( [
			'success'       => true,
			'my_vote'       => $my_vote,
			'like_count'    => $like_count,
			'dislike_count' => $dislike_count,
		] );
	}

	/* =========================================================
	 * 工具
	 * ======================================================= */

	private function check_rate_limit( int $user_id ): bool {
		$key   = "asp_review_rate_{$user_id}";
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_PERIOD );
		return true;
	}

	private function check_vote_rate_limit( int $user_id ): bool {
		$key   = "asp_review_vote_rate_{$user_id}";
		$count = (int) get_transient( $key );
		if ( $count >= self::VOTE_RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::VOTE_RATE_LIMIT_PERIOD );
		return true;
	}
}
