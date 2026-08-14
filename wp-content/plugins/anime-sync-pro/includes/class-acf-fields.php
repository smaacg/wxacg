<?php
/**
 * 檔案名稱: includes/class-acf-fields.php
 *
 * 修正紀錄(對應 repo 完整體檢):
 *   - Bug 1: anime_studio → anime_studios(對齊 import-manager / single-anime / CONTEXT.md)
 *   - 修正 2: anime_score_anilist step 0.01 → 1
 *   - 修正 3: anime_score_bangumi instructions 文字
 *   - 修正 4: anime_trailer_url 用 <br> 換行
 *   - 修正 5: 用 acf/prepare_field hook 讓 readonly 真的生效
 *   - 修正 6: wrapper width 第一排重排為 25/25/25/25
 *   - 修正 7: 加上 crunchyroll(對齊 single-anime.php $provider_icon_map)
 *   - 修正 8: anime_anilist_id required 改為 0(避免 wp_insert_post race)
 *   - 修正 9: 移除 anime 古騰堡編輯器 + 隱藏評分資訊群組(改為 cron 自動維護)
 *   - 修正 10: anime_start_date / anime_end_date return_format 由 Y-m-d 改為 Ymd,
 *              對齊全系統純數字 Ymd 儲存格式(cron 與 YourAnimes fetcher 的 NUMERIC 比對)
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_ACF_Fields {

    /**
     * 全站共用 AI API Key 池(依供應商分池)。
     * 結構:[ 'gemini' => "key1\nkey2", 'openai' => '', 'claude' => '' ]
     * OpenAI / Claude 兩池目前保留供日後擴充,預設為空。
     */
    const SHARED_KEYS_OPTION = 'asp_ai_shared_keys';

    /** 各 Key 池獨立的輪替游標。結構:[ 'gemini' => 0, 'openai' => 0, 'claude' => 0 ] */
    const SHARED_CURSOR_OPTION = 'asp_ai_shared_key_cursor';

    /** 共用 Key 的管理密碼雜湊。空值 = 尚未設定密碼的「首次設定模式」,管理員可免密碼直接設定。 */
    const SHARED_PASSWORD_OPTION = 'asp_ai_shared_key_password_hash';

    /** 解鎖 token 的 transient 名稱前綴(解鎖狀態不寫 Cookie,重整頁面即失效) */
    const UNLOCK_TRANSIENT_PREFIX = 'asp_ai_key_unlock_';

    /** 解鎖 token 有效秒數(僅為 transient 上限,實際重整頁面就要重新解鎖) */
    const UNLOCK_TTL = 1800;

    /**
     * 儲存前記下的短評舊值,供 auto_assign_editorial_reviewer() 判斷這次存檔短評是否真的有變動。
     * 結構:[ post_id => 舊短評文字 ]
     */
    private $editor_summary_before_save = [];

    public function __construct() {
        add_action( 'acf/init',         [ $this, 'register_all_field_groups' ] );
        add_action( 'add_meta_boxes',   [ $this, 'register_resync_metabox' ] );

		/*
		 * 讓 asp-readonly 或 readonly 欄位真正帶有 readonly 屬性。
		 * CSS 僅負責視覺效果，不再作為唯一防護。
		 */
		add_filter( 'acf/prepare_field', [ $this, 'prepare_readonly_field' ], 20 );

        // 修正 5:讓 readonly 真的生效(免費版 ACF 透過 wrapper class + CSS)
        add_action( 'acf/input/admin_head', [ $this, 'inject_readonly_css' ] );

        // 修正 9:移除 anime / manga 文章類型的古騰堡編輯器(編輯只用 ACF 欄位)
        add_action( 'init', [ $this, 'remove_anime_editor' ], 20 );

        // 新增:編輯畫面右下角「回到頂部」浮動按鈕
        add_action( 'admin_footer', [ $this, 'inject_back_to_top_button' ] );

        // 捷徑方塊的 JavaScript 與 AJAX
        add_action( 'admin_footer', [ $this, 'inject_shortcut_scripts' ] );
        add_action( 'wp_ajax_asp_shortcut_save_and_sync', [ $this, 'ajax_shortcut_save_and_sync' ] );
        
        // AI 輔助區塊的 JavaScript 與 AJAX
        add_action( 'admin_footer', [ $this, 'inject_ai_shortcut_scripts' ] );
        add_action( 'wp_ajax_asp_shortcut_ai_save_post', [ $this, 'ajax_shortcut_ai_save_post' ] );
        add_action( 'wp_ajax_asp_shortcut_ai_save_user', [ $this, 'ajax_shortcut_ai_save_user' ] );
        add_action( 'wp_ajax_asp_shortcut_ai_generate', [ $this, 'ajax_shortcut_ai_generate' ] );

        // 全站共用 API Key(管理員角色 + 管理密碼雙重鎖)
        add_action( 'wp_ajax_asp_ai_shared_key_unlock', [ $this, 'ajax_ai_shared_key_unlock' ] );
        add_action( 'wp_ajax_asp_ai_shared_key_save', [ $this, 'ajax_ai_shared_key_save' ] );
        add_action( 'wp_ajax_asp_ai_shared_key_set_password', [ $this, 'ajax_ai_shared_key_set_password' ] );

        // CAST 字典管理與翻譯 AJAX
        add_action( 'wp_ajax_asp_shortcut_ai_cast_translate', [ $this, 'ajax_shortcut_ai_cast_translate' ] );
        add_action( 'wp_ajax_asp_cast_dict_load', [ $this, 'ajax_cast_dict_load' ] );
        add_action( 'wp_ajax_asp_cast_dict_save', [ $this, 'ajax_cast_dict_save' ] );

        // 全人工短評：可複製提示詞盒（含繁中標題）＋ 儲存時自動指定審核者。
        add_filter( 'acf/prepare_field/key=field_anime_editorial_prompt_helper', [ $this, 'render_editorial_prompt_helper' ] );
        add_filter( 'acf/prepare_field/key=field_shortcut_anime_prompt_helper', [ $this, 'render_editorial_prompt_helper' ] );
        // 優先權 5:搶在 ACF 核心存欄位(_acf_do_save_post,優先權 10)之前先記下短評舊值。
        add_action( 'acf/save_post', [ $this, 'capture_editor_summary_before_save' ], 5 );
        add_action( 'acf/save_post', [ $this, 'auto_assign_editorial_reviewer' ], 20 );

        $this->register_mirror_hooks();
    }

    /**
     * 全人工模式：從站上既有 ACF 資料自動組出【已查證資料】區塊的各欄位值。
     *
     * 只填站上「結構化欄位已經有」的事實（原作來源、製作公司、STAFF／CAST JSON
     * 解析出的監督與主要聲優、播出狀態）；台灣分級站上完全沒有對應欄位，
     * 一律留空，交由 AI 走途徑 A 搜尋或編輯手動補上——避免把「猜的」當「查證過的」。
     *
     * @param int    $post_id 動畫文章 ID。
     * @param string $title   作品繁中標題。
     * @return array<string,string> 以【已查證資料】欄位名稱為 key。
     */
    private function gather_verified_facts( int $post_id, string $title ): array {
        $facts = [
            '作品名稱' => ( '' !== trim( $title ) )
                ? trim( $title )
                : '（尚未填寫，請先在旁邊「繁中標題」欄位輸入作品名稱）',
            '原作出處' => '',
            '製作公司' => '',
            '監督'     => '',
            '主要聲優' => '',
            '台灣分級' => '', // 站上無此欄位，永遠留空由 AI 查證或人工補。
            '播映狀態' => '',
        ];

        if ( $post_id <= 0 ) {
            return $facts;
        }

        // 原作出處
        $source_labels = [
            'ORIGINAL'           => '原創',
            'MANGA'              => '漫畫',
            'LIGHT_NOVEL'        => '輕小說',
            'VISUAL_NOVEL'       => '視覺小說',
            'VIDEO_GAME'         => '電子遊戲',
            'GAME'               => 'Comic Game（桌遊／卡牌）',
            'NOVEL'              => '小說',
            'WEB_NOVEL'          => '網路小說',
            'WEB_MANGA'          => '網路漫畫',
            'DOUJINSHI'          => '同人誌',
            'ANIME'              => '動畫',
            'COMIC'              => '歐美漫畫',
            'LIVE_ACTION'        => '真人影視',
            'MULTIMEDIA_PROJECT' => '多媒體企劃',
            'PICTURE_BOOK'       => '繪本',
            'OTHER'              => '其他',
        ];
        $source = strtoupper( trim( (string) get_post_meta( $post_id, 'anime_source', true ) ) );
        if ( isset( $source_labels[ $source ] ) ) {
            $facts['原作出處'] = $source_labels[ $source ];
        }

        // 製作公司（純文字，逗號分隔字串，直接沿用）
        $studios = trim( (string) get_post_meta( $post_id, 'anime_studios', true ) );
        if ( '' !== $studios ) {
            $facts['製作公司'] = $studios;
        }

        // 監督：從 STAFF JSON 篩出「監督／導演」角色（排除副導演、作畫監督等子職位）
        $staff_list = json_decode( (string) get_post_meta( $post_id, 'anime_staff_json', true ), true );
        if ( is_array( $staff_list ) ) {
            $directors = [];
            foreach ( $staff_list as $staff_item ) {
                if ( ! is_array( $staff_item ) ) {
                    continue;
                }
                $role = trim( (string) ( $staff_item['role'] ?? '' ) );
                $name = trim( (string) ( $staff_item['name'] ?? '' ) );
                if ( '' === $role || '' === $name ) {
                    continue;
                }
                if ( $this->is_editorial_director_role( $role ) ) {
                    $directors[] = $name;
                }
            }
            $directors = array_values( array_unique( $directors ) );
            if ( ! empty( $directors ) ) {
                $facts['監督'] = implode( '、', $directors );
            }
        }

        // 主要聲優：CAST JSON 中角色欄位為「主角」或 MAIN 的第一位聲優
        $cast_list = json_decode( (string) get_post_meta( $post_id, 'anime_cast_json', true ), true );
        if ( is_array( $cast_list ) ) {
            $actors = [];
            foreach ( $cast_list as $cast_item ) {
                if ( ! is_array( $cast_item ) ) {
                    continue;
                }
                $cast_role = trim( (string) ( $cast_item['role'] ?? '' ) );
                if ( '主角' !== $cast_role && 'MAIN' !== strtoupper( $cast_role ) ) {
                    continue;
                }
                $voice_actors = ( ! empty( $cast_item['voice_actors'] ) && is_array( $cast_item['voice_actors'] ) )
                    ? $cast_item['voice_actors']
                    : [];
                $voice_actor = is_array( $voice_actors[0] ?? null ) ? $voice_actors[0] : [];
                $va_name     = trim( (string) ( $voice_actor['name'] ?? '' ) );
                if ( '' !== $va_name ) {
                    $actors[] = $va_name;
                }
            }
            $actors = array_values( array_unique( $actors ) );
            if ( ! empty( $actors ) ) {
                $facts['主要聲優'] = implode( '、', array_slice( $actors, 0, 6 ) );
            }
        }

        // 播映狀態
        $status_labels = [
            'FINISHED'         => '已完結',
            'RELEASING'        => '連載中',
            'NOT_YET_RELEASED' => '尚未播出',
            'CANCELLED'        => '已取消',
            'HIATUS'           => '休播中',
        ];
        $status = strtoupper( trim( (string) get_post_meta( $post_id, 'anime_status', true ) ) );
        if ( isset( $status_labels[ $status ] ) ) {
            $facts['播映狀態'] = $status_labels[ $status ];
        }

        return $facts;
    }

    /**
     * 判斷 STAFF JSON 的 role 字串是否為「監督／總導演」本人，排除副導演、
     * 作畫監督、音響監督等子職位。邏輯與前台 single-anime.php 的
     * $is_main_director_role 一致，避免把子職位誤認為監督寫進短評提示詞。
     *
     * @param string $role STAFF 項目的職位字串。
     * @return bool
     */
    private function is_editorial_director_role( string $role ): bool {
        $role = trim( wp_strip_all_tags( $role ) );

        if ( '' === $role ) {
            return false;
        }

        $excluded_pattern =
            '/副導演|副导演|助理導演|助理导演|助監督|助监督|'
            . '副監督|副监督|監督補佐|监督补佐|'
            . '作畫監督|作画監督|動畫監督|动画監督|'
            . '美術監督|美术監督|攝影監督|摄影監督|'
            . '音響監督|音响監督|音樂監督|音乐監督|'
            . '3D監督|3D监督|CG監督|CG监督|'
            . 'アニメーション監督|エピソードディレクター|'
            . 'episode\s*director|assistant\s*director|'
            . 'animation\s*director|art\s*director|'
            . 'sound\s*director|music\s*director|'
            . 'director\s+of\s+photography|'
            . 'photography\s*director/iu';

        if ( preg_match( $excluded_pattern, $role ) ) {
            return false;
        }

        $director_pattern =
            '/(?:^|[\s\/／、,，;；・])'
            . '(?:總導演|总导演|導演|导演|'
            . '總監督|总监督|総監督|監督|'
            . 'director|series\s*director|chief\s*director)'
            . '(?=$|[\s\/／、,，;；・])/iu';

        return (bool) preg_match( $director_pattern, $role );
    }

    /**
     * 全人工模式：組出可餵給 AI 對話視窗的短評提示詞。
     *
     * 【已查證資料】區塊的欄位值來自 gather_verified_facts()：站上已有結構化
     * 資料的欄位（作品名稱／原作出處／製作公司／監督／主要聲優／播映狀態）
     * 自動帶入，讓貼到不具搜尋能力的 AI（如未開啟 Grounding 的 Gemini）也能
     * 直接查證撰寫；台灣分級站上無資料，一律留白交由 AI 走途徑 A 搜尋
     * 或編輯走途徑 B 人工填入。
     *
     * @param array<string,string> $facts gather_verified_facts() 回傳的欄位值。
     * @return string
     */
    private function build_editorial_ai_prompt( array $facts ): string {
        $prompt = <<<'EOT'
【已查證資料】
作品名稱:
原作出處:
製作公司:
監督:
主要聲優:
台灣分級:
播映狀態:
其他(角色年齡、集數、年份等具體數字):
(以上為人工查證結果,視同已查證。此處未列出的事實一律不得寫入短評。)

【角色】
你是動漫資料庫網站「微笑動漫」的編輯,負責撰寫作品頁的「編輯短評」
(ACF 欄位:anime_editor_summary)。

【執行順序|必須依序完成,不得跳步】
第一步:確認事實來源。有兩種合法途徑,擇一:
  (A) 使用搜尋工具查證下列項目:原作出處、製作公司、監督、主要聲優、
      台灣分級、播映狀態。
  (B) 若本環境無搜尋工具,改用【已查證資料】區塊提供的資料。該區塊內容
      視同已查證,查證清單的來源欄位填「使用者提供」。區塊未提供的項目
      一律不得寫入短評。
  若兩者皆無,直接輸出「無可用事實來源,任務中止」並停止,不撰寫短評。
第二步:根據第一步結果撰寫短評本文。
第三步:計算字元數。
第四步:整理待人工確認事項。

【頁面脈絡|決定你該寫什麼】
本頁已有下列自動同步區塊,會獨立顯示,短評不得複述:
- 基本資訊:製作公司、原作來源、集數、時長、播出季度、台灣代理、
  播出頻道、配音版本、資料更新日
- STAFF:監督及各職位人員
- CAST:角色與聲優
- 劇情簡介:官方簡介全文
- 合法串流平台:台灣上架平台(隨授權異動更新)
- 側欄標籤:製作公司、季度、類型

短評是本頁唯一的人工原創內容,價值只在結構化資料寫不出來的部分:
觀點、質感判斷、適合與不適合的族群。

【硬性禁止】
1. 嚴禁寫出任何串流平台名稱或上架狀態。平台資訊由串流區塊負責,
   寫進短評會產生無法同步的過期副本,授權異動後兩處互相矛盾。
2. 嚴禁以「改編自◯◯」「由◯◯製作、◯◯執導」這類 credit 列名開場。
   人名與公司名僅在「作為論點依據」時可提及——例如某位聲優的表現如何、
   某段落看得出該工作室的一貫取向;不得只是列名。

【字數|硬性】
- 純文字長度(含標點,等同 mb_strlen(wp_strip_all_tags()))須為 170～220。
- 低於 150 會被系統判定 thin content 並自動 noindex。
- 【字元數】欄位不得留空。若無法計算,須寫出「無法計算」並說明原因。

【查證規則|最高優先】
1. 查不到、或【已查證資料】未提供的項目,一律整項不寫,不得推測、
   不得以同類作品的常見答案填補、不得改用模糊描述帶過。
2. 嚴禁杜撰角色、聲優、製作人員、獎項、集數、播映日期。
3. 短評中出現的任何具體數字(角色年齡、集數、年份、話數)都必須列入
   查證清單;未列入者一律不得寫入短評——包含在對話中出現、但不在
   【已查證資料】區塊內的數字。對話中提過不等於已查證。
4. 走途徑 (A) 時,每筆 URL 必須是該項目內容的實際出處,且為文章完整
   網址,不得只寫網域首頁。若手邊 URL 的內容與項目不符,該項目移入
   「查無資料而省略」,不得填入相近但無關的連結。留白是被允許的,
   填錯不被允許。
5. 走途徑 (A) 時,URL 須為原始文章網址,不得使用搜尋引擎跳轉網址、
   重新導向連結或任何 vertexaisearch / grounding-api 類型的中介網址。
6. 未播出作品(status = NOT_YET_RELEASED)不得寫任何觀後感,改以
   「可期待/需觀望的點」撰寫,並明確標示為播出前預期。

【內容規範|EEAT】
- Experience:至少一項看過才寫得出來的具體觀察(節奏處理、笑點型態、
  聲線表現、分鏡習慣等),不得劇透關鍵轉折或結局。
- Expertise:至少一項類型脈絡判斷(與同類型作品的差異、漫改取捨、
  該製作公司的一貫風格是否延續),不得複述官方簡介或維基句式。
- Trustworthiness:須明確寫出台灣分級,必要時做內容警示(吸菸、飲酒、
  暴力、性暗示)。須包含一句「可能不合胃口的點」或「不適合誰」,
  不得通篇正面。

【SEO】
- 主關鍵詞為作品中文標題,自然出現 1～2 次,首次盡量在前 40 字內。
- 可帶入 1 個長尾變體(如「◯◯◯ 好看嗎」「◯◯◯ 值得追嗎」),
  但不得為塞詞破壞語句通順。

【格式】
- 繁體中文(台灣用語):聲優、追番、新番、劇場版、跟播、監督。
- 全形標點,句號用「。」不用「.」,引號用「「」」。
- 純文字。輸出經 wpautop() 處理,可用空行分段,但不得使用 markdown
  標記(#、*、-、**)或 HTML 標籤,會原樣顯示。
- 自然口語,有觀點但不誇大,不使用行銷式吹捧。
- 題材涉及成人或敏感內容時轉為客觀中性,只做分級提醒、不做推薦。

【禁用套語】
高品質作畫、光影處理、細膩、細緻、值得一看、不自覺期待、必看、神作、
療癒人心、值得細細品味、不容錯過、堪稱一絕、令人動容、
讓人欲罷不能、後勁十足。

【輸出格式|嚴格遵守,不得增加額外段落或說明文字】

【短評本文】
(純文字,直接可貼入 anime_editor_summary。此區塊內只放短評,
不得附加來源標記、註腳或補充說明。)

【字元數】
(數字;不得留空)

【查證清單】
(純文字,禁止使用 markdown 連結語法。每筆獨立一行,格式為
「項目(內容) | 來源」。走途徑 A 時來源填完整 URL,URL 後不得接任何
文字,行末即結束;走途徑 B 時來源填「使用者提供」。)
原作出處(◯◯◯) |
製作公司(◯◯◯) |
監督(◯◯◯) |
主要聲優(◯◯◯) |
台灣分級(◯◯◯) |
播映狀態(◯◯◯) |

查無資料而省略:(逐項列出,無則寫「無」)

【待人工確認】
(列出填寫 anime_editorial_author 前應覆核的具體事項)
EOT;

        foreach ( $facts as $label => $value ) {
            $value = trim( (string) $value );

            if ( '' === $value ) {
                continue;
            }

            $prompt = str_replace( $label . ":\n", $label . ':' . $value . "\n", $prompt );
        }

        return $prompt;
    }

    /**
     * 動態填入提示詞盒的訊息內容（含繁中標題與「📋 複製提示詞」按鈕）。
     *
     * @param array|false $field ACF 欄位。
     * @return array|false
     */
    public function render_editorial_prompt_helper( $field ) {
        if ( ! is_array( $field ) ) {
            return $field;
        }

        $post_id = $this->get_current_admin_post_id();
        $title   = '';

        if ( $post_id > 0 ) {
            $title = trim( (string) get_post_meta( $post_id, 'anime_title_chinese', true ) );

            if ( '' === $title ) {
                $title = trim( (string) get_the_title( $post_id ) );
            }
        }

        $facts       = $this->gather_verified_facts( $post_id, $title );
        $prompt      = $this->build_editorial_ai_prompt( $facts );
        $title_label = ( '' !== $title ) ? esc_html( $title ) : '（尚未填繁中標題）';

        $field['message'] =
            '<div class="asp-ai-prompt-helper" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;">'
            . '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">'
            . '<button type="button" class="button button-primary asp-copy-prompt-btn">'
            . '📋 複製提示詞</button>'
            . '<strong>🤖 丟給 AI 找資料的提示詞</strong>'
            . '<span style="color:#50575e;">作品：<strong>' . $title_label . '</strong></span>'
            . '</div>'
            . '<input type="text" class="asp-prompt-title" readonly value="' . esc_attr( $title ) . '" '
            . 'style="width:100%;margin-bottom:6px;font-family:monospace;" onclick="this.select();">'
            . '<textarea class="asp-prompt-text" readonly rows="8" '
            . 'style="width:100%;font-family:monospace;font-size:12px;line-height:1.6;resize:none;" onclick="this.select();">'
            . esc_textarea( $prompt )
            . '</textarea>'
            . '</div>';

        return $field;
    }

    /**
     * 全人工模式：儲存 anime 時，若已有編輯短評則自動指定審核者、審核日期與已發布狀態。
     *
     * 取代原本的「手動選審核者/狀態/日期」欄位——因批次 AI 產生已停用、短評皆由
     * 管理員人工貼上，貼上即視為完成人工複核。thin 判定所需的 author 因此自動成立。
     *
     * @param mixed $post_id ACF 傳入的 post id（options 頁為字串）。
     * @return void
     */
    public function auto_assign_editorial_reviewer( $post_id ): void {
        if ( ! is_numeric( $post_id ) ) {
            return;
        }

        $post_id = (int) $post_id;

        if ( $post_id <= 0 || 'anime' !== get_post_type( $post_id ) ) {
            return;
        }

        $summary = trim( (string) get_post_meta( $post_id, 'anime_editor_summary', true ) );

        if ( '' === $summary ) {
            return;
        }

        // 短評內容這次存檔沒有真的變動(例如只改了其他欄位),不重新蓋審核者/審核日期/已發布狀態。
        if ( array_key_exists( $post_id, $this->editor_summary_before_save )
            && trim( (string) $this->editor_summary_before_save[ $post_id ] ) === $summary ) {
            return;
        }

        $user_id = get_current_user_id();

        if ( $user_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        update_post_meta( $post_id, 'anime_editorial_author_id', $user_id );
        update_post_meta( $post_id, '_anime_editorial_author_id', 'field_anime_editorial_author_id' );

        // 前端短評署名讀舊字串欄，一併寫入顯示名稱以維持 E-E-A-T 可見作者。
        $user = get_userdata( $user_id );

        if ( $user && '' !== trim( (string) $user->display_name ) ) {
            update_post_meta( $post_id, 'anime_editorial_author', $user->display_name );
        }

        update_post_meta( $post_id, 'anime_editorial_reviewed_at', current_time( 'Ymd' ) );
        update_post_meta( $post_id, '_anime_editorial_reviewed_at', 'field_anime_editorial_reviewed_at' );

        update_post_meta( $post_id, 'anime_editorial_status', 'published' );
        update_post_meta( $post_id, '_anime_editorial_status', 'field_anime_editorial_status' );

        // 這頁品質提升，排一次背景 thin 重算讓它盡快回到索引。
        if ( function_exists( 'wxacg_schedule_thin_rebuild' ) ) {
            wxacg_schedule_thin_rebuild();
        }
    }

    /**
     * 搶在 ACF 核心把欄位寫入資料庫之前,先記下短評舊值。
     *
     * auto_assign_editorial_reviewer() 靠這個舊值判斷「這次存檔短評文字是否真的有變」,
     * 避免只是儲存了其他無關欄位,也把審核者/審核日期蓋成這次操作的人與今天。
     *
     * @param mixed $post_id ACF 傳入的 post id（options 頁為字串）。
     */
    public function capture_editor_summary_before_save( $post_id ): void {
        if ( ! is_numeric( $post_id ) ) {
            return;
        }

        $post_id = (int) $post_id;

        if ( $post_id <= 0 || 'anime' !== get_post_type( $post_id ) ) {
            return;
        }

        $this->editor_summary_before_save[ $post_id ] = get_post_meta( $post_id, 'anime_editor_summary', true );
    }

    private function register_mirror_hooks(): void {
        $mirror_fields = [
            'shortcut_anime_title_chinese'    => 'anime_title_chinese',
            'shortcut_anime_title_simplified' => 'anime_title_simplified',
            'shortcut_anime_title_native'     => 'anime_title_native',
            'shortcut_anime_youranimes_url'   => 'anime_youranimes_url',
            'shortcut_anime_tw_distributor'   => 'anime_tw_distributor',
            'shortcut_anime_tw_distributor_custom' => 'anime_tw_distributor_custom',
            'shortcut_anime_yt_playlist_url'  => 'anime_yt_playlist_url',
            'shortcut_anime_online_watch'     => 'anime_online_watch',
            'shortcut_anime_trailer_url'      => 'anime_trailer_url',
            'shortcut_anime_wikipedia_url'    => 'anime_wikipedia_url',
            'shortcut_anime_editor_summary'   => 'anime_editor_summary',
            // AI 輔助區塊鏡像
            'shortcut_anime_synopsis_chinese' => 'anime_synopsis_chinese',
            'shortcut_anime_faq_json'         => 'anime_faq_json',
            'shortcut_anime_cast_json'        => 'anime_cast_json',
        ];

        // AI 輔助生成開關 User Meta 載入機制 (使用者獨立偏好)
        $ai_toggles = ['shortcut_ai_generate_synopsis', 'shortcut_ai_generate_faq', 'shortcut_ai_generate_cast'];
        foreach ( $ai_toggles as $toggle ) {
            add_filter( "acf/load_value/name={$toggle}", function( $value, $post_id, $field ) use ( $toggle ) {
                $user_val = get_user_meta( get_current_user_id(), 'asp_ai_pref_' . $toggle, true );
                if ( $user_val !== '' ) {
                    return intval( $user_val );
                }
                return 1; // 預設開啟
            }, 10, 3 );
        }

        foreach ( $mirror_fields as $shortcut => $real_key ) {
            add_filter( "acf/load_value/name={$shortcut}", function( $value, $post_id, $field ) use ( $real_key ) {
                return get_post_meta( $post_id, $real_key, true );
            }, 10, 3 );
            
            add_filter( "acf/update_value/name={$shortcut}", function( $value, $post_id, $field ) use ( $real_key ) {
                update_post_meta( $post_id, $real_key, $value );
                return null;
            }, 10, 3 );
        }

        // 處理 Taxonomy 鏡像 (純文字框版)
        add_filter( 'acf/load_value/name=shortcut_anime_series_tax', function( $value, $post_id, $field ) {
            $terms = wp_get_object_terms( $post_id, 'anime_series_tax', [ 'fields' => 'names' ] );
            return is_wp_error( $terms ) || empty( $terms ) ? '' : implode( ',', $terms );
        }, 10, 3 );

        add_filter( 'acf/update_value/name=shortcut_anime_series_tax', function( $value, $post_id, $field ) {
            $term_names = [];
            if ( ! empty( $value ) && is_string( $value ) ) {
                $parts = explode( ',', $value );
                foreach ( $parts as $part ) {
                    $part = trim( $part );
                    if ( ! empty( $part ) ) {
                        $term_names[] = $part;
                    }
                }
            }
            wp_set_object_terms( $post_id, $term_names, 'anime_series_tax', false );
            return null;
        }, 10, 3 );
    }

    /**
     * 修正 9:移除 anime / manga 內文編輯器(古騰堡 / 傳統編輯器)
     */
    public function remove_anime_editor(): void {
        remove_post_type_support( 'anime', 'editor' );
        remove_post_type_support( 'manga', 'editor' );
    }

	/**
	 * 讓 readonly 欄位真正無法在瀏覽器中修改。
	 *
	 * 支援兩種設定：
	 * 1. 欄位本身具有 'readonly' => 1。
	 * 2. wrapper class 包含 asp-readonly。
	 *
	 * @param array|false $field ACF 欄位資料。
	 * @return array|false
	 */
	public function prepare_readonly_field( $field ) {
		if ( ! is_array( $field ) ) {
			return $field;
		}

		$wrapper_class = '';

		if (
			isset( $field['wrapper'] ) &&
			is_array( $field['wrapper'] ) &&
			isset( $field['wrapper']['class'] )
		) {
			$wrapper_class = (string) $field['wrapper']['class'];
		}

		$is_readonly = ! empty( $field['readonly'] );

		if (
			! $is_readonly &&
			'' !== $wrapper_class &&
			false !== strpos( ' ' . $wrapper_class . ' ', ' asp-readonly ' )
		) {
			$is_readonly = true;
		}

		if ( ! $is_readonly ) {
			return $field;
		}

		/*
		 * ACF 的 text、textarea、url、email、number、date/time 等輸入型欄位
		 * 會讀取 readonly 設定並輸出 HTML readonly attribute。
		 *
		 * 不使用 disabled，避免一般文章儲存時欄位值消失。
		 */
		$field['readonly'] = 1;

		if ( ! isset( $field['wrapper'] ) || ! is_array( $field['wrapper'] ) ) {
			$field['wrapper'] = [];
		}

		$current_class = isset( $field['wrapper']['class'] )
			? trim( (string) $field['wrapper']['class'] )
			: '';

		if (
			false === strpos( ' ' . $current_class . ' ', ' asp-readonly ' )
		) {
			$current_class = trim( $current_class . ' asp-readonly' );
		}

		$field['wrapper']['class'] = $current_class;

		return $field;
	}

	/**
	 * 取得目前後台正在編輯的文章 ID。
	 *
	 * @return int
	 */
	private function get_current_admin_post_id(): int {
		if ( ! is_admin() ) {
			return 0;
		}

		if ( isset( $_GET['post'] ) ) {
			return absint( wp_unslash( $_GET['post'] ) );
		}

		if ( isset( $_POST['post_ID'] ) ) {
			return absint( wp_unslash( $_POST['post_ID'] ) );
		}

		return 0;
	}

	/**
	 * 建立 AI 提示詞使用的作品補充辨識內容。
	 *
	 * @param int $post_id 文章 ID。
	 * @return string
	 */
	private function build_prompt_extra_line( int $post_id ): string {
		$extra_parts = [];

		if ( $post_id <= 0 ) {
			return "【補充辨識】（選填，例如：第二季／劇場版／TV 版）\n";
		}

		$extra_map = [
			'日文原名' => 'anime_title_native',
			'原作來源' => 'anime_source',
			'作品類型' => 'anime_format',
			'播出季度' => 'anime_season',
			'播出年份' => 'anime_season_year',
		];

		$season_labels = [
			'WINTER' => '冬季',
			'SPRING' => '春季',
			'SUMMER' => '夏季',
			'FALL'   => '秋季',
			'AUTUMN' => '秋季',
		];

		$source_labels = [
			'ORIGINAL'           => '原創',
			'MANGA'              => '漫畫',
			'LIGHT_NOVEL'        => '輕小說',
			'VISUAL_NOVEL'       => '視覺小說',
			'VIDEO_GAME'         => '電子遊戲',
			'GAME'               => 'Comic Game（桌遊／卡牌）',
			'NOVEL'              => '小說',
			'WEB_NOVEL'          => '網路小說',
			'WEB_MANGA'          => '網路漫畫',
			'DOUJINSHI'          => '同人誌',
			'ANIME'              => '動畫',
			'COMIC'              => '歐美漫畫',
			'LIVE_ACTION'        => '真人影視',
			'MULTIMEDIA_PROJECT' => '多媒體企劃',
			'PICTURE_BOOK'       => '繪本',
			'OTHER'              => '其他',
		];

		$format_labels = [
			'TV'       => '電視動畫',
			'TV_SHORT' => '電視短篇動畫',
			'MOVIE'    => '劇場版',
			'OVA'      => 'OVA',
			'ONA'      => 'ONA（網路動畫）',
			'SPECIAL'  => '特別篇',
			'MUSIC'    => '音樂 MV',
		];

		foreach ( $extra_map as $label => $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );

			if (
				! is_scalar( $value ) ||
				'' === trim( (string) $value )
			) {
				continue;
			}

			$value = trim( (string) $value );
			$upper = strtoupper( $value );

			if (
				'anime_season' === $meta_key &&
				isset( $season_labels[ $upper ] )
			) {
				$value = $season_labels[ $upper ];
			} elseif (
				'anime_source' === $meta_key &&
				isset( $source_labels[ $upper ] )
			) {
				$value = $source_labels[ $upper ];
			} elseif (
				'anime_format' === $meta_key &&
				isset( $format_labels[ $upper ] )
			) {
				$value = $format_labels[ $upper ];
			}

			$extra_parts[] = $label . '：' . $value;
		}

		if ( empty( $extra_parts ) ) {
			return "【補充辨識】（選填，例如：第二季／劇場版／TV 版）\n";
		}

		return '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
	}

	// =========================================================================
	// 群組 3.5：人工編輯與品質審核
	// =========================================================================

	/**
	 * 註冊人工編輯、審核及 AI 草稿追蹤欄位。
	 *
	 * 注意：
	 * - AI 產生內容時只能設為 draft。
	 * - AI 不得自動填入人工審核者與審核日期。
	 * - 只有人工確認後才能將狀態改為 published。
	 * - 實際索引與廣告資格由 functions.php 統一判定。
	 */
	private function register_editorial_quality(): void {
		acf_add_local_field_group(
			[
				'key'    => 'group_anime_editorial_quality',
				'title'  => '✍️ 人工編輯與品質審核',
				'fields' => [
					[
						'key'       => 'field_anime_editorial_prompt_helper',
						'label'     => '',
						'name'      => '',
						'type'      => 'message',
						// 訊息內容由 acf/prepare_field 動態填入（含該作品繁中標題）。
						'message'   => '',
						'new_lines' => '',
						'esc_html'  => 0,
						'wrapper'   => [ 'width' => '100' ],
					],
					[
						'key'          => 'field_anime_editor_summary',
						'label'        => '編輯推薦短評',
						'name'         => 'anime_editor_summary',
						'type'         => 'textarea',
						'instructions' => '貼上你在 AI 對話視窗撰寫並查證過的繁體中文原創短評（建議 120～160 字）。儲存後系統會自動將你設為審核者、帶入今天日期並標記為「已發布」。',
						'required'     => 0,
						'rows'         => 5,
						'new_lines'    => 'br',
						'wrapper'      => [ 'width' => '100' ],
					],
					[
						'key'       => 'field_anime_editorial_quality_notice',
						'label'     => '',
						'name'      => '',
						'type'      => 'message',
						'message'   => '<strong>全人工模式：</strong>'
							. '用上方「📋 複製提示詞」貼到 AI 對話視窗產出短評，'
							. '自己<strong>查證修改</strong>後貼回上面欄位並儲存即可——'
							. '系統會自動指定審核者、審核日期與「已發布」狀態。'
							. '品質分數、搜尋引擎索引與廣告資格一律由網站共用品質函式判定。',
						'new_lines' => '',
						'esc_html'  => 0,
					],
				],
				'location' => [
					[
						[
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'anime',
						],
					],
				],
				'menu_order'            => 35,
				'position'              => 'normal',
				'style'                 => 'default',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'description'           => '人工編輯內容與品質審核狀態。',
			]
		);
	}


    /**
	 * 註冊全部 ACF 欄位群組。
	 */
	public function register_all_field_groups(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        $this->register_shortcuts();
        $this->register_ai_shortcuts(); // 新增區塊 2：AI 輔助內容
        $this->register_basic_info();
        $this->register_ratings();
        $this->register_synopsis();

		/*
		 * 人工編輯與品質審核欄位。
		 * 品質分數、noindex、AdSense 等判斷不放在本檔，
		 * 後續由 child theme functions.php 統一處理。
		 */
		$this->register_editorial_quality();

        $this->register_media();
        $this->register_production();
        $this->register_themes_and_streaming();
        $this->register_external_links();
        $this->register_taiwan_info();
        $this->register_faq();
        $this->register_sync_control();
        $this->register_post_related_anime();   // ← 新增這一行
        $this->register_manga_fields();      // ← 加這行,啟用漫畫欄位
        $this->register_manga_publication(); // ← 加這行,啟用漫畫出版資訊欄位(日出版社/雜誌/每卷 ISBN)
        $this->register_manga_external();    // ← 加這行,啟用漫畫外部資料庫 ID 欄位
        $this->register_manga_preview();     // ← 加這行,啟用漫畫試閱/免費閱讀欄位
    }
    
    // =========================================================================
    // 群組 1:基本資訊
    // =========================================================================
    private function register_basic_info(): void {
        acf_add_local_field_group( [
            'key'                   => 'group_anime_basic_info',
            'title'                 => '📋 基本資訊',
            'fields'                => [
                [
                    'key'           => 'field_anime_anilist_id',
                    'label'         => 'AniList ID',
                    'name'          => 'anime_anilist_id',
                    'type'          => 'number',
                    'instructions'  => '請填入 AniList 作品 ID(數字),例如:21。',
                    'required'      => 0, // 修正 8:不強制 required,避免 wp_insert_post race
                    'min'           => 1,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_mal_id',
                    'label'         => 'MyAnimeList ID',
                    'name'          => 'anime_mal_id',
                    'type'          => 'number',
                    'instructions'  => '由 AniList API 自動填入(idMal 欄位)。若為空表示 MAL 無對應條目。',
                    'required'      => 0,
                    'min'           => 1,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_bangumi_id',
                    'label'         => 'Bangumi ID',
                    'name'          => 'anime_bangumi_id',
                    'type'          => 'number',
                    'instructions'  => '由三層查找自動填入。若自動查找失敗,請手動填入 Bangumi 條目 ID。',
                    'required'      => 0,
                    'min'           => 1,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_animethemes_id',
                    'label'         => 'AnimeThemes Anime ID',
                    'name'          => 'anime_animethemes_id',
                    'type'          => 'text',
                    'instructions'  => '由 AnimeThemes API 自動填入 anime.id。舊資料若把 slug 寫在這,系統會在重新同步時自動搬到下方 Slug 欄位。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '25' ], // 修正 6:第一排統一 25
                ],
                [
                    'key'           => 'field_anime_animethemes_slug',
                    'label'         => 'AnimeThemes Slug',
                    'name'          => 'anime_animethemes_slug',
                    'type'          => 'text',
                    'instructions'  => 'AnimeThemes slug(例如 shingeki-no-kyojin)。找不到 anime.id 時,系統與人工補抓都會以此欄位作為 fallback。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_chinese',
                    'label'         => '中文標題(台灣繁體)',
                    'name'          => 'anime_title_chinese',
                    'type'          => 'text',
                    'instructions'  => '優先使用 Bangumi name_cn,若為空則 fallback 至 AniList english → AniList romaji。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_simplified',
                    'label'         => '简体标题（简体中文）',
                    'name'          => 'anime_title_simplified',
                    'type'          => 'text',
                    'instructions'  => '由 Bangumi name_cn 原样填入（不经 OpenCC 转换），保留简体供大陆用户搜寻。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],

                [
                    'key'           => 'field_anime_title_native',
                    'label'         => '日文原名',
                    'name'          => 'anime_title_native',
                    'type'          => 'text',
                    'instructions'  => '由 AniList title.native 自動填入(日文原始標題)。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_romaji',
                    'label'         => 'Romaji 標題',
                    'name'          => 'anime_title_romaji',
                    'type'          => 'text',
                    'instructions'  => '由 AniList title.romaji 自動填入。同時作為文章 slug 的產生來源。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_title_english',
                    'label'         => '英文標題',
                    'name'          => 'anime_title_english',
                    'type'          => 'text',
                    'instructions'  => '由 AniList title.english 自動填入。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_format',
                    'label'         => '作品類型',
                    'name'          => 'anime_format',
                    'type'          => 'select',
                    'instructions'  => '由 AniList format 欄位自動填入。',
                    'required'      => 0,
                    'choices'       => [
                        'TV'        => '電視動漫 (TV)',
                        'TV_SHORT'  => '短篇電視動漫 (TV_SHORT)',
                        'MOVIE'     => '劇場版 (MOVIE)',
                        'SPECIAL'   => '特別篇 (SPECIAL)',
                        'OVA'       => 'OVA',
                        'ONA'       => '網路動漫 (ONA)',
                        'MUSIC'     => '音樂 (MUSIC)',
                    ],
                    'default_value' => 'TV',
                    'wrapper'       => [ 'width' => '33' ],
                ],
                [
                    'key'           => 'field_anime_status',
                    'label'         => '播出狀態',
                    'name'          => 'anime_status',
                    'type'          => 'select',
                    'instructions'  => '由每日 cron 自動更新。',
                    'required'      => 0,
                    'choices'       => [
                        'FINISHED'          => '已完結',
                        'RELEASING'         => '連載中',
                        'NOT_YET_RELEASED'  => '尚未播出',
                        'CANCELLED'         => '已取消',
                        'HIATUS'            => '休播中',
                    ],
                    'default_value' => 'FINISHED',
                    'wrapper'       => [ 'width' => '33' ],
                ],
             [
    'key'           => 'field_anime_source',
    'label'         => '原作來源',
    'name'          => 'anime_source',
    'type'          => 'select',
    'instructions'  => '由 AniList source 欄位自動填入。',
    'required'      => 0,
    'choices'       => [
        'ORIGINAL'           => '原創',
        'MANGA'              => '漫畫',
        'LIGHT_NOVEL'        => '輕小說',
        'VISUAL_NOVEL'       => '視覺小說',
        'VIDEO_GAME'         => '電子遊戲',
        'GAME'               => 'Comic Game（桌遊/卡牌）',
        'NOVEL'              => '小說',
        'WEB_NOVEL'          => '網路小說',
        'DOUJINSHI'          => '同人誌',
        'ANIME'              => '動畫',
        'COMIC'              => '歐美漫畫',
        'LIVE_ACTION'        => '真人影視',
        'MULTIMEDIA_PROJECT' => '多媒體企劃',
        'PICTURE_BOOK'       => '繪本',
        'OTHER'              => '其他',
    ],
    'default_value' => '',
    'wrapper'       => [ 'width' => '34' ],
],
                [
                    'key'           => 'field_anime_season',
                    'label'         => '播出季度',
                    'name'          => 'anime_season',
                    'type'          => 'select',
                    'instructions'  => '由 AniList season 欄位自動填入。',
                    'required'      => 0,
                    'choices'       => [
                        'WINTER' => '冬季(1月)',
                        'SPRING' => '春季(4月)',
                        'SUMMER' => '夏季(7月)',
                        'FALL'   => '秋季(10月)',
                    ],
                    'wrapper'       => [ 'width' => '50' ],
                ],
[
    'key'           => 'field_anime_season_year',
    'label'         => '播出年份',
    'name'          => 'anime_season_year',
    'type'          => 'number',
    'instructions'  => '由 AniList seasonYear 欄位自動填入。動畫化確定但未定檔期時為 0，前台顯示「尚未公布」。',
    'required'      => 0,
    'min'           => 0,        // ← 由 1900 改為 0，允許未定檔期作品（值為 0）通過驗證存檔
    'max'           => 2100,
    'step'          => 1,
    'wrapper'       => [ 'width' => '50' ],
],
                [
                    'key'           => 'field_anime_episodes',
                    'label'         => '總集數',
                    'name'          => 'anime_episodes',
                    'type'          => 'number',
                    'instructions'  => '由 AniList episodes 欄位自動填入。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_episodes_aired',
                    'label'         => '已播集數',
                    'name'          => 'anime_episodes_aired',
                    'type'          => 'number',
                    'instructions'  => '播出中時由每日 cron 自動更新。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_duration',
                    'label'         => '每集時長(分鐘)',
                    'name'          => 'anime_duration',
                    'type'          => 'number',
                    'instructions'  => '由 AniList duration 欄位自動填入。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'            => 'field_anime_start_date',
                    'label'          => '開播日期',
                    'name'           => 'anime_start_date',
                    'type'           => 'date_picker',
                    'instructions'   => '由 AniList startDate 欄位自動填入。儲存為純數字 Ymd(例:20260701)。', // 修正 10
                    'required'       => 0,
                    'display_format' => 'Y-m-d',
                    'return_format'  => 'Ymd', // 修正 10:對齊全系統純數字 Ymd(cron / YourAnimes NUMERIC 比對)
                    'first_day'      => 1,
                    'wrapper'        => [ 'width' => '33' ],
                ],
                [
                    'key'            => 'field_anime_end_date',
                    'label'          => '完結日期',
                    'name'           => 'anime_end_date',
                    'type'           => 'date_picker',
                    'instructions'   => '完結後由 cron 自動填入,播出中時留空。儲存為純數字 Ymd(例:20260930)。', // 修正 10
                    'required'       => 0,
                    'display_format' => 'Y-m-d',
                    'return_format'  => 'Ymd', // 修正 10:對齊全系統純數字 Ymd(cron / YourAnimes NUMERIC 比對)
                    'first_day'      => 1,
                    'wrapper'        => [ 'width' => '33' ],
                ],
                [
                    'key'           => 'field_anime_next_airing',
                    'label'         => '下一集播出時間',
                    'name'          => 'anime_next_airing',
                    'type'          => 'text',
                    'instructions'  => '格式:YYYY-MM-DD HH:MM(台灣時間)。由每日 cron 自動更新;完結後清空。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '34' ],
                ],
            ],
            'location'              => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'            => 10,
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active'                => true,
        ] );
    }

    // =========================================================================
    // 群組 2:評分資訊(修正 9:隱藏,改為 cron 自動維護,後台不顯示)
    // =========================================================================
    private function register_ratings(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_ratings',
            'title'  => '⭐ 評分資訊',
            'fields' => [
                [
                    'key'           => 'field_anime_score_anilist',
                    'label'         => 'AniList 評分',
                    'name'          => 'anime_score_anilist',
                    'type'          => 'number',
                    'instructions'  => '範圍 0–100(原始分數)。由每週 cron 自動更新,前台顯示時除以 10。',
                    'required'      => 0,
                    'min'           => 0,
                    'max'           => 100,
                    'step'          => 1, // 修正 2:原 0.01 改為 1
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_score_mal',
                    'label'         => 'MyAnimeList 評分',
                    'name'          => 'anime_score_mal',
                    'type'          => 'number',
                    'instructions'  => '範圍 0–100(原始分數 × 10 儲存)。由每週 cron 透過 MyAnimeList 官方 API 自動更新,前台顯示時除以 10。',
                    'required'      => 0,
                    'min'           => 0,
                    'max'           => 100,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_score_bangumi',
                    'label'         => 'Bangumi 評分',
                    'name'          => 'anime_score_bangumi',
                    'type'          => 'number',
                    'instructions'  => '範圍 0–100(原始分數 × 10 儲存)。由每週 cron 自動更新,前台顯示時除以 10。', // 修正 3
                    'required'      => 0,
                    'min'           => 0,
                    'max'           => 100,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_anime_popularity',
                    'label'         => 'AniList 人氣數',
                    'name'          => 'anime_popularity',
                    'type'          => 'number',
                    'instructions'  => '由 AniList popularity 欄位自動填入(收藏人數)。每週更新。',
                    'required'      => 0,
                    'min'           => 0,
                    'step'          => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 20,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => false, // 修正 9:隱藏此群組(cron 自動維護,不需人工編輯)
        ] );
    }

    // =========================================================================
    // 群組 3:簡介
    // =========================================================================
    private function register_synopsis(): void {

        // 取得目前正在編輯的文章 ID
        $pid = 0;
        if ( is_admin() ) {
            if ( isset( $_GET['post'] ) ) {
                $pid = (int) $_GET['post'];
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $pid = (int) $_POST['post_ID'];
            }
        }

        $current_title    = $pid > 0 ? get_the_title( $pid ) : '';
        $title_for_prompt = $current_title !== '' ? $current_title : '（請填入作品名稱）';

        // ---------------------------------------------------------------------
        // 自動抓取「補充辨識」資料（與 FAQ 相同）
        // ---------------------------------------------------------------------
        $extra_parts = [];
        if ( $pid > 0 ) {

            $extra_map = [
                '日文原名' => 'anime_title_native',
                '原作來源' => 'anime_source',
                '作品類型' => 'anime_format',
                '播出季度' => 'anime_season',
                '播出年份' => 'anime_season_year',
            ];

            $season_label = [
                'WINTER' => '冬季', 'SPRING' => '春季',
                'SUMMER' => '夏季', 'FALL' => '秋季', 'AUTUMN' => '秋季',
            ];
            $source_label = [
                'MANGA'        => '漫畫',
                'ORIGINAL'     => '原創',
                'LIGHT_NOVEL'  => '輕小說',
                'NOVEL'        => '小說',
                'VISUAL_NOVEL' => '視覺小說',
                'VIDEO_GAME'   => '電玩遊戲',
                'GAME'         => '遊戲',
                'WEB_NOVEL'    => '網路小說',
                'WEB_MANGA'    => '網路漫畫',
                'DOUJINSHI'    => '同人誌',
                'ANIME'        => '動畫',
                'OTHER'        => '其他',
            ];
            $format_label = [
                'TV'       => '電視動畫',
                'TV_SHORT' => '電視短篇動畫',
                'MOVIE'    => '劇場版',
                'OVA'      => 'OVA',
                'ONA'      => 'ONA（網路動畫）',
                'SPECIAL'  => '特別篇',
                'MUSIC'    => '音樂 MV',
            ];

            foreach ( $extra_map as $label => $meta_key ) {
                $val = get_post_meta( $pid, $meta_key, true );
                if ( ! is_string( $val ) || $val === '' ) {
                    continue;
                }
                $upper = strtoupper( $val );

                if ( $meta_key === 'anime_season' && isset( $season_label[ $upper ] ) ) {
                    $val = $season_label[ $upper ];
                } elseif ( $meta_key === 'anime_source' && isset( $source_label[ $upper ] ) ) {
                    $val = $source_label[ $upper ];
                } elseif ( $meta_key === 'anime_format' && isset( $format_label[ $upper ] ) ) {
                    $val = $format_label[ $upper ];
                }

                $extra_parts[] = "{$label}：{$val}";
            }
        }

        if ( ! empty( $extra_parts ) ) {
            $extra_line = '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
        } else {
            $extra_line = "【補充辨識】（選填,例：第二季 / 劇場版 / TV版）\n";
        }

        // ---------------------------------------------------------------------
        // 組 prompt 文字
        // ---------------------------------------------------------------------
        $prompt  = "請將故事簡介／作品簡介，翻譯成台灣翻譯版本（繁體中文、台灣用語）。\n\n";
        $prompt .= "【作品名稱】{$title_for_prompt}　\n";
        $prompt .= $extra_line;

        // ---------------------------------------------------------------------
        // 說明文字 + 可複製框
        // ---------------------------------------------------------------------
        $ta_id = 'anime_synopsis_prompt_' . ( $pid > 0 ? $pid : 'new' );

        $instructions  = '優先使用 Bangumi summary（自動簡繁轉換）。若無資料，可用下方提示詞產生後貼回。<br>';
        $instructions .= '<strong>📋 作品名稱與補充辨識已自動帶入。點框內後按 Ctrl+A 再 Ctrl+C：</strong>';
        $instructions .= '<span style="display:block; margin:8px 0;">';
        $instructions .= '<textarea id="' . esc_attr( $ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:140px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $prompt ) . '</textarea>';
        $instructions .= '</span>';

        acf_add_local_field_group( [
            'key'    => 'group_anime_synopsis',
            'title'  => '📝 簡介',
            'fields' => [
                [
                    'key'          => 'field_anime_synopsis_chinese',
                    'label'        => '中文簡介（台灣繁體）',
                    'name'         => 'anime_synopsis_chinese',
                    'type'         => 'textarea',
                    'instructions' => $instructions,
                    'required'     => 0,
                    'rows'         => 6,
                    'new_lines'    => 'br',
                    'wrapper'      => [ 'width' => '100' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order' => 30,
            'position'   => 'normal',
            'style'      => 'default',
            'active'     => true,
        ] );
    }


    // =========================================================================
    // 群組 4:媒體素材
    // =========================================================================
    private function register_media(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_media',
            'title'  => '🖼️ 媒體素材',
            'fields' => [
                [
                    'key'           => 'field_anime_cover_image',
                    'label'         => '封面圖片網址',
                    'name'          => 'anime_cover_image',
                    'type'          => 'url',
                    'instructions'  => '由 AniList coverImage.extraLarge 自動填入。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_banner_image',
                    'label'         => '橫幅圖片網址',
                    'name'          => 'anime_banner_image',
                    'type'          => 'url',
                    'instructions'  => '由 AniList bannerImage 自動填入。可留空。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_trailer_url',
                    'label'         => 'YouTube 預告片網址(支援多支 PV)',
                    'name'          => 'anime_trailer_url',
                    'type'          => 'textarea',
                    // 修正 4:用 <br> 換行(ACF instruction 會 wpautop)
                    'instructions'  => '可填一支或多支 YouTube 網址,分隔方式:換行 / 逗號 / 分號 / 空格 皆可。<br>'
                                     . '可選擇加標題(用 | 分隔),未填標題會自動編號 PV 1、PV 2…<br><br>'
                                     . '<strong>範例(單支):</strong><br>'
                                     . '<code>https://www.youtube.com/watch?v=XXXXX</code><br><br>'
                                     . '<strong>範例(多支,每行一筆):</strong><br>'
                                     . '<code>https://youtu.be/abc12345678 | PV</code><br>'
                                     . '<code>https://youtu.be/def09876543 | PV2</code><br>'
                                     . '<code>https://youtu.be/ghi13579246 | PV3</code>',
                    'rows'          => 4,
                    'new_lines'     => '',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 40,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }

  // =========================================================================
    // 群組 5:製作資訊
    // =========================================================================
    private function register_production(): void {

           // 取得目前正在編輯的文章 ID
        $pid = 0;
        if ( is_admin() ) {
            if ( isset( $_GET['post'] ) ) {
                $pid = (int) $_GET['post'];
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $pid = (int) $_POST['post_ID'];
            }
        }

        $current_title    = $pid > 0 ? get_the_title( $pid ) : '';
        $title_for_prompt = $current_title !== '' ? $current_title : '（請填入作品名稱）';

        // ---------------------------------------------------------------------
        // 自動抓取「補充辨識」資料
        // ---------------------------------------------------------------------
        $extra_parts = [];
        if ( $pid > 0 ) {

            $extra_map = [
                '日文原名' => 'anime_title_native',
                '原作來源' => 'anime_source',
                '作品類型' => 'anime_format',
                '播出季度' => 'anime_season',
                '播出年份' => 'anime_season_year',
            ];

            $season_label = [
                'WINTER' => '冬季', 'SPRING' => '春季',
                'SUMMER' => '夏季', 'FALL' => '秋季', 'AUTUMN' => '秋季',
            ];
$source_label = [
    'ORIGINAL'           => '原創',
    'MANGA'              => '漫畫',
    'LIGHT_NOVEL'        => '輕小說',
    'VISUAL_NOVEL'       => '視覺小說',
    'VIDEO_GAME'         => '電子遊戲',
    'OTHER'              => '其他',
    'NOVEL'              => '小說',
    'DOUJINSHI'          => '同人誌',
    'ANIME'              => '動畫',
    'WEB_NOVEL'          => '網路小說',
    'LIVE_ACTION'        => '真人影視',
    'GAME'               => 'Comic Game（桌遊/卡牌）',
    'COMIC'              => '歐美漫畫',
    'MULTIMEDIA_PROJECT' => '多媒體企劃',
    'PICTURE_BOOK'       => '繪本',
];
            $format_label = [
                'TV'       => '電視動畫',
                'TV_SHORT' => '電視短篇動畫',
                'MOVIE'    => '劇場版',
                'OVA'      => 'OVA',
                'ONA'      => 'ONA（網路動畫）',
                'SPECIAL'  => '特別篇',
                'MUSIC'    => '音樂 MV',
            ];

            foreach ( $extra_map as $label => $meta_key ) {
                $val = get_post_meta( $pid, $meta_key, true );
                if ( ! is_string( $val ) || $val === '' ) {
                    continue;
                }
                $upper = strtoupper( $val );

                if ( $meta_key === 'anime_season' && isset( $season_label[ $upper ] ) ) {
                    $val = $season_label[ $upper ];
                } elseif ( $meta_key === 'anime_source' && isset( $source_label[ $upper ] ) ) {
                    $val = $source_label[ $upper ];
                } elseif ( $meta_key === 'anime_format' && isset( $format_label[ $upper ] ) ) {
                    $val = $format_label[ $upper ];
                }

                $extra_parts[] = "{$label}：{$val}";
            }
        }

        if ( ! empty( $extra_parts ) ) {
            $extra_line = '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
        } else {
            $extra_line = "【補充辨識】（選填,例：第二季 / 劇場版 / TV版）\n";
        }

        // ---------------------------------------------------------------------
        // CAST JSON 譯名在地化指令文字
        // ---------------------------------------------------------------------
        
$cast_prompt = "你是熟悉台灣 ACG 圈譯名的翻譯校對員。請把以下 CAST JSON 的「角色名(name)」與「聲優名(voice_actors 的 name)」改成台灣慣用中文譯名。\n";
$cast_prompt .= "\n【作品名稱】{$title_for_prompt}\n";
$cast_prompt .= "{$extra_line}\n";
$cast_prompt .= "最重要的前提:\n";
$cast_prompt .= "你必須「實際上網開啟網頁查證」,不可僅憑記憶或推測。新番你的記憶很可能沒有或過時。\n";
$cast_prompt .= "查證來源優先順序:\n";
$cast_prompt .= "① 台灣代理商/平台官方(木棉花 Muse、曼迪、羚邦、Netflix、巴哈姆特動畫瘋)的官網或官方社群(FB/IG/X)——有台灣官方代理版本時,以其角色譯名為最高依據\n";
$cast_prompt .= "② 中文維基百科台灣版(zh-hant)\n";
$cast_prompt .= "③ 日文官網、日文維基(確認原文對應,避免張冠李戴)\n";
$cast_prompt .= "④ 萌娘百科/百度(僅輔助確認角色存在與原文對應,為大陸譯名,不可直接採用)\n\n";
$cast_prompt .= "若查無台灣代理官方譯名(常見於老作品、冷門番、未代理作品):\n";
$cast_prompt .= "- 依②③來源查證後,採用台灣 ACG 圈普遍使用之慣用譯名(而非直接照搬大陸慣用譯名)\n\n";
$cast_prompt .= "若同一角色/聲優查到多個不同譯名版本(例如代理商譯名與圈內舊譯不同):\n";
$cast_prompt .= "- 以來源優先順序最高者為準,直接採用,不需列出其他版本讓我選擇\n\n";
$cast_prompt .= "修改後請提供一份「核對清單」告訴我:\n";
$cast_prompt .= "- 每個角色/聲優的新譯名、原文、來源(需具體網址,無法附網址代表沒查到,該筆不要採用,原樣保留)\n\n";
$cast_prompt .= "JSON 結構規則(嚴格遵守):\n";
$cast_prompt .= "- 不可增減欄位、不可改 key、不可改順序、不可改 id、image、role、source\n";
$cast_prompt .= "- image 網址一字不可動\n\n";
$cast_prompt .= "輸出前請自我檢查:\n";
$cast_prompt .= "- 每個修改過的角色/聲優是否都有具體來源網址?\n";
$cast_prompt .= "- JSON 是否為合法格式(無多餘逗號、雙引號皆為半形)?\n";
$cast_prompt .= "- 是否誤動了 id / image / role / source?\n\n";
$cast_prompt .= "最後單獨輸出完整 JSON,放程式碼框內供一鍵複製。框內只有 JSON,結構與我給的完全一致,所有 image 網址保持原樣。\n\n";

$cast_prompt .= "以下是 JSON:\n";
        $cast_ta_id = 'anime_cast_prompt_' . ( $pid > 0 ? $pid : 'new' );

        $cast_instructions  = '由 Bangumi CAST API 自動填入(多為日文原名/大陸譯名),匯入後人工整理。整理後請在「同步控制」勾選「鎖定 CAST 角色資料」,避免下次同步被覆蓋。<br><br>';
        $cast_instructions .= '<strong>📋 譯名在地化指令(點框內後按 Ctrl+A 再 Ctrl+C,務必先填【作品名稱】,連同 CAST JSON 一起貼給可上網的 AI):</strong>';
        $cast_instructions .= '<span style="display:block; margin:8px 0;">';
        $cast_instructions .= '<textarea id="' . esc_attr( $cast_ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:260px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $cast_prompt ) . '</textarea>';
        $cast_instructions .= '</span>';
        $cast_instructions .= '<strong>⚠️ 必填【作品名稱】;核對清單若某筆「來源」沒附具體網址,代表 AI 沒真的查到,該筆譯名別採用;貼回前掃一眼程式碼框,確認 image 網址未被更動。</strong>';

        acf_add_local_field_group( [
            'key'    => 'group_anime_production',
            'title'  => '🎬 製作資訊',
            'fields' => [
                [
                    'key'           => 'field_anime_studios', // 修正 1
                    'label'         => '製作公司',
                    'name'          => 'anime_studios',       // 修正 1:單數 → 複數
                    'type'          => 'text',
                    'instructions'  => '由 AniList studios 自動填入(逗號分隔字串)。可手動編輯。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_staff_json',
                    'label'         => 'STAFF 資料(JSON)',
                    'name'          => 'anime_staff_json',
                    'type'          => 'textarea',
                    'instructions'  => '由 Bangumi STAFF API 自動填入。可手動修正繁簡轉換錯誤後儲存。修改後請在「同步控制」勾選「鎖定 STAFF 製作資料」。',
                    'required'      => 0,
                    'rows'          => 6,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_cast_json',
                    'label'         => 'CAST 角色資料(JSON)',
                    'name'          => 'anime_cast_json',
                    'type'          => 'textarea',
                    'instructions'  => $cast_instructions,
                    'required'      => 0,
                    'rows'          => 6,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ],
                ],
                [
                    'key'           => 'field_anime_episodes_json',
                    'label'         => '集數列表(JSON)',
                    'name'          => 'anime_episodes_json',
                    'type'          => 'textarea',
                    'instructions'  => '由 Bangumi Episodes API 自動填入。修改後請在「同步控制」勾選「鎖定集數列表」。格式:[{"ep":1,"name":"...","name_cn":"...","airdate":"YYYY-MM-DD"}]',
                    'required'      => 0,
                    'rows'          => 6,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 50,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }
    
// =========================================================================
// 群組 M1:漫畫資料(AniList + Bangumi + 台灣代理)
// location 綁定 post_type == manga,不影響動畫。
// =========================================================================
private function register_manga_fields(): void {
	acf_add_local_field_group( [
		'key'    => 'group_manga_data',
		'title'  => '📖 漫畫資料',
		'fields' => [

			// ---- 識別 ID(手動填,AniList 必填) ----
			[
				'key'          => 'field_manga_anilist_id',
				'label'        => 'AniList ID(漫畫)',
				'name'         => 'anime_anilist_id', // 沿用共用 key,find_existing 靠它
				'type'         => 'number',
				'instructions' => '必填。AniList 漫畫頁網址中的數字,例如 anilist.co/manga/147149 → 填 147149。',
				'required'     => 0,
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_bangumi_id',
				'label'        => 'Bangumi ID',
				'name'         => 'anime_bangumi_id', // 沿用共用 key
				'type'         => 'number',
				'instructions' => '選填。用於補中文標題/簡介/台灣代理資訊。',
				'required'     => 0,
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_mal_id',
				'label'        => 'MAL ID',
				'name'         => 'anime_mal_id',
				'type'         => 'number',
				'instructions' => '選填。MAL 評分來源。', // ← 改:已用於評分
				'required'     => 0,
				'wrapper'      => [ 'width' => '34' ],
			],

			// ---- 標題(沿用共用 key,可手動改) ----
			[
				'key'     => 'field_manga_title_chinese',
				'label'   => '中文標題',
				'name'    => 'anime_title_chinese',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_simplified',
				'label'   => '简体标题',
				'name'    => 'anime_title_simplified',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_native',
				'label'   => '日文原名',
				'name'    => 'anime_title_native',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_romaji',
				'label'   => '羅馬字標題',
				'name'    => 'anime_title_romaji',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_title_english',
				'label'   => '英文標題',
				'name'    => 'anime_title_english',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],

			// ---- Cron 自動更新的客觀欄位 ----
			[
				'key'          => 'field_manga_status',
				'label'        => '連載狀態',
				'name'         => 'anime_status', // 沿用共用 key,後台徽章能複用
				'type'         => 'select',
				'instructions' => 'Cron 會自動更新。若 AniList 標錯(例:實際休刊卻標連載中),可手動改後在下方勾選鎖定。',
				'choices'      => [
					'RELEASING'        => '連載中',
					'FINISHED'         => '已完結',
					'HIATUS'           => '休刊中',
					'NOT_YET_RELEASED' => '未發售',
					'CANCELLED'        => '已腰斬',
				],
				'allow_null'   => 1,
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_status_locked',
				'label'        => '鎖定連載狀態',
				'name'         => 'manga_status_locked',
				'type'         => 'true_false',
				'instructions' => '勾選後,Cron 不再自動覆蓋上面的連載狀態(用於 AniList 標錯的個案)。',
				'ui'           => 1,
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_chapters',
				'label'        => '話數',
				'name'         => 'manga_chapters',
				'type'         => 'number',
				'instructions' => 'Cron 自動更新。連載中常為空,前台顯示「連載中・未定」。',
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_volumes',
				'label'        => '卷數(日版)',
				'name'         => 'manga_volumes',
				'type'         => 'number',
				'instructions' => 'Cron 自動更新。連載中常為空。',
				'wrapper'      => [ 'width' => '33' ],
			],
			[
				'key'          => 'field_manga_format',
				'label'        => '類型',
				'name'         => 'anime_format',
				'type'         => 'text',
				'instructions' => 'MANGA / ONE_SHOT / NOVEL 等,由 AniList 帶入。',
				'wrapper'      => [ 'width' => '34' ],
			],
			[
				'key'     => 'field_manga_start_date',
				'label'   => '開始連載日',
				'name'    => 'anime_start_date',
				'type'    => 'text',
				'instructions' => '格式 Ymd(如 20210715)。',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_end_date',
				'label'   => '完結日期',
				'name'    => 'anime_end_date',
				'type'    => 'text',
				'instructions' => '格式 Ymd。未完結留空。',
				'wrapper' => [ 'width' => '50' ],
			],

			// ---- 評分(四欄統一 25 寬,加入 MAL) ----
			[
				'key'     => 'field_manga_score_anilist',
				'label'   => 'AniList 評分',
				'name'    => 'anime_score_anilist',
				'type'    => 'number',
				'instructions' => '0–100。前台可 ÷10 顯示。Cron 自動更新。',
				'wrapper' => [ 'width' => '25' ], // ← 33 改 25
			],
			[
				'key'     => 'field_manga_score_bangumi',
				'label'   => 'Bangumi 評分',
				'name'    => 'anime_score_bangumi',
				'type'    => 'number',
				'instructions' => '0–100(原始 ×10 儲存)。前台 ÷10 顯示。Cron 自動更新。',
				'wrapper' => [ 'width' => '25' ], // ← 33 改 25
			],
			[
				'key'     => 'field_manga_score_mal', // ← 新增
				'label'   => 'MAL 評分',
				'name'    => 'anime_score_mal',
				'type'    => 'number',
				'instructions' => '0–100(原始 ×10 儲存)。前台 ÷10 顯示。Cron 透過 Jikan/MAL 自動更新。',
				'wrapper' => [ 'width' => '25' ],
			],
			[
				'key'     => 'field_manga_popularity',
				'label'   => '人氣值',
				'name'    => 'anime_popularity',
				'type'    => 'number',
				'wrapper' => [ 'width' => '25' ], // ← 34 改 25
			],

			// ---- 內容(匯入後可手動改,靠 anime_locked_fields 保護) ----
			[
				'key'     => 'field_manga_synopsis',
				'label'   => '簡介',
				'name'    => 'anime_synopsis_chinese',
				'type'    => 'textarea',
				'instructions' => '優先 Bangumi 中文,無則 fallback AniList。',
				'rows'    => 5,
			],
			[
				'key'     => 'field_manga_author',
				'label'   => '作者(原作/Story)',
				'name'    => 'manga_author',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_artist',
				'label'   => '作畫(Art)',
				'name'    => 'manga_artist',
				'type'    => 'text',
				'instructions' => '作者與作畫常為同一人。',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'   => 'field_manga_cover',
				'label' => '封面圖 URL',
				'name'  => 'anime_cover_image', // 沿用共用 key,封面下載邏輯能複用
				'type'  => 'text',
			],
			[
				'key'          => 'field_manga_staff_json',
				'label'        => 'STAFF 資料(JSON)',
				'name'         => 'anime_staff_json', // 沿用動畫共用 key，前台/人物頁交叉連結邏輯能複用
				'type'         => 'textarea',
				'instructions' => 'Bangumi 優先、AniList 備援自動填入。可手動修正後儲存，記得在下方「鎖定欄位」勾選避免被同步覆蓋。',
				'required'     => 0,
				'rows'         => 6,
				'new_lines'    => '',
				'wrapper'      => [ 'width' => '100' ],
			],

			// ---- 台灣代理資訊(純手動,SEO 差異化重點) ----
			[
				'key'          => 'field_manga_tw_publisher',
				'label'        => '台灣出版社',
				'name'         => 'manga_tw_publisher',
				'type'         => 'text',
				'instructions' => '如:東立、尖端、青文、長鴻。',
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_tw_translator',
				'label'   => '譯者',
				'name'    => 'manga_tw_translator',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_tw_volumes',
				'label'        => '台版集數',
				'name'         => 'manga_tw_volumes',
				'type'         => 'number',
				'instructions' => '常與日版不同。',
				'wrapper'      => [ 'width' => '50' ],
			],
			[
				'key'     => 'field_manga_tw_release_date',
				'label'   => '台版發售日',
				'name'    => 'manga_tw_release_date',
				'type'    => 'text',
				'wrapper' => [ 'width' => '50' ],
			],
			[
				'key'          => 'field_manga_purchase_url',
				'label'        => '購買連結',
				'name'         => 'manga_purchase_url',
				'type'         => 'url',
				'instructions' => '博客來 / 台版購買頁。',
			],

			// ---- 關聯動畫(雙向連結用) ----
			[
				'key'          => 'field_manga_related_anime',
				'label'        => '關聯動畫',
				'name'         => 'manga_related_anime',
				'type'         => 'post_object',
				'instructions' => '選擇對應的動畫作品,前台做「動畫化」雙向連結。',
				'post_type'    => [ 'anime' ],
				'return_format'=> 'id',
				'ui'           => 1,
				'allow_null'   => 1,
				'multiple'     => 0,
			],

			// ---- 鎖定欄位(沿用動畫同一機制) ----
			[
				'key'          => 'field_manga_locked_fields',
				'label'        => '🔒 鎖定欄位(同步時不覆蓋)',
				'name'         => 'anime_locked_fields', // 沿用!import manager 的鎖定判斷認這個 key
				'type'         => 'checkbox',
				'instructions' => '勾選的欄位在 Cron / 重新同步時不會被覆蓋,保護你手動修改的內容。',
				'choices'      => [
					'anime_title_chinese'    => '中文標題',
					'anime_synopsis_chinese' => '簡介',
					'anime_cover_image'      => '封面圖',
					'manga_author'           => '作者',
					'manga_artist'           => '作畫',
				],
				'layout'       => 'horizontal',
			],
		],
		'location'   => [
			[ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
		],
		'menu_order' => 10,
		'position'   => 'normal',
		'style'      => 'default',
		'active'     => true,
	] );
}

    // =========================================================================
    // 群組 M2:漫畫出版資訊(日出版社/連載雜誌/每卷 ISBN/多地區代理)
    // location 綁定 post_type == manga
    // =========================================================================
    private function register_manga_publication(): void {
        acf_add_local_field_group( [
            'key'    => 'group_manga_publication',
            'title'  => '📚 出版資訊',
            'fields' => [
                [
                    'key'          => 'field_manga_jp_publishers',
                    'label'        => '日本出版社',
                    'name'         => 'manga_jp_publishers',
                    'type'         => 'text',
                    'instructions' => '例如 集英社、講談社、史克威爾艾尼克斯。同步時由維基 infobox / Wikidata 自動帶入。',
                ],
                [
                    'key'          => 'field_manga_magazine',
                    'label'        => '連載雜誌',
                    'name'         => 'manga_magazine',
                    'type'         => 'text',
                    'instructions' => '例如 週刊少年Jump、月刊BIG GANGAN、週刊Young Magazine。以維基 infobox 為主。',
                ],
                [
                    'key'          => 'field_manga_first_publish_date',
                    'label'        => '首刊發表日',
                    'name'         => 'manga_first_publish_date',
                    'type'         => 'text',
                    'instructions' => 'YYYY-MM-DD。來自 Wikidata P577 或維基出版表第 1 卷。',
                ],
                [
                    'key'          => 'field_manga_hk_publisher',
                    'label'        => '香港代理商',
                    'name'         => 'manga_hk_publisher',
                    'type'         => 'text',
                    'instructions' => '例如 玉皇朝、天下出版。港澳讀者最關心的欄位之一。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_cn_publisher',
                    'label'        => '中國大陸代理商',
                    'name'         => 'manga_cn_publisher',
                    'type'         => 'text',
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_volumes_jp',
                    'label'        => '日版已出卷數',
                    'name'         => 'manga_volumes_jp',
                    'type'         => 'number',
                    'instructions' => '維基出版表統計的實際卷數(最準)。',
                    'wrapper'      => [ 'width' => '33' ],
                ],
                [
                    'key'          => 'field_manga_volumes_hk',
                    'label'        => '港版已出卷數',
                    'name'         => 'manga_volumes_hk',
                    'type'         => 'number',
                    'wrapper'      => [ 'width' => '33' ],
                ],
                [
                    'key'          => 'field_manga_volumes_cn',
                    'label'        => '陸版已出卷數',
                    'name'         => 'manga_volumes_cn',
                    'type'         => 'number',
                    'wrapper'      => [ 'width' => '34' ],
                ],
                [
                    'key'          => 'field_manga_volumes_json',
                    'label'        => '每卷發售資訊 (JSON)',
                    'name'         => 'manga_volumes_json',
                    'type'         => 'textarea',
                    'rows'         => 6,
                    'instructions' => '自動來自維基百科「出版」章節。前端範本 json_decode 後展開。一般不用手改。',
                ],
                [
                    'key'          => 'field_manga_volumes_summary',
                    'label'        => '每卷資訊可讀表 (Markdown)',
                    'name'         => 'manga_volumes_summary',
                    'type'         => 'textarea',
                    'rows'         => 10,
                    'instructions' => '若只想讀,看這一欄就好。與 JSON 同步自動產生。',
                ],
                [
                    'key'          => 'field_manga_volume_covers', // ← 新增:單行本封面牆
                    'label'        => '📚 單行本封面 (JSON)',
                    'name'         => 'manga_volume_covers',
                    'type'         => 'textarea',
                    'rows'         => 6,
                    'instructions' => '由 Bangumi 關聯條目自動抓取、封面下載至本地。格式:[{"vol":1,"cover":"本地URL","bgm_id":344360}]。前台封面牆讀此欄位,勿手改。',
                    'wrapper'      => [ 'width' => '100', 'class' => 'asp-readonly' ],
                ],
                [
                    'key'          => 'field_manga_awards',
                    'label'        => '獲獎紀錄',
                    'name'         => 'manga_awards',
                    'type'         => 'textarea',
                    'rows'         => 3,
                    'instructions' => '來自 Wikidata P166。例如「這本漫畫真厲害!」、漫畫大賞等。',
                ],
                [
                    'key'          => 'field_manga_wiki_last_sync',
                    'label'        => '🕒 上次維基同步時間',
                    'name'         => 'manga_wiki_last_sync',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_wiki_last_status',
                    'label'        => '同步狀態',
                    'name'         => 'manga_wiki_last_status',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => [ 'width' => '50' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
            ],
            'menu_order' => 15,
            'position'   => 'normal',
            'style'      => 'default',
            'active'     => true,
        ] );
    }


    // =========================================================================
    // 群組 M3:資料來源(精簡版)
    // 以台港澳為主,移除 MangaUpdates / ANN / 豆瓣 / 百度 / Comic Walker /
    // Niconico / Google KG 等 wikidata-fetcher 已不再提供的冷門外部 ID。
    // 只保留 QID 與維基網址,方便回查資料來源。顯示於側邊。
    // =========================================================================
    private function register_manga_external(): void {
        acf_add_local_field_group( [
            'key'    => 'group_manga_external_ids',
            'title'  => '🔗 資料來源',
            'fields' => [
                [
                    'key'          => 'field_manga_wikidata_qid',
                    'label'        => 'Wikidata QID',
                    'name'         => 'manga_wikidata_qid',
                    'type'         => 'text',
                    'instructions' => '同步時自動帶入,方便回查資料來源。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_wikipedia_url',
                    'label'        => '維基百科網址',
                    'name'         => 'manga_wikipedia_url',
                    'type'         => 'url',
                    // ★改:此欄現可「手動填」作為維基查找依據。
                    'instructions' => '中文維基頁網址,例如 https://zh.wikipedia.org/wiki/膽大黨。<br>'
                                    . '<strong>手動填寫後,按「🔄 重新抓取維基資料」會優先用此網址對應的條目去抓</strong>'
                                    . '(適用譯名對不上、簡繁轉換仍查不到的作品)。<br>'
                                    . '留空則系統自動用中文標題(轉繁後)查找,查到會自動回填此欄。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
            ],
            'menu_order' => 30,
            'position'   => 'side',
            'style'      => 'default',
            'active'     => true,
        ] );
    }

    // =========================================================================
    // 群組 M4:漫畫試閱 / 免費閱讀(合法試閱連結)
    // location 綁定 post_type == manga
    // =========================================================================
    private function register_manga_preview(): void {
        acf_add_local_field_group( [
            'key'    => 'group_manga_preview',
            'title'  => '📖 試閱 / 免費閱讀',
            'fields' => [
                [
                    'key'          => 'field_manga_preview_url',
                    'label'        => '免費閱讀 / 試閱連結',
                    'name'         => 'manga_preview_url',
                    'type'         => 'url',
                    'instructions' => '貼上合法免費閱讀或試閱網址,例如 Book☆Walker 台灣、少年Jump+、Comic Walker 的試閱頁。',
                    'required'     => 0,
                    'placeholder'  => 'https://www.bookwalker.com.tw/...',
                ],
                [
                    'key'          => 'field_manga_preview_source_type',
                    'label'        => '來源類型',
                    'name'         => 'manga_preview_source_type',
                    'type'         => 'select',
                    'choices'      => [
                        ''                  => '—',
                        'trial'             => '試閱(前幾話或前幾頁)',
                        'official_free'     => '官方完全免費',
                        'limited_time_free' => '期間限定免費',
                        'aggregator'        => '聚合區(例 weixiaoacg)',
                    ],
                    'default_value' => '',
                    'allow_null'    => 1,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_manga_preview_note',
                    'label'        => '備註',
                    'name'         => 'manga_preview_note',
                    'type'         => 'text',
                    'instructions' => '例如「免費看到第 30 話」、「2026-12-31 前全集免費」。',
                    'wrapper'      => [ 'width' => '50' ],
                ],
            ],
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'manga' ] ],
            ],
            'menu_order' => 20,
            'position'   => 'normal',
            'style'      => 'default',
            'active'     => true,
        ] );
    }

    // =========================================================================
    // 群組 6:主題曲與串流平台
    // =========================================================================
    private function register_themes_and_streaming(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_themes_streaming',
            'title'  => '🎵 主題曲與串流平台',
            'fields' => [
                [
                    'key'           => 'field_anime_themes_json',
                    'label'         => 'OP/ED 主題曲資料(JSON)',
                    'name'          => 'anime_themes',
                    'type'          => 'textarea',
                    // 開放手動編輯：可修改歌名與歌手
                    'instructions'  => '由 AnimeThemes API 自動抓取，可手動修改歌名與歌手。<br>'
                                     . '⚠️ 只改 <code>title</code> 與 <code>artists</code>，<strong>請勿更動 <code>type</code> 與 <code>sequence</code></strong>（否則同步會誤判成新歌而重複新增）。<br>'
                                     . 'AnimeThemes 之後補的新歌仍會自動加入，不會覆蓋您已改的內容。',
                    'required'      => 0,
                    'rows'          => 8,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100' ], // 已移除 asp-readonly，開放編輯
                ],
                [
                    'key'           => 'field_anime_streaming_json',
                    'label'         => '串流平台資料(JSON)',
                    'name'          => 'anime_streaming',
                    'type'          => 'textarea',
                    'instructions'  => '由 AniList externalLinks(type: STREAMING)自動填入。請勿手動編輯。',
                    'required'      => 0,
                    'rows'          => 4,
                    'new_lines'     => '',
                    'wrapper'       => [ 'width' => '100', 'class' => 'asp-readonly' ], // 維持唯讀
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 60,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }


    // =========================================================================
    // 群組 7:外部連結
    // =========================================================================
    private function register_external_links(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_external_links',
            'title'  => '🔗 外部連結',
            'fields' => [
                [
                    'key'           => 'field_anime_official_site',
                    'label'         => '官方網站',
                    'name'          => 'anime_official_site',
                    'type'          => 'url',
                    'instructions'  => '由 AniList externalLinks 自動填入。可人工覆寫。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_twitter_url',
                    'label'         => 'Twitter / X 官方帳號',
                    'name'          => 'anime_twitter_url',
                    'type'          => 'url',
                    'instructions'  => '由 AniList externalLinks 自動填入。可人工覆寫。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_wikipedia_url',
                    'label'         => 'Wikipedia 頁面',
                    'name'          => 'anime_wikipedia_url',
                    'type'          => 'url',
                    'instructions'  => '請人工填入中文或日文維基百科連結。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_anime_tiktok_url',
                    'label'         => 'TikTok 官方帳號',
                    'name'          => 'anime_tiktok_url',
                    'type'          => 'url',
                    'instructions'  => '請人工填入 TikTok 官方帳號連結(選填)。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 70,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }
    
    

    // =========================================================================
    // 群組 8:台灣在地資訊
    // =========================================================================
    private function register_taiwan_info(): void {

        $platforms  = $this->get_tw_platforms();
        $url_fields = [];

        foreach ( $platforms as $key => $label ) {
            $url_fields[] = [
                'key'          => 'field_anime_tw_streaming_url_' . $key,
                'label'        => $label . ' 直達連結',
                'name'         => 'anime_tw_streaming_url_' . $key,
                'type'         => 'url',
                'instructions' => '勾選上方「' . $label . '」後,可在此填入該動漫的直達連結(留空則顯示純文字)。',
                'required'     => 0,
                'wrapper'      => [ 'width' => '50' ],
            ];
        }

        acf_add_local_field_group( [
            'key'    => 'group_anime_taiwan_info',
            'title'  => '🇹🇼 台灣在地資訊',
            'fields' => array_merge(
                [
                    [
                        'key'           => 'field_anime_tw_streaming',
                        'label'         => '台灣串流平台',
                        'name'          => 'anime_tw_streaming',
                        'type'          => 'checkbox',
                        'instructions'  => '勾選有上架的平台;下方可對應填入該動漫的直達連結。',
                        'required'      => 0,
                        'choices'       => $platforms,
                        'layout'        => 'horizontal',
                        'toggle'        => 0,
                        'return_format' => 'value',
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                ],
                $url_fields,
                [
                    [
                        'key'           => 'field_anime_tw_streaming_other',
                        'label'         => '其他串流平台(自訂)',
                        'name'          => 'anime_tw_streaming_other',
                        'type'          => 'text',
                        'instructions'  => '上方平台以外的服務,多個請用逗號分隔。',
                        'required'      => 0,
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                    [
    'key'           => 'field_anime_no_streaming_google',
    'label'         => 'Google 搜尋連結（無串流平台時顯示）',
    'name'          => 'anime_no_streaming_google',
    'type'          => 'url',
    'instructions'  => '當本作在台灣尚無任何串流平台上架時，可填入一個 Google 搜尋結果連結，供使用者自行查找。<br>'
                     . '留空的話，前台可自動用作品名稱組出搜尋連結。<br>'
                     . '範例：<code>https://www.google.com/search?q=作品名稱+線上看</code>',
    'required'      => 0,
    'placeholder'   => 'https://www.google.com/search?q=...',
    'wrapper'       => [ 'width' => '100' ],
],
                    [
                        'key'           => 'field_anime_tw_distributor',
                        'label'         => '台灣代理商/發行商',
                        'name'          => 'anime_tw_distributor',
                        'type'          => 'select',
                        'instructions'  => '請選擇台灣代理商;若不在清單中請選「其他(自訂)」並於下方填寫。',
                        'required'      => 0,
                        'choices'       => [
                            ''            => '── 請選擇 ──',
                            'muse'        => '木棉花',
                            'medialink'   => '曼迪傳播',
                            'linbang'     => '羚邦',
                            'tropic'      => '回歸線娛樂',
                            'proware'     => '普威爾',
                            'kadokawa'    => '台灣角川',
                            'gungho'      => '群英社',
                            'tien'        => '提恩傳媒',
                            'garage'      => '車庫娛樂',
                            'carsun'      => '采昌國際',
                            'jbf'         => '日本橋文化(JBF)',
                            'righttime'   => '利得時代(Right Time)',
                            'aniplus'     => 'ANIPLUS Asia',
                            'tongli'      => '東立出版社',
                            'remow'       => 'REMOW',
                            'gaga'        => 'GaGa OOLala',
                            'other'       => '其他(自訂)',
                        ],
                        'default_value' => '',
                        'allow_null'    => 1,
                        'wrapper'       => [ 'width' => '50' ],
                    ],
                    [
                        'key'           => 'field_anime_tw_distributor_custom',
                        'label'         => '台灣代理商(自訂名稱)',
                        'name'          => 'anime_tw_distributor_custom',
                        'type'          => 'text',
                        'instructions'  => '僅在上方選「其他(自訂)」時生效。',
                        'required'      => 0,
                        'wrapper'       => [ 'width' => '50' ],
                    ],
                    [
                        'key'           => 'field_anime_tw_broadcast',
                        'label'         => '台灣播出時間',
                        'name'          => 'anime_tw_broadcast',
                        'type'          => 'text',
                        'instructions'  => '請人工填入台灣播出時間(例:每週六 23:00 Netflix)。',
                        'required'      => 0,
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                    [
                        'key'           => 'field_anime_dub_language',
                        'label'         => '配音語言版本',
                        'name'          => 'anime_dub_language',
                        'type'          => 'checkbox',
                        'instructions'  => '勾選本作在台灣有提供的配音版本(可複選)。用於前台標示與 SEO。',
                        'required'      => 0,
                        'choices'       => [
                            'mandarin' => '國語配音',
                            'taigi'    => '台語配音',
                        ],
                        'layout'        => 'horizontal',
                        'toggle'        => 0,
                        'return_format' => 'value',
                        'wrapper'       => [ 'width' => '100' ],
                    ],
                    [
                        'key'          => 'field_anime_dub_url_taigi',
                        'label'        => '台語配音 觀看連結',
                        'name'         => 'anime_dub_url_taigi',
                        'type'         => 'url',
                        'instructions' => '若上方勾選「台語配音」,填入可觀看台語版的平台連結(多為公視+)。留空則只顯示文字標示。',
                        'required'     => 0,
                        'wrapper'      => [ 'width' => '50' ],
                    ],
                    [
    'key'          => 'field_anime_dub_url_mandarin',
    'label'        => '國語配音 觀看連結（可填多平台）',
    'name'         => 'anime_dub_url_mandarin',
    'type'         => 'textarea',
    'instructions' => '可填一個或多個平台連結，換行或逗號分隔皆可。<br>'
                     . '格式：<code>平台名稱|網址</code>，未填名稱則統一顯示「國語配音」。<br><br>'
                     . '<strong>範例：</strong><br>'
                     . '<code>巴哈動畫瘋|https://ani.gamer.com.tw/animeVideo.php?sn=xxxx</code><br>'
                     . '<code>Ofiii|https://www.ofiii.com/xxxx</code><br>'
                     . '<code>LineTV|https://www.linetv.tw/xxxx</code>',
    'rows'         => 4,
    'new_lines'    => '',
    'required'     => 0,
    'wrapper'      => [ 'width' => '50' ],
],
                           [
                        'key'           => 'field_anime_youranimes_url',
                        'label'         => 'YourAnimes 網址',
                        'name'          => 'anime_youranimes_url',
                        'type'          => 'url',
                        'instructions'  => '貼上 YourAnimes 動畫頁網址https://youranimes.tw/animes/xxxx/onair',
                        'required'      => 0,
                        'placeholder'   => 'https://youranimes.tw/animes/XXXX/onair',
                        'wrapper'       => [ 'width' => '100', 'class' => 'anime-youranimes-url-field' ],
                    ],
                      [
                        'key'          => 'field_anime_online_watch',
                        'label'        => '線上看（YouTube 嵌入）',
                        'name'         => 'anime_online_watch',
                        'type'         => 'textarea',
                        'instructions' => '貼 YouTube 網址，一行一支，前台會嵌入播放器。可選加標題：<code>標題|網址</code>。<br>'
                                        . '支援 watch?v=、youtu.be、/embed/、/shorts/ 各種格式。<br><br>'
                                        . '<strong>依照網址填入範例：</strong><br>'
                                        . '<code>第1話|https://youtu.be/XXXXXXXX</code><br>'
                                        . '<code>第2話|</code><br>'
                                        . '<code>第3話|</code>',
                        'rows'         => 4,
                        'new_lines'    => '',
                        'required'     => 0,
                        'wrapper'      => [ 'width' => '100' ],
                    ],

                    // ▶️ YouTube 播放清單自動同步（原獨立群組，改併入本群組，緊接線上看下方）
                    [
                        'key'          => 'field_anime_yt_playlist_url',
                        'label'        => 'YouTube 播放清單網址',
                        'name'         => 'anime_yt_playlist_url',
                        'type'         => 'url',
                        'instructions' => '貼上該作品的官方 YouTube 播放清單網址,系統會自動抓取新集數並補進上方「線上看」。<br>'
                                        . '格式:<code>https://www.youtube.com/playlist?list=PLxxxxxxxx</code><br>'
                                        . '只認標題含「第X話 / #X / EPx」的影片,PV/預告會自動略過。',
                        'required'     => 0,
                        'placeholder'  => 'https://www.youtube.com/playlist?list=PL...',
                        'wrapper'      => [ 'width' => '100' ],
                    ],
                    [
                        'key'          => 'field_anime_yt_sync_enabled',
                        'label'        => '啟用自動同步',
                        'name'         => 'anime_yt_sync_enabled',
                        'type'         => 'true_false',
                        'instructions' => '關閉後此作品不再自動抓取(適合已完結、不需再更新的作品)。',
                        'default_value'=> 1,
                        'ui'           => 1,
                        'wrapper'      => [ 'width' => '50' ],
                    ],
                    [
                        'key'          => 'field_anime_yt_last_sync',
                        'label'        => '上次 YT 同步時間',
                        'name'         => 'anime_yt_last_sync',
                        'type'         => 'text',
                        'instructions' => '系統自動記錄,請勿手動修改。',
                        'required'     => 0,
                        'wrapper'      => [ 'width' => '50', 'class' => 'asp-readonly' ],
                    ],
                    [
                        'key'          => 'field_anime_yt_sync_log',
                        'label'        => '同步紀錄',
                        'name'         => 'anime_yt_sync_log',
                        'type'         => 'textarea',
                        'instructions' => '最近幾次自動同步的結果,方便排查。系統自動維護。',
                        'required'     => 0,
                        'rows'         => 3,
                        'wrapper'      => [ 'width' => '100', 'class' => 'asp-readonly' ],
                    ],
                ]
            ),
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 80,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }


    // =========================================================================
    // 群組 9：常見問題（FAQ）
    // =========================================================================

    private function register_faq(): void {

        // 取得目前正在編輯的文章 ID
        $pid = 0;
        if ( is_admin() ) {
            if ( isset( $_GET['post'] ) ) {
                $pid = (int) $_GET['post'];
            } elseif ( isset( $_POST['post_ID'] ) ) {
                $pid = (int) $_POST['post_ID'];
            }
        }

        $current_title    = $pid > 0 ? get_the_title( $pid ) : '';
        $title_for_prompt = $current_title !== '' ? $current_title : '（請填入作品名稱）';

        // ---------------------------------------------------------------------
        // 自動抓取「補充辨識」資料
        // ---------------------------------------------------------------------
        $extra_parts = [];
        if ( $pid > 0 ) {

            $extra_map = [
                '日文原名' => 'anime_title_native',
                '原作來源' => 'anime_source',
                '作品類型' => 'anime_format',
                '播出季度' => 'anime_season',
                '播出年份' => 'anime_season_year',
            ];

            $season_label = [
                'WINTER' => '冬季', 'SPRING' => '春季',
                'SUMMER' => '夏季', 'FALL' => '秋季', 'AUTUMN' => '秋季',
            ];
            $source_label = [
                'MANGA'        => '漫畫',
                'ORIGINAL'     => '原創',
                'LIGHT_NOVEL'  => '輕小說',
                'NOVEL'        => '小說',
                'VISUAL_NOVEL' => '視覺小說',
                'VIDEO_GAME'   => '電玩遊戲',
                'GAME'         => '遊戲',
                'WEB_NOVEL'    => '網路小說',
                'WEB_MANGA'    => '網路漫畫',
                'DOUJINSHI'    => '同人誌',
                'ANIME'        => '動畫',
                'OTHER'        => '其他',
            ];
            $format_label = [
                'TV'       => '電視動畫',
                'TV_SHORT' => '電視短篇動畫',
                'MOVIE'    => '劇場版',
                'OVA'      => 'OVA',
                'ONA'      => 'ONA（網路動畫）',
                'SPECIAL'  => '特別篇',
                'MUSIC'    => '音樂 MV',
            ];

            foreach ( $extra_map as $label => $meta_key ) {
                $val = get_post_meta( $pid, $meta_key, true );
                if ( ! is_string( $val ) || $val === '' ) {
                    continue;
                }
                $upper = strtoupper( $val );

                if ( $meta_key === 'anime_season' && isset( $season_label[ $upper ] ) ) {
                    $val = $season_label[ $upper ];
                } elseif ( $meta_key === 'anime_source' && isset( $source_label[ $upper ] ) ) {
                    $val = $source_label[ $upper ];
                } elseif ( $meta_key === 'anime_format' && isset( $format_label[ $upper ] ) ) {
                    $val = $format_label[ $upper ];
                }

                $extra_parts[] = "{$label}：{$val}";
            }
        }

        if ( ! empty( $extra_parts ) ) {
            $extra_line = '【補充辨識】' . implode( '／', $extra_parts ) . "\n";
        } else {
            $extra_line = "【補充辨識】（選填,例：第二季 / 劇場版 / TV版）\n";
        }

        // ---------------------------------------------------------------------
        // 組 prompt 文字
        // ---------------------------------------------------------------------
        $prompt  = "你是動漫資料編輯。請上網搜尋我提供的作品，並撰寫 FAQ。\n\n";
        $prompt .= "【作品名稱】{$title_for_prompt}　\n";
        $prompt .= $extra_line . "\n";
        $prompt .= "搜尋與撰寫規則：\n";
        $prompt .= "1. 動筆前先核對：以上方【補充辨識】的日文原名、年份、媒體形式、季別，確認鎖定的是「正確的作品、正確的季別」。若有同名作品、多季、TV版/劇場版/重製版，務必區分清楚，不可混用不同季別的資訊。\n";
        $prompt .= "2. 來源優先順序：① 中文維基百科（主要比對依據，核對譯名、季別、集數）→ ② 日文維基百科、官方網站（故事設定若官網有明確說明可優先參考）→ ③ AniList、MyAnimeList、巴哈姆特（輔助補漏與交叉驗證）。避免個人部落格或來路不明的網站，並盡量交叉比對至少兩個來源。\n";
        $prompt .= "3. 【原創改寫】不得整句照抄任何來源原文。請綜合多個來源後，用你自己的話重新敘述，確保內容原創，不與維基百科逐字重複。\n";
        $prompt .= "4. 【防止虛構】僅根據查證到的資料撰寫。若某項資訊無法交叉確認，寧可省略該題，絕不虛構或用內部記憶推測。\n";
        $prompt .= "5. 題數規則：資料充足產出 3~5 題；資料有限則產出 1~2 題；若完全查無可靠資料，輸出空陣列 []。\n";
        $prompt .= "6. 問題須聚焦「劇情設定、世界觀、角色背景、故事主軸」等內容面向，而非製作或播出時程。答案須簡明扼要，嚴格不涉及關鍵轉折、結局或重大伏筆（不劇透）。\n";
        $prompt .= "7. 【SEO】每題問題開頭須包含完整作品名稱。例如：「動畫《{$title_for_prompt}》的故事背景與核心主軸是什麼？」\n";
        $prompt .= "8. 每題答案以繁體中文撰寫，長度約 50 字左右，簡潔不冗長。\n";
        $prompt .= "9. 來源請「另外用純文字」告訴我方便核對，不要寫進 JSON 的答案內容裡。\n\n";
        $prompt .= "輸出格式（嚴格遵守）：\n";
        $prompt .= "- 只輸出一個 JSON 陣列，放在程式碼框內供一鍵複製。\n";
        $prompt .= "- 每個物件僅含 q 與 a 兩個欄位，不得加入 source 或其他欄位。\n";
        $prompt .= "- 必須是合法 JSON：使用半形雙引號（不可用全形引號），結尾不得有多餘逗號，不得加入註解。\n";
        $prompt .= "- 查無資料時，程式碼框內輸出：[]\n\n";
        $prompt .= '範例：[{"q":"問題一","a":"答案一"},{"q":"問題二","a":"答案二"}]';

       // ---------------------------------------------------------------------
        // 說明文字 + 複製按鈕（clipboard API 優先，失敗退回 execCommand）
        // ---------------------------------------------------------------------
        $ta_id  = 'anime_faq_prompt_' . ( $pid > 0 ? $pid : 'new' );
        $btn_id = $ta_id . '_btn';

        $instructions  = '完全人工輸入，留空則不顯示 FAQ 區塊與 Schema.org FAQPage。<br>';
        $instructions .= '<strong>格式範例：</strong> <code>[{"q":"問題一","a":"答案一"}]</code><br><br>';
        $instructions .= '<strong>📋 作品名稱與補充辨識已自動帶入。點框內後按 Ctrl+A 再 Ctrl+C：</strong>';
        $instructions .= '<span style="display:block; margin:8px 0;">';
        $instructions .= '<textarea id="' . esc_attr( $ta_id ) . '" readonly onclick="this.focus();this.select();" style="width:100%;height:220px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;padding:10px;font-size:12px;line-height:1.6;">' . esc_textarea( $prompt ) . '</textarea>';
        $instructions .= '</span>';
        $instructions .= '<strong>⚠️ AI 回覆中，來源說明在上、JSON 程式碼框在下；用程式碼框右上角複製鈕取得 JSON，貼回前確認只有 q/a 兩個欄位。</strong>';

        acf_add_local_field_group( [
            'key'    => 'group_anime_faq',
            'title'  => '❓ 常見問題（FAQ）',
            'fields' => [
                [
                    'key'           => 'field_anime_faq_json',
                    'label'         => 'FAQ JSON',
                    'name'          => 'anime_faq_json',
                    'type'          => 'textarea',
                    'instructions'  => $instructions,
                    'required'      => 0,
                    'rows'          => 8,
                    'new_lines'     => '',
                    'placeholder'   => '[{"q":"問題","a":"答案"}]',
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 85,
            'position'    => 'normal',
            'style'       => 'default',
            'active'      => true,
        ] );
    }



    // =========================================================================
    // 群組 10:同步控制
    // =========================================================================
    private function register_sync_control(): void {
        acf_add_local_field_group( [
            'key'    => 'group_anime_sync_control',
            'title'  => '⚙️ 同步控制',
            'fields' => [
                [
                    'key'           => 'field_anime_last_sync',
                    'label'         => '上次 API 同步時間',
                    'name'          => 'anime_last_sync',
                    'type'          => 'text',
                    'instructions'  => '由系統自動記錄。請勿手動修改。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50', 'class' => 'asp-readonly' ], // 修正 5
                ],
                [
                    'key'           => 'field_anime_last_updated',
                    'label'         => '資料最後更新時間',
                    'name'          => 'anime_last_updated',
                    'type'          => 'text',
                    'instructions'  => '每次任何欄位更新時由系統自動記錄。',
                    'required'      => 0,
                    'wrapper'       => [ 'width' => '50', 'class' => 'asp-readonly' ], // 修正 5
                ],
                [
                    'key'           => 'field_anime_locked_fields',
                    'label'         => '鎖定欄位(防止自動覆寫)',
                    'name'          => 'anime_locked_fields',
                    'type'          => 'checkbox',
                    'instructions'  => '勾選後,自動更新 cron 與重新同步 Bangumi 將跳過該欄位,保留您的人工修改。',
                    'required'      => 0,
                    'choices'       => [
                        'anime_title_chinese'    => '中文標題',
                        'anime_synopsis_chinese' => '中文簡介',
                        'anime_cover_image'      => '封面圖片',
                        'anime_banner_image'     => '橫幅圖片',
                        'anime_trailer_url'      => 'YouTube 預告片',
                        'anime_cast_json'        => 'CAST 角色資料',
                        'anime_staff_json'       => 'STAFF 製作資料',
                        'anime_episodes_json'    => '集數列表',
                    ],
                    'layout'        => 'horizontal',
                    'toggle'        => 0,
                    'return_format' => 'value',
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location'    => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'anime' ] ],
            ],
            'menu_order'  => 90,
            'position'    => 'side',
            'style'       => 'default',
            'active'      => true,
        ] );
    }
    
        // =========================================================================
    // 群組 11:文章關聯動畫(v1.1.0 新增)
    //
    // 用途:在 feature / review 文章後台勾選「這篇影評/前導是寫哪部動畫」,
    //      single-anime.php 依此 meta 反查並顯示「無雷前導 / 有雷影評」按鈕。
    //
    // 資料結構:
    //   meta_key:   related_anime
    //   meta_value: ACF 序列化陣列 of anime post IDs(多選)
    //
    // 顯示條件:post_type=post 且 category in (feature, review)
    //   ACF location 用 OR:post_category == feature OR post_category == review
    // =========================================================================
    private function register_post_related_anime(): void {
        acf_add_local_field_group( [
            'key'    => 'group_post_related_anime',
            'title'  => '🔗 關聯動畫',
            'fields' => [
                [
                    'key'           => 'field_post_related_anime',
                    'label'         => '關聯動畫',
                    'name'          => 'related_anime',
                    'type'          => 'post_object',
                    'instructions'  => '勾選此影評 / 前導文章是寫哪部動畫(可多選)。動畫單頁會自動顯示對應按鈕。',
                    'required'      => 0,
                    'post_type'     => [ 'anime' ],
                    'taxonomy'      => '',
                    'allow_null'    => 1,
                    'multiple'      => 1,
                    'return_format' => 'id',
                    'ui'            => 1,
                    'wrapper'       => [ 'width' => '100' ],
                ],
            ],
            'location' => [
                [ [ 'param' => 'post_category', 'operator' => '==', 'value' => 'category:feature' ] ],
                [ [ 'param' => 'post_category', 'operator' => '==', 'value' => 'category:review'  ] ],
            ],
            'menu_order'      => 5,
            'position'        => 'side',
            'style'           => 'default',
            'label_placement' => 'top',
            'active'          => true,
        ] );
    }

    // =========================================================================
    // Meta Box:重新同步 Bangumi
    // =========================================================================
    public function register_resync_metabox(): void {
        add_meta_box(
            'anime_resync_bangumi',
            '🔄 重新同步 Bangumi',
            [ $this, 'render_resync_metabox' ],
            'anime',
            'side',
            'default'
        );
    }

    public function render_resync_metabox( WP_Post $post ): void {
        $bangumi_id = get_post_meta( $post->ID, 'anime_bangumi_id', true );
        $last_sync  = get_post_meta( $post->ID, 'anime_last_sync',  true );
        ?>
        <div id="anime-resync-wrap">
            <?php if ( $bangumi_id ) : ?>
                <p style="margin:0 0 8px;">
                    Bangumi ID:<strong><?php echo esc_html( $bangumi_id ); ?></strong>
                </p>
            <?php else : ?>
                <p style="margin:0 0 8px;color:#999;">尚未設定 Bangumi ID。</p>
            <?php endif; ?>
            <?php if ( $last_sync ) : ?>
                <p style="margin:0 0 8px;font-size:11px;color:#666;">
                    上次同步:<?php echo esc_html( $last_sync ); ?>
                </p>
            <?php endif; ?>
            <button
                type="button"
                id="anime-resync-bangumi-btn"
                class="button button-secondary"
                style="width:100%;"
            >
                🔄 重新同步 Bangumi 資料
            </button>
            <p id="anime-resync-bangumi-msg" style="margin:8px 0 0;min-height:20px;font-size:12px;"></p>
        </div>
        <?php
    }

    // =========================================================================
    // 修正 5:用 CSS 讓 readonly 真的生效
    // =========================================================================
    public function inject_readonly_css(): void {
        ?>
        <style>
            .acf-field.asp-readonly input[type="text"],
            .acf-field.asp-readonly textarea {
                background: #f6f7f7 !important;
                color: #50575e !important;
                pointer-events: none;
                cursor: not-allowed;
            }
            .acf-field.asp-readonly .acf-label label::after {
                content: ' (唯讀)';
                color: #999;
                font-weight: normal;
                font-size: 11px;
            }

            /* 壓縮「編輯短評與 AI 提示詞」摺疊面板高度 */
            .acf-field-accordion[data-key="field_shortcut_anime_editorial_accordion"] {
                margin-top: 0 !important;
                padding-top: 0 !important;
            }
            .acf-field-accordion[data-key="field_shortcut_anime_editorial_accordion"] .acf-accordion-title {
                padding: 5px 12px !important;
                min-height: auto !important;
                display: flex !important;
                align-items: center !important;
            }
            .acf-field-accordion[data-key="field_shortcut_anime_editorial_accordion"] .acf-accordion-title label {
                margin: 0 !important;
                padding: 0 !important;
                font-size: 13px !important;
            }
        </style>
        <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                var acc = document.querySelector('.acf-field-accordion[data-key="field_shortcut_anime_editorial_accordion"]');
                if (acc && acc.classList.contains('-open')) {
                    var title = acc.querySelector('.acf-accordion-title');
                    if (title) {
                        title.click();
                    }
                }
            }, 500);
        });
        </script>
        <?php
    }
    // =========================================================================
    // 新增:編輯畫面右下角「回到頂部」浮動按鈕
    // 只在文章編輯(post.php / post-new.php)畫面顯示,避免污染其他後台頁面
    // =========================================================================
    public function inject_back_to_top_button(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->base, [ 'post', 'post-new' ], true ) ) {
            return;
        }
        ?>
        <button
            type="button"
            id="asp-back-to-top-btn"
            title="回到頂部"
            style="
                position: fixed;
                right: 24px;
                bottom: 24px;
                z-index: 99999;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                border: none;
                background: #2271b1;
                color: #fff;
                font-size: 20px;
                line-height: 1;
                cursor: pointer;
                box-shadow: 0 2px 8px rgba(0,0,0,0.25);
                display: none;
                align-items: center;
                justify-content: center;
            "
        >↑</button>
        <script>
        (function () {
            var btn = document.getElementById('asp-back-to-top-btn');
            if (!btn) return;

            function toggleBtn() {
                if (window.scrollY > 300) {
                    btn.style.display = 'flex';
                } else {
                    btn.style.display = 'none';
                }
            }

            window.addEventListener('scroll', toggleBtn, { passive: true });
            toggleBtn();

            btn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
        </script>
        <?php
    }

    public function inject_shortcut_scripts(): void {
        global $pagenow;
        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) || get_post_type() !== 'anime' ) {
            return;
        }
        ?>
        <style>
            /* 壓縮捷徑方塊的垂直空間，保留輸入框原本大小 */
            #acf-group_anime_shortcuts .inside, #acf-group_anime_shortcuts > .inside, #acf-group_anime_shortcuts .acf-fields {
                padding: 0 !important; margin: 0 !important;
            }
            #acf-group_anime_shortcuts .acf-field {
                padding: 6px 12px !important; margin: 0 !important;
            }
            #acf-group_anime_shortcuts .acf-label {
                margin: 0 0 3px !important;
            }
            #acf-group_anime_shortcuts .acf-label label {
                font-size: 13px !important;
                color: #444;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // 確保 ACF 已經載入完畢
            function injectButton() {
                if ($('#asp-btn-save-sync').length) return; // 避免重複加入
                
                // 設定絕對定位：左側 50% 往回拉 50% 寬度，達到完美置中
                var $btnHTML = $('<button type="button" id="asp-btn-save-sync" class="button button-primary" style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); z-index: 10; height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: flex; align-items: center; box-sizing: border-box;">💾 儲存捷徑變更</button>');
                
                // 尋找外框的 Header
                var $header = $('#acf-group_anime_shortcuts .postbox-header');
                if ($header.length === 0) {
                    $header = $('#acf-group_anime_shortcuts .hndle'); // 傳統版
                }
                
                // 將 Header 設為相對定位，並塞入按鈕
                $header.css('position', 'relative').append($btnHTML);
                
                // 將「YouTube 預告片網址」的灰色範例標籤加到標題同一行
                var $trailerLabel = $('.acf-field[data-name="shortcut_anime_trailer_url"] .acf-label label');
                if ($trailerLabel.length && $trailerLabel.find('.asp-trailer-example').length === 0) {
                    $trailerLabel.append(' <span class="asp-trailer-example" style="background-color: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 12px; color: #3c434a; font-weight: normal; margin-left: 8px;">範例:https://youtu.be/abc12345678 | PV</span>');
                }

                // 將「YourAnimes 網址」的灰色範例標籤加到標題同一行
                var $yaLabel = $('.acf-field[data-name="shortcut_anime_youranimes_url"] .acf-label label');
                if ($yaLabel.length && $yaLabel.find('.asp-ya-example').length === 0) {
                    $yaLabel.append(' <span class="asp-ya-example" style="background-color: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 12px; color: #3c434a; font-weight: normal; margin-left: 8px;">範例: https://youranimes.tw/animes/xxxx/onair</span>');
                }

                // 將「Wikipedia」旁邊加上開啟連結按鈕
                var $wikiLabel = $('.acf-field[data-name="shortcut_anime_wikipedia_url"] .acf-label label');
                if ($wikiLabel.length && $wikiLabel.find('.asp-wiki-link-btn').length === 0) {
                    var $wikiBtn = $('<a href="#" class="asp-wiki-link-btn" target="_blank" style="margin-left: 10px; font-size: 12px; text-decoration: none; color: #2271b1; background-color: #f0f0f1; padding: 2px 8px; border-radius: 3px; font-weight: normal;">🔗 點擊維基連結</a>');
                    $wikiLabel.append($wikiBtn);

                    $wikiBtn.on('click', function(e) {
                        var currentUrl = $('.acf-field[data-name="shortcut_anime_wikipedia_url"] input[type="url"]').val();
                        if (!currentUrl) {
                            e.preventDefault();
                            alert('請先在下方輸入 Wikipedia 網址');
                        } else {
                            $(this).attr('href', currentUrl);
                        }
                    });
                }

                // 將「YouTube 播放清單」旁邊加上開啟連結按鈕
                var $ytLabel = $('.acf-field[data-name="shortcut_anime_yt_playlist_url"] .acf-label label');
                if ($ytLabel.length && $ytLabel.find('.asp-yt-link-btn').length === 0) {
                    var $ytBtn = $('<a href="#" class="asp-yt-link-btn" target="_blank" style="margin-left: 10px; font-size: 12px; text-decoration: none; color: #2271b1; background-color: #f0f0f1; padding: 2px 8px; border-radius: 3px; font-weight: normal;">🔗 點擊YT連結</a>');
                    $ytLabel.append($ytBtn);

                    $ytBtn.on('click', function(e) {
                        var currentUrl = $('.acf-field[data-name="shortcut_anime_yt_playlist_url"] input[type="url"]').val();
                        if (!currentUrl) {
                            e.preventDefault();
                            alert('請先在下方輸入 YouTube 播放清單網址');
                        } else {
                            $(this).attr('href', currentUrl);
                        }
                    });
                }
            }

            // 針對傳統編輯器
            injectButton();
            
            // 針對某些動態載入的情況（雙重保險）
            if (typeof acf !== 'undefined') {
                acf.addAction('ready', injectButton);
            }

            // 「📋 複製提示詞」按鈕：ACF message 欄位內容會被 wp_kses 過濾，
            // inline onclick 屬性不在允許清單內會被剔除，改用委派事件綁定。
            $(document).on('click', '.asp-copy-prompt-btn', function(e) {
                e.preventDefault();

                var $btn = $(this);
                var $textarea = $btn.closest('.asp-ai-prompt-helper').find('.asp-prompt-text');
                if (!$textarea.length) return;

                var text = $textarea.val();
                var showCopied = function() {
                    $btn.text('✅ 已複製');
                    setTimeout(function() { $btn.text('📋 複製提示詞'); }, 1500);
                };
                var fallbackCopy = function() {
                    $textarea[0].focus();
                    $textarea[0].select();
                    document.execCommand('copy');
                    showCopied();
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(showCopied).catch(fallbackCopy);
                } else {
                    fallbackCopy();
                }
            });

            $(document).on('click', '#asp-btn-save-sync', function(e) {
                e.preventDefault();
                if (typeof acf === 'undefined') return;
                
                var $btn = $(this);
                var postId = $('#post_ID').val();
                var fields = {};
                
                try {
                    // 使用 ACF JS API 抓取捷徑方塊內的所有欄位值
                    acf.getFields({parent: $('#acf-group_anime_shortcuts')}).forEach(function(field) {
                        if (field.data.name) {
                            fields[field.data.name] = field.val();
                        }
                    });
                } catch(e) {
                    alert('讀取欄位值時發生錯誤，請重新整理頁面後再試。');
                    return;
                }

                $btn.text('⏳ 處理中...').prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'asp_shortcut_save_and_sync',
                    nonce: '<?php echo wp_create_nonce("asp_shortcut_sync"); ?>',
                    post_id: postId,
                    fields: fields
                }, function(res) {
                    if (res.success) {
                        if (res.data.action === 'synced') {
                            $btn.text('✅ 已儲存並同步完成！即將重整...');
                        } else {
                            $btn.text('✅ 捷徑已儲存！即將重整...');
                        }
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        alert('錯誤: ' + (res.data.message || res.data));
                        $btn.text('💾 儲存捷徑變更').prop('disabled', false);
                    }
                }).fail(function() {
                    alert('發生網路錯誤，請重試！');
                    $btn.text('💾 儲存捷徑變更').prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_shortcut_save_and_sync(): void {
        check_ajax_referer( 'asp_shortcut_sync', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( '權限不足' );
        }
        
        $fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];
        
        $mapping = [
            'shortcut_anime_title_chinese'    => 'anime_title_chinese',
            'shortcut_anime_title_simplified' => 'anime_title_simplified',
            'shortcut_anime_title_native'     => 'anime_title_native',
            'shortcut_anime_youranimes_url'   => 'anime_youranimes_url',
            'shortcut_anime_tw_distributor'   => 'anime_tw_distributor',
            'shortcut_anime_tw_distributor_custom' => 'anime_tw_distributor_custom',
            'shortcut_anime_yt_playlist_url'  => 'anime_yt_playlist_url',
            'shortcut_anime_online_watch'     => 'anime_online_watch',
            'shortcut_anime_trailer_url'      => 'anime_trailer_url',
            'shortcut_anime_wikipedia_url'    => 'anime_wikipedia_url',
            'shortcut_anime_editor_summary'   => 'anime_editor_summary',
        ];

        $old_ya_url = get_post_meta( $post_id, 'anime_youranimes_url', true );
        $new_ya_url = isset( $fields['shortcut_anime_youranimes_url'] ) ? $fields['shortcut_anime_youranimes_url'] : '';
        
        $old_yt_url = get_post_meta( $post_id, 'anime_yt_playlist_url', true );
        $new_yt_url = isset( $fields['shortcut_anime_yt_playlist_url'] ) ? $fields['shortcut_anime_yt_playlist_url'] : '';

        // 捷徑盒走 AJAX 不會觸發 acf/save_post,這裡手動記下短評舊值,
        // 讓等一下手動呼叫的 auto_assign_editorial_reviewer() 也能判斷短評是否真的有變。
        $this->editor_summary_before_save[ $post_id ] = get_post_meta( $post_id, 'anime_editor_summary', true );

        // 儲存一般 Post Meta(同步補寫 ACF 參考鍵,與 auto_assign_editorial_reviewer() 的寫法一致)
        foreach ( $mapping as $shortcut => $real_key ) {
            if ( isset( $fields[$shortcut] ) ) {
                update_post_meta( $post_id, $real_key, $fields[$shortcut] );
                update_post_meta( $post_id, '_' . $real_key, 'field_' . $real_key );
            }
        }

        // 捷徑盒走 AJAX 不會呼叫 wp_update_post()，post_modified 不會刷新，
        // 前端「資料更新日」（get_the_modified_date()）因此不會變動。
        // 補呼叫一次讓它跟按原生「更新」按鈕的行為一致。
        wp_update_post( [ 'ID' => $post_id ] );

        // 捷徑盒走 AJAX 不會觸發 acf/save_post，這裡手動補上「貼短評即自動指定審核者」。
        $this->auto_assign_editorial_reviewer( $post_id );
        
        // 儲存 Taxonomy (系列標籤) - 從純文字解析
        if ( isset( $fields['shortcut_anime_series_tax'] ) ) {
            $value = $fields['shortcut_anime_series_tax'];
            $term_names = [];
            if ( ! empty( $value ) && is_string( $value ) ) {
                $parts = explode( ',', $value );
                foreach ( $parts as $part ) {
                    $part = trim( $part );
                    if ( ! empty( $part ) ) {
                        $term_names[] = $part;
                    }
                }
            }
            wp_set_object_terms( $post_id, $term_names, 'anime_series_tax', false );
        }
        
        // 智慧判斷：YourAnimes 網址是否有改變？
        $triggered_ya_sync = false;
        $ya_triggered_yt_sync = false;
        if ( ! empty( $new_ya_url ) && $new_ya_url !== $old_ya_url ) {
            if ( class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
                $fetcher = new Anime_Sync_YourAnimes_Fetcher();
                $res = $fetcher->sync_post( $post_id, true );
                if ( is_wp_error( $res ) ) {
                    wp_send_json_error( [ 'message' => '資料已儲存，但 YourAnimes 同步失敗：' . $res->get_error_message() ] );
                } else if ( is_array( $res ) ) {
                    foreach ( $res as $msg ) {
                        if ( mb_strpos( $msg, 'YouTube 自動同步' ) !== false ) {
                            $ya_triggered_yt_sync = true;
                            break;
                        }
                    }
                }
                $triggered_ya_sync = true;
            }
        }
        
        // 智慧判斷：YouTube 網址是否有改變？ (如果剛剛 YA 同步沒有連帶執行 YT 同步，但 YT 網址有變，就要單獨跑 YT 同步)
        $triggered_yt_sync = false;
        if ( ! $ya_triggered_yt_sync && ! empty( $new_yt_url ) && $new_yt_url !== $old_yt_url ) {
            if ( class_exists( 'Anime_Sync_YouTube_Playlist_Sync' ) ) {
                $yt_sync = new Anime_Sync_YouTube_Playlist_Sync();
                $res = $yt_sync->sync_post( $post_id, true );
                // 注意：YouTube 同步回傳的是陣列 ['added' => x, 'skipped' => y, 'msg' => '...']
                if ( is_array( $res ) && isset( $res['msg'] ) && mb_strpos( $res['msg'], '錯誤' ) !== false ) {
                    wp_send_json_error( [ 'message' => '資料已儲存，但 YouTube 同步失敗：' . $res['msg'] ] );
                }
                $triggered_yt_sync = true;
            }
        }
        
        wp_send_json_success( [
            'message' => '儲存成功！',
            'action'  => ( $triggered_ya_sync || $triggered_yt_sync ) ? 'synced' : 'saved'
        ] );
    }

    private function register_shortcuts(): void {
        acf_add_local_field_group( [
            'key'                   => 'group_anime_shortcuts',
            'title'                 => '🚀 編輯捷徑方塊',
            'fields'                => [
                [
                    'key'     => 'field_shortcut_anime_title_chinese',
                    'label'   => '中文標題 (台灣繁體)',
                    'name'    => 'shortcut_anime_title_chinese',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_title_simplified',
                    'label'   => '簡體標題',
                    'name'    => 'shortcut_anime_title_simplified',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_title_native',
                    'label'   => '日文原名',
                    'name'    => 'shortcut_anime_title_native',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_series_tax',
                    'label'   => '系列 (多個請用半形逗號 , 分隔)',
                    'name'    => 'shortcut_anime_series_tax',
                    'type'    => 'text',
                    'wrapper' => [ 'width' => '25' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_youranimes_url',
                    'label'   => 'YourAnimes 網址',
                    'name'    => 'shortcut_anime_youranimes_url',
                    'type'    => 'url',
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_yt_playlist_url',
                    'label'   => 'YouTube 播放清單網址',
                    'name'    => 'shortcut_anime_yt_playlist_url',
                    'type'    => 'url',
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'          => 'field_shortcut_anime_trailer_url',
                    'label'        => 'YouTube 預告片網址',
                    'name'         => 'shortcut_anime_trailer_url',
                    'type'    => 'textarea',
                    'rows'    => 3,
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_online_watch',
                    'label'   => '線上看（YouTube 嵌入）',
                    'name'    => 'shortcut_anime_online_watch',
                    'type'    => 'textarea',
                    'rows'    => 3,
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_wikipedia_url',
                    'label'   => '外部連結-Wikipedia 頁面',
                    'name'    => 'shortcut_anime_wikipedia_url',
                    'type'    => 'url',
                    'wrapper' => [ 'width' => '50' ],
                ],
                [
                    'key'           => 'field_shortcut_anime_tw_distributor',
                    'label'         => '台灣代理商/發行商',
                    'name'          => 'shortcut_anime_tw_distributor',
                    'type'          => 'select',
                    'choices'       => [
                        ''            => '── 請選擇 ──',
                        'muse'        => '木棉花',
                        'medialink'   => '曼迪傳播',
                        'linbang'     => '羚邦',
                        'tropic'      => '回歸線娛樂',
                        'proware'     => '普威爾',
                        'kadokawa'    => '台灣角川',
                        'gungho'      => '群英社',
                        'tien'        => '提恩傳媒',
                        'garage'      => '車庫娛樂',
                        'carsun'      => '采昌國際',
                        'jbf'         => '日本橋文化(JBF)',
                        'righttime'   => '利得時代(Right Time)',
                        'aniplus'     => 'ANIPLUS Asia',
                        'tongli'      => '東立出版社',
                        'remow'       => 'REMOW',
                        'gaga'        => 'GaGa OOLala',
                        'other'       => '其他(自訂)',
                    ],
                    'default_value' => '',
                    'allow_null'    => 1,
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'           => 'field_shortcut_anime_tw_distributor_custom',
                    'label'         => '台灣代理商(自訂名稱)',
                    'name'          => 'shortcut_anime_tw_distributor_custom',
                    'type'          => 'text',
                    'wrapper'       => [ 'width' => '25' ],
                ],
                [
                    'key'          => 'field_shortcut_anime_editorial_accordion',
                    'label'        => '📝 人工短評與 AI 提示詞',
                    'name'         => '',
                    'type'         => 'accordion',
                    'open'         => 0,
                    'multi_expand' => 1,
                    'endpoint'     => 0,
                ],
                [
                    'key'          => 'field_shortcut_anime_editor_summary',
                    'label'        => '編輯推薦短評（貼上後按「儲存捷徑變更」）',
                    'name'         => 'shortcut_anime_editor_summary',
                    'type'         => 'textarea',
                    'instructions' => '貼上查證過的繁中原創短評；儲存後自動指定審核者/日期並標記已發布。',
                    'rows'         => 9,
                    'wrapper'      => [ 'width' => '45' ],
                ],
                [
                    'key'       => 'field_shortcut_anime_prompt_helper',
                    'label'     => '',
                    'name'      => '',
                    'type'      => 'message',
                    // 訊息由 acf/prepare_field 動態填入（含繁中標題）。
                    'message'   => '',
                    'new_lines' => '',
                    'esc_html'  => 0,
                    'wrapper'   => [ 'width' => '55' ],
                ],
            ],
            'location'              => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'anime',
                    ],
                ],
            ],
            'menu_order'            => 0,
            'position'              => 'acf_after_title',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen'        => '',
            'active'                => true,
            'description'           => '',
        ] );
    }

    private function register_ai_shortcuts(): void {
        acf_add_local_field_group( [
            'key'                   => 'group_anime_shortcuts_ai',
            'title'                 => '🤖 AI 輔助捷徑方塊',
            'fields'                => [
                [
                    'key'     => 'field_shortcut_ai_top_ui',
                    'label'   => '',
                    'name'    => '',
                    'type'    => 'message',
                    // 修正 3:移除死碼 #asp-ai-console(display:none 且從未被 JS 使用,實際 log 面板是動態注入的 #asp-ai-console-box)
                    'message' => '<div id="asp-ai-top-ui"></div>',
                    'new_lines' => '',
                    'esc_html' => 0,
                ],
                [
                    'key'     => 'field_shortcut_ai_generate_synopsis',
                    'label'   => '啟用 AI 生成簡介',
                    'name'    => 'shortcut_ai_generate_synopsis',
                    'type'    => 'true_false',
                    'ui'      => 1,
                    'default_value' => 1,
                    'wrapper' => [ 'width' => '20' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_synopsis_chinese',
                    'label'   => '中文簡介 (台灣繁體)',
                    'name'    => 'shortcut_anime_synopsis_chinese',
                    'type'    => 'textarea',
                    'rows'    => 4,
                    'wrapper' => [ 'width' => '80' ],
                ],
                [
                    'key'     => 'field_shortcut_ai_generate_faq',
                    'label'   => '啟用 AI 生成 FAQ',
                    'name'    => 'shortcut_ai_generate_faq',
                    'type'    => 'true_false',
                    'ui'      => 1,
                    'default_value' => 1,
                    'wrapper' => [ 'width' => '20' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_faq_json',
                    'label'   => 'FAQ JSON',
                    'name'    => 'shortcut_anime_faq_json',
                    'type'    => 'textarea',
                    'rows'    => 4,
                    'wrapper' => [ 'width' => '80' ],
                ],
                [
                    'key'     => 'field_shortcut_ai_generate_cast',
                    'label'   => '啟用 AI 生成 CAST',
                    'name'    => 'shortcut_ai_generate_cast',
                    'type'    => 'true_false',
                    'ui'      => 1,
                    'default_value' => 1,
                    'wrapper' => [ 'width' => '20' ],
                ],
                [
                    'key'     => 'field_shortcut_anime_cast_json',
                    'label'   => 'CAST 角色資料 JSON',
                    'name'    => 'shortcut_anime_cast_json',
                    'type'    => 'textarea',
                    'rows'    => 4,
                    'wrapper' => [ 'width' => '80' ],
                ],
                [
                    'key'     => 'field_shortcut_ai_settings_ui',
                    'label'   => '',
                    'name'    => '',
                    'type'    => 'message',
                    'message' => '<div id="asp-ai-settings-container"></div>',
                    'new_lines' => '',
                    'esc_html' => 0,
                ],
            ],
            'location'              => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'anime',
                    ],
                ],
            ],
            'menu_order'            => 1, // 緊黏在區塊 1 下方
            'position'              => 'acf_after_title',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen'        => '',
            'active'                => true,
            'description'           => '',
        ] );
    }

    // =========================================================================
    // Helper:台灣串流平台定義
    // =========================================================================
    private function get_tw_platforms(): array {
        // ✅ [Registry] 改由 Anime_Sync_Streaming_Registry 統一管理
        // 新增平台只需修改 class-streaming-registry.php，此處無需改動
        return Anime_Sync_Streaming_Registry::get_acf_choices();
    }

        // =========================================================================
    // 靜態輔助方法
    // =========================================================================
    public static function get_auto_update_fields(): array {
        return [
            'anime_episodes_aired' => '已播集數',
            'anime_status'         => '播出狀態',
            'anime_next_airing'    => '下一集播出時間',
            'anime_score_anilist'  => 'AniList 評分',
            'anime_score_mal'      => 'MAL 評分',
            'anime_score_bangumi'  => 'Bangumi 評分',
            'anime_popularity'     => 'AniList 人氣數',
            'anime_end_date'       => '完結日期',
        ];
    }

    public static function get_enrich_fields(): array {
        return [
            'anime_cast_json'     => 'CAST 角色資料',
            'anime_staff_json'    => 'STAFF 製作資料',
            'anime_episodes_json' => '集數列表',
            'anime_themes'        => 'OP/ED 主題曲資料',
        ];
    }
    // =========================================================================
    // AI 輔助捷徑方塊功能
    // =========================================================================

    public function inject_ai_shortcut_scripts(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'anime' ) return;

        $user_id = get_current_user_id();
        $provider = get_user_meta($user_id, 'asp_ai_provider', true) ?: 'gemini';
        $model = get_user_meta($user_id, 'asp_ai_model_name', true) ?: 'gemini-3.7-flash';

        // 全站共用 API Key:只有管理員看得到這個區塊;已設定管理密碼時預設鎖住,要輸入密碼才解鎖。
        $can_manage_keys     = current_user_can( 'manage_options' );
        $shared_password_set = $this->is_shared_key_password_set();

        ?>
        <style>
            #asp-ai-settings-accordion { margin-top: 20px; border: 1px solid #ccd0d4; background: #fff; }
            .asp-ai-settings-content { display: flex; align-items: center; gap: 15px; padding: 10px 15px; flex-wrap: wrap; }
            .asp-ai-settings-item { display: flex; align-items: center; gap: 8px; }
            .asp-ai-settings-item label { font-weight: bold; margin: 0; white-space: nowrap; }
            .asp-ai-settings-item input, .asp-ai-settings-item select { margin: 0 !important; max-width: 250px; }
            
            /* 壓縮版面留白 */
            #acf-group_anime_shortcuts_ai .inside, #acf-group_anime_shortcuts_ai > .inside, #acf-group_anime_shortcuts_ai .acf-fields { padding: 0 !important; margin: 0 !important; border: none !important; }
            #acf-group_anime_shortcuts_ai .acf-field { padding: 6px 12px !important; margin: 0 !important; }
            #acf-group_anime_shortcuts_ai .acf-label { margin-bottom: 3px !important; }
            

            #asp-ai-settings-accordion { margin: 0 !important; padding: 0 !important; border-top: 1px solid #ddd; border-left: none; border-right: none; border-bottom: none; transform: translateY(-1px); }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // 模型下拉選單：依供應商切換，目前主要用 Gemini(3個型號),OpenAI/Claude 先各給一個預設值,保留後續擴充空間
            var AI_MODEL_OPTIONS = {
                gemini: [
                    { value: 'gemini-3.7-flash', label: 'Gemini 3.7 Flash' },
                    { value: 'gemini-3.6-flash', label: 'Gemini 3.6 Flash' },
                    { value: 'gemini-3.5-flash', label: 'Gemini 3.5 Flash' }
                ],
                openai: [
                    { value: 'gpt-4o', label: 'GPT-4o' }
                ],
                claude: [
                    { value: 'claude-3-5-sonnet-20240620', label: 'Claude 3.5 Sonnet' }
                ]
            };

            function renderModelOptions(provider, preferredModel) {
                var options = AI_MODEL_OPTIONS[provider] || AI_MODEL_OPTIONS.gemini;
                var hasPreferred = options.some(function(o) { return o.value === preferredModel; });
                var selected = hasPreferred ? preferredModel : options[0].value;
                var $select = $('#asp_ai_model_name');
                $select.empty();
                options.forEach(function(o) {
                    $select.append($('<option>', { value: o.value, text: o.label, selected: o.value === selected }));
                });
            }

            function initAIPanel() {
                if ($('#asp-btn-ai-generate').length) return;

                // 1. 注入頂部雙按鈕
                var $header = $('#acf-group_anime_shortcuts_ai .postbox-header');
                if ($header.length === 0) $header = $('#acf-group_anime_shortcuts_ai .hndle');
                
                var $btnGroup = $('<div style="position: absolute; right: 215px; top: 50%; transform: translateY(-50%); z-index: 10; display:flex; gap: 8px; align-items: center;">' +
                    '<button type="button" id="asp-btn-ai-generate" class="button button-secondary" style="height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: flex; align-items: center; box-sizing: border-box;">✨ 執行 AI 輔助生成</button>' +
                    '<button type="button" id="asp-btn-ai-stop" class="button" style="height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: none; align-items: center; box-sizing: border-box; color: #fff; background: #d63638; border-color: #d63638;">⏹️ 停止任務</button>' +
                    '<button type="button" id="asp-btn-ai-save" class="button button-primary" style="height: 28px; border-radius: 4px; font-size: 13px; padding: 0 16px; display: flex; align-items: center; box-sizing: border-box;">💾 儲存 AI 輔助區塊</button>' +
                '</div>');
                
                $header.css('position', 'relative').append($btnGroup);

                // 1.5 注入 Console 狀態監控面板 (放置在所有欄位最上方)
                if ($('#asp-ai-console-box').length === 0) {
                    var consoleHTML = '<div id="asp-ai-console-box" style="background:#1e1e1e; color:#0f0; padding:10px 15px; margin:0 !important; border-bottom:1px solid #444; border-top:none; height:100px; overflow-y:auto; font-family:monospace; line-height:1.4; width: 100%; box-sizing: border-box;">[系統就緒] AI 輔助生成模組已載入，等待指令...<br>請先在最底部「⚙️ AI 帳號設定面板」填入 API Key，再點擊上方按鈕開始生成！</div>' +
                        '<div style="background:#111; padding:4px 15px; border-bottom:1px solid #333; display:flex; align-items:center; gap:8px;">' +
                        '<label style="color:#888; font-size:11px; font-family:monospace; cursor:pointer; display:flex; align-items:center; gap:5px;" title="開啟後，每批次送出前會在 Console 印出完整的 System Prompt 與 User Prompt，方便確認 AI 確實收到正確指令">' +
                        '<input type="checkbox" id="asp-ai-debug-mode" style="margin:0;"> 🔍 Debug 模式（印出完整 AI 指令）' +
                        '</label></div>';
                    
                    var $fieldsContainer = $('#acf-group_anime_shortcuts_ai .acf-fields');
                    if ($fieldsContainer.length > 0) {
                        $fieldsContainer.prepend(consoleHTML);
                        // 強制消除 .acf-fields 自身的 padding（CSS 可能被 WP 預設蓋掉）
                        $fieldsContainer[0].style.setProperty('padding', '0', 'important');
                        $fieldsContainer[0].style.setProperty('margin', '0', 'important');
                        $fieldsContainer[0].style.setProperty('border', 'none', 'important');
                    } else {
                        $('#acf-group_anime_shortcuts_ai .inside').prepend(consoleHTML);
                    }
                }

                // 強制清除第一欄上方 / 最後一欄下方的 padding（用 setTimeout 等 ACF 完全渲染完）
                setTimeout(function() {
                    var $fields = $('#acf-group_anime_shortcuts_ai .acf-fields > .acf-field');
                    if ($fields.length > 0) {
                        // 針對最上方的兩個欄位 (Switch & Synopsis)
                        $fields.filter('[data-name="shortcut_ai_generate_synopsis"], [data-name="shortcut_anime_synopsis_chinese"]').each(function() {
                            this.style.setProperty('padding-top', '0', 'important');
                            this.style.setProperty('margin-top', '0', 'important');
                            $(this).find('.acf-label, .acf-input').each(function() {
                                this.style.setProperty('margin-top', '0', 'important');
                                this.style.setProperty('padding-top', '0', 'important');
                            });
                        });
                        
                        var $synopsisSwitchInput = $fields.filter('[data-name="shortcut_ai_generate_synopsis"]').find('.acf-input');
                        if ($synopsisSwitchInput.length > 0 && $('#asp-force-ai-translate').length === 0) {
                            $synopsisSwitchInput.css({ 'display': 'flex', 'align-items': 'center', 'gap': '10px' });
                            var $forceLabel = $('<label style="font-size:12px; display:flex; align-items:center; cursor:pointer; margin-left:15px;"><input type="checkbox" id="asp-force-ai-translate" style="margin:0 5px 0 0;">強制給AI翻譯</label>');
                            $forceLabel.on('click', function(e) { e.stopPropagation(); });
                            $forceLabel.find('#asp-force-ai-translate').on('click', function(e) { e.stopPropagation(); });
                            $synopsisSwitchInput.find('.acf-switch').after($forceLabel);
                        }
                        
                        // 針對最下方的兩個欄位 (Switch & CAST JSON)
                        $fields.filter('[data-name="shortcut_ai_generate_cast"], [data-name="shortcut_anime_cast_json"]').each(function() {
                            this.style.setProperty('padding-bottom', '0', 'important');
                            this.style.setProperty('margin-bottom', '0', 'important');
                            this.style.setProperty('border-bottom', 'none', 'important');
                            $(this).find('.acf-label, .acf-input, textarea').each(function() {
                                this.style.setProperty('margin-bottom', '0', 'important');
                                this.style.setProperty('padding-bottom', '0', 'important');
                            });
                        });
                    }
                }, 300);

                // 2. 注入底部設定面板 (直接掛載到區塊內容的最下方)
                if ($('#asp-ai-settings-accordion').length === 0) {
                    var settingsHTML = `
                    <details id="asp-ai-settings-accordion">
                        <summary style="padding: 10px 15px; font-weight: bold; background: #f8f9fa; border-bottom: 1px solid #ccd0d4; cursor: pointer; user-select: none; outline: none;">⚙️ AI 帳號設定面板</summary>
                        <div class="asp-ai-settings-content" style="display:flex; align-items:center; gap:20px; padding: 15px; flex-wrap:wrap;">
                            
                            <div style="display:flex; flex-direction:column; gap:5px;">
                                <div style="display:flex; gap: 10px;">
                                    <button type="button" id="asp-btn-ai-settings-save" class="button button-primary" style="height:32px;">💾 儲存 AI 設定</button>
                                </div>
                                <span id="asp-ai-settings-msg" style="color:green; display:none; white-space:nowrap; text-align:center;">已儲存！</span>
                            </div>
                            
                            <?php if ( $can_manage_keys ) : ?>
                            <div class="asp-ai-settings-item" style="display:flex; align-items:center; gap:5px;">
                                <label style="margin:0;">API Key（全站共用）</label>

                                <?php if ( $shared_password_set ) : ?>
                                <span id="asp-shared-key-locked" style="display:flex; align-items:center; gap:5px;">
                                    <span style="color:#b32d2e; font-weight:normal;">🔒 已鎖定</span>
                                    <input type="password" id="asp-shared-key-password" placeholder="請輸入管理密碼" autocomplete="new-password" style="width:150px; height:32px;">
                                    <button type="button" id="asp-btn-shared-key-unlock" class="button" style="height:32px;">🔓 解鎖</button>
                                </span>
                                <?php endif; ?>

                                <span id="asp-shared-key-unlocked" style="<?php echo $shared_password_set ? 'display:none;' : 'display:flex;'; ?> align-items:center; gap:5px;">
                                    <span id="asp-shared-key-state" style="color:green; font-weight:normal;"><?php echo $shared_password_set ? '' : '🔓 可設定'; ?></span>
                                    <button type="button" id="asp-btn-edit-apikey" class="button" style="height:32px;">🔑 編輯共用 Key</button>
                                    <button type="button" id="asp-btn-shared-key-password" class="button" style="height:32px;"><?php echo $shared_password_set ? '🔐 修改密碼' : '🔐 設定管理密碼'; ?></button>
                                </span>

                                <?php if ( ! $shared_password_set ) : ?>
                                <span style="color:#b32d2e; font-weight:normal;">⚠️ 尚未設定管理密碼</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="asp-ai-settings-item" style="display:flex; align-items:center; gap:5px;">
                                <label style="margin:0;">模型名稱</label>
                                <select id="asp_ai_model_name" style="width: 150px; height: 32px; line-height: 1.5;"></select>
                            </div>

                            <div class="asp-ai-settings-item" style="display:flex; align-items:center; gap:5px;">
                                <label style="margin:0;">AI 供應商</label>
                                <select id="asp_ai_provider" style="width: 150px; height: 32px; line-height: 1.5;">
                                    <option value="gemini" ${'<?php echo esc_js( $provider ); ?>'==='gemini'?'selected':''}>Google Gemini</option>
                                    <option value="openai" ${'<?php echo esc_js( $provider ); ?>'==='openai'?'selected':''}>OpenAI (ChatGPT)</option>
                                    <option value="claude" ${'<?php echo esc_js( $provider ); ?>'==='claude'?'selected':''}>Anthropic Claude</option>
                                </select>
                            </div>

                        </div>
                    </details>
                    
                    <?php if ( $can_manage_keys ) : ?>
                    <div id="asp-apikey-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; justify-content:center; align-items:center;">
                        <div style="background:#fff; width:500px; max-width:90%; display:flex; flex-direction:column; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                            <div style="padding:15px 20px; border-bottom:1px solid #e2e4e7; display:flex; justify-content:space-between; align-items:center; background:#f8f9fa; border-radius:8px 8px 0 0;">
                                <h3 style="margin:0; font-size:16px;">🔑 編輯全站共用 API Key (可貼上多把，一行一把)</h3>
                                <button type="button" id="asp-apikey-close" style="background:none; border:none; font-size:20px; cursor:pointer; padding:0; color:#666;">&times;</button>
                            </div>
                            <div style="padding:20px; display:flex; flex-direction:column; gap:10px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <label style="margin:0; font-weight:bold;">Key 池</label>
                                    <select id="asp-apikey-pool" style="height:32px;">
                                        <option value="gemini">Google Gemini</option>
                                        <option value="openai">OpenAI (ChatGPT)</option>
                                        <option value="claude">Anthropic Claude</option>
                                    </select>
                                    <span id="asp-apikey-pool-count" style="color:green; font-weight:normal;"></span>
                                </div>
                                <div style="color:#666; font-size:13px;">此處為<strong>全站共用</strong>設定，所有編輯者都會使用這組 Key。<br>為保障安全，不會顯示已儲存的明文 Key；按下儲存會<strong>整批覆蓋</strong>所選 Key 池。</div>
                                <textarea id="asp_ai_api_key_modal_input" placeholder="請在此貼上金鑰，一行一把..." style="width:100%; height:150px; resize:vertical; font-family:monospace; padding: 10px;"></textarea>
                                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                                    <button type="button" id="asp-apikey-cancel" class="button">取消</button>
                                    <button type="button" id="asp-apikey-confirm" class="button button-primary">確定並儲存</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="asp-keypass-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; justify-content:center; align-items:center;">
                        <div style="background:#fff; width:420px; max-width:90%; display:flex; flex-direction:column; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                            <div style="padding:15px 20px; border-bottom:1px solid #e2e4e7; display:flex; justify-content:space-between; align-items:center; background:#f8f9fa; border-radius:8px 8px 0 0;">
                                <h3 id="asp-keypass-title" style="margin:0; font-size:16px;">🔐 設定管理密碼</h3>
                                <button type="button" id="asp-keypass-close" style="background:none; border:none; font-size:20px; cursor:pointer; padding:0; color:#666;">&times;</button>
                            </div>
                            <div style="padding:20px; display:flex; flex-direction:column; gap:10px;">
                                <div style="color:#666; font-size:13px;">這組密碼用來保護全站共用 API Key。<br>設定後每次重新整理頁面都要重新輸入才能檢視或修改 Key。</div>
                                <input type="password" id="asp-keypass-new" placeholder="新密碼（至少 6 個字元）" autocomplete="new-password" style="padding:8px;">
                                <input type="password" id="asp-keypass-confirm" placeholder="再次輸入新密碼" autocomplete="new-password" style="padding:8px;">
                                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                                    <button type="button" id="asp-keypass-cancel" class="button">取消</button>
                                    <button type="button" id="asp-keypass-save" class="button button-primary">儲存密碼</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>`;
                    $('#acf-group_anime_shortcuts_ai .inside').append(settingsHTML);
                    renderModelOptions('<?php echo esc_js( $provider ); ?>', '<?php echo esc_js( $model ); ?>');
                }

                // 3. 鏡像複製 CAST 提示詞到左側 + 字典管理按鈕
                // 修正 1:字典管理按鈕改為只依賴 $castSwitchField,不再耦合於「找得到提示詞來源 textarea」,
                // 避免提示詞文字被改動導致 $sourceTextarea 找不到時,字典管理按鈕連帶消失。
                var $castSwitchField = $('#acf-group_anime_shortcuts_ai .acf-field[data-name="shortcut_ai_generate_cast"]');
                if ($castSwitchField.length > 0) {
                    var $inputWrap = $castSwitchField.find('.acf-input');

                    // 3a. 鏡像提示詞框(依賴找得到來源 textarea,找不到就略過,不影響下方字典管理按鈕)
                    if ($('#asp-mirrored-prompt').length === 0) {
                        // 尋找包含提示詞的來源 textarea
                        var $sourceTextarea = $('textarea').filter(function() {
                            return $(this).val().indexOf('你是熟悉台灣 ACG') > -1;
                        }).first();

                        if ($sourceTextarea.length > 0) {
                            var mirroredValue = $sourceTextarea.val();
                            var $mirroredBox = $('<div id="asp-mirrored-prompt" style="margin-left: 10px; flex: 1; min-width: 0;">' +
                                '<div style="font-size:9px; font-weight:bold; color:#888; margin-bottom:1px; line-height:1;">📋 提示詞(點擊複製)</div>' +
                                '<textarea readonly title="點擊全選複製" style="width:100%; height:80px; background:#f5f5f5; color:#444; font-size:11px; border:1px solid #ccc; resize:none; padding:2px 4px; box-sizing:border-box; line-height:1.2;" onclick="this.select();"></textarea>' +
                                '</div>');
                            $mirroredBox.find('textarea').val(mirroredValue);

                            $inputWrap.css({
                                'display': 'flex',
                                'align-items': 'flex-start'
                            });
                            $inputWrap.append($mirroredBox);
                        }
                    }

                    // 3b. 注入字典管理按鈕 (在開關正下方) — 與上方 3a 脫鉤,獨立判斷是否已注入過
                    if ($('#asp-btn-manage-dict').length === 0) {
                        var $switch = $inputWrap.find('.acf-true-false').first();
                        if ($switch.length > 0) {
                            $switch.wrap('<div style="display:flex; flex-direction:column; gap:5px;"></div>');
                            $switch.after('<button type="button" id="asp-btn-manage-dict" class="button button-small" style="font-size:11px; padding:2px 8px; line-height:1.2; text-align:center;">📚 字典管理</button>');
                        }
                    }
                }
                
                // 4. 注入字典管理 Modal HTML
                if ($('#asp-dict-modal').length === 0) {
                    var modalHTML = `
                    <div id="asp-dict-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; justify-content:center; align-items:center;">
                        <div style="background:#fff; width:600px; max-width:90%; height:500px; max-height:90vh; display:flex; flex-direction:column; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                            <div style="padding:15px 20px; border-bottom:1px solid #e2e4e7; display:flex; justify-content:space-between; align-items:center; background:#f8f9fa; border-radius:8px 8px 0 0;">
                                <h3 style="margin:0; font-size:16px;">📚 CAST 翻譯字典管理</h3>
                                <button type="button" class="asp-dict-close" style="background:none; border:none; font-size:20px; cursor:pointer; padding:0; color:#666;">&times;</button>
                            </div>
                            <div style="padding:15px 20px; border-bottom:1px solid #e2e4e7; background:#fff; display:flex; gap:10px;">
                                <input type="text" id="asp-dict-search" placeholder="🔍 搜尋日文原文或中文譯名..." style="flex:1; padding:5px 10px;">
                                <button type="button" id="asp-dict-clear-aa" class="button" title="清除原文與譯名完全相同的無效記錄">🧹 清除 A=A</button>
                                <button type="button" id="asp-dict-save" class="button button-primary">💾 儲存修改</button>
                            </div>
                            <div id="asp-dict-list" style="flex:1; overflow-y:auto; padding:15px 20px; background:#f0f0f1; display:flex; flex-direction:column; gap:10px;">
                                <div style="text-align:center; padding:20px; color:#888;">正在載入字典...</div>
                            </div>
                            <!-- 分頁列:資料量大時只渲染目前這一頁,避免一次塞上千列拖慢開啟速度。只有 1 頁時整個藏起來,避免留下空白的 padding/邊框線。 -->
                            <div id="asp-dict-pager" style="display:none; padding:8px 20px; border-top:1px solid #e2e4e7; background:#fff; border-radius:0 0 8px 8px; flex-wrap:wrap; gap:4px; align-items:center; justify-content:center;"></div>
                        </div>
                    </div>`;
                    $('body').append(modalHTML);
                }
            }

            if (typeof acf !== 'undefined') {
                acf.addAction('ready', initAIPanel);
            }
            initAIPanel();

            // AI 供應商切換時,模型下拉選單跟著換成對應清單(重設為該供應商的預設型號)
            $(document).on('change', '#asp_ai_provider', function() {
                renderModelOptions($(this).val(), '');
            });

            // ── 全站共用 API Key（管理員角色 + 管理密碼雙重鎖）────────────────────
            // 解鎖通行證只放在這個變數裡,不寫 Cookie/localStorage,重整頁面即失效
            var sharedKeyToken = '';

            // 各池已儲存把數。鎖定狀態下不預先帶出(避免未解鎖就看到把數),解鎖或儲存後才由後端回填。
            var sharedKeyCounts = <?php echo wp_json_encode( ( $can_manage_keys && ! $shared_password_set ) ? $this->get_shared_key_counts() : (object) [] ); ?>;

            // 在編輯 Modal 的 Key 池下拉選單旁顯示該池目前的把數
            function renderPoolCount() {
                var pool = $('#asp-apikey-pool').val();
                var count = (sharedKeyCounts && typeof sharedKeyCounts[pool] !== 'undefined') ? sharedKeyCounts[pool] : null;
                $('#asp-apikey-pool-count').text(count === null ? '' : '(🔒 目前已儲存 ' + count + ' 把)');
            }

            // 解鎖：驗證管理密碼後才顯示把數與編輯按鈕
            $(document).on('click', '#asp-btn-shared-key-unlock', function(e) {
                e.preventDefault();
                var btn = $(this);
                var password = $('#asp-shared-key-password').val();
                if (!password) { alert('請先輸入管理密碼'); return; }

                btn.prop('disabled', true).text('驗證中...');
                $.post(ajaxurl, {
                    action: 'asp_ai_shared_key_unlock',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    password: password
                }, function(res) {
                    btn.prop('disabled', false).text('🔓 解鎖');
                    if (res.success) {
                        sharedKeyToken = res.data.token || '';
                        sharedKeyCounts = res.data.counts || {};
                        $('#asp-shared-key-password').val('');
                        $('#asp-shared-key-locked').hide();
                        $('#asp-shared-key-state').text('🔓 已解鎖');
                        $('#asp-shared-key-unlocked').css('display', 'flex');
                    } else {
                        alert('解鎖失敗：' + (res.data || ''));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('🔓 解鎖');
                    alert('發生網路錯誤，請重試！');
                });
            });

            // 密碼欄位按 Enter 等同按下解鎖
            $(document).on('keydown', '#asp-shared-key-password', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#asp-btn-shared-key-unlock').click();
                }
            });

            // 編輯共用 Key Modal
            $(document).on('click', '#asp-btn-edit-apikey', function(e) {
                e.preventDefault();
                $('#asp_ai_api_key_modal_input').val(''); // 開啟時保持清空，防窺
                $('#asp-apikey-pool').val($('#asp_ai_provider').val() || 'gemini');
                renderPoolCount();
                $('#asp-apikey-modal').css('display', 'flex');
            });

            // 切換 Key 池時，把數跟著換成該池的數字
            $(document).on('change', '#asp-apikey-pool', renderPoolCount);
            $(document).on('click', '#asp-apikey-close, #asp-apikey-cancel', function(e) {
                e.preventDefault();
                $('#asp-apikey-modal').hide();
            });
            $(document).on('click', '#asp-apikey-confirm', function(e) {
                e.preventDefault();
                var btn = $(this);
                var newKeys = $('#asp_ai_api_key_modal_input').val().trim();
                if (!newKeys) { alert('請先貼上至少一把 Key'); return; }

                btn.prop('disabled', true).text('儲存中...');
                $.post(ajaxurl, {
                    action: 'asp_ai_shared_key_save',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    unlock_token: sharedKeyToken,
                    provider: $('#asp-apikey-pool').val(),
                    keys: newKeys
                }, function(res) {
                    btn.prop('disabled', false).text('確定並儲存');
                    if (res.success) {
                        sharedKeyCounts = res.data.counts || {};
                        renderPoolCount();
                        $('#asp_ai_api_key_modal_input').val('');
                        $('#asp-apikey-modal').hide();
                        alert(res.data.message || '已儲存');
                    } else {
                        alert('儲存失敗：' + (res.data || ''));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('確定並儲存');
                    alert('發生網路錯誤，請重試！');
                });
            });

            // 設定 / 修改管理密碼 Modal
            $(document).on('click', '#asp-btn-shared-key-password', function(e) {
                e.preventDefault();
                $('#asp-keypass-new').val('');
                $('#asp-keypass-confirm').val('');
                $('#asp-keypass-title').text($(this).text().indexOf('修改') > -1 ? '🔐 修改管理密碼' : '🔐 設定管理密碼');
                $('#asp-keypass-modal').css('display', 'flex');
            });
            $(document).on('click', '#asp-keypass-close, #asp-keypass-cancel', function(e) {
                e.preventDefault();
                $('#asp-keypass-modal').hide();
            });
            $(document).on('click', '#asp-keypass-save', function(e) {
                e.preventDefault();
                var btn = $(this);
                var newPass = $('#asp-keypass-new').val();
                var confirmPass = $('#asp-keypass-confirm').val();
                if (newPass.length < 6) { alert('密碼長度至少需要 6 個字元'); return; }
                if (newPass !== confirmPass) { alert('兩次輸入的密碼不一致'); return; }

                btn.prop('disabled', true).text('儲存中...');
                $.post(ajaxurl, {
                    action: 'asp_ai_shared_key_set_password',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    unlock_token: sharedKeyToken,
                    new_password: newPass
                }, function(res) {
                    btn.prop('disabled', false).text('儲存密碼');
                    if (res.success) {
                        // 換發新通行證,讓同一個頁面可以繼續操作
                        sharedKeyToken = res.data.token || '';
                        $('#asp-keypass-new').val('');
                        $('#asp-keypass-confirm').val('');
                        $('#asp-keypass-modal').hide();
                        $('#asp-btn-shared-key-password').text('🔐 修改密碼');
                        alert(res.data.message || '密碼已更新');
                    } else {
                        alert('儲存失敗：' + (res.data || ''));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('儲存密碼');
                    alert('發生網路錯誤，請重試！');
                });
            });

            // 字典管理事件綁定
            var fullDictData = { va: {}, char: {} };
            // 記錄載入字典當下的版本,儲存時回傳後端比對,偵測其他管理員的併發修改
            var dictBaseVersion = '';
            // 分頁狀態:字典筆數多時只渲染目前這一頁,避免一次塞進上千列拖慢開啟速度
            var DICT_PAGE_SIZE = 50;
            var dictCurrentPage = 1;
            // 搜尋 debounce 計時器:停止打字一小段時間才真正過濾,避免字典筆數變大後每個按鍵都重新掃描整份資料
            var dictSearchDebounceTimer = null;

            /*
             * 名稱插進 HTML 前先做跳脫。
             *
             * 字典內容是 AI 生成翻譯後自動寫入的,不保證不含 " < > & 等字元;
             * 未跳脫時,名稱裡的雙引號會把屬性提早關掉,導致該列顯示錯誤、
             * 甚至把錯誤的值存回字典。跳脫後瀏覽器讀回來會自動還原,儲存內容不受影響。
             */
            function escHtml(s) {
                return String(s).replace(/[&<>"']/g, function(c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            // 依目前搜尋條件取出要顯示的項目(聲優 + 角色),搜尋比對規則維持原樣
            function getDictItems(filter) {
                var items = [];
                $.each(fullDictData.va, function(k, v) { items.push({ type: 'va', key: k, val: v, label: '聲優' }); });
                $.each(fullDictData.char, function(k, v) { items.push({ type: 'char', key: k, val: v, label: '角色' }); });

                if (filter) {
                    var lowerFilter = filter.toLowerCase();
                    items = items.filter(i => i.key.toLowerCase().includes(lowerFilter) || i.val.toLowerCase().includes(lowerFilter));
                }

                return items;
            }

            // 渲染分頁列。頁數很多時只列出目前頁前後各 2 頁(頭尾固定顯示),避免頁碼列本身過長。
            function renderDictPager(totalItems) {
                var $pager = $('#asp-dict-pager');
                var totalPages = Math.ceil(totalItems / DICT_PAGE_SIZE);

                if (totalPages <= 1) {
                    // 藏起整個容器(而不只是清空內容),避免留下空白的 padding/邊框線
                    $pager.empty().css('display', 'none');
                    return;
                }

                var makeBtn = function(page, text, disabled, active) {
                    var style = 'min-width:28px; padding:2px 6px; font-size:12px; line-height:1.6;';
                    if (active) style += ' background:#2271b1; color:#fff; border-color:#2271b1; font-weight:bold;';
                    return '<button type="button" class="button button-small asp-dict-page" data-page="' + page + '"' +
                        (disabled ? ' disabled' : '') + ' style="' + style + '">' + text + '</button>';
                };

                var html = [];
                html.push(makeBtn(dictCurrentPage - 1, '‹', dictCurrentPage <= 1, false));

                var pages = [];
                for (var p = 1; p <= totalPages; p++) {
                    if (p === 1 || p === totalPages || Math.abs(p - dictCurrentPage) <= 2) pages.push(p);
                }

                var prevPage = 0;
                $.each(pages, function(i, page) {
                    // 與上一個顯示的頁碼不相鄰時補上省略號
                    if (prevPage && page - prevPage > 1) {
                        html.push('<span style="padding:0 2px; color:#888;">…</span>');
                    }
                    html.push(makeBtn(page, page, false, page === dictCurrentPage));
                    prevPage = page;
                });

                html.push(makeBtn(dictCurrentPage + 1, '›', dictCurrentPage >= totalPages, false));
                html.push('<span style="margin-left:8px; font-size:12px; color:#666;">共 ' + totalItems + ' 筆 / ' + totalPages + ' 頁</span>');

                $pager.html(html.join('')).css('display', 'flex');
            }

            function renderDictList(filter = '') {
                var $list = $('#asp-dict-list');
                var items = getDictItems(filter);

                if (items.length === 0) {
                    $list.html('<div style="text-align:center; padding:20px; color:#888;">' + (filter ? '找不到符合的結果' : '字典目前是空的') + '</div>');
                    $('#asp-dict-pager').empty().css('display', 'none');
                    return;
                }

                // 目前頁碼可能因搜尋或清除 A=A 而超出範圍,先夾回合法區間
                var totalPages = Math.ceil(items.length / DICT_PAGE_SIZE);
                if (dictCurrentPage > totalPages) dictCurrentPage = totalPages;
                if (dictCurrentPage < 1) dictCurrentPage = 1;

                var start = (dictCurrentPage - 1) * DICT_PAGE_SIZE;
                var pageItems = items.slice(start, start + DICT_PAGE_SIZE);

                // 先把整頁 HTML 組好再一次寫入,避免逐筆 append 造成大量重繪
                var html = [];
                $.each(pageItems, function(i, item) {
                    var displayKey = item.type === 'char' ? item.key.replace('|||', ' - ') : item.key;
                    var safeKey = escHtml(displayKey);
                    html.push(`
                    <div style="display:flex; align-items:center; background:#fff; padding:8px 12px; border-radius:4px; border:1px solid #ddd; gap:10px;">
                        <span style="font-size:11px; padding:2px 4px; background:#e0e0e0; border-radius:3px;">${item.label}</span>
                        <div style="flex:1; font-weight:bold; font-size:13px; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${safeKey}">${safeKey}</div>
                        <span style="color:#888;">➔</span>
                        <input type="text" class="asp-dict-input" data-type="${item.type}" data-key="${escHtml(item.key)}" value="${escHtml(item.val)}" style="flex:1; padding:3px 8px; font-size:13px;">
                        <button type="button" class="asp-dict-row-delete" data-type="${item.type}" data-key="${escHtml(item.key)}" title="刪除這筆紀錄" style="padding:0; width:26px; height:26px; background:none; border:none; color:#b32d2e; display:flex; align-items:center; justify-content:center; cursor:pointer;"><span class="dashicons dashicons-trash" style="width:20px; height:20px; font-size:20px;"></span></button>
                    </div>`);
                });

                $list.html(html.join(''));
                $list.scrollTop(0);
                renderDictPager(items.length);
            }

            $(document).on('click', '#asp-dict-clear-aa', function(e) {
                e.preventDefault();
                if (!confirm('警告：此功能會刪除字典中「原文」與「譯名」完全相同的角色紀錄。\n（此功能僅對「角色」有效，聲優紀錄不會受到影響）。\n\n您確定要繼續清除嗎？')) {
                    return;
                }
                
                var clearCount = 0;
                $.each(fullDictData.char, function(k, v) {
                    var oriName = k.indexOf('|||') !== -1 ? k.split('|||')[1] : k;
                    if (oriName === v) {
                        delete fullDictData.char[k];
                        clearCount++;
                    }
                });
                
                alert('已清除 ' + clearCount + ' 筆 A=A 的紀錄！\n請記得點擊右方的「💾 儲存修改」才會正式寫入檔案。');
                renderDictList($('#asp-dict-search').val());
            });

            $(document).on('click', '#asp-btn-manage-dict', function(e) {
                e.preventDefault();
                $('#asp-dict-modal').css('display', 'flex');
                $('#asp-dict-list').html('<div style="text-align:center; padding:20px; color:#888;">正在載入字典...</div>');
                $('#asp-dict-search').val('');
                $('#asp-dict-pager').empty().css('display', 'none');
                dictCurrentPage = 1;
                // 清掉尚未觸發的搜尋 debounce,避免舊的過濾條件在重新開啟後才延遲觸發、蓋掉剛載入的畫面
                clearTimeout(dictSearchDebounceTimer);

                $.post(ajaxurl, {
                    action: 'asp_cast_dict_load',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>'
                }, function(res) {
                    if (res.success) {
                        fullDictData = res.data || { va: {}, char: {} };
                        // 取出版本後移除,避免被當成字典項目渲染或寫回檔案
                        dictBaseVersion = fullDictData._version || '';
                        delete fullDictData._version;
                        renderDictList();
                    } else {
                        $('#asp-dict-list').html('<div style="color:red; text-align:center; padding:20px;">載入失敗：' + res.data + '</div>');
                    }
                });
            });

            $(document).on('click', '.asp-dict-close', function(e) {
                e.preventDefault();
                $('#asp-dict-modal').hide();
            });

            $(document).on('input', '#asp-dict-search', function(e) {
                var val = $(this).val();
                // Debounce:停止打字 180ms 後才真正過濾與渲染,字典筆數變大時避免每個按鍵都全量掃描一次
                clearTimeout(dictSearchDebounceTimer);
                dictSearchDebounceTimer = setTimeout(function() {
                    // 搜尋結果的頁數會變,固定回到第 1 頁,避免停在超出範圍的頁碼
                    dictCurrentPage = 1;
                    renderDictList(val);
                }, 180);
            });

            // 切換頁碼:只重新渲染該頁,不重新向後端要資料
            $(document).on('click', '.asp-dict-page', function(e) {
                e.preventDefault();
                var page = parseInt($(this).attr('data-page'), 10);
                if (isNaN(page) || page === dictCurrentPage) return;
                dictCurrentPage = page;
                renderDictList($('#asp-dict-search').val());
            });

            /*
             * 編輯內容即時寫回 fullDictData。
             *
             * 分頁後,不在目前頁面的列不存在於 DOM,若沿用「儲存時才掃描畫面上的輸入框」,
             * 換頁或搜尋前改過的內容就會漏掉。改成打字當下就同步,無論改了幾頁都不會遺失。
             */
            $(document).on('input', '.asp-dict-input', function() {
                var type = $(this).attr('data-type');
                var key  = $(this).attr('data-key');
                if (fullDictData[type] && fullDictData[type][key] !== undefined) {
                    fullDictData[type][key] = $(this).val().trim();
                }
            });

            // 單筆刪除:跟「清除 A=A」一樣先從記憶體移除、重新渲染,要按「💾 儲存修改」才會真的寫檔
            $(document).on('click', '.asp-dict-row-delete', function(e) {
                e.preventDefault();
                var type = $(this).attr('data-type');
                var key  = $(this).attr('data-key');
                if (fullDictData[type] && fullDictData[type][key] !== undefined) {
                    delete fullDictData[type][key];
                }
                renderDictList($('#asp-dict-search').val());
            });

            $(document).on('click', '#asp-dict-save', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true).text('儲存中...');

                // 修改在輸入當下就已同步進 fullDictData(見 .asp-dict-input 的 input 事件),
                // 這裡直接送出整包資料,不再掃描畫面上的輸入框。
                $.post(ajaxurl, {
                    action: 'asp_cast_dict_save',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    base_version: dictBaseVersion,
                    dict_data: JSON.stringify({ va: fullDictData.va || {}, char: fullDictData.char || {} })
                }, function(res) {
                    $btn.prop('disabled', false).text('💾 儲存修改');

                    if (res.success) {
                        // 更新版本,讓使用者不需重新載入就能連續儲存
                        if (res.data && res.data.version) dictBaseVersion = res.data.version;
                        alert('字典已成功儲存！');
                        return;
                    }

                    // 併發衝突：不自動丟棄使用者目前的編輯,交由使用者決定
                    if (res.data && res.data.type === 'conflict') {
                        if (confirm('⚠️ ' + res.data.message + '\n\n按「確定」重新載入最新字典（你目前尚未儲存的修改將會遺失）。\n按「取消」保留目前畫面，可先自行複製需要保留的內容再處理。')) {
                            $('#asp-btn-manage-dict').trigger('click');
                        }
                        return;
                    }

                    var failMsg = (res.data && res.data.message) ? res.data.message : (res.data || '未知錯誤');
                    alert('儲存失敗：' + failMsg);
                });
            });

            function logAI(msg, isError = false) {
                var $console = $('#asp-ai-console-box');
                var time = new Date().toLocaleTimeString('zh-TW', { hour12: false });
                var color = isError ? '#ff4d4d' : '#00ff00';
                $console.append(`<div style="color:${color}">[${time}] ${msg}</div>`);
                $console.scrollTop($console[0].scrollHeight);
            }

            // 儲存設定
            $(document).on('click', '#asp-btn-ai-settings-save', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('儲存中...');
                var f_synopsis = acf.getField($('.acf-field[data-name="shortcut_ai_generate_synopsis"]'));
                var f_faq      = acf.getField($('.acf-field[data-name="shortcut_ai_generate_faq"]'));
                var f_cast     = acf.getField($('.acf-field[data-name="shortcut_ai_generate_cast"]'));
                
                var pref_synopsis = f_synopsis ? f_synopsis.val() : 1;
                var pref_faq      = f_faq ? f_faq.val() : 1;
                var pref_cast     = f_cast ? f_cast.val() : 1;

                $.post(ajaxurl, {
                    action: 'asp_shortcut_ai_save_user',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    provider: $('#asp_ai_provider').val(),
                    model: $('#asp_ai_model_name').val(),
                    pref_synopsis: pref_synopsis,
                    pref_faq: pref_faq,
                    pref_cast: pref_cast
                }, function(res) {
                    btn.prop('disabled', false).text('💾 儲存 AI 設定');
                    if(res.success) {
                        $('#asp-ai-settings-msg').show().delay(2000).fadeOut();
                    } else {
                        alert('儲存失敗: ' + (res.data || ''));
                    }
                });
            });


            // 儲存文章區塊
            $(document).on('click', '#asp-btn-ai-save', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('⏳ 儲存中...');
                
                var fields = {};
                acf.getFields({parent: $('#acf-group_anime_shortcuts_ai')}).forEach(function(field) {
                    if (field.data.name && field.data.name.startsWith('shortcut_anime_')) {
                        fields[field.data.name] = field.val();
                    }
                });

                $.post(ajaxurl, {
                    action: 'asp_shortcut_ai_save_post',
                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                    post_id: $('#post_ID').val(),
                    fields: fields
                }, function(res) {
                    btn.prop('disabled', false).text('💾 儲存 AI 輔助區塊');
                    if(res.success) {
                        logAI('✅ AI 捷徑區塊已成功儲存至文章！網頁即將重整...');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        logAI('❌ 儲存失敗: ' + (res.data || ''), true);
                    }
                });
            });

            async function processCastTranslation(jsonStr, animeTitle, targetField) {
                var taskName = 'CAST';
                try {
                    if (window.asp_ai_abort) return;
                    var parsed = JSON.parse(jsonStr);
                    if (!Array.isArray(parsed)) throw new Error('Root is not array');
                } catch(e) {
                    logAI(`⚠️ [CAST] 捷徑框內的 JSON 格式無效，無法解析。`, true);
                    return;
                }
                
                // 1. 萃取唯一名單 (雙軌)
                var uniqueVa = {};
                var uniqueChar = {};
                
                // 嘗試獲取系列名稱作為 namespace，讓同系列作品(如劇場版)可共用角色字典
                var f_series = acf.getField($('.acf-field[data-name="shortcut_anime_series_tax"]'));
                var seriesName = f_series && f_series.val() ? String(f_series.val()).trim() : '';
                
                // 如果 ACF 欄位沒抓到 (可能是在原生 metabox 剛輸入還沒存檔)，則直接抓取原生 taxonomy 隱藏欄位
                if (!seriesName) {
                    var $nativeTax = $('#tax-input-anime_series_tax');
                    if ($nativeTax.length) {
                        seriesName = $nativeTax.val().trim();
                    }
                }
                
                var namespace = seriesName ? seriesName.split(',')[0].trim() : animeTitle;
                
                parsed.forEach(item => {
                    var charName = item.name ? String(item.name).trim() : '';
                    if (charName) {
                        var charKey = namespace + '|||' + charName;
                        uniqueChar[charKey] = charName;
                    }
                    if (item.voice_actors && Array.isArray(item.voice_actors)) {
                        item.voice_actors.forEach(va => {
                            var vaName = va.name ? va.name.trim() : '';
                            if (vaName) uniqueVa[vaName] = vaName;
                        });
                    }
                });
                
                var charKeys = Object.keys(uniqueChar);
                var vaKeys = Object.keys(uniqueVa);
                var totalNames = charKeys.length + vaKeys.length;
                
                logAI(`▶️ [CAST] 解析完成。共發現 ${charKeys.length} 個角色，${vaKeys.length} 位聲優 (總計去重後 ${totalNames} 筆)。`);
                if (totalNames === 0) {
                    logAI(`✅ [CAST] 無需翻譯的名稱。`);
                    return;
                }
                
                // 2. 準備分批
                var allItems = [];
                charKeys.forEach(k => allItems.push({ type: 'char', key: k, text: uniqueChar[k] }));
                vaKeys.forEach(k => allItems.push({ type: 'va', key: k, text: uniqueVa[k] }));
                
                var batchSize = 120;
                var globalMapping = { va: {}, char: {} };
                
                var f_ori_name = acf.getField($('.acf-field[data-name="anime_title_native"]'));
                var oriName = f_ori_name ? String(f_ori_name.val()) : '';
                
                var f_source = acf.getField($('.acf-field[data-name="anime_source"]'));
                var sourceVal = f_source && f_source.val() ? f_source.val() : '';
                var source = (typeof sourceVal === 'object' && sourceVal !== null && sourceVal.label) ? sourceVal.label : String(sourceVal);
                
                var f_format = acf.getField($('.acf-field[data-name="anime_format"]'));
                var formatVal = f_format && f_format.val() ? f_format.val() : '';
                var format = (typeof formatVal === 'object' && formatVal !== null && formatVal.label) ? formatVal.label : String(formatVal);
                
                var f_season = acf.getField($('.acf-field[data-name="anime_season"]'));
                var seasonVal = f_season && f_season.val() ? f_season.val() : '';
                var season = (typeof seasonVal === 'object' && seasonVal !== null && seasonVal.label) ? seasonVal.label : String(seasonVal);
                
                var f_year = acf.getField($('.acf-field[data-name="anime_season_year"]'));
                var year = f_year ? String(f_year.val()) : '';
                
                var extraContext = `日文原名：${oriName}／原作來源：${source}／作品類型：${format}／播出季度：${season}／播出年份：${year}`;
                
                logAI(`▶️ [CAST] 擷取的背景特徵：【作品名稱】${animeTitle} 【補充】${extraContext}`);
                logAI(`▶️ [CAST] 開始分批查證 (每批 ${batchSize} 筆)...`);
                
                for (var i = 0; i < allItems.length; i += batchSize) {
                    // 停止任務：每批送出前確認中斷旗標,避免按下停止後仍跑完剩餘批次
                    if (window.asp_ai_abort) {
                        logAI(`🛑 [CAST] 已中止,剩餘批次不再送出。已完成的譯名已寫入字典,重新執行時會直接命中快取。`, true);
                        return;
                    }

                    var batch = allItems.slice(i, i + batchSize);
                    var batchNum = Math.floor(i / batchSize) + 1;
                    var totalBatches = Math.ceil(allItems.length / batchSize);
                    
                    logAI(`▶️ [CAST] 正在處理第 ${batchNum}/${totalBatches} 批 (${batch.length} 筆)...`);
                    
                    var success = false;
                    var retries = 0;
                    var keyRetries = 0;
                    var maxKeys = 0; // 0 = 尚未知道，後端第一次回傳後才會設定
                    var retryAfterError = false; // 5xx 暫時性錯誤：是否要帶著旗標重打同一把 Key
                    var isDebug = $('#asp-ai-debug-mode').is(':checked');
                    
                    // Fix 2: 改用 while(!success && retries <= 2)，消除初始 maxKeys=1 的歧義
                    while (!success && retries <= 2) {
                        // 停止任務：重試與換 Key 的迴圈同樣要能中斷
                        if (window.asp_ai_abort) {
                            logAI(`🛑 [CAST] 已中止,停止本批次的重試。`, true);
                            return;
                        }

                        try {
                            // Debug 模式：印出本批次即將送出的完整名單
                            if (isDebug && retries === 0) {
                                var debugList = batch.map((item, idx) => {
                                    var label = item.type === 'va' ? '【聲優】' : '【角色】';
                                    return (idx + 1) + '. ' + label + ' ' + item.text;
                                }).join('\n');
                                logAI(`🔍 [DEBUG] 批次 ${batchNum} System Prompt:\n----\n你是熟悉台灣 ACG 圈譯名的翻譯校對員...(已儲存在後端，請查看 debug_prompts)\n----`);
                                logAI(`🔍 [DEBUG] 批次 ${batchNum} User Prompt:\n----\n【作品名稱】${animeTitle}\n【補充辨識】${extraContext}\n\n請查證以下名單：\n${debugList}\n----`);
                            }
                            
                            var res = await $.post(ajaxurl, {
                                action: 'asp_shortcut_ai_cast_translate',
                                nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                                title: animeTitle,
                                context: extraContext,
                                items: JSON.stringify(batch),
                                debug: isDebug ? 1 : 0,
                                ai_retry_after_error: retryAfterError ? 1 : 0
                            });
                            retryAfterError = false; // 一次性旗標，送出後立即歸零，避免誤用到下一把新 Key

                            // 停止任務：請求回來後立即確認,避免繼續處理與進入冷卻等待
                            if (window.asp_ai_abort) {
                                logAI(`🛑 [CAST] 已中止,本批次結果不再寫入欄位。`, true);
                                return;
                            }

                            if (res.success && res.data && res.data.mapping) {
                                if (res.data.stats) {
                                    logAI(`▶️ [CAST] 批次 ${batchNum} 完成：快取命中 ${res.data.stats.cached} 筆，實際交由 AI 翻譯 ${res.data.stats.api} 筆。`);
                                }
                                // Debug 模式：印出後端確認收到的完整 prompt（Server Side 視角）
                                if (isDebug && res.data.debug_prompts) {
                                    logAI(`🔍 [DEBUG][後端確認] System Prompt 全文：\n====\n${res.data.debug_prompts.system}\n====`);
                                    logAI(`🔍 [DEBUG][後端確認] User Prompt 全文：\n====\n${res.data.debug_prompts.user}\n====`);
                                }
                                
                                Object.assign(globalMapping.va, res.data.mapping.va || {});
                                Object.assign(globalMapping.char, res.data.mapping.char || {});
                                
                                var missing = 0;
                                batch.forEach(item => {
                                    if (!res.data.mapping[item.type] || !res.data.mapping[item.type][item.key]) {
                                        missing++;
                                    }
                                });
                                
                                if (missing > 0 && retries < 2) {
                                    logAI(`⚠️ [CAST] 批次 ${batchNum} 發現 ${missing} 筆遺漏，發起第 ${retries + 1} 次重試...`, true);
                                    batch = batch.filter(item => !res.data.mapping[item.type] || !res.data.mapping[item.type][item.key]);
                                    retries++;
                                    continue;
                                } else {
                                    if (missing > 0) logAI(`⚠️ [CAST] 批次 ${batchNum} 經重試仍有 ${missing} 筆遺漏，將保留原文。`, true);
                                    success = true;
                                    
                                    // 若這批有實際交由 AI 翻譯，且不是最後一批，強制等待 2 秒以避免觸發 429 頻率限制
                                    if (res.data.stats && res.data.stats.api > 0 && batchNum < totalBatches) {
                                        logAI(`⏳ [CAST] 為保護 API 額度並避免頻率過高，自動冷卻等待 2 秒...`);
                                        await new Promise(r => setTimeout(r, 2000));
                                    }
                                }
                            } else {
                                if (res.data && res.data.type === 'key_failed' && res.data.retry) {
                                    logAI(res.data.message, true);
                                    keyRetries++;
                                    maxKeys = res.data.total_keys;
                                    if (keyRetries >= maxKeys) {
                                        logAI(`❌ 所有設定的 API Key (${maxKeys} 把) 皆已測試失敗。`, true);
                                        throw new Error('All keys failed');
                                    }
                                    continue; // Try again with next key without incrementing `retries`
                                }

                                // 5xx 等暫時性伺服器錯誤：原地等待 3 秒後用同一把 Key 重試一次
                                if (res.data && res.data.retry_same_key) {
                                    logAI(`⏳ [CAST] ${res.data.message}`, true);
                                    await new Promise(function (resolve) { setTimeout(resolve, 3000); });
                                    if (window.asp_ai_abort) {
                                        logAI(`🛑 [CAST] 已中止,停止重試。`, true);
                                        return;
                                    }
                                    logAI(`🔄 [CAST] 正在使用同一把 Key 重試...`);
                                    retryAfterError = true;
                                    continue;
                                }

                                // 非 Key 層級的失敗(內容被安全機制擋下、請求本身有誤)重試也不會變好,直接停止
                                if (res.data && (res.data.type === 'content' || res.data.type === 'request')) {
                                    logAI(`❌ [CAST] ${res.data.message}`, true);
                                    logAI(`ℹ️ [CAST] 已完成批次的譯名已寫入字典,修正後重新執行會直接命中快取。`);
                                    return;
                                }

                                var errMsg = '未知錯誤';
                                if (res.data) {
                                    errMsg = typeof res.data === 'string' ? res.data : (res.data.message || '未知錯誤');
                                }
                                if (errMsg.includes('quota') || errMsg.includes('429')) {
                                    logAI(`❌ [CAST] API 額度耗盡或頻率過高。進度已保存在快取中，請稍候重新點擊生成接續！`, true);
                                    return;
                                }
                                throw new Error(errMsg);
                            }
                        } catch (err) {
                            // Fix 3+5: 同時相容 throw new Error() 與 AJAX 錯誤物件兩種形式
                            var castErrDetail;
                            if (err instanceof Error) {
                                castErrDetail = err.message;
                            } else {
                                castErrDetail = err.responseText ? err.responseText : (err.statusText || '未知錯誤');
                            }
                            if (retries < 2) {
                                retries++;
                                logAI(`⚠️ [CAST] 批次 ${batchNum} 錯誤 (${castErrDetail})，2秒後重試 (${retries}/2)...`, true);
                                await new Promise(r => setTimeout(r, 2000));
                            } else {
                                logAI(`❌ [CAST] 批次 ${batchNum} 多次重試失敗，終止。細節：${castErrDetail}`, true);
                                return;
                            }
                        }
                    }
                }
                
                logAI(`▶️ [CAST] 所有批次完成，正在替換原始 JSON...`);
                
                parsed.forEach(item => {
                    var charName = item.name ? String(item.name).trim() : '';
                    if (charName) {
                        var charKey = namespace + '|||' + charName;
                        if (globalMapping.char && globalMapping.char[charKey]) {
                            item.name = globalMapping.char[charKey];
                        }
                    }
                    if (item.voice_actors && Array.isArray(item.voice_actors)) {
                        item.voice_actors.forEach(va => {
                            var vaName = va.name ? va.name.trim() : '';
                            if (vaName && globalMapping.va && globalMapping.va[vaName]) {
                                va.name = globalMapping.va[vaName];
                            }
                        });
                    }
                });
                
                var finalJson = JSON.stringify(parsed);
                var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                if (f_target) f_target.val(finalJson);
                var nativeField = targetField.replace('shortcut_', '');
                var f_native = acf.getField($('.acf-field[data-name="' + nativeField + '"]'));
                if (f_native) f_native.val(finalJson);
                
                logAI(`✅ [CAST] 替換完成並已寫回欄位！`);
            }

            // 手動終止事件
            $(document).on('click', '#asp-btn-ai-stop', function(e) {
                e.preventDefault();
                window.asp_ai_abort = true;
                logAI('🛑 已接收停止指令，將在目前手邊任務完成後安全退出...');
                $(this).prop('disabled', true).text('⏳ 停止中...');
            });

            // 執行生成
            $(document).on('click', '#asp-btn-ai-generate', async function(e) {
                e.preventDefault();
                var btn = $(this);
                // (注意：盲寫模式下 textarea 可能為空，所以這裡不檢查 empty)
                window.asp_ai_abort = false;
                
                // 檢查哪些開關被打開
                var tasks = [];
                var f_gen_synopsis = acf.getField($('.acf-field[data-name="shortcut_ai_generate_synopsis"]'));
                var f_gen_faq      = acf.getField($('.acf-field[data-name="shortcut_ai_generate_faq"]'));
                var f_gen_cast     = acf.getField($('.acf-field[data-name="shortcut_ai_generate_cast"]'));
                
                if (f_gen_synopsis && f_gen_synopsis.val()) tasks.push('synopsis');
                if (f_gen_faq && f_gen_faq.val()) tasks.push('faq');
                if (f_gen_cast && f_gen_cast.val()) tasks.push('cast');
                
                if(tasks.length === 0) {
                    alert('請至少開啟一個要生成的項目！');
                    return;
                }

                // [優化 2] 每次生成前自動清空 Log 面板
                $('#asp-ai-console-box').empty();

                btn.hide();
                $('#asp-btn-ai-stop').show().prop('disabled', false).text('⏹️ 停止任務');
                logAI('🚀 開始執行 AI 生成工作流...');
                
                try {
                    for(var i=0; i<tasks.length; i++) {
                        if (window.asp_ai_abort) {
                            logAI('🛑 任務已由使用者手動終止！', true);
                            break;
                        }
                        var task = tasks[i];
                        var sysPrompt = '';
                        var userPrompt = '';
                        var targetField = '';
                        var taskName = '';
                        
                        var f_title = acf.getField($('.acf-field[data-name="shortcut_anime_title_chinese"]'));
                        var title = (f_title && f_title.val()) ? String(f_title.val()).trim() : $('#title').val();
                        
                        if (task === 'synopsis') {
                            taskName = '中文簡介';
                            targetField = 'shortcut_anime_synopsis_chinese';
                            var $descTextarea = $('.acf-field[data-name="anime_synopsis_chinese"] .description textarea');
                            sysPrompt = $descTextarea.length ? $descTextarea.val().trim() : $('.acf-field[data-name="anime_synopsis_chinese"] .description').text().trim();
                            var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                            userPrompt = (f_target && f_target.val()) ? String(f_target.val()).trim() : '';
                            var forceTranslate = $('#asp-force-ai-translate').is(':checked');

                            if (userPrompt === '') {
                                userPrompt = '但目前沒有提供原文草稿。請直接上網搜尋該部作品的簡介，並撰寫一份繁體中文版本的簡介。作品名稱：' + title + '\n\n⚠️ 重要規則：請直接輸出純簡介內容，絕對不要加上「以下是...的簡介」或任何開場白與對話詞彙。';
                            } else {
                                var isJP = /[\u3040-\u30ff]/.test(userPrompt);
                                var isSC = /[个们这会发说样么进觉动视频观听剧弹网络传统战击异龙剑门飞机关爱与为从来给让设计创办产认写读记买卖国军队员宝梦灵处总极难尽仅虽迟远贫穷华丽]/.test(userPrompt);
                                var englishCharCount = (userPrompt.match(/[a-zA-Z]/g) || []).length;
                                var isEN = englishCharCount > (userPrompt.length * 0.4);
                                
                                var mainlandTerms = /(視頻|軟件|網絡|質量|激活|屏幕|鼠標|程序|服務器|硬盤|默認|賬號|鏈接|彈幕|B站|UP主|番劇|追番|補番|製作組|製作方|譯製|二創|三創|鬼畜|小夥伴|手辦|病嬌|網盤|高清)/;
                                var hasMainlandTerms = mainlandTerms.test(userPrompt);

                                if (forceTranslate) {
                                    userPrompt = "請將以下原文翻譯並潤飾成「台灣繁體中文」，用語需符合台灣 ACG 圈習慣。請直接輸出結果，不要加上任何額外的對話詞彙或解釋。\n\n原文草稿：\n" + userPrompt;
                                } else if (isJP || isSC || isEN) {
                                    userPrompt = "請將以下原文翻譯並潤飾成「台灣繁體中文」，用語需符合台灣 ACG 圈習慣。請直接輸出結果，不要加上任何額外的對話詞彙或解釋。\n\n原文草稿：\n" + userPrompt;
                                } else if (hasMainlandTerms) {
                                    userPrompt = "以下是一篇繁體中文簡介，但包含大陸用語。請將其轉換並潤飾為「台灣 ACG 圈慣用語」的台灣繁體中文。請直接輸出結果，不要加上任何額外的對話詞彙或解釋。\n\n原文草稿：\n" + userPrompt;
                                } else {
                                    logAI(`✅ [${taskName}] 偵測到純繁體中文且無大陸用語，已自動跳過，不消耗 AI 額度。`);
                                    continue;
                                }
                            }
                        } else if (task === 'faq') {
                            taskName = 'FAQ';
                            targetField = 'shortcut_anime_faq_json';
                            var $descTextarea = $('.acf-field[data-name="anime_faq_json"] .description textarea');
                            sysPrompt = $descTextarea.length ? $descTextarea.val().trim() : $('.acf-field[data-name="anime_faq_json"] .description').text().trim();
                            userPrompt = '請嚴格依照上述規則，直接輸出 JSON 陣列，不要包含任何開場白或解釋。';

                            var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                            var currentFaqVal = (f_target && f_target.val()) ? String(f_target.val()).trim() : '';
                            
                            // 去除字串內的空白與換行，避免遇到 [ \n ] 被誤判為「已有資料」
                            var compactFaq = currentFaqVal.replace(/\s/g, '');
                            
                            if (currentFaqVal !== '' && compactFaq !== '[]') {
                                logAI(`✅ [${taskName}] 偵測到已有 FAQ 資料，已自動跳過，不消耗 AI 額度。`);
                                continue;
                            }
                        } else if (task === 'cast') {
                            taskName = 'CAST';
                            targetField = 'shortcut_anime_cast_json';
                            // 修正 2:移除死碼 sysPrompt 計算(第 1 版遺留,改版後 CAST 已改走 processCastTranslation()
                            // 專用流程,系統提示詞寫死在後端 ajax_shortcut_ai_cast_translate(),前端這裡算了也送不出去)
                            var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                            userPrompt = (f_target && f_target.val()) ? String(f_target.val()).trim() : '';
                            if (userPrompt === '') {
                                logAI(`⚠️ [${taskName}] 捷徑框內沒有提供原始 JSON，已自動跳過，不消耗 AI 額度。`);
                                continue;
                            }
                        }
                        
                        logAI(`▶️ 正在生成 [${taskName}]...`);
                        
                        try {
                            if (task === 'cast') {
                                await processCastTranslation(userPrompt, title, targetField);
                                
                                // 若 CAST 後還有其他任務，也要經過冷卻，以免觸發 429(已中止則不需等待)
                                if (!window.asp_ai_abort && i < tasks.length - 1) {
                                    logAI(`⏳ 避免請求過密，等待冷卻 2 秒後繼續下一個任務...`);
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                }
                                continue;
                            }

                            // Debug 模式：送出前在 Console 印出完整指令
                            var isDebug = $('#asp-ai-debug-mode').is(':checked');
                            if (isDebug) {
                                logAI(`🔍 [DEBUG][${taskName}] System Prompt:\n====\n${sysPrompt}\n====`);
                                logAI(`🔍 [DEBUG][${taskName}] User Prompt:\n====\n${userPrompt}\n====`);
                            }
                            
                            var success = false;
                            var keyRetries = 0;
                            var maxKeys = 0; // 0 = 尚未知道，後端第一次回傳後才會設定
                            var retryAfterError = false; // 5xx 暫時性錯誤：是否要帶著旗標重打同一把 Key
                            var res;

                            // Fix 2: 改用 while(!success) 消除初始 maxKeys=1 的歧義
                            // 實際的終止條件完全由迴圈內的 keyRetries >= maxKeys 控制
                            while (!success) {
                                if (window.asp_ai_abort) return;
                                res = await $.post(ajaxurl, {
                                    action: 'asp_shortcut_ai_generate',
                                    nonce: '<?php echo wp_create_nonce("asp_ai_nonce"); ?>',
                                    system_prompt: sysPrompt,
                                    user_prompt: userPrompt,
                                    debug: isDebug ? 1 : 0,
                                    ai_retry_after_error: retryAfterError ? 1 : 0
                                });
                                retryAfterError = false; // 一次性旗標，送出後立即歸零，避免誤用到下一把新 Key

                                if (window.asp_ai_abort) return; // 使用者中斷

                                if (res.success) {
                                    success = true;
                                } else if (res.data && res.data.type === 'key_failed' && res.data.retry) {
                                    // Key 失敗：更新 maxKeys，印出警告，決定是否繼續
                                    logAI(res.data.message, true);
                                    keyRetries++;
                                    maxKeys = res.data.total_keys;
                                    if (keyRetries >= maxKeys) {
                                        logAI(`❌ 所有設定的 API Key (${maxKeys} 把) 皆已測試失敗。`, true);
                                        throw new Error('All keys failed');
                                    }
                                    // continue: 不需要寫，while 迴圈會自然進入下一圈
                                } else if (res.data && res.data.retry_same_key) {
                                    // 5xx 等暫時性伺服器錯誤：原地等待 3 秒後用同一把 Key 重試一次
                                    logAI(res.data.message, true);
                                    await new Promise(function (resolve) { setTimeout(resolve, 3000); });
                                    if (window.asp_ai_abort) return;
                                    logAI(`🔄 正在使用同一把 Key 重試...`);
                                    retryAfterError = true;
                                    // continue: 不需要寫，while 迴圈會自然進入下一圈
                                } else {
                                    // 一般性錯誤（非 Key 問題），直接拋出終止
                                    var errorMsg = '未知錯誤';
                                    if (res.data) {
                                        errorMsg = typeof res.data === 'string' ? res.data : (res.data.message || '未知錯誤');
                                    }
                                    throw new Error(errorMsg);
                                }
                            }
                            
                            // 顯示警告
                            if (res.data && res.data.warnings && res.data.warnings.length > 0) {
                                res.data.warnings.forEach(w => logAI(w, true));
                            }
                            
                            // [優化 3] 更嚴謹的空字串防呆判斷
                            if (success && res.data && typeof res.data.result === 'string') {
                                var text = res.data.result;
                                // Debug 模式：印出後端確認收到的完整 prompt
                                if (isDebug && res.data.debug_prompts) {
                                    logAI(`🔍 [DEBUG][${taskName}][後端確認] System Prompt：\n====\n${res.data.debug_prompts.system}\n====`);
                                    logAI(`🔍 [DEBUG][${taskName}][後端確認] User Prompt：\n====\n${res.data.debug_prompts.user}\n====`);
                                }
                                
                                // [優化 4] 智慧判斷 AI 是否找不到資料
                                var isFailed = false;
                                var failMsg = '';

                                if (task === 'faq') {
                                    // 去除可能包覆的 markdown 語法
                                    text = text.replace(/```[a-zA-Z]*\n?/g, '').replace(/```/g, '').trim();
                                    var firstBracket = text.indexOf('[');
                                    var lastBracket = text.lastIndexOf(']');
                                    
                                    if (firstBracket === -1 || lastBracket === -1 || lastBracket <= firstBracket) {
                                        isFailed = true;
                                        failMsg = '未能產出有效的 JSON 格式';
                                    } else {
                                        // 擷取陣列字串
                                        text = text.substring(firstBracket, lastBracket + 1);
                                        try {
                                            var parsed = JSON.parse(text);
                                            // 如果成功解析，則轉換為壓縮格式
                                            text = JSON.stringify(parsed);
                                            if (text === '[]') {
                                                isFailed = true;
                                                failMsg = 'AI 回傳了空陣列 (查無資料)';
                                            }
                                        } catch (e) {
                                            // 解析失敗，可能是提取範圍包含了多餘文字 (如來源說明中包含了 ']')
                                            isFailed = true;
                                            failMsg = '未能產出有效的 JSON 格式 (夾雜無法解析的字串)';
                                        }
                                    }
                                } else if (task === 'synopsis') {
                                    if (text.length < 60 && (text.includes('抱歉') || text.includes('找不到') || text.includes('查無') || text.includes('無法') || text.includes('沒有'))) {
                                        isFailed = true;
                                        failMsg = 'AI 回報找不到相關資料';
                                    }
                                }

                                if (isFailed) {
                                    logAI(`⚠️ [${taskName}] ${failMsg}，已自動跳過寫入。(AI 原始回覆: ${res.data.result.replace(/\n/g, ' ').substring(0, 40)}...)`, true);
                                } else {
                                    var f_target = acf.getField($('.acf-field[data-name="' + targetField + '"]'));
                                    if (f_target) {
                                        f_target.val(text);
                                        logAI(`✅ [${taskName}] 生成完畢！(獲得 ${text.length} 字元) 並成功填入捷徑框！`);
                                    } else {
                                        logAI(`⚠️ [${taskName}] 警告：找不到名稱為 ${targetField} 的欄位，無法填入！(獲得 ${text.length} 字元)`, true);
                                        // 嘗試使用舊方法
                                        if (acf.getField(targetField)) acf.getField(targetField).val(text);
                                    }
                                    
                                    // [優化 1] 即時同步更新底層原生欄位，避免 Race Condition
                                    var nativeField = targetField.replace('shortcut_', '');
                                    var f_native = acf.getField($('.acf-field[data-name="' + nativeField + '"]'));
                                    if (f_native) {
                                        f_native.val(text);
                                        logAI(`✅ [${taskName}] 已同步回填至底層原生欄位！`);
                                    }
                                }
                            } else {
                                var errorMsg = '未知錯誤';
                                if (res.data) {
                                    errorMsg = typeof res.data === 'string' ? res.data : (res.data.message || '未知錯誤');
                                }
                                if (typeof errorMsg === 'string' && errorMsg.toLowerCase().includes('quota')) {
                                    errorMsg += ' (💡 提示：這通常代表您的 AI 模型免費額度已耗盡，或請求過於頻繁。請稍後再試，或前往 Google AI Studio 檢查/更換 API Key 方案。)';
                                }
                                logAI(`❌ [${taskName}] 發生錯誤: ` + errorMsg, true);
                            }
                        } catch(err) {
                            // Fix 3+5: 同時相容 throw new Error() 與 AJAX 錯誤物件兩種形式
                            var errDetail;
                            if (err instanceof Error) {
                                errDetail = err.message; // throw new Error('...') 的情況
                            } else {
                                errDetail = err.responseText ? err.responseText : (err.statusText || '未知錯誤');
                            }
                            if (typeof errDetail === 'string' && errDetail.toLowerCase().includes('quota')) {
                                errDetail += ' (💡 提示：這通常代表您的 AI 模型免費額度已耗盡，或請求過於頻繁。請稍後再試，或前往 Google AI Studio 檢查/更換 API Key 方案。)';
                            }
                            logAI(`❌ [${taskName}] 發生錯誤：${errDetail}`, true);
                        }

                        // [新增] 冷卻時間機制：如果不是最後一項任務，則等待 1 秒避免觸發 Google API Rate Limit
                        if (i < tasks.length - 1) {
                            logAI(`⏳ 避免請求過密，等待冷卻 1 秒後繼續下一個任務...`);
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                    }
                    
                    // CAST 是最後一個任務,中止後仍會走到這裡,需依旗標分別回報,避免誤報「已完成」
                    if (window.asp_ai_abort) {
                        logAI('🛑 任務已由使用者手動終止,未完成的項目沒有寫入。', true);
                    } else {
                        logAI('🎉 所有選定項目生成工作流已結束！確認無誤後記得按右邊的儲存喔！');
                    }
                } finally {
                    // 不論任何情況（正常完成、提早 return、未預期例外）都確保按鈕被解鎖
                    btn.prop('disabled', false).text('✨ 執行 AI 輔助生成').show();
                    $('#asp-btn-ai-stop').hide();
                }
            });
        });
        </script>
        <?php
    }

    public function ajax_shortcut_ai_save_user(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error('權限不足');
        
        $user_id = get_current_user_id();
        update_user_meta( $user_id, 'asp_ai_provider', sanitize_text_field($_POST['provider']) );

        // API Key 已改為全站共用,由 ajax_ai_shared_key_save() 另行處理(需管理員權限 + 管理密碼),
        // 這裡只保留每位使用者各自的供應商、模型與生成偏好。
        update_user_meta( $user_id, 'asp_ai_model_name', sanitize_text_field($_POST['model']) );
        
        if ( isset($_POST['pref_synopsis']) ) update_user_meta( $user_id, 'asp_ai_pref_shortcut_ai_generate_synopsis', intval($_POST['pref_synopsis']) );
        if ( isset($_POST['pref_faq']) ) update_user_meta( $user_id, 'asp_ai_pref_shortcut_ai_generate_faq', intval($_POST['pref_faq']) );
        if ( isset($_POST['pref_cast']) ) update_user_meta( $user_id, 'asp_ai_pref_shortcut_ai_generate_cast', intval($_POST['pref_cast']) );
        
        wp_send_json_success();
    }

    public function ajax_shortcut_ai_save_post(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( '權限不足' );

        $fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash($_POST['fields']) : [];
        $mapping = [
            'shortcut_anime_synopsis_chinese' => 'anime_synopsis_chinese',
            'shortcut_anime_faq_json'         => 'anime_faq_json',
            'shortcut_anime_cast_json'        => 'anime_cast_json',
        ];

        foreach ( $mapping as $shortcut => $real_key ) {
            if ( isset( $fields[$shortcut] ) ) {
                update_post_meta( $post_id, $real_key, $fields[$shortcut] );
                update_post_meta( $post_id, '_' . $real_key, 'field_' . $real_key );
            }
        }
        wp_send_json_success();
    }

    public function ajax_shortcut_ai_generate(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );

        $user_id = get_current_user_id();
        $provider = get_user_meta( $user_id, 'asp_ai_provider', true ) ?: 'gemini';
        $model    = get_user_meta( $user_id, 'asp_ai_model_name', true );

        // Key 清單為空(含內容只有空白)時會直接回錯,避免後續 % 0 造成 Fatal
        // 改用全站共用 Key,依供應商分池取用
        $key_set     = $this->get_api_key_set( $provider );
        $current_key = $key_set['current'];

        $debug = ! empty( $_POST['debug'] ) && intval( $_POST['debug'] ) === 1;
        // 前端在 3 秒重試倒數後,會帶著此旗標重打同一把 Key,見 send_api_failure()
        $is_retry = ! empty( $_POST['ai_retry_after_error'] );

        if ( $is_retry ) {
            error_log( "ASP AI Retry: 收到 3 秒重試請求 (provider={$provider}, 第 " . ( $key_set['index'] + 1 ) . " 把 Key, endpoint=ajax_shortcut_ai_generate)" );
        }

        $system_prompt = isset( $_POST['system_prompt'] ) ? wp_unslash( $_POST['system_prompt'] ) : '';
        $user_prompt   = isset( $_POST['user_prompt'] ) ? wp_unslash( $_POST['user_prompt'] ) : '';

        $result_text = '';

        if ( $provider === 'openai' ) {
            if ( empty( $model ) ) $model = 'gpt-4o';
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'timeout' => 45,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $current_key,
                ],
                'body' => wp_json_encode( [
                    'model'    => $model,
                    'messages' => [
                        [ 'role' => 'system', 'content' => $system_prompt ],
                        [ 'role' => 'user', 'content' => $user_prompt ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) {
                // 網路層失敗(逾時、連線中斷等):多半是暫時性問題,比照 5xx 先原地等待 3 秒用同一把 Key 重試一次,
                // 重試後仍失敗才換下一把 Key。
                $this->send_api_failure(
                    [ 'type' => 'request', 'message' => '網路連線逾時或失敗: ' . $response->get_error_message(), 'retryable' => true ],
                    $key_set,
                    $is_retry
                );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code === 200 && isset( $body['choices'][0]['message']['content'] ) ) {
                $result_text = $body['choices'][0]['message']['content'];
                $this->advance_key_cursor( $key_set );
            } else {
                // 依失敗類型決定要不要換 Key,避免請求層級錯誤或安全阻擋也白白輪完所有 Key
                $this->send_api_failure(
                    $this->classify_api_failure( $code, $body, 'openai' ),
                    $key_set,
                    $is_retry
                );
            }
        } elseif ( $provider === 'claude' ) {
            if ( empty( $model ) ) $model = 'claude-3-5-sonnet-20240620';
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'timeout' => 45,
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $current_key,
                    'anthropic-version' => '2023-06-01',
                ],
                'body' => wp_json_encode( [
                    'model'      => $model,
                    'max_tokens' => 8192,
                    'system'     => $system_prompt,
                    'messages'   => [
                        [ 'role' => 'user', 'content' => $user_prompt ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) {
                // 網路層失敗(逾時、連線中斷等):多半是暫時性問題,比照 5xx 先原地等待 3 秒用同一把 Key 重試一次,
                // 重試後仍失敗才換下一把 Key。
                $this->send_api_failure(
                    [ 'type' => 'request', 'message' => '網路連線逾時或失敗: ' . $response->get_error_message(), 'retryable' => true ],
                    $key_set,
                    $is_retry
                );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code === 200 && isset( $body['content'][0]['text'] ) ) {
                $result_text = $body['content'][0]['text'];
                $this->advance_key_cursor( $key_set );
            } else {
                // 依失敗類型決定要不要換 Key,避免請求層級錯誤或安全阻擋也白白輪完所有 Key
                $this->send_api_failure(
                    $this->classify_api_failure( $code, $body, 'claude' ),
                    $key_set,
                    $is_retry
                );
            }
        } else {
            // 預設 Gemini
            if ( empty( $model ) ) $model = 'gemini-3.7-flash';
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$current_key}";
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [ [ 'text' => $user_prompt ] ]
                    ]
                ]
            ];
            if ( ! empty( $system_prompt ) ) {
                $payload['system_instruction'] = [
                    'parts' => [ [ 'text' => $system_prompt ] ]
                ];
            }
            $response = wp_remote_post( $url, [
                'timeout' => 45,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
            ] );
            if ( is_wp_error( $response ) ) {
                // 網路層失敗(逾時、連線中斷等):多半是暫時性問題,比照 5xx 先原地等待 3 秒用同一把 Key 重試一次,
                // 重試後仍失敗才換下一把 Key。
                $this->send_api_failure(
                    [ 'type' => 'request', 'message' => '網路連線逾時或失敗: ' . $response->get_error_message(), 'retryable' => true ],
                    $key_set,
                    $is_retry
                );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code === 200 && isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
                $result_text = $body['candidates'][0]['content']['parts'][0]['text'];
                $this->advance_key_cursor( $key_set );
            } else {
                // 依失敗類型決定要不要換 Key。Gemini 因題材敏感回 200 卻無內容時,
                // 這裡會回 content 類型並帶出 finishReason,不再誤報成「Key 失敗: HTTP 200」
                $this->send_api_failure(
                    $this->classify_api_failure( $code, $body, 'gemini' ),
                    $key_set,
                    $is_retry
                );
            }
        }

        $response_data = [ 'result' => $result_text ];
        if ( $debug ) {
            $response_data['debug_prompts'] = [
                'system' => $system_prompt,
                'user'   => $user_prompt
            ];
        }
        wp_send_json_success( $response_data );
    }

    // =========================================================================
    // CAST 字典管理與翻譯 Backend
    // =========================================================================

    private function get_cast_dict_path(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/asp_cast_cache.json';
    }

    /**
     * 字典備份檔路徑(保留寫入前的上一版)。
     *
     * @return string
     */
    private function get_cast_dict_backup_path(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/asp_cast_cache.bak.json';
    }

    /**
     * 取得字典目前的版本識別碼(以檔案內容 hash 為準)。
     *
     * 供多管理員同時編輯時偵測衝突:載入時記下版本,儲存時比對,
     * 不一致即代表期間已被他人寫入,必須擋下,避免整份覆蓋掉對方的修改。
     *
     * @return string 檔案不存在時回傳空字串。
     */
    private function get_cast_dict_version(): string {
        $file = $this->get_cast_dict_path();

        if ( ! file_exists( $file ) ) {
            return '';
        }

        $hash = md5_file( $file );

        return ( false === $hash ) ? '' : $hash;
    }

    /**
     * 寫入前備份現有字典,讓誤覆蓋或誤清除(🧹 清除 A=A)有機會還原。
     *
     * 備份失敗只記錄 Log 不中斷寫入,避免因備份問題導致正常的字典更新失效。
     *
     * @return void
     */
    private function backup_cast_dict(): void {
        $file = $this->get_cast_dict_path();

        if ( ! file_exists( $file ) ) {
            return;
        }

        if ( ! copy( $file, $this->get_cast_dict_backup_path() ) ) {
            error_log( 'asp_cast_cache.json 備份失敗,仍繼續寫入。' );
        }
    }

    /**
     * 讀取字典內容。
     *
     * @param string|null $version 傳入變數時,一併帶出本次讀到的內容版本(md5)。
     *                             用途是省下再呼叫一次 get_cast_dict_version() 重讀整份檔案的成本,
     *                             結果與 md5_file() 完全相同(同樣是對整份檔案位元組取 md5)。
     *                             檔案不存在或讀取失敗時為空字串,與 get_cast_dict_version() 行為一致。
     * @return array
     */
    private function get_cast_dict( ?string &$version = null ): array {
        $version = '';
        $file = $this->get_cast_dict_path();
        if ( ! file_exists( $file ) ) {
            return [ 'va' => [], 'char' => [] ];
        }
        $json = file_get_contents( $file );
        if ( false === $json ) {
            return [ 'va' => [], 'char' => [] ];
        }
        // 直接用已讀進記憶體的內容計算版本,不必再開檔讀第二次
        $version = md5( $json );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return [ 'va' => [], 'char' => [] ];
        }
        return $data;
    }

    private function update_cast_dict( array $new_data, bool $merge = true ): void {
        $file = $this->get_cast_dict_path();
        if ( $merge ) {
            $existing = $this->get_cast_dict();
            $existing['va']   = array_merge( $existing['va'] ?? [], $new_data['va'] ?? [] );
            $existing['char'] = array_merge( $existing['char'] ?? [], $new_data['char'] ?? [] );
            $data_to_save = $existing;
        } else {
            $data_to_save = [
                'va'   => $new_data['va'] ?? [],
                'char' => $new_data['char'] ?? []
            ];
        }
        $json = wp_json_encode( $data_to_save, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        if ( $json === false ) {
            error_log( 'asp_cast_cache.json encode failed: ' . json_last_error_msg() );
            return;
        }
        /*
         * 僅「整份覆蓋」時備份。
         *
         * merge 模式(CAST 生成)只會新增不會刪除,備份價值低;
         * 若連 merge 也備份,例行的 CAST 生成會很快把備份沖成「誤操作後的狀態」,
         * 反而讓備份失去意義。因此只保留手動儲存/清除 A=A 之前的版本。
         */
        if ( ! $merge ) {
            $this->backup_cast_dict();
        }
        file_put_contents( $file, $json, LOCK_EX );
        error_log( 'asp_cast_cache.json updated. va: ' . count($data_to_save['va']) . ', char: ' . count($data_to_save['char']) );
    }

    public function ajax_cast_dict_load(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );

        // 讀檔的同時取回版本,避免為了算版本再整份重讀一次檔案(字典越大差異越明顯)。
        $version = '';
        $dict = $this->get_cast_dict( $version );
        // 一併回傳載入當下的版本,前端記住後於儲存時比對(併發保護)。
        $dict['_version'] = $version;

        wp_send_json_success( $dict );
    }

    public function ajax_cast_dict_save(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );

        $json = isset( $_POST['dict_data'] ) ? wp_unslash( $_POST['dict_data'] ) : '';
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) wp_send_json_error( '格式錯誤' );

        /*
         * 多管理員併發保護。
         *
         * 本端點是「整份覆蓋」,若 A 開啟字典後 B 也開啟並先儲存,
         * A 再儲存就會用自己的舊快照蓋掉 B 的修改且雙方都不會察覺。
         * 因此比對前端載入當下的版本,不一致就擋下並要求重新載入。
         */
        $base_version    = isset( $_POST['base_version'] ) ? sanitize_text_field( wp_unslash( $_POST['base_version'] ) ) : '';
        $current_version = $this->get_cast_dict_version();

        if ( $base_version !== $current_version ) {
            wp_send_json_error( [
                'type'    => 'conflict',
                'message' => '字典在你編輯期間已被其他人修改,為避免覆蓋掉對方的修改,本次儲存已被擋下。',
            ] );
        }

        $this->update_cast_dict( $data, false ); // 完全覆蓋

        // 回傳寫入後的新版本,讓前端可以連續儲存而不需重新載入。
        wp_send_json_success( [ 'version' => $this->get_cast_dict_version() ] );
    }

    public function ajax_shortcut_ai_cast_translate(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( '權限不足' );

        $user_id = get_current_user_id();
        $provider = get_user_meta( $user_id, 'asp_ai_provider', true ) ?: 'gemini';
        $model    = get_user_meta( $user_id, 'asp_ai_model_name', true );

        // 沿用原本的提早防呆:共用池沒有任何 Key 就先擋下,不必等到後面才失敗
        if ( empty( $this->get_shared_pool_keys( $provider ) ) ) wp_send_json_error( '未設定 API Key' );

        $debug = ! empty( $_POST['debug'] ) && intval( $_POST['debug'] ) === 1;
        // 前端在 3 秒重試倒數後,會帶著此旗標重打同一把 Key,見 send_api_failure()
        $is_retry = ! empty( $_POST['ai_retry_after_error'] );

        if ( $is_retry ) {
            error_log( "ASP AI Retry: 收到 3 秒重試請求 (provider={$provider}, endpoint=ajax_shortcut_ai_cast_translate)" );
        }

        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';
        $items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
        $items = json_decode( $items_json, true );

        if ( empty( $items ) || ! is_array( $items ) ) wp_send_json_error( '無效的查證清單' );

        // 1. 雙軌快取過濾：先檢查快取中是否已經有答案
        $global_dict = $this->get_cast_dict();
        $unknown_items = [];
        $known_mapping = [ 'va' => [], 'char' => [] ];

        foreach ( $items as $item ) {
            // 防禦性驗證：確保必要欄位都存在且型別正確
            if ( empty( $item['type'] ) || empty( $item['key'] ) || ! isset( $item['text'] ) ) {
                continue;
            }
            $type = $item['type']; // 'va' or 'char'
            $key  = $item['key'];
            $text = $item['text'];
            
            if ( isset( $global_dict[$type][$key] ) && $global_dict[$type][$key] !== '' ) {
                $known_mapping[$type][$key] = $global_dict[$type][$key];
            } else {
                // 檢查是否「已經是中文譯名」？
                // 如果目前傳進來的 $text 已經存在於字典的「譯名(Value)」當中
                // 我們可以直接認定它已經翻譯過了，快取命中，譯名就是它自己。
                $is_already_translated = false;
                
                if ( $type === 'char' ) {
                    // 角色限制在同一個 namespace 下尋找
                    $parts = explode('|||', $key);
                    if ( count($parts) === 2 ) {
                        $namespace = $parts[0];
                        foreach ( $global_dict['char'] as $dict_key => $dict_val ) {
                            if ( str_starts_with( $dict_key, $namespace . '|||' ) ) {
                                if ( $dict_val === $text ) {
                                    $is_already_translated = true;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    // 聲優直接在所有聲優的譯名中尋找
                    if ( in_array( $text, $global_dict['va'], true ) ) {
                        $is_already_translated = true;
                    }
                }

                if ( $is_already_translated ) {
                    $known_mapping[$type][$key] = $text; // 譯名就是自己
                } else {
                    $unknown_items[] = $item;
                }
            }
        }

        // 如果全部都在快取裡了，直接回傳
        if ( empty( $unknown_items ) ) {
            $response_data = [ 
                'mapping' => $known_mapping,
                'stats'   => [
                    'total'  => count($items),
                    'cached' => count($items),
                    'api'    => 0
                ]
            ];
            if ( $debug ) {
                $response_data['debug_prompts'] = [
                    'system' => '(全部快取命中，本批次未送出 AI 請求)',
                    'user'   => '(全部快取命中，本批次未送出 AI 請求)'
                ];
            }
            wp_send_json_success( $response_data );
            return;
        }

        // 2. 組裝要餵給 AI 的資料
        $prompt_list = [];
        foreach ( $unknown_items as $idx => $item ) {
            $label = $item['type'] === 'va' ? '【聲優】' : '【角色】';
            $text = $item['text']; // 原文
            $prompt_list[] = ($idx + 1) . ". {$label} {$text}";
        }
        $prompt_text = implode( "\n", $prompt_list );

        $system_prompt = "你是熟悉台灣 ACG 圈譯名的翻譯校對員。請把名單的「角色名」與「聲優名」改成台灣慣用中文譯名。\n\n"
                       . "【最重要的前提】\n"
                       . "你必須「實際上網開啟網頁查證」，不可僅憑記憶或推測。新番你的記憶很可能沒有或過時。\n"
                       . "【查證來源優先順序】\n"
                       . "① 台灣代理商/平台官方(木棉花 Muse、曼迪、羚邦、Netflix、巴哈姆特動畫瘋)的官網或官方社群(FB/IG/X)——有台灣官方代理版本時,以其角色譯名為最高依據\n"
                       . "② 中文維基百科台灣版(zh-hant)\n"
                       . "③ 日文官網、日文維基(確認原文對應,避免張冠李戴)\n"
                       . "④ 萌娘百科/百度(僅輔助確認角色存在與原文對應,為大陸譯名,不可直接採用)\n\n"
                       . "若查無台灣代理官方譯名(常見於老作品、冷門番、未代理作品)：依②③來源查證後，採用台灣 ACG 圈普遍使用之慣用譯名(而非直接照搬大陸慣用譯名)。\n"
                       . "若同一角色/聲優查到多個不同譯名版本：以來源優先順序最高者為準，直接採用，不需列出其他版本。\n"
                       . "若查證後確定無對應中文譯名，請原樣保留或略過該筆。\n\n"
                       . "請嚴格以 JSON 陣列格式回傳，格式範例：\n"
                       . "[\n"
                       . "  {\"type\": \"char\", \"text\": \"原文角色名\", \"translated\": \"繁體中文譯名\"},\n"
                       . "  {\"type\": \"va\", \"text\": \"原文聲優名\", \"translated\": \"繁體漢字寫法\"}\n"
                       . "]\n"
                       . "注意：因為系統採用全自動 API 批次映射，請【絕對不要】包含任何額外的解釋文字、核對清單或 markdown 標籤，只能輸出純 JSON 陣列。";

        $user_prompt = "【作品名稱】{$title}\n【補充辨識】{$context}\n\n請查證以下名單：\n" . $prompt_text;

        // 3. 發送 API 請求
        $result_text = '';

        // 同上:集中處理 Key 拆解與游標防呆(全站共用 Key,依供應商分池)
        $key_set     = $this->get_api_key_set( $provider );
        $current_key = $key_set['current'];

        if ( $provider === 'openai' ) {
            if ( empty( $model ) ) $model = 'gpt-4o';
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'timeout' => 45,
                'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $current_key ],
                'body' => wp_json_encode( [
                    'model' => $model,
                    'response_format' => [ 'type' => 'json_object' ],
                    'messages' => [
                        [ 'role' => 'system', 'content' => $system_prompt . "\n(由於 OpenAI JSON 模式限制，請將陣列包裝在 {\"result\": [...]} 中)" ],
                        [ 'role' => 'user', 'content' => $user_prompt ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $response ) ) {
                // 網路層失敗(逾時、連線中斷等):多半是暫時性問題,比照 5xx 先原地等待 3 秒用同一把 Key 重試一次,
                // 重試後仍失敗才換下一把 Key。
                $this->send_api_failure(
                    [ 'type' => 'request', 'message' => '網路連線逾時或失敗: ' . $response->get_error_message(), 'retryable' => true ],
                    $key_set,
                    $is_retry
                );
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code === 200 && isset( $body['choices'][0]['message']['content'] ) ) {
                $result_text = $body['choices'][0]['message']['content'];
                $parsed = json_decode( $result_text, true );
                if ( isset($parsed['result']) ) { $result_text = wp_json_encode( $parsed['result'], JSON_UNESCAPED_UNICODE ); }
                $this->advance_key_cursor( $key_set );
            } else {
                // 依失敗類型決定要不要換 Key,避免請求層級錯誤或安全阻擋也白白輪完所有 Key
                $this->send_api_failure(
                    $this->classify_api_failure( $code, $body, 'openai' ),
                    $key_set,
                    $is_retry
                );
            }

        } elseif ( $provider === 'claude' ) {
            if ( empty( $model ) ) $model = 'claude-3-5-sonnet-20240620';
                $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                    'timeout' => 45,
                    'headers' => [ 'Content-Type' => 'application/json', 'x-api-key' => $current_key, 'anthropic-version' => '2023-06-01' ],
                    'body' => wp_json_encode( [
                        'model' => $model,
                        'max_tokens' => 8192,
                        'system' => $system_prompt,
                        'messages' => [ [ 'role' => 'user', 'content' => $user_prompt ] ],
                    ] ),
                ] );
                if ( is_wp_error( $response ) ) {
                    // 網路層失敗(逾時、連線中斷等):多半是暫時性問題,比照 5xx 先原地等待 3 秒用同一把 Key 重試一次,
                    // 重試後仍失敗才換下一把 Key。
                    $this->send_api_failure(
                        [ 'type' => 'request', 'message' => '網路連線逾時或失敗: ' . $response->get_error_message(), 'retryable' => true ],
                        $key_set,
                        $is_retry
                    );
                }
                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( $code === 200 && isset( $body['content'][0]['text'] ) ) {
                    $result_text = $body['content'][0]['text'];
                    $this->advance_key_cursor( $key_set );
                } else {
                    // 依失敗類型決定要不要換 Key。CAST 每批 150 筆容易超出 max_tokens,
                    // 分類後會明確回報「被長度上限截斷」而非誤報成 Key 失效
                    $this->send_api_failure(
                        $this->classify_api_failure( $code, $body, 'claude' ),
                        $key_set,
                        $is_retry
                    );
                }

        } else {
            if ( empty( $model ) ) $model = 'gemini-3.7-flash';
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$current_key}";
                $payload = [
                    'contents' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $user_prompt ] ] ] ],
                    'system_instruction' => [ 'parts' => [ [ 'text' => $system_prompt ] ] ]
                ];
                $response = wp_remote_post( $url, [
                    'timeout' => 45,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( $payload ),
                ] );
                if ( is_wp_error( $response ) ) {
                    // 網路層失敗(逾時、連線中斷等):多半是暫時性問題,比照 5xx 先原地等待 3 秒用同一把 Key 重試一次,
                    // 重試後仍失敗才換下一把 Key。
                    $this->send_api_failure(
                        [ 'type' => 'request', 'message' => '網路連線逾時或失敗: ' . $response->get_error_message(), 'retryable' => true ],
                        $key_set,
                        $is_retry
                    );
                }
                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( $code === 200 && isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
                    $result_text = $body['candidates'][0]['content']['parts'][0]['text'];
                    $this->advance_key_cursor( $key_set );
                } else {
                    // 依失敗類型決定要不要換 Key。Gemini 因題材敏感回 200 卻無內容時,
                    // 這裡會回 content 類型並帶出 finishReason,不再誤報成「Key 失敗: HTTP 200」
                    $this->send_api_failure(
                        $this->classify_api_failure( $code, $body, 'gemini' ),
                        $key_set,
                        $is_retry
                    );
                }
            }

        // 4. 解析 AI 回傳的 JSON
        $result_text = trim( preg_replace('/```json|```/i', '', $result_text) );
        $ai_parsed = json_decode( $result_text, true );

        if ( ! is_array( $ai_parsed ) ) {
            wp_send_json_error( 'AI 未能回傳有效的 JSON 陣列' );
        }

        // 5. 組合新字典並寫入快取
        $new_dict = [ 'va' => [], 'char' => [] ];
        
        $text_to_item = [];
        foreach ( $unknown_items as $uitem ) {
            $text_to_item[ $uitem['type'] . '_' . $uitem['text'] ] = $uitem['key'];
        }

        foreach ( $ai_parsed as $res_item ) {
            if ( isset( $res_item['type'], $res_item['text'], $res_item['translated'] ) ) {
                $type  = $res_item['type'];
                $text  = $res_item['text'];
                $trans = trim( $res_item['translated'] );
                
                $lookup_key = $type . '_' . $text;
                if ( isset( $text_to_item[ $lookup_key ] ) && $trans !== '' ) {
                    $original_key = $text_to_item[ $lookup_key ];
                    $new_dict[$type][$original_key] = $trans;
                    $known_mapping[$type][$original_key] = $trans;
                }
            }
        }

        if ( ! empty( $new_dict['va'] ) || ! empty( $new_dict['char'] ) ) {
            $this->update_cast_dict( $new_dict, true ); // Merge
        }

        $response_data = [ 
            'mapping' => $known_mapping,
            'stats'   => [
                'total'  => count($items),
                'cached' => count($items) - count($unknown_items),
                'api'    => count($unknown_items)
            ]
        ];
        if ( $debug ) {
            $response_data['debug_prompts'] = [
                'system' => $system_prompt,
                'user'   => $user_prompt
            ];
        }
        wp_send_json_success( $response_data );
    }


    /**
     * 支援的 AI 供應商(同時也是 Key 池的分池依據)。
     *
     * @return array<int,string>
     */
    private function get_supported_providers(): array {
        return [ 'gemini', 'openai', 'claude' ];
    }

    /**
     * 把外部傳入的供應商字串收斂到支援清單,未知值一律當成 gemini(與既有預設一致)。
     */
    private function normalize_provider( string $provider ): string {
        return in_array( $provider, $this->get_supported_providers(), true ) ? $provider : 'gemini';
    }

    /**
     * 供應商顯示名稱(給訊息與面板使用,與前端下拉選單文字一致)。
     */
    private function get_provider_label( string $provider ): string {
        $labels = [
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI (ChatGPT)',
            'claude' => 'Anthropic Claude',
        ];
        return $labels[ $provider ] ?? $provider;
    }

    /**
     * 取得指定供應商的全站共用 Key 清單(已去除空白行)。
     *
     * @return array<int,string>
     */
    private function get_shared_pool_keys( string $provider ): array {
        $stored = get_option( self::SHARED_KEYS_OPTION, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $provider = $this->normalize_provider( $provider );
        $raw      = isset( $stored[ $provider ] ) ? (string) $stored[ $provider ] : '';

        return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
    }

    /**
     * 取得各池目前已儲存的 Key 把數。
     *
     * @return array<string,int>
     */
    private function get_shared_key_counts(): array {
        $counts = [];
        foreach ( $this->get_supported_providers() as $provider ) {
            $counts[ $provider ] = count( $this->get_shared_pool_keys( $provider ) );
        }
        return $counts;
    }

    /**
     * 取得指定供應商的共用 API Key 清單與輪替游標。
     *
     * 集中處理多把 Key 的拆解與防呆:
     * - Key 內容只剩空白時直接回錯,避免後續 `% 0` 造成 DivisionByZeroError(整個 AJAX 回 500)。
     * - 游標若為負數(異常資料)會被歸零,避免取到不存在的陣列索引。
     *
     * 注意:Key 清單為空時本方法會直接輸出 JSON 錯誤並結束請求。
     *
     * @param string $provider gemini|openai|claude,決定要用哪一池。
     * @return array{keys:array<int,string>, count:int, cursor:int, index:int, current:string, provider:string}
     */
    private function get_api_key_set( string $provider ): array {
        $provider = $this->normalize_provider( $provider );

        $keys  = $this->get_shared_pool_keys( $provider );
        $count = count( $keys );

        if ( 0 === $count ) {
            wp_send_json_error(
                '尚未設定「' . $this->get_provider_label( $provider ) . '」的全站共用 API Key,'
                . '或設定內容只有空白。請由網站管理員到「⚙️ AI 帳號設定面板」填入。'
            );
        }

        // 游標可能因舊資料或併發而異常,先夾到合法範圍再取餘數
        $cursors = get_option( self::SHARED_CURSOR_OPTION, [] );
        if ( ! is_array( $cursors ) ) {
            $cursors = [];
        }
        $cursor = max( 0, isset( $cursors[ $provider ] ) ? (int) $cursors[ $provider ] : 0 );
        $index  = $cursor % $count;

        return [
            'keys'     => $keys,
            'count'    => $count,
            'cursor'   => $cursor,
            'index'    => $index,
            'current'  => $keys[ $index ],
            'provider' => $provider,
        ];
    }

    /**
     * 將該池的輪替游標前進一格。
     *
     * 原本 8 處(6 個成功路徑 + send_api_failure() 的 2 個換 Key 分支)各自重複這段運算,
     * 改用共用池後集中在這裡,避免各池游標寫法不一致。
     *
     * @param array $key_set get_api_key_set() 的結果。
     */
    private function advance_key_cursor( array $key_set ): void {
        if ( empty( $key_set['count'] ) ) {
            return;
        }

        $provider = $this->normalize_provider( (string) ( $key_set['provider'] ?? 'gemini' ) );

        $cursors = get_option( self::SHARED_CURSOR_OPTION, [] );
        if ( ! is_array( $cursors ) ) {
            $cursors = [];
        }
        $cursors[ $provider ] = ( (int) $key_set['cursor'] + 1 ) % (int) $key_set['count'];

        update_option( self::SHARED_CURSOR_OPTION, $cursors, false );
    }

    /**
     * 是否已設定共用 Key 的管理密碼。
     *
     * 回傳 false 代表「首次設定模式」:管理員可免密碼直接設定 Key 與密碼。
     */
    private function is_shared_key_password_set(): bool {
        return '' !== (string) get_option( self::SHARED_PASSWORD_OPTION, '' );
    }

    /**
     * 發給前端一張一次性解鎖通行證(存在短效 transient,不寫 Cookie)。
     */
    private function issue_unlock_token(): string {
        $token = wp_generate_password( 32, false );
        set_transient( self::UNLOCK_TRANSIENT_PREFIX . $token, get_current_user_id(), self::UNLOCK_TTL );
        return $token;
    }

    /**
     * 驗證解鎖通行證,並確認是發給同一位使用者的(避免 token 被轉用)。
     */
    private function verify_unlock_token( string $token ): bool {
        if ( '' === $token ) {
            return false;
        }

        $owner = get_transient( self::UNLOCK_TRANSIENT_PREFIX . $token );

        return ( false !== $owner && (int) $owner === get_current_user_id() );
    }

    /**
     * 共用 Key 寫入類操作的統一守門:管理員角色 + 有效解鎖通行證。
     *
     * 尚未設定管理密碼時為「首次設定模式」,只驗管理員角色,讓第一次可以順利設定。
     * 本方法在不通過時會直接輸出 JSON 錯誤並結束請求。
     */
    private function require_shared_key_access(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足:只有網站管理員可以檢視或修改全站共用 API Key。' );
        }

        // 首次設定模式:尚未設定密碼,不需要通行證
        if ( ! $this->is_shared_key_password_set() ) {
            return;
        }

        $token = isset( $_POST['unlock_token'] ) ? sanitize_text_field( wp_unslash( $_POST['unlock_token'] ) ) : '';

        if ( ! $this->verify_unlock_token( $token ) ) {
            wp_send_json_error( '解鎖狀態已失效,請重新輸入管理密碼後再試。' );
        }
    }

    /**
     * 驗證管理密碼並發給解鎖通行證。
     *
     * 尚未設定密碼時直接回報首次設定模式,讓面板開放操作。
     */
    public function ajax_ai_shared_key_unlock(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足:只有網站管理員可以檢視或修改全站共用 API Key。' );
        }

        if ( ! $this->is_shared_key_password_set() ) {
            wp_send_json_success( [
                'first_time' => true,
                'token'      => '',
                'counts'     => $this->get_shared_key_counts(),
            ] );
        }

        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

        if ( '' === $password || ! wp_check_password( $password, (string) get_option( self::SHARED_PASSWORD_OPTION, '' ) ) ) {
            wp_send_json_error( '管理密碼錯誤。' );
        }

        wp_send_json_success( [
            'first_time' => false,
            'token'      => $this->issue_unlock_token(),
            'counts'     => $this->get_shared_key_counts(),
        ] );
    }

    /**
     * 儲存指定供應商池的共用 Key 清單(整批覆蓋)。
     *
     * 沿用既有的盲寫設計:送出空字串代表沒有輸入新內容,維持原本設定不覆蓋。
     */
    public function ajax_ai_shared_key_save(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        $this->require_shared_key_access();

        $provider = isset( $_POST['provider'] ) ? $this->normalize_provider( sanitize_text_field( wp_unslash( $_POST['provider'] ) ) ) : 'gemini';
        $input    = isset( $_POST['keys'] ) ? (string) wp_unslash( $_POST['keys'] ) : '';

        if ( '' === trim( $input ) ) {
            wp_send_json_error( '沒有輸入任何 Key,已維持原本設定不變更。' );
        }

        $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $input ) ) ) );

        $stored = get_option( self::SHARED_KEYS_OPTION, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        $stored[ $provider ] = implode( "\n", $lines );

        update_option( self::SHARED_KEYS_OPTION, $stored, false );

        wp_send_json_success( [
            'counts'  => $this->get_shared_key_counts(),
            'message' => '已更新「' . $this->get_provider_label( $provider ) . '」共用 Key,共 ' . count( $lines ) . ' 把。',
        ] );
    }

    /**
     * 設定或變更共用 Key 的管理密碼。
     *
     * 變更成功後會換發新的解鎖通行證,讓管理員在同一個頁面可以繼續操作。
     */
    public function ajax_ai_shared_key_set_password(): void {
        check_ajax_referer( 'asp_ai_nonce', 'nonce' );
        $this->require_shared_key_access();

        $new_password = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';

        if ( mb_strlen( $new_password ) < 6 ) {
            wp_send_json_error( '密碼長度至少需要 6 個字元。' );
        }

        update_option( self::SHARED_PASSWORD_OPTION, wp_hash_password( $new_password ), false );

        wp_send_json_success( [
            'token'   => $this->issue_unlock_token(),
            'message' => '管理密碼已更新,下次重新整理頁面後需要用新密碼解鎖。',
        ] );
    }

    /**
     * 判斷 API 呼叫失敗屬於哪一類,決定要不要換下一把 Key。
     *
     * 原本所有失敗一律當成「Key 失效」並輪換,造成兩個問題:
     * - 模型名稱打錯、內容超長等請求層級錯誤,換 Key 也沒用,卻仍把每把 Key 都試一遍。
     * - HTTP 200 但被安全機制阻擋時,訊息顯示成「Key 失敗: HTTP 200」,真正原因被蓋掉。
     *
     * @param int    $code     HTTP 狀態碼。
     * @param mixed  $body     已解碼的回應內容。
     * @param string $provider gemini|openai|claude。
     * @return array{type:string, message:string}
     *         type:key=換下一把 Key / request=請求本身有問題 / content=有回應但沒有可用內容
     */
    private function classify_api_failure( int $code, $body, string $provider ): array {
        // 先取出 API 回傳的錯誤訊息(各家結構不同)
        $raw_error = '';

        if ( is_array( $body ) && isset( $body['error'] ) ) {
            if ( is_array( $body['error'] ) && isset( $body['error']['message'] ) ) {
                $raw_error = (string) $body['error']['message'];
            } else {
                $raw_error = is_string( $body['error'] )
                    ? $body['error']
                    : (string) wp_json_encode( $body['error'] );
            }
        }

        // HTTP 200 卻取不到內容:被安全機制擋下或被長度截斷,換 Key 不會變好
        if ( 200 === $code ) {
            return [
                'type'    => 'content',
                'message' => $this->detect_empty_result_reason( $body, $provider ),
            ];
        }

        if ( 401 === $code || 403 === $code ) {
            return [ 'type' => 'key', 'message' => 'API 金鑰無效或未授權' ];
        }

        if ( 429 === $code ) {
            return [ 'type' => 'key', 'message' => 'API 額度耗盡或頻率過高' ];
        }

        if ( 400 === $code || 404 === $code ) {
            $message = ( '' !== $raw_error )
                ? $this->translate_api_error( $raw_error )
                : '請求內容或模型名稱有誤';

            // Gemini 等服務金鑰失效時是用 HTTP 400(而非 401/403)回報,
            // 訊息含金鑰/額度關鍵字就該換 Key,不能一律當成請求本身有誤而中止整個任務。
            if ( 400 === $code ) {
                $lower        = strtolower( $raw_error );
                $is_key_issue = ( false !== strpos( $lower, 'api key' ) )
                    || ( false !== strpos( $lower, 'quota' ) )
                    || ( false !== strpos( $lower, 'rate limit' ) );

                if ( $is_key_issue ) {
                    return [ 'type' => 'key', 'message' => $message ];
                }
            }

            return [
                'type'    => 'request',
                'message' => $message,
            ];
        }

        if ( $code >= 500 ) {
            // 標記為 retryable:讓 send_api_failure() 先原地等待重試同一把 Key,而非直接換 Key
            return [
                'type'      => 'request',
                'message'   => "AI 服務暫時無法回應(HTTP {$code}),請稍後再試",
                'retryable' => true,
            ];
        }

        // 其餘狀況:回退到既有的錯誤字串判斷,含金鑰或額度關鍵字才視為 Key 問題
        $lower        = strtolower( $raw_error );
        $is_key_issue = ( false !== strpos( $lower, 'api key' ) )
            || ( false !== strpos( $lower, 'quota' ) )
            || ( false !== strpos( $lower, 'rate limit' ) );

        return [
            'type'    => $is_key_issue ? 'key' : 'request',
            'message' => $this->translate_api_error( ( '' !== $raw_error ) ? $raw_error : "HTTP {$code}" ),
        ];
    }

    /**
     * HTTP 200 卻取不到文字內容時,找出真正原因。
     *
     * 這類情形最常見於 Gemini 因題材敏感而阻擋(SAFETY),
     * 以及單批資料太多導致回應被長度上限截斷(MAX_TOKENS)。
     *
     * @param mixed  $body     已解碼的回應內容。
     * @param string $provider gemini|openai|claude。
     * @return string 給編輯看的中文原因說明。
     */
    private function detect_empty_result_reason( $body, string $provider ): string {
        if ( ! is_array( $body ) ) {
            return 'AI 回應格式無法解析';
        }

        if ( 'openai' === $provider ) {
            $finish = isset( $body['choices'][0]['finish_reason'] ) ? (string) $body['choices'][0]['finish_reason'] : '';

            if ( 'length' === $finish ) {
                return '回應因長度上限被截斷(finish_reason: length),請縮小單次處理的份量';
            }
            if ( 'content_filter' === $finish ) {
                return '內容被 OpenAI 安全機制阻擋(finish_reason: content_filter)';
            }

            return ( '' !== $finish ) ? "AI 未回傳內容(finish_reason: {$finish})" : 'AI 未回傳任何內容';
        }

        if ( 'claude' === $provider ) {
            $stop = isset( $body['stop_reason'] ) ? (string) $body['stop_reason'] : '';

            if ( 'max_tokens' === $stop ) {
                return '回應因 max_tokens 上限被截斷,請縮小單次處理的份量';
            }

            return ( '' !== $stop ) ? "AI 未回傳內容(stop_reason: {$stop})" : 'AI 未回傳任何內容';
        }

        // Gemini:先看送出的內容是否在輸入階段就被擋下
        $block = isset( $body['promptFeedback']['blockReason'] ) ? (string) $body['promptFeedback']['blockReason'] : '';

        if ( '' !== $block ) {
            return "送出的內容被 Gemini 安全機制阻擋(blockReason: {$block}),請調整提示詞或原文內容";
        }

        $finish = isset( $body['candidates'][0]['finishReason'] ) ? (string) $body['candidates'][0]['finishReason'] : '';

        switch ( $finish ) {
            case 'SAFETY':
                return '回應被 Gemini 安全機制阻擋(finishReason: SAFETY),常見於成人或敏感題材,建議改用人工填寫';
            case 'RECITATION':
                return '回應因疑似重製受著作權保護的內容而被中止(finishReason: RECITATION)';
            case 'MAX_TOKENS':
                return '回應因長度上限被截斷(finishReason: MAX_TOKENS),請縮小單次處理的份量';
            case '':
                return 'AI 未回傳任何內容';
            default:
                return "AI 未回傳內容(finishReason: {$finish})";
        }
    }

    /**
     * 依失敗分類回應前端。
     *
     * 只有 Key 層級的問題才推進游標並要求前端換下一把 Key;
     * 內容層級的失敗換 Key 也沒用,直接回不可重試,讓前端停止該項任務;
     * retryable(5xx)則先讓前端原地等待 3 秒用同一把 Key 重試一次,重試後仍失敗才視同 Key 失效換下一把。
     *
     * @param array $failure  classify_api_failure() 的結果。
     * @param array $key_set  get_api_key_set() 的結果(內含 provider,游標由 advance_key_cursor() 依池推進)。
     * @param bool  $is_retry 是否為前端 3 秒後帶著 ai_retry_after_error 旗標送來的重試請求。
     * @return void 本方法一定會結束請求。
     */
    private function send_api_failure( array $failure, array $key_set, bool $is_retry = false ): void {
        $key_no = $key_set['index'] + 1;

        if ( 'key' === $failure['type'] ) {
            $this->advance_key_cursor( $key_set );

            wp_send_json_error( [
                'type'       => 'key_failed',
                'message'    => "⚠️ 第 {$key_no} 把 Key 失敗: {$failure['message']}，自動切換下一把 Key...",
                'retry'      => true,
                'total_keys' => $key_set['count'],
            ] );
        }

        // retryable(5xx 等暫時性伺服器錯誤):第一次先不換 Key,讓前端原地等待 3 秒後用同一把 Key 重試一次;
        // 若這是重試後仍失敗($is_retry === true),才視同這把 Key 不可用,換下一把繼續。
        if ( ! empty( $failure['retryable'] ) ) {
            if ( ! $is_retry ) {
                wp_send_json_error( [
                    'type'           => $failure['type'],
                    'message'        => "第 {$key_no} 把 Key：{$failure['message']}（3 秒後自動重試...）",
                    'retry'          => true,
                    'retry_same_key' => true,
                ] );
            }

            $this->advance_key_cursor( $key_set );

            wp_send_json_error( [
                'type'       => 'key_failed',
                'message'    => "⚠️ 第 {$key_no} 把 Key 重試後仍失敗: {$failure['message']}，自動切換下一把 Key...",
                'retry'      => true,
                'total_keys' => $key_set['count'],
            ] );
        }

        $label = ( 'content' === $failure['type'] ) ? 'AI 沒有產生可用內容' : '請求無法完成';

        wp_send_json_error( [
            'type'    => $failure['type'],
            'message' => "{$label}：{$failure['message']}（換 Key 無法解決,已停止本項任務）",
            'retry'   => false,
        ] );
    }

    private function translate_api_error( $error_msg ) {
        if (!is_string($error_msg)) $error_msg = json_encode($error_msg);
        $lower = strtolower( $error_msg );
        if ( strpos($lower, 'api key not valid') !== false || strpos($lower, 'invalid api key') !== false || strpos($lower, 'incorrect') !== false ) return 'API 金鑰無效';
        if ( strpos($lower, 'quota') !== false || strpos($lower, 'rate limit') !== false || strpos($lower, 'too many requests') !== false || strpos($lower, '429') !== false || strpos($lower, 'demand') !== false ) return 'API 額度耗盡或頻率過高';
        if ( strpos($lower, 'model not found') !== false ) return '找不到指定的模型';
        if ( strpos($lower, 'context_length_exceeded') !== false ) return '內容過長超出模型限制';
        // Fix 4: 兜底訊息附上部分原始錯誤，方便診斷
        $short = mb_strlen($error_msg) > 60 ? mb_substr($error_msg, 0, 60) . '...' : $error_msg;
        return 'API 呼叫失敗：' . $short;
    }
}