<?php
/**
 * Streaming Index Template (串流平台總覽 /streaming/)
 * Path: wp-content/plugins/anime-sync-pro/public/templates/streaming-index.php
 * Version: 1.0.0 (2026-09-03)
 *
 * 顯示站上收錄的動畫串流平台，依「台灣可用」與「國際平台」分組，
 * 每張卡片標示計費方式與作品數；作品數達門檻者連往該平台的作品清單。
 *
 * SEO / AEO / GEO 的處理：
 *   - 開頭段落直接給出可被摘錄的數字（平台數、作品數、更新日期）
 *   - 比較表格：搶精選摘要最有效的格式
 *   - FAQPage + ItemList 結構化資料
 *   - 顯示 dateModified，讓生成式引擎判斷時效
 *
 * 版面與樣式做法對齊 series-index.php：get_header() / 內嵌 CSS（.asp-st- 前綴）
 * / get_footer()，不需要動主題檔案。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$stats     = Anime_Sync_Streaming_Routing::get_stats( isset( $_GET['asp_refresh'] ) );
$counts    = $stats['counts'];
$exclusive = $stats['exclusive'];
$all       = Anime_Sync_Streaming_Registry::all();
$icon_of   = Anime_Sync_Streaming_Routing::class;

/** 月費顯示：0 → 免費，null → 破折號，其餘 → NT$N 起 */
$price_text = static function ( ?array $pr ): string {
	if ( $pr === null || ! array_key_exists( 'from', $pr ) || $pr['from'] === null ) {
		return '—';
	}
	return (int) $pr['from'] === 0 ? '免費' : 'NT$' . number_format( (int) $pr['from'] ) . ' 起';
};

$billing_label = [
	'free' => '免費',
	'sub'  => '訂閱制',
	'rent' => '單次租看',
	'buy'  => '購買',
];

/* 依台灣／國際分組，組內依作品數排序 */
$groups = [ 'tw' => [], 'global' => [] ];
foreach ( $all as $p ) {
	$key = $p['key'];
	$p['count'] = (int) ( $counts[ $key ] ?? 0 );
	$groups[ empty( $p['global'] ) ? 'tw' : 'global' ][] = $p;
}
foreach ( $groups as &$g ) {
	usort( $g, static fn( $a, $b ) => $b['count'] <=> $a['count'] );
}
unset( $g );

/* 比較表另外依「月費」排序：讀者問的是「哪家便宜」，不是「哪家片多」 */
$by_price = array_merge( $groups['tw'], $groups['global'] );
usort(
	$by_price,
	static function ( $a, $b ) {
		$pa = Anime_Sync_Streaming_Registry::pricing( $a['key'] );
		$pb = Anime_Sync_Streaming_Registry::pricing( $b['key'] );
		// null（台灣未營運／單次計費）排最後，其餘由便宜到貴
		$va = ( $pa['from'] ?? null ) === null ? PHP_INT_MAX : (int) $pa['from'];
		$vb = ( $pb['from'] ?? null ) === null ? PHP_INT_MAX : (int) $pb['from'];
		return $va === $vb ? ( $b['count'] <=> $a['count'] ) : ( $va <=> $vb );
	}
);

$total_platforms = count( $all );
$total_works     = (int) wp_count_posts( 'anime' )->publish;
$with_streaming  = 0;
global $wpdb;
$with_streaming = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
	   JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID
	  WHERE mm.meta_key = 'anime_tw_streaming'
	    AND mm.meta_value <> '' AND mm.meta_value <> 'a:0:{}'
	    AND p.post_type = 'anime' AND p.post_status = 'publish'"
);
$updated = current_time( 'Y-m-d' );

/* ── 結構化資料：ItemList + FAQPage ── */
$item_list = [];
$pos = 0;
foreach ( array_merge( $groups['tw'], $groups['global'] ) as $p ) {
	if ( $p['count'] <= 0 ) {
		continue;
	}
	$pos++;

	$org = [
		'@type' => 'Organization',
		'name'  => $p['label'],
		'url'   => Anime_Sync_Streaming_Routing::has_own_page( $p['key'] )
			? Anime_Sync_Streaming_Routing::platform_url( $p['key'] )
			: Anime_Sync_Streaming_Routing::index_url(),
	];

	/*
	 * 價格寫進 Offer，讓「台灣最便宜的動畫串流」這種問題有機器可讀的答案。
	 * 只放最低方案（與表格一致），免費平台 price = 0。
	 * 查無台灣定價的（HIDIVE／Hulu／renta!）不輸出 Offer，不編造。
	 */
	$pr = Anime_Sync_Streaming_Registry::pricing( $p['key'] );

	if ( $pr !== null && ( $pr['from'] ?? null ) !== null ) {
		$org['makesOffer'] = [
			'@type'         => 'Offer',
			'name'          => (int) $pr['from'] === 0 ? '免費方案' : '最低訂閱方案',
			'price'         => (string) (int) $pr['from'],
			'priceCurrency' => 'TWD',
			'category'      => $billing_label[ $p['billing'] ?? 'sub' ] ?? '訂閱制',
			'availableAtOrFrom' => [ '@type' => 'Country', 'name' => 'Taiwan' ],
		];
		if ( ! empty( $pr['url'] ) ) {
			$org['makesOffer']['url'] = $pr['url'];
		}
	}

	$item_list[] = [
		'@type'    => 'ListItem',
		'position' => $pos,
		'item'     => $org,
	];
}
$top = $groups['tw'][0] ?? null;

/*
 * ── FAQ ──
 *
 * 每一題的答案都從實際資料算出來，不寫死。AI 引擎會直接引用這些句子，
 * 所以句子本身要能獨立成立（帶平台名與數字），不能是「如上表所示」。
 */

/* 最便宜的付費平台 */
$cheapest = null;
foreach ( $by_price as $p ) {
	$pr = Anime_Sync_Streaming_Registry::pricing( $p['key'] );
	if ( $pr && ( $pr['from'] ?? null ) !== null && (int) $pr['from'] > 0 && $p['count'] > 0 ) {
		$cheapest = [ 'p' => $p, 'pr' => $pr ];
		break;
	}
}

/* 獨家作品最多的平台 */
$most_exclusive = null;
foreach ( array_merge( $groups['tw'], $groups['global'] ) as $p ) {
	$ex = (int) ( $exclusive[ $p['key'] ] ?? 0 );
	if ( $most_exclusive === null || $ex > $most_exclusive['n'] ) {
		$most_exclusive = [ 'p' => $p, 'n' => $ex ];
	}
}

/* 免費平台中作品最多的 */
$best_free = null;
foreach ( array_merge( $groups['tw'], $groups['global'] ) as $p ) {
	$pr = Anime_Sync_Streaming_Registry::pricing( $p['key'] );
	if ( $pr && ( $pr['from'] ?? null ) === 0 && ( $best_free === null || $p['count'] > $best_free['count'] ) ) {
		$best_free = $p;
	}
}

$faq = [
	[
		'q' => '台灣有哪些可以看動畫的串流平台？',
		'a' => sprintf(
			'本站整理了 %d 個動畫串流平台，其中台灣可用的有 %d 個，包含巴哈姆特動畫瘋、Hami Video、MyVideo、friDay 影音、LINE TV、LiTV、Netflix 等。共有 %s 部動畫標註了可觀看的平台。',
			$total_platforms,
			count( $groups['tw'] ),
			number_format( $with_streaming )
		),
	],
	[
		'q' => '哪個動畫串流平台的作品最多？',
		'a' => $top
			? sprintf( '以本站收錄的資料統計，%s 上架的動畫最多，共 %s 部。', $top['label'], number_format( $top['count'] ) )
			: '目前尚無統計資料。',
	],
	[
		'q' => '看動畫最便宜的串流平台是哪個？',
		'a' => $cheapest
			? sprintf(
				'在需要付費的平台中，%s 最低月費 NT$%s 起（%s），本站收錄 %s 部作品。若不介意廣告，巴哈姆特動畫瘋與 Ofiii 歐飛提供免費觀看。價格為 %s 查得之最低方案，實際以官方公告為準。',
				$cheapest['p']['label'],
				number_format( (int) $cheapest['pr']['from'] ),
				$cheapest['pr']['note'],
				number_format( $cheapest['p']['count'] ),
				Anime_Sync_Streaming_Registry::PRICING_UPDATED
			)
			: '目前尚無定價資料。',
	],
	[
		'q' => '有免費看動畫的合法串流平台嗎？',
		'a' => $best_free
			? sprintf(
				'有。%s 收錄的動畫最多，共 %s 部。巴哈姆特動畫瘋提供免費會員觀看（含廣告），付費會員可免廣告並支援更高畫質；Ofiii 歐飛完全免費且免註冊；Ani-One、Muse 木棉花等代理商也在 YouTube 提供官方免費頻道。',
				$best_free['label'],
				number_format( $best_free['count'] )
			)
			: '有，巴哈姆特動畫瘋與 Ofiii 歐飛都提供免費觀看。',
	],
	[
		'q' => '哪個平台的獨家動畫最多？',
		'a' => ( $most_exclusive && $most_exclusive['n'] > 0 )
			? sprintf(
				'以本站資料統計，%s 有 %s 部動畫是其他平台查不到的，數量最多。所謂獨家是指本站收錄的平台中僅該平台有上架，實際授權範圍可能隨時異動。',
				$most_exclusive['p']['label'],
				number_format( $most_exclusive['n'] )
			)
			: '目前尚無統計資料。',
	],
	[
		'q' => '訂閱串流平台就能看到所有動畫嗎？',
		'a' => sprintf(
			'不能。動畫的串流授權多半是分平台、分地區的，同一季新番常散在不同平台。本站 %s 部有平台標註的動畫分布在 %d 個平台上，因此多數人會搭配一個免費平台與一到兩個訂閱平台。',
			number_format( $with_streaming ),
			$total_platforms
		),
	],
];
/*
 * 只輸出 ItemList 與 FAQPage。
 *
 * CollectionPage／WebSite／Organization 由 Rank Math 產生（見頁面上
 * class="rank-math-schema" 那一段），這裡再輸出一次會變成同一頁有兩份
 * 同型別節點——實測 CollectionPage×2、WebSite×2。Google 容忍，但對
 * 解析結構化資料的引擎是雜訊，而且兩份內容若日後不一致更難查。
 * ItemList 與 FAQPage 是 Rank Math 沒有的，才由這裡負責。
 */
$schema = [
	'@context' => 'https://schema.org',
	'@graph'   => [
		[
			'@type'           => 'ItemList',
			'name'            => '台灣動畫串流平台一覽',
			'description'     => sprintf( '整理 %d 個動畫串流平台在台灣的上架情形與最低月費。', $total_platforms ),
			'url'             => Anime_Sync_Streaming_Routing::index_url(),
			'dateModified'    => $updated,
			'numberOfItems'   => count( $item_list ),
			'itemListElement' => $item_list,
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
	],
];
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="asp-st-wrap">

	<header class="asp-st-hero">
		<h1 class="asp-st-title">台灣動畫串流平台一覽</h1>
		<?php /* 開頭直接給數字，讓摘要與 AI 引用能直接抓走 */ ?>
		<p class="asp-st-lead">
			本站整理 <strong><?php echo esc_html( (string) $total_platforms ); ?></strong> 個動畫串流平台，
			其中台灣可用的有 <strong><?php echo esc_html( (string) count( $groups['tw'] ) ); ?></strong> 個。
			目前共 <strong><?php echo esc_html( number_format( $with_streaming ) ); ?></strong> 部動畫標註了可觀看的平台
			（全站收錄 <?php echo esc_html( number_format( $total_works ) ); ?> 部）。
		</p>
		<p class="asp-st-updated">資料更新：<time datetime="<?php echo esc_attr( $updated ); ?>"><?php echo esc_html( $updated ); ?></time></p>
	</header>

	<?php
	foreach ( [ 'tw' => '台灣可用平台', 'global' => '國際平台' ] as $gk => $gtitle ) :
		if ( empty( $groups[ $gk ] ) ) {
			continue;
		}
		?>
		<section class="asp-st-section">
			<h2 class="asp-st-h2"><?php echo esc_html( $gtitle ); ?></h2>
			<div class="asp-st-grid">
				<?php foreach ( $groups[ $gk ] as $p ) :
					$has_page = Anime_Sync_Streaming_Routing::has_own_page( $p['key'] );
					$icon     = Anime_Sync_Streaming_Routing::icon_url( $p['icon'] ?? '' );
					$tag      = $has_page ? 'a' : 'div';
					?>
					<<?php echo $tag; ?> class="asp-st-card<?php echo $has_page ? '' : ' is-flat'; ?>"
						<?php if ( $has_page ) : ?>href="<?php echo esc_url( Anime_Sync_Streaming_Routing::platform_url( $p['key'] ) ); ?>"<?php endif; ?>
						style="--asp-st-color: <?php echo esc_attr( $p['color'] ?? '#666' ); ?>">
						<?php if ( $icon ) : ?>
							<img class="asp-st-icon" src="<?php echo esc_url( $icon ); ?>" alt=""
								loading="lazy" decoding="async" width="48" height="48">
						<?php else : ?>
							<span class="asp-st-icon asp-st-icon--none" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="asp-st-name"><?php echo esc_html( $p['label'] ); ?></span>
						<span class="asp-st-meta">
							<span class="asp-st-billing"><?php echo esc_html( $billing_label[ $p['billing'] ?? 'sub' ] ?? '訂閱制' ); ?></span>
							<span class="asp-st-count"><?php echo esc_html( number_format( $p['count'] ) ); ?> 部</span>
						</span>
					</<?php echo $tag; ?>>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endforeach; ?>

	<?php
	/*
	 * 比較表：表格格式最容易被搜尋引擎取為精選摘要，也最容易被 AI 引擎解析。
	 *
	 * 排序改成「由便宜到貴」而不是「作品數多寡」——讀者來這頁是要決定訂哪家，
	 * 價格才是第一決策因素。
	 *
	 * 欄位的取捨：
	 *   月費起   決策核心
	 *   獨家     唯一真正差異化的目錄指標（Bilibili 244、多數平台 0）
	 *   收錄數   規模參考
	 *   方案     官方連結，價格有疑義時讀者自己查，也避免我們的數字過期誤導
	 *
	 * 刻意沒放「平均評分」與「熱門代表作」：實測前者全站擠在 73~77 分、
	 * 後者每個平台都是進擊的巨人／鬼滅之刃，那是湊欄位不是比較。
	 */
	?>
	<section class="asp-st-section">
		<h2 class="asp-st-h2">平台比較</h2>
		<div class="asp-st-tablewrap">
			<table class="asp-st-table">
				<caption class="asp-st-caption">
					依最低月費排序。價格為 <?php echo esc_html( Anime_Sync_Streaming_Registry::PRICING_UPDATED ); ?> 查得之最低方案，實際以官方公告為準。
				</caption>
				<thead>
					<tr>
						<th scope="col">平台</th>
						<th scope="col">月費起</th>
						<th scope="col">地區</th>
						<th scope="col">收錄動畫數</th>
						<th scope="col">獨家</th>
						<th scope="col">方案</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $by_price as $p ) :
					if ( $p['count'] <= 0 ) { continue; }
					$pr = Anime_Sync_Streaming_Registry::pricing( $p['key'] );
					$ex = (int) ( $exclusive[ $p['key'] ] ?? 0 );
					?>
					<tr>
						<th scope="row">
							<?php if ( Anime_Sync_Streaming_Routing::has_own_page( $p['key'] ) ) : ?>
								<a href="<?php echo esc_url( Anime_Sync_Streaming_Routing::platform_url( $p['key'] ) ); ?>"><?php echo esc_html( $p['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $p['label'] ); ?>
							<?php endif; ?>
						</th>
						<td class="asp-st-price">
							<?php echo esc_html( $price_text( $pr ) ); ?>
							<?php if ( ! empty( $pr['note'] ) ) : ?>
								<span class="asp-st-pricenote"><?php echo esc_html( $pr['note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo empty( $p['global'] ) ? '台灣' : '國際'; ?></td>
						<td class="asp-st-num"><?php echo esc_html( number_format( $p['count'] ) ); ?></td>
						<td class="asp-st-num"><?php echo $ex > 0 ? esc_html( number_format( $ex ) ) : '—'; ?></td>
						<td>
							<?php if ( ! empty( $pr['url'] ) ) : ?>
								<a href="<?php echo esc_url( $pr['url'] ); ?>" target="_blank" rel="nofollow noopener">官方方案</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="asp-st-section asp-st-faq">
		<h2 class="asp-st-h2">常見問題</h2>
		<?php foreach ( $faq as $x ) : ?>
			<details class="asp-st-faqitem">
				<summary><?php echo esc_html( $x['q'] ); ?></summary>
				<p><?php echo esc_html( $x['a'] ); ?></p>
			</details>
		<?php endforeach; ?>
	</section>

	<p class="asp-st-disclaimer">
		上架情形以各平台公告為準，本站資料僅供參考。部分平台同時提供訂閱與單次租看，表格標示的是主要方式。
	</p>
</div>

<style>
.asp-st-wrap { max-width: 1120px; margin: 0 auto; padding: 28px 16px 64px; }
.asp-st-hero { margin-bottom: 32px; }
.asp-st-title { font-size: 30px; font-weight: 800; margin: 0 0 12px; letter-spacing: .02em; }
.asp-st-lead { font-size: 16px; line-height: 1.9; margin: 0 0 8px; max-width: 62ch; }
.asp-st-lead strong { font-weight: 700; }
.asp-st-updated { font-size: 13px; opacity: .6; margin: 0; }
.asp-st-section { margin-top: 40px; }
.asp-st-h2 { font-size: 20px; font-weight: 700; margin: 0 0 16px; padding-left: 10px; border-left: 4px solid currentColor; }
.asp-st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 14px; }
.asp-st-card { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 18px 12px;
    border-radius: 14px; text-decoration: none; color: inherit;
    background: rgba(127,127,127,.08); border: 1px solid rgba(127,127,127,.16);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }
a.asp-st-card:hover { transform: translateY(-3px); border-color: var(--asp-st-color);
    box-shadow: 0 6px 18px rgba(0,0,0,.14); }
.asp-st-card.is-flat { opacity: .72; }
.asp-st-icon { width: 48px; height: 48px; border-radius: 10px; object-fit: contain; background: #fff; }
.asp-st-icon--none { display: block; background: var(--asp-st-color); }
.asp-st-name { font-size: 14px; font-weight: 700; text-align: center; line-height: 1.4; }
.asp-st-meta { display: flex; gap: 8px; font-size: 12px; opacity: .75; }
.asp-st-billing { padding: 1px 7px; border-radius: 999px; background: var(--asp-st-color); color: #fff; }
.asp-st-tablewrap { overflow-x: auto; }
.asp-st-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.asp-st-table th, .asp-st-table td { padding: 10px 12px; text-align: left;
    border-bottom: 1px solid rgba(127,127,127,.18); }
.asp-st-table thead th { font-weight: 700; white-space: nowrap; }
.asp-st-num { text-align: right; font-variant-numeric: tabular-nums; }
/* 表格說明：資料查證月份與但書，放 caption 而不是表格外的段落，
   讓「這張表的價格是什麼時候的」與表格本身綁在一起，摘錄時不會脫節 */
.asp-st-caption { caption-side: bottom; margin-top: 10px; font-size: 12.5px;
    opacity: .62; line-height: 1.7; text-align: left; }
.asp-st-price { white-space: nowrap; font-variant-numeric: tabular-nums; }
/* 方案但書：跟價格同格但降階，手機上換行不擠壓主要數字 */
.asp-st-pricenote { display: block; margin-top: 3px; font-size: 11.5px;
    opacity: .58; white-space: normal; line-height: 1.55; max-width: 22em; }
.asp-st-faqitem { padding: 12px 0; border-bottom: 1px solid rgba(127,127,127,.18); }
.asp-st-faqitem summary { cursor: pointer; font-weight: 600; }
.asp-st-faqitem p { margin: 10px 0 0; line-height: 1.85; opacity: .88; }
.asp-st-disclaimer { margin-top: 36px; font-size: 12.5px; opacity: .6; line-height: 1.8; }
@media screen and (max-width: 600px) {
    .asp-st-title { font-size: 23px; }
    .asp-st-grid { grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 10px; }
}
</style>

<?php get_footer(); ?>
