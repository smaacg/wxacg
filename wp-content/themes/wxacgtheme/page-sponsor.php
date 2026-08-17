<?php
/**
 * Template Name: 贊助頁面（極簡版）
 * Template Post Type: page
 * Path: wp-content/themes/blocksy-child/page-sponsor.php
 * Version: 3.3.0 (2026-07-06)
 *
 * 變更紀錄：
 * - v3.3.0 綠界付款卡置頂：移至 Hero 正下方，成為第一眼看到的內容；
 *          id="plan" 隨之上移；移除原贊助方案說明區（避免重複與 id 衝突）
 * - v3.2.0 綠界金流正式上線：即將上線卡片改為含贊助按鈕 + QR Code 的
 *          已開放卡片（連結 https://p.ecpay.com.tw/64EA58B）
 * - v3.1.0 移除贊助分級（yearly/monthly/coffee）
 *          石碑統一刻名，一視同仁
 * - v3.0.0 黑金直立紀念碑、陪伴感 Hero 文案
 * - v2.x   砍 40% 內容；綠界即將上線
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
 *  ✏️ 贊助者名單（手動維護）
 *
 *  欄位說明：
 *   name    顯示名稱；填 '' 時自動顯示為「匿名贊助者」
 *   date    'YYYY-MM'
 *   message 選填，30 字內留言；不顯示就留空
 *
 *  排序：最新贊助會自動顯示在最上方
     array(
        'name'    => '',
        'date'    => '',
        'message' => '',
    ),
 *
 *  ⚠️ 以下為示範資料，正式上線請刪除或改成真實名單
 * ========================================================= */
$sponsors = array(


);

/*
 * SEO 標題。
 *
 * 這個頁面在後台的標題是英文 slug「sponsor」，直接輸出會變成
 * 「sponsor - 微笑動漫」，與站內其他頁面的中文標題不一致。
 * 沿用 page-bangumi*.php 既有的 pre_get_document_title 作法覆寫，
 * 不動後台頁面標題（避免連帶影響選單與頁面上的 H1）。
 */
add_filter( 'pre_get_document_title', function () {
	return '贊助支持｜讓微笑動漫持續運作';
}, 99 );

get_header();
?>

<!-- 載入襯線字體（碑文質感） -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+TC:wght@500;700;900&family=Cinzel:wght@400;600&display=swap" rel="stylesheet">

<style>
  /* ====== SPONSOR PAGE v3.3.0 ====== */

  .sponsor-page-wrap { position: relative; overflow: hidden; }

  /* ── Hero ── */
  .sponsor-hero {
    min-height: 62vh;
    display: flex; align-items: center;
    position: relative; overflow: hidden;
    padding: 100px 0 60px;
  }
  .sponsor-hero-bg {
    position: absolute; inset: 0; z-index: 0;
    background:
      radial-gradient(ellipse 80% 60% at 50% -10%, rgba(99,102,241,0.28) 0%, transparent 70%),
      radial-gradient(ellipse 50% 40% at 80% 60%, rgba(139,92,246,0.18) 0%, transparent 60%),
      var(--grad-bg);
  }
  .sponsor-hero-content {
    position: relative; z-index: 1;
    text-align: center; max-width: 740px;
    margin: 0 auto; padding: 0 var(--space-lg);
  }
  .sponsor-eyebrow {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(99,102,241,0.18);
    border: 1px solid rgba(99,102,241,0.35);
    border-radius: var(--radius-pill);
    padding: 6px 18px;
    font-size: 12px; font-weight: 700;
    color: #a5b4fc;
    letter-spacing: .08em; text-transform: uppercase;
    margin-bottom: 24px;
  }
  .sponsor-hero-title {
    font-size: clamp(26px, 4.8vw, 44px);
    font-weight: 900; line-height: 1.3;
    color: var(--text-primary);
    margin: 0 0 24px; letter-spacing: -.01em;
  }
  .sponsor-hero-title .grad {
    background: linear-gradient(135deg, #818cf8, #c084fc, #f472b6);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .sponsor-hero-sub {
    font-size: 16px; color: var(--text-secondary);
    line-height: 2; margin: 0 auto 32px;
    max-width: 600px;
  }
  .sponsor-hero-sub strong {
    color: var(--text-primary); font-weight: 700;
  }
  .sponsor-hero-sub .accent {
    color: #d4af6f; font-weight: 700;
  }
  .sponsor-hero-cta {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 14px 36px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    border-radius: var(--radius-pill);
    font-size: 15px; font-weight: 700;
    text-decoration: none;
    transition: var(--trans-smooth);
    box-shadow: 0 8px 32px rgba(99,102,241,0.45);
  }
  .sponsor-hero-cta:hover {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    transform: translateY(-3px);
    box-shadow: 0 14px 48px rgba(99,102,241,0.55);
    color: #fff;
  }

  /* ── 共用標題 ── */
  .section-eyebrow {
    font-size: 11px; font-weight: 700;
    letter-spacing: .12em; text-transform: uppercase;
    color: #a5b4fc; margin-bottom: 12px;
  }
  .section-h2 {
    font-size: clamp(24px, 4vw, 32px);
    font-weight: 800; color: var(--text-primary);
    margin: 0 0 16px; letter-spacing: -.01em;
  }

  /* ── WHY ── */
  .why-section { padding: 70px 0; }
  .why-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px; margin: 40px auto 0;
    max-width: 900px;
  }
  .why-card {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-xl);
    padding: 28px 24px;
    backdrop-filter: blur(var(--glass-blur));
    -webkit-backdrop-filter: blur(var(--glass-blur));
    transition: var(--trans-smooth);
  }
  .why-card:hover {
    transform: translateY(-4px);
    border-color: rgba(99,102,241,0.4);
    box-shadow: 0 12px 40px rgba(99,102,241,0.15);
  }
  .why-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; margin-bottom: 14px;
  }
  .why-card h3 {
    font-size: 16px; font-weight: 700;
    color: var(--text-primary); margin: 0 0 8px;
  }
  .why-card p {
    font-size: 13px; color: var(--text-secondary);
    line-height: 1.75; margin: 0;
  }

  /* ── 贊助方案 ── */
  .plan-section { padding: 60px 0; }
  .plan-single {
    max-width: 460px; margin: 40px auto 0;
    background: linear-gradient(135deg, rgba(99,102,241,0.12), rgba(139,92,246,0.08));
    border: 1px solid rgba(99,102,241,0.4);
    border-radius: var(--radius-xl);
    padding: 36px 32px; text-align: center;
    backdrop-filter: blur(var(--glass-blur));
    box-shadow: 0 8px 40px rgba(99,102,241,0.15);
  }
  .plan-emoji { font-size: 48px; margin-bottom: 8px; }
  .plan-name {
    font-size: 20px; font-weight: 800;
    color: var(--text-primary); margin: 0 0 6px;
  }
  .plan-desc {
    font-size: 13px; color: var(--text-muted);
    margin: 0 0 20px;
  }
  .plan-price {
    font-size: 38px; font-weight: 900;
    color: #818cf8; margin-bottom: 4px;
  }
  .plan-price small {
    font-size: 14px; color: var(--text-muted); font-weight: 500;
  }
  .plan-price-hint {
    font-size: 12px; color: var(--text-muted);
    margin: 4px 0 0;
  }

  /* ── 綠界即將上線（保留樣式，v3.2.0 起已不使用） ── */
  .coming-soon-card {
    max-width: 520px; margin: 28px auto 0;
    background: linear-gradient(135deg, rgba(74,222,128,0.08), rgba(16,185,129,0.04));
    border: 1px dashed rgba(74,222,128,0.4);
    border-radius: var(--radius-xl);
    padding: 24px 28px; text-align: center;
    backdrop-filter: blur(var(--glass-blur));
  }
  .coming-soon-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(74,222,128,0.18);
    border: 1px solid rgba(74,222,128,0.35);
    color: #6ee7b7;
    font-size: 11px; font-weight: 700;
    letter-spacing: .08em; text-transform: uppercase;
    padding: 4px 12px; border-radius: var(--radius-pill);
    margin-bottom: 14px;
  }
  .coming-soon-card h3 {
    font-size: 18px; font-weight: 800;
    color: var(--text-primary); margin: 0 0 10px;
  }
  .coming-soon-card p {
    font-size: 13px; color: var(--text-secondary);
    line-height: 1.8; margin: 0;
  }
  .coming-soon-card .ecpay-logo {
    font-size: 22px; font-weight: 900;
    color: #4ade80; letter-spacing: -.02em;
    margin: 6px 0;
  }

  /* ── 綠界已上線（置頂）v3.3.0 ── */
  .ecpay-section {
    padding: 0 0 20px;   /* 緊貼 Hero 下方 */
    position: relative;
  }
  .ecpay-live-card {
    max-width: 520px; margin: -30px auto 0;   /* 往上疊一點，與 Hero 銜接更緊 */
    background: linear-gradient(135deg, rgba(74,222,128,0.10), rgba(16,185,129,0.05));
    border: 1px solid rgba(74,222,128,0.45);
    border-radius: var(--radius-xl);
    padding: 30px 28px; text-align: center;
    backdrop-filter: blur(var(--glass-blur));
    box-shadow: 0 12px 48px rgba(16,185,129,0.18);
    position: relative; z-index: 2;
  }
  .ecpay-live-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(74,222,128,0.18);
    border: 1px solid rgba(74,222,128,0.35);
    color: #6ee7b7;
    font-size: 11px; font-weight: 700;
    letter-spacing: .08em; text-transform: uppercase;
    padding: 4px 12px; border-radius: var(--radius-pill);
    margin-bottom: 14px;
  }
  .ecpay-live-card h3 {
    font-size: 18px; font-weight: 800;
    color: var(--text-primary); margin: 0 0 10px;
  }
  .ecpay-live-card .ecpay-logo {
    font-size: 22px; font-weight: 900;
    color: #4ade80; letter-spacing: -.02em;
    margin: 6px 0 12px;
  }
  .ecpay-live-card p {
    font-size: 13px; color: var(--text-secondary);
    line-height: 1.8; margin: 0 0 20px;
  }
  .ecpay-cta {
    background: linear-gradient(135deg, #22c55e, #16a34a) !important;
    box-shadow: 0 8px 32px rgba(34,197,94,0.4) !important;
  }
  .ecpay-cta:hover {
    background: linear-gradient(135deg, #16a34a, #15803d) !important;
    box-shadow: 0 14px 48px rgba(34,197,94,0.55) !important;
  }
  .ecpay-qr {
    margin-top: 24px;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
  }
  .ecpay-qr img {
    width: 180px; height: 180px;
    border-radius: 12px;
    background: #fff;            /* QR 需白底才掃得到 */
    padding: 10px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.4);
  }
  .ecpay-qr-hint {
    font-size: 12px;
    color: var(--text-muted);
    letter-spacing: 0.05em;
  }


  /* ╔══════════════════════════════════════════════════════╗
   * ║   🗿 黑金直立紀念碑 v3.1（統一版）                          ║
   * ╚══════════════════════════════════════════════════════╝ */

  .monument-section {
    padding: 80px 0 100px;
    background:
      radial-gradient(ellipse 50% 30% at 50% 30%, rgba(212,175,55,0.05) 0%, transparent 60%),
      radial-gradient(ellipse 80% 50% at 50% 100%, rgba(0,0,0,0.4) 0%, transparent 70%);
    position: relative;
  }

  .monument-header {
    text-align: center;
    margin-bottom: 56px;
  }
  .monument-header .section-eyebrow { color: #d4af6f; }

  /* 紀念碑容器 */
  .monument {
    position: relative;
    max-width: 540px;
    margin: 0 auto;
  }

  /* 頂部光暈 */
  .monument-aura {
    position: absolute;
    top: -40px; left: 50%;
    transform: translateX(-50%);
    width: 360px; height: 80px;
    background: radial-gradient(ellipse, rgba(212,175,55,0.18) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
  }

  /* 拱頂 */
  .monument-arch {
    position: relative;
    z-index: 1;
    height: 80px;
    margin-bottom: -2px;
  }
  .monument-arch svg {
    display: block;
    width: 100%;
    height: 100%;
    filter: drop-shadow(0 -2px 8px rgba(0,0,0,0.6));
  }

  /* 碑身 */
  .monument-body {
    position: relative;
    z-index: 1;
    padding: 12px 36px 40px;
    background:
      url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' seed='7'/><feColorMatrix values='0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.22 0'/></filter><rect width='200' height='200' filter='url(%23n)'/></svg>"),
      linear-gradient(180deg, #1a1a1f 0%, #15151a 40%, #0f0f13 100%);
    background-blend-mode: overlay, normal;
    border-left: 1px solid rgba(212,175,55,0.15);
    border-right: 1px solid rgba(212,175,55,0.15);
    box-shadow:
      inset 0 0 60px rgba(0,0,0,0.6),
      inset 1px 0 0 rgba(255,255,255,0.04),
      inset -1px 0 0 rgba(255,255,255,0.04),
      0 0 0 1px rgba(0,0,0,0.6);
  }

  .monument-body::before,
  .monument-body::after {
    content: '';
    position: absolute;
    top: 0; bottom: 0;
    width: 30px;
    pointer-events: none;
  }
  .monument-body::before {
    left: 0;
    background: linear-gradient(90deg, rgba(255,255,255,0.04), transparent);
  }
  .monument-body::after {
    right: 0;
    background: linear-gradient(-90deg, rgba(255,255,255,0.04), transparent);
  }

  /* 橫額題字 */
  .monument-plaque {
    text-align: center;
    padding: 20px 0 24px;
    border-bottom: 1px solid rgba(212,175,55,0.2);
    position: relative;
  }
  .monument-plaque::before,
  .monument-plaque::after {
    content: '';
    position: absolute;
    bottom: -1px;
    width: 40px; height: 1px;
    background: linear-gradient(90deg, transparent, #d4af6f, transparent);
  }
  .monument-plaque::before { left: 50%; transform: translateX(-100%); }
  .monument-plaque::after  { right: 50%; transform: translateX(100%); }

  .monument-plaque-title {
    font-family: 'Noto Serif TC', serif;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.5em;
    color: #d4af6f;
    text-shadow:
      0 -1px 1px rgba(0,0,0,0.9),
      0 1px 0 rgba(212,175,55,0.15),
      0 0 20px rgba(212,175,55,0.2);
    margin: 0 0 8px;
    padding-left: 0.5em;
  }
  .monument-plaque-sub {
    font-family: 'Cinzel', Georgia, serif;
    font-size: 10px;
    letter-spacing: 0.25em;
    color: rgba(212,175,55,0.5);
    font-style: italic;
    margin: 0;
  }

  /* 名單區（統一一段，不分組） */
  .monument-list {
    padding: 32px 0 8px;
  }

  /* 每位贊助者 */
  .monument-name-wrap {
    text-align: center;
    padding: 12px 0;
    border-bottom: 1px dotted rgba(212,175,55,0.1);
    break-inside: avoid;
    -webkit-column-break-inside: avoid;
    page-break-inside: avoid;
  }
  .monument-name-wrap:last-child {
    border-bottom: none;
  }

  /* 名字刻字 */
  .monument-name {
    display: block;
    font-family: 'Noto Serif TC', serif;
    font-size: 17px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: #d4af6f;
    /* 內凹刻字效果 */
    text-shadow:
      0 -1px 1px rgba(0,0,0,0.95),
      0 1px 0 rgba(212,175,55,0.12),
      0 0 12px rgba(212,175,55,0.18);
    line-height: 1.4;
    padding: 2px 0;
    word-break: break-word;
  }

  /* 留言 */
  .monument-name-msg {
    display: block;
    margin-top: 4px;
    font-family: 'Noto Serif TC', serif;
    font-size: 11px;
    font-style: italic;
    color: rgba(212,175,55,0.5);
    text-shadow: 0 -1px 0 rgba(0,0,0,0.8);
    letter-spacing: 0.02em;
  }
  .monument-name-msg::before { content: '「'; }
  .monument-name-msg::after  { content: '」'; }

  /* 日期（小字、暗淡） */
  .monument-name-date {
    display: block;
    margin-top: 4px;
    font-family: 'Cinzel', Georgia, serif;
    font-size: 9px;
    letter-spacing: 0.2em;
    color: rgba(180,150,90,0.35);
  }

  /* 底座 */
  .monument-base {
    position: relative;
    z-index: 1;
    height: 24px;
    margin: 0 -12px;
    background: linear-gradient(180deg, #0f0f13 0%, #08080b 100%);
    border-top: 1px solid rgba(212,175,55,0.1);
    border-radius: 0 0 4px 4px;
    box-shadow:
      0 30px 60px rgba(0,0,0,0.8),
      0 12px 30px rgba(0,0,0,0.5),
      inset 0 1px 0 rgba(255,255,255,0.03);
  }
  .monument-base::after {
    content: '';
    position: absolute;
    bottom: -30px; left: -20px; right: -20px;
    height: 30px;
    background: radial-gradient(ellipse, rgba(0,0,0,0.6) 0%, transparent 70%);
    pointer-events: none;
  }

  /* 計數 */
  .monument-count {
    text-align: center;
    margin-top: 60px;
    font-family: 'Noto Serif TC', serif;
    font-size: 13px;
    color: rgba(212,175,55,0.6);
    letter-spacing: 0.1em;
  }
  .monument-count strong {
    color: #d4af6f;
    font-size: 22px;
    margin: 0 6px;
    font-weight: 700;
    text-shadow: 0 0 12px rgba(212,175,55,0.3);
  }

  /* 空狀態 */
  .monument-empty {
    max-width: 460px;
    margin: 0 auto;
    text-align: center;
  }
  .monument-empty .monument-body {
    padding: 56px 36px;
  }
  .monument-empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
    filter: drop-shadow(0 0 16px rgba(212,175,55,0.3));
  }
  .monument-empty h3 {
    font-family: 'Noto Serif TC', serif;
    font-size: 20px;
    color: #d4af6f;
    margin: 0 0 14px;
    letter-spacing: 0.15em;
    text-shadow:
      0 -1px 1px rgba(0,0,0,0.95),
      0 0 16px rgba(212,175,55,0.25);
  }
  .monument-empty p {
    font-size: 14px;
    color: rgba(212,175,55,0.55);
    line-height: 2;
    margin: 0 0 24px;
    font-family: 'Noto Serif TC', serif;
  }
  .monument-empty .sponsor-hero-cta {
    background: linear-gradient(135deg, #d4af6f, #b8954f);
    box-shadow: 0 8px 32px rgba(212,175,55,0.35);
  }
  .monument-empty .sponsor-hero-cta:hover {
    background: linear-gradient(135deg, #e8c483, #c9a361);
    box-shadow: 0 14px 48px rgba(212,175,55,0.5);
  }

  /* ── FAQ ── */
  .faq-section-sp { padding: 60px 0 80px; }
  .faq-list-sp { max-width: 660px; margin: 32px auto 0; }
  .faq-item-sp { border-bottom: 1px solid var(--glass-border); }
  .faq-item-sp:last-child { border-bottom: none; }
  .faq-q-sp {
    font-size: 14px; font-weight: 600;
    color: var(--text-primary);
    padding: 18px 0; cursor: pointer;
    list-style: none;
    display: flex; justify-content: space-between; align-items: center;
  }
  .faq-q-sp::-webkit-details-marker { display: none; }
  .faq-q-sp::after {
    content: '+'; font-size: 20px; font-weight: 300;
    color: var(--text-muted); flex-shrink: 0; margin-left: 12px;
  }
  details.faq-item-sp[open] .faq-q-sp::after { content: '−'; }
  .faq-a-sp {
    font-size: 13px; color: var(--text-secondary);
    line-height: 1.8; padding-bottom: 18px;
  }

  /* ── RWD ── */
  @media (max-width: 768px) {
    .sponsor-hero { padding: 70px 0 40px; min-height: auto; }
    .plan-single { padding: 28px 20px; }
    .coming-soon-card { padding: 20px; }
    .ecpay-live-card { padding: 24px 18px; margin-top: -16px; }

    .monument { max-width: 92vw; }
    .monument-body { padding: 10px 22px 32px; }
    .monument-plaque-title {
      font-size: 15px;
      letter-spacing: 0.35em;
    }
    .monument-name { font-size: 15px; letter-spacing: 0.08em; }
  }
</style>

<div class="sponsor-page-wrap">

  <!-- ===================== HERO（陪伴感） ===================== -->
  <section class="sponsor-hero">
    <div class="sponsor-hero-bg"></div>
    <div class="container">
      <div class="sponsor-hero-content">
        <div class="sponsor-eyebrow">
          <i class="fa-solid fa-heart"></i> 由動漫迷，為動漫迷
        </div>

        <h1 class="sponsor-hero-title">
          你追過的每一部番，<br>
          <span class="grad">都有人在背後守著</span>
        </h1>

        <p class="sponsor-hero-sub">
          從週一早班的少年漫，到週日深夜的劇場版 ——<br>
          每一部作品的<strong>角色資料、PV、評分、播出時間</strong>，<br>
          我們一筆一筆整理好，<br>
          只為了讓你<strong>多花一秒看動畫，少花一秒搜資料</strong>。<br><br>
          如果這份用心有打動你，<br>
          <span class="accent">請我們喝杯咖啡，讓這個地方繼續守著你下一部最愛的番。</span>
        </p>

        <a href="#plan" class="sponsor-hero-cta">
          <i class="fa-solid fa-mug-hot"></i>
          請我們喝杯咖啡
        </a>
      </div>
    </div>
  </section>

  <!-- ===================== 綠界付款（置頂） ===================== -->
  <section class="ecpay-section" id="plan">
    <div class="container">
      <div class="ecpay-live-card">
        <span class="ecpay-live-badge">
          <i class="fa-solid fa-circle-check"></i> 已開放贊助
        </span>
        <h3>透過綠界安全付款</h3>
        <div class="ecpay-logo">ECPay · 綠界科技</div>
        <p>
          支援<strong style="color:#a5b4fc;">信用卡、ATM、超商代碼</strong>等多種付款方式。<br>
          點擊下方按鈕，或掃描 QR Code 前往贊助頁面 ❤️
        </p>

        <a href="https://p.ecpay.com.tw/64EA58B" target="_blank" rel="noopener" class="sponsor-hero-cta ecpay-cta">
          <i class="fa-solid fa-mug-hot"></i> 前往綠界贊助
        </a>

        <div class="ecpay-qr">
          <img src="https://payment.ecpay.com.tw/Upload/QRCode/202607/QRCode_d85a6597-07d0-4ef7-abcb-2eebf477f8fa.png"
               alt="綠界贊助 QR Code" loading="lazy" width="180" height="180" />
          <span class="ecpay-qr-hint">用手機相機掃我</span>
        </div>
      </div>
    </div>
  </section>

  <!-- ===================== WHY ===================== -->
  <section class="why-section">
    <div class="container">
      <div style="text-align:center;">
        <p class="section-eyebrow">為什麼需要你</p>
        <h2 class="section-h2">一個人做不來的事</h2>
        <p style="font-size:15px;color:var(--text-secondary);max-width:560px;margin:0 auto;line-height:1.85;">
          做一個給整個華人動漫圈用的網站不簡單，<br>
          光靠熱情撐不久 —— 但有你，就可以撐很久。
        </p>
      </div>

      <div class="why-grid">
        <div class="why-card">
          <div class="why-icon" style="background:rgba(99,102,241,0.15);">
            <i class="fa-solid fa-bolt" style="color:#818cf8;"></i>
          </div>
          <h3>讓網站不會突然消失</h3>
          <p>伺服器、CDN、API 每個月都要繳費。你的贊助讓這個網站不會某天突然 404，<strong style="color:var(--text-primary);">繼續陪你追番</strong>。</p>
        </div>

        <div class="why-card">
          <div class="why-icon" style="background:rgba(236,72,153,0.15);">
            <i class="fa-solid fa-heart" style="color:#f472b6;"></i>
          </div>
          <h3>讓廣告不會蓋住內容</h3>
          <p>很多動漫站靠彈跳與遮蔽式廣告活著，我們不想那樣。<strong style="color:var(--text-primary);">有了你的支持，我們才有餘裕把版位控制在不打擾閱讀的範圍</strong>，也不會為了衝曝光而在空白頁面硬塞廣告。</p>
        </div>

        <div class="why-card">
          <div class="why-icon" style="background:rgba(34,197,94,0.15);">
            <i class="fa-solid fa-rocket" style="color:#4ade80;"></i>
          </div>
          <h3>讓新功能更快上線</h3>
          <p>每個你期待的功能 —— 季番追蹤、社群、清單、通知 —— 都需要時間。<strong style="color:var(--text-primary);">你的贊助 = 開發加速</strong>。</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ===================== 🗿 黑金紀念碑（統一版） ===================== -->
  <section class="monument-section">
    <div class="container">

      <div class="monument-header">
        <p class="section-eyebrow">感謝有你</p>
        <h2 class="section-h2">🗿 贊助者紀念碑</h2>
        <p style="font-size:14px;color:var(--text-secondary);max-width:520px;margin:0 auto;line-height:1.8;">
          這裡刻著每一位讓微笑動漫能繼續跑下去的朋友。<br>
          不分多少，<strong style="color:#d4af6f;">每一份心意都一樣珍貴</strong>。
        </p>
      </div>

      <?php if ( empty( $sponsors ) ) : ?>

        <!-- ─── 空狀態紀念碑 ─── -->
        <div class="monument monument-empty">
          <div class="monument-aura"></div>

          <div class="monument-arch">
            <svg viewBox="0 0 540 80" preserveAspectRatio="none">
              <defs>
                <linearGradient id="archGrad1" x1="0%" y1="0%" x2="0%" y2="100%">
                  <stop offset="0%" stop-color="#2a2a30"/>
                  <stop offset="100%" stop-color="#15151a"/>
                </linearGradient>
              </defs>
              <path d="M 0,80 L 0,40 Q 270,-20 540,40 L 540,80 Z" fill="url(#archGrad1)" stroke="rgba(212,175,55,0.15)" stroke-width="1"/>
            </svg>
          </div>

          <div class="monument-body">
            <div class="monument-empty-icon">🪨</div>
            <h3>石碑剛立好</h3>
            <p>
              這面碑還空著，<br>
              等待第一個刻上去的名字。<br>
              願意成為第一位嗎？
            </p>
            <a href="#plan" class="sponsor-hero-cta">
              <i class="fa-solid fa-feather"></i> 成為第一位
            </a>
          </div>

          <div class="monument-base"></div>
        </div>

      <?php else : ?>

        <!-- ─── 有贊助者的紀念碑 ─── -->
        <div class="monument">

          <div class="monument-aura"></div>

          <!-- 拱頂 -->
          <div class="monument-arch">
            <svg viewBox="0 0 540 80" preserveAspectRatio="none">
              <defs>
                <linearGradient id="archGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                  <stop offset="0%" stop-color="#2a2a30"/>
                  <stop offset="60%" stop-color="#1a1a1f"/>
                  <stop offset="100%" stop-color="#15151a"/>
                </linearGradient>
                <linearGradient id="goldLine" x1="0%" y1="0%" x2="100%" y2="0%">
                  <stop offset="0%" stop-color="transparent"/>
                  <stop offset="50%" stop-color="#d4af6f"/>
                  <stop offset="100%" stop-color="transparent"/>
                </linearGradient>
              </defs>
              <path d="M 0,80 L 0,40 Q 270,-20 540,40 L 540,80 Z" fill="url(#archGrad)" stroke="rgba(212,175,55,0.2)" stroke-width="1"/>
              <path d="M 0,40 Q 270,-20 540,40" fill="none" stroke="url(#goldLine)" stroke-width="1" opacity="0.7"/>
              <circle cx="270" cy="20" r="4" fill="#d4af6f" opacity="0.9"/>
              <circle cx="270" cy="20" r="8" fill="none" stroke="rgba(212,175,55,0.4)" stroke-width="0.5"/>
              <circle cx="180" cy="32" r="2" fill="#d4af6f" opacity="0.6"/>
              <circle cx="360" cy="32" r="2" fill="#d4af6f" opacity="0.6"/>
            </svg>
          </div>

          <!-- 碑身 -->
          <div class="monument-body">

            <!-- 橫額題字 -->
            <div class="monument-plaque">
              <h3 class="monument-plaque-title">贊 助 者 紀 念 碑</h3>
              <p class="monument-plaque-sub">In gratitude to those who keep us running</p>
            </div>

            <!-- 名單（統一一段，最新的在最上面） -->
            <div class="monument-list">
              <?php foreach ( array_reverse( $sponsors ) as $s ) :
                  $name    = ! empty( $s['name'] ) ? esc_html( $s['name'] ) : '匿名贊助者';
                  $message = ! empty( $s['message'] ) ? esc_html( $s['message'] ) : '';
                  $date    = ! empty( $s['date'] ) ? esc_html( $s['date'] ) : '';
              ?>
                <div class="monument-name-wrap">
                  <span class="monument-name"><?php echo $name; ?></span>
                  <?php if ( $message ) : ?>
                    <span class="monument-name-msg"><?php echo $message; ?></span>
                  <?php endif; ?>
                  <?php if ( $date ) : ?>
                    <span class="monument-name-date"><?php echo $date; ?></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

          </div>

          <!-- 底座 -->
          <div class="monument-base"></div>

        </div>

        <p class="monument-count">
          已有 <strong><?php echo count( $sponsors ); ?></strong> 位朋友刻在碑上
        </p>

      <?php endif; ?>
    </div>
  </section>

  <!-- ===================== FAQ ===================== -->
  <section class="faq-section-sp">
    <div class="container">
      <div style="text-align:center;">
        <p class="section-eyebrow">常見問題</p>
        <h2 class="section-h2">關於贊助</h2>
      </div>

      <div class="faq-list-sp glass-card" style="padding:4px 24px;">

        <details class="faq-item-sp" open>
          <summary class="faq-q-sp">贊助之後我會得到什麼？</summary>
          <div class="faq-a-sp">
            微笑動漫的核心功能對所有人完全免費，贊助不會解鎖任何「付費功能」。你的支持是對我們工作的認可，讓我們能繼續維持網站運作。如果你願意，可以選擇在「贊助石碑」上留名 —— 公開、匿名、用暱稱都可以。
          </div>
        </details>

        <details class="faq-item-sp">
          <summary class="faq-q-sp">會員制和贊助有什麼關係？</summary>
          <div class="faq-a-sp">
            <strong>沒有關係</strong>。微笑動漫的會員註冊是免費的，會員可以使用追番清單、評分、討論等個人化功能；這些都不需要贊助。<br><br>
            未來如果推出「高級會員」服務，會是針對額外的便利功能（例如更快的同步、客製通知等），會提前公告，不會把現有的免費功能搬走。
          </div>
        </details>

        <details class="faq-item-sp">
          <summary class="faq-q-sp">什麼時候可以開始贊助？</summary>
          <div class="faq-a-sp">
            綠界（ECPay）金流<strong>已經正式上線</strong>，現在就可以透過頁面上方的「前往綠界贊助」按鈕或掃描 QR Code 支持我們。支援信用卡、ATM 轉帳、超商代碼等多種付款方式。<br><br>
            如果過程中遇到任何問題，歡迎寄信到 <strong>weixiaoacg.com@gmail.com</strong> 與我們聯繫。
          </div>
        </details>

        <details class="faq-item-sp">
          <summary class="faq-q-sp">除了贊助，我還能怎麼支持？</summary>
          <div class="faq-a-sp">
            非常多方式都很有幫助：① 分享微笑動漫給身邊的動漫迷；② 在社群與討論區參與互動；③ 發現資料錯誤時回報，幫助我們改善；④ 在社群媒體標記 @weixiaoacg。這些幫助不亞於金錢支持。
          </div>
        </details>

      </div>
    </div>
  </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.sponsor-page-wrap a[href^="#"]').forEach(function(a) {
    a.addEventListener('click', function(e) {
      var targetId = a.getAttribute('href').slice(1);
      var target = document.getElementById(targetId);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });
});
</script>

<?php get_footer(); ?>
