<?php
/**
 * 檔案名稱: tests/test-claude-response.php
 * AI 輔助生成：Claude 回應解析與失敗分類的回歸測試（純函式，不連網、不碰資料庫）
 *
 * 執行（在 wp-content/plugins/anime-sync-pro 下，PHP 指令見 CLAUDE.md「本機指令」）：
 *   $PHP tests/test-claude-response.php
 *
 * 涵蓋 includes/class-acf-fields.php 的：
 *   - extract_claude_text()：回應有 thinking 區塊、多段文字、被拒絕、被截斷時怎麼取文字
 *   - resolve_claude_model()：user_meta 留著退役型號或空值時怎麼處理
 *   - classify_api_failure()：Claude 的 refusal／max_tokens／404／529 會被分到哪一類
 *
 * @package Anime_Sync_Pro
 */

require __DIR__ . '/bootstrap.php';
require ANIME_SYNC_PRO_DIR . 'includes/class-acf-fields.php';

// 建構子只是掛 hook，這裡只測私有的純函式，不需要執行建構子
$acf = ( new ReflectionClass( 'Anime_Sync_ACF_Fields' ) )->newInstanceWithoutConstructor();

// resolve_claude_model() 會寫 error_log，導到暫存檔以便檢查內容，也避免洗版
$log_file = tempnam( sys_get_temp_dir(), 'asp-claude-test-' );
ini_set( 'error_log', $log_file );

// ─────────────────────────────────────────────────────────
// 1. extract_claude_text()：正常回應
// ─────────────────────────────────────────────────────────
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => '你好' ] ],
		'stop_reason' => 'end_turn',
	] ),
	'你好',
	'正常：只有一個 text 區塊'
);

/*
 * ★ Sonnet 5.5／Opus 5.5 的 content[0] 可能是 thinking（display 預設 omitted 時 thinking 為空字串），
 *   文字在後面。只讀 content[0]['text'] 會被誤判成「AI 未回傳內容」。
 */
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [
			[ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig' ],
			[ 'type' => 'text', 'text' => '這是簡介' ],
		],
		'stop_reason' => 'end_turn',
	] ),
	'這是簡介',
	'thinking 在前：要跳過 thinking 取到後面的 text'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [
			[ 'type' => 'text', 'text' => '[{"type":"va",' ],
			[ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig' ],
			[ 'type' => 'text', 'text' => '"text":"花澤香菜"}]' ],
		],
		'stop_reason' => 'end_turn',
	] ),
	'[{"type":"va","text":"花澤香菜"}]',
	'多段 text：依序直接串接（不加分隔，JSON 才不會被拆壞）'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => '' ] ],
		'stop_reason' => 'end_turn',
	] ),
	'',
	'有 text 區塊但為空字串：回空字串（與 Gemini／OpenAI 一致，不當成失敗）'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => 'OK' ] ],
		'stop_reason' => null,
	] ),
	'OK',
	'stop_reason 為 null：仍取得文字'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => 'OK' ] ],
		'stop_reason' => 'stop_sequence',
	] ),
	'OK',
	'stop_reason 為 stop_sequence：正常結束，取得文字'
);

// ─────────────────────────────────────────────────────────
// 2. extract_claude_text()：沒有可用內容 → null
// ─────────────────────────────────────────────────────────
t_is(
	t_call( $acf, 'extract_claude_text', [ 'content' => [], 'stop_reason' => 'end_turn' ] ),
	null,
	'content 是空陣列：null'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig' ] ],
		'stop_reason' => 'end_turn',
	] ),
	null,
	'只有 thinking 沒有 text：null'
);
t_is( t_call( $acf, 'extract_claude_text', null ), null, '回應無法解碼（null）：null' );
t_is( t_call( $acf, 'extract_claude_text', 'not json' ), null, '回應是字串：null' );
t_is( t_call( $acf, 'extract_claude_text', [ 'stop_reason' => 'end_turn' ] ), null, '沒有 content 欄位：null' );
t_is( t_call( $acf, 'extract_claude_text', [ 'content' => 'x' ] ), null, 'content 不是陣列：null' );
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => [ 'x' ] ], [ 'type' => 'text' ], 'x' ],
		'stop_reason' => 'end_turn',
	] ),
	null,
	'區塊格式不對（text 不是字串、缺 text、區塊不是陣列）：略過，全部略過就是 null'
);

/*
 * ★ 被拒絕或被截斷時就算有部分文字也不完整，不能當成功寫進欄位。
 */
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'      => [],
		'stop_reason'  => 'refusal',
		'stop_details' => [ 'type' => 'refusal', 'category' => 'general_harms' ],
	] ),
	null,
	'refusal（輸出前就被拒）：null'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => '前半段簡介……' ] ],
		'stop_reason' => 'refusal',
	] ),
	null,
	'refusal 但有部分文字：不完整，仍是 null'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => '[{"type":"char","text":"' ] ],
		'stop_reason' => 'max_tokens',
	] ),
	null,
	'max_tokens 截斷但有部分文字：不完整，仍是 null'
);
t_is(
	t_call( $acf, 'extract_claude_text', [
		'content'     => [ [ 'type' => 'text', 'text' => '前半段' ] ],
		'stop_reason' => 'model_context_window_exceeded',
	] ),
	null,
	'其他非正常結束原因（含未來新增的值）但有部分文字：不完整，null'
);

// ─────────────────────────────────────────────────────────
// 3. classify_api_failure()：Claude 失敗要分到正確的類別與訊息
// ─────────────────────────────────────────────────────────
$f = t_call( $acf, 'classify_api_failure', 200, [
	'content'      => [],
	'stop_reason'  => 'refusal',
	'stop_details' => [ 'type' => 'refusal', 'category' => 'general_harms', 'explanation' => null ],
], 'claude' );
t_is( $f['type'], 'content', 'refusal：分類為 content（換 Key 沒用，停止該項任務）' );
t_is(
	$f['message'],
	'請求被 Claude 安全機制拒絕(類別: general_harms),建議改用人工填寫',
	'refusal：訊息帶出拒絕類別'
);

$f = t_call( $acf, 'classify_api_failure', 200, [
	'content'      => [],
	'stop_reason'  => 'refusal',
	'stop_details' => null,
], 'claude' );
t_is( $f['message'], '請求被 Claude 安全機制拒絕(未提供類別),建議改用人工填寫', 'refusal 但 stop_details 為 null：仍說明是被拒絕' );

$f = t_call( $acf, 'classify_api_failure', 200, [
	'content'      => [],
	'stop_reason'  => 'refusal',
	'stop_details' => [ 'type' => 'refusal', 'category' => [ 'x' ] ],
], 'claude' );
t_is( $f['message'], '請求被 Claude 安全機制拒絕(未提供類別),建議改用人工填寫', 'refusal 但 category 不是字串：當成未提供類別' );

$f = t_call( $acf, 'classify_api_failure', 200, [
	'content'     => [ [ 'type' => 'text', 'text' => '前半' ] ],
	'stop_reason' => 'max_tokens',
], 'claude' );
t_is( $f['type'], 'content', 'max_tokens：分類為 content' );
t_is( $f['message'], '回應因 max_tokens 上限被截斷,請縮小單次處理的份量', 'max_tokens：訊息說明被截斷' );

$f = t_call( $acf, 'classify_api_failure', 200, [
	'content'     => [ [ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig' ] ],
	'stop_reason' => 'end_turn',
], 'claude' );
t_is( $f['message'], 'AI 未回傳內容(stop_reason: end_turn)', '只有 thinking：說明沒有內容並附 stop_reason' );

// 已退役型號：API 回 404 not_found_error，屬於請求本身的問題，不換 Key
$f = t_call( $acf, 'classify_api_failure', 404, [
	'type'  => 'error',
	'error' => [ 'type' => 'not_found_error', 'message' => 'model: claude-3-5-sonnet-20240620' ],
], 'claude' );
t_is( $f['type'], 'request', '404 型號不存在：分類為 request，不換 Key' );

// 529 overloaded：暫時性，先原地重試同一把 Key
$f = t_call( $acf, 'classify_api_failure', 529, [
	'type'  => 'error',
	'error' => [ 'type' => 'overloaded_error', 'message' => 'Overloaded' ],
], 'claude' );
t_is( ! empty( $f['retryable'] ), true, '529 overloaded：標記為可重試' );

// ─────────────────────────────────────────────────────────
// 4. resolve_claude_model()／get_claude_model_options()
// ─────────────────────────────────────────────────────────
$options = t_call( $acf, 'get_claude_model_options' );
t_is( $options[0]['value'], 'claude-sonnet-5-5', '型號清單：第一個（預設）是 Sonnet 5.5' );
t_is(
	array_column( $options, 'value' ),
	[ 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-4-5' ],
	'型號清單：Sonnet 5.5、Opus 5.5、Haiku 4.5'
);

t_is( t_call( $acf, 'resolve_claude_model', 'claude-opus-5-5' ), 'claude-opus-5-5', '清單內型號：照用' );
t_is( t_call( $acf, 'resolve_claude_model', 'claude-haiku-4-5' ), 'claude-haiku-4-5', '清單內型號：照用（Haiku）' );

file_put_contents( $log_file, '' );
t_is( t_call( $acf, 'resolve_claude_model', '' ), 'claude-sonnet-5-5', '空值：用預設型號' );
t_is( file_get_contents( $log_file ), '', '空值：不寫 Log（本來就沒選型號，不是異常）' );

t_is(
	t_call( $acf, 'resolve_claude_model', 'claude-3-5-sonnet-20240620' ),
	'claude-sonnet-5-5',
	'已退役型號：改用預設型號，避免送出必定 404 的請求'
);
t_is(
	strpos( (string) file_get_contents( $log_file ), 'claude-3-5-sonnet-20240620' ) !== false,
	true,
	'已退役型號：Log 記下被替換的型號'
);
t_is( t_call( $acf, 'resolve_claude_model', 'gemini-3.7-flash' ), 'claude-sonnet-5-5', '其他供應商的型號：改用預設型號' );

unlink( $log_file );

exit( t_report() );
