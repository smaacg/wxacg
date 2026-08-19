<?php
/**
 * 控制台「營運概況」小工具。
 *
 * 顯示上線天數、收錄數量、會員數，以及一句每天輪替的短語。
 *
 * @package WXACG_Admin_Menu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 兩個里程碑日期（單一來源）。
 *
 * 要改日期改這裡；不想動程式碼則掛 wxacg_site_milestones filter 覆寫。
 *
 * @return array{built:string, public:string}
 */
function wxacg_site_milestones(): array {
	return (array) apply_filters(
		'wxacg_site_milestones',
		array(
			'built'  => '2026-04-08',   // 開始架設（＝ WordPress 安裝日）
			'public' => '2026-06-11',   // 正式公開
		)
	);
}

/**
 * 從某個日期到今天經過幾天。
 *
 * ★ 一律用站台時區（wp_timezone）計算：WordPress 會把 PHP 的預設時區設成
 *   UTC，直接用 time() 或 date() 會在跨日前後差一天。
 *
 * @param string $date Y-m-d 格式。
 * @return int
 */
function wxacg_days_since( string $date ): int {
	try {
		$tz    = wp_timezone();
		$start = new DateTimeImmutable( $date . ' 00:00:00', $tz );
		$now   = new DateTimeImmutable( 'now', $tz );
	} catch ( Exception $e ) {
		return 0;
	}

	return max( 0, (int) $start->diff( $now )->days );
}

/**
 * 收錄數量與會員數。
 *
 * ★ 為什麼要快取：角色 25,000+、聲優 10,000+ 都在自訂資料表，每次開控制台
 *   都 COUNT 一次沒有必要。CPT 的部分 wp_count_posts() 本身有快取，但自訂表
 *   沒有，因此整包存進 1 小時的 transient。數字晚一小時更新完全不影響判讀。
 *
 * ★ 只算 publish：動畫另有 500 多篇草稿，那些在站上看不到，
 *   算進來等於對自己灌水。
 *
 * @return array<string, int>
 */
function wxacg_dashboard_stats(): array {
	$cache_key = 'wxacg_dashboard_stats';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$stats = array(
		'anime'      => 0,
		'manga'      => 0,
		'post'       => 0,
		'characters' => 0,
		'persons'    => 0,
		'members'    => 0,
	);

	foreach ( array( 'anime', 'manga', 'post' ) as $type ) {
		if ( post_type_exists( $type ) ) {
			$counts          = wp_count_posts( $type );
			$stats[ $type ] = isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
	}

	// 自訂資料表：表不存在就維持 0，不讓控制台因為缺表而出錯
	foreach ( array( 'characters' => 'anime_characters', 'persons' => 'anime_persons' ) as $key => $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found === $table ) {
			$stats[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	$users            = count_users();
	$stats['members'] = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;

	set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

	return $stats;
}

/**
 * 每日短語。
 *
 * ★ 刻意不引用動漫角色台詞：出處、譯法、版本都容易記錯，寫出來也難查證。
 *   這裡全部是關於經營站台本身的原創短句，不掛任何角色名或作品名。
 *
 * 要增刪就改這個陣列，其餘邏輯不必動。
 *
 * @return array<int, string>
 */
function wxacg_dashboard_quotes(): array {
	return (array) apply_filters(
		'wxacg_dashboard_quotes',
		array(
			'今天多收錄一部，明天就少一個找不到資料的人。',
			'資料庫沒有捷徑，只有一筆一筆累積起來的可信。',
			'更新不必轟轟烈烈，能持續才是最難的那件事。',
			'有人搜到了想找的角色，昨天的工就有了意義。',
			'慢慢來比較快——寫錯的資料要花十倍時間修回來。',
			'站是給讀者用的，不是給自己看的。',
			'一個正確的欄位，勝過十個華麗的版面。',
			'冷門作品也有人在等，那正是大站不會做的事。',
			'今天不想動的時候，補一筆就好。',
			'流量會起伏，資料的品質不會。',
			'把來源寫清楚，未來的自己會感謝現在的自己。',
			'能自動化的就別手動，省下的時間拿去做判斷。',
			'先讓它正確，再讓它好看。',
			'每一次校正，都是在替讀者省下懷疑的時間。',
			'做久了才知道，穩定比爆紅值錢。',
			'資料錯了要認，改掉就好，別讓它留在站上。',
			'今天的一小步，是三年後別人查得到的那一筆。',
			'不用跟大站比快，比準就好。',
			'讀者不會謝你熬夜，但會記得你沒寫錯。',
			'站台是慢慢長出來的，不是一次蓋好的。',
		)
	);
}

/**
 * 註冊小工具，並儘量排到最前面。
 *
 * ★ 只對「沒有自己拖曳過控制台」的使用者有效：WordPress 會把個人排列順序
 *   存在 meta-box-order_dashboard 這個 user meta，一旦拖曳過就以個人設定為準。
 *   這是 WordPress 的既有行為，本外掛不去覆寫個人偏好。
 */
add_action(
	'wp_dashboard_setup',
	function (): void {
		wp_add_dashboard_widget(
			'wxacg_site_overview',
			'🌸 營運概況',
			'wxacg_render_dashboard_widget'
		);

		global $wp_meta_boxes;
		$core = $wp_meta_boxes['dashboard']['normal']['core'] ?? array();
		if ( ! isset( $core['wxacg_site_overview'] ) ) {
			return;
		}

		$ours = array( 'wxacg_site_overview' => $core['wxacg_site_overview'] );
		unset( $core['wxacg_site_overview'] );
		$wp_meta_boxes['dashboard']['normal']['core'] = array_merge( $ours, $core );
	}
);

/**
 * 小工具內容。
 */
function wxacg_render_dashboard_widget(): void {
	$milestones = wxacg_site_milestones();
	$stats      = wxacg_dashboard_stats();
	$quotes     = wxacg_dashboard_quotes();

	$days_public = wxacg_days_since( $milestones['public'] );
	$days_built  = wxacg_days_since( $milestones['built'] );

	// 依「一年中的第幾天」挑句子：每天換一句，同一天內固定不變
	$quote = '';
	if ( $quotes ) {
		$quote = $quotes[ (int) wp_date( 'z' ) % count( $quotes ) ];
	}

	$items = array(
		array( '動畫', $stats['anime'] ),
		array( '漫畫', $stats['manga'] ),
		array( '角色', $stats['characters'] ),
		array( '聲優', $stats['persons'] ),
		array( '會員', $stats['members'] ),
		array( '文章', $stats['post'] ),
	);
	?>
	<div class="wxacg-overview">
		<p class="wxacg-overview__days">
			公開營運 <strong><?php echo esc_html( number_format( $days_public ) ); ?></strong> 天
		</p>
		<p class="wxacg-overview__since">
			<?php echo esc_html( $milestones['public'] ); ?> 公開
			　·
			<?php echo esc_html( $milestones['built'] ); ?> 開始架設（第 <?php echo esc_html( number_format( $days_built ) ); ?> 天）
		</p>

		<ul class="wxacg-overview__grid">
			<?php foreach ( $items as $item ) : ?>
				<li>
					<span class="wxacg-overview__num"><?php echo esc_html( number_format( $item[1] ) ); ?></span>
					<span class="wxacg-overview__label"><?php echo esc_html( $item[0] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( '' !== $quote ) : ?>
			<p class="wxacg-overview__quote"><?php echo esc_html( $quote ); ?></p>
		<?php endif; ?>
	</div>

	<style>
		/*
		 * 顏色一律取自 WordPress 後台既有變數／通用色，
		 * 使用者換後台配色方案時才不會有一塊格格不入。
		 */
		.wxacg-overview__days {
			margin: 0 0 2px;
			font-size: 13px;
			color: #50575e;
		}
		.wxacg-overview__days strong {
			font-size: 24px;
			line-height: 1.2;
			color: #1d2327;
			vertical-align: -2px;
			margin: 0 2px;
		}
		.wxacg-overview__since {
			margin: 0 0 14px;
			font-size: 12px;
			color: #787c82;
		}
		.wxacg-overview__grid {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 12px 8px;
			margin: 0;
			padding: 14px 0 0;
			border-top: 1px solid #dcdcde;
			list-style: none;
		}
		.wxacg-overview__grid li {
			margin: 0;
			text-align: center;
		}
		.wxacg-overview__num {
			display: block;
			font-size: 18px;
			font-weight: 600;
			line-height: 1.3;
			color: #1d2327;
			/* 數字對齊：等寬數字讓三欄的位數不會歪 */
			font-variant-numeric: tabular-nums;
		}
		.wxacg-overview__label {
			display: block;
			font-size: 12px;
			color: #787c82;
		}
		.wxacg-overview__quote {
			margin: 14px 0 0;
			padding-top: 12px;
			border-top: 1px solid #dcdcde;
			font-size: 13px;
			line-height: 1.7;
			color: #50575e;
		}
		/* 兩欄以下時改成兩欄，避免數字被擠到換行 */
		@media screen and (max-width: 782px) {
			.wxacg-overview__grid { grid-template-columns: repeat(2, 1fr); }
		}
	</style>
	<?php
}
