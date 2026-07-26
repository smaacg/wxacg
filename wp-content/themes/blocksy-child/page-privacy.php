<?php
/**
 * Template Name: 隱私權政策
 * Template Post Type: page
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="static-page">

  <!-- Hero -->
  <section class="static-hero">
    <div class="static-hero-inner">
      <div class="static-hero-icon" aria-hidden="true">🔒</div>

      <h1 class="static-hero-title">隱私權政策</h1>

      <p class="static-hero-sub">
        最後更新日期：2026 年 7 月 22 日
      </p>
    </div>
  </section>

  <div class="static-content container">

    <!-- 前言 -->
    <section class="static-section glass-light">
      <p class="static-lead">
        微笑動漫 WeixiaoACG（以下稱「本站」）重視您的隱私與個人資料安全。
        本隱私權政策說明本站在您瀏覽、註冊或使用服務時，可能蒐集哪些資料、
        如何使用與保存資料，以及您可以如何管理個人資料與 Cookie。
      </p>

      <p>
        當您使用本站，即表示您已閱讀並理解本政策。
        如您不同意本政策，請停止使用需要提供個人資料的功能；
        您仍可在不登入的情況下瀏覽大部分公開內容。
      </p>
    </section>

    <!-- 01 基本資料 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">01</span>
        <h2 class="static-section-title">網站營運者與聯絡方式</h2>
      </div>

      <div class="glass-light static-card">
        <ul class="static-list">
          <li>
            <strong>網站名稱：</strong>
            微笑動漫 WeixiaoACG
          </li>

          <li>
            <strong>網站網址：</strong>
            <a
              href="<?php echo esc_url( home_url( '/' ) ); ?>"
              class="static-link"
            >
              <?php echo esc_html( home_url( '/' ) ); ?>
            </a>
          </li>

          <li>
            <strong>隱私權聯絡信箱：</strong>
            <a
              href="mailto:weixiaoacg.com@gmail.com"
              class="static-link"
            >
              weixiaoacg.com@gmail.com
            </a>
          </li>
        </ul>

        <p>
          如需查詢、更正、刪除或匯出個人資料，或對本政策提出疑問，
          請透過上述電子郵件與我們聯絡。
        </p>
      </div>
    </section>

    <!-- 02 蒐集資料 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">02</span>
        <h2 class="static-section-title">我們可能蒐集的資料</h2>
      </div>

      <div class="static-grid-2">

        <div class="glass-light static-card">
          <h3 class="static-card-title">👤 帳號與會員資料</h3>

          <ul class="static-list">
            <li>使用者名稱及顯示名稱</li>
            <li>電子郵件地址</li>
            <li>密碼的不可逆雜湊值</li>
            <li>頭像、個人簡介及偏好設定</li>
            <li>帳號建立時間及最後登入時間</li>
          </ul>
        </div>

        <div class="glass-light static-card">
          <h3 class="static-card-title">🔑 Google 登入資料</h3>

          <ul class="static-list">
            <li>Google 帳號識別碼</li>
            <li>經您授權提供的名稱、電子郵件及頭像</li>
            <li>OAuth 驗證所需的登入狀態或權杖資料</li>
          </ul>

          <p>
            本站不會取得或儲存您的 Google 帳號密碼，
            也不會在未經授權的情況下存取其他 Google 私人資料。
          </p>
        </div>

        <div class="glass-light static-card">
          <h3 class="static-card-title">📊 站內使用紀錄</h3>

          <ul class="static-list">
            <li>追番收藏與觀看狀態</li>
            <li>觀看進度及集數紀錄</li>
            <li>評分、清單及偏好設定</li>
            <li>積分、等級及登入紀錄</li>
            <li>站內功能操作紀錄</li>
          </ul>
        </div>

        <div class="glass-light static-card">
          <h3 class="static-card-title">🌐 裝置及技術資料</h3>

          <ul class="static-list">
            <li>IP 位址及概略地區</li>
            <li>瀏覽器、作業系統及裝置類型</li>
            <li>造訪時間、來源網址及瀏覽頁面</li>
            <li>Cookie、工作階段及類似技術資料</li>
            <li>錯誤紀錄、安全紀錄及伺服器日誌</li>
          </ul>
        </div>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <h3 class="static-card-title">資料提供是否為必要</h3>

        <p>
          您可以自行決定是否註冊帳號或提供選填資料。
          如您不提供建立帳號所需的必要資料，本站可能無法提供會員登入、
          追番同步、觀看進度、評分或個人化設定等功能。
        </p>
      </div>
    </section>

    <!-- 03 蒐集方式與目的 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">03</span>
        <h2 class="static-section-title">資料蒐集方式與使用目的</h2>
      </div>

      <div class="glass-light static-card">
        <p>本站可能在下列情況蒐集資料：</p>

        <ul class="static-list">
          <li>您註冊帳號、登入或修改會員資料時。</li>
          <li>您使用追番、收藏、評分或進度功能時。</li>
          <li>您透過表單或電子郵件聯絡本站時。</li>
          <li>您瀏覽本站，由伺服器、Cookie 或第三方服務自動產生時。</li>
        </ul>

        <p>本站使用資料的目的包括：</p>

        <ul class="static-list">
          <li>建立與管理會員帳號。</li>
          <li>驗證身分及維持登入狀態。</li>
          <li>儲存追番、收藏、評分及觀看進度。</li>
          <li>提供積分、等級及其他會員功能。</li>
          <li>提供、維護及改善網站功能。</li>
          <li>分析網站流量與功能使用狀況。</li>
          <li>偵測垃圾訊息、詐欺、自動化濫用及資安事件。</li>
          <li>回覆詢問、申訴、著作權通知及技術問題。</li>
          <li>顯示廣告、衡量廣告成效及維持本站營運。</li>
          <li>遵守法律義務、政府要求或司法程序。</li>
        </ul>

        <div class="static-notice">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          本站不會出售或出租您的帳號資料、追番紀錄或電子郵件地址。
        </div>
      </div>
    </section>

    <!-- 04 Google AdSense -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">04</span>
        <h2 class="static-section-title">Google AdSense 與廣告 Cookie</h2>
      </div>

      <div class="glass-light static-card">
        <h3 class="static-card-title">📢 Google 廣告服務</h3>

        <p>
          本站使用 Google AdSense 顯示廣告。
          第三方供應商（包括 Google）可能使用 Cookie、
          本機儲存空間、像素標籤、IP 位址或類似技術，
          根據使用者先前造訪本站或其他網站的紀錄放送廣告、
          控制廣告顯示頻率、偵測無效流量並衡量廣告成效。
        </p>

        <p>
          Google 及其合作夥伴可能使用廣告 Cookie，
          根據您造訪本站及其他網站的情況顯示個人化廣告。
          在未使用個人化廣告的情況下，仍可能依據目前頁面內容、
          概略位置、裝置類型或其他非個人化資訊顯示廣告。
        </p>

        <p>
          Google 廣告相關 Cookie 可能由下列網域或其他 Google
          及廣告合作夥伴網域設定：
        </p>

        <ul class="static-list">
          <li>google.com</li>
          <li>doubleclick.net</li>
          <li>googlesyndication.com</li>
          <li>googleadservices.com</li>
        </ul>

        <p>
          本站不會直接取得 Google 根據廣告 Cookie 建立的完整個人廣告檔案。
          Google 及其他廣告技術供應商將依其各自的隱私權政策處理相關資料。
        </p>

        <div class="static-notice">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          即使您停用個人化廣告，本站仍可能顯示非個人化或內容比對廣告。
        </div>
      </div>

      <div class="static-grid-2" style="margin-top:16px;">

        <div class="glass-light static-card">
          <h3 class="static-card-title">⚙️ 管理 Google 廣告設定</h3>

          <p>
            您可以前往「Google 我的廣告中心」查看或調整個人化廣告設定。
          </p>

          <a
            href="https://myadcenter.google.com/"
            target="_blank"
            rel="noopener noreferrer"
            class="static-link"
          >
            開啟 Google 我的廣告中心
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
          </a>
        </div>

        <div class="glass-light static-card">
          <h3 class="static-card-title">🚫 停用第三方個人化廣告</h3>

          <p>
            您也可以透過 AboutAds 或瀏覽器設定，
            管理部分第三方廣告供應商的個人化廣告 Cookie。
          </p>

          <a
            href="https://optout.aboutads.info/"
            target="_blank"
            rel="noopener noreferrer"
            class="static-link"
          >
            前往 AboutAds 選擇退出
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
          </a>
        </div>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <h3 class="static-card-title">廣告技術供應商</h3>

        <p>
          除 Google 外，Google 廣告系統可能使用其他經授權的廣告技術供應商，
          用於廣告投放、成效評估、防止詐欺及其他廣告相關用途。
        </p>

        <a
          href="https://support.google.com/admanager/answer/9012903"
          target="_blank"
          rel="noopener noreferrer"
          class="static-link"
        >
          查看 Google 廣告技術供應商相關資訊
          <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
        </a>
      </div>
    </section>

    <!-- 05 Cookie -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">05</span>
        <h2 class="static-section-title">Cookie 與類似技術</h2>
      </div>

      <div class="static-grid-3">

        <div class="glass-light static-card static-card--center">
          <div class="static-item-icon" aria-hidden="true">🔧</div>
          <h3 class="static-card-title">必要性 Cookie</h3>
          <p>
            用於登入驗證、資訊安全、工作階段及網站基本功能。
            停用後可能無法正常登入或使用部分功能。
          </p>
        </div>

        <div class="glass-light static-card static-card--center">
          <div class="static-item-icon" aria-hidden="true">⚙️</div>
          <h3 class="static-card-title">功能性 Cookie</h3>
          <p>
            用於記住介面、語言、顯示模式及其他偏好設定。
          </p>
        </div>

        <div class="glass-light static-card static-card--center">
          <div class="static-item-icon" aria-hidden="true">📢</div>
          <h3 class="static-card-title">廣告 Cookie</h3>
          <p>
            由 Google 或其他廣告供應商使用，
            用於廣告投放、成效衡量及防止無效流量。
          </p>
        </div>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <h3 class="static-card-title">如何管理 Cookie</h3>

        <p>
          您可以透過瀏覽器設定封鎖、限制或刪除 Cookie。
          但停用必要性 Cookie 後，會員登入、進度儲存或其他網站功能
          可能無法正常運作。
        </p>

        <p>
          若本站顯示 Cookie 或隱私權同意訊息，
          您可以透過該訊息選擇接受、拒絕或管理非必要 Cookie。
          您也可以清除瀏覽器 Cookie，使同意設定重新顯示。
        </p>

        <a
          href="https://policies.google.com/technologies/cookies?hl=zh-TW"
          target="_blank"
          rel="noopener noreferrer"
          class="static-link"
        >
          瞭解 Google 如何使用 Cookie
          <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
        </a>
      </div>
    </section>

    <!-- 06 第三方服務 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">06</span>
        <h2 class="static-section-title">第三方服務與外部資料來源</h2>
      </div>

      <p>
        本站可能使用以下第三方服務。
        當您載入相關功能、圖片、字型、登入介面或廣告時，
        第三方服務可能收到您的 IP 位址、瀏覽器資訊、
        請求時間及必要的技術資料。
      </p>

      <div class="static-grid-2">

        <?php
        $third_parties = [
          [
            'name'  => 'Google AdSense',
            'icon'  => 'fa-brands fa-google',
            'desc'  => '用於顯示廣告、衡量廣告成效及偵測無效流量。',
            'link'  => 'https://policies.google.com/privacy?hl=zh-TW',
            'label' => '查看 Google 隱私權政策',
          ],
          [
            'name'  => 'Google OAuth',
            'icon'  => 'fa-solid fa-right-to-bracket',
            'desc'  => '在您選擇使用 Google 登入時，提供帳號驗證服務。',
            'link'  => 'https://policies.google.com/privacy?hl=zh-TW',
            'label' => '查看 Google 隱私權政策',
          ],
          [
            'name'  => 'Google Fonts',
            'icon'  => 'fa-solid fa-font',
            'desc'  => '如由 Google 伺服器載入字型，Google 可能接收基本連線及裝置資料。',
            'link'  => 'https://developers.google.com/fonts/faq/privacy',
            'label' => '查看 Google Fonts 隱私說明',
          ],
          [
            'name'  => 'AniList',
            'icon'  => 'fa-solid fa-database',
            'desc'  => '提供部分動漫資料、圖片或作品識別資訊。',
            'link'  => 'https://anilist.co/terms',
            'label' => '查看 AniList 條款',
          ],
          [
            'name'  => 'MyAnimeList／Jikan',
            'icon'  => 'fa-solid fa-database',
            'desc'  => '提供部分動漫公開資料、評分或作品識別資訊。',
            'link'  => 'https://myanimelist.net/about/privacy_policy',
            'label' => '查看 MyAnimeList 隱私權政策',
          ],
          [
            'name'  => 'Bangumi',
            'icon'  => 'fa-solid fa-tv',
            'desc'  => '提供部分動漫公開資料、中文資訊或作品識別資訊。',
            'link'  => 'https://bgm.tv',
            'label' => '前往 Bangumi',
          ],
        ];

        foreach ( $third_parties as $tp ) :
        ?>
          <div class="glass-light static-card">

            <div class="static-card-head">
              <i
                class="<?php echo esc_attr( $tp['icon'] ); ?> static-card-fa"
                aria-hidden="true"
              ></i>

              <h3 class="static-card-title">
                <?php echo esc_html( $tp['name'] ); ?>
              </h3>
            </div>

            <p>
              <?php echo esc_html( $tp['desc'] ); ?>
            </p>

            <a
              href="<?php echo esc_url( $tp['link'] ); ?>"
              target="_blank"
              rel="noopener noreferrer"
              class="static-link"
            >
              <?php echo esc_html( $tp['label'] ); ?>
              <i
                class="fa-solid fa-arrow-up-right-from-square"
                aria-hidden="true"
              ></i>
            </a>

          </div>
        <?php endforeach; ?>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <p>
          第三方網站及服務擁有各自的隱私權政策與資料處理方式，
          本站無法控制其所有資料處理活動。
          建議您在使用相關服務前查閱其隱私權政策。
        </p>
      </div>
    </section>

    <!-- 07 歐洲同意 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">07</span>
        <h2 class="static-section-title">歐洲地區使用者與同意聲明</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          若您位於歐洲經濟區、英國或瑞士，
          本站會依適用規定透過 Google 認證的同意聲明管理平台（CMP）
          或其他合規機制，向您揭露廣告技術供應商及資料使用目的，
          並在需要時取得您對 Cookie、本機儲存空間及個人化廣告的同意。
        </p>

        <p>
          您可以透過同意管理介面接受、拒絕或管理相關選項。
          拒絕個人化廣告後，仍可能看到非個人化或內容比對廣告。
        </p>

        <p>
          依適用法律，您可能享有存取、更正、刪除、限制處理、
          反對處理、資料可攜及撤回同意等權利。
        </p>
      </div>
    </section>

    <!-- 08 分享與揭露 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">08</span>
        <h2 class="static-section-title">資料分享與揭露</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站不會出售或出租您的個人資料。
          但在下列必要情況下，可能向第三方提供有限資料：
        </p>

        <ul class="static-list">
          <li>
            向主機、郵件、登入驗證、網站安全、備份或技術服務供應商提供
            執行服務所需的資料。
          </li>

          <li>
            向 Google 或其他廣告技術供應商提供廣告放送、
            成效衡量及防止無效流量所需的技術資料。
          </li>

          <li>
            經您明確同意或由您主動要求時。
          </li>

          <li>
            為遵守法律、法院命令、政府機關要求或保護本站及使用者安全時。
          </li>

          <li>
            若本站發生合併、重組或營運移轉，
            資料可能在適當保護措施下移轉給承接者。
          </li>
        </ul>
      </div>
    </section>

    <!-- 09 儲存 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">09</span>
        <h2 class="static-section-title">資料保存期間</h2>
      </div>

      <div class="glass-light static-card">
        <ul class="static-list">
          <li>
            帳號與會員資料原則上保存至您刪除帳號，
            或本站終止提供相關服務為止。
          </li>

          <li>
            追番、收藏、進度、評分及會員功能資料，
            原則上隨帳號存續期間保存。
          </li>

          <li>
            安全紀錄、伺服器日誌及錯誤紀錄，
            會依資安、除錯、備份及防止濫用所需期間保存。
          </li>

          <li>
            備份資料可能在帳號刪除後短期保留，
            並依備份輪替週期覆寫或刪除。
          </li>

          <li>
            如法律、爭議處理、詐欺防制或司法程序要求，
            本站可能在必要範圍內延長保存期間。
          </li>
        </ul>
      </div>
    </section>

    <!-- 10 安全 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">10</span>
        <h2 class="static-section-title">資料安全</h2>
      </div>

      <div class="glass-light static-card">
        <ul class="static-list">
          <li>使用 HTTPS 加密傳輸資料。</li>
          <li>會員密碼以不可逆雜湊方式儲存。</li>
          <li>限制管理權限並採取合理的存取控制措施。</li>
          <li>透過備份、更新與安全監控降低資料遺失或遭濫用風險。</li>
          <li>發現資安事件時，將依事件性質採取合理的處理及通知措施。</li>
        </ul>

        <p>
          然而，任何網路傳輸或電子儲存方式都無法保證百分之百安全。
          請您使用安全且不重複的密碼，並妥善保管登入資訊。
        </p>
      </div>
    </section>

    <!-- 11 跨境 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">11</span>
        <h2 class="static-section-title">跨境資料傳輸</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站服務面向台灣、香港、澳門及其他地區使用者。
          由於本站可能使用 Google、AniList、MyAnimeList、
          Bangumi、主機服務商及其他國際服務，
          部分資料可能傳輸至您所在國家或地區以外的伺服器處理。
        </p>

        <p>
          不同地區的資料保護法律可能有所差異。
          本站會在合理可行的範圍內選用具有適當資料保護措施的服務供應商，
          並限制所傳輸的資料範圍。
        </p>
      </div>
    </section>

    <!-- 12 權利 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">12</span>
        <h2 class="static-section-title">您的資料權利</h2>
      </div>

      <div class="static-grid-2">

        <?php
        $rights = [
          [
            'icon'  => '👁️',
            'title' => '查詢與閱覽',
            'desc'  => '您可以查詢本站持有的個人資料及帳號相關紀錄。',
          ],
          [
            'icon'  => '✏️',
            'title' => '補充與更正',
            'desc'  => '您可以修改或要求更正不完整、不正確的個人資料。',
          ],
          [
            'icon'  => '📦',
            'title' => '資料匯出',
            'desc'  => '您可以聯絡本站，申請提供可合理匯出的帳號資料。',
          ],
          [
            'icon'  => '🗑️',
            'title' => '停止使用與刪除',
            'desc'  => '您可以申請停止使用或刪除帳號及相關個人資料。',
          ],
          [
            'icon'  => '↩️',
            'title' => '撤回同意',
            'desc'  => '如資料處理以您的同意為基礎，您可以撤回該項同意。',
          ],
          [
            'icon'  => '🚫',
            'title' => '反對或限制處理',
            'desc'  => '在適用法律允許的範圍內，您可以要求限制或反對特定資料處理。',
          ],
        ];

        foreach ( $rights as $right ) :
        ?>
          <div class="glass-light static-item-card">

            <span class="static-item-icon" aria-hidden="true">
              <?php echo esc_html( $right['icon'] ); ?>
            </span>

            <div>
              <strong>
                <?php echo esc_html( $right['title'] ); ?>
              </strong>

              <p>
                <?php echo esc_html( $right['desc'] ); ?>
              </p>
            </div>

          </div>
        <?php endforeach; ?>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <p>
          您可以先透過會員中心管理帳號資料。
          若相關功能尚未提供，請寄信至
          <a
            href="mailto:weixiaoacg.com@gmail.com"
            class="static-link"
          >
            weixiaoacg.com@gmail.com
          </a>
          提出申請。
        </p>

        <p>
          為防止他人冒用您的身分，本站可能要求您提供必要的驗證資訊。
          如法律要求保存特定資料，或該資料涉及安全、詐欺防制及法律爭議，
          本站可能無法立即刪除全部資料，但會限制其使用範圍。
        </p>
      </div>
    </section>

    <!-- 13 未成年人 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">13</span>
        <h2 class="static-section-title">未成年人隱私</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站不以蒐集兒童個人資料為主要目的。
          未成年人使用本站前，應由父母、法定代理人或監護人
          依所在地法律提供必要的同意與指導。
        </p>

        <p>
          若您是父母、法定代理人或監護人，
          並認為未成年人在未獲適當同意的情況下向本站提供個人資料，
          請聯絡我們。經確認後，本站將採取合理措施刪除或限制相關資料。
        </p>
      </div>
    </section>

    <!-- 14 外部連結 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">14</span>
        <h2 class="static-section-title">外部網站與嵌入內容</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站可能包含外部網站連結、影片、圖片、社群內容或其他嵌入資源。
          當您瀏覽或點擊這些內容時，外部服務可能依其政策蒐集資料或使用 Cookie。
        </p>

        <p>
          本站不負責外部網站的隱私權措施，
          建議您在提供個人資料前查閱該網站的隱私權政策。
        </p>
      </div>
    </section>

    <!-- 15 政策變更 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">15</span>
        <h2 class="static-section-title">隱私權政策變更</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站可能因功能、服務、第三方供應商或法律要求變更而修改本政策。
          更新後會調整頁面頂部的「最後更新日期」。
        </p>

        <p>
          如變更可能重大影響您的權利，
          本站會視情況透過網站公告、會員訊息或其他合理方式通知。
          建議您定期查看本頁，瞭解最新內容。
        </p>
      </div>
    </section>

    <!-- 聯絡 -->
    <section class="static-cta glass-mid">
      <div class="static-cta-icon" aria-hidden="true">✉️</div>

      <h2 class="static-cta-title">隱私權相關疑問？</h2>

      <p class="static-cta-desc">
        如需查詢、更正、刪除、匯出個人資料，
        或對 Cookie、Google 廣告及本政策有任何疑問，歡迎與我們聯絡。
      </p>

      <a
        href="mailto:weixiaoacg.com@gmail.com"
        class="btn btn-primary"
      >
        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
        weixiaoacg.com@gmail.com
      </a>
    </section>

  </div>
</main>

<?php
get_footer();
