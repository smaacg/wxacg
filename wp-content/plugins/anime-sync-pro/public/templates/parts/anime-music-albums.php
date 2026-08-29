<?php
/**
 * 相關專輯清單（Bangumi 關聯）。
 *
 * 由 single-anime.php 在音樂子頁（/anime/{slug}/music/）include。
 * 主題曲不在這裡——那個留在動畫頁，見 anime-music-themes.php。
 *
 * 需要 include 端備妥：$rel_albums、$rel_albums_total
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rel_albums       = isset( $rel_albums ) && is_array( $rel_albums ) ? $rel_albums : [];
$rel_albums_total = isset( $rel_albums_total ) ? (int) $rel_albums_total : 0;
?>
<div class="asd-album-block asd-album-block--standalone">
									<h3 class="asd-music-group-title">
										相關專輯
										<span class="asd-album-total"><?php echo esc_html( (string) $rel_albums_total ); ?></span>
									</h3>

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
																<a
																	class="asd-album-name"
																	href="<?php echo esc_url( $album['url'] ); ?>"
																>
																	<?php echo esc_html( $album['title'] ); ?>
																</a>
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

									<p class="asd-album-source">
										專輯資料來源：Bangumi 番組計劃
									</p>
</div>
