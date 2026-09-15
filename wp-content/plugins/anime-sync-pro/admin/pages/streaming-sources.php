<?php
/**
 * 串流來源狀態頁
 *
 * 台灣串流平台「直接抓取」六家（巴哈、MyVideo、Ofiii、LiTV、friDay、LINE TV）的
 * 索引狀態、爬取進度、下次排程、帶來源標記的已寫入筆數、上次排程結果。
 *
 * 純讀取：刻意不放「立即執行」按鈕。LiTV 一輪要抓 90MB、LINE TV 一批 300 頁，
 * 從瀏覽器觸發會逾時。執行走排程或 WP-CLI：
 *     wp anime streaming-source --platform=<key> --dry-run
 *     wp anime streaming-source --platform=<key> --write
 *
 * 原本是儀表板的一個區塊（1.4.0），2026-09-15 應使用者要求獨立成頁。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( '權限不足' );
}

if ( ! class_exists( 'Anime_Sync_Streaming_Source_Base' ) ) {
	echo '<div class="wrap"><h1>📡 串流來源</h1><p>找不到 Anime_Sync_Streaming_Source_Base，外掛未完整載入。</p></div>';
	return;
}

global $wpdb;

$write_on  = (string) get_option( Anime_Sync_Streaming_Source_Base::WRITE_OPTION, '0' ) === '1';
$log_table = $wpdb->prefix . 'anime_sync_logs';
$rows      = [];

foreach ( Anime_Sync_Streaming_Source_Base::available_keys() as $key ) {
	$src = Anime_Sync_Streaming_Source_Base::make( $key );
	if ( ! $src ) {
		continue;
	}
	$state  = $src->status();
	$rows[] = [
		'key'      => $key,
		'label'    => $src->label(),
		'built'    => (int) ( $state['built'] ?? 0 ),
		'works'    => (int) ( $state['works'] ?? 0 ),
		'entries'  => (int) ( $state['entries'] ?? 0 ),
		'progress' => method_exists( $src, 'progress' ) ? $src->progress() : null,
		'next'     => (int) wp_next_scheduled( $src->hook() ),
		'circuit'  => $src->is_circuit_open(),
		'written'  => (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_anime_tw_streaming_src_' . $key
		) ),
		'last'     => $wpdb->get_row( $wpdb->prepare(
			"SELECT created_at, message FROM {$log_table} WHERE message LIKE %s ORDER BY id DESC LIMIT 1",
			'%串流來源[' . $key . ']：[排程]%'
		), ARRAY_A ),
		'warn'     => $wpdb->get_row( $wpdb->prepare(
			"SELECT created_at, message FROM {$log_table} WHERE level IN ('warning','error','critical') AND message LIKE %s ORDER BY id DESC LIMIT 1",
			'%串流來源[' . $key . ']%'
		), ARRAY_A ),
	];
}

$total_written = array_sum( array_column( $rows, 'written' ) );

$recent = $wpdb->get_results( $wpdb->prepare(
	"SELECT created_at, level, message FROM {$log_table} WHERE message LIKE %s ORDER BY id DESC LIMIT %d",
	'%串流來源%', 20
), ARRAY_A );
?>
<div class="wrap ass-wrap">
	<h1>📡 串流來源</h1>
	<p class="ass-lead">
		台灣串流平台的直接抓取來源，與 YourAnimes 並行、互不覆蓋。每筆自動寫入都帶來源標記
		<code>_anime_tw_streaming_src_{平台}</code>，可整批還原。
	</p>

	<div class="ass-summary">
		<div class="ass-pill">
			<span class="ass-pill-num"><?php echo esc_html( number_format_i18n( $total_written ) ); ?></span>
			<span class="ass-pill-label">直接來源已寫入</span>
		</div>
		<div class="ass-pill">
			<span class="ass-pill-num"><?php echo esc_html( count( $rows ) ); ?></span>
			<span class="ass-pill-label">已接平台</span>
		</div>
		<div class="ass-pill <?php echo $write_on ? 'ass-pill--ok' : 'ass-pill--off'; ?>">
			<span class="ass-pill-num"><?php echo $write_on ? '開' : '關'; ?></span>
			<span class="ass-pill-label">排程寫入</span>
		</div>
	</div>

	<?php if ( ! $write_on ) : ?>
		<div class="notice notice-warning inline"><p>
			排程寫入目前關閉：排程只更新索引、不寫入作品。開啟：<code>wp option update anime_sync_streaming_source_write 1</code>
		</p></div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped ass-table">
		<thead>
			<tr>
				<th style="width:16%">平台</th>
				<th style="width:20%">索引</th>
				<th style="width:12%">下次排程</th>
				<th style="width:9%">已寫入</th>
				<th>上次排程結果</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $r ) : ?>
			<tr>
				<td>
					<strong><?php echo esc_html( $r['label'] ); ?></strong><br>
					<code><?php echo esc_html( $r['key'] ); ?></code>
					<?php if ( $r['circuit'] ) : ?>
						<br><span class="ass-err">⛔ 熔斷中（連續失敗，暫停 1 小時）</span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $r['built'] > 0 ) : ?>
						<strong><?php echo esc_html( number_format_i18n( $r['works'] ) ); ?></strong> 部作品
						<?php if ( $r['entries'] > $r['works'] ) : ?>
							<small>（<?php echo esc_html( number_format_i18n( $r['entries'] ) ); ?> 條目）</small>
						<?php endif; ?>
						<br><small><?php echo esc_html( wp_date( 'Y/m/d H:i', $r['built'] ) ); ?> 建立</small>
					<?php else : ?>
						<span class="ass-err">尚未建立</span>
					<?php endif; ?>
					<?php if ( is_array( $r['progress'] ) && $r['progress']['total'] > 0 ) :
						$pct = (int) round( $r['progress']['done'] / max( 1, $r['progress']['total'] ) * 100 ); ?>
						<div class="ass-bar" title="已抓 <?php echo esc_attr( $r['progress']['done'] ); ?>／共 <?php echo esc_attr( $r['progress']['total'] ); ?>">
							<span style="width:<?php echo esc_attr( $pct ); ?>%"></span>
						</div>
						<small>爬取 <?php echo esc_html( number_format_i18n( $r['progress']['done'] ) ); ?> / <?php echo esc_html( number_format_i18n( $r['progress']['total'] ) ); ?>（<?php echo esc_html( $pct ); ?>%，待 <?php echo esc_html( number_format_i18n( $r['progress']['pending'] ) ); ?>）</small>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $r['next'] > 0 ) : ?>
						<?php echo esc_html( wp_date( 'm/d H:i', $r['next'] ) ); ?><br>
						<small><?php echo esc_html( human_time_diff( time(), $r['next'] ) ); ?>後</small>
					<?php else : ?>
						<span class="ass-err">未排程</span>
					<?php endif; ?>
				</td>
				<td><strong><?php echo esc_html( number_format_i18n( $r['written'] ) ); ?></strong> 部</td>
				<td class="ass-msg">
					<?php if ( ! empty( $r['last'] ) ) : ?>
						<small><?php echo esc_html( $r['last']['created_at'] ); ?></small><br>
						<?php echo esc_html( preg_replace( '/^.*\[排程\]\s*/u', '', (string) $r['last']['message'] ) ); ?>
					<?php else : ?>
						<span class="ass-muted">尚未執行過排程</span>
					<?php endif; ?>
					<?php if ( ! empty( $r['warn'] ) ) : ?>
						<br><span class="ass-err">⚠ <?php echo esc_html( $r['warn']['created_at'] ); ?>：<?php echo esc_html( preg_replace( '/^串流來源\[[^\]]+\]：/u', '', (string) $r['warn']['message'] ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2 class="ass-h2">沒接直接來源的平台</h2>
	<table class="wp-list-table widefat fixed ass-table ass-table--compact">
		<tbody>
			<tr><th>Netflix、Crunchyroll、Disney+、Apple TV+、Prime、Bilibili、愛奇藝、HIDIVE、Hulu</th><td>國際平台，來源是 AniList externalLinks，匯入時寫入，本來就不經 YourAnimes。</td></tr>
			<tr><th>Hami Video</th><td>robots.txt 對所有 UA 全站 Disallow，尊重對方，不抓。靠 YourAnimes。</td></tr>
			<tr><th>CatchPlay+</th><td>作品頁是純前端渲染的空殼、無公開資料介面。靠 YourAnimes。</td></tr>
			<tr><th>Ani-One、木棉花、曼迪、回歸線、Ani-Mi、It's Anime、YouTube</th><td>YouTube 頻道；目前由 YouTube 播放清單同步與 YourAnimes 補。可用 YouTube Data API 列各頻道播放清單另接直接來源（尚未做）。</td></tr>
			<tr><th>renta!、車庫娛樂、公視、AniPASS</th><td>收錄數少，尚未探測。</td></tr>
		</tbody>
	</table>

	<h2 class="ass-h2">最近 20 筆相關日誌</h2>
	<table class="wp-list-table widefat fixed striped ass-table">
		<thead><tr><th style="width:14%">時間</th><th style="width:8%">等級</th><th>訊息</th></tr></thead>
		<tbody>
		<?php if ( $recent ) : foreach ( $recent as $l ) : ?>
			<tr>
				<td><small><?php echo esc_html( $l['created_at'] ); ?></small></td>
				<td><span class="ass-lv ass-lv--<?php echo esc_attr( $l['level'] ); ?>"><?php echo esc_html( strtoupper( $l['level'] ) ); ?></span></td>
				<td class="ass-msg"><?php echo esc_html( $l['message'] ); ?></td>
			</tr>
		<?php endforeach; else : ?>
			<tr><td colspan="3" class="ass-muted">尚無紀錄</td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<p class="ass-foot">
		巴哈由本機（台灣 IP）每週建索引包隨部署更新，主機被 Cloudflare 人機驗證擋。
		手動執行：<code>wp anime streaming-source --platform=&lt;key&gt; --dry-run</code>（不寫入）、<code>--write</code>（寫入）、<code>--status</code>。
	</p>
</div>

<style>
.ass-wrap .ass-lead { color:#555; margin:4px 0 14px; }
.ass-summary { display:flex; gap:12px; flex-wrap:wrap; margin:0 0 14px; }
.ass-pill { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:10px 16px; min-width:120px; }
.ass-pill-num { display:block; font-size:22px; font-weight:700; line-height:1.1; }
.ass-pill-label { display:block; font-size:12px; color:#666; margin-top:2px; }
.ass-pill--ok .ass-pill-num { color:#1a7f37; }
.ass-pill--off .ass-pill-num { color:#b32d2e; }
.ass-table td, .ass-table th { vertical-align:top; }
.ass-table--compact th { width:36%; font-weight:600; }
.ass-h2 { margin:22px 0 8px; font-size:15px; }
.ass-err { color:#b32d2e; }
.ass-muted { color:#888; }
.ass-msg { word-break:break-all; }
.ass-bar { height:6px; background:#e5e5e5; border-radius:3px; overflow:hidden; margin:6px 0 2px; }
.ass-bar span { display:block; height:100%; background:#2271b1; }
.ass-lv { display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; font-weight:600; background:#f0f0f1; color:#555; }
.ass-lv--warning { background:#fcf3d9; color:#8a6100; }
.ass-lv--error, .ass-lv--critical { background:#fbe9e7; color:#b32d2e; }
.ass-foot { color:#666; font-size:12px; margin-top:16px; }
@media (max-width:782px){ .ass-table thead { display:none; } .ass-table td { display:block; width:auto !important; } }
</style>
