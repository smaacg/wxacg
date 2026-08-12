<?php
/**
 * 微笑動漫 — AI 編輯短評批次產生工具
 *
 * Path: wp-content/themes/blocksy-child/inc/ai-editorial-tool.php
 *
 * @version 1.0.0 (2026-08-12)
 *
 * 功能：
 *  - 後台批次為 anime 文章產生「編輯短評（anime_editorial_note）」
 *  - 呼叫 Google Gemini API，用「台灣動漫編輯」角色扮演，
 *    讓每篇短評有自然的人工口吻、開頭結構多樣化
 *  - 自動帶入台灣串流平台資訊、評分、類型等在地化資料
 *  - 每批 5–20 篇，AJAX 持續運行，不會伺服器逾時
 *  - 已有短評的預設跳過（可勾選覆蓋模式）
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
 * 後台選單
 * ============================================================ */

add_action( 'admin_menu', function () {
	add_theme_page(
		'AI 編輯短評產生器',
		'✍️ AI 短評產生',
		'manage_options',
		'wxacg-ai-editorial',
		'wxacg_ai_editorial_page'
	);
} );

/* ============================================================
 * AJAX：批次產生
 * ============================================================ */

add_action( 'wp_ajax_wxacg_ai_generate_batch', 'wxacg_ai_generate_batch_handler' );

function wxacg_ai_generate_batch_handler(): void {
	check_ajax_referer( 'wxacg_ai_editorial_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => '權限不足' ] );
	}

	$api_key = get_option( 'wxacg_gemini_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( [ 'message' => '請先設定 Gemini API Key' ] );
	}

	$batch_size = min( (int) ( $_POST['batch_size'] ?? 10 ), 20 );
	$offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
	$overwrite  = ! empty( $_POST['overwrite'] );
	$sort       = sanitize_key( $_POST['sort'] ?? 'new' ); // new | popular | default

	global $wpdb;

	/*
	 * 排序子句
	 * new:     新番優先（anime_season_year DESC，同年依 comment_count 排）
	 * popular: 熱門優先（comment_count DESC，再依 AniList 分數）
	 * default: 依 ID ASC（原始匯入順序）
	 */
	if ( 'new' === $sort ) {
		// JOIN anime_season_year 欄位，年份倒序
		$join_year  = "LEFT JOIN {$wpdb->postmeta} pm_yr ON pm_yr.post_id = p.ID AND pm_yr.meta_key = 'anime_season_year'";
		$order_by   = 'ORDER BY CAST( NULLIF( pm_yr.meta_value, \'\'  ) AS UNSIGNED ) DESC, p.comment_count DESC, p.ID DESC';
	} elseif ( 'popular' === $sort ) {
		// 直接用 posts 表的 comment_count，再 JOIN AniList 分數
		$join_year  = "LEFT JOIN {$wpdb->postmeta} pm_al ON pm_al.post_id = p.ID AND pm_al.meta_key = 'anime_score_anilist'";
		$order_by   = 'ORDER BY p.comment_count DESC, CAST( NULLIF( pm_al.meta_value, \'\'  ) AS DECIMAL(5,2) ) DESC, p.ID ASC';
	} else {
		$join_year = '';
		$order_by  = 'ORDER BY p.ID ASC';
	}

	// 取得待處理文章
	if ( $overwrite ) {
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title
			 FROM {$wpdb->posts} p
			 {$join_year}
			 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
			 {$order_by}
			 LIMIT %d OFFSET %d",
			$batch_size,
			$offset
		) );
	} else {
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			         ON pm.post_id = p.ID AND pm.meta_key = 'anime_editorial_note'
			 {$join_year}
			 WHERE p.post_type = 'anime'
			   AND p.post_status = 'publish'
			   AND ( pm.meta_value IS NULL OR TRIM( pm.meta_value ) = '' )
			 {$order_by}
			 LIMIT %d OFFSET %d",
			$batch_size,
			$offset
		) );
	}

	// 剩餘數量
	$total_remaining = (int) $wpdb->get_var(
		"SELECT COUNT(p.ID)
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm
		         ON pm.post_id = p.ID AND pm.meta_key = 'anime_editorial_note'
		 WHERE p.post_type = 'anime'
		   AND p.post_status = 'publish'
		   AND ( pm.meta_value IS NULL OR TRIM( pm.meta_value ) = '' )"
	);

	$results = [];

	foreach ( $posts as $post ) {
		$post_id = (int) $post->ID;
		$data    = wxacg_gather_anime_data_for_editorial( $post_id );
		$result  = wxacg_call_gemini_editorial( $api_key, $data );

		if ( is_wp_error( $result ) ) {
			$results[] = [
				'id'      => $post_id,
				'title'   => $post->post_title,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			];
		} else {
			update_post_meta( $post_id, 'anime_editorial_note', $result );
			// 同步更新 author / date（讓前端 byline 顯示正常）
			if ( '' === trim( (string) get_post_meta( $post_id, 'anime_editorial_author', true ) ) ) {
				update_post_meta( $post_id, 'anime_editorial_author', '微笑動漫編輯部' );
			}
			if ( '' === trim( (string) get_post_meta( $post_id, 'anime_editorial_updated', true ) ) ) {
				update_post_meta( $post_id, 'anime_editorial_updated', gmdate( 'Y-m-d' ) );
			}

			$results[] = [
				'id'        => $post_id,
				'title'     => $post->post_title,
				'status'    => 'success',
				'editorial' => $result,
			];
		}

		// 每筆請求之間暫停，避免撞 Gemini rate limit
		usleep( 300000 ); // 300ms
	}

	wp_send_json_success( [
		'results'         => $results,
		'processed'       => count( $results ),
		'total_remaining' => max( 0, $total_remaining - count( $results ) ),
		'next_offset'     => $offset + count( $posts ),
	] );
}

/* ============================================================
 * 蒐集動漫資料（用於 prompt）
 * ============================================================ */

function wxacg_gather_anime_data_for_editorial( int $post_id ): array {
	$get = static fn( $key ) => trim( (string) get_post_meta( $post_id, $key, true ) );

	// 簡介：優先中文，其次英文
	$synopsis = $get( 'anime_synopsis_chinese' );
	if ( $synopsis === '' ) {
		$synopsis = $get( 'anime_synopsis' );
	}
	$synopsis = mb_substr( wp_strip_all_tags( $synopsis ), 0, 400 );

	// 類型 Taxonomy
	$genres = implode( '、', array_map(
		static fn( $t ) => $t->name,
		get_the_terms( $post_id, 'anime_genre_tax' ) ?: []
	) );

	// 製作公司
	$studios = implode( '、', array_map(
		static fn( $t ) => $t->name,
		get_the_terms( $post_id, 'anime_studio_tax' ) ?: []
	) );

	// 台灣串流平台
	$streaming_list  = maybe_unserialize( $get( 'anime_streaming_list' ) );
	$streaming_names = [];

	if ( is_array( $streaming_list ) ) {
		foreach ( $streaming_list as $item ) {
			if ( ! empty( $item['site'] ) ) {
				$streaming_names[] = $item['site'];
			}
		}
	}

	// 舊版直接字串欄位
	$tw_streaming_raw = $get( 'anime_tw_streaming' );
	if ( $tw_streaming_raw !== '' ) {
		$streaming_names[] = $tw_streaming_raw;
	}

	$streaming_names = array_values( array_unique( array_filter( $streaming_names ) ) );

	// 評分
	$score_al  = $get( 'anime_score_anilist' );
	$score_mal = $get( 'anime_score_mal' );
	$score_bgm = $get( 'anime_score_bangumi' );

	// 季節 / 年份
	$season_map = [
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
	];
	$season_raw = strtoupper( $get( 'anime_season' ) );
	$season     = $season_map[ $season_raw ] ?? $season_raw;

	return [
		'post_id'    => $post_id,
		'title'      => get_the_title( $post_id ),
		'title_zh'   => $get( 'anime_title_chinese' ),
		'title_ja'   => $get( 'anime_title_native' ),
		'synopsis'   => $synopsis,
		'genres'     => $genres,
		'episodes'   => $get( 'anime_episodes' ),
		'year'       => $get( 'anime_season_year' ),
		'season'     => $season,
		'score_al'   => $score_al,
		'score_mal'  => $score_mal,
		'score_bgm'  => $score_bgm,
		'streaming'  => implode( '、', $streaming_names ),
		'studio'     => $studios,
	];
}

/* ============================================================
 * 呼叫 Gemini API
 * ============================================================ */

/**
 * 呼叫 Gemini API 產生編輯短評。
 *
 * @param string $api_key Gemini API Key。
 * @param array  $data    由 wxacg_gather_anime_data_for_editorial() 回傳的作品資料。
 * @return string|WP_Error 成功回傳短評字串；失敗回傳 WP_Error。
 */
function wxacg_call_gemini_editorial( string $api_key, array $data ) {
	$prompt = wxacg_build_editorial_prompt( $data );

	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . rawurlencode( $api_key ),
		[
			'timeout' => 45,
			'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			'body'    => wp_json_encode( [
				'contents'         => [
					[ 'parts' => [ [ 'text' => $prompt ] ] ],
				],
				'generationConfig' => [
					'temperature'     => 0.88, // 較高溫度，讓每篇開頭句型更多樣
					'maxOutputTokens' => 320,
					'topP'            => 0.95,
					'topK'            => 40,
				],
				'safetySettings'   => [
					[ 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE' ],
					[ 'category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE' ],
					[ 'category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE' ],
					[ 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE' ],
				],
			], JSON_UNESCAPED_UNICODE ),
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( $code !== 200 ) {
		$err = json_decode( $body, true );
		$msg = $err['error']['message'] ?? "HTTP {$code}";
		return new WP_Error( 'gemini_error', "Gemini API 錯誤：{$msg}" );
	}

	$decoded = json_decode( $body, true );
	$text    = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
	$text    = trim( $text );

	if ( $text === '' ) {
		return new WP_Error( 'gemini_empty', 'Gemini 回傳空白內容（可能被安全過濾器擋下）' );
	}

	return $text;
}

/* ============================================================
 * 編輯短評 Prompt（核心）
 * ============================================================ */

function wxacg_build_editorial_prompt( array $d ): string {
	// 串流文字
	if ( $d['streaming'] !== '' ) {
		$streaming_text = "台灣合法串流：{$d['streaming']}";
	} else {
		$streaming_text = '目前無台灣官方授權串流資訊';
	}

	// 評分文字
	$scores = [];
	if ( $d['score_al']  !== '' ) $scores[] = "AniList {$d['score_al']}";
	if ( $d['score_mal'] !== '' ) $scores[] = "MAL {$d['score_mal']}";
	if ( $d['score_bgm'] !== '' ) $scores[] = "Bangumi {$d['score_bgm']}";
	$score_text = $scores ? implode('、', $scores) : '暫無對外評分資料';

	// 播出資訊
	$air_info = trim( "{$d['year']} {$d['season']}" );
	if ( $air_info === '' ) $air_info = '播出時間不詳';

	$episodes_text = $d['episodes'] ? "{$d['episodes']} 集" : '集數未定';

	// 系列開頭隨機化指令（讓 AI 自己決定用哪種）
	$opening_styles = [
		'用這部動畫最讓你印象深刻的一個細節開頭（不要劇透關鍵情節）',
		'用評分或市場反應來帶出這部作品的定位',
		'先說這部適合什麼口味的觀眾，再延伸到作品本身',
		'從類型或題材的角度切入，說說這部的與眾不同之處',
		'先給一個對這部作品的直覺評價，再用具體理由支撐',
		'從製作公司或核心製作人的風格說起（如果有名氣的話）',
	];
	// 用 post_id 做偽隨機，讓不同作品選到不同風格
	$style = $opening_styles[ $d['post_id'] % count( $opening_styles ) ];

	return <<<PROMPT
你是「微笑動漫」（weixiaoacg.com）的資深動漫編輯，筆名「笑編」，有超過十年動漫評論資歷。你的文字風格是：
- 台灣動漫圈的自然口吻，親切有主見
- 偶爾帶點幽默或個人感情，但不誇大
- 不說教條式的套話，直接分享你對作品的具體觀感
- 句子不過長，段落感強，容易閱讀
- 用語使用台灣習慣（聲優、追番、新番、動畫，不用大陸用語）

現在請你為以下作品寫一段 **80～130 字** 的繁體中文編輯短評：

【作品資料】
- 標題：{$d['title']}（{$d['title_ja']}）
- 類型：{$d['genres']}
- 集數：{$episodes_text}
- 播出：{$air_info}
- 製作：{$d['studio']}
- 外部評分：{$score_text}
- {$streaming_text}
- 劇情簡介：{$d['synopsis']}

【本次開頭方向】
{$style}

【輸出規則】
1. 直接輸出短評本文，不加標題、不加引號、不加說明文字
2. 絕對不要以「這部動畫」、「本作」、「如果你喜歡」開頭，要自然多樣
3. 末尾若有台灣串流資訊，請自然融入（例如「台灣觀眾可在 XX 合法追番」），不要生硬地追加一句話
4. 字數控制在 80～130 字之間
5. 不得在結尾加上「本文由微笑動漫編輯部撰寫」等署名

PROMPT;
}

/* ============================================================
 * 後台頁面 UI
 * ============================================================ */

function wxacg_ai_editorial_page(): void {
	// 儲存 API Key
	if (
		isset( $_POST['wxacg_save_apikey'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		update_option( 'wxacg_gemini_api_key', sanitize_text_field( $_POST['wxacg_gemini_api_key'] ?? '' ) );
		echo '<div class="notice notice-success is-dismissible"><p>✅ API Key 已儲存。</p></div>';
	}

	$api_key = get_option( 'wxacg_gemini_api_key', '' );
	$nonce   = wp_create_nonce( 'wxacg_ai_editorial_nonce' );

	global $wpdb;
	$total_anime   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='anime' AND post_status='publish'" );
	$has_editorial = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='anime_editorial_note' AND meta_value != ''" );
	$need_gen      = $total_anime - $has_editorial;
	?>
	<div class="wrap">
	<h1>✍️ AI 編輯短評批次產生器</h1>
	<p style="color:#666;">使用 Google Gemini API，以「台灣動漫資深編輯」口吻，為每部動漫批次產生獨立短評，寫入 <code>anime_editorial_note</code> 欄位，前端 single-anime.php 自動渲染。</p>

	<!-- 統計卡片 -->
	<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:20px 0;">
		<?php
		$cards = [
			[ 'label' => '動漫總數',   'value' => $total_anime,   'color' => '#2271b1' ],
			[ 'label' => '已有短評',   'value' => $has_editorial, 'color' => '#00a32a' ],
			[ 'label' => '待產生',     'value' => $need_gen,      'color' => $need_gen > 0 ? '#d63638' : '#00a32a' ],
		];
		foreach ( $cards as $c ) :
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.07);">
			<div style="font-size:40px;font-weight:700;color:<?php echo $c['color']; ?>;"><?php echo $c['value']; ?></div>
			<div style="color:#555;margin-top:4px;"><?php echo $c['label']; ?></div>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- API Key 設定 -->
	<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-bottom:20px;">
		<h2 style="margin-top:0;">🔑 Gemini API Key 設定</h2>
		<form method="post">
			<?php wp_nonce_field( 'wxacg_ai_editorial_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">API Key</th>
					<td>
						<input type="password" name="wxacg_gemini_api_key"
							   value="<?php echo esc_attr( $api_key ); ?>"
							   style="width:420px;font-family:monospace;"
							   placeholder="AIzaSy...">
						<p class="description">
							至 <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>
							免費申請。免費額度每日約 1500 次請求，足夠處理全站作品。
						</p>
					</td>
				</tr>
			</table>
			<button type="submit" name="wxacg_save_apikey" class="button button-primary">儲存 API Key</button>
			<?php if ( $api_key ) : ?>
			<span style="margin-left:12px;color:#00a32a;">✅ API Key 已設定</span>
			<?php endif; ?>
		</form>
	</div>

	<!-- 批次設定 + 執行 -->
	<?php if ( $api_key ) : ?>
	<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">
		<h2 style="margin-top:0;">🚀 批次產生設定</h2>

		<table class="form-table" role="presentation">
			<tr>
				<th>每批數量</th>
				<td>
					<select id="wxacg-batch-size">
						<option value="5">5 部（測試品質用）</option>
						<option value="10" selected>10 部（推薦）</option>
						<option value="20">20 部（最快，偶爾可能逾時）</option>
					</select>
				</td>
			</tr>
			<tr>
				<th>產生順序</th>
				<td>
					<select id="wxacg-sort-order">
						<option value="new" selected>📅 新番優先（依播出年份倒序）</option>
						<option value="popular">🔥 熱門優先（依留言數 + AniList 評分）</option>
						<option value="default">🔢 預設順序（依 ID，即匯入先後）</option>
					</select>
				</td>
			</tr>
			<tr>
				<th>覆蓋模式</th>
				<td>
					<label>
						<input type="checkbox" id="wxacg-overwrite">
						勾選後，<strong>已有短評的作品也會重新生成</strong>（可用於品質不滿意時重跑）
					</label>
				</td>
			</tr>
		</table>

		<div style="display:flex;gap:12px;align-items:center;margin-top:8px;">
			<button id="wxacg-start-btn" class="button button-primary" style="height:40px;padding:0 20px;font-size:15px;">
				🚀 開始批次產生（剩餘 <?php echo $need_gen; ?> 部）
			</button>
			<button id="wxacg-stop-btn" class="button" style="display:none;">⏹ 停止</button>
		</div>

		<!-- 進度條 -->
		<div id="wxacg-progress" style="margin-top:20px;display:none;">
			<div style="background:#f0f0f0;border-radius:6px;height:18px;overflow:hidden;">
				<div id="wxacg-progress-bar"
					 style="background:linear-gradient(90deg,#2271b1,#00a32a);height:100%;width:0;transition:width .4s ease;"></div>
			</div>
			<p id="wxacg-progress-text" style="margin:8px 0;color:#444;font-size:13px;"></p>
		</div>

		<!-- 執行 log -->
		<div id="wxacg-log"
			 style="display:none;margin-top:16px;max-height:450px;overflow-y:auto;
			        border:1px solid #ddd;border-radius:6px;padding:12px;
			        font-family:monospace;font-size:12px;line-height:1.7;background:#1e1e2e;color:#cdd6f4;">
		</div>
	</div>
	<?php endif; ?>
	</div>

	<script>
	(function () {
		var startBtn  = document.getElementById('wxacg-start-btn');
		var stopBtn   = document.getElementById('wxacg-stop-btn');
		var logEl     = document.getElementById('wxacg-log');
		var progressEl     = document.getElementById('wxacg-progress');
		var progressBar    = document.getElementById('wxacg-progress-bar');
		var progressText   = document.getElementById('wxacg-progress-text');
		var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce     = '<?php echo esc_js( $nonce ); ?>';
		var totalAnime = <?php echo $total_anime; ?>;

		if ( ! startBtn ) return;

		var running  = false;
		var offset   = 0;
		var totalDone = 0;

		startBtn.addEventListener('click', function () {
			if ( running ) return;
			running   = true;
			offset    = 0;
			totalDone = 0;

			startBtn.style.display = 'none';
			stopBtn.style.display  = '';
			progressEl.style.display = '';
			logEl.style.display    = '';
			logEl.innerHTML        = '';

			addLog( '🟢 開始批次產生…', 'info' );
			runBatch();
		});

		stopBtn.addEventListener('click', function () {
			running = false;
			addLog( '⏹ 已手動停止。再次點擊「開始」可從中斷處繼續。', 'warn' );
			startBtn.textContent = '▶ 繼續產生';
			startBtn.style.display = '';
			stopBtn.style.display  = 'none';
		});

		function runBatch() {
			if ( ! running ) return;

			var batchSize = document.getElementById('wxacg-batch-size').value;
			var overwrite  = document.getElementById('wxacg-overwrite').checked ? '1' : '0';
			var sortOrder  = document.getElementById('wxacg-sort-order').value;

			var fd = new FormData();
			fd.append('action',     'wxacg_ai_generate_batch');
			fd.append('nonce',      nonce);
			fd.append('batch_size', batchSize);
			fd.append('offset',     offset);
			fd.append('overwrite',  overwrite);
			fd.append('sort',       sortOrder);

			fetch(ajaxUrl, {
				method: 'POST',
				body:   fd,
				credentials: 'same-origin'
			})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if ( ! res.success ) {
					addLog( '❌ 錯誤：' + ( res.data && res.data.message ? res.data.message : JSON.stringify(res) ), 'error' );
					running = false;
					startBtn.style.display = '';
					stopBtn.style.display  = 'none';
					return;
				}

				var data = res.data;
				totalDone += data.processed;
				offset     = data.next_offset;

				data.results.forEach(function (r) {
					if ( r.status === 'success' ) {
						var preview = r.editorial.length > 55
							? r.editorial.substring(0, 55) + '…'
							: r.editorial;
						addLog( '✅ [' + r.id + '] ' + r.title + '\n   › ' + preview, 'success' );
					} else {
						addLog( '❌ [' + r.id + '] ' + r.title + '\n   › ' + r.message, 'error' );
					}
				});

				// 更新進度
				var pct = totalAnime > 0 ? Math.min( (totalDone / totalAnime * 100), 100 ) : 0;
				progressBar.style.width = pct + '%';
				progressText.textContent =
					'已完成：' + totalDone + ' 部　剩餘：' + data.total_remaining + ' 部　( ' + pct.toFixed(1) + '% )';

				// 判斷結束
				if ( data.total_remaining <= 0 || data.processed === 0 ) {
					addLog( '🎉 全部完成！共產生 ' + totalDone + ' 筆短評，請重新整理頁面查看最新統計。', 'info' );
					running = false;
					startBtn.textContent   = '🚀 重新產生';
					startBtn.style.display = '';
					stopBtn.style.display  = 'none';
					return;
				}

				if ( running ) {
					setTimeout( runBatch, 300 );
				}
			})
			.catch(function (err) {
				addLog( '❌ 網路錯誤：' + err.message + '，將自動重試一次…', 'error' );
				if ( running ) {
					setTimeout( runBatch, 2000 ); // 網路錯誤時 2 秒後重試
				}
			});
		}

		function addLog( msg, type ) {
			var color = { success: '#a6e3a1', error: '#f38ba8', warn: '#fab387', info: '#89dceb' }[type] || '#cdd6f4';
			var line  = '<span style="color:' + color + ';">'
			          + msg.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>   ')
			          + '</span><br>';
			logEl.innerHTML += line;
			logEl.scrollTop  = logEl.scrollHeight;
		}
	})();
	</script>
	<?php
}
