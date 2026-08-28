<?php
/**
 * SMACG Social — Corrections 前台表單 + AJAX 送出
 *
 * 提供 shortcode [wxacg_correction_form]，顯示在 anime / manga 條目頁，
 * 現在也支援角色（character）頁面上掛的「影子 post」（post_type =
 * asa_char_comments，見 single-character.php）。
 * 預設收起，點「發現資料有誤？點此回報」才展開。深色系樣式（v1.1.1 調亮文字對比）。
 * 會員選類別 + 描述問題 + 選填來源，AJAX 送出後存成 pending 的 wxacg_correction。
 * 完全不碰正式資料。
 *
 * @version 1.2.0  支援角色頁影子 post（改用 global $post 判斷，不再依賴 is_singular()）
 *
 * Changelog:
 *   1.2.0 (2026-07-29)
 *     - [修正] 顯示端原本用 is_singular( WXACG_CORR_POST_TYPES ) 判斷要不要輸出。
 *              is_singular() 看的是「主查詢」($wp_query) 認不認得這是 anime/manga
 *              的單篇頁面。角色頁（single-character.php）走的是自訂路由
 *              （asa_character_id query var），主查詢根本不是任何 post 的
 *              singular view，所以不管角色頁模板怎麼暫時替換 global $post，
 *              is_singular() 永遠回傳 false，整個 shortcode 直接吐空字串 ——
 *              這就是角色頁點「糾錯回報」下面完全沒東西的真正原因。
 *              改成直接檢查 global $post 的 post_type 是否在允許清單內，
 *              不再依賴主查詢的 singular 狀態。anime/manga 頁的主迴圈本來
 *              就會把 global $post 設成當篇文章，行為完全不變；角色頁只要
 *              在呼叫 shortcode 前把 global $post 換成對應的影子 post，
 *              現在就能正確通過判斷。
 *     - [新增] WXACG_CORR_POST_TYPES 加入 'asa_char_comments'（角色頁影子
 *              post 的 post_type）。未來若人物 /person/ 頁也要接同一套，
 *              一樣把對應的影子 post_type 加進這個陣列即可。
 *     - [新增] 前端自動展開的錨點判斷，原本寫死只認 '#asd-sec-corrections'
 *              （anime 頁用的 id），角色頁的 section id 是
 *              '#asa-sec-corrections'，兩者都要能觸發自動展開，改成用陣列
 *              比對，兩個 id 都支援。
 *     - [保留] AJAX 送出端 (wxacg_submit_correction) 的驗證邏輯本來就是用
 *              get_post_type($target_id) 直接查資料庫，不依賴主查詢，
 *              只要 post_type 在允許清單內就會通過，不需要額外修改。
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

/**
 * 支援的作品／實體類型。
 *
 * [1.2.0] 加入 asa_char_comments：single-character.php 用它當作角色頁的
 * 「影子 post」（不是真的作品，純粹讓留言 / 糾錯回報這類原生依賴 $post 的
 * 功能可以掛得上去）。要幫其他非 anime/manga 的頁面（例如未來的 /person/
 * 人物頁）接上同一套糾錯表單，一樣把對應的影子 post_type 加進這裡即可。
 */
if ( ! defined( 'WXACG_CORR_POST_TYPES' ) ) {
    define( 'WXACG_CORR_POST_TYPES', [ 'anime', 'manga', 'novel', 'game', 'liveaction', 'asa_char_comments' ] );
}

/* ============================================================
   Shortcode：[wxacg_correction_form]
   ============================================================ */
add_shortcode( 'wxacg_correction_form', function () {

    /*
     * [1.2.0] 改用 global $post 的 post_type 判斷，不再用 is_singular()。
     *
     * is_singular() 看的是主查詢（$wp_query）認不認得目前頁面是哪個 post
     * type 的單篇頁——對走自訂路由的角色頁來說，主查詢從頭到尾就不是任何
     * post 的 singular view，這個判斷永遠是 false。
     *
     * global $post 才是「這個 shortcode 實際被塞進哪篇文章的情境」，anime /
     * manga 的主迴圈本來就會設好它，角色頁模板呼叫這個 shortcode 前也已經
     * 用 setup_postdata() 把 $post 換成對應的影子 post，所以直接看 $post
     * 更準、也更不依賴頁面本身是怎麼路由的。
     */
    global $post;

    if ( ! ( $post instanceof \WP_Post ) || ! in_array( $post->post_type, WXACG_CORR_POST_TYPES, true ) ) {
        return '';
    }

    $target_id    = $post->ID;
    $target_title = get_the_title( $target_id );

    // 未登入：只顯示一行提示，不佔空間
    if ( ! is_user_logged_in() ) {
        return '<div class="wxacg-corr-wrap"><p class="wxacg-corr-guest">🚩 發現資料有誤？<a href="' . esc_url( wp_login_url( get_permalink( $target_id ) ) ) . '">登入</a>後即可協助回報。</p></div>';
    }

    ob_start();
    ?>
    <div class="wxacg-corr-wrap">
        <!-- 收合觸發按鈕 -->
        <button type="button" class="wxacg-corr-toggle" id="wxacg-corr-toggle" aria-expanded="false">
            <span class="wxacg-corr-toggle-icon">🚩</span>
            <span>發現資料有誤？點此回報</span>
            <span class="wxacg-corr-toggle-arrow">▾</span>
        </button>

        <!-- 表單面板（預設隱藏） -->
        <div class="wxacg-corr-panel" id="wxacg-corr-panel" hidden>
            <p class="wxacg-corr-hint">回報「<?php echo esc_html( $target_title ); ?>」的資料錯誤，審核採納後可獲得經驗值回饋 🎉</p>

            <form class="wxacg-corr-form" id="wxacg-corr-form">
                <input type="hidden" name="anime_id" value="<?php echo esc_attr( $target_id ); ?>">

                <label class="wxacg-corr-label">問題類別</label>
                <select name="category" class="wxacg-corr-select" required>
                    <option value="">— 請選擇 —</option>
                    <?php foreach ( Corrections_CPT::CATEGORIES as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="wxacg-corr-label">問題說明</label>
                <textarea name="detail" class="wxacg-corr-textarea" rows="4"
                          placeholder="請描述哪裡有誤、正確的內容應該是什麼。例如：導演應該是新房昭之，不是目前顯示的名字。"
                          required maxlength="1000"></textarea>

                <label class="wxacg-corr-label">參考來源（選填）</label>
                <input type="url" name="source" class="wxacg-corr-input"
                       placeholder="https://（官網、Bangumi、維基等佐證連結）" maxlength="500">

                <div class="wxacg-corr-actions">
                    <button type="submit" class="wxacg-corr-submit">送出回報</button>
                    <button type="button" class="wxacg-corr-cancel" id="wxacg-corr-cancel">取消</button>
                </div>
                <div class="wxacg-corr-msg" style="display:none"></div>
            </form>
        </div>
    </div>

    <script>
    (function(){
        var toggle = document.getElementById('wxacg-corr-toggle');
        var panel  = document.getElementById('wxacg-corr-panel');
        var cancel = document.getElementById('wxacg-corr-cancel');
        var form   = document.getElementById('wxacg-corr-form');
        if (!toggle || !panel || !form) return;

        function openPanel(){
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            toggle.classList.add('is-open');
        }
        function closePanel(){
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.classList.remove('is-open');
        }

        toggle.addEventListener('click', function(){
            panel.hidden ? openPanel() : closePanel();
        });
        if (cancel) cancel.addEventListener('click', closePanel);

        /*
         * [1.2.0] 「糾錯回報」錨點在不同模板裡的 id 前綴不一樣：
         *   - anime 頁 (single-anime.php)：#asd-sec-corrections
         *   - 角色頁 (single-character.php)：#asa-sec-corrections
         * 原本寫死只認前者，角色頁點按鈕會捲過去但面板不會自動展開。
         * 改成陣列比對，兩種都支援；以後其他模板要接，往陣列裡加就好。
         */
        var CORR_ANCHORS = ['#asd-sec-corrections', '#asa-sec-corrections'];

        if (CORR_ANCHORS.indexOf(window.location.hash) !== -1) {
            openPanel();
        }
        CORR_ANCHORS.forEach(function (anchor) {
            document.querySelectorAll('a[href="' + anchor + '"]').forEach(function (a) {
                a.addEventListener('click', function () {
                    setTimeout(openPanel, 300); // 等頁面捲到定位後再展開
                });
            });
        });

        form.addEventListener('submit', function(e){
            e.preventDefault();
            var btn = form.querySelector('.wxacg-corr-submit');
            var msg = form.querySelector('.wxacg-corr-msg');
            btn.disabled = true; btn.textContent = '送出中…';

            var data = new FormData(form);
            data.append('action', 'wxacg_submit_correction');
            data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wxacg_correction_nonce' ) ); ?>');

            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST', credentials: 'same-origin', body: data
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                msg.style.display = 'block';
                if (res.success) {
                    msg.style.color = '#7CE38B';
                    msg.textContent = res.data.msg || '感謝回報，我們會盡快處理！';
                    form.reset();
                    btn.textContent = '已送出 ✓';
                } else {
                    msg.style.color = '#FF8080';
                    msg.textContent = (res.data && res.data.msg) ? res.data.msg : '送出失敗，請稍後再試。';
                    btn.disabled = false; btn.textContent = '送出回報';
                }
            })
            .catch(function(){
                msg.style.display = 'block';
                msg.style.color = '#FF8080';
                msg.textContent = '網路錯誤，請稍後再試。';
                btn.disabled = false; btn.textContent = '送出回報';
            });
        });
    })();
    </script>

    <style>
        .wxacg-corr-wrap{margin:20px 0}
        .wxacg-corr-guest{color:#ccc;font-size:14px;text-align:center}
        .wxacg-corr-guest a{color:#8ab4ff}

        /* 觸發按鈕 */
        .wxacg-corr-toggle{
            display:flex;align-items:center;gap:8px;width:100%;
            padding:12px 16px;background:rgba(255,255,255,.05);
            border:1px solid rgba(255,255,255,.12);border-radius:10px;
            color:#e8e8e8;font-size:14px;cursor:pointer;transition:all .2s;
        }
        .wxacg-corr-toggle:hover{background:rgba(255,255,255,.1);color:#fff}
        .wxacg-corr-toggle-arrow{margin-left:auto;transition:transform .2s}
        .wxacg-corr-toggle.is-open .wxacg-corr-toggle-arrow{transform:rotate(180deg)}

        /* 展開面板 */
        .wxacg-corr-panel{
            margin-top:12px;padding:20px;
            background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.12);border-radius:12px;
        }
        .wxacg-corr-hint{margin:0 0 16px;color:#bbb;font-size:13px}
        .wxacg-corr-label{display:block;margin:14px 0 6px;font-weight:600;font-size:13px;color:#f0f0f0}
        .wxacg-corr-select,.wxacg-corr-input,.wxacg-corr-textarea{
            width:100%;padding:10px 12px;box-sizing:border-box;font-size:14px;
            background:rgba(0,0,0,.3);color:#f5f5f5 !important;
            border:1px solid rgba(255,255,255,.18);border-radius:8px;
        }
        /* 強制打字文字為亮色，蓋過主題 */
        .wxacg-corr-panel .wxacg-corr-textarea,
        .wxacg-corr-panel .wxacg-corr-input{
            color:#f5f5f5 !important;
            -webkit-text-fill-color:#f5f5f5 !important;
        }
        .wxacg-corr-textarea{resize:vertical}
        .wxacg-corr-select:focus,.wxacg-corr-input:focus,.wxacg-corr-textarea:focus{
            outline:none;border-color:#8ab4ff;
        }
        /* 下拉未選擇（值為空）時，文字調亮，避免看起來像 placeholder 太暗 */
        .wxacg-corr-select:invalid,
        .wxacg-corr-select option[value=""]{
            color:#b0b0b0;
        }
        .wxacg-corr-select{
            color:#f5f5f5;
            cursor:pointer;
            appearance:none;
            line-height:normal;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-position:right 14px center;
            padding-right:36px;
        }

        /* placeholder 調亮（分開寫各家前綴 + !important 蓋過主題） */
        .wxacg-corr-input::placeholder,
        .wxacg-corr-textarea::placeholder{color:#b0b0b0 !important;opacity:1 !important}
        .wxacg-corr-input::-webkit-input-placeholder,
        .wxacg-corr-textarea::-webkit-input-placeholder{color:#b0b0b0 !important;opacity:1 !important}
        .wxacg-corr-input::-moz-placeholder,
        .wxacg-corr-textarea::-moz-placeholder{color:#b0b0b0 !important;opacity:1 !important}
        .wxacg-corr-input:-ms-input-placeholder,
        .wxacg-corr-textarea:-ms-input-placeholder{color:#b0b0b0 !important;opacity:1 !important}

        /* 下拉選單選項在深色下的可讀性（部分系統會用系統色） */
        .wxacg-corr-select option{color:#111;background:#fff}

        .wxacg-corr-actions{display:flex;gap:10px;margin-top:18px}
        .wxacg-corr-submit{
            padding:10px 24px;background:#4a6cf7;color:#fff;
            border:none;border-radius:8px;cursor:pointer;font-size:14px;
        }
        .wxacg-corr-submit:disabled{opacity:.6;cursor:not-allowed}
        .wxacg-corr-cancel{
            padding:10px 20px;background:transparent;color:#bbb;
            border:1px solid rgba(255,255,255,.18);border-radius:8px;cursor:pointer;font-size:14px;
        }
        .wxacg-corr-cancel:hover{color:#fff;border-color:rgba(255,255,255,.35)}
        .wxacg-corr-msg{margin-top:12px;font-size:14px}
    </style>
    <?php
    return ob_get_clean();
} );

/* ============================================================
   AJAX：wxacg_submit_correction
   ============================================================ */
add_action( 'wp_ajax_wxacg_submit_correction', function () {

    check_ajax_referer( 'wxacg_correction_nonce', 'nonce' );

    $uid = get_current_user_id();
    if ( ! $uid ) {
        wp_send_json_error( [ 'msg' => '請先登入才能回報' ], 401 );
    }

    $target_id = isset( $_POST['anime_id'] ) ? absint( $_POST['anime_id'] ) : 0;
    $category  = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
    $detail    = isset( $_POST['detail'] ) ? sanitize_textarea_field( wp_unslash( $_POST['detail'] ) ) : '';
    $source    = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';

    // 驗證：作品／實體必須是允許清單內的 post type（anime / manga / 角色影子 post 等）
    if ( ! $target_id || ! in_array( get_post_type( $target_id ), WXACG_CORR_POST_TYPES, true ) ) {
        wp_send_json_error( [ 'msg' => '無效的作品' ], 400 );
    }
    if ( ! array_key_exists( $category, Corrections_CPT::CATEGORIES ) ) {
        wp_send_json_error( [ 'msg' => '請選擇有效的問題類別' ], 400 );
    }
    if ( mb_strlen( $detail ) < 5 ) {
        wp_send_json_error( [ 'msg' => '問題說明太短，請描述清楚一點（至少 5 字）' ], 400 );
    }

    // 簡易防洗版：同一會員同一作品 10 分鐘內重複送出擋掉
    $recent = get_posts( [
        'post_type'      => Corrections_CPT::POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'author'         => $uid,
        'date_query'     => [ [ 'after' => '10 minutes ago' ] ],
        'meta_query'     => [ [ 'key' => '_wxacg_corr_anime_id', 'value' => $target_id ] ],
        'fields'         => 'ids',
    ] );
    if ( ! empty( $recent ) ) {
        wp_send_json_error( [ 'msg' => '你剛剛才回報過這部作品，請稍後再送出' ], 429 );
    }

    // 建立待審記錄
    $post_id = wp_insert_post( [
        'post_type'   => Corrections_CPT::POST_TYPE,
        'post_status' => 'publish',   // 對 CPT 而言 publish 只是「已建立」，因 public=false 前台看不到
        'post_author' => $uid,
        'post_title'  => sprintf( '[%s] %s', Corrections_CPT::category_label( $category ), get_the_title( $target_id ) ),
    ], true );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        wp_send_json_error( [ 'msg' => '系統錯誤，送出失敗' ], 500 );
    }

    update_post_meta( $post_id, '_wxacg_corr_anime_id', $target_id );
    update_post_meta( $post_id, '_wxacg_corr_category', $category );
    update_post_meta( $post_id, '_wxacg_corr_detail',   $detail );
    update_post_meta( $post_id, '_wxacg_corr_source',   $source );
    update_post_meta( $post_id, '_wxacg_corr_reporter', $uid );
    update_post_meta( $post_id, '_wxacg_corr_status',   'pending' );

    wp_send_json_success( [ 'msg' => '感謝回報！審核採納後你會收到通知與經驗值回饋 🎉' ] );
} );