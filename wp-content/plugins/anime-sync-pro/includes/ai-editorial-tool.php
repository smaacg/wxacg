<?php
/**
 * 微笑動漫 — 編輯短評控管工具
 *
 * Path: wp-content/plugins/anime-sync-pro/includes/ai-editorial-tool.php
 *
 * @version 2.0.0 (2026-08-17)
 *
 * v2.0.0（移除 AI 生成，改為人工快速編輯）：
 *   - 移除整套 AI 產生功能：Gemini／Groq API Key 管理、金鑰池與冷卻、
 *     額度偵測、批次產生 AJAX、每日上限、prompt 組裝與模型請求。
 *     原因：AI 產出幻覺過多，實務上已改為全人工撰寫（見 v1.11.0）。
 *   - 頁面改為「短評控管」：列出作品並可直接就地編輯 ACF 短評欄位，
 *     省去逐篇進文章編輯頁的往返。
 *   - 存檔行為與 class-acf-fields.php 的 auto_assign_editorial_reviewer()
 *     一致：自動帶入審核者、審核日期，並標記為已發布。
 *   - 保留：季度／人氣排序與篩選子句、待處理計數、文字正規化與平台防呆。
 *
 * 歷史版本（v1.x）為 AI 批次產生器，相關程式已於 v2.0.0 移除。
 */

defined( 'ABSPATH' ) || exit;


if ( ! defined( 'WXACG_EDITORIAL_META' ) ) {
	define( 'WXACG_EDITORIAL_META', 'anime_editor_summary' );
}

if ( ! defined( 'WXACG_PROMPT_VERSION_META' ) ) {
	define( 'WXACG_PROMPT_VERSION_META', 'anime_editorial_prompt_version' );
}

/**
 * ACF 欄位 name → field key 對照。
 *
 * 寫入 meta 時一併補上 _name 參照，確保後台 ACF 編輯框正常顯示。
 *
 * ⚠️ 請與 class-acf-fields.php 逐項核對：name 與 key 必須成對，
 *    特別是 prompt version 這一列（name 無 ai_、key 有 ai_）。
 *    若 ACF 註冊的欄位名是 anime_editorial_ai_prompt_version，
 *    請同時修改上方 WXACG_PROMPT_VERSION_META 常數。
 */
function wxacg_acf_field_key_map() {
	return array(
		'anime_editor_summary'            => 'field_anime_editor_summary',
		'anime_editorial_status'          => 'field_anime_editorial_status',
		'anime_editorial_author_id'       => 'field_anime_editorial_author_id',
		'anime_editorial_reviewed_at'     => 'field_anime_editorial_reviewed_at',
		'anime_editorial_ai_generated'    => 'field_anime_editorial_ai_generated',
		'anime_editorial_ai_needs_review' => 'field_anime_editorial_ai_needs_review',
		'anime_editorial_ai_model'        => 'field_anime_editorial_ai_model',
		'anime_editorial_prompt_version'  => 'field_anime_editorial_ai_prompt_version',
		'anime_editorial_ai_generated_at' => 'field_anime_editorial_ai_generated_at',
	);
}

/**
 * 安全讀取短評：只認字串，其餘一律視為空值。
 *
 * ★ 為什麼需要這道防線：舊版 AI 產生器在模型回傳空白時，把 WP_Error
 *   物件本身存進了短評欄位。讀出來是物件，前台一做 (string) 轉型就是
 *   「Object of class WP_Error could not be converted to string」致命錯誤，
 *   整頁 500（實際發生過，K-ON！輕音部「計畫！」那篇）。
 *
 *   產生錯誤資料的程式已於 v2.0.0 移除，但欄位本身仍可能被其他外掛或
 *   日後的程式寫入非字串值。與其相信寫入端都正確，不如在讀取端擋住：
 *   最壞情況只是短評顯示為空，不會讓整個頁面掛掉。
 *
 * @param int $post_id 文章 ID。
 * @return string
 */
function wxacg_editorial_text( $post_id ) {
	$value = get_post_meta( (int) $post_id, WXACG_EDITORIAL_META, true );

	return is_string( $value ) ? $value : '';
}

/**
 * 撰寫短評的建議字數下限。
 *
 * ★ 直接沿用主題的廣告資格門檻 WXACG_ADSENSE_EDITORIAL_MIN，不另外寫死數字。
 *   原因：這裡原本建議 120～160 字，但 wxacg_is_anime_adsense_eligible()
 *   要求 180 字以上才有廣告資格。照建議寫出來的短評足以解除 noindex、
 *   會被 Google 收錄，卻永遠不會顯示廣告——流量進得來卻沒有收益。
 *   兩邊共用同一個常數，就不會再各自漂移。
 *
 * @return int
 */
function wxacg_editorial_min_chars() {
	return defined( 'WXACG_ADSENSE_EDITORIAL_MIN' ) ? (int) WXACG_ADSENSE_EDITORIAL_MIN : 180;
}

function wxacg_update_acf_meta( $post_id, $key, $value ) {
	update_post_meta( $post_id, $key, $value );

	$map = wxacg_acf_field_key_map();

	if ( isset( $map[ $key ] ) ) {
		update_post_meta( $post_id, '_' . $key, $map[ $key ] );
	}
}

function wxacg_delete_acf_meta( $post_id, $key ) {
	delete_post_meta( $post_id, $key );
	delete_post_meta( $post_id, '_' . $key );
}

/* ============================================================
 * PHP 7.2／mbstring 相容工具
 * ============================================================ */

function wxacg_editorial_strlen( $text ) {
	$text = (string) $text;

	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( $text, 'UTF-8' );
	}

	return (int) strlen( $text );
}

function wxacg_editorial_substr( $text, $start, $length = null ) {
	$text  = (string) $text;
	$start = (int) $start;

	if ( function_exists( 'mb_substr' ) ) {
		if ( null === $length ) {
			return mb_substr( $text, $start, null, 'UTF-8' );
		}

		return mb_substr( $text, $start, (int) $length, 'UTF-8' );
	}

	if ( null === $length ) {
		return substr( $text, $start );
	}

	return substr( $text, $start, (int) $length );
}

/**
 * 清理模型回傳的編輯短評。
 *
 * @return string|WP_Error
 */
function wxacg_normalize_editorial_text( $text ) {
	$text = is_scalar( $text ) ? (string) $text : '';
	$text = trim( $text );

	$text = preg_replace( '#<(?:think|thinking|reasoning)>.*?</(?:think|thinking|reasoning)>#isu', '', $text );
	$text = preg_replace( '#^\s*<(?:think|thinking|reasoning)>.*$#isu', '', $text );

	$text = preg_replace( '/^\s*```(?:text|markdown|md)?\s*/iu', '', $text );
	$text = preg_replace( '/\s*```\s*$/u', '', $text );

	$text = preg_replace(
		'/^\s*(?:編輯短評|短評|評論|本文|輸出|Answer|Final)\s*[：:]\s*/iu',
		'',
		$text
	);

	$text = preg_replace( '/^\s*[「『“"]\s*/u', '', $text );
	$text = preg_replace( '/\s*[」』”"]\s*$/u', '', $text );

	$text = wp_strip_all_tags( $text );
	$text = sanitize_textarea_field( $text );

	$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
	$text = preg_replace( "/\R{3,}/u", "\n\n", $text );
	$text = trim( $text );

	if ( '' === $text ) {
		return new WP_Error( 'ai_empty', '模型回傳空白內容。' );
	}

	$length = wxacg_editorial_strlen( preg_replace( '/\s+/u', '', $text ) );

	if ( $length < 90 ) {
		return new WP_Error(
			'ai_too_short',
			sprintf( '短評過短（%d 字，需 90 字以上），未寫入資料庫。', $length )
		);
	}

	if ( $length > 240 ) {
		return new WP_Error(
			'ai_too_long',
			sprintf( '短評過長（%d 字），未寫入資料庫。', $length )
		);
	}

	return $text;
}

/**
 * 平台幻覺防護。
 *
 * 短評若出現「台灣合法串流」欄位以外的平台名，或用籠統說法規避限制，
 * 回傳觸發的關鍵字（供錯誤訊息使用）；通過檢查則回傳空字串。
 *
 * @param string $text    已 normalize 的短評本文。
 * @param string $allowed $data['streaming']（已 implode 的允許平台字串，可能為空）。
 * @return string 觸發的關鍵字，未觸發時為空字串。
 */
function wxacg_editorial_platform_guard( $text, $allowed ) {
	$text    = (string) $text;
	$allowed = (string) $allowed;

	// mbstring 不可用時退回大小寫敏感比對，避免 fatal。
	$has_mb = function_exists( 'mb_stripos' );

	// 已知平台關鍵字庫（含常見別名）。
	$known = array(
		'Crunchyroll', 'Netflix', 'Disney+', 'Disney', '迪士尼',
		'Bilibili', 'bilibili', 'B站', '嗶哩嗶哩', '哔哩哔哩',
		'LINE TV', 'LINETV', '愛奇藝', 'iQIYI', '優酷', 'Youku',
		'YouTube', 'Amazon', 'Prime Video', 'Hulu', 'Abema', 'AbemaTV',
		'friDay', 'KKTV', 'myVideo', 'Hami Video', 'CATCHPLAY', 'Viu',
		'Ani-One', 'Muse', '木棉花', '巴哈姆特', '動畫瘋',
	);

	foreach ( $known as $kw ) {
		if ( '' === $kw ) {
			continue;
		}

		$in_text = $has_mb ? ( false !== mb_stripos( $text, $kw ) ) : ( false !== stripos( $text, $kw ) );

		if ( ! $in_text ) {
			continue;
		}

		$in_allowed = $has_mb ? ( false !== mb_stripos( $allowed, $kw ) ) : ( false !== stripos( $allowed, $kw ) );

		// 短評提到此平台，但欄位裡沒有 → 判定為幻覺。
		if ( '' === $allowed || ! $in_allowed ) {
			return $kw;
		}
	}

	// 籠統帶過的說法一律擋，避免模型用模糊字眼規避「只能用給定資料」。
	$vague = array( '其他合法平台', '其他串流平台', '各大串流', '各大平台', '正版平台', '合法平台觀看' );

	foreach ( $vague as $phrase ) {
		// 皆為 CJK 精確字串，strpos 於 UTF-8 位元組比對即可正確運作。
		if ( false !== strpos( $text, $phrase ) ) {
			return $phrase;
		}
	}

	return '';
}

/* ============================================================
 * 後台選單
 * ============================================================ */

/* ============================================================
 * 排序：季度輔助與子句產生
 * ============================================================ */

function wxacg_current_anime_season( $offset_quarters = 0 ) {
	$year  = (int) wp_date( 'Y' );
	$month = (int) wp_date( 'n' );

	$index  = (int) floor( ( $month - 1 ) / 3 );
	$index += (int) $offset_quarters;

	$year += (int) floor( $index / 4 );
	$index = ( ( $index % 4 ) + 4 ) % 4;

	$seasons = array( 'WINTER', 'SPRING', 'SUMMER', 'FALL' );

	return array(
		'year'   => $year,
		'season' => $seasons[ $index ],
	);
}

function wxacg_season_label( $season ) {
	$map = array(
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
		'AUTUMN' => '秋季',
	);

	$season = strtoupper( trim( (string) $season ) );

	return isset( $map[ $season ] ) ? $map[ $season ] : $season;
}

function wxacg_sort_choices() {
	$now  = wxacg_current_anime_season();
	$next = wxacg_current_anime_season( 1 );

	$now_text  = $now['year'] . ' ' . wxacg_season_label( $now['season'] );
	$next_text = $next['year'] . ' ' . wxacg_season_label( $next['season'] );

	return array(
		'season'      => '🔥 本季新番優先（' . $now_text . '，其餘依年份接續）',
		'season_only' => '🎯 只跑本季新番（' . $now_text . '）',
		'next_only'   => '🌱 只跑下季預熱（' . $next_text . '）',
		'recent'      => '🆕 最近匯入的頁面（依建立時間倒序）',
		'new'         => '📅 新番優先（年份＋季度倒序）',
		'airing'      => '📺 連載中優先',
		'gsc'         => '🔍 Google 曝光優先（Search Console 近 90 天）',
		'views'       => '👁️ 站內瀏覽數優先（近 60 天實際流量）',
		'popular'     => '⭐ 熱門優先（站內留言數及 AniList 評分）',
		'default'     => '🔢 預設順序（文章 ID）',
	);
}

/**
 * 取得 WP Statistics 的頁面統計資料表名稱。
 *
 * 外掛未安裝或資料表不存在時回傳空字串，讓呼叫端改用其他排序，
 * 避免組出參照不存在資料表的 SQL。
 *
 * @return string 資料表名稱，不存在時為空字串。
 */
function wxacg_stats_pages_table() {
	global $wpdb;

	static $table = null;

	if ( null !== $table ) {
		return $table;
	}

	$name  = $wpdb->prefix . 'statistics_pages';
	$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ? $name : '';

	return $table;
}

/**
 * 取得 Rank Math 同步下來的 Search Console 資料表名稱。
 *
 * 未連接 Search Console 或資料表不存在時回傳空字串。
 *
 * @return string 資料表名稱，不存在時為空字串。
 */
function wxacg_gsc_table() {
	global $wpdb;

	static $table = null;

	if ( null !== $table ) {
		return $table;
	}

	$name  = $wpdb->prefix . 'rank_math_analytics_gsc';
	$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ? $name : '';

	return $table;
}

/**
 * 產生批次查詢用的 SELECT／JOIN／HAVING／ORDER 子句。
 *
 * 注意：回傳的字串會被外層再次組進 $wpdb->prepare()，
 * 因此這裡一律不使用 prepare()，改以 sprintf + esc_sql 產生字面值，
 * 避免巢狀 prepare 誤把 % 當成佔位符。
 */
function wxacg_build_sort_clauses( $sort ) {
	global $wpdb;

	$now  = wxacg_current_anime_season();
	$next = wxacg_current_anime_season( 1 );

	$join_year = "LEFT JOIN {$wpdb->postmeta} pm_yr
	                     ON pm_yr.post_id = p.ID
	                    AND pm_yr.meta_key = 'anime_season_year'";

	$join_season = "LEFT JOIN {$wpdb->postmeta} pm_se
	                       ON pm_se.post_id = p.ID
	                      AND pm_se.meta_key = 'anime_season'";

	$join_status = "LEFT JOIN {$wpdb->postmeta} pm_st
	                       ON pm_st.post_id = p.ID
	                      AND pm_st.meta_key = 'anime_status'";

	$join_score = "LEFT JOIN {$wpdb->postmeta} pm_al
	                      ON pm_al.post_id = p.ID
	                     AND pm_al.meta_key = 'anime_score_anilist'";

	$sel_year = "MAX( CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) ) AS s_year";

	$sel_season = "MAX(
		FIELD( UPPER( TRIM( pm_se.meta_value ) ), 'WINTER', 'SPRING', 'SUMMER', 'FALL' )
	) AS s_season";

	$sel_airing = "MAX(
		CASE WHEN UPPER( TRIM( pm_st.meta_value ) ) = 'RELEASING' THEN 1 ELSE 0 END
	) AS s_airing";

	$sel_score = "MAX( CAST( NULLIF( pm_al.meta_value, '' ) AS DECIMAL(5,2) ) ) AS s_score";

	/*
	 * 站內留言數：本站留言存在 wxacg_review 自訂文章類型（以 post_parent
	 * 指向作品），不會寫回 wp_posts.comment_count。實測 1,498 篇動漫頁中
	 * comment_count > 0 的只有 10 篇，直接拿它排序等於沒有區分力，
	 * 因此改為即時統計 wxacg_review。
	 */
	$join_reviews = "LEFT JOIN {$wpdb->posts} rv
	                        ON rv.post_parent = p.ID
	                       AND rv.post_type = 'wxacg_review'
	                       AND rv.post_status = 'publish'";

	$sel_reviews = 'COUNT( DISTINCT rv.ID ) AS s_reviews';

	$sel_current = sprintf(
		"MAX(
			CASE
				WHEN CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) = %d
				 AND UPPER( TRIM( pm_se.meta_value ) ) = '%s' THEN 1
				ELSE 0
			END
		) AS s_current",
		(int) $now['year'],
		esc_sql( $now['season'] )
	);

	$sel_next = sprintf(
		"MAX(
			CASE
				WHEN CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) = %d
				 AND UPPER( TRIM( pm_se.meta_value ) ) = '%s' THEN 1
				ELSE 0
			END
		) AS s_next",
		(int) $next['year'],
		esc_sql( $next['season'] )
	);

	switch ( $sort ) {
		case 'season_only':
			return array(
				'select' => ', ' . $sel_current . ', ' . $sel_airing,
				'join'   => $join_year . ' ' . $join_season . ' ' . $join_status,
				'having' => 'HAVING s_current = 1',
				'order'  => 'ORDER BY s_airing DESC, p.comment_count DESC, p.ID DESC',
			);

		case 'next_only':
			return array(
				'select' => ', ' . $sel_next,
				'join'   => $join_year . ' ' . $join_season,
				'having' => 'HAVING s_next = 1',
				'order'  => 'ORDER BY p.comment_count DESC, p.ID DESC',
			);

		case 'recent':
			return array(
				'select' => '',
				'join'   => '',
				'having' => '',
				'order'  => 'ORDER BY p.post_date DESC, p.ID DESC',
			);

		case 'new':
			return array(
				'select' => ', ' . $sel_year . ', ' . $sel_season,
				'join'   => $join_year . ' ' . $join_season,
				'having' => '',
				'order'  => 'ORDER BY s_year DESC, s_season DESC, p.comment_count DESC, p.ID DESC',
			);

		case 'airing':
			return array(
				'select' => ', ' . $sel_airing . ', ' . $sel_year . ', ' . $sel_season,
				'join'   => $join_status . ' ' . $join_year . ' ' . $join_season,
				'having' => '',
				'order'  => 'ORDER BY s_airing DESC, s_year DESC, s_season DESC, p.comment_count DESC, p.ID DESC',
			);

		case 'popular':
			return array(
				'select' => ', ' . $sel_reviews . ', ' . $sel_score,
				'join'   => $join_reviews . ' ' . $join_score,
				'having' => '',
				'order'  => 'ORDER BY s_reviews DESC, s_score DESC, p.ID ASC',
			);

		case 'gsc':
			$gsc_table = wxacg_gsc_table();

			// 未連接 Search Console 時退回站內瀏覽數排序。
			if ( '' === $gsc_table ) {
				return wxacg_build_sort_clauses( 'views' );
			}

			/*
			 * Rank Math 的 page 欄位存的是路徑（例：/anime/oni-no-hanayome/），
			 * 因此以文章代稱組出相同格式比對。
			 *
			 * 為什麼用曝光數而非站內瀏覽數：兩者可能差距極大。實測「尼古喵喵」
			 * 站內 30,885 次瀏覽，但 Google 只帶進 165 次點擊、曝光 3,944；
			 * 而「鬼的新娘」站內僅 1,171 次，Google 曝光卻有 19,572。
			 * 要決定「先補哪篇短評對搜尋排名最有幫助」，曝光數才是對的訊號。
			 */
			$join_gsc = sprintf(
				"LEFT JOIN {$gsc_table} gs
				        ON gs.page = CONCAT( '/anime/', p.post_name, '/' )
				       AND gs.created >= '%s'",
				esc_sql( gmdate( 'Y-m-d', strtotime( '-90 days' ) ) )
			);

			return array(
				'select' => ', COALESCE( SUM( gs.impressions ), 0 ) AS s_impressions',
				'join'   => $join_gsc,
				'having' => '',
				'order'  => 'ORDER BY s_impressions DESC, p.ID ASC',
			);

		case 'views':
			$stats_table = wxacg_stats_pages_table();

			// 沒有 WP Statistics 資料表時退回熱門排序，避免無效 SQL。
			if ( '' === $stats_table ) {
				return array(
					'select' => ', ' . $sel_reviews . ', ' . $sel_score,
					'join'   => $join_reviews . ' ' . $join_score,
					'having' => '',
					'order'  => 'ORDER BY s_reviews DESC, s_score DESC, p.ID ASC',
				);
			}

			/*
			 * WP Statistics 的 type 欄位對自訂文章類型存的是
			 * 'post_type_anime'（非 'anime'），實測確認過。
			 */
			$join_views = sprintf(
				"LEFT JOIN {$stats_table} sp
				        ON sp.id = p.ID
				       AND sp.type = 'post_type_anime'
				       AND sp.date >= '%s'",
				esc_sql( gmdate( 'Y-m-d', strtotime( '-60 days' ) ) )
			);

			return array(
				'select' => ', COALESCE( SUM( sp.count ), 0 ) AS s_views',
				'join'   => $join_views,
				'having' => '',
				'order'  => 'ORDER BY s_views DESC, p.ID ASC',
			);

		case 'default':
			return array(
				'select' => '',
				'join'   => '',
				'having' => '',
				'order'  => 'ORDER BY p.ID ASC',
			);

		case 'season':
		default:
			return array(
				'select' => ', ' . $sel_current . ', ' . $sel_airing . ', ' . $sel_year . ', ' . $sel_season,
				'join'   => $join_year . ' ' . $join_season . ' ' . $join_status,
				'having' => '',
				'order'  => 'ORDER BY s_current DESC, s_airing DESC, s_year DESC, s_season DESC, p.comment_count DESC, p.ID DESC',
			);
	}
}

/**
 * 保護子句：跳過已完成人工審核的作品。
 *
 * ⚠️ 'published' 必須與 class-acf-fields.php 中 anime_editorial_status
 *    select 的實際 choice value 一致，否則保護會失效。
 */
function wxacg_protect_clause() {
	global $wpdb;

	return "
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm_pub
			WHERE pm_pub.post_id = p.ID
			  AND pm_pub.meta_key = 'anime_editorial_status'
			  AND pm_pub.meta_value = 'published'
		)
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm_aut
			WHERE pm_aut.post_id = p.ID
			  AND pm_aut.meta_key = 'anime_editorial_author_id'
			  AND TRIM( pm_aut.meta_value ) <> ''
			  AND TRIM( pm_aut.meta_value ) <> '0'
		)
	";
}

/**
 * 一般模式：只挑尚未有短評的作品。
 */
function wxacg_missing_clause() {
	global $wpdb;

	return "
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pmx
			WHERE pmx.post_id = p.ID
			  AND pmx.meta_key = '" . WXACG_EDITORIAL_META . "'
			  AND TRIM( pmx.meta_value ) <> ''
		)
	";
}

/**
 * 覆蓋模式：跳過「已用目前 prompt 版本重寫過」的作品。
 *
 * 有了這個水位標記，成功處理的項目會離開查詢集合，
 * 因此 offset 可維持 0，關掉瀏覽器隔天再跑也會從上次的斷點接續。
 */
function wxacg_overwrite_skip_clause() {
	global $wpdb;

	return $wpdb->prepare(
		"AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm_ver
			WHERE pm_ver.post_id = p.ID
			  AND pm_ver.meta_key = %s
			  AND pm_ver.meta_value = %s
		)",
		WXACG_PROMPT_VERSION_META,
		WXACG_EDITORIAL_PROMPT_VERSION
	);
}

/**
 * 取得本次模式使用的篩選子句。
 */
function wxacg_scope_clause( $overwrite = false ) {
	return $overwrite ? wxacg_overwrite_skip_clause() : wxacg_missing_clause();
}

/**
 * 尚待處理的已發佈動漫數量。
 *
 * @param string $sort      傳入排序模式時只計算該範圍。
 * @param bool   $protect   是否排除已人工審核的作品。
 * @param bool   $overwrite 覆蓋模式：改算「尚未用目前版本重寫」的數量。
 */
function wxacg_count_remaining( $sort = '', $protect = true, $overwrite = false ) {
	global $wpdb;

	$clauses = ( '' !== $sort )
		? wxacg_build_sort_clauses( $sort )
		: array(
			'select' => '',
			'join'   => '',
			'having' => '',
		);

	$protect_sql = $protect ? wxacg_protect_clause() : '';
	$scope_sql   = wxacg_scope_clause( $overwrite );

	$sql = "
		SELECT COUNT(*) FROM (
			SELECT p.ID {$clauses['select']}
			FROM {$wpdb->posts} p
			{$clauses['join']}
			WHERE p.post_type = 'anime'
			  AND p.post_status = 'publish'
			  {$scope_sql}
			  {$protect_sql}
			GROUP BY p.ID
			{$clauses['having']}
		) t
	";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $sql );
}

/* ============================================================
 * 後台選單
 * ============================================================ */

add_action( 'admin_menu', function () {
	add_submenu_page(
		'anime-sync-pro',
		'編輯短評控管',
		'✍️ 短評控管',
		'manage_options',
		'wxacg-ai-editorial',
		'wxacg_ai_editorial_page'
	);
}, 11 );

/* ============================================================
 * 給 AI 的短評指令
 *
 * 與 class-acf-fields.php 的 build_editorial_ai_prompt() 一致：
 * 一句話請 AI 產出，複製貼上後由編輯自行查證再貼回欄位。
 * ============================================================ */

/**
 * AniList 原作類型代碼 → 中文。
 */
function wxacg_editorial_source_label( $source ) {
	$map = array(
		'ORIGINAL'           => '動畫原創',
		'MANGA'              => '漫畫',
		'LIGHT_NOVEL'        => '輕小說',
		'NOVEL'              => '小說',
		'WEB_NOVEL'          => '網路小說',
		'VISUAL_NOVEL'       => '視覺小說',
		'VIDEO_GAME'         => '電子遊戲',
		'GAME'               => '遊戲',
		'DOUJINSHI'          => '同人作品',
		'ANIME'              => '動畫',
		'PICTURE_BOOK'       => '繪本',
		'COMIC'              => '漫畫',
		'MULTIMEDIA_PROJECT' => '多媒體企劃',
		'LIVE_ACTION'        => '真人作品',
		'OTHER'              => '',
	);

	$source = strtoupper( trim( (string) $source ) );

	return isset( $map[ $source ] ) ? $map[ $source ] : '';
}

/**
 * AniList 播放形式代碼 → 中文。
 */
function wxacg_editorial_format_label( $format ) {
	$map = array(
		'TV'       => 'TV 動畫',
		'TV_SHORT' => '短篇 TV 動畫',
		'MOVIE'    => '劇場版',
		'OVA'      => 'OVA',
		'ONA'      => 'ONA',
		'SPECIAL'  => '特別篇',
	);

	$format = strtoupper( trim( (string) $format ) );

	return isset( $map[ $format ] ) ? $map[ $format ] : '';
}

/**
 * 依作品輪替切入角度。
 *
 * ★ 為什麼需要：同一份指令必然產出同一種骨架。實際發生過——連續五篇短評
 *   四篇以「本作改編自…」起頭、三篇以「是一部…佳作」收尾，讀起來像同一
 *   個模子印出來的。指令再怎麼寫也擋不住這種同質化，因此改為依文章 ID
 *   分派不同的觀察角度，機械式打散骨架。
 *
 * @param int $post_id 文章 ID。
 * @return string
 */
function wxacg_editorial_angle( $post_id, $unreleased = false, $has_prequel = false ) {
	/*
	 * ★ 續作固定用「前作」角度，不參與輪替。
	 *
	 *   純輪替不看作品性質，實際出過問題：《劇場版「鬼滅之刃」無限城篇
	 *   第二章》被分到「製作班底」角度，結果整段在列 ufotable 的過往作品，
	 *   把作品名遮住後換成任何一部 ufotable 未播出作品都成立；而搜尋這部
	 *   的人最想知道的「第一章演到哪、第二章接哪一段」完全沒寫到。
	 *
	 *   續作的前作資訊同時滿足兩件事：回答搜尋者的實際疑問，而且是聚合
	 *   資料站不會寫的內容。價值高於任何輪替角度，因此直接指定。
	 */
	if ( $has_prequel ) {
		return $unreleased
			? '從前作切入：前一部結束在什麼地方、留下什麼懸念，這一部預計接續哪一段，'
				. '讓還沒跟上的讀者知道要不要補前作。'
			: '從前作切入：前一部結束在什麼地方、這一部接續哪一段、接得順不順，'
				. '讓還沒跟上的讀者知道要不要補前作。';
	}

	/*
	 * 未播出作品必須用另一套角度：作畫、節奏、演出這些都要看過才寫得出來，
	 * 沿用已播出的角度等於直接要求模型編造。
	 */
	$angles = $unreleased
		? array(
			'從原作切入：原作連載到哪，這次動畫預計改編哪一段。',
			'從前作切入：前一季（或前作）結束在什麼地方，留下什麼懸念。',
			'從製作班底切入：製作公司與主創過去做過什麼。',
			'從卡司切入：已公布的主要聲優陣容。',
			'從已公開情報切入：目前釋出的視覺圖或 PV 透露了什麼。',
		)
		: array(
			'從「這部在演什麼」切入，把故事的起點講清楚。',
			'從改編幅度切入：跟原作相比，動畫版做了什麼取捨。',
			'從角色關係切入：誰跟誰之間的張力撐起這部作品。',
			'從節奏切入：這部是慢熱還是一開場就抓人。',
			'從美術或演出切入：畫面上有什麼值得一提的地方。',
			'從觀眾期待切入：衝著什麼來看的人會滿意，衝著什麼來的人會失望。',
		);

	return $angles[ (int) $post_id % count( $angles ) ];
}

/**
 * 作品是否有前作（續作、續篇、後篇等）。
 *
 * @param int $post_id 文章 ID。
 * @return bool
 */
function wxacg_editorial_has_prequel( $post_id ) {
	$value = get_post_meta( (int) $post_id, 'anime_has_prequel', true );

	return '' !== trim( (string) $value ) && '0' !== trim( (string) $value );
}

/**
 * 作品是否尚未播出。
 *
 * @param int $post_id 文章 ID。
 * @return bool
 */
function wxacg_editorial_is_unreleased( $post_id ) {
	$status = strtoupper( trim( (string) get_post_meta( (int) $post_id, 'anime_status', true ) ) );

	return 'NOT_YET_RELEASED' === $status;
}

/**
 * 產生貼給 AI 用的撰寫指令。
 *
 * ★ 舊版只有一句「給我 X 的動漫短評」，沒有字數、風格或事實約束，
 *   模型只能退回預設的安全語氣，產出通篇空泛形容詞、句型高度重複的
 *   罐頭短評，而且冷門作品容易靠回憶硬掰。
 *
 *   現在改為：把站上已有的事實一起帶進指令（模型有依據，幻覺變少、
 *   內容更具體），明確禁止已觀察到的罐頭句型，並要求模型自我檢查
 *   「遮住作品名後是否還講得通」——這一句對消除通用廢話最有效。
 *
 * @param int $post_id 文章 ID。
 * @return string 指令全文，找不到文章時為空字串。
 */
function wxacg_editorial_prompt( $post_id ) {
	$post_id = (int) $post_id;
	$title   = trim( (string) get_the_title( $post_id ) );

	if ( $post_id <= 0 || '' === $title ) {
		return '';
	}

	$get = function ( $key ) use ( $post_id ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( is_array( $value ) ) {
			$value = implode( '、', array_filter( $value ) );
		}

		return trim( (string) $value );
	};

	$unreleased  = wxacg_editorial_is_unreleased( $post_id );
	$has_prequel = wxacg_editorial_has_prequel( $post_id );

	// 只列出真的有值的欄位，避免把「（空）」餵給模型當成事實。
	$facts = array();

	$year   = $get( 'anime_season_year' );
	$season = $get( 'anime_season' );

	if ( '' !== $year || '' !== $season ) {
		$facts[] = ( $unreleased ? '預定播出：' : '播出：' )
			. trim( $year . ' ' . ( '' !== $season ? wxacg_season_label( $season ) : '' ) );
	}

	if ( $unreleased ) {
		$facts[] = '狀態：尚未播出';
	}

	$format = wxacg_editorial_format_label( $get( 'anime_format' ) );
	if ( '' !== $format ) {
		$facts[] = '形式：' . $format;
	}

	$source = wxacg_editorial_source_label( $get( 'anime_source' ) );
	if ( '' !== $source ) {
		$facts[] = '原作：' . $source;
	}

	$studios = $get( 'anime_studios' );
	if ( '' !== $studios ) {
		$facts[] = '動畫製作：' . $studios;
	}

	$native = $get( 'anime_title_native' );
	if ( '' !== $native ) {
		$facts[] = '原文標題：' . $native;
	}

	// 劇場版的集數固定是 1，列出來只會讓模型寫成「共 1 集」，反而不自然。
	$episodes = (int) $get( 'anime_episodes' );
	if ( $episodes > 0 && 'MOVIE' !== strtoupper( $get( 'anime_format' ) ) ) {
		$facts[] = '集數：' . $episodes;
	}

	$min = wxacg_editorial_min_chars();

	$lines = array(
		'請幫我寫《' . $title . '》的動漫短評，給台灣讀者看。',
		'',
	);

	if ( $facts ) {
		$lines[] = '【已知資料】只能用這些，不確定的不要寫';
		foreach ( $facts as $fact ) {
			$lines[] = '- ' . $fact;
		}
		$lines[] = '';
	}

	// 條目會依作品狀態增減，改用陣列自動編號，避免手動維護號碼出錯。
	$reqs   = array();
	$reqs[] = '繁體中文、台灣用語，' . $min . '～240 字，一段到底不分段。';
	$reqs[] = wxacg_editorial_angle( $post_id, $unreleased, $has_prequel );
	$reqs[] = '開頭直接進入作品，不要用「本作改編自」「本作是」這類句子起頭。';

	if ( $unreleased ) {
		$reqs[] = '這部尚未播出，開頭要讓讀者知道它還沒播，以及預定什麼時候。';
		$reqs[] = "只寫查證得到的事實作為期待理由：原作連載進度、前作評價、\n"
			. '   製作班底過去的作品、已公布的卡司。不確定的一律不要寫。';
		$reqs[] = '結尾說「什麼樣的人該追蹤這部」，不要用「是一部…的佳作」收尾。';
	} else {
		$reqs[] = '必須寫出一個具體的場景、設定或橋段，讓人看得出你知道這部在演什麼。';
		$reqs[] = '必須有一句主觀判斷：哪裡做得好，或哪裡可能讓人看不下去。';
		$reqs[] = '結尾說「什麼樣的人適合看」，不要用「是一部…的佳作」收尾。';
	}

	$lines[] = '【要求】';

	foreach ( $reqs as $i => $req ) {
		$lines[] = ( $i + 1 ) . '. ' . $req;
	}

	$lines[] = '';

	$lines[] = '【禁止】';

	if ( $unreleased ) {
		/*
		 * ★ 未播出作品不能沿用已播出的要求。原本一律要求「寫出具體場景」
		 *   與「給出主觀判斷」，但作品都還沒播，這兩條等於直接命令模型
		 *   編造劇情與演出評價。全站有 187 部處於此狀態，且 Google 曝光
		 *   前段班就有好幾部，影響不小。
		 */
		$lines[] = '- 描述任何劇情場景、戰鬥畫面、演出效果或作畫表現（作品還沒播，你沒看過）';
		$lines[] = '- 對成品品質下判斷（好不好看目前無人知道）';
		$lines[] = '- 編造上映日期、集數或播出平台';
	} else {
		$lines[] = '- 劇透結局';
	}

	$lines[] = '- 「不僅…更…」「在保持…的同時」這類轉折句型';
	$lines[] = '- 空泛形容詞堆疊（極具魅力、天馬行空、充滿感動、令人動容）';
	$lines[] = '- 任何串流平台名稱（系統會擋下來）';
	$lines[] = '';
	$lines[] = '【自我檢查】';
	$lines[] = '寫完把作品名遮住再讀一次。如果換成同類型的另一部作品也講得通，重寫。';

	if ( $unreleased ) {
		$lines[] = '再檢查一次：整段裡有沒有任何一句是「看過才寫得出來」的？有就刪掉。';
	}

	return implode( "\n", $lines );
}

/* ============================================================
 * 短評存檔
 *
 * 與 class-acf-fields.php 的 auto_assign_editorial_reviewer() 行為一致：
 * 短評內容有變更時，自動帶入審核者、審核日期並標記為已發布。
 * 該 hook 掛在 acf/save_post，本頁不經 ACF 表單送出，因此在這裡
 * 明確補上相同欄位，避免兩條路徑存出來的資料不一致。
 * ============================================================ */

function wxacg_editorial_save_summary( $post_id, $summary ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 || get_post_type( $post_id ) !== 'anime' ) {
		return new WP_Error( 'bad_post', '找不到這篇動漫' );
	}

	$before = trim( wxacg_editorial_text( $post_id ) );

	/*
	 * ★ 不可改用 wxacg_normalize_editorial_text()：那是為 AI 產出設計的，
	 *   內容空白或字數超出 90～240 時會回傳 WP_Error。先前這裡直接把它的
	 *   回傳值寫進資料庫，於是「清空短評再儲存」會把 WP_Error 物件存成
	 *   短評，前台 (string) 轉型即致命錯誤、整頁 500。
	 *
	 *   人工編輯的規則不同：清空是正常操作，字數只給建議不強制。因此改為
	 *   在這裡自行清理，且保證寫入的一定是字串。
	 */
	$after = sanitize_textarea_field( wp_strip_all_tags( (string) $summary ) );
	$after = preg_replace( "/\R{3,}/u", "\n\n", $after );
	$after = trim( $after );

	// 上限僅防呆，避免異常長度灌爆欄位；正常撰寫遠不會觸及
	if ( wxacg_editorial_strlen( $after ) > 2000 ) {
		return new WP_Error( 'too_long', '短評過長（上限 2000 字）' );
	}

	if ( $before === $after ) {
		return array(
			'changed' => false,
			'length'  => wxacg_editorial_strlen( $after ),
		);
	}

	wxacg_update_acf_meta( $post_id, WXACG_EDITORIAL_META, $after );

	// 清空短評：一併清掉審核資訊，避免留下「已發布但沒內容」的狀態。
	if ( '' === $after ) {
		wxacg_delete_acf_meta( $post_id, 'anime_editorial_author_id' );
		wxacg_delete_acf_meta( $post_id, 'anime_editorial_author' );
		wxacg_delete_acf_meta( $post_id, 'anime_editorial_reviewed_at' );
		wxacg_delete_acf_meta( $post_id, 'anime_editorial_status' );

		return array(
			'changed'  => true,
			'length'   => 0,
			'cleared'  => true,
		);
	}

	$user_id = get_current_user_id();

	if ( $user_id > 0 ) {
		wxacg_update_acf_meta( $post_id, 'anime_editorial_author_id', $user_id );

		$user = get_userdata( $user_id );
		if ( $user ) {
			wxacg_update_acf_meta( $post_id, 'anime_editorial_author', $user->display_name );
		}
	}

	wxacg_update_acf_meta( $post_id, 'anime_editorial_reviewed_at', current_time( 'Ymd' ) );
	wxacg_update_acf_meta( $post_id, 'anime_editorial_status', 'published' );

	return array(
		'changed' => true,
		'length'  => wxacg_editorial_strlen( $after ),
	);
}

/* ============================================================
 * AJAX：就地儲存單篇短評
 * ============================================================ */

add_action( 'wp_ajax_wxacg_editorial_save', 'wxacg_editorial_save_handler' );

function wxacg_editorial_save_handler() {
	check_ajax_referer( 'wxacg_editorial_save' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '權限不足' ) );
	}

	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	$summary = isset( $_POST['summary'] ) ? wp_unslash( $_POST['summary'] ) : '';

	$result = wxacg_editorial_save_summary( $post_id, $summary );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	// 平台幻覺防呆：短評提到欄位以外的串流平台時提醒，但不阻擋儲存。
	$warning = '';
	$allowed = get_post_meta( $post_id, 'anime_tw_streaming', true );
	if ( $allowed && function_exists( 'wxacg_editorial_platform_guard' ) ) {
		$guard = wxacg_editorial_platform_guard( (string) $summary, (array) $allowed );
		if ( is_array( $guard ) && ! empty( $guard['bad'] ) ) {
			$warning = '注意：內文出現未列於本作串流欄位的平台名稱（'
				. implode( '、', array_slice( (array) $guard['bad'], 0, 3 ) ) . '）';
		}
	}

	wp_send_json_success(
		array(
			'changed'   => ! empty( $result['changed'] ),
			'cleared'   => ! empty( $result['cleared'] ),
			'length'    => (int) ( $result['length'] ?? 0 ),
			'warning'   => $warning,
			'reviewer'  => wp_get_current_user()->display_name,
			'reviewed'  => date_i18n( 'Y-m-d' ),
		)
	);
}

/* ============================================================
 * 控管頁面
 * ============================================================ */

function wxacg_ai_editorial_page() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '權限不足' );
	}

	global $wpdb;

	$filter   = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'missing';
	$sort     = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'season';
	$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$per_page = 30;

	$sort_choices = wxacg_sort_choices();
	if ( ! isset( $sort_choices[ $sort ] ) ) {
		$sort = 'season';
	}

	$filter_choices = array(
		'missing' => '待撰寫（尚無短評）',
		'has'     => '已有短評',
		'all'     => '全部作品',
	);
	if ( ! isset( $filter_choices[ $filter ] ) ) {
		$filter = 'missing';
	}

	// ── 統計 ──
	$total_anime = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'anime' AND post_status = 'publish'"
	);
	$has_summary = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
			   AND m.meta_key = %s AND m.meta_value <> ''",
			WXACG_EDITORIAL_META
		)
	);
	$need_write = max( 0, $total_anime - $has_summary );

	// ── 清單查詢 ──
	$where = "p.post_type = 'anime' AND p.post_status = 'publish'";

	if ( 'missing' === $filter ) {
		$where .= $wpdb->prepare(
			" AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} mm
				WHERE mm.post_id = p.ID AND mm.meta_key = %s AND mm.meta_value <> ''
			)",
			WXACG_EDITORIAL_META
		);
	} elseif ( 'has' === $filter ) {
		$where .= $wpdb->prepare(
			" AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} mm
				WHERE mm.post_id = p.ID AND mm.meta_key = %s AND mm.meta_value <> ''
			)",
			WXACG_EDITORIAL_META
		);
	}

	$clauses = wxacg_build_sort_clauses( $sort );
	$join    = isset( $clauses['join'] ) ? $clauses['join'] : '';
	$select  = isset( $clauses['select'] ) ? $clauses['select'] : '';
	$having  = isset( $clauses['having'] ) ? $clauses['having'] : '';

	/*
	 * ★ 這裡原本讀 $clauses['orderby']，但 wxacg_build_sort_clauses() 回傳的
	 *   鍵是 'order'，判斷永遠不成立，導致不管選哪個排序都退回
	 *   p.post_date DESC——後台的排序下拉選單等於完全失效。
	 *
	 *   'order' 的值本身已含 "ORDER BY " 前綴（供其他呼叫端直接串接），
	 *   這裡剝掉前綴再組進 SQL，避免變成 ORDER BY ORDER BY。
	 */
	$order_by = 'p.post_date DESC';

	if ( ! empty( $clauses['order'] ) ) {
		$order_by = preg_replace( '/^\s*ORDER\s+BY\s+/i', '', $clauses['order'] );
	}

	if ( ! empty( $clauses['where'] ) ) {
		$where .= ' AND ' . $clauses['where'];
	}

	$found = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} WHERE {$where}"
	);

	$offset = ( $paged - 1 ) * $per_page;

	/*
	 * 排序用的別名（s_year、s_score、s_views…）都是聚合函式，必須同時
	 * 放進 SELECT 並搭配 GROUP BY，ORDER BY 才引用得到。原本兩者皆缺，
	 * 就算鍵名修對也會是無效 SQL。寫法與 wxacg_count_remaining() 一致。
	 */
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title {$select}
			 FROM {$wpdb->posts} p {$join}
			 WHERE {$where}
			 GROUP BY p.ID, p.post_title
			 {$having}
			 ORDER BY {$order_by}
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		)
	);

	$total_pages = $per_page > 0 ? (int) ceil( $found / $per_page ) : 1;
	$base_url    = admin_url( 'admin.php?page=wxacg-ai-editorial' );
	?>
	<div class="wrap">
		<h1>✍️ 編輯短評控管</h1>

		<p style="color:#666;">
			在這裡直接撰寫或修改各作品的編輯短評（ACF 欄位
			<code><?php echo esc_html( WXACG_EDITORIAL_META ); ?></code>），
			不用逐篇進文章編輯頁。儲存後會自動帶入審核者、審核日期並標記為
			<strong>已發布</strong>，與文章編輯頁的行為一致。
		</p>
		<p style="color:#666;">
			💡 建議 <?php echo (int) wxacg_editorial_min_chars(); ?>～240 字。
			未達 <strong><?php echo (int) wxacg_editorial_min_chars(); ?> 字</strong>
			的短評雖然能讓頁面被 Google 收錄，但不符合廣告顯示資格。
			短評請自行查證後再貼上，避免錯誤資訊。
		</p>

		<div style="display:flex;gap:14px;margin:20px 0;flex-wrap:wrap;">
			<?php
			$cards = array(
				array( 'label' => '動漫總數', 'value' => $total_anime, 'color' => '#2271b1' ),
				array( 'label' => '已有短評', 'value' => $has_summary, 'color' => '#00a32a' ),
				array(
					'label' => '待撰寫',
					'value' => $need_write,
					'color' => $need_write > 0 ? '#d63638' : '#00a32a',
				),
			);
			foreach ( $cards as $card ) :
				?>
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 22px;text-align:center;min-width:120px;box-shadow:0 1px 3px rgba(0,0,0,.07);">
					<div style="font-size:30px;font-weight:700;color:<?php echo esc_attr( $card['color'] ); ?>;">
						<?php echo esc_html( number_format_i18n( $card['value'] ) ); ?>
					</div>
					<div style="color:#555;margin-top:4px;font-size:13px;">
						<?php echo esc_html( $card['label'] ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<form method="get" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin-bottom:18px;">
			<input type="hidden" name="page" value="wxacg-ai-editorial">
			<label style="margin-right:16px;">
				顯示
				<select name="filter">
					<?php foreach ( $filter_choices as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="margin-right:16px;">
				排序
				<select name="sort">
					<?php foreach ( $sort_choices as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $sort, $k ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button">套用</button>
			<span style="margin-left:12px;color:#666;">
				符合條件：<strong><?php echo esc_html( number_format_i18n( $found ) ); ?></strong> 部
			</span>
		</form>

		<?php if ( ! $rows ) : ?>
			<div class="notice notice-success" style="padding:14px;">
				<p style="margin:0;">目前條件下沒有待處理的作品 🎉</p>
			</div>
		<?php else : ?>

			<table class="wp-list-table widefat fixed striped" id="wxacg-editorial-list">
				<thead>
					<tr>
						<th style="width:210px;">作品</th>
						<th>編輯短評</th>
						<th style="width:62px;">字數</th>
						<th style="width:150px;">審核狀態</th>
						<th style="width:120px;">操作</th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $rows as $row ) :
					$pid     = (int) $row->ID;
					$summary = wxacg_editorial_text( $pid );
					$title   = get_post_meta( $pid, 'anime_title_chinese', true );
					$title   = $title ? $title : $row->post_title;
					$len     = wxacg_editorial_strlen( $summary );

					$reviewer = get_post_meta( $pid, 'anime_editorial_author', true );
					$reviewed = get_post_meta( $pid, 'anime_editorial_reviewed_at', true );
					$year     = get_post_meta( $pid, 'anime_season_year', true );
					$season   = get_post_meta( $pid, 'anime_season', true );
					?>
					<tr class="wxacg-ed-item" data-post="<?php echo esc_attr( $pid ); ?>">
						<td>
							<strong style="display:block;line-height:1.4;"><?php echo esc_html( $title ); ?></strong>
							<span style="color:#888;font-size:11px;">
								<?php
								echo esc_html(
									trim( ( $year ? $year . ' ' : '' ) . ( $season ? wxacg_season_label( $season ) : '' ) )
									?: '—'
								);
								?>
							</span>
						</td>
						<td>
							<textarea class="wxacg-ed-text" rows="2"
								style="width:100%;font-size:13px;line-height:1.6;"
								placeholder="撰寫編輯短評（建議 <?php echo (int) wxacg_editorial_min_chars(); ?>～240 字）"><?php
								echo esc_textarea( $summary );
							?></textarea>
							<div style="display:flex;align-items:center;gap:8px;margin-top:3px;">
								<button type="button" class="button button-small wxacg-ed-copy"
									data-prompt="<?php echo esc_attr( wxacg_editorial_prompt( $pid ) ); ?>"
									title="複製後貼到 AI 對話視窗，產出的內容請自行查證再貼回左邊欄位">📋 複製指令</button>
								<span class="wxacg-ed-msg" style="font-size:11px;min-height:14px;"></span>
							</div>
						</td>
						<td>
							<span class="wxacg-ed-count" style="color:#666;font-size:12px;">
								<?php echo esc_html( $len ); ?>
							</span>
						</td>
						<td style="font-size:11px;color:#666;line-height:1.5;">
							<?php if ( $reviewer ) : ?>
								<span style="color:#00a32a;">✓ 已發布</span><br>
								<?php echo esc_html( $reviewer ); ?>
								<?php echo $reviewed ? '<br>' . esc_html( $reviewed ) : ''; ?>
							<?php else : ?>
								<span style="color:#d63638;">待撰寫</span>
							<?php endif; ?>
						</td>
						<td>
							<button type="button" class="button button-primary button-small wxacg-ed-save"
								style="width:100%;margin-bottom:4px;">儲存</button>
							<a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>"
								class="button button-small" style="width:100%;text-align:center;"
								target="_blank">完整編輯</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'filter' => $filter,
									'sort'   => $sort,
									'paged'  => '%#%',
								),
								$base_url
							),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					);
					?>
				</div></div>
			<?php endif; ?>

		<?php endif; ?>
	</div>

	<script>
	jQuery(function ($) {
		var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'wxacg_editorial_save' ) ); ?>;

		// 即時字數；120～160 字為建議區間，超出以顏色提示
		$(document).on('input', '.wxacg-ed-text', function () {
			var $item = $(this).closest('.wxacg-ed-item');
			var n     = [...$(this).val()].length;
			$item.find('.wxacg-ed-count')
				.text(n)
				.css('color', (n === 0 || (n >= 120 && n <= 160)) ? '#666' : '#b26b00');
		});

		// 聚焦時放大編輯區，方便撰寫；離開後收合維持清單緊湊
		$(document).on('focus', '.wxacg-ed-text', function () {
			$(this).attr('rows', 6);
		}).on('blur', '.wxacg-ed-text', function () {
			$(this).attr('rows', 2);
		});

		// 複製指令：貼到 AI 對話視窗用
		$(document).on('click', '.wxacg-ed-copy', function () {
			var $btn = $(this);
			var $msg = $btn.closest('td').find('.wxacg-ed-msg');
			var text = $btn.data('prompt') || '';

			if (!text) {
				$msg.css('color', '#d63638').text('這部作品沒有標題，無法產生指令');
				return;
			}

			var done = function () {
				$btn.text('✅ 已複製');
				$msg.css('color', '#666').text('貼給 AI → 查證後把短評貼回左邊欄位');
				setTimeout(function () { $btn.text('📋 複製指令'); }, 1500);
			};

			var fallback = function () {
				var $tmp = $('<textarea>').val(text).css({position:'fixed', opacity:0}).appendTo('body');
				$tmp[0].select();
				try { document.execCommand('copy'); done(); }
				catch (e) { $msg.css('color', '#d63638').text('複製失敗，請手動複製'); }
				$tmp.remove();
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done).catch(fallback);
			} else {
				fallback();
			}
		});

		$(document).on('click', '.wxacg-ed-save', function () {
			var $btn   = $(this);
			var $item  = $btn.closest('.wxacg-ed-item');
			var $msg   = $item.find('.wxacg-ed-msg');
			var postId = $item.data('post');
			var text   = $item.find('.wxacg-ed-text').val();

			$btn.prop('disabled', true).text('儲存中…');
			$msg.css('color', '#666').text('');

			$.post(ajaxurl, {
				action:   'wxacg_editorial_save',
				_ajax_nonce: ajaxNonce,
				post_id:  postId,
				summary:  text
			}).done(function (res) {
				if (!res || !res.success) {
					$msg.css('color', '#d63638')
						.text('✕ ' + ((res && res.data && res.data.message) || '儲存失敗'));
					return;
				}
				var d = res.data || {};
				if (!d.changed) {
					$msg.css('color', '#666').text('內容沒有變更');
				} else if (d.cleared) {
					$msg.css('color', '#d63638').text('✓ 已清空並移除審核資訊');
					$item.find('td').eq(3).html('<span style="color:#d63638;">待撰寫</span>');
				} else {
					$msg.css('color', '#00a32a').text('✓ 已儲存');
					// 同步更新審核狀態欄，不必重整頁面
					$item.find('td').eq(3).html(
						'<span style="color:#00a32a;">✓ 已發布</span><br>' +
						$('<div>').text(d.reviewer).html() + '<br>' +
						$('<div>').text(d.reviewed).html()
					);
				}
				if (d.warning) {
					$msg.css('color', '#b26b00').append(document.createTextNode('　' + d.warning));
				}
			}).fail(function () {
				$msg.css('color', '#d63638').text('✕ 網路錯誤，請重試');
			}).always(function () {
				$btn.prop('disabled', false).text('儲存');
			});
		});
	});
	</script>
	<?php
}
