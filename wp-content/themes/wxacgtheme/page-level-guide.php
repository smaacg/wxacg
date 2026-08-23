<?php
/**
 * Template Name: 等級・積分・段位 完整指南
 *
 * v2.2.0 (2026-05-22)
 *   - 新增 §1.5「段位積分來源」（id=#season-score）：動態列出
 *     Exp_Config::rules() 中 season_score > 0 的規則，與 §1 EXP 來源
 *     並列但分區呈現「永久 EXP vs. 賽季積分」雙軌。
 *   - TOC 加入「🏆 段位積分」chip。
 *   - §5 賽季時程補上重置範圍提醒（EXP 永久、賽季分歸零）。
 *
 * v2.1.0 (2026-05-22)
 *   - 等級公式修正為 ⌊√(EXP/50)⌋；大師 7600 → 5000；
 *     段位區段 anchor 改為 #tier；段位卡片改為動態讀取。
 *
 * v2.0.0 (2026-05-15)
 *   - 採新版 sqrt 公式 + 6 階會員稱號 + 8 職業 × 4 階稱號
 *
 * @package blocksy-child
 */
defined( 'ABSPATH' ) || exit;

get_header();

/* ── 資料源（外掛未啟用時提供安全 fallback）── */
$exp_rules    = function_exists( 'smacg_get_all_exp_rules' )         ? smacg_get_all_exp_rules()         : [];
$member_tiers = function_exists( 'smacg_get_all_member_tiers' )      ? smacg_get_all_member_tiers()      : [];
$rank_tiers   = function_exists( 'smacg_get_all_rank_tiers' )        ? smacg_get_all_rank_tiers()        : [];
$seasons      = function_exists( 'smacg_get_all_seasons_schedule' )  ? smacg_get_all_seasons_schedule()  : [];
$jobs         = function_exists( 'smacg_get_jobs' )                  ? smacg_get_jobs()                  : [];
$milestones   = function_exists( 'smacg_get_career_milestones' )     ? smacg_get_career_milestones()     : [];

/* 使用者進度（未登入則為 null） */
$me_uid       = get_current_user_id();
$me_lvl       = ( $me_uid && function_exists( 'wxacg_get_user_level_info' ) )       ? wxacg_get_user_level_info( $me_uid )       : null;
$me_rank      = ( $me_uid && function_exists( 'smacg_get_user_rank_season_info' ) ) ? smacg_get_user_rank_season_info( $me_uid ) : null;
$me_job       = ( $me_uid && function_exists( 'smacg_get_user_job_title' ) )        ? smacg_get_user_job_title( $me_uid )        : [];

$current_season_code  = function_exists( 'smacg_get_current_season_code' ) ? smacg_get_current_season_code() : '';
$current_season_label = function_exists( 'smacg_get_season_label' )        ? smacg_get_season_label()        : '';

/* ── v2.2.0：從 $exp_rules 過濾出有賽季分的規則 ── */
$season_rules = [];
if ( ! empty( $exp_rules ) && is_array( $exp_rules ) ) {
    foreach ( $exp_rules as $rule ) {
        $ss = (int) ( $rule['season_score'] ?? 0 );
        if ( $ss > 0 ) {
            $season_rules[] = $rule;
        }
    }
}

/* ── 將 29 階 TIERS 收斂為 10 階展示 ── */
$lg_rank_groups = [];
if ( ! empty( $rank_tiers ) && is_array( $rank_tiers ) ) {
    $names = [
        'iron'     => [ '鐵',   '🥉', '#6b6b6b' ],
        'bronze'   => [ '銅',   '🟫', '#a97142' ],
        'silver'   => [ '銀',   '⚪', '#b8b8b8' ],
        'gold'     => [ '金',   '🟡', '#f0c040' ],
        'platinum' => [ '白金', '🟦', '#4ad6c0' ],
        'emerald'  => [ '翡翠', '🟢', '#28b463' ],
        'diamond'  => [ '鑽石', '💎', '#5dade2' ],
    ];
    foreach ( $names as $k => $meta ) {
        $rows = array_values( array_filter( $rank_tiers, function ( $r ) use ( $k ) {
            return ( $r['key'] ?? '' ) === $k;
        } ) );
        if ( empty( $rows ) ) continue;
        $min   = (int) $rows[0]['min'];
        $next  = null;
        $keys_order = array_keys( $names );
        $idx        = array_search( $k, $keys_order, true );
        $next_key   = $keys_order[ $idx + 1 ] ?? 'master';
        foreach ( $rank_tiers as $r ) {
            if ( ( $r['key'] ?? '' ) === $next_key ) {
                $next = (int) $r['min'];
                break;
            }
        }
        $divs_text = implode( ' ‧ ', array_map( function ( $r ) {
            return sprintf( '%s %d', $r['division'] ?: '', (int) $r['min'] );
        }, $rows ) );
        $lg_rank_groups[] = [
            'key'   => $k,
            'name'  => $meta[0],
            'icon'  => $meta[1],
            'color' => $meta[2],
            'range' => $next ? sprintf( '%d – %d', $min, $next - 1 ) : sprintf( '%d+', $min ),
            'divs'  => trim( $divs_text ),
            'note'  => '',
            'apex'  => false,
        ];
    }
    $master_min = 5000;
    foreach ( $rank_tiers as $r ) {
        if ( ( $r['key'] ?? '' ) === 'master' ) { $master_min = (int) $r['min']; break; }
    }
    $lg_rank_groups[] = [
        'key' => 'master',      'name' => '大師', 'icon' => '👑', 'color' => '#bb8fce',
        'range' => $master_min . '+ 分', 'divs' => '', 'note' => '達門檻但未進前 200 名', 'apex' => true,
    ];
    $lg_rank_groups[] = [
        'key' => 'grandmaster', 'name' => '宗師', 'icon' => '🔥', 'color' => '#ff9f43',
        'range' => $master_min . '+ 分', 'divs' => '', 'note' => '全站名次 51 – 200', 'apex' => true,
    ];
    $lg_rank_groups[] = [
        'key' => 'challenger',  'name' => '菁英', 'icon' => '⚡', 'color' => '#ff6b6b',
        'range' => $master_min . '+ 分', 'divs' => '', 'note' => '全站名次 1 – 50', 'apex' => true,
    ];
}
?>

<main id="primary" class="site-main level-guide-page" data-current-season="<?php echo esc_attr( $current_season_code ); ?>">
  <div class="container">

    <!-- ===== Hero ===== -->
    <header class="guide-hero">
      <h1 class="guide-hero-title">等級・積分・段位 完整指南</h1>
      <p class="guide-hero-sub">
        了解微笑動漫的會員成長系統 — 從新進會員到黑卡會員，從學生到逍遙之神。
      </p>
      <nav class="guide-toc" aria-label="目錄">
        <a href="#exp"          class="guide-toc-chip">⚡ EXP 來源</a>
        <a href="#season-score" class="guide-toc-chip">🏆 段位積分</a>
        <a href="#level"        class="guide-toc-chip">📊 等級稱號</a>
        <a href="#career"       class="guide-toc-chip">🎯 職業天命</a>
        <a href="#tier"         class="guide-toc-chip">🏅 賽季段位</a>
        <a href="#season"       class="guide-toc-chip">🗓️ 賽季時程</a>
        <a href="#achievements" class="guide-toc-chip">🎖️ 成就收集</a>
      </nav>

      <?php if ( $me_lvl ): ?>
        <aside class="guide-me-card" aria-label="我的目前進度">
          <div class="guide-me-row">
            <span class="guide-me-icon"><?php echo esc_html( $me_lvl['icon'] ?? '🌱' ); ?></span>
            <div class="guide-me-info">
              <div class="guide-me-name">Lv.<?php echo (int) $me_lvl['level']; ?> · <?php echo esc_html( $me_lvl['title'] ); ?></div>
              <div class="guide-me-exp"><?php echo number_format( (int) $me_lvl['exp'] ); ?> EXP</div>
            </div>
          </div>
          <?php if ( ! empty( $me_job ) ): ?>
            <div class="guide-me-row">
              <span class="guide-me-icon"><?php echo esc_html( $me_job['job_icon'] ?? '🎯' ); ?></span>
              <div class="guide-me-info">
                <div class="guide-me-name"><?php echo esc_html( $me_job['title_name'] ); ?></div>
                <div class="guide-me-exp"><?php echo esc_html( $me_job['job_label'] ); ?> · <?php echo (int) ( $me_job['stage'] ?? 0 ); ?> 轉</div>
              </div>
            </div>
          <?php endif; ?>
          <?php if ( $me_rank && ! empty( $me_rank['tier']['label'] ) ): ?>
            <div class="guide-me-row">
              <span class="guide-me-icon"><?php echo esc_html( $me_rank['tier']['icon'] ?? '🎖️' ); ?></span>
              <div class="guide-me-info">
                <div class="guide-me-name"><?php echo esc_html( $me_rank['tier']['label'] ); ?></div>
                <div class="guide-me-exp"><?php echo number_format( (int) ( $me_rank['score'] ?? 0 ) ); ?> 賽季分</div>
              </div>
            </div>
          <?php endif; ?>
        </aside>
      <?php endif; ?>
    </header>

    <!-- ===== §1 EXP 來源 ===== -->
    <section id="exp" class="guide-section">
      <h2 class="guide-section-title">⚡ EXP 來源 — 我要怎麼獲得經驗值？</h2>
      <p class="guide-section-intro">
        在站上的每個互動都會帶來 EXP，累積到一定數量就會升級。下表是所有 EXP 行為與對應點數。
        <br><small>※ EXP 為<b>永久累積</b>，不會歸零；想知道哪些行為會同時給賽季分？請看下一節 →</small>
      </p>

      <?php if ( ! empty( $exp_rules ) ): ?>
        <div class="guide-exp-grid">
          <?php foreach ( $exp_rules as $rule ): ?>
            <div class="guide-exp-card" data-cap="<?php echo esc_attr( $rule['cap_type'] ?? 'none' ); ?>">
              <div class="guide-exp-icon"><?php echo esc_html( $rule['icon'] ); ?></div>
              <div class="guide-exp-body">
                <div class="guide-exp-label"><?php echo esc_html( $rule['label'] ); ?></div>
                <?php if ( ! empty( $rule['desc'] ) ): ?>
                  <div class="guide-exp-desc"><?php echo esc_html( $rule['desc'] ); ?></div>
                <?php endif; ?>
                <div class="guide-exp-meta">
                  <span class="guide-exp-value">+<?php echo (int) $rule['exp']; ?> EXP</span>
                  <span class="guide-exp-cap"><?php echo esc_html( $rule['cap_text'] ); ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="guide-empty">EXP 規則暫無法載入。</p>
      <?php endif; ?>

      <div class="guide-callout">
        <strong>💡 小提醒：</strong>
        EXP 是<b>永久累積</b>的，會影響你的等級與會員稱號。賽季積分另計，每季會重置（詳見「段位積分來源」與「賽季時程」）。
      </div>
    </section>

    <!-- ===== §1.5 段位積分來源（v2.2.0 新增）===== -->
    <section id="season-score" class="guide-section guide-section--season-score">
      <h2 class="guide-section-title">🏆 段位積分來源 — 賽季積分怎麼來？</h2>
      <p class="guide-section-intro">
        賽季積分（Season Score）與 EXP 是<b>兩個獨立的錢包</b>。
        EXP 永久累積影響「等級」；賽季積分則影響你在當季排行榜的「段位」，
        <b>每季結算後會歸零重來</b>。下表是會同時給「EXP + 賽季分」的行為。
      </p>

      <?php if ( ! empty( $season_rules ) ): ?>
        <div class="guide-exp-grid guide-exp-grid--season">
          <?php foreach ( $season_rules as $rule ):
            $exp_pts    = (int) ( $rule['exp']          ?? 0 );
            $season_pts = (int) ( $rule['season_score'] ?? 0 );
          ?>
            <div class="guide-exp-card guide-exp-card--season" data-cap="<?php echo esc_attr( $rule['cap_type'] ?? 'none' ); ?>">
              <div class="guide-exp-icon"><?php echo esc_html( $rule['icon'] ?? '📌' ); ?></div>
              <div class="guide-exp-body">
                <div class="guide-exp-label"><?php echo esc_html( $rule['label'] ?? '' ); ?></div>
                <?php if ( ! empty( $rule['desc'] ) ): ?>
                  <div class="guide-exp-desc"><?php echo esc_html( $rule['desc'] ); ?></div>
                <?php endif; ?>
                <div class="guide-exp-meta">
                  <?php if ( $exp_pts > 0 ): ?>
                    <span class="guide-exp-value guide-exp-value--exp">+<?php echo $exp_pts; ?> EXP</span>
                  <?php endif; ?>
                  <span class="guide-exp-value guide-exp-value--season">+<?php echo $season_pts; ?> 賽季分</span>
                  <span class="guide-exp-cap"><?php echo esc_html( $rule['cap_text'] ?? '' ); ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="guide-empty">目前沒有設定賽季積分規則。</p>
      <?php endif; ?>

      <div class="guide-callout guide-callout--season">
        <strong>🔁 雙錢包設計：</strong>
        像是「註冊送 100 EXP」、「生日 +50 EXP」這類<b>一次性 / 個人成就</b>只會給 EXP，不影響賽季排名。
        而<b>每日互動類</b>（登入、留言、評分、追蹤…）會同時給兩種點數，鼓勵你保持活躍。
        <br>
        <a class="guide-callout-cta" href="<?php echo esc_url( home_url( '/ranking/' ) ); ?>">查看本季排行榜 →</a>
        <?php if ( $me_uid ): ?>
          <a class="guide-callout-cta" href="<?php echo esc_url( home_url( '/mc/?tab=points' ) ); ?>">查看我的賽季分 →</a>
        <?php endif; ?>
      </div>
    </section>

    <!-- ===== §2 等級稱號 ===== -->
    <section id="level" class="guide-section">
      <h2 class="guide-section-title">📊 等級稱號 — 6 階會員身份</h2>
      <p class="guide-section-intro">
        累積 EXP 會自動提升等級（公式：<code>Lv = ⌊√(EXP/50)⌋</code>，最高 Lv.200）。
        等級對應 6 階會員身份，從「新進會員」一路晉升到「黑卡會員」。
      </p>

      <?php if ( ! empty( $member_tiers ) ): ?>
        <div class="guide-tier-grid">
          <?php foreach ( $member_tiers as $t ):
            $is_mine = ( $me_lvl && (int) $me_lvl['tier'] === (int) $t['tier'] );
          ?>
            <div class="guide-tier-card <?php echo $is_mine ? 'is-mine' : ''; ?>" style="--tier-color: <?php echo esc_attr( $t['color'] ); ?>">
              <div class="guide-tier-icon"><?php echo esc_html( $t['icon'] ); ?></div>
              <div class="guide-tier-name"><?php echo esc_html( $t['title'] ); ?></div>
              <div class="guide-tier-range">
                Lv.<?php echo (int) $t['min_level']; ?>+
                <small>（<?php echo number_format( (int) $t['min_exp'] ); ?> EXP）</small>
              </div>
              <?php if ( $is_mine ): ?>
                <div class="guide-tier-mine">⭐ 你的當前身份</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <details class="guide-details">
        <summary>查看完整 Lv.1 ~ 200 累計 EXP 對照表</summary>
        <div class="guide-level-table-wrap">
          <table class="guide-level-table">
            <thead>
              <tr><th>等級</th><th>累計 EXP</th><th>等級</th><th>累計 EXP</th><th>等級</th><th>累計 EXP</th><th>等級</th><th>累計 EXP</th></tr>
            </thead>
            <tbody>
              <?php
              $table = function_exists( 'smacg_get_full_level_table' ) ? smacg_get_full_level_table() : [];
              $cols  = 4;
              $rows  = (int) ceil( count( $table ) / $cols );
              for ( $r = 1; $r <= $rows; $r++ ) {
                  echo '<tr>';
                  for ( $c = 0; $c < $cols; $c++ ) {
                      $idx = $r + ( $c * $rows );
                      if ( isset( $table[ $idx ] ) ) {
                          $cell_is_mine = ( $me_lvl && (int) $me_lvl['level'] === $idx ) ? ' class="is-mine"' : '';
                          echo '<td' . $cell_is_mine . '>Lv.' . $idx . '</td>';
                          echo '<td' . $cell_is_mine . '>' . number_format( $table[ $idx ] ) . '</td>';
                      } else {
                          echo '<td></td><td></td>';
                      }
                  }
                  echo '</tr>';
              }
              ?>
            </tbody>
          </table>
        </div>
      </details>
    </section>
     <!-- ===== §3 職業天命 ===== -->
    <section id="career" class="guide-section">
      <h2 class="guide-section-title">🎯 職業天命 — 9 大職業 × 5 階稱號</h2>
      <p class="guide-section-intro">
        會員等級代表「身份」，職業則代表「玩家風格」。
        達到 <b>Lv.10（一轉）</b> 後可從 9 個職業中選擇一個，
        隨等級升高，職業稱號會自動進化（一轉 → 二轉 → 三轉 → 四轉 → 五轉）。
      </p>

      <div class="guide-milestone-bar" aria-label="5 個轉職里程碑">
        <?php foreach ( $milestones as $stage => $m ):
          $reached = ( $me_lvl && (int) $me_lvl['level'] >= (int) $m['level'] );
        ?>
          <div class="guide-milestone <?php echo $reached ? 'is-reached' : ''; ?>">
            <div class="guide-milestone-icon"><?php echo esc_html( $m['icon'] ); ?></div>
            <div class="guide-milestone-stage"><?php echo (int) $stage; ?> 轉</div>
            <div class="guide-milestone-level">Lv.<?php echo (int) $m['level']; ?></div>
            <div class="guide-milestone-label"><?php echo esc_html( $m['label'] ); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ( ! empty( $jobs ) ): ?>
        <div class="guide-job-grid">
          <?php foreach ( $jobs as $key => $job ):
            $is_mine = ( ! empty( $me_job['job_key'] ) && $me_job['job_key'] === $key );
          ?>
            <article class="guide-job-card <?php echo $is_mine ? 'is-mine' : ''; ?>" id="job-<?php echo esc_attr( $key ); ?>">
              <header class="guide-job-head">
                <div class="guide-job-icon"><?php echo esc_html( $job['icon'] ); ?></div>
                <div class="guide-job-headinfo">
                  <h3 class="guide-job-name"><?php echo esc_html( $job['label'] ); ?></h3>
                  <?php if ( ! empty( $job['desc'] ) ): ?>
                    <p class="guide-job-desc"><?php echo esc_html( $job['desc'] ); ?></p>
                  <?php endif; ?>
                </div>
                <?php if ( $is_mine ): ?>
                  <span class="guide-job-mine">⭐ 你的職業</span>
                <?php endif; ?>
              </header>
              <ol class="guide-job-titles">
                <?php foreach ( $job['titles'] as $stage => $t ):
                  $ms_lv   = (int) ( $milestones[ $stage ]['level'] ?? 0 );
                  $reached = ( $me_lvl && (int) $me_lvl['level'] >= $ms_lv );
                  $cur     = ( $is_mine && (int) ( $me_job['stage'] ?? 0 ) === (int) $stage );
                  $cls     = $reached ? ( $cur ? 'is-current' : 'is-done' ) : 'is-locked';
                ?>
                  <li class="guide-job-title-row <?php echo $cls; ?>">
                    <span class="guide-job-stage">Lv.<?php echo $ms_lv; ?></span>
                    <span class="guide-job-tname"><?php echo esc_html( $t['name'] ); ?></span>
                    <span class="guide-job-tref"><small><?php echo esc_html( $t['ref'] ); ?></small></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="guide-callout">
        <strong>⚠️ 注意：</strong>
        職業一旦選擇，<b>3 個月內無法變更</b>，請慎重考慮。
        <?php if ( ! is_user_logged_in() ): ?>
          <a class="guide-callout-cta" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">登入以選擇職業 →</a>
        <?php elseif ( $me_lvl && (int) $me_lvl['level'] < 10 ): ?>
          目前你 Lv.<?php echo (int) $me_lvl['level']; ?>，達到 Lv.10 即可在
          <a class="guide-callout-cta" href="<?php echo esc_url( home_url( '/mc/?tab=career' ) ); ?>">會員中心 → 職業頁</a> 選擇。
        <?php elseif ( empty( $me_job ) ): ?>
          <a class="guide-callout-cta" href="<?php echo esc_url( home_url( '/mc/?tab=career' ) ); ?>">前往會員中心選擇職業 →</a>
        <?php endif; ?>
      </div>
    </section>


    <!-- ===== §4 賽季段位（動態 10 階）===== -->
    <section id="tier" class="lg-section guide-section">
      <h2 class="guide-section-title">🏅 賽季段位 — 10 階段位制</h2>
      <p class="guide-section-intro">
        賽季積分由互動行為累積（留言、追蹤、評分等），每季結算重置。
        <strong>大師以下</strong>純看積分；<strong>大師、宗師、菁英</strong>同屬 5000+ 分，再依全站名次切分。
      </p>

      <?php if ( ! empty( $lg_rank_groups ) ): ?>
        <div class="lg-rank-grid">
          <?php foreach ( $lg_rank_groups as $t ): ?>
            <div class="lg-rank-card<?php echo $t['apex'] ? ' is-apex' : ''; ?>"
                 data-rank="<?php echo esc_attr( $t['key'] ); ?>"
                 style="--rank-color: <?php echo esc_attr( $t['color'] ); ?>;">
              <div class="lg-rank-icon"><?php echo esc_html( $t['icon'] ); ?></div>
              <div class="lg-rank-name"><?php echo esc_html( $t['name'] ); ?></div>
              <div class="lg-rank-range"><?php echo esc_html( $t['range'] ); ?></div>
              <?php if ( ! empty( $t['divs'] ) ): ?>
                <div class="lg-rank-divs"><?php echo esc_html( $t['divs'] ); ?></div>
              <?php endif; ?>
              <?php if ( ! empty( $t['note'] ) ): ?>
                <div class="lg-rank-note"><?php echo esc_html( $t['note'] ); ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="guide-empty">段位資料暫無法載入。</p>
      <?php endif; ?>

      <div class="lg-note guide-callout">
        <strong>提醒：</strong>宗師與菁英席位每日依排行榜重算，掉到 200 名外會自動降回大師。賽季結束時依當季最終排名發放紀念徽章。
      </div>
    </section>

    <!-- ===== §5 賽季時程（動漫四季）===== -->
    <section id="season" class="guide-section">
      <h2 class="guide-section-title">🗓️ 賽季時程 — 動漫四季輪替</h2>
      <p class="guide-section-intro">
        每年分為 4 個賽季：春（4/1–6/30）、夏（7/1–9/30）、秋（10/1–12/31）、冬（1/1–3/31）。
        賽季結束時會頒發紀念徽章，並重置賽季積分。
      </p>

      <?php if ( ! empty( $seasons ) ): ?>
        <ol class="guide-season-list">
          <?php foreach ( $seasons as $s ):
            $status_label = [ 'upcoming' => '即將開始', 'active' => '進行中', 'ended' => '已結束' ][ $s['status'] ] ?? '';
          ?>
            <li class="guide-season-item is-<?php echo esc_attr( $s['status'] ); ?>">
              <div class="guide-season-label"><?php echo esc_html( $s['label'] ); ?></div>
              <div class="guide-season-dates">
                <?php echo esc_html( date_i18n( 'Y/m/d', $s['start'] ) ); ?>
                ~
                <?php echo esc_html( date_i18n( 'Y/m/d', $s['end'] ) ); ?>
              </div>
              <div class="guide-season-status"><?php echo esc_html( $status_label ); ?></div>
              <?php if ( $s['status'] === 'active' ): ?>
                <div class="guide-season-countdown" data-end="<?php echo (int) $s['end']; ?>">
                  距離結算還有 <b class="js-countdown">…</b>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <div class="guide-callout">
        <strong>💾 結算範圍：</strong>
        賽季結算時，<b>只會重置「賽季積分」與「排行榜名次」</b>；你的 <b>EXP、等級、職業、徽章都會永久保留</b>。
        當季最終排名會封存到歷史檔案，並依名次發放紀念徽章（規劃中）。
      </div>
    </section>

    <!-- ===== §6 成就收集 ===== -->
    <section id="achievements" class="guide-section">
      <h2 class="guide-section-title">🎖️ 成就收集 — 解鎖你的成就</h2>
      <p class="guide-section-intro">
        成就分兩種：<b>入門成就</b>是第一次做某件事就解鎖，<b>累積成就</b>則是長期挑戰、
        每類分四個階段。完整清單與你目前的進度請至會員中心查看。
      </p>

      <?php
      /*
       * 分成兩組顯示。
       *
       * 原本是「撈 12 個、依 menu_order 排序」，而累積型徽章的 menu_order
       * 刻意設在 100 之後（見 Activator::install_milestone_badges()），
       * 結果前 12 名全是「初次」系列，20 個累積成就一個都沒露出——而它們
       * 存在的意義正是「讓使用者看見下一個目標」，在專門介紹成長系統的
       * 頁面上看不到，等於白做。
       *
       * 累積成就用「階梯」而非徽章格子呈現：重點是讓人看懂「每類有四個
       * 階段」這個結構，20 個格子只會把頁面拉長又看不出關聯。
       */
      $badge_slug = defined( 'WXACG_BADGE_SLUG' ) ? WXACG_BADGE_SLUG : 'badge';

      // 入門成就：沒有 milestone meta 的即是
      $first_badges = get_posts( [
          'post_type'      => $badge_slug,
          'post_status'    => 'publish',
          'posts_per_page' => 8,
          'orderby'        => 'menu_order title',
          'order'          => 'ASC',
          'meta_query'     => [
              [ 'key' => '_wxacg_milestone_type', 'compare' => 'NOT EXISTS' ],
          ],
      ] );

      $earned_ids = ( $me_uid && function_exists( 'smacg_get_user_badge_ids' ) ) ? smacg_get_user_badge_ids( $me_uid ) : [];
      $earned_map = array_flip( $earned_ids );

      // 累積成就：依類別彙整出階梯
      $milestone_posts = get_posts( [
          'post_type'      => $badge_slug,
          'post_status'    => 'publish',
          'posts_per_page' => 100,
          'orderby'        => 'menu_order',
          'order'          => 'ASC',
          'meta_query'     => [
              [ 'key' => '_wxacg_milestone_type', 'compare' => 'EXISTS' ],
          ],
      ] );

      $milestone_groups = [];
      foreach ( $milestone_posts as $mp ) {
          $type   = get_post_meta( $mp->ID, '_wxacg_milestone_type', true );
          $target = (int) get_post_meta( $mp->ID, '_wxacg_milestone_target', true );
          if ( ! $type || $target <= 0 ) {
              continue;
          }
          if ( ! isset( $milestone_groups[ $type ] ) ) {
              $milestone_groups[ $type ] = [
                  'icon'    => function_exists( 'wxacg_get_achievement_icon' )
                      ? wxacg_get_achievement_icon( $mp->post_name )
                      : 'fa-solid fa-trophy',
                  'targets' => [],
                  'earned'  => 0,
              ];
          }
          $milestone_groups[ $type ]['targets'][] = $target;
          if ( isset( $earned_map[ $mp->ID ] ) ) {
              $milestone_groups[ $type ]['earned']++;
          }
      }

      /*
       * 階梯數字去重並由小到大排序。
       *
       * 不倚賴 menu_order 的排列：徽章貼文是程式建立的，若曾因並發競態
       * 產生重複（2026-08-22 上線時就發生過，見 Activator 的鎖說明），
       * 這裡會排出「100 → 3 → 10 → 30 → 100」這種讀不懂的階梯。
       * 顯示層自己保證順序，比假設資料乾淨可靠。
       */
      foreach ( $milestone_groups as $type => $g ) {
          $targets = array_values( array_unique( $g['targets'] ) );
          sort( $targets, SORT_NUMERIC );
          $milestone_groups[ $type ]['targets'] = $targets;
      }

      // 標題與單位；unit 讓「3 / 10 / 30 / 100 部」讀起來完整
      $milestone_labels = [
          'watch'    => [ '看完作品', '部' ],
          'review'   => [ '發表評論', '則' ],
          'rating'   => [ '評分作品', '部' ],
          'favorite' => [ '收藏作品', '部' ],
          'streak'   => [ '連續登入', '天' ],
      ];
      ?>

      <?php if ( ! empty( $first_badges ) ): ?>
        <h3 class="guide-badge-subtitle">🌱 入門成就</h3>
        <div class="guide-badge-grid">
          <?php foreach ( $first_badges as $b ):
            $unlocked = isset( $earned_map[ $b->ID ] );
            $thumb    = get_the_post_thumbnail_url( $b->ID, 'thumbnail' );
          ?>
            <div class="guide-badge-card <?php echo $unlocked ? 'is-unlocked' : 'is-locked'; ?>" title="<?php echo esc_attr( $b->post_title ); ?>">
              <div class="guide-badge-icon">
                <?php if ( $thumb ): ?>
                  <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $b->post_title ); ?>" loading="lazy">
                <?php else:
                  $b_icon = function_exists( 'wxacg_get_achievement_icon' )
                      ? wxacg_get_achievement_icon( $b->post_name )
                      : 'fa-solid fa-trophy';
                ?>
                  <i class="<?php echo esc_attr( $b_icon ); ?>"></i>
                <?php endif; ?>
              </div>
              <div class="guide-badge-name"><?php echo esc_html( $b->post_title ); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ( ! empty( $milestone_groups ) ): ?>
        <h3 class="guide-badge-subtitle">🏆 累積成就</h3>
        <div class="guide-milestone-list">
          <?php foreach ( $milestone_groups as $type => $g ):
            [ $label, $unit ] = $milestone_labels[ $type ] ?? [ $type, '' ];
            $total = count( $g['targets'] );
          ?>
            <div class="guide-milestone-row">
              <div class="guide-milestone-head">
                <i class="<?php echo esc_attr( $g['icon'] ); ?>" aria-hidden="true"></i>
                <span class="guide-milestone-label"><?php echo esc_html( $label ); ?></span>
                <?php if ( $me_uid && $g['earned'] > 0 ): ?>
                  <span class="guide-milestone-count"><?php echo (int) $g['earned']; ?> / <?php echo (int) $total; ?></span>
                <?php endif; ?>
              </div>
              <div class="guide-milestone-tiers">
                <?php foreach ( $g['targets'] as $i => $t ): ?>
                  <?php if ( $i > 0 ): ?><span class="guide-milestone-arrow" aria-hidden="true">→</span><?php endif; ?>
                  <span class="guide-milestone-tier"><?php echo (int) $t; ?><?php echo esc_html( $unit ); ?></span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="guide-milestone-note">
          四個階段依序給予 <b>+30 / +80 / +200 / +500 EXP</b>，解鎖徽章另計 +20 EXP。
          已達標的舊紀錄會在你打開成就頁時自動補發。
        </p>
      <?php endif; ?>

      <?php if ( ! empty( $first_badges ) || ! empty( $milestone_groups ) ): ?>
        <p class="guide-badge-more">
          <a href="<?php echo esc_url( home_url( '/mc/#achievements' ) ); ?>">查看完整成就列表與我的進度 →</a>
        </p>
      <?php else: ?>
        <p class="guide-empty">成就系統正在建置中，敬請期待。</p>
      <?php endif; ?>
    </section>

    <!-- ===== Footer CTA ===== -->
    <footer class="guide-cta">
      <?php if ( ! is_user_logged_in() ): ?>
        <h3>準備好開始你的旅程？</h3>
        <p>登入後即可獲得 EXP、選擇職業、累積賽季積分。</p>
        <a href="<?php echo esc_url( wp_login_url( home_url( '/mc/' ) ) ); ?>" class="guide-cta-btn">立即登入 / 註冊 →</a>
      <?php else: ?>
        <h3>前往會員中心查看你的進度</h3>
        <a href="<?php echo esc_url( home_url( '/mc/' ) ); ?>" class="guide-cta-btn">會員中心 →</a>
      <?php endif; ?>
    </footer>

  </div>
</main>

<?php get_footer(); ?>
