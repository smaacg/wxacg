<?php
/**
 * 微笑動漫 — AI 編輯短評批次產生工具
 *
 * Path: wp-content/themes/blocksy-child/inc/ai-editorial-tool.php
 *
 * @version 1.1.0 (2026-08-12)
 *
 * v1.1.0: 移除 PHP 7.4 arrow function 與 union return type，相容 PHP 7.2+
 *         加入 try-catch，AJAX 錯誤改以 JSON 回傳，不再顯示 HTML 錯誤頁
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

function wxacg_ai_generate_batch_handler() {
	// 最外層 try-catch：確保任何 PHP 錯誤都以 JSON 回傳
	try {
		check_ajax_referer( 'wxacg_ai_editorial_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '權限不足' ) );
		}

		$api_key = get_option( 'wxacg_gemini_api_key', '' );
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => '請先設定 Gemini API Key' ) );
		}

		$batch_size = min( (int) ( isset( $_POST['batch_size'] ) ? $_POST['batch_size'] : 10 ), 20 );
		$offset     = max( 0, (int) ( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ) );
		$overwrite  = ! empty( $_POST['overwrite'] );
		$sort       = sanitize_key( isset( $_POST['sort'] ) ? $_POST['sort'] : 'new' );

		global $wpdb;

		// 排序子句
		if ( 'new' === $sort ) {
			$join_extra = "LEFT JOIN {$wpdb->postmeta} pm_yr ON pm_yr.post_id = p.ID AND pm_yr.meta_key = 'anime_season_year'";
			$order_by   = "ORDER BY CAST( NULLIF( pm_yr.meta_value, '' ) AS UNSIGNED ) DESC, p.comment_count DESC, p.ID DESC";
		} elseif ( 'popular' === $sort ) {
			$join_extra = "LEFT JOIN {$wpdb->postmeta} pm_al ON pm_al.post_id = p.ID AND pm_al.meta_key = 'anime_score_anilist'";
			$order_by   = "ORDER BY p.comment_count DESC, CAST( NULLIF( pm_al.meta_value, '' ) AS DECIMAL(5,2) ) DESC, p.ID ASC";
		} else {
			$join_extra = '';
			$order_by   = 'ORDER BY p.ID ASC';
		}

		// 取得待處理文章
		if ( $overwrite ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$posts = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title
				 FROM {$wpdb->posts} p
				 {$join_extra}
				 WHERE p.post_type = 'anime' AND p.post_status = 'publish'
				 {$order_by}
				 LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$posts = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
				         ON pm.post_id = p.ID AND pm.meta_key = 'anime_editorial_note'
				 {$join_extra}
				 WHERE p.post_type = 'anime'
				   AND p.post_status = 'publish'
				   AND ( pm.meta_value IS NULL OR TRIM( pm.meta_value ) = '' )
				 {$order_by}
				 LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			) );
		}

		// 剩餘數量（未填短評）
		$total_remaining = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			         ON pm.post_id = p.ID AND pm.meta_key = 'anime_editorial_note'
			 WHERE p.post_type = 'anime'
			   AND p.post_status = 'publish'
			   AND ( pm.meta_value IS NULL OR TRIM( pm.meta_value ) = '' )"
		);

		$results = array();

		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;
			$data    = wxacg_gather_anime_data_for_editorial( $post_id );
			$result  = wxacg_call_gemini_editorial( $api_key, $data );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'id'      => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);
			} else {
				update_post_meta( $post_id, 'anime_editorial_note', $result );

				// 設定 byline（若尚未填過）
				if ( '' === trim( (string) get_post_meta( $post_id, 'anime_editorial_author', true ) ) ) {
					update_post_meta( $post_id, 'anime_editorial_author', '微笑動漫編輯部' );
				}
				if ( '' === trim( (string) get_post_meta( $post_id, 'anime_editorial_updated', true ) ) ) {
					update_post_meta( $post_id, 'anime_editorial_updated', gmdate( 'Y-m-d' ) );
				}

				$results[] = array(
					'id'        => $post_id,
					'title'     => $post->post_title,
					'status'    => 'success',
					'editorial' => $result,
				);
			}

			usleep( 300000 ); // 300ms，避免撞 rate limit
		}

		wp_send_json_success( array(
			'results'         => $results,
			'processed'       => count( $results ),
			'total_remaining' => max( 0, $total_remaining - count( $results ) ),
			'next_offset'     => $offset + count( $posts ),
		) );

	} catch ( Exception $e ) {
		wp_send_json_error( array(
			'message' => 'PHP 例外：' . $e->getMessage() . '（' . $e->getFile() . ':' . $e->getLine() . '）',
		) );
	} catch ( Error $e ) {
		wp_send_json_error( array(
			'message' => 'PHP 錯誤：' . $e->getMessage() . '（' . $e->getFile() . ':' . $e->getLine() . '）',
		) );
	}
}

/* ============================================================
 * 蒐集動漫資料
 * ============================================================ */

function wxacg_gather_anime_data_for_editorial( $post_id ) {
	$post_id = (int) $post_id;

	// 簡介：優先中文，其次英文
	$synopsis = trim( (string) get_post_meta( $post_id, 'anime_synopsis_chinese', true ) );
	if ( $synopsis === '' ) {
		$synopsis = trim( (string) get_post_meta( $post_id, 'anime_synopsis', true ) );
	}
	$synopsis = mb_substr( wp_strip_all_tags( $synopsis ), 0, 400 );

	// 類型
	$genre_terms = get_the_terms( $post_id, 'anime_genre_tax' );
	$genres      = '';
	if ( is_array( $genre_terms ) ) {
		$genre_names = array();
		foreach ( $genre_terms as $t ) {
			$genre_names[] = $t->name;
		}
		$genres = implode( '、', $genre_names );
	}

	// 製作公司
	$studio_terms = get_the_terms( $post_id, 'anime_studio_tax' );
	$studios      = '';
	if ( is_array( $studio_terms ) ) {
		$studio_names = array();
		foreach ( $studio_terms as $t ) {
			$studio_names[] = $t->name;
		}
		$studios = implode( '、', $studio_names );
	}

	// 台灣串流
	$streaming_list  = maybe_unserialize( trim( (string) get_post_meta( $post_id, 'anime_streaming_list', true ) ) );
	$streaming_names = array();

	if ( is_array( $streaming_list ) ) {
		foreach ( $streaming_list as $item ) {
			if ( is_array( $item ) && ! empty( $item['site'] ) ) {
				$streaming_names[] = $item['site'];
			}
		}
	}

	$tw_raw = trim( (string) get_post_meta( $post_id, 'anime_tw_streaming', true ) );
	if ( $tw_raw !== '' ) {
		$streaming_names[] = $tw_raw;
	}

	$streaming_names = array_values( array_unique( array_filter( $streaming_names ) ) );

	// 季節映射
	$season_map = array(
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
	);
	$season_raw = strtoupper( trim( (string) get_post_meta( $post_id, 'anime_season', true ) ) );
	$season     = isset( $season_map[ $season_raw ] ) ? $season_map[ $season_raw ] : $season_raw;

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
 * 呼叫 Gemini API
 * ============================================================ */

/**
 * @param string $api_key
 * @param array  $data
 * @return string|WP_Error
 */
function wxacg_call_gemini_editorial( $api_key, $data ) {
	$prompt = wxacg_build_editorial_prompt( $data );

	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . rawurlencode( $api_key ),
		array(
			'timeout' => 45,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( array(
				'contents'         => array(
					array( 'parts' => array( array( 'text' => $prompt ) ) ),
				),
				'generationConfig' => array(
					'temperature'     => 0.88,
					'maxOutputTokens' => 320,
					'topP'            => 0.95,
					'topK'            => 40,
				),
				'safetySettings'   => array(
					array( 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE' ),
					array( 'category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE' ),
					array( 'category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE' ),
					array( 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE' ),
				),
			), JSON_UNESCAPED_UNICODE ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( $code !== 200 ) {
		$err = json_decode( $body, true );
		$msg = isset( $err['error']['message'] ) ? $err['error']['message'] : "HTTP {$code}";
		return new WP_Error( 'gemini_error', 'Gemini API 錯誤：' . $msg );
	}

	$decoded = json_decode( $body, true );
	$text    = isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
		? $decoded['candidates'][0]['content']['parts'][0]['text']
		: '';
	$text = trim( $text );

	if ( $text === '' ) {
		return new WP_Error( 'gemini_empty', 'Gemini 回傳空白（可能被安全過濾器擋下）' );
	}

	return $text;
}

/* ============================================================
 * Prompt 建構
 * ============================================================ */

function wxacg_build_editorial_prompt( $d ) {
	// 串流
	$streaming_text = ( $d['streaming'] !== '' )
		? '台灣合法串流：' . $d['streaming']
		: '目前無台灣官方授權串流資訊';

	// 評分
	$scores = array();
	if ( $d['score_al']  !== '' ) $scores[] = 'AniList ' . $d['score_al'];
	if ( $d['score_mal'] !== '' ) $scores[] = 'MAL ' . $d['score_mal'];
	if ( $d['score_bgm'] !== '' ) $scores[] = 'Bangumi ' . $d['score_bgm'];
	$score_text = $scores ? implode( '、', $scores ) : '暫無對外評分資料';

	// 播出
	$air_info      = trim( $d['year'] . ' ' . $d['season'] );
	if ( $air_info === '' ) $air_info = '播出時間不詳';
	$episodes_text = $d['episodes'] ? $d['episodes'] . ' 集' : '集數未定';

	// 開頭風格（依 post_id 輪替）
	$opening_styles = array(
		'用這部動畫最讓你印象深刻的一個細節開頭（不要劇透關鍵情節）',
		'用評分或市場反應來帶出這部作品的定位',
		'先說這部適合什麼口味的觀眾，再延伸到作品本身',
		'從類型或題材的角度切入，說說這部的與眾不同之處',
		'先給一個對這部作品的直覺評價，再用具體理由支撐',
		'從製作公司或核心製作人的風格說起（如果有名氣的話）',
	);
	$style = $opening_styles[ $d['post_id'] % count( $opening_styles ) ];

	$prompt  = "你是「微笑動漫」（weixiaoacg.com）的資深動漫編輯，筆名「笑編」，有超過十年動漫評論資歷。你的文字風格是：\n";
	$prompt .= "- 台灣動漫圈的自然口吻，親切有主見\n";
	$prompt .= "- 偶爾帶點幽默或個人感情，但不誇大\n";
	$prompt .= "- 不說教條式的套話，直接分享你對作品的具體觀感\n";
	$prompt .= "- 句子不過長，段落感強，容易閱讀\n";
	$prompt .= "- 用語使用台灣習慣（聲優、追番、新番、動畫，不用大陸用語）\n\n";
	$prompt .= "現在請你為以下作品寫一段 80～130 字 的繁體中文編輯短評：\n\n";
	$prompt .= "【作品資料】\n";
	$prompt .= "- 標題：" . $d['title'] . "（" . $d['title_ja'] . "）\n";
	$prompt .= "- 類型：" . $d['genres'] . "\n";
	$prompt .= "- 集數：" . $episodes_text . "\n";
	$prompt .= "- 播出：" . $air_info . "\n";
	$prompt .= "- 製作：" . $d['studio'] . "\n";
	$prompt .= "- 外部評分：" . $score_text . "\n";
	$prompt .= "- " . $streaming_text . "\n";
	$prompt .= "- 劇情簡介：" . $d['synopsis'] . "\n\n";
	$prompt .= "【本次開頭方向】\n";
	$prompt .= $style . "\n\n";
	$prompt .= "【輸出規則】\n";
	$prompt .= "1. 直接輸出短評本文，不加標題、不加引號、不加說明文字\n";
	$prompt .= "2. 絕對不要以「這部動畫」、「本作」、「如果你喜歡」開頭，要自然多樣\n";
	$prompt .= "3. 末尾若有台灣串流資訊，請自然融入，不要生硬地追加一句話\n";
	$prompt .= "4. 字數控制在 80～130 字之間\n";
	$prompt .= "5. 不得在結尾加上署名\n";

	return $prompt;
}

/* ============================================================
 * 後台頁面 UI
 * ============================================================ */

function wxacg_ai_editorial_page() {
	// 儲存 API Key
	if (
		isset( $_POST['wxacg_save_apikey'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		update_option( 'wxacg_gemini_api_key', sanitize_text_field( isset( $_POST['wxacg_gemini_api_key'] ) ? $_POST['wxacg_gemini_api_key'] : '' ) );
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
	<p style="color:#666;">使用 Gemini API，以「台灣動漫資深編輯」口吻批次產生短評，寫入 <code>anime_editorial_note</code>，前端自動渲染。</p>

	<!-- 統計卡片 -->
	<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
		<?php
		$cards = array(
			array( 'label' => '動漫總數', 'value' => $total_anime,   'color' => '#2271b1' ),
			array( 'label' => '已有短評', 'value' => $has_editorial, 'color' => '#00a32a' ),
			array( 'label' => '待產生',   'value' => $need_gen,      'color' => $need_gen > 0 ? '#d63638' : '#00a32a' ),
		);
		foreach ( $cards as $c ) :
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 28px;text-align:center;min-width:140px;box-shadow:0 1px 3px rgba(0,0,0,.07);">
			<div style="font-size:40px;font-weight:700;color:<?php echo esc_attr( $c['color'] ); ?>;"><?php echo esc_html( $c['value'] ); ?></div>
			<div style="color:#555;margin-top:4px;"><?php echo esc_html( $c['label'] ); ?></div>
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
						<p class="description">至 <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a> 免費申請。</p>
					</td>
				</tr>
			</table>
			<button type="submit" name="wxacg_save_apikey" class="button button-primary">儲存 API Key</button>
			<?php if ( $api_key ) : ?>
			<span style="margin-left:12px;color:#00a32a;">✅ API Key 已設定</span>
			<?php endif; ?>
		</form>
	</div>

	<!-- 批次設定 -->
	<?php if ( $api_key ) : ?>
	<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">
		<h2 style="margin-top:0;">🚀 批次產生設定</h2>

		<table class="form-table" role="presentation">
			<tr>
				<th>每批數量</th>
				<td>
					<select id="wxacg-batch-size">
						<option value="5">5 部（測試品質）</option>
						<option value="10" selected>10 部（推薦）</option>
						<option value="20">20 部（最快）</option>
					</select>
				</td>
			</tr>
			<tr>
				<th>產生順序</th>
				<td>
					<select id="wxacg-sort-order">
						<option value="new" selected>📅 新番優先（依播出年份倒序）</option>
						<option value="popular">🔥 熱門優先（依留言數 + AniList 評分）</option>
						<option value="default">🔢 預設順序（依 ID）</option>
					</select>
				</td>
			</tr>
			<tr>
				<th>覆蓋模式</th>
				<td>
					<label>
						<input type="checkbox" id="wxacg-overwrite">
						勾選後，即使已有短評也會重新生成
					</label>
				</td>
			</tr>
		</table>

		<div style="display:flex;gap:12px;align-items:center;margin-top:8px;">
			<button id="wxacg-start-btn" class="button button-primary" style="height:40px;padding:0 20px;font-size:15px;">
				🚀 開始批次產生（剩餘 <?php echo (int) $need_gen; ?> 部）
			</button>
			<button id="wxacg-stop-btn" class="button" style="display:none;">⏹ 停止</button>
		</div>

		<div id="wxacg-progress" style="margin-top:20px;display:none;">
			<div style="background:#f0f0f0;border-radius:6px;height:18px;overflow:hidden;">
				<div id="wxacg-progress-bar" style="background:linear-gradient(90deg,#2271b1,#00a32a);height:100%;width:0;transition:width .4s;"></div>
			</div>
			<p id="wxacg-progress-text" style="margin:8px 0;color:#444;font-size:13px;"></p>
		</div>

		<div id="wxacg-log"
			 style="display:none;margin-top:16px;max-height:450px;overflow-y:auto;
			        border:1px solid #ddd;border-radius:6px;padding:12px;
			        font-family:monospace;font-size:12px;line-height:1.8;
			        background:#1e1e2e;color:#cdd6f4;white-space:pre-wrap;word-break:break-all;">
		</div>
	</div>
	<?php endif; ?>
	</div>

	<script>
	(function () {
		var startBtn  = document.getElementById('wxacg-start-btn');
		var stopBtn   = document.getElementById('wxacg-stop-btn');
		var logEl     = document.getElementById('wxacg-log');
		var progressEl   = document.getElementById('wxacg-progress');
		var progressBar  = document.getElementById('wxacg-progress-bar');
		var progressText = document.getElementById('wxacg-progress-text');
		var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce     = '<?php echo esc_js( $nonce ); ?>';
		var totalAnime = <?php echo (int) $total_anime; ?>;

		if ( ! startBtn ) return;

		var running   = false;
		var offset    = 0;
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
			logEl.textContent      = '';

			addLog( '🟢 開始批次產生…', '#89dceb' );
			runBatch();
		});

		stopBtn.addEventListener('click', function () {
			running = false;
			addLog( '⏹ 已手動停止。', '#fab387' );
			startBtn.textContent   = '▶ 繼續產生';
			startBtn.style.display = '';
			stopBtn.style.display  = 'none';
		});

		function runBatch() {
			if ( ! running ) return;

			var batchSize = document.getElementById('wxacg-batch-size').value;
			var sortOrder = document.getElementById('wxacg-sort-order').value;
			var overwrite = document.getElementById('wxacg-overwrite').checked ? '1' : '0';

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
			.then(function (r) {
				// 先取得文字，判斷是否為 JSON
				return r.text();
			})
			.then(function (text) {
				var res;
				try {
					res = JSON.parse(text);
				} catch (e) {
					// 非 JSON，把前 300 字顯示出來
					addLog( '❌ 伺服器回傳非 JSON（PHP 錯誤）：\n' + text.substring(0, 300), '#f38ba8' );
					running = false;
					startBtn.style.display = '';
					stopBtn.style.display  = 'none';
					return;
				}

				if ( ! res.success ) {
					addLog( '❌ 錯誤：' + ( res.data && res.data.message ? res.data.message : JSON.stringify(res) ), '#f38ba8' );
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
						var preview = r.editorial.length > 55 ? r.editorial.substring(0, 55) + '…' : r.editorial;
						addLog( '✅ [' + r.id + '] ' + r.title + '\n   › ' + preview, '#a6e3a1' );
					} else {
						addLog( '❌ [' + r.id + '] ' + r.title + '\n   › ' + r.message, '#f38ba8' );
					}
				});

				var pct = totalAnime > 0 ? Math.min( totalDone / totalAnime * 100, 100 ) : 0;
				progressBar.style.width  = pct + '%';
				progressText.textContent = '已完成：' + totalDone + ' 部　剩餘：' + data.total_remaining + ' 部　(' + pct.toFixed(1) + '%)';

				if ( data.total_remaining <= 0 || data.processed === 0 ) {
					addLog( '🎉 全部完成！共產生 ' + totalDone + ' 筆短評。', '#89dceb' );
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
				addLog( '❌ fetch 錯誤：' + err.message, '#f38ba8' );
				running = false;
				startBtn.style.display = '';
				stopBtn.style.display  = 'none';
			});
		}

		function addLog( msg, color ) {
			var span = document.createElement('span');
			span.style.color = color || '#cdd6f4';
			span.textContent = msg + '\n';
			logEl.appendChild(span);
			logEl.scrollTop = logEl.scrollHeight;
		}
	})();
	</script>
	<?php
}
