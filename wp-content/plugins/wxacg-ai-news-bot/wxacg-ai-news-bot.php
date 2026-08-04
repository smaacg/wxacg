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
        
        # 註冊 AJAX 下單與輪詢處理
        add_action('wp_ajax_wxacg_trigger_ai_news', [$this, 'handle_trigger_ai_news']);
        add_action('wp_ajax_wxacg_poll_task_status', [$this, 'handle_poll_task_status']);

        # 註冊雙軌獨立保存自定處理端點 (區分全域與使用者獨立金鑰)
        add_action('admin_post_wxacg_save_settings', [$this, 'handle_save_settings']);
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
                        <textarea id="wxacg_target_url" class="wxacg-textarea" rows="2" style="height: 54px; min-height: 54px; resize: none;" placeholder="請貼上海外報導原文網址..."></textarea>
                        <div class="wxacg-rec-sources" style="margin-top: 10px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #d63638; font-size: 13.5px;">💡 建議新聞來源：</span>
                            <a href="https://www.oricon.co.jp/category/anime/" target="_blank" rel="noopener" class="button" style="text-decoration: none; border-radius: 99px; font-weight: 600; font-size: 13px; padding: 2px 14px;">Oricon</a>
                            <a href="https://www.animatetimes.com/anime/" target="_blank" rel="noopener" class="button" style="text-decoration: none; border-radius: 99px; font-weight: 600; font-size: 13px; padding: 2px 14px;">Animate</a>
                            <a href="https://animeanime.jp/category/report/japan/latest/" target="_blank" rel="noopener" class="button" style="text-decoration: none; border-radius: 99px; font-weight: 600; font-size: 13px; padding: 2px 14px;">Anime!Anime!</a>
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
                        <label for="wxacg_ai_style" style="font-weight: 700; color: #d63638; font-size: 14px; white-space: nowrap; margin: 0 8px 0 0; display: inline-block;">風格：</label>
                        <select id="wxacg_ai_style" class="wxacg-select" style="min-width: 180px; font-weight: 500; margin: 0;">
                            <option value="default">1. AI預設(直譯改寫)</option>
                            <option value="professional">2. 正規專業報導</option>
                            <option value="passionate">3. 熱血網路鄉民</option>
                            <option value="deep">4. 御宅深度賞析</option>
                            <option value="meme">5. 迷因善意吐嘈風</option>
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

        $target_url = isset($_POST['target_url']) ? esc_url_raw($_POST['target_url']) : '';
        if (empty($target_url)) {
            wp_send_json_error(['message' => '不可以留下海外原始 URL 網格為空值。']);
        }

        $custom_glossary = isset($_POST['custom_glossary']) ? sanitize_textarea_field($_POST['custom_glossary']) : '';
        $style = isset($_POST['style']) ? sanitize_text_field(trim($_POST['style'])) : 'default';
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
        $response = wp_remote_get($status_url, ['timeout' => 4]);

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
