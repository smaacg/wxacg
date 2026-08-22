<?php
/**
 * Template Name: 加入我們
 * Description: 微笑動漫 專業領域人才招募頁面模板
 * Version: 2.1.0
 *
 * 更新內容：
 * - 2.1.0 (2026-08-19) [新增電競組 + 強化直播主/Vtuber創作者合作]
 *   - 新增「電競組（Esports / 賽事追蹤與戰隊動態）」
 *   - 強化「直播主 & Vtuber 組」：熱烈歡迎本身有在開台的直播主、遊戲實況主、個人勢/企業勢 Vtuber 加入連動合作
 *   - 升級為 11 大專業領域職缺矩陣與申請表單選項
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * SEO 標題覆寫
 */
add_filter( 'pre_get_document_title', function () {
    return '人才招募｜加入微笑動漫 專業領域團隊・共同成長';
}, 99 );

get_header();

/**
 * 編輯與內容團隊：動態抓取已發表過文章的作者
 */
$weixiao_team_members = get_users( array(
    'has_published_posts' => array( 'post' ),
    'orderby'              => 'post_count',
    'order'                => 'DESC',
    'number'               => 12,
    'exclude'              => function_exists( 'wxacg_team_excluded_user_ids' )
        ? wxacg_team_excluded_user_ids()
        : array(),
) );

/**
 * 表單處理邏輯
 */
$join_form_message = '';
$join_form_status  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['weixiao_join_form_submit'])) {
    // 防灌水：honeypot 欄位
    $is_honeypot_filled = !empty($_POST['applicant_website']);

    // 防灌水：送出間隔太短（<3 秒）
    $loaded_at          = isset($_POST['weixiao_join_loaded_at']) ? (int) $_POST['weixiao_join_loaded_at'] : 0;
    $is_submit_too_fast = $loaded_at > 0 && (time() - $loaded_at) < 3;

    if ($is_honeypot_filled || $is_submit_too_fast) {
        $join_form_status  = 'success';
        $join_form_message = '已收到你的申請！我們會閱讀你的資料與作品後再與你聯繫安排線上詳談。';
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
        $applicant_links   = isset($_POST['applicant_links']) ? sanitize_textarea_field($_POST['applicant_links']) : '';
        $applicant_intro   = isset($_POST['applicant_intro']) ? sanitize_textarea_field($_POST['applicant_intro']) : '';
        $applicant_agree   = isset($_POST['applicant_agree']) ? '已同意本站經營與共同成長理念' : '未勾選';

        if (empty($applicant_name) || empty($applicant_email) || empty($applicant_role) || empty($applicant_intro)) {
            $join_form_status  = 'error';
            $join_form_message = '請填寫必填欄位：暱稱、Email、想參與的專業組別、自我介紹。';
        } elseif (!is_email($applicant_email)) {
            $join_form_status  = 'error';
            $join_form_message = 'Email 格式不正確，請再次確認。';
        } else {
            $to      = get_option('admin_email');
            $subject = '【微笑動漫】新的夥伴申請（' . $applicant_role . '）：' . $applicant_name;

            $body  = "你收到一份新的微笑動漫夥伴加入申請：\n\n";
            $body .= "──────── 申請人資訊 ────────\n";
            $body .= "暱稱 / 姓名：{$applicant_name}\n";
            $body .= "Email：{$applicant_email}\n";
            $body .= "其他聯絡方式：{$applicant_contact}\n";
            $body .= "想加入的組別：{$applicant_role}\n";
            $body .= "理念認同狀態：{$applicant_agree}\n\n";
            $body .= "──────── 作品集 / 頻道 / 相關連結 ────────\n";
            $body .= "{$applicant_links}\n\n";
            $body .= "──────── 自我介紹 / 涉獵作品 / 想法 ────────\n";
            $body .= "{$applicant_intro}\n\n";
            $body .= "──────── 系統資訊 ────────\n";
            $body .= "送出時間：" . current_time('mysql') . "\n";
            $body .= "來源頁面：" . get_permalink() . "\n";

            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'Reply-To: ' . $applicant_name . ' <' . $applicant_email . '>',
            );

            $sent = wp_mail($to, $subject, $body, $headers);

            if ($sent) {
                $join_form_status  = 'success';
                $join_form_message = '🎉 已成功收到你的申請！我們會仔細閱讀你的資料與作品，並主動與你聯繫安排一對一線上詳談。';
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
    --join-card: rgba(255, 255, 255, 0.06);
    --join-card-hover: rgba(255, 255, 255, 0.10);
    --join-border: rgba(255, 255, 255, 0.14);
    --join-border-soft: rgba(255, 255, 255, 0.09);
    --join-border-glow: rgba(99, 168, 255, 0.35);
    --join-text: #ffffff;
    --join-muted: rgba(255, 255, 255, 0.88);
    --join-muted-2: rgba(255, 255, 255, 0.65);
    --join-pink: #ff5fa2;
    --join-purple: #9b6dff;
    --join-blue: #5fd8ff;
    --join-blue-bright: #8fe6ff;
    --join-yellow: #ffd166;
    --join-green: #7CFFB2;
    --join-orange: #ff9f43;
    --join-radius-xl: 26px;
    --join-radius-lg: 20px;
    --join-radius-md: 14px;
    --join-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.weixiao-join-page {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 10% 6%, rgba(255, 95, 162, 0.18), transparent 32%),
        radial-gradient(circle at 90% 16%, rgba(95, 216, 255, 0.15), transparent 30%),
        radial-gradient(circle at 50% 90%, rgba(155, 109, 255, 0.16), transparent 35%),
        var(--join-bg);
    color: var(--join-text);
    padding: 56px 18px 88px;
    font-family: var(--font-main, sans-serif);
}

.weixiao-join-page * {
    box-sizing: border-box;
}

.join-wrap {
    width: min(1180px, 100%);
    margin: 0 auto;
    position: relative;
    z-index: 2;
}

.join-bg-orb {
    position: absolute;
    width: 380px;
    height: 380px;
    border-radius: 999px;
    filter: blur(54px);
    opacity: 0.13;
    pointer-events: none;
    z-index: 1;
}

.join-bg-orb.one {
    background: var(--join-pink);
    left: -120px;
    top: 140px;
}

.join-bg-orb.two {
    background: var(--join-blue);
    right: -140px;
    top: 560px;
}

/* HERO SECTION */
.join-hero {
    padding: 60px 36px;
    border: 1px solid var(--join-border);
    border-radius: var(--join-radius-xl);
    background:
        linear-gradient(135deg, rgba(28, 22, 52, 0.82), rgba(14, 12, 30, 0.90)),
        rgba(10, 10, 24, 0.60);
    box-shadow: var(--join-shadow);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    text-align: center;
    overflow: visible;
    position: relative;
}

.join-hero-content {
    position: relative;
    z-index: 3;
    max-width: 860px;
    margin: 0 auto;
}

/* KYOANI FLANK CHARACTERS - FIXED ON SCREEN WINGS (NON-OVERLAPPING) */
.join-flank-character {
    position: fixed;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    pointer-events: none;
}

.join-flank-character.left {
    left: calc(50vw - 600px - 260px);
}

.join-flank-character.right {
    right: calc(50vw - 600px - 260px);
}

.join-flank-character img {
    width: clamp(200px, 14vw, 250px);
    height: auto;
    max-height: 72vh;
    object-fit: contain;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    filter: drop-shadow(0 16px 36px rgba(0, 0, 0, 0.75)) drop-shadow(0 0 25px rgba(155, 109, 255, 0.25));
}

.join-flank-character.left img {
    filter: drop-shadow(0 16px 36px rgba(0, 0, 0, 0.75)) drop-shadow(0 0 25px rgba(255, 95, 162, 0.3));
}

.join-flank-character.right img {
    filter: drop-shadow(0 16px 36px rgba(0, 0, 0, 0.75)) drop-shadow(0 0 25px rgba(95, 216, 255, 0.3));
}

.join-flank-bubble {
    margin-bottom: 14px;
    background: rgba(14, 16, 30, 0.94);
    border: 1px solid var(--join-border);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 6px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 850;
    color: #fff;
    white-space: nowrap;
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    letter-spacing: .02em;
}

.join-flank-character.left .join-flank-bubble {
    border-color: rgba(255, 95, 162, 0.5);
    color: #ffd6eb;
}

.join-flank-character.right .join-flank-bubble {
    border-color: rgba(95, 216, 255, 0.5);
    color: #d6f6ff;
}

@media (max-width: 1750px) {
    .join-flank-character.left {
        left: 10px;
    }
    .join-flank-character.right {
        right: 10px;
    }
    .join-flank-character img {
        width: 190px;
        max-height: 60vh;
    }
}

@media (max-width: 1560px) {
    .join-flank-character {
        display: none;
    }
}

.join-hero::before {
    content: "";
    position: absolute;
    inset: -2px;
    background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.14), transparent);
    transform: translateX(-100%);
    animation: joinShine 6s ease-in-out infinite;
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
    padding: 8px 18px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.10);
    border: 1px solid var(--join-border);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.25);
}

.join-title {
    margin: 0;
    font-size: clamp(32px, 5.5vw, 64px);
    line-height: 1.12;
    letter-spacing: -0.04em;
    font-weight: 950;
    color: #fff;
    text-shadow: 0 2px 20px rgba(0,0,0,0.5);
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
    font-size: clamp(17px, 2.2vw, 24px);
    font-weight: 850;
    color: var(--join-blue-bright);
    text-shadow: 0 2px 14px rgba(0,0,0,0.4);
}

.join-suit-desc {
    max-width: 860px;
    margin: 18px auto 0;
    color: var(--join-muted);
    font-size: 16px;
    line-height: 1.9;
}

.join-suit-desc strong {
    color: #fff;
}

.join-hero-actions {
    display: flex;
    justify-content: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 30px;
}

.join-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 48px;
    padding: 0 24px;
    border-radius: 999px;
    text-decoration: none;
    border: 1px solid var(--join-border);
    color: #fff;
    font-weight: 850;
    font-size: 15px;
    transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
}

.join-btn:hover {
    transform: translateY(-2px);
    color: #fff;
    border-color: rgba(255,255,255,0.35);
}

.join-btn.primary {
    background: linear-gradient(135deg, var(--join-pink), var(--join-purple));
    border-color: rgba(255,255,255,0.25);
    box-shadow: 0 14px 36px rgba(255,95,162,0.35);
}

.join-btn.ghost {
    background: rgba(255,255,255,0.08);
}

/* SECTION COMMON */
.join-section {
    margin-top: 36px;
    padding: 36px;
    border: 1px solid var(--join-border-soft);
    border-radius: var(--join-radius-xl);
    background:
        linear-gradient(135deg, rgba(22, 20, 42, 0.76), rgba(12, 12, 26, 0.84));
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 18px 50px rgba(0,0,0,0.32);
}

.join-section-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
}

.join-section-kicker {
    color: var(--join-blue-bright);
    font-size: 13px;
    font-weight: 900;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-bottom: 8px;
    text-shadow: 0 0 14px rgba(95,216,255,0.4);
}

.join-section-title {
    margin: 0;
    font-size: clamp(24px, 3vw, 34px);
    letter-spacing: -0.03em;
    font-weight: 950;
    color: #fff;
    text-shadow: 0 2px 14px rgba(0,0,0,0.4);
}

.join-section-desc {
    color: var(--join-muted);
    line-height: 1.85;
    margin: 10px 0 0;
    max-width: 820px;
    font-size: 15px;
}

.join-section-desc strong {
    color: #fff;
}

/* CORE VALUES & CONDITIONS */
.join-values-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 24px;
}

.join-value-card {
    padding: 22px;
    border-radius: var(--join-radius-lg);
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--join-border-soft);
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: transform .18s ease, border-color .18s ease;
}

.join-value-card:hover {
    transform: translateY(-3px);
    border-color: rgba(255,255,255,0.25);
}

.join-value-card .icon {
    font-size: 30px;
}

.join-value-card h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 900;
    color: #fff;
}

.join-value-card p {
    margin: 0;
    color: var(--join-muted);
    line-height: 1.7;
    font-size: 14px;
}

/* PREFERRED TRAITS BANNER */
.join-notice-box {
    padding: 24px 28px;
    border-radius: var(--join-radius-lg);
    border: 1px solid rgba(124, 255, 178, 0.35);
    background: linear-gradient(135deg, rgba(124, 255, 178, 0.12), rgba(95, 216, 255, 0.08));
    color: rgba(255,255,255,0.95);
    line-height: 1.85;
    margin-bottom: 24px;
}

.join-notice-box h3 {
    margin: 0 0 10px;
    font-size: 19px;
    font-weight: 950;
    color: var(--join-green);
    display: flex;
    align-items: center;
    gap: 8px;
}

.join-notice-box ul {
    margin: 8px 0 0;
    padding-left: 20px;
    line-height: 1.85;
}

.join-notice-box li {
    margin-bottom: 6px;
}

.join-notice-box strong {
    color: #fff;
}

/* ROLES GRID */
.join-roles-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
}

.join-role-card {
    padding: 24px;
    border-radius: var(--join-radius-lg);
    border: 1px solid var(--join-border-soft);
    background: rgba(255, 255, 255, 0.055);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform .18s ease, border-color .18s ease, background .18s ease;
    position: relative;
    overflow: hidden;
}

.join-role-card:hover {
    transform: translateY(-3px);
    border-color: rgba(255, 255, 255, 0.28);
    background: rgba(255, 255, 255, 0.085);
}

.join-role-card.featured {
    border: 1px solid rgba(255, 95, 162, 0.45);
    background: linear-gradient(135deg, rgba(255, 95, 162, 0.12), rgba(155, 109, 255, 0.08));
    box-shadow: 0 8px 30px rgba(255, 95, 162, 0.15);
}

.join-role-card.featured::before {
    content: "★ 核心合作・需詳談";
    position: absolute;
    top: 14px;
    right: 14px;
    background: linear-gradient(135deg, var(--join-pink), var(--join-purple));
    color: #fff;
    font-size: 11px;
    font-weight: 850;
    padding: 4px 10px;
    border-radius: 999px;
    letter-spacing: .04em;
}

.join-role-header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 12px;
}

.join-role-icon {
    font-size: 32px;
    flex-shrink: 0;
    line-height: 1;
}

.join-role-title-wrap {
    flex: 1;
}

.join-role-title {
    margin: 0;
    font-size: 20px;
    font-weight: 950;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.join-role-tag {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 750;
    background: rgba(255,255,255,0.1);
    color: var(--join-blue-bright);
    border: 1px solid rgba(255,255,255,0.12);
}

.join-role-desc {
    color: var(--join-muted);
    font-size: 14px;
    line-height: 1.8;
    margin-bottom: 14px;
}

.join-role-duties {
    margin: 0 0 16px;
    padding-left: 18px;
    color: var(--join-muted);
    font-size: 14px;
    line-height: 1.8;
}

.join-role-duties li {
    margin-bottom: 4px;
}

.join-role-traits {
    padding: 10px 14px;
    border-radius: var(--join-radius-md);
    background: rgba(0, 0, 0, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 13px;
    line-height: 1.6;
    color: var(--join-muted-2);
}

.join-role-traits strong {
    color: var(--join-yellow);
}

/* TEAM SHOWCASE */
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
    transition: transform .18s ease, border-color .18s ease;
}

.join-team-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255,255,255,0.3);
}

.join-team-inner {
    position: relative;
    z-index: 2;
}

.join-team-avatar-wrap {
    width: 108px;
    height: 108px;
    border-radius: 999px;
    padding: 4px;
    margin: 0 auto 16px;
    background: linear-gradient(135deg, var(--join-pink), var(--join-blue), var(--join-purple));
    box-shadow: 0 14px 30px rgba(0,0,0,0.35);
}

.join-team-avatar {
    display: block;
    width: 100px;
    height: 100px;
    border-radius: 999px;
    object-fit: cover;
    background: rgba(255,255,255,0.14);
    border: 3px solid rgba(7,8,20,0.92);
}

.join-team-avatar-fallback {
    width: 100px;
    height: 100px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    font-weight: 950;
    color: #fff;
    background: linear-gradient(135deg, rgba(255,95,162,0.8), rgba(155,109,255,0.8));
    border: 3px solid rgba(7,8,20,0.92);
}

.join-team-name {
    text-align: center;
    margin: 0;
    font-size: 21px;
    font-weight: 950;
    letter-spacing: -0.02em;
    color: #fff;
}

.join-team-role {
    margin: 6px auto 0;
    text-align: center;
    color: var(--join-blue-bright);
    font-size: 14px;
    font-weight: 850;
}

.join-team-desc {
    margin: 12px 0 0;
    color: var(--join-muted);
    font-size: 14px;
    line-height: 1.8;
    text-align: center;
}

.join-team-link {
    display: flex;
    justify-content: center;
    margin-top: 16px;
}

.join-team-link a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 38px;
    padding: 0 16px;
    border-radius: 999px;
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 850;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.16);
    transition: transform .18s ease, background .18s ease;
}

.join-team-link a:hover {
    transform: translateY(-2px);
    color: #fff;
    background: rgba(255,255,255,0.18);
}

/* FORM STYLING */
.join-form {
    display: grid;
    gap: 18px;
}

.join-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
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
    padding: 14px 16px;
    outline: none;
    transition: border-color .18s ease, background .18s ease;
    font-family: inherit;
    font-size: 15px;
    line-height: normal;
}

.join-field select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 16px center;
    padding-right: 42px;
}

.join-field input::placeholder,
.join-field textarea::placeholder {
    color: rgba(255,255,255,0.45);
}

.join-field input:focus,
.join-field select:focus,
.join-field textarea:focus {
    border-color: rgba(95,216,255,0.65);
    background: rgba(255,255,255,0.12);
    box-shadow: 0 0 0 3px rgba(95, 216, 255, 0.15);
}

.join-field textarea {
    min-height: 130px;
    resize: vertical;
}

.join-field select option {
    color: #111;
    background-color: #fff;
}

.join-form-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 16px;
    border-radius: var(--join-radius-md);
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--join-muted);
    font-size: 14px;
    line-height: 1.6;
    cursor: pointer;
}

.join-form-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    accent-color: var(--join-pink);
    cursor: pointer;
    flex-shrink: 0;
}

.join-form-help {
    margin-top: 6px;
    color: var(--join-muted-2);
    font-size: 13px;
    line-height: 1.6;
}

.join-message {
    padding: 16px 20px;
    border-radius: 16px;
    line-height: 1.7;
    font-weight: 800;
    margin-bottom: 20px;
}

.join-message.success {
    background: rgba(124,255,178,0.16);
    border: 1px solid rgba(124,255,178,0.36);
    color: #d9ffe8;
}

.join-message.error {
    background: rgba(255,95,95,0.16);
    border: 1px solid rgba(255,95,95,0.36);
    color: #ffe1e1;
}

.join-submit {
    border: 0;
    cursor: pointer;
    min-height: 52px;
    padding: 0 28px;
    border-radius: 999px;
    color: #fff;
    font-size: 16px;
    font-weight: 950;
    background: linear-gradient(135deg, var(--join-pink), var(--join-purple));
    box-shadow: 0 14px 34px rgba(255,95,162,0.32);
    transition: transform .18s ease, box-shadow .18s ease;
    justify-self: start;
}

.join-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 44px rgba(255,95,162,0.40);
}

.join-footer-note {
    text-align: center;
    color: var(--join-muted-2);
    font-size: 14px;
    line-height: 1.9;
    margin-top: 36px;
}

/* RESPONSIVE */
@media (max-width: 960px) {
    .join-values-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .join-roles-grid,
    .join-team-grid {
        grid-template-columns: 1fr;
    }

    .join-form-grid {
        grid-template-columns: 1fr;
    }

    .join-section-head {
        display: block;
    }
}

@media (max-width: 580px) {
    .weixiao-join-page {
        padding: 36px 14px 64px;
    }

    .join-hero,
    .join-section {
        padding: 26px 18px;
        border-radius: 20px;
    }

    .join-title {
        font-size: 32px;
    }

    .join-suit-sub {
        font-size: 18px;
    }

    .join-suit-desc {
        font-size: 15px;
    }

    .join-values-grid {
        grid-template-columns: 1fr;
    }

    .join-submit {
        width: 100%;
    }
}
</style>

<main class="weixiao-join-page">
    <div class="join-bg-orb one"></div>
    <div class="join-bg-orb two"></div>

    <!-- 京阿尼畫風 左右兩側守護人物（螢幕兩側側翼） -->
    <div class="join-flank-character left">
        <div class="join-flank-bubble">歡迎你的加入！🌸</div>
        <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/kyoani-left.png?v=5'); ?>" alt="京阿尼畫風 歡迎看板娘" loading="lazy">
    </div>

    <div class="join-flank-character right">
        <div class="join-flank-bubble">和我們一起創作吧！✨</div>
        <img src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/kyoani-right.png?v=5'); ?>" alt="京阿尼畫風 創作看板娘" loading="lazy">
    </div>

    <div class="join-wrap">

        <!-- HERO BANNER -->
        <section class="join-hero">
            <div class="join-hero-content">
                <div class="join-badge">
                    <span aria-hidden="true">🌸</span>
                    微笑動漫 專業領域夥伴招募中
                </div>

                <h1 class="join-title">
                    加入微笑動漫，<br>
                    與我們 <span>共同成長</span>・共創 ACG 新生態
                </h1>

                <p class="join-suit-sub">
                    <span aria-hidden="true">🌍</span>
                    不限國籍・年齡・居住地｜只要能使用中文溝通，歡迎全球 ACG 同好！
                </p>

                <p class="join-suit-desc">
                    我們正在打造一個專注於<strong>深度動漫評測、跨領域 ACG 資料庫與高品質動漫社群</strong>的長遠生態平台。<br>
                    <strong>加入零壓力、完全遠端非同步</strong>。不論你身在台灣、香港、日本、馬來西亞、韓國、中國、美國或世界各地，只要<strong>熱愛分享、認同本站理念，並期待隨著團隊一起長期成長</strong>，都歡迎成為我們的核心夥伴。
                </p>

                <div class="join-hero-actions">
                    <a class="join-btn primary" href="#join-form">
                        <span aria-hidden="true">🚀</span>
                        立即申請詳談
                    </a>
                    <a class="join-btn ghost" href="#join-roles">
                        <span aria-hidden="true">🔍</span>
                        查看各專業領域組別
                    </a>
                </div>
            </div>
        </section>

        <!-- CORE VALUES -->
        <section class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Core Philosophy</div>
                    <h2 class="join-section-title">我們的合作理念與加分特質</h2>
                    <p class="join-section-desc">
                        我們重視每一位夥伴的熱情、自主性與實戰成長，不設僵化框架，重視個人與團隊的共同成長，尋找志同道合、願意長期同行的朋友。
                    </p>
                </div>
            </div>

            <div class="join-values-grid">
                <div class="join-value-card">
                    <div class="icon">🌿</div>
                    <h3>加入零壓力</h3>
                    <p>遠端非同步協作，無硬性 KPI 與考核壓力，依照個人生活與學業節奏自由安排產出。</p>
                </div>

                <div class="join-value-card">
                    <div class="icon">🎯</div>
                    <h3>認同經營理念</h3>
                    <p>堅持原創高價值內容與真實考證，拒絕粗製濫造與農場搬運，共同守護純粹乾淨的動漫閱讀體驗。</p>
                </div>

                <div class="join-value-card">
                    <div class="icon">📈</div>
                    <h3>隨著團隊成長</h3>
                    <p>平台正在持續擴展與迭代，期待夥伴抱持共同願景，一起見證平台從小做大、建立個人專屬影響力。</p>
                </div>

                <div class="join-value-card">
                    <div class="icon">💬</div>
                    <h3>全部皆需詳談</h3>
                    <p>所有申請皆由站長一對一深度線上交流，確認彼此理念與發揮空間，找到最舒服的合作默契。</p>
                </div>
            </div>

            <div class="join-notice-box">
                <h3><span aria-hidden="true">✨</span> 夥伴特質與合作條件：</h3>
                <ul>
                    <li><strong>🌍 不限國籍、年齡與居住地：</strong>無論你在台灣、香港、日本、馬來西亞、韓國、中國、美國或其他世界各地，只要能以中文順暢溝通與創作，都熱烈歡迎！</li>
                    <li><strong>💖 本身無經濟壓力、純粹樂在分享者：</strong>這是一個以熱情為本、追求長遠價值的共創天地，適合真心喜愛 ACG、享受將好作品推薦給大眾的朋友。</li>
                    <li><strong>🎓 學生族群大歡迎：</strong>彈性自由的時間安排，能讓你於課餘期間累積真實大型網站專案、專屬具名專欄與社群營運的實戰作品集。</li>
                    <li><strong>🤖 願意學習與使用 AI 工具者：</strong>本站具備先進的 AI 自動化輔助工作流，樂於擁抱新工具、藉助 AI 提升整理與創作效率的夥伴能更快上手！</li>
                </ul>
            </div>
        </section>

        <!-- DOMAIN TEAMS -->
        <section id="join-roles" class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Open Positions</div>
                    <h2 class="join-section-title">專業領域招募組別</h2>
                    <p class="join-section-desc">
                        無論你在哪一個 ACG 領域鑽研已久，只要有熱情與分享慾，這裡都有你專屬的舞台。
                    </p>
                </div>
            </div>

            <div class="join-roles-grid">
                <!-- 1. 新聞組 / 特派記者 (NEW) -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">📰</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">新聞組 / 特派記者 <span class="join-role-tag">News & Press</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">負責 ACG 業界重大新聞編採、深度專題報導，未來可配合參與線下發布會、見面會與展會現場採訪。</p>
                        <ul class="join-role-duties">
                            <li><strong>內情報導：</strong>追蹤國內外 ACG 業界重大事件、版權發行、產業趨勢快訊與深度專題撰寫</li>
                            <li><strong>外情採訪：</strong>配合線下記者會、聲優/作者見面會、首映會與展會活動進行現場採訪與第一手取材</li>
                            <li>具備嚴謹客觀的新聞求證態度，傳遞精準、有價值且即時的動漫新聞</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>形象佳、口條清晰、具備良好人際溝通與採訪應變能力、具文字功底且未來可配合線下採訪。
                    </div>
                </div>

                <!-- 2. 動漫組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🎬</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">動漫組 <span class="join-role-tag">Anime</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛動漫、每季追番量大，負責新番追蹤、情報整理與動畫深度短評。</p>
                        <ul class="join-role-duties">
                            <li>追蹤當季動畫新作、PV、主視覺圖、聲優陣容與播出排程</li>
                            <li>撰寫流暢且具主觀鑑賞點的原創動畫短評與專題推薦</li>
                            <li>補充與維護作品頁深度資訊，杜絕錯誤情報</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>熱愛看動漫、有在持續追番、能客觀整理情報並具備良好文字表達力。
                    </div>
                </div>

                <!-- 2. 漫畫組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">📖</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">漫畫組 <span class="join-role-tag">Manga</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛漫畫研究，負責連載動態追蹤、單行本資訊整理與寶藏作品推廣。</p>
                        <ul class="join-role-duties">
                            <li>整理熱門與小眾漫畫連載最新進度、出版情報與獲獎榜單</li>
                            <li>撰寫漫畫推坑指南、名場面評析與單行本發售資訊</li>
                            <li>協助建立涵蓋日漫、條漫與台漫的多元作品推薦庫</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>漫畫閱讀量大、熟悉各大出版社連載平台、熱衷把優秀漫畫介紹給更多人。
                    </div>
                </div>

                <!-- 3. 小說組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">📚</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">小說組 <span class="join-role-tag">Light Novel</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛讀輕小說，負責文庫新作動態、原作書評撰寫與動畫改編考據。</p>
                        <ul class="join-role-duties">
                            <li>追蹤角川、GA、電擊文庫等各大文庫新書出版與改編情報</li>
                            <li>撰寫原作輕小說深度書評、世界觀解析與角色心路歷程</li>
                            <li>針對動畫化作品進行「原作 vs. 動畫改編」對比考證專題</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>重度輕小說讀者、文字細膩、對作品世界觀與文字設定有深刻理解。
                    </div>
                </div>

                <!-- 4. 音樂組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🎵</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">音樂組 <span class="join-role-tag">Music</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛動漫音樂，負責 OP/ED/OST 整理、聲優歌手動態與演唱會情報。</p>
                        <ul class="join-role-duties">
                            <li>每季整理新番主題曲（OP/ED/插曲）、單曲發售日與製作陣容</li>
                            <li>追蹤動漫聲優歌手、VOCALOID 創作者、Anisong 演唱會最新動態</li>
                            <li>整理特色歌單、神曲背景考據與音樂專題介紹</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>熱愛 ACG 音樂、耳朵靈敏、熟悉動漫歌手或作曲家、樂於分享聽後感。
                    </div>
                </div>

                <!-- 5. 遊戲組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🎮</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">遊戲組 <span class="join-role-tag">Galgame & Games</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">深耕 Galgame 與 ACG 遊戲，懂市場動態、情報規劃與玩家社群喜好。</p>
                        <ul class="join-role-duties">
                            <li>整理美少女遊戲（Galgame）、視覺小說（VN）、二次元手遊與家機最新情報</li>
                            <li>撰寫具深度與情懷的遊戲評測、劇本賞析與聲優陣容介紹</li>
                            <li>掌握 ACG 遊戲市場脈動與展會新品動態</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>本身有接觸 Galgame 或二次元遊戲、懂市場生態、具備清晰鑑賞視野。
                    </div>
                </div>

                <!-- 6. 電競組 (NEW) -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🏆</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">電競組 <span class="join-role-tag">Esports</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛電子競技與競技遊戲，負責各大賽事追蹤、戰隊選手動態與電競文化評析。</p>
                        <ul class="join-role-duties">
                            <li>追蹤國內外熱門電競賽事（如 LOL、瓦羅蘭/特戰英豪、格鬥遊戲 EVO、二次元競技對戰）最新賽況</li>
                            <li>整理戰隊動態、選手轉會情報、版本 Meta 趨勢與精彩賽事精華</li>
                            <li>撰寫深入且客觀的電競觀賽評析與熱門話題推廣</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>熱愛觀看或參與電競賽事、熟悉戰隊選手生態、具備敏銳的賽事觀察與整理能力。
                    </div>
                </div>

                <!-- 7. 直播主 & Vtuber 組 (ENHANCED) -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🎙️</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">直播主 & Vtuber 組 <span class="join-role-tag">Streamer & Vtuber</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱烈歡迎「本身有在開台的實況主/直播主/Vtuber 創作者」連動合作，以及深愛 Vtuber 文化的情報整理夥伴。</p>
                        <ul class="join-role-duties">
                            <li><strong>實況主/Vtuber 創作者：</strong>與微笑動漫進行聯合直播、新番/遊戲同樂、專欄連動與社群曝光推廣</li>
                            <li><strong>情報整理者：</strong>追蹤 Hololive、NIJISANJI 及海內外個人勢重大活動、新衣裝與演唱會</li>
                            <li>共同規劃直播精華、專案企劃與推動友善活躍的 ACG 實況生態</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>本身為直播主/實況主/Vtuber，或深度沉浸 Vtuber 文化，熱愛社群互動與內容創作。
                    </div>
                </div>

                <!-- 8. Cosplay 組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">👗</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">Cosplay 組 <span class="join-role-tag">Cosplay</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛 Cosplay 文化且本身有在出角，負責角色還原分享與玩家交流推廣。</p>
                        <ul class="join-role-duties">
                            <li>分享角色扮演心得、服裝假髮道具製作技術與妝容技巧</li>
                            <li>整理展場出角速報、攝影打光技巧與 Coser 專訪推薦</li>
                            <li>促進健康友善的 Cosplay 交流氛圍與二次元文化傳播</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>本身有在玩 Cosplay、熱愛角色還原、具備社群交流與分享熱忱。
                    </div>
                </div>

                <!-- 9. AI 創作與品牌推廣組 (NEW) -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🤖</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">AI 創作與品牌推廣組 <span class="join-role-tag">AI Creators</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛使用 AI 工具製作動漫作品，抱持正能量分享心態，樂於運用創新內容協助推廣微笑動漫品牌。</p>
                        <ul class="join-role-duties">
                            <li>運用各類 AI 工具（圖像/影音/音樂/文本）創作二次元相關原創作品與趣味企劃</li>
                            <li>以健康積極、正能量的視角分享 AI × ACG 創作心得與前沿探索</li>
                            <li>協助製作微笑動漫品牌宣傳素材、主題視覺衍生與社群推廣內容</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>有在使用 AI 工具進行創作、心態健康正能量、熱愛動漫文化並樂於協助品牌宣傳。
                    </div>
                </div>

                <!-- 10. 主視覺繪師組 (FEATURED) -->
                <div class="join-role-card featured">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🎨</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">本站主視覺繪師組 <span class="join-role-tag" style="color:var(--join-pink);border-color:var(--join-pink);">Visual Artist</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">★ 核心特邀合作・需詳談。配合本站成長步伐，協助設計專屬人物與節慶主視覺。</p>
                        <ul class="join-role-duties">
                            <li>協助設計微笑動漫專屬看板娘、吉祥物與特色人物設定</li>
                            <li>繪製網站節慶主題 Banner、專題插畫與品牌周邊視覺</li>
                            <li>與團隊共同打造具辨識度且極具二次元魅力的官方視覺體系</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>畫工優秀成熟、具備日系 ACG 潮流畫風，能配合平台成長長遠合作（需附作品集）。
                    </div>
                </div>

                <!-- 10. 聖地巡禮組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">⛩️</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">聖地巡禮組 <span class="join-role-tag">Pilgrimage</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">熱愛生活與動漫，曾實際跑點動漫取景地，享受並能分享照片與詳細攻略。</p>
                        <ul class="join-role-duties">
                            <li>分享親身走訪日本或各國動漫實景（如秩父、宇治、秋葉原等）之圖文遊記</li>
                            <li>整理精準的比對照片、交通指南、實用路線與避坑建議</li>
                            <li>讓熱愛動畫的同好也能跟隨你的腳步踏上跨次元的浪漫旅程</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>熱愛旅行與動漫、曾實際跑點過取景地、享受拍照並樂於分享攻略。
                    </div>
                </div>

                <!-- 11. 展場與動漫文化推廣組 -->
                <div class="join-role-card">
                    <div>
                        <div class="join-role-header">
                            <div class="join-role-icon">🎪</div>
                            <div class="join-role-title-wrap">
                                <h3 class="join-role-title">展場與文化推廣組 <span class="join-role-tag">Events & Expo</span></h3>
                            </div>
                        </div>
                        <p class="join-role-desc">活躍於各大動漫展會，負責第一手現場取材、展覽快報與優秀情報整理。</p>
                        <ul class="join-role-duties">
                            <li>整理 FF 開拓動漫祭、CWT、台北動漫節/漫博、Comiket 等活動資訊</li>
                            <li>展會現場速報、同人社團創作推薦與特色攤位介紹</li>
                            <li>策劃推廣各類動漫文化活動，讓更多優質創作者與作品被看見</li>
                        </ul>
                    </div>
                    <div class="join-role-traits">
                        <strong>期待特質：</strong>常跑各類動漫展會、現場感受力強、具備敏銳的情報蒐集與分享熱情。
                    </div>
                </div>
            </div>
        </section>

        <!-- TEAM SHOWCASE -->
        <?php if (!empty($weixiao_team_members)) : ?>
        <section id="join-team" class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Our Team</div>
                    <h2 class="join-section-title">現有編輯與內容團隊</h2>
                    <p class="join-section-desc">
                        本站的新聞、短評、專欄與資料整理由下列作者共同維護。點擊姓名可查看該作者發表過的所有文章。
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
                    if ( strtolower( (string) $member->user_login ) === 'kousei' || $m_name === 'Kousei' ) {
                        $m_role = '日文翻譯人員 + 洽談公關';
                    } elseif ( $m_posts >= 20 ) {
                        $m_role = '核心編輯';
                    } else {
                        $m_role = '專欄作者';
                    }
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
                                    已發表 <?php echo esc_html(number_format_i18n($m_posts)); ?> 篇文章・查看專欄
                                    <span aria-hidden="true">↗</span>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- APPLICATION FORM -->
        <section id="join-form" class="join-section">
            <div class="join-section-head">
                <div>
                    <div class="join-section-kicker">Apply For Discussion</div>
                    <h2 class="join-section-title">填寫加入與詳談申請</h2>
                    <p class="join-section-desc">
                        請簡單填寫以下資訊，讓我們認識你的興趣、涉獵方向、頻道或作品。收到後我們將主動安排一對一線上詳談！
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
                        <label for="applicant_name">暱稱 / 姓名 / 實況台稱呼 <span style="color:var(--join-pink);">*</span></label>
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
                            placeholder="Discord / IG / X (Twitter) / LINE 等"
                            value="<?php echo isset($_POST['applicant_contact']) ? esc_attr(wp_unslash($_POST['applicant_contact'])) : ''; ?>"
                        >
                        <div class="join-form-help">方便我們私訊聯繫你安排線上詳談。</div>
                    </div>

                    <div class="join-field">
                        <label for="applicant_role">想加入的專業組別 <span style="color:var(--join-pink);">*</span></label>
                        <select id="applicant_role" name="applicant_role" required>
                            <?php
                            $selected_role = isset($_POST['applicant_role']) ? sanitize_text_field(wp_unslash($_POST['applicant_role'])) : '';
                            $roles = array(
                                '' => '請選擇主要感興趣的組別',
                                '📰 新聞組 / 特派記者（業界新聞 / 線下採訪）' => '📰 新聞組 / 特派記者（業界新聞 / 線下採訪）',
                                '🎬 動漫組（情報整理 / 短評撰寫）' => '🎬 動漫組（情報整理 / 短評撰寫）',
                                '📖 漫畫組（連載追蹤 / 寶藏推薦）' => '📖 漫畫組（連載追蹤 / 寶藏推薦）',
                                '📚 小說組（輕小說書評 / 改編考證）' => '📚 小說組（輕小說書評 / 改編考證）',
                                '🎵 音樂組（動漫音樂 / 聲優情報）' => '🎵 音樂組（動漫音樂 / 聲優情報）',
                                '🎮 遊戲組（Galgame / ACG遊戲市場）' => '🎮 遊戲組（Galgame / ACG遊戲市場）',
                                '🏆 電競組（電競賽事 / 戰隊動態）' => '🏆 電競組（電競賽事 / 戰隊動態）',
                                '🎙️ 直播主 & Vtuber 組（實況主連動 / 虛擬主播）' => '🎙️ 直播主 & Vtuber 組（實況主連動 / 虛擬主播）',
                                '👗 Cosplay 組（角色還原 / 玩家交流）' => '👗 Cosplay 組（角色還原 / 玩家交流）',
                                '🤖 AI 創作與品牌推廣組（動漫AI作品 / 品牌宣傳）' => '🤖 AI 創作與品牌推廣組（動漫AI作品 / 品牌宣傳）',
                                '🎨 主視覺繪師組（看板娘 / 人物設計 ★核心合作）' => '🎨 主視覺繪師組（看板娘 / 人物設計 ★核心合作）',
                                '⛩️ 聖地巡禮組（實景走訪 / 攻略分享）' => '⛩️ 聖地巡禮組（實景走訪 / 攻略分享）',
                                '🎪 展場文化推廣組（展會取材 / 情報整理）' => '🎪 展場文化推廣組（展會取材 / 情報整理）',
                                '✨ 跨組別 / 想先線上聊聊探討' => '✨ 跨組別 / 想先線上聊聊探討',
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
                    <label for="applicant_links">作品集 / 直播頻道 / 專欄 / 社群 / 攝影 / 繪圖連結</label>
                    <textarea
                        id="applicant_links"
                        name="applicant_links"
                        placeholder="直播主/Vtuber請附上 YouTube/Twitch 頻道連結；繪師請附上 Pixiv / ArtStation / X / 作品集；專欄/社群/Cosplay/巡禮夥伴亦歡迎貼上您的粉專、IG、個人部落格或曾寫過的文章/照片連結。"
                    ><?php echo isset($_POST['applicant_links']) ? esc_textarea(wp_unslash($_POST['applicant_links'])) : ''; ?></textarea>
                    <div class="join-form-help">若暫無公開作品集亦可空白，後續詳談時交流。</div>
                </div>

                <div class="join-field">
                    <label for="applicant_intro">自我介紹、常看作品與想法 <span style="color:var(--join-pink);">*</span></label>
                    <textarea
                        id="applicant_intro"
                        name="applicant_intro"
                        required
                        placeholder="可以聊聊：&#10;1. 平常最熱衷的 ACG 作品、遊戲、直播或電競領域&#10;2. 目前身分（例如學生、創作者/直播主、自由工作者、上班族）&#10;3. 是否有使用過或想學習 AI 工具&#10;4. 為什麼想加入微笑動漫，期待一起完成什麼樣的內容？"
                    ><?php echo isset($_POST['applicant_intro']) ? esc_textarea(wp_unslash($_POST['applicant_intro'])) : ''; ?></textarea>
                </div>

                <label class="join-form-checkbox">
                    <input type="checkbox" name="applicant_agree" value="1" required checked>
                    <span>我已了解本站為<strong>無壓力自由協作模式</strong>，認同微笑動漫的<strong>經營與創作理念</strong>，並願意隨著團隊共同成長。</span>
                </label>

                <p class="join-form-help">
                    送出申請即表示同意我們依
                    <a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" target="_blank" rel="noopener" style="color:var(--join-blue-bright);text-decoration:underline;">隱私政策</a>
                    使用你填寫的聯絡資訊，資料僅用於招募聯繫與線上詳談，絕不公開或挪作他用。
                </p>

                <button class="join-submit" type="submit" name="weixiao_join_form_submit" value="1">
                    送出申請・預約詳談 🚀
                </button>
            </form>
        </section>

        <!-- FOOTER NOTE -->
        <div class="join-footer-note">
            微笑動漫是一個以 ACG 原創內容與深度資料庫為核心的同好生態平台。<br>
            我們期待與每一位熱愛動漫、抱持共同理念的夥伴，一起把平台打造成更有深度、更有溫度的華文 ACG 殿堂。
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
