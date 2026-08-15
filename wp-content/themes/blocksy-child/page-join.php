<?php
/**
 * Template Name: 加入我們
 * Description: 微笑動漫 ACG 招募頁面模板
 * Version: 1.4.2
 *
 * 更新內容：
 * - 1.4.2 (2026-06-20) [可讀性修正] 加深各區塊底色、調亮過淡文字、kicker 換亮藍、
 *          加文字陰影，解決深色背景下標題與說明文字過暗的問題。版面結構、表單邏輯不變。
 * - 1.4.1 加入三位管理員團隊介紹卡片
 * - 顯示 WordPress 使用者頭貼
 * - 移除金錢、收益、利益導向文字
 * - 改為鼓勵分享動漫、整理情報、推廣作品、累積實戰經驗
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * SEO 標題。
 *
 * 後台頁面標題為英文 slug「join」，直接輸出會是「join - 微笑動漫」。
 * 沿用 page-bangumi*.php 既有的 pre_get_document_title 作法覆寫，
 * 不動後台頁面標題（避免連帶影響選單與頁面上的 H1）。
 */
add_filter( 'pre_get_document_title', function () {
    return '人才招募｜加入微笑動漫編輯與內容團隊';
}, 99 );

get_header();

/**
 * 編輯與內容團隊：與 page-about.php 的「編輯與內容團隊」區塊沿用同一份邏輯
 * ──動態抓取已發表過至少一篇文章的作者，而非手動維護的固定名單，
 * 兩頁呈現的作者資訊才會一致，不會各說各話。
 */
$weixiao_team_members = get_users( array(
    'has_published_posts' => array( 'post' ),
    'orderby'              => 'post_count',
    'order'                => 'DESC',
    'number'               => 12,
) );

/**
 * 表單處理
 */
$join_form_message = '';
$join_form_status  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['weixiao_join_form_submit'])) {
    // 防灌水：honeypot 欄位一般使用者看不到也不會填，機器人常會自動填入。
    $is_honeypot_filled = !empty($_POST['applicant_website']);

    // 防灌水：表單載入到送出間隔太短（<3 秒）視為機器人自動送出。
    $loaded_at        = isset($_POST['weixiao_join_loaded_at']) ? (int) $_POST['weixiao_join_loaded_at'] : 0;
    $is_submit_too_fast = $loaded_at > 0 && (time() - $loaded_at) < 3;

    if ($is_honeypot_filled || $is_submit_too_fast) {
        // 靜默視為成功，不告知機器人被擋下，避免對方調整策略再試。
        $join_form_status  = 'success';
        $join_form_message = '已收到你的申請！我們會閱讀內容後再與你聯繫。';
    } elseif (
        !isset($_POST['weixiao_join_nonce']) ||
        !wp_verify_nonce($_POST['weixiao_join_nonce'], 'weixiao_join_form_action')
    ) {
        $join_form_status  = 'error';
        $join_form_message = '表單驗證失敗，請重新整理頁面後再試一次。';
    } else {
        $applicant_name    = isset($_POST['applicant_name']) ? sanitize_text_field($_POST['applicant_name']) : '';
        $applicant_email   = isset($_POST['applicant_email']) ? sanitize_email($_POST['applicant_email']) : '';
        $applicant_contact = isset($_POST['applicant_contact']) ? sanitize_text_field($_POST['applicant_contact']) : '';
        $applicant_role    = isset($_POST['applicant_role']) ? sanitize_text_field($_POST['applicant_role']) : '';
        $applicant_intro   = isset($_POST['applicant_intro']) ? sanitize_textarea_field($_POST['applicant_intro']) : '';
        $applicant_links   = isset($_POST['applicant_links']) ? sanitize_textarea_field($_POST['applicant_links']) : '';

        if (empty($applicant_name) || empty($applicant_email) || empty($applicant_role) || empty($applicant_intro)) {
            $join_form_status  = 'error';
            $join_form_message = '請填寫必填欄位：暱稱、Email、想參與的方向、自我介紹。';
        } elseif (!is_email($applicant_email)) {
            $join_form_status  = 'error';
            $join_form_message = 'Email 格式不正確，請再次確認。';
        } else {
            $to      = get_option('admin_email');
            $subject = '【微笑動漫】新的加入我們申請：' . $applicant_name;

            $body  = "你收到一份新的加入我們申請：\n\n";
            $body .= "暱稱 / 姓名：{$applicant_name}\n";
            $body .= "Email：{$applicant_email}\n";
            $body .= "其他聯絡方式：{$applicant_contact}\n";
            $body .= "想參與的方向：{$applicant_role}\n\n";
            $body .= "自我介紹：\n{$applicant_intro}\n\n";
            $body .= "作品 / 社群 / 參考連結：\n{$applicant_links}\n\n";
            $body .= "送出時間：" . current_time('mysql') . "\n";
            $body .= "來源頁面：" . get_permalink() . "\n";

            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'Reply-To: ' . $applicant_name . ' <' . $applicant_email . '>',
            );

            $sent = wp_mail($to, $subject, $body, $headers);

            if ($sent) {
                $join_form_status  = 'success';
                $join_form_message = '已收到你的申請！我們會閱讀內容後再與你聯繫。';
            } else {
                $join_form_status  = 'error';
                $join_form_message = '送出時發生問題，請稍後再試，或直接透過站內聯絡方式與我們聯繫。';
            }
        }
    }
}
?>

<style>
:root {
    --join-bg: #070814;
    --join-card: rgba(255, 255, 255, 0.075);
    --join-card-strong: rgba(255, 255, 255, 0.12);
    --join-border: rgba(255, 255, 255, 0.16);
    --join-border-soft: rgba(255, 255, 255, 0.10);
    --join-text: #ffffff;
    --join-muted: rgba(255, 255, 255, 0.86);
    --join-muted-2: rgba(255, 255, 255, 0.66);
    --join-pink: #ff5fa2;
    --join-purple: #9b6dff;
    --join-blue: #5fd8ff;
    --join-blue-bright: #8fe6ff;
    --join-yellow: #ffd166;
    --join-green: #7CFFB2;
    --join-radius-xl: 28px;
    --join-radius-lg: 22px;
    --join-radius-md: 16px;
    --join-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.weixiao-join-page {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 8%, rgba(255, 95, 162, 0.20), transparent 32%),
        radial-gradient(circle at 88% 18%, rgba(95, 216, 255, 0.16), transparent 30%),
        radial-gradient(circle at 50% 92%, rgba(155, 109, 255, 0.18), transparent 34%),
        var(--join-bg);
    color: var(--join-text);
    padding: 56px 18px 80px;
}

.weixiao-join-page * {
    box-sizing: border-box;
}

.join-wrap {
    width: min(1120px, 100%);
    margin: 0 auto;
    position: relative;
    z-index: 2;
}

.join-bg-orb {
    position: absolute;
    width: 360px;
    height: 360px;
    border-radius: 999px;
    filter: blur(48px);
    opacity: 0.14;
    pointer-events: none;
    z-index: 1;
}

.join-bg-orb.one {
    background: var(--join-pink);
    left: -120px;
    top: 160px;
}

.join-bg-orb.two {
    background: var(--join-blue);
    right: -140px;
    top: 520px;
}

.join-hero {
    padding: 58px 34px;
    border: 1px solid var(--join-border);
    border-radius: var(--join-radius-xl);
    background:
        linear-gradient(135deg, rgba(28, 22, 52, 0.78), rgba(14, 12, 30, 0.86)),
        rgba(10, 10, 24, 0.55);
    box-shadow: var(--join-shadow);
    backdrop-filter: blur(16px);
    text-align: center;
    overflow: hidden;
    position: relative;
}

.join-hero::before {
    content: "";
    position: absolute;
    inset: -2px;
    background:
        linear-gradient(120deg, transparent, rgba(255,255,255,0.16), transparent);
    transform: translateX(-100%);
    animation: joinShine 5.5s ease-in-out infinite;
    pointer-events: none;
}

@keyframes joinShine {
    0%, 55% { transform: translateX(-100%); }
    75%, 100% { transform: translateX(100%); }
}

.join-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 999px;
    background: rgba(255,255,255,0.12);
    border: 1px solid var(--join-border);
    color: #fff;
    font-size: 14px;
    margin-bottom: 18px;
}

.join-title {
    margin: 0;
    font-size: clamp(34px, 6vw, 72px);
    line-height: 1.03;
    letter-spacing: -0.06em;
    font-weight: 950;
    color: #fff;
    text-shadow: 0 2px 18px rgba(0,0,0,0.45);
}

.join-title span {
    background: linear-gradient(90deg, var(--join-pink), var(--join-yellow), var(--join-blue), var(--join-purple));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: none;
}

.join-suit-sub {
    margin: 22px auto 0;
    font-size: clamp(18px, 2.4vw, 28px);
    font-weight: 850;
    color: #fff;
    text-shadow: 0 2px 14px rgba(0,0,0,0.4);
}

.join-suit-desc {
    max-width: 820px;
    margin: 18px auto 0;
    color: var(--join-muted);
    font-size: 17px;
    line-height: 1.9;
}

.join-suit-desc strong {
    color: #fff;
}

.join-hero-actions {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 28px;
}

.join-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 48px;
    padding: 0 20px;
    border-radius: 999px;
    text-decoration: none;
    border: 1px solid var(--join-border);
    color: #fff;
    font-weight: 850;
    transition: transform .18s ease, border-color .18s ease, background .18s ease;
}

.join-btn:hover {
    transform: translateY(-2px);
    color: #fff;
    border-color: rgba(255,255,255,0.35);
}

.join-btn.primary {
    background: linear-gradient(135deg, var(--join-pink), var(--join-purple));
    border-color: rgba(255,255,255,0.2);
    box-shadow: 0 14px 36px rgba(255,95,162,0.3);
}

.join-btn.ghost {
    background: rgba(255,255,255,0.1);
}

.join-section {
    margin-top: 34px;
    padding: 34px;
    border: 1px solid var(--join-border-soft);
    border-radius: var(--join-radius-xl);
    background:
        linear-gradient(135deg, rgba(22, 20, 42, 0.72), rgba(12, 12, 26, 0.80));
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 50px rgba(0,0,0,0.30);
}

.join-section-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 22px;
}

.join-section-kicker {
    color: var(--join-blue-bright);
    font-size: 13px;
    font-weight: 900;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-bottom: 8px;
    text-shadow: 0 0 14px rgba(95,216,255,0.35);
}

.join-section-title {
    margin: 0;
    font-size: clamp(24px, 3vw, 36px);
    letter-spacing: -0.03em;
    font-weight: 950;
    color: #fff;
    text-shadow: 0 2px 14px rgba(0,0,0,0.4);
}

.join-section-desc {
    color: var(--join-muted);
    line-height: 1.8;
    margin: 10px 0 0;
    max-width: 780px;
}

.join-section-desc strong {
    color: #fff;
}

.join-status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.join-status-card {
    padding: 20px;
    border-radius: var(--join-radius-lg);
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--join-border-soft);
}

.join-status-card .icon {
    font-size: 28px;
    margin-bottom: 10px;
}

.join-status-card h3 {
    margin: 0 0 8px;
    font-size: 18px;
    color: #fff;
}

.join-status-card p {
    margin: 0;
    color: var(--join-muted);
    line-height: 1.7;
    font-size: 15px;
}

.join-team-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
}

.join-team-card {
    position: relative;
    overflow: hidden;
    padding: 24px;
    border-radius: var(--join-radius-xl);
    background:
        linear-gradient(180deg, rgba(255,255,255,0.10), rgba(255,255,255,0.04)),
        rgba(14, 14, 30, 0.55);
    border: 1px solid var(--join-border);
    box-shadow: 0 18px 50px rgba(0,0,0,0.30);
    transition: transform .18s ease, border-color .18s ease, background .18s ease;
}

.join-team-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255,255,255,0.3);
}

.join-team-card::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 25% 8%, rgba(255,95,162,0.18), transparent 34%),
        radial-gradient(circle at 88% 22%, rgba(95,216,255,0.14), transparent 36%);
    pointer-events: none;
}

.join-team-inner {
    position: relative;
    z-index: 2;
}

.join-team-avatar-wrap {
    width: 112px;
    height: 112px;
    border-radius: 999px;
    padding: 4px;
    margin: 0 auto 16px;
    background: linear-gradient(135deg, var(--join-pink), var(--join-blue), var(--join-purple));
    box-shadow: 0 16px 34px rgba(0,0,0,0.32);
}

.join-team-avatar {
    display: block;
    width: 104px;
    height: 104px;
    border-radius: 999px;
    object-fit: cover;
    background: rgba(255,255,255,0.14);
    border: 3px solid rgba(7,8,20,0.92);
}

.join-team-avatar-fallback {
    width: 104px;
    height: 104px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    font-weight: 950;
    color: #fff;
    background: linear-gradient(135deg, rgba(255,95,162,0.8), rgba(155,109,255,0.8));
    border: 3px solid rgba(7,8,20,0.92);
}

.join-team-name {
    text-align: center;
    margin: 0;
    font-size: 22px;
    font-weight: 950;
    letter-spacing: -0.02em;
    color: #fff;
}

.join-team-role {
    margin: 8px auto 0;
    text-align: center;
    color: var(--join-blue-bright);
    font-size: 14px;
    font-weight: 850;
}

.join-team-desc {
    margin: 14px 0 0;
    color: var(--join-muted);
    font-size: 15px;
    line-height: 1.8;
    text-align: center;
}

.join-team-tags {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
}

.join-team-tag {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    color: rgba(255,255,255,0.9);
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.14);
    font-size: 12px;
    font-weight: 750;
}

.join-team-link {
    display: flex;
    justify-content: center;
    margin-top: 18px;
}

.join-team-link a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 40px;
    padding: 0 15px;
    border-radius: 999px;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 850;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.16);
    transition: transform .18s ease, background .18s ease;
}

.join-team-link a:hover {
    transform: translateY(-2px);
    color: #fff;
    background: rgba(255,255,255,0.16);
}

.join-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.join-card {
    padding: 20px;
    border-radius: var(--join-radius-lg);
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--join-border-soft);
}

.join-card .emoji {
    font-size: 28px;
    margin-bottom: 10px;
}

.join-card .main {
    margin: 0 0 6px;
    font-weight: 900;
    color: #fff;
}

.join-card .sub {
    margin: 0;
    color: var(--join-muted);
    font-size: 14px;
    line-height: 1.7;
}

.join-role-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.join-role-card {
    padding: 22px;
    border-radius: var(--join-radius-lg);
    border: 1px solid var(--join-border-soft);
    background: rgba(255,255,255,0.055);
}

.join-role-card h3 {
    margin: 0 0 12px;
    font-size: 20px;
    color: #fff;
}

.join-role-card ul {
    margin: 0;
    padding-left: 20px;
    color: var(--join-muted);
    line-height: 1.85;
}

.join-notice {
    padding: 22px;
    border-radius: var(--join-radius-lg);
    border: 1px solid rgba(255,209,102,0.32);
    background: rgba(255,209,102,0.10);
    color: rgba(255,255,255,0.92);
    line-height: 1.85;
}

.join-notice strong {
    color: #fff;
}

.join-form {
    display: grid;
    gap: 16px;
}

.join-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}

.join-field label {
    display: block;
    margin-bottom: 8px;
    color: #fff;
    font-weight: 850;
    font-size: 14px;
}

.join-field input,
.join-field select,
.join-field textarea {
    width: 100%;
    border: 1px solid rgba(255,255,255,0.16);
    border-radius: 16px;
    background: rgba(255,255,255,0.08);
    color: #fff;
    padding: 14px 15px;
    outline: none;
    transition: border-color .18s ease, background .18s ease;
}

.join-field input::placeholder,
.join-field textarea::placeholder {
    color: rgba(255,255,255,0.5);
}

.join-field input:focus,
.join-field select:focus,
.join-field textarea:focus {
    border-color: rgba(95,216,255,0.6);
    background: rgba(255,255,255,0.12);
}

.join-field textarea {
    min-height: 132px;
    resize: vertical;
}

.join-field select option {
    color: #111;
    background-color: #fff;
}

.join-field select option:checked,
.join-field select option:hover {
    background-color: #5fd8ff;
    color: #111;
}


.join-form-help {
    margin-top: 7px;
    color: var(--join-muted-2);
    font-size: 13px;
    line-height: 1.6;
}

.join-message {
    padding: 15px 16px;
    border-radius: 16px;
    line-height: 1.7;
    font-weight: 800;
}

.join-message.success {
    background: rgba(124,255,178,0.14);
    border: 1px solid rgba(124,255,178,0.32);
    color: #d9ffe8;
}

.join-message.error {
    background: rgba(255,95,95,0.14);
    border: 1px solid rgba(255,95,95,0.32);
    color: #ffe1e1;
}

.join-submit {
    border: 0;
    cursor: pointer;
    min-height: 52px;
    padding: 0 22px;
    border-radius: 999px;
    color: #fff;
    font-size: 16px;
    font-weight: 950;
    background: linear-gradient(135deg, var(--join-pink), var(--join-purple));
    box-shadow: 0 14px 34px rgba(255,95,162,0.28);
    transition: transform .18s ease, box-shadow .18s ease;
}

.join-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 42px rgba(255,95,162,0.34);
}

.join-footer-note {
    text-align: center;
    color: var(--join-muted-2);
    font-size: 13px;
    line-height: 1.8;
    margin-top: 24px;
}

@media (max-width: 900px) {
    .join-status-grid,
    .join-team-grid,
    .join-cards {
        grid-template-columns: 1fr;
    }

    .join-role-grid,
    .join-form-grid {
        grid-template-columns: 1fr;
    }

    .join-section-head {
        display: block;
    }
}

@media (max-width: 560px) {
    .weixiao-join-page {
        padding: 34px 12px 58px;
    }

    .join-hero,
    .join-section {
        padding: 24px 18px;
        border-radius: 22px;
    }

    .join-title {
        font-size: 38px;
    }

    .join-suit-desc {
        font-size: 15px;
    }
}
</style>

<main class="weixiao-join-page">
    <div class="join-bg-orb one"></div>
    <div class="join-bg-orb two"></div>

    <div class="join-wrap">

        <section class="join-hero">
            <div class="join-badge">
                <span aria-hidden="true">🌸</span>
                微笑動漫 ACG 夥伴招募中
            </div>

            <h1 class="join-title">
                加入我們，<br>
                一起把 <span>好作品</span> 分享出去
            </h1>

            <p class="join-suit-sub">
                <span aria-hidden="true">👔</span>
                喜歡動漫・擅長分享・一起讓更多人看見好作品
            </p>

            <p class="join-suit-desc">
                我們是個 <strong>3 人小團隊</strong>，正在打造一個以 ACG 為核心的動漫內容平台。<br>
                如果你喜歡<strong>分享動漫、整理情報、宣傳作品、經營社群</strong>，也想學習 AI 工具與網站實戰工作流，歡迎加入我們。
            </p>

            <div class="join-hero-actions">
                <a class="join-btn primary" href="#join-form">
                    <span aria-hidden="true">🚀</span>
                    我要加入
                </a>
                <a class="join-btn ghost" href="#join-team">
                    <span aria-hidden="true">👥</span>
                    認識團隊
                </a>
            </div>
        </section>

        <section class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Current Status</div>
                    <h2 class="join-section-title">目前團隊狀態</h2>
                    <p class="join-section-desc">
                        <span aria-hidden="true">📅</span>
                        <strong>運作方式：</strong>以分享動漫、整理情報、宣傳作品為核心。<br>
                        我們希望找到同樣喜歡 ACG 的夥伴，一起把微笑動漫做成更完整、更好逛、更有內容的動漫平台。
                    </p>
                </div>
            </div>

            <div class="join-status-grid">
                <div class="join-status-card">
                    <div class="icon">👥</div>
                    <h3>小團隊運作</h3>
                    <p>目前由 3 位管理員一起維護平台內容、作品資料與社群互動。</p>
                </div>

                <div class="join-status-card">
                    <div class="icon">📝</div>
                    <h3>內容持續擴充</h3>
                    <p>動畫新聞、作品頁、追番資訊、專題整理都會慢慢補強。</p>
                </div>

                <div class="join-status-card">
                    <div class="icon">✨</div>
                    <h3>歡迎一起參與</h3>
                    <p>只要你喜歡動漫、願意分享、想累積實戰經驗，都很適合加入。</p>
                </div>
            </div>
        </section>

        <?php if (!empty($weixiao_team_members)) : ?>
        <section id="join-team" class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Our Team</div>
                    <h2 class="join-section-title">編輯與內容團隊</h2>
                    <p class="join-section-desc">
                        本站的新聞、評論、專欄與資料整理由下列作者撰寫或編修。
                        點擊姓名可查看該作者發表過的所有文章。
                    </p>
                </div>
            </div>

            <div class="join-team-grid">
                <?php foreach ($weixiao_team_members as $member) :
                    $m_id     = (int) $member->ID;
                    $m_name   = $member->display_name ?: $member->user_login;
                    $m_bio    = trim( (string) get_user_meta( $m_id, 'description', true ) );
                    $m_url    = get_author_posts_url( $m_id );
                    $m_avatar = get_avatar_url( $m_id, array( 'size' => 220 ) );
                    $m_posts  = (int) count_user_posts( $m_id, 'post', true );
                    $m_role   = ( $m_posts >= 20 ) ? '主編輯' : '內容作者';
                    if ( $m_posts < 1 ) continue;
                ?>
                    <article class="join-team-card">
                        <div class="join-team-inner">
                            <div class="join-team-avatar-wrap">
                                <?php if (!empty($m_avatar)) : ?>
                                    <img
                                        class="join-team-avatar"
                                        src="<?php echo esc_url($m_avatar); ?>"
                                        alt="<?php echo esc_attr($m_name); ?> 的頭貼"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                <?php else : ?>
                                    <div class="join-team-avatar-fallback" aria-label="<?php echo esc_attr($m_name); ?> 的頭貼">
                                        <?php echo esc_html(mb_substr($m_name, 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <h3 class="join-team-name">
                                <a href="<?php echo esc_url($m_url); ?>" rel="author" style="color:inherit;text-decoration:none;">
                                    <?php echo esc_html($m_name); ?>
                                </a>
                            </h3>

                            <div class="join-team-role">
                                <?php echo esc_html($m_role); ?>
                            </div>

                            <?php if ($m_bio !== '') : ?>
                                <p class="join-team-desc">
                                    <?php echo esc_html($m_bio); ?>
                                </p>
                            <?php endif; ?>

                            <div class="join-team-link">
                                <a href="<?php echo esc_url($m_url); ?>" rel="author">
                                    已發表 <?php echo esc_html(number_format_i18n($m_posts)); ?> 篇文章・查看文章
                                    <span aria-hidden="true">↗</span>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Why Join Us</div>
                    <h2 class="join-section-title">為什麼加入微笑動漫？</h2>
                    <p class="join-section-desc">
                        在這裡，你可以把對動漫的喜歡變成實際作品，累積
                        <strong>內容創作經驗、社群經營經驗、AI 工具應用能力</strong>，
                        也能和一群同樣熱愛 ACG 的夥伴一起推廣好作品。
                    </p>
                </div>
            </div>

            <div class="join-cards">
                <div class="join-card">
                    <div class="emoji">💖</div>
                    <p class="main">熱愛動漫 ACG</p>
                    <p class="sub">喜歡看，也喜歡分享</p>
                </div>

                <div class="join-card">
                    <div class="emoji">📣</div>
                    <p class="main">想推廣好作品</p>
                    <p class="sub">讓更多人看見喜歡的動畫</p>
                </div>

                <div class="join-card">
                    <div class="emoji">🎓</div>
                    <p class="main">學生也很適合</p>
                    <p class="sub">累積內容、社群與網站經驗</p>
                </div>

                <div class="join-card">
                    <div class="emoji">🌐</div>
                    <p class="main">重度網路使用者</p>
                    <p class="sub">懂社群，也懂動漫圈話題</p>
                </div>

                <div class="join-card">
                    <div class="emoji">⏰</div>
                    <p class="main">願意投入時間</p>
                    <p class="sub">每週一點時間，也能一起完成很多事</p>
                </div>

                <div class="join-card">
                    <div class="emoji">🤖</div>
                    <p class="main">想學 AI 工具</p>
                    <p class="sub">一起用更有效率的方式整理與創作內容</p>
                </div>
            </div>
        </section>

        <section class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Recruitment</div>
                    <h2 class="join-section-title">目前正在尋找的夥伴</h2>
                    <p class="join-section-desc">
                        熱愛動漫 ACG 文化，願意一起整理情報、創作內容、經營社群、推廣好作品的夥伴。<br>
                        <strong>特別歡迎學生、重度網路使用者、喜歡分享動漫、想學習 AI 工具的人。</strong>
                    </p>
                </div>
            </div>

            <div class="join-notice">
                <strong>我們不會包裝得很夢幻。</strong><br>
                這是一個剛起步的動漫平台，靠的是大家對 ACG 的熱情與行動力。
                如果你想找個地方
                <strong>真正動手做內容、推廣喜歡的作品、累積實戰經驗、和同好一起把平台做起來</strong>，
                歡迎加入我們。
            </div>

            <div class="join-role-grid" style="margin-top:18px;">
                <div class="join-role-card">
                    <h3>📰 動畫新聞 / 情報整理</h3>
                    <ul>
                        <li>整理動畫新作、PV、視覺圖、聲優、播出日期等情報</li>
                        <li>協助撰寫清楚好讀的動漫新聞</li>
                        <li>確認來源與作品名稱，避免錯誤資訊</li>
                    </ul>
                </div>

                <div class="join-role-card">
                    <h3>🎬 作品資料維護</h3>
                    <ul>
                        <li>補充動畫作品頁資訊</li>
                        <li>整理標籤、播出季、官方連結、簡介</li>
                        <li>協助讓網站資料更完整、更好查</li>
                    </ul>
                </div>

                <div class="join-role-card">
                    <h3>📱 社群內容分享</h3>
                    <ul>
                        <li>協助把文章整理成社群貼文</li>
                        <li>發想動漫話題、互動文、推薦內容</li>
                        <li>讓更多人看見微笑動漫與好作品</li>
                    </ul>
                </div>

                <div class="join-role-card">
                    <h3>🎨 圖文 / 短影音協助</h3>
                    <ul>
                        <li>製作文章封面、簡易宣傳圖</li>
                        <li>整理短影音腳本或素材方向</li>
                        <li>協助提升內容呈現品質</li>
                    </ul>
                </div>

                <div class="join-role-card">
                    <h3>🤖 AI 工具應用</h3>
                    <ul>
                        <li>協助使用 AI 整理資料、優化文章架構</li>
                        <li>建立更有效率的內容工作流程</li>
                        <li>一起研究適合 ACG 平台的 AI 用法</li>
                    </ul>
                </div>

                <div class="join-role-card">
                    <h3>🧩 網站測試 / 使用回饋</h3>
                    <ul>
                        <li>協助測試網站功能與使用體驗</li>
                        <li>回報錯字、連結錯誤、資料缺漏</li>
                        <li>提供讓平台更好用的建議</li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="join-form" class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Apply Now</div>
                    <h2 class="join-section-title">填寫加入申請</h2>
                    <p class="join-section-desc">
                        如果你有興趣一起參與，簡單填寫下面表單即可。
                        不需要很正式，只要讓我們知道你喜歡什麼、想做什麼、平常常在哪些平台活動就可以。
                    </p>
                </div>
            </div>

            <?php if (!empty($join_form_message)) : ?>
                <div class="join-message <?php echo esc_attr($join_form_status); ?>">
                    <?php echo esc_html($join_form_message); ?>
                </div>
            <?php endif; ?>

            <form class="join-form" method="post" action="#join-form">
                <?php wp_nonce_field('weixiao_join_form_action', 'weixiao_join_nonce'); ?>
                <input type="hidden" name="weixiao_join_loaded_at" value="<?php echo esc_attr(time()); ?>">

                <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                    <label for="applicant_website">網站（請勿填寫）</label>
                    <input type="text" id="applicant_website" name="applicant_website" tabindex="-1" autocomplete="off">
                </div>

                <div class="join-form-grid">
                    <div class="join-field">
                        <label for="applicant_name">暱稱 / 姓名 <span style="color:var(--join-pink);">*</span></label>
                        <input
                            type="text"
                            id="applicant_name"
                            name="applicant_name"
                            required
                            placeholder="例如：小微、Faro、Kousei"
                            value="<?php echo isset($_POST['applicant_name']) ? esc_attr(wp_unslash($_POST['applicant_name'])) : ''; ?>"
                        >
                    </div>

                    <div class="join-field">
                        <label for="applicant_email">Email <span style="color:var(--join-pink);">*</span></label>
                        <input
                            type="email"
                            id="applicant_email"
                            name="applicant_email"
                            required
                            placeholder="your@email.com"
                            value="<?php echo isset($_POST['applicant_email']) ? esc_attr(wp_unslash($_POST['applicant_email'])) : ''; ?>"
                        >
                    </div>
                </div>

                <div class="join-form-grid">
                    <div class="join-field">
                        <label for="applicant_contact">其他聯絡方式</label>
                        <input
                            type="text"
                            id="applicant_contact"
                            name="applicant_contact"
                            placeholder="Discord / IG / Threads / LINE 等"
                            value="<?php echo isset($_POST['applicant_contact']) ? esc_attr(wp_unslash($_POST['applicant_contact'])) : ''; ?>"
                        >
                        <div class="join-form-help">可填寫你方便聯絡的平台。</div>
                    </div>

                    <div class="join-field">
                        <label for="applicant_role">想參與的方向 <span style="color:var(--join-pink);">*</span></label>
                        <select id="applicant_role" name="applicant_role" required>
                            <?php
                            $selected_role = isset($_POST['applicant_role']) ? sanitize_text_field(wp_unslash($_POST['applicant_role'])) : '';
                            $roles = array(
                                '' => '請選擇',
                                '動畫新聞 / 情報整理' => '動畫新聞 / 情報整理',
                                '作品資料維護' => '作品資料維護',
                                '社群內容分享' => '社群內容分享',
                                '圖文 / 短影音協助' => '圖文 / 短影音協助',
                                'AI 工具應用' => 'AI 工具應用',
                                '網站測試 / 使用回饋' => '網站測試 / 使用回饋',
                                '還不確定，想先聊聊' => '還不確定，想先聊聊',
                            );

                            foreach ($roles as $value => $label) :
                            ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_role, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="join-field">
                    <label for="applicant_intro">簡單自我介紹 <span style="color:var(--join-pink);">*</span></label>
                    <textarea
                        id="applicant_intro"
                        name="applicant_intro"
                        required
                        placeholder="可以寫你喜歡的動畫類型、平常追番習慣、想參與什麼、為什麼想加入微笑動漫。"
                    ><?php echo isset($_POST['applicant_intro']) ? esc_textarea(wp_unslash($_POST['applicant_intro'])) : ''; ?></textarea>
                </div>

                <div class="join-field">
                    <label for="applicant_links">作品 / 社群 / 參考連結</label>
                    <textarea
                        id="applicant_links"
                        name="applicant_links"
                        placeholder="如果你有寫過文章、經營社群、做過圖片、影片，或有想分享的連結，可以貼在這裡。"
                    ><?php echo isset($_POST['applicant_links']) ? esc_textarea(wp_unslash($_POST['applicant_links'])) : ''; ?></textarea>
                    <div class="join-form-help">沒有也沒關係，可以空白。</div>
                </div>

                <p class="join-form-help">
                    送出申請即表示同意我們依
                    <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" target="_blank" rel="noopener" style="color:var(--join-blue-bright);">隱私政策</a>
                    使用你填寫的聯絡資訊，僅用於招募聯繫，不會公開或作其他用途。
                </p>

                <button class="join-submit" type="submit" name="weixiao_join_form_submit" value="1">
                    送出申請 🚀
                </button>
            </form>
        </section>

        <div class="join-footer-note">
            微笑動漫是一個以 ACG 內容分享為核心的平台。<br>
            我們期待和喜歡動漫、願意一起行動的夥伴，把更多好作品介紹給更多人。
            <br><br>
            <a href="<?php echo esc_url(home_url('/about/')); ?>" style="color:var(--join-muted-2);">關於微笑動漫</a>
            ・
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" style="color:var(--join-muted-2);">聯絡／合作</a>
            ・
            <a href="<?php echo esc_url(home_url('/terms/')); ?>" style="color:var(--join-muted-2);">服務條款</a>
            ・
            <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" style="color:var(--join-muted-2);">隱私政策</a>
        </div>

    </div>
</main>

<?php
get_footer();
?>
