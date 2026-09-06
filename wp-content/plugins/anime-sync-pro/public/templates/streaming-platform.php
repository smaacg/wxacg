<?php
/**
 * Streaming Platform Template (單一平台作品清單 /streaming/{platform}/)
 * Path: wp-content/plugins/anime-sync-pro/public/templates/streaming-platform.php
 * Version: 1.0.0 (2026-09-03)
 *
 * 列出該串流平台在本站收錄的動畫，依年份分組、新到舊。
 *
 * SEO / AEO / GEO 的處理：
 *   - 開頭段落直接回答「這個平台有哪些動畫可以看」並附數字與更新日期
 *   - ItemList + FAQPage 結構化資料
 *   - 每部作品同時輸出台灣譯名與（若有）大陸譯名，讓兩種查詢都能命中
 *     ——這是 YourAnimes 這類純台灣站沒有的優勢，也對應本站 88% 的簡體讀者
 *
 * 分頁：作品可能上百部，一次全出會拖慢頁面也不利於評價，
 *       因此每頁 60 部並輸出 rel=prev/next。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$key = Anime_Sync_Streaming_Routing::current_platform_key();
if ( $key === '' ) {
	get_header();
	echo '<div class="asp-sp-wrap"><p>找不到這個串流平台。</p></div>';
	get_footer();
	return;
}

$platform = Anime_Sync_Streaming_Registry::get( $key );
$label    = $platform['label'] ?? $key;
$color    = $platform['color'] ?? '#666';
$icon     = Anime_Sync_Streaming_Routing::icon_url( $platform['icon'] ?? '' );
$is_tw    = empty( $platform['global'] );

$billing_label = [ 'free' => '免費', 'sub' => '訂閱制', 'rent' => '單次租看', 'buy' => '購買' ];
$billing = $billing_label[ $platform['billing'] ?? 'sub' ] ?? '訂閱制';

/* 台灣定價（可能為 null：台灣未營運或單次計費） */
$pricing = Anime_Sync_Streaming_Registry::pricing( $key );

$per_page = 60;
$paged    = max( 1, (int) get_query_var( 'paged' ) ?: ( isset( $_GET['p'] ) ? (int) $_GET['p'] : 1 ) );

$q = new WP_Query( [
	'post_type'      => 'anime',
	'post_status'    => 'publish',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ],
	'meta_key'       => 'anime_start_date',
	'meta_query'     => [
		[
			'key'     => 'anime_tw_streaming',
			'value'   => '"' . $key . '"',
			'compare' => 'LIKE',
		],
	],
] );

$total   = (int) $q->found_posts;
$updated = current_time( 'Y-m-d' );

get_header();

/* ── 結構化資料 ── */
$items = [];
$pos   = ( $paged - 1 ) * $per_page;
foreach ( $q->posts as $post_obj ) {
	$pos++;
	$items[] = [
		'@type'    => 'ListItem',
		'position' => $pos,
		'url'      => get_permalink( $post_obj->ID ),
		'name'     => get_the_title( $post_obj->ID ),
	];
}
$faq = [
	[
		'q' => $label . ' 有哪些動畫可以看？',
		'a' => sprintf( '本站收錄 %s 上架的動畫共 %s 部，可在本頁依年份瀏覽完整清單。', $label, number_format( $total ) ),
	],
	[
		'q' => $label . ' 要付費嗎？多少錢？',
		/*
		 * 帶入實際價格而不只是「訂閱制」三個字——這一題正是使用者搜尋
		 * 「XX 多少錢」時要的答案，AI 引擎也會直接引用這個句子。
		 * 查無台灣定價的平台退回原本的說法，不編造數字。
		 */
		'a' => ( $pricing !== null && ( $pricing['from'] ?? null ) !== null )
			? implode( '', array_filter( [
				(int) $pricing['from'] === 0
					? sprintf( '%s 可以免費觀看。', $label )
					: sprintf( '%s 需要付費，最低月費 NT$%s 起。', $label, number_format( (int) $pricing['from'] ) ),
				$pricing['note'] ? $pricing['note'] . '。' : '',
				$total > 0 ? sprintf( '本站收錄該平台 %s 部動畫。', number_format( $total ) ) : '',
				sprintf( '價格為 %s 查得之最低方案，實際以官方公告為準。', Anime_Sync_Streaming_Registry::PRICING_UPDATED ),
			] ) )
			: sprintf( '%s 的主要計費方式為「%s」。實際方案與可觀看範圍以平台公告為準。', $label, $billing ),
	],
];
$schema = [
	'@context' => 'https://schema.org',
	'@graph'   => [
		[
			'@type'        => 'CollectionPage',
			'name'         => $label . ' 動畫線上看清單',
			'description'  => sprintf( '%s 在台灣上架的動畫清單，本站收錄 %s 部。', $label, number_format( $total ) ),
			'url'          => Anime_Sync_Streaming_Routing::platform_url( $key ),
			'dateModified' => $updated,
			/* 平台本身當作 Organization，價格掛在它的 makesOffer 上，
			   讓「XX 多少錢」這種問題有機器可讀的答案 */
			'about'        => array_filter( [
				'@type'      => 'Organization',
				'name'       => $label,
				'makesOffer' => ( $pricing !== null && ( $pricing['from'] ?? null ) !== null )
					? array_filter( [
						'@type'             => 'Offer',
						'name'              => (int) $pricing['from'] === 0 ? '免費方案' : '最低訂閱方案',
						'price'             => (string) (int) $pricing['from'],
						'priceCurrency'     => 'TWD',
						'category'          => $billing,
						'url'               => $pricing['url'] ?? '',
						'availableAtOrFrom' => [ '@type' => 'Country', 'name' => 'Taiwan' ],
					] )
					: null,
			] ),
			'mainEntity'   => [ '@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => $items ],
		],
		[
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				static fn( $x ) => [
					'@type'          => 'Question',
					'name'           => $x['q'],
					'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $x['a'] ],
				],
				$faq
			),
		],
		[
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => '首頁', 'item' => home_url( '/' ) ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => '串流平台', 'item' => Anime_Sync_Streaming_Routing::index_url() ],
				[ '@type' => 'ListItem', 'position' => 3, 'name' => $label ],
			],
		],
	],
];
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="asp-sp-wrap" style="--asp-sp-color: <?php echo esc_attr( $color ); ?>">

	<nav class="asp-sp-crumb" aria-label="麵包屑">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a>
		<span aria-hidden="true">›</span>
		<a href="<?php echo esc_url( Anime_Sync_Streaming_Routing::index_url() ); ?>">串流平台</a>
		<span aria-hidden="true">›</span>
		<span><?php echo esc_html( $label ); ?></span>
	</nav>

	<header class="asp-sp-hero">
		<?php if ( $icon ) : ?>
			<img class="asp-sp-icon" src="<?php echo esc_url( $icon ); ?>" alt="" width="64" height="64" decoding="async">
		<?php endif; ?>
		<div>
			<h1 class="asp-sp-title"><?php echo esc_html( $label ); ?> 動畫線上看清單</h1>
			<?php /* 第一段就給出可摘錄的答案 */ ?>
			<p class="asp-sp-lead">
				本站收錄 <strong><?php echo esc_html( $label ); ?></strong> 上架的動畫共
				<strong><?php echo esc_html( number_format( $total ) ); ?></strong> 部，
				計費方式為<strong><?php echo esc_html( $billing ); ?></strong><?php
				/*
				 * 有查到台灣定價就把數字寫進導言，讓摘要與 AI 引用能直接抓走。
				 * 免費平台不補「（可免費觀看）」——上一句的計費方式已經寫著「免費」，
				 * 再補一次是廢話。
				 */
				if ( $pricing !== null && (int) ( $pricing['from'] ?? 0 ) > 0 ) : ?>（最低月費 <strong>NT$<?php echo esc_html( number_format( (int) $pricing['from'] ) ); ?></strong> 起）<?php
				endif; ?>，
				屬於<?php echo $is_tw ? '台灣本地' : '國際'; ?>串流平台。
			</p>
			<?php if ( ! empty( $pricing['note'] ) ) : ?>
				<p class="asp-sp-pricenote">
					<?php echo esc_html( $pricing['note'] ); ?>
					<?php
					/* 追蹤標籤帶平台 key，聯盟後台就能看出是哪個平台頁帶來的點擊 */
					$plan = Anime_Sync_Streaming_Registry::pricing_link( $key, 'streaming-' . $key );
					if ( $plan['url'] !== '' ) :
						?>
						<a href="<?php echo esc_url( $plan['url'] ); ?>" target="_blank"
							rel="nofollow noopener<?php echo $plan['sponsored'] ? ' sponsored' : ''; ?>">官方方案 →</a>
					<?php endif; ?>
					<span class="asp-sp-pricedate">（<?php echo esc_html( Anime_Sync_Streaming_Registry::PRICING_UPDATED ); ?> 查得，實際以官方公告為準）</span>
				</p>
			<?php endif; ?>
			<p class="asp-sp-updated">資料更新：<time datetime="<?php echo esc_attr( $updated ); ?>"><?php echo esc_html( $updated ); ?></time></p>
		</div>
	</header>

	<?php if ( ! $q->have_posts() ) : ?>
		<p class="asp-sp-empty">目前沒有收錄這個平台的作品。</p>
	<?php else : ?>
		<div class="asp-sp-grid">
			<?php
			while ( $q->have_posts() ) :
				$q->the_post();
				$pid   = get_the_ID();
				$cover = get_post_meta( $pid, 'anime_cover_image', true ) ?: get_the_post_thumbnail_url( $pid, 'medium' );
				$year  = (int) get_post_meta( $pid, 'anime_season_year', true );
				$eps   = (int) get_post_meta( $pid, 'anime_episodes', true );
				/* 同時提供大陸譯名，讓簡體查詢也能命中——本站 88% 讀者來自簡體搜尋 */
				$simp  = trim( (string) get_post_meta( $pid, 'anime_title_simplified', true ) );
				$title = get_the_title();
				?>
				<a class="asp-sp-card" href="<?php the_permalink(); ?>">
					<span class="asp-sp-cover">
						<?php if ( $cover ) : ?>
							<img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $title ); ?>"
								loading="lazy" decoding="async">
						<?php endif; ?>
					</span>
					<span class="asp-sp-name"><?php echo esc_html( $title ); ?></span>
					<?php if ( $simp !== '' && $simp !== $title ) : ?>
						<span class="asp-sp-alt"><?php echo esc_html( $simp ); ?></span>
					<?php endif; ?>
					<span class="asp-sp-info">
						<?php if ( $year ) : ?><span><?php echo esc_html( (string) $year ); ?></span><?php endif; ?>
						<?php if ( $eps ) : ?><span><?php echo esc_html( (string) $eps ); ?> 集</span><?php endif; ?>
					</span>
				</a>
			<?php endwhile; wp_reset_postdata(); ?>
		</div>

		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) :
			$base = Anime_Sync_Streaming_Routing::platform_url( $key );
			?>
			<nav class="asp-sp-pager" aria-label="分頁">
				<?php if ( $paged > 1 ) : ?>
					<a href="<?php echo esc_url( $paged - 1 === 1 ? $base : add_query_arg( 'p', $paged - 1, $base ) ); ?>">← 上一頁</a>
				<?php endif; ?>
				<span><?php echo esc_html( $paged . ' / ' . $pages ); ?></span>
				<?php if ( $paged < $pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'p', $paged + 1, $base ) ); ?>">下一頁 →</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<section class="asp-sp-faq">
		<h2>常見問題</h2>
		<?php foreach ( $faq as $x ) : ?>
			<details class="asp-sp-faqitem">
				<summary><?php echo esc_html( $x['q'] ); ?></summary>
				<p><?php echo esc_html( $x['a'] ); ?></p>
			</details>
		<?php endforeach; ?>
	</section>

	<p class="asp-sp-disclaimer">
		上架情形以 <?php echo esc_html( $label ); ?> 官方公告為準，本站資料僅供參考，可能與實際上架狀況有落差。
	</p>
</div>

<style>
.asp-sp-wrap { max-width: 1120px; margin: 0 auto; padding: 22px 16px 64px; }
.asp-sp-crumb { font-size: 13px; opacity: .7; margin-bottom: 16px; display: flex; gap: 7px; flex-wrap: wrap; }
.asp-sp-crumb a { color: inherit; text-decoration: none; }
.asp-sp-crumb a:hover { text-decoration: underline; }
.asp-sp-hero { display: flex; gap: 18px; align-items: flex-start; margin-bottom: 30px;
    padding-bottom: 22px; border-bottom: 3px solid var(--asp-sp-color); }
.asp-sp-icon { width: 64px; height: 64px; border-radius: 14px; object-fit: contain; background: #fff; flex: 0 0 auto; }
.asp-sp-title { font-size: 27px; font-weight: 800; margin: 0 0 10px; line-height: 1.3; }
.asp-sp-lead { font-size: 15.5px; line-height: 1.9; margin: 0 0 6px; max-width: 62ch; }
.asp-sp-updated { font-size: 13px; opacity: .6; margin: 0; }
/* 方案但書：接在導言下方，字級降階不搶主要數字 */
.asp-sp-pricenote { font-size: 13px; line-height: 1.8; opacity: .75; margin: 0 0 6px; max-width: 62ch; }
.asp-sp-pricenote a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
.asp-sp-pricedate { opacity: .7; }
.asp-sp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 16px; }
.asp-sp-card { display: flex; flex-direction: column; gap: 5px; text-decoration: none; color: inherit; }
.asp-sp-cover { display: block; aspect-ratio: 3/4; border-radius: 10px; overflow: hidden;
    background: rgba(127,127,127,.12); }
.asp-sp-cover img { width: 100%; height: 100%; object-fit: cover; display: block;
    transition: transform .2s ease; }
.asp-sp-card:hover .asp-sp-cover img { transform: scale(1.04); }
.asp-sp-name { font-size: 14px; font-weight: 600; line-height: 1.45;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.asp-sp-alt { font-size: 12px; opacity: .55; line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
.asp-sp-info { display: flex; gap: 8px; font-size: 12px; opacity: .6; font-variant-numeric: tabular-nums; }
.asp-sp-pager { display: flex; gap: 16px; justify-content: center; align-items: center;
    margin-top: 34px; font-size: 14px; }
.asp-sp-pager a { color: inherit; text-decoration: none; padding: 7px 14px; border-radius: 999px;
    border: 1px solid rgba(127,127,127,.3); }
.asp-sp-pager a:hover { border-color: var(--asp-sp-color); }
.asp-sp-faq { margin-top: 44px; }
.asp-sp-faq h2 { font-size: 19px; font-weight: 700; margin: 0 0 14px;
    padding-left: 10px; border-left: 4px solid var(--asp-sp-color); }
.asp-sp-faqitem { padding: 12px 0; border-bottom: 1px solid rgba(127,127,127,.18); }
.asp-sp-faqitem summary { cursor: pointer; font-weight: 600; }
.asp-sp-faqitem p { margin: 10px 0 0; line-height: 1.85; opacity: .88; }
.asp-sp-empty { padding: 60px 20px; text-align: center; opacity: .6; }
.asp-sp-disclaimer { margin-top: 34px; font-size: 12.5px; opacity: .6; line-height: 1.8; }
@media screen and (max-width: 600px) {
    .asp-sp-title { font-size: 21px; }
    .asp-sp-hero { gap: 12px; }
    .asp-sp-icon { width: 48px; height: 48px; }
    .asp-sp-grid { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 12px; }
}
</style>

<?php get_footer(); ?>
