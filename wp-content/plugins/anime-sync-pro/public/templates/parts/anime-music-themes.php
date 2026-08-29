<?php
/**
 * 主題曲播放器（AnimeThemes）。
 *
 * 留在動畫頁的音樂區塊裡——這是使用者最常找的東西，不該藏到子頁。
 * Bangumi 的相關專輯量體大（最多 133 張），移到 /anime/{slug}/music/。
 *
 * 需要 include 端備妥：$openings、$endings
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$openings = isset( $openings ) && is_array( $openings ) ? $openings : [];
$endings  = isset( $endings ) && is_array( $endings ) ? $endings : [];
?>
							<?php /* 區塊標題由 include 端輸出,這裡不要再來一次 */ ?>

							<?php
							$music_groups = [
								'OP' => $openings,
								'ED' => $endings,
							];

							foreach ( $music_groups as $music_type => $music_list ) :
								if ( empty( $music_list ) ) {
									continue;
								}
								?>
								<div class="asd-music-group">
									<h3 class="asd-music-group-title">
										<?php echo $music_type === 'OP' ? '片頭曲 OP' : '片尾曲 ED'; ?>
									</h3>

									<?php foreach ( $music_list as $theme_item ) :
										if ( ! is_array( $theme_item ) ) {
											continue;
										}

										$theme_type = strtoupper(
											trim(
												(string) (
													$theme_item['type']
														?? ''
												)
											)
										);

										$theme_title = trim(
											(string) (
												$theme_item['title']
													?? ''
											)
										);

										$theme_native = trim(
											(string) (
												$theme_item['title_native']
													?? ''
											)
										);

										$theme_artists = is_array(
											$theme_item['artists']
												?? null
										)
											? $theme_item['artists']
											: [];

										$artist_names        = [];
										$artist_romaji_names = [];

										foreach ( $theme_artists as $artist_item ) {
											if ( ! is_array( $artist_item ) ) {
												continue;
											}

											$artist_display = trim(
												(string) (
													$artist_item['name_native']
														?? $artist_item['name']
														?? ''
												)
											);

											$artist_romaji = trim(
												(string) (
													$artist_item['name']
														?? ''
												)
											);

											if ( $artist_display !== '' ) {
												$artist_names[] = $artist_display;
											}

											if ( $artist_romaji !== '' ) {
												$artist_romaji_names[] = $artist_romaji;
											}
										}

										$artist_display = implode(
											'、',
											array_unique( $artist_names )
										);

										$artist_romaji = implode(
											', ',
											array_unique( $artist_romaji_names )
										);

										$audio_url = trim(
											(string) (
												$theme_item['audio_url']
													?? ''
											)
										);

										$video_url = trim(
											(string) (
												$theme_item['video_url']
													?? ''
											)
										);

										if (
											$audio_url !== ''
											&& ! wp_http_validate_url( $audio_url )
										) {
											$audio_url = '';
										}

										if (
											$video_url !== ''
											&& ! wp_http_validate_url( $video_url )
										) {
											$video_url = '';
										}

										$theme_episodes = trim(
											(string) (
												$theme_item['episodes']
													?? ''
											)
										);

										$open_media_url =
											$video_url ?: $audio_url;

										$music_main = $theme_native !== ''
											? $theme_native
											: $theme_title;

										$music_sub = (
											$theme_native !== ''
											&& $theme_title !== ''
											&& $theme_title !== $theme_native
										)
											? $theme_title
											: '';

										$music_badge_class =
											strpos( $theme_type, 'OP' ) === 0
												? 'asd-music-type-badge--op'
												: 'asd-music-type-badge--ed';
										?>
										<div class="asd-music-card-v2">
											<span class="asd-music-type-badge <?php echo esc_attr( $music_badge_class ); ?>">
												<?php echo esc_html( $theme_type ); ?>
											</span>

											<div class="asd-music-body">
												<?php if ( $music_main ) : ?>
													<span class="asd-music-title">
														<?php echo esc_html( $music_main ); ?>
													</span>
												<?php endif; ?>

												<?php if ( $music_sub ) : ?>
													<span class="asd-music-native">
														<?php echo esc_html( $music_sub ); ?>
													</span>
												<?php endif; ?>

												<?php if ( $artist_display ) : ?>
													<span class="asd-music-artist">
														by <?php echo esc_html( $artist_display ); ?>
														<?php if (
															$artist_romaji
															&& $artist_romaji !== $artist_display
														) : ?>
															<span class="asd-music-artist-romaji">
																(<?php echo esc_html( $artist_romaji ); ?>)
															</span>
														<?php endif; ?>
													</span>
												<?php elseif ( $artist_romaji ) : ?>
													<span class="asd-music-artist">
														by <?php echo esc_html( $artist_romaji ); ?>
													</span>
												<?php endif; ?>

												<?php if ( $theme_episodes ) : ?>
													<span class="asd-music-episodes">
														<?php echo esc_html( $theme_episodes ); ?>
													</span>
												<?php endif; ?>
											</div>

											<?php if ( $video_url ) : ?>
												<div
													class="asd-music-thumb-slot"
													role="button"
													tabindex="0"
													aria-label="播放 MV"
												>
													<video
														class="asd-music-thumb-video"
														data-src="<?php echo esc_url( $video_url ); ?>"
														preload="none"
														muted
														playsinline
													></video>
													<span class="asd-music-thumb-play" aria-hidden="true">▶</span>
												</div>
											<?php endif; ?>

											<?php if ( $audio_url || $video_url ) : ?>
												<div
													class="asd-music-player-wrap"
													data-audio-src="<?php echo esc_url( $audio_url ); ?>"
													data-video-src="<?php echo esc_url( $video_url ); ?>"
												>
													<audio class="asd-music-audio" preload="none"></audio>
													<video
														class="asd-music-video"
														preload="none"
														playsinline
														hidden
													></video>

													<button
														class="asd-music-play-btn"
														type="button"
														aria-label="播放"
													></button>

													<div
														class="asd-music-progress-wrap"
														role="slider"
														aria-label="播放進度"
														aria-valuemin="0"
														aria-valuemax="100"
														aria-valuenow="0"
														tabindex="0"
													>
														<div class="asd-music-progress-bar"></div>
													</div>

													<span class="asd-music-time">0:00</span>

													<?php if ( ! $video_url && $open_media_url ) : ?>
														<a
															class="asd-music-open-link"
															href="<?php echo esc_url( $open_media_url ); ?>"
															target="_blank"
															rel="noopener noreferrer"
														>
															MV
														</a>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>

							<?php /* 只有主題曲播放器存在時才提醒載入問題 */ ?>
							<?php if ( ! empty( $openings ) || ! empty( $endings ) ) : ?>
								<p class="asd-stream-disclaimer" style="margin-top:20px !important;">
									主題曲影音由外部資料庫即時提供，載入速度依網路狀況而定，若遇到緩衝、讀取較慢或播放失敗，請耐心等候或重新點擊播放一次。
								</p>
							<?php endif; ?>

