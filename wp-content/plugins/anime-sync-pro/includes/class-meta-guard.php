<?php
/**
 * 動畫欄位寫入防護欄
 *
 * 為什麼需要
 * ----------
 * 站上有 6 個 AniList／Bangumi 呼叫點與十幾支 cron，每一支都能寫 post meta。
 * 目前的安全機制是「每個寫入的地方自己記得檢查」——2026-09-08 證明這會失效：
 * Bangumi 備援算出的已播集數因為 API 的 limit=100 被截斷，把
 * ONE PIECE 1177→100、吉伊卡哇 375→100 寫進資料庫，資料庫完全沒有意見。
 * 守衛在一次性腳本裡有寫，移植進外掛時漏掉了。
 *
 * 這個檔案把規則收在唯一一個地方：掛 WordPress 的 update_post_metadata
 * filter，那是所有 meta 更新的必經之路，不需要改任何呼叫點，也繞不過去。
 *
 * 規則
 * ----
 * 只擋「邏輯上不可能」的變更，不做內容審查：
 *   - 已播集數不得變少（集數不會倒退）
 *   - 總集數／評分不得從有值變成 0（API 回空值時洗掉既有資料）
 *   - 標題類欄位不得從有值變成空字串
 *
 * 刻意不擋的：值變大、第一次寫入、人工在後台編輯（後台走的是 ACF／WP 自己的
 * 儲存流程，同樣經過這道 filter，但那些情況都是「值變大」或「有值→有值」）。
 *
 * 三種模式（存在 option，可隨時切換不必重新部署）
 * ----------------------------------------------
 *   enforce  擋下並記 log（預設）
 *   observe  只記 log 不擋，用來觀察會不會誤傷正常流程
 *   off      完全停用
 *
 * 合法的往下修正
 * --------------
 * 上游真的修正了資料（例如某作品總集數從 24 更正為 12）時，
 * 呼叫 Anime_Sync_Meta_Guard::allow_once( $post_id, $meta_key ) 再寫入即可。
 * 這樣「往下修」變成必須明講的動作，而不是預設就能發生。
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Meta_Guard {

	const MODE_OPTION = 'anime_sync_meta_guard_mode';

	/** 一次性放行的白名單，key 為 "postid:metakey" */
	private static array $allow_once = [];

	/**
	 * 受保護的欄位與規則。
	 *
	 * 'no_decrease'  數值不得變小（也涵蓋變成 0）
	 * 'no_zero'      數值不得從 >0 變成 0／空
	 * 'no_empty'     字串不得從有值變成空
	 */
	private const RULES = [
		'anime_episodes_aired'  => 'no_decrease',
		'anime_episodes'        => 'no_zero',
		'anime_score_mal'       => 'no_zero',
		'anime_score_bangumi'   => 'no_zero',
		'anime_score_anilist'   => 'no_zero',
		'anime_title_native'    => 'no_empty',
		'anime_title_romaji'    => 'no_empty',
		'anime_title_chinese'   => 'no_empty',
		'anime_bangumi_id'      => 'no_zero',
		'anime_anilist_id'      => 'no_zero',
	];

	public static function init(): void {
		add_filter( 'update_post_metadata', [ __CLASS__, 'guard' ], 10, 5 );
	}

	/**
	 * 明確允許某一次往下修正。
	 *
	 * 用在上游真的更正了資料的情況；用完即失效，不會留下長期漏洞。
	 */
	public static function allow_once( int $post_id, string $meta_key ): void {
		self::$allow_once[ $post_id . ':' . $meta_key ] = true;
	}

	public static function mode(): string {
		$m = (string) get_option( self::MODE_OPTION, 'enforce' );
		return in_array( $m, [ 'enforce', 'observe', 'off' ], true ) ? $m : 'enforce';
	}

	/**
	 * @param mixed  $check      非 null 即短路（擋下）
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 * @param mixed  $prev_value
	 * @return mixed null = 放行
	 */
	public static function guard( $check, $object_id, $meta_key, $meta_value, $prev_value ) {

		/*
		 * 這個 filter 會跑在全站每一次 post meta 更新上，
		 * 所以第一件事必須是最便宜的判斷：不在規則表裡就立刻放行。
		 */
		if ( ! isset( self::RULES[ $meta_key ] ) ) {
			return $check;
		}

		if ( self::mode() === 'off' ) {
			return $check;
		}

		$key = $object_id . ':' . $meta_key;
		if ( isset( self::$allow_once[ $key ] ) ) {
			unset( self::$allow_once[ $key ] );
			return $check;
		}

		/*
		 * 後台人工編輯一律放行。
		 *
		 * 防護欄要擋的是「程式在無人看管時寫壞資料」，不是站長本人。
		 * 編輯者在後台把某部作品的集數改小，多半正是因為原本的值錯了——
		 * 這時候擋下來只會讓人以為後台壞掉，而且錯誤資料還改不掉。
		 *
		 * wp_doing_cron() 期間 is_admin() 為 false，所以 cron 不會被誤放行。
		 */
		if ( ! wp_doing_cron() && is_admin() && current_user_can( 'edit_posts' ) ) {
			return $check;
		}

		$old = get_post_meta( (int) $object_id, $meta_key, true );

		/*
		 * anime_episodes_aired 的不變量：狀態是「尚未播出」時，已播集數只能是 0。
		 *
		 * 2026-09-15 事故：《藥師少女的獨語 第三季》（2026-10-02 才開播）被寫入
		 * aired = 24。成因是該篇 anime_bangumi_id 誤掛成第一季的條目，AniList
		 * 熔斷期間 build_bgm_fallback_media() 取到第一季 24 集的集數表，合成出
		 * nextAiringEpisode.episode = 25，於是算成 24。當時全站有 13 篇處於
		 * 「狀態未播出、卻有已播集數」的矛盾狀態。
		 *
		 * 兩個方向都要處理：
		 *   寫入 > 0  → 擋下。未播出卻有已播集數，邏輯上不可能，不管來源是誰。
		 *   往下修正  → 放行。原本的 no_decrease 會讓錯誤的高值永久卡死，
		 *               連上游更正了也寫不回去——這正是當時 24 降不下來的原因。
		 *
		 * 刻意放在「沒有舊值就放行」之前：第一次就寫入錯值的情況也要擋得住。
		 */
		if ( 'anime_episodes_aired' === $meta_key
			&& is_numeric( $meta_value )
			&& 'NOT_YET_RELEASED' === get_post_meta( (int) $object_id, 'anime_status', true ) ) {

			if ( (int) $meta_value > 0 ) {
				self::log_violation(
					(int) $object_id,
					(string) $meta_key,
					sprintf( '狀態為尚未播出，已播集數不得為 %d', (int) $meta_value )
				);
				return self::mode() === 'observe' ? $check : false;
			}

			return $check;
		}

		// 沒有舊值就沒有東西要保護
		if ( $old === '' || $old === null || $old === false ) {
			return $check;
		}

		$rule      = self::RULES[ $meta_key ];
		$violation = '';

		switch ( $rule ) {
			case 'no_decrease':
				if ( is_numeric( $old ) && is_numeric( $meta_value ) && (int) $meta_value < (int) $old ) {
					$violation = sprintf( '不得變小（%d → %d）', (int) $old, (int) $meta_value );
				}
				break;

			case 'no_zero':
				if ( is_numeric( $old ) && (int) $old > 0
					&& ( $meta_value === '' || $meta_value === null || ( is_numeric( $meta_value ) && (int) $meta_value === 0 ) ) ) {
					$violation = sprintf( '不得從 %d 歸零', (int) $old );
				}
				break;

			case 'no_empty':
				if ( is_string( $old ) && trim( $old ) !== ''
					&& ( ! is_string( $meta_value ) || trim( (string) $meta_value ) === '' ) ) {
					$violation = sprintf( '不得清空（原值「%s」）', mb_substr( (string) $old, 0, 20 ) );
				}
				break;
		}

		if ( $violation === '' ) {
			return $check;
		}

		self::log_violation( (int) $object_id, (string) $meta_key, $violation );

		// observe 模式只記錄不擋，用來確認不會誤傷正常流程
		return self::mode() === 'observe' ? $check : false;
	}

	/**
	 * 記錄被擋下的寫入。
	 *
	 * 一定要記到誰呼叫的——只寫「某欄位被擋」的話，事後根本查不出是哪支程式，
	 * 而查不出來就等於這道防護欄只會讓問題變得更難追。
	 */
	private static function log_violation( int $post_id, string $meta_key, string $violation ): void {

		$caller = '';
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $f ) {
			$file = (string) ( $f['file'] ?? '' );
			if ( $file === '' || strpos( $file, 'meta-guard' ) !== false || strpos( $file, '/wp-includes/' ) !== false ) {
				continue;
			}
			$caller = basename( $file ) . ':' . (int) ( $f['line'] ?? 0 );
			break;
		}

		$msg = sprintf(
			'[防護欄%s] %s 的 %s %s｜呼叫端 %s',
			self::mode() === 'observe' ? '（僅觀察）' : '',
			get_the_title( $post_id ) ?: "#{$post_id}",
			$meta_key,
			$violation,
			$caller ?: '未知'
		);

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::warning( $msg, [
				'post_id'  => $post_id,
				'meta_key' => $meta_key,
			] );
		} else {
			error_log( 'anime-sync ' . $msg );
		}
	}
}
