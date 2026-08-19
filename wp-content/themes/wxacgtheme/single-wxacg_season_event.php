<?php
/**
 * Single Template — wxacg_season_event
 *
 * @package weixiaoacg
 * @version 1.0.0 (2026-05-14)  Batch 2B-5
 *
 * 顯示：
 *   - Hero（標題 / banner / 狀態 / 倒數計時）
 *   - 任務說明 + 我的進度（已登入）
 *   - 獎勵說明
 *   - Top 50 進度榜（即時讀 wp_smacg_event_progress；活動結束後讀 snapshot）
 *   - 規則 / 結束時間提示
 */

if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

while ( have_posts() ) : the_post();

    $post_id = get_the_ID();
    $meta    = smacg_get_event_meta( $post_id );
    $status  = $meta['status'];

    /*
     * 函式名稱以 wxacg-gamification 的 includes/compat/legacy-functions.php 為準。
     *
     * 本模板原本呼叫 smacg_event_get_user_progress() / smacg_event_counts() /
     * smacg_event_top_progress()，那三個名稱在整個外掛裡都不存在——相容層提供的
     * 是 smacg_get_user_event_progress() / smacg_get_event_progress_count() /
     * smacg_get_event_leaderboard()，是命名不一致而非缺功能。
     *
     * 因為 wxacg_season_event 目前 0 篇文章，這個模板從未被執行過，所以錯誤
     * 一直沒有浮現；一旦建立第一篇活動就會是白畫面（未定義函式 fatal error）。
     */
    $task_info = [ 'label' => $meta['task_type'] ?? '', 'unit' => '', 'desc' => '' ];

    $is_logged_in = is_user_logged_in();
    $current_uid  = get_current_user_id();
    $my_progress  = $is_logged_in ? smacg_get_user_event_progress( $post_id, $current_uid ) : null;
    $counts       = smacg_get_event_progress_count( $post_id );

    // Top progress：結束後讀快照，進行中讀即時
    if ( $status === 'ended' ) {
        $top = (array) get_post_meta( $post_id, '_smacg_event_final_snapshot', true );
        if ( empty( $top ) ) $top = smacg_get_event_leaderboard( $post_id, 50 );
    } else {
        $top = smacg_get_event_leaderboard( $post_id, 50 );
    }

    $status_zh = [
        'upcoming' => [ '即將開始', '#3b82f6' ],
        'active'   => [ '進行中',   '#10b981' ],
        'ended'    => [ '已結束',   '#6b7280' ],
        'invalid'  => [ '時間未設', '#ef4444' ],
    ];
    $s = $status_zh[ $status ] ?? [ $status, '#666' ];
?>

<!-- ================================================================
     HERO
     ================================================================ -->
<section class="evt-hero" <?php if ( $meta['banner_url'] ) echo 'style="background-image:linear-gradient(rgba(15,12,36,.85),rgba(8,6,26,.95)), url(' . esc_url( $meta['banner_url'] ) . ');background-size:cover;background-position:center;"'; ?>>
  <div class="container evt-hero__content">

    <div class="evt-hero__eyebrow">
      <span class="evt-status-chip" style="background:<?php echo esc_attr( $s[1] ); ?>;">
        <?php echo esc_html( $s[0] ); ?>
      </span>
      <span class="chip">
        <i class="fa-solid fa-flag"></i> <?php echo esc_html( $task_info['label'] ); ?>
      </span>
      <?php if ( $meta['max_participants'] > 0 ) : ?>
      <span class="chip chip--warn">
        <i class="fa-solid fa-user-clock"></i> 限前 <?php echo (int) $meta['max_participants']; ?> 名
      </span>
      <?php endif; ?>
    </div>

    <h1 class="evt-hero__title">🏆 <?php the_title(); ?></h1>

    <?php if ( $meta['excerpt'] ) : ?>
    <p class="evt-hero__subtitle"><?php echo esc_html( $meta['excerpt'] ); ?></p>
    <?php endif; ?>

    <!-- 倒數計時 / 結束顯示 -->
    <div class="evt-countdown" id="evt-countdown"
         data-status="<?php echo esc_attr( $status ); ?>"
         data-start="<?php echo esc_attr( $meta['start'] ? strtotime( $meta['start'] ) : '' ); ?>"
         data-end="<?php echo esc_attr( $meta['end']   ? strtotime( $meta['end'] )   : '' ); ?>">
      <div class="evt-countdown__label">
        <?php
        if ( $status === 'upcoming' ) esc_html_e( '距離開始還有', 'weixiaoacg' );
        elseif ( $status === 'active' ) esc_html_e( '距離結束還有', 'weixiaoacg' );
        else esc_html_e( '活動於以下時間結束', 'weixiaoacg' );
        ?>
      </div>
      <?php if ( $status === 'ended' ) : ?>
        <div class="evt-countdown__ended">
          <i class="fa-solid fa-flag-checkered"></i>
          <?php echo esc_html( mysql2date( 'Y-m-d H:i', $meta['end'] ) ); ?>
        </div>
      <?php else : ?>
        <div class="evt-countdown__nums">
          <div><span id="evt-d">--</span><small>天</small></div>
          <div><span id="evt-h">--</span><small>時</small></div>
          <div><span id="evt-m">--</span><small>分</small></div>
          <div><span id="evt-s">--</span><small>秒</small></div>
        </div>
      <?php endif; ?>
    </div>

  </div>
</section>

<!-- ================================================================
     主體
     ================================================================ -->
<section class="evt-body section">
  <div class="container">
    <div class="evt-layout">

      <!-- ============= 左欄：任務 + 我的進度 + 內文 ============= -->
      <div class="evt-main">

        <!-- 任務說明 + 我的進度 -->
        <div class="evt-card glass-mid">
          <h2 class="evt-card__title">
            <i class="fa-solid fa-bullseye" style="color:var(--accent-cyan);"></i>
            任務說明
          </h2>
          <p class="evt-task-desc"><?php echo esc_html( $task_info['desc'] ); ?></p>
          <div class="evt-task-target">
            目標：<strong><?php echo number_format( $meta['task_target'] ); ?></strong>
            <span><?php echo esc_html( $task_info['unit'] ); ?></span>
          </div>

          <?php if ( $is_logged_in && $status !== 'upcoming' ) : ?>
          <!-- 我的進度 -->
          <div class="evt-my-progress">
            <div class="evt-progress-head">
              <span class="evt-progress-label">我的進度</span>
              <span class="evt-progress-num">
                <strong><?php echo number_format( $my_progress['progress'] ); ?></strong>
                / <?php echo number_format( $my_progress['target'] ); ?>
                <small><?php echo esc_html( $task_info['unit'] ); ?></small>
              </span>
            </div>
            <div class="evt-progress-bar">
              <div class="evt-progress-fill" style="width:<?php echo esc_attr( $my_progress['percent'] ); ?>%;"></div>
            </div>
            <div class="evt-progress-meta">
              <?php if ( $my_progress['over_limit'] ) : ?>
                <span class="evt-progress-status evt-progress-status--err">
                  <i class="fa-solid fa-circle-exclamation"></i> 已達標但名額已滿
                </span>
              <?php elseif ( $my_progress['awarded_at'] ) : ?>
                <span class="evt-progress-status evt-progress-status--ok">
                  <i class="fa-solid fa-check-circle"></i> 已達標並領取獎勵（<?php echo esc_html( mysql2date( 'Y-m-d H:i', $my_progress['awarded_at'] ) ); ?>）
                </span>
              <?php elseif ( $my_progress['reached_at'] ) : ?>
                <span class="evt-progress-status evt-progress-status--ok">
                  <i class="fa-solid fa-clock"></i> 已達標，獎勵稍後發放
                </span>
              <?php else : ?>
                <span class="evt-progress-status">
                  <?php echo esc_html( $my_progress['percent'] ); ?>% · 還差 <strong><?php echo number_format( max( 0, $my_progress['target'] - $my_progress['progress'] ) ); ?></strong> <?php echo esc_html( $task_info['unit'] ); ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
          <?php elseif ( ! $is_logged_in ) : ?>
          <div class="evt-login-cta">
            <i class="fa-solid fa-lock"></i>
            <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">登入</a>
            後即可參加活動並追蹤進度
          </div>
          <?php elseif ( $status === 'upcoming' ) : ?>
          <div class="evt-login-cta">
            <i class="fa-solid fa-hourglass-start"></i>
            活動尚未開始，敬請期待
          </div>
          <?php endif; ?>
        </div>

        <!-- 文章內容（活動詳細說明） -->
        <?php if ( get_the_content() ) : ?>
        <div class="evt-card glass-mid">
          <h2 class="evt-card__title">
            <i class="fa-solid fa-circle-info" style="color:var(--accent-violet);"></i>
            活動詳情
          </h2>
          <div class="evt-content"><?php the_content(); ?></div>
        </div>
        <?php endif; ?>

        <!-- Top 50 排行 -->
        <div class="evt-card glass-mid">
          <h2 class="evt-card__title">
            <i class="fa-solid fa-ranking-star" style="color:var(--accent-cyan);"></i>
            <?php echo $status === 'ended' ? '最終排行' : '即時排行'; ?>
            <span class="evt-card__count">(<?php echo $counts['total']; ?> 位參與 / <?php echo $counts['reached']; ?> 位達標)</span>
          </h2>

          <?php if ( empty( $top ) ) : ?>
            <p class="evt-empty">
              <i class="fa-solid fa-hourglass-half"></i>
              還沒有人參加，成為第一個吧！
            </p>
          <?php else : ?>
          <ol class="evt-rank-list">
            <?php foreach ( $top as $i => $r ) :
                $rk_uid  = (int) $r['user_id'];
                $u       = get_user_by( 'id', $rk_uid );
                if ( ! $u ) continue;
                $rk_pos  = $i + 1;
                $display = $u->display_name ?: $u->user_login;
                $pct     = min( 100, round( $r['progress'] / max( 1, $meta['task_target'] ) * 100, 1 ) );
                $is_me   = $rk_uid === $current_uid;
                $medal   = $rk_pos === 1 ? '🥇' : ( $rk_pos === 2 ? '🥈' : ( $rk_pos === 3 ? '🥉' : '#' . $rk_pos ) );
                $profile = function_exists( 'wxacg_get_public_profile_url' )
                    ? wxacg_get_public_profile_url( $u->user_login )
                    : '#';
            ?>
            <li class="evt-rank-row evt-rank-row--<?php echo $rk_pos; ?> <?php echo $is_me ? 'evt-rank-row--me' : ''; ?>">
              <span class="evt-rank-pos"><?php echo $medal; ?></span>
              <a class="evt-rank-avatar" href="<?php echo esc_url( $profile ); ?>">
                <img src="<?php echo esc_url( get_avatar_url( $rk_uid, [ 'size' => 64 ] ) ); ?>" alt="" loading="lazy">
              </a>
              <div class="evt-rank-info">
                <a class="evt-rank-name" href="<?php echo esc_url( $profile ); ?>">
                  <?php echo esc_html( $display ); ?>
                </a>
                <div class="evt-rank-prog">
                  <div class="evt-rank-bar"><div class="evt-rank-fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></div></div>
                  <span class="evt-rank-pct"><?php echo esc_html( $pct ); ?>%</span>
                </div>
              </div>
              <div class="evt-rank-score">
                <?php echo number_format( $r['progress'] ); ?>
                <small><?php echo esc_html( $task_info['unit'] ); ?></small>
                <?php if ( ! empty( $r['awarded_at'] ) ) : ?>
                  <span class="evt-rank-done"><i class="fa-solid fa-check-circle"></i></span>
                <?php elseif ( ! empty( $r['reached_at'] ) ) : ?>
                  <span class="evt-rank-done evt-rank-done--pending"><i class="fa-solid fa-clock"></i></span>
                <?php endif; ?>
              </div>
            </li>
            <?php endforeach; ?>
          </ol>
          <?php endif; ?>
        </div>

      </div>

      <!-- ============= 右欄：獎勵 + 規則 ============= -->
      <aside class="evt-sidebar">

        <!-- 獎勵卡 -->
        <div class="evt-card glass-mid evt-reward-card">
          <h3 class="evt-card__title">
            <i class="fa-solid fa-gift" style="color:#fbbf24;"></i>
            活動獎勵
          </h3>
          <ul class="evt-reward-list">
            <?php if ( $meta['reward_exp'] > 0 ) : ?>
              <li>
                <span class="evt-reward-icon">⚡</span>
                <div class="evt-reward-info">
                  <strong>+<?php echo number_format( $meta['reward_exp'] ); ?> EXP</strong>
                  <small>立即生效</small>
                </div>
              </li>
            <?php endif; ?>
            <?php if ( $meta['reward_badge'] > 0 ) : ?>
              <li>
                <span class="evt-reward-icon">🏅</span>
                <div class="evt-reward-info">
                  <strong><?php echo esc_html( get_the_title( $meta['reward_badge'] ) ?: '專屬成就' ); ?></strong>
                  <small>永久收藏</small>
                </div>
              </li>
            <?php endif; ?>
            <?php if ( $meta['reward_title'] ) : ?>
              <li>
                <span class="evt-reward-icon">👑</span>
                <div class="evt-reward-info">
                  <strong><?php echo esc_html( $meta['reward_title'] ); ?></strong>
                  <small>顯示於個人頁與留言區</small>
                </div>
              </li>
            <?php endif; ?>
          </ul>
          <?php if ( $meta['max_participants'] > 0 ) : ?>
          <p class="evt-limit-note">
            <i class="fa-solid fa-trophy"></i>
            先達標先得，限前 <strong><?php echo (int) $meta['max_participants']; ?></strong> 名（已達標 <?php echo $counts['reached']; ?>）
          </p>
          <?php endif; ?>
        </div>

        <!-- 規則 -->
        <div class="evt-card glass-mid">
          <h3 class="evt-card__title">
            <i class="fa-solid fa-book" style="color:var(--accent-cyan);"></i>
            活動規則
          </h3>
          <ul class="evt-rules">
            <li>活動期間：<?php echo esc_html( mysql2date( 'Y-m-d H:i', $meta['start'] ) ); ?> ~ <?php echo esc_html( mysql2date( 'Y-m-d H:i', $meta['end'] ) ); ?></li>
            <li>進度於對應事件發生時自動累計，無需報名</li>
            <li>達標後系統自動發放獎勵；偶有延遲，最長 10 分鐘內補發</li>
            <li>排行依進度由高至低排序；同進度按達標時間早者優先</li>
            <?php if ( $meta['max_participants'] > 0 ) : ?>
            <li>限前 <?php echo (int) $meta['max_participants']; ?> 名達標者獲獎，後續達標者不再發放</li>
            <?php endif; ?>
          </ul>
        </div>

        <!-- 相關活動入口 -->
        <div class="evt-card glass-mid">
          <h3 class="evt-card__title">
            <i class="fa-solid fa-list" style="color:var(--accent-violet);"></i>
            更多活動
          </h3>
          <a class="btn btn-primary btn-block" href="<?php echo esc_url( get_post_type_archive_link( WXACG_EVENT_CPT ) ); ?>">
            <i class="fa-solid fa-grip"></i> 查看全部活動
          </a>
        </div>

      </aside>
    </div>
  </div>
</section>

<?php endwhile; ?>

<!-- 倒數計時 inline JS（單獨頁面，不獨立成檔） -->
<script>
(function(){
  const el = document.getElementById('evt-countdown');
  if (!el) return;
  const status = el.dataset.status;
  const start  = parseInt(el.dataset.start || '0', 10) * 1000;
  const end    = parseInt(el.dataset.end   || '0', 10) * 1000;
  if (status === 'ended' || !end) return;

  const d = document.getElementById('evt-d');
  const h = document.getElementById('evt-h');
  const m = document.getElementById('evt-m');
  const s = document.getElementById('evt-s');
  if (!d) return;

  function tick(){
    const now = Date.now();
    const target = (status === 'upcoming') ? start : end;
    let diff = Math.max(0, target - now);
    if (diff === 0){ d.textContent='0';h.textContent='00';m.textContent='00';s.textContent='00'; return; }
    const dd = Math.floor(diff/86400000); diff -= dd*86400000;
    const hh = Math.floor(diff/3600000);  diff -= hh*3600000;
    const mm = Math.floor(diff/60000);    diff -= mm*60000;
    const ss = Math.floor(diff/1000);
    d.textContent = dd;
    h.textContent = String(hh).padStart(2,'0');
    m.textContent = String(mm).padStart(2,'0');
    s.textContent = String(ss).padStart(2,'0');
  }
  tick();
  setInterval(tick, 1000);
})();
</script>

<?php get_footer(); ?>
