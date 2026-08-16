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

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'register_custom_capability']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        # 註冊 AJAX 下單與輪詢處理
        add_action('wp_ajax_wxacg_trigger_ai_news', [$this, 'handle_trigger_ai_news']);
        add_action('wp_ajax_wxacg_poll_task_status', [$this, 'handle_poll_task_status']);

        # 註冊雙軌獨立保存自定處理端點 (區分全域與使用者獨立金鑰)
        add_action('admin_post_wxacg_save_settings', [$this, 'handle_save_settings']);

        # 作品名稱自動內部連結：輸出文章內容時即時加上作品頁連結 (優先權 20，晚於 wpautop 避免干擾段落處理)
        add_filter('the_content', [$this, 'autolink_anime_titles'], 20);
        # 動畫資料異動時清除對照表快取，讓新建立的作品頁能立即被連結
        add_action('save_post_anime', [$this, 'flush_anime_link_map']);
        add_action('deleted_post', [$this, 'flush_anime_link_map']);
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
        register_setting('wxacg_ai_news_settings_group', 'wxacg_ai_news_cloud_token', ['default' => 'wxacg-super-secret-master-key-2026']);
        register_setting('wxacg_ai_news_settings_group', 'wxacg_ai_news_unlock_password', ['default' => '123456789']);
        register_setting('wxacg_ai_news_settings_group', 'wxacg_ai_news_enable_autolink', ['default' => '0']);
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
        return $table;
    }

    /**
     * 比對用正規化：統一全半形、轉小寫，並移除空白與作品名常見的分隔符號。
     *
     * 之所以需要位置對照表，是因為正規化後的字串長度與原文不同，
     * 找到位置後必須換算回原文位移，才能在插入連結時保留文章原本的寫法。
     *
     * @param string     $text        原始文字
     * @param array|null $offset_map  輸出參數：正規化位移 => 原文位移（含結尾哨兵）
     * @return string 正規化後字串
     */
    private static function normalize_for_match($text, &$offset_map = null) {
        $table = self::get_width_normalize_table();
        # 作品名常見但兩邊寫法可能不一致的分隔符號，一律移除後再比對
        $droppable = ['・' => 1, '･' => 1, '·' => 1, '‧' => 1];

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            $offset_map = [0 => 0];
            return '';
        }

        $normalized = '';
        $offset_map = [];
        $original_offset = 0;

        foreach ($chars as $char) {
            $byte_length = strlen($char);
            $converted = isset($table[$char]) ? $table[$char] : $char;

            # 空白與分隔符號不納入比對字串（僅推進原文位移）
            if (isset($droppable[$char]) || trim($converted) === '') {
                $original_offset += $byte_length;
                continue;
            }

            # 位移對照只記錄在字元邊界上，供比對命中後換算回原文使用
            $offset_map[strlen($normalized)] = $original_offset;
            $normalized .= strtolower($converted);
            $original_offset += $byte_length;
        }

        # 結尾哨兵：讓「比對結束位置」也能換算回原文
        $offset_map[strlen($normalized)] = $original_offset;
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
     * - 每個作品名只連結第一次出現處，且整篇有連結總數上限，避免過度優化。
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
        $skip_depth = 0;

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }

            # 標籤本體：只更新排除區塊的巢狀深度，不做任何替換
            if ($part[0] === '<') {
                if (preg_match('#^</\s*([a-zA-Z0-9]+)#', $part, $m)) {
                    if (in_array(strtolower($m[1]), $excluded_tags, true) && $skip_depth > 0) {
                        $skip_depth--;
                    }
                } elseif (preg_match('#^<\s*([a-zA-Z0-9]+)#', $part, $m)) {
                    $is_self_closing = substr(rtrim($part), -2) === '/>';
                    if (in_array(strtolower($m[1]), $excluded_tags, true) && !$is_self_closing) {
                        $skip_depth++;
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
            $normalized_text = self::normalize_for_match($text, $offset_map);

            foreach ($map as $normalized_title => $info) {
                # 文章若正好關聯到自己（例如作品同名文章），略過避免自我連結
                if ($info['id'] === $current_id || empty($info['url'])) {
                    continue;
                }
                # 此作品在本篇已達連結次數上限就跳過
                if (isset($title_link_counts[$normalized_title])
                    && $title_link_counts[$normalized_title] >= self::AUTOLINK_MAX_PER_TITLE) {
                    continue;
                }
                # 【刻意使用位元組版 strpos/substr】WordPress 核心 compat.php 只在缺少 mbstring 時補上
                # mb_substr / mb_strlen，並未提供 mb_strpos；若主機未啟用 mbstring，呼叫 mb_strpos 會直接 Fatal Error。
                # 這裡比對的是正規化字串，位移再透過 $offset_map 換算回原文，故位元組運算安全且無外部相依。
                $pos = strpos($normalized_text, $normalized_title);
                if ($pos === false) {
                    continue;
                }

                $end = $pos + strlen($normalized_title);
                # 位移必落在字元邊界上，理論上必定存在；防禦性檢查避免異常資料造成錯位切割
                if (!isset($offset_map[$pos]) || !isset($offset_map[$end])) {
                    continue;
                }
                $original_start = $offset_map[$pos];
                $original_end = $offset_map[$end];
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
                # 文字已變動，重新計算正規化字串與位移對照表，供後續作品名繼續比對
                $normalized_text = self::normalize_for_match($text, $offset_map);
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
        $app_pass = get_user_meta($user_id, 'wxacg_ai_news_app_password', true);
        $api_key = get_user_meta($user_id, 'wxacg_ai_news_api_key', true);
        $model_name = get_user_meta($user_id, 'wxacg_ai_news_model', true);
        if (empty($model_name)) { $model_name = 'gemini-3.6-flash'; }
        $post_status = get_user_meta($user_id, 'wxacg_ai_news_post_status', true);
        if (empty($post_status)) { $post_status = 'draft'; }

        # 【全網公務區】獲取選項表中所儲藏的常規總局配置 (雲端伺服連接點與鎖)
        $cloud_url = get_option('wxacg_ai_news_cloud_url', '');
        $cloud_token = get_option('wxacg_ai_news_cloud_token', 'wxacg-super-secret-master-key-2026');
        $unlock_pass = get_option('wxacg_ai_news_unlock_password', '123456789');
        $enable_autolink = get_option('wxacg_ai_news_enable_autolink', '0');

        ?>
        <div class="wrap wxacg-ai-container">
            <h1 class="wxacg-title">AI 新聞發佈中心</h1>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true') : ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom:20px; padding: 12px 16px; border-left: 4px solid #46b450; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <p style="margin:0; font-size:14px; font-weight:600; color:#1b4a24;">✔ 儲存順暢：您本帳戶專注綁定的授權金鑰與總體設定皆已安穩記錄成妥！</p>
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
                            <th scope="row"><label for="wxacg_ai_news_api_key">AI 授權金鑰 (API Key)<br><small style="color:#0073aa;">[帳戶個體獨立保存]</small></label></th>
                            <td>
                                <input type="password" id="wxacg_ai_news_api_key" name="wxacg_ai_news_api_key" class="regular-text code" value="<?php echo esc_attr($api_key); ?>" placeholder="在此填寫專屬於您本人動用的 AI API Key...">
                                <p class="description">採個人獨立紀錄【金鑰僅供本人獨立運算調用，保障專屬於您的權限與付費額度】</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wxacg_ai_news_model">使用模型名稱<br><small style="color:#0073aa;">[帳戶個體獨立保存]</small></label></th>
                            <td>
                                <input type="text" id="wxacg_ai_news_model" name="wxacg_ai_news_model" class="regular-text code" value="<?php echo esc_attr($model_name); ?>" placeholder="gemini-3.6-flash">
                                <p class="description">開放填入形式【AI的模型名稱。若為 Google AI，推薦 <code>gemini-3.6-flash</code>】</p>
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
                                <div id="wxacg_lock_guard_area" class="lock-panel">
                                    <p style="margin-top:0;"><strong>已啟用資安鎖，禁止隨意竄改雲端 AI 伺服端點位置</strong></p>
                                    <div>輸入解鎖密碼才能閱覽或修改：</div>
                                    <div style="margin-top:6px;">
                                        <input type="password" id="wxacg_unlock_input" class="regular-text" placeholder="解鎖通行口令">
                                        <button type="button" id="wxacg_btn_unlock" class="button button-secondary">🔓 解除隔離鎖</button>
                                    </div>
                                </div>

                                <div id="wxacg_cloud_secret_fields" class="unlock-panel" style="display:none;">
                                    <div class="unlock-title">✅ 自我把守驗證順利！已展現深藏變數可親修修改：</div>
                                    
                                    <label><strong>1. Cloud Run 主機專用連線網址 URL：</strong></label><br>
                                    <input type="text" id="wxacg_ai_news_cloud_url" name="wxacg_ai_news_cloud_url" class="large-text code" value="<?php echo esc_attr($cloud_url); ?>"><br>
                                    <p class="description" style="margin-top:2px; margin-bottom:12px;">將從 Google 帶歸來的 <code>https://xxxxx.a.run.app</code> 存放於這。</p>

                                    <label><strong>2. 伺服中心授權暗號Token：</strong></label><br>
                                    <input type="text" id="wxacg_ai_news_cloud_token" name="wxacg_ai_news_cloud_token" class="regular-text code" value="<?php echo esc_attr($cloud_token); ?>"><br>
                                    <p class="description" style="margin-top:2px; margin-bottom:15px;">要與您放到 Cloud Run 的 <code>cloud_secret_token</code> 兩處一致相通！</p>

                                    <div style="border-top:1px dashed #40c057; padding-top:10px; margin-top:10px;">
                                        <label style="color:#186229;"><strong>🔑 設定與修改解鎖密碼 (即日後解此防衛門用的口令)：</strong></label><br>
                                        <input type="text" id="wxacg_ai_news_unlock_password" name="wxacg_ai_news_unlock_password" class="regular-text" value="<?php echo esc_attr($unlock_pass); ?>"><br>
                                        <p class="description">直接改寫這裡的新單字並按最底部存檔，未來此鎖就以這組自定義新口令來驗證。</p>
                                    </div>
                                </div>
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
        if (isset($_POST['wxacg_ai_news_api_key'])) {
            update_user_meta($user_id, 'wxacg_ai_news_api_key', sanitize_text_field(trim($_POST['wxacg_ai_news_api_key'])));
        }
        if (isset($_POST['wxacg_ai_news_model'])) {
            update_user_meta($user_id, 'wxacg_ai_news_model', sanitize_text_field(trim($_POST['wxacg_ai_news_model'])));
        }
        if (isset($_POST['wxacg_ai_news_post_status'])) {
            update_user_meta($user_id, 'wxacg_ai_news_post_status', sanitize_text_field($_POST['wxacg_ai_news_post_status']));
        }

        # 2. 寫入總局共用式系統選項 (WordPress Options - 唯伺服端點網址跟主控鎖是全體合一)
        if (isset($_POST['wxacg_ai_news_cloud_url'])) {
            update_option('wxacg_ai_news_cloud_url', esc_url_raw(trim($_POST['wxacg_ai_news_cloud_url'])));
        }
        if (isset($_POST['wxacg_ai_news_cloud_token'])) {
            update_option('wxacg_ai_news_cloud_token', sanitize_text_field(trim($_POST['wxacg_ai_news_cloud_token'])));
        }
        if (isset($_POST['wxacg_ai_news_unlock_password'])) {
            update_option('wxacg_ai_news_unlock_password', sanitize_text_field($_POST['wxacg_ai_news_unlock_password']));
        }

        # 作品名稱自動內部連結開關（未勾選時瀏覽器不會送出該欄位，故以 isset 判斷存 1 或 0）
        update_option('wxacg_ai_news_enable_autolink', isset($_POST['wxacg_ai_news_enable_autolink']) ? '1' : '0');
        # 開關切換後清一次對照表快取，確保狀態立即反映
        $this->flush_anime_link_map();

        # 保存完畢，攜帶反饋狀態順暢轉送返回原操作面壁
        wp_redirect(add_query_arg(['page' => 'wxacg-ai-news-engine', 'updated' => 'true'], admin_url('admin.php')));
        exit;
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
        $api_key = get_user_meta($user_id, 'wxacg_ai_news_api_key', true);
        $model_name = get_user_meta($user_id, 'wxacg_ai_news_model', true);
        if (empty($model_name)) { $model_name = 'gemini-3.6-flash'; }
        $post_status = get_user_meta($user_id, 'wxacg_ai_news_post_status', true);
        if (empty($post_status)) { $post_status = 'draft'; }

        # 自總體選項庫存引出伺服聯網端 (此為不被破壞的全域共向處)
        $cloud_url = get_option('wxacg_ai_news_cloud_url', '');
        $cloud_token = get_option('wxacg_ai_news_cloud_token', 'wxacg-super-secret-master-key-2026');

        if (empty($cloud_url) || empty($api_key) || empty($app_pass)) {
            wp_send_json_error(['message' => '錯誤：您個人的【WordPress 應用程式密碼】與【AI 授權金鑰 (API Key)】，或是主機 URL 不可是空白狀態！請至上方列表確認存妥沒？']);
        }

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
