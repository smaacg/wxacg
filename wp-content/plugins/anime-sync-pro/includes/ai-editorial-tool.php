<?php
/**
 * 微笑動漫 — AI 編輯短評批次產生工具
 *
 * Path: wp-content/plugins/anime-sync-pro/includes/ai-editorial-tool.php
 *
 * @version 1.6.0 (2026-08-12)
 *
 * v1.6.0:
 *         1) AI 短評改寫入 anime_editor_summary，不再寫入 anime_editorial_note
 *         2) AI 產出一律標記為待人工審核草稿：
 *            - anime_editorial_status = draft
 *            - anime_editorial_ai_generated = 1
 *            - anime_editorial_ai_needs_review = 1
 *            - anime_editorial_ai_model
 *            - anime_editorial_prompt_version
 *            - anime_editorial_ai_generated_at
 *         3) 不自動填寫人工作者及人工審核日期
 *         4) 覆蓋既有摘要時清除舊人工審核資訊，避免新 AI 草稿被誤認為已審核
 *         5) API Key 不再以明文回填至後台 textarea
 *            - 留空儲存代表保留既有 Key
 *            - 另提供明確的「清除全部 API Keys」按鈕
 *         6) Gemini 回傳內容增加清理與長度檢查
 *         7) 增加 PHP 7.2 與無 mbstring 環境的字串相容函式
 *         8) 查詢、統計與非覆蓋模式全面改用 anime_editor_summary
 *         9) Key 顯示編號改為從 1 開始
 *
 * v1.5.0: 配額處理大改 + 穩定性修正
 *         1) 429 分類：解析 error.details 內的 QuotaFailure.quotaId 與 RetryInfo.retryDelay，
 *            區分「分鐘級（PerMinute）」「每日級（PerDay）」「不明」三種
 *         2) 每把 key 各自記錄冷卻到期時間（存於 option，不存明文 key，只存 md5 指紋）：
 *            - 分鐘級 → 冷卻 retryDelay 秒（5～60 秒）
 *            - 每日級 → 直接停用到「太平洋時間隔日午夜」（Google RPD 重置點）
 *            - 不明   → 只冷卻 15 分鐘
 *         3) 冷卻中的 key 在挑選階段直接跳過
 *         4) 全部 key 皆今日配額用盡時回傳 daily_exhausted
 *         5) 後台顯示每把 key 的即時狀態並可清除冷卻紀錄
 *         6) 新增每筆節流設定
 *         7) 修正非覆蓋模式 next_offset
 *         8) 修正 ONLY_FULL_GROUP_BY 相容性
 *         9) 新增單次請求時間預算
 * v1.4.0: 支援多把 Gemini API Key 輪替
 * v1.3.0: 加入 429 重試與 rate_limited 旗標
 * v1.2.0: 改用 gemini-3.6-flash + thinkingConfig
 * v1.1.0: 相容 PHP 7.2+，AJAX 錯誤改以 JSON 回傳
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
 * 常數
 * ============================================================ */

if ( ! defined( 'WXACG_GEMINI_MODEL' ) ) {
	define( 'WXACG_GEMINI_MODEL', 'gemini-3.6-flash' );
}

if ( ! defined( 'WXACG_EDITORIAL_PROMPT_VERSION' ) ) {
	define( 'WXACG_EDITORIAL_PROMPT_VERSION', '1.6.0' );
}

if ( ! defined( 'WXACG_BATCH_TIME_BUDGET' ) ) {
	define( 'WXACG_BATCH_TIME_BUDGET', 25 );
}

if ( ! defined( 'WXACG_COOLDOWN_OPTION' ) ) {
	define( 'WXACG_COOLDOWN_OPTION', 'wxacg_gemini_key_cooldowns' );
}

/* ============================================================
 * PHP 7.2／mbstring 相容工具
 * ============================================================ */

/**
 * 取得 UTF-8 字串長度。
 *
 * mbstring 不存在時使用 WordPress 相容函式，最後才退回 strlen。
 */
function wxacg_editorial_strlen( $text ) {
	$text = (string) $text;

	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( $text, 'UTF-8' );
	}

	if ( function_exists( 'wp_strlen' ) ) {
		return (int) wp_strlen( $text );
	}

	return (int) strlen( $text );
}

/**
 * 截取 UTF-8 字串。
 */
function wxacg_editorial_substr( $text, $start, $length = null ) {
	$text  = (string) $text;
	$start = (int) $start;

	if ( function_exists( 'mb_substr' ) ) {
		if ( null === $length ) {
			return mb_substr( $text, $start, null, 'UTF-8' );
		}

		return mb_substr( $text, $start, (int) $length, 'UTF-8' );
	}

	if ( function_exists( 'wp_html_excerpt' ) && 0 === $start && null !== $length ) {
		return wp_html_excerpt( $text, (int) $length, '' );
	}

	if ( null === $length ) {
		return substr( $text, $start );
	}

	return substr( $text, $start, (int) $length );
}

/**
 * 清理 Gemini 回傳的編輯短評。
 *
 * @return string|WP_Error
 */
function wxacg_normalize_editorial_text( $text ) {
	$text = is_scalar( $text ) ? (string) $text : '';
	$text = trim( $text );

	// 移除偶發的 Markdown code fence。
	$text = preg_replace( '/^\s*```(?:text|markdown|md)?\s*/iu', '', $text );
	$text = preg_replace( '/\s*```\s*$/u', '', $text );

	// 移除模型偶發加入的標題前綴。
	$text = preg_replace(
		'/^\s*(?:編輯短評|短評|評論|本文|輸出)\s*[：:]\s*/u',
		'',
		$text
	);

	// 移除整段最外層引號。
	$text = preg_replace( '/^\s*[「『“"]\s*/u', '', $text );
	$text = preg_replace( '/\s*[」』”"]\s*$/u', '', $text );

	$text = wp_strip_all_tags( $text );
	$text = sanitize_textarea_field( $text );

	// 合併過多空白與換行，保留最多兩段。
	$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
	$text = preg_replace( "/\R{3,}/u", "\n\n", $text );
	$text = trim( $text );

	if ( '' === $text ) {
		return new WP_Error( 'gemini_empty', 'Gemini 回傳空白內容。' );
	}

	$length = wxacg_editorial_strlen( preg_replace( '/\s+/u', '', $text ) );

	// Prompt 目標為 80～130 字；保留合理容錯，異常輸出則拒絕寫入。
	if ( $length < 50 ) {
		return new WP_Error(
			'gemini_too_short',
			sprintf( 'Gemini 短評過短（%d 字），未寫入資料庫。', $length )
		);
	}

	if ( $length > 180 ) {
		return new WP_Error(
			'gemini_too_long',
			sprintf( 'Gemini 短評過長（%d 字），未寫入資料庫。', $length )
		);
	}

	return $text;
}

/* ============================================================
 * 後台選單
 * ============================================================ */

add_action( 'admin_menu', function () {
	add_submenu_page(
		'anime-sync-pro',
		'AI 編輯短評產生器',
		'✍️ AI 短評產生',
		'manage_options',
		'wxacg-ai-editorial',
		'wxacg_ai_editorial_page'
	);
}, 11 );

/* ============================================================
 * API Key 取得
 * ============================================================ */

function wxacg_get_gemini_api_keys() {
	$keys = get_option( 'wxacg_gemini_api_keys', array() );

	// 相容舊版單一 Key 選項。
	if ( empty( $keys ) ) {
		$legacy = get_option( 'wxacg_gemini_api_key', '' );

		if ( ! empty( $legacy ) ) {
			$keys = array( $legacy );
		}
	}

	if ( ! is_array( $keys ) ) {
		$keys = array();
	}

	$keys = array_map( 'trim', $keys );
	$keys = array_filter( $keys );

	return array_values( array_unique( $keys ) );
}

/**
 * 只顯示 Key 前後少量字元，不洩漏完整 Key。
 */
function wxacg_mask_gemini_api_key( $key ) {
	$key = trim( (string) $key );

	if ( '' === $key ) {
		return '';
	}

	$length = strlen( $key );

	if ( $length <= 10 ) {
		return substr( $key, 0, 2 ) . '••••••';
	}

	return substr( $key, 0, 6 ) . '••••••••' . substr( $key, -4 );
}

/* ============================================================
 * Key 冷卻狀態管理
 * ============================================================ */

/**
 * 只存指紋，不把明文 Key 寫進冷卻紀錄。
 */
function wxacg_gemini_key_fp( $key ) {
	return substr( md5( (string) $key ), 0, 12 );
}

/**
 * 取得目前冷卻中的 Key，並清除已過期紀錄。
 */
function wxacg_get_key_cooldowns() {
	$data = get_option( WXACG_COOLDOWN_OPTION, array() );

	if ( ! is_array( $data ) ) {
		$data = array();
	}

	$now     = time();
	$changed = false;

	foreach ( $data as $fp => $row ) {
		if (
			! is_array( $row ) ||
			empty( $row['until'] ) ||
			(int) $row['until'] <= $now
		) {
			unset( $data[ $fp ] );
			$changed = true;
		}
	}

	if ( $changed ) {
		update_option( WXACG_COOLDOWN_OPTION, $data, false );
	}

	return $data;
}

/**
 * 設定某把 Key 的冷卻；若已有更晚的到期時間，不縮短。
 */
function wxacg_set_key_cooldown( $key, $until_ts, $reason ) {
	$data = wxacg_get_key_cooldowns();
	$fp   = wxacg_gemini_key_fp( $key );

	if (
		isset( $data[ $fp ]['until'] ) &&
		(int) $data[ $fp ]['until'] > (int) $until_ts
	) {
		return;
	}

	$data[ $fp ] = array(
		'until'  => (int) $until_ts,
		'reason' => sanitize_key( $reason ),
		'set_at' => time(),
	);

	update_option( WXACG_COOLDOWN_OPTION, $data, false );
}

function wxacg_key_is_cooling( $key, $cooldowns ) {
	$fp = wxacg_gemini_key_fp( $key );

	return (
		isset( $cooldowns[ $fp ]['until'] ) &&
		(int) $cooldowns[ $fp ]['until'] > time()
	);
}

/**
 * 取得下一個太平洋時間午夜。
 */
function wxacg_next_pacific_midnight() {
	try {
		$tz   = new DateTimeZone( 'America/Los_Angeles' );
		$now  = new DateTime( 'now', $tz );
		$next = new DateTime( $now->format( 'Y-m-d' ) . ' 00:00:00', $tz );

		$next->modify( '+1 day' );

		return $next->getTimestamp();
	} catch ( Exception $e ) {
		return time() + ( 3 * HOUR_IN_SECONDS );
	}
}

/**
 * 分類 Gemini 429 錯誤。
 *
 * @return array
 */
function wxacg_classify_quota_error( $decoded, $raw_body ) {
	$out = array(
		'scope'       => 'unknown',
		'retry_after' => 0,
		'quota_id'    => '',
	);

	$details = (
		isset( $decoded['error']['details'] ) &&
		is_array( $decoded['error']['details'] )
	)
		? $decoded['error']['details']
		: array();

	foreach ( $details as $detail ) {
		if ( ! is_array( $detail ) ) {
			continue;
		}

		$type = isset( $detail['@type'] ) ? (string) $detail['@type'] : '';

		if (
			false !== strpos( $type, 'RetryInfo' ) &&
			! empty( $detail['retryDelay'] )
		) {
			$out['retry_after'] = (int) ceil( (float) $detail['retryDelay'] );
		}

		if (
			false !== strpos( $type, 'QuotaFailure' ) &&
			! empty( $detail['violations'] ) &&
			is_array( $detail['violations'] )
		) {
			foreach ( $detail['violations'] as $violation ) {
				if ( ! is_array( $violation ) ) {
					continue;
				}

				$quota_id = isset( $violation['quotaId'] )
					? (string) $violation['quotaId']
					: '';

				$metric = isset( $violation['quotaMetric'] )
					? (string) $violation['quotaMetric']
					: '';

				if ( '' !== $quota_id ) {
					$out['quota_id'] = $quota_id;
				}

				$haystack = strtolower( $quota_id . ' ' . $metric );

				if (
					false !== strpos( $haystack, 'perday' ) ||
					false !== strpos( $haystack, 'per_day' )
				) {
					$out['scope'] = 'day';
				} elseif (
					'day' !== $out['scope'] &&
					(
						false !== strpos( $haystack, 'perminute' ) ||
						false !== strpos( $haystack, 'per_minute' )
					)
				) {
					$out['scope'] = 'minute';
				}
			}
		}
	}

	// details 缺漏時的字串後備判斷。
	if ( 'unknown' === $out['scope'] ) {
		$lower_body = strtolower( (string) $raw_body );

		if (
			false !== strpos( $lower_body, 'per day' ) ||
			false !== strpos( $lower_body, 'perday' ) ||
			false !== strpos( $lower_body, 'daily' )
		) {
			$out['scope'] = 'day';
		} elseif ( $out['retry_after'] > 0 ) {
			$out['scope'] = 'minute';
		}
	}

	return $out;
}

/**
 * 產生後台顯示用的 Key 狀態。
 */
function wxacg_build_key_status( $api_keys ) {
	$cooldowns = wxacg_get_key_cooldowns();
	$ready     = 0;
	$daily     = 0;
	$lines     = array();
	$soonest   = 0;

	foreach ( $api_keys as $index => $key ) {
		$fp      = wxacg_gemini_key_fp( $key );
		$display = $index + 1;

		if ( ! isset( $cooldowns[ $fp ] ) ) {
			$ready++;
			$lines[] = sprintf(
				'Key #%d（%s）：✅ 可用',
				$display,
				wxacg_mask_gemini_api_key( $key )
			);
			continue;
		}

		$until  = (int) $cooldowns[ $fp ]['until'];
		$reason = isset( $cooldowns[ $fp ]['reason'] )
			? $cooldowns[ $fp ]['reason']
			: 'unknown';

		$soonest = ( 0 === $soonest ) ? $until : min( $soonest, $until );

		if ( 'daily' === $reason ) {
			$daily++;

			$lines[] = sprintf(
				'Key #%d（%s）：🛑 今日配額用盡（%s 恢復）',
				$display,
				wxacg_mask_gemini_api_key( $key ),
				wp_date( 'm/d H:i', $until )
			);
		} elseif ( 'minute' === $reason ) {
			$lines[] = sprintf(
				'Key #%d（%s）：⏳ 分鐘配額冷卻中（%s 恢復）',
				$display,
				wxacg_mask_gemini_api_key( $key ),
				wp_date( 'H:i:s', $until )
			);
		} else {
			$lines[] = sprintf(
				'Key #%d（%s）：⏳ 冷卻中／原因不明（%s 恢復）',
				$display,
				wxacg_mask_gemini_api_key( $key ),
				wp_date( 'H:i:s', $until )
			);
		}
	}

	return array(
		'ready'     => $ready,
		'daily'     => $daily,
		'lines'     => $lines,
		'soonest'   => $soonest,
		'all_daily' => (
			count( $api_keys ) > 0 &&
			$daily === count( $api_keys )
		),
	);
}

/* ============================================================
 * AI 草稿 Meta 寫入
 * ============================================================ */

/**
 * 將 AI 產出的內容存成待人工審核草稿。
 *
 * 不會填入人工作者或人工審核日期。
 * 覆蓋時會清除舊審核資訊，避免新 AI 內容沿用舊審核狀態。
 */
function wxacg_save_editorial_ai_draft( $post_id, $editorial ) {
	$post_id  = (int) $post_id;
	$editorial = trim( (string) $editorial );

	if ( $post_id <= 0 || '' === $editorial ) {
		return false;
	}

	update_post_meta( $post_id, 'anime_editor_summary', $editorial );
	update_post_meta( $post_id, 'anime_editorial_status', 'draft' );
	update_post_meta( $post_id, 'anime_editorial_ai_generated', '1' );
	update_post_meta( $post_id, 'anime_editorial_ai_needs_review', '1' );
	update_post_meta( $post_id, 'anime_editorial_ai_model', WXACG_GEMINI_MODEL );
	update_post_meta( $post_id, 'anime_editorial_prompt_version', WXACG_EDITORIAL_PROMPT_VERSION );
	update_post_meta(
		$post_id,
		'anime_editorial_ai_generated_at',
		current_time( 'mysql' )
	);

	// 新 AI 草稿尚未經人工審核，不可保留舊審核身分與日期。
	delete_post_meta( $post_id, 'anime_editorial_author_id' );
	delete_post_meta( $post_id, 'anime_editorial_reviewed_at' );

	// 不修改 anime_watch_guide 與 anime_editorial_note。
	return true;
}

/* ============================================================
 * AJAX：批次產生
 * ============================================================ */

add_action(
	'wp_ajax_wxacg_ai_generate_batch',
	'wxacg_ai_generate_batch_handler'
);

function wxacg_ai_generate_batch_handler() {
	try {
		check_ajax_referer( 'wxacg_ai_editorial_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => '權限不足' )
			);
		}

		$api_keys = wxacg_get_gemini_api_keys();

		if ( empty( $api_keys ) ) {
			wp_send_json_error(
				array( 'message' => '請先設定至少一把 Gemini API Key' )
			);
		}

		$key_count = count( $api_keys );

		$batch_size = min(
			max(
				1,
				(int) (
					isset( $_POST['batch_size'] )
						? $_POST['batch_size']
						: 10
				)
			),
			60
		);

		$offset = max(
			0,
			(int) (
				isset( $_POST['offset'] )
					? $_POST['offset']
					: 0
			)
		);

		$overwrite = ! empty( $_POST['overwrite'] );

		$sort = sanitize_key(
			isset( $_POST['sort'] )
				? wp_unslash( $_POST['sort'] )
				: 'new'
		);

		if ( ! in_array( $sort, array( 'new', 'popular', 'default' ), true ) ) {
			$sort = 'new';
		}

		// 0 代表自動；每把 Key 以保守的 4 RPM 估算。
		$item_delay_ms = (int) (
			isset( $_POST['item_delay'] )
				? $_POST['item_delay']
				: 0
		);

		if ( $item_delay_ms <= 0 ) {
			$item_delay_ms = (int) ceil(
				60000 / max( 1, $key_count * 4 )
			);
		}

		$item_delay_ms = min(
			max( $item_delay_ms, 100 ),
			15000
		);

		$key_cursor = (
			(int) (
				isset( $_POST['key_cursor'] )
					? $_POST['key_cursor']
					: 0
			)
		) % $key_count;

		if ( $key_cursor < 0 ) {
			$key_cursor += $key_count;
		}

		// 所有 Key 都已用盡每日配額時，不查詢文章也不發 API。
		$pre_status = wxacg_build_key_status( $api_keys );

		if ( $pre_status['all_daily'] ) {
			wp_send_json_success(
				array(
					'results'         => array(),
					'processed'       => 0,
					'succeeded'       => 0,
					'failed'          => 0,
					'total_remaining' => wxacg_count_remaining(),
					'next_offset'     => $offset,
					'next_key_cursor' => $key_cursor,
					'key_count'       => $key_count,
					'keys_ready'      => 0,
					'key_status'      => $pre_status['lines'],
					'rate_limited'    => false,
					'daily_exhausted' => true,
					'reset_text'      => $pre_status['soonest']
						? wp_date( 'm/d H:i', $pre_status['soonest'] )
						: '',
					'cooldown_sec'    => 0,
					'item_delay_ms'   => $item_delay_ms,
				)
			);
		}

		global $wpdb;

		if ( 'new' === $sort ) {
			$join_extra = "
				LEFT JOIN {$wpdb->postmeta} pm_yr
				       ON pm_yr.post_id = p.ID
				      AND pm_yr.meta_key = 'anime_season_year'
			";

			$order_by = "
				ORDER BY
					MAX(
						CAST(
							NULLIF( pm_yr.meta_value, '' )
							AS UNSIGNED
						)
					) DESC,
					p.comment_count DESC,
					p.ID DESC
			";
		} elseif ( 'popular' === $sort ) {
			$join_extra = "
				LEFT JOIN {$wpdb->postmeta} pm_al
				       ON pm_al.post_id = p.ID
				      AND pm_al.meta_key = 'anime_score_anilist'
			";

			$order_by = "
				ORDER BY
					p.comment_count DESC,
					MAX(
						CAST(
							NULLIF( pm_al.meta_value, '' )
							AS DECIMAL(5,2)
						)
					) DESC,
					p.ID ASC
			";
		} else {
			$join_extra = '';
			$order_by   = 'ORDER BY p.ID ASC';
		}

		$where_missing = $overwrite
			? ''
			: "
				AND NOT EXISTS (
					SELECT 1
					FROM {$wpdb->postmeta} pmx
					WHERE pmx.post_id = p.ID
					  AND pmx.meta_key = 'anime_editor_summary'
					  AND TRIM( pmx.meta_value ) <> ''
				)
			";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.comment_count
			 FROM {$wpdb->posts} p
			 {$join_extra}
			 WHERE p.post_type = 'anime'
			   AND p.post_status = 'publish'
			   {$where_missing}
			 GROUP BY p.ID, p.post_title, p.comment_count
			 {$order_by}
			 LIMIT %d OFFSET %d",
			$batch_size,
			$offset
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results( $sql );

		if ( ! empty( $wpdb->last_error ) ) {
			wp_send_json_error(
				array(
					'message' => 'SQL 錯誤：' . $wpdb->last_error,
				)
			);
		}

		$results         = array();
		$rate_limited    = false;
		$daily_exhausted = false;
		$reset_text      = '';
		$attempted       = 0;
		$started_at      = microtime( true );

		foreach ( $posts as $post ) {
			if (
				$attempted > 0 &&
				( microtime( true ) - $started_at ) >
				WXACG_BATCH_TIME_BUDGET
			) {
				break;
			}

			$post_id = (int) $post->ID;
			$data    = wxacg_gather_anime_data_for_editorial( $post_id );

			$start_index = (
				$key_cursor + $attempted
			) % $key_count;

			$result = wxacg_call_gemini_editorial_multi(
				$api_keys,
				$start_index,
				$data
			);

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code();

				$results[] = array(
					'id'      => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);

				$attempted++;

				if ( 'gemini_quota_daily' === $error_code ) {
					$daily_exhausted = true;
					$error_data      = $result->get_error_data();

					$reset_text = (
						is_array( $error_data ) &&
						isset( $error_data['reset_text'] )
					)
						? $error_data['reset_text']
						: '';

					break;
				}

				if ( 'gemini_quota' === $error_code ) {
					$rate_limited = true;
					break;
				}
			} else {
				$saved = wxacg_save_editorial_ai_draft(
					$post_id,
					$result
				);

				if ( ! $saved ) {
					$results[] = array(
						'id'      => $post_id,
						'title'   => $post->post_title,
						'status'  => 'error',
						'message' => '短評產生成功，但寫入草稿欄位失敗。',
					);
				} else {
					$results[] = array(
						'id'        => $post_id,
						'title'     => $post->post_title,
						'status'    => 'success',
						'editorial' => $result,
					);
				}

				$attempted++;
			}

			// 最後一筆不需要再睡眠。
			if ( $attempted < count( $posts ) ) {
				usleep( $item_delay_ms * 1000 );
			}
		}

		$error_count = 0;

		foreach ( $results as $row ) {
			if (
				isset( $row['status'] ) &&
				'error' === $row['status']
			) {
				$error_count++;
			}
		}

		$status = wxacg_build_key_status( $api_keys );

		$cooldown_sec = 0;

		if (
			$rate_limited &&
			$status['soonest'] > 0
		) {
			$cooldown_sec = max(
				5,
				min(
					180,
					$status['soonest'] - time() + 2
				)
			);
		}

		wp_send_json_success(
			array(
				'results'         => $results,
				'processed'       => count( $results ),
				'succeeded'       => count( $results ) - $error_count,
				'failed'          => $error_count,
				'total_remaining' => wxacg_count_remaining(),

				// 非覆蓋模式中，成功項目已離開查詢集合；
				// offset 僅跳過仍留在集合中的失敗項目。
				'next_offset'     => $overwrite
					? ( $offset + count( $results ) )
					: ( $offset + $error_count ),

				'next_key_cursor' => (
					$key_cursor + $attempted
				) % $key_count,

				'key_count'       => $key_count,
				'keys_ready'      => $status['ready'],
				'key_status'      => $status['lines'],
				'rate_limited'    => $rate_limited,
				'daily_exhausted' => $daily_exhausted,
				'reset_text'      => $reset_text,
				'cooldown_sec'    => $cooldown_sec,
				'item_delay_ms'   => $item_delay_ms,
			)
		);
	} catch ( Exception $e ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					'PHP 例外：%s（%s:%d）',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				),
			)
		);
	} catch ( Error $e ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					'PHP 錯誤：%s（%s:%d）',
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				),
			)
		);
	}
}

/**
 * 尚未填入編輯摘要的已發佈動漫數量。
 */
function wxacg_count_remaining() {
	global $wpdb;

	$sql = "
		SELECT COUNT(*)
		FROM {$wpdb->posts} p
		WHERE p.post_type = 'anime'
		  AND p.post_status = 'publish'
		  AND NOT EXISTS (
			  SELECT 1
			  FROM {$wpdb->postmeta} pmx
			  WHERE pmx.post_id = p.ID
			    AND pmx.meta_key = 'anime_editor_summary'
			    AND TRIM( pmx.meta_value ) <> ''
		  )
	";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $sql );
}

/* ============================================================
 * 蒐集動漫資料
 * ============================================================ */

function wxacg_gather_anime_data_for_editorial( $post_id ) {
	$post_id = (int) $post_id;

	$synopsis = trim(
		(string) get_post_meta(
			$post_id,
			'anime_synopsis_chinese',
			true
		)
	);

	if ( '' === $synopsis ) {
		$synopsis = trim(
			(string) get_post_meta(
				$post_id,
				'anime_synopsis',
				true
			)
		);
	}

	$synopsis = wp_strip_all_tags( $synopsis );
	$synopsis = wxacg_editorial_substr( $synopsis, 0, 400 );

	$genre_terms = get_the_terms( $post_id, 'anime_genre_tax' );
	$genres      = '';

	if (
		is_array( $genre_terms ) &&
		! is_wp_error( $genre_terms )
	) {
		$genre_names = array();

		foreach ( $genre_terms as $term ) {
			if ( isset( $term->name ) ) {
				$genre_names[] = $term->name;
			}
		}

		$genres = implode(
			'、',
			array_values( array_unique( $genre_names ) )
		);
	}

	$studio_terms = get_the_terms( $post_id, 'anime_studio_tax' );
	$studios      = '';

	if (
		is_array( $studio_terms ) &&
		! is_wp_error( $studio_terms )
	) {
		$studio_names = array();

		foreach ( $studio_terms as $term ) {
			if ( isset( $term->name ) ) {
				$studio_names[] = $term->name;
			}
		}

		$studios = implode(
			'、',
			array_values( array_unique( $studio_names ) )
		);
	}

	// Taxonomy 無資料時，使用 ACF/meta anime_studios 作後備。
	if ( '' === $studios ) {
		$studio_meta = get_post_meta(
			$post_id,
			'anime_studios',
			true
		);

		if ( is_array( $studio_meta ) ) {
			$studio_meta = implode(
				'、',
				array_filter(
					array_map( 'sanitize_text_field', $studio_meta )
				)
			);
		}

		$studios = trim( wp_strip_all_tags( (string) $studio_meta ) );
	}

	$streaming_raw = get_post_meta(
		$post_id,
		'anime_streaming_list',
		true
	);

	$streaming_list  = maybe_unserialize( $streaming_raw );
	$streaming_names = array();

	if ( is_array( $streaming_list ) ) {
		foreach ( $streaming_list as $item ) {
			if (
				is_array( $item ) &&
				! empty( $item['site'] )
			) {
				$streaming_names[] = sanitize_text_field(
					$item['site']
				);
			}
		}
	}

	$tw_raw = get_post_meta(
		$post_id,
		'anime_tw_streaming',
		true
	);

	if ( is_array( $tw_raw ) ) {
		foreach ( $tw_raw as $platform ) {
			if ( is_scalar( $platform ) ) {
				$streaming_names[] = sanitize_text_field(
					(string) $platform
				);
			}
		}
	} else {
		$tw_raw = trim( (string) $tw_raw );

		if ( '' !== $tw_raw ) {
			$streaming_names[] = sanitize_text_field( $tw_raw );
		}
	}

	$streaming_names = array_values(
		array_unique(
			array_filter( $streaming_names )
		)
	);

	$season_map = array(
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
	);

	$season_raw = strtoupper(
		trim(
			(string) get_post_meta(
				$post_id,
				'anime_season',
				true
			)
		)
	);

	$season = isset( $season_map[ $season_raw ] )
		? $season_map[ $season_raw ]
		: $season_raw;

	return array(
		'post_id'   => $post_id,
		'title'     => get_the_title( $post_id ),
		'title_ja'  => trim(
			(string) get_post_meta(
				$post_id,
				'anime_title_native',
				true
			)
		),
		'synopsis'  => $synopsis,
		'genres'    => $genres,
		'episodes'  => trim(
			(string) get_post_meta(
				$post_id,
				'anime_episodes',
				true
			)
		),
		'year'      => trim(
			(string) get_post_meta(
				$post_id,
				'anime_season_year',
				true
			)
		),
		'season'    => $season,
		'score_al'  => trim(
			(string) get_post_meta(
				$post_id,
				'anime_score_anilist',
				true
			)
		),
		'score_mal' => trim(
			(string) get_post_meta(
				$post_id,
				'anime_score_mal',
				true
			)
		),
		'score_bgm' => trim(
			(string) get_post_meta(
				$post_id,
				'anime_score_bangumi',
				true
			)
		),
		'streaming' => implode( '、', $streaming_names ),
		'studio'    => $studios,
	);
}

/* ============================================================
 * 呼叫 Gemini API（多 Key 輪替 + 冷卻感知）
 * ============================================================ */

/**
 * @param array $api_keys Gemini API Keys。
 * @param int   $start_idx Round-robin 起點。
 * @param array $data 動漫資料。
 *
 * @return string|WP_Error
 */
function wxacg_call_gemini_editorial_multi( $api_keys, $start_idx, $data ) {
	$prompt    = wxacg_build_editorial_prompt( $data );
	$key_count = count( $api_keys );

	if ( $key_count < 1 ) {
		return new WP_Error(
			'gemini_no_key',
			'沒有可用的 API Key'
		);
	}

	$last_error    = null;
	$saw_non_quota = false;
	$max_rounds    = 2;

	for ( $round = 0; $round < $max_rounds; $round++ ) {
		$cooldowns  = wxacg_get_key_cooldowns();
		$tried      = 0;
		$wait_hint  = 0;
		$only_quota = true;

		for ( $offset = 0; $offset < $key_count; $offset++ ) {
			$key_index = (
				$start_idx + $offset
			) % $key_count;

			$display_index = $key_index + 1;
			$api_key       = $api_keys[ $key_index ];

			// 冷卻中的 Key 直接跳過。
			if ( wxacg_key_is_cooling( $api_key, $cooldowns ) ) {
				continue;
			}

			$tried++;

			$endpoint = sprintf(
				'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
				rawurlencode( WXACG_GEMINI_MODEL ),
				rawurlencode( $api_key )
			);

			$request_body = array(
				'contents' => array(
					array(
						'parts' => array(
							array(
								'text' => $prompt,
							),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => 320,
					'thinkingConfig'  => array(
						'thinkingLevel' => 'minimal',
					),
				),
				'safetySettings' => array(
					array(
						'category'  => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
						'threshold' => 'BLOCK_NONE',
					),
					array(
						'category'  => 'HARM_CATEGORY_HATE_SPEECH',
						'threshold' => 'BLOCK_NONE',
					),
					array(
						'category'  => 'HARM_CATEGORY_HARASSMENT',
						'threshold' => 'BLOCK_NONE',
					),
					array(
						'category'  => 'HARM_CATEGORY_DANGEROUS_CONTENT',
						'threshold' => 'BLOCK_NONE',
					),
				),
			);

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 45,
					'headers' => array(
						'Content-Type' => 'application/json; charset=utf-8',
					),
					'body' => wp_json_encode(
						$request_body,
						JSON_UNESCAPED_UNICODE
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = new WP_Error(
					'gemini_http',
					sprintf(
						'Key #%d 連線失敗：%s',
						$display_index,
						$response->get_error_message()
					)
				);

				$saw_non_quota = true;
				$only_quota    = false;
				continue;
			}

			$response_code = (int) wp_remote_retrieve_response_code(
				$response
			);

			$response_body = wp_remote_retrieve_body( $response );

			if ( 200 === $response_code ) {
				$decoded = json_decode( $response_body, true );
				$text    = '';

				if (
					isset(
						$decoded['candidates'][0]['content']['parts']
					) &&
					is_array(
						$decoded['candidates'][0]['content']['parts']
					)
				) {
					foreach (
						$decoded['candidates'][0]['content']['parts']
						as $part
					) {
						if (
							is_array( $part ) &&
							isset( $part['text'] )
						) {
							$text .= (string) $part['text'];
						}
					}
				}

				$text = trim( $text );

				if ( '' === $text ) {
					$finish_reason = isset(
						$decoded['candidates'][0]['finishReason']
					)
						? $decoded['candidates'][0]['finishReason']
						: '未知';

					$last_error = new WP_Error(
						'gemini_empty',
						sprintf(
							'Key #%d 回傳空白（finishReason：%s）',
							$display_index,
							$finish_reason
						)
					);

					$saw_non_quota = true;
					$only_quota    = false;
					continue;
				}

				$normalized = wxacg_normalize_editorial_text( $text );

				if ( is_wp_error( $normalized ) ) {
					$last_error = new WP_Error(
						$normalized->get_error_code(),
						sprintf(
							'Key #%d：%s',
							$display_index,
							$normalized->get_error_message()
						)
					);

					$saw_non_quota = true;
					$only_quota    = false;
					continue;
				}

				return $normalized;
			}

			$error_data = json_decode( $response_body, true );

			$error_message = isset( $error_data['error']['message'] )
				? $error_data['error']['message']
				: 'HTTP ' . $response_code;

			if ( 429 === $response_code ) {
				$quota_info = wxacg_classify_quota_error(
					$error_data,
					$response_body
				);

				if ( 'day' === $quota_info['scope'] ) {
					$until = wxacg_next_pacific_midnight();

					wxacg_set_key_cooldown(
						$api_key,
						$until,
						'daily'
					);

					$last_error = new WP_Error(
						'gemini_quota_single',
						sprintf(
							'Key #%d 今日配額用盡%s，停用至 %s',
							$display_index,
							$quota_info['quota_id']
								? '（' . $quota_info['quota_id'] . '）'
								: '',
							wp_date( 'm/d H:i', $until )
						)
					);
				} elseif ( 'minute' === $quota_info['scope'] ) {
					$wait = max(
						5,
						min(
							60,
							$quota_info['retry_after']
								? $quota_info['retry_after']
								: 20
						)
					);

					$wait_hint = max( $wait_hint, $wait );

					wxacg_set_key_cooldown(
						$api_key,
						time() + $wait,
						'minute'
					);

					$last_error = new WP_Error(
						'gemini_quota_single',
						sprintf(
							'Key #%d 分鐘配額已滿，冷卻 %d 秒',
							$display_index,
							$wait
						)
					);
				} else {
					// 無法確定是 TPM、專案限制或其他配額，
					// 不直接判定為每日耗盡，只冷卻 15 分鐘。
					wxacg_set_key_cooldown(
						$api_key,
						time() + ( 15 * MINUTE_IN_SECONDS ),
						'unknown'
					);

					$last_error = new WP_Error(
						'gemini_quota_single',
						sprintf(
							'Key #%d 配額錯誤（類型不明，冷卻 15 分鐘）：%s',
							$display_index,
							$error_message
						)
					);
				}

				continue;
			}

			// 400、403、404、500 等錯誤直接回報。
			return new WP_Error(
				'gemini_error',
				sprintf(
					'Gemini API 錯誤 %d（Key #%d）：%s',
					$response_code,
					$display_index,
					$error_message
				)
			);
		}

		// 沒有 Key 可試，或包含非配額錯誤時，不進下一輪。
		if ( 0 === $tried || ! $only_quota ) {
			break;
		}

		if ( $round < ( $max_rounds - 1 ) ) {
			sleep(
				min(
					15,
					max( 5, $wait_hint )
				)
			);
		}
	}

	$status = wxacg_build_key_status( $api_keys );

	if ( $status['all_daily'] ) {
		$error = new WP_Error(
			'gemini_quota_daily',
			sprintf(
				'全部 %d 把 Key 今日配額皆已用盡，最快可於 %s 恢復（太平洋時間午夜重置）。',
				$key_count,
				$status['soonest']
					? wp_date( 'm/d H:i', $status['soonest'] )
					: '隔日'
			)
		);

		$error->add_data(
			array(
				'reset_at' => $status['soonest'],
				'reset_text' => $status['soonest']
					? wp_date( 'm/d H:i', $status['soonest'] )
					: '',
			)
		);

		return $error;
	}

	if (
		$saw_non_quota &&
		$status['ready'] > 0 &&
		$last_error
	) {
		return $last_error;
	}

	return new WP_Error(
		'gemini_quota',
		'目前所有 Key 皆在冷卻中：' .
		(
			$last_error
				? $last_error->get_error_message()
				: '未知原因'
		) .
		(
			$status['soonest']
				? sprintf(
					'（最快 %s 恢復）',
					wp_date( 'H:i:s', $status['soonest'] )
				)
				: ''
		)
	);
}

/* ============================================================
 * Prompt 建構
 * ============================================================ */

function wxacg_build_editorial_prompt( $data ) {
	$streaming_text = (
		isset( $data['streaming'] ) &&
		'' !== $data['streaming']
	)
		? '台灣合法串流：' . $data['streaming']
		: '目前無台灣官方授權串流資訊';

	$scores = array();

	if ( isset( $data['score_al'] ) && '' !== $data['score_al'] ) {
		$scores[] = 'AniList ' . $data['score_al'];
	}

	if ( isset( $data['score_mal'] ) && '' !== $data['score_mal'] ) {
		$scores[] = 'MAL ' . $data['score_mal'];
	}

	if ( isset( $data['score_bgm'] ) && '' !== $data['score_bgm'] ) {
		$scores[] = 'Bangumi ' . $data['score_bgm'];
	}

	$score_text = ! empty( $scores )
		? implode( '、', $scores )
		: '暫無對外評分資料';

	$year = isset( $data['year'] )
		? $data['year']
		: '';

	$season = isset( $data['season'] )
		? $data['season']
		: '';

	$air_info = trim( $year . ' ' . $season );

	if ( '' === $air_info ) {
		$air_info = '播出時間不詳';
	}

	$episodes_text = (
		isset( $data['episodes'] ) &&
		$data['episodes']
	)
		? $data['episodes'] . ' 集'
		: '集數未定';

	$opening_styles = array(
		'用這部動畫最讓你印象深刻的一個細節開頭（不要劇透關鍵情節）',
		'用評分或市場反應來帶出這部作品的定位',
		'先說這部適合什麼口味的觀眾，再延伸到作品本身',
		'從類型或題材的角度切入，說說這部的與眾不同之處',
		'先給一個對這部作品的直覺評價，再用具體理由支撐',
		'從製作公司或核心製作人的風格說起（僅限資料足夠時）',
	);

	$post_id = isset( $data['post_id'] )
		? (int) $data['post_id']
		: 0;

	$style = $opening_styles[
		$post_id % count( $opening_styles )
	];

	$title = isset( $data['title'] )
		? $data['title']
		: '';

	$title_ja = isset( $data['title_ja'] )
		? $data['title_ja']
		: '';

	$title_text = $title;

	if ( '' !== $title_ja && $title_ja !== $title ) {
		$title_text .= '（' . $title_ja . '）';
	}

	$genres = (
		isset( $data['genres'] ) &&
		'' !== $data['genres']
	)
		? $data['genres']
		: '類型資料不足';

	$studio = (
		isset( $data['studio'] ) &&
		'' !== $data['studio']
	)
		? $data['studio']
		: '製作資料不足';

	$synopsis = (
		isset( $data['synopsis'] ) &&
		'' !== $data['synopsis']
	)
		? $data['synopsis']
		: '暫無完整劇情簡介';

	$prompt  = "你是「微笑動漫」（weixiaoacg.com）的資深動漫編輯，筆名「笑編」，有超過十年動漫評論資歷。你的文字風格是：\n";
	$prompt .= "- 使用台灣動漫圈自然口吻，親切、有觀點，但不武斷\n";
	$prompt .= "- 可以帶一點幽默或個人感受，但不得誇大或捏造\n";
	$prompt .= "- 避免教條式套話，直接分享對作品的具體觀感\n";
	$prompt .= "- 句子不要過長，閱讀節奏自然\n";
	$prompt .= "- 使用台灣慣用詞，例如聲優、追番、新番、動畫\n";
	$prompt .= "- 資料不足時不要自行杜撰角色、聲優、製作人或獎項\n\n";

	$prompt .= "請為以下作品撰寫一段 80～130 字的繁體中文「AI 編輯短評草稿」，之後會交由人工編輯審核：\n\n";

	$prompt .= "【作品資料】\n";
	$prompt .= '- 標題：' . $title_text . "\n";
	$prompt .= '- 類型：' . $genres . "\n";
	$prompt .= '- 集數：' . $episodes_text . "\n";
	$prompt .= '- 播出：' . $air_info . "\n";
	$prompt .= '- 製作：' . $studio . "\n";
	$prompt .= '- 外部評分：' . $score_text . "\n";
	$prompt .= '- ' . $streaming_text . "\n";
	$prompt .= '- 劇情簡介：' . $synopsis . "\n\n";

	$prompt .= "【本次開頭方向】\n";
	$prompt .= $style . "\n\n";

	$prompt .= "【輸出規則】\n";
	$prompt .= "1. 只輸出短評本文，不加標題、引號、Markdown、署名或說明文字\n";
	$prompt .= "2. 不要以「這部動畫」、「本作」、「如果你喜歡」開頭\n";
	$prompt .= "3. 不要照抄劇情簡介，要加入具體但不劇透的編輯觀點\n";
	$prompt .= "4. 有台灣串流資訊時可自然融入；沒有資料時不要自行猜測平台\n";
	$prompt .= "5. 字數控制在 80～130 個繁體中文字左右\n";
	$prompt .= "6. 不得捏造資料中沒有提供的角色、聲優、製作人、獎項或觀看平台\n";
	$prompt .= "7. 不要把外部評分當作本站評分，也不要宣稱評分保證作品品質\n";

	return $prompt;
}

/* ============================================================
 * 後台頁面 UI
 * ============================================================ */

function wxacg_ai_editorial_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
	}

	/*
	 * 儲存 API Keys。
	 *
	 * 為避免把既有 Key 明文放回 HTML：
	 * - textarea 留空：保留既有 Key
	 * - textarea 貼入內容：以新內容取代既有 Key
	 * - 清除 Key：必須使用獨立按鈕
	 */
	if (
		isset( $_POST['wxacg_save_apikey'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		$raw = isset( $_POST['wxacg_gemini_api_keys'] )
			? trim(
				(string) wp_unslash(
					$_POST['wxacg_gemini_api_keys']
				)
			)
			: '';

		if ( '' === $raw ) {
			echo '<div class="notice notice-info is-dismissible"><p>ℹ️ 欄位留空，已保留原有 API Keys。</p></div>';
		} else {
			$keys = preg_split( '/[\r\n]+/', $raw );
			$keys = array_map( 'sanitize_text_field', $keys );
			$keys = array_map( 'trim', $keys );
			$keys = array_values(
				array_unique(
					array_filter( $keys )
				)
			);

			update_option(
				'wxacg_gemini_api_keys',
				$keys,
				false
			);

			delete_option( 'wxacg_gemini_api_key' );

			// Key 集合變更後，舊指紋冷卻紀錄已無必要。
			delete_option( WXACG_COOLDOWN_OPTION );

			echo '<div class="notice notice-success is-dismissible"><p>✅ 已更新並儲存 ' .
				(int) count( $keys ) .
				' 把 API Key。</p></div>';
		}
	}

	// 清除全部 API Keys。
	if (
		isset( $_POST['wxacg_clear_apikeys'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		delete_option( 'wxacg_gemini_api_keys' );
		delete_option( 'wxacg_gemini_api_key' );
		delete_option( WXACG_COOLDOWN_OPTION );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已清除全部 API Keys 與相關冷卻紀錄。</p></div>';
	}

	// 清除冷卻紀錄。
	if (
		isset( $_POST['wxacg_clear_cooldown'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		delete_option( WXACG_COOLDOWN_OPTION );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已清除所有 Key 冷卻紀錄。</p></div>';
	}

	$api_keys  = wxacg_get_gemini_api_keys();
	$key_count = count( $api_keys );
	$nonce     = wp_create_nonce( 'wxacg_ai_editorial_nonce' );
	$status    = wxacg_build_key_status( $api_keys );

	global $wpdb;

	$total_anime = (int) $wpdb->get_var(
		"SELECT COUNT(*)
		 FROM {$wpdb->posts}
		 WHERE post_type = 'anime'
		   AND post_status = 'publish'"
	);

	$need_generate = wxacg_count_remaining();
	$has_summary   = max( 0, $total_anime - $need_generate );
	?>
	<div class="wrap">
		<h1>✍️ AI 編輯短評批次產生器</h1>

		<p style="color:#666;">
			使用 Gemini API（<code><?php echo esc_html( WXACG_GEMINI_MODEL ); ?></code>）產生短評草稿，
			寫入 <code>anime_editor_summary</code>。
		</p>

		<p style="color:#666;">
			所有 AI 產出都會標記為
			<strong>draft／待人工審核</strong>，
			不會自動填入審核作者、審核日期，也不會自動公開為正式人工短評。
		</p>

		<p style="color:#666;">
			💡 多把 Key 會自動輪替；分鐘級配額只進行短期冷卻，
			每日配額耗盡則停用到太平洋時間隔日午夜。
			中途關閉頁面不會影響已儲存的草稿。
		</p>

		<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
			<?php
			$cards = array(
				array(
					'label' => '動漫總數',
					'value' => $total_anime,
					'color' => '#2271b1',
				),
				array(
					'label' => '已有摘要',
					'value' => $has_summary,
					'color' => '#00a32a',
				),
				array(
					'label' => '待產生',
					'value' => $need_generate,
					'color' => $need_generate > 0
						? '#d63638'
						: '#00a32a',
				),
				array(
					'label' => 'API Key 數',
					'value' => $key_count,
					'color' => $key_count > 0
						? '#8250df'
						: '#d63638',
				),
				array(
					'label' => '目前可用 Key',
					'value' => $status['ready'],
					'color' => $status['ready'] > 0
						? '#00a32a'
						: '#d63638',
				),
			);

			foreach ( $cards as $card ) :
				?>
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 28px;text-align:center;min-width:130px;box-shadow:0 1px 3px rgba(0,0,0,.07);">
					<div style="font-size:38px;font-weight:700;color:<?php echo esc_attr( $card['color'] ); ?>;">
						<?php echo esc_html( $card['value'] ); ?>
					</div>
					<div style="color:#555;margin-top:4px;">
						<?php echo esc_html( $card['label'] ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px;">
			<h2 style="margin-top:0;">🔑 Gemini API Key 設定</h2>

			<form method="post">
				<?php wp_nonce_field( 'wxacg_ai_editorial_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wxacg-gemini-api-keys">
								新 API Keys
							</label>
						</th>
						<td>
							<textarea
								id="wxacg-gemini-api-keys"
								name="wxacg_gemini_api_keys"
								rows="7"
								autocomplete="off"
								spellcheck="false"
								style="width:460px;max-width:100%;font-family:monospace;"
								placeholder="留空代表保留目前設定。若要更換，請貼上一行一把的新 Key。&#10;AIzaSy...key1&#10;AIzaSy...key2"
							></textarea>

							<p class="description">
								為避免洩漏，已儲存的 Key 不會回填到此欄位。<br>
								<strong>留空後按儲存：</strong>保留目前設定。<br>
								<strong>貼入新 Key 後按儲存：</strong>完整取代目前所有 Key。<br>
								<strong>清除全部：</strong>使用下方紅色按鈕。
							</p>

							<p class="description">
								可至
								<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">
									Google AI Studio
								</a>
								申請 API Key。各專案實際配額請至
								<a href="https://aistudio.google.com/rate-limit" target="_blank" rel="noopener noreferrer">
									Rate limit
								</a>
								頁面確認。
							</p>
						</td>
					</tr>
				</table>

				<button
					type="submit"
					name="wxacg_save_apikey"
					class="button button-primary"
				>
					儲存／取代 API Keys
				</button>

				<button
					type="submit"
					name="wxacg_clear_cooldown"
					class="button"
					style="margin-left:8px;"
				>
					清除 Key 冷卻紀錄
				</button>

				<button
					type="submit"
					name="wxacg_clear_apikeys"
					class="button"
					style="margin-left:8px;color:#b32d2e;border-color:#b32d2e;"
					onclick="return confirm('確定要清除全部 Gemini API Keys 嗎？此操作無法復原。');"
				>
					清除全部 API Keys
				</button>

				<?php if ( $key_count > 0 ) : ?>
					<span style="margin-left:12px;color:#00a32a;">
						✅ 已設定 <?php echo (int) $key_count; ?> 把 Key
					</span>
				<?php else : ?>
					<span style="margin-left:12px;color:#d63638;">
						⚠️ 尚未設定任何 Key
					</span>
				<?php endif; ?>
			</form>

			<?php if ( $key_count > 0 ) : ?>
				<div style="margin-top:16px;padding:12px 16px;background:#f6f7f7;border-radius:6px;font-family:monospace;font-size:12px;line-height:1.9;">
					<?php foreach ( $status['lines'] as $line ) : ?>
						<div><?php echo esc_html( $line ); ?></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $key_count > 0 ) : ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">
				<h2 style="margin-top:0;">🚀 批次產生設定</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wxacg-batch-size">每批數量</label>
						</th>
						<td>
							<select id="wxacg-batch-size">
								<?php
								$size_options = array(
									3  => '3 部',
									5  => '5 部',
									10 => '10 部',
									20 => '20 部',
									30 => '30 部',
								);

								$recommended = min(
									30,
									max( 3, $key_count * 2 )
								);

								foreach (
									$size_options as $value => $label
								) :
									$is_recommended = (
										$value === $recommended
									);
									?>
									<option
										value="<?php echo (int) $value; ?>"
										<?php selected( $is_recommended ); ?>
									>
										<?php echo esc_html( $label ); ?>
										<?php echo $is_recommended ? '（建議）' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								單次請求最多執行
								<?php echo (int) WXACG_BATCH_TIME_BUDGET; ?>
								秒，超過後會先回傳目前結果，再由前端接續。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-item-delay">
								每筆間隔（節流）
							</label>
						</th>
						<td>
							<select id="wxacg-item-delay">
								<option value="0" selected>
									自動（依 <?php echo (int) $key_count; ?> 把 Key 推算，約
									<?php
									echo (int) ceil(
										60000 / max( 1, $key_count * 4 )
									);
									?>
									ms）
								</option>
								<option value="1000">
									1 秒（較快，容易碰到分鐘配額）
								</option>
								<option value="3000">
									3 秒（穩健）
								</option>
								<option value="6000">
									6 秒（非常保守）
								</option>
							</select>

							<p class="description">
								間隔較長可降低 429 發生機率。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-sort-order">
								產生順序
							</label>
						</th>
						<td>
							<select id="wxacg-sort-order">
								<option value="new" selected>
									📅 新番優先（播出年份倒序）
								</option>
								<option value="popular">
									🔥 熱門優先（留言數及 AniList 評分）
								</option>
								<option value="default">
									🔢 預設順序（文章 ID）
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">覆蓋模式</th>
						<td>
							<label>
								<input
									type="checkbox"
									id="wxacg-overwrite"
								>
								重新產生全部摘要，包括已有內容的作品
							</label>

							<p class="description" style="color:#b32d2e;">
								⚠️ 覆蓋後會將內容重新標記為 AI 草稿，
								並清除舊的人工審核作者與審核日期。
								不會修改觀看指南或內部編輯筆記。
							</p>
						</td>
					</tr>
				</table>

				<div style="display:flex;gap:12px;align-items:center;margin-top:8px;">
					<button
						id="wxacg-start-btn"
						class="button button-primary"
						style="height:40px;padding:0 20px;font-size:15px;"
					>
						🚀 開始批次產生（剩餘
						<?php echo (int) $need_generate; ?>
						部）
					</button>

					<button
						id="wxacg-stop-btn"
						class="button"
						style="display:none;"
					>
						⏹ 停止
					</button>
				</div>

				<div
					id="wxacg-progress"
					style="margin-top:20px;display:none;"
				>
					<div style="background:#f0f0f0;border-radius:6px;height:18px;overflow:hidden;">
						<div
							id="wxacg-progress-bar"
							style="background:linear-gradient(90deg,#2271b1,#00a32a);height:100%;width:0;transition:width .4s;"
						></div>
					</div>

					<p
						id="wxacg-progress-text"
						style="margin:8px 0;color:#444;font-size:13px;"
					></p>
				</div>

				<div
					id="wxacg-log"
					style="display:none;margin-top:16px;max-height:450px;overflow-y:auto;border:1px solid #ddd;border-radius:6px;padding:12px;font-family:monospace;font-size:12px;line-height:1.8;background:#1e1e2e;color:#cdd6f4;white-space:pre-wrap;word-break:break-all;"
				></div>
			</div>
		<?php endif; ?>
	</div>

	<script>
	(function () {
		'use strict';

		var startBtn     = document.getElementById('wxacg-start-btn');
		var stopBtn      = document.getElementById('wxacg-stop-btn');
		var logEl        = document.getElementById('wxacg-log');
		var progressEl   = document.getElementById('wxacg-progress');
		var progressBar  = document.getElementById('wxacg-progress-bar');
		var progressText = document.getElementById('wxacg-progress-text');

		var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce   = '<?php echo esc_js( $nonce ); ?>';

		var totalAnime = <?php echo (int) $total_anime; ?>;

		var DEFAULT_COOLDOWN_MS = 65000;
		var NORMAL_DELAY_MS     = 800;

		if (!startBtn) {
			return;
		}

		var running   = false;
		var offset    = 0;
		var totalDone = 0;
		var totalFail = 0;
		var keyCursor = 0;
		var timer     = null;
		var retryTimer = null;

		startBtn.addEventListener('click', function () {
			if (running) {
				return;
			}

			var overwrite = document.getElementById('wxacg-overwrite').checked;

			if (
				overwrite &&
				!window.confirm(
					'覆蓋模式會重新產生全部摘要，並將它們標記為待審核 AI 草稿。確定要繼續嗎？'
				)
			) {
				return;
			}

			running   = true;
			offset    = 0;
			totalDone = 0;
			totalFail = 0;
			keyCursor = 0;

			startBtn.style.display    = 'none';
			stopBtn.style.display     = '';
			progressEl.style.display  = '';
			logEl.style.display       = '';
			logEl.textContent         = '';
			progressBar.style.width   = '0%';
			progressText.textContent  = '';

			addLog(
				overwrite
					? '🟠 開始覆蓋產生，所有新內容都會重設為待審核 AI 草稿。'
					: '🟢 開始產生尚未填寫的編輯摘要草稿。',
				overwrite ? '#fab387' : '#89dceb'
			);

			runBatch();
		});

		stopBtn.addEventListener('click', function () {
			running = false;
			clearTimers();

			addLog(
				'⏹ 已手動停止。完成 ' + totalDone +
				' 筆，失敗 ' + totalFail + ' 筆。',
				'#fab387'
			);

			finish('▶ 繼續產生');
		});

		function clearTimers() {
			if (timer) {
				clearInterval(timer);
				timer = null;
			}

			if (retryTimer) {
				clearTimeout(retryTimer);
				retryTimer = null;
			}
		}

		function finish(label) {
			running = false;
			clearTimers();

			startBtn.textContent   = label;
			startBtn.style.display = '';
			stopBtn.style.display  = 'none';
		}

		function runBatch() {
			if (!running) {
				return;
			}

			var fd = new FormData();

			fd.append('action', 'wxacg_ai_generate_batch');
			fd.append('nonce', nonce);
			fd.append(
				'batch_size',
				document.getElementById('wxacg-batch-size').value
			);
			fd.append(
				'item_delay',
				document.getElementById('wxacg-item-delay').value
			);
			fd.append(
				'sort',
				document.getElementById('wxacg-sort-order').value
			);
			fd.append(
				'overwrite',
				document.getElementById('wxacg-overwrite').checked
					? '1'
					: '0'
			);
			fd.append('offset', offset);
			fd.append('key_cursor', keyCursor);

			fetch(
				ajaxUrl,
				{
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				}
			)
			.then(function (response) {
				return response.text();
			})
			.then(function (text) {
				var response;

				try {
					response = JSON.parse(text);
				} catch (error) {
					addLog(
						'❌ 伺服器回傳非 JSON，可能發生 PHP 錯誤：\n' +
						text.substring(0, 800),
						'#f38ba8'
					);

					finish('▶ 繼續產生');
					return;
				}

				if (!response.success) {
					addLog(
						'❌ 錯誤：' +
						(
							response.data &&
							response.data.message
								? response.data.message
								: JSON.stringify(response)
						),
						'#f38ba8'
					);

					finish('▶ 繼續產生');
					return;
				}

				var data = response.data || {};

				offset    = parseInt(data.next_offset || 0, 10);
				keyCursor = parseInt(data.next_key_cursor || 0, 10);
				totalDone += parseInt(data.succeeded || 0, 10);
				totalFail += parseInt(data.failed || 0, 10);

				(data.results || []).forEach(function (row) {
					if (row.status === 'success') {
						var editorial = row.editorial || '';
						var preview = editorial.length > 70
							? editorial.substring(0, 70) + '…'
							: editorial;

						addLog(
							'✅ [' + row.id + '] ' + row.title +
							'\n   › ' + preview +
							'\n   › 已存為待人工審核 AI 草稿',
							'#a6e3a1'
						);
					} else {
						addLog(
							'❌ [' + row.id + '] ' + row.title +
							'\n   › ' + row.message,
							'#f38ba8'
						);
					}
				});

				var remaining = parseInt(
					data.total_remaining || 0,
					10
				);

				var percent = totalAnime > 0
					? Math.min(
						Math.max(
							(totalAnime - remaining) /
							totalAnime * 100,
							0
						),
						100
					)
					: 0;

				progressBar.style.width = percent + '%';

				setProgress(
					remaining,
					percent,
					'可用 Key ' +
					(data.keys_ready || 0) +
					'/' +
					(data.key_count || 0) +
					'　間隔 ' +
					(data.item_delay_ms || 0) +
					'ms'
				);

				if (data.daily_exhausted) {
					addLog(
						'🛑 所有 API Key 今日配額已用盡' +
						(
							data.reset_text
								? '，約 ' + data.reset_text + ' 後恢復'
								: ''
						) +
						'。本次完成 ' + totalDone +
						' 筆，尚餘 ' + remaining + ' 部。',
						'#f38ba8'
					);

					if (
						data.key_status &&
						data.key_status.length
					) {
						addLog(
							'   › ' +
							data.key_status.join('\n   › '),
							'#9399b2'
						);
					}

					finish('▶ 配額恢復後繼續');
					return;
				}

				if (
					remaining <= 0 &&
					!document.getElementById('wxacg-overwrite').checked
				) {
					addLog(
						'🎉 全部完成！本次共產生 ' +
						totalDone +
						' 筆待審核短評草稿。',
						'#89dceb'
					);

					finish('🚀 重新檢查');
					return;
				}

				if (parseInt(data.processed || 0, 10) === 0) {
					addLog(
						'ℹ️ 沒有更多可處理項目。完成 ' +
						totalDone +
						' 筆，失敗 ' +
						totalFail +
						' 筆。',
						'#f9e2af'
					);

					finish('🚀 重新開始');
					return;
				}

				if (!running) {
					return;
				}

				if (data.rate_limited) {
					var waitMs = data.cooldown_sec
						? parseInt(data.cooldown_sec, 10) * 1000
						: DEFAULT_COOLDOWN_MS;

					addLog(
						'⏳ 目前所有 Key 都在冷卻，' +
						Math.round(waitMs / 1000) +
						' 秒後自動繼續。',
						'#f9e2af'
					);

					countdownThen(
						waitMs,
						remaining,
						percent
					);

					return;
				}

				retryTimer = setTimeout(
					runBatch,
					NORMAL_DELAY_MS
				);
			})
			.catch(function (error) {
				addLog(
					'❌ fetch 錯誤：' +
						error.message +
						'（30 秒後自動重試）',
					'#f38ba8'
				);

				if (running) {
					retryTimer = setTimeout(
						runBatch,
						30000
					);
				}
			});
		}

		function countdownThen(ms, remaining, percent) {
			var left = ms;

			if (timer) {
				clearInterval(timer);
			}

			timer = setInterval(function () {
				if (!running) {
					clearInterval(timer);
					timer = null;
					return;
				}

				left -= 1000;

				if (left <= 0) {
					clearInterval(timer);
					timer = null;

					setProgress(
						remaining,
						percent,
						'恢復中…'
					);

					runBatch();
				} else {
					setProgress(
						remaining,
						percent,
						'冷卻中，' +
							Math.ceil(left / 1000) +
							' 秒後繼續'
					);
				}
			}, 1000);
		}

		function setProgress(remaining, percent, note) {
			progressText.textContent =
				'本次完成：' + totalDone +
				' 筆　失敗：' + totalFail +
				' 筆　尚未有摘要：' + remaining +
				' 部　(' + percent.toFixed(1) + '%)　' +
				(note || '');
		}

		function addLog(message, color) {
			var span = document.createElement('span');

			span.style.color = color || '#cdd6f4';
			span.textContent = message + '\n';

			logEl.appendChild(span);
			logEl.scrollTop = logEl.scrollHeight;
		}
	})();
	</script>
	<?php
}

