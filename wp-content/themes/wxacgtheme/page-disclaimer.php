<?php
/**
 * Template Name: 免責聲明
 * Template Post Type: page
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main class="static-page">

  <!-- Hero -->
  <section class="static-hero">
    <div class="static-hero-inner">
      <div class="static-hero-icon" aria-hidden="true">⚠️</div>

      <h1 class="static-hero-title">免責聲明</h1>

      <p class="static-hero-sub">
        最後更新日期：2026 年 7 月 22 日
      </p>
    </div>
  </section>

  <div class="static-content container">

    <!-- 前言 -->
    <section class="static-section glass-light">
      <p class="static-lead">
        本免責聲明說明微笑動漫 WeixiaoACG（以下稱「本站」）
        對網站內容、第三方資料、外部連結、廣告及相關服務的責任範圍。
        使用本站即表示您已閱讀並理解本聲明。
      </p>

      <p>
        本站以動漫資訊整理、新聞、作品介紹、評論、資料查詢、
        會員追番及相關交流功能為主要服務內容，不提供非法動畫串流、
        盜版影片或未經授權的作品下載服務。
      </p>
    </section>

    <!-- 01 網站性質 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">01</span>
        <h2 class="static-section-title">網站性質與營運方式</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站為動漫資訊與評論網站，提供作品基本資料、播出資訊、
          角色與製作人員資料、評分參考、相關作品關聯、新聞、
          專欄及會員追番功能。
        </p>

        <p>
          為支付網域、伺服器、網站維護、內容製作及其他營運成本，
          本站可能透過 Google AdSense 或其他廣告服務顯示廣告，
          並可能接受使用者自願贊助。
        </p>

        <div class="static-notice">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>

          廣告或贊助收入不代表本站擁有第三方動漫作品、
          圖片、影片、音樂、角色或其他版權內容的著作權。
        </div>
      </div>
    </section>

    <!-- 02 著作權 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">02</span>
        <h2 class="static-section-title">著作權與商標聲明</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站所介紹之動畫、漫畫、小說、遊戲及相關作品名稱、
          商標、角色名稱、官方圖片、海報、封面、劇照、影片、
          音樂及其他素材，其著作權或商標權原則上屬於原作者、
          出版社、動畫製作公司、發行商、代理商及其他合法權利人。
        </p>

        <p>
          本站不因整理、顯示或介紹相關資訊而主張擁有第三方作品的
          著作權、商標權或其他智慧財產權。
        </p>

        <p>
          「微笑動漫」、「WeixiaoACG」網站自行製作的版面設計、
          原創文章、評論、整理文字、圖表及程式功能，
          除另有標示外，其相關權利由本站或個別內容創作者所有。
        </p>
      </div>
    </section>

    <!-- 03 資訊引用 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">03</span>
        <h2 class="static-section-title">資訊引用與內容使用</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站引用作品名稱、封面、海報、劇照、預告片縮圖或其他相關內容時，
          主要用於作品辨識、資訊介紹、新聞報導、資料整理、
          評論及協助使用者查找官方資訊。
        </p>

        <ul class="static-list">
          <li>
            本站原則上僅在作品辨識及資訊說明所需的範圍內使用相關素材。
          </li>

          <li>
            本站不以相關素材取代原作，也不提供完整動畫、
            漫畫、小說、音樂或其他受保護作品。
          </li>

          <li>
            本站會盡可能標示作品名稱、來源平台或相關官方資訊。
          </li>

          <li>
            部分資料及圖片網址可能透過第三方公開 API 取得；
            API 可供存取不代表相關內容的著作權發生移轉。
          </li>

          <li>
            個別內容是否符合合理使用或其他法定利用情形，
            仍應依使用目的、素材性質、使用比例、
            對市場的影響及適用法律個別判斷。
          </li>
        </ul>

        <div class="static-notice">
          <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>

          如您是權利人並認為本站使用方式不適當，
          請透過本頁下方的著作權申訴方式聯絡我們。
        </div>
      </div>
    </section>

    <!-- 04 第三方資料來源 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">04</span>
        <h2 class="static-section-title">第三方資料來源</h2>
      </div>

      <div class="static-grid-2">

        <?php
        $sources = [
          [
            'icon' => 'fa-solid fa-database',
            'name' => 'AniList',
            'url'  => 'https://anilist.co/',
            'desc' => '提供部分作品識別資料、英文或羅馬字名稱、封面網址、製作資訊及評分參考。',
          ],
          [
            'icon' => 'fa-solid fa-tv',
            'name' => 'Bangumi',
            'url'  => 'https://bgm.tv/',
            'desc' => '提供部分中文作品資料、人物資料、製作資訊、關聯作品及評分參考。',
          ],
          [
            'icon' => 'fa-solid fa-list',
            'name' => 'MyAnimeList／Jikan',
            'url'  => 'https://jikan.moe/',
            'desc' => '提供部分 MyAnimeList 公開資料、作品識別資訊、角色聲優及評分參考。',
          ],
          [
            'icon' => 'fa-solid fa-music',
            'name' => 'AnimeThemes.moe',
            'url'  => 'https://animethemes.moe/',
            'desc' => '提供部分動畫主題曲、片頭曲、片尾曲及相關公開資料。',
          ],
        ];

        foreach ( $sources as $source ) :
        ?>
          <div class="glass-light static-card">

            <div class="static-card-head">
              <i
                class="<?php echo esc_attr( $source['icon'] ); ?> static-card-fa"
                aria-hidden="true"
              ></i>

              <h3 class="static-card-title">
                <?php echo esc_html( $source['name'] ); ?>
              </h3>
            </div>

            <p>
              <?php echo esc_html( $source['desc'] ); ?>
            </p>

            <a
              href="<?php echo esc_url( $source['url'] ); ?>"
              target="_blank"
              rel="noopener noreferrer"
              class="static-link"
            >
              前往相關網站
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
          上述平台為獨立的第三方服務，本站與其之間不必然存在
          代理、合作、授權或背書關係，除非另有明確標示。
        </p>

        <p>
          第三方 API、網站及資料庫可供公開存取，
          不代表其中所有作品資料、圖片或其他內容均可不受限制地使用。
          相關內容的權利仍屬原始權利人或資料提供者所有。
        </p>
      </div>
    </section>

    <!-- 05 資料準確性 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">05</span>
        <h2 class="static-section-title">資料準確性與即時性</h2>
      </div>

      <div class="static-grid-2">

        <?php
        $accuracy_items = [
          [
            'icon'  => '📡',
            'title' => 'API 同步延遲',
            'desc'  => '第三方資料可能定期同步，與原始平台之間可能存在更新時間差。',
          ],
          [
            'icon'  => '🌐',
            'title' => '中文翻譯與轉換',
            'desc'  => '部分內容可能經人工翻譯、自動翻譯或繁簡轉換，可能存在名稱及用詞差異。',
          ],
          [
            'icon'  => '⭐',
            'title' => '評分資料',
            'desc'  => 'AniList、MyAnimeList、Bangumi 等評分來自不同平台與使用者群體，僅供參考。',
          ],
          [
            'icon'  => '📅',
            'title' => '播出日期',
            'desc'  => '播出日期、時間、集數及平台可能因製作方、電視台或串流平台調整而變更。',
          ],
          [
            'icon'  => '🎬',
            'title' => '作品資訊',
            'desc'  => '作品名稱、工作人員、聲優、集數及關聯作品可能因來源不同而存在差異。',
          ],
          [
            'icon'  => '🔗',
            'title' => '播放平台資訊',
            'desc'  => '串流平台授權可能因地區及時間而變更，實際 availability 以平台公告為準。',
          ],
        ];

        foreach ( $accuracy_items as $item ) :
        ?>
          <div class="glass-light static-item-card">

            <span class="static-item-icon" aria-hidden="true">
              <?php echo esc_html( $item['icon'] ); ?>
            </span>

            <div>
              <strong>
                <?php echo esc_html( $item['title'] ); ?>
              </strong>

              <p>
                <?php echo esc_html( $item['desc'] ); ?>
              </p>
            </div>

          </div>
        <?php endforeach; ?>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <p>
          本站會盡合理努力維護資料品質，但不保證所有內容在任何時間均為
          完全正確、完整、即時或適合特定用途。
          重要資訊請以作品官方網站、版權方、代理商、
          電視台或合法串流平台的最新公告為準。
        </p>
      </div>
    </section>

    <!-- 06 不提供非法服務 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">06</span>
        <h2 class="static-section-title">不提供非法服務</h2>
      </div>

      <div class="static-grid-3">

        <?php
        $service_items = [
          [
            'icon'  => '🚫',
            'title' => '不提供非法串流',
            'desc'  => '本站不自行上傳、儲存或播放未經授權的完整動畫、電影及其他版權影片。',
          ],
          [
            'icon'  => '⬇️',
            'title' => '不提供盜版下載',
            'desc'  => '本站不提供未經授權的動畫、漫畫、小說、音樂或其他版權內容下載。',
          ],
          [
            'icon'  => '✅',
            'title' => '合法平台導流',
            'desc'  => '如本站提供觀看平台資訊，原則上用於協助使用者前往官方或合法授權平台。',
          ],
        ];

        foreach ( $service_items as $item ) :
        ?>
          <div class="glass-light static-card static-card--center">

            <div class="static-item-icon" aria-hidden="true">
              <?php echo esc_html( $item['icon'] ); ?>
            </div>

            <h3 class="static-card-title">
              <?php echo esc_html( $item['title'] ); ?>
            </h3>

            <p>
              <?php echo esc_html( $item['desc'] ); ?>
            </p>

          </div>
        <?php endforeach; ?>

      </div>

      <div class="glass-light static-card" style="margin-top:16px;">
        <p>
          如第三方使用者在留言、討論區或其他互動功能張貼疑似非法連結，
          不代表本站認可或授權該內容。本站在收到通知或發現違規後，
          得依使用條款移除內容或限制相關帳號。
        </p>
      </div>
    </section>

    <!-- 07 廣告 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">07</span>
        <h2 class="static-section-title">廣告、贊助與商業揭露</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站可能顯示由 Google AdSense 或其他第三方廣告服務提供的廣告。
          廣告內容通常由第三方廣告系統自動投放，
          不一定代表本站推薦、保證或認同廣告中的產品、服務及主張。
        </p>

        <p>
          使用者與廣告主、商品提供者或第三方服務商之間的交易、
          付款、退款、產品品質、資料處理及其他爭議，
          應由使用者與該第三方依其條款處理。
        </p>

        <p>
          若文章、連結或內容屬於贊助、廣告合作或其他商業合作，
          本站會在合理可行的範圍內標示「廣告」、「贊助」、
          「合作」或其他具有相同意義的揭露文字。
        </p>

        <div class="static-notice">
          <i class="fa-solid fa-cookie-bite" aria-hidden="true"></i>

          廣告供應商可能使用 Cookie 或類似技術。
          詳細資料請參閱本站的
          <a
            href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"
            class="static-link"
            style="margin:0;display:inline;"
          >
            隱私權政策
          </a>。
        </div>
      </div>
    </section>

    <!-- 08 第三方連結 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">08</span>
        <h2 class="static-section-title">第三方連結與嵌入內容</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站可能包含指向動畫官方網站、出版社、製作公司、
          AniList、Bangumi、MyAnimeList、YouTube、
          合法串流平台及其他第三方網站的連結。
        </p>

        <p>
          第三方網站的內容、可用性、安全性、價格、服務條款、
          隱私權政策及資料處理方式均由該第三方負責。
          外部連結不代表本站對該網站作出保證或背書。
        </p>

        <p>
          使用者前往第三方網站前，應自行確認網址、
          服務條款、付款資訊、授權地區及隱私權政策。
        </p>
      </div>
    </section>

    <!-- 09 使用者內容 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">09</span>
        <h2 class="static-section-title">使用者產生內容</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          使用者透過評論、討論區、個人資料、清單或其他互動功能
          發布的內容，原則上由發布者自行負責。
        </p>

        <p>
          使用者不得發布侵害著作權、隱私權、商標權或其他合法權益的內容，
          也不得發布惡意程式、詐騙訊息、非法下載、
          非法串流或其他違法內容。
        </p>

        <p>
          本站得依使用條款、社群規範、法律要求或安全需要，
          移除內容、限制功能、停權帳號或保留必要紀錄。
        </p>

        <a
          href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"
          class="static-link"
        >
          查看使用條款
          <i
            class="fa-solid fa-arrow-up-right-from-square"
            aria-hidden="true"
          ></i>
        </a>
      </div>
    </section>

    <!-- 10 責任限制 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">10</span>
        <h2 class="static-section-title">責任限制</h2>
      </div>

      <div class="glass-light static-card">
        <ul class="static-list">
          <li>
            本站會盡合理努力維護服務，但不保證網站永遠不會中斷、
            發生錯誤、延遲或安全事件。
          </li>

          <li>
            本站不保證第三方 API、外部網站、圖片、
            影片或嵌入內容持續可用。
          </li>

          <li>
            因作品資料更新延遲、第三方平台調整、
            網路中斷或不可控制因素造成的資訊差異，
            本站將在合理範圍內處理，但不保證即時修正。
          </li>

          <li>
            使用者依本站資訊作出的購買、訂閱、觀看或其他決定，
            應自行評估並承擔相應風險。
          </li>

          <li>
            在適用法律允許的範圍內，
            本站對間接、附帶、特殊或衍生性損害不承擔責任。
          </li>
        </ul>

        <p>
          本聲明不排除或限制依適用法律不得排除或限制的責任。
        </p>
      </div>
    </section>

    <!-- 11 著作權申訴 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">11</span>
        <h2 class="static-section-title">著作權與內容申訴</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          如您認為本站內容侵害您的著作權、商標權、隱私權或其他合法權益，
          請寄送申訴通知並盡可能提供以下資料：
        </p>

        <ul class="static-list">
          <li>權利人或合法代理人的姓名及聯絡方式。</li>
          <li>所主張權利之作品、商標或相關內容說明。</li>
          <li>本站疑似侵權頁面的完整網址。</li>
          <li>希望本站移除、更正或限制顯示的具體內容。</li>
          <li>足以證明權利歸屬或代理權限的合理資料。</li>
          <li>申訴內容真實且為善意提出的聲明。</li>
        </ul>

        <p>
          本站收到完整通知後，將進行必要審查，
          並原則上於 72 小時內提供初步回覆或採取適當處理措施。
          實際完成時間可能視案件複雜度及資料完整性而異。
        </p>

        <div class="static-notice">
          <i class="fa-solid fa-envelope" aria-hidden="true"></i>

          著作權與內容申訴信箱：
          <a
            href="mailto:weixiaoacg.com@gmail.com"
            class="static-link"
            style="margin:0;display:inline;"
          >
            weixiaoacg.com@gmail.com
          </a>
        </div>
      </div>
    </section>

    <!-- 12 聲明修改 -->
    <section class="static-section">
      <div class="static-section-header">
        <span class="static-section-num">12</span>
        <h2 class="static-section-title">聲明修改</h2>
      </div>

      <div class="glass-light static-card">
        <p>
          本站可能因服務內容、第三方資料來源、廣告服務或法律要求變更，
          修改本免責聲明。
        </p>

        <p>
          更新後會調整頁面頂部的「最後更新日期」。
          建議您定期查看本頁，以瞭解最新內容。
        </p>
      </div>
    </section>

    <!-- CTA -->
    <section class="static-cta glass-mid">
      <div class="static-cta-icon" aria-hidden="true">📩</div>

      <h2 class="static-cta-title">發現版權或內容問題？</h2>

      <p class="static-cta-desc">
        如果您是權利人，或發現本站內容可能有錯誤、失效或侵權情形，
        請提供相關頁面網址及說明，我們將儘速確認處理。
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
