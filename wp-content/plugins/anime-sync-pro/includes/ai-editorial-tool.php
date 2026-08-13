<?php
/**
 * 微笑動漫 — AI 編輯短評批次產生工具
 *
 * Path: wp-content/plugins/anime-sync-pro/includes/ai-editorial-tool.php
 *
 * @version 1.7.0 (2026-08-13)
 *
 * v1.7.0:
 *         1) 同時支援 Google Gemini 與 Groq（console.groq.com）兩種 API Key
 *         2) Key 池抽象化：array('provider','key','label')，跨供應商輪替
 *         3) 冷卻指紋改為 md5(provider|key)，兩家 Key 各自獨立計算冷卻
 *         4) 每日配額重置時間分流：
 *            - Gemini → 太平洋時間隔日午夜
 *            - Groq   → UTC 隔日午夜（並優先採用 x-ratelimit-reset-requests）
 *         5) Groq 使用 OpenAI 相容端點 /openai/v1/chat/completions
 *         6) 429 解析：Gemini 讀 error.details，Groq 讀 retry-after 與錯誤訊息
 *         7) 401／403 視為該把 Key 失效，冷卻 6 小時後改用其他 Key，不中斷整批
 *         8) 產生順序可選 auto／gemini_first／groq_first／單一供應商
 *         9) meta 記錄實際使用的供應商與模型
 *
 * v1.6.0: 改寫入 anime_editor_summary，AI 產出一律標記待人工審核草稿
 * v1.5.0: 配額處理大改 + 每把 Key 冷卻管理
 * v1.4.0: 支援多把 API Key 輪替
 * v1.3.0: 加入 429 重試與 rate_limited 旗標
 * v1.2.0: 改用 gemini-3.6-flash + thinkingConfig
 * v1.1.0: 相容 PHP 7.2+
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
 * 常數
 * ============================================================ */

if ( ! defined( 'WXACG_GEMINI_MODEL' ) ) {
	define( 'WXACG_GEMINI_MODEL', 'gemini-3.6-flash' );
}

if ( ! defined( 'WXACG_GROQ_MODEL_DEFAULT' ) ) {
	define( 'WXACG_GROQ_MODEL_DEFAULT', 'openai/gpt-oss-120b' );
}

if ( ! defined( 'WXACG_EDITORIAL_PROMPT_VERSION' ) ) {
	define( 'WXACG_EDITORIAL_PROMPT_VERSION', '1.7.0' );
}

if ( ! defined( 'WXACG_BATCH_TIME_BUDGET' ) ) {
	define( 'WXACG_BATCH_TIME_BUDGET', 25 );
}

if ( ! defined( 'WXACG_COOLDOWN_OPTION' ) ) {
	define( 'WXACG_COOLDOWN_OPTION', 'wxacg_gemini_key_cooldowns' );
}

/**
 * 各供應商的保守 RPM 估算，用於自動節流。
 */
function wxacg_provider_safe_rpm( $provider ) {
	return ( 'groq' === $provider ) ? 20 : 4;
}

/**
 * Groq 可選模型清單。
 */
function wxacg_groq_model_choices() {
	return array(
		'openai/gpt-oss-120b'     => 'openai/gpt-oss-120b（品質較佳，建議）',
		'openai/gpt-oss-20b'      => 'openai/gpt-oss-20b（速度最快）',
		'llama-3.3-70b-versatile' => 'llama-3.3-70b-versatile（中文尚可）',
		'qwen/qwen3.6-27b'        => 'qwen/qwen3.6-27b（中文表現佳，preview）',
	);
}

function wxacg_get_groq_model() {
	$model   = (string) get_option( 'wxacg_groq_model', WXACG_GROQ_MODEL_DEFAULT );
	$choices = wxacg_groq_model_choices();

	if ( ! isset( $choices[ $model ] ) ) {
		$model = WXACG_GROQ_MODEL_DEFAULT;
	}

	return $model;
}

/* ============================================================
 * PHP 7.2／mbstring 相容工具
 * ============================================================ */

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
 * 清理模型回傳的編輯短評。
 *
 * @return string|WP_Error
 */
function wxacg_normalize_editorial_text( $text ) {
	$text = is_scalar( $text ) ? (string) $text : '';
	$text = trim( $text );

	// 移除 gpt-oss 等模型偶發輸出的思考標籤。
	$text = preg_replace( '#<(?:think|thinking|reasoning)>.*?</(?:think|thinking|reasoning)>#isu', '', $text );
	$text = preg_replace( '#^\s*<(?:think|thinking|reasoning)>.*$#isu', '', $text );

	// 移除偶發的 Markdown code fence。
	$text = preg_replace( '/^\s*```(?:text|markdown|md)?\s*/iu', '', $text );
	$text = preg_replace( '/\s*```\s*$/u', '', $text );

	// 移除模型偶發加入的標題前綴。
	$text = preg_replace(
		'/^\s*(?:編輯短評|短評|評論|本文|輸出|Answer|Final)\s*[：:]\s*/iu',
		'',
		$text
	);

	// 移除整段最外層引號。
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

	if ( $length < 50 ) {
		return new WP_Error(
			'ai_too_short',
			sprintf( '短評過短（%d 字），未寫入資料庫。', $length )
		);
	}

	if ( $length > 180 ) {
		return new WP_Error(
			'ai_too_long',
			sprintf( '短評過長（%d 字），未寫入資料庫。', $length )
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
 * API Key 取得與 Key 池
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

function wxacg_get_groq_api_keys() {
	$keys = get_option( 'wxacg_groq_api_keys', array() );

	if ( ! is_array( $keys ) ) {
		$keys = array();
	}

	$keys = array_map( 'trim', $keys );
	$keys = array_filter( $keys );

	return array_values( array_unique( $keys ) );
}

function wxacg_provider_order_choices() {
	return array(
		'auto'         => '🔀 自動交錯（兩家輪流，最平均）',
		'groq_first'   => '⚡ Groq 優先（用完才換 Gemini）',
		'gemini_first' => '💎 Gemini 優先（用完才換 Groq）',
		'groq'         => '僅使用 Groq',
		'gemini'       => '僅使用 Gemini',
	);
}

/**
 * 建立跨供應商的 Key 池。
 *
 * @param string $mode auto|groq_first|gemini_first|groq|gemini
 *
 * @return array 每個元素為 array('provider','key','label')。
 */
function wxacg_get_ai_key_pool( $mode = 'auto' ) {
	$gemini_keys = wxacg_get_gemini_api_keys();
	$groq_keys   = wxacg_get_groq_api_keys();

	$gemini = array();
	$groq   = array();

	foreach ( $gemini_keys as $index => $key ) {
		$gemini[] = array(
			'provider' => 'gemini',
			'key'      => $key,
			'label'    => sprintf( 'Gemini #%d', $index + 1 ),
		);
	}

	foreach ( $groq_keys as $index => $key ) {
		$groq[] = array(
			'provider' => 'groq',
			'key'      => $key,
			'label'    => sprintf( 'Groq #%d', $index + 1 ),
		);
	}

	if ( 'gemini' === $mode ) {
		return $gemini;
	}

	if ( 'groq' === $mode ) {
		return $groq;
	}

	if ( 'gemini_first' === $mode ) {
		return array_merge( $gemini, $groq );
	}

	if ( 'groq_first' === $mode ) {
		return array_merge( $groq, $gemini );
	}

	// auto：兩家交錯排列。
	$pool  = array();
	$count = max( count( $groq ), count( $gemini ) );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( isset( $groq[ $i ] ) ) {
			$pool[] = $groq[ $i ];
		}

		if ( isset( $gemini[ $i ] ) ) {
			$pool[] = $gemini[ $i ];
		}
	}

	return $pool;
}

/**
 * 只顯示 Key 前後少量字元，不洩漏完整 Key。
 */
function wxacg_mask_api_key( $key ) {
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

// 向下相容舊函式名。
function wxacg_mask_gemini_api_key( $key ) {
	return wxacg_mask_api_key( $key );
}

/* ============================================================
 * Key 冷卻狀態管理
 * ============================================================ */

/**
 * 只存指紋，不把明文 Key 寫進冷卻紀錄。
 */
function wxacg_key_fp( $provider, $key ) {
	return substr( md5( (string) $provider . '|' . (string) $key ), 0, 12 );
}

function wxacg_entry_fp( $entry ) {
	return wxacg_key_fp( $entry['provider'], $entry['key'] );
}

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
function wxacg_set_key_cooldown( $entry, $until_ts, $reason ) {
	$data = wxacg_get_key_cooldowns();
	$fp   = wxacg_entry_fp( $entry );

	if (
		isset( $data[ $fp ]['until'] ) &&
		(int) $data[ $fp ]['until'] > (int) $until_ts
	) {
		return;
	}

	$data[ $fp ] = array(
		'until'    => (int) $until_ts,
		'reason'   => sanitize_key( $reason ),
		'provider' => sanitize_key( $entry['provider'] ),
		'set_at'   => time(),
	);

	update_option( WXACG_COOLDOWN_OPTION, $data, false );
}

function wxacg_key_is_cooling( $entry, $cooldowns ) {
	$fp = wxacg_entry_fp( $entry );

	return (
		isset( $cooldowns[ $fp ]['until'] ) &&
		(int) $cooldowns[ $fp ]['until'] > time()
	);
}

/**
 * Gemini RPD 重置點：太平洋時間午夜。
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
 * Groq RPD 重置點：以 UTC 午夜估算。
 */
function wxacg_next_utc_midnight() {
	$next = strtotime(
		gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' 00:00:00 UTC'
	);

	return $next ? (int) $next : ( time() + ( 3 * HOUR_IN_SECONDS ) );
}

function wxacg_provider_daily_reset_ts( $provider ) {
	return ( 'groq' === $provider )
		? wxacg_next_utc_midnight()
		: wxacg_next_pacific_midnight();
}

/**
 * 解析 Groq 的時間字串，例如 2m59.56s、7.66s、1h2m3s、120ms。
 */
function wxacg_parse_reset_duration( $text ) {
	$text = strtolower( trim( (string) $text ) );

	if ( '' === $text ) {
		return 0;
	}

	if ( is_numeric( $text ) ) {
		return (int) ceil( (float) $text );
	}

	$total   = 0;
	$matched = false;

	if ( preg_match_all( '/([\d.]+)\s*(ms|h|m|s)/', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $part ) {
			$value   = (float) $part[1];
			$matched = true;

			switch ( $part[2] ) {
				case 'h':
					$total += $value * 3600;
					break;
				case 'm':
					$total += $value * 60;
					break;
				case 's':
					$total += $value;
					break;
				case 'ms':
					$total += $value / 1000;
					break;
			}
		}
	}

	return $matched ? (int) ceil( $total ) : 0;
}

/**
 * 分類 Gemini 429 錯誤。
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
 * 分類 Groq 429 錯誤。
 *
 * Groq 訊息格式範例：
 * Rate limit reached for model `openai/gpt-oss-120b` ... on requests per day (RPD):
 * Limit 1000, Used 1000, Requested 1. Please try again in 12m34s.
 */
function wxacg_classify_groq_quota( $response, $raw_body ) {
	$out = array(
		'scope'       => 'unknown',
		'retry_after' => 0,
		'quota_id'    => '',
	);

	$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

	if ( '' !== $retry_after && null !== $retry_after ) {
		$out['retry_after'] = wxacg_parse_reset_duration( $retry_after );
	}

	$lower = strtolower( (string) $raw_body );

	if (
		false !== strpos( $lower, 'per day' ) ||
		false !== strpos( $lower, '(rpd)' ) ||
		false !== strpos( $lower, '(tpd)' ) ||
		false !== strpos( $lower, 'per-day' )
	) {
		$out['scope']    = 'day';
		$out['quota_id'] = ( false !== strpos( $lower, '(tpd)' ) ) ? 'TPD' : 'RPD';
	} elseif (
		false !== strpos( $lower, 'per minute' ) ||
		false !== strpos( $lower, '(rpm)' ) ||
		false !== strpos( $lower, '(tpm)' ) ||
		false !== strpos( $lower, '(itpm)' ) ||
		false !== strpos( $lower, '(otpm)' )
	) {
		$out['scope']    = 'minute';
		$out['quota_id'] = ( false !== strpos( $lower, '(rpm)' ) ) ? 'RPM' : 'TPM';
	} elseif ( $out['retry_after'] > 0 && $out['retry_after'] <= 300 ) {
		$out['scope'] = 'minute';
	}

	// 每日型配額時，優先採用標頭給的剩餘重置時間。
	if ( 'day' === $out['scope'] ) {
		$reset_requests = wp_remote_retrieve_header( $response, 'x-ratelimit-reset-requests' );
		$reset_seconds  = wxacg_parse_reset_duration( $reset_requests );

		if ( $reset_seconds > 0 ) {
			$out['retry_after'] = $reset_seconds;
		}
	}

	return $out;
}

/**
 * 產生後台顯示用的 Key 狀態。
 */
function wxacg_build_key_status( $pool ) {
	$cooldowns = wxacg_get_key_cooldowns();
	$ready     = 0;
	$daily     = 0;
	$lines     = array();
	$soonest   = 0;

	foreach ( $pool as $entry ) {
		$fp    = wxacg_entry_fp( $entry );
		$label = $entry['label'];
		$mask  = wxacg_mask_api_key( $entry['key'] );

		if ( ! isset( $cooldowns[ $fp ] ) ) {
			$ready++;
			$lines[] = sprintf( '%s（%s）：✅ 可用', $label, $mask );
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
				'%s（%s）：🛑 今日配額用盡（%s 恢復）',
				$label,
				$mask,
				wp_date( 'm/d H:i', $until )
			);
		} elseif ( 'minute' === $reason ) {
			$lines[] = sprintf(
				'%s（%s）：⏳ 分鐘配額冷卻中（%s 恢復）',
				$label,
				$mask,
				wp_date( 'H:i:s', $until )
			);
		} elseif ( 'invalid' === $reason ) {
			$daily++;

			$lines[] = sprintf(
				'%s（%s）：🚫 Key 無效或被拒絕（%s 後重試）',
				$label,
				$mask,
				wp_date( 'm/d H:i', $until )
			);
		} else {
			$lines[] = sprintf(
				'%s（%s）：⏳ 冷卻中／原因不明（%s 恢復）',
				$label,
				$mask,
				wp_date( 'H:i:s', $until )
			);
		}
	}

	return array(
		'ready'     => $ready,
		'daily'     => $daily,
		'lines'     => $lines,
		'soonest'   => $soonest,
		'all_daily' => ( count( $pool ) > 0 && $daily === count( $pool ) ),
	);
}

/* ============================================================
 * AI 草稿 Meta 寫入
 * ============================================================ */

/**
 * 將 AI 產出的內容存成待人工審核草稿。
 */
function wxacg_save_editorial_ai_draft( $post_id, $editorial, $provider = 'gemini', $model = '' ) {
	$post_id   = (int) $post_id;
	$editorial = trim( (string) $editorial );

	if ( $post_id <= 0 || '' === $editorial ) {
		return false;
	}

	if ( '' === $model ) {
		$model = ( 'groq' === $provider )
			? wxacg_get_groq_model()
			: WXACG_GEMINI_MODEL;
	}

	update_post_meta( $post_id, 'anime_editor_summary', $editorial );
	update_post_meta( $post_id, 'anime_editorial_status', 'draft' );
	update_post_meta( $post_id, 'anime_editorial_ai_generated', '1' );
	update_post_meta( $post_id, 'anime_editorial_ai_needs_review', '1' );
	update_post_meta( $post_id, 'anime_editorial_ai_provider', sanitize_key( $provider ) );
	update_post_meta( $post_id, 'anime_editorial_ai_model', sanitize_text_field( $provider . ':' . $model ) );
	update_post_meta( $post_id, 'anime_editorial_prompt_version', WXACG_EDITORIAL_PROMPT_VERSION );
	update_post_meta( $post_id, 'anime_editorial_ai_generated_at', current_time( 'mysql' ) );

	// 新 AI 草稿尚未經人工審核，不可保留舊審核身分與日期。
	delete_post_meta( $post_id, 'anime_editorial_author_id' );
	delete_post_meta( $post_id, 'anime_editorial_reviewed_at' );

	return true;
}

/* ============================================================
 * AJAX：批次產生
 * ============================================================ */

add_action( 'wp_ajax_wxacg_ai_generate_batch', 'wxacg_ai_generate_batch_handler' );

function wxacg_ai_generate_batch_handler() {
	try {
		check_ajax_referer( 'wxacg_ai_editorial_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '權限不足' ) );
		}

		$provider_mode = sanitize_key(
			isset( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : ''
		);

		$order_choices = wxacg_provider_order_choices();

		if ( ! isset( $order_choices[ $provider_mode ] ) ) {
			$provider_mode = (string) get_option( 'wxacg_ai_provider_order', 'auto' );
		}

		if ( ! isset( $order_choices[ $provider_mode ] ) ) {
			$provider_mode = 'auto';
		}

		$pool = wxacg_get_ai_key_pool( $provider_mode );

		if ( empty( $pool ) ) {
			wp_send_json_error(
				array( 'message' => '目前選擇的供應商沒有可用的 API Key，請先於上方設定。' )
			);
		}

		$key_count = count( $pool );

		$batch_size = min(
			max( 1, (int) ( isset( $_POST['batch_size'] ) ? $_POST['batch_size'] : 10 ) ),
			60
		);

		$offset = max( 0, (int) ( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ) );

		$overwrite = ! empty( $_POST['overwrite'] );

		$sort = sanitize_key( isset( $_POST['sort'] ) ? wp_unslash( $_POST['sort'] ) : 'new' );

		if ( ! in_array( $sort, array( 'new', 'popular', 'default' ), true ) ) {
			$sort = 'new';
		}

		// 0 代表自動：依 Key 池中各供應商的保守 RPM 推算。
		$item_delay_ms = (int) ( isset( $_POST['item_delay'] ) ? $_POST['item_delay'] : 0 );

		if ( $item_delay_ms <= 0 ) {
			$item_delay_ms = wxacg_auto_item_delay_ms( $pool );
		}

		$item_delay_ms = min( max( $item_delay_ms, 100 ), 15000 );

		$key_cursor = ( (int) ( isset( $_POST['key_cursor'] ) ? $_POST['key_cursor'] : 0 ) ) % $key_count;

		if ( $key_cursor < 0 ) {
			$key_cursor += $key_count;
		}

		// 所有 Key 都已用盡每日配額時，不查詢文章也不發 API。
		$pre_status = wxacg_build_key_status( $pool );

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
					MAX( CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) ) DESC,
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
					MAX( CAST( NULLIF( pm_al.meta_value, '' ) AS DECIMAL(5,2) ) ) DESC,
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
			wp_send_json_error( array( 'message' => 'SQL 錯誤：' . $wpdb->last_error ) );
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
				( microtime( true ) - $started_at ) > WXACG_BATCH_TIME_BUDGET
			) {
				break;
			}

			$post_id = (int) $post->ID;
			$data    = wxacg_gather_anime_data_for_editorial( $post_id );

			$start_index = ( $key_cursor + $attempted ) % $key_count;

			$result = wxacg_call_ai_editorial_multi( $pool, $start_index, $data );

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code();

				$results[] = array(
					'id'      => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);

				$attempted++;

				if ( 'ai_quota_daily' === $error_code ) {
					$daily_exhausted = true;
					$error_data      = $result->get_error_data();

					$reset_text = ( is_array( $error_data ) && isset( $error_data['reset_text'] ) )
						? $error_data['reset_text']
						: '';

					break;
				}

				if ( 'ai_quota' === $error_code ) {
					$rate_limited = true;
					break;
				}
			} else {
				$saved = wxacg_save_editorial_ai_draft(
					$post_id,
					$result['text'],
					$result['provider'],
					$result['model']
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
						'editorial' => $result['text'],
						'source'    => $result['label'] . '／' . $result['model'],
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
			if ( isset( $row['status'] ) && 'error' === $row['status'] ) {
				$error_count++;
			}
		}

		$status = wxacg_build_key_status( $pool );

		$cooldown_sec = 0;

		if ( $rate_limited && $status['soonest'] > 0 ) {
			$cooldown_sec = max( 5, min( 180, $status['soonest'] - time() + 2 ) );
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

				'next_key_cursor' => ( $key_cursor + $attempted ) % $key_count,
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
 * 依 Key 池組成推算自動節流間隔。
 */
function wxacg_auto_item_delay_ms( $pool ) {
	$capacity = 0;

	foreach ( $pool as $entry ) {
		$capacity += wxacg_provider_safe_rpm( $entry['provider'] );
	}

	if ( $capacity < 1 ) {
		$capacity = 1;
	}

	return (int) ceil( 60000 / $capacity );
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

	$synopsis = trim( (string) get_post_meta( $post_id, 'anime_synopsis_chinese', true ) );

	if ( '' === $synopsis ) {
		$synopsis = trim( (string) get_post_meta( $post_id, 'anime_synopsis', true ) );
	}

	$synopsis = wp_strip_all_tags( $synopsis );
	$synopsis = wxacg_editorial_substr( $synopsis, 0, 400 );

	$genre_terms = get_the_terms( $post_id, 'anime_genre_tax' );
	$genres      = '';

	if ( is_array( $genre_terms ) && ! is_wp_error( $genre_terms ) ) {
		$genre_names = array();

		foreach ( $genre_terms as $term ) {
			if ( isset( $term->name ) ) {
				$genre_names[] = $term->name;
			}
		}

		$genres = implode( '、', array_values( array_unique( $genre_names ) ) );
	}

	$studio_terms = get_the_terms( $post_id, 'anime_studio_tax' );
	$studios      = '';

	if ( is_array( $studio_terms ) && ! is_wp_error( $studio_terms ) ) {
		$studio_names = array();

		foreach ( $studio_terms as $term ) {
			if ( isset( $term->name ) ) {
				$studio_names[] = $term->name;
			}
		}

		$studios = implode( '、', array_values( array_unique( $studio_names ) ) );
	}

	// Taxonomy 無資料時，使用 ACF/meta anime_studios 作後備。
	if ( '' === $studios ) {
		$studio_meta = get_post_meta( $post_id, 'anime_studios', true );

		if ( is_array( $studio_meta ) ) {
			$studio_meta = implode(
				'、',
				array_filter( array_map( 'sanitize_text_field', $studio_meta ) )
			);
		}

		$studios = trim( wp_strip_all_tags( (string) $studio_meta ) );
	}

	$streaming_raw   = get_post_meta( $post_id, 'anime_streaming_list', true );
	$streaming_list  = maybe_unserialize( $streaming_raw );
	$streaming_names = array();

	if ( is_array( $streaming_list ) ) {
		foreach ( $streaming_list as $item ) {
			if ( is_array( $item ) && ! empty( $item['site'] ) ) {
				$streaming_names[] = sanitize_text_field( $item['site'] );
			}
		}
	}

	$tw_raw = get_post_meta( $post_id, 'anime_tw_streaming', true );

	if ( is_array( $tw_raw ) ) {
		foreach ( $tw_raw as $platform ) {
			if ( is_scalar( $platform ) ) {
				$streaming_names[] = sanitize_text_field( (string) $platform );
			}
		}
	} else {
		$tw_raw = trim( (string) $tw_raw );

		if ( '' !== $tw_raw ) {
			$streaming_names[] = sanitize_text_field( $tw_raw );
		}
	}

	$streaming_names = array_values( array_unique( array_filter( $streaming_names ) ) );

	$season_map = array(
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
	);

	$season_raw = strtoupper( trim( (string) get_post_meta( $post_id, 'anime_season', true ) ) );

	$season = isset( $season_map[ $season_raw ] ) ? $season_map[ $season_raw ] : $season_raw;

	return array(
		'post_id'   => $post_id,
		'title'     => get_the_title( $post_id ),
		'title_ja'  => trim( (string) get_post_meta( $post_id, 'anime_title_native', true ) ),
		'synopsis'  => $synopsis,
		'genres'    => $genres,
		'episodes'  => trim( (string) get_post_meta( $post_id, 'anime_episodes', true ) ),
		'year'      => trim( (string) get_post_meta( $post_id, 'anime_season_year', true ) ),
		'season'    => $season,
		'score_al'  => trim( (string) get_post_meta( $post_id, 'anime_score_anilist', true ) ),
		'score_mal' => trim( (string) get_post_meta( $post_id, 'anime_score_mal', true ) ),
		'score_bgm' => trim( (string) get_post_meta( $post_id, 'anime_score_bangumi', true ) ),
		'streaming' => implode( '、', $streaming_names ),
		'studio'    => $studios,
	);
}

/* ============================================================
 * 供應商請求層（統一回傳格式）
 * ============================================================
 *
 * 回傳結構：
 * array(
 *   'status'      => 'ok' | 'quota' | 'key_invalid' | 'soft_error' | 'fatal',
 *   'text'        => string,  // status = ok
 *   'scope'       => 'minute' | 'day' | 'unknown', // status = quota
 *   'retry_after' => int,
 *   'message'     => string,
 * )
 */

function wxacg_request_gemini( $api_key, $parts ) {
	$prompt = $parts['system'] . "\n\n" . $parts['user'];

	$endpoint = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
		rawurlencode( WXACG_GEMINI_MODEL ),
		rawurlencode( $api_key )
	);

	$request_body = array(
		'contents'         => array(
			array(
				'parts' => array(
					array( 'text' => $prompt ),
				),
			),
		),
		'generationConfig' => array(
			'maxOutputTokens' => 320,
			'temperature'     => 0.9,
			'thinkingConfig'  => array(
				'thinkingLevel' => 'minimal',
			),
		),
		'safetySettings'   => array(
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
			'body'    => wp_json_encode( $request_body, JSON_UNESCAPED_UNICODE ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status'  => 'soft_error',
			'message' => '連線失敗：' . $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( 200 === $code ) {
		$decoded = json_decode( $body, true );
		$text    = '';

		if (
			isset( $decoded['candidates'][0]['content']['parts'] ) &&
			is_array( $decoded['candidates'][0]['content']['parts'] )
		) {
			foreach ( $decoded['candidates'][0]['content']['parts'] as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		$text = trim( $text );

		if ( '' === $text ) {
			$finish = isset( $decoded['candidates'][0]['finishReason'] )
				? $decoded['candidates'][0]['finishReason']
				: '未知';

			return array(
				'status'  => 'soft_error',
				'message' => sprintf( '回傳空白（finishReason：%s）', $finish ),
			);
		}

		return array(
			'status' => 'ok',
			'text'   => $text,
			'model'  => WXACG_GEMINI_MODEL,
		);
	}

	$decoded = json_decode( $body, true );

	$message = isset( $decoded['error']['message'] )
		? $decoded['error']['message']
		: 'HTTP ' . $code;

	if ( 429 === $code ) {
		$quota = wxacg_classify_quota_error( $decoded, $body );

		return array(
			'status'      => 'quota',
			'scope'       => $quota['scope'],
			'retry_after' => $quota['retry_after'],
			'message'     => $quota['quota_id']
				? sprintf( '%s（%s）', $message, $quota['quota_id'] )
				: $message,
		);
	}

	if ( 401 === $code || 403 === $code ) {
		return array(
			'status'  => 'key_invalid',
			'message' => sprintf( 'HTTP %d：%s', $code, $message ),
		);
	}

	if ( 500 === $code || 503 === $code || 502 === $code || 504 === $code ) {
		return array(
			'status'  => 'soft_error',
			'message' => sprintf( '伺服器暫時錯誤 %d：%s', $code, $message ),
		);
	}

	return array(
		'status'  => 'fatal',
		'message' => sprintf( 'Gemini API 錯誤 %d：%s', $code, $message ),
	);
}

function wxacg_request_groq( $api_key, $parts ) {
	$model = wxacg_get_groq_model();

	$request_body = array(
		'model'                 => $model,
		'messages'              => array(
			array(
				'role'    => 'system',
				'content' => $parts['system'],
			),
			array(
				'role'    => 'user',
				'content' => $parts['user'],
			),
		),
		'temperature'           => 0.9,
		'top_p'                 => 0.95,
		'max_completion_tokens' => 900,
		'stream'                => false,
	);

	// gpt-oss 與 qwen 支援 reasoning 控制；其他模型送出會 400。
	if (
		0 === strpos( $model, 'openai/gpt-oss' ) ||
		0 === strpos( $model, 'qwen/' )
	) {
		$request_body['reasoning_effort'] = 'low';
	}

	$response = wp_remote_post(
		'https://api.groq.com/openai/v1/chat/completions',
		array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $request_body, JSON_UNESCAPED_UNICODE ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status'  => 'soft_error',
			'message' => '連線失敗：' . $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( 200 === $code ) {
		$decoded = json_decode( $body, true );

		$text = isset( $decoded['choices'][0]['message']['content'] )
			? (string) $decoded['choices'][0]['message']['content']
			: '';

		$text = trim( $text );

		if ( '' === $text ) {
			$finish = isset( $decoded['choices'][0]['finish_reason'] )
				? $decoded['choices'][0]['finish_reason']
				: '未知';

			return array(
				'status'  => 'soft_error',
				'message' => sprintf(
					'回傳空白（finish_reason：%s，可能思考佔滿 token）',
					$finish
				),
			);
		}

		return array(
			'status' => 'ok',
			'text'   => $text,
			'model'  => $model,
		);
	}

	$decoded = json_decode( $body, true );

	$message = isset( $decoded['error']['message'] )
		? $decoded['error']['message']
		: 'HTTP ' . $code;

	if ( 429 === $code ) {
		$quota = wxacg_classify_groq_quota( $response, $body );

		return array(
			'status'      => 'quota',
			'scope'       => $quota['scope'],
			'retry_after' => $quota['retry_after'],
			'message'     => $quota['quota_id']
				? sprintf( '%s（%s）', $message, $quota['quota_id'] )
				: $message,
		);
	}

	if ( 401 === $code || 403 === $code ) {
		return array(
			'status'  => 'key_invalid',
			'message' => sprintf( 'HTTP %d：%s', $code, $message ),
		);
	}

	if ( 498 === $code || 499 === $code || $code >= 500 ) {
		return array(
			'status'  => 'soft_error',
			'message' => sprintf( '伺服器暫時錯誤 %d：%s', $code, $message ),
		);
	}

	return array(
		'status'  => 'fatal',
		'message' => sprintf( 'Groq API 錯誤 %d：%s', $code, $message ),
	);
}

/* ============================================================
 * 跨供應商輪替呼叫
 * ============================================================ */

/**
 * @param array $pool      Key 池。
 * @param int   $start_idx Round-robin 起點。
 * @param array $data      動漫資料。
 *
 * @return array|WP_Error 成功時回傳 array('text','provider','model','label')。
 */
function wxacg_call_ai_editorial_multi( $pool, $start_idx, $data ) {
	$parts     = wxacg_build_editorial_prompt_parts( $data );
	$key_count = count( $pool );

	if ( $key_count < 1 ) {
		return new WP_Error( 'ai_no_key', '沒有可用的 API Key' );
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
			$entry = $pool[ ( $start_idx + $offset ) % $key_count ];

			// 冷卻中的 Key 直接跳過。
			if ( wxacg_key_is_cooling( $entry, $cooldowns ) ) {
				continue;
			}

			$tried++;

			$provider = $entry['provider'];
			$label    = $entry['label'];

			$result = ( 'groq' === $provider )
				? wxacg_request_groq( $entry['key'], $parts )
				: wxacg_request_gemini( $entry['key'], $parts );

			if ( 'ok' === $result['status'] ) {
				$normalized = wxacg_normalize_editorial_text( $result['text'] );

				if ( is_wp_error( $normalized ) ) {
					$last_error = new WP_Error(
						$normalized->get_error_code(),
						sprintf( '%s：%s', $label, $normalized->get_error_message() )
					);

					$saw_non_quota = true;
					$only_quota    = false;
					continue;
				}

				return array(
					'text'     => $normalized,
					'provider' => $provider,
					'model'    => $result['model'],
					'label'    => $label,
				);
			}

			if ( 'quota' === $result['status'] ) {
				$scope = isset( $result['scope'] ) ? $result['scope'] : 'unknown';

				if ( 'day' === $scope ) {
					$retry = isset( $result['retry_after'] ) ? (int) $result['retry_after'] : 0;

					$until = ( $retry > 0 )
						? ( time() + $retry )
						: wxacg_provider_daily_reset_ts( $provider );

					wxacg_set_key_cooldown( $entry, $until, 'daily' );

					$last_error = new WP_Error(
						'ai_quota_single',
						sprintf(
							'%s 今日配額用盡，停用至 %s',
							$label,
							wp_date( 'm/d H:i', $until )
						)
					);
				} elseif ( 'minute' === $scope ) {
					$retry = isset( $result['retry_after'] ) ? (int) $result['retry_after'] : 0;
					$wait  = max( 5, min( 90, $retry > 0 ? $retry : 20 ) );

					$wait_hint = max( $wait_hint, $wait );

					wxacg_set_key_cooldown( $entry, time() + $wait, 'minute' );

					$last_error = new WP_Error(
						'ai_quota_single',
						sprintf( '%s 分鐘配額已滿，冷卻 %d 秒', $label, $wait )
					);
				} else {
					// 類型不明時只冷卻 15 分鐘，不直接判定每日耗盡。
					wxacg_set_key_cooldown(
						$entry,
						time() + ( 15 * MINUTE_IN_SECONDS ),
						'unknown'
					);

					$last_error = new WP_Error(
						'ai_quota_single',
						sprintf(
							'%s 配額錯誤（類型不明，冷卻 15 分鐘）：%s',
							$label,
							$result['message']
						)
					);
				}

				continue;
			}

			if ( 'key_invalid' === $result['status'] ) {
				// Key 本身有問題：冷卻 6 小時，換下一把繼續，不中斷整批。
				wxacg_set_key_cooldown(
					$entry,
					time() + ( 6 * HOUR_IN_SECONDS ),
					'invalid'
				);

				$last_error = new WP_Error(
					'ai_key_invalid',
					sprintf( '%s Key 無效或被拒絕：%s', $label, $result['message'] )
				);

				$saw_non_quota = true;
				$only_quota    = false;
				continue;
			}

			if ( 'soft_error' === $result['status'] ) {
				$last_error = new WP_Error(
					'ai_soft_error',
					sprintf( '%s：%s', $label, $result['message'] )
				);

				$saw_non_quota = true;
				$only_quota    = false;
				continue;
			}

			// fatal：參數或模型設定錯誤，換 Key 也沒用。
			return new WP_Error(
				'ai_error',
				sprintf( '%s：%s', $label, $result['message'] )
			);
		}

		// 沒有 Key 可試，或包含非配額錯誤時，不進下一輪。
		if ( 0 === $tried || ! $only_quota ) {
			break;
		}

		if ( $round < ( $max_rounds - 1 ) ) {
			sleep( min( 15, max( 5, $wait_hint ) ) );
		}
	}

	$status = wxacg_build_key_status( $pool );

	if ( $status['all_daily'] ) {
		$error = new WP_Error(
			'ai_quota_daily',
			sprintf(
				'全部 %d 把 Key 今日配額皆已用盡或無效，最快可於 %s 恢復。',
				$key_count,
				$status['soonest'] ? wp_date( 'm/d H:i', $status['soonest'] ) : '隔日'
			)
		);

		$error->add_data(
			array(
				'reset_at'   => $status['soonest'],
				'reset_text' => $status['soonest'] ? wp_date( 'm/d H:i', $status['soonest'] ) : '',
			)
		);

		return $error;
	}

	if ( $saw_non_quota && $status['ready'] > 0 && $last_error ) {
		return $last_error;
	}

	return new WP_Error(
		'ai_quota',
		'目前所有 Key 皆在冷卻中：' .
		( $last_error ? $last_error->get_error_message() : '未知原因' ) .
		(
			$status['soonest']
				? sprintf( '（最快 %s 恢復）', wp_date( 'H:i:s', $status['soonest'] ) )
				: ''
		)
	);
}

/**
 * 向下相容舊函式名（僅 Gemini）。
 */
function wxacg_call_gemini_editorial_multi( $api_keys, $start_idx, $data ) {
	$pool = array();

	foreach ( (array) $api_keys as $index => $key ) {
		$pool[] = array(
			'provider' => 'gemini',
			'key'      => $key,
			'label'    => sprintf( 'Gemini #%d', $index + 1 ),
		);
	}

	$result = wxacg_call_ai_editorial_multi( $pool, $start_idx, $data );

	return is_wp_error( $result ) ? $result : $result['text'];
}

/* ============================================================
 * Prompt 建構
 * ============================================================ */

/**
 * 產生 system／user 兩段式 prompt，供 Gemini 與 Groq 共用。
 */
function wxacg_build_editorial_prompt_parts( $data ) {
	$system  = "你是「微笑動漫」（weixiaoacg.com）的資深動漫編輯，筆名「笑編」，有超過十年動漫評論資歷。你的文字風格是：\n";
	$system .= "- 使用台灣動漫圈自然口吻，親切、有觀點，但不武斷\n";
	$system .= "- 可以帶一點幽默或個人感受，但不得誇大或捏造\n";
	$system .= "- 避免教條式套話，直接分享對作品的具體觀感\n";
	$system .= "- 句子不要過長，閱讀節奏自然\n";
	$system .= "- 使用台灣慣用詞，例如聲優、追番、新番、動畫\n";
	$system .= "- 資料不足時不要自行杜撰角色、聲優、製作人或獎項\n";
	$system .= "- 一律輸出繁體中文，且只輸出短評本文，不要任何前言或說明";

	$streaming_text = ( isset( $data['streaming'] ) && '' !== $data['streaming'] )
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

	$score_text = ! empty( $scores ) ? implode( '、', $scores ) : '暫無對外評分資料';

	$year   = isset( $data['year'] ) ? $data['year'] : '';
	$season = isset( $data['season'] ) ? $data['season'] : '';

	$air_info = trim( $year . ' ' . $season );

	if ( '' === $air_info ) {
		$air_info = '播出時間不詳';
	}

	$episodes_text = ( isset( $data['episodes'] ) && $data['episodes'] )
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

	$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
	$style   = $opening_styles[ $post_id % count( $opening_styles ) ];

	$title    = isset( $data['title'] ) ? $data['title'] : '';
	$title_ja = isset( $data['title_ja'] ) ? $data['title_ja'] : '';

	$title_text = $title;

	if ( '' !== $title_ja && $title_ja !== $title ) {
		$title_text .= '（' . $title_ja . '）';
	}

	$genres = ( isset( $data['genres'] ) && '' !== $data['genres'] )
		? $data['genres']
		: '類型資料不足';

	$studio = ( isset( $data['studio'] ) && '' !== $data['studio'] )
		? $data['studio']
		: '製作資料不足';

	$synopsis = ( isset( $data['synopsis'] ) && '' !== $data['synopsis'] )
		? $data['synopsis']
		: '暫無完整劇情簡介';

	$user  = "請為以下作品撰寫一段 80～130 字的繁體中文「AI 編輯短評草稿」，之後會交由人工編輯審核：\n\n";
	$user .= "【作品資料】\n";
	$user .= '- 標題：' . $title_text . "\n";
	$user .= '- 類型：' . $genres . "\n";
	$user .= '- 集數：' . $episodes_text . "\n";
	$user .= '- 播出：' . $air_info . "\n";
	$user .= '- 製作：' . $studio . "\n";
	$user .= '- 外部評分：' . $score_text . "\n";
	$user .= '- ' . $streaming_text . "\n";
	$user .= '- 劇情簡介：' . $synopsis . "\n\n";
	$user .= "【本次開頭方向】\n";
	$user .= $style . "\n\n";
	$user .= "【輸出規則】\n";
	$user .= "1. 只輸出短評本文，不加標題、引號、Markdown、署名或說明文字\n";
	$user .= "2. 不要以「這部動畫」、「本作」、「如果你喜歡」開頭\n";
	$user .= "3. 不要照抄劇情簡介，要加入具體但不劇透的編輯觀點\n";
	$user .= "4. 有台灣串流資訊時可自然融入；沒有資料時不要自行猜測平台\n";
	$user .= "5. 字數控制在 80～130 個繁體中文字左右\n";
	$user .= "6. 不得捏造資料中沒有提供的角色、聲優、製作人、獎項或觀看平台\n";
	$user .= "7. 不要把外部評分當作本站評分，也不要宣稱評分保證作品品質\n";

	return array(
		'system' => $system,
		'user'   => $user,
	);
}

/**
 * 向下相容：合併為單一 prompt 字串。
 */
function wxacg_build_editorial_prompt( $data ) {
	$parts = wxacg_build_editorial_prompt_parts( $data );

	return $parts['system'] . "\n\n" . $parts['user'];
}

/* ============================================================
 * 後台頁面 UI
 * ============================================================ */

function wxacg_ai_editorial_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
	}

	/*
	 * 儲存設定。
	 *
	 * 為避免把既有 Key 明文放回 HTML：
	 * - textarea 留空：保留既有 Key
	 * - textarea 貼入內容：以新內容取代該供應商既有 Key
	 * - 清除 Key：使用獨立按鈕
	 */
	if (
		isset( $_POST['wxacg_save_apikey'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		$messages = array();

		$fields = array(
			'wxacg_gemini_api_keys' => array(
				'option' => 'wxacg_gemini_api_keys',
				'name'   => 'Gemini',
			),
			'wxacg_groq_api_keys'   => array(
				'option' => 'wxacg_groq_api_keys',
				'name'   => 'Groq',
			),
		);

		$keys_changed = false;

		foreach ( $fields as $field => $meta ) {
			$raw = isset( $_POST[ $field ] )
				? trim( (string) wp_unslash( $_POST[ $field ] ) )
				: '';

			if ( '' === $raw ) {
				continue;
			}

			$keys = preg_split( '/[\r\n]+/', $raw );
			$keys = array_map( 'sanitize_text_field', $keys );
			$keys = array_map( 'trim', $keys );
			$keys = array_values( array_unique( array_filter( $keys ) ) );

			update_option( $meta['option'], $keys, false );

			if ( 'wxacg_gemini_api_keys' === $field ) {
				delete_option( 'wxacg_gemini_api_key' );
			}

			$keys_changed = true;

			$messages[] = sprintf(
				'已更新 %s：共 %d 把 Key。',
				$meta['name'],
				count( $keys )
			);
		}

		// Key 集合變更後，舊指紋冷卻紀錄已無必要。
		if ( $keys_changed ) {
			delete_option( WXACG_COOLDOWN_OPTION );
		}

		$order   = sanitize_key( isset( $_POST['wxacg_ai_provider_order'] ) ? wp_unslash( $_POST['wxacg_ai_provider_order'] ) : 'auto' );
		$choices = wxacg_provider_order_choices();

		if ( ! isset( $choices[ $order ] ) ) {
			$order = 'auto';
		}

		update_option( 'wxacg_ai_provider_order', $order, false );

		$groq_model    = isset( $_POST['wxacg_groq_model'] )
			? sanitize_text_field( wp_unslash( $_POST['wxacg_groq_model'] ) )
			: WXACG_GROQ_MODEL_DEFAULT;
		$model_choices = wxacg_groq_model_choices();

		if ( ! isset( $model_choices[ $groq_model ] ) ) {
			$groq_model = WXACG_GROQ_MODEL_DEFAULT;
		}

		update_option( 'wxacg_groq_model', $groq_model, false );

		$messages[] = 'Groq 模型：' . $groq_model . '。';

		if ( ! $keys_changed ) {
			$messages[] = 'API Key 欄位留空，已保留原有設定。';
		}

		echo '<div class="notice notice-success is-dismissible"><p>✅ ' .
			esc_html( implode( ' ', $messages ) ) .
			'</p></div>';
	}

	// 清除 API Keys。
	if (
		isset( $_POST['wxacg_clear_apikeys'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		$target = isset( $_POST['wxacg_clear_apikeys'] )
			? sanitize_key( wp_unslash( $_POST['wxacg_clear_apikeys'] ) )
			: '';

		if ( 'groq' === $target ) {
			delete_option( 'wxacg_groq_api_keys' );
			$label = 'Groq';
		} elseif ( 'gemini' === $target ) {
			delete_option( 'wxacg_gemini_api_keys' );
			delete_option( 'wxacg_gemini_api_key' );
			$label = 'Gemini';
		} else {
			delete_option( 'wxacg_groq_api_keys' );
			delete_option( 'wxacg_gemini_api_keys' );
			delete_option( 'wxacg_gemini_api_key' );
			$label = '全部';
		}

		delete_option( WXACG_COOLDOWN_OPTION );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已清除 ' .
			esc_html( $label ) .
			' API Keys 與相關冷卻紀錄。</p></div>';
	}

	// 清除冷卻紀錄。
	if (
		isset( $_POST['wxacg_clear_cooldown'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		delete_option( WXACG_COOLDOWN_OPTION );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已清除所有 Key 冷卻紀錄。</p></div>';
	}

	$gemini_keys   = wxacg_get_gemini_api_keys();
	$groq_keys     = wxacg_get_groq_api_keys();
	$provider_mode = (string) get_option( 'wxacg_ai_provider_order', 'auto' );

	if ( ! isset( wxacg_provider_order_choices()[ $provider_mode ] ) ) {
		$provider_mode = 'auto';
	}

	$pool      = wxacg_get_ai_key_pool( $provider_mode );
	$key_count = count( $pool );
	$nonce     = wp_create_nonce( 'wxacg_ai_editorial_nonce' );
	$status    = wxacg_build_key_status( $pool );

	$auto_delay = $key_count > 0 ? wxacg_auto_item_delay_ms( $pool ) : 0;

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
			支援 <strong>Google Gemini</strong>（<code><?php echo esc_html( WXACG_GEMINI_MODEL ); ?></code>）
			與 <strong>Groq</strong>（<code><?php echo esc_html( wxacg_get_groq_model() ); ?></code>）兩種 API，
			產生的短評草稿寫入 <code>anime_editor_summary</code>。
		</p>

		<p style="color:#666;">
			所有 AI 產出都會標記為 <strong>draft／待人工審核</strong>，
			不會自動填入審核作者、審核日期，也不會自動公開為正式人工短評。
		</p>

		<p style="color:#666;">
			💡 兩家的 Key 會放進同一個輪替池；分鐘級配額只做短期冷卻，
			每日配額耗盡則停用到該供應商的重置時間（Gemini＝太平洋午夜、Groq＝UTC 午夜）。
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
					'color' => $need_generate > 0 ? '#d63638' : '#00a32a',
				),
				array(
					'label' => 'Gemini Key',
					'value' => count( $gemini_keys ),
					'color' => count( $gemini_keys ) > 0 ? '#8250df' : '#888',
				),
				array(
					'label' => 'Groq Key',
					'value' => count( $groq_keys ),
					'color' => count( $groq_keys ) > 0 ? '#f2711c' : '#888',
				),
				array(
					'label' => '目前可用 Key',
					'value' => $status['ready'],
					'color' => $status['ready'] > 0 ? '#00a32a' : '#d63638',
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
			<h2 style="margin-top:0;">🔑 API Key 與供應商設定</h2>

			<form method="post">
				<?php wp_nonce_field( 'wxacg_ai_editorial_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wxacg-gemini-api-keys">Gemini API Keys</label>
						</th>
						<td>
							<textarea
								id="wxacg-gemini-api-keys"
								name="wxacg_gemini_api_keys"
								rows="5"
								autocomplete="off"
								spellcheck="false"
								style="width:460px;max-width:100%;font-family:monospace;"
								placeholder="留空＝保留目前設定。要更換請一行一把貼上。&#10;AIzaSy...key1&#10;AIzaSy...key2"
							></textarea>

							<p class="description">
								目前已設定 <strong><?php echo (int) count( $gemini_keys ); ?></strong> 把。
								申請：
								<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio</a>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-groq-api-keys">Groq API Keys</label>
						</th>
						<td>
							<textarea
								id="wxacg-groq-api-keys"
								name="wxacg_groq_api_keys"
								rows="5"
								autocomplete="off"
								spellcheck="false"
								style="width:460px;max-width:100%;font-family:monospace;"
								placeholder="留空＝保留目前設定。要更換請一行一把貼上。&#10;gsk_...key1&#10;gsk_...key2"
							></textarea>

							<p class="description">
								目前已設定 <strong><?php echo (int) count( $groq_keys ); ?></strong> 把。
								申請：
								<a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">console.groq.com/keys</a>　
								配額查詢：
								<a href="https://console.groq.com/settings/limits" target="_blank" rel="noopener noreferrer">Limits</a>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-groq-model">Groq 模型</label>
						</th>
						<td>
							<select id="wxacg-groq-model" name="wxacg_groq_model">
								<?php foreach ( wxacg_groq_model_choices() as $value => $label ) : ?>
									<option
										value="<?php echo esc_attr( $value ); ?>"
										<?php selected( wxacg_get_groq_model(), $value ); ?>
									>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								Groq 免費／開發者方案多數文字模型約為 30 RPM、1K RPD。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-provider-order">供應商優先順序</label>
						</th>
						<td>
							<select id="wxacg-provider-order" name="wxacg_ai_provider_order">
								<?php foreach ( wxacg_provider_order_choices() as $value => $label ) : ?>
									<option
										value="<?php echo esc_attr( $value ); ?>"
										<?php selected( $provider_mode, $value ); ?>
									>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								此設定同時作為批次執行時的預設值，執行前仍可臨時切換。
							</p>
						</td>
					</tr>
				</table>

				<button type="submit" name="wxacg_save_apikey" class="button button-primary">
					儲存設定
				</button>

				<button type="submit" name="wxacg_clear_cooldown" class="button" style="margin-left:8px;">
					清除 Key 冷卻紀錄
				</button>

				<button
					type="submit"
					name="wxacg_clear_apikeys"
					value="gemini"
					class="button"
					style="margin-left:8px;"
					onclick="return confirm('確定要清除全部 Gemini API Keys 嗎？');"
				>
					清除 Gemini Keys
				</button>

				<button
					type="submit"
					name="wxacg_clear_apikeys"
					value="groq"
					class="button"
					style="margin-left:8px;"
					onclick="return confirm('確定要清除全部 Groq API Keys 嗎？');"
				>
					清除 Groq Keys
				</button>

				<button
					type="submit"
					name="wxacg_clear_apikeys"
					value="all"
					class="button"
					style="margin-left:8px;color:#b32d2e;border-color:#b32d2e;"
					onclick="return confirm('確定要清除全部 API Keys 嗎？此操作無法復原。');"
				>
					全部清除
				</button>
			</form>

			<?php if ( $key_count > 0 ) : ?>
				<div style="margin-top:16px;padding:12px 16px;background:#f6f7f7;border-radius:6px;font-family:monospace;font-size:12px;line-height:1.9;">
					<?php foreach ( $status['lines'] as $line ) : ?>
						<div><?php echo esc_html( $line ); ?></div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p style="margin-top:16px;color:#d63638;">
					⚠️ 目前選擇的供應商尚未設定任何 API Key。
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $key_count > 0 ) : ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">
				<h2 style="margin-top:0;">🚀 批次產生設定</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wxacg-run-provider">本次使用</label>
						</th>
						<td>
							<select id="wxacg-run-provider">
								<?php foreach ( wxacg_provider_order_choices() as $value => $label ) : ?>
									<option
										value="<?php echo esc_attr( $value ); ?>"
										<?php selected( $provider_mode, $value ); ?>
									>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

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

								$recommended = min( 30, max( 3, $key_count * 2 ) );

								foreach ( $size_options as $value => $label ) :
									$is_recommended = ( $value === $recommended );
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
								單次請求最多執行 <?php echo (int) WXACG_BATCH_TIME_BUDGET; ?> 秒，
								超過後會先回傳目前結果，再由前端接續。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-item-delay">每筆間隔（節流）</label>
						</th>
						<td>
							<select id="wxacg-item-delay">
								<option value="0" selected>
									自動（依目前 Key 池推算，約 <?php echo (int) $auto_delay; ?> ms）
								</option>
								<option value="1000">1 秒（較快，容易碰到分鐘配額）</option>
								<option value="3000">3 秒（穩健）</option>
								<option value="6000">6 秒（非常保守）</option>
							</select>

							<p class="description">
								Groq 速度較快可用較短間隔；Gemini 免費層建議 3 秒以上。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="wxacg-sort-order">產生順序</label>
						</th>
						<td>
							<select id="wxacg-sort-order">
								<option value="new" selected>📅 新番優先（播出年份倒序）</option>
								<option value="popular">🔥 熱門優先（留言數及 AniList 評分）</option>
								<option value="default">🔢 預設順序（文章 ID）</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">覆蓋模式</th>
						<td>
							<label>
								<input type="checkbox" id="wxacg-overwrite">
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
						🚀 開始批次產生（剩餘 <?php echo (int) $need_generate; ?> 部）
					</button>

					<button id="wxacg-stop-btn" class="button" style="display:none;">
						⏹ 停止
					</button>
				</div>

				<div id="wxacg-progress" style="margin-top:20px;display:none;">
					<div style="background:#f0f0f0;border-radius:6px;height:18px;overflow:hidden;">
						<div
							id="wxacg-progress-bar"
							style="background:linear-gradient(90deg,#2271b1,#00a32a);height:100%;width:0;transition:width .4s;"
						></div>
					</div>

					<p id="wxacg-progress-text" style="margin:8px 0;color:#444;font-size:13px;"></p>
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

		var running    = false;
		var offset     = 0;
		var totalDone  = 0;
		var totalFail  = 0;
		var keyCursor  = 0;
		var timer      = null;
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

			startBtn.style.display   = 'none';
			stopBtn.style.display    = '';
			progressEl.style.display = '';
			logEl.style.display      = '';
			logEl.textContent        = '';
			progressBar.style.width  = '0%';
			progressText.textContent = '';

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
				'⏹ 已手動停止。完成 ' + totalDone + ' 筆，失敗 ' + totalFail + ' 筆。',
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
			fd.append('batch_size', document.getElementById('wxacg-batch-size').value);
			fd.append('item_delay', document.getElementById('wxacg-item-delay').value);
			fd.append('sort', document.getElementById('wxacg-sort-order').value);
			fd.append('provider', document.getElementById('wxacg-run-provider').value);
			fd.append(
				'overwrite',
				document.getElementById('wxacg-overwrite').checked ? '1' : '0'
			);
			fd.append('offset', offset);
			fd.append('key_cursor', keyCursor);

			fetch(ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			})
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
						(response.data && response.data.message
							? response.data.message
							: JSON.stringify(response)),
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
							'\n   › 來源：' + (row.source || '') +
							'　已存為待人工審核 AI 草稿',
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

				var remaining = parseInt(data.total_remaining || 0, 10);

				var percent = totalAnime > 0
					? Math.min(
						Math.max((totalAnime - remaining) / totalAnime * 100, 0),
						100
					)
					: 0;

				progressBar.style.width = percent + '%';

				setProgress(
					remaining,
					percent,
					'可用 Key ' + (data.keys_ready || 0) + '/' + (data.key_count || 0) +
					'　間隔 ' + (data.item_delay_ms || 0) + 'ms'
				);

				if (data.daily_exhausted) {
					addLog(
						'🛑 所有 API Key 今日配額已用盡' +
						(data.reset_text ? '，約 ' + data.reset_text + ' 後恢復' : '') +
						'。本次完成 ' + totalDone + ' 筆，尚餘 ' + remaining + ' 部。',
						'#f38ba8'
					);

					if (data.key_status && data.key_status.length) {
						addLog('   › ' + data.key_status.join('\n   › '), '#9399b2');
					}

					finish('▶ 配額恢復後繼續');
					return;
				}

				if (
					remaining <= 0 &&
					!document.getElementById('wxacg-overwrite').checked
				) {
					addLog(
						'🎉 全部完成！本次共產生 ' + totalDone + ' 筆待審核短評草稿。',
						'#89dceb'
					);

					finish('🚀 重新檢查');
					return;
				}

				if (parseInt(data.processed || 0, 10) === 0) {
					addLog(
						'ℹ️ 沒有更多可處理項目。完成 ' + totalDone +
						' 筆，失敗 ' + totalFail + ' 筆。',
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
						Math.round(waitMs / 1000) + ' 秒後自動繼續。',
						'#f9e2af'
					);

					countdownThen(waitMs, remaining, percent);
					return;
				}

				retryTimer = setTimeout(runBatch, NORMAL_DELAY_MS);
			})
			.catch(function (error) {
				addLog(
					'❌ fetch 錯誤：' + error.message + '（30 秒後自動重試）',
					'#f38ba8'
				);

				if (running) {
					retryTimer = setTimeout(runBatch, 30000);
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

					setProgress(remaining, percent, '恢復中…');
					runBatch();
				} else {
					setProgress(
						remaining,
						percent,
						'冷卻中，' + Math.ceil(left / 1000) + ' 秒後繼續'
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
