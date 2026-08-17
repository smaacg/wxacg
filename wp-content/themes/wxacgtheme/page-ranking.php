<?php
/**
 * Template Name: 動漫排行榜
 * Template Post Type: page
 *
 * v1.3  2026-08-16
 * - 預渲染改用 wxacg_ranking_get_state()，一併輸出資料狀態：
 *   外部平台無法連線時（status=error）直接渲染提示區塊，
 *   不再顯示骨架動畫後才由 JS 換成誤導的「此條件暫無資料」。
 * - 計數列改為依實際狀態輸出，避免先閃一次寫死的「Top 20」。
 * - 狀態經 data-status 傳給 ranking.js（見該檔 v1.5.0）。
 *
 * v1.1  2026-05-11
 * - 品牌名稱：weixiaoacg+ → 微笑動漫+
 * - <div class="rank-category-tab"> 改 <button>
 * - inline style="display:none" 改 hidden 屬性
 * - 文字加 esc_html__() 包裝（為未來 i18n 預留）
 * - 平台說明卡：圖示與描述微調
 * - 新增最後更新時間顯示（前端 JS 補值）
 *
 * Path: wp-content/themes/blocksy-child/page-ranking.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

// 主品牌名（將來改名只需動這一行）
$smacg_brand = '微笑動漫';
?>

<!-- ================================================================
     HERO
     ================================================================ -->
<section class="rank-hero">
  <canvas id="rank-particles" class="rank-hero__particles" aria-hidden="true"></canvas>
  <div class="rank-hero__overlay"></div>
  <div class="container rank-hero__content">
    <div class="rank-hero__eyebrow">
      <span class="chip"><i class="fa-solid fa-database"></i> <?php echo esc_html__( '多平台整合數據', 'weixiaoacg' ); ?></span>
      <span class="chip chip--green"><i class="fa-solid fa-clock-rotate-left"></i> <?php echo esc_html__( '每小時更新', 'weixiaoacg' ); ?></span>
    </div>
    <h1 class="rank-hero__title">
      <span class="rank-hero__trophy">🏆</span>
      <?php echo esc_html__( '動漫排行榜', 'weixiaoacg' ); ?>
    </h1>
    <p class="rank-hero__subtitle"><?php echo esc_html__( '整合全球四大平台數據，發現真正的神作', 'weixiaoacg' ); ?></p>
    <div class="rank-hero__stats">
      <div class="rank-hero__stat">
        <span class="rank-hero__stat-num grad-text">4</span>
        <span class="rank-hero__stat-label"><?php echo esc_html__( '資料平台', 'weixiaoacg' ); ?></span>
      </div>
      <div class="rank-hero__stat-divider"></div>
      <div class="rank-hero__stat">
        <span class="rank-hero__stat-num grad-text">500萬+</span>
        <span class="rank-hero__stat-label"><?php echo esc_html__( '全球評分數', 'weixiaoacg' ); ?></span>
      </div>
      <div class="rank-hero__stat-divider"></div>
      <div class="rank-hero__stat">
        <span class="rank-hero__stat-num grad-text">Top 20</span>
        <span class="rank-hero__stat-label"><?php echo esc_html__( '每榜收錄', 'weixiaoacg' ); ?></span>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================
     第一層分類頁籤
     ================================================================ -->
<div class="rank-category-row">
  <div class="container">
    <div class="rank-category-tabs" id="rank-category-tabs" role="tablist" aria-label="<?php esc_attr_e( '排行榜分類', 'weixiaoacg' ); ?>">
      <button type="button" class="rank-category-tab active" data-category="anime" role="tab" aria-selected="true">
        🎬 <?php echo esc_html__( '動漫', 'weixiaoacg' ); ?>
      </button>
      <button type="button" class="rank-category-tab coming-soon" data-category="manga" role="tab" aria-selected="false" disabled
              title="<?php esc_attr_e( '即將推出', 'weixiaoacg' ); ?>">
        📖 <?php echo esc_html__( '漫畫', 'weixiaoacg' ); ?>
        <span class="soon-badge">Coming Soon</span>
      </button>
      <button type="button" class="rank-category-tab coming-soon" data-category="novel" role="tab" aria-selected="false" disabled
              title="<?php esc_attr_e( '即將推出', 'weixiaoacg' ); ?>">
        📚 <?php echo esc_html__( '輕小說', 'weixiaoacg' ); ?>
        <span class="soon-badge">Coming Soon</span>
      </button>
      <button type="button" class="rank-category-tab coming-soon" data-category="music" role="tab" aria-selected="false" disabled
              title="<?php esc_attr_e( '即將推出', 'weixiaoacg' ); ?>">
        🎵 <?php echo esc_html__( '音樂', 'weixiaoacg' ); ?>
        <span class="soon-badge">Coming Soon</span>
      </button>
      <button type="button" class="rank-category-tab coming-soon" data-category="game" role="tab" aria-selected="false" disabled
              title="<?php esc_attr_e( '即將推出', 'weixiaoacg' ); ?>">
        🎮 <?php echo esc_html__( '遊戲', 'weixiaoacg' ); ?>
        <span class="soon-badge">Coming Soon</span>
      </button>
      <button type="button" class="rank-category-tab coming-soon" data-category="vtuber" role="tab" aria-selected="false" disabled
              title="<?php esc_attr_e( '即將推出', 'weixiaoacg' ); ?>">
        📺 VTuber
        <span class="soon-badge">Coming Soon</span>
      </button>
    </div>
  </div>
</div>

<!-- ================================================================
     主體內容區
     ================================================================ -->
<section class="rank-body section">
  <div class="container">
    <div class="rank-layout">

      <!-- 左欄：排行榜主區 -->
      <div class="rank-main">

        <!-- 平台頁籤 -->
        <div class="rank-platform-row">
          <div class="rank-platform-tabs" id="rank-platform-tabs" role="tablist" aria-label="<?php esc_attr_e( '評分平台', 'weixiaoacg' ); ?>">
            <button type="button" class="rank-platform-tab" data-platform="site" role="tab" aria-selected="false">
              ⭐ <?php echo esc_html( $smacg_brand ); ?>
            </button>
            <button type="button" class="rank-platform-tab active" data-platform="anilist" role="tab" aria-selected="true">
              🌐 AniList
            </button>
            <button type="button" class="rank-platform-tab" data-platform="mal" role="tab" aria-selected="false">
              📊 MyAnimeList
            </button>
            <button type="button" class="rank-platform-tab" data-platform="bangumi" role="tab" aria-selected="false">
              🎯 Bangumi
            </button>
          </div>
        </div>

        <!-- 站內子分類（切到 site 才顯示） -->
        <div class="site-sub-row" id="site-sub-tabs" hidden>
          <button type="button" class="site-sub-btn active" data-rank-type="rating">
            ⭐ <?php echo esc_html__( '評分最高', 'weixiaoacg' ); ?>
          </button>
          <button type="button" class="site-sub-btn" data-rank-type="views">
            🔥 <?php echo esc_html__( '最多瀏覽', 'weixiaoacg' ); ?>
          </button>
          <button type="button" class="site-sub-btn" data-rank-type="favorites">
            💖 <?php echo esc_html__( '最多收藏', 'weixiaoacg' ); ?>
          </button>
        </div>

        <?php
        /**
         * v1.2：預設視圖改為伺服器端渲染。
         *
         * 原本整份榜單都由 ranking.js 在瀏覽器端向第三方 API 取得，
         * 導致搜尋引擎只看得到骨架動畫、頁面實質內容為零。
         * 這裡先把「AniList + 本週」（即 ranking.js 的預設狀態）直接輸出，
         * 使用者切換頁籤後才交由 JS 接手重繪。
         *
         * 資料來源與快取見 inc/ranking-feed.php。
         *
         * v1.3：連同狀態一起預渲染。
         * 外部平台掛掉時（status=error）直接輸出「無法連線」提示，
         * 不再落到骨架動畫、等 JS 再打一次注定失敗的請求；
         * status=stale 則在榜單上方掛一條資料時間提示。
         * 計數也在這裡先算好，避免先閃一次寫死的「Top 20」再被 JS 修正。
         */
        $rank_default_platform = 'anilist';
        $rank_default_period   = 'weekly';

        $rank_prerendered = '';
        $rank_status      = 'ok';
        $rank_count       = 0;

        if ( function_exists( 'wxacg_ranking_get_state' ) ) {
            $rank_state  = wxacg_ranking_get_state( $rank_default_platform, $rank_default_period );
            $rank_status = $rank_state['status'];
            $rank_count  = count( $rank_state['items'] );

            $rank_prerendered = wxacg_ranking_render_state_notice(
                $rank_default_platform,
                $rank_status,
                (int) $rank_state['updated']
            ) . wxacg_ranking_render_cards(
                $rank_state['items'],
                $rank_default_platform
            );
        }
        ?>

        <!-- 平台介紹卡 -->
        <div class="platform-info-card glass-card" id="platform-info-card"></div>

        <!-- 時間週期 + 計數 -->
        <div class="rank-toolbar">
          <div class="period-btns" id="period-btns" role="group" aria-label="<?php esc_attr_e( '時間範圍', 'weixiaoacg' ); ?>">
            <button type="button" class="period-btn" data-period="daily"><?php echo esc_html__( '今日', 'weixiaoacg' ); ?></button>
            <button type="button" class="period-btn active" data-period="weekly"><?php echo esc_html__( '本週', 'weixiaoacg' ); ?></button>
            <button type="button" class="period-btn" data-period="monthly"><?php echo esc_html__( '本月', 'weixiaoacg' ); ?></button>
            <button type="button" class="period-btn" data-period="yearly"><?php echo esc_html__( '年度', 'weixiaoacg' ); ?></button>
          </div>
          <h2 class="rank-count-info" id="rank-count-info">
            <?php
            echo esc_html__( '本週 AniList 排行', 'weixiaoacg' );

            if ( $rank_status === 'error' ) {
                echo ' · ' . esc_html__( '暫時無法取得', 'weixiaoacg' );
            } elseif ( $rank_count > 0 ) {
                echo ' · Top ' . (int) $rank_count;
            }
            // 兩者皆非（伺服器端沒資料、待 JS 載入）時不標數量，避免顯示誤導的 Top 0
            ?>
          </h2>
        </div>

        <?php
        /*
         * ItemList 結構化資料。
         *
         * 排行榜本質是有序清單，先前頁面只有 BreadcrumbList。
         * 這裡輸出的是伺服器預渲染的那一份（AniList／本週）——與搜尋引擎
         * 實際看得到的 HTML 內容一致；其餘平台是 JS 切換的，不納入，
         * 避免宣告了頁面上不存在的項目。
         */
        if ( ! empty( $rank_state['items'] ) ) {
            $rank_ld_items = [];

            foreach ( $rank_state['items'] as $rank_ld_i => $rank_ld_row ) {
                $rank_ld_name = trim( (string) ( $rank_ld_row['titleZh'] ?? '' ) );
                $rank_ld_url  = trim( (string) ( $rank_ld_row['url'] ?? '' ) );

                if ( $rank_ld_name === '' || $rank_ld_url === '' ) {
                    continue;
                }

                $rank_ld_items[] = [
                    '@type'    => 'ListItem',
                    'position' => $rank_ld_i + 1,
                    'name'     => $rank_ld_name,
                    'url'      => $rank_ld_url,
                ];
            }

            if ( ! empty( $rank_ld_items ) ) {
                echo '<script type="application/ld+json">'
                    . wp_json_encode(
                        [
                            '@context'        => 'https://schema.org',
                            '@type'           => 'ItemList',
                            'name'            => __( '本週 AniList 動畫排行榜', 'weixiaoacg' ),
                            'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
                            'numberOfItems'   => count( $rank_ld_items ),
                            'itemListElement' => $rank_ld_items,
                        ],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                    . '</script>' . "\n";
            }
        }
        ?>

        <!-- 排行列表 -->
        <div
          class="rank-list"
          id="rank-list"
          aria-live="polite"
          <?php if ( $rank_prerendered !== '' ) : ?>
            data-prerendered="1"
            data-status="<?php echo esc_attr( $rank_status ); ?>"
            data-platform="<?php echo esc_attr( $rank_default_platform ); ?>"
            data-period="<?php echo esc_attr( $rank_default_period ); ?>"
          <?php endif; ?>
        >
          <?php if ( $rank_prerendered !== '' ) : ?>
            <?php
            // 內容由 wxacg_ranking_render_cards() 逐欄位 esc_* 過，此處不可再跑一次跳脫。
            echo $rank_prerendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
          <?php else : ?>
            <div class="rank-loading">
              <div class="skeleton" style="height:90px;border-radius:16px;"></div>
              <div class="skeleton" style="height:90px;border-radius:16px;margin-top:10px;"></div>
              <div class="skeleton" style="height:90px;border-radius:16px;margin-top:10px;"></div>
            </div>
          <?php endif; ?>
        </div>

        <!-- 站內排行底部提示 -->
        <p class="site-rank-note" id="site-rank-note" hidden>
          <i class="fa-solid fa-circle-info"></i>
          <?php
          printf(
            esc_html__( '數據來自 %s 會員的真實評分（貝葉斯加權），至少 1 人評分即入榜', 'weixiaoacg' ),
            esc_html( $smacg_brand )
          );
          ?>
        </p>

        <!-- 最後更新時間（JS 補值） -->
        <p class="rank-updated-at" id="rank-updated-at" hidden>
          <i class="fa-regular fa-clock"></i>
          <?php echo esc_html__( '最後更新：', 'weixiaoacg' ); ?>
          <span id="rank-updated-time">—</span>
        </p>

      </div><!-- /rank-main -->

      <!-- 右欄：側欄 -->
      <aside class="rank-sidebar">

        <!-- 本週新上榜 -->
        <div class="rank-sidebar-card glass-mid">
          <h2 class="rank-sidebar-title">
            <i class="fa-solid fa-circle-plus" style="color:var(--accent-cyan);"></i>
            <?php echo esc_html__( '本週新上榜', 'weixiaoacg' ); ?> 🆕
          </h2>
          <div class="sb-rank-list" id="sidebar-new-list"></div>
        </div>

        <!-- 排名變動最大 -->
        <div class="rank-sidebar-card glass-mid">
          <h2 class="rank-sidebar-title">
            <i class="fa-solid fa-chart-line" style="color:var(--accent-violet);"></i>
            <?php echo esc_html__( '本週排名變動', 'weixiaoacg' ); ?> 📈
          </h2>
          <div class="sb-rank-list" id="sidebar-movers-list"></div>
        </div>

        <!-- 平台說明 -->
        <div class="rank-sidebar-card glass-mid">
          <h2 class="rank-sidebar-title">
            <i class="fa-solid fa-circle-question" style="color:var(--accent-blue);"></i>
            <?php echo esc_html__( '平台說明', 'weixiaoacg' ); ?>
          </h2>
          <div class="platform-guide">
            <div class="pg-item">
              <span class="pg-icon" style="background:rgba(108,99,255,0.15);color:#6c63ff;">⭐</span>
              <div class="pg-info">
                <div class="pg-name"><?php echo esc_html( $smacg_brand ); ?></div>
                <div class="pg-desc"><?php echo esc_html__( '本站社群真實數據', 'weixiaoacg' ); ?></div>
              </div>
            </div>
            <div class="pg-item">
              <span class="pg-icon" style="background:rgba(2,169,255,0.15);color:#02a9ff;">🌐</span>
              <div class="pg-info">
                <div class="pg-name">AniList</div>
                <div class="pg-desc"><?php echo esc_html__( '歐美社群・精細評分', 'weixiaoacg' ); ?></div>
              </div>
            </div>
            <div class="pg-item">
              <span class="pg-icon" style="background:rgba(46,81,162,0.15);color:#4d7fff;">📊</span>
              <div class="pg-info">
                <div class="pg-name">MyAnimeList</div>
                <div class="pg-desc"><?php echo esc_html__( '全球最大・歷史最完整', 'weixiaoacg' ); ?></div>
              </div>
            </div>
            <div class="pg-item">
              <span class="pg-icon" style="background:rgba(240,145,153,0.15);color:#f09199;">🎯</span>
              <div class="pg-info">
                <div class="pg-name">Bangumi</div>
                <div class="pg-desc"><?php echo esc_html__( '華語圈・聲優資料詳盡', 'weixiaoacg' ); ?></div>
              </div>
            </div>
          </div>
          <p style="font-size:11px;color:var(--text-muted);margin-top:12px;line-height:1.6;">
            <?php echo esc_html__( '各平台用戶組成不同，排名順序可能有差異，體現真實的口味多元性。', 'weixiaoacg' ); ?>
          </p>
        </div>

      </aside><!-- /rank-sidebar -->

    </div><!-- /rank-layout -->
  </div>
</section>

<div class="toast-container" id="toast-container" aria-live="polite"></div>

<?php get_footer(); ?>
