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
?>

<div class="asd-album-groups">
	<?php foreach ( $rel_groups as $rel_group ) : ?>
		<div class="asd-album-group">
			<h4 class="asd-album-group-title">
				<?php echo esc_html( $rel_group['label'] ); ?>
				<span class="asd-album-count"><?php echo esc_html( (string) $rel_group['count'] ); ?></span>
			</h4>

			<ul class="asd-album-list">
				<?php foreach ( $rel_group['items'] as $rel_item ) : ?>
					<li class="asd-album-item">
						<?php if ( '' !== $rel_item['url'] ) : ?>
							<a
								class="asd-album-name"
								href="<?php echo esc_url( $rel_item['url'] ); ?>"
							>
								<?php echo esc_html( $rel_item['title'] ); ?>
							</a>
						<?php else : ?>
							<span class="asd-album-name">
								<?php echo esc_html( $rel_item['title'] ); ?>
							</span>
						<?php endif; ?>

						<?php if ( '' !== $rel_item['badge'] ) : ?>
							<span class="asd-album-badge"><?php echo esc_html( $rel_item['badge'] ); ?></span>
						<?php endif; ?>

						<?php if ( '' !== $rel_item['sub'] ) : ?>
							<span class="asd-album-sub">
								<?php echo esc_html( $rel_item['sub'] ); ?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( '' !== $rel_source ) : ?>
	<p class="asd-album-source"><?php echo esc_html( $rel_source ); ?></p>
<?php endif; ?>
