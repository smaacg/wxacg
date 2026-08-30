<?php
/**
 * 相關專輯清單（Bangumi 關聯）。
 *
 * 由 single-anime.php 在音樂子頁（/anime/{slug}/music/）include。
 * 主題曲不在這裡——那個留在動畫頁，見 anime-music-themes.php。
 *
 * 需要 include 端備妥：$rel_albums
 *（總數 $rel_albums_total 現在由 include 端自己印在區塊標題上，這裡不用）
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rel_albums = isset( $rel_albums ) && is_array( $rel_albums ) ? $rel_albums : [];

/*
 * 數量少就攤平成單一面封面牆，不分組。
 *
 * 分組在數量少時會把牆切碎：7 張分成 1/2/3/1 四組，每組都填不滿一行，
 * 右邊各空一大截，看起來是四排零星小圖。實測正式站 80% 的作品專輯
 * 總數不到 6 張，這是常態不是特例。門檻與理由見 repository 的 FLAT_MAX。
 *
 * 攤平後類型改由每張自己標（$album['group']），資訊不會少——反而不必
 * 回頭看上面的組標題。
 */
$album_flat = [];

foreach ( $rel_albums as $album_group ) {
	$album_flat = array_merge( $album_flat, $album_group['items'] );
}

$album_is_grouped = class_exists( 'Anime_Sync_Subject_Relations_Repository' )
	&& count( $album_flat ) > Anime_Sync_Subject_Relations_Repository::FLAT_MAX;

/* 攤平時包成一個沒有標題的區段，下面的迴圈兩種模式共用 */
$album_sections = $album_is_grouped
	? $rel_albums
	: [ [ 'label' => '', 'count' => count( $album_flat ), 'items' => $album_flat ] ];
?>
<div class="asd-album-block asd-album-block--standalone">
									<?php
									/*
									 * 這裡不再放「相關專輯」標題。
									 *
									 * 它是子頁時代的產物——當時專輯跟主題曲播放器同在一個
									 * 區塊裡，需要標題把兩者分開。改成獨立分頁後上面已經有
									 * 區塊標題（h2）寫著同樣四個字，連著出現兩次。
									 * 總數徽章移到那個 h2 旁邊。
									 */
									?>
									<?php /* 不收合：專輯是獨立 tab，點進來就是要看全部 */ ?>

									<div class="asd-album-groups">
										<?php foreach ( $album_sections as $album_group ) : ?>
											<div class="asd-album-group">
												<?php /* 攤平模式沒有組標題——上面的 h2 已經寫了「相關專輯 7」 */ ?>
												<?php if ( '' !== $album_group['label'] ) : ?>
													<h4 class="asd-album-group-title">
														<?php echo esc_html( $album_group['label'] ); ?>
														<span class="asd-album-count"><?php echo esc_html( (string) $album_group['count'] ); ?></span>
													</h4>
												<?php endif; ?>

												<ul class="asd-album-list">
													<?php foreach ( $album_group['items'] as $album ) : ?>
														<li class="asd-album-item">
															<?php
															/*
															 * 封面也要能點開彈窗——它是這張卡最大的目標，
															 * 使用者的直覺是點圖不是點字。
															 *
															 * 做法是把 data-bgm-id 也放到封面上，前端那支委派
															 * 處理器認的就是這個屬性（見 initAlbumModal），
															 * 不必為封面另綁一組事件。
															 *
															 * 站內有對應文章時不給這個屬性——那種情況名稱是
															 * <a>，點封面應該跟著連過去而不是開彈窗。
															 * （目前全站 local_post_id 都是 0，實務上碰不到。）
															 */
															$album_modal_id = ( '' === $album['url'] && $album['bgm_id'] > 0 )
																? (string) $album['bgm_id']
																: '';
															?>
															<?php
															/*
															 * 封面。網址由回補 cron 寫進 cover_url，圖片本身仍由
															 * Bangumi CDN 送（本站不存檔，理由見回補類別的檔頭）。
															 *
															 * width/height 給 400（來源就是 /r/400/）只為了讓瀏覽器
															 * 在 CSS 生效前就知道是 1:1，避免載入時整排跳動；
															 * 實際尺寸由 .asd-album-thumb 的 aspect-ratio 決定。
															 *
															 * alt 留空：名稱就在圖片正下方，讀螢幕軟體再念一次
															 * 同樣的字是雜訊。
															 */
															?>
															<?php if ( ! empty( $album['cover'] ) ) : ?>
																<img
																	class="asd-album-thumb<?php echo '' !== $album_modal_id ? ' is-clickable' : ''; ?>"
																	src="<?php echo esc_url( $album['cover'] ); ?>"
																	alt=""
																	loading="lazy"
																	decoding="async"
																	width="400"
																	height="400"
																	<?php if ( '' !== $album_modal_id ) : ?>
																		data-bgm-id="<?php echo esc_attr( $album_modal_id ); ?>"
																	<?php endif; ?>
																>
															<?php else : ?>
																<span
																	class="asd-album-thumb asd-album-thumb--empty<?php echo '' !== $album_modal_id ? ' is-clickable' : ''; ?>"
																	<?php if ( '' !== $album_modal_id ) : ?>
																		data-bgm-id="<?php echo esc_attr( $album_modal_id ); ?>"
																	<?php endif; ?>
																	aria-hidden="true"
																>🎵</span>
															<?php endif; ?>

															<span class="asd-album-main">
															<?php if ( '' !== $album['url'] ) : ?>
																<?php /* 站內有這張專輯的文章就直接連過去，優先於彈窗 */ ?>
																<a
																	class="asd-album-name"
																	href="<?php echo esc_url( $album['url'] ); ?>"
																	title="<?php echo esc_attr( $album['title'] ); ?>"
																>
																	<?php echo esc_html( $album['title'] ); ?>
																</a>
															<?php elseif ( $album['bgm_id'] > 0 ) : ?>
																<?php
																/*
																 * 站內沒有對應文章時，點名稱開彈窗顯示封面／藝術家／
																 * 發售日（向 Bangumi 取，走後端代理避開 CORS）。
																 *
																 * 用 <button> 不用 <a href="https://bgm.tv/...">：
																 * 這是「在本頁展開資料」不是「離開本站」，
																 * 語意上是動作不是導覽。JS 壞掉時它就只是個沒反應的
																 * 按鈕，不會把人送去外站。
																 */
																?>
																<button
																	type="button"
																	class="asd-album-name asd-album-name--btn"
																	data-bgm-id="<?php echo esc_attr( (string) $album['bgm_id'] ); ?>"
																	title="<?php echo esc_attr( $album['title'] ); ?>"
																>
																	<?php echo esc_html( $album['title'] ); ?>
																</button>
															<?php else : ?>
																<span class="asd-album-name">
																	<?php echo esc_html( $album['title'] ); ?>
																</span>
															<?php endif; ?>

															<?php
															/*
															 * 類型標籤只在攤平模式出現。分組模式上面的 h4
															 * 已經寫了「片頭曲 3」，每張再標一次是廢話。
															 */
															?>
															<?php if ( ! $album_is_grouped && ! empty( $album['group'] ) ) : ?>
																<span class="asd-album-tag"><?php echo esc_html( $album['group'] ); ?></span>
															<?php endif; ?>

															<?php if ( '' !== $album['sub'] ) : ?>
																<span class="asd-album-sub">
																	<?php echo esc_html( $album['sub'] ); ?>
																</span>
															<?php endif; ?>
															</span><!-- /.asd-album-main -->
														</li>
													<?php endforeach; ?>
												</ul>
											</div>
										<?php endforeach; ?>
									</div>

										<?php /* 收合按鈕已移除——獨立 tab 直接列全部 */ ?>
										<?php /* 來源標註先不顯示（使用者指定） */ ?>
</div>
