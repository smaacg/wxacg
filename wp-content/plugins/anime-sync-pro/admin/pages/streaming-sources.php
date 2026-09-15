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
		// 結果日誌的標籤有 [排程]／[後台]／[手動] 三種，都以「：[」開頭；只認 [排程] 會漏掉手動寫入
		'last'     => $wpdb->get_row( $wpdb->prepare(
			"SELECT created_at, message FROM {$log_table} WHERE message LIKE %s ORDER BY id DESC LIMIT 1",
			'%串流來源[' . $key . ']：[%'
		), ARRAY_A ),
		'running'  => $src->is_running(),
		'end'      => $src->end_stats(),
		'bundle'   => ( $key === 'bahamut' && class_exists( 'Anime_Sync_Streaming_Source_Bahamut' ) ) ? ( static function (): ?array {
			$path = Anime_Sync_Streaming_Source_Bahamut::bundle_path();
			if ( ! is_readable( $path ) ) {
				return null;
			}
			$data = json_decode( (string) file_get_contents( $path ), true );
			return [
				'mtime' => (int) filemtime( $path ),
				'works' => is_array( $data['works'] ?? null ) ? count( $data['works'] ) : 0,
				'acg'   => is_array( $data['acg'] ?? null ) ? count( $data['acg'] ) : 0,
			];
		} )() : null,
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

	<?php if ( class_exists( 'Anime_Sync_Bangumi_Data_Feed' ) ) : $bd = Anime_Sync_Bangumi_Data_Feed::cache_info(); ?>
		<p class="ass-lead">
			<strong>bangumi-data ID 對照表</strong>（<a href="https://github.com/bangumi-data/bangumi-data" target="_blank" rel="noopener noreferrer">開源資料集</a>，用 Bangumi／AniList ID 對應各站，不比標題）：
			<?php if ( $bd['exists'] ) : ?>
				<?php echo esc_html( wp_date( 'Y-m-d H:i', $bd['updated'] ) ); ?> 更新，
				<?php
				$parts = [];
				foreach ( [ 'gamer' => '動畫瘋', 'muse_tw' => '木棉花', 'ani_one' => 'Ani-One', 'ani_one_asia' => 'Ani-One Asia', 'bilibili_tw' => 'Bilibili', 'tropics' => '回歸線', 'mighty' => '曼迪' ] as $site => $name ) {
					if ( ! empty( $bd['sites'][ $site ] ) ) {
						$parts[] = $name . ' ' . number_format_i18n( (int) $bd['sites'][ $site ] );
					}
				}
				echo esc_html( implode( '、', $parts ) );
				?>
				部（6 天快取，各來源重建索引時共用）。
			<?php else : ?>
				<span class="ass-muted">尚未下載，第一個來源重建索引時會抓（7.6MB）。</span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( ! $write_on ) : ?>
		<div class="notice notice-warning inline"><p>
			排程寫入目前關閉：排程只更新索引、不寫入作品。開啟：<code>wp option update anime_sync_streaming_source_write 1</code>
		</p></div>
	<?php endif; ?>

	<?php
	// 「立即執行」按鈕按完的回饋（admin-post 轉回來帶的參數）
	$asp_run  = sanitize_key( (string) ( $_GET['asp_run'] ?? '' ) );
	$asp_src  = sanitize_key( (string) ( $_GET['asp_src'] ?? '' ) );
	$asp_mode = sanitize_key( (string) ( $_GET['asp_mode'] ?? '' ) );
	if ( $asp_run === 'started' ) : ?>
		<div class="notice notice-success inline"><p>
			已開始 <code><?php echo esc_html( $asp_src ); ?></code> 的<?php echo $asp_mode === 'write' ? '重建索引並寫入' : '重建索引（dry-run，不寫入）'; ?>，
			在背景執行中。小來源幾秒、大來源（LiTV、MyVideo）約 1～3 分鐘，之後重新整理本頁看「上次執行結果」。
			<?php if ( $asp_mode !== 'write' && ! $write_on ) : ?>（排程寫入開關關著，寫入按鈕已降為 dry-run）<?php endif; ?>
		</p></div>
	<?php elseif ( $asp_run === 'running' ) : ?>
		<div class="notice notice-info inline"><p><code><?php echo esc_html( $asp_src ); ?></code> 正在執行中（排程或前一次按鈕），跑完再按。</p></div>
	<?php elseif ( $asp_run === 'bad' ) : ?>
		<div class="notice notice-error inline"><p>不認得的來源 key。</p></div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped ass-table">
		<thead>
			<tr>
				<th style="width:15%">平台</th>
				<th style="width:20%">索引</th>
				<th style="width:11%">下次排程</th>
				<th style="width:8%">已寫入</th>
				<th>上次執行結果</th>
				<th style="width:13%">立即執行</th>
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
					<?php if ( ! empty( $r['end']['checked'] ) ) : ?>
						<br><small class="ass-muted" title="平台作品頁公告的授權到期日；到期後覆核，真下架才移除">
							到期日：查過 <?php echo esc_html( number_format_i18n( $r['end']['checked'] ) ); ?>、有日期 <?php echo esc_html( number_format_i18n( $r['end']['known'] ) ); ?>、30 天內 <strong><?php echo esc_html( number_format_i18n( $r['end']['soon'] ) ); ?></strong><?php if ( $r['end']['expired'] > 0 ) : ?>、已過期待覆核 <?php echo esc_html( number_format_i18n( $r['end']['expired'] ) ); ?><?php endif; ?>
						</small>
					<?php endif; ?>
					<?php if ( $r['key'] === 'bahamut' ) : ?>
						<?php /* 巴哈唯一的失效模式是「本機週日建包沒跑／沒 push」，索引包日期要看得到 */ ?>
						<br><small class="<?php echo ( ! $r['bundle'] || time() - $r['bundle']['mtime'] > 10 * DAY_IN_SECONDS ) ? 'ass-err' : 'ass-muted'; ?>">
							<?php if ( $r['bundle'] ) : ?>
								索引包 <?php echo esc_html( wp_date( 'm/d', $r['bundle']['mtime'] ) ); ?> 建包・<?php echo esc_html( number_format_i18n( $r['bundle']['works'] ) ); ?> 部・ACG 對照 <?php echo esc_html( number_format_i18n( $r['bundle']['acg'] ) ); ?> 筆<?php echo ( time() - $r['bundle']['mtime'] > 10 * DAY_IN_SECONDS ) ? '（超過 10 天，本機週日排程可能沒跑或沒 push）' : ''; ?>
							<?php else : ?>
								找不到索引包 data/source_bahamut_bundle.json
							<?php endif; ?>
						</small>
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
						<?php
						// 「串流來源[key]：[排程] 索引…」→ 標籤保留讓人看得出是排程還是手動，前綴去掉
						echo esc_html( preg_replace( '/^串流來源\[[^\]]+\]：/u', '', (string) $r['last']['message'] ) );
						?>
					<?php else : ?>
						<span class="ass-muted">尚未執行過</span>
					<?php endif; ?>
					<?php if ( ! empty( $r['warn'] ) ) : ?>
						<br><span class="ass-err">⚠ <?php echo esc_html( $r['warn']['created_at'] ); ?>：<?php echo esc_html( preg_replace( '/^串流來源\[[^\]]+\]：/u', '', (string) $r['warn']['message'] ) ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $r['running'] ) : ?>
						<span class="ass-muted">⏳ 執行中，重新整理看結果</span>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ass-run">
							<?php wp_nonce_field( 'anime_sync_streaming_source_run' ); ?>
							<input type="hidden" name="action" value="anime_sync_streaming_source_run">
							<input type="hidden" name="source" value="<?php echo esc_attr( $r['key'] ); ?>">
							<button type="submit" name="mode" value="dry" class="button button-small" title="重建索引並比對，不寫入">重建・dry-run</button>
							<button type="submit" name="mode" value="write" class="button button-small button-primary" title="重建索引、比對並寫入（受排程寫入開關管）"<?php echo $write_on ? '' : ' disabled'; ?>>重建・寫入</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	/*
	 * 疑似下架（下架偵測的產出）。
	 *   自動：我們寫的、連續配不到的，有 _anime_tw_streaming_gone_{key}「日期|第幾輪」；滿 3 輪自動移除。
	 *   人工：YA／人工寫的、這輪索引配不到的，只列出來給人判斷，程式不動它——譯名差異就會配不到。
	 * 人工那份要逐部查索引，結果快取 1 小時。
	 */
	$gone_rows = $wpdb->get_results(
		"SELECT g.post_id, g.meta_key, g.meta_value, p.post_title
		   FROM {$wpdb->postmeta} g JOIN {$wpdb->posts} p ON p.ID = g.post_id
		  WHERE g.meta_key LIKE '_anime_tw_streaming_gone_%'
		  ORDER BY g.meta_value DESC LIMIT 100",
		ARRAY_A
	);

	/*
	 * 曾經在這裡多列一份「YA／人工寫的網址在索引裡配不到」的人工確認清單。
	 * 2026-09-15 拿掉：那批本質上就是標題比對配不到的 26%（譯名差異），幾百筆全是噪音，
	 * 而且連結進編輯器對判斷「還在不在平台上」沒幫助。等接了 bangumi-data 用 Bangumi ID
	 * 對應後，「站上有網址、資料集裡該站沒有」才是精確的人工確認訊號，那時再放回來。
	 */
	?>
	<h2 class="ass-h2">疑似下架</h2>
	<p class="ass-lead">
		直接來源寫入過、這輪平台索引配不到的作品。前台已標「可能已下架」；連續 3 輪未出現自動移除。
		點「平台網址」直接到該平台確認還在不在。
	</p>
	<table class="wp-list-table widefat fixed striped ass-table">
		<thead><tr><th style="width:12%">平台</th><th style="width:34%">作品</th><th>平台網址</th><th style="width:18%">狀態</th></tr></thead>
		<tbody>
		<?php if ( $gone_rows ) : foreach ( $gone_rows as $g ) :
			$gk  = str_replace( '_anime_tw_streaming_gone_', '', $g['meta_key'] );
			$gu  = (string) get_post_meta( (int) $g['post_id'], 'anime_tw_streaming_url_' . $gk, true );
			[ $gd, $gn ] = array_pad( explode( '|', (string) $g['meta_value'] ), 2, '1' ); ?>
			<tr>
				<td><code><?php echo esc_html( $gk ); ?></code></td>
				<td>
					<a href="<?php echo esc_url( get_permalink( (int) $g['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $g['post_title'] ); ?></a>
					<br><small>#<?php echo esc_html( $g['post_id'] ); ?> · <a href="<?php echo esc_url( get_edit_post_link( (int) $g['post_id'] ) ); ?>">編輯</a></small>
				</td>
				<td class="ass-msg"><?php if ( $gu !== '' ) : ?><a href="<?php echo esc_url( $gu ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $gu ); ?></a><?php else : ?><span class="ass-muted">（無）</span><?php endif; ?></td>
				<td><span class="ass-err">第 <?php echo esc_html( $gn ); ?>/3 輪</span><br><small><?php echo esc_html( $gd ); ?> 起配不到</small></td>
			</tr>
		<?php endforeach; else : ?>
			<tr><td colspan="4" class="ass-muted">目前沒有疑似下架的作品</td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<h2 class="ass-h2">沒接直接來源的平台</h2>
	<table class="wp-list-table widefat fixed ass-table ass-table--compact">
		<tbody>
			<tr><th>Netflix、Crunchyroll、Disney+、Apple TV+、Prime、愛奇藝、HIDIVE、Hulu</th><td>國際平台，來源是 AniList externalLinks，匯入時寫入，本來就不經 YourAnimes；Netflix 另有 YourAnimes 補。bangumi-data 的 netflix 站點不標地區（全球），不能拿來當台灣依據，2026-09-15 試過已撤回。（Bilibili 台灣已改由上表的 bangumi-data ID 對應接上）</td></tr>
			<tr><th>Hami Video</th><td>robots.txt 對所有 UA 全站 Disallow，尊重對方，不抓。靠 YourAnimes。</td></tr>
			<tr><th>CatchPlay+</th><td>作品頁是純前端渲染的空殼、無公開資料介面。靠 YourAnimes。</td></tr>
			<tr><th>renta!、車庫娛樂、公視、AniPASS</th><td>收錄數少；車庫回 403、公視 robots 全站 Disallow。靠 YourAnimes。</td></tr>
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
		上表「立即執行」按鈕會另起一個背景程序跑等同排程的那一輪（日誌標 [後台]）；命令列：<code>wp anime streaming-source --platform=&lt;key&gt; --dry-run</code>（不寫入）、<code>--write</code>（寫入，日誌標 [手動]）、<code>--status</code>。
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
.ass-run { display:flex; flex-direction:column; gap:4px; }
.ass-run .button { text-align:center; }
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
