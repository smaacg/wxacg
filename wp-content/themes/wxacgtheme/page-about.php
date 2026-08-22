<?php
/**
 * Template Name: 關於本站
 * Template Post Type: page
 *
 * @package weixiaoacg
 * @version 2.3.0
 *
 * 變更（v2.3.0 — E-E-A-T / AdSense 品質優化）：
 * - 新增「編輯與內容團隊」區塊：動態列出已發文的作者（頭像／簡介／發文數／
 *   作者頁連結），讓 Google 與讀者能看到內容背後的真人與其專業領域。
 *   若作者未填寫個人簡介，只顯示頭像、姓名、發文數與連結，不會出現空白區塊。
 */

defined( 'ABSPATH' ) || exit;

$anime_total = 0;

/* ── 開站日期：2026/06/11 正式上線，計算已上線天數 ───────── */
$wx_launch_date  = new DateTimeImmutable( '2026-06-11', wp_timezone() );
$wx_now          = new DateTimeImmutable( 'now', wp_timezone() );
$wx_days_online  = (int) $wx_launch_date->diff( $wx_now )->days;

if ( post_type_exists( 'anime' ) ) {
	$anime_counts = wp_count_posts( 'anime' );

	if ( isset( $anime_counts->publish ) ) {
		$anime_total = (int) $anime_counts->publish;
	}
}

/* ── 編輯與內容團隊：抓取已發表過至少一篇文章的作者 ─────────
 *
 * 排除官方發文帳號——它是站方的帳號、不是人，列在團隊裡會誤導讀者。
 * 排除清單放在 functions.php，與 page-join.php 共用同一份，
 * 才不會改了一邊漏了另一邊。
 */
$wx_team_members = get_users( [
	'has_published_posts' => [ 'post' ],
	'orderby'              => 'post_count',
	'order'                => 'DESC',
	'number'               => 12,
	'exclude'              => function_exists( 'wxacg_team_excluded_user_ids' )
		? wxacg_team_excluded_user_ids()
		: [],
] );

get_header();
?>

<style>
.wx-about {
	--wx-blue: #60a5fa;
	--wx-purple: #a78bfa;
	--wx-pink: #f472b6;
	--wx-green: #4ade80;
	--wx-yellow: #fbbf24;
}

.wx-about *,
.wx-about *::before,
.wx-about *::after {
	box-sizing: border-box;
}

.wx-about-hero {
	position: relative;
	overflow: hidden;
	padding: 72px 20px 58px;
	text-align: center;
	background:
		radial-gradient(circle at 20% 20%, rgba(59, 130, 246, 0.28), transparent 55%),
		radial-gradient(circle at 80% 30%, rgba(139, 92, 246, 0.28), transparent 55%),
		linear-gradient(
			135deg,
			rgba(59, 130, 246, 0.16),
			rgba(139, 92, 246, 0.16),
			rgba(236, 72, 153, 0.10)
		);
	border-bottom: 1px solid var(--glass-border);
}

.wx-about-hero::before {
	content: "";
	position: absolute;
	inset: 0;
	pointer-events: none;
	background:
		radial-gradient(
			ellipse at center top,
			rgba(255, 255, 255, 0.07),
			transparent 70%
		);
}

.wx-about-hero .container {
	position: relative;
	z-index: 1;
}

.wx-about-logo {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 92px;
	height: 92px;
	margin: 0 auto 22px;
	border: 1px solid rgba(255, 255, 255, 0.10);
	border-radius: 24px;
	background:
		linear-gradient(
			135deg,
			rgba(59, 130, 246, 0.18),
			rgba(139, 92, 246, 0.18)
		);
	box-shadow: 0 10px 36px rgba(59, 130, 246, 0.32);
}

.wx-about-logo img {
	display: block;
	width: 66px;
	height: 66px;
	object-fit: contain;
}

.wx-about-title {
	margin: 0 0 12px;
	color: var(--text-primary);
	font-size: clamp(30px, 5vw, 44px);
	font-weight: 800;
	line-height: 1.25;
}

.wx-about-subtitle {
	max-width: 780px;
	margin: 0 auto;
	color: var(--text-muted);
	font-size: 16px;
	line-height: 1.85;
}

.wx-about-main {
	padding-top: 12px;
	padding-bottom: 72px;
}

.wx-about-section {
	padding: 58px 0;
}

.wx-about-section + .wx-about-section {
	border-top: 1px solid var(--glass-border);
}

.wx-about-heading {
	margin: 0 0 14px;
	color: var(--text-primary);
	font-size: 28px;
	font-weight: 800;
	line-height: 1.35;
}

.wx-about-heading-center {
	text-align: center;
}

.wx-about-lead {
	max-width: 820px;
	margin: 0 auto;
	color: var(--text-secondary);
	font-size: 16px;
	line-height: 1.9;
}

.wx-about-lead + .wx-about-lead {
	margin-top: 14px;
}

.wx-about-center {
	text-align: center;
}

.wx-about-muted {
	color: var(--text-muted);
}

.wx-about-grid-2 {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 24px;
}

.wx-about-grid-3 {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 20px;
}

.wx-about-grid-4 {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 20px;
}

.wx-about-grid-5 {
	display: grid;
	grid-template-columns: repeat(5, minmax(0, 1fr));
	gap: 20px;
}

.wx-about-card {
	height: 100%;
	padding: 28px 26px;
	border: 1px solid var(--glass-border);
	border-radius: 20px;
	background: var(--glass-bg);
	transition:
		transform 0.2s ease,
		border-color 0.2s ease,
		box-shadow 0.2s ease;
}

.wx-about-card:hover {
	transform: translateY(-3px);
	border-color: rgba(139, 92, 246, 0.35);
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.22);
}

.wx-about-card-icon {
	margin-bottom: 14px;
	font-size: 30px;
	line-height: 1;
}

.wx-about-card-title {
	margin: 0 0 10px;
	color: var(--text-primary);
	font-size: 17px;
	font-weight: 800;
	line-height: 1.45;
}

.wx-about-card-text {
	margin: 0;
	color: var(--text-muted);
	font-size: 14px;
	line-height: 1.8;
}

.wx-about-card-text + .wx-about-card-text {
	margin-top: 10px;
}

.wx-about-intro {
	padding: 36px;
	border: 1px solid var(--glass-border);
	border-radius: 24px;
	background:
		radial-gradient(circle at 15% 20%, rgba(59, 130, 246, 0.13), transparent 50%),
		radial-gradient(circle at 85% 80%, rgba(236, 72, 153, 0.11), transparent 50%),
		var(--glass-bg);
}

.wx-about-intro p {
	margin: 0;
	color: var(--text-secondary);
	font-size: 16px;
	line-height: 1.95;
}

.wx-about-intro p + p {
	margin-top: 14px;
}

.wx-about-notice {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	margin-top: 22px;
	padding: 18px 20px;
	border: 1px solid rgba(96, 165, 250, 0.24);
	border-radius: 16px;
	background: rgba(59, 130, 246, 0.08);
	color: var(--text-secondary);
	font-size: 14px;
	line-height: 1.8;
}

.wx-about-notice-icon {
	flex: 0 0 auto;
	font-size: 20px;
}

.wx-about-stat {
	padding: 28px 18px;
	text-align: center;
	border: 1px solid var(--glass-border);
	border-radius: 20px;
	background: var(--glass-bg);
}

.wx-about-stat-number {
	margin-bottom: 6px;
	color: var(--wx-blue);
	font-size: 34px;
	font-weight: 800;
	line-height: 1.15;
}

.wx-about-stat-label {
	color: var(--text-muted);
	font-size: 13px;
	line-height: 1.6;
}

.wx-about-api-list {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.wx-about-api {
	display: grid;
	grid-template-columns: 12px 150px 1fr auto;
	align-items: center;
	gap: 14px;
	padding: 18px 20px;
	border: 1px solid var(--glass-border);
	border-radius: 16px;
	background: var(--glass-bg);
}

.wx-about-api-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
}

.wx-about-api-name {
	color: var(--text-primary);
	font-size: 14px;
	font-weight: 800;
}

.wx-about-api-desc {
	color: var(--text-muted);
	font-size: 13px;
	line-height: 1.65;
}

.wx-about-api-link {
	color: var(--wx-blue);
	font-size: 13px;
	font-weight: 700;
	text-decoration: none;
	white-space: nowrap;
}

.wx-about-api-link:hover,
.wx-about-api-link:focus {
	text-decoration: underline;
}

.wx-about-timeline {
	position: relative;
	max-width: 840px;
	margin: 42px auto 0;
	padding-left: 70px;
}

.wx-about-timeline::before {
	content: "";
	position: absolute;
	top: 10px;
	bottom: 10px;
	left: 24px;
	width: 2px;
	border-radius: 2px;
	background:
		linear-gradient(
			to bottom,
			rgba(59, 130, 246, 0.7),
			rgba(139, 92, 246, 0.7),
			rgba(236, 72, 153, 0.6)
		);
}

.wx-about-timeline-item {
	position: relative;
	margin-bottom: 28px;
}

.wx-about-timeline-item:last-child {
	margin-bottom: 0;
}

.wx-about-timeline-dot {
	position: absolute;
	top: 18px;
	left: -62px;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border-radius: 50%;
	background: linear-gradient(135deg, #3b82f6, #8b5cf6);
	box-shadow:
		0 0 0 4px #0f1119,
		0 5px 18px rgba(59, 130, 246, 0.35);
	font-size: 16px;
}

.wx-about-timeline-card {
	padding: 24px 26px;
	border: 1px solid var(--glass-border);
	border-radius: 20px;
	background: var(--glass-bg);
}

.wx-about-timeline-year {
	display: inline-block;
	margin-bottom: 10px;
	padding: 5px 11px;
	border-radius: 999px;
	background: rgba(59, 130, 246, 0.12);
	color: var(--wx-blue);
	font-size: 11px;
	font-weight: 800;
	letter-spacing: 1.5px;
}

.wx-about-timeline-title {
	margin: 0 0 10px;
	color: var(--text-primary);
	font-size: 18px;
	font-weight: 800;
	line-height: 1.45;
}

.wx-about-timeline-text {
	margin: 0;
	color: var(--text-secondary);
	font-size: 14px;
	line-height: 1.85;
}

.wx-about-timeline-text + .wx-about-timeline-text {
	margin-top: 10px;
}

.wx-about-transparency {
	padding: 36px;
	border: 1px solid rgba(167, 139, 250, 0.25);
	border-radius: 24px;
	background:
		linear-gradient(
			135deg,
			rgba(59, 130, 246, 0.10),
			rgba(139, 92, 246, 0.10),
			rgba(236, 72, 153, 0.07)
		);
}

.wx-about-transparency h3 {
	margin: 0 0 14px;
	color: var(--text-primary);
	font-size: 22px;
	font-weight: 800;
}

.wx-about-transparency p {
	margin: 0;
	color: var(--text-secondary);
	font-size: 14px;
	line-height: 1.85;
}

.wx-about-transparency p + p {
	margin-top: 12px;
}

.wx-about-links {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-top: 24px;
}

.wx-about-link {
	display: inline-flex;
	align-items: center;
	gap: 9px;
	min-height: 44px;
	padding: 11px 20px;
	border: 1px solid var(--glass-border);
	border-radius: 999px;
	background: var(--glass-bg);
	color: var(--text-secondary);
	font-size: 13px;
	font-weight: 700;
	text-decoration: none;
	transition:
		color 0.2s ease,
		border-color 0.2s ease,
		transform 0.2s ease;
}

.wx-about-link:hover,
.wx-about-link:focus {
	color: var(--wx-blue);
	border-color: rgba(59, 130, 246, 0.45);
	transform: translateY(-1px);
}

.wx-about-cta {
	padding: 48px 30px;
	text-align: center;
	border: 1px solid var(--glass-border);
	border-radius: 26px;
	background:
		radial-gradient(circle at 20% 20%, rgba(59, 130, 246, 0.15), transparent 50%),
		radial-gradient(circle at 80% 80%, rgba(236, 72, 153, 0.12), transparent 50%),
		var(--glass-bg);
}

.wx-about-cta-icon {
	margin-bottom: 12px;
	font-size: 38px;
}

.wx-about-cta h2 {
	margin: 0 0 14px;
	color: var(--text-primary);
	font-size: 24px;
	font-weight: 800;
}

.wx-about-cta p {
	max-width: 700px;
	margin: 0 auto;
	color: var(--text-muted);
	font-size: 14px;
	line-height: 1.85;
}

.wx-about-cta p + p {
	margin-top: 12px;
}

.wx-about-cta .wx-about-links {
	justify-content: center;
}

/* ── 編輯與內容團隊（新增） ───────────────────────── */
.wx-about-team-card {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	height: 100%;
	padding: 24px 22px;
	border: 1px solid var(--glass-border);
	border-radius: 20px;
	background: var(--glass-bg);
	transition:
		transform 0.2s ease,
		border-color 0.2s ease,
		box-shadow 0.2s ease;
}

.wx-about-team-card:hover {
	transform: translateY(-3px);
	border-color: rgba(139, 92, 246, 0.35);
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.22);
}

.wx-about-team-avatar {
	flex: 0 0 auto;
	width: 56px;
	height: 56px;
	border-radius: 50%;
	object-fit: cover;
	border: 2px solid var(--glass-border);
}

.wx-about-team-body {
	min-width: 0;
	flex: 1 1 auto;
}

.wx-about-team-name {
	display: block;
	margin-bottom: 4px;
	color: var(--text-primary);
	font-size: 15px;
	font-weight: 800;
	text-decoration: none;
}

.wx-about-team-name:hover,
.wx-about-team-name:focus {
	text-decoration: underline;
}

.wx-about-team-role {
	display: inline-block;
	margin-bottom: 8px;
	padding: 2px 9px;
	border-radius: 999px;
	background: rgba(59, 130, 246, 0.12);
	color: var(--wx-blue);
	font-size: 11px;
	font-weight: 800;
	letter-spacing: 0.5px;
}

.wx-about-team-bio {
	margin: 0 0 10px;
	color: var(--text-muted);
	font-size: 13px;
	line-height: 1.75;
}

.wx-about-team-meta {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--text-muted);
	font-size: 12px;
}

.wx-about-team-meta a {
	color: var(--wx-blue);
	font-weight: 700;
	text-decoration: none;
}

.wx-about-team-meta a:hover,
.wx-about-team-meta a:focus {
	text-decoration: underline;
}

@media (max-width: 900px) {
	.wx-about-grid-3 {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.wx-about-grid-4,
	.wx-about-grid-5 {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.wx-about-api {
		grid-template-columns: 12px 130px 1fr;
	}

	.wx-about-api-link {
		grid-column: 2 / -1;
	}
}

@media (max-width: 650px) {
	.wx-about-hero {
		padding: 46px 16px 38px;
	}

	.wx-about-logo {
		width: 74px;
		height: 74px;
		border-radius: 19px;
	}

	.wx-about-logo img {
		width: 54px;
		height: 54px;
	}

	.wx-about-subtitle {
		font-size: 14px;
	}

	.wx-about-main {
		padding-bottom: 48px;
	}

	.wx-about-section {
		padding: 40px 0;
	}

	.wx-about-heading {
		font-size: 23px;
	}

	.wx-about-grid-2,
	.wx-about-grid-3 {
		grid-template-columns: 1fr;
	}

	.wx-about-grid-4,
	.wx-about-grid-5 {
		gap: 12px;
	}

	.wx-about-card,
	.wx-about-intro,
	.wx-about-transparency {
		padding: 22px 20px;
		border-radius: 18px;
	}

	.wx-about-stat {
		padding: 22px 12px;
		border-radius: 16px;
	}

	.wx-about-stat-number {
		font-size: 27px;
	}

	.wx-about-api {
		grid-template-columns: 12px 1fr;
		gap: 9px 12px;
		padding: 16px;
	}

	.wx-about-api-desc,
	.wx-about-api-link {
		grid-column: 2;
	}

	.wx-about-timeline {
		padding-left: 54px;
	}

	.wx-about-timeline::before {
		left: 17px;
	}

	.wx-about-timeline-dot {
		left: -52px;
		width: 32px;
		height: 32px;
	}

	.wx-about-timeline-card {
		padding: 20px 18px;
		border-radius: 17px;
	}

	.wx-about-cta {
		padding: 34px 20px;
		border-radius: 20px;
	}

	.wx-about-team-card {
		flex-direction: column;
		align-items: flex-start;
	}
}

@media (max-width: 380px) {
	.wx-about-grid-4,
	.wx-about-grid-5 {
		grid-template-columns: 1fr;
	}
}
</style>

<div class="wx-about">

	<!-- Hero -->
	<header class="wx-about-hero">
		<div class="container">

			<div class="wx-about-logo">
				<img
					src="https://weixiaoacg.com/wp-content/uploads/2026/06/DHBdKsLa.webp"
					alt="微笑動漫 WeixiaoACG 標誌"
					width="1500"
					height="1426"
					loading="eager"
					decoding="async"
				>
			</div>

			<h1 class="wx-about-title">
				關於微笑動漫 WeixiaoACG
			</h1>

			<p class="wx-about-subtitle">
				由動漫愛好者共同建立的繁體中文 ACG 平台，
				結合作品資料庫、動漫新聞、作品評論、深度專欄、
				追番紀錄、評分、討論社群與開發者 API，
				讓更多作品被看見，也讓喜歡動漫的人在這裡相遇。
			</p>

		</div>
	</header>

	<main class="wx-about-main container">

		<!-- 我們是誰 -->
		<section class="wx-about-section">
			<div class="wx-about-intro">

				<h2 class="wx-about-heading">我們是誰</h2>

				<p>
					微笑動漫 WeixiaoACG 是一個以繁體中文使用者為主要服務對象的
					獨立 ACG 資料庫、內容媒體與社群平台。
					我們整理動畫、漫畫、小說、音樂及相關作品資料，
					並提供動漫新聞、作品評論、專欄報導、追番紀錄、
					會員評分及動漫討論等功能。
				</p>

				<p>
					我們希望建立一個能同時滿足「查找資料」、「發現作品」、
					「閱讀內容」、「記錄進度」與「交流心得」的繁體中文動漫空間。
					無論是剛開始接觸動漫的新觀眾，或長期關注不同類型作品的愛好者，
					都能在這裡找到適合自己的內容、工具與交流方式。
				</p>

				<p>
					我們相信，每一部作品都有被認識的機會。
					除了熱門新作，本站也希望介紹經典作品、不同類型的創作，
					以及較少受到關注但同樣值得認識的內容。
					透過繁體中文資料整理、新聞報導、作品評論、
					專欄分析與社群分享，讓動漫文化被更多人理解與欣賞。
				</p>

				<p>
					本站不是動畫製作公司、出版社、代理商或串流平台，
					也不提供未經授權的完整動畫、漫畫、小說、音樂或下載服務。
					如頁面提供觀看資訊或外部連結，主要目的是協助使用者前往
					作品官方網站、出版單位或合法授權平台。
				</p>

				<p>
					網站目前仍在持續建設中。部分作品識別資料可能透過第三方 API
					取得，再由本站依實際情況進行繁體中文整理、人工校正、
					來源查核及內容補充。若發現資料錯誤，
					歡迎透過聯絡頁面通知我們。
				</p>

				<div class="wx-about-notice">
					<span class="wx-about-notice-icon" aria-hidden="true">ℹ️</span>
					<span>
						微笑動漫為獨立營運的 ACG 資訊與社群平台。
						除非頁面另有明確標示，本站與所介紹的動畫製作公司、
						出版社、資料庫、發行商、串流平台及其他第三方服務
						不存在代理、官方認可或合作關係。
					</span>
				</div>

			</div>
		</section>

		<!-- 平台定位 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				我們正在建立的繁體中文 ACG 平台
			</h2>

			<p class="wx-about-lead wx-about-center">
				微笑動漫不只是作品資料庫，也不只是新聞網站或討論區。
				我們希望將資料、內容、追番工具與社群交流連結在一起，
				建立能持續成長的繁體中文動漫平台。
			</p>

			<div class="wx-about-grid-2" style="margin-top:32px;">

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">📚</div>
					<h3 class="wx-about-card-title">繁體中文動漫資料庫</h3>
					<p class="wx-about-card-text">
						整理作品名稱、季度、集數、劇情介紹、人物、聲優、
						製作團隊、主題曲、系列關聯及合法觀看資訊，
						協助使用者快速理解一部作品。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">📰</div>
					<h3 class="wx-about-card-title">動漫新聞與內容媒體</h3>
					<p class="wx-about-card-text">
						整理作品新訊、官方公告、產業動態與相關情報，
						並透過作品評論、專欄與主題報導，
						提供不只是資料列表的閱讀內容。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">💬</div>
					<h3 class="wx-about-card-title">動漫討論與會員社群</h3>
					<p class="wx-about-card-text">
						讓使用者圍繞作品、角色、聲優、音樂及動漫文化分享心得，
						並透過追番、評分與個人紀錄，
						建立屬於自己的動漫觀看歷程。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🧩</div>
					<h3 class="wx-about-card-title">繁體中文開發者 API</h3>
					<p class="wx-about-card-text">
						逐步建立穩定的作品識別與跨資料庫對照，
						並在資料、文件及使用規則準備完成後，
						提供適合繁體中文網站、App及工具使用的開發者 API。
					</p>
				</article>

			</div>
		</section>

		<!-- 核心使命 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				我們的核心使命
			</h2>

			<p class="wx-about-lead wx-about-center">
				以繁體中文整理可靠、容易理解且有深度的動漫資訊，
				推廣多元作品與動漫文化，讓更多人找到自己喜歡的內容。
			</p>

			<div class="wx-about-grid-2" style="margin-top:32px;">

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🌟</div>
					<h3 class="wx-about-card-title">讓更多作品被看見</h3>
					<p class="wx-about-card-text">
						從當季新番、熱門系列、經典作品到較少受到關注的創作，
						我們希望透過分類、搜尋、作品關聯、新聞與專欄，
						協助更多人發現符合自己興趣的作品。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">繁</div>
					<h3 class="wx-about-card-title">充實繁體中文資訊</h3>
					<p class="wx-about-card-text">
						逐步整理繁體中文名稱、作品介紹、人物、聲優、
						製作資料及系列關聯，並盡可能區分正式譯名、
						常用別名與其他語言名稱。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">💡</div>
					<h3 class="wx-about-card-title">呈現動漫文化的多元面貌</h3>
					<p class="wx-about-card-text">
						動漫不只是娛樂，也包含藝術、敘事、音樂、設計、
						技術、產業與社群文化。我們希望透過資料、
						評論與專欄呈現作品背後更完整的面貌。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🤝</div>
					<h3 class="wx-about-card-title">建立友善的交流空間</h3>
					<p class="wx-about-card-text">
						無論是剛接觸動漫的使用者或長期動漫愛好者，
						都能在尊重彼此、保護隱私並遵守社群規範的前提下，
						分享自己的喜好、心得與觀點。
					</p>
				</article>

			</div>
		</section>

		<!-- 內容原則 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				我們的資料與內容原則
			</h2>

			<p class="wx-about-lead wx-about-center">
				我們重視資訊來源、原創內容、資料透明度與使用者體驗，
				並持續修正自動同步或人工整理可能產生的錯誤。
			</p>

			<div class="wx-about-grid-3" style="margin-top:32px;">

				<?php
				$content_principles = [
					[
						'icon'  => '🧭',
						'title' => '清楚標示資訊來源',
						'desc'  => '作品識別、人物、製作資料、關聯作品及評分可能參考第三方網站或公開 API。第三方資料僅供參考，重要資訊仍以作品官方及原始來源為準。',
					],
					[
						'icon'  => '✍️',
						'title' => '原創整理與編輯內容',
						'desc'  => '新聞、評論、專欄及報導會盡可能加入本站整理、說明、分析或觀點，不以大量複製其他網站文章作為主要內容來源。',
					],
					[
						'icon'  => '📰',
						'title' => '區分新聞與評論',
						'desc'  => '新聞內容以可查核資訊為基礎；評論與專欄則可能包含作者觀點。我們會盡量透過分類、標題或內文明確區分內容性質。',
					],
					[
						'icon'  => '🛡️',
						'title' => '尊重著作權',
						'desc'  => '作品名稱、角色、圖片、影片、音樂及商標的相關權利，原則上屬於原作者、出版社、製作公司、發行商或其他合法權利人。',
					],
					[
						'icon'  => '💬',
						'title' => '管理社群內容',
						'desc'  => '會員發表的主題、回覆及評論由發布者自行負責。本站提供管理與檢舉機制，並依規範處理垃圾訊息、騷擾、侵權連結及其他違規內容。',
					],
					[
						'icon'  => '🔎',
						'title' => '接受更正與申訴',
						'desc'  => '使用者或權利人可以回報資料錯誤、失效連結、新聞更正、隱私問題或著作權疑慮，本站會依案件內容進行確認與處理。',
					],
				];

				foreach ( $content_principles as $principle ) :
					?>
					<article class="wx-about-card">
						<div class="wx-about-card-icon" aria-hidden="true">
							<?php echo esc_html( $principle['icon'] ); ?>
						</div>

						<h3 class="wx-about-card-title">
							<?php echo esc_html( $principle['title'] ); ?>
						</h3>

						<p class="wx-about-card-text">
							<?php echo esc_html( $principle['desc'] ); ?>
						</p>
					</article>
				<?php endforeach; ?>

			</div>
		</section>

		<!-- 編輯與內容團隊（新增：E-E-A-T） -->
		<?php if ( ! empty( $wx_team_members ) ) : ?>
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				編輯與內容團隊
			</h2>

			<p class="wx-about-lead wx-about-center">
				本站的新聞、評論、專欄與資料整理由下列作者撰寫或編修。
				點擊姓名可查看該作者發表過的所有文章。
			</p>

			<div class="wx-about-grid-3" style="margin-top:32px;">

				<?php foreach ( $wx_team_members as $member ) :
					$m_id       = (int) $member->ID;
					$m_name     = $member->display_name ?: $member->user_login;
					$m_bio      = trim( (string) get_user_meta( $m_id, 'description', true ) );
					$m_url      = get_author_posts_url( $m_id );
					$m_avatar   = get_avatar_url( $m_id, [ 'size' => 112 ] );
					$m_posts    = (int) count_user_posts( $m_id, 'post', true );
					if ( strtolower( (string) $member->user_login ) === 'kousei' || $m_name === 'Kousei' ) {
						$m_role = '日文翻譯人員 + 洽談公關';
					} elseif ( $m_posts >= 20 ) {
						$m_role = '主編輯';
					} else {
						$m_role = '內容作者';
					}
					if ( $m_posts < 1 ) continue;
				?>
					<div class="wx-about-team-card">

						<a href="<?php echo esc_url( $m_url ); ?>" tabindex="-1" aria-hidden="true">
							<img
								src="<?php echo esc_url( $m_avatar ); ?>"
								alt="<?php echo esc_attr( $m_name ); ?>"
								class="wx-about-team-avatar"
								width="56" height="56"
								loading="lazy"
								decoding="async"
							>
						</a>

						<div class="wx-about-team-body">

							<span class="wx-about-team-role"><?php echo esc_html( $m_role ); ?></span>

							<a href="<?php echo esc_url( $m_url ); ?>" class="wx-about-team-name" rel="author">
								<?php echo esc_html( $m_name ); ?>
							</a>

							<?php if ( $m_bio !== '' ) : ?>
							<p class="wx-about-team-bio"><?php echo esc_html( $m_bio ); ?></p>
							<?php endif; ?>

							<div class="wx-about-team-meta">
								<i class="fa-solid fa-pen-nib" aria-hidden="true"></i>
								已發表 <?php echo esc_html( number_format_i18n( $m_posts ) ); ?> 篇文章
								·
								<a href="<?php echo esc_url( $m_url ); ?>">查看文章</a>
							</div>

						</div>
					</div>
				<?php endforeach; ?>

			</div>

			<div class="wx-about-notice">
				<span class="wx-about-notice-icon" aria-hidden="true">📝</span>
				<span>
					新聞內容於發布前會盡可能查核來源；評論與專欄可能包含作者個人觀點，
					不代表全體編輯團隊或本站的統一立場。
					如需回報特定文章的錯誤或提出更正，
					歡迎透過文章下方的作者連結或
					<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" style="color:var(--wx-blue);font-weight:700;">聯絡頁面</a>
					與我們聯繫。
				</span>
			</div>

		</section>
		<?php endif; ?>

		<!-- 新聞評論專欄 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				新聞、評論與專欄報導
			</h2>

			<p class="wx-about-lead wx-about-center">
				除了整理作品資料，我們也希望提供具有閱讀價值的繁體中文內容，
				協助使用者理解作品、創作者與動漫產業的不同面向。
			</p>

			<div class="wx-about-grid-3" style="margin-top:32px;">

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🗞️</div>
					<h3 class="wx-about-card-title">動漫新聞</h3>
					<p class="wx-about-card-text">
						整理新作發表、播出資訊、官方公告、聲優消息、
						製作動態及其他與 ACG 相關的公開資訊，
						並盡量附上可供查核的來源。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">⭐</div>
					<h3 class="wx-about-card-title">作品評論</h3>
					<p class="wx-about-card-text">
						從劇情、角色、演出、作畫、音樂及整體觀賞體驗等角度
						分享作品心得。評論屬作者觀點，
						不代表所有使用者或作品權利人的立場。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🖋️</div>
					<h3 class="wx-about-card-title">深度專欄</h3>
					<p class="wx-about-card-text">
						以主題整理、作品分析、系列導讀、創作背景、
						文化觀察及產業議題等方式，
						提供比基本資料更深入的閱讀內容。
					</p>
				</article>

			</div>

			<div class="wx-about-notice">
				<span class="wx-about-notice-icon" aria-hidden="true">📌</span>
				<span>
					新聞內容可能因官方後續公告而更新；
					評論與專欄可能包含作者個人觀點。
					如果文章內容有重要錯誤或需要補充，
					歡迎透過聯絡頁面提供具體資料與來源。
				</span>
			</div>

		</section>

		<!-- 功能 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				網站目前提供的內容與功能
			</h2>

			<div class="wx-about-grid-3" style="margin-top:32px;">

				<?php
				$features = [
					[
						'icon'  => '📅',
						'title' => '當季新番與播出資訊',
						'desc'  => '依日期、年份及季度查看動畫作品，掌握播出狀態與基本資料。',
					],
					[
						'icon'  => '📖',
						'title' => '繁體中文作品資料',
						'desc'  => '整理作品名稱、劇情介紹、角色、聲優及製作人員資訊。',
					],
					[
						'icon'  => '📰',
						'title' => '動漫新聞與新訊',
						'desc'  => '整理作品公告、製作消息、播出動態及其他 ACG 相關資訊。',
					],
					[
						'icon'  => '✍️',
						'title' => '評論與專欄報導',
						'desc'  => '提供作品心得、系列導讀、主題分析及動漫文化相關內容。',
					],
					[
						'icon'  => '🔗',
						'title' => '作品關聯與系列整理',
						'desc'  => '協助使用者辨識前作、續作、外傳、特別篇及系列關係。',
					],
					[
						'icon'  => '🎵',
						'title' => '主題曲識別資料',
						'desc'  => '整理部分動畫片頭曲、片尾曲及相關音樂識別資訊。',
					],
					[
						'icon'  => '📊',
						'title' => '評分與觀看參考',
						'desc'  => '提供本站會員評分及部分第三方平台評分，協助使用者認識作品。',
					],
					[
						'icon'  => '⭐',
						'title' => '追番與觀看進度',
						'desc'  => '會員可以儲存想看、觀看中、已看完、暫停及棄番狀態。',
					],
					[
						'icon'  => '💬',
						'title' => '動漫討論與社群交流',
						'desc'  => '使用者可以圍繞作品、角色、聲優、音樂及動漫文化分享心得。',
					],
				];

				foreach ( $features as $feature ) :
					?>
					<article class="wx-about-card">
						<div class="wx-about-card-icon" aria-hidden="true">
							<?php echo esc_html( $feature['icon'] ); ?>
						</div>

						<h3 class="wx-about-card-title">
							<?php echo esc_html( $feature['title'] ); ?>
						</h3>

						<p class="wx-about-card-text">
							<?php echo esc_html( $feature['desc'] ); ?>
						</p>
					</article>
				<?php endforeach; ?>

			</div>
		</section>

		<!-- 社群 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				動漫討論與社群文化
			</h2>

			<p class="wx-about-lead wx-about-center">
				作品資料不只是靜態頁面，也可以成為評論、追番、
				評分與討論的共同入口。
			</p>

			<div class="wx-about-grid-2" style="margin-top:32px;">

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🫶</div>
					<h3 class="wx-about-card-title">讓作品與同好相遇</h3>
					<p class="wx-about-card-text">
						使用者可以從作品資料找到相關討論，
						也能從社群內容回到作品、人物、音樂及系列頁面，
						讓資料查詢與交流分享不再彼此分離。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🗣️</div>
					<h3 class="wx-about-card-title">尊重不同觀點與喜好</h3>
					<p class="wx-about-card-text">
						每位使用者對作品的感受都可能不同。
						本站鼓勵有內容的評論與理性討論，
						不因評分、角色喜好或觀看經驗不同而攻擊其他使用者。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🚨</div>
					<h3 class="wx-about-card-title">檢舉與管理機制</h3>
					<p class="wx-about-card-text">
						使用者如發現垃圾訊息、騷擾、不當內容、
						侵權連結或其他違規行為，可以向本站回報。
						管理團隊會依社群規範與案件情況進行處理。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🌱</div>
					<h3 class="wx-about-card-title">與繁體中文社群共同成長</h3>
					<p class="wx-about-card-text">
						未來希望讓社群成員參與錯誤回報、名稱校正、
						作品關聯補充及合法觀看資訊更新，
						經過確認後逐步改善資料品質。
					</p>
				</article>

			</div>

			<div class="wx-about-links" style="justify-content:center;">
				<a
					href="<?php echo esc_url( home_url( '/forum/' ) ); ?>"
					class="wx-about-link"
				>
					<i class="fa-solid fa-comments" aria-hidden="true"></i>
					前往討論區
				</a>

				<a
					href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"
					class="wx-about-link"
				>
					<i class="fa-solid fa-file-contract" aria-hidden="true"></i>
					查看使用條款
				</a>
			</div>

		</section>

		<!-- API -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				資料庫、社群與 API 發展方向
			</h2>

			<p class="wx-about-lead wx-about-center">
				我們希望逐步建立穩定、可追溯且適合繁體中文開發者使用的動漫資料基礎，
				讓網站內容、社群功能與外部應用可以透過一致的作品識別連結。
			</p>

			<div class="wx-about-grid-2" style="margin-top:32px;">

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🆔</div>
					<h3 class="wx-about-card-title">穩定的作品識別</h3>
					<p class="wx-about-card-text">
						規劃建立本站統一作品識別碼，
						並保存不同外部資料庫的作品 ID 對照，
						減少同一作品在不同平台之間的配對困難。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🌐</div>
					<h3 class="wx-about-card-title">繁體中文在地化資料</h3>
					<p class="wx-about-card-text">
						逐步整理繁體中文正式名稱、常用別名、
						作品說明與不同地區的合法觀看資訊，
						不將單純的簡繁轉換視為完整在地化。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🔗</div>
					<h3 class="wx-about-card-title">跨資料庫對照</h3>
					<p class="wx-about-card-text">
						不同資料庫對季度、劇場版、OVA及特別篇的劃分方式可能不同。
						本站會逐步保存來源、配對方式與確認狀態，
						避免只依作品名稱直接合併。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">⚙️</div>
					<h3 class="wx-about-card-title">開發者 API</h3>
					<p class="wx-about-card-text">
						WeixiaoACG API 目前仍在規劃與建設階段。
						正式公開前將準備資料結構、API文件、版本規則、
						請求限制與資料使用條款。
					</p>
				</article>

			</div>

			<div class="wx-about-notice">
				<span class="wx-about-notice-icon" aria-hidden="true">🛠️</span>
				<span>
					未來 API 將以本站有權提供的資料為主。
					第三方 API 可供存取，不代表相關資料、圖片、
					商標或作品內容可以由本站任意重新授權。
					實際公開欄位將依資料來源條款及授權狀況調整。
				</span>
			</div>
		</section>

		<!-- 資料概況 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				網站資料概況
			</h2>

			<div class="wx-about-grid-5" style="margin-top:32px;">

				<div class="wx-about-stat">
					<div class="wx-about-stat-number">
						<?php echo esc_html( number_format_i18n( $anime_total ) ); ?>
					</div>
					<div class="wx-about-stat-label">
						已發布動畫資料
					</div>
				</div>

				<div class="wx-about-stat">
					<div class="wx-about-stat-number">
						<?php echo esc_html( number_format_i18n( $wx_days_online ) ); ?>
					</div>
					<div class="wx-about-stat-label">
						已上線天數（自 2026/6/11）
					</div>
				</div>

				<div class="wx-about-stat">
					<div class="wx-about-stat-number">繁中</div>
					<div class="wx-about-stat-label">
						主要內容與社群語言
					</div>
				</div>

				<div class="wx-about-stat">
					<div class="wx-about-stat-number">社群</div>
					<div class="wx-about-stat-label">
						追番、評分與動漫討論
					</div>
				</div>

				<div class="wx-about-stat">
					<div class="wx-about-stat-number">開發中</div>
					<div class="wx-about-stat-label">
						動漫資料庫 API
					</div>
				</div>

			</div>

			<p
				class="wx-about-muted"
				style="margin:18px auto 0;text-align:center;font-size:12px;line-height:1.7;"
			>
				資料數量會隨新增、更新、合併、下架及內容品質檢查而變動。
			</p>
		</section>

		<!-- 編輯團隊 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				編輯團隊
			</h2>

			<p class="wx-about-lead wx-about-center">
				微笑動漫的內容，由具備多年動漫觀賞、評論與資料整理經驗的編輯群負責。
				每一則作品短評、專欄與資料整理，都經過人工查證與複核後才會發布。
			</p>

			<div class="wx-about-grid-2" style="margin-top:32px;">

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">✍️</div>
					<h3 class="wx-about-card-title">笑編｜主編</h3>
					<p class="wx-about-card-text">
						自 2008 年開始接觸動漫，累積超過十五年的觀賞與評論經驗，
						橫跨不同年代、類型與播出季度的作品。負責編輯短評的方向與品質把關，
						以台灣讀者熟悉的語氣，提供具體、不劇透且經查證的觀點，
						而非制式的資料複述。
					</p>
				</article>

				<article class="wx-about-card">
					<div class="wx-about-card-icon" aria-hidden="true">🔎</div>
					<h3 class="wx-about-card-title">資料查核與整理</h3>
					<p class="wx-about-card-text">
						負責繁體中文名稱、系列關聯、製作資料與合法觀看資訊的
						人工校正與來源比對。資料若有疑義，以官方公告及
						多來源交叉查核為準，並歡迎讀者回報更正。
					</p>
				</article>

			</div>

			<div class="wx-about-notice">
				<span class="wx-about-notice-icon" aria-hidden="true">✅</span>
				<span>
					本站的作品短評皆為原創，並經人工查證與複核後才發布。
					AI 工具僅用於輔助整理草稿，最終內容一律由編輯人工修改、
					確認事實與來源後才會刊出，並標註審核者。
				</span>
			</div>
		</section>

		<!-- 資料來源 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading">
				資料來源與處理方式
			</h2>

			<p class="wx-about-lead" style="margin:0;">
				本站的<strong>繁體中文作品說明、編輯短評、系列分類與各地區合法觀看整理，
				皆由編輯人工原創、查核與撰寫</strong>；作品的基礎識別資料，
				則參考下列公開資料庫並清楚標註來源。
			</p>

			<p class="wx-about-lead" style="margin:14px 0 0;">
				本站依各服務的使用規範，透過第三方網站或公開 API
				進行作品識別、資料比對、連結建立及內容查核，
				並在必要範圍內快取資料以改善網站效能。
			</p>

			<p class="wx-about-lead" style="margin:14px 0 0;">
				第三方 API 可供存取，不代表相關作品、圖片、影片、
				商標或資料內容的著作權發生移轉，
				也不代表本站能將所有第三方資料重新授權給其他使用者。
			</p>

			<div class="wx-about-api-list" style="margin-top:30px;">

				<?php
				$sources = [
					[
						'color' => '#02a9ff',
						'name'  => 'AniList',
						'desc'  => '用於部分作品識別、羅馬字名稱、製作資料、評分及作品關聯參考。',
						'url'   => 'https://anilist.co/',
					],
					[
						'color' => '#f39c12',
						'name'  => 'Bangumi',
						'desc'  => '用於部分中文名稱、人物、製作資料、關聯作品及評分參考。',
						'url'   => 'https://bgm.tv/',
					],
					[
						'color' => '#2e51a2',
						'name'  => 'MyAnimeList／Jikan',
						'desc'  => '用於部分 MyAnimeList 作品識別、評分、人物及製作資料參考。',
						'url'   => 'https://jikan.moe/',
					],
					[
						'color' => '#22c55e',
						'name'  => 'AnimeThemes.moe',
						'desc'  => '用於部分動畫片頭曲、片尾曲及相關主題曲識別資料。',
						'url'   => 'https://animethemes.moe/',
					],
				];

				foreach ( $sources as $source ) :
					?>
					<div class="wx-about-api">

						<span
							class="wx-about-api-dot"
							style="background:<?php echo esc_attr( $source['color'] ); ?>;"
							aria-hidden="true"
						></span>

						<strong class="wx-about-api-name">
							<?php echo esc_html( $source['name'] ); ?>
						</strong>

						<span class="wx-about-api-desc">
							<?php echo esc_html( $source['desc'] ); ?>
						</span>

						<a
							href="<?php echo esc_url( $source['url'] ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="wx-about-api-link"
						>
							前往網站
							<i
								class="fa-solid fa-arrow-up-right-from-square"
								aria-hidden="true"
							></i>
						</a>

					</div>
				<?php endforeach; ?>

			</div>

			<div class="wx-about-notice">
				<span class="wx-about-notice-icon" aria-hidden="true">📝</span>
				<span>
					繁體中文名稱、系列分類、作品說明及各地區合法觀看資訊，
					可能由本站進一步整理。作品授權與上架情況可能因地區及時間變動，
					實際資訊請以作品官方公告及合法平台頁面為準。
				</span>
			</div>
		</section>

		<!-- 品牌歷史 -->
		<section class="wx-about-section">

			<h2 class="wx-about-heading wx-about-heading-center">
				微笑動漫的故事
			</h2>

			<p class="wx-about-lead wx-about-center">
				從早期動漫愛好者社群，到重新建立一個透明、
				尊重著作權並適合現代網路環境的繁體中文 ACG 平台。
			</p>

			<div class="wx-about-timeline">

				<div class="wx-about-timeline-item">
					<div class="wx-about-timeline-dot" aria-hidden="true">🌱</div>

					<article class="wx-about-timeline-card">
						<span class="wx-about-timeline-year">2008</span>

						<h3 class="wx-about-timeline-title">
							動漫愛好者社群的起點
						</h3>

						<p class="wx-about-timeline-text">
							「微笑動漫」最早源自一群動漫愛好者建立的交流社群。
							當時大家透過網站及語音平台分享作品心得、
							音樂與生活，逐漸形成共同的社群記憶。
						</p>
					</article>
				</div>

				<div class="wx-about-timeline-item">
					<div class="wx-about-timeline-dot" aria-hidden="true">🌙</div>

					<article class="wx-about-timeline-card">
						<span class="wx-about-timeline-year">轉型與沉澱</span>

						<h3 class="wx-about-timeline-title">
							隨著網路環境改變重新思考
						</h3>

						<p class="wx-about-timeline-text">
							隨著平台、法規及內容產業環境改變，
							早期社群逐漸停止運作。
							這段期間也讓我們重新思考著作權、
							資訊來源、社群責任及長期維運的重要性。
						</p>
					</article>
				</div>

				<div class="wx-about-timeline-item">
					<div class="wx-about-timeline-dot" aria-hidden="true">🚀</div>

					<article class="wx-about-timeline-card">
						<span class="wx-about-timeline-year">2026年6月11日</span>

						<h3 class="wx-about-timeline-title">
							以 WeixiaoACG 重新出發
						</h3>

						<p class="wx-about-timeline-text">
							微笑動漫以 weixiaoacg.com 重新建立，
							並於 2026 年 6 月 11 日正式上線，
							定位為繁體中文 ACG 資料庫、內容媒體與社群平台，
							專注於作品資料、新番資訊、動漫新聞、
							評論專欄、追番紀錄及會員討論。
						</p>

						<p class="wx-about-timeline-text">
							我們參考成熟動漫資料庫與社群平台的發展經驗，
							但保有自己的繁體中文定位、資料架構、
							內容原則與社群文化。
						</p>

						<p class="wx-about-timeline-text">
							我們希望以透明、尊重著作權且能長期維護的方式，
							持續推廣動漫文化，讓更多好作品被不同使用者看見。
						</p>
					</article>
				</div>

				<div class="wx-about-timeline-item">
					<div class="wx-about-timeline-dot" aria-hidden="true">🧩</div>

					<article class="wx-about-timeline-card">
						<span class="wx-about-timeline-year">下一階段</span>

						<h3 class="wx-about-timeline-title">
							建立繁體中文動漫資料與社群服務
						</h3>

						<p class="wx-about-timeline-text">
							WeixiaoACG 將逐步整理現有作品資料、
							建立本站統一作品識別、完善繁體中文名稱與來源紀錄，
							並持續改善新聞、專欄、追番、評分及社群功能。
						</p>

						<p class="wx-about-timeline-text">
							當資料架構與使用規範準備完成後，
							我們也希望提供文件化的開發者 API，
							降低繁體中文網站、App、搜尋工具及研究專案的資料門檻。
						</p>
					</article>
				</div>

			</div>
		</section>

		<!-- 營運透明 -->
		<section class="wx-about-section">

			<div class="wx-about-transparency">

				<h3>廣告、內容與網站營運透明度</h3>

				<p>
					微笑動漫的一般網站內容可以免費瀏覽。
					為支付網域、伺服器、網站安全、資料維護、
					新聞整理、內容編輯及社群管理成本，
					本站可能透過 Google AdSense 或其他廣告服務顯示廣告，
					也可能接受使用者自願贊助。
				</p>

				<p>
					廣告內容通常由第三方廣告系統投放，
					不代表本站保證、推薦或認同廣告所宣稱的產品與服務。
					若出現贊助文章、聯盟連結或商業合作內容，
					本站會在合理可行的範圍內標示「廣告」、
					「贊助」、「合作」或其他適當揭露文字。
				</p>

				<p>
					本站不會因廣告合作而將未經查核的宣傳內容偽裝成獨立新聞或評論。
					評論與專欄可能包含作者觀點；
					商業合作內容則會在合理可行的範圍內另外標示。
				</p>

				<p>
					廣告服務可能使用 Cookie 或類似技術，
					以提供廣告、限制顯示頻率、衡量成效或防止濫用。
					相關資料處理方式請參閱本站隱私權政策。
				</p>

				<p>
					廣告或贊助收入不會改變第三方作品、圖片、
					商標及資料內容的權利歸屬，
					也不代表本站可以提供未經授權的完整作品或下載服務。
				</p>

				<div class="wx-about-links">

					<a
						href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"
						class="wx-about-link"
					>
						<i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
						隱私權政策
					</a>

					<a
						href="<?php echo esc_url( home_url( '/disclaimer/' ) ); ?>"
						class="wx-about-link"
					>
						<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
						免責聲明
					</a>

					<a
						href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"
						class="wx-about-link"
					>
						<i class="fa-solid fa-file-contract" aria-hidden="true"></i>
						使用條款
					</a>

				</div>

			</div>
		</section>

		<!-- 聯絡 -->
		<section class="wx-about-section">

			<div class="wx-about-cta">

				<div class="wx-about-cta-icon" aria-hidden="true">✉️</div>

				<h2>聯絡微笑動漫</h2>

				<p>
					如果您發現作品資料錯誤、重複作品、錯誤外部 ID、
					新聞內容需要更正、失效連結、社群違規內容、
					隱私問題或著作權疑慮，
					歡迎提供相關頁面網址、具體說明及可供查核的資訊。
				</p>

				<p>
					如果您是動漫愛好者、作者、社群管理者、開發者、
					研究者、內容平台、出版社、代理商或合法影音服務，
					也歡迎與我們聯絡資料校正、內容合作、
					API測試及其他合作可能性。
				</p>

				<div class="wx-about-links">

					<a
						href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"
						class="wx-about-link"
					>
						<i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
						前往聯絡頁面
					</a>

					<a
						href="mailto:weixiaoacg.com@gmail.com"
						class="wx-about-link"
					>
						<i class="fa-solid fa-envelope" aria-hidden="true"></i>
						weixiaoacg.com@gmail.com
					</a>

					<a
						href="<?php echo esc_url( home_url( '/columns/' ) ); ?>"
						class="wx-about-link"
					>
						<i class="fa-solid fa-newspaper" aria-hidden="true"></i>
						閱讀專欄內容
					</a>

				</div>

			</div>
		</section>

	</main>
</div>

<?php
get_footer();