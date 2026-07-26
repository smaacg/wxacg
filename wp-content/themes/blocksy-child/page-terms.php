<?php
/**
 * Template Name: 使用條款
 * Template Post Type: page
 *
 * @package WeixiaoACG
 * @version 3.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * 取得指定頁面的網址。
 * 若後台尚未建立該頁面，則使用預設網址。
 *
 * @param string $slug 頁面代稱。
 * @return string
 */
$get_static_page_url = static function ( $slug ) {
	$page = get_page_by_path( $slug );

	if ( $page instanceof WP_Post ) {
		return get_permalink( $page );
	}

	return home_url( '/' . trim( $slug, '/' ) . '/' );
};

$privacy_url   = $get_static_page_url( 'privacy' );
$disclaimer_url = $get_static_page_url( 'disclaimer' );
$contact_url   = $get_static_page_url( 'contact' );
$community_url = $get_static_page_url( 'community' );

$contact_email = 'weixiaoacg.com@gmail.com';
$updated_date  = '2026 年 7 月 22 日';

$prohibited_items = [
	[
		'icon' => '🚫',
		'title' => '違法或侵權內容',
		'text'  => '發布違法、詐騙、誹謗、威脅、騷擾、仇恨、猥褻，或侵害著作權、商標權、隱私權及其他權利的內容。',
	],
	[
		'icon' => '🎭',
		'title' => '冒用身分',
		'text'  => '冒充他人、偽造身分，或虛假聲稱與任何創作者、公司、組織、平台或權利人具有合作關係。',
	],
	[
		'icon' => '🦠',
		'title' => '惡意程式',
		'text'  => '上傳、傳播或連結至病毒、木馬、惡意腳本、破壞性程式碼，或其他可能危害使用者與系統安全的內容。',
	],
	[
		'icon' => '🤖',
		'title' => '濫用自動化工具',
		'text'  => '未經書面許可，使用爬蟲、機器人或自動化程式大量擷取資料、建立鏡像網站，或造成伺服器不合理負擔。',
	],
	[
		'icon' => '🔓',
		'title' => '未授權存取',
		'text'  => '嘗試繞過登入、權限、速率限制及安全機制，或未經授權存取帳號、資料庫、API、伺服器與管理功能。',
	],
	[
		'icon' => '📣',
		'title' => '垃圾訊息與操縱',
		'text'  => '大量發布重複內容、廣告、釣魚連結，或利用多重帳號、程式與不正當手段操縱評分、留言、排行及積分。',
	],
	[
		'icon' => '🏴‍☠️',
		'title' => '未授權內容',
		'text'  => '分享、索取或引導他人取得盜版影片、漫畫、小說、音樂、破解程式，以及其他未經權利人授權的內容。',
	],
	[
		'icon' => '⚡',
		'title' => '干擾平台運作',
		'text'  => '以任何方式干擾本站正常運作、妨礙其他使用者，或利用系統漏洞取得不當利益。',
	],
];

$quick_links = [
	[
		'number' => '01',
		'label'  => '服務說明',
	],
	[
		'number' => '02',
		'label'  => '使用資格與帳號',
	],
	[
		'number' => '03',
		'label'  => '社群內容規範',
	],
	[
		'number' => '04',
		'label'  => '禁止行為',
	],
	[
		'number' => '05',
		'label'  => '評分與積分',
	],
	[
		'number' => '06',
		'label'  => '新聞、評論與專欄',
	],
	[
		'number' => '07',
		'label'  => '著作權與投稿',
	],
	[
		'number' => '08',
		'label'  => '第三方資料與連結',
	],
	[
		'number' => '09',
		'label'  => 'API 與自動化存取',
	],
	[
		'number' => '10',
		'label'  => '廣告與贊助',
	],
	[
		'number' => '11',
		'label'  => '隱私與資料處理',
	],
	[
		'number' => '12',
		'label'  => '停權與服務調整',
	],
	[
		'number' => '13',
		'label'  => '免責與責任限制',
	],
	[
		'number' => '14',
		'label'  => '條款修改',
	],
	[
		'number' => '15',
		'label'  => '聯絡與爭議處理',
	],
];

get_header();
?>

<style>
	/*
	 * WeixiaoACG 使用條款頁面
	 * 所有樣式限定於 .wx-terms，避免影響其他頁面。
	 */
	.wx-terms {
		--wx-primary: #6c5ce7;
		--wx-primary-dark: #5142c7;
		--wx-secondary: #ff6b9d;
		--wx-text: #202235;
		--wx-muted: #687086;
		--wx-border: rgba(108, 92, 231, 0.14);
		--wx-surface: rgba(255, 255, 255, 0.94);
		--wx-soft: #f7f6ff;
		--wx-warning: #fff8e7;
		--wx-warning-border: #f2ca67;
		--wx-shadow: 0 16px 45px rgba(42, 38, 94, 0.08);
		--wx-radius: 22px;

		color: var(--wx-text);
		background:
			radial-gradient(circle at 10% 8%, rgba(108, 92, 231, 0.08), transparent 28rem),
			radial-gradient(circle at 92% 30%, rgba(255, 107, 157, 0.07), transparent 26rem),
			#fbfbfe;
		font-family: inherit;
		line-height: 1.8;
	}

	.wx-terms,
	.wx-terms * {
		box-sizing: border-box;
	}

	.wx-terms a {
		color: var(--wx-primary-dark);
		text-decoration-thickness: 1px;
		text-underline-offset: 3px;
	}

	.wx-terms a:hover {
		color: var(--wx-secondary);
	}

	.wx-terms-container {
		width: min(1120px, calc(100% - 40px));
		margin-inline: auto;
	}

	.wx-terms-hero {
		position: relative;
		overflow: hidden;
		padding: 88px 20px 76px;
		color: #fff;
		text-align: center;
		background:
			linear-gradient(135deg, rgba(56, 43, 151, 0.97), rgba(108, 92, 231, 0.94) 54%, rgba(213, 77, 139, 0.90));
	}

	.wx-terms-hero::before,
	.wx-terms-hero::after {
		position: absolute;
		border-radius: 50%;
		content: "";
		pointer-events: none;
	}

	.wx-terms-hero::before {
		top: -130px;
		left: -90px;
		width: 330px;
		height: 330px;
		background: rgba(255, 255, 255, 0.08);
	}

	.wx-terms-hero::after {
		right: -80px;
		bottom: -160px;
		width: 370px;
		height: 370px;
		background: rgba(255, 255, 255, 0.07);
	}

	.wx-terms-hero-inner {
		position: relative;
		z-index: 1;
		width: min(820px, 100%);
		margin-inline: auto;
	}

	.wx-terms-hero-icon {
		display: grid;
		width: 74px;
		height: 74px;
		margin: 0 auto 20px;
		border: 1px solid rgba(255, 255, 255, 0.28);
		border-radius: 24px;
		background: rgba(255, 255, 255, 0.14);
		box-shadow: 0 14px 40px rgba(22, 16, 78, 0.2);
		font-size: 34px;
		place-items: center;
		backdrop-filter: blur(10px);
	}

	.wx-terms-title {
		margin: 0;
		color: #fff;
		font-size: clamp(2.25rem, 6vw, 4rem);
		font-weight: 850;
		letter-spacing: -0.035em;
		line-height: 1.15;
	}

	.wx-terms-subtitle {
		max-width: 680px;
		margin: 18px auto 0;
		color: rgba(255, 255, 255, 0.9);
		font-size: 1.04rem;
	}

	.wx-terms-updated {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		margin-top: 24px;
		padding: 8px 15px;
		border: 1px solid rgba(255, 255, 255, 0.25);
		border-radius: 999px;
		background: rgba(255, 255, 255, 0.12);
		color: #fff;
		font-size: 0.92rem;
	}

	.wx-terms-content {
		padding-top: 52px;
		padding-bottom: 90px;
	}

	.wx-terms-notice,
	.wx-terms-card,
	.wx-terms-toc,
	.wx-terms-cta {
		border: 1px solid var(--wx-border);
		border-radius: var(--wx-radius);
		background: var(--wx-surface);
		box-shadow: var(--wx-shadow);
	}

	.wx-terms-notice {
		padding: 26px 30px;
	}

	.wx-terms-notice p {
		margin: 0;
		font-size: 1.06rem;
	}

	.wx-terms-notice strong {
		color: var(--wx-primary-dark);
	}

	.wx-terms-toc {
		margin-top: 24px;
		padding: 28px 30px;
	}

	.wx-terms-toc-title {
		display: flex;
		align-items: center;
		gap: 10px;
		margin: 0 0 18px;
		font-size: 1.2rem;
	}

	.wx-terms-toc-grid {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 10px;
	}

	.wx-terms-toc-link {
		display: flex;
		align-items: center;
		gap: 10px;
		min-width: 0;
		padding: 10px 12px;
		border: 1px solid transparent;
		border-radius: 12px;
		color: var(--wx-text) !important;
		text-decoration: none;
		transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
	}

	.wx-terms-toc-link:hover {
		transform: translateY(-1px);
		border-color: var(--wx-border);
		background: var(--wx-soft);
	}

	.wx-terms-toc-number {
		flex: 0 0 auto;
		color: var(--wx-primary);
		font-size: 0.77rem;
		font-weight: 800;
	}

	.wx-terms-section {
		scroll-margin-top: 110px;
		margin-top: 58px;
	}

	.wx-terms-section-header {
		display: flex;
		align-items: center;
		gap: 14px;
		margin-bottom: 18px;
	}

	.wx-terms-section-number {
		display: inline-grid;
		flex: 0 0 auto;
		width: 46px;
		height: 46px;
		border-radius: 15px;
		background: linear-gradient(135deg, var(--wx-primary), var(--wx-secondary));
		box-shadow: 0 10px 24px rgba(108, 92, 231, 0.2);
		color: #fff;
		font-size: 0.84rem;
		font-weight: 800;
		place-items: center;
	}

	.wx-terms-section-title {
		margin: 0;
		color: var(--wx-text);
		font-size: clamp(1.45rem, 3vw, 2rem);
		font-weight: 800;
		letter-spacing: -0.025em;
		line-height: 1.3;
	}

	.wx-terms-card {
		padding: 30px;
	}

	.wx-terms-card p {
		margin: 0 0 16px;
	}

	.wx-terms-card p:last-child {
		margin-bottom: 0;
	}

	.wx-terms-list {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	.wx-terms-list li {
		position: relative;
		padding-left: 29px;
	}

	.wx-terms-list li + li {
		margin-top: 13px;
	}

	.wx-terms-list li::before {
		position: absolute;
		top: 0.53em;
		left: 2px;
		width: 10px;
		height: 10px;
		border: 3px solid rgba(108, 92, 231, 0.2);
		border-radius: 50%;
		background: var(--wx-primary);
		content: "";
	}

	.wx-terms-prohibited-grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 16px;
	}

	.wx-terms-prohibited-item {
		display: flex;
		align-items: flex-start;
		gap: 15px;
		padding: 22px;
		border: 1px solid var(--wx-border);
		border-radius: 18px;
		background: rgba(255, 255, 255, 0.92);
		box-shadow: 0 10px 30px rgba(42, 38, 94, 0.05);
	}

	.wx-terms-prohibited-icon {
		display: grid;
		flex: 0 0 auto;
		width: 46px;
		height: 46px;
		border-radius: 15px;
		background: var(--wx-soft);
		font-size: 22px;
		place-items: center;
	}

	.wx-terms-prohibited-title {
		margin: 0 0 5px;
		color: var(--wx-text);
		font-size: 1.03rem;
		font-weight: 800;
	}

	.wx-terms-prohibited-text {
		margin: 0;
		color: var(--wx-muted);
		font-size: 0.95rem;
		line-height: 1.7;
	}

	.wx-terms-highlight {
		margin-top: 20px;
		padding: 18px 20px;
		border: 1px solid var(--wx-warning-border);
		border-radius: 15px;
		background: var(--wx-warning);
		color: #604b16;
	}

	.wx-terms-highlight p {
		margin: 0;
	}

	.wx-terms-source-list {
		display: flex;
		flex-wrap: wrap;
		gap: 9px;
		margin-top: 18px;
	}

	.wx-terms-source {
		display: inline-flex;
		align-items: center;
		padding: 7px 12px;
		border: 1px solid var(--wx-border);
		border-radius: 999px;
		background: var(--wx-soft);
		color: var(--wx-primary-dark);
		font-size: 0.88rem;
		font-weight: 700;
	}

	.wx-terms-cta {
		margin-top: 66px;
		padding: 45px 30px;
		text-align: center;
		background:
			linear-gradient(145deg, rgba(108, 92, 231, 0.08), rgba(255, 107, 157, 0.08)),
			#fff;
	}

	.wx-terms-cta-icon {
		display: grid;
		width: 64px;
		height: 64px;
		margin: 0 auto 17px;
		border-radius: 20px;
		background: linear-gradient(135deg, var(--wx-primary), var(--wx-secondary));
		box-shadow: 0 12px 28px rgba(108, 92, 231, 0.22);
		font-size: 27px;
		place-items: center;
	}

	.wx-terms-cta-title {
		margin: 0;
		color: var(--wx-text);
		font-size: 1.7rem;
		font-weight: 850;
	}

	.wx-terms-cta-description {
		max-width: 650px;
		margin: 12px auto 24px;
		color: var(--wx-muted);
	}

	.wx-terms-actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: center;
		gap: 12px;
	}

	.wx-terms-button {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		min-height: 46px;
		padding: 10px 19px;
		border: 1px solid var(--wx-primary);
		border-radius: 999px;
		background: var(--wx-primary);
		box-shadow: 0 10px 25px rgba(108, 92, 231, 0.19);
		color: #fff !important;
		font-weight: 750;
		text-decoration: none !important;
		transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
	}

	.wx-terms-button:hover {
		transform: translateY(-2px);
		background: var(--wx-primary-dark);
		box-shadow: 0 14px 30px rgba(108, 92, 231, 0.25);
	}

	.wx-terms-button-secondary {
		border-color: var(--wx-border);
		background: #fff;
		box-shadow: none;
		color: var(--wx-primary-dark) !important;
	}

	.wx-terms-button-secondary:hover {
		background: var(--wx-soft);
	}

	.wx-terms-footer-note {
		margin: 28px auto 0;
		color: var(--wx-muted);
		font-size: 0.86rem;
		text-align: center;
	}

	@media (max-width: 900px) {
		.wx-terms-toc-grid {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
	}

	@media (max-width: 720px) {
		.wx-terms-container {
			width: min(100% - 28px, 1120px);
		}

		.wx-terms-hero {
			padding: 68px 18px 58px;
		}

		.wx-terms-content {
			padding-top: 34px;
			padding-bottom: 65px;
		}

		.wx-terms-notice,
		.wx-terms-toc,
		.wx-terms-card,
		.wx-terms-cta {
			padding: 22px;
			border-radius: 18px;
		}

		.wx-terms-toc-grid,
		.wx-terms-prohibited-grid {
			grid-template-columns: 1fr;
		}

		.wx-terms-section {
			margin-top: 46px;
		}

		.wx-terms-section-header {
			align-items: flex-start;
		}

		.wx-terms-prohibited-item {
			padding: 19px;
		}
	}

	@media (max-width: 480px) {
		.wx-terms-hero-icon {
			width: 64px;
			height: 64px;
			border-radius: 20px;
			font-size: 29px;
		}

		.wx-terms-section-number {
			width: 42px;
			height: 42px;
			border-radius: 13px;
		}

		.wx-terms-toc-grid {
			gap: 5px;
		}

		.wx-terms-actions {
			flex-direction: column;
		}

		.wx-terms-button {
			width: 100%;
		}
	}

	@media (prefers-reduced-motion: reduce) {
		.wx-terms *,
		.wx-terms *::before,
		.wx-terms *::after {
			scroll-behavior: auto !important;
			transition: none !important;
		}
	}
</style>

<main id="primary" class="wx-terms">
	<header class="wx-terms-hero">
		<div class="wx-terms-hero-inner">
			<div class="wx-terms-hero-icon" aria-hidden="true">📋</div>

			<h1 class="wx-terms-title">
				<?php echo esc_html__( '使用條款', 'blocksy-child' ); ?>
			</h1>

			<p class="wx-terms-subtitle">
				本條款說明您使用微笑動漫 WeixiaoACG 網站、會員、社群、評分、追番、內容及相關服務時，應遵守的基本規範。
			</p>

			<p class="wx-terms-updated">
				<span aria-hidden="true">🗓️</span>
				<span>最後更新日期：<?php echo esc_html( $updated_date ); ?></span>
			</p>
		</div>
	</header>

	<div class="wx-terms-container wx-terms-content">

		<section class="wx-terms-notice" aria-labelledby="terms-introduction">
			<p id="terms-introduction">
				<strong>歡迎使用微笑動漫 WeixiaoACG。</strong>
				請在存取或使用本站服務前仔細閱讀本使用條款。當您存取、瀏覽、註冊或使用本站任何功能，即表示您已閱讀並同意遵守本條款及
				<a href="<?php echo esc_url( $privacy_url ); ?>">隱私權政策</a>。
				若您不同意其中任何內容，請停止使用本站相關服務。
			</p>
		</section>

		<nav class="wx-terms-toc" aria-label="使用條款目錄">
			<h2 class="wx-terms-toc-title">
				<span aria-hidden="true">📑</span>
				條款目錄
			</h2>

			<div class="wx-terms-toc-grid">
				<?php foreach ( $quick_links as $index => $quick_link ) : ?>
					<a
						class="wx-terms-toc-link"
						href="#terms-section-<?php echo esc_attr( (string) ( $index + 1 ) ); ?>"
					>
						<span class="wx-terms-toc-number">
							<?php echo esc_html( $quick_link['number'] ); ?>
						</span>
						<span><?php echo esc_html( $quick_link['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>

		<!-- 01 服務說明 -->
		<section id="terms-section-1" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">01</span>
				<h2 class="wx-terms-section-title">服務說明</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					微笑動漫 WeixiaoACG 是獨立營運的繁體中文 ACG 資訊與社群平台，提供動畫及相關作品資料、新番資訊、動漫新聞、評論、專欄報導、評分參考、作品收藏、追番紀錄、觀看進度、會員互動及討論等功能。
				</p>

				<p>
					本站可能持續開發作品識別、跨平台資料對照、搜尋工具及開發者 API。個別功能可能處於測試、Beta、調整或暫停狀態，實際提供內容以本站當時顯示為準。
				</p>

				<p>
					微笑動漫並非 AniList、Bangumi、MyAnimeList、Jikan、AnimeThemes.moe，以及任何動畫製作公司、出版社、代理商、串流平台或權利人的官方網站，除非另有明確書面說明。
				</p>
			</div>
		</section>

		<!-- 02 使用資格與帳號 -->
		<section id="terms-section-2" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">02</span>
				<h2 class="wx-terms-section-title">使用資格與帳號規範</h2>
			</div>

			<div class="wx-terms-card">
				<ul class="wx-terms-list">
					<li>您必須年滿 13 歲，或符合所在地區使用網路服務與同意資料處理的最低法定年齡，方可自行註冊帳號。</li>
					<li>未達所在地法定成年年齡者，應在父母或法定代理人同意及指導下使用本站。</li>
					<li>您應提供有效且合理正確的帳號資訊，並自行保管密碼及登入憑證。</li>
					<li>您不得出售、出租、轉讓帳號，或允許他人利用您的帳號進行違規行為。</li>
					<li>若發現帳號遭未授權使用、安全異常或個人資料可能外洩，請立即變更密碼並聯絡本站。</li>
					<li>本站可能提供 Google 等第三方登入方式；第三方服務仍受其自身條款與隱私政策約束。</li>
					<li>您應對透過自身帳號進行的發文、留言、評分、收藏及其他操作負責，但非因您可合理控制之原因造成者除外。</li>
				</ul>
			</div>
		</section>

		<!-- 03 社群內容 -->
		<section id="terms-section-3" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">03</span>
				<h2 class="wx-terms-section-title">社群討論與使用者內容</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					本站可能允許會員發布留言、評論、討論、評分、個人介紹、清單、圖片或其他使用者生成內容。您應確保所發布內容合法、與討論主題相關，並尊重其他使用者。
				</p>

				<ul class="wx-terms-list">
					<li>請理性討論作品、角色、創作者及相關議題，不得針對其他使用者進行人身攻擊、霸凌、騷擾或歧視。</li>
					<li>涉及作品劇情的重要內容，應依本站功能或社群習慣標示劇透，避免影響其他使用者體驗。</li>
					<li>不得公布他人的真實姓名、地址、電話、電子郵件、登入資訊或其他未經同意的個人資料。</li>
					<li>不得發布未授權影片、漫畫、小說、音樂下載連結、破解資源或引導盜版存取的內容。</li>
					<li>若內容包含宣傳、合作、贊助、利益關係或收到免費產品，應以清楚方式揭露。</li>
					<li>本站得依社群安全、法律要求及平台營運需要，隱藏、限制、刪除或停止顯示相關內容。</li>
				</ul>

				<div class="wx-terms-highlight">
					<p>
						<strong>內容管理說明：</strong>
						本站會盡力維護友善、安全的討論環境，但無法保證所有使用者內容都會在發布前經過審核。若您發現疑似違法、侵權、騷擾、垃圾訊息或不當內容，請使用檢舉功能或聯絡本站。
					</p>
				</div>
			</div>
		</section>

		<!-- 04 禁止行為 -->
		<section id="terms-section-4" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">04</span>
				<h2 class="wx-terms-section-title">禁止行為</h2>
			</div>

			<div class="wx-terms-prohibited-grid">
				<?php foreach ( $prohibited_items as $item ) : ?>
					<article class="wx-terms-prohibited-item">
						<span class="wx-terms-prohibited-icon" aria-hidden="true">
							<?php echo esc_html( $item['icon'] ); ?>
						</span>

						<div>
							<h3 class="wx-terms-prohibited-title">
								<?php echo esc_html( $item['title'] ); ?>
							</h3>

							<p class="wx-terms-prohibited-text">
								<?php echo esc_html( $item['text'] ); ?>
							</p>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<!-- 05 評分與積分 -->
		<section id="terms-section-5" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">05</span>
				<h2 class="wx-terms-section-title">評分、排行與積分系統</h2>
			</div>

			<div class="wx-terms-card">
				<ul class="wx-terms-list">
					<li>本站的評分、排行及推薦結果可能受到資料量、演算法、時間範圍及使用者參與程度影響，不代表官方評價或絕對品質標準。</li>
					<li>會員應依真實使用意見進行評分，不得使用大量帳號、程式或交換利益等方式操縱結果。</li>
					<li>積分、經驗值、徽章、等級及其他站內獎勵均屬虛擬機制，除非另有明確說明，否則不具有現金價值，亦不得要求兌換現金。</li>
					<li>積分可能透過登入、追番、收藏、留言、完成任務或其他正常參與行為取得。</li>
					<li>本站得因系統錯誤、作弊、濫用或違反規則而修正、撤銷或重新計算積分、評分及排行資料。</li>
					<li>本站得依營運需要調整積分、等級、任務及獎勵規則，重大變更將盡可能透過網站公告說明。</li>
					<li>帳號遭永久停權或依法刪除後，相關積分、等級及虛擬獎勵可能一併失效。</li>
				</ul>
			</div>
		</section>

		<!-- 06 新聞評論專欄 -->
		<section id="terms-section-6" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">06</span>
				<h2 class="wx-terms-section-title">動漫新聞、評論與專欄報導</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					本站發布的動漫新聞、評論、介紹及專欄報導，可能包含公開資料整理、編輯觀點、作品分析及作者個人意見。評論與專欄中的觀點不必然代表動畫製作方、出版社、代理商、平台或其他第三方立場。
				</p>

				<ul class="wx-terms-list">
					<li>本站會盡力查核資訊來源，但產業消息、上映日期、播出時間、授權範圍及平台上架情況仍可能隨時變動。</li>
					<li>引用官方公告、新聞稿、公開訪談及第三方資料時，將在合理範圍內標示或連結來源。</li>
					<li>評論內容屬於作者主觀分析，不應視為購買、投資、法律或其他專業建議。</li>
					<li>若文章包含合作、贊助、聯盟連結或其他商業關係，本站將依適用規範進行適當揭露。</li>
					<li>如發現內容有事實錯誤、翻譯問題或來源需要補充，歡迎提供可信來源協助修正。</li>
				</ul>
			</div>
		</section>

		<!-- 07 著作權 -->
		<section id="terms-section-7" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">07</span>
				<h2 class="wx-terms-section-title">著作權、商標與投稿授權</h2>
			</div>

			<div class="wx-terms-card">
				<ul class="wx-terms-list">
					<li>動畫、漫畫、小說、遊戲、音樂、角色名稱、商標、封面、劇照及相關素材，其權利原則上屬於各自的創作者、出版社、製作委員會、代理商及其他權利人。</li>
					<li>本站使用相關名稱、圖片或資料，主要為作品識別、資訊介紹、評論、索引與導向合法來源之目的，不表示本站取得其所有權或官方授權。</li>
					<li>本站原創文章、編輯內容、介面設計、品牌識別及自行建立的資料整理成果，在適用法律允許範圍內，其權利歸本站或原作者所有。</li>
					<li>未經授權，不得大量複製、轉載、出售、重新包裝、建立鏡像，或將本站原創內容用於冒充官方來源。</li>
					<li>您仍保有自己投稿內容的原有權利；但當您將內容提交至本站，即授予本站在營運、展示、排版、備份、宣傳及改善服務所必要範圍內，使用、重製、公開傳輸與顯示該內容的非專屬授權。</li>
					<li>您保證有權發布所提交的文字、圖片及其他內容，且該內容不會侵害第三方權利。</li>
				</ul>

				<div class="wx-terms-highlight">
					<p>
						<strong>權利人通知：</strong>
						若您認為本站內容侵害您的著作權、商標權或其他合法權利，請寄送包含「具體網址、權利證明、問題說明及聯絡方式」的通知至
						<a href="mailto:<?php echo esc_attr( antispambot( $contact_email ) ); ?>">
							<?php echo esc_html( antispambot( $contact_email ) ); ?>
						</a>。
						本站將在收到足以辨識的通知後進行查核及必要處理。
					</p>
				</div>
			</div>
		</section>

		<!-- 08 第三方資料 -->
		<section id="terms-section-8" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">08</span>
				<h2 class="wx-terms-section-title">第三方資料來源與外部連結</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					為協助使用者查找作品，本站可能參考、整合或連結第三方公開資料，包括作品識別碼、名稱、播出資訊、評分參考、角色資料及主題曲資訊。各項第三方資料仍受原平台條款、授權方式及權利聲明約束。
				</p>

				<div class="wx-terms-source-list" aria-label="主要第三方資料來源">
					<span class="wx-terms-source">AniList</span>
					<span class="wx-terms-source">Bangumi</span>
					<span class="wx-terms-source">MyAnimeList／Jikan</span>
					<span class="wx-terms-source">AnimeThemes.moe</span>
					<span class="wx-terms-source">官方網站與公開公告</span>
				</div>

				<p style="margin-top: 20px;">
					本站可能提供前往官方網站、合法串流服務、社群平台、影音平台或其他第三方網站的連結。第三方網站的內容、可用性、價格、隱私措施與安全性均由其自行負責，本站無法控制或保證。
				</p>

				<p>
					使用者在離開本站後，應自行閱讀第三方網站的使用條款及隱私政策。外部連結的存在不必然表示本站推薦、擔保或與該第三方存在合作關係。
				</p>
			</div>
		</section>

		<!-- 09 API -->
		<section id="terms-section-9" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">09</span>
				<h2 class="wx-terms-section-title">API、資料使用與自動化存取</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					本站可能提供公開或受限制的開發者 API，用於查詢繁體中文作品資料、本站作品識別碼、季節資訊及外部 ID 對照。API 是否開放、使用額度及可用欄位，以實際 API 文件及開發者條款為準。
				</p>

				<ul class="wx-terms-list">
					<li>未經書面許可，不得繞過 API 金鑰、驗證、權限、速率限制、快取或其他技術限制。</li>
					<li>不得利用 API 建立內容鏡像、冒充本站、重售未經授權的第三方資料，或從事侵權、監控、騷擾及其他違法用途。</li>
					<li>不得將 API 用於大量取得會員個人資料、私人紀錄或其他非公開資訊。</li>
					<li>使用者應依 API 文件標示資料來源、更新時間與必要的第三方歸屬資訊。</li>
					<li>本站可能因安全、效能、授權、維護或濫用情形，變更欄位、調整限制、撤銷金鑰或停止 API 服務。</li>
					<li>除非另有書面約定，API 以現況提供，不保證永久免費、持續可用或與所有應用程式相容。</li>
				</ul>

				<div class="wx-terms-highlight">
					<p>
						一般搜尋引擎依標準方式建立索引，不等同於取得大量複製、重新發布或建立本站資料庫鏡像的授權。
					</p>
				</div>
			</div>
		</section>

		<!-- 10 廣告 -->
		<section id="terms-section-10" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">10</span>
				<h2 class="wx-terms-section-title">廣告、贊助與商業合作</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					為支援網站伺服器、內容製作、資料維護及功能開發，本站目前或未來可能使用 Google AdSense 等第三方廣告服務、接受使用者自願贊助，或進行適當的品牌及內容合作。
				</p>

				<ul class="wx-terms-list">
					<li>第三方廣告服務可能依其政策使用 Cookie、本機儲存空間、裝置識別碼或類似技術，用於投放、衡量及改善廣告。</li>
					<li>使用者可透過瀏覽器、Cookie 同意工具或廣告服務提供者的設定，管理部分廣告與追蹤偏好。</li>
					<li>廣告內容通常由第三方系統提供；本站不保證所有廣告商品、服務或外部頁面的品質與適用性。</li>
					<li>使用者不應以虛假、重複或自動化方式點擊廣告，也不會因點擊廣告而獲得本站積分或其他獎勵。</li>
					<li>若內容為贊助、合作或包含聯盟連結，本站將在合理且適當的位置進行揭露。</li>
				</ul>

				<p>
					關於 Cookie、分析工具及廣告資料處理方式，請參閱本站
					<a href="<?php echo esc_url( $privacy_url ); ?>">隱私權政策</a>。
				</p>
			</div>
		</section>

		<!-- 11 隱私 -->
		<section id="terms-section-11" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">11</span>
				<h2 class="wx-terms-section-title">隱私與個人資料處理</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					本站可能依功能需要處理帳號資料、登入紀錄、收藏、追番進度、評分、留言、裝置與安全紀錄，以及 Cookie 或類似技術產生的資料。
				</p>

				<ul class="wx-terms-list">
					<li>資料將用於提供會員功能、保存使用紀錄、維護安全、防止濫用、分析服務效能及履行必要法律義務。</li>
					<li>公開發表的暱稱、留言、評論及其他社群內容，可能被其他使用者或搜尋引擎看見。</li>
					<li>請勿在公開欄位中發布不必要的身分證件、住址、電話、密碼及其他敏感資料。</li>
					<li>帳號刪除、資料查詢及其他隱私權利的處理方式，以本站隱私權政策及適用法律為準。</li>
				</ul>

				<p>
					完整說明請閱讀：
					<a href="<?php echo esc_url( $privacy_url ); ?>">微笑動漫隱私權政策</a>。
				</p>
			</div>
		</section>

		<!-- 12 停權 -->
		<section id="terms-section-12" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">12</span>
				<h2 class="wx-terms-section-title">內容處理、停權與服務調整</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					若本站合理認為帳號、內容或操作違反本條款、法律、第三方權利或平台安全要求，得依情節採取下列措施：
				</p>

				<ul class="wx-terms-list">
					<li>要求補充說明、修改內容或停止特定行為。</li>
					<li>降低內容曝光、關閉留言、移除連結或刪除內容。</li>
					<li>限制部分會員功能、暫停帳號或永久終止帳號。</li>
					<li>撤銷異常積分、評分、排行、API 金鑰及其他不當取得的利益。</li>
					<li>在法律要求或為保護使用者安全所必要時，保存及提供必要紀錄給有權機關。</li>
				</ul>

				<p>
					本站將視情況考量行為嚴重程度、重複違規、帳號安全及申訴資料，但涉及明顯惡意攻擊、詐騙、兒少安全、重大侵權或系統安全風險時，可能不經事先通知立即採取措施。
				</p>

				<p>
					本站亦得因維護、升級、安全事件、不可抗力、第三方服務中止或營運需求，調整、暫停或終止部分服務。
				</p>
			</div>
		</section>

		<!-- 13 免責 -->
		<section id="terms-section-13" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">13</span>
				<h2 class="wx-terms-section-title">免責聲明與責任限制</h2>
			</div>

			<div class="wx-terms-card">
				<ul class="wx-terms-list">
					<li>本站服務依「現況」及「可用狀態」提供，不保證所有內容永遠正確、完整、即時、不中斷或沒有錯誤。</li>
					<li>動畫播出日期、集數、授權地區、合法觀看平台、價格及上架狀態可能隨時變更，使用者應以權利人或服務平台的最新公告為準。</li>
					<li>使用者生成內容代表其發布者自身觀點，不必然代表本站立場。</li>
					<li>本站不對第三方網站、廣告、商品、外部連結、下載內容及第三方服務的安全性或品質提供保證。</li>
					<li>在適用法律允許的最大範圍內，本站不對因使用、依賴或無法使用本站服務而產生的間接、附帶或衍生損害負責。</li>
					<li>本條款不排除或限制依法不得排除的消費者權利、故意或重大過失責任，以及其他法律強制規定。</li>
				</ul>

				<p>
					更多網站內容與責任說明，請參閱
					<a href="<?php echo esc_url( $disclaimer_url ); ?>">免責聲明</a>。
				</p>
			</div>
		</section>

		<!-- 14 條款變更 -->
		<section id="terms-section-14" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">14</span>
				<h2 class="wx-terms-section-title">條款修改</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					本站可能因服務功能、社群規則、資料來源、廣告與 API 政策、法律要求或營運方式變更，而修訂本使用條款。
				</p>

				<p>
					條款修改後，本站將更新頁面頂部的「最後更新日期」；如屬重大變更，將盡可能透過站內公告、電子郵件或其他適當方式通知。除另有說明外，修訂內容自公布時起生效。
				</p>

				<p>
					您在修訂條款生效後繼續使用本站，即表示您同意受修訂內容約束；若您不同意，應停止使用本站並可依隱私權政策提出帳號或資料處理申請。
				</p>
			</div>
		</section>

		<!-- 15 聯絡 -->
		<section id="terms-section-15" class="wx-terms-section">
			<div class="wx-terms-section-header">
				<span class="wx-terms-section-number">15</span>
				<h2 class="wx-terms-section-title">聯絡、申訴與爭議處理</h2>
			</div>

			<div class="wx-terms-card">
				<p>
					如果您對本條款、帳號處置、內容移除、資料錯誤或其他服務問題有疑問，可透過本站聯絡頁面或電子郵件與我們聯絡。
				</p>

				<p>
					提出申訴時，請提供足以識別問題的帳號名稱、頁面網址、發生時間、問題說明及可供查核的資料，但請勿透過一般電子郵件傳送密碼或完整身分證件。
				</p>

				<p>
					雙方如發生爭議，應先以善意方式溝通處理；如無法解決，則依適用法律及有管轄權之機關或法院處理。
				</p>
			</div>
		</section>

		<section class="wx-terms-cta" aria-labelledby="terms-contact-title">
			<div class="wx-terms-cta-icon" aria-hidden="true">✉️</div>

			<h2 id="terms-contact-title" class="wx-terms-cta-title">
				對使用條款有疑問嗎？
			</h2>

			<p class="wx-terms-cta-description">
				若您需要回報資料錯誤、不當內容、帳號問題或著作權事項，歡迎透過聯絡頁面或電子郵件與我們聯繫。
			</p>

			<div class="wx-terms-actions">
				<a
					href="mailto:<?php echo esc_attr( antispambot( $contact_email ) ); ?>"
					class="wx-terms-button"
				>
					<span aria-hidden="true">📧</span>
					<?php echo esc_html( antispambot( $contact_email ) ); ?>
				</a>

				<a
					href="<?php echo esc_url( $contact_url ); ?>"
					class="wx-terms-button wx-terms-button-secondary"
				>
					<span aria-hidden="true">📝</span>
					前往聯絡頁面
				</a>
			</div>
		</section>

		<p class="wx-terms-footer-note">
			微笑動漫 WeixiaoACG 致力於提供友善、安全且尊重著作權的動漫資訊與交流環境。
		本頁為一般平台使用規範，不構成針對個別情況的法律意見。
	</p>

	</div>
</main>

<?php
get_footer();
