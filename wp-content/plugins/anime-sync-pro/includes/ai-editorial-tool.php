<?php
/**
 * 微笑動漫 — AI 編輯短評批次產生工具
 *
 * Path: wp-content/plugins/anime-sync-pro/includes/ai-editorial-tool.php
 *
 * @version 1.9.0 (2026-08-13)
 *
 * v1.9.0（配合 class-acf-fields.php 對齊）：
 *   1) 寫入 anime_editor_summary 時同步補寫 ACF field key 參照 _anime_editor_summary
 *   2) anime_tw_streaming 為 checkbox 存 key，改用 Streaming Registry 轉中文標籤
 *   3) anime_studios 為逗號分隔 text 欄位，改為 meta 優先讀取
 *   4) Prompt 補入作品類型、原作來源、台灣代理商，減少 AI 誤判季別
 *   5) 新增「本次工作階段上限」：跑滿 N 部自動停止
 *   6) 新增「每日產生上限」：伺服器端強制，跨瀏覽器分頁都算同一份額度
 *   7) 覆蓋模式新增「保護已人工審核」：跳過 status=published 或已填審核者的作品
 *   8) 可一鍵匯入編輯畫面 AI 面板（user meta asp_ai_api_key）的既有金鑰
 *   9) 短評長度驗證改為 90～240 字，對齊 ACF「建議至少 120 字」
 *
 * v1.8.0: 排序改為可組合子句，新增本季／下季／最近匯入
 * v1.7.0: 同時支援 Gemini 與 Groq，跨供應商 Key 池輪替
 * v1.6.0: 改寫入 anime_editor_summary，一律標記待人工審核草稿
 * v1.5.0: 每把 Key 獨立冷卻管理
 * v1.4.0: 支援多把 API Key 輪替
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
	define( 'WXACG_EDITORIAL_PROMPT_VERSION', '1.9.0' );
}

if ( ! defined( 'WXACG_BATCH_TIME_BUDGET' ) ) {
	define( 'WXACG_BATCH_TIME_BUDGET', 25 );
}

if ( ! defined( 'WXACG_COOLDOWN_OPTION' ) ) {
	define( 'WXACG_COOLDOWN_OPTION', 'wxacg_gemini_key_cooldowns' );
}

if ( ! defined( 'WXACG_DAILY_COUNTER_OPTION' ) ) {
	define( 'WXACG_DAILY_COUNTER_OPTION', 'wxacg_ai_daily_counter' );
}

if ( ! defined( 'WXACG_EDITORIAL_META' ) ) {
	define( 'WXACG_EDITORIAL_META', 'anime_editor_summary' );
}

/**
 * ACF 欄位 name → field key 對照。
 *
 * 寫入 meta 時一併補上 _name 參照，確保後台 ACF 編輯框正常顯示。
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

function wxacg_provider_safe_rpm( $provider ) {
	return ( 'groq' === $provider ) ? 20 : 4;
}

function wxacg_groq_model_choices() {
	return array(
		'openai/gpt-oss-120b'     => 'openai/gpt-oss-120b（品質較佳，建議）',
		'openai/gpt-oss-20b'      => 'openai/gpt-oss-20b（速度最快）',
		'llama-3.3-70b-versatile' => 'llama-3.3-70b-versatile（中文尚可）',
		'qwen/qwen3.6-27b'        => 'qwen/qwen3.6-27b（中文表現佳）',
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

/**
 * 取得編輯畫面 AI 面板（user meta）已存的金鑰，供一鍵匯入。
 */
function wxacg_get_asp_user_keys( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( $user_id <= 0 ) {
		return array();
	}

	$raw = (string) get_user_meta( $user_id, 'asp_ai_api_key', true );

	if ( '' === trim( $raw ) ) {
		return array();
	}

	$keys = preg_split( '/[\r\n]+/', $raw );
	$keys = array_map( 'trim', $keys );

	return array_values( array_unique( array_filter( $keys ) ) );
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

/* ============================================================
 * Key 冷卻狀態管理
 * ============================================================ */

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

function wxacg_next_utc_midnight() {
	$next = strtotime( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' 00:00:00 UTC' );

	return $next ? (int) $next : ( time() + ( 3 * HOUR_IN_SECONDS ) );
}

function wxacg_provider_daily_reset_ts( $provider ) {
	return ( 'groq' === $provider )
		? wxacg_next_utc_midnight()
		: wxacg_next_pacific_midnight();
}

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

		if ( false !== strpos( $type, 'RetryInfo' ) && ! empty( $detail['retryDelay'] ) ) {
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

				$quota_id = isset( $violation['quotaId'] ) ? (string) $violation['quotaId'] : '';
				$metric   = isset( $violation['quotaMetric'] ) ? (string) $violation['quotaMetric'] : '';

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
		false !== strpos( $lower, '(tpd)' )
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

	if ( 'day' === $out['scope'] ) {
		$reset_seconds = wxacg_parse_reset_duration(
			wp_remote_retrieve_header( $response, 'x-ratelimit-reset-requests' )
		);

		if ( $reset_seconds > 0 ) {
			$out['retry_after'] = $reset_seconds;
		}
	}

	return $out;
}

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
		$reason = isset( $cooldowns[ $fp ]['reason'] ) ? $cooldowns[ $fp ]['reason'] : 'unknown';

		$soonest = ( 0 === $soonest ) ? $until : min( $soonest, $until );

		if ( 'daily' === $reason ) {
			$daily++;
			$lines[] = sprintf( '%s（%s）：🛑 今日配額用盡（%s 恢復）', $label, $mask, wp_date( 'm/d H:i', $until ) );
		} elseif ( 'minute' === $reason ) {
			$lines[] = sprintf( '%s（%s）：⏳ 分鐘配額冷卻中（%s 恢復）', $label, $mask, wp_date( 'H:i:s', $until ) );
		} elseif ( 'invalid' === $reason ) {
			$daily++;
			$lines[] = sprintf( '%s（%s）：🚫 Key 無效或被拒絕（%s 後重試）', $label, $mask, wp_date( 'm/d H:i', $until ) );
		} else {
			$lines[] = sprintf( '%s（%s）：⏳ 冷卻中／原因不明（%s 恢復）', $label, $mask, wp_date( 'H:i:s', $until ) );
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
 * 每日產生上限（伺服器端強制）
 * ============================================================ */

function wxacg_get_daily_cap() {
	$cap = (int) get_option( 'wxacg_ai_daily_cap', 50 );

	return max( 0, min( 2000, $cap ) );
}

function wxacg_get_daily_usage() {
	$data  = get_option( WXACG_DAILY_COUNTER_OPTION, array() );
	$today = wp_date( 'Ymd' );

	if (
		! is_array( $data ) ||
		! isset( $data['date'] ) ||
		$data['date'] !== $today
	) {
		return array(
			'date'  => $today,
			'count' => 0,
		);
	}

	return array(
		'date'  => $today,
		'count' => max( 0, (int) $data['count'] ),
	);
}

function wxacg_bump_daily_usage( $amount = 1 ) {
	$usage = wxacg_get_daily_usage();

	$usage['count'] += max( 0, (int) $amount );

	update_option( WXACG_DAILY_COUNTER_OPTION, $usage, false );

	return $usage['count'];
}

/**
 * 今日剩餘可產生數量；0 代表已達上限，PHP_INT_MAX 代表不限。
 */
function wxacg_daily_room() {
	$cap = wxacg_get_daily_cap();

	if ( $cap <= 0 ) {
		return PHP_INT_MAX;
	}

	$usage = wxacg_get_daily_usage();

	return max( 0, $cap - $usage['count'] );
}

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
		'popular'     => '⭐ 熱門優先（留言數及 AniList 評分）',
		'default'     => '🔢 預設順序（文章 ID）',
	);
}

/**
 * 產生批次查詢用的 SELECT／JOIN／HAVING／ORDER 子句。
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

	$sel_current = $wpdb->prepare(
		"MAX(
			CASE
				WHEN CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) = %d
				 AND UPPER( TRIM( pm_se.meta_value ) ) = %s THEN 1
				ELSE 0
			END
		) AS s_current",
		$now['year'],
		$now['season']
	);

	$sel_next = $wpdb->prepare(
		"MAX(
			CASE
				WHEN CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) = %d
				 AND UPPER( TRIM( pm_se.meta_value ) ) = %s THEN 1
				ELSE 0
			END
		) AS s_next",
		$next['year'],
		$next['season']
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
				'select' => ', ' . $sel_score,
				'join'   => $join_score,
				'having' => '',
				'order'  => 'ORDER BY p.comment_count DESC, s_score DESC, p.ID ASC',
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
 * 尚未填入編輯摘要的已發佈動漫數量。
 *
 * @param string $sort    傳入排序模式時只計算該範圍。
 * @param bool   $protect 是否排除已人工審核的作品。
 */
function wxacg_count_remaining( $sort = '', $protect = true ) {
	global $wpdb;

	$clauses = ( '' !== $sort )
		? wxacg_build_sort_clauses( $sort )
		: array(
			'select' => '',
			'join'   => '',
			'having' => '',
		);

	$protect_sql = $protect ? wxacg_protect_clause() : '';
	$missing_sql = wxacg_missing_clause();

	$sql = "
		SELECT COUNT(*) FROM (
			SELECT p.ID {$clauses['select']}
			FROM {$wpdb->posts} p
			{$clauses['join']}
			WHERE p.post_type = 'anime'
			  AND p.post_status = 'publish'
			  {$missing_sql}
			  {$protect_sql}
			GROUP BY p.ID
			{$clauses['having']}
		) t
	";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $sql );
}

/* ============================================================
 * AI 草稿 Meta 寫入
 * ============================================================ */

function wxacg_save_editorial_ai_draft( $post_id, $editorial, $provider = 'gemini', $model = '' ) {
	$post_id   = (int) $post_id;
	$editorial = trim( (string) $editorial );

	if ( $post_id <= 0 || '' === $editorial ) {
		return false;
	}

	if ( '' === $model ) {
		$model = ( 'groq' === $provider ) ? wxacg_get_groq_model() : WXACG_GEMINI_MODEL;
	}

	wxacg_update_acf_meta( $post_id, WXACG_EDITORIAL_META, $editorial );
	wxacg_update_acf_meta( $post_id, 'anime_editorial_status', 'draft' );
	wxacg_update_acf_meta( $post_id, 'anime_editorial_ai_generated', 1 );
	wxacg_update_acf_meta( $post_id, 'anime_editorial_ai_needs_review', 1 );
	wxacg_update_acf_meta(
		$post_id,
		'anime_editorial_ai_model',
		sanitize_text_field( $provider . ':' . $model )
	);
	wxacg_update_acf_meta(
		$post_id,
		'anime_editorial_prompt_version',
		WXACG_EDITORIAL_PROMPT_VERSION
	);
	wxacg_update_acf_meta(
		$post_id,
		'anime_editorial_ai_generated_at',
		current_time( 'mysql' )
	);

	// 非 ACF 欄位，純粹供後台查詢與統計使用。
	update_post_meta( $post_id, 'anime_editorial_ai_provider', sanitize_key( $provider ) );

	// 新草稿尚未經人工審核，不可沿用舊的審核身分與日期。
	wxacg_delete_acf_meta( $post_id, 'anime_editorial_author_id' );
	wxacg_delete_acf_meta( $post_id, 'anime_editorial_reviewed_at' );

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

		$order_choices = wxacg_provider_order_choices();

		$provider_mode = sanitize_key(
			isset( $_POST['provider'] ) ? wp_unslash( $_POST['provider'] ) : ''
		);

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

		$sort         = sanitize_key( isset( $_POST['sort'] ) ? wp_unslash( $_POST['sort'] ) : 'season' );
		$sort_choices = wxacg_sort_choices();

		if ( ! isset( $sort_choices[ $sort ] ) ) {
			$sort = 'season';
		}

		$overwrite = ! empty( $_POST['overwrite'] );
		$protect   = ! empty( $_POST['protect'] );

		$offset = max( 0, (int) ( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ) );

		$requested_size = min(
			max( 1, (int) ( isset( $_POST['batch_size'] ) ? $_POST['batch_size'] : 3 ) ),
			30
		);

		// 本次工作階段剩餘額度（0 或未傳代表不限）。
		$session_left = (int) ( isset( $_POST['session_left'] ) ? $_POST['session_left'] : 0 );

		if ( $session_left <= 0 ) {
			$session_left = PHP_INT_MAX;
		}

		$daily_room = wxacg_daily_room();
		$daily_cap  = wxacg_get_daily_cap();
		$daily_used = wxacg_get_daily_usage();

		if ( $daily_room <= 0 ) {
			wp_send_json_success(
				array(
					'results'          => array(),
					'processed'        => 0,
					'succeeded'        => 0,
					'failed'           => 0,
					'total_remaining'  => wxacg_count_remaining( '', $protect ),
					'scope_remaining'  => wxacg_count_remaining( $sort, $protect ),
					'scope_label'      => $sort_choices[ $sort ],
					'next_offset'      => $offset,
					'next_key_cursor'  => 0,
					'key_count'        => $key_count,
					'keys_ready'       => 0,
					'key_status'       => array(),
					'rate_limited'     => false,
					'daily_exhausted'  => false,
					'daily_cap_hit'    => true,
					'daily_cap'        => $daily_cap,
					'daily_used'       => $daily_used['count'],
					'reset_text'       => '',
					'cooldown_sec'     => 0,
					'item_delay_ms'    => 0,
				)
			);
		}

		$batch_size = (int) min( $requested_size, $daily_room, $session_left );
		$batch_size = max( 1, $batch_size );

		$item_delay_ms = (int) ( isset( $_POST['item_delay'] ) ? $_POST['item_delay'] : 0 );

		if ( $item_delay_ms <= 0 ) {
			$item_delay_ms = wxacg_auto_item_delay_ms( $pool );
		}

		$item_delay_ms = min( max( $item_delay_ms, 100 ), 15000 );

		$key_cursor = ( (int) ( isset( $_POST['key_cursor'] ) ? $_POST['key_cursor'] : 0 ) ) % $key_count;

		if ( $key_cursor < 0 ) {
			$key_cursor += $key_count;
		}

		$pre_status = wxacg_build_key_status( $pool );

		if ( $pre_status['all_daily'] ) {
			wp_send_json_success(
				array(
					'results'         => array(),
					'processed'       => 0,
					'succeeded'       => 0,
					'failed'          => 0,
					'total_remaining' => wxacg_count_remaining( '', $protect ),
					'scope_remaining' => wxacg_count_remaining( $sort, $protect ),
					'scope_label'     => $sort_choices[ $sort ],
					'next_offset'     => $offset,
					'next_key_cursor' => $key_cursor,
					'key_count'       => $key_count,
					'keys_ready'      => 0,
					'key_status'      => $pre_status['lines'],
					'rate_limited'    => false,
					'daily_exhausted' => true,
					'daily_cap_hit'   => false,
					'daily_cap'       => $daily_cap,
					'daily_used'      => $daily_used['count'],
					'reset_text'      => $pre_status['soonest'] ? wp_date( 'm/d H:i', $pre_status['soonest'] ) : '',
					'cooldown_sec'    => 0,
					'item_delay_ms'   => $item_delay_ms,
				)
			);
		}

		global $wpdb;

		$clauses     = wxacg_build_sort_clauses( $sort );
		$protect_sql = $protect ? wxacg_protect_clause() : '';
		$missing_sql = $overwrite ? '' : wxacg_missing_clause();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.comment_count {$clauses['select']}
			 FROM {$wpdb->posts} p
			 {$clauses['join']}
			 WHERE p.post_type = 'anime'
			   AND p.post_status = 'publish'
			   {$missing_sql}
			   {$protect_sql}
			 GROUP BY p.ID, p.post_title, p.comment_count, p.post_date
			 {$clauses['having']}
			 {$clauses['order']}
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
		$saved_count     = 0;
		$started_at      = microtime( true );
		$post_total      = count( $posts );

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
			$result      = wxacg_call_ai_editorial_multi( $pool, $start_index, $data );

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
					$saved_count++;

					$results[] = array(
						'id'        => $post_id,
						'title'     => $post->post_title,
						'status'    => 'success',
						'editorial' => $result['text'],
						'source'    => $result['label'] . '／' . $result['model'],
						'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
					);
				}

				$attempted++;
			}

			if ( $attempted < $post_total ) {
				usleep( $item_delay_ms * 1000 );
			}
		}

		if ( $saved_count > 0 ) {
			wxacg_bump_daily_usage( $saved_count );
		}

		$error_count = 0;

		foreach ( $results as $row ) {
			if ( isset( $row['status'] ) && 'error' === $row['status'] ) {
				$error_count++;
			}
		}

		$status       = wxacg_build_key_status( $pool );
		$cooldown_sec = 0;

		if ( $rate_limited && $status['soonest'] > 0 ) {
			$cooldown_sec = max( 5, min( 180, $status['soonest'] - time() + 2 ) );
		}

		$daily_used_now = wxacg_get_daily_usage();

		wp_send_json_success(
			array(
				'results'         => $results,
				'processed'       => count( $results ),
				'succeeded'       => $saved_count,
				'failed'          => $error_count,
				'total_remaining' => wxacg_count_remaining( '', $protect ),
				'scope_remaining' => wxacg_count_remaining( $sort, $protect ),
				'scope_label'     => $sort_choices[ $sort ],

				// 非覆蓋模式中成功項目已離開查詢集合，offset 僅跳過仍留下的失敗項目。
				'next_offset'     => $overwrite
					? ( $offset + count( $results ) )
					: ( $offset + $error_count ),

				'next_key_cursor' => ( $key_cursor + $attempted ) % $key_count,
				'key_count'       => $key_count,
				'keys_ready'      => $status['ready'],
				'key_status'      => $status['lines'],
				'rate_limited'    => $rate_limited,
				'daily_exhausted' => $daily_exhausted,
				'daily_cap_hit'   => ( $daily_cap > 0 && $daily_used_now['count'] >= $daily_cap ),
				'daily_cap'       => $daily_cap,
				'daily_used'      => $daily_used_now['count'],
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

/* ============================================================
 * 蒐集動漫資料（對齊 class-acf-fields.php 定義）
 * ============================================================ */

/**
 * 台灣串流平台 key → 中文標籤。
 */
function wxacg_streaming_label_map() {
	if (
		class_exists( 'Anime_Sync_Streaming_Registry' ) &&
		method_exists( 'Anime_Sync_Streaming_Registry', 'get_acf_choices' )
	) {
		$choices = Anime_Sync_Streaming_Registry::get_acf_choices();

		if ( is_array( $choices ) ) {
			return $choices;
		}
	}

	return array();
}

function wxacg_distributor_label_map() {
	return array(
		'muse'      => '木棉花',
		'medialink' => '曼迪傳播',
		'linbang'   => '羚邦',
		'tropic'    => '回歸線娛樂',
		'proware'   => '普威爾',
		'kadokawa'  => '台灣角川',
		'gungho'    => '群英社',
		'tien'      => '提恩傳媒',
		'garage'    => '車庫娛樂',
		'carsun'    => '采昌國際',
		'jbf'       => '日本橋文化',
		'righttime' => '利得時代',
		'aniplus'   => 'ANIPLUS Asia',
		'tongli'    => '東立出版社',
		'remow'     => 'REMOW',
		'gaga'      => 'GaGa OOLala',
	);
}

function wxacg_format_label( $format ) {
	$map = array(
		'TV'       => '電視動畫',
		'TV_SHORT' => '短篇電視動畫',
		'MOVIE'    => '劇場版',
		'SPECIAL'  => '特別篇',
		'OVA'      => 'OVA',
		'ONA'      => 'ONA（網路動畫）',
		'MUSIC'    => '音樂 MV',
	);

	$format = strtoupper( trim( (string) $format ) );

	return isset( $map[ $format ] ) ? $map[ $format ] : $format;
}

function wxacg_source_label( $source ) {
	$map = array(
		'ORIGINAL'           => '原創',
		'MANGA'              => '漫畫改編',
		'LIGHT_NOVEL'        => '輕小說改編',
		'VISUAL_NOVEL'       => '視覺小說改編',
		'VIDEO_GAME'         => '電子遊戲改編',
		'GAME'               => '桌遊／卡牌改編',
		'NOVEL'              => '小說改編',
		'WEB_NOVEL'          => '網路小說改編',
		'WEB_MANGA'          => '網路漫畫改編',
		'DOUJINSHI'          => '同人誌改編',
		'ANIME'              => '動畫',
		'COMIC'              => '歐美漫畫改編',
		'LIVE_ACTION'        => '真人影視改編',
		'MULTIMEDIA_PROJECT' => '多媒體企劃',
		'PICTURE_BOOK'       => '繪本改編',
		'OTHER'              => '其他',
	);

	$source = strtoupper( trim( (string) $source ) );

	return isset( $map[ $source ] ) ? $map[ $source ] : $source;
}

function wxacg_gather_anime_data_for_editorial( $post_id ) {
	$post_id = (int) $post_id;

	// 簡介：anime_synopsis_chinese 為 ACF textarea，new_lines=br。
	$synopsis = trim( (string) get_post_meta( $post_id, 'anime_synopsis_chinese', true ) );

	if ( '' === $synopsis ) {
		$synopsis = trim( (string) get_post_meta( $post_id, 'anime_synopsis', true ) );
	}

	$synopsis = wp_strip_all_tags( $synopsis );
	$synopsis = trim( preg_replace( "/\s+/u", ' ', $synopsis ) );
	$synopsis = wxacg_editorial_substr( $synopsis, 0, 420 );

	// 製作公司：anime_studios 為逗號分隔 text 欄位。
	$studio_meta = get_post_meta( $post_id, 'anime_studios', true );

	if ( is_array( $studio_meta ) ) {
		$studio_meta = implode( '、', array_filter( array_map( 'sanitize_text_field', $studio_meta ) ) );
	}

	$studios = trim( wp_strip_all_tags( (string) $studio_meta ) );
	$studios = str_replace( array( ', ', ',' ), '、', $studios );

	if ( '' === $studios ) {
		$studio_terms = get_the_terms( $post_id, 'anime_studio_tax' );

		if ( is_array( $studio_terms ) && ! is_wp_error( $studio_terms ) ) {
			$names = array();

			foreach ( $studio_terms as $term ) {
				if ( isset( $term->name ) ) {
					$names[] = $term->name;
				}
			}

			$studios = implode( '、', array_values( array_unique( $names ) ) );
		}
	}

	// 類型 taxonomy：站台可能使用不同註冊名，依序嘗試。
	$genres = '';

	foreach ( array( 'anime_genre_tax', 'anime_genre', 'genre' ) as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! is_array( $terms ) || is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}

		$names = array();

		foreach ( $terms as $term ) {
			if ( isset( $term->name ) ) {
				$names[] = $term->name;
			}
		}

		if ( ! empty( $names ) ) {
			$genres = implode( '、', array_values( array_unique( $names ) ) );
			break;
		}
	}

	// 台灣串流：anime_tw_streaming 為 checkbox 存 key。
	$labels    = wxacg_streaming_label_map();
	$tw_keys   = get_post_meta( $post_id, 'anime_tw_streaming', true );
	$streaming = array();

	if ( is_array( $tw_keys ) ) {
		foreach ( $tw_keys as $key ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			$streaming[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
	} elseif ( is_string( $tw_keys ) && '' !== trim( $tw_keys ) ) {
		$streaming[] = isset( $labels[ $tw_keys ] ) ? $labels[ $tw_keys ] : trim( $tw_keys );
	}

	$other = trim( (string) get_post_meta( $post_id, 'anime_tw_streaming_other', true ) );

	if ( '' !== $other ) {
		foreach ( preg_split( '/[,，、]+/u', $other ) as $item ) {
			$item = trim( $item );

			if ( '' !== $item ) {
				$streaming[] = $item;
			}
		}
	}

	$streaming = array_values( array_unique( array_filter( $streaming ) ) );

	// 台灣代理商。
	$distributor_key = trim( (string) get_post_meta( $post_id, 'anime_tw_distributor', true ) );
	$distributor_map = wxacg_distributor_label_map();
	$distributor     = '';

	if ( 'other' === $distributor_key ) {
		$distributor = trim( (string) get_post_meta( $post_id, 'anime_tw_distributor_custom', true ) );
	} elseif ( '' !== $distributor_key ) {
		$distributor = isset( $distributor_map[ $distributor_key ] )
			? $distributor_map[ $distributor_key ]
			: $distributor_key;
	}

	$year = trim( (string) get_post_meta( $post_id, 'anime_season_year', true ) );

	// ACF 允許 0 代表尚未公布檔期。
	if ( '0' === $year ) {
		$year = '';
	}

	return array(
		'post_id'     => $post_id,
		'title'       => get_the_title( $post_id ),
		'title_ja'    => trim( (string) get_post_meta( $post_id, 'anime_title_native', true ) ),
		'synopsis'    => $synopsis,
		'genres'      => $genres,
		'episodes'    => trim( (string) get_post_meta( $post_id, 'anime_episodes', true ) ),
		'year'        => $year,
		'season'      => wxacg_season_label( get_post_meta( $post_id, 'anime_season', true ) ),
		'format'      => wxacg_format_label( get_post_meta( $post_id, 'anime_format', true ) ),
		'source'      => wxacg_source_label( get_post_meta( $post_id, 'anime_source', true ) ),
		'score_al'    => trim( (string) get_post_meta( $post_id, 'anime_score_anilist', true ) ),
		'score_mal'   => trim( (string) get_post_meta( $post_id, 'anime_score_mal', true ) ),
		'score_bgm'   => trim( (string) get_post_meta( $post_id, 'anime_score_bangumi', true ) ),
		'streaming'   => implode( '、', $streaming ),
		'studio'      => $studios,
		'distributor' => $distributor,
	);
}

/* ============================================================
 * 供應商請求層（統一回傳格式）
 * ============================================================ */

function wxacg_request_gemini( $api_key, $parts ) {
	$endpoint = sprintf(
		'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
		rawurlencode( WXACG_GEMINI_MODEL ),
		rawurlencode( $api_key )
	);

	$request_body = array(
		'contents'           => array(
			array(
				'role'  => 'user',
				'parts' => array(
					array( 'text' => $parts['user'] ),
				),
			),
		),
		'system_instruction' => array(
			'parts' => array(
				array( 'text' => $parts['system'] ),
			),
		),
		'generationConfig'   => array(
			'maxOutputTokens' => 512,
			'temperature'     => 0.9,
			'thinkingConfig'  => array(
				'thinkingLevel' => 'minimal',
			),
		),
		'safetySettings'     => array(
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
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
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
	$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'HTTP ' . $code;

	if ( 429 === $code ) {
		$quota = wxacg_classify_quota_error( $decoded, $body );

		return array(
			'status'      => 'quota',
			'scope'       => $quota['scope'],
			'retry_after' => $quota['retry_after'],
			'message'     => $quota['quota_id'] ? sprintf( '%s（%s）', $message, $quota['quota_id'] ) : $message,
		);
	}

	if ( 401 === $code || 403 === $code ) {
		return array(
			'status'  => 'key_invalid',
			'message' => sprintf( 'HTTP %d：%s', $code, $message ),
		);
	}

	if ( $code >= 500 ) {
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
		'max_completion_tokens' => 1200,
		'stream'                => false,
	);

	if ( 0 === strpos( $model, 'openai/gpt-oss' ) || 0 === strpos( $model, 'qwen/' ) ) {
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
				'message' => sprintf( '回傳空白（finish_reason：%s，可能思考佔滿 token）', $finish ),
			);
		}

		return array(
			'status' => 'ok',
			'text'   => $text,
			'model'  => $model,
		);
	}

	$decoded = json_decode( $body, true );
	$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'HTTP ' . $code;

	if ( 429 === $code ) {
		$quota = wxacg_classify_groq_quota( $response, $body );

		return array(
			'status'      => 'quota',
			'scope'       => $quota['scope'],
			'retry_after' => $quota['retry_after'],
			'message'     => $quota['quota_id'] ? sprintf( '%s（%s）', $message, $quota['quota_id'] ) : $message,
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
						sprintf( '%s 今日配額用盡，停用至 %s', $label, wp_date( 'm/d H:i', $until ) )
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
					wxacg_set_key_cooldown( $entry, time() + ( 15 * MINUTE_IN_SECONDS ), 'unknown' );

					$last_error = new WP_Error(
						'ai_quota_single',
						sprintf( '%s 配額錯誤（類型不明，冷卻 15 分鐘）：%s', $label, $result['message'] )
					);
				}

				continue;
			}

			if ( 'key_invalid' === $result['status'] ) {
				wxacg_set_key_cooldown( $entry, time() + ( 6 * HOUR_IN_SECONDS ), 'invalid' );

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

			return new WP_Error( 'ai_error', sprintf( '%s：%s', $label, $result['message'] ) );
		}

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
		( $status['soonest'] ? sprintf( '（最快 %s 恢復）', wp_date( 'H:i:s', $status['soonest'] ) ) : '' )
	);
}

/* ============================================================
 * Prompt 建構
 * ============================================================ */

function wxacg_build_editorial_prompt_parts( $data ) {
	$system  = "你是「微笑動漫」（weixiaoacg.com）的資深動漫編輯，筆名「笑編」，有超過十年動漫評論資歷。你的文字風格是：\n";
	$system .= "- 使用台灣動漫圈自然口吻，親切、有觀點，但不武斷\n";
	$system .= "- 可以帶一點幽默或個人感受，但不得誇大或捏造\n";
	$system .= "- 避免教條式套話，直接分享對作品的具體觀感\n";
	$system .= "- 句子不要過長，閱讀節奏自然\n";
	$system .= "- 使用台灣慣用詞，例如聲優、追番、新番、動畫、劇場版\n";
	$system .= "- 資料不足時不要自行杜撰角色、聲優、製作人或獎項\n";
	$system .= "- 一律輸出繁體中文，且只輸出短評本文，不要任何前言、標題或說明";

	$scores = array();

	if ( '' !== $data['score_al'] ) {
		$scores[] = 'AniList ' . round( (float) $data['score_al'] / 10, 1 );
	}

	if ( '' !== $data['score_mal'] ) {
		$scores[] = 'MAL ' . round( (float) $data['score_mal'] / 10, 1 );
	}

	if ( '' !== $data['score_bgm'] ) {
		$scores[] = 'Bangumi ' . round( (float) $data['score_bgm'] / 10, 1 );
	}

	$score_text = ! empty( $scores ) ? implode( '、', $scores ) . '（滿分 10）' : '暫無對外評分資料';

	$air_info = trim( $data['year'] . ' ' . $data['season'] );

	if ( '' === $air_info ) {
		$air_info = '播出時間尚未公布';
	}

	$episodes_text = ( '' !== $data['episodes'] && '0' !== $data['episodes'] )
		? $data['episodes'] . ' 集'
		: '集數未定';

	$streaming_text = ( '' !== $data['streaming'] )
		? '台灣合法串流：' . $data['streaming']
		: '目前無台灣官方授權串流資訊';

	$opening_styles = array(
		'用這部作品最讓你印象深刻的一個細節開頭（不要劇透關鍵情節）',
		'用評分或市場反應來帶出這部作品的定位',
		'先說這部適合什麼口味的觀眾，再延伸到作品本身',
		'從類型或題材的角度切入，說說這部的與眾不同之處',
		'先給一個對這部作品的直覺評價，再用具體理由支撐',
		'從製作公司或原作來源的角度說起（僅限資料足夠時）',
	);

	$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
	$style   = $opening_styles[ $post_id % count( $opening_styles ) ];

	$title_text = $data['title'];

	if ( '' !== $data['title_ja'] && $data['title_ja'] !== $data['title'] ) {
		$title_text .= '（' . $data['title_ja'] . '）';
	}

	$user  = "請為以下作品撰寫一段 120～160 字的繁體中文「AI 編輯短評草稿」，之後會交由人工編輯審核：\n\n";
	$user .= "【作品資料】\n";
	$user .= '- 標題：' . $title_text . "\n";
	$user .= '- 作品類型：' . ( '' !== $data['format'] ? $data['format'] : '未標註' ) . "\n";
	$user .= '- 原作來源：' . ( '' !== $data['source'] ? $data['source'] : '未標註' ) . "\n";
	$user .= '- 題材類型：' . ( '' !== $data['genres'] ? $data['genres'] : '類型資料不足' ) . "\n";
	$user .= '- 集數：' . $episodes_text . "\n";
	$user .= '- 播出：' . $air_info . "\n";
	$user .= '- 製作公司：' . ( '' !== $data['studio'] ? $data['studio'] : '製作資料不足' ) . "\n";

	if ( '' !== $data['distributor'] ) {
		$user .= '- 台灣代理：' . $data['distributor'] . "\n";
	}

	$user .= '- 外部評分：' . $score_text . "\n";
	$user .= '- ' . $streaming_text . "\n";
	$user .= '- 劇情簡介：' . ( '' !== $data['synopsis'] ? $data['synopsis'] : '暫無完整劇情簡介' ) . "\n\n";
	$user .= "【本次開頭方向】\n";
	$user .= $style . "\n\n";
	$user .= "【輸出規則】\n";
	$user .= "1. 只輸出短評本文，不加標題、引號、Markdown、署名或說明文字\n";
	$user .= "2. 不要以「這部動畫」、「本作」、「如果你喜歡」開頭\n";
	$user .= "3. 不要照抄劇情簡介，要加入具體但不劇透的編輯觀點\n";
	$user .= "4. 有台灣串流資訊時可自然融入；沒有資料時不要自行猜測平台\n";
	$user .= "5. 字數控制在 120～160 個繁體中文字，低於 90 字視為不合格\n";
	$user .= "6. 不得捏造資料中沒有提供的角色、聲優、製作人、獎項或觀看平台\n";
	$user .= "7. 不要把外部評分當作本站評分，也不要宣稱評分保證作品品質\n";
	$user .= "8. 若為劇場版或續作，敘述時要與 TV 版區分清楚，不可混淆季別\n";

	return array(
		'system' => $system,
		'user'   => $user,
	);
}

/* ============================================================
 * 後台頁面 UI
 * ============================================================ */

function wxacg_ai_editorial_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
	}

	// 儲存設定。
	if ( isset( $_POST['wxacg_save_apikey'] ) && check_admin_referer( 'wxacg_ai_editorial_save' ) ) {
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
			$raw = isset( $_POST[ $field ] ) ? trim( (string) wp_unslash( $_POST[ $field ] ) ) : '';

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

			$messages[] = sprintf( '已更新 %s：共 %d 把 Key。', $meta['name'], count( $keys ) );
		}

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

		$daily_cap = isset( $_POST['wxacg_ai_daily_cap'] ) ? (int) $_POST['wxacg_ai_daily_cap'] : 50;
		$daily_cap = max( 0, min( 2000, $daily_cap ) );

		update_option( 'wxacg_ai_daily_cap', $daily_cap, false );

		$messages[] = sprintf(
			'Groq 模型：%s，每日上限：%s。',
			$groq_model,
			$daily_cap > 0 ? $daily_cap . ' 部' : '不限'
		);

		if ( ! $keys_changed ) {
			$messages[] = 'API Key 欄位留空，已保留原有設定。';
		}

		echo '<div class="notice notice-success is-dismissible"><p>✅ ' .
			esc_html( implode( ' ', $messages ) ) . '</p></div>';
	}

	// 從編輯畫面 AI 面板匯入金鑰。
	if ( isset( $_POST['wxacg_import_user_keys'] ) && check_admin_referer( 'wxacg_ai_editorial_save' ) ) {
		$target = sanitize_key( wp_unslash( $_POST['wxacg_import_user_keys'] ) );
		$source = wxacg_get_asp_user_keys();

		if ( empty( $source ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>⚠️ 您的編輯畫面 AI 面板尚未儲存任何金鑰，無可匯入項目。</p></div>';
		} else {
			$option  = ( 'groq' === $target ) ? 'wxacg_groq_api_keys' : 'wxacg_gemini_api_keys';
			$existing = ( 'groq' === $target ) ? wxacg_get_groq_api_keys() : wxacg_get_gemini_api_keys();

			$merged = array_values( array_unique( array_merge( $existing, $source ) ) );

			update_option( $option, $merged, false );
			delete_option( WXACG_COOLDOWN_OPTION );

			echo '<div class="notice notice-success is-dismissible"><p>✅ 已從編輯畫面匯入 ' .
				esc_html( count( $source ) ) . ' 把金鑰，目前 ' .
				esc_html( 'groq' === $target ? 'Groq' : 'Gemini' ) . ' 共 ' .
				esc_html( count( $merged ) ) . ' 把。</p></div>';
		}
	}

	// 清除 API Keys。
	if ( isset( $_POST['wxacg_clear_apikeys'] ) && check_admin_referer( 'wxacg_ai_editorial_save' ) ) {
		$target = sanitize_key( wp_unslash( $_POST['wxacg_clear_apikeys'] ) );

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
			esc_html( $label ) . ' API Keys 與相關冷卻紀錄。</p></div>';
	}

	// 清除冷卻紀錄。
	if ( isset( $_POST['wxacg_clear_cooldown'] ) && check_admin_referer( 'wxacg_ai_editorial_save' ) ) {
		delete_option( WXACG_COOLDOWN_OPTION );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已清除所有 Key 冷卻紀錄。</p></div>';
	}

	// 重設今日用量。
	if ( isset( $_POST['wxacg_reset_daily'] ) && check_admin_referer( 'wxacg_ai_editorial_save' ) ) {
		delete_option( WXACG_DAILY_COUNTER_OPTION );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已重設今日產生用量計數。</p></div>';
	}

	$gemini_keys   = wxacg_get_gemini_api_keys();
	$groq_keys     = wxacg_get_groq_api_keys();
	$user_keys     = wxacg_get_asp_user_keys();
	$provider_mode = (string) get_option( 'wxacg_ai_provider_order', 'auto' );

	$order_choices = wxacg_provider_order_choices();

	if ( ! isset( $order_choices[ $provider_mode ] ) ) {
		$provider_mode = 'auto';
	}

	$pool      = wxacg_get_ai_key_pool( $provider_mode );
	$key_count = count( $pool );
	$nonce     = wp_create_nonce( 'wxacg_ai_editorial_nonce' );
	$status    = wxacg_build_key_status( $pool );

	$auto_delay = $key_count > 0 ? wxacg_auto_item_delay_ms( $pool ) : 0;

	$daily_cap   = wxacg_get_daily_cap();
	$daily_usage = wxacg_get_daily_usage();

	global $wpdb;

	$total_anime = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type = 'anime' AND post_status = 'publish'"
	);

	$need_generate = wxacg_count_remaining( '', true );
	$has_summary   = max( 0, $total_anime - $need_generate );

	$default_sort  = 'season';
	$scope_default = wxacg_count_remaining( $default_sort, true );
	?>
	<div class="wrap">
		<h1>✍️ AI 編輯短評批次產生器</h1>

		<p style="color:#666;">
			支援 <strong>Google Gemini</strong>（<code><?php echo esc_html( WXACG_GEMINI_MODEL ); ?></code>）
			與 <strong>Groq</strong>（<code><?php echo esc_html( wxacg_get_groq_model() ); ?></code>），
			產出寫入 ACF 欄位 <code>anime_editor_summary</code>，並自動標記為
			<strong>draft／待人工審核</strong>，不會填入審核者與審核日期。
		</p>

		<p style="color:#666;">
			💡 設有「每日上限」與「本次工作階段上限」雙層煞車，可以分很多天慢慢補齊，
			不必一次跑完全站作品。
		</p>

		<div style="display:flex;gap:14px;margin:20px 0;flex-wrap:wrap;">
			<?php
			$cards = array(
				array(
					'label' => '動漫總數',
					'value' => $total_anime,
					'color' => '#2271b1',
				),
				array(
					'label' => '已有短評',
					'value' => $has_summary,
					'color' => '#00a32a',
				),
				array(
					'label' => '待產生',
					'value' => $need_generate,
					'color' => $need_generate > 0 ? '#d63638' : '#00a32a',
				),
				array(
					'label' => '今日已產生',
					'value' => $daily_cap > 0
						? $daily_usage['count'] . ' / ' . $daily_cap
						: $daily_usage['count'],
					'color' => ( $daily_cap > 0 && $daily_usage['count'] >= $daily_cap ) ? '#d63638' : '#8250df',
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
					'label' => '可用 Key',
					'value' => $status['ready'],
					'color' => $status['ready'] > 0 ? '#00a32a' : '#d63638',
				),
			);

			foreach ( $cards as $card ) :
				?>
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 22px;text-align:center;min-width:120px;box-shadow:0 1px 3px rgba(0,0,0,.07);">
					<div style="font-size:30px;font-weight:700;color:<?php echo esc_attr( $card['color'] ); ?>;">
						<?php echo esc_html( $card['value'] ); ?>
					</div>
					<div style="color:#555;margin-top:4px;font-size:13px;">
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
						<th scope="row"><label for="wxacg-gemini-api-keys">Gemini API Keys</label></th>
						<td>
							<textarea
								id="wxacg-gemini-api-keys"
								name="wxacg_gemini_api_keys"
								rows="4"
								autocomplete="off"
								spellcheck="false"
								style="width:460px;max-width:100%;font-family:monospace;"
								placeholder="留空＝保留目前設定。要更換請一行一把貼上。"
							></textarea>

							<p class="description">
								目前 <strong><?php echo (int) count( $gemini_keys ); ?></strong> 把。
								申請：<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio</a>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-groq-api-keys">Groq API Keys</label></th>
						<td>
							<textarea
								id="wxacg-groq-api-keys"
								name="wxacg_groq_api_keys"
								rows="4"
								autocomplete="off"
								spellcheck="false"
								style="width:460px;max-width:100%;font-family:monospace;"
								placeholder="留空＝保留目前設定。要更換請一行一把貼上。"
							></textarea>

							<p class="description">
								目前 <strong><?php echo (int) count( $groq_keys ); ?></strong> 把。
								申請：<a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">console.groq.com/keys</a>
							</p>
						</td>
					</tr>

					<?php if ( ! empty( $user_keys ) ) : ?>
						<tr>
							<th scope="row">從編輯畫面匯入</th>
							<td>
								<p class="description" style="margin-top:0;">
									偵測到您在動畫編輯畫面的 AI 面板已存有
									<strong><?php echo (int) count( $user_keys ); ?></strong> 把金鑰，可一鍵併入本工具。
								</p>

								<button type="submit" name="wxacg_import_user_keys" value="gemini" class="button">
									匯入為 Gemini Keys
								</button>

								<button type="submit" name="wxacg_import_user_keys" value="groq" class="button" style="margin-left:8px;">
									匯入為 Groq Keys
								</button>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><label for="wxacg-groq-model">Groq 模型</label></th>
						<td>
							<select id="wxacg-groq-model" name="wxacg_groq_model">
								<?php foreach ( wxacg_groq_model_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( wxacg_get_groq_model(), $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-provider-order">供應商優先順序</label></th>
						<td>
							<select id="wxacg-provider-order" name="wxacg_ai_provider_order">
								<?php foreach ( $order_choices as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $provider_mode, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-daily-cap">每日產生上限</label></th>
						<td>
							<input
								type="number"
								id="wxacg-daily-cap"
								name="wxacg_ai_daily_cap"
								value="<?php echo (int) $daily_cap; ?>"
								min="0"
								max="2000"
								step="5"
								style="width:100px;"
							>
							<span style="color:#666;">部／天（填 0 表示不限）</span>

							<p class="description">
								伺服器端強制計算，開幾個分頁都算同一份額度，站台時區每日午夜自動歸零。
								今日已用 <strong><?php echo (int) $daily_usage['count']; ?></strong> 部。
							</p>
						</td>
					</tr>
				</table>

				<button type="submit" name="wxacg_save_apikey" class="button button-primary">儲存設定</button>

				<button type="submit" name="wxacg_clear_cooldown" class="button" style="margin-left:8px;">
					清除 Key 冷卻紀錄
				</button>

				<button type="submit" name="wxacg_reset_daily" class="button" style="margin-left:8px;">
					重設今日用量
				</button>

				<button
					type="submit"
					name="wxacg_clear_apikeys"
					value="all"
					class="button"
					style="margin-left:8px;color:#b32d2e;border-color:#b32d2e;"
					onclick="return confirm('確定要清除全部 API Keys 嗎？此操作無法復原。');"
				>
					清除全部 Keys
				</button>
			</form>

			<?php if ( $key_count > 0 ) : ?>
				<div style="margin-top:16px;padding:12px 16px;background:#f6f7f7;border-radius:6px;font-family:monospace;font-size:12px;line-height:1.9;">
					<?php foreach ( $status['lines'] as $line ) : ?>
						<div><?php echo esc_html( $line ); ?></div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p style="margin-top:16px;color:#d63638;">⚠️ 目前選擇的供應商尚未設定任何 API Key。</p>
			<?php endif; ?>
		</div>

		<?php if ( $key_count > 0 ) : ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">
				<h2 style="margin-top:0;">🚀 批次產生設定</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wxacg-run-provider">本次使用</label></th>
						<td>
							<select id="wxacg-run-provider">
								<?php foreach ( $order_choices as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $provider_mode, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-sort-order">產生順序</label></th>
						<td>
							<select id="wxacg-sort-order">
								<?php foreach ( wxacg_sort_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $default_sort, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								「只跑本季」會嚴格過濾，跑完該季自動停止，適合每季開播前快速補齊。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-session-limit">本次工作階段上限</label></th>
						<td>
							<select id="wxacg-session-limit">
								<option value="5">5 部（試跑）</option>
								<option value="10" selected>10 部（建議）</option>
								<option value="20">20 部</option>
								<option value="30">30 部</option>
								<option value="50">50 部</option>
								<option value="0">不限（跑到配額或上限為止）</option>
							</select>

							<p class="description">
								跑滿設定數量就自動停止並顯示成果，方便您抽查品質後再決定要不要繼續。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-batch-size">每批數量</label></th>
						<td>
							<select id="wxacg-batch-size">
								<option value="1">1 部（最保守）</option>
								<option value="2">2 部</option>
								<option value="3" selected>3 部（建議）</option>
								<option value="5">5 部</option>
								<option value="10">10 部</option>
							</select>

							<p class="description">
								單次請求最多執行 <?php echo (int) WXACG_BATCH_TIME_BUDGET; ?> 秒，超過會先回傳結果再由前端接續。
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wxacg-item-delay">每筆間隔</label></th>
						<td>
							<select id="wxacg-item-delay">
								<option value="0" selected>自動（約 <?php echo (int) $auto_delay; ?> ms）</option>
								<option value="1000">1 秒</option>
								<option value="3000">3 秒（穩健）</option>
								<option value="6000">6 秒（非常保守）</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">保護與覆蓋</th>
						<td>
							<label style="display:block;margin-bottom:8px;">
								<input type="checkbox" id="wxacg-protect" checked>
								<strong>保護已人工審核的作品</strong>（狀態為「已發布」或已填寫審核者時一律跳過）
							</label>

							<label style="display:block;">
								<input type="checkbox" id="wxacg-overwrite">
								覆蓋模式：連同已有短評的作品一起重新產生
							</label>

							<p class="description" style="color:#b32d2e;">
								⚠️ 覆蓋會把內容重設為 AI 草稿並清除舊的審核者與審核日期。
								建議保持上方「保護」勾選，避免蓋掉您親手寫過的短評。
							</p>
						</td>
					</tr>
				</table>

				<div style="display:flex;gap:12px;align-items:center;margin-top:8px;">
					<button id="wxacg-start-btn" class="button button-primary" style="height:40px;padding:0 20px;font-size:15px;">
						🚀 開始批次產生（本範圍待補 <?php echo (int) $scope_default; ?> 部）
					</button>

					<button id="wxacg-stop-btn" class="button" style="display:none;">⏹ 停止</button>
				</div>

				<div id="wxacg-progress" style="margin-top:20px;display:none;">
					<div style="background:#f0f0f0;border-radius:6px;height:18px;overflow:hidden;">
						<div id="wxacg-progress-bar" style="background:linear-gradient(90deg,#2271b1,#00a32a);height:100%;width:0;transition:width .4s;"></div>
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

		if (!startBtn) {
			return;
		}

		var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce   = '<?php echo esc_js( $nonce ); ?>';

		var DEFAULT_COOLDOWN_MS = 65000;
		var NORMAL_DELAY_MS     = 800;

		var running      = false;
		var offset       = 0;
		var totalDone    = 0;
		var totalFail    = 0;
		var keyCursor    = 0;
		var sessionLimit = 0;
		var scopeStart   = 0;
		var timer        = null;
		var retryTimer   = null;

		startBtn.addEventListener('click', function () {
			if (running) {
				return;
			}

			var overwrite = document.getElementById('wxacg-overwrite').checked;
			var protect   = document.getElementById('wxacg-protect').checked;

			if (overwrite && !protect &&
				!window.confirm('您同時開啟了覆蓋模式並關閉保護，這會蓋掉已人工審核的短評。確定要繼續嗎？')) {
				return;
			}

			if (overwrite && protect &&
				!window.confirm('覆蓋模式會重新產生短評並重設為待審核草稿（已人工審核者仍受保護）。確定要繼續嗎？')) {
				return;
			}

			running      = true;
			offset       = 0;
			totalDone    = 0;
			totalFail    = 0;
			keyCursor    = 0;
			scopeStart   = 0;
			sessionLimit = parseInt(document.getElementById('wxacg-session-limit').value, 10) || 0;

			startBtn.style.display   = 'none';
			stopBtn.style.display    = '';
			progressEl.style.display = '';
			logEl.style.display      = '';
			logEl.textContent        = '';
			progressBar.style.width  = '0%';
			progressText.textContent = '';

			addLog(
				'🟢 開始產生。本次工作階段上限：' +
				(sessionLimit > 0 ? sessionLimit + ' 部' : '不限') +
				(overwrite ? '（覆蓋模式）' : ''),
				'#89dceb'
			);

			runBatch();
		});

		stopBtn.addEventListener('click', function () {
			running = false;
			clearTimers();

			addLog('⏹ 已手動停止。本次完成 ' + totalDone + ' 筆，失敗 ' + totalFail + ' 筆。', '#fab387');
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

		function sessionLeft() {
			if (sessionLimit <= 0) {
				return 0;
			}

			return Math.max(0, sessionLimit - totalDone);
		}

		function runBatch() {
			if (!running) {
				return;
			}

			if (sessionLimit > 0 && totalDone >= sessionLimit) {
				addLog('🏁 已達本次工作階段上限 ' + sessionLimit + ' 部，自動停止。', '#89dceb');
				finish('▶ 再跑一輪');
				return;
			}

			var fd = new FormData();

			fd.append('action', 'wxacg_ai_generate_batch');
			fd.append('nonce', nonce);
			fd.append('batch_size', document.getElementById('wxacg-batch-size').value);
			fd.append('item_delay', document.getElementById('wxacg-item-delay').value);
			fd.append('sort', document.getElementById('wxacg-sort-order').value);
			fd.append('provider', document.getElementById('wxacg-run-provider').value);
			fd.append('overwrite', document.getElementById('wxacg-overwrite').checked ? '1' : '0');
			fd.append('protect', document.getElementById('wxacg-protect').checked ? '1' : '0');
			fd.append('offset', offset);
			fd.append('key_cursor', keyCursor);
			fd.append('session_left', sessionLeft());

			fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (response) {
					return response.text();
				})
				.then(function (text) {
					var response;

					try {
						response = JSON.parse(text);
					} catch (error) {
						addLog('❌ 伺服器回傳非 JSON，可能發生 PHP 錯誤：\n' + text.substring(0, 800), '#f38ba8');
						finish('▶ 繼續產生');
						return;
					}

					if (!response.success) {
						addLog('❌ 錯誤：' + ((response.data && response.data.message) || JSON.stringify(response)), '#f38ba8');
						finish('▶ 繼續產生');
						return;
					}

					handleResult(response.data || {});
				})
				.catch(function (error) {
					addLog('❌ fetch 錯誤：' + error.message + '（30 秒後自動重試）', '#f38ba8');

					if (running) {
						retryTimer = setTimeout(runBatch, 30000);
					}
				});
		}

		function handleResult(data) {
			offset    = parseInt(data.next_offset || 0, 10);
			keyCursor = parseInt(data.next_key_cursor || 0, 10);
			totalDone += parseInt(data.succeeded || 0, 10);
			totalFail += parseInt(data.failed || 0, 10);

			(data.results || []).forEach(function (row) {
				if (row.status === 'success') {
					var editorial = row.editorial || '';
					var preview = editorial.length > 70 ? editorial.substring(0, 70) + '…' : editorial;

					addLog(
						'✅ [' + row.id + '] ' + row.title +
						'\n   › ' + preview +
						'\n   › 來源：' + (row.source || '') + '　已存為待人工審核草稿',
						'#a6e3a1'
					);
				} else {
					addLog('❌ [' + row.id + '] ' + row.title + '\n   › ' + row.message, '#f38ba8');
				}
			});

			var scopeRemaining = parseInt(data.scope_remaining || 0, 10);

			if (scopeStart === 0) {
				scopeStart = scopeRemaining + totalDone;
			}

			var percent = scopeStart > 0
				? Math.min(Math.max((scopeStart - scopeRemaining) / scopeStart * 100, 0), 100)
				: 0;

			progressBar.style.width = percent + '%';

			setProgress(
				scopeRemaining,
				percent,
				'今日 ' + (data.daily_used || 0) +
				(data.daily_cap > 0 ? '/' + data.daily_cap : '') +
				'　可用 Key ' + (data.keys_ready || 0) + '/' + (data.key_count || 0) +
				'　間隔 ' + (data.item_delay_ms || 0) + 'ms'
			);

			if (data.daily_cap_hit) {
				addLog(
					'🛑 已達每日上限 ' + data.daily_cap + ' 部，今天到此為止。' +
					'本次完成 ' + totalDone + ' 筆，範圍內尚餘 ' + scopeRemaining + ' 部。',
					'#f9e2af'
				);

				finish('▶ 明天繼續');
				return;
			}

			if (data.daily_exhausted) {
				addLog(
					'🛑 所有 API Key 今日配額已用盡' +
					(data.reset_text ? '，約 ' + data.reset_text + ' 後恢復' : '') + '。',
					'#f38ba8'
				);

				if (data.key_status && data.key_status.length) {
					addLog('   › ' + data.key_status.join('\n   › '), '#9399b2');
				}

				finish('▶ 配額恢復後繼續');
				return;
			}

			if (scopeRemaining <= 0 && !document.getElementById('wxacg-overwrite').checked) {
				addLog('🎉 本範圍全部完成！共產生 ' + totalDone + ' 筆待審核短評草稿。', '#89dceb');
				finish('🚀 重新檢查');
				return;
			}

			if (parseInt(data.processed || 0, 10) === 0) {
				addLog('ℹ️ 沒有更多可處理項目。完成 ' + totalDone + ' 筆，失敗 ' + totalFail + ' 筆。', '#f9e2af');
				finish('🚀 重新開始');
				return;
			}

			if (sessionLimit > 0 && totalDone >= sessionLimit) {
				addLog(
					'🏁 已達本次工作階段上限 ' + sessionLimit + ' 部。' +
					'建議先抽查幾篇品質，滿意再按繼續。範圍內尚餘 ' + scopeRemaining + ' 部。',
					'#89dceb'
				);

				finish('▶ 再跑一輪');
				return;
			}

			if (!running) {
				return;
			}

			if (data.rate_limited) {
				var waitMs = data.cooldown_sec ? parseInt(data.cooldown_sec, 10) * 1000 : DEFAULT_COOLDOWN_MS;

				addLog('⏳ 目前所有 Key 都在冷卻，' + Math.round(waitMs / 1000) + ' 秒後自動繼續。', '#f9e2af');
				countdownThen(waitMs, scopeRemaining, percent);
				return;
			}

			retryTimer = setTimeout(runBatch, NORMAL_DELAY_MS);
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
					setProgress(remaining, percent, '冷卻中，' + Math.ceil(left / 1000) + ' 秒後繼續');
				}
			}, 1000);
		}

		function setProgress(remaining, percent, note) {
			progressText.textContent =
				'本次完成：' + totalDone +
				(sessionLimit > 0 ? '/' + sessionLimit : '') +
				' 筆　失敗：' + totalFail +
				' 筆　本範圍尚餘：' + remaining +
				' 部　(' + percent.toFixed(1) + '%)　' + (note || '');
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
