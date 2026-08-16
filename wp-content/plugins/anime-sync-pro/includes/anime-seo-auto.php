<?php
/**
 * Anime Sync Pro - Auto SEO for Anime CPT
 *
 * File:
 * anime-sync-pro/includes/anime-seo-auto.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * 1. Helper：取得乾淨作品名稱
 * ============================================================ */
if ( ! function_exists( 'wx_asp_get_clean_anime_title' ) ) {
	function wx_asp_get_clean_anime_title( $post_id ) {
		$title_chinese = get_post_meta( $post_id, 'anime_title_chinese', true );

		$title = $title_chinese ?: get_the_title( $post_id );
		$title = wp_strip_all_tags( (string) $title );

		$remove_words = [
			'線上看',
			'在线观看',
			'動畫線上看',
			'免費線上看',
			'全集線上看',
			' - 微笑動漫',
		];

		$title = str_replace( $remove_words, '', $title );
		$title = preg_replace( '/\s+/', ' ', $title );

		return trim( $title );
	}
}

/* ============================================================
 * 2. Helper：產生作品頁 Meta Description
 * ============================================================ */
if ( ! function_exists( 'wx_asp_get_anime_seo_desc' ) ) {
	function wx_asp_get_anime_seo_desc( $post_id ) {
		$title = wx_asp_get_clean_anime_title( $post_id );

		$season_year = (int) get_post_meta( $post_id, 'anime_season_year', true );
		$season      = get_post_meta( $post_id, 'anime_season', true );
		$episodes    = (int) get_post_meta( $post_id, 'anime_episodes', true );
		$studio      = get_post_meta( $post_id, 'anime_studios', true );
		$source      = get_post_meta( $post_id, 'anime_source', true );

		$season_labels = [
			'WINTER' => '冬季',
			'SPRING' => '春季',
			'SUMMER' => '夏季',
			'FALL'   => '秋季',
		];

		$source_labels = [
			'ORIGINAL'           => '原創',
			'MANGA'              => '漫畫改編',
			'LIGHT_NOVEL'        => '輕小說改編',
			'NOVEL'              => '小說改編',
			'GAME'               => '遊戲改編',
			'VIDEO_GAME'         => '遊戲改編',
			'WEB_MANGA'          => '網路漫畫改編',
			'VISUAL_NOVEL'       => '視覺小說改編',
			'BOOK'               => '書籍改編',
			'MULTIMEDIA_PROJECT' => '跨媒體企劃',
			'OTHER'              => '其他',
		];

		$parts = [];

		if ( $title ) {
			$parts[] = '《' . $title . '》動畫作品資訊整理';
		}

		if ( $season_year ) {
			$season_text = $season_labels[ $season ] ?? '';
			$parts[] = $season_year . '年' . $season_text . '動畫';
		}

		if ( $episodes ) {
			$parts[] = '全' . $episodes . '集';
		}

		if ( $studio ) {
			$parts[] = '動畫製作：' . $studio;
		}

		if ( $source && isset( $source_labels[ $source ] ) ) {
			$parts[] = $source_labels[ $source ];
		}

		$desc = implode( '，', array_filter( $parts ) );

		if ( $desc ) {
			$desc .= '，包含播出時間、集數、官方PV、聲優陣容、劇情簡介與合法線上看／串流平台資訊。';
		} else {
			$desc = '動畫作品資訊整理，包含播出時間、集數、官方PV、聲優陣容、劇情簡介與合法線上看／串流平台資訊。';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $desc, 0, 160 );
		}

		return substr( $desc, 0, 160 );
	}
}

/* ============================================================
 * 3. Helper：產生作品頁 SEO Title
 * ============================================================ */
if ( ! function_exists( 'wx_asp_get_anime_seo_title' ) ) {
	function wx_asp_get_anime_seo_title( $post_id ) {
		$anime_title = wx_asp_get_clean_anime_title( $post_id );

		if ( ! $anime_title ) {
			return '';
		}

		// 「線上看」提前，貼近台灣搜尋意圖；標題精簡避免被 Google 截斷
		return $anime_title . ' 線上看｜播出時間、聲優、串流平台整理';
	}
}


/* ============================================================
 * 4. 前台 Rank Math SEO Title
 * ============================================================ */
add_filter( 'rank_math/frontend/title', function( $title ) {
	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if ( get_post_status( $post_id ) !== 'publish' ) {
			return $title;
		}

		$seo_title = wx_asp_get_anime_seo_title( $post_id );

		if ( $seo_title ) {
			return $seo_title;
		}
	}

	return $title;
}, 20 );

/* ============================================================
 * 5. 前台 Rank Math Meta Description
 * ============================================================ */
add_filter( 'rank_math/frontend/description', function( $description ) {
	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if ( get_post_status( $post_id ) !== 'publish' ) {
			return $description;
		}

		return wx_asp_get_anime_seo_desc( $post_id );
	}

	return $description;
}, 20 );

/* ============================================================
 * 6. Facebook OG Title
 * ============================================================ */
add_filter( 'rank_math/opengraph/facebook/title', function( $title ) {
	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if ( get_post_status( $post_id ) !== 'publish' ) {
			return $title;
		}

		$anime_title = wx_asp_get_clean_anime_title( $post_id );

		if ( $anime_title ) {
			return '《' . $anime_title . '》播出時間、聲優、PV與串流平台整理｜微笑動漫';
		}
	}

	return $title;
}, 20 );

/* ============================================================
 * 7. Facebook OG Description
 * ============================================================ */
add_filter( 'rank_math/opengraph/facebook/description', function( $description ) {
	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if ( get_post_status( $post_id ) !== 'publish' ) {
			return $description;
		}

		return wx_asp_get_anime_seo_desc( $post_id );
	}

	return $description;
}, 20 );

/* ============================================================
 * 8. Twitter Title
 * ============================================================ */
add_filter( 'rank_math/opengraph/twitter/title', function( $title ) {
	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if ( get_post_status( $post_id ) !== 'publish' ) {
			return $title;
		}

		$anime_title = wx_asp_get_clean_anime_title( $post_id );

		if ( $anime_title ) {
			return '《' . $anime_title . '》播出時間、聲優、PV與串流平台整理｜微笑動漫';
		}
	}

	return $title;
}, 20 );

/* ============================================================
 * 9. Twitter Description
 * ============================================================ */
add_filter( 'rank_math/opengraph/twitter/description', function( $description ) {
	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if ( get_post_status( $post_id ) !== 'publish' ) {
			return $description;
		}

		return wx_asp_get_anime_seo_desc( $post_id );
	}

	return $description;
}, 20 );

/* ============================================================
 * 9.5. Helper：寫入 Rank Math SEO meta，但保留使用者手動修改過的內容
 *
 * 判斷邏輯：目前值若等於「上次我們自動寫入的值」（或還沒有值），代表
 * 沒被手動改過，可放心覆寫成最新自動產生內容，並更新追蹤值；若不相等，
 * 代表使用者已在 Rank Math 後台手動調整過該欄位，跳過不覆寫，避免
 * 每次儲存/回填都把手動內容蓋掉。
 * ============================================================ */
if ( ! function_exists( 'wx_asp_write_seo_meta_protected' ) ) {
	function wx_asp_write_seo_meta_protected( int $post_id, string $focus_keyword, string $seo_title, string $seo_desc ): void {
		$tracking_keys = [
			'rank_math_focus_keyword' => '_wx_asp_last_auto_keyword',
			'rank_math_title'         => '_wx_asp_last_auto_title',
			'rank_math_description'   => '_wx_asp_last_auto_desc',
		];
		$new_values = [
			'rank_math_focus_keyword' => $focus_keyword,
			'rank_math_title'         => $seo_title,
			'rank_math_description'   => $seo_desc,
		];

		foreach ( $tracking_keys as $meta_key => $tracking_key ) {
			$new_value = $new_values[ $meta_key ];
			$current   = (string) get_post_meta( $post_id, $meta_key, true );
			$last_auto = (string) get_post_meta( $post_id, $tracking_key, true );

			$is_manual = ( '' !== $current && $current !== $last_auto );

			if ( $is_manual ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, $new_value );
			update_post_meta( $post_id, $tracking_key, $new_value );
		}
	}
}

/* ============================================================
 * 10. 儲存 anime 時，自動寫入 Rank Math 後台欄位
 * 只處理已發布 publish
 * ============================================================ */
add_action( 'save_post_anime', function( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( get_post_status( $post_id ) !== 'publish' ) {
		return;
	}

	$anime_title = wx_asp_get_clean_anime_title( $post_id );

	if ( ! $anime_title ) {
		return;
	}

	$seo_title = wx_asp_get_anime_seo_title( $post_id );
	$seo_desc  = wx_asp_get_anime_seo_desc( $post_id );

	wx_asp_write_seo_meta_protected( $post_id, $anime_title, $seo_title, $seo_desc );
}, 30 );

/* ============================================================
 * 11. 強化首頁 WebSite / Organization Schema
 * ============================================================ */
add_filter( 'rank_math/json_ld', function( $data, $jsonld ) {
	if ( ! is_front_page() && ! is_home() ) {
		return $data;
	}

	if ( ! is_array( $data ) ) {
		return $data;
	}

	foreach ( $data as $key => $schema ) {
		if ( ! is_array( $schema ) ) {
			continue;
		}

		$type = $schema['@type'] ?? '';

		if ( is_array( $type ) ) {
			$is_website      = in_array( 'WebSite', $type, true );
			$is_organization = in_array( 'Organization', $type, true );
		} else {
			$is_website      = ( $type === 'WebSite' );
			$is_organization = ( $type === 'Organization' );
		}

		if ( $is_website ) {
			$data[ $key ]['name'] = '微笑動漫';
			$data[ $key ]['alternateName'] = [
				'微笑動漫',
				'weixiaoacg',
				'Weixiao ACG',
				'WXACG',
				'微笑ACG',
				'weixiaoacg.com',
			];
			$data[ $key ]['url'] = home_url( '/' );
		}

		if ( $is_organization ) {
			$data[ $key ]['name'] = '微笑動漫';
			$data[ $key ]['alternateName'] = [
				'微笑動漫',
				'weixiaoacg',
				'Weixiao ACG',
				'WXACG',
				'微笑ACG',
			];
			$data[ $key ]['url'] = home_url( '/' );
		}
	}

	return $data;
}, 99, 2 );

/* ============================================================
 * 12. 後台工具：Anime SEO 回填
 * 路徑：工具 > Anime SEO 回填
 * 按一次直接更新全部已發布作品，不跳轉、不分批
 * ============================================================ */
if ( is_admin() ) {

	add_action( 'admin_menu', function() {
		add_management_page(
			'Anime SEO 回填',
			'Anime SEO 回填',
			'manage_options',
			'wx-anime-seo-backfill',
			'wx_asp_render_seo_backfill_page'
		);
	} );

	add_action( 'admin_post_wx_anime_seo_backfill_all', 'wx_asp_handle_seo_backfill_all' );

	if ( ! function_exists( 'wx_asp_backfill_one_post' ) ) {
		function wx_asp_backfill_one_post( int $post_id ): bool {
			if ( get_post_status( $post_id ) !== 'publish' ) {
				return false;
			}

			$anime_title = wx_asp_get_clean_anime_title( $post_id );

			if ( $anime_title === '' ) {
				return false;
			}

			$seo_title = wx_asp_get_anime_seo_title( $post_id );
			$seo_desc  = wx_asp_get_anime_seo_desc( $post_id );

			wx_asp_write_seo_meta_protected( $post_id, $anime_title, $seo_title, $seo_desc );

			return true;
		}
	}

	if ( ! function_exists( 'wx_asp_handle_seo_backfill_all' ) ) {
		function wx_asp_handle_seo_backfill_all() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '權限不足' );
			}

			check_admin_referer( 'wx_anime_seo_backfill_all' );

			@set_time_limit( 300 );
			wp_suspend_cache_addition( true );

			$query = new WP_Query( [
				'post_type'              => 'anime',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			] );

			$updated = 0;
			$skipped = 0;
			$total   = 0;

			if ( ! empty( $query->posts ) ) {
				foreach ( $query->posts as $post_id ) {
					$total++;

					if ( wx_asp_backfill_one_post( (int) $post_id ) ) {
						$updated++;
					} else {
						$skipped++;
					}
				}
			}

			wp_suspend_cache_addition( false );

			$redirect_url = add_query_arg(
				[
					'page'    => 'wx-anime-seo-backfill',
					'done'    => 1,
					'total'   => $total,
					'updated' => $updated,
					'skipped' => $skipped,
				],
				admin_url( 'tools.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	if ( ! function_exists( 'wx_asp_render_seo_backfill_page' ) ) {
		function wx_asp_render_seo_backfill_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '權限不足' );
			}

			$count_posts   = wp_count_posts( 'anime' );
			$total_publish = isset( $count_posts->publish ) ? (int) $count_posts->publish : 0;

			$done    = isset( $_GET['done'] ) ? (int) $_GET['done'] : 0;
			$total   = isset( $_GET['total'] ) ? (int) $_GET['total'] : 0;
			$updated = isset( $_GET['updated'] ) ? (int) $_GET['updated'] : 0;
			$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

			echo '<div class="wrap">';
			echo '<h1>Anime SEO 回填</h1>';

			if ( $done ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo '<strong>SEO 回填完成。</strong><br>';
				echo '本次掃描：' . esc_html( $total ) . ' 筆。<br>';
				echo '成功更新：' . esc_html( $updated ) . ' 筆。<br>';
				echo '略過：' . esc_html( $skipped ) . ' 筆。';
				echo '</p></div>';
			}

			echo '<p>這個工具會一次更新所有「已發布」動畫作品頁的 Rank Math SEO 欄位。</p>';

			echo '<ul style="list-style:disc;padding-left:22px;">';
			echo '<li>只處理：已發布 publish 的 anime 作品頁</li>';
			echo '<li>不處理：草稿、排程、待審、私密、垃圾桶</li>';
			echo '<li>Focus Keyword：作品名稱</li>';
			echo '<li>SEO Title：動畫作品資訊、播出時間、聲優、PV、串流平台整理</li>';
			echo '<li>Meta Description：播出時間、集數、官方PV、聲優、劇情簡介、合法線上看／串流平台資訊</li>';
			echo '</ul>';

			echo '<p><strong>目前已發布動畫作品數：</strong>' . esc_html( $total_publish ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wx_anime_seo_backfill_all">';
			wp_nonce_field( 'wx_anime_seo_backfill_all' );
			echo '<p><button type="submit" class="button button-primary button-large" onclick="return confirm(\'確定要更新全部已發布作品的 Rank Math SEO 欄位嗎？\');">開始批次回填已發布作品</button></p>';
			echo '</form>';

			echo '<p style="color:#666;">建議先備份資料庫。此工具不會修改文章內容、網址、分類、標籤，只會更新 Rank Math SEO meta。</p>';
			echo '</div>';
		}
	}
}
