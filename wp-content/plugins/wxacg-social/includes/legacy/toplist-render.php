<?php
/**
 * 會員自訂動漫排行 — 公開展示
 *
 * 兩種畫面：
 *   索引  /u/{username}/toplist/       該會員的所有公開排行
 *   單一  /u/{username}/toplist/{id}/  單一排行，這是拿去分享的網址
 *
 * 分享按鈕刻意提供「複製文字」而不只是複製連結——留言板多半只吃純文字，
 * 使用者要的是能直接貼進去的內容，連結附在文字末尾即可。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 取得排行中某一部作品的顯示資料。
 *
 * 作品可能已被刪除或轉為草稿——此時回傳 null，呼叫端跳過該筆並讓名次
 * 自然遞補，不顯示「作品已不存在」之類的破碎項目。
 *
 * @param int $post_id 作品 ID。
 * @return array|null [ title, url, cover, year ]
 */
function wxacg_toplist_item_data( int $post_id ): ?array {
    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) {
        return null;
    }

    $cover = (string) get_the_post_thumbnail_url( $post_id, 'weixiaoacg-cover' );
    if ( $cover === '' ) {
        $cover = (string) get_the_post_thumbnail_url( $post_id, 'medium' );
    }

    return [
        'title' => get_the_title( $post_id ),
        'url'   => (string) get_permalink( $post_id ),
        'cover' => $cover,
        'year'  => (int) get_post_meta( $post_id, 'anime_season_year', true ),
    ];
}

/**
 * 產生可直接貼到站外留言板的純文字版本。
 *
 * 格式刻意樸素——Threads、Discord、論壇留言板對 Markdown 的支援不一，
 * 純數字編號與換行是唯一到處都不會壞的排版。
 *
 * @param array   $list 清單資料。
 * @param WP_User $user 清單擁有者。
 */
function wxacg_toplist_share_text( array $list, WP_User $user ): string {
    $display = $user->display_name ?: $user->user_login;
    $lines   = [];

    $rank = 0;
    foreach ( $list['items'] as $pid ) {
        $item = wxacg_toplist_item_data( (int) $pid );
        if ( ! $item ) {
            continue;
        }
        $rank++;
        $lines[] = $rank . '. ' . $item['title'];
    }

    $out   = [];
    $out[] = sprintf( '🏆 %s — %s', $list['title'], $display );
    $out[] = '';
    $out   = array_merge( $out, $lines );
    $out[] = '';
    $out[] = wxacg_toplist_permalink( $user->ID, (int) $list['id'] );

    return implode( "\n", $out );
}

/**
 * 渲染單一排行清單。
 *
 * @param WP_User $user 清單擁有者。
 * @param array   $list 清單資料。
 */
function wxacg_toplist_render_single( WP_User $user, array $list ): void {
    $display = $user->display_name ?: $user->user_login;
    $items   = [];

    foreach ( $list['items'] as $pid ) {
        $data = wxacg_toplist_item_data( (int) $pid );
        if ( $data ) {
            $items[] = $data;
        }
    }
    ?>
    <?php
    /*
     * data-wxtl-view 交給 public-profile.js 觸發一次計數 AJAX。
     *
     * 不在這裡直接 +1：本頁對訪客是被 LiteSpeed 快取的，PHP 只有在快取
     * 未命中時才會執行，越熱門的排行反而計得越少。
     */
    $views = function_exists( 'wxacg_toplist_get_views' )
        ? wxacg_toplist_get_views( $user->ID, (int) $list['id'] )
        : 0;
    ?>
    <section class="wxtl wxtl-single"
             data-wxtl-view="1"
             data-wxtl-user="<?php echo (int) $user->ID; ?>"
             data-wxtl-list="<?php echo (int) $list['id']; ?>"
             data-wxtl-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

        <header class="wxtl-head">
            <h1 class="wxtl-title"><?php echo esc_html( $list['title'] ); ?></h1>
            <p class="wxtl-by">
                <?php
                printf(
                    /* translators: %1$s 會員名稱, %2$d 作品數 */
                    esc_html__( '%1$s 的排行 · 共 %2$d 部', 'wxacg-social' ),
                    esc_html( $display ),
                    count( $items )
                );
                ?>
                <span class="wxtl-views" hidden>
                    · 👁 <span class="wxtl-views-num"><?php echo (int) $views; ?></span> 次瀏覽
                </span>
            </p>
        </header>

        <?php if ( empty( $items ) ) : ?>
            <p class="wxtl-empty">這個排行還沒有加入任何作品。</p>
        <?php else : ?>

            <ol class="wxtl-list">
                <?php foreach ( $items as $i => $item ) : ?>
                    <li class="wxtl-item<?php echo $i < 3 ? ' is-top3' : ''; ?>">
                        <span class="wxtl-rank"><?php echo (int) ( $i + 1 ); ?></span>

                        <a class="wxtl-cover" href="<?php echo esc_url( $item['url'] ); ?>">
                            <?php if ( $item['cover'] ) : ?>
                                <img src="<?php echo esc_url( $item['cover'] ); ?>"
                                     alt="<?php echo esc_attr( $item['title'] ); ?>"
                                     loading="lazy" decoding="async">
                            <?php else : ?>
                                <span class="wxtl-cover-blank">—</span>
                            <?php endif; ?>
                        </a>

                        <div class="wxtl-meta">
                            <a class="wxtl-name" href="<?php echo esc_url( $item['url'] ); ?>">
                                <?php echo esc_html( $item['title'] ); ?>
                            </a>
                            <?php if ( $item['year'] > 0 ) : ?>
                                <span class="wxtl-year"><?php echo (int) $item['year']; ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php
            /*
             * 分享區。
             *
             * 文字放進 data 屬性交給 JS 複製，而不是塞在隱藏的 textarea——
             * 後者在部分行動瀏覽器上 select() 會把畫面捲到奇怪的位置。
             */
            $share_text = wxacg_toplist_share_text( $list, $user );
            $share_url  = wxacg_toplist_permalink( $user->ID, (int) $list['id'] );
            ?>
            <div class="wxtl-share">
                <button type="button" class="wxtl-btn wxtl-copy-text"
                        data-copy="<?php echo esc_attr( $share_text ); ?>">
                    📋 複製文字版
                </button>
                <button type="button" class="wxtl-btn wxtl-copy-link"
                        data-copy="<?php echo esc_attr( $share_url ); ?>">
                    🔗 複製連結
                </button>
                <span class="wxtl-copied" hidden>已複製</span>
            </div>

        <?php endif; ?>

    </section>
    <?php
}

/**
 * 渲染某會員的排行索引。
 *
 * @param WP_User $user     清單擁有者。
 * @param bool    $is_owner 瀏覽者是否為本人（本人才看得到私人清單）。
 */
function wxacg_toplist_render_index( WP_User $user, bool $is_owner = false ): void {
    $viewer = $is_owner ? $user->ID : get_current_user_id();
    $lists  = wxacg_toplist_get_all( $user->ID );

    $visible = [];
    foreach ( $lists as $list ) {
        if ( wxacg_toplist_can_view( $list, $user->ID, $viewer ) ) {
            $visible[] = $list;
        }
    }

    if ( empty( $visible ) ) {
        echo '<p class="wxtl-empty">' . esc_html__( '這位會員還沒有建立公開的排行。', 'wxacg-social' ) . '</p>';
        return;
    }
    ?>
    <section class="wxtl wxtl-index">
        <ul class="wxtl-cards">
            <?php foreach ( $visible as $list ) : ?>
                <?php
                // 取前三名的封面當卡片縮圖
                $thumbs = [];
                foreach ( array_slice( $list['items'], 0, 3 ) as $pid ) {
                    $d = wxacg_toplist_item_data( (int) $pid );
                    if ( $d && $d['cover'] ) {
                        $thumbs[] = $d['cover'];
                    }
                }
                ?>
                <li class="wxtl-card">
                    <a href="<?php echo esc_url( wxacg_toplist_permalink( $user->ID, (int) $list['id'] ) ); ?>">
                        <span class="wxtl-card-thumbs">
                            <?php foreach ( $thumbs as $t ) : ?>
                                <img src="<?php echo esc_url( $t ); ?>" alt="" loading="lazy" decoding="async">
                            <?php endforeach; ?>
                        </span>
                        <span class="wxtl-card-title"><?php echo esc_html( $list['title'] ); ?></span>
                        <span class="wxtl-card-count"><?php echo (int) count( $list['items'] ); ?> 部</span>
                        <?php if ( empty( $list['public'] ) ) : ?>
                            <span class="wxtl-card-private">🔒 私人</span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}
