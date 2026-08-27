<?php
/**
 * 每日簽到彈窗（置中，巴哈風格）
 *
 * 目的是「讓會員想回訪」。原本簽到只記在 /mc/ 的面板裡，不主動點進去
 * 就完全看不到自己有連續紀錄。
 *
 * 為什麼不放在 /mc/：
 * 會員中心的區塊已經很多，簽到擠在總覽最上方會讓整頁更雜。改成比照巴哈
 * ——放進頭像下拉選單，選單項目右邊直接顯示「已簽到／未簽到」，點了才展開
 * 完整內容。日常只佔選單一行，需要時才看細節。
 *
 * 為什麼是置中彈窗而不是角落小卡：
 * 右下角已經被「返回頂部」（style.css 的 .back-to-top，z-index 9999）和
 * 首頁的抽卡元件佔住，小卡會疊在上面。
 *
 * 兩種開啟方式共用同一份 DOM：
 *   1. 當天第一次瀏覽前台 → 自動跳出（wxacg_daily_checkin 留下的旗標）
 *   2. 點頭像選單的「每日簽到」→ 隨時可以再打開
 *
 * 刻意不做的事：
 *   - 不改成手動點擊簽到。既有會員的 smacg_login_streak 是登入自動累積的，
 *     改成要點才算，等於讓所有人歸零重來，還得再補一套補簽機制。
 *   - 不新增／不修改任何 EXP 規則。這支只讀資料、只顯示，不發點數。
 *
 * 樣式與腳本刻意內嵌而非另外 enqueue：
 *   1. 只對登入者輸出，而登入者不走 LiteSpeed 快取。
 *   2. 站上 LiteSpeed 合併後的資產檔名不隨內容變動，Cloudflare 會送舊副本
 *      （2026-08-27 實際踩過），內嵌可以完全避開這個問題。
 *
 * @package WXACG_Gamification
 */

namespace WXACG\Gamification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkin_Modal {

    /** 待自動跳出的旗標（user_meta） */
    const PENDING_META = 'wxacg_checkin_toast_pending';

    /**
     * 掛載。
     */
    public static function init(): void {
        add_action( 'wxacg_daily_checkin', [ __CLASS__, 'mark_pending' ], 10, 2 );
        add_action( 'wp_footer', [ __CLASS__, 'render_shell' ], 20 );
    }

    /**
     * 簽到完成 → 記下「今天要自動跳一次」。
     *
     * 用 user_meta 而不是請求內的靜態變數：簽到那次請求如果是轉址結尾
     * （footer 根本不會跑），就整天不會自動跳了，而每日 transient 已經
     * 標記過，補不回來。改用 meta 可以留到下一個真正有畫面的請求。
     *
     * @param int   $uid  會員 ID。
     * @param array $info 簽到結果（streak／gen／broken／hit_7／hit_30）。
     */
    public static function mark_pending( $uid, $info ): void {
        $uid = (int) $uid;
        if ( $uid <= 0 || ! is_array( $info ) ) {
            return;
        }

        update_user_meta( $uid, self::PENDING_META, [
            'broken' => ! empty( $info['broken'] ),
            'hit_7'  => ! empty( $info['hit_7'] ),
            'hit_30' => ! empty( $info['hit_30'] ),
            'ymd'    => current_time( 'Ymd' ),
        ] );
    }

    /**
     * 今天是否已簽到——給頭像選單的標籤用。
     *
     * @param int $uid 會員 ID。
     * @return bool
     */
    public static function is_done_today( int $uid ): bool {
        if ( $uid <= 0 ) {
            return false;
        }
        return (string) get_user_meta( $uid, 'smacg_last_login_date', true ) === current_time( 'Ymd' );
    }

    /**
     * 取出並清掉「今天要自動跳」的旗標。
     *
     * @param int $uid 會員 ID。
     * @return array|null 有旗標時回傳內容，否則 null。
     */
    private static function consume_pending( int $uid ): ?array {
        $info = get_user_meta( $uid, self::PENDING_META, true );

        if ( ! is_array( $info ) || empty( $info['ymd'] ) ) {
            return null;
        }

        delete_user_meta( $uid, self::PENDING_META );

        /*
         * 隔日殘留的旗標直接丟掉不自動跳。
         * 會發生在「簽到那天之後都沒開過任何前台頁面」的情況，這時再跳
         * 「今日簽到完成」是錯的資訊。
         */
        if ( (string) $info['ymd'] !== current_time( 'Ymd' ) ) {
            return null;
        }

        return $info;
    }

    /**
     * 前台頁尾：輸出彈窗本體（預設隱藏）。
     *
     * 不論今天有沒有簽到都會輸出，因為頭像選單隨時可以點開。當天第一次
     * 瀏覽時額外帶 data-auto="1"，由 JS 自動打開一次。
     */
    public static function render_shell(): void {
        if ( is_admin() || ! is_user_logged_in() ) {
            return;
        }

        $uid     = get_current_user_id();
        $pending = self::consume_pending( $uid );

        self::render( $uid, $pending );
    }

    /**
     * 輸出彈窗。
     *
     * @param int        $uid     會員 ID。
     * @param array|null $pending 當天首次瀏覽的簽到結果，null 代表不自動跳。
     */
    private static function render( int $uid, ?array $pending ): void {
        $status = Checkin_Panel::get_status( $uid );
        $streak = (int) $status['streak'];
        $done   = ! empty( $status['today_done'] );

        // 里程碑 EXP 直接讀規則，避免與實際發放的數字說法不一致
        $rules = Exp_Config::rules();
        $exp7  = (int) ( $rules['streak_7']['exp']  ?? 100 );
        $exp30 = (int) ( $rules['streak_30']['exp'] ?? 500 );

        $pos7 = (int) $status['cycle_pos'];

        /*
         * 標題與說明。
         * 剛滿 30 天 > 剛滿 7 天 > 中斷後重來 > 今日已簽 > 今日未簽。
         * 後兩種是從選單點開時的常態，前三種只有當天自動跳出時才會出現。
         */
        if ( $pending && ! empty( $pending['hit_30'] ) ) {
            $title = '30 天大里程碑！';
            $sub   = sprintf( '獲得 %s EXP，繼續保持。', number_format( $exp30 ) );
            $tone  = 'gold';
        } elseif ( $pending && ! empty( $pending['hit_7'] ) ) {
            $title = '連續七天達成！';
            $sub   = sprintf( '獲得 %s EXP，再 %d 天就是 30 天大關。', number_format( $exp7 ), (int) $status['to_next30'] );
            $tone  = 'gold';
        } elseif ( $pending && ! empty( $pending['broken'] ) ) {
            $title = '今日簽到完成';
            $sub   = sprintf( '上次的連續紀錄中斷了，從頭開始。連續 7 天可拿 %s EXP。', number_format( $exp7 ) );
            $tone  = 'plain';
        } elseif ( $done ) {
            $title = '今日簽到完成';
            $sub   = sprintf( '再 %d 天可拿 %s EXP。', (int) $status['to_next7'], number_format( $exp7 ) );
            $tone  = 'plain';
        } else {
            $title = '今天還沒簽到';
            $sub   = '瀏覽站上任何頁面就會自動簽到，不需要手動點。';
            $tone  = 'plain';
        }

        // 本輪 7 格進度點
        $dots = '';
        for ( $i = 1; $i <= 7; $i++ ) {
            $dots .= '<i class="wxcm-dot' . ( $i <= $pos7 ? ' is-on' : '' ) . '"></i>';
        }
        ?>
        <div class="wxcm-mask" id="wxcm-mask" role="dialog" aria-modal="true"
             aria-labelledby="wxcm-title" hidden
             <?php echo $pending ? 'data-auto="1"' : ''; ?>>
            <div class="wxcm wxcm--<?php echo esc_attr( $tone ); ?>">
                <button type="button" class="wxcm-x" id="wxcm-x" aria-label="關閉">&times;</button>

                <div class="wxcm-icon" aria-hidden="true">🔥</div>

                <h2 class="wxcm-title" id="wxcm-title"><?php echo esc_html( $title ); ?></h2>

                <div class="wxcm-streak">
                    <span class="wxcm-num"><?php echo (int) $streak; ?></span>
                    <span class="wxcm-unit">天</span>
                </div>
                <div class="wxcm-streak-label">目前連續簽到</div>

                <div class="wxcm-dots" aria-hidden="true">
                    <?php echo $dots; // phpcs:ignore WordPress.Security.EscapeOutput -- 上方以固定字串組成，無外部輸入 ?>
                </div>

                <p class="wxcm-sub"><?php echo esc_html( $sub ); ?></p>

                <?php
                /* 本月月曆：逐日實記，與上面的週期進度來源不同 */
                if ( class_exists( __NAMESPACE__ . '\Checkin_Calendar' ) ) {
                    Checkin_Calendar::render( $uid );
                }
                ?>

                <div class="wxcm-acts">
                    <button type="button" class="wxcm-btn wxcm-btn--primary" id="wxcm-ok">知道了</button>
                </div>
            </div>
        </div>

        <style>
        .wxcm-mask{
            position:fixed;inset:0;z-index:1000000;
            display:flex;align-items:center;justify-content:center;
            padding:20px;
            background:rgba(6,8,14,.66);
            backdrop-filter:blur(3px);
            opacity:0;transition:opacity .22s ease;
        }
        .wxcm-mask[hidden]{display:none;}
        .wxcm-mask.is-in{opacity:1;}
        .wxcm{
            position:relative;width:100%;max-width:400px;
            max-height:calc(100vh - 40px);overflow-y:auto;
            padding:30px 26px 24px;
            background:#1b1e29;color:#e8eaf2;
            border:1px solid rgba(255,255,255,.10);border-radius:20px;
            box-shadow:0 24px 60px rgba(0,0,0,.5);
            text-align:center;
            transform:scale(.92);transition:transform .22s cubic-bezier(.2,.9,.3,1.2);
        }
        .wxcm-mask.is-in .wxcm{transform:scale(1);}
        .wxcm--gold{
            border-color:rgba(255,190,80,.42);
            background:linear-gradient(160deg,#2a2318,#1b1e29 55%);
        }
        .wxcm-x{
            position:absolute;top:10px;right:12px;
            background:none;border:none;cursor:pointer;
            color:#767d95;font-size:26px;line-height:1;padding:4px 8px;
        }
        .wxcm-x:hover{color:#e8eaf2;}
        .wxcm-icon{font-size:44px;line-height:1;margin-bottom:6px;}
        .wxcm-title{
            margin:0 0 12px;font-size:19px;font-weight:800;
            letter-spacing:.4px;color:#e8eaf2;
        }
        .wxcm--gold .wxcm-title{color:#ffd479;}
        .wxcm-streak{display:flex;align-items:baseline;justify-content:center;gap:4px;}
        .wxcm-num{
            font-size:50px;font-weight:900;line-height:1;
            font-variant-numeric:tabular-nums;
            background:linear-gradient(135deg,#ff8c42,#ff6bae);
            -webkit-background-clip:text;background-clip:text;
            -webkit-text-fill-color:transparent;color:#ff8c42;
        }
        .wxcm-unit{font-size:17px;font-weight:700;color:#a7adc0;}
        .wxcm-streak-label{margin-top:2px;font-size:12px;color:#7d8399;letter-spacing:1px;}
        .wxcm-dots{display:flex;justify-content:center;gap:6px;margin:16px 0 12px;}
        .wxcm-dot{
            width:26px;height:6px;border-radius:3px;
            background:rgba(255,255,255,.13);
        }
        .wxcm-dot.is-on{background:linear-gradient(90deg,#ff8c42,#ff6bae);}
        .wxcm-sub{margin:0 0 16px;font-size:13px;line-height:1.65;color:#a7adc0;}

        /*
         * 月曆樣式寫在這裡而不是 member.css：那支只在會員中心載入
         * （setup-enqueue.php 的 is_page_template('page-member.php')），
         * 而彈窗是全站的，寫在那邊等於永遠不生效。
         * 一併改用明確色值，不依賴只存在於會員中心的 --mc-* 變數。
         */
        .wxcm .wxcc{margin:0 0 18px;text-align:left;}
        .wxcm .wxcc-head{
            display:flex;flex-wrap:wrap;align-items:baseline;
            justify-content:space-between;gap:8px;margin-bottom:10px;
        }
        .wxcm .wxcc-title{font-size:13px;font-weight:700;color:#c3c8d8;}
        .wxcm .wxcc-title i{color:#9b8cff;margin-right:5px;}
        .wxcm .wxcc-count{font-size:12px;color:#a7adc0;}
        .wxcm .wxcc-count b{
            font-size:15px;color:#9b8cff;
            font-variant-numeric:tabular-nums;margin:0 2px;
        }
        .wxcm .wxcc-grid{
            list-style:none;margin:0;padding:0;
            display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;
        }
        .wxcm .wxcc-dow{
            text-align:center;font-size:10px;font-weight:700;
            color:#6b7280;padding-bottom:2px;
        }
        .wxcm .wxcc-pad{visibility:hidden;}
        .wxcm .wxcc-day{
            aspect-ratio:1;display:flex;align-items:center;justify-content:center;
            border-radius:7px;border:1px solid rgba(255,255,255,.09);
            background:rgba(255,255,255,.02);
        }
        .wxcm .wxcc-num{font-size:11px;font-variant-numeric:tabular-nums;color:#a7adc0;}
        .wxcm .wxcc-day.is-done{
            border-color:transparent;
            background:linear-gradient(135deg,#ff8c42,#ff6bae);
        }
        .wxcm .wxcc-day.is-done .wxcc-num{color:#fff;font-weight:700;}
        /* 今天用外框標出來，已簽到時仍看得到 */
        .wxcm .wxcc-day.is-today{box-shadow:0 0 0 2px #9b8cff;}
        /* 未來的日子淡化，避免看起來像漏簽 */
        .wxcm .wxcc-day.is-future{opacity:.35;}
        .wxcm .wxcc-note{margin:10px 0 0;font-size:11px;line-height:1.6;color:#6b7280;}
        .wxcm-acts{display:flex;gap:10px;}
        .wxcm-btn{
            flex:1;padding:11px 14px;border-radius:11px;
            font-size:14px;font-weight:700;cursor:pointer;
            text-decoration:none;text-align:center;border:1px solid transparent;
            transition:filter .18s ease,background .18s ease;
        }
        .wxcm-btn--primary{
            background:linear-gradient(135deg,#6c63ff,#9b8cff);
            color:#fff;
        }
        .wxcm-btn--primary:hover{filter:brightness(1.1);}
        @media (max-width:420px){
            .wxcm{padding:26px 20px 20px;}
            .wxcm-num{font-size:42px;}
        }
        @media (prefers-reduced-motion:reduce){
            .wxcm-mask,.wxcm{transition:none;}
        }
        </style>

        <script>
        (function(){
            var mask = document.getElementById('wxcm-mask');
            if (!mask) return;

            function open(){
                mask.hidden = false;
                /*
                 * 用 setTimeout 而不是 requestAnimationFrame：分頁在背景時
                 * rAF 不會執行，彈窗會停在 opacity:0。setTimeout 在背景分頁
                 * 只會被節流，仍然會跑。
                 */
                setTimeout(function(){ mask.classList.add('is-in'); }, 20);
                document.addEventListener('keydown', onKey);
            }

            function close(){
                mask.classList.remove('is-in');
                document.removeEventListener('keydown', onKey);
                setTimeout(function(){ mask.hidden = true; }, 260);
            }

            function onKey(e){ if (e.key === 'Escape') close(); }

            var x  = document.getElementById('wxcm-x');
            var ok = document.getElementById('wxcm-ok');
            if (x)  x.addEventListener('click', close);
            if (ok) ok.addEventListener('click', close);

            /* 點遮罩空白處關閉，點卡片本身不關 */
            mask.addEventListener('click', function(e){
                if (e.target === mask) close();
            });

            /* 頭像選單的「每日簽到」 */
            var trigger = document.getElementById('wxcm-open');
            if (trigger) {
                trigger.addEventListener('click', function(e){
                    e.preventDefault();
                    open();
                });
            }

            /* 當天第一次瀏覽 → 自動跳一次 */
            if (mask.dataset.auto === '1') {
                setTimeout(open, 400);
            }
        })();
        </script>
        <?php
    }
}
