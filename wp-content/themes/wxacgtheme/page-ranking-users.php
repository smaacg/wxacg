<?php
/**
 * Template Name: 會員排行榜
 * Template Post Type: page
 * @package weixiaoacg
 * @version 2.5.0 (2026-08-16)
 *
 * v2.5.0 變更：
 *   - 補齊 3 個排行 tab：exp_monthly（本月 EXP 榜）/ followers（人氣榜）/
 *     badges（徽章榜）。這 3 種型別的資料早已由 class-ranking-cron.php 每小時
 *     隨 Ranking_System::rebuild_all() 一併算入 wp_smacg_rankings 表，
 *     後端 AJAX（smacg_get_ranking）也一直支援，先前僅缺前台入口。
 *   - 對應前端字典補在 assets/js/leaderboard.js（typeLabel / scoreUnit）。
 *   - 圖示避開已被使用的 fa-trophy（本季牌位），徽章榜改用 fa-medal；
 *     fa-calendar-star 為 FA Pro 專屬，本月榜改用免費版的 fa-calendar-days。
 *
 * v2.4.0 變更：
 *   - 新增：本季牌位排行（rank_season）tabs 列旁邊顯示「距離本季結算」倒數
 *     （#ranku-season-countdown），只在預設 tab 為 rank_season 時初始顯示。
 *     時間戳來自 smacg_get_all_seasons_schedule() 的 active 賽季 end，
 *     這裡算出的值只是「首屏保底值」；實際切換 tab 後的倒數
 *     由 class-leaderboard-ajax.php v2.2.0 新增的 season_end 欄位即時校正
 *     （見 ranku-front.js），避免使用者長時間停留跨過結算時間點後
 *     顯示過期倒數。
 *
 * v2.3.0 變更：
 *   - 隱藏 #ranku-particles canvas（其 JS 繪製的藍色背景與過高高度為跑版主因）
 *   - Hero 改為純 CSS 四季奢華背景，高度、顏色完全由 CSS 控制
 *   - 修正內容置中跑版
 *
 * v2.2.0：Hero 四季奢華版，依月份自動切換配色
 * v2.1.0：Hero 會員數 chip + 未登入雙鈕 CTA
 * v2.0.0：精簡為 3 個 tab
 */

if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$current_uid  = get_current_user_id();
$is_logged_in = is_user_logged_in();
$updated_at   = get_option( 'smacg_ranking_last_rebuild', '' );

$default_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'exp_total';
/*
 * followers（人氣榜）暫時下架。
 *
 * 站上目前 230 位會員、追蹤關係稀疏，榜單多半只有個位數而且長期不動，
 * 呈現出來反而像功能壞掉。等會員規模成長再開回來——排行類型本身仍註冊
 * 在 WXACG_RANKING_TYPES，資料也持續累積，把 'followers' 加回這個陣列
 * 就能復原，不需要其他改動。
 */
$valid_tabs  = [ 'exp_total', 'rank_season', 'rank_last_season', 'exp_monthly', 'login_streak', 'achievements' ];
if ( ! in_array( $default_tab, $valid_tabs, true ) ) $default_tab = 'exp_total';

$season_info = null;
if ( $is_logged_in && function_exists( 'smacg_get_user_rank_season_info' ) ) {
    $season_info = smacg_get_user_rank_season_info( $current_uid );
}
$season_label = function_exists( 'smacg_get_season_label' ) ? smacg_get_season_label() : '';
$guide_url    = home_url( '/level-guide/' );

/* 依當前月份判斷季節（wp_date 依 WP 時區）：春 3-5 / 夏 6-8 / 秋 9-11 / 冬 12-2 */
$ranku_month  = (int) wp_date( 'n' );
if     ( in_array( $ranku_month, [ 3, 4, 5 ],   true ) ) { $ranku_season = 'spring'; }
elseif ( in_array( $ranku_month, [ 6, 7, 8 ],   true ) ) { $ranku_season = 'summer'; }
elseif ( in_array( $ranku_month, [ 9, 10, 11 ], true ) ) { $ranku_season = 'autumn'; }
else                                                      { $ranku_season = 'winter'; }

/* 目前會員總數（快取 1 小時） */
$total_members = get_transient( 'smacg_total_members_count' );
if ( false === $total_members ) {
    $counts        = count_users();
    $total_members = (int) ( $counts['total_users'] ?? 0 );
    set_transient( 'smacg_total_members_count', $total_members, HOUR_IN_SECONDS );
}

/* 註冊連結 */
$register_url = get_option( 'users_can_register' )
    ? wp_registration_url()
    : wp_login_url( get_permalink() );

/* 上季 label */
$last_season_label = '';
if ( class_exists( '\WXACG\Gamification\Rank_Season' )
  && method_exists( '\WXACG\Gamification\Rank_Season', 'get_last_settled_season_code' ) ) {
    $code = \WXACG\Gamification\Rank_Season::get_last_settled_season_code();
    if ( $code && class_exists( '\WXACG\Gamification\Rank_Tier' ) ) {
        $last_season_label = \WXACG\Gamification\Rank_Tier::season_label( $code );
    }
}

/* ★ v2.4.0 新增：本季結束時間戳（給前端倒數用的首屏保底值） */
$season_end_ts = 0;
if ( function_exists( 'smacg_get_all_seasons_schedule' ) ) {
    foreach ( smacg_get_all_seasons_schedule() as $__s ) {
        if ( ( $__s['status'] ?? '' ) === 'active' ) {
            $season_end_ts = (int) ( $__s['end'] ?? 0 );
            break;
        }
    }
}

/* ★ v2.6.0 新增：展示帳號（官方帳號，全成就滿等，僅供展示，不參與排行） */
$showcase_user        = get_user_by( 'slug', 'weixiaoacg' );
$showcase_info         = null;
$showcase_badge_total  = 0;
if ( $showcase_user && class_exists( '\WXACG\Gamification\Level_System' ) ) {
    $showcase_info = \WXACG\Gamification\Level_System::get_user_level( $showcase_user->ID );
    if ( defined( 'WXACG_BADGE_SLUG' ) ) {
        $showcase_badge_total = (int) wp_count_posts( WXACG_BADGE_SLUG )->publish;
    }
}
?>

<section class="ranku-hero ranku-hero--<?php echo esc_attr( $ranku_season ); ?>">
  <canvas id="ranku-particles" class="ranku-hero__particles" aria-hidden="true"></canvas>
  <div class="ranku-hero__overlay"></div>
  <div class="container ranku-hero__content">
    <div class="ranku-hero__eyebrow">
      <span class="chip"><i class="fa-solid fa-users"></i> <?php esc_html_e( '社群會員排名', 'weixiaoacg' ); ?></span>
      <span class="chip chip--green"><i class="fa-solid fa-clock-rotate-left"></i> <?php esc_html_e( '每小時更新', 'weixiaoacg' ); ?></span>
      <span class="chip chip--gold">
        <i class="fa-solid fa-user-plus"></i>
        <?php echo esc_html( sprintf( __( '目前 %s 位會員', 'weixiaoacg' ), number_format( $total_members ) ) ); ?>
      </span>
      <?php if ( $season_label ) : ?>
        <span class="chip chip--violet"><i class="fa-solid fa-trophy"></i> <?php echo esc_html( $season_label ); ?></span>
      <?php endif; ?>
    </div>
<?php
$hero_title_labels = [
  'exp_total'        => __( '等級排行', 'weixiaoacg' ),
  'rank_season'       => __( '本季牌位排行', 'weixiaoacg' ),
  'rank_last_season'  => __( '上季牌位排行', 'weixiaoacg' ),
  'exp_monthly'       => __( '本月 EXP 榜', 'weixiaoacg' ),
  'login_streak'      => __( '簽到榜', 'weixiaoacg' ),
  'achievements'      => __( '成就榜', 'weixiaoacg' ),
];
$hero_title_text = $hero_title_labels[ $default_tab ] ?? __( '會員排行榜', 'weixiaoacg' );
?>
<h1 class="ranku-hero__title">
  <span class="ranku-hero__trophy">🏆</span>
  <span id="ranku-hero-title-text"><?php echo esc_html( $hero_title_text ); ?></span>
</h1>
    <p class="ranku-hero__subtitle"><?php esc_html_e( '看看誰才是真正的二次元勇者', 'weixiaoacg' ); ?></p>

    <?php if ( ! $is_logged_in ) : ?>
      <div class="ranku-hero__join">
        <?php if ( get_option( 'users_can_register' ) ) : ?>
          <a class="btn btn-primary ranku-hero__join-btn" href="<?php echo esc_url( $register_url ); ?>">
            <i class="fa-solid fa-user-plus"></i> <?php esc_html_e( '免費註冊，一起上榜', 'weixiaoacg' ); ?>
          </a>
        <?php else : ?>
          <a class="btn btn-primary ranku-hero__join-btn" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
            <i class="fa-solid fa-right-to-bracket"></i> <?php esc_html_e( '登入，一起上榜', 'weixiaoacg' ); ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ( $is_logged_in ) :
        $my_pos = function_exists( 'smacg_ranking_user_position' )
            ? smacg_ranking_user_position( $default_tab, $current_uid )
            : null;
    ?>
      <div class="ranku-hero__me" id="ranku-hero-me" data-tab="<?php echo esc_attr( $default_tab ); ?>">
        <span class="ranku-hero__me-label"><?php esc_html_e( '我的名次：', 'weixiaoacg' ); ?></span>
        <span class="ranku-hero__me-pos" id="ranku-me-pos">
          <?php echo $my_pos ? '#' . esc_html( $my_pos ) : esc_html__( '未上榜', 'weixiaoacg' ); ?>
        </span>
      </div>

      <?php if ( $season_info ) :
          $tier = $season_info['tier'];
          $prog = $season_info['progress'];
      ?>
      <div class="ranku-hero__tier" style="--tier-color: <?php echo esc_attr( $tier['color'] ); ?>;">
        <div class="ranku-hero__tier-badge">
          <span class="ranku-hero__tier-icon"><?php echo esc_html( $tier['icon'] ); ?></span>
          <span class="ranku-hero__tier-label"><?php echo esc_html( $tier['label'] ); ?></span>
        </div>
        <div class="ranku-hero__tier-meta">
          <span><?php echo esc_html( number_format( $season_info['score'] ) ); ?> <?php esc_html_e( '賽季積分', 'weixiaoacg' ); ?></span>
          <?php if ( ! $prog['is_max'] ) : ?>
            <span class="ranku-hero__tier-to-next">
              <?php echo esc_html( sprintf( __( '距離 %s 還差 %d 分', 'weixiaoacg' ),
                  $prog['next_label'], $prog['to_next'] ) ); ?>
            </span>
          <?php else : ?>
            <span class="ranku-hero__tier-to-next"><?php esc_html_e( '已達最高段位', 'weixiaoacg' ); ?></span>
          <?php endif; ?>
        </div>
        <div class="ranku-hero__tier-bar">
          <div class="ranku-hero__tier-fill" style="width: <?php echo (int) $prog['percent']; ?>%;"></div>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<section class="ranku-body section">
  <div class="container">
    <div class="ranku-layout">

      <div class="ranku-main">

        <div class="ranku-tabs-row">
          <div class="ranku-tabs" id="ranku-tabs" role="tablist" aria-label="<?php esc_attr_e( '排行榜類別', 'weixiaoacg' ); ?>">
            <?php
            $tabs = [
              'exp_total'        => [ 'fa-bolt',          __( '等級排行', 'weixiaoacg' ) ],
              'rank_season'      => [ 'fa-trophy',        __( '本季牌位排行', 'weixiaoacg' ) ],
              'rank_last_season' => [ 'fa-scroll',        __( '上季牌位排行', 'weixiaoacg' ) ],
              'exp_monthly'      => [ 'fa-calendar-days', __( '本月 EXP 榜', 'weixiaoacg' ) ],
              'login_streak'     => [ 'fa-fire',          __( '簽到榜', 'weixiaoacg' ) ],
              'achievements'     => [ 'fa-medal',         __( '成就榜', 'weixiaoacg' ) ],
            ];
            foreach ( $tabs as $key => $info ) {
                [ $icon, $label ] = $info;
                $active = ( $key === $default_tab ) ? ' active' : '';
                printf(
                    '<button type="button" class="ranku-tab%s" data-type="%s" role="tab" aria-selected="%s"><i class="fa-solid %s"></i> %s</button>',
                    esc_attr( $active ), esc_attr( $key ),
                    $active ? 'true' : 'false',
                    esc_attr( $icon ), esc_html( $label )
                );
            }
            ?>
          </div>
          <div class="ranku-tabs-meta">
            <span class="ranku-count-info" id="ranku-count-info">
              <?php esc_html_e( '等級排行', 'weixiaoacg' ); ?> · Top 100
            </span>
            <?php if ( $season_end_ts > 0 ) : ?>
              <span class="ranku-season-countdown" id="ranku-season-countdown"
                    data-end="<?php echo (int) $season_end_ts; ?>"
                    style="<?php echo ( $default_tab === 'rank_season' ) ? '' : 'display:none;'; ?>">
                <i class="fa-solid fa-hourglass-half"></i>
                <?php esc_html_e( '距離本季結算：', 'weixiaoacg' ); ?>
                <b class="js-ranku-countdown">—</b>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <?php
        /*
         * 分頁說明。
         *
         * 各榜的分數單位不同（EXP／天／粉絲數），只給一個數字使用者分不出
         * 代表什麼——實際收到「簽到榜的數字代表什麼」的疑問才補上。
         * 內容由 leaderboard.js 依分頁切換，這裡先渲染預設分頁的說明，
         * 避免 JS 載入前是空白。
         */
        $ranku_descs = [
            'exp_total'        => __( '依累積 EXP 排名，EXP 只增不減。', 'weixiaoacg' ),
            'rank_season'      => __( '依本季賽季積分排名，每季結算後重新開始。', 'weixiaoacg' ),
            'rank_last_season' => __( '上一季結算後的最終排名。', 'weixiaoacg' ),
            'exp_monthly'      => __( '依本月獲得的 EXP 排名，每月一號重新計算。', 'weixiaoacg' ),
            'login_streak'     => __( '數字是連續簽到天數。登入就自動簽到，中斷一天會從頭算起。', 'weixiaoacg' ),
            'achievements'     => __( '依獲得的成就徽章數量排名。', 'weixiaoacg' ),
        ];
        ?>
        <p class="ranku-tab-desc" id="ranku-tab-desc">
          <?php echo esc_html( $ranku_descs[ $default_tab ] ?? '' ); ?>
        </p>

        <div class="ranku-list" id="ranku-list" aria-live="polite">
          <div class="ranku-loading">
            <?php for ( $i = 0; $i < 5; $i++ ) : ?>
              <div class="skeleton" style="height:78px;border-radius:14px;margin-top:<?php echo $i ? 10 : 0; ?>px;"></div>
            <?php endfor; ?>
          </div>
        </div>

        <div class="ranku-pagination" id="ranku-pagination" hidden>
          <button type="button" class="ranku-page-btn" id="ranku-prev" disabled>
            <i class="fa-solid fa-chevron-left"></i> <?php esc_html_e( '上一頁', 'weixiaoacg' ); ?>
          </button>
          <span class="ranku-page-info" id="ranku-page-info">1 / 1</span>
          <button type="button" class="ranku-page-btn" id="ranku-next" disabled>
            <?php esc_html_e( '下一頁', 'weixiaoacg' ); ?> <i class="fa-solid fa-chevron-right"></i>
          </button>
        </div>

        <p class="ranku-updated-at">
          <i class="fa-regular fa-clock"></i>
          <?php esc_html_e( '最後更新：', 'weixiaoacg' ); ?>
          <span id="ranku-updated-time"><?php echo esc_html( $updated_at ?: '—' ); ?></span>
        </p>

      </div>

      <aside class="ranku-sidebar">

        <?php if ( $showcase_user && $showcase_info ) : ?>
        <div class="ranku-sidebar-card glass-mid ranku-showcase">
          <h3 class="ranku-sidebar-title">
            <i class="fa-solid fa-star" style="color:#ffd76a;"></i>
            <?php esc_html_e( '展示帳號', 'weixiaoacg' ); ?>
          </h3>
          <a class="ranku-showcase-user" href="<?php echo esc_url( function_exists( 'wxacg_get_public_profile_url' ) ? wxacg_get_public_profile_url( $showcase_user->ID ) : '#' ); ?>">
            <img class="ranku-showcase-avatar" src="<?php echo esc_url( get_avatar_url( $showcase_user->ID, [ 'size' => 64 ] ) ); ?>" alt="" />
            <div class="ranku-showcase-meta">
              <span class="ranku-showcase-name">
                <?php echo esc_html( $showcase_user->display_name ); ?>
                <i class="fa-solid fa-circle-check ranku-showcase-verified" title="<?php esc_attr_e( '官方帳號', 'weixiaoacg' ); ?>"></i>
              </span>
              <div class="ranku-showcase-tags">
                <span class="ranku-showcase-tag ranku-showcase-tag--gold">
                  <i class="fa-solid fa-crown"></i>
                  <?php echo esc_html( sprintf( __( 'Lv.%d 滿等', 'weixiaoacg' ), $showcase_info['level'] ) ); ?>
                </span>
                <span class="ranku-showcase-tag ranku-showcase-tag--legend">
                  <i class="fa-solid fa-medal"></i>
                  <?php echo esc_html( sprintf( __( '%d/%d 全成就', 'weixiaoacg' ), $showcase_info['badge_count'], $showcase_badge_total ) ); ?>
                </span>
              </div>
            </div>
          </a>
          <p class="ranku-showcase-note"><?php esc_html_e( '官方帳號僅作展示，不參與排行競賽', 'weixiaoacg' ); ?></p>
        </div>
        <?php endif; ?>

        <div class="ranku-sidebar-card glass-mid">
          <h3 class="ranku-sidebar-title">
            <i class="fa-solid fa-scale-balanced" style="color:var(--accent-cyan);"></i>
            <?php esc_html_e( '排行規則', 'weixiaoacg' ); ?>
          </h3>
          <ul class="ranku-rules">
            <li>
              <i class="fa-solid fa-bolt"></i>
              <strong><?php esc_html_e( '等級排行', 'weixiaoacg' ); ?></strong>
              — <?php esc_html_e( '依註冊以來累計獲得的全部 EXP 排序，反映整體活躍度', 'weixiaoacg' ); ?>
            </li>
            <li>
              <i class="fa-solid fa-trophy"></i>
              <strong><?php esc_html_e( '本季牌位排行', 'weixiaoacg' ); ?></strong>
              — <?php esc_html_e( '當季活躍積分，按 TFT 段位制（鐵～菁英），每季結束結算並重置', 'weixiaoacg' ); ?>
            </li>
            <li>
              <i class="fa-solid fa-scroll"></i>
              <strong><?php esc_html_e( '上季牌位排行', 'weixiaoacg' ); ?></strong>
              —
              <?php if ( $last_season_label ) :
                  echo esc_html( sprintf( __( '%s 結算後的最終排名與段位', 'weixiaoacg' ), $last_season_label ) );
              else :
                  esc_html_e( '上一賽季結算後的最終排名快照', 'weixiaoacg' );
              endif; ?>
            </li>
            <li>
              <i class="fa-solid fa-calendar-days"></i>
              <strong><?php esc_html_e( '本月 EXP 榜', 'weixiaoacg' ); ?></strong>
              — <?php esc_html_e( '只計算當月獲得的 EXP，每月 1 日重新開始，新加入的會員也有機會上榜', 'weixiaoacg' ); ?>
            </li>
            <li>
              <i class="fa-solid fa-users"></i>
              <strong><?php esc_html_e( '人氣榜', 'weixiaoacg' ); ?></strong>
              — <?php esc_html_e( '依被其他會員追蹤的粉絲人數排序', 'weixiaoacg' ); ?>
            </li>
            <li>
              <i class="fa-solid fa-medal"></i>
              <strong><?php esc_html_e( '成就榜', 'weixiaoacg' ); ?></strong>
              — <?php esc_html_e( '依已解鎖的成就總數排序，反映達成過的各項成就', 'weixiaoacg' ); ?>
            </li>
          </ul>
          <a href="<?php echo esc_url( $guide_url ); ?>" class="ranku-guide-link">
            <i class="fa-solid fa-book"></i>
            <?php esc_html_e( '查看完整規則指南', 'weixiaoacg' ); ?>
            <i class="fa-solid fa-arrow-right"></i>
          </a>
        </div>

        <?php if ( ! $is_logged_in ) : ?>
        <div class="ranku-sidebar-card glass-mid ranku-cta">
          <h3 class="ranku-sidebar-title">
            <i class="fa-solid fa-rocket" style="color:var(--accent-cyan);"></i>
            <?php esc_html_e( '加入競爭', 'weixiaoacg' ); ?>
          </h3>
          <p class="ranku-cta-count">
            <i class="fa-solid fa-users"></i>
            <?php echo esc_html( sprintf( __( '已有 %s 位勇者加入', 'weixiaoacg' ), number_format( $total_members ) ) ); ?>
          </p>
          <p style="font-size:13px;color:var(--text-muted);line-height:1.7;margin:0 0 12px;">
            <?php esc_html_e( '註冊後追蹤動漫、發表評分與留言即可累積 EXP，挑戰榜首！', 'weixiaoacg' ); ?>
          </p>
          <?php if ( get_option( 'users_can_register' ) ) : ?>
          <a class="btn btn-primary btn-block" href="<?php echo esc_url( $register_url ); ?>">
            <i class="fa-solid fa-user-plus"></i> <?php esc_html_e( '免費註冊', 'weixiaoacg' ); ?>
          </a>
          <a class="btn btn-block ranku-cta-login" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
            <i class="fa-solid fa-right-to-bracket"></i> <?php esc_html_e( '已有帳號？登入', 'weixiaoacg' ); ?>
          </a>
          <?php else : ?>
          <a class="btn btn-primary btn-block" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
            <i class="fa-solid fa-right-to-bracket"></i> <?php esc_html_e( '立即登入', 'weixiaoacg' ); ?>
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </aside>
    </div>
  </div>
</section>

<div class="toast-container" id="ranku-toast-container" aria-live="polite"></div>

<style>
/* ============================================================
   規則卡「完整規則」連結
   ============================================================ */
.ranku-guide-link {
  display: flex; align-items: center; gap: 8px;
  margin-top: 14px; padding: 10px 14px;
  background: rgba(40, 200, 214, 0.08);
  border: 1px solid rgba(40, 200, 214, 0.25);
  border-radius: 10px;
  color: var(--accent-cyan, #28c8d6);
  font-size: 13px; font-weight: 600;
  text-decoration: none;
  transition: all .2s ease;
}
.ranku-guide-link:hover { background: rgba(40,200,214,0.15); transform: translateX(2px); }
.ranku-guide-link i:last-child { margin-left: auto; }

/* 側欄 CTA 會員數 */
.ranku-cta-count {
  display: flex; align-items: center; gap: 8px;
  margin: 0 0 10px; padding: 8px 12px;
  border-radius: 10px;
  font-size: 14px; font-weight: 700;
}
.ranku-cta-login {
  margin-top: 8px;
  background: transparent;
  border: 1px solid rgba(255,255,255,0.15);
  color: var(--text-muted, #aaa);
  font-size: 13px;
}
.ranku-cta-login:hover { background: rgba(255,255,255,0.06); }

/* ============================================================
   ★ 關鍵修復：隱藏 JS 繪製的 canvas（藍色背景 + 過高元兇）
   ============================================================ */
.ranku-hero__particles,
#ranku-particles,
.ranku-hero__overlay {
    display: none !important;
}

/* ============================================================
   Hero 四季奢華版 v5.0（純 CSS，高度/顏色完全可控）
   ============================================================ */
.ranku-hero {
    display: block !important;
    position: relative;
    overflow: hidden;
    height: auto !important;
    min-height: 0 !important;
    padding: 40px 24px !important;
    margin: 0 0 32px;
    border-radius: 22px;
    background-color: #0b0b0f;
    box-shadow:
        0 18px 50px rgba(0,0,0,0.5),
        inset 0 1px 0 rgba(255,255,255,0.08);
    text-align: center;
}

/* 細金邊框（奢華感） */
.ranku-hero::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 22px;
    padding: 1.5px;
    background: linear-gradient(135deg,
        var(--ranku-edge, rgba(212,175,55,0.6)),
        rgba(255,255,255,0.08) 45%,
        var(--ranku-edge, rgba(212,175,55,0.35)));
    -webkit-mask:
        linear-gradient(#fff 0 0) content-box,
        linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
    z-index: 1;
}

/* 緩慢旋轉的奢華光暈 */
.ranku-hero::before {
    content: "";
    position: absolute;
    top: 50%; left: 50%;
    width: 140%; padding-bottom: 140%;
    transform: translate(-50%, -50%);
    background: conic-gradient(from 0deg,
        transparent 0deg, var(--ranku-glow, rgba(212,175,55,0.18)) 55deg,
        transparent 130deg, var(--ranku-glow2, rgba(255,255,255,0.10)) 210deg,
        transparent 300deg);
    animation: rankuLuxSpin 22s linear infinite;
    pointer-events: none;
    z-index: 0;
    opacity: 0.85;
}
@keyframes rankuLuxSpin { to { transform: translate(-50%,-50%) rotate(360deg); } }

/* ---- 內容置中接管（修跑版核心） ---- */
.ranku-hero .ranku-hero__content,
.ranku-hero__content.container {
    position: relative !important;
    z-index: 2 !important;
    width: 100% !important;
    max-width: 820px !important;
    margin: 0 auto !important;
    padding: 0 16px !important;
    float: none !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    gap: 16px !important;
}

/* eyebrow chip 群 */
.ranku-hero__eyebrow {
    display: flex !important;
    flex-wrap: wrap !important;
    justify-content: center !important;
    align-items: center !important;
    gap: 10px !important;
    width: 100%;
    margin: 0 !important;
}

/* ---- 四季配色（顏色分明版）---- */

/* 🌸 春 · 櫻花粉 */
.ranku-hero--spring {
    --ranku-glow:  rgba(255,145,180,0.28);
    --ranku-glow2: rgba(255,200,220,0.18);
    --ranku-edge:  rgba(255,150,190,0.65);
    background-image:
        radial-gradient(ellipse at 20% 115%, rgba(255,130,175,0.40), transparent 55%),
        radial-gradient(ellipse at 85% -15%, rgba(255,190,215,0.32), transparent 55%);
}

/* 🌊 夏 · 湛藍海洋 */
.ranku-hero--summer {
    --ranku-glow:  rgba(30,140,255,0.30);
    --ranku-glow2: rgba(0,200,255,0.20);
    --ranku-edge:  rgba(40,160,255,0.65);
    background-image:
        radial-gradient(ellipse at 20% 115%, rgba(0,120,255,0.42), transparent 55%),
        radial-gradient(ellipse at 85% -15%, rgba(0,190,255,0.34), transparent 55%);
}

/* 🍁 秋 · 楓紅菊黃 */
.ranku-hero--autumn {
    --ranku-glow:  rgba(230,90,20,0.30);
    --ranku-glow2: rgba(255,180,40,0.22);
    --ranku-edge:  rgba(235,110,30,0.65);
    background-image:
        radial-gradient(ellipse at 20% 115%, rgba(215,65,15,0.42), transparent 55%),
        radial-gradient(ellipse at 85% -15%, rgba(255,175,35,0.34), transparent 55%);
}

/* ❄️ 冬 · 純白雪 */
.ranku-hero--winter {
    --ranku-glow:  rgba(255,255,255,0.28);
    --ranku-glow2: rgba(200,225,255,0.20);
    --ranku-edge:  rgba(255,255,255,0.7);
    background-image:
        radial-gradient(ellipse at 20% 115%, rgba(235,245,255,0.40), transparent 55%),
        radial-gradient(ellipse at 85% -15%, rgba(180,215,255,0.30), transparent 55%);
}

/* ---- 標題：金屬流光 ---- */
.ranku-hero__title {
    margin: 4px 0 !important;
    font-weight: 900 !important;
    letter-spacing: 1.5px;
    text-align: center !important;
    background: linear-gradient(90deg, #fff, #f4e2a1, #d4af37, #f4e2a1, #fff);
    background-size: 200% auto;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    filter: drop-shadow(0 2px 12px rgba(0,0,0,0.5));
    animation: rankuLuxShine 6s linear infinite;
}
@keyframes rankuLuxShine { to { background-position: 200% center; } }
.ranku-hero__title .ranku-hero__trophy { -webkit-text-fill-color: initial; }

.ranku-hero__subtitle {
    margin: 0 !important;
    color: rgba(255,255,255,0.80) !important;
    letter-spacing: 0.5px;
    text-align: center !important;
    text-shadow: 0 1px 8px rgba(0,0,0,0.5);
}

/* ---- Chip：磨砂玻璃 + 細金邊 ---- */
.ranku-hero__eyebrow .chip {
    font-size: 14.5px !important;
    font-weight: 700 !important;
    padding: 7px 16px !important;
    color: #fff !important;
    background: rgba(255,255,255,0.07) !important;
    border: 1px solid rgba(255,255,255,0.18) !important;
    border-radius: 999px;
    backdrop-filter: blur(10px);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.22), 0 4px 12px rgba(0,0,0,0.3);
    transition: transform .25s ease, box-shadow .25s ease;
}
.ranku-hero__eyebrow .chip:hover {
    transform: translateY(-2px);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.4), 0 6px 18px rgba(0,0,0,0.45);
}
/* 金色會員數 chip */
.ranku-hero__eyebrow .chip.chip--gold,
.ranku-cta-count {
    background: linear-gradient(135deg, rgba(212,175,55,0.28), rgba(255,255,255,0.05)) !important;
    border-color: rgba(212,175,55,0.55) !important;
    color: #ffe9a8 !important;
}

/* ---- 我的名次 / 段位卡置中 ---- */
.ranku-hero__me {
    display: flex !important;
    align-items: center; justify-content: center;
    gap: 8px; margin: 0 auto !important;
    color: rgba(255,255,255,0.9);
}
.ranku-hero__me-pos { font-weight: 800; color: #ffe9a8; }
.ranku-hero__tier { margin: 6px auto 0 !important; }

/* ---- 註冊按鈕：香檳金 ---- */
.ranku-hero__join { display: flex !important; justify-content: center; margin: 6px auto 0 !important; }
.ranku-hero__join .btn-primary,
.ranku-hero__join a.btn-primary {
    font-weight: 800 !important;
    color: #1a1206 !important;
    border: none !important;
    background: linear-gradient(135deg, #f4e2a1, #d4af37 55%, #b8860b) !important;
    box-shadow: 0 4px 18px rgba(212,175,55,0.4) !important;
    transition: transform .2s ease, box-shadow .2s ease;
}
.ranku-hero__join .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 26px rgba(212,175,55,0.65) !important;
}

/* ============================================================
   ★ v2.4.0 新增：Tabs 列旁的「距離本季結算」倒數
   ============================================================ */
.ranku-tabs-meta {
    display: flex !important;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.ranku-season-countdown {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 999px;
    color: #ffd88a;
    background: rgba(255, 183, 3, 0.12);
    border: 1px solid rgba(255, 183, 3, 0.3);
}
.ranku-season-countdown i { font-size: 12px; }
.ranku-season-countdown b { color: #ffe9a8; }

/* ============================================================
   ★ v2.6.0 新增：展示帳號卡片（官方帳號，全成就滿等）
   ============================================================ */
.ranku-showcase-user {
    display: flex; align-items: center; gap: 12px;
    text-decoration: none; color: inherit;
    padding: 4px 0;
}
.ranku-showcase-avatar {
    width: 56px; height: 56px; border-radius: 50%;
    border: 2px solid rgba(212,175,55,.6);
    box-shadow: 0 0 14px rgba(212,175,55,.35);
    flex-shrink: 0;
}
.ranku-showcase-meta { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.ranku-showcase-name {
    font-size: 14.5px; font-weight: 700; color: #fff;
    display: flex; align-items: center; gap: 6px;
}
.ranku-showcase-verified { color: #3a86ff; font-size: 13px; }
.ranku-showcase-tags { display: flex; flex-wrap: wrap; gap: 6px; }
.ranku-showcase-tag {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700; padding: 3px 9px;
    border-radius: 999px; white-space: nowrap;
}
.ranku-showcase-tag--gold {
    background: linear-gradient(135deg, rgba(212,175,55,.28), rgba(255,255,255,.05));
    border: 1px solid rgba(212,175,55,.55); color: #ffe9a8;
}
.ranku-showcase-tag--legend {
    background: linear-gradient(90deg, rgba(255,0,150,.18), rgba(0,180,255,.18), rgba(160,0,255,.18));
    background-size: 200% auto;
    border: 1px solid rgba(200,120,255,.5); color: #f0d9ff;
    animation: rankuLegendShift 4s linear infinite;
}
@keyframes rankuLegendShift { to { background-position: 200% center; } }
.ranku-showcase-note {
    margin: 10px 0 0; font-size: 11.5px; color: var(--text-muted,#9ca3af);
    line-height: 1.6;
}

/* ---- 手機端 ---- */
@media (max-width: 640px) {
    .ranku-hero { padding: 28px 16px !important; }
    .ranku-hero__eyebrow .chip { font-size: 13px !important; padding: 6px 13px !important; }
    .ranku-season-countdown { font-size: 12px; padding: 5px 10px; }
}
</style>

<?php get_footer(); ?>