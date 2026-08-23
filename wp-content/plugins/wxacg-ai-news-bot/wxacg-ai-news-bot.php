<?php
/**
 * Plugin Name: 微笑動漫 AI 新聞自駕中心
 * Description: 提供海外新聞一鍵抓取、自動翻譯、分類排版與實時終端監控的高效能管理工具。
 * Version: 2.3.0
 * Author: 微笑動漫開發組
 * Text Domain: wxacg-ai-news
 */

if (!defined('ABSPATH')) {
    exit; # 防止直接訪問，保障資安
}

class WXACG_AI_News_Engine_Plugin {

    # =====================================================================
    # 全站共用 Gemini API Key 池
    # =====================================================================
    # 原本 API Key 是每位主編各自存在 user_meta，且明文會被渲染進 <input value="...">，
    # 改為全站共用池後若沿用該寫法，任何具 use_wxacg_ai_news 權限者（含編輯角色）
    # 都能從網頁原始碼看到全部金鑰，故改採「管理員限定 + 明文永不回填 + 管理密碼」三重保護。

    # 換行分隔的 Key 池
    const KEY_POOL_OPTION = 'wxacg_ai_news_shared_keys';
    # 輪替游標：跨任務接續前進，讓每次派工從不同一把開始，平均分攤各把 Key 的用量與速率限制
    const KEY_CURSOR_OPTION = 'wxacg_ai_news_key_cursor';
    # 管理密碼雜湊（以 wp_hash_password 產生，永不存明文）
    const KEY_PASSWORD_OPTION = 'wxacg_ai_news_key_password_hash';

    # 雲端伺服端點的解鎖密碼雜湊。
    # 舊版是把明文密碼存在 wxacg_ai_news_unlock_password 並直接渲染成
    # <input type="text" value="...">，等於畫面上與網頁原始碼都看得到，
    # 前端 JS 再拿它跟使用者輸入比對——形同虛設。改為與金鑰池相同的
    # 伺服器端雜湊驗證。舊的明文設定會在首次載入時自動轉換並刪除。
    const CLOUD_PASSWORD_OPTION = 'wxacg_ai_news_cloud_password_hash';
    const LEGACY_CLOUD_PASSWORD_OPTION = 'wxacg_ai_news_unlock_password';

    # 可選用的 Gemini 模型清單（雲端引擎 cloud_engine.py 僅支援 Gemini，故不提供其他供應商）
    const GEMINI_MODELS = [
        'gemini-3.7-flash' => 'Gemini 3.7 Flash',
        'gemini-3.6-flash' => 'Gemini 3.6 Flash',
        'gemini-3.5-flash' => 'Gemini 3.5 Flash',
    ];
    const DEFAULT_MODEL = 'gemini-3.7-flash';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'register_custom_capability']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        # 註冊 AJAX 下單與輪詢處理
        add_action('wp_ajax_wxacg_trigger_ai_news', [$this, 'handle_trigger_ai_news']);
        add_action('wp_ajax_wxacg_poll_task_status', [$this, 'handle_poll_task_status']);
        # 中止進行中的雲端任務：Key 數量多時 503 連續發生可能跑很久，需讓操作者隨時喊停
        add_action('wp_ajax_wxacg_cancel_ai_news', [$this, 'handle_cancel_ai_news']);

        # 金鑰池資安鎖的解鎖驗證。
        # 密碼以雜湊存於資料庫，前端拿不到明文，無法比照雲端端點那組在瀏覽器直接比對，
        # 故改由此端點在伺服器端驗證後才回覆前端可否展開面板。
        add_action('wp_ajax_wxacg_verify_key_password', [$this, 'handle_verify_key_password']);
        add_action('wp_ajax_wxacg_verify_cloud_password', [$this, 'handle_verify_cloud_password']);

        # 註冊雙軌獨立保存自定處理端點 (區分全域與使用者獨立金鑰)
        add_action('admin_post_wxacg_save_settings', [$this, 'handle_save_settings']);

        # 作品名稱自動內部連結：輸出文章內容時即時加上作品頁連結 (優先權 20，晚於 wpautop 避免干擾段落處理)
        add_filter('the_content', [$this, 'autolink_anime_titles'], 20);
        # 動畫資料異動時清除對照表快取，讓新建立的作品頁能立即被連結。
        # 刪除／丟垃圾桶／還原都要處理，否則文章會在快取到期前持續連向 404 頁面；
        # 且一律先判斷 post_type，避免修訂版本裁切、刪除媒體等無關操作把快取洗掉。
        add_action('save_post_anime', [$this, 'flush_anime_link_map']);
        add_action('deleted_post', [$this, 'flush_anime_link_map_for_post'], 10, 2);
        add_action('trashed_post', [$this, 'flush_anime_link_map_for_post']);
        add_action('untrashed_post', [$this, 'flush_anime_link_map_for_post']);
    }

    /**
     * 註冊獨立的客製化能力權柄，令【User Role Editor】得以順遂捉捕該名字牌 (use_wxacg_ai_news)
     */
    public function register_custom_capability() {
        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('use_wxacg_ai_news')) {
            $admin_role->add_cap('use_wxacg_ai_news');
        }
        $editor_role = get_role('editor');
        if ($editor_role && !$editor_role->has_cap('use_wxacg_ai_news')) {
            $editor_role->add_cap('use_wxacg_ai_news');
        }
    }

    /**
     * 註冊管理側邊欄頁面
     */
    public function register_admin_menu() {
        add_menu_page(
            'AI 新聞發佈中心',
            'AI 新聞部',
            'use_wxacg_ai_news', # 改赴【方案B】獨一無二之御用權狀，憑牌放行不憑舊有單面階級
            'wxacg-ai-news-engine',
            [$this, 'render_admin_dashboard'],
            'dashicons-media-text',
            6
        );
    }

    /**
     * 註冊常規與隱藏型保護設置欄位 (供全網一體維持共用的通用參數庫)
     */
    public function register_settings() {
        # 將原全域模組移離至各私人帳號的 user_meta 區間自帶維和；留其餘共用站址維持全域選項
        register_setting('wxacg_ai_news_settings_group', 'wxacg_ai_news_cloud_url', ['default' => '']);
        /*
         * 通訊 Token 不再提供預設值。
         *
         * 舊版把 'wxacg-super-secret-master-key-2026' 寫死成預設值，
         * 而這串字同時存在於本外掛與 cloud_engine.py 的原始碼中（且已進版控），
         * 任何看得到原始碼的人都能直接冒用雲端運算資源。
         * 改為預設空字串並在派工前檢查，強制必須自行設定一組真正的密語。
         */
        register_setting('wxacg_ai_news_settings_group', 'wxacg_ai_news_cloud_token', ['default' => '']);
        # 解鎖密碼改存雜湊（見 CLOUD_PASSWORD_OPTION），不再以明文註冊為設定項
        register_setting('wxacg_ai_news_settings_group', 'wxacg_ai_news_enable_autolink', ['default' => '0']);
    }

    # =====================================================================
    # 全站共用 Gemini Key 池：讀取、輪替與密碼保護
    # =====================================================================

    /**
     * 取得 Key 池中所有金鑰（已去除空白行）。
     *
     * @return array<int,string>
     */
    private function get_pool_keys() {
        $raw = (string) get_option(self::KEY_POOL_OPTION, '');
        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    /**
     * 取得整池金鑰，並以目前輪替游標為起點重新排序，同時把游標往前推一格。
     *
     * 之所以整池一次送給雲端而非只送一把：cloud_engine.py 會在自己的重試迴圈裡
     * 於額度不足時就地換下一把，只需重跑 AI 那一步；若改由 WordPress 端換 Key 重送，
     * 每換一把都得把爬原文、建詞典整條流水線重跑一遍，既慢又會對來源站重複發爬蟲請求。
     *
     * 游標每次派工 +1，使得各把 Key 平均分攤用量，避免集中消耗單一把而撞上每分鐘速率上限。
     *
     * @return array<int,string> 空池時回傳空陣列（由呼叫端負責回報錯誤）
     */
    private function get_rotated_keys() {
        $keys  = $this->get_pool_keys();
        $count = count($keys);
        if ($count === 0) {
            return [];
        }

        # 游標可能因舊資料或併發而異常，先夾回合法範圍再取餘數，避免取到不存在的索引
        $cursor = max(0, (int) get_option(self::KEY_CURSOR_OPTION, 0));
        $start  = $cursor % $count;

        # 以 $start 為起點重排：例如 [A,B,C] 游標 1 → [B,C,A]
        $rotated = array_merge(array_slice($keys, $start), array_slice($keys, 0, $start));

        update_option(self::KEY_CURSOR_OPTION, ($start + 1) % $count, false);

        return $rotated;
    }

    /**
     * 是否已設定 Key 池管理密碼。
     *
     * 回傳 false 代表「首次設定模式」：管理員可免密碼直接設定 Key 與密碼，讓第一次能順利完成。
     */
    private function is_key_password_set() {
        return '' !== (string) get_option(self::KEY_PASSWORD_OPTION, '');
    }

    /**
     * 驗證 Key 池管理密碼。尚未設定密碼時一律放行（首次設定模式）。
     */
    private function verify_key_password($password) {
        if (!$this->is_key_password_set()) {
            return true;
        }
        if ('' === (string) $password) {
            return false;
        }
        return wp_check_password($password, (string) get_option(self::KEY_PASSWORD_OPTION, ''));
    }

    # =====================================================================
    # 雲端伺服端點的解鎖密碼（與金鑰池採同一套伺服器端雜湊驗證）
    # =====================================================================

    /**
     * 將舊版的明文解鎖密碼轉為雜湊後刪除明文。
     *
     * 舊版把密碼明文存在 option 並渲染進 HTML，升級後必須主動清掉，
     * 否則明文會一直留在資料庫裡。轉換只做一次，之後兩者都不存在明文。
     */
    private function migrate_legacy_cloud_password() {
        if ('' !== (string) get_option(self::CLOUD_PASSWORD_OPTION, '')) {
            return; // 已是新版，無須處理
        }

        $legacy = (string) get_option(self::LEGACY_CLOUD_PASSWORD_OPTION, '');
        if ('' === $legacy) {
            return; // 從未設定過，維持「首次設定模式」
        }

        update_option(self::CLOUD_PASSWORD_OPTION, wp_hash_password($legacy), false);
        delete_option(self::LEGACY_CLOUD_PASSWORD_OPTION);
        error_log('wxacg-ai-news: 已將舊版明文解鎖密碼轉為雜湊並刪除明文設定。');
    }

    /**
     * 是否已設定雲端端點解鎖密碼。
     */
    private function is_cloud_password_set() {
        return '' !== (string) get_option(self::CLOUD_PASSWORD_OPTION, '');
    }

    /**
     * 驗證雲端端點解鎖密碼。尚未設定時一律放行（首次設定模式）。
     */
    private function verify_cloud_password($password) {
        if (!$this->is_cloud_password_set()) {
            return true;
        }
        if ('' === (string) $password) {
            return false;
        }
        return wp_check_password($password, (string) get_option(self::CLOUD_PASSWORD_OPTION, ''));
    }

    /**
     * AJAX：驗證雲端端點解鎖密碼，決定前端能否展開設定面板。
     *
     * 與金鑰池同理：這只是畫面上的鎖，真正的防線在儲存時的伺服器端檢查。
     */
    public function handle_verify_cloud_password() {
        check_ajax_referer('wxacg_ai_news_action_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '權限不足：只有網站管理員可以檢視或修改雲端伺服端點。']);
        }

        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        if (!$this->verify_cloud_password($password)) {
            wp_send_json_error(['message' => '解鎖密碼錯誤。']);
        }

        wp_send_json_success(['message' => '驗證通過']);
    }

    /**
     * AJAX：驗證金鑰池管理密碼，決定前端能否展開編輯面板。
     *
     * 注意：這裡只是「畫面上的鎖」，真正的防線仍在 handle_key_pool_save()——
     * 即使有人略過前端直接送出表單，沒有正確密碼一樣寫不進 Key 池。
     * 因此本端點不需要發放通行證或維護解鎖狀態，單純回答密碼對不對即可。
     */
    public function handle_verify_key_password() {
        check_ajax_referer('wxacg_ai_news_action_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '權限不足：只有網站管理員可以檢視或修改全站共用金鑰池。']);
        }

        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        if (!$this->verify_key_password($password)) {
            wp_send_json_error(['message' => '管理密碼錯誤，無法解鎖。']);
        }

        wp_send_json_success(['message' => '驗證通過']);
    }

    /**
     * 取得目前生效的模型名稱。
     *
     * 舊版本此欄位是自由文字輸入，使用者可能存過清單以外的型號，
     * 這裡原樣沿用不強制重設，避免既有設定被靜默改掉。
     */
    private function get_current_model($user_id) {
        $model = get_user_meta($user_id, 'wxacg_ai_news_model', true);
        return empty($model) ? self::DEFAULT_MODEL : $model;
    }

    /**
     * 載入樣式表及前端 JavaScript 大員
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_wxacg-ai-news-engine') {
            return;
        }
        wp_enqueue_style('wxacg-ai-admin-style', plugin_dir_url(__FILE__) . 'admin-style.css', [], filemtime(plugin_dir_path(__FILE__) . 'admin-style.css'));
        wp_enqueue_script('wxacg-ai-admin-script', plugin_dir_url(__FILE__) . 'admin-script.js', ['jquery'], filemtime(plugin_dir_path(__FILE__) . 'admin-script.js'), true);
        
        wp_localize_script('wxacg-ai-admin-script', 'wxacgAIParams', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wxacg_ai_news_action_nonce')
        ]);
    }

    /**
     * 掛載前台共用樣式表：AI 新聞文章的固定版面/間距/字體規則統一寫在這一份 CSS，
     * 全站只載入一次、瀏覽器可跨頁快取，避免每篇文章各自內嵌重複樣式拖累頁面體積。
     */
    public function enqueue_frontend_assets() {
        $css_path = plugin_dir_path(__FILE__) . 'wx-majo-news.css';
        wp_enqueue_style(
            'wxacg-ai-news-article',
            plugin_dir_url(__FILE__) . 'wx-majo-news.css',
            [],
            file_exists($css_path) ? filemtime($css_path) : '1.0.0'
        );
    }

    # =====================================================================
    # 作品名稱自動內部連結 (Internal Linking)
    # =====================================================================

    # 對照表快取鍵名與最短可連結標題字數 (太短的通用詞容易誤命中，一律不納入)
    const ANIME_LINK_MAP_TRANSIENT = 'wxacg_anime_link_map';
    const AUTOLINK_MIN_TITLE_LENGTH = 3;
    # 不設單篇「總連結數」上限：像「異世界動畫大整理」這類樞紐型文章動輒收錄數十部作品，
    # 逐一連向各自作品頁屬合理結構，非過度優化。
    # 但同一部作品在同一篇文章內最多連結 3 次，避免同一目標被重複連結而顯得像自動洗連結。
    # 又因掃描以「文字節點」為單位、每個節點內同一作品只比對一次，
    # 第 2、3 條必然落在不同段落或小標，短篇新聞自然用不滿額度，無須另外判斷文章長短。
    const AUTOLINK_MAX_PER_TITLE = 3;

    /**
     * 清除作品對照表快取。動畫資料新增/更新/刪除時觸發，
     * 讓新建立的作品頁不必等快取自然過期就能立即被文章連結。
     */
    public function flush_anime_link_map() {
        delete_transient(self::ANIME_LINK_MAP_TRANSIENT);
    }

    /**
     * 僅在異動對象確實是動畫時才清除對照表快取。
     *
     * deleted_post / trashed_post 等 hook 對所有文章類型都會觸發，
     * 若不判斷類型，修訂版本裁切、刪除媒體、每日清理自動草稿等日常操作
     * 都會把快取洗掉，導致對照表幾乎永遠處於冷啟動狀態而反覆重建。
     *
     * @param int          $post_id 文章 ID
     * @param WP_Post|null $post    文章物件（deleted_post 會帶入，此時資料已從資料庫移除）
     */
    public function flush_anime_link_map_for_post($post_id, $post = null) {
        $post_type = ($post instanceof WP_Post) ? $post->post_type : get_post_type($post_id);
        if ($post_type === 'anime') {
            $this->flush_anime_link_map();
        }
    }

    /**
     * 將 Unicode 碼位編碼為 UTF-8 位元組字串。
     * 自行實作而不使用 mb_chr()，因為 WordPress 核心的 compat.php 並未提供該函式的備援，
     * 主機若未啟用 mbstring 擴充會直接 Fatal Error。
     */
    private static function utf8_chr($codepoint) {
        if ($codepoint < 0x80) {
            return chr($codepoint);
        }
        if ($codepoint < 0x800) {
            return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
        }
        return chr(0xE0 | ($codepoint >> 12))
             . chr(0x80 | (($codepoint >> 6) & 0x3F))
             . chr(0x80 | ($codepoint & 0x3F));
    }

    /**
     * 取得「全形 → 半形」字元轉換對照表（只建立一次後重複使用）。
     * 解決作品頁標題與文章內文使用不同形式標點導致比對失敗的問題，
     * 例如資料庫寫「Re：從零開始的異世界生活」（全形冒號）、文章寫「Re:從零開始的異世界生活」（半形冒號）。
     */
    private static function get_width_normalize_table() {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        $table = [];
        # 全形 ASCII 區段 U+FF01～U+FF5E 對應到半形 U+0021～U+007E（差值固定 0xFEE0）
        for ($cp = 0xFF01; $cp <= 0xFF5E; $cp++) {
            $table[self::utf8_chr($cp)] = chr($cp - 0xFEE0);
        }
        # 全形空白與波浪號的各種變體
        $table[self::utf8_chr(0x3000)] = ' ';   // 表意文字空格
        $table[self::utf8_chr(0x301C)] = '~';   // 波浪號 〜
        $table[self::utf8_chr(0xFF5E)] = '~';   // 全形波浪號 ～
        $table[self::utf8_chr(0x00A0)] = ' ';   // 不斷行空格（trim() 不認得，需明確轉換才會被視為空白丟棄）
        # 印刷體標點：wptexturize 會把內文的直式引號轉為彎引號，資料庫標題則多為原始字元，兩邊統一為 ASCII
        $table[self::utf8_chr(0x2018)] = "'";
        $table[self::utf8_chr(0x2019)] = "'";
        $table[self::utf8_chr(0x201C)] = '"';
        $table[self::utf8_chr(0x201D)] = '"';
        $table[self::utf8_chr(0x2013)] = '-';
        $table[self::utf8_chr(0x2014)] = '-';
        $table[self::utf8_chr(0x2026)] = '...';
        return $table;
    }

    /**
     * 取得「HTML 實體 → ASCII」對照表。
     *
     * 本濾鏡掛在優先權 20，晚於 wptexturize（優先權 10），內文中的撇號與引號此時
     * 已被轉成 `&#8217;` 這類數值實體，而資料庫標題仍是原始字元，不處理就會完全比對不到
     * （例如「Don't Toy with Me, Miss Nagatoro」這類含撇號的作品名會靜默失效）。
     */
    private static function get_entity_normalize_table() {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        $table = [
            '&#8216;' => "'", '&#8217;' => "'", '&lsquo;' => "'", '&rsquo;' => "'",
            '&#39;'   => "'", '&#039;'  => "'", '&apos;'  => "'",
            '&#8220;' => '"', '&#8221;' => '"', '&ldquo;' => '"', '&rdquo;' => '"',
            '&quot;'  => '"',
            '&#8211;' => '-', '&ndash;' => '-', '&#8212;' => '-', '&mdash;' => '-',
            '&#8230;' => '...', '&hellip;' => '...',
            '&amp;'   => '&',
            '&nbsp;'  => ' ', '&#160;' => ' ',
        ];
        return $table;
    }

    /**
     * 比對用正規化：統一全半形、轉小寫，並移除空白與作品名常見的分隔符號。
     *
     * 之所以需要位置對照表，是因為正規化後的字串長度與原文不同，
     * 找到位置後必須換算回原文位移，才能在插入連結時保留文章原本的寫法。
     *
     * @param string     $text       原始文字
     * @param array|null $start_map  輸出參數：正規化位移 => 該字元在原文的「起始」位移
     * @param array|null $end_map    輸出參數：正規化位移 => 前一個字元在原文的「結束」位移
     * @return string 正規化後字串
     */
    private static function normalize_for_match($text, &$start_map = null, &$end_map = null) {
        $table = self::get_width_normalize_table();
        $entities = self::get_entity_normalize_table();
        # 作品名常見但兩邊寫法可能不一致的分隔符號，一律移除後再比對
        $droppable = ['・' => 1, '･' => 1, '·' => 1, '‧' => 1];

        # 以「HTML 實體或單一 UTF-8 字元」為切割單位，讓 &#8217; 這類實體能整組轉換
        if (!preg_match_all('/&(?:#\d{1,6}|#x[0-9a-fA-F]{1,5}|[a-zA-Z][a-zA-Z0-9]{1,9});|./us', $text, $matches)) {
            $start_map = [0 => 0];
            $end_map = [0 => 0];
            return '';
        }

        $normalized = '';
        $start_map = [];
        # end_map[0] 對應「尚未取用任何字元」的狀態
        $end_map = [0 => 0];
        $original_offset = 0;

        foreach ($matches[0] as $token) {
            $byte_length = strlen($token);
            $lower_token = strtolower($token);

            if (isset($entities[$lower_token])) {
                $converted = $entities[$lower_token];
            } elseif (isset($table[$token])) {
                $converted = $table[$token];
            } else {
                $converted = $token;
            }

            # 空白與分隔符號不納入比對字串（僅推進原文位移）
            if ($converted === '' || isset($droppable[$token]) || trim($converted) === '') {
                $original_offset += $byte_length;
                continue;
            }

            # 起始位移記錄在字元邊界上，供比對命中後換算回原文
            $start_map[strlen($normalized)] = $original_offset;
            $normalized .= strtolower($converted);
            $original_offset += $byte_length;
            # 結束位移必須單獨記錄：若只靠「下一個字元的起始位移」，
            # 中間被丟棄的空白或分隔符號會被一併算進比對範圍，
            # 導致連結文字多吃一個尾隨空白（例如「咒術迴戰 」）而在前台多畫出一截底線
            $end_map[strlen($normalized)] = $original_offset;
        }

        # 結尾哨兵：讓「比對結束位置」在全部字元都被丟棄時仍可換算
        if (!isset($start_map[strlen($normalized)])) {
            $start_map[strlen($normalized)] = $original_offset;
        }
        return $normalized;
    }

    /**
     * 取得「作品標題 → 作品頁網址」對照表 (以 Transient 快取 12 小時，避免每次瀏覽都查資料庫)。
     * 回傳陣列已按標題長度由長至短排序，確保較具體的長標題優先命中，
     * 不會被同系列的短標題搶先比對成功。
     */
    private function get_anime_link_map() {
        $map = get_transient(self::ANIME_LINK_MAP_TRANSIENT);
        if (is_array($map)) {
            return $map;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_type = 'anime' AND post_status = 'publish' AND post_title != ''"
        );

        # 【避免 N+1 查詢】原生 SQL 只取回 ID 與標題，不會填入 WP 的文章物件快取，
        # 後面每次 get_permalink() 都會各自再送一次 SELECT（近千筆就是近千條查詢）。
        # 先分批預先載入文章快取，讓後續 get_permalink() 全部命中記憶體。
        if (is_array($rows) && !empty($rows)) {
            $post_ids = array_map(function ($row) {
                return (int) $row->ID;
            }, $rows);
            foreach (array_chunk($post_ids, 200) as $chunk) {
                _prime_post_caches($chunk, false, false);
            }
        }

        # 對照表以「正規化後的標題」為鍵，比對時才能忽略全半形與空白差異
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $title = trim($row->post_title);
                # 過短的標題（如兩字通用詞）容易在內文誤命中，直接略過不建立連結
                if (mb_strlen($title, 'UTF-8') < self::AUTOLINK_MIN_TITLE_LENGTH) {
                    continue;
                }
                $normalized = self::normalize_for_match($title);
                if ($normalized === '' || isset($map[$normalized])) {
                    continue;
                }
                $map[$normalized] = [
                    'id'  => (int) $row->ID,
                    'url' => get_permalink((int) $row->ID),
                ];
            }
        }

        # 依標題長度由長到短排序：先比對《咒術迴戰 第二季》再比對《咒術迴戰》，避免長標題永遠命不中
        uksort($map, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        set_transient(self::ANIME_LINK_MAP_TRANSIENT, $map, 12 * HOUR_IN_SECONDS);
        return $map;
    }

    /**
     * the_content 過濾器：輸出文章時即時把內文中的作品名稱轉為指向站內作品頁的連結。
     *
     * 特性：
     * - 不修改資料庫內容，純輸出時加工，關閉開關即完全復原。
     * - 既有舊文章同樣生效（回溯）；日後新增作品頁後，舊文章下次瀏覽時自動補上連結。
     * - 同一部作品最多連結 AUTOLINK_MAX_PER_TITLE 次，且同一段落內不重複；
     *   不限制單篇「總連結數」，讓整理型文章能逐一連向各作品頁。
     */
    public function autolink_anime_titles($content) {
        # 未啟用開關時完全不介入
        if (get_option('wxacg_ai_news_enable_autolink', '0') !== '1') {
            return $content;
        }

        # 僅處理前台單篇內容主迴圈，排除後台、RSS、摘要與各式列表頁
        if (is_admin() || is_feed() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        # 作品頁本身不需要再連向自己
        if (get_post_type() === 'anime') {
            return $content;
        }

        $map = $this->get_anime_link_map();
        if (empty($map)) {
            return $content;
        }

        $current_id = get_the_ID();
        # 記錄本篇各作品已連結次數，超過 AUTOLINK_MAX_PER_TITLE 就不再連結
        $title_link_counts = [];
        # 【防巢狀連結】剛插入的 <a> 先以佔位符代替，避免後續較短的作品名比對時，
        # 命中前一輪已插入的 anchor 內部文字，產生 <a> 包 <a> 的不合法結構（例：「咒術迴戰 死滅迴游」與「咒術迴戰」）
        $link_placeholders = [];

        # 以標籤為界切開內容，只在「純文字節點」進行替換，
        # 避免破壞 HTML 屬性值，也避免在既有連結或標題內重複加連結
        $parts = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $content;
        }

        # 這些標籤內的文字一律跳過：既有連結、文章主標題、樣式與腳本區塊。
        # h2~h6 刻意「不」排除：整理型文章常把作品名放在段落小標，那正是最該連向作品頁的位置；
        # 僅 h1 為文章自身主標題，不應連出。
        $excluded_tags = ['a', 'h1', 'style', 'script'];
        # 區塊層級標籤：進入新區塊時重置該區塊的去重清單，確保「同一段落內同一作品不重複連結」
        # （單靠文字節點判斷不足，因為 <p>文字<strong>文字</strong></p> 會被切成多個文字節點）
        $block_tags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'td', 'th',
                       'blockquote', 'div', 'figcaption', 'section', 'article'];
        $skip_depth = 0;
        $block_linked = [];

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            # 標籤本體：只更新排除區塊的巢狀深度與區塊邊界，不做任何替換
            if ($part[0] === '<') {
                if (preg_match('#^</\s*([a-zA-Z0-9]+)#', $part, $m)) {
                    if (in_array(strtolower($m[1]), $excluded_tags, true) && $skip_depth > 0) {
                        $skip_depth--;
                    }
                } elseif (preg_match('#^<\s*([a-zA-Z0-9]+)#', $part, $m)) {
                    $tag_name = strtolower($m[1]);
                    $is_self_closing = substr(rtrim($part), -2) === '/>';
                    if (in_array($tag_name, $excluded_tags, true) && !$is_self_closing) {
                        $skip_depth++;
                    }
                    if (in_array($tag_name, $block_tags, true)) {
                        $block_linked = [];
                    }
                }
                continue;
            }

            # 位於排除標籤內的文字不處理
            if ($skip_depth > 0) {
                continue;
            }

            $text = $part;
            # 先算出這段文字的正規化版本與位移對照表，之後每命中一次就重算，確保對照表與文字同步
            $normalized_text = self::normalize_for_match($text, $start_map, $end_map);

            foreach ($map as $normalized_title => $info) {
                # 文章若正好關聯到自己（例如作品同名文章），略過避免自我連結
                if ($info['id'] === $current_id || empty($info['url'])) {
                    continue;
                }
                # 此作品在本篇已達連結次數上限，或已在目前這個段落連結過，就跳過
                if (isset($title_link_counts[$normalized_title])
                    && $title_link_counts[$normalized_title] >= self::AUTOLINK_MAX_PER_TITLE) {
                    continue;
                }
                if (isset($block_linked[$normalized_title])) {
                    continue;
                }

                # 【刻意使用位元組版 strpos/substr】WordPress 核心 compat.php 只在缺少 mbstring 時補上
                # mb_substr / mb_strlen，並未提供 mb_strpos；若主機未啟用 mbstring，呼叫 mb_strpos 會直接 Fatal Error。
                # 這裡比對的是正規化字串，位移再透過對照表換算回原文，故位元組運算安全且無外部相依。
                # 逐一往後找，直到取得一個通過詞界檢查的位置為止
                $title_length = strlen($normalized_title);
                $starts_with_alnum = ctype_alnum($normalized_title[0]);
                $ends_with_alnum = ctype_alnum($normalized_title[$title_length - 1]);
                $search_from = 0;
                $original_start = null;
                $original_end = null;

                while (($candidate = strpos($normalized_text, $normalized_title, $search_from)) !== false) {
                    $candidate_end = $candidate + $title_length;
                    # 位移必落在字元邊界上，理論上必定存在；防禦性檢查避免異常資料造成錯位切割。
                    # 結束位移取自 end_map，避免把後方被丟棄的空白或分隔符號一併吃進連結文字。
                    if (!isset($start_map[$candidate]) || !isset($end_map[$candidate_end])) {
                        $search_from = $candidate + 1;
                        continue;
                    }
                    $candidate_original_start = $start_map[$candidate];
                    $candidate_original_end = $end_map[$candidate_end];

                    # 【英數詞界檢查】若不檢查詞界，「Air」會命中 repair 當中的 air、把單字從中切斷。
                    # 必須在「原文」而非正規化字串上檢查：正規化會剝除空白，
                    # 在正規化字串上看，"The Air anime" 的 air 前面會變成字母 e 而被誤判為單字內部。
                    # 中日文標題不受影響（首尾非英數字元時不觸發檢查）。
                    $prev_is_alnum = $starts_with_alnum && $candidate_original_start > 0
                        && ctype_alnum($text[$candidate_original_start - 1]);
                    $next_is_alnum = $ends_with_alnum && isset($text[$candidate_original_end])
                        && ctype_alnum($text[$candidate_original_end]);

                    # 【英文作品名大小寫檢查】詞界檢查擋不掉語意歧義：
                    # 「One Piece」在 "one piece of good news" 中前後都是空白，詞界完全合法卻不是在講作品。
                    # 英文作品名在文章中一律會是首字大寫的專有名詞，故要求原文首字母為大寫才視為命中。
                    $case_mismatch = ctype_alpha($normalized_title[0])
                        && !ctype_upper($text[$candidate_original_start]);

                    if (!$prev_is_alnum && !$next_is_alnum && !$case_mismatch) {
                        $original_start = $candidate_original_start;
                        $original_end = $candidate_original_end;
                        break;
                    }
                    $search_from = $candidate + 1;
                }

                if ($original_start === null) {
                    continue;
                }
                # 連結文字取用「原文實際寫法」而非資料庫標題，保留文章原本的標點與空白
                $matched_text = substr($text, $original_start, $original_end - $original_start);

                $anchor = '<a href="' . esc_url($info['url']) . '" class="wxacg-autolink">' . esc_html($matched_text) . '</a>';
                # 以不含中文與角括號的控制字元佔位符暫代，待全部比對結束後再還原成真正的 <a> 標籤
                $token = "\x02WXACGLINK" . count($link_placeholders) . "\x02";
                $link_placeholders[$token] = $anchor;

                $text = substr($text, 0, $original_start)
                      . $token
                      . substr($text, $original_end);

                $title_link_counts[$normalized_title] = ($title_link_counts[$normalized_title] ?? 0) + 1;
                $block_linked[$normalized_title] = true;
                # 文字已變動，重新計算正規化字串與位移對照表，供後續作品名繼續比對
                $normalized_text = self::normalize_for_match($text, $start_map, $end_map);
            }
            $parts[$index] = $text;
        }

        $output = implode('', $parts);
        # 還原佔位符為實際連結
        if (!empty($link_placeholders)) {
            $output = str_replace(array_keys($link_placeholders), array_values($link_placeholders), $output);
        }

        return $output;
    }

    /**
     * 後台主主控板渲染
     */
    public function render_admin_dashboard() {
        # 讀取當前管理人的帳號認證狀態與ID (僅供閱讀與提取身分專用 Meta)
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_display_identity = $current_user->exists() ? ($current_user->user_login . ' (' . $current_user->user_email . ')') : '未知';

        # 抓取目前全網頁所建設好的文章分類以及頻道分類 (Channel)
        $categories = get_categories(['hide_empty' => false]);
        $channel_terms = get_terms(['taxonomy' => 'channel', 'hide_empty' => false]);
        if (is_wp_error($channel_terms)) {
            $channel_terms = [];
        }

        # 【個人隔離區】由「User Meta」撈出，若是從沒填打或初度造訪的新主編皆為空白無字或取預設值！
        # 註：AI 授權金鑰已改為全站共用 Key 池（見下方 $pool_count），不再逐人保存。
        $app_pass = get_user_meta($user_id, 'wxacg_ai_news_app_password', true);
        $model_name = $this->get_current_model($user_id);
        $post_status = get_user_meta($user_id, 'wxacg_ai_news_post_status', true);
        if (empty($post_status)) { $post_status = 'draft'; }

        # 【全站共用 Key 池】只有管理員能檢視編輯框與修改；一般主編僅看得到目前把數。
        # 已儲存的金鑰明文一律不回填進 HTML，避免任何人從網頁原始碼直接讀走整池金鑰。
        $can_manage_keys = current_user_can('manage_options');
        $pool_count      = count($this->get_pool_keys());
        $key_password_set = $this->is_key_password_set();

        # 【全網公務區】獲取選項表中所儲藏的常規總局配置 (雲端伺服連接點與鎖)
        $cloud_url = get_option('wxacg_ai_news_cloud_url', '');
        # 舊版明文密碼在此轉為雜湊並刪除明文（只會執行一次）
        $this->migrate_legacy_cloud_password();
        $cloud_password_set = $this->is_cloud_password_set();

        /*
         * Token 一律不回填進 HTML。
         *
         * 舊版直接渲染成 <input type="text" value="...">，即使外層面板以 CSS 隱藏，
         * 內容仍完整存在於網頁原始碼中，任何能開啟本頁的人檢視原始碼即可取得。
         * 改為只顯示「是否已設定」，欄位留空代表不變更（與金鑰池的盲寫一致）。
         */
        $cloud_token_set = '' !== (string) get_option('wxacg_ai_news_cloud_token', '');
        $enable_autolink = get_option('wxacg_ai_news_enable_autolink', '0');

        ?>
        <div class="wrap wxacg-ai-container">
            <h1 class="wxacg-title">AI 新聞發佈中心</h1>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true') : ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom:20px; padding: 12px 16px; border-left: 4px solid #46b450; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <p style="margin:0; font-size:14px; font-weight:600; color:#1b4a24;">✔ 儲存順暢：您本帳戶專注綁定的授權金鑰與總體設定皆已安穩記錄成妥！</p>
                </div>
            <?php endif; ?>

            <?php
            # Key 池與管理密碼的個別處理結果。這幾項與其他設定分開回報，
            # 是因為密碼驗證失敗時只會擋下 Key 池的變更，其餘設定仍照常儲存，
            # 若共用同一則「已儲存」訊息，使用者會誤以為金鑰也一併更新了。
            $key_notices = [
                'saved'     => ['success', '🔑 共用 Key 池已整批更新完成。'],
                'badpass'   => ['error',   '⛔ 管理密碼錯誤，共用 Key 池未變更（其餘設定已正常儲存）。'],
                'nopass'    => ['error',   '⛔ 未輸入管理密碼，共用 Key 池未變更（其餘設定已正常儲存）。'],
                'nofield'   => ['error',   '⚠️ 伺服器沒有收到金鑰欄位，Key 池未變更。此欄位在送達 PHP 前就被移除了，通常是安全性外掛或主機防火牆(WAF)攔截了含金鑰的內容，請檢查相關規則或洽主機商。'],
                'writefail' => ['error',   '⛔ 金鑰已送達並通過驗證，但寫入資料庫後讀回的內容不符，Key 池可能未實際保存。常見於持久化物件快取或資料庫寫入受限，詳情請查看伺服器錯誤日誌。'],
                'pwsaved'   => ['success', '🔐 Key 池管理密碼已更新完成。'],
                'pwbad'     => ['error',   '⛔ 舊密碼錯誤，管理密碼未變更。'],
                'pwshort'   => ['error',   '⛔ 新密碼長度至少需 6 個字元，管理密碼未變更。'],
                'pwmismatch' => ['error',  '⛔ 兩次輸入的新密碼不一致，管理密碼未變更。'],
            ];
            $key_msg = isset($_GET['key_msg']) ? sanitize_key($_GET['key_msg']) : '';
            if (isset($key_notices[$key_msg])) :
                list($notice_type, $notice_text) = $key_notices[$key_msg];
                $border = ($notice_type === 'success') ? '#46b450' : '#d63638';
                $color  = ($notice_type === 'success') ? '#1b4a24' : '#8a1f21';
            ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible" style="margin-bottom:20px; padding: 12px 16px; border-left: 4px solid <?php echo esc_attr($border); ?>; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <p style="margin:0; font-size:14px; font-weight:600; color:<?php echo esc_attr($color); ?>;"><?php echo esc_html($notice_text); ?></p>
                    <?php
                    # 寫入驗證失敗時附上實際數字，讓管理員不必翻伺服器日誌就能判斷是哪一種問題
                    $key_diag = get_transient('wxacg_ai_news_key_diag_' . $user_id);
                    if ($key_diag && in_array($key_msg, ['writefail', 'saved'], true)) :
                        delete_transient('wxacg_ai_news_key_diag_' . $user_id);
                    ?>
                        <p style="margin:8px 0 0 0; font-size:12.5px; color:#50575e; font-family:monospace; background:#f6f7f7; padding:8px 10px; border-radius:4px;">
                            診斷：<?php echo esc_html($key_diag); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            # 雲端端點的處理結果。與金鑰池分開回報，理由相同：
            # 密碼驗證失敗時只擋下端點設定，其餘設定仍照常儲存。
            $cloud_notices = [
                'saved'      => ['success', '⚙️ 雲端伺服端點設定已更新完成。'],
                'badpass'    => ['error',   '⛔ 解鎖密碼錯誤，雲端端點設定未變更（其餘設定已正常儲存）。'],
                'nopass'     => ['error',   '⛔ 未通過解鎖驗證，雲端端點設定未變更。請先按「解除隔離鎖」再修改。'],
                'pwsaved'    => ['success', '🔑 雲端端點解鎖密碼已更新完成。'],
                'pwbad'      => ['error',   '⛔ 舊的解鎖密碼錯誤，密碼未變更。'],
                'pwshort'    => ['error',   '⛔ 新密碼長度至少需 6 個字元，密碼未變更。'],
                'pwmismatch' => ['error',   '⛔ 兩次輸入的新密碼不一致，密碼未變更。'],
                'tokenbad'   => ['error',   '⛔ Token 含有不可見的控制字元（可能是複製時夾帶了換行或跳格），未儲存。請重新複製一次乾淨的內容。'],
                'tokenlong'  => ['error',   '⛔ Token 長度超過 255 字元，未儲存。請確認是否誤貼了多餘內容。'],
            ];
            $cloud_msg_code = isset($_GET['cloud_msg']) ? sanitize_key($_GET['cloud_msg']) : '';
            if (isset($cloud_notices[$cloud_msg_code])) :
                list($c_type, $c_text) = $cloud_notices[$cloud_msg_code];
                $c_border = ($c_type === 'success') ? '#46b450' : '#d63638';
                $c_color  = ($c_type === 'success') ? '#1b4a24' : '#8a1f21';
            ?>
                <div class="notice notice-<?php echo esc_attr($c_type); ?> is-dismissible" style="margin-bottom:20px; padding: 12px 16px; border-left: 4px solid <?php echo esc_attr($c_border); ?>; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <p style="margin:0; font-size:14px; font-weight:600; color:<?php echo esc_attr($c_color); ?>;"><?php echo esc_html($c_text); ?></p>
                </div>
            <?php endif; ?>

            <!-- ================= 第二大類：日常新聞產製區 ================= -->
            <div class="wxacg-box">
                <h2 class="section-title">新聞產製操作區</h2>
                <div class="divider"></div>

                <div class="wxacg-grid">
                    <div class="wxacg-col">
                        <label for="wxacg_target_url"><strong>新聞網址 (Target URL)</strong></label>
                        <textarea id="wxacg_target_url" class="wxacg-textarea" rows="3" style="height: 80px; min-height: 80px; resize: vertical;" placeholder="請貼上海外報導原文網址...&#10;多頁新聞可每行貼一個網址（第1頁↵第2頁）"></textarea>
                        <div class="wxacg-rec-sources" style="margin-top: 10px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #d63638; font-size: 13.5px;">💡 建議新聞來源：</span>
                            <a href="https://www.oricon.co.jp/category/anime/" target="_blank" rel="noopener" class="button" style="text-decoration: none; border-radius: 99px; font-weight: 600; font-size: 13px; padding: 2px 14px;">Oricon</a>
                            <a href="https://www.animatetimes.com/anime/" target="_blank" rel="noopener" class="button" style="text-decoration: none; border-radius: 99px; font-weight: 600; font-size: 13px; padding: 2px 14px;">Animate</a>
                            <a href="https://animeanime.jp/" target="_blank" rel="noopener" class="button" style="text-decoration: none; border-radius: 99px; font-weight: 600; font-size: 13px; padding: 2px 14px;">Anime!Anime!</a>
                        </div>
                    </div>

                    <div class="wxacg-col">
                        <label for="wxacg_custom_glossary"><strong>自訂中文譯名 (優先強制採用)</strong></label>
                        <textarea id="wxacg_custom_glossary" class="wxacg-textarea" rows="3" placeholder="每行填寫一個名字， AI 均會自動找對照文字改為中字：&#10;勇者欣梅爾&#10;大魔法使芙莉蓮"></textarea>
                    </div>
                </div>

                <div class="wxacg-grid">
                    <div class="wxacg-col">
                        <label for="wxacg_target_category"><strong>預設文章分類</strong></label>
                        <select id="wxacg_target_category" class="wxacg-select">
                            <?php foreach ($categories as $cat) : ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($cat->term_id, 9); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="wxacg-col">
                        <label for="wxacg_target_channel"><strong>預設頻道標籤 (Channel)</strong></label>
                        <select id="wxacg_target_channel" class="wxacg-select">
                            <?php if (empty($channel_terms)) : ?>
                                <option value="12">動漫頻道 (預設)</option>
                            <?php else : ?>
                                <?php foreach ($channel_terms as $ch) : ?>
                                    <option value="<?php echo esc_attr($ch->term_id); ?>" <?php selected($ch->term_id, 12); ?>>
                                        <?php echo esc_html($ch->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="wxacg-action-row" style="display: flex; align-items: center; flex-wrap: wrap;">
                    <button id="wxacg_btn_generate" class="button button-primary wxacg-btn-simple">
                        開始生成報導
                    </button>
                    <div class="wxacg-style-wrap" style="display: flex; align-items: center; margin-left: 28px;">
                        <label for="wxacg_ai_style" style="font-weight: 700; color: #d63638; font-size: 14px; white-space: nowrap; margin: 0 8px 0 0; display: inline-block;">模板：</label>
                        <select id="wxacg_ai_style" class="wxacg-select" style="min-width: 180px; font-weight: 500; margin: 0;">
                            <option value="breaking">A. ⚡ 速報快訊型</option>
                            <option value="comprehensive" selected="selected">B. 📖 新作完整情報型</option>
                            <option value="pv_visual">C. 🎬 PV／視覺圖解讀型</option>
                            <option value="spec_guide">D. 📋 作品資訊整理型</option>
                            <option value="editorial_analysis">E. 🧐 編輯深度評析型</option>
                        </select>
                    </div>
                </div>

                <!-- 壓縮減低後的實用型即時監視器屏 -->
                <div class="wxacg-terminal-box">
                    <div class="terminal-bar">
                        <div class="terminal-dots">
                            <span class="dot red"></span>
                            <span class="dot yellow"></span>
                            <span class="dot green"></span>
                        </div>
                        <span class="terminal-header-title">&gt;_ AI ENGINE TERMINAL</span>
                        <button id="wxacg_btn_clear_log" class="button button-small terminal-clear-btn">清空記錄</button>
                    </div>
                    <div id="wxacg_terminal_screen" class="terminal-screen">
                        <div class="term-line"><span class="term-time">[system]</span> &gt; 終端機狀態待命中... 按下上面【開始生成報導】將每 2 秒回報雲端進度。</div>
                    </div>
                </div>
            </div>

            <!-- ================= 第一大類：永久通用參數庫與安全鎖設定 ================= -->
            <div class="wxacg-box wxacg-settings-box">
                <h2 class="section-title">核心授權與連線設定</h2>
                <div class="divider"></div>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="wxacg_save_settings">
                    <?php wp_nonce_field('wxacg_save_settings_nonce', 'wxacg_settings_nonce_field'); ?>

                    <table class="form-table wxacg-table">
                        <tr>
                            <th scope="row"><label for="wxacg_ai_news_app_password">WordPress 應用程式密碼<br><small style="color:#0073aa;">[帳戶個體獨立保存]</small></label></th>
                            <td>
                                <input type="text" id="wxacg_ai_news_app_password" name="wxacg_ai_news_app_password" class="regular-text code" value="<?php echo esc_attr($app_pass); ?>" placeholder="XXXX XXXX XXXX XXXX XXXX XXXX">
                                <p class="description">採個人獨立紀錄【使用者 ➔ 個人資料 ➔ 應用程式密碼➔ 新增一組密碼並貼回儲存】</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>綁定密碼的登入帳號</label></th>
                            <td>
                                <input type="text" class="regular-text" value="<?php echo esc_attr($user_display_identity); ?>" readonly style="background:#e9ecf0; color:#50575e; font-weight:600;">
                                <p class="description">僅為檢視，表示目前將以此人身分發行新稿。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>AI 供應商<br><small style="color:#0073aa;">[全站固定]</small></label></th>
                            <td>
                                <input type="text" class="regular-text" value="Google Gemini" readonly style="background:#e9ecf0; color:#50575e; font-weight:600;">
                                <p class="description">雲端 AI 引擎（<code>cloud_engine.py</code>）目前僅實作 Gemini，故此處固定不可切換。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wxacg_ai_news_model">使用模型名稱<br><small style="color:#0073aa;">[帳戶個體獨立保存]</small></label></th>
                            <td>
                                <select id="wxacg_ai_news_model" name="wxacg_ai_news_model" class="wxacg-select" style="min-width:220px;">
                                    <?php foreach (self::GEMINI_MODELS as $model_value => $model_label) : ?>
                                        <option value="<?php echo esc_attr($model_value); ?>" <?php selected($model_name, $model_value); ?>>
                                            <?php echo esc_html($model_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php
                                    # 舊版此欄位為自由文字輸入，使用者可能存過清單以外的型號。
                                    # 這裡把該值額外列為一個選項保留下來，避免改版後被靜默重設而不自知。
                                    if (!isset(self::GEMINI_MODELS[$model_name])) :
                                    ?>
                                        <option value="<?php echo esc_attr($model_name); ?>" selected>
                                            <?php echo esc_html($model_name); ?>（自訂）
                                        </option>
                                    <?php endif; ?>
                                </select>
                                <p class="description">預設推薦 <code>gemini-3.7-flash</code>。此設定為每位主編各自獨立保存。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>文章默認產出狀態<br><small style="color:#0073aa;">[帳戶個體獨立保存]</small></label></th>
                            <td>
                                <label><input type="radio" name="wxacg_ai_news_post_status" value="draft" <?php checked($post_status, 'draft'); ?>> 草稿 (Draft)</label> &nbsp;&nbsp;&nbsp;
                                <label><input type="radio" name="wxacg_ai_news_post_status" value="publish" <?php checked($post_status, 'publish'); ?>> 發佈(Publish)</label>
                                <p class="description">AI產出文章後的後台狀態【草稿or直接發佈】</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wxacg_ai_news_enable_autolink">作品名稱自動內部連結<br><small style="color:#0073aa;">[全站共用設定]</small></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="wxacg_ai_news_enable_autolink" name="wxacg_ai_news_enable_autolink" value="1" <?php checked($enable_autolink, '1'); ?>>
                                    啟用：自動把文章內文提到的作品名稱，轉為指向站內動畫作品頁的連結
                                </label>
                                <p class="description">
                                    套用範圍為<strong>全站所有文章</strong>（含既有舊文章），不限單篇作品數量；<br>
                                    同一部作品最多連結 3 次，且同一段落內不重複，短篇新聞通常僅 1～2 條。<br>
                                    段落小標（h2～h6）內的作品名同樣會連結，文章主標題（h1）與既有連結則不受影響。<br>
                                    此功能不會修改資料庫內的文章內容，僅在前台顯示時即時處理，取消勾選即完全復原。
                                </p>
                            </td>
                        </tr>

                        <!-- 加密防禦大門與密碼手設功能 -->
                        <tr class="wxacg-lock-section">
                            <th scope="row">
                                <label style="color:#c92a2a; font-weight:bold;">雲端 AI 伺服端點</label>
                            </th>
                            <td>
                                <?php if (!$can_manage_keys) : ?>
                                    <?php
                                    # 比照金鑰池：非管理員只顯示狀態，不給欄位。
                                    # 否則會看到能填卻存不了的輸入框（儲存需 manage_options），徒增困惑。
                                    ?>
                                    <p style="margin:0; font-size:14px;">
                                        雲端端點：
                                        <strong style="color:<?php echo $cloud_token_set ? '#186229' : '#d63638'; ?>;">
                                            <?php echo $cloud_token_set ? '🔒 已設定並上鎖' : '⚠️ 尚未設定'; ?>
                                        </strong>
                                    </p>
                                    <p class="description">
                                        此區由網站管理員統一維護，您無需（也無法）自行修改。<br>
                                        若生成持續失敗，請聯繫管理員確認端點與授權設定。
                                    </p>
                                <?php else : ?>

                                <?php if ($cloud_password_set) : ?>
                                <div id="wxacg_lock_guard_area" class="lock-panel">
                                    <p style="margin-top:0;"><strong>已啟用資安鎖，禁止隨意竄改雲端 AI 伺服端點位置</strong></p>
                                    <div>輸入解鎖密碼才能閱覽或修改：</div>
                                    <div style="margin-top:6px;">
                                        <input type="password" id="wxacg_unlock_input" class="regular-text" autocomplete="new-password" placeholder="解鎖通行口令">
                                        <button type="button" id="wxacg_btn_unlock" class="button button-secondary">🔓 解除隔離鎖</button>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div id="wxacg_cloud_secret_fields" class="unlock-panel" style="<?php echo $cloud_password_set ? 'display:none;' : ''; ?>">
                                    <?php if ($cloud_password_set) : ?>
                                        <div class="unlock-title">✅ 自我把守驗證順利！已展現深藏變數可親修修改：</div>
                                        <?php
                                        # 解鎖時由 JS 把剛才輸入的密碼填入此隱藏欄位隨表單送出，
                                        # 免去同一組密碼輸入兩次；儲存時伺服器端仍會再驗一次。
                                        ?>
                                        <input type="hidden" id="wxacg_ai_news_cloud_password" name="wxacg_ai_news_cloud_password" value="">
                                    <?php else : ?>
                                        <div class="unlock-title">⚙️ 雲端伺服端點設定（僅網站管理員可見）</div>
                                        <p style="margin:0 0 15px 0; color:#b32d2e; font-weight:600;">
                                            ⚠️ 尚未設定解鎖密碼（首次設定模式：目前任何管理員都能直接修改）<br>
                                            請於下方設定密碼，設定後本區塊就會自動上鎖。
                                        </p>
                                    <?php endif; ?>

                                    <label><strong>1. Cloud Run 主機專用連線網址 URL：</strong></label><br>
                                    <input type="text" id="wxacg_ai_news_cloud_url" name="wxacg_ai_news_cloud_url" class="large-text code" value="<?php echo esc_attr($cloud_url); ?>"><br>
                                    <p class="description" style="margin-top:2px; margin-bottom:12px;">將從 Google 帶歸來的 <code>https://xxxxx.a.run.app</code> 存放於這。</p>

                                    <label><strong>2. 伺服中心授權暗號 Token：</strong></label>
                                    <span style="margin-left:8px; font-weight:600; color:<?php echo $cloud_token_set ? '#186229' : '#d63638'; ?>;">
                                        <?php echo $cloud_token_set ? '🔒 已設定' : '⚠️ 尚未設定，無法生成報導'; ?>
                                    </span><br>
                                    <input type="password" id="wxacg_ai_news_cloud_token" name="wxacg_ai_news_cloud_token" class="regular-text code"
                                           autocomplete="new-password" placeholder="留空＝維持原本 Token 不變更">
                                    <button type="button" id="wxacg_btn_gen_token" class="button" style="margin-left:6px;">🎲 產生新 Token</button>
                                    <div id="wxacg_gen_token_result" style="display:none; margin-top:8px; padding:10px; background:#fff8e5; border:1px solid #f0c36d; border-radius:4px;">
                                        <strong style="color:#8a6d3b;">⚠️ 請立刻複製這串，並到 Cloud Run 把環境變數 <code>CLOUD_SECRET_TOKEN</code> 改成同一組，兩邊一致才能運作。</strong>
                                        <div style="margin-top:6px;">
                                            <input type="text" id="wxacg_gen_token_text" class="large-text code" readonly onclick="this.select();" style="font-family:monospace; background:#fff;">
                                        </div>
                                        <div style="margin-top:4px; color:#8a6d3b; font-size:12px;">按下最底部的「儲存所有設定」後才會正式生效。此處關閉後就不會再顯示。</div>
                                    </div>
                                    <p class="description" style="margin-top:2px; margin-bottom:15px;">
                                        為保障安全，此處<strong>不會顯示</strong>已儲存的 Token。要與您放到 Cloud Run 的
                                        <code>CLOUD_SECRET_TOKEN</code> 兩處一致相通！
                                    </p>

                                    <div style="border-top:1px dashed #40c057; padding-top:10px; margin-top:10px;">
                                        <label style="color:#186229;"><strong>🔑 <?php echo $cloud_password_set ? '修改' : '設定'; ?>解鎖密碼（即日後解此防衛門用的口令）：</strong></label><br>
                                        <?php if ($cloud_password_set) : ?>
                                            <input type="password" name="wxacg_ai_news_cloud_old_password" class="regular-text" autocomplete="new-password" placeholder="目前的舊密碼" style="margin-bottom:6px;"><br>
                                        <?php endif; ?>
                                        <input type="password" name="wxacg_ai_news_cloud_new_password" class="regular-text" autocomplete="new-password" placeholder="新密碼（至少 6 個字元）" style="margin-bottom:6px;"><br>
                                        <input type="password" name="wxacg_ai_news_cloud_new_password2" class="regular-text" autocomplete="new-password" placeholder="再次輸入新密碼"><br>
                                        <p class="description" style="margin-top:2px;">全部留空即代表不變更密碼。密碼以雜湊保存，不會顯示也無法還原。</p>
                                    </div>
                                </div>

                                <?php endif; // $can_manage_keys ?>
                            </td>
                        </tr>

                        <!--
                            金鑰池與其管理密碼合併為同一列，版面比照上方「雲端 AI 伺服端點」：
                            綠色面板內依序編號列出各欄位，最後以虛線分隔出密碼設定區。

                            差異說明：雲端端點那組是把正確密碼直接輸出到 DOM 再由 JS 比對，
                            本區的密碼以雜湊存於資料庫、送出後才在伺服器端驗證，
                            無法（也不應）在前端先行比對，故不做解鎖按鈕，改為直接顯示欄位。
                        -->
                        <tr class="wxacg-lock-section">
                            <th scope="row">
                                <label for="wxacg_ai_news_api_key_pool" style="color:#c92a2a; font-weight:bold;">AI 授權金鑰 (API Key)</label>
                                <br><small style="color:#0073aa;">[全站共用金鑰池]</small>
                            </th>
                            <td>
                                <p style="margin:0 0 10px 0; font-size:14px;">
                                    🔑 目前共用池已存放
                                    <strong style="color:<?php echo $pool_count > 0 ? '#186229' : '#d63638'; ?>;"><?php echo (int) $pool_count; ?></strong> 把 Key
                                    <?php if ($pool_count === 0) : ?>
                                        <span style="color:#d63638; font-weight:600;">— 尚未設定，無法生成報導！</span>
                                    <?php endif; ?>
                                </p>

                                <?php if ($can_manage_keys) : ?>

                                    <?php if ($key_password_set) : ?>
                                    <div id="wxacg_key_lock_guard" class="lock-panel">
                                        <p style="margin-top:0;"><strong>已啟用資安鎖，禁止隨意竄改全站共用金鑰池</strong></p>
                                        <div>輸入管理密碼才能檢視或修改：</div>
                                        <div style="margin-top:6px;">
                                            <input type="password" id="wxacg_key_unlock_input" class="regular-text" autocomplete="new-password" placeholder="Key 池管理密碼">
                                            <button type="button" id="wxacg_btn_key_unlock" class="button button-secondary">🔓 解除金鑰鎖</button>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div id="wxacg_key_pool_fields" class="unlock-panel" style="<?php echo $key_password_set ? 'display:none;' : ''; ?>">
                                        <?php if ($key_password_set) : ?>
                                            <div class="unlock-title">✅ 金鑰鎖已解除！以下欄位現在可以修改：</div>
                                            <?php
                                            # 解鎖時由 JS 把剛才輸入的密碼填入此隱藏欄位，隨表單一併送出，
                                            # 讓使用者不必為了同一組密碼輸入兩次。實際仍由伺服器端再驗一次。
                                            ?>
                                            <input type="hidden" id="wxacg_ai_news_key_password" name="wxacg_ai_news_key_password" value="">
                                        <?php else : ?>
                                            <div class="unlock-title">🔐 全站共用金鑰池管理（僅網站管理員可見）</div>
                                            <p style="margin:0 0 15px 0; color:#b32d2e; font-weight:600;">
                                                ⚠️ 尚未設定管理密碼（首次設定模式：目前任何管理員都能直接修改 Key 池）<br>
                                                請於下方設定密碼，設定後本區塊就會像上方雲端端點一樣自動上鎖。
                                            </p>
                                        <?php endif; ?>

                                        <label><strong>1. Gemini API Key 清單（一行一把）：</strong></label><br>
                                        <textarea id="wxacg_ai_news_api_key_pool" name="wxacg_ai_news_api_key_pool" class="large-text code" rows="5"
                                                  placeholder="一行一把 Key，貼上後將【整批覆蓋】原有內容；留空則維持原池不變更..."
                                                  style="font-family:monospace;"></textarea>
                                        <p class="description" style="margin-top:2px; margin-bottom:15px;">
                                            為保障安全，此處<strong>不會顯示</strong>已儲存的金鑰明文。留空儲存＝維持原池不動；<br>
                                            有填內容＝整批覆蓋整個 Key 池。額度不足時雲端會自動輪替到下一把。
                                        </p>

                                        <div style="border-top:1px dashed #40c057; padding-top:10px; margin-top:10px;">
                                            <label style="color:#186229;"><strong>2. <?php echo $key_password_set ? '修改' : '設定'; ?>管理密碼（保護上方金鑰池用）：</strong></label><br>
                                            <?php if ($key_password_set) : ?>
                                                <input type="password" name="wxacg_ai_news_key_old_password" class="regular-text" autocomplete="new-password" placeholder="目前的舊密碼" style="margin-bottom:6px;"><br>
                                            <?php endif; ?>
                                            <input type="password" id="wxacg_ai_news_key_new_password" name="wxacg_ai_news_key_new_password" class="regular-text" autocomplete="new-password" placeholder="新密碼（至少 6 個字元）" style="margin-bottom:6px;"><br>
                                            <input type="password" name="wxacg_ai_news_key_new_password2" class="regular-text" autocomplete="new-password" placeholder="再次輸入新密碼"><br>
                                            <p class="description" style="margin-top:2px;">全部留空即代表不變更密碼。設定後，日後修改上方 Key 池都必須先解鎖。</p>
                                        </div>
                                    </div>

                                <?php else : ?>
                                    <p class="description">
                                        共用金鑰由網站管理員統一維護，您無需（也無法）自行填寫。<br>
                                        若上方顯示 0 把或生成持續失敗，請聯繫管理員補充金鑰。
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <?php submit_button('儲存所有設定', 'primary', 'submit', false, ['style' => 'font-size:14px; padding:6px 24px; border-radius:5px; height:auto; font-weight:600;']); ?>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * 自訂表單送出接應：同時進行 User Meta 私有保全處理與 Options 總體存查
     */
    public function handle_save_settings() {
        if (!current_user_can('use_wxacg_ai_news') || !isset($_POST['wxacg_settings_nonce_field']) || !wp_verify_nonce($_POST['wxacg_settings_nonce_field'], 'wxacg_save_settings_nonce')) {
            wp_die('資安防護檢查失敗：您未持有儲存或更動本頁數值配置的工作權益。');
        }

        $user_id = get_current_user_id();

        # 1. 寫入私人使用者級別之隔離授權屬性與編輯自訂習性 (User Meta)
        if (isset($_POST['wxacg_ai_news_app_password'])) {
            update_user_meta($user_id, 'wxacg_ai_news_app_password', sanitize_text_field(trim($_POST['wxacg_ai_news_app_password'])));
        }
        # 註：AI 授權金鑰已改為全站共用 Key 池（見下方第 3 段），不再逐人保存。
        #     舊的 wxacg_ai_news_api_key 保留在資料庫中不刪除，只是不再讀取或寫入。
        if (isset($_POST['wxacg_ai_news_model'])) {
            update_user_meta($user_id, 'wxacg_ai_news_model', sanitize_text_field(trim($_POST['wxacg_ai_news_model'])));
        }
        if (isset($_POST['wxacg_ai_news_post_status'])) {
            update_user_meta($user_id, 'wxacg_ai_news_post_status', sanitize_text_field($_POST['wxacg_ai_news_post_status']));
        }

        # 2. 雲端伺服端點與其解鎖密碼（僅限管理員，且需通過解鎖密碼驗證）
        $cloud_msg = '';
        if (current_user_can('manage_options')) {
            $cloud_msg = $this->handle_cloud_endpoint_save();
        }

        # 作品名稱自動內部連結開關（未勾選時瀏覽器不會送出該欄位，故以 isset 判斷存 1 或 0）
        update_option('wxacg_ai_news_enable_autolink', isset($_POST['wxacg_ai_news_enable_autolink']) ? '1' : '0');
        # 開關切換後清一次對照表快取，確保狀態立即反映
        $this->flush_anime_link_map();

        # 3. 全站共用 Gemini Key 池與其管理密碼（僅限管理員）
        #    刻意獨立回報處理結果：密碼驗證失敗時只擋下 Key 池的變更，
        #    上面那些設定仍照常儲存，避免使用者辛苦改的其他欄位一起白做。
        $key_msg = '';
        if (current_user_can('manage_options')) {
            $key_msg = $this->handle_key_pool_save();
        }

        # 保存完畢，攜帶反饋狀態順暢轉送返回原操作面壁
        $redirect_args = ['page' => 'wxacg-ai-news-engine', 'updated' => 'true'];
        if ($key_msg !== '') {
            $redirect_args['key_msg'] = $key_msg;
        }
        if ($cloud_msg !== '') {
            $redirect_args['cloud_msg'] = $cloud_msg;
        }
        wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    /**
     * 處理雲端伺服端點與其解鎖密碼的儲存。
     *
     * 設計與金鑰池一致：
     * - Token 採盲寫：欄位留空代表沒有要變更，有內容才覆寫，
     *   如此才能在「不回填明文」的前提下允許只改其他欄位。
     * - 密碼一律在伺服器端以 wp_check_password() 比對雜湊，前端不參與判斷。
     * - 尚未設定密碼時為「首次設定模式」，管理員可免密碼直接寫入。
     *
     * @return string 回報給頁面的訊息代碼，空字串代表本次沒有涉及相關操作。
     */
    private function handle_cloud_endpoint_save() {
        $msg = '';

        # --- 2a. 先處理解鎖密碼的設定／變更 ---
        $new_pass  = isset($_POST['wxacg_ai_news_cloud_new_password']) ? (string) $_POST['wxacg_ai_news_cloud_new_password'] : '';
        $new_pass2 = isset($_POST['wxacg_ai_news_cloud_new_password2']) ? (string) $_POST['wxacg_ai_news_cloud_new_password2'] : '';
        $old_pass  = isset($_POST['wxacg_ai_news_cloud_old_password']) ? (string) $_POST['wxacg_ai_news_cloud_old_password'] : '';

        if ('' !== $new_pass || '' !== $new_pass2) {
            if (!$this->verify_cloud_password($old_pass)) {
                $msg = 'pwbad';
            } elseif (strlen($new_pass) < 6) {
                $msg = 'pwshort';
            } elseif ($new_pass !== $new_pass2) {
                $msg = 'pwmismatch';
            } else {
                update_option(self::CLOUD_PASSWORD_OPTION, wp_hash_password($new_pass), false);
                $msg = 'pwsaved';
            }
        }

        # --- 2b. 判斷本次是否真的要變更端點設定 ---
        $url_input   = isset($_POST['wxacg_ai_news_cloud_url']) ? trim((string) wp_unslash($_POST['wxacg_ai_news_cloud_url'])) : null;
        $token_input = isset($_POST['wxacg_ai_news_cloud_token']) ? trim((string) wp_unslash($_POST['wxacg_ai_news_cloud_token'])) : '';

        $current_url = (string) get_option('wxacg_ai_news_cloud_url', '');
        # 網址未送出（未解鎖時整個面板不在表單內）或與現值相同，就不算變更
        $url_changed   = (null !== $url_input && $url_input !== $current_url);
        $token_changed = ('' !== $token_input);

        if (!$url_changed && !$token_changed) {
            return $msg;
        }

        # --- 2c. 變更前先驗證解鎖密碼 ---
        if ('pwsaved' === $msg) {
            # 本次剛改過密碼，代表已通過舊密碼驗證，不再重複要求
            $authorized = true;
        } else {
            $pass       = isset($_POST['wxacg_ai_news_cloud_password']) ? (string) $_POST['wxacg_ai_news_cloud_password'] : '';
            $authorized = $this->verify_cloud_password($pass);
            if (!$authorized && $this->is_cloud_password_set() && '' === $pass) {
                return 'nopass';
            }
        }

        if (!$authorized) {
            return 'badpass';
        }

        if ($token_changed) {
            /*
             * Token 刻意不經過 sanitize_text_field()。
             *
             * 該函式會把 < 轉成 HTML 實體、移除多餘空白與百分號編碼，
             * 若使用者貼上含特殊符號的 Token 就會被靜默改動，
             * 與 Cloud Run 端逐字比對時對不上，且畫面不顯示明文而極難追查。
             * Token 只是要原樣比對的字串，故保留原值，僅擋掉控制字元與過長輸入。
             */
            if (preg_match('/[\x00-\x1F\x7F]/', $token_input)) {
                return 'tokenbad';
            }
            if (strlen($token_input) > 255) {
                return 'tokenlong';
            }
            update_option('wxacg_ai_news_cloud_token', $token_input);
        }

        if ($url_changed) {
            update_option('wxacg_ai_news_cloud_url', esc_url_raw($url_input));
        }

        return 'saved';
    }

    /**
     * 處理全站共用 Key 池與管理密碼的儲存。
     *
     * 設計重點：
     * - Key 池採「盲寫」：欄位留空代表沒有要變更，有內容才整批覆蓋。
     *   若不這樣設計，使用者只是想改個模型名稱按下儲存，就會被空欄位把整池洗掉。
     * - 密碼驗證一律在伺服器端以 wp_check_password() 比對雜湊，前端不參與判斷。
     * - 尚未設定密碼時為「首次設定模式」，管理員可免密碼直接寫入，讓第一次能順利完成。
     *
     * @return string 回報給頁面的訊息代碼，空字串代表本次沒有涉及 Key 池／密碼操作。
     */
    private function handle_key_pool_save() {
        $msg = '';

        # --- 3a. 先處理密碼的設定／變更 ---
        $new_pass  = isset($_POST['wxacg_ai_news_key_new_password']) ? (string) $_POST['wxacg_ai_news_key_new_password'] : '';
        $new_pass2 = isset($_POST['wxacg_ai_news_key_new_password2']) ? (string) $_POST['wxacg_ai_news_key_new_password2'] : '';
        $old_pass  = isset($_POST['wxacg_ai_news_key_old_password']) ? (string) $_POST['wxacg_ai_news_key_old_password'] : '';

        if ('' !== $new_pass || '' !== $new_pass2) {
            if (!$this->verify_key_password($old_pass)) {
                $msg = 'pwbad';
            } elseif (strlen($new_pass) < 6) {
                $msg = 'pwshort';
            } elseif ($new_pass !== $new_pass2) {
                $msg = 'pwmismatch';
            } else {
                update_option(self::KEY_PASSWORD_OPTION, wp_hash_password($new_pass), false);
                $msg = 'pwsaved';
            }
        }

        # --- 3b. 再處理 Key 池本身 ---
        /*
         * 欄位「完全沒送達」與「送達但留空」必須分開處理。
         *
         * 金鑰欄位即使在上鎖狀態也只是 display:none，仍會隨表單送出空字串，
         * 因此正常情況下 isset() 一定為 true。若連 isset() 都不成立，
         * 代表這個欄位在送達 PHP 之前就被攔掉了（常見於安全性外掛或主機層 WAF
         * 偵測到內容像金鑰而過濾整個欄位），這種情況必須明確告知，
         * 不能跟「使用者沒打算改」一起靜默略過，否則會像金鑰存了卻消失一樣難以追查。
         */
        if (!isset($_POST['wxacg_ai_news_api_key_pool'])) {
            # 若本次同時有密碼操作，優先回報使用者實際動作的結果，
            # 不讓這則環境警告蓋掉「密碼已更新／舊密碼錯誤」等更需要知道的訊息。
            return ('' !== $msg) ? $msg : 'nofield';
        }

        $pool_input = (string) wp_unslash($_POST['wxacg_ai_news_api_key_pool']);

        if ('' === trim($pool_input)) {
            # 留空＝沒有要變更 Key 池，維持原池不動
            return $msg;
        }

        /*
         * 驗證用的密碼來源：
         * 若本次同時設定了新密碼（3a 已成功寫入），沿用新密碼驗證會造成循環，
         * 故此時直接視為已授權——因為要能改密碼，本來就已經通過舊密碼驗證了。
         */
        if ('pwsaved' === $msg) {
            $authorized = true;
        } else {
            $key_pass   = isset($_POST['wxacg_ai_news_key_password']) ? (string) $_POST['wxacg_ai_news_key_password'] : '';
            $authorized = $this->verify_key_password($key_pass);
            if (!$authorized && $this->is_key_password_set() && '' === $key_pass) {
                return 'nopass';
            }
        }

        if (!$authorized) {
            return 'badpass';
        }

        # 換行以外也接受 \r\n（各家瀏覽器 textarea 送出的換行字元不一致）
        $lines    = array_values(array_filter(array_map('trim', preg_split('/\R/', $pool_input))));
        $expected = count($lines);
        $to_write = implode("\n", $lines);

        update_option(self::KEY_POOL_OPTION, $to_write, false);
        # 整池換掉後游標歸零，避免沿用舊游標指到不存在或非預期的位置
        update_option(self::KEY_CURSOR_OPTION, 0, false);

        /*
         * 寫入後立刻讀回驗證。
         *
         * update_option() 回傳 false 時無法區分「寫入失敗」與「值沒變動」，
         * 不足以判斷成敗；而持久化物件快取或資料庫權限問題會讓寫入看似成功卻讀不到。
         * 少了這道檢查，使用者只會看到「已儲存」卻依然是 0 把，完全無從追查。
         */
        $verified = count($this->get_pool_keys());

        if ($verified === $expected) {
            # 密碼與 Key 同時更新時，以 Key 的結果為主要回報（密碼成功屬於附帶結果）
            return 'saved';
        }

        /*
         * 讀回不符時，先清掉該筆的物件快取再重讀一次。
         *
         * 若資料其實已正確寫進資料庫、只是持久化物件快取（Redis/Memcached）沒同步，
         * 清快取後就會讀到正確值——這種情況資料是好的，不該誤報成儲存失敗。
         * 重讀仍不符才是真的沒寫進去。
         */
        wp_cache_delete(self::KEY_POOL_OPTION, 'options');
        $fresh_keys = $this->get_pool_keys();
        $fresh      = count($fresh_keys);

        $detail = sprintf(
            '預期 %d 把；直接讀回 %d 把；清快取後重讀 %d 把。寫入 %d 字元、讀回 %d 字元。外部物件快取：%s。',
            $expected,
            $verified,
            $fresh,
            strlen($to_write),
            strlen((string) get_option(self::KEY_POOL_OPTION, '')),
            (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) ? '啟用中' : '未使用'
        );
        error_log('wxacg-ai-news: Key 池寫入後驗證失敗。' . $detail);

        # 診斷細節暫存 5 分鐘，讓設定頁能直接顯示給管理員看，不必去翻伺服器日誌
        set_transient('wxacg_ai_news_key_diag_' . get_current_user_id(), $detail, 300);

        if ($fresh === $expected) {
            # 資料庫其實是正確的，純粹是快取沒同步，已於上方清除，視為儲存成功
            return 'saved';
        }

        return 'writefail';
    }

    /**
     * AJAX 接收處理事件並轉呼叫 雲端 Engine
     */
    public function handle_trigger_ai_news() {
        check_ajax_referer('wxacg_ai_news_action_nonce', 'nonce');
        if (!current_user_can('use_wxacg_ai_news')) {
            wp_send_json_error(['message' => '毫無足夠管理與主編級操作權益！']);
        }

        # 支援多行 URL 輸入（方案A：每行一個網址），逐行以 esc_url_raw 清理後以換行合併
        $raw_urls_input = isset($_POST['target_url']) ? sanitize_textarea_field(trim($_POST['target_url'])) : '';
        $url_lines = array_filter(array_map(function($line) {
            return esc_url_raw(trim($line));
        }, explode("\n", $raw_urls_input)));
        $target_url = implode("\n", $url_lines);
        if (empty($target_url)) {
            wp_send_json_error(['message' => '不可以留下海外原始 URL 網格為空值。']);
        }

        $custom_glossary = isset($_POST['custom_glossary']) ? sanitize_textarea_field($_POST['custom_glossary']) : '';
        $style = isset($_POST['style']) ? sanitize_text_field(trim($_POST['style'])) : 'comprehensive';
        $target_category = isset($_POST['target_category']) ? intval($_POST['target_category']) : 9;
        $target_channel = isset($_POST['target_channel']) ? intval($_POST['target_channel']) : 12;

        # 自發令者帳單下方調取專有之私房金鑰與編輯慣性設定 (User Meta 獨立保存領域)
        $user_id = get_current_user_id();
        $app_pass = get_user_meta($user_id, 'wxacg_ai_news_app_password', true);
        $model_name = $this->get_current_model($user_id);
        $post_status = get_user_meta($user_id, 'wxacg_ai_news_post_status', true);
        if (empty($post_status)) { $post_status = 'draft'; }

        # 自總體選項庫存引出伺服聯網端 (此為不被破壞的全域共向處)
        $cloud_url = get_option('wxacg_ai_news_cloud_url', '');
        # 不再提供硬編碼預設值：該字串已隨原始碼進版控，等同公開，必須自行設定
        $cloud_token = (string) get_option('wxacg_ai_news_cloud_token', '');

        # 先做不具副作用的檢查，全部通過後才去取 Key。
        # get_rotated_keys() 會推進輪替游標，若放在這些檢查之前，
        # 光是設定沒填好的失敗請求也會空推游標，白白跳過一把 Key。
        if (empty($cloud_url) || empty($app_pass)) {
            wp_send_json_error(['message' => '錯誤：您個人的【WordPress 應用程式密碼】或是主機 URL 不可是空白狀態！請至上方列表確認存妥沒？']);
        }
        if (empty($cloud_token)) {
            wp_send_json_error(['message' => '錯誤：尚未設定【伺服中心授權暗號 Token】。請至下方「雲端 AI 伺服端點」設定一組，並確保與 Cloud Run 的 CLOUD_SECRET_TOKEN 環境變數一致。']);
        }

        # 【全站共用 Key 池】整池依輪替游標重排後一次送出，讓雲端在額度不足時就地換下一把，
        # 只需重跑 AI 那一步，不必把爬原文、建詞典整條流水線重來。
        $rotated_keys = $this->get_rotated_keys();
        if (empty($rotated_keys)) {
            wp_send_json_error(['message' => '錯誤：全站共用 Gemini Key 池目前是空的，無法生成報導！請由網站管理員至下方【核心授權與連線設定】填入 API Key。']);
        }
        $api_key = implode("\n", $rotated_keys);

        $current_user = wp_get_current_user();
        $username = $current_user->user_email ? $current_user->user_email : $current_user->user_login;

        $target_api_endpoint = rtrim($cloud_url, '/') . '/api/generate';
        $payload = [
            'wp_url' => site_url(),
            'wp_username' => $username,
            'wp_app_password' => $app_pass,
            'gemini_api_key' => $api_key,
            'gemini_model' => $model_name,
            'post_status' => $post_status,
            'cloud_secret_token' => $cloud_token,
            'target_url' => $target_url,
            'style' => $style,
            'custom_glossary' => $custom_glossary,
            'target_category_id' => $target_category,
            'target_channel_id' => $target_channel,
            'channel_taxonomy_name' => 'channel'
        ];

        $response = wp_remote_post($target_api_endpoint, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload),
            'timeout' => 12
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => '無法相連 雲端機器中心：' . $response->get_error_message()]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error(['message' => '雲端發退居阻卻信號：' . ($data['detail'] ?? '不明認證失敗理由')]);
        }

        wp_send_json_success([
            'task_id' => $data['task_id'],
            'message' => $data['message']
        ]);
    }

    /**
     * AJAX：中止進行中的雲端任務。
     *
     * 金鑰池變大後（例如 50～100 把），遇到模型端持續壅塞時整池輪完可能耗時數十分鐘，
     * 必須讓操作者能隨時喊停，不必空等或關掉分頁放生。
     *
     * 雲端採合作式中止（立旗標、各階段自行檢查），故此處送出後不會瞬間結束，
     * 而是在目前那一小步結束後停下，因此不會留下寫到一半的文章。
     */
    public function handle_cancel_ai_news() {
        check_ajax_referer('wxacg_ai_news_action_nonce', 'nonce');
        if (!current_user_can('use_wxacg_ai_news')) {
            wp_send_json_error(['message' => '毫無足夠管理與主編級操作權益！']);
        }

        $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';
        if (empty($task_id)) {
            wp_send_json_error(['message' => '缺少任務編碼，無法中止。']);
        }

        $cloud_url = get_option('wxacg_ai_news_cloud_url', '');
        if (empty($cloud_url)) {
            wp_send_json_error(['message' => '尚未設定雲端伺服端點，無法送出中止指令。']);
        }
        # 不再提供硬編碼預設值：該字串已隨原始碼進版控，等同公開，必須自行設定
        $cloud_token = (string) get_option('wxacg_ai_news_cloud_token', '');

        $response = wp_remote_post(
            rtrim($cloud_url, '/') . '/api/cancel/' . rawurlencode($task_id),
            [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'body'    => wp_json_encode(['cloud_secret_token' => $cloud_token]),
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => '無法連上雲端中心送出中止指令：' . $response->get_error_message()]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            wp_send_json_error(['message' => '雲端拒絕中止指令：' . ($data['detail'] ?? "HTTP {$code}")]);
        }

        wp_send_json_success([
            'status'  => $data['status'] ?? 'cancelling',
            'message' => $data['message'] ?? '已送出中止指令。',
        ]);
    }

    /**
     * AJAX 日誌 2 秒輪詢
     */
    public function handle_poll_task_status() {
        check_ajax_referer('wxacg_ai_news_action_nonce', 'nonce');
        $task_id = isset($_GET['task_id']) ? sanitize_text_field($_GET['task_id']) : '';
        $cloud_url = get_option('wxacg_ai_news_cloud_url', '');

        if (empty($task_id) || empty($cloud_url)) {
            wp_send_json_error(['message' => '沒有向對齊尋問之任務編碼號。']);
        }

        $status_url = rtrim($cloud_url, '/') . "/api/status/" . urlencode($task_id);
        $response = wp_remote_get($status_url, ['timeout' => 6]);  // 6 秒容許 Cloud Run 冷啟動的回應延遲

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => '本次拿取日誌跳掉一針，順待下一個時間發打。']);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($data && isset($data['status'])) {
            wp_send_json_success($data);
        } else {
            wp_send_json_error(['message' => '回歸日誌表頭並未能符合既定期待結構。']);
        }
    }
}

new WXACG_AI_News_Engine_Plugin();
