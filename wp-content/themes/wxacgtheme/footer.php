<?php
/**
 * 微笑動漫 Child Theme — footer.php
 *
 * v1.8.0 (2026-08-12)
 *  - 新增頁尾連結安全網：若連結指向的「靜態頁面」被刪除／改為草稿／移到回收桶，
 *    自動不輸出該連結，避免全站每一頁都連到 404。
 *    （僅對可用 url_to_postid() 解析出來的一般頁面／文章有效；
 *      分類、頻道、自訂文章類型封存頁等無法可靠判斷是否為空，不在此檢查範圍內，
 *      這類「還沒做完/暫時是空的」欄目，請直接從下方陣列註解掉或移除，不要等內容做完再放回來——
 *      在那之前放著，等於每一頁都在邀請 Google 去逛你還沒做完的地方。）
 *
 * v1.7.0 (2026-07-19)
 *  - 隨機動漫骰子浮動按鈕改版：紅色漸層、外圈圓弧「抽動漫」旋轉字、進頁即顯示（免捲動）
 *  - 手機版隱藏浮動骰子（底部導覽列已有抽動漫入口）
 *
 * v1.6.0 (2026-07-18)
 *  - 新增「隨機動漫」骰子浮動按鈕，固定顯示於回到頂端按鈕正上方
 *    點擊後透過 WP REST API 隨機抓取一篇 anime 文章並跳轉
 *
 * v1.5.0 (2026-06-17)
 *  - 移除頁尾自輸出的 Organization JSON-LD（改由 Rank Math 於 <head> 統一輸出，
 *    避免全站每頁出現兩份 Organization、logo 網域不一致）
 *  - 社群連結改由 Rank Math → Titles & Meta → Social 設定輸出 sameAs
 *  - brand logo 改為正式網域（weixiaoacg.com）
 *
 * v1.4.0 (2026-05-27)
 *  - 移除底部輔助列（Sitemap / 最後更新 / 回到頂端文字連結）
 *  - 移除 Discord CTA 按鈕
 *  - 社群圖示大擴充：新增 Reddit / LinkedIn / Threads / Linkgoods / RSS
 *  - 啟用原 placeholder：X / YouTube / Instagram / FB 個人
 *  - 更新 Plurk 帳號 → SMAACG
 *  - 移除未使用的 last_update 變數與 JS
 *
 * v1.3.0 (2026-05-27) 結構重整 / 5 欄 / Schema 強化 / Cookie 動畫
 * v1.2.0 (2026-05-17) Footer brand logo 改為 PNG
 * v1.1.0 (2026-05-15) 新增等級指南連結
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Logo URL：與 header 共用同一份，見 functions.php 的 wxacg_brand_logo_url() ──
 * 原本這裡寫死 2026/06/112.png，而 header 早已換成 wxacglogo-300x288.webp，
 * 兩處長得不一樣（註解卻寫著「與 header.php 一致」）。改為共用來源。
 */
$brand_logo_url = function_exists( 'wxacg_brand_logo_url' )
	? wxacg_brand_logo_url()
	: 'https://weixiaoacg.com/wp-content/uploads/2026/08/wxacglogo-300x288.webp';

/* ── 品牌名稱與描述 ── */
$site_name = get_bloginfo( 'name' ) ?: '微笑動漫';
$site_desc = get_bloginfo( 'description' )
    ?: __( '高質感 ACG 情報中心，動漫・音樂・COSPLAY・百科，讓你不錯過任何精彩。', 'blocksy-child' );

/* ── 頁尾連結安全網 ──────────────────────────────────────────
 * 只對 url_to_postid() 能解析出實際 post/page 的連結做檢查
 * （一般靜態頁面、文章都適用）。
 *
 * 分類 / 頻道 / 自訂文章類型封存頁（如 /news/、/anime/）通常會
 * 回傳 0，這裡不主動判斷「有沒有內容」，因為那需要真正下 query
 * 才能確定，而且封存頁本身不會因為被刪除而變成無效連結
 * （分類本身還在，只是文章數可能是 0）——那類「稀薄封存頁」的
 * noindex 處理，已經在 category.php / tag.php 裡做掉了。
 * 這裡只負責擋掉「頁面本身被刪除/下架」這種情況。
 */
function wxacg_footer_link_is_valid( $url ) {
    static $cache = [];
    if ( isset( $cache[ $url ] ) ) {
        return $cache[ $url ];
    }

    $post_id = url_to_postid( $url );

    if ( $post_id > 0 ) {
        $result = ( 'publish' === get_post_status( $post_id ) );
    } else {
        // 無法解析成實際 post/page（多半是分類、CPT 封存頁等），不主動判斷，視為有效。
        $result = true;
    }

    $cache[ $url ] = $result;
    return $result;
}

/* ── 社群連結（可由 filter 擴充；僅供頁尾圖示顯示用）──
 *   注意：sameAs 結構化資料已改由 Rank Math 統一輸出，
 *   此陣列只負責頁尾的視覺社群圖示，不再產生 JSON-LD。
 */
$social = apply_filters( 'smacg_footer_social', [
    'discord'        => [ 'https://discord.com/invite/yw73RBZgss',                                                                         'fa-brands fa-discord',   'Discord'      ],
    'facebook_page'  => [ 'https://www.facebook.com/smaacg?locale=zh_TW',                                                              'fa-brands fa-facebook',  'FB 粉專'       ],
    'facebook_group' => [ 'https://www.facebook.com/groups/148714851855091/',                                                               'fa-brands fa-users',     'FB 社團'       ],
    'x_twitter'      => [ 'https://x.com/weixiaoacg',                                                                                       'fa-brands fa-x-twitter', 'X (Twitter)'   ],
    'instagram'      => [ 'https://www.instagram.com/weixiaoacg/',                                                                          'fa-brands fa-instagram', 'Instagram'     ],
    'youtube'        => [ 'https://www.youtube.com/@%E5%BE%AE%E7%AC%91%E5%8B%95%E6%BC%AB/live',                                              'fa-brands fa-youtube',   'YouTube'       ],
    'threads'        => [ 'https://www.threads.com/@weixiaoacg?xmt=AQG0s3gdLtPBmlFd4pzKCJj26pVioVDxOt11GEthx1CF8xI',                          'fa-brands fa-threads',   'Threads'       ],
    'line'           => [ 'https://line.me/ti/g2/E5swfdtaIoC4UWHLpm4u-bUr_FTjVATPwOxX9A?utm_source=invitation&utm_medium=link_copy&utm_campaign=default', 'fa-brands fa-line', 'LINE 社團' ],
    'plurk'          => [ 'https://www.plurk.com/SMAACG',                                                                                   'fa-solid fa-p',          'Plurk'         ],
    'vocus'          => [ 'https://vocus.cc/user/69c92cdcfd89780001abdd8e',                                                                 'fa-solid fa-pen-nib',    'Vocus'         ],
    'reddit'         => [ 'https://www.reddit.com/user/Icy-Description-2073/',                                                              'fa-brands fa-reddit',    'Reddit'        ],
    'linkedin'       => [ 'https://www.linkedin.com/in/%E5%8B%95%E6%BC%AB-%E5%BE%AE%E7%AC%91-88ab4140a/',                                    'fa-brands fa-linkedin',  'LinkedIn'      ],
    'linkgoods'      => [ 'https://linkgoods.com/weixiaoacg',                                                                               'fa-solid fa-bag-shopping','Linkgoods'    ],
    'tiktok'         => [ '',                                                                                                               'fa-brands fa-tiktok',    '抖音'           ],
    'rss'            => [ get_bloginfo( 'rss2_url' ),                                                                                       'fa-solid fa-rss',        'RSS 訂閱'      ],
] );

/* ── Footer 欄目（可由 filter 擴充）──
 *
 * ★ 如果某個項目目前頁面還是空的／施工中，請直接把該行註解掉或刪除，
 *   等內容做好了再放回來 —— 不要放著等做完，這種連結會出現在全站每一頁。
 */
/*
 * 分欄原則（2026-08-22 重新分組）
 * ------------------------------------------------------------------
 * 原本 6 / 4 / 2 / 7，「互動」只有兩項、下方空一大片，「關於」卻長到
 * 七項，右半邊明顯不平衡；分類本身也有些放錯位置（「會員排行榜」
 * 「等級指南」是社群功能卻放在站內欄目、「專欄」是內容卻放在資料庫、
 * 「站務中心」是站務卻放在站內欄目）。
 *
 * 改依「使用者想做什麼」分組，並把三個法務連結移到最下方的版權列
 * ——那是法務連結的慣例位置，也讓「關於」從 7 項降到 5 項。
 * 結果為 4 / 3 / 4 / 5，高度接近。
 */
$footer_columns = apply_filters( 'smacg_footer_columns', [
    'site' => [
        'title' => __( '瀏覽', 'blocksy-child' ),
        'links' => [
            __( '首頁',       'blocksy-child' ) => home_url( '/' ),
            __( '最新新聞',   'blocksy-child' ) => home_url( '/news/' ),
            __( '專欄',       'blocksy-child' ) => home_url( '/columns/' ),
            __( '排行榜',     'blocksy-child' ) => home_url( '/ranking/' ),
        ],
    ],
    'database' => [
        'title' => __( '資料庫', 'blocksy-child' ),
        'links' => [
            __( '動畫百科',   'blocksy-child' ) => home_url( '/anime/' ),
            __( '新番導覽',   'blocksy-child' ) => home_url( '/bangumi/' ),
            __( '音樂庫',     'blocksy-child' ) => home_url( '/music/' ),
            // __( 'COSPLAY',    'blocksy-child' ) => home_url( '/cosplay/' ),    // 施工中暫時隱藏（AdSense 審核避坑）
            // __( '聖地巡禮',   'blocksy-child' ) => home_url( '/pilgrimage/' ), // 施工中暫時隱藏（AdSense 審核避坑）
        ],
    ],
    'interact' => [
        'title' => __( '社群', 'blocksy-child' ),
        'links' => [
            __( '討論區',     'blocksy-child' ) => home_url( '/forum/' ),
            __( '會員中心',   'blocksy-child' ) => home_url( '/member/' ),
            __( '會員排行榜', 'blocksy-child' ) => home_url( '/ranking-users/' ),
            __( '等級指南',   'blocksy-child' ) => home_url( '/level-guide/' ),
            // __( '季番投票',   'blocksy-child' ) => home_url( '/vote/' ),       // 施工中暫時隱藏（AdSense 審核避坑）
            // __( '投稿須知',   'blocksy-child' ) => home_url( '/submit/' ),     // 施工中暫時隱藏（AdSense 審核避坑）
            // __( 'AI 工具',    'blocksy-child' ) => home_url( '/ai-tools/' ),   // 施工中暫時隱藏（AdSense 審核避坑）
        ],
    ],
    'about' => [
        'title' => __( '關於', 'blocksy-child' ),
        'links' => [
            __( '關於微笑動漫', 'blocksy-child' ) => home_url( '/about/' ),
            __( '加入我們',     'blocksy-child' ) => home_url( '/join/' ),
            __( '贊助我們',     'blocksy-child' ) => home_url( '/sponsor/' ),
            __( '聯絡／合作',   'blocksy-child' ) => home_url( '/contact/' ),
            __( '站務中心',     'blocksy-child' ) => home_url( '/announcement/' ),
        ],
    ],
] );

/* ── 法務連結：放在最下方版權列，不佔欄目版位 ── */
$footer_legal = apply_filters( 'smacg_footer_legal', [
    __( '服務條款',   'blocksy-child' ) => home_url( '/terms/' ),
    __( '隱私政策',   'blocksy-child' ) => home_url( '/privacy-policy/' ),
    __( '免責聲明',   'blocksy-child' ) => home_url( '/disclaimer/' ),
] );

/* ── 資料來源（可由 filter 擴充）── */
$data_sources = apply_filters( 'smacg_footer_data_sources', [
    'Bangumi'     => 'https://bgm.tv',
    'AniList'     => 'https://anilist.co',
    'MyAnimeList' => 'https://myanimelist.net',
    'Jikan'       => 'https://jikan.moe',
    'Wikipedia'   => 'https://www.wikipedia.org',
    'AnimeThemes' => 'https://animethemes.moe',
] );
?>

<footer class="site-footer glass-mid" role="contentinfo">
  <div class="container">
    <div class="footer-top">

      <!-- ── Brand ── -->
      <div class="footer-brand">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"
           class="site-logo footer-logo"
           aria-label="<?php echo esc_attr( sprintf( __( '%s 首頁', 'blocksy-child' ), $site_name ) ); ?>">
          <span class="logo-icon-box" aria-hidden="true">
            <img src="<?php echo esc_url( $brand_logo_url ); ?>"
                 alt="<?php echo esc_attr( $site_name ); ?>"
                 class="footer-logo-img"
                 loading="lazy"
                 decoding="async" />
          </span>
          <span class="logo-text">
            <?php echo esc_html( $site_name ); ?>
          </span>
        </a>

        <p class="footer-tagline"><?php echo esc_html( $site_desc ); ?></p>

        <!-- 社群圖示 -->
        <nav class="social-links" aria-label="<?php echo esc_attr__( '社群媒體連結', 'blocksy-child' ); ?>">
          <?php foreach ( $social as $key => [ $url, $icon, $label ] ) : ?>
            <?php if ( $url !== '' ) : ?>
              <a href="<?php echo esc_url( $url ); ?>"
                 class="footer-social-btn social-<?php echo esc_attr( $key ); ?>"
                 aria-label="<?php echo esc_attr( $site_name . ' ' . $label ); ?>"
                 title="<?php echo esc_attr( $site_name . ' ' . $label ); ?>"
                 target="_blank" rel="noopener noreferrer">
                <i class="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
              </a>
            <?php else : ?>
              <span class="footer-social-btn footer-social-placeholder"
                    title="<?php echo esc_attr( sprintf( __( '%s 即將上線', 'blocksy-child' ), $label ) ); ?>"
                    role="button" aria-label="<?php echo esc_attr( sprintf( __( '%s（即將上線）', 'blocksy-child' ), $label ) ); ?>">
                <i class="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
              </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </div>

      <!-- ── 動態欄目 ── -->
      <?php foreach ( $footer_columns as $col_key => $col ) :
        // 過濾掉指向已刪除/未發佈頁面的連結
        $valid_links = array_filter(
            $col['links'],
            static function ( $url ) { return wxacg_footer_link_is_valid( $url ); }
        );
        if ( empty( $valid_links ) ) continue;
      ?>
        <nav class="footer-nav-group" aria-label="<?php echo esc_attr( $col['title'] ); ?>">
          <h5><?php echo esc_html( $col['title'] ); ?></h5>
          <?php foreach ( $valid_links as $label => $url ) : ?>
            <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endforeach; ?>

    </div><!-- .footer-top -->

    <div class="footer-divider" role="separator"></div>

    <!-- ── Footer Bottom：版權 + 資料來源 ── -->
    <div class="footer-bottom">
      <p class="footer-copy">
        © <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?> WeixiaoACG．
        <?php echo esc_html__( 'All rights reserved．', 'blocksy-child' ); ?>
        <?php echo esc_html__( '資料來源包含', 'blocksy-child' ); ?>
        <?php
        $ds_html = [];
        foreach ( $data_sources as $name => $url ) {
            $ds_html[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url( $url ),
                esc_html( $name )
            );
        }
        echo implode( '、', $ds_html ); // phpcs:ignore WordPress.Security.EscapeOutput
        ?>。
      </p>

      <?php /* 法務連結 + 桌面版切換（統一在同一列） */ ?>
      <?php if ( ! empty( $footer_legal ) ) : ?>
        <nav class="footer-legal" aria-label="<?php echo esc_attr__( '法務資訊', 'blocksy-child' ); ?>">
          <?php
          $legal_html = [];
          foreach ( $footer_legal as $label => $url ) {
              if ( ! wxacg_footer_link_is_valid( $url ) ) {
                  continue;
              }
              $legal_html[] = sprintf(
                  '<a href="%s">%s</a>',
                  esc_url( $url ),
                  esc_html( $label )
              );
          }
          echo implode( '<span class="footer-legal-sep" aria-hidden="true">·</span>', $legal_html ); // phpcs:ignore WordPress.Security.EscapeOutput
          ?>
          <span class="footer-legal-sep" aria-hidden="true">·</span>
          <button type="button" id="wxacg-view-mode-toggle" class="footer-legal-desktop-btn" aria-label="<?php echo esc_attr__( '切換至桌面版檢視', 'blocksy-child' ); ?>" title="<?php echo esc_attr__( '切換至桌面版', 'blocksy-child' ); ?>">
            <i class="fa-solid fa-desktop" aria-hidden="true"></i>
            <span><?php echo esc_html__( '桌面版', 'blocksy-child' ); ?></span>
          </button>
        </nav>
      <?php endif; ?>
    </div>

  </div>
</footer>

<!-- Toast 通知容器 -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- ── 隨機動漫（骰子）浮動按鈕 ── -->
<a id="random-anime-btn"
   href="<?php echo esc_url( admin_url('admin-ajax.php') . '?action=wxacg_random_anime' ); ?>"
   rel="nofollow"
   aria-label="<?php echo esc_attr__('隨機抽一部動漫','blocksy-child'); ?>"
   title="<?php echo esc_attr__('隨機抽一部動漫','blocksy-child'); ?>">
  <!-- 外環旋轉文字 -->
  <svg class="ra-arc" viewBox="0 0 88 88" aria-hidden="true">
    <defs>
      <path id="raArcPath" d="M 44,44 m -37,0 a 37,37 0 1 1 74,0 a 37,37 0 1 1 -74,0" />
    </defs>
    <text>
      <textPath href="#raArcPath" xlink:href="#raArcPath" startOffset="50%" text-anchor="middle">
        · 抽 動 漫 ·
      </textPath>
    </text>
  </svg>
  <!-- 中間毛玻璃骰子 -->
  <span class="ra-glass">
   <i class="fa-solid fa-dice ra-icon" aria-hidden="true"></i>
  </span>
</a>

<!-- ── 抽動漫音效開關 ── -->
<button type="button"
        id="random-anime-sound-toggle"
        aria-label="<?php echo esc_attr__('抽動漫音效開關','blocksy-child'); ?>"
        aria-pressed="true"
        title="<?php echo esc_attr__('抽動漫音效開關','blocksy-child'); ?>">
  <i class="fa-solid fa-volume-high" aria-hidden="true"></i>
</button>

<!-- ── 抽選模式切換（動漫／老婆／老公／聲優，點擊循環） ── -->
<button type="button"
        id="random-anime-mode-toggle"
        aria-label="<?php echo esc_attr__('切換抽選模式：動漫／老婆／老公／聲優','blocksy-child'); ?>"
        title="<?php echo esc_attr__('切換抽選模式：動漫／老婆／老公／聲優','blocksy-child'); ?>">
  <i class="fa-solid fa-dice ra-mode-icon" aria-hidden="true"></i>
</button>


<!-- ── Back to Top ── -->
<button id="back-to-top" aria-label="<?php echo esc_attr__( '回到頂端', 'blocksy-child' ); ?>" title="<?php echo esc_attr__( '回到頂端', 'blocksy-child' ); ?>">
  <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
</button>

<!--
  ── Organization Schema 已移除 ──
  原本此處輸出的 Organization JSON-LD（含 sameAs / contactPoint）
  已改由 Rank Math（Titles & Meta → Local SEO + Social）於 <head> 統一輸出，
  以避免全站每頁出現兩份 Organization。
  社群連結請於 Rank Math → Titles & Meta → Social 的
  「Facebook Page URL」與「Additional Profiles」中設定。
-->

<!-- ── 隨機動漫按鈕樣式 ── -->
<style>
#random-anime-btn{
  position:fixed; right:24px; bottom:88px; z-index:9998;
  width:88px; height:88px; border:none; background:none;
  display:flex; align-items:center; justify-content:center;
  color:#fff; text-decoration:none; cursor:pointer;
  opacity:0; visibility:hidden; transform:translateY(10px) scale(.9);
  transition:opacity .3s ease, transform .3s cubic-bezier(.34,1.56,.64,1);
}
#random-anime-btn.visible{opacity:1; visibility:visible; transform:translateY(0) scale(1);}
#random-anime-btn:hover,#random-anime-btn:focus,#random-anime-btn:active{color:#fff; text-decoration:none;}
#random-anime-btn:hover{transform:translateY(-3px) scale(1.08);}

/* 抽動漫音效開關：貼在骰子按鈕右上角的小圓鈕 */
#random-anime-sound-toggle{
  position:fixed; right:20px; bottom:150px; z-index:9999;
  width:26px; height:26px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  background:rgba(20,30,45,.75); border:1px solid rgba(255,255,255,.25);
  color:#cfe4f5; font-size:11px; cursor:pointer; padding:0;
  opacity:0; visibility:hidden; transform:translateY(10px) scale(.9);
  transition:opacity .3s ease, transform .3s cubic-bezier(.34,1.56,.64,1), background .15s ease;
}
#random-anime-sound-toggle.visible{opacity:1; visibility:visible; transform:translateY(0) scale(1);}
#random-anime-sound-toggle:hover{background:rgba(20,30,45,.92);}
#random-anime-sound-toggle.is-muted{color:rgba(207,228,245,.45);}
@media (max-width:768px){#random-anime-sound-toggle{display:none;}}

/* 抽選模式切換：疊在音效開關正下方，樣式共用同一套小圓鈕語彙 */
#random-anime-mode-toggle{
  position:fixed; right:20px; bottom:114px; z-index:9999;
  width:26px; height:26px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  background:rgba(20,30,45,.75); border:1px solid rgba(255,255,255,.25);
  color:#cfe4f5; font-size:11px; cursor:pointer; padding:0;
  opacity:0; visibility:hidden; transform:translateY(10px) scale(.9);
  transition:opacity .3s ease, transform .3s cubic-bezier(.34,1.56,.64,1), background .15s ease;
}
#random-anime-mode-toggle.visible{opacity:1; visibility:visible; transform:translateY(0) scale(1);}
#random-anime-mode-toggle:hover{background:rgba(20,30,45,.92);}
@media (max-width:768px){#random-anime-mode-toggle{display:none;}}

/* 中間毛玻璃骰子 —— 科技白/藍/灰 */
#random-anime-btn .ra-glass{
  position:relative; z-index:1;
  width:64px; height:64px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  background:
    radial-gradient(circle at 32% 24%, rgba(255,255,255,.85), transparent 52%),
    linear-gradient(150deg, #f4f9ff 0%, #cfe4f5 38%, #8fb4d6 72%, #5f7f9e 100%);
  -webkit-backdrop-filter:blur(8px) saturate(150%);
  backdrop-filter:blur(8px) saturate(150%);
  border:1.5px solid rgba(255,255,255,.75);
  box-shadow:
    0 0 0 4px rgba(120,190,255,.16),          /* 外圈冷光環 */
    0 8px 22px rgba(30,60,100,.45),           /* 落地陰影 */
    0 0 26px rgba(90,170,255,.5),             /* 科技藍發光暈 */
    inset 0 2px 6px rgba(255,255,255,.9),     /* 頂部高光 */
    inset 0 -4px 8px rgba(50,90,130,.3);      /* 底部立體 */
  animation:raGlow 2.8s ease-in-out infinite;
  transition:box-shadow .25s ease;
}
#random-anime-btn:hover .ra-glass{
  animation:none;
  box-shadow:
    0 0 0 5px rgba(120,190,255,.28),
    0 12px 30px rgba(30,60,100,.5),
    0 0 42px rgba(90,170,255,.75),
    inset 0 2px 6px rgba(255,255,255,.95),
    inset 0 -4px 8px rgba(50,90,130,.3);
}

/* 骰子圖示 —— 平常靜止一顆 */
#random-anime-btn .ra-icon{
  font-size:24px; color:#28496e;
  text-shadow:0 1px 2px rgba(255,255,255,.7);
  transform-origin:center;
  display:inline-block;
}
/* 滑鼠移過去：持續翻滾骰動 */
#random-anime-btn:hover .ra-icon{
  animation:raRoll .8s cubic-bezier(.36,.07,.19,.97) infinite;
}

/* 外環旋轉文字 —— 淺冰藍字 */
#random-anime-btn .ra-arc{
  position:absolute; inset:0; width:100%; height:100%;
  pointer-events:none;
  animation:raSpin 12s linear infinite;
  filter:drop-shadow(0 1px 3px rgba(20,50,90,.6));
}
#random-anime-btn:hover .ra-arc{animation-duration:4s;}
#random-anime-btn .ra-arc text{
  fill:#ffffff; font-size:12.5px; font-weight:800; letter-spacing:.5px;
  paint-order:stroke;
  stroke:rgba(10,18,32,.85); stroke-width:1.4px;
}

@keyframes raSpin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}

/* 骰子：拋起、翻滾、彈跳、落定 */
@keyframes raRoll{
  0%   {transform:translateY(0)     rotate(0deg)   scale(1);}
  15%  {transform:translateY(-8px)  rotate(90deg)  scale(1.12);}   /* 拋起 */
  30%  {transform:translateY(2px)   rotate(200deg) scale(.92);}    /* 落下壓扁 */
  45%  {transform:translateY(-5px)  rotate(300deg) scale(1.08);}   /* 再彈 */
  60%  {transform:translateY(1px)   rotate(420deg) scale(.96);}
  75%  {transform:translateY(-2px)  rotate(500deg) scale(1.03);}   /* 小彈 */
  90%  {transform:translateY(0)     rotate(540deg) scale(1);}
  100% {transform:translateY(0)     rotate(540deg) scale(1);}      /* 落定 */
}

@keyframes raGlow{
  0%,100%{box-shadow:
    0 0 0 4px rgba(120,190,255,.16),
    0 8px 22px rgba(30,60,100,.45),
    0 0 22px rgba(90,170,255,.4),
    inset 0 2px 6px rgba(255,255,255,.9),
    inset 0 -4px 8px rgba(50,90,130,.3);}
  50%{box-shadow:
    0 0 0 4px rgba(120,190,255,.26),
    0 8px 22px rgba(30,60,100,.5),
    0 0 36px rgba(90,170,255,.8),
    inset 0 2px 6px rgba(255,255,255,.9),
    inset 0 -4px 8px rgba(50,90,130,.3);}
}

@media (prefers-reduced-motion:reduce){
  #random-anime-btn .ra-arc,#random-anime-btn .ra-icon,#random-anime-btn .ra-glass{animation:none;}
}
@media (max-width:768px){#random-anime-btn{display:none;}}
</style>

<!-- ── JS：Back to Top + 隨機動漫 + Cookie Banner ── -->
<script>
(function () {
  'use strict';

  /* Back to Top 浮動按鈕 */
  var btn = document.getElementById('back-to-top');
  var diceBtn = document.getElementById('random-anime-btn');

  var scrollHandler = function () {
    var show = window.scrollY > 300;
    if ( btn ) btn.classList.toggle('visible', show);
  };
  window.addEventListener('scroll', scrollHandler, { passive: true });

  /* 隨機動漫按鈕：進頁即顯示，不需捲動 */
  if ( diceBtn ) diceBtn.classList.add('visible');

  /* 抽動漫音效開關：偏好存在瀏覽器（localStorage），預設開啟 */
  var SMACG_DICE_SOUND_KEY = 'smacg_dice_sound_enabled';
  var soundToggle = document.getElementById('random-anime-sound-toggle');

  function diceSoundEnabled() {
    try {
      var v = localStorage.getItem(SMACG_DICE_SOUND_KEY);
      return v === null ? true : v === '1';
    } catch (err) { return true; }
  }

  function syncSoundToggleUI() {
    if ( !soundToggle ) return;
    var on = diceSoundEnabled();
    soundToggle.classList.toggle('is-muted', !on);
    soundToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
    soundToggle.querySelector('i').className = on ? 'fa-solid fa-volume-high' : 'fa-solid fa-volume-xmark';
  }

  if ( soundToggle ) {
    soundToggle.classList.add('visible');
    syncSoundToggleUI();
    soundToggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var next = !diceSoundEnabled();
      try { localStorage.setItem(SMACG_DICE_SOUND_KEY, next ? '1' : '0'); } catch (err) {}
      syncSoundToggleUI();
    });
  }

  /*
   * 抽選模式切換：動漫／老婆／老公／聲優，點擊在骰子右下角的小圓鈕
   * 循環切換。切換時同步改骰子按鈕的 href（實際導頁目標）、外環文字、
   * 圖示，偏好存 localStorage，下次進站記得上次選的模式。
   *
   * href 直接改 admin-ajax action 名稱，後端三支新的 wxacg_random_wife /
   * wxacg_random_husband / wxacg_random_seiyuu 已各自處理成就與導頁，
   * 這裡不用管抽獎邏輯本身。
   */
  var SMACG_DICE_MODE_KEY = 'smacg_dice_mode';
  var DICE_MODES = [
    { key: 'anime',   action: 'wxacg_random_anime',    icon: 'fa-dice',       arc: '· 抽 動 漫 ·', label: '隨機抽一部動漫' },
    { key: 'wife',    action: 'wxacg_random_wife',     icon: 'fa-venus',      arc: '· 抽 老 婆 ·', label: '隨機抽一位老婆' },
    { key: 'husband', action: 'wxacg_random_husband',  icon: 'fa-mars',       arc: '· 抽 老 公 ·', label: '隨機抽一位老公' },
    { key: 'seiyuu',  action: 'wxacg_random_seiyuu',   icon: 'fa-microphone', arc: '· 抽 聲 優 ·', label: '隨機抽一位聲優' }
  ];
  var modeToggle = document.getElementById('random-anime-mode-toggle');
  var diceAjaxBase = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

  function findModeIndex() {
    try {
      var saved = localStorage.getItem(SMACG_DICE_MODE_KEY);
      for (var i = 0; i < DICE_MODES.length; i++) {
        if (DICE_MODES[i].key === saved) return i;
      }
    } catch (err) {}
    return 0;
  }

  function applyDiceMode(idx) {
    var mode = DICE_MODES[idx];

    if (diceBtn) {
      diceBtn.setAttribute('href', diceAjaxBase + '?action=' + mode.action);
      diceBtn.setAttribute('aria-label', mode.label);
      diceBtn.setAttribute('title', mode.label);

      var iconEl = diceBtn.querySelector('.ra-icon');
      if (iconEl) iconEl.className = 'fa-solid ' + mode.icon + ' ra-icon';

      var arcTextEl = diceBtn.querySelector('.ra-arc textPath');
      if (arcTextEl) arcTextEl.textContent = mode.arc;
    }

    if (modeToggle) {
      var modeIconEl = modeToggle.querySelector('.ra-mode-icon');
      if (modeIconEl) modeIconEl.className = 'fa-solid ' + mode.icon + ' ra-mode-icon';

      var toggleLabel = '目前：' + mode.label + '（點擊切換下一種）';
      modeToggle.setAttribute('aria-label', toggleLabel);
      modeToggle.setAttribute('title', toggleLabel);
    }
  }

  var currentDiceModeIdx = findModeIndex();
  applyDiceMode(currentDiceModeIdx);

  if (modeToggle) {
    modeToggle.classList.add('visible');
    modeToggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      currentDiceModeIdx = (currentDiceModeIdx + 1) % DICE_MODES.length;
      try { localStorage.setItem(SMACG_DICE_MODE_KEY, DICE_MODES[currentDiceModeIdx].key); } catch (err) {}
      applyDiceMode(currentDiceModeIdx);
    });
  }

  if ( btn ) {
    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  /* 隨機動漫按鈕：攔截點擊 → 播放音效 → 稍微延遲後才導頁
     第一次點擊固定播放 dice-first.mp3，之後每次隨機播放 dice-1/2/3.mp3 */
  if ( diceBtn ) {
    var SMACG_SOUND_BASE  = '<?php echo esc_url( get_stylesheet_directory_uri() . "/assets/sounds/" ); ?>';
    var SMACG_FIRST_SOUND = SMACG_SOUND_BASE + 'dice-first.mp3';
    var SMACG_RANDOM_SOUNDS = [
      SMACG_SOUND_BASE + 'dice-1.mp3',
      SMACG_SOUND_BASE + 'dice-2.mp3',
      SMACG_SOUND_BASE + 'dice-3.mp3'
    ];
    var SMACG_FIRST_KEY = 'smacg_dice_first_played';

    diceBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var targetUrl = diceBtn.getAttribute('href');

      var hasPlayedFirst = false;
      try { hasPlayedFirst = !!localStorage.getItem(SMACG_FIRST_KEY); } catch (err) {}

      var soundUrl;
      if ( !hasPlayedFirst ) {
        soundUrl = SMACG_FIRST_SOUND;
        try { localStorage.setItem(SMACG_FIRST_KEY, '1'); } catch (err) {}
      } else {
        soundUrl = SMACG_RANDOM_SOUNDS[ Math.floor( Math.random() * SMACG_RANDOM_SOUNDS.length ) ];
      }

      var navigated = false;
      function goNow() {
        if ( navigated ) return;
        navigated = true;
        window.location.href = targetUrl;
      }

      if ( !diceSoundEnabled() ) {
        goNow(); // 音效關閉：不用等，直接跳轉
        return;
      }

      try {
        var audio = new Audio(soundUrl);
        audio.volume = 0.6;
        audio.addEventListener('error', goNow); // 音效檔案缺失時直接跳轉，不卡住使用者
        audio.play().catch(function () { goNow(); }); // 瀏覽器擋自動播放時直接跳轉
        setTimeout(goNow, 260); // 給音效一點播放時間再跳轉
      } catch (err) {
        goNow();
      }
    });
  }

  /* Cookie Banner */
  try {
    var COOKIE_KEY = 'smacg_cookie_ok';
    var banner = document.getElementById('cookie-banner');
    var accept = document.getElementById('cookie-accept');
    if ( banner && accept && !localStorage.getItem(COOKIE_KEY) ) {
      banner.hidden = false;
      requestAnimationFrame(function(){ banner.classList.add('visible'); });
      accept.addEventListener('click', function () {
        localStorage.setItem(COOKIE_KEY, '1');
        banner.classList.remove('visible');
        setTimeout(function(){ banner.hidden = true; }, 250);
      });
    }
  } catch (e) {}

  /* View Mode Toggle (切換電腦版 / 手機版) */
  try {
    var viewBtn = document.getElementById('wxacg-view-mode-toggle');
    if (viewBtn) {
      var isDesktop = (localStorage.getItem('wxacg_view_mode') === 'desktop');
      var icon = viewBtn.querySelector('i');
      var text = viewBtn.querySelector('.view-toggle-text');

      if (isDesktop) {
        if (icon) icon.className = 'fa-solid fa-mobile-screen-button';
        if (text) text.textContent = '手機版';
        viewBtn.setAttribute('title', '切換回手機版檢視');
      }

      viewBtn.addEventListener('click', function(e) {
        e.preventDefault();
        var currentDesktop = (localStorage.getItem('wxacg_view_mode') === 'desktop');
        var vp = document.getElementById('wxacg-viewport') || document.querySelector('meta[name="viewport"]');

        if (currentDesktop) {
          localStorage.removeItem('wxacg_view_mode');
          if (vp) vp.setAttribute('content', 'width=device-width, initial-scale=1.0');
          document.documentElement.classList.remove('view-as-desktop');
        } else {
          localStorage.setItem('wxacg_view_mode', 'desktop');
          if (vp) vp.setAttribute('content', 'width=1280, initial-scale=0.3, minimum-scale=0.2, maximum-scale=3.0, user-scalable=yes');
          document.documentElement.classList.add('view-as-desktop');
        }
        window.location.reload();
      });
    }
  } catch (e) {}

})();
</script>

<?php wp_footer(); ?>
</body>
</html>