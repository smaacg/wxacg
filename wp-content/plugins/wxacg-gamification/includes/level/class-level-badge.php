<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * 等級徽章顯示（搬自 theme/inc/level-badge-display.php）
 *
 * 用途：
 *   - 留言作者名稱旁顯示等級徽章
 *   - 公開個人頁顯示英雄徽章
 *   - 提供 render_badge( $uid, $opts ) helper
 *
 * v1.1.0 (2026-05-20)
 *   - Fix #1：修正讀取 Level_System::get_user_level() 不存在的 array key
 *             （custom_career / job_title / progress_pct / exp_next_level / exp_needed）
 *             統一改用實際存在的 key：title / percent / next_floor / to_next
 *             job_title 改為呼叫 Career_Jobs::user_title() 動態取得
 *
 * v1.2.0 (2026-05-22)
 *   - Fix BugO（效能）：留言列表常見 100 則留言 + 多位重複留言者，
 *                       原 render_badge() 對每則留言都會：
 *                         1) Level_System::get_user_level()
 *                            └─ 內含 Gamipress_Bridge::get_user_exp()（DB 查詢）
 *                            └─ 內含 Gamipress_Bridge::get_user_badge_count()（DB 查詢）
 *                         2) resolve_career_text() 再做 1 次 get_user_meta
 *                            + 1 次 Career_Jobs::user_title()（DB 查詢）
 *                       100 則留言 = ~400 次 DB query，明顯拖慢頁面。
 *
 *                       現加上 per-request static cache（uid → 整組 info + career），
 *                       同一 request 內同一 uid 的後續呼叫直接命中記憶體，
 *                       100 則留言 + 20 位獨立用戶 = 20 次 DB query。
 *
 *                       Cache 範圍：只在單一 PHP request 內有效，
 *                       request 結束 PHP 結束 → cache 自動釋放。
 *                       若需要在長執行的 cron 中主動清空，可呼叫 clear_cache()。
 */
class Level_Badge {

    private static $instance = null;

    /**
     * Per-request user info cache
     * 格式：[ uid => [ 'info' => array, 'career' => string ] ]
     *
     * @var array
     */
    private static $cache = [];

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'get_comment_author', [ __CLASS__, 'append_to_comment_author' ], 10, 3 );
    }

    /* ==========================================================
     * 內部 helper：取得指定 uid 的 level info + career text（快取版）
     *
     * 同一 request 內第 N 次呼叫直接返回 cache，
     * 避免留言列表大量重複留言者造成 N+1 查詢。
     * ========================================================== */
    private static function get_cached( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return null;

        if ( isset( self::$cache[ $uid ] ) ) {
            return self::$cache[ $uid ];
        }

        $info   = Level_System::get_user_level( $uid );
        $career = self::resolve_career_text( $uid, $info );

        self::$cache[ $uid ] = [
            'info'   => $info,
            'career' => $career,
        ];
        return self::$cache[ $uid ];
    }

    /* ==========================================================
     * 內部 helper：取得顯示用的職業/稱號字串
     * 優先順序：自訂稱號 user_meta > Career_Jobs 動態稱號 > 6 階會員稱號
     *
     * ★ v1.2.0：僅在 cache miss 時被呼叫，整體效能由 get_cached() 控管
     * ========================================================== */
    private static function resolve_career_text( $uid, $info ) {
        // 1) 使用者自訂稱號（若主題/外掛有此 meta）
        $custom = (string) get_user_meta( (int) $uid, 'smacg_custom_career', true );
        if ( $custom !== '' ) return $custom;

        // 2) Career_Jobs 動態職業稱號
        if ( class_exists( __NAMESPACE__ . '\\Career_Jobs' ) ) {
            $job = Career_Jobs::user_title( (int) $uid );
            if ( ! empty( $job['title_name'] ) ) {
                return $job['title_name'];
            }
        }

        // 3) Fallback：6 階會員稱號（一定存在）
        return (string) ( $info['title'] ?? '' );
    }

    /**
     * 對外公開：清空 per-request cache
     *
     * 使用情境：
     *   - 長執行的 cron 任務，逐筆處理使用者後手動清空避免記憶體膨脹
     *   - 單元測試 setUp/tearDown
     *   - 業務邏輯主動讓某個 uid 的 cache 失效（傳 $uid），不傳則清全部
     *
     * @param int|null $uid 指定清空某 uid；null 則清全部
     */
    public static function clear_cache( $uid = null ) {
        if ( $uid === null ) {
            self::$cache = [];
        } else {
            unset( self::$cache[ (int) $uid ] );
        }
    }

    /* ==========================================================
     * 主要 render — 留言列表的小徽章
     * ========================================================== */
    public static function render_badge( $uid, $opts = [] ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return '';

        // ★ v1.2.0：用 cache 取得 info + career
        $cached = self::get_cached( $uid );
        if ( ! $cached ) return '';

        $info   = $cached['info'];
        $career = $cached['career'];

        if ( (int) ( $info['level'] ?? 0 ) <= 0 ) return '';

        $size      = $opts['size']      ?? 'sm';      // sm / md / lg
        $with_job  = $opts['with_job']  ?? true;
        $with_link = $opts['with_link'] ?? false;

        $tier_class = 'smacg-tier-' . (int) ( $info['tier'] ?? 1 );
        $size_class = 'smacg-badge-' . $size;

        $title = sprintf( 'Lv.%d %s', (int) $info['level'], $career );

        ob_start();
        ?>
        <span class="smacg-level-badge <?php echo esc_attr( $tier_class . ' ' . $size_class ); ?>"
              title="<?php echo esc_attr( $title ); ?>">
            <span class="smacg-level-num">Lv.<?php echo (int) $info['level']; ?></span>
            <?php if ( $with_job && $career !== '' ) : ?>
                <span class="smacg-level-job"><?php echo esc_html( $career ); ?></span>
            <?php endif; ?>
        </span>
        <?php
        $html = ob_get_clean();

        if ( $with_link && function_exists( 'wxacg_get_public_profile_url' ) ) {
            $url = wxacg_get_public_profile_url( $uid );
            if ( $url ) {
                $html = '<a href="' . esc_url( $url ) . '" class="smacg-level-badge-link">' . $html . '</a>';
            }
        }

        return $html;
    }

    /* ==========================================================
     * Hero 大徽章（公開個人頁用）
     *
     * ★ v1.2.0：同樣走 cache，個人頁通常只渲染 1 次，但若同頁也有留言列表
     *           出現本人留言，cache 可重用
     * ========================================================== */
    public static function render_hero_badge( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return '';

        $cached = self::get_cached( $uid );
        if ( ! $cached ) return '';

        $info   = $cached['info'];
        $career = $cached['career'];
        $tier   = (int) ( $info['tier'] ?? 1 );

        $percent    = (int) ( $info['percent']    ?? 0 );
        $exp_cur    = (int) ( $info['exp']        ?? 0 );
        $exp_next   = (int) ( $info['next_floor'] ?? 0 );
        $exp_needed = (int) ( $info['to_next']    ?? 0 );
        $is_max     = ! empty( $info['is_max'] );

        ob_start();
        ?>
        <div class="smacg-hero-badge smacg-hero-tier-<?php echo $tier; ?>">
            <div class="smacg-hero-level">
                <span class="smacg-hero-lv-label">Lv.</span>
                <span class="smacg-hero-lv-num"><?php echo (int) ( $info['level'] ?? 1 ); ?></span>
            </div>
            <div class="smacg-hero-job"><?php echo esc_html( $career ); ?></div>
            <div class="smacg-hero-progress">
                <div class="smacg-hero-progress-bar" style="width:<?php echo $percent; ?>%"></div>
            </div>
            <div class="smacg-hero-exp">
                <?php
                if ( $is_max ) {
                    echo '滿級 (' . number_format( $exp_cur ) . ' EXP)';
                } else {
                    printf(
                        '%s / %s EXP（距離下一級 %s）',
                        number_format( $exp_cur ),
                        number_format( $exp_next ),
                        number_format( $exp_needed )
                    );
                }
                ?>
            </div>
            <?php
            $badge_count = (int) ( $info['badge_count'] ?? 0 );
            if ( $badge_count > 0 ) :
                ?>
                <div class="smacg-hero-badges">
                    🏆 持有 <?php echo $badge_count; ?> 枚徽章
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================
     * Hook: comment author append
     *
     * ★ v1.2.0：這是主要受惠的入口
     * 100 則留言 + 20 位獨立用戶 → DB query 從 ~400 次降到 ~60 次
     * ========================================================== */
    public static function append_to_comment_author( $author, $comment_id, $comment ) {
        if ( is_admin() ) return $author;
        if ( ! $comment instanceof \WP_Comment ) return $author;

        $uid = (int) $comment->user_id;
        if ( $uid <= 0 ) return $author;

        $badge = self::render_badge( $uid, [ 'size' => 'sm', 'with_job' => false ] );
        if ( ! $badge ) return $author;

        return $author . ' ' . $badge;
    }
}
