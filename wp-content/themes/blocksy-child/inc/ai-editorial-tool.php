<?php
/**
 * 微笑動漫 — AI 編輯短評批次產生工具
 *
 * Path: wp-content/themes/blocksy-child/inc/ai-editorial-tool.php
 *
 * @version 1.4.0 (2026-08-13)
 *
 * v1.4.0: 支援多把 Gemini API Key 輪替 —
 *         1) API Key 改為陣列儲存（一行一把），後台可貼多把
 *         2) 批次處理時依項目序號輪流分配 key（round-robin）
 *         3) 單把 key 遇到 429，立刻換下一把 key 重試，不再 sleep 等待；
 *            只有「這一輪所有 key 都 429」時才短暫 sleep 再跑第二輪
 *         4) 兩輪（所有 key 都試過兩次）仍全部配額用盡，才回傳 rate_limited，
 *            交由前端用較長冷卻時間再重新呼叫
 *         5) 前端新增 key_cursor 於批次間傳遞，確保下一批從正確的 key 索引接續輪替
 *         6) 批次大小上限依 key 數量動態調整（每把 key 約可負擔 5 部/批）
 * v1.3.0: 修正 Gemini 免費方案 5 RPM 配額問題 —
 *         1) 呼叫端加入 429 自動重試（依錯誤訊息解析建議等待秒數，指數退避）
 *         2) 偵測到配額錯誤時，批次立即中止（不再繼續燒剩餘項目），並回傳
 *            rate_limited 旗標
 *         3) 前端偵測到 rate_limited 時，改用較長的冷卻時間（預設 65 秒）
 *            再重新呼叫，而不是原本的 300ms，避免無效重試洗爆日配額
 * v1.2.0: gemini-2.5-flash 已被 Google 擋掉新用戶／新專案呼叫（正式下線日 2026-10-16，
 *         但已提前限制），改用目前 GA 的 gemini-3.6-flash；同時把已棄用的
 *         temperature/topP/topK 換成新版 thinkingConfig（thinkingLevel: minimal，
 *         短評這種輕量文字生成不需要額外推理，可省成本與延遲）
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
 * 共用：取得已設定的 API Key 陣列
 * ============================================================ */

function wxacg_get_gemini_api_keys() {
	$keys = get_option( 'wxacg_gemini_api_keys', array() );

	// 相容舊版單一 key 選項：若舊選項存在且新選項是空的，自動搬過來用一次
	if ( empty( $keys ) ) {
		$legacy = get_option( 'wxacg_gemini_api_key', '' );
		if ( ! empty( $legacy ) ) {
			$keys = array( $legacy );
		}
	}

	if ( ! is_array( $keys ) ) {
		$keys = array();
	}

	$keys = array_values( array_filter( array_map( 'trim', $keys ) ) );

	return $keys;
}

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

		$api_keys = wxacg_get_gemini_api_keys();
		if ( empty( $api_keys ) ) {
			wp_send_json_error( array( 'message' => '請先設定至少一把 Gemini API Key' ) );
		}
		$key_count = count( $api_keys );

		$batch_size = min( (int) ( isset( $_POST['batch_size'] ) ? $_POST['batch_size'] : 10 ), 60 );
		$offset     = max( 0, (int) ( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ) );
		$overwrite  = ! empty( $_POST['overwrite'] );
		$sort       = sanitize_key( isset( $_POST['sort'] ) ? $_POST['sort'] : 'new' );

		// 這一批從哪個 key 索引開始輪替（由前端在批次間接續傳遞，讓輪替不中斷）
		$key_cursor = ( (int) ( isset( $_POST['key_cursor'] ) ? $_POST['key_cursor'] : 0 ) ) % $key_count;
		if ( $key_cursor < 0 ) {
			$key_cursor += $key_count;
		}

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

		$results      = array();
		$rate_limited = false;
		$i            = 0;

		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;
			$data    = wxacg_gather_anime_data_for_editorial( $post_id );

			// 這個項目從哪把 key 開始嘗試（round-robin）
			$start_idx = ( $key_cursor + $i ) % $key_count;
			$result    = wxacg_call_gemini_editorial_multi( $api_keys, $start_idx, $data );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'id'      => $post_id,
					'title'   => $post->post_title,
					'status'  => 'error',
					'message' => $result->get_error_message(),
				);

				// 遇到「所有 key 皆配額用盡」：不要繼續燒剩下的項目，立刻結束這個批次，
				// 讓前端用較長的冷卻時間再重試，而不是逐一把整批都燒成失敗。
				if ( 'gemini_quota' === $result->get_error_code() ) {
					$rate_limited = true;
					$i++;
					break;
				}
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

			$i++;
			usleep( 150000 ); // 150ms，多把 key 輪替下可以稍微縮短間隔
		}

		wp_send_json_success( array(
			'results'         => $results,
			'processed'       => count( $results ),
			'total_remaining' => max( 0, $total_remaining - count( $results ) ),
			'next_offset'     => $offset + count( $results ),
			'next_key_cursor' => ( $key_cursor + $i ) % $key_count,
			'key_count'       => $key_count,
			'rate_limited'    => $rate_limited,
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
 * 呼叫 Gemini API（多把 key 輪替版）
 * ============================================================ */

/**
 * 依序嘗試多把 API Key，單把 429 就換下一把，不 sleep 等待。
 * 只有「這一輪所有 key 都 429」才短暫 sleep 後跑下一輪；
 * 兩輪（每把 key 各試兩次）仍全部失敗，才視為整批配額用盡。
 *
 * @param array $api_keys   所有可用的 key
 * @param int   $start_idx  這次從哪個 index 開始嘗試（round-robin 起點）
 * @param array $data
 * @return string|WP_Error
 */
function wxacg_call_gemini_editorial_multi( $api_keys, $start_idx, $data ) {
	$prompt     = wxacg_build_editorial_prompt( $data );
	$key_count  = count( $api_keys );
	$last_error = null;
	$max_rounds = 2; // 每把 key 最多各試 2 次（第一輪 + 第二輪）

	for ( $round = 0; $round < $max_rounds; $round++ ) {

		$all_quota_this_round = true; // 是否這一輪每一把都是 429（配額問題）

		for ( $offset = 0; $offset < $key_count; $offset++ ) {
			$idx     = ( $start_idx + $offset ) % $key_count;
			$api_key = $api_keys[ $idx ];

			$response = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . rawurlencode( $api_key ),
				array(
					'timeout' => 45,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( array(
						'contents'         => array(
							array( 'parts' => array( array( 'text' => $prompt ) ) ),
						),
						'generationConfig' => array(
							'maxOutputTokens' => 320,
							'thinkingConfig'  => array(
								'thinkingLevel' => 'minimal',
							),
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
				// 網路層錯誤，不算配額問題，換下一把繼續試
				$last_error            = $response;
				$all_quota_this_round  = false;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( $code === 200 ) {
				$decoded = json_decode( $body, true );
				$text    = isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
					? $decoded['candidates'][0]['content']['parts'][0]['text']
					: '';
				$text = trim( $text );

				if ( $text === '' ) {
					$last_error           = new WP_Error( 'gemini_empty', "Key #{$idx} 回傳空白（可能被安全過濾器擋下）" );
					$all_quota_this_round = false;
					continue;
				}

				return $text; // 成功，直接回傳
			}

			$err = json_decode( $body, true );
			$msg = isset( $err['error']['message'] ) ? $err['error']['message'] : "HTTP {$code}";

			if ( 429 === $code ) {
				// 這把 key 配額用完，換下一把繼續試，不 sleep
				$last_error = new WP_Error( 'gemini_quota_single', "Key #{$idx} 配額用盡：" . $msg );
				continue; // all_quota_this_round 維持 true
			}

			// 非配額類錯誤（如 400 參數錯誤、500 伺服器錯誤），沒必要拿其他 key 再試同一筆內容
			return new WP_Error( 'gemini_error', 'Gemini API 錯誤：' . $msg );
		}

		// 跑完一輪：如果不是「全部都因配額失敗」，就沒必要進第二輪硬撐（例如中間夾雜 empty/網路錯誤）
		if ( ! $all_quota_this_round ) {
			break;
		}

		// 這一輪所有 key 都 429：短暫等待，讓配額有機會恢復，再跑下一輪
		if ( $round < $max_rounds - 1 ) {
			sleep( 8 );
		}
	}

	// 所有 key、所有輪次都失敗 → 視為整批配額用盡，交由外層中止批次
	return new WP_Error(
		'gemini_quota',
		'所有 ' . $key_count . ' 把 API Key 皆已達配額上限或失敗：' . ( $last_error ? $last_error->get_error_message() : '未知錯誤' )
	);
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
	// 儲存 API Key（多把，一行一把）
	if (
		isset( $_POST['wxacg_save_apikey'] ) &&
		check_admin_referer( 'wxacg_ai_editorial_save' )
	) {
		$raw  = isset( $_POST['wxacg_gemini_api_keys'] ) ? wp_unslash( $_POST['wxacg_gemini_api_keys'] ) : '';
		$keys = preg_split( '/[\r\n]+/', $raw );
		$keys = array_map( 'sanitize_text_field', $keys );
		$keys = array_map( 'trim', $keys );
		$keys = array_values( array_unique( array_filter( $keys ) ) );

		update_option( 'wxacg_gemini_api_keys', $keys );
		// 舊選項不再使用，清空避免混淆
		delete_option( 'wxacg_gemini_api_key' );

		echo '<div class="notice notice-success is-dismissible"><p>✅ 已儲存 ' . count( $keys ) . ' 把 API Key。</p></div>';
	}

	$api_keys  = wxacg_get_gemini_api_keys();
	$key_count = count( $api_keys );
	$nonce     = wp_create_nonce( 'wxacg_ai_editorial_nonce' );

	global $wpdb;
	$total_anime   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='anime' AND post_status='publish'" );
	$has_editorial = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='anime_editorial_note' AND meta_value != ''" );
	$need_gen      = $total_anime - $has_editorial;
	?>
	<div class="wrap">
	<h1>✍️ AI 編輯短評批次產生器</h1>
	<p style="color:#666;">使用 Gemini API，以「台灣動漫資深編輯」口吻批次產生短評，寫入 <code>anime_editorial_note</code>，前端自動渲染。</p>
	<p style="color:#666;">💡 支援設定多把 API Key，批次處理時會自動輪替使用，單把 key 遇到配額限制會自動換下一把，大幅提升批次速度。</p>

	<!-- 統計卡片 -->
	<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
		<?php
		$cards = array(
			array( 'label' => '動漫總數', 'value' => $total_anime,   'color' => '#2271b1' ),
			array( 'label' => '已有短評', 'value' => $has_editorial, 'color' => '#00a32a' ),
			array( 'label' => '待產生',   'value' => $need_gen,      'color' => $need_gen > 0 ? '#d63638' : '#00a32a' ),
			array( 'label' => 'API Key 數', 'value' => $key_count,   'color' => $key_count > 0 ? '#8250df' : '#d63638' ),
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
		<h2 style="margin-top:0;">🔑 Gemini API Key 設定（可貼多把，一行一把）</h2>
		<form method="post">
			<?php wp_nonce_field( 'wxacg_ai_editorial_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">API Keys</th>
					<td>
						<textarea name="wxacg_gemini_api_keys" rows="7"
								  style="width:460px;font-family:monospace;"
								  placeholder="一行一把&#10;AIzaSy...key1&#10;AIzaSy...key2&#10;AIzaSy...key3"><?php echo esc_textarea( implode( "\n", $api_keys ) ); ?></textarea>
						<p class="description">
							至 <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a> 免費申請，建議用不同 Google 帳號各申請一把以確保配額獨立。<br>
							免費方案通常每把 key 約 5 requests/分鐘；目前共 <strong><?php echo (int) $key_count; ?></strong> 把，
							理論上限約 <strong><?php echo (int) ( $key_count * 5 ); ?></strong> requests/分鐘。
						</p>
					</td>
				</tr>
			</table>
			<button type="submit" name="wxacg_save_apikey" class="button button-primary">儲存 API Keys</button>
			<?php if ( $key_count > 0 ) : ?>
			<span style="margin-left:12px;color:#00a32a;">✅ 已設定 <?php echo (int) $key_count; ?> 把 Key</span>
			<?php else : ?>
			<span style="margin-left:12px;color:#d63638;">⚠️ 尚未設定任何 Key</span>
			<?php endif; ?>
		</form>
	</div>

	<!-- 批次設定 -->
	<?php if ( $key_count > 0 ) : ?>
	<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;">
		<h2 style="margin-top:0;">🚀 批次產生設定</h2>

		<table class="form-table" role="presentation">
			<tr>
				<th>每批數量</th>
				<td>
					<select id="wxacg-batch-size">
						<?php
						$size_options = array(
							5  => '5 部',
							10 => '10 部',
							20 => '20 部',
							30 => '30 部',
							60 => '60 部',
						);
						$recommended  = min( 60, max( 5, $key_count * 5 ) );
						foreach ( $size_options as $val => $label ) :
							$is_recommended = ( $val === $recommended );
						?>
						<option value="<?php echo (int) $val; ?>" <?php selected( $is_recommended ); ?>>
							<?php echo esc_html( $label ); ?><?php echo $is_recommended ? '（依 ' . (int) $key_count . ' 把 key 建議值）' : ''; ?>
						</option>
						<?php endforeach; ?>
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

		// 所有 key 都配額用盡時的冷卻秒數；一般狀況下批次間的短暫延遲
		var RATE_LIMIT_COOLDOWN_MS = 65000;
		var NORMAL_DELAY_MS        = 500;

		if ( ! startBtn ) return;

		var running    = false;
		var offset     = 0;
		var totalDone  = 0;
		var keyCursor  = 0;

		startBtn.addEventListener('click', function () {
			if ( running ) return;
			running    = true;
			offset     = 0;
			totalDone  = 0;
			keyCursor  = 0;

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
			fd.append('key_cursor', keyCursor);

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
				keyCursor  = data.next_key_cursor;

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
				progressText.textContent = '已完成：' + totalDone + ' 部　剩餘：' + data.total_remaining + ' 部　(' + pct.toFixed(1) + '%)　使用 ' + data.key_count + ' 把 key 輪替中';

				if ( data.total_remaining <= 0 ) {
					addLog( '🎉 全部完成！共產生 ' + totalDone + ' 筆短評。', '#89dceb' );
					running = false;
					startBtn.textContent   = '🚀 重新產生';
					startBtn.style.display = '';
					stopBtn.style.display  = 'none';
					return;
				}

				if ( ! running ) return;

				if ( data.rate_limited ) {
					// 所有 key 都配額用盡：等冷卻時間再送下一批，並倒數提示，而不是立刻重打
					var remainMs = RATE_LIMIT_COOLDOWN_MS;
					addLog( '⏳ 所有 API Key 皆已達配額上限，冷卻 ' + Math.round(remainMs/1000) + ' 秒後自動繼續…', '#f9e2af' );
					var countdown = setInterval(function () {
						remainMs -= 1000;
						if ( ! running ) { clearInterval(countdown); return; }
						if ( remainMs <= 0 ) {
							clearInterval(countdown);
							progressText.textContent = '已完成：' + totalDone + ' 部　剩餘：' + data.total_remaining + ' 部　(' + pct.toFixed(1) + '%)　— 恢復中…';
							runBatch();
						} else {
							progressText.textContent = '已完成：' + totalDone + ' 部　剩餘：' + data.total_remaining + ' 部　— 冷卻中，' + Math.ceil(remainMs/1000) + ' 秒後繼續';
						}
					}, 1000);
					return;
				}

				if ( data.processed === 0 ) {
					addLog( '🎉 全部完成！共產生 ' + totalDone + ' 筆短評。', '#89dceb' );
					running = false;
					startBtn.textContent   = '🚀 重新產生';
					startBtn.style.display = '';
					stopBtn.style.display  = 'none';
					return;
				}

				setTimeout( runBatch, NORMAL_DELAY_MS );
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