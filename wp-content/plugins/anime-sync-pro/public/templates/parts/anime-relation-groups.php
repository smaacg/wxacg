<?php
/**
 * 通用的關聯清單（分組 → 項目）。
 *
 * 遊戲子頁與真人版子頁共用同一份輸出，兩者的資料結構一樣
 * （Anime_Sync_Subject_Relations_Repository::get_grouped() 的回傳），
 * 差別只在分組標籤，那個是 repository 決定的，模板不必知道。
 *
 * 需要 include 端備妥：
 *   $rel_groups  array   分組後的關聯
 *   $rel_source  string  來源標註文字（選填）
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rel_groups = isset( $rel_groups ) && is_array( $rel_groups ) ? $rel_groups : [];
$rel_source = isset( $rel_source ) ? (string) $rel_source : '資料來源：Bangumi 番組計劃';

/* 與相關專輯同一套規則：數量少就攤平成一面牆，理由見 repository 的 FLAT_MAX */
$rel_flat = [];

foreach ( $rel_groups as $rel_group ) {
	$rel_flat = array_merge( $rel_flat, $rel_group['items'] );
}

$rel_is_grouped = class_exists( 'Anime_Sync_Subject_Relations_Repository' )
	&& count( $rel_flat ) > Anime_Sync_Subject_Relations_Repository::FLAT_MAX;

$rel_sections = $rel_is_grouped
	? $rel_groups
	: [ [ 'label' => '', 'count' => count( $rel_flat ), 'items' => $rel_flat ] ];
?>

<div class="asd-album-groups">
	<?php foreach ( $rel_sections as $rel_group ) : ?>
		<div class="asd-album-group">
			<?php if ( '' !== $rel_group['label'] ) : ?>
				<h4 class="asd-album-group-title">
					<?php echo esc_html( $rel_group['label'] ); ?>
					<span class="asd-album-count"><?php echo esc_html( (string) $rel_group['count'] ); ?></span>
				</h4>
			<?php endif; ?>

			<ul class="asd-album-list">
				<?php foreach ( $rel_group['items'] as $rel_item ) : ?>
					<li class="asd-album-item">
						<?php /* 封面與專輯同一套：網址存本地，圖片走 Bangumi CDN */ ?>
						<?php if ( ! empty( $rel_item['cover'] ) ) : ?>
							<img
								class="asd-album-thumb"
								src="<?php echo esc_url( $rel_item['cover'] ); ?>"
								alt=""
								loading="lazy"
								decoding="async"
								width="400"
								height="400"
							>
						<?php else : ?>
							<span class="asd-album-thumb asd-album-thumb--empty" aria-hidden="true">🎬</span>
						<?php endif; ?>

						<span class="asd-album-main">
						<?php if ( '' !== $rel_item['url'] ) : ?>
							<a
								class="asd-album-name"
								href="<?php echo esc_url( $rel_item['url'] ); ?>"
								title="<?php echo esc_attr( $rel_item['title'] ); ?>"
							>
								<?php echo esc_html( $rel_item['title'] ); ?>
							</a>
						<?php elseif ( $rel_item['bgm_id'] > 0 ) : ?>
							<?php
							/*
							 * 站內沒有對應文章時開彈窗，跟相關專輯同一套。
							 *
							 * 這個分支原本只做在專輯的模板裡，這裡沒補上，
							 * 於是遊戲與真人版全部落到下面的 <span>——local_post_id
							 * 實測全站都是 0，等於整頁沒有一個點得動。
							 *
							 * class 沿用 .asd-album-name--btn：前端 initAlbumModal
							 * 是綁這個 class，不必為了這裡再改一份 JS。
							 */
							?>
							<button
								type="button"
								class="asd-album-name asd-album-name--btn"
								data-bgm-id="<?php echo esc_attr( (string) $rel_item['bgm_id'] ); ?>"
								title="<?php echo esc_attr( $rel_item['title'] ); ?>"
							>
								<?php echo esc_html( $rel_item['title'] ); ?>
							</button>
						<?php else : ?>
							<span class="asd-album-name">
								<?php echo esc_html( $rel_item['title'] ); ?>
							</span>
						<?php endif; ?>

						<?php if ( '' !== $rel_item['badge'] ) : ?>
							<span class="asd-album-badge"><?php echo esc_html( $rel_item['badge'] ); ?></span>
						<?php endif; ?>

						<?php /* 攤平時類型改由每張自己標；分組時上面的 h4 已經說了 */ ?>
						<?php if ( ! $rel_is_grouped && ! empty( $rel_item['group'] ) ) : ?>
							<span class="asd-album-tag"><?php echo esc_html( $rel_item['group'] ); ?></span>
						<?php endif; ?>

						<?php if ( '' !== $rel_item['sub'] ) : ?>
							<span class="asd-album-sub">
								<?php echo esc_html( $rel_item['sub'] ); ?>
							</span>
						<?php endif; ?>
						</span><!-- /.asd-album-main -->
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( '' !== $rel_source ) : ?>
	<p class="asd-album-source"><?php echo esc_html( $rel_source ); ?></p>
<?php endif; ?>
