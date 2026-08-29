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
										<?php foreach ( $rel_albums as $album_group ) : ?>
											<div class="asd-album-group">
												<h4 class="asd-album-group-title">
													<?php echo esc_html( $album_group['label'] ); ?>
													<span class="asd-album-count"><?php echo esc_html( (string) $album_group['count'] ); ?></span>
												</h4>

												<ul class="asd-album-list">
													<?php foreach ( $album_group['items'] as $album ) : ?>
														<li class="asd-album-item">
															<?php if ( '' !== $album['url'] ) : ?>
																<?php /* 站內有這張專輯的文章就直接連過去，優先於彈窗 */ ?>
																<a
																	class="asd-album-name"
																	href="<?php echo esc_url( $album['url'] ); ?>"
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
																>
																	<?php echo esc_html( $album['title'] ); ?>
																</button>
															<?php else : ?>
																<span class="asd-album-name">
																	<?php echo esc_html( $album['title'] ); ?>
																</span>
															<?php endif; ?>

															<?php if ( '' !== $album['sub'] ) : ?>
																<span class="asd-album-sub">
																	<?php echo esc_html( $album['sub'] ); ?>
																</span>
															<?php endif; ?>
														</li>
													<?php endforeach; ?>
												</ul>
											</div>
										<?php endforeach; ?>
									</div>

										<?php /* 收合按鈕已移除——獨立 tab 直接列全部 */ ?>
										<?php /* 來源標註先不顯示（使用者指定） */ ?>
</div>
