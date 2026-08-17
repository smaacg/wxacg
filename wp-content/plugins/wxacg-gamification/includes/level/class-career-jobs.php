<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Career Jobs — 9 職業 × 5 階稱號（動漫梗）
 *
 * 用法：
 *   Career_Jobs::all()                       → 9 職業完整表
 *   Career_Jobs::milestones()                → 5 個里程碑（Lv 10/30/70/120/170）
 *   Career_Jobs::career_stage( $level )      → 該等級在第幾轉（0~5）
 *   Career_Jobs::user_job( $uid )            → 玩家當前 job_key
 *   Career_Jobs::set_user_job( $uid, $key )  → 一轉時呼叫
 *   Career_Jobs::user_title( $uid )          → 玩家當前稱號（依等級判轉階）
 * @version 2.2.0 (2026-05-26)
 *   - 新增第 9 職業：craft（製造／藍領／工程）
 *   - 全 9 職業擴充至 5 階稱號，新增 Lv.170 五轉「傳說稱號」
 *   - 文案改採「短梗描述 + 動漫·角色」風格
 */
class Career_Jobs {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

      /* ==========================================================
     * 9 職業 × 5 階稱號表
     * ========================================================== */
    public static function all() {
        return [
            'student' => [
                'label'  => '學生',
                'icon'   => '🎓',
                'desc'   => '校園裡的真理追尋者，從懵懂新生到能理解世界規則的真理鍊成者',
                'titles' => [
                    1 => [ 'name' => '懵懂新生',     'ref' => '我的英雄學院 · 綠谷出久' ],
                    2 => [ 'name' => '優等生',       'ref' => '五等分的新娘 · 上杉風太郎' ],
                    3 => [ 'name' => '學生會長',     'ref' => '輝夜姬想讓人告白 · 白銀御行' ],
                    4 => [ 'name' => '神級學者',     'ref' => 'Dr. STONE · 石神千空' ],
                    5 => [ 'name' => '真理鍊成者',   'ref' => '鋼之鍊金術師 · 愛德華' ],
                ],
            ],
            'it' => [
                'label'  => '資訊／軟體業',
                'icon'   => '💻',
                'desc'   => '與 Bug 共舞的世界線工程師，從 Hello World 到登入神域，最後成為網路本身',
                'titles' => [
                    1 => [ 'name' => '程式新手',     'ref' => 'NEW GAME! · 涼風青葉' ],
                    2 => [ 'name' => 'Debug 修行生', 'ref' => '夏日大作戰 · 小磯健二' ],
                    3 => [ 'name' => '超級駭客',     'ref' => '命運石之門 · 橋田至' ],
                    4 => [ 'name' => '神域架構師',   'ref' => '刀劍神域 · 茅場晶彥' ],
                    5 => [ 'name' => 'Wired 創世神', 'ref' => 'Serial Experiments Lain · 岩倉玲音' ],
                ],
            ],
            'design' => [
                'label'  => '設計／創作',
                'icon'   => '🎨',
                'desc'   => '線條、色彩與靈魂的鍊成師，從第一次拿起畫筆到創造能改變世界觀的作品',
                'titles' => [
                    1 => [ 'name' => '美術新手',     'ref' => '藍色時期 · 矢口八虎' ],
                    2 => [ 'name' => '色彩魔法師',   'ref' => '魔法少女小圓' ],
                    3 => [ 'name' => '分鏡鬼才',     'ref' => '別對映像研出手！· 淺草綠' ],
                    4 => [ 'name' => '神之筆者',     'ref' => '爆漫王。· 真城最高' ],
                    5 => [ 'name' => '世界繪卷師',   'ref' => '新海誠作品意象' ],
                ],
            ],
            'office' => [
                'label'  => '行政／內勤',
                'icon'   => '💼',
                'desc'   => '支配辦公室秩序的幕後司令官，不拿劍、不開槍，卻能讓整個組織正常運轉',
                'titles' => [
                    1 => [ 'name' => '社畜新血',     'ref' => 'NEW GAME!' ],
                    2 => [ 'name' => '會議調停者',   'ref' => 'Servant × Service' ],
                    3 => [ 'name' => '辦公室核心',   'ref' => '衝吧烈子 · 烈子' ],
                    4 => [ 'name' => '地獄總務長',   'ref' => '鬼燈的冷徹 · 鬼燈' ],
                    5 => [ 'name' => '冥府營運神',   'ref' => '鬼燈的冷徹 · 鬼燈完全體' ],
                ],
            ],
            'sales' => [
                'label'  => '業務／行銷',
                'icon'   => '📊',
                'desc'   => '用話術打穿市場的契約之王，只要話術到位連神明與魔王都能成交',
                'titles' => [
                    1 => [ 'name' => '業務新兵',         'ref' => '機動戰士鋼彈 · 量產型吉姆' ],
                    2 => [ 'name' => '簽單獵人',         'ref' => 'HUNTER × HUNTER' ],
                    3 => [ 'name' => '王牌談判師',       'ref' => 'JOJO 的奇妙冒險 · DIO' ],
                    4 => [ 'name' => '商業賢狼契約者',   'ref' => '狼與辛香料 · 羅倫斯' ],
                    5 => [ 'name' => '市場支配者',       'ref' => '遊戲人生 · 空白' ],
                ],
            ],
            'medical' => [
                'label'  => '醫療／護理',
                'icon'   => '🏥',
                'desc'   => '與死神搶人的白衣戰士，白天救人晚上補眠，永遠在鬼門關前加班',
                'titles' => [
                    1 => [ 'name' => '見習醫療員',   'ref' => '工作細胞 · 紅血球' ],
                    2 => [ 'name' => '白衣守護者',   'ref' => '工作細胞 · 白血球' ],
                    3 => [ 'name' => '黑牌名醫',     'ref' => '怪醫黑傑克' ],
                    4 => [ 'name' => '百豪醫神',     'ref' => '火影忍者 · 綱手' ],
                    5 => [ 'name' => '生命守門人',   'ref' => '鋼之鍊金術師 · 生命與靈魂意象' ],
                ],
            ],
            'service' => [
                'label'  => '餐飲／服務業',
                'icon'   => '🍽️',
                'desc'   => '從端盤勇者到發光料理王，客人不是問題，奧客只是還沒被料理感化',
                'titles' => [
                    1 => [ 'name' => '服務員',       'ref' => '迷糊餐廳 WORKING!!' ],
                    2 => [ 'name' => '迎賓武士',     'ref' => '銀魂 · 萬事屋' ],
                    3 => [ 'name' => '食戟主廚',     'ref' => '食戟之靈 · 幸平創真' ],
                    4 => [ 'name' => '傳說廚神',     'ref' => '中華一番！· 小當家' ],
                    5 => [ 'name' => '味覺創世神',   'ref' => '美食獵人 Toriko' ],
                ],
            ],
            'craft' => [
                'label'  => '製造／藍領／工程',
                'icon'   => '🔨',
                'desc'   => '用雙手鍛造世界的百鍊匠神，鋼鐵、機械、螺絲、汗水，都是造物主的語言',
                'titles' => [
                    1 => [ 'name' => '實習黑手',     'ref' => '天元突破 · 西蒙' ],
                    2 => [ 'name' => '機械技師',     'ref' => '鋼之鍊金術師 · 溫莉' ],
                    3 => [ 'name' => '鋼鐵鍊成師',   'ref' => '鋼之鍊金術師 · 愛德華' ],
                    4 => [ 'name' => '天元匠神',     'ref' => '天元突破 · 西蒙覺醒後' ],
                    5 => [ 'name' => '螺旋造物主',   'ref' => '天元突破 · 天元突破意象' ],
                ],
            ],
            'freelance' => [
                'label'  => '自由／其他',
                'icon'   => '🌱',
                'desc'   => '不被職業欄定義的人生玩家，沒有固定職業，因為人生本身就是開放世界',
                'titles' => [
                    1 => [ 'name' => '自由旅人',     'ref' => '葬送的芙莉蓮 · 芙莉蓮' ],
                    2 => [ 'name' => '斜槓冒險者',   'ref' => '為美好的世界獻上祝福 · 佐藤和真' ],
                    3 => [ 'name' => '人生玩家',     'ref' => '孤獨搖滾！· 廣井菊理' ],
                    4 => [ 'name' => '逍遙之神',     'ref' => '聖哥傳' ],
                    5 => [ 'name' => '次元旅法師',   'ref' => '奇諾之旅 · 奇諾意象' ],
                ],
            ],
        ];
    }

    /* ==========================================================
     * 5 轉里程碑
     * ========================================================== */
    public static function milestones() {
        return [
            1 => [ 'level' => 10,  'label' => '一轉：解鎖職業',  'icon' => '🎓' ],
            2 => [ 'level' => 30,  'label' => '二轉：職業升階',  'icon' => '⭐' ],
            3 => [ 'level' => 70,  'label' => '三轉：高階稱號',  'icon' => '🌟' ],
            4 => [ 'level' => 120, 'label' => '四轉：究極稱號',  'icon' => '👑' ],
            5 => [ 'level' => 170, 'label' => '五轉：傳說稱號',  'icon' => '🔱' ],
        ];
    }

    /**
     * 由 level 判斷在第幾轉（0~5）
     */
    public static function career_stage( $level ) {
        $level = (int) $level;
        if ( $level >= 170 ) return 5;
        if ( $level >= 120 ) return 4;
        if ( $level >= 70  ) return 3;
        if ( $level >= 30  ) return 2;
        if ( $level >= 10  ) return 1;
        return 0;
    }

    /* ==========================================================
     * 玩家職業（meta：smacg_job_key）
     * ========================================================== */
    public static function user_job( $uid ) {
        return (string) get_user_meta( (int) $uid, 'smacg_job_key', true );
    }

    public static function set_user_job( $uid, $job_key ) {
        $uid     = (int) $uid;
        $job_key = sanitize_key( $job_key );
        $jobs    = self::all();

        if ( $uid <= 0 || ! isset( $jobs[ $job_key ] ) ) return false;

        update_user_meta( $uid, 'smacg_job_key',       $job_key );
        update_user_meta( $uid, 'smacg_job_chosen_at', current_time( 'mysql' ) );

        do_action( 'smacg_user_job_chosen', $uid, $job_key );
        return true;
    }

    /**
     * 取得玩家目前的職業稱號（依當前等級判轉階）
     *
     * ★ Fix #2 (2026-05-20)：
     *   - 移除原本永遠不會成立的 `if ( $stage < 1 )` 死碼
     *   - Lv<10（career_stage=0）正確回傳 []，不再被 max(1, …) 強制升為 stage 1
     *   - 對 $jobs[$key]['titles'][$stage] 補上 isset() 防呆，未來 filter 擴充不會 fatal
     *
     * @return array|[]  ['job_key','job_label','job_icon','stage','title_name','title_ref']
     */
    public static function user_title( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return [];

        $key = self::user_job( $uid );
        if ( ! $key ) return [];

        $jobs = self::all();
        if ( ! isset( $jobs[ $key ] ) ) return [];

        $info  = Level_System::get_user_level( $uid );
        $stage = self::career_stage( (int) ( $info['level'] ?? 0 ) );

        // Lv<10 → 還沒一轉，沒有職業稱號
        if ( $stage < 1 ) return [];

        /*
         * 二轉（含）以上：等級到了只是必要條件，還要集滿全部「初次」成就
         * 徽章才會真的顯示對應階級的稱號，等級到了但徽章沒集滿就卡在一轉。
         * 這裡不動 career_stage()（純等級換算的通用函式，其他地方可能還要
         * 用原始換算結果），只在「決定要顯示什麼稱號」這一步擋。
         */
        if ( $stage >= 2 && class_exists( __NAMESPACE__ . '\\First_Badge' ) && ! First_Badge::user_has_all_badges( $uid ) ) {
            $stage = 1;
        }

        // 防呆：擴充 job 時可能少填某一階
        if ( ! isset( $jobs[ $key ]['titles'][ $stage ] ) ) return [];

        $t = $jobs[ $key ]['titles'][ $stage ];
        return [
            'job_key'    => $key,
            'job_label'  => $jobs[ $key ]['label'],
            'job_icon'   => $jobs[ $key ]['icon'],
            'stage'      => $stage,
            'title_name' => $t['name'] ?? '',
            'title_ref'  => $t['ref']  ?? '',
        ];
    }
}
